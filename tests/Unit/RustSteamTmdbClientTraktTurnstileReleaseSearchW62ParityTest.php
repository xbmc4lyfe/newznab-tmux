<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\ReleaseSearchService;
use App\Services\SteamService;
use App\Services\TmdbClient;
use App\Services\TraktService;
use App\Services\TurnstileService;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * Paired randomized benchmark (`test_paired_randomized_benchmark_gates_rust_default`, run with
 * `NNTMUX_W625_BENCH=1`): per helper, 100 interleaved samples per workload after 20 warmup rounds,
 * per-sample random PHP/Rust order, each side the fastest of three back-to-back calls, release dylib.
 * Common workloads hold 320 records and uncommon ones 384 records with edge cases. Timings include
 * PHP-side packing, the FFI call and output decoding; each run prints its ratios to STDERR.
 *
 * A helper only defaults to Rust when every recorded common and uncommon mean and p99 ratio reaches 1.5x
 * (QUALIFICATION); each benchmark run asserts that the Rust defaults still clear 1.5x.
 */
class RustSteamTmdbClientTraktTurnstileReleaseSearchW62ParityTest extends TestCase
{
    /**
     * Batch method per class; each has `<method>Php` and `<method>Native` variants.
     *
     * @var array<class-string, string>
     */
    private const METHODS = [
        SteamService::class => 'lookupPlanBatch',
        TmdbClient::class => 'requestPlanBatch',
        TraktService::class => 'lookupPlanBatch',
        TurnstileService::class => 'verificationPlanBatch',
        ReleaseSearchService::class => 'searchPlanBatch',
    ];

    /**
     * Private C declaration constant and entry point per class.
     *
     * @var array<class-string, array{string, string}>
     */
    private const FUNCTIONS = [
        SteamService::class => ['W62_STEAM_PLAN_FFI_CDEF', 'nntmux_w625_steam_lookup_plan'],
        TmdbClient::class => ['W62_TMDB_PLAN_FFI_CDEF', 'nntmux_w625_tmdb_request_plan'],
        TraktService::class => ['W62_TRAKT_PLAN_FFI_CDEF', 'nntmux_w625_trakt_lookup_plan'],
        TurnstileService::class => ['W62_TURNSTILE_PLAN_FFI_CDEF', 'nntmux_w625_turnstile_verification_plan'],
        ReleaseSearchService::class => ['W62_RELEASE_SEARCH_PLAN_FFI_CDEF', 'nntmux_w625_release_search_plan'],
    ];

    /**
     * Output words per record.
     *
     * @var array<class-string, int>
     */
    private const WIDTHS = [
        SteamService::class => 10,
        TmdbClient::class => 11,
        TraktService::class => 9,
        TurnstileService::class => 9,
        ReleaseSearchService::class => 15,
    ];

    /**
     * Benchmark-qualified defaults (see the class docblock for the gate).
     *
     * @var array<class-string, bool>
     */
    private const DEFAULTS = [
        SteamService::class => true,
        TmdbClient::class => true,
        TraktService::class => true,
        TurnstileService::class => true,
        ReleaseSearchService::class => true,
    ];

    /**
     * Common mean/p99 then uncommon mean/p99 speedups (PHP / Rust), the per-metric minimum of three
     * `NNTMUX_W625_BENCH=1` runs (100 samples per workload, seed 6252, release Rust, PHP CLI without
     * opcache, Apple Silicon macOS on a shared host).
     *
     * @var array<class-string, array{float, float, float, float}>
     */
    private const QUALIFICATION = [
        SteamService::class => [2.85, 2.78, 2.65, 2.63],
        TmdbClient::class => [7.65, 5.41, 6.67, 6.80],
        TraktService::class => [6.92, 5.60, 6.64, 5.75],
        TurnstileService::class => [1.92, 1.85, 3.05, 2.46],
        ReleaseSearchService::class => [6.54, 5.06, 5.75, 4.75],
    ];

    /**
     * Static properties before Wave 62.5; the wave keeps its state in function-local statics.
     *
     * @var array<class-string, list<string>>
     */
    private const STATIC_PROPERTIES = [
        SteamService::class => ['titleMatchingFfi', 'titleMatchingResolved', 'w444UseRustFfi', 'w444Ffi'],
        TmdbClient::class => ['useRustFfi', 'ffi', 'w464UseRustFfi', 'w464Ffi'],
        TraktService::class => ['traktFfi', 'traktFfiResolved', 'w464UseRustFfi', 'w464Ffi'],
        TurnstileService::class => ['useRustFfi', 'ffi', 'w48SiteverifyRustFfi', 'w48Ffi'],
        ReleaseSearchService::class => ['ffi', 'outBuf512', 'outBounds2', 'useRustFfi', 'useW48ReleaseSearchServiceRustFfi', 'w48Ffi', 'w48Out', 'w48OutCap'],
    ];

    /** Back-to-back calls per side of a sample; the fastest is kept (drops preemption spikes). */
    private const REPEATS = 3;

    private const RANDOM_ROUNDS = 400;

    private const BENCH_SAMPLES = 100;

    private const BYTES = ['a', 'Z', 'x', 'X', 't', 'T', 'f', '0', '1', '7', '9', ' ', "\t", "\n", "\r", "\x0b", "\x0c", "\x01", "\x7f", '-', '+', '_', '.', ',', ':', '/', '@', "'", "\xc3\xa9", "\xc3", "\xff", "\0"];

    /** Fragments glued into otherwise structured records so separators and keywords actually occur. */
    private const FRAGMENTS = ["\t", "\t\t", ',', ',,', ' ', '  ', '-', '.', '..', ':', '::', '/', '@', 'X', 'x', '0', '1', '9', '-1', 'tt', 'TT', '0x', 'https://', '1999', '255', '256', '999999', '8388608', '999999999999999999', '1234567890123456789', 'CODEX', 'goty', 'Edition', 'REPACK', 'imdb', 'slug', 'true', 'internal-error', 'alt.binaries.', 'a.b.', "\x0c", "\x01", "\xc3\xa9"];

    protected function tearDown(): void
    {
        foreach (array_keys(self::METHODS) as $class) {
            self::state($class, 'reset', null);
        }

        parent::tearDown();
    }

    /**
     * @return array<string, array{class-string, string}>
     */
    public static function helperProvider(): array
    {
        $cases = [];
        foreach (self::METHODS as $class => $method) {
            $cases[(new ReflectionClass($class))->getShortName()] = [$class, $method];
        }

        return $cases;
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_methods_defaults_and_scoped_toggles(string $class, string $method): void
    {
        $reflection = new ReflectionClass($class);
        foreach (['usesW62RustFfi', 'withW62RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
            $this->assertTrue($reflection->hasMethod($name), "{$class}::{$name}");
            $this->assertTrue($reflection->getMethod($name)->isStatic() && $reflection->getMethod($name)->isPublic(), "{$class}::{$name}");
        }
        $this->assertTrue($reflection->getMethod('w62RustFfiState')->isPrivate(), "{$class} keeps its W62 state private");
        $this->assertTrue((new ReflectionClassConstant($class, self::FUNCTIONS[$class][0]))->isPrivate(), "{$class} keeps its W62 declaration private");
        $constant = new ReflectionClassConstant($class, 'W62_RUST_FFI_DEFAULT');
        $this->assertTrue($constant->isPublic(), "{$class} default must be public");
        $this->assertSame(self::DEFAULTS[$class], $constant->getValue(), "{$class} default must match the benchmark evidence");
        $this->assertSame(min(self::QUALIFICATION[$class]) >= 1.5, self::DEFAULTS[$class], "{$class} default must follow its recorded qualification");
        $this->assertSame(self::DEFAULTS[$class], $class::usesW62RustFfi(), "{$class} routes by its default (PHP when it did not qualify)");

        foreach ([true, false] as $enabled) {
            $this->assertSame([$enabled, 'ok'], $class::withW62RustFfi($enabled, static fn () => [$class::usesW62RustFfi(), 'ok']));
            $this->assertSame(self::DEFAULTS[$class], $class::usesW62RustFfi(), "{$class} must restore after the callback");
        }
        $nested = $class::withW62RustFfi(false, static fn () => [$class::withW62RustFfi(true, static fn () => $class::usesW62RustFfi()), $class::usesW62RustFfi()]);
        $this->assertSame([true, false], $nested);
        $caught = null;
        try {
            $class::withW62RustFfi(! $class::usesW62RustFfi(), static fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException $exception) {
            $caught = $exception->getMessage();
        }
        $this->assertSame('boom', $caught, 'The callback exception must propagate.');
        $this->assertSame(self::DEFAULTS[$class], $class::usesW62RustFfi(), "{$class} must restore after an exception");

        $class::withW62RustFfi(! $class::usesW62RustFfi(), function () use ($class): void {
            foreach (array_keys(self::METHODS) as $other) {
                if ($other !== $class) {
                    $this->assertSame(self::DEFAULTS[$other], $other::usesW62RustFfi(), "{$class} leaked into {$other}");
                }
            }
        });
    }

    public function test_w62_state_adds_no_properties(): void
    {
        foreach (self::STATIC_PROPERTIES as $class => $expected) {
            $reflection = new ReflectionClass($class);
            foreach ($reflection->getProperties() as $property) {
                $this->assertStringNotContainsStringIgnoringCase('w62', $property->getName(), $class);
            }
            $this->assertSame($expected, array_map(
                static fn (ReflectionProperty $property): string => $property->getName(),
                $reflection->getProperties(ReflectionProperty::IS_STATIC),
            ), "{$class} gains no static properties");
        }
    }

    public function test_earlier_wave_toggles_are_untouched(): void
    {
        $snapshot = static fn (): array => [
            SteamService::usesW444RustFfi(),
            TmdbClient::usesRustFfi(),
            TmdbClient::usesW464RustFfi(),
            TraktService::usesW464RustFfi(),
            TurnstileService::usesRustFfi(),
            TurnstileService::usesW48SiteverifyRustFfi(),
            ReleaseSearchService::usesRustFfi(),
            ReleaseSearchService::usesW48ReleaseSearchServiceRustFfi(),
        ];
        $before = $snapshot();
        foreach (array_keys(self::METHODS) as $class) {
            foreach ([true, false] as $enabled) {
                $this->assertSame($before, $class::withW62RustFfi($enabled, $snapshot), $class);
            }
        }
        $this->assertSame($before, $snapshot());
    }

    public function test_existing_public_api_is_preserved(): void
    {
        foreach ([
            SteamService::class => ['search', 'getGameDetails', 'getAll', 'getPlayerCount', 'getReviewsSummary', 'getDLCList', 'populateSteamAppsTable', 'getFullAppList', 'searchMultiple', 'cleanTitle', 'cleanTitlePhp', 'generateQueryVariantsPhp', 'normalizeTitlePhp', 'stripEditionTagsPhp', 'scoreTitlePhp', 'tokenizePhp', 'replaceRomanNumeralsPhp', 'buildLikePatternPhp', 'clearCache', 'generateQueryVariantsNative', 'tokenizeNative', 'buildLikePatternNative', 'useW444RustFfi', 'usesW444RustFfi', 'resetW444RustFfi', 'summarizeSteamTitleBatch', 'summarizeSteamTitleBatchNative', 'summarizeSteamTitleBatchPhp'],
            TmdbClient::class => ['setUseRustFfi', 'usesRustFfi', 'isConfigured', 'throwingOnTransientFailure', 'getImageUrl', 'getImageUrlNative', 'getImageUrlPhp', 'searchMovies', 'getMovie', 'findMovieByExternalId', 'getMovieCredits', 'getMovieExternalIds', 'getMovieAlternativeTitles', 'searchTv', 'getTvShow', 'getTvExternalIds', 'getTvAlternativeTitles', 'getTvSeason', 'getTvEpisode', 'findTvByExternalId', 'getTvEpisodeWithFallback', 'lookupTvShowIds', 'getString', 'getStringNative', 'getStringPhp', 'getInt', 'getIntNative', 'getIntPhp', 'getFloat', 'getFloatNative', 'getFloatPhp', 'getBool', 'getBoolNative', 'getBoolPhp', 'getArray', 'getArrayNative', 'getArrayPhp', 'getNested', 'useW464RustFfi', 'usesW464RustFfi', 'resetW464RustFfi', 'summarizeTmdbImagePathBatch', 'summarizeTmdbImagePathBatchNative', 'summarizeTmdbImagePathBatchPhp'],
            TraktService::class => ['isConfigured', 'throwingOnTransientFailure', 'getEpisodeSummary', 'getEpisodeSummaryWithFallback', 'getShowWithAllIds', 'lookupShowIds', 'getBoxOffice', 'getCalendar', 'getMovieSummary', 'getMovieImdbId', 'searchById', 'searchShows', 'getShowSummary', 'getShowSeasons', 'getSeasonEpisodes', 'getTrendingShows', 'getTrendingMovies', 'getPopularShows', 'getPopularMovies', 'clearShowCache', 'clearMovieCache', 'formatImdbIdPhp', 'formatImdbIdNative', 'formatMediaIdPhp', 'formatMediaIdNative', 'traktFfi', 'isUsableExternalIdPhp', 'isUsableExternalIdNative', 'useW464RustFfi', 'usesW464RustFfi', 'resetW464RustFfi', 'summarizeTraktMediaIdBatch', 'summarizeTraktMediaIdBatchNative', 'summarizeTraktMediaIdBatchPhp'],
            TurnstileService::class => ['verify', 'display', 'renderJs', 'enableRustFfi', 'disableRustFfi', 'resetRustFfi', 'usesRustFfi', 'setFfiForTesting', 'failureReason', 'failureReasonPhp', 'failureReasonNative', 'renderWidget', 'renderWidgetPhp', 'renderWidgetNative', 'useW48SiteverifyRustFfi', 'usesW48SiteverifyRustFfi', 'resetW48SiteverifyRustFfi', 'evaluateSiteverify', 'evaluateSiteverifyPhp', 'evaluateSiteverifyNative'],
            ReleaseSearchService::class => ['setUseRustFfi', 'usesRustFfi', 'useW48ReleaseSearchServiceRustFfi', 'usesW48ReleaseSearchServiceRustFfi', 'resetW48ReleaseSearchServiceRustFfi', 'normalizeSearchRequestBatch', 'normalizeSearchRequestBatchPhp', 'normalizeSearchRequestBatchNative', 'evaluateW48Native', 'search', 'apiSearch', 'buildRangeSqlConditions', 'buildRangeSqlConditionsNative', 'buildRangeSqlConditionsPhp', 'apiMusicSearch', 'apiBookSearch', 'tvSearch', 'apiTvSearch', 'animeSearch', 'moviesSearch', 'searchSimilar', 'buildEpisodeJoinPredicates', 'buildEpisodeJoinPredicatesNative', 'buildEpisodeJoinPredicatesPhp', 'normalizeMysqlFallbackPhrase', 'normalizeMysqlFallbackPhraseNative', 'normalizeMysqlFallbackPhrasePhp', 'resolveSizeRangeBounds', 'resolveSizeRangeBoundsNative', 'resolveSizeRangeBoundsPhp', 'showPasswords', 'getBrowseOrder'],
        ] as $class => $methods) {
            $reflection = new ReflectionClass($class);
            foreach ($methods as $name) {
                $this->assertTrue($reflection->hasMethod($name) && $reflection->getMethod($name)->isPublic(), "{$class}::{$name}");
            }
        }

        $earlier = static fn (): array => [
            SteamService::summarizeSteamTitleBatchPhp(['Game.Name.GOTY-CODEX', '']),
            TmdbClient::summarizeTmdbImagePathBatchPhp(['/abc.jpg', 'https://x/y.png', '']),
            TraktService::summarizeTraktMediaIdBatchPhp(['tt0111161', 'breaking-bad', '']),
            TurnstileService::evaluateSiteverifyPhp(['abc'], ['{"success":true}']),
            ReleaseSearchService::normalizeSearchRequestBatchPhp([['The Matrix', 1, 5, 30, 0, 100]]),
        ];
        $expected = $earlier();
        foreach (array_keys(self::METHODS) as $class) {
            foreach ([true, false] as $enabled) {
                $this->assertSame($expected, $class::withW62RustFfi($enabled, $earlier), $class);
            }
        }
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_entry_points_route_by_toggle(string $class, string $method): void
    {
        $this->requireFfi($class);
        $records = self::firstRecords($class);
        $php = $class::{$method.'Php'}($records);
        $this->assertCount(self::WIDTHS[$class] * \count($records), $php);
        $this->assertSame($php, $class::withW62RustFfi(false, static fn () => $class::{$method}($records)), $class);
        $this->assertSame($php, $class::withW62RustFfi(true, static fn () => $class::{$method}($records)), $class);
        $this->assertSame($php, $class::{$method.'Native'}($records), $class);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_empty_batches_return_no_words(string $class, string $method): void
    {
        $this->assertSame([], $class::{$method.'Php'}([]));
        $this->assertSame([], $class::{$method.'Native'}([]));
        $this->assertSame([], $class::{$method}([]));
        // One empty record still yields a full row.
        $this->assertSame($class::{$method.'Php'}(['']), $class::{$method.'Native'}(['']));
        $this->assertCount(self::WIDTHS[$class], $class::{$method}(['']));
    }

    public function test_steam_plan_examples(): void
    {
        $this->requireFfi(SteamService::class);
        $this->assertBoth(SteamService::class, [
            " 292030 \tThe.Witcher.3.Wild.Hunt.Game.of.the.Year.Edition-GOG\tThe Witcher 3: Wild Hunt\tgame",
            '',
            "https://store.steampowered.com/app/570/Dota_2/\tDota 2 REPACK-FitGirl\tdota 2\tdlc",
            "0\tHalf-Life 2 GOTY Remastered\thalf life\tGame\textra",
            "https://store.steampowered.com/app/x\tCaf\xc3\xa9 Racer Deluxe Edition CODEX PLAZA\tcafe racer\t",
        ], [
            292030, 1, 11, 31, 6, 2, 3, 90, 2, 40,
            0, 0, 0, 0, 0, 0, 0, 0, 0, 577,
            570, 2, 4, 6, 2, 0, 23, 100, 2, 284,
            0, 3, 5, 22, 4, 1, 0, 66, 0, 546,
            0, 3, 6, 11, 2, 4, 1, 50, 0, 174,
        ]);
    }

    public function test_tmdb_plan_examples(): void
    {
        $this->requireFfi(TmdbClient::class);
        $this->assertBoth(TmdbClient::class, [
            "find\ttt0111161\timdb_id\t\t\tfr-CA\t/abc.jpg\tw780\t40\t1000\t1004",
            '',
            "SEARCH_MOVIE\t\t\t  The   Matrix \t1999\tde\thttps://x/../y\tw9\t\t\t",
            "tv_episode\t\tbogus\t\t1873\tenglish\tposter path.jpg\tORIGINAL\t39\t1000\t995\textra",
            "search_tv\t12\t\t\t2100\ten_gb\tabc.png\t\tx\t1000\t2000",
        ], [
            3, 111161, 1, 0, 0, 148_053, 1, 7, 0, 6, 0,
            0, 0, 0, 0, 0, 118_539, 0, 6, 1, 0, 4097,
            1, 0, 0, 10, 1999, 83_000, 2, 6, 1, 0, 768,
            7, 0, -1, 0, 0, 118_539, 3, 10, 1, 0, 6612,
            4, 12, 0, 0, 2100, 118_158, 3, 6, 1, 0, 1056,
        ]);
    }

    public function test_trakt_plan_examples(): void
    {
        $this->requireFfi(TraktService::class);
        $this->assertBoth(TraktService::class, [
            "episode\ttt0903747\t\t1\t2\tfull\t500",
            '',
            "SHOW\tBreaking Bad!\t\t\t\tbogus\tx\textra",
            "movie\t603\tTMDB\t\t\tMETADATA\t0",
            "podcast\t12345\timdb\t99999\t\tepisodes\t25",
        ], [
            3, 1, 903747, 0, 1, 2, 2, 100, 128,
            0, 0, 0, 0, -1, -1, 1, 10, 1025,
            2, 5, 0, 12, -1, -1, 1, 10, 1344,
            1, 2, 603, 0, -1, -1, 3, 1, 128,
            -1, 1, 0, 0, -1, -1, 4, 25, 26,
        ]);
    }

    public function test_turnstile_plan_examples(): void
    {
        $this->requireFfi(TurnstileService::class);
        $this->assertBoth(TurnstileService::class, [
            "abc.DEF_1-2\t0x4AAAAAAAB\t203.0.113.7\t123e4567-e89b-12d3-a456-426614174000\t\ttrue\texample.com\tEXAMPLE.com",
            '',
            "tok en\t1x0000000000000000000000000000000AA\t192.168.1.1\tnope\tinternal-error, bogus\tyes\ta\tb",
            "tok\t0xabc\t2001:db8::1\t\tinternal-error\tfalse\t\t",
            "tok\t0xabc\tfd00::192.168.1.300\t\ttimeout-or-duplicate\t1\th\t\textra",
        ], [
            11, 1, 1, 1, 1, 0, 1, 0, 0,
            0, 0, 0, 0, 0, 0, 2, 0, 512,
            6, 3, 2, 1, 2, 192, 3, 0, 249,
            3, 1, 1, 2, 0, 64, 0, 1, 0,
            3, 1, 1, 3, 0, 32, 0, 0, 772,
        ]);
    }

    public function test_release_search_plan_examples(): void
    {
        $this->requireFfi(ReleaseSearchService::class);
        $this->assertBoth(ReleaseSearchService::class, [
            "The.Matrix 1999\t\tposter@example.com\talt.binaries.movies\t2000,2040,2000\t5\t3\t40000\ttt0133093\t603\t\t50\t5000",
            '',
            "x\t\t\tbad group\t-1,abc\t12\t\t0\ttt\t-5\t7\tx\t0",
            "\tsome.usenet.name\t\tA.B.TEEVEE\t5030\t0\t11\t30\t\t\t\t\t",
            "\t\t\tmisc\t\t\t\t\t\t\t\t\t",
        ], [
            3, 15, 0, 18, 1, 2, 524_288_000, 2_097_152_000, 36_500, 133_093, 603, -1, 50, 1000, 5288,
            0, 0, 0, 0, 0, 0, 0, 0, -1, -1, -1, -1, 0, 100, 8193,
            1, 1, 0, 0, 4, -1, 0, 0, -1, -1, -1, 7, 0, 1, 1814,
            0, 0, 3, 0, 2, 1, 0, 67_108_864_000, 30, -1, -1, -1, 0, 100, 0,
            0, 0, 0, 0, 3, 0, 0, 0, -1, -1, -1, -1, 0, 100, 1,
        ]);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_every_helper_is_served_by_rust(string $class, string $method): void
    {
        $this->assertInstanceOf(FFI::class, $this->requireFfi($class));
        $records = self::firstRecords($class);
        $this->assertCount(self::WIDTHS[$class] * \count($records), $class::{$method.'Native'}($records));
        $handle = self::state($class, 'buffer', 1);
        $this->assertIsArray($handle, "{$class} must resolve the Rust helper");
        $this->assertInstanceOf(FFI::class, $handle[0]);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_large_batches_grow_the_output_buffer(string $class, string $method): void
    {
        $this->requireFfi($class);
        $records = [];
        for ($i = 0; $i < 3000; $i++) {
            $records[] = self::firstRecords($class)[$i % 3].$i;
        }
        $this->assertSame($class::{$method.'Php'}($records), $class::{$method.'Native'}($records), $class);
        $handle = self::state($class, 'buffer', 0);
        $this->assertIsArray($handle);
        $this->assertSame(3000 * self::WIDTHS[$class], $handle[2]);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_native_helpers_fall_back_to_php_without_library(string $class, string $method): void
    {
        self::state($class, 'reset', false);
        $records = self::firstRecords($class);
        $this->assertSame($class::{$method.'Php'}($records), $class::{$method.'Native'}($records), $class);
        $this->assertSame($class::{$method.'Php'}($records), $class::withW62RustFfi(true, static fn () => $class::{$method}($records)), $class);
        $this->assertNull(self::state($class, 'buffer', 1), "{$class} must keep the disabled handle");
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_nul_bytes_decline_to_php(string $class, string $method): void
    {
        $this->requireFfi($class);
        $records = self::firstRecords($class);
        $records[1] .= "\0x";
        $this->assertSame($class::{$method.'Php'}($records), $class::{$method.'Native'}($records), $class);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_direct_c_abi_declines_invalid_input_and_reports_needed_capacity(string $class, string $method): void
    {
        $this->assertNotSame('', $method);
        $ffi = $this->requireFfi($class);
        $function = self::abiFunction($class);
        $parts = self::firstRecords($class);
        $data = implode("\0", $parts);
        $count = \count($parts);
        $needed = 3 * self::WIDTHS[$class];
        $out = $ffi->new('int64_t[64]');
        $small = $ffi->new('int64_t[1]');
        $small[0] = 7;
        $call = static fn (int $count, int $flags, string $data, string $ints, mixed $out, int $cap): int => $ffi->{$function}($count, $flags, $data, \strlen($data), $ints, \strlen($ints), $out, $cap);

        $this->assertSame($needed, $call($count, 0, $data, '', $out, 64), $function);
        $this->assertSame(-$needed, $call($count, 0, $data, '', $small, 1), $function);
        $this->assertSame(7, $small[0], "{$function} must not write on a capacity error");
        $this->assertSame(-$needed, $call($count, 0, $data, '', null, 0), $function);
        $this->assertSame(-1, $call($count, 0, $data, '', null, 4), $function);
        $this->assertSame(-1, $call($count, 1, $data, '', $out, 64), "{$function} flags");
        $this->assertSame(-1, $call($count + 1, 0, $data, '', $out, 64), "{$function} record count");
        $this->assertSame(-1, $call($count - 1, 0, $data, '', $out, 64), "{$function} record count");
        $this->assertSame(-1, $call(0, 0, $data, '', $out, 64), "{$function} record count");
        $this->assertSame(-1, $call($count, 0, $data, pack('P', 1), $out, 64), "{$function} int table");
        $this->assertSame(0, $call(0, 0, '', '', null, 0), "{$function} empty batch");
    }

    /**
     * Over 400 rounds of 1-16 records each (thousands of structured, fragment-spliced and raw-byte inputs
     * per helper), every class's Rust and PHP plans agree bit for bit; every 50th round also carries NUL
     * bytes, which must decline to PHP.
     *
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_randomized_parity(string $class, string $method): void
    {
        $this->assertNotSame('', $method);
        $this->requireFfi($class);
        mt_srand(6251 + crc32($class) % 1000);
        $checked = 0;
        for ($round = 0; $round < self::RANDOM_ROUNDS; $round++) {
            $withNul = $round % 50 === 49;
            $records = [];
            for ($i = 0, $count = mt_rand(1, 16); $i < $count; $i++) {
                $records[] = match (mt_rand(0, 5)) {
                    0 => self::randomBytes(24, $withNul),
                    1 => self::spliced(self::structuredRecord($class), $withNul),
                    default => self::structuredRecord($class),
                };
            }
            if ($withNul) {
                $records[0] .= "\0";
            }
            $this->assertBoth($class, $records, null);
            $checked += \count($records);
        }
        $this->assertGreaterThanOrEqual(300, $checked);
    }

    public function test_paired_randomized_benchmark_gates_rust_default(): void
    {
        if (getenv('NNTMUX_W625_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_W625_BENCH=1 to run the Wave 62.5 paired benchmark.');
        }
        $this->requireFfi(SteamService::class);
        mt_srand(6252);
        $workloads = [];
        $floors = [];

        foreach (['common' => 320, 'uncommon' => 384] as $name => $count) {
            $odd = $name === 'uncommon';
            $steam = $tmdb = $trakt = $turnstile = $search = [];
            for ($i = 0; $i < $count; $i++) {
                $now = 1_700_000_000 + $i * 37;

                $steam[] = $odd
                    ? match ($i % 5) {
                        0 => " https://store.steampowered.com/app/{$i}0/Some_Game/ \tThe.Elder.Scrolls.V.Skyrim.Special.Edition.Anniversary.Edition.MULTI.REPACK-FitGirl\tThe Elder Scrolls V: Skyrim Special Edition\tGAME",
                        1 => '',
                        2 => "x{$i}\tCaf\xc3\xa9 \xc3\x89t\xc3\xa9 Simulator -- Directors Cut [DODI] (v1.0) PROPER\tcaf\xc3\xa9 \xc3\xa9t\xc3\xa9 simulator\tdlc\textra",
                        3 => "{$i}\t".str_repeat('Call of Duty Black Ops ', 5).'HOTFIX-CODEX'."\t".str_repeat('call duty ops black ', 4)."\tdemo",
                        default => "0\t{$i}\t{$i}\t",
                    }
                : (100 + $i)."\t".['Half-Life.2-CODEX', 'Portal 2 GOTY-PLAZA', 'Hades.v1.38-GOG', 'Dark.Souls.III.Deluxe.Edition-SKIDROW'][$i % 4]."\t".['Half-Life 2', 'Portal 2', 'Hades', 'Dark Souls III'][$i % 4]."\tgame";

                $tmdb[] = $odd
                    ? match ($i % 5) {
                        0 => " FIND \t tt00{$i}99 \tIMDB_ID\t\t0999\tpt_BR\t//image.tmdb.org/../t/p/original.jpg\tH632\t999\t".($now + 5)."\t{$now}",
                        1 => '',
                        2 => "tv_episode\t\tbogus\t\xc3\xa9t\xc3\xa9\t20x0\tzh-Hans\thttps://image.tmdb.org/t/p/w500/a b.jpg\tw999\t-1\t\t\textra",
                        3 => "search_tv\t\t\t".str_repeat("  breaking \t bad ", 6)."\t2008\tES\t".str_repeat('/abc', 10).'.png'."\toriginal\t40\t".($now - 3)."\t{$now}",
                        default => "{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}",
                    }
                : ['movie', 'tv', 'search_movie', 'find'][$i % 4]."\t".($i % 4 === 3 ? 'tt'.str_pad((string) (100000 + $i * 17), 7, '0', STR_PAD_LEFT) : ($i % 4 === 2 ? '' : (string) (550 + $i)))."\t".($i % 4 === 3 ? 'imdb_id' : '')."\t".($i % 4 === 2 ? ['The Matrix', 'Inception', 'Fight Club', 'Heat'][$i % 4] : '')."\t".($i % 4 === 2 ? (string) (1990 + $i % 30) : '')."\ten-US\t/".substr(md5((string) $i), 0, 12).".jpg\tw500\t".($i % 45)."\t".($now - $i % 12)."\t{$now}";

                $trakt[] = $odd
                    ? match ($i % 5) {
                        0 => " EPISODE \t TT{$i}0903747 \t\t99999\t\tFULL\t999999",
                        1 => '',
                        2 => "list\tCaf\xc3\xa9 -- \xc3\x89t\xc3\xa9 Show!!\tSLUG\tx\t-1\tbogus\t-5\textra",
                        3 => "show\t".str_repeat('The Long Show Name ', 5)."\t\t\t\tmetadata\t0",
                        default => "{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}",
                    }
                : ['show', 'movie', 'episode', 'show'][$i % 4]."\t".['breaking-bad', 'tt0133093', (string) (1388 + $i), 'game-of-thrones'][$i % 4]."\t".['', 'imdb', 'trakt', 'slug'][$i % 4]."\t".($i % 4 === 2 ? (string) ($i % 9) : '')."\t".($i % 4 === 2 ? (string) ($i % 20 + 1) : '')."\t".['full', 'min', 'full', 'episodes'][$i % 4]."\t".['', '10', '25', '50'][$i % 4];

                $turnstile[] = $odd
                    ? match ($i % 5) {
                        0 => ' '.str_repeat('0.AbCdEf_-', 210)." \t1x0000000000000000000000000000000AA\t ::FFFF:10.0.0.1 \tNOT-A-UUID\tinternal-error,timeout-or-duplicate , ,bogus\tTRUE\tExample.com\texample.org",
                        1 => '',
                        2 => "tok\xc3\xa9n\t0x".str_repeat('A', 70)."\t2001:0db8:85a3:0000:0000:8a2e:0370:7334:1\t\tbad-request\tmaybe\t\t\textra",
                        3 => "0.abc\t2x0000000000000000000000000000000AA\t172.16.254.1\t123E4567-E89B-12D3-A456-426614174000\t\t1\th\th",
                        default => "{$i}\t{$i}\t{$i}.{$i}.{$i}.{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}",
                    }
                : '0.'.substr(str_repeat(md5((string) $i), 8), 0, 200 + $i % 100)."\t0x4AAAAAAA".strtoupper(substr(md5((string) ($i * 7)), 0, 24))."\t".['203.0.113.'.($i % 250), '2001:db8::'.dechex($i), '198.51.100.7', ''][$i % 4]."\t".($i % 2 === 0 ? sprintf('%08x-0000-4000-8000-%012x', $i, $i * 13) : '')."\t".($i % 7 === 0 ? 'timeout-or-duplicate' : '')."\t".($i % 7 === 0 ? 'false' : 'true')."\texample.com\texample.com";

                $search[] = $odd
                    ? match ($i % 5) {
                        0 => " The.Lord.of.the.Rings.The.Return.of.the.King.2003.EXTENDED.2160p.UHD.BluRay.x265-TERMiNAL \tlotr.rotk.extended.part01.rar\t Poster <poster@example.com> \tALT.BINARIES.HDTV.X264\t2040,2040,-1, 2050 ,abc,99999\t11\t1\t999999\tTT0167260\t122\t\t999999999\t999999",
                        1 => '',
                        2 => "caf\xc3\xa9 \xc3\xa9t\xc3\xa9\t\t\tbad group!\t,,,\t12\t-1\t-1\ttt\t0\tx\t-1\t-1\textra",
                        3 => str_repeat('word ', 30)."\t".str_repeat('usenet.name.', 10)."\t\ta.b.teevee\t5030,5040,5045,5050,5060,5070,5080\t1\t2\t0\t\t\t\t\t",
                        default => "{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}",
                    }
                : ['The Matrix 1999', 'breaking bad s01e01', 'ubuntu 24.04 iso', 'Dune Part Two 2160p'][$i % 4]."\t\t\t".['alt.binaries.movies', 'alt.binaries.teevee', '', 'alt.binaries.hdtv'][$i % 4]."\t".['2040', '5030,5040', '', '2045'][$i % 4]."\t".($i % 3 === 0 ? '3' : '')."\t".($i % 3 === 0 ? '8' : '')."\t".['', '30', '365', '7'][$i % 4]."\t".($i % 4 === 0 ? 'tt0133093' : '')."\t".($i % 4 === 3 ? '693134' : '')."\t".($i % 4 === 1 ? '81189' : '')."\t".(($i % 5) * 50)."\t".['', '50', '100', '1000'][$i % 4];
            }

            $workloads[SteamService::class][$name] = static fn (bool $rust) => $rust ? SteamService::lookupPlanBatchNative($steam) : SteamService::lookupPlanBatchPhp($steam);
            $workloads[TmdbClient::class][$name] = static fn (bool $rust) => $rust ? TmdbClient::requestPlanBatchNative($tmdb) : TmdbClient::requestPlanBatchPhp($tmdb);
            $workloads[TraktService::class][$name] = static fn (bool $rust) => $rust ? TraktService::lookupPlanBatchNative($trakt) : TraktService::lookupPlanBatchPhp($trakt);
            $workloads[TurnstileService::class][$name] = static fn (bool $rust) => $rust ? TurnstileService::verificationPlanBatchNative($turnstile) : TurnstileService::verificationPlanBatchPhp($turnstile);
            $workloads[ReleaseSearchService::class][$name] = static fn (bool $rust) => $rust ? ReleaseSearchService::searchPlanBatchNative($search) : ReleaseSearchService::searchPlanBatchPhp($search);
        }

        foreach ($workloads as $class => $byName) {
            $results = [];
            foreach ($byName as $name => $workload) {
                $this->assertSame($workload(false), $workload(true), "{$class} {$name} benchmark workload must agree");
                for ($w = 0; $w < 20; $w++) {
                    $workload(false);
                    $workload(true);
                }

                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < self::BENCH_SAMPLES; $s++) {
                    foreach (mt_rand(0, 1) === 1 ? [true, false] : [false, true] as $rust) {
                        $best = PHP_INT_MAX;
                        for ($r = 0; $r < self::REPEATS; $r++) {
                            $t0 = hrtime(true);
                            $workload($rust);
                            $best = min($best, hrtime(true) - $t0);
                        }
                        $times[$rust ? 'rust' : 'php'][] = $best / 1000;
                    }
                }

                $results[$name] = [
                    'mean' => self::mean($times['php']) / max(1e-9, self::mean($times['rust'])),
                    'p99' => self::p99($times['php']) / max(1e-9, self::p99($times['rust'])),
                    'php_mean_us' => self::mean($times['php']),
                    'rust_mean_us' => self::mean($times['rust']),
                ];
            }

            fwrite(STDERR, \sprintf(
                "\n[Wave 62.5 benchmark] %s: common mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx | uncommon mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx",
                (new ReflectionClass($class))->getShortName(),
                $results['common']['mean'],
                $results['common']['php_mean_us'],
                $results['common']['rust_mean_us'],
                $results['common']['p99'],
                $results['uncommon']['mean'],
                $results['uncommon']['php_mean_us'],
                $results['uncommon']['rust_mean_us'],
                $results['uncommon']['p99'],
            ));

            $floors[$class] = min($results['common']['mean'], $results['common']['p99'], $results['uncommon']['mean'], $results['uncommon']['p99']);
        }
        fwrite(STDERR, "\n");

        // Every helper is reported before the gate is asserted; Rust defaults must still clear 1.5x.
        foreach ($floors as $class => $floor) {
            $this->assertSame(self::DEFAULTS[$class], $class::usesW62RustFfi(), $class);
            $this->assertSame(min(self::QUALIFICATION[$class]) >= 1.5, $class::usesW62RustFfi(), $class);
            if ($class::usesW62RustFfi()) {
                $this->assertGreaterThanOrEqual(1.5, $floor, \sprintf('%s: a Rust default must clear the 1.5x gate (min ratio %.2fx)', $class, $floor));
            } else {
                $this->assertFalse($class::usesW62RustFfi(), "{$class} retains PHP by default");
            }
        }
    }

    /**
     * Asserts that the PHP and native variants (and both toggled entry points) agree, and equal $expected
     * unless it is null.
     *
     * @param  class-string  $class
     * @param  list<string>  $records
     * @param  list<int>|null  $expected
     */
    private function assertBoth(string $class, array $records, ?array $expected): void
    {
        $method = self::METHODS[$class];
        $php = $class::{$method.'Php'}($records);
        if ($expected !== null) {
            $this->assertSame($expected, $php, "{$class} PHP");
        }
        $this->assertSame($php, $class::{$method.'Native'}($records), "{$class} native ".var_export($records, true));
        $this->assertSame($php, $class::withW62RustFfi(true, static fn () => $class::{$method}($records)), "{$class} Rust route");
        $this->assertSame($php, $class::withW62RustFfi(false, static fn () => $class::{$method}($records)), "{$class} PHP route");
    }

    /**
     * Three representative records per class.
     *
     * @param  class-string  $class
     * @return list<string>
     */
    private static function firstRecords(string $class): array
    {
        return match ($class) {
            SteamService::class => ["292030\tThe Witcher 3 GOTY-GOG\tThe Witcher 3\tgame", "x\t--\t", ''],
            TmdbClient::class => ["movie\t603\t\t\t1999\ten-US\t/abc.jpg\tw500\t3\t1700000000\t1700000004", "bogus\tx\tbogus", ''],
            TraktService::class => ["show\tbreaking-bad\tslug\t1\t1\tfull\t10", "bogus\t\tx", ''],
            TurnstileService::class => ["abc\t0xabc\t203.0.113.7\t\t\ttrue\texample.com\texample.com", "tok en\tx\t999.1.1.1", ''],
            ReleaseSearchService::class => ["The Matrix\t\t\talt.binaries.movies\t2040\t1\t5\t30\ttt0133093\t603\t\t0\t100", "\t\t\tbad group\tx\t99", ''],
            default => throw new RuntimeException($class),
        };
    }

    /**
     * One record shaped like the class's input, built from plausible and adversarial pieces.
     *
     * @param  class-string  $class
     */
    private static function structuredRecord(string $class): string
    {
        $pick = static fn (array $items): string => (string) $items[mt_rand(0, \count($items) - 1)];
        $join = static function (string $separator, int $max, callable $part): string {
            $parts = [];
            for ($i = 0, $n = mt_rand(0, $max); $i < $n; $i++) {
                $parts[] = $part();
            }

            return implode($separator, $parts);
        };
        $number = static fn (): string => $pick(['', ' ', '0', '1', '2', '3', '4', '5', '9', '10', '11', '12', '00', '007', '-1', '-0', '+1', '1.5', ' 5', '5 ', 'x', '39', '40', '41', '99', '100', '101', '255', '256', '999', '1000', '1001', '1873', '1874', '1999', '2100', '2101', '9999', '36500', '36501', '999999', '1000000', (string) mt_rand(0, 1_000_000_000), (string) mt_rand(1_699_999_990, 1_700_000_010), '999999999999', '1000000000000', '999999999999999999', '1234567890123456789', self::randomBytes(3)]);
        $cut = static fn (array $fields, int $expected): array => \array_slice($fields, 0, mt_rand(0, 3) === 0 ? mt_rand(0, $expected + 1) : $expected);
        $words = static fn (): string => $pick(['', ' ', 'The', 'Witcher', '3', 'Half-Life', 'GOTY', 'Game of the Year', 'game OF the year', 'Deluxe Edition', 'Edition', 'Directors Cut', 'HD Remaster', 'Remastered', 'CODEX', 'codex', 'FitGirl', 'I_KNOW', 'REPACK', 'Multi', 'v1.0', '[DODI]', 'The Matrix', 'Breaking Bad', "Caf\xc3\xa9", "\xc3\x89t\xc3\xa9", 'a.b', "a\x0cb", "a\x0bb", '  ', self::randomBytes(10)]);
        $mixed = static fn (): string => $join($pick([' ', '.', '-', '_']), 6, $words);
        $ip = static fn (): string => $pick(['', ' ', '203.0.113.7', '10.0.0.1', '127.0.0.1', '169.254.1.1', '172.15.0.1', '172.16.0.1', '172.31.255.255', '172.32.0.1', '192.168.0.1', '192.169.0.1', '1.2.3', '1.2.3.4.5', '01.2.3.4', '0.0.0.0', '256.1.1.1', '255.255.255.255', '::1', '::', ':', '2001:db8::1', '2001:DB8::1', '2001:db8:::1', '2001::db8::1', 'fe80::1', 'FD00::1', 'fc00::', '::ffff:10.0.0.1', '::ffff:10.0.0', '1:2:3:4:5:6:7:8', '1:2:3:4:5:6:7:8:9', '12345::1', 'g::1', '1.2.3.4:80', self::randomBytes(8)]);

        return match ($class) {
            SteamService::class => implode("\t", $cut([
                $pick(['', ' ', '0', '1', '292030', ' 570 ', '00570', '12345678901', '9999999999', '-1', 'x1', 'https://store.steampowered.com/app/570', 'https://store.steampowered.com/app/570/Dota_2/', 'https://store.steampowered.com/app/', 'https://store.steampowered.com/app/x/', 'HTTPS://store.steampowered.com/app/570', 'http://store.steampowered.com/app/570', self::randomBytes(6)]),
                $mixed(), $mixed(),
                $pick(['', ' ', 'game', 'Game', 'GAME', 'dlc', 'demo', 'mod', 'video', self::randomBytes(4)]),
                'extra',
            ], 4)),
            TmdbClient::class => implode("\t", $cut([
                $pick(['', ' ', 'search_movie', 'SEARCH_MOVIE', 'movie', 'find', 'search_tv', 'tv', 'tv_season', 'tv_episode', 'credits', 'external_ids', 'alternative_titles', 'person', self::randomBytes(5)]),
                $pick(['', ' ', '0', '1', '603', 'tt0111161', 'TT0111161', 'tt012345', 'tt01234', 'tt12345678901', 'nm0000001', '12345678901', '-5', self::randomBytes(6)]),
                $pick(['', ' ', 'imdb_id', 'IMDB_ID', 'tvdb_id', 'tmdb', 'facebook_id', self::randomBytes(4)]),
                $mixed(),
                $number(),
                $pick(['', ' ', 'en', 'EN', 'en-US', 'en_us', 'pt-BR', 'zh-Hans', 'e1', 'eng', 'en-', 'en-U', "\xc3\xa9n", self::randomBytes(5)]),
                $pick(['', ' ', '/abc.jpg', 'abc.jpg', 'https://image.tmdb.org/t/p/w500/a.jpg', 'http://x/y.png', 'HTTPS://x', '/a/../b.jpg', '/a b.jpg', "/a\x7fb", '//double.jpg', '/', self::randomBytes(8)]),
                $pick(['', ' ', 'w92', 'W92', 'w154', 'w185', 'w300', 'w342', 'w500', 'w780', 'w1280', 'h632', 'original', 'ORIGINAL', 'w999', self::randomBytes(4)]),
                $number(), $number(), $number(),
                'extra',
            ], 11)),
            TraktService::class => implode("\t", $cut([
                $pick(['', ' ', 'movie', 'MOVIE', 'show', 'episode', 'person', 'list', 'podcast', self::randomBytes(4)]),
                $pick(['', ' ', 'tt0903747', 'TT0903747', 'tt12345', 'tt1234567890123456789', 'x123456y', '1388', '0', '-1', 'breaking-bad', 'Breaking Bad!', '--', "caf\xc3\xa9-show", '1234567890123456789', self::randomBytes(10)]),
                $pick(['', ' ', 'imdb', 'IMDB', 'tmdb', 'trakt', 'tvdb', 'slug', 'SLUG', 'tvrage', self::randomBytes(4)]),
                $number(), $number(),
                $pick(['', ' ', 'min', 'full', 'FULL', 'metadata', 'episodes', 'images', self::randomBytes(4)]),
                $number(),
                'extra',
            ], 7)),
            TurnstileService::class => implode("\t", $cut([
                $pick(['', ' ', 'abc', '0.abc_DEF-1', 'tok en', "tok\xc3\xa9n", str_repeat('a', 2048), str_repeat('a', 2049), 'a+b', 'a/b', self::randomBytes(12)]),
                $pick(['', ' ', '0x', '0xabc', '0xAB_c-D', '0x'.str_repeat('A', 64), '0x'.str_repeat('A', 65), '0Xabc', '1x0000000000000000000000000000000AA', '2x0000000000000000000000000000000AA', '3x0000000000000000000000000000000AA', '4x0000000000000000000000000000000AA', '0xab cd', self::randomBytes(6)]),
                $ip(),
                $pick(['', ' ', '123e4567-e89b-12d3-a456-426614174000', '123E4567-E89B-12D3-A456-426614174000', '123e4567e89b12d3a456426614174000', '123e4567-e89b-12d3-a456-42661417400g', 'nope', self::randomBytes(6)]),
                $join(',', 4, static fn (): string => $pick(['', ' ', 'missing-input-secret', 'invalid-input-secret', 'missing-input-response', 'invalid-input-response', 'bad-request', 'timeout-or-duplicate', 'internal-error', ' internal-error ', 'INTERNAL-ERROR', 'bogus'])),
                $pick(['', ' ', '1', '0', 'true', 'TRUE', 'false', 'False', 'yes', '2', self::randomBytes(3)]),
                $pick(['', ' ', 'example.com', 'EXAMPLE.com', 'other.org', self::randomBytes(4)]),
                $pick(['', ' ', 'example.com', 'Example.COM', 'other.org', self::randomBytes(4)]),
                'extra',
            ], 8)),
            ReleaseSearchService::class => implode("\t", $cut([
                $mixed(), $mixed(),
                $pick(['', ' ', 'poster', 'poster@example.com', 'Poster <p@x>', "caf\xc3\xa9", self::randomBytes(6)]),
                $pick(['', ' ', 'alt.binaries.movies', 'ALT.BINARIES.HDTV', 'alt.binaries.', 'alt.binaries', 'a.b.teevee', 'A.B.', 'misc', 'bad group', 'x+y_z-1', "caf\xc3\xa9", self::randomBytes(5)]),
                $join(',', 5, static fn (): string => $pick(['', ' ', '-1', ' -1 ', '0', '2040', '5030', '5030', '0010', '99999', 'abc', '-2', self::randomBytes(2)])),
                $number(), $number(), $number(),
                $pick(['', ' ', 'tt0133093', 'TT0133093', 'tt', '0133093', 'tt0', 'tt12345678901', '-1', self::randomBytes(4)]),
                $number(), $number(), $number(), $number(),
                'extra',
            ], 13)),
            default => throw new RuntimeException($class),
        };
    }

    /** Splices random fragments and bytes into $record at random offsets. */
    private static function spliced(string $record, bool $withNul): string
    {
        for ($i = 0, $n = mt_rand(1, 4); $i < $n; $i++) {
            $insert = mt_rand(0, 1) === 1 ? self::FRAGMENTS[mt_rand(0, \count(self::FRAGMENTS) - 1)] : self::randomBytes(4, $withNul);
            $at = mt_rand(0, \strlen($record));
            $record = substr($record, 0, $at).$insert.substr($record, $at);
        }

        return $record;
    }

    /**
     * The C entry point of $class, called by name through the FFI handle.
     *
     * @param  class-string  $class
     */
    private static function abiFunction(string $class): string
    {
        return self::FUNCTIONS[$class][1];
    }

    /**
     * Invokes the class's private `w62RustFfiState()`.
     *
     * @param  class-string  $class
     */
    private static function state(string $class, ?string $op, mixed $val): mixed
    {
        return (new ReflectionMethod($class, 'w62RustFfiState'))->invoke(null, $op, $val);
    }

    private static function randomBytes(int $maxLength, bool $withNul = false): string
    {
        $value = '';
        for ($i = 0, $length = mt_rand(0, $maxLength); $i < $length; $i++) {
            $byte = self::BYTES[mt_rand(0, \count(self::BYTES) - 1)];
            $value .= $byte === "\0" && ! $withNul ? ' ' : $byte;
        }

        return $value;
    }

    /**
     * @param  list<float>  $values
     */
    private static function mean(array $values): float
    {
        return array_sum($values) / max(1, \count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private static function p99(array $values): float
    {
        sort($values);

        return $values[(int) min(\count($values) - 1, (int) ceil(0.99 * \count($values)) - 1)];
    }

    /**
     * Resolves the class's Wave 62.5 FFI handle or skips the test without the library.
     *
     * @param  class-string  $class
     */
    private function requireFfi(string $class): FFI
    {
        if (! \extension_loaded('FFI')) {
            $this->markTestSkipped('FFI extension not loaded.');
        }
        $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $cdef = (new ReflectionClassConstant($class, self::FUNCTIONS[$class][0]))->getValue();
        $ffi = \is_string($cdef) ? RustPipelineLibrary::resolveDomain('w62_5', $cdef, \is_string($path) && $path !== '' ? $path : null) : null;
        if (! $ffi instanceof FFI) {
            $this->markTestSkipped('Rust pipeline library not built.');
        }

        return $ffi;
    }
}
