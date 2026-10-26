<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Api\ApiInputCanonicalizer;
use App\Services\Api\ApiQueryParameters;
use App\Services\Api\V1\ReleaseItemPayloadBuilder;
use App\Services\Api\V2\ApiV2Presenter;
use App\Services\LogViewer\Index\LogIndexQuery;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * Paired randomized benchmark (`test_paired_randomized_benchmark_gates_rust_default`, run with
 * `NNTMUX_W605_BENCH=1`): per helper, 200 interleaved samples per workload after 20 warmup rounds,
 * per-sample random PHP/Rust order, each side the fastest of three back-to-back calls, release dylib.
 * Common workloads hold 320 records and uncommon ones 384 records with edge cases. Timings include
 * PHP-side packing, the FFI call and output decoding; each run prints its ratios to STDERR.
 *
 * A helper only defaults to Rust when every recorded common and uncommon mean and p99 ratio reaches 1.5x
 * (QUALIFICATION); each benchmark run asserts that the Rust defaults still clear 1.5x.
 */
class RustApiCanonicalizerQueryParamsV1BuilderV2PresenterLogQueryW60ParityTest extends TestCase
{
    /**
     * Batch method per class; each has `<method>Php` and `<method>Native` variants.
     *
     * @var array<class-string, string>
     */
    private const METHODS = [
        ApiInputCanonicalizer::class => 'inputPlanBatch',
        ApiQueryParameters::class => 'queryPlanBatch',
        ReleaseItemPayloadBuilder::class => 'payloadPlanBatch',
        ApiV2Presenter::class => 'presenterPlanBatch',
        LogIndexQuery::class => 'queryPlanBatch',
    ];

    /**
     * Private C declaration constant and entry point per class.
     *
     * @var array<class-string, array{string, string}>
     */
    private const FUNCTIONS = [
        ApiInputCanonicalizer::class => ['W60_INPUT_PLAN_FFI_CDEF', 'nntmux_w605_api_input_plan'],
        ApiQueryParameters::class => ['W60_QUERY_PLAN_FFI_CDEF', 'nntmux_w605_api_query_plan'],
        ReleaseItemPayloadBuilder::class => ['W60_PAYLOAD_PLAN_FFI_CDEF', 'nntmux_w605_v1_payload_plan'],
        ApiV2Presenter::class => ['W60_PRESENTER_PLAN_FFI_CDEF', 'nntmux_w605_v2_presenter_plan'],
        LogIndexQuery::class => ['W60_LOG_QUERY_PLAN_FFI_CDEF', 'nntmux_w605_log_query_plan'],
    ];

    /**
     * Output words per record.
     *
     * @var array<class-string, int>
     */
    private const WIDTHS = [
        ApiInputCanonicalizer::class => 8,
        ApiQueryParameters::class => 8,
        ReleaseItemPayloadBuilder::class => 9,
        ApiV2Presenter::class => 8,
        LogIndexQuery::class => 9,
    ];

    /**
     * Benchmark-qualified defaults (see the class docblock for the gate).
     *
     * @var array<class-string, bool>
     */
    private const DEFAULTS = [
        ApiInputCanonicalizer::class => true,
        ApiQueryParameters::class => true,
        ReleaseItemPayloadBuilder::class => true,
        ApiV2Presenter::class => true,
        LogIndexQuery::class => true,
    ];

    /**
     * Common mean/p99 then uncommon mean/p99 speedups (PHP / Rust), the per-metric minimum of three
     * `NNTMUX_W605_BENCH=1` runs (200 samples per workload, seed 6052, release Rust, PHP CLI without
     * opcache, Apple Silicon macOS on a shared host).
     *
     * @var array<class-string, array{float, float, float, float}>
     */
    private const QUALIFICATION = [
        ApiInputCanonicalizer::class => [5.93, 4.50, 6.99, 4.94],
        ApiQueryParameters::class => [9.00, 6.36, 8.48, 5.57],
        ReleaseItemPayloadBuilder::class => [3.68, 2.25, 4.70, 4.89],
        ApiV2Presenter::class => [9.67, 5.75, 8.14, 5.84],
        LogIndexQuery::class => [5.53, 3.60, 5.79, 3.47],
    ];

    /** Back-to-back calls per side of a sample; the fastest is kept (drops preemption spikes). */
    private const REPEATS = 3;

    private const RANDOM_ROUNDS = 400;

    private const BENCH_SAMPLES = 200;

    private const BYTES = ['a', 'Z', 'f', 'S', 'e', 't', '0', '1', '9', ' ', "\t", "\n", "\r", "\x0b", "\x0c", "\x01", "\x7f", '-', '+', '_', '.', ',', ':', '/', 'T', "\xc3\xa9", "\xc3", "\xff", "\0"];

    /** Fragments glued into otherwise structured records so separators and keywords actually occur. */
    private const FRAGMENTS = ["\t", "\t\t", ',', ',,', ' ', '-', '+', ':', 'T', '0', '1', '9', '1024', '86400', '100001', '999999999999999999', '1234567890123456789', 'tt', 'S', 'E', 'search', 'info', 'error', 'true', 'off', '2024-02-29', ' 12:00:00', '/', "\x01", "\xc3\xa9"];

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
        foreach (['usesW60RustFfi', 'withW60RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
            $this->assertTrue($reflection->hasMethod($name), "{$class}::{$name}");
            $this->assertTrue($reflection->getMethod($name)->isStatic() && $reflection->getMethod($name)->isPublic(), "{$class}::{$name}");
        }
        $this->assertTrue($reflection->getMethod('w60RustFfiState')->isPrivate(), "{$class} keeps its W60 state private");
        $this->assertTrue((new ReflectionClassConstant($class, self::FUNCTIONS[$class][0]))->isPrivate(), "{$class} keeps its W60 declaration private");
        $constant = new ReflectionClassConstant($class, 'W60_RUST_FFI_DEFAULT');
        $this->assertTrue($constant->isPublic(), "{$class} default must be public");
        $this->assertSame(self::DEFAULTS[$class], $constant->getValue(), "{$class} default must match the benchmark evidence");
        $this->assertSame(min(self::QUALIFICATION[$class]) >= 1.5, self::DEFAULTS[$class], "{$class} default must follow its recorded qualification");
        $this->assertSame(self::DEFAULTS[$class], $class::usesW60RustFfi(), "{$class} routes by its default (PHP when it did not qualify)");

        foreach ([true, false] as $enabled) {
            $this->assertSame([$enabled, 'ok'], $class::withW60RustFfi($enabled, static fn () => [$class::usesW60RustFfi(), 'ok']));
            $this->assertSame(self::DEFAULTS[$class], $class::usesW60RustFfi(), "{$class} must restore after the callback");
        }
        $nested = $class::withW60RustFfi(false, static fn () => [$class::withW60RustFfi(true, static fn () => $class::usesW60RustFfi()), $class::usesW60RustFfi()]);
        $this->assertSame([true, false], $nested);
        $caught = null;
        try {
            $class::withW60RustFfi(! $class::usesW60RustFfi(), static fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException $exception) {
            $caught = $exception->getMessage();
        }
        $this->assertSame('boom', $caught, 'The callback exception must propagate.');
        $this->assertSame(self::DEFAULTS[$class], $class::usesW60RustFfi(), "{$class} must restore after an exception");

        $class::withW60RustFfi(! $class::usesW60RustFfi(), function () use ($class): void {
            foreach (array_keys(self::METHODS) as $other) {
                if ($other !== $class) {
                    $this->assertSame(self::DEFAULTS[$other], $other::usesW60RustFfi(), "{$class} leaked into {$other}");
                }
            }
        });
    }

    public function test_w60_state_adds_no_static_properties(): void
    {
        foreach (array_keys(self::METHODS) as $class) {
            foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_STATIC) as $property) {
                $this->assertStringNotContainsStringIgnoringCase('w60', $property->getName(), $class);
            }
        }
    }

    public function test_earlier_wave_toggles_are_untouched(): void
    {
        $snapshot = static fn (): array => [
            ApiInputCanonicalizer::usesRustFfi(),
            ApiInputCanonicalizer::usesW455RustFfi(),
            ApiQueryParameters::usesRustFfi(),
            ApiQueryParameters::usesW455RustFfi(),
            ReleaseItemPayloadBuilder::usesRustFfi(),
            ReleaseItemPayloadBuilder::usesW465RustFfi(),
            ApiV2Presenter::usesW465RustFfi(),
            LogIndexQuery::usesW445RustFfi(),
        ];
        $before = $snapshot();
        $this->assertSame([true, true, false, true, true, true, true, true], $before);
        foreach (array_keys(self::METHODS) as $class) {
            foreach ([true, false] as $enabled) {
                $this->assertSame($before, $class::withW60RustFfi($enabled, $snapshot), $class);
            }
        }
        $this->assertSame($before, $snapshot());
    }

    public function test_existing_public_api_is_preserved(): void
    {
        foreach ([
            ApiInputCanonicalizer::class => ['canonicalize', 'canonicalizePhp', 'isListParameter', 'canonicalizeListCsv', 'canonicalizeListCsvNative', 'canonicalizeListCsvPhp', 'setUseRustFfi', 'usesRustFfi', 'useW455RustFfi', 'usesW455RustFfi', 'resetW455RustFfi', 'summarizeListCsvBatch', 'summarizeListCsvBatchPhp', 'summarizeListCsvBatchNative', 'evaluateW455Native', 'scalarStrings'],
            ApiQueryParameters::class => ['setUseRustFfi', 'usesRustFfi', 'ffi', 'categories', 'categoriesNative', 'categoriesPhp', 'group', 'limit', 'maxLimit', 'defaultLimit', 'offset', 'minimumSize', 'maximumAge', 'sort', 'sortNative', 'sortPhp', 'hasValidSort', 'hasValidSortNative', 'hasValidSortPhp', 'useW455RustFfi', 'usesW455RustFfi', 'resetW455RustFfi', 'summarizeSortBatch', 'summarizeSortBatchPhp', 'summarizeSortBatchNative'],
            ReleaseItemPayloadBuilder::class => ['setUseRustFfi', 'usesRustFfi', 'rfc2822FromUnixUtc', 'rfc2822FromUnixUtcNative', 'rfc2822FromUnixUtcPhp', 'useW465RustFfi', 'usesW465RustFfi', 'resetW465RustFfi', 'rfc2822FromUnixUtcBatch', 'rfc2822FromUnixUtcBatchPhp', 'rfc2822FromUnixUtcBatchNative', 'rssDates', 'rssDatesNative', 'rssDatesPhp', 'build', 'buildPhp'],
            ApiV2Presenter::class => ['setUseRustFfi', 'usesRustFfi', 'json', 'search', 'details', 'usage', 'usageNative', 'usagePhp', 'formatRelease', 'formatReleaseNative', 'formatReleasePhp', 'formatRssDates', 'formatRssDatesNative', 'formatRssDatesPhp', 'useW465RustFfi', 'usesW465RustFfi', 'resetW465RustFfi', 'formatRssDateBatch', 'formatRssDateBatchPhp', 'formatRssDateBatchNative'],
            LogIndexQuery::class => ['supports', 'matchExpression', 'matchExpressionNative', 'useW445RustFfi', 'usesW445RustFfi', 'resetW445RustFfi', 'summarizeMatchExpressionBatch', 'summarizeMatchExpressionBatchPhp', 'summarizeMatchExpressionBatchNative', 'clauses'],
        ] as $class => $methods) {
            $reflection = new ReflectionClass($class);
            foreach ($methods as $name) {
                $this->assertTrue($reflection->hasMethod($name) && $reflection->getMethod($name)->isPublic(), "{$class}::{$name}");
            }
        }
        $this->assertSame(['cat'], ApiInputCanonicalizer::LIST_PARAMETERS);
        $this->assertSame(3, LogIndexQuery::MIN_INFIX_LENGTH);

        $canonicalizer = new ApiInputCanonicalizer(false);
        $request = Request::create('/api', 'GET', ['sort' => ' Name_ASC ']);
        $parameters = new ApiQueryParameters;
        $builder = new ReleaseItemPayloadBuilder(['token' => 'tok', 'del' => '1', 'extended' => '1'], ['server' => ['url' => 'https://idx']], 'newznab', false);
        $release = ['guid' => str_repeat('a', 40), 'searchname' => 'Some.Name', 'adddate' => '2024-01-02 03:04:05', 'postdate' => '2024-01-01 00:00:00', 'size' => 2048, 'categories_id' => 5030, 'category_name' => 'TV > HD', 'grabs' => 3, 'comments' => 0, 'passwordstatus' => 0, 'totalpart' => 7];
        $expected = [
            $canonicalizer->canonicalizePhp(['cat' => ' 2000, ,5030', 't' => ' search ', 'empty' => ' ']),
            $parameters->sortPhp($request),
            $parameters->hasValidSortPhp($request),
            $builder->buildPhp($release),
            ApiV2Presenter::formatRssDatesPhp('2024-01-02 03:04:05'),
            LogIndexQuery::matchExpression('Jobs\ReindexRel'),
        ];
        $this->assertSame(['cat' => '2000,5030', 'empty' => null, 't' => 'search'], $expected[0]);
        $this->assertSame(['name_asc', true, '"*jobs reindexrel*"'], [$expected[1], $expected[2], $expected[5]]);
        $this->assertSame('https://idx/getnzb?id='.str_repeat('a', 40).'.nzb&r=tok&del=1', $expected[3]['link']);
        foreach (array_keys(self::METHODS) as $class) {
            foreach ([true, false] as $enabled) {
                $this->assertSame($expected, $class::withW60RustFfi($enabled, static fn () => [
                    $canonicalizer->canonicalizePhp(['cat' => ' 2000, ,5030', 't' => ' search ', 'empty' => ' ']),
                    $parameters->sortPhp($request),
                    $parameters->hasValidSortPhp($request),
                    $builder->buildPhp($release),
                    ApiV2Presenter::formatRssDatesPhp('2024-01-02 03:04:05'),
                    LogIndexQuery::matchExpression('Jobs\ReindexRel'),
                ]), $class);
            }
        }
    }

    public function test_payload_plan_lengths_follow_build(): void
    {
        $guid = str_repeat('b', 40);
        foreach ([['tok', '1', true], ['', '0', false], ['secret-token', '', false]] as [$token, $del, $withDel]) {
            $builder = new ReleaseItemPayloadBuilder(['token' => $token, 'del' => $del, 'extended' => '1'], ['server' => ['url' => 'https://idx.example']], 'newznab', false);
            $payload = $builder->buildPhp(['guid' => $guid, 'searchname' => 'x', 'adddate' => '2024-01-01', 'size' => 1, 'categories_id' => 5030, 'nfostatus' => 1]);
            $words = ReleaseItemPayloadBuilder::payloadPlanBatchPhp(["https://idx.example\t{$token}\t{$guid}\t1\t0\t5030\t\t1\t\t1\t{$del}"]);
            $this->assertSame([\strlen($payload['link']), \strlen($payload['guid'])], [$words[0], $words[1]], $token);
            $this->assertSame($withDel, str_ends_with($payload['link'], '&del=1'));
            $this->assertSame(\count($payload['attr']), $words[6] + 1, 'build() also emits usenetdate for an extended item');
        }
    }

    public function test_presenter_plan_timestamps_match_gmmktime(): void
    {
        mt_srand(6053);
        for ($i = 0; $i < 300; $i++) {
            [$y, $m, $d, $h, $mi, $s] = [mt_rand(1000, 9999), mt_rand(1, 12), mt_rand(1, 28), mt_rand(0, 23), mt_rand(0, 59), mt_rand(0, 59)];
            $text = \sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y, $m, $d, $h, $mi, $s);
            $words = ApiV2Presenter::presenterPlanBatchPhp(["1\t1\t{$text}\t\t5030\t"]);
            $this->assertSame(gmmktime($h, $mi, $s, $m, $d, $y), $words[3], $text);
        }
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
        $this->assertCount(self::WIDTHS[$class] * \count($records), $php);
        $this->assertSame($php, $class::withW60RustFfi(false, static fn () => $class::{$method}($records)), $class);
        $this->assertSame($php, $class::withW60RustFfi(true, static fn () => $class::{$method}($records)), $class);
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
        // One empty record still yields a full row.
        $this->assertSame($class::{$method.'Php'}(['']), $class::{$method.'Native'}(['']));
        $this->assertCount(self::WIDTHS[$class], $class::{$method}(['']));
    }

    public function test_input_plan_examples(): void
    {
        $this->requireFfi(ApiInputCanonicalizer::class);
        $this->assertBoth(ApiInputCanonicalizer::class, [
            "TVSearch\t5030, ,5040\t".str_repeat('a', 40)."\t12\ttt0111161\t0\tx\tS02\tE05",
            '',
            "bogus\t,\t\t\t\t\t\t\t3",
            " m \tx,2000\t".str_repeat('F', 39)."g\t\tTT12345678\t99\t1234567890\t2024\te1",
        ], [
            2, 2, 5030, 1, 3, 2, 5, 24,
            0, 0, 0, 0, 0, -1, -1, 257,
            0, 0, 0, 0, 0, -1, 3, 138,
            3, 2, -1, 2, 14, 2024, 1, 4,
        ]);
    }

    public function test_query_plan_examples(): void
    {
        $this->requireFfi(ApiQueryParameters::class);
        $this->assertBoth(ApiQueryParameters::class, [
            "9999\t-5\t40000\t10\t5\t1\t0\t\t",
            '',
            "x\tx\tx\tx\t\t+1\t1\t0\t900",
            " 100 \t 20 \t-7\t0\t1\t2\t01\tbad\t-3",
        ], [
            500, 0, 36500, 10, 5, 1, 0, 149,
            250, 0, -1, 0, 0, 0, 0, 512,
            1, 0, -1, 0, 0, 1, 1, 106,
            100, 20, -1, 0, 1, 0, 1, 256,
        ]);
    }

    public function test_payload_plan_examples(): void
    {
        $this->requireFfi(ReleaseItemPayloadBuilder::class);
        $this->assertBoth(ReleaseItemPayloadBuilder::class, [
            "https://idx\ttok\t".str_repeat('f', 40)."\t2048\t3\t5030\tc.jpg\t1\t\t1\t1",
            '',
            "http://a/\t\tnot-hex\tx\tx\tx\t \t1\t0\t0\t0\textra",
        ], [
            78, 60, 2048, 3, 5030, 5000, 8, 1, 0,
            18, 9, 0, 0, 0, 0, 2, 1, 213,
            34, 25, 0, 0, 0, 0, 2, 0, 254,
        ]);
    }

    public function test_presenter_plan_examples(): void
    {
        $this->requireFfi(ApiV2Presenter::class);
        $this->assertBoth(ApiV2Presenter::class, [
            "7\t1073741824\t2024-02-29 12:00:00\t\t5040\t1709208000",
            "x\t\t2023-02-29\t1970-01-01T00:00:01\tx\t",
            "9\t5\t2000-02-29\t2000-03-01 00:00:00\t7020\t951868800\textra",
            "1\t1125899906842624\t1999-12-31 23:59:60\t2024-01-01 24:00:00\t0\t",
        ], [
            7, 1073741824, 3, 1709208000, 1709208000, 0, 5000, 16,
            0, 0, 0, 0, 1, 0, 0, 103,
            9, 5, 0, 951782400, 951868800, 0, 7000, 128,
            1, 1125899906842624, 4, 0, 0, 0, 0, 44,
        ]);
    }

    public function test_log_query_plan_examples(): void
    {
        $this->requireFfi(LogIndexQuery::class);
        $this->assertBoth(LogIndexQuery::class, [
            "Jobs\\Reindex\tError, info ,bogus\t200000\t100000\t\t\t",
            '',
            "--\t\t86400\t\tmaybe\t200000\t0",
            "a.*b\t\t\t\tYES\tx\tx\textra",
            " \xc3\xa9 \tDEBUG,EMERGENCY,,\t\t1700000000\toff\t7\t500",
        ], [
            1, 18, 100000, 200000, 0, 50, 1, 2, 5,
            0, 0, 0, 0, 0, 50, 1, 0, 256,
            3, 0, 86400, 0, 100000, 1, 2, 0, 176,
            2, 0, 0, 0, 0, 50, 1, 0, 328,
            1, 129, 0, 1700000000, 7, 500, 1, 0, 0,
        ]);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_every_helper_is_served_by_rust(string $class, string $method): void
    {
        $this->assertInstanceOf(FFI::class, $this->requireFfi($class));
        $records = self::firstRecords($class);
        $this->assertCount(self::WIDTHS[$class] * \count($records), $class::{$method.'Native'}($records));
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
        $this->assertSame(3000 * self::WIDTHS[$class], $handle[2]);
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
        $this->assertSame($class::{$method.'Php'}($records), $class::withW60RustFfi(true, static fn () => $class::{$method}($records)), $class);
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
        $parts = self::firstRecords($class);
        $data = implode("\0", $parts);
        $count = \count($parts);
        $needed = 3 * self::WIDTHS[$class];
        $out = $ffi->new('int64_t[32]');
        $small = $ffi->new('int64_t[1]');
        $small[0] = 7;
        $call = static fn (int $count, int $flags, string $data, string $ints, mixed $out, int $cap): int => $ffi->{$function}($count, $flags, $data, \strlen($data), $ints, \strlen($ints), $out, $cap);

        $this->assertSame($needed, $call($count, 0, $data, '', $out, 32), $function);
        $this->assertSame(-$needed, $call($count, 0, $data, '', $small, 1), $function);
        $this->assertSame(7, $small[0], "{$function} must not write on a capacity error");
        $this->assertSame(-$needed, $call($count, 0, $data, '', null, 0), $function);
        $this->assertSame(-1, $call($count, 0, $data, '', null, 4), $function);
        $this->assertSame(-1, $call($count, 1, $data, '', $out, 32), "{$function} flags");
        $this->assertSame(-1, $call($count + 1, 0, $data, '', $out, 32), "{$function} record count");
        $this->assertSame(-1, $call($count - 1, 0, $data, '', $out, 32), "{$function} record count");
        $this->assertSame(-1, $call(0, 0, $data, '', $out, 32), "{$function} record count");
        $this->assertSame(-1, $call($count, 0, $data, pack('P', 1), $out, 32), "{$function} int table");
        $this->assertSame(0, $call(0, 0, '', '', null, 0), "{$function} empty batch");
    }

    /**
     * Over 400 rounds of 1-16 records each (thousands of structured, fragment-spliced and raw-byte inputs
     * per helper), every class's Rust and PHP plans agree bit for bit; every 50th round also carries NUL
     * bytes, which must decline to PHP.
     *
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_randomized_parity(string $class, string $method): void
    {
        $this->assertNotSame('', $method);
        $this->requireFfi($class);
        mt_srand(6051 + crc32($class) % 1000);
        $checked = 0;
        for ($round = 0; $round < self::RANDOM_ROUNDS; $round++) {
            $withNul = $round % 50 === 49;
            $records = [];
            for ($i = 0, $count = mt_rand(1, 16); $i < $count; $i++) {
                $records[] = match (mt_rand(0, 5)) {
                    0 => self::randomBytes(24, $withNul),
                    1 => self::spliced(self::structuredRecord($class), $withNul),
                    default => self::structuredRecord($class),
                };
            }
            if ($withNul) {
                $records[0] .= "\0";
            }
            $this->assertBoth($class, $records, null);
            $checked += \count($records);
        }
        $this->assertGreaterThanOrEqual(300, $checked);
    }

    public function test_paired_randomized_benchmark_gates_rust_default(): void
    {
        if (getenv('NNTMUX_W605_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_W605_BENCH=1 to run the Wave 60.5 paired benchmark.');
        }
        $this->requireFfi(ApiInputCanonicalizer::class);
        mt_srand(6052);
        $workloads = [];
        $floors = [];
        $guid = static fn (int $i): string => substr(sha1((string) $i), 0, 40);

        foreach (['common' => 320, 'uncommon' => 384] as $name => $count) {
            $odd = $name === 'uncommon';
            $inputs = $queries = $payloads = $presenters = $logs = [];
            for ($i = 0; $i < $count; $i++) {
                $inputs[] = $odd
                    ? match ($i % 5) {
                        0 => "  TVSEARCH \t 5030 , ,x,1234567,,\t".strtoupper($guid($i))."\t-1\ttT0\t0\t12345678901\tS12345\tE",
                        1 => '',
                        2 => "bogus{$i}\t,,,\tnot-a-guid\t\t\t\t\t\t3\textra",
                        3 => "Details\t".implode(',', range(1000, 1000 + $i % 40))."\t{$guid($i)}g\ttt\tt1\tx\t-\ts\te",
                        default => "M\t2000\t \t{$i}\ttt".str_pad((string) $i, 8, '0', STR_PAD_LEFT)."\t{$i}\t{$i}\t2024\te".($i % 99),
                    }
                : ['search', 'tvsearch', 'movie', 'caps', 'get'][$i % 5]."\t".['5030,5040', '2000', '', '5000,5010,5020,5030'][$i % 4]."\t".($i % 3 === 0 ? $guid($i) : '')."\t".($i % 2 === 0 ? (string) (1000 + $i) : '')."\t".($i % 3 === 1 ? 'tt'.str_pad((string) (100000 + $i), 7, '0', STR_PAD_LEFT) : '')."\t".($i % 4 === 0 ? (string) (500 + $i) : '')."\t\t".($i % 2 === 0 ? (string) ($i % 20 + 1) : '')."\t".($i % 2 === 0 ? (string) ($i % 24 + 1) : '');

                $queries[] = $odd
                    ? match ($i % 5) {
                        0 => " 99999 \t-{$i}\t40000\t".($i * 1000)."\t5\t+1\t01\t\t",
                        1 => '',
                        2 => "1.5\tx\tnever\t-\t+\t2\ttrue\tbad\t-3\textra",
                        3 => "-{$i}\t999999999999999999\t-7\t-1\t-1\t-0\t1\t0\t0",
                        default => "1234567890123456789\t 7 \t36501\t{$i}\t".($i * 10)."\t1\t1\t1000\t2000",
                    }
                : ['', '50', '100', '250', '500'][$i % 5]."\t".($i * 25)."\t".['', '7', '30', '365'][$i % 4]."\t".($i % 3 === 0 ? '0' : '')."\t".($i % 4 === 0 ? (string) (1073741824 * ($i % 5 + 1)) : '')."\t".($i % 2)."\t0\t500\t250";

                $payloads[] = $odd
                    ? match ($i % 5) {
                        0 => "https://idx.example/\t\t".strtoupper($guid($i))."\t x \t-3\t70a0\t \t2\t2\t+1\t01",
                        1 => '',
                        2 => "\ttok\t\t999999999999999999\t999999999999999999\t1234567890\tc.jpg\t1\t0\t1\t1\textra",
                        3 => str_repeat('https://very.long.indexer.example/path/', 3)."\t".str_repeat('t', 64)."\t{$guid($i)}x\t{$i}\t\t{$i}\t\t\t-1\tyes\t1",
                        default => "http://a\tk\t{$guid($i)}\t1.5\t1e3\t5030 \t{$i}.jpg\t 1 \t\t1\t\t",
                    }
                : "https://indexer.example.com\t".md5((string) $i)."\t{$guid($i)}\t".(1048576 * ($i + 1))."\t".($i % 50)."\t".[5030, 5040, 2040, 7020][$i % 4]."\t".($i % 3 === 0 ? "{$i}.jpg" : '')."\t".($i % 2)."\t\t".($i % 2)."\t0";

                $post = 1_700_000_000 + $i * 3600;
                $presenters[] = $odd
                    ? match ($i % 5) {
                        0 => "{$i}\t1125899906842624\t1999-12-31 23:59:60\t2024-01-01 24:00:00\t0\t",
                        1 => '',
                        2 => "x\t-1\t2023-02-29\t1900-02-29T00:00:00\tx\tx\textra",
                        3 => ($i + 1)."\t999999999999999999\t9999-12-31 23:59:59\t1000-01-01\t999999999\t999999999999",
                        default => ($i + 1)."\t 2048 \t 2024-0{$i}-01 \t2024-02-30\t5030\t".($post + 99),
                    }
                : (100000 + $i)."\t".(1048576 * ($i + 1))."\t".gmdate('Y-m-d H:i:s', $post + 600)."\t".($i % 4 === 0 ? '' : gmdate('Y-m-d H:i:s', $post))."\t".[5030, 5040, 2040, 7020][$i % 4]."\t".($post + 86400);

                $logs[] = $odd
                    ? match ($i % 5) {
                        0 => " --- \tbogus,WARN,Error,,\t".($post + 86400 * 9)."\t{$post}\tmaybe\t200000\t0",
                        1 => '',
                        2 => "^\\[\\d+\\] .*error\t\t\t\tYES\tx\t9999\textra",
                        3 => " \xc3\xa9t\xc3\xa9 \tDEBUG,INFO,NOTICE,WARNING,ERROR,CRITICAL,ALERT,EMERGENCY\t{$post}\t\toff\t {$i} \t 501 ",
                        default => "...\t ,\t1234567890123\t-1\t2\t100001\t-",
                    }
                : ['Jobs\\ReindexRel', '', 'ProcessReleases', 'error', 'NNTP connection'][$i % 5]."\t".['', 'error', 'error,warning', 'info'][$i % 4]."\t".($i % 3 === 0 ? (string) ($post - 86400 * ($i % 7)) : '')."\t".($i % 2 === 0 ? (string) $post : '')."\t".($i % 9 === 0 ? '1' : '')."\t".(($i % 4) * 50)."\t".['', '50', '100'][$i % 3];
            }

            $workloads[ApiInputCanonicalizer::class][$name] = static fn (bool $rust) => $rust ? ApiInputCanonicalizer::inputPlanBatchNative($inputs) : ApiInputCanonicalizer::inputPlanBatchPhp($inputs);
            $workloads[ApiQueryParameters::class][$name] = static fn (bool $rust) => $rust ? ApiQueryParameters::queryPlanBatchNative($queries) : ApiQueryParameters::queryPlanBatchPhp($queries);
            $workloads[ReleaseItemPayloadBuilder::class][$name] = static fn (bool $rust) => $rust ? ReleaseItemPayloadBuilder::payloadPlanBatchNative($payloads) : ReleaseItemPayloadBuilder::payloadPlanBatchPhp($payloads);
            $workloads[ApiV2Presenter::class][$name] = static fn (bool $rust) => $rust ? ApiV2Presenter::presenterPlanBatchNative($presenters) : ApiV2Presenter::presenterPlanBatchPhp($presenters);
            $workloads[LogIndexQuery::class][$name] = static fn (bool $rust) => $rust ? LogIndexQuery::queryPlanBatchNative($logs) : LogIndexQuery::queryPlanBatchPhp($logs);
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
                "\n[Wave 60.5 benchmark] %s: common mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx | uncommon mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx",
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
            $this->assertSame(self::DEFAULTS[$class], $class::usesW60RustFfi(), $class);
            $this->assertSame(min(self::QUALIFICATION[$class]) >= 1.5, $class::usesW60RustFfi(), $class);
            if ($class::usesW60RustFfi()) {
                $this->assertGreaterThanOrEqual(1.5, $floor, \sprintf('%s: a Rust default must clear the 1.5x gate (min ratio %.2fx)', $class, $floor));
            } else {
                $this->assertFalse($class::usesW60RustFfi(), "{$class} retains PHP by default");
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
        $this->assertSame($php, $class::withW60RustFfi(true, static fn () => $class::{$method}($records)), "{$class} Rust route");
        $this->assertSame($php, $class::withW60RustFfi(false, static fn () => $class::{$method}($records)), "{$class} PHP route");
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
            ApiInputCanonicalizer::class => ["search\t5030,5040\t\t\t\t\t\t1\t2", "bogus\tx\tid\t0", ''],
            ApiQueryParameters::class => ["100\t0\t7\t\t\t1\t0\t500\t250", "x\t-1\tx", ''],
            ReleaseItemPayloadBuilder::class => ["https://idx\ttok\t".str_repeat('a', 40)."\t2048\t1\t5030\t\t0\t\t1\t0", "\t\tbad\tx", ''],
            ApiV2Presenter::class => ["1\t2048\t2024-01-01 00:00:00\t\t5030\t1704067200", "x\t\tbad", ''],
            LogIndexQuery::class => ["error\terror,info\t1700000000\t1700086400\t\t0\t50", "--\tbogus\tx", ''],
            default => throw new RuntimeException($class),
        };
    }

    /**
     * One record shaped like the class's input, built from plausible and adversarial pieces.
     *
     * @param  class-string  $class
     */
    private static function structuredRecord(string $class): string
    {
        $pick = static fn (array $items): string => (string) $items[mt_rand(0, \count($items) - 1)];
        $join = static function (string $separator, int $max, callable $part): string {
            $parts = [];
            for ($i = 0, $n = mt_rand(0, $max); $i < $n; $i++) {
                $parts[] = $part();
            }

            return implode($separator, $parts);
        };
        $number = static fn (): string => $pick(['', '0', '1', '7', '12', '00', '007', '-1', '-0', '-', '+', '+1', '+0', '1.5', ' 5', '5 ', 'x', '300', '500', '501', '1024', '65535', '86400', '99999', '100000', '100001', '36500', '36501', (string) mt_rand(0, 1_000_000_000), (string) mt_rand(-1_000_000, 5_000_000_000), '4102444800', '999999999', '1000000000', '999999999999', '1000000000000', '1234567890123', '999999999999999999', '-999999999999999999', '1234567890123456789', self::randomBytes(3)]);
        $flag = static fn (): string => $pick(['', '0', '1', ' 1 ', '+1', '-1', '2', 'x', '01', 'true', self::randomBytes(2)]);
        $hex = static fn (): string => $pick([sha1((string) mt_rand()), strtoupper(sha1((string) mt_rand())), substr(sha1((string) mt_rand()), 0, 39), substr(sha1((string) mt_rand()), 0, 39).'g', ' '.sha1('x').' ', '', 'not-a-guid', self::randomBytes(6)]);
        $date = static fn (): string => $pick([
            '', '2024-02-29 12:00:00', '2023-02-29', '1970-01-01', '1970-01-01T00:00:01', '1000-01-01 00:00:00', '0999-12-31', '9999-12-31 23:59:59',
            '2024-13-01', '2024-00-10', '2024-01-00', '2024-01-32', '2024-04-31', '2024-01-01 24:00:00', '2024-01-01 23:60:00', '2024-01-01 23:59:60',
            '2024-01-01X00:00:00', '2024/01/01', '2024-1-01', ' 2024-06-15 ', '2000-02-29', '1900-02-29', '2024-01-01 1:00:00', '2024-01-01 00:00:0x', '-024-01-01',
            \sprintf('%04d-%02d-%02d', mt_rand(900, 9999), mt_rand(0, 13), mt_rand(0, 32)),
            \sprintf('%04d-%02d-%02d%s%02d:%02d:%02d', mt_rand(1000, 9999), mt_rand(1, 12), mt_rand(1, 31), $pick([' ', 'T', 't', '_']), mt_rand(0, 24), mt_rand(0, 60), mt_rand(0, 60)),
            self::randomBytes(10),
        ]);
        $cut = static fn (array $fields, int $expected): array => \array_slice($fields, 0, mt_rand(0, 3) === 0 ? mt_rand(0, $expected + 1) : $expected);

        return match ($class) {
            ApiInputCanonicalizer::class => implode("\t", $cut([
                $pick(['', ' ', 'search', 'S', 'TVSearch', 'tv', 'movie', 'M', 'music', 'book', 'caps', 'c', 'details', 'D', 'get', 'g', 'info', 'INFO', ' search ', 'bogus', 'searchx', self::randomBytes(5)]),
                $join(',', 5, static fn (): string => $pick(['2000', '5030', ' 5040 ', '', ' ', 'x', '1234567', '100000', '0', '-1', self::randomBytes(3)])),
                $hex(),
                $number(),
                $pick(['', 'tt0111161', 'TT1234567', 'tt', 't1', '0', 'tt0', '123456789', 'tt12345678', 'tt123456789', 'Tt1', $number()]),
                $number(),
                $number(),
                $pick(['', 'S01', 's1', '1', '2024', 'S', 'S12345', 'x', ' 3 ', 'SS1', $number()]),
                $pick(['', 'E01', 'e1', '1', '9999', 'E', 'E12345', 'x', ' 3 ', 'EE1', $number()]),
                'extra',
            ], 9)),
            ApiQueryParameters::class => implode("\t", $cut([$number(), $number(), $number(), $number(), $number(), $flag(), $flag(), $number(), $number(), 'extra'], 9)),
            ReleaseItemPayloadBuilder::class => implode("\t", $cut([
                $pick(['', 'https://idx.example', 'http://a/', 'https://x.y:8080/nn', '/', self::randomBytes(8)]),
                $pick(['', 'abc123', str_repeat('t', 32), self::randomBytes(5)]),
                $hex(),
                $number(),
                $number(),
                $pick(['5030', '2000', '0', '999999999', '1234567890', 'x', '', ' 7020 ', $number()]),
                $pick(['', 'c.jpg', ' ', self::randomBytes(4)]),
                $flag(), $flag(), $flag(), $flag(),
                'extra',
            ], 11)),
            ApiV2Presenter::class => implode("\t", $cut([
                $number(), $number(), $date(), $date(),
                $pick(['5030', '2000', '0', '999999999', '1234567890', 'x', '', ' 7020 ', $number()]),
                $pick(['', '1700000000', '0', '9999999999999', $number()]),
                'extra',
            ], 6)),
            LogIndexQuery::class => implode("\t", $cut([
                $pick(['', ' ', 'Jobs\\Reindex', '--', '...', "\xc3\xa9", "\xc3", 'a.*b', '_', 'ERROR', '   x  ', '[]', self::randomBytes(8)]),
                $join(',', 4, static fn (): string => $pick(['debug', 'INFO', ' warning ', 'Error', 'critical', 'alert', 'emergency', 'notice', 'bogus', '', 'warn', self::randomBytes(3)])),
                $number(), $number(),
                $pick(['', '1', '0', 'yes', 'NO', 'True', 'off', 'On', 'maybe', ' 1 ', self::randomBytes(2)]),
                $number(), $number(),
                'extra',
            ], 7)),
            default => throw new RuntimeException($class),
        };
    }

    /** Splices random fragments and bytes into $record at random offsets. */
    private static function spliced(string $record, bool $withNul): string
    {
        for ($i = 0, $n = mt_rand(1, 4); $i < $n; $i++) {
            $insert = mt_rand(0, 1) === 1 ? self::FRAGMENTS[mt_rand(0, \count(self::FRAGMENTS) - 1)] : self::randomBytes(4, $withNul);
            $at = mt_rand(0, \strlen($record));
            $record = substr($record, 0, $at).$insert.substr($record, $at);
        }

        return $record;
    }

    /**
     * The C entry point of $class, called by name through the FFI handle.
     *
     * @param  class-string  $class
     */
    private static function abiFunction(string $class): string
    {
        return self::FUNCTIONS[$class][1];
    }

    /**
     * Invokes the class's private `w60RustFfiState()`.
     *
     * @param  class-string  $class
     */
    private static function state(string $class, ?string $op, mixed $val): mixed
    {
        return (new ReflectionMethod($class, 'w60RustFfiState'))->invoke(null, $op, $val);
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
     * Resolves the class's Wave 60.5 FFI handle or skips the test without the library.
     *
     * @param  class-string  $class
     */
    private function requireFfi(string $class): FFI
    {
        if (! \extension_loaded('FFI')) {
            $this->markTestSkipped('FFI extension not loaded.');
        }
        $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $cdef = (new ReflectionClassConstant($class, self::FUNCTIONS[$class][0]))->getValue();
        $ffi = \is_string($cdef) ? RustPipelineLibrary::resolveDomain('w60_5', $cdef, \is_string($path) && $path !== '' ? $path : null) : null;
        if (! $ffi instanceof FFI) {
            $this->markTestSkipped('Rust pipeline library not built.');
        }

        return $ffi;
    }
}
