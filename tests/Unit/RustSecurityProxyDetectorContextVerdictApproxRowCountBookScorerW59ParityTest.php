<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Security\IndexerProxyDetector;
use App\Services\Security\ProxyRequestContext;
use App\Services\Security\ProxyVerdict;
use App\Support\ApproximateRowCount;
use App\Support\BookMatchScorer;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use RuntimeException;

final class RustSecurityProxyDetectorContextVerdictApproxRowCountBookScorerW59ParityTest extends TestCase
{
    /** Class => batch method. */
    private const WAVE = [
        IndexerProxyDetector::class => 'detectProxyBatch',
        ProxyRequestContext::class => 'summarizeContextBatch',
        ProxyVerdict::class => 'rollupVerdictBatch',
        ApproximateRowCount::class => 'planRowCountBatch',
        BookMatchScorer::class => 'rankCandidateBatch',
    ];

    /** Header words before the per-record rows. */
    private const HEADER = [
        IndexerProxyDetector::class => IndexerProxyDetector::W59_DETECT_HEADER,
        ProxyRequestContext::class => ProxyRequestContext::W59_CONTEXT_HEADER,
        ProxyVerdict::class => ProxyVerdict::W59_VERDICT_HEADER,
        ApproximateRowCount::class => ApproximateRowCount::W59_ROWS_HEADER,
        BookMatchScorer::class => BookMatchScorer::W59_BOOK_HEADER,
    ];

    /** Words per record. */
    private const ROW_WORDS = [
        IndexerProxyDetector::class => 1,
        ProxyRequestContext::class => 2,
        ProxyVerdict::class => 1,
        ApproximateRowCount::class => 1,
        BookMatchScorer::class => 1,
    ];

    /**
     * Apple M-series/macOS arm64, PHP 8.5.12 CLI without opcache/JIT, release Rust; base b7c37a17f plus
     * this change, seed 596, 50 warmups and 1,000 pairs/workload (w596-benchmark-cf98fccdb3221da2.json in
     * system tmp, shared host under load). Common mean/p99 then uncommon mean/p99 speedups (PHP time /
     * Rust time); a default is Rust only when all four reach 1.5x. All five classes exceed 1.5x on every
     * metric.
     */
    private const QUALIFICATION = [
        IndexerProxyDetector::class => [3.977, 3.349, 2.752, 2.291],
        ProxyRequestContext::class => [6.901, 6.142, 9.471, 10.095],
        ProxyVerdict::class => [4.714, 3.680, 4.985, 5.982],
        ApproximateRowCount::class => [5.241, 3.759, 2.917, 2.392],
        BookMatchScorer::class => [3.500, 3.315, 2.268, 2.339],
    ];

    private const AGENTS = [
        'NZBHydra2/5.3.0 (Linux; amd64)', 'Prowlarr/1.20.0.4590 (ubuntu 22.04)', 'Sonarr/4.0.9.2244 (debian 12)', 'Radarr/5.9.1.9070',
        'SABnzbd/4.3.3', 'NZBGet/21.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36',
        'curl/8.7.1', 'Lidarr/2.5.3', 'Readarr/0.4.0', 'Mylar3/0.8.0', 'Jackett/0.22', 'NZBVortex/3.0', 'python-requests/2.32.3',
    ];

    private const VIAS = ['', '', '', '1.1 varnish', '1.1 proxy.local, 1.1 cdn', '1.1 nzbhydra2', 'HTTP/1.1 prowlarr-gw, 1.0 squid'];

    private const TRUSTED = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.1', '::1', 'fd00::/8'];

    private const REASONS = ['referer', 'ua_pair', 'download_ratio', 'ip_correlation', 'indexer_ua', 'empty_ua', 'private_ip', 'local_referer'];

    private const DRIVERS = ['mysql', 'mysql', 'mariadb', 'mariadb', 'mysql', 'sqlite', 'pgsql', 'sqlsrv'];

    /** [title, author] pairs. */
    private const BOOKS = [
        ['The Hobbit', 'J. R. R. Tolkien'], ['The Fellowship of the Ring', 'J.R.R. Tolkien'], ['Dune', 'Frank Herbert'],
        ['Children of Dune', 'Frank Herbert'], ['The Name of the Wind', 'Patrick Rothfuss'], ['A Game of Thrones', 'George R. R. Martin'],
        ['Project Hail Mary', 'Andy Weir'], ['The Martian', 'Andy Weir'], ['Good Omens', 'Terry Pratchett, Neil Gaiman'],
        ['Thinking, Fast and Slow', 'Daniel Kahneman'], ['Clean Code: A Handbook of Agile Software Craftsmanship', 'Robert C. Martin'],
        ['Design Patterns - Elements of Reusable Object-Oriented Software', 'Erich Gamma'], ['The Pragmatic Programmer (20th Anniversary Edition)', 'David Thomas'],
        ['Sapiens: A Brief History of Humankind', 'Yuval Noah Harari'], ['1984', 'George Orwell'], ['Brave New World', 'Aldous Huxley'],
    ];

    private const BOOK_SUFFIXES = ['', '', '', ': A Novel', ' (2nd Edition)', ' - Unabridged', ' [Illustrated]', ' Deluxe Edition', ' epub', ': Book 1'];

    public function test_wave_methods_and_constants_exist(): void
    {
        foreach (self::WAVE as $class => $method) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW59RustFfi', 'withW59RustFfi', 'w59RustFfiState', $method, $method.'Php', $method.'Native'] as $name) {
                self::assertTrue($reflection->hasMethod($name), $class.'::'.$name);
            }
            $state = $reflection->getMethod('w59RustFfiState');
            self::assertTrue($state->isPrivate() && $state->isStatic(), $class);
            self::assertSame(2, $state->getNumberOfParameters(), $class);
            foreach ([$method, $method.'Php', $method.'Native', 'usesW59RustFfi', 'withW59RustFfi'] as $name) {
                self::assertTrue($reflection->getMethod($name)->isStatic(), $class.'::'.$name);
                self::assertTrue($reflection->getMethod($name)->isPublic(), $class.'::'.$name);
            }
            self::assertTrue($reflection->getReflectionConstant('W59_RUST_FFI_DEFAULT')?->isPublic() ?? false, $class);
            self::assertIsBool($reflection->getConstant('W59_RUST_FFI_DEFAULT'), $class);
            self::assertStringContainsString('nntmux_w596_', (string) $reflection->getConstant('W59_FFI_CDEF'), $class);
            foreach ($reflection->getProperties() as $property) {
                self::assertStringNotContainsString('W59', $property->getName(), $class);
                self::assertStringNotContainsString('w59', $property->getName(), $class);
            }
        }
        self::assertTrue((new ReflectionClass(ProxyRequestContext::class))->isReadOnly());
        self::assertTrue((new ReflectionClass(ProxyVerdict::class))->isReadOnly());
        self::assertTrue((new ReflectionClass(ProxyVerdict::class))->isFinal());
    }

    public function test_defaults_match_recorded_qualification_and_toggles_restore(): void
    {
        foreach (array_keys(self::WAVE) as $class) {
            $expected = (bool) (new ReflectionClass($class))->getConstant('W59_RUST_FFI_DEFAULT');
            self::assertSame($expected, min(self::QUALIFICATION[$class]) >= 1.5, $class);
            self::assertSame($expected, $class::usesW59RustFfi(), $class);
            if (! $expected) {
                self::assertFalse($class::usesW59RustFfi(), $class.' retains PHP by default');
            }
            foreach ([true, false] as $enabled) {
                $seen = $class::withW59RustFfi($enabled, function () use ($class, $enabled): bool {
                    $inner = $class::withW59RustFfi(! $enabled, static fn (): bool => $class::usesW59RustFfi());
                    self::assertSame(! $enabled, $inner);
                    foreach (array_keys(self::WAVE) as $other) {
                        if ($other !== $class) {
                            self::assertSame((bool) (new ReflectionClass($other))->getConstant('W59_RUST_FFI_DEFAULT'), $other::usesW59RustFfi());
                        }
                    }

                    return $class::usesW59RustFfi();
                });
                self::assertSame($enabled, $seen, $class);
                self::assertSame($expected, $class::usesW59RustFfi(), $class);
            }
            $thrown = null;
            try {
                $class::withW59RustFfi(! $expected, static function (): bool {
                    throw new RuntimeException('boom');
                });
            } catch (RuntimeException $e) {
                $thrown = $e->getMessage();
            }
            self::assertSame('boom', $thrown);
            self::assertSame($expected, $class::usesW59RustFfi(), $class);
        }
    }

    public function test_existing_behavior_is_unchanged(): void
    {
        $verdict = ProxyVerdict::fromScorePhp(60, 50, ['referer' => 30]);
        self::assertTrue($verdict->shouldBlock);
        self::assertSame(['referer' => 30], $verdict->reasons);
        self::assertFalse(ProxyVerdict::allowPhp()->shouldBlock);
        self::assertSame(3, \count(ProxyVerdict::aggregateVerdictsPhp(['1.2.3.4'], ['referer:30'], 50)) - 1);
        self::assertSame('k', ProxyRequestContext::resolveApiTokenPhp([null, '', 'k', 'z']));
        self::assertNull(ProxyRequestContext::resolveApiTokenPhp([1, '']));
        self::assertSame([-3, 1 << 18], ProxyRequestContext::resolveForwardedChainsPhp(['10.0.0.1'], [''], ['10.0.0.0/8']));
        $context = new ProxyRequestContext('1.2.3.4', 'UA', 'tok', null, true, false);
        self::assertSame('tok', $context->apiToken);
        self::assertSame([25 | 2 << 8], IndexerProxyDetector::scoreRequestsPhp(['203.0.113.1'], ['Sonarr/4'], [''], ''));
        self::assertSame(12, ApproximateRowCount::normalizeApproximateRowsPhp('12'));
        self::assertNull(ApproximateRowCount::normalizeApproximateRowsPhp(0));
        self::assertTrue(ApproximateRowCount::driverSupportsInformationSchemaPhp('mariadb'));
        $scorer = new BookMatchScorer;
        self::assertSame(1.0, $scorer->titleScorePhp('The Hobbit', 'the hobbit'));
        self::assertSame(1.0, $scorer->authorScorePhp('J. R. R. Tolkien', 'J R R Tolkien'));
        self::assertSame(['edition' => 2], $scorer->structuralMarkersPhp('Dune 2nd Edition'));
        self::assertSame('dune|frank herbert', $scorer->workKeyPhp(['title' => 'Dune', 'author' => 'Herbert, Frank']));
    }

    public function test_proxy_detect_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(IndexerProxyDetector::class, [[], [], []]);
        self::assertSame([0, 0, -1], [$empty[0], $empty[7], $empty[8]]);
        $out = $this->assertParity(IndexerProxyDetector::class, [['NZBHydra2/5.0', 'SABnzbd/4.0', ''], ['1.1 prowlarr, 1.1 x', '', ''], ['a, b', ' , ', '1,2,3,4,5'], 50]);
        self::assertSame([3, 1, 1, 1, 1, 2, 7, 90, 0], \array_slice($out, 0, 9));
        self::assertSame(90 | 1 << 7 | 1 << 8 | 1 << 12 | 2 << 24 | 2 << 32 | 1 << 40 | 13 << 42, $out[IndexerProxyDetector::W59_DETECT_HEADER]);
        self::assertSame(35 | 5 << 32 | 1 << 41, $out[IndexerProxyDetector::W59_DETECT_HEADER + 2]);
        $this->assertParity(IndexerProxyDetector::class, [
            ["Sonarr\0Radarr", "\0", 'PROWLARR', 'nzbunity nzbvortex sabnzbd nzbget', str_repeat('x', 70_000).'mylar', "\xff\xfeNZBHYDRA", ' '],
            ["\0nzbhydra", 'jackett', ",,,\t,", str_repeat('a,', 400), '', "x\0y", 'LIDARR'],
            ["1\0,2", '', str_repeat(',', 300), ' ,a', "\t", str_repeat('h,', 300), 'z'],
            PHP_INT_MIN,
        ]);
        $this->assertParity(IndexerProxyDetector::class, [['a' => 'Radarr/5', 'b' => ''], [9 => '', 3 => 'via'], ['q' => '', 'r' => '1.1.1.1'], PHP_INT_MAX]);
        self::assertNull(IndexerProxyDetector::detectProxyBatchNative(['x'], [], ['']));
    }

    public function test_proxy_context_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(ProxyRequestContext::class, [[], [], [], self::TRUSTED]);
        self::assertSame([0, -1, 0], [$empty[0], $empty[14], $empty[13]]);
        $out = $this->assertParity(ProxyRequestContext::class, [
            ['10.0.0.1', '203.0.113.9', 'bogus', '::1'], ['198.51.100.7, 10.0.0.2', '', '', 'x, 1.2.3.4'],
            [str_repeat('a', 32), ' k1 ', 'bad key!', str_repeat('z', 65)], ['10.0.0.0/8', '::1'],
        ]);
        self::assertSame([4, 1, 1, 0, 1, 1, 0, 1, 1, 1, 1], \array_slice($out, 0, 11));
        self::assertSame([2, 3], [$out[13], $out[14]]);
        self::assertSame(1 << 48 | 198 << 16 | 51 << 8 | 100, $out[ProxyRequestContext::W59_CONTEXT_HEADER + 1]);
        $this->assertParity(ProxyRequestContext::class, [
            ['10.1.2.3', '10.1.2.3', "10.1.2.3\0", '[::1]', '::ffff:10.0.0.1', '', 'fd00::5', '127.0.0.1', '192.168.1.1', '172.31.255.255'],
            ['[2001:db8::1]:443, 10.0.0.9', "1.2.3.4\0, 10.0.0.1", '1.2.3.4', '5.6.7.8', '9.9.9.9:8080, 10.0.0.3', '1.1.1.1',
                '2001:db8::2, fd00::9', ' , ', '1.2.3.4:99999', str_repeat('10.0.0.1, ', 300).'8.8.8.8'],
            ["\tABCDEF0123456789abcdef0123456789", "k\0ey", '', ' ', str_repeat('0', 64), 'ÄÖÜ', '0123456789abcdef0123456789abcdef', "\0", 'a-b', 'Z'],
            ['10.0.0.0/8', 'bogus', '10.0.0.0/33', "1.2.3.4\0", '', '2001:db8::/129', 'fd00::/8', '127.0.0.1', '192.168.0.0/16', '172.16.0.0/012', '::ffff:10.0.0.0/104'],
        ]);
        $this->assertParity(ProxyRequestContext::class, [['8.8.8.8', '10.0.0.1'], ['', '8.8.4.4'], ['', ''], []]);
        $this->assertParity(ProxyRequestContext::class, [['10.0.0.1', '10.0.0.2'], ['8.8.4.4', '0.0.0.0'], ['', ''], ['0.0.0.0/0', "\0", "\0\0"]]);
        $this->assertParity(ProxyRequestContext::class, [['x' => '10.0.0.1'], [5 => '8.8.8.8'], ['k' => 'key'], ['a' => '10.0.0.0/8']]);
        self::assertNull(ProxyRequestContext::summarizeContextBatchNative(['1.2.3.4'], [''], [], []));
    }

    public function test_proxy_verdict_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(ProxyVerdict::class, [[], []]);
        self::assertSame([0, -1, 0], [$empty[0], $empty[14], $empty[15]]);
        $out = $this->assertParity(ProxyVerdict::class, [[60, -5, 30], ['referer, ua_pair,referer,bogus', '', 'download_ratio'], 50]);
        self::assertSame([3, 1, 1, 1, 1, 1, 1, 0], \array_slice($out, 0, 8));
        self::assertSame([60, 90, 0, 53, 1], \array_slice($out, 12, 5));
        self::assertSame(60 | 2 << 10 | 100 << 12 | 3 << 19 | 1 << 27 | 1 << 35 | 4 << 43, $out[ProxyVerdict::W59_VERDICT_HEADER]);
        foreach ([PHP_INT_MIN, -1, 0, 1, 7, 1_000, 1_001, PHP_INT_MAX] as $threshold) {
            $this->assertParity(ProxyVerdict::class, [
                [PHP_INT_MIN, PHP_INT_MAX, 0, 1_000, 1_001, -1, 499, 500, 3],
                ["referer\0", 'REFERER', ',,,', " \tua_pair\t ", str_repeat('empty_ua,', 300), "\0", 'x,y,z', 'local_referer,private_ip,indexer_ua,empty_ua,ip_correlation,download_ratio,ua_pair,referer', '0,1'],
                $threshold,
            ]);
        }
        $this->assertParity(ProxyVerdict::class, [['a' => 5, 'b' => 70], [4 => 'referer', 2 => ''], 60]);
        self::assertNull(ProxyVerdict::rollupVerdictBatchNative([1], []));
    }

    public function test_row_count_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(ApproximateRowCount::class, [[], []]);
        self::assertSame([0, -1, 0], [$empty[0], $empty[13], $empty[11]]);
        $out = $this->assertParity(ApproximateRowCount::class, [['5000', '10', ''], ['mysql', 'mariadb', 'sqlite'], 100, 1]);
        self::assertSame([3, 1, 0, 1, 1], \array_slice($out, 0, 5));
        self::assertSame([5000, 5000, 1, 1], \array_slice($out, 11, 4));
        $this->assertParity(ApproximateRowCount::class, [
            [' 0012 ', 'NuLl', '-0', '-3', '1e5', '12.5', '1234567890123456789', '999999999999999999', "\0 42 \0", "\x0B7\n", '+', '-', '+0', '++1',
                "12\0", 'NULL ', "\tnull\t", '0x10', '१२', str_repeat('9', 300), '000000000000000000000000001', ' - 5', '-00'],
            ['mysql', 'mariadb', 'mysql', 'mariadb', 'mysql', 'mysql', 'mariadb', 'mysql', 'mysql', 'mariadb', 'mysql', 'pgsql', 'MYSQL', 'mysql ',
                "mysql\0", '', 'sqlsrv', 'sqlite', 'mysql', 'mariadb', 'mysql', 'mysql', 'mariadb'],
            PHP_INT_MIN, PHP_INT_MAX,
        ]);
        foreach ([[PHP_INT_MAX, PHP_INT_MIN], [0, 0], [-1, -1], [1 << 40, 1 << 40]] as [$min, $max]) {
            $this->assertParity(ApproximateRowCount::class, [['1099511627776', '1099511627775', '5', '0', '-1'], ['mysql', 'mysql', 'mariadb', 'mysql', 'mysql'], $min, $max]);
        }
        $this->assertParity(ApproximateRowCount::class, [['x' => '12', 'y' => 'NULL'], [3 => 'mysql', 1 => 'pgsql'], 1, 2]);
        self::assertNull(ApproximateRowCount::planRowCountBatchNative(['1'], []));
    }

    public function test_book_rank_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(BookMatchScorer::class, ['The Hobbit', 'Tolkien', [], []]);
        self::assertSame([0, -1, -1, -1, 0, 1, 1, 1], \array_slice($empty, 0, 8));
        $out = $this->assertParity(BookMatchScorer::class, [
            'The Hobbit', 'J.R.R. Tolkien', ['The Hobbit: There and Back Again', 'Hobbit', 'Silmarillion'], ['J. R. R. Tolkien', 'Tolkien', ''],
        ]);
        self::assertSame([0, 1, 3, 1], [$out[1], $out[5], $out[6], $out[7]]);
        self::assertSame([2, 1, 4], [
            $out[BookMatchScorer::W59_BOOK_HEADER] >> 50, $out[BookMatchScorer::W59_BOOK_HEADER + 1] >> 50, $out[BookMatchScorer::W59_BOOK_HEADER + 2] >> 50,
        ]);
        $titles = ['', ':', '(', ' - ', 'The', 'A 2nd Edition', 'Les Misérables', 'LES MISÉRABLES', 'Война и мир', "Dune\0Messiah", 'Dune: Messiah',
            '1984', '01984', '12th Night', 'Night 12th', 'Hobbit - The: x', str_repeat('word ', 400), 'a b c d e f', '12 34 56', "\xff\xfe", 'Dune (1965)', 'DUNE', '[x] Dune'];
        $authors = ['', 'J', 'j r r', 'Frank Herbert', 'HERBERT, FRANK', "F\0Herbert", 'Victor Hugo', 'Лев Толстой', str_repeat('n ', 300), 'f h', 'Herbert', '-', 'a.b.c',
            'Frank', 'F. Herbert', 'X', 'x y', '0', '00 0', 'é', 'Dune', 'Frank Herbert Jr.', ''];
        foreach ([['Dune', 'Frank Herbert'], ['', ''], ['Dune: Messiah', ''], [': Dune', 'F'], ["Dune\0", "Frank\0Herbert"], ['Les Misérables (Unabridged)', 'Victor Hugo'], ['The of and', 'x'], ['1984 - Nineteen', '']] as [$title, $author]) {
            $this->assertParity(BookMatchScorer::class, [$title, $author, $titles, $authors]);
        }
        $this->assertParity(BookMatchScorer::class, ['Dune', 'Frank', ['q' => 'Dune', 'r' => 'Dune'], [7 => 'Frank', 2 => 'Frank']]);
        self::assertNull(BookMatchScorer::rankCandidateBatchNative('x', 'y', ['a'], []));
    }

    /**
     * @return iterable<string, array{0: class-string, 1: int, 2: bool, 3: int}>
     */
    public static function datasetProvider(): iterable
    {
        foreach (array_keys(self::WAVE) as $class) {
            $short = (new ReflectionClass($class))->getShortName();
            yield $short.' common' => [$class, 320, false, 596];
            yield $short.' uncommon' => [$class, 384, true, 597];
            yield $short.' large common' => [$class, 2_048, false, 598];
        }
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('datasetProvider')]
    public function test_realistic_dataset_parity(string $class, int $count, bool $uncommon, int $seed): void
    {
        $this->requireBindings();
        $out = $this->assertParity($class, $this->benchmarkArgs($class, $count, $uncommon, $seed));
        self::assertSame($count, $out[0]);
    }

    public function test_randomized_fuzz_parity(): void
    {
        $this->requireBindings();
        $checked = array_fill_keys(array_keys(self::WAVE), 0);
        for ($round = 0; $round < 40; $round++) {
            foreach (array_keys(self::WAVE) as $class) {
                $count = (new Randomizer(new Mt19937($round)))->getInt(0, 96);
                $this->assertParity($class, $this->benchmarkArgs($class, $count, $round % 2 === 1, 9_600 + $round));
                $checked[$class] += $count;
            }
        }
        foreach ($checked as $class => $records) {
            self::assertGreaterThanOrEqual(300, $records, $class);
        }
    }

    /**
     * Protocol (set NNTMUX_W596_BENCH=1): seed 596, 8 fixture variants, 50 warmups and
     * NNTMUX_W596_BENCH_PAIRS (default 1,000, at least 60) randomized-order interleaved pairs per
     * class/workload. Common batches hold 320 typical records; uncommon ones 384 malformed, unknown,
     * NUL-holding, oversized or extreme records. Fixtures are built outside timing and parity is
     * asserted on every pair. Timings cover public dispatch, marshaling, FFI and unpacking. Nearest-rank
     * p99. The summary and raw pairs are written to the system temp directory.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        if (getenv('NNTMUX_W596_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W596_BENCH=1 to run the paired Wave 59.6 benchmark.');
        }
        $this->requireBindings();
        $pairs = max(60, (int) (getenv('NNTMUX_W596_BENCH_PAIRS') ?: 1000));
        $random = new Randomizer(new Mt19937(596));
        $summary = $records = [];
        foreach (array_keys(self::WAVE) as $class) {
            foreach (['common' => false, 'uncommon' => true] as $workload => $uncommon) {
                $variants = [];
                for ($v = 0; $v < 8; $v++) {
                    $variants[] = $this->benchmarkCall($class, $uncommon ? 384 : 320, $uncommon, 596 + $v * 31 + ($uncommon ? 7 : 0));
                }
                $php = $rust = [];
                for ($i = -50; $i < $pairs; $i++) {
                    $variant = $random->getInt(0, 7);
                    $order = $random->getInt(0, 1) === 0 ? [false, true] : [true, false];
                    $results = $elapsed = [];
                    foreach ($order as $native) {
                        $call = $variants[$variant];
                        // The routing scope is set outside the timed region; the public batch call (its own
                        // dispatch, marshaling, FFI and unpacking) is timed inside it.
                        [$results[(int) $native], $elapsed[(int) $native]] = $class::withW59RustFfi($native, static function () use ($call): array {
                            $start = hrtime(true);
                            $result = $call();

                            return [$result, hrtime(true) - $start];
                        });
                    }
                    self::assertSame($results[0], $results[1], $class.' '.$workload);
                    if ($i >= 0) {
                        $php[] = $elapsed[0];
                        $rust[] = $elapsed[1];
                        $records[] = [$class, $workload, $variant, $order[0] ? 'rust-first' : 'php-first', $elapsed[0], $elapsed[1]];
                    }
                }
                sort($php);
                sort($rust);
                $p99 = (int) ceil($pairs * 0.99) - 1;
                $summary[$class][$workload] = [
                    'mean_speedup' => round(array_sum($php) / array_sum($rust), 3),
                    'p99_speedup' => round($php[$p99] / $rust[$p99], 3),
                    'php_mean_ns' => intdiv(array_sum($php), $pairs), 'rust_mean_ns' => intdiv(array_sum($rust), $pairs),
                    'php_p99_ns' => $php[$p99], 'rust_p99_ns' => $rust[$p99],
                ];
            }
        }
        $metadata = ['utc' => gmdate('c'), 'php' => PHP_VERSION, 'os' => php_uname(), 'pairs' => $pairs, 'warmups' => 50, 'seed' => 596, 'variants' => 8,
            'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')];
        $artifact = sys_get_temp_dir().'/w596-benchmark-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($artifact, json_encode(['metadata' => $metadata, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW59.6 paired benchmark artifact: ".$artifact."\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach (array_keys(self::WAVE) as $class) {
            $eligible = true;
            foreach ($summary[$class] as $metrics) {
                $eligible = $eligible && $metrics['mean_speedup'] >= 1.5 && $metrics['p99_speedup'] >= 1.5;
            }
            // A class below 1.5x on any metric must retain PHP; one that clears it may still retain PHP after a
            // failing run (see QUALIFICATION), but a Rust default must clear every metric.
            if (! $eligible || $class::usesW59RustFfi()) {
                self::assertSame($eligible, $class::usesW59RustFfi(), $class.' must default to Rust only when it clears >=1.5x mean/p99 on both workloads');
            }
        }
    }

    /**
     * Arguments of the class's batch method for one fixture.
     *
     * @param  class-string  $class
     * @return list<mixed>
     */
    private function benchmarkArgs(string $class, int $count, bool $uncommon, int $seed): array
    {
        $random = new Randomizer(new Mt19937($seed));

        return match ($class) {
            IndexerProxyDetector::class => $this->detectBatch($random, $count, $uncommon),
            ProxyRequestContext::class => $this->contextBatch($random, $count, $uncommon),
            ProxyVerdict::class => $this->verdictBatch($random, $count, $uncommon),
            ApproximateRowCount::class => $this->rowsBatch($random, $count, $uncommon),
            default => $this->bookBatch($random, $count, $uncommon),
        };
    }

    /**
     * @param  class-string  $class
     * @return Closure(): list<int>
     */
    private function benchmarkCall(string $class, int $count, bool $uncommon, int $seed): Closure
    {
        $args = $this->benchmarkArgs($class, $count, $uncommon, $seed);
        $method = self::WAVE[$class];

        return static fn (): array => $class::$method(...$args);
    }

    private function publicIp(Randomizer $random): string
    {
        return $random->getInt(0, 4) === 0
            ? sprintf('2001:db8:%x::%x', $random->getInt(0, 0xFFFF), $random->getInt(1, 0xFFFF))
            : $random->getInt(1, 223).'.'.$random->getInt(0, 255).'.'.$random->getInt(0, 255).'.'.$random->getInt(1, 254);
    }

    /**
     * Indexer, *arr, downloader and browser User-Agents with occasional Via and forwarded hops; uncommon
     * ones are mixed-case, empty, NUL-holding, oversized or random bytes with many blank hops.
     *
     * @return list<mixed>
     */
    private function detectBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $agents = $vias = $chains = [];
        for ($i = 0; $i < $count; $i++) {
            $agent = self::AGENTS[$random->getInt(0, \count(self::AGENTS) - 1)];
            $via = self::VIAS[$random->getInt(0, \count(self::VIAS) - 1)];
            $chain = match ($random->getInt(0, 3)) {
                0 => $this->publicIp($random).', 10.0.0.'.$random->getInt(1, 254),
                1 => $this->publicIp($random),
                default => '',
            };
            if ($uncommon) {
                match ($random->getInt(0, 11)) {
                    0 => $agent = strtoupper($agent),
                    1 => $agent = '',
                    2 => $agent = $agent."\0".self::AGENTS[$random->getInt(0, 5)],
                    3 => $agent = str_repeat('Mozilla ', $random->getInt(50, 600)).self::AGENTS[$random->getInt(0, 3)],
                    4 => $agent = substr($random->getBytes(48), 0, $random->getInt(0, 48)),
                    5 => $via = str_repeat('1.1 hop, ', $random->getInt(1, 300)),
                    6 => $via = "1.1 NZBHYDRA\0, ,\t,",
                    7 => $chain = str_repeat(' , ', $random->getInt(1, 400)),
                    8 => $chain = str_repeat($this->publicIp($random).',', $random->getInt(5, 300)),
                    9 => $agent = ' ',
                    default => null,
                };
            }
            $agents[] = $agent;
            $vias[] = $via;
            $chains[] = $chain;
        }

        return [$agents, $vias, $chains, $uncommon ? [PHP_INT_MIN, -1, 0, 40, 75, 101, PHP_INT_MAX][$random->getInt(0, 6)] : 50];
    }

    /**
     * Remote proxies with forwarded chains and API keys; uncommon ones have invalid remotes, malformed,
     * ported, bracketed, NUL-holding or long chains, odd keys and invalid trusted entries.
     *
     * @return list<mixed>
     */
    private function contextBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $remotes = $chains = $keys = [];
        for ($i = 0; $i < $count; $i++) {
            $trusted = $random->getInt(0, 3) > 0;
            $remote = $trusted ? ['10.0.'.$random->getInt(0, 255).'.'.$random->getInt(1, 254), '127.0.0.1', '192.168.1.'.$random->getInt(1, 254), '::1'][$random->getInt(0, 3)]
                : $this->publicIp($random);
            $hops = [];
            for ($h = $random->getInt(0, 3); $h > 0; $h--) {
                $hops[] = $random->getInt(0, 2) === 0 ? '172.16.'.$random->getInt(0, 255).'.'.$random->getInt(1, 254) : $this->publicIp($random);
            }
            $chain = implode(', ', $hops);
            $key = $random->getInt(0, 4) === 0 ? '' : bin2hex($random->getBytes(16));
            if ($uncommon) {
                match ($random->getInt(0, 13)) {
                    0 => $remote = 'unknown',
                    1 => $remote = '',
                    2 => $chain = $chain.', unknown',
                    3 => $chain = '['.$this->publicIp($random).']:'.$random->getInt(1, 99_999).', '.$chain,
                    4 => $chain = $this->publicIp($random).':'.$random->getInt(0, 999_999),
                    5 => $chain = $chain."\0, 10.0.0.1",
                    6 => $chain = str_repeat('10.0.0.1, ', $random->getInt(10, 400)).$this->publicIp($random),
                    7 => $key = strtoupper($key).' ',
                    8 => $key = str_repeat('k', $random->getInt(60, 300)),
                    9 => $key = "key\0".$key,
                    10 => $key = 'bad key/'.$i,
                    11 => $chain = ' , ',
                    default => null,
                };
            }
            $remotes[] = $remote;
            $chains[] = $chain;
            $keys[] = $key;
        }
        $trustedList = self::TRUSTED;
        if ($uncommon) {
            $trustedList = [...$trustedList, 'bogus', '10.0.0.0/33', "1.2.3.4\0", '', '2001:db8::/129', '0.0.0.0/'.$random->getInt(0, 1)];
        }

        return [$remotes, $chains, $keys, $trustedList];
    }

    /**
     * Analyzer scores with their reason names; uncommon ones have extreme scores, unknown, duplicate,
     * mixed-case, blank or NUL-holding names and long specs.
     *
     * @return list<mixed>
     */
    private function verdictBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $scores = $specs = [];
        for ($i = 0; $i < $count; $i++) {
            $names = [];
            foreach (self::REASONS as $index => $reason) {
                if ($random->getInt(0, $index < 4 ? 2 : 8) === 0) {
                    $names[] = $reason;
                }
            }
            $score = $random->getInt(0, 100);
            $spec = implode(',', $names);
            if ($uncommon) {
                match ($random->getInt(0, 11)) {
                    0 => $score = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    1 => $score = $random->getInt(-2_000, 3_000),
                    2 => $spec = strtoupper($spec),
                    3 => $spec = $spec.',bogus,'.$spec,
                    4 => $spec = " , \t,".$spec.', ,',
                    5 => $spec = $spec."\0,referer",
                    6 => $spec = str_repeat('ua_pair,unknown_reason,', $random->getInt(10, 300)),
                    7 => $spec = '',
                    8 => $spec = substr($random->getBytes(40), 0, $random->getInt(0, 40)),
                    default => null,
                };
            }
            $scores[] = $score;
            $specs[] = $spec;
        }

        return [$scores, $specs, $uncommon ? [PHP_INT_MIN, -1, 0, 1, 50, 999, 1_001, PHP_INT_MAX][$random->getInt(0, 7)] : 50];
    }

    /**
     * TABLE_ROWS/EXPLAIN estimates for MySQL and MariaDB with a few other drivers; uncommon ones are
     * padded, signed, NULL, fractional, exponent, NUL-holding, oversized or random estimates.
     *
     * @return list<mixed>
     */
    private function rowsBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $estimates = $drivers = [];
        for ($i = 0; $i < $count; $i++) {
            $estimate = match ($random->getInt(0, 9)) {
                0 => '0',
                1 => (string) $random->getInt(1, 999),
                default => (string) $random->getInt(1_000, 80_000_000),
            };
            $driver = self::DRIVERS[$random->getInt(0, \count(self::DRIVERS) - 1)];
            if ($uncommon) {
                match ($random->getInt(0, 13)) {
                    0 => $estimate = ' '.$estimate."\n",
                    1 => $estimate = '+'.$estimate,
                    2 => $estimate = '-'.$estimate,
                    3 => $estimate = ['NULL', 'null', '', ' '][$random->getInt(0, 3)],
                    4 => $estimate = $estimate.'.5',
                    5 => $estimate = $random->getInt(1, 9).'e'.$random->getInt(1, 30),
                    6 => $estimate = $estimate."\0",
                    7 => $estimate = str_repeat('9', $random->getInt(19, 400)),
                    8 => $estimate = str_repeat('0', $random->getInt(1, 40)).$estimate,
                    9 => $estimate = substr($random->getBytes(24), 0, $random->getInt(0, 24)),
                    10 => $driver = strtoupper($driver),
                    11 => $driver = $driver."\0",
                    default => null,
                };
            }
            $estimates[] = $estimate;
            $drivers[] = $driver;
        }

        return [$estimates, $drivers, $uncommon ? $random->getInt(-10, 10_000) : 1_000, $uncommon ? $random->getInt(PHP_INT_MIN, PHP_INT_MAX) : 5_000_000];
    }

    /**
     * A query book with candidate titles/authors (exact, subtitled, edition-marked or other books);
     * uncommon ones are mixed-case, UTF-8, NUL-holding, long, punctuation-only or empty.
     *
     * @return list<mixed>
     */
    private function bookBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        [$queryTitle, $queryAuthor] = self::BOOKS[$random->getInt(0, \count(self::BOOKS) - 1)];
        $titles = $authors = [];
        for ($i = 0; $i < $count; $i++) {
            [$title, $author] = $random->getInt(0, 3) === 0 ? [$queryTitle, $queryAuthor] : self::BOOKS[$random->getInt(0, \count(self::BOOKS) - 1)];
            $title .= self::BOOK_SUFFIXES[$random->getInt(0, \count(self::BOOK_SUFFIXES) - 1)];
            if ($random->getInt(0, 5) === 0) {
                $author = '';
            }
            if ($uncommon) {
                match ($random->getInt(0, 11)) {
                    0 => $title = strtoupper($title),
                    1 => $title = 'Les Misérables — Tome '.$random->getInt(1, 5),
                    2 => $title = $title."\0".$queryTitle,
                    3 => $title = str_repeat($title.' ', $random->getInt(10, 60)),
                    4 => $title = str_repeat('-:([ ', $random->getInt(1, 20)),
                    5 => $title = '',
                    6 => $author = 'Лев Николаевич Толстой',
                    7 => $author = str_repeat('A. ', $random->getInt(10, 100)).$author,
                    8 => $title = substr($random->getBytes(64), 0, $random->getInt(0, 64)),
                    9 => $title = $random->getInt(1, 99).['st', 'nd', 'rd', 'th'][$random->getInt(0, 3)].' '.$title,
                    default => null,
                };
            }
            $titles[] = $title;
            $authors[] = $author;
        }
        if ($uncommon && $random->getInt(0, 2) === 0) {
            $queryAuthor = '';
        }

        return [$uncommon && $random->getInt(0, 3) === 0 ? strtoupper($queryTitle).': Special' : $queryTitle, $queryAuthor, $titles, $authors];
    }

    /**
     * PHP routing, Rust routing, the PHP and native methods agree; returns the PHP result.
     *
     * @param  class-string  $class
     * @param  list<mixed>  $args
     * @return list<int>
     */
    private function assertParity(string $class, array $args): array
    {
        $method = self::WAVE[$class];
        $php = $class::withW59RustFfi(false, static fn (): array => $class::$method(...$args));
        $rust = $class::withW59RustFfi(true, static fn (): array => $class::$method(...$args));
        self::assertSame($php, $class::{$method.'Php'}(...$args), $class);
        self::assertSame($php, $class::{$method.'Native'}(...$args), $class);
        self::assertSame($php, $rust, $class);
        self::assertCount(self::HEADER[$class] + self::ROW_WORDS[$class] * $php[0], $php, $class);

        return $php;
    }

    private function requireBindings(): void
    {
        if (IndexerProxyDetector::detectProxyBatchNative([], [], []) === null
            || ProxyRequestContext::summarizeContextBatchNative([], [], [], []) === null
            || ProxyVerdict::rollupVerdictBatchNative([], []) === null
            || ApproximateRowCount::planRowCountBatchNative([], []) === null
            || BookMatchScorer::rankCandidateBatchNative('', '', [], []) === null
        ) {
            self::markTestSkipped('The Wave 59.6 Rust bindings are unavailable; build rust/pipeline-ffi in release mode.');
        }
    }
}
