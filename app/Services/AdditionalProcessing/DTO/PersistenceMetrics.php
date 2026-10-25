<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
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
}
