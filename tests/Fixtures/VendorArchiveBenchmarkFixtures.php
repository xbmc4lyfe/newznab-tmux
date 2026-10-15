<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use dariusiii\rarinfo\RarInfo;
use dariusiii\rarinfo\SzipInfo;
use dariusiii\rarinfo\ZipInfo;
use InvalidArgumentException;
use RuntimeException;

/** Locked public fixtures; the oracle reads format bytes without either parser. */
final class VendorArchiveBenchmarkFixtures
{
    public const PREFIX = 'vendor/dariusiii/rarinfo/tests/fixtures/';

    /** @return array<string,array{category:string,format:string,path:string,sha256:string,paired:bool}> */
    public static function strata(): array
    {
        $rows = [
            'rar4-stored' => ['common', 'rar4', 'rar/store_method.rar', 'c32c1bb9c65159df7a8cedd939e7e42d3fd424e334898e586c1558093bc89d37'],
            'rar5-mixed' => ['common', 'rar5', 'rar/rar50_quickopen.rar', '645f6a8adfae57ff04b3e2ff0fbf018101e623646b4396d3cd605437d3b5f882'],
            'zip-stored' => ['common', 'zip', 'zip/little_file.zip', 'ababf71680cceb96c44ee04d69ab5dd99436c3b05c1d60242ffb8994e171ff09'],
            'seven-stored' => ['common', 'seven_zip', 'szip/store_method.7z', 'ec369e350984c9af3f5211f6cc20008abf29f7f331f8aa656604be05fafede5a'],
            'rar4-solid' => ['uncommon', 'rar4', 'rar/solid.rar', '05d39c43a4ba897ea358543aa5b700208eba307ee6e6379d8d7cbd978aa374e4'],
            'rar5-encrypted-headers' => ['uncommon', 'rar5', 'rar/rar50_encrypted_headers.rar', 'f5db452c3d7dc351b1ff9971edfb0611893838affa369e31e209570e270cc2af'],
            'zip-aes' => ['uncommon', 'zip', 'zip/encrypted_file_aes.zip', '7555457edd346b22c718e43d3409e1b9ae1dca1b118a26b1cdb960e58781238d'],
            'seven-encrypted-headers' => ['uncommon', 'seven_zip', 'szip/encrypted_headers.7z', '4b7fdbb7b875847278e65bed4ce23fc29a149a7a7e93334304dbf34fb8d52f0d'],
        ];
        $result = [];
        foreach ($rows as $name => [$category, $format, $path, $hash]) {
            $result[$name] = ['category' => $category, 'format' => $format, 'path' => self::PREFIX.$path, 'sha256' => $hash, 'paired' => true];
        }

        return $result;
    }

    /** @return array<string,mixed> */
    public static function make(string $name, string $root): array
    {
        $definition = self::strata()[$name] ?? throw new InvalidArgumentException('Unknown vendor archive fixture.');
        $bytes = file_get_contents($root.'/'.$definition['path']);
        if ($bytes === false || hash('sha256', $bytes) !== $definition['sha256']) {
            throw new RuntimeException('Locked archive fixture hash mismatch.');
        }

        return ['name' => $name, 'format' => $definition['format'], 'bytes' => $bytes,
            'input_sha256' => $definition['sha256'], 'oracle' => self::oracle($definition['format'], $bytes)];
    }

    /** Actual public in-memory API; read cap includes the complete identical input.
     * @return array<string,mixed>
     */
    public static function parseVendor(string $format, string $bytes): array
    {
        $reader = match ($format) {
            'rar4', 'rar5' => new RarInfo,
            'zip' => new ZipInfo,
            'seven_zip' => new SzipInfo,
            default => throw new InvalidArgumentException('Unknown archive format.'),
        };
        $reader->setMaxReadBytes(strlen($bytes));
        $success = $reader->setData($bytes);
        $summary = $reader->getSummary(true);
        $records = match (true) {
            $reader instanceof RarInfo => $reader->getBlocks(false),
            $reader instanceof ZipInfo => $reader->getRecords(),
            default => $reader->getHeaders(),
        };
        $reader->close();

        return ['success' => $success, 'summary' => $summary, 'records' => $records];
    }

    /** @return array<string,mixed> */
    public static function oracle(string $format, string $bytes): array
    {
        if ($format === 'rar4') {
            if (substr($bytes, 0, 7) !== "Rar!\x1a\x07\0") {
                throw new RuntimeException('RAR4 marker mismatch.');
            }
            $size = self::uint($bytes, 12, 2);
            if ((crc32(substr($bytes, 9, $size - 2)) & 0xFFFF) !== self::uint($bytes, 7, 2)) {
                throw new RuntimeException('RAR4 main header checksum mismatch.');
            }

            return ['format' => $format, 'type' => ord($bytes[9]), 'flags' => self::uint($bytes, 10, 2), 'size' => $size];
        }
        if ($format === 'rar5') {
            if (substr($bytes, 0, 8) !== "Rar!\x1a\x07\x01\0") {
                throw new RuntimeException('RAR5 marker mismatch.');
            }
            $cursor = 12;
            $size = self::vint($bytes, $cursor);
            if (crc32(substr($bytes, 12, $cursor - 12 + $size)) !== self::uint($bytes, 8, 4)) {
                throw new RuntimeException('RAR5 first header checksum mismatch.');
            }
            $type = self::vint($bytes, $cursor);
            $flags = self::vint($bytes, $cursor);
            if ($flags & 1) {
                self::vint($bytes, $cursor);
            }
            if ($flags & 2) {
                self::vint($bytes, $cursor);
            }

            return ['format' => $format, 'type' => $type, 'flags' => $flags, 'size' => $size,
                'main_flags' => $type === 1 ? self::vint($bytes, $cursor) : null];
        }
        if ($format === 'zip') {
            if (substr($bytes, 0, 4) !== "PK\x03\x04") {
                throw new RuntimeException('ZIP local marker mismatch.');
            }
            $nameLength = self::uint($bytes, 26, 2);

            return ['format' => $format, 'version' => self::uint($bytes, 4, 2), 'flags' => self::uint($bytes, 6, 2),
                'method' => self::uint($bytes, 8, 2), 'crc32' => self::uint($bytes, 14, 4),
                'packed' => self::uint($bytes, 18, 4), 'unpacked' => self::uint($bytes, 22, 4),
                'name_hex' => bin2hex(substr($bytes, 30, $nameLength))];
        }
        if ($format !== 'seven_zip' || substr($bytes, 0, 6) !== "7z\xbc\xaf\x27\x1c") {
            throw new RuntimeException('7z marker mismatch.');
        }
        $offset = self::uint($bytes, 12, 8);
        $size = self::uint($bytes, 20, 8);
        $crc = self::uint($bytes, 28, 4);
        if ($offset + 32 + $size !== strlen($bytes) || crc32(substr($bytes, 12, 20)) !== self::uint($bytes, 8, 4)
            || crc32(substr($bytes, 32 + $offset, $size)) !== $crc) {
            throw new RuntimeException('7z header extent or checksum mismatch.');
        }

        return ['format' => $format, 'major' => ord($bytes[6]), 'minor' => ord($bytes[7]),
            'next_offset' => $offset, 'next_size' => $size, 'next_crc32' => $crc, 'next_type' => ord($bytes[32 + $offset])];
    }

    /** @param array<string,mixed> $result
     * @return array<string,mixed>|null
     */
    public static function projection(array $result, string $format): ?array
    {
        if (($result['parser_engine'] ?? null) === 'nntmux-rust-ffi') {
            $headers = array_values(array_filter($result['headers'] ?? [], static fn (array $row): bool => ($row['offset'] ?? null) === 0 && ($row['format'] ?? null) === $format));
            if (count($headers) !== 1) {
                return null;
            }
            $h = $headers[0];

            return match ($format) {
                'rar4' => ['format' => $format, 'type' => $h['main_header_type'] ?? null, 'flags' => $h['main_header_flags'] ?? null, 'size' => $h['main_header_size'] ?? null],
                'rar5' => ['format' => $format, 'type' => $h['first_block_type'] ?? null, 'flags' => $h['first_block_flags'] ?? null, 'size' => $h['first_block_header_size'] ?? null, 'main_flags' => $h['main_archive_flags'] ?? null],
                'zip' => ['format' => $format, 'version' => $h['version_needed'] ?? null, 'flags' => $h['general_purpose_flags'] ?? null, 'method' => $h['compression_method'] ?? null, 'crc32' => $h['crc32'] ?? null, 'packed' => $h['compressed_size_32'] ?? null, 'unpacked' => $h['uncompressed_size_32'] ?? null, 'name_hex' => $h['filename_bytes_hex'] ?? null],
                'seven_zip' => ['format' => $format, 'major' => $h['major_version'] ?? null, 'minor' => $h['minor_version'] ?? null, 'next_offset' => $h['next_header_offset'] ?? null, 'next_size' => $h['next_header_size'] ?? null, 'next_crc32' => $h['next_header_crc32'] ?? null, 'next_type' => $h['next_header_observed_type'] ?? null],
                default => null,
            };
        }
        if (($result['success'] ?? false) !== true || isset($result['summary']['error']) || ! is_array($result['records'] ?? null)) {
            return null;
        }
        $records = array_values($result['records']);
        $h = $records[$format === 'rar4' ? 1 : 0] ?? [];

        return match ($format) {
            'rar4' => ['format' => $format, 'type' => $h['head_type'] ?? null, 'flags' => $h['head_flags'] ?? null, 'size' => $h['head_size'] ?? null],
            'rar5' => ['format' => $format, 'type' => $h['head_type'] ?? null, 'flags' => $h['head_flags'] ?? null, 'size' => $h['head_size_r'] ?? null, 'main_flags' => $h['flags'] ?? null],
            'zip' => ['format' => $format, 'version' => isset($h['version_need_num'], $h['version_need_os']) ? $h['version_need_num'] + 256 * $h['version_need_os'] : null, 'flags' => $h['flags'] ?? null, 'method' => $h['method'] ?? null, 'crc32' => $h['crc32'] ?? null, 'packed' => $h['compressed_size'] ?? null, 'unpacked' => $h['uncompressed_size'] ?? null, 'name_hex' => isset($h['file_name']) ? bin2hex($h['file_name']) : null],
            'seven_zip' => ['format' => $format, 'major' => $h['version_major'] ?? null, 'minor' => $h['version_minor'] ?? null, 'next_offset' => $h['next_head_offset'] ?? null, 'next_size' => $h['next_head_size'] ?? null, 'next_crc32' => $h['next_head_crc'] ?? null, 'next_type' => $records[1]['type'] ?? null],
            default => null,
        };
    }

    /** @param array<string,mixed> $fixture */
    public static function correct(array $fixture, mixed $result): bool
    {
        if (! is_array($result) || hash('sha256', $fixture['bytes']) !== $fixture['input_sha256']
            || self::projection($result, $fixture['format']) !== $fixture['oracle']) {
            return false;
        }
        if (($result['parser_engine'] ?? null) !== 'nntmux-rust-ffi') {
            return ($result['summary']['data_size'] ?? null) === strlen($fixture['bytes']);
        }
        if (($result['sample_prefix_identity']['md5'] ?? null) !== md5(substr($fixture['bytes'], 0, 16384))
            || ($result['candidate_scan_truncated_formats'] ?? null) !== []) {
            return false;
        }
        $header = $result['headers'][0] ?? [];

        return match ($fixture['format']) {
            'rar4' => ($header['main_header_available'] ?? false) && ($header['main_header_checksum_valid'] ?? false),
            'rar5' => ($header['first_block_header_available'] ?? false) && ($header['first_block_header_checksum_valid'] ?? false),
            'zip' => ($header['fixed_header_available'] ?? false) && ($header['filename_complete'] ?? false) && ($header['extra_field_complete'] ?? false),
            'seven_zip' => ($header['start_header_crc_valid'] ?? false) && ($header['next_header_checksum_valid'] ?? false) && ($header['header_range_within_sample'] ?? false),
            default => false,
        };
    }

    private static function uint(string $bytes, int $offset, int $width): int
    {
        if ($offset < 0 || $offset + $width > strlen($bytes)) {
            throw new RuntimeException('Oracle header is truncated.');
        }
        $value = unpack(match ($width) {
            2 => 'v', 4 => 'V', 8 => 'P', default => throw new InvalidArgumentException('Unsupported oracle integer width.')
        }, substr($bytes, $offset, $width))[1];
        if (! is_int($value) || $value < 0) {
            throw new RuntimeException('Oracle integer is outside supported range.');
        }

        return $value;
    }

    private static function vint(string $bytes, int &$cursor): int
    {
        $value = 0;
        for ($shift = 0; $shift < 56 && $cursor < strlen($bytes); $shift += 7) {
            $byte = ord($bytes[$cursor++]);
            $value |= ($byte & 127) << $shift;
            if (($byte & 128) === 0) {
                return $value;
            }
        }
        throw new RuntimeException('Oracle vint unsupported or truncated.');
    }
}
