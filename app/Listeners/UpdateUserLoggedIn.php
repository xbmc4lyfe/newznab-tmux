<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\UserLoggedIn;
use App\Jobs\RecordApiUsage;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

class UpdateUserLoggedIn
{
    private static bool $useRustFfi = false;

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    /** @return array{lastlogin: string|CarbonInterface, host: string} */
    public static function buildUpdateAttributes(string|CarbonInterface $occurredAt, string $ip): array
    {
        return self::$useRustFfi ? self::buildUpdateAttributesNative($occurredAt, $ip) : self::buildUpdateAttributesPhp($occurredAt, $ip);
    }

    /** @return array{lastlogin: string|CarbonInterface, host: string} */
    public static function buildUpdateAttributesPhp(string|CarbonInterface $occurredAt, string $ip): array
    {
        return ['lastlogin' => $occurredAt, 'host' => $ip];
    }

    /** @return array{lastlogin: string|CarbonInterface, host: string} */
    public static function buildUpdateAttributesNative(string|CarbonInterface $occurredAt, string $ip): array
    {
        $result = RecordApiUsage::transformNative(3, [is_string($occurredAt) ? $occurredAt : '', $ip]);
        if (is_array($result) && array_keys($result) === ['lastlogin', 'host'] && is_string($result['lastlogin']) && is_string($result['host'])) {
            $result['lastlogin'] = $occurredAt;

            return $result;
        }

        return self::buildUpdateAttributesPhp($occurredAt, $ip);
    }

    /** @return array{user_id: int, username: string, ip: string, time: string|CarbonInterface} */
    public static function buildLogPayload(int $userId, string $username, string $ip, string|CarbonInterface $occurredAt): array
    {
        return self::$useRustFfi ? self::buildLogPayloadNative($userId, $username, $ip, $occurredAt) : self::buildLogPayloadPhp($userId, $username, $ip, $occurredAt);
    }

    /** @return array{user_id: int, username: string, ip: string, time: string|CarbonInterface} */
    public static function buildLogPayloadPhp(int $userId, string $username, string $ip, string|CarbonInterface $occurredAt): array
    {
        return ['user_id' => $userId, 'username' => $username, 'ip' => $ip, 'time' => $occurredAt];
    }

    /** @return array{user_id: int, username: string, ip: string, time: string|CarbonInterface} */
    public static function buildLogPayloadNative(int $userId, string $username, string $ip, string|CarbonInterface $occurredAt): array
    {
        $result = RecordApiUsage::transformNative(8, [$userId, $username, $ip, is_string($occurredAt) ? $occurredAt : '']);
        if (is_array($result) && array_keys($result) === ['user_id', 'username', 'ip', 'time']
            && is_int($result['user_id']) && is_string($result['username']) && is_string($result['ip']) && is_string($result['time'])) {
            $result['time'] = $occurredAt;

            return $result;
        }

        return self::buildLogPayloadPhp($userId, $username, $ip, $occurredAt);
    }

    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(UserLoggedIn $event): void
    {
        User::find($event->user->id)->update(
            self::buildUpdateAttributes(now(), $event->ip)
        );

        // Log the user login event
        Log::channel('user_login')->info('User logged in', self::buildLogPayload(
            $event->user->id, $event->user->username, $event->ip, now(),
        ));
    }
}
