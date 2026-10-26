<?php

declare(strict_types=1);

namespace App\Services\LogViewer;

use App\Support\RustPipelineLibrary;
use FFI;
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

    /** C signature of the Wave 67.5 {@see self::w67LogEntryPlanBatch()} helper. */
    private const string W67_LOG_ENTRY_PLAN_FFI_CDEF = 'int64_t nntmux_w675_log_entry_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /**
     * The Wave 67.5 plan batch defaults to Rust: every common and uncommon mean and p99 ratio cleared 1.5x
     * (min 8.48/5.25/3.86/3.20x); see tests/Unit/RustLogViewerIndexCoordinatorManticoreEntryParserW67ParityTest.php.
     */
    public const bool W67_RUST_FFI_DEFAULT = true;

    /**
     * Wave 67.5 routing and FFI cache, kept in function-local statics (no properties). Without $op it
     * reports the routing; 'set' swaps the override for $val and returns the previous one; 'reset' replaces the
     * cached handle with $val (null re-resolves, false disables); 'buffer' returns `[FFI, int64_t[], capacity]`
     * with room for at least $val words, or null without the library.
     */
    private static function w67RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'reset':
                $ffi = $val;
                $out = null;
                $outCap = 0;

                return null;
            case 'buffer':
                if ($ffi === null) {
                    $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
                    $ffi = RustPipelineLibrary::resolveDomain('w67_5', self::W67_LOG_ENTRY_PLAN_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
                }
                if (! $ffi instanceof FFI || ! \is_int($val)) {
                    return null;
                }
                if ($out === null || $outCap < $val) {
                    $outCap = max(1024, $val);
                    $out = $ffi->new('int64_t['.$outCap.']');
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled ?? self::W67_RUST_FFI_DEFAULT;
        }
    }

    /** Route the Wave 67.5 plan batch to Rust until disabled or reset. */
    public static function enableW67RustFfi(): void
    {
        self::w67RustFfiState('set', true);
    }

    /** Route the Wave 67.5 plan batch to PHP until enabled or reset. */
    public static function disableW67RustFfi(): void
    {
        self::w67RustFfiState('set', false);
    }

    /** Restore W67_RUST_FFI_DEFAULT routing and drop the cached Wave 67.5 handle and buffer. */
    public static function resetW67RustFfi(): void
    {
        self::w67RustFfiState('set', null);
        self::w67RustFfiState('reset', null);
    }

    public static function usesW67RustFfi(): bool
    {
        return self::w67RustFfiState() === true;
    }

    /**
     * Runs $callback with w67LogEntryPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW67RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w67RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w67RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 67.5 Rust helper over $count NUL-joined records, or null
     * to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w67Words(int $count, string $data, int $words): ?array
    {
        try {
            $handle = self::w67RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w675_log_entry_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('q*', FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Wave 67.5 timestamp: date, time, optional fraction and optional `Z` or `±HH[:]MM` zone. */
    private const string W67_TIMESTAMP_PATTERN = '/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2}):(\d{2})(?:\.\d+)?(?:(Z)|([+-])(\d{2}):?(\d{2}))?\z/';

    /**
     * Batch entry normalization plan over `timestamp\tlevel\tchannel\tmessage\tbody` (the body keeps any further
     * tabs). Eight words per record: `[severity, epoch, message_bytes, context_bytes, stack_frames, body_lines,
     * fingerprint, flags]`. Severity is the W48_SEVERITY rank of the upper-cased level (0 otherwise). Epoch is the UTC
     * second of `Y-m-d[T ]H:i:s[.u][Z|+HH[:]MM|-HH[:]MM]` (years from 1970; days past the month end roll over like
     * gmmktime()), -1 without one. Context bytes measure the trailing ` {...}` of the right-trimmed message; stack
     * frames count body lines starting with `#<digit>`. The fingerprint is {@see w48Hash64()} of
     * `level|channel|message` without that context. Flags: 1 fewer than five fields, 2 unknown level, 4 unparsable
     * timestamp, 8 body holds `[stacktrace]` or `Stack trace:`, 16 non-ASCII message.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w67LogEntryPlanBatch(array $records): array
    {
        return self::usesW67RustFfi() ? self::w67LogEntryPlanBatchNative($records) : self::w67LogEntryPlanBatchPhp($records);
    }

    /**
     * Rust batch over the NUL-joined records; falls back to PHP without the library or when a record holds
     * a NUL.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w67LogEntryPlanBatchNative(array $records): array
    {
        $count = \count($records);
        if ($count === 0) {
            return [];
        }

        return self::w67Words($count, implode("\0", $records), 8 * $count) ?? self::w67LogEntryPlanBatchPhp($records);
    }

    /**
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w67LogEntryPlanBatchPhp(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $fields = explode("\t", $record, 5);
            $flags = \count($fields) === 5 ? 0 : 1;
            [$timestamp, $level, $channel, $message, $body] = $fields + ['', '', '', '', ''];
            $severity = 0;
            if ($level !== '') {
                $severity = self::W48_SEVERITY[strtoupper($level)] ?? 0;
                if ($severity === 0) {
                    $flags |= 2;
                }
            }
            $epoch = -1;
            if ($timestamp !== '') {
                if (preg_match(self::W67_TIMESTAMP_PATTERN, $timestamp, $m) === 1
                    && (int) $m[1] >= 1970 && (int) $m[2] >= 1 && (int) $m[2] <= 12 && (int) $m[3] >= 1 && (int) $m[3] <= 31
                    && (int) $m[4] <= 23 && (int) $m[5] <= 59 && (int) $m[6] <= 59
                    && (int) ($m[9] ?? 0) <= 23 && (int) ($m[10] ?? 0) <= 59) {
                    $zone = ((int) ($m[9] ?? 0)) * 3600 + ((int) ($m[10] ?? 0)) * 60;
                    $epoch = (int) gmmktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], (int) $m[1]) - (($m[8] ?? '') === '-' ? -$zone : $zone);
                } else {
                    $flags |= 4;
                }
            }
            $trimmed = rtrim($message, " \t\r");
            $core = $trimmed;
            $context = 0;
            if (str_ends_with($trimmed, '}')) {
                $at = strrpos($trimmed, ' {');
                if ($at !== false) {
                    $context = \strlen($trimmed) - $at - 1;
                    $core = substr($trimmed, 0, $at);
                }
            }
            if (str_contains($body, '[stacktrace]') || str_contains($body, 'Stack trace:')) {
                $flags |= 8;
            }
            if (preg_match('/[\x80-\xff]/', $message) === 1) {
                $flags |= 16;
            }
            array_push(
                $out,
                $severity,
                $epoch,
                \strlen($message),
                $context,
                (int) preg_match_all('/(?:\A|\n)#\d/', $body),
                $body === '' ? 0 : substr_count($body, "\n") + 1,
                self::w48Hash64($level.'|'.$channel.'|'.$core),
                $flags,
            );
        }

        return $out;
    }
}
