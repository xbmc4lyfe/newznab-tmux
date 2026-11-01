<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Backoff([1, 5, 30])]
#[FailOnTimeout]
final class UpdateUserApiAccess implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public int $timeout = 15;

    public function __construct(
        public readonly int $userId,
        public readonly ?string $ip,
        public readonly string $occurredAt,
    ) {
        $this->onQueue((string) config('nntmux.api.audit_queue', 'api-audit'));
    }

    public function handle(): void
    {
        $lockKey = 'api_access_update:'.$this->userId;
        $interval = max(1, (int) config('nntmux.api.access_update_interval', 60));

        try {
            if (! Cache::add($lockKey, true, $interval)) {
                return;
            }
        } catch (Throwable) {
            // Cache degradation must not prevent the metadata update.
        }

        $update = ['apiaccess' => $this->occurredAt];
        if ($this->ip !== null) {
            $update['host'] = $this->ip;
        }

        DB::table('users')->where('id', $this->userId)->update($update);
    }
}
