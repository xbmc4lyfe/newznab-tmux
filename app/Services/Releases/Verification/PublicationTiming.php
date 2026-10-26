<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;
use Throwable;

/**
 * Monotonic, aggregate diagnostics for one outermost publication transaction.
 *
 * total_ns spans sampling to the end of the transaction. Work before the first attempt is
 * reported apart from the in-lock attempts: request_phases_ns (controller, remote only) and
 * pre_lock_phases_ns (claim check, metadata, incoming document). callback_after_state_lock_ns
 * keeps its meaning: in-lock phases of one attempt, excluding state_lock_acquisition and the
 * pre-lock work. The per-attempt lock detail is reported beside phases_ns, never inside it.
 */
final class PublicationTiming
{
    public const string FFI_CDEF = <<<'C'
        intptr_t nntmux_w17_nzb_xml_verification_timing(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);
        C;

    // Paired common/uncommon mean and p99 must all pass before adoption.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    /** Reject objects before serialization can invoke user code; bound cycles. */
    private static function nativeValueSupported(mixed $value, int &$remaining, int $depth): bool
    {
        if (--$remaining < 0 || $depth > 24) {
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (! self::nativeValueSupported($item, $remaining, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || is_scalar($value);
    }

    /** @param list<mixed> $arguments */
    private static function computeNative(int $operation, array $arguments): mixed
    {
        if (! self::$useRustFfi) {
            return null;
        }
        try {
            $remaining = 100000;
            if (! self::nativeValueSupported($arguments, $remaining, 0)) {
                return null;
            }
            $ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF);
            if ($ffi === null) {
                return null;
            }
            $input = serialize($arguments);
            $capacity = 0;
            $output = RustPipelineLibrary::obtainScratch($ffi, max(4096, strlen($input) + 512), $capacity);
            if ($output === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w17_nzb_xml_verification_timing($operation, $input, strlen($input), $output, $capacity);
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return unserialize(FFI::string($output, $written), ['allowed_classes' => false]);
        } catch (Throwable) {
            return null;
        }
    }

    public static function accumulateDuration(int $current, int $now, int $boundary): int|float
    {
        return self::accumulateDurationNative($current, $now, $boundary) ?? self::accumulateDurationPhp($current, $now, $boundary);
    }

    public static function accumulateDurationPhp(int $current, int $now, int $boundary): int|float
    {
        // PHP promotes overflowing integer arithmetic to float; retain that
        // behavior when the checked Rust candidate declines the operation.
        if (($now > 0 && $current > PHP_INT_MAX - $now)
            || ($now < 0 && $current < PHP_INT_MIN - $now)) {
            return (float) $current + $now - $boundary;
        }
        $sum = $current + $now;
        if (($boundary < 0 && $sum > PHP_INT_MAX + $boundary)
            || ($boundary > 0 && $sum < PHP_INT_MIN + $boundary)) {
            return (float) $sum - $boundary;
        }

        return $sum - $boundary;
    }

    public static function accumulateDurationNative(int $current, int $now, int $boundary): int|float|null
    {
        $result = self::computeNative(10, [$current, $now, $boundary]);

        return (is_int($result) || is_float($result)) ? $result : null;
    }

    /** @param array<string, int> $phases
     * @return array{total_ns: int|float, callback_after_state_lock_ns: int|float} */
    public static function attemptSummary(array $phases): array
    {
        return self::attemptSummaryNative($phases) ?? self::attemptSummaryPhp($phases);
    }

    /** @param array<string, int> $phases
     * @return array{total_ns: int|float, callback_after_state_lock_ns: int|float} */
    public static function attemptSummaryPhp(array $phases): array
    {
        $total = array_sum($phases);

        return ['total_ns' => $total, 'callback_after_state_lock_ns' => $total - ($phases['state_lock_acquisition'] ?? 0)];
    }

    /** @param array<string, int> $phases
     * @return array{total_ns: int|float, callback_after_state_lock_ns: int|float}|null */
    public static function attemptSummaryNative(array $phases): ?array
    {
        $result = self::computeNative(11, [$phases]);

        return is_array($result) ? $result : null;
    }

    public static function phaseKey(string $phase, ?string $parent = null): string
    {
        return self::phaseKeyNative($phase, $parent) ?? self::phaseKeyPhp($phase, $parent);
    }

    public static function phaseKeyPhp(string $phase, ?string $parent = null): string
    {
        return $parent === null || $parent === '' ? $phase : $parent.'.'.$phase;
    }

    public static function phaseKeyNative(string $phase, ?string $parent = null): ?string
    {
        $result = self::computeNative(12, [$phase, $parent]);

        return is_string($result) ? $result : null;
    }

    private static int $sampleCounter = 0;

    /**
     * `lock` is recorded by VerificationLedger::enterPublication, also when a lock statement throws
     * (`completed` false); a thrown stripe statement reports its elapsed time as stripe_lock_ns.
     *
     * @var list<array{phases_ns: array<string, int>, callback_status: string, callback_after_state_lock_ns: int, transaction_finalization_ns: int, lock?: array{path: string, stripe_count: int, stripe_lock_ns: int, publication_row_lock_ns: int, completed: bool}}>
     */
    private array $attempts = [];

    /** @var array<string, int> */
    private array $requestPhases = [];

    /** @var array<string, int> */
    private array $preLockPhases = [];

    /** @var array{outcome: string, segment_count: ?int, keys_present: ?bool, source: string} */
    private array $facts = ['outcome' => 'threw', 'segment_count' => null, 'keys_present' => null, 'source' => 'local'];

    private int $boundary;

    private ?int $callbackEndedAt = null;

    /** @var array{batch_size: int, batch_index: int}|null */
    private ?array $batch = null;

    private string $phase = 'state_lock_acquisition';

    private function __construct(private readonly int $startedAt)
    {
        $this->boundary = $startedAt;
    }

    public static function sample(): ?self
    {
        try {
            $every = (int) config('release_verification.publication_timing_sample_every', 0);
            if ($every < 1 || DB::transactionLevel() !== 0) {
                return null;
            }
            if ($every > 1 && PHP_SAPI !== 'cli') {
                // Web servers such as classic-mode FrankenPHP reset static state per request, so a
                // counter would never reach $every there; draw per request at the same rate.
                return random_int(1, $every) === 1 ? self::start() : null;
            }
            self::$sampleCounter = (self::$sampleCounter + 1) % $every;

            return self::$sampleCounter === 0 ? self::start() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A sample the caller already decided on, such as one batch endpoint request that sampled once
     * and times each of its items separately.
     */
    public static function start(): self
    {
        return new self(hrtime(true));
    }

    /** Mark this publication as item $index of a batch of $size, which the report then names. */
    public function setBatch(int $size, int $index): void
    {
        $this->batch = ['batch_size' => $size, 'batch_index' => $index];
    }

    /** Close the span since the previous mark as a controller-side phase (before publish()). */
    public function requestPhase(string $name): void
    {
        $this->requestPhases[$name] = self::accumulateDuration($this->requestPhases[$name] ?? 0, $this->lapNs(), 0);
    }

    /** Close the span since the previous mark as a pre-lock phase (before the first attempt). */
    public function preLockPhase(string $name): void
    {
        $this->preLockPhases[$name] = self::accumulateDuration($this->preLockPhases[$name] ?? 0, $this->lapNs(), 0);
    }

    public function setSource(string $source): void
    {
        $this->facts['source'] = $source;
    }

    public function setOutcome(string $outcome): void
    {
        $this->facts['outcome'] = $outcome;
    }

    public function setManifestFacts(?int $segmentCount, ?bool $keysPresent): void
    {
        $this->facts['segment_count'] = $segmentCount;
        $this->facts['keys_present'] = $keysPresent;
    }

    /** @param array{path: string, stripe_count: int, stripe_lock_ns: int, publication_row_lock_ns: int, completed: bool} $lock */
    public function recordLock(array $lock): void
    {
        $this->attempts[array_key_last($this->attempts)]['lock'] = $lock;
    }

    /** Switch to $phase and return the previous one, so a nested step can restore it. */
    public function enterNestedPhase(string $phase): string
    {
        $previous = $this->phase;
        $this->nextPhase($phase);

        return $previous;
    }

    /** One line for the worker side of a remote publication: the HTTP round trip. */
    public function reportRoundTrip(string $status, ?int $httpStatus, ?int $batchSize = null): void
    {
        try {
            Log::info('Remote publication round trip timing.', ['status' => $status,
                'http_status' => $httpStatus, 'round_trip_ns' => hrtime(true) - $this->startedAt]
                + ($batchSize === null ? [] : ['batch_size' => $batchSize]));
        } catch (Throwable) {
            // Diagnostics must preserve the publication result.
        }
    }

    /** Nanoseconds since the previous mark, which this call becomes. */
    private function lapNs(): int
    {
        $now = hrtime(true);
        $elapsed = self::accumulateDuration(0, $now, $this->boundary);
        $this->boundary = $now;

        return $elapsed;
    }

    public function beginAttempt(): void
    {
        $now = hrtime(true);
        $this->finalizeAttempt($now);
        $this->attempts[] = ['phases_ns' => [], 'callback_status' => 'threw',
            'callback_after_state_lock_ns' => 0, 'transaction_finalization_ns' => 0];
        $this->boundary = $now;
        $this->phase = 'state_lock_acquisition';
    }

    public function nextPhase(string $phase): void
    {
        $this->recordPhase(hrtime(true));
        $this->phase = self::phaseKey($phase);
    }

    public function endAttempt(bool $returned): void
    {
        $now = hrtime(true);
        $this->recordPhase($now);
        $index = array_key_last($this->attempts);
        $this->attempts[$index]['callback_status'] = $returned ? 'returned' : 'threw';
        $phases = $this->attempts[$index]['phases_ns'];
        $this->attempts[$index]['callback_after_state_lock_ns'] = self::attemptSummary($phases)['callback_after_state_lock_ns'];
        $this->callbackEndedAt = $now;
    }

    public function report(string $status): void
    {
        try {
            if (DB::transactionLevel() !== 0) {
                return;
            }
            $pdo = DB::connection()->getRawPdo();
            if (! $pdo instanceof PDO || $pdo->inTransaction()) {
                return;
            }
            $now = hrtime(true);
            $this->finalizeAttempt($now);
            $report = ['status' => $status, 'total_ns' => $now - $this->startedAt] + $this->facts + ($this->batch ?? []);
            if ($this->requestPhases !== []) {
                $report['request_ns'] = self::attemptSummary($this->requestPhases)['total_ns'];
                $report['request_phases_ns'] = $this->requestPhases;
            }
            $report['pre_lock_ns'] = self::attemptSummary($this->preLockPhases)['total_ns'];
            $report['pre_lock_phases_ns'] = $this->preLockPhases;
            $report['attempts'] = $this->attempts;
            Log::info('Verified release publication timing.', $report);
        } catch (Throwable) {
            // Diagnostics must preserve the publication result, including after-commit exceptions.
        }
    }

    private function recordPhase(int $now): void
    {
        $index = array_key_last($this->attempts);
        $this->attempts[$index]['phases_ns'][$this->phase] = self::accumulateDuration($this->attempts[$index]['phases_ns'][$this->phase] ?? 0, $now, $this->boundary);
        $this->boundary = $now;
    }

    private function finalizeAttempt(int $now): void
    {
        if ($this->callbackEndedAt !== null) {
            // Includes commit/afterCommit or rollback/retry setup; it is not lock hold time.
            $this->attempts[array_key_last($this->attempts)]['transaction_finalization_ns'] = self::accumulateDuration(0, $now, $this->callbackEndedAt);
            $this->callbackEndedAt = null;
        }
    }

    // Rust is the default only while the paired W57.6 benchmark clears 1.5x on all four metrics.
    private const bool W57_RUST_FFI_DEFAULT = true;

    public const string W57_FFI_CDEF = <<<'C'
        int32_t nntmux_w576_timing_summary_batch(const char *ints, size_t ints_len, size_t count, int64_t sla, int64_t *out, size_t out_cap);
        C;

    private const int W57_MAX_ITEMS = 1_048_576;

    public const int W57_TIMING_HEADER = 21;

    /** Largest phase duration a batch carries (2^50-1 ns, about 13 days). */
    private const int W57_NS_CAP = 1_125_899_906_842_623;

    private const int W57_TOTAL_CAP = 4_611_686_018_427_387_904;

    /**
     * Summarize phase durations in ns (clamped to 0..2^50-1) against an SLA. Buckets: below 1 us, 10 us,
     * 100 us, 1 ms, 10 ms, 100 ms, 1 s, else 7.
     *
     * Layout: `[0]` count, `[1]` over the SLA, `[2]` clamped, `[3]` total (capped at 2^62), `[4]` mean,
     * `[5]` min and `[6]` max (0 when empty), `[7]` p50, `[8]` p90 and `[9]` p99 (nearest rank), `[10]`
     * within-SLA ppm, `[11]` first over-SLA index (-1 when none), `[12..19]` per bucket, `[20]` FNV-1a
     * digest; then per duration `ns | bucket << 51 | over << 54 | clamped << 55`.
     *
     * @param  array<array-key, int>  $durations
     * @return list<int>
     */
    public static function summarizeTimingBatch(array $durations, int $slaNs): array
    {
        return (self::usesW57RustFfi() ? self::summarizeTimingBatchNative($durations, $slaNs) : null) ?? self::summarizeTimingBatchPhp($durations, $slaNs);
    }

    /**
     * @param  array<array-key, int>  $durations
     * @return list<int>
     */
    public static function summarizeTimingBatchPhp(array $durations, int $slaNs): array
    {
        $count = \count($durations);
        $out = array_fill(0, self::W57_TIMING_HEADER, 0);
        $out[0] = $count;
        $out[11] = -1;
        $digest = 2_166_136_261;
        $rows = $sorted = [];
        $at = 0;
        foreach ($durations as $raw) {
            $raw = (int) $raw;
            $ns = $raw < 0 ? 0 : ($raw > self::W57_NS_CAP ? self::W57_NS_CAP : $raw);
            $clamped = $ns !== $raw;
            $over = $ns > $slaNs;
            $bucket = $ns < 1_000 ? 0 : ($ns < 10_000 ? 1 : ($ns < 100_000 ? 2 : ($ns < 1_000_000 ? 3
                : ($ns < 10_000_000 ? 4 : ($ns < 100_000_000 ? 5 : ($ns < 1_000_000_000 ? 6 : 7))))));
            $out[1] += (int) $over;
            $out[2] += (int) $clamped;
            $out[3] = min($out[3] + $ns, self::W57_TOTAL_CAP);
            $out[12 + $bucket]++;
            if ($over && $out[11] < 0) {
                $out[11] = $at;
            }
            $sorted[] = $ns;
            $row = $ns | $bucket << 51 | (int) $over << 54 | (int) $clamped << 55;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
            $at++;
        }
        if ($count > 0) {
            sort($sorted);
            $out[4] = intdiv($out[3], $count);
            $out[5] = $sorted[0];
            $out[6] = $sorted[$count - 1];
            $out[7] = $sorted[intdiv($count * 50 + 99, 100) - 1];
            $out[8] = $sorted[intdiv($count * 90 + 99, 100) - 1];
            $out[9] = $sorted[intdiv($count * 99 + 99, 100) - 1];
            $out[10] = intdiv(($count - $out[1]) * 1_000_000, $count);
        }
        $out[20] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust summary, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, int>  $durations
     * @return list<int>|null
     */
    public static function summarizeTimingBatchNative(array $durations, int $slaNs): ?array
    {
        $count = \count($durations);
        if ($count > self::W57_MAX_ITEMS) {
            return null;
        }
        $ints = pack('q*', ...array_values($durations));
        $fields = self::W57_TIMING_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w57Call($fields, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w576_timing_summary_batch($ints, \strlen($ints), $count, $slaNs, $out, $fields));
    }

    /**
     * Run `$callback` with the Wave 57.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW57RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w57RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w57RustFfiState('set', $previous);
        }
    }

    public static function usesW57RustFfi(): bool
    {
        $forced = self::w57RustFfiState();

        return \is_bool($forced) ? $forced : self::W57_RUST_FFI_DEFAULT;
    }

    /**
     * Wave 57.6 routing and binding in function statics (shared by every Wave 57.6 target, including readonly classes and enums).
     * No `$op` returns the forced routing (null for the default); `'set'` replaces it with `$val`
     * and returns the previous value; `'binding'` returns the cached `additional_w57` binding and
     * an output buffer of at least `$val` values, or null.
     */
    private static function w57RustFfiState(?string $op = null, mixed $val = null): mixed
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
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w57', self::W57_FFI_CDEF) ?? false;
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
     * The batch's `$fields` output words from one Wave 57.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w57Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w57RustFfiState('binding', $fields);
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
