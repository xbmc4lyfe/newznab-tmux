<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Api\ApiUsageService;
use App\Services\Api\V1\ReleaseRows;
use App\Services\Api\V1\XmlResponseContext;
use App\Services\Search\Support\ElasticsearchClientFactory;
use App\Support\ReleaseSearchIndexSync;
use App\Support\RustPipelineLibrary;
use ArrayIterator;
use FFI;
use Generator;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Source contract: fork master 7a1911099. The owned classes hold little pure CPU
 * work: ElasticsearchClientFactory builds host URLs and timeout options,
 * ApiUsageService formats the redacted audit entry (after the separately owned
 * canonicalizer) and decides quota reservation, ReleaseRows casts `_totalrows`,
 * XmlResponseContext selects legacy option slots and ReleaseSearchIndexSync filters
 * ids and strips the raw WHERE suffix. There is no rate-limit bucket key, pagination
 * slicing, offset clamping, id deduplication or batch chunking to extract: the
 * quota counts rows directly, index sync keeps duplicate ids (each triggers an
 * update) and chunking is the query builder's chunkById(500).
 *
 * Paired screen: Apple M5, PHP 8.5.12, rustc 1.99.1, release LTO, CLI OPcache
 * off; two repetitions of 10,000 single-call pairs per workload. Conservative
 * speedups (minimum across repetitions), common mean/p99; uncommon mean/p99,
 * including dispatch, packing, FFI and allocation:
 * buildHosts:     0.304/0.250; 0.561/0.570
 * httpOptions:    0.556/0.555; 0.430/0.400
 * auditEntry:     0.569/0.619; 0.910/0.953
 * reservedCount:  0.455/0.404; 0.461/0.404
 * totalRows:      0.532/0.503; 0.547/0.572
 * xmlContext:     0.638/0.647; 0.650/0.539
 * positiveIds:    0.425/0.426; 0.425/0.433
 * whereCondition: 0.629/0.799; 0.674/0.628
 * None reaches 1.5x, so every adapter keeps PHP by default ($useRustFfi = false).
 * Reproduce with NNTMUX_SEARCH_API_AUDIT_CLIENT_BENCH=1; raw pairs/provenance are
 * saved outside the checkout and their artifact path is printed by the opt-in test.
 */
final class RustSearchApiAuditClientParityTest extends TestCase
{
    private const array METHODS = ['buildHosts', 'httpOptions', 'auditEntry', 'reservedCount', 'totalRows', 'xmlContext', 'positiveIds', 'whereCondition'];

    public function test_build_hosts_match_php(): void
    {
        $this->requireNative();
        $cases = [
            [],
            ['http://raw:9200', '  ', '', 'https://x', 42, null, true],
            [['host' => 'es', 'port' => 9200], ['host' => ' es2 ', 'scheme' => ' https ', 'port' => '9243', 'user' => 'u ser', 'pass' => 'p@ss/~:']],
            [['host' => ''], ['host' => " \t"], ['host' => 'a', 'scheme' => '0'], ['host' => 'b', 'scheme' => ''], ['host' => 'c', 'scheme' => null]],
            [['host' => 'd', 'port' => ''], ['host' => 'e', 'port' => 0], ['host' => 'f', 'port' => '0'], ['host' => 'g', 'port' => 9200.0], ['host' => 'h', 'port' => ' 1 ']],
            [['host' => 'i', 'user' => 'x'], ['host' => 'j', 'pass' => 'y'], ['host' => 'k', 'user' => '0', 'pass' => 'y'], ['host' => 'l', 'user' => 'x', 'pass' => '0'], ['host' => 'm', 'user' => 5, 'pass' => true]],
            [['host' => 0], ['host' => 1.5], ['host' => true], ['host' => false], ['host' => "\x00n\x00"], ['host' => 'é', 'user' => 'ü', 'pass' => "\xff"]],
            [['host' => 'n', 'user' => [], 'pass' => 'p'], ['host' => 'o']],
        ];
        $random = new Randomizer(new Mt19937(1601));
        for ($i = 0; $i < 300; $i++) {
            $hosts = [];
            for ($j = $random->getInt(0, 6); $j > 0; $j--) {
                $hosts[] = $random->getInt(0, 4) === 0
                    ? $this->randomString($random, 12)
                    : array_filter(['host' => $this->randomString($random, 10), 'scheme' => $random->getInt(0, 1) ? $this->randomString($random, 6) : null, 'port' => $random->getInt(0, 1) ? $random->getInt(0, 70000) : null, 'user' => $this->randomString($random, 8), 'pass' => $this->randomString($random, 8)], static fn (mixed $v): bool => $v !== null);
            }
            $cases[] = $hosts;
        }
        foreach ($cases as $hosts) {
            $expected = ElasticsearchClientFactory::buildHostsPhp($hosts);
            self::assertSame($expected, ElasticsearchClientFactory::buildHosts($hosts, true), json_encode($hosts, JSON_INVALID_UTF8_SUBSTITUTE) ?: '');
            if (! $this->hasNonScalarField($hosts)) {
                self::assertSame($expected, ElasticsearchClientFactory::buildHostsNative($hosts));
            }
        }
        // A non-scalar field leaves the PHP path in charge of string conversion.
        self::assertNull(ElasticsearchClientFactory::buildHostsNative([['host' => 'n', 'user' => [], 'pass' => 'p']]));
        $many = array_map(static fn (int $i): array => ['host' => 'h'.$i, 'user' => str_repeat('/', 50), 'pass' => str_repeat('é', 40), 'port' => $i], range(1, 300));
        self::assertSame(ElasticsearchClientFactory::buildHostsPhp($many), ElasticsearchClientFactory::buildHostsNative($many));
    }

    public function test_http_client_options_match_php(): void
    {
        $this->requireNative();
        $values = [null, true, false, 0, 1, -1, 0.0, -0.0, 2.5, INF, NAN, '', ' ', '0', '-0', '12', ' 12', "12\f", "\v1", '.5', '5.', '+.5', '1e3', '1e', '1e400', '-1e400', 'inf', 'NAN', '0x1A', '1_000', 'abc', '12abc', ' 1 2', "1\x00", [], ['1']];
        foreach ($values as $timeout) {
            foreach ([$timeout, '3', null] as $connect) {
                $config = ['timeout' => $timeout, 'connect_timeout' => $connect];
                $expected = ElasticsearchClientFactory::buildHttpClientOptionsPhp($config);
                foreach ([ElasticsearchClientFactory::buildHttpClientOptions($config, true), ElasticsearchClientFactory::buildHttpClientOptionsNative($config)] as $actual) {
                    self::assertSame(var_export($expected, true), var_export($actual, true), var_export($config, true));
                }
            }
        }
        self::assertSame([], ElasticsearchClientFactory::buildHttpClientOptionsNative([]));
    }

    public function test_audit_entry_matches_php(): void
    {
        $this->requireNative();
        $cases = [
            ['api/v2/search', ['cat' => '5030', 'id' => 'test']],
            ['/', []],
            ['', ['q' => null]],
            ['//api//x/', ['q' => '', 't' => 'search']],
            ['api', ['q' => 'a b&c=d/é~*+%', 'limit' => 5, 'o' => ['json'], 'extended' => true]],
            ['api', [0 => 'zero', 'q' => str_repeat('é', 70)]],
            ['api', ['q' => str_repeat('x', 300)]],
            [str_repeat('p', 300), ['q' => 'x']],
            [str_repeat('é', 260), []],
            ['api', ['q' => "\xff\xfe"]],
            ["\xc3", ['q' => 'x']],
            ['api', ['q' => "\x00\n\r\t", 'k y' => 'v']],
            ['api', ['q' => '😀'.str_repeat('a', 63).'b']],
        ];
        $random = new Randomizer(new Mt19937(1602));
        for ($i = 0; $i < 300; $i++) {
            $parameters = [];
            for ($j = $random->getInt(0, 6); $j > 0; $j--) {
                $parameters[$this->randomString($random, 4)] = $random->getInt(0, 5) === 0 ? null : $this->randomString($random, $random->getInt(0, 1) ? 20 : 90);
            }
            $cases[] = [$this->randomString($random, $random->getInt(0, 1) ? 12 : 120), $parameters];
        }
        foreach ($cases as [$path, $parameters]) {
            $expected = ApiUsageService::auditEntryFromPhp($path, $parameters);
            self::assertSame($expected, ApiUsageService::auditEntryFrom($path, $parameters, true));
            $native = ApiUsageService::auditEntryFromNative($path, $parameters);
            if (mb_check_encoding($path, 'UTF-8') && array_filter($parameters, static fn (mixed $v): bool => is_string($v) && ! mb_check_encoding($v, 'UTF-8')) === []) {
                self::assertSame($expected, $native, $path);
            } else {
                self::assertNull($native);
            }
        }
    }

    public function test_reserved_count_matches_php(): void
    {
        $this->requireNative();
        foreach ([[0, 0], [0, 1], [4, 5], [5, 5], [6, 5], [-3, 2], [PHP_INT_MAX, PHP_INT_MAX], [PHP_INT_MAX - 1, PHP_INT_MAX], [PHP_INT_MIN, PHP_INT_MIN + 1], [0, PHP_INT_MIN]] as [$used, $max]) {
            $expected = ApiUsageService::reservedCountPhp($used, $max);
            self::assertSame($expected, ApiUsageService::reservedCount($used, $max, true));
            $supported = false;
            self::assertSame($expected, ApiUsageService::reservedCountNative($used, $max, $supported));
            self::assertTrue($supported);
        }
    }

    public function test_total_rows_match_php(): void
    {
        $this->requireNative();
        $values = [null, 0, 7, -7, 1.9, true, false, '', '0', '12', ' 12', "\f12", "\v12", "\t\n\r12", '12abc', '12 ', '0012', '-5', '+5', '--5', '- 5', '1.9', '1e2', '12e', '12E', '.5', 'abc', '999999999999999999', '9999999999999999999', '-999999999999999999', '9223372036854775808', '١٢', [], [1]];
        foreach ($values as $value) {
            self::assertSame(ReleaseRows::totalRowsFromPhp($value, 3), ReleaseRows::totalRowsFrom($value, 3, true), var_export($value, true));
            $native = ReleaseRows::totalRowsFromNative($value);
            if ($native !== null) {
                self::assertSame((int) $value, $native, var_export($value, true));
            }
        }
        self::assertSame(12, ReleaseRows::totalRowsFromNative(' 12abc'));
        self::assertNull(ReleaseRows::totalRowsFromNative('1e2'));
        self::assertNull(ReleaseRows::totalRowsFromNative(12));
        self::assertSame(2, (new ReleaseRows([['guid' => 1], ['guid' => 2]]))->totalRows());
        self::assertSame(40, (new ReleaseRows([['_totalrows' => '40']]))->totalRows());
    }

    public function test_xml_context_matches_php(): void
    {
        $this->requireNative();
        $values = ['missing', null, [], ['a' => 1], 'text', 0, 5, false, new \stdClass];
        foreach ($values as $parameters) {
            foreach ($values as $other) {
                $options = [];
                foreach (['Parameters' => $parameters, 'Data' => $other, 'Server' => $other, 'Offset' => $parameters, 'Type' => $other] as $key => $value) {
                    if ($value !== 'missing') {
                        $options[$key] = $value;
                    }
                }
                $options['Extra'] = 'ignored';
                $expected = get_object_vars(XmlResponseContext::fromLegacyOptionsPhp($options));
                self::assertSame($expected, get_object_vars(XmlResponseContext::fromLegacyOptions($options, true)));
                $native = XmlResponseContext::fromLegacyOptionsNative($options);
                self::assertNotNull($native);
                self::assertSame($expected, get_object_vars($native));
            }
        }
    }

    public function test_positive_ids_match_php(): void
    {
        $this->requireNative();
        $random = new Randomizer(new Mt19937(1603));
        $cases = [[], [1], [0], [-1], [3, 0, -1, 3, 9], [PHP_INT_MAX, PHP_INT_MIN], ['5', '0', 'abc', '7x', ' 8', 2.9, true, null], range(1, 2000)];
        for ($i = 0; $i < 100; $i++) {
            $cases[] = array_map(static fn (): int => $random->getInt(-5, 5000), range(0, $random->getInt(0, 600)));
        }
        foreach ($cases as $ids) {
            $expected = ReleaseSearchIndexSync::positiveIdsPhp($ids);
            self::assertSame($expected, ReleaseSearchIndexSync::positiveIds($ids, true));
            self::assertSame($expected, ReleaseSearchIndexSync::positiveIds(new Collection($ids), true));
            self::assertSame($expected, ReleaseSearchIndexSync::positiveIds(new ArrayIterator($ids), true));
            self::assertSame($expected, ReleaseSearchIndexSync::positiveIds(self::generate($ids), true));
            self::assertSame($expected, ReleaseSearchIndexSync::positiveIds(self::generate($ids)));
            $native = ReleaseSearchIndexSync::positiveIdsNative($ids);
            if (array_filter($ids, static fn (mixed $id): bool => ! is_int($id)) === []) {
                self::assertSame($expected, $native);
            } else {
                self::assertNull($native);
            }
        }
    }

    public function test_where_condition_matches_php(): void
    {
        $this->requireNative();
        $cases = ['', '   ', 'AND x = 1', '  AND x = 1 ', 'and y', 'AnD  z', "AND\tz", "AND\fz", "AND\vz", "\fAND z", "\x00AND z", 'ANDx', 'AND', ' AND ', "AND \f", 'x AND y', 'AND AND x', "AND\nx\n", 'ORAND x', 'é AND', "AND\xa0x", "AND \x85x", 'releases.id IN (1,2)'];
        $random = new Randomizer(new Mt19937(1604));
        $alphabet = [' ', "\t", "\n", "\r", "\v", "\f", "\x00", 'A', 'a', 'N', 'n', 'D', 'd', 'x', '=', '1'];
        for ($i = 0; $i < 500; $i++) {
            $value = '';
            for ($j = $random->getInt(0, 12); $j > 0; $j--) {
                $value .= $alphabet[$random->getInt(0, count($alphabet) - 1)];
            }
            $cases[] = $value;
        }
        foreach ($cases as $suffix) {
            $expected = ReleaseSearchIndexSync::whereConditionPhp($suffix);
            self::assertSame($expected, ReleaseSearchIndexSync::whereCondition($suffix, true), json_encode($suffix) ?: '');
            self::assertSame($expected, ReleaseSearchIndexSync::whereConditionNative($suffix), json_encode($suffix) ?: '');
        }
    }

    public function test_c_abi_error_and_buffer_contracts(): void
    {
        $ffi = FFI::cdef(<<<'C'
            ssize_t nntmux_es_build_hosts(const char *input, size_t len, const int64_t *lens, const uint32_t *flags, size_t count, char *out, size_t cap, int64_t *out_lens);
            int32_t nntmux_es_positive_seconds(const char *input, size_t len, double *out);
            ssize_t nntmux_api_audit_entry(const char *input, size_t len, const int64_t *lens, size_t count, char *out, size_t cap);
            int64_t nntmux_api_quota_reservation(int64_t used, int64_t max);
            int64_t nntmux_api_release_rows_total(const char *input, size_t len);
            int32_t nntmux_api_xml_context_mask(uint32_t tags);
            ssize_t nntmux_search_sync_positive_ids(const int64_t *ids, size_t count, int64_t *out, size_t cap);
            int32_t nntmux_search_sync_where_condition(const char *input, size_t len, int64_t *out);
            C, $this->libraryPath());
        // Exported symbols are resolved at runtime, so call them by name.
        $call = static fn (string $symbol, mixed ...$arguments): mixed => $ffi->{$symbol}(...$arguments);
        $lens = $ffi->new('int64_t[5]');
        $flags = $ffi->new('uint32_t[1]');
        $outLens = $ffi->new('int64_t[1]');
        $out = $ffi->new('char[4]');
        foreach ([3, 0, 0, 0, 0] as $i => $length) {
            $lens[$i] = $length;
        }
        // Short output reports -(required + 3) and leaves the caller to retry.
        self::assertSame(-(10 + 3), $call('nntmux_es_build_hosts', 'abc', 3, $lens, $flags, 1, $out, 4, $outLens));
        self::assertSame(-1, $call('nntmux_es_build_hosts', null, 3, $lens, $flags, 1, $out, 4, $outLens));
        self::assertSame(-1, $call('nntmux_es_build_hosts', 'abc', 2, $lens, $flags, 1, $out, 4, $outLens));
        self::assertSame(-1, $call('nntmux_es_build_hosts', 'abc', 3, null, $flags, 1, $out, 4, $outLens));
        self::assertSame(-1, $call('nntmux_es_build_hosts', 'abc', 3, $lens, $flags, 1, null, 4, $outLens));
        self::assertSame(-1, $call('nntmux_es_build_hosts', 'abc', PHP_INT_MAX, $lens, $flags, 1, $out, 4, $outLens));
        self::assertSame(-1, $call('nntmux_es_positive_seconds', '1', 1, null));
        self::assertSame(-1, $call('nntmux_api_audit_entry', 'ab', 2, $lens, 2, $out, 4));
        $lens[0] = 2;
        self::assertSame(-(3 + 3), $call('nntmux_api_audit_entry', 'ab', 2, $lens, 1, $out, 2));
        self::assertSame(3, $call('nntmux_api_audit_entry', 'ab', 2, $lens, 1, $out, 4));
        self::assertSame('/ab', FFI::string($out, 3));
        self::assertSame(-1, $call('nntmux_api_audit_entry', 'ab', 2, $lens, 1, null, 4));
        $lens[0] = -1;
        self::assertSame(-1, $call('nntmux_api_audit_entry', 'ab', 2, $lens, 1, $out, 4));
        self::assertSame(PHP_INT_MIN, $call('nntmux_api_quota_reservation', PHP_INT_MAX, PHP_INT_MAX));
        self::assertSame(PHP_INT_MIN, $call('nntmux_api_release_rows_total', null, 1));
        self::assertSame(-1, $call('nntmux_api_xml_context_mask', 1 << 10));
        $ids = $ffi->new('int64_t[3]');
        $kept = $ffi->new('int64_t[1]');
        foreach ([4, -1, 5] as $i => $id) {
            $ids[$i] = $id;
        }
        self::assertSame(-(2 + 3), $call('nntmux_search_sync_positive_ids', $ids, 3, $kept, 1));
        self::assertSame(-1, $call('nntmux_search_sync_positive_ids', null, 3, $kept, 1));
        self::assertSame(-1, $call('nntmux_search_sync_positive_ids', $ids, 3, $ids, 3));
        self::assertSame(0, $call('nntmux_search_sync_positive_ids', null, 0, null, 0));
        self::assertSame(-1, $call('nntmux_search_sync_where_condition', 'x', 1, null));
    }

    public function test_native_unavailability_falls_back_without_changing_results(): void
    {
        $code = <<<'PHP'
            require $argv[1];
            echo json_encode([
                \App\Services\Search\Support\ElasticsearchClientFactory::buildHosts([['host' => 'es', 'user' => 'u', 'pass' => 'p w', 'port' => 9200], 'http://raw'], true),
                \App\Services\Search\Support\ElasticsearchClientFactory::buildHttpClientOptions(['timeout' => ' 2.5', 'connect_timeout' => 3], true),
                \App\Services\Api\ApiUsageService::auditEntryFrom('api/v2/search', ['q' => 'a b', 'id' => null], true),
                \App\Services\Api\ApiUsageService::reservedCount(4, 5, true),
                \App\Services\Api\V1\ReleaseRows::totalRowsFrom(' 12abc', 1, true),
                get_object_vars(\App\Services\Api\V1\XmlResponseContext::fromLegacyOptions(['Parameters' => 'x', 'Offset' => 3], true)),
                \App\Support\ReleaseSearchIndexSync::positiveIds([3, 0, 4], true),
                \App\Support\ReleaseSearchIndexSync::whereCondition(' AND x = 1', true),
                \App\Services\Search\Support\ElasticsearchClientFactory::buildHostsNative(['http://raw']),
                \App\Support\ReleaseSearchIndexSync::whereConditionNative('AND x'),
            ], JSON_THROW_ON_ERROR);
            PHP;
        $process = proc_open([PHP_BINARY, '-d', 'ffi.enable=0', '-r', $code, __DIR__.'/../bootstrap.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);
        self::assertSame([
            ['http://u:p%20w@es:9200', 'http://raw'],
            ['timeout' => 2.5, 'connect_timeout' => 3],
            '/api/v2/search?q=a%20b&id=',
            5,
            12,
            ['parameters' => [], 'data' => null, 'server' => [], 'offset' => 3, 'type' => null],
            [3, 4],
            'x = 1',
            null,
            null,
        ], json_decode((string) $output, true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * Opt in: NNTMUX_SEARCH_API_AUDIT_CLIENT_BENCH=1 php artisan test --compact tests/Unit/RustSearchApiAuditClientParityTest.php
     * Two repetitions, each with 500 warmups and 10,000 randomized single-call pairs per
     * workload through the public adapters (dispatch, packing, FFI and allocation).
     * Raw pairs, fixtures, order, source/runtime identity and p99 are saved to a
     * temporary artifact whose path is printed.
     */
    public function test_paired_randomized_benchmarks(): void
    {
        if (getenv('NNTMUX_SEARCH_API_AUDIT_CLIENT_BENCH') !== '1') {
            self::markTestSkipped('Opt-in paired benchmark.');
        }
        $this->requireNative();
        $random = new Randomizer(new Mt19937(1605));
        $artifact = ['protocol' => 'wave16-v1', 'seed' => 1605, 'repetitions' => 2, 'warmups' => 500, 'pairs_per_repetition' => 10000, 'p99' => 'nearest-rank single-call latency', 'profile' => 'cargo release opt-level=3,lto=true,codegen-units=1', 'php' => PHP_VERSION, 'host' => php_uname(), 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'), 'head' => trim((string) shell_exec('git rev-parse HEAD')), 'rustc' => trim((string) shell_exec('rustc --version')), 'cpu' => trim((string) shell_exec(PHP_OS_FAMILY === 'Darwin' ? 'sysctl -n machdep.cpu.brand_string' : 'uname -m')), 'sources' => [], 'results' => [], 'failures' => []];
        foreach (['app/Services/Search/Support/ElasticsearchClientFactory.php', 'app/Services/Api/ApiUsageService.php', 'app/Services/Api/V1/ReleaseRows.php', 'app/Services/Api/V1/XmlResponseContext.php', 'app/Support/ReleaseSearchIndexSync.php', 'tests/Unit/RustSearchApiAuditClientParityTest.php', 'rust/pipeline-ffi/src/search.rs', 'composer.lock', 'rust/pipeline-ffi/Cargo.lock'] as $source) {
            $artifact['sources'][$source] = hash_file('sha256', dirname(__DIR__, 2).'/'.$source);
        }
        $artifact['sources']['library'] = hash_file('sha256', $this->libraryPath());
        $artifactPath = tempnam(sys_get_temp_dir(), 'nntmux-wave16-');
        self::assertNotFalse($artifactPath);
        $workloads = [];
        try {
            foreach (['common', 'uncommon'] as $kind) {
                $rare = $kind === 'uncommon';
                $hosts = $rare
                    ? [array_map(static fn (int $i): array => ['host' => ' node'.$i.'.example.test ', 'scheme' => 'https', 'port' => 9200 + $i, 'user' => 'user/'.$i, 'pass' => 'pä ss@'.$i], range(1, 40)), [['host' => str_repeat('h', 500), 'user' => str_repeat('é', 200), 'pass' => str_repeat('/', 200)], '  ', 'http://raw:9200']]
                    : [[['host' => 'elasticsearch', 'port' => 9200, 'scheme' => 'http']], ['http://elasticsearch:9200'], [['host' => 'search.example.test', 'port' => 9243, 'scheme' => 'https', 'user' => 'elastic', 'pass' => 'secret value']]];
                $options = $rare
                    ? [['timeout' => ' 1.5e1 ', 'connect_timeout' => "\f.25"], ['timeout' => 'abc', 'connect_timeout' => '-1'], ['timeout' => '1e400', 'connect_timeout' => '0']]
                    : [['timeout' => 30, 'connect_timeout' => 10], ['timeout' => '30', 'connect_timeout' => '5'], []];
                $audits = $rare
                    ? [['api/v1/api', array_combine(['t', 'q', 'cat', 'imdbid', 'season', 'ep', 'limit', 'offset', 'extended', 'o'], array_map(static fn (int $i): string => str_repeat('é/'.$i, 30), range(1, 10)))], [str_repeat('é', 120), ['q' => str_repeat('x y', 40), 'id' => null]]]
                    : [['api/v2/search', ['cat' => '5030', 'q' => 'ubuntu 24.04']], ['api', ['t' => 'tvsearch', 'q' => 'the expanse', 'season' => '1', 'ep' => '2', 'limit' => '100']], ['api/v2/capabilities', []]];
                $quotas = $rare ? [[PHP_INT_MAX, PHP_INT_MAX], [-5, 0], [99999, 100000]] : [[10, 100], [100, 100], [0, 5000]];
                $totals = $rare ? [' 12abc', '999999999999999999', '1e3', '-0', '9223372036854775808'] : ['1234', '40', '0'];
                $contexts = $rare
                    ? [['Parameters' => 'bad', 'Server' => null, 'Offset' => '9'], [], ['Parameters' => array_fill(0, 50, 'x'), 'Data' => new \stdClass, 'Server' => ['server' => ['title' => 't']], 'Offset' => 5, 'Type' => 'caps', 'Extra' => 1]]
                    : [['Parameters' => ['t' => 'search'], 'Data' => [], 'Server' => ['server' => []], 'Offset' => 0, 'Type' => 'api'], ['Parameters' => [], 'Data' => [], 'Server' => [], 'Offset' => 100, 'Type' => 'rss']];
                $idLists = $rare
                    ? [range(-500, 4500), array_merge(array_fill(0, 300, 0), range(1, 200)), ['5', '0', 'x', 7]]
                    : [range(1000, 1499), [42], range(1, 25)];
                $wheres = $rare
                    ? ["\f AND ".str_repeat('x = 1 OR ', 200).'x = 2', 'ANDx', '   ', "and\t\t\tcategories_id = 7010"]
                    : ['', 'AND categories_id = 7010', ' AND predb_id = 0'];
                $artifact['fixtures'][$kind] = ['hosts' => $hosts, 'options' => $options, 'audits' => $audits, 'quotas' => $quotas, 'totals' => $totals, 'contexts' => array_map('array_keys', $contexts), 'id_lists' => array_map('count', $idLists), 'wheres' => $wheres];
                $workloads['buildHosts/'.$kind] = [$hosts, static fn ($h) => ElasticsearchClientFactory::buildHosts($h), static fn ($h) => ElasticsearchClientFactory::buildHosts($h, true)];
                $workloads['httpOptions/'.$kind] = [$options, static fn ($o) => ElasticsearchClientFactory::buildHttpClientOptions($o), static fn ($o) => ElasticsearchClientFactory::buildHttpClientOptions($o, true)];
                $workloads['auditEntry/'.$kind] = [$audits, static fn ($a) => ApiUsageService::auditEntryFrom($a[0], $a[1]), static fn ($a) => ApiUsageService::auditEntryFrom($a[0], $a[1], true)];
                $workloads['reservedCount/'.$kind] = [$quotas, static fn ($q) => ApiUsageService::reservedCount($q[0], $q[1]), static fn ($q) => ApiUsageService::reservedCount($q[0], $q[1], true)];
                $workloads['totalRows/'.$kind] = [$totals, static fn ($t) => ReleaseRows::totalRowsFrom($t, 1), static fn ($t) => ReleaseRows::totalRowsFrom($t, 1, true)];
                $workloads['xmlContext/'.$kind] = [$contexts, static fn ($c) => get_object_vars(XmlResponseContext::fromLegacyOptions($c)), static fn ($c) => get_object_vars(XmlResponseContext::fromLegacyOptions($c, true))];
                $workloads['positiveIds/'.$kind] = [$idLists, static fn ($i) => ReleaseSearchIndexSync::positiveIds($i), static fn ($i) => ReleaseSearchIndexSync::positiveIds($i, true)];
                $workloads['whereCondition/'.$kind] = [$wheres, static fn ($w) => ReleaseSearchIndexSync::whereCondition($w), static fn ($w) => ReleaseSearchIndexSync::whereCondition($w, true)];
            }
            for ($repeat = 0; $repeat < 2; $repeat++) {
                foreach ($random->shuffleArray(array_keys($workloads)) as $name) {
                    [$inputs, $php, $rust] = $workloads[$name];
                    foreach ($inputs as $input) {
                        self::assertSame($php($input), $rust($input), $name);
                    }
                    for ($i = 0; $i < 500; $i++) {
                        $input = $inputs[$i % count($inputs)];
                        $php($input);
                        $rust($input);
                    }
                    $pairs = [];
                    for ($i = 0; $i < 10000; $i++) {
                        $fixture = $random->getInt(0, count($inputs) - 1);
                        $input = $inputs[$fixture];
                        $first = $random->getInt(0, 1);
                        $pair = [];
                        foreach ([$first, 1 - $first] as $side) {
                            $start = hrtime(true);
                            ($side === 0 ? $php : $rust)($input);
                            $pair[$side] = hrtime(true) - $start;
                        }
                        $pairs[] = [$pair[0], $pair[1], $fixture, $first];
                    }
                    $p = array_column($pairs, 0);
                    $r = array_column($pairs, 1);
                    sort($p);
                    sort($r);
                    $result = ['repeat' => $repeat + 1, 'php_mean_ns' => array_sum($p) / count($p), 'rust_mean_ns' => array_sum($r) / count($r), 'php_p99_ns' => $p[9899], 'rust_p99_ns' => $r[9899], 'mean_speedup' => array_sum($p) / array_sum($r), 'p99_speedup' => $p[9899] / $r[9899], 'pairs_ns' => $pairs];
                    $artifact['results'][$name][] = $result;
                    fwrite(STDERR, sprintf("%s repeat %d mean %.3fx p99 %.3fx\n", $name, $repeat + 1, $result['mean_speedup'], $result['p99_speedup']));
                }
            }
            // Qualification is conservative: every metric in both repetitions must pass.
            foreach (self::METHODS as $method) {
                $ratios = [];
                foreach (['common', 'uncommon'] as $kind) {
                    foreach ($artifact['results'][$method.'/'.$kind] as $result) {
                        $ratios[$kind.'_mean'][] = $result['mean_speedup'];
                        $ratios[$kind.'_p99'][] = $result['p99_speedup'];
                    }
                }
                $minimums = array_map('min', $ratios);
                $artifact['qualified'][$method] = ['minimums' => $minimums, 'qualified' => min($minimums) >= 1.5];
                fwrite(STDERR, sprintf("%s common %.3f/%.3f uncommon %.3f/%.3f qualified=%s\n", $method, $minimums['common_mean'], $minimums['common_p99'], $minimums['uncommon_mean'], $minimums['uncommon_p99'], min($minimums) >= 1.5 ? 'yes' : 'no'));
            }
        } catch (\Throwable $error) {
            $artifact['failures'][] = $error->getMessage();
            throw $error;
        } finally {
            file_put_contents($artifactPath, (string) json_encode($artifact, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
            fwrite(STDERR, 'Raw benchmark artifact: '.$artifactPath.PHP_EOL);
        }
    }

    /**
     * @param  list<mixed>  $values
     */
    private static function generate(array $values): Generator
    {
        yield from $values;
    }

    /**
     * @param  array<array-key, mixed>  $hosts
     */
    private function hasNonScalarField(array $hosts): bool
    {
        foreach ($hosts as $host) {
            if (is_array($host)) {
                foreach (['host', 'scheme', 'port', 'user', 'pass'] as $key) {
                    if (isset($host[$key]) && ! is_scalar($host[$key])) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function randomString(Randomizer $random, int $maxLength): string
    {
        $pieces = ['a', 'Z', '0', '9', ' ', "\t", "\x00", "\x0b", "\f", '/', '@', ':', '%', '+', '&', '=', '~', '-', '_', '.', 'é', '日', '😀', "\xff", "\xc3"];
        $value = '';
        for ($i = $random->getInt(0, $maxLength); $i > 0; $i--) {
            $value .= $pieces[$random->getInt(0, count($pieces) - 1)];
        }

        return $value;
    }

    private function libraryPath(): string
    {
        $path = dirname(__DIR__, 2).'/rust/pipeline-ffi/target/release/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so');
        self::assertFileExists($path, 'Build the release Rust library before running parity checks.');

        return $path;
    }

    private function requireNative(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('search', 'int32_t nntmux_api_xml_context_mask(uint32_t tags);');
        self::assertNotNull($ffi, 'Build the release Rust library before running parity checks.');

        return $ffi;
    }
}
