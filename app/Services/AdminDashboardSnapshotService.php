<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DnzbFailure;
use App\Models\Payment;
use App\Models\Release;
use App\Models\ReleaseReport;
use App\Models\UsenetGroup;
use App\Models\User;
use App\Models\UserActivity;
use App\Support\RustPipelineLibrary;
use FFI\CData;
use Illuminate\Support\Facades\Cache;

/**
 * Builds (and caches) the entire admin dashboard payload in a single
 * Cache::flexible() entry with deferred rebuilding during the stale window.
 *
 * - Fresh window: 900s
 * - Stale grace period: 60s (expires after 960s total)
 * - Warmed every fifteen minutes by `admin:warm-dashboard`.
 */
class AdminDashboardSnapshotService
{
    public const CACHE_KEY = 'admin:dashboard:snapshot:v2';

    /**
     * @var array{0: int, 1: int}
     */
    private const CACHE_TTL = [900, 960];

    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_admin_dashboard_activity_badge_classes_nul(
    const char *color_class,
    size_t color_class_len,
    uint8_t *out,
    size_t out_cap
);
ssize_t nntmux_admin_dashboard_normalize_payment_row_nul(
    int64_t id,
    const char *username,
    size_t username_len,
    const char *email,
    size_t email_len,
    const char *item_description,
    size_t item_description_len,
    const char *invoice_amount,
    size_t invoice_amount_len,
    const char *payment_method,
    size_t payment_method_len,
    const char *payment_status,
    size_t payment_status_len,
    uint8_t has_invoice_status,
    const char *invoice_status,
    size_t invoice_status_len,
    const char *order_id,
    size_t order_id_len,
    int64_t *out_id,
    uint8_t *out,
    size_t out_cap
);
CDEF;

    /**
     * Production default stays PHP until paired benchmarks show >= 1.5x Rust speedup.
     */
    private static bool $useRustFfi = false;

    private static \FFI|false|null $ffi = null;

    public function __construct(
        private readonly UserStatsService $userStatsService,
        private readonly SystemMetricsService $systemMetricsService,
        private readonly RegistrationStatusService $registrationStatusService,
        private readonly SiteStatusService $siteStatusService,
    ) {}

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = false;
        self::$ffi = null;
    }

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(\FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    /**
     * Returns the cached dashboard snapshot, building it on first miss.
     *
     * @return array<string, mixed>
     */
    public function get(): array
    {
        return Cache::flexible(self::CACHE_KEY, self::CACHE_TTL, fn (): array => $this->build());
    }

    /**
     * Forcibly rebuild and re-cache the snapshot. Used by the warmer command
     * and by admin actions that want immediate freshness.
     *
     * @return array<string, mixed>
     */
    public function warm(): array
    {
        $payload = $this->build();
        // Re-prime both the value and the SWR control key used by Cache::flexible().
        Cache::forget(self::CACHE_KEY);

        return Cache::flexible(self::CACHE_KEY, self::CACHE_TTL, static fn (): array => $payload);
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array{icon_bg: string, icon_color: string}
     */
    public static function formatActivityBadgeClasses(string $colorClass): array
    {
        if (self::$useRustFfi) {
            return self::formatActivityBadgeClassesNative($colorClass);
        }

        return self::formatActivityBadgeClassesPhp($colorClass);
    }

    /**
     * @return array{icon_bg: string, icon_color: string}
     */
    public static function formatActivityBadgeClassesPhp(string $colorClass): array
    {
        return [
            'icon_bg' => 'bg-'.$colorClass.'-100 dark:bg-'.$colorClass.'-900',
            'icon_color' => 'text-'.$colorClass.'-600 dark:text-'.$colorClass.'-400',
        ];
    }

    /**
     * @return array{icon_bg: string, icon_color: string}
     */
    public static function formatActivityBadgeClassesNative(string $colorClass): array
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof \FFI) {
            return self::formatActivityBadgeClassesPhp($colorClass);
        }

        try {
            $cap = max(96, (strlen($colorClass) * 4) + 64);
            $out = $ffi->new("uint8_t[{$cap}]");
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_admin_dashboard_activity_badge_classes_nul(
                $colorClass,
                strlen($colorClass),
                $out,
                $cap
            );
            if (! is_int($written) || $written <= 0) {
                return self::formatActivityBadgeClassesPhp($colorClass);
            }

            $raw = \FFI::string($out, $written);
            $parts = explode("\0", $raw);
            if (count($parts) < 2) {
                return self::formatActivityBadgeClassesPhp($colorClass);
            }

            return [
                'icon_bg' => $parts[0],
                'icon_color' => $parts[1],
            ];
        } catch (\Throwable) {
            return self::formatActivityBadgeClassesPhp($colorClass);
        }
    }

    /**
     * @param  array<string, mixed>  $rawPayment
     * @return array{
     *     id: int,
     *     username: string,
     *     email: string,
     *     item_description: string,
     *     invoice_amount: string,
     *     payment_method: string,
     *     payment_status: string,
     *     invoice_status: string,
     *     order_id: string,
     *     created_at: ?string,
     *     created_at_human: ?string,
     *     created_at_formatted: ?string
     * }
     */
    public static function normalizePaymentRow(array $rawPayment): array
    {
        if (self::$useRustFfi) {
            return self::normalizePaymentRowNative($rawPayment);
        }

        return self::normalizePaymentRowPhp($rawPayment);
    }

    /**
     * @param  array<string, mixed>  $rawPayment
     * @return array{
     *     id: int,
     *     username: string,
     *     email: string,
     *     item_description: string,
     *     invoice_amount: string,
     *     payment_method: string,
     *     payment_status: string,
     *     invoice_status: string,
     *     order_id: string,
     *     created_at: ?string,
     *     created_at_human: ?string,
     *     created_at_formatted: ?string
     * }
     */
    public static function normalizePaymentRowPhp(array $rawPayment): array
    {
        return [
            'id' => self::coerceInt($rawPayment['id'] ?? 0),
            'username' => self::coerceString($rawPayment['username'] ?? ''),
            'email' => self::coerceString($rawPayment['email'] ?? ''),
            'item_description' => self::coerceString($rawPayment['item_description'] ?? ''),
            'invoice_amount' => self::coerceString($rawPayment['invoice_amount'] ?? ''),
            'payment_method' => self::coerceString($rawPayment['payment_method'] ?? ''),
            'payment_status' => self::coerceString($rawPayment['payment_status'] ?? ''),
            'invoice_status' => self::coerceString($rawPayment['invoice_status'] ?? Payment::INVOICE_STATUS_PENDING),
            'order_id' => self::coerceString($rawPayment['order_id'] ?? ''),
            'created_at' => isset($rawPayment['created_at']) ? self::coerceString($rawPayment['created_at']) : null,
            'created_at_human' => isset($rawPayment['created_at_human']) ? self::coerceString($rawPayment['created_at_human']) : null,
            'created_at_formatted' => isset($rawPayment['created_at_formatted']) ? self::coerceString($rawPayment['created_at_formatted']) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $rawPayment
     * @return array{
     *     id: int,
     *     username: string,
     *     email: string,
     *     item_description: string,
     *     invoice_amount: string,
     *     payment_method: string,
     *     payment_status: string,
     *     invoice_status: string,
     *     order_id: string,
     *     created_at: ?string,
     *     created_at_human: ?string,
     *     created_at_formatted: ?string
     * }
     */
    public static function normalizePaymentRowNative(array $rawPayment): array
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof \FFI) {
            return self::normalizePaymentRowPhp($rawPayment);
        }

        try {
            $id = self::coerceInt($rawPayment['id'] ?? 0);
            $username = self::coerceString($rawPayment['username'] ?? '');
            $email = self::coerceString($rawPayment['email'] ?? '');
            $itemDescription = self::coerceString($rawPayment['item_description'] ?? '');
            $invoiceAmount = self::coerceString($rawPayment['invoice_amount'] ?? '');
            $paymentMethod = self::coerceString($rawPayment['payment_method'] ?? '');
            $paymentStatus = self::coerceString($rawPayment['payment_status'] ?? '');
            $hasInvoiceStatus = array_key_exists('invoice_status', $rawPayment) && $rawPayment['invoice_status'] !== null ? 1 : 0;
            $invoiceStatus = $hasInvoiceStatus === 1 ? self::coerceString($rawPayment['invoice_status']) : '';
            $orderId = self::coerceString($rawPayment['order_id'] ?? '');

            $cap = max(
                256,
                strlen($username)
                + strlen($email)
                + strlen($itemDescription)
                + strlen($invoiceAmount)
                + strlen($paymentMethod)
                + strlen($paymentStatus)
                + strlen($invoiceStatus)
                + strlen($orderId)
                + 64
            );
            $outId = $ffi->new('int64_t[1]');
            $out = $ffi->new("uint8_t[{$cap}]");
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_admin_dashboard_normalize_payment_row_nul(
                $id,
                $username,
                strlen($username),
                $email,
                strlen($email),
                $itemDescription,
                strlen($itemDescription),
                $invoiceAmount,
                strlen($invoiceAmount),
                $paymentMethod,
                strlen($paymentMethod),
                $paymentStatus,
                strlen($paymentStatus),
                $hasInvoiceStatus,
                $invoiceStatus,
                strlen($invoiceStatus),
                $orderId,
                strlen($orderId),
                $outId,
                $out,
                $cap
            );
            $normalizedId = $outId[0];
            if (! is_int($written) || $written <= 0 || ! is_int($normalizedId)) {
                return self::normalizePaymentRowPhp($rawPayment);
            }

            $raw = \FFI::string($out, $written);
            $parts = explode("\0", $raw);
            if (count($parts) < 8) {
                return self::normalizePaymentRowPhp($rawPayment);
            }

            return [
                'id' => $normalizedId,
                'username' => $parts[0],
                'email' => $parts[1],
                'item_description' => $parts[2],
                'invoice_amount' => $parts[3],
                'payment_method' => $parts[4],
                'payment_status' => $parts[5],
                'invoice_status' => $parts[6],
                'order_id' => $parts[7],
                'created_at' => isset($rawPayment['created_at']) ? self::coerceString($rawPayment['created_at']) : null,
                'created_at_human' => isset($rawPayment['created_at_human']) ? self::coerceString($rawPayment['created_at_human']) : null,
                'created_at_formatted' => isset($rawPayment['created_at_formatted']) ? self::coerceString($rawPayment['created_at_formatted']) : null,
            ];
        } catch (\Throwable) {
            return self::normalizePaymentRowPhp($rawPayment);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function build(): array
    {
        $now = now();
        $registrationStatus = $this->registrationStatusService->resolve($now);
        $nextRegistrationPeriod = $registrationStatus['active_period'] === null
            ? $this->registrationStatusService->getNextUpcomingPeriod($now)
            : null;

        return [
            'generated_at' => $now->toIso8601String(),
            'stats' => $this->buildStats(),
            'userStats' => $this->buildUserStats(),
            'systemMetrics' => $this->buildSystemMetrics(),
            'recent_activity' => $this->buildRecentActivity(),
            'recent_payments' => $this->buildRecentPayments(),
            'serviceStatuses' => $this->siteStatusService->getEnabledServices(),
            'activeIncidents' => $this->siteStatusService->getActiveIncidents(),
            'registrationStatus' => $registrationStatus,
            'nextRegistrationPeriod' => $nextRegistrationPeriod,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStats(): array
    {
        $today = now()->format('Y-m-d');

        $releasesCount = Release::query()->count();
        $activeUsersCount = User::query()->count();
        $groupsCount = UsenetGroup::query()->count();
        $failedCount = DnzbFailure::query()->count();

        $activeGroupsCount = UsenetGroup::where('active', 1)->count();
        $reportedCount = ReleaseReport::where('status', 'pending')->count();
        $softDeletedCount = User::onlyTrashed()->count();

        // Replaces previous whereJsonContains() count which couldn't use an index.
        $permanentlyDeletedCount = UserActivity::query()
            ->where('activity_type', 'deleted')
            ->where('is_permanent', true)
            ->count();

        $releasesToday = Release::whereDate('adddate', $today)->count();
        $usersToday = User::whereNull('deleted_at')->whereDate('created_at', $today)->count();

        return [
            'releases' => $releasesCount,
            'releases_today' => $releasesToday,
            'users' => $activeUsersCount,
            'users_today' => $usersToday,
            'groups' => $groupsCount,
            'active_groups' => $activeGroupsCount,
            'failed' => $failedCount,
            'reported' => $reportedCount,
            'soft_deleted_users' => $softDeletedCount,
            'permanently_deleted_users' => $permanentlyDeletedCount,
            'disk_free' => $this->systemMetricsService->getDiskSpace(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildUserStats(): array
    {
        return [
            'users_by_role' => $this->userStatsService->getUsersByRole(),
            'downloads_per_hour' => $this->userStatsService->getDownloadsPerHour(168),
            'downloads_per_minute' => $this->userStatsService->getDownloadsPerMinute(60),
            'api_hits_per_hour' => $this->userStatsService->getApiHitsPerHour(168),
            'api_hits_per_minute' => $this->userStatsService->getApiHitsPerMinute(60),
            'summary' => $this->userStatsService->getSummaryStats(),
            'top_downloaders' => $this->userStatsService->getTopDownloaders(5),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSystemMetrics(): array
    {
        $cpuInfo = $this->systemMetricsService->getCpuInfo();
        $cpuUsage = $this->systemMetricsService->getCpuUsage();
        $ramUsage = $this->systemMetricsService->getRamUsage();
        $loadAverage = $this->systemMetricsService->getLoadAverage();

        return [
            'cpu' => [
                'current' => $cpuUsage,
                'label' => 'CPU Usage',
                'history_24h' => $this->systemMetricsService->getHourlyMetrics('cpu', 24),
                'history_30d' => $this->systemMetricsService->getDailyMetrics('cpu', 30),
                'cores' => $cpuInfo['cores'],
                'threads' => $cpuInfo['threads'],
                'model' => $cpuInfo['model'],
                'load_average' => $loadAverage,
            ],
            'ram' => [
                'used' => $ramUsage['used'],
                'total' => $ramUsage['total'],
                'percentage' => $ramUsage['percentage'],
                'label' => 'RAM Usage',
                'history_24h' => $this->systemMetricsService->getHourlyMetrics('ram', 24),
                'history_30d' => $this->systemMetricsService->getDailyMetrics('ram', 30),
            ],
        ];
    }

    /**
     * @return array<int, object>
     */
    private function buildRecentActivity(): array
    {
        $activities = UserActivity::orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        return $activities->map(static function ($activity): object {
            $badgeClasses = self::formatActivityBadgeClasses((string) $activity->color_class);

            return (object) [
                'type' => $activity->activity_type,
                'message' => $activity->description,
                'icon' => $activity->icon,
                'icon_bg' => $badgeClasses['icon_bg'],
                'icon_color' => $badgeClasses['icon_color'],
                'created_at' => $activity->created_at,
                'username' => $activity->username,
                'metadata' => $activity->metadata,
            ];
        })->all();
    }

    /**
     * Build the "Last 5 payments" widget payload (most recent records by id desc).
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildRecentPayments(): array
    {
        return Payment::query()
            ->orderByDesc('id')
            ->limit(5)
            ->get([
                'id',
                'created_at',
                'username',
                'email',
                'item_description',
                'invoice_amount',
                'payment_method',
                'payment_status',
                'invoice_status',
                'order_id',
            ])
            ->map(static function (Payment $payment): array {
                $createdAt = $payment->created_at;

                return self::normalizePaymentRow([
                    'id' => $payment->id,
                    'username' => $payment->username,
                    'email' => $payment->email,
                    'item_description' => $payment->item_description,
                    'invoice_amount' => $payment->invoice_amount,
                    'payment_method' => $payment->payment_method,
                    'payment_status' => $payment->payment_status,
                    'invoice_status' => $payment->invoice_status,
                    'order_id' => $payment->order_id,
                    'created_at' => $createdAt?->toIso8601String(),
                    'created_at_human' => $createdAt?->diffForHumans(),
                    'created_at_formatted' => $createdAt?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
                ]);
            })
            ->all();
    }

    private static function coerceInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) || is_float($value) || is_bool($value)) {
            return (int) $value;
        }

        return 0;
    }

    private static function coerceString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        return '';
    }

    private static function additionalFfi(): ?\FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof \FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W51 parity test). */
    private const bool W51_RUST_FFI_DEFAULT = true;

    private const string W51_FFI_CDEF = 'ssize_t nntmux_w511_admin_dashboard_activity_buckets_batch(const char *timestamps, size_t timestamps_len, size_t count, int64_t now, int64_t bucket_seconds, size_t bucket_count, int64_t *out, size_t out_cap);';

    public static function usesW51RustFfi(): bool
    {
        $enabled = self::w51RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W51_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 51 batch routed to Rust or PHP, then restores the routing. */
    public static function withW51RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w51RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w51RustFfiState('set', $previous);
        }
    }

    /**
     * Sparse activity histogram over `$bucketCount` newest-first buckets of `$bucketSeconds`
     * ending at `$now` (bucket `intdiv($now - $ts, $bucketSeconds)` for timestamps in
     * `($now - $bucketSeconds * $bucketCount, $now]`): `[outside, bucket, count, ...]` listing
     * only non-empty buckets in ascending order after the count of timestamps outside the window.
     *
     * @param  list<int>  $timestamps
     * @return list<int>
     */
    public static function bucketActivityTimestamps(array $timestamps, int $now, int $bucketSeconds, int $bucketCount): array
    {
        return self::usesW51RustFfi()
            ? self::bucketActivityTimestampsNative($timestamps, $now, $bucketSeconds, $bucketCount)
            : self::bucketActivityTimestampsPhp($timestamps, $now, $bucketSeconds, $bucketCount);
    }

    /**
     * @param  list<int>  $timestamps
     * @return list<int>
     */
    public static function bucketActivityTimestampsPhp(array $timestamps, int $now, int $bucketSeconds, int $bucketCount): array
    {
        $windowStart = self::w51ActivityWindowStart($now, $bucketSeconds, $bucketCount);
        $counts = [];
        $outside = 0;
        foreach ($timestamps as $timestamp) {
            if ($timestamp <= $now && $timestamp > $windowStart) {
                $bucket = intdiv($now - $timestamp, $bucketSeconds);
                $counts[$bucket] = ($counts[$bucket] ?? 0) + 1;
            } else {
                $outside++;
            }
        }
        ksort($counts);
        $words = [$outside];
        foreach ($counts as $bucket => $count) {
            $words[] = $bucket;
            $words[] = $count;
        }

        return $words;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or declines the batch.
     *
     * @param  list<int>  $timestamps
     * @return list<int>
     */
    public static function bucketActivityTimestampsNative(array $timestamps, int $now, int $bucketSeconds, int $bucketCount): array
    {
        self::w51ActivityWindowStart($now, $bucketSeconds, $bucketCount);
        $count = \count($timestamps);
        $maxWords = 2 * min($count, $bucketCount) + 1;
        $buffer = $count === 0 ? null : self::w51Buffer($maxWords);
        if ($buffer === null) {
            return self::bucketActivityTimestampsPhp($timestamps, $now, $bucketSeconds, $bucketCount);
        }
        [$ffi, $out, $cap] = $buffer;
        $packed = pack('P*', ...$timestamps);
        try {
            // @phpstan-ignore method.notFound
            $words = self::w51Decode($out, $ffi->nntmux_w511_admin_dashboard_activity_buckets_batch($packed, \strlen($packed), $count, $now, $bucketSeconds, $bucketCount, $out, $cap), $maxWords);
        } catch (\Throwable) {
            $words = null;
        }

        return $words !== null && \count($words) % 2 === 1
            ? $words
            : self::bucketActivityTimestampsPhp($timestamps, $now, $bucketSeconds, $bucketCount);
    }

    private static function w51ActivityWindowStart(int $now, int $bucketSeconds, int $bucketCount): int
    {
        if ($bucketSeconds < 1 || $bucketCount < 1 || $bucketCount > 4096) {
            throw new \InvalidArgumentException('Activity buckets need a positive width and 1-4096 buckets.');
        }
        if ($bucketSeconds > intdiv(PHP_INT_MAX, $bucketCount) || $now < PHP_INT_MIN + $bucketSeconds * $bucketCount) {
            throw new \InvalidArgumentException('Activity bucket window overflows an integer.');
        }

        return $now - $bucketSeconds * $bucketCount;
    }

    /**
     * Lazily resolved binding plus the reusable output buffer sized for `$words` words.
     *
     * @return array{0: \FFI, 1: CData, 2: int}|null
     */
    private static function w51Buffer(int $words): ?array
    {
        $buffer = self::w51RustFfiState('out', $words);

        return \is_array($buffer) ? $buffer : null;
    }

    /** @return list<int>|null The `$written` words the export reported, or null when it declined. */
    private static function w51Decode(CData $out, mixed $written, int $maxWords): ?array
    {
        if (! \is_int($written) || $written < 0 || $written > $maxWords) {
            return null;
        }
        if ($written === 0) {
            return [];
        }
        $decoded = unpack('P*', \FFI::string($out, $written * 8));

        /** @var list<int>|null */
        return \is_array($decoded) ? array_values($decoded) : null;
    }

    /**
     * Wave 51 routing override (`set`) plus the lazily resolved binding and reusable `int64_t`
     * output buffer (`out`, sized for `$val` words), kept in function-local statics.
     */
    private static function w51RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'out':
                $ffi ??= RustPipelineLibrary::resolveDomain('admin_status_w51', self::W51_FFI_CDEF) ?? false;
                if (! $ffi instanceof \FFI || ! \is_int($val)) {
                    return null;
                }
                if (! $out instanceof CData || $outCap < $val) {
                    $outCap = max(512, $val * 2);
                    $out = $ffi->new("int64_t[{$outCap}]");
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled;
        }
    }

    // ========================================================================
    // WAVE 65.4 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 1000-round paired runs reached 2.14x common mean, 2.14x common p99, 1.84x uncommon mean and 1.77x uncommon p99; see RustUsenetDownloadAdminSnapshotAnidbArchiveBlacklistW65ParityTest. */
    public const bool W65_RUST_FFI_DEFAULT = true;

    private const string W65_FFI_CDEF = 'ssize_t nntmux_w654_admin_dashboard_snapshot_plan_batch(const char *blob, size_t blob_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);';

    /** int64 words per w65AdminDashboardSnapshotPlanBatch() record. */
    public const int W65_ROW_WORDS = 4;

    /** int64 summary words after the w65AdminDashboardSnapshotPlanBatch() records (none for an empty batch). */
    public const int W65_TRAILER_WORDS = 13;

    private const array W65_KINDS = ['releases' => 1, 'groups' => 2, 'users' => 3, 'queue' => 4, 'storage' => 5, 'failed' => 6, 'reported' => 7];

    private const array W65_SERVICES = ['operational' => 1, 'degraded' => 2, 'partial_outage' => 3, 'major_outage' => 4, 'maintenance' => 5];

    private const int W65_VALUE_CAP = (1 << 40) - 1;

    private const int W65_TOTAL_CAP = 1 << 62;

    /**
     * Admin dashboard KPI rows for [KPI kind, service status, current value, previous value,
     * warn threshold, critical threshold, capacity] items.
     *
     * Kinds (trimmed, case-insensitive): 1 releases, 2 groups, 3 users, 4 queue, 5 storage, 6
     * failed, 7 reported, else 0. Service statuses: 0 empty, 1 operational, 2 degraded, 3
     * partial_outage, 4 major_outage, 5 maintenance, 6 other. Every integer clamps to
     * [0, 2^40). The delta is current - previous; the change is `intdiv(delta * 10000,
     * previous)` basis points (10000 from zero to a positive value, else 0) and the utilization
     * `intdiv(current * 10000, capacity)` (-1 without a capacity). The metric status compares
     * the release/group/user drop, the queue/failed/reported value or the storage utilization
     * with the thresholds (0 disables one): 0 ok, 1 warn, 2 critical, 3 unknown (kind 0, or
     * storage without a capacity). Degraded, partial outage and maintenance raise health to warn
     * and a major outage to critical; an unknown metric takes the service severity when it has
     * one.
     *
     * Rows [health | status << 4 | kind << 8 | service << 12 | flags << 16 (1 decrease, 2
     * increase, 4 no previous value, 8 no capacity, 16 unknown kind, 32 unknown service), delta,
     * change basis points, utilization basis points], then the trailer [records, ok, warn,
     * critical, unknown, increases, decreases, current total (saturating at 2^62), delta total,
     * first index of the worst known health (-1 when none), largest utilization (-1 when none),
     * maintenance records, FNV-1a digest].
     *
     * @param  list<array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int, 6: int}>  $items
     * @return list<int>
     */
    public static function w65AdminDashboardSnapshotPlanBatch(array $items): array
    {
        return self::usesW65RustFfi() ? self::w65AdminDashboardSnapshotPlanBatchNative($items) : self::w65AdminDashboardSnapshotPlanBatchPhp($items);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int, 6: int}>  $items
     * @return list<int>
     */
    public static function w65AdminDashboardSnapshotPlanBatchPhp(array $items): array
    {
        self::w65Items($items);
        if ($items === []) {
            return [];
        }
        $out = [];
        $t = [\count($items), 0, 0, 0, 0, 0, 0, 0, 0, -1, -1, 0, 0];
        $worst = -1;
        $digest = 2_166_136_261;
        foreach ($items as $i => [$kind, $service, $current, $previous, $warn, $critical, $capacity]) {
            $kind = self::W65_KINDS[strtolower(trim($kind))] ?? 0;
            $service = strtolower(trim($service));
            $service = $service === '' ? 0 : self::W65_SERVICES[$service] ?? 6;
            $current = max(0, min($current, self::W65_VALUE_CAP));
            $previous = max(0, min($previous, self::W65_VALUE_CAP));
            $warn = max(0, min($warn, self::W65_VALUE_CAP));
            $critical = max(0, min($critical, self::W65_VALUE_CAP));
            $capacity = max(0, min($capacity, self::W65_VALUE_CAP));
            $delta = $current - $previous;
            $pct = $previous > 0 ? intdiv($delta * 10_000, $previous) : ($current > 0 ? 10_000 : 0);
            $util = $capacity > 0 ? intdiv($current * 10_000, $capacity) : -1;
            if ($kind === 0 || ($kind === 5 && $util < 0)) {
                $status = 3;
            } else {
                $value = match ($kind) {
                    1, 2, 3 => -$pct,
                    5 => $util,
                    default => $current,
                };
                $status = $critical > 0 && $value >= $critical ? 2 : ($warn > 0 && $value >= $warn ? 1 : 0);
            }
            $severity = match ($service) {
                2, 3, 5 => 1,
                4 => 2,
                default => 0,
            };
            $health = $status === 3 ? ($severity > 0 ? $severity : 3) : max($status, $severity);
            $flags = ($delta < 0 ? 1 : 0) | ($delta > 0 ? 2 : 0) | ($previous === 0 ? 4 : 0) | ($capacity === 0 ? 8 : 0)
                | ($kind === 0 ? 16 : 0) | ($service === 6 ? 32 : 0);
            $word = $health | $status << 4 | $kind << 8 | $service << 12 | $flags << 16;
            array_push($out, $word, $delta, $pct, $util);
            $t[1 + $health]++;
            if ($delta > 0) {
                $t[5]++;
            } elseif ($delta < 0) {
                $t[6]++;
            }
            $t[7] = min($t[7] + $current, self::W65_TOTAL_CAP);
            $t[8] += $delta;
            if ($health < 3 && $health > $worst) {
                $worst = $health;
                $t[9] = $i;
            }
            $t[10] = max($t[10], $util);
            if ($service === 5) {
                $t[11]++;
            }
            $value = $word ^ $delta << 8 ^ $util;
            $digest = (($digest ^ (($value ^ ($value >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
        }
        $t[12] = $digest;

        return [...$out, ...$t];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int, 6: int}>  $items
     * @return list<int>
     */
    public static function w65AdminDashboardSnapshotPlanBatchNative(array $items): array
    {
        self::w65Items($items);
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        [$blob, $twin] = self::w65Join([array_column($items, 0), array_column($items, 1)]);
        $ints = pack('P*', ...array_column($items, 2), ...array_column($items, 3), ...array_column($items, 4), ...array_column($items, 5), ...array_column($items, 6));

        return self::w65Call($blob, $twin, $ints, $count, $count * self::W65_ROW_WORDS + self::W65_TRAILER_WORDS)
            ?? self::w65AdminDashboardSnapshotPlanBatchPhp($items);
    }

    /** @param array<array-key, mixed> $items */
    private static function w65Items(array $items): void
    {
        if (! array_is_list($items)) {
            throw new \InvalidArgumentException('W65 admin dashboard snapshot plan batch items must be a list.');
        }
        foreach ($items as $item) {
            if (! \is_array($item) || \count($item) !== 7 || ! array_is_list($item) || ! \is_string($item[0]) || ! \is_string($item[1]) || ! \is_int($item[2]) || ! \is_int($item[3]) || ! \is_int($item[4]) || ! \is_int($item[5]) || ! \is_int($item[6])) {
                throw new \InvalidArgumentException('W65 admin dashboard snapshot plan batch items must be [string KPI kind, string service status, int current, int previous, int warn threshold, int critical threshold, int capacity] lists.');
            }
        }
    }

    public static function usesW65RustFfi(): bool
    {
        $enabled = self::w65RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W65_RUST_FFI_DEFAULT;
    }

    public static function enableW65RustFfi(): void
    {
        self::w65RustFfiState('enabled', true);
    }

    public static function disableW65RustFfi(): void
    {
        self::w65RustFfiState('enabled', false);
    }

    /** Drop the W65 override, binding, output buffer and declined-call count; routing returns to W65_RUST_FFI_DEFAULT. */
    public static function resetW65RustFfi(): void
    {
        self::w65RustFfiState('reset');
    }

    /**
     * Run $callback with the W65 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW65RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w65RustFfiState('enabled');
        self::w65RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w65RustFfiState('enabled', $previous);
        }
    }

    /**
     * String columns NUL-joined field-major and, when a value holds a NUL, the same columns
     * joined by 0x01 so Rust finds the real separators.
     *
     * @param  list<list<string>>  $columns
     * @return array{0: string, 1: string}
     */
    private static function w65Join(array $columns): array
    {
        $values = array_merge(...$columns);
        $joined = implode("\0", $values);

        return substr_count($joined, "\0") === \count($values) - 1 ? [$joined, ''] : [$joined, implode("\x01", $values)];
    }

    /**
     * One FFI crossing for the batch: $need int64 words back. Null when the library is missing
     * or declines a malformed frame, so the caller falls back to PHP; a declined call with a
     * resolved library is counted in the 'misses' state.
     *
     * @return list<int>|null
     */
    private static function w65Call(string $blob, string $twin, string $ints, int $count, int $need): ?array
    {
        /** @var array{enabled: ?bool, ffi: \FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        $state = self::w65RustFfiState();
        $ffi = $state['ffi'];
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata_w65', self::W65_FFI_CDEF) ?? false;
            self::w65RustFfiState('ffi', $ffi);
        }
        if (! $ffi instanceof \FFI) {
            return null;
        }
        try {
            $out = $state['out'];
            $cap = $state['cap'];
            if (! $out instanceof CData || $cap < $need) {
                $cap = max(4096, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w65RustFfiState('out', $out);
                self::w65RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W65_FFI_CDEF.)
            $written = $ffi->nntmux_w654_admin_dashboard_snapshot_plan_batch($blob, \strlen($blob), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $cap);
            $values = $written === $need ? unpack('P*', \FFI::string($out, $need * 8)) : false;
            if ($values !== false) {
                return array_values($values);
            }
        } catch (\Throwable) {
        }
        self::w65RustFfiState('misses', $state['misses'] + 1);

        return null;
    }

    /**
     * W65 routing override, cached binding, reusable output buffer and declined-call count, kept
     * in a function static so the class declares no new property: no arguments returns the state,
     * one argument reads a key ('reset' clears everything) and two arguments write a key.
     */
    private static function w65RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: \FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;

            return null;
        }

        return $state[$op] ?? null;
    }
}
