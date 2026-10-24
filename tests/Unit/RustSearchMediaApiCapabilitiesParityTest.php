<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Api\ApiCapabilitiesService;
use App\Services\Api\V1\ApiV1Presenter;
use App\Services\Search\Drivers\ElasticSearchDriver;
use App\Services\Search\Drivers\ManticoreSearchDriver;
use App\Services\Search\MediaSearchService;
use App\Services\Search\SearchService;
use App\Support\ApproximateRowCount;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;

/**
 * Wave 17 parity and paired benchmark suite for `SearchService`, `MediaSearchService`,
 * `ApiCapabilitiesService`, `ApiV1Presenter` and `ApproximateRowCount`
 * (`rust/pipeline-ffi/src/search.rs`).
 *
 * Reproduce paired randomized benchmarks:
 *   cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release
 *   NNTMUX_SEARCH_MEDIA_API_CAPABILITIES_BENCH=1 php artisan test --compact tests/Unit/RustSearchMediaApiCapabilitiesParityTest.php --filter=test_benchmark_paired_workloads
 *
 * Protocol: seed 20261024; 512 warmups; 3 repetitions of 4,096 randomized paired
 * single calls per workload (common and uncommon) through the public PHP adapters, so
 * FFI marshaling and allocation are included; nearest-rank p99; output equality
 * verified before timing. Adoption requires >= 1.5x on all 4 metrics (common mean,
 * common p99, uncommon mean, uncommon p99) per `.ai/rules/rust-performance.md`.
 *
 * Environment: Apple M5, macOS, PHP 8.5.12 CLI (FFI), rustc 1.99.1 release (lto, cgu=1),
 * base commit cede7e144. Paired speedup (`PHP latency / Rust FFI latency`, mean/p99, 3 reps):
 * - `escapeString`:            common 0.89x/1.00x, 0.88x/0.90x, 0.88x/0.80x | uncommon 1.03x/0.86x, 0.90x/0.92x, 0.90x/0.92x
 * - `normalizeFuzzyConfig`:    common 0.07x/0.08x, 0.07x/0.08x, 0.07x/0.09x | uncommon 0.08x/0.08x, 0.08x/0.09x, 0.08x/0.08x
 * - `refreshedReleaseIds`:     common 0.72x/0.66x, 0.73x/0.87x, 0.73x/0.89x | uncommon 1.00x/0.90x, 1.00x/1.00x, 1.00x/0.95x
 * - `collectExternalIds`:      common 0.10x/0.10x, 0.10x/0.11x, 0.11x/0.11x | uncommon 0.10x/0.13x, 0.10x/0.14x, 0.10x/0.05x
 * - `uniqueLimitedReleaseIds`: common 0.55x/0.59x, 0.56x/0.62x, 0.54x/0.41x | uncommon 0.67x/0.74x, 0.70x/0.84x, 0.69x/0.79x
 * - `searchMediaRoute`:        common 0.32x/0.25x, 0.28x/0.34x, 0.29x/0.25x | uncommon 0.25x/0.40x, 0.25x/0.34x, 0.26x/0.25x
 * - `capsLimits`:              common 0.22x/0.23x, 0.22x/0.34x, 0.22x/0.29x | uncommon 0.23x/0.34x, 0.23x/0.34x, 0.23x/0.29x
 * - `capsRegistration`:        common 0.15x/0.20x, 0.14x/0.18x, 0.17x/0.22x | uncommon 0.16x/0.22x, 0.16x/0.22x, 0.16x/0.22x
 * - `capsSearching`:           common 0.29x/0.09x, 0.29x/0.08x, 0.30x/0.09x | uncommon 0.32x/0.25x, 0.31x/0.32x, 0.30x/0.14x
 * - `v1IsCaps`:                common 0.28x/0.25x, 0.27x/0.25x, 0.26x/0.25x | uncommon 0.29x/0.34x, 0.28x/0.34x, 0.27x/0.34x
 * - `v1ResponseHeaders`:       common 0.25x/0.30x, 0.24x/0.33x, 0.23x/0.20x | uncommon 0.30x/0.33x, 0.30x/0.31x, 0.38x/0.32x
 * - `rowCountDriver`:          common 0.33x/0.25x, 0.34x/0.50x, 0.41x/0.50x | uncommon 0.36x/0.33x, 0.31x/0.50x, 0.28x/0.25x
 * - `rowCountNormalize`:       common 0.15x/0.25x, 0.15x/0.28x, 0.18x/0.25x | uncommon 0.15x/0.14x, 0.15x/0.13x, 0.15x/0.14x
 *
 * The `escapeString` PHP baseline is the current production path, which already calls
 * the Rust Manticore escaper through `RustPipelineLibrary`. No method qualified: each helper is a sub-microsecond scalar,
 * short-string or small-list operation, so the FFI call and value marshaling cost more
 * than the PHP work. `$useRustFfi = false` stays the default in all five classes; both
 * `*Native` and `*Php` paths remain covered by the parity tests below.
 */
final class RustSearchMediaApiCapabilitiesParityTest extends TestCase
{
    private const string FULL_CDEF = SearchService::FFI_CDEF."\n"
        .MediaSearchService::FFI_CDEF."\n"
        .ApiCapabilitiesService::FFI_CDEF."\n"
        .ApiV1Presenter::FFI_CDEF."\n"
        .ApproximateRowCount::FFI_CDEF;

    /** @var list<class-string> */
    private const array CLASSES = [
        SearchService::class,
        MediaSearchService::class,
        ApiCapabilitiesService::class,
        ApiV1Presenter::class,
        ApproximateRowCount::class,
    ];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(false);
            (new ReflectionProperty($class, 'ffi'))->setValue(null, null);
        }
        parent::tearDown();
    }

    private static function ffi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('search', self::FULL_CDEF);
        self::assertNotNull($ffi, 'Build rust/pipeline-ffi in release mode to load the search domain.');

        return $ffi;
    }

    public function test_search_service_escape_fuzzy_and_deferred_ids_match_php(): void
    {
        self::assertNotNull(SearchService::searchFfi());

        $strings = ['', '0', '*', ' ', 'foo', 'a--b', 'x---y', ' foo(bar)!- ', "tab\t", 'back\\slash', '"quoted" & \'single\'',
            '@field ~fuzzy /path $var =eq [br] !bang', '-leading', 'trailing!!--', "\0nul\0", 'ünïcödé – dash', str_repeat('(-)', 200)];
        foreach ($strings as $string) {
            self::assertSame(
                ManticoreSearchDriver::escapeStringPhp($string),
                SearchService::escapeForDriverClassNative(ManticoreSearchDriver::class, $string),
                var_export($string, true),
            );
            self::assertSame(
                ElasticSearchDriver::escapeString($string),
                SearchService::escapeForDriverClassNative(ElasticSearchDriver::class, $string),
            );
        }

        $configs = [
            [],
            ['enabled' => false],
            ['enabled' => true, 'max_distance' => 3, 'layouts' => 'us'],
            ['enabled' => '0', 'max_distance' => '4', 'prefix_length' => '1', 'max_expansions' => '25'],
            ['enabled' => 'false', 'max_distance' => '', 'prefix_length' => null, 'max_expansions' => '-7'],
            ['enabled' => 0, 'max_distance' => true, 'prefix_length' => false, 'max_expansions' => []],
            ['enabled' => [], 'max_distance' => [1], 'fuzziness' => 'AUTO'],
            ['enabled' => 1.0, 'max_distance' => 2.9, 'prefix_length' => ' 3', 'max_expansions' => '1e2'],
            ['max_distance' => '99999999999999999999', 'max_expansions' => '12abc'],
            ['max_distance' => PHP_INT_MIN, 'prefix_length' => PHP_INT_MAX],
        ];
        foreach ($configs as $config) {
            self::assertSame(SearchService::normalizeFuzzyConfigPhp($config), SearchService::normalizeFuzzyConfigNative($config), json_encode($config) ?: '');
        }

        $cases = [
            [[], []],
            [[1, 2, 3], []],
            [[5, 3, 5, 9, 1], [5]],
            [[5, 3, 5, 9, 1], [9, 9, 100]],
            [range(1, 200), range(2, 200, 3)],
            [[PHP_INT_MAX, PHP_INT_MIN, 0, -1], [0, PHP_INT_MIN]],
            [[1, 2, 3], ['2']],
            [[1, 2, 3], [1, 2, 3]],
        ];
        foreach ($cases as [$ids, $failed]) {
            self::assertSame(SearchService::refreshedReleaseIdsPhp($ids, $failed), SearchService::refreshedReleaseIdsNative($ids, $failed));
        }
    }

    public function test_media_search_external_ids_limits_and_route_match_php(): void
    {
        self::assertNotNull(MediaSearchService::searchFfi());

        $rows = [
            [],
            ['imdbid' => '0133093', 'tmdbid' => 603, 'traktid' => 481],
            ['imdbid' => '0', 'tmdbid' => 0, 'traktid' => null, 'title' => 'x'],
            ['imdbid' => '', 'tmdbid' => '00', 'traktid' => false],
            ['imdbid' => true, 'tmdbid' => [], 'traktid' => [1]],
            ['imdbid' => 0.0, 'tmdbid' => 1.5],
            ['tvdb' => 81189, 'trakt' => '1388', 'tvmaze' => 0, 'tvrage' => '18164'],
            ['tvdb' => '0', 'trakt' => 0, 'tvmaze' => '169', 'tvrage' => null],
        ];
        foreach ($rows as $row) {
            foreach ([MediaSearchService::MOVIE_EXTERNAL_ID_FIELDS, MediaSearchService::TV_EXTERNAL_ID_FIELDS] as $fields) {
                self::assertSame(MediaSearchService::collectExternalIdsPhp($row, $fields), MediaSearchService::collectExternalIdsNative($row, $fields));
            }
        }
        self::assertSame(['traktid' => '1388'], MediaSearchService::collectExternalIdsNative(['trakt' => '1388'], MediaSearchService::TV_EXTERNAL_ID_FIELDS));
        self::assertSame([], MediaSearchService::collectExternalIdsNative(['a' => 1], []));

        $lists = [[], [1, 2, 3], [3, 3, 1, 2, 1], range(1, 50), array_merge(range(1, 40), range(20, 60)), [7, '7', 8], ['a' => 1, 'b' => 1, 'c' => 2], [PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX]];
        foreach ($lists as $ids) {
            foreach ([1000, 3, 1, 0, -1, -2, -100, PHP_INT_MAX, PHP_INT_MIN] as $limit) {
                self::assertSame(MediaSearchService::uniqueLimitedReleaseIdsPhp($ids, $limit), MediaSearchService::uniqueLimitedReleaseIdsNative($ids, $limit), json_encode([$ids, $limit]) ?: '');
            }
        }

        foreach ([[[], 'movie'], [[], 'tv'], [[], ''], [[], 'Movie'], [['imdbid' => 1], 'tv'], [['tvdb' => 0], 'movie']] as [$externalIds, $type]) {
            self::assertSame(MediaSearchService::searchMediaRoutePhp($externalIds, $type), MediaSearchService::searchMediaRouteNative($externalIds, $type));
        }
    }

    public function test_api_capabilities_presenter_and_row_count_helpers_match_php(): void
    {
        self::assertNotNull(ApiCapabilitiesService::searchFfi());
        self::assertNotNull(ApiV1Presenter::searchFfi());
        self::assertNotNull(ApproximateRowCount::searchFfi());

        foreach ([[500, 250], [1, 1], [100, 100], [PHP_INT_MAX, 1], [0, 0], [5, 6], [-1, -1]] as [$max, $default]) {
            self::assertSame(ApiCapabilitiesService::limitsPayloadPhp($max, $default), ApiCapabilitiesService::limitsPayloadNative($max, $default));
        }
        foreach ([[true, true], [true, false], [false, true], [false, false]] as [$available, $open]) {
            self::assertSame(ApiCapabilitiesService::registrationPayloadPhp($available, $open), ApiCapabilitiesService::registrationPayloadNative($available, $open));
        }
        foreach ([1, 2, 0, 3, -1] as $version) {
            self::assertSame(ApiCapabilitiesService::searchingPhp($version), ApiCapabilitiesService::searchingNative($version));
        }
        // The advertised v1 table is unchanged from the inline literal it replaced.
        self::assertSame(
            ['available' => 'yes', 'supportedParams' => 'q,vid,tvdbid,traktid,rid,tvmazeid,imdbid,tmdbid,season,ep,cat,minsize,maxsize,maxage,limit,offset,attrs,extended,del,sort'],
            ApiCapabilitiesService::searchingPhp(1)['tv-search'],
        );
        self::assertSame(['search', 'tv-search', 'movie-search', 'audio-search', 'book-search', 'anime-search'], array_keys(ApiCapabilitiesService::searchingNative(2)));

        foreach (['caps', 'search', '', 'CAPS', 'caps ', "caps\0"] as $type) {
            self::assertSame(ApiV1Presenter::isCapsRequestPhp($type), ApiV1Presenter::isCapsRequestNative($type));
        }
        $headerSets = [[], ['X-DNZB-Name' => 'name'], ['Content-type' => 'application/rss+xml'], ['Content-Length' => '1', 'Cache-Control' => 'no-cache'], [0 => 'numeric']];
        foreach ([true, false] as $xml) {
            foreach (['', '{}', str_repeat('<item/>', 5000)] as $body) {
                foreach ($headerSets as $headers) {
                    self::assertSame(ApiV1Presenter::responseHeadersPhp($xml, $body, $headers), ApiV1Presenter::responseHeadersNative($xml, $body, $headers));
                }
            }
        }

        foreach (['mysql', 'mariadb', 'MySQL', 'pgsql', 'sqlite', '', 'mysql ', null, 1, false] as $driver) {
            self::assertSame(ApproximateRowCount::driverSupportsInformationSchemaPhp($driver), ApproximateRowCount::driverSupportsInformationSchemaNative($driver));
        }
        $approxValues = [null, 0, 1, -5, 123456789, PHP_INT_MAX, '0', '42', '-3', '', 'abc', '007', '12abc', ' 42', '1e3', '18446744073709551615', '9223372036854775807', true, false, 3.7, -0.5, [], [1]];
        foreach ($approxValues as $approx) {
            self::assertSame(ApproximateRowCount::normalizeApproximateRowsPhp($approx), ApproximateRowCount::normalizeApproximateRowsNative($approx), var_export($approx, true));
        }
    }

    public function test_dispatch_toggles_and_missing_library_fall_back_to_php(): void
    {
        $config = ['enabled' => '0', 'max_distance' => '3'];
        $expected = SearchService::normalizeFuzzyConfigPhp($config);
        SearchService::setUseRustFfi(true);
        MediaSearchService::setUseRustFfi(true);
        ApiCapabilitiesService::setUseRustFfi(true);
        ApiV1Presenter::setUseRustFfi(true);
        ApproximateRowCount::setUseRustFfi(true);
        self::assertSame($expected, SearchService::normalizeFuzzyConfig($config));
        self::assertSame([2, 4], SearchService::refreshedReleaseIds([1, 2, 3, 4], [1, 3]));
        self::assertSame([3, 1], MediaSearchService::uniqueLimitedReleaseIds([3, 3, 1, 2], 2));
        self::assertSame('movie', MediaSearchService::searchMediaRoute([], 'movie'));
        self::assertSame(['max' => 500, 'default' => 250], ApiCapabilitiesService::limitsPayload(500, 250));
        self::assertTrue(ApiV1Presenter::isCapsRequest('caps'));
        self::assertSame(42, ApproximateRowCount::normalizeApproximateRows('42'));

        // A missing library resolves to false; every Native path then returns the PHP result.
        foreach (self::CLASSES as $class) {
            (new ReflectionProperty($class, 'ffi'))->setValue(null, false);
            self::assertNull($class::searchFfi());
        }
        self::assertSame('a\\-b', SearchService::escapeForDriverClass(ManticoreSearchDriver::class, 'a-b'));
        self::assertSame($expected, SearchService::normalizeFuzzyConfig($config));
        self::assertSame([2, 4], SearchService::refreshedReleaseIds([1, 2, 3, 4], [1, 3]));
        self::assertSame(['imdbid' => 'tt1'], MediaSearchService::collectExternalIds(['imdbid' => 'tt1'], MediaSearchService::MOVIE_EXTERNAL_ID_FIELDS));
        self::assertSame([3, 1], MediaSearchService::uniqueLimitedReleaseIds([3, 3, 1, 2], 2));
        self::assertSame('tv', MediaSearchService::searchMediaRoute([], 'tv'));
        self::assertSame(['available' => 'no', 'open' => 'yes'], ApiCapabilitiesService::registrationPayload(false, true));
        self::assertSame(ApiCapabilitiesService::searchingPhp(1), ApiCapabilitiesService::searching(1));
        self::assertSame(['Content-type' => 'text/xml', 'Content-Length' => '3'], ApiV1Presenter::responseHeaders(true, 'abc'));
        self::assertTrue(ApproximateRowCount::driverSupportsInformationSchema('mariadb'));
        self::assertNull(ApproximateRowCount::normalizeApproximateRows('0'));
    }

    public function test_direct_c_abi_error_and_buffer_contracts(): void
    {
        $ffi = self::ffi();
        $small = $ffi->new('char[4]');
        $big = $ffi->new('char[1024]');
        $i64 = $ffi->new('int64_t[4]');
        $tags = $ffi->new('int32_t[4]');
        $mask = $ffi->new('uint64_t[1]');

        // Escape: unsupported driver kind, short buffer (-(required + 3)), null input with length.
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_search_service_escape_string(1, 'x', 1, $big, 1024));
        // @phpstan-ignore method.notFound
        self::assertSame(-(\strlen('a\\(b\\)') + 3), $ffi->nntmux_search_service_escape_string(0, 'a(b)', 4, $small, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_search_service_escape_string(0, null, 3, $big, 1024));
        // @phpstan-ignore method.notFound
        self::assertSame(0, $ffi->nntmux_search_service_escape_string(0, '', 0, null, 0));

        // Fuzzy: null arrays, a length table that does not cover the input, an unknown tag.
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_search_fuzzy_config_normalize(null, null, '', 0, null, $i64));
        for ($i = 0; $i < 4; $i++) {
            $tags[$i] = 0;
            $i64[$i] = 0;
        }
        $lens = $ffi->new('int64_t[4]');
        $out = $ffi->new('int64_t[4]');
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_search_fuzzy_config_normalize($tags, $i64, 'xy', 2, $lens, $out));
        // @phpstan-ignore method.notFound
        self::assertSame(0, $ffi->nntmux_search_fuzzy_config_normalize($tags, $i64, '', 0, $lens, $out));
        self::assertSame([1, 2, 2, 50], [$out[0], $out[1], $out[2], $out[3]]);
        $tags[1] = 4;
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_search_fuzzy_config_normalize($tags, $i64, '', 0, $lens, $out));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_search_fuzzy_config_normalize($tags, $i64, '', 0, $lens, null));

        // ID lists: output capacity is reported in elements, null inputs with counts fail.
        $ids = $ffi->new('int64_t[3]');
        $ids[0] = 4;
        $ids[1] = 4;
        $ids[2] = 5;
        $one = $ffi->new('int64_t[1]');
        // @phpstan-ignore method.notFound
        self::assertSame(-6, $ffi->nntmux_search_release_ids_diff($ids, 3, null, 0, $one, 1));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_search_release_ids_diff(null, 3, null, 0, $one, 1));
        // @phpstan-ignore method.notFound
        self::assertSame(-5, $ffi->nntmux_search_release_ids_unique_limit($ids, 3, 10, $one, 1));
        // @phpstan-ignore method.notFound
        self::assertSame(1, $ffi->nntmux_search_release_ids_unique_limit($ids, 3, -1, $one, 1));
        self::assertSame(4, $one[0]);
        // @phpstan-ignore method.notFound
        self::assertSame(0, $ffi->nntmux_search_release_ids_unique_limit(null, 0, 10, null, 0));

        // External-ID presence: more than 64 values and null mask are rejected.
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_media_external_id_presence($tags, $i64, '', 0, $lens, 65, $mask));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_media_external_id_presence($tags, $i64, '', 0, $lens, 1, null));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_media_search_route(2, 'movie', 5));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_media_search_route(0, null, 5));

        // API helpers.
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_api_capabilities_registration(2, 0, $big, 1024));
        // @phpstan-ignore method.notFound
        self::assertSame(-(\strlen("yes\tyes") + 3), $ffi->nntmux_api_capabilities_registration(1, 1, $small, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_api_capabilities_limits(5, 6, $i64));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_api_capabilities_limits(5, 5, null));
        // @phpstan-ignore method.notFound
        self::assertLessThan(-100, $ffi->nntmux_api_capabilities_searching(1, $small, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_api_capabilities_searching(1, null, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_api_v1_is_caps(null, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-(\strlen("text/xml\t12") + 3), $ffi->nntmux_api_v1_response_headers(1, 12, $small, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_api_v1_response_headers(2, 12, $big, 1024));

        // Row-count helpers.
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_db_supports_information_schema(null, 5));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_db_normalize_approx_rows(2, 5, '', 0, null));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_db_normalize_approx_rows(9, 0, '', 0, $one));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_db_normalize_approx_rows(2, 5, 'x', 1, $one));
    }

    /**
     * Run with:
     * NNTMUX_SEARCH_MEDIA_API_CAPABILITIES_BENCH=1 php artisan test --compact tests/Unit/RustSearchMediaApiCapabilitiesParityTest.php --filter=test_benchmark_paired_workloads
     */
    public function test_benchmark_paired_workloads(): void
    {
        if (getenv('NNTMUX_SEARCH_MEDIA_API_CAPABILITIES_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_SEARCH_MEDIA_API_CAPABILITIES_BENCH=1 to run paired benchmarks.');
        }

        self::ffi();
        $m = ManticoreSearchDriver::class;
        $deferredCommon = range(1_000_001, 1_000_200);
        $deferredUncommon = range(5_000_001, 5_000_200);
        $failedUncommon = range(5_000_001, 5_000_200, 7);
        $releaseIdsCommon = array_merge(range(1, 80), range(41, 120));
        $releaseIdsUncommon = array_merge(range(1, 900), range(450, 1500));
        $movieRow = ['id' => 1, 'imdbid' => '0133093', 'tmdbid' => 603, 'traktid' => 481, 'title' => 'The Matrix'];
        $tvRow = ['id' => 2, 'tvdb' => 0, 'trakt' => '1388', 'tvmaze' => 169, 'tvrage' => null, 'title' => 'Breaking Bad'];
        $bodyUncommon = str_repeat('<item><title>x</title></item>', 4000);

        $workloads = [
            'escapeString/common' => [
                static fn () => SearchService::escapeForDriverClassPhp($m, 'the matrix 1999'),
                static fn () => SearchService::escapeForDriverClassNative($m, 'the matrix 1999'),
            ],
            'escapeString/uncommon' => [
                static fn () => SearchService::escapeForDriverClassPhp($m, 'Some.Show-(2024)--S01E02 [1080p] @group !REPACK "proper"'),
                static fn () => SearchService::escapeForDriverClassNative($m, 'Some.Show-(2024)--S01E02 [1080p] @group !REPACK "proper"'),
            ],
            'normalizeFuzzyConfig/common' => [
                static fn () => SearchService::normalizeFuzzyConfigPhp(['enabled' => true, 'max_distance' => 2, 'layouts' => 'us']),
                static fn () => SearchService::normalizeFuzzyConfigNative(['enabled' => true, 'max_distance' => 2, 'layouts' => 'us']),
            ],
            'normalizeFuzzyConfig/uncommon' => [
                static fn () => SearchService::normalizeFuzzyConfigPhp(['enabled' => '0', 'max_distance' => '3', 'prefix_length' => '1', 'max_expansions' => '25']),
                static fn () => SearchService::normalizeFuzzyConfigNative(['enabled' => '0', 'max_distance' => '3', 'prefix_length' => '1', 'max_expansions' => '25']),
            ],
            'refreshedReleaseIds/common' => [
                static fn () => SearchService::refreshedReleaseIdsPhp($deferredCommon, [1_000_050]),
                static fn () => SearchService::refreshedReleaseIdsNative($deferredCommon, [1_000_050]),
            ],
            'refreshedReleaseIds/uncommon' => [
                static fn () => SearchService::refreshedReleaseIdsPhp($deferredUncommon, $failedUncommon),
                static fn () => SearchService::refreshedReleaseIdsNative($deferredUncommon, $failedUncommon),
            ],
            'collectExternalIds/common' => [
                static fn () => MediaSearchService::collectExternalIdsPhp($movieRow, MediaSearchService::MOVIE_EXTERNAL_ID_FIELDS),
                static fn () => MediaSearchService::collectExternalIdsNative($movieRow, MediaSearchService::MOVIE_EXTERNAL_ID_FIELDS),
            ],
            'collectExternalIds/uncommon' => [
                static fn () => MediaSearchService::collectExternalIdsPhp($tvRow, MediaSearchService::TV_EXTERNAL_ID_FIELDS),
                static fn () => MediaSearchService::collectExternalIdsNative($tvRow, MediaSearchService::TV_EXTERNAL_ID_FIELDS),
            ],
            'uniqueLimitedReleaseIds/common' => [
                static fn () => MediaSearchService::uniqueLimitedReleaseIdsPhp($releaseIdsCommon, 1000),
                static fn () => MediaSearchService::uniqueLimitedReleaseIdsNative($releaseIdsCommon, 1000),
            ],
            'uniqueLimitedReleaseIds/uncommon' => [
                static fn () => MediaSearchService::uniqueLimitedReleaseIdsPhp($releaseIdsUncommon, 1000),
                static fn () => MediaSearchService::uniqueLimitedReleaseIdsNative($releaseIdsUncommon, 1000),
            ],
            'searchMediaRoute/common' => [
                static fn () => MediaSearchService::searchMediaRoutePhp([], 'movie'),
                static fn () => MediaSearchService::searchMediaRouteNative([], 'movie'),
            ],
            'searchMediaRoute/uncommon' => [
                static fn () => MediaSearchService::searchMediaRoutePhp(['tvdb' => 81189], 'television'),
                static fn () => MediaSearchService::searchMediaRouteNative(['tvdb' => 81189], 'television'),
            ],
            'capsLimits/common' => [
                static fn () => ApiCapabilitiesService::limitsPayloadPhp(500, 250),
                static fn () => ApiCapabilitiesService::limitsPayloadNative(500, 250),
            ],
            'capsLimits/uncommon' => [
                static fn () => ApiCapabilitiesService::limitsPayloadPhp(PHP_INT_MAX, 1),
                static fn () => ApiCapabilitiesService::limitsPayloadNative(PHP_INT_MAX, 1),
            ],
            'capsRegistration/common' => [
                static fn () => ApiCapabilitiesService::registrationPayloadPhp(true, true),
                static fn () => ApiCapabilitiesService::registrationPayloadNative(true, true),
            ],
            'capsRegistration/uncommon' => [
                static fn () => ApiCapabilitiesService::registrationPayloadPhp(false, true),
                static fn () => ApiCapabilitiesService::registrationPayloadNative(false, true),
            ],
            'capsSearching/common' => [
                static fn () => ApiCapabilitiesService::searchingPhp(1),
                static fn () => ApiCapabilitiesService::searchingNative(1),
            ],
            'capsSearching/uncommon' => [
                static fn () => ApiCapabilitiesService::searchingPhp(2),
                static fn () => ApiCapabilitiesService::searchingNative(2),
            ],
            'v1IsCaps/common' => [
                static fn () => ApiV1Presenter::isCapsRequestPhp('search'),
                static fn () => ApiV1Presenter::isCapsRequestNative('search'),
            ],
            'v1IsCaps/uncommon' => [
                static fn () => ApiV1Presenter::isCapsRequestPhp('caps'),
                static fn () => ApiV1Presenter::isCapsRequestNative('caps'),
            ],
            'v1ResponseHeaders/common' => [
                static fn () => ApiV1Presenter::responseHeadersPhp(false, '{"channel":{}}', []),
                static fn () => ApiV1Presenter::responseHeadersNative(false, '{"channel":{}}', []),
            ],
            'v1ResponseHeaders/uncommon' => [
                static fn () => ApiV1Presenter::responseHeadersPhp(true, $bodyUncommon, ['X-DNZB-Name' => 'Some.Release', 'Content-type' => 'application/rss+xml']),
                static fn () => ApiV1Presenter::responseHeadersNative(true, $bodyUncommon, ['X-DNZB-Name' => 'Some.Release', 'Content-type' => 'application/rss+xml']),
            ],
            'rowCountDriver/common' => [
                static fn () => ApproximateRowCount::driverSupportsInformationSchemaPhp('mariadb'),
                static fn () => ApproximateRowCount::driverSupportsInformationSchemaNative('mariadb'),
            ],
            'rowCountDriver/uncommon' => [
                static fn () => ApproximateRowCount::driverSupportsInformationSchemaPhp('pgsql'),
                static fn () => ApproximateRowCount::driverSupportsInformationSchemaNative('pgsql'),
            ],
            'rowCountNormalize/common' => [
                static fn () => ApproximateRowCount::normalizeApproximateRowsPhp(48_211_934),
                static fn () => ApproximateRowCount::normalizeApproximateRowsNative(48_211_934),
            ],
            'rowCountNormalize/uncommon' => [
                static fn () => ApproximateRowCount::normalizeApproximateRowsPhp('0'),
                static fn () => ApproximateRowCount::normalizeApproximateRowsNative('0'),
            ],
        ];

        $percentile99 = static function (array $ns): float {
            sort($ns);

            return (float) $ns[(int) ceil(count($ns) * 0.99) - 1];
        };

        $random = new Randomizer(new Mt19937(20261024));
        foreach ($workloads as $name => [$phpCall, $rustCall]) {
            self::assertSame($phpCall(), $rustCall(), $name);
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
                $reps[] = sprintf('rep%d=%.2fx/%.2fx', $rep + 1, $phpMean / $rustMean, $percentile99($samples[0]) / $percentile99($samples[1]));
            }
            fwrite(STDOUT, sprintf("%-34s %s\n", $name, implode('  ', $reps)));
        }
    }
}
