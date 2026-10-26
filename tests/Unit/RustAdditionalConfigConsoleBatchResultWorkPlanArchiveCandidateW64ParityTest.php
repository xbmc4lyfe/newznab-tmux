<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\ConsoleOutputService;
use App\Services\AdditionalProcessing\DTO\AdditionalBatchResult;
use App\Services\AdditionalProcessing\DTO\AdditionalWorkPlan;
use App\Services\AdditionalProcessing\DTO\ArchiveCandidate;
use FFI;
use FFI\CData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use RuntimeException;

final class RustAdditionalConfigConsoleBatchResultWorkPlanArchiveCandidateW64ParityTest extends TestCase
{
    /** Class => batch entry point; each also has `*Php()` and `*Native()` variants. */
    private const ENTRIES = [
        ProcessingConfiguration::class => 'w64ProcessingConfigPlanBatch',
        ConsoleOutputService::class => 'w64ConsoleOutputPlanBatch',
        AdditionalBatchResult::class => 'w64BatchResultPlanBatch',
        AdditionalWorkPlan::class => 'w64WorkPlanEvalBatch',
        ArchiveCandidate::class => 'w64ArchiveCandidatePlanBatch',
    ];

    /** Class => Wave 64.2 export. */
    private const SYMBOLS = [
        ProcessingConfiguration::class => 'nntmux_w642_processing_config_plan_batch',
        ConsoleOutputService::class => 'nntmux_w642_console_output_plan_batch',
        AdditionalBatchResult::class => 'nntmux_w642_batch_result_plan_batch',
        AdditionalWorkPlan::class => 'nntmux_w642_work_plan_eval_batch',
        ArchiveCandidate::class => 'nntmux_w642_archive_candidate_plan_batch',
    ];

    /** Class => class-distinct C declaration constant. */
    private const CDEFS = [
        ProcessingConfiguration::class => 'W64_PROCESSING_CONFIGURATION_FFI_CDEF',
        ConsoleOutputService::class => 'W64_CONSOLE_OUTPUT_SERVICE_FFI_CDEF',
        AdditionalBatchResult::class => 'W64_ADDITIONAL_BATCH_RESULT_FFI_CDEF',
        AdditionalWorkPlan::class => 'W64_ADDITIONAL_WORK_PLAN_FFI_CDEF',
        ArchiveCandidate::class => 'W64_ARCHIVE_CANDIDATE_FFI_CDEF',
    ];

    /** Class => summary words after the per-row words (the digest included). */
    private const TAIL = [
        ProcessingConfiguration::class => 44,
        ConsoleOutputService::class => 27,
        AdditionalBatchResult::class => 48,
        AdditionalWorkPlan::class => 22,
        ArchiveCandidate::class => 19,
    ];

    /** Class => public tail-size constant. */
    private const TAIL_CONSTANTS = [
        ProcessingConfiguration::class => 'W64_PROCESSING_CONFIGURATION_TAIL',
        ConsoleOutputService::class => 'W64_CONSOLE_OUTPUT_SERVICE_TAIL',
        AdditionalBatchResult::class => 'W64_ADDITIONAL_BATCH_RESULT_TAIL',
        AdditionalWorkPlan::class => 'W64_ADDITIONAL_WORK_PLAN_TAIL',
        ArchiveCandidate::class => 'W64_ARCHIVE_CANDIDATE_TAIL',
    ];

    /**
     * Common mean/p99 then Uncommon mean/p99 speedups (PHP ns / Rust ns), each the minimum of
     * three runs of test_paired_randomized_common_and_uncommon_benchmarks (seed 64, 40
     * interleaved rounds of 10 pairs per class and workload after 2 warmups, 400 pooled samples
     * per metric, each side the fastest of 3 back-to-back calls, batches of 320 Common or
     * 320-640 Uncommon rows, release Rust, PHP 8.5.12 CLI, Apple Silicon macOS on a shared host
     * at load average 7-8). Timings include dispatch, implode, the FFI call and unpack('P*'). A
     * class defaults to Rust only when all four reach 1.5x.
     */
    private const QUALIFICATION = [
        ProcessingConfiguration::class => [12.598, 10.157, 12.772, 11.126],
        ConsoleOutputService::class => [9.239, 8.251, 8.870, 8.135],
        AdditionalBatchResult::class => [8.980, 7.867, 9.005, 8.156],
        AdditionalWorkPlan::class => [14.825, 13.310, 14.938, 13.910],
        ArchiveCandidate::class => [7.995, 6.887, 7.385, 6.767],
    ];

    /** Interleaved benchmark rounds; each times PAIRS pairs per class and workload. */
    private const ROUNDS = 40;

    private const PAIRS = 10;

    private const WARMUPS = 2;

    /** Back-to-back calls per side of a pair; the fastest is kept (drops preemption spikes). */
    private const REPEATS = 3;

    /** Batches per class and workload in the randomized parity sweep. */
    private const PARITY_BATCHES = 6;

    private const WORDS = ['the', 'great', 'night', 'big', 'movie', 'show', 'pro', 'saga', 'old', 'new', 'dark', 'star', 'wild', 'hunt', 'city', 'last'];

    private const FEATURES = ['video', 'thumbnails', 'audio', 'jpg', 'mediainfo', 'passwords', 'par2', 'debug'];

    private const OUTCOMES = ['completed', 'completed', 'completed', 'no-useful-artifacts', 'passworded', 'group-unavailable', 'temporary-workspace-unavailable',
        'timed-out', 'deleted-after-timeout', 'deleted-broken-nzb', 'deleted-par2-only', 'not-found', 'awaiting-late-articles', 'failed'];

    private const MEDIA = ['sample', 'jpg', 'video', 'audio', 'mediainfo', 'nfo', 'filelist'];

    private const ERRORS = ['', '', '', 'Read timed out', 'Connection reset by peer', '430 No Such Article', 'Archive is password protected', 'CRC mismatch',
        'unrar exited with 3', 'Socket closed', 'Operation timeout after 30s'];

    private const ARCHIVE_EXTENSIONS = ['.part01.rar', '.part02.rar', '.part10.rar', '.rar', '.r00', '.r01', '.r123', '.7z', '.7z.001', '.7z.002', '.zip', '.z01',
        '.mkv.001', '.mkv.002', '.mkv', '.nfo', '.par2', '.vol00+01.par2', ''];

    protected function tearDown(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $class::resetW64RustFfi();
        }
        parent::tearDown();
    }

    public function test_wave_methods_and_constants_exist(): void
    {
        foreach (self::ENTRIES as $class => $entry) {
            $reflection = new ReflectionClass($class);
            foreach (['enableW64RustFfi', 'disableW64RustFfi', 'resetW64RustFfi', 'usesW64RustFfi', 'withW64RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                self::assertTrue($reflection->hasMethod($method), $class.'::'.$method);
                self::assertTrue($reflection->getMethod($method)->isPublic() && $reflection->getMethod($method)->isStatic(), $class.'::'.$method);
                self::assertSame($class, $reflection->getMethod($method)->getDeclaringClass()->getName(), $class.'::'.$method);
            }
            $state = $reflection->getMethod('w64RustFfiState');
            self::assertTrue($state->isPrivate() && $state->isStatic(), $class);
            self::assertSame(2, $state->getNumberOfParameters(), $class);
            self::assertSame(['enabled', 'ffi', 'out', 'capacity'], array_keys($state->getStaticVariables()), $class.' keeps Wave 64 state in function-local statics');
            $default = $reflection->getReflectionConstant('W64_RUST_FFI_DEFAULT');
            self::assertInstanceOf(ReflectionClassConstant::class, $default, $class);
            self::assertIsBool($default->getValue(), $class);
            self::assertTrue($default->isPublic(), $class);
            self::assertSame($class, $reflection->getReflectionConstant(self::CDEFS[$class])?->getDeclaringClass()->getName(), $class);
            self::assertStringContainsString(self::SYMBOLS[$class], (string) $reflection->getConstant(self::CDEFS[$class]), $class);
            self::assertSame(self::TAIL[$class], $reflection->getConstant(self::TAIL_CONSTANTS[$class]), $class);
            foreach ($reflection->getProperties() as $property) {
                self::assertStringNotContainsString('w64', strtolower($property->getName()), $class.' adds no Wave 64 property');
            }
        }
        // Readonly classes cannot hold static properties; the Wave 64 state must not need them.
        foreach ([ProcessingConfiguration::class, AdditionalBatchResult::class, AdditionalWorkPlan::class, ArchiveCandidate::class] as $class) {
            $reflection = new ReflectionClass($class);
            self::assertTrue($reflection->isReadOnly() && $reflection->isFinal(), $class);
            self::assertSame([], $reflection->getStaticProperties(), $class);
        }
        self::assertSame(['useRustFfi', 'ffi', 'w416UseRustFfi', 'w416Ffi'], array_keys((new ReflectionClass(ConsoleOutputService::class))->getStaticProperties()));
        self::assertCount(5, array_unique(self::CDEFS));
    }

    public function test_existing_public_api_is_preserved(): void
    {
        $expected = [
            ProcessingConfiguration::class => ['__construct', 'resolveFfprobePath', 'ffprobeSiblingCandidate', 'ffprobeSiblingCandidatePhp', 'ffprobeSiblingCandidateNative',
                'normalizeLimits', 'normalizeLimitsPhp', 'normalizeLimitsNative', 'getKillString', 'killString', 'killStringPhp', 'killStringNative', 'ffi', 'useW426RustFfi',
                'usesW426RustFfi', 'resetW426RustFfi', 'summarizeLimitConfigurationBatch', 'summarizeLimitConfigurationBatchPhp', 'summarizeLimitConfigurationBatchNative',
                'normalizeLimitsBatch', 'normalizeLimitsBatchNative', 'normalizeLimitsBatchPhp', 'usesW52RustFfi', 'withW52RustFfi'],
            ConsoleOutputService::class => ['__construct', 'setUseRustFfi', 'usesRustFfi', 'formatStatus', 'formatStatusPhp', 'formatStatusNative', 'echo', 'debug',
                'echoDescription', 'echoReleaseStart', 'setProcessTitle', 'echoCompressedFailure', 'echoPar2Mapped', 'echoMediaSource', 'echoReleaseTimeout',
                'echoReleaseTimeoutDeleted', 'echoReleaseDeleted', 'endOutput', 'isEnabled', 'useW416RustFfi', 'usesW416RustFfi', 'resetW416RustFfi',
                'summarizeConsoleEventBatch', 'summarizeConsoleEventBatchPhp', 'summarizeConsoleEventBatchNative', 'consoleLineBatch', 'consoleLineBatchNative',
                'consoleLineBatchPhp', 'usesW52RustFfi', 'withW52RustFfi'],
            AdditionalBatchResult::class => ['__construct', 'empty', 'setupFailed', 'claimedCount', 'attemptedCount', 'successfulCount', 'unsuccessfulCount', 'outcomeCounts',
                'outcomeCountsPhp', 'firstResult', 'hasOutcome', 'withPerformance', 'releasesPerSecond', 'averageReleaseSeconds', 'artifactsCreatedCount',
                'artifactYieldPercent', 'downloadMetrics', 'persistenceMetrics', 'useW47OutcomeTelemetryRustFfi', 'usesW47OutcomeTelemetryRustFfi',
                'resetW47OutcomeTelemetryRustFfi', 'summarizeOutcomeTelemetry', 'summarizeOutcomeTelemetryPhp', 'summarizeOutcomeTelemetryNative', 'summarizeRunBatch',
                'summarizeRunBatchPhp', 'summarizeRunBatchNative', 'withW54RustFfi', 'usesW54RustFfi'],
            AdditionalWorkPlan::class => ['__construct', 'withMappedNames', 'hasSupportedCandidates', 'hasCompressedFile', 'archiveSetCount', 'archiveSetCountPhp',
                'archiveSetCountNative', 'setKeyOf', 'orderedArchiveCandidates', 'prioritizedArchiveCandidates', 'useW47TaskScheduleRustFfi', 'usesW47TaskScheduleRustFfi',
                'resetW47TaskScheduleRustFfi', 'scheduleTaskBatch', 'scheduleTaskBatchPhp', 'scheduleTaskBatchNative', 'summarizePlanBatch', 'summarizePlanBatchPhp',
                'summarizePlanBatchNative', 'withW54RustFfi', 'usesW54RustFfi'],
            ArchiveCandidate::class => ['useRustFfi', 'usesRustFfi', '__construct', 'volumeSet', 'volumeSetKey', 'volumeSetKeyNative', 'volumeSetKeyPhp', 'fileName',
                'fileNameNative', 'fileNamePhp', 'volumeSetGroupBatch', 'volumeSetGroupBatchPhp', 'volumeSetGroupBatchNative', 'withW52RustFfi', 'usesW52RustFfi'],
        ];
        foreach ($expected as $class => $methods) {
            $reflection = new ReflectionClass($class);
            foreach ($methods as $method) {
                self::assertTrue($reflection->hasMethod($method) && $reflection->getMethod($method)->isPublic(), $class.'::'.$method);
            }
        }
        self::assertSame(14, ProcessingConfiguration::LIMIT_COUNT);
        self::assertSame(8, ArchiveCandidate::W52_ARCHIVE_HEADER);
        // The earlier-wave helpers answer the same whichever way the Wave 64 toggle points.
        $earlier = static fn (): array => [
            ProcessingConfiguration::normalizeLimitsPhp([3, 3, 30, 2, 2, 16777216, 8388608, 3145728, 104857600, 8388608, 30, 30, 4, 1]),
            ProcessingConfiguration::killStringPhp('/usr/bin/timeout', 30), ProcessingConfiguration::ffprobeSiblingCandidatePhp('/usr/bin/ffmpeg'),
            ConsoleOutputService::formatStatusPhp(ConsoleOutputService::STATUS_COMPRESSED_FAILURE, 3), ConsoleOutputService::consoleLineBatchPhp(['x', '']),
            AdditionalWorkPlan::scheduleTaskBatchPhp(['a.mkv', 'b.nfo'], [100, 5], 1000),
            ArchiveCandidate::volumeSetKeyPhp('movie.part01.rar'), ArchiveCandidate::fileNamePhp('"Movie.R00" yEnc (1/5)'),
            ArchiveCandidate::volumeSetGroupBatchPhp(['a.rar', 'a.r00', 'b.7z']),
        ];
        $baseline = $earlier();
        foreach (array_keys(self::ENTRIES) as $class) {
            foreach ([true, false] as $enabled) {
                self::assertEquals($baseline, $class::withW64RustFfi($enabled, $earlier), $class);
            }
        }
    }

    public function test_defaults_match_recorded_qualification(): void
    {
        foreach (self::QUALIFICATION as $class => $speedups) {
            self::assertCount(4, $speedups, $class);
            self::assertSame(min($speedups) >= 1.5, $this->default($class), $class);
            self::assertSame($this->default($class), $class::usesW64RustFfi(), $class);
        }
    }

    public function test_enable_disable_reset_and_with_toggle_scope_routing(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $default = $this->default($class);
            $class::enableW64RustFfi();
            self::assertTrue($class::usesW64RustFfi(), $class);
            $class::disableW64RustFfi();
            self::assertFalse($class::usesW64RustFfi(), $class);
            self::assertTrue($class::withW64RustFfi(true, static fn (): bool => $class::usesW64RustFfi()), $class);
            self::assertFalse($class::usesW64RustFfi(), $class.' with restores an explicit override');
            $class::resetW64RustFfi();
            self::assertSame($default, $class::usesW64RustFfi(), $class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW64RustFfi($enabled, static function () use ($class, $enabled): array {
                    $inner = $class::withW64RustFfi(! $enabled, static fn (): bool => $class::usesW64RustFfi());

                    return [$class::usesW64RustFfi(), $inner];
                });
                self::assertSame([$enabled, ! $enabled], $seen, $class);
                self::assertSame($default, $class::usesW64RustFfi(), $class);
            }
            $thrown = null;
            try {
                $class::withW64RustFfi(! $default, static fn () => throw new RuntimeException('boom'));
            } catch (RuntimeException $e) {
                $thrown = $e->getMessage();
            }
            self::assertSame('boom', $thrown, 'The callback exception must propagate.');
            self::assertSame($default, $class::usesW64RustFfi(), $class);
        }
    }

    public function test_toggles_stay_independent_of_each_other_and_earlier_switches(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $seen = $class::withW64RustFfi(! $this->default($class), static fn (): array => array_map(
                static fn (string $other): bool => $other::usesW64RustFfi(),
                array_keys(self::ENTRIES),
            ));
            foreach (array_keys(self::ENTRIES) as $i => $other) {
                self::assertSame($other === $class ? ! $this->default($other) : $this->default($other), $seen[$i], $class.' vs '.$other);
            }
        }
        $earlier = [
            ProcessingConfiguration::class => ['usesW426RustFfi', 'usesW52RustFfi'],
            ConsoleOutputService::class => ['usesRustFfi', 'usesW416RustFfi', 'usesW52RustFfi'],
            AdditionalBatchResult::class => ['usesW47OutcomeTelemetryRustFfi', 'usesW54RustFfi'],
            AdditionalWorkPlan::class => ['usesW47TaskScheduleRustFfi', 'usesW54RustFfi'],
            ArchiveCandidate::class => ['usesRustFfi', 'usesW52RustFfi'],
        ];
        foreach ($earlier as $class => $methods) {
            $before = array_map(static fn (string $method): bool => $class::$method(), $methods);
            foreach ([true, false] as $enabled) {
                $inside = $class::withW64RustFfi($enabled, static fn (): array => array_map(static fn (string $method): bool => $class::$method(), $methods));
                self::assertSame($before, $inside, $class);
            }
            $class::resetW64RustFfi();
            self::assertSame($before, array_map(static fn (string $method): bool => $class::$method(), $methods), $class.' reset leaves earlier toggles alone');
        }
        $consoleRust = ConsoleOutputService::usesRustFfi();
        $archiveRust = ArchiveCandidate::usesRustFfi();
        try {
            foreach ([true, false] as $enabled) {
                ProcessingConfiguration::useW426RustFfi($enabled);
                ConsoleOutputService::setUseRustFfi($enabled);
                ConsoleOutputService::useW416RustFfi($enabled);
                AdditionalBatchResult::useW47OutcomeTelemetryRustFfi($enabled);
                AdditionalWorkPlan::useW47TaskScheduleRustFfi($enabled);
                ArchiveCandidate::useRustFfi($enabled);
                foreach (array_keys(self::ENTRIES) as $class) {
                    self::assertSame($this->default($class), $class::usesW64RustFfi(), $class);
                    $scoped = \in_array($class, [AdditionalBatchResult::class, AdditionalWorkPlan::class], true) ? 'withW54RustFfi' : 'withW52RustFfi';
                    self::assertSame($this->default($class), $class::$scoped($enabled, static fn (): bool => $class::usesW64RustFfi()), $class);
                }
            }
        } finally {
            ProcessingConfiguration::resetW426RustFfi();
            ConsoleOutputService::setUseRustFfi($consoleRust);
            ConsoleOutputService::resetW416RustFfi();
            AdditionalBatchResult::resetW47OutcomeTelemetryRustFfi();
            AdditionalWorkPlan::resetW47TaskScheduleRustFfi();
            ArchiveCandidate::useRustFfi($archiveRust);
        }
    }

    public function test_entry_points_route_through_toggle(): void
    {
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(6400));
        foreach (array_keys(self::ENTRIES) as $class) {
            foreach ([false, true] as $uncommon) {
                $items = $this->fixture($class, $random, $uncommon);
                $php = $this->call($class, 'Php', $items);
                foreach ([true, false] as $native) {
                    self::assertSame($php, $class::withW64RustFfi($native, fn (): array => $this->call($class, '', $items)), $class);
                }
                self::assertSame($php, $this->call($class, 'Native', $items), $class);
            }
        }
    }

    public function test_native_helpers_fall_back_to_php_for_unsupported_input(): void
    {
        $this->requireBindings();
        // NUL bytes and keyed or non-sequential batches keep the PHP semantics.
        foreach (array_keys(self::ENTRIES) as $class) {
            $this->assertParity($class, ["25\t2\t\0\t\t\t\t/t\0\t\tvideo", "0\t7\t\0", "1\tcompleted\t\t\t\t\0\t", "1\t\0\t\t\t\t1", "a.\0rar\t1\t1\t1"]);
            $this->assertParity($class, ['k' => "a.rar\t10\t2\t1", 'j' => "4\t9\t120\t"]);
            $this->assertParity($class, [1 => "1\tfailed\t1\t1\t\t\tsocket", 0 => "2\t2\t\t\t\t1\t0\t0\t0\t\t"]);
            $this->assertParity($class, ["\0"]);
        }
    }

    public function test_exports_guard_null_short_overlapping_and_malformed_batches(): void
    {
        $this->requireBindings();
        foreach (self::SYMBOLS as $class => $symbol) {
            $ffi = $this->ffi($class);
            $out = $ffi->new('int64_t[64]');
            $words = 1 + self::TAIL[$class];
            self::assertSame($words, $this->invoke($ffi, $symbol, ['a', 1, 1, $out, 64]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, ['a', 1, 1, null, 64]), $class);
            self::assertSame(-$words, $this->invoke($ffi, $symbol, ['a', 1, 1, $out, $words - 1]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, ["a\0b", 3, 1, $out, 64]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, ['a', 1, 2, $out, 64]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, [null, 1, 1, $out, 64]), $class);
            self::assertSame(self::TAIL[$class], $this->invoke($ffi, $symbol, [null, 0, 0, $out, 64]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, ['', 0, 1 << 21, $out, 64]), $class);
            // An output aliasing the input is refused; an unaligned output is written byte-wise.
            $shared = $ffi->new('char[1024]');
            FFI::memcpy($shared, 'a', 1);
            $base = $ffi->cast('char *', FFI::addr($shared));
            self::assertSame(-1, $this->invoke($ffi, $symbol, [$base, 1, 1, $ffi->cast('int64_t *', $base), 64]), $class);
            self::assertSame($words, $this->invoke($ffi, $symbol, [$base, 1, 1, $ffi->cast('int64_t *', $this->offset($ffi, $shared, 512)), 64]), $class);
            $raw = $ffi->new('char['.(8 * $words + 8).']');
            $unaligned = $this->offset($ffi, $raw, 1);
            self::assertSame($words, $this->invoke($ffi, $symbol, ['a', 1, 1, $ffi->cast('int64_t *', $unaligned), $words]), $class);
            self::assertSame($this->call($class, 'Php', ['a']), array_values((array) unpack('P*', FFI::string($unaligned, 8 * $words))), $class);
        }
    }

    public function test_processing_config_plan_parity(): void
    {
        $this->requireBindings();
        $rows = ["\t0\t500\t20\t30\t\t/usr/bin/timeout\t/usr/bin/ffmpeg\tvideo, AUDIO,jpg,bogus", "x\t2\t3\t1\t0\t90\ttimeout\t\tvideo,mediainfo"];
        $this->assertFields(ProcessingConfiguration::class, $rows, -1, [25 | 2 << 11 | 100 << 17 | 10 << 24 | 0b100011 << 28 | 0b1100 << 34 | 1 << 46 | 1 << 48
            | 0b1101 << 50 | 1 << 58 | 1 << 59, 25 | 2 << 11 | 3 << 17 | 1 << 24 | 1 << 40 | 2 << 46 | 0b10000 << 50], [1, 1, 0, 0, 0, 1, 0, 0, 1, 1, 0, 0, 1, 0, 0, 0, 0, 0,
                0, 1, 1, 0, 1, 1, 0, 0, 1, 0, 1, 1, 1, 0, 0, 0, 1, 1, 51, 50, 2, 30, 120, 1, 1]);
        // The kill string length is strlen(killStringPhp()) for the trimmed path.
        $words = ProcessingConfiguration::w64ProcessingConfigPlanBatchPhp(["\t\t\t\t30\t\t /usr/bin/timeout \t\t"]);
        self::assertSame(\strlen(ProcessingConfiguration::killStringPhp('/usr/bin/timeout', 30)), $words[1 + 36]);
        $this->assertRandomParity(ProcessingConfiguration::class, 6410);
    }

    public function test_console_output_plan_parity(): void
    {
        $this->requireBindings();
        $rows = ["0\t7\t1572864\t", "3\t\t\tpar2 index", "4\t9\t120\t", "9\t1\t1\t"];
        $this->assertFields(ConsoleOutputService::class, $rows, (1 << 22) - 1, [12 << 3 | 2 << 18, 3 | 16 << 3 | 2 << 15, 4 | 31 << 3 | 3 << 15, 7 | 5 << 15],
            [1, 0, 0, 1, 1, 0, 0, 1, 1, 0, 1, 1, 0, 1, 0, 0, 1, 0, 0, 59, 31, 0, 120, 2, 1, 1572864]);
        // Labels other than the release-start size match formatStatusPhp() exactly.
        $kinds = [[1, 3, 0, ''], [2, 4, 0, ''], [3, 0, 0, 'mid'], [4, 9, 120, ''], [5, 9, 3, ''], [6, 11, 0, '']];
        foreach ($kinds as [$kind, $a, $b, $text]) {
            $label = ltrim(ConsoleOutputService::formatStatusPhp($kind, $a, $b, $text), "\n");
            $word = ConsoleOutputService::w64ConsoleOutputPlanBatchPhp(["{$kind}\t{$a}\t{$b}\t{$text}"])[0];
            self::assertSame(crc32($label), $word >> 22 & 0xFFFFFFFF, (string) $kind);
        }
        $this->assertRandomParity(ConsoleOutputService::class, 6420);
    }

    public function test_batch_result_plan_parity(): void
    {
        $this->requireBindings();
        $rows = ["1\tcompleted\t1500\t1\tno\tsample,jpg,x\t", "2\tTIMED-OUT\t400000\t1\t\t\tRead timed out", "2\tpassworded\t\t\tno\t\t", "x\tbogus\t\t\t\t\t"];
        $this->assertFields(AdditionalBatchResult::class, $rows, -1, [1 | 2 << 7 | 3 << 9 | 1 << 16 | 1 << 22 | 1 << 25 | 1 << 33 | 1 << 53,
            6 | 1 << 4 | 4 << 16 | 4 << 22 | 1 << 25 | 400 << 33, 3 | 2 << 7 | 25 << 16, 32 << 16], [1, 1, 0, 1, 0, 0, 1, 0, 0, 0, 0, 0, 0, 3, 1, 0, 0, 0, 0, 0, 2, 0, 2, 0,
                1, 1, 0, 0, 0, 0, 0, 2, 0, 1, 1, 1, 1, 2, 1, 0, 0, 1, 401500, 400000, 2, 2, 2]);
        $this->assertRandomParity(AdditionalBatchResult::class, 6430);
    }

    public function test_work_plan_eval_parity(): void
    {
        $this->requireBindings();
        $rows = ["2\t1\t<bad>\tid@x\t.FLAC\t3\t0\t1\t0\t\tm", "0\t0\t\t\t\t0\t2\t0\t0\t5\tV q", "x\t\t\t\t\t2\t\t\t4\t0\tB"];
        $this->assertFields(AdditionalWorkPlan::class, $rows, -1, [155 | 10 << 8 | 2 << 28 | 4 << 35 | 1 << 42 | 1 << 47 | 2 << 48 | 1 << 50,
            160 | 2 << 8 | 5 << 28 | 8 << 35 | 1 << 42 | 1 << 43, 128 | 2 << 28 | 1 << 35 | 1 << 40 | 1 << 42], [1, 1, 0, 1, 1, 1, 0, 3, 1, 0, 1, 1, 0, 3, 1, 0, 12, 0, 10, 5, 0]);
        $this->assertRandomParity(AdditionalWorkPlan::class, 6440);
    }

    public function test_archive_candidate_plan_parity(): void
    {
        $this->requireBindings();
        $rows = ["Movie.part01.rar\t10\t1000\t0", "movie.part02.rar\t10\t1000\t1", "Movie.r00\t1\t70000000\t1", "x.7z.002\tx\t\t2"];
        $this->assertFields(ArchiveCandidate::class, $rows, (1 << 46) - 1, [1 << 3 | 1 << 4 | 1 << 20 | 1 << 23, 2 << 4 | 1 << 20, 2 << 4 | 3 << 20 | 1 << 24,
            1 | 2 << 4 | 1 << 25 | 3 << 26], [3, 1, 0, 0, 0, 1, 2, 0, 1, 0, 1, 2, 1, 1000, 70002000, 1, 1, 2]);
        $this->assertRandomParity(ArchiveCandidate::class, 6450);
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<string>  $items
     */
    #[DataProvider('edgeCases')]
    public function test_edge_case_parity(string $class, array $items): void
    {
        $this->requireBindings();
        $this->assertParity($class, $items);
        if ($items !== [] && ! str_contains(implode('', $items), "\0")) {
            self::assertSame($this->call($class, 'Php', $items), $this->exported($class, $items), $class);
        }
    }

    /** @return iterable<string, array{key-of<self::ENTRIES>, list<string>}> */
    public static function edgeCases(): iterable
    {
        $long = str_repeat('Ab.', 25000);
        $binary = "\xff\xfe\x01".str_repeat("\xDB\xB2\x85", 30);
        $unicode = ['Ünïcödé.part01.rar', '映画.mkv.001', 'ファイル.7z', "\u{1F3AC}.r00", 'İ.ZIP', "\xC9\xE1.z01", 'naïve.PART02.RAR', 'vidéo,AUDİO'];
        $digits = ['0', '1', '-1', '+1', ' 1 ', "\x0b7\x0b", '1.5', '1e3', '99', '100', '999', '1000', '999999', '1000000', '999999999', '1000000000', '999999999999',
            '1000000000000', '0x10', '٣', '１'];
        $shared = ['', ' ', "\t", str_repeat("\t", 12), "\x0b", '-', '.', ',', '0', '9', '[', "\xC9\xE1", $long, $binary, $long."\t".$long, $long."\t".$binary."\t".$long,
            str_repeat("\t", 5000).'1', ...$unicode];
        foreach (array_keys(self::ENTRIES) as $class) {
            yield $class.' empty' => [$class, []];
            yield $class.' huge, binary and unicode' => [$class, $shared];
        }
        $config = [];
        foreach ($digits as $value) {
            for ($k = 0; $k < 6; $k++) {
                $fields = array_fill(0, 9, '');
                $fields[$k] = $value;
                $config[] = implode("\t", $fields);
            }
        }
        foreach (['', '/usr/bin/timeout', 'timeout', ' /t ', "/a\x01b", "/a\x7fb", '/a"b', "/a\tb", '0', '/', "/\xC9"] as $path) {
            array_push($config, "\t\t\t\t30\t\t{$path}\t{$path}\tvideo", "\t\t\t\t0\t\t{$path}\t\t", "\t\t\t\t86401\t\t{$path}\t{$path}\taudio,thumbnails");
        }
        foreach (['', ',', ',,video,,', 'VIDEO', ' Video ', 'video,video', 'vidéo', 'İnfo', implode(',', self::FEATURES), 'a,b,c,d,e,f,g,h,i,j,k,l,m,n,o,p,q',
            "video\tx", 'par2;debug'] as $features) {
            array_push($config, "\t\t\t\t\t\t\t/ffmpeg\t{$features}", "\t\t\t\t\t\t\t\t{$features}");
        }
        yield 'processing config bounds' => [ProcessingConfiguration::class, $config];
        $console = [];
        foreach (['', '0', '1', '6', '7', '06', '007', '-1', ' 3 ', 'x', '99'] as $kind) {
            foreach (['', '1', '999999999999', '1000000000000', 'x'] as $a) {
                $console[] = "{$kind}\t{$a}\t5\tsrc";
            }
        }
        foreach ([0, 1, 1023, 1024, 1025, 1048575, 1048576, 1572864, 1073741823, 1073741824, 1099511627775, 1099511627776, 999999999999] as $bytes) {
            $console[] = "0\t1\t{$bytes}\t";
        }
        foreach ($digits as $value) {
            array_push($console, "4\t1\t{$value}\t", "1\t{$value}\t\t", "0\t{$value}\t{$value}\t");
        }
        array_push($console, "3\t\t\t", "3\t\t\t\t", "3\t\t\t{$long}", "3\t\t\ta\tb\tc", "3\t\t\t\xC9", "5\t1\t2\textra", "6\t\t\t");
        yield 'console output bounds' => [ConsoleOutputService::class, $console];
        $result = [];
        foreach (['completed', 'COMPLETED', ' completed ', 'complete', 'failed', 'not-found', 'awaiting-late-articles', 'deleted-par2-only', 'timed-out',
            'temporary-workspace-unavailable', 'group-unavailable', 'passworded', 'no-useful-artifacts', 'deleted-after-timeout', 'deleted-broken-nzb', '', 'İ'] as $outcome) {
            foreach (['', '0', '2', '3', '999'] as $attempts) {
                $result[] = "1\t{$outcome}\t100\t{$attempts}\t\t\tconnection reset";
            }
        }
        foreach (['', 'yes', 'YES', ' true ', '1', 'no', 'False', '0', 'maybe', "\xC9"] as $password) {
            array_push($result, "5\tpassworded\t1\t1\t{$password}\t\t", "5\tcompleted\t1\t1\t{$password}\t\t");
        }
        foreach ($digits as $value) {
            array_push($result, "{$value}\tfailed\t1\t1\t\t\t", "1\tfailed\t{$value}\t1\t\t\t", "1\tfailed\t1\t{$value}\t\t\t");
        }
        foreach ([...self::ERRORS, 'TIMEOUT', 'timed  out', 'Error 430', 'CORRUPT data', 'Pass word', "x\tconnection", "\xC9socket"] as $error) {
            $result[] = "9\tfailed\t1\t1\t\t\t{$error}";
        }
        foreach ([implode(',', self::MEDIA), 'SAMPLE, Jpg', ',,', 'x,y,z,w,v,u,t,s,r', 'nfo,nfo', 'jpeg'] as $media) {
            $result[] = "2\tcompleted\t1\t1\t\t{$media}\t";
        }
        foreach ([0, 999, 1000, 9999, 10000, 59999, 60000, 299999, 300000, 999999999] as $elapsed) {
            $result[] = "3\tcompleted\t{$elapsed}\t\t\t\t";
        }
        yield 'batch result bounds' => [AdditionalBatchResult::class, $result];
        $plan = [];
        foreach ($digits as $value) {
            foreach ([0, 1, 5, 6, 7, 8, 9] as $k) {
                $fields = ['1', '1', 'm@x', 'a@x', 'mp3', '1', '1', '1', '1', '2', ''];
                $fields[$k] = $value;
                $plan[] = implode("\t", $fields);
            }
        }
        foreach (['', 'a@b', '<a@b>', 'a b', "a\x7fb", "\xC9@x", 'a>b', ' a@b '] as $id) {
            $plan[] = "1\t1\t{$id}\t{$id}\tflac\t0\t0\t0\t0\t\t";
        }
        foreach (['', 'mp3', 'MP3', '.mp3', '..mp3', ' .Flac ', 'mkv', 'opus', 'İ'] as $ext) {
            $plan[] = "0\t0\t\ta@b\t{$ext}\t0\t0\t0\t0\t\t";
        }
        foreach (['', 'b', 'B', 'bpmva', 'BPMVA', 'b, p', 'xyz', str_repeat('q', 30), "\xC9", 'vv', 'A'] as $flags) {
            $plan[] = "3\t1\tm@x\ta@x\tmp3\t2\t1\t1\t2\t\t{$flags}";
        }
        array_push($plan, "999999\t999999\t\t\t\t999999\t999999\t999999\t0\t100\t", "0\t0\t\t\t\t10\t0\t0\t0\t100\t", "0\t0\t\t\t\t11\t0\t0\t0\t100\t",
            "0\t0\t\t\t\t0\t0\t0\t0\t\t", "\t\t\t\t\t\t\t\t\t\t\t\t");
        yield 'work plan bounds' => [AdditionalWorkPlan::class, $plan];
        $archive = [];
        foreach (['x.rar', 'x.RAR', 'x.part1.rar', 'x.part0001.rar', 'x.part12345.rar', 'x.part.rar', 'x.partx1.rar', '.part01.rar', 'part01.rar', 'x.r0', 'x.r00',
            'x.r000', 'x.r0000', 'x.r1x', 'x.7z', '.7z', 'x.7z.000', 'x.7z.001', '.7z.001', 'x.7z.01', 'x.zip', 'x.zipx', 'x.z00', 'x.z999', 'x.z1', 'x.000', 'x.001',
            'x.0001', 'x.00', 'x.mkv', 'x', 'x.', '.', '..', '.rar', ' X.Part02.Rar ', 'a.b.c.rar', ...$unicode] as $name) {
            $archive[] = "{$name}\t10\t1000\t";
        }
        foreach ($digits as $value) {
            array_push($archive, "a.rar\t{$value}\t1\t", "a.rar\t1\t{$value}\t", "a.rar\t1\t1\t{$value}");
        }
        foreach ([0, 1048575, 1048576, 16777216, 16777217, 4294967296, 4294967297, 999999999999] as $bytes) {
            $archive[] = "s{$bytes}.part01.rar\t1\t{$bytes}\t";
        }
        for ($i = 0; $i < 40; $i++) {
            $archive[] = 'set'.($i % 3).'.part'.sprintf('%02d', $i + 1).".rar\t40\t3000000\t".($i % 7);
        }
        yield 'archive candidate bounds' => [ArchiveCandidate::class, $archive];
    }

    /**
     * Paired randomized benchmark: ROUNDS interleaved rounds visit every class and workload in
     * turn; each round times PAIRS pairs (after WARMUPS) of the PHP and Rust batch entry point on
     * one randomly chosen fixture, alternating which runs first, each side keeping the fastest of
     * REPEATS back-to-back calls. Common batches hold 320 typical rows, Uncommon batches 320-640
     * malformed or adversarial ones. Mean and nearest-rank p99 speedups pool every round's
     * samples. Every pair must agree; raw pairs are written outside the checkout. A class must
     * default to Rust exactly when all four metrics reach 1.5x. Gated behind
     * RUN_RUST_FFI_BENCHMARKS=1.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        if (getenv('RUN_RUST_FFI_BENCHMARKS') !== '1') {
            self::markTestSkipped('Set RUN_RUST_FFI_BENCHMARKS=1 to run the Wave 64.2 paired benchmark.');
        }
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(64));
        $fixtures = $samples = $records = [];
        foreach (array_keys(self::ENTRIES) as $class) {
            foreach (['Common', 'Uncommon'] as $workload) {
                for ($v = 0; $v < 8; $v++) {
                    $fixtures[$class][$workload][] = $this->fixture($class, $random, $workload === 'Uncommon');
                }
            }
        }
        for ($round = 0; $round < self::ROUNDS; $round++) {
            foreach (array_keys(self::ENTRIES) as $class) {
                foreach (['Common', 'Uncommon'] as $workload) {
                    for ($i = -self::WARMUPS; $i < self::PAIRS; $i++) {
                        $variant = $random->getInt(0, 7);
                        $order = ($i & 1) === 0 ? [false, true] : [true, false];
                        $results = $elapsed = [];
                        foreach ($order as $native) {
                            $items = $fixtures[$class][$workload][$variant];
                            [$results[(int) $native], $elapsed[(int) $native]] = $class::withW64RustFfi($native, fn (): array => $this->timed($class, $items));
                        }
                        self::assertSame($results[0], $results[1], $class.' '.$workload);
                        if ($i >= 0) {
                            $samples[$class][$workload][] = [$elapsed[0], $elapsed[1]];
                            $records[] = [$class, $workload, $round, $variant, $order[0], $elapsed[0], $elapsed[1]];
                        }
                    }
                }
            }
        }
        $summary = [];
        foreach ($samples as $class => $workloads) {
            $metrics = [];
            foreach ($workloads as $workload => $pairs) {
                $php = array_column($pairs, 0);
                $rust = array_column($pairs, 1);
                $summary[$class][$workload] = [
                    'mean_speedup' => round(array_sum($php) / array_sum($rust), 3), 'p99_speedup' => round($this->p99($php) / $this->p99($rust), 3),
                    'php_mean_ns' => (int) (array_sum($php) / \count($php)), 'rust_mean_ns' => (int) (array_sum($rust) / \count($rust)),
                    'php_p99_ns' => $this->p99($php), 'rust_p99_ns' => $this->p99($rust), 'samples' => \count($php),
                ];
                $metrics[] = $summary[$class][$workload]['mean_speedup'];
                $metrics[] = $summary[$class][$workload]['p99_speedup'];
            }
            $summary[$class]['qualifies'] = min($metrics) >= 1.5;
        }
        $artifact = sys_get_temp_dir().'/w642-benchmark-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($artifact, json_encode(['php' => PHP_VERSION, 'os' => php_uname(), 'rounds' => self::ROUNDS, 'pairs' => self::PAIRS, 'warmups' => self::WARMUPS,
            'repeats' => self::REPEATS, 'summary' => $summary, 'records' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW64.2 paired benchmark artifact: {$artifact}\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach (array_keys(self::ENTRIES) as $class) {
            self::assertSame($summary[$class]['qualifies'], $class::usesW64RustFfi(), $class.' default must follow the measured 1.5x gate');
        }
    }

    /**
     * Every rule is byte-level ASCII, so an explicit setlocale() changes neither implementation.
     * Declared last: once a script sets a locale, later regexes keep the rebuilt tables.
     */
    public function test_parity_holds_under_locale_tables(): void
    {
        $this->requireBindings();
        $locale = setlocale(LC_CTYPE, '0');
        try {
            foreach (['C.UTF-8', 'en_US.ISO8859-1', 'tr_TR.ISO8859-9'] as $candidate) {
                if (setlocale(LC_CTYPE, $candidate) === false) {
                    continue;
                }
                $cases = [
                    ProcessingConfiguration::class => ["\t\t\t\t5\t\t/\xC9\t/\xC9\tVİDEO,AUDIO,\xC9", "\xC9\t\t\t\t\t\tİ\t\tvideo"],
                    ConsoleOutputService::class => ["3\t\t\t\xC9İ", "\xC9\t1\t1\t"],
                    AdditionalBatchResult::class => ["1\tCOMPLETED\t1\t1\tYES\tSAMPLE,JPG,İ\tTIMEOUT", "2\tİ\t\t\t\xC9\t\tSOCKET \xC9"],
                    AdditionalWorkPlan::class => ["1\t1\t\xC9@x\tİ\t.FLAC\t1\t1\t1\t1\t\tBPMVAİ\xC9"],
                    ArchiveCandidate::class => ["\xC9.PART01.RAR\t1\t1\t1", "İ.R00\t1\t1\t1", "I.7Z.001\t1\t1\t1"],
                ];
                foreach ($cases as $class => $items) {
                    $this->assertParity($class, $items);
                    self::assertSame($this->call($class, 'Php', $items), $this->exported($class, $items), $class);
                }
            }
        } finally {
            setlocale(LC_CTYPE, (string) $locale);
        }
    }

    // ---- helpers -----------------------------------------------------------------------------

    /**
     * Documented per-row fields (`word & $mask`) and summary words (without the digest) from both
     * implementations.
     *
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<string>  $rows
     * @param  list<int>  $fields
     * @param  list<int>  $tail
     */
    private function assertFields(string $class, array $rows, int $mask, array $fields, array $tail): void
    {
        $this->assertParity($class, $rows);
        foreach (['Php', 'Native'] as $suffix) {
            $words = $this->call($class, $suffix, $rows);
            self::assertSame($fields, array_map(static fn (int $word): int => $word & $mask, \array_slice($words, 0, \count($rows))), $class.'::'.$suffix);
            self::assertSame($tail, \array_slice($words, \count($rows), -1), $class.'::'.$suffix);
        }
        self::assertSame($this->call($class, 'Php', $rows), $this->exported($class, $rows), $class);
    }

    /**
     * The fastest of REPEATS back-to-back calls of the class's batch entry point.
     *
     * @param  key-of<self::ENTRIES>  $class
     * @param  array<array-key, string>  $items
     * @return array{list<int>, int}
     */
    private function timed(string $class, array $items): array
    {
        $best = PHP_INT_MAX;
        $result = [];
        for ($r = 0; $r < self::REPEATS; $r++) {
            $start = hrtime(true);
            $result = $this->call($class, '', $items);
            $best = min($best, hrtime(true) - $start);
        }

        return [$result, $best];
    }

    /** @param  list<int>  $values */
    private function p99(array $values): int
    {
        sort($values);

        return $values[max(0, (int) ceil(\count($values) * 0.99) - 1)];
    }

    /** @param  key-of<self::ENTRIES>  $class */
    private function default(string $class): bool
    {
        $value = (new ReflectionClass($class))->getConstant('W64_RUST_FFI_DEFAULT');
        self::assertIsBool($value);

        return $value;
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    private function call(string $class, string $suffix, array $items): array
    {
        /** @var list<int> */
        return [$class, self::ENTRIES[$class].$suffix]($items);
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @param  array<array-key, string>  $items
     */
    private function assertParity(string $class, array $items): void
    {
        $php = $class::withW64RustFfi(false, fn (): array => $this->call($class, '', $items));
        $rust = $class::withW64RustFfi(true, fn (): array => $this->call($class, '', $items));
        self::assertSame($php, $rust, $class);
        self::assertSame($php, $this->call($class, 'Php', $items), $class);
        self::assertSame($php, $this->call($class, 'Native', $items), $class);
        self::assertCount(\count($items) + self::TAIL[$class], $php, $class);
        self::assertSame($this->digest(\array_slice($php, 0, -1)), $php[\count($php) - 1], $class);
    }

    /**
     * PARITY_BATCHES Common and Uncommon batches (at least 320 rows each) must match, and the
     * Rust export must actually produce them (no silent PHP fallback).
     *
     * @param  key-of<self::ENTRIES>  $class
     */
    private function assertRandomParity(string $class, int $seed): void
    {
        $items = 0;
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < self::PARITY_BATCHES; $i++) {
                $batch = $this->fixture($class, new Randomizer(new Mt19937($seed + 2 * $i + (int) $uncommon)), $uncommon);
                $this->assertParity($class, $batch);
                self::assertSame($this->call($class, 'Php', $batch), $this->exported($class, $batch), $class);
                $items += \count($batch);
            }
        }
        self::assertGreaterThanOrEqual(320 * 2 * self::PARITY_BATCHES, $items, $class);
    }

    /**
     * Calls the export directly with the entry point's framing.
     *
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<string>  $items
     * @return list<int>
     */
    private function exported(string $class, array $items): array
    {
        $ffi = $this->ffi($class);
        $count = \count($items);
        $cap = $count + 64;
        $out = $ffi->new('int64_t['.$cap.']');
        $blob = implode("\0", $items);
        $written = $this->invoke($ffi, self::SYMBOLS[$class], [$blob, \strlen($blob), $count, $out, $cap]);
        self::assertIsInt($written);
        self::assertGreaterThan(0, $written, $class);
        /** @var array<int, int> $values */
        $values = unpack('P*', FFI::string($out, $written * 8));

        return array_values($values);
    }

    /** @param  key-of<self::ENTRIES>  $class */
    private function ffi(string $class): FFI
    {
        $ffi = (new ReflectionMethod($class, 'w64RustFfiState'))->invoke(null, 'ffi');
        self::assertInstanceOf(FFI::class, $ffi, $class.' must load the nfo_w64 binding');

        return $ffi;
    }

    /** Each Wave 64.2 export must resolve and accept a one-item batch, so parity runs Rust. */
    private function requireBindings(): void
    {
        self::assertTrue(\extension_loaded('FFI'), 'FFI extension required');
        foreach (self::SYMBOLS as $class => $symbol) {
            $ffi = $this->ffi($class);
            $out = $ffi->new('int64_t[64]');
            self::assertSame(1 + self::TAIL[$class], $this->invoke($ffi, $symbol, ['a', 1, 1, $out, 64]), $class);
        }
    }

    /**
     * Calls an export declared by a class's W64 C declaration.
     *
     * @param  list<mixed>  $args
     */
    private function invoke(FFI $ffi, string $symbol, array $args): mixed
    {
        return $ffi->{$symbol}(...$args);
    }

    /** A `char *` $bytes past the start of $buffer (pointer arithmetic through a `uintptr_t` view). */
    private function offset(FFI $ffi, CData $buffer, int $bytes): CData
    {
        $pointer = $ffi->new('char *[1]');
        $pointer[0] = $ffi->cast('char *', FFI::addr($buffer));
        $address = $ffi->cast('uintptr_t *', FFI::addr($pointer));
        $address[0] += $bytes;
        $result = $pointer[0];
        self::assertInstanceOf(CData::class, $result);

        return $result;
    }

    /** @param  list<int>  $words */
    private function digest(array $words): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$words), true));

        return $digest[1];
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @return list<string>
     */
    private function fixture(string $class, Randomizer $random, bool $uncommon): array
    {
        $items = [];
        for ($i = 0, $n = $uncommon ? $random->getInt(320, 640) : 320; $i < $n; $i++) {
            $items[] = match ($class) {
                ProcessingConfiguration::class => $this->configRow($random, $uncommon),
                ConsoleOutputService::class => $this->consoleRow($random, $uncommon),
                AdditionalBatchResult::class => $this->resultRow($random, $uncommon),
                AdditionalWorkPlan::class => $this->planRow($random, $uncommon),
                ArchiveCandidate::class => $this->archiveRow($random, $uncommon, $i),
            };
        }

        return $items;
    }

    /**
     * @template T
     *
     * @param  list<T>  $values
     * @return T
     */
    private function pick(Randomizer $random, array $values): mixed
    {
        return $values[$random->getInt(0, \count($values) - 1)];
    }

    /** Printable noise, or binary noise without NUL bytes. */
    private function noise(Randomizer $random, int $max, bool $binary = false): string
    {
        $length = $random->getInt(0, $max);
        if ($length === 0) {
            return '';
        }

        return $binary ? str_replace("\0", "\1", $random->getBytes($length))
            : $random->getBytesFromString("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789._-[]()<>@/+=:,\"' \t", $length);
    }

    /** Case-flips, pads or corrupts a field the way hand-edited or truncated data does. */
    private function mangle(Randomizer $random, string $value): string
    {
        return match ($random->getInt(0, 6)) {
            0 => strtoupper($value),
            1 => ucfirst(strtolower($value)),
            2 => $this->pick($random, [' ', "\r\n", "\x0b", '']).$value.$this->pick($random, [' ', "\r\n", "\x0b", '']),
            3 => substr($value, 0, $random->getInt(0, max(0, \strlen($value) - 1))),
            4 => $value.$this->noise($random, 3),
            5 => str_replace(['a', 'e', 'o', '1'], ["\xE1", 'É', '0', $this->pick($random, ['l', ' ', '-', '11'])], $value),
            default => $value,
        };
    }

    /**
     * Replaces one random field of a tab-joined row with $mutate($field), or drops or adds
     * trailing fields.
     *
     * @param  list<string>  $fields
     * @param  callable(string): string  $mutate
     */
    private function mutateFields(Randomizer $random, array $fields, callable $mutate): string
    {
        $k = $random->getInt(0, \count($fields) - 1);
        match ($random->getInt(0, 5)) {
            0 => $fields = \array_slice($fields, 0, $k),
            1 => $fields[] = $this->noise($random, 10),
            default => $fields[$k] = $mutate($fields[$k]),
        };

        return implode("\t", $fields);
    }

    /**
     * A random comma-joined subset of $names.
     *
     * @param  list<string>  $names
     */
    private function subset(Randomizer $random, array $names): string
    {
        $picked = [];
        foreach ($names as $name) {
            if ($random->getInt(0, 2) === 0) {
                $picked[] = $name;
            }
        }

        return implode($this->pick($random, [',', ',', ', ']), $picked);
    }

    private function releaseName(Randomizer $random): string
    {
        $words = [];
        for ($w = $random->getInt(1, 4); $w > 0; $w--) {
            $words[] = ucfirst($this->pick($random, self::WORDS));
        }

        return implode($this->pick($random, ['.', '.', '_', ' ']), $words).'.'.$random->getInt(1990, 2025);
    }

    private function configRow(Randomizer $random, bool $uncommon): string
    {
        $fields = [$this->pick($random, ['', '25', '50', '100', '0']), $this->pick($random, ['', '2', '3', '5']), $this->pick($random, ['', '3', '5', '10']),
            $this->pick($random, ['', '1', '2', '5']), $this->pick($random, ['', '0', '60', '120']), $this->pick($random, ['', '120', '300']),
            $this->pick($random, ['', '/usr/bin/timeout', '/opt/homebrew/bin/gtimeout', 'timeout']), $this->pick($random, ['', '/usr/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg']),
            $this->subset($random, self::FEATURES)];
        if (! $uncommon) {
            return implode("\t", $fields);
        }

        return match ($random->getInt(0, 9)) {
            0 => $this->noise($random, 80, true),
            1 => $this->noise($random, 60),
            2 => '',
            3 => $this->mutateFields($random, $fields, fn (string $field): string => $this->mangle($random, $field)),
            4 => $this->mutateFields($random, $fields, fn (string $field): string => $this->pick($random, ['-1', '9999999999', '1001', '51', 'x', ' 7 ', '1e3', '', '٣'])),
            5 => implode("\t", [...\array_slice($fields, 0, 6), $this->pick($random, ["/a\x01b", '/a"b', ' /t ', 'relative/path', "/\xC9"]),
                $this->pick($random, ["ff\x7fmpeg", '', 'ffmpeg', '/x']), $fields[8]]),
            6 => implode("\t", [...\array_slice($fields, 0, 8), $this->pick($random, ['VIDEO,Audio', 'vidéo', ',,,', 'x,y,z', implode(',', self::FEATURES).',bogus'])]),
            default => $this->mutateFields($random, $fields, fn (string $field): string => $this->noise($random, 12, true)),
        };
    }

    private function consoleRow(Randomizer $random, bool $uncommon): string
    {
        $kind = $random->getInt(0, 6);
        $fields = [(string) $kind, (string) $random->getInt(1, 50_000_000), (string) match ($kind) {
            0 => $random->getInt(0, 9_000_000_000),
            4 => $random->getInt(1, 600),
            5 => $random->getInt(1, 5),
            default => $random->getInt(0, 3),
        }, $kind === 3 ? $this->pick($random, ['par2', 'archive', 'main-video', 'sample', 'mid-slice', 'image']) : ''];
        if (! $uncommon) {
            return implode("\t", $fields);
        }

        return match ($random->getInt(0, 9)) {
            0 => $this->noise($random, 80, true),
            1 => $this->noise($random, 60),
            2 => '',
            3 => $this->mutateFields($random, $fields, fn (string $field): string => $this->mangle($random, $field)),
            4 => $this->mutateFields($random, $fields, fn (string $field): string => $this->pick($random, ['-1', '9999999999999', '7', '99', 'x', ' 3 ', '', '٣'])),
            5 => implode("\t", ['3', '', '', $this->noise($random, 200, true)]),
            6 => implode("\t", ['0', $fields[1], (string) $this->pick($random, [0, 1023, 1024, 1048576, 999999999999, 1099511627776])]),
            default => $this->mutateFields($random, $fields, fn (string $field): string => $this->noise($random, 12, true)),
        };
    }

    private function resultRow(Randomizer $random, bool $uncommon): string
    {
        $fields = [(string) $random->getInt(1, 2_000_000), $this->pick($random, self::OUTCOMES), (string) $random->getInt(0, 600_000), (string) $random->getInt(0, 4),
            $this->pick($random, ['', '', 'no', 'yes']), $this->subset($random, self::MEDIA), $this->pick($random, self::ERRORS)];
        if (! $uncommon) {
            return implode("\t", $fields);
        }

        return match ($random->getInt(0, 9)) {
            0 => $this->noise($random, 80, true),
            1 => $this->noise($random, 60),
            2 => '',
            3 => $this->mutateFields($random, $fields, fn (string $field): string => $this->mangle($random, $field)),
            4 => $this->mutateFields($random, $fields, fn (string $field): string => $this->pick($random, ['-1', '9999999999999', '1000', 'x', ' 3 ', '', '٣'])),
            5 => implode("\t", [...\array_slice($fields, 0, 6), $this->pick($random, ['TIMEOUT', "\xC9 socket", str_repeat('connection ', 40), 'Error 430', 'corrupt'])]),
            6 => implode("\t", [$fields[0], $this->pick($random, ['Completed', 'COMPLETE', 'deleted', '', 'İ']), ...\array_slice($fields, 2)]),
            default => $this->mutateFields($random, $fields, fn (string $field): string => $this->noise($random, 12, true)),
        };
    }

    private function planRow(Randomizer $random, bool $uncommon): string
    {
        $id = static fn (): string => bin2hex($random->getBytes(6)).'@news.example.com';
        $fields = [(string) $random->getInt(0, 3), (string) $random->getInt(0, 2), $this->pick($random, ['', $id()]), $this->pick($random, ['', $id()]),
            $this->pick($random, ['', 'mp3', 'flac', '.FLAC', 'mkv']), (string) $random->getInt(0, 12), (string) $random->getInt(0, 3), (string) $random->getInt(0, 2),
            (string) $random->getInt(0, 4), $this->pick($random, ['', '2', '3', '5']), $this->pick($random, ['', '', 'b', 'm', 'p', 'v', 'bm', 'a'])];
        if (! $uncommon) {
            return implode("\t", $fields);
        }

        return match ($random->getInt(0, 9)) {
            0 => $this->noise($random, 80, true),
            1 => $this->noise($random, 60),
            2 => '',
            3 => $this->mutateFields($random, $fields, fn (string $field): string => $this->mangle($random, $field)),
            4 => $this->mutateFields($random, $fields, fn (string $field): string => $this->pick($random, ['-1', '9999999', '999999', '1000', 'x', ' 3 ', '', '٣'])),
            5 => implode("\t", [$fields[0], $fields[1], $this->pick($random, ['<a@b>', 'a b', "\xC9@x", ' a@b ']), ...\array_slice($fields, 3)]),
            6 => implode("\t", [...\array_slice($fields, 0, 10), $this->pick($random, ['BPMVA', 'xyz', str_repeat('q', 20), "b,\xC9", 'b p'])]),
            default => $this->mutateFields($random, $fields, fn (string $field): string => $this->noise($random, 12, true)),
        };
    }

    private function archiveRow(Randomizer $random, bool $uncommon, int $index): string
    {
        $fields = [$this->releaseName($random).$this->pick($random, self::ARCHIVE_EXTENSIONS), (string) $random->getInt(1, 400),
            $random->getInt(0, 3) === 0 ? '' : (string) ($random->getInt(1, 400) * $random->getInt(300_000, 800_000)),
            (string) ($random->getInt(0, 9) === 0 ? $random->getInt(0, $index) : $index)];
        if (! $uncommon) {
            return implode("\t", $fields);
        }

        return match ($random->getInt(0, 9)) {
            0 => $this->noise($random, 80, true),
            1 => $this->noise($random, 60),
            2 => '',
            3 => $this->mutateFields($random, $fields, fn (string $field): string => $this->mangle($random, $field)),
            4 => $this->mutateFields($random, $fields, fn (string $field): string => $this->pick($random, ['-1', '9999999999999', '1000000', 'x', ' 3 ', '', '٣'])),
            5 => implode("\t", [$this->pick($random, ['Ünïcödé.part01.rar', '映画.mkv.001', '.7z.001', 'x.part12345.rar', 'a.R00', 'b.7Z.002', '.rar', '']),
                ...\array_slice($fields, 1)]),
            6 => implode("\t", ['set.part'.$random->getInt(0, 9999).'.rar', $fields[1], (string) $random->getInt(0, 70_000_000), (string) $random->getInt(0, 5)]),
            default => $this->mutateFields($random, $fields, fn (string $field): string => $this->noise($random, 12, true)),
        };
    }
}
