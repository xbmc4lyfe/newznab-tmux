<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Services\Releases\Fingerprint\NativeMetadataParser;
use InvalidArgumentException;
use RuntimeException;

/**
 * Offline bounded references for shared projections only, not full-parser PHP
 * equivalents or production fallback implementations. Native work deliberately
 * includes the entire richer parseFormats FFI/JSON result before projection.
 *
 * Sources: ECMA-119 sixth edition, 7.7.2 (repeated PVD recordings),
 * https://ecma-international.org/publications-and-standards/standards/ecma-119/;
 * https://www.rarlab.com/technote.htm#qopen (RAR5 framing/backward positions);
 * UnRAR 7.3.1 extract.cpp/hash.cpp (file-copy entry empty-data hash domain).
 * These fixtures prove no ISO conformance, target-data validity, extraction
 * success, archive authentication, whole-archive validity or uploader identity.
 */
final class RemainingArchiveBenchmarkFixtures
{
    public const SCOPE = 'bounded independent PHP reference versus shared metadata projection; no full-parser equivalence or production PHP speedup';

    private const EMPTY_BLAKE = 'dd0e891776933f43c7d032b08a917e25741f8aa9a12c12e1cac8801500f2ca4f';

    private const COPY_FIELDS = ['status', 'source_header_offset', 'source_crc32_byte_offset', 'source_record_offset', 'source_record_end_offset', 'source_hash_record_index', 'source_hash_record_offset', 'source_hash_record_end_offset', 'source_hash_digest_offset', 'redirection_type_observed', 'reference_data_domain', 'reference_empty_crc32', 'reference_empty_blake2sp_hex', 'crc32_comparison_status', 'stored_crc32_matches_empty_data', 'blake2sp_comparison_status', 'stored_blake2sp_matches_empty_data', 'reference_selected_checksum', 'reference_selection_status', 'target_resolution_performed'];

    private const ISO_FIELDS = ['status', 'image_origin_known', 'whole_image_validated', 'sequence_status', 'sampled_descriptor_sequence_terminated', 'reference_descriptor_offset', 'observed_descriptor_count', 'repeat_comparison_count', 'matching_reference_count', 'differing_reference_count', 'all_observed_copies_equal', 'first_difference', 'detail_limit', 'details_omitted_count', 'copies'];

    private const QUICK_FIELDS = ['status', 'work_byte_limit', 'scanned_record_limit', 'retained_record_limit', 'record_count', 'declared_data_fully_scanned', 'observations_omitted', 'observed_original_positions_strictly_increasing', 'cache_overlap_comparison_status', 'stopped_offset', 'service_data_crc32_status', 'records'];

    private const RECORD_FIELDS = ['index', 'structure_start_offset', 'structure_end_offset', 'structure_crc32_valid', 'structure_sha256', 'backward_offset', 'declared_cached_data_size', 'cached_data_sha256', 'original_start_offset', 'original_end_offset', 'original_data_sha256', 'comparison_status', 'cached_bytes_match_original'];

    /** @return array<string,array{module:string,category:string,comparison_scope:string}> */
    public static function strata(): array
    {
        $result = [];
        foreach (['iso_primary_copies' => ['iso-copies-equal', 'iso-copies-different', 'iso-copies-uncommon-limit', 'iso-copies-uncommon-partial'],
            'rar5_copy_checksums' => ['rar-copy-crc', 'rar-copy-blake', 'rar-copy-uncommon-ambiguous', 'rar-copy-uncommon-truncated'],
            'rar5_quick_open' => ['rar-quick-open-match', 'rar-quick-open-conflict', 'rar-quick-open-uncommon-retention', 'rar-quick-open-uncommon-corrupt']] as $module => $names) {
            foreach ($names as $index => $name) {
                $result[$name] = ['module' => $module, 'category' => $index < 2 ? 'common' : 'uncommon', 'comparison_scope' => self::SCOPE];
            }
        }

        return $result;
    }

    /** @return array<string,mixed> */
    public static function make(string $name): array
    {
        $definition = self::strata()[$name] ?? throw new InvalidArgumentException('Unknown remaining archive fixture.');
        [$bytes, $oracle] = match ($definition['module']) {
            'iso_primary_copies' => self::isoFixture($name),
            'rar5_copy_checksums' => self::copyFixture($name),
            default => self::quickFixture($name),
        };

        return $definition + ['name' => $name, 'bytes' => $bytes, 'input_sha256' => hash('sha256', $bytes), 'oracle' => $oracle];
    }

    /** @param array<string,mixed> $fixture
     * @return array<string,mixed>
     */
    public static function baseline(array $fixture): array
    {
        return match ($fixture['module']) {
            'iso_primary_copies' => self::readIso($fixture['bytes']),
            'rar5_copy_checksums' => self::readCopy($fixture['bytes']),
            'rar5_quick_open' => self::readQuick($fixture['bytes']),
            default => throw new InvalidArgumentException('Unknown reference module.'),
        };
    }

    /** @param array<string,mixed> $fixture
     * @return array{full:array<string,mixed>,projection:array<string,mixed>}
     */
    public static function native(NativeMetadataParser $parser, array $fixture): array
    {
        $full = $parser->parseFormats($fixture['bytes']);
        if ($full === null) {
            throw new RuntimeException('NativeMetadataParser::parseFormats returned null.');
        }
        $value = match ($fixture['module']) {
            'iso_primary_copies' => $full['iso_primary_volume_descriptor_copies'] ?? null,
            'rar5_copy_checksums' => $full['headers'][0]['visible_block_walk']['blocks'][1]['file_header']['redirection_checksum_relationship'] ?? null,
            'rar5_quick_open' => $full['headers'][0]['visible_block_walk']['blocks'][2]['quick_open_cache'] ?? null,
            default => null,
        };
        if (! is_array($value)) {
            throw new RuntimeException('Native parseFormats module metadata missing.');
        }
        $projection = self::fields($value, match ($fixture['module']) {
            'iso_primary_copies' => self::ISO_FIELDS,
            'rar5_copy_checksums' => self::COPY_FIELDS,
            default => self::QUICK_FIELDS,
        });
        if ($fixture['module'] === 'rar5_quick_open') {
            $projection['records'] = array_map(static fn (array $record): array => self::fields($record, self::RECORD_FIELDS), $projection['records']);
        }

        return ['full' => $full, 'projection' => $projection];
    }

    /** @param array<string,mixed> $fixture
     * @param  array<string,mixed>  $result
     */
    public static function correct(array $fixture, array $result): bool
    {
        return hash('sha256', $fixture['bytes']) === $fixture['input_sha256'] && $result === $fixture['oracle'];
    }

    /** @return list<string> */
    public static function sourcePaths(): array
    {
        return ['tests/Fixtures/RemainingArchiveBenchmarkFixtures.php', 'app/Services/Releases/Fingerprint/NativeMetadataParser.php'];
    }

    /** @param array<string,mixed> $value
     * @param  list<string>  $fields
     * @return array<string,mixed>
     */
    private static function fields(array $value, array $fields): array
    {
        $result = [];
        foreach ($fields as $field) {
            if (! array_key_exists($field, $value)) {
                throw new RuntimeException('Missing projected field '.$field);
            }
            $result[$field] = $value[$field];
        }

        return $result;
    }

    /** Oracle is built from declared construction facts, never from readIso().
     * @return array{string,array<string,mixed>}
     */
    private static function isoFixture(string $name): array
    {
        $primary = "\x01CD001\x01".str_repeat("\0", 2041);
        $changed = substr_replace($primary, "\x01", 2047, 1);
        $count = $name === 'iso-copies-uncommon-limit' ? 240 : ($name === 'iso-copies-uncommon-partial' ? 1 : 2);
        $differing = in_array($name, ['iso-copies-different', 'iso-copies-uncommon-limit'], true);
        $sequence = $name === 'iso-copies-uncommon-limit' ? 'scan_limit_reached' : ($name === 'iso-copies-uncommon-partial' ? 'sample_truncated' : 'terminated');
        $bytes = str_repeat("\0", 32768).str_repeat($primary, $count);
        if ($differing) {
            $bytes = substr_replace($bytes, $changed, 32768 + ($count - 1) * 2048, 2048);
        }
        if ($sequence === 'terminated') {
            $bytes .= "\xffCD001\x01".str_repeat("\0", 2041).$changed;
        } elseif ($sequence === 'sample_truncated') {
            $bytes .= substr($changed, 0, 2047);
        }
        $difference = $differing ? ['descriptor_relative_offset' => 2047, 'reference_sample_offset' => 34815,
            'differing_sample_offset' => 32768 + $count * 2048 - 1, 'reference_byte' => 0, 'differing_byte' => 1] : null;
        $copies = [];
        for ($index = 0; $index < min($count, 64); $index++) {
            $different = $differing && $index === $count - 1;
            $copies[] = ['descriptor_sequence_index' => $index, 'sector' => 16 + $index, 'offset' => 32768 + $index * 2048,
                'end_offset_exclusive' => 32768 + ($index + 1) * 2048, 'bytes_hashed' => 2048,
                'raw_sha256' => hash('sha256', $different ? $changed : $primary), 'is_reference' => $index === 0,
                'matches_reference' => ! $different, 'first_difference' => $different ? $difference : null];
        }
        $oracle = ['status' => $count === 1 ? 'single_primary_descriptor_observed' : ($differing ? 'observed_copies_differ' : 'observed_copies_agree'),
            'image_origin_known' => false, 'whole_image_validated' => false, 'sequence_status' => $sequence,
            'sampled_descriptor_sequence_terminated' => $sequence === 'terminated', 'reference_descriptor_offset' => 32768,
            'observed_descriptor_count' => $count, 'repeat_comparison_count' => $count - 1,
            'matching_reference_count' => $count - (int) $differing, 'differing_reference_count' => (int) $differing,
            'all_observed_copies_equal' => $count > 1 ? ! $differing : null, 'first_difference' => $difference,
            'detail_limit' => 64, 'details_omitted_count' => max(0, $count - 64), 'copies' => $copies];

        return [$bytes, $oracle];
    }

    /** ECMA-119 descriptors have fixed 2048-byte recordings; acceptance stops
     * at first invalid record, terminator, partial record or sector 256.
     *
     * @return array<string,mixed>
     */
    private static function readIso(string $bytes): array
    {
        if (substr($bytes, 32769, 5) !== 'CD001') {
            throw new RuntimeException('Reference requires an ISO descriptor sequence at sector 16.');
        }
        $reference = null;
        $referenceOffset = null;
        $copies = [];
        $observed = $matching = $differing = 0;
        $firstDifference = null;
        $sequence = 'scan_limit_reached';
        for ($index = 0; $index < 240; $index++) {
            $offset = (16 + $index) * 2048;
            $record = substr($bytes, $offset, 2048);
            if (strlen($record) !== 2048) {
                $sequence = 'sample_truncated';
                break;
            }
            $type = ord($record[0]);
            $version = ord($record[6]);
            if (substr($record, 1, 5) !== 'CD001') {
                $sequence = 'malformed';
                break;
            }
            if ($type === 255) {
                $sequence = $version !== 1 ? 'malformed_terminator_version' : (trim(substr($record, 7), "\0") === '' ? 'terminated' : 'malformed_terminator_reserved_bytes');
                break;
            }
            if (! (in_array($type, [0, 1, 3], true) && $version === 1) && ! ($type === 2 && in_array($version, [1, 2], true))) {
                $sequence = $type >= 4 ? 'unsupported_reserved_type' : 'malformed';
                break;
            }
            if ($type !== 1) {
                continue;
            }
            if ($reference === null) {
                $reference = $record;
                $referenceOffset = $offset;
            }
            $difference = null;
            if ($record !== $reference) {
                for ($relative = 0; $relative < 2048; $relative++) {
                    if ($record[$relative] !== $reference[$relative]) {
                        $difference = ['descriptor_relative_offset' => $relative, 'reference_sample_offset' => $referenceOffset + $relative,
                            'differing_sample_offset' => $offset + $relative, 'reference_byte' => ord($reference[$relative]), 'differing_byte' => ord($record[$relative])];
                        break;
                    }
                }
            }
            $observed++;
            if ($difference === null) {
                $matching++;
            } else {
                $differing++;
                $firstDifference ??= $difference;
            }
            if (count($copies) < 64) {
                $copies[] = ['descriptor_sequence_index' => $index, 'sector' => 16 + $index, 'offset' => $offset, 'end_offset_exclusive' => $offset + 2048,
                    'bytes_hashed' => 2048, 'raw_sha256' => hash('sha256', $record), 'is_reference' => $observed === 1,
                    'matches_reference' => $difference === null, 'first_difference' => $difference];
            }
        }

        return ['status' => $observed < 2 ? ($observed === 1 ? 'single_primary_descriptor_observed' : 'no_primary_descriptor_observed') : ($differing === 0 ? 'observed_copies_agree' : 'observed_copies_differ'),
            'image_origin_known' => false, 'whole_image_validated' => false, 'sequence_status' => $sequence, 'sampled_descriptor_sequence_terminated' => $sequence === 'terminated',
            'reference_descriptor_offset' => $referenceOffset, 'observed_descriptor_count' => $observed, 'repeat_comparison_count' => max(0, $observed - 1),
            'matching_reference_count' => $matching, 'differing_reference_count' => $differing, 'all_observed_copies_equal' => $observed > 1 ? $differing === 0 : null,
            'first_difference' => $firstDifference, 'detail_limit' => 64, 'details_omitted_count' => max(0, $observed - 64), 'copies' => $copies];
    }

    /** @return array{string,array<string,mixed>} */
    private static function copyFixture(string $name): array
    {
        $blake = $name === 'rar-copy-blake';
        $ambiguous = $name === 'rar-copy-uncommon-ambiguous';
        $partial = $name === 'rar-copy-uncommon-truncated';
        $redirect = "\x05\x05\x05\0\x01a";
        $extra = $partial ? "\x06\x05\x05\0\x01a" : $redirect.($ambiguous ? $redirect : '');
        if ($blake) {
            $extra .= "\x22\x02\0".hex2bin(self::EMPTY_BLAKE);
        }
        $fields = "\x02\x03".self::vint(strlen($extra))."\0".($blake ? "\0" : "\x04")."\x01\0".($blake ? '' : pack('V', 0))."\0\0\x01b";
        $header = self::frame($fields.$extra);
        $bytes = hex2bin('526172211a070100c51a333203010000').$header.hex2bin('19b23a3503050000');
        $extraOffset = 16 + 4 + strlen(self::vint(strlen($fields.$extra))) + strlen($fields);
        $status = $ambiguous ? 'redirection_records_ambiguous' : ($partial ? 'extra_framing_incomplete' : 'qualified_empty_entry_domain');
        $oracle = self::copyResult($status, 16, $blake ? null : 28, $partial ? null : $extraOffset, $partial ? null : $extraOffset + 6,
            $blake ? [$extraOffset + 6, $extraOffset + 41] : null, $partial ? null : 5);
        if (! $ambiguous && ! $partial) {
            $oracle['reference_data_domain'] = 'empty_file_copy_entry_data';
            $oracle['crc32_comparison_status'] = $blake ? 'not_declared' : 'matched';
            $oracle['stored_crc32_matches_empty_data'] = $blake ? null : true;
            $oracle['blake2sp_comparison_status'] = $blake ? 'matched' : 'not_declared';
            $oracle['stored_blake2sp_matches_empty_data'] = $blake ? true : null;
            $oracle['reference_selected_checksum'] = $blake ? 'BLAKE2sp' : 'CRC32';
            $oracle['reference_selection_status'] = $blake ? 'unique_supported_hash_extra_replaces_crc32' : 'no_hash_extra_crc32_selection_if_declared';
        }

        return [$bytes, $oracle];
    }

    /** @param array{int,int}|null $hash
     * @return array<string,mixed>
     */
    private static function copyResult(string $status, int $offset, ?int $crc, ?int $start, ?int $end, ?array $hash, ?int $type): array
    {
        return ['status' => $status, 'source_header_offset' => $offset, 'source_crc32_byte_offset' => $crc,
            'source_record_offset' => $start, 'source_record_end_offset' => $end, 'source_hash_record_index' => $hash === null ? null : 1,
            'source_hash_record_offset' => $hash[0] ?? null, 'source_hash_record_end_offset' => $hash[1] ?? null,
            'source_hash_digest_offset' => $hash === null ? null : $hash[1] - 32, 'redirection_type_observed' => $type,
            'reference_data_domain' => null, 'reference_empty_crc32' => 0, 'reference_empty_blake2sp_hex' => self::EMPTY_BLAKE,
            'crc32_comparison_status' => 'domain_unqualified', 'stored_crc32_matches_empty_data' => null,
            'blake2sp_comparison_status' => 'domain_unqualified', 'stored_blake2sp_matches_empty_data' => null,
            'reference_selected_checksum' => null, 'reference_selection_status' => 'domain_unqualified', 'target_resolution_performed' => false];
    }

    /** Independent RAR5 wire framing; constrained fixture grammar: stored,
     * unencrypted file-copy entries with redirection/hash extras, no split data.
     * Unsupported shapes throw instead of being silently treated as equivalent.
     *
     * @return array<string,mixed>
     */
    private static function readCopy(string $bytes): array
    {
        $blocks = self::blocks($bytes);
        $block = $blocks[1] ?? throw new RuntimeException('Missing file-copy header.');
        $cursor = $block['fields'];
        $fileFlags = self::readVint($bytes, $cursor, $block['extras']);
        $unpacked = self::readVint($bytes, $cursor, $block['extras']);
        self::readVint($bytes, $cursor, $block['extras']);
        $crcOffset = null;
        $crc = null;
        if ($fileFlags & 4) {
            $crcOffset = $cursor;
            $crc = self::u32($bytes, $cursor);
            $cursor += 4;
        }
        $compression = self::readVint($bytes, $cursor, $block['extras']);
        self::readVint($bytes, $cursor, $block['extras']);
        $nameSize = self::readVint($bytes, $cursor, $block['extras']);
        $cursor += $nameSize;
        if ($cursor !== $block['extras'] || $fileFlags & ~4 || $unpacked !== 1 || $compression !== 0 || $block['packed'] !== 0 || $block['kind'] !== 2 || $block['flags'] !== 3) {
            throw new RuntimeException('Reference file-copy grammar unsupported.');
        }
        $redirects = [];
        $hashes = [];
        $complete = true;
        $cursor = $block['extras'];
        while ($cursor < $block['end']) {
            $start = $cursor;
            $size = self::readVint($bytes, $cursor, $block['end']);
            $end = $cursor + $size;
            if ($end > $block['end'] || $size === 0) {
                $complete = false;
                break;
            }
            $type = self::readVint($bytes, $cursor, $end);
            if ($type === 5) {
                $redirectType = self::readVint($bytes, $cursor, $end);
                $flags = self::readVint($bytes, $cursor, $end);
                $length = self::readVint($bytes, $cursor, $end);
                if ($redirectType !== 5 || $flags !== 0 || $length !== 1 || $cursor + $length !== $end) {
                    throw new RuntimeException('Reference redirection grammar unsupported.');
                }
                $redirects[] = [$start, $end, $redirectType];
            } elseif ($type === 2) {
                if (self::readVint($bytes, $cursor, $end) !== 0 || $end - $cursor !== 32) {
                    throw new RuntimeException('Reference hash grammar unsupported.');
                }
                $hashes[] = [$start, $end, bin2hex(substr($bytes, $cursor, 32))];
            } else {
                throw new RuntimeException('Reference extra type unsupported.');
            }
            $cursor = $end;
        }
        $status = ! $block['valid'] ? 'header_checksum_unconfirmed' : (! $complete ? 'extra_framing_incomplete' : (count($redirects) !== 1 ? 'redirection_records_ambiguous' : 'qualified_empty_entry_domain'));
        $redirect = $redirects[0] ?? null;
        $hash = count($hashes) === 1 ? $hashes[0] : null;
        $result = self::copyResult($status, $block['start'], $crcOffset, $redirect[0] ?? null, $redirect[1] ?? null,
            $hash === null ? null : [$hash[0], $hash[1]], $redirect[2] ?? null);
        if ($status !== 'qualified_empty_entry_domain') {
            return $result;
        }
        $result['reference_data_domain'] = 'empty_file_copy_entry_data';
        $result['crc32_comparison_status'] = $crc === null ? 'not_declared' : ($crc === 0 ? 'matched' : 'mismatch');
        $result['stored_crc32_matches_empty_data'] = $crc === null ? null : $crc === 0;
        if ($hash !== null) {
            $result['blake2sp_comparison_status'] = $hash[2] === self::EMPTY_BLAKE ? 'matched' : 'mismatch';
            $result['stored_blake2sp_matches_empty_data'] = $hash[2] === self::EMPTY_BLAKE;
            $result['reference_selected_checksum'] = 'BLAKE2sp';
            $result['reference_selection_status'] = 'unique_supported_hash_extra_replaces_crc32';
        } elseif ($hashes === []) {
            $result['blake2sp_comparison_status'] = 'not_declared';
            $result['reference_selected_checksum'] = $crc === null ? null : 'CRC32';
            $result['reference_selection_status'] = 'no_hash_extra_crc32_selection_if_declared';
        } else {
            throw new RuntimeException('Reference ambiguous hash grammar unsupported.');
        }

        return $result;
    }

    /** @return array{string,array<string,mixed>} */
    private static function quickFixture(string $name): array
    {
        $original = hex2bin('b50bc71209020000000000000161');
        $cached = $name === 'rar-quick-open-conflict' ? hex2bin('0f5ace8b09020000000000000162') : $original;
        $record = self::frame("\0\x0e\x0e".$cached);
        $corrupt = $name === 'rar-quick-open-uncommon-corrupt';
        if ($corrupt) {
            $record[0] = chr(ord($record[0]) ^ 1);
        }
        $count = $name === 'rar-quick-open-uncommon-retention' ? 66 : ($corrupt ? 2 : 1);
        $records = str_repeat($record, $count);
        $service = self::frame("\x03\x02".self::vint(strlen($records))."\0".self::vint(strlen($records))."\0\0\0\x02QO");
        $bytes = hex2bin('526172211a070100fec2942c08010104000301011b').$original.$service.$records.hex2bin('19b23a3503050000');
        $start = 35 + strlen($service);
        $rows = [];
        for ($index = 0; $index < min($corrupt ? 1 : $count, 64); $index++) {
            $offset = $start + $index * strlen($record);
            $match = $cached === $original;
            $rows[] = ['index' => $index, 'structure_start_offset' => $offset, 'structure_end_offset' => $offset + strlen($record),
                'structure_crc32_valid' => ! $corrupt, 'structure_sha256' => hash('sha256', $record), 'backward_offset' => 14,
                'declared_cached_data_size' => 14, 'cached_data_sha256' => hash('sha256', $cached),
                'original_start_offset' => $corrupt ? null : 21, 'original_end_offset' => $corrupt ? null : 35,
                'original_data_sha256' => $corrupt ? null : hash('sha256', $original),
                'comparison_status' => $corrupt ? 'structure_checksum_invalid' : ($match ? 'matched' : 'mismatch'),
                'cached_bytes_match_original' => $corrupt ? null : $match];
        }
        $oracle = ['status' => $corrupt ? 'structure_checksum_invalid' : 'complete', 'work_byte_limit' => 1048576, 'scanned_record_limit' => 4096,
            'retained_record_limit' => 64, 'record_count' => $corrupt ? 1 : $count, 'declared_data_fully_scanned' => ! $corrupt,
            'observations_omitted' => max(0, $count - 64), 'observed_original_positions_strictly_increasing' => $count > 1 && ! $corrupt ? false : null,
            'cache_overlap_comparison_status' => 'not_evaluated_between_cache_records', 'stopped_offset' => $start + ($corrupt ? 1 : $count) * strlen($record),
            'service_data_crc32_status' => 'not_declared', 'records' => $rows];

        return [$bytes, $oracle];
    }

    /** Constrained stored QO grammar, same-sample byte relationships only.
     * @return array<string,mixed>
     */
    private static function readQuick(string $bytes): array
    {
        $blocks = self::blocks($bytes);
        $service = $blocks[2] ?? throw new RuntimeException('Missing QO service.');
        $cursor = $service['fields'];
        $fileFlags = self::readVint($bytes, $cursor, $service['extras']);
        $unpacked = self::readVint($bytes, $cursor, $service['extras']);
        self::readVint($bytes, $cursor, $service['extras']);
        $compression = self::readVint($bytes, $cursor, $service['extras']);
        self::readVint($bytes, $cursor, $service['extras']);
        $length = self::readVint($bytes, $cursor, $service['extras']);
        $name = substr($bytes, $cursor, $length);
        $cursor += $length;
        if ($fileFlags !== 0 || $unpacked !== $service['packed'] || $compression !== 0 || $name !== 'QO' || $cursor !== $service['end'] || $service['kind'] !== 3 || $service['flags'] !== 2) {
            throw new RuntimeException('Reference QO service grammar unsupported.');
        }
        $cursor = $service['end'];
        $declaredEnd = $cursor + $service['packed'];
        $end = min(strlen($bytes), $declaredEnd);
        $result = ['status' => $service['valid'] ? ($declaredEnd <= strlen($bytes) ? 'complete' : 'service_data_partial') : 'service_header_untrusted',
            'work_byte_limit' => 1048576, 'scanned_record_limit' => 4096, 'retained_record_limit' => 64, 'record_count' => 0,
            'declared_data_fully_scanned' => false, 'observations_omitted' => 0, 'observed_original_positions_strictly_increasing' => null,
            'cache_overlap_comparison_status' => 'not_evaluated_between_cache_records', 'stopped_offset' => $cursor,
            'service_data_crc32_status' => $service['valid'] ? 'not_declared' : 'not_inspected', 'records' => []];
        if (! $service['valid']) {
            return $result;
        }
        $work = 1048576;
        $previous = null;
        while ($cursor < $end) {
            if ($result['record_count'] >= 4096) {
                $result['status'] = 'scan_budget_exhausted';
                break;
            }
            if ($end - $cursor < 4) {
                $result['status'] = 'structure_crc_truncated';
                break;
            }
            $position = $cursor + 4;
            $sizeStart = $position;
            $size = self::readVint($bytes, $position, $end);
            if ($position - $sizeStart > 3) {
                $result['status'] = 'structure_size_encoding_unsupported';
                break;
            }
            $recordEnd = $position + $size;
            if ($recordEnd > $end) {
                $result['status'] = 'structure_partial';
                break;
            }
            $flags = self::readVint($bytes, $position, $recordEnd);
            $back = self::readVint($bytes, $position, $recordEnd);
            $cachedSize = self::readVint($bytes, $position, $recordEnd);
            if ($position + $cachedSize > $recordEnd) {
                throw new RuntimeException('Reference cached range unsupported.');
            }
            $cost = 2 * ($recordEnd - $cursor) + 12 * $cachedSize;
            if ($cost > $work) {
                $result['status'] = 'work_budget_exhausted';
                break;
            }
            $work -= $cost;
            $valid = self::u32($bytes, $cursor) === crc32(substr($bytes, $sizeStart, $recordEnd - $sizeStart));
            $cached = substr($bytes, $position, $cachedSize);
            $start = $valid && $flags === 0 && $back <= $service['start'] ? $service['start'] - $back : null;
            $original = $start !== null && $start + $cachedSize <= strlen($bytes) ? substr($bytes, $start, $cachedSize) : null;
            $status = ! $valid ? 'structure_checksum_invalid' : ($flags !== 0 ? 'structure_flags_unsupported' : ($cachedSize === 0 ? 'empty_cached_range' : ($start === null ? 'original_precedes_sample' : ($original === null ? 'original_range_unavailable' : ($position + $cachedSize !== $recordEnd ? 'structure_extension_uninterpreted' : ($cached === $original ? 'matched' : 'mismatch'))))));
            if ($valid && $flags === 0) {
                if ($previous !== null) {
                    $result['observed_original_positions_strictly_increasing'] = ($result['observed_original_positions_strictly_increasing'] ?? true) && $back < $previous;
                }
                $previous = $back;
            }
            $index = $result['record_count']++;
            if ($index < 64) {
                $result['records'][] = ['index' => $index, 'structure_start_offset' => $cursor, 'structure_end_offset' => $recordEnd,
                    'structure_crc32_valid' => $valid, 'structure_sha256' => hash('sha256', substr($bytes, $cursor, $recordEnd - $cursor)),
                    'backward_offset' => $back, 'declared_cached_data_size' => $cachedSize, 'cached_data_sha256' => hash('sha256', $cached),
                    'original_start_offset' => $start, 'original_end_offset' => $start === null ? null : $start + $cachedSize,
                    'original_data_sha256' => $original === null || $cachedSize === 0 ? null : hash('sha256', $original),
                    'comparison_status' => $status, 'cached_bytes_match_original' => in_array($status, ['matched', 'mismatch'], true) ? $cached === $original : null];
            } else {
                $result['observations_omitted']++;
            }
            $cursor = $recordEnd;
            $result['stopped_offset'] = $cursor;
            if (! $valid || $flags !== 0) {
                $result['status'] = $status;
            }
            if (! $valid) {
                break;
            }
        }
        $result['declared_data_fully_scanned'] = $cursor === $declaredEnd;

        return $result;
    }

    /** RAR5 header CRC includes size vint and header data, excludes stored CRC.
     * @return list<array<string,mixed>>
     */
    private static function blocks(string $bytes): array
    {
        if (substr($bytes, 0, 8) !== "Rar!\x1a\x07\x01\0") {
            throw new RuntimeException('Reference RAR5 marker missing.');
        }
        $result = [];
        $cursor = 8;
        while ($cursor < strlen($bytes) && count($result) < 256) {
            $start = $cursor;
            $cursor += 4;
            $sizeStart = $cursor;
            $size = self::readVint($bytes, $cursor, strlen($bytes));
            $end = $cursor + $size;
            if ($end > strlen($bytes)) {
                throw new RuntimeException('Reference RAR5 header partial.');
            }
            $kind = self::readVint($bytes, $cursor, $end);
            $flags = self::readVint($bytes, $cursor, $end);
            $extra = $flags & 1 ? self::readVint($bytes, $cursor, $end) : 0;
            $packed = $flags & 2 ? self::readVint($bytes, $cursor, $end) : 0;
            if ($end - $extra < $cursor) {
                throw new RuntimeException('Reference extra extent invalid.');
            }
            $result[] = ['start' => $start, 'end' => $end, 'fields' => $cursor, 'extras' => $end - $extra, 'packed' => $packed,
                'kind' => $kind, 'flags' => $flags, 'valid' => self::u32($bytes, $start) === crc32(substr($bytes, $sizeStart, $end - $sizeStart))];
            $cursor = $end + $packed;
            if ($kind === 5) {
                break;
            }
        }

        return $result;
    }

    private static function frame(string $body): string
    {
        $checked = self::vint(strlen($body)).$body;

        return pack('V', crc32($checked)).$checked;
    }

    private static function vint(int $value): string
    {
        $result = '';
        do {
            $byte = $value & 127;
            $value >>= 7;
            $result .= chr($byte | ($value > 0 ? 128 : 0));
        } while ($value > 0);

        return $result;
    }

    private static function readVint(string $bytes, int &$cursor, int $end): int
    {
        $value = 0;
        for ($shift = 0; $shift <= 28; $shift += 7) {
            if ($cursor >= $end || $cursor >= strlen($bytes)) {
                throw new RuntimeException('Reference vint truncated.');
            }
            $byte = ord($bytes[$cursor++]);
            $value |= ($byte & 127) << $shift;
            if (($byte & 128) === 0) {
                return $value;
            }
        }
        throw new RuntimeException('Reference vint exceeds bounded 35-bit grammar.');
    }

    private static function u32(string $bytes, int $offset): int
    {
        if ($offset + 4 > strlen($bytes)) {
            throw new RuntimeException('Reference CRC truncated.');
        }

        return unpack('V', substr($bytes, $offset, 4))[1];
    }
}
