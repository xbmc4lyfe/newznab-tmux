<?php

declare(strict_types=1);

namespace App\Services\Releases\Fingerprint;

use App\Models\Release;
use App\Services\NNTP\NntpArticleMissingException;
use App\Services\NNTP\NntpProviderUnavailableException;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbService;
use Throwable;

/**
 * Bounded fingerprint collector: at most SAMPLE_LIMIT articles per release and a record of a few KB.
 *
 * This is the reference implementation of the compact-v1 contract shared with the Rust workers, so
 * the output must stay deterministic apart from the documented timing and timestamp fields.
 */
final class CompactFingerprintCollector
{
    /** Versioned independently of the full collector; records are told apart by FORMAT. */
    public const SCHEMA_VERSION = 1;

    public const FORMAT = 'compact-v1';

    public const SAMPLE_LIMIT = 12;

    public const MAX_ARTICLE_BODY_BYTES = 2_000_000;

    private const MAX_ARTICLE_HEADER_BYTES = 65_536;

    private const LARGEST_FILES_SAMPLED = 3;

    private const MAX_LISTED_FILES = 200;

    /** Maximum UTF-8 bytes kept from any name. */
    private const MAX_NAME_LENGTH = 120;

    private const MAX_LISTED_GROUPS = 20;

    /** Serialized record budget; file lists are shortened until the record fits. */
    public const MAX_RECORD_BYTES = 20_480;

    private readonly FingerprintProviderBreaker $breaker;

    public function __construct(private readonly NzbService $nzb, private readonly NzbFingerprintAnalyzer $analyzer,
        private readonly Par2MetadataParser $par2, ?FingerprintProviderBreaker $breaker = null)
    {
        $this->breaker = $breaker ?? new FingerprintProviderBreaker;
    }

    /** @return array{status: string, data: array<string, mixed>} */
    public function collect(Release $release): array
    {
        $xml = $this->nzb->readNzbContents((string) $release->guid);
        if (! is_string($xml) || $xml === '') {
            return ['status' => 'unavailable', 'data' => ['reason' => 'The release NZB is not available locally.']];
        }

        return $this->collectFromNzb($xml, (int) $release->id);
    }

    /** @return array{status: string, data: array<string, mixed>} */
    public function collectFromNzb(string $xml, int $releaseId): array
    {
        $manifest = $this->analyzer->analyzeManifest($xml);
        $files = $manifest['files'];
        $omitted = 0;
        $samples = self::selectSamples($files, $omitted);
        $servers = $this->servers($releaseId);
        $nntp = app(NNTPService::class);

        $providers = [];
        $unavailable = [];
        $errors = [];
        foreach ($servers as $server) {
            $account = $server['account'];
            $providers[$account] = [
                'account' => $account,
                'host' => $server['name'],
                'role' => $server['alternate'] ? 'alternate' : 'primary',
                'tls' => $server['tls'],
                'port' => $server['port'],
                'profile' => 'ok',
                'skipped_reason' => null,
                'implementation' => null,
                'articles' => ['requested' => 0, 'found' => 0, 'missing' => 0, 'errors' => 0],
                'retrieval_ms' => [],
            ];
            $skipReason = $server['alternate'] ? $this->breaker->skipReason($server) : null;
            if ($skipReason !== null) {
                $providers[$account]['profile'] = 'NntpProviderUnavailableException';
                $providers[$account]['skipped_reason'] = $skipReason;
                $unavailable[$account] = 'NntpProviderUnavailableException';

                continue;
            }
            try {
                $profile = $nntp->getFingerprintServerProfile($server['alternate']);
                $providers[$account]['implementation'] = self::bounded($profile['implementation'] ?? null);
                if ($server['alternate']) {
                    $this->breaker->recordReachable($server);
                }
                // A profile can fail without throwing: same acceptance rule as the full collector.
                if (! self::profileSucceeded($profile)) {
                    $providers[$account]['profile'] = 'ProfileResponseInvalid';
                    $errors[$account]['ProfileResponseInvalid'] = ($errors[$account]['ProfileResponseInvalid'] ?? 0) + 1;
                }
            } catch (Throwable $exception) {
                $providers[$account]['profile'] = class_basename($exception);
                $errors[$account][class_basename($exception)] = ($errors[$account][class_basename($exception)] ?? 0) + 1;
                if ($server['alternate'] && $exception instanceof NntpProviderUnavailableException) {
                    $unavailable[$account] = class_basename($exception);
                    $this->breaker->recordUnavailable($server);
                } elseif ($server['alternate']) {
                    $this->breaker->releaseProbe($server);
                }
            }
        }

        $manifestInvalidFiles = self::manifestInvalidFileCount($manifest);
        if ($manifestInvalidFiles > 0) {
            $errors['nzb']['NzbManifestInvalid'] = $manifestInvalidFiles;
        }
        $articles = [];
        $softwareCandidates = [];
        $firstPrefixMd5 = [];
        $par2 = null;
        $par2Fallback = null;
        foreach ($samples as $sample) {
            $article = [
                'file_index' => $sample['file_index'],
                'segment' => $sample['segment_number'],
                'role' => $sample['role'],
                'provider_results' => [],
                'yenc' => null,
                'date_utc' => null,
                'xref_group_count' => null,
            ];
            foreach ($servers as $server) {
                $account = $server['account'];
                $providers[$account]['articles']['requested']++;
                if (isset($unavailable[$account])) {
                    $providers[$account]['articles']['errors']++;
                    $errors[$account][$unavailable[$account]] = ($errors[$account][$unavailable[$account]] ?? 0) + 1;
                    $article['provider_results'][$account] = ['code' => null, 'bytes' => null, 'ms' => null];

                    continue;
                }
                $started = hrtime(true);
                try {
                    $fetched = $nntp->getFingerprintArticleByMessageID($sample['messageid'], self::MAX_ARTICLE_BODY_BYTES,
                        self::MAX_ARTICLE_HEADER_BYTES, $server['alternate'], false);
                    $ms = (int) round((hrtime(true) - $started) / 1_000_000);
                    $providers[$account]['retrieval_ms'][] = $ms;
                    $providers[$account]['articles']['found']++;
                    $article['provider_results'][$account] = [
                        'code' => self::responseCode($fetched['body_response'] ?? null) ?? 222,
                        'bytes' => strlen($fetched['body']),
                        'ms' => $ms,
                    ];
                    // Each provider's copy is decoded so corruption on any of them is reported.
                    $analysis = $this->analyzer->analyzeArticleAndDecodedBytes($fetched['headers'], $fetched['body']);
                    $metadata = $analysis['metadata'];
                    $yenc = self::yencSummary($metadata['yenc'] ?? null);
                    foreach (self::yencIntegrityErrors($metadata['yenc'] ?? null) as $integrityError) {
                        $errors[$account][$integrityError] = ($errors[$account][$integrityError] ?? 0) + 1;
                    }
                    // Every verified provider copy may supply PAR2 metadata until a usable summary is found.
                    if ($par2 === null && $sample['kind'] === 'par2' && self::yencIntegrityErrors($metadata['yenc'] ?? null) === []
                        && is_string($analysis['decoded_bytes'] ?? null)) {
                        $summary = $this->par2Summary($analysis['decoded_bytes'], $files);
                        // Only a summary with file descriptions ends the search; a set ID alone (e.g. a
                        // Creator-only volume) is kept as a fallback while later volumes are examined.
                        if ($summary !== null && $summary['files'] !== []) {
                            $par2 = $summary;
                        } elseif ($summary !== null && $summary['set_id'] !== null) {
                            $par2Fallback ??= $summary;
                        }
                    }
                    if ($article['yenc'] !== null) {
                        continue;
                    }
                    // The article summary comes from the first provider that returned it.
                    $article['yenc'] = $yenc;
                    $article['date_utc'] = self::dateUtc($metadata['headers']['date'] ?? null);
                    $article['xref_group_count'] = self::xrefGroupCount($metadata['headers']['xref'] ?? null);
                    foreach (array_keys($metadata['uploader_software_candidates'] ?? []) as $candidate) {
                        $softwareCandidates[$candidate] = true;
                    }
                    $prefixMd5 = $metadata['yenc']['par2_first_16k_md5_candidate'] ?? null;
                    if ($sample['segment_number'] === 1 && is_string($prefixMd5)) {
                        $firstPrefixMd5[$sample['file_index']] ??= strtolower($prefixMd5);
                    }
                } catch (Throwable $exception) {
                    $providers[$account]['retrieval_ms'][] = (int) round((hrtime(true) - $started) / 1_000_000);
                    $providers[$account]['articles'][self::isMissingArticle($exception) ? 'missing' : 'errors']++;
                    $article['provider_results'][$account] = ['code' => null, 'bytes' => null, 'ms' => null];
                    $type = class_basename($exception);
                    $errors[$account][$type] = ($errors[$account][$type] ?? 0) + 1;
                    if ($server['alternate'] && $exception instanceof NntpProviderUnavailableException) {
                        $unavailable[$account] = $type;
                        $this->breaker->recordUnavailable($server);
                    }
                }
            }
            $articles[] = $article;
        }

        $providerRows = [];
        foreach ($providers as $provider) {
            $timings = $provider['retrieval_ms'];
            sort($timings);
            $provider['retrieval_ms'] = [
                'p50' => $timings === [] ? null : $timings[intdiv(count($timings) - 1, 2)],
                'max' => $timings === [] ? null : max($timings),
                'total' => array_sum($timings),
            ];
            $providerRows[] = $provider;
        }
        $errorRows = [];
        foreach ($errors as $account => $types) {
            ksort($types);
            foreach ($types as $type => $count) {
                $errorRows[] = ['account' => $account, 'error_type' => $type, 'count' => $count];
            }
        }
        $softwareCandidates = array_keys($softwareCandidates);
        sort($softwareCandidates);

        $primary = $providerRows[0] ?? null;
        $status = $primary !== null && $samples !== []
            && $primary['articles']['found'] === count($samples)
            && $errorRows === [] ? 'complete' : 'partial';

        return ['status' => $status, 'data' => self::withinBudget([
            'schema_version' => self::SCHEMA_VERSION,
            'format' => self::FORMAT,
            'collector' => ['engine' => 'php', 'version' => self::SCHEMA_VERSION],
            'nzb' => self::nzbSummary($manifest),
            'samples' => ['limit' => self::SAMPLE_LIMIT, 'selected' => count($samples), 'omitted' => $omitted],
            'providers' => $providerRows,
            'articles' => $articles,
            'files' => self::fileRows($files, $firstPrefixMd5),
            'par2' => $par2 ?? $par2Fallback,
            'posting' => self::posting($files),
            'uploader_software_candidates' => $softwareCandidates,
            'errors' => $errorRows,
            // Also true when a list cap below omitted entries before the byte budget applied.
            'truncated' => count($files) > self::MAX_LISTED_FILES
                || (($par2 ?? $par2Fallback) !== null && ($par2 ?? $par2Fallback)['file_count'] > count(($par2 ?? $par2Fallback)['files']))
                || count(self::nzbGroups($files)) > self::MAX_LISTED_GROUPS,
        ])];
    }

    /**
     * Shrink the record in stages until it fits MAX_RECORD_BYTES as stored (default JSON escaping):
     * halve the file lists, then drop groups, then drop per-article names, then halve the article
     * list. The last stage always terminates, and everything left is bounded by count.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private static function withinBudget(array $record): array
    {
        // Timings vary between runs; measure them at a fixed worst-case width so the same release
        // always keeps the same content.
        $fits = static fn (array $candidate): bool => strlen((string) json_encode(self::withWorstCaseTimings($candidate), JSON_INVALID_UTF8_SUBSTITUTE)) <= self::MAX_RECORD_BYTES;
        while (! $fits($record)) {
            $record['truncated'] = true;
            $files = count($record['files']);
            $par2Files = is_array($record['par2']) ? count($record['par2']['files']) : 0;
            if ($files > 0 || $par2Files > 0) {
                if ($files >= $par2Files) {
                    $record['files'] = array_slice($record['files'], 0, intdiv($files, 2));
                } else {
                    $record['par2']['files'] = array_slice($record['par2']['files'], 0, intdiv($par2Files, 2));
                }
            } elseif ($record['nzb']['groups'] !== []) {
                $record['nzb']['groups'] = [];
            } elseif (array_filter($record['articles'], static fn (array $article): bool => ($article['yenc']['name'] ?? null) !== null) !== []) {
                foreach ($record['articles'] as &$article) {
                    if (is_array($article['yenc'])) {
                        $article['yenc']['name'] = null;
                    }
                }
                unset($article);
            } elseif ($record['articles'] !== []) {
                $record['articles'] = array_slice($record['articles'], 0, intdiv(count($record['articles']), 2));
            } else {
                break;
            }
        }

        return $record;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private static function withWorstCaseTimings(array $record): array
    {
        $widest = PHP_INT_MAX;
        foreach ($record['providers'] as &$provider) {
            $provider['retrieval_ms'] = ['p50' => $widest, 'max' => $widest, 'total' => $widest];
        }
        unset($provider);
        foreach ($record['articles'] as &$article) {
            foreach ($article['provider_results'] as &$result) {
                $result['ms'] = $widest;
            }
            unset($result);
        }
        unset($article);

        return $record;
    }

    /**
     * Segment 1 of the smallest PAR2 file, then first/middle/last segments of the largest non-PAR2
     * files, then first segments of the remaining files, up to SAMPLE_LIMIT.
     *
     * @param  list<array<string, mixed>>  $files
     *
     * @param-out int $omitted
     *
     * @return list<array{file_index: int, kind: string, segment_number: int, messageid: string, role: string}>
     */
    public static function selectSamples(array $files, ?int &$omitted = null): array
    {
        $candidates = [];
        $withSegments = array_values(array_filter($files, static fn (array $file): bool => is_array($file['segments'] ?? null) && $file['segments'] !== []));
        $par2Files = array_values(array_filter($withSegments, static fn (array $file): bool => ($file['kind'] ?? null) === 'par2'));
        $dataFiles = array_values(array_filter($withSegments, static fn (array $file): bool => ($file['kind'] ?? null) !== 'par2'));
        usort($par2Files, static fn (array $a, array $b): int => [self::fileBytes($a), $a['index']] <=> [self::fileBytes($b), $b['index']]);
        usort($dataFiles, static fn (array $a, array $b): int => [self::fileBytes($b), $a['index']] <=> [self::fileBytes($a), $b['index']]);

        if ($par2Files !== []) {
            $candidates[] = [$par2Files[0], 0, 'par2'];
        }
        foreach (array_slice($dataFiles, 0, self::LARGEST_FILES_SAMPLED) as $file) {
            $last = count($file['segments']) - 1;
            $candidates[] = [$file, 0, 'first'];
            if ($last > 1) {
                $candidates[] = [$file, intdiv($last, 2), 'middle'];
            }
            if ($last > 0) {
                $candidates[] = [$file, $last, 'last'];
            }
        }
        foreach (array_slice($dataFiles, self::LARGEST_FILES_SAMPLED) as $file) {
            $candidates[] = [$file, 0, 'first'];
        }
        foreach (array_slice($par2Files, 1) as $file) {
            $candidates[] = [$file, 0, 'first'];
        }

        $samples = [];
        $seen = [];
        $omitted = 0;
        foreach ($candidates as [$file, $position, $role]) {
            $segment = $file['segments'][$position];
            $messageId = (string) ($segment['messageid'] ?? '');
            if ($messageId === '' || isset($seen[$messageId])) {
                continue;
            }
            $seen[$messageId] = true;
            if (count($samples) >= self::SAMPLE_LIMIT) {
                $omitted++;

                continue;
            }
            $samples[] = [
                'file_index' => (int) $file['index'],
                'kind' => (string) $file['kind'],
                'segment_number' => (int) $segment['number'],
                'messageid' => $messageId,
                'role' => $role,
            ];
        }

        return $samples;
    }

    /**
     * Whether this release also samples the alternate provider (a stable percentage of releases).
     */
    public static function comparesAlternate(int $releaseId): bool
    {
        $percent = max(0, min(100, (int) config('nntmux.fingerprint_alternate_percent', 10)));

        return crc32((string) $releaseId) % 100 < $percent;
    }

    /** @return list<array{name: string, alternate: bool, port: string, tls: bool, account: string}> */
    private function servers(int $releaseId): array
    {
        $servers = [[
            'name' => (string) config('nntmux_nntp.server', 'primary'),
            'alternate' => false,
            'port' => (string) config('nntmux_nntp.port', ''),
            'tls' => (bool) config('nntmux_nntp.ssl', false),
            'account' => 'primary',
        ]];
        if (trim((string) config('nntmux_nntp.alternate_server', '')) !== '' && self::comparesAlternate($releaseId)) {
            $servers[] = [
                'name' => (string) config('nntmux_nntp.alternate_server'),
                'alternate' => true,
                'port' => (string) config('nntmux_nntp.alternate_server_port', ''),
                'tls' => (bool) config('nntmux_nntp.alternate_server_ssl', false),
                'account' => 'alternate',
            ];
        }

        return $servers;
    }

    /**
     * @param  list<array<string, mixed>>  $files
     * @return array<string, mixed>|null
     */
    private function par2Summary(string $decoded, array $files): ?array
    {
        try {
            $parsed = $this->par2->parse($decoded);
        } catch (Throwable) {
            return null;
        }
        $nzbNames = [];
        foreach ($files as $file) {
            $nzbNames[self::subjectFilename((string) ($file['subject'] ?? ''))] = true;
        }
        $sources = [];
        $matches = 0;
        foreach ($parsed['source_files'] ?? [] as $source) {
            $name = is_string($source['filename'] ?? null) ? $source['filename'] : null;
            // Match complete names before they are shortened for output.
            if ($name !== null && $name !== '' && isset($nzbNames[$name])) {
                $matches++;
            }
            $sources[] = [
                'name' => self::bounded($name),
                'size' => is_int($source['file_length'] ?? null) ? $source['file_length'] : null,
                'md5' => $source['md5'] ?? null,
                'md5_16k' => $source['md5_first_16k'] ?? null,
            ];
        }
        usort($sources, static fn (array $a, array $b): int => [(string) $a['name'], (string) $a['md5']] <=> [(string) $b['name'], (string) $b['md5']]);

        return [
            'set_id' => $parsed['recovery_set_ids'][0] ?? null,
            'block_size' => $parsed['source_block_size'] ?? null,
            'recovery_blocks' => $parsed['unique_recovery_slice_count'] ?? null,
            'file_count' => count($sources),
            'files' => array_slice($sources, 0, self::MAX_LISTED_FILES),
            'nzb_matches' => $matches,
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private static function nzbSummary(array $manifest): array
    {
        $groups = self::nzbGroups($manifest['files']);
        $posters = [];
        $segments = 0;
        $bytes = 0;
        $kinds = [];
        foreach ($manifest['files'] as $file) {
            if (($file['poster'] ?? '') !== '') {
                $posters[(string) $file['poster']] = ($posters[(string) $file['poster']] ?? 0) + 1;
            }
            $segments += (int) ($file['segment_count'] ?? 0);
            $bytes += is_int($file['advertised_bytes'] ?? null) ? $file['advertised_bytes'] : 0;
            $kind = (string) ($file['kind'] ?? 'other');
            $kinds[$kind] = ($kinds[$kind] ?? 0) + 1;
        }
        ksort($kinds);
        arsort($posters);

        return [
            'file_count' => (int) $manifest['file_count'],
            'segment_count' => $segments,
            'total_bytes' => $bytes,
            'groups' => array_map(self::bounded(...), array_slice($groups, 0, self::MAX_LISTED_GROUPS)),
            'poster' => self::bounded(array_key_first($posters)),
            'kinds' => $kinds,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $files
     * @return list<string>
     */
    private static function nzbGroups(array $files): array
    {
        $groups = [];
        foreach ($files as $file) {
            foreach ($file['groups'] ?? [] as $group) {
                $groups[(string) $group] = true;
            }
        }
        $groups = array_map('strval', array_keys($groups));
        sort($groups);

        return $groups;
    }

    /**
     * @param  list<array<string, mixed>>  $files
     * @param  array<int, string>  $firstPrefixMd5
     * @return list<array<string, mixed>>
     */
    private static function fileRows(array $files, array $firstPrefixMd5): array
    {
        $rows = [];
        foreach (array_slice($files, 0, self::MAX_LISTED_FILES) as $file) {
            $index = (int) $file['index'];
            $rows[] = [
                'file_index' => $index,
                'name' => self::bounded(self::subjectFilename((string) ($file['subject'] ?? ''))),
                'size' => is_int($file['advertised_bytes'] ?? null) ? $file['advertised_bytes'] : null,
                'first16k_md5' => $firstPrefixMd5[$index] ?? null,
                'format_hint' => $file['format_hint'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $files
     * @return array{first_utc: string, last_utc: string, span_s: int}|null
     */
    private static function posting(array $files): ?array
    {
        // NZBs use date="0" for an unknown date; only positive timestamps are evidence.
        $dates = array_values(array_filter(array_map(static fn (array $file): mixed => $file['date'] ?? null, $files),
            static fn (mixed $date): bool => is_int($date) && $date > 0));
        if ($dates === []) {
            return null;
        }
        $first = min($dates);
        $last = max($dates);

        return ['first_utc' => gmdate('Y-m-d\TH:i:s\Z', $first), 'last_utc' => gmdate('Y-m-d\TH:i:s\Z', $last), 'span_s' => $last - $first];
    }

    /** @return array<string, mixed>|null */
    private static function yencSummary(mixed $yenc): ?array
    {
        if (! is_array($yenc)) {
            return null;
        }

        return [
            'name' => self::bounded($yenc['name'] ?? null),
            'size' => is_int($yenc['file_size'] ?? null) ? $yenc['file_size'] : null,
            'part' => is_int($yenc['part'] ?? null) ? $yenc['part'] : null,
            'total' => is_int($yenc['total_parts'] ?? null) ? $yenc['total_parts'] : null,
            'begin' => is_int($yenc['begin'] ?? null) ? $yenc['begin'] : null,
            'end' => is_int($yenc['end'] ?? null) ? $yenc['end'] : null,
            'crc_ok' => is_bool($yenc['declared_crc32_matches'] ?? null) ? $yenc['declared_crc32_matches'] : null,
            'size_ok' => self::sizesAgree($yenc),
        ];
    }

    /**
     * Integrity errors for one provider's copy of an article. The copy is clean only on positive
     * evidence: yEnc framing parsed, the declared CRC matches, the structure is single-part or a
     * complete multipart, escaping is valid and no size disagrees. Anything else is reported, with a
     * specific error where the analyzer established one and YencUnverified otherwise.
     *
     * @return list<string>
     */
    private static function yencIntegrityErrors(mixed $yenc): array
    {
        if (! is_array($yenc)) {
            return ['YencUnverified'];
        }
        $errors = [];
        if (($yenc['declared_crc32_matches'] ?? null) === false) {
            $errors[] = 'YencCrcMismatch';
        }
        foreach (['decoded_size_matches_yenc', 'decoded_size_matches_ypart_range', 'decoded_size_matches_yend_size'] as $check) {
            if (($yenc[$check] ?? null) === false) {
                $errors[] = 'YencSizeMismatch';
                break;
            }
        }
        if (($yenc['payload_escape_state_valid'] ?? null) === false) {
            $errors[] = 'YencFramingInvalid';
        }
        if (self::declaredCrcsContradict($yenc)) {
            $errors[] = 'YencCrcMismatch';
        }
        $verified = ($yenc['declared_crc32_matches'] ?? null) === true
            && ($yenc['all_declared_crc32_fields_are_hex'] ?? null) !== false
            && (self::sizesAgree($yenc) === true || self::emptySinglePart($yenc))
            && in_array($yenc['multipart_structure_status'] ?? null, ['single-part', 'complete'], true)
            && ($yenc['payload_escape_state_valid'] ?? null) === true
            && ($yenc['integrity_checks_ambiguous'] ?? null) !== true
            && ($yenc['decoded_size_matches_yend_size'] ?? null) === true
            && ! in_array(false, array_map(static fn (string $check): mixed => $yenc[$check] ?? null, array_values(array_diff([
                'decoded_size_matches_yenc', 'decoded_size_matches_ypart_range', 'ypart_range_valid',
                'header_numeric_fields_valid', 'header_numeric_fields_representable', 'trailer_numeric_fields_valid',
                'trailer_numeric_fields_representable', 'trailer_part_matches_header', 'trailer_total_matches_header',
            ], self::emptySinglePart($yenc) ? ['header_numeric_fields_valid'] : []))), true)
            && (! is_array($yenc['conflicting_control_fields'] ?? null) || $yenc['conflicting_control_fields'] === []);
        if (! $verified && $errors === []) {
            $errors[] = 'YencUnverified';
        }

        return array_values(array_unique($errors));
    }

    /**
     * True only when every size the article declares agrees with the decoded bytes, false when one
     * disagrees, and null when the evidence is missing or malformed.
     *
     * @param  array<string, mixed>  $yenc
     */
    private static function sizesAgree(array $yenc): ?bool
    {
        $checks = ['decoded_size_matches_yend_size', ($yenc['multipart'] ?? false) === true ? 'decoded_size_matches_ypart_range' : 'decoded_size_matches_yenc'];
        $values = array_map(static fn (string $check): mixed => $yenc[$check] ?? null, $checks);
        if (in_array(false, $values, true)) {
            return false;
        }

        return $values === [true, true] ? true : null;
    }

    /**
     * Every CRC an article declares must agree with its decoded bytes: both crc32 and pcrc32 for a
     * single-part article, and pcrc32 for a part (whose crc32 covers the whole file).
     *
     * @param  array<string, mixed>  $yenc
     */
    private static function declaredCrcsContradict(array $yenc): bool
    {
        $observed = strtolower((string) ($yenc['observed_decoded_crc32'] ?? ''));
        $trailer = is_array($yenc['trailer_fields'] ?? null) ? $yenc['trailer_fields'] : [];
        $fields = ($yenc['multipart'] ?? false) === true ? ['pcrc32'] : ['crc32', 'pcrc32'];
        foreach ($fields as $field) {
            $declared = $trailer[$field] ?? null;
            if (is_string($declared) && $observed !== '' && strtolower(str_pad(ltrim(trim($declared), '0'), 8, '0', STR_PAD_LEFT)) !== str_pad(ltrim($observed, '0'), 8, '0', STR_PAD_LEFT)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A valid empty file: single part, declared size 0, nothing decoded, and every other numeric header
     * field valid. The analyzer treats size 0 as a non-positive numeric field, which is only wrong here.
     *
     * @param  array<string, mixed>  $yenc
     */
    private static function emptySinglePart(array $yenc): bool
    {
        $header = is_array($yenc['header_fields'] ?? null) ? $yenc['header_fields'] : [];
        // Only size may be zero; every other numeric header field must still be a positive decimal.
        foreach (['line', 'part', 'total'] as $field) {
            if (array_key_exists($field, $header)
                && (! is_string($header[$field]) || preg_match('/\A[1-9][0-9]{0,18}\z/', $header[$field]) !== 1)) {
                return false;
            }
        }

        return ($yenc['multipart'] ?? null) === false
            && ($header['size'] ?? null) === '0'
            && ($yenc['decoded_segment_size'] ?? null) === 0
            && ($yenc['decoded_size_matches_yend_size'] ?? null) === true;
    }

    /**
     * An absolute RFC 5322 date with an explicit zone; relative or zone-less values would depend on
     * when and where the job ran.
     */
    private static function dateUtc(mixed $date): ?string
    {
        if (! is_string($date)) {
            return null;
        }
        $pattern = '/\A\s*(?:[A-Za-z]{3},\s*)?(\d{1,2})\s+(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+(\d{4})\s+'
            .'(\d{2}):(\d{2})(?::(\d{2}))?\s+([+-]\d{4}|UT|GMT|Z|[ECMP][SD]T)(?:\s*\(.*\))?\s*\z/i';
        if (preg_match($pattern, $date, $match) !== 1) {
            return null;
        }
        $zone = strtoupper($match[7]);
        $offset = match ($zone) {
            'UT', 'GMT', 'Z' => '+0000',
            'EST' => '-0500', 'EDT' => '-0400', 'CST' => '-0600', 'CDT' => '-0500',
            'MST' => '-0700', 'MDT' => '-0600', 'PST' => '-0800', 'PDT' => '-0700',
            default => $zone,
        };
        $parsed = \DateTimeImmutable::createFromFormat('!j M Y H:i:s O',
            sprintf('%d %s %s %s:%s:%s %s', $match[1], ucfirst(strtolower($match[2])), $match[3], $match[4], $match[5], $match[6] !== '' ? $match[6] : '00', $offset));
        $errors = \DateTimeImmutable::getLastErrors();
        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $parsed->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private static function xrefGroupCount(mixed $xref): ?int
    {
        if (! is_string($xref) || trim($xref) === '') {
            return null;
        }
        $parts = preg_split('/\s+/', trim($xref)) ?: [];

        // The first token is the server name; each further token is group:number.
        return max(0, count($parts) - 1);
    }

    private static function subjectFilename(string $subject): string
    {
        return preg_match('/"([^"]+)"/', $subject, $match) === 1 ? $match[1] : $subject;
    }

    private static function bounded(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $value = (string) $value;
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        // Cut on a UTF-8 boundary by bytes, so multibyte names cannot inflate the record.
        return mb_strcut($value, 0, self::MAX_NAME_LENGTH, 'UTF-8');
    }

    /** @param array<string, mixed> $file */
    private static function fileBytes(array $file): int
    {
        return is_int($file['advertised_bytes'] ?? null) ? $file['advertised_bytes'] : 0;
    }

    private static function responseCode(mixed $response): ?int
    {
        return is_array($response) && is_numeric($response['response_code'] ?? null) ? (int) $response['response_code'] : null;
    }

    /**
     * Files whose NZB records the analyzer found corrupt (or 1 for an invalid release byte total).
     *
     * @param  array<string, mixed>  $manifest
     */
    private static function manifestInvalidFileCount(array $manifest): int
    {
        $invalid = 0;
        foreach ($manifest['files'] as $file) {
            if ((int) ($file['invalid_segment_record_count'] ?? 0) > 0
                || (int) ($file['invalid_segment_metadata_count'] ?? 0) > 0
                || (int) ($file['duplicate_message_id_count'] ?? 0) > 0
                || (int) ($file['duplicate_segment_number_count'] ?? 0) > 0
                || ($file['segment_number_sequence_valid'] ?? null) === false
                || ($file['advertised_bytes_overflow'] ?? false) === true) {
                $invalid++;
            }
        }

        return $invalid === 0 && ($manifest['advertised_byte_totals_valid'] ?? true) === false ? 1 : $invalid;
    }

    /** @param array<string, mixed> $profile */
    private static function profileSucceeded(array $profile): bool
    {
        return ! isset($profile['capabilities_error_type'])
            && ! isset($profile['overview_format_error_type'])
            && in_array($profile['capabilities_response_code'] ?? null, [101, 500, 501], true)
            && (($profile['overview_format_response_code'] ?? null) === null
                || in_array($profile['overview_format_response_code'], [215, 500, 501], true));
    }

    /** The provider said the article does not exist; protocol and transport failures are errors. */
    private static function isMissingArticle(Throwable $exception): bool
    {
        return $exception instanceof NntpArticleMissingException;
    }
}
