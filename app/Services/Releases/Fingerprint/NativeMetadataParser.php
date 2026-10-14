<?php

declare(strict_types=1);

namespace App\Services\Releases\Fingerprint;

use FFI;
use Throwable;

final class NativeMetadataParser
{
    public const NNTP_HEADER_SCHEMA_VERSION = 3;

    /** @var array<string, FFI> */
    private static array $libraries = [];

    /** @var array<string, FFI> */
    private static array $identityLibraries = [];

    /** @var array<string, FFI> */
    private static array $headerLibraries = [];

    /** @var array<string, FFI> */
    private static array $bodyLibraries = [];

    /** @var array<string, FFI> */
    private static array $xrefLibraries = [];

    /** @var array<string, FFI> */
    private static array $dateLibraries = [];

    /** @var array<string, FFI|null> */
    private static array $articleMetadataLibraries = [];

    /** @return array<string, mixed>|null */
    public function parseArticleMetadata(string $headers, string $body): ?array
    {
        if (strlen($headers) > 131_072 || strlen($body) > 2_000_000) {
            return null;
        }
        $library = $this->articleMetadataLibrary();

        return $library === null ? null : self::callArticleMetadata($library, $headers, $body);
    }

    /** @return array<string, mixed>|null */
    private static function callArticleMetadata(FFI $library, string $headers, string $body): ?array
    {
        try {
            // FFI borrows these PHP strings only for this synchronous call; lengths preserve every byte.
            $pointer = $library->nntmux_article_metadata_v1_json($headers, strlen($headers), $body, strlen($body)); // @phpstan-ignore method.notFound
            if (FFI::isNull($pointer)) {
                return null;
            }
            try {
                $decoded = json_decode(FFI::string($pointer), true, 512, JSON_BIGINT_AS_STRING);

                return is_array($decoded) && self::hasArticleMetadataSchema($decoded) ? $decoded : null;
            } finally {
                $library->nntmux_metadata_string_free($pointer); // @phpstan-ignore method.notFound
            }
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $metadata */
    private static function hasArticleMetadataSchema(array $metadata): bool
    {
        if (array_keys($metadata) !== [
            'yenc_framing_metadata', 'nntp_header_metadata', 'nntp_body_metadata', 'nntp_lines_body_relationship',
        ]) {
            return false;
        }
        $framing = $metadata['yenc_framing_metadata'];
        $headers = $metadata['nntp_header_metadata'];
        $body = $metadata['nntp_body_metadata'];
        $relationship = $metadata['nntp_lines_body_relationship'];

        return is_array($framing) && self::hasYencFramingSchema($framing)
            && is_array($headers) && ($headers['parser_engine'] ?? null) === 'nntmux-rust-nntp-headers'
            && ($headers['schema_version'] ?? null) === self::NNTP_HEADER_SCHEMA_VERSION
            && is_string($headers['status'] ?? null) && is_array($headers['fields'] ?? null)
            && is_array($body) && ($body['parser_engine'] ?? null) === 'nntmux-rust-nntp-body'
            && ($body['schema_version'] ?? null) === 1 && is_string($body['status'] ?? null)
            && is_int($body['body_octets_after_dot_unstuffing'] ?? null)
            && array_key_exists('body_line_count', $body)
            && ($body['body_line_count'] === null || is_int($body['body_line_count']))
            && is_array($relationship) && ($relationship['parser_engine'] ?? null) === 'nntmux-rust-nntp-body'
            && ($relationship['schema_version'] ?? null) === 1 && is_string($relationship['status'] ?? null)
            && array_key_exists('matches', $relationship)
            && ($relationship['matches'] === null || is_bool($relationship['matches']));
    }

    /** @param array<string, mixed> $metadata */
    private static function hasYencFramingSchema(array $metadata): bool
    {
        if (($metadata['parser_engine'] ?? null) !== 'nntmux-rust-ffi'
            || ! is_int($metadata['input_bytes'] ?? null)
            || ! is_int($metadata['frame_count'] ?? null)
            || ! is_int($metadata['control_line_count'] ?? null)
            || ! is_bool($metadata['frames_truncated'] ?? null)
            || ! is_bool($metadata['control_lines_truncated'] ?? null)
            || ! is_array($metadata['frames'] ?? null) || ! array_is_list($metadata['frames'])
            || ! is_array($metadata['lines'] ?? null) || ! array_is_list($metadata['lines'])) {
            return false;
        }
        foreach ($metadata['frames'] as $frame) {
            if (! is_array($frame) || ! is_int($frame['frame_index'] ?? null)
                || ! is_string($frame['size_relationship_status'] ?? null)) {
                return false;
            }
        }
        foreach ($metadata['lines'] as $line) {
            if (! is_array($line) || ! is_string($line['kind'] ?? null)
                || ! array_key_exists('frame_index', $line)
                || ($line['frame_index'] !== null && ! is_int($line['frame_index']))
                || ! is_bool($line['field_occurrences_truncated'] ?? null)
                || ! is_array($line['conflicting_duplicate_field_names'] ?? null)
                || ! array_is_list($line['conflicting_duplicate_field_names'])) {
                return false;
            }
            foreach ($line['conflicting_duplicate_field_names'] as $name) {
                if (! is_string($name)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function articleMetadataLibrary(): ?FFI
    {
        if (PHP_OS_FAMILY !== 'Linux' || PHP_SAPI !== 'cli' || ! extension_loaded('FFI')) {
            return null;
        }
        $resolved = realpath((string) config('yenc.native_library', ''));
        if ($resolved === false || ! is_file($resolved) || ! is_readable($resolved)) {
            return null;
        }
        $key = getmypid().':'.$resolved;
        if (array_key_exists($key, self::$articleMetadataLibraries)) {
            return self::$articleMetadataLibraries[$key];
        }
        self::$articleMetadataLibraries[$key] = null;
        try {
            $library = FFI::cdef(<<<'C'
                char* nntmux_article_metadata_v1_json(const char* headers, size_t header_length, const char* body, size_t body_length);
                void nntmux_metadata_string_free(char* value);
                C, $resolved);
            $probe = self::callArticleMetadata($library, "Lines: 1\r\n", "x\r\n");
            if ($probe === null
                || ($probe['yenc_framing_metadata']['input_bytes'] ?? null) !== 3
                || ($probe['nntp_header_metadata']['input_bytes'] ?? null) !== 10
                || ($probe['nntp_header_metadata']['status'] ?? null) !== 'complete'
                || ($probe['nntp_body_metadata']['body_octets_after_dot_unstuffing'] ?? null) !== 3
                || ($probe['nntp_body_metadata']['body_line_count'] ?? null) !== 1
                || ($probe['nntp_lines_body_relationship']['status'] ?? null) !== 'match'
                || ($probe['nntp_lines_body_relationship']['declared_body_line_count'] ?? null) !== 1
                || ($probe['nntp_lines_body_relationship']['observed_body_line_count'] ?? null) !== 1
                || ($probe['nntp_lines_body_relationship']['matches'] ?? null) !== true) {
                return null;
            }
            self::$articleMetadataLibraries[$key] = $library;

            return $library;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param list<string> $rawHeaders
     * @return array<string, mixed>|null
     */
    public function parseArticleDates(array $rawHeaders): ?array
    {
        $headerBlock = implode('', $rawHeaders);
        if (strlen($headerBlock) > 65_536) {
            return null;
        }

        return $this->parseJson('nntmux_article_dates_v1_json', $headerBlock);
    }

    /** @return array<string, mixed>|null */
    public function parsePar2(string $bytes): ?array
    {
        return $this->parseJson('nntmux_par2_metadata_json', $bytes);
    }

    /** @return array<string, mixed>|null */
    public function parseFormats(string $bytes): ?array
    {
        return $this->parseJson('nntmux_format_metadata_json', $bytes);
    }

    /** @return array<string, mixed>|null */
    public function resolveSevenZipRanges(string $packedRanges): ?array
    {
        return $this->parseJson('nntmux_seven_zip_ranges_json', $packedRanges);
    }

    /** @return array<string, mixed>|null */
    public function resolvePar2SourceRanges(string $packedRanges): ?array
    {
        return $this->parseJson('nntmux_par2_source_ranges_json', $packedRanges);
    }

    /** @return array<string, mixed>|null */
    public function parseYencFraming(string $bytes): ?array
    {
        return $this->parseJson('nntmux_yenc_framing_metadata_json', $bytes);
    }

    /** @return array<string, mixed>|null */
    public function parseNntpHeaders(string $bytes): ?array
    {
        if (strlen($bytes) > 131_072) {
            return null;
        }

        return $this->parseJson('nntmux_nntp_header_metadata_json', $bytes);
    }

    /** @return array<string, mixed>|null */
    public function compareNntpHeaders(string $left, string $right, ?bool $identityMatch): ?array
    {
        if (strlen($left) > 131_072 || strlen($right) > 131_072) {
            return null;
        }
        $identityFlag = $identityMatch === null ? -1 : (int) $identityMatch;
        $packed = pack('V', strlen($left)).$left.pack('V', strlen($right)).$right;

        return $this->parseJson('nntmux_nntp_header_pair_comparison_json', $packed, [$identityFlag]);
    }

    /** @return array<string, mixed>|null */
    public function parseNntpBody(string $bytes): ?array
    {
        if (strlen($bytes) > 2_000_000) {
            return null;
        }

        return $this->parseJson('nntmux_nntp_body_measurement_json', $bytes);
    }

    /** @return array<string, mixed>|null */
    public function compareNntpLinesToBody(string $headers, string $body): ?array
    {
        if (strlen($headers) > 131_072 || strlen($body) > 2_000_000) {
            return null;
        }
        $packed = pack('V', strlen($headers)).$headers.$body;

        return $this->parseJson('nntmux_nntp_lines_body_relationship_json', $packed);
    }

    /** @param array<string, mixed>|null $metadata */
    public static function hasCurrentNntpHeaderSchema(?array $metadata): bool
    {
        return ($metadata['parser_engine'] ?? null) === 'nntmux-rust-nntp-headers'
            && ($metadata['schema_version'] ?? null) === self::NNTP_HEADER_SCHEMA_VERSION
            && ($metadata['status'] ?? null) === 'complete';
    }

    /** @param list<string> $rawHeaders
     * @return array<string, mixed>|null
     */
    public function parseArticleIdentity(
        string $requestedMessageId,
        array $rawHeaders,
        ?string $overviewRow,
        ?int $overviewResponseCode,
        bool $overviewAttempted,
        ?string $headResponseText,
        ?string $bodyResponseText,
        ?int $headResponseCode,
        ?int $bodyResponseCode,
    ): ?array {
        $headerBlock = implode('', $rawHeaders);
        $packed = 'AI02'.self::packMetadataField($requestedMessageId)
            .self::packMetadataField($headerBlock)
            .($overviewRow === null ? "\xff\xff\xff\xff" : self::packMetadataField($overviewRow))
            .($headResponseText === null ? "\xff\xff\xff\xff" : self::packMetadataField($headResponseText))
            .($bodyResponseText === null ? "\xff\xff\xff\xff" : self::packMetadataField($bodyResponseText));

        if (strlen($packed) > 131_072) {
            return null;
        }

        return $this->parseJson('nntmux_article_identity_v2_json', $packed, [
            $overviewResponseCode ?? -1,
            (int) $overviewAttempted,
            $headResponseCode ?? -1,
            $bodyResponseCode ?? -1,
        ]);
    }

    /**
     * @param  list<string>  $rawHeaders
     * @param  list<string>  $overviewRows
     * @param  list<string>  $overviewFormat
     * @return array<string, mixed>|null
     */
    public function parseArticleXref(
        array $rawHeaders,
        array $overviewRows,
        array $overviewFormat,
        ?int $headResponseCode,
        ?int $overviewResponseCode,
        bool $overviewAttempted,
        int $overviewRowCount,
        ?int $overviewFormatResponseCode,
    ): ?array {
        $packed = 'XR01'.self::packMetadataField(implode('', $rawHeaders))
            .self::packMetadataField(self::packMetadataLines($overviewRows))
            .self::packMetadataField(self::packMetadataLines($overviewFormat));

        if (strlen($packed) > 196_608) {
            return null;
        }

        return $this->parseJson('nntmux_article_identity_xref_v1_json', $packed, [
            $headResponseCode ?? -1,
            $overviewResponseCode ?? -1,
            (int) $overviewAttempted,
            $overviewRowCount,
            $overviewFormatResponseCode ?? -1,
        ]);
    }

    /**
     * @param  list<int>  $integerArguments
     * @return array<string, mixed>|null
     */
    private function parseJson(string $function, string $bytes, array $integerArguments = []): ?array
    {
        $identityParser = $function === 'nntmux_article_identity_v2_json';
        $xrefParser = $function === 'nntmux_article_identity_xref_v1_json';
        $dateParser = $function === 'nntmux_article_dates_v1_json';
        $library = match ($function) {
            'nntmux_nntp_header_metadata_json', 'nntmux_nntp_header_pair_comparison_json',
            'nntmux_nntp_lines_body_relationship_json' => $this->headerLibrary(),
            'nntmux_nntp_body_measurement_json' => $this->bodyLibrary(),
            default => $this->library($identityParser, $xrefParser, $dateParser),
        };
        if ($library === null || strlen($bytes) > 67_108_864) {
            return null;
        }

        $encoded = null;
        try {
            $length = strlen($bytes);
            $buffer = $library->new($length > 0 ? 'unsigned char['.$length.']' : 'unsigned char[1]');
            if ($length > 0) {
                FFI::memcpy($buffer, $bytes, $length);
            }
            $pointer = $library->{$function}($buffer, $length, ...$integerArguments);
            if (FFI::isNull($pointer)) {
                return null;
            }
            try {
                $encoded = FFI::string($pointer);
            } finally {
                $library->nntmux_metadata_string_free($pointer); // @phpstan-ignore method.notFound
            }
        } catch (Throwable) {
            return null;
        }

        // Preserve exact Rust u64 values that exceed PHP_INT_MAX instead of rounding them to floats.
        $decoded = json_decode($encoded, true, 512, JSON_BIGINT_AS_STRING);

        return is_array($decoded) ? $decoded : null;
    }

    private static function packMetadataField(string $value): string
    {
        return pack('V', strlen($value)).$value;
    }

    /** @param list<string> $lines */
    private static function packMetadataLines(array $lines): string
    {
        $packed = pack('V', count($lines));
        foreach ($lines as $line) {
            $packed .= self::packMetadataField($line);
        }

        return $packed;
    }

    private function library(bool $articleIdentity = false, bool $articleXref = false, bool $articleDates = false): ?FFI
    {
        if (PHP_OS_FAMILY !== 'Linux' || PHP_SAPI !== 'cli' || ! extension_loaded('FFI')) {
            return null;
        }
        $path = (string) config('yenc.native_library', '');
        $resolved = realpath($path);
        if ($resolved === false || ! is_file($resolved) || ! is_readable($resolved)) {
            return null;
        }
        $key = getmypid().':'.$resolved;
        $libraries = match (true) {
            $articleIdentity => self::$identityLibraries,
            $articleXref => self::$xrefLibraries,
            $articleDates => self::$dateLibraries,
            default => self::$libraries,
        };
        if (isset($libraries[$key])) {
            return $libraries[$key];
        }

        try {
            if ($articleIdentity) {
                $definitions = <<<'C'
                char* nntmux_article_identity_v2_json(const unsigned char* data, size_t length, int overview_response_code, int overview_attempted, int head_response_code, int body_response_code);
                void nntmux_metadata_string_free(char* value);
                C;
            } elseif ($articleXref) {
                $definitions = <<<'C'
                char* nntmux_article_identity_xref_v1_json(const unsigned char* data, size_t length, int head_response_code, int overview_response_code, int overview_attempted, int overview_row_count, int overview_format_response_code);
                void nntmux_metadata_string_free(char* value);
                C;
            } elseif ($articleDates) {
                $definitions = <<<'C'
                char* nntmux_article_dates_v1_json(const unsigned char* data, size_t length);
                void nntmux_metadata_string_free(char* value);
                C;
            } else {
                $definitions = <<<'C'
                char* nntmux_par2_metadata_json(const unsigned char* data, size_t length);
                char* nntmux_format_metadata_json(const unsigned char* data, size_t length);
                char* nntmux_yenc_framing_metadata_json(const unsigned char* data, size_t length);
                char* nntmux_seven_zip_ranges_json(const unsigned char* data, size_t length);
                char* nntmux_par2_source_ranges_json(const unsigned char* data, size_t length);
                void nntmux_metadata_string_free(char* value);
                C;
            }
            $library = FFI::cdef($definitions, $resolved);
            if ($articleIdentity) {
                self::$identityLibraries[$key] = $library;
            } elseif ($articleXref) {
                self::$xrefLibraries[$key] = $library;
            } elseif ($articleDates) {
                self::$dateLibraries[$key] = $library;
            } else {
                self::$libraries[$key] = $library;
            }

            return $library;
        } catch (Throwable) {
            return null;
        }
    }

    private function headerLibrary(): ?FFI
    {
        if (PHP_OS_FAMILY !== 'Linux' || PHP_SAPI !== 'cli' || ! extension_loaded('FFI')) {
            return null;
        }
        $path = (string) config('yenc.native_library', '');
        $resolved = realpath($path);
        if ($resolved === false || ! is_file($resolved) || ! is_readable($resolved)) {
            return null;
        }
        $key = getmypid().':'.$resolved;
        if (isset(self::$headerLibraries[$key])) {
            return self::$headerLibraries[$key];
        }

        try {
            $definitions = <<<'C'
            char* nntmux_nntp_header_metadata_json(const unsigned char* data, size_t length);
            char* nntmux_nntp_header_pair_comparison_json(const unsigned char* data, size_t length, int identity_match);
            char* nntmux_nntp_lines_body_relationship_json(const unsigned char* data, size_t length);
            void nntmux_metadata_string_free(char* value);
            C;
            $library = FFI::cdef($definitions, $resolved);
            self::$headerLibraries[$key] = $library;

            return $library;
        } catch (Throwable) {
            return null;
        }
    }

    private function bodyLibrary(): ?FFI
    {
        if (PHP_OS_FAMILY !== 'Linux' || PHP_SAPI !== 'cli' || ! extension_loaded('FFI')) {
            return null;
        }
        $path = (string) config('yenc.native_library', '');
        $resolved = realpath($path);
        if ($resolved === false || ! is_file($resolved) || ! is_readable($resolved)) {
            return null;
        }
        $key = getmypid().':'.$resolved;
        if (isset(self::$bodyLibraries[$key])) {
            return self::$bodyLibraries[$key];
        }

        try {
            $definitions = <<<'C'
            char* nntmux_nntp_body_measurement_json(const unsigned char* data, size_t length);
            void nntmux_metadata_string_free(char* value);
            C;
            $library = FFI::cdef($definitions, $resolved);
            self::$bodyLibraries[$key] = $library;

            return $library;
        } catch (Throwable) {
            return null;
        }
    }
}
