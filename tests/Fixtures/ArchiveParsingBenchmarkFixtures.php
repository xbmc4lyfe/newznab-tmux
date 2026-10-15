<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use InvalidArgumentException;
use RuntimeException;

/** Public, offline wire fixtures; no production NZBs, articles, credentials or paths. */
final class ArchiveParsingBenchmarkFixtures
{
    public const PUBLIC_SHA256 = 'daa664a5a3c782d03be0eb8bf954b1d3daec18f8c72c7e9d09786c244757ad9e';

    /**
     * @return array<string,array{operation:string,category:string,paired:bool}> */
    public static function strata(): array
    {
        $result = [];
        foreach (['public-rename-index', 'synthetic-128', 'synthetic-512', 'synthetic-1024'] as $name) {
            $result[$name] = ['operation' => 'par2', 'category' => in_array($name, ['public-rename-index', 'synthetic-128'], true) ? 'common' : 'uncommon', 'paired' => true];
        }
        foreach (['rar4', 'rar5', 'zip', 'seven-zip', 'iso', 'seven-zip-truncated', 'seven-zip-corrupt'] as $name) {
            $result[$name] = ['operation' => 'formats', 'category' => 'diagnostic', 'paired' => false];
        }
        foreach (['seven-ranges', 'par2-ranges'] as $operation) {
            foreach (['complete', 'split', 'missing', 'conflicting'] as $kind) {
                $result[$operation.'-'.$kind] = ['operation' => $operation, 'category' => 'diagnostic', 'paired' => false];
            }
        }
        $result['par2-ranges-checksum'] = ['operation' => 'par2-ranges', 'category' => 'diagnostic', 'paired' => false];

        return $result;
    }

    /**
     * @return array<string,mixed> */
    public static function make(string $name, string $root): array
    {
        $definition = self::strata()[$name] ?? throw new InvalidArgumentException('Unknown archive stratum.');
        if ($definition['operation'] === 'par2') {
            if ($name === 'public-rename-index') {
                $bytes = file_get_contents($root.'/tests/Fixtures/par2/rename-index.par2');
                if ($bytes === false || hash('sha256', $bytes) !== self::PUBLIC_SHA256) {
                    throw new RuntimeException('Public PAR2 fixture identity mismatch.');
                }
            } else {
                $bytes = self::par2((int) substr($name, strlen('synthetic-')));
            }
            $oracle = self::par2Oracle($bytes);
            if ($name === 'public-rename-index' && ($oracle['packet_count'] !== 22 || $oracle['source_file_count'] !== 10
                || $oracle['source_block_size'] !== 5376 || $oracle['source_file_bytes'] !== 1048576 || $oracle['source_block_count'] !== 200)) {
                throw new RuntimeException('Public PAR2 fixture inventory mismatch.');
            }

            return $definition + ['name' => $name, 'bytes' => $bytes, 'oracle' => $oracle, 'input_sha256' => hash('sha256', $bytes)];
        }
        if ($definition['operation'] === 'formats') {
            [$bytes, $oracle] = self::format($name);
        } else {
            [$bytes, $oracle] = self::ranges($definition['operation'], substr($name, strlen($definition['operation']) + 1));
        }

        return $definition + ['name' => $name, 'bytes' => $bytes, 'oracle' => $oracle, 'input_sha256' => hash('sha256', $bytes)];
    }

    public static function par2(int $files): string
    {
        if ($files < 1 || $files > 1024) {
            throw new InvalidArgumentException('Synthetic inventory must contain 1..1024 files.');
        }
        $data = str_repeat('fixture payload ', 1200);
        $length = pack('V2', strlen($data), 0);
        $hash16 = md5(substr($data, 0, 16384), true);
        $packets = [];
        $ids = [];
        for ($index = 0; $index < $files; $index++) {
            $name = sprintf('offline-%04d.bin', $index);
            $id = md5($hash16.$length.$name, true);
            $ids[] = $id;
            $packets[] = ["PAR 2.0\0FileDesc", $id.md5($data, true).$hash16.$length.$name];
            $records = '';
            foreach (str_split($data, 16384) as $slice) {
                $padded = str_pad($slice, 16384, "\0");
                $records .= md5($padded, true).pack('V', crc32($padded));
            }
            $packets[] = ["PAR 2.0\0IFSC\0\0\0\0", $id.$records];
        }
        usort($ids, static fn (string $left, string $right): int => strcmp(strrev($left), strrev($right)));
        $main = pack('V3', 16384, 0, $files).implode('', $ids);
        $setId = md5($main, true);
        $bytes = self::packet("PAR 2.0\0Main\0\0\0\0", $main, $setId);
        foreach ($packets as [$type, $body]) {
            $bytes .= self::packet($type, $body, $setId);
        }

        return $bytes.self::packet("PAR 2.0\0Creator\0", 'Offline benchmark https://example.test/', $setId);
    }

    private static function packet(string $type, string $body, string $setId): string
    {
        $body = str_pad($body, (int) (ceil(strlen($body) / 4) * 4), "\0");
        $hashed = $setId.$type.$body;

        return "PAR2\0PKT".pack('V2', strlen($body) + 64, 0).md5($hashed, true).$hashed;
    }

    /** Independent bounded wire decoder, with no application parser calls.
     *
     * @return array<string,mixed>
     */
    public static function par2Oracle(string $bytes): array
    {
        $offset = 0;
        $count = 0;
        $sources = [];
        $checksums = [];
        $main = null;
        $setIds = [];
        $creator = null;
        $types = [];
        while ($offset < strlen($bytes)) {
            if (substr($bytes, $offset, 8) !== "PAR2\0PKT" || strlen($bytes) - $offset < 64) {
                throw new RuntimeException('Invalid PAR2 packet marker/header.');
            }
            $size = self::u64(substr($bytes, $offset + 8, 8));
            if ($size < 64 || $size % 4 !== 0 || $size > strlen($bytes) - $offset) {
                throw new RuntimeException('Invalid PAR2 packet extent.');
            }
            $packet = substr($bytes, $offset, $size);
            if (! hash_equals(substr($packet, 16, 16), md5(substr($packet, 32), true))) {
                throw new RuntimeException('Invalid PAR2 packet MD5.');
            }
            $set = bin2hex(substr($packet, 32, 16));
            $setIds[$set] = true;
            $type = rtrim(substr($packet, 48, 16), "\0");
            $types[$type] = ($types[$type] ?? 0) + 1;
            $body = substr($packet, 64);
            if ($type === "PAR 2.0\0Main") {
                if ($main !== null || strlen($body) < 12 || (strlen($body) - 12) % 16 !== 0 || md5($body) !== $set) {
                    throw new RuntimeException('Invalid PAR2 Main/set ID.');
                }
                $block = self::u64(substr($body, 0, 8));
                $recoverable = self::u32(substr($body, 8, 4));
                $ids = array_map('bin2hex', str_split(substr($body, 12), 16));
                $sortedIds = $ids;
                usort($sortedIds, static fn (string $a, string $b): int => strcmp(strrev(hex2bin($a)), strrev(hex2bin($b))));
                if ($block < 1 || $recoverable !== count($ids) || $sortedIds !== $ids || count(array_unique($ids)) !== count($ids)) {
                    throw new RuntimeException('Invalid PAR2 Main inventory.');
                }
                $main = ['block' => $block, 'ids' => $ids];
            } elseif ($type === "PAR 2.0\0FileDesc") {
                if (strlen($body) < 57) {
                    throw new RuntimeException('Invalid PAR2 description.');
                }
                $id = bin2hex(substr($body, 0, 16));
                $name = rtrim(substr($body, 56), "\0");
                $sizeBytes = substr($body, 48, 8);
                if (isset($sources[$id]) || md5(substr($body, 32, 16).$sizeBytes.$name) !== $id) {
                    throw new RuntimeException('Invalid PAR2 derived file ID.');
                }
                $sources[$id] = ['file_id' => $id, 'filename' => $name, 'file_length' => self::u64($sizeBytes),
                    'md5' => bin2hex(substr($body, 16, 16)), 'md5_first_16k' => bin2hex(substr($body, 32, 16))];
            } elseif ($type === "PAR 2.0\0IFSC") {
                if (strlen($body) < 16 || (strlen($body) - 16) % 20 !== 0) {
                    throw new RuntimeException('Invalid PAR2 IFSC records.');
                }
                $id = bin2hex(substr($body, 0, 16));
                if (isset($checksums[$id])) {
                    throw new RuntimeException('Duplicate PAR2 IFSC.');
                }
                $records = substr($body, 16);
                $checksums[$id] = ['file_id' => $id, 'slice_count' => intdiv(strlen($records), 20), 'checksum_records_sha256' => hash('sha256', $records)];
            } elseif ($type === "PAR 2.0\0Creator") {
                $creator = rtrim($body, "\0");
            } else {
                throw new RuntimeException('Fixture oracle supports Main/FileDesc/IFSC/Creator only.');
            }
            $count++;
            $offset += $size;
        }
        if ($main === null || count($setIds) !== 1 || $creator === null) {
            throw new RuntimeException('Incomplete PAR2 fixture.');
        }
        $ids = array_keys($sources);
        sort($ids);
        $mainIds = $main['ids'];
        sort($mainIds);
        $checksumIds = array_keys($checksums);
        sort($checksumIds);
        if ($ids !== $mainIds || $checksumIds !== $mainIds) {
            throw new RuntimeException('PAR2 source membership differs.');
        }
        $blocks = 0;
        $total = 0;
        foreach ($sources as $id => $source) {
            $slices = (int) ceil($source['file_length'] / $main['block']);
            if ($checksums[$id]['slice_count'] !== $slices) {
                throw new RuntimeException('PAR2 slice inventory mismatch.');
            }
            $blocks += $slices;
            $total += $source['file_length'];
        }
        ksort($sources);
        ksort($checksums);
        ksort($types);

        return ['packet_count' => $count, 'invalid_packet_count' => 0, 'source_block_size' => $main['block'],
            'source_block_count' => $blocks, 'source_file_count' => count($sources), 'source_file_bytes' => $total,
            'creator' => $creator, 'recovery_set_ids' => array_keys($setIds), 'packet_type_counts' => $types,
            'source_files' => array_values($sources), 'input_file_slice_checksums' => array_values($checksums)];
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed> */
    public static function projection(array $result): array
    {
        $keys = ['packet_count', 'invalid_packet_count', 'source_block_size', 'source_block_count', 'source_file_count',
            'source_file_bytes', 'creator', 'recovery_set_ids', 'packet_type_counts'];
        $projection = [];
        foreach ($keys as $key) {
            $projection[$key] = $result[$key] ?? null;
        }
        if (is_array($projection['packet_type_counts'])) {
            // Rust's public metadata escapes the embedded NUL in these four type
            // names; PHP preserves it. Normalize only this known spelling difference.
            $types = [];
            foreach ($projection['packet_type_counts'] as $type => $count) {
                $canonical = $type;
                foreach (['Main', 'FileDesc', 'IFSC', 'Creator'] as $name) {
                    if ($type === 'PAR 2.0\\0'.$name) {
                        $canonical = "PAR 2.0\0".$name;
                    }
                }
                if (array_key_exists($canonical, $types)) {
                    $types['duplicate-spelling:'.$type] = $count;
                } else {
                    $types[$canonical] = $count;
                }
            }
            ksort($types);
            $projection['packet_type_counts'] = $types;
        }
        foreach (['source_files' => ['file_id', 'filename', 'file_length', 'md5', 'md5_first_16k'],
            'input_file_slice_checksums' => ['file_id', 'slice_count', 'checksum_records_sha256']] as $key => $fields) {
            $rows = [];
            foreach ($result[$key] ?? [] as $row) {
                $projected = [];
                foreach ($fields as $field) {
                    $projected[$field] = $row[$field] ?? null;
                }
                $rows[] = $projected;
            }
            usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['file_id'], (string) $b['file_id']));
            $projection[$key] = $rows;
        }

        return $projection;
    }

    /**
     * @param array<string,mixed> $fixture
     * @param array<string,mixed>|null $result */
    public static function correct(array $fixture, ?array $result): bool
    {
        if ($result === null || hash('sha256', $fixture['bytes']) !== $fixture['input_sha256']) {
            return false;
        }
        if ($fixture['operation'] === 'par2') {
            return self::projection($result) === $fixture['oracle'];
        }

        return self::contains($result, $fixture['oracle']);
    }

    /**
     * @param array<array-key,mixed> $actual
     * @param array<array-key,mixed> $expected */
    private static function contains(array $actual, array $expected): bool
    {
        if (array_is_list($expected) && count($actual) !== count($expected)) {
            return false;
        }
        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $actual) || (is_array($value)
                ? (! is_array($actual[$key]) || ! self::contains($actual[$key], $value)) : $actual[$key] !== $value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{string,array<string,mixed>} */
    private static function format(string $name): array
    {
        $header = [];
        switch ($name) {
            case 'rar4':
                $main = "\x73".pack('vv', 0, 13).str_repeat("\0", 6);
                $bytes = "Rar!\x1a\x07\0".pack('v', crc32($main) & 0xFFFF).$main;
                $header = ['format' => 'rar4', 'main_header_available' => true, 'main_header_type' => 115, 'main_header_size' => 13, 'main_header_checksum_valid' => true];
                break;
            case 'rar5':
                $body = "\x03\x01\0\0";
                $bytes = "Rar!\x1a\x07\x01\0".pack('V', crc32($body)).$body;
                $header = ['format' => 'rar5', 'first_block_header_available' => true, 'first_block_header_checksum_valid' => true, 'first_block_type' => 1, 'main_archive_flags' => 0];
                break;
            case 'zip':
                $bytes = "PK\x03\x04".pack('vvvvvVVVvv', 20, 0, 0, 0, 0, 0, 0, 0, 0, 0);
                $header = ['format' => 'zip', 'structure_valid' => true, 'resolved_uncompressed_size' => 0, 'resolved_compressed_size' => 0];
                break;
            case 'iso':
                $descriptor = str_repeat("\0", 2048);
                $descriptor = substr_replace($descriptor, "\x01CD001\x01", 0, 7);
                $descriptor = substr_replace($descriptor, pack('VN', 32, 32), 80, 8);
                $descriptor = substr_replace($descriptor, pack('vnvnvn', 1, 1, 1, 1, 2048, 2048), 120, 12);
                $bytes = str_repeat("\0", 32768).$descriptor."\xffCD001\x01".str_repeat("\0", 2041);
                $header = ['format' => 'iso9660_primary_volume_descriptor', 'offset' => 32768, 'version' => 1,
                    'dual_endian_volume_space_match' => true, 'volume_space_blocks' => 32, 'logical_block_size' => 2048];
                break;
            default:
                $bytes = self::sevenZip();
                $header = ['format' => 'seven_zip', 'start_header_available' => true, 'start_header_crc_valid' => true,
                    'next_header_size' => 2, 'next_header_checksum_valid' => true, 'next_header_kind' => 'plain_header'];
                if ($name === 'seven-zip-truncated') {
                    $bytes = substr($bytes, 0, 16);
                    $header = ['format' => 'seven_zip', 'start_header_available' => false];
                } elseif ($name === 'seven-zip-corrupt') {
                    $bytes[33] = "\x01";
                    $header['next_header_checksum_valid'] = false;
                    $header['next_header_kind'] = null;
                }
        }

        return [$bytes, ['parser_engine' => 'nntmux-rust-ffi', 'sample_prefix_identity' => ['bytes_hashed' => min(16384, strlen($bytes)), 'md5' => md5(substr($bytes, 0, 16384))], 'headers' => [$header]]];
    }

    private static function sevenZip(): string
    {
        $next = "\x01\0";
        $start = pack('V2V2V', 0, 0, strlen($next), 0, crc32($next));

        return "7z\xbc\xaf\x27\x1c\0\x04".pack('V', crc32($start)).$start.$next;
    }

    /**
     * @return array{string,array<string,mixed>} */
    private static function ranges(string $operation, string $kind): array
    {
        $seven = $operation === 'seven-ranges';
        $data = $seven ? self::sevenZip() : 'offline source';
        $start = $seven ? 32 : 0;
        $parts = [[0, $data]];
        if ($kind === 'split') {
            $split = $seven ? 33 : 5;
            $parts = [[0, substr($data, 0, $split)], [$split, substr($data, $split)]];
        } elseif ($kind === 'missing') {
            $parts = [[0, substr($data, 0, -1)]];
        } elseif ($kind === 'conflicting') {
            $parts[] = [$start, '!'];
        } elseif ($kind === 'checksum') {
            $parts = [[0, 'X'.substr($data, 1)]];
        }
        $packed = $seven ? pack('VVV', count($parts), strlen($data), 0) : 'P2SR'.pack('VVVV', count($parts), 1, strlen($data), 0);
        foreach ($parts as [$offset, $bytes]) {
            $packed .= pack('V3', $offset, 0, strlen($bytes)).$bytes;
        }
        $status = match ($kind) {
            'missing' => 'partially_covered',
            'conflicting' => 'conflicting_overlaps',
            'checksum' => 'checksum_mismatch',
            default => $seven ? 'resolved_checksum_valid' : 'all_sampled_slices_valid',
        };
        if ($seven) {
            $oracle = ['relationship' => 'seven_zip_next_header_from_sampled_articles', 'resolution_status' => $status,
                'next_header_file_start' => 32, 'next_header_file_end_exclusive' => 34, 'declared_next_header_size' => 2];
            if (in_array($kind, ['complete', 'split'], true)) {
                $oracle += ['checksum_valid' => true, 'observed_crc32' => crc32(substr($data, 32)), 'sha256' => hash('sha256', substr($data, 32)), 'missing_ranges' => [], 'conflicting_overlap_bytes' => 0];
            }
        } else {
            $padded = str_pad($data, 16, "\0");
            $packed .= pack('V7', 0, 0, 0, strlen($data), 0, 16 - strlen($data), 0).md5($padded, true).pack('V', crc32($padded));
            $oracle = ['relationship' => 'par2_ifsc_slice_to_sampled_source_bytes', 'resolution_status' => $status,
                'declared_file_size' => strlen($data), 'slices' => [['source_byte_start' => 0, 'source_byte_end_exclusive' => strlen($data), 'zero_padding_bytes' => 16 - strlen($data),
                    'expected_md5' => md5($padded), 'expected_crc32' => crc32($padded)]]];
            if (in_array($kind, ['complete', 'split'], true)) {
                $oracle['slices'][0] += ['checksum_valid' => true, 'observed_md5' => md5($padded), 'observed_crc32' => crc32($padded), 'missing_ranges' => [], 'conflicting_overlap_bytes' => 0];
            }
        }

        return [$packed, $oracle];
    }

    private static function u32(string $bytes): int
    {
        if (strlen($bytes) !== 4) {
            throw new RuntimeException('Truncated integer.');
        }

        return unpack('V', $bytes)[1];
    }

    private static function u64(string $bytes): int
    {
        if (strlen($bytes) !== 8 || substr($bytes, 4) !== "\0\0\0\0") {
            throw new RuntimeException('Fixture oracle only permits bounded 32-bit sizes.');
        }

        return self::u32(substr($bytes, 0, 4));
    }
}
