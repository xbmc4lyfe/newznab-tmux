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

    // Rust is the default only while the paired W61.6 benchmark clears 1.5x on all four metrics.
    public const bool W61_RUST_FFI_DEFAULT = true;

    private const string W61_IMAGE_FFI_CDEF = <<<'C'
        int32_t nntmux_w616_image_asset_batch(const char *strings, size_t strings_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    public const int W61_IMAGE_HEADER = 27;

    private const int W61_MAX_ITEMS = 1_048_576;

    private const int W61_DIMENSION_CAP = 65_535;

    private const int W61_PIXEL_CAP = 40_000_000;

    /** Case name => case index (5 is unknown). */
    private const array W61_NAMES = ['Original' => 0, 'MetadataCover' => 1, 'Backdrop' => 2, 'Preview' => 3, 'Sample' => 4];

    /** Normalized case name => case index. */
    private const array W61_LOWER_NAMES = ['original' => 0, 'metadatacover' => 1, 'backdrop' => 2, 'preview' => 3, 'sample' => 4];

    /** Normalized alias => case index. */
    private const array W61_ALIASES = [
        'full' => 0, 'raw' => 0, 'source' => 0, 'cover' => 1, 'poster' => 1, 'metadata' => 1, 'fanart' => 2, 'background' => 2, 'banner' => 2,
        'thumbnail' => 3, 'thumb' => 3, 'screenshot' => 3, 'samples' => 4, 'still' => 4,
    ];

    /** Format extension => format code. */
    private const array W61_FORMATS = ['jpg' => 1, 'jpeg' => 1, 'pjpeg' => 1, 'jpe' => 1, 'png' => 2, 'webp' => 3, 'gif' => 4, 'bmp' => 5, 'avif' => 6];

    /** `[maxWidth, maxHeight]` per case (0 unbounded). */
    private const array W61_BOUNDS = [[0, 0], [250, 250], [1920, 1024], [800, 600], [650, 650], [0, 0]];

    /** JPEG quality per case. */
    private const array W61_QUALITY = [100, 85, 80, 75, 70, 0];

    /**
     * Plan image assets in order. Profiles resolve by case name (source 0), `str_replace(['_', '-'], '',
     * strtolower(trim($profile)))` of a case name (1), an alias of it (2), else unknown case 5 (3). Formats are
     * `ltrim()` of `strtolower(trim($format))` without an `image/` prefix and leading dots: 1 JPEG, 2 PNG, 3
     * WebP, 4 GIF, 5 BMP, 6 AVIF, 0 unknown. Dimensions clamp to 0..65535; bounded profiles fit oversized images
     * within their bounds keeping the aspect ratio (at least 1 pixel). Original and unknown profiles keep a
     * known format (else JPEG), metadata covers keep PNG, others encode JPEG at the profile's quality (9 for
     * PNG). Flags: 1 unknown profile, 2 unknown format, 4 zero dimension, 8 resized, 16 under 32 pixels, 32
     * over 40 megapixels, 64 converted; an image is accepted without 1, 2, 4, 16 or 32.
     *
     * Layout: `[0]` count, `[1..6]` per case, `[7..10]` per source, `[11..17]` per format, `[18]` resized, `[19]`
     * converted, `[20]` accepted, `[21]` rejected and `[22]` the first rejected index (-1), `[23]` output pixels,
     * `[24]` widest and `[25]` tallest output, `[26]` FNV-1a digest; then per image `case | source << 3 | format
     * << 5 | target << 8 | flags << 11 | accepted << 18 | quality << 19 | width << 26 | height << 42 |
     * min(profile bytes, 31) << 58`.
     *
     * @param  array<array-key, string>  $profiles
     * @param  array<array-key, string>  $formats
     * @param  array<array-key, int>  $widths
     * @param  array<array-key, int>  $heights
     * @return list<int>
     */
    public static function planImageAssetBatch(array $profiles, array $formats, array $widths, array $heights): array
    {
        return (self::usesW61RustFfi() ? self::planImageAssetBatchNative($profiles, $formats, $widths, $heights) : null)
            ?? self::planImageAssetBatchPhp($profiles, $formats, $widths, $heights);
    }

    /**
     * @param  array<array-key, string>  $profiles
     * @param  array<array-key, string>  $formats
     * @param  array<array-key, int>  $widths
     * @param  array<array-key, int>  $heights
     * @return list<int>
     */
    public static function planImageAssetBatchPhp(array $profiles, array $formats, array $widths, array $heights): array
    {
        $profiles = array_values($profiles);
        $count = \count($profiles);
        $formats = self::w61Column($formats, $count, '');
        $widths = self::w61Column($widths, $count);
        $heights = self::w61Column($heights, $count);
        $out = array_fill(0, self::W61_IMAGE_HEADER, 0);
        $out[0] = $count;
        $out[22] = -1;
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($profiles as $at => $profile) {
            $profile = (string) $profile;
            $source = 0;
            $case = self::W61_NAMES[$profile] ?? null;
            if ($case === null) {
                $normalized = str_replace(['_', '-'], '', strtolower(trim($profile)));
                $source = 1;
                $case = self::W61_LOWER_NAMES[$normalized] ?? null;
                if ($case === null) {
                    $case = self::W61_ALIASES[$normalized] ?? 5;
                    $source = $case === 5 ? 3 : 2;
                }
            }
            $format = strtolower(trim((string) $formats[$at]));
            if (str_starts_with($format, 'image/')) {
                $format = substr($format, 6);
            }
            $format = self::W61_FORMATS[ltrim($format, '.')] ?? 0;
            $width = min(max((int) $widths[$at], 0), self::W61_DIMENSION_CAP);
            $height = min(max((int) $heights[$at], 0), self::W61_DIMENSION_CAP);
            [$maxWidth, $maxHeight] = self::W61_BOUNDS[$case];
            $zero = $width === 0 || $height === 0;
            $resized = ! $zero && $maxWidth > 0 && ($width > $maxWidth || $height > $maxHeight);
            if ($resized) {
                [$outWidth, $outHeight] = $width * $maxHeight >= $height * $maxWidth
                    ? [$maxWidth, max(1, intdiv($height * $maxWidth, $width))]
                    : [max(1, intdiv($width * $maxHeight, $height)), $maxHeight];
            } else {
                [$outWidth, $outHeight] = $zero ? [0, 0] : [$width, $height];
            }
            $target = $case === 0 || $case === 5 ? ($format === 0 ? 1 : $format) : ($case === 1 && $format === 2 ? 2 : 1);
            $quality = $target === 2 ? 9 : self::W61_QUALITY[$case];
            $flags = ($case === 5 ? 1 : 0)
                | ($format === 0 ? 2 : 0)
                | ($zero ? 4 : 0)
                | ($resized ? 8 : 0)
                | (! $zero && ($width < 32 || $height < 32) ? 16 : 0)
                | ($width * $height > self::W61_PIXEL_CAP ? 32 : 0)
                | ($target !== $format ? 64 : 0);
            $accepted = ($flags & 0b11_0111) === 0 ? 1 : 0;
            $out[1 + $case]++;
            $out[7 + $source]++;
            $out[11 + $format]++;
            $out[18] += $resized ? 1 : 0;
            $out[19] += $flags >> 6 & 1;
            $out[20] += $accepted;
            if ($accepted === 0) {
                $out[21]++;
                if ($out[22] < 0) {
                    $out[22] = $at;
                }
            }
            $out[23] += $outWidth * $outHeight;
            $out[24] = max($out[24], $outWidth);
            $out[25] = max($out[25], $outHeight);
            $row = $case | $source << 3 | $format << 5 | $target << 8 | $flags << 11 | $accepted << 18 | $quality << 19
                | $outWidth << 26 | $outHeight << 42 | min(\strlen($profile), 31) << 58;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[26] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust plan, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, string>  $profiles
     * @param  array<array-key, string>  $formats
     * @param  array<array-key, int>  $widths
     * @param  array<array-key, int>  $heights
     * @return list<int>|null
     */
    public static function planImageAssetBatchNative(array $profiles, array $formats, array $widths, array $heights): ?array
    {
        $count = \count($profiles);
        if ($count > self::W61_MAX_ITEMS) {
            return null;
        }
        [$joined, $twin] = self::w61Strings([...array_values($profiles), ...self::w61Column($formats, $count, '')]);
        $ints = pack('q*', ...self::w61Column($widths, $count), ...self::w61Column($heights, $count));
        $words = self::W61_IMAGE_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w61Call($words, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w616_image_asset_batch($joined, \strlen($joined), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $words));
    }

    /**
     * Run `$callback` with the Wave 61.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW61RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w61RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w61RustFfiState('set', $previous);
        }
    }

    public static function usesW61RustFfi(): bool
    {
        $forced = self::w61RustFfiState();

        return \is_bool($forced) ? $forced : self::W61_RUST_FFI_DEFAULT;
    }

    /**
     * Wave 61.6 routing and binding in function statics (enums cannot declare properties). No `$op` returns
     * the forced routing (null for the default); `'set'` replaces it with `$val` and returns the previous
     * value; `'binding'` returns the cached binding and an output buffer of at least `$val` values, or null.
     */
    private static function w61RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $enabled */
        static $enabled = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $buffer */
        static $buffer = null;
        static $capacity = 0;
        if ($op === 'set') {
            $previous = $enabled;
            $enabled = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op !== 'binding') {
            return $enabled;
        }
        $fields = \is_int($val) ? $val : 0;
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w616', self::W61_IMAGE_FFI_CDEF) ?? false;
            if ($ffi === false) {
                return null;
            }
            if ($buffer === null || $capacity < $fields) {
                $capacity = max(256, $fields);
                $buffer = $ffi->new('int64_t['.$capacity.']');
            }
        } catch (Throwable) {
            return null;
        }

        return $buffer === null ? null : [$ffi, $buffer];
    }

    /**
     * The batch's `$fields` output words from one Wave 61.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w61Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w61RustFfiState('binding', $fields);
        if ($binding === null) {
            return null;
        }
        try {
            if ($call($binding[0], $binding[1]) !== 1) {
                return null;
            }
            $values = unpack('P*', FFI::string($binding[1], $fields * 8));
        } catch (Throwable) {
            return null;
        }

        return $values === false ? null : array_values($values);
    }

    /**
     * Values NUL-joined and, when one holds a NUL, the same values joined with 0x01 so Rust finds the
     * separators where the two differ.
     *
     * @param  list<int|string>  $items
     * @return array{0: string, 1: string}
     */
    private static function w61Strings(array $items): array
    {
        $joined = implode("\0", $items);

        return $items !== [] && substr_count($joined, "\0") !== \count($items) - 1 ? [$joined, implode("\x01", $items)] : [$joined, ''];
    }

    /**
     * Values by position, cut or padded with `$pad` to `$count`.
     *
     * @template T of int|string
     *
     * @param  array<array-key, T>  $values
     * @param  T  $pad
     * @return list<T>
     */
    private static function w61Column(array $values, int $count, int|string $pad = 0): array
    {
        $values = array_values($values);

        return \count($values) === $count ? $values : array_pad(\array_slice($values, 0, $count), $count, $pad);
    }
}
