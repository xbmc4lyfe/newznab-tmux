<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

final class DownloadMetrics
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w356_dto_provider(uint8_t operation, const char *input, size_t input_len, char *output, size_t output_capacity);
        C;

    // PHP remains the default until the complete caller clears the 1.5x gate.
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    // PHP stays the default: uncommon p99 reached only 1.41x (3.80x/2.44x common, 2.01x/1.41x uncommon mean/p99).
    public const bool W47_ARTICLE_STATS_RUST_FFI_DEFAULT = false;

    public const string W47_FFI_CDEF = <<<'C'
        int32_t nntmux_w47_article_metrics(const char *ints, size_t ints_len, size_t count, int64_t window_micros, int64_t link_bytes_per_second, int64_t *out, size_t out_count);
        C;

    public const int W47_ARTICLE_FIELDS = 20;

    private const int W47_VALUE_CAP = 1_099_511_627_775;

    private const int W47_TOTAL_CAP = 4_611_686_018_427_387_904;

    /** Largest byte total whose `* 1_000_000` fits an integer. */
    private const int W47_THROUGHPUT_BYTES_CAP = 8_796_093_022_207;

    /** Largest throughput whose `* 1_000_000` fits an integer. */
    private const int W47_UTILIZATION_CAP = 9_223_372_036_854;

    private const int W47_MAX_ITEMS = 1_048_576;

    private static ?bool $w47RustFfi = null;

    private static FFI|false|null $w47Ffi = null;

    private static ?CData $w47Out = null;

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
            $written = self::$ffi->nntmux_w356_dto_provider(1, $input, strlen($input), $output, $capacity);
            if (! is_int($written) || $written < 1 || $written > $capacity) {
                return null;
            }

            return FFI::string($output, $written);
        } catch (Throwable) {
            return null;
        }
    }

    public function __construct(
        public readonly int $logicalRequests = 0,
        public readonly int $networkRequests = 0,
        public readonly int $cacheHits = 0,
        public readonly int $bytesDownloaded = 0,
        public readonly int $bytesReused = 0,
        public readonly int $mediaBytesDownloaded = 0,
    ) {}

    /**
     * Nonnegative counters and bounded fractions (zero when their denominator is zero).
     *
     * @return array{logicalRequests: int, networkRequests: int, cacheHits: int, bytesDownloaded: int, bytesReused: int, mediaBytesDownloaded: int, cacheHitRatio: float, byteReuseRatio: float, mediaByteRatio: float}
     */
    public static function summarizeTransferEfficiency(int $logicalRequests, int $networkRequests, int $cacheHits, int $bytesDownloaded, int $bytesReused, int $mediaBytesDownloaded): array
    {
        return (self::usesRustFfi() ? self::summarizeTransferEfficiencyNative($logicalRequests, $networkRequests, $cacheHits, $bytesDownloaded, $bytesReused, $mediaBytesDownloaded) : null)
            ?? self::summarizeTransferEfficiencyPhp($logicalRequests, $networkRequests, $cacheHits, $bytesDownloaded, $bytesReused, $mediaBytesDownloaded);
    }

    /** @return array{logicalRequests: int, networkRequests: int, cacheHits: int, bytesDownloaded: int, bytesReused: int, mediaBytesDownloaded: int, cacheHitRatio: float, byteReuseRatio: float, mediaByteRatio: float} */
    public static function summarizeTransferEfficiencyPhp(int $logicalRequests, int $networkRequests, int $cacheHits, int $bytesDownloaded, int $bytesReused, int $mediaBytesDownloaded): array
    {
        $logicalRequests = max(0, $logicalRequests);
        $networkRequests = max(0, $networkRequests);
        $cacheHits = max(0, $cacheHits);
        $bytesDownloaded = max(0, $bytesDownloaded);
        $bytesReused = max(0, $bytesReused);
        $mediaBytesDownloaded = max(0, $mediaBytesDownloaded);
        // Convert before addition so two maximum counters cannot overflow an integer.
        $totalBytes = (float) $bytesDownloaded + (float) $bytesReused;

        return [
            'logicalRequests' => $logicalRequests, 'networkRequests' => $networkRequests,
            'cacheHits' => $cacheHits, 'bytesDownloaded' => $bytesDownloaded,
            'bytesReused' => $bytesReused, 'mediaBytesDownloaded' => $mediaBytesDownloaded,
            'cacheHitRatio' => $logicalRequests > 0 ? min(1.0, (float) $cacheHits / (float) $logicalRequests) : 0.0,
            'byteReuseRatio' => $totalBytes > 0.0 ? $bytesReused / $totalBytes : 0.0,
            'mediaByteRatio' => $bytesDownloaded > 0 ? min(1.0, (float) $mediaBytesDownloaded / (float) $bytesDownloaded) : 0.0,
        ];
    }

    /** @return array{logicalRequests: int, networkRequests: int, cacheHits: int, bytesDownloaded: int, bytesReused: int, mediaBytesDownloaded: int, cacheHitRatio: float, byteReuseRatio: float, mediaByteRatio: float}|null */
    public static function summarizeTransferEfficiencyNative(int $logicalRequests, int $networkRequests, int $cacheHits, int $bytesDownloaded, int $bytesReused, int $mediaBytesDownloaded): ?array
    {
        $bytes = self::rust(pack('q*', $logicalRequests, $networkRequests, $cacheHits, $bytesDownloaded, $bytesReused, $mediaBytesDownloaded), 72);
        if ($bytes === null || strlen($bytes) !== 72) {
            return null;
        }
        /** @var array{logicalRequests: int, networkRequests: int, cacheHits: int, bytesDownloaded: int, bytesReused: int, mediaBytesDownloaded: int, cacheHitRatio: float, byteReuseRatio: float, mediaByteRatio: float} $result */
        $result = unpack('qlogicalRequests/qnetworkRequests/qcacheHits/qbytesDownloaded/qbytesReused/qmediaBytesDownloaded/dcacheHitRatio/dbyteReuseRatio/dmediaByteRatio', $bytes);

        return $result;
    }

    public static function useW47ArticleStatsRustFfi(?bool $enabled): void
    {
        self::$w47RustFfi = $enabled;
    }

    public static function usesW47ArticleStatsRustFfi(): bool
    {
        return self::$w47RustFfi ?? self::W47_ARTICLE_STATS_RUST_FFI_DEFAULT;
    }

    public static function resetW47ArticleStatsRustFfi(): void
    {
        self::$w47RustFfi = null;
        self::$w47Ffi = null;
        self::$w47Out = null;
    }

    /**
     * Article latency, throughput and failure statistics for a batch of segment fetches. Statuses:
     * 0 ok, 1 missing (430), 2 CRC error, 3 timeout, anything else other. Latencies and bytes of
     * ok articles are clamped to [0, 2^40) and their totals to 2^62.
     *
     * Layout: `[0]` count, `[1..5]` ok/missing/CRC/timeout/other, `[6]` ok bytes, `[7]` ok latency
     * sum, `[8]` min, `[9]` max, `[10]` mean, `[11..13]` nearest-rank p50/p95/p99, `[14]` mean
     * consecutive latency delta, `[15]` bytes per second over the window, `[16]` missing ppm,
     * `[17]` failure ppm, `[18]` link utilization ppm and `[19]` longest failure streak. Missing
     * bytes and statuses are 0.
     *
     * @param  array<array-key, int>  $latencyMicros
     * @param  array<array-key, int>  $bytes
     * @param  array<array-key, int>  $statuses
     * @return list<int>
     */
    public static function articleStatsBatch(array $latencyMicros, array $bytes, array $statuses, int $windowMicros, int $linkBytesPerSecond): array
    {
        return (self::usesW47ArticleStatsRustFfi() ? self::articleStatsBatchNative($latencyMicros, $bytes, $statuses, $windowMicros, $linkBytesPerSecond) : null)
            ?? self::articleStatsBatchPhp($latencyMicros, $bytes, $statuses, $windowMicros, $linkBytesPerSecond);
    }

    /**
     * @param  array<array-key, int>  $latencyMicros
     * @param  array<array-key, int>  $bytes
     * @param  array<array-key, int>  $statuses
     * @return list<int>
     */
    public static function articleStatsBatchPhp(array $latencyMicros, array $bytes, array $statuses, int $windowMicros, int $linkBytesPerSecond): array
    {
        $latencyMicros = array_values($latencyMicros);
        $bytes = array_values($bytes);
        $statuses = array_values($statuses);
        $count = \count($latencyMicros);
        $out = array_fill(0, self::W47_ARTICLE_FIELDS, 0);
        $out[0] = $count;
        $ok = [];
        $total = $sum = $jitter = $streak = 0;
        $previous = null;
        for ($i = 0; $i < $count; $i++) {
            $status = $statuses[$i] ?? 0;
            $out[1 + ($status >= 0 && $status <= 3 ? $status : 4)]++;
            if ($status !== 0) {
                $streak++;
                if ($streak > $out[19]) {
                    $out[19] = $streak;
                }

                continue;
            }
            $streak = 0;
            $latency = min(max($latencyMicros[$i], 0), self::W47_VALUE_CAP);
            $total = min($total + min(max($bytes[$i] ?? 0, 0), self::W47_VALUE_CAP), self::W47_TOTAL_CAP);
            $sum = min($sum + $latency, self::W47_TOTAL_CAP);
            if ($previous !== null) {
                $jitter = min($jitter + abs($latency - $previous), self::W47_TOTAL_CAP);
            }
            $previous = $latency;
            $ok[] = $latency;
        }
        $okays = \count($ok);
        $out[6] = $total;
        $out[7] = $sum;
        if ($okays > 0) {
            $out[10] = intdiv($sum, $okays);
            $out[14] = $okays > 1 ? intdiv($jitter, $okays - 1) : 0;
            sort($ok);
            $out[8] = $ok[0];
            $out[9] = $ok[$okays - 1];
            $out[11] = $ok[intdiv(50 * $okays + 99, 100) - 1];
            $out[12] = $ok[intdiv(95 * $okays + 99, 100) - 1];
            $out[13] = $ok[intdiv(99 * $okays + 99, 100) - 1];
        }
        if ($windowMicros > 0) {
            $out[15] = intdiv(min($total, self::W47_THROUGHPUT_BYTES_CAP) * 1_000_000, $windowMicros);
        }
        if ($count > 0) {
            $out[16] = intdiv($out[2] * 1_000_000, $count);
            $out[17] = intdiv(($count - $okays) * 1_000_000, $count);
        }
        if ($linkBytesPerSecond > 0) {
            $out[18] = intdiv(min($out[15], self::W47_UTILIZATION_CAP) * 1_000_000, $linkBytesPerSecond);
        }

        return $out;
    }

    /**
     * Rust statistics, or null when PHP must compute them (unavailable library or rejected input).
     *
     * @param  array<array-key, int>  $latencyMicros
     * @param  array<array-key, int>  $bytes
     * @param  array<array-key, int>  $statuses
     * @return list<int>|null
     */
    public static function articleStatsBatchNative(array $latencyMicros, array $bytes, array $statuses, int $windowMicros, int $linkBytesPerSecond): ?array
    {
        $count = \count($latencyMicros);
        if ($count > self::W47_MAX_ITEMS) {
            return null;
        }
        try {
            self::$w47Ffi ??= RustPipelineLibrary::resolveDomain('additional_w47', self::W47_FFI_CDEF) ?? false;
            if (self::$w47Ffi === false) {
                return null;
            }
            $out = self::$w47Out ??= self::$w47Ffi->new('int64_t['.self::W47_ARTICLE_FIELDS.']');
            $ints = pack('q*', ...array_values($latencyMicros), ...self::w47Fit($bytes, $count), ...self::w47Fit($statuses, $count));
            // @phpstan-ignore method.notFound
            $status = self::$w47Ffi->nntmux_w47_article_metrics($ints, \strlen($ints), $count, $windowMicros, $linkBytesPerSecond, $out, self::W47_ARTICLE_FIELDS);
            if ($status !== 1) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, self::W47_ARTICLE_FIELDS * 8));
        } catch (Throwable) {
            return null;
        }

        return $values === false ? null : array_values($values);
    }

    /**
     * @param  array<array-key, int>  $values
     * @return list<int>
     */
    private static function w47Fit(array $values, int $count): array
    {
        return \count($values) === $count && array_is_list($values) ? $values : array_pad(\array_slice(array_values($values), 0, $count), $count, 0);
    }

    // Rust is the default only while the paired W53.6 benchmark clears 1.5x on all four metrics.
    private const bool W53_RUST_FFI_DEFAULT = true;

    public const string W53_FFI_CDEF = <<<'C'
        int32_t nntmux_w536_transfer_efficiency_batch(const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_count);
        C;

    public const int W53_TRANSFER_HEADER = 12;

    private const int W53_MAX_ITEMS = 1_048_576;

    /**
     * `summarizeTransferEfficiencyPhp()` over counter rows, as integer parts per million.
     *
     * Counters are clamped to [0, 2^40) and totals to 2^62; missing counters are 0. Layout: `[0]`
     * count, `[1..6]` logical/network/cache-hit/downloaded/reused/media totals, `[7]` requests saved
     * (logical minus network, never negative), `[8]` mean cache-hit ppm, `[9]` mean byte-reuse ppm,
     * `[10]` flagged rows, `[11]` FNV-1a digest; then per row `cache | reuse << 20 | media << 40 |
     * flags << 60`, where flag bit 0 is logical requests without network requests, bit 1 more hits
     * than logical requests and bit 2 more media bytes than downloaded bytes.
     *
     * @param  array<array-key, int>  $logicalRequests
     * @param  array<array-key, int>  $networkRequests
     * @param  array<array-key, int>  $cacheHits
     * @param  array<array-key, int>  $bytesDownloaded
     * @param  array<array-key, int>  $bytesReused
     * @param  array<array-key, int>  $mediaBytesDownloaded
     * @return list<int>
     */
    public static function transferEfficiencyBatch(array $logicalRequests, array $networkRequests, array $cacheHits, array $bytesDownloaded, array $bytesReused, array $mediaBytesDownloaded): array
    {
        return (self::usesW53RustFfi() ? self::transferEfficiencyBatchNative($logicalRequests, $networkRequests, $cacheHits, $bytesDownloaded, $bytesReused, $mediaBytesDownloaded) : null)
            ?? self::transferEfficiencyBatchPhp($logicalRequests, $networkRequests, $cacheHits, $bytesDownloaded, $bytesReused, $mediaBytesDownloaded);
    }

    /**
     * @param  array<array-key, int>  $logicalRequests
     * @param  array<array-key, int>  $networkRequests
     * @param  array<array-key, int>  $cacheHits
     * @param  array<array-key, int>  $bytesDownloaded
     * @param  array<array-key, int>  $bytesReused
     * @param  array<array-key, int>  $mediaBytesDownloaded
     * @return list<int>
     */
    public static function transferEfficiencyBatchPhp(array $logicalRequests, array $networkRequests, array $cacheHits, array $bytesDownloaded, array $bytesReused, array $mediaBytesDownloaded): array
    {
        $logicalRequests = array_values($logicalRequests);
        $networkRequests = array_values($networkRequests);
        $cacheHits = array_values($cacheHits);
        $bytesDownloaded = array_values($bytesDownloaded);
        $bytesReused = array_values($bytesReused);
        $mediaBytesDownloaded = array_values($mediaBytesDownloaded);
        $count = \count($logicalRequests);
        $cap = self::W47_VALUE_CAP;
        $totalCap = self::W47_TOTAL_CAP;
        $out = array_fill(0, self::W53_TRANSFER_HEADER, 0);
        $out[0] = $count;
        $cacheSum = $reuseSum = 0;
        $digest = 2_166_136_261;
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $logical = min(max($logicalRequests[$i], 0), $cap);
            $network = min(max($networkRequests[$i] ?? 0, 0), $cap);
            $hits = min(max($cacheHits[$i] ?? 0, 0), $cap);
            $downloaded = min(max($bytesDownloaded[$i] ?? 0, 0), $cap);
            $reused = min(max($bytesReused[$i] ?? 0, 0), $cap);
            $media = min(max($mediaBytesDownloaded[$i] ?? 0, 0), $cap);
            $out[1] = min($out[1] + $logical, $totalCap);
            $out[2] = min($out[2] + $network, $totalCap);
            $out[3] = min($out[3] + $hits, $totalCap);
            $out[4] = min($out[4] + $downloaded, $totalCap);
            $out[5] = min($out[5] + $reused, $totalCap);
            $out[6] = min($out[6] + $media, $totalCap);
            $out[7] = min($out[7] + max($logical - $network, 0), $totalCap);
            $cache = $logical > 0 ? min(intdiv($hits * 1_000_000, $logical), 1_000_000) : 0;
            $total = $downloaded + $reused;
            $reuse = $total > 0 ? intdiv($reused * 1_000_000, $total) : 0;
            $share = $downloaded > 0 ? min(intdiv($media * 1_000_000, $downloaded), 1_000_000) : 0;
            $flags = ($logical > 0 && $network === 0 ? 1 : 0) | ($hits > $logical ? 2 : 0) | ($media > $downloaded ? 4 : 0);
            $cacheSum += $cache;
            $reuseSum += $reuse;
            if ($flags !== 0) {
                $out[10]++;
            }
            $row = $cache | $reuse << 20 | $share << 40 | $flags << 60;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        if ($count > 0) {
            $out[8] = intdiv($cacheSum, $count);
            $out[9] = intdiv($reuseSum, $count);
        }
        $out[11] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust summaries, or null when PHP must compute them (unavailable library or rejected input).
     *
     * @param  array<array-key, int>  $logicalRequests
     * @param  array<array-key, int>  $networkRequests
     * @param  array<array-key, int>  $cacheHits
     * @param  array<array-key, int>  $bytesDownloaded
     * @param  array<array-key, int>  $bytesReused
     * @param  array<array-key, int>  $mediaBytesDownloaded
     * @return list<int>|null
     */
    public static function transferEfficiencyBatchNative(array $logicalRequests, array $networkRequests, array $cacheHits, array $bytesDownloaded, array $bytesReused, array $mediaBytesDownloaded): ?array
    {
        $count = \count($logicalRequests);
        if ($count > self::W53_MAX_ITEMS) {
            return null;
        }
        $ints = pack(
            'q*',
            ...self::w47Fit($logicalRequests, $count), ...self::w47Fit($networkRequests, $count), ...self::w47Fit($cacheHits, $count),
            ...self::w47Fit($bytesDownloaded, $count), ...self::w47Fit($bytesReused, $count), ...self::w47Fit($mediaBytesDownloaded, $count),
        );
        $fields = self::W53_TRANSFER_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w53Call($fields, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w536_transfer_efficiency_batch($ints, \strlen($ints), $count, $out, $fields));
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
}
