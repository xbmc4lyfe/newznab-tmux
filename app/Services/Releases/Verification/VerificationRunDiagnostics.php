<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

/**
 * Fixed-size facts about one natural command invocation, without candidate metadata.
 * Returned calls/results can overlap; a returned finish does not prove its CAS matched.
 */
final class VerificationRunDiagnostics
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_verification_diagnostics_stop(const char *reason, size_t reason_len, int64_t stopped, int64_t claims_admitted, int64_t limit, uint8_t has_loop_started, int64_t loop_started, int64_t max_seconds);
        ssize_t nntmux_verification_diagnostics_round_metrics(const char *input, size_t input_len, int64_t started, int64_t finished, uint8_t has_loop_started, int64_t loop_started, uint8_t has_stopped, int64_t stopped, char *out, size_t out_cap);
        C;

    private const array STOP_REASONS = [
        'limit',
        'deadline',
        'no_claim',
        'draining',
        'worker_unavailable',
        'initialization_unavailable',
        'initialization_error',
        'unsafe_scratch_root',
        'error',
    ];

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private static ?FFI\CData $scratch = null;

    private static int $scratchCap = 0;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi && self::ffi() !== null;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    private static function scratch(FFI $ffi, int $minBytes): ?FFI\CData
    {
        if (self::$scratch === null || self::$scratchCap < $minBytes) {
            self::$scratch = RustPipelineLibrary::obtainScratch($ffi, $minBytes, self::$scratchCap);
        }

        return self::$scratch;
    }

    private bool $workerAcquired = false;

    private ?int $loopStarted = null;

    private ?int $stopped = null;

    private ?string $stopReason = null;

    private bool $limitReached = false;

    private bool $deadlineReached = false;

    private int $claimCalls = 0;

    private int $claimsAdmitted = 0;

    private int $queuedReturned = 0;

    private int $queuedLost = 0;

    private int $queuedReturnFailed = 0;

    /** @var array{complete: int, repaired: int, sampled: int, unknown: int, unrepairable: int, other: int} */
    private array $verdicts = ['complete' => 0, 'repaired' => 0, 'sampled' => 0, 'unknown' => 0, 'unrepairable' => 0, 'other' => 0];

    private int $publishReturned = 0;

    private int $handedOff = 0;

    private int $outboxFallbacks = 0;

    private float $outboxWaitMilliseconds = 0.0;

    private int $finishReturned = 0;

    private int $filteredRecoveries = 0;

    private int $candidateErrors = 0;

    private bool $fatalError = false;

    private string $commandResult = 'exception';

    private ?int $exitCode = null;

    private string $maintenanceState = 'not_reached';

    private ?int $maintenanceStarted = null;

    private float $maintenanceMilliseconds = 0.0;

    /**
     * Fixed phases of the claim loop. Prefetch includes batch manifest reads and network; verification includes remaining fetch and decode; an
     * operation that throws is charged to its own phase.
     */
    private const array PHASES = ['claim', 'prefetch', 'bookkeeping', 'manifest_read', 'verify_passed', 'verify_other', 'publish', 'finish', 'status_write'];

    /** @var array<string, array{ms: float, count: int}> */
    private array $phases = [];

    public function __construct(private readonly int $started, private readonly string $startedAt,
        private readonly int $limit, private readonly int $maxSeconds, private readonly int $requestedSlot) {}

    /** Add the time since $started to a phase and return the current time for the next one. */
    public function phase(string $name, ?int $started): int
    {
        $now = hrtime(true);
        if ($started !== null && in_array($name, self::PHASES, true)) {
            $this->phases[$name] ??= ['ms' => 0.0, 'count' => 0];
            $this->phases[$name]['ms'] += ($now - $started) / 1_000_000.0;
            $this->phases[$name]['count']++;
        }

        return $now;
    }

    public function workerAcquired(): void
    {
        $this->workerAcquired = true;
    }

    public function maintenanceSkipped(): void
    {
        $this->maintenanceState = 'not_acquired';
    }

    public function maintenanceStarted(int $started): void
    {
        $this->maintenanceStarted = $started;
        $this->maintenanceState = 'ran';
    }

    public function maintenanceFinished(int $finished, bool $successful): void
    {
        if ($this->maintenanceStarted !== null) {
            $this->maintenanceMilliseconds = ($finished - $this->maintenanceStarted) / 1_000_000.0;
            $this->maintenanceStarted = null;
        }
        $this->maintenanceState = $successful ? 'ran' : 'error';
    }

    public function loopStarted(int $started): void
    {
        $this->loopStarted = $started;
    }

    public function claimCalled(): void
    {
        $this->claimCalls++;
    }

    public function claimed(): void
    {
        $this->claimsAdmitted++;
    }

    public function queuedReturned(): void
    {
        $this->queuedReturned++;
    }

    public function queuedLost(): void
    {
        $this->queuedLost++;
    }

    public function queuedReturnFailed(): void
    {
        $this->queuedReturnFailed++;
    }

    public function verified(string $verdict): void
    {
        $this->verdicts[array_key_exists($verdict, $this->verdicts) ? $verdict : 'other']++;
    }

    public function published(): void
    {
        $this->publishReturned++;
    }

    /** A verified result went to the publication outbox instead of the primary. */
    public function handedOff(): void
    {
        $this->handedOff++;
    }

    /** A result that would have gone to the outbox was published synchronously instead. */
    public function outboxFallback(): void
    {
        $this->outboxFallbacks++;
    }

    public function outboxWaited(int $started, int $finished): void
    {
        $this->outboxWaitMilliseconds += ($finished - $started) / 1_000_000.0;
    }

    public function finished(): void
    {
        $this->finishReturned++;
    }

    public function filtered(): void
    {
        $this->filteredRecoveries++;
    }

    public function candidateFailed(): void
    {
        $this->candidateErrors++;
    }

    /** Capture the admission decision before cleanup or a slow log sink can change threshold evidence. */
    public function stop(string $reason, int $stopped): void
    {
        if (self::$useRustFfi && $this->stopNative($reason, $stopped)) {
            return;
        }
        $this->stopPhp($reason, $stopped);
    }

    public function stopPhp(string $reason, int $stopped): void
    {
        if ($this->stopReason !== null) {
            return;
        }
        $this->stopReason = in_array($reason, self::STOP_REASONS, true) ? $reason : 'error';
        $this->stopped = $stopped;
        $this->limitReached = $this->claimsAdmitted >= $this->limit;
        $this->deadlineReached = $this->loopStarted !== null && $this->maxSeconds > 0
            && ($stopped - $this->loopStarted) / 1e9 >= $this->maxSeconds;
    }

    public function stopNative(string $reason, int $stopped): bool
    {
        if ($this->stopReason !== null) {
            return true;
        }
        $ffi = self::ffi();
        if ($ffi === null) {
            return false;
        }
        try {
            // @phpstan-ignore method.notFound
            $flags = $ffi->nntmux_verification_diagnostics_stop(
                $reason,
                strlen($reason),
                $stopped,
                $this->claimsAdmitted,
                $this->limit,
                $this->loopStarted !== null ? 1 : 0,
                $this->loopStarted ?? 0,
                $this->maxSeconds
            );
            if (! is_int($flags) || $flags < 0) {
                return false;
            }
            $this->stopReason = self::STOP_REASONS[$flags & 0x0F] ?? 'error';
            $this->stopped = $stopped;
            $this->limitReached = ($flags & 0x10) !== 0;
            $this->deadlineReached = ($flags & 0x20) !== 0;

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function returned(int $exitCode): void
    {
        $this->exitCode = $exitCode;
        $this->commandResult = $exitCode === 0 ? 'success' : 'failure';
    }

    public function failed(int $finished): void
    {
        $this->fatalError = true;
        $this->commandResult = 'exception';
        $this->exitCode = null;
        if ($this->maintenanceStarted !== null) {
            $this->maintenanceFinished($finished, false);
        }
        $this->stop('error', $finished);
    }

    /** @return array<string, mixed> */
    public function report(int $finished): array
    {
        return (self::$useRustFfi ? $this->reportNative($finished) : null)
            ?? $this->reportPhp($finished);
    }

    /** @return array<string, mixed> */
    public function reportPhp(int $finished): array
    {
        return [
            'schema_version' => 4,
            'started_at' => $this->startedAt,
            'requested_slot' => $this->requestedSlot,
            'limit' => $this->limit,
            'max_seconds' => $this->maxSeconds,
            'worker_acquired' => $this->workerAcquired,
            'loop_started' => $this->loopStarted !== null,
            'claim_calls' => $this->claimCalls,
            'claims_admitted' => $this->claimsAdmitted,
            'queued_returned' => $this->queuedReturned,
            'queued_lost' => $this->queuedLost,
            'queued_return_failed' => $this->queuedReturnFailed,
            'stop_reason' => $this->stopReason,
            'limit_reached' => $this->limitReached,
            'deadline_reached_at_stop' => $this->deadlineReached,
            'verifier_verdicts' => $this->verdicts,
            'publish_returned' => $this->publishReturned,
            'handed_off' => $this->handedOff,
            'outbox_fallbacks' => $this->outboxFallbacks,
            'outbox_wait_ms' => round($this->outboxWaitMilliseconds, 3),
            'finish_returned' => $this->finishReturned,
            'filtered_recoveries' => $this->filteredRecoveries,
            'candidate_errors' => $this->candidateErrors,
            'fatal_error' => $this->fatalError,
            'command_result' => $this->commandResult,
            'exit_code' => $this->exitCode,
            'maintenance_state' => $this->maintenanceState,
            'durations_ms' => [
                'total' => ($finished - $this->started) / 1_000_000.0,
                'maintenance' => $this->maintenanceMilliseconds,
                'claim_loop' => $this->loopStarted === null ? null : (($this->stopped ?? $finished) - $this->loopStarted) / 1_000_000.0,
            ],
            'phases' => array_combine(self::PHASES, array_map(fn (string $phase): array => [
                'ms' => round($this->phases[$phase]['ms'] ?? 0.0, 3), 'count' => $this->phases[$phase]['count'] ?? 0,
            ], self::PHASES)),
        ];
    }

    /** @return array<string, mixed>|null */
    public function reportNative(int $finished): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $input = pack(
            'd10',
            $this->outboxWaitMilliseconds,
            $this->phases['claim']['ms'] ?? 0.0,
            $this->phases['prefetch']['ms'] ?? 0.0,
            $this->phases['bookkeeping']['ms'] ?? 0.0,
            $this->phases['manifest_read']['ms'] ?? 0.0,
            $this->phases['verify_passed']['ms'] ?? 0.0,
            $this->phases['verify_other']['ms'] ?? 0.0,
            $this->phases['publish']['ms'] ?? 0.0,
            $this->phases['finish']['ms'] ?? 0.0,
            $this->phases['status_write']['ms'] ?? 0.0
        );
        try {
            $scratch = self::scratch($ffi, 128);
            if ($scratch === null) {
                return null;
            }
            $cap = self::$scratchCap;
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_verification_diagnostics_round_metrics(
                $input,
                80,
                $this->started,
                $finished,
                $this->loopStarted !== null ? 1 : 0,
                $this->loopStarted ?? 0,
                $this->stopped !== null ? 1 : 0,
                $this->stopped ?? 0,
                $scratch,
                $cap
            );
            if ($written !== 96) {
                return null;
            }
            /** @var array<int, float>|false $vals */
            $vals = unpack('d12', FFI::string($scratch, 96));
            if ($vals === false) {
                return null;
            }
            $phases = [];
            foreach (self::PHASES as $idx => $phase) {
                $phases[$phase] = [
                    'ms' => $vals[4 + $idx],
                    'count' => $this->phases[$phase]['count'] ?? 0,
                ];
            }

            return [
                'schema_version' => 4,
                'started_at' => $this->startedAt,
                'requested_slot' => $this->requestedSlot,
                'limit' => $this->limit,
                'max_seconds' => $this->maxSeconds,
                'worker_acquired' => $this->workerAcquired,
                'loop_started' => $this->loopStarted !== null,
                'claim_calls' => $this->claimCalls,
                'claims_admitted' => $this->claimsAdmitted,
                'queued_returned' => $this->queuedReturned,
                'queued_lost' => $this->queuedLost,
                'queued_return_failed' => $this->queuedReturnFailed,
                'stop_reason' => $this->stopReason,
                'limit_reached' => $this->limitReached,
                'deadline_reached_at_stop' => $this->deadlineReached,
                'verifier_verdicts' => $this->verdicts,
                'publish_returned' => $this->publishReturned,
                'handed_off' => $this->handedOff,
                'outbox_fallbacks' => $this->outboxFallbacks,
                'outbox_wait_ms' => $vals[1],
                'finish_returned' => $this->finishReturned,
                'filtered_recoveries' => $this->filteredRecoveries,
                'candidate_errors' => $this->candidateErrors,
                'fatal_error' => $this->fatalError,
                'command_result' => $this->commandResult,
                'exit_code' => $this->exitCode,
                'maintenance_state' => $this->maintenanceState,
                'durations_ms' => [
                    'total' => $vals[2],
                    'maintenance' => $this->maintenanceMilliseconds,
                    'claim_loop' => $this->loopStarted === null ? null : $vals[3],
                ],
                'phases' => $phases,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /** @param callable(array<string, mixed>): void $observer */
    public function observe(callable $observer): void
    {
        try {
            $observer($this->report(hrtime(true)));
        } catch (Throwable) {
            // Diagnostics cannot change processing outcomes or replace an original exception.
        }
    }
}
