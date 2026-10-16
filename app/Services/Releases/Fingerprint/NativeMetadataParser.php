<?php

declare(strict_types=1);

namespace App\Services\Releases\Fingerprint;

use FFI;
use Throwable;

final class NativeMetadataParser
{
    public const NNTP_HEADER_SCHEMA_VERSION = 3;

    public const ARTICLE_DATES_SCHEMA_VERSION = 2;

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

    /** @param array<string, mixed>|null $metadata */
    public static function hasCurrentArticleDatesSchema(?array $metadata): bool
    {
        if (($metadata['parser_engine'] ?? null) !== 'nntmux-rust-article-dates'
            || ($metadata['schema_version'] ?? null) !== self::ARTICLE_DATES_SCHEMA_VERSION
            || ! is_array($metadata['reported_clock_relations'] ?? null)) {
            return false;
        }
        $sources = ['date', 'injection_date', 'legacy_nntp_posting_date', 'expires'];
        foreach ($sources as $source) {
            $value = $metadata[$source] ?? null;
            if (! is_array($value) || ! in_array($value['parse_status'] ?? null, [
                'valid', 'missing', 'duplicate', 'budget_exceeded', 'malformed_or_unsupported_absolute_date',
                'invalid_octet_or_folding', 'unsupported_absolute_timezone', 'invalid_calendar_or_time',
                'weekday_calendar_mismatch',
            ], true)
                || ! array_key_exists('utc_epoch_seconds', $value)
                || ($value['parse_status'] === 'valid'
                    ? ! is_int($value['utc_epoch_seconds']) : $value['utc_epoch_seconds'] !== null)) {
                return false;
            }
        }
        foreach ($sources as $source) {
            $value = $metadata[$source];
            $candidates = $value['candidates'] ?? null;
            $count = $value['occurrence_count'] ?? null;
            if (! is_int($count) || $count < 0 || $count > 256
                || ! is_string($value['occurrence_count_basis'] ?? null)
                || trim($value['occurrence_count_basis']) === ''
                || ! is_array($candidates) || ! array_is_list($candidates) || count($candidates) !== $count) {
                return false;
            }
            foreach ($candidates as $candidate) {
                if (! is_array($candidate) || ! in_array($candidate['parse_status'] ?? null, [
                    'valid', 'malformed_or_unsupported_absolute_date', 'invalid_octet_or_folding',
                    'unsupported_absolute_timezone', 'invalid_calendar_or_time', 'weekday_calendar_mismatch',
                ], true) || ! array_key_exists('utc_epoch_seconds', $candidate)
                    || ($candidate['parse_status'] === 'valid'
                        ? ! is_int($candidate['utc_epoch_seconds']) : $candidate['utc_epoch_seconds'] !== null)
                    || ! is_array($candidate['source_line_indices'] ?? null)
                    || ! array_is_list($candidate['source_line_indices']) || $candidate['source_line_indices'] === []) {
                    return false;
                }
                $previousLine = 0;
                foreach ($candidate['source_line_indices'] as $line) {
                    if (! is_int($line) || $line <= $previousLine || $line > 65_536) {
                        return false;
                    }
                    $previousLine = $line;
                }
            }
            if ($value['parse_status'] === 'budget_exceeded' && ! in_array($count, [0, 256], true)) {
                return false;
            }
            $status = $value['parse_status'];
            if ($status === 'missing' ? $count !== 0
                : ($status === 'duplicate' ? ($count < 2 || $count >= 256)
                    : ($status !== 'budget_exceeded' && ($count !== 1
                        || $candidates[0]['parse_status'] !== $status
                        || $candidates[0]['utc_epoch_seconds'] !== $value['utc_epoch_seconds'])))) {
                return false;
            }
        }
        foreach ([
            ['date', 'injection_date'],
            ['date', 'legacy_nntp_posting_date'],
            ['injection_date', 'legacy_nntp_posting_date'],
            ['date', 'expires'],
            ['injection_date', 'expires'],
            ['legacy_nntp_posting_date', 'expires'],
        ] as [$lhs, $rhs]) {
            $relation = $metadata['reported_clock_relations'][$lhs.'_to_'.$rhs] ?? null;
            if (! is_array($relation)
                || ($relation['lhs_parse_status'] ?? null) !== $metadata[$lhs]['parse_status']
                || ($relation['rhs_parse_status'] ?? null) !== $metadata[$rhs]['parse_status']
                || ! array_key_exists('rhs_minus_lhs_seconds', $relation)
                || ! array_key_exists('lhs_order_relative_to_rhs', $relation)) {
                return false;
            }
            $available = $metadata[$lhs]['parse_status'] === 'valid' && $metadata[$rhs]['parse_status'] === 'valid';
            $delta = $available ? $metadata[$rhs]['utc_epoch_seconds'] - $metadata[$lhs]['utc_epoch_seconds'] : null;
            $status = ! $available ? 'source_unavailable' : (is_int($delta) ? 'compared' : 'epoch_difference_out_of_range');
            $order = $status === 'compared' ? ($delta > 0 ? 'before' : ($delta < 0 ? 'after' : 'equal')) : null;
            if (($relation['comparison_status'] ?? null) !== $status
                || $relation['rhs_minus_lhs_seconds'] !== ($status === 'compared' ? $delta : null)
                || $relation['lhs_order_relative_to_rhs'] !== $order) {
                return false;
            }
        }

        return true;
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
