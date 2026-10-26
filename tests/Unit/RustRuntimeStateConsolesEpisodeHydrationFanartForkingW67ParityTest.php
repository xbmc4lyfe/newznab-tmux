<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Configuration\ProcessingRuntimeStateRepository;
use App\Services\ConsolesProcessor;
use App\Services\EpisodeHydrationService;
use App\Services\FanartTvService;
use App\Services\ForkingService;
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

final class RustRuntimeStateConsolesEpisodeHydrationFanartForkingW67ParityTest extends TestCase
{
    /** Class => batch entry point; each also has `*Php()` and `*Native()` variants. */
    private const ENTRIES = [
        ProcessingRuntimeStateRepository::class => 'w67RuntimeStatePlanBatch',
        ConsolesProcessor::class => 'w67ConsolesProcessorPlanBatch',
        EpisodeHydrationService::class => 'w67EpisodeHydrationPlanBatch',
        FanartTvService::class => 'w67FanartTvPlanBatch',
        ForkingService::class => 'w67ForkingServicePlanBatch',
    ];

    /** Class => Wave 67.2 export. */
    private const SYMBOLS = [
        ProcessingRuntimeStateRepository::class => 'nntmux_w672_runtime_state_plan_batch',
        ConsolesProcessor::class => 'nntmux_w672_consoles_processor_plan_batch',
        EpisodeHydrationService::class => 'nntmux_w672_episode_hydration_plan_batch',
        FanartTvService::class => 'nntmux_w672_fanart_tv_plan_batch',
        ForkingService::class => 'nntmux_w672_forking_service_plan_batch',
    ];

    /** Class => class-distinct C declaration constant. */
    private const CDEFS = [
        ProcessingRuntimeStateRepository::class => 'W67_RUNTIME_STATE_FFI_CDEF',
        ConsolesProcessor::class => 'W67_CONSOLES_PROCESSOR_FFI_CDEF',
        EpisodeHydrationService::class => 'W67_EPISODE_HYDRATION_FFI_CDEF',
        FanartTvService::class => 'W67_FANART_TV_FFI_CDEF',
        ForkingService::class => 'W67_FORKING_SERVICE_FFI_CDEF',
    ];

    /** Class => summary words after the per-row words (the digest included). */
    private const TAIL = [
        ProcessingRuntimeStateRepository::class => 25,
        ConsolesProcessor::class => 26,
        EpisodeHydrationService::class => 19,
        FanartTvService::class => 20,
        ForkingService::class => 14,
    ];

    /** Class => public tail-size constant. */
    private const TAIL_CONSTANTS = [
        ProcessingRuntimeStateRepository::class => 'W67_RUNTIME_STATE_TAIL',
        ConsolesProcessor::class => 'W67_CONSOLES_PROCESSOR_TAIL',
        EpisodeHydrationService::class => 'W67_EPISODE_HYDRATION_TAIL',
        FanartTvService::class => 'W67_FANART_TV_TAIL',
        ForkingService::class => 'W67_FORKING_SERVICE_TAIL',
    ];

    /** Class => static properties declared before Wave 67 (the wave adds none). */
    private const STATIC_PROPERTIES = [
        ProcessingRuntimeStateRepository::class => ['useRustFfi', 'ffi', 'values'],
        ConsolesProcessor::class => ['useRustFfi', 'ffi', 'w414UseRustFfi', 'w414Ffi'],
        EpisodeHydrationService::class => ['useW50RustFfi', 'w50Ffi', 'w50Out', 'w50OutCap', 'w50Reference', 'w434UseRustFfi', 'w434Ffi'],
        FanartTvService::class => ['useRustFfi', 'ffi', 'w434UseRustFfi', 'w434Ffi', 'useW50RustFfi', 'w50Ffi', 'w50Out', 'w50OutCap'],
        ForkingService::class => ['useRustFfi', 'ffi', 'useW50RustFfi', 'w50Ffi', 'w50Out', 'w50OutCap'],
    ];

    /** Class => earlier-wave routing probes. */
    private const EARLIER_TOGGLES = [
        ProcessingRuntimeStateRepository::class => ['usesRustFfi', 'usesW57RustFfi'],
        ConsolesProcessor::class => ['usesRustFfi', 'usesW414RustFfi', 'usesW53RustFfi'],
        EpisodeHydrationService::class => ['usesW50RustFfi', 'usesW434RustFfi'],
        FanartTvService::class => ['usesRustFfi', 'usesW434RustFfi', 'usesW50RustFfi'],
        ForkingService::class => ['usesRustFfi', 'usesW50RustFfi'],
    ];

    /**
     * Common mean/p99 then Uncommon mean/p99 speedups (PHP ns / Rust ns), each the minimum of
     * three runs of test_paired_randomized_common_and_uncommon_benchmarks (seed 67, 40
     * interleaved rounds of 10 pairs per class and workload after 2 warmups, 400 pooled samples
     * per metric, each side the fastest of 3 back-to-back calls, batches of 320 Common or
     * 320-640 Uncommon rows, release Rust, PHP 8.5.12 CLI, Apple Silicon macOS on a shared host
     * at load average 8-10). Timings include dispatch, implode, the FFI call and unpack('P*'). A
     * class defaults to Rust only when all four reach 1.5x.
     */
    private const QUALIFICATION = [
        ProcessingRuntimeStateRepository::class => [12.616, 10.147, 11.494, 8.919],
        ConsolesProcessor::class => [8.475, 6.917, 8.701, 8.247],
        EpisodeHydrationService::class => [12.866, 9.769, 13.803, 10.080],
        FanartTvService::class => [5.084, 5.232, 5.667, 5.263],
        ForkingService::class => [12.751, 10.878, 10.965, 8.701],
    ];

    /** Interleaved benchmark rounds; each times PAIRS pairs per class and workload. */
    private const ROUNDS = 40;

    private const PAIRS = 10;

    private const WARMUPS = 2;

    /** Back-to-back calls per side of a pair; the fastest is kept (drops preemption spikes). */
    private const REPEATS = 3;

    /** Batches per class and workload in the randomized parity sweep. */
    private const PARITY_BATCHES = 6;

    private const STATES = ['running', 'paused', 'draining', 'drained', 'stopped'];

    private const GAME_WORDS = ['super', 'mario', 'odyssey', 'halo', 'infinite', 'zelda', 'breath', 'wild', 'gran', 'turismo', 'final', 'fantasy', 'god', 'war', 'forza', 'kart'];

    private const CONSOLE_TOKENS = ['NSW', 'SWITCH', 'PS4', 'PS5', 'PS3', 'PS2', 'PSP', 'VITA', 'XBOX', 'X360', 'XBOXONE', 'WII', 'WIIU', '3DS', 'NDS', 'GBA', 'NGC', 'DREAMCAST'];

    private const REGION_TOKENS = ['USA', 'EUR', 'PAL', 'NTSC', 'JPN', 'JAPAN', 'ASIA', 'REGIONFREE', 'MULTi5', 'Update', 'DLC'];

    private const FANART_KINDS = ['movieposter', 'movieposter', 'moviebackground', 'moviebanner', 'tvposter', 'showbackground', 'tvbanner', 'seasonposter', 'hdmovielogo',
        'moviedisc', 'clearart', 'tvthumb'];

    protected function tearDown(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $class::resetW67RustFfi();
        }
        parent::tearDown();
    }

    public function test_wave_methods_and_constants_exist(): void
    {
        foreach (self::ENTRIES as $class => $entry) {
            $reflection = new ReflectionClass($class);
            foreach (['enableW67RustFfi', 'disableW67RustFfi', 'resetW67RustFfi', 'usesW67RustFfi', 'withW67RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                self::assertTrue($reflection->hasMethod($method), $class.'::'.$method);
                self::assertTrue($reflection->getMethod($method)->isPublic() && $reflection->getMethod($method)->isStatic(), $class.'::'.$method);
                self::assertSame($class, $reflection->getMethod($method)->getDeclaringClass()->getName(), $class.'::'.$method);
            }
            $state = $reflection->getMethod('w67RustFfiState');
            self::assertTrue($state->isPrivate() && $state->isStatic(), $class);
            self::assertSame(2, $state->getNumberOfParameters(), $class);
            self::assertSame(['enabled', 'ffi', 'out', 'capacity'], array_keys($state->getStaticVariables()), $class.' keeps Wave 67 state in function-local statics');
            $default = $reflection->getReflectionConstant('W67_RUST_FFI_DEFAULT');
            self::assertInstanceOf(ReflectionClassConstant::class, $default, $class);
            self::assertIsBool($default->getValue(), $class);
            self::assertTrue($default->isPublic(), $class);
            self::assertSame($class, $reflection->getReflectionConstant(self::CDEFS[$class])?->getDeclaringClass()->getName(), $class);
            self::assertStringContainsString(self::SYMBOLS[$class], (string) $reflection->getConstant(self::CDEFS[$class]), $class);
            self::assertSame(self::TAIL[$class], $reflection->getConstant(self::TAIL_CONSTANTS[$class]), $class);
            self::assertSame(self::STATIC_PROPERTIES[$class], array_keys($reflection->getStaticProperties()), $class.' adds no static property');
            foreach ($reflection->getProperties() as $property) {
                self::assertStringNotContainsString('w67', strtolower($property->getName()), $class.' adds no Wave 67 property');
            }
        }
        self::assertTrue((new ReflectionClass(ProcessingRuntimeStateRepository::class))->isFinal());
        self::assertCount(5, array_unique(self::CDEFS));
    }

    public function test_existing_public_api_is_preserved(): void
    {
        $expected = [
            ProcessingRuntimeStateRepository::class => ['setUseRustFfi', 'usesRustFfi', 'setFfiForTesting', 'isTmuxRunning', 'setTmuxRunning', 'stopRequested', 'requestStop',
                'monitorPaths', 'projectMonitorPaths', 'projectMonitorPathsPhp', 'projectMonitorPathsNative', 'defaultStateAttributes', 'defaultStateAttributesPhp',
                'defaultStateAttributesNative', 'updateMonitorPaths', 'markBinaryRun', 'state', 'usesW57RustFfi', 'withW57RustFfi', 'runtimeStateBatch',
                'runtimeStateBatchPhp', 'runtimeStateBatchNative'],
            ConsolesProcessor::class => ['__construct', 'usesRustFfi', 'setUseRustFfi', 'process', 'dispatchPlan', 'dispatchPlanNative', 'dispatchPlanPhp', 'useW414RustFfi',
                'usesW414RustFfi', 'resetW414RustFfi', 'summarizeConsoleReleaseBatch', 'summarizeConsoleReleaseBatchNative', 'summarizeConsoleReleaseBatchPhp',
                'usesW53RustFfi', 'withW53RustFfi', 'w53ConsoleTitleFlagBatch', 'w53ConsoleTitleFlagBatchNative', 'w53ConsoleTitleFlagBatchPhp'],
            EpisodeHydrationService::class => ['__construct', 'hydrateEpisodeMetadata', 'hydrateEpisodeMetadataNative', 'hydrateEpisodeMetadataPhp', 'parseSearchnameNative',
                'parseSearchnamePhp', 'usesW50RustFfi', 'withW50RustFfi', 'w50SearchnameHydrateBatch', 'w50SearchnameHydrateBatchNative', 'w50SearchnameHydrateBatchPhp',
                'useW434RustFfi', 'usesW434RustFfi', 'resetW434RustFfi', 'summarizeEpisodeSearchnameBatch', 'summarizeEpisodeSearchnameBatchNative',
                'summarizeEpisodeSearchnameBatchPhp'],
            FanartTvService::class => ['setUseRustFfi', 'usesRustFfi', '__construct', 'isConfigured', 'getMovieFanArt', 'getTvFanArt', 'getMusicFanArt', 'getMovieProperties',
                'parseMovieProperties', 'parseMoviePropertiesNative', 'parseMoviePropertiesPhp', 'getTvProperties', 'getBestTvPoster', 'getBestMoviePoster',
                'selectBestPosterUrl', 'selectBestPosterUrlNative', 'selectBestPosterUrlPhp', 'normalizeImdbId', 'normalizeImdbIdNative', 'normalizeImdbIdPhp',
                'clearMovieCache', 'clearTvCache', 'useW434RustFfi', 'usesW434RustFfi', 'resetW434RustFfi', 'summarizeFanartImageBatch', 'summarizeFanartImageBatchNative',
                'summarizeFanartImageBatchPhp', 'usesW50RustFfi', 'withW50RustFfi', 'rankImageBatch', 'rankImageBatchNative', 'rankImageBatchPhp'],
            ForkingService::class => ['__construct', 'backfill', 'safeBackfill', 'binaries', 'safeBinaries', 'releases', 'updatePerGroup', 'fixRelNames', 'processAdditional',
                'processNfo', 'processMovies', 'processTv', 'processAnime', 'processBooks', 'processMusic', 'processConsoles', 'processGames', 'processAmazon',
                'processWorkType', 'enableRustFfi', 'disableRustFfi', 'resetRustFfi', 'usesRustFfi', 'setFfiForTesting', 'maxRetriesFor', 'maxRetriesForPhp',
                'maxRetriesForNative', 'workTypeIndex', 'workTypeIndexPhp', 'workTypeIndexNative', 'timingHeader', 'timingHeaderPhp', 'timingHeaderNative',
                'usesW50RustFfi', 'withW50RustFfi', 'parseTimingHeaderBatch', 'parseTimingHeaderBatchNative', 'parseTimingHeaderBatchPhp'],
        ];
        foreach ($expected as $class => $methods) {
            $reflection = new ReflectionClass($class);
            foreach ($methods as $method) {
                self::assertTrue($reflection->hasMethod($method) && $reflection->getMethod($method)->isPublic(), $class.'::'.$method);
            }
        }
        self::assertSame(['running', 'paused', 'draining', 'drained', 'stopped'], ProcessingRuntimeStateRepository::W57_STATES);
        self::assertCount(18, ForkingService::WORK_TYPES);
        // The earlier-wave helpers answer the same whichever way the Wave 67 toggle points.
        $earlier = static fn (): array => [
            ProcessingRuntimeStateRepository::runtimeStateBatchPhp(['state=running;next=paused;pending=3', 'tmux_running=maybe']),
            ProcessingRuntimeStateRepository::projectMonitorPathsPhp('a', null, 'b'),
            ConsolesProcessor::dispatchPlanPhp(1, true), ConsolesProcessor::w53ConsoleTitleFlagBatchPhp(['Game.PS4-GRP', 'x']),
            EpisodeHydrationService::summarizeEpisodeSearchnameBatchPhp(['Show.S01E02', 'Show.2024.01.15']),
            FanartTvService::summarizeFanartImageBatchPhp(['https://assets.fanart.tv/a.jpg', '']),
            ForkingService::maxRetriesForPhp(3), ForkingService::workTypeIndexPhp('releases'), ForkingService::timingHeaderPhp('x', 5, 'Mon, 1 Jan 2024'),
        ];
        $baseline = $earlier();
        foreach (array_keys(self::ENTRIES) as $class) {
            foreach ([true, false] as $enabled) {
                self::assertEquals($baseline, $class::withW67RustFfi($enabled, $earlier), $class);
            }
        }
    }

    public function test_defaults_match_recorded_qualification(): void
    {
        foreach (self::QUALIFICATION as $class => $speedups) {
            self::assertCount(4, $speedups, $class);
            self::assertSame(min($speedups) >= 1.5, $this->default($class), $class);
            self::assertSame($this->default($class), $class::usesW67RustFfi(), $class);
        }
    }

    public function test_enable_disable_reset_and_with_toggle_scope_routing(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $default = $this->default($class);
            $class::enableW67RustFfi();
            self::assertTrue($class::usesW67RustFfi(), $class);
            $class::disableW67RustFfi();
            self::assertFalse($class::usesW67RustFfi(), $class);
            self::assertTrue($class::withW67RustFfi(true, static fn (): bool => $class::usesW67RustFfi()), $class);
            self::assertFalse($class::usesW67RustFfi(), $class.' with restores an explicit override');
            $class::resetW67RustFfi();
            self::assertSame($default, $class::usesW67RustFfi(), $class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW67RustFfi($enabled, static function () use ($class, $enabled): array {
                    $inner = $class::withW67RustFfi(! $enabled, static fn (): bool => $class::usesW67RustFfi());

                    return [$class::usesW67RustFfi(), $inner];
                });
                self::assertSame([$enabled, ! $enabled], $seen, $class);
                self::assertSame($default, $class::usesW67RustFfi(), $class);
            }
            $thrown = null;
            try {
                $class::withW67RustFfi(! $default, static fn () => throw new RuntimeException('boom'));
            } catch (RuntimeException $e) {
                $thrown = $e->getMessage();
            }
            self::assertSame('boom', $thrown, 'The callback exception must propagate.');
            self::assertSame($default, $class::usesW67RustFfi(), $class);
        }
    }

    public function test_toggles_stay_independent_of_each_other_and_earlier_switches(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $seen = $class::withW67RustFfi(! $this->default($class), static fn (): array => array_map(
                static fn (string $other): bool => $other::usesW67RustFfi(),
                array_keys(self::ENTRIES),
            ));
            foreach (array_keys(self::ENTRIES) as $i => $other) {
                self::assertSame($other === $class ? ! $this->default($other) : $this->default($other), $seen[$i], $class.' vs '.$other);
            }
        }
        foreach (self::EARLIER_TOGGLES as $class => $methods) {
            $before = array_map(static fn (string $method): bool => $class::$method(), $methods);
            foreach ([true, false] as $enabled) {
                $inside = $class::withW67RustFfi($enabled, static fn (): array => array_map(static fn (string $method): bool => $class::$method(), $methods));
                self::assertSame($before, $inside, $class);
            }
            $class::resetW67RustFfi();
            self::assertSame($before, array_map(static fn (string $method): bool => $class::$method(), $methods), $class.' reset leaves earlier toggles alone');
        }
        try {
            foreach ([true, false] as $enabled) {
                ProcessingRuntimeStateRepository::setUseRustFfi($enabled);
                ConsolesProcessor::useW414RustFfi($enabled);
                EpisodeHydrationService::useW434RustFfi($enabled);
                FanartTvService::useW434RustFfi($enabled);
                $enabled ? ForkingService::enableRustFfi() : ForkingService::disableRustFfi();
                foreach (array_keys(self::ENTRIES) as $class) {
                    self::assertSame($this->default($class), $class::usesW67RustFfi(), $class);
                }
                self::assertSame($this->default(ProcessingRuntimeStateRepository::class), ProcessingRuntimeStateRepository::withW57RustFfi($enabled,
                    static fn (): bool => ProcessingRuntimeStateRepository::usesW67RustFfi()));
                self::assertSame($this->default(ConsolesProcessor::class), ConsolesProcessor::withW53RustFfi($enabled, static fn (): bool => ConsolesProcessor::usesW67RustFfi()));
                foreach ([EpisodeHydrationService::class, FanartTvService::class, ForkingService::class] as $class) {
                    self::assertSame($this->default($class), $class::withW50RustFfi($enabled, static fn (): bool => $class::usesW67RustFfi()), $class);
                }
            }
        } finally {
            ProcessingRuntimeStateRepository::setUseRustFfi(null);
            ConsolesProcessor::resetW414RustFfi();
            EpisodeHydrationService::resetW434RustFfi();
            FanartTvService::resetW434RustFfi();
            ForkingService::resetRustFfi();
        }
    }

    public function test_entry_points_route_through_toggle(): void
    {
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(6700));
        foreach (array_keys(self::ENTRIES) as $class) {
            foreach ([false, true] as $uncommon) {
                $items = $this->fixture($class, $random, $uncommon);
                $php = $this->call($class, 'Php', $items);
                foreach ([true, false] as $native) {
                    self::assertSame($php, $class::withW67RustFfi($native, fn (): array => $this->call($class, '', $items)), $class);
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
            $this->assertParity($class, ["run\0ning\t\t1\t0\t0\t0\t", "Game.PS\x004\t1050\t\t0\t1\t", "10\t1\t2\t2020-01-0\x005\t", "tt1\tmovieposter\ten\t1\thttps://a/\0b.jpg\t\t",
                "binaries\t1\0\t\t\t\t", "running\tpaused\t1\t0\t0\t0\t\0"]);
            $this->assertParity($class, ['k' => "running\tpaused\t1\t0\t3\t2\trunning", 'j' => "Halo.Infinite\t1040\t\t0\t2\tyes"]);
            $this->assertParity($class, [1 => "10\t1\t2\t2020-01-05\t", 0 => "binaries\t7\t\t\t\t500"]);
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

    public function test_runtime_state_plan_parity(): void
    {
        $this->requireBindings();
        $rows = ["running\tpaused\t1\t0\t3\t2\trunning", "running\t\t1\tyes\t0\t0\t", "draining\t\t0\t0\t5\t0\tdraining", "stopped\trunning\tno\t\t\t\tdrained",
            "paused\tdrained\t0\t0\t1\t1\t", "bogus\t\t1\t0\t0\t0\t"];
        $this->assertFields(ProcessingRuntimeStateRepository::class, $rows, -1, [1 | 1 << 2 | 2 << 5 | 2 << 8 | 2 << 11 | 1 << 17 | 1 << 20 | 5 << 22 | 3 << 42,
            1 << 2 | 4 << 8 | 5 << 11 | 1 << 14 | 1 << 15 | 1 << 16 | 1 << 18 | 1 << 20 | 1 << 21, 3 << 2 | 3 << 8 | 3 << 11 | 1 << 16 | 5 << 22 | 5 << 42,
            1 | 5 << 2 | 1 << 5 | 1 << 8 | 1 << 11 | 1 << 16 | 1 << 17, 2 | 2 << 2 | 4 << 5 | 2 << 8 | 2 << 11 | 1 << 18 | 2 << 22 | 1 << 42, 1 << 19 | 1 << 20],
            [3, 2, 1, 0, 1, 1, 2, 1, 0, 1, 1, 1, 2, 1, 1, 0, 1, 3, 2, 1, 1, 9, 3, 5]);
        // Every allowed W57_TRANSITIONS pair is allowed; every other distinct pair is rejected.
        foreach (ProcessingRuntimeStateRepository::W57_STATES as $from) {
            foreach (ProcessingRuntimeStateRepository::W57_STATES as $to) {
                $word = ProcessingRuntimeStateRepository::w67RuntimeStatePlanBatchPhp(["{$from}\t{$to}\t\t\t1\t\t"])[0];
                $expected = $from === $to ? 3 : (\in_array($to, ProcessingRuntimeStateRepository::W57_TRANSITIONS[$from], true) ? 1 : 2);
                self::assertSame($expected, $word & 3, "{$from} -> {$to}");
            }
        }
        $this->assertRandomParity(ProcessingRuntimeStateRepository::class, 6710);
    }

    public function test_consoles_processor_plan_parity(): void
    {
        $this->requireBindings();
        $rows = ["Super.Mario.Odyssey.NSW-BigBlueBox\t1050\t\t0\t1\t", "super mario odyssey SWITCH EUR\t1050\t0\t1\t1\t", "Some.Game.PAL.PS2-GRP\t1080\t\t2\t1\t",
            "Halo.Infinite\t1040\t\t0\t2\tyes", "Halo\t1040\t\t0\t1\t", "Movie.2024\t2040\t\t0\t1\t", "x\t1050\t12\t0\t1\t", "x\t1050\t\t0\t0\t", "Halo.Infinite\t1040\t\t0\t2\tno",
            "x\t1050\t\t3\t1\t", "\t1050\t\t0\t1\t"];
        $this->assertFields(ConsolesProcessor::class, $rows, (1 << 44) - 1, [6 | 5 << 4 | 3 << 12 | 1 << 16 | 1 << 19, 9 | 5 << 4 | 2 << 9 | 3 << 12 | 1 << 23,
            6 | 9 << 4 | 2 << 9 | 2 << 12 | 3 << 16 | 12 << 19 | 2 << 24, 7 | 2 << 12 | 1 << 16 | 1 << 19 | 3 << 24, 8 | 1 << 12 | 3 << 16, 1 | 2 << 12, 2 | 1 << 12, 3 | 1 << 12,
            4 | 2 << 12, 5 | 1 << 12, 0], [1, 1, 1, 1, 1, 1, 2, 1, 1, 1, 8, 2, 1, 0, 0, 9, 0, 2, 0, 0, 0, 3, 1, 6, 3]);
        $words = ConsolesProcessor::w67ConsolesProcessorPlanBatchPhp($rows);
        self::assertSame(crc32("\x05:super mario odyssey") & 0x7FFFF, $words[0] >> 44);
        self::assertSame($words[0] >> 44, $words[1] >> 44);
        $this->assertRandomParity(ConsolesProcessor::class, 6720);
    }

    public function test_episode_hydration_plan_parity(): void
    {
        $this->requireBindings();
        $rows = ["10\t1\t2\t2020-01-05\t", "10\t\t\t2024-01-15 00:00:00\t7", "10\t01\t02\t\t", "11\t\t\t\t", "12\t100\t5\t\t", "0\t1\t1\t\t", "12\t1\t1\t2023-02-29\t",
            "13\t\t3\t0000-00-00\t"];
        $this->assertFields(EpisodeHydrationService::class, $rows, (1 << 52) - 1, [2 | 1 << 3 | 1 << 5 | 1 << 8 | 2 << 20,
            3 | 2 << 3 | 1 << 5 | 1 << 7 | 2024 << 8 | 115 << 20 | 1 << 32, 4 | 1 << 3 | 1 << 8 | 2 << 20, 1, 5 | 1 << 3 | 100 << 8 | 5 << 20, 1 << 3 | 1 << 8 | 1 << 20,
            1 << 3 | 2 << 5 | 1 << 8 | 1 << 20, 1 | 3 << 5 | 3 << 20], [2, 2, 1, 1, 1, 1, 2, 5, 1, 4, 2, 1, 1, 4, 2, 1, 18266, 19737]);
        self::assertSame(crc32('10:1:2') & 0x7FF, EpisodeHydrationService::w67EpisodeHydrationPlanBatchPhp($rows)[0] >> 52);
        // Airdates agree with gmmktime() and hydrateFromFirstAired()'s Y / md split.
        foreach (['1900-01-01', '1969-12-31', '2000-02-29', '2024-12-31 23:59:59', '2099-12-31T00:00:00'] as $date) {
            $words = EpisodeHydrationService::w67EpisodeHydrationPlanBatchPhp(["1\t\t\t{$date}\t"]);
            $ts = (int) gmmktime(0, 0, 0, (int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4));
            self::assertSame(intdiv($ts, 86400) - ($ts < 0 && $ts % 86400 !== 0 ? 1 : 0), $words[1 + 16], $date);
            self::assertSame([(int) gmdate('Y', $ts), (int) gmdate('md', $ts)], [$words[0] >> 8 & 0xFFF, $words[0] >> 20 & 0xFFF], $date);
            $this->assertParity(EpisodeHydrationService::class, ["1\t\t\t{$date}\t"]);
        }
        $this->assertRandomParity(EpisodeHydrationService::class, 6730);
    }

    public function test_fanart_tv_plan_parity(): void
    {
        $this->requireBindings();
        $rows = ["tt1\tmovieposter\ten\t5\thttps://assets.fanart.tv/fanart/a.jpg\t1000\t1426", "TT1\tmovieposter\tde\t50\thttps://assets.fanart.tv/fanart/b.jpg\t1000\t1426",
            "tt1\tmovieposter\t00\t9\thttp://x.org/c.png\t\t", "tt1\tmoviebackground\ten\t1\thttps://assets.fanart.tv/preview/d.jpg\t1920\t1080",
            "tt1\thdmovielogo\ten\t1\thttps://assets.fanart.tv/e.png\t\t", "tt2\ttvbanner\t\tx\thttps://a/b\t\t", "\tmovieposter\ten\t1\thttps://a/b\t\t"];
        $this->assertFields(FanartTvService::class, $rows, (1 << 52) - 1, [3 | 1 << 2 | 3 << 4 | 1 << 6 | 1 << 7 | 1 << 9 | 1 << 11 | 5 << 12,
            2 | 1 << 2 | 1 << 4 | 1 << 6 | 1 << 7 | 1 << 9 | 1 << 11 | 50 << 12, 2 | 1 << 2 | 2 << 4 | 2 << 9 | 9 << 12,
            3 | 2 << 2 | 3 << 4 | 1 << 6 | 1 << 7 | 1 << 8 | 1 << 9 | 1 << 11 | 1 << 12 | 1 << 32, 1 | 3 << 4 | 1 << 6 | 1 << 7 | 2 << 9 | 1 << 12, 3 << 2 | 2 << 4 | 1 << 6,
            1 << 2 | 3 << 4 | 1 << 6 | 1 << 12], [2, 1, 2, 2, 1, 4, 1, 1, 0, 1, 2, 4, 1, 2, 65, 50, 4, 1, 3]);
        self::assertSame(crc32('https://assets.fanart.tv/fanart/a.jpg') & 0x7FF, FanartTvService::w67FanartTvPlanBatchPhp($rows)[0] >> 52);
        // Equal scores keep the first row; more likes win within one language priority.
        $words = FanartTvService::w67FanartTvPlanBatchPhp(["m\tposter\ten\t3\thttps://a/1.jpg\t\t", "m\tposter\ten\t3\thttps://a/2.jpg\t\t", "m\tposter\tEN\t4\thttps://a/3.jpg\t\t"]);
        self::assertSame([2, 2, 3], [$words[0] & 3, $words[1] & 3, $words[2] & 3]);
        $this->assertRandomParity(FanartTvService::class, 6740);
    }

    public function test_forking_service_plan_parity(): void
    {
        $this->requireBindings();
        $rows = ["postProcess_add\t100\t8\t4\t\t", "releases\t10\t4\t\t\t", "binaries\t0\t2\t\t\t", "backfill\t1000\t2\t\t10\t100", "postProcess_tv\t1000\t2\t4\t1\t100",
            "unknown\t1\t1\t\t\t", "binaries\t7\t\t\t\t500"];
        $this->assertFields(ForkingService::class, $rows, -1, [2 | 6 << 3 | 4 << 8 | 1 << 18 | 25 << 20, 2 | 5 << 3 | 1 << 8 | 1 << 18 | 1 << 19 | 10 << 20, 1 | 2 << 3 | 1 << 18,
            3 | 1 << 3 | 10 << 8 | 1 << 18 | 100 << 20 | 10 << 51, 4 | 15 << 3 | 4 << 8 | 1 << 18 | 250 << 20 | 1 << 50 | 25 << 51, 0, 2 | 2 << 3 | 7 << 8 | 1 << 20 | 1 << 51],
            [1, 1, 3, 1, 1, 26, 2117, 10, 5, 1, 35500, 25000, 5]);
        // Type positions follow WORK_TYPES and workTypeIndex().
        foreach (ForkingService::WORK_TYPES as $type) {
            self::assertSame((int) ForkingService::workTypeIndexPhp($type) + 1, ForkingService::w67ForkingServicePlanBatchPhp(["{$type}\t1\t\t\t\t"])[0] >> 3 & 31, $type);
        }
        // Remainders go to the first workers: 10 items over 4 workers is 2 each plus 2 extra.
        self::assertSame([2, 2], [ForkingService::w67ForkingServicePlanBatchPhp(["binaries\t10\t4\t\t\t"])[0] >> 20 & 0xFFFFF,
            ForkingService::w67ForkingServicePlanBatchPhp(["binaries\t10\t4\t\t\t"])[0] >> 40 & 1023]);
        $this->assertRandomParity(ForkingService::class, 6750);
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
        $unicode = ['Ünïcödé.PS4-GRP', '映画.Switch', 'ゲーム.NSW', "\u{1F3AE}.XBOX", 'İ.PAL', "\xC9\xE1.3ds", 'naïve.WiiU', 'vidéo/cövér.JPG'];
        $digits = ['0', '1', '-1', '+1', ' 1 ', "\x0b7\x0b", '1.5', '1e3', '9', '10', '99', '100', '999', '1000', '9999', '10000', '99999', '100000', '999999', '1000000',
            '9999999', '10000000', '999999999', '1000000000', '0x10', '٣', '１'];
        $shared = ['', ' ', "\t", str_repeat("\t", 12), "\x0b", '-', '.', ',', ':', '<', '>', "\xC9\xE1", $long, $binary, $long."\t".$long, $long."\t".$binary."\t".$long,
            str_repeat("\t", 5000).'1', ...$unicode];
        foreach (array_keys(self::ENTRIES) as $class) {
            yield $class.' empty' => [$class, []];
            yield $class.' huge, binary and unicode' => [$class, $shared];
        }
        $runtime = [];
        foreach (['', ...self::STATES, 'Running', ' DRAINING ', 'halted', 'run', "\xC9", 'İ', 'stopped '] as $state) {
            foreach (['', 'running', 'paused', 'draining', 'drained', 'stopped', 'bogus'] as $requested) {
                $runtime[] = "{$state}\t{$requested}\t1\t0\t1\t0\tpaused";
            }
        }
        foreach (['', '0', '1', 'true', 'TRUE', ' on ', 'Off', 'yes', 'No', 'maybe', 'y', "\xC9"] as $flag) {
            array_push($runtime, "running\t\t{$flag}\t0\t0\t0\t", "paused\t\t1\t{$flag}\t0\t0\t", "draining\t\t0\t{$flag}\t0\t0\t");
        }
        foreach ($digits as $value) {
            array_push($runtime, "draining\t\t0\t0\t{$value}\t\t", "running\t\t1\t1\t\t{$value}\t");
        }
        yield 'runtime state bounds' => [ProcessingRuntimeStateRepository::class, $runtime];
        $consoles = [];
        foreach (['Super.Mario.NSW', 'super mario nsw', 'SUPER_MARIO-SWITCH', 'Halo.Infinite', 'Halo', 'Game.PAL.PS2', 'Game.PS2.PAL', 'Game.XBOX360.USA', 'Game.X360',
            'Game.Xbox.One', 'Game.XBOXONE', 'Game.PS4.PS5', 'A.B.C.D.E.F.G.H.I.J.K.L.M.N.O.P.Q.R.NDS', 'PS4', 'USA', 'PS4.Game', 'Game.ps4', 'Game.Ps4', 'Game.PS4x',
            str_repeat('A', 17).'.PS4', 'REGIONFREE.Game.NGC', 'Gamecube.Game', 'Dreamcast.Sonic', ...$unicode] as $name) {
            foreach (['0', '1', '2'] as $mode) {
                $consoles[] = "{$name}\t1050\t\t0\t{$mode}\tyes";
            }
            $consoles[] = "{$name}\t1050\t\t0\t2\t";
        }
        foreach ($digits as $value) {
            array_push($consoles, "Game.PS4\t{$value}\t\t0\t1\t", "Game.PS4\t1050\t{$value}\t0\t1\t", "Game.PS4\t1050\t\t{$value}\t1\t", "Game.PS4\t1050\t\t0\t{$value}\t");
        }
        foreach (['999', '1000', '1999', '2000', '', ' 1040 '] as $category) {
            $consoles[] = "Game.Name.PS4\t{$category}\t\t1\t1\t";
        }
        foreach (['', 'yes', 'YES', ' true ', '1', 'no', 'False', '0', 'on', 'maybe', "\xC9"] as $flag) {
            $consoles[] = "Title.Words.Here\t1050\t\t2\t2\t{$flag}";
        }
        yield 'consoles processor bounds' => [ConsolesProcessor::class, $consoles];
        $episodes = [];
        foreach (['', '2024-01-15', '2024-1-15', '2024-01-15 10:20:30', '2024-01-15T10:20:30', '2024-01-15 10:20', '2024-01-15  10:20:30', '2024-01-15 24:00:00',
            '2024-01-15 23:60:00', '2024-01-15 23:59:60', '2024-02-29', '2023-02-29', '1900-02-29', '2000-02-29', '2100-01-01', '1899-12-31', '1900-01-01', '2099-12-31',
            '0000-00-00', '0000-00-00 00:00:00', '0000-00-00 99:99:99', '0000-01-00', '2024-00-10', '2024-13-01', '2024-04-31', '2024/01/15', '20240115', ' 2024-01-15 ',
            "\x0b2024-01-15", '2024-01-15Z', '２０２４-01-15', '2024-01-15 10:20:30.5'] as $date) {
            array_push($episodes, "5\t\t\t{$date}\t", "5\t3\t\t{$date}\t9", "6\t\t4\t{$date}\t");
        }
        foreach ($digits as $value) {
            array_push($episodes, "{$value}\t1\t1\t\t", "7\t{$value}\t1\t\t", "7\t1\t{$value}\t\t", "7\t1\t1\t\t{$value}");
        }
        foreach ([[1899, 101], [1900, 100], [1900, 101], [2099, 1231], [2099, 1232], [2100, 101], [99, 999], [100, 1], [1, 1000], [0, 5], [5, 0]] as [$s, $e]) {
            $episodes[] = "8\t{$s}\t{$e}\t\t";
        }
        for ($i = 0; $i < 300; $i++) {
            $episodes[] = ($i % 17 + 1)."\t".($i % 5)."\t".($i % 7)."\t".($i % 3 === 0 ? '2020-0'.($i % 9 + 1).'-1'.($i % 10) : '')."\t".($i % 4);
        }
        yield 'episode hydration bounds' => [EpisodeHydrationService::class, $episodes];
        $fanart = [];
        foreach (['movieposter', 'MoviePoster', ' tvposter ', 'seasonposter', 'poster', 'moviebackground', 'showbackground', 'artistbackground', 'background', 'moviebanner',
            'tvbanner', 'seasonbanner', 'banner', 'hdmovielogo', 'clearart', '', 'posters', "\xC9"] as $kind) {
            foreach (['en', 'EN', ' en ', '00', '', 'de', 'zz', 'eng', 'e1', 'İ', "\xC9\xE1"] as $lang) {
                $fanart[] = "tt9\t{$kind}\t{$lang}\t1\thttps://assets.fanart.tv/x.jpg\t1000\t1426";
            }
        }
        foreach (['https://assets.fanart.tv/fanart/a.jpg', 'HTTPS://ASSETS.FANART.TV/PREVIEW/A.PNG?X=1', 'http://images.fanart.tv/a.jpeg', 'https://fanart.tv/a.png',
            'https://evilfanart.tv/a.jpg', 'https://a.fanart.tv.evil/a.png', 'https://.fanart.tv/a.jpg', 'https://', 'http://', 'https:/a', 'ftp://a/b.jpg', 'https://a b/c.jpg',
            "https://a/\x7fb.jpg", 'https://a/b.jpg?c.png', 'https://a/b.png?', 'https://a/b.JPG', 'https://a/preview', 'https://a/preview/', '//a/b.jpg', 'https://a/b.jpg#x',
            ' https://a/b.png ', "https://\xC9/b.jpg", 'https://a/b.jp g'] as $url) {
            $fanart[] = "tt8\tmovieposter\ten\t2\t{$url}\t\t";
        }
        foreach ($digits as $value) {
            array_push($fanart, "tt7\tmovieposter\ten\t{$value}\thttps://a/b.jpg\t\t", "tt7\tmoviebanner\ten\t1\thttps://a/b.jpg\t{$value}\t185",
                "tt7\tmoviebackground\ten\t1\thttps://a/b.jpg\t1920\t{$value}");
        }
        foreach ([[999, 1426], [1000, 1425], [1000, 1426], [99999, 99999], [9999, 9999], [10000, 10000]] as [$w, $h]) {
            $fanart[] = "tt6\tmovieposter\t00\t0\thttps://a/{$w}.jpg\t{$w}\t{$h}";
        }
        for ($i = 0; $i < 300; $i++) {
            $fanart[] = 'g'.($i % 13)."\t".self::FANART_KINDS[$i % 12]."\t".['en', 'de', '', '00'][$i % 4]."\t".($i * 7 % 23)."\thttps://assets.fanart.tv/".($i % 5 === 0 ? 'preview' : 'fanart')."/{$i}.jpg\t".($i % 3 * 1000)."\t".($i % 2 * 1500);
        }
        yield 'fanart tv bounds' => [FanartTvService::class, $fanart];
        $fork = [];
        foreach ([...ForkingService::WORK_TYPES, '', 'Releases', ' releases ', 'postprocess_tv', 'unknown', "\xC9"] as $type) {
            $fork[] = "{$type}\t100\t4\t\t60\t100";
        }
        foreach ($digits as $value) {
            array_push($fork, "binaries\t{$value}\t4\t\t\t", "binaries\t100\t{$value}\t\t\t", "binaries\t100\t4\t{$value}\t\t", "binaries\t100\t4\t\t{$value}\t1000",
                "binaries\t100\t4\t\t1\t{$value}");
        }
        foreach ([[1, 0, 0, 0, 0], [999999999, 9999, 9999, 1, 9999999], [999999999, 0, 0, 999999, 9999999], [5, 10, 0, 0, 0], [5, 0, 3, 0, 0], [1000, 1, 1, 1, 1000],
            [1000, 1, 2, 1, 1000], [7, 3, 0, 0, 0], [999999999, 9999, 0, 1, 1]] as [$n, $req, $max, $t, $per]) {
            $fork[] = "backfill\t{$n}\t{$req}\t{$max}\t{$t}\t{$per}";
            $fork[] = "releases\t{$n}\t{$req}\t{$max}\t{$t}\t{$per}";
        }
        yield 'forking service bounds' => [ForkingService::class, $fork];
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
            self::markTestSkipped('Set RUN_RUST_FFI_BENCHMARKS=1 to run the Wave 67.2 paired benchmark.');
        }
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(67));
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
                            [$results[(int) $native], $elapsed[(int) $native]] = $class::withW67RustFfi($native, fn (): array => $this->timed($class, $items));
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
        $artifact = sys_get_temp_dir().'/w672-benchmark-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($artifact, json_encode(['php' => PHP_VERSION, 'os' => php_uname(), 'rounds' => self::ROUNDS, 'pairs' => self::PAIRS, 'warmups' => self::WARMUPS,
            'repeats' => self::REPEATS, 'summary' => $summary, 'records' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW67.2 paired benchmark artifact: {$artifact}\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach (array_keys(self::ENTRIES) as $class) {
            self::assertSame($summary[$class]['qualifies'], $class::usesW67RustFfi(), $class.' default must follow the measured 1.5x gate');
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
                    ProcessingRuntimeStateRepository::class => ["RUNNİNG\t\t1\t0\t0\t0\t", "PAUSED\tRUNNING\tYES\tNO\t1\t1\tPAUSED", "\xC9\t\tİ\t\t\t\t", "DRAINING\t\tON\tOFF\t0\t0\tDRAİNED"],
                    ConsolesProcessor::class => ["\xC9TOİLE.PS4\t1050\t\t0\t1\t", "ÉTOİLE.İ.WİİU\t1050\t\t0\t2\tYES", "GAME.WIIU.PAL\t1050\t\t0\t1\tTRUE", "x\xE9y.z\t1050\t\t0\t1\t"],
                    EpisodeHydrationService::class => ["1\t\t\t2024-01-15T10:20:30\t", "2\t\t\t2024-01-15t10:20:30\t", "3\t\xC9\t1\t\t", "4\t1\t1\t２０２４-01-15\t"],
                    FanartTvService::class => ["TT1\tMOVİEPOSTER\tEN\t1\tHTTPS://A/B.JPG\t\t", "tt1\tMOVIEPOSTER\tİN\t1\tHTTPS://ASSETS.FANART.TV/PREVİEW/A.PNG\t\t", "tt1\tposter\t\xC9\xE1\t1\thttp://\xC9/a.png\t\t"],
                    ForkingService::class => ["RELEASES\t1\t\t\t\t", "releases\t1\t\t\t\t", "bİnaries\t1\t\t\t\t", "binaries\t1\t\t\t\t"],
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
        $value = (new ReflectionClass($class))->getConstant('W67_RUST_FFI_DEFAULT');
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
        $php = $class::withW67RustFfi(false, fn (): array => $this->call($class, '', $items));
        $rust = $class::withW67RustFfi(true, fn (): array => $this->call($class, '', $items));
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
        $ffi = (new ReflectionMethod($class, 'w67RustFfiState'))->invoke(null, 'ffi');
        self::assertInstanceOf(FFI::class, $ffi, $class.' must load the nfo_w67 binding');

        return $ffi;
    }

    /** Each Wave 67.2 export must resolve and accept a one-item batch, so parity runs Rust. */
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
     * Calls an export declared by a class's W67 C declaration.
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
                ProcessingRuntimeStateRepository::class => $this->runtimeRow($random, $uncommon),
                ConsolesProcessor::class => $this->consoleRow($random, $uncommon),
                EpisodeHydrationService::class => $this->episodeRow($random, $uncommon),
                FanartTvService::class => $this->fanartRow($random, $uncommon),
                ForkingService::class => $this->forkRow($random, $uncommon),
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
            : $random->getBytesFromString("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789._-[]()<>@/\\+=:,\"' \t", $length);
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
     * A random row from one of the shared uncommon shapes, or $special() for the class-specific
     * ones.
     *
     * @param  list<string>  $fields
     * @param  list<string>  $values
     * @param  callable(): string  $special
     */
    private function uncommon(Randomizer $random, array $fields, array $values, callable $special): string
    {
        return match ($random->getInt(0, 9)) {
            0 => $this->noise($random, 80, true),
            1 => $this->noise($random, 60),
            2 => '',
            3 => $this->mutateFields($random, $fields, fn (string $field): string => $this->mangle($random, $field)),
            4 => $this->mutateFields($random, $fields, fn (string $field): string => $this->pick($random, $values)),
            5, 6 => $special(),
            default => $this->mutateFields($random, $fields, fn (string $field): string => $this->noise($random, 12, true)),
        };
    }

    private function runtimeRow(Randomizer $random, bool $uncommon): string
    {
        $fields = [$this->pick($random, ['running', 'running', 'running', 'paused', 'draining', 'drained', 'stopped']),
            $this->pick($random, ['', '', '', 'paused', 'running', 'draining', 'drained', 'stopped']), $this->pick($random, ['1', '1', '0', 'true', 'false', 'yes', 'no', '']),
            $this->pick($random, ['', '0', '0', '0', '1', 'no', 'yes']), (string) ($random->getInt(0, 2) === 0 ? 0 : $random->getInt(0, 5000)), (string) $random->getInt(0, 64),
            $this->pick($random, ['', ...self::STATES])];
        if (! $uncommon) {
            return implode("\t", $fields);
        }

        return $this->uncommon($random, $fields, ['-1', '9999999999', 'x', ' 3 ', '', '٣', 'Running', 'halted', 'maybe', 'ON'], fn (): string => implode("\t", [
            $this->pick($random, ['Running', ' DRAINING ', 'halted', '', 'İ', "\xC9", 'stopped']), $this->pick($random, ['RUNNING', 'bogus', 'drained ', '']),
            $this->pick($random, ['On', 'OFF', 'maybe', ' yes ']), $this->pick($random, ['TRUE', '2', '']), $this->pick($random, ['999999999', '0', '1000000000']),
            $fields[5], $this->pick($random, ['Paused', 'unknown', ''])]));
    }

    private function consoleRow(Randomizer $random, bool $uncommon): string
    {
        $title = [];
        for ($w = $random->getInt(1, 4); $w > 0; $w--) {
            $title[] = ucfirst($this->pick($random, self::GAME_WORDS));
        }
        $name = implode($this->pick($random, ['.', '.', '_', ' ']), $title);
        if ($random->getInt(0, 4) !== 0) {
            $name .= '.'.$this->pick($random, self::CONSOLE_TOKENS);
        }
        if ($random->getInt(0, 2) === 0) {
            $name .= '.'.$this->pick($random, self::REGION_TOKENS);
        }
        $name .= '-'.$this->pick($random, ['BigBlueBox', 'VENOM', 'DUPLEX', 'iNSOMNi', 'CODEX', 'Kuma']);
        $fields = [$name, (string) $this->pick($random, [1010, 1020, 1030, 1040, 1050, 1060, 1070, 1080, 1110, 1120, 1130, 1140, 1180, 1050, 2040, 1000]),
            $this->pick($random, ['', '', '', '0', (string) $random->getInt(1, 90000)]), (string) $this->pick($random, [0, 0, 0, 1, 1, 2, 3]), $this->pick($random, ['1', '1', '1', '2', '0']),
            $this->pick($random, ['', 'yes', 'no', '1', '0'])];
        if (! $uncommon) {
            return implode("\t", $fields);
        }

        return $this->uncommon($random, $fields, ['-1', '9999999', '0', 'x', ' 3 ', '', '٣', '3', 'maybe'], fn (): string => implode("\t", [
            $this->pick($random, ['Ünïcödé.PS4', '映画.Switch', 'PS4', 'USA.PAL', '', ' ', str_repeat('Long.', 60).'XBOX', $this->noise($random, 120, true)]), $fields[1],
            $fields[2], $this->pick($random, ['3', '4', '255', '00']), $this->pick($random, ['3', '9', '00', '2']), $this->pick($random, ['YES', 'True', 'maybe', "\xC9", ' no '])]));
    }

    private function episodeRow(Randomizer $random, bool $uncommon): string
    {
        $ts = $random->getInt(-2_000_000_000, 4_000_000_000);
        $date = match ($random->getInt(0, 4)) {
            0 => '',
            1 => gmdate('Y-m-d H:i:s', $ts),
            default => gmdate('Y-m-d', $ts),
        };
        $fields = [(string) $random->getInt(1, 400), $this->pick($random, ['', '', '0', (string) $random->getInt(1, 30), (string) $random->getInt(1, 12)]),
            $this->pick($random, ['', '0', (string) $random->getInt(1, 30), (string) $random->getInt(1, 24)]), $date, $this->pick($random, ['', '', '0', (string) $random->getInt(1, 900000)])];
        if (! $uncommon) {
            return implode("\t", $fields);
        }

        return $this->uncommon($random, $fields, ['-1', '99999', '0', 'x', ' 3 ', '', '٣', '2023-02-29', '0000-00-00', '2100-01-01'], fn (): string => implode("\t", [
            $fields[0], $this->pick($random, ['2024', '1899', '100', '9999', '']), $this->pick($random, ['1231', '1232', '1000', '0', '']),
            $this->pick($random, ['2024-13-01', '2024-04-31', '1899-12-31', '2024-01-15T10:20:30', '2024-01-15 25:00:00', '2024/01/15', '0000-00-00 00:00:00', $this->noise($random, 19)]),
            $fields[4]]));
    }

    private function fanartRow(Randomizer $random, bool $uncommon): string
    {
        $group = $this->pick($random, ['tt', 'TT', '']).$random->getInt(100000, 100040);
        $kind = $this->pick($random, self::FANART_KINDS);
        $url = 'https://assets.fanart.tv/'.($random->getInt(0, 5) === 0 ? 'preview' : 'fanart').'/movies/'.$group.'/'.$kind.'/'.bin2hex($random->getBytes(6))
            .$this->pick($random, ['.jpg', '.jpg', '.png', '.jpeg']);
        [$w, $h] = $this->pick($random, [[1000, 1426], [680, 1000], [1920, 1080], [1280, 720], [1000, 185], [3840, 2160], [0, 0]]);
        $fields = [$group, $kind, $this->pick($random, ['en', 'en', 'en', 'de', 'fr', '00', '', 'es', 'ru']), (string) $random->getInt(0, 200), $url,
            $w === 0 ? '' : (string) $w, $h === 0 ? '' : (string) $h];
        if (! $uncommon) {
            return implode("\t", $fields);
        }

        return $this->uncommon($random, $fields, ['-1', '9999999999', '0', 'x', ' 3 ', '', '٣', 'ftp://a/b.jpg', 'eng'], fn (): string => implode("\t", [
            $this->pick($random, [$group, strtoupper($group), ' '.$group.' ', 'Ünïcödé', '']), $this->pick($random, ['MoviePoster', 'poster', 'banner', 'clearlogo']),
            $this->pick($random, ['EN', 'zz', 'eng', 'İ', '']), $fields[3], $this->pick($random, ['HTTPS://ASSETS.FANART.TV/PREVIEW/A.PNG?X=1', 'http://x y/a.jpg', 'https://',
                'https://evilfanart.tv/a.jpg', 'https://a.fanart.tv.evil/a.png', "https://a/\x7f.jpg", $url.'?v=2']), $this->pick($random, ['99999', '1000', '']),
            $this->pick($random, ['99999', '1426', ''])]));
    }

    private function forkRow(Randomizer $random, bool $uncommon): string
    {
        $fields = [$this->pick($random, ForkingService::WORK_TYPES), (string) ($random->getInt(0, 9) === 0 ? 0 : $random->getInt(1, 5000)), (string) $random->getInt(0, 32),
            $this->pick($random, ['', '', '4', '8', '16', '64']), $this->pick($random, ['', '0', '60', '300', '3600']), $this->pick($random, ['', '0', '5', '50', '250', '1000'])];
        if (! $uncommon) {
            return implode("\t", $fields);
        }

        return $this->uncommon($random, $fields, ['-1', '9999999999', '0', 'x', ' 3 ', '', '٣', '99999'], fn (): string => implode("\t", [
            $this->pick($random, ['Releases', ' releases ', 'unknown', 'postprocess_tv', '', $fields[0]]), $this->pick($random, ['999999999', '1', '0', $fields[1]]),
            $this->pick($random, ['9999', '1', '0']), $this->pick($random, ['9999', '1', '0']), $this->pick($random, ['1', '999999', '0']),
            $this->pick($random, ['9999999', '1', '0'])]));
    }
}
