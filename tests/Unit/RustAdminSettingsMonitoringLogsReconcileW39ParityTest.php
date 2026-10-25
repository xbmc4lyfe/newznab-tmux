<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\NntmuxSearchDiag;
use App\Console\Commands\NntmuxSearchReconcile;
use App\Http\Controllers\Admin\AdminLogViewerController;
use App\Http\Controllers\Admin\AdminMonitoringController;
use App\Http\Controllers\Admin\AdminSettingsController;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;

/**
 * Paired randomized benchmark (always runs; `test_paired_randomized_benchmark_keeps_php_default`):
 * 300 samples x 20 calls per workload, 20 warmup rounds, per-sample random PHP/Rust order, release
 * dylib. See the commit message for the measured run; each run prints its ratios to STDERR.
 *
 * Packet marshaling, FFI scratch access and result decoding dominate these small summaries, so Rust
 * misses the 1.5x gate and every helper keeps PHP as the default.
 */
class RustAdminSettingsMonitoringLogsReconcileW39ParityTest extends TestCase
{
    /** @var list<class-string> */
    private const CLASSES = [
        AdminSettingsController::class,
        AdminMonitoringController::class,
        AdminLogViewerController::class,
        NntmuxSearchReconcile::class,
        NntmuxSearchDiag::class,
    ];

    private const RANDOM_ROUNDS = 1200;

    private const ALPHABET = ['a', 'B', 'e', '0', '1', '9', '-', ' ', "\t", "\n", "\r", "\0", "\x0B", "\x0C", "\xc3", "\xa9", "\xff", '_'];

    private const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency', ' Warning ', "ERROR\n", "\0info", 'trace', 'warn', '', 'Emergency'];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::resetW395RustFfi();
        }
        AdminSettingsController::setUseRustFfi(false);
        AdminMonitoringController::setUseRustFfi(false);
        AdminLogViewerController::setUseRustFfi(false);
        NntmuxSearchReconcile::setUseRustFfi(null);
        (new ReflectionClass(NntmuxSearchReconcile::class))->setStaticPropertyValue('w395Ffi', null);

        parent::tearDown();
    }

    public function test_defaults_are_php_and_toggles_work(): void
    {
        foreach (self::CLASSES as $class) {
            $this->assertFalse((new ReflectionClassConstant($class, 'W395_RUST_FFI_DEFAULT'))->getValue());
            $this->assertFalse($class::usesW395RustFfi(), "{$class} must default to PHP");
            $class::useW395RustFfi(true);
            $this->assertTrue($class::usesW395RustFfi());
            $class::useW395RustFfi(false);
            $this->assertFalse($class::usesW395RustFfi());
            $class::useW395RustFfi();
            $class::resetW395RustFfi();
            $this->assertFalse($class::usesW395RustFfi());
            $class::useW395RustFfi(null);
            $this->assertFalse($class::usesW395RustFfi());
        }

        // Pre-existing toggles are independent of the Wave 39.5 ones.
        foreach (self::CLASSES as $class) {
            $class::useW395RustFfi(true);
        }
        $this->assertFalse(AdminSettingsController::usesRustFfi());
        $this->assertFalse(AdminMonitoringController::usesRustFfi());
        $this->assertFalse(AdminLogViewerController::usesRustFfi());
        $before = NntmuxSearchReconcile::usesRustFfi();
        NntmuxSearchReconcile::resetW395RustFfi();
        $this->assertSame($before, NntmuxSearchReconcile::usesRustFfi());
        AdminSettingsController::setUseRustFfi(true);
        $this->assertTrue(AdminSettingsController::usesW395RustFfi());
        AdminSettingsController::resetW395RustFfi();
        $this->assertTrue(AdminSettingsController::usesRustFfi());
    }

    public function test_settings_diff_examples(): void
    {
        $current = ['title' => 'NNTmux', 'limit' => 10, 'motd' => null, 'flag' => true, 'tags' => ['a'], 7 => 'x'];
        $submitted = ['title' => 'NNTmux', 'limit' => '20', 'motd' => " \t", 'flag' => '1', 'tags' => ['a'], 'bogus' => '', '7' => 'y'];
        $expected = ['total' => 7, 'changed' => 3, 'unchanged' => 3, 'unknown' => 1, 'empty' => 2];
        $this->assertSame($expected, $this->both(AdminSettingsController::class, static fn () => AdminSettingsController::summarizeSettingsDiff($current, $submitted)));
        $this->assertSame(['total' => 0, 'changed' => 0, 'unchanged' => 0, 'unknown' => 0, 'empty' => 0], $this->both(AdminSettingsController::class, static fn () => AdminSettingsController::summarizeSettingsDiff($current, [])));
        $this->assertSame(['total' => 2, 'changed' => 0, 'unchanged' => 0, 'unknown' => 2, 'empty' => 1], $this->both(AdminSettingsController::class, static fn () => AdminSettingsController::summarizeSettingsDiff([], ['a' => false, 'b' => 0.5])));
    }

    public function test_queue_latency_examples(): void
    {
        $this->assertSame(
            ['samples' => 4, 'valid' => 4, 'invalid' => 0, 'min_ms' => 10, 'max_ms' => 5001, 'mean_ms' => 2565.25, 'sla_breaches' => 1, 'healthy' => false],
            $this->both(AdminMonitoringController::class, static fn () => AdminMonitoringController::summarizeQueueLatencySnapshots([10, 5001, 250, 5000])),
        );
        $this->assertSame(
            ['samples' => 6, 'valid' => 2, 'invalid' => 4, 'min_ms' => 1, 'max_ms' => 2, 'mean_ms' => 1.5, 'sla_breaches' => 0, 'healthy' => false],
            $this->both(AdminMonitoringController::class, static fn () => AdminMonitoringController::summarizeQueueLatencySnapshots([-1, '5', 86_400_001, 2.0, 1, 2], 100)),
        );
        $this->assertSame(
            ['samples' => 0, 'valid' => 0, 'invalid' => 0, 'min_ms' => null, 'max_ms' => null, 'mean_ms' => 0.0, 'sla_breaches' => 0, 'healthy' => true],
            $this->both(AdminMonitoringController::class, static fn () => AdminMonitoringController::summarizeQueueLatencySnapshots([], PHP_INT_MIN)),
        );
        $this->assertSame(0.333, $this->both(AdminMonitoringController::class, static fn () => AdminMonitoringController::summarizeQueueLatencySnapshots([0, 0, 1]))['mean_ms']);
    }

    public function test_log_level_examples(): void
    {
        $summary = $this->both(AdminLogViewerController::class, static fn () => AdminLogViewerController::summarizeLogLevelDistribution([' Warning ', 'debug', 'INFO', 'error', 'trace', '', 42, 'emergency'], "warning\n"));
        $this->assertSame([
            'total' => 8,
            'counts' => ['debug' => 1, 'info' => 1, 'notice' => 0, 'warning' => 1, 'error' => 1, 'critical' => 0, 'alert' => 0, 'emergency' => 1],
            'unknown' => 3,
            'highest' => 'emergency',
            'highest_index' => 7,
            'min_severity' => 'warning',
            'at_or_above' => 3,
        ], $summary);

        $empty = $this->both(AdminLogViewerController::class, static fn () => AdminLogViewerController::summarizeLogLevelDistribution(['nope'], 'bogus'));
        $this->assertSame([null, -1, 'debug', 0, 1], [$empty['highest'], $empty['highest_index'], $empty['min_severity'], $empty['at_or_above'], $empty['unknown']]);
    }

    public function test_reconcile_id_diff_examples(): void
    {
        $plan = $this->both(NntmuxSearchReconcile::class, static fn () => NntmuxSearchReconcile::planReconcileIdDiff([5, 3, 1, 3, 9, 0], [3, 7, 8, 'x', 6], 2));
        $this->assertSame([
            'db' => 4,
            'index' => 4,
            'invalid' => 2,
            'missing_in_index' => 3,
            'orphans_in_index' => 3,
            'in_sync' => 1,
            'planned_upserts' => 2,
            'planned_deletes' => 2,
            'upsert_ids' => [1, 5],
            'delete_ids' => [6, 7],
            'truncated' => true,
        ], $plan);

        $none = $this->both(NntmuxSearchReconcile::class, static fn () => NntmuxSearchReconcile::planReconcileIdDiff([1, 2], [2], -5));
        $this->assertSame([1, 0, [], true], [$none['missing_in_index'], $none['planned_upserts'], $none['upsert_ids'], $none['truncated']]);

        $synced = $this->both(NntmuxSearchReconcile::class, static fn () => NntmuxSearchReconcile::planReconcileIdDiff([PHP_INT_MAX, 4], [4, PHP_INT_MAX]));
        $this->assertSame([2, 0, 0, false], [$synced['in_sync'], $synced['missing_in_index'], $synced['orphans_in_index'], $synced['truncated']]);
    }

    public function test_index_shard_health_examples(): void
    {
        $shards = [
            ['online' => true, 'docs' => 100, 'lag' => 0],
            ['online' => true, 'docs' => 40, 'lag' => 501],
            ['online' => false, 'docs' => 7],
            'broken',
            ['online' => 1, 'docs' => '9', 'lag' => 1000],
        ];
        $this->assertSame(
            ['shards' => 5, 'online' => 2, 'offline' => 3, 'malformed' => 1, 'lagging' => 1, 'total_docs' => 147, 'max_lag' => 501, 'cluster_ready' => false],
            $this->both(NntmuxSearchDiag::class, static fn () => NntmuxSearchDiag::summarizeIndexShardHealth($shards)),
        );
        $saturated = [['online' => true, 'docs' => PHP_INT_MAX, 'lag' => -4], ['online' => true, 'docs' => 5, 'lag' => 'x'], ['online' => true, 'lag' => 10]];
        $this->assertSame(
            ['shards' => 3, 'online' => 3, 'offline' => 0, 'malformed' => 0, 'lagging' => 0, 'total_docs' => PHP_INT_MAX, 'max_lag' => 10, 'cluster_ready' => true],
            $this->both(NntmuxSearchDiag::class, static fn () => NntmuxSearchDiag::summarizeIndexShardHealth($saturated, 10)),
        );
        $this->assertFalse($this->both(NntmuxSearchDiag::class, static fn () => NntmuxSearchDiag::summarizeIndexShardHealth([]))['cluster_ready']);
    }

    public function test_randomized_parity_across_helpers(): void
    {
        $this->requireFfi();

        mt_srand(3905);
        $pick = static fn (array $values): mixed => $values[mt_rand(0, \count($values) - 1)];
        $value = static fn (): mixed => match (mt_rand(0, 7)) {
            0 => null,
            1 => (bool) mt_rand(0, 1),
            2 => self::randomInt(),
            3 => mt_rand(0, 3) / 2,
            4 => ['nested' => mt_rand(0, 2)],
            default => self::randomBytes(6),
        };
        $id = static fn (): mixed => match (mt_rand(0, 9)) {
            0 => self::randomInt(),
            1 => (string) mt_rand(1, 40),
            2 => mt_rand(-3, 0),
            default => mt_rand(1, 40),
        };

        for ($i = 0; $i < self::RANDOM_ROUNDS; $i++) {
            $keys = ['a', 'b', 'c', '0', '7', 'site_name', ' a', 'B'];
            $current = [];
            $submitted = [];
            for ($k = mt_rand(0, 6); $k > 0; $k--) {
                $current[$pick($keys)] = $value();
            }
            for ($k = mt_rand(0, 6); $k > 0; $k--) {
                $key = $pick($keys);
                $submitted[$key] = mt_rand(0, 2) === 0 && \array_key_exists($key, $current) ? $current[$key] : $value();
            }
            $this->assertParity(AdminSettingsController::class, static fn () => AdminSettingsController::summarizeSettingsDiff($current, $submitted), [$current, $submitted]);

            $samples = [];
            for ($s = mt_rand(0, 12); $s > 0; $s--) {
                $samples[] = match (mt_rand(0, 9)) {
                    0 => self::randomInt(),
                    1 => (string) mt_rand(0, 100),
                    2 => mt_rand(86_399_990, 86_400_010),
                    default => mt_rand(0, 10_000),
                };
            }
            $sla = mt_rand(0, 3) === 0 ? self::randomInt() : mt_rand(0, 10_000);
            $this->assertParity(AdminMonitoringController::class, static fn () => AdminMonitoringController::summarizeQueueLatencySnapshots($samples, $sla), [$samples, $sla]);

            $levels = [];
            for ($l = mt_rand(0, 12); $l > 0; $l--) {
                $levels[] = mt_rand(0, 5) === 0 ? (mt_rand(0, 1) === 0 ? self::randomBytes(8) : mt_rand(0, 9)) : $pick(self::LEVELS);
            }
            $min = mt_rand(0, 3) === 0 ? self::randomBytes(6) : $pick(self::LEVELS);
            $this->assertParity(AdminLogViewerController::class, static fn () => AdminLogViewerController::summarizeLogLevelDistribution($levels, $min), [$levels, $min]);

            $db = [];
            $index = [];
            for ($n = mt_rand(0, 15); $n > 0; $n--) {
                $db[] = $id();
            }
            for ($n = mt_rand(0, 15); $n > 0; $n--) {
                $index[] = $id();
            }
            $cap = mt_rand(0, 3) === 0 ? self::randomInt() : mt_rand(-1, 8);
            $this->assertParity(NntmuxSearchReconcile::class, static fn () => NntmuxSearchReconcile::planReconcileIdDiff($db, $index, $cap), [$db, $index, $cap]);

            $shards = [];
            for ($n = mt_rand(0, 8); $n > 0; $n--) {
                $shards[] = mt_rand(0, 9) === 0 ? $pick(['x', 3, null]) : array_filter([
                    'online' => $pick([true, true, true, false, 1, 'true', null]),
                    'docs' => mt_rand(0, 5) === 0 ? $pick([self::randomInt(), '12', 1.5]) : mt_rand(0, 10_000),
                    'lag' => mt_rand(0, 5) === 0 ? $pick([self::randomInt(), '12', -7]) : mt_rand(0, 1_000),
                ], static fn (): bool => mt_rand(0, 7) !== 0);
            }
            $maxLag = mt_rand(0, 3) === 0 ? self::randomInt() : mt_rand(0, 1_000);
            $this->assertParity(NntmuxSearchDiag::class, static fn () => NntmuxSearchDiag::summarizeIndexShardHealth($shards, $maxLag), [$shards, $maxLag]);
        }
    }

    public function test_every_mode_is_served_by_rust(): void
    {
        $this->requireFfi();

        $this->assertSame(['2', '1', '0', '1', '1'], NntmuxSearchReconcile::evaluateW395Native(1, ['1', 'a', '1', 'a', '2', 'b', ' ']));
        $this->assertSame(['2', '1', '1', '7', '7', '7', '1', '0'], NntmuxSearchReconcile::evaluateW395Native(2, ['5', '7', '']));
        $this->assertSame(['1', '0', '0', '0', '0', '1', '0', '0', '0', '0', '4', '0', '1'], NntmuxSearchReconcile::evaluateW395Native(3, ['debug', 'error']));
        $this->assertSame(['1', '1', '0', '1', '1', '0', '1', '1', '1', '2'], NntmuxSearchReconcile::evaluateW395Native(4, ['10', '1', '1', '2']));
        $this->assertSame(['1', '1', '0', '0', '0', '3', '4', '1'], NntmuxSearchReconcile::evaluateW395Native(5, ['5', '1', '3', '4']));
        $this->assertNull(NntmuxSearchReconcile::evaluateW395Native(-1, ['x']));
        $this->assertNull(NntmuxSearchReconcile::evaluateW395Native(0, ['x']));
        $this->assertNull(NntmuxSearchReconcile::evaluateW395Native(6, ['x']));
        $this->assertNull(NntmuxSearchReconcile::evaluateW395Native(2, ['+5']));
        $this->assertNull(NntmuxSearchReconcile::evaluateW395Native(5, ['0', 'z', '', '']));
    }

    public function test_native_helpers_fall_back_to_php(): void
    {
        foreach (self::CLASSES as $class) {
            $class::useW395RustFfi(true);
        }
        (new ReflectionClass(NntmuxSearchReconcile::class))->setStaticPropertyValue('w395Ffi', false);

        $this->assertNull(NntmuxSearchReconcile::evaluateW395Native(2, ['5']));
        $this->assertSame(1, AdminSettingsController::summarizeSettingsDiff(['a' => '1'], ['a' => '2'])['changed']);
        $this->assertSame(1, AdminMonitoringController::summarizeQueueLatencySnapshots([6000])['sla_breaches']);
        $this->assertSame('error', AdminLogViewerController::summarizeLogLevelDistribution(['error'])['highest']);
        $this->assertSame([1], NntmuxSearchReconcile::planReconcileIdDiff([1], [])['upsert_ids']);
        $this->assertTrue(NntmuxSearchDiag::summarizeIndexShardHealth([['online' => true]])['cluster_ready']);

        $missing = RustPipelineLibrary::resolveDomain('search_w395', NntmuxSearchReconcile::W395_FFI_CDEF, sys_get_temp_dir().'/nntmux-missing-'.bin2hex(random_bytes(4)).'.dylib');
        $this->assertNull($missing);
    }

    public function test_direct_c_abi_declines_invalid_input_and_reports_needed_capacity(): void
    {
        $ffi = $this->requireFfi();

        $valid = self::packet(['5000', '1', '2', '3']);
        $buf = $ffi->new('char[1024]');
        $small = $ffi->new('char[8]');
        for ($i = 0; $i < 8; $i++) {
            $small[$i] = 'Z';
        }

        $call = static fn (int $mode, ?string $input, int $len, mixed $out, int $cap): int => (int) $ffi->nntmux_w39_admin_settings_monitoring_logs_reconcile($mode, $input, $len, $out, $cap); // @phpstan-ignore method.notFound
        $callParts = static function (int $mode, array $parts) use ($call, $small): int {
            $packet = self::packet($parts);

            return $call($mode, $packet, strlen($packet), $small, 8);
        };

        $written = $call(2, $valid, strlen($valid), $buf, 1024);
        $this->assertGreaterThan(8, $written);
        $expected = NntmuxSearchReconcile::evaluateW395Native(2, ['5000', '1', '2', '3']);
        $this->assertNotNull($expected);
        $this->assertSame(self::packet($expected), FFI::string($buf, $written));

        // Tiny capacity: -required without touching the buffer, then a retry with that capacity succeeds.
        $this->assertSame(-$written, $call(2, $valid, strlen($valid), $small, 8));
        $this->assertSame(-$written, $call(2, $valid, strlen($valid), null, 0));
        $this->assertSame('ZZZZZZZZ', FFI::string($small, 8));
        $exact = $ffi->new("char[{$written}]");
        $this->assertSame($written, $call(2, $valid, strlen($valid), $exact, $written));
        $this->assertSame(self::packet($expected), FFI::string($exact, $written));

        // Overlapping input/output buffers decline without writing.
        $shared = $ffi->new('char[256]');
        FFI::memcpy($shared, $valid, strlen($valid));
        $this->assertSame(-1, (int) $ffi->nntmux_w39_admin_settings_monitoring_logs_reconcile(2, $shared, strlen($valid), $shared, 256)); // @phpstan-ignore method.notFound
        $this->assertSame($valid, FFI::string($shared, strlen($valid)));

        // Null pointers with non-zero lengths, malformed packets and unknown modes.
        $this->assertSame(-1, $call(2, null, 4, $buf, 1024));
        $this->assertSame(-1, $call(2, $valid, strlen($valid), null, 8));
        foreach ([0, 6, 99] as $mode) {
            $this->assertSame(-1, $call($mode, $valid, strlen($valid), $small, 8));
        }
        $this->assertSame(-1, $call(2, '', 0, $small, 8));
        $this->assertSame(-1, $call(2, "\x01\0\0", 3, $small, 8));
        $this->assertSame(-1, $call(2, "\x01\0\0\0\x09\0\0\0", 8, $small, 8));
        $this->assertSame(-1, $call(2, $valid."\0", strlen($valid) + 1, $small, 8));

        // Non-canonical integers and wrong field counts.
        $this->assertSame(-1, $callParts(1, ['2', 'a', '1']));
        $this->assertSame(-1, $callParts(1, ['0', 'a']));
        $this->assertSame(-1, $callParts(1, ['-1']));
        $this->assertSame(-1, $callParts(2, ['05', '1']));
        $this->assertSame(-1, $callParts(2, []));
        $this->assertSame(-1, $callParts(3, []));
        $this->assertSame(-1, $callParts(4, ['1', '3', '1']));
        $this->assertSame(-1, $callParts(4, ['1']));
        $this->assertSame(-1, $callParts(4, ['1', '99999999999999999999']));
        $this->assertSame(-1, $callParts(5, ['0', '1', '1']));
        $this->assertSame(-1, $callParts(5, ['0', '2', '1', '1']));
        $this->assertSame('ZZZZZZZZ', FFI::string($small, 8));
    }

    public function test_paired_randomized_benchmark_keeps_php_default(): void
    {
        $this->requireFfi();

        $current = ['title' => 'NNTmux', 'limit' => 100, 'motd' => '', 'register' => true];
        $submitted = ['title' => 'NNTmux', 'limit' => '200', 'motd' => ' ', 'register' => '1'];
        $common = static function () use ($current, $submitted): void {
            AdminSettingsController::summarizeSettingsDiff($current, $submitted);
            AdminMonitoringController::summarizeQueueLatencySnapshots([120, 340, 90, 5100, 410]);
            AdminLogViewerController::summarizeLogLevelDistribution(['info', 'info', 'error', 'debug', 'warning'], 'warning');
            NntmuxSearchReconcile::planReconcileIdDiff([1, 2, 3, 4, 5], [2, 3, 4, 6]);
            NntmuxSearchDiag::summarizeIndexShardHealth([['online' => true, 'docs' => 1000, 'lag' => 2], ['online' => true, 'docs' => 980, 'lag' => 30]]);
        };

        $bigCurrent = [];
        $bigSubmitted = [];
        $samples = [];
        $levels = [];
        $db = [];
        $index = [];
        $shards = [];
        for ($i = 0; $i < 2000; $i++) {
            $bigCurrent["setting_{$i}"] = $i % 7 === 0 ? null : "value {$i}";
            $bigSubmitted[$i % 11 === 0 ? "extra_{$i}" : "setting_{$i}"] = $i % 3 === 0 ? "value {$i}" : " changed {$i} ";
            $samples[] = $i % 97 === 0 ? -1 : ($i * 37) % 9000;
            $levels[] = self::LEVELS[$i % \count(self::LEVELS)];
            $db[] = $i * 2 + 1;
            $index[] = $i % 13 === 0 ? 'bad' : $i * 3 + 1;
            if ($i < 256) {
                $shards[] = ['online' => $i % 17 !== 0, 'docs' => $i * 10_000, 'lag' => ($i * 31) % 900];
            }
        }
        $uncommon = static function () use ($bigCurrent, $bigSubmitted, $samples, $levels, $db, $index, $shards): void {
            AdminSettingsController::summarizeSettingsDiff($bigCurrent, $bigSubmitted);
            AdminMonitoringController::summarizeQueueLatencySnapshots($samples, 4000);
            AdminLogViewerController::summarizeLogLevelDistribution($levels, 'error');
            NntmuxSearchReconcile::planReconcileIdDiff($db, $index, 500);
            NntmuxSearchDiag::summarizeIndexShardHealth($shards, 600);
        };

        $setAll = static function (bool $rust): void {
            foreach (self::CLASSES as $class) {
                $class::useW395RustFfi($rust);
            }
        };

        $results = [];
        foreach (['common' => [$common, 20], 'uncommon' => [$uncommon, 2]] as $name => [$workload, $calls]) {
            for ($w = 0; $w < 20; $w++) {
                foreach ([false, true] as $rust) {
                    $setAll($rust);
                    $workload();
                }
            }

            $times = ['php' => [], 'rust' => []];
            for ($s = 0; $s < 300; $s++) {
                $order = mt_rand(0, 1) === 1 ? [true, false] : [false, true];
                foreach ($order as $rust) {
                    $setAll($rust);
                    $t0 = hrtime(true);
                    for ($i = 0; $i < $calls; $i++) {
                        $workload();
                    }
                    $times[$rust ? 'rust' : 'php'][] = (hrtime(true) - $t0) / ($calls * 1000);
                }
            }

            $results[$name] = [
                'mean' => self::mean($times['php']) / max(1e-9, self::mean($times['rust'])),
                'p99' => self::p99($times['php']) / max(1e-9, self::p99($times['rust'])),
                'php_mean_us' => self::mean($times['php']),
                'rust_mean_us' => self::mean($times['rust']),
                'php_p99_us' => self::p99($times['php']),
                'rust_p99_us' => self::p99($times['rust']),
            ];
        }

        foreach (self::CLASSES as $class) {
            $class::resetW395RustFfi();
        }

        fwrite(STDERR, sprintf(
            "\n[Wave 39.5 benchmark] common mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx (PHP %.2fus / Rust %.2fus) | uncommon mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx (PHP %.2fus / Rust %.2fus)\n",
            $results['common']['mean'],
            $results['common']['php_mean_us'],
            $results['common']['rust_mean_us'],
            $results['common']['p99'],
            $results['common']['php_p99_us'],
            $results['common']['rust_p99_us'],
            $results['uncommon']['mean'],
            $results['uncommon']['php_mean_us'],
            $results['uncommon']['rust_mean_us'],
            $results['uncommon']['p99'],
            $results['uncommon']['php_p99_us'],
            $results['uncommon']['rust_p99_us'],
        ));

        $qualifies = $results['common']['mean'] >= 1.5 && $results['common']['p99'] >= 1.5
            && $results['uncommon']['mean'] >= 1.5 && $results['uncommon']['p99'] >= 1.5;
        foreach (self::CLASSES as $class) {
            if (! $qualifies) {
                $this->assertFalse($class::usesW395RustFfi(), "{$class} must keep PHP when Rust is < 1.5x");
            } else {
                $this->assertIsBool($class::usesW395RustFfi());
            }
        }
    }

    /**
     * Runs $plan with PHP then Rust, asserts identical results and returns the PHP result.
     *
     * @template T
     *
     * @param  class-string  $class
     * @param  callable(): T  $plan
     * @return T
     */
    private function both(string $class, callable $plan): mixed
    {
        $class::useW395RustFfi(false);
        $php = $plan();
        $class::useW395RustFfi(true);
        $rust = $plan();
        $class::resetW395RustFfi();
        $this->assertSame($php, $rust);

        return $php;
    }

    /**
     * @param  class-string  $class
     * @param  list<mixed>  $args
     */
    private function assertParity(string $class, callable $plan, array $args): void
    {
        $class::useW395RustFfi(false);
        $php = $plan();
        $class::useW395RustFfi(true);
        $rust = $plan();
        $class::resetW395RustFfi();
        $this->assertSame($php, $rust, bin2hex(serialize($args)));
    }

    private static function randomBytes(int $maxLength): string
    {
        $out = '';
        for ($i = mt_rand(0, $maxLength); $i > 0; $i--) {
            $out .= self::ALPHABET[mt_rand(0, \count(self::ALPHABET) - 1)];
        }

        return $out;
    }

    private static function randomInt(): int
    {
        return match (mt_rand(0, 3)) {
            0 => mt_rand(PHP_INT_MIN, PHP_INT_MAX),
            1 => [PHP_INT_MIN, PHP_INT_MAX, 0, -1, 1, PHP_INT_MAX - 1][mt_rand(0, 5)],
            default => mt_rand(-2, 50_000),
        };
    }

    /**
     * @param  list<string>  $parts
     */
    private static function packet(array $parts): string
    {
        $packet = pack('V', count($parts));
        foreach ($parts as $part) {
            $packet .= pack('V', strlen($part)).$part;
        }

        return $packet;
    }

    /**
     * @param  list<float>  $values
     */
    private static function mean(array $values): float
    {
        return array_sum($values) / max(1, count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private static function p99(array $values): float
    {
        sort($values);

        return $values[(int) min(count($values) - 1, (int) ceil(0.99 * count($values)) - 1)];
    }

    private function requireFfi(): FFI
    {
        $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $ffi = RustPipelineLibrary::resolveDomain('search_w395', NntmuxSearchReconcile::W395_FFI_CDEF, is_string($path) && $path !== '' ? $path : null);
        if ($ffi === null) {
            $this->markTestSkipped('Rust search FFI library is not built or available.');
        }

        return $ffi;
    }
}
