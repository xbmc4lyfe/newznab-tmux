<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\BackfillUserActivityStats;
use App\Console\Commands\CaptchaStatus;
use App\Console\Commands\CloudflareReload;
use App\Console\Commands\DisableExpiredPromotions;
use App\Console\Commands\DisableExpiredRegistrationPeriods;
use App\Models\RolePromotion;
use App\Services\Cloudflare\CloudflareIpRangeService;
use App\Services\RegistrationStatusService;
use App\Support\RustPipelineLibrary;
use Carbon\Carbon;
use FFI;
use Illuminate\Config\Repository;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Wave 31.5 caller-inclusive paired benchmark, 2026-10-25:
 * PHP 8.5.12, rustc 1.99.1, optimized release, Apple M5 arm64, no imposed
 * resource caps; baseline 66067e6babb8221da369e38d35c215b7c4971386.
 * Three repetitions, 100 warmups and 1001 randomized pairs per workload.
 * Worst repetition mean / p99 PHP-to-Rust latency speedup:
 * Helper                                            Common         Uncommon
 * BackfillUserActivityStats::isValidStatType        0.117 / 0.079  0.103 / 0.041
 * BackfillUserActivityStats::formatCompletionLines  0.124 / 0.040  0.124 / 0.071
 * CaptchaStatus::evaluateCaptchaReadiness           0.089 / 0.036  0.094 / 0.051
 * CloudflareReload::mergeEffectiveProxies           0.151 / 0.080  0.092 / 0.110
 * CloudflareReload::formatRangeSummaryLines         0.121 / 0.041  0.137 / 0.061
 * DisableExpiredPromotions::formatDisabledPromotionLine 0.104 / 0.028  0.119 / 0.054
 * DisableExpiredPromotions::formatCompletionMessage 0.127 / 0.098  0.144 / 0.050
 * DisableExpiredRegistrationPeriods::formatCompletedPeriodLine 0.136 / 0.029  0.112 / 0.057
 * DisableExpiredRegistrationPeriods::formatCompletionMessage 0.140 / 0.052  0.118 / 0.027
 * All miss the 1.5x gate, so PHP stays default. Timings are local helper
 * measurements; p99 estimates at this sample size are noisy. Warmed loading
 * omits process startup and is favorable to Rust; it still fails the gate.
 * Raw samples, source/lockfile hashes and runtime metadata:
 * /tmp/w31-5-benchmark.jsonl. Reproduction is in the opt-in test below.
 */
final class RustActivityCaptchaCloudflareExpiryCommandsParityTest extends TestCase
{
    /** @var list<class-string> */
    private const array CLASSES = [
        BackfillUserActivityStats::class, CaptchaStatus::class, CloudflareReload::class,
        DisableExpiredPromotions::class, DisableExpiredRegistrationPeriods::class,
    ];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            if (method_exists($class, 'setUseRustFfi')) {
                $class::setUseRustFfi(null);
                (new ReflectionProperty($class, 'ffi'))->setValue(null, null);
            }
        }
        parent::tearDown();
    }

    public function test_defaults_and_per_class_overrides_reset_to_php(): void
    {
        foreach (self::CLASSES as $class) {
            $this->assertTrue(method_exists($class, 'setUseRustFfi'), $class.' must expose its FFI toggle');
            $this->assertFalse($class::usesRustFfi());
            $class::setUseRustFfi(true);
            $this->assertTrue($class::useRustFfi());
            $class::setUseRustFfi(false);
            $this->assertFalse($class::usesRustFfi());
            $class::setUseRustFfi(true);
            $class::setUseRustFfi(null);
            $this->assertFalse($class::usesRustFfi());
        }
        CaptchaStatus::setUseRustFfi(true);
        $this->assertFalse(BackfillUserActivityStats::usesRustFfi());
        $this->assertFalse(CloudflareReload::usesRustFfi());
    }

    /** @return iterable<array{class-string, string, list<mixed>, mixed, int, list<string>}> */
    public static function cases(): iterable
    {
        foreach (['daily', 'hourly', '', 'Daily', 'daily ', "hourly\0", "é\0\xff", '0'] as $type) {
            yield [BackfillUserActivityStats::class, 'isValidStatType', [$type], in_array($type, ['daily', 'hourly'], true), 1, [$type]];
        }
        foreach ([0, -1, 1, 42, PHP_INT_MIN, PHP_INT_MAX] as $n) {
            foreach ([0, -1, 1, PHP_INT_MIN, PHP_INT_MAX] as $skipped) {
                $lines = ['Backfill completed!', "Stats collected: {$n}"];
                if ($skipped > 0) {
                    $lines[] = "Stats skipped (already existed): {$skipped}";
                }
                yield [BackfillUserActivityStats::class, 'formatCompletionLines', [$n, $skipped], $lines, 2, [(string) $n, (string) $skipped]];
            }
            yield [CloudflareReload::class, 'formatRangeSummaryLines', [$n, $n, $n, $n, $n], [
                "Cloudflare IPv4 ranges: {$n}", "Cloudflare IPv6 ranges: {$n}",
                "Combined Cloudflare proxy count: {$n}", "Manual proxy count: {$n}", "Effective trusted proxy count: {$n}",
            ], 5, array_fill(0, 5, (string) $n)];
            yield [DisableExpiredPromotions::class, 'formatCompletionMessage', [$n], $n === 0 ? 'No expired promotions found.' : "Successfully disabled {$n} expired promotion(s).", 7, [(string) $n]];
            yield [DisableExpiredRegistrationPeriods::class, 'formatCompletionMessage', [$n], $n === 0 ? 'No expired registration periods found.' : "Successfully completed {$n} expired registration period(s).", 9, [(string) $n]];
        }
        yield [CloudflareReload::class, 'formatRangeSummaryLines', [1, 2, 3, 4, 5], [
            'Cloudflare IPv4 ranges: 1', 'Cloudflare IPv6 ranges: 2', 'Combined Cloudflare proxy count: 3', 'Manual proxy count: 4', 'Effective trusted proxy count: 5',
        ], 5, ['1', '2', '3', '4', '5']];
        foreach (['', "Café 日本語 😀\0tail", "\xff\xfe\0", '0', str_repeat("é\0", 2048)] as $name) {
            foreach (['', '2026-10-25', "日付\0\xff"] as $date) {
                yield [DisableExpiredPromotions::class, 'formatDisabledPromotionLine', [$name, $date], "Disabled promotion: {$name} (ended: {$date})", 6, [$name, $date]];
                yield [DisableExpiredRegistrationPeriods::class, 'formatCompletedPeriodLine', [$name, $date], "Marked registration period as done: {$name} (ended: {$date})", 8, [$name, $date]];
            }
        }
        foreach ([
            [[], [], []],
            [['a', 'b', 'a'], ['b', 'c', 'a'], ['a', 'b', 'c']],
            [['', '0', '00', '1', '01', '-0', '1.0'], ['0', '', '1'], ['', '0', '00', '1', '01', '-0', '1.0']],
            [["é\0", "\xff", "é\0"], ["\xff", "\0"], ["é\0", "\xff", "\0"]],
            [['x' => 'a', 'y' => 'b'], ['x' => 'c', 'z' => 'a'], ['c', 'b', 'a']],
            [[3 => 'a', 9 => 'b'], [2 => 'a', 8 => 'c'], ['a', 'b', 'c']],
        ] as [$manual, $manifest, $expected]) {
            // PHP string-key unpacking overwrites earlier values before deduplication.
            $items = array_values([...$manual, ...$manifest]);
            yield [CloudflareReload::class, 'mergeEffectiveProxies', [$manual, $manifest], $expected, 4, $items];
        }
        for ($mask = 0; $mask < 64; $mask++) {
            $args = [];
            for ($bit = 0; $bit < 6; $bit++) {
                $args[] = (bool) ($mask & (1 << $bit));
            }
            $recaptcha = ($mask & 7) === 7;
            $turnstile = ($mask & 56) === 56;
            yield [CaptchaStatus::class, 'evaluateCaptchaReadiness', $args, [
                'recaptcha_ready' => $recaptcha, 'turnstile_ready' => $turnstile,
                'conflict' => $recaptcha && $turnstile,
                'active_provider' => $recaptcha ? 'recaptcha' : ($turnstile ? 'turnstile' : null),
            ], 3, array_map(static fn (bool $v): string => $v ? '1' : '0', $args)];
        }
    }

    public function test_all_helpers_match_php_contract_in_both_dispatch_modes_and_without_ffi(): void
    {
        foreach ([false, true] as $available) {
            foreach (self::CLASSES as $class) {
                (new ReflectionProperty($class, 'ffi'))->setValue(null, $available ? $this->library() : false);
            }
            foreach (self::cases() as [$class, $method, $args, $expected, $mode, $items]) {
                $this->assertSame($expected, $class::{$method.'Php'}(...$args), $method.' PHP');
                $this->assertSame($expected, $class::{$method.'Native'}(...$args), $method.' native');
                foreach ([false, true] as $enabled) {
                    $class::setUseRustFfi($enabled);
                    $this->assertSame($expected, $class::$method(...$args), $method.' dispatch');
                }
                if ($available) {
                    // Require the bridge itself to succeed; PHP fallback cannot hide a broken ABI.
                    $packet = (new ReflectionMethod($class, 'nativePacket'))->invoke(null, $mode, $items);
                    $this->assertNotNull($packet, $method.' must execute Rust');
                    $rawExpected = match ($mode) {
                        1 => [$expected ? '1' : '0'],
                        3 => [$expected['recaptcha_ready'] ? '1' : '0', $expected['turnstile_ready'] ? '1' : '0', $expected['conflict'] ? '1' : '0', $expected['active_provider'] ?? ''],
                        6, 7, 8, 9 => [$expected],
                        default => $expected,
                    };
                    $this->assertSame($rawExpected, $packet, $method.' raw Rust');
                }
            }
        }
    }

    public function test_random_byte_strings_keep_order_and_embedded_nuls(): void
    {
        foreach (self::CLASSES as $class) {
            (new ReflectionProperty($class, 'ffi'))->setValue(null, $this->library());
        }
        mt_srand(3105);
        for ($i = 0; $i < 300; $i++) {
            $name = '';
            for ($j = 0, $length = mt_rand(0, 128); $j < $length; $j++) {
                $name .= chr(mt_rand(0, 255));
            }
            $this->assertSame("Disabled promotion: {$name} (ended: \0)", DisableExpiredPromotions::formatDisabledPromotionLineNative($name, "\0"));
            $this->assertSame("Marked registration period as done: {$name} (ended: \0)", DisableExpiredRegistrationPeriods::formatCompletedPeriodLineNative($name, "\0"));
            $values = [$name, '', $name, "\0", '0', '00', ''];
            $this->assertSame(array_values(array_unique($values)), CloudflareReload::mergeEffectiveProxiesNative($values, []));
        }
    }

    public function test_missing_symbol_and_declined_requests_fall_back_safely(): void
    {
        $ffi = FFI::cdef('');
        foreach (self::CLASSES as $class) {
            (new ReflectionProperty($class, 'ffi'))->setValue(null, $ffi);
        }
        foreach (self::cases() as [$class, $method, $args, $expected]) {
            $class::setUseRustFfi(true);
            $this->assertSame($expected, $class::$method(...$args));
        }
        (new ReflectionProperty(CloudflareReload::class, 'ffi'))->setValue(null, $this->library());
        $this->assertNull((new ReflectionMethod(CloudflareReload::class, 'nativePacket'))->invoke(null, 99, []));
        $large = str_repeat('x', 16777217);
        $this->assertSame([$large], CloudflareReload::mergeEffectiveProxiesNative([$large], [$large]));
    }

    /** @param array<string, mixed> $options */
    private function outputFor(Command $command, array $options = []): BufferedOutput
    {
        $input = new ArrayInput($options, $command->getDefinition());
        $buffer = new BufferedOutput;
        $command->setInput($input);
        $command->setOutput(new OutputStyle($input, $buffer));

        return $buffer;
    }

    private function withDatabase(callable $test): void
    {
        $oldContainer = Container::getInstance();
        $oldFacadeApp = Facade::getFacadeApplication();
        $oldResolver = Model::getConnectionResolver();
        $oldNow = Carbon::getTestNow();
        $container = new Application(dirname(__DIR__, 2));
        $container->instance('config', new Repository);
        $capsule = new Capsule($container);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->bootEloquent();
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        $container->instance('db', $capsule->getDatabaseManager());
        Carbon::setTestNow(Carbon::parse('2026-10-25 12:30:00', 'UTC'));
        try {
            $test($capsule);
        } finally {
            $capsule->getConnection()->disconnect();
            if ($oldResolver !== null) {
                Model::setConnectionResolver($oldResolver);
            } else {
                Model::unsetConnectionResolver();
            }
            Carbon::setTestNow($oldNow);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($oldFacadeApp);
            Container::setInstance($oldContainer);
        }
    }

    public function test_backfill_handlers_keep_validation_skips_and_original_headings(): void
    {
        $this->withDatabase(function (Capsule $db): void {
            $db->getConnection()->getSchemaBuilder()->create('user_activity_stats', static function (Blueprint $table): void {
                $table->date('stat_date');
            });
            $db->getConnection()->getSchemaBuilder()->create('user_activity_stats_hourly', static function (Blueprint $table): void {
                $table->dateTime('stat_hour');
            });
            $db->getConnection()->table('user_activity_stats')->insert(['stat_date' => '2026-10-25']);
            $db->getConnection()->table('user_activity_stats_hourly')->insert(['stat_hour' => '2026-10-25 12:00:00']);
            foreach ([false, true] as $enabled) {
                BackfillUserActivityStats::setUseRustFfi($enabled);
                foreach (['daily' => 'Daily', 'hourly' => 'Hourly'] as $type => $heading) {
                    $command = new BackfillUserActivityStats;
                    $buffer = $this->outputFor($command, ['--type' => $type, '--days' => 1, '--hours' => 1]);
                    $this->assertSame(0, $command->handle());
                    $text = $buffer->fetch();
                    $this->assertStringContainsString($heading.' backfill complete!', $text);
                    $this->assertStringContainsString('Stats collected: 0', $text);
                    $this->assertStringContainsString('Stats skipped (already existed): 1', $text);
                    $buffer = $this->outputFor($command, ['--type' => $type, '--days' => 0, '--hours' => 0]);
                    $this->assertSame(0, $command->handle());
                    $this->assertStringNotContainsString('Stats skipped', $buffer->fetch());
                }
                $command = new BackfillUserActivityStats;
                $buffer = $this->outputFor($command, ['--type' => 'Daily']);
                $this->assertSame(1, $command->handle());
                $this->assertStringContainsString("Invalid type 'Daily'. Must be 'daily' or 'hourly'.", $buffer->fetch());
            }
        });
    }

    public function test_backfill_handler_reports_a_valueless_type_as_invalid(): void
    {
        foreach ([false, true] as $enabled) {
            BackfillUserActivityStats::setUseRustFfi($enabled);
            $command = new BackfillUserActivityStats;
            $buffer = $this->outputFor($command, ['--type' => null]);
            $this->assertSame(1, $command->handle());
            $this->assertStringContainsString("Invalid type ''. Must be 'daily' or 'hourly'.", $buffer->fetch());
        }
    }

    public function test_captcha_handler_keeps_configured_provider_conflicts_and_empty_semantics(): void
    {
        $oldContainer = Container::getInstance();
        try {
            $container = new Container;
            Container::setInstance($container);
            foreach ([false, true] as $enabled) {
                CaptchaStatus::setUseRustFfi($enabled);
                foreach (['recaptcha', 'turnstile', 'unknown'] as $provider) {
                    foreach ([true, false, '0', "\0"] as $key) {
                        $container->instance('config', new Repository(['captcha' => [
                            'provider' => $provider,
                            'recaptcha' => ['enabled' => true, 'sitekey' => $key, 'secret' => 'secret'],
                            'turnstile' => ['enabled' => true, 'sitekey' => 'sitekey', 'secret' => 'secret'],
                        ]]));
                        $command = new CaptchaStatus;
                        $buffer = $this->outputFor($command);
                        $this->assertSame(0, $command->handle());
                        $text = $buffer->fetch();
                        $this->assertStringContainsString('Active Provider: '.$provider, $text);
                        if (! empty($key)) {
                            $this->assertStringContainsString('Both providers are enabled!', $text);
                        } else {
                            $this->assertStringNotContainsString('Both providers are enabled!', $text);
                        }
                        $expected = match ($provider) {
                            'recaptcha' => ! empty($key) ? 'reCAPTCHA is properly configured and active' : 'reCAPTCHA is selected but not properly configured',
                            'turnstile' => 'Turnstile is properly configured and active',
                            default => 'No CAPTCHA provider is active',
                        };
                        $this->assertStringContainsString($expected, $text);
                    }
                }
            }
        } finally {
            Container::setInstance($oldContainer);
        }
    }

    public function test_cloudflare_handler_keeps_dry_run_persistence_argument_and_summary(): void
    {
        foreach ([false, true] as $enabled) {
            CloudflareReload::setUseRustFfi($enabled);
            foreach ([false, true] as $dryRun) {
                $service = $this->createMock(CloudflareIpRangeService::class);
                $service->expects($this->once())->method('refresh')->with(! $dryRun)->willReturn([
                    'ipv4' => ['1.2.3.0/24'], 'ipv6' => ['::1/128'],
                    'proxies' => ['1.2.3.0/24', '::1/128'], 'updated_at' => '2026-10-25',
                ]);
                $service->method('manualTrustedProxies')->willReturn(['1.2.3.0/24', '127.0.0.1']);
                $service->method('manifestPath')->willReturn('/fake/manifest.json');
                $command = new CloudflareReload($service);
                $buffer = $this->outputFor($command, ['--dry-run' => $dryRun]);
                $this->assertSame(0, $command->handle());
                $text = $buffer->fetch();
                $this->assertStringContainsString("Cloudflare IPv4 ranges: 1\nCloudflare IPv6 ranges: 1\nCombined Cloudflare proxy count: 2\nManual proxy count: 2\nEffective trusted proxy count: 3\n", $text);
                $this->assertStringContainsString($dryRun ? 'Dry run complete; manifest file was not updated.' : 'Stored Cloudflare manifest: /fake/manifest.json', $text);
            }
        }
    }

    public function test_expired_promotion_handler_changes_only_expired_active_rows_and_keeps_output(): void
    {
        $this->withDatabase(function (Capsule $db): void {
            $db->getConnection()->getSchemaBuilder()->create('role_promotions', static function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->dateTime('end_date')->nullable();
                $table->boolean('is_active');
                $table->timestamps();
            });
            foreach ([false, true] as $enabled) {
                DisableExpiredPromotions::setUseRustFfi($enabled);
                $db->getConnection()->table('role_promotions')->delete();
                $db->getConnection()->table('role_promotions')->insert([
                    ['id' => 1, 'name' => 'Café', 'end_date' => '2026-10-24 12:00:00', 'is_active' => true],
                    ['id' => 2, 'name' => 'Future', 'end_date' => '2026-10-26 12:00:00', 'is_active' => true],
                    ['id' => 3, 'name' => 'Inactive', 'end_date' => '2026-10-24 12:00:00', 'is_active' => false],
                    ['id' => 4, 'name' => 'No end', 'end_date' => null, 'is_active' => true],
                ]);
                $command = new DisableExpiredPromotions;
                $buffer = $this->outputFor($command);
                $this->assertSame(0, $command->handle());
                $this->assertSame("Disabled promotion: Café (ended: 2026-10-24)\nSuccessfully disabled 1 expired promotion(s).\n", $buffer->fetch());
                $this->assertSame([0, 1, 0, 1], $db->getConnection()->table('role_promotions')->orderBy('id')->pluck('is_active')->all());
                $this->assertFalse(RolePromotion::findOrFail(1)->is_active);
                $this->assertSame(0, $command->handle());
                $this->assertSame("No expired promotions found.\n", $buffer->fetch());
            }
        });
    }

    public function test_registration_handler_keeps_service_call_empty_case_and_date_format(): void
    {
        foreach ([false, true] as $enabled) {
            DisableExpiredRegistrationPeriods::setUseRustFfi($enabled);
            foreach ([false, true] as $hasExpired) {
                $service = $this->createMock(RegistrationStatusService::class);
                $service->expects($this->once())->method('disableExpiredPeriods')->willReturn(collect($hasExpired ? [
                    (object) ['name' => 'Café', 'ends_at' => Carbon::parse('2026-10-24 13:45:59')],
                ] : []));
                $command = new DisableExpiredRegistrationPeriods($service);
                $buffer = $this->outputFor($command);
                $this->assertSame(0, $command->handle());
                $this->assertSame($hasExpired
                    ? "Marked registration period as done: Café (ended: 2026-10-24 13:45)\nSuccessfully completed 1 expired registration period(s).\n"
                    : "No expired registration periods found.\n", $buffer->fetch());
            }
        }
    }

    /**
     * Reproduce with NNTMUX_ACTIVITY_CAPTCHA_CLOUDFLARE_EXPIRY_BENCH=1 php artisan test
     * --compact tests/Unit/RustActivityCaptchaCloudflareExpiryCommandsParityTest.php
     * Release Rust, warmed library; timed dispatch includes FFI packing, copying,
     * allocation and unpacking. Raw randomized paired nanoseconds go to STDERR.
     */
    public function test_paired_caller_inclusive_benchmarks(): void
    {
        if (getenv('NNTMUX_ACTIVITY_CAPTCHA_CLOUDFLARE_EXPIRY_BENCH') !== '1') {
            $this->markTestSkipped('Opt-in caller-inclusive benchmark.');
        }
        foreach (self::CLASSES as $class) {
            (new ReflectionProperty($class, 'ffi'))->setValue(null, $this->library());
        }
        $workloads = [
            [BackfillUserActivityStats::class, 'isValidStatType', ['daily'], [str_repeat("é\0", 1024)]],
            [BackfillUserActivityStats::class, 'formatCompletionLines', [30, 2], [PHP_INT_MIN, PHP_INT_MAX]],
            [CaptchaStatus::class, 'evaluateCaptchaReadiness', [true, true, true, false, false, false], [true, true, true, true, true, true]],
            [CloudflareReload::class, 'mergeEffectiveProxies', [['127.0.0.1', '1.2.3.0/24'], ['1.2.3.0/24', '::1/128']], [array_fill(0, 500, str_repeat("é\0", 30)), array_map('strval', range(1, 500))]],
            [CloudflareReload::class, 'formatRangeSummaryLines', [15, 7, 22, 1, 23], [PHP_INT_MIN, PHP_INT_MAX, -1, 0, PHP_INT_MAX]],
            [DisableExpiredPromotions::class, 'formatDisabledPromotionLine', ['Summer', '2026-10-24'], [str_repeat("é\0\xff", 1024), '']],
            [DisableExpiredPromotions::class, 'formatCompletionMessage', [2], [PHP_INT_MIN]],
            [DisableExpiredRegistrationPeriods::class, 'formatCompletedPeriodLine', ['Summer', '2026-10-24 13:45'], [str_repeat("é\0\xff", 1024), '']],
            [DisableExpiredRegistrationPeriods::class, 'formatCompletionMessage', [2], [PHP_INT_MIN]],
        ];
        $hashes = [];
        foreach (self::CLASSES as $class) {
            $file = (new \ReflectionClass($class))->getFileName();
            $hashes[$class] = hash_file('sha256', $file);
        }
        $hashes['search.rs'] = hash_file('sha256', __DIR__.'/../../rust/pipeline-ffi/src/search.rs');
        fwrite(STDERR, json_encode([
            'php' => PHP_VERSION, 'os' => php_uname(), 'source_sha256' => $hashes,
            'composer_lock_sha256' => hash_file('sha256', __DIR__.'/../../composer.lock'),
            'seed' => 3105, 'warmups' => 100, 'samples' => 1001, 'repetitions' => 3,
        ]).PHP_EOL);
        mt_srand(3105);
        foreach ($workloads as [$class, $method, $common, $uncommon]) {
            foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $args) {
                for ($rep = 0; $rep < 3; $rep++) {
                    $class::setUseRustFfi(false);
                    $expected = $class::$method(...$args);
                    $class::setUseRustFfi(true);
                    $this->assertSame($expected, $class::$method(...$args));
                    foreach ([false, true] as $enabled) {
                        $class::setUseRustFfi($enabled);
                        for ($i = 0; $i < 100; $i++) {
                            $class::$method(...$args);
                        }
                    }
                    $times = [[], []];
                    for ($sample = 0; $sample < 1001; $sample++) {
                        foreach (mt_rand(0, 1) ? [false, true] : [true, false] as $enabled) {
                            $class::setUseRustFfi($enabled);
                            $start = hrtime(true);
                            $class::$method(...$args);
                            $times[(int) $enabled][] = hrtime(true) - $start;
                        }
                    }
                    fwrite(STDERR, json_encode(['class' => $class, 'method' => $method, 'kind' => $kind, 'rep' => $rep, 'php_ns' => $times[0], 'rust_ns' => $times[1]]).PHP_EOL);
                    sort($times[0]);
                    sort($times[1]);
                    fwrite(STDERR, json_encode(['summary' => $class.'::'.$method, 'kind' => $kind, 'rep' => $rep,
                        'mean_speedup' => array_sum($times[0]) / array_sum($times[1]),
                        'p99_speedup' => $times[0][990] / $times[1][990],
                    ]).PHP_EOL);
                }
            }
        }
    }

    private function library(): FFI
    {
        $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $ffi = RustPipelineLibrary::resolveDomain('activity-captcha-cloudflare-expiry-parity', BackfillUserActivityStats::FFI_CDEF, is_string($path) && $path !== '' ? $path : null);
        $this->assertInstanceOf(FFI::class, $ffi, 'Build the release library before running native parity tests.');

        return $ffi;
    }
}
