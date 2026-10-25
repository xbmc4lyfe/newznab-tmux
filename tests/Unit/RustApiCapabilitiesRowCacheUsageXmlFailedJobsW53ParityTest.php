<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Api\ApiCapabilitiesService;
use App\Services\Api\ApiReleaseRowCache;
use App\Services\Api\ApiUsageService;
use App\Services\Api\V1\XmlResponseContext;
use App\Services\Monitoring\Collectors\FailedJobsCollector;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use RuntimeException;

/**
 * Paired randomized benchmark (`test_paired_randomized_benchmark_gates_rust_default`): per helper, 300
 * samples per workload (1000 with `NNTMUX_W53_BENCH=1`), 20 warmup rounds, per-sample random PHP/Rust
 * order, each side the fastest of three back-to-back calls, release dylib. Common workloads hold 64
 * records and uncommon ones 192 records with edge cases. Timings include PHP-side packing, the FFI call
 * and output decoding; each run prints its ratios to STDERR.
 *
 * A helper only defaults to Rust when every recorded common and uncommon mean and p99 ratio reaches 1.5x
 * (QUALIFICATION); each run asserts that the Rust defaults still clear 1.5x.
 */
class RustApiCapabilitiesRowCacheUsageXmlFailedJobsW53ParityTest extends TestCase
{
    /**
     * Batch method, words per record and the index of the record argument per class; each has
     * `<method>Php` and `<method>Native` variants.
     *
     * @var array<class-string, array{string, int, int}>
     */
    private const METHODS = [
        ApiCapabilitiesService::class => ['supportedParamPlanBatch', 4, 1],
        ApiReleaseRowCache::class => ['leafFingerprintBatch', 3, 0],
        ApiUsageService::class => ['auditValuePlanBatch', 3, 0],
        XmlResponseContext::class => ['xmlEscapePlanBatch', 3, 0],
        FailedJobsCollector::class => ['exceptionPlanBatch', 3, 0],
    ];

    /**
     * Benchmark-qualified defaults (see the class docblock for the gate).
     *
     * @var array<class-string, bool>
     */
    private const DEFAULTS = [
        ApiCapabilitiesService::class => true,
        ApiReleaseRowCache::class => true,
        ApiUsageService::class => true,
        XmlResponseContext::class => true,
        FailedJobsCollector::class => true,
    ];

    /**
     * Common mean/p99 then uncommon mean/p99 speedups (PHP / Rust), the per-metric minimum of three
     * `NNTMUX_W53_BENCH=1` runs (1000 samples per workload, seed 5352, release Rust, PHP 8.5.12 CLI without
     * opcache, Apple Silicon macOS on a shared host).
     *
     * @var array<class-string, array{float, float, float, float}>
     */
    private const QUALIFICATION = [
        ApiCapabilitiesService::class => [3.46, 3.54, 4.01, 4.19],
        ApiReleaseRowCache::class => [2.61, 2.77, 2.13, 2.05],
        ApiUsageService::class => [2.63, 2.68, 2.43, 2.39],
        XmlResponseContext::class => [3.48, 3.59, 6.30, 6.08],
        FailedJobsCollector::class => [2.12, 1.91, 2.19, 1.78],
    ];

    /** Back-to-back calls per side of a sample; the fastest is kept (drops preemption spikes). */
    private const REPEATS = 3;

    private const RANDOM_ROUNDS = 400;

    private const BYTES = ['a', 'Z', 'q', '0', '9', ' ', "\t", "\n", "\r", "\x0b", "\x0c", "\x01", "\x1f", "\x7f", '-', '_', '.', '~', ',', ':', '\\', '&', '<', '>', '"', "'", '%', '+', "\xc3\xa9", "\xe2\x82\xac", "\xf0\x9f\x8e\xac", "\xc3", "\xff", "\0"];

    private const FUNCTIONS = ['search', 'tv-search', 'movie-search', 'audio-search', 'book-search', 'anime-search', 'tvsearch', 'Search', '', 'caps', ' search'];

    private const PARAMS = ['q', 'cat', 'limit', 'offset', 'extended', 'attrs', 'del', 'sort', 'imdbid', 'tmdbid', 'tvdbid', 'season', 'ep', 'id', 'cursor', 'genre', 'author', 'title', 'anidbid', 'anilistid', 'apikey', 't', 'o', ' Q ', 'CAT', "\tlimit\n", '', ' ', 'maxsize', 'minsize', 'maxage', 'group', 'unknownparameter', 'q=1', "\xc3\xa9"];

    private const EXCEPTION_HEADS = [
        'Illuminate\Queue\TimeoutExceededException: App\Jobs\ProcessRelease has timed out.',
        'Illuminate\Queue\MaxAttemptsExceededException: App\Jobs\X has been attempted too many times.',
        'Illuminate\Database\Eloquent\ModelNotFoundException: No query results for model [App\Models\Release] 12',
        'RuntimeException: Unable to connect to NNTP server',
        'App\Jobs\ProcessRelease has timed out.',
        'App\Jobs\X has been attempted too many times.',
        'PDOException: SQLSTATE[HY000] [2002] Connection refused',
        "ErrorException\r",
        '9Bad\Name: x',
        ': no class',
        '',
        'Bad Name: spaced',
        "T\xc3\xa9st: utf8",
    ];

    protected function tearDown(): void
    {
        foreach (array_keys(self::METHODS) as $class) {
            self::state($class, 'reset', null);
        }
        mb_internal_encoding('UTF-8');

        parent::tearDown();
    }

    /**
     * @return array<string, array{class-string, string, int, int}>
     */
    public static function helperProvider(): array
    {
        $cases = [];
        foreach (self::METHODS as $class => [$method, $words, $recordArgument]) {
            $cases[(new ReflectionClass($class))->getShortName()] = [$class, $method, $words, $recordArgument];
        }

        return $cases;
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_methods_defaults_and_scoped_toggles(string $class, string $method, int $words, int $recordArgument): void
    {
        $this->assertGreaterThan(0, $words);
        $this->assertGreaterThanOrEqual(0, $recordArgument);
        $reflection = new ReflectionClass($class);
        foreach (['usesW53RustFfi', 'withW53RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
            $this->assertTrue($reflection->hasMethod($name), "{$class}::{$name}");
            $this->assertTrue($reflection->getMethod($name)->isStatic() && $reflection->getMethod($name)->isPublic(), "{$class}::{$name}");
        }
        $this->assertTrue($reflection->getMethod('w53RustFfiState')->isPrivate(), "{$class} keeps its W53 state private");
        $constant = new ReflectionClassConstant($class, 'W53_RUST_FFI_DEFAULT');
        $this->assertTrue($constant->isPrivate(), "{$class} default must be private");
        $this->assertSame(self::DEFAULTS[$class], $constant->getValue(), "{$class} default must match the benchmark evidence");
        $this->assertSame(min(self::QUALIFICATION[$class]) >= 1.5, self::DEFAULTS[$class], "{$class} default must follow its recorded qualification");
        $this->assertSame(self::DEFAULTS[$class], $class::usesW53RustFfi());

        foreach ([true, false] as $enabled) {
            $this->assertSame([$enabled, 'ok'], $class::withW53RustFfi($enabled, static fn () => [$class::usesW53RustFfi(), 'ok']));
            $this->assertSame(self::DEFAULTS[$class], $class::usesW53RustFfi(), "{$class} must restore after the callback");
        }
        $nested = $class::withW53RustFfi(false, static fn () => [$class::withW53RustFfi(true, static fn () => $class::usesW53RustFfi()), $class::usesW53RustFfi()]);
        $this->assertSame([true, false], $nested);
        $caught = null;
        try {
            $class::withW53RustFfi(! $class::usesW53RustFfi(), static fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException $exception) {
            $caught = $exception->getMessage();
        }
        $this->assertSame('boom', $caught, 'The callback exception must propagate.');
        $this->assertSame(self::DEFAULTS[$class], $class::usesW53RustFfi(), "{$class} must restore after an exception");

        $class::withW53RustFfi(! $class::usesW53RustFfi(), function () use ($class): void {
            foreach (array_keys(self::METHODS) as $other) {
                if ($other !== $class) {
                    $this->assertSame(self::DEFAULTS[$other], $other::usesW53RustFfi(), "{$class} leaked into {$other}");
                }
            }
        });
    }

    public function test_readonly_class_keeps_w53_state_without_static_properties(): void
    {
        $reflection = new ReflectionClass(XmlResponseContext::class);
        $this->assertTrue($reflection->isReadOnly());
        $this->assertSame([], array_keys($reflection->getStaticProperties()));
    }

    public function test_earlier_wave_toggles_are_untouched(): void
    {
        ApiCapabilitiesService::withW53RustFfi(true, fn () => $this->assertFalse(ApiCapabilitiesService::$useRustFfi || ApiCapabilitiesService::usesW455RustFfi()));
        ApiReleaseRowCache::withW53RustFfi(true, fn () => $this->assertFalse(ApiReleaseRowCache::$useRustFfi || ApiReleaseRowCache::usesW455RustFfi()));
        ApiUsageService::withW53RustFfi(true, fn () => $this->assertFalse(ApiUsageService::usesW455RustFfi()));
        XmlResponseContext::withW53RustFfi(true, fn () => $this->assertFalse(XmlResponseContext::usesW465RustFfi()));
        FailedJobsCollector::withW53RustFfi(true, fn () => $this->assertFalse(FailedJobsCollector::usesRustFfi()));
        $this->assertFalse(ApiCapabilitiesService::$useRustFfi);
        $this->assertFalse(ApiCapabilitiesService::usesW455RustFfi());
        $this->assertFalse(ApiReleaseRowCache::$useRustFfi);
        $this->assertFalse(ApiReleaseRowCache::usesW455RustFfi());
        $this->assertFalse(ApiUsageService::usesW455RustFfi());
        $this->assertFalse(XmlResponseContext::usesW465RustFfi());
        $this->assertFalse(FailedJobsCollector::usesRustFfi());
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_entry_points_route_by_toggle(string $class, string $method, int $words, int $recordArgument): void
    {
        $this->requireFfi($class);
        $arguments = self::firstArguments($class);
        $php = $class::{$method.'Php'}(...$arguments);
        $this->assertCount($words * \count($arguments[$recordArgument]), $php);
        $this->assertSame($php, $class::withW53RustFfi(false, static fn () => $class::{$method}(...$arguments)), $class);
        $this->assertSame($php, $class::withW53RustFfi(true, static fn () => $class::{$method}(...$arguments)), $class);
        $this->assertSame($php, $class::{$method.'Native'}(...$arguments), $class);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_empty_batches_return_no_words(string $class, string $method, int $words, int $recordArgument): void
    {
        $this->assertGreaterThan(0, $words);
        $arguments = self::firstArguments($class);
        $arguments[$recordArgument] = [];
        if ($class === ApiCapabilitiesService::class) {
            $arguments[2] = [];
        }
        $this->assertSame([], $class::{$method.'Php'}(...$arguments));
        $this->assertSame([], $class::{$method.'Native'}(...$arguments));
        $this->assertSame([], $class::{$method}(...$arguments));
    }

    public function test_capabilities_param_plan_examples(): void
    {
        $this->requireFfi(ApiCapabilitiesService::class);
        $functions = ['search', 'tv-search', 'nope', 'movie-search', 'search', 'Search', 'anime-search', 'book-search'];
        $lists = ['q, CAT ,limit,,bogus', '', 'q,cat', 'id', ',, ,', 'q', "anidbid,\tANILISTID\n,attrs,sort,cursor", 'title,author,genre'];
        $this->assertBoth(ApiCapabilitiesService::class, [1, $functions, $lists], [
            1, 4, 3, 3,
            2, 0, 0, -1,
            0, 2, 0, 0,
            3, 1, 0, 0,
            1, 0, 0, -1,
            0, 1, 0, 0,
            6, 5, 4, 4,
            5, 3, 2, 2,
        ]);
        $this->assertBoth(ApiCapabilitiesService::class, [2, ['search', 'tv-search', 'movie-search'], ['id,cursor,q', 'vid,EP,attrs', 'genre,imdbid']], [
            1, 3, 2, 2,
            2, 3, 2, 2,
            3, 2, 1, 0,
        ]);
        $this->assertBoth(ApiCapabilitiesService::class, [3, ['search'], ['q,cat']], [0, 2, 0, 0]);
        // Mismatched lists, NUL bytes and non-list arrays decline to PHP.
        $this->assertBoth(ApiCapabilitiesService::class, [1, ['search', 'search'], ['q']], [1, 1, 1, -1, 1, 0, 0, -1]);
        $this->assertBoth(ApiCapabilitiesService::class, [1, ['search'], ["q\0,cat"]], [1, 2, 2, -1]);
        $this->assertBoth(ApiCapabilitiesService::class, [1, ["search\0"], ['q']], [0, 1, 0, 0]);
        $this->assertBoth(ApiCapabilitiesService::class, [1, ['search', 'tv-search'], [1 => 'ep', 0 => 'q']], [1, 1, 1, -1, 2, 1, 1, -1]);
    }

    public function test_row_cache_leaf_fingerprint_examples(): void
    {
        $this->requireFfi(ApiReleaseRowCache::class);
        $fnv = static fn (string $value): int => unpack('J', hash('fnv1a64', $value, true))[1];
        $this->assertBoth(ApiReleaseRowCache::class, [['abc', " \t", " abc\n", '', "\0x\0", "t\u{e9}l\u{e9}"]], [
            3, 0, $fnv('abc'),
            0, 3, $fnv(''),
            3, 2, $fnv('abc'),
            0, 1, $fnv(''),
            1, 2, $fnv('x'),
            6, 0, $fnv("t\u{e9}l\u{e9}"),
        ]);
        $this->assertSame(-1792535898324117685, $fnv('abc'));
    }

    public function test_usage_audit_value_plan_examples(): void
    {
        $this->requireFfi(ApiUsageService::class);
        $long = str_repeat("\u{e9}", 70);
        $this->assertBoth(ApiUsageService::class, [['a b~', $long, '', str_repeat('x', 64), str_repeat('y', 65), "\u{1F3AC}/x", 'Release.Name-GRP_1']], [
            4, 4, 6,
            70, 128, 384,
            0, 0, 0,
            64, 64, 64,
            65, 64, 64,
            3, 6, 16,
            18, 18, 18,
        ]);
        // Invalid UTF-8 and NUL bytes decline to PHP.
        $this->assertBoth(ApiUsageService::class, [["bad\xff", 'ok', "a\0b", "\xc3"]], null);
        mb_internal_encoding('ISO-8859-1');
        $this->assertBoth(ApiUsageService::class, [["\u{e9}t\u{e9}", str_repeat("\u{e9}", 40)]], [5, 5, 13, 80, 64, 192]);
    }

    public function test_xml_escape_plan_examples(): void
    {
        $this->requireFfi(XmlResponseContext::class);
        $this->assertBoth(XmlResponseContext::class, [["a'b\"<>&", "x\x01\t\x1f\n\r", '', 'plain', "t\u{e9}l\u{e9} & co", '&amp;']], [
            27, 5, 0,
            6, 0, 2,
            0, 0, 0,
            5, 0, 0,
            15, 1, 0,
            9, 1, 0,
        ]);
        // Invalid UTF-8 and NUL bytes decline to PHP (htmlspecialchars() returns '' for invalid UTF-8).
        $this->assertBoth(XmlResponseContext::class, [["bad\xff<", "a\0<b", 'ok']], [0, 1, 0, 7, 1, 1, 2, 0, 0]);
    }

    public function test_failed_job_exception_plan_examples(): void
    {
        $this->requireFfi(FailedJobsCollector::class);
        $trace = "\n#0 /app/vendor/laravel/framework/src/Illuminate/Queue/Worker.php(123): x\n#1 {main}";
        $this->assertBoth(FailedJobsCollector::class, [array_map(static fn (string $head): string => $head.$trace, self::EXCEPTION_HEADS)], [
            1, 41, 81,
            2, 45, 92,
            3, 51, 103,
            4, 16, 50,
            0, 0, 38,
            0, 0, 45,
            4, 12, 55,
            4, 14, 14,
            0, 0, 12,
            0, 0, 10,
            0, 0, 0,
            0, 0, 16,
            0, 0, 11,
        ]);
        $this->assertBoth(FailedJobsCollector::class, [["X\0: y", "Job\r\r\nnext", "A\\B:\0", 'no newline at all']], [0, 0, 5, 0, 0, 4, 4, 3, 5, 0, 0, 17]);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_every_helper_is_served_by_rust(string $class, string $method, int $words, int $recordArgument): void
    {
        $this->assertInstanceOf(FFI::class, $this->requireFfi($class));
        $arguments = self::firstArguments($class);
        $this->assertCount($words * \count($arguments[$recordArgument]), $class::{$method.'Native'}(...$arguments));
        $handle = self::state($class, 'buffer', 1);
        $this->assertIsArray($handle, "{$class} must resolve the Rust helper");
        $this->assertInstanceOf(FFI::class, $handle[0]);
    }

    public function test_large_batches_grow_the_output_buffer(): void
    {
        $this->requireFfi(ApiReleaseRowCache::class);
        $values = array_map(static fn (int $i): string => $i % 3 === 0 ? ' ' : ' value-'.$i.' ', range(0, 4999));
        $this->assertSame(ApiReleaseRowCache::leafFingerprintBatchPhp($values), ApiReleaseRowCache::leafFingerprintBatchNative($values));
        $handle = self::state(ApiReleaseRowCache::class, 'buffer', 0);
        $this->assertIsArray($handle);
        $this->assertSame(15000, $handle[2]);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_native_helpers_fall_back_to_php_without_library(string $class, string $method, int $words, int $recordArgument): void
    {
        self::state($class, 'reset', false);
        $arguments = self::firstArguments($class);
        $this->assertCount($words * \count($arguments[$recordArgument]), $class::{$method.'Native'}(...$arguments));
        $this->assertSame($class::{$method.'Php'}(...$arguments), $class::{$method.'Native'}(...$arguments), $class);
        $this->assertNull(self::state($class, 'buffer', 1), "{$class} must keep the disabled handle");
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_direct_c_abi_declines_invalid_input_and_reports_needed_capacity(string $class, string $method, int $words, int $recordArgument): void
    {
        $this->assertNotSame('', $method);
        $this->assertGreaterThanOrEqual(0, $recordArgument);
        $ffi = $this->requireFfi($class);
        [$function, $data, $ints, $count, $emptyInts] = self::abiPacket($class);
        $needed = $count * $words;
        $out = $ffi->new('int64_t[16]');
        $small = $ffi->new('int64_t[1]');
        $small[0] = 7;
        $call = static fn (int $count, int $flags, string $data, string $ints, mixed $out, int $cap): int => $ffi->{$function}($count, $flags, $data, \strlen($data), $ints, \strlen($ints), $out, $cap);

        $this->assertSame($needed, $call($count, 0, $data, $ints, $out, 16), $function);
        $this->assertSame(-$needed, $call($count, 0, $data, $ints, $small, 1), $function);
        $this->assertSame(7, $small[0], "{$function} must not write on a capacity error");
        $this->assertSame(-$needed, $call($count, 0, $data, $ints, null, 0), $function);
        $this->assertSame(-1, $call($count, 0, $data, $ints, null, 4), $function);
        $this->assertSame(-1, $call($count, 1, $data, $ints, $out, 16), "{$function} flags");
        $this->assertSame(-1, $call($count + 1, 0, $data, $ints, $out, 16), "{$function} record count");
        $this->assertSame(-1, $call($count, 0, $data, $ints.'x', $out, 16), "{$function} ragged int table");
        $this->assertSame(0, $call(0, 0, '', $emptyInts, null, 0), "{$function} empty batch");
    }

    public function test_randomized_parity_across_helpers(): void
    {
        $this->requireFfi(ApiCapabilitiesService::class);
        mt_srand(5351);
        for ($round = 0; $round < self::RANDOM_ROUNDS; $round++) {
            $count = mt_rand(1, 16);
            $withNul = $round % 50 === 49;
            $functions = $lists = $values = $exceptions = [];
            for ($i = 0; $i < $count; $i++) {
                $functions[] = self::FUNCTIONS[mt_rand(0, \count(self::FUNCTIONS) - 1)];
                $names = [];
                for ($n = 0, $max = mt_rand(0, 12); $n < $max; $n++) {
                    $names[] = mt_rand(0, 9) === 0 ? self::randomBytes(6, $withNul) : self::PARAMS[mt_rand(0, \count(self::PARAMS) - 1)];
                }
                $lists[] = implode(',', $names);
                $values[] = mt_rand(0, 3) === 0 ? str_repeat(self::randomBytes(4, $withNul), mt_rand(1, 30)) : self::randomBytes(40, $withNul);
                $exceptions[] = (mt_rand(0, 3) === 0 ? self::randomBytes(30, $withNul) : self::EXCEPTION_HEADS[mt_rand(0, \count(self::EXCEPTION_HEADS) - 1)])
                    .(mt_rand(0, 1) === 1 ? self::randomBytes(20, true) : '');
            }

            $this->assertBoth(ApiCapabilitiesService::class, [mt_rand(0, 3), $functions, $lists], null);
            $this->assertBoth(ApiReleaseRowCache::class, [$values], null);
            $this->assertBoth(ApiUsageService::class, [$values], null);
            $this->assertBoth(XmlResponseContext::class, [$values], null);
            $this->assertBoth(FailedJobsCollector::class, [$exceptions], null);

            // The audit plan matches what auditEntryFromPhp() writes for each value below the column limit.
            if (! $withNul) {
                $plan = ApiUsageService::auditValuePlanBatchNative($values);
                foreach ($values as $index => $value) {
                    if (mb_check_encoding($value, 'UTF-8') && $plan[3 * $index + 2] + 4 < 255) {
                        $entry = ApiUsageService::auditEntryFromPhp('', ['v' => $value]);
                        // `/?v=` precedes the encoded value.
                        $this->assertSame(\strlen($entry) - 4, $plan[3 * $index + 2], 'encoded length');
                    }
                }
            }
        }
    }

    public function test_paired_randomized_benchmark_gates_rust_default(): void
    {
        $this->requireFfi(ApiCapabilitiesService::class);
        mt_srand(5352);
        $strict = getenv('NNTMUX_W53_BENCH') === '1';
        $samples = $strict ? 1000 : 300;
        $workloads = [];
        $floors = [];
        $trace = '';
        for ($frame = 0; $frame < 12; $frame++) {
            $trace .= "\n#{$frame} /app/vendor/laravel/framework/src/Illuminate/Queue/Worker.php(".(100 + $frame * 7).'): Illuminate\Queue\Worker->process()';
        }

        foreach (['common' => 64, 'uncommon' => 192] as $name => $count) {
            $odd = $name === 'uncommon';
            $functions = $lists = $leaves = $audits = $xml = $exceptions = [];
            for ($i = 0; $i < $count; $i++) {
                $functions[] = $odd ? self::FUNCTIONS[$i % \count(self::FUNCTIONS)] : ['search', 'tv-search', 'movie-search'][$i % 3];
                $lists[] = $odd
                    ? match ($i % 6) {
                        0 => ' Q , CAT ,limit,,offset, apikey ,t,o,extended,attrs,del,sort,maxage,minsize,maxsize,group',
                        1 => '',
                        2 => 'tvdbid,season,ep,imdbid,tmdbid,traktid,tvmazeid,rid,vid,cat,limit',
                        3 => str_repeat('unknownparam,', 8).'q',
                        4 => "anidbid,\tanilistid\n,cursor,id,genre,title,author",
                        default => 'q',
                    }
                : ['q,cat,limit,offset,extended,apikey,t', 'q,season,ep,tvdbid,cat,limit,apikey,t,o', 'imdbid,cat,limit,offset,extended,apikey,t'][$i % 3];

                $leaves[] = $odd
                    ? match ($i % 6) {
                        0 => "  T\u{e9}l\u{e9}chargement {$i} \u{1F3AC}  ",
                        1 => " \t\n",
                        2 => str_repeat('Long.Release.Name.', 12).$i,
                        3 => '5030,5040,5045,'.$i,
                        4 => '',
                        default => "\t".$i."\n",
                    }
                : ['Release.Name.S01E'.sprintf('%02d', $i % 24).'.1080p.WEB-DL-GRP', ' 5030 ', 'tt'.(1_000_000 + $i), (string) (100 * $i)][$i % 4];

                $audits[] = $odd
                    ? match ($i % 6) {
                        0 => str_repeat("t\u{e9}l\u{e9}", 30).$i,
                        1 => '',
                        2 => str_repeat('a b/c?d&e=', 10),
                        3 => "\u{1F3AC}\u{1F3AC} {$i}",
                        4 => str_repeat('x', 200),
                        default => 'Name.'.$i,
                    }
                : ['Release Name 2024 1080p', '5030,5040', 'tt'.(1_000_000 + $i), 'The.Show.S0'.($i % 9 + 1)][$i % 4];

                $xml[] = $odd
                    ? match ($i % 6) {
                        0 => "Tom & Jerry's \"Best\" <{$i}> \u{e9}t\u{e9}",
                        1 => "ctrl\x01\x02\x1f text {$i}",
                        2 => str_repeat('&<>', 30),
                        3 => '',
                        4 => str_repeat("Long description of release {$i}. ", 8),
                        default => "\u{1F3AC} {$i}",
                    }
                : ['Release.Name.'.$i.'.1080p.WEB-DL-GRP', 'Movies > HD', 'Tom & Jerry '.$i, 'https://example.test/details/'.md5((string) $i)][$i % 4];

                $exceptions[] = $odd
                    ? self::EXCEPTION_HEADS[$i % \count(self::EXCEPTION_HEADS)].($i % 3 === 0 ? '' : $trace)
                    : ['Illuminate\Queue\TimeoutExceededException: App\Jobs\ProcessReleases has timed out.', 'Illuminate\Queue\MaxAttemptsExceededException: App\Jobs\PostProcess has been attempted too many times.', 'RuntimeException: NNTP connection reset by peer', 'Illuminate\Database\Eloquent\ModelNotFoundException: No query results for model [App\Models\Release] '.$i][$i % 4].$trace;
            }

            $workloads[ApiCapabilitiesService::class][$name] = static fn (bool $rust) => $rust ? ApiCapabilitiesService::supportedParamPlanBatchNative(1, $functions, $lists) : ApiCapabilitiesService::supportedParamPlanBatchPhp(1, $functions, $lists);
            $workloads[ApiReleaseRowCache::class][$name] = static fn (bool $rust) => $rust ? ApiReleaseRowCache::leafFingerprintBatchNative($leaves) : ApiReleaseRowCache::leafFingerprintBatchPhp($leaves);
            $workloads[ApiUsageService::class][$name] = static fn (bool $rust) => $rust ? ApiUsageService::auditValuePlanBatchNative($audits) : ApiUsageService::auditValuePlanBatchPhp($audits);
            $workloads[XmlResponseContext::class][$name] = static fn (bool $rust) => $rust ? XmlResponseContext::xmlEscapePlanBatchNative($xml) : XmlResponseContext::xmlEscapePlanBatchPhp($xml);
            $workloads[FailedJobsCollector::class][$name] = static fn (bool $rust) => $rust ? FailedJobsCollector::exceptionPlanBatchNative($exceptions) : FailedJobsCollector::exceptionPlanBatchPhp($exceptions);
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
                "\n[Wave 53.5 benchmark] %s: common mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx | uncommon mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx",
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
            $this->assertSame(self::DEFAULTS[$class], $class::usesW53RustFfi(), $class);
            if ($class::usesW53RustFfi()) {
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
        $method = self::METHODS[$class][0];
        $php = $class::{$method.'Php'}(...$arguments);
        if ($expected !== null) {
            $this->assertSame($expected, $php, "{$class} PHP");
        }
        $this->assertSame($php, $class::{$method.'Native'}(...$arguments), "{$class} native");
        $this->assertSame($php, $class::withW53RustFfi(true, static fn () => $class::{$method}(...$arguments)), "{$class} Rust route");
        $this->assertSame($php, $class::withW53RustFfi(false, static fn () => $class::{$method}(...$arguments)), "{$class} PHP route");
    }

    /**
     * @param  class-string  $class
     * @return list<mixed>
     */
    private static function firstArguments(string $class): array
    {
        return match ($class) {
            ApiCapabilitiesService::class => [1, ['search', 'tv-search', 'caps'], ['q,cat,apikey', 'season,ep', 'q']],
            ApiReleaseRowCache::class => [[' a ', '', 'b']],
            ApiUsageService::class => [['a b', "\u{e9}", '']],
            XmlResponseContext::class => [['a&b', '<x>', '']],
            FailedJobsCollector::class => [['RuntimeException: x', "App\\Jobs\\X has timed out.\n#0", '']],
            default => throw new RuntimeException($class),
        };
    }

    /**
     * Function, data, int table and record count of a valid one-record C call, plus the int table of an
     * empty call.
     *
     * @param  class-string  $class
     * @return array{string, string, string, int, string}
     */
    private static function abiPacket(string $class): array
    {
        return match ($class) {
            ApiCapabilitiesService::class => ['nntmux_w535_api_capabilities_param_plan', "search\0q", pack('P', 1), 1, pack('P', 1)],
            ApiReleaseRowCache::class => ['nntmux_w535_api_row_cache_leaf_fingerprint', 'abc', '', 1, ''],
            ApiUsageService::class => ['nntmux_w535_api_usage_audit_value_plan', 'abc', '', 1, ''],
            XmlResponseContext::class => ['nntmux_w535_xml_escape_plan', 'a&b', '', 1, ''],
            FailedJobsCollector::class => ['nntmux_w535_failed_job_exception_plan', 'X: y', pack('P', 4), 1, ''],
            default => throw new RuntimeException($class),
        };
    }

    /**
     * Invokes the class's private `w53RustFfiState()`.
     *
     * @param  class-string  $class
     */
    private static function state(string $class, ?string $op, mixed $val): mixed
    {
        return (new ReflectionMethod($class, 'w53RustFfiState'))->invoke(null, $op, $val);
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
        $cdef = (new ReflectionClassConstant($class, 'W53_FFI_CDEF'))->getValue();
        $ffi = RustPipelineLibrary::resolveDomain('w53_5', $cdef, \is_string($path) && $path !== '' ? $path : null);
        if ($ffi === null) {
            $this->markTestSkipped('Rust W53.5 FFI library is not built or available.');
        }

        return $ffi;
    }
}
