<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class SiteLogoService
{
    public const DIRECTORY = 'site-logos';

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_site_logo_url(const char *ptr, size_t len, char *out, size_t cap);
        int32_t nntmux_site_logo_is_managed(const char *ptr, size_t len);
        C;

    private const DISK = 'public';

    // PHP remains the production default until benchmarks qualify Rust.
    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public function store(UploadedFile $logo): string
    {
        $path = $logo->store(self::DIRECTORY, self::DISK);

        if ($path === false) {
            throw new RuntimeException('The site logo could not be stored.');
        }

        return $path;
    }

    public function delete(mixed $path): void
    {
        if (! is_string($path) || ! self::isManagedPath($path)) {
            return;
        }

        Storage::disk(self::DISK)->delete($path);
    }

    public function url(mixed $path): ?string
    {
        return self::urlFor($path);
    }

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    /**
     * Restore the production default and re-resolve the library on next use.
     */
    public static function resetRustFfi(): void
    {
        self::$useRustFfi = null;
        self::$ffi = null;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::$ffi = $override;
    }

    /**
     * Public URL for a managed logo path, or null for anything outside {@see DIRECTORY}.
     */
    public static function urlFor(mixed $path): ?string
    {
        return self::usesRustFfi() ? self::urlForNative($path) : self::urlForPhp($path);
    }

    public static function urlForPhp(mixed $path): ?string
    {
        if (! is_string($path) || ! self::isManagedPathPhp($path)) {
            return null;
        }

        return '/storage/'.$path;
    }

    public static function urlForNative(mixed $path): ?string
    {
        if (! is_string($path) || ($ffi = self::ffi()) === null) {
            return self::urlForPhp($path);
        }
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, \strlen($path) + 16, $capacity);
        if ($buffer === null) {
            return self::urlForPhp($path);
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_site_logo_url($path, \strlen($path), $buffer, $capacity);
            if ($written === 0) {
                return null;
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return self::urlForPhp($path);
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return self::urlForPhp($path);
        }
    }

    /**
     * Whether `$path` is a non-traversing path inside {@see DIRECTORY}.
     */
    public static function isManagedPath(string $path): bool
    {
        return self::usesRustFfi() ? self::isManagedPathNative($path) : self::isManagedPathPhp($path);
    }

    public static function isManagedPathPhp(string $path): bool
    {
        return $path !== ''
            && ! str_contains($path, '..')
            && str_starts_with($path, self::DIRECTORY.'/');
    }

    public static function isManagedPathNative(string $path): bool
    {
        if (($ffi = self::ffi()) === null) {
            return self::isManagedPathPhp($path);
        }
        try {
            // @phpstan-ignore method.notFound
            $managed = $ffi->nntmux_site_logo_is_managed($path, \strlen($path));
        } catch (Throwable) {
            return self::isManagedPathPhp($path);
        }

        return match ($managed) {
            1 => true,
            0 => false,
            default => self::isManagedPathPhp($path),
        };
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }
}
