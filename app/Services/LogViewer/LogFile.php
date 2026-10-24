<?php

declare(strict_types=1);

namespace App\Services\LogViewer;

use App\Support\RustPipelineLibrary;
use Carbon\CarbonImmutable;
use FFI;
use Throwable;

final readonly class LogFile
{
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
}
