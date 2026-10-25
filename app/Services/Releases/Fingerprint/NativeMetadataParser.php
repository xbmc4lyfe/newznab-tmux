<?php

declare(strict_types=1);

namespace App\Services\Releases\Fingerprint;

use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

final class NativeMetadataParser
{
    public const NNTP_HEADER_SCHEMA_VERSION = 3;

    public const ARTICLE_DATES_SCHEMA_VERSION = 2;

    /**
     * Wave 47.2 production default for summarizeFrameBatch() is Rust: the paired benchmark in
     * tests/Unit/RustFingerprintDispatchBreakerParserAnalyzerCollectorW47ParityTest.php measures 3.86-3.93x mean and
     * 2.59-3.99x p99 on common batches and 4.78-5.23x mean and 3.99-6.11x p99 on uncommon batches. PHP stays the
     * fallback when the library is unavailable.
     */
    public const bool W47_PARSER_RUST_FFI_DEFAULT = true;

    public const string W47_FFI_CDEF = <<<'C'
        ssize_t nntmux_w47_metadata_frame_batch(const char *blob, size_t blob_len, const char *aux, size_t aux_len, int64_t reserved_a, int64_t reserved_b, char *out_buf, size_t out_cap);
        C;

    /** Length-table sentinel for a non-string frame, and packMetadataField()'s null field. */
    public const int W47_NULL = 0xFFFFFFFF;

    private const array W47_PARSER_KEYS = ['total', 'invalid', 'identity_frames', 'xref_frames', 'unknown_magic', 'truncated', 'malformed', 'trailing',
        'fields', 'null_fields', 'payload_bytes', 'max_field_bytes', 'header_lines', 'xref_lines', 'bracketed_message_ids', 'crc32_sum', 'digest'];

    private static ?bool $w47ParserRustFfiOverride = null;

    private static FFI|false|null $w47ParserRustFfiHandle = null;

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

    /** Force the Wave 47.2 frame summary onto Rust (true) or PHP (false); null restores W47_PARSER_RUST_FFI_DEFAULT. */
    public static function useW47ParserRustFfi(?bool $enable = true): void
    {
        self::$w47ParserRustFfiOverride = $enable;
    }

    public static function usesW47ParserRustFfi(): bool
    {
        return self::$w47ParserRustFfiOverride ?? self::W47_PARSER_RUST_FFI_DEFAULT;
    }

    public static function resetW47ParserRustFfi(): void
    {
        self::$w47ParserRustFfiOverride = null;
        self::$w47ParserRustFfiHandle = null;
    }

    /**
     * Summarize packed identity (AI02: requested id, headers, then nullable overview, HEAD and BODY texts) and xref
     * (XR01: headers and two packMetadataLines() lists) frames as parseArticleIdentity() and parseArticleXref()
     * build them. Each string frame adds its crc32() and lands in exactly one outcome: under four bytes or a field
     * past the end is truncated, an unknown magic is unknown, a null required field or a broken line list is
     * malformed, bytes after the last field are trailing; the rest are identity or xref frames, whose fields,
     * payload bytes, header newlines, line counts and bracketed ids are counted and whose bytes feed one FNV-1a digest.
     *
     * @param  array<array-key, mixed>  $frames
     * @return array{total: int, invalid: int, identity_frames: int, xref_frames: int, unknown_magic: int, truncated: int, malformed: int, trailing: int, fields: int, null_fields: int, payload_bytes: int, max_field_bytes: int, header_lines: int, xref_lines: int, bracketed_message_ids: int, crc32_sum: int, digest: int}
     */
    public static function summarizeFrameBatch(array $frames): array
    {
        return (self::usesW47ParserRustFfi() ? self::summarizeFrameBatchNative($frames) : null)
            ?? self::summarizeFrameBatchPhp($frames);
    }

    /**
     * @param  array<array-key, mixed>  $frames
     * @return array{total: int, invalid: int, identity_frames: int, xref_frames: int, unknown_magic: int, truncated: int, malformed: int, trailing: int, fields: int, null_fields: int, payload_bytes: int, max_field_bytes: int, header_lines: int, xref_lines: int, bracketed_message_ids: int, crc32_sum: int, digest: int}
     */
    public static function summarizeFrameBatchPhp(array $frames): array
    {
        // invalid, identity, xref, unknown, truncated, malformed, trailing, fields, null_fields, payload_bytes,
        // max_field_bytes, header_lines, xref_lines, bracketed_message_ids, crc32_sum
        $c = array_fill(0, 15, 0);
        $valid = [];
        foreach ($frames as $frame) {
            if (! \is_string($frame)) {
                $c[0]++;

                continue;
            }
            $c[14] += crc32($frame);
            $length = \strlen($frame);
            if ($length < 4) {
                $c[4]++;

                continue;
            }
            $magic = substr($frame, 0, 4);
            if ($magic !== 'AI02' && $magic !== 'XR01') {
                $c[3]++;

                continue;
            }
            $identity = $magic === 'AI02';
            $expected = $identity ? 5 : 3;
            $fields = [];
            $pos = 4;
            $outcome = 0;
            for ($index = 0; $index < $expected; $index++) {
                if ($pos + 4 > $length) {
                    $outcome = 4;
                    break;
                }
                /** @var array{1: int} $header */
                $header = unpack('V', $frame, $pos);
                $fieldLength = $header[1];
                $pos += 4;
                if ($fieldLength === self::W47_NULL) {
                    if (! $identity || $index < 2) {
                        $outcome = 5;
                        break;
                    }
                    $fields[] = null;

                    continue;
                }
                if ($fieldLength > $length - $pos) {
                    $outcome = 4;
                    break;
                }
                $fields[] = substr($frame, $pos, $fieldLength);
                $pos += $fieldLength;
            }
            $lines = 0;
            if ($outcome === 0 && ! $identity) {
                $left = self::w47FrameLines((string) $fields[1]);
                $right = self::w47FrameLines((string) $fields[2]);
                if ($left === null || $right === null) {
                    $outcome = 5;
                } else {
                    $lines = $left + $right;
                }
            }
            if ($outcome === 0 && $pos !== $length) {
                $outcome = 6;
            }
            if ($outcome !== 0) {
                $c[$outcome]++;

                continue;
            }
            $c[$identity ? 1 : 2]++;
            $c[7] += $expected;
            foreach ($fields as $field) {
                if ($field === null) {
                    $c[8]++;
                } else {
                    $c[9] += \strlen($field);
                    $c[10] = max($c[10], \strlen($field));
                }
            }
            $c[11] += substr_count((string) $fields[$identity ? 1 : 0], "\n");
            $c[12] += $lines;
            if ($identity) {
                $id = (string) $fields[0];
                $c[13] += \strlen($id) > 2 && $id[0] === '<' && $id[-1] === '>' ? 1 : 0;
            }
            $valid[] = $frame;
        }

        return array_combine(self::W47_PARSER_KEYS, [\count($frames), ...$c, FingerprintDispatch::w47Fnv(implode('', $valid))]);
    }

    /**
     * Null when the library is missing or the batch exceeds the native limits.
     *
     * @param  array<array-key, mixed>  $frames
     * @return array{total: int, invalid: int, identity_frames: int, xref_frames: int, unknown_magic: int, truncated: int, malformed: int, trailing: int, fields: int, null_fields: int, payload_bytes: int, max_field_bytes: int, header_lines: int, xref_lines: int, bracketed_message_ids: int, crc32_sum: int, digest: int}|null
     */
    public static function summarizeFrameBatchNative(array $frames): ?array
    {
        $ffi = self::w47ParserRustFfi();
        if ($ffi === null || \count($frames) > FingerprintDispatch::W47_MAX_ITEMS) {
            return null;
        }
        $lengths = [];
        $strings = true;
        foreach ($frames as $frame) {
            if (\is_string($frame)) {
                $lengths[] = \strlen($frame);
            } else {
                $lengths[] = self::W47_NULL;
                $strings = false;
            }
        }
        $blob = implode('', $strings ? $frames : array_filter($frames, \is_string(...)));
        $values = FingerprintDispatch::callW47Native($ffi, 'nntmux_w47_metadata_frame_batch', $blob, pack('V*', ...$lengths), 0, 0,
            \count(self::W47_PARSER_KEYS));
        if ($values === null || $values[0] !== \count($frames) || min(\array_slice($values, 0, 16)) < 0
            || array_sum(\array_slice($values, 1, 7)) !== $values[0]) {
            return null;
        }

        return array_combine(self::W47_PARSER_KEYS, $values);
    }

    /** The line count of one packMetadataLines() list that its bytes hold exactly, or null. */
    private static function w47FrameLines(string $field): ?int
    {
        $length = \strlen($field);
        if ($length < 4) {
            return null;
        }
        /** @var array{1: int} $header */
        $header = unpack('V', $field);
        $count = $header[1];
        if ($count > intdiv($length - 4, 4)) {
            return null;
        }
        $pos = 4;
        for ($i = 0; $i < $count; $i++) {
            if ($pos + 4 > $length) {
                return null;
            }
            /** @var array{1: int} $line */
            $line = unpack('V', $field, $pos);
            $pos += 4;
            if ($line[1] === self::W47_NULL || $line[1] > $length - $pos) {
                return null;
            }
            $pos += $line[1];
        }

        return $pos === $length ? $count : null;
    }

    private static function w47ParserRustFfi(): ?FFI
    {
        self::$w47ParserRustFfiHandle ??= RustPipelineLibrary::resolveDomain(FingerprintDispatch::W47_FFI_DOMAIN, self::W47_FFI_CDEF) ?? false;

        return self::$w47ParserRustFfiHandle === false ? null : self::$w47ParserRustFfiHandle;
    }
}
