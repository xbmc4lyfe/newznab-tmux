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

    /** @var array<string, FFI|null> */
    private static array $par2IntegerRangeLibraries = [];

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

    /** @return array{metadata: array<string, mixed>, unsupported_range_count: int}|null */
    public function parsePar2WithIntegerRanges(string $bytes): ?array
    {
        if (PHP_INT_SIZE !== 8 || strlen($bytes) > 67_108_864) {
            return null;
        }
        $library = $this->par2IntegerRangeLibrary();
        if ($library === null) {
            return null;
        }
        try {
            $length = strlen($bytes);
            $buffer = $library->new($length > 0 ? 'unsigned char['.$length.']' : 'unsigned char[1]');
            if ($length > 0) {
                FFI::memcpy($buffer, $bytes, $length);
            }
            $count = $library->new('uint64_t[1]');
            $pointer = $library->nntmux_par2_integer_ranges_v1_json($buffer, $length, $count); // @phpstan-ignore method.notFound
            if (FFI::isNull($pointer)) {
                return null;
            }
            try {
                $encoded = FFI::string($pointer);
            } finally {
                $library->nntmux_metadata_string_free($pointer); // @phpstan-ignore method.notFound
            }
            $unsupportedRangeCount = $count[0];
            if (! is_int($unsupportedRangeCount) || $unsupportedRangeCount < 0) {
                return null;
            }
            $metadata = json_decode($encoded, true, 512, JSON_BIGINT_AS_STRING);

            return is_array($metadata) ? ['metadata' => $metadata, 'unsupported_range_count' => $unsupportedRangeCount] : null;
        } catch (Throwable) {
            return null;
        }
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

    private function par2IntegerRangeLibrary(): ?FFI
    {
        if (PHP_OS_FAMILY !== 'Linux' || PHP_SAPI !== 'cli' || ! extension_loaded('FFI')) {
            return null;
        }
        $resolved = realpath((string) config('yenc.native_library', ''));
        if ($resolved === false || ! is_file($resolved) || ! is_readable($resolved)) {
            return null;
        }
        $key = getmypid().':'.$resolved;
        if (array_key_exists($key, self::$par2IntegerRangeLibraries)) {
            return self::$par2IntegerRangeLibraries[$key];
        }
        try {
            // Optional binding: older libraries retain every existing native API.
            $library = FFI::cdef(<<<'C'
                char* nntmux_par2_integer_ranges_v1_json(const unsigned char* data, size_t length, uint64_t* unsupported_count);
                void nntmux_metadata_string_free(char* value);
                C, $resolved);
            self::$par2IntegerRangeLibraries[$key] = $library;

            return $library;
        } catch (Throwable) {
            self::$par2IntegerRangeLibraries[$key] = null;

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
