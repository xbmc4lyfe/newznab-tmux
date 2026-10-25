<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\Enums;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

enum ProcessingStage: string
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_aenum_resolve(int32_t kind, const char *ptr, size_t len);
        ssize_t nntmux_aenum_label(int32_t kind, int64_t index, char *out, size_t cap);
        int32_t nntmux_aenum_rank(int32_t kind, int64_t index);
        C;

    /** `nntmux_aenum_*` selector for this enum. */
    private const int FFI_KIND = 1;

    case WorkspacePreparation = 'workspace-preparation';
    case NzbParsing = 'nzb-parsing';
    case ReleaseInitialization = 'release-initialization';
    case MessageIdSelection = 'message-id-selection';
    case MediaSourcePlanning = 'media-source-planning';
    case DirectDownloads = 'direct-downloads';
    case ArchiveDownloads = 'archive-downloads';
    case ExtractedFiles = 'extracted-files';
    case MediaSources = 'media-sources';
    case ArchiveFallbacks = 'archive-fallbacks';
    case TimeoutHandling = 'timeout-handling';
    case Finalization = 'finalization';
    case WorkspaceCleanup = 'workspace-cleanup';

    // PHP remains the production default until benchmarks qualify Rust.
    public static function enableRustFfi(): void
    {
        self::toggleState(true, true);
    }

    public static function disableRustFfi(): void
    {
        self::toggleState(false, true);
    }

    /**
     * Restore the production default and re-resolve the library on next use.
     */
    public static function resetRustFfi(): void
    {
        self::toggleState(null, true);
        self::ffi(true);
    }

    public static function usesRustFfi(): bool
    {
        return self::toggleState() ?? false;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::ffi(true, $override);
    }

    public function label(): string
    {
        return self::usesRustFfi() ? $this->labelNative() : $this->labelPhp();
    }

    public function labelPhp(): string
    {
        return match ($this) {
            self::WorkspacePreparation => 'Workspace preparation',
            self::NzbParsing => 'NZB parsing',
            self::ReleaseInitialization => 'Release initialization',
            self::MessageIdSelection => 'Message-ID selection',
            self::MediaSourcePlanning => 'Media source planning',
            self::DirectDownloads => 'Direct downloads',
            self::ArchiveDownloads => 'Archive downloads',
            self::ExtractedFiles => 'Extracted files',
            self::MediaSources => 'Media sources',
            self::ArchiveFallbacks => 'Archive fallbacks',
            self::TimeoutHandling => 'Timeout handling',
            self::Finalization => 'Finalization',
            self::WorkspaceCleanup => 'Workspace cleanup',
        };
    }

    public function labelNative(): string
    {
        if (($ffi = self::ffi()) === null) {
            return $this->labelPhp();
        }
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, 64, $capacity);
        if ($buffer === null) {
            return $this->labelPhp();
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_aenum_label(self::FFI_KIND, $this->ordinal(), $buffer, $capacity);
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return $this->labelPhp();
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return $this->labelPhp();
        }
    }

    /**
     * Position of the stage in the release processing pipeline.
     */
    public function rank(): int
    {
        return self::usesRustFfi() ? $this->rankNative() : $this->rankPhp();
    }

    public function rankPhp(): int
    {
        return match ($this) {
            self::WorkspacePreparation => 0,
            self::NzbParsing => 1,
            self::ReleaseInitialization => 2,
            self::MessageIdSelection => 3,
            self::MediaSourcePlanning => 4,
            self::DirectDownloads => 5,
            self::ArchiveDownloads => 6,
            self::ExtractedFiles => 7,
            self::MediaSources => 8,
            self::ArchiveFallbacks => 9,
            self::TimeoutHandling => 10,
            self::Finalization => 11,
            self::WorkspaceCleanup => 12,
        };
    }

    public function rankNative(): int
    {
        if (($ffi = self::ffi()) === null) {
            return $this->rankPhp();
        }
        try {
            // @phpstan-ignore method.notFound
            $rank = $ffi->nntmux_aenum_rank(self::FFI_KIND, $this->ordinal());
        } catch (Throwable) {
            return $this->rankPhp();
        }

        return \is_int($rank) && $rank >= 0 ? $rank : $this->rankPhp();
    }

    /**
     * Resolve a trimmed, case-insensitive backing value that may use `_` for `-`.
     */
    public static function tryFromNormalized(string $value): ?self
    {
        return self::usesRustFfi() ? self::tryFromNormalizedNative($value) : self::tryFromNormalizedPhp($value);
    }

    public static function tryFromNormalizedPhp(string $value): ?self
    {
        return self::tryFrom(str_replace('_', '-', strtolower(trim($value))));
    }

    public static function tryFromNormalizedNative(string $value): ?self
    {
        if (($ffi = self::ffi()) === null) {
            return self::tryFromNormalizedPhp($value);
        }
        try {
            // @phpstan-ignore method.notFound
            $index = $ffi->nntmux_aenum_resolve(self::FFI_KIND, $value, \strlen($value));
        } catch (Throwable) {
            return self::tryFromNormalizedPhp($value);
        }
        if ($index === -2) {
            return null;
        }

        return \is_int($index) && $index >= 0
            ? (self::cases()[$index] ?? self::tryFromNormalizedPhp($value))
            : self::tryFromNormalizedPhp($value);
    }

    /**
     * Declaration index of this case, as the Rust tables order it.
     */
    private function ordinal(): int
    {
        return match ($this) {
            self::WorkspacePreparation => 0,
            self::NzbParsing => 1,
            self::ReleaseInitialization => 2,
            self::MessageIdSelection => 3,
            self::MediaSourcePlanning => 4,
            self::DirectDownloads => 5,
            self::ArchiveDownloads => 6,
            self::ExtractedFiles => 7,
            self::MediaSources => 8,
            self::ArchiveFallbacks => 9,
            self::TimeoutHandling => 10,
            self::Finalization => 11,
            self::WorkspaceCleanup => 12,
        };
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

    // Rust is the default only while the paired W50.6 benchmark clears 1.5x on all four metrics.
    private const bool W50_RUST_FFI_DEFAULT = true;

    public const string W50_FFI_CDEF = <<<'C'
        int32_t nntmux_w506_processing_stage_batch(const char *values, size_t values_len, const char *value_twin, size_t value_twin_len, size_t count, int64_t *out, size_t out_count);
        C;

    public const int W50_STAGE_HEADER = 20;

    private const int W50_MAX_ITEMS = 1_048_576;

    public static function usesW50RustFfi(): bool
    {
        return self::w50RustFfiState() === true;
    }

    /**
     * Run `$callback` with the Wave 50.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW50RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w50RustFfiState('swap', $enabled);
        try {
            return $callback();
        } finally {
            self::w50RustFfiState('swap', $previous);
        }
    }

    /**
     * Resolve and rank a batch of stage names as `tryFromNormalizedPhp()`, `rankPhp()` and
     * `labelPhp()` do (case 13 is an unresolved value).
     *
     * Layout: `[0]` count, `[1..14]` per case, `[15]` highest rank (-1 when none resolves) and
     * `[16]` its first index, `[17]` 1 when the resolved ranks never decrease, `[18]` label bytes,
     * `[19]` FNV-1a digest; then per value `case | (rank + 1) << 4 | label length << 8`.
     *
     * @param  array<array-key, string>  $values
     * @return list<int>
     */
    public static function rankStageBatch(array $values): array
    {
        return (self::usesW50RustFfi() ? self::rankStageBatchNative($values) : null) ?? self::rankStageBatchPhp($values);
    }

    /**
     * @param  array<array-key, string>  $values
     * @return list<int>
     */
    public static function rankStageBatchPhp(array $values): array
    {
        $out = array_fill(0, self::W50_STAGE_HEADER, 0);
        $out[0] = \count($values);
        $out[15] = $out[16] = -1;
        $out[17] = 1;
        $last = -1;
        $digest = 2_166_136_261;
        $rows = [];
        $index = 0;
        foreach ($values as $value) {
            $stage = self::tryFromNormalizedPhp($value);
            if ($stage === null) {
                [$case, $rank, $label] = [13, -1, 0];
            } else {
                [$case, $rank, $label] = [$stage->ordinal(), $stage->rankPhp(), \strlen($stage->labelPhp())];
            }
            $out[1 + $case]++;
            if ($rank >= 0) {
                if ($rank > $out[15]) {
                    $out[15] = $rank;
                    $out[16] = $index;
                }
                if ($rank < $last) {
                    $out[17] = 0;
                }
                $last = $rank;
            }
            $out[18] += $label;
            $row = $case | ($rank + 1) << 4 | $label << 8;
            $digest = (($digest ^ $row) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
            $index++;
        }
        $out[19] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust ranking, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, string>  $values
     * @return list<int>|null
     */
    public static function rankStageBatchNative(array $values): ?array
    {
        $values = array_values($values);
        $count = \count($values);
        $fields = self::W50_STAGE_HEADER + $count;
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = $count <= self::W50_MAX_ITEMS ? self::w50RustFfiState('buffer', $fields) : null;
        if ($binding === null) {
            return null;
        }
        [$ffi, $out] = $binding;
        [$joined, $twin] = self::w50Strings($values);
        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_w506_processing_stage_batch($joined, \strlen($joined), $twin, \strlen($twin), $count, $out, $fields);
            if ($status !== 1) {
                return null;
            }
            $unpacked = unpack('P*', FFI::string($out, $fields * 8));
        } catch (Throwable) {
            return null;
        }

        return $unpacked === false ? null : array_values($unpacked);
    }

    /**
     * Wave 50.6 routing: null reads the toggle, `swap` replaces it (null restores the default)
     * and returns the previous value, `buffer` returns the cached binding and an output buffer
     * of at least `$val` values. Enums and readonly classes cannot declare static properties,
     * so the state lives in function-local statics.
     */
    private static function w50RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $enabled */
        static $enabled = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $buffer */
        static $buffer = null;
        static $capacity = 0;
        if ($op === null) {
            return $enabled ?? self::W50_RUST_FFI_DEFAULT;
        }
        if ($op === 'swap') {
            $previous = $enabled;
            $enabled = \is_bool($val) ? $val : null;

            return $previous;
        }
        $fields = \is_int($val) ? $val : 0;
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w50', self::W50_FFI_CDEF) ?? false;
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
    private static function w50Strings(array $items): array
    {
        $joined = implode("\0", $items);

        return $items !== [] && substr_count($joined, "\0") !== \count($items) - 1 ? [$joined, implode("\x01", $items)] : [$joined, ''];
    }
}
