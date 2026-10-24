<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\RustPipelineLibrary;
use Carbon\CarbonInterface;
use FFI;
use Throwable;

final class UserInactivityEvaluator
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_user_inactivity_is_activity_stale(uint8_t has_activity_at, int64_t activity_at_us, uint8_t has_fallback_at, int64_t fallback_at_us, int64_t threshold_us);
        int32_t nntmux_user_inactivity_is_download_stale(uint8_t has_created_at, int64_t created_at_us, uint8_t has_updated_at, int64_t updated_at_us, uint8_t has_last_download_at, int64_t last_download_at_us, int64_t grabs, int64_t threshold_us);
        int32_t nntmux_user_inactivity_should_purge(uint8_t has_created_at, int64_t created_at_us, uint8_t has_updated_at, int64_t updated_at_us, uint8_t has_last_login_at, int64_t last_login_at_us, uint8_t has_api_access_at, int64_t api_access_at_us, uint8_t has_last_download_at, int64_t last_download_at_us, int64_t grabs, int64_t threshold_us);
        C;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public static function enableRustFfi(): void
    {
        self::setUseRustFfi(true);
    }

    public static function disableRustFfi(): void
    {
        self::setUseRustFfi(false);
    }

    public static function resetRustFfi(): void
    {
        self::setUseRustFfi(null);
    }

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use ?? false;
        if ($use === null) {
            self::$ffi = null;
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::$ffi = $override;
    }

    public static function categorizeFfi(bool $reset = false, FFI|false|null $override = null): ?FFI
    {
        if ($reset) {
            self::$ffi = $override;
            if ($override !== null) {
                return self::$ffi === false ? null : self::$ffi;
            }
        }
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('categorize', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    public function shouldPurge(
        ?CarbonInterface $createdAt,
        ?CarbonInterface $updatedAt,
        ?CarbonInterface $lastLoginAt,
        ?CarbonInterface $apiAccessAt,
        ?CarbonInterface $lastDownloadAt,
        int $grabs,
        CarbonInterface $threshold
    ): bool {
        return self::$useRustFfi
            ? $this->shouldPurgeNative($createdAt, $updatedAt, $lastLoginAt, $apiAccessAt, $lastDownloadAt, $grabs, $threshold)
            : $this->shouldPurgePhp($createdAt, $updatedAt, $lastLoginAt, $apiAccessAt, $lastDownloadAt, $grabs, $threshold);
    }

    public function shouldPurgePhp(
        ?CarbonInterface $createdAt,
        ?CarbonInterface $updatedAt,
        ?CarbonInterface $lastLoginAt,
        ?CarbonInterface $apiAccessAt,
        ?CarbonInterface $lastDownloadAt,
        int $grabs,
        CarbonInterface $threshold
    ): bool {
        return $this->isActivityStalePhp($lastLoginAt, $createdAt, $threshold)
            && $this->isActivityStalePhp($apiAccessAt, $createdAt, $threshold)
            && $this->isDownloadActivityStalePhp($createdAt, $updatedAt, $lastDownloadAt, $grabs, $threshold);
    }

    public function shouldPurgeNative(
        ?CarbonInterface $createdAt,
        ?CarbonInterface $updatedAt,
        ?CarbonInterface $lastLoginAt,
        ?CarbonInterface $apiAccessAt,
        ?CarbonInterface $lastDownloadAt,
        int $grabs,
        CarbonInterface $threshold
    ): bool {
        $ffi = self::categorizeFfi();
        if ($ffi === null) {
            return $this->shouldPurgePhp($createdAt, $updatedAt, $lastLoginAt, $apiAccessAt, $lastDownloadAt, $grabs, $threshold);
        }

        $createdUs = self::toEpochMicroseconds($createdAt);
        $updatedUs = self::toEpochMicroseconds($updatedAt);
        $loginUs = self::toEpochMicroseconds($lastLoginAt);
        $apiUs = self::toEpochMicroseconds($apiAccessAt);
        $downloadUs = self::toEpochMicroseconds($lastDownloadAt);
        $thresholdUs = self::toEpochMicroseconds($threshold);

        if (
            $thresholdUs === null
            || ($createdAt !== null && $createdUs === null)
            || ($updatedAt !== null && $updatedUs === null)
            || ($lastLoginAt !== null && $loginUs === null)
            || ($apiAccessAt !== null && $apiUs === null)
            || ($lastDownloadAt !== null && $downloadUs === null)
        ) {
            return $this->shouldPurgePhp($createdAt, $updatedAt, $lastLoginAt, $apiAccessAt, $lastDownloadAt, $grabs, $threshold);
        }

        try {
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_user_inactivity_should_purge(
                $createdUs !== null ? 1 : 0,
                $createdUs ?? 0,
                $updatedUs !== null ? 1 : 0,
                $updatedUs ?? 0,
                $loginUs !== null ? 1 : 0,
                $loginUs ?? 0,
                $apiUs !== null ? 1 : 0,
                $apiUs ?? 0,
                $downloadUs !== null ? 1 : 0,
                $downloadUs ?? 0,
                $grabs,
                $thresholdUs,
            );
            if ($rc === 0 || $rc === 1) {
                return $rc === 1;
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return $this->shouldPurgePhp($createdAt, $updatedAt, $lastLoginAt, $apiAccessAt, $lastDownloadAt, $grabs, $threshold);
    }

    public function isActivityStale(
        ?CarbonInterface $activityAt,
        ?CarbonInterface $fallbackAt,
        CarbonInterface $threshold
    ): bool {
        return self::$useRustFfi
            ? $this->isActivityStaleNative($activityAt, $fallbackAt, $threshold)
            : $this->isActivityStalePhp($activityAt, $fallbackAt, $threshold);
    }

    public function isActivityStalePhp(
        ?CarbonInterface $activityAt,
        ?CarbonInterface $fallbackAt,
        CarbonInterface $threshold
    ): bool {
        $comparisonDate = $activityAt ?? $fallbackAt;

        return $comparisonDate !== null && $comparisonDate->lt($threshold);
    }

    public function isActivityStaleNative(
        ?CarbonInterface $activityAt,
        ?CarbonInterface $fallbackAt,
        CarbonInterface $threshold
    ): bool {
        $ffi = self::categorizeFfi();
        if ($ffi === null) {
            return $this->isActivityStalePhp($activityAt, $fallbackAt, $threshold);
        }

        $activityUs = self::toEpochMicroseconds($activityAt);
        $fallbackUs = self::toEpochMicroseconds($fallbackAt);
        $thresholdUs = self::toEpochMicroseconds($threshold);

        if (
            $thresholdUs === null
            || ($activityAt !== null && $activityUs === null)
            || ($fallbackAt !== null && $fallbackUs === null)
        ) {
            return $this->isActivityStalePhp($activityAt, $fallbackAt, $threshold);
        }

        try {
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_user_inactivity_is_activity_stale(
                $activityUs !== null ? 1 : 0,
                $activityUs ?? 0,
                $fallbackUs !== null ? 1 : 0,
                $fallbackUs ?? 0,
                $thresholdUs,
            );
            if ($rc === 0 || $rc === 1) {
                return $rc === 1;
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return $this->isActivityStalePhp($activityAt, $fallbackAt, $threshold);
    }

    public function isDownloadActivityStale(
        ?CarbonInterface $createdAt,
        ?CarbonInterface $updatedAt,
        ?CarbonInterface $lastDownloadAt,
        int $grabs,
        CarbonInterface $threshold
    ): bool {
        return self::$useRustFfi
            ? $this->isDownloadActivityStaleNative($createdAt, $updatedAt, $lastDownloadAt, $grabs, $threshold)
            : $this->isDownloadActivityStalePhp($createdAt, $updatedAt, $lastDownloadAt, $grabs, $threshold);
    }

    public function isDownloadActivityStalePhp(
        ?CarbonInterface $createdAt,
        ?CarbonInterface $updatedAt,
        ?CarbonInterface $lastDownloadAt,
        int $grabs,
        CarbonInterface $threshold
    ): bool {
        if ($lastDownloadAt !== null) {
            return $lastDownloadAt->lt($threshold);
        }

        if ($grabs > 0) {
            return $updatedAt !== null && $updatedAt->lt($threshold);
        }

        return $createdAt !== null && $createdAt->lt($threshold);
    }

    public function isDownloadActivityStaleNative(
        ?CarbonInterface $createdAt,
        ?CarbonInterface $updatedAt,
        ?CarbonInterface $lastDownloadAt,
        int $grabs,
        CarbonInterface $threshold
    ): bool {
        $ffi = self::categorizeFfi();
        if ($ffi === null) {
            return $this->isDownloadActivityStalePhp($createdAt, $updatedAt, $lastDownloadAt, $grabs, $threshold);
        }

        $createdUs = self::toEpochMicroseconds($createdAt);
        $updatedUs = self::toEpochMicroseconds($updatedAt);
        $downloadUs = self::toEpochMicroseconds($lastDownloadAt);
        $thresholdUs = self::toEpochMicroseconds($threshold);

        if (
            $thresholdUs === null
            || ($createdAt !== null && $createdUs === null)
            || ($updatedAt !== null && $updatedUs === null)
            || ($lastDownloadAt !== null && $downloadUs === null)
        ) {
            return $this->isDownloadActivityStalePhp($createdAt, $updatedAt, $lastDownloadAt, $grabs, $threshold);
        }

        try {
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_user_inactivity_is_download_stale(
                $createdUs !== null ? 1 : 0,
                $createdUs ?? 0,
                $updatedUs !== null ? 1 : 0,
                $updatedUs ?? 0,
                $downloadUs !== null ? 1 : 0,
                $downloadUs ?? 0,
                $grabs,
                $thresholdUs,
            );
            if ($rc === 0 || $rc === 1) {
                return $rc === 1;
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return $this->isDownloadActivityStalePhp($createdAt, $updatedAt, $lastDownloadAt, $grabs, $threshold);
    }

    private static function toEpochMicroseconds(?CarbonInterface $date): ?int
    {
        if ($date === null) {
            return null;
        }
        $sec = $date->getTimestamp();
        if ($sec < -9_000_000_000_000 || $sec > 9_000_000_000_000) {
            return null;
        }

        return ($sec * 1_000_000) + (int) $date->format('u');
    }
}
