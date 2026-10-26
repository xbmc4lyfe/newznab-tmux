<?php

declare(strict_types=1);

namespace App\Services\LogViewer;

use App\Support\RustPipelineLibrary;
use Carbon\CarbonImmutable;
use FFI;
use FFI\CData;
use Throwable;

final readonly class LogFile
{
    /**
     * Benchmark-gated default for {@see rotationMetadataBatch()}: Rust cleared every 1.5x mean/p99 gate
     * (min 2.69x); see tests/Unit/RustLogViewerEntryFileRepoReaderReleaseSearchW48ParityTest.php.
     */
    public const bool W48_LOG_FILE_RUST_FFI_DEFAULT = true;

    /** Compression suffixes and their codes. */
    private const array W48_COMPRESSION = ['gz' => 1, 'bz2' => 2, 'xz' => 3, 'zst' => 4, 'zip' => 5];

    public function __construct(
        public string $path,
        public string $name,
        public ?string $directory,
        public string $absolutePath,
        public int $size,
        public CarbonImmutable $modifiedAt,
        public bool $structured,
    ) {}

    /**
     * Whether something wrote to the file recently enough that it is probably still held open.
     */
    public function isActive(int $guardMinutes): bool
    {
        return $this->modifiedAt->greaterThan(CarbonImmutable::now()->subMinutes($guardMinutes));
    }

    /**
     * @return array{
     *     path: string,
     *     name: string,
     *     directory: string|null,
     *     size: int,
     *     human_size: string,
     *     modified_at: string,
     *     structured: bool,
     *     active: bool
     * }
     */
    public function toArray(int $guardMinutes = 10): array
    {
        return [
            'path' => $this->path,
            'name' => $this->name,
            'directory' => $this->directory,
            'size' => $this->size,
            'human_size' => self::formatBytes($this->size),
            'modified_at' => $this->modifiedAt->toIso8601String(),
            'structured' => $this->structured,
            'active' => $this->isActive($guardMinutes),
        ];
    }

    /** Pass null to restore {@see W48_LOG_FILE_RUST_FFI_DEFAULT}. */
    public static function useW48LogFileRustFfi(?bool $enable = true): void
    {
        self::w48LogFileToggle(true, $enable);
    }

    public static function usesW48LogFileRustFfi(): bool
    {
        return self::w48LogFileToggle() ?? self::W48_LOG_FILE_RUST_FFI_DEFAULT;
    }

    public static function resetW48LogFileRustFfi(): void
    {
        self::w48LogFileToggle(true, null);
    }

    /**
     * Rotation metadata for `[name, size, mtime]` files. Per file: `[date, rotation, compression,
     * is_log, size_bucket, stem_fnv32, family_size, sort_rank]` — the stem loses a compression suffix
     * (1 gz, 2 bz2, 3 xz, 4 zst, 5 zip, case-insensitive), then a numeric rotation suffix of up to nine
     * digits; is_log reports a remaining `.log` suffix; the first `-YYYY-MM-DD` becomes the date
     * (YYYYMMDD, 0 when invalid) and is cut with everything after it, then a trailing `.log` is cut. Size
     * buckets are -1 negative, 0 < 1 KiB, 1 < 1 MiB, 2 < 1 GiB, 3 < 1 TiB, 4 larger; family_size counts
     * files sharing the stem; sort_rank orders by mtime descending, then name, then input order.
     *
     * @param  list<array{string, int, int}>  $files
     * @return list<list<int>>
     */
    public static function rotationMetadataBatch(array $files): array
    {
        return self::usesW48LogFileRustFfi() ? self::rotationMetadataBatchNative($files) : self::rotationMetadataBatchPhp($files);
    }

    /**
     * @param  list<array{string, int, int}>  $files
     * @return list<list<int>>
     */
    public static function rotationMetadataBatchPhp(array $files): array
    {
        $rows = [];
        $stems = [];
        $families = [];

        foreach ($files as [$name, $size]) {
            $stem = $name;
            $compression = 0;
            $dot = strrpos($stem, '.');

            if ($dot !== false) {
                $compression = self::W48_COMPRESSION[strtolower(substr($stem, $dot + 1))] ?? 0;
                if ($compression > 0) {
                    $stem = substr($stem, 0, $dot);
                }
            }

            $rotation = 0;
            $dot = strrpos($stem, '.');
            if ($dot !== false) {
                $tail = substr($stem, $dot + 1);
                $length = \strlen($tail);
                if ($length > 0 && $length <= 9 && strspn($tail, '0123456789') === $length) {
                    $rotation = (int) $tail;
                    $stem = substr($stem, 0, $dot);
                }
            }

            $isLog = \strlen($stem) >= 4 && strcasecmp(substr($stem, -4), '.log') === 0 ? 1 : 0;
            $date = 0;
            if (preg_match('/-(\d{4})-(\d{2})-(\d{2})/', $stem, $match, PREG_OFFSET_CAPTURE) === 1) {
                [$year, $month, $day] = [(int) $match[1][0], (int) $match[2][0], (int) $match[3][0]];
                if (checkdate($month, $day, $year)) {
                    $date = $year * 10_000 + $month * 100 + $day;
                }
                $stem = substr($stem, 0, $match[0][1]);
            }

            if (\strlen($stem) >= 4 && strcasecmp(substr($stem, -4), '.log') === 0) {
                $stem = substr($stem, 0, -4);
            }

            $bucket = match (true) {
                $size < 0 => -1,
                $size < 1024 => 0,
                $size < 1_048_576 => 1,
                $size < 1_073_741_824 => 2,
                $size < 1_099_511_627_776 => 3,
                default => 4,
            };
            $families[$stem] = ($families[$stem] ?? 0) + 1;
            $stems[] = $stem;
            $rows[] = [$date, $rotation, $compression, $isLog, $bucket, LogEntry::w48Hash32($stem)];
        }

        $order = array_keys($files);
        usort($order, static fn (int $a, int $b): int => $files[$b][2] <=> $files[$a][2] ?: strcmp($files[$a][0], $files[$b][0]) ?: $a <=> $b);
        $ranks = array_flip($order);

        foreach ($rows as $index => $row) {
            $row[] = $families[$stems[$index]];
            $row[] = $ranks[$index];
            $rows[$index] = $row;
        }

        return $rows;
    }

    /**
     * @param  list<array{string, int, int}>  $files
     * @return list<list<int>>
     */
    public static function rotationMetadataBatchNative(array $files): array
    {
        $count = \count($files);

        if ($count === 0) {
            return [];
        }

        $raw = LogReader::evaluateW48Native(
            2,
            pack('P*', $count, ...array_column($files, 1), ...array_column($files, 2)).LogReader::w48Strings(array_column($files, 0)),
            64 * $count,
        );

        return $raw === null || \strlen($raw) !== 64 * $count ? self::rotationMetadataBatchPhp($files) : LogReader::w48Rows($raw, 8);
    }

    public static function formatBytes(int $bytes, bool $useRustFfi = false): string
    {
        return ($useRustFfi ? self::formatBytesNative($bytes) : null) ?? self::formatBytesPhp($bytes);
    }

    public static function formatBytesNative(int $bytes): ?string
    {
        static $ffi;
        $ffi ??= RustPipelineLibrary::resolveDomain('search', 'ssize_t nntmux_log_viewer_format_bytes(int64_t bytes, char *out, size_t cap);') ?? false;
        if ($ffi === false) {
            return null;
        }
        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, 64, $cap);
            if ($scratch === null) {
                return null;
            }
            $written = $ffi->nntmux_log_viewer_format_bytes($bytes, $scratch, $cap);

            return is_int($written) && $written >= 0 && $written <= $cap ? FFI::string($scratch, $written) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function formatBytesPhp(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        $unitIndex = 0;

        while ($value >= 1024 && $unitIndex < count($units) - 1) {
            $value /= 1024;
            $unitIndex++;
        }

        return number_format($value, 1).' '.$units[$unitIndex];
    }

    /**
     * Readonly classes cannot hold static properties, so the toggle lives in a function static.
     */
    private static function w48LogFileToggle(bool $write = false, ?bool $value = null): ?bool
    {
        static $toggle = null;

        if ($write) {
            $toggle = $value;
        }

        return $toggle;
    }

    // ---- Wave 63.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 10.13x/10.62x, uncommon 10.13x/9.04x); see RustFingerprintCollectorYencDecoderPartServiceLogFileW63ParityTest. */
    public const bool W63_RUST_FFI_DEFAULT = true;

    private const string W63_LOG_FILE_FFI_CDEF = <<<'C'
        intptr_t nntmux_w632_log_file_plan_batch(const char *items, size_t items_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Summary words after the per-row words, the digest included. */
    public const int W63_LOG_FILE_TAIL = 31;

    /**
     * Wave 63 routing, binding and output buffer, kept in function-local statics (no static or
     * instance properties). Operations: null reads the routing override, 'set' replaces it
     * (returning the previous one), 'reset' also drops the binding and buffer, 'ffi' resolves the
     * binding and 'out' returns `[binding, buffer, capacity]` with room for at least $val words
     * (null without the library).
     */
    private static function w63RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'reset':
                $previous = $enabled;
                [$enabled, $ffi, $out, $capacity] = [null, null, null, 0];

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w63', self::W63_LOG_FILE_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w63RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    /** Routes w63LogFilePlanBatch() to Rust until reset. */
    public static function enableW63RustFfi(): void
    {
        self::w63RustFfiState('set', true);
    }

    /** Routes w63LogFilePlanBatch() to PHP until reset. */
    public static function disableW63RustFfi(): void
    {
        self::w63RustFfiState('set', false);
    }

    /** Restores W63_RUST_FFI_DEFAULT and drops the cached binding and buffer; earlier toggles are untouched. */
    public static function resetW63RustFfi(): void
    {
        self::w63RustFfiState('reset');
    }

    public static function usesW63RustFfi(): bool
    {
        $enabled = self::w63RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W63_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with w63LogFilePlanBatch() routed to Rust ($enabled) or PHP, then restores the previous
     * routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW63RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w63RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w63RustFfiState('set', $previous);
        }
    }

    /**
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w63LogFilePlanBatch(array $rows): array
    {
        return self::usesW63RustFfi() ? self::w63LogFilePlanBatchNative($rows) : self::w63LogFilePlanBatchPhp($rows);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a row holds a NUL byte or the
     * column is not a list.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w63LogFilePlanBatchNative(array $rows): array
    {
        $count = \count($rows);
        $words = $count + self::W63_LOG_FILE_TAIL;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w63RustFfiState('out', $words);
        if ($state === null || ! array_is_list($rows)) {
            return self::w63LogFilePlanBatchPhp($rows);
        }
        $blob = implode("\0", $rows);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W63_LOG_FILE_FFI_CDEF.)
            $written = $ffi->nntmux_w632_log_file_plan_batch($blob, \strlen($blob), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : self::w63LogFilePlanBatchPhp($rows);
    }

    /** @param  list<int>  $out */
    private static function w63Digest(array $out): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));

        return $digest[1];
    }

    /** A trimmed field: null when empty, its value for 1 to $max ASCII digits, otherwise false. */
    private static function w63Int(string $value, int $max): int|false|null
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $length = \strlen($value);

        return $length <= $max && strspn($value, '0123456789') === $length ? (int) $value : false;
    }

    /** Bytes per page chunk and the recently-written window of w63LogFilePlanBatch(). */
    public const int W63_LOG_CHUNK_BYTES = 65_536;

    public const int W63_LOG_ACTIVE_SECONDS = 600;

    /** Laravel log levels by severity, as w63LogFilePlanBatch() codes them (1-8). */
    private const array W63_LEVELS = ['emergency' => 1, 'alert' => 2, 'critical' => 3, 'error' => 4, 'warning' => 5, 'notice' => 6, 'info' => 7, 'debug' => 8];

    /**
     * Wave 63.2 inspection plan. Per `name\tsize\tmtime\tnow\toffset\tper_page\tfirst_line` row
     * `compression | min(rotation, 255) << 3 | is_log << 11 | size_bucket << 12 | level << 15 |
     * active << 19 | offset_valid << 20 | entry_dated << 21 | min(chunk, 2^20 - 1) << 22 |
     * min(pages, 65535) << 42 | (crc32(stem) & 0xF) << 58 | malformed << 62`. The name follows
     * rotationMetadataBatchPhp(): a compression suffix (1 gz, 2 bz2, 3 xz, 4 zst, 5 zip), then a
     * numeric rotation suffix of up to nine digits, is_log for a remaining `.log`, the first
     * `-YYYY-MM-DD` (ASCII digits; YYYYMMDD when checkdate() accepts it) cut with everything after
     * it, then a trailing `.log`. Numbers are w63Int() fields (12 digits; per_page 3 digits, 1-500,
     * default 50); malformed reports any unparsable one. Size buckets: 0 < 1 KiB, 1 < 1 MiB, 2 < 1
     * GiB, 3 < 1 TiB, 4 larger. Active: a positive mtime newer than now - W63_LOG_ACTIVE_SECONDS. A
     * valid offset (<= size) selects chunk offset / W63_LOG_CHUNK_BYTES; pages is ceil(chunks /
     * per_page), at least one. The first line of a `[YYYY-MM-DD ...] env.LEVEL: message` entry gives
     * entry_dated and the level (case-insensitive W63_LEVELS, else 0). Then six compression, nine
     * level and five size bucket counts, the active rows, invalid offsets, dated files, the latest
     * and earliest file date (0 without one), dated entries, distinct stems, total size, the most
     * pages, the malformed rows and the XXH64 digest of all earlier words.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w63LogFilePlanBatchPhp(array $rows): array
    {
        $out = $stems = [];
        $compressions = array_fill(0, 6, 0);
        $levels = array_fill(0, 9, 0);
        $buckets = array_fill(0, 5, 0);
        $activeRows = $offsetInvalid = $dated = $maxDate = $minDate = $entryDated = $totalSize = $maxPages = $malformedRows = 0;
        foreach ($rows as $row) {
            [$name, $size, $mtime, $now, $offset, $perPage, $line] = explode("\t", (string) $row, 7) + array_fill(0, 7, '');
            $stem = $name;
            $compression = 0;
            $dot = strrpos($stem, '.');
            if ($dot !== false) {
                $compression = self::W48_COMPRESSION[strtolower(substr($stem, $dot + 1))] ?? 0;
                if ($compression > 0) {
                    $stem = substr($stem, 0, $dot);
                }
            }
            $rotation = 0;
            $dot = strrpos($stem, '.');
            if ($dot !== false) {
                $tail = substr($stem, $dot + 1);
                $length = \strlen($tail);
                if ($length > 0 && $length <= 9 && strspn($tail, '0123456789') === $length) {
                    $rotation = (int) $tail;
                    $stem = substr($stem, 0, $dot);
                }
            }
            $isLog = \strlen($stem) >= 4 && strtolower(substr($stem, -4)) === '.log';
            $date = 0;
            for ($at = strpos($stem, '-'); $at !== false; $at = strpos($stem, '-', $at + 1)) {
                $found = self::w63Ymd($stem, $at + 1);
                if ($found !== null) {
                    $date = $found;
                    $stem = substr($stem, 0, $at);

                    break;
                }
            }
            if (\strlen($stem) >= 4 && strtolower(substr($stem, -4)) === '.log') {
                $stem = substr($stem, 0, -4);
            }
            $size = self::w63Int($size, 12);
            $mtime = self::w63Int($mtime, 12);
            $now = self::w63Int($now, 12);
            $offset = self::w63Int($offset, 12);
            $perPage = self::w63Int($perPage, 3);
            $malformed = $size === false || $mtime === false || $now === false || $offset === false || $perPage === false;
            $size = \is_int($size) ? $size : 0;
            $mtime = \is_int($mtime) ? $mtime : 0;
            $now = \is_int($now) ? $now : 0;
            $offset = \is_int($offset) ? $offset : 0;
            $perPage = \is_int($perPage) && $perPage >= 1 ? min($perPage, 500) : 50;
            $bucket = match (true) {
                $size < 1024 => 0,
                $size < 1_048_576 => 1,
                $size < 1_073_741_824 => 2,
                $size < 1_099_511_627_776 => 3,
                default => 4,
            };
            $active = $mtime > 0 && $mtime > $now - self::W63_LOG_ACTIVE_SECONDS;
            $offsetValid = $offset <= $size;
            $chunk = $offsetValid ? intdiv($offset, self::W63_LOG_CHUNK_BYTES) : 0;
            $pages = max(1, intdiv(intdiv($size + self::W63_LOG_CHUNK_BYTES - 1, self::W63_LOG_CHUNK_BYTES) + $perPage - 1, $perPage));
            $level = self::w63Level($line);
            $entryDate = str_starts_with($line, '[') && (self::w63Ymd($line, 1) ?? 0) > 0;
            $stems[$stem] = true;
            $compressions[$compression]++;
            $levels[$level]++;
            $buckets[$bucket]++;
            $activeRows += (int) $active;
            $offsetInvalid += (int) ! $offsetValid;
            if ($date > 0) {
                $dated++;
                $maxDate = max($maxDate, $date);
                $minDate = $minDate === 0 ? $date : min($minDate, $date);
            }
            $entryDated += (int) $entryDate;
            $totalSize += $size;
            $maxPages = max($maxPages, $pages);
            $malformedRows += (int) $malformed;
            $out[] = $compression | min($rotation, 255) << 3 | (int) $isLog << 11 | $bucket << 12 | $level << 15 | (int) $active << 19 | (int) $offsetValid << 20
                | (int) $entryDate << 21 | min($chunk, 0xFFFFF) << 22 | min($pages, 0xFFFF) << 42 | (crc32($stem) & 0xF) << 58 | (int) $malformed << 62;
        }
        array_push($out, ...$compressions, ...$levels, ...$buckets);
        array_push($out, $activeRows, $offsetInvalid, $dated, $maxDate, $minDate, $entryDated, \count($stems), $totalSize, $maxPages, $malformedRows);
        $out[] = self::w63Digest($out);

        return $out;
    }

    /** `YYYY-MM-DD` at $at as YYYYMMDD (0 when checkdate() rejects it), or null for another shape. */
    private static function w63Ymd(string $value, int $at): ?int
    {
        if (\strlen($value) - $at < 10 || $value[$at + 4] !== '-' || $value[$at + 7] !== '-' || strspn($value, '0123456789', $at, 4) !== 4
            || strspn($value, '0123456789', $at + 5, 2) !== 2 || strspn($value, '0123456789', $at + 8, 2) !== 2) {
            return null;
        }
        [$year, $month, $day] = [(int) substr($value, $at, 4), (int) substr($value, $at + 5, 2), (int) substr($value, $at + 8, 2)];

        return checkdate($month, $day, $year) ? $year * 10_000 + $month * 100 + $day : 0;
    }

    /** The W63_LEVELS code of a `[...] env.LEVEL:` entry line, else 0. */
    private static function w63Level(string $line): int
    {
        if (! str_starts_with($line, '[')) {
            return 0;
        }
        $bracket = strpos($line, ']');
        $colon = $bracket === false ? false : strpos($line, ':', $bracket + 1);
        if ($bracket === false || $colon === false) {
            return 0;
        }
        $token = trim(substr($line, $bracket + 1, $colon - $bracket - 1));
        $dot = strrpos($token, '.');

        return self::W63_LEVELS[strtolower($dot === false ? $token : substr($token, $dot + 1))] ?? 0;
    }
}
