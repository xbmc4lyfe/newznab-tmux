<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
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

    private static ?CData $scratch = null;

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

    private static function scratch(FFI $ffi, int $minBytes): ?CData
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

    // Rust is the default only while the paired W58.6 benchmark clears 1.5x on all four metrics.
    private const bool W58_RUST_FFI_DEFAULT = true;

    public const string W58_FFI_CDEF = <<<'C'
        int32_t nntmux_w586_run_diagnostics_batch(const char *reasons, size_t reasons_len, const char *reason_twin, size_t reason_twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    private const int W58_MAX_ITEMS = 1_048_576;

    public const int W58_RUN_HEADER = 43;

    /** STOP_REASONS value => index. */
    private const array W58_STOP_INDEX = ['limit' => 0, 'deadline' => 1, 'no_claim' => 2, 'draining' => 3, 'worker_unavailable' => 4,
        'initialization_unavailable' => 5, 'initialization_error' => 6, 'unsafe_scratch_root' => 7, 'error' => 8];

    /**
     * Summarize command runs given each stop reason (unrecognized ones count as error, as `stopPhp()`
     * records them), claims admitted, limit, claim calls (counts clamped to 0..2^31-1), claim loop ns
     * (negative when the loop never started; clamped to 2^50-1), max seconds and nine phase ns per run
     * in `PHASES` order (clamped to 0..2^50-1). Limit and deadline follow `stopPhp()`; the bottleneck is
     * the first strictly longest non-zero phase (9 none); idle is loop time not charged to a phase; a run
     * is stalled when it claimed nothing despite calls.
     *
     * Layout: `[0]` count, `[1..9]` per stop reason, `[10]` unrecognized reasons, `[11]` limit and
     * `[12]` deadline reached, `[13]` loops started, `[14]` stalled, `[15..24]` per bottleneck, `[25]`
     * claims, `[26]` calls, `[27]` loop ns, `[28..36]` per phase ns and `[37]` idle ns (totals capped at
     * 2^62), `[38]` slowest loop index (-1 when none) and `[39]` its ns, `[40]` the batch bottleneck (9
     * none), `[41]` mean claim efficiency ppm (each run capped at 1e6), `[42]` FNV-1a digest; then per
     * run `reason | unrecognized << 4 | limit << 5 | deadline << 6 | started << 7 | stalled << 8 |
     * bottleneck << 9 | min(claims, 2^20-1) << 13 | min(loop ms, 2^30-1) << 33`.
     *
     * @param  array<array-key, string>  $stopReasons
     * @param  array<array-key, int>  $claims
     * @param  array<array-key, int>  $limits
     * @param  array<array-key, int>  $claimCalls
     * @param  array<array-key, int>  $loopNs
     * @param  array<array-key, int>  $maxSeconds
     * @param  array<array-key, array<array-key, int>>  $phaseNs
     * @return list<int>
     */
    public static function summarizeRunBatch(array $stopReasons, array $claims, array $limits, array $claimCalls, array $loopNs,
        array $maxSeconds, array $phaseNs): array
    {
        return (self::usesW58RustFfi() ? self::summarizeRunBatchNative($stopReasons, $claims, $limits, $claimCalls, $loopNs, $maxSeconds, $phaseNs) : null)
            ?? self::summarizeRunBatchPhp($stopReasons, $claims, $limits, $claimCalls, $loopNs, $maxSeconds, $phaseNs);
    }

    /**
     * @param  array<array-key, string>  $stopReasons
     * @param  array<array-key, int>  $claims
     * @param  array<array-key, int>  $limits
     * @param  array<array-key, int>  $claimCalls
     * @param  array<array-key, int>  $loopNs
     * @param  array<array-key, int>  $maxSeconds
     * @param  array<array-key, array<array-key, int>>  $phaseNs
     * @return list<int>
     */
    public static function summarizeRunBatchPhp(array $stopReasons, array $claims, array $limits, array $claimCalls, array $loopNs,
        array $maxSeconds, array $phaseNs): array
    {
        $stopReasons = array_values($stopReasons);
        $claims = array_values($claims);
        $limits = array_values($limits);
        $claimCalls = array_values($claimCalls);
        $loopNs = array_values($loopNs);
        $maxSeconds = array_values($maxSeconds);
        $phaseNs = array_values($phaseNs);
        $cap = (1 << 50) - 1;
        $total = 1 << 62;
        $count = \count($stopReasons);
        $out = array_fill(0, self::W58_RUN_HEADER, 0);
        $out[0] = $count;
        $out[38] = -1;
        $efficiency = $measured = 0;
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($stopReasons as $at => $reason) {
            $known = self::W58_STOP_INDEX[(string) $reason] ?? null;
            $case = $known ?? 8;
            $claimed = max(0, min((int) ($claims[$at] ?? 0), 2_147_483_647));
            $limit = max(0, min((int) ($limits[$at] ?? 0), 2_147_483_647));
            $calls = max(0, min((int) ($claimCalls[$at] ?? 0), 2_147_483_647));
            $rawLoop = (int) ($loopNs[$at] ?? 0);
            $seconds = max(0, min((int) ($maxSeconds[$at] ?? 0), 2_147_483_647));
            $started = $rawLoop >= 0;
            $loop = $started ? min($rawLoop, $cap) : 0;
            $limitReached = $claimed >= $limit;
            $deadline = $started && $seconds > 0 && $loop >= $seconds * 1_000_000_000;
            $stalled = $calls > 0 && $claimed === 0;
            $bottleneck = 9;
            $longest = $charged = 0;
            $phases = array_values((array) ($phaseNs[$at] ?? []));
            for ($phase = 0; $phase < 9; $phase++) {
                $ns = max(0, min((int) ($phases[$phase] ?? 0), $cap));
                $charged += $ns;
                if ($ns > $longest) {
                    $longest = $ns;
                    $bottleneck = $phase;
                }
                $out[28 + $phase] = min($out[28 + $phase] + $ns, $total);
            }
            $idle = $started ? max(0, $loop - $charged) : 0;
            if ($calls > 0) {
                $efficiency += min(intdiv($claimed * 1_000_000, $calls), 1_000_000);
                $measured++;
            }
            $out[1 + $case]++;
            $out[10] += (int) ($known === null);
            $out[11] += (int) $limitReached;
            $out[12] += (int) $deadline;
            $out[13] += (int) $started;
            $out[14] += (int) $stalled;
            $out[15 + $bottleneck]++;
            $out[25] += $claimed;
            $out[26] += $calls;
            $out[27] = min($out[27] + $loop, $total);
            $out[37] = min($out[37] + $idle, $total);
            if ($started && ($out[38] < 0 || $loop > $out[39])) {
                $out[38] = $at;
                $out[39] = $loop;
            }
            $row = $case | (int) ($known === null) << 4 | (int) $limitReached << 5 | (int) $deadline << 6 | (int) $started << 7
                | (int) $stalled << 8 | $bottleneck << 9 | min($claimed, (1 << 20) - 1) << 13 | min(intdiv($loop, 1_000_000), (1 << 30) - 1) << 33;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[40] = 9;
        $longest = 0;
        for ($phase = 0; $phase < 9; $phase++) {
            if ($out[28 + $phase] > $longest) {
                $longest = $out[28 + $phase];
                $out[40] = $phase;
            }
        }
        if ($measured > 0) {
            $out[41] = intdiv($efficiency, $measured);
        }
        $out[42] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust summary, or null when PHP must compute it (unavailable library, a phase row without exactly
     * nine values, or rejected input).
     *
     * @param  array<array-key, string>  $stopReasons
     * @param  array<array-key, int>  $claims
     * @param  array<array-key, int>  $limits
     * @param  array<array-key, int>  $claimCalls
     * @param  array<array-key, int>  $loopNs
     * @param  array<array-key, int>  $maxSeconds
     * @param  array<array-key, array<array-key, int>>  $phaseNs
     * @return list<int>|null
     */
    public static function summarizeRunBatchNative(array $stopReasons, array $claims, array $limits, array $claimCalls, array $loopNs,
        array $maxSeconds, array $phaseNs): ?array
    {
        $count = \count($stopReasons);
        if ($count > self::W58_MAX_ITEMS || \count($claims) !== $count || \count($limits) !== $count || \count($claimCalls) !== $count
            || \count($loopNs) !== $count || \count($maxSeconds) !== $count || \count($phaseNs) !== $count) {
            return null;
        }
        $phases = [];
        foreach ($phaseNs as $row) {
            if (! \is_array($row) || \count($row) !== 9) {
                return null;
            }
            $phases[] = array_values($row);
        }
        [$joined, $twin] = self::w58Strings(array_values($stopReasons));
        $ints = pack('q*', ...array_values($claims), ...array_values($limits), ...array_values($claimCalls), ...array_values($loopNs),
            ...array_values($maxSeconds), ...array_merge(...$phases));
        $fields = self::W58_RUN_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w58Call($fields, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w586_run_diagnostics_batch($joined, \strlen($joined), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $fields));
    }

    /**
     * Run `$callback` with the Wave 58.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW58RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w58RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w58RustFfiState('set', $previous);
        }
    }

    public static function usesW58RustFfi(): bool
    {
        $forced = self::w58RustFfiState();

        return \is_bool($forced) ? $forced : self::W58_RUST_FFI_DEFAULT;
    }

    /**
     * Wave 58.6 routing and binding in function statics (no static properties, so readonly classes can share it).
     * No `$op` returns the forced routing (null for the default); `'set'` replaces it with `$val`
     * and returns the previous value; `'binding'` returns the cached `additional_w58` binding and
     * an output buffer of at least `$val` values, or null.
     */
    private static function w58RustFfiState(?string $op = null, mixed $val = null): mixed
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
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w58', self::W58_FFI_CDEF) ?? false;
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
     * The batch's `$fields` output words from one Wave 58.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w58Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w58RustFfiState('binding', $fields);
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
     * Columns NUL-joined one after another and, when a value holds a NUL, the same columns joined
     * with 0x01 so Rust finds the separators where the two differ.
     *
     * @param  list<string>  ...$columns
     * @return array{0: string, 1: string}
     */
    private static function w58Strings(array ...$columns): array
    {
        $joined = '';
        $items = 0;
        foreach ($columns as $column) {
            if ($column !== []) {
                $joined .= ($items > 0 ? "\0" : '').implode("\0", $column);
                $items += \count($column);
            }
        }
        if ($items === 0 || substr_count($joined, "\0") === $items - 1) {
            return [$joined, ''];
        }
        $twin = '';
        $items = 0;
        foreach ($columns as $column) {
            if ($column !== []) {
                $twin .= ($items > 0 ? "\x01" : '').implode("\x01", $column);
                $items += \count($column);
            }
        }

        return [$joined, $twin];
    }
}
