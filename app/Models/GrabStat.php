<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GrabStat extends Model
{
    use HasFactory; // @phpstan-ignore missingType.generics

    protected $guarded = [];

    // Opt-in experiment: retain PHP unless every caller-inclusive performance gate qualifies.
    private static bool $useRustFfi = false;

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    /** @return array{attributes: array{username: mixed}, values: array{grabs: mixed}} */
    public static function buildPayload(mixed $username, mixed $grabs): array
    {
        return self::$useRustFfi ? self::buildPayloadNative($username, $grabs) : self::buildPayloadPhp($username, $grabs);
    }

    /** @return array{attributes: array{username: mixed}, values: array{grabs: mixed}} */
    public static function buildPayloadPhp(mixed $username, mixed $grabs): array
    {
        return ['attributes' => ['username' => $username], 'values' => ['grabs' => $grabs]];
    }

    /** @return array{attributes: array{username: mixed}, values: array{grabs: mixed}} */
    public static function buildPayloadNative(mixed $username, mixed $grabs): array
    {
        $result = DownloadStat::transformNative(1, [$username, $grabs]);

        return is_array($result) && array_keys($result) === ['attributes', 'values']
            && is_array($result['attributes']) && array_keys($result['attributes']) === ['username']
            && is_array($result['values']) && array_keys($result['values']) === ['grabs']
            ? $result : self::buildPayloadPhp($username, $grabs);
    }

    public static function insertTopGrabbers(): void
    {
        $users = User::query()->selectRaw('id, username, SUM(grabs) as grabs')->groupBy('id', 'username')->having('grabs', '>', 0)->orderByDesc('grabs')->limit(10)->get();
        // Insert data into the grab_stats table
        foreach ($users as $user) {
            $payload = self::buildPayload($user->username, $user->grabs);
            self::updateOrCreate($payload['attributes'], $payload['values']);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function getTopGrabbers(): array
    {
        return self::query()->select(['username', 'grabs'])->orderByDesc('grabs')->limit(10)->get()->toArray();
    }
}
