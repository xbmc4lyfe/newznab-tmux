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
    protected $signature = 'releases:verification-barrier {--timeout=900 : Seconds to wait for every host\'s workers to finish}';

    protected $description = 'Hold verification workers on every host out until this command is stopped';

    private bool $stopping = false;

    public function handle(): int
    {
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
        if (! VerificationWorkerLocks::pause($token, 'deployment')) {
            $this->warn('The verification pause table is not migrated yet; holding only the session gate.');
        }
        $locks = new VerificationWorkerLocks;
        if (! $locks->drain(max(1, (int) $this->option('timeout')))) {
            VerificationWorkerLocks::unpause($token);
            $this->error('Verification workers are still running on a host; the barrier was not taken.');

            return self::FAILURE;
        }
        try {
            $this->line('barrier held');
            $checked = 0;
            while (! $this->stopping) {
                if (time() - $checked >= 5) {
                    if (! $locks->protects(0)) {
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
}
