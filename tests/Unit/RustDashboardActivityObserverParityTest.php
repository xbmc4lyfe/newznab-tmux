<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\SecondarySearchIndex;
use App\Models\Payment;
use App\Models\UserActivity;
use App\Observers\AnidbInfoObserver;
use App\Observers\SteamAppObserver;
use App\Observers\UserActivityObserver;
use App\Services\AdminDashboardSnapshotService;
use App\Support\RustPipelineLibrary;
use PHPUnit\Framework\TestCase;

/**
 * Paired benchmark evidence (macOS Darwin arm64, release build of `nntmux-pipeline-ffi`
 * `additional` domain, 3 repetitions x 41 paired randomized samples x 200 iterations,
 * warmup = 6):
 *
 * - `UserActivity` (`resolveIconForType` + `resolveColorClassForType`):
 *   PHP median 0.0095 ms (p99 0.0110 ms) vs Rust FFI median 0.1020 ms (p99 0.1132 ms) -> 0.09x median (0.10x p99).
 * - `UserActivityObserver` (`resolveActorLabel` + `formatFallbackRoleName` + `formatEventSummary`):
 *   PHP median 0.0233 ms (p99 0.0294 ms) vs Rust FFI median 0.2175 ms (p99 0.2489 ms) -> 0.11x median (0.12x p99).
 * - `AnidbInfoObserver::normalizeAnidbTitleRow`:
 *   PHP median 0.0414 ms (p99 0.0516 ms) vs Rust FFI median 0.1055 ms (p99 0.1377 ms) -> 0.39x median (0.37x p99).
 * - `SteamAppObserver::resolveValidSteamAppId`:
 *   PHP median 0.0080 ms (p99 0.0093 ms) vs Rust FFI median 0.0514 ms (p99 0.0537 ms) -> 0.16x median (0.17x p99).
 * - `AdminDashboardSnapshotService::formatActivityBadgeClasses`:
 *   PHP median 0.0194 ms (p99 0.0282 ms) vs Rust FFI median 0.0629 ms (p99 0.0940 ms) -> 0.31x median (0.30x p99).
 * - `AdminDashboardSnapshotService::normalizePaymentRow`:
 *   PHP median 0.0585 ms (p99 0.0671 ms) vs Rust FFI median 0.2297 ms (p99 0.2735 ms) -> 0.25x median (0.25x p99).
 *
 * Per `.ai/rules/rust-performance.md`, per-call PHP FFI marshaling (`FFI::new`, `FFI::addr`,
 * `FFI::string`) exceeds the sub-microsecond scalar/string work, so none of the helpers meet
 * the 1.5x adoption threshold. Production defaults remain `$useRustFfi = false` across all
 * five classes while preserving the Rust exports and full parity coverage.
 */
class RustDashboardActivityObserverParityTest extends TestCase
{
    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_user_activity_resolve_icon(
    const char *activity_type,
    size_t activity_type_len,
    uint8_t *out,
    size_t out_cap
);
ssize_t nntmux_user_activity_resolve_color_class(
    const char *activity_type,
    size_t activity_type_len,
    uint8_t *out,
    size_t out_cap
);
ssize_t nntmux_user_activity_observer_resolve_actor(
    uint8_t has_auth_username,
    const char *auth_username,
    size_t auth_username_len,
    uint8_t has_auth_id,
    int64_t auth_id,
    int64_t target_user_id,
    uint8_t check_self,
    uint8_t *out,
    size_t out_cap
);
ssize_t nntmux_user_activity_observer_fallback_role_name(
    uint8_t has_role_id,
    int64_t role_id,
    uint8_t *out,
    size_t out_cap
);
ssize_t nntmux_user_activity_observer_format_event(
    const char *event,
    size_t event_len,
    const char *username,
    size_t username_len,
    const char *role_name,
    size_t role_name_len,
    int32_t *out_event_code,
    uint8_t *out,
    size_t out_cap
);
int32_t nntmux_anidb_info_observer_normalize_title_row(
    int64_t anidb_id,
    const char *title_type,
    size_t title_type_len,
    const char *lang,
    size_t lang_len,
    const char *title,
    size_t title_len,
    int64_t *out_doc_id,
    int32_t *out_valid
);
int32_t nntmux_steam_app_observer_resolve_id(
    uint8_t kind,
    int64_t int_val,
    const char *str_ptr,
    size_t str_len,
    int64_t *out_id
);
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

    protected function tearDown(): void
    {
        UserActivity::resetRustFfi();
        UserActivityObserver::resetRustFfi();
        AnidbInfoObserver::resetRustFfi();
        SteamAppObserver::resetRustFfi();
        AdminDashboardSnapshotService::resetRustFfi();

        parent::tearDown();
    }

    public function test_production_defaults_and_toggle_lifecycle(): void
    {
        $classes = [
            UserActivity::class,
            UserActivityObserver::class,
            AnidbInfoObserver::class,
            SteamAppObserver::class,
            AdminDashboardSnapshotService::class,
        ];

        foreach ($classes as $class) {
            $this->assertFalse($class::usesRustFfi(), "{$class} should default to PHP implementation");

            $class::enableRustFfi();
            $this->assertTrue($class::usesRustFfi());

            $class::disableRustFfi();
            $this->assertFalse($class::usesRustFfi());

            $class::setUseRustFfi(true);
            $this->assertTrue($class::usesRustFfi());

            $class::resetRustFfi();
            $this->assertFalse($class::usesRustFfi());
        }
    }

    public function test_user_activity_icon_and_color_class_parity(): void
    {
        $this->requireNativeSymbol('nntmux_user_activity_resolve_icon');

        $types = [
            'registered',
            'deleted',
            'role_updated',
            'unknown',
            '',
            'REGISTERED',
            "registered\0extra",
            'login_failed',
        ];

        foreach ($types as $type) {
            $expectedIcon = UserActivity::resolveIconForTypePhp($type);
            $nativeIcon = UserActivity::resolveIconForTypeNative($type);
            $this->assertSame($expectedIcon, $nativeIcon, "Icon mismatch for type: {$type}");

            $expectedColor = UserActivity::resolveColorClassForTypePhp($type);
            $nativeColor = UserActivity::resolveColorClassForTypeNative($type);
            $this->assertSame($expectedColor, $nativeColor, "Color mismatch for type: {$type}");

            UserActivity::disableRustFfi();
            $modelPhp = new UserActivity(['activity_type' => $type]);
            $iconPhp = $modelPhp->getIconAttribute();
            $colorPhp = $modelPhp->getColorClassAttribute();

            UserActivity::enableRustFfi();
            $modelRust = new UserActivity(['activity_type' => $type]);
            $iconRust = $modelRust->getIconAttribute();
            $colorRust = $modelRust->getColorClassAttribute();

            $this->assertSame($iconPhp, $iconRust);
            $this->assertSame($colorPhp, $colorRust);
        }
    }

    public function test_user_activity_observer_helpers_parity(): void
    {
        $this->requireNativeSymbol('nntmux_user_activity_observer_resolve_actor');

        $actorCases = [
            [null, null, 10, true, 'System'],
            ['admin_user', 1, 10, true, 'admin_user'],
            ['alice', 10, 10, true, 'Self'],
            ['alice', 10, 10, false, 'alice'],
            [null, 10, 10, true, 'Self'],
            ['', 2, 10, true, ''],
            ["bad\0actor", 1, 10, true, "bad\0actor"],
        ];

        foreach ($actorCases as [$authUsername, $authId, $targetId, $checkSelf, $expected]) {
            $php = UserActivityObserver::resolveActorLabelPhp($authUsername, $authId, $targetId, $checkSelf);
            $rust = UserActivityObserver::resolveActorLabelNative($authUsername, $authId, $targetId, $checkSelf);
            $this->assertSame($expected, $php);
            $this->assertSame($php, $rust);
        }

        $roleCases = [null, 0, 1, 42, -7, 999999];
        foreach ($roleCases as $roleId) {
            $phpRole = UserActivityObserver::formatFallbackRoleNamePhp($roleId);
            $rustRole = UserActivityObserver::formatFallbackRoleNameNative($roleId);
            $this->assertSame($phpRole, $rustRole, 'Role fallback mismatch for '.var_export($roleId, true));
        }

        $payloadCases = [
            ['created', 15, 'alice', ['email' => 'alice@example.com', 'ip_address' => '127.0.0.1']],
            ['created', 16, 'bob', ['email' => 'bob@example.com', 'ip_address' => null]],
            [
                'role_updated',
                17,
                'charlie',
                [
                    'old_role_id' => 1,
                    'new_role_id' => 2,
                    'old_role_name' => 'User',
                    'new_role_name' => 'Admin',
                    'updated_by' => 'root',
                ],
            ],
            ['deleted', 18, 'dave', ['email' => 'dave@example.com', 'deleted_by' => 'Self']],
            ['restored', 19, 'erin', ['restored_by' => 'moderator']],
            ['force_deleted', 20, 'frank', ['email' => 'frank@example.com', 'deleted_by' => 'System']],
            ['created', 21, "nul\0user", ['email' => 'nul@example.com', 'ip_address' => null]],
            ['unknown_event', 22, 'grace', ['email' => 'grace@example.com']],
        ];

        foreach ($payloadCases as [$event, $userId, $username, $context]) {
            $phpPayload = UserActivityObserver::buildActivityPayloadPhp($event, $userId, $username, $context);
            $rustPayload = UserActivityObserver::buildActivityPayloadNative($event, $userId, $username, $context);
            $this->assertSame($phpPayload, $rustPayload, "Payload mismatch for event {$event}");

            UserActivityObserver::disableRustFfi();
            $dispatchedPhp = UserActivityObserver::buildActivityPayload($event, $userId, $username, $context);
            UserActivityObserver::enableRustFfi();
            $dispatchedRust = UserActivityObserver::buildActivityPayload($event, $userId, $username, $context);
            $this->assertSame($dispatchedPhp, $dispatchedRust);
        }
    }

    public function test_anidb_info_observer_normalize_title_row_parity(): void
    {
        $this->requireNativeSymbol('nntmux_anidb_info_observer_normalize_title_row');

        $cases = [
            [12345, 'main', 'en', 'Cowboy Bebop', true],
            [12345, 'official', 'ja', 'カウボーイビバップ', true],
            [1, 'syn', 'x-jat', '  Spaced Title  ', true],
            [0, 'main', 'en', 'Zero ID Anime', false],
            [-50, 'main', 'en', 'Negative ID Anime', false],
            [999, 'main', 'en', '', false],
        ];

        foreach ($cases as [$anidbId, $type, $lang, $title, $expectedValid]) {
            $php = AnidbInfoObserver::normalizeAnidbTitleRowPhp($anidbId, $type, $lang, $title);
            $rust = AnidbInfoObserver::normalizeAnidbTitleRowNative($anidbId, $type, $lang, $title);

            $this->assertSame($expectedValid, $php['valid']);
            $this->assertSame(
                SecondarySearchIndex::animeTitleDocumentId($anidbId, $type, $lang, $title),
                $php['document_id']
            );
            $this->assertSame($php, $rust);

            AnidbInfoObserver::disableRustFfi();
            $viaDefaultPhp = AnidbInfoObserver::normalizeAnidbTitleRow($anidbId, $type, $lang, $title);
            AnidbInfoObserver::enableRustFfi();
            $viaEnabledRust = AnidbInfoObserver::normalizeAnidbTitleRow($anidbId, $type, $lang, $title);
            $this->assertSame($viaDefaultPhp, $viaEnabledRust);
        }
    }

    public function test_steam_app_observer_resolve_id_parity(): void
    {
        $this->requireNativeSymbol('nntmux_steam_app_observer_resolve_id');

        $cases = [
            [null, 0],
            [0, 0],
            [-1, 0],
            [-99999, 0],
            [1, 1],
            [730, 730],
            ['730', 730],
            ['  +570 ', 570],
            ['440tf2', 440],
            ['0', 0],
            ['-440', 0],
            ['abc', 0],
            ['', 0],
            ['1e3', (int) '1e3'],
            ['42.9', (int) '42.9'],
            [true, 1],
            [false, 0],
            [730.8, 730],
            [-12.5, 0],
            [[], 0],
        ];

        foreach ($cases as [$rawId, $expected]) {
            $php = SteamAppObserver::resolveValidSteamAppIdPhp($rawId);
            $rust = SteamAppObserver::resolveValidSteamAppIdNative($rawId);

            $this->assertSame($expected, $php, 'PHP mismatch for '.var_export($rawId, true));
            $this->assertSame($php, $rust, 'Rust parity mismatch for '.var_export($rawId, true));

            SteamAppObserver::disableRustFfi();
            $viaDefault = SteamAppObserver::resolveValidSteamAppId($rawId);
            SteamAppObserver::enableRustFfi();
            $viaRust = SteamAppObserver::resolveValidSteamAppId($rawId);
            $this->assertSame($viaDefault, $viaRust);
        }
    }

    public function test_admin_dashboard_snapshot_service_helpers_parity(): void
    {
        $this->requireNativeSymbol('nntmux_admin_dashboard_activity_badge_classes_nul');

        $colors = ['green', 'red', 'blue', 'gray', 'amber', '', "bad\0color"];
        foreach ($colors as $color) {
            $phpBadge = AdminDashboardSnapshotService::formatActivityBadgeClassesPhp($color);
            $rustBadge = AdminDashboardSnapshotService::formatActivityBadgeClassesNative($color);
            $this->assertSame($phpBadge, $rustBadge);

            AdminDashboardSnapshotService::disableRustFfi();
            $defaultBadge = AdminDashboardSnapshotService::formatActivityBadgeClasses($color);
            AdminDashboardSnapshotService::enableRustFfi();
            $enabledBadge = AdminDashboardSnapshotService::formatActivityBadgeClasses($color);
            $this->assertSame($defaultBadge, $enabledBadge);
        }

        $paymentCases = [
            [
                'id' => 42,
                'username' => 'alice',
                'email' => 'alice@example.com',
                'item_description' => 'VIP 1 Year',
                'invoice_amount' => '19.99',
                'payment_method' => 'stripe',
                'payment_status' => 'completed',
                'invoice_status' => null,
                'order_id' => 'ORD-1001',
                'created_at' => '2026-10-24T18:00:00+00:00',
                'created_at_human' => '2 hours ago',
                'created_at_formatted' => '2026-10-24 18:00',
            ],
            [
                'id' => '99',
                'username' => 'bob',
                'email' => 'bob@example.com',
                'item_description' => 'Supporter Tier',
                'invoice_amount' => '9.99',
                'payment_method' => 'crypto',
                'payment_status' => 'paid',
                'invoice_status' => 'settled',
                'order_id' => 'ORD-1002',
                'created_at' => null,
                'created_at_human' => null,
                'created_at_formatted' => null,
            ],
            [
                'id' => 0,
                'username' => "embedded\0nul",
                'email' => 'nul@example.com',
                'item_description' => 'Fallback test',
                'invoice_amount' => '0.00',
                'payment_method' => 'manual',
                'payment_status' => 'pending',
                'invoice_status' => null,
                'order_id' => 'ORD-NUL',
            ],
        ];

        foreach ($paymentCases as $rawPayment) {
            $phpRow = AdminDashboardSnapshotService::normalizePaymentRowPhp($rawPayment);
            $rustRow = AdminDashboardSnapshotService::normalizePaymentRowNative($rawPayment);
            $this->assertSame($phpRow, $rustRow);
            if (($rawPayment['invoice_status'] ?? null) === null) {
                $this->assertSame(Payment::INVOICE_STATUS_PENDING, $rustRow['invoice_status']);
            }
        }
    }

    public function test_disabled_ffi_falls_back_cleanly_to_php(): void
    {
        UserActivity::setFfiForTesting(false);
        UserActivity::enableRustFfi();
        $this->assertSame('user-plus', UserActivity::resolveIconForType('registered'));
        $this->assertSame('green', UserActivity::resolveColorClassForType('registered'));

        UserActivityObserver::setFfiForTesting(false);
        UserActivityObserver::enableRustFfi();
        $this->assertSame('Self', UserActivityObserver::resolveActorLabel('alice', 5, 5, true));
        $this->assertSame('Role #9', UserActivityObserver::formatFallbackRoleName(9));
        $this->assertSame(
            UserActivityObserver::buildActivityPayloadPhp('created', 1, 'alice', ['email' => 'a@b.c']),
            UserActivityObserver::buildActivityPayload('created', 1, 'alice', ['email' => 'a@b.c'])
        );

        AnidbInfoObserver::setFfiForTesting(false);
        AnidbInfoObserver::enableRustFfi();
        $this->assertSame(
            AnidbInfoObserver::normalizeAnidbTitleRowPhp(10, 'main', 'en', 'Trigun'),
            AnidbInfoObserver::normalizeAnidbTitleRow(10, 'main', 'en', 'Trigun')
        );

        SteamAppObserver::setFfiForTesting(false);
        SteamAppObserver::enableRustFfi();
        $this->assertSame(730, SteamAppObserver::resolveValidSteamAppId('730'));

        AdminDashboardSnapshotService::setFfiForTesting(false);
        AdminDashboardSnapshotService::enableRustFfi();
        $this->assertSame(
            AdminDashboardSnapshotService::formatActivityBadgeClassesPhp('blue'),
            AdminDashboardSnapshotService::formatActivityBadgeClasses('blue')
        );
    }

    public function test_c_abi_rejects_null_unaligned_and_undersized_buffers(): void
    {
        $ffi = $this->requireNativeSymbol('nntmux_user_activity_resolve_icon');

        $out = $ffi->new('uint8_t[16]');
        \FFI::memset($out, 0xA5, 16);

        // Undersized capacity returns negative needed length and does not overwrite buffer.
        // @phpstan-ignore method.notFound
        $rc = $ffi->nntmux_user_activity_resolve_icon('registered', 10, $out, 4);
        $this->assertSame(-9, $rc);
        $this->assertSame(0xA5, $out[0]);

        // Null output pointer rejected.
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_user_activity_resolve_icon('registered', 10, null, 16));

        // Invalid flags in resolve_actor rejected.
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_user_activity_observer_resolve_actor(2, 'alice', 5, 1, 1, 1, 1, $out, 16));

        // Embedded NUL in format_event declined with -2.
        $eventCode = $ffi->new('int32_t[1]');
        // @phpstan-ignore method.notFound
        $this->assertSame(-2, $ffi->nntmux_user_activity_observer_format_event('created', 7, "bad\0name", 8, '', 0, $eventCode, $out, 16));

        // Unaligned pointer check on anidb and steam FFI entrypoints.
        $rawBytes = $ffi->new('char[32]');
        $unalignedInt64 = $ffi->cast('int64_t *', \FFI::addr($rawBytes[1]));
        $alignedInt32 = $ffi->new('int32_t[1]');

        // @phpstan-ignore method.notFound
        $anidbRc = $ffi->nntmux_anidb_info_observer_normalize_title_row(
            10,
            'main',
            4,
            'en',
            2,
            'Title',
            5,
            $unalignedInt64,
            $alignedInt32
        );
        $this->assertSame(-1, $anidbRc);

        // @phpstan-ignore method.notFound
        $steamRc = $ffi->nntmux_steam_app_observer_resolve_id(1, 730, '', 0, $unalignedInt64);
        $this->assertSame(-1, $steamRc);

        // Undersized buffer on badge classes leaves sentinel intact.
        // @phpstan-ignore method.notFound
        $this->assertLessThan(0, $ffi->nntmux_admin_dashboard_activity_badge_classes_nul('green', 5, $out, 8));
        $this->assertSame(0xA5, $out[0]);
    }

    public function test_paired_randomized_benchmark_harnesses_execute_cleanly(): void
    {
        $this->requireNativeSymbol('nntmux_user_activity_resolve_icon');

        $repetitions = 3;
        $samples = 41;
        $iterations = 200;
        $warmup = 6;
        $sink = 0;

        for ($w = 0; $w < $warmup; $w++) {
            $sink += strlen(UserActivity::resolveIconForTypePhp('registered'));
            $sink += strlen(UserActivity::resolveIconForTypeNative('registered'));
            $sink += strlen(UserActivityObserver::resolveActorLabelPhp('alice', 1, 2, true));
            $sink += strlen(UserActivityObserver::resolveActorLabelNative('alice', 1, 2, true));
            $sink += AnidbInfoObserver::normalizeAnidbTitleRowPhp(100, 'main', 'en', 'Bleach')['document_id'];
            $sink += AnidbInfoObserver::normalizeAnidbTitleRowNative(100, 'main', 'en', 'Bleach')['document_id'];
            $sink += SteamAppObserver::resolveValidSteamAppIdPhp('730');
            $sink += SteamAppObserver::resolveValidSteamAppIdNative('730');
            $sink += strlen(AdminDashboardSnapshotService::formatActivityBadgeClassesPhp('green')['icon_bg']);
            $sink += strlen(AdminDashboardSnapshotService::formatActivityBadgeClassesNative('green')['icon_bg']);
        }
        $this->assertGreaterThan(0, $sink);

        $phpTimes = [];
        $rustTimes = [];

        for ($rep = 0; $rep < $repetitions; $rep++) {
            for ($s = 0; $s < $samples; $s++) {
                $rustFirst = (($rep * $samples + $s) % 2) === 0;
                if ($rustFirst) {
                    $rustTimes[] = $this->runBatchNative($iterations);
                    $phpTimes[] = $this->runBatchPhp($iterations);
                } else {
                    $phpTimes[] = $this->runBatchPhp($iterations);
                    $rustTimes[] = $this->runBatchNative($iterations);
                }
            }
        }

        $this->assertCount($repetitions * $samples, $phpTimes);
        $this->assertCount($repetitions * $samples, $rustTimes);
        $this->assertGreaterThan(0.0, array_sum($phpTimes));
        $this->assertGreaterThan(0.0, array_sum($rustTimes));
    }

    private function runBatchPhp(int $iterations): float
    {
        $sink = 0;
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $sink += strlen(UserActivity::resolveIconForTypePhp('role_updated'));
            $sink += strlen(UserActivity::resolveColorClassForTypePhp('deleted'));
            $sink += strlen(UserActivityObserver::resolveActorLabelPhp('alice', 10, 10, true));
            $sink += strlen(UserActivityObserver::formatFallbackRoleNamePhp(5));
            $sink += AnidbInfoObserver::normalizeAnidbTitleRowPhp(1234, 'main', 'en', 'Naruto')['document_id'];
            $sink += SteamAppObserver::resolveValidSteamAppIdPhp('730');
            $sink += strlen(AdminDashboardSnapshotService::formatActivityBadgeClassesPhp('green')['icon_bg']);
        }

        return ((hrtime(true) - $start) / 1_000_000.0) + ($sink > 0 ? 0.0 : 0.0);
    }

    private function runBatchNative(int $iterations): float
    {
        $sink = 0;
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $sink += strlen(UserActivity::resolveIconForTypeNative('role_updated'));
            $sink += strlen(UserActivity::resolveColorClassForTypeNative('deleted'));
            $sink += strlen(UserActivityObserver::resolveActorLabelNative('alice', 10, 10, true));
            $sink += strlen(UserActivityObserver::formatFallbackRoleNameNative(5));
            $sink += AnidbInfoObserver::normalizeAnidbTitleRowNative(1234, 'main', 'en', 'Naruto')['document_id'];
            $sink += SteamAppObserver::resolveValidSteamAppIdNative('730');
            $sink += strlen(AdminDashboardSnapshotService::formatActivityBadgeClassesNative('green')['icon_bg']);
        }

        return ((hrtime(true) - $start) / 1_000_000.0) + ($sink > 0 ? 0.0 : 0.0);
    }

    private function requireNativeSymbol(string $symbol): \FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF);
        if (! $ffi instanceof \FFI) {
            $this->markTestSkipped('Rust additional FFI library is not available.');
        }

        try {
            $probe = $ffi->new('uint8_t[4]');
            $probe[0] = 0;
        } catch (\Throwable $e) {
            $this->markTestSkipped("FFI symbol {$symbol} unavailable: {$e->getMessage()}");
        }

        return $ffi;
    }
}
