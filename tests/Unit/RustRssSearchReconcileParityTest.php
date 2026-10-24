<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\NntmuxCheckIndex;
use App\Console\Commands\NntmuxSearchReconcile;
use App\Http\Controllers\Api\RSS;
use App\Http\Controllers\RssController;
use App\Services\Search\Support\ManticoreClientFactory;
use Closure;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Parity, boundary, and paired benchmark suite for Wave 13 Slice 4 Rust FFI exports in
 * `rust/pipeline-ffi/src/search.rs` (`nntmux_rss_*`, `nntmux_reconcile_*`, `nntmux_checkindex_*`,
 * `nntmux_manticore_cfg_*`):
 * - `RssController`: `parseCategoryIds`, `normalizeFeedId`, `absNumericOrDefault`
 * - `RSS`: `filterFragments`
 * - `NntmuxSearchReconcile`: `parseSinceWindow`, `missingIds`, `indexedIdsProbeSql`
 * - `NntmuxCheckIndex`: `formatBytes`, `manticoreTableName`
 * - `ManticoreClientFactory`: `normalizeHosts`, `parseHost`
 *
 * Paired randomized benchmark (Darwin arm64, PHP 8.5.12 CLI, `cargo build --release`, N=6,000 pairs,
 * 300 warmup pairs; `RUN_RUST_FFI_BENCHMARKS=1`), native helper vs pure PHP including FFI, pack/unpack
 * and scratch-buffer costs:
 * - `NntmuxSearchReconcile::missingIds` common (500 ids, all indexed): PHP 16146.3 ns mean / 20541 ns p99 vs
 *   Rust 4848.6 ns / 6000 ns -> 3.33x mean / 3.42x p99; uncommon (1000 ids, a third missing, shuffled):
 *   33588.3 / 42083 vs 12935.9 / 16375 -> 2.60x / 2.57x. Adopted for batches >= 64 ids.
 * - `NntmuxSearchReconcile::indexedIdsProbeSql` common (500 ids): PHP 11296.6 / 13583 vs Rust 4264.3 / 5250
 *   -> 2.65x / 2.59x; uncommon (1000 ids, backtick index): 23288.4 / 28958 vs 8182.7 / 9875 -> 2.85x / 2.93x.
 *   Adopted for batches >= 64 ids.
 * - Size sweep (missingIds / probeSql): 1 id 0.23x / 0.57x, 4 ids 0.54x / 0.80x, 16 ids 1.23x / 1.28x,
 *   32 ids 1.67x / 1.64x, 64 ids 2.15x / 1.92x, 128 ids 2.60x / 2.22x mean, so smaller tail batches
 *   (`NntmuxSearchReconcile::NATIVE_MIN_IDS`) stay on PHP.
 * - `RssController::parseCategoryIds`: 0.95x mean / 1.00x p99 common (2 ids), 3.17x / 3.04x uncommon
 *   (43 tokens). Common path fails -> retained pure PHP.
 * - `RssController::normalizeFeedId` / `absNumericOrDefault`: 0.27x-0.35x mean -> retained pure PHP.
 * - `RSS::filterFragments`: 0.39x / 0.59x mean -> retained pure PHP.
 * - `NntmuxSearchReconcile::parseSinceWindow`: 0.71x / 0.80x mean -> retained pure PHP.
 * - `NntmuxCheckIndex::formatBytes` / `manticoreTableName`: 0.55x-0.70x / 0.26x-0.29x mean -> retained pure PHP.
 * - `ManticoreClientFactory::normalizeHosts` / `parseHost`: 0.49x-1.04x / 0.54x-0.63x mean -> retained pure PHP.
 */
final class RustRssSearchReconcileParityTest extends TestCase
{
    /** @var list<class-string> */
    private const array TOGGLED = [
        RssController::class,
        RSS::class,
        NntmuxSearchReconcile::class,
        NntmuxCheckIndex::class,
        ManticoreClientFactory::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetToggles();
    }

    protected function tearDown(): void
    {
        $this->resetToggles();
        parent::tearDown();
    }

    #[Test]
    public function ffi_handles_and_toggles_work(): void
    {
        self::assertNotNull(RssController::rssFfi(), 'RssController FFI handle must be available.');
        self::assertNotNull(RSS::rssFfi(), 'RSS FFI handle must be available.');
        self::assertNotNull(NntmuxSearchReconcile::reconcileFfi(), 'NntmuxSearchReconcile FFI handle must be available.');
        self::assertNotNull(NntmuxCheckIndex::checkIndexFfi(), 'NntmuxCheckIndex FFI handle must be available.');
        self::assertNotNull(ManticoreClientFactory::factoryFfi(), 'ManticoreClientFactory FFI handle must be available.');

        // Batch reconcile helpers adopt Rust by default (>= 1.5x mean and p99 for >= 64 ids).
        self::assertTrue(NntmuxSearchReconcile::usesRustFfi());

        foreach (self::TOGGLED as $class) {
            if ($class !== NntmuxSearchReconcile::class) {
                self::assertFalse($class::usesRustFfi(), "{$class} should default to pure PHP");
            }
            $class::setUseRustFfi(true);
            self::assertTrue($class::usesRustFfi(), "{$class} should enable Rust FFI when toggled on");
            $class::setUseRustFfi(false);
            self::assertFalse($class::usesRustFfi(), "{$class} should disable Rust FFI when toggled off");
            $class::setUseRustFfi(null);
        }

        // Explicitly disabled native helpers decline instead of guessing.
        RssController::setUseRustFfi(false);
        $supported = true;
        self::assertNull(RssController::parseCategoryIdsNative('5000', $supported));
        self::assertFalse($supported);
        NntmuxSearchReconcile::setUseRustFfi(false);
        self::assertNull(NntmuxSearchReconcile::missingIdsNative([1], [], $supported));
        self::assertFalse($supported);
    }

    #[Test]
    public function c_abi_error_sentinel_and_resize_contracts(): void
    {
        $ffi = NntmuxSearchReconcile::reconcileFfi();
        $rss = RssController::rssFfi();
        $rssApi = RSS::rssFfi();
        $check = NntmuxCheckIndex::checkIndexFfi();
        $factory = ManticoreClientFactory::factoryFfi();
        self::assertNotNull($ffi);
        self::assertNotNull($rss);
        self::assertNotNull($rssApi);
        self::assertNotNull($check);
        self::assertNotNull($factory);

        $tiny = $ffi->new('char[4]');
        $buf = $ffi->new('char[256]');
        $int = $ffi->new('int64_t[1]');
        $fields = $factory->new('int64_t[7]');

        // Invalid pointers.
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $rss->nntmux_rss_parse_category_ids(null, 3, $buf, 256));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $rss->nntmux_rss_show_id(null, 3, $int));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $rss->nntmux_rss_show_id('5', 1, null));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $rss->nntmux_rss_abs_numeric_int(null, 3, $int));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $rssApi->nntmux_rss_filter_fragments(1, 1, 1, 1, 1, 1, 1, 1, null, 16));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_reconcile_parse_since(null, 3, $int));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_reconcile_missing_ids(null, 2, '', 0, $buf, 256));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_reconcile_probe_sql(null, 3, '', 0, $buf, 256));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $check->nntmux_checkindex_format_bytes(5, null, 16));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $check->nntmux_checkindex_table_code(null, 3));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $factory->nntmux_manticore_cfg_normalize_hosts(null, 3, $buf, 256));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $factory->nntmux_manticore_cfg_parse_host(null, 3, $fields));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $factory->nntmux_manticore_cfg_parse_host('h', 1, null));

        // Undersized buffers report -(needed), never less than -4 so -1 stays distinct.
        // @phpstan-ignore method.notFound
        self::assertSame(-9, $rss->nntmux_rss_parse_category_ids('5000,5040', 9, $tiny, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-4, $rss->nntmux_rss_parse_category_ids('5', 1, $tiny, 0));
        // @phpstan-ignore method.notFound
        self::assertSame(-16, $ffi->nntmux_reconcile_missing_ids(pack('q*', 1, 2), 2, '', 0, $tiny, 4));
        // @phpstan-ignore method.notFound
        $needed = $ffi->nntmux_reconcile_probe_sql('releases_rt', 11, pack('q*', 7), 1, $tiny, 4);
        self::assertSame(-\strlen(NntmuxSearchReconcile::indexedIdsProbeSqlPhp('releases_rt', [7])), $needed);
        // @phpstan-ignore method.notFound
        self::assertSame(-5, $factory->nntmux_manticore_cfg_normalize_hosts('ab , cd', 7, $tiny, 4));

        // Empty outputs and decline codes.
        // @phpstan-ignore method.notFound
        self::assertSame(0, $rss->nntmux_rss_parse_category_ids('', 0, $buf, 256));
        // @phpstan-ignore method.notFound
        self::assertSame(0, $rss->nntmux_rss_show_id('1.5', 3, $int));
        // @phpstan-ignore method.notFound
        self::assertSame(0, $rss->nntmux_rss_abs_numeric_int('-9223372036854775808', 20, $int));
        // @phpstan-ignore method.notFound
        self::assertSame(2, $rss->nntmux_rss_abs_numeric_int('nope', 4, $int));
        // @phpstan-ignore method.notFound
        self::assertSame(3, $rssApi->nntmux_rss_filter_fragments(0, 0, -1, 1, -1, 0, 5000, 5999, $buf, 256));
        // @phpstan-ignore method.notFound
        self::assertSame(0, $ffi->nntmux_reconcile_parse_since('2w', 2, $int));
        // @phpstan-ignore method.notFound
        self::assertSame(0, $factory->nntmux_manticore_cfg_parse_host('[::1]:9308', 10, $fields));
        // @phpstan-ignore method.notFound
        self::assertSame(0, $factory->nntmux_manticore_cfg_parse_host('0:9308', 6, $fields));
    }

    #[Test]
    public function rss_controller_parameter_parity(): void
    {
        $categoryCases = ['', '5000', ' 5000 , 5040 ', '-2', '-1,5000', '5000,,abc,5040', "5000\n,\t5040\x0B,\x005030", "5000\x0C", '+5000', '5000.5', '1-', '--1', '0', "5000\xa0", str_repeat('5030,', 64)];
        foreach ($categoryCases as $case) {
            $this->assertCategoryIdsParity($case);
        }

        $numericCases = ['', ' ', '0', '-0', '00', '7', '-7', '+7', ' 42 ', "\t42\n", "\f42\v", '123456789', '9223372036854775807', '-9223372036854775807', '-9223372036854775808', '9223372036854775808', 'abc', '.', '.e5', '+', '-', '1e', '0x1A', '5 5', "5\0", '1.5', '-.5', '5.', '1e3', '1E-2', '5abc', ' 5abc', "\xa05"];
        foreach ($numericCases as $case) {
            $this->assertScalarParity($case);
        }
        foreach ([null, 0, 7, -7, 1.5, true, false] as $value) {
            $this->assertScalarParity($value);
        }
    }

    #[Test]
    public function rss_controller_real_caller_parse_common_params(): void
    {
        $method = new ReflectionMethod(RssController::class, 'parseCommonRssParams');
        $controller = (new ReflectionClass(RssController::class))->newInstanceWithoutConstructor();
        $requests = [
            [],
            ['show' => '42', 'airdate' => '7', 'num' => '-3', 'limit' => '50', 'o' => 'json'],
            ['anidb' => ' 9 ', 'airdate' => 'x', 'num' => '1.5', 'limit' => 'lots'],
            ['show' => '0', 'anidb' => '5', 'airdate' => '-0', 'num' => '00'],
            ['show' => 'abc', 'airdate' => ' 12 ', 'num' => '1e2'],
        ];
        foreach ($requests as $query) {
            $request = Request::create('https://nntmux.test/rss/full-feed', 'GET', $query);
            RssController::setUseRustFfi(false);
            $php = $method->invoke($controller, $request);
            RssController::setUseRustFfi(true);
            self::assertSame($php, $method->invoke($controller, $request), json_encode($query) ?: '');
        }
    }

    #[Test]
    public function rss_filter_fragment_parity(): void
    {
        $rng = new Randomizer(new Mt19937(20261024));
        $values = [-2, -1, 0, 1, 7, 365, 5000, PHP_INT_MAX, PHP_INT_MIN];
        for ($i = 0; $i < 600; $i++) {
            $args = [
                $values[$rng->getInt(0, \count($values) - 1)],
                $values[$rng->getInt(0, \count($values) - 1)],
                $values[$rng->getInt(0, \count($values) - 1)],
                $rng->getInt(0, 1) === 1,
                $values[$rng->getInt(0, \count($values) - 1)],
                $values[$rng->getInt(0, \count($values) - 1)],
            ];
            $php = RSS::filterFragmentsPhp(...$args);
            $supported = false;
            self::assertSame($php, RSS::filterFragmentsNative($args[0], $args[1], $args[2], $args[3], $args[4], $args[5], $supported));
            self::assertTrue($supported);
        }

        // Non-integer ids defer to PHP.
        $supported = true;
        self::assertNull(RSS::filterFragmentsNative('5', 0, -1, true, 100, 0, $supported));
        self::assertFalse($supported);
        RSS::setUseRustFfi(true);
        self::assertSame(RSS::filterFragmentsPhp(1.5, 0, -1, true, 100, 0), RSS::filterFragments(1.5, 0, -1, true, 100, 0));
    }

    #[Test]
    public function reconcile_helper_parity(): void
    {
        $sinceCases = ['24h', '24H', '7d', '7D', ' 24h ', "24h\n", "\x0024h\x0B", "\f24h", '0h', '00d', 'h', 'd', '24', '2w', '-1h', '+1h', '1.5h', '24hh', '24 h', '2026-10-01', '99999999999999999999h', "24h\xa0", ''];
        foreach ($sinceCases as $case) {
            $supported = false;
            self::assertSame(NntmuxSearchReconcile::parseSinceWindowPhp($case), NntmuxSearchReconcile::parseSinceWindowNative($case, $supported), json_encode($case) ?: '');
            self::assertTrue($supported);
        }

        $rng = new Randomizer(new Mt19937(20261024));
        for ($i = 0; $i < 300; $i++) {
            $batch = [];
            $indexed = [];
            $batchLen = $rng->getInt(0, 60);
            for ($j = 0; $j < $batchLen; $j++) {
                $batch[] = $rng->getInt(0, 4) === 0 ? $rng->getInt(PHP_INT_MIN, PHP_INT_MAX) : $rng->getInt(-5, 80);
            }
            $indexedLen = $rng->getInt(0, 60);
            for ($j = 0; $j < $indexedLen; $j++) {
                $indexed[] = $rng->getInt(0, 2) === 0 && $batch !== [] ? $batch[$rng->getInt(0, \count($batch) - 1)] : $rng->getInt(-5, 80);
            }
            $supported = false;
            self::assertSame(NntmuxSearchReconcile::missingIdsPhp($batch, $indexed), NntmuxSearchReconcile::missingIdsNative($batch, $indexed, $supported));
            self::assertTrue($supported);

            $index = '';
            $indexLen = $rng->getInt(0, 16);
            for ($j = 0; $j < $indexLen; $j++) {
                $index .= 'ab_`rt'[$rng->getInt(0, 5)];
            }
            self::assertSame(NntmuxSearchReconcile::indexedIdsProbeSqlPhp($index, $batch), NntmuxSearchReconcile::indexedIdsProbeSqlNative($index, $batch, $supported));
            self::assertTrue($supported);
        }

        // Production dispatchers (adopted, with the small-batch PHP floor) match PHP at every size.
        foreach ([0, 1, 63, 64, 65, 500] as $size) {
            $batch = $size === 0 ? [] : range(1, $size);
            $indexed = array_values(array_filter($batch, static fn (int $id): bool => $id % 3 === 1));
            self::assertSame(NntmuxSearchReconcile::missingIdsPhp($batch, $indexed), NntmuxSearchReconcile::missingIds($batch, $indexed));
            self::assertSame(NntmuxSearchReconcile::indexedIdsProbeSqlPhp('releases_rt', $batch), NntmuxSearchReconcile::indexedIdsProbeSql('releases_rt', $batch));
        }

        // Large batches exercise scratch growth.
        $big = range(1, 5000);
        self::assertSame(NntmuxSearchReconcile::missingIdsPhp($big, range(2, 5000, 2)), NntmuxSearchReconcile::missingIdsNative($big, range(2, 5000, 2)));
        self::assertSame(NntmuxSearchReconcile::indexedIdsProbeSqlPhp('releases_rt', $big), NntmuxSearchReconcile::indexedIdsProbeSqlNative('releases_rt', $big));
    }

    #[Test]
    public function reconcile_real_caller_since_cutoff(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 24)->setTime(12, 0));
        $command = (new ReflectionClass(NntmuxSearchReconcile::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(NntmuxSearchReconcile::class, 'parseSinceCutoff');
        foreach (['24h', ' 7D ', '2026-10-01 00:00:00'] as $since) {
            NntmuxSearchReconcile::setUseRustFfi(false);
            $php = $method->invoke($command, $since);
            NntmuxSearchReconcile::setUseRustFfi(true);
            self::assertEquals($php, $method->invoke($command, $since));
        }
    }

    #[Test]
    public function check_index_helper_parity(): void
    {
        $cases = [PHP_INT_MIN, -1, 0, 1, 2, 999, 1023, 1024, 1025, 1535, 1536, 10239, 1048575, 1048576, 1073741823, 1073741824, 1099511627775, 1099511627776, 1125899906842624, PHP_INT_MAX];
        for ($pow = 1; $pow <= 6; $pow++) {
            $base = 1024 ** $pow;
            foreach ([-1, 0, 1, 5, -5, 10, -10] as $delta) {
                $cases[] = $base + $delta;
            }
        }
        $rng = new Randomizer(new Mt19937(20261024));
        for ($i = 0; $i < 20000; $i++) {
            $cases[] = $rng->getInt(0, 1 << $rng->getInt(1, 62));
        }
        // Decimal halfway points such as x.xx5 in each unit.
        for ($i = 0; $i < 5000; $i++) {
            $cases[] = (int) round(($rng->getInt(0, 102399) + 0.5) / 100 * (1024 ** $rng->getInt(0, 4)));
        }
        foreach ($cases as $bytes) {
            $supported = false;
            $native = NntmuxCheckIndex::formatBytesNative($bytes, $supported);
            self::assertTrue($supported);
            self::assertSame(NntmuxCheckIndex::formatBytesPhp($bytes), $native, (string) $bytes);
        }

        foreach (['releases', 'predb', 'movies', 'tvshows', 'music', 'books', 'games', 'console', 'steam', 'anime', '', 'Releases', 'releases ', 'unknown', "predb\0"] as $index) {
            $supported = false;
            self::assertSame(NntmuxCheckIndex::manticoreTableNamePhp($index), NntmuxCheckIndex::manticoreTableNameNative($index, $supported));
            self::assertTrue($supported);
        }
    }

    #[Test]
    public function manticore_client_factory_parity(): void
    {
        $hostLists = ['', ' ', 'manticore', ' a:1 , 0 ,,b ', '0', '0,0', ',', "a\x0C,b", "\x00a\x0B, b\n", 'h1:9308,h2:9308,h3:9308'];
        foreach ($hostLists as $hosts) {
            $supported = false;
            self::assertSame(ManticoreClientFactory::normalizeHostsPhp($hosts), ManticoreClientFactory::normalizeHostsNative($hosts, $supported), json_encode($hosts) ?: '');
            self::assertTrue($supported);
        }
        $supported = true;
        self::assertNull(ManticoreClientFactory::normalizeHostsNative(['a', ''], $supported));
        self::assertFalse($supported);

        $config = ['host' => 'manticore', 'port' => 9308, 'scheme' => 'http'];
        $hosts = ['127.0.0.1', 'localhost:9312', 'https://search.example:9443/api', 'http://h/', 'http://h/a/b/', 'H_x.y-z', '9308', 'h:0', 'h:65535', 'h:65536', 'h:009', 'h:', '0', '0:9308', 'a+b.c-d://h', '1x://h', '[::1]:9308', 'user@h', 'h?x=1', 'h#f', ' h', 'h//x', 'h:1//x', 'http://', '//h', 'h/a://b', ''];
        $rng = new Randomizer(new Mt19937(20261024));
        $alphabet = 'aZ09._-:/@[]?#+ ';
        for ($i = 0; $i < 4000; $i++) {
            $s = '';
            $len = $rng->getInt(0, 18);
            for ($j = 0; $j < $len; $j++) {
                $s .= $alphabet[$rng->getInt(0, \strlen($alphabet) - 1)];
            }
            $hosts[] = $s;
            $hosts[] = ['', 'http://', 'HTTPS://'][$rng->getInt(0, 2)].'h'.$rng->getInt(0, 9).['', ':'.$rng->getInt(0, 70000)][$rng->getInt(0, 1)].['', '/', '/api', '/a/b/'][$rng->getInt(0, 3)];
        }
        $nativeHits = 0;
        foreach ($hosts as $host) {
            $supported = false;
            $native = ManticoreClientFactory::parseHostNative($config, $host, $supported);
            if ($supported) {
                $nativeHits++;
                self::assertSame(ManticoreClientFactory::parseHostPhp($config, $host), $native, json_encode($host) ?: '');
            }
        }
        self::assertGreaterThan(2000, $nativeHits);
        self::assertSame(ManticoreClientFactory::parseHostPhp($config, null), ManticoreClientFactory::parseHostNative($config, null));
        self::assertNull(ManticoreClientFactory::parseHostNative($config, ['host' => 'h']));

        // Real caller: full client config with the toggle on and off.
        foreach ([
            ['host' => 'manticore', 'port' => 9308],
            ['hosts' => ' https://m1:9443/api , m2:9308 ,0', 'retries' => 3, 'token' => 't'],
            ['host' => 'user@h:1', 'scheme' => 'https', 'username' => 'u', 'password' => 'p'],
        ] as $cfg) {
            ManticoreClientFactory::setUseRustFfi(false);
            $php = ManticoreClientFactory::clientConfig($cfg);
            ManticoreClientFactory::setUseRustFfi(true);
            self::assertSame($php, ManticoreClientFactory::clientConfig($cfg));
        }
    }

    #[Test]
    public function randomized_fuzz_and_locale_restoration_parity(): void
    {
        $startupIsC = preg_match('/^\s$/', "\xa0") === 0;
        $prevLocale = setlocale(LC_CTYPE, '0') ?: 'C';
        try {
            $rng = new Randomizer(new Mt19937(20261024));
            $alphabet = "0123456789-+.,eEhHdDx \t\n\r\x00\x0B\x0C\xa0";
            foreach (['C', 'en_US.ISO8859-1', 'en_US.UTF-8', 'de_DE.ISO8859-1'] as $locale) {
                if (setlocale(LC_CTYPE, $locale) === false) {
                    continue;
                }
                for ($i = 0; $i < 400; $i++) {
                    $s = '';
                    $len = $rng->getInt(0, 24);
                    for ($j = 0; $j < $len; $j++) {
                        $s .= $alphabet[$rng->getInt(0, \strlen($alphabet) - 1)];
                    }
                    $this->assertCategoryIdsParity($s);
                    $this->assertScalarParity($s);
                    $supported = false;
                    self::assertSame(NntmuxSearchReconcile::parseSinceWindowPhp($s), NntmuxSearchReconcile::parseSinceWindowNative($s, $supported), $locale.' '.json_encode($s));
                    $hosts = str_replace(['e', 'E'], ['a', 'b'], $s);
                    self::assertSame(ManticoreClientFactory::normalizeHostsPhp($hosts), ManticoreClientFactory::normalizeHostsNative($hosts));
                }
            }
        } finally {
            setlocale(LC_CTYPE, $prevLocale);
            if ($startupIsC) {
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

        $commonIds = range(80_000_001, 80_000_500);
        $uncommonBatch = range(90_000_001, 90_001_000);
        $uncommonIndexed = array_values(array_filter($uncommonBatch, static fn (int $id): bool => $id % 3 !== 0));
        shuffle($uncommonIndexed);
        $manyCats = implode(', ', array_merge(range(5000, 5090, 10), range(2000, 2090, 10), ['junk', '', ' -2 '], range(7000, 7150, 10)));
        $config = ['host' => 'manticore', 'port' => 9308, 'scheme' => 'http'];
        $tailIds = range(80_000_001, 80_000_016);

        /** @var array<string, array{Closure(): mixed, Closure(): mixed}> $cases */
        $cases = [
            'RssController::parseCategoryIds[common]' => [static fn () => RssController::parseCategoryIdsNative('5030,5040'), static fn () => RssController::parseCategoryIdsPhp('5030,5040')],
            'RssController::parseCategoryIds[uncommon]' => [static fn () => RssController::parseCategoryIdsNative($manyCats), static fn () => RssController::parseCategoryIdsPhp($manyCats)],
            'RssController::normalizeFeedId[common]' => [static fn () => RssController::normalizeFeedIdNative('12345'), static fn () => RssController::normalizeFeedIdPhp('12345')],
            'RssController::normalizeFeedId[uncommon]' => [static fn () => RssController::normalizeFeedIdNative(" \t-0042 "), static fn () => RssController::normalizeFeedIdPhp(" \t-0042 ")],
            'RssController::absNumericOrDefault[common]' => [static fn () => RssController::absNumericOrDefaultNative('7', -1), static fn () => RssController::absNumericOrDefaultPhp('7', -1)],
            'RssController::absNumericOrDefault[uncommon]' => [static fn () => RssController::absNumericOrDefaultNative('not-a-number', -1), static fn () => RssController::absNumericOrDefaultPhp('not-a-number', -1)],
            'RSS::filterFragments[common]' => [static fn () => RSS::filterFragmentsNative(0, 0, -1, false, 100, 0), static fn () => RSS::filterFragmentsPhp(0, 0, -1, false, 100, 0)],
            'RSS::filterFragments[uncommon]' => [static fn () => RSS::filterFragmentsNative(123456, 98765, 30, true, 5000, 250), static fn () => RSS::filterFragmentsPhp(123456, 98765, 30, true, 5000, 250)],
            'NntmuxSearchReconcile::parseSinceWindow[common]' => [static fn () => NntmuxSearchReconcile::parseSinceWindowNative('24h'), static fn () => NntmuxSearchReconcile::parseSinceWindowPhp('24h')],
            'NntmuxSearchReconcile::parseSinceWindow[uncommon]' => [static fn () => NntmuxSearchReconcile::parseSinceWindowNative(' 2026-10-01 00:00:00 '), static fn () => NntmuxSearchReconcile::parseSinceWindowPhp(' 2026-10-01 00:00:00 ')],
            'NntmuxSearchReconcile::missingIds[common]' => [static fn () => NntmuxSearchReconcile::missingIdsNative($commonIds, $commonIds), static fn () => NntmuxSearchReconcile::missingIdsPhp($commonIds, $commonIds)],
            'NntmuxSearchReconcile::missingIds[uncommon]' => [static fn () => NntmuxSearchReconcile::missingIdsNative($uncommonBatch, $uncommonIndexed), static fn () => NntmuxSearchReconcile::missingIdsPhp($uncommonBatch, $uncommonIndexed)],
            'NntmuxSearchReconcile::indexedIdsProbeSql[common]' => [static fn () => NntmuxSearchReconcile::indexedIdsProbeSqlNative('releases_rt', $commonIds), static fn () => NntmuxSearchReconcile::indexedIdsProbeSqlPhp('releases_rt', $commonIds)],
            'NntmuxSearchReconcile::indexedIdsProbeSql[uncommon]' => [static fn () => NntmuxSearchReconcile::indexedIdsProbeSqlNative('rel`eases_rt', $uncommonBatch), static fn () => NntmuxSearchReconcile::indexedIdsProbeSqlPhp('rel`eases_rt', $uncommonBatch)],
            'NntmuxSearchReconcile::missingIds[dispatch tail-16]' => [static fn () => NntmuxSearchReconcile::missingIds($tailIds, $tailIds), static fn () => NntmuxSearchReconcile::missingIdsPhp($tailIds, $tailIds)],
            'NntmuxSearchReconcile::indexedIdsProbeSql[dispatch tail-16]' => [static fn () => NntmuxSearchReconcile::indexedIdsProbeSql('releases_rt', $tailIds), static fn () => NntmuxSearchReconcile::indexedIdsProbeSqlPhp('releases_rt', $tailIds)],
            'NntmuxCheckIndex::formatBytes[common]' => [static fn () => NntmuxCheckIndex::formatBytesNative(5_368_709_120), static fn () => NntmuxCheckIndex::formatBytesPhp(5_368_709_120)],
            'NntmuxCheckIndex::formatBytes[uncommon]' => [static fn () => NntmuxCheckIndex::formatBytesNative(PHP_INT_MAX), static fn () => NntmuxCheckIndex::formatBytesPhp(PHP_INT_MAX)],
            'NntmuxCheckIndex::manticoreTableName[common]' => [static fn () => NntmuxCheckIndex::manticoreTableNameNative('releases'), static fn () => NntmuxCheckIndex::manticoreTableNamePhp('releases')],
            'NntmuxCheckIndex::manticoreTableName[uncommon]' => [static fn () => NntmuxCheckIndex::manticoreTableNameNative('unknown'), static fn () => NntmuxCheckIndex::manticoreTableNamePhp('unknown')],
            'ManticoreClientFactory::normalizeHosts[common]' => [static fn () => ManticoreClientFactory::normalizeHostsNative('manticore:9308'), static fn () => ManticoreClientFactory::normalizeHostsPhp('manticore:9308')],
            'ManticoreClientFactory::normalizeHosts[uncommon]' => [static fn () => ManticoreClientFactory::normalizeHostsNative(' m1:9308 , m2:9308,,0, https://m3:9443/api , m4 ,m5:1,m6:2 '), static fn () => ManticoreClientFactory::normalizeHostsPhp(' m1:9308 , m2:9308,,0, https://m3:9443/api , m4 ,m5:1,m6:2 ')],
            'ManticoreClientFactory::parseHost[common]' => [static fn () => ManticoreClientFactory::parseHostNative($config, '127.0.0.1'), static fn () => ManticoreClientFactory::parseHostPhp($config, '127.0.0.1')],
            'ManticoreClientFactory::parseHost[uncommon]' => [static fn () => ManticoreClientFactory::parseHostNative($config, 'https://search.example.internal:9443/api'), static fn () => ManticoreClientFactory::parseHostPhp($config, 'https://search.example.internal:9443/api')],
        ];

        $rng = new Randomizer(new Mt19937(20261024));
        $iterations = (int) (getenv('RUST_FFI_BENCHMARK_ITERATIONS') ?: 6000);
        $idx99 = (int) floor($iterations * 0.99);
        $adopted = [
            'NntmuxSearchReconcile::missingIds[common]',
            'NntmuxSearchReconcile::missingIds[uncommon]',
            'NntmuxSearchReconcile::indexedIdsProbeSql[common]',
            'NntmuxSearchReconcile::indexedIdsProbeSql[uncommon]',
        ];
        $report = [];
        $speedups = [];
        foreach ($cases as $label => [$native, $php]) {
            self::assertEquals($php(), $native(), $label);
            for ($w = 0; $w < 300; $w++) {
                $native();
                $php();
            }
            $rTimes = [];
            $pTimes = [];
            for ($i = 0; $i < $iterations; $i++) {
                if ($rng->getInt(0, 1) === 0) {
                    $t0 = hrtime(true);
                    $native();
                    $rTimes[] = hrtime(true) - $t0;
                    $t1 = hrtime(true);
                    $php();
                    $pTimes[] = hrtime(true) - $t1;
                } else {
                    $t1 = hrtime(true);
                    $php();
                    $pTimes[] = hrtime(true) - $t1;
                    $t0 = hrtime(true);
                    $native();
                    $rTimes[] = hrtime(true) - $t0;
                }
            }
            sort($rTimes);
            sort($pTimes);
            $rMean = array_sum($rTimes) / $iterations;
            $pMean = array_sum($pTimes) / $iterations;
            $speedups[$label] = [$pMean / max(1.0, $rMean), $pTimes[$idx99] / max(1, $rTimes[$idx99])];
            $report[] = sprintf(
                '%-52s PHP %9.1f ns mean / %7d ns p99 | Rust %9.1f ns mean / %7d ns p99 | %5.2fx mean / %5.2fx p99',
                $label,
                $pMean,
                $pTimes[$idx99],
                $rMean,
                $rTimes[$idx99],
                $pMean / max(1.0, $rMean),
                $pTimes[$idx99] / max(1, $rTimes[$idx99]),
            );
        }
        fwrite(STDERR, "\n".implode("\n", $report)."\n");
        foreach ($adopted as $label) {
            self::assertGreaterThanOrEqual(1.5, $speedups[$label][0], "{$label} mean speedup");
            self::assertGreaterThanOrEqual(1.5, $speedups[$label][1], "{$label} p99 speedup");
        }
    }

    private function assertCategoryIdsParity(string $input): void
    {
        $supported = false;
        $native = RssController::parseCategoryIdsNative($input, $supported);
        self::assertTrue($supported, json_encode($input) ?: '');
        self::assertSame(RssController::parseCategoryIdsPhp($input), $native, json_encode($input) ?: bin2hex($input));
    }

    private function assertScalarParity(mixed $value): void
    {
        $label = var_export($value, true);
        $supported = false;
        $native = RssController::normalizeFeedIdNative($value, $supported);
        if ($supported) {
            self::assertSame(RssController::normalizeFeedIdPhp($value), $native, $label);
        } else {
            self::assertNull($native);
        }

        $supported = false;
        $native = RssController::absNumericOrDefaultNative($value, -1, $supported);
        if ($supported) {
            self::assertSame(RssController::absNumericOrDefaultPhp($value, -1), $native, $label);
        } else {
            self::assertNull($native);
        }
    }

    private function resetToggles(): void
    {
        foreach (self::TOGGLED as $class) {
            $class::setUseRustFfi(null);
        }
    }
}
