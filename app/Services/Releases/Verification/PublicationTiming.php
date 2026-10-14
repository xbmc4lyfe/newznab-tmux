<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;
use Throwable;

/** Monotonic, aggregate diagnostics for one outermost publication transaction. */
final class PublicationTiming
{
    private static int $sampleCounter = 0;

    /** @var list<array{phases_ns: array<string, int>, callback_status: string, callback_after_state_lock_ns: int, transaction_finalization_ns: int}> */
    private array $attempts = [];

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
            Log::info('Verified release publication timing.', ['status' => $status,
                'total_ns' => $now - $this->startedAt, 'attempts' => $this->attempts]);
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
