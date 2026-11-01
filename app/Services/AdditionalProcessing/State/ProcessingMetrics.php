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
}
