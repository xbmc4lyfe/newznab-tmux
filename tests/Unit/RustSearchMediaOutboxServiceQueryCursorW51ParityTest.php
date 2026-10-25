<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Search\DTO\ReleaseSearchQuery;
use App\Services\Search\DTO\SearchCursor;
use App\Services\Search\MediaSearchService;
use App\Services\Search\SearchIndexOutboxDispatcher;
use App\Services\Search\SearchService;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;
use RuntimeException;

/**
 * Paired randomized benchmark (`test_paired_randomized_benchmark_gates_rust_default`): per helper, 300
 * samples per workload (1000 with `NNTMUX_W51_BENCH=1`), 20 warmup rounds, per-sample random PHP/Rust
 * order, each side the fastest of three back-to-back calls, release dylib. Common workloads hold 64
 * records and uncommon ones 192 records with edge cases. Timings include PHP-side packing, the FFI call,
 * output decoding and PHP patches; each run prints its ratios to STDERR.
 *
 * A helper only defaults to Rust when every recorded common and uncommon mean and p99 ratio reaches 1.5x
 * (QUALIFICATION); each run asserts that the Rust defaults still clear 1.5x.
 */
class RustSearchMediaOutboxServiceQueryCursorW51ParityTest extends TestCase
{
    /**
     * Batch method and words per record per class; each has `<method>Php` and `<method>Native` variants.
     *
     * @var array<class-string, array{string, int}>
     */
    private const METHODS = [
        MediaSearchService::class => ['externalIdPlanBatch', 4],
        SearchIndexOutboxDispatcher::class => ['routeOutboxBatch', 5],
        SearchService::class => ['escapeQueryBatch', 4],
        ReleaseSearchQuery::class => ['normalizeRequestBatch', 5],
        SearchCursor::class => ['resumePlanBatch', 6],
    ];

    /**
     * Benchmark-qualified defaults (see the class docblock for the gate).
     *
     * @var array<class-string, bool>
     */
    private const DEFAULTS = [
        MediaSearchService::class => true,
        SearchIndexOutboxDispatcher::class => true,
        SearchService::class => true,
        ReleaseSearchQuery::class => true,
        SearchCursor::class => true,
    ];

    /**
     * Common mean/p99 then uncommon mean/p99 speedups (PHP / Rust), the per-metric minimum of three
     * `NNTMUX_W51_BENCH=1` runs (1000 samples per workload, seed 5152, release Rust, PHP 8.5.12 CLI without
     * opcache, Apple Silicon macOS on a shared host).
     *
     * @var array<class-string, array{float, float, float, float}>
     */
    private const QUALIFICATION = [
        MediaSearchService::class => [3.13, 2.68, 2.39, 2.27],
        SearchIndexOutboxDispatcher::class => [6.25, 5.25, 5.02, 4.65],
        SearchService::class => [4.81, 4.67, 5.92, 6.03],
        ReleaseSearchQuery::class => [3.12, 3.16, 3.65, 4.05],
        SearchCursor::class => [3.75, 3.12, 2.61, 2.27],
    ];

    /** Back-to-back calls per side of a sample; the fastest is kept (drops preemption spikes). */
    private const REPEATS = 3;

    private const RANDOM_ROUNDS = 400;

    private const BYTES = ['a', 'Z', '0', '1', '9', ' ', "\t", "\n", "\r", "\x0b", "\x0c", '-', '!', '.', '"', '\\', '/', '(', ')', '@', '~', '&', '$', '=', "'", '[', ']', '*', '+', 'e', 'x', '_', "\x01", "\x7f", "\0", "\xc3\xa9", "\xe2\x82\xac", "\xf0\x9f\x98\x80", "\xc3", "\xff"];

    private const TYPES = ['release', 'Releases', 'App\\Models\\Release', 'movie_info', 'Movie-Info', 'MovieInfo', 'tv show', 'Video', 'music', 'Music Info', 'steam_apps/', 'App/Models/SteamApp', 'anidb-titles', 'Console Info', 'secondary', 'Secondary Search', 'predb', 'PreDB', 'x/y\\z', '/', '', 'a//', "R\xc3\xa9lease"];

    private const ACTIONS = ['insert', 'Created', 'SAVE', 'index', 'Indexed', 'update', 'deleted', 'Remove', 'destroy', 'bogus act', '', 'upsert-ed', 'REPLACED'];

    private const NUMBERS = ['0', '-0', '+0', '00', '12', ' 12 ', "\t7\n", "\x0b8", "\x0c9", "\r10\r", '1 2', '-4', '+5', '9223372036854775807', '9223372036854775808', '-9223372036854775808', '0x1A', '1e3', '1.0', '', ' ', '+', '-', '007'];

    protected function tearDown(): void
    {
        foreach (array_keys(self::METHODS) as $class) {
            (new ReflectionClass($class))->setStaticPropertyValue('w51Ffi', null);
        }

        parent::tearDown();
    }

    /**
     * @return array<string, array{class-string, string, int}>
     */
    public static function helperProvider(): array
    {
        $cases = [];
        foreach (self::METHODS as $class => [$method, $words]) {
            $cases[(new ReflectionClass($class))->getShortName()] = [$class, $method, $words];
        }

        return $cases;
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_methods_defaults_and_scoped_toggles(string $class, string $method, int $words): void
    {
        $this->assertGreaterThan(0, $words);
        $reflection = new ReflectionClass($class);
        foreach (['usesW51RustFfi', 'withW51RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
            $this->assertTrue($reflection->hasMethod($name), "{$class}::{$name}");
            $this->assertTrue($reflection->getMethod($name)->isStatic() && $reflection->getMethod($name)->isPublic(), "{$class}::{$name}");
        }
        $constant = new ReflectionClassConstant($class, 'W51_RUST_FFI_DEFAULT');
        $this->assertTrue($constant->isPrivate(), "{$class} default must be private");
        $this->assertSame(self::DEFAULTS[$class], $constant->getValue(), "{$class} default must match the benchmark evidence");
        $this->assertSame(min(self::QUALIFICATION[$class]) >= 1.5, self::DEFAULTS[$class], "{$class} default must follow its recorded qualification");
        $this->assertSame(self::DEFAULTS[$class], $class::usesW51RustFfi());

        foreach ([true, false] as $enabled) {
            $this->assertSame([$enabled, 'ok'], $class::withW51RustFfi($enabled, static fn () => [$class::usesW51RustFfi(), 'ok']));
            $this->assertSame(self::DEFAULTS[$class], $class::usesW51RustFfi(), "{$class} must restore after the callback");
        }
        $nested = $class::withW51RustFfi(false, static fn () => [$class::withW51RustFfi(true, static fn () => $class::usesW51RustFfi()), $class::usesW51RustFfi()]);
        $this->assertSame([true, false], $nested);
        $caught = null;
        try {
            $class::withW51RustFfi(! $class::usesW51RustFfi(), static fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException $exception) {
            $caught = $exception->getMessage();
        }
        $this->assertSame('boom', $caught, 'The callback exception must propagate.');
        $this->assertSame(self::DEFAULTS[$class], $class::usesW51RustFfi(), "{$class} must restore after an exception");

        $class::withW51RustFfi(! $class::usesW51RustFfi(), function () use ($class): void {
            foreach (array_keys(self::METHODS) as $other) {
                if ($other !== $class) {
                    $this->assertSame(self::DEFAULTS[$other], $other::usesW51RustFfi(), "{$class} leaked into {$other}");
                }
            }
        });
    }

    public function test_earlier_wave_toggles_are_untouched(): void
    {
        $this->assertFalse(MediaSearchService::usesW415RustFfi());
        $this->assertFalse(SearchIndexOutboxDispatcher::usesW425RustFfi());
        $this->assertFalse(SearchService::usesW405RustFfi());
        $this->assertFalse(ReleaseSearchQuery::usesW415RustFfi());
        $this->assertFalse(SearchCursor::usesW415RustFfi());
        MediaSearchService::withW51RustFfi(false, fn () => $this->assertFalse(MediaSearchService::usesW415RustFfi()));
        SearchCursor::withW51RustFfi(true, fn () => $this->assertFalse(SearchCursor::usesRustFfi()));
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_entry_points_route_by_toggle(string $class, string $method, int $words): void
    {
        $this->requireFfi($class);
        $arguments = self::firstArguments($class);
        $php = $class::{$method.'Php'}(...$arguments);
        $this->assertCount($words * \count($arguments[0]), $php);
        $this->assertSame($php, $class::withW51RustFfi(false, static fn () => $class::{$method}(...$arguments)), $class);
        $this->assertSame($php, $class::withW51RustFfi(true, static fn () => $class::{$method}(...$arguments)), $class);
        $this->assertSame($php, $class::{$method.'Native'}(...$arguments), $class);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_empty_batches_return_no_words(string $class, string $method, int $words): void
    {
        $this->assertGreaterThan(0, $words);
        $arguments = self::firstArguments($class);
        foreach (array_keys($arguments) as $index) {
            if (\is_array($arguments[$index])) {
                $arguments[$index] = [];
            }
        }
        $this->assertSame([], $class::{$method.'Php'}(...$arguments));
        $this->assertSame([], $class::{$method.'Native'}(...$arguments));
    }

    public function test_media_external_id_plan_examples(): void
    {
        $movie = [
            ['imdbid' => '0111161', 'tmdbid' => 278, 'traktid' => 0, 'title' => 'The Shawshank Redemption'],
            'not a row',
            ['imdbid' => null, 'tmdbid' => '', 'traktid' => false],
        ];
        $first = 'imdbid=0111161&tmdbid=278';
        $expected = [3 | 2 << 8 | \strlen($first) << 20, 111161, self::xxh64($first), self::xxh64("imdbid:0111161\ntmdbid:278"), 1 << 16, 0, 0, 0, 1 << 12, 0, self::xxh64(''), self::xxh64('')];
        $this->assertBoth(MediaSearchService::class, [$movie, 'movie'], $expected);
        // A row without every field takes the field-by-field encoding.
        $this->assertBoth(MediaSearchService::class, [[...$movie, ['imdbid' => 5]], 'movie'], [...$expected, 1 | 1 << 8 | 8 << 20, 5, self::xxh64('imdbid=5'), self::xxh64('imdbid:5')]);

        $tv = 'tvdb=a+b%2F%C3%A9&traktid=1&tvrage=7';
        $this->assertBoth(MediaSearchService::class, [[['tvdb' => "a b/\u{e9}", 'trakt' => true, 'tvmaze' => '0', 'tvrage' => 7]], 'tv'], [11 | 3 << 8 | \strlen($tv) << 20, 0, self::xxh64($tv), self::xxh64("tvdb:0\ntraktid:1\ntvrage:7")]);
        $this->assertBoth(MediaSearchService::class, [[['tvdb' => 0, 'trakt' => null, 'tvmaze' => '', 'tvrage' => false]], 'anime'], [2 << 12, 0, self::xxh64(''), self::xxh64('')]);
        // Floats, arrays and PHP-cast IDs are patched by PHP.
        $this->assertBoth(MediaSearchService::class, [[['imdbid' => 1.5, 'tmdbid' => 1, 'traktid' => 1], ['imdbid' => ' 12', 'tmdbid' => 0, 'traktid' => 0], ['imdbid' => 'tt1', 'tmdbid' => '1e3', 'traktid' => 0], ['imdbid' => ['x'], 'tmdbid' => 0, 'traktid' => 0]], 'movie'], null);
    }

    public function test_outbox_route_examples(): void
    {
        $types = ['App\\Models\\Release', 'Music-Info', 'secondary', 'widget', "R\u{e9}lease"];
        $actions = ['Created', 'SAVE', 'removed', 'bogus action', 'update'];
        $expected = [];
        foreach ([[0, 'release:5', 'insert'], [1 | 5 << 3 | 1 << 6, 'secondary:music:6', 'upsert'], [3 | 4 << 3, 'secondary:7', 'delete'], [4 | 6 << 3, 'widget:-9', 'bogus_action'], [2 | 6 << 3, "r\u{e9}lease:10", 'update']] as [$flags, $key, $action]) {
            array_push($expected, $flags, \strlen($key), self::xxh64($key), \strlen($action), self::xxh64($action));
        }
        $this->assertBoth(SearchIndexOutboxDispatcher::class, [$types, $actions, [5, 6, 7, -9, 10]], $expected);
        $this->assertBoth(SearchIndexOutboxDispatcher::class, [['steam_apps/', 'a//', '', 'Video'], ['Index', 'x y', '', 'DESTROYED'], [1, 2, 3, PHP_INT_MIN]], null);
        // A NUL byte declines the batch to PHP.
        $this->assertBoth(SearchIndexOutboxDispatcher::class, [["rel\0ease"], ['insert'], [1]], null);
    }

    public function test_search_service_escape_examples(): void
    {
        $expected = [];
        foreach ([['The.Movie \\(2024\\) \\\\-\\-x\\', 'The Movie (2024) --x!'], ['', ''], ['', ''], ["..a\tb..", 'a b'], ['\\\\path\\/to\\=x\\-y', '\\path/to=x-y']] as [$manticore, $elastic]) {
            array_push($expected, \strlen($manticore), self::xxh64($manticore), \strlen($elastic), self::xxh64($elastic));
        }
        $this->assertBoth(SearchService::class, [['The.Movie (2024) --x!', '*', '0', " ..a\tb.. ", '\\path/to=x-y']], $expected);
        $this->assertBoth(SearchService::class, [["a\0b"]], null);
    }

    public function test_release_query_normalize_examples(): void
    {
        $this->assertBoth(
            ReleaseSearchQuery::class,
            [[' abc ', '-1', ''], ['size', 'bogus', 'id'], ['ASC', 'desc', 'asc '], ['12', 'x', '0'], ['', ' 7 ', '-3']],
            [2 | 8 | 16, 12, -1, 3, self::xxh64('abc'), 0, -1, 7, -1, 0, 7 | 8, 0, -1, -1, 0],
        );
        $this->assertBoth(ReleaseSearchQuery::class, [self::NUMBERS, array_fill(0, 24, 'grabs'), array_fill(0, 24, 'Asc'), self::NUMBERS, array_reverse(self::NUMBERS)], null);
    }

    public function test_search_cursor_resume_plan_examples(): void
    {
        $text = static fn (string $payload): array => [\strlen($payload), self::xxh64($payload)];
        $own = static fn (string $sort, int $total, string $query, string $driver, string $generation, int $expires): array => $text('{"sort":'.$sort.',"total":'.$total.',"query":"'.$query.'","driver":"'.$driver.'","generation":"'.$generation.'","expires":'.$expires.'}');
        $renewed = static fn (string $sort, int $total): array => $own($sort, $total, 'qh', 'drv', 'g1', 1000);
        $cursors = [
            new SearchCursor([17, "a/\u{e9}"], 9, 'qh', 'drv', 'g1', 200),
            new SearchCursor(['5'], 3, 'qh', 'es', 'g1', 100),
            new SearchCursor([], 0, 'qh', 'drv', "\xff", 50),
            new SearchCursor([1.5], 1, 'qh', 'drv', 'g1', 300),
            // @phpstan-ignore argument.type
            new SearchCursor(['x' => 4], 2, 'qx', 'drv', 'g1', 300),
            new SearchCursor([' 7'], 2, 'qh', 'drv', 'g2', 300),
        ];
        $this->assertBoth(SearchCursor::class, [$cursors, 'qh', 'drv', 'g1', 100], [
            2 << 3, 0, ...$own('[17,"a\/\u00e9"]', 9, 'qh', 'drv', 'g1', 200), ...$renewed('[17,"a\/\u00e9"]', 9),
            3 | 1 << 3, 5, ...$own('["5"]', 3, 'qh', 'es', 'g1', 100), ...$renewed('["5"]', 3),
            1, 0, -1, 0, ...$renewed('[]', 0),
            1 << 3, 1, ...$own('[1.5]', 1, 'qh', 'drv', 'g1', 300), ...$renewed('[1.5]', 1),
            2 | 1 << 3, 4, ...$own('{"x":4}', 2, 'qx', 'drv', 'g1', 300), ...$renewed('{"x":4}', 2),
            4 | 1 << 3, 7, ...$own('[" 7"]', 2, 'qh', 'drv', 'g2', 300), ...$renewed('[" 7"]', 2),
        ]);
        $this->assertBoth(SearchCursor::class, [\array_slice($cursors, 0, 2), 'qh', 'drv', 'g1', 100, 60], [
            2 << 3, 0, ...$own('[17,"a\/\u00e9"]', 9, 'qh', 'drv', 'g1', 200), ...$own('[17,"a\/\u00e9"]', 9, 'qh', 'drv', 'g1', 160),
            3 | 1 << 3, 5, ...$own('["5"]', 3, 'qh', 'es', 'g1', 100), ...$own('["5"]', 3, 'qh', 'drv', 'g1', 160),
        ]);
        // Invalid expected strings fail every renewed payload.
        $this->assertBoth(SearchCursor::class, [[new SearchCursor([1], 1, 'q', 'd', 'g', 9)], 'q', "d\xff", 'g', 0], [3 | 1 << 3, 1, ...$own('[1]', 1, 'q', 'd', 'g', 9), -1, 0]);
        // Invalid UTF-8 in a sort value or a NUL byte in a string declines the batch to PHP.
        $this->assertBoth(SearchCursor::class, [[new SearchCursor(["\xff"], 1, 'q', 'd', 'g', 9)], 'q', 'd', 'g', 0], [1 << 3, 0, -1, 0, -1, 0]);
        $this->assertBoth(SearchCursor::class, [[new SearchCursor([1], 1, "q\0", 'd', 'g', 9)], 'q', 'd', 'g', 0], null);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_every_helper_is_served_by_rust(string $class, string $method, int $words): void
    {
        $this->assertInstanceOf(FFI::class, $this->requireFfi($class));
        $this->assertCount($words * \count(self::firstArguments($class)[0]), $class::{$method.'Native'}(...self::firstArguments($class)));
        $this->assertInstanceOf(FFI::class, (new ReflectionClass($class))->getStaticPropertyValue('w51Ffi'), "{$class} must resolve the Rust helper");
    }

    public function test_large_batches_grow_the_output_buffer(): void
    {
        $this->requireFfi(SearchService::class);
        $queries = array_map(static fn (int $i): string => 'query-'.$i.'.x264--', range(0, 4999));
        $this->assertSame(SearchService::escapeQueryBatchPhp($queries), SearchService::escapeQueryBatchNative($queries));
        $this->assertSame(20001, (new ReflectionClass(SearchService::class))->getStaticPropertyValue('w51OutCap'));
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_native_helpers_fall_back_to_php_without_library(string $class, string $method, int $words): void
    {
        $reflection = new ReflectionClass($class);
        $reflection->setStaticPropertyValue('w51Ffi', false);
        $arguments = self::firstArguments($class);
        $this->assertCount($words * \count($arguments[0]), $class::{$method.'Native'}(...$arguments));
        $this->assertSame($class::{$method.'Php'}(...$arguments), $class::{$method.'Native'}(...$arguments), $class);
        $this->assertFalse($reflection->getStaticPropertyValue('w51Ffi'), "{$class} must keep the disabled handle");
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_direct_c_abi_declines_invalid_input_and_reports_needed_capacity(string $class, string $method, int $words): void
    {
        $this->assertNotSame('', $method);
        $ffi = $this->requireFfi($class);
        [$function, $data, $ints] = self::abiPacket($class);
        $needed = 1 + $words;
        $out = $ffi->new('int64_t[16]');
        $small = $ffi->new('int64_t[1]');
        $small[0] = 7;
        $call = static fn (int $count, int $flags, string $data, string $ints, mixed $out, int $cap): int => $ffi->{$function}($count, $flags, $data, \strlen($data), $ints, \strlen($ints), $out, $cap);

        $this->assertSame($needed, $call(1, 0, $data, $ints, $out, 16), $function);
        $this->assertSame(0, $out[0], "{$function} reports no patches");
        $this->assertSame(-$needed, $call(1, 0, $data, $ints, $small, 1), $function);
        $this->assertSame(7, $small[0], "{$function} must not write on a capacity error");
        $this->assertSame(-$needed, $call(1, 0, $data, $ints, null, 0), $function);
        $this->assertSame(-1, $call(1, 0, $data, $ints, null, 4), $function);
        $this->assertSame(-1, $call(1, 1, $data, $ints, $out, 16), "{$function} flags");
        $this->assertSame(-1, $call(2, 0, $data, $ints, $out, 16), "{$function} record count");
        $this->assertSame(-1, $call(1, 0, $data."\0", $ints, $out, 16), "{$function} trailing field");
        $this->assertSame(-1, $call(1, 0, $data, $ints.'x', $out, 16), "{$function} ragged int table");
    }

    public function test_randomized_parity_across_helpers(): void
    {
        $this->requireFfi(SearchService::class);
        mt_srand(5151);
        for ($round = 0; $round < self::RANDOM_ROUNDS; $round++) {
            $count = mt_rand(1, 16);
            $withNul = $round % 50 === 49;
            $rows = $types = $actions = $ids = $queries = $media = $fields = $dirs = $mins = $maxes = $cursors = [];
            $tv = mt_rand(0, 1) === 1;
            for ($i = 0; $i < $count; $i++) {
                $rows[] = self::randomMediaRow($tv, $round % 3 === 0);
                $types[] = mt_rand(0, 2) === 0 ? self::randomBytes(10, $withNul) : self::TYPES[mt_rand(0, \count(self::TYPES) - 1)];
                $actions[] = mt_rand(0, 2) === 0 ? self::randomBytes(10, $withNul) : self::ACTIONS[mt_rand(0, \count(self::ACTIONS) - 1)];
                $ids[] = [0, -5, PHP_INT_MAX, PHP_INT_MIN, mt_rand()][mt_rand(0, 4)];
                $queries[] = mt_rand(0, 4) === 0 ? ['*', '0', '', '--', 'a--b-', 'x!', '- -', '!-!-'][mt_rand(0, 7)] : self::randomBytes(30, $withNul);
                $media[] = mt_rand(0, 3) === 0 ? ['-1', ' -1 ', '', "\t\0", '0'][mt_rand(0, 4)] : self::randomBytes(12, $withNul);
                $fields[] = ['postdate_ts', 'adddate_ts', 'size', 'totalpart', 'grabs', 'comments', 'categories_id', 'id', 'ID', 'bogus', ''][mt_rand(0, 10)];
                $dirs[] = ['asc', 'ASC', 'aSc', 'desc', ' asc', '', self::randomBytes(4)][mt_rand(0, 6)];
                $mins[] = mt_rand(0, 4) === 0 ? self::randomBytes(5, $withNul) : self::NUMBERS[mt_rand(0, \count(self::NUMBERS) - 1)];
                $maxes[] = mt_rand(0, 4) === 0 ? (string) mt_rand() : self::NUMBERS[mt_rand(0, \count(self::NUMBERS) - 1)];
                $cursors[] = self::randomCursor($withNul);
            }
            if ($round % 7 === 0) {
                $rows[array_rand($rows)] = mt_rand(0, 1) === 0 ? 'not a row' : (object) ['imdbid' => 5];
            }
            $this->assertBoth(MediaSearchService::class, [$rows, $tv ? 'tv' : 'movie'], null);
            $this->assertBoth(SearchIndexOutboxDispatcher::class, [$types, $actions, $ids], null);
            $this->assertBoth(SearchService::class, [$queries], null);
            $this->assertBoth(ReleaseSearchQuery::class, [$media, $fields, $dirs, $mins, $maxes], null);
            $this->assertBoth(SearchCursor::class, [$cursors, 'qh', 'drv', 'g1', 100], null);
        }
    }

    public function test_paired_randomized_benchmark_gates_rust_default(): void
    {
        $this->requireFfi(SearchService::class);
        mt_srand(5152);
        $strict = getenv('NNTMUX_W51_BENCH') === '1';
        $samples = $strict ? 1000 : 300;
        $workloads = [];
        $floors = [];
        $hash = static fn (string $value): string => hash('md5', $value);

        foreach (['common' => 64, 'uncommon' => 192] as $name => $count) {
            $odd = $name === 'uncommon';
            $rows = $types = $actions = $ids = $queries = $media = $fields = $dirs = $mins = $maxes = $cursors = [];
            for ($i = 0; $i < $count; $i++) {
                $rows[] = $odd
                    ? match ($i % 8) {
                        0 => ['tvdb' => (string) (70000 + $i), 'trakt' => 0, 'tvmaze' => true, 'tvrage' => null, 'title' => "Sh\u{f8}w {$i}"],
                        1 => ['tvdb' => 'tt'.$i, 'trakt' => "id/{$i} & co", 'tvmaze' => '0', 'tvrage' => 12 * $i, 'title' => 'Show'],
                        2 => ['tvdb' => '', 'trakt' => false, 'tvmaze' => 0, 'tvrage' => '', 'title' => 'Untracked'],
                        3 => $i % 24 === 3 ? 'not a row' : ['tvdb' => "\u{30a2}\u{30cb}\u{30e1}-{$i}", 'trakt' => -$i, 'tvmaze' => '000', 'tvrage' => '12abc'],
                        4 => ['tvdb' => $i % 16 === 4 ? 1.5 : $i, 'trakt' => '7', 'tvmaze' => '8', 'tvrage' => '9', 'title' => 'Float'],
                        default => ['tvdb' => 80000 + $i, 'trakt' => (string) (90000 + $i), 'tvmaze' => 1000 + $i, 'tvrage' => (string) $i, 'title' => 'Show '.$i],
                    }
                : ['id' => $i, 'imdbid' => \sprintf('%07d', 100000 + $i * 37), 'tmdbid' => 1000 + $i, 'traktid' => $i % 3 === 0 ? 0 : (string) (5500 + $i), 'title' => 'Movie '.$i, 'year' => 1990 + $i % 30];

                $types[] = $odd
                    ? (self::TYPES[$i % \count(self::TYPES)].($i % 5 === 0 ? '' : '/Sub-Type '.$i))
                    : ['release', 'App\\Models\\Release', 'movie', 'tvshow', 'predb', 'music'][$i % 6];
                $actions[] = $odd ? self::ACTIONS[$i % \count(self::ACTIONS)].($i % 4 === 0 ? ' Now' : '') : ['insert', 'updated', 'delete', 'save'][$i % 4];
                $ids[] = $odd ? ($i % 9 === 0 ? PHP_INT_MIN + $i : mt_rand()) : 1_000_000 + $i;

                $queries[] = $odd
                    ? match ($i % 6) {
                        0 => '"exact phrase" -excluded (group|other) @name ~fuzzy/'.$i,
                        1 => str_repeat('--a--!', $i % 9 + 1).' $money=1 [x] \'q\' \\path\\to'.$i.' !-',
                        2 => ['*', '0', '', " \t\n"][$i % 4],
                        3 => "\t..Some.Show.S0{$i}E01..\x0c.720p.\u{e9}t\u{e9}.HDTV  ..",
                        default => 'The.Movie.Name.'.(1990 + $i).'.1080p.BluRay.DTS-HD.MA.5.1.x264-GROUP'.str_repeat('.PROPER', $i % 3),
                    }
                : 'The.Movie.Name.'.(2000 + $i % 25).'.1080p.BluRay.x264-GROUP'.($i % 4 === 0 ? ' (Director\'s Cut)' : '');

                $media[] = $odd ? ['  '.$hash((string) $i).' ', '-1', '', "\t-1\n", "uid-{$i}\0"][$i % 4] : $hash('media'.$i);
                $fields[] = $odd ? ['postdate_ts', 'bogus', 'id', 'Size', 'categories_id', ''][$i % 6] : ['postdate_ts', 'size', 'grabs', 'adddate_ts'][$i % 4];
                $dirs[] = $odd ? ['ASC', 'asc ', 'Desc', '', 'aSc'][$i % 5] : ($i % 2 === 0 ? 'desc' : 'asc');
                $mins[] = $odd ? self::NUMBERS[$i % \count(self::NUMBERS)] : (string) ($i * 10);
                $maxes[] = $odd ? self::NUMBERS[($i * 7) % \count(self::NUMBERS)] : (string) (100_000 + $i * 1000);

                $cursors[] = $odd
                    ? match ($i % 6) {
                        0 => new SearchCursor([1_700_000_000 + $i, 'release-'.$i], 5000 + $i, $hash('q'), 'manticore', '7', 2_000),
                        1 => new SearchCursor([(string) ($i * 100)], $i, $hash('q'), 'elasticsearch', '7', 2_000),
                        2 => new SearchCursor([$i % 12 === 2 ? 1.25 : $i, "t\u{e9}l\u{e9}chargement/\u{1f600} {$i}"], $i, $hash('other'), 'manticore', '7', 2_000),
                        3 => new SearchCursor([$i], $i, $hash('q'), 'manticore', '8', 500),
                        default => new SearchCursor([1_700_000_000 - $i, 9_000_000 + $i, 'tie'], 99_999, $hash('q'), 'manticore', '7', 2_000),
                    }
                : new SearchCursor([1_700_000_000 + $i * 60, 123_456 + $i], 5000 + $i, $hash('q'), 'manticore', '7', 2_000);
            }
            $now = 1_000;
            $queryHash = $hash('q');

            $workloads[MediaSearchService::class][$name] = static fn (bool $rust) => $rust ? MediaSearchService::externalIdPlanBatchNative($rows, $odd ? 'tv' : 'movie') : MediaSearchService::externalIdPlanBatchPhp($rows, $odd ? 'tv' : 'movie');
            $workloads[SearchIndexOutboxDispatcher::class][$name] = static fn (bool $rust) => $rust ? SearchIndexOutboxDispatcher::routeOutboxBatchNative($types, $actions, $ids) : SearchIndexOutboxDispatcher::routeOutboxBatchPhp($types, $actions, $ids);
            $workloads[SearchService::class][$name] = static fn (bool $rust) => $rust ? SearchService::escapeQueryBatchNative($queries) : SearchService::escapeQueryBatchPhp($queries);
            $workloads[ReleaseSearchQuery::class][$name] = static fn (bool $rust) => $rust ? ReleaseSearchQuery::normalizeRequestBatchNative($media, $fields, $dirs, $mins, $maxes) : ReleaseSearchQuery::normalizeRequestBatchPhp($media, $fields, $dirs, $mins, $maxes);
            $workloads[SearchCursor::class][$name] = static fn (bool $rust) => $rust ? SearchCursor::resumePlanBatchNative($cursors, $queryHash, 'manticore', '7', $now) : SearchCursor::resumePlanBatchPhp($cursors, $queryHash, 'manticore', '7', $now);
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
                for ($s = 0; $s < $samples; $s++) {
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
                "\n[Wave 51.5 benchmark] %s: common mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx | uncommon mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx",
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
            $this->assertSame(self::DEFAULTS[$class], $class::usesW51RustFfi(), $class);
            if ($class::usesW51RustFfi()) {
                $this->assertGreaterThanOrEqual(1.5, $floor, \sprintf('%s: a Rust default must clear the 1.5x gate (min ratio %.2fx)', $class, $floor));
            }
        }
    }

    /**
     * Asserts that the PHP and native variants (and both toggled entry points) agree, and equal $expected
     * unless it is null.
     *
     * @param  class-string  $class
     * @param  list<mixed>  $arguments
     * @param  list<int>|null  $expected
     */
    private function assertBoth(string $class, array $arguments, ?array $expected): void
    {
        [$method] = self::METHODS[$class];
        $php = @$class::{$method.'Php'}(...$arguments);
        $native = @$class::{$method.'Native'}(...$arguments);
        $this->assertSame($php, $native, $class.' '.json_encode($arguments, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        $this->assertSame($php, @$class::withW51RustFfi(true, static fn () => $class::{$method}(...$arguments)));
        $this->assertSame($php, @$class::withW51RustFfi(false, static fn () => $class::{$method}(...$arguments)));
        if ($expected !== null) {
            $this->assertSame($expected, $php, $class);
        }
    }

    /**
     * @param  class-string  $class
     * @return list<mixed>
     */
    private static function firstArguments(string $class): array
    {
        return match ($class) {
            MediaSearchService::class => [[['imdbid' => '1', 'tmdbid' => 2, 'traktid' => null], 'x', ['imdbid' => 0, 'tmdbid' => '', 'traktid' => true]], 'movie'],
            SearchIndexOutboxDispatcher::class => [['release', 'Music', 'tv'], ['insert', 'save', 'drop'], [1, 2, 3]],
            SearchService::class => [['a.b--c', '*', '(x)']],
            ReleaseSearchQuery::class => [['m', '-1', ' x '], ['size', 'id', 'x'], ['asc', 'DESC', 'ASC'], ['1', '-2', ' 3'], ['', '04', '5']],
            default => [[new SearchCursor([1, 2], 3, 'q', 'd', 'g', 9), new SearchCursor(['4'], 0, 'q', 'x', 'g', 9)], 'q', 'd', 'g', 5],
        };
    }

    /**
     * One valid record for the class's C entry point: `[function, data, packed ints]`.
     *
     * @param  class-string  $class
     * @return array{string, string, string}
     */
    private static function abiPacket(string $class): array
    {
        return match ($class) {
            MediaSearchService::class => ['nntmux_w515_media_external_id_plan', '', pack('P*', 1, 0, 1, 2, 0, 2, 0, 2, 0)],
            SearchIndexOutboxDispatcher::class => ['nntmux_w515_outbox_route', "release\0insert", pack('P', 1)],
            SearchService::class => ['nntmux_w515_search_escape_queries', 'abc', ''],
            ReleaseSearchQuery::class => ['nntmux_w515_release_query_normalize', "a\0b\0c\0d\0e", ''],
            default => ['nntmux_w515_search_cursor_resume_plan', "[[1]]\0q\0d\0g\0q\0d\0g", pack('P*', 0, 0, 5, 1)],
        };
    }

    private static function xxh64(string $value): int
    {
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('J', hash('xxh64', $value, true));

        return $unpacked[1];
    }

    private static function randomBytes(int $maxLength, bool $withNul = false): string
    {
        $out = '';
        for ($i = mt_rand(0, $maxLength); $i > 0; $i--) {
            $byte = self::BYTES[mt_rand(0, \count(self::BYTES) - 1)];
            $out .= $byte === "\0" && ! $withNul ? 'n' : $byte;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function randomMediaRow(bool $tv, bool $partial): array
    {
        $value = static fn (): mixed => match (mt_rand(0, 21)) {
            0 => null,
            1 => false,
            2 => true,
            3 => 0,
            4 => mt_rand(-5, 5),
            5 => mt_rand(),
            6 => '',
            7 => '0',
            8 => '00',
            9 => 'tt'.mt_rand(0, 9_999_999),
            10 => mt_rand(0, 99).'abc',
            11 => ' '.mt_rand(0, 9),
            12 => ['1.5', '1e3', '.5', '-', '+7', '-0', '123456789012345678901'][mt_rand(0, 6)],
            13 => mt_rand(0, 20) === 0 ? 1.5 : 7,
            14 => mt_rand(0, 20) === 0 ? ['nested'] : 8,
            15 => self::randomBytes(8),
            default => (string) mt_rand(1, 9_999_999),
        };
        $row = ['title' => self::randomBytes(6)];
        foreach ($tv ? ['tvdb', 'trakt', 'tvmaze', 'tvrage'] : ['imdbid', 'tmdbid', 'traktid'] as $field) {
            if (! $partial || mt_rand(0, 5) !== 0) {
                $row[$field] = $value();
            }
        }

        return $row;
    }

    private static function randomCursor(bool $withNul): SearchCursor
    {
        $sort = [];
        for ($k = mt_rand(0, 3); $k > 0; $k--) {
            $sort[] = match (mt_rand(0, 9)) {
                0 => self::randomBytes(6, $withNul),
                1 => ['12', ' 5', 'abc', '-3', '1e3', '0x1', "\u{e9}9", '9999999999999999999'][mt_rand(0, 7)],
                2 => mt_rand(0, 15) === 0 ? 1.5 : 3,
                3 => mt_rand(0, 15) === 0 ? "\xff" : 'ok',
                4 => "\u{e9}/\"\\\u{1f600}\x01",
                5 => [PHP_INT_MIN, PHP_INT_MAX, 0, -1][mt_rand(0, 3)],
                default => mt_rand(-5, 99_999),
            };
        }
        if (mt_rand(0, 20) === 0) {
            $sort = ['k' => 1];
        }

        return new SearchCursor($sort, mt_rand(-3, 9_999), ['qh', 'qx', self::randomBytes(3, $withNul)][mt_rand(0, 2)], ['drv', 'es', "dr\u{e9}v"][mt_rand(0, 2)], ['g1', 'g2', "\xff"][mt_rand(0, 2)], mt_rand(95, 105));
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
     * @param  class-string  $class
     */
    private function requireFfi(string $class): FFI
    {
        $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
        /** @var string $cdef */
        $cdef = (new ReflectionClassConstant($class, 'W51_FFI_CDEF'))->getValue();
        $ffi = RustPipelineLibrary::resolveDomain('search_w51', $cdef, \is_string($path) && $path !== '' ? $path : null);
        if ($ffi === null) {
            $this->markTestSkipped('Rust search FFI library is not built or available.');
        }

        return $ffi;
    }
}
