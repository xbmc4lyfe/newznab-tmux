<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Releases\Verification\VerificationWorkerLocks;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Throwable;

/**
 * The deployment's cross-host writer barrier: it stops verification workers on every host that
 * shares the database from claiming, waits for them to finish, and keeps them out until it is
 * stopped (SIGTERM or SIGINT). The deployment runs it as a one-off container and stops it only
 * after a healthy rollout, so a failed migration keeps remote workers out until it is repaired.
 */
class ReleaseVerificationBarrier extends Command
{
    protected $signature = 'releases:verification-barrier {--timeout=900 : Seconds to wait for every host\'s workers to finish} {--release : Only clear deployment pauses left by a barrier that did not release} {--all : With --release, also clear pauses left by an interrupted reset}';

    protected $description = 'Hold verification workers on every host out until this command is stopped';

    // Keep PHP as the production default: the JSON FFI round trip is slower than these helpers (< 1.5x).
    private static ?bool $useRustFfi = null;

    /**
     * Wave 39.3 production default stays false: the paired benchmark in
     * tests/Unit/RustVerificationBarrierForwardPubControllersW39ParityTest.php is below 1.5x.
     * Named W393 so it stays distinct from this class's existing Rust FFI toggle.
     */
    public const bool W393_RUST_FFI_DEFAULT = false;

    public const string W393_FFI_CDEF = <<<'C'
        ssize_t nntmux_w393_barrier_release_quorum(
            int64_t total,
            int64_t ready,
            int64_t failed,
            int64_t elapsed_ms,
            int64_t timeout_ms,
            char *out_buf,
            size_t out_cap
        );
        C;

    /** Milliseconds between the held barrier's polls while waiting for its workers. */
    public const int BARRIER_POLL_MS = 200;

    public const array BARRIER_QUORUM_STATES = ['quorum', 'failed', 'timed_out', 'waiting'];

    private static ?bool $w393RustFfiOverride = null;

    private static FFI|false|null $w393RustFfiHandle = null;

    private bool $stopping = false;

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::useRustFfi();
    }

    /** `--release --all` clears every pause; plain `--release` only deployment pauses. */
    public static function resolveReleaseScopeReason(bool $all): ?string
    {
        return self::useRustFfi() ? self::resolveReleaseScopeReasonNative($all) : self::resolveReleaseScopeReasonPhp($all);
    }

    public static function resolveReleaseScopeReasonPhp(bool $all): ?string
    {
        return $all ? null : 'deployment';
    }

    public static function resolveReleaseScopeReasonNative(bool $all): ?string
    {
        return GetArticleRange::transformNative(6, [$all], $result) && ($result === null || \is_string($result))
            ? $result
            : self::resolveReleaseScopeReasonPhp($all);
    }

    public static function clampTimeoutSeconds(mixed $timeout): int
    {
        return self::useRustFfi() ? self::clampTimeoutSecondsNative($timeout) : self::clampTimeoutSecondsPhp($timeout);
    }

    public static function clampTimeoutSecondsPhp(mixed $timeout): int
    {
        return max(1, (int) $timeout);
    }

    public static function clampTimeoutSecondsNative(mixed $timeout): int
    {
        return GetArticleRange::transformNative(7, [$timeout], $result) && \is_int($result) ? $result : self::clampTimeoutSecondsPhp($timeout);
    }

    /** The held barrier re-verifies its database session at most every five seconds. */
    public static function shouldCheckLiveness(int $now, int $lastChecked): bool
    {
        return self::useRustFfi() ? self::shouldCheckLivenessNative($now, $lastChecked) : self::shouldCheckLivenessPhp($now, $lastChecked);
    }

    public static function shouldCheckLivenessPhp(int $now, int $lastChecked): bool
    {
        return $now - $lastChecked >= 5;
    }

    public static function shouldCheckLivenessNative(int $now, int $lastChecked): bool
    {
        return GetArticleRange::transformNative(8, [$now, $lastChecked], $result) && \is_bool($result)
            ? $result
            : self::shouldCheckLivenessPhp($now, $lastChecked);
    }

    public function handle(): int
    {
        if ($this->option('release')) {
            $reason = self::resolveReleaseScopeReason((bool) $this->option('all'));
            VerificationWorkerLocks::unpause(null, $reason);
            $this->line('barrier released');

            return VerificationWorkerLocks::paused(null, $reason) ? self::FAILURE : self::SUCCESS;
        }
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            foreach ([SIGTERM, SIGINT] as $signal) {
                pcntl_signal($signal, function (): void {
                    $this->stopping = true;
                });
            }
        }
        // The durable pause first: workers on every host stop claiming, and it outlives this
        // session, so losing the gate later cannot readmit them.
        $token = bin2hex(random_bytes(16));
        VerificationWorkerLocks::pause($token, 'deployment');
        $locks = new VerificationWorkerLocks;
        if (! $locks->drain(self::clampTimeoutSeconds($this->option('timeout')))) {
            VerificationWorkerLocks::unpause($token);
            $this->error('Verification workers are still running on a host; the barrier was not taken.');

            return self::FAILURE;
        }
        try {
            // Another barrier's release clears every deployment pause; reassert this one's before
            // reporting readiness and on every check, so it always backs this barrier's gate.
            VerificationWorkerLocks::pause($token, 'deployment');
            $this->line('barrier held');
            $checked = 0;
            while (! $this->stopping) {
                if (self::shouldCheckLiveness(time(), $checked)) {
                    try {
                        $held = $locks->protects(0);
                        if ($held) {
                            VerificationWorkerLocks::pause($token, 'deployment');
                        }
                    } catch (Throwable) {
                        $held = false;
                    }
                    if (! $held) {
                        $this->line('barrier lost');
                        $this->error('The verification barrier lost its database session; the durable pause remains until released.');

                        return self::FAILURE;
                    }
                    $checked = time();
                }
                usleep(200000);
            }
            // A healthy rollout also clears pauses left by earlier failed deployment attempts.
            VerificationWorkerLocks::unpause(null, 'deployment');
            $this->line('barrier released');

            return self::SUCCESS;
        } finally {
            $locks->close();
        }
    }

    private static function useRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }

    public static function useW393RustFfi(?bool $enabled): void
    {
        self::$w393RustFfiOverride = $enabled;
    }

    public static function usesW393RustFfi(): bool
    {
        return self::$w393RustFfiOverride ?? self::W393_RUST_FFI_DEFAULT;
    }

    public static function resetW393RustFfi(): void
    {
        self::$w393RustFfiOverride = null;
        self::$w393RustFfiHandle = null;
    }

    /**
     * Evaluates the barrier's worker quorum. Counts are clamped (ready to the total, failed to the
     * rest, times to zero or more). Any failed worker fails the barrier; otherwise every worker ready
     * meets the quorum (an empty set trivially), and a quorum still pending once $elapsedMs reaches
     * $timeoutMs times out. A waiting barrier polls again after `wait_ms` (at most BARRIER_POLL_MS).
     *
     * @return array{total: int, ready: int, failed_workers: int, pending: int, quorum_met: bool, timed_out: bool, failed: bool, wait_ms: int, state: string}
     */
    public static function evaluateBarrierReleaseQuorum(int $totalWorkers, int $readyWorkers, int $failedWorkers, int $elapsedMs, int $timeoutMs): array
    {
        return (self::usesW393RustFfi() ? self::evaluateBarrierReleaseQuorumNative($totalWorkers, $readyWorkers, $failedWorkers, $elapsedMs, $timeoutMs) : null)
            ?? self::evaluateBarrierReleaseQuorumPhp($totalWorkers, $readyWorkers, $failedWorkers, $elapsedMs, $timeoutMs);
    }

    /**
     * @return array{total: int, ready: int, failed_workers: int, pending: int, quorum_met: bool, timed_out: bool, failed: bool, wait_ms: int, state: string}
     */
    public static function evaluateBarrierReleaseQuorumPhp(int $totalWorkers, int $readyWorkers, int $failedWorkers, int $elapsedMs, int $timeoutMs): array
    {
        $total = max(0, $totalWorkers);
        $ready = max(0, min($readyWorkers, $total));
        $failed = max(0, min($failedWorkers, $total - $ready));
        $elapsed = max(0, $elapsedMs);
        $timeout = max(0, $timeoutMs);
        $pending = $total - $ready - $failed;
        $hasFailed = $failed > 0;
        $quorum = ! $hasFailed && $pending === 0;
        $timedOut = ! $quorum && $elapsed >= $timeout;
        $state = match (true) {
            $hasFailed => 'failed',
            $quorum => 'quorum',
            $timedOut => 'timed_out',
            default => 'waiting',
        };

        return [
            'total' => $total,
            'ready' => $ready,
            'failed_workers' => $failed,
            'pending' => $pending,
            'quorum_met' => $quorum,
            'timed_out' => $timedOut,
            'failed' => $hasFailed,
            'wait_ms' => $state === 'waiting' ? min(self::BARRIER_POLL_MS, $timeout - $elapsed) : 0,
            'state' => $state,
        ];
    }

    /**
     * @return array{total: int, ready: int, failed_workers: int, pending: int, quorum_met: bool, timed_out: bool, failed: bool, wait_ms: int, state: string}|null
     */
    public static function evaluateBarrierReleaseQuorumNative(int $totalWorkers, int $readyWorkers, int $failedWorkers, int $elapsedMs, int $timeoutMs): ?array
    {
        $fields = self::callW393Native('nntmux_w393_barrier_release_quorum', [$totalWorkers, $readyWorkers, $failedWorkers, $elapsedMs, $timeoutMs], 128, 9, 8);
        if ($fields === null || ! in_array($fields[8], self::BARRIER_QUORUM_STATES, true)) {
            return null;
        }

        return [
            'total' => (int) $fields[0],
            'ready' => (int) $fields[1],
            'failed_workers' => (int) $fields[2],
            'pending' => (int) $fields[3],
            'quorum_met' => $fields[4] === '1',
            'timed_out' => $fields[5] === '1',
            'failed' => $fields[6] === '1',
            'wait_ms' => (int) $fields[7],
            'state' => $fields[8],
        ];
    }

    /**
     * Calls a Wave 39.3 export with the shared scratch buffer, retrying once at the size it asks for.
     * Returns its fields only when there are exactly $fieldCount and the first $digitFields are decimal.
     *
     * @param  list<int|string>  $args  arguments preceding the caller-owned output buffer
     * @return list<string>|null
     */
    private static function callW393Native(string $symbol, array $args, int $minBytes, int $fieldCount, int $digitFields): ?array
    {
        $ffi = self::w393RustFfi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $minBytes, $capacity);
            if ($buffer === null) {
                return null;
            }
            $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }
            $fields = explode("\x1F", FFI::string($buffer, $written));
            if (count($fields) !== $fieldCount) {
                return null;
            }
            for ($i = 0; $i < $digitFields; $i++) {
                if (strlen($fields[$i]) > 19 || ! ctype_digit($fields[$i])) {
                    return null;
                }
            }

            return $fields;
        } catch (Throwable) {
            return null;
        }
    }

    private static function w393RustFfi(): ?FFI
    {
        if (self::$w393RustFfiHandle === null) {
            self::$w393RustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w393', self::W393_FFI_CDEF) ?? false;
        }

        return self::$w393RustFfiHandle === false ? null : self::$w393RustFfiHandle;
    }
}
