<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\Admin\AdminInvitationController as Invitation;
use App\Http\Controllers\Admin\AdminPromotionController as Promotion;
use App\Http\Controllers\Admin\AdminRegistrationController as Registration;
use App\Http\Controllers\Admin\AdminUserRoleHistoryController as History;
use App\Http\Controllers\Admin\GdprRequestController as Gdpr;
use App\Http\Controllers\BasePageController;
use App\Http\Requests\Admin\AdminPromotionRequest;
use App\Http\Requests\Admin\UpdateRegistrationStatusRequest;
use App\Models\GdprAuditLog;
use App\Models\GdprRequest;
use App\Services\Gdpr\GdprAuditService;
use App\Services\Gdpr\GdprErasureService;
use App\Services\Gdpr\GdprExportService;
use App\Services\Gdpr\GdprNotificationService;
use App\Services\Gdpr\GdprRetentionService;
use App\Services\InvitationService;
use App\Services\RegistrationFailureLogService;
use App\Services\RegistrationStatusService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Mockery;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Paired release screen: 3 rounds x 1001 individual-call pairs per fixture,
 * 51 warmups, seeded randomized order, identical common/uncommon inputs.
 * Includes controller dispatch, byte framing, FFI, allocation and decoding.
 * Isolated real handler screens include SQLite or mocked service boundaries;
 * they exclude network, view rendering and production service throughput.
 * Raw samples, failures, source/library/tool identities and commands go to /tmp.
 * Adoption requires each common mean and uncommon p99 speedup >= 1.5x.
 */
class RustAdminGdprRegPromoInviteHistoryControllersParityTest extends TestCase
{
    private const CLASSES = [Gdpr::class, Registration::class, Promotion::class, Invitation::class, History::class];

    /** @return list<array{class-string, string, int, list<mixed>, array<string, mixed>, mixed}> */
    private static function cases(): array
    {
        return [
            [Gdpr::class, 'normalizeFilters', 0, [" export\t", ' pending '], ['type' => " export\t", 'status' => ' pending '], ['type' => 'export', 'status' => 'pending']],
            [Gdpr::class, 'openRequest', 1, ['processing'], ['status' => 'processing'], true],
            [Gdpr::class, 'eligibilityError', 2, ['erasure', 'pending', 'erasure', true, true], ['type' => 'erasure', 'status' => 'pending', 'action' => 'erasure', 'subject_exists' => true, 'is_admin' => true], 'Admin accounts cannot be erased from the GDPR request queue.'],
            [Gdpr::class, 'rejectionPayload', 3, ["private\0\xff"], ['reason' => "private\0\xff"], ['rejected' => true, 'reason' => "private\0\xff"]],
            [Registration::class, 'partitionPeriods', 4, [[['ended' => false, 'starts_at' => null, 'ends_at' => null], ['ended' => true, 'starts_at' => 1, 'ends_at' => 9], ['ended' => false, 'starts_at' => 4, 'ends_at' => 6], ['ended' => false, 'starts_at' => 4, 'ends_at' => 5], ['ended' => true, 'starts_at' => 0, 'ends_at' => 10]]], ['periods' => [['ended' => false, 'starts_at' => null, 'ends_at' => null], ['ended' => true, 'starts_at' => 1, 'ends_at' => 9], ['ended' => false, 'starts_at' => 4, 'ends_at' => 6], ['ended' => false, 'starts_at' => 4, 'ends_at' => 5], ['ended' => true, 'starts_at' => 0, 'ends_at' => 10]]], ['current' => [2, 3, 0], 'past' => [4, 1]]],
            [Registration::class, 'validActivityMetadata', 5, [['email' => '', 'ip_address' => false]], ['metadata' => ['email' => '', 'ip_address' => false]], true],
            [Registration::class, 'enrichFailedAttempt', 6, [['registration_status' => '1e0', 'manual_registration_status' => 'bad', 'extra' => false]], ['entry' => ['registration_status' => '1e0', 'manual_registration_status' => 'bad', 'extra' => false], 'statuses' => [1, null]], ['registration_status' => '1e0', 'manual_registration_status' => 'bad', 'extra' => false, 'registration_status_label' => 'Invite', 'manual_registration_status_label' => null]],
            [Registration::class, 'manualStatusMessage', 7, [1, 1], ['old_status' => 1, 'new_status' => 1], 'Registration status note saved without changing the manual mode.'],
            [Promotion::class, 'promotionAttributes', 8, [['name' => 'Offer', 'applicable_roles' => null, 'ignored' => 1], false], ['input' => ['name' => 'Offer', 'applicable_roles' => null, 'ignored' => 1], 'is_active' => false], ['name' => 'Offer', 'description' => null, 'applicable_roles' => null, 'additional_days' => null, 'start_date' => null, 'end_date' => null, 'is_active' => false]],
            [Promotion::class, 'toggleStatusLabel', 9, [true], ['active' => true], 'activated'],
            [Promotion::class, 'overallStatistics', 10, [[['is_active' => true, 'statistics_count' => 3], ['is_active' => false, 'statistics_count' => 8]]], ['promotions' => [['is_active' => true, 'statistics_count' => 3], ['is_active' => false, 'statistics_count' => 8]]], ['total_promotions' => 2, 'active_promotions' => 1, 'total_applications' => 11]],
            [Invitation::class, 'invitationFilters', 11, [['status' => null, 'ob' => 'email_asc', 'email' => false]], ['input' => ['status' => null, 'ob' => 'email_asc', 'email' => false]], ['status' => '', 'invited_by' => '', 'email' => '', 'orderBy' => 'email_asc', 'column' => 'email', 'direction' => 'asc']],
            [Invitation::class, 'statusOptions', 12, [], [], ['' => 'All', 'pending' => 'Pending', 'used' => 'Used', 'expired' => 'Expired', 'cancelled' => 'Cancelled']],
            [Invitation::class, 'bulkActionState', 13, ['delete', [1, 2], 2], ['action' => 'delete', 'ids' => [1, 2], 'count' => 2], ['error' => null, 'message' => 'Deleted 2 invitations']],
            [History::class, 'normalizeFilters', 14, [['user_id' => '0', 'username' => ['invalid'], 'role_id' => 2]], ['input' => ['user_id' => '0', 'username' => ['invalid'], 'role_id' => 2]], ['user_id' => '0', 'username' => '', 'role_id' => '2', 'change_reason' => '', 'date_from' => '', 'date_to' => '']],
            [History::class, 'dateBoundaries', 15, ['2026-10-24', '2026-10-25'], ['date_from' => '2026-10-24', 'date_to' => '2026-10-25'], ['date_from' => '2026-10-24 00:00:00', 'date_to' => '2026-10-25 23:59:59']],
        ];
    }

    public function test_php_contracts_and_nullable_controller_switches(): void
    {
        foreach (self::cases() as [$class, $method, , $args, , $expected]) {
            self::assertSame($expected, $class::{$method.'Php'}(...$args));
        }
        BasePageController::setUseRustFfi(true);
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(null);
            self::assertFalse($class::usesRustFfi());
        }
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(true);
            foreach (self::CLASSES as $other) {
                self::assertSame($class === $other, $other::usesRustFfi());
            }
            $class::setUseRustFfi(null);
        }
        self::assertTrue(BasePageController::usesRustFfi());
    }

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(null);
        }
        BasePageController::setUseRustFfi(null);
        (new ReflectionProperty(Gdpr::class, 'wave29Ffi'))->setValue(null, null);
        Carbon::setTestNow();
        DB::purge('wave29_parity');
        parent::tearDown();
    }

    private function native(): FFI
    {
        $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
        self::assertIsString($path, 'Provide the requested release library.');
        $ffi = RustPipelineLibrary::resolveDomain('wave29-parity-test', Gdpr::FFI_CDEF, $path);
        self::assertNotNull($ffi, 'Native parity must exercise Rust rather than PHP fallback.');

        return $ffi;
    }

    private static function envelope(int $operation, mixed $expected): mixed
    {
        return match ($operation) {
            1 => ['open' => $expected],
            2 => ['error' => $expected],
            5 => ['valid' => $expected],
            7 => ['message' => $expected],
            9 => ['label' => $expected],
            default => $expected,
        };
    }

    public function test_every_operation_reaches_rust_and_matches_php_and_dispatch(): void
    {
        $this->native();
        foreach (self::cases() as [$class, $method, $operation, $args, $wire, $expected]) {
            self::assertSame(self::envelope($operation, $expected), Gdpr::wave29Native($operation, $wire), "Rust operation {$operation}");
            self::assertSame($expected, $class::{$method.'Native'}(...$args));
            foreach ([false, true, null] as $enabled) {
                $class::setUseRustFfi($enabled);
                self::assertSame($expected, $class::$method(...$args));
            }
        }
    }

    public function test_gdpr_whitelists_state_and_eligibility_error_precedence(): void
    {
        $this->native();
        foreach (['export', 'erasure', 'rectification', 'restriction', '', '0', 'EXPORT', "export\0x"] as $type) {
            foreach (['pending', 'processing', 'completed', 'rejected', 'cancelled', 'unknown', ''] as $status) {
                self::assertSame(Gdpr::normalizeFiltersPhp("\t{$type}\0", " {$status}\r"), Gdpr::wave29Native(0, ['type' => "\t{$type}\0", 'status' => " {$status}\r"]));
                self::assertSame(['open' => in_array($status, ['pending', 'processing'], true)], Gdpr::wave29Native(1, ['status' => $status]));
                foreach (['export', 'erasure'] as $action) {
                    foreach ([false, true] as $subjectExists) {
                        foreach ([false, true] as $admin) {
                            self::assertSame(['error' => Gdpr::eligibilityErrorPhp($type, $status, $action, $subjectExists, $admin)], Gdpr::wave29Native(2, ['type' => $type, 'status' => $status, 'action' => $action, 'subject_exists' => $subjectExists, 'is_admin' => $admin]));
                        }
                    }
                }
            }
        }
        self::assertNull(Gdpr::wave29Native(2, ['type' => 'export', 'status' => 'pending', 'action' => 'unknown', 'subject_exists' => true, 'is_admin' => false]));
    }

    public function test_scalar_filter_sort_metadata_and_payload_edge_cases(): void
    {
        $this->native();
        $values = [null, '', '0', 0, 1, -1, false, true, [], ['x'], " \0\xff", 1.25, 1e20];
        foreach ($values as $value) {
            $input = array_fill_keys(['user_id', 'username', 'role_id', 'change_reason', 'date_from', 'date_to', 'status', 'email', 'invited_by', 'ob'], $value);
            self::assertSame(History::normalizeFiltersPhp($input), History::normalizeFiltersNative($input));
            self::assertSame(Invitation::invitationFiltersPhp($input), Invitation::invitationFiltersNative($input));
            self::assertSame(Gdpr::rejectionPayloadPhp($value), Gdpr::wave29Native(3, ['reason' => $value]));
            self::assertSame(Registration::validActivityMetadataPhp($value), Registration::validActivityMetadataNative($value));
            foreach (['email', 'ip_address'] as $key) {
                $metadata = array_replace(['email' => '', 'ip_address' => '0'], [$key => $value]);
                self::assertSame(['valid' => isset($metadata['email'], $metadata['ip_address'])], Gdpr::wave29Native(5, ['metadata' => $metadata]));
            }
            $entry = ['registration_status' => $value, 'manual_registration_status' => $value, 'registration_status_label' => 'stale'];
            if (is_float($value) && abs($value) > PHP_INT_MAX) {
                $errors = [];
                foreach (['enrichFailedAttemptPhp', 'enrichFailedAttemptNative'] as $method) {
                    try {
                        Registration::$method($entry);
                        self::fail('An out-of-range status cast must retain PHP 8.5 errors.');
                    } catch (\Throwable $error) {
                        $errors[] = $error->getMessage();
                    }
                }
                self::assertCount(2, $errors);
                self::assertSame($errors[0], $errors[1]);
                self::assertStringContainsString('not representable as an int', $errors[0]);
            } else {
                self::assertSame(Registration::enrichFailedAttemptPhp($entry), Registration::enrichFailedAttemptNative($entry));
            }
            foreach (['cancel', 'resend', 'delete', 'invalid', true, false, null, 0, []] as $action) {
                self::assertSame(Invitation::bulkActionStatePhp($action, $value, 23), Invitation::bulkActionStateNative($action, $value, 23));
            }
            foreach ([false, true] as $active) {
                self::assertSame(Promotion::promotionAttributesPhp(['applicable_roles' => $value, 'name' => $value], $active), Gdpr::wave29Native(8, ['input' => ['applicable_roles' => $value, 'name' => $value], 'is_active' => $active]));
            }
        }
        foreach (['created_at', 'expires_at', 'email'] as $column) {
            foreach (['asc', 'desc'] as $direction) {
                $actual = Gdpr::wave29Native(11, ['input' => ['ob' => "{$column}_{$direction}"]]);
                self::assertSame($column, $actual['column']);
                self::assertSame($direction, $actual['direction']);
            }
        }
        foreach ([['', ''], ['0', 'invalid'], ['2026-02-28', '2026-03-01']] as [$from, $to]) {
            self::assertSame(History::dateBoundariesPhp($from, $to), Gdpr::wave29Native(15, ['date_from' => $from, 'date_to' => $to]));
        }
        foreach ([-1, 0, 1, 2, PHP_INT_MAX] as $old) {
            self::assertSame(Registration::manualStatusMessagePhp($old, 1), Registration::manualStatusMessageNative($old, 1));
        }
        self::assertSame(['name' => null, 'description' => null, 'applicable_roles' => [], 'additional_days' => null, 'start_date' => null, 'end_date' => null, 'is_active' => true], Promotion::promotionAttributesNative([], true));
        self::assertSame('deactivated', Promotion::toggleStatusLabelNative(false));
    }

    public function test_stable_period_ordering_empty_statistics_and_large_buffer_retry(): void
    {
        $this->native();
        $random = new Randomizer(new Mt19937(291));
        $periods = [];
        foreach (range(0, 127) as $i) {
            $periods[] = ['ended' => $i % 3 === 0, 'starts_at' => $i % 7 === 0 ? null : $random->getInt(-3, 3), 'ends_at' => $i % 9 === 0 ? null : $random->getInt(-3, 3)];
        }
        self::assertSame(Registration::partitionPeriodsPhp($periods), Gdpr::wave29Native(4, ['periods' => $periods]));
        self::assertSame(['current' => [], 'past' => []], Registration::partitionPeriodsNative([]));
        self::assertSame(['total_promotions' => 0, 'active_promotions' => 0, 'total_applications' => 0], Promotion::overallStatisticsNative([]));
        $overflow = [['is_active' => true, 'statistics_count' => PHP_INT_MAX], ['is_active' => false, 'statistics_count' => 1]];
        self::assertNull(Gdpr::wave29Native(10, ['promotions' => $overflow]));
        self::assertSame(Promotion::overallStatisticsPhp($overflow), Promotion::overallStatisticsNative($overflow));
        $reason = str_repeat("reason\0\xff", 100000);
        self::assertSame(['rejected' => true, 'reason' => $reason], Gdpr::wave29Native(3, ['reason' => $reason]));
    }

    public function test_missing_library_symbol_errors_and_unsupported_nodes_fall_back(): void
    {
        $this->native();
        foreach ([false, FFI::cdef('int abs(int value);')] as $unavailable) {
            (new ReflectionProperty(Gdpr::class, 'wave29Ffi'))->setValue(null, $unavailable);
            foreach (self::cases() as [$class, $method, , $args, , $expected]) {
                $class::setUseRustFfi(true);
                self::assertSame($expected, $class::{$method.'Native'}(...$args));
                self::assertSame($expected, $class::$method(...$args));
            }
        }
        (new ReflectionProperty(Gdpr::class, 'wave29Ffi'))->setValue(null, null);
        $object = new \stdClass;
        self::assertNull(Gdpr::wave29Native(3, ['reason' => $object]));
        self::assertSame(['rejected' => true, 'reason' => $object], Gdpr::rejectionPayloadNative($object));
        self::assertNull(Gdpr::wave29Native(999, []));
        self::assertNull(RustPipelineLibrary::resolveDomain('wave29-missing-library', Gdpr::FFI_CDEF, '/tmp/nntmux-wave29-does-not-exist.dylib'));
        $deep = [];
        for ($i = 0; $i < 66; $i++) {
            $deep = ['nested' => $deep];
        }
        self::assertNull(Gdpr::wave29Native(3, ['reason' => $deep]));
        self::assertSame(Gdpr::rejectionPayloadPhp($deep), Gdpr::rejectionPayloadNative($deep));
    }

    private function databaseFixture(): void
    {
        config(['database.default' => 'wave29_parity', 'database.connections.wave29_parity' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('wave29_parity');
        foreach ([
            'CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, deleted_at TEXT)',
            'CREATE TABLE gdpr_requests (id INTEGER PRIMARY KEY, user_id INTEGER, type TEXT, status TEXT, processed_by INTEGER, completed_at TEXT, admin_notes TEXT, response_payload TEXT, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE role_promotions (id INTEGER PRIMARY KEY, name TEXT, description TEXT, applicable_roles TEXT, additional_days INTEGER, start_date TEXT, end_date TEXT, is_active INTEGER, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE role_promotion_stats (id INTEGER PRIMARY KEY, role_promotion_id INTEGER, user_id INTEGER, role_id INTEGER, days_added INTEGER, applied_at TEXT)',
            'CREATE TABLE registration_periods (id INTEGER PRIMARY KEY, name TEXT, starts_at TEXT, ends_at TEXT, is_enabled INTEGER, created_by INTEGER, updated_by INTEGER)',
            'CREATE TABLE registration_status_history (id INTEGER PRIMARY KEY, changed_by INTEGER, registration_period_id INTEGER, created_at TEXT)',
            'CREATE TABLE user_activities (id INTEGER PRIMARY KEY, activity_type TEXT, metadata TEXT, created_at TEXT)',
            'CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT, guard_name TEXT)',
            'CREATE TABLE user_role_history (id INTEGER PRIMARY KEY, user_id INTEGER, old_role_id INTEGER, new_role_id INTEGER, changed_by INTEGER, change_reason TEXT, created_at TEXT)',
            'CREATE TABLE invitations (id INTEGER PRIMARY KEY, email TEXT, invited_by INTEGER, used_by INTEGER, is_active INTEGER, used_at TEXT, expires_at TEXT, created_at TEXT)',
        ] as $statement) {
            DB::statement($statement);
        }
        DB::table('users')->insert(['id' => 1, 'username' => 'Alice']);
        DB::table('roles')->insert(['id' => 1, 'name' => 'Member', 'guard_name' => 'web']);
        DB::table('role_promotions')->insert(['id' => 1, 'name' => 'Initial', 'is_active' => true]);
        DB::table('user_role_history')->insert([
            ['id' => 1, 'user_id' => 1, 'old_role_id' => null, 'new_role_id' => 1, 'change_reason' => 'manual', 'created_at' => '2026-10-24 00:00:00'],
            ['id' => 2, 'user_id' => 1, 'old_role_id' => 1, 'new_role_id' => 1, 'change_reason' => 'manual', 'created_at' => '2026-10-24 23:59:59'],
            ['id' => 3, 'user_id' => 1, 'old_role_id' => 1, 'new_role_id' => 1, 'change_reason' => 'manual', 'created_at' => '2026-10-25 00:00:00'],
        ]);
        Carbon::setTestNow(Carbon::parse('2026-10-24 12:00:00.500000'));
        DB::table('registration_periods')->insert([
            ['id' => 1, 'name' => 'past-microsecond', 'starts_at' => '2026-10-23 00:00:00', 'ends_at' => '2026-10-24 12:00:00.499999', 'is_enabled' => 0],
            ['id' => 2, 'name' => 'current', 'starts_at' => '2026-10-23 00:00:00', 'ends_at' => '2026-10-24 12:00:00.500000', 'is_enabled' => 1],
            ['id' => 3, 'name' => 'unbounded', 'starts_at' => null, 'ends_at' => null, 'is_enabled' => 1],
        ]);
        DB::table('user_activities')->insert([
            ['id' => 1, 'activity_type' => 'registered', 'metadata' => json_encode(['email' => 'a@example.test', 'ip_address' => '127.0.0.1']), 'created_at' => '2026-10-24 00:00:00'],
            ['id' => 2, 'activity_type' => 'registered', 'metadata' => json_encode(['email' => null, 'ip_address' => '127.0.0.1']), 'created_at' => '2026-10-24 00:00:01'],
        ]);
    }

    /** @return array{gdpr: Gdpr, registration: Registration, promotion: Promotion, invitation: Invitation, history: History} */
    private function controllers(): array
    {
        $registration = Mockery::mock(RegistrationStatusService::class);
        $registration->shouldReceive('disableExpiredPeriods')->andReturn(collect());
        $registration->shouldReceive('resolve')->andReturn([]);
        $registration->shouldReceive('statusOptions')->andReturn([0 => 'Open', 1 => 'Invite', 2 => 'Closed']);
        $registration->shouldReceive('getManualStatus')->andReturn(1);
        $registration->shouldReceive('updateManualStatus')->andReturnNull();
        $failures = Mockery::mock(RegistrationFailureLogService::class);
        $failures->shouldReceive('recentFailures')->with(10)->andReturn([['registration_status' => '0', 'manual_registration_status' => 'invalid']]);
        $registrations = new class extends Registration
        {
            public function __construct() {}

            public function setAdminPrefs(): void {}
        };
        (new ReflectionProperty(Registration::class, 'registrationStatusService'))->setValue($registrations, $registration);
        (new ReflectionProperty(Registration::class, 'registrationFailureLogService'))->setValue($registrations, $failures);
        $invitations = new class extends Invitation
        {
            public function __construct() {}

            public function setAdminPrefs(): void {}
        };
        $invitationService = Mockery::mock(InvitationService::class);
        $invitationService->shouldReceive('cancelInvitation')->with(1)->andReturn(true);
        $invitationService->shouldReceive('resendInvitation')->with(1)->andReturn(new \App\Models\Invitation);
        (new ReflectionProperty(Invitation::class, 'invitationService'))->setValue($invitations, $invitationService);

        return [
            'gdpr' => new class extends Gdpr
            {
                public function __construct() {}

                public function setAdminPrefs(): void {}
            },
            'registration' => $registrations,
            'promotion' => (new ReflectionClass(Promotion::class))->newInstanceWithoutConstructor(),
            'invitation' => $invitations,
            'history' => new class extends History
            {
                public function __construct() {}

                public function setAdminPrefs(): void {}
            },
        ];
    }

    public function test_real_handlers_preserve_storage_flash_messages_and_inclusive_dates(): void
    {
        $this->native();
        $this->databaseFixture();
        $controllers = $this->controllers();
        $export = Mockery::mock(GdprExportService::class);
        self::assertInstanceOf(GdprExportService::class, $export);
        $export->shouldNotReceive('generate');
        $erasure = Mockery::mock(GdprErasureService::class);
        self::assertInstanceOf(GdprErasureService::class, $erasure);
        $erasure->shouldNotReceive('eraseForAccountDeletion');
        foreach ([false, true] as $native) {
            foreach (self::CLASSES as $class) {
                $class::setUseRustFfi($native);
            }
            $retention = Mockery::mock(GdprRetentionService::class);
            self::assertInstanceOf(GdprRetentionService::class, $retention);
            $retention->shouldReceive('policy')->andReturn([]);
            $gdprView = $controllers['gdpr']->index(Request::create('/', 'GET', ['type' => "\u{00a0}export\u{200b}", 'status' => "\u{200b}pending\u{00a0}"]), $retention)->getData();
            self::assertSame('export', $gdprView['type'], 'Keep Laravel Unicode trimming at the request boundary.');
            self::assertSame('pending', $gdprView['status']);

            $closed = new GdprRequest(['type' => 'export', 'status' => 'completed']);
            $closed->id = 99;
            $controllers['gdpr']->generateExport($closed, $export);
            self::assertSame('This request cannot be exported.', session('error'));
            $closed->type = 'erasure';
            $controllers['gdpr']->completeErasure($closed, $erasure);
            self::assertSame('This erasure request cannot be completed.', session('error'));
            $closed->status = 'pending';
            $controllers['gdpr']->completeErasure($closed, $erasure);
            self::assertSame('The subject user could not be found.', session('error'));

            $storedRequest = GdprRequest::create(['type' => 'export', 'status' => 'pending', 'user_id' => 1]);
            $successfulExport = Mockery::mock(GdprExportService::class);
            self::assertInstanceOf(GdprExportService::class, $successfulExport);
            $successfulExport->shouldReceive('generate')->once()->withArgs(fn ($user, $gdpr, $actor): bool => $user->id === 1 && $gdpr->is($storedRequest) && $gdpr->status === 'processing' && $actor === null)->andReturn([]);
            $controllers['gdpr']->generateExport($storedRequest, $successfulExport);
            self::assertSame('processing', $storedRequest->fresh()->status);
            self::assertSame('GDPR export generated successfully.', session('success'));
            $audit = Mockery::mock(GdprAuditService::class);
            self::assertInstanceOf(GdprAuditService::class, $audit);
            $audit->shouldReceive('record')->once()->andReturn(new GdprAuditLog);
            $notification = Mockery::mock(GdprNotificationService::class);
            self::assertInstanceOf(GdprNotificationService::class, $notification);
            $notification->shouldReceive('requestRejected')->once()->withArgs(fn ($gdpr): bool => $gdpr->status === 'rejected' && $gdpr->response_payload === ['rejected' => true, 'reason' => 'Reason']);
            $controllers['gdpr']->reject(Request::create('/', 'POST', ['admin_notes' => 'Reason']), $storedRequest, $audit, $notification);
            self::assertSame(['rejected' => true, 'reason' => 'Reason'], $storedRequest->fresh()->response_payload);

            $periodView = $controllers['registration']->index(Request::create('/'))->getData();
            self::assertSame([2, 3], $periodView['currentPeriods']->modelKeys());
            self::assertSame([1], $periodView['pastPeriods']->modelKeys());
            self::assertSame([1], $periodView['recentSuccessfulRegistrations']->modelKeys());
            self::assertSame('Open', $periodView['recentFailedAttempts'][0]['registration_status_label']);
            foreach ([1, 2] as $status) {
                $controllers['registration']->updateStatus(UpdateRegistrationStatusRequest::create('/', 'POST', ['registerstatus' => $status]));
                self::assertSame(Registration::manualStatusMessagePhp(1, $status), session('success'));
            }

            $controllers['promotion']->update(AdminPromotionRequest::create('/', 'POST', ['name' => 'Updated', 'applicable_roles' => null, 'is_active' => '0']), 1);
            $stored = DB::table('role_promotions')->find(1);
            self::assertInstanceOf(\stdClass::class, $stored);
            self::assertSame('Updated', $stored->name);
            self::assertSame(1, $stored->is_active, 'Request::has controls checkbox semantics.');
            self::assertNull($stored->applicable_roles);
            $controllers['promotion']->toggle(1);
            self::assertSame('Promotion deactivated successfully.', session('success'));
            $controllers['promotion']->update(AdminPromotionRequest::create('/', 'POST', ['name' => 'Updated']), 1);
            self::assertSame('[]', DB::table('role_promotions')->where('id', 1)->value('applicable_roles'));

            foreach (['cancel' => 'Cancelled', 'resend' => 'Resent'] as $action => $verb) {
                $controllers['invitation']->bulkAction(Request::create('/', 'POST', ['bulk_action' => $action, 'invitation_ids' => [1]]));
                self::assertSame("{$verb} 1 invitations", session('success'));
            }
            DB::table('invitations')->insert(['id' => 1, 'email' => 'a@example.test']);
            $controllers['invitation']->bulkAction(Request::create('/', 'POST', ['bulk_action' => 'delete', 'invitation_ids' => [1]]));
            self::assertSame('Deleted 1 invitations', session('success'));
            self::assertSame(0, DB::table('invitations')->count());
            foreach ([['invalid', [], 'No invitations selected'], ['invalid', [1], 'Invalid bulk action']] as [$action, $ids, $error]) {
                $controllers['invitation']->bulkAction(Request::create('/', 'POST', ['bulk_action' => $action, 'invitation_ids' => $ids]));
                self::assertSame($error, session('error'));
            }
            $history = $controllers['history']->index(Request::create('/', 'GET', ['user_id' => '1', 'username' => 'Alice', 'role_id' => '1', 'change_reason' => 'manual', 'date_from' => '2026-10-24', 'date_to' => '2026-10-24']))->getData();
            self::assertSame([2, 1], $history['history']->getCollection()->modelKeys());
        }
    }

    /** @param list<mixed> $args
     * @return array{list<mixed>, array<string, mixed>}
     */
    private static function uncommonFixture(int $operation, array $args): array
    {
        $long = str_repeat("sample\0\xff", 2048);

        return match ($operation) {
            0 => [[$long, 'unknown'], ['type' => $long, 'status' => 'unknown']],
            1 => [[$long], ['status' => $long]],
            2 => [['erasure', 'processing', 'erasure', false, false], ['type' => 'erasure', 'status' => 'processing', 'action' => 'erasure', 'subject_exists' => false, 'is_admin' => false]],
            3 => [[$long], ['reason' => $long]],
            4 => [[array_fill(0, 128, ['ended' => true, 'starts_at' => null, 'ends_at' => 17])], ['periods' => array_fill(0, 128, ['ended' => true, 'starts_at' => null, 'ends_at' => 17])]],
            5 => [[['email' => null, 'ip_address' => '::1', 'extra' => $long]], ['metadata' => ['email' => null, 'ip_address' => '::1', 'extra' => $long]]],
            6 => [[['registration_status' => '1e0', 'manual_registration_status' => '2', 'message' => $long]], ['entry' => ['registration_status' => '1e0', 'manual_registration_status' => '2', 'message' => $long], 'statuses' => [1, 2]]],
            7 => [[1, 2], ['old_status' => 1, 'new_status' => 2]],
            8 => [[['name' => $long, 'applicable_roles' => range(1, 128)], true], ['input' => ['name' => $long, 'applicable_roles' => range(1, 128)], 'is_active' => true]],
            9 => [[false], ['active' => false]],
            10 => [[array_fill(0, 128, ['is_active' => true, 'statistics_count' => 111])], ['promotions' => array_fill(0, 128, ['is_active' => true, 'statistics_count' => 111])]],
            11 => [[['email' => $long, 'ob' => 'unknown']], ['input' => ['email' => $long, 'ob' => 'unknown']]],
            12 => [[], []],
            13 => [['delete', range(1, 128), 128], ['action' => 'delete', 'ids' => range(1, 128), 'count' => 128]],
            14 => [[['username' => $long, 'date_from' => '2026-10-24', 'date_to' => '2026-10-25']], ['input' => ['username' => $long, 'date_from' => '2026-10-24', 'date_to' => '2026-10-25']]],
            15 => [['', '2026-10-24'], ['date_from' => '', 'date_to' => '2026-10-24']],
            default => throw new \LogicException('Unknown benchmark operation'),
        };
    }

    /**
     * @param  callable(): mixed  $call
     * @return array<int, array<string, mixed>>
     */
    private function pairedSamples(callable $call, Randomizer $random): array
    {
        $rounds = [];
        for ($round = 1; $round <= 3; $round++) {
            foreach (range(1, 51) as $warmup) {
                foreach (self::CLASSES as $class) {
                    $class::setUseRustFfi(false);
                }
                $expected = $call();
                foreach (self::CLASSES as $class) {
                    $class::setUseRustFfi(true);
                }
                self::assertEquals($expected, $call());
            }
            $raw = [];
            for ($sample = 0; $sample < 1001; $sample++) {
                $pair = ['order' => $random->getInt(0, 1) === 0 ? [false, true] : [true, false]];
                foreach ($pair['order'] as $native) {
                    foreach (self::CLASSES as $class) {
                        $class::setUseRustFfi($native);
                    }
                    $start = hrtime(true);
                    $actual = $call();
                    $pair[$native ? 'rust' : 'php'] = hrtime(true) - $start;
                    self::assertEquals($expected, $actual);
                }
                $raw[] = $pair;
            }
            $php = array_column($raw, 'php');
            $rust = array_column($raw, 'rust');
            sort($php);
            sort($rust);
            $rounds[$round] = ['pairs' => $raw, 'mean_speedup' => array_sum($php) / array_sum($rust), 'php_p99_ns' => $php[990], 'rust_p99_ns' => $rust[990], 'p99_speedup' => $php[990] / $rust[990]];
        }

        return $rounds;
    }

    public function test_paired_randomized_release_benchmarks_cover_helpers_and_real_callers(): void
    {
        $this->native();
        $this->databaseFixture();
        $controllers = $this->controllers();
        $random = new Randomizer(new Mt19937(291));
        $artifact = tempnam(sys_get_temp_dir(), 'nntmux-wave29-1-bench-');
        self::assertIsString($artifact);
        $evidence = [
            'protocol' => ['rounds' => 3, 'pairs' => 1001, 'warmups' => 51, 'seed' => 291, 'unit' => 'nanoseconds per individual call', 'gate' => 'each common mean and uncommon p99 >= 1.5x', 'uncertainty' => 'three independent round ratios reported as a min/max range; screen only, no production throughput claim', 'rust_profile' => 'release', 'database' => 'private in-memory SQLite', 'service_boundaries' => 'registration and invitation services mocked; GDPR closed export; promotion update and history use SQLite; views not rendered', 'commands' => ['CARGO_TARGET_DIR=/tmp/cargo-w29-1 cargo test --manifest-path rust/pipeline-ffi/Cargo.toml --lib w29_admin_gdpr_reg_promo_invite_history', 'CARGO_TARGET_DIR=/tmp/cargo-w29-1 cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release', 'NNTMUX_PIPELINE_FFI_LIB=/tmp/cargo-w29-1/release/libnntmux_pipeline.dylib php artisan test --compact tests/Unit/RustAdminGdprRegPromoInviteHistoryControllersParityTest.php']],
            'identity' => ['php' => PHP_VERSION, 'os' => php_uname(), 'ffi.enable' => ini_get('ffi.enable'), 'opcache.enable_cli' => ini_get('opcache.enable_cli'), 'memory_limit' => ini_get('memory_limit'), 'load_average' => sys_getloadavg(), 'library_sha256' => hash_file('sha256', (string) getenv('NNTMUX_PIPELINE_FFI_LIB')), 'composer_lock_sha256' => hash_file('sha256', base_path('composer.lock')), 'vendor_metadata_sha256' => hash_file('sha256', base_path('vendor/composer/installed.json'))],
            'sources' => [], 'samples' => [], 'summary' => [], 'failures' => [],
        ];
        foreach (array_merge(array_map(static fn ($class) => (new ReflectionClass($class))->getFileName(), self::CLASSES), [__FILE__, base_path('rust/pipeline-ffi/src/cleaning.rs')]) as $file) {
            $evidence['sources'][$file] = hash_file('sha256', $file);
        }
        foreach (['git' => ['git', 'rev-parse', 'HEAD'], 'rust' => ['rustc', '--version'], 'cargo' => ['cargo', '--version'], 'hardware' => PHP_OS_FAMILY === 'Darwin' ? ['sysctl', '-n', 'machdep.cpu.brand_string'] : ['uname', '-m']] as $name => $command) {
            $process = new Process($command);
            $process->run();
            $evidence['identity'][$name] = trim($process->getOutput().$process->getErrorOutput());
        }
        try {
            foreach (self::cases() as [$class, $method, $operation, $args, $wire]) {
                foreach (['common', 'uncommon'] as $kind) {
                    [$fixture, $input] = $kind === 'common' ? [$args, $wire] : self::uncommonFixture($operation, $args);
                    self::assertSame(self::envelope($operation, $class::{$method.'Php'}(...$fixture)), Gdpr::wave29Native($operation, $input), 'Benchmarks require actual Rust without fallback.');
                    $name = class_basename($class).'::'.$method.'/'.$kind;
                    $evidence['samples'][$name] = $this->pairedSamples(fn () => $class::$method(...$fixture), $random);
                }
            }
            $export = Mockery::mock(GdprExportService::class);
            self::assertInstanceOf(GdprExportService::class, $export);
            $export->shouldNotReceive('generate');
            $closed = new GdprRequest(['type' => 'export', 'status' => 'completed']);
            $closed->id = 99;
            foreach (['common', 'uncommon'] as $kind) {
                $closed->status = $kind === 'common' ? 'completed' : 'pending';
                $status = $kind === 'common' ? 1 : 2;
                $promotion = ['name' => $kind === 'common' ? 'Offer' : str_repeat('Offer', 2048), 'applicable_roles' => $kind === 'common' ? [] : range(1, 128)];
                $history = $kind === 'common' ? [] : ['username' => 'Alice', 'role_id' => '1', 'change_reason' => 'manual', 'date_from' => '2026-10-24', 'date_to' => '2026-10-24'];
                $action = $kind === 'common' ? 'cancel' : 'resend';
                $callers = [
                    'GdprRequestController::generateExport' => function () use ($controllers, $closed, $export): array {
                        $response = $controllers['gdpr']->generateExport($closed, $export);

                        return [$response->getTargetUrl(), session('error')];
                    },
                    'AdminRegistrationController::updateStatus' => function () use ($controllers, $status): array {
                        $response = $controllers['registration']->updateStatus(UpdateRegistrationStatusRequest::create('/', 'POST', ['registerstatus' => $status]));

                        return [$response->getTargetUrl(), session('success')];
                    },
                    'AdminPromotionController::update' => function () use ($controllers, $promotion): array {
                        $response = $controllers['promotion']->update(AdminPromotionRequest::create('/', 'POST', $promotion), 1);

                        return [$response->getTargetUrl(), session('success'), (array) DB::table('role_promotions')->find(1)];
                    },
                    'AdminInvitationController::bulkAction' => function () use ($controllers, $action): array {
                        $response = $controllers['invitation']->bulkAction(Request::create('/', 'POST', ['bulk_action' => $action, 'invitation_ids' => [1]]));

                        return [$response->getTargetUrl(), session('success')];
                    },
                    'AdminUserRoleHistoryController::index' => function () use ($controllers, $history): array {
                        $data = $controllers['history']->index(Request::create('/', 'GET', $history))->getData();

                        return [$data['filters'], $data['history']->getCollection()->modelKeys(), $data['roles']];
                    },
                ];
                foreach ($callers as $name => $call) {
                    $evidence['samples'][$name.'-caller/'.$kind] = $this->pairedSamples($call, $random);
                }
            }
            foreach ($evidence['samples'] as $name => $rounds) {
                $mean = array_column($rounds, 'mean_speedup');
                $p99 = array_column($rounds, 'p99_speedup');
                $evidence['summary'][$name] = ['mean_speedup_range' => [min($mean), max($mean)], 'p99_speedup_range' => [min($p99), max($p99)], 'qualifies' => str_ends_with($name, '/common') ? min($mean) >= 1.5 : min($p99) >= 1.5];
            }
        } catch (\Throwable $error) {
            $evidence['failures'][] = (string) $error;
            throw $error;
        } finally {
            file_put_contents($artifact, json_encode($evidence, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE));
            fwrite(STDERR, "\nWave 29.1 paired benchmark evidence: {$artifact}\n");
        }
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(null);
            self::assertFalse($class::usesRustFfi());
        }
    }
}
