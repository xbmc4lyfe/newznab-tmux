<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\Api\ApiV2Controller;
use App\Http\Controllers\BasePageController;
use App\Http\Controllers\SeriesController;
use App\Services\Api\ApiQueryParameters;
use App\Services\Api\V2\ApiV2Presenter;
use App\Services\Configuration\ConfigurationProvider;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\UrlGenerator as UrlGeneratorContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\UrlGenerator;
use PHPUnit\Framework\Attributes\Test;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Parity, boundary, and paired benchmark suite for Wave 12 Worker 5 Rust FFI exports in
 * `rust/pipeline-ffi/src/categorize.rs` (`pub mod api_query_and_page_params`):
 * - `ApiQueryParameters`: `categories`, `sort`, `hasValidSort`
 * - `ApiV2Controller`: `isNonNegativeInteger`, `parseSort`, `combineTvAirDate`
 * - `BasePageController`: `localReturnUrl`, `resolvePage`, `integerInput`
 * - `SeriesController`: `classifyTitleBucket`, `groupSeriesByBucket`, `resolveSeason`
 *
 * Paired randomized single-call benchmark summary (Darwin arm64 Apple M5, `cargo build --release`, N=6,000 pairs):
 * - `SeriesController::groupSeriesByBucket` (common 40-show list): PHP 3676.4 ns mean / 4042 ns p99 vs Rust 1981.2 ns mean / 2416 ns p99 -> 1.86x mean / 1.67x p99 (adopted in production).
 * - `SeriesController::groupSeriesByBucket` (uncommon 250-show list): PHP 23646.3 ns mean / 29542 ns p99 vs Rust 12164.0 ns mean / 15875 ns p99 -> 1.94x mean / 1.86x p99 (adopted in production).
 * - `ApiQueryParameters::categories` (common 6-cat / uncommon 32-cat): 1.08x mean / 0.90x p99 and 1.39x mean / 1.39x p99 due to `Request::has()` + `Request::input()` overhead (~1000 ns) -> retained pure PHP in production; `categoriesNative` tested.
 * - `ApiQueryParameters::sort` / `hasValidSort`: 0.83x and 0.96x mean -> retained pure PHP in production; `sortNative` / `hasValidSortNative` tested.
 * - `ApiV2Controller::isNonNegativeInteger` / `parseSort` / `combineTvAirDate`: 0.68x-0.96x mean -> retained pure PHP in production; `*Native` methods tested.
 * - `BasePageController::localReturnUrl` / `resolvePage` / `integerInput`: 0.87x-1.04x mean (`url()` container lookup ~1000 ns and `Request::input()` ~500 ns dominate) -> retained pure PHP in production; `*Native` methods tested.
 * - `SeriesController::classifyTitleBucket` (single call) / `resolveSeason`: 1.01x-1.06x and 0.91x mean -> retained pure PHP in production; `*Native` methods tested.
 */
final class RustApiQueryAndPageParameterParityTest extends TestCase
{
    private ApiQueryParameters $queryParams;

    private ApiV2Controller $apiV2Controller;

    private BasePageController $basePageController;

    private SeriesController $seriesController;

    protected function setUp(): void
    {
        parent::setUp();

        ApiQueryParameters::setUseRustFfi(null);
        ApiV2Controller::setUseRustFfi(null);
        BasePageController::setUseRustFfi(null);
        SeriesController::setUseRustFfi(null);

        $this->queryParams = new ApiQueryParameters;
        $this->apiV2Controller = (new ReflectionClass(ApiV2Controller::class))->newInstanceWithoutConstructor();
        $presenterProp = new ReflectionProperty(ApiV2Controller::class, 'presenter');
        $presenterProp->setValue($this->apiV2Controller, new ApiV2Presenter);

        $this->basePageController = (new ReflectionClass(BasePageController::class))->newInstanceWithoutConstructor();
        $this->seriesController = (new ReflectionClass(SeriesController::class))->newInstanceWithoutConstructor();
    }

    protected function tearDown(): void
    {
        ApiQueryParameters::setUseRustFfi(null);
        ApiV2Controller::setUseRustFfi(null);
        BasePageController::setUseRustFfi(null);
        SeriesController::setUseRustFfi(null);

        parent::tearDown();
    }

    #[Test]
    public function ffi_handles_and_toggles_work(): void
    {
        self::assertNotNull(ApiQueryParameters::ffi(), 'ApiQueryParameters FFI handle must be available.');
        self::assertNotNull(ApiV2Controller::v2Ffi(), 'ApiV2Controller FFI handle must be available.');
        self::assertNotNull(BasePageController::baseFfi(), 'BasePageController FFI handle must be available.');
        self::assertNotNull(SeriesController::seriesFfi(), 'SeriesController FFI handle must be available.');

        // SeriesController adopts Rust FFI by default for batch title bucketing (>= 1.5x mean & p99)
        self::assertTrue(SeriesController::usesRustFfi());
        SeriesController::setUseRustFfi(false);
        self::assertFalse(SeriesController::usesRustFfi());
        SeriesController::setUseRustFfi(true);
        self::assertTrue(SeriesController::usesRustFfi());
        SeriesController::setUseRustFfi(null);

        // Single-call request parameter helpers default to pure PHP (< 1.5x with Request/UrlGenerator overhead)
        foreach ([
            ApiQueryParameters::class,
            ApiV2Controller::class,
            BasePageController::class,
        ] as $class) {
            self::assertFalse($class::usesRustFfi(), "{$class} should default to pure PHP");
            $class::setUseRustFfi(true);
            self::assertTrue($class::usesRustFfi(), "{$class} should enable Rust FFI when toggled on");
            $class::setUseRustFfi(false);
            self::assertFalse($class::usesRustFfi(), "{$class} should disable Rust FFI when toggled off");
            $class::setUseRustFfi(null);
        }
    }

    #[Test]
    public function c_abi_error_sentinel_and_resize_contracts(): void
    {
        $ffi = ApiQueryParameters::ffi();
        self::assertNotNull($ffi);

        $tiny = $ffi->new('char[4]');
        $buf = $ffi->new('char[64]');

        // 1. nntmux_api_parse_categories
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_api_parse_categories(null, 5, 0, 0, $buf, 64));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_api_parse_categories('2000', 4, 2, 0, $buf, 64));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_api_parse_categories('2000', 4, 0, 5, $buf, 64));
        // @phpstan-ignore method.notFound
        self::assertSame(-2, $ffi->nntmux_api_parse_categories('   ', 3, 0, 0, $buf, 64));
        // @phpstan-ignore method.notFound
        self::assertSame(0, $ffi->nntmux_api_parse_categories(',,,', 3, 0, 0, $buf, 64));
        // @phpstan-ignore method.notFound
        self::assertSame(-3, $ffi->nntmux_api_parse_categories('2000,5040', 9, 0, -1, $buf, 64));
        // @phpstan-ignore method.notFound
        self::assertSame(-9, $ffi->nntmux_api_parse_categories('2000,5030', 9, 0, 0, $tiny, 4));
        // Embedded newline in token declines (-1)
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_api_parse_categories('2000%0A3000', 11, 0, 0, $buf, 64));

        // 2. nntmux_api_normalize_sort / is_valid_sort / validate_sort
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_api_normalize_sort(null, 5, $buf, 64));
        // @phpstan-ignore method.notFound
        self::assertSame(-11, $ffi->nntmux_api_normalize_sort('POSTED_DESC', 11, $tiny, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_api_is_valid_sort(null, 5));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_api_validate_sort(null, 5, $buf, 64));
        // @phpstan-ignore method.notFound
        self::assertSame(0, $ffi->nntmux_api_validate_sort('   ', 3, $buf, 64));
        // @phpstan-ignore method.notFound
        self::assertSame(-2, $ffi->nntmux_api_validate_sort('invalid', 7, $buf, 64));
        // @phpstan-ignore method.notFound
        self::assertSame(-8, $ffi->nntmux_api_validate_sort('NAME_ASC', 8, $tiny, 4));

        // 3. nntmux_api_is_non_negative_int_str & combine_tv_airdate
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_api_is_non_negative_int_str(null, 3));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_api_combine_tv_airdate(null, 4, '03/15', 5, $buf, 64));
        // @phpstan-ignore method.notFound
        self::assertSame(-10, $ffi->nntmux_api_combine_tv_airdate('2024', 4, '03/15', 5, $tiny, 4));

        // 4. nntmux_page_resolve_page, parse_integer, classify_return_url
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_page_resolve_page(null, 3));
        // @phpstan-ignore method.notFound
        self::assertSame(PHP_INT_MIN, $ffi->nntmux_page_parse_integer(null, 3, 0));
        // @phpstan-ignore method.notFound
        self::assertSame(PHP_INT_MIN, $ffi->nntmux_page_parse_integer('10', 2, PHP_INT_MIN));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_page_classify_return_url(null, 5, 'https', 5, 'nntmux.test', 11, 443));

        // 5. nntmux_series_classify_title_bucket, classify_titles_nul, resolve_season
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_series_classify_title_bucket(null, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_series_classify_titles_nul(null, 4, 2, $buf, 64));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_series_classify_titles_nul("A\0B", 3, 3, $buf, 64));
        // @phpstan-ignore method.notFound
        self::assertSame(-6, $ffi->nntmux_series_classify_titles_nul("A\0B\0C\0D\0E\0F", 11, 6, $tiny, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_series_resolve_season(null, 3));
    }

    #[Test]
    public function api_query_parameters_categories_sort_and_valid_sort_match_php(): void
    {
        foreach ([0, 1] as $webDlSetting) {
            $this->app->instance(ConfigurationProvider::class, new class($webDlSetting)
            {
                public function __construct(private readonly int $categorizeWebDl) {}

                public function ingestion(): object
                {
                    return (object) ['categorizeWebDl' => $this->categorizeWebDl];
                }
            });

            $requests = [
                Request::create('/', 'GET'),
                Request::create('/', 'GET', ['cat' => '']),
                Request::create('/', 'GET', ['cat' => '   ']),
                Request::create('/', 'GET', ['cat' => '+']),
                Request::create('/', 'GET', ['cat' => '%20']),
                Request::create('/', 'GET', ['cat' => '%00']),
                Request::create('/', 'GET', ['cat' => ',,,']),
                Request::create('/', 'GET', ['cat' => '2000']),
                Request::create('/', 'GET', ['cat' => ' 2000 , 5030 , , 7020 ']),
                Request::create('/', 'GET', ['cat' => '2000%2C5040%2C5030']),
                Request::create('/', 'GET', ['cat' => '5040']),
                Request::create('/', 'GET', ['cat' => '5040,5010']),
                Request::create('/', 'GET', ['cat' => '50400']),
                Request::create('/', 'GET', ['cat' => '2000%2']),
                Request::create('/', 'GET', ['cat' => '2000%ZZ']),
                Request::create('/', 'GET', ['cat' => "\x0c2000\x0c,5030"]),
                Request::create('/', 'GET', ['cat' => ['2000', ' 5040 ', '', '  ', '5030']]),
                Request::create('/', 'GET', ['cat' => []]),
                Request::create('/', 'GET', ['cat' => ['', '   ']]),
                Request::create('/', 'GET', ['cat' => ['+']]),
                Request::create('/', 'GET', ['cat' => implode(',', range(1000, 1200))]),
            ];

            foreach ($requests as $idx => $req) {
                ApiQueryParameters::setUseRustFfi(true);
                $expected = $this->queryParams->categoriesPhp($req);
                $supported = false;
                $native = $this->queryParams->categoriesNative($req, $supported);
                self::assertTrue($supported, "Expected categoriesNative supported=true at case #{$idx} (webDl={$webDlSetting})");
                self::assertSame($expected, $native, "categoriesNative mismatch at case #{$idx} (webDl={$webDlSetting})");
                self::assertSame($expected, $this->queryParams->categories($req));

                ApiQueryParameters::setUseRustFfi(false);
                $supportedDisabled = true;
                self::assertNull($this->queryParams->categoriesNative($req, $supportedDisabled));
                self::assertFalse($supportedDisabled);
            }

            // Embedded newline inside a token declines native and falls back to PHP in categories()
            $newlineReq = Request::create('/', 'GET', ['cat' => '2000%0A3000,5030']);
            ApiQueryParameters::setUseRustFfi(true);
            $supported = true;
            self::assertNull($this->queryParams->categoriesNative($newlineReq, $supported));
            self::assertFalse($supported);
            self::assertSame(
                $this->queryParams->categoriesPhp($newlineReq),
                $this->queryParams->categories($newlineReq)
            );
        }

        $sortInputs = [
            null,
            '',
            '   ',
            " \t\n\r\0\x0B ",
            'posted_desc',
            'POSTED_ASC',
            ' name_asc ',
            "\tCat_Desc\r\n",
            'SIZE_ASC',
            'size_desc',
            'files_asc',
            'FILES_DESC',
            'stats_asc',
            'stats_desc',
            "name_asc\n",
            "name_asc\n\n",
            "\x0cname_asc",
            'name_asc_extra',
            'unknown_asc',
            str_repeat('a', 600),
        ];

        foreach ($sortInputs as $idx => $sortVal) {
            $req = $sortVal === null
                ? Request::create('/', 'GET')
                : Request::create('/', 'GET', ['sort' => $sortVal]);

            ApiQueryParameters::setUseRustFfi(true);
            $supportedSort = false;
            $supportedValid = false;
            $expectedSort = $this->queryParams->sortPhp($req);
            $expectedValid = $this->queryParams->hasValidSortPhp($req);

            self::assertSame($expectedSort, $this->queryParams->sortNative($req, $supportedSort), "sortNative mismatch at #{$idx}");
            self::assertTrue($supportedSort);
            self::assertSame($expectedSort, $this->queryParams->sort($req));

            self::assertSame($expectedValid, $this->queryParams->hasValidSortNative($req, $supportedValid), "hasValidSortNative mismatch at #{$idx}");
            self::assertTrue($supportedValid);
            self::assertSame($expectedValid, $this->queryParams->hasValidSort($req));
        }
    }

    #[Test]
    public function api_v2_controller_sanitizers_match_php(): void
    {
        $intCases = [
            0,
            1,
            42,
            999999999,
            1000000000,
            PHP_INT_MAX,
            -1,
            PHP_INT_MIN,
            '0',
            '1',
            '999999999',
            '000000000',
            '1000000000',
            '0000000000',
            ' 42 ',
            " \t\n\r\0\x0B12345\t\n ",
            "\x0c123",
            '-1',
            '+1',
            '12.3',
            '',
            '   ',
            'abc',
            true,
            false,
            null,
            12.0,
            ['1'],
            (object) [],
        ];

        ApiV2Controller::setUseRustFfi(true);
        foreach ($intCases as $idx => $val) {
            $expected = $this->apiV2Controller->isNonNegativeIntegerPhp($val);
            $supported = false;
            $native = $this->apiV2Controller->isNonNegativeIntegerNative($val, $supported);
            self::assertTrue($supported, "isNonNegativeIntegerNative should support case #{$idx}");
            self::assertSame($expected, $native, "isNonNegativeIntegerNative mismatch at case #{$idx}");
            self::assertSame($expected, $this->apiV2Controller->isNonNegativeInteger($val));
        }

        $sortRequests = [
            Request::create('/api/v2/search', 'GET'),
            Request::create('/api/v2/search', 'GET', ['sort' => '']),
            Request::create('/api/v2/search', 'GET', ['sort' => '   ']),
            Request::create('/api/v2/search', 'GET', ['sort' => 'invalid_sort']),
            Request::create('/api/v2/search', 'GET', ['sort' => "\x0cname_asc"]),
            Request::create('/api/v2/search', 'GET', ['sort' => ' NAME_ASC ']),
            Request::create('/api/v2/search', 'GET', ['sort' => "posted_asc\n"]),
            Request::create('/api/v2/search', 'GET', ['sort' => 'FILES_DESC']),
        ];

        foreach ($sortRequests as $idx => $req) {
            $expected = $this->apiV2Controller->parseSortPhp($req);
            $supported = false;
            $native = $this->apiV2Controller->parseSortNative($req, $supported);
            self::assertTrue($supported, "parseSortNative should support case #{$idx}");
            if ($expected instanceof JsonResponse) {
                self::assertInstanceOf(JsonResponse::class, $native);
                self::assertSame($expected->getStatusCode(), $native->getStatusCode());
                self::assertSame($expected->getData(true), $native->getData(true));
            } else {
                self::assertSame($expected, $native);
            }
        }

        $airDateCases = [
            ['2024', '03/15'],
            ['1999', '12/31'],
            ["2024\n", '03/15'],
            ["2024\n\n", '03/15'],
            [' 2024', '03/15'],
            ['1899', '03/15'],
            ['2100', '03/15'],
            ['S01', 'E02'],
            ['2024', 'E02'],
            ['2024', '03-15'],
            ['2024', ''],
            ['', '03/15'],
            ['2025', str_repeat('01/', 200).'02'],
        ];

        foreach ($airDateCases as $idx => [$series, $episode]) {
            $expected = $this->apiV2Controller->combineTvAirDatePhp($series, $episode);
            $supported = false;
            $native = $this->apiV2Controller->combineTvAirDateNative($series, $episode, $supported);
            self::assertTrue($supported, "combineTvAirDateNative should support case #{$idx}");
            self::assertSame($expected, $native, "combineTvAirDateNative mismatch at case #{$idx}");
            self::assertSame($expected, $this->apiV2Controller->combineTvAirDate($series, $episode));
        }
    }

    #[Test]
    public function base_page_controller_return_url_page_and_integer_input_match_php(): void
    {
        $originalContainer = Container::getInstance();
        $container = new Container;
        $container->instance(
            UrlGeneratorContract::class,
            new UrlGenerator(new RouteCollection, Request::create('https://nntmux.test')),
        );
        Container::setInstance($container);

        try {
            BasePageController::setUseRustFfi(true);

            $fromUrls = [
                null,
                '',
                '   ',
                '/browse',
                '  /browse?view=covers&page=2  ',
                '/browse?next=https://external.example:8080/path',
                '/browse#section:80/sub',
                'browse/sub?a=1',
                '//external.example/browse',
                '///external.example',
                '/browse\\evil',
                "/browse\x00evil",
                "/browse\x1fevil",
                "/browse\x7fevil",
                '/browse with space',
                'https://nntmux.test/browse?view=covers',
                'HTTPS://NNTMUX.TEST/browse',
                'https://nntmux.test:443/browse',
                'https://nntmux.test:/browse',
                'https://nntmux.test:8443/browse',
                'http://nntmux.test/browse',
                'https://external.example/browse',
                'https://user:pass@nntmux.test/browse',
                'https://user@nntmux.test/browse',
                'https://@nntmux.test/browse',
                'https://nntmux.test:0/browse',
                'https://nntmux.test:65536/browse',
                'https://nntmux.test:080/browse',
                'https://[::1]:443/browse',
                'http://',
                'javascript:alert(1)',
                'data:text/html,hi',
                '/browse/12:00/foo',
                'localhost:8080/browse',
            ];

            foreach ($fromUrls as $idx => $from) {
                $req = $from === null
                    ? Request::create('https://nntmux.test/mymovies', 'GET')
                    : Request::create('https://nntmux.test/mymovies', 'GET', ['from' => $from]);

                $expected = $this->basePageController->localReturnUrlPhp($req, '/mymovies');
                $supported = false;
                $native = $this->basePageController->localReturnUrlNative($req, '/mymovies', 'from', $supported);
                if ($supported) {
                    self::assertSame($expected, $native, "localReturnUrlNative mismatch at case #{$idx}: ".json_encode($from));
                }

                $refMethod = (new ReflectionClass(BasePageController::class))->getMethod('localReturnUrl');
                self::assertSame($expected, $refMethod->invoke($this->basePageController, $req, '/mymovies'), "localReturnUrl mismatch at case #{$idx}");
            }

            $scalarCases = [
                null,
                '',
                '0',
                '00',
                '1',
                '42',
                "42\n",
                "42\n\n",
                ' 42',
                '42 ',
                '-1',
                '-42',
                "-42\n",
                '+42',
                '3.5',
                '9223372036854775807',
                '-9223372036854775807',
                '-9223372036854775808',
                '9223372036854775808',
                '-9223372036854775809',
                str_repeat('9', 350),
                ['2'],
                true,
                false,
                15,
                -4,
            ];

            $resolvePageRef = (new ReflectionClass(BasePageController::class))->getMethod('resolvePage');
            $integerInputRef = (new ReflectionClass(BasePageController::class))->getMethod('integerInput');

            foreach ($scalarCases as $idx => $val) {
                $req = $val === null
                    ? Request::create('/browse', 'GET')
                    : Request::create('/browse', 'GET', ['page' => $val, 't' => $val]);

                $expectedPage = $this->basePageController->resolvePagePhp($req);
                $supportedPage = false;
                $nativePage = $this->basePageController->resolvePageNative($req, 'page', $supportedPage);
                if ($supportedPage) {
                    self::assertSame($expectedPage, $nativePage, "resolvePageNative mismatch at #{$idx}");
                }
                self::assertSame($expectedPage, $resolvePageRef->invoke($this->basePageController, $req), "resolvePage mismatch at #{$idx}");

                $expectedInt = $this->basePageController->integerInputPhp($req, 't', 2000);
                $supportedInt = false;
                $nativeInt = $this->basePageController->integerInputNative($req, 't', 2000, $supportedInt);
                if ($supportedInt) {
                    self::assertSame($expectedInt, $nativeInt, "integerInputNative mismatch at #{$idx}");
                }
                self::assertSame($expectedInt, $integerInputRef->invoke($this->basePageController, $req, 't', 2000), "integerInput mismatch at #{$idx}");
            }
        } finally {
            Container::setInstance($originalContainer);
        }
    }

    #[Test]
    public function series_controller_title_bucketing_and_season_resolution_match_php(): void
    {
        SeriesController::setUseRustFfi(true);

        $titles = [
            '24',
            '0-9',
            '9-1-1',
            '1899',
            'Breaking Bad',
            'the office',
            'Zorro',
            "'Allo 'Allo!",
            '#1 Show',
            '[The Office]',
            'Élite',
            'Ñandú',
            ' - 123',
            '???',
            '東京',
            '',
            "\nAlpha",
            "123\nBeta",
        ];

        $rows = [];
        foreach ($titles as $idx => $title) {
            $expectedBucket = $this->seriesController->classifyTitleBucketPhp($title);
            $supported = false;
            $nativeBucket = $this->seriesController->classifyTitleBucketNative($title, $supported);
            self::assertTrue($supported);
            self::assertSame($expectedBucket, $nativeBucket, "classifyTitleBucketNative mismatch at #{$idx}");
            self::assertSame($expectedBucket, $this->seriesController->classifyTitleBucket($title));

            $rows[] = ['id' => $idx + 1, 'title' => $title];
        }

        $expectedGrouped = $this->seriesController->groupSeriesByBucketPhp($rows);
        $supportedGroup = false;
        $nativeGrouped = $this->seriesController->groupSeriesByBucketNative($rows, $supportedGroup);
        self::assertTrue($supportedGroup);
        self::assertSame($expectedGrouped, $nativeGrouped);
        self::assertSame($expectedGrouped, $this->seriesController->groupSeriesByBucket($rows));

        // Large list > 1024 items exercises obtainScratch resize path
        $largeRows = [];
        for ($i = 0; $i < 1200; $i++) {
            $largeRows[] = ['id' => $i + 1, 'title' => $titles[$i % count($titles)].' '.$i];
        }
        $supportedLarge = false;
        self::assertSame(
            $this->seriesController->groupSeriesByBucketPhp($largeRows),
            $this->seriesController->groupSeriesByBucketNative($largeRows, $supportedLarge)
        );
        self::assertTrue($supportedLarge);

        // NUL byte in title declines batch and falls back cleanly to PHP
        $nulRows = [
            ['id' => 1, 'title' => "Bad\0Title"],
            ['id' => 2, 'title' => 'Good Title'],
        ];
        $supportedNul = true;
        self::assertNull($this->seriesController->groupSeriesByBucketNative($nulRows, $supportedNul));
        self::assertFalse($supportedNul);
        self::assertSame(
            $this->seriesController->groupSeriesByBucketPhp($nulRows),
            $this->seriesController->groupSeriesByBucket($nulRows)
        );

        $seasonValues = [
            null,
            '',
            '0',
            '1',
            '02',
            '2024',
            "3\n",
            "3\n\n",
            ' 3',
            '-1',
            '+1',
            'S01',
            '9223372036854775807',
            '9223372036854775808',
            ['1'],
        ];

        foreach ($seasonValues as $idx => $season) {
            $req = $season === null
                ? Request::create('/series/1', 'GET')
                : Request::create('/series/1', 'GET', ['season' => $season]);

            $expectedSeason = $this->seriesController->resolveSeasonPhp($req);
            $supportedSeason = false;
            $nativeSeason = $this->seriesController->resolveSeasonNative($req, $supportedSeason);
            if ($supportedSeason) {
                self::assertSame($expectedSeason, $nativeSeason, "resolveSeasonNative mismatch at #{$idx}");
            }
            self::assertSame($expectedSeason, $this->seriesController->resolveSeason($req), "resolveSeason mismatch at #{$idx}");
        }
    }

    #[Test]
    public function randomized_fuzz_and_locale_restoration_parity(): void
    {
        $startupAsciiTables = preg_match('/^\s$/', "\xa0") === 0;
        $prevLocale = setlocale(LC_CTYPE, '0') ?: 'C';
        try {
            setlocale(LC_CTYPE, 'C');
            $rng = new Randomizer(new Mt19937(20261024));
            $alphabet = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ,%/+-_:#?@\t\n\r ";
            $alphaLen = strlen($alphabet);

            ApiQueryParameters::setUseRustFfi(true);
            ApiV2Controller::setUseRustFfi(true);
            BasePageController::setUseRustFfi(true);
            SeriesController::setUseRustFfi(true);

            $this->app->instance(ConfigurationProvider::class, new class
            {
                public function ingestion(): object
                {
                    return (object) ['categorizeWebDl' => 0];
                }
            });

            for ($i = 0; $i < 250; $i++) {
                $len = $rng->getInt(0, 48);
                $s = '';
                for ($j = 0; $j < $len; $j++) {
                    $s .= $alphabet[$rng->getInt(0, $alphaLen - 1)];
                }

                $req = Request::create('https://nntmux.test/browse', 'GET', [
                    'cat' => $s,
                    'sort' => $s,
                    'page' => $s,
                    't' => $s,
                    'season' => $s,
                    'from' => $s,
                ]);

                self::assertSame($this->queryParams->categoriesPhp($req), $this->queryParams->categories($req));
                self::assertSame($this->queryParams->sortPhp($req), $this->queryParams->sort($req));
                self::assertSame($this->queryParams->hasValidSortPhp($req), $this->queryParams->hasValidSort($req));
                self::assertSame($this->apiV2Controller->isNonNegativeIntegerPhp($s), $this->apiV2Controller->isNonNegativeInteger($s));
                self::assertSame($this->seriesController->classifyTitleBucketPhp($s), $this->seriesController->classifyTitleBucket($s));
                self::assertSame($this->seriesController->resolveSeasonPhp($req), $this->seriesController->resolveSeason($req));
            }
        } finally {
            setlocale(LC_CTYPE, 'C');
            setlocale(LC_CTYPE, $prevLocale);
            if ($startupAsciiTables) {
                setlocale(LC_CTYPE, 'C');
            }
        }
    }

    #[Test]
    public function paired_randomized_microbenchmark(): void
    {
        if (getenv('RUN_RUST_FFI_BENCHMARKS') !== '1') {
            self::markTestSkipped('Set RUN_RUST_FFI_BENCHMARKS=1 to run paired randomized benchmarks.');
        }

        $titlesPool = ['24', '1899', 'Breaking Bad', 'The Office (US)', "'Allo 'Allo!", '#1 Show', 'Élite', 'Mr. Robot', 'Doctor Who', ' - 123'];
        $seriesCommon = [];
        for ($i = 0; $i < 40; $i++) {
            $seriesCommon[] = ['id' => $i + 1, 'title' => $titlesPool[$i % count($titlesPool)].' '.$i];
        }
        $seriesUncommon = [];
        for ($i = 0; $i < 250; $i++) {
            $seriesUncommon[] = ['id' => $i + 1, 'title' => $titlesPool[$i % count($titlesPool)].' Extended Series Title '.$i];
        }

        $rng = new Randomizer(new Mt19937(20261024));
        $iterations = 3000;
        foreach ([
            'SeriesController::groupSeriesByBucket[common]' => [$seriesCommon],
            'SeriesController::groupSeriesByBucket[uncommon]' => [$seriesUncommon],
        ] as $label => [$input]) {
            for ($w = 0; $w < 200; $w++) {
                $this->seriesController->groupSeriesByBucketNative($input);
                $this->seriesController->groupSeriesByBucketPhp($input);
            }
            $rTimes = [];
            $pTimes = [];
            for ($i = 0; $i < $iterations; $i++) {
                if ($rng->getInt(0, 1) === 0) {
                    $t0 = hrtime(true);
                    $this->seriesController->groupSeriesByBucketNative($input);
                    $rTimes[] = hrtime(true) - $t0;

                    $t1 = hrtime(true);
                    $this->seriesController->groupSeriesByBucketPhp($input);
                    $pTimes[] = hrtime(true) - $t1;
                } else {
                    $t1 = hrtime(true);
                    $this->seriesController->groupSeriesByBucketPhp($input);
                    $pTimes[] = hrtime(true) - $t1;

                    $t0 = hrtime(true);
                    $this->seriesController->groupSeriesByBucketNative($input);
                    $rTimes[] = hrtime(true) - $t0;
                }
            }
            sort($rTimes);
            sort($pTimes);
            $rMean = array_sum($rTimes) / $iterations;
            $pMean = array_sum($pTimes) / $iterations;
            $idx99 = (int) floor($iterations * 0.99);
            $meanSpeedup = $pMean / max(1.0, $rMean);
            $p99Speedup = $pTimes[$idx99] / max(1, $rTimes[$idx99]);
            self::assertGreaterThanOrEqual(1.5, $meanSpeedup, "{$label} mean speedup");
            self::assertGreaterThanOrEqual(1.5, $p99Speedup, "{$label} p99 speedup");
        }
    }
}
