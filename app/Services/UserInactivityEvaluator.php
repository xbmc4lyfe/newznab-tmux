<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\RustPipelineLibrary;
use Carbon\CarbonInterface;
use FFI;
use FFI\CData;
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

    /** Enabled: three paired runs cleared every 1.5x gate (common mean 5.73-5.96x, p99 5.00-5.83x; uncommon mean 5.30-5.53x, p99 4.07-5.01x); see RustSystemTempUserInactivityStatsSeriesW51ParityTest. */
    private const bool W51_RUST_FFI_DEFAULT = true;

    public const string W51_FFI_CDEF = <<<'C'
        ssize_t nntmux_w513_user_purge_plan_batch(const char *blob, size_t blob_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    private static ?bool $useW51RustFfi = null;

    private static FFI|false|null $w51Ffi = null;

    private static ?CData $w51Out = null;

    private static int $w51OutCap = 0;

    public static function usesW51RustFfi(): bool
    {
        return self::$useW51RustFfi ?? self::W51_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 51 Rust path forced on or off, then restores the previous setting. */
    public static function withW51RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$useW51RustFfi;
        self::$useW51RustFfi = $enabled;
        try {
            return $callback();
        } finally {
            self::$useW51RustFfi = $previous;
        }
    }

    /** The `users` timestamp columns, in the order the Wave 51 export reads them. */
    private const array W51_DATE_COLUMNS = ['created_at', 'updated_at', 'last_login_at', 'api_access_at', 'last_download_at'];

    /**
     * Applies shouldPurge() to a batch of raw `users` rows. Timestamps are `Y-m-d H:i:s` (or
     * `T`-separated) strings compared through their fourteen digits, so any other value, including
     * null, counts as absent; `grabs` is read as `(int)`. An invalid $threshold makes nothing stale.
     * Two words per row: `flags | rule << 4` (flags 1 login stale, 2 API stale, 4 download stale,
     * 8 purge; rule 0 last download, 1 grabs with updated_at, 2 created_at) and the last-seen key
     * (the latest present login, API, download or created key, 0 when none). Then the purge and
     * kept counts and the oldest purged last-seen key (0 when none purge).
     *
     * @param  array<int, array<string, mixed>>  $rows  created_at, updated_at, last_login_at, api_access_at, last_download_at, grabs
     * @return list<int>
     */
    public static function w51PurgePlanBatch(array $rows, string $threshold): array
    {
        return self::usesW51RustFfi() ? self::w51PurgePlanBatchNative($rows, $threshold) : self::w51PurgePlanBatchPhp($rows, $threshold);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<int>
     */
    public static function w51PurgePlanBatchNative(array $rows, string $threshold): array
    {
        $count = \count($rows);
        if ($count === 0) {
            return self::w51PurgePlanBatchPhp($rows, $threshold);
        }
        $columns = self::w51Columns($rows, array_fill_keys(self::W51_DATE_COLUMNS, null) + ['grabs' => 0]);
        $blob = '';
        foreach (self::W51_DATE_COLUMNS as $column) {
            $blob .= implode("\0", $columns[$column])."\0";
        }

        return self::w51Invoke($blob.$threshold, pack('q*', ...$columns['grabs']), $count, $count * 2 + 3)
            ?? self::w51PurgePlanBatchPhp($rows, $threshold);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<int>
     */
    public static function w51PurgePlanBatchPhp(array $rows, string $threshold): array
    {
        $limit = self::w51DateKey($threshold);
        $out = [];
        $purged = 0;
        $oldest = 0;
        foreach ($rows as $row) {
            $created = self::w51DateKey((string) ($row['created_at'] ?? ''));
            $updated = self::w51DateKey((string) ($row['updated_at'] ?? ''));
            $login = self::w51DateKey((string) ($row['last_login_at'] ?? ''));
            $api = self::w51DateKey((string) ($row['api_access_at'] ?? ''));
            $download = self::w51DateKey((string) ($row['last_download_at'] ?? ''));
            $loginStale = self::w51Stale($login ?? $created, $limit);
            $apiStale = self::w51Stale($api ?? $created, $limit);
            if ($download !== null) {
                [$downloadStale, $rule] = [self::w51Stale($download, $limit), 0];
            } elseif ((int) ($row['grabs'] ?? 0) > 0) {
                [$downloadStale, $rule] = [self::w51Stale($updated, $limit), 1];
            } else {
                [$downloadStale, $rule] = [self::w51Stale($created, $limit), 2];
            }
            $purge = $loginStale && $apiStale && $downloadStale;
            $seen = max($login ?? 0, $api ?? 0, $download ?? 0, $created ?? 0);
            if ($purge) {
                $oldest = ++$purged === 1 ? $seen : min($oldest, $seen);
            }
            $flags = ($loginStale ? 1 : 0) | ($apiStale ? 2 : 0) | ($downloadStale ? 4 : 0) | ($purge ? 8 : 0);
            array_push($out, $flags | $rule << 4, $seen);
        }
        array_push($out, $purged, \count($rows) - $purged, $oldest);

        return $out;
    }

    /** The fourteen digits of a `Y-m-d H:i:s` (or `T`-separated) timestamp, else null. */
    private static function w51DateKey(string $stamp): ?int
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})$/D', $stamp, $m) === 1
            ? (int) ($m[1].$m[2].$m[3].$m[4].$m[5].$m[6])
            : null;
    }

    private static function w51Stale(?int $key, ?int $threshold): bool
    {
        return $key !== null && $threshold !== null && $key < $threshold;
    }

    /**
     * One column per key of $defaults. Rows missing a key read its default; present nulls stay
     * null, which pack() and implode() read as 0 and '' like the defaults they replace.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $defaults
     * @return array<string, list<mixed>>
     */
    private static function w51Columns(array $rows, array $defaults): array
    {
        $count = \count($rows);
        $columns = [];
        foreach ($defaults as $key => $default) {
            $column = array_column($rows, $key);
            if (\count($column) !== $count) {
                $filled = [];
                foreach ($rows as $row) {
                    $filled[] = $row + $defaults;
                }
                $columns = [];
                foreach ($defaults as $name => $unused) {
                    $columns[$name] = array_column($filled, $name);
                }

                return $columns;
            }
            $columns[$key] = $column;
        }

        return $columns;
    }

    /**
     * Calls the Wave 51 export with NUL-joined strings and packed `i64` integers. Null when the
     * library is missing, the call declines (for example a string holding a NUL byte) or it writes
     * more than $cap words, so the caller falls back to PHP.
     *
     * @return list<int>|null
     */
    private static function w51Invoke(string $blob, string $ints, int $count, int $cap): ?array
    {
        self::$w51Ffi ??= RustPipelineLibrary::resolveDomain('categorize_w51', self::W51_FFI_CDEF) ?? false;
        $ffi = self::$w51Ffi;
        if ($ffi === false) {
            return null;
        }
        try {
            if (self::$w51Out === null || self::$w51OutCap < $cap) {
                self::$w51OutCap = max(1024, $cap * 2);
                self::$w51Out = $ffi->new('int64_t['.self::$w51OutCap.']');
            }
            // @phpstan-ignore method.notFound (Declared by W51_FFI_CDEF.)
            $written = $ffi->nntmux_w513_user_purge_plan_batch($blob, \strlen($blob), $ints, \strlen($ints), $count, self::$w51Out, self::$w51OutCap);
            if (! \is_int($written) || $written <= 0 || $written > $cap) {
                return null;
            }
            $values = unpack('P*', FFI::string(self::$w51Out, $written * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
