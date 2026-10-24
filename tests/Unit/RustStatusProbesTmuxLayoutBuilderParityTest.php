<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\NNTP\NNTPService;
use App\Services\StatusProbes\NntpProbe;
use App\Services\StatusProbes\ProbeResult;
use App\Services\StatusProbes\QueueProbe;
use App\Services\StatusProbes\RedisProbe;
use App\Services\StatusProbes\SearchProbe;
use App\Services\Tmux\TmuxLayoutBuilder;
use App\Services\Tmux\TmuxSessionManager;
use App\Support\RustPipelineLibrary;
use Closure;
use DariusIII\NetNntp\Error as NntpError;
use FFI;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\SyncQueue;
use Tests\TestCase;

/**
 * Parity, C-ABI safety contract, fallback, and paired benchmark verification for Wave 21 Slice 21.6:
 * RedisProbe, NntpProbe, QueueProbe, SearchProbe, and TmuxLayoutBuilder (`rust/pipeline-ffi/src/media.rs`).
 *
 * Benchmarks run with NNTMUX_STATUS_PROBES_TMUX_LAYOUT_BUILDER_BENCH=1:
 *   cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release
 *   NNTMUX_STATUS_PROBES_TMUX_LAYOUT_BUILDER_BENCH=1 php artisan test --compact tests/Unit/RustStatusProbesTmuxLayoutBuilderParityTest.php --filter=test_paired_randomized_benchmarks
 *
 * Paired randomized benchmark evidence (3 repetitions x 41 paired samples x 200 inner loops,
 * worst-repetition PHP latency / Rust latency):
 *
 * | Method                                           | Common (Mean / P99) | Uncommon (Mean / P99) | >= 1.5x | Default      |
 * |--------------------------------------------------|---------------------|-----------------------|---------|--------------|
 * | RedisProbe::evaluateDriverUsage                  | 0.21x / 0.20x       | 0.35x / 0.34x         | No      | PHP (false)  |
 * | RedisProbe::evaluatePingResponse                 | 0.30x / 0.31x       | 0.36x / 0.34x         | No      | PHP (false)  |
 * | RedisProbe::formatUnreachableResult              | 0.56x / 0.56x       | 1.61x / 1.59x         | No      | PHP (false)  |
 * | NntpProbe::classifyConnectionAttempt             | 0.17x / 0.16x       | 0.17x / 0.17x         | No      | PHP (false)  |
 * | NntpProbe::evaluateConnections                   | 0.27x / 0.28x       | 0.29x / 0.29x         | No      | PHP (false)  |
 * | NntpProbe::formatFailureResult                   | 0.51x / 0.50x       | 1.62x / 1.60x         | No      | PHP (false)  |
 * | QueueProbe::evaluateQueueResult                  | 0.40x / 0.40x       | 0.32x / 0.34x         | No      | PHP (false)  |
 * | QueueProbe::formatFailureResult                  | 0.52x / 0.51x       | 1.61x / 1.59x         | No      | PHP (false)  |
 * | SearchProbe::evaluateAvailability                | 0.35x / 0.36x       | 0.36x / 0.38x         | No      | PHP (false)  |
 * | SearchProbe::formatFailureResult                 | 0.53x / 0.50x       | 1.58x / 1.54x         | No      | PHP (false)  |
 * | TmuxLayoutBuilder::resolvePaneDisplayName        | 0.15x / 0.14x       | 0.15x / 0.14x         | No      | PHP (false)  |
 * | TmuxLayoutBuilder::planCoreLayoutSteps           | 0.30x / 0.28x       | 0.30x / 0.30x         | No      | PHP (false)  |
 * | TmuxLayoutBuilder::formatToolCommand             | 0.15x / 0.14x       | 0.19x / 0.19x         | No      | PHP (false)  |
 * | TmuxLayoutBuilder::planRedisPaneCommand          | 0.17x / 0.17x       | 0.19x / 0.21x         | No      | PHP (false)  |
 * | TmuxLayoutBuilder::formatOperationError          | 0.24x / 0.23x       | 0.21x / 0.22x         | No      | PHP (false)  |
 *
 * While `formatUnreachableResult` and `formatFailureResult` achieve 1.54x-1.62x speedups on
 * `mb_strwidth`/`mb_strimwidth` uncommon long-error workloads, single-call FFI boundary marshaling
 * overhead dominates the common short-string and sub-microsecond paths. Per `.ai/rules/rust-performance.md`,
 * all five classes retain PHP (`$useRustFfi = false`) as the production default.
 */
class RustStatusProbesTmuxLayoutBuilderParityTest extends TestCase
{
    /** @var list<class-string> */
    private const array TOGGLED_CLASSES = [
        RedisProbe::class,
        NntpProbe::class,
        QueueProbe::class,
        SearchProbe::class,
        TmuxLayoutBuilder::class,
    ];

    private const string FULL_CDEF = RedisProbe::FFI_CDEF."\n"
        .NntpProbe::FFI_CDEF."\n"
        .QueueProbe::FFI_CDEF."\n"
        .SearchProbe::FFI_CDEF."\n"
        .TmuxLayoutBuilder::FFI_CDEF;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetFfiState();
        if (RustPipelineLibrary::resolveDomain('media', self::FULL_CDEF) === null) {
            $this->markTestSkipped('Rust pipeline library is not built; run cargo build --release.');
        }
    }

    protected function tearDown(): void
    {
        $this->resetFfiState();
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

            $class::enableRustFfi();
            $this->assertTrue($class::usesRustFfi());

            $class::disableRustFfi();
            $this->assertFalse($class::usesRustFfi());

            $class::enableRustFfi();
            $class::resetRustFfi();
            $this->assertFalse($class::usesRustFfi());
        }
    }

    // ---------------------------------------------------------------------
    // 1. RedisProbe
    // ---------------------------------------------------------------------

    public function test_redis_probe_driver_usage_ping_evaluation_and_failure_parity(): void
    {
        $driverCases = [
            ['redis', 'file', 'sync', 'storage', '', []],
            ['REDIS', 'file', 'sync', 'storage', '', []],
            ['file', 'redis', 'sync', 'storage', '', []],
            ['file', 'cookie', 'Redis', 'storage', '', []],
            ['file', 'cookie', 'sync', 'REDIS', '', []],
            ['failover', 'cookie', 'sync', 'storage', 'failover', ['array', 'redis']],
            ['failover', 'cookie', 'sync', 'storage', 'failover', ['array', 'REDIS']],
            ['failover', 'cookie', 'sync', 'storage', 'failover', ['array', 'file']],
            ['failover', 'cookie', 'sync', 'storage', 'Failover', ['redis']],
            ['file', 'cookie', 'sync', 'storage', 'file', []],
            ['', '', '', '', '', []],
            ["bad\0cache", 'file', 'sync', 'storage', '', []],
            ['failover', 'file', 'sync', 'storage', 'failover', ["bad\0store", 'redis']],
        ];

        foreach ($driverCases as $idx => [$cache, $session, $queue, $pulse, $storeDriver, $failover]) {
            $expected = RedisProbe::evaluateDriverUsagePhp($cache, $session, $queue, $pulse, $storeDriver, $failover);
            $actual = RedisProbe::evaluateDriverUsageNative($cache, $session, $queue, $pulse, $storeDriver, $failover);
            $this->assertSame($expected, $actual, "driver case {$idx}");

            foreach ([false, true] as $toggle) {
                RedisProbe::setUseRustFfi($toggle);
                $this->assertSame(
                    $expected,
                    RedisProbe::evaluateDriverUsage($cache, $session, $queue, $pulse, $storeDriver, $failover)
                );
            }
        }

        $pingCases = [
            [true, 1_000_000_000, 1_003_500_000],
            ['+PONG', 1_000_000_000, 1_001_200_000],
            ['PONG', 1_000_000_000, 1_000_999_999],
            ['pong', 1_000_000_000, 1_015_000_000],
            ['Pong', 2_000_000_000, 2_000_000_000],
            [false, 1_000_000_000, 1_004_000_000],
            ['LOADING Redis is loading the dataset in memory', 1_000_000_000, 1_025_000_000],
            ['', 1_000_000_000, 1_002_000_000],
            [null, 1_000_000_000, 1_002_000_000],
            [1, 1_000_000_000, 1_002_000_000],
        ];

        foreach ($pingCases as $idx => [$pong, $startNs, $endNs]) {
            $expected = RedisProbe::evaluatePingResponsePhp($pong, $startNs, $endNs);
            $actual = RedisProbe::evaluatePingResponseNative($pong, $startNs, $endNs);
            self::assertProbeResultSame($expected, $actual, "ping case {$idx}");

            foreach ([false, true] as $toggle) {
                RedisProbe::setUseRustFfi($toggle);
                self::assertProbeResultSame($expected, RedisProbe::evaluatePingResponse($pong, $startNs, $endNs));
            }
        }

        foreach (self::errorMessagesForTruncationTesting() as $idx => $msg) {
            $expected = RedisProbe::formatUnreachableResultPhp($msg);
            $actual = RedisProbe::formatUnreachableResultNative($msg);
            self::assertProbeResultSame($expected, $actual, "redis error case {$idx}");

            foreach ([false, true] as $toggle) {
                RedisProbe::setUseRustFfi($toggle);
                self::assertProbeResultSame($expected, RedisProbe::formatUnreachableResult($msg));
            }
        }

        config([
            'status-probes.redis.probe' => true,
            'status-probes.redis.only_when_used' => true,
            'cache.default' => 'array',
            'session.driver' => 'array',
            'queue.default' => 'sync',
            'pulse.ingest.driver' => 'storage',
        ]);
        $probe = new RedisProbe;
        RedisProbe::disableRustFfi();
        $phpSkip = $probe->probe();
        RedisProbe::enableRustFfi();
        $rustSkip = $probe->probe();
        self::assertProbeResultSame($phpSkip, $rustSkip, 'redis skip probe');
    }

    // ---------------------------------------------------------------------
    // 2. NntpProbe
    // ---------------------------------------------------------------------

    public function test_nntp_probe_connection_classification_evaluation_and_failure_parity(): void
    {
        $attemptCases = [
            [true, false, '', 1_000_000_000, 1_012_400_000],
            [false, true, '502 Authentication failed', 1_000_000_000, 1_045_000_000],
            [false, true, '', 1_000_000_000, 1_005_000_000],
            [false, true, str_repeat('TLS handshake error detail ', 12), 1_000_000_000, 1_120_000_000],
            [false, false, 'ignored', 1_000_000_000, 1_003_000_000],
        ];

        foreach ($attemptCases as $idx => [$isSuccess, $isErrObj, $errMsg, $startNs, $endNs]) {
            $expected = NntpProbe::classifyConnectionAttemptPhp($isSuccess, $isErrObj, $errMsg, $startNs, $endNs);
            $actual = NntpProbe::classifyConnectionAttemptNative($isSuccess, $isErrObj, $errMsg, $startNs, $endNs);
            $this->assertSame($expected, $actual, "nntp attempt case {$idx}");

            foreach ([false, true] as $toggle) {
                NntpProbe::setUseRustFfi($toggle);
                $this->assertSame(
                    $expected,
                    NntpProbe::classifyConnectionAttempt($isSuccess, $isErrObj, $errMsg, $startNs, $endNs)
                );
            }
        }

        $evalCases = [
            [['ok' => false, 'reason' => 'Connection refused', 'responseTimeMs' => 9], false, null],
            [['ok' => false, 'reason' => 'Connection refused', 'responseTimeMs' => 9], true, null],
            [['ok' => true, 'reason' => 'Connected', 'responseTimeMs' => 14], false, null],
            [['ok' => true, 'reason' => 'Connected', 'responseTimeMs' => 14], true, ['ok' => true, 'reason' => 'Connected', 'responseTimeMs' => 22]],
            [['ok' => true, 'reason' => 'Connected', 'responseTimeMs' => 14], true, ['ok' => false, 'reason' => '480 Auth required', 'responseTimeMs' => 30]],
            [['ok' => true, 'reason' => 'Connected', 'responseTimeMs' => 14], true, ['ok' => false, 'reason' => str_repeat('long reason ', 25), 'responseTimeMs' => 30]],
        ];

        foreach ($evalCases as $idx => [$primary, $checkAlt, $alternate]) {
            $expected = NntpProbe::evaluateConnectionsPhp($primary, $checkAlt, $alternate);
            $actual = NntpProbe::evaluateConnectionsNative($primary, $checkAlt, $alternate);
            self::assertProbeResultSame($expected, $actual, "nntp evaluate case {$idx}");

            foreach ([false, true] as $toggle) {
                NntpProbe::setUseRustFfi($toggle);
                self::assertProbeResultSame($expected, NntpProbe::evaluateConnections($primary, $checkAlt, $alternate));
            }
        }

        foreach (self::errorMessagesForTruncationTesting() as $idx => $msg) {
            $expected = NntpProbe::formatFailureResultPhp($msg);
            $actual = NntpProbe::formatFailureResultNative($msg);
            self::assertProbeResultSame($expected, $actual, "nntp error case {$idx}");

            foreach ([false, true] as $toggle) {
                NntpProbe::setUseRustFfi($toggle);
                self::assertProbeResultSame($expected, NntpProbe::formatFailureResult($msg));
            }
        }

        config(['status-probes.nntp.check_alternate' => true]);
        $fakeNntp = new class extends NNTPService
        {
            public int $quits = 0;

            public function __construct() {}

            public function doConnect(bool $compression = true, bool $alternate = false): mixed
            {
                return $alternate ? new NntpError('Alternate socket timeout') : true;
            }

            public function doQuit(bool $force = false): mixed
            {
                $this->quits++;

                return true;
            }
        };

        $nntpProbe = new NntpProbe($fakeNntp);
        NntpProbe::disableRustFfi();
        $phpProbe = $nntpProbe->probe();
        NntpProbe::enableRustFfi();
        $rustProbe = $nntpProbe->probe();
        self::assertProbeResultSame($phpProbe, $rustProbe, 'nntp live probe');
        $this->assertSame(2, $fakeNntp->quits);
    }

    // ---------------------------------------------------------------------
    // 3. QueueProbe
    // ---------------------------------------------------------------------

    public function test_queue_probe_evaluation_and_failure_parity(): void
    {
        $queueCases = [
            ['sync', null, 1_000_000_000, 1_000_400_000],
            ['sync', 0, 1_000_000_000, 1_001_000_000],
            ['redis', 42, 1_000_000_000, 1_003_200_000],
            ['database', 0, 1_000_000_000, 1_012_000_000],
            ['beanstalkd', null, 1_000_000_000, 1_005_000_000],
            [str_repeat('custom_queue_', 15), 99, 1_000_000_000, 1_007_000_000],
        ];

        foreach ($queueCases as $idx => [$conn, $size, $startNs, $endNs]) {
            $expected = QueueProbe::evaluateQueueResultPhp($conn, $size, $startNs, $endNs);
            $actual = QueueProbe::evaluateQueueResultNative($conn, $size, $startNs, $endNs);
            self::assertProbeResultSame($expected, $actual, "queue evaluate case {$idx}");

            foreach ([false, true] as $toggle) {
                QueueProbe::setUseRustFfi($toggle);
                self::assertProbeResultSame($expected, QueueProbe::evaluateQueueResult($conn, $size, $startNs, $endNs));
            }
        }

        foreach (self::errorMessagesForTruncationTesting() as $idx => $msg) {
            $expected = QueueProbe::formatFailureResultPhp($msg);
            $actual = QueueProbe::formatFailureResultNative($msg);
            self::assertProbeResultSame($expected, $actual, "queue error case {$idx}");

            foreach ([false, true] as $toggle) {
                QueueProbe::setUseRustFfi($toggle);
                self::assertProbeResultSame($expected, QueueProbe::formatFailureResult($msg));
            }
        }

        config(['queue.default' => 'redis']);
        $fakeQueueFactory = new class implements QueueFactory
        {
            public function connection($name = null): Queue
            {
                return new class extends SyncQueue
                {
                    public function size($queue = null): int
                    {
                        return 17;
                    }
                };
            }
        };

        $queueProbe = new QueueProbe($fakeQueueFactory);
        QueueProbe::disableRustFfi();
        $phpRes = $queueProbe->probe();
        QueueProbe::enableRustFfi();
        $rustRes = $queueProbe->probe();
        $this->assertSame($phpRes->ok, $rustRes->ok);
        $this->assertSame($phpRes->reason, $rustRes->reason);
        $this->assertSame($phpRes->metadata, $rustRes->metadata);
    }

    // ---------------------------------------------------------------------
    // 4. SearchProbe
    // ---------------------------------------------------------------------

    public function test_search_probe_availability_and_failure_parity(): void
    {
        $searchCases = [
            [true, 'manticore', 1_000_000_000, 1_002_500_000],
            [false, 'manticore', 1_000_000_000, 1_009_000_000],
            [true, 'elasticsearch', 1_000_000_000, 1_004_000_000],
            [false, 'elasticsearch', 1_000_000_000, 1_015_000_000],
            [true, str_repeat('custom_driver_', 15), 1_000_000_000, 1_001_000_000],
            [false, '', 1_000_000_000, 1_000_000_000],
        ];

        foreach ($searchCases as $idx => [$avail, $driver, $startNs, $endNs]) {
            $expected = SearchProbe::evaluateAvailabilityPhp($avail, $driver, $startNs, $endNs);
            $actual = SearchProbe::evaluateAvailabilityNative($avail, $driver, $startNs, $endNs);
            self::assertProbeResultSame($expected, $actual, "search availability case {$idx}");

            foreach ([false, true] as $toggle) {
                SearchProbe::setUseRustFfi($toggle);
                self::assertProbeResultSame($expected, SearchProbe::evaluateAvailability($avail, $driver, $startNs, $endNs));
            }
        }

        foreach (self::errorMessagesForTruncationTesting() as $idx => $msg) {
            $expected = SearchProbe::formatFailureResultPhp($msg);
            $actual = SearchProbe::formatFailureResultNative($msg);
            self::assertProbeResultSame($expected, $actual, "search error case {$idx}");

            foreach ([false, true] as $toggle) {
                SearchProbe::setUseRustFfi($toggle);
                self::assertProbeResultSame($expected, SearchProbe::formatFailureResult($msg));
            }
        }
    }

    // ---------------------------------------------------------------------
    // 5. TmuxLayoutBuilder
    // ---------------------------------------------------------------------

    public function test_tmux_layout_builder_icons_core_steps_tool_commands_redis_plan_and_error_parity(): void
    {
        $iconKeys = array_merge(
            array_keys(TmuxLayoutBuilder::DEFAULT_PANE_ICONS),
            ['unknown_pane', '', 'custom_pane_name', str_repeat('long_pane_name_', 10)],
        );

        foreach ($iconKeys as $key) {
            $expected = TmuxLayoutBuilder::resolvePaneDisplayNamePhp($key);
            $actual = TmuxLayoutBuilder::resolvePaneDisplayNameNative($key);
            $this->assertSame($expected, $actual, "pane icon key={$key}");

            foreach ([false, true] as $toggle) {
                TmuxLayoutBuilder::setUseRustFfi($toggle);
                $this->assertSame($expected, TmuxLayoutBuilder::resolvePaneDisplayName($key));
            }
        }

        foreach ([-1, 0, 1, 2, 3, 99] as $mode) {
            $expectedSteps = TmuxLayoutBuilder::planCoreLayoutStepsPhp($mode);
            $actualSteps = TmuxLayoutBuilder::planCoreLayoutStepsNative($mode);
            $this->assertSame($expectedSteps, $actualSteps, "core layout steps mode={$mode}");

            foreach ([false, true] as $toggle) {
                TmuxLayoutBuilder::setUseRustFfi($toggle);
                $this->assertSame($expectedSteps, TmuxLayoutBuilder::planCoreLayoutSteps($mode));
            }
        }

        $toolCases = [
            ['htop', ''],
            ['nmon', ''],
            ['vnstat', ''],
            ['vnstat', '-i eth0 -u'],
            ['tcptrack', '-i eth0 port 443'],
            ['tcptrack', str_repeat('-i eth0 ', 30)],
            ['bwm-ng', ''],
            ['mytop', ''],
            ['bash', ''],
            ['unknown_tool', '-v'],
            ['', ''],
        ];

        foreach ($toolCases as [$tool, $args]) {
            $expectedCmd = TmuxLayoutBuilder::formatToolCommandPhp($tool, $args);
            $actualCmd = TmuxLayoutBuilder::formatToolCommandNative($tool, $args);
            $this->assertSame($expectedCmd, $actualCmd, "tool={$tool} args={$args}");

            foreach ([false, true] as $toggle) {
                TmuxLayoutBuilder::setUseRustFfi($toggle);
                $this->assertSame($expectedCmd, TmuxLayoutBuilder::formatToolCommand($tool, $args));
            }
        }

        $redisCases = [
            ['-s', true, '127.0.0.1', 6379, 30, false, false, '127.0.0.1', '/usr/bin/php', '/app/artisan', '/app/sail'],
            ['--stat', true, 'redis.internal', '6380', 15, true, true, '127.0.0.1', '/usr/bin/php', '/app/artisan', '/app/sail'],
            ['', true, '127.0.0.1', 6379, 30, false, false, '127.0.0.1', '/usr/bin/php', '/app/artisan', '/app/sail'],
            ['0', true, '127.0.0.1', 6379, 30, false, false, '127.0.0.1', '/usr/bin/php', '/app/artisan', '/app/sail'],
            ['NULL', true, '127.0.0.1', 6379, 30, false, false, '127.0.0.1', '/usr/bin/php', '/app/artisan', '/app/sail'],
            ['--stat', false, 'redis', 6379, 30, true, false, 'redis', '/usr/bin/php', '/app/artisan', '/app/sail'],
            ['', false, 'redis', 6379, 30, false, true, '127.0.0.1', '/usr/bin/php8.5', '/var/www/nntmux/artisan', '/var/www/nntmux/sail'],
            ["bad\0arg", true, '127.0.0.1', 6379, 30, false, false, '127.0.0.1', '/usr/bin/php', '/app/artisan', '/app/sail'],
        ];

        foreach ($redisCases as $idx => [$rArgs, $hasCli, $rHost, $rPort, $interval, $useSail, $overrideHost, $resHost, $phpBin, $artisan, $sail]) {
            $expectedPlan = TmuxLayoutBuilder::planRedisPaneCommandPhp(
                $rArgs,
                $hasCli,
                $rHost,
                $rPort,
                $interval,
                $useSail,
                $overrideHost,
                $resHost,
                $phpBin,
                $artisan,
                $sail,
            );
            $actualPlan = TmuxLayoutBuilder::planRedisPaneCommandNative(
                $rArgs,
                $hasCli,
                $rHost,
                $rPort,
                $interval,
                $useSail,
                $overrideHost,
                $resHost,
                $phpBin,
                $artisan,
                $sail,
            );
            $this->assertSame($expectedPlan, $actualPlan, "redis plan case {$idx}");

            foreach ([false, true] as $toggle) {
                TmuxLayoutBuilder::setUseRustFfi($toggle);
                $this->assertSame(
                    $expectedPlan,
                    TmuxLayoutBuilder::planRedisPaneCommand(
                        $rArgs,
                        $hasCli,
                        $rHost,
                        $rPort,
                        $interval,
                        $useSail,
                        $overrideHost,
                        $resHost,
                        $phpBin,
                        $artisan,
                        $sail,
                    ),
                );
            }
        }

        $errorCases = [
            ['retain monitor pane', null],
            ['retain monitor pane', ''],
            ['retain monitor pane', 'can\'t find pane: %99'],
            ['respawn Redis pane', str_repeat('tmux error output ', 15)],
        ];

        foreach ($errorCases as [$op, $err]) {
            $expectedErr = TmuxLayoutBuilder::formatOperationErrorPhp($op, $err);
            $actualErr = TmuxLayoutBuilder::formatOperationErrorNative($op, $err);
            $this->assertSame($expectedErr, $actualErr, "op={$op}");

            foreach ([false, true] as $toggle) {
                TmuxLayoutBuilder::setUseRustFfi($toggle);
                $this->assertSame($expectedErr, TmuxLayoutBuilder::formatOperationError($op, $err));
            }
        }

        $builder = new class(new TmuxSessionManager('test-layout')) extends TmuxLayoutBuilder
        {
            public function exposeDisplayName(string $name): string
            {
                return $this->getPaneDisplayName($name);
            }
        };
        TmuxLayoutBuilder::disableRustFfi();
        $phpDisplay = $builder->exposeDisplayName('Monitor');
        TmuxLayoutBuilder::enableRustFfi();
        $rustDisplay = $builder->exposeDisplayName('Monitor');
        $this->assertSame($phpDisplay, $rustDisplay);
    }

    // ---------------------------------------------------------------------
    // 6. Direct C-ABI Safety & Fallback Contracts
    // ---------------------------------------------------------------------

    private static function ffiCall(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    public function test_c_abi_null_alignment_overflow_and_insufficient_buffer_contracts(): void
    {
        $ffi = RustPipelineLibrary::resolveDomain('media', self::FULL_CDEF);
        $this->assertNotNull($ffi);

        $buf = $ffi->new('char[256]');
        $small = $ffi->new('char[4]');
        $int64Slot = $ffi->new('int64_t[1]');
        $int32Slot = $ffi->new('int32_t[1]');

        $rawBytes = $ffi->new('uint8_t[32]');
        $unalignedInt64 = $ffi->cast('int64_t*', FFI::addr($rawBytes[1]));
        $unalignedInt32 = $ffi->cast('int32_t*', FFI::addr($rawBytes[1]));

        // 1. nntmux_redis_probe_uses_redis
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_redis_probe_uses_redis', null, 4, 'file', 4, 'sync', 4, 'storage', 7, '', 0, '', 0, 0));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_redis_probe_uses_redis', 'file', 4, 'file', 4, 'sync', 4, 'storage', 7, 'failover', 8, 'redis', 5, 2));

        // 2. nntmux_redis_probe_evaluate_ping
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_redis_probe_evaluate_ping', 2, 'PONG', 4, 100, 200, $int64Slot, $int32Slot, $buf, 256));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_redis_probe_evaluate_ping', 0, null, 4, 100, 200, $int64Slot, $int32Slot, $buf, 256));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_redis_probe_evaluate_ping', 0, 'PONG', 4, 100, 200, $unalignedInt64, $int32Slot, $buf, 256));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_redis_probe_evaluate_ping', 0, 'PONG', 4, 100, 200, $int64Slot, $unalignedInt32, $buf, 256));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_redis_probe_evaluate_ping', 0, 'PONG', 4, PHP_INT_MIN, PHP_INT_MAX, $int64Slot, $int32Slot, $buf, 256));
        FFI::memset($small, 0x5A, 4);
        $int64Slot[0] = 999;
        $int32Slot[0] = 888;
        $this->assertSame(-9, self::ffiCall($ffi, 'nntmux_redis_probe_evaluate_ping', 1, '', 0, 0, 1_000_000, $int64Slot, $int32Slot, $small, 4));
        $this->assertSame(999, $int64Slot[0]);
        $this->assertSame(888, $int32Slot[0]);
        $this->assertSame("\x5A\x5A\x5A\x5A", FFI::string($small, 4));

        // 3. nntmux_status_probe_format_failure
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_status_probe_format_failure', 4, 'err', 3, $buf, 256));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_status_probe_format_failure', 0, null, 3, $buf, 256));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_status_probe_format_failure', 0, 'err', 3, null, 256));
        $this->assertSame(-2, self::ffiCall($ffi, 'nntmux_status_probe_format_failure', 0, 'utf8_é', 6, $buf, 256));
        $this->assertLessThan(-4, self::ffiCall($ffi, 'nntmux_status_probe_format_failure', 0, 'timeout', 7, $small, 4));

        // 4. nntmux_nntp_probe_classify_connection
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_nntp_probe_classify_connection', 2, 0, '', 0, 0, 100, $int64Slot, $int32Slot, $buf, 256));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_nntp_probe_classify_connection', 0, 1, null, 3, 0, 100, $int64Slot, $int32Slot, $buf, 256));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_nntp_probe_classify_connection', 1, 0, '', 0, 0, 100, $unalignedInt64, $int32Slot, $buf, 256));
        $this->assertLessThan(-4, self::ffiCall($ffi, 'nntmux_nntp_probe_classify_connection', 1, 0, '', 0, 0, 100, $int64Slot, $int32Slot, $small, 4));

        // 5. nntmux_nntp_probe_evaluate
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_nntp_probe_evaluate', 2, 10, 'ok', 2, 0, 1, '', 0, $int64Slot, $int32Slot, $buf, 256));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_nntp_probe_evaluate', 1, 10, null, 2, 0, 1, '', 0, $int64Slot, $int32Slot, $buf, 256));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_nntp_probe_evaluate', 1, 10, 'ok', 2, 0, 1, '', 0, $unalignedInt64, $int32Slot, $buf, 256));
        $this->assertLessThan(-4, self::ffiCall($ffi, 'nntmux_nntp_probe_evaluate', 1, 10, 'ok', 2, 0, 1, '', 0, $int64Slot, $int32Slot, $small, 4));

        // 6. nntmux_queue_probe_evaluate
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_queue_probe_evaluate', null, 4, 0, 100, $int64Slot, $int32Slot, $buf, 256));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_queue_probe_evaluate', 'sync', 4, 0, 100, $unalignedInt64, $int32Slot, $buf, 256));
        $this->assertLessThan(-4, self::ffiCall($ffi, 'nntmux_queue_probe_evaluate', 'sync', 4, 0, 100, $int64Slot, $int32Slot, $small, 4));

        // 7. nntmux_search_probe_evaluate
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_search_probe_evaluate', 2, 'manticore', 9, 0, 100, $int64Slot, $int32Slot, $buf, 256));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_search_probe_evaluate', 1, null, 9, 0, 100, $int64Slot, $int32Slot, $buf, 256));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_search_probe_evaluate', 1, 'manticore', 9, 0, 100, $int64Slot, $unalignedInt32, $buf, 256));
        $this->assertLessThan(-4, self::ffiCall($ffi, 'nntmux_search_probe_evaluate', 1, 'manticore', 9, 0, 100, $int64Slot, $int32Slot, $small, 4));

        // 8. TmuxLayoutBuilder symbols
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_tmux_layout_pane_display_name', null, 7, $buf, 256));
        $this->assertLessThan(-4, self::ffiCall($ffi, 'nntmux_tmux_layout_pane_display_name', 'Monitor', 7, $small, 4));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_tmux_layout_plan_core_steps_nul', 0, $unalignedInt64, $buf, 256));
        $this->assertLessThan(-4, self::ffiCall($ffi, 'nntmux_tmux_layout_plan_core_steps_nul', 0, $int64Slot, $small, 4));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_tmux_layout_format_tool_command', null, 4, '', 0, $buf, 256));
        $this->assertSame(-2, self::ffiCall($ffi, 'nntmux_tmux_layout_format_tool_command', 'invalid', 7, '', 0, $buf, 256));
        $this->assertLessThan(-4, self::ffiCall($ffi, 'nntmux_tmux_layout_format_tool_command', 'vnstat', 6, '-i eth0', 7, $small, 4));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_tmux_layout_plan_redis_command_nul', '', 0, 1, '127.0.0.1', 9, '6379', 4, 30, 0, 0, '127.0.0.1', 9, '/usr/bin/php', 12, '/app/artisan', 12, '/app/sail', 9, $unalignedInt32, $buf, 256));
        $this->assertLessThan(-4, self::ffiCall($ffi, 'nntmux_tmux_layout_plan_redis_command_nul', '', 0, 1, '127.0.0.1', 9, '6379', 4, 30, 0, 0, '127.0.0.1', 9, '/usr/bin/php', 12, '/app/artisan', 12, '/app/sail', 9, $int32Slot, $small, 4));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_tmux_layout_format_operation_error', null, 4, '', 0, $buf, 256));
        $this->assertLessThan(-4, self::ffiCall($ffi, 'nntmux_tmux_layout_format_operation_error', 'retain pane', 11, 'failed', 6, $small, 4));
    }

    public function test_native_methods_fall_back_to_php_when_ffi_unavailable(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::setFfiForTesting(false);
            $class::enableRustFfi();
        }

        $this->assertTrue(RedisProbe::evaluateDriverUsageNative('redis', 'file', 'sync', 'storage'));
        $this->assertTrue(RedisProbe::evaluatePingResponseNative('+PONG', 1_000_000_000, 1_002_000_000)->ok);
        $this->assertFalse(RedisProbe::formatUnreachableResultNative('Connection refused')->ok);

        $this->assertTrue(NntpProbe::classifyConnectionAttemptNative(true, false, '', 1_000_000_000, 1_005_000_000)['ok']);
        $this->assertTrue(NntpProbe::evaluateConnectionsNative(['ok' => true, 'reason' => 'Connected', 'responseTimeMs' => 5], false)->ok);
        $this->assertFalse(NntpProbe::formatFailureResultNative('Timeout')->ok);

        $this->assertTrue(QueueProbe::evaluateQueueResultNative('redis', 3, 1_000_000_000, 1_002_000_000)->ok);
        $this->assertFalse(QueueProbe::formatFailureResultNative('Queue down')->ok);

        $this->assertTrue(SearchProbe::evaluateAvailabilityNative(true, 'manticore', 1_000_000_000, 1_002_000_000)->ok);
        $this->assertFalse(SearchProbe::formatFailureResultNative('Manticore offline')->ok);

        $this->assertSame(TmuxLayoutBuilder::DEFAULT_PANE_ICONS['Monitor'], TmuxLayoutBuilder::resolvePaneDisplayNameNative('Monitor'));
        $this->assertCount(11, TmuxLayoutBuilder::planCoreLayoutStepsNative(0));
        $this->assertSame("watch -n10 'vnstat -i eth0'", TmuxLayoutBuilder::formatToolCommandNative('vnstat', '-i eth0'));
        $this->assertSame(
            ['command' => ['/usr/bin/php', '/app/artisan', 'redis:monitor', '--refresh=30'], 'environment' => ['REDIS_HOST' => '127.0.0.1']],
            TmuxLayoutBuilder::planRedisPaneCommandNative('', false, 'redis', 6379, 30, false, true, '127.0.0.1', '/usr/bin/php', '/app/artisan', '/app/sail')
        );
        $this->assertSame('Unable to retain monitor pane: dead.', TmuxLayoutBuilder::formatOperationErrorNative('retain monitor pane', 'dead'));
    }

    // ---------------------------------------------------------------------
    // 7. Paired Randomized Benchmarks
    // ---------------------------------------------------------------------

    public function test_paired_randomized_benchmarks(): void
    {
        if (getenv('NNTMUX_STATUS_PROBES_TMUX_LAYOUT_BUILDER_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_STATUS_PROBES_TMUX_LAYOUT_BUILDER_BENCH=1 to run paired Rust/PHP benchmarks.');
        }

        $longErr = str_repeat('Socket connection timed out while reading server greeting from endpoint ', 3);

        $workloads = [
            'RedisProbe::evaluateDriverUsage' => [
                RedisProbe::class,
                static fn () => RedisProbe::evaluateDriverUsage('redis', 'file', 'sync', 'storage'),
                static fn () => RedisProbe::evaluateDriverUsage('failover', 'cookie', 'sync', 'database', 'failover', ['array', 'file', 'Redis']),
            ],
            'RedisProbe::evaluatePingResponse' => [
                RedisProbe::class,
                static fn () => RedisProbe::evaluatePingResponse('+PONG', 1_000_000_000, 1_002_000_000)->reason,
                static fn () => RedisProbe::evaluatePingResponse('LOADING dataset', 1_000_000_000, 1_045_000_000)->reason,
            ],
            'RedisProbe::formatUnreachableResult' => [
                RedisProbe::class,
                static fn () => RedisProbe::formatUnreachableResult('Connection refused [tcp://127.0.0.1:6379]')->reason,
                static fn () => RedisProbe::formatUnreachableResult($longErr)->reason,
            ],
            'NntpProbe::classifyConnectionAttempt' => [
                NntpProbe::class,
                static fn () => NntpProbe::classifyConnectionAttempt(true, false, '', 1_000_000_000, 1_012_000_000)['reason'],
                static fn () => NntpProbe::classifyConnectionAttempt(false, true, '502 Authentication failed on primary server', 1_000_000_000, 1_048_000_000)['reason'],
            ],
            'NntpProbe::evaluateConnections' => [
                NntpProbe::class,
                static fn () => NntpProbe::evaluateConnections(['ok' => true, 'reason' => 'Connected', 'responseTimeMs' => 12], false)->reason,
                static fn () => NntpProbe::evaluateConnections(['ok' => true, 'reason' => 'Connected', 'responseTimeMs' => 12], true, ['ok' => false, 'reason' => '480 Authentication required', 'responseTimeMs' => 30])->reason,
            ],
            'NntpProbe::formatFailureResult' => [
                NntpProbe::class,
                static fn () => NntpProbe::formatFailureResult('TLS handshake failed')->reason,
                static fn () => NntpProbe::formatFailureResult($longErr)->reason,
            ],
            'QueueProbe::evaluateQueueResult' => [
                QueueProbe::class,
                static fn () => QueueProbe::evaluateQueueResult('redis', 14, 1_000_000_000, 1_003_000_000)->reason,
                static fn () => QueueProbe::evaluateQueueResult('sync', null, 1_000_000_000, 1_000_500_000)->reason,
            ],
            'QueueProbe::formatFailureResult' => [
                QueueProbe::class,
                static fn () => QueueProbe::formatFailureResult('Queue connection lost')->reason,
                static fn () => QueueProbe::formatFailureResult($longErr)->reason,
            ],
            'SearchProbe::evaluateAvailability' => [
                SearchProbe::class,
                static fn () => SearchProbe::evaluateAvailability(true, 'manticore', 1_000_000_000, 1_004_000_000)->reason,
                static fn () => SearchProbe::evaluateAvailability(false, 'elasticsearch', 1_000_000_000, 1_025_000_000)->reason,
            ],
            'SearchProbe::formatFailureResult' => [
                SearchProbe::class,
                static fn () => SearchProbe::formatFailureResult('Manticore port 9306 unreachable')->reason,
                static fn () => SearchProbe::formatFailureResult($longErr)->reason,
            ],
            'TmuxLayoutBuilder::resolvePaneDisplayName' => [
                TmuxLayoutBuilder::class,
                static fn () => TmuxLayoutBuilder::resolvePaneDisplayName('Monitor'),
                static fn () => TmuxLayoutBuilder::resolvePaneDisplayName('postprocessing_additional'),
            ],
            'TmuxLayoutBuilder::planCoreLayoutSteps' => [
                TmuxLayoutBuilder::class,
                static fn () => TmuxLayoutBuilder::planCoreLayoutSteps(0),
                static fn () => TmuxLayoutBuilder::planCoreLayoutSteps(1),
            ],
            'TmuxLayoutBuilder::formatToolCommand' => [
                TmuxLayoutBuilder::class,
                static fn () => TmuxLayoutBuilder::formatToolCommand('htop'),
                static fn () => TmuxLayoutBuilder::formatToolCommand('vnstat', '-i eth0 -u'),
            ],
            'TmuxLayoutBuilder::planRedisPaneCommand' => [
                TmuxLayoutBuilder::class,
                static fn () => TmuxLayoutBuilder::planRedisPaneCommand('', false, 'redis', 6379, 30, false, true, '127.0.0.1', '/usr/bin/php', '/app/artisan', '/app/sail'),
                static fn () => TmuxLayoutBuilder::planRedisPaneCommand('--stat', true, '127.0.0.1', 6379, 30, false, false, '127.0.0.1', '/usr/bin/php', '/app/artisan', '/app/sail'),
            ],
            'TmuxLayoutBuilder::formatOperationError' => [
                TmuxLayoutBuilder::class,
                static fn () => TmuxLayoutBuilder::formatOperationError('retain monitor pane', 'pane dead'),
                static fn () => TmuxLayoutBuilder::formatOperationError('respawn Redis pane', null),
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

                    $stats = $this->runPairedBenchmark($class, $fn, 200, 41, 2106 + $rep);
                    fwrite(STDERR, json_encode(['method' => $name, 'workload' => $kind, 'repetition' => $rep] + $stats).PHP_EOL);
                    $worst["{$kind}_mean"] = min($worst["{$kind}_mean"], $stats['mean_speedup']);
                    $worst["{$kind}_p99"] = min($worst["{$kind}_p99"], $stats['p99_speedup']);
                }
            }
            $class::resetRustFfi();
            $qualified = min($worst) >= 1.5;
            $rows[] = sprintf(
                ' * | %-48s | %.2fx / %.2fx       | %.2fx / %.2fx         | %-7s | %-12s |',
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

    /**
     * @return list<string>
     */
    private static function errorMessagesForTruncationTesting(): array
    {
        return [
            '',
            'Connection refused',
            str_repeat('a', 119),
            str_repeat('b', 120),
            str_repeat('c', 121),
            str_repeat('d', 118).'   extra words after 120 boundary',
            str_repeat('e', 115)."\t\n\r  trailing whitespace before cut",
            str_repeat('Error detail segment ', 12),
            'UTF-8 non-ASCII error: '.str_repeat('é', 130),
        ];
    }

    private static function assertProbeResultSame(ProbeResult $expected, ProbeResult $actual, string $message = ''): void
    {
        self::assertSame($expected->ok, $actual->ok, $message.' (ok)');
        self::assertSame($expected->responseTimeMs, $actual->responseTimeMs, $message.' (responseTimeMs)');
        self::assertSame($expected->impact, $actual->impact, $message.' (impact)');
        self::assertSame($expected->reason, $actual->reason, $message.' (reason)');
        self::assertSame($expected->metadata, $actual->metadata, $message.' (metadata)');
    }

    private static function invoke(Closure $fn): mixed
    {
        return $fn();
    }

    /**
     * @param  class-string  $class
     * @return array{rust_mean_us: float, php_mean_us: float, rust_p99_us: float, php_p99_us: float, mean_speedup: float, p99_speedup: float, samples: int, inner_loops: int}
     */
    private function runPairedBenchmark(string $class, Closure $fn, int $innerLoops, int $samples, int $seed): array
    {
        foreach ([true, false] as $toggle) {
            $class::setUseRustFfi($toggle);
            for ($w = 0; $w < 50; $w++) {
                $fn();
            }
        }

        $times = [true => [], false => []];
        mt_srand($seed);
        for ($s = 0; $s < $samples; $s++) {
            $order = mt_rand(0, 1) === 0 ? [true, false] : [false, true];
            foreach ($order as $toggle) {
                $class::setUseRustFfi($toggle);
                $t0 = hrtime(true);
                for ($i = 0; $i < $innerLoops; $i++) {
                    $fn();
                }
                $times[$toggle][] = (hrtime(true) - $t0) / 1000.0 / $innerLoops;
            }
        }

        $rust = $times[true];
        $php = $times[false];
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
        ];
    }
}
