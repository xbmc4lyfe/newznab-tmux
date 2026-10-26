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

    // Rust is the default only while the paired W64.6 benchmark clears 1.5x on all four metrics.
    public const bool W64_RUST_FFI_DEFAULT = true;

    private const string W64_STAGE_FFI_CDEF = <<<'C'
        int32_t nntmux_w646_processing_stage_batch(const char *strings, size_t strings_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t budget, int64_t *out, size_t out_cap);
        C;

    public const int W64_STAGE_HEADER = 32;

    private const int W64_MAX_ITEMS = 1_048_576;

    /** Elapsed clamp (2^31 - 1) and budget cap (2^50). */
    private const int W64_STAGE_ELAPSED_CAP = 2_147_483_647;

    private const int W64_STAGE_BUDGET_CAP = 1_125_899_906_842_624;

    /** Timeout in seconds per stage in pipeline order (unknown 0). */
    private const array W64_STAGE_TIMEOUT = [30, 60, 30, 30, 60, 600, 1_800, 900, 1_200, 900, 60, 120, 60, 0];

    /** Exact token => `case | source << 4`: backing values (0), case names (1) and labels (2). */
    private const array W64_STAGE_TOKENS = [
        'workspace-preparation' => 0, 'nzb-parsing' => 1, 'release-initialization' => 2, 'message-id-selection' => 3,
        'media-source-planning' => 4, 'direct-downloads' => 5, 'archive-downloads' => 6, 'extracted-files' => 7,
        'media-sources' => 8, 'archive-fallbacks' => 9, 'timeout-handling' => 10, 'finalization' => 11, 'workspace-cleanup' => 12,
        'WorkspacePreparation' => 16, 'NzbParsing' => 17, 'ReleaseInitialization' => 18, 'MessageIdSelection' => 19,
        'MediaSourcePlanning' => 20, 'DirectDownloads' => 21, 'ArchiveDownloads' => 22, 'ExtractedFiles' => 23,
        'MediaSources' => 24, 'ArchiveFallbacks' => 25, 'TimeoutHandling' => 26, 'Finalization' => 27, 'WorkspaceCleanup' => 28,
        'Workspace preparation' => 32, 'NZB parsing' => 33, 'Release initialization' => 34, 'Message-ID selection' => 35,
        'Media source planning' => 36, 'Direct downloads' => 37, 'Archive downloads' => 38, 'Extracted files' => 39,
        'Media sources' => 40, 'Archive fallbacks' => 41, 'Timeout handling' => 42, 'Workspace cleanup' => 44,
    ];

    /**
     * Plan an ordered stage trace. Stages resolve by backing value (source 0), case name (1), label (2; the
     * Finalization label is its case name), the backing value of `str_replace('_', '-', strtolower(trim($s)))`
     * (3), else unknown case 13 (4); a known case is its `rankPhp()`. Elapsed seconds clamp to 0..2^31-1; a
     * budget at or below 0 is none, else capped at 2^50. State 0 advance (rank above every earlier one, a gap
     * when it skips a rank, so its dependency never ran), 1 repeat (equal to the highest), 2 regress (below
     * it), 3 unknown. A known stage times out when its elapsed exceeds its timeout; a stage is over budget once
     * the running elapsed total exceeds the budget.
     *
     * Layout: `[0]` count, `[1..15]` per case, `[15..20]` per source, `[20..24]` per state, `[24]` gaps, `[25]`
     * timed out, `[26]` over budget, `[27]` total elapsed, `[28]` highest rank (-1), `[29]` first regress and
     * `[30]` first timed-out index (-1), `[31]` FNV-1a digest; then per stage `case | source << 4 | state << 7
     * | gap << 9 | timed out << 10 | over budget << 11 | timeout << 12 | elapsed << 23 | min(token bytes, 255)
     * << 54`.
     *
     * @param  array<array-key, string>  $stages
     * @param  array<array-key, int>  $elapsed
     * @return list<int>
     */
    public static function w64ProcessingStagePlanBatch(array $stages, array $elapsed, int $budget = 0): array
    {
        return (self::usesW64RustFfi() ? self::w64ProcessingStagePlanBatchNative($stages, $elapsed, $budget) : null)
            ?? self::w64ProcessingStagePlanBatchPhp($stages, $elapsed, $budget);
    }

    /**
     * @param  array<array-key, string>  $stages
     * @param  array<array-key, int>  $elapsed
     * @return list<int>
     */
    public static function w64ProcessingStagePlanBatchPhp(array $stages, array $elapsed, int $budget = 0): array
    {
        $stages = array_values($stages);
        $count = \count($stages);
        $elapsed = self::w64Column($elapsed, $count);
        $budget = $budget <= 0 ? -1 : min($budget, self::W64_STAGE_BUDGET_CAP);
        $out = array_fill(0, self::W64_STAGE_HEADER, 0);
        $out[0] = $count;
        $out[28] = $out[29] = $out[30] = -1;
        $digest = 2_166_136_261;
        $rows = [];
        $total = 0;
        foreach ($stages as $at => $stage) {
            $stage = (string) $stage;
            $code = self::W64_STAGE_TOKENS[$stage] ?? null;
            if ($code === null) {
                $code = self::W64_STAGE_TOKENS[str_replace('_', '-', strtolower(trim($stage)))] ?? null;
                $case = $code === null || $code >= 16 ? 13 : $code;
                $source = $case === 13 ? 4 : 3;
            } else {
                $case = $code & 15;
                $source = $code >> 4;
            }
            $seconds = max(0, min((int) $elapsed[$at], self::W64_STAGE_ELAPSED_CAP));
            $timeout = self::W64_STAGE_TIMEOUT[$case];
            $gap = 0;
            if ($case === 13) {
                $state = 3;
            } elseif ($case > $out[28]) {
                $gap = $case > $out[28] + 1 ? 1 : 0;
                $out[28] = $case;
                $state = 0;
            } else {
                $state = $case === $out[28] ? 1 : 2;
            }
            $timedOut = $case < 13 && $seconds > $timeout ? 1 : 0;
            $total += $seconds;
            $over = $budget > 0 && $total > $budget ? 1 : 0;
            $out[1 + $case]++;
            $out[15 + $source]++;
            $out[20 + $state]++;
            $out[24] += $gap;
            $out[25] += $timedOut;
            $out[26] += $over;
            if ($state === 2 && $out[29] < 0) {
                $out[29] = $at;
            }
            if ($timedOut === 1 && $out[30] < 0) {
                $out[30] = $at;
            }
            $row = $case | $source << 4 | $state << 7 | $gap << 9 | $timedOut << 10 | $over << 11 | $timeout << 12 | $seconds << 23 | min(\strlen($stage), 255) << 54;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[27] = $total;
        $out[31] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust plan, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, string>  $stages
     * @param  array<array-key, int>  $elapsed
     * @return list<int>|null
     */
    public static function w64ProcessingStagePlanBatchNative(array $stages, array $elapsed, int $budget = 0): ?array
    {
        $count = \count($stages);
        if ($count > self::W64_MAX_ITEMS) {
            return null;
        }
        [$joined, $twin] = self::w64Strings(array_values($stages));
        $ints = pack('q*', ...self::w64Column($elapsed, $count));
        $words = self::W64_STAGE_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w64Call($words, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w646_processing_stage_batch($joined, \strlen($joined), $twin, \strlen($twin), $ints, \strlen($ints), $count, $budget, $out, $words));
    }

    /**
     * Route the Wave 64.6 batch plan through Rust for the rest of the process.
     */
    public static function enableW64RustFfi(): void
    {
        self::w64RustFfiState('set', true);
    }

    /**
     * Route the Wave 64.6 batch plan through PHP for the rest of the process.
     */
    public static function disableW64RustFfi(): void
    {
        self::w64RustFfiState('set', false);
    }

    /**
     * Restore the default Wave 64.6 routing and drop the cached binding and scratch buffer.
     */
    public static function resetW64RustFfi(): void
    {
        self::w64RustFfiState('reset');
    }

    public static function usesW64RustFfi(): bool
    {
        $forced = self::w64RustFfiState();

        return \is_bool($forced) ? $forced : self::W64_RUST_FFI_DEFAULT;
    }

    /**
     * Run `$callback` with the Wave 64.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW64RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w64RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w64RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 64.6 routing and binding in function statics (enums cannot declare properties). No `$op` returns
     * the forced routing (null for the default); `'set'` replaces it with `$val` and returns the previous
     * value; `'reset'` clears the routing, binding and buffer; `'binding'` returns the cached binding and an
     * output buffer of at least `$val` values, or null.
     */
    private static function w64RustFfiState(?string $op = null, mixed $val = null): mixed
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
        if ($op === 'reset') {
            $enabled = $ffi = $buffer = null;
            $capacity = 0;

            return null;
        }
        if ($op !== 'binding') {
            return $enabled;
        }
        $fields = \is_int($val) ? $val : 0;
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w646', self::W64_STAGE_FFI_CDEF) ?? false;
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
     * The batch's `$fields` output words from one Wave 64.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w64Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w64RustFfiState('binding', $fields);
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
    private static function w64Strings(array $items): array
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
    private static function w64Column(array $values, int $count, int|string $pad = 0): array
    {
        $values = array_values($values);

        return \count($values) === $count ? $values : array_pad(\array_slice($values, 0, $count), $count, $pad);
    }
}
