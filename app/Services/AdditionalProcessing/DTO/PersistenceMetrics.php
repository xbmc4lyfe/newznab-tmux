<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use InvalidArgumentException;
use Throwable;

final class PersistenceMetrics
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w356_dto_provider(uint8_t operation, const char *input, size_t input_len, char *output, size_t output_capacity);
        C;

    // PHP remains the default until the complete caller clears the 1.5x gate.
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(?bool $enabled): void
    {
        self::$useRustFfi = $enabled;
        if ($enabled === null) {
            self::$ffi = null;
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    private static function rust(string $input, int $capacity): ?string
    {
        if (strlen($input) > 16_777_216 || $capacity < 1 || $capacity > 16_777_216) {
            return null;
        }
        try {
            self::$ffi ??= RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
            if (self::$ffi === false) {
                return null;
            }
            $output = self::$ffi->new("char[{$capacity}]");
            // @phpstan-ignore method.notFound
            $written = self::$ffi->nntmux_w356_dto_provider(4, $input, strlen($input), $output, $capacity);
            if (! is_int($written) || $written < 1 || $written > $capacity) {
                return null;
            }

            return FFI::string($output, $written);
        } catch (Throwable) {
            return null;
        }
    }

    public function __construct(
        public readonly int $databaseStatements = 0,
        public readonly float $databaseMilliseconds = 0.0,
        public readonly int $searchSyncRequests = 0,
        public readonly int $searchSyncExecutions = 0,
    ) {}

    /**
     * Finite nonnegative timings/counters, mean query cost and requests saved by coalescing.
     *
     * @return array{databaseStatements: int, databaseMilliseconds: float, searchSyncRequests: int, searchSyncExecutions: int, averageStatementMs: float, coalescedSearchSyncRequests: int}
     */
    public static function summarizeDatabaseAndSearchLoad(int $databaseStatements, float $databaseMilliseconds, int $searchSyncRequests, int $searchSyncExecutions): array
    {
        return (self::usesRustFfi() ? self::summarizeDatabaseAndSearchLoadNative($databaseStatements, $databaseMilliseconds, $searchSyncRequests, $searchSyncExecutions) : null)
            ?? self::summarizeDatabaseAndSearchLoadPhp($databaseStatements, $databaseMilliseconds, $searchSyncRequests, $searchSyncExecutions);
    }

    /** @return array{databaseStatements: int, databaseMilliseconds: float, searchSyncRequests: int, searchSyncExecutions: int, averageStatementMs: float, coalescedSearchSyncRequests: int} */
    public static function summarizeDatabaseAndSearchLoadPhp(int $databaseStatements, float $databaseMilliseconds, int $searchSyncRequests, int $searchSyncExecutions): array
    {
        $databaseStatements = max(0, $databaseStatements);
        $databaseMilliseconds = is_finite($databaseMilliseconds) && $databaseMilliseconds > 0.0 ? $databaseMilliseconds : 0.0;
        $searchSyncRequests = max(0, $searchSyncRequests);
        $searchSyncExecutions = max(0, $searchSyncExecutions);

        return [
            'databaseStatements' => $databaseStatements, 'databaseMilliseconds' => $databaseMilliseconds,
            'searchSyncRequests' => $searchSyncRequests, 'searchSyncExecutions' => $searchSyncExecutions,
            'averageStatementMs' => $databaseStatements > 0 ? $databaseMilliseconds / $databaseStatements : 0.0,
            'coalescedSearchSyncRequests' => max(0, $searchSyncRequests - $searchSyncExecutions),
        ];
    }

    /** @return array{databaseStatements: int, databaseMilliseconds: float, searchSyncRequests: int, searchSyncExecutions: int, averageStatementMs: float, coalescedSearchSyncRequests: int}|null */
    public static function summarizeDatabaseAndSearchLoadNative(int $databaseStatements, float $databaseMilliseconds, int $searchSyncRequests, int $searchSyncExecutions): ?array
    {
        $input = pack('qdq2', $databaseStatements, $databaseMilliseconds, $searchSyncRequests, $searchSyncExecutions);
        $bytes = self::rust($input, 48);
        if ($bytes === null || strlen($bytes) !== 48) {
            return null;
        }
        /** @var array{databaseStatements: int, databaseMilliseconds: float, searchSyncRequests: int, searchSyncExecutions: int, averageStatementMs: float, coalescedSearchSyncRequests: int} $result */
        $result = unpack('qdatabaseStatements/ddatabaseMilliseconds/qsearchSyncRequests/qsearchSyncExecutions/daverageStatementMs/qcoalescedSearchSyncRequests', $bytes);

        return $result;
    }

    // Rust is the default: the paired W48.6 benchmark cleared 1.5x on all four metrics (5.15x/3.13x common, 6.76x/4.20x uncommon mean/p99).
    public const bool W48_WRITE_TELEMETRY_RUST_FFI_DEFAULT = true;

    public const string W48_FFI_CDEF = <<<'C'
        int32_t nntmux_w48_persistence_telemetry(const char *ints, size_t ints_len, size_t count, int64_t window_micros, int64_t slow_micros, int64_t *out, size_t out_count);
        C;

    public const int W48_WRITE_FIELDS = 32;

    private const int W48_VALUE_CAP = 1_099_511_627_775;

    private const int W48_TOTAL_CAP = 4_611_686_018_427_387_904;

    /** Largest value whose `* 1_000_000` fits an integer. */
    private const int W48_THROUGHPUT_CAP = 8_796_093_022_207;

    /** Largest latency total whose `* 1_000_000` fits an integer. */
    private const int W48_PPM_CAP = 9_223_372_036_854;

    private const int W48_MAX_ITEMS = 1_048_576;

    private static ?bool $w48RustFfi = null;

    private static FFI|false|null $w48Ffi = null;

    private static ?CData $w48Out = null;

    public static function useW48WriteTelemetryRustFfi(?bool $enabled): void
    {
        self::$w48RustFfi = $enabled;
    }

    public static function usesW48WriteTelemetryRustFfi(): bool
    {
        return self::$w48RustFfi ?? self::W48_WRITE_TELEMETRY_RUST_FFI_DEFAULT;
    }

    public static function resetW48WriteTelemetryRustFfi(): void
    {
        self::$w48RustFfi = null;
        self::$w48Ffi = null;
        self::$w48Out = null;
    }

    /**
     * Database/disk write latency, artifact and throughput telemetry for a batch of persisted
     * releases. Latencies, artifact counts and bytes are clamped to [0, 2^40) and totals to 2^62;
     * a write is slow when its combined latency exceeds a positive `$slowMicros`.
     *
     * Layout: `[0]` count, `[1..7]` database and `[8..14]` disk latency sum, min, max, mean and
     * nearest-rank p50/p95/p99, `[15]` artifacts, `[16]` bytes, `[17]` bytes and `[18]` artifacts
     * per second over the window, `[19]` bytes per artifact, `[20]` slow writes, `[21]` longest
     * slow streak, `[22..26]` combined <1ms/<10ms/<100ms/<1s/longer, `[27]` first slowest index or
     * -1, `[28]` database share ppm of all latency, `[29]` mean artifacts per write, `[30]` writes
     * without artifacts and `[31]` an FNV-1a digest. Missing values are 0.
     *
     * @param  array<array-key, int>  $databaseMicros
     * @param  array<array-key, int>  $diskMicros
     * @param  array<array-key, int>  $artifacts
     * @param  array<array-key, int>  $bytesWritten
     * @return list<int>
     */
    public static function aggregateWriteTelemetryBatch(array $databaseMicros, array $diskMicros, array $artifacts, array $bytesWritten, int $windowMicros, int $slowMicros): array
    {
        return (self::usesW48WriteTelemetryRustFfi() ? self::aggregateWriteTelemetryBatchNative($databaseMicros, $diskMicros, $artifacts, $bytesWritten, $windowMicros, $slowMicros) : null)
            ?? self::aggregateWriteTelemetryBatchPhp($databaseMicros, $diskMicros, $artifacts, $bytesWritten, $windowMicros, $slowMicros);
    }

    /**
     * @param  array<array-key, int>  $databaseMicros
     * @param  array<array-key, int>  $diskMicros
     * @param  array<array-key, int>  $artifacts
     * @param  array<array-key, int>  $bytesWritten
     * @return list<int>
     */
    public static function aggregateWriteTelemetryBatchPhp(array $databaseMicros, array $diskMicros, array $artifacts, array $bytesWritten, int $windowMicros, int $slowMicros): array
    {
        $databaseMicros = array_values($databaseMicros);
        $diskMicros = array_values($diskMicros);
        $artifacts = array_values($artifacts);
        $bytesWritten = array_values($bytesWritten);
        $count = \count($databaseMicros);
        $out = array_fill(0, self::W48_WRITE_FIELDS, 0);
        $out[0] = $count;
        $out[27] = -1;
        $database = $disk = [];
        $produced = $bytes = $streak = 0;
        $max = -1;
        $digest = 2_166_136_261;
        for ($i = 0; $i < $count; $i++) {
            $db = min(max($databaseMicros[$i], 0), self::W48_VALUE_CAP);
            $io = min(max($diskMicros[$i] ?? 0, 0), self::W48_VALUE_CAP);
            $database[] = $db;
            $disk[] = $io;
            $made = min(max($artifacts[$i] ?? 0, 0), self::W48_VALUE_CAP);
            $produced = min($produced + $made, self::W48_TOTAL_CAP);
            $bytes = min($bytes + min(max($bytesWritten[$i] ?? 0, 0), self::W48_VALUE_CAP), self::W48_TOTAL_CAP);
            $out[30] += (int) ($made === 0);
            $combined = $db + $io;
            $late = $slowMicros > 0 && $combined > $slowMicros;
            if ($late) {
                $out[20]++;
                $streak++;
                if ($streak > $out[21]) {
                    $out[21] = $streak;
                }
            } else {
                $streak = 0;
            }
            if ($combined > $max) {
                $max = $combined;
                $out[27] = $i;
            }
            $bucket = $combined < 1_000 ? 0 : ($combined < 10_000 ? 1 : ($combined < 100_000 ? 2 : ($combined < 1_000_000 ? 3 : 4)));
            $out[22 + $bucket]++;
            $digest = (($digest ^ ($bucket | (int) $late << 3 | min($made, 255) << 4)) * 16_777_619) & 0xFFFFFFFF;
        }
        foreach ([1 => $database, 8 => $disk] as $at => $latencies) {
            foreach (self::w48LatencyStats($latencies) as $offset => $value) {
                $out[$at + $offset] = $value;
            }
        }
        $out[15] = $produced;
        $out[16] = $bytes;
        if ($windowMicros > 0) {
            $out[17] = intdiv(min($bytes, self::W48_THROUGHPUT_CAP) * 1_000_000, $windowMicros);
            $out[18] = intdiv(min($produced, self::W48_THROUGHPUT_CAP) * 1_000_000, $windowMicros);
        }
        if ($produced > 0) {
            $out[19] = intdiv($bytes, $produced);
        }
        $total = $out[1] + $out[8];
        if ($total > 0) {
            $share = $total <= self::W48_PPM_CAP ? intdiv($out[1] * 1_000_000, $total) : intdiv($out[1], intdiv($total, 1_000_000));
            $out[28] = min($share, 1_000_000);
        }
        if ($count > 0) {
            $out[29] = intdiv($produced, $count);
        }
        $out[31] = $digest;

        return $out;
    }

    /**
     * Rust telemetry, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, int>  $databaseMicros
     * @param  array<array-key, int>  $diskMicros
     * @param  array<array-key, int>  $artifacts
     * @param  array<array-key, int>  $bytesWritten
     * @return list<int>|null
     */
    public static function aggregateWriteTelemetryBatchNative(array $databaseMicros, array $diskMicros, array $artifacts, array $bytesWritten, int $windowMicros, int $slowMicros): ?array
    {
        $count = \count($databaseMicros);
        if ($count > self::W48_MAX_ITEMS) {
            return null;
        }
        try {
            self::$w48Ffi ??= RustPipelineLibrary::resolveDomain('additional_w48', self::W48_FFI_CDEF) ?? false;
            if (self::$w48Ffi === false) {
                return null;
            }
            $out = self::$w48Out ??= self::$w48Ffi->new('int64_t['.self::W48_WRITE_FIELDS.']');
            $ints = pack('q*', ...array_values($databaseMicros), ...self::w48Fit($diskMicros, $count), ...self::w48Fit($artifacts, $count), ...self::w48Fit($bytesWritten, $count));
            // @phpstan-ignore method.notFound
            $status = self::$w48Ffi->nntmux_w48_persistence_telemetry($ints, \strlen($ints), $count, $windowMicros, $slowMicros, $out, self::W48_WRITE_FIELDS);
            if ($status !== 1) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, self::W48_WRITE_FIELDS * 8));
        } catch (Throwable) {
            return null;
        }

        return $values === false ? null : array_values($values);
    }

    /**
     * Sum, min, max, mean and nearest-rank p50/p95/p99 (zeros past the sum when empty).
     *
     * @param  list<int>  $values
     * @return list<int>
     */
    private static function w48LatencyStats(array $values): array
    {
        $sum = 0;
        foreach ($values as $value) {
            $sum = min($sum + $value, self::W48_TOTAL_CAP);
        }
        $count = \count($values);
        if ($count === 0) {
            return [$sum, 0, 0, 0, 0, 0, 0];
        }
        sort($values);

        return [
            $sum, $values[0], $values[$count - 1], intdiv($sum, $count), $values[intdiv(50 * $count + 99, 100) - 1],
            $values[intdiv(95 * $count + 99, 100) - 1], $values[intdiv(99 * $count + 99, 100) - 1],
        ];
    }

    /**
     * @param  array<array-key, int>  $values
     * @return list<int>
     */
    private static function w48Fit(array $values, int $count): array
    {
        return \count($values) === $count && array_is_list($values) ? $values : array_pad(\array_slice(array_values($values), 0, $count), $count, 0);
    }

    // Rust is the default only while the paired W53.6 benchmark clears 1.5x on all four metrics.
    private const bool W53_RUST_FFI_DEFAULT = true;

    public const string W53_FFI_CDEF = <<<'C'
        int32_t nntmux_w536_load_summary_batch(const char *ints, size_t ints_len, const char *millis, size_t millis_len, size_t count, int64_t slow_micros, int64_t *out, size_t out_count);
        C;

    public const int W53_LOAD_HEADER = 10;

    /** Longest database time, in milliseconds, a load row keeps (its microseconds fit 2^50). */
    private const float W53_MS_CAP = 1e12;

    /**
     * `summarizeDatabaseAndSearchLoadPhp()` over load rows, in microseconds.
     *
     * Counters are clamped to [0, 2^40) and totals to 2^62; missing counters are 0. A non-finite or
     * non-positive time (or a missing one) counts as 0 and a longer one is capped at 1e12 ms. Layout:
     * `[0]` count, `[1]` statements, `[2]` database microseconds, `[3]` search requests, `[4]` search
     * executions, `[5]` coalesced requests, `[6]` slow rows (mean at least `$slowMicros` when that is
     * positive), `[7]` largest mean, `[8]` rows with statements, `[9]` FNV-1a digest; then per row the
     * truncated mean statement microseconds and `slow | (executions > requests) << 1 | coalesced << 2`.
     *
     * @param  array<array-key, int>  $databaseStatements
     * @param  array<array-key, float>  $databaseMilliseconds
     * @param  array<array-key, int>  $searchSyncRequests
     * @param  array<array-key, int>  $searchSyncExecutions
     * @return list<int>
     */
    public static function summarizeLoadBatch(array $databaseStatements, array $databaseMilliseconds, array $searchSyncRequests, array $searchSyncExecutions, int $slowMicros): array
    {
        return (self::usesW53RustFfi() ? self::summarizeLoadBatchNative($databaseStatements, $databaseMilliseconds, $searchSyncRequests, $searchSyncExecutions, $slowMicros) : null)
            ?? self::summarizeLoadBatchPhp($databaseStatements, $databaseMilliseconds, $searchSyncRequests, $searchSyncExecutions, $slowMicros);
    }

    /**
     * @param  array<array-key, int>  $databaseStatements
     * @param  array<array-key, float>  $databaseMilliseconds
     * @param  array<array-key, int>  $searchSyncRequests
     * @param  array<array-key, int>  $searchSyncExecutions
     * @return list<int>
     */
    public static function summarizeLoadBatchPhp(array $databaseStatements, array $databaseMilliseconds, array $searchSyncRequests, array $searchSyncExecutions, int $slowMicros): array
    {
        $databaseStatements = array_values($databaseStatements);
        $databaseMilliseconds = array_values($databaseMilliseconds);
        $searchSyncRequests = array_values($searchSyncRequests);
        $searchSyncExecutions = array_values($searchSyncExecutions);
        $count = \count($databaseStatements);
        $cap = self::W48_VALUE_CAP;
        $totalCap = self::W48_TOTAL_CAP;
        $out = array_fill(0, self::W53_LOAD_HEADER, 0);
        $out[0] = $count;
        $digest = 2_166_136_261;
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $statements = min(max($databaseStatements[$i], 0), $cap);
            $requests = min(max($searchSyncRequests[$i] ?? 0, 0), $cap);
            $executions = min(max($searchSyncExecutions[$i] ?? 0, 0), $cap);
            $ms = (float) ($databaseMilliseconds[$i] ?? 0.0);
            $ms = is_finite($ms) && $ms > 0.0 ? min($ms, self::W53_MS_CAP) : 0.0;
            $micros = $ms * 1000.0;
            $mean = $statements > 0 ? (int) ($micros / $statements) : 0;
            $coalesced = max($requests - $executions, 0);
            $slow = $slowMicros > 0 && $mean >= $slowMicros ? 1 : 0;
            $out[1] = min($out[1] + $statements, $totalCap);
            $out[2] = min($out[2] + (int) $micros, $totalCap);
            $out[3] = min($out[3] + $requests, $totalCap);
            $out[4] = min($out[4] + $executions, $totalCap);
            $out[5] = min($out[5] + $coalesced, $totalCap);
            $out[6] += $slow;
            if ($mean > $out[7]) {
                $out[7] = $mean;
            }
            if ($statements > 0) {
                $out[8]++;
            }
            $flags = $slow | ($executions > $requests ? 2 : 0) | $coalesced << 2;
            $digest = (($digest ^ (($mean ^ ($mean >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $digest = (($digest ^ (($flags ^ ($flags >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $mean;
            $rows[] = $flags;
        }
        $out[9] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust summaries, or null when PHP must compute them (unavailable library or rejected input).
     *
     * @param  array<array-key, int>  $databaseStatements
     * @param  array<array-key, float>  $databaseMilliseconds
     * @param  array<array-key, int>  $searchSyncRequests
     * @param  array<array-key, int>  $searchSyncExecutions
     * @return list<int>|null
     */
    public static function summarizeLoadBatchNative(array $databaseStatements, array $databaseMilliseconds, array $searchSyncRequests, array $searchSyncExecutions, int $slowMicros): ?array
    {
        $count = \count($databaseStatements);
        if ($count > self::W48_MAX_ITEMS) {
            return null;
        }
        if (\count($databaseMilliseconds) !== $count || ! array_is_list($databaseMilliseconds)) {
            $databaseMilliseconds = array_pad(\array_slice(array_values($databaseMilliseconds), 0, $count), $count, 0.0);
        }
        $ints = pack('q*', ...self::w48Fit($databaseStatements, $count), ...self::w48Fit($searchSyncRequests, $count), ...self::w48Fit($searchSyncExecutions, $count));
        $millis = pack('e*', ...$databaseMilliseconds);
        $fields = self::W53_LOAD_HEADER + 2 * $count;

        // @phpstan-ignore method.notFound
        return self::w53Call($fields, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w536_load_summary_batch($ints, \strlen($ints), $millis, \strlen($millis), $count, $slowMicros, $out, $fields));
    }

    /**
     * Run `$callback` with the Wave 53.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW53RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w53RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w53RustFfiState('set', $previous);
        }
    }

    public static function usesW53RustFfi(): bool
    {
        $forced = self::w53RustFfiState();

        return \is_bool($forced) ? $forced : self::W53_RUST_FFI_DEFAULT;
    }

    /**
     * Wave 53.6 routing and binding, kept in function statics. No `$op` returns the forced routing (null
     * for the default); `'set'` replaces it with `$val` and returns the previous value; `'binding'`
     * returns the cached `additional_w53` binding and an output buffer of at least `$val` values, or null.
     */
    private static function w53RustFfiState(?string $op = null, mixed $val = null): mixed
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
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w53', self::W53_FFI_CDEF) ?? false;
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
     * The batch's `$fields` output words from one Wave 53.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w53Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w53RustFfiState('binding', $fields);
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

    // ========================================================================
    // WAVE 64.4 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 1000-round paired runs reached 1.94x common mean, 1.62x common p99, 1.79x uncommon mean and 1.83x uncommon p99; see RustAdditionalSourcePlanPar2EntryPersistenceSlicerContainerW64ParityTest. */
    public const bool W64_RUST_FFI_DEFAULT = true;

    private const string W64_FFI_CDEF = 'ssize_t nntmux_w644_persistence_metrics_plan_batch(const char *blob, size_t blob_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);';

    /** int64 words per w64PersistenceMetricsPlanBatch() record. */
    public const int W64_ROW_WORDS = 4;

    /** int64 summary words after the w64PersistenceMetricsPlanBatch() records (none for an empty batch). */
    public const int W64_TRAILER_WORDS = 16;

    private const array W64_KINDS = ['media' => 1, 'audio' => 2, 'video' => 3, 'nfo' => 4, 'file' => 5];

    private const int W64_VALUE_CAP = (1 << 40) - 1;

    /**
     * Persistence counter and latency rollup rows for [artifact kind, error message, release id,
     * write microseconds, rows written, bytes written] writes.
     *
     * Kinds (trimmed, case-insensitive): 1 media, 2 audio, 3 video, 4 nfo, 5 file, else 0. A write
     * succeeded when its trimmed error is empty. Latencies and bytes clamp to [0, 2^40), rows to
     * [0, 2^24); latency buckets are <1ms, <10ms, <100ms, <1s and longer. Flags: 1 succeeded, 2 the
     * release already wrote this kind, 4 release id < 1, 8 unknown kind. Release ordinals number
     * distinct release ids by first appearance.
     *
     * Rows [kind | flags << 4 | bucket << 8 | rows << 12 | trimmed error length << 40 (clamped to
     * 4095), fnv1a32(trimmed error) (0 when empty), microseconds, release ordinal], then the trailer
     * [writes, other, media, audio, video, nfo and file writes, failures, repeated release kinds,
     * distinct releases, total microseconds, longest write, its first index, rows, bytes, FNV-1a
     * digest].
     *
     * @param  list<array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int}>  $items
     * @return list<int>
     */
    public static function w64PersistenceMetricsPlanBatch(array $items): array
    {
        return self::usesW64RustFfi() ? self::w64PersistenceMetricsPlanBatchNative($items) : self::w64PersistenceMetricsPlanBatchPhp($items);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int}>  $items
     * @return list<int>
     */
    public static function w64PersistenceMetricsPlanBatchPhp(array $items): array
    {
        self::w64Items($items);
        if ($items === []) {
            return [];
        }
        $out = $seen = $releases = [];
        $t = array_fill(0, self::W64_TRAILER_WORDS, 0);
        $t[0] = \count($items);
        $t[12] = -1;
        $max = -1;
        $digest = 2_166_136_261;
        foreach ($items as $i => [$kind, $error, $release, $micros, $rows, $bytes]) {
            $code = self::W64_KINDS[strtolower(trim($kind))] ?? 0;
            $error = trim($error);
            $micros = max(0, min($micros, self::W64_VALUE_CAP));
            $rows = max(0, min($rows, 0xFFFFFF));
            $bytes = max(0, min($bytes, self::W64_VALUE_CAP));
            $ok = $error === '';
            $dup = isset($seen[$release][$code]);
            $seen[$release][$code] = true;
            $ordinal = $releases[$release] ??= \count($releases);
            $flags = ($ok ? 1 : 0) | ($dup ? 2 : 0) | ($release <= 0 ? 4 : 0) | ($code === 0 ? 8 : 0);
            $hash = self::w64Fnv($error);
            $bucket = $micros < 1_000 ? 0 : ($micros < 10_000 ? 1 : ($micros < 100_000 ? 2 : ($micros < 1_000_000 ? 3 : 4)));
            $word = $code | $flags << 4 | $bucket << 8 | $rows << 12 | min(\strlen($error), 0xFFF) << 40;
            array_push($out, $word, $hash, $micros, $ordinal);
            $t[1 + $code]++;
            if (! $ok) {
                $t[7]++;
            }
            if ($dup) {
                $t[8]++;
            }
            $t[10] += $micros;
            if ($micros > $max) {
                $max = $micros;
                $t[12] = $i;
            }
            $t[13] += $rows;
            $t[14] += $bytes;
            $value = $word ^ $hash << 20 ^ $micros;
            $digest = (($digest ^ (($value ^ ($value >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
        }
        $t[9] = \count($releases);
        $t[11] = $max;
        $t[15] = $digest;

        return [...$out, ...$t];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int}>  $items
     * @return list<int>
     */
    public static function w64PersistenceMetricsPlanBatchNative(array $items): array
    {
        self::w64Items($items);
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        [$blob, $twin] = self::w64Join([array_column($items, 0), array_column($items, 1)]);
        $ints = pack('P*', ...array_column($items, 2), ...array_column($items, 3), ...array_column($items, 4), ...array_column($items, 5));

        return self::w64Call($blob, $twin, $ints, $count, $count * self::W64_ROW_WORDS + self::W64_TRAILER_WORDS)
            ?? self::w64PersistenceMetricsPlanBatchPhp($items);
    }

    /** @param array<array-key, mixed> $items */
    private static function w64Items(array $items): void
    {
        if (! array_is_list($items)) {
            throw new InvalidArgumentException('W64 persistence metrics plan batch items must be a list.');
        }
        foreach ($items as $item) {
            if (! \is_array($item) || \count($item) !== 6 || ! array_is_list($item) || ! \is_string($item[0]) || ! \is_string($item[1]) || ! \is_int($item[2]) || ! \is_int($item[3]) || ! \is_int($item[4]) || ! \is_int($item[5])) {
                throw new InvalidArgumentException('W64 persistence metrics plan batch items must be [string kind, string error, int release id, int microseconds, int rows, int bytes] lists.');
            }
        }
    }

    public static function usesW64RustFfi(): bool
    {
        $enabled = self::w64RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W64_RUST_FFI_DEFAULT;
    }

    public static function enableW64RustFfi(): void
    {
        self::w64RustFfiState('enabled', true);
    }

    public static function disableW64RustFfi(): void
    {
        self::w64RustFfiState('enabled', false);
    }

    /** Drop the W64 override, binding, output buffer and declined-call count; routing returns to W64_RUST_FFI_DEFAULT. */
    public static function resetW64RustFfi(): void
    {
        self::w64RustFfiState('reset');
    }

    /**
     * Run $callback with the W64 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW64RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w64RustFfiState('enabled');
        self::w64RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w64RustFfiState('enabled', $previous);
        }
    }

    /**
     * String columns NUL-joined field-major and, when a value holds a NUL, the same columns
     * joined by 0x01 so Rust finds the real separators.
     *
     * @param  list<list<string>>  $columns
     * @return array{0: string, 1: string}
     */
    private static function w64Join(array $columns): array
    {
        $values = array_merge(...$columns);
        $joined = implode("\0", $values);

        return substr_count($joined, "\0") === \count($values) - 1 ? [$joined, ''] : [$joined, implode("\x01", $values)];
    }

    /**
     * One FFI crossing for the batch: $need int64 words back. Null when the library is missing
     * or declines a malformed frame, so the caller falls back to PHP; a declined call with a
     * resolved library is counted in the 'misses' state.
     *
     * @return list<int>|null
     */
    private static function w64Call(string $blob, string $twin, string $ints, int $count, int $need): ?array
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        $state = self::w64RustFfiState();
        $ffi = $state['ffi'];
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata_w64', self::W64_FFI_CDEF) ?? false;
            self::w64RustFfiState('ffi', $ffi);
        }
        if (! $ffi instanceof FFI) {
            return null;
        }
        try {
            $out = $state['out'];
            $cap = $state['cap'];
            if (! $out instanceof CData || $cap < $need) {
                $cap = max(4096, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w64RustFfiState('out', $out);
                self::w64RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W64_FFI_CDEF.)
            $written = $ffi->nntmux_w644_persistence_metrics_plan_batch($blob, \strlen($blob), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $cap);
            $values = $written === $need ? unpack('P*', FFI::string($out, $need * 8)) : false;
            if ($values !== false) {
                return array_values($values);
            }
        } catch (Throwable) {
        }
        self::w64RustFfiState('misses', $state['misses'] + 1);

        return null;
    }

    /**
     * W64 routing override, cached binding, reusable output buffer and declined-call count, kept
     * in a function static so the class declares no new property: no arguments returns the state,
     * one argument reads a key ('reset' clears everything) and two arguments write a key.
     */
    private static function w64RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;

            return null;
        }

        return $state[$op] ?? null;
    }

    /** FNV-1a 32 of $bytes as an unsigned int, 0 for an empty string. */
    private static function w64Fnv(string $bytes): int
    {
        if ($bytes === '') {
            return 0;
        }
        /** @var array{1: int} $hash */
        $hash = unpack('N', hash('fnv1a32', $bytes, true));

        return $hash[1];
    }
}
