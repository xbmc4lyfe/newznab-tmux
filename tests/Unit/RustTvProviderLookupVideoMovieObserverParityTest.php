<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\LookupMode;
use App\Exceptions\TvProviderException;
use App\Facades\Search;
use App\Models\MovieInfo;
use App\Models\Video;
use App\Observers\MovieInfoObserver;
use App\Observers\VideoObserver;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Parity, C-ABI contract, fallback, and paired benchmark verification for Wave 20 Slice 20.5:
 * TvProviderException, LookupMode, Video, MovieInfoObserver, and VideoObserver.
 *
 * Benchmarks run only with NNTMUX_TV_PROVIDER_LOOKUP_VIDEO_OBSERVERS_BENCH=1:
 *   cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release
 *   NNTMUX_TV_PROVIDER_LOOKUP_VIDEO_OBSERVERS_BENCH=1 php artisan test --compact tests/Unit/RustTvProviderLookupVideoMovieObserverParityTest.php
 *
 * Paired randomized benchmark evidence (3 repetitions x 41 paired samples x 200 inner loops,
 * worst-repetition PHP latency / Rust latency):
 *
 * | Method                                     | Common (Mean / P99) | Uncommon (Mean / P99) | >= 1.5x | Default      |
 * |--------------------------------------------|---------------------|-----------------------|---------|--------------|
 * | TvProviderException::isTransientStatus     | 0.33x / 0.28x       | 0.34x / 0.30x         | No      | PHP (false)  |
 * | TvProviderException::parseRetryAfter       | 0.31x / 0.28x       | 2.65x / 1.89x         | No      | PHP (false)  |
 * | LookupMode::label                          | 0.26x / 0.27x       | 0.27x / 0.30x         | No      | PHP (false)  |
 * | Video::buildSeriesListCacheKey             | 0.52x / 0.48x       | 0.60x / 0.58x         | No      | PHP (false)  |
 * | Video::buildSeriesLetterFilter             | 0.14x / 0.11x       | 0.10x / 0.09x         | No      | PHP (false)  |
 * | Video::buildSeriesShownameFilter           | 0.13x / 0.14x       | 1.60x / 1.60x         | No      | PHP (false)  |
 * | MovieInfoObserver::buildSearchDocument     | 0.77x / 0.76x       | 0.77x / 0.76x         | No      | PHP (false)  |
 * | VideoObserver::buildSearchDocument         | 0.73x / 0.67x       | 0.78x / 0.79x         | No      | PHP (false)  |
 */
class RustTvProviderLookupVideoMovieObserverParityTest extends TestCase
{
    /** @var list<class-string> */
    private const array TOGGLED_CLASSES = [
        TvProviderException::class,
        LookupMode::class,
        Video::class,
        MovieInfoObserver::class,
        VideoObserver::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetFfiState();
        if (RustPipelineLibrary::resolveDomain('metadata', TvProviderException::FFI_CDEF) === null) {
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
            $class::setUseRustFfi(null);
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
        }
    }

    // ---------------------------------------------------------------------
    // 1. TvProviderException
    // ---------------------------------------------------------------------

    public function test_tv_provider_exception_is_transient_status_parity(): void
    {
        $statuses = [
            -1,
            0,
            100,
            200,
            204,
            301,
            302,
            400,
            401,
            403,
            404,
            408,
            422,
            428,
            429,
            430,
            499,
            500,
            501,
            502,
            503,
            504,
            599,
            999,
        ];

        foreach ($statuses as $status) {
            $expected = TvProviderException::isTransientStatusPhp($status);
            $this->assertSame(
                $expected,
                TvProviderException::isTransientStatusNative($status),
                "status={$status}"
            );
            foreach ([true, false, null] as $toggle) {
                TvProviderException::setUseRustFfi($toggle);
                $this->assertSame($expected, TvProviderException::isTransientStatus($status));
            }
        }
    }

    #[DataProvider('retryAfterProvider')]
    public function test_tv_provider_exception_parse_retry_after_parity(?string $header, int $now): void
    {
        $expected = TvProviderException::parseRetryAfterPhp($header, $now);
        $this->assertSame(
            $expected,
            TvProviderException::parseRetryAfterNative($header, $now),
            'header='.var_export($header, true)." now={$now}"
        );

        foreach ([true, false, null] as $toggle) {
            TvProviderException::setUseRustFfi($toggle);
            $this->assertSame($expected, TvProviderException::parseRetryAfter($header, $now));
        }
    }

    /**
     * @return array<string, array{0: ?string, 1: int}>
     */
    public static function retryAfterProvider(): array
    {
        return [
            'null header' => [null, 1_700_000_000],
            'empty header' => ['', 1_700_000_000],
            'whitespace only' => [" \t\r\n ", 1_700_000_000],
            'zero seconds' => ['0', 1_700_000_000],
            'positive seconds' => ['30', 1_700_000_000],
            'padded positive seconds' => ['  120  ', 1_700_000_000],
            'plus sign seconds' => ['+45', 1_700_000_000],
            'negative seconds clamps to zero' => ['-15', 1_700_000_000],
            'decimal numeric string declines cleanly to php' => ['12.7', 1_700_000_000],
            'scientific numeric string declines cleanly to php' => ['1e2', 1_700_000_000],
            'http date in future' => ['Sun, 06 Nov 1994 08:49:37 GMT', 784_111_700],
            'http date in past clamps to zero' => ['Sun, 06 Nov 1994 08:49:37 GMT', 784_112_000],
            'http date exact now' => ['Sun, 06 Nov 1994 08:49:37 GMT', 784_111_777],
            'leap year feb 29 http date' => ['Thu, 29 Feb 2024 12:00:00 GMT', 1_709_207_900],
            'mismatched weekday http date declines to php' => ['Mon, 06 Nov 1994 08:49:37 GMT', 784_111_700],
            'iso date string declines to php' => ['2026-01-15T12:00:00Z', 1_768_478_300],
            'invalid date string' => ['not-a-valid-date-at-all', 1_700_000_000],
            'nul byte in header' => ["30\0extra", 1_700_000_000],
        ];
    }

    // ---------------------------------------------------------------------
    // 2. LookupMode
    // ---------------------------------------------------------------------

    public function test_lookup_mode_label_parity(): void
    {
        $expectedMap = [
            0 => 'Disabled',
            1 => 'All releases',
            2 => 'Renamed releases only',
        ];

        foreach (LookupMode::cases() as $mode) {
            $expected = $mode->labelPhp();
            $this->assertSame($expectedMap[$mode->value], $expected);
            $this->assertSame($expected, $mode->labelNative());

            foreach ([true, false, null] as $toggle) {
                LookupMode::setUseRustFfi($toggle);
                $this->assertSame($expected, $mode->label());
            }
        }
    }

    // ---------------------------------------------------------------------
    // 3. Video
    // ---------------------------------------------------------------------

    /**
     * @param  array<int|string, mixed>  $rawHitIds
     */
    #[DataProvider('videoSeriesInputsProvider')]
    public function test_video_series_cache_key_and_filters_parity(
        mixed $uid,
        string $letter,
        string $showname,
        bool $searchAvailable,
        array $rawHitIds,
    ): void {
        $expectedKey = Video::buildSeriesListCacheKeyPhp($uid, $letter, $showname);
        $this->assertSame(
            $expectedKey,
            Video::buildSeriesListCacheKeyNative($uid, $letter, $showname),
        );

        $expectedLetter = Video::buildSeriesLetterFilterPhp($letter);
        $this->assertSame(
            $expectedLetter,
            Video::buildSeriesLetterFilterNative($letter),
        );

        $expectedShowname = Video::buildSeriesShownameFilterPhp($showname, $searchAvailable, $rawHitIds);
        $this->assertSame(
            $expectedShowname,
            Video::buildSeriesShownameFilterNative($showname, $searchAvailable, $rawHitIds),
        );

        foreach ([true, false, null] as $toggle) {
            Video::setUseRustFfi($toggle);
            $this->assertSame($expectedKey, Video::buildSeriesListCacheKey($uid, $letter, $showname));
            $this->assertSame($expectedLetter, Video::buildSeriesLetterFilter($letter));
            $this->assertSame($expectedShowname, Video::buildSeriesShownameFilter($showname, $searchAvailable, $rawHitIds));
        }
    }

    /**
     * @return array<string, array{0: mixed, 1: string, 2: string, 3: bool, 4: array<int|string, mixed>}>
     */
    public static function videoSeriesInputsProvider(): array
    {
        return [
            'default empty filters' => [42, '', '', false, []],
            'numeric range 0-9 with search hits' => ['42', '0-9', 'Severance', true, [10, '25', 0, '0', 99]],
            'bracket numeric range [0-9] with empty search hits' => [7, '[0-9]', 'Nonexistent Show', true, []],
            'letter A with search unavailable' => [1001, 'A', 'Breaking Bad', false, []],
            'lowercase letter with zero-only search hits' => [0, 'z', 'ZeroHits', true, [0, '0', null, false]],
            'utf8 and special characters' => [99, 'É', "Mr. Robot's Café", false, []],
            'nul bytes in strings' => ["42\0id", "A\0B", "Show\0Name", false, []],
        ];
    }

    // ---------------------------------------------------------------------
    // 4. MovieInfoObserver
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     */
    #[DataProvider('movieInfoProvider')]
    public function test_movie_info_observer_search_document_and_sync_parity(array $attributes): void
    {
        $movie = new MovieInfo;
        $movie->setRawAttributes($attributes, true);

        $expected = MovieInfoObserver::buildSearchDocumentPhp($movie);
        $this->assertSame(
            $expected,
            MovieInfoObserver::buildSearchDocumentNative($movie),
        );
        $this->assertSame(
            MovieInfoObserver::normalizeSearchDocumentPhp($attributes),
            MovieInfoObserver::normalizeSearchDocumentNative($attributes),
        );

        foreach ([false, true] as $useRust) {
            MovieInfoObserver::setUseRustFfi($useRust);
            $this->assertSame($expected, MovieInfoObserver::buildSearchDocument($movie));

            Search::shouldReceive('insertMovie')
                ->once()
                ->with($expected);

            (new MovieInfoObserver)->created($movie);
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function movieInfoProvider(): array
    {
        return [
            'populated movie record' => [[
                'id' => 101,
                'imdbid' => '0133093',
                'tmdbid' => 603,
                'traktid' => 481,
                'title' => 'The Matrix',
                'year' => '1999',
                'genre' => 'Action, Sci-Fi',
                'actors' => 'Keanu Reeves, Laurence Fishburne',
                'director' => 'Lana Wachowski, Lilly Wachowski',
                'rating' => '8.7',
                'plot' => 'A computer hacker learns about the true nature of reality.',
            ]],
            'sparse movie with nulls and missing fields' => [[
                'id' => 202,
                'imdbid' => null,
                'tmdbid' => null,
                'title' => 'Untitled Film',
            ]],
            'completely empty attributes' => [[]],
            'utf8 and special punctuation in fields' => [[
                'id' => 303,
                'imdbid' => '0211915',
                'tmdbid' => 194,
                'traktid' => 129,
                'title' => 'Le Fabuleux Destin d\'Amélie Poulain',
                'year' => '2001',
                'genre' => 'Comédie, Romance',
                'actors' => 'Audrey Tautou, Mathieu Kassovitz',
                'director' => 'Jean-Pierre Jeunet',
                'rating' => '8.3',
                'plot' => 'Amélie is an innocent and naïve girl in Paris with her own sense of justice.',
            ]],
        ];
    }

    // ---------------------------------------------------------------------
    // 5. VideoObserver
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     */
    #[DataProvider('videoProvider')]
    public function test_video_observer_search_document_and_sync_parity(array $attributes): void
    {
        $video = new Video;
        $video->setRawAttributes($attributes, true);

        $expected = VideoObserver::buildSearchDocumentPhp($video);
        $this->assertSame(
            $expected,
            VideoObserver::buildSearchDocumentNative($video),
        );
        $this->assertSame(
            VideoObserver::normalizeSearchDocumentPhp($attributes),
            VideoObserver::normalizeSearchDocumentNative($attributes),
        );

        foreach ([false, true] as $useRust) {
            VideoObserver::setUseRustFfi($useRust);
            $this->assertSame($expected, VideoObserver::buildSearchDocument($video));

            Search::shouldReceive('insertTvShow')
                ->once()
                ->with($expected);

            (new VideoObserver)->created($video);
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function videoProvider(): array
    {
        return [
            'populated tv show record' => [[
                'id' => 501,
                'title' => 'Severance',
                'tvdb' => 371980,
                'trakt' => 153993,
                'tvmaze' => 44933,
                'tvrage' => 0,
                'imdb' => '11280740',
                'tmdb' => 95396,
                'started' => '2022-02-18',
                'type' => 0,
            ]],
            'sparse video with nulls and missing fields' => [[
                'id' => 502,
                'title' => null,
                'tvdb' => null,
                'imdb' => null,
                'started' => null,
            ]],
            'completely empty video attributes' => [[]],
            'anime video with string and int fields' => [[
                'id' => 503,
                'title' => 'Steins;Gate 0',
                'tvdb' => 79216,
                'trakt' => 34039,
                'tvmaze' => 2740,
                'tvrage' => 28234,
                'imdb' => '1910272',
                'tmdb' => 42509,
                'started' => '2018-04-12',
                'type' => 2,
            ]],
        ];
    }

    // ---------------------------------------------------------------------
    // C-ABI Contract & Fallback Verification
    // ---------------------------------------------------------------------

    private static function ffiCall(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    public function test_c_abi_null_unaligned_overflow_and_buffer_contracts(): void
    {
        $tvFfi = RustPipelineLibrary::resolveDomain('metadata', TvProviderException::FFI_CDEF);
        $this->assertNotNull($tvFfi);
        $rawBytes = $tvFfi->new('uint8_t[32]');
        $unalignedI64 = $tvFfi->cast('int64_t*', FFI::addr($rawBytes[1]));
        $this->assertSame(-1, self::ffiCall($tvFfi, 'nntmux_tv_provider_parse_retry_after', 1, '30', 2, 1000, $unalignedI64));

        $lookupFfi = RustPipelineLibrary::resolveDomain('metadata', LookupMode::FFI_CDEF);
        $this->assertNotNull($lookupFfi);
        $small = $lookupFfi->new('char[4]');
        $this->assertSame(-1, self::ffiCall($lookupFfi, 'nntmux_lookup_mode_label', 99, $small, 4));
        $this->assertSame(-21, self::ffiCall($lookupFfi, 'nntmux_lookup_mode_label', 2, $small, 4));

        $videoFfi = RustPipelineLibrary::resolveDomain('metadata', Video::FFI_CDEF);
        $this->assertNotNull($videoFfi);
        $unalignedUsize = $videoFfi->cast('size_t*', FFI::addr($rawBytes[1]));
        $unalignedU32 = $videoFfi->cast('uint32_t*', FFI::addr($rawBytes[1]));
        $alignedUsize = $videoFfi->new('size_t[1]');
        $alignedU32 = $videoFfi->new('uint32_t[1]');
        $this->assertSame(-50, self::ffiCall($videoFfi, 'nntmux_video_series_list_cache_key', '1', 1, 'A', 1, 'S', 1, $small, 4));
        $this->assertSame(-1, self::ffiCall($videoFfi, 'nntmux_video_series_letter_filter', 'A', 1, $unalignedUsize, $alignedU32, $small, 4));
        $this->assertSame(-1, self::ffiCall($videoFfi, 'nntmux_video_series_letter_filter', 'A', 1, $alignedUsize, $unalignedU32, $small, 4));
        $this->assertSame(-1, self::ffiCall($videoFfi, 'nntmux_video_series_showname_filter', 'Show', 4, 1, $unalignedI64, 1, $alignedUsize, $alignedU32, $alignedU32, $small, 4));

        $movieObsFfi = RustPipelineLibrary::resolveDomain('metadata', MovieInfoObserver::FFI_CDEF);
        $this->assertNotNull($movieObsFfi);
        $this->assertSame(-1, self::ffiCall($movieObsFfi, 'nntmux_movie_info_observer_search_doc', 'invalid', 7, $small, 4));

        $videoObsFfi = RustPipelineLibrary::resolveDomain('metadata', VideoObserver::FFI_CDEF);
        $this->assertNotNull($videoObsFfi);
        $this->assertSame(-1, self::ffiCall($videoObsFfi, 'nntmux_video_observer_search_doc', 'invalid', 7, $small, 4));
    }

    public function test_native_methods_fall_back_to_php_when_ffi_unavailable(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::setFfiForTesting(false);
            $class::setUseRustFfi(true);
        }

        $this->assertTrue(TvProviderException::isTransientStatusNative(429));
        $this->assertFalse(TvProviderException::isTransientStatusNative(404));
        $this->assertSame(30, TvProviderException::parseRetryAfterNative('30', 1_700_000_000));

        $this->assertSame('All releases', LookupMode::All->labelNative());

        $this->assertSame(
            Video::buildSeriesListCacheKeyPhp(42, 'A', 'Severance'),
            Video::buildSeriesListCacheKeyNative(42, 'A', 'Severance'),
        );
        $this->assertSame(
            Video::buildSeriesLetterFilterPhp('A'),
            Video::buildSeriesLetterFilterNative('A'),
        );
        $this->assertSame(
            Video::buildSeriesShownameFilterPhp('Severance', true, [10, 20]),
            Video::buildSeriesShownameFilterNative('Severance', true, [10, 20]),
        );

        $movie = new MovieInfo(['id' => 1, 'imdbid' => '0133093', 'title' => 'The Matrix']);
        $this->assertSame(
            MovieInfoObserver::buildSearchDocumentPhp($movie),
            MovieInfoObserver::buildSearchDocumentNative($movie),
        );

        $video = new Video(['id' => 2, 'title' => 'Severance', 'tvdb' => 371980]);
        $this->assertSame(
            VideoObserver::buildSearchDocumentPhp($video),
            VideoObserver::buildSearchDocumentNative($video),
        );
    }

    // ---------------------------------------------------------------------
    // Paired Randomized Benchmarks
    // ---------------------------------------------------------------------

    public function test_paired_randomized_benchmarks(): void
    {
        if (getenv('NNTMUX_TV_PROVIDER_LOOKUP_VIDEO_OBSERVERS_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_TV_PROVIDER_LOOKUP_VIDEO_OBSERVERS_BENCH=1 to run paired Rust/PHP benchmarks.');
        }

        $movieCommon = new MovieInfo;
        $movieCommon->setRawAttributes([
            'id' => 101,
            'imdbid' => '0133093',
            'tmdbid' => 603,
            'traktid' => 481,
            'title' => 'The Matrix',
            'year' => '1999',
            'genre' => 'Action, Sci-Fi',
            'actors' => 'Keanu Reeves, Laurence Fishburne',
            'director' => 'Lana Wachowski, Lilly Wachowski',
            'rating' => '8.7',
            'plot' => 'A computer hacker learns about the true nature of reality.',
        ], true);

        $movieUncommon = new MovieInfo;
        $movieUncommon->setRawAttributes([
            'id' => 202,
            'title' => str_repeat('Long Movie Title ', 8),
            'plot' => str_repeat('Detailed synopsis paragraph with actors and genres. ', 16),
        ], true);

        $videoCommon = new Video;
        $videoCommon->setRawAttributes([
            'id' => 501,
            'title' => 'Severance',
            'tvdb' => 371980,
            'trakt' => 153993,
            'tvmaze' => 44933,
            'tvrage' => 0,
            'imdb' => '11280740',
            'tmdb' => 95396,
            'started' => '2022-02-18',
            'type' => 0,
        ], true);

        $videoUncommon = new Video;
        $videoUncommon->setRawAttributes([
            'id' => 502,
            'title' => str_repeat('Extended Documentary Series Title ', 6),
        ], true);

        $hitIdsUncommon = range(1001, 1150);

        $workloads = [
            'TvProviderException::isTransientStatus' => [
                TvProviderException::class,
                static fn () => TvProviderException::isTransientStatus(429),
                static fn () => TvProviderException::isTransientStatus(404),
            ],
            'TvProviderException::parseRetryAfter' => [
                TvProviderException::class,
                static fn () => TvProviderException::parseRetryAfter('30', 1_700_000_000),
                static fn () => TvProviderException::parseRetryAfter('Sun, 06 Nov 1994 08:49:37 GMT', 784_111_700),
            ],
            'LookupMode::label' => [
                LookupMode::class,
                static fn () => LookupMode::All->label(),
                static fn () => LookupMode::Renamed->label(),
            ],
            'Video::buildSeriesListCacheKey' => [
                Video::class,
                static fn () => Video::buildSeriesListCacheKey(42, 'A', ''),
                static fn () => Video::buildSeriesListCacheKey(987654, '0-9', 'The Lord of the Rings: The Rings of Power'),
            ],
            'Video::buildSeriesLetterFilter' => [
                Video::class,
                static fn () => Video::buildSeriesLetterFilter('S'),
                static fn () => Video::buildSeriesLetterFilter('0-9'),
            ],
            'Video::buildSeriesShownameFilter' => [
                Video::class,
                static fn () => Video::buildSeriesShownameFilter('Severance', false),
                static fn () => Video::buildSeriesShownameFilter('House', true, $hitIdsUncommon),
            ],
            'MovieInfoObserver::buildSearchDocument' => [
                MovieInfoObserver::class,
                static fn () => MovieInfoObserver::buildSearchDocument($movieCommon),
                static fn () => MovieInfoObserver::buildSearchDocument($movieUncommon),
            ],
            'VideoObserver::buildSearchDocument' => [
                VideoObserver::class,
                static fn () => VideoObserver::buildSearchDocument($videoCommon),
                static fn () => VideoObserver::buildSearchDocument($videoUncommon),
            ],
        ];

        $rows = [];
        foreach ($workloads as $name => [$class, $common, $uncommon]) {
            $worst = ['common_mean' => INF, 'common_p99' => INF, 'uncommon_mean' => INF, 'uncommon_p99' => INF];
            for ($rep = 0; $rep < 3; $rep++) {
                foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $fn) {
                    $class::setUseRustFfi(true);
                    $rustOut = self::invoke($fn);
                    $class::setUseRustFfi(false);
                    $this->assertSame(self::invoke($fn), $rustOut, "{$name} {$kind} benchmark output parity");

                    $stats = $this->runPairedBenchmark($class, $fn, 200, 41, 2005 + $rep);
                    fwrite(STDERR, json_encode(['method' => $name, 'workload' => $kind, 'repetition' => $rep] + $stats).PHP_EOL);
                    $worst["{$kind}_mean"] = min($worst["{$kind}_mean"], $stats['mean_speedup']);
                    $worst["{$kind}_p99"] = min($worst["{$kind}_p99"], $stats['p99_speedup']);
                }
            }
            $class::setUseRustFfi(null);
            $qualified = min($worst) >= 1.5;
            $rows[] = sprintf(
                ' * | %-42s | %.2fx / %.2fx       | %.2fx / %.2fx         | %-7s | %-12s |',
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
