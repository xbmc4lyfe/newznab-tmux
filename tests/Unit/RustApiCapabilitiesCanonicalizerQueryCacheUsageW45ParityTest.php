<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Api\ApiCapabilitiesService;
use App\Services\Api\ApiInputCanonicalizer;
use App\Services\Api\ApiQueryParameters;
use App\Services\Api\ApiReleaseRowCache;
use App\Services\Api\ApiUsageService;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;
use stdClass;

/**
 * Paired randomized benchmark (`test_paired_randomized_benchmark_gates_rust_default`): per helper, 300
 * samples per workload (1000 with `NNTMUX_W455_BENCH=1`), 20 warmup rounds, per-sample random PHP/Rust
 * order, release dylib. Timings include PHP-side filtering and framing, the FFI call and counter
 * decoding; each run prints its ratios to STDERR.
 *
 * A helper only defaults to Rust when every common and uncommon mean and p99 ratio reaches 1.5x. The
 * default run only checks that Rust-default helpers are not slower than PHP, so slower CI hosts do not
 * flake; `NNTMUX_W455_BENCH=1` enforces the full 1.5x gate for every Rust default.
 */
class RustApiCapabilitiesCanonicalizerQueryCacheUsageW45ParityTest extends TestCase
{
    /** @var list<class-string> */
    private const CLASSES = [
        ApiCapabilitiesService::class,
        ApiInputCanonicalizer::class,
        ApiQueryParameters::class,
        ApiReleaseRowCache::class,
        ApiUsageService::class,
    ];

    /**
     * Benchmark-qualified defaults. Three 1000-sample runs (Apple Silicon, PHP 8.5 CLI, release dylib)
     * gave min common mean/p99 and uncommon mean/p99 speedups of: ApiInputCanonicalizer
     * 5.32/4.71/9.82/8.49, ApiQueryParameters 2.36/2.05/3.19/2.90, ApiReleaseRowCache 1.22/1.16/1.46/1.28
     * (collecting string leaves in PHP dominates), ApiUsageService 1.38/1.29/1.24/1.13 (http_build_query()
     * and mb_substr() are already native) and ApiCapabilitiesService 0.84/0.87/0.56/0.64 (framing keys
     * costs more than array_intersect_key()). The last three keep PHP.
     *
     * @var array<class-string, bool>
     */
    private const DEFAULTS = [
        ApiCapabilitiesService::class => false,
        ApiInputCanonicalizer::class => true,
        ApiQueryParameters::class => true,
        ApiReleaseRowCache::class => false,
        ApiUsageService::class => false,
    ];

    private const RANDOM_ROUNDS = 1500;

    private const ALPHABET = ['a', 'B', 'z', 'Q', '0', '7', '_', '-', '.', '~', ',', ',', ' ', "\t", "\0", "\n", "\r", "\x0b", "\x0c", '%', '&', '=', '+', '/', "\xc3\xa9", "\xe2\x82\xac", "\xf0\x9f\x8e\xac", "\xff"];

    private const SORT_WORDS = ['posted', 'POSTED', 'cat', 'Name', 'size', 'files', 'stats', 'title', '_', 'asc', 'DESC', 'Desc', ' ', "\n", "\t", "\0", "\x0c", "\xc3\x89", ''];

    private const FUNCTIONS = ['search', 'tv-search', 'movie-search', 'audio-search', 'book-search', 'anime-search', 'tvsearch', 'Search', '', '0', 'caps'];

    private const PARAMETERS = ['q', 'id', 'cat', 'limit', 'offset', 'group', 'minsize', 'maxsize', 'maxage', 'attrs', 'extended', 'del', 'sort', 'cursor', 'vid', 'tvdbid', 'imdbid', 'tmdbid', 'traktid', 'rid', 'tvmazeid', 'season', 'ep', 'genre', 'title', 'author', 'anidbid', 'anilistid', 'apikey', 'o', 'Q', 'x y'];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::resetW455RustFfi();
        }
        (new ReflectionClass(ApiInputCanonicalizer::class))->setStaticPropertyValue('w455Ffi', null);

        parent::tearDown();
    }

    public function test_defaults_and_toggles(): void
    {
        foreach (self::CLASSES as $class) {
            $reflection = new ReflectionClass($class);
            foreach (['useW455RustFfi', 'usesW455RustFfi', 'resetW455RustFfi'] as $method) {
                $this->assertTrue($reflection->hasMethod($method), "{$class}::{$method}");
            }
            $default = (new ReflectionClassConstant($class, 'W455_RUST_FFI_DEFAULT'))->getValue();
            $this->assertSame(self::DEFAULTS[$class], $default, "{$class} default must match the benchmark evidence");
            $this->assertSame($default, $class::usesW455RustFfi(), "{$class} must start at its default");
            $class::useW455RustFfi(true);
            $this->assertTrue($class::usesW455RustFfi());
            $class::useW455RustFfi(null);
            $this->assertSame($default, $class::usesW455RustFfi(), "{$class}: null restores the default");
            $class::useW455RustFfi(false);
            $this->assertFalse($class::usesW455RustFfi());
            $class::useW455RustFfi();
            $this->assertTrue($class::usesW455RustFfi());
            $class::resetW455RustFfi();
            $this->assertSame($default, $class::usesW455RustFfi(), "{$class}: reset restores the default");
        }

        // Toggles are independent of each other.
        foreach (self::CLASSES as $class) {
            $class::useW455RustFfi(true);
            foreach (self::CLASSES as $other) {
                if ($other !== $class) {
                    $this->assertSame(self::DEFAULTS[$other], $other::usesW455RustFfi(), "{$class} leaked into {$other}");
                }
            }
            $class::resetW455RustFfi();
        }
    }

    public function test_supported_parameter_batch_examples(): void
    {
        $requests = [
            ['t' => 'search', 'id' => 'x', 'cat' => '2000', 'q' => 'legacy'],
            ['t' => 'tv-search'],
            ['t' => 'nope', 'a' => 1],
            5,
            ['q' => 'no function'],
            ['t' => 7],
        ];
        $summary = $this->both(ApiCapabilitiesService::class, static fn () => ApiCapabilitiesService::summarizeSupportedParameterBatch(2, $requests));
        $this->assertSame(['requests' => 6, 'invalid' => 3, 'known_functions' => 2, 'unknown_functions' => 1, 'parameters' => 4, 'supported_parameters' => 2, 'unsupported_parameters' => 2, 'fully_supported' => 1, 'max_parameters' => 3, 'distinct_functions' => 3], $summary);

        // Version 1 advertises `q`; unknown versions advertise nothing.
        $this->assertSame(2, $this->both(ApiCapabilitiesService::class, static fn () => ApiCapabilitiesService::summarizeSupportedParameterBatch(1, $requests))['supported_parameters']);
        $this->assertSame(0, $this->both(ApiCapabilitiesService::class, static fn () => ApiCapabilitiesService::summarizeSupportedParameterBatch(3, $requests))['known_functions']);
        $this->assertSame(
            ['requests' => 2, 'invalid' => 0, 'known_functions' => 1, 'unknown_functions' => 1, 'parameters' => 2, 'supported_parameters' => 0, 'unsupported_parameters' => 2, 'fully_supported' => 0, 'max_parameters' => 1, 'distinct_functions' => 2],
            $this->both(ApiCapabilitiesService::class, static fn () => ApiCapabilitiesService::summarizeSupportedParameterBatch(2, [['t' => 'search', 0 => 'x'], ['t' => '0', 'id' => 'y']])),
        );
    }

    public function test_list_csv_batch_examples(): void
    {
        $canonicalizer = new ApiInputCanonicalizer;
        $values = ['2000, 5030,,', '2000,5030', ' , ', '', 7, true, false, 1.5, null, ['x'], "\x0b1\0,\x0c2"];
        $summary = $this->both(ApiInputCanonicalizer::class, static fn () => $canonicalizer->summarizeListCsvBatch($values));
        $this->assertSame(['values' => 11, 'invalid' => 2, 'null_results' => 3, 'changed' => 2, 'items' => 9, 'dropped_items' => 6, 'canonical_bytes' => 27, 'max_items' => 2, 'distinct_canonical' => 5], $summary);
        $this->assertSame('2000,5030', $canonicalizer->canonicalizeListCsvPhp('2000, 5030,,'));
    }

    public function test_sort_batch_examples(): void
    {
        $summary = $this->both(ApiQueryParameters::class, static fn () => ApiQueryParameters::summarizeSortBatch(['posted_desc', " NAME_Asc\n", 'size_up', '', null, 3, 'cat_asc_', "\xc3\x89_asc", 'FILES_DESC', 'stats_asc']));
        $this->assertSame(['values' => 10, 'invalid' => 1, 'changed' => 2, 'empty' => 1, 'valid' => 4, 'asc' => 2, 'desc' => 2, 'cat' => 0, 'name' => 1, 'size' => 0, 'files' => 1, 'stats' => 1, 'posted' => 1, 'distinct_sorts' => 9], $summary);
    }

    public function test_string_leaf_batch_examples(): void
    {
        $cache = new ApiReleaseRowCache;
        $sets = [['q' => ' a ', 'cat' => [1, 2], 'limit' => 100, 'x' => null], 'bad', [], ['q' => 'a', 'id' => "\t", 'm' => ['k' => 'v']]];
        $summary = $this->both(ApiReleaseRowCache::class, static fn () => $cache->summarizeStringLeafBatch($sets));
        $this->assertSame(['sets' => 4, 'invalid' => 1, 'keys' => 7, 'strings' => 3, 'trimmed' => 2, 'blank' => 1, 'string_bytes' => 2, 'max_string_bytes' => 1, 'nested' => 2, 'other' => 2, 'max_keys' => 4, 'distinct_strings' => 2], $summary);
    }

    public function test_audit_entry_batch_examples(): void
    {
        $entries = [
            ['path' => '//api/v2/search', 'parameters' => ['cat' => '5030', 'id' => 'a b', 'o' => ['json'], 'q' => null]],
            ['path' => 'api', 'parameters' => []],
            ['path' => '/api', 'parameters' => ['q' => str_repeat('é', 70)]],
            'bad',
            ['path' => 'x'],
        ];
        $summary = $this->both(ApiUsageService::class, static fn () => ApiUsageService::summarizeAuditEntryBatch($entries));
        $this->assertSame(['entries' => 5, 'invalid' => 2, 'values' => 3, 'null_values' => 1, 'skipped_values' => 1, 'truncated_values' => 1, 'truncated_entries' => 1, 'entry_bytes' => 294, 'max_entry_bytes' => 255, 'distinct_entries' => 3], $summary);
        $this->assertSame('/api/v2/search?cat=5030&id=a%20b&q=', ApiUsageService::auditEntryFromPhp('//api/v2/search', $entries[0]['parameters']));

        // Invalid UTF-8 declines the Rust batch; mb_* substitution runs in PHP.
        $invalid = [['path' => "/api\xff", 'parameters' => []], ['path' => '/api', 'parameters' => ['q' => str_repeat("\xff", 80)]]];
        $this->assertNull(ApiInputCanonicalizer::evaluateW455Native(5, ["/api\xff", '0', '', ''], 5));
        $this->assertSame(ApiUsageService::summarizeAuditEntryBatchPhp($invalid), ApiUsageService::summarizeAuditEntryBatchNative($invalid));
    }

    public function test_every_mode_is_served_by_rust(): void
    {
        $this->requireFfi();

        $this->assertSame([1, 0, 1, 1, 0, 1, 1, 1], ApiInputCanonicalizer::evaluateW455Native(1, ['1', 'search', '2', "t\0q"], 8));
        $this->assertSame([0, 0, 1, 0, 1, 1, 1], ApiInputCanonicalizer::evaluateW455Native(2, ['a'], 7));
        $this->assertSame([1, 0, 1, 1, 0, 0, 1, 0, 0, 0, 0, 1], ApiInputCanonicalizer::evaluateW455Native(3, ['Name_Asc'], 12));
        $this->assertSame([2, 1, 0, 2, 1, 1], ApiInputCanonicalizer::evaluateW455Native(4, [' a', 'a'], 6));
        $this->assertSame([0, 0, 10, 10, 1], ApiInputCanonicalizer::evaluateW455Native(5, ['/x', '1', 'k', 'a b'], 5));
        $this->assertSame([0, 0, 0, 0, 0, 0, 0], ApiInputCanonicalizer::evaluateW455Native(2, [], 7));
        $this->assertNull(ApiInputCanonicalizer::evaluateW455Native(0, ['x'], 7));
        $this->assertNull(ApiInputCanonicalizer::evaluateW455Native(6, ['x'], 7));
        $this->assertNull(ApiInputCanonicalizer::evaluateW455Native(2, ['x'], 6));
        $this->assertNull(ApiInputCanonicalizer::evaluateW455Native(2, ['x'], 33));
        $this->assertNull(ApiInputCanonicalizer::evaluateW455Native(1, [], 8));
        $this->assertNull(ApiInputCanonicalizer::evaluateW455Native(1, ['01'], 8));
        $this->assertNull(ApiInputCanonicalizer::evaluateW455Native(1, ['2', 'search', '1', "t\0q"], 8));
        $this->assertNull(ApiInputCanonicalizer::evaluateW455Native(1, ['2', 'search', '-1', 't'], 8));
        $this->assertNull(ApiInputCanonicalizer::evaluateW455Native(1, ['2', 'search', '2'], 8));
        $this->assertNull(ApiInputCanonicalizer::evaluateW455Native(5, ['/x', '1', 'k'], 5));
        $this->assertNull(ApiInputCanonicalizer::evaluateW455Native(5, ['/x', '1', 'k', "\xc3"], 5));
        $this->assertNull(ApiInputCanonicalizer::evaluateW455Native(5, ['/x', '1', 'k', "a\0b"], 5));

        // NUL inside a key or value declines the joined framing; PHP serves the batch.
        $requests = [['t' => 'search', "q\0" => 'x']];
        $this->assertSame(ApiCapabilitiesService::summarizeSupportedParameterBatchPhp(2, $requests), ApiCapabilitiesService::summarizeSupportedParameterBatchNative(2, $requests));
        $entries = [['path' => '/api', 'parameters' => ['q' => "a\0b", "k\0" => null]]];
        $this->assertSame(ApiUsageService::summarizeAuditEntryBatchPhp($entries), ApiUsageService::summarizeAuditEntryBatchNative($entries));
    }

    public function test_native_helpers_fall_back_to_php_without_library(): void
    {
        (new ReflectionClass(ApiInputCanonicalizer::class))->setStaticPropertyValue('w455Ffi', false);
        $this->assertNull(ApiInputCanonicalizer::evaluateW455Native(2, ['x'], 7));

        $requests = [['t' => 'search', 'q' => 'x'], 1];
        $this->assertSame(ApiCapabilitiesService::summarizeSupportedParameterBatchPhp(1, $requests), ApiCapabilitiesService::summarizeSupportedParameterBatchNative(1, $requests));
        $canonicalizer = new ApiInputCanonicalizer;
        $this->assertSame($canonicalizer->summarizeListCsvBatchPhp(['1, 2', null]), $canonicalizer->summarizeListCsvBatchNative(['1, 2', null]));
        $this->assertSame(ApiQueryParameters::summarizeSortBatchPhp(['NAME_asc', []]), ApiQueryParameters::summarizeSortBatchNative(['NAME_asc', []]));
        $cache = new ApiReleaseRowCache;
        $this->assertSame($cache->summarizeStringLeafBatchPhp([['q' => ' x'], 1]), $cache->summarizeStringLeafBatchNative([['q' => ' x'], 1]));
        $entries = [['path' => '/api', 'parameters' => ['q' => 'x']], 1];
        $this->assertSame(ApiUsageService::summarizeAuditEntryBatchPhp($entries), ApiUsageService::summarizeAuditEntryBatchNative($entries));
    }

    public function test_direct_c_abi_declines_invalid_input_and_reports_needed_capacity(): void
    {
        $ffi = $this->requireFfi();
        $small = $ffi->new('int64_t[4]');
        $big = $ffi->new('int64_t[32]');
        $call = static function (int $mode, string $input, mixed $out, int $cap) use ($ffi): int {
            // @phpstan-ignore method.notFound
            return $ffi->nntmux_w455_api_capabilities_canonicalizer_query_cache_usage($mode, $input, \strlen($input), $out, $cap);
        };

        $packet = self::packet(['a, b', 'c']);
        $this->assertSame(7, $call(2, $packet, $big, 32));
        $this->assertSame(-7, $call(2, $packet, $small, 4));
        $this->assertSame(0, $small[0]);
        $this->assertSame(-7, $call(2, $packet, null, 0));
        $this->assertSame(-1, $call(2, $packet, null, 8));
        foreach ([0, 6, 99] as $mode) {
            $this->assertSame(-1, $call($mode, $packet, $big, 32));
        }
        $this->assertSame(-1, $call(2, '', $big, 32));
        $this->assertSame(-1, $call(2, "\x01\x00\x00", $big, 32));
        $this->assertSame(-1, $call(2, "\x01\x00\x00\x00\x09\x00\x00\x00", $big, 32));
        $this->assertSame(-1, $call(2, $packet."\x00", $big, 32));
        $this->assertSame(-1, $call(2, "\xff\xff\xff\xff", $big, 32));
    }

    public function test_randomized_parity_across_helpers(): void
    {
        $this->requireFfi();
        mt_srand(4555);
        $canonicalizer = new ApiInputCanonicalizer;
        $cache = new ApiReleaseRowCache;
        $pick = static fn (array $items): mixed => $items[mt_rand(0, \count($items) - 1)];
        $odd = static fn (): mixed => $pick([7, -3, 0, 1.5, -0.0, 1e20, true, false, null, ['x'], new stdClass]);

        for ($round = 0; $round < self::RANDOM_ROUNDS; $round++) {
            $requests = [];
            for ($i = mt_rand(0, 6); $i > 0; $i--) {
                if (mt_rand(0, 12) === 0) {
                    $requests[] = $pick([1, 'search', null, ['q' => 'x'], ['t' => 5], ['t' => null]]);

                    continue;
                }
                $request = ['t' => $pick(self::FUNCTIONS)];
                for ($j = mt_rand(0, 8); $j > 0; $j--) {
                    $request[mt_rand(0, 9) === 0 ? mt_rand(0, 3) : $pick(self::PARAMETERS)] = self::randomBytes(4);
                }
                $requests[] = mt_rand(0, 1) === 1 ? array_reverse($request, true) : $request;
            }
            $version = $pick([1, 2, 2, 0, 3, -1]);
            $this->assertParity(ApiCapabilitiesService::class, static fn () => ApiCapabilitiesService::summarizeSupportedParameterBatch($version, $requests), [$version, $requests]);

            $values = [];
            for ($i = mt_rand(0, 6); $i > 0; $i--) {
                $values[] = mt_rand(0, 10) === 0 ? $odd() : self::randomBytes(14);
            }
            $this->assertParity(ApiInputCanonicalizer::class, static fn () => $canonicalizer->summarizeListCsvBatch($values), $values);

            $sorts = [];
            for ($i = mt_rand(0, 6); $i > 0; $i--) {
                $sort = '';
                for ($j = mt_rand(0, 5); $j > 0; $j--) {
                    $sort .= $pick(self::SORT_WORDS);
                }
                $sorts[] = match (mt_rand(0, 9)) {
                    0 => $odd(),
                    1 => $pick(['cat', 'name', 'size', 'files', 'stats', 'posted']).'_'.$pick(['asc', 'desc', 'ASC', 'Desc']),
                    default => $sort,
                };
            }
            $this->assertParity(ApiQueryParameters::class, static fn () => ApiQueryParameters::summarizeSortBatch($sorts), $sorts);

            $sets = [];
            for ($i = mt_rand(0, 5); $i > 0; $i--) {
                if (mt_rand(0, 10) === 0) {
                    $sets[] = $pick(['set', 1, null]);

                    continue;
                }
                $set = [];
                for ($j = mt_rand(0, 6); $j > 0; $j--) {
                    $set[$pick(self::PARAMETERS)] = match (mt_rand(0, 5)) {
                        0 => $odd(),
                        1 => [self::randomBytes(3), self::randomBytes(3)],
                        default => self::randomBytes(10),
                    };
                }
                $sets[] = $set;
            }
            $this->assertParity(ApiReleaseRowCache::class, static fn () => $cache->summarizeStringLeafBatch($sets), $sets);

            $entries = [];
            for ($i = mt_rand(0, 5); $i > 0; $i--) {
                if (mt_rand(0, 12) === 0) {
                    $entries[] = $pick([1, 'entry', ['path' => 5, 'parameters' => []], ['path' => '/a', 'parameters' => 'x'], []]);

                    continue;
                }
                $parameters = [];
                for ($j = mt_rand(0, 6); $j > 0; $j--) {
                    $parameters[mt_rand(0, 9) === 0 ? mt_rand(0, 3) : $pick(self::PARAMETERS)] = match (mt_rand(0, 6)) {
                        0 => $pick([null, 3, ['json'], true]),
                        1 => self::randomBytes(90),
                        default => self::randomBytes(12),
                    };
                }
                $entries[] = ['path' => $pick(['api/v2/search', '/api/v1/api', '//api', '', '/', "/api/caf\xc3\xa9"]).(mt_rand(0, 3) === 0 ? self::randomBytes(6) : ''), 'parameters' => $parameters];
            }
            $this->assertParity(ApiUsageService::class, static fn () => ApiUsageService::summarizeAuditEntryBatch($entries), $entries);
        }
    }

    public function test_paired_randomized_benchmark_gates_rust_default(): void
    {
        $this->requireFfi();
        mt_srand(4551);
        $strict = getenv('NNTMUX_W455_BENCH') === '1';
        $samples = $strict ? 1000 : 300;

        $canonicalizer = new ApiInputCanonicalizer;
        $cache = new ApiReleaseRowCache;
        $commonRequests = [];
        $commonCsvs = [];
        $commonSorts = [];
        $commonSets = [];
        $commonEntries = [];
        $categories = ['2000', '5030', '5040', '2040', '5070', '7020', '3010'];
        $sortValues = ['posted_desc', 'size_asc', 'name_asc', 'posted_asc', 'files_desc', 'stats_desc', 'cat_asc', 'POSTED_DESC', ' size_desc '];
        for ($i = 0; $i < 50; $i++) {
            $commonRequests[] = $i % 2 === 0
                ? ['t' => 'search', 'id' => 'Show Name S01E'.($i % 24), 'cat' => '5030,5040', 'limit' => '100', 'offset' => (string) ($i * 100)]
                : ['t' => 'tv-search', 'tvdbid' => (string) (70000 + $i), 'season' => '1', 'ep' => (string) ($i % 24), 'cat' => '5040', 'apikey' => 'k'];
            $commonCsvs[] = implode($i % 3 === 0 ? ', ' : ',', \array_slice($categories, $i % 4, 1 + $i % 3));
            $commonSorts[] = $sortValues[$i % \count($sortValues)];
            $commonSets[] = ['cat' => [5030, 5040], 'id' => ' Show Name S01E'.($i % 24), 'limit' => 100, 'maxage' => -1, 'minsize' => 0, 'offset' => $i * 100, 'sort' => 'posted_desc', 'group' => -1];
            $commonEntries[] = ['path' => 'api/v2/search', 'parameters' => ['cat' => '5030,5040', 'id' => 'Show Name S01E'.($i % 24).' 1080p', 'limit' => '100', 'offset' => (string) ($i * 100), 't' => 'search']];
        }

        $bigRequests = [];
        $bigCsvs = [];
        $bigSorts = [];
        $bigSets = [];
        $bigEntries = [];
        for ($i = 0; $i < 3000; $i++) {
            $request = ['t' => self::FUNCTIONS[$i % \count(self::FUNCTIONS)]];
            for ($j = 0; $j < 4 + $i % 16; $j++) {
                $request[self::PARAMETERS[($i + $j * 7) % \count(self::PARAMETERS)]] = (string) $j;
            }
            $bigRequests[] = $request;
            $bigCsvs[] = $i % 5 === 0 ? str_repeat(' 5030 ,, 5040,', 1 + $i % 30) : implode(',', \array_slice([...$categories, ...$categories], $i % 7, 1 + $i % 10));
            $bigSorts[] = $i % 4 === 0 ? str_repeat(' ', $i % 5).strtoupper($sortValues[$i % 9])."\n" : $sortValues[$i % 9].($i % 7 === 0 ? '_x' : '');
            $bigSets[] = ['q' => str_repeat(' padded query ', 1 + $i % 20), 'cat' => [$i % 10, 5030], 'imdbid' => (string) (1000000 + $i), 'limit' => $i % 500, 'sort' => "  size_desc\t", 'season' => 'S'.($i % 30), 'ep' => (string) ($i % 50), 'extended' => '1', 'del' => '0', 'attrs' => 'size,files,poster', 'tvdbid' => null];
            $bigEntries[] = ['path' => '/api/v1/api', 'parameters' => ['q' => str_repeat($i % 3 === 0 ? 'Film Ünïcödé ' : 'Long Title ', 1 + $i % 12), 'cat' => '2000,2040,2045', 'imdbid' => (string) (1000000 + $i), 'extended' => '1', 'o' => ['json'], 'del' => null, 'limit' => '500', 'offset' => (string) $i, 't' => 'movie']];
        }

        $workloads = [
            ApiCapabilitiesService::class => [
                static fn () => ApiCapabilitiesService::summarizeSupportedParameterBatch(2, $commonRequests),
                static fn () => ApiCapabilitiesService::summarizeSupportedParameterBatch(1, $bigRequests),
            ],
            ApiInputCanonicalizer::class => [
                static fn () => $canonicalizer->summarizeListCsvBatch($commonCsvs),
                static fn () => $canonicalizer->summarizeListCsvBatch($bigCsvs),
            ],
            ApiQueryParameters::class => [
                static fn () => ApiQueryParameters::summarizeSortBatch($commonSorts),
                static fn () => ApiQueryParameters::summarizeSortBatch($bigSorts),
            ],
            ApiReleaseRowCache::class => [
                static fn () => $cache->summarizeStringLeafBatch($commonSets),
                static fn () => $cache->summarizeStringLeafBatch($bigSets),
            ],
            ApiUsageService::class => [
                static fn () => ApiUsageService::summarizeAuditEntryBatch($commonEntries),
                static fn () => ApiUsageService::summarizeAuditEntryBatch($bigEntries),
            ],
        ];

        foreach ($workloads as $class => [$common, $uncommon]) {
            $class::useW455RustFfi(false);
            $expected = [$common(), $uncommon()];
            $class::useW455RustFfi(true);
            $this->assertSame($expected, [$common(), $uncommon()], "{$class} benchmark workloads must agree");

            $results = [];
            foreach (['common' => [$common, 20], 'uncommon' => [$uncommon, 1]] as $name => [$workload, $calls]) {
                for ($w = 0; $w < 20; $w++) {
                    foreach ([false, true] as $rust) {
                        $class::useW455RustFfi($rust);
                        $workload();
                    }
                }

                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < $samples; $s++) {
                    $order = mt_rand(0, 1) === 1 ? [true, false] : [false, true];
                    foreach ($order as $rust) {
                        $class::useW455RustFfi($rust);
                        $t0 = hrtime(true);
                        for ($i = 0; $i < $calls; $i++) {
                            $workload();
                        }
                        $times[$rust ? 'rust' : 'php'][] = (hrtime(true) - $t0) / ($calls * 1000);
                    }
                }
                $class::resetW455RustFfi();

                $results[$name] = [
                    'mean' => self::mean($times['php']) / max(1e-9, self::mean($times['rust'])),
                    'p99' => self::p99($times['php']) / max(1e-9, self::p99($times['rust'])),
                    'php_mean_us' => self::mean($times['php']),
                    'rust_mean_us' => self::mean($times['rust']),
                ];
            }

            fwrite(STDERR, sprintf(
                "\n[Wave 45.5 benchmark] %s: common mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx | uncommon mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx",
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

            $this->assertSame(self::DEFAULTS[$class], $class::usesW455RustFfi());
            if (! self::DEFAULTS[$class]) {
                continue;
            }
            $ratios = [$results['common']['mean'], $results['common']['p99'], $results['uncommon']['mean'], $results['uncommon']['p99']];
            if ($strict) {
                $this->assertGreaterThanOrEqual(1.5, min($ratios), "{$class} defaults to Rust without clearing every 1.5x mean/p99 gate");
            } else {
                $this->assertGreaterThanOrEqual(1.0, min($results['common']['mean'], $results['uncommon']['mean']), "{$class} defaults to Rust but Rust is slower than PHP");
            }
        }
        fwrite(STDERR, "\n");
    }

    /**
     * Runs $plan with PHP then Rust, asserts identical results and returns the PHP result.
     *
     * @template T
     *
     * @param  class-string  $class
     * @param  callable(): T  $plan
     * @return T
     */
    private function both(string $class, callable $plan): mixed
    {
        $class::useW455RustFfi(false);
        $php = $plan();
        $class::useW455RustFfi(true);
        $rust = $plan();
        $class::resetW455RustFfi();
        $this->assertSame($php, $rust);

        return $php;
    }

    /**
     * @param  class-string  $class
     * @param  array<array-key, mixed>  $args
     */
    private function assertParity(string $class, callable $plan, array $args): void
    {
        $class::useW455RustFfi(false);
        $php = $plan();
        $class::useW455RustFfi(true);
        $rust = $plan();
        $class::resetW455RustFfi();
        $this->assertSame($php, $rust, bin2hex(serialize(self::describe($args))));
    }

    private static function describe(mixed $value): mixed
    {
        if (\is_object($value)) {
            return $value::class;
        }

        return \is_array($value) ? array_map(self::describe(...), $value) : $value;
    }

    private static function randomBytes(int $maxLength): string
    {
        $out = '';
        for ($i = mt_rand(0, $maxLength); $i > 0; $i--) {
            $out .= self::ALPHABET[mt_rand(0, \count(self::ALPHABET) - 1)];
        }

        return $out;
    }

    /**
     * @param  list<string>  $parts
     */
    private static function packet(array $parts): string
    {
        return pack('V*', \count($parts), ...array_map(\strlen(...), $parts)).implode('', $parts);
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

    private function requireFfi(): FFI
    {
        $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $ffi = RustPipelineLibrary::resolveDomain('search_w455', ApiInputCanonicalizer::W455_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null);
        if ($ffi === null) {
            $this->markTestSkipped('Rust search FFI library is not built or available.');
        }

        return $ffi;
    }
}
