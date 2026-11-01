<?php

declare(strict_types=1);

namespace App\Services\LogViewer;

use App\Support\RustPipelineLibrary;
use Throwable;

/**
 * One logical log entry: a Monolog header line plus its continuation lines, or a single plain line.
 *
 * `offset` and `end` are byte positions in the file; `offset` doubles as the pagination cursor.
 */
final readonly class LogEntry
{
    /**
     * Benchmark-gated default for {@see classifyEntryBatch()}: Rust cleared every 1.5x mean/p99 gate
     * (min 1.70x); see tests/Unit/RustLogViewerEntryFileRepoReaderReleaseSearchW48ParityTest.php.
     */
    public const bool W48_LOG_ENTRY_RUST_FFI_DEFAULT = true;

    /**
     * The {@see LogEntryParser} header prefix through the level colon: channel and level.
     */
    public const string W48_HEADER_PATTERN = '/^\[\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[+-]\d{2}:?\d{2}|Z)?\]\s+([\w.\-]+)\.([A-Z]+):/';

    /**
     * Monolog level ranks; other upper-case levels rank 0.
     *
     * @var array<string, int>
     */
    public const array W48_SEVERITY = [
        'DEBUG' => 1,
        'INFO' => 2,
        'NOTICE' => 3,
        'WARNING' => 4,
        'ERROR' => 5,
        'CRITICAL' => 6,
        'ALERT' => 7,
        'EMERGENCY' => 8,
    ];

    public function __construct(
        public int $offset,
        public int $end,
        public ?int $lineNumber,
        public ?string $timestamp,
        public ?string $channel,
        public ?string $level,
        public string $message,
        public string $body,
        public bool $truncated,
    ) {}

    /**
     * The entry's timestamp as Unix seconds (read in the application timezone), or null when it has none.
     *
     * Rust is the default: it beat strtotime() by at least 1.5x on every paired metric
     * (RustSearchCursorAndLogIndexParityTest); unsupported shapes fall back to PHP.
     */
    public function loggedAt(bool $useRustFfi = true): ?int
    {
        $supported = false;
        $native = $useRustFfi ? $this->loggedAtNative($supported) : null;

        return $supported ? $native : $this->loggedAtPhp();
    }

    public function loggedAtPhp(): ?int
    {
        if ($this->timestamp === null) {
            return null;
        }

        $seconds = strtotime($this->timestamp);

        return $seconds === false ? null : $seconds;
    }

    /**
     * Rust `strtotime()` for canonical Monolog/plain timestamps in a UTC default zone.
     * `$supported` stays false (PHP decides) for other zones, shapes and overflowing dates.
     *
     * @param-out bool $supported
     */
    public function loggedAtNative(bool &$supported = false): ?int
    {
        $supported = false;
        if ($this->timestamp === null) {
            $supported = true;

            return null;
        }
        if (date_default_timezone_get() !== 'UTC') {
            return null;
        }
        static $ffi, $slot;
        $ffi ??= RustPipelineLibrary::resolveDomain('search', 'int32_t nntmux_log_entry_logged_at(const char *input, size_t len, uint32_t default_utc, int64_t *out);') ?? false;
        if ($ffi === false) {
            return null;
        }
        try {
            $slot ??= $ffi->new('int64_t[1]');
            if ($ffi->nntmux_log_entry_logged_at($this->timestamp, strlen($this->timestamp), 1, $slot) !== 1) {
                return null;
            }
            $supported = true;

            return (int) $slot[0];
        } catch (Throwable) {
            return null;
        }
    }

    /** Pass null to restore {@see W48_LOG_ENTRY_RUST_FFI_DEFAULT}. */
    public static function useW48LogEntryRustFfi(?bool $enable = true): void
    {
        self::w48LogEntryToggle(true, $enable);
    }

    public static function usesW48LogEntryRustFfi(): bool
    {
        return self::w48LogEntryToggle() ?? self::W48_LOG_ENTRY_RUST_FFI_DEFAULT;
    }

    public static function resetW48LogEntryRustFfi(): void
    {
        self::w48LogEntryToggle(true, null);
    }

    /**
     * Classifies raw entry texts (first line plus continuation lines). Per entry: `[header, severity,
     * channel_fnv32, context_offset, stack_frames, lines, exception_flags, fingerprint]` — whether the
     * first line matches {@see W48_HEADER_PATTERN}, its {@see W48_SEVERITY} rank, the FNV-1a 32 hash of
     * the channel (0 without a header), the offset of the trailing ` {...}` context on the first line
     * (-1 without one), the continuation lines starting with `#<digit>`, the line count, flags (1
     * `[stacktrace]`, 2 `Stack trace:`, 4 `Exception`, 8 `{"exception":`) and the FNV-1a 64 hash of
     * the right-trimmed first line from the channel on (the whole line without a header).
     *
     * @param  list<string>  $texts
     * @return list<list<int>>
     */
    public static function classifyEntryBatch(array $texts): array
    {
        return self::usesW48LogEntryRustFfi() ? self::classifyEntryBatchNative($texts) : self::classifyEntryBatchPhp($texts);
    }

    /**
     * @param  list<string>  $texts
     * @return list<list<int>>
     */
    public static function classifyEntryBatchPhp(array $texts): array
    {
        $rows = [];

        foreach ($texts as $text) {
            $newline = strpos($text, "\n");
            $first = $newline === false ? $text : substr($text, 0, $newline);
            $body = $newline === false ? '' : substr($text, $newline + 1);
            $trimmed = rtrim($first, " \t\r");
            $header = $severity = $channel = $from = 0;

            if (preg_match(self::W48_HEADER_PATTERN, $first, $match, PREG_OFFSET_CAPTURE) === 1) {
                $header = 1;
                $severity = self::W48_SEVERITY[$match[2][0]] ?? 0;
                $channel = self::w48Hash32($match[1][0]);
                $from = $match[1][1];
            }

            $context = -1;
            if (str_ends_with($trimmed, '}')) {
                $position = strrpos($trimmed, ' {');
                $context = $position === false ? -1 : $position + 1;
            }

            $rows[] = [
                $header,
                $severity,
                $channel,
                $context,
                (int) preg_match_all('/(?:\A|\n)#\d/', $body),
                $text === '' ? 0 : substr_count($text, "\n") + 1,
                (str_contains($text, '[stacktrace]') ? 1 : 0)
                    | (str_contains($text, 'Stack trace:') ? 2 : 0)
                    | (str_contains($text, 'Exception') ? 4 : 0)
                    | (str_contains($text, '{"exception":') ? 8 : 0),
                self::w48Hash64(substr($trimmed, $from)),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<string>  $texts
     * @return list<list<int>>
     */
    public static function classifyEntryBatchNative(array $texts): array
    {
        $count = \count($texts);

        if ($count === 0) {
            return [];
        }

        $raw = LogReader::evaluateW48Native(1, pack('P', $count).LogReader::w48Strings($texts), 64 * $count);

        return $raw === null || \strlen($raw) !== 64 * $count ? self::classifyEntryBatchPhp($texts) : LogReader::w48Rows($raw, 8);
    }

    /** PHP `hash('fnv1a64')` as a signed integer. */
    public static function w48Hash64(string $value): int
    {
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('J', hash('fnv1a64', $value, true));

        return $unpacked[1];
    }

    /** PHP `hash('fnv1a32')` as an unsigned 32-bit integer. */
    public static function w48Hash32(string $value): int
    {
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('N', hash('fnv1a32', $value, true));

        return $unpacked[1];
    }

    /**
     * Readonly classes cannot hold static properties, so the toggle lives in a function static.
     */
    private static function w48LogEntryToggle(bool $write = false, ?bool $value = null): ?bool
    {
        static $toggle = null;

        if ($write) {
            $toggle = $value;
        }

        return $toggle;
    }

    /**
     * @return array{
     *     offset: int,
     *     end: int,
     *     line: int|null,
     *     timestamp: string|null,
     *     channel: string|null,
     *     level: string|null,
     *     message: string,
     *     body: string,
     *     truncated: bool,
     *     message_segments: list<array{text: string, hit: bool}>|null,
     *     body_segments: list<array{text: string, hit: bool}>|null
     * }
     */
    public function toArray(?SearchQuery $query = null): array
    {
        $highlight = $query !== null && $query->hasTerm();

        return [
            'offset' => $this->offset,
            'end' => $this->end,
            'line' => $this->lineNumber,
            'timestamp' => $this->timestamp,
            'channel' => $this->channel,
            'level' => $this->level,
            'message' => $this->message,
            'body' => $this->body,
            'truncated' => $this->truncated,
            'message_segments' => $highlight ? $query->segments($this->message) : null,
            'body_segments' => $highlight && $this->body !== '' ? $query->segments($this->body) : null,
        ];
    }
}
