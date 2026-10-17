<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Releases\Verification\VerificationWorkerLocks;
use Illuminate\Console\Command;

/**
 * The deployment's cross-host writer barrier: it stops verification workers on every host that
 * shares the database from claiming, waits for them to finish, and keeps them out until its
 * standard input closes. The deployment holds it across migration and the health wait.
 */
class ReleaseVerificationBarrier extends Command
{
    protected $signature = 'releases:verification-barrier {--timeout=900 : Seconds to wait for every host\'s workers to finish} {--max-hold=3600 : Release after this many seconds even while standard input stays open}';

    protected $description = 'Hold verification workers on every host out until standard input closes';

    public function handle(): int
    {
        $locks = new VerificationWorkerLocks;
        if (! $locks->drain(max(1, (int) $this->option('timeout')))) {
            $this->error('Verification workers are still running on a host; the barrier was not taken.');

            return self::FAILURE;
        }
        try {
            $this->line('barrier held');
            $deadline = time() + max(1, (int) $this->option('max-hold'));
            while (time() < $deadline) {
                if (! $locks->protects(0)) {
                    $this->error('The verification barrier was lost with its database session.');

                    return self::FAILURE;
                }
                $read = [STDIN];
                $write = $except = null;
                if (@stream_select($read, $write, $except, 5) === 1 && (fread(STDIN, 8192) === '' || feof(STDIN))) {
                    break;
                }
            }
            $this->line('barrier released');

            return self::SUCCESS;
        } finally {
            $locks->close();
        }
    }
}
