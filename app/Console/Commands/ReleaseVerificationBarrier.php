<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Releases\Verification\VerificationWorkerLocks;
use Illuminate\Console\Command;

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
                    } catch (\Throwable) {
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
}
