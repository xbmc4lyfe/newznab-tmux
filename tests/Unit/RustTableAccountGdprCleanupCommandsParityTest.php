<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\NntmuxDeleteUnVerifiedUsers;
use App\Console\Commands\NntmuxOptimizeTables;
use App\Console\Commands\NntmuxPurgeDeletedAccounts;
use App\Console\Commands\NntmuxUpdateExpiredRoles;
use App\Console\Commands\PurgeExpiredGdprExports;
use FFI;
use FFI\Exception;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\TestCase;

final class RustTableAccountGdprCleanupCommandsParityTest extends TestCase
{
    private const FFI_CDEF = <<<'CDEF'
int32_t nntmux_w32_table_account_gdpr_cleanup_commands(
    const char *input,
    size_t len,
    uint8_t *out,
    size_t out_cap
);
CDEF;

    /** @var list<class-string> */
    private const COMMANDS = [
        NntmuxOptimizeTables::class,
        NntmuxDeleteUnVerifiedUsers::class,
        NntmuxPurgeDeletedAccounts::class,
        NntmuxUpdateExpiredRoles::class,
        PurgeExpiredGdprExports::class,
    ];

    protected function tearDown(): void
    {
        foreach (self::COMMANDS as $command) {
            $command::resetRustFfi();
        }
        parent::tearDown();
    }

    public function test_rust_ffi_is_disabled_by_default_and_toggles(): void
    {
        foreach (self::COMMANDS as $command) {
            $this->assertFalse($command::usesRustFfi(), $command);
            $command::setUseRustFfi(true);
            $this->assertTrue($command::usesRustFfi(), $command);
            $command::setUseRustFfi(false);
            $this->assertFalse($command::usesRustFfi(), $command);
            $command::setUseRustFfi(null);
            $this->assertFalse($command::usesRustFfi(), $command);
        }
    }

    public function test_php_helpers_preserve_existing_command_output(): void
    {
        $this->assertSame(
            ['optimize_all' => true, 'tables' => [], 'force' => false, 'free_threshold_percent' => 5],
            NntmuxOptimizeTables::normalizeOptimizeRequestPhp(false, [])
        );
        $this->assertTrue(NntmuxOptimizeTables::normalizeOptimizeRequestPhp(false, ['all', 'parts'])['optimize_all']);
        $this->assertSame(['parts', 'all'], NntmuxOptimizeTables::normalizeOptimizeRequestPhp(false, ['parts', 'all'])['tables']);
        $this->assertSame('OPTIMIZE TABLE releases', NntmuxOptimizeTables::buildOptimizeTableSqlPhp('releases'));

        $this->assertSame(['days' => 3, 'start_message' => 'Deleting unverified users.'], NntmuxDeleteUnVerifiedUsers::normalizeCleanupWindowPhp());
        $this->assertSame('Unverified users deleted.', NntmuxDeleteUnVerifiedUsers::formatDeleteSummaryPhp(NntmuxDeleteUnVerifiedUsers::UNKNOWN_COUNT));

        $purge = NntmuxPurgeDeletedAccounts::normalizePurgeSummaryPhp(false, 180, 0);
        $this->assertSame('Starting to purge accounts that were soft deleted 6 months ago...', $purge['start_message']);
        $this->assertSame('Job dispatched to purge deleted accounts', $purge['finish_message']);

        $plan = NntmuxUpdateExpiredRoles::normalizeRoleExpirationPlanPhp(null, 1);
        $this->assertTrue($plan['valid']);
        $this->assertSame(['Updating expired roles.', 'Updating users that will have their roles expire or are already expired'], $plan['messages']);
        $this->assertSame('Expired roles updated.', NntmuxUpdateExpiredRoles::formatExpiredRolesSummaryPhp(NntmuxUpdateExpiredRoles::UNKNOWN_COUNT));

        $this->assertSame(
            ['success' => true, 'level' => 'info', 'message' => 'Purged 4 expired GDPR export file(s).', 'exit_code' => 0],
            PurgeExpiredGdprExports::summarizeExportPurgeResultPhp(4)
        );
        $this->assertSame(
            ['success' => false, 'level' => 'error', 'message' => 'Failed to purge expired GDPR exports: boom', 'exit_code' => 1],
            PurgeExpiredGdprExports::summarizeExportPurgeResultPhp(0, 'boom')
        );
    }

    public function test_rust_evaluates_requests_without_falling_back(): void
    {
        $ffi = $this->loadFfi();
        $cases = [
            [['optimize_tables.normalize_request', true, ['parts', 'binaries'], 12], NntmuxOptimizeTables::normalizeOptimizeRequestPhp(true, ['parts', 'binaries'], 12)],
            [['optimize_tables.normalize_request', false, [], 5], NntmuxOptimizeTables::normalizeOptimizeRequestPhp(false, [])],
            [['optimize_tables.build_optimize_sql', '`release_files`'], NntmuxOptimizeTables::buildOptimizeTableSqlPhp('`release_files`')],
            [['delete_unverified.normalize_cleanup_window', 0], NntmuxDeleteUnVerifiedUsers::normalizeCleanupWindowPhp(0)],
            [['delete_unverified.format_delete_summary', 9], NntmuxDeleteUnVerifiedUsers::formatDeleteSummaryPhp(9)],
            [['purge_deleted.normalize_summary', true, 180, 12], NntmuxPurgeDeletedAccounts::normalizePurgeSummaryPhp(true, 180, 12)],
            [['update_expired_roles.normalize_plan', '2024-02-29', 1], NntmuxUpdateExpiredRoles::normalizeRoleExpirationPlanPhp('2024-02-29', 1)],
            [['update_expired_roles.format_summary', 2], NntmuxUpdateExpiredRoles::formatExpiredRolesSummaryPhp(2)],
            [['gdpr_exports.summarize_purge_result', 3, null], PurgeExpiredGdprExports::summarizeExportPurgeResultPhp(3)],
            [['gdpr_exports.summarize_purge_result', 0, 'é😀 "x"'], PurgeExpiredGdprExports::summarizeExportPurgeResultPhp(0, 'é😀 "x"')],
        ];
        foreach ($cases as [$request, $expected]) {
            $this->assertSame([$expected], $this->callRust($ffi, $request), (string) json_encode($request));
        }

        foreach ([
            [],
            ['unknown'],
            ['optimize_tables.normalize_request', false, [1], 5],
            ['optimize_tables.normalize_request', false, ['a' => 'b'], 5],
            ['optimize_tables.normalize_request', false, [], 5.0],
            ['optimize_tables.build_optimize_sql', null],
            ['delete_unverified.format_delete_summary', '1'],
            ['purge_deleted.normalize_summary', 1, 180, 0],
            ['update_expired_roles.normalize_plan', 20240101, 1],
            ['gdpr_exports.summarize_purge_result', 0],
            ['gdpr_exports.summarize_purge_result', 0, false],
        ] as $request) {
            $this->assertNull($this->callRust($ffi, $request), (string) json_encode($request));
        }
    }

    public function test_optimize_tables_helpers_match_php(): void
    {
        $this->loadFfi();
        $tableSets = [
            [], ['all'], ['all', 'parts'], ['ALL'], [' all'], ['parts', 'all'], ['parts'], ['', 'x'],
            ["tab\0le", 'é😀'], ['a' => 'parts'], [1 => 'parts'], [null], [0], ['parts', 5],
        ];
        foreach ($tableSets as $tables) {
            foreach ([true, false] as $force) {
                foreach ([PHP_INT_MIN, -1, 0, 5, 100, 101, PHP_INT_MAX] as $threshold) {
                    $this->assertSame(
                        NntmuxOptimizeTables::normalizeOptimizeRequestPhp($force, $tables, $threshold),
                        NntmuxOptimizeTables::normalizeOptimizeRequestNative($force, $tables, $threshold),
                        json_encode($tables).' '.$threshold
                    );
                }
            }
        }
        $this->assertFalse(NntmuxOptimizeTables::normalizeOptimizeRequestPhp(false, [1 => 'all'])['optimize_all']);

        foreach (['releases', '', '`a`.`b`', "x\0y", 'é😀', "bad\xff"] as $name) {
            $this->assertSame(NntmuxOptimizeTables::buildOptimizeTableSqlPhp($name), NntmuxOptimizeTables::buildOptimizeTableSqlNative($name));
        }
    }

    public function test_account_cleanup_helpers_match_php(): void
    {
        $this->loadFfi();
        foreach ([PHP_INT_MIN, -1, 0, 1, 3, 30, 36500, 36501, PHP_INT_MAX] as $days) {
            $this->assertSame(NntmuxDeleteUnVerifiedUsers::normalizeCleanupWindowPhp($days), NntmuxDeleteUnVerifiedUsers::normalizeCleanupWindowNative($days), (string) $days);
        }
        foreach ([PHP_INT_MIN, -2, -1, 0, 1, 42, PHP_INT_MAX] as $count) {
            $this->assertSame(NntmuxDeleteUnVerifiedUsers::formatDeleteSummaryPhp($count), NntmuxDeleteUnVerifiedUsers::formatDeleteSummaryNative($count));
            $this->assertSame(NntmuxUpdateExpiredRoles::formatExpiredRolesSummaryPhp($count), NntmuxUpdateExpiredRoles::formatExpiredRolesSummaryNative($count));
            foreach ([true, false] as $dryRun) {
                foreach ([PHP_INT_MIN, -1, 0, 180, PHP_INT_MAX] as $retention) {
                    $this->assertSame(
                        NntmuxPurgeDeletedAccounts::normalizePurgeSummaryPhp($dryRun, $retention, $count),
                        NntmuxPurgeDeletedAccounts::normalizePurgeSummaryNative($dryRun, $retention, $count)
                    );
                }
            }
        }
    }

    public function test_role_expiration_plans_match_php(): void
    {
        $this->loadFfi();
        $dates = [
            null, '', ' ', "\t\n\r\0\x0B", '2024-02-29', '2023-02-29', '1900-02-29', '2000-02-29', '0000-01-01',
            '0001-01-01', '9999-12-31', '2024-13-01', '2024-00-10', '2024-04-31', '2024-1-01', '2024/01/01',
            " 2024-01-01\n", "2024-01-01\n", "2024-01-01\nx", '20240-01-01', '２024-01-01', 'tomorrow', "\xff", "\u{a0}2024-01-01",
        ];
        foreach ($dates as $date) {
            foreach ([PHP_INT_MIN, -1, 0, 1, 2, PHP_INT_MAX] as $role) {
                $this->assertSame(
                    NntmuxUpdateExpiredRoles::normalizeRoleExpirationPlanPhp($date, $role),
                    NntmuxUpdateExpiredRoles::normalizeRoleExpirationPlanNative($date, $role),
                    var_export($date, true).' '.$role
                );
            }
        }
        $this->assertSame('2024-02-29', NntmuxUpdateExpiredRoles::normalizeRoleExpirationPlanPhp(" 2024-02-29\t", 1)['run_date']);
        $this->assertFalse(NntmuxUpdateExpiredRoles::normalizeRoleExpirationPlanPhp('2023-02-29', 1)['valid']);
        $this->assertFalse(NntmuxUpdateExpiredRoles::normalizeRoleExpirationPlanPhp(null, 0)['valid']);
    }

    public function test_gdpr_export_summaries_match_php(): void
    {
        $this->loadFfi();
        foreach ([PHP_INT_MIN, -1, 0, 1, 99, PHP_INT_MAX] as $count) {
            foreach ([null, '', 'disk full', "line\nbreak \"quoted\" \\ /", "nul\0byte", 'é😀', "bad\xff"] as $error) {
                $this->assertSame(
                    PurgeExpiredGdprExports::summarizeExportPurgeResultPhp($count, $error),
                    PurgeExpiredGdprExports::summarizeExportPurgeResultNative($count, $error),
                    var_export($error, true)
                );
            }
        }
    }

    public function test_randomized_inputs_match_php(): void
    {
        $this->loadFfi();
        $random = new Randomizer(new Mt19937(3201));
        $alphabet = "abcXYZ019 _-.`\t\n\0é😀";
        for ($i = 0; $i < 300; $i++) {
            $int = $random->getInt(-50, 50_000);
            $text = $this->randomString($random, $alphabet, $random->getInt(0, 12));
            $tables = [];
            for ($t = 0, $n = $random->getInt(0, 4); $t < $n; $t++) {
                $tables[] = $random->getInt(0, 4) === 0 ? 'all' : $this->randomString($random, $alphabet, $random->getInt(0, 8));
            }
            $flag = $random->getInt(0, 1) === 1;
            $date = sprintf('%04d-%02d-%02d', $random->getInt(0, 2100), $random->getInt(0, 13), $random->getInt(0, 32));

            $this->assertSame(NntmuxOptimizeTables::normalizeOptimizeRequestPhp($flag, $tables, $int), NntmuxOptimizeTables::normalizeOptimizeRequestNative($flag, $tables, $int));
            $this->assertSame(NntmuxOptimizeTables::buildOptimizeTableSqlPhp($text), NntmuxOptimizeTables::buildOptimizeTableSqlNative($text));
            $this->assertSame(NntmuxDeleteUnVerifiedUsers::normalizeCleanupWindowPhp($int), NntmuxDeleteUnVerifiedUsers::normalizeCleanupWindowNative($int));
            $this->assertSame(NntmuxDeleteUnVerifiedUsers::formatDeleteSummaryPhp($int), NntmuxDeleteUnVerifiedUsers::formatDeleteSummaryNative($int));
            $this->assertSame(NntmuxPurgeDeletedAccounts::normalizePurgeSummaryPhp($flag, $int, $int - 7), NntmuxPurgeDeletedAccounts::normalizePurgeSummaryNative($flag, $int, $int - 7));
            $this->assertSame(NntmuxUpdateExpiredRoles::normalizeRoleExpirationPlanPhp($date, $int), NntmuxUpdateExpiredRoles::normalizeRoleExpirationPlanNative($date, $int), $date);
            $this->assertSame(NntmuxUpdateExpiredRoles::formatExpiredRolesSummaryPhp($int), NntmuxUpdateExpiredRoles::formatExpiredRolesSummaryNative($int));
            $error = $flag ? $text : null;
            $this->assertSame(PurgeExpiredGdprExports::summarizeExportPurgeResultPhp($int, $error), PurgeExpiredGdprExports::summarizeExportPurgeResultNative($int, $error));
        }
    }

    public function test_dispatchers_follow_the_toggle_and_fall_back_without_the_library(): void
    {
        $this->loadFfi();
        $outputs = [];
        foreach ([false, true] as $useRust) {
            foreach (self::COMMANDS as $command) {
                $command::setUseRustFfi($useRust);
            }
            $outputs[] = $this->runDispatchers();
        }
        $this->assertSame($outputs[0], $outputs[1]);

        foreach (self::COMMANDS as $command) {
            $command::setUseRustFfi(true);
            $command::setFfiForTesting(false);
        }
        $this->assertSame($outputs[0], $this->runDispatchers());
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark including JSON encoding, the FFI call and decoding
        // (PHP 8.5, Apple Silicon, release dylib; 41 samples x 200 iterations, 5 warmups):
        // - common (the 8 helpers on typical scheduled-command inputs) and uncommon
        //   (long table lists, invalid dates, long error messages) workloads are measured separately;
        //   the latest run's mean/p99 speedups are printed to STDERR. Recorded run, per 200-iteration sample:
        // - common:   PHP mean 0.072 ms (p99 0.086 ms), Rust FFI mean 1.872 ms (p99 2.311 ms) -> mean 0.04x, p99 0.04x
        // - uncommon: PHP mean 0.116 ms (p99 0.173 ms), Rust FFI mean 2.495 ms (p99 3.010 ms) -> mean 0.05x, p99 0.06x
        // The FFI round trip dwarfs the microsecond PHP work (< 1.5x in `.ai/rules/rust-performance.md`),
        // so all five commands keep PHP by default and Rust remains opt-in through setUseRustFfi(true).
        $this->loadFfi();
        $random = new Randomizer(new Mt19937(3202));
        $results = [];
        foreach (['common', 'uncommon'] as $workload) {
            $results[$workload] = $this->benchmark($workload, $random);
        }

        foreach ($results as $workload => $result) {
            fwrite(STDERR, sprintf(
                "\n[w32.1 %s] PHP mean %.3f ms p99 %.3f ms | Rust mean %.3f ms p99 %.3f ms | speedup mean %.2fx p99 %.2fx",
                $workload, $result['php_mean'], $result['php_p99'], $result['rust_mean'], $result['rust_p99'], $result['speedup_mean'], $result['speedup_p99']
            ));
            $this->assertGreaterThan(0.0, $result['php_mean']);
            $this->assertGreaterThan(0.0, $result['rust_mean']);
        }

        $meetsThreshold = $results['common']['speedup_mean'] >= 1.5 && $results['common']['speedup_p99'] >= 1.5
            && $results['uncommon']['speedup_mean'] >= 1.5 && $results['uncommon']['speedup_p99'] >= 1.5;
        if (! $meetsThreshold) {
            foreach (self::COMMANDS as $command) {
                $this->assertFalse($command::usesRustFfi(), $command.' must keep PHP by default below the 1.5x threshold');
            }
        }
    }

    /**
     * @return array{php_mean: float, php_p99: float, rust_mean: float, rust_p99: float, speedup_mean: float, speedup_p99: float}
     */
    private function benchmark(string $workload, Randomizer $random): array
    {
        $warmup = 5;
        $samples = 41;
        $iterations = 200;
        $sink = 0;
        for ($w = 0; $w < $warmup; $w++) {
            $sink += count($this->runWorkloadOnce($workload, false)) + count($this->runWorkloadOnce($workload, true));
        }

        $phpTimes = [];
        $rustTimes = [];
        for ($s = 0; $s < $samples; $s++) {
            foreach ($random->getInt(0, 1) === 1 ? [true, false] : [false, true] as $useRust) {
                $t0 = hrtime(true);
                for ($i = 0; $i < $iterations; $i++) {
                    $sink += count($this->runWorkloadOnce($workload, $useRust));
                }
                $elapsed = (hrtime(true) - $t0) / 1_000_000;
                if ($useRust) {
                    $rustTimes[] = $elapsed;
                } else {
                    $phpTimes[] = $elapsed;
                }
            }
        }
        $this->assertGreaterThan(0, $sink);
        sort($phpTimes);
        sort($rustTimes);
        $p99 = (int) min($samples - 1, (int) ceil($samples * 0.99) - 1);
        $phpMean = array_sum($phpTimes) / $samples;
        $rustMean = array_sum($rustTimes) / $samples;

        return [
            'php_mean' => $phpMean,
            'php_p99' => $phpTimes[$p99],
            'rust_mean' => $rustMean,
            'rust_p99' => $rustTimes[$p99],
            'speedup_mean' => $phpMean / max($rustMean, 1e-9),
            'speedup_p99' => $phpTimes[$p99] / max($rustTimes[$p99], 1e-9),
        ];
    }

    /**
     * @return list<mixed>
     */
    private function runDispatchers(): array
    {
        return [
            NntmuxOptimizeTables::normalizeOptimizeRequest(false, ['parts', 'binaries']),
            NntmuxOptimizeTables::normalizeOptimizeRequest(false, ['all']),
            NntmuxOptimizeTables::buildOptimizeTableSql('releases'),
            NntmuxDeleteUnVerifiedUsers::normalizeCleanupWindow(),
            NntmuxDeleteUnVerifiedUsers::formatDeleteSummary(NntmuxDeleteUnVerifiedUsers::UNKNOWN_COUNT),
            NntmuxPurgeDeletedAccounts::normalizePurgeSummary(false, 180, 0),
            NntmuxUpdateExpiredRoles::normalizeRoleExpirationPlan(null, 1),
            NntmuxUpdateExpiredRoles::formatExpiredRolesSummary(5),
            PurgeExpiredGdprExports::summarizeExportPurgeResult(2),
            PurgeExpiredGdprExports::summarizeExportPurgeResult(0, 'disk full'),
        ];
    }

    /**
     * @return list<mixed>
     */
    private function runWorkloadOnce(string $workload, bool $useRust): array
    {
        if ($workload === 'common') {
            $tables = [];
            $date = null;
            $error = null;
            $count = 3;
        } else {
            $tables = ['parts', 'binaries', 'collections', 'missed_parts', 'releases', 'release_files', 'predb', 'users'];
            $date = '2023-02-29';
            $error = str_repeat('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away ', 8);
            $count = PHP_INT_MAX;
        }
        if ($useRust) {
            return [
                NntmuxOptimizeTables::normalizeOptimizeRequestNative(false, $tables),
                NntmuxOptimizeTables::buildOptimizeTableSqlNative('release_files'),
                NntmuxDeleteUnVerifiedUsers::normalizeCleanupWindowNative(3),
                NntmuxDeleteUnVerifiedUsers::formatDeleteSummaryNative($count),
                NntmuxPurgeDeletedAccounts::normalizePurgeSummaryNative(false, 180, $count),
                NntmuxUpdateExpiredRoles::normalizeRoleExpirationPlanNative($date, 1),
                NntmuxUpdateExpiredRoles::formatExpiredRolesSummaryNative($count),
                PurgeExpiredGdprExports::summarizeExportPurgeResultNative($count, $error),
            ];
        }

        return [
            NntmuxOptimizeTables::normalizeOptimizeRequestPhp(false, $tables),
            NntmuxOptimizeTables::buildOptimizeTableSqlPhp('release_files'),
            NntmuxDeleteUnVerifiedUsers::normalizeCleanupWindowPhp(3),
            NntmuxDeleteUnVerifiedUsers::formatDeleteSummaryPhp($count),
            NntmuxPurgeDeletedAccounts::normalizePurgeSummaryPhp(false, 180, $count),
            NntmuxUpdateExpiredRoles::normalizeRoleExpirationPlanPhp($date, 1),
            NntmuxUpdateExpiredRoles::formatExpiredRolesSummaryPhp($count),
            PurgeExpiredGdprExports::summarizeExportPurgeResultPhp($count, $error),
        ];
    }

    private function randomString(Randomizer $random, string $alphabet, int $length): string
    {
        $chars = mb_str_split($alphabet);
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $chars[$random->getInt(0, count($chars) - 1)];
        }

        return $out;
    }

    /**
     * @param  list<mixed>  $request
     * @return list<mixed>|null
     */
    private function callRust(FFI $ffi, array $request): ?array
    {
        $json = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $capacity = max(4096, strlen($json) * 4);
        $buffer = $ffi->new("uint8_t[{$capacity}]");
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_w32_table_account_gdpr_cleanup_commands($json, strlen($json), $buffer, $capacity);
        if ($written === -1) {
            return null;
        }
        $this->assertIsInt($written);
        $this->assertGreaterThan(0, $written);
        $decoded = json_decode(FFI::string($buffer, $written), true, 8, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->assertTrue(array_is_list($decoded));

        return $decoded;
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

        foreach ($candidates as $path) {
            if (is_file($path)) {
                try {
                    $ffi = FFI::cdef(self::FFI_CDEF, $path);
                } catch (Exception) {
                    continue;
                }
                // Native helpers must exercise this library rather than silently falling back to PHP.
                foreach (self::COMMANDS as $command) {
                    $command::setFfiForTesting($ffi);
                }

                return $ffi;
            }
        }

        $this->markTestSkipped('libnntmux_pipeline shared library with the wave 32.1 export not found.');
    }
}
