<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Events\UserAccessedApi;
use App\Models\User;
use App\Models\UserRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
        UserRequest::addApiRequest($user->id, $request->getRequestUri());
        event(new UserAccessedApi($user, $request->ip()));
    }
}
