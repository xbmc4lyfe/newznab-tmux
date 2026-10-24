<?php

declare(strict_types=1);

namespace App\Services\Releases\Fingerprint;

/**
 * `findCrossVolumeRecoveryConflicts`, `validateCrossVolumeIfscRelationships` and
 * `relateNzbFilePrefixesToSources` retain PHP under the < 1.5x FFI bailout rule: the Rust
 * candidates (`nntmux_par2_cross_volume_recovery_conflicts`, `nntmux_par2_validate_cross_volume_ifsc`,
 * `nntmux_par2_relate_nzb_file_prefixes` in `rust/pipeline-ffi/src/metadata.rs`) are output-identical
 * but `serialize()`/`unserialize()` marshaling of the caller's arrays (NZB files carry full segment
 * lists) measured 0.23x-0.91x on common paths. Raw packet parsing in `parse()` is already native.
 */
final class Par2MetadataParser
{
    private const MAX_MAIN_FILE_IDS = 32_768;

    public const MAX_SOURCE_BLOCKS = 32_768;

    /**
     * @param  list<array<string, mixed>>  $volumes
     * @return list<array<string, mixed>>
     */
    public static function findCrossVolumeRecoveryConflicts(array $volumes): array
    {
        $firstBySetAndExponent = [];
        $conflictingKeys = [];
        $conflicts = [];
        foreach ($volumes as $volume) {
            foreach ($volume['recovery_packets'] ?? [] as $packet) {
                $setId = $packet['recovery_set_id'] ?? null;
                $exponent = $packet['exponent'] ?? null;
                $sliceHash = $packet['slice_sha256'] ?? null;
                $offset = $packet['packet_offset'] ?? null;
                if (! is_string($setId) || ! is_int($exponent) || $exponent < 0 || $exponent > 65535
                    || ! is_string($sliceHash) || preg_match('/\A[0-9a-f]{64}\z/i', $sliceHash) !== 1
                    || ! is_int($offset) || $offset < 0) {
                    continue;
                }
                $exponent = $exponent === 65535 ? 0 : $exponent;
                $key = $setId."\0".$exponent;
                if (! isset($firstBySetAndExponent[$key])) {
                    $firstBySetAndExponent[$key] = ['volume' => $volume, 'packet' => $packet];

                    continue;
                }
                $first = $firstBySetAndExponent[$key];
                if (($first['volume']['file_index'] ?? null) === ($volume['file_index'] ?? null)) {
                    continue;
                }
                $firstPacket = $first['packet'];
                if (strcasecmp($firstPacket['slice_sha256'], $sliceHash) === 0 || isset($conflictingKeys[$key])) {
                    continue;
                }
                $conflictingKeys[$key] = true;
                $conflicts[] = [
                    'recovery_set_id' => $setId,
                    'exponent' => $exponent,
                    'first_file_index' => $first['volume']['file_index'] ?? null,
                    'first_subject' => $first['volume']['subject'] ?? null,
                    'first_packet_offset' => $firstPacket['packet_offset'],
                    'first_slice_sha256' => $firstPacket['slice_sha256'],
                    'first_article_segments' => $firstPacket['article_segments'] ?? [],
                    'conflicting_file_index' => $volume['file_index'] ?? null,
                    'conflicting_subject' => $volume['subject'] ?? null,
                    'conflicting_packet_offset' => $offset,
                    'conflicting_slice_sha256' => $sliceHash,
                    'conflicting_article_segments' => $packet['article_segments'] ?? [],
                ];
            }
        }

        return $conflicts;
    }

    /**
     * @param  array<string, mixed>|null  $mainPacket
     * @param  array<string, array<string, mixed>>  $sourceFiles  keyed by file ID
     * @param  list<array<string, mixed>>  $checksums  collected from every volume in one recovery set
     * @return array{valid: bool, status: string, conflicting_checksum_file_ids: list<string>, main_membership_mismatch_file_ids: list<string>, slice_count_mismatch_file_ids: list<string>, invalid_file_ids: list<string>, unresolved_file_ids: list<string>}
     */
    public static function validateCrossVolumeIfscRelationships(
        ?array $mainPacket,
        array $sourceFiles,
        ?int $blockSize,
        array $checksums,
    ): array {
        $mainFileIds = $mainPacket['all_file_ids'] ?? null;
        if (! is_array($mainFileIds)) {
            $mainFileIds = [...($mainPacket['recoverable_file_ids'] ?? []), ...($mainPacket['non_recoverable_file_ids'] ?? [])];
        }
        $mainFileIds = array_values(array_filter($mainFileIds, 'is_string'));
        $seen = [];
        $conflictingChecksumFileIds = [];
        $mainMembershipMismatchFileIds = [];
        $sliceCountMismatchFileIds = [];
        $invalidFileIds = [];
        $unresolvedFileIds = [];

        foreach ($checksums as $checksum) {
            $fileId = $checksum['file_id'] ?? null;
            $sliceCount = $checksum['slice_count'] ?? null;
            $recordsHash = $checksum['checksum_records_sha256'] ?? null;
            if (! is_string($fileId) || preg_match('/\A[0-9a-f]{32}\z/i', $fileId) !== 1
                || ! is_int($sliceCount) || $sliceCount < 0
                || ! is_string($recordsHash) || preg_match('/\A[0-9a-f]{64}\z/i', $recordsHash) !== 1) {
                $invalidFileIds[] = is_string($fileId) ? $fileId : '';

                continue;
            }
            $fileId = strtolower($fileId);
            $recordsHash = strtolower($recordsHash);
            if (($checksum['duplicate_conflict'] ?? false) === true) {
                $conflictingChecksumFileIds[] = $fileId;
            }
            if (isset($seen[$fileId])
                && ($seen[$fileId]['checksum_records_sha256'] !== $recordsHash || $seen[$fileId]['slice_count'] !== $sliceCount)) {
                $conflictingChecksumFileIds[] = $fileId;
            } else {
                $seen[$fileId] = ['checksum_records_sha256' => $recordsHash, 'slice_count' => $sliceCount];
            }
            if ($mainPacket !== null && ! in_array($fileId, $mainFileIds, true)) {
                $mainMembershipMismatchFileIds[] = $fileId;
            }
            $source = $sourceFiles[$fileId] ?? null;
            $fileLength = $source['file_length'] ?? null;
            if (is_array($source) && is_int($fileLength) && $fileLength >= 0 && is_int($blockSize) && $blockSize > 0) {
                $expectedSliceCount = intdiv($fileLength, $blockSize) + ($fileLength % $blockSize === 0 ? 0 : 1);
                if ($expectedSliceCount !== $sliceCount || ($checksum['slice_count_matches_file_length'] ?? null) === false) {
                    $sliceCountMismatchFileIds[] = $fileId;
                }
            } else {
                $unresolvedFileIds[] = $fileId;
            }
        }

        foreach ([&$conflictingChecksumFileIds, &$mainMembershipMismatchFileIds, &$sliceCountMismatchFileIds, &$invalidFileIds, &$unresolvedFileIds] as &$ids) {
            $ids = array_values(array_unique($ids));
            sort($ids);
        }
        unset($ids);

        $valid = $conflictingChecksumFileIds === [] && $mainMembershipMismatchFileIds === []
            && $sliceCountMismatchFileIds === [] && $invalidFileIds === [];
        $status = ! $valid ? 'invalid'
            : ($checksums === [] ? 'no_ifsc_packets'
                : ($unresolvedFileIds !== [] ? 'pending_source_metadata' : 'valid'));

        return [
            'valid' => $valid,
            'status' => $status,
            'conflicting_checksum_file_ids' => $conflictingChecksumFileIds,
            'main_membership_mismatch_file_ids' => $mainMembershipMismatchFileIds,
            'slice_count_mismatch_file_ids' => $sliceCountMismatchFileIds,
            'invalid_file_ids' => $invalidFileIds,
            'unresolved_file_ids' => $unresolvedFileIds,
        ];
    }

    /**
     * Relate verified first-segment decoded prefixes to PAR2 source descriptors.
     *
     * @param  list<array<string, mixed>>  $nzbFiles
     * @param  list<array<string, mixed>>  $observations
     * @param  list<array<string, mixed>>  $sources
     * @param  list<string>  $conflictingSourceKeys  Set/file keys whose source definitions disagree across PAR2 volumes
     * @return array<string, mixed>
     */
    public static function relateNzbFilePrefixesToSources(
        array $nzbFiles,
        array $observations,
        array $sources,
        array $conflictingSourceKeys = [],
    ): array {
        $conflictingSourceKeys = array_fill_keys($conflictingSourceKeys, true);
        $files = [];
        $qualifiedPrefixCount = 0;
        $candidateMatchCount = 0;
        $ambiguousCount = 0;
        $noMatchCount = 0;
        $insufficientEvidenceCount = 0;
        $conflictingSourceDefinitionCount = 0;
        $eligibleFiles = array_values(array_filter(
            $nzbFiles,
            static fn (array $file): bool => ($file['kind'] ?? null) !== 'par2',
        ));
        $par2CarrierFileCount = count($nzbFiles) - count($eligibleFiles);

        foreach ($eligibleFiles as $file) {
            $fileIndex = $file['index'] ?? null;
            $subject = is_string($file['subject'] ?? null) ? $file['subject'] : null;
            $fileObservations = array_values(array_filter(
                $observations,
                static fn (array $observation): bool => ($observation['file_index'] ?? null) === $fileIndex
                    && ($observation['segment_number'] ?? null) === 1
                    && ($observation['sample_role'] ?? null) === 'first'
                    && ($observation['server_role'] ?? null) === 'primary',
            ));
            $sample = count($fileObservations) === 1 ? $fileObservations[0] : null;
            [$prefix, $evidenceStatus] = self::verifiedFirstSegmentPrefix($file, $sample);
            $matches = [];
            $conflictedCandidates = [];

            if ($prefix !== null) {
                $qualifiedPrefixCount++;
                foreach ($sources as $source) {
                    $sourceKey = self::sourceDefinitionKey($source);
                    $sourceLength = self::boundedNonNegativeInt($source['file_length'] ?? null);
                    if ($sourceLength !== $prefix['file_length']) {
                        continue;
                    }
                    $sourceSetWildcard = is_string($source['recovery_set_id'] ?? null)
                        ? strtolower($source['recovery_set_id']).':*' : null;
                    if ($sourceKey !== null && (isset($conflictingSourceKeys[$sourceKey])
                        || ($sourceSetWildcard !== null && isset($conflictingSourceKeys[$sourceSetWildcard])))) {
                        $conflictedCandidates[] = [
                            'recovery_set_id' => $source['recovery_set_id'],
                            'file_id' => strtolower((string) $source['file_id']),
                            'par2_filename' => $source['filename'] ?? null,
                            'par2_file_length' => $sourceLength,
                            'comparison_status' => 'source_definition_conflict',
                        ];

                        continue;
                    }
                    $sourceHash = $source['md5_first_16k'] ?? null;
                    if (! is_string($sourceHash) || preg_match('/\A[0-9a-f]{32}\z/i', $sourceHash) !== 1
                        || ! is_string($source['recovery_set_id'] ?? null)
                        || ! is_string($source['file_id'] ?? null)
                        || preg_match('/\A[0-9a-f]{32}\z/i', $source['file_id']) !== 1) {
                        continue;
                    }
                    if (strcasecmp($sourceHash, $prefix['md5']) !== 0) {
                        continue;
                    }
                    $matches[] = [
                        'recovery_set_id' => strtolower($source['recovery_set_id']),
                        'file_id' => strtolower($source['file_id']),
                        'par2_filename' => is_string($source['filename'] ?? null) ? $source['filename'] : null,
                        'par2_file_length' => $sourceLength,
                        'par2_md5_first_16k' => strtolower($sourceHash),
                        'file_length_matches' => true,
                        'prefix_md5_matches' => true,
                        'match_basis' => 'exact_file_length_and_par2_md5_first_16k',
                        'whole_file_identity_established' => false,
                    ];
                }
            }

            if ($prefix === null) {
                $status = 'insufficient_evidence';
                $insufficientEvidenceCount++;
            } elseif (count($matches) + count($conflictedCandidates) > 1) {
                $status = 'ambiguous';
                $ambiguousCount++;
            } elseif ($conflictedCandidates !== []) {
                $status = 'conflicting_source_definition';
                $conflictingSourceDefinitionCount++;
            } elseif (count($matches) === 1) {
                $status = 'candidate_match';
                $candidateMatchCount++;
            } else {
                $status = 'no_match';
                $noMatchCount++;
            }

            $files[] = [
                'file_index' => is_int($fileIndex) ? $fileIndex : null,
                'kind' => is_string($file['kind'] ?? null) ? $file['kind'] : null,
                'nzb_subject' => $subject,
                'status' => $status,
                'evidence_status' => $prefix === null ? $evidenceStatus : 'verified_yenc_prefix',
                'sample' => $sample === null ? null : [
                    'message_id' => is_string($sample['message_id'] ?? null) ? $sample['message_id'] : null,
                    'server' => is_string($sample['server'] ?? null) ? $sample['server'] : null,
                    'provider_label' => is_string($sample['provider_label'] ?? null) ? $sample['provider_label'] : null,
                    'server_role' => $sample['server_role'],
                    'server_port' => self::boundedNonNegativeInt($sample['server_port'] ?? null),
                    'server_tls' => is_bool($sample['server_tls'] ?? null) ? $sample['server_tls'] : null,
                    'queried_at_utc' => is_string($sample['queried_at_utc'] ?? null) ? $sample['queried_at_utc'] : null,
                    'segment_number' => 1,
                    'file_offset_start' => 0,
                    'file_length' => $prefix['file_length'] ?? null,
                    'prefix_bytes_hashed' => $prefix['bytes_hashed'] ?? null,
                    'prefix_md5' => $prefix['md5'] ?? null,
                ],
                'candidate_count' => count($matches) + count($conflictedCandidates),
                'matches' => $matches,
                'conflicted_candidates' => $conflictedCandidates,
            ];
        }

        $hasConflictingSourceDefinitions = $conflictingSourceKeys !== [];
        $hasInsufficientEvidence = $insufficientEvidenceCount > 0;
        $status = $sources === [] ? 'no_par2_sources'
            : ($hasConflictingSourceDefinitions || $hasInsufficientEvidence ? 'partial' : 'complete');

        return [
            'status' => $status,
            'comparison_basis' => 'A primary-provider first article whose decoded yEnc range begins at byte 1 and passes size, segment, and CRC checks; exact decoded-prefix length and MD5 are compared with each non-conflicting PAR2 source descriptor file length and md5_first_16k.',
            'prefix_limit_bytes' => 16_384,
            'whole_file_identity_established' => false,
            'nzb_file_count' => count($nzbFiles),
            'source_file_count' => count($files),
            'par2_carrier_file_count' => $par2CarrierFileCount,
            'qualified_prefix_count' => $qualifiedPrefixCount,
            'candidate_match_count' => $candidateMatchCount,
            'ambiguous_count' => $ambiguousCount,
            'no_match_count' => $noMatchCount,
            'insufficient_evidence_count' => $insufficientEvidenceCount,
            'conflicting_source_definition_count' => $conflictingSourceDefinitionCount,
            'conflicting_source_keys' => array_keys($conflictingSourceKeys),
            'files' => $files,
        ];
    }

    /** @param array<string, mixed> $file
     * @param  array<string, mixed>|null  $sample
     * @return array{?array{file_length: int, bytes_hashed: int, md5: string}, string}
     */
    private static function verifiedFirstSegmentPrefix(array $file, ?array $sample): array
    {
        if (($file['segment_number_sequence_valid'] ?? false) !== true
            || ($file['invalid_segment_record_count'] ?? null) !== 0
            || ($file['invalid_segment_metadata_count'] ?? null) !== 0
            || ($file['duplicate_message_id_count'] ?? null) !== 0
            || ($file['duplicate_segment_number_count'] ?? null) !== 0) {
            return [null, 'nzb_file_segment_inventory_invalid'];
        }
        if ($sample === null) {
            return [null, 'first_primary_sample_missing_or_ambiguous'];
        }
        $metadata = $sample['metadata'] ?? null;
        $yenc = is_array($metadata) && is_array($metadata['yenc'] ?? null) ? $metadata['yenc'] : null;
        $identity = is_array($metadata) && is_array($metadata['decoded_format_metadata'] ?? null)
            && is_array($metadata['decoded_format_metadata']['sample_prefix_identity'] ?? null)
            ? $metadata['decoded_format_metadata']['sample_prefix_identity'] : null;
        if (! is_array($yenc) || ! is_array($identity)) {
            return [null, 'prefix_or_yenc_metadata_unavailable'];
        }
        if (! self::bodyResponseIdentityVerified($metadata)) {
            return [null, 'article_response_identity_unverified'];
        }
        if (($yenc['header_numeric_fields_valid'] ?? false) !== true
            || ($yenc['integrity_checks_ambiguous'] ?? true) !== false
            || ($yenc['decoded_size_matches_yenc'] ?? false) !== true
            || ($yenc['decoded_size_matches_yend_size'] ?? false) !== true) {
            return [null, 'yenc_integrity_failed'];
        }

        $fileLength = self::boundedNonNegativeInt($yenc['file_size'] ?? null);
        if ($fileLength === null || $fileLength === 0) {
            return [null, 'yenc_file_length_unavailable'];
        }
        $expectedPrefixBytes = min($fileLength, 16_384);
        $bytesHashed = self::boundedNonNegativeInt($identity['bytes_hashed'] ?? null);
        $prefixMd5 = $identity['md5'] ?? null;
        if ($bytesHashed !== $expectedPrefixBytes || ! is_string($prefixMd5)
            || preg_match('/\A[0-9a-f]{32}\z/i', $prefixMd5) !== 1
            || ($identity['parser_input_offset_zero'] ?? false) !== true
            || ($identity['file_offset_known'] ?? true) !== false) {
            return [null, 'decoded_prefix_identity_incomplete'];
        }

        $decodedSize = self::boundedNonNegativeInt($yenc['decoded_segment_size'] ?? null);
        if ($decodedSize === null || $decodedSize < $expectedPrefixBytes) {
            return [null, 'decoded_prefix_too_short'];
        }

        if (($yenc['multipart'] ?? null) === true) {
            $part = self::boundedNonNegativeInt($yenc['part'] ?? null);
            $totalParts = self::boundedNonNegativeInt($yenc['total_parts'] ?? null);
            $begin = self::boundedNonNegativeInt($yenc['begin'] ?? null);
            $end = self::boundedNonNegativeInt($yenc['end'] ?? null);
            $crcValid = is_string($yenc['pcrc32'] ?? null)
                && ($yenc['declared_crc32_is_hex'] ?? false) === true
                && ($yenc['declared_crc32_matches'] ?? false) === true;
            $trailerFields = is_array($yenc['trailer_fields'] ?? null) ? $yenc['trailer_fields'] : [];
            $trailerPart = self::boundedNonNegativeInt($trailerFields['part'] ?? null);
            $trailerTotalParts = self::boundedNonNegativeInt($trailerFields['total'] ?? null);
            $nativeFraming = is_array($metadata['yenc_framing_metadata'] ?? null)
                ? $metadata['yenc_framing_metadata'] : null;
            $nativeFrames = is_array($nativeFraming['frames'] ?? null) ? $nativeFraming['frames'] : null;
            $nativeFrame = is_array($nativeFrames) && count($nativeFrames) === 1 && is_array($nativeFrames[0])
                ? $nativeFrames[0] : null;
            $nativeFrameContradictsYenc = $nativeFraming !== null
                && (($nativeFraming['frames_truncated'] ?? false) === true
                    || ($nativeFraming['frame_count'] ?? null) !== 1
                    || $nativeFrame === null
                    || ($nativeFrame['part_number_matches'] ?? null) === false
                    || ($nativeFrame['total_parts_match'] ?? null) === false);
            if ($part !== 1 || $totalParts === null || $totalParts < 1
                || $trailerPart !== $part
                || ($trailerTotalParts !== null && $trailerTotalParts !== $totalParts)
                || $nativeFrameContradictsYenc
                || ($metadata['yenc_nzb_part_number_matches'] ?? false) !== true
                || ($metadata['yenc_nzb_total_parts_matches'] ?? false) !== true) {
                return [null, 'yenc_integrity_failed'];
            }
            if ($begin !== 1) {
                return [null, 'yenc_start_offset_not_zero'];
            }
            if (($yenc['ypart_range_valid'] ?? false) !== true
                || ($yenc['decoded_size_matches_ypart_range'] ?? false) !== true
                || $end === null || $end < $expectedPrefixBytes || $end > $fileLength || ! $crcValid) {
                return [null, 'yenc_integrity_failed'];
            }
        } elseif (($yenc['multipart'] ?? null) === false) {
            if (($yenc['part'] ?? null) !== null || $decodedSize !== $fileLength
                || ($yenc['decoded_size_matches_header_size'] ?? false) !== true) {
                return [null, 'yenc_file_coverage_unverified'];
            }
            if (! self::declaredYencCrc32Valid($yenc)) {
                return [null, 'yenc_integrity_failed'];
            }
        } else {
            return [null, 'yenc_multipart_status_unavailable'];
        }

        return [[
            'file_length' => $fileLength,
            'bytes_hashed' => $bytesHashed,
            'md5' => strtolower($prefixMd5),
        ], 'verified_yenc_prefix'];
    }

    /** @param array<string, mixed> $yenc */
    public static function declaredYencCrc32Valid(array $yenc): bool
    {
        $basis = $yenc['segment_crc32_basis'] ?? null;
        if (in_array($basis, ['unavailable-conflicting-yenc-field', 'unavailable-multipart-structure'], true)) {
            return false;
        }

        $field = in_array($basis, ['crc32', 'pcrc32'], true)
            ? $basis
            : (array_key_exists('crc32', $yenc) && $yenc['crc32'] !== null
                ? 'crc32'
                : (array_key_exists('pcrc32', $yenc) && $yenc['pcrc32'] !== null ? 'pcrc32' : null));
        if ($field === null) {
            return $basis === null || in_array($basis, ['missing-crc32', 'missing-pcrc32'], true);
        }

        $declared = $yenc[$field] ?? null;

        return is_string($declared)
            && preg_match('/\A[0-9a-f]{1,8}\z/i', $declared) === 1
            && ($yenc['declared_crc32_is_hex'] ?? false) === true
            && ($yenc['declared_crc32_matches'] ?? false) === true;
    }

    /** @param array<string, mixed> $metadata */
    public static function bodyResponseIdentityVerified(array $metadata): bool
    {
        $identity = is_array($metadata['article_identity'] ?? null) ? $metadata['article_identity'] : null;
        $relations = is_array($identity['relations'] ?? null) ? $identity['relations'] : null;

        return ($identity['parser_engine'] ?? null) === 'nntmux-rust-article-identity'
            && ($identity['schema_version'] ?? null) === 3
            && ($relations['body_response_matches_request'] ?? null) === true;
    }

    /** @param array<string, mixed> $metadata
     * @return list<string>
     */
    public static function sourceDefinitionConflictKeys(array $metadata): array
    {
        $keys = [];
        $conflicts = is_array($metadata['source_definition_conflicts'] ?? null)
            ? $metadata['source_definition_conflicts'] : [];
        foreach ($conflicts as $conflict) {
            if (! is_array($conflict)) {
                continue;
            }
            $setId = $conflict['recovery_set_id'] ?? null;
            $fileId = $conflict['file_id'] ?? null;
            if (! is_string($setId) || preg_match('/\A[0-9a-f]{32}\z/i', $setId) !== 1) {
                continue;
            }
            if ($fileId !== null && (! is_string($fileId) || preg_match('/\A[0-9a-f]{32}\z/i', $fileId) !== 1)) {
                continue;
            }
            $keys[] = strtolower($setId.':'.($fileId ?? '*'));
        }

        return array_values(array_unique($keys));
    }

    /** @param array<string, mixed> $source */
    private static function sourceDefinitionKey(array $source): ?string
    {
        if (! is_string($source['recovery_set_id'] ?? null) || ! is_string($source['file_id'] ?? null)) {
            return null;
        }

        return strtolower($source['recovery_set_id']).':'.strtolower($source['file_id']);
    }

    private static function boundedNonNegativeInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (! is_string($value) || preg_match('/\A[0-9]+\z/D', $value) !== 1) {
            return null;
        }
        $normalized = ltrim($value, '0');
        if ($normalized === '') {
            return 0;
        }
        $maximum = (string) PHP_INT_MAX;
        if (strlen($normalized) > strlen($maximum)
            || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0)) {
            return null;
        }

        return (int) $normalized;
    }

    /**
     * @param  list<array<string, mixed>>  $packets
     * @return list<int>
     */
    public static function validatedRecoveryExponents(array $packets, int $blockSize): array
    {
        if ($blockSize < 1 || $blockSize > PHP_INT_MAX - 4) {
            return [];
        }

        $exponents = [];
        foreach ($packets as $packet) {
            $exponent = $packet['exponent'] ?? null;
            if (is_int($exponent) && $exponent >= 0 && $exponent <= 65535
                && ($packet['payload_bytes'] ?? null) === $blockSize + 4) {
                $exponents[$exponent === 65535 ? 0 : $exponent] = true;
            }
        }

        $values = array_map('intval', array_keys($exponents));
        sort($values);

        return $values;
    }

    /**
     * @param  list<array<string, mixed>>  $packets
     * @param  list<array{segment_number: int, message_id: string, offset: int, byte_length: int, server?: string, provider_label?: string, server_role?: string, declared_file_byte_start?: ?int, declared_file_byte_end_inclusive?: ?int, declared_range_matches_assembled_position?: bool}>  $segments
     * @return list<array<string, mixed>>
     */
    public static function mapPacketArticleSpans(array $packets, array $segments): array
    {
        usort($segments, static fn (array $left, array $right): int => ($left['offset'] ?? PHP_INT_MAX) <=> ($right['offset'] ?? PHP_INT_MAX));
        $segmentsValid = true;
        $previousEnd = 0;
        foreach ($segments as $segment) {
            $offset = $segment['offset'] ?? null;
            $byteLength = $segment['byte_length'] ?? null;
            if (! is_int($offset) || ! is_int($byteLength) || $offset < $previousEnd || $byteLength < 1
                || $byteLength > PHP_INT_MAX - $offset || ! is_int($segment['segment_number'] ?? null)
                || ! is_string($segment['message_id'] ?? null) || $segment['message_id'] === '') {
                $segmentsValid = false;
                break;
            }
            $previousEnd = $offset + $byteLength;
        }

        foreach ($packets as &$packet) {
            $offset = $packet['offset'] ?? null;
            $byteLength = $packet['packet_bytes'] ?? null;
            $spans = [];
            $mappedBytes = 0;
            $packetValid = $segmentsValid && is_int($offset) && is_int($byteLength)
                && $offset >= 0 && $byteLength >= 0 && $byteLength <= PHP_INT_MAX - $offset;
            if ($packetValid) {
                $packetEnd = $offset + $byteLength;
                $zeroLengthPositionCovered = false;
                foreach ($segments as $segment) {
                    $segmentStart = $segment['offset'];
                    $segmentEnd = $segmentStart + $segment['byte_length'];
                    $zeroLengthPositionCovered = $zeroLengthPositionCovered
                        || ($byteLength === 0 && $offset >= $segmentStart && $offset <= $segmentEnd);
                    $overlapStart = max($offset, $segmentStart);
                    $overlapEnd = min($packetEnd, $segmentEnd);
                    if ($overlapStart >= $overlapEnd) {
                        continue;
                    }
                    $overlapBytes = $overlapEnd - $overlapStart;
                    $mappedBytes += $overlapBytes;
                    $span = [
                        'segment_number' => $segment['segment_number'],
                        'message_id' => $segment['message_id'],
                        'assembled_file_byte_start' => $overlapStart,
                        'assembled_file_byte_end_exclusive' => $overlapEnd,
                        'segment_byte_start' => $overlapStart - $segmentStart,
                        'segment_byte_end_exclusive' => $overlapEnd - $segmentStart,
                        'overlap_bytes' => $overlapBytes,
                    ];
                    foreach (['server' => 'queried_server', 'provider_label' => 'provider_label', 'server_role' => 'server_role'] as $field => $outputField) {
                        if (is_string($segment[$field] ?? null)) {
                            $span[$outputField] = $segment[$field];
                        }
                    }
                    foreach (['declared_file_byte_start', 'declared_file_byte_end_inclusive', 'declared_range_matches_assembled_position'] as $field) {
                        if (array_key_exists($field, $segment)) {
                            $span[$field] = $segment[$field];
                        }
                    }
                    $spans[] = $span;
                }
                $packetValid = $byteLength === 0
                    ? $zeroLengthPositionCovered
                    : $mappedBytes === $byteLength;
            }
            $packet['article_segments'] = $spans;
            $packet['article_span_mapped_bytes'] = $mappedBytes;
            $packet['article_span_mapping_complete'] = $packetValid;
        }
        unset($packet);

        return $packets;
    }

    /** @return array<string, mixed> */
    public function parse(string $data): array
    {
        $native = (new NativeMetadataParser)->parsePar2($data);
        if ($native !== null) {
            return self::normalizeNativeIntegerRanges($native);
        }

        $offset = 0;
        $blockSize = null;
        $creator = null;
        $sources = [];
        $sourcesBySet = [];
        $blockSizesBySet = [];
        $mainsBySet = [];
        $mainFileIdLimitObservations = [];
        $retainedMainFileIdCount = 0;
        $ifscBySetAndFile = [];
        $main = null;
        $recoveryExponentsBySet = [];
        $recoverySliceConflictsBySet = [];
        $recoveryExponentsObservedBySet = [];
        $recoverySliceLengthsUnvalidatedBySet = [];
        $recoveryPackets = [];
        $optionalPackets = [];
        $packetTypeCounts = [];
        $packetSummaries = [];
        $packetDefinitions = [];
        $sourceDefinitionConflicts = [];
        $packetCount = 0;
        $invalidPacketCount = 0;
        $unsupportedIntegerRangeCount = 0;
        $unsupportedIntegerRangeObservations = [];
        $unsupportedIntegerSets = [];
        $unparsedBytes = 0;
        $length = strlen($data);
        $maximumPackets = 20_000;

        while ($offset + 64 <= $length && $packetCount < $maximumPackets) {
            $magic = substr($data, $offset, 8);
            if ($magic !== "PAR2\0PKT") {
                $next = strpos($data, "PAR2\0PKT", $offset + 1);
                if ($next === false) {
                    $unparsedBytes += $length - $offset;
                    break;
                }
                $unparsedBytes += $next - $offset;
                $offset = $next;

                continue;
            }
            $packetLengthBytes = substr($data, $offset + 8, 8);
            $packetLength = self::unsigned64($packetLengthBytes);
            if ($packetLength === null) {
                $unsupportedIntegerRangeCount++;
                $unsupportedIntegerRangeObservations[] = [
                    'packet_offset' => $offset,
                    'field' => 'packet_length',
                    'raw_little_endian_hex' => bin2hex($packetLengthBytes),
                ];
            }
            if ($packetLength === null || $packetLength < 64 || $packetLength > $length - $offset || $packetLength % 4 !== 0) {
                $invalidPacketCount++;
                break;
            }
            $packet = substr($data, $offset, (int) $packetLength);
            $packetCount++;
            $type = rtrim(substr($packet, 48, 16), "\0");
            $payload = substr($packet, 64);
            $payloadHash = hash('sha256', $payload);
            $recoveryExponent = $type === "PAR 2.0\0RecvSlic" && strlen($payload) >= 4
                ? (int) (unpack('V', substr($payload, 0, 4))[1] ?? 0) : null;
            $checksumValid = hash_equals(substr($packet, 16, 16), md5(substr($packet, 32), true));
            $packetTypeCounts[$type] = ($packetTypeCounts[$type] ?? 0) + 1;
            $packetSummaries[] = [
                'offset' => $offset,
                'packet_bytes' => (int) $packetLength,
                'packet_type' => str_replace("\0", '\\0', $type),
                'set_id' => bin2hex(substr($packet, 32, 16)),
                'packet_md5' => bin2hex(substr($packet, 16, 16)),
                'checksum_valid' => $checksumValid,
                'payload_bytes' => strlen($payload),
                'payload_sha256' => $payloadHash,
                'payload_prefix_hex' => bin2hex(substr($payload, 0, 256)),
                'payload_prefix_truncated' => strlen($payload) > 256,
                'recovery_exponent' => $recoveryExponent,
                'recovery_data_sha256' => $recoveryExponent === null ? null : hash('sha256', substr($payload, 4)),
                'physical_regions' => self::packetPhysicalRegions(
                    $offset,
                    $type,
                    $payload,
                ),
            ];
            if (! $checksumValid) {
                $invalidPacketCount++;
                $offset += (int) $packetLength;

                continue;
            }
            if ($type === "PAR 2.0\0Main" || $type === "PAR 2.0\0FileDesc") {
                $definitionKey = $type === "PAR 2.0\0Main"
                    ? bin2hex(substr($packet, 32, 16)).':main'
                    : bin2hex(substr($packet, 32, 16)).':file:'.bin2hex(substr($payload, 0, 16));
                $definitionHash = $payloadHash;
                if (isset($packetDefinitions[$definitionKey])) {
                    $firstDefinition = $packetDefinitions[$definitionKey];
                    if ($firstDefinition['payload_sha256'] !== $definitionHash) {
                        $invalidPacketCount++;
                        $isMainDefinition = $type === "PAR 2.0\0Main";
                        $sourceDefinitionConflicts[] = [
                            'recovery_set_id' => bin2hex(substr($packet, 32, 16)),
                            'definition_kind' => $isMainDefinition ? 'main' : 'file_description',
                            'file_id' => $isMainDefinition ? null : bin2hex(substr($payload, 0, 16)),
                            'first_packet_offset' => $firstDefinition['packet_offset'],
                            'conflicting_packet_offset' => $offset,
                            'first_payload_sha256' => $firstDefinition['payload_sha256'],
                            'conflicting_payload_sha256' => $definitionHash,
                        ];
                    }
                } else {
                    $packetDefinitions[$definitionKey] = [
                        'payload_sha256' => $definitionHash,
                        'packet_offset' => $offset,
                    ];
                }
            }
            if ($type === "PAR 2.0\0Main" && strlen($payload) >= 12) {
                $candidateBlockSize = self::unsigned64(substr($payload, 0, 8));
                if ($candidateBlockSize === null) {
                    $unsupportedIntegerRangeCount++;
                    $unsupportedIntegerSets[bin2hex(substr($packet, 32, 16))] = true;
                    $unsupportedIntegerRangeObservations[] = [
                        'packet_offset' => $offset,
                        'field' => 'source_block_size',
                        'raw_little_endian_hex' => bin2hex(substr($payload, 0, 8)),
                    ];
                }
                $recoverableFileCount = (int) (unpack('V', substr($payload, 8, 4))[1] ?? 0);
                $fileIdsByteLength = strlen($payload) - 12;
                $setId = bin2hex(substr($packet, 32, 16));
                $mainAlreadyRetained = isset($mainsBySet[$setId]);
                $retainedBudgetExceeded = ! $mainAlreadyRetained
                    && intdiv($fileIdsByteLength, 16) > self::MAX_MAIN_FILE_IDS - $retainedMainFileIdCount;
                if ($fileIdsByteLength > self::MAX_MAIN_FILE_IDS * 16 || $retainedBudgetExceeded) {
                    $mainFileIdLimitObservations[] = [
                        'packet_offset' => $offset,
                        'recovery_set_id' => bin2hex(substr($packet, 32, 16)),
                        'observed_file_id_count' => intdiv($fileIdsByteLength, 16),
                        'trailing_file_id_bytes' => $fileIdsByteLength % 16,
                        'limit_kind' => $fileIdsByteLength > self::MAX_MAIN_FILE_IDS * 16
                            ? 'packet-file-id-limit' : 'retained-file-id-budget',
                        'retained_file_id_count' => $retainedMainFileIdCount,
                    ];
                    $invalidPacketCount++;
                    $offset += (int) $packetLength;

                    continue;
                }
                if ($mainAlreadyRetained && $packetDefinitions[$setId.':main']['payload_sha256'] === $payloadHash) {
                    $offset += (int) $packetLength;

                    continue;
                }
                $fileIdsBytes = substr($payload, 12);
                $fileIds = strlen($fileIdsBytes) % 16 === 0 ? array_map('bin2hex', str_split($fileIdsBytes, 16)) : [];
                if ($candidateBlockSize === null || $candidateBlockSize < 1 || $candidateBlockSize % 4 !== 0 || strlen($fileIdsBytes) % 16 !== 0 || $recoverableFileCount > count($fileIds) || count(array_unique($fileIds)) !== count($fileIds)) {
                    $invalidPacketCount++;
                    $offset += (int) $packetLength;

                    continue;
                }
                $blockSize = $candidateBlockSize;
                if (! $mainAlreadyRetained) {
                    $retainedMainFileIdCount += count($fileIds);
                }
                $blockSizesBySet[$setId] ??= $candidateBlockSize;
                $main = [
                    'recoverable_file_count' => $recoverableFileCount,
                    'recoverable_file_ids' => array_slice($fileIds, 0, $recoverableFileCount),
                    'non_recoverable_file_ids' => array_slice($fileIds, $recoverableFileCount),
                    'all_file_ids' => $fileIds,
                ];
                $mainsBySet[$setId] ??= $main;
            } elseif ($type === "PAR 2.0\0FileDesc" && strlen($payload) >= 57) {
                $setId = bin2hex(substr($packet, 32, 16));
                $fileSize = self::unsigned64(substr($payload, 48, 8));
                if ($fileSize === null) {
                    $unsupportedIntegerRangeCount++;
                    $invalidPacketCount++;
                }
                $filenameField = substr($payload, 56);
                $terminator = strpos($filenameField, "\0");
                $filename = $terminator === false ? $filenameField : substr($filenameField, 0, $terminator);
                $padding = $terminator === false ? '' : substr($filenameField, $terminator);
                $filenameLength = strlen($filename);
                $filenamePrefix = substr($filename, 0, 128);
                $filenameAsciiValid = preg_match('/[\x80-\xff]/', $filename) !== 1;
                $expectedPaddingBytes = (4 - ($filenameLength % 4)) % 4;
                $filenamePaddingValid = strlen($filenameField) % 4 === 0
                    && strlen($padding) === $expectedPaddingBytes
                    && strspn($padding, "\0") === strlen($padding);
                $filenameBytesNonempty = $filename !== '';
                if (! $filenameAsciiValid || ! $filenamePaddingValid) {
                    $invalidPacketCount++;
                }
                $fileIdDerivationValid = $filenameBytesNonempty && $filenameAsciiValid && $filenamePaddingValid;
                $derivedFileIdBytes = md5(substr($payload, 32, 16).substr($payload, 48, 8).$filename, true);
                $advertisedFileIdBytes = substr($payload, 0, 16);
                $md5 = bin2hex(substr($payload, 16, 16));
                $md5First16k = bin2hex(substr($payload, 32, 16));
                $source = [
                    'recovery_set_id' => $setId,
                    'filename' => $filename,
                    'filename_bytes_length' => $filenameLength,
                    'filename_bytes_nonempty' => $filenameBytesNonempty,
                    'filename_bytes_sha256' => hash('sha256', $filename),
                    'filename_field_sha256' => hash('sha256', $filenameField),
                    'filename_bytes_prefix_hex' => bin2hex($filenamePrefix),
                    'filename_bytes_prefix_truncated' => $filenameLength > strlen($filenamePrefix),
                    'filename_ascii_valid' => $filenameAsciiValid,
                    'filename_padding_bytes' => strlen($padding),
                    'filename_padding_valid' => $filenamePaddingValid,
                    'file_length' => $fileSize,
                    'file_length_raw_little_endian_hex' => bin2hex(substr($payload, 48, 8)),
                    'file_length_integer_range_valid' => $fileSize !== null,
                    'md5' => $md5,
                    'md5_first_16k' => $md5First16k,
                    'short_file_hashes_consistent' => is_int($fileSize) && $fileSize <= 16_384
                        ? hash_equals($md5, $md5First16k) : null,
                    'file_id' => bin2hex($advertisedFileIdBytes),
                    'derived_file_id' => bin2hex($derivedFileIdBytes),
                    'file_id_derivation_valid' => $fileIdDerivationValid,
                    'file_id_matches_derived' => $fileIdDerivationValid
                        ? hash_equals($advertisedFileIdBytes, $derivedFileIdBytes) : null,
                ];
                $sources[$source['file_id']] ??= $source;
                $sourcesBySet[$setId][$source['file_id']] ??= $source;
            } elseif ($type === "PAR 2.0\0IFSC") {
                $setId = bin2hex(substr($packet, 32, 16));
                if (strlen($payload) < 16 || (strlen($payload) - 16) % 20 !== 0) {
                    $invalidPacketCount++;
                } else {
                    $fileId = bin2hex(substr($payload, 0, 16));
                    $records = substr($payload, 16);
                    $preview = substr($records, 0, intdiv(min(strlen($records), 4096), 20) * 20);
                    $candidate = [
                        'slice_count' => intdiv(strlen($records), 20),
                        'checksum_records_sha256' => hash('sha256', $records),
                        'checksum_records_preview_hex' => bin2hex($preview),
                        'preview_slice_count' => intdiv(strlen($preview), 20),
                        'duplicate_conflict' => false,
                    ];
                    if (isset($ifscBySetAndFile[$setId][$fileId])) {
                        if ($ifscBySetAndFile[$setId][$fileId]['checksum_records_sha256'] !== $candidate['checksum_records_sha256']) {
                            $ifscBySetAndFile[$setId][$fileId]['duplicate_conflict'] = true;
                            $invalidPacketCount++;
                        }
                    } else {
                        $ifscBySetAndFile[$setId][$fileId] = $candidate;
                    }
                }
                $optionalPackets[$type][] = [
                    'payload_bytes' => strlen($payload),
                    'payload_sha256' => hash('sha256', $payload),
                    'payload_hex' => bin2hex(substr($payload, 0, 256)),
                ];
            } elseif ($type === "PAR 2.0\0Main" || $type === "PAR 2.0\0FileDesc") {
                $invalidPacketCount++;
            } elseif ($type === "PAR 2.0\0RecvSlic" && strlen($payload) >= 4) {
                $recoveryPackets[] = [
                    'recovery_set_id' => bin2hex(substr($packet, 32, 16)),
                    'exponent' => (int) (unpack('V', substr($payload, 0, 4))[1] ?? 0),
                    'payload_bytes' => strlen($payload),
                    'slice_sha256' => hash('sha256', substr($payload, 4)),
                    'packet_offset' => $offset,
                ];
            } elseif ($type === "PAR 2.0\0Creator") {
                $creator = trim(strtok($payload, "\0") ?: $payload);
            } elseif (in_array($type, ["PAR 2.0\0UniFileN", "PAR 2.0\0CommASCI", "PAR 2.0\0CommUni", "PAR 2.0\0FileSlic", "PAR 2.0\0RFSC", "PAR 2.0\0PkdMain", "PAR 2.0\0PkdRecvS"], true)) {
                if ($type === "PAR 2.0\0UniFileN" && strlen($payload) < 20) {
                    $invalidPacketCount++;
                }
                $optionalPackets[$type][] = [
                    'payload_bytes' => strlen($payload),
                    'payload_sha256' => hash('sha256', $payload),
                    'payload_hex' => bin2hex(substr($payload, 0, 256)),
                ];
            }
            $offset += (int) $packetLength;
        }

        $recoverySetIds = array_values(array_unique(array_column(array_filter(
            $packetSummaries,
            static fn (array $packet): bool => $packet['checksum_valid'] === true,
        ), 'set_id')));
        $inputFileSliceChecksums = [];
        foreach ($ifscBySetAndFile as $setId => $fileChecksums) {
            foreach ($fileChecksums as $fileId => $ifsc) {
                $source = $sourcesBySet[$setId][$fileId] ?? null;
                $setBlockSize = $blockSizesBySet[$setId] ?? null;
                $expectedSliceCount = is_array($source) && is_int($source['file_length'] ?? null)
                    && is_int($setBlockSize)
                    ? intdiv($source['file_length'], $setBlockSize) + (($source['file_length'] % $setBlockSize) === 0 ? 0 : 1) : null;
                $inputFileSliceChecksums[] = $ifsc + [
                    'recovery_set_id' => $setId,
                    'file_id' => $fileId,
                    'preview_truncated' => $ifsc['preview_slice_count'] < $ifsc['slice_count'],
                    'source_file_present' => is_array($source),
                    'expected_slice_count_from_file_length' => $expectedSliceCount,
                    'slice_count_matches_file_length' => $expectedSliceCount === null ? null : $expectedSliceCount === $ifsc['slice_count'],
                ];
            }
        }
        $recoveryHashesBySetAndExponent = [];
        $conflictingRecoveryExponentsBySet = [];
        $conflictingRecoveryExponents = [];
        $ifscSliceCountMismatchesBySet = [];
        foreach ($inputFileSliceChecksums as $ifsc) {
            if (($ifsc['slice_count_matches_file_length'] ?? null) === false) {
                $setId = $ifsc['recovery_set_id'];
                $ifscSliceCountMismatchesBySet[$setId] = ($ifscSliceCountMismatchesBySet[$setId] ?? 0) + 1;
                $invalidPacketCount++;
            }
        }
        foreach ($recoveryPackets as $packet) {
            $setId = $packet['recovery_set_id'];
            $exponent = $packet['exponent'];
            $payloadLength = $packet['payload_bytes'];
            $recoveryDataHash = $packet['slice_sha256'];
            $recoveryExponentsObservedBySet[$setId][$exponent] = true;
            if ($exponent > 65535) {
                $invalidPacketCount++;

                continue;
            }
            $exponentIdentity = $exponent === 65535 ? 0 : $exponent;
            $setBlockSize = $blockSizesBySet[$setId] ?? null;
            if (! is_int($setBlockSize)) {
                $recoverySliceLengthsUnvalidatedBySet[$setId] = ($recoverySliceLengthsUnvalidatedBySet[$setId] ?? 0) + 1;
            } elseif ($setBlockSize > PHP_INT_MAX - 4) {
                $unsupportedIntegerRangeCount++;
                $unsupportedIntegerSets[$setId] = true;
                $invalidPacketCount++;

                continue;
            } elseif ($payloadLength !== $setBlockSize + 4) {
                $invalidPacketCount++;

                continue;
            }
            $previous = $recoveryHashesBySetAndExponent[$setId][$exponentIdentity] ?? null;
            if (is_array($previous)) {
                if ($previous['slice_sha256'] !== $recoveryDataHash
                    && ! isset($recoverySliceConflictsBySet[$setId][$exponentIdentity])) {
                    $recoverySliceConflictsBySet[$setId][$exponentIdentity] = true;
                    $conflictingRecoveryExponentsBySet[$setId][$exponentIdentity] = true;
                    $conflictingRecoveryExponents[] = [
                        'recovery_set_id' => $setId,
                        'exponent' => $exponentIdentity,
                        'first_packet_offset' => $previous['packet_offset'],
                        'conflicting_packet_offset' => $packet['packet_offset'],
                        'first_slice_sha256' => $previous['slice_sha256'],
                        'conflicting_slice_sha256' => $recoveryDataHash,
                    ];
                    $invalidPacketCount++;
                }
            } else {
                $recoveryHashesBySetAndExponent[$setId][$exponentIdentity] = [
                    'slice_sha256' => $recoveryDataHash,
                    'packet_offset' => $packet['packet_offset'],
                ];
            }
            $recoveryExponentsBySet[$setId][$exponentIdentity] = true;
        }
        $recoveryPacketsForOutput = array_values(array_filter(
            $recoveryPackets,
            static function (array $packet) use ($blockSizesBySet): bool {
                $setBlockSize = $blockSizesBySet[$packet['recovery_set_id']] ?? null;

                return $packet['exponent'] <= 65535 && (! is_int($setBlockSize)
                    || ($setBlockSize <= PHP_INT_MAX - 4 && $packet['payload_bytes'] === $setBlockSize + 4));
            },
        ));
        $recoverySets = [];
        foreach ($recoverySetIds as $setId) {
            $setSources = $sourcesBySet[$setId] ?? [];
            $setMain = $mainsBySet[$setId] ?? null;
            $setMainFileIds = $setMain['all_file_ids'] ?? [];
            $setDescribedFileIds = array_keys($setSources);
            $setMissingFileIds = array_values(array_diff($setMainFileIds, $setDescribedFileIds));
            $setUnexpectedFileIds = array_values(array_diff($setDescribedFileIds, $setMainFileIds));
            $setBlockSize = $blockSizesBySet[$setId] ?? null;
            $setRecoverableIds = $setMain['recoverable_file_ids'] ?? [];
            $setDefinitionConflicts = array_values(array_filter(
                $sourceDefinitionConflicts,
                static fn (array $conflict): bool => $conflict['recovery_set_id'] === $setId,
            ));
            $mainDefinitionConflict = count(array_filter(
                $setDefinitionConflicts,
                static fn (array $conflict): bool => $conflict['definition_kind'] === 'main',
            )) > 0;
            $conflictingFileDescriptionIds = array_values(array_unique(array_filter(
                array_column(array_filter(
                    $setDefinitionConflicts,
                    static fn (array $conflict): bool => $conflict['definition_kind'] === 'file_description',
                ), 'file_id'),
                'is_string',
            )));
            sort($conflictingFileDescriptionIds);
            $invalidFileDescriptionIds = [];
            $fileIdsByFilename = [];
            foreach ($setSources as $fileId => $source) {
                if ($source['short_file_hashes_consistent'] === false
                    || ! $source['filename_ascii_valid'] || ! $source['filename_padding_valid']) {
                    $invalidFileDescriptionIds[] = $fileId;
                }
                $filenameKey = $source['filename_bytes_length'].':'.$source['filename_bytes_sha256'];
                $fileIdsByFilename[$filenameKey][] = $fileId;
            }
            $duplicateFilenameFileIds = [];
            foreach ($fileIdsByFilename as $fileIds) {
                if (count($fileIds) > 1) {
                    array_push($duplicateFilenameFileIds, ...$fileIds);
                }
            }
            sort($invalidFileDescriptionIds);
            sort($duplicateFilenameFileIds);
            $sourceDescriptionsValid = $conflictingFileDescriptionIds === []
                && $invalidFileDescriptionIds === [] && $duplicateFilenameFileIds === [];
            $setInventoryComplete = $setMain !== null && $setMissingFileIds === [] && $setUnexpectedFileIds === []
                && ! $mainDefinitionConflict && $sourceDescriptionsValid;
            $setSourceBlockCount = 0;
            $setSourceBlockCountOverflow = false;
            $setSourceFileBytesOverflow = false;
            $setSourceBytes = 0;
            $setIntegerRangeValid = ! isset($unsupportedIntegerSets[$setId]);
            foreach ($setSources as $fileId => $source) {
                $fileLength = $source['file_length'] ?? null;
                $previousBytes = $setSourceBytes;
                $setSourceBytes = self::checkedAdd($setSourceBytes, $fileLength);
                if ($previousBytes !== null && $setSourceBytes === null && $fileLength !== null) {
                    $setSourceFileBytesOverflow = true;
                    $unsupportedIntegerRangeCount++;
                    $invalidPacketCount++;
                }
                $setIntegerRangeValid = $setIntegerRangeValid && $fileLength !== null && $setSourceBytes !== null;
                if (in_array($fileId, $setRecoverableIds, true) && is_int($setBlockSize)) {
                    $fileBlocks = $fileLength === null ? null
                        : intdiv($fileLength, $setBlockSize) + (($fileLength % $setBlockSize) === 0 ? 0 : 1);
                    $previousBlocks = $setSourceBlockCount;
                    $setSourceBlockCount = self::checkedAdd($setSourceBlockCount, $fileBlocks);
                    if ($previousBlocks !== null && $setSourceBlockCount === null && $fileBlocks !== null) {
                        $setSourceBlockCountOverflow = true;
                        $unsupportedIntegerRangeCount++;
                        $invalidPacketCount++;
                    }
                    $setIntegerRangeValid = $setIntegerRangeValid && $setSourceBlockCount !== null;
                }
            }
            if (! $setInventoryComplete || $setSourceBlockCount === 0) {
                $setSourceBlockCount = null;
            }
            $setIfsc = array_values(array_filter($inputFileSliceChecksums, static fn (array $ifsc): bool => $ifsc['recovery_set_id'] === $setId));
            $setIfscRelationshipsValid = count(array_filter(
                $setIfsc,
                static fn (array $ifsc): bool => ($ifsc['duplicate_conflict'] ?? false) === true
                    || ($ifsc['slice_count_matches_file_length'] ?? null) === false
                    || ($setMain !== null && ! in_array($ifsc['file_id'] ?? null, $setMainFileIds, true)),
            )) === 0;
            $recoverySets[] = [
                'recovery_set_id' => $setId,
                'source_block_size' => $setBlockSize,
                'source_block_count' => $setSourceBlockCount,
                'source_block_limit' => self::MAX_SOURCE_BLOCKS,
                'source_block_count_within_current_v2_limit' => $setSourceBlockCount === null ? null : $setSourceBlockCount <= self::MAX_SOURCE_BLOCKS,
                'main_packet' => $setMain,
                'source_inventory_complete' => $setInventoryComplete,
                'missing_file_description_ids' => $setMissingFileIds,
                'unexpected_file_description_ids' => $setUnexpectedFileIds,
                'source_file_count' => count($setSources),
                'source_file_bytes' => $setSourceBytes,
                'integer_range_valid' => $setIntegerRangeValid,
                'source_block_count_overflow' => $setSourceBlockCountOverflow,
                'ifsc_relationships_valid' => $setIfscRelationshipsValid,
                'source_descriptions_valid' => $sourceDescriptionsValid,
                'invalid_file_description_ids' => $invalidFileDescriptionIds,
                'duplicate_filename_file_ids' => $duplicateFilenameFileIds,
                'main_definition_conflict' => $mainDefinitionConflict,
                'conflicting_file_description_ids' => $conflictingFileDescriptionIds,
                'source_definition_conflicts' => $setDefinitionConflicts,
                'source_file_bytes_overflow' => $setSourceFileBytesOverflow,
                'source_files' => array_values($setSources),
                'input_file_slice_checksums' => $setIfsc,
                'ifsc_slice_count_mismatch_count' => $ifscSliceCountMismatchesBySet[$setId] ?? 0,
                'unique_recovery_slice_count' => count($recoveryExponentsBySet[$setId] ?? []),
                'recovery_exponents' => array_map('intval', array_keys($recoveryExponentsBySet[$setId] ?? [])),
                'recovery_exponents_observed' => array_map('intval', array_keys($recoveryExponentsObservedBySet[$setId] ?? [])),
                'recovery_slice_conflict_count' => count($recoverySliceConflictsBySet[$setId] ?? []),
                'recovery_slice_length_unvalidated_count' => $recoverySliceLengthsUnvalidatedBySet[$setId] ?? 0,
                'conflicting_recovery_exponents' => array_map('intval', array_keys($conflictingRecoveryExponentsBySet[$setId] ?? [])),
            ];
        }
        $singleSet = count($recoverySets) === 1 ? $recoverySets[0] : null;
        $blockSize = $singleSet['source_block_size'] ?? null;
        $main = $singleSet['main_packet'] ?? null;
        $sourceBlockCount = $singleSet['source_block_count'] ?? null;
        $sourceInventoryComplete = ($singleSet['source_inventory_complete'] ?? false) === true;
        $ifscRelationshipsValid = count(array_filter(
            $recoverySets,
            static fn (array $set): bool => $set['ifsc_relationships_valid'] !== true,
        )) === 0;
        $missingFileDescriptionIds = $singleSet['missing_file_description_ids'] ?? [];
        $unexpectedFileDescriptionIds = $singleSet['unexpected_file_description_ids'] ?? [];
        $sourceBytes = 0;
        foreach ($recoverySets as $set) {
            $previousBytes = $sourceBytes;
            $sourceBytes = self::checkedAdd($sourceBytes, $set['source_file_bytes']);
            if ($previousBytes !== null && $sourceBytes === null && $set['source_file_bytes'] !== null) {
                $unsupportedIntegerRangeCount++;
                $invalidPacketCount++;
            }
        }
        $recoveryCount = $recoverySets === [] ? null : array_sum(array_column($recoverySets, 'unique_recovery_slice_count'));
        $recoverySliceConflictCount = array_sum(array_map('count', $recoverySliceConflictsBySet));
        $recoverySliceLengthUnvalidatedCount = array_sum($recoverySliceLengthsUnvalidatedBySet);
        $sourceBytesOverflow = $sourceBytes === null;
        $hasConflictingRecoveryExponents = $conflictingRecoveryExponents !== [];
        $mainDefinitionConflict = count(array_filter(
            $sourceDefinitionConflicts,
            static fn (array $conflict): bool => $conflict['definition_kind'] === 'main',
        )) > 0;
        $conflictingFileDescriptionIds = array_values(array_unique(array_filter(
            array_column(array_filter(
                $sourceDefinitionConflicts,
                static fn (array $conflict): bool => $conflict['definition_kind'] === 'file_description',
            ), 'file_id'),
            'is_string',
        )));
        sort($conflictingFileDescriptionIds);
        $flatSources = array_reduce(
            $recoverySets,
            static fn (array $files, array $set): array => array_merge($files, $set['source_files']),
            [],
        );
        $packedLayoutPresent = isset($optionalPackets["PAR 2.0\0PkdMain"]) || isset($optionalPackets["PAR 2.0\0PkdRecvS"]);
        $sourceBlockCountWithinLimit = $sourceBlockCount === null ? null : $sourceBlockCount <= self::MAX_SOURCE_BLOCKS;
        $recoveryPercent = is_int($recoveryCount) && is_int($sourceBlockCount) && $sourceBlockCount > 0
            && $main !== null && $sourceInventoryComplete && $ifscRelationshipsValid
            && ! $hasConflictingRecoveryExponents && $invalidPacketCount === 0
            && $sourceBlockCountWithinLimit === true && ! $sourceBytesOverflow
            && ! ($packetCount >= $maximumPackets && $offset < $length) && ! $packedLayoutPresent
            ? round($recoveryCount / $sourceBlockCount * 100, 3) : null;
        $recoveryPercentageBasis = $mainDefinitionConflict || $conflictingFileDescriptionIds !== []
            ? 'unavailable-conflicting-source-definition-packets'
            : ($hasConflictingRecoveryExponents
                ? 'unavailable-conflicting-recovery-exponent-packets'
                : (! $ifscRelationshipsValid
                ? 'unavailable-invalid-input-file-slice-checksums'
                : ($packedLayoutPresent
                ? 'unavailable-packed-recovery-layout'
                : ($blockSize === null && $recoveryCount > 0
                    ? 'unavailable-source-block-size-not-in-this-volume'
                    : 'unique-valid-recovery-exponents-over-recoverable-source-blocks'))));
        $hasSourceOrRecoveryConflicts = $mainDefinitionConflict || $conflictingFileDescriptionIds !== [] || $hasConflictingRecoveryExponents;
        if ($sourceBlockCountWithinLimit === false && ! $packedLayoutPresent && ! $hasSourceOrRecoveryConflicts) {
            $recoveryPercentageBasis = 'unavailable-source-block-count-exceeds-current-v2-limit';
        }
        if ($recoveryPercentageBasis === 'unique-valid-recovery-exponents-over-recoverable-source-blocks'
            && (! $sourceInventoryComplete || ! is_int($sourceBlockCount) || $sourceBlockCount <= 0)) {
            $recoveryPercentageBasis = 'unavailable-source-block-denominator';
        }
        if ($sourceBytesOverflow && ! $hasSourceOrRecoveryConflicts) {
            $recoveryPercentageBasis = 'unavailable-source-file-byte-total-overflow';
        } elseif (($singleSet['source_block_count_overflow'] ?? false) === true && ! $hasSourceOrRecoveryConflicts) {
            $recoveryPercentageBasis = 'unavailable-source-block-count-overflow';
        } elseif ($recoveryPercent === null
            && $recoveryPercentageBasis === 'unique-valid-recovery-exponents-over-recoverable-source-blocks') {
            $recoveryPercentageBasis = 'unavailable-invalid-or-incomplete-packets';
        }

        return [
            'parser_engine' => 'php-fallback',
            'creator' => $creator,
            'packet_count' => $packetCount,
            'packet_limit_reached' => $packetCount >= $maximumPackets && $offset < $length,
            'trailing_unparsed_bytes' => max(0, $length - $offset),
            'unparsed_bytes' => $unparsedBytes,
            'invalid_packet_count' => $invalidPacketCount,
            'unsupported_integer_range_count' => $unsupportedIntegerRangeCount,
            'unsupported_integer_range_observations' => $unsupportedIntegerRangeObservations,
            'integer_range_valid' => $unsupportedIntegerRangeCount === 0,
            'source_block_size' => $blockSize,
            'source_block_count' => $sourceBlockCount ?: null,
            'source_block_limit' => self::MAX_SOURCE_BLOCKS,
            'source_block_count_within_current_v2_limit' => $sourceBlockCountWithinLimit,
            'source_block_count_overflow' => ($singleSet['source_block_count_overflow'] ?? false) === true,
            'main_packet' => $main,
            'main_file_id_limit' => self::MAX_MAIN_FILE_IDS,
            'retained_main_file_id_count' => $retainedMainFileIdCount,
            'main_file_id_limit_reached' => $mainFileIdLimitObservations !== [],
            'main_file_id_limit_observations' => $mainFileIdLimitObservations,
            'source_inventory_complete' => $sourceInventoryComplete,
            'ifsc_relationships_valid' => $ifscRelationshipsValid,
            'source_descriptions_valid' => ($singleSet['source_descriptions_valid'] ?? false) === true,
            'invalid_file_description_ids' => $singleSet['invalid_file_description_ids'] ?? [],
            'duplicate_filename_file_ids' => $singleSet['duplicate_filename_file_ids'] ?? [],
            'main_definition_conflict' => $mainDefinitionConflict,
            'conflicting_file_description_ids' => $conflictingFileDescriptionIds,
            'source_definition_conflicts' => $sourceDefinitionConflicts,
            'missing_file_description_ids' => $missingFileDescriptionIds,
            'unexpected_file_description_ids' => $unexpectedFileDescriptionIds,
            'source_file_count' => count($flatSources),
            'source_file_bytes' => $sourceBytesOverflow ? null : $sourceBytes,
            'source_file_bytes_overflow' => $sourceBytesOverflow,
            'source_files' => $flatSources,
            'input_file_slice_checksums' => $inputFileSliceChecksums,
            'ifsc_slice_count_mismatch_count' => array_sum($ifscSliceCountMismatchesBySet),
            'recovery_set_ids' => $recoverySetIds,
            'recovery_sets' => $recoverySets,
            'unique_recovery_slice_count' => $recoveryCount,
            'recovery_exponents' => $singleSet === null ? null : $singleSet['recovery_exponents'],
            'recovery_slice_conflict_count' => $recoverySliceConflictCount,
            'recovery_slice_length_unvalidated_count' => $recoverySliceLengthUnvalidatedCount,
            'recovery_to_source_percent' => $recoveryPercent,
            'packed_layout_present' => $packedLayoutPresent,
            'recovery_percentage_basis' => $recoveryPercentageBasis,
            'conflicting_recovery_exponents' => $conflictingRecoveryExponents,
            'recovery_packets' => $recoveryPacketsForOutput,
            'optional_packet_summaries' => $optionalPackets,
            'packet_summaries' => $packetSummaries,
            'packet_type_counts' => array_map('intval', $packetTypeCounts),
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private static function normalizeNativeIntegerRanges(array $metadata): array
    {
        $unsupportedRangeCount = 0;
        self::normalizeIntegerRangeValues($metadata, $unsupportedRangeCount);
        $metadata['unsupported_integer_range_count'] = (int) ($metadata['unsupported_integer_range_count'] ?? 0) + $unsupportedRangeCount;
        if ($unsupportedRangeCount > 0) {
            $metadata['invalid_packet_count'] = (int) ($metadata['invalid_packet_count'] ?? 0) + $unsupportedRangeCount;
            $metadata['integer_range_valid'] = false;
            $metadata['recovery_to_source_percent'] = null;
            $metadata['recovery_percentage_basis'] = 'unavailable-unsupported-integer-range';
        }
        // Enforce the format dimension independently of the native library's
        // reported validity flags, including libraries predating this field.
        $metadata['source_block_limit'] = self::MAX_SOURCE_BLOCKS;
        $count = $metadata['source_block_count'] ?? null;
        $metadata['source_block_count_within_current_v2_limit'] = is_int($count) && $count > 0
            ? $count <= self::MAX_SOURCE_BLOCKS : null;
        foreach ($metadata['recovery_sets'] ?? [] as $index => $set) {
            if (! is_array($set)) {
                continue;
            }
            $count = $set['source_block_count'] ?? null;
            $metadata['recovery_sets'][$index]['source_block_limit'] = self::MAX_SOURCE_BLOCKS;
            $metadata['recovery_sets'][$index]['source_block_count_within_current_v2_limit'] = is_int($count) && $count > 0
                ? $count <= self::MAX_SOURCE_BLOCKS : null;
        }
        $hasConflictBasis = in_array($metadata['recovery_percentage_basis'] ?? null, [
            'unavailable-conflicting-source-definition-packets',
            'unavailable-conflicting-recovery-exponent-packets',
        ], true);
        if ($metadata['source_block_count_within_current_v2_limit'] === false) {
            $metadata['recovery_to_source_percent'] = null;
            if ($unsupportedRangeCount === 0 && ($metadata['packed_layout_present'] ?? false) !== true && ! $hasConflictBasis) {
                $metadata['recovery_percentage_basis'] = 'unavailable-source-block-count-exceeds-current-v2-limit';
            }
        }
        if (($metadata['recovery_percentage_basis'] ?? null) === 'unique-valid-recovery-exponents-over-recoverable-source-blocks'
            && (($metadata['source_inventory_complete'] ?? false) !== true
                || ! is_int($metadata['source_block_count'] ?? null) || $metadata['source_block_count'] <= 0)) {
            $metadata['recovery_percentage_basis'] = 'unavailable-source-block-denominator';
            $metadata['recovery_to_source_percent'] = null;
        }
        if (($metadata['source_file_bytes_overflow'] ?? false) === true) {
            $metadata['recovery_to_source_percent'] = null;
            if ($unsupportedRangeCount === 0 && ! $hasConflictBasis) {
                $metadata['recovery_percentage_basis'] = 'unavailable-source-file-byte-total-overflow';
            }
        } elseif (($metadata['recovery_to_source_percent'] ?? null) === null
            && ($metadata['recovery_percentage_basis'] ?? null) === 'unique-valid-recovery-exponents-over-recoverable-source-blocks') {
            $metadata['recovery_percentage_basis'] = 'unavailable-invalid-or-incomplete-packets';
        }

        return $metadata;
    }

    /** @param array<array-key, mixed> $values */
    private static function normalizeIntegerRangeValues(array &$values, int &$unsupportedRangeCount): void
    {
        $integerFields = [
            'source_block_size', 'source_block_count', 'source_file_bytes', 'file_length',
            'expected_slice_count_from_file_length', 'source_byte_start', 'source_byte_end_exclusive',
            'source_bytes_in_slice', 'zero_padding_bytes',
            'ifsc_record_index', 'source_slice_checksum_bytes',
        ];
        $initialUnsupportedRangeCount = $unsupportedRangeCount;
        foreach ($values as $key => &$value) {
            if (is_array($value)) {
                self::normalizeIntegerRangeValues($value, $unsupportedRangeCount);
            } elseif (in_array($key, ['input_slice_index', 'logical_file_offset', 'logical_byte_length'], true) && $value !== null) {
                // This optional unsigned observation is not a global validity constraint.
                // Keep JSON_BIGINT_AS_STRING's exact decimal value for public evidence.
                $values[$key.'_integer_range_valid'] = is_int($value);
            } elseif (in_array($key, $integerFields, true) && $value !== null && ! is_int($value)) {
                $value = null;
                $unsupportedRangeCount++;
                if ($key === 'file_length') {
                    $values['file_length_integer_range_valid'] = false;
                } elseif ($key === 'source_file_bytes') {
                    $values['source_file_bytes_overflow'] = true;
                } elseif ($key === 'source_block_count') {
                    $values['source_block_count_overflow'] = true;
                }
            }
        }
        unset($value);
        if ($unsupportedRangeCount > $initialUnsupportedRangeCount && array_key_exists('integer_range_valid', $values)) {
            $values['integer_range_valid'] = false;
        }
    }

    private static function checkedAdd(?int $left, ?int $right): ?int
    {
        if ($left === null || $right === null || $left < 0 || $right < 0 || $left > PHP_INT_MAX - $right) {
            return null;
        }

        return $left + $right;
    }

    private static function unsigned64(string $bytes): ?int
    {
        if (strlen($bytes) !== 8) {
            return null;
        }
        $parts = unpack('Vlow/Vhigh', $bytes);
        $low = (int) ($parts['low'] ?? 0);
        $high = (int) ($parts['high'] ?? 0);
        if (PHP_INT_SIZE < 8) {
            return $high === 0 && $low >= 0 ? $low : null;
        }
        if ($high > 0x7FFF_FFFF) {
            return null;
        }

        return $low + ($high * 4_294_967_296);
    }

    /**
     * @return list<array{
     *     kind: string,
     *     offset: int,
     *     byte_length: int,
     *     record_count?: int,
     *     record_byte_length?: int,
     * }>
     */
    private static function packetPhysicalRegions(
        int $packetOffset,
        string $type,
        string $payload,
    ): array {
        $payloadOffset = $packetOffset + 64;
        $regions = [
            ['kind' => 'packet_header', 'offset' => $packetOffset, 'byte_length' => 64],
            ['kind' => 'packet_payload', 'offset' => $payloadOffset, 'byte_length' => strlen($payload)],
        ];

        if ($type === "PAR 2.0\0IFSC" && strlen($payload) >= 16) {
            $recordsOffset = $payloadOffset + 16;
            $recordsBytes = strlen($payload) - 16;
            $recordCount = intdiv($recordsBytes, 20);
            $regions[] = [
                'kind' => 'ifsc_file_id',
                'offset' => $payloadOffset,
                'byte_length' => 16,
            ];
            $regions[] = [
                'kind' => 'ifsc_records',
                'offset' => $recordsOffset,
                'byte_length' => $recordCount * 20,
                'record_count' => $recordCount,
                'record_byte_length' => 20,
            ];
            $trailingBytes = $recordsBytes % 20;
            if ($trailingBytes > 0) {
                $regions[] = [
                    'kind' => 'ifsc_trailing_bytes',
                    'offset' => $recordsOffset + $recordCount * 20,
                    'byte_length' => $trailingBytes,
                ];
            }
        } elseif ($type === "PAR 2.0\0RecvSlic" && strlen($payload) >= 4) {
            $regions[] = [
                'kind' => 'recovery_exponent',
                'offset' => $payloadOffset,
                'byte_length' => 4,
            ];
            $regions[] = [
                'kind' => 'recovery_data',
                'offset' => $payloadOffset + 4,
                'byte_length' => strlen($payload) - 4,
            ];
        } elseif ($type === "PAR 2.0\0FileSlic") {
            $fileIdBytes = min(strlen($payload), 16);
            if ($fileIdBytes > 0) {
                $regions[] = [
                    'kind' => $fileIdBytes === 16 ? 'fileslic_file_id' : 'fileslic_truncated_file_id',
                    'offset' => $payloadOffset,
                    'byte_length' => $fileIdBytes,
                ];
            }
            $indexBytes = min(max(strlen($payload) - 16, 0), 8);
            if ($indexBytes > 0) {
                $regions[] = [
                    'kind' => $indexBytes === 8 ? 'fileslic_input_slice_index' : 'fileslic_truncated_input_slice_index',
                    'offset' => $payloadOffset + 16,
                    'byte_length' => $indexBytes,
                ];
            }
            if (strlen($payload) > 24) {
                $regions[] = [
                    'kind' => 'fileslic_physical_slice_field',
                    'offset' => $payloadOffset + 24,
                    'byte_length' => strlen($payload) - 24,
                ];
            }
        }

        return $regions;
    }
}
