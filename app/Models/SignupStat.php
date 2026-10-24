<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SignupStat extends Model
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

    /** @return array{attributes: array{month: mixed}, values: array{signups: mixed, sort_date: mixed}} */
    public static function buildPayload(mixed $month, mixed $signups, mixed $sort_date): array
    {
        return self::$useRustFfi ? self::buildPayloadNative($month, $signups, $sort_date) : self::buildPayloadPhp($month, $signups, $sort_date);
    }

    /** @return array{attributes: array{month: mixed}, values: array{signups: mixed, sort_date: mixed}} */
    public static function buildPayloadPhp(mixed $month, mixed $signups, mixed $sort_date): array
    {
        return ['attributes' => ['month' => $month], 'values' => ['signups' => $signups, 'sort_date' => $sort_date]];
    }

    /** @return array{attributes: array{month: mixed}, values: array{signups: mixed, sort_date: mixed}} */
    public static function buildPayloadNative(mixed $month, mixed $signups, mixed $sort_date): array
    {
        $result = DownloadStat::transformNative(6, [$month, $signups, $sort_date]);

        return is_array($result) && array_keys($result) === ['attributes', 'values']
            && is_array($result['attributes']) && array_keys($result['attributes']) === ['month']
            && is_array($result['values']) && array_keys($result['values']) === ['signups', 'sort_date']
            ? $result : self::buildPayloadPhp($month, $signups, $sort_date);
    }

    public static function insertUsersByMonth(): void
    {
        $users = User::query()
            ->whereNotNull('created_at')
            ->where('created_at', '<>', '0000-00-00 00:00:00')
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m-01') as sort_date, DATE_FORMAT(created_at, '%M %Y') as mth, COUNT(id) as num")
            ->groupBy(['sort_date', 'mth'])
            ->orderByDesc('sort_date')
            ->get();

        foreach ($users as $user) {
            $payload = self::buildPayload($user->mth, $user->num, $user->sort_date);
            self::updateOrCreate($payload['attributes'], $payload['values']);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function getUsersByMonth(): array
    {
        return self::query()
            ->select(['month', 'signups'])
            ->orderByDesc('sort_date')
            ->get()
            ->toArray();
    }
}
