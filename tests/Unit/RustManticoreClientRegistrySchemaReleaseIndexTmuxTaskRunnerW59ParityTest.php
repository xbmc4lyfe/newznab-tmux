<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Search\Support\ManticoreClientFactory;
use App\Services\Search\Support\ManticoreIndexRegistry;
use App\Services\Search\Support\ManticoreSchemaInspector;
use App\Services\Search\Support\ReleaseIndexProjection;
use App\Services\Tmux\TmuxTaskRunner;
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
 * `NNTMUX_W595_BENCH=1`): per helper, 200 interleaved samples per workload after 20 warmup rounds,
 * per-sample random PHP/Rust order, each side the fastest of three back-to-back calls, release dylib.
 * Common workloads hold 320 records and uncommon ones 384 records with edge cases. Timings include
 * PHP-side packing, the FFI call and output decoding; each run prints its ratios to STDERR.
 *
 * A helper only defaults to Rust when every recorded common and uncommon mean and p99 ratio reaches 1.5x
 * (QUALIFICATION); each benchmark run asserts that the Rust defaults still clear 1.5x.
 */
class RustManticoreClientRegistrySchemaReleaseIndexTmuxTaskRunnerW59ParityTest extends TestCase
{
    /**
     * Batch method per class; each has `<method>Php` and `<method>Native` variants.
     *
     * @var array<class-string, string>
     */
    private const METHODS = [
        ManticoreClientFactory::class => 'endpointPlanBatch',
        ManticoreIndexRegistry::class => 'tableResolvePlanBatch',
        ManticoreSchemaInspector::class => 'describePlanBatch',
        ReleaseIndexProjection::class => 'projectionPlanBatch',
        TmuxTaskRunner::class => 'taskPlanBatch',
    ];

    /**
     * C entry point per class.
     *
     * @var array<class-string, string>
     */
    private const FUNCTIONS = [
        ManticoreClientFactory::class => 'nntmux_w595_endpoint_plan',
        ManticoreIndexRegistry::class => 'nntmux_w595_index_resolve_plan',
        ManticoreSchemaInspector::class => 'nntmux_w595_schema_describe_plan',
        ReleaseIndexProjection::class => 'nntmux_w595_release_projection_plan',
        TmuxTaskRunner::class => 'nntmux_w595_task_runner_plan',
    ];

    /**
     * Output words per record.
     *
     * @var array<class-string, int>
     */
    private const WIDTHS = [
        ManticoreClientFactory::class => 8,
        ManticoreIndexRegistry::class => 6,
        ManticoreSchemaInspector::class => 7,
        ReleaseIndexProjection::class => 9,
        TmuxTaskRunner::class => 6,
    ];

    /**
     * Benchmark-qualified defaults (see the class docblock for the gate).
     *
     * @var array<class-string, bool>
     */
    private const DEFAULTS = [
        ManticoreClientFactory::class => true,
        ManticoreIndexRegistry::class => true,
        ManticoreSchemaInspector::class => true,
        ReleaseIndexProjection::class => true,
        TmuxTaskRunner::class => true,
    ];

    /**
     * Common mean/p99 then uncommon mean/p99 speedups (PHP / Rust), the per-metric minimum of three
     * `NNTMUX_W595_BENCH=1` runs (200 samples per workload, seed 5952, release Rust, PHP CLI without
     * opcache, Apple Silicon macOS on a shared host).
     *
     * @var array<class-string, array{float, float, float, float}>
     */
    private const QUALIFICATION = [
        ManticoreClientFactory::class => [4.36, 4.00, 4.99, 4.79],
        ManticoreIndexRegistry::class => [4.12, 4.12, 2.93, 2.86],
        ManticoreSchemaInspector::class => [3.73, 2.44, 3.71, 3.14],
        ReleaseIndexProjection::class => [5.61, 4.26, 6.02, 6.22],
        TmuxTaskRunner::class => [4.54, 4.50, 5.55, 4.85],
    ];

    /** Back-to-back calls per side of a sample; the fastest is kept (drops preemption spikes). */
    private const REPEATS = 3;

    private const RANDOM_ROUNDS = 400;

    private const BENCH_SAMPLES = 200;

    private const BYTES = ['a', 'Z', 'f', '0', '1', '9', ' ', "\t", "\n", "\r", "\x0b", "\x0c", "\x01", "\x7f", '-', '_', '.', ',', '=', ':', '/', '\\', "\xc3\xa9", "\xc3", "\xff", "\0"];

    /** Fragments glued into otherwise structured records so separators and keywords actually occur. */
    private const FRAGMENTS = ["\t", "\n", ',', ':', '.', '/', '://', '_rt', 'nn_', '-', '', ' ', '0', '7', '65535', '65536', '86401', '1234567890123456789', '999999999999999999', 'x', 'text', 'TEXT', 'integer', 'uint', 'attribute', 'https', 'HTTP', 'releases', 'predb', 'true', 'off', "\x01"];

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
        foreach (['usesW59RustFfi', 'withW59RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
            $this->assertTrue($reflection->hasMethod($name), "{$class}::{$name}");
            $this->assertTrue($reflection->getMethod($name)->isStatic() && $reflection->getMethod($name)->isPublic(), "{$class}::{$name}");
        }
        $this->assertTrue($reflection->getMethod('w59RustFfiState')->isPrivate(), "{$class} keeps its W59 state private");
        $constant = new ReflectionClassConstant($class, 'W59_RUST_FFI_DEFAULT');
        $this->assertTrue($constant->isPublic(), "{$class} default must be public");
        $this->assertSame(self::DEFAULTS[$class], $constant->getValue(), "{$class} default must match the benchmark evidence");
        $this->assertSame(min(self::QUALIFICATION[$class]) >= 1.5, self::DEFAULTS[$class], "{$class} default must follow its recorded qualification");
        $this->assertSame(self::DEFAULTS[$class], $class::usesW59RustFfi());

        foreach ([true, false] as $enabled) {
            $this->assertSame([$enabled, 'ok'], $class::withW59RustFfi($enabled, static fn () => [$class::usesW59RustFfi(), 'ok']));
            $this->assertSame(self::DEFAULTS[$class], $class::usesW59RustFfi(), "{$class} must restore after the callback");
        }
        $nested = $class::withW59RustFfi(false, static fn () => [$class::withW59RustFfi(true, static fn () => $class::usesW59RustFfi()), $class::usesW59RustFfi()]);
        $this->assertSame([true, false], $nested);
        $caught = null;
        try {
            $class::withW59RustFfi(! $class::usesW59RustFfi(), static fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException $exception) {
            $caught = $exception->getMessage();
        }
        $this->assertSame('boom', $caught, 'The callback exception must propagate.');
        $this->assertSame(self::DEFAULTS[$class], $class::usesW59RustFfi(), "{$class} must restore after an exception");

        $class::withW59RustFfi(! $class::usesW59RustFfi(), function () use ($class): void {
            foreach (array_keys(self::METHODS) as $other) {
                if ($other !== $class) {
                    $this->assertSame(self::DEFAULTS[$other], $other::usesW59RustFfi(), "{$class} leaked into {$other}");
                }
            }
        });
    }

    public function test_w59_state_adds_no_static_properties(): void
    {
        foreach (array_keys(self::METHODS) as $class) {
            foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_STATIC) as $property) {
                $this->assertStringNotContainsStringIgnoringCase('w59', $property->getName(), $class);
            }
        }
    }

    public function test_earlier_wave_toggles_are_untouched(): void
    {
        $snapshot = static fn (): array => [
            ManticoreClientFactory::usesRustFfi(),
            ManticoreClientFactory::usesW425RustFfi(),
            ManticoreIndexRegistry::usesW425RustFfi(),
            ManticoreSchemaInspector::usesRustFfi(),
            ManticoreSchemaInspector::usesW43RustFfi(),
            ReleaseIndexProjection::usesW405RustFfi(),
            TmuxTaskRunner::usesRustFfi(),
        ];
        $before = $snapshot();
        $this->assertSame([false, false, false, true, false, false, false], $before);
        foreach (array_keys(self::METHODS) as $class) {
            foreach ([true, false] as $enabled) {
                $this->assertSame($before, $class::withW59RustFfi($enabled, $snapshot), $class);
            }
        }
        $this->assertSame($before, $snapshot());
    }

    public function test_existing_public_api_is_preserved(): void
    {
        foreach ([
            ManticoreClientFactory::class => ['make', 'clientConfig', 'guzzleOptions', 'parseHost', 'parseHostNative', 'parseHostPhp', 'normalizeHosts', 'normalizeHostsNative', 'normalizeHostsPhp', 'summarizeHostListBatch', 'setUseRustFfi', 'factoryFfi'],
            ManticoreIndexRegistry::class => ['definitions', 'profile', 'autocompleteFields', 'inspectableSettings', 'logicalName', 'logicalNameNative', 'logicalNamePhp', 'defaultTableNames', 'summarizeTableNameBatch'],
            ManticoreSchemaInspector::class => ['missingSettings', 'missingSettingsNative', 'missingSettingsPhp', 'compareColumns', 'compareColumnsNative', 'compareColumnsPhp', 'summarizeSettingsBlobBatch', 'evaluateW43Native'],
            ReleaseIndexProjection::class => ['query', 'aggregateExpressions', 'aggregateExpressionsPhp', 'aggregateExpressionsNative', 'forId', 'summarizeHydrationBatchCompleteness'],
            TmuxTaskRunner::class => ['runTask', 'buildCommand', 'buildCommandNative', 'buildCommandPhp', 'beginCycle', 'batchCommandNative', 'batchCommandPhp', 'drainGuardNative', 'drainGuardPhp', 'runPaneTask', 'setUseRustFfi', 'usesRustFfi'],
        ] as $class => $methods) {
            $reflection = new ReflectionClass($class);
            foreach ($methods as $name) {
                $this->assertTrue($reflection->hasMethod($name) && $reflection->getMethod($name)->isPublic(), "{$class}::{$name}");
            }
        }

        $config = ['host' => 'https://search.local:9443/api', 'retries' => 0, 'timeout' => 3];
        $expected = ManticoreClientFactory::clientConfig($config);
        $this->assertSame(['host' => 'search.local', 'port' => 9443, 'scheme' => 'https', 'path' => '/api', 'transport' => 'Https', 'timeout' => 3], $expected);
        $columns = ['id' => ['type' => 'bigint'], 'name' => ['type' => 'text']];
        $compare = ManticoreSchemaInspector::compareColumnsPhp([['Field' => 'name', 'Type' => 'string']], $columns);
        foreach (array_keys(self::METHODS) as $class) {
            foreach ([true, false] as $enabled) {
                $this->assertSame($expected, $class::withW59RustFfi($enabled, static fn () => ManticoreClientFactory::clientConfig($config)));
                $this->assertSame('releases', $class::withW59RustFfi($enabled, static fn () => ManticoreIndexRegistry::logicalNamePhp('releases_rt')));
                $this->assertSame($compare, $class::withW59RustFfi($enabled, static fn () => ManticoreSchemaInspector::compareColumns([['Field' => 'name', 'Type' => 'string']], $columns)));
                $this->assertSame(6, \count($class::withW59RustFfi($enabled, static fn () => ReleaseIndexProjection::aggregateExpressionsPhp(true))));
            }
        }
        $this->assertSame(['missing' => ['id'], 'incompatible' => ['name' => ['expected' => 'text', 'actual' => 'string']]], $compare);
    }

    public function test_registry_header_follows_definitions(): void
    {
        $logicals = array_map('strval', array_keys(ManticoreIndexRegistry::definitions()));
        $registry = self::state(ManticoreIndexRegistry::class, 'registry', null);
        $this->assertIsArray($registry);
        $this->assertSame(implode("\n", $logicals), $registry[0]);
        $this->assertSame(array_combine($logicals, range(1, \count($logicals))), $registry[1]);
        foreach ($logicals as $position => $logical) {
            $words = ManticoreIndexRegistry::tableResolvePlanBatchPhp([$logical."_rt\t"]);
            $this->assertSame($position + 1, $words[1], $logical);
            $this->assertSame($logical, ManticoreIndexRegistry::logicalNamePhp($logical.'_rt'));
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
        $this->assertSame($php, $class::withW59RustFfi(false, static fn () => $class::{$method}($records)), $class);
        $this->assertSame($php, $class::withW59RustFfi(true, static fn () => $class::{$method}($records)), $class);
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

    public function test_endpoint_plan_examples(): void
    {
        $this->requireFfi(ManticoreClientFactory::class);
        $this->assertBoth(ManticoreClientFactory::class, [
            "https://search.local:9443/\t\t30\t3",
            '',
            " manticore \t9312\t0\t",
            "ftp://bad host:x/path\t70000\tx\t50",
            "127.0.0.1:99999\t9308\t999\tx\textra",
            "HTTP://a.b\t 1 \t300\t0",
            "a://b://c:1\t\t\t1",
        ], [
            2, 1, 12, 9443, 30, 3, 700, 0,
            0, 0, 0, 9308, 5, 2, 300, 1025,
            0, 0, 9, 9312, 1, 2, 300, 128,
            3, 0, 8, 9308, 5, 10, 13100, 638,
            0, 0, 9, 9308, 300, 2, 300, 1412,
            1, 0, 3, 1, 300, 1, 100, 0,
            3, 0, 1, 9308, 5, 1, 100, 28,
        ]);
        // Scheme, host and port agree with parseHostPhp() for plain hosts.
        foreach (['https://search.local:9443', 'manticore', 'http://10.0.0.1:9312', 'search-1.local:9308'] as $host) {
            $words = ManticoreClientFactory::endpointPlanBatchNative([$host."\t9308\t\t"]);
            $parsed = ManticoreClientFactory::parseHostPhp(['port' => 9308], $host);
            $this->assertSame($parsed['port'], $words[3], $host);
            $this->assertSame(\strlen($parsed['host']), $words[2], $host);
            $this->assertSame($parsed['scheme'] === 'https', $words[1] === 1, $host);
        }
    }

    public function test_table_resolve_plan_examples(): void
    {
        $this->requireFfi(ManticoreIndexRegistry::class);
        $hash = static fn (string $text): int => (int) hexdec(hash('fnv1a32', $text));
        $this->assertBoth(ManticoreIndexRegistry::class, [
            "releases_rt\t",
            "nn_predb_rt\tnn_",
            "movies\t",
            "_rt\t",
            "Bogus\tBAD-",
            "tv_rt\tx\ty",
            '',
            "anime_rt\t".str_repeat('p', 33),
        ], [
            2, 1, 8, 11, $hash('releases_rt'), 0,
            2, 2, 5, 11, $hash('nn_predb_rt'), 1,
            1, 3, 6, 9, $hash('movies_rt'), 0,
            0, 0, 0, 0, 0, 4,
            1, 0, 5, 0, 0, 26,
            2, 0, 2, 0, 0, 34,
            0, 0, 0, 0, 0, 36,
            2, 10, 5, 41, $hash(str_repeat('p', 33).'anime_rt'), 8,
        ]);
    }

    public function test_describe_plan_examples(): void
    {
        $this->requireFfi(ManticoreSchemaInspector::class);
        $this->assertBoth(ManticoreSchemaInspector::class, [
            "id:bigint,name:text,size:integer,gone:string,bad\nid\tbigint\t\nname\tTEXT\tindexed stored\nsize\tuint\t\nname\ttext\tindexed attribute\nlonely\n\tstring",
            '',
            "a:integer\na\tbigint",
            ",,\n\n\nx\tstring\tATTRIBUTE",
        ], [
            4, 2, 3, 1, 0, 1, 15,
            0, 0, 0, 0, 0, 0, 16,
            1, 0, 1, 0, 1, 0, 0,
            1, 0, 1, 0, 0, 0, 0,
        ]);
        // Missing and incompatible counts agree with compareColumnsPhp() for distinct fields.
        $definitions = ManticoreIndexRegistry::definitions()['releases']['columns'];
        $rows = [];
        $describe = ['id' => 'bigint'];
        foreach (\array_slice($definitions, 0, 20, true) as $name => $definition) {
            $describe[$name] = match ($definition['type']) {
                'integer' => 'uint',
                'string' => $name === 'guid' ? 'text' : 'string',
                default => $definition['type'],
            };
        }
        foreach ($describe as $name => $type) {
            $rows[] = ['Field' => $name, 'Type' => $type];
        }
        $compare = ManticoreSchemaInspector::compareColumnsPhp($rows, $definitions);
        $spec = implode(',', array_map(static fn (string $name, array $definition): string => $name.':'.$definition['type'], array_keys($definitions), $definitions));
        $record = $spec."\n".implode("\n", array_map(static fn (array $row): string => $row['Field']."\t".$row['Type']."\t", $rows));
        $words = ManticoreSchemaInspector::describePlanBatchNative([$record]);
        $this->assertSame([\count($compare['missing']), \count($compare['incompatible'])], [$words[3], $words[4]]);
        $this->assertSame(1, $words[4], 'guid is the only incompatible column');
    }

    public function test_projection_plan_examples(): void
    {
        $this->requireFfi(ReleaseIndexProjection::class);
        $this->assertBoth(ReleaseIndexProjection::class, [
            "42\tSome.Movie.2024\t2097152\t1700000000\t1700000100\t55\t2040\t0",
            '',
            "7\t x \t-5\t9999999999\t100\t3000000000\t-1\t1",
            "x\t\t1.5\t\t\t\t\t\textra",
            "-0\tA\t999999999999999999\t-1\t0\t-7\t0\t-3",
        ], [
            42, 15, 3, 2097152, 1700000000, 1700000100, 55, 50, 0,
            0, 0, 0, 0, 0, 0, 0, 0, 259,
            7, 1, 1, 0, 100, 100, 2147483647, 60, 124,
            0, 0, 0, 0, 0, 0, 0, 0, 387,
            0, 1, 1, 999999999999999999, 0, 0, 0, 0, 41,
        ]);
    }

    public function test_task_plan_examples(): void
    {
        $this->requireFfi(TmuxTaskRunner::class);
        $this->assertBoth(TmuxTaskRunner::class, [
            "Binaries\tnntmux:0.1\tphp artisan update\t60\t1\t1\t0",
            '',
            "Backfill\t2.3\tcmd\t\tno\t\t",
            "Releases\t1.1\tcmd\t999999\tyes\toff\t",
            "Fix\t:1.1\tcmd\x01\tx\tmaybe\t1\tTRUE",
            "Post\t1.2\tcmd\t5\t1\t1\t1",
            "IRC\ta:b:1234.1\tcmd\t1\t1\t1\t0\textra",
        ], [
            4, 0, 1, 18, 60, 0,
            0, -1, -1, 0, 0, 135,
            2, 2, 3, 3, 0, 0,
            3, 1, 1, 3, 86400, 32,
            0, -1, -1, 4, 0, 90,
            1, 1, 2, 3, 5, 0,
            0, -1, -1, 3, 1, 130,
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
        $this->assertSame($class::{$method.'Php'}($records), $class::withW59RustFfi(true, static fn () => $class::{$method}($records)), $class);
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
        $header = $class === ManticoreIndexRegistry::class;
        $parts = self::firstRecords($class);
        if ($header) {
            array_unshift($parts, "releases\npredb");
        }
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
        mt_srand(5951 + crc32($class) % 1000);
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
        if (getenv('NNTMUX_W595_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_W595_BENCH=1 to run the Wave 59.5 paired benchmark.');
        }
        $this->requireFfi(ManticoreClientFactory::class);
        mt_srand(5952);
        $workloads = [];
        $floors = [];
        $definitions = ManticoreIndexRegistry::definitions();
        $logicals = array_keys($definitions);

        foreach (['common' => 320, 'uncommon' => 384] as $name => $count) {
            $odd = $name === 'uncommon';
            $endpoints = $tables = $schemas = $projections = $tasks = [];
            for ($i = 0; $i < $count; $i++) {
                $endpoints[] = $odd
                    ? match ($i % 5) {
                        0 => "  HTTPS://Search-{$i}.Example.COM:9443/api/v1  \t70000\tx\t50",
                        1 => '',
                        2 => "ftp://bad host {$i}:x/\t9308\t0\t\textra",
                        3 => "[::1]:9308\t-1\t999\t11",
                        default => "127.0.0.{$i}:99999\t 9312 \t".($i % 400)."\t".($i % 13),
                    }
                : ['manticore', 'http://10.0.'.($i % 255).'.1:9308', 'https://search-'.$i.'.local:9443', 'search-'.$i.'.internal:9312'][$i % 4]."\t".['', '9308'][$i % 2]."\t".(5 + $i % 30)."\t".($i % 4);

                $logical = $logicals[$i % \count($logicals)];
                $tables[] = $odd
                    ? match ($i % 5) {
                        0 => strtoupper($logical)."_RT\tBAD-",
                        1 => '',
                        2 => "_rt\t",
                        3 => "nntmux_unknown{$i}_rt\tnntmux_\textra",
                        default => $logical."_rt_rt\t".str_repeat('p', 40),
                    }
                : ['', 'nntmux_'][$i % 2].$logical.['_rt', '', '_rt'][$i % 3]."\t".['', 'nntmux_'][$i % 2];

                $columns = $definitions[$logical]['columns'];
                $rows = ["id\tbigint\t"];
                $spec = ['id:bigint'];
                foreach ($columns as $column => $definition) {
                    $type = $definition['type'];
                    $rows[] = $column."\t".($type === 'integer' ? 'uint' : $type)."\t".($type === 'text' ? 'indexed stored' : '');
                    $spec[] = $column.':'.$type;
                }
                if ($odd) {
                    $rows = match ($i % 5) {
                        0 => [...$rows, ...\array_slice($rows, 1, 4), "lonely\n\tstring", "TITLE\tTEXT\tINDEXED ATTRIBUTE\r"],
                        1 => [],
                        2 => array_map(static fn (string $row): string => strtoupper($row), \array_slice($rows, 0, (int) (\count($rows) / 2))),
                        3 => \array_slice($rows, 3),
                        default => [...$rows, "extra\tjson\tattribute\textra"],
                    };
                    $spec = match ($i % 5) {
                        1 => [],
                        2 => [...$spec, 'bad', ',', 'gone:string'],
                        default => $spec,
                    };
                }
                $schemas[] = implode(',', $spec)."\n".implode("\n", $rows);

                $post = 1_700_000_000 + $i * 60;
                $projections[] = $odd
                    ? match ($i % 5) {
                        0 => "-{$i}\t \t-5\t9999999999\t100\t3000000000\t-1\t1",
                        1 => '',
                        2 => "x\t\t1.5\t\t\t\t\t\textra",
                        3 => ($i + 1)."\t".str_repeat('Long.Release.Name.', 12)."\t999999999999999999\t-1\t0\t-7\t0\t-3",
                        default => ($i + 1)."\tName.{$i}\t 2048 \t".($post + 999)."\t{$post}\t 12 \t".($i % 9)."\t0",
                    }
                : (100000 + $i)."\tSome.Release.Name.S0".($i % 9).'E'.($i % 20).".1080p.WEB-DL.x264-GRP\t".(1048576 * ($i + 1))."\t{$post}\t".($post + 3600)."\t".($i % 500)."\t".(2000 + $i % 80)."\t".($i % 7 === 0 ? 1 : 0);

                $tasks[] = $odd
                    ? match ($i % 5) {
                        0 => "Fix{$i}\t:1.1\tcmd\x01 --flag\tx\tmaybe\t1\tTRUE",
                        1 => '',
                        2 => " \tbad session:1.1\t \t999999\tyes\toff\t\textra",
                        3 => "Releases\tnntmux:1234.1\tnice -n19 php artisan releases\t86401\tNo\tON\tFalse",
                        default => "Post{$i}\tnntmux-".$i.':'.($i % 4).'.'.($i % 10)."\tphp artisan postprocess\t".($i % 600)."\tyes\tno\toff",
                    }
                : ['Binaries', 'Backfill', 'Releases', 'PostProcess'][$i % 4]."\tnntmux:".($i % 3).'.'.($i % 8)."\tnice -n19 php artisan update:binaries --pane={$i}\t".(30 + $i % 300)."\t1\t".($i % 5 === 0 ? '0' : '1')."\t".($i % 2);
            }

            $workloads[ManticoreClientFactory::class][$name] = static fn (bool $rust) => $rust ? ManticoreClientFactory::endpointPlanBatchNative($endpoints) : ManticoreClientFactory::endpointPlanBatchPhp($endpoints);
            $workloads[ManticoreIndexRegistry::class][$name] = static fn (bool $rust) => $rust ? ManticoreIndexRegistry::tableResolvePlanBatchNative($tables) : ManticoreIndexRegistry::tableResolvePlanBatchPhp($tables);
            $workloads[ManticoreSchemaInspector::class][$name] = static fn (bool $rust) => $rust ? ManticoreSchemaInspector::describePlanBatchNative($schemas) : ManticoreSchemaInspector::describePlanBatchPhp($schemas);
            $workloads[ReleaseIndexProjection::class][$name] = static fn (bool $rust) => $rust ? ReleaseIndexProjection::projectionPlanBatchNative($projections) : ReleaseIndexProjection::projectionPlanBatchPhp($projections);
            $workloads[TmuxTaskRunner::class][$name] = static fn (bool $rust) => $rust ? TmuxTaskRunner::taskPlanBatchNative($tasks) : TmuxTaskRunner::taskPlanBatchPhp($tasks);
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
                "\n[Wave 59.5 benchmark] %s: common mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx | uncommon mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx",
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
            $this->assertSame(self::DEFAULTS[$class], $class::usesW59RustFfi(), $class);
            $this->assertSame(min(self::QUALIFICATION[$class]) >= 1.5, $class::usesW59RustFfi(), $class);
            if ($class::usesW59RustFfi()) {
                $this->assertGreaterThanOrEqual(1.5, $floor, \sprintf('%s: a Rust default must clear the 1.5x gate (min ratio %.2fx)', $class, $floor));
            } else {
                $this->assertFalse($class::usesW59RustFfi(), "{$class} retains PHP by default");
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
        $this->assertSame($php, $class::withW59RustFfi(true, static fn () => $class::{$method}($records)), "{$class} Rust route");
        $this->assertSame($php, $class::withW59RustFfi(false, static fn () => $class::{$method}($records)), "{$class} PHP route");
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
            ManticoreClientFactory::class => ["https://search.local:9443\t9308\t5\t2", "bad host:x\tx\t\t", ''],
            ManticoreIndexRegistry::class => ["releases_rt\t", "nn_predb\tnn_", ''],
            ManticoreSchemaInspector::class => ["id:bigint,name:text\nid\tbigint\nname\ttext\tindexed", "x:integer\nx\tuint", ''],
            ReleaseIndexProjection::class => ["1\tSome.Name\t2048\t1700000000\t1700000100\t3\t2040\t0", "x\t\t-1", ''],
            TmuxTaskRunner::class => ["Binaries\tnntmux:0.1\tphp artisan\t60\t1\t1\t0", "Fix\tbad\t\tx", ''],
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
        $number = static fn (): string => $pick(['', '0', '1', '7', '12', '00', '007', '-1', '-0', '-', '1.5', ' 5', '5 ', 'x', '300', '301', '65535', '65536', '86400', '86401', '4096', (string) mt_rand(0, 1_000_000_000), (string) mt_rand(-1_000_000, 5_000_000_000), '4102444800', '4102444801', '999999999999', '1234567890123', '999999999999999999', '-999999999999999999', '1234567890123456789', self::randomBytes(3)]);
        $cut = static fn (array $fields, int $expected): array => \array_slice($fields, 0, mt_rand(0, 3) === 0 ? mt_rand(0, $expected + 1) : $expected);

        return match ($class) {
            ManticoreClientFactory::class => implode("\t", $cut([
                $pick(['', ' ', ' manticore ', 'manticore', '127.0.0.1', '10.0.0.1:9308', 'http://a.b:9312', 'HTTPS://Search.Local:9443/', 'https://s:0', 'https://s:65536', 'ftp://x', '://x', 'a://b://c', 'host:x', 'host:', ':9308', 'bad host', 'h_1-2.x', '[::1]:9308', 'http://u:p@h:1/p', 'h/', 'h//', self::randomBytes(10)])
                    .$pick(['', '', ':'.$number(), '/'.$pick(['', 'api', '/']), self::randomBytes(4)]),
                $number(), $number(), $number(), 'extra',
            ], 4)),
            ManticoreIndexRegistry::class => implode("\t", $cut([
                $pick(['', 'nn_', 'nntmux_', 'NN_', 'x'])
                    .$pick(['releases', 'predb', 'movies', 'tvshows', 'music', 'books', 'games', 'console', 'steam', 'anime', 'Releases', 'tv', '', 'nn_', '_rt', self::randomBytes(5)])
                    .$pick(['', '_rt', '_rt', '_RT', '_rt_rt', '_r', 'rt']),
                $pick(['', '', 'nn_', 'nntmux_', 'NN_', 'bad-', str_repeat('p', 32), str_repeat('p', 33), self::randomBytes(4)]),
                'extra',
            ], 2)),
            ManticoreSchemaInspector::class => $join(',', 6, static fn (): string => $pick(['id', 'name', 'size', 'title', 'Name', '', 'x', self::randomBytes(3)]).$pick([':', ':', '']).$pick(['bigint', 'text', 'TEXT', 'integer', 'string', 'uint', '', 'json', self::randomBytes(3)]))
                .$join('', 10, static fn (): string => "\n".(mt_rand(0, 6) === 0 ? '' : implode("\t", \array_slice([
                    $pick(['id', 'name', 'size', 'title', 'Name', '', 'x', '1', '01', self::randomBytes(4)]),
                    $pick(['bigint', 'text', 'TEXT', 'Text', 'uint', 'integer', 'bigint unsigned', 'string', 'json', 'multi', '', self::randomBytes(4)]),
                    $pick(['', 'indexed stored', 'attribute', 'ATTRIBUTE', 'indexed attribute', self::randomBytes(5)]),
                    'extra',
                ], 0, mt_rand(0, 4) === 0 ? mt_rand(0, 4) : 3)))),
            ReleaseIndexProjection::class => implode("\t", $cut([
                $number(),
                $pick(['', ' ', 'Some.Movie.2024', ' x ', 'A-B_C', '...', 'Ünïcode.Näme', self::randomBytes(12)]),
                $number(), $number(), $number(), $number(), $number(), $number(), 'extra',
            ], 8)),
            TmuxTaskRunner::class => implode("\t", $cut([
                $pick(['', ' ', 'Binaries', 'Fix Names', self::randomBytes(5)]),
                $pick(['', '0.1', '1.1', 'nntmux:0.1', 'nn-1_x:12.345', ':1.1', 'a:b:1.1', 'bad session:1.1', '1.', '.1', '1234.1', '1.2.3', 'x.y', ' 2.3 ', self::randomBytes(5)]),
                $pick(['', ' ', 'php artisan update', "cmd\x01", "a\nb", "cmd\x7f", ' trimmed ', self::randomBytes(8)]),
                $number(),
                $pick(['', '1', '0', 'yes', 'NO', 'True', 'off', 'On', 'maybe', ' 1 ']),
                $pick(['', '1', '0', 'yes', 'NO', 'True', 'off', 'On', 'maybe']),
                $pick(['', '1', '0', 'yes', 'NO', 'True', 'off', 'On', 'maybe']),
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
        return self::FUNCTIONS[$class];
    }

    /**
     * Invokes the class's private `w59RustFfiState()`.
     *
     * @param  class-string  $class
     */
    private static function state(string $class, ?string $op, mixed $val): mixed
    {
        return (new ReflectionMethod($class, 'w59RustFfiState'))->invoke(null, $op, $val);
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
     * Resolves the class's Wave 59.5 FFI handle or skips the test without the library.
     *
     * @param  class-string  $class
     */
    private function requireFfi(string $class): FFI
    {
        if (! \extension_loaded('FFI')) {
            $this->markTestSkipped('FFI extension not loaded.');
        }
        $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $ffi = RustPipelineLibrary::resolveDomain('w59_5', $class::W59_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null);
        if (! $ffi instanceof FFI) {
            $this->markTestSkipped('Rust pipeline library not built.');
        }

        return $ffi;
    }
}
