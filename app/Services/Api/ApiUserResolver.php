<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

final class ApiUserResolver
{
    public function v1(string $token): ?User
    {
        return Cache::remember('api_user:'.md5($token), 300, static function () use ($token): ?User {
            $user = User::findVerifiedByApiToken($token);
            $user?->load('role');

            return $user;
        });
    }

    public function v2(string $token): ?User
    {
        return Cache::remember('api_user:'.md5($token), 300, static fn (): ?User => User::query()
            ->whereApiToken($token)
            ->with('role')
            ->first());
    }
}
