<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\State;

use App\Services\AdditionalProcessing\Enums\ProcessingStage;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use FFI\CData;
use Throwable;

final class ProcessingMetrics
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_pmetrics_ns_to_seconds(const char *packed, size_t len, char *out, size_t cap);
        double nntmux_pmetrics_rate(int64_t count, double seconds);
        ssize_t nntmux_pmetrics_format_summary(const char *packed, size_t len, char *out, size_t cap);
        C;

    public const bool W466_RUST_FFI_DEFAULT = false;

    public const string W466_FFI_CDEF = <<<'C'
        ssize_t nntmux_w466_stage_summary(const char *keys, size_t keys_len, size_t count, const char *nanoseconds, size_t nanoseconds_len, char *out, size_t cap);
        C;

    // PHP remains the production default: FFI marshaling stays below the 1.5x gate.
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private static ?bool $w466RustFfi = null;

    private static FFI|false|null $w466Ffi = null;

    private readonly int $startedAtNanoseconds;

    /**
     * @var array<string, int>
     */
    private array $stageNanoseconds = [];

    public function __construct()
    {
        $this->startedAtNanoseconds = hrtime(true);
    }

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function useW466RustFfi(?bool $enabled): void
    {
        self::$w466RustFfi = $enabled;
    }

    public static function usesW466RustFfi(): bool
    {
        return self::$w466RustFfi ?? self::W466_RUST_FFI_DEFAULT;
    }

    public static function resetW466RustFfi(): void
    {
        self::$w466RustFfi = null;
        self::$w466Ffi = null;
    }

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $operation
     * @return TValue
     */
    public function measure(ProcessingStage $stage, Closure $operation): mixed
    {
        $startedAt = hrtime(true);

        try {
            return $operation();
        } finally {
            $elapsed = hrtime(true) - $startedAt;
            $this->stageNanoseconds[$stage->value] = ($this->stageNanoseconds[$stage->value] ?? 0) + $elapsed;
        }
    }

    public function elapsedSeconds(): float
    {
        return $this->nanosecondsToSeconds(hrtime(true) - $this->startedAtNanoseconds);
    }

    /**
     * @return array<string, float>
     */
    public function stageDurations(): array
    {
        return self::secondsFromNanoseconds($this->stageNanoseconds);
    }

    /**
     * Releases handled per second since construction.
     */
    public function releasesPerSecond(int $releases): float
    {
        return self::rate($releases, $this->elapsedSeconds());
    }

    /**
     * One-line `stage=seconds` summary of the stage durations.
     */
    public function summaryLine(): string
    {
        return self::usesW466RustFfi()
            ? self::summaryFromNanoseconds($this->stageNanoseconds)
            : self::formatStageSummary($this->stageDurations());
    }

    /**
     * `stage=seconds` summary straight from nanosecond totals in one call.
     *
     * @param  array<array-key, int>  $nanoseconds
     */
    public static function summaryFromNanoseconds(array $nanoseconds): string
    {
        return (self::usesW466RustFfi() ? self::summaryFromNanosecondsNative($nanoseconds) : null)
            ?? self::summaryFromNanosecondsPhp($nanoseconds);
    }

    /**
     * @param  array<array-key, int>  $nanoseconds
     */
    public static function summaryFromNanosecondsPhp(array $nanoseconds): string
    {
        return self::formatStageSummaryPhp(self::secondsFromNanosecondsPhp($nanoseconds));
    }

    /**
     * Rust summary, or null when PHP must format it (non-integer totals, which the
     * strict int conversion rejects, keys with NUL bytes or an unavailable library).
     *
     * @param  array<array-key, mixed>  $nanoseconds
     */
    public static function summaryFromNanosecondsNative(array $nanoseconds): ?string
    {
        if ($nanoseconds === []) {
            return '';
        }
        $ffi = self::$w466Ffi instanceof FFI ? self::$w466Ffi : self::w466Ffi();
        $count = \count($nanoseconds);
        if ($ffi === null || \count(array_filter($nanoseconds, 'is_int')) !== $count) {
            return null;
        }
        $keys = implode("\0", array_keys($nanoseconds));
        if (substr_count($keys, "\0") !== $count - 1) {
            return null;
        }
        $values = pack('q*', ...array_values($nanoseconds));

        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, \strlen($keys) + 24 * $count, $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w466_stage_summary($keys, \strlen($keys), $count, $values, \strlen($values), $scratch, $cap);
            if ($written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w466_stage_summary($keys, \strlen($keys), $count, $values, \strlen($values), $scratch, $cap);
            }

            return $written > 0 && $written <= $cap ? FFI::string($scratch, $written) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, int>  $nanoseconds
     * @return array<TKey, float>
     */
    public static function secondsFromNanoseconds(array $nanoseconds): array
    {
        return (self::$useRustFfi ? self::secondsFromNanosecondsNative($nanoseconds) : null) ?? self::secondsFromNanosecondsPhp($nanoseconds);
    }

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, int>  $nanoseconds
     * @return array<TKey, float>
     */
    public static function secondsFromNanosecondsPhp(array $nanoseconds): array
    {
        return array_map(self::nanosecondsToSecondsPhp(...), $nanoseconds);
    }

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, int|float>  $nanoseconds  an overflowed counter is a float
     * @return array<TKey, float>|null Null when PHP must convert the values.
     */
    public static function secondsFromNanosecondsNative(array $nanoseconds): ?array
    {
        if ($nanoseconds === []) {
            return [];
        }
        foreach ($nanoseconds as $value) {
            if (! \is_int($value)) {
                return null;
            }
        }
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            $packed = pack('q*', ...array_values($nanoseconds));
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, \strlen($packed), $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_pmetrics_ns_to_seconds($packed, \strlen($packed), $scratch, $cap);
            if ($written !== \strlen($packed)) {
                return null;
            }
            $seconds = unpack('e*', FFI::string($scratch, $written));
        } catch (Throwable) {
            return null;
        }
        if (! \is_array($seconds)) {
            return null;
        }

        /** @var list<float> $values */
        $values = array_values($seconds);

        return array_combine(array_keys($nanoseconds), $values);
    }

    /**
     * Events per second, or 0.0 when no time elapsed.
     */
    public static function rate(int $count, float $seconds): float
    {
        return (self::$useRustFfi ? self::rateNative($count, $seconds) : null) ?? self::ratePhp($count, $seconds);
    }

    public static function ratePhp(int $count, float $seconds): float
    {
        return $seconds > 0 ? $count / $seconds : 0.0;
    }

    public static function rateNative(int $count, float $seconds): ?float
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            return (float) $ffi->nntmux_pmetrics_rate($count, $seconds);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<array-key, float>  $durations
     */
    public static function formatStageSummary(array $durations): string
    {
        return (self::$useRustFfi ? self::formatStageSummaryNative($durations) : null) ?? self::formatStageSummaryPhp($durations);
    }

    /**
     * @param  array<array-key, float>  $durations
     */
    public static function formatStageSummaryPhp(array $durations): string
    {
        $parts = [];
        foreach ($durations as $stage => $seconds) {
            $parts[] = $stage.'='.sprintf('%.6F', $seconds).'s';
        }

        return implode(' ', $parts);
    }

    /**
     * @param  array<array-key, float>  $durations
     */
    public static function formatStageSummaryNative(array $durations): ?string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $packed = '';
        foreach ($durations as $stage => $seconds) {
            if (! \is_float($seconds) && ! \is_int($seconds)) {
                return null;
            }
            $key = (string) $stage;
            $packed .= pack('V', \strlen($key)).$key.pack('e', (float) $seconds);
        }
        if ($packed === '') {
            return '';
        }
        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, \strlen($packed) + 16 * \count($durations), $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_pmetrics_format_summary($packed, \strlen($packed), $scratch, $cap);
            if ($written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_pmetrics_format_summary($packed, \strlen($packed), $scratch, $cap);
            }

            return $written > 0 ? FFI::string($scratch, $written) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function nanosecondsToSecondsPhp(int $nanoseconds): float
    {
        return $nanoseconds / 1_000_000_000;
    }

    private function nanosecondsToSeconds(int $nanoseconds): float
    {
        return self::nanosecondsToSecondsPhp($nanoseconds);
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    private static function w466Ffi(): ?FFI
    {
        if (self::$w466Ffi === null) {
            self::$w466Ffi = RustPipelineLibrary::resolveDomain('additional', self::W466_FFI_CDEF) ?? false;
        }

        return self::$w466Ffi === false ? null : self::$w466Ffi;
    }

    private const bool W55_RUST_FFI_DEFAULT = true;

    public const string W55_FFI_CDEF = <<<'C'
        int32_t nntmux_w556_stage_summary_batch(const char *keys, size_t keys_len, const char *key_twin, size_t key_twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    public const int W55_STAGE_HEADER = 21;

    private const int W55_MAX_ITEMS = 1_048_576;

    private const int W55_VALUE_CAP = 1_099_511_627_775;

    /** ProcessingStage backing value => case index; any other key is code 13. */
    private const array W55_STAGE_CODES = [
        'workspace-preparation' => 0, 'nzb-parsing' => 1, 'release-initialization' => 2, 'message-id-selection' => 3,
        'media-source-planning' => 4, 'direct-downloads' => 5, 'archive-downloads' => 6, 'extracted-files' => 7,
        'media-sources' => 8, 'archive-fallbacks' => 9, 'timeout-handling' => 10, 'finalization' => 11, 'workspace-cleanup' => 12,
    ];

    /**
     * Summarize stage timing records of many releases: a stage key (a `ProcessingStage` value) and
     * nanoseconds (clamped to 0..2^40-1; a missing value is 0) per record.
     *
     * Layout: `[0]` count, `[1]` total nanoseconds, `[2..15]` nanoseconds per code (case index, 13 for
     * unknown keys), `[16]` first slowest record (-1 when none), `[17]` unknown keys, `[18]` distinct
     * known stages, `[19]` known stage bitmask, `[20]` FNV-1a digest; then per record `code | share_ppm
     * << 4 | milliseconds << 24` (share of the total, 0 when the total is 0).
     *
     * @param  array<array-key, string>  $stages
     * @param  array<array-key, int>  $nanoseconds
     * @return list<int>
     */
    public static function summarizeStageBatch(array $stages, array $nanoseconds): array
    {
        return (self::usesW55RustFfi() ? self::summarizeStageBatchNative($stages, $nanoseconds) : null)
            ?? self::summarizeStageBatchPhp($stages, $nanoseconds);
    }

    /**
     * @param  array<array-key, string>  $stages
     * @param  array<array-key, int>  $nanoseconds
     * @return list<int>
     */
    public static function summarizeStageBatchPhp(array $stages, array $nanoseconds): array
    {
        $stages = array_values($stages);
        $nanoseconds = array_values($nanoseconds);
        $count = \count($stages);
        $out = array_fill(0, self::W55_STAGE_HEADER, 0);
        $out[0] = $count;
        $slowAt = -1;
        $slow = 0;
        $mask = 0;
        $codes = $values = [];
        for ($i = 0; $i < $count; $i++) {
            $code = self::W55_STAGE_CODES[$stages[$i]] ?? 13;
            $ns = min(max($nanoseconds[$i] ?? 0, 0), self::W55_VALUE_CAP);
            $out[1] += $ns;
            $out[2 + $code] += $ns;
            if ($slowAt < 0 || $ns > $slow) {
                $slowAt = $i;
                $slow = $ns;
            }
            if ($code === 13) {
                $out[17]++;
            } else {
                $mask |= 1 << $code;
            }
            $codes[] = $code;
            $values[] = $ns;
        }
        $total = $out[1];
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($codes as $i => $code) {
            $ns = $values[$i];
            $row = $code | ($total > 0 ? intdiv($ns * 1_000_000, $total) : 0) << 4 | intdiv($ns, 1_000_000) << 24;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $bits = $mask - (($mask >> 1) & 0x5555);
        $bits = ($bits & 0x3333) + (($bits >> 2) & 0x3333);
        $bits = ($bits + ($bits >> 4)) & 0x0F0F;
        $out[16] = $slowAt;
        $out[18] = ($bits + ($bits >> 8)) & 0x1F;
        $out[19] = $mask;
        $out[20] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust summary, or null when PHP must compute it (unavailable library or rejected input). Keys
     * cross NUL-joined; Rust rejects a join with extra NULs, and only then does a 0x01-joined twin
     * cross with a second call, so the common batch skips the NUL scan.
     *
     * @param  array<array-key, string>  $stages
     * @param  array<array-key, int>  $nanoseconds
     * @return list<int>|null
     */
    public static function summarizeStageBatchNative(array $stages, array $nanoseconds): ?array
    {
        $count = \count($stages);
        if ($count > self::W55_MAX_ITEMS) {
            return null;
        }
        $joined = implode("\0", $stages);
        $ints = pack('q*', ...self::w55Fit($nanoseconds, $count));
        $fields = self::W55_STAGE_HEADER + $count;
        $twin = '';
        // @phpstan-ignore method.notFound
        $call = static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w556_stage_summary_batch($joined, \strlen($joined), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $fields);
        $result = self::w55Call($fields, $call);
        if ($result !== null || $count === 0 || substr_count($joined, "\0") === $count - 1) {
            return $result;
        }
        $twin = implode("\x01", $stages);

        // @phpstan-ignore method.notFound
        return self::w55Call($fields, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w556_stage_summary_batch($joined, \strlen($joined), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $fields));
    }

    /**
     * Run `$callback` with the Wave 55.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW55RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w55RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w55RustFfiState('set', $previous);
        }
    }

    public static function usesW55RustFfi(): bool
    {
        $forced = self::w55RustFfiState();

        return \is_bool($forced) ? $forced : self::W55_RUST_FFI_DEFAULT;
    }

    /**
     * Wave 55.6 routing and binding, kept in function statics so earlier waves' static state stays
     * untouched. No `$op` returns the forced routing (null for the default); `'set'` replaces it with
     * `$val` and returns the previous value; `'binding'` returns the cached `additional_w55` binding and
     * an output buffer of at least `$val` values, or null.
     */
    private static function w55RustFfiState(?string $op = null, mixed $val = null): mixed
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
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w55', self::W55_FFI_CDEF) ?? false;
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
     * The batch's `$fields` output words from one Wave 55.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w55Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w55RustFfiState('binding', $fields);
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
     * Exactly `$count` values: the list itself when it already fits, otherwise padded with 0.
     *
     * @param  array<array-key, int>  $values
     * @return list<int>
     */
    private static function w55Fit(array $values, int $count): array
    {
        return \count($values) === $count && array_is_list($values) ? $values : array_pad(\array_slice(array_values($values), 0, $count), $count, 0);
    }

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 8.77x, p99 6.40x; uncommon mean 7.60x, p99 4.42x); see RustAdditional7zParserBudgetPersistenceMetricsContextW65ParityTest. */
    public const bool W65_RUST_FFI_DEFAULT = true;

    public const string W65_PROCESSING_METRICS_FFI_CDEF = <<<'C'
        ssize_t nntmux_w653_processing_metrics_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of the Wave 65.3 batch. */
    public const int W65_PROCESSING_METRICS_TRAILER = 22;

    /** Cap for the Wave 65.3 running totals. */
    private const int W65_TOTAL_CAP = 4_611_686_018_427_387_904;

    public static function enableW65RustFfi(): void
    {
        self::w65RustFfiState('set', true);
    }

    public static function disableW65RustFfi(): void
    {
        self::w65RustFfiState('set', false);
    }

    /** Restores W65_RUST_FFI_DEFAULT and drops the cached library handle and scratch buffers. */
    public static function resetW65RustFfi(): void
    {
        self::w65RustFfiState('reset');
    }

    public static function usesW65RustFfi(): bool
    {
        $override = self::w65RustFfiState();

        return \is_bool($override) ? $override : self::W65_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the ProcessingMetrics Wave 65 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW65RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w65RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w65RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 65.3 processing stage timing, counter and throughput plan. Each item is
     * `stage|nanoseconds|releases|downloads|extractions|mediaFiles`; the trimmed stage is 0 empty, 1-13 for the
     * ProcessingStage values in case order (any case) or 14 other, the duration is 0 empty, 1 up to 15 trimmed ASCII
     * digits, 2 other, and the four counters the same with up to 6 digits (invalid values count as 0). An item is
     * invalid with stage 14 or any state 2 and then stays out of every total. The rate is releases per second * 1000
     * (capped at 2^20 - 1, 0 without a duration). Per item `stage | nanosecondsState << 4 | releasesState << 6 |
     * downloadsState << 8 | extractionsState << 10 | mediaState << 12 | invalid << 14 | rate << 15 | milliseconds <<
     * 35` (capped at 2^27 - 1). Then the thirteen per-stage nanosecond totals, the invalid count, the nanosecond,
     * release, download, extraction and media file totals (each capped at 2^62), the code of the first stage with the
     * strictly largest total (0 when none), the aggregate rate (releases capped at 9,000,000, rate at 2^40) and the
     * distinct valid stages seen.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w65ProcessingMetricsPlanBatch(array $items): array
    {
        return self::usesW65RustFfi() ? self::w65ProcessingMetricsPlanBatchNative($items) : self::w65ProcessingMetricsPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w65ProcessingMetricsPlanBatchNative(array $items): array
    {
        return self::w65Invoke($items, \count($items) + self::W65_PROCESSING_METRICS_TRAILER) ?? self::w65ProcessingMetricsPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w65ProcessingMetricsPlanBatchPhp(array $items): array
    {
        $out = [];
        $stageNs = array_fill(0, 13, 0);
        $seen = [];
        $invalidCount = $totalNs = $totalReleases = $totalDownloads = $totalExtractions = $totalMedia = 0;
        foreach ($items as $item) {
            [$rawStage, $rawNs, $rawReleases, $rawDownloads, $rawExtractions, $rawMedia] = explode('|', (string) $item, 6) + ['', '', '', '', '', ''];
            $stageText = strtolower(trim($rawStage));
            $stage = $stageText === '' ? 0 : (self::W65_STAGES[$stageText] ?? 14);
            [$nsState, $ns] = self::w65Count($rawNs, 15);
            [$releasesState, $releases] = self::w65Count($rawReleases, 6);
            [$downloadsState, $downloads] = self::w65Count($rawDownloads, 6);
            [$extractionsState, $extractions] = self::w65Count($rawExtractions, 6);
            [$mediaState, $media] = self::w65Count($rawMedia, 6);
            $invalid = $stage === 14 || $nsState === 2 || $releasesState === 2 || $downloadsState === 2 || $extractionsState === 2 || $mediaState === 2;
            $rate = $ns > 0 ? min(intdiv($releases * 1_000_000_000_000, $ns), 1_048_575) : 0;
            if ($invalid) {
                $invalidCount++;
            } else {
                if ($stage > 0) {
                    $stageNs[$stage - 1] = min($stageNs[$stage - 1] + $ns, self::W65_TOTAL_CAP);
                    $seen[$stage] = true;
                }
                $totalNs = min($totalNs + $ns, self::W65_TOTAL_CAP);
                $totalReleases = min($totalReleases + $releases, self::W65_TOTAL_CAP);
                $totalDownloads = min($totalDownloads + $downloads, self::W65_TOTAL_CAP);
                $totalExtractions = min($totalExtractions + $extractions, self::W65_TOTAL_CAP);
                $totalMedia = min($totalMedia + $media, self::W65_TOTAL_CAP);
            }
            $out[] = $stage | $nsState << 4 | $releasesState << 6 | $downloadsState << 8 | $extractionsState << 10 | $mediaState << 12
                | ($invalid ? 1 : 0) << 14 | $rate << 15 | min(intdiv($ns, 1_000_000), 134_217_727) << 35;
        }
        $slowest = $slowestNs = 0;
        foreach ($stageNs as $offset => $value) {
            if ($value > $slowestNs) {
                $slowest = $offset + 1;
                $slowestNs = $value;
            }
        }
        $overall = $totalNs > 0 ? min(intdiv(min($totalReleases, 9_000_000) * 1_000_000_000_000, $totalNs), 1_099_511_627_776) : 0;
        array_push($out, ...$stageNs);
        array_push($out, $invalidCount, $totalNs, $totalReleases, $totalDownloads, $totalExtractions, $totalMedia, $slowest, $overall, \count($seen));

        return $out;
    }

    /**
     * (0 empty, 1 one to $max ASCII digits, 2 other; the value) for the trimmed field.
     *
     * @return array{int, int}
     */
    private static function w65Count(string $text, int $max): array
    {
        $text = trim($text);
        if ($text === '') {
            return [0, 0];
        }
        $length = \strlen($text);

        return $length <= $max && strspn($text, '0123456789') === $length ? [1, (int) $text] : [2, 0];
    }

    /** ProcessingStage value => 1-based case position (empty 0, anything else 14). */
    private const array W65_STAGES = ['workspace-preparation' => 1, 'nzb-parsing' => 2, 'release-initialization' => 3, 'message-id-selection' => 4,
        'media-source-planning' => 5, 'direct-downloads' => 6, 'archive-downloads' => 7, 'extracted-files' => 8, 'media-sources' => 9,
        'archive-fallbacks' => 10, 'timeout-handling' => 11, 'finalization' => 12, 'workspace-cleanup' => 13];

    /**
     * Wave 65 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and returns the
     * previous override, 'reset' clears the override, library handle and buffers, 'ffi' resolves the library
     * (null when unavailable) and 'out' and 'lens' return `int64_t` output and length-column buffers of at least
     * $val words. Function statics keep the class's property set unchanged.
     */
    private static function w65RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'reset') {
            $override = $ffi = $out = $lens = null;
            $outCap = $lensCap = 0;

            return null;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w653', self::W65_PROCESSING_METRICS_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w65RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w65RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 65.3 ProcessingMetrics export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call declines
     * or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w65Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w65RustFfiState('ffi');
            $out = self::w65RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w65RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W65_PROCESSING_METRICS_FFI_CDEF.)
            $written = $ffi->nntmux_w653_processing_metrics_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
