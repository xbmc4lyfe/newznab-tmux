<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Invitation;
use App\Models\RolePromotion;
use App\Models\UserActivityStat;
use App\Services\InvitationService;
use App\Services\UserStatsService;
use App\Support\RustPipelineLibrary;
use Carbon\Carbon;
use Closure;
use FFI;
use FFI\CData;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Parity, C-ABI contract, fallback, and paired benchmark verification for Wave 23 Slice 23.3:
 * UserStatsService, InvitationService, Invitation, RolePromotion, and UserActivityStat.
 *
 * Benchmarks run only with NNTMUX_USER_STATS_INVITATION_BENCH=1:
 *   cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release
 *   cp rust/pipeline-ffi/target/release/libnntmux_pipeline.dylib storage/app/nntmux-native/
 *   NNTMUX_USER_STATS_INVITATION_BENCH=1 php artisan test --compact tests/Unit/RustUserStatsInvitationParityTest.php
 *
 * Paired randomized benchmark evidence (3 repetitions x 41 paired samples x 200 inner loops,
 * 50 warmup calls per side, worst-repetition PHP latency / Rust latency, FFI call overhead included):
 *
 * | Method                                           | Common (Mean / P99) | Uncommon (Mean / P99) | >= 1.5x | Default     |
 * |--------------------------------------------------|---------------------|-----------------------|---------|-------------|
 * | UserStatsService::resolveMinuteBucketExpression  | 0.14x / 0.14x       | 0.15x / 0.15x         | No      | PHP (false) |
 * | UserStatsService::computeSummaryTotals           | 0.25x / 0.19x       | 0.26x / 0.24x         | No      | PHP (false) |
 * | InvitationService::calculateInviteAvailability   | 0.34x / 0.35x       | 0.34x / 0.36x         | No      | PHP (false) |
 * | Invitation::normalizeEmail                       | 0.19x / 0.20x       | 0.26x / 0.27x         | No      | PHP (false) |
 * | Invitation::evaluateInvitationState              | 0.42x / 0.42x       | 0.40x / 0.41x         | No      | PHP (false) |
 * | RolePromotion::evaluatePromotionActiveWindow     | 0.30x / 0.30x       | 0.32x / 0.31x         | No      | PHP (false) |
 * | RolePromotion::systemRoleNames                   | 0.10x / 0.09x       | 0.10x / 0.07x         | No      | PHP (false) |
 * | UserActivityStat::formatHourlyStatLabel          | 0.31x / 0.30x       | 0.33x / 0.34x         | No      | PHP (false) |
 *
 * Environment: Apple M5 arm64, Darwin 27.2.0, PHP 8.5.12 NTS, rustc 1.99.1 release profile, base commit 775ba6cf8.
 *
 * Median-repetition common-workload mean latency per call:
 *   UserStatsService::resolveMinuteBucketExpression: PHP 0.029 us, Rust 0.199 us
 *   UserStatsService::computeSummaryTotals: PHP 0.072 us, Rust 0.278 us
 *   InvitationService::calculateInviteAvailability: PHP 0.084 us, Rust 0.241 us
 *   Invitation::normalizeEmail: PHP 0.043 us, Rust 0.225 us
 *   Invitation::evaluateInvitationState: PHP 0.066 us, Rust 0.157 us
 *   RolePromotion::evaluatePromotionActiveWindow: PHP 0.045 us, Rust 0.144 us
 *   RolePromotion::systemRoleNames: PHP 0.023 us, Rust 0.230 us
 *   UserActivityStat::formatHourlyStatLabel: PHP 0.084 us, Rust 0.273 us
 *
 * Every Rust path is FFI-call bound against single-expression PHP, so all defaults stay PHP (false);
 * both *Native and *Php implementations remain public for parity verification.
 */
class RustUserStatsInvitationParityTest extends TestCase
{
    /** @var list<class-string> */
    private const array TOGGLED_CLASSES = [
        UserStatsService::class,
        InvitationService::class,
        Invitation::class,
        RolePromotion::class,
        UserActivityStat::class,
    ];

    private const string SQLITE_BUCKET = "strftime('%Y-%m-%d %H:%M:00', timestamp)";

    private const string MYSQL_BUCKET = 'DATE_FORMAT(timestamp, "%Y-%m-%d %H:%i:00")';

    private string $originalTimezone = 'UTC';

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        $this->resetFfiState();
        if (RustPipelineLibrary::resolveDomain('metadata', UserStatsService::FFI_CDEF) === null) {
            $this->markTestSkipped('Rust pipeline library is not built; run cargo build --release.');
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->resetFfiState();
        date_default_timezone_set($this->originalTimezone);
        parent::tearDown();
    }

    private function resetFfiState(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::resetRustFfi();
            $class::setFfiForTesting(null);
        }
    }

    public function test_production_defaults_follow_benchmark_gate(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $this->assertFalse(
                $class::usesRustFfi(),
                "{$class} must keep PHP as default until its Rust path passes the 1.5x gate across common and uncommon mean/p99"
            );
        }
    }

    public function test_toggle_api(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::enableRustFfi();
            $this->assertTrue($class::usesRustFfi());
            $class::disableRustFfi();
            $this->assertFalse($class::usesRustFfi());
            $class::setUseRustFfi(true);
            $this->assertTrue($class::usesRustFfi());
            $class::setUseRustFfi(false);
            $this->assertFalse($class::usesRustFfi());
            $class::enableRustFfi();
            $class::resetRustFfi();
            $this->assertFalse($class::usesRustFfi());
        }
    }

    // ---------------------------------------------------------------------
    // 1. UserStatsService
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function minuteBucketProvider(): array
    {
        return [
            'sqlite' => ['sqlite', self::SQLITE_BUCKET],
            'mysql' => ['mysql', self::MYSQL_BUCKET],
            'mariadb' => ['mariadb', self::MYSQL_BUCKET],
            'pgsql' => ['pgsql', self::MYSQL_BUCKET],
            'case sensitive sqlite' => ['SQLite', self::MYSQL_BUCKET],
            'padded sqlite' => [' sqlite', self::MYSQL_BUCKET],
            'sqlite with nul suffix' => ["sqlite\0", self::MYSQL_BUCKET],
            'empty driver' => ['', self::MYSQL_BUCKET],
            'utf8 driver' => ['sqlité', self::MYSQL_BUCKET],
        ];
    }

    #[DataProvider('minuteBucketProvider')]
    public function test_minute_bucket_expression_parity(string $driver, string $expected): void
    {
        $this->assertSame($expected, UserStatsService::resolveMinuteBucketExpressionPhp($driver));
        $this->assertSame($expected, UserStatsService::resolveMinuteBucketExpressionNative($driver));
        foreach ([true, false] as $toggle) {
            UserStatsService::setUseRustFfi($toggle);
            $this->assertSame($expected, UserStatsService::resolveMinuteBucketExpression($driver));
        }
    }

    /**
     * @return array<string, array{0: list<int>}>
     */
    public static function summaryTotalsProvider(): array
    {
        return [
            'all zero' => [[0, 0, 0, 0, 0, 0, 0]],
            'typical' => [[42, 12, 340, 900, 15000, 3, 25]],
            'large counts' => [[1_000_000, 9_000_000_000, 63_000_000_000, 4_000_000_000, 28_000_000_000, 750_000, 8_000_000]],
            'negative inputs pass through' => [[-1, -5, 10, -20, 30, 2, -3]],
            'near int64 max without overflow' => [[PHP_INT_MAX, PHP_INT_MAX - 1, PHP_INT_MAX - 2, 0, 0, 1, 0]],
            'near int64 min without overflow' => [[PHP_INT_MIN, PHP_INT_MIN + 1, 0, PHP_INT_MIN + 5, 0, -1, -5]],
        ];
    }

    /**
     * @param  list<int>  $args
     */
    #[DataProvider('summaryTotalsProvider')]
    public function test_summary_totals_parity(array $args): void
    {
        [$users, $todayDl, $weekDl, $todayApi, $weekApi, $curDl, $curApi] = $args;
        $expected = [
            'total_users' => $users,
            'downloads_today' => $todayDl + $curDl,
            'downloads_week' => $weekDl + $curDl,
            'api_hits_today' => $todayApi + $curApi,
            'api_hits_week' => $weekApi + $curApi,
        ];
        $this->assertSame($expected, UserStatsService::computeSummaryTotalsPhp(...$args));
        $this->assertSame($expected, UserStatsService::computeSummaryTotalsNative(...$args));
        foreach ([true, false] as $toggle) {
            UserStatsService::setUseRustFfi($toggle);
            $this->assertSame($expected, UserStatsService::computeSummaryTotals(...$args));
        }
    }

    public function test_summary_totals_overflow_falls_back_to_php_semantics(): void
    {
        $args = [1, PHP_INT_MAX, 0, 0, PHP_INT_MIN, 1, -1];
        $expected = [
            'total_users' => 1,
            'downloads_today' => (float) PHP_INT_MAX + 1.0,
            'downloads_week' => 1,
            'api_hits_today' => -1,
            'api_hits_week' => (float) PHP_INT_MIN - 1.0,
        ];
        $this->assertSame($expected, UserStatsService::computeSummaryTotalsPhp(...$args));
        $this->assertSame($expected, UserStatsService::computeSummaryTotalsNative(...$args));
    }

    public function test_user_stats_service_database_paths_match_across_toggles(): void
    {
        $this->useStatsSqlite();
        Carbon::setTestNow('2026-07-20 14:37:30');

        DB::table('users')->insert([['id' => 1, 'deleted_at' => null], ['id' => 2, 'deleted_at' => null], ['id' => 3, 'deleted_at' => '2026-01-01 00:00:00']]);
        foreach ([['2026-07-14 09:00:00', 3, 7], ['2026-07-20 09:00:00', 4, 11], ['2026-07-20 13:00:00', 2, 5], ['2026-07-13 23:00:00', 50, 50]] as [$hour, $dl, $api]) {
            DB::table('user_activity_stats_hourly')->insert(['stat_hour' => $hour, 'downloads_count' => $dl, 'api_hits_count' => $api]);
        }
        foreach (['2026-07-20 14:01:00', '2026-07-20 14:35:10', '2026-07-20 14:35:50', '2026-07-20 13:59:59'] as $ts) {
            DB::table('user_downloads')->insert(['users_id' => 1, 'timestamp' => $ts]);
            DB::table('user_requests')->insert(['users_id' => 1, 'request' => 'api', 'timestamp' => $ts]);
        }

        $service = new UserStatsService;
        $expectedSummary = [
            'total_users' => 2,
            'downloads_today' => 4 + 2 + 3,
            'downloads_week' => 3 + 4 + 2 + 3,
            'api_hits_today' => 11 + 5 + 3,
            'api_hits_week' => 7 + 11 + 5 + 3,
        ];

        $baseline = null;
        foreach ([false, true] as $toggle) {
            UserStatsService::setUseRustFfi($toggle);
            UserActivityStat::setUseRustFfi($toggle);
            $this->assertSame($expectedSummary, $service->getSummaryStats());
            $snapshot = [
                $service->getDownloadsPerMinute(5),
                $service->getApiHitsPerMinute(5),
                $service->getDownloadsPerHour(30),
                $service->getApiHitsPerHour(30),
            ];
            $baseline ??= $snapshot;
            $this->assertSame($baseline, $snapshot);
        }

        $this->assertSame([
            ['time' => '14:33', 'count' => 0],
            ['time' => '14:34', 'count' => 0],
            ['time' => '14:35', 'count' => 2],
            ['time' => '14:36', 'count' => 0],
            ['time' => '14:37', 'count' => 0],
        ], $baseline[0]);
        $perHour = $baseline[2];
        $this->assertSame('Yesterday 09:00', $perHour[0]['time']);
        $this->assertSame(['time' => '13:00', 'count' => 2], $perHour[28]);
        $this->assertSame(['time' => '14:00', 'count' => 3], $perHour[29]);
    }

    // ---------------------------------------------------------------------
    // 2. InvitationService
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{0: int, 1: int, 2: int, 3: int, 4: int}>
     */
    public static function inviteAvailabilityProvider(): array
    {
        return [
            'one invite none pending' => [1, 0, 0, 0, 0],
            'quota exhausted by pending' => [2, 2, 5, 1, 0],
            'over committed' => [1, 4, 0, 3, 2],
            'zero quota' => [0, 0, 7, 7, 7],
            'negative quota' => [-3, 0, 0, 0, 0],
            'large quota' => [PHP_INT_MAX, 1, 2, 3, 4],
            'large pending' => [PHP_INT_MIN + 1, 1, 0, 0, 0],
        ];
    }

    #[DataProvider('inviteAvailabilityProvider')]
    public function test_invite_availability_parity(int $total, int $active, int $used, int $expired, int $cancelled): void
    {
        $available = $total - $active;
        $expected = [
            'total_invites' => $total,
            'active_pending' => $active,
            'used_invitations' => $used,
            'expired_invitations' => $expired,
            'cancelled_invitations' => $cancelled,
            'calculated_available' => $available,
            'can_send_invite' => $available > 0,
        ];
        $this->assertSame($expected, InvitationService::calculateInviteAvailabilityPhp($total, $active, $used, $expired, $cancelled));
        $this->assertSame($expected, InvitationService::calculateInviteAvailabilityNative($total, $active, $used, $expired, $cancelled));
        foreach ([true, false] as $toggle) {
            InvitationService::setUseRustFfi($toggle);
            $this->assertSame($expected, InvitationService::calculateInviteAvailability($total, $active, $used, $expired, $cancelled));
        }
    }

    public function test_invite_availability_overflow_falls_back_to_php_semantics(): void
    {
        $expected = [
            'total_invites' => PHP_INT_MIN,
            'active_pending' => 1,
            'used_invitations' => 0,
            'expired_invitations' => 0,
            'cancelled_invitations' => 0,
            'calculated_available' => (float) PHP_INT_MIN - 1.0,
            'can_send_invite' => false,
        ];
        $this->assertSame($expected, InvitationService::calculateInviteAvailabilityPhp(PHP_INT_MIN, 1, 0, 0, 0));
        $this->assertSame($expected, InvitationService::calculateInviteAvailabilityNative(PHP_INT_MIN, 1, 0, 0, 0));
    }

    public function test_invitation_service_database_paths_match_across_toggles(): void
    {
        $this->useInvitationSqlite();
        Carbon::setTestNow('2026-07-20 12:00:00');

        DB::table('users')->insert([
            ['id' => 1, 'username' => 'alice', 'email' => 'alice@example.com', 'invites' => 3],
            ['id' => 2, 'username' => 'bob', 'email' => 'bob@example.com', 'invites' => 1],
        ]);
        $rows = [
            // active pending
            [1, 'p1@example.com', '2026-07-25 00:00:00', null, true],
            [1, 'p2@example.com', '2026-07-21 00:00:00', null, true],
            // used
            [1, 'u1@example.com', '2026-07-25 00:00:00', '2026-07-19 00:00:00', false],
            // expired
            [1, 'e1@example.com', '2026-07-10 00:00:00', null, true],
            // cancelled
            [1, 'c1@example.com', '2026-07-30 00:00:00', null, false],
            [2, 'b1@example.com', '2026-07-30 00:00:00', null, true],
        ];
        foreach ($rows as $i => [$by, $email, $expires, $used, $active]) {
            DB::table('invitations')->insert([
                'token' => str_repeat((string) $i, 8),
                'email' => $email,
                'invited_by' => $by,
                'expires_at' => $expires,
                'used_at' => $used,
                'is_active' => $active,
            ]);
        }

        $service = new InvitationService;
        $expectedAlice = [
            'user_id' => 1,
            'username' => 'alice',
            'total_invites' => 3,
            'active_pending' => 2,
            'used_invitations' => 1,
            'expired_invitations' => 1,
            'cancelled_invitations' => 1,
            'calculated_available' => 1,
            'can_send_invite' => true,
        ];
        foreach ([false, true] as $toggle) {
            InvitationService::setUseRustFfi($toggle);
            $this->assertSame($expectedAlice, $service->getUserInvitationDetails(1));
            $bob = $service->getUserInvitationDetails(2);
            $this->assertSame(0, $bob['calculated_available']);
            $this->assertFalse($bob['can_send_invite']);
            $this->assertSame([], $service->getUserInvitationDetails(99));

            $this->assertTrue($service->canUserSendInvitation(1));
            $this->assertTrue($service->canUserSendInvitation(1, 6));
            $this->assertFalse($service->canUserSendInvitation(1, 5));
            $this->assertFalse($service->canUserSendInvitation(1, 0));
            $this->assertFalse($service->canUserSendInvitation(1, -1));
            $this->assertTrue($service->canUserSendInvitation(99, 1));
            $this->assertFalse($service->canUserSendInvitation(99, 0));
        }
    }

    // ---------------------------------------------------------------------
    // 3. Invitation
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function emailProvider(): array
    {
        return [
            'plain lower' => ['user@example.com'],
            'mixed case' => ['John.Doe@Example.COM'],
            'php trim set' => [" \t\n\r\0\x0BPadded@Example.org\x0B\0\r\n\t "],
            'form feed is not trimmed' => ["\x0CForm@Feed.io\x0C"],
            'non breaking space is not trimmed' => ["\u{00A0}Nbsp@Example.com\u{00A0}"],
            'utf8 untouched by strtolower' => ['ÜSER@ÉXAMPLE.COM'],
            'invalid utf8 bytes' => ["\xC3\x28AB@CD.EF\xFF"],
            'single upper char' => ['A'],
            'single space' => [' '],
            'empty' => [''],
            'only trim bytes' => ["\0\0  \n"],
            'interior whitespace kept' => ['First Last@Example.com'],
            'long address' => [str_repeat('Segment.', 40).'@Example.COM   '],
        ];
    }

    #[DataProvider('emailProvider')]
    public function test_normalize_email_parity(string $email): void
    {
        $expected = strtolower(trim($email));
        $this->assertSame($expected, Invitation::normalizeEmailPhp($email));
        $this->assertSame($expected, Invitation::normalizeEmailNative($email));
        foreach ([true, false] as $toggle) {
            Invitation::setUseRustFfi($toggle);
            $this->assertSame($expected, Invitation::normalizeEmail($email));
        }
    }

    public function test_normalize_email_handles_inputs_larger_than_scratch(): void
    {
        $email = '  '.str_repeat('ABCDEFGH', 20_000).'@EXAMPLE.COM  ';
        $this->assertSame(strtolower(trim($email)), Invitation::normalizeEmailNative($email));
    }

    /**
     * @return array<string, array{0: bool, 1: int, 2: int, 3: bool}>
     */
    public static function invitationStateProvider(): array
    {
        $now = 1_784_548_800_123_456;

        return [
            'valid future' => [true, $now + 1, $now, false],
            'expires exactly now' => [true, $now, $now, false],
            'expired by one microsecond' => [true, $now - 1, $now, false],
            'inactive future' => [false, $now + 1_000_000, $now, false],
            'used future' => [true, $now + 1_000_000, $now, true],
            'used and expired' => [false, $now - 1_000_000, $now, true],
            'epoch zero' => [true, 0, 0, false],
            'negative timestamps' => [true, -5, -10, false],
            'extreme bounds' => [true, PHP_INT_MAX, PHP_INT_MIN, false],
            'extreme bounds reversed' => [true, PHP_INT_MIN, PHP_INT_MAX, true],
        ];
    }

    #[DataProvider('invitationStateProvider')]
    public function test_invitation_state_parity(bool $isActive, int $expiresAt, int $now, bool $hasUsedAt): void
    {
        $expected = [
            'is_valid' => $isActive && $expiresAt > $now && ! $hasUsedAt,
            'is_expired' => $expiresAt < $now,
            'is_used' => $hasUsedAt,
        ];
        $this->assertSame($expected, Invitation::evaluateInvitationStatePhp($isActive, $expiresAt, $now, $hasUsedAt));
        $this->assertSame($expected, Invitation::evaluateInvitationStateNative($isActive, $expiresAt, $now, $hasUsedAt));
        foreach ([true, false] as $toggle) {
            Invitation::setUseRustFfi($toggle);
            $this->assertSame($expected, Invitation::evaluateInvitationState($isActive, $expiresAt, $now, $hasUsedAt));
        }
    }

    public function test_invitation_model_state_matches_original_carbon_semantics(): void
    {
        $now = Carbon::parse('2026-07-20 12:00:00.250000', 'UTC');
        Carbon::setTestNow($now);

        $cases = [
            'future' => ['2026-07-20 12:00:01', true, null],
            'same second, earlier microseconds' => ['2026-07-20 12:00:00', true, null],
            'past' => ['2026-07-20 11:59:59', true, null],
            'far future inactive' => ['2027-01-01 00:00:00', false, null],
            'future used' => ['2026-08-01 00:00:00', true, '2026-07-19 08:00:00'],
            'past used inactive' => ['2026-07-01 00:00:00', false, '2026-06-30 08:00:00'],
            'inactive null attribute' => ['2026-07-21 00:00:00', null, null],
        ];

        foreach ($cases as $label => [$expiresAt, $isActive, $usedAt]) {
            $model = new Invitation;
            $model->setRawAttributes([
                'expires_at' => $expiresAt,
                'is_active' => $isActive,
                'used_at' => $usedAt,
            ], true);

            $expected = [
                'valid' => (bool) $model->is_active && $model->expires_at->isFuture() && $model->used_at === null,
                'expired' => $model->expires_at->isPast(),
                'used' => $model->used_at !== null,
            ];
            foreach ([false, true] as $toggle) {
                Invitation::setUseRustFfi($toggle);
                $this->assertSame($expected['valid'], $model->isValid(), "{$label} isValid toggle={$toggle}");
                $this->assertSame($expected['expired'], $model->isExpired(), "{$label} isExpired toggle={$toggle}");
                $this->assertSame($expected['used'], $model->isUsed(), "{$label} isUsed toggle={$toggle}");
            }
        }

        $model = new Invitation;
        $model->setRawAttributes(['expires_at' => '2026-07-20 12:00:00', 'is_active' => true, 'used_at' => null], true);
        $this->assertFalse($model->isValid());
        $this->assertTrue($model->isExpired(), 'Carbon isPast() is true when now has later microseconds in the same second');

        $micro = Carbon::parse('2026-07-20 12:00:00.000001', 'UTC');
        $this->assertSame(Invitation::toMicroTimestamp($micro) - 1, Invitation::toMicroTimestamp(Carbon::parse('2026-07-20 12:00:00', 'UTC')));
        $this->assertSame(
            Invitation::toMicroTimestamp(Carbon::parse('2026-07-20 14:00:00.5', 'Europe/Berlin')),
            Invitation::toMicroTimestamp(Carbon::parse('2026-07-20 12:00:00.5', 'UTC')),
        );
    }

    public function test_invitation_email_normalization_is_used_by_queries(): void
    {
        $this->useInvitationSqlite();
        Carbon::setTestNow('2026-07-20 12:00:00');
        DB::table('users')->insert(['id' => 1, 'username' => 'alice', 'email' => 'alice@example.com', 'invites' => 3]);

        foreach ([false, true] as $toggle) {
            Invitation::setUseRustFfi($toggle);
            $email = $toggle ? '  Native@Example.COM ' : "\tPhp@Example.COM\n";
            $created = Invitation::createInvitation($email, 1);
            $this->assertSame(strtolower(trim($email)), $created->email);
            $found = Invitation::findByEmail(strtoupper($email));
            $this->assertNotNull($found);
            $this->assertSame($created->id, $found->id);
            $this->assertTrue($found->isValid());
            $this->assertFalse($found->isExpired());
            $this->assertFalse($found->isUsed());
        }
    }

    // ---------------------------------------------------------------------
    // 4. RolePromotion
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{0: bool, 1: int|null, 2: int|null, 3: int}>
     */
    public static function promotionWindowProvider(): array
    {
        $now = 1_784_548_800_500_000;

        return [
            'inactive' => [false, null, null, $now],
            'active open window' => [true, null, null, $now],
            'starts later' => [true, $now + 1, null, $now],
            'starts now' => [true, $now, null, $now],
            'ended earlier' => [true, null, $now - 1, $now],
            'ends now' => [true, null, $now, $now],
            'inside window' => [true, $now - 10, $now + 10, $now],
            'inverted window' => [true, $now + 10, $now - 10, $now],
            'inactive inside window' => [false, $now - 10, $now + 10, $now],
            'zero bounds' => [true, 0, 0, 0],
            'extreme bounds' => [true, PHP_INT_MIN, PHP_INT_MAX, 0],
            'extreme now' => [true, PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MAX],
        ];
    }

    #[DataProvider('promotionWindowProvider')]
    public function test_promotion_active_window_parity(bool $isActive, ?int $start, ?int $end, int $now): void
    {
        $expected = $isActive && ($start === null || $now >= $start) && ($end === null || $now <= $end);
        $this->assertSame($expected, RolePromotion::evaluatePromotionActiveWindowPhp($isActive, $start, $end, $now));
        $this->assertSame($expected, RolePromotion::evaluatePromotionActiveWindowNative($isActive, $start, $end, $now));
        foreach ([true, false] as $toggle) {
            RolePromotion::setUseRustFfi($toggle);
            $this->assertSame($expected, RolePromotion::evaluatePromotionActiveWindow($isActive, $start, $end, $now));
        }
    }

    public function test_role_promotion_model_matches_original_carbon_semantics(): void
    {
        $cases = [
            'open window' => [true, null, null],
            'inactive' => [false, null, null],
            'starts tomorrow' => [true, '2026-07-21', null],
            'started today' => [true, '2026-07-20', null],
            'ends today (end date is midnight)' => [true, null, '2026-07-20'],
            'ends tomorrow' => [true, null, '2026-07-21'],
            'ended yesterday' => [true, '2026-07-01', '2026-07-19'],
            'null active flag' => [null, null, null],
        ];
        foreach (['2026-07-20 00:00:00.000000', '2026-07-20 00:00:00.000001', '2026-07-20 13:45:10.5'] as $nowString) {
            Carbon::setTestNow(Carbon::parse($nowString, 'UTC'));
            foreach ($cases as $label => [$isActive, $start, $end]) {
                $model = new RolePromotion;
                $model->setRawAttributes(['is_active' => $isActive, 'start_date' => $start, 'end_date' => $end], true);

                $now = Carbon::now();
                $expected = (bool) $model->is_active
                    && ! ($model->start_date && $now->lt($model->start_date))
                    && ! ($model->end_date && $now->gt($model->end_date));
                foreach ([false, true] as $toggle) {
                    RolePromotion::setUseRustFfi($toggle);
                    $this->assertSame($expected, $model->isCurrentlyActive(), "{$label} at {$nowString} toggle={$toggle}");
                }
            }
        }
    }

    public function test_system_role_names_parity(): void
    {
        $expected = ['Admin', 'User', 'Moderator', 'Disabled', 'Friend'];
        $this->assertSame($expected, RolePromotion::systemRoleNamesPhp());
        $this->assertSame($expected, RolePromotion::systemRoleNamesNative());
        foreach ([true, false] as $toggle) {
            RolePromotion::setUseRustFfi($toggle);
            $this->assertSame($expected, RolePromotion::systemRoleNames());
        }
    }

    // ---------------------------------------------------------------------
    // 5. UserActivityStat
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function hourlyLabelProvider(): array
    {
        return [
            'utc midday' => ['UTC', '2026-07-20 14:37:12.345678'],
            'utc just after midnight' => ['UTC', '2026-07-20 00:00:00.000001'],
            'new york spring forward' => ['America/New_York', '2026-03-08 03:30:00.5'],
            'new york week after spring forward' => ['America/New_York', '2026-03-15 02:30:00'],
            'new york fall back first 01:30' => ['America/New_York', '2026-11-01 05:30:00'],
            'new york fall back second 01:30' => ['America/New_York', '2026-11-01 06:30:00'],
            'new york week after fall back' => ['America/New_York', '2026-11-08 01:15:00'],
            'berlin end of dst' => ['Europe/Berlin', '2026-10-25 02:30:00'],
            'kathmandu offset' => ['Asia/Kathmandu', '2026-01-01 00:10:00'],
            'chatham offset' => ['Pacific/Chatham', '2026-04-05 03:00:00'],
            'pre epoch' => ['America/Los_Angeles', '1969-12-31 18:00:00'],
        ];
    }

    #[DataProvider('hourlyLabelProvider')]
    public function test_hourly_label_parity_with_original_carbon_logic(string $timezone, string $utcNow): void
    {
        date_default_timezone_set($timezone);
        Carbon::setTestNow(Carbon::parse($utcNow, 'UTC')->setTimezone($timezone));

        for ($i = 199; $i >= 0; $i--) {
            $time = Carbon::now()->subHours($i)->startOfHour();
            $now = Carbon::now();
            $expected = self::legacyHourlyLabel($time, $now);
            $args = [
                $time->getTimestamp() + $time->getOffset(),
                $now->getTimestamp() + $now->getOffset(),
                $time->format('H:i'),
                $time->format('D H:i'),
                $time->format('M d H:i'),
            ];

            $this->assertSame($expected, UserActivityStat::formatHourlyStatLabelPhp(...$args), "php {$timezone} {$time->toIso8601String()}");
            $this->assertSame($expected, UserActivityStat::formatHourlyStatLabelNative(...$args), "rust {$timezone} {$time->toIso8601String()}");
            foreach ([true, false] as $toggle) {
                UserActivityStat::setUseRustFfi($toggle);
                $this->assertSame($expected, UserActivityStat::formatHourlyStatLabel(...$args));
            }
        }
    }

    public function test_hourly_label_edge_inputs(): void
    {
        $day = 86400;
        $cases = [
            [0, 0, '00:00'],
            [-1, -1, '23:59'],
            [-$day, -1, '00:00'],
            [-$day - 1, -1, 'Yesterday 23:59'],
            [5 * $day, 0, 'D 00:00'],
            [PHP_INT_MIN, PHP_INT_MAX, 'M 00:00'],
            [PHP_INT_MAX, PHP_INT_MIN, 'D 00:00'],
            [0, 7 * $day - 1, 'D 00:00'],
            [0, 7 * $day, 'M 00:00'],
        ];
        foreach ($cases as [$hour, $now, $label]) {
            $timeHm = substr($label, -5);
            $expected = str_starts_with($label, 'D ') ? 'Tue '.$timeHm : (str_starts_with($label, 'M ') ? 'Jan 01 '.$timeHm : $label);
            $args = [$hour, $now, $timeHm, 'Tue '.$timeHm, 'Jan 01 '.$timeHm];
            if ($hour === PHP_INT_MIN) {
                // Wall-clock difference overflows int64; Rust declines and PHP's float comparison decides.
                $this->assertSame(UserActivityStat::formatHourlyStatLabelPhp(...$args), UserActivityStat::formatHourlyStatLabelNative(...$args));

                continue;
            }
            $this->assertSame($expected, UserActivityStat::formatHourlyStatLabelPhp(...$args));
            $this->assertSame($expected, UserActivityStat::formatHourlyStatLabelNative(...$args));
        }

        foreach (['', "nul\0inside", str_repeat('Ü', 300)] as $text) {
            $args = [0, 0, $text, 'unused', 'unused'];
            $this->assertSame($text, UserActivityStat::formatHourlyStatLabelPhp(...$args));
            $this->assertSame($text, UserActivityStat::formatHourlyStatLabelNative(...$args));
            $args = [-1, 0, $text, 'unused', 'unused'];
            $this->assertSame('Yesterday '.$text, UserActivityStat::formatHourlyStatLabelNative(...$args));
        }
    }

    // ---------------------------------------------------------------------
    // C-ABI Contract & Fallback Verification
    // ---------------------------------------------------------------------

    private static function ffiCall(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private static function fillSentinel(CData $buffer, int $length): void
    {
        for ($i = 0; $i < $length; $i++) {
            $buffer[$i] = 'Z';
        }
    }

    public function test_c_abi_null_unaligned_overflow_and_buffer_contracts(): void
    {
        $combinedCdef = implode("\n", [
            UserStatsService::FFI_CDEF,
            InvitationService::FFI_CDEF,
            Invitation::FFI_CDEF,
            RolePromotion::FFI_CDEF,
            UserActivityStat::FFI_CDEF,
        ]);
        $ffi = RustPipelineLibrary::resolveDomain('metadata', $combinedCdef);
        $this->assertNotNull($ffi);

        $small = $ffi->new('char[8]');
        self::fillSentinel($small, 8);
        $big = $ffi->new('char[256]');

        // Minute bucket: success, short buffer (no partial write), null input/output.
        $this->assertSame(\strlen(self::SQLITE_BUCKET), self::ffiCall($ffi, 'nntmux_user_stats_minute_bucket_expression', 'sqlite', 6, $big, 256));
        $this->assertSame(self::SQLITE_BUCKET, FFI::string($big, \strlen(self::SQLITE_BUCKET)));
        $this->assertSame(-\strlen(self::MYSQL_BUCKET), self::ffiCall($ffi, 'nntmux_user_stats_minute_bucket_expression', 'mysql', 5, $small, 8));
        $this->assertSame('ZZZZZZZZ', FFI::string($small, 8));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_user_stats_minute_bucket_expression', null, 6, $big, 256));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_user_stats_minute_bucket_expression', 'sqlite', 6, null, 256));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_user_stats_minute_bucket_expression', 'sqlite', PHP_INT_MAX, $big, 256));

        // Summary totals: typed output must be non-null, aligned, and untouched on overflow.
        $raw = $ffi->new('uint8_t[64]');
        $unalignedI64 = $ffi->cast('int64_t*', FFI::addr($raw[1]));
        $totals = $ffi->new('int64_t[5]');
        for ($i = 0; $i < 5; $i++) {
            $totals[$i] = 777;
        }
        $this->assertSame(1, self::ffiCall($ffi, 'nntmux_user_stats_summary_totals', 1, 2, 3, 4, 5, 6, 7, $totals));
        $this->assertSame([1, 8, 9, 11, 12], [$totals[0], $totals[1], $totals[2], $totals[3], $totals[4]]);
        for ($i = 0; $i < 5; $i++) {
            $totals[$i] = 777;
        }
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_user_stats_summary_totals', 0, PHP_INT_MAX, 0, 0, 0, 1, 0, $totals));
        $this->assertSame([777, 777, 777, 777, 777], [$totals[0], $totals[1], $totals[2], $totals[3], $totals[4]]);
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_user_stats_summary_totals', 0, 0, 0, 0, 0, 0, 0, null));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_user_stats_summary_totals', 0, 0, 0, 0, 0, 0, 0, $unalignedI64));
        for ($i = 0; $i < 64; $i++) {
            $this->assertSame(0, $raw[$i]);
        }

        // Invitation availability.
        $available = $ffi->new('int64_t[1]');
        $available[0] = 555;
        $this->assertSame(1, self::ffiCall($ffi, 'nntmux_invitation_availability', 5, 2, $available));
        $this->assertSame(3, $available[0]);
        $this->assertSame(0, self::ffiCall($ffi, 'nntmux_invitation_availability', 1, 4, $available));
        $this->assertSame(-3, $available[0]);
        $available[0] = 555;
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_invitation_availability', PHP_INT_MIN, 1, $available));
        $this->assertSame(555, $available[0]);
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_invitation_availability', 1, 0, null));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_invitation_availability', 1, 0, $unalignedI64));

        // Email normalization: short buffer reports the trimmed length, one-byte need reports -2.
        self::fillSentinel($small, 8);
        $this->assertSame(-11, self::ffiCall($ffi, 'nntmux_invitation_normalize_email', '  ABCDEFGHIJK  ', 15, $small, 8));
        $this->assertSame('ZZZZZZZZ', FFI::string($small, 8));
        $this->assertSame(-2, self::ffiCall($ffi, 'nntmux_invitation_normalize_email', ' A ', 3, $small, 0));
        $this->assertSame('ZZZZZZZZ', FFI::string($small, 8));
        $this->assertSame(1, self::ffiCall($ffi, 'nntmux_invitation_normalize_email', ' A ', 3, $small, 8));
        $this->assertSame('a', FFI::string($small, 1));
        $this->assertSame(0, self::ffiCall($ffi, 'nntmux_invitation_normalize_email', " \0\t", 3, $small, 8));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_invitation_normalize_email', null, 3, $small, 8));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_invitation_normalize_email', 'a@b', 3, null, 8));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_invitation_normalize_email', 'a@b', PHP_INT_MAX, $small, 8));

        // Invitation state and promotion window reject non-boolean flags.
        $this->assertSame(Invitation::STATE_VALID, self::ffiCall($ffi, 'nntmux_invitation_state', 1, 10, 5, 0));
        $this->assertSame(Invitation::STATE_EXPIRED | Invitation::STATE_USED, self::ffiCall($ffi, 'nntmux_invitation_state', 1, 4, 5, 1));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_invitation_state', 2, 10, 5, 0));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_invitation_state', 1, 10, 5, -1));
        $this->assertSame(1, self::ffiCall($ffi, 'nntmux_role_promotion_active_window', 1, 1, 5, 1, 5, 5));
        $this->assertSame(0, self::ffiCall($ffi, 'nntmux_role_promotion_active_window', 1, 1, 6, 0, 0, 5));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_role_promotion_active_window', 7, 0, 0, 0, 0, 5));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_role_promotion_active_window', 1, -1, 0, 0, 0, 5));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_role_promotion_active_window', 1, 0, 0, 2, 0, 5));

        // System roles.
        $roles = "Admin\nUser\nModerator\nDisabled\nFriend";
        self::fillSentinel($small, 8);
        $this->assertSame(-\strlen($roles), self::ffiCall($ffi, 'nntmux_role_promotion_system_roles', $small, 8));
        $this->assertSame('ZZZZZZZZ', FFI::string($small, 8));
        $this->assertSame(\strlen($roles), self::ffiCall($ffi, 'nntmux_role_promotion_system_roles', $big, 256));
        $this->assertSame($roles, FFI::string($big, \strlen($roles)));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_role_promotion_system_roles', null, 256));

        // Hourly label.
        $this->assertSame(15, self::ffiCall($ffi, 'nntmux_user_activity_hourly_label', -1, 0, '10:00', 5, 'Tue 10:00', 9, 'Jan 01 10:00', 12, $big, 256));
        $this->assertSame('Yesterday 10:00', FFI::string($big, 15));
        self::fillSentinel($small, 8);
        $this->assertSame(-15, self::ffiCall($ffi, 'nntmux_user_activity_hourly_label', -1, 0, '10:00', 5, 'Tue 10:00', 9, 'Jan 01 10:00', 12, $small, 8));
        $this->assertSame('ZZZZZZZZ', FFI::string($small, 8));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_user_activity_hourly_label', PHP_INT_MIN, PHP_INT_MAX, '10:00', 5, 'Tue 10:00', 9, 'Jan 01 10:00', 12, $big, 256));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_user_activity_hourly_label', 0, 0, null, 5, 'Tue 10:00', 9, 'Jan 01 10:00', 12, $big, 256));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_user_activity_hourly_label', 0, 0, '10:00', 5, null, 9, 'Jan 01 10:00', 12, $big, 256));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_user_activity_hourly_label', 0, 0, '10:00', 5, 'Tue 10:00', 9, null, 12, $big, 256));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_user_activity_hourly_label', 0, 0, '10:00', 5, 'Tue 10:00', 9, 'Jan 01 10:00', 12, null, 256));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_user_activity_hourly_label', 0, 0, '10:00', PHP_INT_MAX, 'Tue 10:00', 9, 'Jan 01 10:00', 12, $big, 256));
    }

    public function test_native_methods_fall_back_to_php_when_ffi_unavailable(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::setFfiForTesting(false);
            $class::enableRustFfi();
        }

        $this->assertSame(self::SQLITE_BUCKET, UserStatsService::resolveMinuteBucketExpressionNative('sqlite'));
        $this->assertSame(self::MYSQL_BUCKET, UserStatsService::resolveMinuteBucketExpression('mysql'));
        $this->assertSame(
            UserStatsService::computeSummaryTotalsPhp(1, 2, 3, 4, 5, 6, 7),
            UserStatsService::computeSummaryTotalsNative(1, 2, 3, 4, 5, 6, 7),
        );
        $this->assertSame(
            InvitationService::calculateInviteAvailabilityPhp(3, 1, 1, 1, 1),
            InvitationService::calculateInviteAvailabilityNative(3, 1, 1, 1, 1),
        );
        $this->assertSame('user@example.com', Invitation::normalizeEmailNative('  USER@Example.com '));
        $this->assertSame(
            Invitation::evaluateInvitationStatePhp(true, 10, 5, false),
            Invitation::evaluateInvitationStateNative(true, 10, 5, false),
        );
        $this->assertTrue(RolePromotion::evaluatePromotionActiveWindowNative(true, 1, 10, 5));
        $this->assertFalse(RolePromotion::evaluatePromotionActiveWindowNative(true, 6, null, 5));
        $this->assertSame(RolePromotion::systemRoleNamesPhp(), RolePromotion::systemRoleNamesNative());
        $this->assertSame('Yesterday 10:00', UserActivityStat::formatHourlyStatLabelNative(-1, 0, '10:00', 'Tue 10:00', 'Jan 01 10:00'));
    }

    // ---------------------------------------------------------------------
    // Paired Randomized Benchmarks
    // ---------------------------------------------------------------------

    public function test_paired_randomized_benchmarks(): void
    {
        if (getenv('NNTMUX_USER_STATS_INVITATION_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_USER_STATS_INVITATION_BENCH=1 to run paired Rust/PHP benchmarks.');
        }

        $nowUs = 1_784_548_800_250_000;
        $nowLocal = 1_784_548_800;
        $longEmail = "  \t".str_repeat('Long.Mixed.Case.Segment.', 8).'@Sub.Domain.EXAMPLE.COM'."\n  ";

        $workloads = [
            'UserStatsService::resolveMinuteBucketExpression' => [
                UserStatsService::class,
                static fn () => UserStatsService::resolveMinuteBucketExpression('mysql'),
                static fn () => UserStatsService::resolveMinuteBucketExpression('sqlite'),
            ],
            'UserStatsService::computeSummaryTotals' => [
                UserStatsService::class,
                static fn () => UserStatsService::computeSummaryTotals(1250, 340, 2875, 9100, 61200, 12, 85),
                static fn () => UserStatsService::computeSummaryTotals(PHP_INT_MAX, 9_000_000_000, 63_000_000_000, 4_000_000_000, 28_000_000_000, 750_000, 8_000_000),
            ],
            'InvitationService::calculateInviteAvailability' => [
                InvitationService::class,
                static fn () => InvitationService::calculateInviteAvailability(1, 0, 0, 0, 0),
                static fn () => InvitationService::calculateInviteAvailability(25, 40, 1200, 75, 30),
            ],
            'Invitation::normalizeEmail' => [
                Invitation::class,
                static fn () => Invitation::normalizeEmail('John.Doe@Example.com'),
                static fn () => Invitation::normalizeEmail($longEmail),
            ],
            'Invitation::evaluateInvitationState' => [
                Invitation::class,
                static fn () => Invitation::evaluateInvitationState(true, $nowUs + 86_400_000_000, $nowUs, false),
                static fn () => Invitation::evaluateInvitationState(false, $nowUs - 1, $nowUs, true),
            ],
            'RolePromotion::evaluatePromotionActiveWindow' => [
                RolePromotion::class,
                static fn () => RolePromotion::evaluatePromotionActiveWindow(true, null, null, $nowUs),
                static fn () => RolePromotion::evaluatePromotionActiveWindow(true, $nowUs - 1_000_000, $nowUs + 1_000_000, $nowUs),
            ],
            'RolePromotion::systemRoleNames' => [
                RolePromotion::class,
                static fn () => RolePromotion::systemRoleNames(),
                static fn () => RolePromotion::systemRoleNames(),
            ],
            'UserActivityStat::formatHourlyStatLabel' => [
                UserActivityStat::class,
                static fn () => UserActivityStat::formatHourlyStatLabel($nowLocal - 3600, $nowLocal, '13:00', 'Mon 13:00', 'Jul 20 13:00'),
                static fn () => UserActivityStat::formatHourlyStatLabel($nowLocal - 86_400 * 3 - 7200, $nowLocal, '12:00', 'Fri 12:00', 'Jul 17 12:00'),
            ],
        ];

        $rows = [];
        foreach ($workloads as $name => [$class, $common, $uncommon]) {
            $worst = ['common_mean' => INF, 'common_p99' => INF, 'uncommon_mean' => INF, 'uncommon_p99' => INF];
            for ($rep = 0; $rep < 3; $rep++) {
                foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $fn) {
                    $class::enableRustFfi();
                    $rustOut = self::invoke($fn);
                    $class::disableRustFfi();
                    $this->assertSame(self::invoke($fn), $rustOut, "{$name} {$kind} benchmark output parity");

                    $stats = $this->runPairedBenchmark($class, $fn, 200, 41, 2303 + $rep);
                    fwrite(STDERR, json_encode(['method' => $name, 'workload' => $kind, 'repetition' => $rep] + $stats).PHP_EOL);
                    $worst["{$kind}_mean"] = min($worst["{$kind}_mean"], $stats['mean_speedup']);
                    $worst["{$kind}_p99"] = min($worst["{$kind}_p99"], $stats['p99_speedup']);
                }
            }
            $class::resetRustFfi();
            $qualified = min($worst) >= 1.5;
            $rows[] = sprintf(
                ' * | %-46s | %.2fx / %.2fx       | %.2fx / %.2fx         | %-7s | %-11s |',
                $name,
                $worst['common_mean'],
                $worst['common_p99'],
                $worst['uncommon_mean'],
                $worst['uncommon_p99'],
                $qualified ? 'Yes' : 'No',
                $qualified ? 'Rust (true)' : 'PHP (false)',
            );
            if ($class::usesRustFfi()) {
                $this->assertTrue($qualified, "{$name} defaults to Rust but misses the 1.5x gate");
            }
        }

        fwrite(STDERR, implode(PHP_EOL, $rows).PHP_EOL);
    }

    private static function invoke(Closure $fn): mixed
    {
        return $fn();
    }

    /**
     * @param  class-string  $class
     * @return array{rust_mean_us: float, php_mean_us: float, rust_p99_us: float, php_p99_us: float, mean_speedup: float, p99_speedup: float, samples: int, inner_loops: int, warmup: int}
     */
    private function runPairedBenchmark(string $class, Closure $fn, int $innerLoops, int $samples, int $seed): array
    {
        $warmup = 50;
        foreach ([true, false] as $toggle) {
            $class::setUseRustFfi($toggle);
            for ($w = 0; $w < $warmup; $w++) {
                $fn();
            }
        }

        $times = [1 => [], 0 => []];
        mt_srand($seed);
        for ($s = 0; $s < $samples; $s++) {
            $order = mt_rand(0, 1) === 0 ? [true, false] : [false, true];
            foreach ($order as $toggle) {
                $class::setUseRustFfi($toggle);
                $t0 = hrtime(true);
                for ($i = 0; $i < $innerLoops; $i++) {
                    $fn();
                }
                $times[(int) $toggle][] = (hrtime(true) - $t0) / 1000.0 / $innerLoops;
            }
        }

        $rust = $times[1];
        $php = $times[0];
        sort($rust);
        sort($php);
        $p99 = (int) floor((\count($rust) - 1) * 0.99);
        $rustMean = array_sum($rust) / \count($rust);
        $phpMean = array_sum($php) / \count($php);

        return [
            'rust_mean_us' => $rustMean,
            'php_mean_us' => $phpMean,
            'rust_p99_us' => $rust[$p99],
            'php_p99_us' => $php[$p99],
            'mean_speedup' => $phpMean / $rustMean,
            'p99_speedup' => $php[$p99] / $rust[$p99],
            'samples' => $samples,
            'inner_loops' => $innerLoops,
            'warmup' => $warmup,
        ];
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Pre-extraction `UserActivityStat` label branch, kept verbatim as the parity oracle.
     */
    private static function legacyHourlyLabel(Carbon $time, Carbon $now): string
    {
        if ($time->isToday()) {
            return $time->format('H:i');
        }
        if ($time->isYesterday()) {
            return 'Yesterday '.$time->format('H:i');
        }
        if ($time->diffInDays($now) < 7) {
            return $time->format('D H:i');
        }

        return $time->format('M d H:i');
    }

    private function useSqliteMemory(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
        ]);
        DB::purge();
        DB::reconnect();
        Cache::flush();
    }

    private function useStatsSqlite(): void
    {
        $this->useSqliteMemory();
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->dateTime('deleted_at')->nullable();
        });
        Schema::create('user_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('users_id');
            $table->text('request');
            $table->dateTime('timestamp');
        });
        Schema::create('user_downloads', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('users_id');
            $table->dateTime('timestamp');
        });
        Schema::create('user_activity_stats_hourly', function (Blueprint $table): void {
            $table->id();
            $table->dateTime('stat_hour')->unique();
            $table->integer('downloads_count')->default(0);
            $table->integer('api_hits_count')->default(0);
            $table->timestamps();
        });
    }

    private function useInvitationSqlite(): void
    {
        $this->useSqliteMemory();
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('username');
            $table->string('email');
            $table->integer('invites')->default(0);
            $table->dateTime('deleted_at')->nullable();
            $table->timestamps();
        });
        Schema::create('invitations', function (Blueprint $table): void {
            $table->id();
            $table->string('token', 64)->unique();
            $table->string('email');
            $table->unsignedBigInteger('invited_by');
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();
            $table->unsignedBigInteger('used_by')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }
}
