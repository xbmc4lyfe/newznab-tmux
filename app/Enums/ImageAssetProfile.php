<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Image bounds used by the existing cover and sample producers.
 */
enum ImageAssetProfile
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_iap_bounds(const char *name, size_t len, int64_t *out, size_t out_count);
        C;

    case Original;
    case MetadataCover;
    case Backdrop;
    case Preview;
    case Sample;

    // PHP remains the production default until benchmarks qualify Rust.
    public static function setUseRustFfi(?bool $use = true): void
    {
        self::toggleState($use, true);
        if ($use === null) {
            self::ffi(true);
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::toggleState() ?? false;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::ffi(true, $override);
    }

    public function maxWidth(): ?int
    {
        return self::usesRustFfi() ? $this->maxWidthNative() : $this->maxWidthPhp();
    }

    public function maxWidthPhp(): ?int
    {
        return match ($this) {
            self::Original => null,
            self::MetadataCover => 250,
            self::Backdrop => 1920,
            self::Preview => 800,
            self::Sample => 650,
        };
    }

    public function maxWidthNative(): ?int
    {
        $resolved = self::boundsNative($this->name);

        return $resolved === null ? $this->maxWidthPhp() : $resolved[1];
    }

    public function maxHeight(): ?int
    {
        return self::usesRustFfi() ? $this->maxHeightNative() : $this->maxHeightPhp();
    }

    public function maxHeightPhp(): ?int
    {
        return match ($this) {
            self::Original => null,
            self::MetadataCover => 250,
            self::Backdrop => 1024,
            self::Preview => 600,
            self::Sample => 650,
        };
    }

    public function maxHeightNative(): ?int
    {
        $resolved = self::boundsNative($this->name);

        return $resolved === null ? $this->maxHeightPhp() : $resolved[2];
    }

    /**
     * Resolve a profile by case name; null when it names no profile.
     */
    public static function tryFromName(string $name): ?self
    {
        return self::usesRustFfi() ? self::tryFromNameNative($name) : self::tryFromNamePhp($name);
    }

    public static function tryFromNamePhp(string $name): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->name === $name) {
                return $case;
            }
        }

        return null;
    }

    public static function tryFromNameNative(string $name): ?self
    {
        $resolved = self::boundsNative($name);

        return $resolved === null
            ? self::tryFromNamePhp($name)
            : (self::cases()[$resolved[0]] ?? self::tryFromNamePhp($name));
    }

    /**
     * `[case index, maxWidth, maxHeight]` from Rust, or null for an unknown name or
     * unavailable library.
     *
     * @return array{0: int, 1: ?int, 2: ?int}|null
     */
    private static function boundsNative(string $name): ?array
    {
        if (($ffi = self::ffi()) === null || ($out = self::boundsBuffer($ffi)) === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $index = $ffi->nntmux_iap_bounds($name, \strlen($name), $out, 2);
            if (! \is_int($index) || $index < 0) {
                return null;
            }
            /** @var int $width */
            $width = $out[0];
            /** @var int $height */
            $height = $out[1];
        } catch (Throwable) {
            return null;
        }

        return [$index, $width < 0 ? null : $width, $height < 0 ? null : $height];
    }

    /** Reusable `int64_t[2]` bounds buffer for `$ffi`. */
    private static function boundsBuffer(FFI $ffi): ?CData
    {
        static $buffer = null;
        static $owner = null;
        if ($owner !== $ffi || $buffer === null) {
            try {
                $buffer = $ffi->new('int64_t[2]');
                $owner = $ffi;
            } catch (Throwable) {
                return null;
            }
        }

        return $buffer;
    }

    private static function toggleState(?bool $next = null, bool $write = false): ?bool
    {
        // Enums cannot declare static properties.
        static $useRustFfi = null;
        if ($write) {
            $useRustFfi = $next;
        }

        return $useRustFfi;
    }

    private static function ffi(bool $reset = false, FFI|false|null $override = null): ?FFI
    {
        static $ffi = null;
        if ($reset) {
            $ffi = $override;
            if ($override !== null) {
                return $ffi === false ? null : $ffi;
            }
        }
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return $ffi === false ? null : $ffi;
    }
}
