<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds;

use App\Models\Predb;
use App\Support\RustPipelineLibrary;
use Carbon\CarbonImmutable;
use FFI;
use Throwable;

/**
 * Release-name metadata from a remote PRE feed or catalogue, normalised to predb table semantics.
 */
final class PredbFeedEntry
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_predb_feed_entry_format_size_mb(double megabytes, char *out_buf, size_t out_cap);
        ssize_t nntmux_predb_feed_entry_format_files(int64_t count, const char *text, size_t text_len, uint8_t is_string, char *out_buf, size_t out_cap);
        C;

    /**
     * Both helpers stay on PHP: single-call FFI overhead outweighs number_format()/(int) (see
     * RustPredbFeedsAndStreamParityTest for the paired benchmark evidence).
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public function __construct(
        public readonly string $title,
        public readonly string $source,
        public readonly ?string $category = null,
        public readonly ?string $size = null,
        public readonly ?string $files = null,
        public readonly ?CarbonImmutable $predate = null,
        public readonly int $nuked = Predb::PRE_NONUKE,
        public readonly ?string $nukeReason = null,
        // When the feed listed the entry, if that is not the PRE time (e.g. srrDB's upload time).
        // Used only to page history imports; never stored as predate.
        public readonly ?CarbonImmutable $listedAt = null,
        // Details-only entry (e.g. an IRC INFO line): it may fill in an existing row but never creates one.
        public readonly bool $enrichOnly = false,
    ) {}

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    private static function ffi(): ?FFI
    {
        self::$ffi ??= RustPipelineLibrary::resolveDomain('predb', self::FFI_CDEF) ?? false;

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * Timestamp used to decide how far back a history import has paged.
     */
    public function pagingTime(): ?CarbonImmutable
    {
        return $this->predate ?? $this->listedAt;
    }

    /**
     * Format a size given in megabytes the way IRC pre bots announce it (KB below 1 MB), or null when unknown.
     */
    public static function sizeFromMegabytes(int|float|null $megabytes): ?string
    {
        if (self::$useRustFfi) {
            $supported = false;
            $native = self::sizeFromMegabytesNative($megabytes, $supported);
            if ($supported) {
                return $native;
            }
        }

        return self::sizeFromMegabytesPhp($megabytes);
    }

    /**
     * Rust sizeFromMegabytes(); $supported is false when PHP must format the value (near a
     * rounding tie, large, non-finite, or the library is unavailable).
     */
    public static function sizeFromMegabytesNative(int|float|null $megabytes, bool &$supported = false): ?string
    {
        $supported = false;
        if ($megabytes === null) {
            $supported = true;

            return null;
        }
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $capacity = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 64, $capacity);
        if ($scratch === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_predb_feed_entry_format_size_mb((float) $megabytes, $scratch, $capacity);
        } catch (Throwable) {
            return null;
        }
        if (! is_int($written) || $written < 0) {
            return null;
        }
        $supported = true;

        return $written === 0 ? null : FFI::string($scratch, $written);
    }

    public static function sizeFromMegabytesPhp(int|float|null $megabytes): ?string
    {
        if ($megabytes === null || $megabytes <= 0) {
            return null;
        }

        $format = static fn (float $value): string => rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return $megabytes < 1 ? $format($megabytes * 1024).'KB' : $format((float) $megabytes).'MB';
    }

    /**
     * Normalise a file count, treating zero as unknown.
     */
    public static function filesFromCount(int|string|null $count): ?string
    {
        if (self::$useRustFfi) {
            $supported = false;
            $native = self::filesFromCountNative($count, $supported);
            if ($supported) {
                return $native;
            }
        }

        return self::filesFromCountPhp($count);
    }

    /**
     * Rust filesFromCount(); $supported is false for float-like or overlong numeric strings.
     */
    public static function filesFromCountNative(int|string|null $count, bool &$supported = false): ?string
    {
        $supported = false;
        if ($count === null) {
            $supported = true;

            return null;
        }
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $capacity = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 64, $capacity);
        if ($scratch === null) {
            return null;
        }
        $text = is_string($count) ? $count : null;
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_predb_feed_entry_format_files(is_int($count) ? $count : 0, $text, $text === null ? 0 : strlen($text), $text === null ? 0 : 1, $scratch, $capacity);
        } catch (Throwable) {
            return null;
        }
        if (! is_int($written) || $written < 0) {
            return null;
        }
        $supported = true;

        return $written === 0 ? null : FFI::string($scratch, $written);
    }

    public static function filesFromCountPhp(int|string|null $count): ?string
    {
        if ($count === null || (int) $count <= 0) {
            return null;
        }

        return (string) (int) $count;
    }
}
