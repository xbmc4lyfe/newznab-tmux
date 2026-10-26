<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Api\ApiCapabilitiesService;
use App\Services\Api\ApiReleaseRowCache;
use App\Services\Api\ApiUsageService;
use App\Services\Api\ApiUserResolver;
use App\Services\Api\V1\ApiV1Presenter;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * Paired randomized benchmark (`test_paired_randomized_benchmark_gates_rust_default`, run with
 * `RUN_RUST_FFI_BENCHMARKS=1`): per helper, 100 interleaved samples per workload after 20 warmup rounds,
 * per-sample random PHP/Rust order, each side the fastest of three back-to-back calls, release dylib.
 * Common workloads hold 320 records and uncommon ones 384 records with edge cases. Timings include
 * PHP-side packing, the FFI call and output decoding; each run prints its ratios to STDERR.
 *
 * A helper only defaults to Rust when every recorded common and uncommon mean and p99 ratio reaches 1.5x
 * (QUALIFICATION); each benchmark run asserts that the Rust defaults still clear 1.5x.
 */
class RustApiCapabilitiesCacheUsageUserResolverV1PresenterW65ParityTest extends TestCase
{
    /**
     * Batch method per class; each has `<method>Php` and `<method>Native` variants.
     *
     * @var array<class-string, string>
     */
    private const METHODS = [
        ApiCapabilitiesService::class => 'w65ApiCapabilitiesPlanBatch',
        ApiReleaseRowCache::class => 'w65ApiReleaseCachePlanBatch',
        ApiUsageService::class => 'w65ApiUsagePlanBatch',
        ApiUserResolver::class => 'w65ApiUserResolverPlanBatch',
        ApiV1Presenter::class => 'w65ApiV1PresenterPlanBatch',
    ];

    /**
     * Private C declaration constant and entry point per class.
     *
     * @var array<class-string, array{string, string}>
     */
    private const FUNCTIONS = [
        ApiCapabilitiesService::class => ['W65_API_CAPABILITIES_PLAN_FFI_CDEF', 'nntmux_w655_api_capabilities_plan'],
        ApiReleaseRowCache::class => ['W65_API_RELEASE_CACHE_PLAN_FFI_CDEF', 'nntmux_w655_api_release_cache_plan'],
        ApiUsageService::class => ['W65_API_USAGE_PLAN_FFI_CDEF', 'nntmux_w655_api_usage_plan'],
        ApiUserResolver::class => ['W65_API_USER_RESOLVER_PLAN_FFI_CDEF', 'nntmux_w655_api_user_resolver_plan'],
        ApiV1Presenter::class => ['W65_API_V1_PRESENTER_PLAN_FFI_CDEF', 'nntmux_w655_api_v1_presenter_plan'],
    ];

    /**
     * Output words per record.
     *
     * @var array<class-string, int>
     */
    private const WIDTHS = [
        ApiCapabilitiesService::class => 11,
        ApiReleaseRowCache::class => 10,
        ApiUsageService::class => 11,
        ApiUserResolver::class => 8,
        ApiV1Presenter::class => 10,
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
        ApiUserResolver::class => true,
        ApiV1Presenter::class => true,
    ];

    /**
     * Common mean/p99 then uncommon mean/p99 speedups (PHP / Rust), the per-metric minimum of four
     * `RUN_RUST_FFI_BENCHMARKS=1` runs (100 samples per workload, seed 6552, release Rust, PHP CLI without
     * opcache, Apple Silicon macOS on a shared host).
     *
     * @var array<class-string, array{float, float, float, float}>
     */
    private const QUALIFICATION = [
        ApiCapabilitiesService::class => [6.74, 5.01, 5.01, 3.59],
        ApiReleaseRowCache::class => [4.38, 2.67, 5.77, 4.93],
        ApiUsageService::class => [6.57, 3.78, 6.10, 6.17],
        ApiUserResolver::class => [6.05, 4.10, 6.12, 3.60],
        ApiV1Presenter::class => [26.61, 15.71, 8.68, 7.95],
    ];

    /**
     * Properties before Wave 65.5; the wave keeps its state in function-local statics.
     *
     * @var array<class-string, list<string>>
     */
    private const PROPERTIES = [
        ApiCapabilitiesService::class => ['useRustFfi', 'ffi', 'useW455RustFfi', 'registrationStatus', 'queryParameters'],
        ApiReleaseRowCache::class => ['useRustFfi', 'ffi', 'useW455RustFfi'],
        ApiUsageService::class => ['useW455RustFfi', 'inputCanonicalizer'],
        ApiUserResolver::class => ['useRustFfi', 'ffi'],
        ApiV1Presenter::class => ['useRustFfi', 'ffi', 'useW465RustFfi', 'w465Ffi', 'w465Out', 'w465OutCap', 'capabilities'],
    ];

    /** Back-to-back calls per side of a sample; the fastest is kept (drops preemption spikes). */
    private const REPEATS = 3;

    private const RANDOM_ROUNDS = 400;

    private const BENCH_SAMPLES = 100;

    private const BYTES = ['a', 'Z', 'x', 'F', 'f', '0', '1', '7', '9', ' ', "\t", "\n", "\r", "\x0b", "\x0c", "\x01", "\x08", "\x1f", "\x7f", '-', '+', '_', '.', ',', ':', ';', '/', '\\', '|', '&', '=', '<', '>', '"', "'", "\xc3\xa9", "\xc3", "\xff", "\xa0", "\xed\xa0\x80", "\xf4\x90\x80\x80", "\xe2\x80\xa8", "\0"];

    /** Fragments glued into otherwise structured records so separators and keywords actually occur. */
    private const FRAGMENTS = ["\t", "\t\t", '|', '||', ':', '::', ',', ',,', '&', '&&', '=', '==', ' ', '"', '<', '&amp;', '\\', '0', '1', '9', '00', '64', '86400', '999999999999999', '1000000000000000', 'q', 'cat', 'imdbid', 'search', 'tv-search', 'caps', 'json', 'XML', 'get', 'nzb', 'admin', 'Moderator', 'size', 'guid', "\x0c", "\x01", "\xc3\xa9", "\xff"];

    protected function tearDown(): void
    {
        foreach (array_keys(self::METHODS) as $class) {
            $class::resetW65RustFfi();
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
        foreach (['enableW65RustFfi', 'disableW65RustFfi', 'resetW65RustFfi', 'usesW65RustFfi', 'withW65RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
            $this->assertTrue($reflection->hasMethod($name), "{$class}::{$name}");
            $this->assertTrue($reflection->getMethod($name)->isStatic() && $reflection->getMethod($name)->isPublic(), "{$class}::{$name}");
        }
        $this->assertTrue($reflection->getMethod('w65RustFfiState')->isPrivate(), "{$class} keeps its W65 state private");
        $this->assertTrue((new ReflectionClassConstant($class, self::FUNCTIONS[$class][0]))->isPrivate(), "{$class} keeps its W65 declaration private");
        $constant = new ReflectionClassConstant($class, 'W65_RUST_FFI_DEFAULT');
        $this->assertTrue($constant->isPublic(), "{$class} default must be public");
        $this->assertSame(self::DEFAULTS[$class], $constant->getValue(), "{$class} default must match the benchmark evidence");
        $this->assertSame(min(self::QUALIFICATION[$class]) >= 1.5, self::DEFAULTS[$class], "{$class} default must follow its recorded qualification");
        $this->assertSame(self::DEFAULTS[$class], $class::usesW65RustFfi(), "{$class} routes by its default (PHP when it did not qualify)");

        $class::enableW65RustFfi();
        $this->assertTrue($class::usesW65RustFfi());
        $class::disableW65RustFfi();
        $this->assertFalse($class::usesW65RustFfi());
        $class::resetW65RustFfi();
        $this->assertSame(self::DEFAULTS[$class], $class::usesW65RustFfi(), "{$class} reset restores the default");

        foreach ([true, false] as $enabled) {
            $this->assertSame([$enabled, 'ok'], $class::withW65RustFfi($enabled, static fn () => [$class::usesW65RustFfi(), 'ok']));
            $this->assertSame(self::DEFAULTS[$class], $class::usesW65RustFfi(), "{$class} must restore after the callback");
        }
        $class::disableW65RustFfi();
        $this->assertTrue($class::withW65RustFfi(true, static fn () => $class::usesW65RustFfi()));
        $this->assertFalse($class::usesW65RustFfi(), "{$class} restores an explicit override, not the default");
        $class::resetW65RustFfi();
        $nested = $class::withW65RustFfi(false, static fn () => [$class::withW65RustFfi(true, static fn () => $class::usesW65RustFfi()), $class::usesW65RustFfi()]);
        $this->assertSame([true, false], $nested);
        $caught = null;
        try {
            $class::withW65RustFfi(! $class::usesW65RustFfi(), static fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException $exception) {
            $caught = $exception->getMessage();
        }
        $this->assertSame('boom', $caught, 'The callback exception must propagate.');
        $this->assertSame(self::DEFAULTS[$class], $class::usesW65RustFfi(), "{$class} must restore after an exception");

        $class::withW65RustFfi(! $class::usesW65RustFfi(), function () use ($class): void {
            foreach (array_keys(self::METHODS) as $other) {
                if ($other !== $class) {
                    $this->assertSame(self::DEFAULTS[$other], $other::usesW65RustFfi(), "{$class} leaked into {$other}");
                }
            }
        });
    }

    public function test_w65_state_adds_no_properties(): void
    {
        foreach (self::PROPERTIES as $class => $expected) {
            $reflection = new ReflectionClass($class);
            foreach ($reflection->getProperties() as $property) {
                $this->assertStringNotContainsStringIgnoringCase('w65', $property->getName(), $class);
            }
            $this->assertSame($expected, array_map(
                static fn (ReflectionProperty $property): string => $property->getName(),
                $reflection->getProperties(),
            ), "{$class} gains no properties");
        }
    }

    public function test_earlier_wave_toggles_are_untouched(): void
    {
        $snapshot = static fn (): array => [
            ApiCapabilitiesService::$useRustFfi,
            ApiCapabilitiesService::usesW455RustFfi(),
            ApiCapabilitiesService::usesW53RustFfi(),
            ApiReleaseRowCache::usesW455RustFfi(),
            ApiReleaseRowCache::usesW53RustFfi(),
            ApiUsageService::usesW455RustFfi(),
            ApiUsageService::usesW53RustFfi(),
            ApiUserResolver::usesW54RustFfi(),
            ApiV1Presenter::usesW465RustFfi(),
            ApiV1Presenter::usesW54RustFfi(),
        ];
        $before = $snapshot();
        foreach (array_keys(self::METHODS) as $class) {
            foreach ([true, false] as $enabled) {
                $this->assertSame($before, $class::withW65RustFfi($enabled, $snapshot), $class);
            }
            $class::enableW65RustFfi();
            $class::resetW65RustFfi();
            $this->assertSame($before, $snapshot(), $class);
        }
    }

    public function test_existing_public_api_is_preserved(): void
    {
        foreach ([
            ApiCapabilitiesService::class => ['__construct', 'setUseRustFfi', 'searchFfi', 'v1', 'v2', 'limitsPayload', 'limitsPayloadPhp', 'limitsPayloadNative', 'searching', 'searchingPhp', 'searchingNative', 'useW455RustFfi', 'usesW455RustFfi', 'resetW455RustFfi', 'summarizeSupportedParameterBatch', 'summarizeSupportedParameterBatchPhp', 'summarizeSupportedParameterBatchNative', 'registrationPayload', 'registrationPayloadPhp', 'registrationPayloadNative', 'usesW53RustFfi', 'withW53RustFfi', 'supportedParamPlanBatch', 'supportedParamPlanBatchPhp', 'supportedParamPlanBatchNative'],
            ApiReleaseRowCache::class => ['remember', 'normalize', 'normalizePhp', 'normalizeNative', 'normalizeJsonNative', 'setUseRustFfi', 'useW455RustFfi', 'usesW455RustFfi', 'resetW455RustFfi', 'summarizeStringLeafBatch', 'summarizeStringLeafBatchPhp', 'summarizeStringLeafBatchNative', 'mediaFfi', 'usesW53RustFfi', 'withW53RustFfi', 'leafFingerprintBatch', 'leafFingerprintBatchPhp', 'leafFingerprintBatchNative'],
            ApiUsageService::class => ['__construct', 'statistics', 'reserve', 'reservedCount', 'reservedCountPhp', 'reservedCountNative', 'quotaLockStore', 'record', 'auditEntry', 'auditEntryFrom', 'auditEntryFromPhp', 'auditEntryFromNative', 'useW455RustFfi', 'usesW455RustFfi', 'resetW455RustFfi', 'summarizeAuditEntryBatch', 'summarizeAuditEntryBatchPhp', 'summarizeAuditEntryBatchNative', 'usesW53RustFfi', 'withW53RustFfi', 'auditValuePlanBatch', 'auditValuePlanBatchPhp', 'auditValuePlanBatchNative'],
            ApiUserResolver::class => ['setUseRustFfi', 'searchFfi', 'v1', 'v2', 'cacheKey', 'cacheKeyPhp', 'cacheKeyNative', 'resolveCredentials', 'resolveCredentialsPhp', 'resolveCredentialsNative', 'usesW54RustFfi', 'withW54RustFfi', 'tokenPlanBatch', 'tokenPlanBatchPhp', 'tokenPlanBatchNative'],
            ApiV1Presenter::class => ['__construct', 'setUseRustFfi', 'searchFfi', 'output', 'isCapsRequest', 'isCapsRequestPhp', 'isCapsRequestNative', 'responseHeaders', 'responseHeadersPhp', 'responseHeadersNative', 'useW465RustFfi', 'usesW465RustFfi', 'resetW465RustFfi', 'summarizeJsonStringBatch', 'summarizeJsonStringBatchPhp', 'summarizeJsonStringBatchNative', 'evaluateW465Native', 'evaluateW465Counters', 'w465Joined', 'usesW54RustFfi', 'withW54RustFfi', 'parameterPlanBatch', 'parameterPlanBatchPhp', 'parameterPlanBatchNative'],
        ] as $class => $methods) {
            $reflection = new ReflectionClass($class);
            foreach ($methods as $name) {
                $this->assertTrue($reflection->hasMethod($name) && $reflection->getMethod($name)->isPublic(), "{$class}::{$name}");
            }
        }
        foreach ([
            [ApiCapabilitiesService::class, 'W455_RUST_FFI_DEFAULT', false],
            [ApiCapabilitiesService::class, 'W53_RUST_FFI_DEFAULT', true],
            [ApiReleaseRowCache::class, 'W455_RUST_FFI_DEFAULT', false],
            [ApiReleaseRowCache::class, 'W53_RUST_FFI_DEFAULT', true],
            [ApiUsageService::class, 'W455_RUST_FFI_DEFAULT', false],
            [ApiUsageService::class, 'W53_RUST_FFI_DEFAULT', true],
            [ApiUserResolver::class, 'W54_RUST_FFI_DEFAULT', true],
            [ApiV1Presenter::class, 'W465_RUST_FFI_DEFAULT', true],
            [ApiV1Presenter::class, 'W54_RUST_FFI_DEFAULT', true],
        ] as [$class, $constant, $value]) {
            $this->assertSame($value, (new ReflectionClassConstant($class, $constant))->getValue(), "{$class}::{$constant}");
        }

        $earlier = static fn (): array => [
            ApiCapabilitiesService::limitsPayloadPhp(100, 50),
            ApiCapabilitiesService::searchingPhp(2),
            ApiCapabilitiesService::supportedParamPlanBatchPhp(1, ['search', 'bogus'], ['q,cat,x', 'q']),
            ApiReleaseRowCache::leafFingerprintBatchPhp(['a', ' b ']),
            ApiUsageService::reservedCountPhp(4, 5),
            ApiUsageService::auditValuePlanBatchPhp(['abc', str_repeat('x', 80)]),
            ApiUserResolver::cacheKeyPhp('token'),
            ApiUserResolver::resolveCredentialsPhp(' ', 'key'),
            ApiUserResolver::tokenPlanBatchPhp(['abc', '']),
            ApiV1Presenter::isCapsRequestPhp('caps'),
            ApiV1Presenter::parameterPlanBatchPhp(['t=search', '']),
        ];
        $expected = $earlier();
        foreach (array_keys(self::METHODS) as $class) {
            foreach ([true, false] as $enabled) {
                $this->assertSame($expected, $class::withW65RustFfi($enabled, $earlier), $class);
            }
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
        $this->assertSame($php, $class::withW65RustFfi(false, static fn () => $class::{$method}($records)), $class);
        $this->assertSame($php, $class::withW65RustFfi(true, static fn () => $class::{$method}($records)), $class);
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

    public function test_api_capabilities_plan_examples(): void
    {
        $this->requireFfi(ApiCapabilitiesService::class);
        $this->assertBoth(ApiCapabilitiesService::class, [
            "1\t100\t50\ttv-search\tq, season ,ep,bogus,,\t5000|5030:5000|5040:7000",
            '',
            "2\t0\t500\tmovie-search\tid,q,cursor,imdbid\t1000|1000|1010:1000|x|1:|:2",
            " 3 \t250\t\tbook-search\tq,author",
            "1\t\t\tSearch\tq\t\textra",
        ], [
            1, 100, 50, 2, 4, 3, 769, 1, 2, 1, 0,
            1, 100, 100, 0, 0, 0, 0, 0, 0, 0, 2,
            2, 1, 1, 3, 4, 3, 515, 2, 1, 0, 9,
            3, 250, 250, 5, 2, 0, 0, 0, 0, 0, 6,
            1, 100, 100, -1, 1, 0, 0, 0, 0, 0, 18,
        ]);
    }

    public function test_api_release_cache_plan_examples(): void
    {
        $this->requireFfi(ApiReleaseRowCache::class);
        $this->assertBoth(ApiReleaseRowCache::class, [
            "v1\tsearch\tt=search&q=abc&cat=2000\t600\t60\t900\t30\t4096\t1048576\t0",
            '',
            "v2\tmovies\t b = 2 & a=1 &&noeq&=x&c=\t100\t0\t50\t100\t65537\t65536\t1",
            "v2\tmovies\ta=1&b=2\t100\t0\t150\t120\t\t\t1",
            "v2\tmovies\ta=1&b=2\t100\t0\t150\t\t\t\tx",
        ], [
            crc32('api_release_rows:v1:search:cat=2000&q=abc&t=search'), 3, 0, 23, 641, 900, 1, 1, 1, 0,
            crc32('api_release_rows:::'), 0, 0, 0, 605, 900, 3, 1, 0, 2,
            crc32('api_release_rows:v2:movies:a=1&b=2'), 2, 4, 7, 100, 100, 4, 0, 2, 4,
            crc32('api_release_rows:v2:movies:a=1&b=2'), 2, 0, 7, 100, 150, 2, 1, 0, 0,
            crc32('api_release_rows:v2:movies:a=1&b=2'), 2, 0, 7, 100, 150, 3, 1, 0, 1,
        ]);
    }

    public function test_api_usage_plan_examples(): void
    {
        $this->requireFfi(ApiUsageService::class);
        $this->assertBoth(ApiUsageService::class, [
            "user\tapi\t10\t2\t\t\t3600\t0",
            '',
            " Admin \tget\t5\t1000\t\t\t100\t1",
            "user\tnzb\t3\t25\t\t\t0\t0",
            "4\tapi\t1000\t0\t\t\t0\t0",
            "disabled\t\t0\t0\t\t\t90000\t0",
            "bogus\tpost\tx",
            "user\tapi\t0\t0\t100\t\t\t2",
        ], [
            1, 0, 100, 25, 11, 89, 23, 82800, 2, 0, 0,
            1, 0, 100, 25, 1, 99, 25, 86400, 2, 0, 2,
            2, 1, 10000, 1000, 0, 9995, 0, 86300, 167, 2, 0,
            1, 1, 100, 25, 0, 97, 0, 86400, 2, 2, 0,
            4, 0, 1000, 100, 0, 0, 100, 86400, 17, 1, 0,
            3, 0, 0, 0, 0, 0, 0, 86400, 1, 4, 16,
            0, 0, 0, 0, 0, 0, 0, 86400, 1, 5, 15,
            1, 0, 100, 25, 0, 100, 25, 86400, 2, 3, 0,
        ]);
        // The request quota matches reservedCountPhp().
        foreach ([[0, 1], [4, 5], [5, 5], [6, 5], [0, 0]] as [$used, $max]) {
            $plan = ApiUsageService::w65ApiUsagePlanBatchPhp(["admin\tapi\t{$used}\t0\t{$max}\t1\t0\t0"]);
            $this->assertSame(ApiUsageService::reservedCountPhp($used, $max) ?? 0, $plan[4], "{$used}/{$max}");
        }
    }

    public function test_api_user_resolver_plan_examples(): void
    {
        $this->requireFfi(ApiUserResolver::class);
        $this->assertBoth(ApiUserResolver::class, [
            "0123456789abcdef0123456789abcdef\t\t0\t1\t0\t0\tuser",
            '',
            "\t".str_repeat('ab', 20)."\t0\t0\t0\t0\tadmin",
            " \tkey\t0\t1\t0\t0\t",
            "\tkey with space\t0\t1\t0\t0\t",
            str_repeat('f', 64)."\t\t1\t0\t\t\tmoderator",
            "tok\t\t0\t0\t100\t100\t4",
            "tok\t\t0\t0\t100\t99\t9",
            "\t\t0",
        ], [
            2, 0, 32, 1, 1, 0, 35, 0,
            0, -1, 0, 0, 1, 1, 0, 2,
            1, 1, 40, 2, 2, 4, 0, 0,
            1, 0, 1, 5, 1, 2, 0, 0,
            1, 1, 14, 5, 1, 2, 0, 0,
            2, 0, 64, 3, 4, 3, 0, 0,
            2, 0, 3, 4, 4, 5, 0, 0,
            2, 0, 3, 4, 0, 6, 0, 8,
            0, -1, 0, 0, 1, 1, 0, 2,
        ]);
        // Token selection and version match resolveCredentialsPhp() with empty fields standing for null.
        foreach ([['abc', ''], ['', 'key'], [' ', 'key'], ['', ''], ['x', 'y']] as [$apiToken, $apikey]) {
            $plan = ApiUserResolver::w65ApiUserResolverPlanBatchPhp(["{$apiToken}\t{$apikey}\t0\t1\t0\t0\tuser"]);
            $credentials = ApiUserResolver::resolveCredentialsPhp($apiToken === '' ? null : $apiToken, $apikey === '' ? null : $apikey);
            $this->assertSame($credentials['version'] ?? 0, $plan[0], var_export([$apiToken, $apikey], true));
        }
    }

    public function test_api_v1_presenter_plan_examples(): void
    {
        $this->requireFfi(ApiV1Presenter::class);
        $this->assertBoth(ApiV1Presenter::class, [
            "xml\tsearch\t0123456789abcdef0123456789abcdef\tA & B\t1024\t2000\tsize=1024|grabs=3",
            '',
            " JSON \tcaps\t\t\"x\"\n\t\t\t",
            "yaml\tnope\tZZ\t\xff\t5\t\tnoeq|=v|custom=<a>",
            "json\t\t\tA\\B\x01\t\t\tguid=\"x\"",
        ], [
            0, 2, 1, 9, 5, 2, 2, 415, 360, 0,
            0, 2, 0, 0, 0, 0, 0, 258, 194, 2,
            1, 1, 0, 14, 7, 0, 0, 0, 0, 0,
            0, 0, 0, 1, -1, 1, 0, 310, -1, 61,
            1, 2, 0, 4, 10, 1, 1, 311, 251, 0,
        ]);
        // The XML title length matches htmlspecialchars() with quotes, and the JSON one json_encode() for plain text.
        foreach (['A & B', '<"it\'s">', "line\nquote\"back\\slash/\x01", 'caf'."\xc3\xa9"] as $title) {
            $plan = ApiV1Presenter::w65ApiV1PresenterPlanBatchPhp(["xml\tsearch\t\t{$title}\t\t\t"]);
            $this->assertSame(\strlen(htmlspecialchars($title, ENT_QUOTES | ENT_XML1)), $plan[3], $title);
            $this->assertSame(\strlen((string) json_encode($title, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) - 2, $plan[4], $title);
        }
    }

    /**
     * Numeric limits: 15 digits parse, 16 digits are invalid, and window, quota and budget edges hold exactly.
     */
    public function test_boundary_records(): void
    {
        $this->requireFfi(ApiCapabilitiesService::class);
        $max = '999999999999999';
        $this->assertBoth(ApiCapabilitiesService::class, [
            "1\t{$max}\t{$max}\ttv-search\t".implode(',', explode(',', 'q,vid,tvdbid,traktid,rid,tvmazeid,imdbid,tmdbid,season,ep,cat,minsize,maxsize,maxage,limit,offset,attrs,extended,del,sort'))."\t{$max}|1{$max}|0:{$max}",
            "1{$max}\t1{$max}\t1\tsearch\t\t",
        ], [
            1, (int) $max, (int) $max, 2, 20, 20, (1 << 20) - 1, 1, 1, 0, 1,
            1, 100, 1, 1, 0, 0, 0, 0, 0, 0, 1,
        ]);
        $this->assertBoth(ApiReleaseRowCache::class, [
            "v\ts\tk=v\t{$max}\t{$max}\t{$max}\t{$max}\t{$max}\t{$max}\t{$max}",
            "v\ts\tk=v\t1{$max}\t0\t0\t0\t65536\t65536\t0",
        ], null);
        $this->assertBoth(ApiUsageService::class, [
            "admin\tget\t{$max}\t{$max}\t{$max}\t{$max}\t86399\t{$max}",
            "admin\tget\t{$max}\t0\t1{$max}\t\t86400\t0",
            "user\tget\t99\t24\t\t\t86399\t1",
        ], [
            2, 1, (int) $max, (int) $max, 0, 0, 0, 1, 16666666666667, 1, 0,
            2, 1, 10000, 1000, 1, 9999, 999, 86400, 167, 0, 17,
            1, 1, 100, 25, 100, 0, 0, 1, 2, 0, 0,
        ]);
        $this->assertBoth(ApiUserResolver::class, [
            str_repeat('a', 31)."\t\t0\t1\t{$max}\t{$max}\t1",
            str_repeat('a', 65)."\t\t0\t1\t1{$max}\t0\t1",
        ], [
            2, 0, 31, 4, 1, 5, 0, 0,
            2, 0, 65, 4, 1, 0, 35, 1,
        ]);
        $this->assertBoth(ApiV1Presenter::class, [
            "json\tsearch\t".str_repeat('a', 40)."\t".str_repeat('&', 1000)."\t{$max}\t{$max}\t".implode('|', array_fill(0, 500, 'size=1')),
            "xml\tdetails\t".str_repeat('a', 41)."\t\t1{$max}\t0\t",
        ], null);
    }

    /**
     * Non-ASCII bytes (valid and invalid UTF-8) never count as ASCII letters, digits, separators or whitespace.
     */
    public function test_unicode_records(): void
    {
        $this->requireFfi(ApiV1Presenter::class);
        $this->assertBoth(ApiV1Presenter::class, [
            "\xc3\xa9\tsearch\t\t\xc3\xa9t\xc3\xa9 \xf0\x9f\x98\x80\t\t\t\xc3\xa9=\xc3\xa9",
            "json\tsearch\t\t\xed\xa0\x80\t\t\t",
            "json\tsearch\t\t\xf4\x90\x80\x80\t\t\t",
            "json\tsearch\t\t\xc0\xaf\t\t\t",
            "json\tsearch\t\t\xe2\x80\xa8\xc2\xa0\t\xc2\xa01\t\t",
        ], null);
        $this->assertBoth(ApiCapabilitiesService::class, ["\xc2\xa01\t\t\tsearch\xc2\xa0\tq\xc2\xa0,\xc3\xa9\t\xd9\xa1"], null);
        $this->assertBoth(ApiReleaseRowCache::class, ["v\xc3\xa9\t\xc3\xa9\t\xc3\xa9=\xc3\xa9&\xc3\xa8=1\t\t\t\t\t\t\t"], null);
        $this->assertBoth(ApiUsageService::class, ["\xc3\x9cSER\t\xc3\xa9\t\xd9\xa1"], null);
        $this->assertBoth(ApiUserResolver::class, ["\xc3\xa9t\xc3\xa9\t\t0\t1\t0\t0\tUSER", "\t\xff\t\t\t\t\tadm\xc4\xb1n"], null);
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
            $records[] = self::firstRecords($class)[$i % 3].($i % 7 === 0 ? (string) $i : '');
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
        $this->assertSame($class::{$method.'Php'}($records), $class::withW65RustFfi(true, static fn () => $class::{$method}($records)), $class);
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
        $records = ["\0", "\t\0\t", self::firstRecords($class)[0]."\0"];
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
        $out = $ffi->new('int64_t[64]');
        $small = $ffi->new('int64_t[1]');
        $small[0] = 7;
        $call = static fn (int $count, int $flags, string $data, string $ints, mixed $out, int $cap): int => $ffi->{$function}($count, $flags, $data, \strlen($data), $ints, \strlen($ints), $out, $cap);

        $this->assertSame($needed, $call($count, 0, $data, '', $out, 64), $function);
        $this->assertSame(-$needed, $call($count, 0, $data, '', $small, 1), $function);
        $this->assertSame(7, $small[0], "{$function} must not write on a capacity error");
        $this->assertSame(-$needed, $call($count, 0, $data, '', null, 0), $function);
        $this->assertSame(-1, $call($count, 0, $data, '', null, 4), $function);
        $this->assertSame(-1, $call($count, 1, $data, '', $out, 64), "{$function} flags");
        $this->assertSame(-1, $call($count, -1, $data, '', $out, 64), "{$function} flags");
        $this->assertSame(-1, $call($count + 1, 0, $data, '', $out, 64), "{$function} record count");
        $this->assertSame(-1, $call($count - 1, 0, $data, '', $out, 64), "{$function} record count");
        $this->assertSame(-1, $call(0, 0, $data, '', $out, 64), "{$function} record count");
        $this->assertSame(-1, $call($count, 0, $data, pack('P', 1), $out, 64), "{$function} int table");
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
        mt_srand(6551 + crc32($class) % 1000);
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
        if (getenv('RUN_RUST_FFI_BENCHMARKS') !== '1') {
            $this->markTestSkipped('Set RUN_RUST_FFI_BENCHMARKS=1 to run the Wave 65.5 paired benchmark.');
        }
        $this->requireFfi(ApiCapabilitiesService::class);
        mt_srand(6552);
        $workloads = [];
        $floors = [];

        foreach (['common' => 320, 'uncommon' => 384] as $name => $count) {
            $odd = $name === 'uncommon';
            $capabilities = $cache = $usage = $resolver = $presenter = [];
            $functions = ['search', 'tv-search', 'movie-search', 'audio-search', 'book-search', 'anime-search'];
            for ($i = 0; $i < $count; $i++) {
                $hash = md5((string) $i);
                $categories = implode('|', array_map(static fn (int $n): string => $n % 4 === 0 ? (string) (1000 * ($n / 4 + 1)) : (1000 * (intdiv($n, 4) + 1) + $n).':'.(1000 * (intdiv($n, 4) + 1)), range(0, 11 + $i % 8)));

                $capabilities[] = $odd
                    ? match ($i % 5) {
                        0 => " 2 \t 0 \t999999999999999\t tv-search \t".str_repeat(' id , vid ,, tvdbid , Q , cursor , bogus ,', 4)."\t".$categories.'|x|5:|:7|99:12345',
                        1 => '',
                        2 => "\xc3\xa9\t-1\t\xff\tsearch\xc2\xa0\tq,cat\textra",
                        3 => "3\t100\t50\tunknown-search\t".implode(',', array_fill(0, 30, 'q'))."\t".implode('|', range(1, 40)),
                        default => "{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}",
                    }
                : (1 + $i % 2)."\t".(100 + $i % 5 * 50)."\t".(25 + $i % 4 * 25)."\t".$functions[$i % 6]."\t".['q,cat,limit,offset', 'q,season,ep,tvdbid,imdbid,cat', 'imdbid,cat,limit,attrs,extended', 'id,cat,cursor,sort', 'q,author,title,group'][$i % 5]."\t".$categories;

                $cache[] = $odd
                    ? match ($i % 5) {
                        0 => " v1 \t search \t".implode('&', array_map(static fn (int $n): string => ' k'.(($n * 7919) % 97).' = '.($n % 3 === 0 ? '' : "v{$n}\xc3\xa9"), range(0, 40)))."&&noeq&=x\t0\t999999999999999\t1\t{$i}\t999999999999999\t1\t1",
                        1 => '',
                        2 => "\xff\t\xc3\xa9\t&&&\tx\t-1\t\t\t\t\t\textra",
                        3 => "v2\tmovies\tq=a&q=a&Q=b&q=\t5\t\t\t5\t65536\t65535\t9",
                        default => "{$i}\t{$i}\t{$i}=\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}",
                    }
                : ['v1', 'v2'][$i % 2]."\t".['search', 'tv', 'movies', 'music'][$i % 4]."\t"."t=search&q=release{$i}&cat=".(2000 + $i % 9 * 10).'&limit=100&offset='.($i * 100).'&extended=1&apikey='.$hash."\t600\t60\t900\t".($i * 7 % 1200)."\t".(4096 + $i * 333)."\t1048576\t".($i % 3 === 0 ? 1 : 0);

                $usage[] = $odd
                    ? match ($i % 5) {
                        0 => " MODERATOR \t get \t999999999999999\t999999999999999\t\t\t999999999999999\t999999999999999",
                        1 => '',
                        2 => "\xc3\xa9\tbogus\t-1\t\xff\t\t\t\t\textra",
                        3 => "disabled\tnzb\t5\t5\t10\t10\t86400\t0",
                        default => "{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}",
                    }
                : ['user', 'Admin', '1', 'moderator', '2'][$i % 5]."\t".['api', 'get', '', 'nzb'][$i % 4]."\t".($i * 3 % 120)."\t".($i % 30)."\t".($i % 7 === 0 ? '' : (string) (100 + $i % 3 * 50))."\t".($i % 5 === 0 ? '' : '25')."\t".($i * 271 % 90000)."\t".($i % 6);

                $token = $odd ? strtoupper($hash) : $hash;
                $resolver[] = $odd
                    ? match ($i % 5) {
                        0 => " {$token} \t\t 0 \t 1 \t999999999999999\t999999999999999\t MODERATOR ",
                        1 => '',
                        2 => "\t\xc3\xa9t\xc3\xa9\x01\t-1\t\xff\t\t\tbogus\textra",
                        3 => "\t".hash('sha256', (string) $i)."\t0\t0\t10\t20\tdisabled",
                        default => "{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}",
                    }
                : ($i % 3 === 0 ? "\t{$hash}" : "{$hash}\t")."\t0\t".($i % 4 === 0 ? 0 : 1)."\t".($i % 9 === 0 ? 1700000000 : 0)."\t1750000000\t".['user', 'admin', '1', 'moderator'][$i % 4];

                $attrs = implode('|', array_map(static fn (int $n): string => ['size', 'category', 'guid', 'files', 'poster', 'grabs', 'comments', 'usenetdate', 'tvdbid', 'imdb', 'season', 'episode'][$n % 12].'='.($n % 3 === 0 ? "Some Poster <poster{$n}@example.com>" : (string) ($n * 977)), range(0, 9 + $i % 6)));
                $presenter[] = $odd
                    ? match ($i % 5) {
                        0 => " JSON \t tvsearch \t".strtoupper($hash)."\t".str_repeat("Show & \"Tell\" <S01E0{$i}> \xc3\xa9\t", 1).'x'."\t999999999999999\t5000\t".$attrs.'|=x|noeq|a\\b="c"',
                        1 => '',
                        2 => "yaml\tbogus\t{$hash}\t\xff\xfeBroken\x01\x1f Title\t-1\t\xc3\xa9\t",
                        3 => "xml\tcaps\t\t\t\t\t",
                        default => "{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}",
                    }
                : ['xml', 'json'][$i % 2]."\t".['search', 'tvsearch', 'movie', 'music', 'book'][$i % 5]."\t".$hash."\tSome.Release.Name.{$i}.1080p.WEB-DL.x264-GROUP\t".(1_000_000 + $i * 7919)."\t".(2000 + $i % 9 * 10)."\t".$attrs;
            }

            $workloads[ApiCapabilitiesService::class][$name] = static fn (bool $rust) => $rust ? ApiCapabilitiesService::w65ApiCapabilitiesPlanBatchNative($capabilities) : ApiCapabilitiesService::w65ApiCapabilitiesPlanBatchPhp($capabilities);
            $workloads[ApiReleaseRowCache::class][$name] = static fn (bool $rust) => $rust ? ApiReleaseRowCache::w65ApiReleaseCachePlanBatchNative($cache) : ApiReleaseRowCache::w65ApiReleaseCachePlanBatchPhp($cache);
            $workloads[ApiUsageService::class][$name] = static fn (bool $rust) => $rust ? ApiUsageService::w65ApiUsagePlanBatchNative($usage) : ApiUsageService::w65ApiUsagePlanBatchPhp($usage);
            $workloads[ApiUserResolver::class][$name] = static fn (bool $rust) => $rust ? ApiUserResolver::w65ApiUserResolverPlanBatchNative($resolver) : ApiUserResolver::w65ApiUserResolverPlanBatchPhp($resolver);
            $workloads[ApiV1Presenter::class][$name] = static fn (bool $rust) => $rust ? ApiV1Presenter::w65ApiV1PresenterPlanBatchNative($presenter) : ApiV1Presenter::w65ApiV1PresenterPlanBatchPhp($presenter);
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
                "\n[Wave 65.5 benchmark] %s: common mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx | uncommon mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx",
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
            $this->assertSame(self::DEFAULTS[$class], $class::usesW65RustFfi(), $class);
            $this->assertSame(min(self::QUALIFICATION[$class]) >= 1.5, $class::usesW65RustFfi(), $class);
            if ($class::usesW65RustFfi()) {
                $this->assertGreaterThanOrEqual(1.5, $floor, \sprintf('%s: a Rust default must clear the 1.5x gate (min ratio %.2fx)', $class, $floor));
            } else {
                $this->assertFalse($class::usesW65RustFfi(), "{$class} retains PHP by default");
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
        $this->assertCount(self::WIDTHS[$class] * \count($records), $php, $class);
        $this->assertSame($php, $class::{$method.'Native'}($records), "{$class} native ".var_export($records, true));
        $this->assertSame($php, $class::withW65RustFfi(true, static fn () => $class::{$method}($records)), "{$class} Rust route");
        $this->assertSame($php, $class::withW65RustFfi(false, static fn () => $class::{$method}($records)), "{$class} PHP route");
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
            ApiCapabilitiesService::class => ["1\t100\t50\ttv-search\tq,season,ep,bogus\t5000|5030:5000|5040:7000", "x\t0\t\tnope", ''],
            ApiReleaseRowCache::class => ["v1\tsearch\tt=search&q=abc&cat=2000\t600\t60\t900\t30\t4096\t1048576\t0", "v2\tx\tnoeq&k=\tx", ''],
            ApiUsageService::class => ["user\tapi\t10\t2\t100\t25\t3600\t0", "bogus\tget\tx", ''],
            ApiUserResolver::class => ["0123456789abcdef0123456789abcdef\t\t0\t1\t0\t0\tuser", "\tkey with space\tx", ''],
            ApiV1Presenter::class => ["xml\tsearch\t0123456789abcdef0123456789abcdef\tA & B\t1024\t2000\tsize=1024|grabs=3", "yaml\tnope\tzz", ''],
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
        $number = static fn (): string => $pick(['', '', ' ', '0', '1', '2', '3', '4', '5', '9', '10', ' 5', '5 ', '00', '007', '-1', '+1', '1.5', 'x', '59', '60', '61', '99', '100', '101', '600', '900', '3599', '86399', '86400', '86401', '65535', '65536', '1700000000', (string) mt_rand(0, 1_000_000_000), (string) mt_rand(0, 2000), '999999999999999', '1000000000000000', self::randomBytes(3)]);
        $cut = static fn (array $fields, int $expected): array => \array_slice($fields, 0, mt_rand(0, 3) === 0 ? mt_rand(0, $expected + 1) : $expected);
        $role = static fn (): string => $pick(['', ' ', 'user', 'User', 'USER', ' admin ', 'Admin', 'disabled', 'Disabled', 'moderator', 'MODERATOR', 'mod', '0', '1', '2', '3', '4', '5', '01', '-1', "user\xc2\xa0", "\xc3\xa9", self::randomBytes(4)]);
        $params = ['q', 'id', 'group', 'minsize', 'maxsize', 'maxage', 'cat', 'limit', 'offset', 'attrs', 'extended', 'del', 'sort', 'vid', 'tvdbid', 'traktid', 'rid', 'tvmazeid', 'imdbid', 'tmdbid', 'season', 'ep', 'genre', 'title', 'author', 'anidbid', 'anilistid', 'cursor', 'Q', 'CAT', 'bogus', '0', ''];

        return match ($class) {
            ApiCapabilitiesService::class => implode("\t", $cut([
                $pick(['', ' ', '1', '2', ' 2 ', '0', '3', '01', 'x', '-1', '999999999999999']),
                $number(), $number(),
                $pick(['', ' ', 'search', 'tv-search', 'movie-search', 'audio-search', 'book-search', 'anime-search', ' movie-search ', 'Search', 'tvsearch', '0', 'bogus', self::randomBytes(4)]),
                $join($pick([',', ', ', ' ,', ',,']), 24, static fn (): string => $pick(['', ' ', "\t"]).$pick($params).$pick(['', ' ', "\n"])),
                $join('|', 16, static fn (): string => $pick(['', ':', ' ']).$pick(['1000', '2000', '5000', '5030', '0', '7', (string) mt_rand(0, 9000), '999999999999999', '1000000000000000', 'x', ' 1']).$pick(['', '', ':1000', ':2000', ':5000', ':0', ':', ':x', ': 5', ':5:5', ':999999999999999'])),
                'extra',
            ], 6)),
            ApiReleaseRowCache::class => implode("\t", $cut([
                $pick(['', 'v1', 'v2', ' v1 ', "v\xc3\xa9", self::randomBytes(3)]),
                $pick(['', 'search', 'tv', 'movies', ' music ', 'a:b', self::randomBytes(4)]),
                $join('&', 14, static fn (): string => $pick(['', ' ', "\t"]).$pick(['q', 'cat', 'limit', 'offset', 't', 'Q', 'a', 'a!', 'ab', 'a=b', 'é', '', ' ', 'x y']).$pick(['=', '=', '=', ' = ', '', '==']).$pick(['', 'abc', ' 1 ', '2000', 'a=b', "\xc3\xa9", 'x&y', (string) mt_rand(0, 100000), self::randomBytes(3)])),
                $number(), $number(), $number(), $number(), $number(), $number(),
                $pick(['', '0', '1', ' 1 ', '00', '2', 'x']),
                'extra',
            ], 10)),
            ApiUsageService::class => implode("\t", $cut([
                $role(),
                $pick(['', ' ', 'api', ' api ', 'get', 'nzb', 'GET', 'Api', 'bogus', self::randomBytes(3)]),
                $number(), $number(), $number(), $number(), $number(), $number(),
                'extra',
            ], 8)),
            ApiUserResolver::class => implode("\t", $cut([
                $pick(['', '', ' ', '  ', md5((string) mt_rand()), strtoupper(md5((string) mt_rand())), sha1((string) mt_rand()), hash('sha256', (string) mt_rand()), substr(md5((string) mt_rand()), 0, 31), 'abc', 'key-with_symbols!~', 'with space', "\xc3\xa9t\xc3\xa9", "\x01ctrl", "\x7f", self::randomBytes(8)]),
                $pick(['', '', ' ', md5((string) mt_rand()), sha1((string) mt_rand()), 'apikey', "\xff", self::randomBytes(6)]),
                $number(), $number(), $number(), $number(),
                $role(),
                'extra',
            ], 7)),
            ApiV1Presenter::class => implode("\t", $cut([
                $pick(['', ' ', 'xml', 'XML', ' json ', 'Json', 'yaml', "json\xc2\xa0", self::randomBytes(3)]),
                $pick(['', ' ', 'caps', 'search', ' tvsearch ', 'movie', 'music', 'book', 'details', 'Caps', 'get', self::randomBytes(3)]),
                $pick(['', md5((string) mt_rand()), sha1((string) mt_rand()), strtoupper(md5((string) mt_rand())), substr(sha1((string) mt_rand()), 0, 39), 'zz', self::randomBytes(4)]),
                $join($pick(['', ' ', '.', '&', '"']), 6, static fn (): string => $pick(['Show', 'S01E02', '&', '<b>', '"x"', "'y'", '\\', '/', "\n", "\x01", "\x1f", "\x7f", "\xc3\xa9", "\xc3", "\xe2\x80\xa8", "\xed\xa0\x80", "\xf0\x9f\x98\x80", "\xf4\x90\x80\x80", "\xc0\xaf", self::randomBytes(4)])),
                $number(), $number(),
                $join('|', 10, static fn (): string => $pick(['size', 'category', 'guid', 'files', 'poster', 'grabs', 'tvdbid', 'imdb', 'Size', 'custom', '', 'a"b', "\x01"]).$pick(['=', '=', '', '==']).$pick(['', '1', 'a&b', '<x>', '"q"', "\xc3\xa9", "\n", (string) mt_rand(0, 99999)])),
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
     * Invokes the class's private `w65RustFfiState()`.
     *
     * @param  class-string  $class
     */
    private static function state(string $class, ?string $op, mixed $val): mixed
    {
        return (new ReflectionMethod($class, 'w65RustFfiState'))->invoke(null, $op, $val);
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
     * Resolves the class's Wave 65.5 FFI handle or skips the test without the library.
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
        $ffi = \is_string($cdef) ? RustPipelineLibrary::resolveDomain('w65_5', $cdef, \is_string($path) && $path !== '' ? $path : null) : null;
        if (! $ffi instanceof FFI) {
            $this->markTestSkipped('Rust pipeline library not built.');
        }

        return $ffi;
    }
}
