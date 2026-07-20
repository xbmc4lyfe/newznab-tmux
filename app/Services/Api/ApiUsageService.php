<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Jobs\UpdateUserApiAccess;
use App\Models\User;
use App\Models\UserRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ApiUsageService
{
    public function statistics(int $userId): object
    {
        return Cache::remember('api_user_stats:'.$userId, 60, function () use ($userId): object {
            $oneDayAgo = now()->subDay()->toDateTimeString();

            return DB::selectOne('SELECT
                (SELECT COUNT(*) FROM user_requests WHERE users_id = ? AND timestamp > ?) as api_count,
                (SELECT COUNT(*) FROM user_downloads WHERE users_id = ? AND timestamp > ?) as grab_count,
                (SELECT MIN(timestamp) FROM user_requests WHERE users_id = ? AND timestamp > ?) as api_time,
                (SELECT MIN(timestamp) FROM user_downloads WHERE users_id = ? AND timestamp > ?) as grab_time',
                [$userId, $oneDayAgo, $userId, $oneDayAgo, $userId, $oneDayAgo, $userId, $oneDayAgo]
            );
        });
    }

    public function record(User $user, Request $request): void
    {
        $occurredAt = now()->toDateTimeString();

        // Request accounting drives quotas and admin statistics, so it must be
        // durable before the response returns even when no queue worker runs.
        UserRequest::recordApiRequest(
            $user->id,
            $request->getRequestUri(),
            $occurredAt,
        );

        $job = new UpdateUserApiAccess(
            $user->id,
            $request->ip(),
            $occurredAt,
        );

        if ((bool) config('nntmux.api.async_audit', true)) {
            try {
                dispatch($job);
            } catch (Throwable) {
                // Keep access metadata current when the queue backend is unavailable.
                $job->handle();
            }

            return;
        }

        $job->handle();
    }
}
