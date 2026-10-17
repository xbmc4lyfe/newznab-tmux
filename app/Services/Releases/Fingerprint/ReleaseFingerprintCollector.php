<?php

declare(strict_types=1);

namespace App\Services\Releases\Fingerprint;

use App\Models\Release;
use App\Services\NNTP\NntpProviderUnavailableException;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbService;
use App\Services\YencService;
use Throwable;

final class ReleaseFingerprintCollector
{
    public const SCHEMA_VERSION = 39;

    private const MAX_ARTICLE_BODY_BYTES = 2_000_000;

    private const MAX_PAR2_SET_BYTES = 67_108_864;

    private const MAX_SEVEN_ZIP_FRAGMENT_BYTES = 16_777_216;

    private const MAX_SEVEN_ZIP_FRAGMENTS_PER_RECORD = 32;

    private const MAX_PAR2_SOURCE_SAMPLE_BYTES = 16_777_216;

    private const MAX_PAR2_SOURCE_RESOLUTION_WORK_BYTES = 33_554_432;

    private const MAX_PAR2_SOURCE_FRAGMENTS_PER_RECORD = 32;

    private const MAX_PAR2_SOURCE_RELATIONSHIPS = 512;

    private const MAX_IFSC_PREVIEW_HEX_BYTES = 8192;

    private const MAX_IFSC_STRUCTURED_PREVIEW_SLICES = 16;

    private const MAX_RELEASE_IFSC_RECORDS = 20_000;

    private const MAX_RELEASE_IFSC_PREVIEW_RECORDS = 4096;

    public function __construct(private readonly NzbService $nzb, private readonly NzbFingerprintAnalyzer $analyzer,
        private readonly Par2MetadataParser $par2, private readonly YencService $yenc) {}

    /** @return array{status: string, data: array<string, mixed>} */
    public function collect(Release $release): array
    {
        $xml = $this->nzb->readNzbContents((string) $release->guid);
        if (! is_string($xml) || $xml === '') {
            return ['status' => 'unavailable', 'data' => ['reason' => 'The release NZB is not available locally.']];
        }

        $manifest = $this->analyzer->analyzeManifest($xml);
        $omittedSampleCount = 0;
        $samples = $this->analyzer->selectArticleSamples($manifest['files'], NzbFingerprintAnalyzer::MAX_ARTICLE_SAMPLES, $omittedSampleCount);
        $servers = $this->configuredServers();
        $nntp = app(NNTPService::class);
        // Alternate providers whose connection or login failed; their remaining samples are skipped
        // instead of repeating the full connect-retry cycle for every one. The primary keeps
        // retrying per sample, so a transient failure cannot blank its observations.
        $unavailable = [];
        $observations = [];
        $errors = [];
        $serverProfiles = [];
        $serverProfileMap = [];
        $decodedPar2 = [];
        $decodedPar2Bytes = 0;
        $par2BudgetExceeded = false;
        $par2ProviderAttemptsSkipped = 0;
        $sevenZipCandidates = [];
        $sevenZipRetainedBytes = 0;
        $sevenZipOmittedSamples = 0;
        $par2SourceSamples = [];
        $par2SourceSampleRetainedBytes = 0;
        $par2SourceSampleOmittedCount = 0;
        foreach ($servers as $server) {
            $serverKey = ($server['alternate'] ? 'alternate ' : 'primary ').$server['name'];
            try {
                $profile = $nntp->getFingerprintServerProfile($server['alternate']);
                $profileRow = $server + ['profile' => $profile];
            } catch (Throwable $exception) {
                $profile = [];
                $profileRow = $server + ['profile_error_type' => class_basename($exception)];
                if ($server['alternate'] && $exception instanceof NntpProviderUnavailableException) {
                    $unavailable[$serverKey] = class_basename($exception);
                }
            }
            $serverProfiles[] = $profileRow;
            $serverProfileMap[$serverKey] = $profile;
        }
        foreach ($samples as $sample) {
            foreach ($servers as $server) {
                if ($sample['kind'] === 'par2' && $par2BudgetExceeded) {
                    $par2ProviderAttemptsSkipped++;

                    continue;
                }
                $serverKey = ($server['alternate'] ? 'alternate ' : 'primary ').$server['name'];
                if (isset($unavailable[$serverKey])) {
                    $errors[] = [
                        'file_index' => $sample['file_index'],
                        'message_id' => $sample['messageid'],
                        'server' => $server['name'],
                        'error_type' => $unavailable[$serverKey],
                    ];

                    continue;
                }
                try {
                    $queryStart = hrtime(true);
                    $capabilities = $serverProfileMap[$serverKey]['capabilities_raw'] ?? [];
                    $supportsMessageIdOverview = count(array_filter(
                        $capabilities,
                        static fn (string $line): bool => preg_match('/\AOVER\s+MSGID\b/i', $line) === 1,
                    )) > 0;
                    $article = $nntp->getFingerprintArticleByMessageID(
                        $sample['messageid'],
                        self::MAX_ARTICLE_BODY_BYTES,
                        65_536,
                        $server['alternate'],
                        $supportsMessageIdOverview,
                    );
                    $retrievalDurationMs = round((hrtime(true) - $queryStart) / 1_000_000, 3);
                    $analysis = $this->analyzer->analyzeArticleAndDecodedBytes($article['headers'], $article['body']);
                    $metadata = $analysis['metadata'];
                    $decodedBytes = $analysis['decoded_bytes'];
                    $articleIdentity = (new NativeMetadataParser)->parseArticleIdentity(
                        '<'.$sample['messageid'].'>',
                        $article['headers'],
                        $article['overview_raw'],
                        $article['overview_response_code'],
                        $article['overview_attempted'],
                        $article['head_response']['response_text'],
                        $article['body_response']['response_text'],
                        $article['head_response']['response_code'],
                        $article['body_response']['response_code'],
                    );
                    $metadata['article_identity'] = $articleIdentity ?? [
                        'parser_engine' => null,
                        'schema_version' => self::SCHEMA_VERSION,
                        'collection_status' => 'unavailable',
                        'reason' => 'native_parser_unavailable_or_input_exceeded_budget',
                    ];
                    $yenc = $metadata['yenc'] ?? null;
                    $metadata['yenc_nzb_part_number_matches'] = is_array($yenc) && is_int($yenc['part'] ?? null)
                        ? $yenc['part'] === $sample['segment_number'] : null;
                    $metadata['yenc_nzb_total_parts_matches'] = is_array($yenc) && is_int($yenc['total_parts'] ?? null)
                        ? $yenc['total_parts'] === count($manifest['files'][$sample['file_index']]['segments'] ?? []) : null;
                    $trailerFields = is_array($yenc) && is_array($yenc['trailer_fields'] ?? null)
                        ? $yenc['trailer_fields'] : [];
                    $metadata['yenc_nzb_trailer_part_number_matches'] = is_string($trailerFields['part'] ?? null)
                        && ctype_digit($trailerFields['part'])
                        ? (int) $trailerFields['part'] === $sample['segment_number'] : null;
                    $metadata['yenc_nzb_trailer_total_parts_matches'] = is_string($trailerFields['total'] ?? null)
                        && ctype_digit($trailerFields['total'])
                        ? (int) $trailerFields['total'] === count($manifest['files'][$sample['file_index']]['segments'] ?? []) : null;
                    $decodedFileRange = $this->decodedFileRange($metadata, $decodedBytes);
                    $metadata['decoded_file_range'] = $decodedFileRange;
                    $providerKey = json_encode([
                        $sample['file_index'], $server['name'], $server['alternate'] ? 'alternate' : 'primary',
                        $server['port'], $server['tls'],
                    ], JSON_INVALID_UTF8_SUBSTITUTE);
                    if (is_string($providerKey) && $sample['kind'] !== 'par2'
                        && ($decodedFileRange['mapping_status'] ?? null) === 'valid'
                        && is_string($decodedBytes) && $decodedBytes !== '') {
                        $par2SourceSamples[$providerKey] ??= [
                            'file_index' => $sample['file_index'],
                            'kind' => $sample['kind'],
                            'subject' => $sample['subject'],
                            'provider' => [
                                'server' => $server['name'],
                                'label' => $server['provider_label'],
                                'role' => $server['alternate'] ? 'alternate' : 'primary',
                                'port' => $server['port'],
                                'tls' => $server['tls'],
                            ],
                            'first_sample' => null,
                            'fragments' => [],
                            'budget_omitted_ranges' => [],
                            'omitted_sample_count_due_to_budget' => 0,
                        ];
                        $par2SourceSamples[$providerKey]['declared_file_sizes'][$decodedFileRange['file_size']] = true;
                        if (($sample['sample_role'] ?? null) === 'first' && ($sample['segment_number'] ?? null) === 1) {
                            $par2SourceSamples[$providerKey]['first_sample'] = [
                                'message_id' => $sample['messageid'],
                                'prefix_md5' => strtolower((string) ($yenc['par2_first_16k_md5_candidate'] ?? '')),
                                'file_length' => $decodedFileRange['file_size'],
                                'integrity_status' => $decodedFileRange['integrity_status'],
                            ];
                        }
                        $decodedFragmentLength = strlen($decodedBytes);
                        if (count($par2SourceSamples[$providerKey]['fragments']) < self::MAX_PAR2_SOURCE_FRAGMENTS_PER_RECORD
                            && $par2SourceSampleRetainedBytes + $decodedFragmentLength <= self::MAX_PAR2_SOURCE_SAMPLE_BYTES) {
                            $par2SourceSamples[$providerKey]['fragments'][] = [
                                'file_offset' => $decodedFileRange['start_offset'],
                                'bytes' => $decodedBytes,
                                'reference' => [
                                    'message_id' => $sample['messageid'],
                                    'segment_number' => $sample['segment_number'],
                                    'sample_role' => $sample['sample_role'],
                                    'source_byte_start' => $decodedFileRange['start_offset'],
                                    'source_byte_end_exclusive' => $decodedFileRange['end_offset_exclusive'],
                                    'yenc_integrity_status' => $decodedFileRange['integrity_status'],
                                ],
                            ];
                            $par2SourceSampleRetainedBytes += $decodedFragmentLength;
                        } else {
                            $par2SourceSamples[$providerKey]['budget_omitted_ranges'][] = [
                                'start' => $decodedFileRange['start_offset'],
                                'end_exclusive' => $decodedFileRange['end_offset_exclusive'],
                            ];
                            $par2SourceSamples[$providerKey]['omitted_sample_count_due_to_budget']++;
                            $par2SourceSampleOmittedCount++;
                        }
                    }
                    $sevenZipAnchor = $this->sevenZipAnchor($metadata);
                    if (is_string($providerKey) && $sample['sample_role'] === 'first'
                        && $sevenZipAnchor !== null && ($decodedFileRange['mapping_status'] ?? null) === 'valid'
                        && ($decodedFileRange['start_offset'] ?? null) === 0) {
                        $sevenZipCandidates[$providerKey] = [
                            'file_index' => $sample['file_index'],
                            'server' => $server['name'],
                            'provider_label' => $server['provider_label'],
                            'server_role' => $server['alternate'] ? 'alternate' : 'primary',
                            'server_port' => $server['port'],
                            'server_tls' => $server['tls'],
                            'anchor_reference' => [
                                'message_id' => $sample['messageid'],
                                'segment_number' => $sample['segment_number'],
                                'sample_role' => $sample['sample_role'],
                            ],
                            'anchor_header' => $sevenZipAnchor,
                            'fragments' => [],
                            'anchor_fragment_retained' => false,
                            'declared_file_sizes' => [],
                            'budget_omitted_ranges' => [],
                        ];
                    }
                    if (is_string($providerKey) && isset($sevenZipCandidates[$providerKey])) {
                        if (is_int($yenc['file_size'] ?? null)) {
                            $sevenZipCandidates[$providerKey]['declared_file_sizes'][$yenc['file_size']] = true;
                        }
                        if (($decodedFileRange['mapping_status'] ?? null) === 'valid' && is_string($decodedBytes)) {
                            if ($sevenZipRetainedBytes + strlen($decodedBytes) <= self::MAX_SEVEN_ZIP_FRAGMENT_BYTES) {
                                $sevenZipCandidates[$providerKey]['fragments'][] = [
                                    'file_offset' => $decodedFileRange['start_offset'],
                                    'bytes' => $decodedBytes,
                                    'reference' => [
                                        'message_id' => $sample['messageid'],
                                        'segment_number' => $sample['segment_number'],
                                        'sample_role' => $sample['sample_role'],
                                        'source_byte_start' => $decodedFileRange['start_offset'],
                                        'source_byte_end_exclusive' => $decodedFileRange['end_offset_exclusive'],
                                        'integrity_status' => $decodedFileRange['integrity_status'],
                                    ],
                                ];
                                $sevenZipRetainedBytes += strlen($decodedBytes);
                                if (($sample['sample_role'] ?? null) === 'first'
                                    && ($decodedFileRange['start_offset'] ?? null) === 0) {
                                    $sevenZipCandidates[$providerKey]['anchor_fragment_retained'] = true;
                                }
                            } else {
                                $sevenZipOmittedSamples++;
                                $sevenZipCandidates[$providerKey]['budget_omitted_ranges'][] = [
                                    'start' => $decodedFileRange['start_offset'],
                                    'end_exclusive' => $decodedFileRange['end_offset_exclusive'],
                                ];
                            }
                        }
                    }
                    $overviewFormatRaw = $serverProfileMap[$serverKey]['overview_format_raw'] ?? [];
                    $overviewFormat = is_array($overviewFormatRaw)
                        ? array_values(array_filter($overviewFormatRaw, 'is_string')) : [];
                    $overviewFormatCodeRaw = $serverProfileMap[$serverKey]['overview_format_response_code'] ?? null;
                    $overviewFormatResponseCode = is_int($overviewFormatCodeRaw) ? $overviewFormatCodeRaw : null;
                    $overviewFields = $this->parseOverview($article['overview_raw'], $overviewFormat);
                    $linesBodyRelationship = $metadata['nntp_lines_body_relationship'] ?? null;
                    if (is_array($linesBodyRelationship)) {
                        $metadata['nntp_lines_body_relationship'] = $this->relateLinesBodyToRequestedArticle(
                            $linesBodyRelationship,
                            $articleIdentity,
                        );
                    }
                    $articleXref = (new NativeMetadataParser)->parseArticleXref(
                        $article['headers'],
                        $article['overview_rows'],
                        $overviewFormat,
                        $article['head_response']['response_code'],
                        $article['overview_response_code'],
                        $article['overview_attempted'],
                        $article['overview_row_count'],
                        $overviewFormatResponseCode,
                    );
                    $metadata['article_xref'] = $articleXref ?? [
                        'parser_engine' => null,
                        'schema_version' => 1,
                        'collection_status' => 'unavailable',
                        'reason' => 'native_parser_unavailable_or_input_exceeded_budget',
                    ];
                    $articleDates = (new NativeMetadataParser)->parseArticleDates($article['headers']);
                    $metadata['article_dates'] = $articleDates ?? [
                        'parser_engine' => null,
                        'schema_version' => NativeMetadataParser::ARTICLE_DATES_SCHEMA_VERSION,
                        'collection_status' => 'unavailable',
                        'reason' => 'native_parser_unavailable_or_input_exceeded_budget',
                    ];
                    $observation = [
                        'file_index' => $sample['file_index'],
                        'nzb_subject' => $sample['subject'],
                        'kind' => $sample['kind'],
                        'message_id' => $sample['messageid'],
                        'segment_number' => $sample['segment_number'],
                        'segment_bytes_from_nzb' => $sample['segment_bytes'],
                        'sample_role' => $sample['sample_role'],
                        'server' => $server['name'],
                        'provider_label' => $server['provider_label'],
                        'provider_label_basis' => $server['provider_label_basis'],
                        'server_role' => $server['alternate'] ? 'alternate' : 'primary',
                        'server_port' => $server['port'],
                        'server_tls' => $server['tls'],
                        'queried_at_utc' => now()->utc()->toIso8601String(),
                        'retrieval_duration_ms' => $retrievalDurationMs,
                        'overview_attempted' => $article['overview_attempted'],
                        'overview_response_code' => $article['overview_response_code'],
                        'overview_raw' => $article['overview_raw'],
                        'overview_rows' => $article['overview_rows'],
                        'overview_row_count' => $article['overview_row_count'],
                        'overview_row_protocol_status' => $this->overviewRowProtocolStatus($article),
                        'overview_response_row_observation_status' => $this->overviewResponseRowStatus($article),
                        'overview_rows_basis' => 'NNTP multiline data rows after client dot-unstuffing and CRLF removal',
                        'head_response' => $article['head_response'],
                        'body_response' => $article['body_response'],
                        'overview_fields' => $overviewFields,
                        'overview_nzb_segment_bytes_difference' => is_int($sample['segment_bytes']) && isset($overviewFields[':bytes']) && is_numeric($overviewFields[':bytes'])
                            ? (int) $overviewFields[':bytes'] - (int) $sample['segment_bytes'] : null,
                        'command_results' => $article['command_results'],
                        'metadata' => $metadata,
                    ];
                    $observations[] = $observation;
                    if ($sample['kind'] === 'par2' && ! $server['alternate']) {
                        $decoded = $this->yenc->decodeWithFrame($article['body']);
                        $yencIntegrity = $metadata['yenc'] ?? null;
                        $bodyResponseIdentityValid = Par2MetadataParser::bodyResponseIdentityVerified($metadata);
                        $sizeValid = is_array($yencIntegrity)
                            && ($yencIntegrity['payload_escape_state_valid'] ?? false) === true
                            && ($yencIntegrity['header_numeric_fields_valid'] ?? false) === true
                            && ($yencIntegrity['decoded_size_matches_header_size'] ?? false) === true
                            && ($yencIntegrity['decoded_size_matches_yenc'] ?? false) === true
                            && (($yencIntegrity['multipart'] ?? false) === true
                                ? ($yencIntegrity['ypart_range_valid'] ?? false) === true
                                    && ($yencIntegrity['decoded_size_matches_yend_size'] ?? false) === true
                                    && isset($yencIntegrity['trailer_fields']['part'])
                                    && (int) $yencIntegrity['trailer_fields']['part'] === ($yencIntegrity['part'] ?? null)
                                : ($yencIntegrity['decoded_size_matches_yend_size'] ?? false) === true)
                            && ($yencIntegrity['trailer_size_is_decimal'] ?? false) === true
                            && (($yencIntegrity['multipart'] ?? false) === true
                                ? ($metadata['yenc_nzb_part_number_matches'] ?? false) === true
                                : ($yencIntegrity['part'] ?? null) === null)
                            && (($metadata['yenc_nzb_total_parts_matches'] ?? null) !== false);
                        $crcValid = is_array($yencIntegrity)
                            && (($yencIntegrity['multipart'] ?? false) === true
                                ? ($yencIntegrity['pcrc32'] ?? null) !== null
                                    && ($yencIntegrity['declared_crc32_is_hex'] ?? false) === true
                                    && ($yencIntegrity['declared_crc32_matches'] ?? false) === true
                                : Par2MetadataParser::declaredYencCrc32Valid($yencIntegrity));
                        if ($decoded[1] !== null && $bodyResponseIdentityValid && $sizeValid && $crcValid) {
                            $decodedSize = strlen($decoded[0]);
                            if ($decodedPar2Bytes + $decodedSize <= self::MAX_PAR2_SET_BYTES) {
                                $decodedPar2[$sample['file_index']][$sample['segment_number']] = $decoded[0];
                                $decodedPar2Bytes += $decodedSize;
                            } else {
                                $par2BudgetExceeded = true;
                                $errors[] = [
                                    'file_index' => $sample['file_index'],
                                    'message_id' => $sample['messageid'],
                                    'server' => $server['name'],
                                    'error_type' => 'Par2MetadataMemoryBudgetExceeded',
                                ];
                            }
                        } else {
                            $errors[] = [
                                'file_index' => $sample['file_index'],
                                'message_id' => $sample['messageid'],
                                'server' => $server['name'],
                                'error_type' => $bodyResponseIdentityValid
                                    ? 'Par2YencIntegrityCheckFailed'
                                    : 'Par2BodyResponseIdentityUnverified',
                            ];
                        }
                    }
                } catch (Throwable $exception) {
                    $errors[] = [
                        'file_index' => $sample['file_index'],
                        'message_id' => $sample['messageid'],
                        'server' => $server['name'],
                        'error_type' => class_basename($exception),
                    ];
                    if ($server['alternate'] && $exception instanceof NntpProviderUnavailableException) {
                        $unavailable[$serverKey] = class_basename($exception);
                    }
                }
            }
        }

        $sevenZipRelationships = $this->resolveSevenZipRelationships($sevenZipCandidates);

        $fileRows = [];
        $yencSizes = ['rar' => 0, 'par2' => 0, 'other' => 0];
        $yencSizeCounts = ['rar' => 0, 'par2' => 0, 'other' => 0];
        $yencSizeConflictCounts = ['rar' => 0, 'par2' => 0, 'other' => 0];
        foreach ($manifest['files'] as $file) {
            $primary = $this->primaryObservation((int) $file['index'], $observations);
            $fileObservations = array_values(array_filter($observations, fn (array $observation): bool => $observation['file_index'] === (int) $file['index'] && $observation['server_role'] === 'primary'));
            $yenc = $primary['metadata']['yenc'] ?? null;
            $yencSamples = array_values(array_filter(array_map(
                static fn (array $observation): mixed => $observation['metadata']['yenc'] ?? null,
                $fileObservations,
            ), 'is_array'));
            $yencConsistency = [];
            foreach (['name', 'file_size', 'line_length', 'total_parts', 'crc32'] as $field) {
                $fieldValues = array_column($yencSamples, $field);
                $uniqueFieldValues = array_values(array_unique($fieldValues, SORT_REGULAR));
                $yencConsistency[$field] = [
                    'consistent' => $fieldValues !== [] && count($uniqueFieldValues) === 1,
                    'observed_values' => $uniqueFieldValues,
                    'sample_count' => count($fieldValues),
                ];
            }
            $ranges = [];
            foreach ($fileObservations as $observation) {
                $sampleYenc = $observation['metadata']['yenc'] ?? null;
                if (is_array($sampleYenc) && is_int($sampleYenc['begin'] ?? null) && is_int($sampleYenc['end'] ?? null)) {
                    $ranges[] = [
                        'nzb_segment_number' => $observation['segment_number'],
                        'yenc_part_number' => $sampleYenc['part'],
                        'begin' => $sampleYenc['begin'],
                        'end' => $sampleYenc['end'],
                        'range_valid' => $sampleYenc['ypart_range_valid'] ?? null,
                    ];
                }
            }
            usort($ranges, static fn (array $left, array $right): int => $left['begin'] <=> $right['begin']);
            $allSegmentsSampled = count($fileObservations) === count($file['segments']) && count($file['segments']) > 0;
            $rangesContiguous = $ranges !== []
                && $ranges[0]['begin'] === 1
                && count(array_filter($ranges, static fn (array $range): bool => $range['range_valid'] !== true)) === 0;
            for ($rangeIndex = 1; $rangeIndex < count($ranges); $rangeIndex++) {
                $rangesContiguous = $rangesContiguous && $ranges[$rangeIndex]['begin'] === $ranges[$rangeIndex - 1]['end'] + 1;
            }
            $declaredSizes = $yencConsistency['file_size']['observed_values'];
            $declaredFileSize = count($declaredSizes) === 1 && is_int($declaredSizes[0]) ? $declaredSizes[0] : null;
            $singlePartCoversFile = $allSegmentsSampled
                && count($file['segments']) === 1
                && count($yencSamples) === 1
                && ($yencSamples[0]['payload_escape_state_valid'] ?? false) === true
                && $declaredFileSize !== null
                && ($yencSamples[0]['part'] ?? null) === null
                && ($yencSamples[0]['decoded_segment_size'] ?? null) === $declaredFileSize;
            $allMultipartNumbersMatch = count(array_filter(
                $fileObservations,
                static fn (array $observation): bool => ($observation['metadata']['yenc_nzb_part_number_matches'] ?? false) === true
                    && ($observation['metadata']['yenc']['payload_escape_state_valid'] ?? false) === true
                    && ($observation['metadata']['yenc_nzb_total_parts_matches'] ?? null) !== false,
            )) === count($fileObservations);
            $rangesCoverFile = ! $allSegmentsSampled ? null : ($singlePartCoversFile || ($allMultipartNumbersMatch && $rangesContiguous
                && $declaredFileSize !== null
                && $ranges[count($ranges) - 1]['end'] === $declaredFileSize));
            if ($yencConsistency['file_size']['sample_count'] > 0 && ! $yencConsistency['file_size']['consistent']) {
                $yencSizeConflictCounts[$file['kind']]++;
            } elseif ($yencConsistency['file_size']['consistent']
                && is_array($yenc) && is_int($yenc['file_size'] ?? null)) {
                $yencSizes[$file['kind']] = $this->checkedNonNegativeIntegerAdd($yencSizes[$file['kind']], $yenc['file_size']);
                $yencSizeCounts[$file['kind']]++;
                if ($yencSizes[$file['kind']] === null) {
                    $yencSizeConflictCounts[$file['kind']]++;
                }
            }
            $fileRows[] = [
                'index' => $file['index'],
                'kind' => $file['kind'],
                'format_hint' => $file['format_hint'],
                'subject' => $file['subject'],
                'poster' => $file['poster'],
                'date' => $file['date'],
                'source_date' => $file['source_date'] ?? null,
                'groups' => $file['groups'],
                'segment_count' => $file['segment_count'],
                'nzb_advertised_bytes' => $file['advertised_bytes'],
                'nzb_advertised_bytes_overflow' => $file['advertised_bytes_overflow'] ?? false,
                'segments' => array_map(static fn (array $segment): array => [
                    'number' => $segment['number'],
                    'source_number' => $segment['source_number'] ?? null,
                    'message_id' => $segment['messageid'],
                    'advertised_bytes' => $segment['bytes'],
                    'source_bytes' => $segment['source_bytes'] ?? null,
                ], $file['segments']),
                'sampled_article_count' => count($fileObservations),
                'sampled_segment_number' => $primary['segment_number'] ?? null,
                'yenc' => $yenc,
                'yenc_consistency' => [
                    'sample_count' => count($yencSamples),
                    'fields' => $yencConsistency,
                    'sampled_ranges' => $ranges,
                    'sampled_ranges_contiguous' => $rangesContiguous,
                    'all_nzb_segments_sampled' => $allSegmentsSampled,
                    'ranges_cover_declared_file' => $rangesCoverFile,
                ],
                'yenc_samples' => array_map(static fn (array $observation): array => [
                    'segment_number' => $observation['segment_number'],
                    'role' => $observation['sample_role'],
                    'metadata' => $observation['metadata']['yenc'] ?? null,
                    'part_number_matches_nzb' => $observation['metadata']['yenc_nzb_part_number_matches'] ?? null,
                    'total_parts_matches_nzb' => $observation['metadata']['yenc_nzb_total_parts_matches'] ?? null,
                ], $fileObservations),
                'nzb_segment_integrity' => [
                    'source_segment_record_count' => $file['source_segment_record_count'],
                    'invalid_segment_record_count' => $file['invalid_segment_record_count'],
                    'invalid_segment_metadata_count' => $file['invalid_segment_metadata_count'],
                    'duplicate_message_id_count' => $file['duplicate_message_id_count'],
                    'duplicate_segment_number_count' => $file['duplicate_segment_number_count'],
                    'segment_number_sequence_valid' => $file['segment_number_sequence_valid'],
                ],
            ];
        }

        $assembledPar2 = [];
        $par2Complete = true;
        foreach ($manifest['files'] as $file) {
            if ($file['kind'] !== 'par2') {
                continue;
            }
            $parts = $decodedPar2[$file['index']] ?? [];
            ksort($parts);
            $segmentNumbers = array_column($file['segments'], 'number');
            $expectedNumbers = $segmentNumbers === [] ? [] : range(1, count($segmentNumbers));
            $complete = $segmentNumbers !== []
                && $segmentNumbers === $expectedNumbers
                && count($parts) === count($file['segments'])
                && ! $par2BudgetExceeded;
            $bytes = array_sum(array_map('strlen', $parts));
            if (! $complete || $bytes > self::MAX_PAR2_SET_BYTES) {
                $par2Complete = false;
                $assembledPar2[] = [
                    'file_index' => $file['index'],
                    'subject' => $file['subject'],
                    'all_segments_retrieved' => $complete,
                    'packet_checksums_valid' => false,
                    'packet_set_consistent' => false,
                    'main_source_membership_valid' => false,
                    'whole_file_crc32_expected_values' => [],
                    'whole_file_crc32_observed' => null,
                    'whole_file_crc32_matches' => null,
                    'metadata_valid' => false,
                    'decoded_bytes' => $bytes,
                    'metadata' => null,
                ];

                continue;
            }
            $assembled = implode('', $parts);
            $metadata = $this->par2->parse($assembled);
            $fileObservations = array_values(array_filter($observations, static fn (array $observation): bool => $observation['file_index'] === (int) $file['index'] && $observation['server_role'] === 'primary'));
            $articleSegmentRanges = [];
            $segmentOffset = 0;
            foreach ($parts as $segmentNumber => $segmentBytes) {
                $segmentObservation = null;
                foreach ($fileObservations as $observation) {
                    if ($observation['segment_number'] === (int) $segmentNumber) {
                        $segmentObservation = $observation;
                        break;
                    }
                }
                if ($segmentObservation !== null) {
                    $yencMetadata = $segmentObservation['metadata']['yenc'] ?? [];
                    $declaredRangeStart = is_int($yencMetadata['begin'] ?? null) ? $yencMetadata['begin'] : null;
                    $declaredRangeEnd = is_int($yencMetadata['end'] ?? null) ? $yencMetadata['end'] : null;
                    $expectedRangeStart = $segmentOffset + 1;
                    $expectedRangeEnd = $segmentOffset + strlen($segmentBytes);
                    $articleSegmentRanges[] = [
                        'segment_number' => (int) $segmentNumber,
                        'message_id' => $segmentObservation['message_id'],
                        'offset' => $segmentOffset,
                        'byte_length' => strlen($segmentBytes),
                        'declared_file_byte_start' => $declaredRangeStart,
                        'declared_file_byte_end_inclusive' => $declaredRangeEnd,
                        'declared_range_matches_assembled_position' => $declaredRangeStart === $expectedRangeStart
                            && $declaredRangeEnd === $expectedRangeEnd,
                        'server' => $segmentObservation['server'],
                        'provider_label' => $segmentObservation['provider_label'],
                        'server_role' => $segmentObservation['server_role'],
                    ];
                }
                $segmentOffset += strlen($segmentBytes);
            }
            $metadata['packet_summaries'] = Par2MetadataParser::mapPacketArticleSpans(
                $metadata['packet_summaries'] ?? [],
                $articleSegmentRanges,
            );
            $packetArticleSegmentsByOffset = [];
            foreach ($metadata['packet_summaries'] as $packetSummary) {
                $packetArticleSegmentsByOffset[$packetSummary['offset']] = $packetSummary['article_segments'] ?? [];
            }
            $metadata['recovery_packets'] = $metadata['recovery_packets'] ?? [];
            foreach ($metadata['recovery_packets'] as &$recoveryPacket) {
                $packetOffset = $recoveryPacket['packet_offset'] ?? null;
                $recoveryPacket['article_segments'] = is_int($packetOffset)
                    ? ($packetArticleSegmentsByOffset[$packetOffset] ?? []) : [];
            }
            unset($recoveryPacket);
            $physicalRegionCount = 0;
            $unmappedPhysicalRegionCount = 0;
            foreach ($metadata['packet_summaries'] as &$packet) {
                $regions = $packet['physical_regions'] ?? [];
                foreach ($regions as &$region) {
                    $regionMappings = Par2MetadataParser::mapPacketArticleSpans([[
                        'offset' => $region['offset'] ?? null,
                        'packet_bytes' => $region['byte_length'] ?? null,
                    ]], $articleSegmentRanges);
                    $mappedRegion = $regionMappings[0] ?? [];
                    $region['article_segments'] = $mappedRegion['article_segments'] ?? [];
                    $region['article_span_mapped_bytes'] = $mappedRegion['article_span_mapped_bytes'] ?? 0;
                    $region['article_span_mapping_complete'] = $mappedRegion['article_span_mapping_complete'] ?? false;
                    $physicalRegionCount++;
                    if (($region['article_span_mapping_complete'] ?? false) !== true) {
                        $unmappedPhysicalRegionCount++;
                    }
                }
                unset($region);
                $packet['physical_regions'] = $regions;
            }
            unset($packet);
            $metadata['article_span_mapping'] = [
                'basis' => 'PAR2 packet offsets in assembled segment-number order mapped to decoded article bytes and primary-provider Message-IDs; yEnc-declared file positions are compared separately.',
                'decoded_segment_count' => count($articleSegmentRanges),
                'decoded_segment_bytes' => $segmentOffset,
                'yenc_segment_position_claims_match_assembled_order' => $articleSegmentRanges !== []
                    && count(array_filter(
                        $articleSegmentRanges,
                        static fn (array $segment): bool => $segment['declared_range_matches_assembled_position'] === true,
                    )) === count($articleSegmentRanges),
                'yenc_segment_position_mismatches' => array_values(array_map(
                    static fn (array $segment): int => $segment['segment_number'],
                    array_filter(
                        $articleSegmentRanges,
                        static fn (array $segment): bool => $segment['declared_range_matches_assembled_position'] !== true,
                    ),
                )),
                'packet_count' => count($metadata['packet_summaries']),
                'unmapped_packet_count' => count(array_filter(
                    $metadata['packet_summaries'],
                    static fn (array $packet): bool => ($packet['article_span_mapping_complete'] ?? false) !== true,
                )),
                'physical_region_count' => $physicalRegionCount,
                'unmapped_physical_region_count' => $unmappedPhysicalRegionCount,
                'complete' => $metadata['packet_summaries'] !== []
                    && count(array_filter(
                        $metadata['packet_summaries'],
                        static fn (array $packet): bool => ($packet['article_span_mapping_complete'] ?? false) !== true,
                    )) === 0
                    && $unmappedPhysicalRegionCount === 0,
            ];
            $yencNames = array_values(array_unique(array_filter(array_map(
                static fn (array $observation): ?string => $observation['metadata']['yenc']['name'] ?? null,
                $fileObservations,
            ), 'is_string')));
            $par2YencSizes = array_values(array_unique(array_filter(array_map(
                static fn (array $observation): ?int => $observation['metadata']['yenc']['file_size'] ?? null,
                $fileObservations,
            ), 'is_int')));
            $hasMultipartYenc = count(array_filter($fileObservations, static fn (array $observation): bool => ($observation['metadata']['yenc']['multipart'] ?? false) === true)) > 0;
            $yencStructureValid = count($fileObservations) === count($file['segments'])
                && count($yencNames) === 1 && count($par2YencSizes) === 1
                && ($hasMultipartYenc || count($fileObservations) === 1)
                && count(array_filter($fileObservations, static fn (array $observation): bool => ((($observation['metadata']['yenc']['multipart'] ?? false) === true)
                        ? ($observation['metadata']['yenc_nzb_part_number_matches'] ?? false) === true
                        : ($observation['metadata']['yenc']['part'] ?? null) === null)
                    && ($observation['metadata']['yenc_nzb_total_parts_matches'] ?? null) !== false
                    && ($observation['metadata']['yenc']['decoded_size_matches_header_size'] ?? false) === true
                    && ($observation['metadata']['yenc']['decoded_size_matches_yend_size'] ?? false) === true
                    && ($observation['metadata']['yenc']['integrity_checks_ambiguous'] ?? false) !== true
                    && (($observation['metadata']['yenc']['multipart'] ?? false) === true
                        ? ($observation['metadata']['yenc']['ypart_range_valid'] ?? false) === true
                            && ($observation['metadata']['yenc']['pcrc32'] ?? null) !== null
                            && ($observation['metadata']['yenc']['declared_crc32_matches'] ?? false) === true
                            && isset($observation['metadata']['yenc']['trailer_fields']['part'])
                            && (int) $observation['metadata']['yenc']['trailer_fields']['part'] === ($observation['metadata']['yenc']['part'] ?? null)
                        : true),
                )) === count($fileObservations);
            if ($yencStructureValid && $hasMultipartYenc) {
                $orderedObservations = $fileObservations;
                usort($orderedObservations, static fn (array $left, array $right): int => $left['segment_number'] <=> $right['segment_number']);
                $nextByte = 1;
                foreach ($orderedObservations as $observation) {
                    $begin = (int) ($observation['metadata']['yenc']['begin'] ?? 0);
                    $end = (int) ($observation['metadata']['yenc']['end'] ?? 0);
                    if ($begin !== $nextByte || $end < $begin) {
                        $yencStructureValid = false;
                        break;
                    }
                    $nextByte = $end + 1;
                }
                $yencStructureValid = $yencStructureValid && $nextByte - 1 === $par2YencSizes[0];
            }
            $wholeFileCrcValues = [];
            foreach ($observations as $observation) {
                if ($observation['file_index'] === (int) $file['index']
                    && $observation['server_role'] === 'primary'
                    && is_string($observation['metadata']['yenc']['crc32'] ?? null)) {
                    $wholeFileCrcValues[] = strtoupper($observation['metadata']['yenc']['crc32']);
                }
            }
            $wholeFileCrcValues = array_values(array_unique($wholeFileCrcValues));
            $observedWholeFileCrc = strtoupper(hash('crc32b', $assembled));
            $wholeFileCrcMatches = $wholeFileCrcValues === [] ? null
                : (count($wholeFileCrcValues) === 1
                    && preg_match('/\A[0-9A-F]{1,8}\z/', $wholeFileCrcValues[0]) === 1
                    && str_pad($wholeFileCrcValues[0], 8, '0', STR_PAD_LEFT) === $observedWholeFileCrc);
            $packets = $metadata['packet_summaries'];
            $packetChecksumsValid = $packets !== []
                && (int) ($metadata['invalid_packet_count'] ?? 0) === 0
                && count(array_filter($packets, static fn (array $packet): bool => ($packet['checksum_valid'] ?? false) !== true)) === 0;
            $validSetIds = array_values(array_unique(array_column(array_filter(
                $packets,
                static fn (array $packet): bool => ($packet['checksum_valid'] ?? false) === true,
            ), 'set_id')));
            $packetSetConsistent = count($validSetIds) === 1;
            $mainPacket = $metadata['main_packet'] ?? null;
            $mainSourceMembershipValid = is_array($mainPacket)
                ? (int) ($mainPacket['recoverable_file_count'] ?? -1) === count($mainPacket['recoverable_file_ids'] ?? [])
                : null;
            $metadataValid = $packetChecksumsValid
                && ! (bool) ($metadata['packet_limit_reached'] ?? false)
                && (int) ($metadata['trailing_unparsed_bytes'] ?? 0) === 0
                && (int) ($metadata['unparsed_bytes'] ?? 0) === 0
                && is_string($metadata['creator'] ?? null)
                && trim($metadata['creator']) !== ''
                && $yencStructureValid
                && $packetSetConsistent
                && ($metadata['source_descriptions_valid'] ?? true) === true
                && ($metadata['ifsc_relationships_valid'] ?? true) === true
                && $wholeFileCrcMatches !== false
                && (int) ($metadata['packet_count'] ?? 0) > 0;
            if (! $metadataValid) {
                $par2Complete = false;
            }
            $assembledPar2[] = [
                'file_index' => $file['index'],
                'subject' => $file['subject'],
                'all_segments_retrieved' => true,
                'yenc_structure_valid' => $yencStructureValid,
                'packet_checksums_valid' => $packetChecksumsValid,
                'packet_set_consistent' => $packetSetConsistent,
                'main_source_membership_valid' => $mainSourceMembershipValid,
                'whole_file_crc32_expected_values' => $wholeFileCrcValues,
                'whole_file_crc32_observed' => $observedWholeFileCrc,
                'whole_file_crc32_matches' => $wholeFileCrcMatches,
                'metadata_valid' => $metadataValid,
                'decoded_bytes' => strlen($assembled),
                'metadata' => $metadata,
            ];
        }

        $par2Sources = [];
        $par2RecoveryExponentsBySet = [];
        $par2SetIds = [];
        $par2SetMainPackets = [];
        $par2SetMainPacketConflicts = [];
        $par2SetMainDefinitionHashes = [];
        $par2SetSources = [];
        $par2SetSourceConflicts = [];
        $par2SourceDefinitionConflictKeys = [];
        $par2SetSourceDefinitionHashes = [];
        $par2SetBlockSizes = [];
        $par2SetBlockSizeConflicts = [];
        $par2IfscObservations = [];
        $par2IfscObservationsOmitted = 0;
        $par2IfscObservationCount = 0;
        $par2RecoverySlicePayloadHashesBySet = [];
        $par2RecoverySliceConflicts = [];
        $par2PackedLayout = false;
        $par2FileCount = count(array_filter($manifest['files'], static fn (array $file): bool => $file['kind'] === 'par2'));
        foreach ($assembledPar2 as $par2File) {
            $packetMetadata = is_array($par2File['metadata'] ?? null) ? $par2File['metadata'] : [];
            foreach (Par2MetadataParser::sourceDefinitionConflictKeys($packetMetadata) as $conflictKey) {
                $par2SourceDefinitionConflictKeys[$conflictKey] = true;
            }
            if (! is_array($par2File['metadata'] ?? null)) {
                continue;
            }
            $metadata = $par2File['metadata'];
            $validIfscPacketKeys = [];
            foreach ($metadata['packet_summaries'] as $packet) {
                $setId = $packet['set_id'] ?? null;
                $payloadPrefix = $packet['payload_prefix_hex'] ?? null;
                if (($packet['checksum_valid'] ?? false) !== true
                    || ($packet['packet_type'] ?? null) !== 'PAR 2.0\\0IFSC'
                    || ! is_string($setId)
                    || ! is_string($payloadPrefix)) {
                    continue;
                }
                $fileId = strtolower(substr($payloadPrefix, 0, 32));
                if (preg_match('/\\A[a-f0-9]{32}\\z/', $fileId) === 1) {
                    $validIfscPacketKeys[$setId.':'.$fileId] = true;
                }
            }
            foreach ($metadata['input_file_slice_checksums'] ?? [] as $ifsc) {
                $setId = $ifsc['recovery_set_id'] ?? null;
                $fileId = $ifsc['file_id'] ?? null;
                if (! is_string($setId) || preg_match('/\\A[a-f0-9]{32}\\z/i', $setId) !== 1
                    || ! is_string($fileId) || preg_match('/\\A[a-f0-9]{32}\\z/i', $fileId) !== 1) {
                    continue;
                }
                if (! isset($validIfscPacketKeys[$setId.':'.strtolower($fileId)])) {
                    continue;
                }
                if ($par2IfscObservationCount >= self::MAX_RELEASE_IFSC_RECORDS) {
                    $par2IfscObservationsOmitted++;

                    continue;
                }
                $par2IfscObservationCount++;
                $previewHex = is_string($ifsc['checksum_records_preview_hex'] ?? null)
                    ? strtolower($ifsc['checksum_records_preview_hex']) : null;
                $previewSliceCount = is_int($ifsc['preview_slice_count'] ?? null) ? $ifsc['preview_slice_count'] : null;
                $boundedPreviewSliceCount = $previewSliceCount === null
                    ? null : min($previewSliceCount, self::MAX_IFSC_STRUCTURED_PREVIEW_SLICES);
                $par2SetIds[$setId] = true;
                $par2IfscObservations[$setId][$fileId][] = [
                    'slice_count' => is_int($ifsc['slice_count'] ?? null) ? $ifsc['slice_count'] : null,
                    'checksum_records_sha256' => is_string($ifsc['checksum_records_sha256'] ?? null)
                        ? strtolower($ifsc['checksum_records_sha256']) : null,
                    'checksum_records_preview_hex' => is_string($previewHex)
                        ? substr($previewHex, 0, self::MAX_IFSC_STRUCTURED_PREVIEW_SLICES * 40) : null,
                    'preview_slice_count' => $boundedPreviewSliceCount,
                    'preview_truncated' => ($ifsc['preview_truncated'] ?? false) === true
                        || ($previewSliceCount !== null && $previewSliceCount > self::MAX_IFSC_STRUCTURED_PREVIEW_SLICES),
                    'duplicate_conflict' => ($ifsc['duplicate_conflict'] ?? false) === true,
                    'origin' => [
                        'file_index' => $par2File['file_index'],
                        'subject' => $par2File['subject'],
                    ],
                ];
            }
            $fileSetIds = array_values(array_unique(array_column(array_filter(
                $metadata['packet_summaries'],
                static fn (array $packet): bool => ($packet['checksum_valid'] ?? false) === true,
            ), 'set_id')));
            if (count($fileSetIds) !== 1) {
                $par2Complete = false;

                continue;
            }
            $setId = $fileSetIds[0];
            $par2SetIds[$setId] = true;
            foreach ($metadata['packet_summaries'] as $packet) {
                if (($packet['checksum_valid'] ?? false) !== true
                    || ($packet['set_id'] ?? null) !== $setId
                    || ! is_string($packet['payload_sha256'] ?? null)) {
                    continue;
                }
                if (($packet['packet_type'] ?? null) === 'PAR 2.0\\0Main') {
                    $par2SetMainDefinitionHashes[$setId][$packet['payload_sha256']] = true;
                } elseif (($packet['packet_type'] ?? null) === 'PAR 2.0\\0FileDesc'
                    && is_string($packet['payload_prefix_hex'] ?? null)) {
                    $fileId = strtolower(substr($packet['payload_prefix_hex'], 0, 32));
                    if (preg_match('/\\A[a-f0-9]{32}\\z/i', $fileId) === 1) {
                        $par2SetSourceDefinitionHashes[$setId][$fileId][$packet['payload_sha256']] = true;
                    }
                }
            }
            if (is_array($metadata['main_packet'] ?? null)) {
                $main = $metadata['main_packet'];
                if (isset($par2SetMainPackets[$setId]) && $par2SetMainPackets[$setId] !== $main) {
                    $par2SetMainPacketConflicts[$setId] = true;
                    $par2Complete = false;
                } else {
                    $par2SetMainPackets[$setId] = $main;
                }
            }
            if (is_int($metadata['source_block_size'] ?? null)) {
                $candidateBlockSize = $metadata['source_block_size'];
                if (isset($par2SetBlockSizes[$setId]) && $par2SetBlockSizes[$setId] !== $candidateBlockSize) {
                    $par2SetBlockSizeConflicts[$setId] = true;
                    $par2Complete = false;
                } else {
                    $par2SetBlockSizes[$setId] = $candidateBlockSize;
                }
            }
            foreach ($metadata['source_files'] ?? [] as $sourceFile) {
                $fileId = (string) ($sourceFile['file_id'] ?? '');
                if ($fileId === '') {
                    continue;
                }
                if (isset($par2SetSources[$setId][$fileId]) && $par2SetSources[$setId][$fileId] !== $sourceFile) {
                    $par2SetSourceConflicts[$setId][$fileId] = true;
                    $par2Complete = false;
                    $par2SourceDefinitionConflictKeys[$setId.':'.$fileId] = true;
                } else {
                    $par2SetSources[$setId][$fileId] = $sourceFile;
                }
            }
            if ($par2File['metadata_valid'] !== true) {
                continue;
            }
            foreach ($par2File['metadata']['packet_summaries'] as $packet) {
                if (($packet['checksum_valid'] ?? false) === true && is_string($packet['set_id'] ?? null)) {
                    $par2SetIds[$packet['set_id']] = true;
                }
                if (($packet['checksum_valid'] ?? false) !== true
                    || ($packet['packet_type'] ?? null) !== 'PAR 2.0\\0RecvSlic'
                    || ! is_string($packet['set_id'] ?? null)
                    || ! is_int($packet['recovery_exponent'] ?? null)
                    || ! is_string($packet['recovery_data_sha256'] ?? null)) {
                    continue;
                }
                $setId = $packet['set_id'];
                $exponent = $packet['recovery_exponent'] === 65535 ? 0 : $packet['recovery_exponent'];
                if (isset($par2RecoverySlicePayloadHashesBySet[$setId][$exponent])
                    && $par2RecoverySlicePayloadHashesBySet[$setId][$exponent] !== $packet['recovery_data_sha256']) {
                    $par2RecoverySliceConflicts[$setId.':'.$exponent] = [
                        'recovery_set_id' => $setId,
                        'exponent' => $exponent,
                    ];
                    $par2Complete = false;
                } else {
                    $par2RecoverySlicePayloadHashesBySet[$setId][$exponent] = $packet['recovery_data_sha256'];
                }
            }
            $par2PackedLayout = $par2PackedLayout || (bool) ($par2File['metadata']['packed_layout_present'] ?? false);
        }
        foreach ($par2SetMainDefinitionHashes as $setId => $definitionHashes) {
            if (count($definitionHashes) > 1) {
                $par2SetMainPacketConflicts[$setId] = true;
                $par2Complete = false;
            }
        }
        foreach ($par2SetSourceDefinitionHashes as $setId => $definitionsByFile) {
            foreach ($definitionsByFile as $fileId => $definitionHashes) {
                if (count($definitionHashes) > 1) {
                    $par2SetSourceConflicts[$setId][$fileId] = true;
                    $par2Complete = false;
                }
            }
        }
        if ($par2IfscObservationsOmitted > 0) {
            $par2Complete = false;
        }
        $par2MainDefinitionConflicts = [];
        foreach (array_keys($par2SetMainPacketConflicts) as $setId) {
            $par2MainDefinitionConflicts[] = [
                'recovery_set_id' => $setId,
                'payload_sha256_variants' => array_keys($par2SetMainDefinitionHashes[$setId] ?? []),
            ];
        }
        $par2SourceDefinitionConflicts = [];
        foreach ($par2SetSourceConflicts as $setId => $filesWithConflicts) {
            foreach (array_keys($filesWithConflicts) as $fileId) {
                $par2SourceDefinitionConflictKeys[$setId.':'.$fileId] = true;
                $par2SourceDefinitionConflicts[] = [
                    'recovery_set_id' => $setId,
                    'file_id' => $fileId,
                    'payload_sha256_variants' => array_keys($par2SetSourceDefinitionHashes[$setId][$fileId] ?? []),
                ];
            }
        }
        foreach (array_keys($par2SetIds) as $setId) {
            $main = $par2SetMainPackets[$setId] ?? null;
            $sourceFiles = $par2SetSources[$setId] ?? [];
            if (! is_array($main)) {
                $par2Complete = false;

                continue;
            }
            $mainFileIds = [...($main['recoverable_file_ids'] ?? []), ...($main['non_recoverable_file_ids'] ?? [])];
            $describedFileIds = array_keys($sourceFiles);
            sort($mainFileIds);
            sort($describedFileIds);
            if ($mainFileIds !== $describedFileIds) {
                $par2Complete = false;
            }
            $blockSize = (int) ($par2SetBlockSizes[$setId] ?? 0);
            $validatedRecoveryPackets = [];
            foreach ($assembledPar2 as $par2File) {
                foreach ($par2File['metadata']['recovery_packets'] ?? [] as $recoveryPacket) {
                    if (($recoveryPacket['recovery_set_id'] ?? null) !== $setId) {
                        continue;
                    }
                    $validatedRecoveryPackets[] = $recoveryPacket;
                    $payloadBytes = $recoveryPacket['payload_bytes'] ?? null;
                    if (! is_int($payloadBytes) || $blockSize < 1 || $payloadBytes !== $blockSize + 4) {
                        $par2Complete = false;
                    }
                }
            }
            $par2RecoveryExponentsBySet[$setId] = array_fill_keys(
                Par2MetadataParser::validatedRecoveryExponents($validatedRecoveryPackets, $blockSize),
                true,
            );
            foreach ($assembledPar2 as $par2File) {
                foreach ($par2File['metadata']['packet_summaries'] ?? [] as $packet) {
                    if (($packet['set_id'] ?? null) === $setId
                        && ($packet['packet_type'] ?? null) === 'PAR 2.0\\0RecvSlic'
                        && ($blockSize < 1 || (int) ($packet['payload_bytes'] ?? 0) !== $blockSize + 4)) {
                        $par2Complete = false;
                    }
                }
            }
            foreach ($sourceFiles as $fileId => $sourceFile) {
                if (($par2SetSourceConflicts[$setId][$fileId] ?? false) === true) {
                    continue;
                }
                $par2Sources[$setId.':'.$fileId] = $sourceFile;
            }
        }
        $par2IfscPreviewBudget = self::MAX_RELEASE_IFSC_PREVIEW_RECORDS;
        $par2Ifsc = $this->mergeInputFileSliceChecksums(
            $par2IfscObservations,
            $par2SetMainPackets,
            $par2SetMainPacketConflicts,
            $par2SetSources,
            $par2SetSourceConflicts,
            $par2SetBlockSizes,
            $par2SetBlockSizeConflicts,
            $par2IfscObservationsOmitted === 0,
            $par2Complete,
            $par2IfscPreviewBudget,
        );
        foreach ($assembledPar2 as &$par2File) {
            if (! is_array($par2File['metadata'] ?? null)) {
                continue;
            }
            unset($par2File['metadata']['input_file_slice_checksums']);
            if (is_array($par2File['metadata']['recovery_sets'] ?? null)) {
                foreach (array_keys($par2File['metadata']['recovery_sets']) as $recoverySetIndex) {
                    unset($par2File['metadata']['recovery_sets'][$recoverySetIndex]['input_file_slice_checksums']);
                }
            }
        }
        unset($par2File);
        $crossVolumeFiles = [];
        foreach ($assembledPar2 as $par2File) {
            $recoveryPackets = array_values(array_filter(
                $par2File['metadata']['recovery_packets'] ?? [],
                static function (array $packet) use ($par2SetBlockSizes): bool {
                    $setId = $packet['recovery_set_id'] ?? null;
                    $blockSize = is_string($setId) ? ($par2SetBlockSizes[$setId] ?? null) : null;

                    return ! is_int($blockSize) || (int) ($packet['payload_bytes'] ?? 0) === $blockSize + 4;
                },
            ));
            $crossVolumeFiles[] = [
                'file_index' => $par2File['file_index'],
                'subject' => $par2File['subject'],
                'recovery_packets' => $recoveryPackets,
            ];
        }
        $crossVolumeRecoveryConflicts = Par2MetadataParser::findCrossVolumeRecoveryConflicts($crossVolumeFiles);
        if ($crossVolumeRecoveryConflicts !== []) {
            $par2Complete = false;
        }
        $multiplePar2Sets = count($par2SetIds) > 1;
        $par2RecoveryExponents = $multiplePar2Sets ? [] : ($par2RecoveryExponentsBySet[array_key_first($par2SetIds)] ?? []);
        $recoveryCount = count($par2RecoveryExponents);
        $parsedRecoveryAcrossSets = array_sum(array_map('count', $par2RecoveryExponentsBySet));
        $par2SourceBlocks = null;
        $par2SourceBlocksOverflow = false;
        $par2SourceBlocksWithinLimit = null;
        $par2SourceBlockBasis = $multiplePar2Sets
            ? 'unavailable-multiple-recovery-sets' : 'unavailable-source-block-denominator';
        if (! $multiplePar2Sets && $par2SetIds !== []) {
            $singleSetId = array_key_first($par2SetIds);
            $main = $par2SetMainPackets[$singleSetId] ?? null;
            $blockSize = (int) ($par2SetBlockSizes[$singleSetId] ?? 0);
            $layout = $this->computePar2SourceBlockLayout(
                is_array($main) ? $main : null,
                $blockSize,
                $par2SetSources[$singleSetId] ?? [],
                $par2SetSourceConflicts[$singleSetId] ?? [],
                ($par2SetMainPacketConflicts[$singleSetId] ?? false) === true
                    || ($par2SetBlockSizeConflicts[$singleSetId] ?? false) === true,
            );
            $par2SourceBlocks = $layout['source_block_count'];
            $par2SourceBlocksOverflow = $layout['source_block_count_overflow'];
            $par2SourceBlocksWithinLimit = $layout['source_block_count_within_current_v2_limit'];
            $par2SourceBlockBasis = $layout['recovery_percentage_basis'];
            if (! $layout['complete']) {
                $par2Complete = false;
            }
        }
        $par2SourceFiles = array_values($par2Sources);
        $par2SourcePrefixRelationships = Par2MetadataParser::relateNzbFilePrefixesToSources(
            $manifest['files'],
            $observations,
            $par2SourceFiles,
            array_keys($par2SourceDefinitionConflictKeys),
        );
        $par2SourceRarFiles = array_values(array_filter($par2SourceFiles, static fn (array $sourceFile): bool => preg_match(
            '/(?:\.part\d+\.rar|\.rar|\.r\d{2,})\z/i',
            (string) ($sourceFile['filename'] ?? ''),
        ) === 1));
        $par2SourceFileTotalBytes = $this->sourceFileLengthTotal($par2SourceFiles);
        $par2SourceRarTotalBytes = $this->sourceFileLengthTotal($par2SourceRarFiles);
        $par2SourceSizeTotalsAvailable = $par2SourceFileTotalBytes !== null && $par2SourceRarTotalBytes !== null;
        if (! $par2SourceSizeTotalsAvailable || $yencSizes['rar'] === null || $yencSizes['par2'] === null) {
            $par2Complete = false;
        }
        $par2SourceArticleMatches = $this->matchPar2SourcesToNzbFiles($par2SourceFiles, $observations);
        $par2SourceSliceRelationships = $this->resolvePar2SourceSliceRelationships(
            $par2SourceFiles,
            $par2Ifsc['files'],
            $par2SourceSamples,
            $observations,
            $par2SourceSampleRetainedBytes,
            $par2SourceSampleOmittedCount,
        );
        $par2RecoveryPercent = $par2Complete && $par2RecoverySliceConflicts === [] && ! $multiplePar2Sets && is_int($par2SourceBlocks) && $par2SourceBlocks > 0 && $par2SourceBlocksWithinLimit === true && ! $par2PackedLayout
            ? round($recoveryCount / $par2SourceBlocks * 100, 3)
            : null;
        $yencFileLengthPercent = $yencSizeCounts['rar'] > 0 && $yencSizeCounts['par2'] > 0
            && is_int($yencSizes['rar']) && is_int($yencSizes['par2']) && $yencSizes['rar'] > 0
            ? round($yencSizes['par2'] / $yencSizes['rar'] * 100, 3)
            : null;

        $comparisons = $this->compareProviders($observations);
        $timing = $this->estimatePostingTimeline(
            $manifest['files'],
            $observations,
            $servers,
            count(array_unique(array_column($samples, 'messageid'))),
        );
        $completePrimarySamples = count(array_filter($samples, fn (array $sample): bool => $this->primaryObservation($sample['file_index'], $observations, $sample['messageid']) !== null));
        $allFilesSampled = count(array_unique(array_column($samples, 'file_index'))) === count($manifest['files']);
        $allFilesHaveSegments = count(array_filter(
            $manifest['files'],
            static fn (array $file): bool => ($file['segments'] ?? []) !== [],
        )) === count($manifest['files']);
        $manifestIntegrityValid = ($manifest['advertised_byte_totals_valid'] ?? false) === true && count(array_filter(
            $manifest['files'],
            static fn (array $file): bool => ($file['segment_number_sequence_valid'] ?? false) === true
                && ($file['invalid_segment_record_count'] ?? 0) === 0
                && ($file['invalid_segment_metadata_count'] ?? 0) === 0
                && ($file['duplicate_message_id_count'] ?? 0) === 0
                && ($file['duplicate_segment_number_count'] ?? 0) === 0,
        )) === count($manifest['files']);
        $allSamplesAvailable = $samples !== []
            && $completePrimarySamples === count($samples)
            && $allFilesSampled
            && $allFilesHaveSegments
            && $manifestIntegrityValid;
        $providerCoverageComplete = count($servers) < 2 || count($comparisons) >= count(array_unique(array_column($samples, 'messageid')));
        $providerHeaderComparisonRows = [];
        foreach ($comparisons as $comparison) {
            array_push($providerHeaderComparisonRows, ...($comparison['ordered_occurrence_comparisons'] ?? []));
        }
        $providerHeaderComparisonCoverageComplete = count($servers) < 2
            || ($providerCoverageComplete && $providerHeaderComparisonRows !== []
                && count(array_filter(
                    $providerHeaderComparisonRows,
                    static fn (array $comparison): bool => ($comparison['comparison_status'] ?? null) !== 'available',
                )) === 0);
        $profileCoverageComplete = count($serverProfiles) === count($servers)
            && count(array_filter($serverProfiles, static fn (array $profile): bool => ! isset($profile['profile_error_type'])
                && ! isset($profile['profile']['capabilities_error_type'])
                && ! isset($profile['profile']['overview_format_error_type'])
                // Explicit unsupported commands are valid observations; transport
                // failures and other unexpected responses need another collection.
                && in_array($profile['profile']['capabilities_response_code'] ?? null, [101, 500, 501], true)
                && (($profile['profile']['overview_format_response_code'] ?? null) === null
                    || in_array($profile['profile']['overview_format_response_code'], [215, 500, 501], true)))) === count($servers);
        $identityParserCoverageComplete = $observations !== [] && count(array_filter(
            $observations,
            static fn (array $observation): bool => ($observation['metadata']['article_identity']['parser_engine'] ?? null)
                === 'nntmux-rust-article-identity'
                && ($observation['metadata']['article_identity']['schema_version'] ?? null) === 3
                && ($observation['metadata']['article_identity']['request']['syntax_status'] ?? null) === 'valid',
        )) === count($observations);
        $headerParserCoverageComplete = $observations !== [] && count(array_filter(
            $observations,
            static fn (array $observation): bool => NativeMetadataParser::hasCurrentNntpHeaderSchema(
                $observation['metadata']['nntp_header_metadata'] ?? null,
            ),
        )) === count($observations);
        $xrefParserCoverageComplete = $observations !== [] && count(array_filter(
            $observations,
            static fn (array $observation): bool => ($observation['metadata']['article_xref']['parser_engine'] ?? null)
                === 'nntmux-rust-article-xref'
                && ($observation['metadata']['article_xref']['schema_version'] ?? null) === 1,
        )) === count($observations);
        $dateParserCoverageComplete = self::articleDateParserCoverageComplete($observations);
        $yencEscapeStateValid = count(array_filter(
            $observations,
            static fn (array $observation): bool => ($observation['metadata']['yenc']['payload_escape_state_valid'] ?? null) === false,
        )) === 0;
        $yencNumericFieldsValid = count(array_filter(
            $observations,
            static fn (array $observation): bool => ($observation['metadata']['yenc']['header_numeric_fields_valid'] ?? null) === false
                || ($observation['metadata']['yenc']['trailer_numeric_fields_valid'] ?? null) === false
                || ($observation['metadata']['yenc']['ypart_range_valid'] ?? null) === false,
        )) === 0;
        $status = $allSamplesAvailable && $par2Complete && $providerCoverageComplete && $providerHeaderComparisonCoverageComplete && $profileCoverageComplete
            && $identityParserCoverageComplete && $headerParserCoverageComplete && $xrefParserCoverageComplete && $dateParserCoverageComplete
            && $yencEscapeStateValid && $yencNumericFieldsValid && $omittedSampleCount === 0 && ! $par2BudgetExceeded ? 'complete' : 'partial';

        return [
            'status' => $status,
            'data' => [
                'schema_version' => self::SCHEMA_VERSION,
                'article_identity_parser_coverage_complete' => $identityParserCoverageComplete,
                'article_xref_parser_coverage_complete' => $xrefParserCoverageComplete,
                'article_date_parser_coverage_complete' => $dateParserCoverageComplete,
                'collection_policy' => [
                    'all_nzb_files_listed' => true,
                    'non_par2_article_samples_per_file' => 'first, middle, and last segment; repeated Message-IDs are deduplicated within each NZB file',
                    'par2_segments_requested' => $omittedSampleCount === 0 && ! $par2BudgetExceeded ? 'all' : 'bounded',
                    'article_sample_limit' => NzbFingerprintAnalyzer::MAX_ARTICLE_SAMPLES,
                    'article_samples_selected' => count($samples),
                    'article_samples_omitted_due_to_limit' => $omittedSampleCount,
                    'maximum_provider_article_attempts' => NzbFingerprintAnalyzer::MAX_ARTICLE_SAMPLES * count($servers),
                    'par2_retained_byte_limit' => self::MAX_PAR2_SET_BYTES,
                    'par2_retained_byte_limit_exceeded' => $par2BudgetExceeded,
                    'par2_provider_attempts_skipped_due_to_byte_limit' => $par2ProviderAttemptsSkipped,
                    'article_body_limit_bytes' => self::MAX_ARTICLE_BODY_BYTES,
                    'providers' => array_column($servers, 'name'),
                    'queried_server_endpoints' => $servers,
                    'server_profiles' => $serverProfiles,
                    'coverage_note' => 'First, middle, and last RAR/other article bodies and PAR2 segments are requested within the release article budget. Further PAR2 requests stop when retained bytes exceed their budget. Omitted samples force partial coverage. Provider-specific raw headers are retained for every collected article.',
                ],
                'nzb' => [
                    'file_count' => $manifest['file_count'],
                    'meta' => $manifest['nzb_meta_values'],
                    'totals' => $manifest['totals'],
                    'advertised_byte_totals_valid' => $manifest['advertised_byte_totals_valid'],
                    'par2_to_rar_advertised_percent' => $manifest['par2_to_rar_advertised_percent'],
                    'par2_to_rar_percent_basis' => $manifest['par2_to_rar_percent_basis'],
                ],
                'files' => $fileRows,
                'article_observations' => $observations,
                'archive_relationships' => [
                    'par2_source_slices' => $par2SourceSliceRelationships,
                    'seven_zip_next_headers' => [
                        'relationship_count' => count($sevenZipRelationships),
                        'retained_decoded_sample_bytes' => $sevenZipRetainedBytes,
                        'retained_sample_byte_limit' => self::MAX_SEVEN_ZIP_FRAGMENT_BYTES,
                        'omitted_sample_count_due_to_budget' => $sevenZipOmittedSamples,
                        'records' => $sevenZipRelationships,
                    ],
                ],
                'provider_comparisons' => $comparisons,
                'provider_header_occurrence_comparison_coverage_complete' => $providerHeaderComparisonCoverageComplete,
                'posting_timeline_estimate' => $timing,
                'nntp_header_parser_coverage_complete' => $headerParserCoverageComplete,
                'par2' => [
                    'present' => $par2FileCount > 0,
                    'files' => $assembledPar2,
                    'set_ids' => array_keys($par2SetIds),
                    'multiple_recovery_sets' => $multiplePar2Sets,
                    'source_files' => $par2SourceFiles,
                    'source_file_total_bytes' => $par2SourceFileTotalBytes,
                    'source_size_totals_available' => $par2SourceSizeTotalsAvailable,
                    'main_definition_conflicts' => $par2MainDefinitionConflicts,
                    'source_file_definition_conflicts' => $par2SourceDefinitionConflicts,
                    'source_rar_files' => $par2SourceRarFiles,
                    'source_rar_total_bytes' => $par2SourceRarTotalBytes,
                    'source_file_article_matches' => $par2SourceArticleMatches,
                    'input_file_slice_checksums' => $par2Ifsc['files'],
                    'ifsc_conflicts' => $par2Ifsc['conflicts'],
                    'ifsc_slice_count_mismatches' => $par2Ifsc['slice_count_mismatches'],
                    'ifsc_unvalidated_file_ids' => $par2Ifsc['unvalidated_file_ids'],
                    'missing_recoverable_ifsc_file_ids' => $par2Ifsc['missing_recoverable_file_ids'],
                    'ifsc_observations_omitted' => $par2IfscObservationsOmitted,
                    'ifsc_aggregation_complete' => $par2IfscObservationsOmitted === 0,
                    'ifsc_coverage_complete' => $par2Ifsc['coverage_complete'],
                    'ifsc_preview_record_budget' => [
                        'maximum' => self::MAX_RELEASE_IFSC_PREVIEW_RECORDS,
                        'used' => self::MAX_RELEASE_IFSC_PREVIEW_RECORDS - $par2IfscPreviewBudget,
                        'omitted' => $par2Ifsc['preview_records_omitted'],
                        'truncated_variants' => $par2Ifsc['preview_variants_truncated'],
                    ],
                    'ifsc_file_count' => count($par2Ifsc['files']),
                    'ifsc_conflict_count' => count(array_filter(
                        $par2Ifsc['files'],
                        static fn (array $ifsc): bool => $ifsc['duplicate_conflict'] === true,
                    )),
                    'ifsc_validated_count' => count(array_filter(
                        $par2Ifsc['files'],
                        static fn (array $ifsc): bool => $ifsc['validation_status'] === 'validated',
                    )),
                    'unique_recovery_slices' => $multiplePar2Sets ? null : $recoveryCount,
                    'parsed_recovery_exponents_across_sets' => $multiplePar2Sets ? $parsedRecoveryAcrossSets : null,
                    'recovery_slice_conflicts' => array_values($par2RecoverySliceConflicts),
                    'source_block_count' => $par2SourceBlocks ?: null,
                    'source_block_limit' => Par2MetadataParser::MAX_SOURCE_BLOCKS,
                    'source_block_count_within_current_v2_limit' => $par2SourceBlocksWithinLimit,
                    'source_block_count_overflow' => $par2SourceBlocksOverflow,
                    'recovery_percentage_basis' => $this->par2RecoveryPercentageBasis(
                        $par2SourceBlockBasis, $par2SourceBlocksWithinLimit, $par2PackedLayout, $par2RecoveryPercent,
                    ),
                    'nzb_file_prefix_relationships' => $par2SourcePrefixRelationships,
                    'recovery_percent' => $par2RecoveryPercent,
                    'cross_volume_recovery_conflicts' => $crossVolumeRecoveryConflicts,
                    'packed_layout_present' => $par2PackedLayout,
                    'complete' => $par2FileCount > 0 ? $par2Complete : null,
                    'warning' => $par2FileCount === 0
                        ? 'The NZB lists no PAR2 files.'
                        : ($multiplePar2Sets
                            ? 'Multiple PAR2 recovery sets were found; a single aggregate recovery percentage is unavailable.'
                        : ($crossVolumeRecoveryConflicts !== []
                            ? 'Conflicting copies of the same recovery exponent were found across PAR2 volumes; recovery percentage is unavailable.'
                            : ($par2SourceBlocksWithinLimit === false
                                ? 'Source slice count exceeds the current PAR2 v2 limit; recovery percentage is unavailable.'
                                : ($par2Complete ? null : 'PAR2 packets are incomplete; recovery percentage is unavailable.')))),
                ],
                'yenc_file_lengths' => [
                    'rar_bytes' => $yencSizeCounts['rar'] > 0 ? $yencSizes['rar'] : null,
                    'par2_bytes' => $yencSizeCounts['par2'] > 0 ? $yencSizes['par2'] : null,
                    'par2_to_rar_percent' => $yencFileLengthPercent,
                    'excluded_file_size_conflict_counts' => $yencSizeConflictCounts,
                    'basis' => 'One complete-file size per NZB file, included only when the sampled yEnc file_size values agree; totals are partial when files have no yEnc sample or conflicting sizes.',
                ],
                'uploader_software_candidates' => $this->softwareCandidates($observations),
                'errors' => $errors,
                'not_collected' => [
                    'Encrypted RAR header contents and payload filenames cannot be read without a password. File signature detection uses only decoded sampled prefixes.',
                    'ExifTool and TrID are not invoked; current format identification uses bounded Rust headers plus the local PHP fileinfo magic database when available.',
                    'NFO/SFV/SRR sidecars can be listed as NZB files but are not separately decoded as text or release records; local downloader history is outside the NZB.',
                    'Candidate posting software is a fingerprint clue, not confirmed uploader identity.',
                ],
            ],
        ];
    }

    /** @param list<array<string, mixed>> $observations */
    private static function articleDateParserCoverageComplete(array $observations): bool
    {
        return $observations !== [] && count(array_filter(
            $observations,
            static function (array $observation): bool {
                $dates = $observation['metadata']['article_dates'] ?? null;
                if (! NativeMetadataParser::hasCurrentArticleDatesSchema($dates)) {
                    return false;
                }
                foreach (['date', 'injection_date', 'legacy_nntp_posting_date', 'expires'] as $source) {
                    if ($dates[$source]['parse_status'] === 'budget_exceeded') {
                        return false;
                    }
                }

                return true;
            },
        )) === count($observations);
    }

    /**
     * @param  array<string, mixed>  $relationship
     * @param  array<string, mixed>|null  $identity
     * @return array<string, mixed>
     */
    private function relateLinesBodyToRequestedArticle(array $relationship, ?array $identity): array
    {
        $headBodyMatches = $identity['relations']['head_response_matches_body_response'] ?? null;
        $bodyRequestMatches = $identity['relations']['body_response_matches_request'] ?? null;
        $bodyRequestVerified = Par2MetadataParser::bodyResponseIdentityVerified(['article_identity' => $identity]);
        if ($headBodyMatches === true && $bodyRequestVerified) {
            $relationship['response_identity_status'] = 'head_body_and_requested_message_ids_match';

            return $relationship;
        }

        $mismatch = $headBodyMatches === false || $bodyRequestMatches === false;
        $relationship['status'] = $mismatch ? 'article_identity_mismatch' : 'article_identity_unconfirmed';
        $relationship['matches'] = null;
        $relationship['absolute_difference'] = null;
        $relationship['response_identity_status'] = match (true) {
            $headBodyMatches === false => 'head_body_message_ids_differ',
            $bodyRequestMatches === false => 'body_message_id_differs_from_request',
            ! $bodyRequestVerified => 'body_request_message_id_match_unavailable',
            default => 'head_body_message_id_match_unavailable',
        };

        return $relationship;
    }

    /** @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function decodedFileRange(array $metadata, ?string $decodedBytes): array
    {
        if (! Par2MetadataParser::bodyResponseIdentityVerified($metadata)) {
            return ['mapping_status' => 'invalid', 'integrity_status' => 'article_response_identity_unverified'];
        }
        $yenc = $metadata['yenc'] ?? null;
        if (is_array($yenc) && ($yenc['payload_escape_state_valid'] ?? null) === false) {
            return ['mapping_status' => 'invalid', 'integrity_status' => 'invalid_unmatched_escape'];
        }
        if (! is_array($yenc) || ! is_string($decodedBytes)) {
            return ['mapping_status' => 'unavailable', 'integrity_status' => 'unavailable'];
        }

        $decodedSize = strlen($decodedBytes);
        $fileSize = $yenc['file_size'] ?? null;
        $multipart = ($yenc['multipart'] ?? false) === true;
        $rangeValid = $multipart
            ? ($yenc['ypart_range_valid'] ?? false) === true
                && is_int($yenc['begin'] ?? null) && is_int($yenc['end'] ?? null)
                && $yenc['begin'] >= 1 && $yenc['end'] >= $yenc['begin']
                && $decodedSize === $yenc['end'] - $yenc['begin'] + 1
            : is_int($fileSize) && $fileSize === $decodedSize
                && ($yenc['decoded_size_matches_yend_size'] ?? null) !== false;
        if (! $rangeValid || ! is_int($fileSize) || $fileSize < 0) {
            return ['mapping_status' => 'invalid', 'integrity_status' => 'range_or_size_mismatch'];
        }

        $partMatches = ($metadata['yenc_nzb_part_number_matches'] ?? null) === true;
        $totalMatches = ($metadata['yenc_nzb_total_parts_matches'] ?? null) !== false
            && ($metadata['yenc_nzb_trailer_part_number_matches'] ?? null) !== false
            && ($metadata['yenc_nzb_trailer_total_parts_matches'] ?? null) !== false;
        $sizeMatches = ($yenc['header_numeric_fields_valid'] ?? false) === true
            && ($yenc['trailer_numeric_fields_valid'] ?? false) === true
            && ($yenc['trailer_size_is_decimal'] ?? false) === true
            && ($yenc['decoded_size_matches_yenc'] ?? null) === true
            && ($yenc['decoded_size_matches_yend_size'] ?? null) === true;
        $crcSyntaxValid = ($yenc['all_declared_crc32_fields_are_hex'] ?? false) === true;
        $observedCrc = $yenc['observed_decoded_crc32'] ?? null;
        $crcValuesMatch = is_string($observedCrc);
        $segmentCrcFields = ($yenc['part'] ?? null) !== null ? ['pcrc32'] : ['pcrc32', 'crc32'];
        foreach ($segmentCrcFields as $crcField) {
            $declaredCrc = $yenc[$crcField] ?? null;
            if ($declaredCrc !== null && (! is_string($declaredCrc)
                || preg_match('/\A[0-9a-f]{1,8}\z/i', $declaredCrc) !== 1
                || ! is_string($observedCrc)
                || strcasecmp(str_pad($declaredCrc, 8, '0', STR_PAD_LEFT), $observedCrc) !== 0)) {
                $crcValuesMatch = false;
            }
        }
        $crcValid = Par2MetadataParser::declaredYencCrc32Valid($yenc)
            && ($yenc['declared_crc32_matches'] ?? null) === true && ($multipart || $crcValuesMatch);
        $crcDeclared = ($yenc['segment_crc32_basis'] ?? null) !== 'missing-pcrc32'
            && ($yenc['segment_crc32_basis'] ?? null) !== 'missing-crc32';
        $integrityValid = $sizeMatches && $totalMatches && $crcSyntaxValid
            && (! $multipart || ($partMatches && $crcValid))
            && ($multipart || ! $crcDeclared || $crcValid)
            && ($multipart || ($yenc['part'] ?? null) === null);
        if (! $integrityValid) {
            return ['mapping_status' => 'invalid', 'integrity_status' => 'yenc_integrity_mismatch'];
        }

        $start = $multipart ? $yenc['begin'] - 1 : 0;
        $end = $multipart ? $yenc['end'] : $decodedSize;

        return [
            'mapping_status' => 'valid',
            'start_offset' => $start,
            'end_offset_exclusive' => $end,
            'file_size' => $fileSize,
            'integrity_status' => $crcValid ? 'yenc_crc_valid' : 'yenc_crc_not_declared',
        ];
    }

    /** @param array<string, mixed> $metadata
     * @return array<string, mixed>|null
     */
    private function sevenZipAnchor(array $metadata): ?array
    {
        $headers = $metadata['decoded_format_metadata']['headers'] ?? null;
        if (! is_array($headers)) {
            return null;
        }
        foreach ($headers as $header) {
            if (is_array($header) && ($header['format'] ?? null) === 'seven_zip' && ($header['offset'] ?? null) === 0) {
                return $header;
            }
        }

        return null;
    }

    /** @param array<string, array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
     */
    private function resolveSevenZipRelationships(array $candidates): array
    {
        $relationships = [];
        $native = new NativeMetadataParser;
        foreach ($candidates as $candidate) {
            $fragments = $candidate['fragments'] ?? [];
            $declaredSizes = array_keys($candidate['declared_file_sizes'] ?? []);
            $base = [
                'relationship' => 'seven_zip_next_header_from_sampled_articles',
                'nzb_file_index' => $candidate['file_index'],
                'provider' => [
                    'server' => $candidate['server'],
                    'label' => $candidate['provider_label'],
                    'role' => $candidate['server_role'],
                    'port' => $candidate['server_port'],
                    'tls' => $candidate['server_tls'],
                ],
                'anchor_observation' => $candidate['anchor_reference'],
                'anchor_header' => $candidate['anchor_header'],
                'sampled_fragment_refs' => array_column($fragments, 'reference'),
            ];
            if (count($declaredSizes) !== 1 || ! is_int($declaredSizes[0])) {
                $relationships[] = $base + [
                    'resolution_status' => 'inconsistent_or_unavailable_declared_file_size',
                    'declared_file_sizes' => $declaredSizes,
                    'omitted_ranges' => $candidate['budget_omitted_ranges'],
                ];

                continue;
            }
            if (($candidate['anchor_fragment_retained'] ?? false) !== true) {
                $relationships[] = $base + [
                    'resolution_status' => 'budget_exceeded',
                    'declared_file_size' => $declaredSizes[0],
                    'omitted_ranges' => $candidate['budget_omitted_ranges'],
                    'budget_reason' => 'The first 7-Zip header sample was omitted from the release-wide decoded-byte budget.',
                ];

                continue;
            }
            if (count($fragments) > self::MAX_SEVEN_ZIP_FRAGMENTS_PER_RECORD) {
                $relationships[] = $base + [
                    'resolution_status' => 'fragment_count_budget_exceeded',
                    'declared_file_size' => $declaredSizes[0],
                    'omitted_ranges' => $candidate['budget_omitted_ranges'],
                ];

                continue;
            }

            $packed = pack('V', count($fragments)).$this->packUint64($declaredSizes[0]);
            foreach ($fragments as $fragment) {
                $bytes = $fragment['bytes'] ?? null;
                $offset = $fragment['file_offset'] ?? null;
                if (! is_string($bytes) || ! is_int($offset) || $offset < 0 || strlen($bytes) > 0xFFFFFFFF) {
                    $relationships[] = $base + [
                        'resolution_status' => 'invalid_fragment',
                        'declared_file_size' => $declaredSizes[0],
                        'omitted_ranges' => $candidate['budget_omitted_ranges'],
                    ];

                    continue 2;
                }
                $packed .= $this->packUint64($offset).pack('V', strlen($bytes)).$bytes;
            }
            $resolution = $native->resolveSevenZipRanges($packed);
            $references = $base + [
                'declared_file_size' => $declaredSizes[0],
                'omitted_ranges' => $candidate['budget_omitted_ranges'],
            ];
            if (! is_array($resolution)) {
                $relationships[] = $references + ['resolution_status' => 'native_resolver_unavailable'];

                continue;
            }
            $contributorIndexes = $resolution['source_fragment_indexes'] ?? [];
            $resolution['contributing_observations'] = array_values(array_filter(
                array_map(static fn (mixed $index): mixed => is_int($index) ? ($fragments[$index]['reference'] ?? null) : null, $contributorIndexes),
                'is_array',
            ));
            unset($resolution['source_fragment_indexes']);
            $packInfo = $resolution['encoded_header_pack_info'] ?? null;
            if (is_array($packInfo) && is_array($packInfo['streams'] ?? null)) {
                foreach ($packInfo['streams'] as $streamIndex => $stream) {
                    if (! is_array($stream)) {
                        continue;
                    }
                    $streamContributorIndexes = $stream['source_fragment_indexes'] ?? [];
                    $stream['contributing_observations'] = array_values(array_filter(
                        array_map(
                            static fn (mixed $index): mixed => is_int($index) ? ($fragments[$index]['reference'] ?? null) : null,
                            is_array($streamContributorIndexes) ? $streamContributorIndexes : [],
                        ),
                        'is_array',
                    ));
                    unset($stream['source_fragment_indexes']);
                    if (($stream['missing_ranges'] ?? []) !== [] && $this->omittedRangesIntersect(
                        $candidate['budget_omitted_ranges'] ?? [],
                        $stream['missing_ranges'],
                    )) {
                        $stream['underlying_resolution_status'] = $stream['resolution_status'] ?? null;
                        $stream['resolution_status'] = 'budget_exceeded';
                    }
                    $packInfo['streams'][$streamIndex] = $stream;
                }
                $resolution['encoded_header_pack_info'] = $packInfo;
            }
            if (($resolution['missing_ranges'] ?? []) !== [] && $this->omittedRangesIntersect(
                $candidate['budget_omitted_ranges'] ?? [],
                $resolution['missing_ranges'],
            )) {
                $resolution['underlying_resolution_status'] = $resolution['resolution_status'] ?? null;
                $resolution['resolution_status'] = 'budget_exceeded';
            }
            $relationships[] = $references + $resolution;
        }

        return $relationships;
    }

    private function packUint64(int $value): string
    {
        return pack('V2', $value & 0xFFFFFFFF, intdiv($value, 4_294_967_296));
    }

    /** @param list<array{start: int, end_exclusive: int}> $omittedRanges
     * @param  list<array{start: int, end_exclusive: int}>  $missingRanges
     */
    private function omittedRangesIntersect(array $omittedRanges, array $missingRanges): bool
    {
        foreach ($omittedRanges as $omitted) {
            foreach ($missingRanges as $missing) {
                if (($omitted['start'] ?? 0) < ($missing['end_exclusive'] ?? 0)
                    && ($missing['start'] ?? 0) < ($omitted['end_exclusive'] ?? 0)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $sourceFiles
     * @param  list<array<string, mixed>>  $ifscFiles
     * @param  array<string, array<string, mixed>>  $sampledFiles
     * @param  list<array<string, mixed>>  $observations
     * @return array<string, mixed>
     */
    private function resolvePar2SourceSliceRelationships(
        array $sourceFiles,
        array $ifscFiles,
        array $sampledFiles,
        array $observations,
        int $retainedBytes,
        int $omittedSamples,
    ): array {
        $sourcesByPrefix = [];
        foreach ($sourceFiles as $sourceFile) {
            $prefixMd5 = strtolower((string) ($sourceFile['md5_first_16k'] ?? ''));
            if (preg_match('/\A[a-f0-9]{32}\z/D', $prefixMd5) === 1) {
                $sourcesByPrefix[$prefixMd5][] = $sourceFile;
            }
        }

        $ifscBySource = [];
        foreach ($ifscFiles as $ifscFile) {
            if (! is_array($ifscFile) || ! is_string($ifscFile['recovery_set_id'] ?? null)
                || ! is_string($ifscFile['file_id'] ?? null)) {
                continue;
            }
            $ifscBySource[strtolower($ifscFile['recovery_set_id'].':'.$ifscFile['file_id'])] = $ifscFile;
        }

        $native = new NativeMetadataParser;
        $records = [];
        $omittedRelationshipCount = 0;
        $resolutionWorkBytes = 0;
        $resolutionWorkBudgetOmittedCount = 0;
        foreach ($sampledFiles as $sampledFile) {
            if (count($records) >= self::MAX_PAR2_SOURCE_RELATIONSHIPS || ! is_array($sampledFile)) {
                $omittedRelationshipCount++;

                continue;
            }
            $firstSample = $sampledFile['first_sample'] ?? null;
            $prefixMd5 = is_array($firstSample) ? strtolower((string) ($firstSample['prefix_md5'] ?? '')) : '';
            $fileLength = is_array($firstSample) && is_int($firstSample['file_length'] ?? null)
                ? $firstSample['file_length'] : null;
            $prefixCandidates = preg_match('/\A[a-f0-9]{32}\z/D', $prefixMd5) === 1
                ? ($sourcesByPrefix[$prefixMd5] ?? []) : [];
            $lengthCandidates = array_values(array_filter(
                $prefixCandidates,
                static fn (array $sourceFile): bool => $fileLength !== null
                    && is_int($sourceFile['file_length'] ?? null)
                    && $sourceFile['file_length'] === $fileLength,
            ));
            $unknownLengthCandidateCount = count(array_filter(
                $prefixCandidates,
                static fn (array $sourceFile): bool => ! is_int($sourceFile['file_length'] ?? null),
            ));
            $declaredFileSizes = array_map('intval', array_keys($sampledFile['declared_file_sizes'] ?? []));
            $sampleFileLengthConflict = count(array_unique($declaredFileSizes)) > 1
                || ($fileLength !== null && $declaredFileSizes !== [] && ! in_array($fileLength, $declaredFileSizes, true));
            $sourceStatus = $sampleFileLengthConflict ? 'contradictory_sample_file_lengths'
                : ($prefixMd5 === '' ? 'insufficient_prefix'
                : ($prefixCandidates === [] ? 'unmatched'
                    : ($fileLength === null || $unknownLengthCandidateCount > 0 ? 'length_unavailable'
                        : (count($lengthCandidates) === 1 ? 'matched_prefix_and_length'
                            : (count($lengthCandidates) > 1 ? 'ambiguous' : 'contradictory_length')))));
            $matchedSource = $sourceStatus === 'matched_prefix_and_length' ? $lengthCandidates[0] : null;
            $ifscKey = is_array($matchedSource)
                ? strtolower((string) ($matchedSource['recovery_set_id'] ?? '').':'.(string) ($matchedSource['file_id'] ?? ''))
                : null;
            $ifsc = is_string($ifscKey) ? ($ifscBySource[$ifscKey] ?? null) : null;
            $variant = is_array($ifsc) && count($ifsc['variants'] ?? []) === 1
                ? $ifsc['variants'][0] : null;
            $ifscStatus = ! is_array($ifsc) ? 'ifsc_unavailable'
                : (($ifsc['validation_status'] ?? null) !== 'validated' ? (string) ($ifsc['validation_status'] ?? 'unvalidated')
                    : (($ifsc['duplicate_conflict'] ?? false) === true || ($ifsc['definition_conflict'] ?? false) === true
                        ? 'definition_conflict'
                        : (! is_array($variant) ? 'ifsc_variant_unavailable' : 'validated')));
            $slices = is_array($variant) && is_array($variant['slice_preview'] ?? null)
                ? $variant['slice_preview'] : [];
            $usableSlices = array_values(array_filter($slices, static fn (array $slice): bool => ($slice['source_range_valid'] ?? false) === true
                && is_int($slice['slice_index'] ?? null)
                && is_int($slice['source_byte_start'] ?? null)
                && is_int($slice['source_byte_end_exclusive'] ?? null)
                && is_int($slice['zero_padding_bytes'] ?? null)
                && is_string($slice['md5'] ?? null)
                && is_int($slice['crc32'] ?? null)));
            $resolution = null;
            $resolutionWorkBudgetExceeded = false;
            if ($sourceStatus === 'matched_prefix_and_length' && $ifscStatus === 'validated'
                && $fileLength !== null && $usableSlices !== []) {
                $sliceWorkBytes = array_reduce($usableSlices, static function (int $total, array $slice): int {
                    $sourceBytes = max(0, $slice['source_byte_end_exclusive'] - $slice['source_byte_start']);
                    $workBytes = $sourceBytes + max(0, $slice['zero_padding_bytes']);

                    return $total > self::MAX_PAR2_SOURCE_RESOLUTION_WORK_BYTES - $workBytes
                        ? self::MAX_PAR2_SOURCE_RESOLUTION_WORK_BYTES + 1
                        : $total + $workBytes;
                }, 0);
                if ($sliceWorkBytes <= self::MAX_PAR2_SOURCE_RESOLUTION_WORK_BYTES - $resolutionWorkBytes) {
                    $packed = $this->packPar2SourceRangeInput(
                        $fileLength,
                        $sampledFile['fragments'] ?? [],
                        $usableSlices,
                    );
                    if (is_string($packed)) {
                        $resolutionWorkBytes += $sliceWorkBytes;
                        $resolution = $native->resolvePar2SourceRanges($packed);
                    }
                } else {
                    $resolutionWorkBudgetExceeded = true;
                    $resolutionWorkBudgetOmittedCount++;
                }
            }

            $sliceResults = is_array($resolution['slices'] ?? null) ? $resolution['slices'] : [];
            foreach ($sliceResults as &$sliceResult) {
                if (! is_array($sliceResult)) {
                    continue;
                }
                $references = [];
                foreach ($sliceResult['source_fragment_indexes'] ?? [] as $fragmentIndex) {
                    if (is_int($fragmentIndex) && is_array($sampledFile['fragments'][$fragmentIndex]['reference'] ?? null)) {
                        $references[] = $sampledFile['fragments'][$fragmentIndex]['reference'];
                    }
                }
                $sliceResult['source_article_references'] = $references;
            }
            unset($sliceResult);
            $ifscOrigins = $ifsc['variants'][0]['origins'] ?? [];
            foreach ($ifscOrigins as &$origin) {
                if (! is_array($origin) || ! is_int($origin['file_index'] ?? null)) {
                    continue;
                }
                $originFileIndex = $origin['file_index'];
                $originArticles = array_values(array_filter($observations, static fn (array $observation): bool => ($observation['file_index'] ?? null) === $originFileIndex
                    && ($observation['kind'] ?? null) === 'par2'
                    && ($observation['server_role'] ?? null) === 'primary'));
                $origin['article_references'] = array_map(static fn (array $observation): array => [
                    'message_id' => $observation['message_id'] ?? null,
                    'segment_number' => $observation['segment_number'] ?? null,
                    'server' => $observation['server'] ?? null,
                    'provider_label' => $observation['provider_label'] ?? null,
                    'server_port' => $observation['server_port'] ?? null,
                    'server_tls' => $observation['server_tls'] ?? null,
                ], $originArticles);
            }
            unset($origin);

            $records[] = [
                'relationship' => 'par2_ifsc_slice_to_sampled_source_bytes',
                'source_relationship_status' => $sourceStatus,
                'ifsc_definition_status' => $ifscStatus,
                'recovery_set_id' => is_array($matchedSource) ? ($matchedSource['recovery_set_id'] ?? null) : null,
                'file_id' => is_array($matchedSource) ? ($matchedSource['file_id'] ?? null) : null,
                'par2_filename' => is_array($matchedSource) ? ($matchedSource['filename'] ?? null) : null,
                'source_candidates' => array_map(static fn (array $sourceFile): array => [
                    'recovery_set_id' => $sourceFile['recovery_set_id'] ?? null,
                    'file_id' => $sourceFile['file_id'] ?? null,
                    'filename' => $sourceFile['filename'] ?? null,
                    'file_length' => $sourceFile['file_length'] ?? null,
                ], $prefixCandidates),
                'nzb_file_index' => $sampledFile['file_index'] ?? null,
                'nzb_kind' => $sampledFile['kind'] ?? null,
                'nzb_subject' => $sampledFile['subject'] ?? null,
                'sampled_yenc_declared_file_sizes' => $declaredFileSizes,
                'provider' => $sampledFile['provider'] ?? null,
                'source_file_length' => $fileLength,
                'source_block_size' => $ifsc['source_block_size'] ?? null,
                'observed_first_16k_md5' => preg_match('/\A[a-f0-9]{32}\z/D', $prefixMd5) === 1 ? $prefixMd5 : null,
                'ifsc_origins' => $ifscOrigins,
                'preview_scope' => [
                    'available_slices' => count($slices),
                    'omitted_slices' => is_array($variant) ? max(0, (int) ($variant['slice_count'] ?? 0) - count($slices)) : 0,
                    'truncated' => is_array($variant) && ($variant['slice_preview_truncated'] ?? false) === true,
                ],
                'sampled_fragment_count' => count($sampledFile['fragments'] ?? []),
                'sampled_ranges_omitted_due_to_budget' => $sampledFile['budget_omitted_ranges'] ?? [],
                'resolution_status' => is_array($resolution)
                    ? ($resolution['resolution_status'] ?? 'native_resolution_incomplete')
                    : ($resolutionWorkBudgetExceeded ? 'resolution_work_budget_exceeded'
                        : ($sourceStatus !== 'matched_prefix_and_length' ? 'source_unresolved'
                        : ($ifscStatus !== 'validated' ? 'ifsc_unvalidated'
                            : ($usableSlices === [] ? 'ifsc_preview_unavailable' : 'native_resolution_unavailable')))),
                'verified_slice_count' => count(array_filter($sliceResults, static fn (array $slice): bool => ($slice['status'] ?? null) === 'resolved_checksum_valid')),
                'mismatched_slice_count' => count(array_filter($sliceResults, static fn (array $slice): bool => ($slice['status'] ?? null) === 'resolved_checksum_mismatch')),
                'slice_results' => $sliceResults,
            ];
        }

        return [
            'basis' => 'A unique PAR2 FileDesc match requires the first decoded 16 KiB MD5 candidate and whole-file length. Rust compares available provider-local decoded yEnc ranges against validated PAR2 IFSC MD5 and CRC32 records; incomplete coverage is reported without padding missing bytes. Zero padding is applied only after the exact source-file end for the final PAR2 slice.',
            'retained_decoded_sample_bytes' => $retainedBytes,
            'retained_sample_byte_limit' => self::MAX_PAR2_SOURCE_SAMPLE_BYTES,
            'resolution_work_bytes' => $resolutionWorkBytes,
            'resolution_work_byte_limit' => self::MAX_PAR2_SOURCE_RESOLUTION_WORK_BYTES,
            'resolution_work_budget_omitted_count' => $resolutionWorkBudgetOmittedCount,
            'omitted_sample_count_due_to_budget' => $omittedSamples,
            'omitted_relationship_count' => $omittedRelationshipCount,
            'maximum_relationships' => self::MAX_PAR2_SOURCE_RELATIONSHIPS,
            'records' => $records,
        ];
    }

    /** @param list<array<string, mixed>> $fragments
     * @param  list<array<string, mixed>>  $slices
     */
    private function packPar2SourceRangeInput(int $fileLength, array $fragments, array $slices): ?string
    {
        if ($fileLength < 1 || count($fragments) > self::MAX_PAR2_SOURCE_FRAGMENTS_PER_RECORD
            || $slices === [] || count($slices) > self::MAX_IFSC_STRUCTURED_PREVIEW_SLICES) {
            return null;
        }
        $packed = 'P2SR'.pack('V2P', count($fragments), count($slices), $fileLength);
        foreach ($fragments as $fragment) {
            $bytes = $fragment['bytes'] ?? null;
            $start = $fragment['file_offset'] ?? null;
            if (! is_string($bytes) || ! is_int($start) || $start < 0 || strlen($bytes) > 0xFFFFFFFF) {
                return null;
            }
            $packed .= pack('PV', $start, strlen($bytes)).$bytes;
        }
        foreach ($slices as $slice) {
            $md5 = $slice['md5'] ?? null;
            $index = $slice['slice_index'] ?? null;
            $start = $slice['source_byte_start'] ?? null;
            $end = $slice['source_byte_end_exclusive'] ?? null;
            $padding = $slice['zero_padding_bytes'] ?? null;
            $crc32 = $slice['crc32'] ?? null;
            if (! is_string($md5) || preg_match('/\A[a-f0-9]{32}\z/i', $md5) !== 1
                || ! is_int($index) || ! is_int($start) || ! is_int($end) || ! is_int($padding) || ! is_int($crc32)) {
                return null;
            }
            $packed .= pack('VPPP', $index, $start, $end, $padding).hex2bin($md5).pack('V', $crc32);
        }

        return strlen($packed) <= self::MAX_PAR2_SOURCE_SAMPLE_BYTES + 16_384 ? $packed : null;
    }

    /** @param list<array<string, mixed>> $sourceFiles
     * @param  list<array<string, mixed>>  $observations
     * @return array{basis: string, matched_prefix_and_length_count: int, ambiguous_count: int, unmatched_count: int, contradictory_length_count: int, insufficient_prefix_count: int, length_unavailable_count: int, integrity_conflict_count: int, matches: list<array<string, mixed>>}
     */
    private function matchPar2SourcesToNzbFiles(array $sourceFiles, array $observations): array
    {
        $sourcesByPrefixMd5 = [];
        foreach ($sourceFiles as $sourceFile) {
            $prefixMd5 = strtolower((string) ($sourceFile['md5_first_16k'] ?? ''));
            if (preg_match('/\A[a-f0-9]{32}\z/D', $prefixMd5) === 1) {
                $sourcesByPrefixMd5[$prefixMd5][] = $sourceFile;
            }
        }

        $matches = [];
        foreach ($observations as $observation) {
            if (($observation['sample_role'] ?? null) !== 'first'
                || ($observation['segment_number'] ?? null) !== 1
                || ! is_int($observation['file_index'] ?? null)) {
                continue;
            }
            if (($observation['kind'] ?? null) === 'par2') {
                continue;
            }
            $signatures = $observation['metadata']['decoded_format_metadata']['signatures'] ?? [];
            $signaturesAtStart = array_values(array_filter(
                is_array($signatures) ? $signatures : [],
                static fn (mixed $signature): bool => is_array($signature)
                    && ($signature['offset'] ?? null) === 0
                    && is_string($signature['format'] ?? null),
            ));
            $yenc = $observation['metadata']['yenc'] ?? null;
            $integrityConflicts = [];
            if (! Par2MetadataParser::bodyResponseIdentityVerified($observation['metadata'] ?? [])) {
                $integrityConflicts[] = 'article_response_identity_unverified';
            }
            if (is_array($yenc)) {
                foreach ([
                    'payload_escape_state_valid' => 'unmatched_yenc_escape',
                    'header_numeric_fields_valid' => 'invalid_yenc_numeric_fields',
                    'trailer_numeric_fields_valid' => 'invalid_yend_numeric_fields',
                    'decoded_size_matches_header_size' => 'decoded_size_header_size_mismatch',
                    'decoded_size_matches_ypart_range' => 'decoded_size_ypart_range_mismatch',
                    'decoded_size_matches_yend_size' => 'decoded_size_yend_size_mismatch',
                    'trailer_size_is_decimal' => 'invalid_yend_size',
                    'all_declared_crc32_fields_are_hex' => 'invalid_declared_crc32_field',
                    'declared_crc32_matches' => 'declared_crc32_mismatch',
                    'trailer_part_matches_header' => 'yend_part_header_mismatch',
                    'trailer_total_matches_header' => 'yend_total_header_mismatch',
                ] as $field => $conflict) {
                    if (($yenc[$field] ?? null) === false) {
                        $integrityConflicts[] = $conflict;
                    }
                }
            }
            foreach ([
                'yenc_nzb_part_number_matches' => 'yenc_part_number_nzb_mismatch',
                'yenc_nzb_total_parts_matches' => 'yenc_total_parts_nzb_mismatch',
                'yenc_nzb_trailer_part_number_matches' => 'yend_part_number_nzb_mismatch',
                'yenc_nzb_trailer_total_parts_matches' => 'yend_total_parts_nzb_mismatch',
            ] as $field => $conflict) {
                if (($observation['metadata'][$field] ?? null) === false) {
                    $integrityConflicts[] = $conflict;
                }
            }
            $prefixMd5 = is_array($yenc) ? strtolower((string) ($yenc['par2_first_16k_md5_candidate'] ?? '')) : '';
            $hasPrefixMd5 = preg_match('/\A[a-f0-9]{32}\z/D', $prefixMd5) === 1;
            $hashCandidates = $hasPrefixMd5 ? ($sourcesByPrefixMd5[$prefixMd5] ?? []) : [];
            $expectedLength = is_array($yenc) && is_int($yenc['file_size'] ?? null) ? $yenc['file_size'] : null;
            $exactLengthCandidates = array_values(array_filter(
                $hashCandidates,
                static fn (array $sourceFile): bool => is_int($sourceFile['file_length'] ?? null)
                    && $expectedLength !== null && $sourceFile['file_length'] === $expectedLength,
            ));
            $unknownLengthCandidateCount = count(array_filter(
                $hashCandidates,
                static fn (array $sourceFile): bool => ! is_int($sourceFile['file_length'] ?? null),
            ));
            if (! $hasPrefixMd5) {
                $matchStatus = 'insufficient_prefix';
            } elseif ($hashCandidates === []) {
                $matchStatus = 'unmatched';
            } elseif ($expectedLength === null
                || ($unknownLengthCandidateCount > 0 && count($exactLengthCandidates) === 1)) {
                $matchStatus = 'length_unavailable';
            } elseif (count($exactLengthCandidates) === 1) {
                $matchStatus = 'matched_prefix_and_length';
            } elseif (count($exactLengthCandidates) > 1) {
                $matchStatus = 'ambiguous';
            } elseif ($unknownLengthCandidateCount > 0) {
                $matchStatus = 'length_unavailable';
            } else {
                $matchStatus = 'contradictory_length';
            }
            $prefixAndLengthStatus = $matchStatus;
            if ($integrityConflicts !== []) {
                $matchStatus = 'integrity_conflict';
            }

            $messageId = (string) ($observation['message_id'] ?? '');
            if ($messageId === '') {
                continue;
            }
            $relationshipSources = $prefixAndLengthStatus === 'matched_prefix_and_length'
                ? $exactLengthCandidates : $hashCandidates;
            $candidateIds = array_map(
                static fn (array $sourceFile): string => (string) ($sourceFile['recovery_set_id'] ?? '').':'.strtolower((string) ($sourceFile['file_id'] ?? '')),
                $relationshipSources,
            );
            sort($candidateIds);
            $key = implode("\0", [
                (string) $observation['file_index'],
                $messageId,
                $expectedLength === null ? 'length-unknown' : (string) $expectedLength,
                $hasPrefixMd5 ? $prefixMd5 : 'insufficient-prefix',
                $matchStatus,
                implode(',', $integrityConflicts),
                implode(',', $candidateIds),
            ]);
            $matches[$key] ??= [
                'relationship' => 'par2_source_file_to_nzb_file',
                'status' => $matchStatus,
                'prefix_and_length_status' => $prefixAndLengthStatus,
                'integrity_conflicts' => $integrityConflicts,
                'nzb_file_index' => $observation['file_index'],
                'nzb_file_kind' => $observation['kind'] ?? null,
                'nzb_subject' => $observation['nzb_subject'] ?? null,
                'decoded_format_signatures_at_offset_zero' => array_map(static fn (array $signature): array => [
                    'format' => $signature['format'],
                    'basis' => is_string($signature['basis'] ?? null) ? $signature['basis'] : null,
                ], $signaturesAtStart),
                'message_id' => $messageId,
                'observed_first_16k_md5' => $hasPrefixMd5 ? $prefixMd5 : null,
                'observed_yenc_file_length' => $expectedLength,
                'candidate_source_files' => array_map(static fn (array $sourceFile): array => [
                    'recovery_set_id' => $sourceFile['recovery_set_id'] ?? null,
                    'file_id' => $sourceFile['file_id'] ?? null,
                    'filename' => $sourceFile['filename'] ?? null,
                    'file_length' => $sourceFile['file_length'] ?? null,
                ], $relationshipSources),
                'provider_observations' => [],
            ];
            $matches[$key]['provider_observations'][] = [
                'server' => $observation['server'] ?? null,
                'provider_label' => $observation['provider_label'] ?? null,
            ];
        }

        foreach ($matches as &$match) {
            $uniqueProviders = [];
            foreach ($match['provider_observations'] as $providerObservation) {
                $providerKey = json_encode($providerObservation, JSON_INVALID_UTF8_SUBSTITUTE);
                if (is_string($providerKey)) {
                    $uniqueProviders[$providerKey] = $providerObservation;
                }
            }
            $match['provider_observations'] = array_values($uniqueProviders);
        }
        unset($match);

        return [
            'basis' => 'Compare PAR2 FileDesc md5_first_16k with the first 16384 decoded bytes from NZB segment 1 for every non-PAR2 file, regardless of its recognized format signature; multipart yEnc samples must declare a valid range beginning at byte 1. A unique prefix-hash and whole-file length match is labeled matched_prefix_and_length only when available yEnc part numbering, numeric fields, size fields, and declared CRC checks have no contradiction; this does not verify the remaining file bytes. Format signatures are supporting byte-pattern observations, not proof of file type.',
            'matched_prefix_and_length_count' => count(array_filter($matches, static fn (array $match): bool => $match['status'] === 'matched_prefix_and_length')),
            'ambiguous_count' => count(array_filter($matches, static fn (array $match): bool => $match['status'] === 'ambiguous')),
            'unmatched_count' => count(array_filter($matches, static fn (array $match): bool => $match['status'] === 'unmatched')),
            'contradictory_length_count' => count(array_filter($matches, static fn (array $match): bool => $match['status'] === 'contradictory_length')),
            'insufficient_prefix_count' => count(array_filter($matches, static fn (array $match): bool => $match['status'] === 'insufficient_prefix')),
            'length_unavailable_count' => count(array_filter($matches, static fn (array $match): bool => $match['status'] === 'length_unavailable')),
            'integrity_conflict_count' => count(array_filter($matches, static fn (array $match): bool => $match['status'] === 'integrity_conflict')),
            'matches' => array_values($matches),
        ];
    }

    /**
     * Merge per-volume IFSC records against the release-wide PAR2 definitions.
     * Repeated packets describe the same complete checksum sequence; they are
     * never concatenated.
     *
     * @param  array<string, array<string, list<array<string, mixed>>>>  $observations
     * @param  array<string, array<string, mixed>>  $mainPackets
     * @param  array<string, bool>  $mainPacketConflicts
     * @param  array<string, array<string, array<string, mixed>>>  $sourceFiles
     * @param  array<string, array<string, bool>>  $sourceFileConflicts
     * @param  array<string, int>  $blockSizes
     * @param  array<string, bool>  $blockSizeConflicts
     * @return array{files: list<array<string, mixed>>, conflicts: list<array<string, mixed>>, slice_count_mismatches: list<array<string, mixed>>, unvalidated_file_ids: list<array{recovery_set_id: string, file_id: string}>, missing_recoverable_file_ids: list<array{recovery_set_id: string, file_id: string}>, coverage_complete: bool, preview_records_omitted: int, preview_variants_truncated: int}
     */
    private function mergeInputFileSliceChecksums(
        array $observations,
        array $mainPackets,
        array $mainPacketConflicts,
        array $sourceFiles,
        array $sourceFileConflicts,
        array $blockSizes,
        array $blockSizeConflicts,
        bool $observationsComplete,
        bool &$par2Complete,
        int &$previewRecordsRemaining,
    ): array {
        $files = [];
        $conflicts = [];
        $sliceCountMismatches = [];
        $unvalidatedFileIds = [];
        $missingRecoverableFileIds = [];
        $previewRecordsOmitted = 0;
        $previewVariantsTruncated = 0;

        foreach ($observations as $setId => $filesById) {
            foreach ($filesById as $fileId => $fileObservations) {
                $variants = [];
                $localConflict = false;
                foreach ($fileObservations as $observation) {
                    $sliceCount = $observation['slice_count'];
                    $digest = $observation['checksum_records_sha256'];
                    if (! is_int($sliceCount) || $sliceCount < 0
                        || ! is_string($digest) || preg_match('/\\A[a-f0-9]{64}\\z/i', $digest) !== 1) {
                        $localConflict = true;

                        continue;
                    }
                    $variantKey = $sliceCount.':'.strtolower($digest);
                    $variants[$variantKey] ??= [
                        'slice_count' => $sliceCount,
                        'checksum_records_sha256' => strtolower($digest),
                        'checksum_records_preview_hex' => $observation['checksum_records_preview_hex'],
                        'preview_slice_count' => $observation['preview_slice_count'],
                        'preview_truncated' => $observation['preview_truncated'],
                        'source_file_indices' => [],
                        'origins' => [],
                    ];
                    $variants[$variantKey]['source_file_indices'][] = $observation['origin']['file_index'];
                    $variants[$variantKey]['origins'][] = $observation['origin'];
                    $localConflict = $localConflict || $observation['duplicate_conflict'];
                    if ($variants[$variantKey]['checksum_records_preview_hex'] === null
                        && is_string($observation['checksum_records_preview_hex'])) {
                        $variants[$variantKey]['checksum_records_preview_hex'] = $observation['checksum_records_preview_hex'];
                        $variants[$variantKey]['preview_slice_count'] = $observation['preview_slice_count'];
                        $variants[$variantKey]['preview_truncated'] = $observation['preview_truncated'];
                    }
                }

                foreach ($variants as &$variant) {
                    $variant['source_file_indices'] = array_values(array_unique($variant['source_file_indices']));
                }
                unset($variant);

                $mainConflict = ($mainPacketConflicts[$setId] ?? false) === true;
                $sourceConflict = ($sourceFileConflicts[$setId][$fileId] ?? false) === true;
                $blockConflict = ($blockSizeConflicts[$setId] ?? false) === true;
                $main = $mainConflict ? null : ($mainPackets[$setId] ?? null);
                $source = $sourceConflict ? null : ($sourceFiles[$setId][$fileId] ?? null);
                $blockSize = ($blockConflict || $mainConflict) ? null : ($blockSizes[$setId] ?? null);
                $recoverableIds = is_array($main) ? ($main['recoverable_file_ids'] ?? []) : [];
                $nonRecoverableIds = is_array($main) ? ($main['non_recoverable_file_ids'] ?? []) : [];
                $allMainIds = is_array($main) ? ($main['all_file_ids'] ?? [...$recoverableIds, ...$nonRecoverableIds]) : [];
                $mainMembership = $mainConflict ? 'definition_conflict' : (! is_array($main)
                    ? 'unknown'
                    : (in_array($fileId, $recoverableIds, true) ? 'recoverable'
                        : (in_array($fileId, $nonRecoverableIds, true) ? 'non_recoverable'
                            : (in_array($fileId, $allMainIds, true) ? 'non_recoverable' : 'unlisted'))));
                $fileLength = is_array($source) && is_int($source['file_length'] ?? null)
                    ? $source['file_length'] : null;
                $shortFileHashesConsistent = is_array($source)
                    ? ($source['short_file_hashes_consistent'] ?? null) : null;
                $validBlockSize = is_int($blockSize) && $blockSize > 0 ? $blockSize : null;
                $expectedSliceCount = $fileLength !== null && $validBlockSize !== null
                    ? intdiv($fileLength, $validBlockSize) + (($fileLength % $validBlockSize) === 0 ? 0 : 1)
                    : null;
                $sliceCountMismatch = false;
                foreach ($variants as &$variant) {
                    $variant['slice_count_matches_file_length'] = $expectedSliceCount === null
                        ? null : $variant['slice_count'] === $expectedSliceCount;
                    $variant['slice_preview'] = $this->mapIfscPreviewToSource(
                        $variant['checksum_records_preview_hex'],
                        $fileLength,
                        $validBlockSize,
                        min(self::MAX_IFSC_STRUCTURED_PREVIEW_SLICES, $previewRecordsRemaining),
                    );
                    $previewedSliceCount = count($variant['slice_preview']);
                    $previewRecordsRemaining -= $previewedSliceCount;
                    $previewRecordsOmitted += max(0, $variant['slice_count'] - $previewedSliceCount);
                    $variant['slice_preview_truncated'] = $previewedSliceCount < $variant['slice_count']
                        || $variant['preview_truncated'];
                    if ($variant['slice_preview_truncated']) {
                        $previewVariantsTruncated++;
                    }
                    unset($variant['checksum_records_preview_hex']);
                    if ($variant['slice_count_matches_file_length'] === false) {
                        $sliceCountMismatch = true;
                    }
                }
                unset($variant);

                $duplicateConflict = $localConflict || count($variants) > 1;
                $definitionConflict = $mainConflict || $sourceConflict || $blockConflict
                    || $shortFileHashesConsistent === false;
                $invalid = $duplicateConflict || $mainMembership === 'unlisted' || $sliceCountMismatch;
                $validationStatus = $invalid ? 'invalid'
                    : ($mainMembership !== 'unknown' && $source !== null && $validBlockSize !== null
                        && count($variants) === 1 && $expectedSliceCount !== null
                        && ($variants[array_key_first($variants)]['slice_count_matches_file_length'] ?? null) === true
                        && ! $definitionConflict ? 'validated' : 'unvalidated');
                if ($invalid) {
                    $par2Complete = false;
                }

                $variants = array_values($variants);
                $sourceFileIndices = $variants === [] ? [] : array_values(array_unique(array_merge(...array_map(
                    static fn (array $variant): array => $variant['source_file_indices'],
                    $variants,
                ))));
                $entry = [
                    'recovery_set_id' => $setId,
                    'file_id' => $fileId,
                    'source_file_indices' => $sourceFileIndices,
                    'variants' => $variants,
                    'duplicate_conflict' => $duplicateConflict,
                    'definition_conflict' => $definitionConflict,
                    'source_file_present' => $sourceConflict ? null : is_array($source),
                    'source_file_short_hashes_consistent' => $shortFileHashesConsistent,
                    'source_file_length' => $fileLength,
                    'main_membership' => $mainMembership,
                    'source_block_size' => $validBlockSize,
                    'expected_slice_count_from_file_length' => $expectedSliceCount,
                    'slice_count_matches_file_length' => count($variants) === 1
                        ? ($variants[0]['slice_count_matches_file_length'] ?? null) : null,
                    'validation_status' => $validationStatus,
                ];
                $files[] = $entry;
                if ($duplicateConflict) {
                    $conflicts[] = [
                        'recovery_set_id' => $setId,
                        'file_id' => $fileId,
                        'variant_count' => count($variants),
                        'source_file_indices' => $sourceFileIndices,
                    ];
                }
                if ($sliceCountMismatch) {
                    $sliceCountMismatches[] = [
                        'recovery_set_id' => $setId,
                        'file_id' => $fileId,
                        'expected_slice_count' => $expectedSliceCount,
                        'observed_slice_counts' => array_values(array_unique(array_column($variants, 'slice_count'))),
                    ];
                }
                if ($validationStatus === 'unvalidated') {
                    $unvalidatedFileIds[] = ['recovery_set_id' => $setId, 'file_id' => $fileId];
                }
            }
        }

        if ($observationsComplete) {
            foreach ($mainPackets as $setId => $main) {
                if (($mainPacketConflicts[$setId] ?? false) === true) {
                    continue;
                }
                foreach ($main['recoverable_file_ids'] ?? [] as $fileId) {
                    if (is_string($fileId) && ! isset($observations[$setId][$fileId])) {
                        $missingRecoverableFileIds[] = ['recovery_set_id' => $setId, 'file_id' => $fileId];
                    }
                }
            }
        }

        return [
            'files' => $files,
            'conflicts' => $conflicts,
            'slice_count_mismatches' => $sliceCountMismatches,
            'unvalidated_file_ids' => $unvalidatedFileIds,
            'missing_recoverable_file_ids' => $missingRecoverableFileIds,
            'coverage_complete' => $observationsComplete && $missingRecoverableFileIds === [],
            'preview_records_omitted' => $previewRecordsOmitted,
            'preview_variants_truncated' => $previewVariantsTruncated,
        ];
    }

    /** @return list<array<string, int|string|bool|null>> */
    private function mapIfscPreviewToSource(?string $previewHex, ?int $fileLength, ?int $blockSize, int $maximumSlices): array
    {
        if ($maximumSlices < 1 || ! is_string($previewHex) || $previewHex === '' || strlen($previewHex) > self::MAX_IFSC_PREVIEW_HEX_BYTES
            || strlen($previewHex) % 40 !== 0 || preg_match('/\\A[a-f0-9]*\\z/i', $previewHex) !== 1) {
            return [];
        }
        $slices = [];
        $expectedSliceCount = $fileLength !== null && $blockSize !== null && $blockSize > 0
            ? intdiv($fileLength, $blockSize) + (($fileLength % $blockSize) === 0 ? 0 : 1)
            : null;
        foreach (array_slice(str_split($previewHex, 40), 0, $maximumSlices, true) as $sliceIndex => $recordHex) {
            $sourceStart = null;
            $sourceEndExclusive = null;
            $sourceBytesInSlice = null;
            $zeroPaddingBytes = null;
            $sourceRangeValid = null;
            if ($fileLength !== null && $blockSize !== null && $blockSize > 0
                && $sliceIndex <= intdiv(PHP_INT_MAX, $blockSize)) {
                $candidateStart = $sliceIndex * $blockSize;
                if ($candidateStart < $fileLength && $sliceIndex < $expectedSliceCount) {
                    $sourceStart = $candidateStart;
                    $sourceBytesInSlice = min($blockSize, $fileLength - $candidateStart);
                    $sourceEndExclusive = $candidateStart + $sourceBytesInSlice;
                    $zeroPaddingBytes = $blockSize - $sourceBytesInSlice;
                    $sourceRangeValid = true;
                } else {
                    $sourceRangeValid = false;
                }
            }
            $crcBytes = hex2bin(substr($recordHex, 32, 8));
            $crc32 = is_string($crcBytes) ? unpack('Vcrc32', $crcBytes)['crc32'] : null;
            $slices[] = [
                'slice_index' => $sliceIndex,
                'md5' => substr($recordHex, 0, 32),
                'crc32' => $crc32,
                'crc32_little_endian_hex' => substr($recordHex, 32, 8),
                'source_byte_start' => $sourceStart,
                'source_byte_end_exclusive' => $sourceEndExclusive,
                'source_bytes_in_slice' => $sourceBytesInSlice,
                'zero_padding_bytes' => $zeroPaddingBytes,
                'source_range_valid' => $sourceRangeValid,
            ];
        }

        return $slices;
    }

    /** @param list<array<string, mixed>> $observations
     * @return array<string, mixed>|null
     */
    private function primaryObservation(int $fileIndex, array $observations, ?string $messageId = null): ?array
    {
        $fallback = null;
        foreach ($observations as $observation) {
            if ($observation['file_index'] !== $fileIndex || $observation['server_role'] !== 'primary') {
                continue;
            }
            if ($messageId !== null) {
                if ($observation['message_id'] === $messageId) {
                    return $observation;
                }

                continue;
            }
            $fallback ??= $observation;
            if (is_array($observation['metadata']['yenc'] ?? null)) {
                return $observation;
            }
        }

        return $fallback;
    }

    /** @return list<array{name: string, alternate: bool, port: string, tls: bool, provider_label: string, provider_label_basis: string}> */
    private function configuredServers(): array
    {
        $primaryHost = (string) config('nntmux_nntp.server', 'primary');
        [$primaryProvider, $primaryLabelBasis] = self::providerLabel($primaryHost);
        $servers = [[
            'name' => $primaryHost,
            'alternate' => false,
            'port' => (string) config('nntmux_nntp.port', ''),
            'tls' => (bool) config('nntmux_nntp.ssl', false),
            'provider_label' => $primaryProvider,
            'provider_label_basis' => $primaryLabelBasis,
        ]];
        if (trim((string) config('nntmux_nntp.alternate_server', '')) !== '') {
            $alternateHost = (string) config('nntmux_nntp.alternate_server');
            [$alternateProvider, $alternateLabelBasis] = self::providerLabel($alternateHost);
            $servers[] = [
                'name' => $alternateHost,
                'alternate' => true,
                'port' => (string) config('nntmux_nntp.alternate_server_port', ''),
                'tls' => (bool) config('nntmux_nntp.alternate_server_ssl', false),
                'provider_label' => $alternateProvider,
                'provider_label_basis' => $alternateLabelBasis,
            ];
        }

        return $servers;
    }

    /** @return array{string, string} */
    private static function providerLabel(string $hostname): array
    {
        $host = strtolower($hostname);
        foreach ([
            'eweka' => 'Eweka',
            'newshosting' => 'Newshosting',
            'newsgroupdirect' => 'NewsgroupDirect',
            'frugalusenet' => 'Frugal Usenet',
            'easynews' => 'Easynews',
            'astraweb' => 'Astraweb',
            'usenetserver' => 'UsenetServer',
            'hitnews' => 'Hitnews',
        ] as $needle => $label) {
            if (str_contains($host, $needle)) {
                return [$label, 'inferred from configured hostname'];
            }
        }

        return [$hostname, 'configured hostname'];
    }

    /** @param list<array<string, mixed>> $files
     * @param  list<array<string, mixed>>  $observations
     * @param  list<array{name: string, alternate: bool, port: string, tls: bool, provider_label: string, provider_label_basis: string}>  $servers
     * @return array<string, mixed>
     */
    private function estimatePostingTimeline(array $files, array $observations, array $servers, int $expectedSamples): array
    {
        $dates = [];
        $bytesByMinute = [];
        foreach ($files as $file) {
            $timestamp = is_int($file['date'] ?? null) ? $file['date'] : null;
            if ($timestamp === null || $timestamp < 1) {
                continue;
            }
            $dates[] = $timestamp;
            $minute = intdiv($timestamp, 60) * 60;
            $previousBytes = array_key_exists($minute, $bytesByMinute) ? $bytesByMinute[$minute] : 0;
            $fileBytes = is_int($file['advertised_bytes'] ?? null) ? $file['advertised_bytes'] : null;
            $bytesByMinute[$minute] = $this->checkedNonNegativeIntegerAdd($previousBytes, $fileBytes);
        }
        if ($dates === []) {
            $nzbTimestampSpan = [
                'available' => false,
                'reason' => 'NZB file-level timestamps are absent.',
                'measured_uploader_bandwidth' => false,
            ];
        } else {
            sort($dates);
            ksort($bytesByMinute);
            $start = $dates[0];
            $end = $dates[count($dates) - 1];
            $duration = max(0, $end - $start);
            $datedFiles = array_values(array_filter($files, static fn (array $file): bool => is_int($file['date'] ?? null) && $file['date'] > 0));
            $advertisedBytes = 0;
            foreach ($datedFiles as $file) {
                $fileBytes = is_int($file['advertised_bytes'] ?? null) ? $file['advertised_bytes'] : null;
                $advertisedBytes = $this->checkedNonNegativeIntegerAdd($advertisedBytes, $fileBytes);
            }
            $minuteRates = $advertisedBytes === null ? [] : array_map(static fn (int $bytes): float => round($bytes / 60, 3), array_values($bytesByMinute));
            $nzbTimestampSpan = [
                'available' => true,
                'source' => 'NZB file-level date attributes and advertised segment bytes',
                'start_utc' => gmdate(DATE_ATOM, $start),
                'end_utc' => gmdate(DATE_ATOM, $end),
                'timestamp_span_seconds' => $duration,
                'advertised_bytes' => $advertisedBytes,
                'advertised_byte_rates_available' => $advertisedBytes !== null,
                'dated_file_count' => count($datedFiles),
                'undated_file_count_excluded' => count($files) - count($datedFiles),
                'whole_span_average_advertised_bytes_per_second' => $duration > 0 && $advertisedBytes !== null ? round($advertisedBytes / $duration, 3) : null,
                'observed_nonempty_minute_bucket_rates_bytes_per_second' => [
                    'minimum_nonempty_bucket' => $minuteRates === [] ? null : min($minuteRates),
                    'maximum_nonempty_bucket' => $minuteRates === [] ? null : max($minuteRates),
                    'bucket_count' => count($bytesByMinute),
                    'bucket_seconds' => 60,
                    'empty_buckets_included' => false,
                    'basis' => 'NZB advertised bytes grouped by file-level timestamps; bucket rates are not measured transfer rates.',
                ],
                'measured_uploader_bandwidth' => false,
                'interpretation' => 'Coarse NZB timestamp-based posting-volume estimates, not measured network transfer rates. File-level timestamps can be overridden or normalized; each timestamp may describe one segment rather than completion of every segment. Empty minutes are omitted.',
            ];
        }
        $articleSpans = [];
        $injectionSpans = [];
        $legacyPostingDateSpans = [];
        $headDateIdentityCoverage = [];
        $deltas = [];
        foreach ($servers as $server) {
            $serverObservations = array_values(array_filter($observations, static fn (array $observation): bool => $observation['server'] === $server['name'] && $observation['server_role'] === ($server['alternate'] ? 'alternate' : 'primary')));
            $articleTimes = [];
            $injectionTimes = [];
            $legacyPostingDateTimes = [];
            $identityStatusCounts = [];
            $admittedCount = 0;
            foreach ($serverObservations as $observation) {
                $identityStatus = self::headDateIdentityStatus($observation);
                $identityStatusCounts[$identityStatus] = ($identityStatusCounts[$identityStatus] ?? 0) + 1;
                if ($identityStatus !== 'identity_verified') {
                    continue;
                }
                $admittedCount++;
                $dates = $observation['metadata']['article_dates'] ?? [];
                $date = $dates['date']['parse_status'] ?? null;
                $articleTimestamp = $date === 'valid' && is_int($dates['date']['utc_epoch_seconds'] ?? null)
                    ? $dates['date']['utc_epoch_seconds'] : null;
                $injectionDate = $dates['injection_date']['parse_status'] ?? null;
                $injectionTimestamp = $injectionDate === 'valid' && is_int($dates['injection_date']['utc_epoch_seconds'] ?? null)
                    ? $dates['injection_date']['utc_epoch_seconds'] : null;
                $legacyDate = $dates['legacy_nntp_posting_date']['parse_status'] ?? null;
                $legacyTimestamp = $legacyDate === 'valid' && is_int($dates['legacy_nntp_posting_date']['utc_epoch_seconds'] ?? null)
                    ? $dates['legacy_nntp_posting_date']['utc_epoch_seconds'] : null;
                if ($articleTimestamp !== null) {
                    $articleTimes[$observation['message_id']] = $articleTimestamp;
                }
                if ($injectionTimestamp !== null) {
                    $injectionTimes[$observation['message_id']] = $injectionTimestamp;
                }
                if ($legacyTimestamp !== null) {
                    $legacyPostingDateTimes[$observation['message_id']] = $legacyTimestamp;
                }
                if ($articleTimestamp !== null && $injectionTimestamp !== null) {
                    $deltas[] = ['server' => $server['name'], 'message_id' => $observation['message_id'], 'seconds' => $injectionTimestamp - $articleTimestamp];
                }
            }
            $serverKey = ($server['alternate'] ? 'alternate ' : 'primary ').$server['name'];
            $headDateIdentityCoverage[$serverKey] = [
                'admitted_count' => $admittedCount,
                'excluded_count' => count($serverObservations) - $admittedCount,
                'status_counts' => $identityStatusCounts,
                'count_basis' => 'sampled HEAD observations, including repeated requested article IDs',
                'admission_basis' => 'supported Rust identity evidence: exact requested ID, successful HEAD response, and one valid matching Message-ID field; BODY and OVER identity do not gate HEAD dates',
                'excluded_raw_dates_retained' => true,
            ];
            $uniqueServerSamples = count(array_unique(array_column($serverObservations, 'message_id')));
            $articleSpans[$serverKey] = self::timestampSummary($articleTimes, $uniqueServerSamples, $expectedSamples);
            $injectionSpans[$serverKey] = self::timestampSummary($injectionTimes, $uniqueServerSamples, $expectedSamples);
            $legacyPostingDateSpans[$serverKey] = self::timestampSummary($legacyPostingDateTimes, $uniqueServerSamples, $expectedSamples);
        }
        $deltaValues = array_column($deltas, 'seconds');
        sort($deltaValues);
        $observationCounts = [];
        foreach ($servers as $server) {
            $serverKey = ($server['alternate'] ? 'alternate ' : 'primary ').$server['name'];
            $observationCounts[$serverKey] = count(array_filter($observations, static fn (array $observation): bool => $observation['server'] === $server['name'] && $observation['server_role'] === ($server['alternate'] ? 'alternate' : 'primary')));
        }

        return [
            'nzb_file_timestamp_span' => $nzbTimestampSpan,
            'observed_article_date_span_by_server' => $articleSpans,
            'observed_injection_date_span_by_server' => $injectionSpans,
            'observed_legacy_nntp_posting_date_span_by_server' => $legacyPostingDateSpans,
            'head_date_identity_coverage_by_server' => $headDateIdentityCoverage,
            'composition_to_injection_delta_seconds' => [
                'min' => $deltaValues === [] ? null : min($deltaValues),
                'median' => self::percentile($deltaValues, 0.5),
                'p95' => self::percentile($deltaValues, 0.95),
                'max' => $deltaValues === [] ? null : max($deltaValues),
                'negative_count' => count(array_filter($deltaValues, static fn (int $value): bool => $value < 0)),
                'paired_samples' => count($deltaValues),
                'source' => 'NNTP Date and Injection-Date headers; these timestamps may use different clocks.',
            ],
            'posting_rate_estimates' => [
                'available' => false,
                'unavailable_reason' => 'Collected article timestamps cover sampled articles only; this cannot establish release-wide peak, minimum, or actual uploader bandwidth.',
                'actual_uploader_bandwidth_measured' => false,
            ],
            'timestamp_coverage' => [
                'nzb_segment_reference_count' => array_sum(array_map(static fn (array $file): int => count($file['segments']), $files)),
                'expected_unique_article_ids_in_nzb' => count(array_unique(array_merge(...array_map(
                    static fn (array $file): array => array_column($file['segments'], 'messageid'),
                    $files,
                )))),
                'queried_sampled_article_ids' => count(array_unique(array_column($observations, 'message_id'))),
                'expected_samples_per_server' => $expectedSamples,
                'actual_observations_per_server' => $observationCounts,
                'coverage_complete' => false,
            ],
            'interpretation' => 'Date is the article composition time; Injection-Date is an observed injection timestamp. Both can be overridden or normalized, and sampled spans do not equal the full upload duration. Actual uploader bandwidth requires uploader-side telemetry.',
        ];
    }

    /** @param array<string, mixed> $observation */
    private static function headDateIdentityStatus(array $observation): string
    {
        $identity = $observation['metadata']['article_identity'] ?? null;
        if (! is_array($identity) || ($identity['parser_engine'] ?? null) !== 'nntmux-rust-article-identity'
            || ($identity['schema_version'] ?? null) !== 3) {
            return 'identity_parser_unavailable';
        }
        $requestedId = $observation['message_id'] ?? null;
        if (! is_string($requestedId) || ($identity['request']['syntax_status'] ?? null) !== 'valid'
            || ($identity['request']['token'] ?? null) !== '<'.$requestedId.'>') {
            return 'invalid_request_identity';
        }
        if (($identity['head_response']['availability'] ?? null) !== 'available'
            || ($identity['head_response']['parse_status'] ?? null) !== 'valid'
            || ($identity['head_response']['response_code'] ?? null) !== 221
            || ($identity['head_message_id']['availability'] ?? null) !== 'available'
            || ($identity['head_message_id']['parse_status'] ?? null) !== 'valid'
            || ($identity['head_message_id']['occurrence_count'] ?? null) !== 1) {
            return 'identity_unconfirmed';
        }
        $matches = [
            $identity['head_message_id']['matches_request'] ?? null,
            $identity['relations']['head_response_matches_request'] ?? null,
            $identity['relations']['head_response_matches_head_field'] ?? null,
        ];
        if (in_array(false, $matches, true)) {
            return 'identity_mismatch';
        }

        return count(array_filter($matches, static fn (mixed $match): bool => $match === true)) === 3
            ? 'identity_verified' : 'identity_unconfirmed';
    }

    /** @param array<string, int> $timestamps
     * @return array<string, mixed>
     */
    private static function timestampSummary(array $timestamps, int $observations, int $expected): array
    {
        if ($timestamps === []) {
            return ['available' => false, 'valid_timestamp_count' => 0, 'observations' => $observations, 'expected_samples' => $expected];
        }
        $values = array_values($timestamps);
        sort($values);

        return [
            'available' => true,
            'first_utc' => gmdate(DATE_ATOM, $values[0]),
            'last_utc' => gmdate(DATE_ATOM, $values[count($values) - 1]),
            'seconds' => max(0, $values[count($values) - 1] - $values[0]),
            'valid_timestamp_count' => count($values),
            'observations' => $observations,
            'expected_samples' => $expected,
            'complete' => $observations >= $expected && count($values) >= $expected,
        ];
    }

    /** @param list<int> $values */
    private static function percentile(array $values, float $percentile): ?int
    {
        if ($values === []) {
            return null;
        }
        $index = (int) floor((count($values) - 1) * $percentile);

        return $values[$index];
    }

    /** @param list<array<string, mixed>> $observations
     * @return list<array<string, mixed>>
     */
    private function compareProviders(array $observations): array
    {
        $byId = [];
        foreach ($observations as $observation) {
            $serverKey = $observation['server_role'].' '.$observation['server'].':'.$observation['server_port'];
            $byId[$observation['message_id']][$serverKey] = $observation;
        }
        $comparisons = [];
        foreach ($byId as $messageId => $perServer) {
            if (count($perServer) < 2) {
                continue;
            }
            $bodyHashes = [];
            $headers = [];
            $headerOccurrenceOrders = [];
            $decodedSegmentHashes = [];
            $decodedSegmentRanges = [];
            $decodedSegmentIntegrity = [];
            $allHeaderNames = [];
            foreach ($perServer as $server => $observation) {
                $bodyHashes[$server] = $observation['metadata']['article_body_sha256'] ?? null;
                $headers[$server] = $observation['metadata']['headers'] ?? [];
                $orderedNames = $observation['metadata']['header_profile']['ordered_names'] ?? null;
                $headerOccurrenceOrders[$server] = is_array($orderedNames) ? array_values($orderedNames) : null;
                $yenc = $observation['metadata']['yenc'] ?? [];
                $decodedSegmentHashes[$server] = is_string($yenc['decoded_segment_sha256'] ?? null)
                    ? strtolower($yenc['decoded_segment_sha256']) : null;
                $multipart = ($yenc['multipart'] ?? false) === true;
                $fileSize = is_int($yenc['file_size'] ?? null) ? $yenc['file_size'] : null;
                $decodedSegmentRanges[$server] = [
                    'begin' => $multipart ? (is_int($yenc['begin'] ?? null) ? $yenc['begin'] : null) : ($fileSize === null ? null : 1),
                    'end' => $multipart ? (is_int($yenc['end'] ?? null) ? $yenc['end'] : null) : $fileSize,
                    'file_size' => $fileSize,
                ];
                $decodedSegmentIntegrity[$server] = Par2MetadataParser::bodyResponseIdentityVerified($observation['metadata'])
                    && ($yenc['payload_escape_state_valid'] ?? null) === true
                    && ($yenc['header_numeric_fields_valid'] ?? null) === true
                    && ($yenc['trailer_numeric_fields_valid'] ?? null) === true
                    && ($yenc['decoded_size_matches_yenc'] ?? null) === true
                    && ($yenc['declared_crc32_matches'] ?? null) === true
                    && ($multipart
                        ? ($yenc['ypart_range_valid'] ?? null) === true
                            && ($yenc['decoded_size_matches_ypart_range'] ?? null) === true
                            && ($yenc['trailer_part_matches_header'] ?? null) === true
                        : ($yenc['decoded_size_matches_header_size'] ?? null) === true);
                $allHeaderNames = array_unique(array_merge($allHeaderNames, array_keys($headers[$server])));
            }
            $headerDifferences = [];
            foreach ($allHeaderNames as $headerName) {
                $values = [];
                foreach ($headers as $server => $serverHeaders) {
                    $values[$server] = $serverHeaders[$headerName] ?? null;
                }
                // Compare raw bytes before JSON persistence replaces invalid UTF-8.
                if (count(array_unique(array_map(static fn (mixed $value): string => serialize($value), $values))) > 1) {
                    $headerDifferences[$headerName] = $values;
                }
            }
            $decodedComparisonAvailable = ! in_array(null, $decodedSegmentHashes, true)
                && ! in_array(false, $decodedSegmentIntegrity, true);
            $decodedRangeValues = array_values($decodedSegmentRanges);
            $decodedRangeMatch = $decodedComparisonAvailable
                ? count(array_unique(array_map(static fn (array $range): string => serialize($range), $decodedRangeValues))) === 1
                    && ! in_array(null, array_merge(...array_map('array_values', $decodedRangeValues)), true)
                : null;
            $decodedSegmentMatch = $decodedComparisonAvailable
                ? count(array_unique($decodedSegmentHashes)) === 1
                : null;
            $decodedSegmentRangeContentMatch = $decodedSegmentMatch === null || $decodedRangeMatch === null
                ? null : ($decodedSegmentMatch && $decodedRangeMatch);
            $comparisons[] = [
                'message_id' => $messageId,
                'body_hashes_by_server' => $bodyHashes,
                'body_matches' => count(array_unique($bodyHashes)) === 1 && ! in_array(null, $bodyHashes, true),
                'decoded_segment_sha256_by_server' => $decodedSegmentHashes,
                'decoded_segment_integrity_valid_by_server' => $decodedSegmentIntegrity,
                'decoded_segment_ranges_by_server' => $decodedSegmentRanges,
                'decoded_segment_bytes_match' => $decodedSegmentMatch,
                'decoded_segment_range_match' => $decodedRangeMatch,
                'decoded_segment_content_for_same_range_match' => $decodedSegmentRangeContentMatch,
                'decoded_segment_comparison_basis' => 'Exact SHA-256 of yEnc-decoded segment bytes; equality claims require valid framing, declared size, and CRC32 on every compared provider. Range equality compares inclusive yEnc offsets (single-part files use 1..file_size) and full file size.',
                'headers_by_server' => $headers,
                'header_differences' => $headerDifferences,
                'header_values_match' => $headerDifferences === [],
                'header_order_match' => ! in_array(null, $headerOccurrenceOrders, true)
                    && count(array_unique(array_map(static fn (array $value): string => serialize($value), $headerOccurrenceOrders))) === 1,
                'normalized_header_order_match' => count(array_unique(array_map(static function (array $value): string {
                    return hash('sha256', serialize(array_keys($value)));
                }, $headers))) === 1,
                'header_order_comparison_basis' => 'Original field-name occurrence sequence with duplicates and capitalization preserved; normalized_header_order_match separately compares case-folded unique map keys.',
                'header_comparison_basis' => 'legacy collapsed PHP projection; duplicate occurrences are comma-joined',
                'ordered_occurrence_comparisons' => $this->compareProviderHeaderOccurrences($perServer),
            ];
        }

        return $comparisons;
    }

    /** @param array<string, array<string, mixed>> $perServer
     * @return list<array<string, mixed>>
     */
    private function compareProviderHeaderOccurrences(array $perServer): array
    {
        $providers = array_values($perServer);
        $comparisons = [];
        $parser = new NativeMetadataParser;
        for ($leftIndex = 0; $leftIndex < count($providers); $leftIndex++) {
            for ($rightIndex = $leftIndex + 1; $rightIndex < count($providers); $rightIndex++) {
                $left = $providers[$leftIndex];
                $right = $providers[$rightIndex];
                $leftHeaders = $left['metadata']['raw_headers'] ?? null;
                $rightHeaders = $right['metadata']['raw_headers'] ?? null;
                if (! is_array($leftHeaders) || ! is_array($rightHeaders)) {
                    $comparison = null;
                } else {
                    $leftIdentity = self::providerArticleIdentityMatch($left['metadata']['article_identity'] ?? null);
                    $rightIdentity = self::providerArticleIdentityMatch($right['metadata']['article_identity'] ?? null);
                    $identityMatch = $leftIdentity === false || $rightIdentity === false
                        ? false : ($leftIdentity === true && $rightIdentity === true ? true : null);
                    $comparison = $parser->compareNntpHeaders(
                        implode('', array_map('strval', $leftHeaders)),
                        implode('', array_map('strval', $rightHeaders)),
                        $identityMatch,
                    );
                }
                $comparisons[] = [
                    'left_server' => $left['server_role'].' '.$left['server'].':'.$left['server_port'],
                    'right_server' => $right['server_role'].' '.$right['server'].':'.$right['server_port'],
                    'comparison_status' => $comparison === null ? 'native_comparison_unavailable' : 'available',
                    'comparison' => $comparison,
                ];
            }
        }

        return $comparisons;
    }

    /** @param array<string, mixed>|null $identity */
    private static function providerArticleIdentityMatch(?array $identity): ?bool
    {
        $relations = $identity['relations'] ?? null;
        if (! is_array($relations)) {
            return null;
        }
        $matches = [
            $relations['head_response_matches_request'] ?? null,
            $relations['body_response_matches_request'] ?? null,
            $relations['head_response_matches_body_response'] ?? null,
        ];
        if (in_array(false, $matches, true)) {
            return false;
        }

        return count(array_filter($matches, static fn (mixed $match): bool => $match === true)) === count($matches)
            ? true : null;
    }

    /** @param array<string, mixed> $article */
    private function overviewResponseRowStatus(array $article): string
    {
        if (($article['overview_attempted'] ?? false) !== true) {
            return 'not_attempted';
        }
        $rowCount = (int) ($article['overview_row_count'] ?? 0);
        if (($article['overview_response_code'] ?? null) !== 224) {
            if ($rowCount > 0) {
                return 'rows_without_success_response';
            }

            return ($article['overview_response_code'] ?? null) === null ? 'attempt_failed' : 'no_row';
        }
        if ($rowCount === 0) {
            return 'success_without_row';
        }

        return $rowCount === 1 ? 'single_row' : 'unexpected_multiple_rows';
    }

    /** @param list<string> $format
     * @return array<string, string>
     */
    private function parseOverview(?string $row, array $format): array
    {
        if ($row === null || $format === []) {
            return [];
        }
        $values = explode("\t", $row);
        $parsed = ['article_number' => array_shift($values) ?? ''];
        foreach ($format as $index => $field) {
            if (! array_key_exists($index, $values)) {
                break;
            }
            $field = trim($field);
            if (preg_match('/\A:[a-z0-9-]+\z/i', $field) === 1) {
                $key = strtolower($field);
            } elseif (preg_match('/\A(?:Bytes|Lines):\z/i', $field) === 1) {
                $key = ':'.strtolower(rtrim($field, ':'));
            } elseif (preg_match('/\A([a-z0-9-]+):full\z/i', $field, $matches) === 1) {
                $key = strtolower($matches[1]);
            } else {
                $key = strtolower(rtrim($field, ':'));
            }
            if ($key !== '') {
                $parsed[$key] = $values[$index];
            }
        }

        return $parsed;
    }

    /** @param list<array<string, mixed>> $sourceFiles */
    private function sourceFileLengthTotal(array $sourceFiles): ?int
    {
        $total = 0;
        foreach ($sourceFiles as $sourceFile) {
            $total = $this->checkedNonNegativeIntegerAdd($total, $sourceFile['file_length'] ?? null);
            if ($total === null) {
                return null;
            }
        }

        return $total;
    }

    /**
     * @param  array<string, mixed>|null  $main
     * @param  array<string, array<string, mixed>>  $sources
     * @param  array<string, bool>  $conflicts
     * @return array{source_block_count: ?int, source_block_count_overflow: bool, source_block_count_within_current_v2_limit: ?bool, source_block_limit: int, complete: bool, recovery_percentage_basis: string}
     */
    private function computePar2SourceBlockLayout(?array $main, int $blockSize, array $sources, array $conflicts, bool $definitionConflict = false): array
    {
        $layout = [
            'source_block_count' => null,
            'source_block_count_overflow' => false,
            'source_block_count_within_current_v2_limit' => null,
            'source_block_limit' => Par2MetadataParser::MAX_SOURCE_BLOCKS,
            'complete' => false,
            'recovery_percentage_basis' => 'unavailable-source-block-denominator',
        ];
        if ($main === null || $definitionConflict || $blockSize <= 0
            || ! is_array($main['recoverable_file_ids'] ?? null)) {
            return $layout;
        }
        $count = 0;
        foreach ($main['recoverable_file_ids'] as $fileId) {
            if (! is_string($fileId) || ($conflicts[$fileId] ?? false) === true
                || ! is_int($sources[$fileId]['file_length'] ?? null)
                || $sources[$fileId]['file_length'] < 0) {
                return $layout;
            }
            $length = $sources[$fileId]['file_length'];
            $blocks = intdiv($length, $blockSize) + (($length % $blockSize) === 0 ? 0 : 1);
            $count = $this->checkedNonNegativeIntegerAdd($count, $blocks);
            if ($count === null) {
                $layout['source_block_count_overflow'] = true;
                $layout['recovery_percentage_basis'] = 'unavailable-source-block-count-overflow';

                return $layout;
            }
        }
        $withinLimit = $count <= Par2MetadataParser::MAX_SOURCE_BLOCKS;
        $layout['source_block_count'] = $count > 0 ? $count : null;
        $layout['source_block_count_within_current_v2_limit'] = $count > 0 ? $withinLimit : null;
        $layout['complete'] = $withinLimit;
        $layout['recovery_percentage_basis'] = ! $withinLimit
            ? 'unavailable-source-block-count-exceeds-current-v2-limit'
            : ($count > 0 ? 'unique-valid-recovery-exponents-over-recoverable-source-blocks'
                : 'unavailable-zero-source-block-count');

        return $layout;
    }

    private function par2RecoveryPercentageBasis(?string $denominatorBasis, ?bool $withinLimit, bool $packedLayout, ?float $recoveryPercent): string
    {
        if ($packedLayout) {
            return 'unavailable-packed-recovery-layout';
        }
        if ($denominatorBasis === 'unavailable-source-block-count-overflow') {
            return $denominatorBasis;
        }
        if ($withinLimit === false) {
            return 'unavailable-source-block-count-exceeds-current-v2-limit';
        }
        if ($withinLimit === true && $recoveryPercent !== null) {
            return 'unique-valid-recovery-exponents-over-recoverable-source-blocks';
        }
        if ($denominatorBasis !== null && str_starts_with($denominatorBasis, 'unavailable-')) {
            return $denominatorBasis;
        }

        return 'unavailable-incomplete-or-unsupported-par2-layout';
    }

    private function checkedNonNegativeIntegerAdd(?int $total, mixed $value): ?int
    {
        if ($total === null || $total < 0 || ! is_int($value) || $value < 0 || $total > PHP_INT_MAX - $value) {
            return null;
        }

        return $total + $value;
    }

    /** @param array<string, mixed> $article */
    private function overviewRowProtocolStatus(array $article): string
    {
        if (($article['overview_attempted'] ?? false) !== true) {
            return 'not_attempted';
        }
        if (($article['overview_response_code'] ?? null) === 503) {
            return 'unsupported';
        }
        if (($article['overview_response_code'] ?? null) !== 224) {
            return 'response_not_successful';
        }

        return match ((int) ($article['overview_row_count'] ?? 0)) {
            0 => 'empty_response',
            1 => 'single_row',
            default => 'multiple_rows',
        };
    }

    /** @param list<array<string, mixed>> $observations
     * @return list<array<string, mixed>>
     */
    private function softwareCandidates(array $observations): array
    {
        $candidates = [];
        foreach ($observations as $observation) {
            foreach ($observation['metadata']['uploader_software_candidates'] ?? [] as $name => $evidence) {
                $candidates[$name]['software'] = $name;
                $candidates[$name]['evidence'][] = [
                    'server' => $observation['server'],
                    'message_id' => $observation['message_id'],
                    'header' => $evidence['header'],
                    'value' => $evidence['value'],
                ];
            }
        }
        foreach ($candidates as &$candidate) {
            $candidate['confidence'] = count($candidate['evidence']) > 1 ? 'medium' : 'low';
        }
        unset($candidate);

        return array_values($candidates);
    }
}
