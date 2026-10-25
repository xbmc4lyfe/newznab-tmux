<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
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

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W51 parity test). */
    private const bool W51_RUST_FFI_DEFAULT = true;

    private const string W51_FFI_CDEF = 'ssize_t nntmux_w511_site_logo_unmanaged_batch(const char *paths, size_t paths_len, size_t count, int64_t *out, size_t out_cap);';

    public static function usesW51RustFfi(): bool
    {
        $enabled = self::w51RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W51_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 51 batch routed to Rust or PHP, then restores the routing. */
    public static function withW51RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w51RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w51RustFfiState('set', $previous);
        }
    }

    /**
     * Indices of the paths {@see isManagedPath()} rejects (those {@see delete()} skips), ascending.
     *
     * @param  list<string>  $paths
     * @return list<int>
     */
    public static function unmanagedPathIndices(array $paths): array
    {
        return self::usesW51RustFfi() ? self::unmanagedPathIndicesNative($paths) : self::unmanagedPathIndicesPhp($paths);
    }

    /**
     * @param  list<string>  $paths
     * @return list<int>
     */
    public static function unmanagedPathIndicesPhp(array $paths): array
    {
        $indices = [];
        foreach ($paths as $i => $path) {
            if (! self::isManagedPathPhp($path)) {
                $indices[] = $i;
            }
        }

        return $indices;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or a path holds a NUL byte
     * (Rust rejects the extra rows it splits into).
     *
     * @param  list<string>  $paths
     * @return list<int>
     */
    public static function unmanagedPathIndicesNative(array $paths): array
    {
        $count = \count($paths);
        $maxWords = $count;
        $buffer = $count === 0 ? null : self::w51Buffer($maxWords);
        if ($buffer === null) {
            return self::unmanagedPathIndicesPhp($paths);
        }
        [$ffi, $out, $cap] = $buffer;
        $joined = implode("\0", $paths);
        try {
            // @phpstan-ignore method.notFound
            $words = self::w51Decode($out, $ffi->nntmux_w511_site_logo_unmanaged_batch($joined, \strlen($joined), $count, $out, $cap), $maxWords);
        } catch (Throwable) {
            $words = null;
        }

        return $words ?? self::unmanagedPathIndicesPhp($paths);
    }

    /**
     * Lazily resolved binding plus the reusable output buffer sized for `$words` words.
     *
     * @return array{0: FFI, 1: CData, 2: int}|null
     */
    private static function w51Buffer(int $words): ?array
    {
        $buffer = self::w51RustFfiState('out', $words);

        return \is_array($buffer) ? $buffer : null;
    }

    /** @return list<int>|null The `$written` words the export reported, or null when it declined. */
    private static function w51Decode(CData $out, mixed $written, int $maxWords): ?array
    {
        if (! \is_int($written) || $written < 0 || $written > $maxWords) {
            return null;
        }
        if ($written === 0) {
            return [];
        }
        $decoded = unpack('P*', FFI::string($out, $written * 8));

        /** @var list<int>|null */
        return \is_array($decoded) ? array_values($decoded) : null;
    }

    /**
     * Wave 51 routing override (`set`) plus the lazily resolved binding and reusable `int64_t`
     * output buffer (`out`, sized for `$val` words), kept in function-local statics.
     */
    private static function w51RustFfiState(?string $op = null, mixed $val = null): mixed
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
            case 'out':
                $ffi ??= RustPipelineLibrary::resolveDomain('admin_status_w51', self::W51_FFI_CDEF) ?? false;
                if (! $ffi instanceof FFI || ! \is_int($val)) {
                    return null;
                }
                if (! $out instanceof CData || $outCap < $val) {
                    $outCap = max(512, $val * 2);
                    $out = $ffi->new("int64_t[{$outCap}]");
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled;
        }
    }
}
