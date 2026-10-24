<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\DTO\PersistenceMetrics;
use App\Services\AdditionalProcessing\DTO\ReleaseProcessingResult;
use App\Services\AdditionalProcessing\Enums\ProcessingOutcome;
use App\Services\AdditionalProcessing\Media\MediaToolFactory;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Support\RustPipelineLibrary;
use FFI;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use ReflectionProperty;
use Tests\Unit\AdditionalProcessing\CreatesProcessingConfiguration;
use TypeError;

/**
 * Wave 17 parity and paired benchmark suite for `ProcessingConfiguration`,
 * `ReleaseProcessingResult`, `MediaToolFactory`, `ReleaseSearchSyncCoordinator` and
 * `PersistenceMetricsCollector` (`rust/pipeline-ffi/src/additional.rs`).
 *
 * Reproduce paired randomized benchmarks:
 *   NNTMUX_ADDITIONAL_CONFIG_METRICS_SYNC_BENCH=1 php artisan test --compact tests/Unit/RustAdditionalConfigMetricsSyncParityTest.php --filter=test_benchmark_paired_workloads
 *
 * Protocol: seed 20261024; 512 warmups; 3 repetitions of 4,096 randomized paired
 * single calls per workload (common and uncommon); nearest-rank p99; output equality
 * verified before timing. Adoption requires >= 1.5x speedup on all 4 metrics
 * (common mean, common p99, uncommon mean, uncommon p99) per `.ai/rules/rust-performance.md`.
 *
 * Environment: Apple M5, macOS (Darwin 27.2.0), PHP 8.5.12 NTS CLI, rustc 1.99.1,
 * `cargo build --release`; base commit cede7e144.
 *
 * Paired speedup results (`PHP latency / Rust FFI latency`, mean/p99 per rep, 3 reps):
 * - `killString`:          common 0.26x/0.33x, 0.26x/0.37x, 0.28x/0.33x | uncommon 0.30x/0.33x, 0.29x/0.37x, 0.30x/0.33x
 * - `ffprobeSibling`:      common 0.24x/0.29x, 0.24x/0.21x, 0.26x/0.25x | uncommon 0.39x/0.45x, 0.39x/0.45x, 0.38x/0.45x
 * - `normalizeLimits`:     common 0.39x/0.43x, 0.42x/0.46x, 0.43x/0.46x | uncommon 0.42x/0.46x, 0.42x/0.50x, 0.43x/0.46x
 * - `isSuccessful`:        common 0.43x/0.50x, 0.45x/0.51x, 0.45x/0.51x | uncommon 0.50x/0.50x, 0.49x/0.40x, 0.50x/0.67x
 * - `durationSummary`:     common 0.37x/0.43x, 0.38x/0.43x, 0.36x/0.36x | uncommon 0.80x/0.80x, 0.81x/0.80x, 0.84x/0.84x
 * - `driverConfiguration`: common 0.35x/0.34x, 0.35x/0.36x, 0.35x/0.34x | uncommon 0.34x/0.29x, 0.33x/0.43x, 0.34x/0.29x
 * - `syncDecide`:          common 0.28x/0.29x, 0.28x/0.43x, 0.26x/0.38x | uncommon 0.29x/0.43x, 0.29x/0.43x, 0.28x/0.43x
 * - `metricsApply`:        common 0.22x/0.38x, 0.22x/0.37x, 0.20x/0.37x | uncommon 0.19x/0.29x, 0.19x/0.29x, 0.19x/0.29x
 *
 * Every helper is a 40-550 ns scalar/short-string operation, so the FFI call, argument
 * marshaling and CData reads (~110-280 ns) exceed the computation itself. No method
 * reaches 1.5x on any metric, so per `.ai/rules/rust-performance.md` all five classes keep
 * PHP as the production default (`$useRustFfi = false`) while the `*Native` and `*Php`
 * paths stay covered by these parity tests.
 */
final class RustAdditionalConfigMetricsSyncParityTest extends TestCase
{
    use CreatesProcessingConfiguration;

    private const string FULL_CDEF = ProcessingConfiguration::FFI_CDEF."\n"
        .ReleaseProcessingResult::FFI_CDEF."\n"
        .MediaToolFactory::FFI_CDEF."\n"
        .ReleaseSearchSyncCoordinator::FFI_CDEF."\n"
        .PersistenceMetricsCollector::FFI_CDEF;

    protected function tearDown(): void
    {
        MediaToolFactory::setUseRustFfi(false);
        ReleaseSearchSyncCoordinator::setUseRustFfi(false);
        PersistenceMetricsCollector::setUseRustFfi(false);
        foreach ([MediaToolFactory::class, ReleaseSearchSyncCoordinator::class, PersistenceMetricsCollector::class] as $class) {
            (new ReflectionProperty($class, 'ffi'))->setValue(null, null);
        }
        parent::tearDown();
    }

    private static function ffi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('additional', self::FULL_CDEF);
        self::assertNotNull($ffi, 'Expected libnntmux_pipeline additional domain FFI to be available.');

        return $ffi;
    }

    /**
     * Float-exact view of a value (distinguishes -0.0 and compares NAN payloads).
     */
    private static function canonical(mixed $value): mixed
    {
        if (is_float($value)) {
            return 'f:'.bin2hex(pack('e', $value));
        }
        if (is_array($value)) {
            return array_map(self::canonical(...), $value);
        }

        return $value;
    }

    public function test_processing_configuration_toggle_defaults_to_php(): void
    {
        $this->assertFalse((new ReflectionMethod(ProcessingConfiguration::class, '__construct'))->getParameters()[0]->getDefaultValue());
        $this->assertFalse(MediaToolFactory::$useRustFfi);
        $this->assertFalse(ReleaseSearchSyncCoordinator::$useRustFfi);
        $this->assertFalse(PersistenceMetricsCollector::$useRustFfi);
        $this->assertFalse((new ReleaseProcessingResult(1, 'g', ProcessingOutcome::Completed))->useRustFfi);
    }

    public function test_kill_string_matches_php(): void
    {
        self::ffi();
        $cases = [
            [false, 30],
            ['', 30],
            ['0', 30],
            ['00', 30],
            [' ', 30],
            ['/usr/bin/timeout', 60],
            ['/usr/bin/timeout', 0],
            ['/usr/bin/timeout', -5],
            ['/usr/bin/timeout', PHP_INT_MAX],
            ['/opt/my tools/"timeout"', 1],
            ['/opt/ütf8/timeout', 120],
            [str_repeat('/very-long-segment', 300), 9],
        ];

        foreach ($cases as [$path, $seconds]) {
            $expected = ProcessingConfiguration::killStringPhp($path, $seconds);
            $this->assertSame($expected, ProcessingConfiguration::killStringNative($path, $seconds));
            $this->assertSame($expected, ProcessingConfiguration::killString($path, $seconds, true));
            $this->assertSame($expected, ProcessingConfiguration::killString($path, $seconds));

            $config = $this->makeConfig(['timeoutPath' => $path, 'timeoutSeconds' => $seconds]);
            $this->assertSame($expected, $config->getKillString());
            (new ReflectionProperty(ProcessingConfiguration::class, 'useRustFfi'))->setValue($config, true);
            $this->assertSame($expected, $config->getKillString());
        }

        $this->assertSame('"/usr/bin/timeout" --foreground --signal=KILL 60 "', ProcessingConfiguration::killStringNative('/usr/bin/timeout', 60));
    }

    public function test_ffprobe_sibling_candidate_matches_php_dirname(): void
    {
        self::ffi();
        $cases = [
            '', 'ffmpeg', '/', '///', '/ffmpeg', '//ffmpeg', '/usr/bin/ffmpeg', '/usr/bin//ffmpeg',
            '/usr/bin/', 'usr/bin', '///a//b//', './ffmpeg', '../ffmpeg', 'C:\\ffmpeg\\ffmpeg.exe',
            '/opt/ffmpeg 7.1/bin/ffmpeg', "/opt/\xff\xfe/ffmpeg", '.', '..', 'a/', '/a/b/c/d/e/f/ffmpeg',
        ];

        foreach ($cases as $path) {
            $expected = ProcessingConfiguration::ffprobeSiblingCandidatePhp($path);
            $this->assertSame($expected, ProcessingConfiguration::ffprobeSiblingCandidateNative($path), $path);
            $this->assertSame($expected, ProcessingConfiguration::ffprobeSiblingCandidate($path, true), $path);
        }

        $dir = sys_get_temp_dir().'/w17-ffprobe-'.bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            touch($dir.'/ffprobe');
            chmod($dir.'/ffprobe', 0755);
            foreach ([false, true] as $toggle) {
                $this->assertSame($dir.'/ffprobe', ProcessingConfiguration::resolveFfprobePath(false, $dir.'/ffmpeg', $toggle));
                $this->assertSame('/missing/ffprobe', ProcessingConfiguration::resolveFfprobePath('/missing/ffprobe', '/missing/bin/ffmpeg', $toggle));
                $this->assertFalse(ProcessingConfiguration::resolveFfprobePath(false, false, $toggle));
            }
        } finally {
            @unlink($dir.'/ffprobe');
            @rmdir($dir);
        }
    }

    public function test_normalize_limits_matches_php(): void
    {
        self::ffi();
        $defaults = [3, 3, 30, 2, 2, 16777216, 8388608, 3145728, 104857600, 8388608, 30, 30, 4, 1];
        $cases = [
            $defaults,
            array_fill(0, 14, 0),
            array_fill(0, 14, -1),
            array_fill(0, 14, PHP_INT_MIN),
            array_fill(0, 14, PHP_INT_MAX),
            [0, 50, 200, 1, 0, 0, 1048576, 262144, 0, 1048576, 0, 1, 10, 1],
            [-1, 51, 201, 0, -1, -1, 1048575, 262143, -1, 1048575, -1, 0, 11, 0],
            [1, 0, 10, 2, 1, 1, 1048577, 262145, 1, 1048577, 1, 2, 0, 2],
        ];
        $random = new Randomizer(new Mt19937(17003));
        for ($i = 0; $i < 200; $i++) {
            $cases[] = array_map(static fn (): int => $random->getInt(-300, 300) * ($random->getInt(0, 1) === 1 ? 1 : 65536), range(1, 14));
        }

        foreach ($cases as $raw) {
            $expected = ProcessingConfiguration::normalizeLimitsPhp($raw);
            $this->assertSame($expected, ProcessingConfiguration::normalizeLimitsNative($raw));
            $this->assertSame($expected, ProcessingConfiguration::normalizeLimits($raw, true));
        }
        $this->assertSame($defaults, ProcessingConfiguration::normalizeLimitsNative($defaults));

        foreach ([[], array_fill(0, 13, 1), array_fill(0, 15, 1)] as $invalid) {
            foreach ([false, true] as $toggle) {
                try {
                    ProcessingConfiguration::normalizeLimits($invalid, $toggle);
                    $this->fail('Expected an invalid limit count to throw.');
                } catch (InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }

    public function test_release_processing_result_success_and_duration_summary_match_php(): void
    {
        self::ffi();
        foreach (ProcessingOutcome::cases() as $outcome) {
            $result = new ReleaseProcessingResult(1, 'guid', $outcome);
            $rust = new ReleaseProcessingResult(1, 'guid', $outcome, useRustFfi: true);
            $this->assertSame($outcome->isSuccessful(), $result->isSuccessfulPhp());
            $this->assertSame($outcome->isSuccessful(), $result->isSuccessfulNative());
            $this->assertSame($outcome->isSuccessful(), $rust->isSuccessful());
        }

        $cases = [
            [0.0, []],
            [1.5, []],
            [4.0, ['download' => 0.5, 'probe' => 2.0, 'media' => 2.0, 'nfo' => 1.0]],
            [1.0, ['download' => 3.0, 'probe' => 1.0]],
            [10.0, ['a' => -1.0, 'b' => -2.0]],
            [2.0, ['a' => 0.0, 'b' => -0.0]],
            [-0.0, ['a' => -0.0]],
            [3.0, ['a' => NAN, 'b' => 1.0]],
            [3.0, ['a' => 1.0, 'b' => NAN]],
            [INF, ['a' => INF, 'b' => 1.0]],
            [1.0, ['a' => INF, 'b' => -INF]],
            [NAN, ['a' => 0.1, 'b' => 0.2]],
            [0.3, ['0' => 0.1, '1' => 0.2, '17' => 0.3]],
            [1e300, ['x' => 1e308, 'y' => 1e308]],
            [5.0, ['download' => 1, 'probe' => 2]],
        ];
        $random = new Randomizer(new Mt19937(17005));
        for ($i = 0; $i < 100; $i++) {
            $stages = [];
            $count = $random->getInt(0, 40);
            for ($s = 0; $s < $count; $s++) {
                $stages['stage_'.$random->getInt(0, 60)] = $random->getFloat(0.0, 5.0);
            }
            $cases[] = [$random->getFloat(0.0, 100.0), $stages];
        }

        foreach ($cases as $index => [$elapsed, $stages]) {
            $result = new ReleaseProcessingResult(1, 'guid', ProcessingOutcome::Completed, elapsedSeconds: $elapsed, stageDurations: $stages);
            $expected = self::canonical($result->durationSummaryPhp());
            $this->assertSame($expected, self::canonical($result->durationSummaryNative()), "case {$index}");
            $rust = new ReleaseProcessingResult(1, 'guid', ProcessingOutcome::Completed, elapsedSeconds: $elapsed, stageDurations: $stages, useRustFfi: true);
            $this->assertSame($expected, self::canonical($rust->durationSummary()), "case {$index}");
        }

        $summary = (new ReleaseProcessingResult(1, 'g', ProcessingOutcome::Completed, elapsedSeconds: 4.0, stageDurations: ['download' => 0.5, 'probe' => 2.0, 'media' => 2.0]))->durationSummaryNative();
        $this->assertSame(['stageCount' => 3, 'stageSeconds' => 4.5, 'slowestStage' => 'probe', 'slowestSeconds' => 2.0, 'unaccountedSeconds' => 0.0], $summary);

        $rust = new ReleaseProcessingResult(1, 'g', ProcessingOutcome::Failed, useRustFfi: true);
        $this->assertTrue($rust->withPerformance(1.0, ['a' => 1.0])->useRustFfi);
        $this->assertFalse((new ReleaseProcessingResult(1, 'g', ProcessingOutcome::Failed))->withPerformance(1.0, [])->useRustFfi);
    }

    public function test_media_tool_factory_driver_configuration_matches_php(): void
    {
        self::ffi();
        $paths = [false, '', '0', '/opt/ffmpeg/bin/ffmpeg', 'ffmpeg', str_repeat('x', 4096)];
        foreach ([1, 30, 0, -5, PHP_INT_MAX] as $timeout) {
            foreach ($paths as $ffmpeg) {
                foreach ($paths as $ffprobe) {
                    $expected = MediaToolFactory::driverConfigurationPhp($timeout, $ffmpeg, $ffprobe);
                    $this->assertSame($expected, MediaToolFactory::driverConfigurationNative($timeout, $ffmpeg, $ffprobe));
                    MediaToolFactory::setUseRustFfi(true);
                    $this->assertSame($expected, MediaToolFactory::driverConfigurationFor($timeout, $ffmpeg, $ffprobe));
                    MediaToolFactory::setUseRustFfi(false);
                }
            }
        }

        $config = $this->makeConfig([
            'ffmpegPath' => '/opt/ffmpeg/bin/ffmpeg',
            'ffprobePath' => '/opt/ffmpeg/bin/ffprobe',
            'mediaFfmpegTimeout' => 17,
        ]);
        foreach ([false, true] as $toggle) {
            MediaToolFactory::setUseRustFfi($toggle);
            $this->assertSame([
                'timeout' => 17,
                'ffmpeg.binaries' => '/opt/ffmpeg/bin/ffmpeg',
                'ffprobe.binaries' => '/opt/ffmpeg/bin/ffprobe',
            ], (new MediaToolFactory($config))->driverConfiguration());
        }
    }

    public function test_search_sync_decisions_match_php(): void
    {
        self::ffi();
        $ops = [
            ReleaseSearchSyncCoordinator::OP_BEGIN,
            ReleaseSearchSyncCoordinator::OP_REQUEST,
            ReleaseSearchSyncCoordinator::OP_DISCARD,
            ReleaseSearchSyncCoordinator::OP_FINISH,
        ];
        foreach ($ops as $op) {
            foreach ([true, false] as $coalesce) {
                foreach ([null, 0, 7, -7, PHP_INT_MAX, PHP_INT_MIN] as $scope) {
                    foreach ([true, false] as $pending) {
                        foreach ([0, 7, 8, -7, PHP_INT_MAX, PHP_INT_MIN] as $arg) {
                            $expected = ReleaseSearchSyncCoordinator::decidePhp($op, $coalesce, $scope, $pending, $arg);
                            $this->assertSame($expected, ReleaseSearchSyncCoordinator::decideNative($op, $coalesce, $scope, $pending, $arg));
                        }
                    }
                }
            }
        }

        foreach ([-1, 4, PHP_INT_MAX] as $invalid) {
            foreach ([false, true] as $toggle) {
                ReleaseSearchSyncCoordinator::setUseRustFfi($toggle);
                try {
                    ReleaseSearchSyncCoordinator::decide($invalid, true, 1, true, 1);
                    $this->fail('Expected an unknown operation to throw.');
                } catch (InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }

    public function test_coordinator_and_collector_sequences_match_php(): void
    {
        self::ffi();
        $random = new Randomizer(new Mt19937(17007));
        for ($run = 0; $run < 60; $run++) {
            $coalesce = $random->getInt(0, 3) !== 0;
            $steps = [];
            for ($i = 0; $i < 40; $i++) {
                $steps[] = [$random->getInt(0, 4), $random->getInt(1, 3), $random->getFloat(0.0, 12.0)];
            }

            $outcomes = [];
            foreach ([false, true] as $toggle) {
                ReleaseSearchSyncCoordinator::setUseRustFfi($toggle);
                PersistenceMetricsCollector::setUseRustFfi($toggle);
                $collector = new PersistenceMetricsCollector;
                $executed = [];
                $coordinator = new ReleaseSearchSyncCoordinator(
                    $collector,
                    static function (int $releaseId) use (&$executed): void {
                        $executed[] = $releaseId;
                    },
                    $coalesce,
                );
                $metrics = [];
                foreach ($steps as [$op, $id, $ms]) {
                    switch ($op) {
                        case 0:
                            $collector->beginReleaseScope($id);
                            $coordinator->beginReleaseScope($id);
                            break;
                        case 1:
                            $coordinator->request($id);
                            break;
                        case 2:
                            $coordinator->discard($id);
                            break;
                        case 3:
                            $coordinator->finishReleaseScope();
                            $metrics[] = $collector->finishReleaseScope();
                            break;
                        default:
                            $collector->recordDatabaseStatement($ms);
                    }
                }
                $outcomes[] = [$executed, array_map(static fn (PersistenceMetrics $m): array => self::canonical([
                    $m->databaseStatements, $m->databaseMilliseconds, $m->searchSyncRequests, $m->searchSyncExecutions,
                ]), $metrics)];
            }
            $this->assertSame($outcomes[0], $outcomes[1], "run {$run}");
        }

        foreach ([false, true] as $toggle) {
            ReleaseSearchSyncCoordinator::setUseRustFfi($toggle);
            PersistenceMetricsCollector::setUseRustFfi($toggle);
            $collector = new PersistenceMetricsCollector;
            $executed = [];
            $coordinator = new ReleaseSearchSyncCoordinator($collector, static function (int $id) use (&$executed): void {
                $executed[] = $id;
            });
            $collector->beginReleaseScope(5);
            $coordinator->beginReleaseScope(5);
            $coordinator->request(5);
            $coordinator->request(5);
            $coordinator->request(6);
            $collector->recordDatabaseStatement(1.25);
            $coordinator->finishReleaseScope();
            $metrics = $collector->finishReleaseScope();
            $this->assertSame([6, 5], $executed);
            $this->assertSame([1, 1.25, 2, 1], [$metrics->databaseStatements, $metrics->databaseMilliseconds, $metrics->searchSyncRequests, $metrics->searchSyncExecutions]);
        }
    }

    public function test_metrics_apply_event_matches_php_including_overflow(): void
    {
        self::ffi();
        $random = new Randomizer(new Mt19937(17011));
        $counterSets = [
            [0, 0.0, 0, 0],
            [5, 12.5, 2, 1],
            [PHP_INT_MAX - 1, 1e300, PHP_INT_MAX - 1, PHP_INT_MAX - 1],
            [3, NAN, 0, 0],
            [3, -0.0, 0, 0],
        ];
        for ($i = 0; $i < 50; $i++) {
            $counterSets[] = [$random->getInt(0, 1000), $random->getFloat(0.0, 5000.0), $random->getInt(0, 50), $random->getInt(0, 50)];
        }

        foreach ([0, 1, 2] as $event) {
            foreach ([null, 0, 4, -4, PHP_INT_MIN] as $scope) {
                foreach ([0, 4, 5, PHP_INT_MIN] as $id) {
                    foreach ([0.0, -0.0, 1.5, INF, NAN] as $ms) {
                        foreach ($counterSets as $counters) {
                            $expected = self::canonical(PersistenceMetricsCollector::applyEventPhp($event, $scope, $id, $ms, $counters));
                            $this->assertSame($expected, self::canonical(PersistenceMetricsCollector::applyEventNative($event, $scope, $id, $ms, $counters)));
                        }
                    }
                }
            }
        }

        // Counter overflow defers to PHP, which promotes to float; typed properties then reject it.
        $overflowing = [PHP_INT_MAX, 0.0, PHP_INT_MAX, PHP_INT_MAX];
        foreach ([[0, 0], [1, 2], [2, 3]] as [$event, $slot]) {
            $php = PersistenceMetricsCollector::applyEventPhp($event, 1, 1, 1.0, $overflowing);
            $native = PersistenceMetricsCollector::applyEventNative($event, 1, 1, 1.0, $overflowing);
            $this->assertIsFloat($php[$slot]);
            $this->assertSame(self::canonical($php), self::canonical($native));
        }

        foreach ([false, true] as $toggle) {
            PersistenceMetricsCollector::setUseRustFfi($toggle);
            $collector = new PersistenceMetricsCollector;
            $collector->beginReleaseScope(1);
            (new ReflectionProperty($collector, 'databaseStatements'))->setValue($collector, PHP_INT_MAX);
            try {
                $collector->recordDatabaseStatement(1.0);
                $this->fail('Expected statement counter overflow to throw.');
            } catch (TypeError) {
                $this->addToAssertionCount(1);
            }

            try {
                PersistenceMetricsCollector::applyEvent(9, 1, 1, 0.0, [0, 0.0, 0, 0]);
                $this->fail('Expected an unknown event to throw.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_native_paths_fall_back_to_php_without_library(): void
    {
        foreach ([MediaToolFactory::class, ReleaseSearchSyncCoordinator::class, PersistenceMetricsCollector::class] as $class) {
            (new ReflectionProperty($class, 'ffi'))->setValue(null, false);
        }

        $this->assertNull(MediaToolFactory::additionalFfi());
        $this->assertSame(['timeout' => 3, 'ffmpeg.binaries' => 'ff'], MediaToolFactory::driverConfigurationNative(3, 'ff', ''));
        $this->assertSame([null, false, 9], ReleaseSearchSyncCoordinator::decideNative(ReleaseSearchSyncCoordinator::OP_REQUEST, true, null, false, 9));
        $this->assertSame([1, 2.5, 0, 0], PersistenceMetricsCollector::applyEventNative(0, 1, 0, 2.5, [0, 0.0, 0, 0]));

        ReleaseSearchSyncCoordinator::setUseRustFfi(true);
        PersistenceMetricsCollector::setUseRustFfi(true);
        $collector = new PersistenceMetricsCollector;
        $executed = [];
        $coordinator = new ReleaseSearchSyncCoordinator($collector, static function (int $id) use (&$executed): void {
            $executed[] = $id;
        });
        $collector->beginReleaseScope(2);
        $coordinator->beginReleaseScope(2);
        $coordinator->request(2);
        $coordinator->finishReleaseScope();
        $this->assertSame([2], $executed);
        $this->assertSame(1, $collector->finishReleaseScope()->searchSyncExecutions);

        // Readonly classes cache the handle per process; invalid input still defers to PHP.
        $result = new ReleaseProcessingResult(1, 'g', ProcessingOutcome::Completed, stageDurations: ['a' => 1.0], useRustFfi: true);
        $this->assertTrue($result->isSuccessful());
        $this->assertSame('"', ProcessingConfiguration::killStringNative(false, 10));
    }

    public function test_direct_c_abi_null_overflow_and_small_buffer_contracts(): void
    {
        $ffi = self::ffi();
        $small = $ffi->new('char[4]');
        $big = $ffi->new('char[64]');

        // Short buffers report the required size; null inputs and lengths above isize::MAX
        // (-1 converts to SIZE_MAX) are rejected.
        // @phpstan-ignore method.notFound
        $this->assertSame(-35, $ffi->nntmux_pcfg_kill_string('/t', 2, 1, 5, $small, 4));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_pcfg_kill_string(null, 3, 1, 5, $small, 4));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_pcfg_kill_string('/t', -1, 1, 5, $small, 4));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_pcfg_kill_string('/t', 2, 1, 5, null, 64));
        // @phpstan-ignore method.notFound
        $this->assertSame(1, $ffi->nntmux_pcfg_kill_string(null, 0, 0, 5, $small, 4));
        // @phpstan-ignore method.notFound
        $this->assertSame(-16, $ffi->nntmux_pcfg_ffprobe_sibling('/usr/bin/ffmpeg', 15, $small, 4));
        // @phpstan-ignore method.notFound
        $this->assertSame(8, $ffi->nntmux_pcfg_ffprobe_sibling('', 0, $big, 64));
        $this->assertSame('/ffprobe', FFI::string($big, 8));

        $limits = $ffi->new('int64_t[14]');
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_pcfg_normalize_limits($limits, 13, $limits, 14));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_pcfg_normalize_limits($limits, 14, $limits, 13));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_pcfg_normalize_limits(null, 14, $limits, 14));
        // @phpstan-ignore method.notFound
        $this->assertSame(14, $ffi->nntmux_pcfg_normalize_limits($limits, 14, $limits, 14));

        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_rpresult_outcome_successful('Completed', 9));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_rpresult_outcome_successful(null, 9));
        // @phpstan-ignore method.notFound
        $this->assertSame(1, $ffi->nntmux_rpresult_outcome_successful('passworded', 10));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_rpresult_duration_summary('', 0, 1.0, $small, 4));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_rpresult_duration_summary('1234567', 7, 1.0, $big, 64));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_rpresult_duration_summary(null, 8, 1.0, $big, 64));
        // @phpstan-ignore method.notFound
        $this->assertSame(32, $ffi->nntmux_rpresult_duration_summary('', 0, 1.0, $big, 64));

        // @phpstan-ignore method.notFound
        $this->assertSame(3, $ffi->nntmux_mtf_driver_mask(1, 1, 1, 1));
        // @phpstan-ignore method.notFound
        $this->assertSame(0, $ffi->nntmux_mtf_driver_mask(0, 9, 1, 0));

        $decision = $ffi->new('int64_t[5]');
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_rssc_decide(9, 1, 1, 1, 1, 1, $decision, 5));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_rssc_decide(3, 1, 1, 1, 1, 1, $decision, 4));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_rssc_decide(3, 1, 1, 1, 1, 1, null, 5));
        // @phpstan-ignore method.notFound
        $this->assertSame(0, $ffi->nntmux_rssc_decide(3, 1, 1, PHP_INT_MIN, 1, 0, $decision, 5));
        $this->assertSame([0, 0, 0, 1, PHP_INT_MIN], [$decision[0], $decision[1], $decision[2], $decision[3], $decision[4]]);

        $counters = $ffi->new('int64_t[3]');
        $ms = $ffi->new('double');
        $counters[0] = PHP_INT_MAX;
        FFI::memcpy($ms, pack('e', 2.0), 8);
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_pmc_apply(0, 1, 1, 0, 1.0, $counters, 3, FFI::addr($ms)));
        $this->assertSame([PHP_INT_MAX, 2.0], [$counters[0], unpack('e', FFI::string(FFI::addr($ms), 8))[1]]);
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_pmc_apply(7, 1, 1, 1, 0.0, $counters, 3, FFI::addr($ms)));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_pmc_apply(1, 1, 1, 1, 0.0, $counters, 2, FFI::addr($ms)));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_pmc_apply(1, 1, 1, 1, 0.0, null, 3, FFI::addr($ms)));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_pmc_apply(1, 1, 1, 1, 0.0, $counters, 3, null));
        // @phpstan-ignore method.notFound
        $this->assertSame(0, $ffi->nntmux_pmc_apply(2, 0, 0, 0, 0.0, $counters, 3, FFI::addr($ms)));
        // @phpstan-ignore method.notFound
        $this->assertSame(1, $ffi->nntmux_pmc_apply(2, 1, 1, 1, 0.0, $counters, 3, FFI::addr($ms)));
        $this->assertSame(1, $counters[2]);
    }

    /**
     * Run with:
     * NNTMUX_ADDITIONAL_CONFIG_METRICS_SYNC_BENCH=1 php artisan test --compact tests/Unit/RustAdditionalConfigMetricsSyncParityTest.php --filter=test_benchmark_paired_workloads
     */
    public function test_benchmark_paired_workloads(): void
    {
        if (getenv('NNTMUX_ADDITIONAL_CONFIG_METRICS_SYNC_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_ADDITIONAL_CONFIG_METRICS_SYNC_BENCH=1 to run paired benchmarks.');
        }

        self::ffi();
        $defaults = [3, 3, 30, 2, 2, 16777216, 8388608, 3145728, 104857600, 8388608, 30, 30, 4, 1];
        $outOfRange = [-4, 90, 2, 0, -1, -16, 1024, 4096, -1, 512, -30, 0, 25, 0];
        $longTimeout = '/opt/homebrew/Cellar/coreutils/9.5/libexec/gnubin with spaces/'.str_repeat('nested/', 20).'timeout';
        $stagesCommon = ['claim' => 0.002, 'download' => 1.25, 'archive' => 0.41, 'media' => 2.8, 'nfo' => 0.03, 'persist' => 0.06];
        $stagesUncommon = [];
        for ($i = 0; $i < 40; $i++) {
            $stagesUncommon["stage_{$i}"] = 0.01 * (($i * 37) % 23);
        }
        $common = new ReleaseProcessingResult(1, 'g', ProcessingOutcome::Completed, elapsedSeconds: 4.8, stageDurations: $stagesCommon);
        $uncommon = new ReleaseProcessingResult(1, 'g', ProcessingOutcome::AwaitingLateArticles, elapsedSeconds: 2.0, stageDurations: $stagesUncommon);
        $counters = [12, 48.75, 2, 1];

        $workloads = [
            'killString/common' => [
                static fn () => ProcessingConfiguration::killStringPhp('/usr/bin/timeout', 60),
                static fn () => ProcessingConfiguration::killStringNative('/usr/bin/timeout', 60),
            ],
            'killString/uncommon' => [
                static fn () => ProcessingConfiguration::killStringPhp($longTimeout, 86400),
                static fn () => ProcessingConfiguration::killStringNative($longTimeout, 86400),
            ],
            'ffprobeSibling/common' => [
                static fn () => ProcessingConfiguration::ffprobeSiblingCandidatePhp('/usr/local/bin/ffmpeg'),
                static fn () => ProcessingConfiguration::ffprobeSiblingCandidateNative('/usr/local/bin/ffmpeg'),
            ],
            'ffprobeSibling/uncommon' => [
                static fn () => ProcessingConfiguration::ffprobeSiblingCandidatePhp('///opt//ffmpeg-7.1/'.str_repeat('deep/', 30).'bin//ffmpeg'),
                static fn () => ProcessingConfiguration::ffprobeSiblingCandidateNative('///opt//ffmpeg-7.1/'.str_repeat('deep/', 30).'bin//ffmpeg'),
            ],
            'normalizeLimits/common' => [
                static fn () => ProcessingConfiguration::normalizeLimitsPhp($defaults),
                static fn () => ProcessingConfiguration::normalizeLimitsNative($defaults),
            ],
            'normalizeLimits/uncommon' => [
                static fn () => ProcessingConfiguration::normalizeLimitsPhp($outOfRange),
                static fn () => ProcessingConfiguration::normalizeLimitsNative($outOfRange),
            ],
            'isSuccessful/common' => [
                static fn () => $common->isSuccessfulPhp(),
                static fn () => $common->isSuccessfulNative(),
            ],
            'isSuccessful/uncommon' => [
                static fn () => $uncommon->isSuccessfulPhp(),
                static fn () => $uncommon->isSuccessfulNative(),
            ],
            'durationSummary/common' => [
                static fn () => $common->durationSummaryPhp(),
                static fn () => $common->durationSummaryNative(),
            ],
            'durationSummary/uncommon' => [
                static fn () => $uncommon->durationSummaryPhp(),
                static fn () => $uncommon->durationSummaryNative(),
            ],
            'driverConfiguration/common' => [
                static fn () => MediaToolFactory::driverConfigurationPhp(30, '/usr/bin/ffmpeg', '/usr/bin/ffprobe'),
                static fn () => MediaToolFactory::driverConfigurationNative(30, '/usr/bin/ffmpeg', '/usr/bin/ffprobe'),
            ],
            'driverConfiguration/uncommon' => [
                static fn () => MediaToolFactory::driverConfigurationPhp(1, false, ''),
                static fn () => MediaToolFactory::driverConfigurationNative(1, false, ''),
            ],
            'syncDecide/common' => [
                static fn () => ReleaseSearchSyncCoordinator::decidePhp(ReleaseSearchSyncCoordinator::OP_REQUEST, true, 912345, false, 912345),
                static fn () => ReleaseSearchSyncCoordinator::decideNative(ReleaseSearchSyncCoordinator::OP_REQUEST, true, 912345, false, 912345),
            ],
            'syncDecide/uncommon' => [
                static fn () => ReleaseSearchSyncCoordinator::decidePhp(ReleaseSearchSyncCoordinator::OP_FINISH, true, 912345, true, 0),
                static fn () => ReleaseSearchSyncCoordinator::decideNative(ReleaseSearchSyncCoordinator::OP_FINISH, true, 912345, true, 0),
            ],
            'metricsApply/common' => [
                static fn () => PersistenceMetricsCollector::applyEventPhp(0, 912345, 0, 0.84, $counters),
                static fn () => PersistenceMetricsCollector::applyEventNative(0, 912345, 0, 0.84, $counters),
            ],
            'metricsApply/uncommon' => [
                static fn () => PersistenceMetricsCollector::applyEventPhp(2, 912345, 912346, 0.0, $counters),
                static fn () => PersistenceMetricsCollector::applyEventNative(2, 912345, 912346, 0.0, $counters),
            ],
        ];

        $percentile99 = static function (array $ns): float {
            sort($ns);

            return (float) $ns[(int) ceil(count($ns) * 0.99) - 1];
        };

        $random = new Randomizer(new Mt19937(20261024));
        foreach ($workloads as $name => [$phpCall, $rustCall]) {
            $this->assertSame(self::canonical($phpCall()), self::canonical($rustCall()), $name);
            for ($w = 0; $w < 512; $w++) {
                $phpCall();
                $rustCall();
            }

            $reps = [];
            for ($rep = 0; $rep < 3; $rep++) {
                $samples = [[], []];
                for ($pair = 0; $pair < 4096; $pair++) {
                    $first = $random->getInt(0, 1);
                    foreach ([$first, 1 - $first] as $side) {
                        $fn = $side === 0 ? $phpCall : $rustCall;
                        $t0 = hrtime(true);
                        $fn();
                        $samples[$side][] = hrtime(true) - $t0;
                    }
                }
                $phpMean = array_sum($samples[0]) / count($samples[0]);
                $rustMean = array_sum($samples[1]) / count($samples[1]);
                $reps[] = sprintf(
                    'rep%d=%.2fx/%.2fx (php %.0f/%.0fns rust %.0f/%.0fns)',
                    $rep + 1,
                    $phpMean / $rustMean,
                    $percentile99($samples[0]) / $percentile99($samples[1]),
                    $phpMean,
                    $percentile99($samples[0]),
                    $rustMean,
                    $percentile99($samples[1]),
                );
            }
            fwrite(STDOUT, sprintf("%-30s %s\n", $name, implode('  ', $reps)));
        }
    }
}
