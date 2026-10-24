<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Search\DTO\SearchPage;
use App\Services\Search\Support\ElasticsearchResponseHelper;
use App\Services\Security\ProxyRequestContext;
use App\Services\Security\ProxyVerdict;
use App\Support\RustPipelineLibrary;
use App\View\Components\SortDropdown;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Elastic\Elasticsearch\Response\Elasticsearch as ElasticsearchResponse;
use FFI;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;
use RuntimeException;

/**
 * Wave 18 parity and paired benchmark suite for `SearchPage`,
 * `ElasticsearchResponseHelper`, `ProxyRequestContext`, `ProxyVerdict` and
 * `SortDropdown` (`rust/pipeline-ffi/src/search.rs`).
 *
 * Reproduce paired randomized benchmarks:
 *   cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release
 *   NNTMUX_SEARCH_PAGE_PROXY_SORT_DROPDOWN_BENCH=1 php artisan test --compact tests/Unit/RustSearchPageProxySortDropdownParityTest.php --filter=test_benchmark_paired_workloads
 *
 * Protocol: seed 20261024; 512 warmups; 3 repetitions of 4,096 randomized paired
 * single calls per workload (common and uncommon) through the public PHP adapters, so
 * FFI marshaling and allocation are included; nearest-rank p99; output equality
 * verified before timing. Adoption requires >= 1.5x on all 4 metrics (common mean,
 * common p99, uncommon mean, uncommon p99) per `.ai/rules/rust-performance.md`.
 *
 * Environment: Apple M5, macOS, PHP 8.5.12 CLI (FFI), rustc 1.99.1 release (lto, cgu=1),
 * base commit 5e1a7204f. Paired speedup (`PHP latency / Rust FFI latency`, mean/p99, 3 reps):
 * - `searchPageLegacy`:      common 0.04x/0.02x, 0.04x/0.02x, 0.04x/0.02x | uncommon 0.00x/0.01x, 0.00x/0.01x, 0.00x/0.00x
 * - `esAsArray`:             common 0.45x/0.37x, 0.40x/0.40x, 0.38x/0.50x | uncommon 0.35x/0.50x, 0.34x/0.50x, 0.33x/0.50x
 * - `esIsNotFound`:          common 0.37x/0.50x, 0.39x/0.50x, 0.38x/0.50x | uncommon 0.25x/0.20x, 0.26x/0.25x, 0.29x/0.25x
 * - `proxyFromRequest`:      common 0.44x/0.31x, 0.44x/0.60x, 0.44x/0.58x | uncommon 0.73x/0.79x, 0.73x/0.83x, 0.72x/0.72x
 * - `proxyResolveToken`:     common 0.15x/0.20x, 0.13x/0.20x, 0.14x/0.20x | uncommon 0.21x/0.20x, 0.20x/0.27x, 0.20x/0.27x
 * - `proxyVerdictAllow`:     common 0.23x/0.27x, 0.23x/0.30x, 0.24x/0.30x | uncommon 0.24x/0.30x, 0.24x/0.27x, 0.24x/0.27x
 * - `proxyVerdictFromScore`: common 0.42x/0.50x, 0.43x/0.50x, 0.43x/0.43x | uncommon 0.42x/0.43x, 0.42x/0.43x, 0.41x/0.38x
 * - `sortDropdownState`:     common 0.73x/0.67x, 0.72x/0.60x, 0.74x/0.71x | uncommon 1.01x/1.00x, 0.99x/1.00x, 0.99x/0.98x
 *
 * No method qualified. Each helper is a constant, flag test, array return or a few
 * short strings already handled by PHP builtins (`http_build_query()` is C), so
 * marshaling IDs and strings into `int64_t`/`char` buffers and the FFI call cost more
 * than the PHP work; `legacy()` copies a list PHP otherwise returns copy-on-write.
 * `$useRustFfi = false` stays the default in all five classes; both `*Native` and
 * `*Php` paths remain covered by the parity tests below.
 */
final class RustSearchPageProxySortDropdownParityTest extends TestCase
{
    private const string FULL_CDEF = SearchPage::FFI_CDEF."\n"
        .ElasticsearchResponseHelper::FFI_CDEF."\n"
        .ProxyRequestContext::FFI_CDEF."\n"
        .ProxyVerdict::FFI_CDEF."\n"
        .SortDropdown::FFI_CDEF;

    /** @var list<class-string> */
    private const array READONLY_CLASSES = [
        SearchPage::class,
        ProxyRequestContext::class,
        ProxyVerdict::class,
    ];

    /** @var list<class-string> */
    private const array STATIC_CLASSES = [
        ElasticsearchResponseHelper::class,
        SortDropdown::class,
    ];

    protected function tearDown(): void
    {
        foreach (self::READONLY_CLASSES as $class) {
            $class::setUseRustFfi(false);
            $class::setSearchFfi(null);
        }
        foreach (self::STATIC_CLASSES as $class) {
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

    /** @param array<string, mixed> $body */
    private static function esResponse(array $body): ElasticsearchResponse
    {
        $response = new ElasticsearchResponse;
        $response->setResponse(new Response(200, [
            'Content-Type' => 'application/json',
            ElasticsearchResponse::HEADER_CHECK => ElasticsearchResponse::PRODUCT_NAME,
        ], json_encode($body, JSON_THROW_ON_ERROR)));

        return $response;
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $server
     */
    private static function request(array $query, array $server = []): Request
    {
        return Request::create('https://nntmux.test/getnzb', 'GET', $query, [], [], $server);
    }

    public function test_search_page_legacy_matches_php(): void
    {
        self::ffi();
        $pages = [
            new SearchPage([], 0, false, 'manticore'),
            new SearchPage([1, 2, 3], 3, true, 'elasticsearch'),
            new SearchPage([PHP_INT_MAX, PHP_INT_MIN, 0, 5, 5], 99_999, false, 'manticore', false, 12.5, [1, 'x'], true),
            new SearchPage(range(1, 500), -1, true, 'manticore'),
        ];
        foreach ($pages as $page) {
            self::assertSame($page->legacyPhp(), $page->legacyNative());
        }

        // Non-list or non-int IDs (outside the declared contract) fall back to PHP.
        // @phpstan-ignore argument.type
        $sparse = new SearchPage([3 => 7, 9 => 8], 2, false, 'manticore');
        self::assertSame([3 => 7, 9 => 8], $sparse->legacyNative()['ids']);
        // @phpstan-ignore argument.type
        $strings = new SearchPage(['7', 8], 2, false, 'manticore');
        self::assertSame(['7', 8], $strings->legacyNative()['ids']);
    }

    public function test_elasticsearch_response_helper_matches_php(): void
    {
        self::ffi();
        $body = ['cluster_name' => 'nntmux', 'status' => 'green', 'nested' => ['a' => [1, 2]]];
        foreach ([[], $body, self::esResponse($body), self::esResponse([])] as $response) {
            self::assertSame(
                ElasticsearchResponseHelper::asArrayPhp($response),
                ElasticsearchResponseHelper::asArrayNative($response),
            );
        }

        $throwables = [
            new ClientResponseException('missing', 404),
            new ClientResponseException('bad request', 400),
            new ServerResponseException('not found?', 404),
            new RuntimeException('plain', 404),
            new ClientResponseException('zero', 0),
            new ClientResponseException('max', PHP_INT_MAX),
        ];
        foreach ($throwables as $throwable) {
            self::assertSame(
                ElasticsearchResponseHelper::isNotFoundPhp($throwable),
                ElasticsearchResponseHelper::isNotFoundNative($throwable),
                $throwable->getMessage(),
            );
        }
        self::assertTrue(ElasticsearchResponseHelper::isNotFoundNative(new ClientResponseException('x', 404)));
    }

    public function test_proxy_request_context_matches_php(): void
    {
        self::ffi();
        $requests = [
            self::request([]),
            self::request(['apikey' => 'abc123'], ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_USER_AGENT' => 'NZBHydra2']),
            self::request(['api_token' => '', 'apikey' => 'k', 'r' => 'rss'], ['HTTP_REFERER' => 'http://hydra.local:5076/']),
            self::request(['api_token' => ['x'], 'apiToken' => 'camel', 'r' => 'z'], ['HTTP_USER_AGENT' => '']),
            self::request(['r' => "\u{00e9}t\u{00e9}\0bin"], ['REMOTE_ADDR' => '::1', 'HTTP_REFERER' => '']),
            self::request(['api_token' => '', 'apikey' => '', 'apiToken' => '', 'r' => '']),
        ];
        foreach ($requests as $i => $request) {
            foreach ([[false, false], [true, false], [false, true], [true, true]] as [$download, $search]) {
                self::assertEquals(
                    ProxyRequestContext::fromRequestPhp($request, $download, $search),
                    ProxyRequestContext::fromRequestNative($request, $download, $search),
                    "request {$i}",
                );
            }
        }

        $candidateSets = [
            [], [null, null, null, null], ['', 0, false, 'last'], [123, 'first', 'second'],
            [['nested'], 1.5, true, ''], ['a'], [str_repeat('k', 4096), 'b'],
        ];
        foreach ($candidateSets as $candidates) {
            self::assertSame(
                ProxyRequestContext::resolveApiTokenPhp($candidates),
                ProxyRequestContext::resolveApiTokenNative($candidates),
            );
            self::assertSame(
                ProxyRequestContext::normalizeContextPhp(null, 'ua', null, $candidates, true, false),
                ProxyRequestContext::normalizeContextNative(null, 'ua', null, $candidates, true, false),
            );
        }
        self::assertSame(
            ProxyRequestContext::normalizeContextPhp('1.2.3.4', null, 'ref', ['', 'tok'], false, true),
            ProxyRequestContext::normalizeContextNative('1.2.3.4', null, 'ref', ['', 'tok'], false, true),
        );
    }

    public function test_proxy_verdict_matches_php(): void
    {
        self::ffi();
        self::assertEquals(ProxyVerdict::allowPhp(), ProxyVerdict::allowNative());
        $cases = [
            [0, 50, []],
            [49, 50, ['referer' => 30, 'ip_correlation' => 19]],
            [50, 50, ['referer' => 30, 'ip_correlation' => 20]],
            [100, 50, ['referer' => 30, 'ua_pair' => 25, 'download_ratio' => 25, 'ip_correlation' => 20]],
            [PHP_INT_MIN, PHP_INT_MAX, []],
            [PHP_INT_MAX, PHP_INT_MIN, []],
            [-5, -5, ['negative' => -5]],
        ];
        foreach ($cases as [$score, $threshold, $reasons]) {
            self::assertEquals(
                ProxyVerdict::fromScorePhp($score, $threshold, $reasons),
                ProxyVerdict::fromScoreNative($score, $threshold, $reasons),
            );
        }
    }

    public function test_sort_dropdown_state_matches_php(): void
    {
        self::ffi();
        $defaults = SortDropdown::DEFAULT_SORT_OPTIONS;
        $custom = ['b' => ['label' => 'B', 'icon' => 'fa-b'], 'a z' => ['label' => 'A Z', 'icon' => 'fa-a']];
        $numeric = [5 => ['label' => 'Five', 'icon' => 'fa-5'], 'x/y' => ['label' => 'Slash', 'icon' => 'fa-s']];
        $missingLabel = ['posted_desc' => ['icon' => 'fa-i'], 'other' => 'scalar'];
        $cases = [
            ['posted_desc', $defaults, 'https://nntmux.test/browse', []],
            ['size_asc', $defaults, 'https://nntmux.test/browse/Movies', ['t' => '2000', 'ob' => 'name_asc', 'page' => '3']],
            ['bogus', $defaults, '/browse', ['q' => 'a b&c=d/é~', 'empty' => '', 'n' => 42, 'yes' => true, 'no' => false]],
            [null, $defaults, '/browse', ['arr' => ['x'], 'nul' => null, 'ok' => 'v']],
            [7, $custom, '', [9 => 'nine', 'k' => 'v', 3 => 'three']],
            ['missing', $custom, '/c', ['ob' => ['nested'], 'page' => 1.5]],
            ['5', $numeric, '/n', ['5' => 'five']],
            ['05', $numeric, '/n', []],
            ['x', [], '/empty', ['a' => 'b']],
            ['other', $missingLabel, '/m', []],
            ['nope', $missingLabel, '/m', []],
            ['posted_desc', $defaults, "/\u{00fc}ber?x=1", ['-_.' => '-_.', ' ' => ' ', '' => '']],
            ['posted_desc', $defaults, '/f', ['f' => 0.30000000000000004]],
        ];
        foreach ($cases as $i => [$sort, $options, $base, $query]) {
            self::assertSame(
                SortDropdown::resolveSortStatePhp($sort, $options, $base, $query),
                SortDropdown::resolveSortStateNative($sort, $options, $base, $query),
                "case {$i}",
            );
        }

        // The component constructor uses the dispatch path.
        SortDropdown::setUseRustFfi(true);
        $component = new SortDropdown('name_desc', null, '/browse', ['t' => '5070', 'page' => '2']);
        self::assertSame('name_desc', $component->currentSort);
        self::assertSame('Name (Z-A)', $component->currentLabel);
        self::assertSame('fa-font', $component->currentIcon);
        self::assertSame(['t' => '5070'], $component->queryParams);
        self::assertSame('/browse?t=5070&ob=posted_desc', $component->sortUrls['posted_desc']);
    }

    public function test_sort_dropdown_random_parity(): void
    {
        self::ffi();
        $random = new Randomizer(new Mt19937(20261024));
        $alphabet = "ab-_. ~/&=?%+\u{00e9}0129";
        $word = static function () use ($random, $alphabet): string {
            $length = $random->getInt(0, 8);

            return $length === 0 ? '' : $random->getBytesFromString($alphabet, $length);
        };
        for ($round = 0; $round < 300; $round++) {
            $options = [];
            for ($o = $random->getInt(0, 6); $o > 0; $o--) {
                $key = $random->getInt(0, 4) === 0 ? $random->getInt(0, 20) : $word();
                $options[$key] = ['label' => $word(), 'icon' => $word()];
            }
            $query = [];
            for ($p = $random->getInt(0, 6); $p > 0; $p--) {
                $key = match ($random->getInt(0, 5)) {
                    0 => $random->getInt(0, 9),
                    1 => 'ob',
                    2 => 'page',
                    default => $word(),
                };
                $query[$key] = match ($random->getInt(0, 4)) {
                    0 => $random->getInt(-1000, 1000),
                    1 => (bool) $random->getInt(0, 1),
                    2 => null,
                    default => $word(),
                };
            }
            $keys = array_keys($options);
            $sort = $keys !== [] && $random->getInt(0, 1) === 1 ? (string) $keys[$random->getInt(0, count($keys) - 1)] : $word();
            self::assertSame(
                SortDropdown::resolveSortStatePhp($sort, $options, '/r', $query),
                SortDropdown::resolveSortStateNative($sort, $options, '/r', $query),
                "round {$round}",
            );
        }
    }

    public function test_dispatch_toggles_and_missing_library_fall_back_to_php(): void
    {
        $page = new SearchPage([4, 2], 2, true, 'manticore');
        $request = self::request(['apikey' => 'tok'], ['REMOTE_ADDR' => '10.1.1.1', 'HTTP_USER_AGENT' => 'ua']);
        $notFound = new ClientResponseException('missing', 404);

        foreach (self::READONLY_CLASSES as $class) {
            $class::setUseRustFfi(true);
        }
        foreach (self::STATIC_CLASSES as $class) {
            $class::setUseRustFfi(true);
        }
        self::assertSame(['ids' => [4, 2], 'total' => 2, 'fuzzy' => true], $page->legacy());
        self::assertSame(['a' => 1], ElasticsearchResponseHelper::asArray(['a' => 1]));
        self::assertTrue(ElasticsearchResponseHelper::isNotFound($notFound));
        self::assertSame('tok', ProxyRequestContext::fromRequest($request, true, false)->apiToken);
        self::assertSame('x', ProxyRequestContext::resolveApiToken(['', 'x']));
        self::assertEquals(new ProxyVerdict(false, 0, []), ProxyVerdict::allow());
        self::assertTrue(ProxyVerdict::fromScore(60, 50, ['referer' => 30])->shouldBlock);
        self::assertSame('posted_desc', SortDropdown::resolveSortState(null, SortDropdown::DEFAULT_SORT_OPTIONS, '/', [])['currentSort']);

        // A missing library resolves to false; every Native path then returns the PHP result.
        foreach (self::READONLY_CLASSES as $class) {
            $class::setSearchFfi(false);
            self::assertNull($class::searchFfi());
        }
        foreach (self::STATIC_CLASSES as $class) {
            (new ReflectionProperty($class, 'ffi'))->setValue(null, false);
            self::assertNull($class::searchFfi());
        }
        self::assertSame(['ids' => [4, 2], 'total' => 2, 'fuzzy' => true], $page->legacy());
        self::assertSame(['a' => 1], ElasticsearchResponseHelper::asArray(['a' => 1]));
        self::assertSame(['ok' => true], ElasticsearchResponseHelper::asArray(self::esResponse(['ok' => true])));
        self::assertTrue(ElasticsearchResponseHelper::isNotFound($notFound));
        self::assertFalse(ElasticsearchResponseHelper::isNotFound(new RuntimeException('x', 404)));
        self::assertEquals(ProxyRequestContext::fromRequestPhp($request, true, false), ProxyRequestContext::fromRequest($request, true, false));
        self::assertNull(ProxyRequestContext::resolveApiToken(['', 0]));
        self::assertEquals(ProxyVerdict::allowPhp(), ProxyVerdict::allow());
        self::assertFalse(ProxyVerdict::fromScore(40, 50, [])->shouldBlock);
        self::assertSame(
            SortDropdown::resolveSortStatePhp('size_asc', SortDropdown::DEFAULT_SORT_OPTIONS, '/b', ['t' => 1]),
            SortDropdown::resolveSortState('size_asc', SortDropdown::DEFAULT_SORT_OPTIONS, '/b', ['t' => 1]),
        );
    }

    public function test_direct_c_abi_error_buffer_and_alignment_contracts(): void
    {
        $ffi = self::ffi();
        $ids = $ffi->new('int64_t[3]');
        $ids[0] = 9;
        $ids[1] = 8;
        $ids[2] = 7;
        $out = $ffi->new('int64_t[3]');
        $meta = $ffi->new('int64_t[2]');

        // SearchPage: short output reports -(count + 3); invalid fuzzy, null pointers
        // and a misaligned int64 pointer are rejected; in-place copies are allowed.
        // @phpstan-ignore method.notFound
        self::assertSame(-6, $ffi->nntmux_search_page_legacy($ids, 3, 1, 0, $out, 2, $meta));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_search_page_legacy($ids, 3, 1, 2, $out, 3, $meta));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_search_page_legacy(null, 3, 1, 0, $out, 3, $meta));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_search_page_legacy($ids, 3, 1, 0, $out, 3, null));
        $raw = $ffi->new('char[64]');
        $misaligned = $ffi->cast('int64_t *', FFI::addr($raw[1]));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_search_page_legacy($misaligned, 1, 1, 0, $out, 3, $meta));
        // @phpstan-ignore method.notFound
        self::assertSame(3, $ffi->nntmux_search_page_legacy($ids, 3, 77, 1, $ids, 3, $meta));
        self::assertSame([9, 8, 7, 77, 1], [$ids[0], $ids[1], $ids[2], $meta[0], $meta[1]]);
        // @phpstan-ignore method.notFound
        self::assertSame(0, $ffi->nntmux_search_page_legacy(null, 0, 5, 0, null, 0, $meta));

        // Elasticsearch classification rejects non-boolean flags.
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_es_response_as_array_mode(2));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_es_is_not_found(-1, 404));
        // @phpstan-ignore method.notFound
        self::assertSame(1, $ffi->nntmux_es_is_not_found(1, 404));

        // Proxy token/context: lengths must cover the buffer exactly.
        $lens = $ffi->new('int64_t[4]');
        foreach ([3, -1, 0, -1] as $i => $len) {
            $lens[$i] = $len;
        }
        // @phpstan-ignore method.notFound
        self::assertSame(0, $ffi->nntmux_proxy_resolve_token('abc', 3, $lens, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_proxy_resolve_token('abcd', 4, $lens, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_proxy_resolve_token(null, 3, $lens, 4));
        $lens[0] = -2;
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_proxy_resolve_token('', 0, $lens, 4));
        $lens[0] = -1;
        // @phpstan-ignore method.notFound
        self::assertSame(4, $ffi->nntmux_proxy_resolve_token('', 0, $lens, 4));
        $ctx = $ffi->new('int64_t[9]');
        // @phpstan-ignore method.notFound
        self::assertSame(-12, $ffi->nntmux_proxy_context_normalize('', 0, $lens, 4, 0, $ctx, 8));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_proxy_context_normalize('', 0, $lens, 4, 8, $ctx, 9));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_proxy_context_normalize('', 0, $lens, 2, 0, $ctx, 9));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_proxy_context_normalize('', 0, $lens, 4, 0, null, 9));

        // Verdict state.
        $block = $ffi->new('int32_t[1]');
        $score = $ffi->new('int64_t[1]');
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_proxy_verdict_allow(null, $score));
        // @phpstan-ignore method.notFound
        self::assertSame(0, $ffi->nntmux_proxy_verdict_allow($block, $score));
        // @phpstan-ignore method.notFound
        self::assertSame(1, $ffi->nntmux_proxy_verdict_should_block(PHP_INT_MAX, PHP_INT_MAX));

        // Sort dropdown: short buffer, bad tag bits, retained floats and bad lengths.
        $keyLens = $ffi->new('int64_t[1]');
        $keyLens[0] = 1;
        $tags = $ffi->new('int32_t[1]');
        $paramLens = $ffi->new('int64_t[2]');
        $paramLens[0] = 1;
        $paramLens[1] = 0;
        $keep = $ffi->new('int32_t[1]');
        $urlLens = $ffi->new('int64_t[1]');
        $current = $ffi->new('int64_t[1]');
        $small = $ffi->new('char[4]');
        $big = $ffi->new('char[256]');
        // @phpstan-ignore method.notFound
        $call = static fn (int $sortIsString, $outBuf, int $cap) => $ffi->nntmux_sort_dropdown_resolve(
            'a', 1, $sortIsString, 'a', 1, $keyLens, 1, '/', 1, 'f', 1, $tags, $paramLens, 1, $keep, $outBuf, $cap, $urlLens, $current,
        );
        $tags[0] = 1;
        self::assertSame(-(\strlen('/?f=&ob=a') + 3), $call(1, $small, 4));
        self::assertSame(\strlen('/?f=&ob=a'), $call(1, $big, 256));
        self::assertSame('/?f=&ob=a', FFI::string($big, 9));
        self::assertSame([1, 0], [$keep[0], $current[0]]);
        self::assertSame(-1, $call(2, $big, 256));
        $tags[0] = 5;
        self::assertSame(-1, $call(1, $big, 256));
        $tags[0] = 16;
        self::assertSame(-1, $call(1, $big, 256));
        $tags[0] = 1;
        $keyLens[0] = 2;
        self::assertSame(-1, $call(1, $big, 256));
        $keyLens[0] = 1;
        self::assertSame(-1, $call(1, null, 256));
    }

    /**
     * Run with:
     * NNTMUX_SEARCH_PAGE_PROXY_SORT_DROPDOWN_BENCH=1 php artisan test --compact tests/Unit/RustSearchPageProxySortDropdownParityTest.php --filter=test_benchmark_paired_workloads
     */
    public function test_benchmark_paired_workloads(): void
    {
        if (getenv('NNTMUX_SEARCH_PAGE_PROXY_SORT_DROPDOWN_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_SEARCH_PAGE_PROXY_SORT_DROPDOWN_BENCH=1 to run paired benchmarks.');
        }

        self::ffi();
        $pageCommon = new SearchPage(range(10_000_001, 10_000_050), 50, false, 'manticore');
        $pageUncommon = new SearchPage(range(10_000_001, 10_001_000), 48_211, true, 'elasticsearch');
        $esArray = ['acknowledged' => true];
        $esObject = self::esResponse(['cluster_name' => 'nntmux', 'status' => 'green', 'number_of_nodes' => 1]);
        $missing = new ClientResponseException('missing', 404);
        $server = new ServerResponseException('unavailable', 503);
        $reqCommon = self::request(['apikey' => '0123456789abcdef0123456789abcdef', 't' => 'get', 'id' => 'abc'], ['REMOTE_ADDR' => '203.0.113.5', 'HTTP_USER_AGENT' => 'SABnzbd/4.3.2']);
        $reqUncommon = self::request(['api_token' => '', 'apikey' => ['x'], 'apiToken' => '', 'r' => 'rss-0123456789'], ['REMOTE_ADDR' => '2001:db8::1', 'HTTP_USER_AGENT' => 'NZBHydra2', 'HTTP_REFERER' => 'http://hydra.local:5076/']);
        $tokensCommon = ['0123456789abcdef0123456789abcdef', null, null, null];
        $tokensUncommon = ['', ['x'], '', 'rss-0123456789'];
        $reasons = ['referer' => 30, 'ua_pair' => 25, 'download_ratio' => 25, 'ip_correlation' => 20];
        $options = SortDropdown::DEFAULT_SORT_OPTIONS;
        $queryCommon = ['t' => '2000', 'page' => '2'];
        $queryUncommon = ['t' => '5070', 'search' => 'Some Show S01E02 1080p WEB-DL', 'minsize' => 100, 'hidexxx' => true, 'arr' => ['a'], 'ob' => 'name_asc', 3 => 'x'];

        $workloads = [
            'searchPageLegacy/common' => [
                static fn () => $pageCommon->legacyPhp(),
                static fn () => $pageCommon->legacyNative(),
            ],
            'searchPageLegacy/uncommon' => [
                static fn () => $pageUncommon->legacyPhp(),
                static fn () => $pageUncommon->legacyNative(),
            ],
            'esAsArray/common' => [
                static fn () => ElasticsearchResponseHelper::asArrayPhp($esObject),
                static fn () => ElasticsearchResponseHelper::asArrayNative($esObject),
            ],
            'esAsArray/uncommon' => [
                static fn () => ElasticsearchResponseHelper::asArrayPhp($esArray),
                static fn () => ElasticsearchResponseHelper::asArrayNative($esArray),
            ],
            'esIsNotFound/common' => [
                static fn () => ElasticsearchResponseHelper::isNotFoundPhp($missing),
                static fn () => ElasticsearchResponseHelper::isNotFoundNative($missing),
            ],
            'esIsNotFound/uncommon' => [
                static fn () => ElasticsearchResponseHelper::isNotFoundPhp($server),
                static fn () => ElasticsearchResponseHelper::isNotFoundNative($server),
            ],
            'proxyFromRequest/common' => [
                static fn () => ProxyRequestContext::fromRequestPhp($reqCommon, true, false),
                static fn () => ProxyRequestContext::fromRequestNative($reqCommon, true, false),
            ],
            'proxyFromRequest/uncommon' => [
                static fn () => ProxyRequestContext::fromRequestPhp($reqUncommon, true, false),
                static fn () => ProxyRequestContext::fromRequestNative($reqUncommon, true, false),
            ],
            'proxyResolveToken/common' => [
                static fn () => ProxyRequestContext::resolveApiTokenPhp($tokensCommon),
                static fn () => ProxyRequestContext::resolveApiTokenNative($tokensCommon),
            ],
            'proxyResolveToken/uncommon' => [
                static fn () => ProxyRequestContext::resolveApiTokenPhp($tokensUncommon),
                static fn () => ProxyRequestContext::resolveApiTokenNative($tokensUncommon),
            ],
            'proxyVerdictAllow/common' => [
                static fn () => ProxyVerdict::allowPhp(),
                static fn () => ProxyVerdict::allowNative(),
            ],
            'proxyVerdictAllow/uncommon' => [
                static fn () => ProxyVerdict::allowPhp(),
                static fn () => ProxyVerdict::allowNative(),
            ],
            'proxyVerdictFromScore/common' => [
                static fn () => ProxyVerdict::fromScorePhp(0, 50, []),
                static fn () => ProxyVerdict::fromScoreNative(0, 50, []),
            ],
            'proxyVerdictFromScore/uncommon' => [
                static fn () => ProxyVerdict::fromScorePhp(100, 50, $reasons),
                static fn () => ProxyVerdict::fromScoreNative(100, 50, $reasons),
            ],
            'sortDropdownState/common' => [
                static fn () => SortDropdown::resolveSortStatePhp('posted_desc', $options, 'https://nntmux.test/browse/Movies', $queryCommon),
                static fn () => SortDropdown::resolveSortStateNative('posted_desc', $options, 'https://nntmux.test/browse/Movies', $queryCommon),
            ],
            'sortDropdownState/uncommon' => [
                static fn () => SortDropdown::resolveSortStatePhp('bogus', $options, 'https://nntmux.test/search', $queryUncommon),
                static fn () => SortDropdown::resolveSortStateNative('bogus', $options, 'https://nntmux.test/search', $queryUncommon),
            ],
        ];

        $percentile99 = static function (array $ns): float {
            sort($ns);

            return (float) $ns[(int) ceil(count($ns) * 0.99) - 1];
        };

        $random = new Randomizer(new Mt19937(20261024));
        foreach ($workloads as $name => [$phpCall, $rustCall]) {
            self::assertEquals($phpCall(), $rustCall(), $name);
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
