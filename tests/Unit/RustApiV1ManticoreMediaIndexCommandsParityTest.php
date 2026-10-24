<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\CreateManticoreIndexes;
use App\Console\Commands\CreateMediaIndexes;
use App\Console\Commands\InspectManticore;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\ApiHelpController;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Illuminate\Http\Request;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Parity, C-ABI contract, fallback, and paired benchmark verification for Wave 29 Slice 29.5:
 * ApiController, ApiHelpController, InspectManticore, CreateManticoreIndexes and
 * CreateMediaIndexes helpers.
 *
 * Benchmarks run only with NNTMUX_APIV1_MANTICORE_MEDIA_BENCH=1:
 *   CARGO_TARGET_DIR=/tmp/cargo-w29-5 cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release
 *   NNTMUX_PIPELINE_FFI_LIB=/tmp/cargo-w29-5/release/libnntmux_pipeline.dylib \
 *     NNTMUX_APIV1_MANTICORE_MEDIA_BENCH=1 php artisan test --compact tests/Unit/RustApiV1ManticoreMediaIndexCommandsParityTest.php
 *
 * Paired randomized benchmark evidence (3 repetitions x 41 paired samples x 200 inner loops,
 * worst-repetition PHP latency / Rust latency, FFI call overhead included):
 *
 * Apple M5 (arm64), PHP 8.5.12 NTS, release cdylib, base commit 07548662f. Raw per-repetition
 * JSON lines are written to STDERR by the benchmark test. Only ApiController::tvAirDate's
 * uncommon (long episode) workload exceeds 1.5x; its common path does not, and no other helper
 * reaches 1.5x, so every class keeps the PHP default.
 *
 * | Method                                         | Common (Mean / P99) | Uncommon (Mean / P99) | >= 1.5x | Default     |
 * |------------------------------------------------|---------------------|-----------------------|---------|-------------|
 * | ApiController::apiFunctionName                 | 0.08x / 0.07x       | 0.10x / 0.05x         | No      | PHP (false) |
 * | ApiController::isQuerySafeFunction             | 0.25x / 0.15x       | 0.13x / 0.22x         | No      | PHP (false) |
 * | ApiController::normalizeSortValue              | 0.29x / 0.35x       | 0.55x / 0.37x         | No      | PHP (false) |
 * | ApiController::maxAgeValue                     | 0.23x / 0.20x       | 0.18x / 0.04x         | No      | PHP (false) |
 * | ApiController::tvAirDate                       | 0.44x / 0.29x       | 5.53x / 4.04x         | No      | PHP (false) |
 * | ApiHelpController::helpViewMetadata            | 0.05x / 0.02x       | 0.04x / 0.01x         | No      | PHP (false) |
 * | InspectManticore::extractVersion               | 0.41x / 0.40x       | 0.69x / 0.47x         | No      | PHP (false) |
 * | InspectManticore::isSafeTableName              | 0.16x / 0.12x       | 0.42x / 0.50x         | No      | PHP (false) |
 * | InspectManticore::tableNeedsRebuild            | 0.24x / 0.17x       | 0.30x / 0.28x         | No      | PHP (false) |
 * | CreateManticoreIndexes::selectedIndexes        | 0.14x / 0.07x       | 0.31x / 0.35x         | No      | PHP (false) |
 * | CreateManticoreIndexes::unknownIndexes         | 0.05x / 0.03x       | 0.07x / 0.08x         | No      | PHP (false) |
 * | CreateManticoreIndexes::resolveIndexName       | 0.14x / 0.14x       | 0.17x / 0.06x         | No      | PHP (false) |
 * | CreateManticoreIndexes::createErrorKind        | 0.33x / 0.39x       | 0.29x / 0.33x         | No      | PHP (false) |
 * | CreateMediaIndexes::manticoreMediaSchemas      | 0.01x / 0.01x       | 0.01x / 0.00x         | No      | PHP (false) |
 * | CreateMediaIndexes::elasticsearchMediaMappings | 0.00x / 0.00x       | 0.01x / 0.01x         | No      | PHP (false) |
 * | CreateMediaIndexes::mediaDriver                | 0.24x / 0.14x       | 0.16x / 0.06x         | No      | PHP (false) |
 */
class RustApiV1ManticoreMediaIndexCommandsParityTest extends TestCase
{
    /** @var list<class-string> */
    private const array TOGGLED_CLASSES = [
        ApiController::class,
        ApiHelpController::class,
        InspectManticore::class,
        CreateManticoreIndexes::class,
        CreateMediaIndexes::class,
    ];

    private const int DECLINE = -2147483648;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetFfiState();
        if (self::library(ApiController::FFI_CDEF) === null) {
            $this->markTestSkipped('Rust pipeline library is not built; run cargo build --release.');
        }
        $this->useLibraryUnderTest();
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

    private static function library(string $cdef): ?FFI
    {
        $path = getenv('NNTMUX_PIPELINE_FFI_LIB');

        return RustPipelineLibrary::resolveDomain('search', $cdef, \is_string($path) && $path !== '' ? $path : null);
    }

    /**
     * Point every toggled class at the library under test.
     */
    private function useLibraryUnderTest(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $ffi = self::library($class::FFI_CDEF);
            $this->assertNotNull($ffi, $class);
            $class::setFfiForTesting($ffi);
        }
    }

    private static function ffiCall(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    /**
     * Assert the PHP helper returns `$expected` and every other path agrees with it.
     *
     * @param  class-string  $class
     * @param  list<mixed>  $args
     */
    private function assertAllPaths(string $class, string $method, array $args, mixed $expected, string $message = ''): void
    {
        $this->assertSame($expected, $class::{$method.'Php'}(...$args), $message);
        $this->assertPathsAgree($class, $method, $args, $message);
    }

    /**
     * Assert the native helper and the toggled dispatcher in both modes match the PHP helper.
     *
     * @param  class-string  $class
     * @param  list<mixed>  $args
     */
    private function assertPathsAgree(string $class, string $method, array $args, string $message = ''): void
    {
        $php = $class::{$method.'Php'}(...$args);
        $this->assertSame($php, $class::{$method.'Native'}(...$args), $message);
        foreach ([true, false] as $use) {
            $class::setUseRustFfi($use);
            $this->assertSame($php, $class::$method(...$args), $message);
        }
        $class::setUseRustFfi(null);
    }

    private static function randomString(string $alphabet, int $maxLength): string
    {
        $length = mt_rand(0, $maxLength);
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[mt_rand(0, \strlen($alphabet) - 1)];
        }

        return $out;
    }

    /**
     * @param  list<string>  $fragments
     */
    private static function randomFragments(array $fragments, int $maxParts): string
    {
        $parts = mt_rand(0, $maxParts);
        $out = '';
        for ($i = 0; $i < $parts; $i++) {
            $out .= $fragments[mt_rand(0, \count($fragments) - 1)];
        }

        return $out;
    }

    public function test_production_defaults_follow_benchmark_gate(): void
    {
        $this->resetFfiState();
        foreach (self::TOGGLED_CLASSES as $class) {
            $this->assertFalse(
                $class::usesRustFfi(),
                "{$class} must keep PHP as default until its Rust path passes the 1.5x gate across common and uncommon mean/p99"
            );
        }
    }

    public function test_toggle_api_accepts_nullable_bool(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::setUseRustFfi(true);
            $this->assertTrue($class::usesRustFfi());
            $class::setUseRustFfi(false);
            $this->assertFalse($class::usesRustFfi());
            $class::setUseRustFfi(true);
            $class::setUseRustFfi(null);
            $this->assertFalse($class::usesRustFfi());
        }
    }

    // ---------------------------------------------------------------------
    // 1. ApiController
    // ---------------------------------------------------------------------

    public function test_api_function_alias_parity(): void
    {
        $expected = [
            'd' => 'd', 'details' => 'd', 'g' => 'g', 'get' => 'g', 's' => 's', 'search' => 's',
            'c' => 'c', 'caps' => 'c', 'tv' => 'tv', 'tvsearch' => 'tv', 'm' => 'm', 'movie' => 'm',
            'music' => 'music', 'audio' => 'music', 'b' => 'book', 'book' => 'book', 'anime' => 'anime',
            'gn' => 'n', 'n' => 'n', 'nfo' => 'n', 'info' => 'n', 'nzbadd' => 'nzbAdd',
        ];
        foreach ($expected as $alias => $canonical) {
            $this->assertAllPaths(ApiController::class, 'apiFunctionName', [(string) $alias], $canonical, (string) $alias);
        }
        foreach (['', 'D', 'Details', ' d', "d\n", "d\0", 'nzbAdd', 'r', 'x', 'searchx', 'é'] as $unknown) {
            $this->assertPathsAgree(ApiController::class, 'apiFunctionName', [$unknown], $unknown);
        }
        // Loose `switch` semantics for non-string request values stay on the PHP path.
        foreach ([true, false, null, 0, 1, 1.0, ['d']] as $value) {
            $this->assertPathsAgree(ApiController::class, 'apiFunctionName', [$value]);
        }
        $this->assertSame('d', ApiController::apiFunctionNameNative(true));

        mt_srand(2905);
        $fragments = ['d', 'g', 's', 'c', 'tv', 'm', 'b', 'n', 'gn', 'nfo', 'info', 'search', 'music', 'audio', 'nzb', 'add', 'D', ' '];
        for ($i = 0; $i < 400; $i++) {
            $this->assertPathsAgree(ApiController::class, 'apiFunctionName', [self::randomFragments($fragments, 3)]);
        }
    }

    public function test_query_safe_function_parity(): void
    {
        foreach (['s', 'tv', 'm', 'music', 'book', 'anime', 'd', 'c', 'n'] as $function) {
            $this->assertAllPaths(ApiController::class, 'isQuerySafeFunction', [$function], true, $function);
        }
        foreach (['g', 'nzbAdd', 'r', '', 'S', 'search', 'details', "s\0"] as $function) {
            $this->assertAllPaths(ApiController::class, 'isQuerySafeFunction', [$function], false, $function);
        }
    }

    public function test_sort_normalization_parity(): void
    {
        $cases = [
            'posted_desc' => ['status' => ApiController::SORT_VALID, 'sort' => 'posted_desc'],
            " NAME_ASC\n" => ['status' => ApiController::SORT_VALID, 'sort' => 'name_asc'],
            "\0\x0bStats_Desc\t\r" => ['status' => ApiController::SORT_VALID, 'sort' => 'stats_desc'],
            '' => ['status' => ApiController::SORT_EMPTY, 'sort' => ''],
            " \n\t" => ['status' => ApiController::SORT_EMPTY, 'sort' => ''],
            'posted' => ['status' => ApiController::SORT_INVALID, 'sort' => ''],
            "\fposted_desc" => ['status' => ApiController::SORT_INVALID, 'sort' => ''],
            'date_desc' => ['status' => ApiController::SORT_INVALID, 'sort' => ''],
            'posted_DÉSC' => ['status' => ApiController::SORT_INVALID, 'sort' => ''],
        ];
        foreach ($cases as $raw => $expected) {
            $this->assertAllPaths(ApiController::class, 'normalizeSortValue', [(string) $raw], $expected, json_encode($raw) ?: '');
        }

        mt_srand(2906);
        $fragments = ['cat', 'name', 'size', 'files', 'stats', 'posted', 'CAT', 'Posted', '_', 'asc', 'desc', 'ASC', ' ', "\n", "\t", "\0", "\f", 'x', 'é'];
        for ($i = 0; $i < 600; $i++) {
            $this->assertPathsAgree(ApiController::class, 'normalizeSortValue', [self::randomFragments($fragments, 5)]);
        }
    }

    public function test_max_age_parity(): void
    {
        $this->assertAllPaths(ApiController::class, 'maxAgeValue', [false, null], ['status' => ApiController::MAX_AGE_OK, 'value' => -1]);
        $this->assertAllPaths(ApiController::class, 'maxAgeValue', [false, 'x'], ['status' => ApiController::MAX_AGE_OK, 'value' => -1]);
        $strings = [
            '7', ' +12 ', "12\n", '-0', '-30', '0012', '999999999999999999', '9999999999999999999', '-9223372036854775808',
            '99999999999999999999999', '1.5', '.5', '5.', '1e3', ' -2.5E-1 ', '1e30', '-1e999', '',
            ' ', "\0", "\x0b", "\f", 'abc', '1a', '0x1A', '1e', '.', '-', '1 2', 'e5', "1\0", '١',
        ];
        foreach ($strings as $value) {
            $this->assertPathsAgree(ApiController::class, 'maxAgeValue', [true, $value], json_encode($value) ?: '');
        }
        foreach ([null, true, false, 0, 15, 1.9, [], ['1']] as $value) {
            $this->assertPathsAgree(ApiController::class, 'maxAgeValue', [true, $value]);
        }
        $this->assertSame(['status' => ApiController::MAX_AGE_OK, 'value' => 12], ApiController::maxAgeValueNative(true, ' +12 '));
        $this->assertSame(['status' => ApiController::MAX_AGE_EMPTY, 'value' => -1], ApiController::maxAgeValueNative(true, ' '));
        $this->assertSame(['status' => ApiController::MAX_AGE_NOT_NUMERIC, 'value' => -1], ApiController::maxAgeValueNative(true, "\f"));

        mt_srand(2907);
        for ($i = 0; $i < 800; $i++) {
            $this->assertPathsAgree(ApiController::class, 'maxAgeValue', [true, self::randomString("0123456789 +-.eE\t\n\fx", 22)]);
        }
    }

    public function test_tv_air_date_parity(): void
    {
        $cases = [
            ['2024', '10/31', '2024-10-31'],
            ["1999\n", '1/2/3', '1999-1-2-3'],
            ['2024', '/', '2024--'],
            ['2024', '10', ''],
            ['2124', '10/31', ''],
            ['1899', '10/31', ''],
            [' 2024', '10/31', ''],
            ["2024\n\n", '10/31', ''],
            ['', '', ''],
            ['2024', "\0/é", "2024-\0-é"],
        ];
        foreach ($cases as [$series, $episode, $expected]) {
            $this->assertAllPaths(ApiController::class, 'tvAirDate', [$series, $episode], $expected, json_encode([$series, $episode]) ?: '');
        }
        // A season that is not a year never inspects the episode.
        $this->assertAllPaths(ApiController::class, 'tvAirDate', ['', ['10/31']], '');
        $this->assertAllPaths(ApiController::class, 'tvAirDate', ['5', 3], '');

        mt_srand(2908);
        for ($i = 0; $i < 600; $i++) {
            $series = self::randomFragments(['19', '20', '21', '0', '9', "\n", ' '], 3);
            $episode = self::randomString("0123456789/-\n ", 8);
            $this->assertPathsAgree(ApiController::class, 'tvAirDate', [$series, $episode]);
        }
    }

    public function test_api_controller_request_callers_follow_helpers(): void
    {
        $controller = (new ReflectionClass(ApiController::class))->newInstanceWithoutConstructor();
        $requests = [
            Request::create('/api', 'GET'),
            Request::create('/api', 'GET', ['sort' => ' Size_Asc ', 'maxage' => ' 30 ']),
            Request::create('/api', 'GET', ['sort' => '', 'maxage' => '']),
            Request::create('/api', 'GET', ['sort' => 'bogus', 'maxage' => 'x']),
            Request::create('/api', 'GET', ['sort' => 'posted_desc', 'maxage' => '2.9']),
        ];
        foreach ($requests as $request) {
            $results = [];
            foreach ([false, true] as $use) {
                ApiController::setUseRustFfi($use);
                $sort = $controller->sort($request);
                $maxAge = $controller->maxAge($request);
                $results[] = [
                    $sort instanceof Response ? [$sort->getStatusCode(), $sort->getContent()] : $sort,
                    $maxAge instanceof Response ? [$maxAge->getStatusCode(), $maxAge->getContent()] : $maxAge,
                ];
            }
            $this->assertSame($results[0], $results[1]);
        }
        ApiController::setUseRustFfi(false);
        $this->assertSame('size_asc', $controller->sort($requests[1]));
        $this->assertSame(30, $controller->maxAge($requests[1]));
        $this->assertSame(2, $controller->maxAge($requests[4]));
        $this->assertSame('posted_desc', $controller->sort($requests[0]));
        $this->assertSame(-1, $controller->maxAge($requests[0]));
    }

    // ---------------------------------------------------------------------
    // 2. ApiHelpController
    // ---------------------------------------------------------------------

    public function test_help_view_metadata_parity(): void
    {
        $this->assertAllPaths(ApiHelpController::class, 'helpViewMetadata', [ApiHelpController::API_V1], [
            'title' => 'Api Help',
            'meta_title' => 'Api Help Topics',
            'meta_keywords' => 'view,nzb,api,details,help,json,rss,atom',
            'meta_description' => 'View description of the site Nzb Api.',
        ]);
        $this->assertAllPaths(ApiHelpController::class, 'helpViewMetadata', [ApiHelpController::API_V2], [
            'title' => 'Api V2 Help',
            'meta_title' => 'Api V2 Help Topics',
            'meta_keywords' => 'view,nzb,api,details,help,json,rss,atom',
            'meta_description' => 'View description of the site Nzb version 2 Api.',
        ]);
        foreach ([0, 3, -1, PHP_INT_MAX] as $version) {
            $calls = [
                'php' => static fn () => ApiHelpController::helpViewMetadataPhp($version),
                'native' => static fn () => ApiHelpController::helpViewMetadataNative($version),
                'dispatch' => static fn () => ApiHelpController::helpViewMetadata($version),
            ];
            foreach ($calls as $path => $call) {
                try {
                    $call();
                    $this->fail("{$path} helpViewMetadata({$version}) must throw");
                } catch (InvalidArgumentException $e) {
                    $this->assertSame("Unknown API help version {$version}.", $e->getMessage());
                }
            }
        }
    }

    // ---------------------------------------------------------------------
    // 3. InspectManticore
    // ---------------------------------------------------------------------

    public function test_extract_version_parity(): void
    {
        $responses = [
            [['version' => '6.3.6 1d8b2b3e5@24070216 dev']],
            ['version' => '13.11'],
            'v1.2.3.4',
            '1.x 2.5',
            '12..3 4.5.',
            '1.2.x',
            'no version',
            '',
            '7.',
            ['version' => "\xff"],
            NAN,
            ['nested' => ['deep' => ['v' => 'Manticore 10.1.0 é']]],
            3.25,
            42,
            null,
        ];
        foreach ($responses as $response) {
            $this->assertPathsAgree(InspectManticore::class, 'extractVersion', [$response], (string) json_encode($response, JSON_PARTIAL_OUTPUT_ON_ERROR));
        }
        $this->assertSame('6.3.6', InspectManticore::extractVersionNative($responses[0]));
        $this->assertSame('unknown', InspectManticore::extractVersionNative($responses[9]));

        mt_srand(2909);
        for ($i = 0; $i < 600; $i++) {
            $this->assertPathsAgree(InspectManticore::class, 'extractVersion', [self::randomString('0123456789..x "-', 16)]);
        }
    }

    public function test_table_name_safety_and_rebuild_status_parity(): void
    {
        foreach (['releases_rt', 'A1_b2', '_', '0', "releases_rt\n"] as $name) {
            $this->assertAllPaths(InspectManticore::class, 'isSafeTableName', [$name], true, json_encode($name) ?: '');
        }
        foreach (['', "\n", 'releases-rt', 'rel eases', "a\n\n", 'a;b', 'é', "a\0"] as $name) {
            $this->assertAllPaths(InspectManticore::class, 'isSafeTableName', [$name], false, json_encode($name) ?: '');
        }
        mt_srand(2910);
        for ($i = 0; $i < 600; $i++) {
            $this->assertPathsAgree(InspectManticore::class, 'isSafeTableName', [self::randomString("abZ09_-\n ;", 6)]);
        }

        foreach ([[], ['x']] as $missing) {
            foreach ([[], ['y' => 'text']] as $incompatible) {
                foreach ([[], ['min_infix_len']] as $settings) {
                    $this->assertAllPaths(
                        InspectManticore::class,
                        'tableNeedsRebuild',
                        [$missing, $incompatible, $settings],
                        $missing !== [] || $incompatible !== [] || $settings !== [],
                    );
                }
            }
        }
    }

    // ---------------------------------------------------------------------
    // 4. CreateManticoreIndexes
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function selectedIndexProvider(): array
    {
        return [
            'none' => [[]],
            'null' => [null],
            'single string' => ['releases'],
            'falsy strings' => [['releases', '', '0', 'predb', '00', ' ', '0.0']],
            'scalars' => [[0, 1, true, false, 2.5, 'x']],
            'keyed' => [['a' => 'releases', 'b' => '', 'c' => 'predb']],
            'only falsy' => [['', '0', false]],
        ];
    }

    #[DataProvider('selectedIndexProvider')]
    public function test_selected_indexes_parity(mixed $option): void
    {
        $this->assertPathsAgree(CreateManticoreIndexes::class, 'selectedIndexes', [$option]);
        $this->assertSame(array_values(array_filter(array_map('strval', (array) $option))), CreateManticoreIndexes::selectedIndexesNative($option));
    }

    public function test_unknown_indexes_and_names_parity(): void
    {
        $known = ['releases', 'predb', 'movies', 1];
        $cases = [
            [['releases', 'bogus', 'predb', 'Releases'], [1 => 'bogus', 3 => 'Releases']],
            [['releases'], []],
            [['1', 'movies', '01'], [2 => '01']],
            [['x' => 'bogus', 'y' => 'predb'], ['x' => 'bogus']],
            [[], []],
        ];
        foreach ($cases as [$selected, $expected]) {
            $this->assertAllPaths(CreateManticoreIndexes::class, 'unknownIndexes', [$selected, $known], $expected);
        }
        $this->assertAllPaths(CreateManticoreIndexes::class, 'unknownIndexes', [['a', 'b'], []], ['a', 'b']);

        mt_srand(2911);
        for ($i = 0; $i < 300; $i++) {
            $selected = [];
            for ($j = mt_rand(1, 6); $j > 0; $j--) {
                $selected[] = self::randomFragments(['releases', 'predb', 'movies', 'tv', '1', '_rt', 'x'], 2);
            }
            $this->assertPathsAgree(CreateManticoreIndexes::class, 'unknownIndexes', [$selected, $known]);
        }

        $configured = ['releases' => 'custom_releases', 'predb' => null, 'tv' => 5, 7 => 'seven'];
        foreach ([[$configured, 'releases', 'custom_releases'], [$configured, 'predb', 'predb_rt'], [$configured, 'tv', '5'], [$configured, 'movies', 'movies_rt'], [$configured, 7, 'seven'], [$configured, 8, '8_rt'], [null, 'releases', 'releases_rt'], [[], '', '_rt'], ['abc', 'releases', 'releases_rt']] as [$config, $logical, $expected]) {
            $this->assertAllPaths(CreateManticoreIndexes::class, 'resolveIndexName', [$config, $logical], $expected);
        }

        $messages = [
            "table 'x' already exists" => CreateManticoreIndexes::CREATE_ERROR_ALREADY_EXISTS,
            'data_dir missing; already exists' => CreateManticoreIndexes::CREATE_ERROR_ALREADY_EXISTS,
            'please set data_dir' => CreateManticoreIndexes::CREATE_ERROR_DATA_DIR,
            'Already Exists' => CreateManticoreIndexes::CREATE_ERROR_GENERIC,
            '' => CreateManticoreIndexes::CREATE_ERROR_GENERIC,
            "data\0_dir" => CreateManticoreIndexes::CREATE_ERROR_GENERIC,
        ];
        foreach ($messages as $message => $kind) {
            $this->assertAllPaths(CreateManticoreIndexes::class, 'createErrorKind', [(string) $message], $kind);
        }
    }

    // ---------------------------------------------------------------------
    // 5. CreateMediaIndexes
    // ---------------------------------------------------------------------

    public function test_media_schemas_and_driver_parity(): void
    {
        $this->assertPathsAgree(CreateMediaIndexes::class, 'manticoreMediaSchemas', []);
        $this->assertPathsAgree(CreateMediaIndexes::class, 'elasticsearchMediaMappings', []);
        $this->assertSame(['movies_rt', 'tvshows_rt'], array_keys(CreateMediaIndexes::manticoreMediaSchemasNative()));
        $this->assertSame(['type' => 'text', 'analyzer' => 'standard'], CreateMediaIndexes::elasticsearchMediaMappingsNative()['tvshows']['mappings']['properties']['title']);

        foreach (['manticore' => CreateMediaIndexes::DRIVER_MANTICORE, 'elasticsearch' => CreateMediaIndexes::DRIVER_ELASTICSEARCH] as $driver => $kind) {
            $this->assertAllPaths(CreateMediaIndexes::class, 'mediaDriver', [$driver], $kind);
        }
        foreach (['', 'Manticore', 'manticore ', 'meilisearch', null, 0, true, ['manticore']] as $driver) {
            $this->assertAllPaths(CreateMediaIndexes::class, 'mediaDriver', [$driver], CreateMediaIndexes::DRIVER_UNSUPPORTED);
        }
    }

    // ---------------------------------------------------------------------
    // C-ABI contract and fallback
    // ---------------------------------------------------------------------

    public function test_c_abi_pointer_and_capacity_contract(): void
    {
        $api = self::library(ApiController::FFI_CDEF);
        $help = self::library(ApiHelpController::FFI_CDEF);
        $inspect = self::library(InspectManticore::FFI_CDEF);
        $create = self::library(CreateManticoreIndexes::FFI_CDEF);
        $media = self::library(CreateMediaIndexes::FFI_CDEF);
        $this->assertNotNull($api);
        $this->assertNotNull($help);
        $this->assertNotNull($inspect);
        $this->assertNotNull($create);
        $this->assertNotNull($media);

        $out = $api->new('char[64]');
        FFI::memset($out, 0x5A, 64);
        $this->assertSame(self::DECLINE, self::ffiCall($api, 'nntmux_api_function_alias', null, 1, $out, 64));
        $this->assertSame(self::DECLINE, self::ffiCall($api, 'nntmux_api_function_alias', 'd', -1, $out, 64));
        $this->assertSame(self::DECLINE, self::ffiCall($api, 'nntmux_api_function_alias', 'd', 1, null, 64));
        $this->assertSame(self::DECLINE, self::ffiCall($api, 'nntmux_api_function_alias', 'd', 1, $out, -1));
        $this->assertSame(-6, self::ffiCall($api, 'nntmux_api_function_alias', 'nzbadd', 6, $out, 5));
        $this->assertSame(-1, self::ffiCall($api, 'nntmux_api_function_alias', 'c', 1, null, 0));
        $this->assertSame(-11, self::ffiCall($api, 'nntmux_api_sort_normalize', 'POSTED_DESC', 11, $out, 10));
        $this->assertSame(-10, self::ffiCall($api, 'nntmux_api_tv_air_date', '2024', 4, '10/31', 5, $out, 9));
        $this->assertSame(str_repeat("\x5A", 64), FFI::string($out, 64));
        $this->assertSame(self::DECLINE, self::ffiCall($api, 'nntmux_api_query_safe_function', null, 1));
        $this->assertSame(self::DECLINE, self::ffiCall($api, 'nntmux_api_sort_normalize', null, 1, $out, 64));
        $this->assertSame(self::DECLINE, self::ffiCall($api, 'nntmux_api_tv_air_date', '2024', 4, null, 3, $out, 64));

        $raw = $api->new('uint8_t[32]');
        $value = $api->new('int64_t[1]');
        $value[0] = 77;
        $this->assertSame(self::DECLINE, self::ffiCall($api, 'nntmux_api_max_age', '1', 1, null));
        $this->assertSame(self::DECLINE, self::ffiCall($api, 'nntmux_api_max_age', '1', 1, $api->cast('int64_t*', FFI::addr($raw[1]))));
        $this->assertSame(self::DECLINE, self::ffiCall($api, 'nntmux_api_max_age', null, 1, $value));
        $this->assertSame(self::DECLINE, self::ffiCall($api, 'nntmux_api_max_age', '1', -1, $value));
        $this->assertSame(77, $value[0]);
        $this->assertSame(0, self::ffiCall($api, 'nntmux_api_max_age', '42', 2, $value));
        $this->assertSame(42, $value[0]);

        $lens = $help->new('size_t[4]');
        $small = $help->new('char[8]');
        $this->assertSame(-99, self::ffiCall($help, 'nntmux_api_help_metadata', 1, $lens, $small, 8));
        $this->assertSame(self::DECLINE, self::ffiCall($help, 'nntmux_api_help_metadata', 3, $lens, $small, 8));
        $this->assertSame(self::DECLINE, self::ffiCall($help, 'nntmux_api_help_metadata', 1, null, $small, 8));
        $this->assertSame(self::DECLINE, self::ffiCall($help, 'nntmux_api_help_metadata', 1, $help->cast('size_t*', FFI::addr($raw[1])), $small, 8));
        $this->assertSame(self::DECLINE, self::ffiCall($help, 'nntmux_api_help_metadata', 1, $lens, null, 8));

        $this->assertSame(-7, self::ffiCall($inspect, 'nntmux_manticore_extract_version', 'x', 1, $small, 3));
        $this->assertSame(self::DECLINE, self::ffiCall($inspect, 'nntmux_manticore_extract_version', null, 1, $small, 8));
        $this->assertSame(self::DECLINE, self::ffiCall($inspect, 'nntmux_manticore_table_name_safe', null, 1));
        $this->assertSame(self::DECLINE, self::ffiCall($inspect, 'nntmux_manticore_table_name_safe', 'a', -1));
        $this->assertSame(1, self::ffiCall($inspect, 'nntmux_manticore_needs_rebuild', 0, 0, 1));

        $itemLens = $create->new('size_t[3]');
        foreach ([8, 0, 5] as $index => $length) {
            $itemLens[$index] = $length;
        }
        $indices = $create->new('size_t[3]');
        $this->assertSame(2, self::ffiCall($create, 'nntmux_manticore_selected_indexes', 'releasespredb', 13, $itemLens, 3, $indices, 3));
        $this->assertSame([0, 2], [(int) $indices[0], (int) $indices[1]]);
        $this->assertSame(-2, self::ffiCall($create, 'nntmux_manticore_selected_indexes', 'releasespredb', 13, $itemLens, 3, $indices, 1));
        $this->assertSame(self::DECLINE, self::ffiCall($create, 'nntmux_manticore_selected_indexes', 'releasespredb', 12, $itemLens, 3, $indices, 3));
        $this->assertSame(self::DECLINE, self::ffiCall($create, 'nntmux_manticore_selected_indexes', 'releasespredb', 13, null, 3, $indices, 3));
        $this->assertSame(self::DECLINE, self::ffiCall($create, 'nntmux_manticore_selected_indexes', 'releasespredb', 13, $create->cast('size_t*', FFI::addr($raw[1])), 1, $indices, 3));
        $this->assertSame(self::DECLINE, self::ffiCall($create, 'nntmux_manticore_selected_indexes', 'releasespredb', 13, $itemLens, 3, null, 3));
        $this->assertSame(0, self::ffiCall($create, 'nntmux_manticore_selected_indexes', null, 0, null, 0, null, 0));
        $this->assertSame(self::DECLINE, self::ffiCall($create, 'nntmux_manticore_unknown_indexes', 'releasespredb', 13, $itemLens, 3, 'releases', 8, null, 1, $indices, 3));
        $this->assertSame(-5, self::ffiCall($create, 'nntmux_manticore_default_index_name', 'tv', 2, $small, 4));
        $this->assertSame(self::DECLINE, self::ffiCall($create, 'nntmux_manticore_default_index_name', 'tv', 2, null, 8));
        $this->assertSame(self::DECLINE, self::ffiCall($create, 'nntmux_manticore_create_error_kind', null, 1));

        $this->assertLessThan(-100, self::ffiCall($media, 'nntmux_media_index_schema', 0, $small, 8));
        $this->assertSame(self::DECLINE, self::ffiCall($media, 'nntmux_media_index_schema', 2, $small, 8));
        $this->assertSame(self::DECLINE, self::ffiCall($media, 'nntmux_media_index_schema', 0, null, 8));
        $this->assertSame(self::DECLINE, self::ffiCall($media, 'nntmux_media_driver_kind', null, 1));
        $this->assertSame(self::DECLINE, self::ffiCall($media, 'nntmux_media_driver_kind', 'x', -1));
    }

    public function test_native_methods_fall_back_to_php_when_ffi_unavailable(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::setFfiForTesting(false);
            $class::setUseRustFfi(true);
        }

        $this->assertSame('nzbAdd', ApiController::apiFunctionName('nzbadd'));
        $this->assertNull(ApiController::apiFunctionName('bogus'));
        $this->assertFalse(ApiController::isQuerySafeFunction('g'));
        $this->assertSame(['status' => ApiController::SORT_VALID, 'sort' => 'cat_asc'], ApiController::normalizeSortValue(' CAT_ASC '));
        $this->assertSame(['status' => ApiController::MAX_AGE_OK, 'value' => 3], ApiController::maxAgeValue(true, '3.7'));
        $this->assertSame('2024-10-31', ApiController::tvAirDate('2024', '10/31'));
        $this->assertSame('Api V2 Help', ApiHelpController::helpViewMetadata(ApiHelpController::API_V2)['title']);
        $this->assertSame('6.3.6', InspectManticore::extractVersion(['version' => '6.3.6']));
        $this->assertFalse(InspectManticore::isSafeTableName('a-b'));
        $this->assertTrue(InspectManticore::tableNeedsRebuild([], [], ['x']));
        $this->assertSame(['releases'], CreateManticoreIndexes::selectedIndexes(['releases', '0']));
        $this->assertSame([1 => 'x'], CreateManticoreIndexes::unknownIndexes(['releases', 'x'], ['releases']));
        $this->assertSame('tv_rt', CreateManticoreIndexes::resolveIndexName([], 'tv'));
        $this->assertSame(CreateManticoreIndexes::CREATE_ERROR_DATA_DIR, CreateManticoreIndexes::createErrorKind('no data_dir'));
        $this->assertSame(CreateMediaIndexes::manticoreMediaSchemasPhp(), CreateMediaIndexes::manticoreMediaSchemas());
        $this->assertSame(CreateMediaIndexes::elasticsearchMediaMappingsPhp(), CreateMediaIndexes::elasticsearchMediaMappings());
        $this->assertSame(CreateMediaIndexes::DRIVER_ELASTICSEARCH, CreateMediaIndexes::mediaDriver('elasticsearch'));
    }

    // ---------------------------------------------------------------------
    // Paired Randomized Benchmarks
    // ---------------------------------------------------------------------

    public function test_paired_randomized_benchmarks(): void
    {
        if (getenv('NNTMUX_APIV1_MANTICORE_MEDIA_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_APIV1_MANTICORE_MEDIA_BENCH=1 to run paired Rust/PHP benchmarks.');
        }

        $longEpisode = str_repeat('10/31/', 200);
        $versionResponse = [['version' => '6.3.6 1d8b2b3e5@24070216 dev']];
        $versionUncommon = [['banner' => str_repeat('build x.y ', 200), 'version' => 'Manticore 10.1.0']];
        $longTable = str_repeat('releases_rt_', 100);
        $missingSettings = array_fill(0, 50, 'min_infix_len');
        $indexOption = ['releases'];
        $indexUncommon = [];
        $knownUncommon = [];
        foreach (range(1, 200) as $i) {
            $indexUncommon[] = $i % 7 === 0 ? '0' : "index_{$i}";
            $knownUncommon[] = "index_{$i}";
        }
        $known = ['releases', 'predb', 'movies', 'tvshows'];
        $configured = ['releases' => 'releases_rt'];
        $longMessage = str_repeat('Manticore error context ', 200).'data_dir';

        $workloads = [
            'ApiController::apiFunctionName' => [
                ApiController::class,
                static fn () => ApiController::apiFunctionName('search'),
                static fn () => ApiController::apiFunctionName('nzbadd'),
            ],
            'ApiController::isQuerySafeFunction' => [
                ApiController::class,
                static fn () => ApiController::isQuerySafeFunction('s'),
                static fn () => ApiController::isQuerySafeFunction('nzbAdd'),
            ],
            'ApiController::normalizeSortValue' => [
                ApiController::class,
                static fn () => ApiController::normalizeSortValue('posted_desc'),
                static fn () => ApiController::normalizeSortValue("  \t STATS_ASC \n".str_repeat(' ', 200)),
            ],
            'ApiController::maxAgeValue' => [
                ApiController::class,
                static fn () => ApiController::maxAgeValue(true, '30'),
                static fn () => ApiController::maxAgeValue(true, ' -2.5E+3 '),
            ],
            'ApiController::tvAirDate' => [
                ApiController::class,
                static fn () => ApiController::tvAirDate('2024', '10/31'),
                static fn () => ApiController::tvAirDate('2024', $longEpisode),
            ],
            'ApiHelpController::helpViewMetadata' => [
                ApiHelpController::class,
                static fn () => ApiHelpController::helpViewMetadata(ApiHelpController::API_V1),
                static fn () => ApiHelpController::helpViewMetadata(ApiHelpController::API_V2),
            ],
            'InspectManticore::extractVersion' => [
                InspectManticore::class,
                static fn () => InspectManticore::extractVersion($versionResponse),
                static fn () => InspectManticore::extractVersion($versionUncommon),
            ],
            'InspectManticore::isSafeTableName' => [
                InspectManticore::class,
                static fn () => InspectManticore::isSafeTableName('releases_rt'),
                static fn () => InspectManticore::isSafeTableName($longTable),
            ],
            'InspectManticore::tableNeedsRebuild' => [
                InspectManticore::class,
                static fn () => InspectManticore::tableNeedsRebuild([], [], []),
                static fn () => InspectManticore::tableNeedsRebuild([], [], $missingSettings),
            ],
            'CreateManticoreIndexes::selectedIndexes' => [
                CreateManticoreIndexes::class,
                static fn () => CreateManticoreIndexes::selectedIndexes($indexOption),
                static fn () => CreateManticoreIndexes::selectedIndexes($indexUncommon),
            ],
            'CreateManticoreIndexes::unknownIndexes' => [
                CreateManticoreIndexes::class,
                static fn () => CreateManticoreIndexes::unknownIndexes($indexOption, $known),
                static fn () => CreateManticoreIndexes::unknownIndexes($indexUncommon, $knownUncommon),
            ],
            'CreateManticoreIndexes::resolveIndexName' => [
                CreateManticoreIndexes::class,
                static fn () => CreateManticoreIndexes::resolveIndexName($configured, 'predb'),
                static fn () => CreateManticoreIndexes::resolveIndexName(null, str_repeat('logical_', 50)),
            ],
            'CreateManticoreIndexes::createErrorKind' => [
                CreateManticoreIndexes::class,
                static fn () => CreateManticoreIndexes::createErrorKind("table 'releases_rt' already exists"),
                static fn () => CreateManticoreIndexes::createErrorKind($longMessage),
            ],
            'CreateMediaIndexes::manticoreMediaSchemas' => [
                CreateMediaIndexes::class,
                static fn () => CreateMediaIndexes::manticoreMediaSchemas(),
                static fn () => CreateMediaIndexes::manticoreMediaSchemas(),
            ],
            'CreateMediaIndexes::elasticsearchMediaMappings' => [
                CreateMediaIndexes::class,
                static fn () => CreateMediaIndexes::elasticsearchMediaMappings(),
                static fn () => CreateMediaIndexes::elasticsearchMediaMappings(),
            ],
            'CreateMediaIndexes::mediaDriver' => [
                CreateMediaIndexes::class,
                static fn () => CreateMediaIndexes::mediaDriver('manticore'),
                static fn () => CreateMediaIndexes::mediaDriver('opensearch-unsupported-driver'),
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

                    $stats = $this->runPairedBenchmark($class, $fn, 200, 41, 2905 + $rep);
                    fwrite(STDERR, json_encode(['method' => $name, 'workload' => $kind, 'repetition' => $rep] + $stats).PHP_EOL);
                    $worst["{$kind}_mean"] = min($worst["{$kind}_mean"], $stats['mean_speedup']);
                    $worst["{$kind}_p99"] = min($worst["{$kind}_p99"], $stats['p99_speedup']);
                }
            }
            $class::setUseRustFfi(false);
            $qualified = min($worst) >= 1.5;
            $rows[] = sprintf(
                ' * | %-46s | %.2fx / %.2fx       | %.2fx / %.2fx         | %-7s | %-11s |',
                $name,
                $worst['common_mean'],
                $worst['common_p99'],
                $worst['uncommon_mean'],
                $worst['uncommon_p99'],
                $qualified ? 'Yes' : 'No',
                $qualified ? 'Rust (true)' : 'PHP (false)',
            );
        }
        $this->resetFfiState();
        foreach (self::TOGGLED_CLASSES as $class) {
            $this->assertFalse($class::usesRustFfi(), "{$class} must keep the PHP default");
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
        foreach ([true, false] as $use) {
            $class::setUseRustFfi($use);
            for ($w = 0; $w < 50; $w++) {
                $fn();
            }
        }

        $times = [1 => [], 0 => []];
        mt_srand($seed);
        for ($s = 0; $s < $samples; $s++) {
            $order = mt_rand(0, 1) === 0 ? [true, false] : [false, true];
            foreach ($order as $use) {
                $class::setUseRustFfi($use);
                $t0 = hrtime(true);
                for ($i = 0; $i < $innerLoops; $i++) {
                    $fn();
                }
                $times[(int) $use][] = (hrtime(true) - $t0) / 1000.0 / $innerLoops;
            }
        }

        $rust = $times[1];
        $php = $times[0];
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
