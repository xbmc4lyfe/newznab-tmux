<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\UserInactivityEvaluator;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RemoveInactiveAccounts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private static bool $useRustFfi = false;

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function cutoff(CarbonInterface $now, int $purgeDays): CarbonInterface
    {
        return self::$useRustFfi ? self::cutoffNative($now, $purgeDays) : self::cutoffPhp($now, $purgeDays);
    }

    public static function cutoffPhp(CarbonInterface $now, int $purgeDays): CarbonInterface
    {
        return $now->copy()->subDays($purgeDays);
    }

    public static function cutoffNative(CarbonInterface $now, int $purgeDays): CarbonInterface
    {
        $result = RecordApiUsage::transformNative(4, [(int) $now->format('Y'), (int) $now->format('n'), (int) $now->format('j'), $purgeDays]);
        if (is_array($result) && array_keys($result) === [0, 1, 2] && is_int($result[0]) && is_int($result[1]) && is_int($result[2])) {
            // Calendar days, not elapsed 86400-second periods across DST.
            if ($purgeDays === 0) {
                return $now->copy();
            }
            $candidate = $now->copy()->setDate($result[0], $result[1], $result[2]);

            // setDate can retain a nonexistent wall time at a DST gap even
            // when its epoch is normalized. Round-trip it without losing micros.
            return $candidate->setTimestamp($candidate->getTimestamp())->setMicrosecond((int) $now->format('u'));
        }

        return self::cutoffPhp($now, $purgeDays);
    }

    public static function shouldPurge(?CarbonInterface $createdAt, ?CarbonInterface $updatedAt, ?CarbonInterface $lastLoginAt, ?CarbonInterface $apiAccessAt, ?CarbonInterface $lastDownloadAt, int $grabs, CarbonInterface $threshold): bool
    {
        return self::$useRustFfi
            ? self::shouldPurgeNative($createdAt, $updatedAt, $lastLoginAt, $apiAccessAt, $lastDownloadAt, $grabs, $threshold)
            : self::shouldPurgePhp($createdAt, $updatedAt, $lastLoginAt, $apiAccessAt, $lastDownloadAt, $grabs, $threshold);
    }

    public static function shouldPurgePhp(?CarbonInterface $createdAt, ?CarbonInterface $updatedAt, ?CarbonInterface $lastLoginAt, ?CarbonInterface $apiAccessAt, ?CarbonInterface $lastDownloadAt, int $grabs, CarbonInterface $threshold): bool
    {
        return (new UserInactivityEvaluator)->shouldPurgePhp($createdAt, $updatedAt, $lastLoginAt, $apiAccessAt, $lastDownloadAt, $grabs, $threshold);
    }

    public static function shouldPurgeNative(?CarbonInterface $createdAt, ?CarbonInterface $updatedAt, ?CarbonInterface $lastLoginAt, ?CarbonInterface $apiAccessAt, ?CarbonInterface $lastDownloadAt, int $grabs, CarbonInterface $threshold): bool
    {
        $timestamps = [];
        foreach ([$createdAt, $updatedAt, $lastLoginAt, $apiAccessAt, $lastDownloadAt, $threshold] as $date) {
            if ($date === null) {
                $timestamps[] = null;

                continue;
            }
            $seconds = $date->getTimestamp();
            if ($seconds < -9_000_000_000_000 || $seconds > 9_000_000_000_000) {
                return self::shouldPurgePhp($createdAt, $updatedAt, $lastLoginAt, $apiAccessAt, $lastDownloadAt, $grabs, $threshold);
            }
            $timestamps[] = $seconds * 1_000_000 + (int) $date->format('u');
        }
        $thresholdUs = array_pop($timestamps);
        $result = RecordApiUsage::transformNative(5, [...$timestamps, $grabs, $thresholdUs]);

        return is_bool($result) ? $result : self::shouldPurgePhp($createdAt, $updatedAt, $lastLoginAt, $apiAccessAt, $lastDownloadAt, $grabs, $threshold);
    }

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $purgeDays = (int) config('nntmux.purge_inactive_users_days');
        $threshold = self::cutoff(now(), $purgeDays);

        User::query()
            ->where('roles_id', UserRole::USER->value)
            ->where('created_at', '<', $threshold)
            ->chunkById(100, static function ($users) use ($threshold): void {
                $users
                    ->filter(
                        static fn (User $user): bool => self::shouldPurge(
                            createdAt: $user->created_at,
                            updatedAt: $user->updated_at,
                            lastLoginAt: $user->lastlogin,
                            apiAccessAt: $user->apiaccess,
                            lastDownloadAt: $user->lastdownload,
                            grabs: $user->grabs,
                            threshold: $threshold,
                        )
                    )
                    ->each(static fn (User $user) => $user->delete());
            });
    }
}
