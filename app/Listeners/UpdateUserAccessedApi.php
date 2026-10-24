<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\UserAccessedApi;
use App\Jobs\RecordApiUsage;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class UpdateUserAccessedApi
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

    /** @return array{apiaccess: string|CarbonInterface, host?: string} */
    public static function buildUpdateAttributes(string|CarbonInterface $occurredAt, ?string $ip): array
    {
        return self::$useRustFfi ? self::buildUpdateAttributesNative($occurredAt, $ip) : self::buildUpdateAttributesPhp($occurredAt, $ip);
    }

    /** @return array{apiaccess: string|CarbonInterface, host?: string} */
    public static function buildUpdateAttributesPhp(string|CarbonInterface $occurredAt, ?string $ip): array
    {
        return RecordApiUsage::buildUpdateAttributesPhp($occurredAt, $ip);
    }

    /** @return array{apiaccess: string|CarbonInterface, host?: string} */
    public static function buildUpdateAttributesNative(string|CarbonInterface $occurredAt, ?string $ip): array
    {
        return RecordApiUsage::buildUpdateAttributesNative($occurredAt, $ip);
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
    public function handle(UserAccessedApi $event): void
    {
        $updateData = self::buildUpdateAttributes(now(), $event->ip);

        DB::table('users')
            ->where('id', $event->user->id)
            ->update($updateData);
    }
}
