<?php

declare(strict_types=1);

namespace App\Services\LogViewer;

use App\Support\RustPipelineLibrary;
use Carbon\CarbonImmutable;
use FFI;
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
}
