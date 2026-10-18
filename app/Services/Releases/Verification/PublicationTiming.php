<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

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
            self::$sampleCounter = (self::$sampleCounter + 1) % $every;

            return self::$sampleCounter === 0 ? new self(hrtime(true)) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Close the span since the previous mark as a controller-side phase (before publish()). */
    public function requestPhase(string $name): void
    {
        $this->requestPhases[$name] = ($this->requestPhases[$name] ?? 0) + $this->lapNs();
    }

    /** Close the span since the previous mark as a pre-lock phase (before the first attempt). */
    public function preLockPhase(string $name): void
    {
        $this->preLockPhases[$name] = ($this->preLockPhases[$name] ?? 0) + $this->lapNs();
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
    public function reportRoundTrip(string $status, ?int $httpStatus): void
    {
        try {
            Log::info('Remote publication round trip timing.', ['status' => $status,
                'http_status' => $httpStatus, 'round_trip_ns' => hrtime(true) - $this->startedAt]);
        } catch (Throwable) {
            // Diagnostics must preserve the publication result.
        }
    }

    /** Nanoseconds since the previous mark, which this call becomes. */
    private function lapNs(): int
    {
        $now = hrtime(true);
        $elapsed = $now - $this->boundary;
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
        $this->phase = $phase;
    }

    public function endAttempt(bool $returned): void
    {
        $now = hrtime(true);
        $this->recordPhase($now);
        $index = array_key_last($this->attempts);
        $this->attempts[$index]['callback_status'] = $returned ? 'returned' : 'threw';
        $phases = $this->attempts[$index]['phases_ns'];
        $this->attempts[$index]['callback_after_state_lock_ns'] = array_sum($phases) - $phases['state_lock_acquisition'];
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
            $report = ['status' => $status, 'total_ns' => $now - $this->startedAt] + $this->facts;
            if ($this->requestPhases !== []) {
                $report['request_ns'] = array_sum($this->requestPhases);
                $report['request_phases_ns'] = $this->requestPhases;
            }
            $report['pre_lock_ns'] = array_sum($this->preLockPhases);
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
        $this->attempts[$index]['phases_ns'][$this->phase] = ($this->attempts[$index]['phases_ns'][$this->phase] ?? 0) + $now - $this->boundary;
        $this->boundary = $now;
    }

    private function finalizeAttempt(int $now): void
    {
        if ($this->callbackEndedAt !== null) {
            // Includes commit/afterCommit or rollback/retry setup; it is not lock hold time.
            $this->attempts[array_key_last($this->attempts)]['transaction_finalization_ns'] = $now - $this->callbackEndedAt;
            $this->callbackEndedAt = null;
        }
    }
}
