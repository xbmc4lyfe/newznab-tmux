<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;
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
}
