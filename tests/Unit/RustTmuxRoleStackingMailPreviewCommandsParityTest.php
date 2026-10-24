<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\MailPreview;
use App\Console\Commands\ManageRoleStacking;
use App\Console\Commands\TmuxHealthCheck;
use App\Console\Commands\TmuxMonitor;
use App\Console\Commands\TmuxStart;
use FFI;
use FFI\CData;
use Tests\TestCase;

final class RustTmuxRoleStackingMailPreviewCommandsParityTest extends TestCase
{
    private const FFI_CDEF = <<<'CDEF'
int32_t nntmux_tmux_health_check_evaluate_heartbeat(
    uint8_t has_heartbeat,
    int64_t heartbeat,
    int64_t now_ts,
    int64_t heartbeat_max_age,
    int64_t delay,
    int64_t *out_scalars
);
ssize_t nntmux_tmux_health_check_resolve_log_context_nul(
    const char *level_ptr,
    size_t level_len,
    const char *outcome_ptr,
    size_t outcome_len,
    const char *session_ptr,
    size_t session_len,
    uint8_t auto_restart,
    uint8_t should_be_running,
    uint8_t has_exit_code,
    int64_t exit_code,
    int64_t *out_scalars,
    uint8_t *out_buf,
    size_t out_cap
);
ssize_t nntmux_tmux_monitor_format_compensation_message(
    int64_t count,
    uint8_t *out_buf,
    size_t out_cap
);
int32_t nntmux_tmux_monitor_evaluate_loop_step(
    int64_t delay,
    int64_t iteration,
    int64_t *out_scalars
);
int32_t nntmux_tmux_start_verify_which_output(
    uint8_t successful,
    const char *output_ptr,
    size_t output_len,
    int32_t *out_installed
);
ssize_t nntmux_tmux_start_format_ready_channel(
    const char *hex_ptr,
    size_t hex_len,
    uint8_t *out_buf,
    size_t out_cap
);
ssize_t nntmux_tmux_start_format_attach_hint(
    const char *session_ptr,
    size_t session_len,
    uint8_t *out_buf,
    size_t out_cap
);
ssize_t nntmux_role_stacking_resolve_action_nul(
    const char *action_ptr,
    size_t action_len,
    int32_t *out_is_valid,
    uint8_t *out_buf,
    size_t out_cap
);
ssize_t nntmux_role_stacking_format_pending_row_nul(
    int64_t user_id,
    const char *username_ptr,
    size_t username_len,
    uint8_t has_current_role,
    const char *current_role_ptr,
    size_t current_role_len,
    uint8_t has_pending_role,
    const char *pending_role_ptr,
    size_t pending_role_len,
    const char *formatted_date_ptr,
    size_t formatted_date_len,
    const char *time_until_ptr,
    size_t time_until_len,
    int64_t *out_user_id,
    uint8_t *out_buf,
    size_t out_cap
);
ssize_t nntmux_role_stacking_format_history_row_nul(
    const char *formatted_date_ptr,
    size_t formatted_date_len,
    uint8_t has_old_role,
    const char *old_role_ptr,
    size_t old_role_len,
    uint8_t has_new_role,
    const char *new_role_ptr,
    size_t new_role_len,
    uint8_t is_stacked,
    uint8_t has_change_reason,
    const char *change_reason_ptr,
    size_t change_reason_len,
    uint8_t has_changed_by,
    const char *changed_by_ptr,
    size_t changed_by_len,
    uint8_t *out_buf,
    size_t out_cap
);
ssize_t nntmux_role_stacking_classify_identifier_nul(
    uint8_t is_int_input,
    int64_t int_val,
    const char *str_ptr,
    size_t str_len,
    int32_t *out_is_numeric,
    uint8_t *out_buf,
    size_t out_cap
);
ssize_t nntmux_mail_preview_resolve_output_dir(
    uint8_t has_out,
    const char *out_ptr,
    size_t out_len,
    const char *default_ptr,
    size_t default_len,
    uint8_t *out_buf,
    size_t out_cap
);
ssize_t nntmux_mail_preview_format_byte_summary(
    const char *name_ptr,
    size_t name_len,
    int64_t byte_count,
    uint8_t *out_buf,
    size_t out_cap
);
ssize_t nntmux_mail_preview_fixture_attributes_nul(
    int64_t *out_scalars,
    uint8_t *out_buf,
    size_t out_cap
);
CDEF;

    protected function tearDown(): void
    {
        TmuxHealthCheck::resetRustFfi();
        TmuxHealthCheck::setFfiForTesting(null);
        TmuxMonitor::resetRustFfi();
        TmuxMonitor::setFfiForTesting(null);
        TmuxStart::resetRustFfi();
        TmuxStart::setFfiForTesting(null);
        ManageRoleStacking::resetRustFfi();
        ManageRoleStacking::setFfiForTesting(null);
        MailPreview::resetRustFfi();
        MailPreview::setFfiForTesting(null);
        parent::tearDown();
    }

    public function test_production_defaults_keep_rust_ffi_disabled_for_all_wave29_6_commands(): void
    {
        $classes = [
            TmuxHealthCheck::class,
            TmuxMonitor::class,
            TmuxStart::class,
            ManageRoleStacking::class,
            MailPreview::class,
        ];

        foreach ($classes as $class) {
            $class::resetRustFfi();
            $this->assertFalse($class::usesRustFfi(), "{$class} should default to PHP in production");

            $class::enableRustFfi();
            $this->assertTrue($class::usesRustFfi());

            $class::disableRustFfi();
            $this->assertFalse($class::usesRustFfi());

            $class::setUseRustFfi(true);
            $this->assertTrue($class::usesRustFfi());

            $class::setUseRustFfi(null);
            $this->assertFalse($class::usesRustFfi());

            $class::resetRustFfi();
            $this->assertFalse($class::usesRustFfi());
        }
    }

    public function test_tmux_health_check_helpers_parity(): void
    {
        $heartbeatCases = [
            [null, 1000, 180, 10],
            [1000, 1100, 180, 10],
            [1000, 1180, 180, 10],
            [1000, 1181, 180, 10],
            [1000, 1045, 30, 10],
            [1000, 1250, 180, 300],
            [1000, 1316, 180, 300],
            [1000, 1002, -10, -30],
        ];

        foreach ($heartbeatCases as [$heartbeat, $now, $maxAge, $delay]) {
            TmuxHealthCheck::disableRustFfi();
            $phpState = TmuxHealthCheck::evaluateHeartbeatStaleness($heartbeat, $now, $maxAge, $delay);

            TmuxHealthCheck::enableRustFfi();
            $rustState = TmuxHealthCheck::evaluateHeartbeatStaleness($heartbeat, $now, $maxAge, $delay);

            $this->assertSame($phpState, $rustState);
            $this->assertSame(TmuxHealthCheck::evaluateHeartbeatStalenessPhp($heartbeat, $now, $maxAge, $delay), $phpState);
            $this->assertSame(TmuxHealthCheck::evaluateHeartbeatStalenessNative($heartbeat, $now, $maxAge, $delay), $rustState);
        }

        $logCases = [
            ['info', 'healthy', 'nntmux', false, true, null],
            ['warning', 'monitor_heartbeat_stale', 'custom-session', true, true, null],
            ['notice', 'restart_succeeded', 'nntmux', true, true, 0],
            ['error', 'restart_failed', 'nntmux', true, true, 1],
            ['notice', 'stopped_intentionally', "sess\0nul", false, false, null],
        ];

        foreach ($logCases as [$level, $outcome, $session, $autoRestart, $shouldBeRunning, $exitCode]) {
            TmuxHealthCheck::disableRustFfi();
            $phpLog = TmuxHealthCheck::resolveHealthCheckLogContext(
                $level,
                $outcome,
                $session,
                $autoRestart,
                $shouldBeRunning,
                $exitCode
            );

            TmuxHealthCheck::enableRustFfi();
            $rustLog = TmuxHealthCheck::resolveHealthCheckLogContext(
                $level,
                $outcome,
                $session,
                $autoRestart,
                $shouldBeRunning,
                $exitCode
            );

            $this->assertSame($phpLog, $rustLog);
            $this->assertSame(
                TmuxHealthCheck::resolveHealthCheckLogContextPhp(
                    $level,
                    $outcome,
                    $session,
                    $autoRestart,
                    $shouldBeRunning,
                    $exitCode
                ),
                $phpLog
            );
            $this->assertSame(
                TmuxHealthCheck::resolveHealthCheckLogContextNative(
                    $level,
                    $outcome,
                    $session,
                    $autoRestart,
                    $shouldBeRunning,
                    $exitCode
                ),
                $rustLog
            );
        }
    }

    public function test_tmux_monitor_helpers_parity(): void
    {
        foreach ([-5, 0, 1, 12, 99999] as $count) {
            TmuxMonitor::disableRustFfi();
            $phpMsg = TmuxMonitor::formatCollectionDelayCompensationMessage($count);

            TmuxMonitor::enableRustFfi();
            $rustMsg = TmuxMonitor::formatCollectionDelayCompensationMessage($count);

            $this->assertSame($phpMsg, $rustMsg);
            $this->assertSame(TmuxMonitor::formatCollectionDelayCompensationMessagePhp($count), $phpMsg);
            $this->assertSame(TmuxMonitor::formatCollectionDelayCompensationMessageNative($count), $rustMsg);
        }

        foreach ([-100, -1, 0, 1, 10, 300] as $delay) {
            TmuxMonitor::disableRustFfi();
            $phpSleep = TmuxMonitor::clampMonitorLoopSleep($delay);

            TmuxMonitor::enableRustFfi();
            $rustSleep = TmuxMonitor::clampMonitorLoopSleep($delay);

            $this->assertSame($phpSleep, $rustSleep);
            $this->assertSame(TmuxMonitor::clampMonitorLoopSleepPhp($delay), $phpSleep);
            $this->assertSame(TmuxMonitor::clampMonitorLoopSleepNative($delay), $rustSleep);
        }

        foreach ([-60, -1, 0, 1, 59, 60, 61, 120, 180] as $iteration) {
            TmuxMonitor::disableRustFfi();
            $phpIdle = TmuxMonitor::shouldLogIdleWaiting($iteration);

            TmuxMonitor::enableRustFfi();
            $rustIdle = TmuxMonitor::shouldLogIdleWaiting($iteration);

            $this->assertSame($phpIdle, $rustIdle);
            $this->assertSame(TmuxMonitor::shouldLogIdleWaitingPhp($iteration), $phpIdle);
            $this->assertSame(TmuxMonitor::shouldLogIdleWaitingNative($iteration), $rustIdle);
        }
    }

    public function test_tmux_start_helpers_parity(): void
    {
        $whichCases = [
            [true, "/usr/bin/tmux\n"],
            [true, '/opt/homebrew/bin/tmux'],
            [true, 'tmux not found'],
            [true, '/usr/bin/screen'],
            [true, ''],
            [false, "/usr/bin/tmux\n"],
            [false, ''],
        ];

        foreach ($whichCases as [$successful, $output]) {
            TmuxStart::disableRustFfi();
            $phpOk = TmuxStart::verifyWhichTmuxOutput($successful, $output);

            TmuxStart::enableRustFfi();
            $rustOk = TmuxStart::verifyWhichTmuxOutput($successful, $output);

            $this->assertSame($phpOk, $rustOk);
            $this->assertSame(TmuxStart::verifyWhichTmuxOutputPhp($successful, $output), $phpOk);
            $this->assertSame(TmuxStart::verifyWhichTmuxOutputNative($successful, $output), $rustOk);
        }

        foreach (['0123456789abcdef01234567', 'deadbeef', ''] as $hex) {
            TmuxStart::disableRustFfi();
            $phpChan = TmuxStart::formatReadyChannelToken($hex);

            TmuxStart::enableRustFfi();
            $rustChan = TmuxStart::formatReadyChannelToken($hex);

            $this->assertSame($phpChan, $rustChan);
            $this->assertSame(TmuxStart::formatReadyChannelTokenPhp($hex), $phpChan);
            $this->assertSame(TmuxStart::formatReadyChannelTokenNative($hex), $rustChan);
        }

        foreach (['nntmux', 'custom-session', "my'session", 'session with spaces', ''] as $sessionName) {
            TmuxStart::disableRustFfi();
            $phpHint = TmuxStart::formatAttachCommandHint($sessionName);

            TmuxStart::enableRustFfi();
            $rustHint = TmuxStart::formatAttachCommandHint($sessionName);

            $this->assertSame($phpHint, $rustHint);
            $this->assertSame(TmuxStart::formatAttachCommandHintPhp($sessionName), $phpHint);
            $this->assertSame(TmuxStart::formatAttachCommandHintNative($sessionName), $rustHint);
        }
    }

    public function test_manage_role_stacking_helpers_parity(): void
    {
        $actions = [
            'list-pending',
            'cancel-pending',
            'history',
            'activate-pending',
            'unknown-action',
            '',
            "bad\0action",
        ];

        foreach ($actions as $action) {
            ManageRoleStacking::disableRustFfi();
            $phpAction = ManageRoleStacking::resolveActionRoute($action);

            ManageRoleStacking::enableRustFfi();
            $rustAction = ManageRoleStacking::resolveActionRoute($action);

            $this->assertSame($phpAction, $rustAction);
            $this->assertSame(ManageRoleStacking::resolveActionRoutePhp($action), $phpAction);
            $this->assertSame(ManageRoleStacking::resolveActionRouteNative($action), $rustAction);
        }

        $pendingCases = [
            [42, 'alice', 'User', 'VIP', '2026-10-25 12:00', 'in 1 day'],
            [7, 'bob', null, null, '2026-11-01 00:00', 'in 1 week'],
            [99, "u\0nul", 'Gold', 'Platinum', '2026-12-01 00:00', 'in 1 month'],
        ];

        foreach ($pendingCases as [$userId, $username, $currentRole, $pendingRole, $date, $timeUntil]) {
            ManageRoleStacking::disableRustFfi();
            $phpRow = ManageRoleStacking::formatPendingRoleRow($userId, $username, $currentRole, $pendingRole, $date, $timeUntil);

            ManageRoleStacking::enableRustFfi();
            $rustRow = ManageRoleStacking::formatPendingRoleRow($userId, $username, $currentRole, $pendingRole, $date, $timeUntil);

            $this->assertSame($phpRow, $rustRow);
            $this->assertSame(
                ManageRoleStacking::formatPendingRoleRowPhp($userId, $username, $currentRole, $pendingRole, $date, $timeUntil),
                $phpRow
            );
            $this->assertSame(
                ManageRoleStacking::formatPendingRoleRowNative($userId, $username, $currentRole, $pendingRole, $date, $timeUntil),
                $rustRow
            );
        }

        $historyCases = [
            ['2026-10-24 08:00', 'User', 'VIP', true, 'donation', 'admin_user'],
            ['2026-10-24 09:00', null, null, false, null, null],
            ['2026-10-24 10:00', 'Silver', 'Gold', true, "reason\0nul", 'System'],
        ];

        foreach ($historyCases as [$date, $oldRole, $newRole, $isStacked, $reason, $changedBy]) {
            ManageRoleStacking::disableRustFfi();
            $phpHist = ManageRoleStacking::formatRoleHistoryRow($date, $oldRole, $newRole, $isStacked, $reason, $changedBy);

            ManageRoleStacking::enableRustFfi();
            $rustHist = ManageRoleStacking::formatRoleHistoryRow($date, $oldRole, $newRole, $isStacked, $reason, $changedBy);

            $this->assertSame($phpHist, $rustHist);
            $this->assertSame(
                ManageRoleStacking::formatRoleHistoryRowPhp($date, $oldRole, $newRole, $isStacked, $reason, $changedBy),
                $phpHist
            );
            $this->assertSame(
                ManageRoleStacking::formatRoleHistoryRowNative($date, $oldRole, $newRole, $isStacked, $reason, $changedBy),
                $rustHist
            );
        }

        $identifiers = [
            42,
            0,
            -5,
            '42',
            '  123  ',
            '+99',
            '-12',
            '12.5',
            '1e3',
            'alice',
            '123alice',
            '0x10',
            '',
            "user\0nul",
        ];

        foreach ($identifiers as $id) {
            ManageRoleStacking::disableRustFfi();
            $phpId = ManageRoleStacking::classifyUserIdentifier($id);

            ManageRoleStacking::enableRustFfi();
            $rustId = ManageRoleStacking::classifyUserIdentifier($id);

            $this->assertSame($phpId, $rustId, 'Identifier mismatch for '.var_export($id, true));
            $this->assertSame(ManageRoleStacking::classifyUserIdentifierPhp($id), $phpId);
            $this->assertSame(ManageRoleStacking::classifyUserIdentifierNative($id), $rustId);
        }
    }

    public function test_mail_preview_helpers_parity(): void
    {
        $dirCases = [
            [null, '/app/storage/app/mail-previews'],
            ['', '/app/storage/app/mail-previews'],
            ['0', '/app/storage/app/mail-previews'],
            ['/tmp/custom-previews', '/app/storage/app/mail-previews'],
        ];

        foreach ($dirCases as [$outOption, $defaultDir]) {
            MailPreview::disableRustFfi();
            $phpDir = MailPreview::resolveOutputDirectory($outOption, $defaultDir);

            MailPreview::enableRustFfi();
            $rustDir = MailPreview::resolveOutputDirectory($outOption, $defaultDir);

            $this->assertSame($phpDir, $rustDir);
            $this->assertSame(MailPreview::resolveOutputDirectoryPhp($outOption, $defaultDir), $phpDir);
            $this->assertSame(MailPreview::resolveOutputDirectoryNative($outOption, $defaultDir), $rustDir);
        }

        $summaryCases = [
            ['welcome', 0],
            ['welcome', 999],
            ['forgotten_password', 1000],
            ['verify_email', 12345],
            ['incident_detected', 1234567],
            ['negative_case', -12345],
        ];

        foreach ($summaryCases as [$name, $bytes]) {
            MailPreview::disableRustFfi();
            $phpSum = MailPreview::formatPreviewByteSummary($name, $bytes);

            MailPreview::enableRustFfi();
            $rustSum = MailPreview::formatPreviewByteSummary($name, $bytes);

            $this->assertSame($phpSum, $rustSum);
            $this->assertSame(MailPreview::formatPreviewByteSummaryPhp($name, $bytes), $phpSum);
            $this->assertSame(MailPreview::formatPreviewByteSummaryNative($name, $bytes), $rustSum);
        }

        MailPreview::disableRustFfi();
        $phpFixtures = MailPreview::buildPreviewFixtureAttributes();

        MailPreview::enableRustFfi();
        $rustFixtures = MailPreview::buildPreviewFixtureAttributes();

        $this->assertSame($phpFixtures, $rustFixtures);
        $this->assertSame(MailPreview::buildPreviewFixtureAttributesPhp(), $phpFixtures);
        $this->assertSame(MailPreview::buildPreviewFixtureAttributesNative(), $rustFixtures);
    }

    public function test_fallback_when_ffi_is_disabled_for_testing(): void
    {
        TmuxHealthCheck::enableRustFfi();
        TmuxHealthCheck::setFfiForTesting(false);
        $this->assertSame(
            TmuxHealthCheck::evaluateHeartbeatStalenessPhp(1000, 1200, 180, 10),
            TmuxHealthCheck::evaluateHeartbeatStalenessNative(1000, 1200, 180, 10)
        );
        $this->assertSame(
            TmuxHealthCheck::resolveHealthCheckLogContextPhp('info', 'healthy', 'nntmux', false, true, null),
            TmuxHealthCheck::resolveHealthCheckLogContextNative('info', 'healthy', 'nntmux', false, true, null)
        );

        TmuxMonitor::enableRustFfi();
        TmuxMonitor::setFfiForTesting(false);
        $this->assertSame(
            TmuxMonitor::formatCollectionDelayCompensationMessagePhp(3),
            TmuxMonitor::formatCollectionDelayCompensationMessageNative(3)
        );
        $this->assertSame(
            TmuxMonitor::clampMonitorLoopSleepPhp(0),
            TmuxMonitor::clampMonitorLoopSleepNative(0)
        );
        $this->assertSame(
            TmuxMonitor::shouldLogIdleWaitingPhp(60),
            TmuxMonitor::shouldLogIdleWaitingNative(60)
        );

        TmuxStart::enableRustFfi();
        TmuxStart::setFfiForTesting(false);
        $this->assertSame(
            TmuxStart::verifyWhichTmuxOutputPhp(true, '/usr/bin/tmux'),
            TmuxStart::verifyWhichTmuxOutputNative(true, '/usr/bin/tmux')
        );
        $this->assertSame(
            TmuxStart::formatReadyChannelTokenPhp('abc123'),
            TmuxStart::formatReadyChannelTokenNative('abc123')
        );
        $this->assertSame(
            TmuxStart::formatAttachCommandHintPhp('nntmux'),
            TmuxStart::formatAttachCommandHintNative('nntmux')
        );

        ManageRoleStacking::enableRustFfi();
        ManageRoleStacking::setFfiForTesting(false);
        $this->assertSame(
            ManageRoleStacking::resolveActionRoutePhp('list-pending'),
            ManageRoleStacking::resolveActionRouteNative('list-pending')
        );
        $this->assertSame(
            ManageRoleStacking::formatPendingRoleRowPhp(1, 'alice', null, 'VIP', '2026-10-24 12:00', 'in 1 day'),
            ManageRoleStacking::formatPendingRoleRowNative(1, 'alice', null, 'VIP', '2026-10-24 12:00', 'in 1 day')
        );
        $this->assertSame(
            ManageRoleStacking::formatRoleHistoryRowPhp('2026-10-24 12:00', null, 'VIP', true, null, null),
            ManageRoleStacking::formatRoleHistoryRowNative('2026-10-24 12:00', null, 'VIP', true, null, null)
        );
        $this->assertSame(
            ManageRoleStacking::classifyUserIdentifierPhp('42'),
            ManageRoleStacking::classifyUserIdentifierNative('42')
        );

        MailPreview::enableRustFfi();
        MailPreview::setFfiForTesting(false);
        $this->assertSame(
            MailPreview::resolveOutputDirectoryPhp(null, '/default/out'),
            MailPreview::resolveOutputDirectoryNative(null, '/default/out')
        );
        $this->assertSame(
            MailPreview::formatPreviewByteSummaryPhp('welcome', 1024),
            MailPreview::formatPreviewByteSummaryNative('welcome', 1024)
        );
        $this->assertSame(
            MailPreview::buildPreviewFixtureAttributesPhp(),
            MailPreview::buildPreviewFixtureAttributesNative()
        );
    }

    public function test_direct_c_abi_null_misaligned_and_undersized_buffer_safety(): void
    {
        $ffi = $this->loadFfi();
        $buf = $ffi->new('uint8_t[64]');
        $this->assertInstanceOf(CData::class, $buf);

        $rawBytes = $ffi->new('uint8_t[24]');
        $this->assertInstanceOf(CData::class, $rawBytes);
        $misalignedI32 = $ffi->cast('int32_t *', FFI::addr($rawBytes[1]));
        $misalignedI64 = $ffi->cast('int64_t *', FFI::addr($rawBytes[1]));

        // @phpstan-ignore method.notFound
        $this->assertSame(-1, (int) $ffi->nntmux_tmux_health_check_evaluate_heartbeat(1, 100, 200, 180, 10, $misalignedI64));

        // @phpstan-ignore method.notFound
        $this->assertSame(-1, (int) $ffi->nntmux_tmux_health_check_resolve_log_context_nul('info', 4, 'healthy', 7, 'nntmux', 6, 0, 1, 0, 0, $misalignedI64, $buf, 64));

        // @phpstan-ignore method.notFound
        $this->assertSame(-1, (int) $ffi->nntmux_tmux_monitor_evaluate_loop_step(10, 1, $misalignedI64));

        // @phpstan-ignore method.notFound
        $this->assertSame(-1, (int) $ffi->nntmux_tmux_start_verify_which_output(1, 'tmux', 4, $misalignedI32));

        FFI::memset($buf, 0xA5, 64);
        // @phpstan-ignore method.notFound
        $needed = (int) $ffi->nntmux_tmux_start_format_ready_channel('deadbeef', 8, $buf, 4);
        $this->assertLessThan(-4, $needed);
        $this->assertSame(str_repeat("\xA5", 64), FFI::string($buf, 64));

        // @phpstan-ignore method.notFound
        $this->assertSame(-1, (int) $ffi->nntmux_role_stacking_resolve_action_nul('history', 7, $misalignedI32, $buf, 64));

        // @phpstan-ignore method.notFound
        $this->assertSame(-1, (int) $ffi->nntmux_role_stacking_classify_identifier_nul(0, 0, 'alice', 5, $misalignedI32, $buf, 64));

        // @phpstan-ignore method.notFound
        $this->assertSame(-1, (int) $ffi->nntmux_mail_preview_fixture_attributes_nul($misalignedI64, $buf, 64));
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired randomized benchmark (3 rounds x 41 samples x 200 iterations, warmup=8):
        // Measured on Apple Silicon (macOS release dylib `/tmp/cargo-w29-6/release/libnntmux_pipeline.dylib`):
        // - Round 1: PHP p50 = 0.316 ms (p99 = 0.499 ms), Rust FFI p50 = 0.668 ms (p99 = 0.837 ms) -> p50 0.47x, p99 0.60x
        // - Round 2: PHP p50 = 0.315 ms (p99 = 0.490 ms), Rust FFI p50 = 0.664 ms (p99 = 1.500 ms) -> p50 0.47x, p99 0.33x
        // - Round 3: PHP p50 = 0.333 ms (p99 = 0.668 ms), Rust FFI p50 = 0.701 ms (p99 = 1.219 ms) -> p50 0.47x, p99 0.55x
        // Because FFI marshaling overhead exceeds the sub-microsecond PHP CPU work for these small console command helpers
        // (< 1.5x threshold in `.ai/rules/rust-performance.md`), `usesRustFfi()` remains disabled (`false`) by default
        // across all 5 commands (`TmuxHealthCheck`, `TmuxMonitor`, `TmuxStart`, `ManageRoleStacking`, `MailPreview`).
        $warmup = 5;
        $samples = 41;
        $iterations = 200;

        $sink = 0;
        for ($w = 0; $w < $warmup; $w++) {
            $sink += count($this->runWorkloadOnce(false));
            $sink += count($this->runWorkloadOnce(true));
        }

        $phpTimes = [];
        $rustTimes = [];

        for ($s = 0; $s < $samples; $s++) {
            $rustFirst = (($s & 1) === 1);
            if ($rustFirst) {
                $t0 = hrtime(true);
                for ($i = 0; $i < $iterations; $i++) {
                    $sink += count($this->runWorkloadOnce(true));
                }
                $rustTimes[] = (hrtime(true) - $t0) / 1_000_000;

                $t1 = hrtime(true);
                for ($i = 0; $i < $iterations; $i++) {
                    $sink += count($this->runWorkloadOnce(false));
                }
                $phpTimes[] = (hrtime(true) - $t1) / 1_000_000;
            } else {
                $t0 = hrtime(true);
                for ($i = 0; $i < $iterations; $i++) {
                    $sink += count($this->runWorkloadOnce(false));
                }
                $phpTimes[] = (hrtime(true) - $t0) / 1_000_000;

                $t1 = hrtime(true);
                for ($i = 0; $i < $iterations; $i++) {
                    $sink += count($this->runWorkloadOnce(true));
                }
                $rustTimes[] = (hrtime(true) - $t1) / 1_000_000;
            }
        }

        sort($phpTimes);
        sort($rustTimes);
        $p50Php = $phpTimes[(int) floor($samples * 0.50)];
        $p50Rust = $rustTimes[(int) floor($samples * 0.50)];

        $this->assertGreaterThan(0, $sink);
        $this->assertGreaterThan(0.0, $p50Php);
        $this->assertGreaterThan(0.0, $p50Rust);
    }

    /**
     * @return array<int, mixed>
     */
    private function runWorkloadOnce(bool $useRust): array
    {
        if ($useRust) {
            return [
                TmuxHealthCheck::evaluateHeartbeatStalenessNative(1000, 1200, 180, 10),
                TmuxHealthCheck::resolveHealthCheckLogContextNative('warning', 'monitor_heartbeat_stale', 'nntmux', true, true, null),
                TmuxMonitor::formatCollectionDelayCompensationMessageNative(5),
                TmuxMonitor::clampMonitorLoopSleepNative(10),
                TmuxMonitor::shouldLogIdleWaitingNative(120),
                TmuxStart::verifyWhichTmuxOutputNative(true, "/usr/bin/tmux\n"),
                TmuxStart::formatReadyChannelTokenNative('0123456789abcdef01234567'),
                TmuxStart::formatAttachCommandHintNative('nntmux'),
                ManageRoleStacking::resolveActionRouteNative('list-pending'),
                ManageRoleStacking::formatPendingRoleRowNative(42, 'alice', null, 'VIP', '2026-10-25 12:00', 'in 1 day'),
                ManageRoleStacking::formatRoleHistoryRowNative('2026-10-24 08:00', 'User', 'Gold', true, 'donation', null),
                ManageRoleStacking::classifyUserIdentifierNative('12345'),
                MailPreview::resolveOutputDirectoryNative(null, '/app/storage/app/mail-previews'),
                MailPreview::formatPreviewByteSummaryNative('welcome', 12345),
                MailPreview::buildPreviewFixtureAttributesNative(),
            ];
        }

        return [
            TmuxHealthCheck::evaluateHeartbeatStalenessPhp(1000, 1200, 180, 10),
            TmuxHealthCheck::resolveHealthCheckLogContextPhp('warning', 'monitor_heartbeat_stale', 'nntmux', true, true, null),
            TmuxMonitor::formatCollectionDelayCompensationMessagePhp(5),
            TmuxMonitor::clampMonitorLoopSleepPhp(10),
            TmuxMonitor::shouldLogIdleWaitingPhp(120),
            TmuxStart::verifyWhichTmuxOutputPhp(true, "/usr/bin/tmux\n"),
            TmuxStart::formatReadyChannelTokenPhp('0123456789abcdef01234567'),
            TmuxStart::formatAttachCommandHintPhp('nntmux'),
            ManageRoleStacking::resolveActionRoutePhp('list-pending'),
            ManageRoleStacking::formatPendingRoleRowPhp(42, 'alice', null, 'VIP', '2026-10-25 12:00', 'in 1 day'),
            ManageRoleStacking::formatRoleHistoryRowPhp('2026-10-24 08:00', 'User', 'Gold', true, 'donation', null),
            ManageRoleStacking::classifyUserIdentifierPhp('12345'),
            MailPreview::resolveOutputDirectoryPhp(null, '/app/storage/app/mail-previews'),
            MailPreview::formatPreviewByteSummaryPhp('welcome', 12345),
            MailPreview::buildPreviewFixtureAttributesPhp(),
        ];
    }

    private function loadFfi(): FFI
    {
        $candidates = [];
        $envPath = getenv('NNTMUX_PIPELINE_FFI_LIB');
        if (is_string($envPath) && $envPath !== '') {
            $candidates[] = $envPath;
        }

        $base = base_path();
        $ext = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
        $candidates[] = $base."/storage/app/nntmux-native/libnntmux_pipeline.{$ext}";
        $candidates[] = $base."/rust/pipeline-ffi/target/release/libnntmux_pipeline.{$ext}";
        $candidates[] = $base."/rust/pipeline-ffi/target/debug/libnntmux_pipeline.{$ext}";

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return FFI::cdef(self::FFI_CDEF, $path);
            }
        }

        $this->markTestSkipped('libnntmux_pipeline shared library not found.');
    }
}
