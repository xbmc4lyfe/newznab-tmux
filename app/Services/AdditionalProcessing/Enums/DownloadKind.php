<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\Enums;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

enum DownloadKind: string
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_dk_is_preview_source(const char *value, size_t len);
        C;

    case Sample = 'sample';
    case MediaInfo = 'media-info';
    case Audio = 'audio';
    case Jpg = 'jpg';
    case Compressed = 'compressed';
    case Par2Index = 'par2-index';
    case MediaSource = 'media-source';

    // PHP remains the production default until benchmarks qualify Rust.
    private static function rustEnabled(?bool $enabled = null): bool
    {
        // Enums cannot declare properties.
        static $useRustFfi = false;
        if ($enabled !== null) {
            $useRustFfi = $enabled;
        }

        return $useRustFfi;
    }

    public static function setUseRustFfi(bool $enabled): void
    {
        self::rustEnabled($enabled);
    }

    public static function usesRustFfi(): bool
    {
        return self::rustEnabled();
    }

    /**
     * Cached `additional` domain handle; `$reset` replaces it with `$override` (null re-resolves).
     */
    public static function additionalFfi(bool $reset = false, FFI|false|null $override = null): ?FFI
    {
        static $ffi = null;
        if ($reset) {
            $ffi = $override;
        }
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return $ffi === false ? null : $ffi;
    }

    /**
     * Downloads made only to find a preview, counted against the media byte budget.
     */
    public function isPreviewSource(): bool
    {
        return self::rustEnabled() ? $this->isPreviewSourceNative() : $this->isPreviewSourcePhp();
    }

    public function isPreviewSourcePhp(): bool
    {
        return $this === self::Par2Index || $this === self::MediaSource || $this === self::Jpg;
    }

    public function isPreviewSourceNative(): bool
    {
        $ffi = self::additionalFfi();
        if ($ffi === null) {
            return $this->isPreviewSourcePhp();
        }

        try {
            // @phpstan-ignore method.notFound
            $preview = $ffi->nntmux_dk_is_preview_source($this->value, strlen($this->value));
        } catch (Throwable) {
            return $this->isPreviewSourcePhp();
        }

        return $preview === 0 || $preview === 1 ? $preview === 1 : $this->isPreviewSourcePhp();
    }

    // Rust is the default only while the paired W49.6 benchmark clears 1.5x on all four metrics.
    private const bool W49_RUST_FFI_DEFAULT = true;

    public const string W49_FFI_CDEF = <<<'C'
        int32_t nntmux_w49_download_kind_account(const char *kinds, size_t kinds_len, const char *kind_twin, size_t kind_twin_len, const char *bytes, size_t bytes_len, size_t count, int64_t budget, int64_t *out, size_t out_count);
        C;

    public const int W49_DOWNLOAD_HEADER = 24;

    private const int W49_MAX_ITEMS = 1_048_576;

    private const int W49_VALUE_CAP = (1 << 40) - 1;

    private const int W49_TOTAL_CAP = 1 << 62;

    /** Backing value => case index; 7 is unknown. */
    private const array W49_KINDS = [
        'sample' => 0, 'media-info' => 1, 'audio' => 2, 'jpg' => 3, 'compressed' => 4, 'par2-index' => 5, 'media-source' => 6,
    ];

    public static function usesW49RustFfi(): bool
    {
        return self::w49RustFfiState() === true;
    }

    /**
     * Run `$callback` with the Wave 49.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW49RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w49RustFfiState('swap', $enabled);
        try {
            return $callback();
        } finally {
            self::w49RustFfiState('swap', $previous);
        }
    }

    /**
     * Account a batch of downloads, in order, against the preview byte budget: preview sources
     * (jpg, par2-index, media-source) are admitted while their running total fits `$previewBudget`
     * (clamped to 0..2^62); others are always admitted. Unknown backing values count as case 7;
     * missing sizes are 0 and sizes are clamped to 0..2^40-1.
     *
     * Layout: `[0]` count, `[1..8]` per case, `[9..16]` bytes per case, `[17]` preview sources,
     * `[18]` admitted preview bytes, `[19]` rejected previews, `[20]` their bytes, `[21]` first
     * rejected index or -1, `[22]` remaining budget, `[23]` FNV-1a digest; then per download
     * `case | preview << 4 | admitted << 5 | bytes << 8`.
     *
     * @param  array<array-key, string>  $kinds
     * @param  array<array-key, int>  $bytes
     * @return list<int>
     */
    public static function accountDownloadBatch(array $kinds, array $bytes, int $previewBudget): array
    {
        return (self::usesW49RustFfi() ? self::accountDownloadBatchNative($kinds, $bytes, $previewBudget) : null)
            ?? self::accountDownloadBatchPhp($kinds, $bytes, $previewBudget);
    }

    /**
     * @param  array<array-key, string>  $kinds
     * @param  array<array-key, int>  $bytes
     * @return list<int>
     */
    public static function accountDownloadBatchPhp(array $kinds, array $bytes, int $previewBudget): array
    {
        $kinds = array_values($kinds);
        $bytes = array_values($bytes);
        $budget = min(max($previewBudget, 0), self::W49_TOTAL_CAP);
        $cap = self::W49_VALUE_CAP;
        $totalCap = self::W49_TOTAL_CAP;
        $out = array_fill(0, self::W49_DOWNLOAD_HEADER, 0);
        $out[0] = \count($kinds);
        $out[21] = -1;
        $used = 0;
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($kinds as $index => $kind) {
            $case = self::W49_KINDS[$kind] ?? 7;
            $size = min(max($bytes[$index] ?? 0, 0), $cap);
            $preview = $case === 3 || $case === 5 || $case === 6 ? 1 : 0;
            $admitted = 1;
            $out[1 + $case]++;
            $out[9 + $case] = min($out[9 + $case] + $size, $totalCap);
            if ($preview === 1) {
                $out[17]++;
                if ($size <= $budget - $used) {
                    $used += $size;
                } else {
                    $admitted = 0;
                    $out[19]++;
                    $out[20] = min($out[20] + $size, $totalCap);
                    if ($out[21] < 0) {
                        $out[21] = $index;
                    }
                }
            }
            $digest = (($digest ^ ($case | $preview << 4 | $admitted << 5)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $case | $preview << 4 | $admitted << 5 | $size << 8;
        }
        $out[18] = $used;
        $out[22] = $budget - $used;
        $out[23] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust accounting, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, string>  $kinds
     * @param  array<array-key, int>  $bytes
     * @return list<int>|null
     */
    public static function accountDownloadBatchNative(array $kinds, array $bytes, int $previewBudget): ?array
    {
        $kinds = array_values($kinds);
        $count = \count($kinds);
        $fields = self::W49_DOWNLOAD_HEADER + $count;
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = $count <= self::W49_MAX_ITEMS ? self::w49RustFfiState('buffer', $fields) : null;
        if ($binding === null) {
            return null;
        }
        [$ffi, $out] = $binding;
        [$joined, $twin] = self::w49Strings($kinds);
        $packed = pack('q*', ...self::w49Column($bytes, $count));
        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_w49_download_kind_account($joined, \strlen($joined), $twin, \strlen($twin), $packed, \strlen($packed), $count, $previewBudget, $out, $fields);
            if ($status !== 1) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $fields * 8));
        } catch (Throwable) {
            return null;
        }

        return $values === false ? null : array_values($values);
    }

    /**
     * Wave 49.6 routing: null reads the toggle, `swap` replaces it (null restores the default)
     * and returns the previous value, `buffer` returns the cached binding and an output buffer
     * of at least `$val` values. Enums and readonly classes cannot declare static properties,
     * so the state lives in function-local statics.
     */
    private static function w49RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $enabled */
        static $enabled = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $buffer */
        static $buffer = null;
        static $capacity = 0;
        if ($op === null) {
            return $enabled ?? self::W49_RUST_FFI_DEFAULT;
        }
        if ($op === 'swap') {
            $previous = $enabled;
            $enabled = \is_bool($val) ? $val : null;

            return $previous;
        }
        $fields = \is_int($val) ? $val : 0;
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w49', self::W49_FFI_CDEF) ?? false;
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
     * NUL-joined values and, when one holds a NUL, the same list joined with 0x01 so Rust
     * finds the separators where the two differ.
     *
     * @param  list<string>  $items
     * @return array{0: string, 1: string}
     */
    private static function w49Strings(array $items): array
    {
        $joined = implode("\0", $items);

        return $items !== [] && substr_count($joined, "\0") !== \count($items) - 1 ? [$joined, implode("\x01", $items)] : [$joined, ''];
    }

    /**
     * Values by position, cut or zero-padded to `$count`.
     *
     * @param  array<array-key, int>  $values
     * @return list<int>
     */
    private static function w49Column(array $values, int $count): array
    {
        $values = array_values($values);

        return \count($values) === $count ? $values : array_pad(\array_slice($values, 0, $count), $count, 0);
    }
}
