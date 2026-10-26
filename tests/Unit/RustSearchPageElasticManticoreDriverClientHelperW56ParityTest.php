<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Search\Drivers\ElasticSearchDriver;
use App\Services\Search\Drivers\ManticoreSearchDriver;
use App\Services\Search\DTO\SearchPage;
use App\Services\Search\Support\ElasticsearchClientFactory;
use App\Services\Search\Support\ElasticsearchResponseHelper;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use RuntimeException;

/**
 * Paired randomized benchmark (`test_paired_randomized_benchmark_gates_rust_default`, run with
 * `NNTMUX_W565_BENCH=1`): per helper, 200 interleaved samples per workload after 20 warmup rounds,
 * per-sample random PHP/Rust order, each side the fastest of three back-to-back calls, release dylib.
 * Common workloads hold 320 records and uncommon ones 384 records with edge cases. Timings include
 * PHP-side packing, the FFI call and output decoding; each run prints its ratios to STDERR.
 *
 * A helper only defaults to Rust when every recorded common and uncommon mean and p99 ratio reaches 1.5x
 * (QUALIFICATION); each benchmark run asserts that the Rust defaults still clear 1.5x.
 */
class RustSearchPageElasticManticoreDriverClientHelperW56ParityTest extends TestCase
{
    /**
     * Batch method per class (four words per record); each has `<method>Php` and `<method>Native` variants.
     *
     * @var array<class-string, string>
     */
    private const METHODS = [
        SearchPage::class => 'idListPlanBatch',
        ElasticSearchDriver::class => 'queryStringPlanBatch',
        ManticoreSearchDriver::class => 'userQueryPlanBatch',
        ElasticsearchClientFactory::class => 'hostUriPlanBatch',
        ElasticsearchResponseHelper::class => 'hitPlanBatch',
    ];

    /**
     * C entry point per class.
     *
     * @var array<class-string, string>
     */
    private const FUNCTIONS = [
        SearchPage::class => 'nntmux_w565_search_page_id_list_plan',
        ElasticSearchDriver::class => 'nntmux_w565_elastic_query_string_plan',
        ManticoreSearchDriver::class => 'nntmux_w565_manticore_user_query_plan',
        ElasticsearchClientFactory::class => 'nntmux_w565_es_client_host_uri_plan',
        ElasticsearchResponseHelper::class => 'nntmux_w565_es_response_hit_plan',
    ];

    /**
     * Benchmark-qualified defaults (see the class docblock for the gate).
     *
     * @var array<class-string, bool>
     */
    private const DEFAULTS = [
        SearchPage::class => true,
        ElasticSearchDriver::class => true,
        ManticoreSearchDriver::class => true,
        ElasticsearchClientFactory::class => true,
        ElasticsearchResponseHelper::class => true,
    ];

    /**
     * Common mean/p99 then uncommon mean/p99 speedups (PHP / Rust), the per-metric minimum of four
     * `NNTMUX_W565_BENCH=1` runs (200 samples per workload, seed 5652, release Rust, PHP 8.5 CLI without
     * opcache, Apple Silicon macOS on a shared host).
     *
     * @var array<class-string, array{float, float, float, float}>
     */
    private const QUALIFICATION = [
        SearchPage::class => [7.60, 6.56, 6.74, 6.71],
        ElasticSearchDriver::class => [8.56, 8.88, 8.00, 7.21],
        ManticoreSearchDriver::class => [11.50, 11.12, 10.26, 9.35],
        ElasticsearchClientFactory::class => [3.47, 3.77, 3.94, 4.19],
        ElasticsearchResponseHelper::class => [3.64, 3.51, 3.57, 3.29],
    ];

    /** Back-to-back calls per side of a sample; the fastest is kept (drops preemption spikes). */
    private const REPEATS = 3;

    private const RANDOM_ROUNDS = 400;

    private const BENCH_SAMPLES = 200;

    private const WORDS = 4;

    private const BYTES = ['a', 'Z', 'E', '0', '1', '9', ' ', "\t", "\n", "\r", "\x0b", "\x0c", "\x01", "\x7f", '-', '_', '.', ',', ':', '/', '\\', '[', ']', '{', '}', '"', '!', '*', '|', '@', '(', ')', "\xc3\xa9", "\xf0\x9f\x8e\xac", "\xc3", "\xff", "\0"];

    /** Fragments the randomized rounds glue together so ids, queries, hosts and response keys actually occur. */
    private const FRAGMENTS = ['12', '007', '1234567890123456789', '999999999999999999', ',', ', ', ' , ', 'x', '-1', 'The.Matrix', '..', ' -', '!', '"', '"a b"', '-"', '!"x', '|', '*', 'man*', '(', ')', 'http://', 'HTTPS://', 'ftp://', '://', 'user:pass@', '@', '[::1]', '[', ']', ':9200', ':', ':99999', '/path', 'es.example.com', '"_id":', '"_id":"1"', '"error":', '"errors":', '"errors": true', 'false', '"total":', '"total": {', '"value":', '"value": 42', '{', '}', ' 7'];

    protected function tearDown(): void
    {
        foreach (array_keys(self::METHODS) as $class) {
            self::state($class, 'reset', null);
        }

        parent::tearDown();
    }

    /**
     * @return array<string, array{class-string, string}>
     */
    public static function helperProvider(): array
    {
        $cases = [];
        foreach (self::METHODS as $class => $method) {
            $cases[(new ReflectionClass($class))->getShortName()] = [$class, $method];
        }

        return $cases;
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_methods_defaults_and_scoped_toggles(string $class, string $method): void
    {
        $reflection = new ReflectionClass($class);
        foreach (['usesW56RustFfi', 'withW56RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
            $this->assertTrue($reflection->hasMethod($name), "{$class}::{$name}");
            $this->assertTrue($reflection->getMethod($name)->isStatic() && $reflection->getMethod($name)->isPublic(), "{$class}::{$name}");
        }
        $this->assertTrue($reflection->getMethod('w56RustFfiState')->isPrivate(), "{$class} keeps its W56 state private");
        $constant = new ReflectionClassConstant($class, 'W56_RUST_FFI_DEFAULT');
        $this->assertTrue($constant->isPrivate(), "{$class} default must be private");
        $this->assertSame(self::DEFAULTS[$class], $constant->getValue(), "{$class} default must match the benchmark evidence");
        $this->assertSame(min(self::QUALIFICATION[$class]) >= 1.5, self::DEFAULTS[$class], "{$class} default must follow its recorded qualification");
        $this->assertSame(self::DEFAULTS[$class], $class::usesW56RustFfi());

        foreach ([true, false] as $enabled) {
            $this->assertSame([$enabled, 'ok'], $class::withW56RustFfi($enabled, static fn () => [$class::usesW56RustFfi(), 'ok']));
            $this->assertSame(self::DEFAULTS[$class], $class::usesW56RustFfi(), "{$class} must restore after the callback");
        }
        $nested = $class::withW56RustFfi(false, static fn () => [$class::withW56RustFfi(true, static fn () => $class::usesW56RustFfi()), $class::usesW56RustFfi()]);
        $this->assertSame([true, false], $nested);
        $caught = null;
        try {
            $class::withW56RustFfi(! $class::usesW56RustFfi(), static fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException $exception) {
            $caught = $exception->getMessage();
        }
        $this->assertSame('boom', $caught, 'The callback exception must propagate.');
        $this->assertSame(self::DEFAULTS[$class], $class::usesW56RustFfi(), "{$class} must restore after an exception");

        $class::withW56RustFfi(! $class::usesW56RustFfi(), function () use ($class): void {
            foreach (array_keys(self::METHODS) as $other) {
                if ($other !== $class) {
                    $this->assertSame(self::DEFAULTS[$other], $other::usesW56RustFfi(), "{$class} leaked into {$other}");
                }
            }
        });
    }

    public function test_search_page_stays_a_readonly_class_without_static_properties(): void
    {
        $reflection = new ReflectionClass(SearchPage::class);
        $this->assertTrue($reflection->isReadOnly());
        $this->assertTrue($reflection->isFinal());
        $this->assertSame([], $reflection->getStaticProperties());
    }

    public function test_earlier_wave_toggles_are_untouched(): void
    {
        $snapshot = static fn (): array => [
            SearchPage::usesW415RustFfi(),
            ElasticSearchDriver::usesRustFfi(),
            ElasticSearchDriver::usesW405RustFfi(),
            ManticoreSearchDriver::usesRustFfi(),
            ManticoreSearchDriver::usesW405RustFfi(),
            ElasticsearchClientFactory::usesW425RustFfi(),
            ElasticsearchResponseHelper::usesW425RustFfi(),
            ElasticsearchResponseHelper::$useRustFfi,
        ];
        $before = $snapshot();
        $this->assertSame([false, false, false, false, false, false, false, false], $before);
        foreach (array_keys(self::METHODS) as $class) {
            foreach ([true, false] as $enabled) {
                $this->assertSame($before, $class::withW56RustFfi($enabled, $snapshot), $class);
            }
        }
        $this->assertSame($before, $snapshot());
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_entry_points_route_by_toggle(string $class, string $method): void
    {
        $this->requireFfi($class);
        $records = self::firstRecords($class);
        $php = $class::{$method.'Php'}($records);
        $this->assertCount(self::WORDS * \count($records), $php);
        $this->assertSame($php, $class::withW56RustFfi(false, static fn () => $class::{$method}($records)), $class);
        $this->assertSame($php, $class::withW56RustFfi(true, static fn () => $class::{$method}($records)), $class);
        $this->assertSame($php, $class::{$method.'Native'}($records), $class);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_empty_batches_return_no_words(string $class, string $method): void
    {
        $this->assertSame([], $class::{$method.'Php'}([]));
        $this->assertSame([], $class::{$method.'Native'}([]));
        $this->assertSame([], $class::{$method}([]));
        // One empty record still yields four words.
        $this->assertSame($class::{$method.'Php'}(['']), $class::{$method.'Native'}(['']));
        $this->assertCount(self::WORDS, $class::{$method}(['']));
    }

    public function test_id_list_plan_examples(): void
    {
        $this->requireFfi(SearchPage::class);
        $this->assertBoth(SearchPage::class, [
            '1, 2,3,2 ,007,7',
            '',
            ',, ,',
            'x,-1,1.5,9',
            '1234567890123456789,999999999999999999',
            "\t42\n",
            "\x0b5\x0b,\x0c6",
            '0,00,000',
        ], [
            6, 4, 7, 0,
            0, 0, 0, 0,
            0, 0, 0, 0,
            1, 1, 9, 3,
            1, 1, 999999999999999999, 1,
            1, 1, 42, 0,
            1, 1, 5, 1,
            3, 1, 0, 0,
        ]);
    }

    public function test_query_string_plan_examples(): void
    {
        $this->requireFfi(ElasticSearchDriver::class);
        $queries = [
            '',
            '0',
            '*',
            'The.Matrix.1999',
            "  a   b\tc ",
            '...',
            "\x0cx",
            "foo:(bar) -baz caf\u{e9}",
            '00',
            ' * ',
        ];
        $this->assertBoth(ElasticSearchDriver::class, $queries, [
            0, 0, 0, 1,
            0, 0, 0, 1,
            0, 0, 0, 1,
            15, 3, 0, 4,
            5, 3, 0, 8,
            0, 0, 0, 4,
            2, 1, 0, 8,
            20, 3, 4, 2,
            2, 1, 0, 0,
            1, 1, 1, 0,
        ]);
        // The normalized length is exactly the length of the driver's own escapeStringPhp() output.
        foreach ([...$queries, "a.\x0b.b", "\r\n x \f", 'Spider-Man.No.Way.Home.2021.1080p'] as $query) {
            $this->assertSame(\strlen(ElasticSearchDriver::escapeStringPhp($query)), ElasticSearchDriver::queryStringPlanBatchNative([$query])[0], var_export($query, true));
        }
    }

    public function test_user_query_plan_examples(): void
    {
        $this->requireFfi(ManticoreSearchDriver::class);
        $this->assertBoth(ManticoreSearchDriver::class, [
            '',
            ' * ',
            'harry -potter !"deathly hallows" | *man*',
            '"unterminated phrase',
            '-"a" "" x"y z"',
            "- ! caf\u{e9}",
            "\"multi\nline\" --x !!",
            '**',
        ], [
            0, 0, 0, 1,
            0, 0, 0, 1,
            5, 2, 1, 6,
            2, 0, 0, 0,
            4, 1, 2, 0,
            3, 0, 0, 8,
            3, 2, 1, 0,
            1, 0, 0, 4,
        ]);
    }

    public function test_host_uri_plan_examples(): void
    {
        $this->requireFfi(ElasticsearchClientFactory::class);
        $this->assertBoth(ElasticsearchClientFactory::class, [
            '',
            'localhost',
            ' https://user:pass@es.example.com:9243/prefix ',
            'HTTP://[::1]:9200',
            'ftp://host:0',
            'http://[::1',
            'http://:9200',
            'bad host:99999',
            'http://[::1]x',
            'http://a@b@c:00080',
            'es:',
        ], [
            0, -1, 0, 1,
            0, -1, 9, 0,
            2, 9243, 14, 6,
            1, 9200, 5, 8,
            3, -2, 4, 16,
            1, -1, 4, 16,
            1, 9200, 0, 16,
            0, -2, 8, 48,
            1, -1, 5, 24,
            1, 80, 1, 4,
            0, -2, 2, 16,
        ]);
        // Every host string buildHostsPhp() assembles parses back without an invalid flag.
        $built = ElasticsearchClientFactory::buildHostsPhp([['host' => 'es1', 'port' => 9200], ['host' => 'es2', 'scheme' => 'https', 'user' => 'u', 'pass' => 'p w']]);
        $this->assertSame([1, 9200, 3, 0, 2, -1, 3, 4], ElasticsearchClientFactory::hostUriPlanBatchNative($built));
    }

    public function test_hit_plan_examples(): void
    {
        $this->requireFfi(ElasticsearchResponseHelper::class);
        $this->assertBoth(ElasticsearchResponseHelper::class, [
            '{"hits":{"total":{"value":42,"relation":"eq"},"hits":[{"_id":"1"},{"_id":"2"}]}}',
            '{"errors": true,"items":[{"index":{"error":{}}},{"index":{"error":{}}}]}',
            '{"errors":false}',
            '{"hits":{"total": 7}}',
            '',
            '{"total":{"relation":"eq"},"value":3}',
            '{"total":1234567890123456789}',
            "{\"total\":\n{ \"value\":\t5}}",
            '{"errors":"true","total":x}',
        ], [
            2, 42, -1, 0,
            0, -1, 1, 2,
            0, -1, 0, 0,
            0, 7, -1, 0,
            0, -1, -1, 0,
            0, -1, -1, 0,
            0, -2, -1, 0,
            0, 5, -1, 0,
            0, -1, -1, 0,
        ]);
        // Ids and totals agree with the decoded response for real search bodies.
        $response = ['hits' => ['total' => ['value' => 3, 'relation' => 'eq'], 'hits' => [['_id' => '7'], ['_id' => '8'], ['_id' => '9']]]];
        $words = ElasticsearchResponseHelper::hitPlanBatchNative([json_encode($response, JSON_THROW_ON_ERROR)]);
        $this->assertSame([3, 3, -1, 0], $words);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_every_helper_is_served_by_rust(string $class, string $method): void
    {
        $this->assertInstanceOf(FFI::class, $this->requireFfi($class));
        $records = self::firstRecords($class);
        $this->assertCount(self::WORDS * \count($records), $class::{$method.'Native'}($records));
        $handle = self::state($class, 'buffer', 1);
        $this->assertIsArray($handle, "{$class} must resolve the Rust helper");
        $this->assertInstanceOf(FFI::class, $handle[0]);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_large_batches_grow_the_output_buffer(string $class, string $method): void
    {
        $this->requireFfi($class);
        $records = [];
        for ($i = 0; $i < 3000; $i++) {
            $records[] = self::firstRecords($class)[$i % 3].$i;
        }
        $this->assertSame($class::{$method.'Php'}($records), $class::{$method.'Native'}($records), $class);
        $handle = self::state($class, 'buffer', 0);
        $this->assertIsArray($handle);
        $this->assertSame(12000, $handle[2]);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_native_helpers_fall_back_to_php_without_library(string $class, string $method): void
    {
        self::state($class, 'reset', false);
        $records = self::firstRecords($class);
        $this->assertSame($class::{$method.'Php'}($records), $class::{$method.'Native'}($records), $class);
        $this->assertSame($class::{$method.'Php'}($records), $class::withW56RustFfi(true, static fn () => $class::{$method}($records)), $class);
        $this->assertNull(self::state($class, 'buffer', 1), "{$class} must keep the disabled handle");
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_nul_bytes_decline_to_php(string $class, string $method): void
    {
        $this->requireFfi($class);
        $records = self::firstRecords($class);
        $records[1] .= "\0x";
        $this->assertSame($class::{$method.'Php'}($records), $class::{$method.'Native'}($records), $class);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_direct_c_abi_declines_invalid_input_and_reports_needed_capacity(string $class, string $method): void
    {
        $this->assertNotSame('', $method);
        $ffi = $this->requireFfi($class);
        $function = self::abiFunction($class);
        $data = implode("\0", self::firstRecords($class));
        $needed = 3 * self::WORDS;
        $out = $ffi->new('int64_t[16]');
        $small = $ffi->new('int64_t[1]');
        $small[0] = 7;
        $call = static fn (int $count, int $flags, string $data, string $ints, mixed $out, int $cap): int => $ffi->{$function}($count, $flags, $data, \strlen($data), $ints, \strlen($ints), $out, $cap);

        $this->assertSame($needed, $call(3, 0, $data, '', $out, 16), $function);
        $this->assertSame(-$needed, $call(3, 0, $data, '', $small, 1), $function);
        $this->assertSame(7, $small[0], "{$function} must not write on a capacity error");
        $this->assertSame(-$needed, $call(3, 0, $data, '', null, 0), $function);
        $this->assertSame(-1, $call(3, 0, $data, '', null, 4), $function);
        $this->assertSame(-1, $call(3, 1, $data, '', $out, 16), "{$function} flags");
        $this->assertSame(-1, $call(4, 0, $data, '', $out, 16), "{$function} record count");
        $this->assertSame(-1, $call(2, 0, $data, '', $out, 16), "{$function} record count");
        $this->assertSame(-1, $call(0, 0, $data, '', $out, 16), "{$function} record count");
        $this->assertSame(-1, $call(3, 0, $data, pack('P', 1), $out, 16), "{$function} int table");
        $this->assertSame(0, $call(0, 0, '', '', null, 0), "{$function} empty batch");
    }

    /**
     * Over 400 rounds of 1-16 records each (thousands of inputs per helper), every class's Rust and PHP
     * plans agree bit for bit; every 50th round also carries NUL bytes, which must decline to PHP.
     */
    public function test_randomized_parity_across_helpers(): void
    {
        $this->requireFfi(SearchPage::class);
        mt_srand(5651);
        $checked = 0;
        for ($round = 0; $round < self::RANDOM_ROUNDS; $round++) {
            $withNul = $round % 50 === 49;
            $records = [];
            for ($i = 0, $count = mt_rand(1, 16); $i < $count; $i++) {
                $record = '';
                for ($p = 0, $parts = mt_rand(0, 10); $p < $parts; $p++) {
                    $record .= mt_rand(0, 1) === 1 ? self::FRAGMENTS[mt_rand(0, \count(self::FRAGMENTS) - 1)] : self::randomBytes(6, $withNul);
                }
                $records[] = $record;
            }
            foreach (array_keys(self::METHODS) as $class) {
                $this->assertBoth($class, $records, null);
            }
            $checked += \count($records);
        }
        $this->assertGreaterThanOrEqual(300, $checked);
    }

    public function test_paired_randomized_benchmark_gates_rust_default(): void
    {
        if (getenv('NNTMUX_W565_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_W565_BENCH=1 to run the Wave 56.5 paired benchmark.');
        }
        $this->requireFfi(SearchPage::class);
        mt_srand(5652);
        $workloads = [];
        $floors = [];

        foreach (['common' => 320, 'uncommon' => 384] as $name => $count) {
            $odd = $name === 'uncommon';
            $ids = $queries = $userQueries = $hosts = $bodies = [];
            for ($i = 0; $i < $count; $i++) {
                $ids[] = $odd
                    ? match ($i % 5) {
                        0 => implode(', ', range($i, $i + 60)).', '.implode(',', range($i, $i + 30)),
                        1 => '',
                        2 => 'x, -1, 1.5, '.str_repeat('7,', 12).' 1234567890123456789',
                        3 => " \t007 , 7 ,, 0007,\n",
                        default => implode(',', array_map(static fn (int $k): string => (string) ($k * 7919 % 1000), range(1, 40))),
                    }
                : implode(',', range(1000 * $i, 1000 * $i + 24));

                $queries[] = $odd
                    ? match ($i % 5) {
                        0 => '  The...Matrix  Reloaded.2003.1080p.BluRay  x264  ',
                        1 => '*',
                        2 => "caf\u{e9}\tna\u{ef}ve \x0c\x0c title:(foo OR bar) -baz",
                        3 => str_repeat('word.', 40),
                        default => '"exact phrase"~2 +must -not a&&b || c!',
                    }
                : 'Some.Release.Name.S0'.($i % 10).'E'.\sprintf('%02d', $i % 30).'.1080p.WEB-DL.DDP5.1.H.264-GRP'.$i;

                $userQueries[] = $odd
                    ? match ($i % 5) {
                        0 => '(harry | potter) -"deathly hallows" !goblet *wand*',
                        1 => ' * ',
                        2 => '"unterminated phrase with many words here and there',
                        3 => str_repeat('-"a b" ', 12),
                        default => "caf\u{e9} na\u{ef}ve | x\"y z\" --x !!",
                    }
                : 'the matrix reloaded 2003 1080p -cam !ts "blu ray"';

                $hosts[] = $odd
                    ? match ($i % 5) {
                        0 => ' https://elastic:s3cret@es-'.$i.'.prod.example.com:9243/prefix ',
                        1 => '',
                        2 => 'http://[2001:db8::'.$i.']:9200',
                        3 => 'ftp://bad host:99999',
                        default => 'HTTP://[::1',
                    }
                : 'http://es-node-'.($i % 16).'.cluster.local:'.(9200 + $i % 4);

                $bodies[] = $odd
                    ? match ($i % 5) {
                        0 => '{"took":3,"errors":true,"items":['.implode(',', array_map(static fn (int $k): string => '{"index":{"_index":"releases","_id":"'.$k.'","status":'.($k % 3 === 0 ? '400,"error":{"type":"mapper_parsing_exception","reason":"failed"}' : '201').'}}', range(1, 20))).']}',
                        1 => '',
                        2 => '{"hits":{"total":1234567890123456789,"hits":[]}}',
                        3 => "{\"hits\":{\"total\":\n  {\"relation\": \"gte\",\n \"value\": 10000}, \"hits\": []}}",
                        default => '{"acknowledged":true}',
                    }
                : '{"took":5,"timed_out":false,"_shards":{"total":1,"successful":1,"skipped":0,"failed":0},"hits":{"total":{"value":'.(100 + $i).',"relation":"eq"},"max_score":1.0,"hits":['
                    .implode(',', array_map(static fn (int $k): string => '{"_index":"releases","_id":"'.(1000 * $i + $k).'","_score":1.0}', range(1, 8))).']}}';
            }

            $workloads[SearchPage::class][$name] = static fn (bool $rust) => $rust ? SearchPage::idListPlanBatchNative($ids) : SearchPage::idListPlanBatchPhp($ids);
            $workloads[ElasticSearchDriver::class][$name] = static fn (bool $rust) => $rust ? ElasticSearchDriver::queryStringPlanBatchNative($queries) : ElasticSearchDriver::queryStringPlanBatchPhp($queries);
            $workloads[ManticoreSearchDriver::class][$name] = static fn (bool $rust) => $rust ? ManticoreSearchDriver::userQueryPlanBatchNative($userQueries) : ManticoreSearchDriver::userQueryPlanBatchPhp($userQueries);
            $workloads[ElasticsearchClientFactory::class][$name] = static fn (bool $rust) => $rust ? ElasticsearchClientFactory::hostUriPlanBatchNative($hosts) : ElasticsearchClientFactory::hostUriPlanBatchPhp($hosts);
            $workloads[ElasticsearchResponseHelper::class][$name] = static fn (bool $rust) => $rust ? ElasticsearchResponseHelper::hitPlanBatchNative($bodies) : ElasticsearchResponseHelper::hitPlanBatchPhp($bodies);
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
                for ($s = 0; $s < self::BENCH_SAMPLES; $s++) {
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
                "\n[Wave 56.5 benchmark] %s: common mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx | uncommon mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx",
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
            $this->assertSame(self::DEFAULTS[$class], $class::usesW56RustFfi(), $class);
            $this->assertSame(min(self::QUALIFICATION[$class]) >= 1.5, $class::usesW56RustFfi(), $class);
            if ($class::usesW56RustFfi()) {
                $this->assertGreaterThanOrEqual(1.5, $floor, \sprintf('%s: a Rust default must clear the 1.5x gate (min ratio %.2fx)', $class, $floor));
            } else {
                $this->assertFalse($class::usesW56RustFfi(), "{$class} retains PHP by default");
            }
        }
    }

    /**
     * Asserts that the PHP and native variants (and both toggled entry points) agree, and equal $expected
     * unless it is null.
     *
     * @param  class-string  $class
     * @param  list<string>  $records
     * @param  list<int>|null  $expected
     */
    private function assertBoth(string $class, array $records, ?array $expected): void
    {
        $method = self::METHODS[$class];
        $php = $class::{$method.'Php'}($records);
        if ($expected !== null) {
            $this->assertSame($expected, $php, "{$class} PHP");
        }
        $this->assertSame($php, $class::{$method.'Native'}($records), "{$class} native ".var_export($records, true));
        $this->assertSame($php, $class::withW56RustFfi(true, static fn () => $class::{$method}($records)), "{$class} Rust route");
        $this->assertSame($php, $class::withW56RustFfi(false, static fn () => $class::{$method}($records)), "{$class} PHP route");
    }

    /**
     * Three representative records per class.
     *
     * @param  class-string  $class
     * @return list<string>
     */
    private static function firstRecords(string $class): array
    {
        return match ($class) {
            SearchPage::class => ['1,2,3,2', ' 7 , x', ''],
            ElasticSearchDriver::class => ['The.Matrix.1999', "a  b\tc", ''],
            ManticoreSearchDriver::class => ['harry -potter "a b"', '| *x*', ''],
            ElasticsearchClientFactory::class => ['https://u:p@es:9200/x', 'localhost', ''],
            ElasticsearchResponseHelper::class => ['{"hits":{"total":{"value":2},"hits":[{"_id":"1"},{"_id":"2"}]}}', '{"errors":true,"items":[{"error":{}}]}', ''],
            default => throw new RuntimeException($class),
        };
    }

    /**
     * The C entry point of $class, called by name through the FFI handle.
     *
     * @param  class-string  $class
     */
    private static function abiFunction(string $class): string
    {
        return self::FUNCTIONS[$class];
    }

    /**
     * Invokes the class's private `w56RustFfiState()`.
     *
     * @param  class-string  $class
     */
    private static function state(string $class, ?string $op, mixed $val): mixed
    {
        return (new ReflectionMethod($class, 'w56RustFfiState'))->invoke(null, $op, $val);
    }

    private static function randomBytes(int $maxLength, bool $withNul = false): string
    {
        $value = '';
        for ($i = 0, $length = mt_rand(0, $maxLength); $i < $length; $i++) {
            $byte = self::BYTES[mt_rand(0, \count(self::BYTES) - 1)];
            $value .= $byte === "\0" && ! $withNul ? ' ' : $byte;
        }

        return $value;
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
        $cdef = (new ReflectionClassConstant($class, 'W56_FFI_CDEF'))->getValue();
        $ffi = RustPipelineLibrary::resolveDomain('w56_5', $cdef, \is_string($path) && $path !== '' ? $path : null);
        if ($ffi === null) {
            $this->markTestSkipped('Rust W56.5 FFI library is not built or available.');
        }

        return $ffi;
    }
}
