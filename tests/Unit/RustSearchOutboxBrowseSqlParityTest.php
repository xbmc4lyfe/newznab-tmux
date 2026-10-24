<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\MovieBrowseService;
use App\Services\Releases\ReleaseBrowseService;
use App\Services\Search\SearchIndexOutboxDispatcher as Dispatcher;
use App\Support\RustPipelineLibrary;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;

/**
 * Wave 11 paired single-call screen: Apple M5 / 32 GiB, PHP 8.5.12, Rust 1.99.1,
 * release LTO, CLI OPcache disabled; 500 warmups and 10,000 randomized pairs per case.
 * Source/lockfile/library hashes and every raw nanosecond pair are retained by the harness.
 * Baseline branch commit: fead5ae34261e809689d58a71b3231eb8a6144cc.
 * Speedup (PHP/Rust), common mean/p99; uncommon mean/p99:
 * compactRows: 0.803x/0.781x; 1.413x/1.584x.
 * documentKey: 0.382x/0.233x; 0.450x/0.313x.
 * normalizeEntityType: 0.259x/0.132x; 0.294x/0.267x.
 * normalizeAction: 0.168x/0.100x; 0.375x/0.240x.
 * getBrowseOrder: 0.267x/0.302x; 0.311x/1.570x.
 * getMovieOrder: 0.282x/0.462x; 0.268x/0.412x.
 * uSQL: 0.431x/0.433x; 0.536x/0.544x.
 * rewritePagerCountQuery: 0.410x/0.381x; 0.244x/0.156x.
 * fallbackPagerCountQuery: 0.435x/0.375x; 0.672x/0.660x.
 * No candidate passed mean AND p99 >=1.5x on BOTH workloads: PHP defaults stay enabled.
 * Tail ratios vary with host scheduling; no production adoption relies on this screen.
 */
final class RustSearchOutboxBrowseSqlParityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (RustPipelineLibrary::resolveDomain('search', 'ssize_t nntmux_search_outbox_browse_sql(uint32_t mode, const char *input, size_t input_len, char *output, size_t output_cap);') === null) {
            self::markTestSkipped('Build and copy the release pipeline library before running parity tests.');
        }
    }

    protected function tearDown(): void
    {
        Dispatcher::setUseRustFfi(false);
        ReleaseBrowseService::setUseRustFfi(false);
        MovieBrowseService::setUseRustFfi(false);
        parent::tearDown();
    }

    #[Test]
    public function outbox_aliases_keys_and_compaction_preserve_php_behavior(): void
    {
        Dispatcher::setUseRustFfi(true);
        foreach (['', 'App\\Models\\Releases', '/Movie-Info///', 'TV Shows', 'MusicInfo', 'BOOKS', 'secondary search index', "A\0B", 'Фильм', null, 23, false] as $type) {
            self::assertSame(Dispatcher::normalizeEntityTypePhp($type), Dispatcher::normalizeEntityTypeNative($type));
        }
        foreach (['CREATED', 'replaced', 'Indexed', 'UPDATED', 'destroyed', 'removed', 'other-action', "a\0b", null, 12, true] as $action) {
            self::assertSame(Dispatcher::normalizeActionPhp($action), Dispatcher::normalizeActionNative($action));
        }
        $rows = [];
        foreach (['release', 'Releases', 'music', 'MusicInfo', 'secondary', 'books', 'tv', 'video', 'unknown'] as $i => $type) {
            $rows[] = (object) ['id' => 20 - $i, 'entity_type' => $type, 'entity_id' => '42', 'payload' => ['index' => 'music']];
        }
        $rows[] = (object) ['id' => 19, 'entity_type' => 'release', 'entity_id' => 42, 'payload' => (object) ['id' => 2]];
        $rows[] = (object) ['id' => 'not numeric', 'entity_type' => 'movie', 'payload' => '{"id":"123"}'];
        $rows[] = (object) ['id' => PHP_INT_MAX, 'entity_type' => "foo\0bar", 'entity_id' => -1];
        $rows[] = (object) ['id' => PHP_INT_MIN, 'entity_type' => 'movie', 'entity_id' => -1];
        foreach ($rows as $row) {
            self::assertSame(Dispatcher::documentKeyPhp($row), Dispatcher::documentKeyNative($row));
        }
        self::assertSame(Dispatcher::compactRowsPhp($rows), Dispatcher::compactRowsNative($rows));
        self::assertCount(0, Dispatcher::compactRowsNative([]));
        self::assertSame(Dispatcher::compactRowsPhp($rows), Dispatcher::compactRows($rows));
        Dispatcher::setUseRustFfi(false);
    }

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function unsupported_outbox_inputs_preserve_warning_count_and_order(): void
    {
        $logger = new class
        {
            /** @var list<array{0: string, 1: array<string, mixed>}> */
            public array $warnings = [];

            /** @param array<string, mixed> $context */
            public function warning(string $message, array $context): void
            {
                $this->warnings[] = [$message, $context];
            }
        };
        Log::swap($logger);
        try {
            $rows = [(object) ['id' => 2, 'entity_type' => '映画', 'payload' => '{bad'], (object) ['id' => 1, 'entity_type' => 'release', 'payload' => '{invalid']];
            $expected = Dispatcher::compactRowsPhp($rows);
            $warnings = $logger->warnings;
            $logger->warnings = [];
            self::assertSame($expected, Dispatcher::compactRowsNative($rows));
            self::assertSame($warnings, $logger->warnings);
        } finally {
            Log::clearResolvedInstance('log');
        }
    }

    #[Test]
    public function order_parsers_preserve_their_different_regex_contracts(): void
    {
        $browse = new ReleaseBrowseService;
        $movie = (new ReflectionClass(MovieBrowseService::class))->newInstanceWithoutConstructor();
        foreach (['', 'posted_desc', 'name_ASC', "cat_asc\n", 'size_desc_extra', 'added_descending', 'title_ascgarbage', 'rating_junkdesc', "year_desc\n", 'files_desc', "comments_\0asc", 'unknown', str_repeat('a', 300000).'_ASC'] as $order) {
            self::assertSame($browse->getBrowseOrderPhp($order), $browse->getBrowseOrderNative($order));
            self::assertSame($movie->getMovieOrderPhp($order), $movie->getMovieOrderNative($order));
        }
        $this->expectException(\TypeError::class);
        $browse->getBrowseOrder([]);
    }

    #[Test]
    public function user_sql_preserves_integer_coercions_and_verbatim_multi_categories(): void
    {
        $service = new ReleaseBrowseService;
        $rows = array_map(static fn ($id, $cats): object => (object) ['videos_id' => $id, 'categories' => $cats], [1, '02', '-1e2', null, 3.9, PHP_INT_MAX], ['', '0', '2000', '2000| 2040||0', 'abc', "2\0|3"]);
        foreach ([[], $rows, new Collection(array_map(static fn (object $row) => (new class extends Model {})->setRawAttributes((array) $row), $rows))] as $input) {
            self::assertSame($service->uSQLPhp($input, 'videos_id'), $service->uSQLNative($input, 'videos_id'));
        }
    }

    #[Test]
    public function pager_rewrite_preserves_regex_order_and_fallback_join_stripping(): void
    {
        $service = new ReleaseBrowseService;
        foreach (self::queries() as $query) {
            self::assertSame($service->rewritePagerCountQueryPhp($query), $service->rewritePagerCountQueryNative($query), $query);
            self::assertSame($service->rewritePagerCountQueryPhp($query, true), $service->rewritePagerCountQueryNative($query, true), 'fallback: '.$query);
        }
    }

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function pager_handler_preserves_execution_caps_cache_and_fallback(): void
    {
        $previous = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository(['nntmux' => ['max_pager_results' => 50, 'cache_expiry_short' => 2]]));
        Container::setInstance($container);
        $db = new class
        {
            /** @var list<string> */
            public array $queries = [];

            public bool $failFirst = false;

            /** @return list<object{count: int}> */
            public function select(string $query): array
            {
                $this->queries[] = $query;
                if ($this->failFirst) {
                    $this->failFirst = false;
                    throw new \RuntimeException('fixture optimized query failure');
                }

                return [(object) ['count' => 75]];
            }
        };
        $cache = new class
        {
            /** @var array<string, mixed> */
            public array $values = [];

            public function get(string $key): mixed
            {
                return $this->values[$key] ?? null;
            }

            public function put(string $key, mixed $value, mixed $ttl): void
            {
                $this->values[$key] = $value;
            }
        };
        DB::swap($db);
        Cache::swap($cache);
        try {
            $method = new \ReflectionMethod(ReleaseBrowseService::class, 'getPagerCount');
            $service = new ReleaseBrowseService;
            foreach ([false, true] as $enabled) {
                ReleaseBrowseService::setUseRustFfi($enabled);
                $cache->values = [];
                $db->queries = [];
                $query = 'SELECT r.* FROM releases r LEFT JOIN videos v ON 1 WHERE r.id > 2 ORDER BY r.id';
                $db->failFirst = true;
                self::assertSame(50, $method->invoke($service, $query));
                self::assertSame([$service->rewritePagerCountQueryPhp($query), $service->rewritePagerCountQueryPhp($query, true)], $db->queries);
                self::assertSame(50, $method->invoke($service, $query));
                self::assertCount(2, $db->queries, 'A cached count must not execute SQL.');
                self::assertSame(10, $method->invoke($service, 'SELECT COUNT(*) as count FROM releases r', 10));
            }
        } finally {
            DB::clearResolvedInstance('db');
            Cache::clearResolvedInstance('cache');
            Container::setInstance($previous);
        }
    }

    #[Test]
    public function randomized_byte_inputs_and_query_patterns_match_php(): void
    {
        $random = new Randomizer(new Mt19937(111));
        $browse = new ReleaseBrowseService;
        $movie = (new ReflectionClass(MovieBrowseService::class))->newInstanceWithoutConstructor();
        $pieces = ['SELECT ', 'SELECT  ', "SELECT\t", 'DISTINCT ', 'r.id ', 'x ', 'FROM ', 'releases r ', 'GROUP BY r.id ', 'GROUP BY r.id', 'ORDER BY r.id', 'ORDER BY RAND()', 'LIMIT 4', "LIMIT 4\n", 'OFFSET 1', 'LEFT JOIN x ON 1 ', 'WHERE x=1 ', 'WHERELEFTINNERJOIN', "\0", "\f", "\n", 'JOIN ', 'INNER OUTER JOIN x ', 'COUNT (x) '];
        for ($i = 0; $i < 2000; $i++) {
            $query = '';
            for ($j = 0, $n = $random->getInt(1, 15); $j < $n; $j++) {
                $query .= $pieces[$random->getInt(0, count($pieces) - 1)];
            }
            foreach ([false, true] as $fallback) {
                self::assertSame($browse->rewritePagerCountQueryPhp($query, $fallback), $browse->rewritePagerCountQueryNative($query, $fallback), bin2hex($query).':'.(int) $fallback);
            }
            $order = ['name', 'title', 'cat', 'year', 'rating'][$random->getInt(0, 4)].'_'.['asc', 'DESC', "desc\n", 'descendant', 'xdesc', '', "asc\0", 'desc_x'][$random->getInt(0, 7)];
            self::assertSame($browse->getBrowseOrderPhp($order), $browse->getBrowseOrderNative($order));
            self::assertSame($movie->getMovieOrderPhp($order), $movie->getMovieOrderNative($order));
        }
        for ($i = 0; $i < 50; $i++) {
            $rows = [];
            for ($j = 0; $j < 100; $j++) {
                $rows[] = (object) ['id' => $random->getInt(-5, 5), 'entity_type' => ['release', 'releases', 'music', 'secondary'][$random->getInt(0, 3)], 'entity_id' => $random->getInt(1, 8), 'payload' => ['secondary' => 'music']];
            }
            self::assertSame(Dispatcher::compactRowsPhp($rows), Dispatcher::compactRowsNative($rows));
        }
    }

    #[Test]
    public function abi_validates_packets_and_reports_required_capacity(): void
    {
        $ffi = RustPipelineLibrary::resolveDomain('search', 'ssize_t nntmux_search_outbox_browse_sql(uint32_t mode, const char *input, size_t input_len, char *output, size_t output_cap);');
        self::assertNotNull($ffi);
        $out = $ffi->new('char[2]');
        // @phpstan-ignore method.notFound
        self::assertSame(-6, $ffi->nntmux_search_outbox_browse_sql(1, 'created', 7, $out, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_search_outbox_browse_sql(1, null, 1, $out, 2));
        foreach ([2, 3, 6] as $mode) {
            // @phpstan-ignore method.notFound
            self::assertSame(-1, $ffi->nntmux_search_outbox_browse_sql($mode, "\xff\xff\xff\xff", 4, $out, 2));
        }
        // @phpstan-ignore method.notFound
        self::assertSame(-2, $ffi->nntmux_search_outbox_browse_sql(0, '映画', 6, $out, 2));
    }

    /**
     * Opt in: NNTMUX_WAVE11_BENCHMARK=1 php artisan test --compact tests/Unit/RustSearchOutboxBrowseSqlParityTest.php
     * 10,000 randomized paired SINGLE calls, 500 warmups, nearest-rank p99.
     * Raw pairs, source hashes, library hash and host/runtime settings are saved to /tmp.
     */
    #[Test]
    public function benchmark_each_candidate_on_common_and_uncommon_inputs(): void
    {
        if (getenv('NNTMUX_WAVE11_BENCHMARK') !== '1') {
            self::markTestSkipped('Opt-in CPU benchmark.');
        }
        $browse = new ReleaseBrowseService;
        $movie = (new ReflectionClass(MovieBrowseService::class))->newInstanceWithoutConstructor();
        $random = new Randomizer(new Mt19937(111));
        $workloads = [];
        foreach (['common' => 32, 'uncommon' => 1000] as $kind => $size) {
            $rows = [];
            $users = [];
            for ($i = 0; $i < $size; $i++) {
                $rows[] = (object) ['id' => ($size - $i), 'entity_type' => ['release', 'music', 'Releases'][$i % 3], 'entity_id' => $i % max(1, intdiv($size, 3)), 'payload' => '{"id":42}'];
            }
            for ($i = 0; $i < ($kind === 'common' ? 8 : 200); $i++) {
                $users[] = (object) ['videos_id' => $i + 1, 'categories' => $i % 2 === 0 ? '5030' : '5030|5040|5070'];
            }
            $type = $kind === 'common' ? 'App\\Models\\Releases' : '/vendor/domain/Models/Secondary-Search-Index///';
            $action = $kind === 'common' ? 'UPDATED' : 'custom action-'.str_repeat('a', 200);
            $row = $kind === 'common' ? $rows[0] : (object) ['id' => 1, 'entity_type' => 'secondary', 'payload' => '{"id":123,"index":"unknown","secondary_index":"anidb_titles"}'];
            $order = $kind === 'common' ? 'name_desc' : 'comments_DESC_extra';
            $movieOrder = $kind === 'common' ? 'title_asc' : 'rating_somethingDESC';
            $query = $kind === 'common'
                ? 'SELECT r.* FROM releases r WHERE r.categories_id IN (5030,5040) ORDER BY r.postdate DESC LIMIT 100 OFFSET 25'
                : 'SELECT DISTINCT r.id, r.searchname, v.title FROM releases r LEFT JOIN videos v ON v.id = r.videos_id WHERE r.id IN ('.implode(',', range(1, 150)).') GROUP BY r.id ORDER BY r.postdate DESC LIMIT 100 OFFSET 1000';
            $workloads['compactRows/'.$kind] = [static fn () => Dispatcher::compactRowsPhp($rows), static fn () => Dispatcher::compactRowsNative($rows)];
            $workloads['documentKey/'.$kind] = [static fn () => Dispatcher::documentKeyPhp($row), static fn () => Dispatcher::documentKeyNative($row)];
            $workloads['normalizeEntityType/'.$kind] = [static fn () => Dispatcher::normalizeEntityTypePhp($type), static fn () => Dispatcher::normalizeEntityTypeNative($type)];
            $workloads['normalizeAction/'.$kind] = [static fn () => Dispatcher::normalizeActionPhp($action), static fn () => Dispatcher::normalizeActionNative($action)];
            $workloads['getBrowseOrder/'.$kind] = [static fn () => $browse->getBrowseOrderPhp($order), static fn () => $browse->getBrowseOrderNative($order)];
            $workloads['getMovieOrder/'.$kind] = [static fn () => $movie->getMovieOrderPhp($movieOrder), static fn () => $movie->getMovieOrderNative($movieOrder)];
            $workloads['uSQL/'.$kind] = [static fn () => $browse->uSQLPhp($users, 'videos_id'), static fn () => $browse->uSQLNative($users, 'videos_id')];
            $workloads['rewritePagerCountQuery/'.$kind] = [static fn () => $browse->rewritePagerCountQueryPhp($query), static fn () => $browse->rewritePagerCountQueryNative($query)];
            $workloads['fallbackPagerCountQuery/'.$kind] = [static fn () => $browse->rewritePagerCountQueryPhp($query, true), static fn () => $browse->rewritePagerCountQueryNative($query, true)];
        }
        $artifact = ['php' => PHP_VERSION, 'host' => php_uname(), 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'), 'profile' => 'cargo release: opt-level=3,lto=true,codegen-units=1', 'warmups' => 500, 'samples' => 10000, 'seed' => 111, 'sources' => [], 'results' => []];
        foreach (['app/Services/Search/SearchIndexOutboxDispatcher.php', 'app/Services/Releases/ReleaseBrowseService.php', 'app/Services/MovieBrowseService.php', 'rust/pipeline-ffi/src/search.rs', 'tests/Unit/RustSearchOutboxBrowseSqlParityTest.php', 'composer.lock', 'rust/pipeline-ffi/Cargo.lock', 'storage/app/nntmux-native/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so')] as $path) {
            $artifact['sources'][$path] = hash_file('sha256', dirname(__DIR__, 2).'/'.$path);
        }
        foreach ($workloads as $name => [$php, $rust]) {
            self::assertSame($php(), $rust(), $name);
            for ($i = 0; $i < 500; $i++) {
                $php();
                $rust();
            }
            $pairs = [];
            for ($i = 0; $i < 10000; $i++) {
                $pair = [];
                foreach ($random->getInt(0, 1) === 0 ? [0, 1] : [1, 0] as $side) {
                    $start = hrtime(true);
                    ($side === 0 ? $php : $rust)();
                    $pair[$side] = hrtime(true) - $start;
                }
                $pairs[] = [$pair[0], $pair[1]];
            }
            $p = array_column($pairs, 0);
            $r = array_column($pairs, 1);
            sort($p);
            sort($r);
            $mean = (array_sum($p) / count($p)) / (array_sum($r) / count($r));
            $p99 = $p[9899] / $r[9899];
            $artifact['results'][$name] = ['mean_speedup' => $mean, 'p99_speedup' => $p99, 'php_mean_ns' => array_sum($p) / count($p), 'rust_mean_ns' => array_sum($r) / count($r), 'php_p99_ns' => $p[9899], 'rust_p99_ns' => $r[9899], 'pairs_ns' => $pairs];
            fwrite(STDERR, sprintf("%s: mean %.3fx; p99 %.3fx\n", $name, $mean, $p99));
        }
        $path = tempnam(sys_get_temp_dir(), 'nntmux-wave11-');
        self::assertNotFalse($path);
        file_put_contents($path, json_encode($artifact, JSON_THROW_ON_ERROR));
        fwrite(STDERR, 'Raw benchmark artifact: '.$path.PHP_EOL);
    }

    /** @return list<string> */
    private static function queries(): array
    {
        return [
            '', 'SELECT COUNT(*) FROM releases r', 'select count (r.id) as total from releases r',
            'SELECT r.* FROM releases r WHERE r.id > 2 ORDER BY r.id DESC LIMIT 100 OFFSET 2',
            'SELECT DISTINCT r.id FROM releases r GROUP BY r.id ORDER BY r.postdate DESC',
            'SELECT r.id FROM releases r GROUP BY r.id LIMIT 25 OFFSET 3',
            'SELECT r.id FROM releases r GROUP BY r.categories_id',
            'SELECT (SELECT x FROM t) AS v FROM releases r ORDER BY RAND()',
            'SELECT r.id FROM releases r LEFT OUTER JOIN videos v ON v.id = r.videos_id INNER JOIN t ON t.id = r.id WHERE r.id > 3 ORDER BY r.id',
            "SELECT r.id FROM releases r WHERE x='ORDER BY x)' LIMIT 2\n",
            "SELECT\tDISTINCT\nr.id\rFROM releases r\nWHERE x = 1 GROUP BY r.id\n",
            'SELECT  FROM releases r ', 'SELECT   FROM releases r  ', 'FROM releases r ', 'FROM releases r  ',
            'SELECT   FROM_A FROM releases r',
            "SELECT r.id FROM releases r WHERE x = 'ORDER BYZ'",
            'SELECT r.id FROM releases r ORDER BY ',
            'SELECT x FROM releases r RIGHT JOIN x ON 1 WHERE r.id = 2',
            'SELECT x FROM releases r JOIN x ON 1 WHERELEFTINNERJOIN = 1 LIMIT 2',
            "SELECT r.id FROM releases r WHERE x='a\0b' ORDER BY r.id\n", str_repeat('x ', 10000).'SELECT x FROM releases r LIMIT 1',
        ];
    }
}
