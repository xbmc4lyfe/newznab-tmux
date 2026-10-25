<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Api\ApiUserResolver;
use App\Services\Api\V1\ApiV1Presenter;
use App\Services\Api\V1\ReleaseRows;
use App\Services\LogViewer\Index\LogIndex;
use App\Services\LogViewer\Index\LogSearchCoordinator;
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
 * samples per workload (1000 with `NNTMUX_W54_BENCH=1`), 20 warmup rounds, per-sample random PHP/Rust
 * order, each side the fastest of three back-to-back calls, release dylib. Common workloads hold 64
 * records and uncommon ones 192 records with edge cases. Timings include PHP-side packing, the FFI call
 * and output decoding; each run prints its ratios to STDERR.
 *
 * A helper only defaults to Rust when every recorded common and uncommon mean and p99 ratio reaches 1.5x
 * (QUALIFICATION); each run asserts that the Rust defaults still clear 1.5x.
 */
class RustApiUserResolverV1PresenterReleaseRowsLogIndexCoordW54ParityTest extends TestCase
{
    /**
     * Batch method per class (four words per record); each has `<method>Php` and `<method>Native` variants.
     *
     * @var array<class-string, string>
     */
    private const METHODS = [
        ApiUserResolver::class => 'tokenPlanBatch',
        ApiV1Presenter::class => 'parameterPlanBatch',
        ReleaseRows::class => 'searchNamePlanBatch',
        LogIndex::class => 'headerPlanBatch',
        LogSearchCoordinator::class => 'pathPlanBatch',
    ];

    /**
     * C entry point per class.
     *
     * @var array<class-string, string>
     */
    private const FUNCTIONS = [
        ApiUserResolver::class => 'nntmux_w545_api_user_token_plan',
        ApiV1Presenter::class => 'nntmux_w545_api_v1_parameter_plan',
        ReleaseRows::class => 'nntmux_w545_release_rows_search_name_plan',
        LogIndex::class => 'nntmux_w545_log_index_header_plan',
        LogSearchCoordinator::class => 'nntmux_w545_log_search_path_plan',
    ];

    /**
     * Benchmark-qualified defaults (see the class docblock for the gate).
     *
     * @var array<class-string, bool>
     */
    private const DEFAULTS = [
        ApiUserResolver::class => true,
        ApiV1Presenter::class => true,
        ReleaseRows::class => true,
        LogIndex::class => true,
        LogSearchCoordinator::class => true,
    ];

    /**
     * Common mean/p99 then uncommon mean/p99 speedups (PHP / Rust), the per-metric minimum of three
     * `NNTMUX_W54_BENCH=1` runs (1000 samples per workload, seed 5452, release Rust, PHP 8.5.12 CLI without
     * opcache, Apple Silicon macOS on a shared host).
     *
     * @var array<class-string, array{float, float, float, float}>
     */
    private const QUALIFICATION = [
        ApiUserResolver::class => [2.62, 2.53, 3.66, 3.52],
        ApiV1Presenter::class => [2.55, 3.03, 3.21, 3.20],
        ReleaseRows::class => [7.31, 7.38, 9.24, 8.38],
        LogIndex::class => [5.54, 5.16, 5.12, 4.95],
        LogSearchCoordinator::class => [3.29, 3.31, 2.84, 2.69],
    ];

    /** Back-to-back calls per side of a sample; the fastest is kept (drops preemption spikes). */
    private const REPEATS = 3;

    private const RANDOM_ROUNDS = 400;

    private const WORDS = 4;

    private const BYTES = ['a', 'Z', 'f', 'F', '0', '1', '2', '9', ' ', "\t", "\n", "\r", "\x0b", "\x01", "\x7f", '-', '_', '.', ',', ':', '/', '[', ']', 'T', 'p', 'P', "\xc3\xa9", "\xf0\x9f\x8e\xac", "\xc3", "\xff", "\0"];

    /** Fragments the randomized rounds glue together so headers, dates and integer lists actually occur. */
    private const FRAGMENTS = ['[2024-01-02 03:04:05', '[1970-01-01T00:00:00.5Z', '[2024-13-40 25:61:61', '] ', 'production', '.ERROR', '.info', ': ', 'laravel', '-2024-05-06', '.log', '.gz', '/var/log/', '.hidden', '1080p', '2160P', '4k', 'UHD', '2019', '1999', 'PROPER', 'repack', '-GRP', '5030', ',', '123456789012345678', '1234567890123456789', 'deadbeef', '9dd4e461268c8034f5c8564e155c67a6'];

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
        foreach (['usesW54RustFfi', 'withW54RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
            $this->assertTrue($reflection->hasMethod($name), "{$class}::{$name}");
            $this->assertTrue($reflection->getMethod($name)->isStatic() && $reflection->getMethod($name)->isPublic(), "{$class}::{$name}");
        }
        $this->assertTrue($reflection->getMethod('w54RustFfiState')->isPrivate(), "{$class} keeps its W54 state private");
        $constant = new ReflectionClassConstant($class, 'W54_RUST_FFI_DEFAULT');
        $this->assertTrue($constant->isPrivate(), "{$class} default must be private");
        $this->assertSame(self::DEFAULTS[$class], $constant->getValue(), "{$class} default must match the benchmark evidence");
        $this->assertSame(min(self::QUALIFICATION[$class]) >= 1.5, self::DEFAULTS[$class], "{$class} default must follow its recorded qualification");
        $this->assertSame(self::DEFAULTS[$class], $class::usesW54RustFfi());

        foreach ([true, false] as $enabled) {
            $this->assertSame([$enabled, 'ok'], $class::withW54RustFfi($enabled, static fn () => [$class::usesW54RustFfi(), 'ok']));
            $this->assertSame(self::DEFAULTS[$class], $class::usesW54RustFfi(), "{$class} must restore after the callback");
        }
        $nested = $class::withW54RustFfi(false, static fn () => [$class::withW54RustFfi(true, static fn () => $class::usesW54RustFfi()), $class::usesW54RustFfi()]);
        $this->assertSame([true, false], $nested);
        $caught = null;
        try {
            $class::withW54RustFfi(! $class::usesW54RustFfi(), static fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException $exception) {
            $caught = $exception->getMessage();
        }
        $this->assertSame('boom', $caught, 'The callback exception must propagate.');
        $this->assertSame(self::DEFAULTS[$class], $class::usesW54RustFfi(), "{$class} must restore after an exception");

        $class::withW54RustFfi(! $class::usesW54RustFfi(), function () use ($class): void {
            foreach (array_keys(self::METHODS) as $other) {
                if ($other !== $class) {
                    $this->assertSame(self::DEFAULTS[$other], $other::usesW54RustFfi(), "{$class} leaked into {$other}");
                }
            }
        });
    }

    public function test_earlier_wave_toggles_are_untouched(): void
    {
        $snapshot = static fn (): array => [
            ApiUserResolver::$useRustFfi,
            ApiV1Presenter::$useRustFfi,
            ApiV1Presenter::usesW465RustFfi(),
            ReleaseRows::usesW465RustFfi(),
            LogIndex::usesRustFfi(),
            LogIndex::usesW47LogIndexRustFfi(),
            LogSearchCoordinator::usesW47LogSearchCoordinatorRustFfi(),
        ];
        $before = $snapshot();
        $this->assertSame([false, false, true, true, true, false, false], $before);
        foreach (array_keys(self::METHODS) as $class) {
            foreach ([true, false] as $enabled) {
                $this->assertSame($before, $class::withW54RustFfi($enabled, $snapshot), $class);
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
        $this->assertSame($php, $class::withW54RustFfi(false, static fn () => $class::{$method}($records)), $class);
        $this->assertSame($php, $class::withW54RustFfi(true, static fn () => $class::{$method}($records)), $class);
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

    public function test_token_plan_examples(): void
    {
        $this->requireFfi(ApiUserResolver::class);
        $this->assertBoth(ApiUserResolver::class, ['0123456789abcdef0123456789ABCDEF', '', " \t", " abc-def \n", "x\x01y", 'tok_en', md5('k'), "  \u{e9}t\u{e9}  "], [
            2, 32, 3, 0,
            0, 0, 0, 0,
            1, 0, 12, 0,
            2, 7, 12, 1,
            2, 3, 8, 1,
            2, 6, 0, 1,
            2, 32, 3, 0,
            2, 5, 4, 4,
        ]);
        // The version column matches resolveCredentialsPhp() for an `api_token` string.
        foreach (['', ' ', 'k', ' k '] as $token) {
            $resolved = ApiUserResolver::resolveCredentialsPhp($token, null);
            $this->assertSame($resolved['version'] ?? 0, ApiUserResolver::tokenPlanBatchNative([$token])[0], var_export($token, true));
        }
        // A NUL inside a token declines to PHP.
        $this->assertBoth(ApiUserResolver::class, ["a\0b", 'ok'], [2, 3, 8, 1, 2, 2, 0, 0]);
    }

    public function test_parameter_plan_examples(): void
    {
        $this->requireFfi(ApiV1Presenter::class);
        $this->assertBoth(ApiV1Presenter::class, [' 5030,5040 ', '100', '', " \n", '1,,2', 'tt123,x', '1234567890123456789', '999999999999999999', '-1', '0', '7,3,07', "1\n,2", '1, 2'], [
            9, 2, 2, 5040,
            3, 1, 1, 100,
            0, 0, 0, 0,
            0, 0, 0, 0,
            4, 3, 3, 0,
            7, 3, 2, 0,
            19, 3, 1, 0,
            18, 1, 1, 999999999999999999,
            2, 3, 1, 0,
            1, 1, 1, 0,
            6, 2, 3, 7,
            4, 3, 2, 0,
            4, 3, 2, 0,
        ]);
    }

    public function test_search_name_plan_examples(): void
    {
        $this->requireFfi(ReleaseRows::class);
        $this->assertBoth(ReleaseRows::class, ['The.Show.S01E02.2019.1080p.WEB-DL-GRP', 'Movie 1999 2020 4K PROPER', "T\u{e9}l\u{e9}..__--", '', '-GRP', 'name-', 'a-b.2160P', 'Album_2005_FLAC_rePack', '20199.1080i', 'x.uhd.720p-'], [
            8, 2019, 1080, 4,
            5, 1999, 2160, 2,
            1, 0, 0, 1,
            0, 0, 0, 0,
            1, 0, 0, 4,
            1, 0, 0, 0,
            3, 0, 2160, 0,
            4, 2005, 0, 2,
            2, 0, 1080, 0,
            3, 0, 2160, 0,
        ]);
    }

    public function test_header_plan_examples(): void
    {
        $this->requireFfi(LogIndex::class);
        $this->assertBoth(LogIndex::class, [
            '[2024-01-02 03:04:05] production.ERROR: boom',
            '[1970-01-01T00:00:00.123456+00:00] local.Debug: x',
            '[2024-02-31 00:00:00] app.custom: y',
            '[2024-13-01 00:00:00] app.INFO: y',
            '[1969-12-31 23:59:59] app.INFO: y',
            '[2024-01-02 03:04:05] production.ERROR:boom',
            '[2024-01-02 03:04:05] .ERROR: x',
            '[2024-01-02 03:04:05',
            'plain line',
            '#0 /app/vendor/laravel/framework/src/Illuminate/Queue/Worker.php(123): x',
            '[2099-12-31 23:59:59 extra] a_b.EMERGENCY: ',
        ], [
            1704164645, 5, 10, 40,
            0, 1, 5, 48,
            1709337600, 0, 3, 34,
            0, 0, 0, 0,
            0, 0, 0, 0,
            0, 0, 0, 0,
            0, 0, 0, 0,
            0, 0, 0, 0,
            0, 0, 0, 0,
            0, 0, 0, 0,
            4102444799, 8, 3, 43,
        ]);
    }

    public function test_path_plan_examples(): void
    {
        $this->requireFfi(LogSearchCoordinator::class);
        $this->assertBoth(LogSearchCoordinator::class, [
            '/var/log/laravel-2024-01-02.log',
            '/var/log/laravel.log',
            '/var/log/laravel-2024-01-02.log',
            'archive/worker-2023-12-31.log.gz',
            '.hidden.log',
            '',
            'x-2024-01-0a.log',
            '123',
            '123',
            'dir/',
            'a-2024-01-02.log.gz.log',
        ], [
            9, 20240102, 1, 0,
            9, 0, 1, 1,
            9, 20240102, 1, 0,
            8, 20231231, 2, 3,
            0, 0, 5, 4,
            0, 0, 0, 5,
            0, 0, 1, 6,
            0, 0, 0, 7,
            0, 0, 0, 7,
            4, 0, 0, 9,
            0, 0, 1, 10,
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
        $this->assertSame($class::{$method.'Php'}($records), $class::withW54RustFfi(true, static fn () => $class::{$method}($records)), $class);
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
        $this->assertSame(-1, $call(3, 0, $data, pack('P', 1), $out, 16), "{$function} int table");
        $this->assertSame(0, $call(0, 0, '', '', null, 0), "{$function} empty batch");
    }

    public function test_randomized_parity_across_helpers(): void
    {
        $this->requireFfi(ApiUserResolver::class);
        mt_srand(5451);
        for ($round = 0; $round < self::RANDOM_ROUNDS; $round++) {
            $withNul = $round % 50 === 49;
            $records = [];
            for ($i = 0, $count = mt_rand(1, 16); $i < $count; $i++) {
                $record = '';
                for ($p = 0, $parts = mt_rand(0, 6); $p < $parts; $p++) {
                    $record .= mt_rand(0, 1) === 1 ? self::FRAGMENTS[mt_rand(0, \count(self::FRAGMENTS) - 1)] : self::randomBytes(6, $withNul);
                }
                $records[] = $record;
            }
            if ($round % 7 === 0) {
                $records[] = $records[0];
            }
            foreach (array_keys(self::METHODS) as $class) {
                $this->assertBoth($class, $records, null);
            }
        }
    }

    public function test_paired_randomized_benchmark_gates_rust_default(): void
    {
        $this->requireFfi(ApiUserResolver::class);
        mt_srand(5452);
        $strict = getenv('NNTMUX_W54_BENCH') === '1';
        $samples = $strict ? 1000 : 300;
        $workloads = [];
        $floors = [];
        $levels = ['ERROR', 'INFO', 'WARNING', 'DEBUG', 'CRITICAL', 'NOTICE'];

        foreach (['common' => 64, 'uncommon' => 192] as $name => $count) {
            $odd = $name === 'uncommon';
            $tokens = $parameters = $names = $lines = $paths = [];
            for ($i = 0; $i < $count; $i++) {
                $tokens[] = $odd
                    ? match ($i % 6) {
                        0 => '',
                        1 => " \t",
                        2 => ' '.md5((string) $i)." \n",
                        3 => str_repeat('tok-en_'.$i.'.', 12),
                        4 => "bad\x01token{$i}\x7f",
                        default => "t\u{e9}l\u{e9}".sha1((string) $i),
                    }
                : ($i % 8 === 7 ? sha1((string) $i) : md5((string) $i));

                $parameters[] = $odd
                    ? match ($i % 6) {
                        0 => implode(',', range(5000, 5000 + 10 * ($i % 30), 10)),
                        1 => '',
                        2 => ' 100 ',
                        3 => 'The Show '.$i.', Season 2',
                        4 => '1234567890123456789,'.$i,
                        default => "\u{e9}t\u{e9},".$i,
                    }
                : ['5030,5040', '100', (string) (100 * $i), 'tt'.(1_000_000 + $i), 'the show', '0', 'json'][$i % 7];

                $names[] = $odd
                    ? match ($i % 6) {
                        0 => "T\u{e9}l\u{e9}chargement {$i} 1999 PROPER 720p",
                        1 => '',
                        2 => str_repeat('Long.Release.Name.', 10).$i.'-GRP',
                        3 => 'Artist - Album (2005) [FLAC] REPACK',
                        4 => '--__..  ',
                        default => 'show_s0'.($i % 9).'e01_2160P_UHD_'.$i,
                    }
                : ['The.Show.S01E'.\sprintf('%02d', $i % 24).'.1080p.WEB-DL-GRP', 'Movie.Title.'.(1990 + $i % 30).'.2160p.UHD.BluRay.x265-GRP', 'Some.Documentary.720p.HDTV.x264-GRP', 'Artist-Album-WEB-2019-GRP'][$i % 4];

                $lines[] = $odd
                    ? match ($i % 6) {
                        0 => '[2024-05-06T07:08:09.123456+02:00] worker.'.strtolower($levels[$i % 6]).': job '.$i,
                        1 => '#'.$i.' /app/vendor/laravel/framework/src/Illuminate/Queue/Worker.php(123): Illuminate\Queue\Worker->process()',
                        2 => '[2024-13-40 25:61:61] production.ERROR: invalid stamp',
                        3 => '',
                        4 => '[2024-05-06 07:08:09] production.ERROR: '.str_repeat('SQLSTATE[HY000] connection refused ', 6),
                        default => '[1969-12-31 23:59:59] app.custom_level: before epoch',
                    }
                : '[2024-05-'.\sprintf('%02d', 1 + $i % 28).' '.\sprintf('%02d', $i % 24).':'.\sprintf('%02d', $i % 60).':17] production.'.$levels[$i % 6].': Processing release '.$i.' failed';

                $paths[] = $odd
                    ? match ($i % 6) {
                        0 => '/var/www/storage/logs/archive/laravel-2023-'.\sprintf('%02d', 1 + $i % 12).'-01.log.gz',
                        1 => 'laravel.log',
                        2 => '/var/www/storage/logs/.hidden-'.($i % 5).'.log',
                        3 => '/var/www/storage/logs/laravel-2024-05-01.log',
                        4 => '/very/deep/'.str_repeat('nested/', 8).'worker-'.$i.'.txt',
                        default => '',
                    }
                : '/var/www/storage/logs/'.['laravel', 'horizon', 'worker'][$i % 3].'-2024-05-'.\sprintf('%02d', 1 + $i % 20).'.log';
            }

            $workloads[ApiUserResolver::class][$name] = static fn (bool $rust) => $rust ? ApiUserResolver::tokenPlanBatchNative($tokens) : ApiUserResolver::tokenPlanBatchPhp($tokens);
            $workloads[ApiV1Presenter::class][$name] = static fn (bool $rust) => $rust ? ApiV1Presenter::parameterPlanBatchNative($parameters) : ApiV1Presenter::parameterPlanBatchPhp($parameters);
            $workloads[ReleaseRows::class][$name] = static fn (bool $rust) => $rust ? ReleaseRows::searchNamePlanBatchNative($names) : ReleaseRows::searchNamePlanBatchPhp($names);
            $workloads[LogIndex::class][$name] = static fn (bool $rust) => $rust ? LogIndex::headerPlanBatchNative($lines) : LogIndex::headerPlanBatchPhp($lines);
            $workloads[LogSearchCoordinator::class][$name] = static fn (bool $rust) => $rust ? LogSearchCoordinator::pathPlanBatchNative($paths) : LogSearchCoordinator::pathPlanBatchPhp($paths);
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
                "\n[Wave 54.5 benchmark] %s: common mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx | uncommon mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx",
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
            $this->assertSame(self::DEFAULTS[$class], $class::usesW54RustFfi(), $class);
            $this->assertSame(min(self::QUALIFICATION[$class]) >= 1.5, $class::usesW54RustFfi(), $class);
            if ($class::usesW54RustFfi()) {
                $this->assertGreaterThanOrEqual(1.5, $floor, \sprintf('%s: a Rust default must clear the 1.5x gate (min ratio %.2fx)', $class, $floor));
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
        $this->assertSame($php, $class::{$method.'Native'}($records), "{$class} native");
        $this->assertSame($php, $class::withW54RustFfi(true, static fn () => $class::{$method}($records)), "{$class} Rust route");
        $this->assertSame($php, $class::withW54RustFfi(false, static fn () => $class::{$method}($records)), "{$class} PHP route");
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
            ApiUserResolver::class => [md5('a'), ' padded ', ''],
            ApiV1Presenter::class => ['5030,5040', 'tt123', ''],
            ReleaseRows::class => ['The.Show.S01E01.1080p.WEB-DL-GRP', 'Movie 2019 PROPER', ''],
            LogIndex::class => ['[2024-01-02 03:04:05] production.ERROR: boom', '#0 trace', ''],
            LogSearchCoordinator::class => ['/var/log/laravel-2024-01-02.log', 'laravel.log', ''],
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
     * Invokes the class's private `w54RustFfiState()`.
     *
     * @param  class-string  $class
     */
    private static function state(string $class, ?string $op, mixed $val): mixed
    {
        return (new ReflectionMethod($class, 'w54RustFfiState'))->invoke(null, $op, $val);
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
        $cdef = (new ReflectionClassConstant($class, 'W54_FFI_CDEF'))->getValue();
        $ffi = RustPipelineLibrary::resolveDomain('w54_5', $cdef, \is_string($path) && $path !== '' ? $path : null);
        if ($ffi === null) {
            $this->markTestSkipped('Rust W54.5 FFI library is not built or available.');
        }

        return $ffi;
    }
}
