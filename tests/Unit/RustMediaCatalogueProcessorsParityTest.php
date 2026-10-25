<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\ConfigurationDomain;
use App\Enums\LookupMode;
use App\Services\BooksProcessor;
use App\Services\Configuration\ConfigurationProvider;
use App\Services\ConsolesProcessor;
use App\Services\GamesProcessor;
use App\Services\GamesService;
use App\Services\MoviesProcessor;
use App\Services\MusicProcessor;
use App\Support\Configuration\MetadataConfigurationData;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;
use Tests\TestCase;

final class RustMediaCatalogueProcessorsParityTest extends TestCase
{
    /** @var list<class-string> */
    private const PROCESSORS = [
        BooksProcessor::class,
        ConsolesProcessor::class,
        GamesProcessor::class,
        MoviesProcessor::class,
        MusicProcessor::class,
    ];

    /** Processors whose plan is driven by the configured lookup value alone. */
    private const SETTING_PROCESSORS = [
        BooksProcessor::class,
        ConsolesProcessor::class,
        GamesProcessor::class,
        MusicProcessor::class,
    ];

    private const LOOKUPS = [PHP_INT_MIN, -5, -1, 0, 1, 2, 3, 4294967296, PHP_INT_MAX];

    /** `$processMovies` overrides covering PHP 8 numeric-string and `(int)` cast edge cases. */
    private const OVERRIDES = [
        null, '', ' ', '0', '1', '2', ' 2', "2 \n", "\x0B1\x0C", '-1', '-0', '+1', '1.9', '-1.9', '.5', '5.',
        '0.5', '1e', '1e3', '+.5e-3', '2.5E0', '1e1000', '-1e1000', '-9.3e18', '9223372036854775807',
        '9223372036854775808', '-9223372036854775808', '-9223372036854775809', '00000000000000000000001',
        '1_000', '0x1A', '1 2', 'abc', '.', '+', '-', "\x001", "1\x00", "1\x1F", '١', "1\u{00A0}", "bad \xff",
        0, 1, 2, -1, PHP_INT_MAX, PHP_INT_MIN,
    ];

    protected function tearDown(): void
    {
        foreach (self::PROCESSORS as $processor) {
            $processor::setUseRustFfi(null);
            (new ReflectionProperty($processor, 'ffi'))->setValue(null, null);
        }
        Cache::forget(ConfigurationProvider::cacheKeyFor(ConfigurationDomain::Metadata));
        Schema::dropIfExists(ConfigurationProvider::tableFor(ConfigurationDomain::Metadata));
        parent::tearDown();
    }

    public function test_rust_ffi_is_disabled_by_default_and_toggles(): void
    {
        foreach (self::PROCESSORS as $processor) {
            $this->assertFalse($processor::usesRustFfi(), $processor);
            $processor::setUseRustFfi(true);
            $this->assertTrue($processor::usesRustFfi(), $processor);
            $processor::setUseRustFfi(false);
            $this->assertFalse($processor::usesRustFfi(), $processor);
            $processor::setUseRustFfi(null);
            $this->assertFalse($processor::usesRustFfi(), $processor);
        }
    }

    public function test_php_reference_semantics(): void
    {
        $this->assertSame([
            'dispatch' => true,
            'mode' => 1,
            'echo' => false,
            'source' => 'setting',
            'summary' => 'Books lookup: mode=1 source=setting dispatch=yes echo=off',
        ], BooksProcessor::dispatchPlanPhp(1, false));
        $this->assertSame('Consoles lookup: mode=0 source=setting dispatch=no echo=on', ConsolesProcessor::dispatchPlanPhp(0, true)['summary']);
        $this->assertSame('Games lookup: mode=2 source=setting dispatch=yes echo=off', GamesProcessor::dispatchPlanPhp(2, false)['summary']);
        $this->assertSame('Music lookup: mode=-1 source=setting dispatch=yes echo=on', MusicProcessor::dispatchPlanPhp(-1, true)['summary']);

        $this->assertSame([
            'dispatch' => true,
            'mode' => 2,
            'echo' => true,
            'source' => 'override',
            'summary' => 'Movies lookup: mode=2 source=override dispatch=yes echo=on',
        ], MoviesProcessor::dispatchPlanPhp(' 2', 0, true));
        $this->assertSame(['dispatch' => true, 'mode' => 1, 'source' => 'setting'], $this->movieCore(MoviesProcessor::dispatchPlanPhp('', 1, false)));
        $this->assertSame(['dispatch' => false, 'mode' => 0, 'source' => 'override'], $this->movieCore(MoviesProcessor::dispatchPlanPhp('0.5', 2, false)));
        $this->assertSame(['dispatch' => false, 'mode' => -1, 'source' => 'override'], $this->movieCore(MoviesProcessor::dispatchPlanPhp(-1, 2, false)));
        $this->assertSame(['dispatch' => false, 'mode' => 0, 'source' => 'setting'], $this->movieCore(MoviesProcessor::dispatchPlanPhp(null, 0, false)));
        $this->assertSame(PHP_INT_MAX, MoviesProcessor::dispatchPlanPhp('9223372036854775808', 0, false)['mode']);
    }

    public function test_setting_processor_native_and_dispatch_match_php(): void
    {
        $this->ffiOrSkip();
        foreach (self::SETTING_PROCESSORS as $processor) {
            foreach (self::LOOKUPS as $lookup) {
                foreach ([false, true] as $echo) {
                    $this->assertSettingParity($processor, $lookup, $echo);
                }
            }
        }
    }

    public function test_movies_native_and_dispatch_match_php(): void
    {
        $this->ffiOrSkip();
        foreach (self::OVERRIDES as $override) {
            foreach ([0, 1, 2, -3] as $default) {
                foreach ([false, true] as $echo) {
                    $this->assertMoviesParity($override, $default, $echo);
                }
            }
        }
    }

    public function test_movies_default_closure_is_read_lazily_once(): void
    {
        $this->ffiOrSkip();
        foreach ([false, true] as $useRust) {
            MoviesProcessor::setUseRustFfi($useRust);
            foreach (self::OVERRIDES as $override) {
                $calls = 0;
                $default = static function () use (&$calls): int {
                    $calls++;

                    return 2;
                };
                $plan = MoviesProcessor::dispatchPlan($override, $default, false);
                $numeric = is_numeric($override);
                $this->assertSame($numeric ? 0 : 1, $calls, json_encode([$useRust, $override]) ?: '');
                $this->assertSame($numeric ? 'override' : 'setting', $plan['source']);
                $this->assertSame(MoviesProcessor::dispatchPlanPhp($override, 2, false), $plan);
            }
        }
    }

    public function test_randomized_inputs_match_php(): void
    {
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(3404));
        $alphabet = " \t\n\r\x0B\x0C\x00+-.eE0123456789x_a\x1F\xff";
        $int = static fn (): int => match ($random->getInt(0, 3)) {
            0 => $random->getInt(-3, 3),
            1 => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
            2 => $random->getInt(-100000, 100000),
            default => $random->getInt(0, 2),
        };
        $text = static function () use ($random, $alphabet): string {
            $value = '';
            $length = $random->getInt(0, 12);
            for ($i = 0; $i < $length; $i++) {
                $value .= $alphabet[$random->getInt(0, strlen($alphabet) - 1)];
            }

            return $value;
        };
        $numeric = static fn (): string => ($random->getInt(0, 1) === 1 ? ' ' : '')
            .['', '+', '-'][$random->getInt(0, 2)]
            .$random->getInt(0, 999999)
            .($random->getInt(0, 1) === 1 ? '.'.$random->getInt(0, 999) : '')
            .($random->getInt(0, 2) === 1 ? 'e'.['', '+', '-'][$random->getInt(0, 2)].$random->getInt(0, 25) : '')
            .($random->getInt(0, 1) === 1 ? "\n" : '');

        for ($i = 0; $i < 1500; $i++) {
            $processor = self::SETTING_PROCESSORS[$random->getInt(0, 3)];
            $this->assertSettingParity($processor, $int(), $random->getInt(0, 1) === 1);
            $override = match ($random->getInt(0, 3)) {
                0 => null,
                1 => $int(),
                2 => $text(),
                default => $numeric(),
            };
            $this->assertMoviesParity($override, $int(), $random->getInt(0, 1) === 1);
        }
    }

    public function test_native_exports_validate_input_and_capacity(): void
    {
        $ffi = $this->ffiOrSkip();
        $out = $ffi->new('char[256]');
        $call = fn (mixed ...$args): mixed => $this->callFfi($ffi, 'nntmux_w34_media_catalogue_processor_plan', ...[...$args, $out, 256]);

        $written = $call(1, 0, 0, null, 0, 1, 1, 0);
        $this->assertSame("1\x1F1\x1F0\x1Fsetting\x1FBooks lookup: mode=1 source=setting dispatch=yes echo=off", FFI::string($out, $written));
        $written = $call(4, 2, 0, ' 2 ', 3, 0, 0, 1);
        $this->assertSame("1\x1F2\x1F1\x1Foverride\x1FMovies lookup: mode=2 source=override dispatch=yes echo=on", FFI::string($out, $written));

        // -2: the movie override is not numeric and no default lookup was supplied.
        $this->assertSame(-2, $call(4, 2, 0, 'abc', 3, 0, 0, 0));
        $this->assertSame(-2, $call(4, 0, 0, null, 0, 0, 0, 0));
        $this->assertSame(-2, $call(4, 2, 0, '', 0, 0, 0, 0));

        // -1: unknown processor, overrides on setting-only processors, bad flags/kinds and pointers.
        foreach ([
            [0, 0, 0, null, 0, 1, 1, 0],
            [6, 0, 0, null, 0, 1, 1, 0],
            [3, 1, 1, null, 0, 1, 1, 0],
            [3, 2, 0, '1', 1, 1, 1, 0],
            [3, 0, 0, null, 0, 0, 0, 0],
            [3, 0, 0, null, 0, 2, 1, 0],
            [3, 0, 0, null, 0, 1, 1, 2],
            [4, 3, 0, null, 0, 1, 1, 0],
            [4, 0, 5, null, 0, 1, 1, 0],
            [4, 0, 0, 'x', 1, 1, 1, 0],
            [4, 1, 1, '1', 1, 1, 1, 0],
            [4, 2, 0, null, 0, 1, 1, 0],
            [4, 2, 0, null, 3, 1, 1, 0],
            [4, 2, 7, '1', 1, 1, 1, 0],
            [4, 0, 0, null, 0, 0, 4, 0],
        ] as $args) {
            $this->assertSame(-1, $call(...$args), json_encode($args) ?: '');
        }
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w34_media_catalogue_processor_plan', 1, 0, 0, null, 0, 1, 1, 0, null, 256));

        $small = $ffi->new('char[8]');
        FFI::memset($small, ord('Z'), 8);
        $needed = $this->callFfi($ffi, 'nntmux_w34_media_catalogue_processor_plan', 5, 0, 0, null, 0, 1, 0, 1, $small, 8);
        $this->assertLessThan(-2, $needed);
        $this->assertSame('ZZZZZZZZ', FFI::string($small, 8));
        $exact = $ffi->new('char['.(-$needed).']');
        $this->assertSame(-$needed, $this->callFfi($ffi, 'nntmux_w34_media_catalogue_processor_plan', 5, 0, 0, null, 0, 1, 0, 1, $exact, -$needed));
        $this->assertSame("0\x1F0\x1F1\x1Fsetting\x1FMusic lookup: mode=0 source=setting dispatch=no echo=on", FFI::string($exact, -$needed));

        foreach (self::PROCESSORS as $processor) {
            $processor::setUseRustFfi(true);
        }
        foreach (self::SETTING_PROCESSORS as $processor) {
            $processor::dispatchPlan(1, false);
        }
        MoviesProcessor::dispatchPlan('1', 0, false);
        foreach (self::PROCESSORS as $processor) {
            $this->assertInstanceOf(FFI::class, (new ReflectionProperty($processor, 'ffi'))->getValue(), $processor.' must resolve its native export');
        }
    }

    public function test_missing_library_falls_back_to_php(): void
    {
        foreach (self::PROCESSORS as $processor) {
            (new ReflectionProperty($processor, 'ffi'))->setValue(null, false);
            $processor::setUseRustFfi(true);
        }
        foreach (self::SETTING_PROCESSORS as $processor) {
            foreach (self::LOOKUPS as $lookup) {
                $this->assertSame($processor::dispatchPlanPhp($lookup, true), $processor::dispatchPlan($lookup, true), $processor);
                $this->assertSame($processor::dispatchPlanPhp($lookup, false), $processor::dispatchPlanNative($lookup, false), $processor);
            }
        }
        foreach (self::OVERRIDES as $override) {
            $calls = 0;
            $default = static function () use (&$calls): int {
                $calls++;

                return 1;
            };
            $this->assertSame(MoviesProcessor::dispatchPlanPhp($override, 1, false), MoviesProcessor::dispatchPlanNative($override, $default, false));
            $this->assertSame(is_numeric($override) ? 0 : 1, $calls);
        }
    }

    public function test_process_gates_dispatch_on_configured_lookup(): void
    {
        foreach ([false, true] as $useRust) {
            foreach (self::PROCESSORS as $processor) {
                $processor::setUseRustFfi($useRust);
            }

            // Disabled lookups never construct (or touch) the downstream metadata services.
            $this->cacheMetadata(LookupMode::Disabled);
            (new BooksProcessor(false))->process();
            (new ConsolesProcessor(false))->process();
            (new MusicProcessor(true))->process();
            (new MoviesProcessor(false))->process();
            (new MoviesProcessor(false))->process('', '', 'not numeric');
            (new MoviesProcessor(false))->process('', '', 0);
            (new MoviesProcessor(false))->process('', '', '-1');
            $games = $this->createMock(GamesService::class);
            $games->expects($this->never())->method('processGamesReleases');
            (new GamesProcessor(false, $games))->process('7', 'a');

            $this->cacheMetadata(LookupMode::Renamed);
            $games = $this->createMock(GamesService::class);
            $games->expects($this->once())->method('processGamesReleases')->with('7', 'a');
            (new GamesProcessor(true, $games))->process('7', 'a');
        }
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of the five processor plans through their public dispatchers,
        // including argument packing, the FFI call and field decoding (release dylib, 5 warmups,
        // 301 samples x 40 iterations). Common = configured 0/1/2 lookups and '' movie overrides;
        // uncommon = padded, exponent, overflowing and non-numeric movie overrides with lazy defaults.
        // Speedup = PHP latency / Rust latency per workload pass (PHP 8.5.12 NTS, Apple M5, 3 rounds):
        // - Round 1: common mean 0.24x / p99 0.23x, uncommon mean 0.24x / p99 0.24x
        // - Round 2: common mean 0.24x / p99 0.25x, uncommon mean 0.23x / p99 0.23x
        // - Round 3: common mean 0.23x / p99 0.22x, uncommon mean 0.23x / p99 0.26x
        // Reproduce: php vendor/bin/phpunit tests/Unit/RustMediaCatalogueProcessorsParityTest.php --filter benchmark
        // Sub-microsecond PHP comparisons are far cheaper than the FFI round trip, so every processor stays
        // below the 1.5x bar in .ai/rules/rust-performance.md and keeps RUST_FFI_DEFAULT = false.
        $this->ffiOrSkip();
        $workloads = ['common' => $this->commonWorkload(), 'uncommon' => $this->uncommonWorkload()];
        $random = new Randomizer(new Mt19937(3405));
        $warmup = 5;
        $samples = 301;
        $iterations = 40;
        $sink = 0;

        $speedups = [];
        foreach ($workloads as $name => $workload) {
            for ($w = 0; $w < $warmup; $w++) {
                $sink += $this->runWorkload($workload, false) + $this->runWorkload($workload, true);
            }
            $times = ['php' => [], 'rust' => []];
            for ($s = 0; $s < $samples; $s++) {
                $order = $random->getInt(0, 1) === 1 ? [true, false] : [false, true];
                foreach ($order as $useRust) {
                    $start = hrtime(true);
                    for ($i = 0; $i < $iterations; $i++) {
                        $sink += $this->runWorkload($workload, $useRust);
                    }
                    $times[$useRust ? 'rust' : 'php'][] = (hrtime(true) - $start) / 1_000 / $iterations;
                }
            }
            foreach (self::PROCESSORS as $processor) {
                $processor::setUseRustFfi(null);
            }
            $speedups[$name] = [
                'mean' => $this->mean($times['php']) / $this->mean($times['rust']),
                'p99' => $this->p99($times['php']) / $this->p99($times['rust']),
            ];
        }

        $this->assertGreaterThan(0, $sink);
        $summary = json_encode($speedups, JSON_PRESERVE_ZERO_FRACTION) ?: '';
        if ($speedups['common']['mean'] < 1.5 || $speedups['common']['p99'] < 1.5 || $speedups['uncommon']['p99'] < 1.5) {
            foreach (self::PROCESSORS as $processor) {
                $this->assertFalse($processor::usesRustFfi(), $processor.' must keep PHP below 1.5x: '.$summary);
            }
        }
        foreach ($speedups as $name => $speedup) {
            $this->assertGreaterThan(0.0, $speedup['mean'], $name);
            $this->assertGreaterThan(0.0, $speedup['p99'], $name);
        }
    }

    /**
     * @param  class-string  $processor
     */
    private function assertSettingParity(string $processor, int $lookup, bool $echo): void
    {
        $expected = $processor::dispatchPlanPhp($lookup, $echo);
        $context = $processor.' '.json_encode([$lookup, $echo]);
        $this->assertSame($expected, $processor::dispatchPlanNative($lookup, $echo), $context);
        foreach ([false, true] as $useRust) {
            $processor::setUseRustFfi($useRust);
            $this->assertSame($expected, $processor::dispatchPlan($lookup, $echo), $context);
        }
        $processor::setUseRustFfi(null);
    }

    private function assertMoviesParity(int|string|null $override, int $default, bool $echo): void
    {
        $expected = MoviesProcessor::dispatchPlanPhp($override, $default, $echo);
        $context = json_encode([is_string($override) ? bin2hex($override) : $override, $default, $echo]) ?: '';
        $this->assertSame($expected, MoviesProcessor::dispatchPlanNative($override, $default, $echo), $context);
        $this->assertSame($expected, MoviesProcessor::dispatchPlanNative($override, static fn (): int => $default, $echo), $context);
        foreach ([false, true] as $useRust) {
            MoviesProcessor::setUseRustFfi($useRust);
            $this->assertSame($expected, MoviesProcessor::dispatchPlan($override, static fn (): int => $default, $echo), $context);
        }
        MoviesProcessor::setUseRustFfi(null);
    }

    /**
     * @param  array{dispatch: bool, mode: int, echo: bool, source: string, summary: string}  $plan
     * @return array{dispatch: bool, mode: int, source: string}
     */
    private function movieCore(array $plan): array
    {
        return ['dispatch' => $plan['dispatch'], 'mode' => $plan['mode'], 'source' => $plan['source']];
    }

    private function cacheMetadata(LookupMode $mode): void
    {
        // The provider only reads (cached) persisted configuration once its table exists.
        $table = ConfigurationProvider::tableFor(ConfigurationDomain::Metadata);
        if (! Schema::hasTable($table)) {
            Schema::create($table, static function (Blueprint $table): void {
                $table->id();
            });
        }
        $defaults = MetadataConfigurationData::defaultsPhp();
        Cache::put(ConfigurationProvider::cacheKeyFor(ConfigurationDomain::Metadata), new MetadataConfigurationData(
            $mode,
            $mode,
            $mode,
            $mode,
            $mode,
            $mode,
            $defaults->movieLanguage,
            $defaults->imdbAlternateUrl,
            $defaults->maxAnimeProcessed,
            $defaults->maxBooksProcessed,
            $defaults->maxGamesProcessed,
            $defaults->maxMoviesProcessed,
            $defaults->maxMusicProcessed,
            $defaults->maxTvProcessed,
            $defaults->amazonSleepMilliseconds,
        ), 300);
    }

    /**
     * @return list<array{string, list<mixed>}>
     */
    private function commonWorkload(): array
    {
        $workload = [];
        foreach ([0, 1, 2] as $lookup) {
            foreach (self::SETTING_PROCESSORS as $processor) {
                $workload[] = [$processor, [$lookup, false]];
            }
            $workload[] = [MoviesProcessor::class, ['', $lookup, false]];
        }

        return $workload;
    }

    /**
     * @return list<array{string, list<mixed>}>
     */
    private function uncommonWorkload(): array
    {
        $workload = [];
        foreach ([" 2\n", '+.5e1', '1e1000', '9223372036854775808', 'renamed', null, PHP_INT_MIN, "1\u{00A0}"] as $override) {
            $workload[] = [MoviesProcessor::class, [$override, static fn (): int => 2, true]];
        }
        foreach ([PHP_INT_MIN, -1, PHP_INT_MAX] as $lookup) {
            $workload[] = [BooksProcessor::class, [$lookup, true]];
            $workload[] = [MusicProcessor::class, [$lookup, true]];
        }

        return $workload;
    }

    /**
     * @param  list<array{string, list<mixed>}>  $workload
     */
    private function runWorkload(array $workload, bool $useRust): int
    {
        foreach (self::PROCESSORS as $processor) {
            $processor::setUseRustFfi($useRust);
        }
        $count = 0;
        foreach ($workload as [$processor, $args]) {
            $count += strlen($processor::dispatchPlan(...$args)['summary']);
        }

        return $count;
    }

    /**
     * @param  list<float>  $values
     */
    private function mean(array $values): float
    {
        return array_sum($values) / max(1, count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private function p99(array $values): float
    {
        sort($values);

        return $values[(int) min(count($values) - 1, (int) ceil(count($values) * 0.99) - 1)];
    }

    private function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function ffiOrSkip(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('metadata', BooksProcessor::FFI_CDEF);
        if ($ffi === null) {
            $this->markTestSkipped('libnntmux_pipeline with the media catalogue processor export is not available.');
        }

        return $ffi;
    }
}
