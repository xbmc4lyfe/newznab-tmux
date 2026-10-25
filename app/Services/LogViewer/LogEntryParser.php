<?php

declare(strict_types=1);

namespace App\Services\LogViewer;

use App\Support\ReleaseSearchIndexDocument;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Recognises Monolog line headers and turns raw log text into {@see LogEntry} objects.
 *
 * Monolog writes `[2026-10-06 18:11:29] channel.LEVEL: message {context}` on the first line of an
 * entry; stack traces and multi-line context follow on continuation lines without a header.
 * Files that never contain a header (Horizon/supervisor console output) are treated as one entry per line.
 */
class LogEntryParser
{
    /**
     * @var list<string>
     */
    public const array LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    public const string FFI_CDEF = <<<'C'
        int64_t nntmux_log_parse_header_spans(const char *line, size_t line_len);
        int64_t nntmux_log_plain_timestamp_span(const char *line, size_t line_len);
        ssize_t nntmux_log_is_header_batch_nul(const char *input, size_t input_len, size_t count, char *out_buf, size_t out_cap);
        ssize_t nntmux_log_parse_headers_batch_nul(const char *input, size_t input_len, size_t count, char *out_buf, size_t out_cap);
        ssize_t nntmux_log_parse_entries_block(const char *input, size_t input_len, uint32_t structured, size_t max_entry_bytes, int64_t *meta_out, size_t meta_cap, char *out_buf, size_t out_cap);
        int32_t nntmux_donor_validate_canonical_single(int64_t predb_id, const char *title, size_t title_len, const char *search_name, size_t search_name_len);
        int32_t nntmux_donor_select_canonical_batch_nul(const char *input, size_t input_len, size_t count);
        int32_t nntmux_donor_select_best_index(const int64_t *pairs, size_t count, int64_t target_size, int64_t tolerance_percent);
        int32_t nntmux_donor_select_best_nul(const char *input, size_t input_len, size_t count, int64_t target_size, int64_t tolerance_percent);
        ssize_t nntmux_book_isbn_normalize(const char *input, size_t input_len, uint32_t mode, char *out_buf, size_t out_cap);
        int32_t nntmux_book_isbn_equivalent(const char *left, size_t left_len, const char *right, size_t right_len);
        ssize_t nntmux_book_isbn_normalize_batch_nul(const char *input, size_t input_len, size_t count, uint32_t mode, char *out_buf, size_t out_cap);
        C;

    private const string HEADER_PATTERN = '/^\[(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[+-]\d{2}:?\d{2}|Z)?)\]\s+([\w.\-]+)\.([A-Z]+):\s?(.*)$/s';

    private const string PLAIN_TIMESTAMP_PATTERN = '/^\s*\[?(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2})/';

    /**
     * The Wave 44.5 summary defaults to Rust: every common and uncommon mean and p99 ratio cleared 1.5x;
     * see tests/Unit/RustLogViewerIndexParserSearchPrometheusDocW44ParityTest.php.
     */
    public const bool W445_RUST_FFI_DEFAULT = true;

    private static FFI|false|null $ffi = null;

    private static ?bool $useW445RustFfi = null;

    private static ?CData $buf = null;

    private static int $cap = 0;

    private static ?CData $metaBuf = null;

    private static int $metaCap = 0;

    public function __construct(
        private readonly bool $useNative = true,
    ) {}

    public function isHeader(string $line): bool
    {
        // Single-line calls bail out to PHP per .ai/rules/rust-performance.md (< 1.5x over FFI);
        // batch/block operations use nntmux_log_is_header_batch_nul / nntmux_log_parse_entries_block.
        return $this->isHeaderPhp($line);
    }

    public function isHeaderPhp(string $line): bool
    {
        return str_starts_with($line, '[') && preg_match(self::HEADER_PATTERN, $line) === 1;
    }

    /**
     * @return array{timestamp: string, channel: string, level: string|null, message: string}|null
     */
    public function parseHeader(string $line): ?array
    {
        return $this->parseHeaderPhp($line);
    }

    /**
     * @return array{timestamp: string, channel: string, level: string|null, message: string}|null
     */
    public function parseHeaderPhp(string $line): ?array
    {
        if (! str_starts_with($line, '[') || preg_match(self::HEADER_PATTERN, $line, $matches) !== 1) {
            return null;
        }

        $level = strtolower($matches[3]);

        return [
            'timestamp' => $matches[1],
            'channel' => $matches[2],
            'level' => in_array($level, self::LEVELS, true) ? $level : null,
            'message' => $matches[4],
        ];
    }

    public function plainTimestamp(string $line): ?string
    {
        return $this->plainTimestampPhp($line);
    }

    public function plainTimestampPhp(string $line): ?string
    {
        return preg_match(self::PLAIN_TIMESTAMP_PATTERN, $line, $matches) === 1 ? $matches[1] : null;
    }

    /** Pass null to restore {@see W445_RUST_FFI_DEFAULT}. */
    public static function useW445RustFfi(?bool $enable = true): void
    {
        self::$useW445RustFfi = $enable;
    }

    public static function usesW445RustFfi(): bool
    {
        return self::$useW445RustFfi ?? self::W445_RUST_FFI_DEFAULT;
    }

    public static function resetW445RustFfi(): void
    {
        self::$useW445RustFfi = null;
    }

    /**
     * Summarizes raw lines as {@see parseHeaderPhp()} and {@see plainTimestampPhp()} read them: `headers`
     * with one count per level (`unknown_level` for other levels), `distinct_channels` and
     * `max_message_bytes`; header-less lines count as `plain_timestamps` or, when trim() leaves nothing,
     * `blank`. Non-strings are invalid.
     *
     * @param  array<array-key, mixed>  $lines
     * @return array{lines: int, invalid: int, headers: int, debug: int, info: int, notice: int, warning: int, error: int, critical: int, alert: int, emergency: int, unknown_level: int, plain_timestamps: int, blank: int, distinct_channels: int, max_message_bytes: int}
     */
    public function summarizeLogLineBatch(array $lines): array
    {
        return self::usesW445RustFfi() ? $this->summarizeLogLineBatchNative($lines) : $this->summarizeLogLineBatchPhp($lines);
    }

    /**
     * @param  array<array-key, mixed>  $lines
     * @return array{lines: int, invalid: int, headers: int, debug: int, info: int, notice: int, warning: int, error: int, critical: int, alert: int, emergency: int, unknown_level: int, plain_timestamps: int, blank: int, distinct_channels: int, max_message_bytes: int}
     */
    public function summarizeLogLineBatchPhp(array $lines): array
    {
        $summary = ['lines' => \count($lines), 'invalid' => 0, 'headers' => 0, 'debug' => 0, 'info' => 0, 'notice' => 0, 'warning' => 0, 'error' => 0, 'critical' => 0, 'alert' => 0, 'emergency' => 0, 'unknown_level' => 0, 'plain_timestamps' => 0, 'blank' => 0, 'distinct_channels' => 0, 'max_message_bytes' => 0];
        $channels = [];
        foreach ($lines as $line) {
            if (! \is_string($line)) {
                $summary['invalid']++;

                continue;
            }
            $header = $this->parseHeaderPhp($line);
            if ($header !== null) {
                $summary['headers']++;
                $summary[$header['level'] ?? 'unknown_level']++;
                $channels[$header['channel']] = true;
                $summary['max_message_bytes'] = max($summary['max_message_bytes'], \strlen($header['message']));
            } elseif ($this->plainTimestampPhp($line) !== null) {
                $summary['plain_timestamps']++;
            } elseif (trim($line) === '') {
                $summary['blank']++;
            }
        }
        $summary['distinct_channels'] = \count($channels);

        return $summary;
    }

    /**
     * @param  array<array-key, mixed>  $lines
     * @return array{lines: int, invalid: int, headers: int, debug: int, info: int, notice: int, warning: int, error: int, critical: int, alert: int, emergency: int, unknown_level: int, plain_timestamps: int, blank: int, distinct_channels: int, max_message_bytes: int}
     */
    public function summarizeLogLineBatchNative(array $lines): array
    {
        $strings = array_values(array_filter($lines, 'is_string'));
        $fields = ReleaseSearchIndexDocument::evaluateW445Native(2, $strings, 14);
        if ($fields === null) {
            return $this->summarizeLogLineBatchPhp($lines);
        }

        return ['lines' => \count($lines), 'invalid' => \count($lines) - \count($strings)]
            + array_combine(['headers', ...self::LEVELS, 'unknown_level', 'plain_timestamps', 'blank', 'distinct_channels', 'max_message_bytes'], $fields);
    }

    /**
     * Splits and parses a multi-line log block into {@see LogEntry} objects.
     *
     * @return list<LogEntry>
     */
    public function parseEntriesBlock(
        string $block,
        bool $structured = true,
        int $baseOffset = 0,
        int $maxEntryBytes = 32768,
        ?int $startLine = 1,
    ): array {
        if ($block === '' || $maxEntryBytes <= 0) {
            return [];
        }

        if ($this->useNative && $structured) {
            $ffi = self::$ffi ??= self::initFfi();
            if ($ffi !== false) {
                try {
                    $inLen = \strlen($block);
                    $needed = max(1024, $inLen + 512);
                    $cap = self::$cap;
                    $scratch = (self::$buf !== null && $cap >= $needed)
                        ? self::$buf
                        : RustPipelineLibrary::obtainScratch($ffi, $needed, $cap);
                    $neededMeta = max(512, ($inLen >> 3) + 64);
                    if (self::$metaBuf === null || self::$metaCap < $neededMeta) {
                        self::$metaBuf = $ffi->new("int64_t[{$neededMeta}]");
                        self::$metaCap = $neededMeta;
                    }
                    $meta = self::$metaBuf;
                    $metaCap = self::$metaCap;
                    if ($scratch !== null) {
                        self::$buf = $scratch;
                        self::$cap = $cap;
                        // @phpstan-ignore method.notFound
                        $written = $ffi->nntmux_log_parse_entries_block($block, $inLen, 1, $maxEntryBytes, $meta, $metaCap, $scratch, $cap);
                        if (\is_int($written) && $written < -1) {
                            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                            if ($scratch !== null) {
                                self::$buf = $scratch;
                                self::$cap = $cap;
                                // @phpstan-ignore method.notFound
                                $written = $ffi->nntmux_log_parse_entries_block($block, $inLen, 1, $maxEntryBytes, $meta, $metaCap, $scratch, $cap);
                            }
                        }
                        if (\is_int($written) && $written >= 0 && $scratch !== null) {
                            if ($written === 0) {
                                return [];
                            }
                            $parts = \explode("\0", FFI::string($scratch, $written));
                            $entryCount = \count($parts) >> 2;
                            $entries = [];
                            for ($e = 0, $p = 0, $m = 0; $e < $entryCount; $e++, $p += 4, $m += 2) {
                                /** @var int $w0 */
                                $w0 = $meta[$m];
                                /** @var int $w1 */
                                $w1 = $meta[$m + 1];
                                $ts = $parts[$p];
                                $chan = $parts[$p + 1];

                                $entries[] = new LogEntry(
                                    offset: $baseOffset + ($w0 & 0xFFFFFFFF),
                                    end: $baseOffset + (($w0 >> 32) & 0xFFFFFFFF),
                                    lineNumber: $startLine !== null ? ($startLine + ($w1 & 0xFFFFFFFF) - 1) : null,
                                    timestamp: $ts !== '' ? $ts : null,
                                    channel: $chan !== '' ? $chan : null,
                                    level: self::LEVELS[($w1 >> 32) & 0xFF] ?? null,
                                    message: $parts[$p + 2],
                                    body: $parts[$p + 3],
                                    truncated: (($w1 >> 40) & 1) === 1,
                                );
                            }

                            return $entries;
                        }
                    }
                } catch (Throwable) {
                }
            }
        }

        return $this->parseEntriesBlockPhp($block, $structured, $baseOffset, $maxEntryBytes, $startLine);
    }

    private static function initFfi(): FFI|false
    {
        $ffi = RustPipelineLibrary::resolveDomain('log_donor_isbn', self::FFI_CDEF);
        if ($ffi === null) {
            return false;
        }
        self::$buf = RustPipelineLibrary::obtainScratch($ffi, 65536, self::$cap);

        return $ffi;
    }

    /**
     * @return list<LogEntry>
     */
    public function parseEntriesBlockPhp(
        string $block,
        bool $structured = true,
        int $baseOffset = 0,
        int $maxEntryBytes = 32768,
        ?int $startLine = 1,
    ): array {
        if ($block === '' || $maxEntryBytes <= 0) {
            return [];
        }

        $entries = [];
        $len = \strlen($block);
        $pos = 0;
        $lineNo = 0;
        /** @var array{offset: int, line: int, first: string, body: list<string>, bytes: int, truncated: bool, structured: bool}|null $pending */
        $pending = null;

        while ($pos < $len) {
            $lineOffset = $pos;
            $nlPos = \strpos($block, "\n", $pos);
            if ($nlPos === false) {
                $rawLine = \substr($block, $pos);
                $nextPos = $len;
            } else {
                $rawLine = \substr($block, $pos, $nlPos - $pos);
                $nextPos = $nlPos + 1;
            }
            $text = \rtrim($rawLine, "\r");
            $isHeader = $structured && $this->isHeaderPhp($text);

            if (! $structured || $isHeader) {
                if ($pending !== null) {
                    $entries[] = $this->makePhp(
                        $baseOffset + $pending['offset'],
                        $baseOffset + $lineOffset,
                        $pending['first'],
                        \implode("\n", $pending['body']),
                        $pending['truncated'],
                        $pending['structured'],
                        $startLine !== null ? ($startLine + $pending['line'] - 1) : null,
                    );
                    $pending = null;
                }
            }

            $lineNo++;
            $firstCapped = \strlen($text) > $maxEntryBytes ? \substr($text, 0, $maxEntryBytes) : $text;
            $lineTruncated = \strlen($text) > $maxEntryBytes;

            if (! $structured) {
                if (\trim($text) !== '') {
                    $entries[] = $this->makePhp(
                        $baseOffset + $lineOffset,
                        $baseOffset + $nextPos,
                        $firstCapped,
                        '',
                        $lineTruncated,
                        false,
                        $startLine !== null ? ($startLine + $lineNo - 1) : null,
                    );
                }
            } elseif ($pending !== null) {
                if ($maxEntryBytes < $pending['bytes'] + \strlen($firstCapped) + 1) {
                    $pending['truncated'] = true;
                } else {
                    $pending['body'][] = $firstCapped;
                    $pending['bytes'] += \strlen($firstCapped) + 1;
                    $pending['truncated'] = $pending['truncated'] || $lineTruncated;
                }
            } else {
                $pending = [
                    'offset' => $lineOffset,
                    'line' => $lineNo,
                    'first' => $firstCapped,
                    'body' => [],
                    'bytes' => 0,
                    'truncated' => $lineTruncated,
                    'structured' => $isHeader,
                ];
            }

            $pos = $nextPos;
        }

        if ($pending !== null) {
            $entries[] = $this->makePhp(
                $baseOffset + $pending['offset'],
                $baseOffset + $len,
                $pending['first'],
                \implode("\n", $pending['body']),
                $pending['truncated'],
                $pending['structured'],
                $startLine !== null ? ($startLine + $pending['line'] - 1) : null,
            );
        }

        return $entries;
    }

    /**
     * Build an entry from its first line and the (already newline-joined) continuation lines.
     */
    public function make(
        int $offset,
        int $end,
        string $firstLine,
        string $body,
        bool $truncated,
        bool $structured,
        ?int $lineNumber = null,
    ): LogEntry {
        $header = $structured ? $this->parseHeader($firstLine) : null;

        if ($header !== null) {
            return new LogEntry(
                offset: $offset,
                end: $end,
                lineNumber: $lineNumber,
                timestamp: $header['timestamp'],
                channel: $header['channel'],
                level: $header['level'],
                message: $header['message'],
                body: $body,
                truncated: $truncated,
            );
        }

        return new LogEntry(
            offset: $offset,
            end: $end,
            lineNumber: $lineNumber,
            timestamp: $this->plainTimestamp($firstLine),
            channel: null,
            level: null,
            message: $firstLine,
            body: $body,
            truncated: $truncated,
        );
    }

    public function makePhp(
        int $offset,
        int $end,
        string $firstLine,
        string $body,
        bool $truncated,
        bool $structured,
        ?int $lineNumber = null,
    ): LogEntry {
        $header = $structured ? $this->parseHeaderPhp($firstLine) : null;

        if ($header !== null) {
            return new LogEntry(
                offset: $offset,
                end: $end,
                lineNumber: $lineNumber,
                timestamp: $header['timestamp'],
                channel: $header['channel'],
                level: $header['level'],
                message: $header['message'],
                body: $body,
                truncated: $truncated,
            );
        }

        return new LogEntry(
            offset: $offset,
            end: $end,
            lineNumber: $lineNumber,
            timestamp: $this->plainTimestampPhp($firstLine),
            channel: null,
            level: null,
            message: $firstLine,
            body: $body,
            truncated: $truncated,
        );
    }
}
