<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Events\UserAccessedApi;
use App\Events\UserLoggedIn;
use App\Jobs\RecordApiUsage;
use App\Jobs\RemoveInactiveAccounts;
use App\Jobs\UpdateUserApiAccess;
use App\Listeners\UpdateUserAccessedApi;
use App\Listeners\UpdateUserLoggedIn;
use App\Models\User;
use App\Support\RustPipelineLibrary;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use FFI;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

/**
 * Wave 25.2: isolated SQLite caller parity and opt-in paired helper benchmarks.
 * Native timings include normalization, serialization, allocation, FFI and
 * reconstruction; they exclude Laravel boot, storage I/O and production load.
 * Protocol: seed 25022026, three rounds of 41 randomized pairs, 200 individually
 * timed identical inputs per side/pair and 10 warmups per side/round. Nearest-rank
 * p99 uses 24,600 observations per side/cell; round ranges describe uncertainty.
 * Adoption requires every common/uncommon mean AND p99 speedup to reach 1.5x.
 * Run API_AUDIT_ACCOUNT_JOB_BENCHMARK=1 with the release library configured to
 * preserve raw ns, order, input/source/library hashes and environment in /tmp.
 *
 * Measured 2026-10-24 on Apple M5 / Darwin arm64, PHP 8.5.12 (CLI opcache/JIT
 * disabled), rustc 1.99.1 release; base 5871398b6dcd9dc834e704b0c8386b8991412806.
 * All 12 helpers failed the gate in both common/uncommon cells: mean speedups
 * 0.024-0.459x, p99 speedups 0.015-0.585x (PHP ns / Native ns). Every cell has
 * 24,600 observations/side, with no failures. All five toggles remain false.
 * Final raw report (includes DST-gap/fold uncommon cutoffs):
 * /tmp/wave25-2-api-audit-account-job-20261024-193521-8874.json
 * This evidence annotation follows the measured source hashes; runtime code
 * and protocol are unchanged. No builds or other validation ran concurrently.
 */
final class RustApiAuditAccountJobParityTest extends TestCase
{
    private const array CLASSES = [RecordApiUsage::class, UpdateUserApiAccess::class,
        RemoveInactiveAccounts::class, UpdateUserAccessedApi::class, UpdateUserLoggedIn::class];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            if (method_exists($class, 'setUseRustFfi')) {
                $class::setUseRustFfi(false);
            }
        }
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_default_dispatch_and_original_scalar_contracts(): void
    {
        foreach (self::CLASSES as $class) {
            $this->assertTrue(method_exists($class, 'usesRustFfi'), $class.' toggle missing');
            $this->assertFalse((new ReflectionClass($class))->getDefaultProperties()['useRustFfi']);
            $this->assertFalse($class::usesRustFfi());
        }
        $this->assertSame('api_access_update:-1', RecordApiUsage::cacheKey(-1));
        $this->assertSame(1, RecordApiUsage::normalizeInterval(PHP_INT_MIN));
        $this->assertSame(['key' => 'api_access_update:42', 'interval' => 60], RecordApiUsage::buildAccessBucket(42, 60));
        $this->assertSame(['users_id' => 42, 'request' => '/api?q=raw', 'timestamp' => 'at'], RecordApiUsage::buildAuditPayload(42, '/api?q=raw', 'at'));
        foreach ([RecordApiUsage::class, UpdateUserApiAccess::class, UpdateUserAccessedApi::class] as $class) {
            $this->assertSame(['apiaccess' => 'at'], $class::buildUpdateAttributes('at', null));
            $this->assertSame(['apiaccess' => 'at', 'host' => ''], $class::buildUpdateAttributes('at', ''));
        }
        $this->assertSame(['lastlogin' => 'at', 'host' => ''], UpdateUserLoggedIn::buildUpdateAttributes('at', ''));
        $this->assertSame(['user_id' => 42, 'username' => 'name', 'ip' => '', 'time' => 'at'], UpdateUserLoggedIn::buildLogPayload(42, 'name', '', 'at'));
    }

    public function test_native_and_dispatch_parity_on_binary_empty_and_extreme_inputs(): void
    {
        $this->nativeFfi();
        foreach (self::workloads() as [$class, $method, $common, $uncommon]) {
            $php = $method.'Php';
            $native = $method.'Native';
            foreach ([...$common, ...$uncommon] as $args) {
                $expected = $class::$php(...$args);
                $this->assertSame(serialize($expected), serialize($class::$native(...$args)), $class.'::'.$native);
                foreach ([false, true] as $enabled) {
                    $class::setUseRustFfi($enabled);
                    $this->assertSame(serialize($expected), serialize($class::$method(...$args)), $class.'::'.$method);
                }
            }
        }
        foreach ([RecordApiUsage::class, UpdateUserApiAccess::class, UpdateUserAccessedApi::class, UpdateUserLoggedIn::class] as $class) {
            $at = Carbon::parse('2026-10-24 13:14:15.123456', 'Europe/Madrid');
            $attributes = $class::buildUpdateAttributesNative($at, '');
            $this->assertSame($at, $attributes[$class === UpdateUserLoggedIn::class ? 'lastlogin' : 'apiaccess']);
        }
    }

    public function test_calendar_cutoff_preserves_timezone_microseconds_and_input_identity(): void
    {
        $this->nativeFfi();
        foreach (['Europe/Madrid', 'America/New_York', 'Pacific/Apia', 'UTC'] as $zone) {
            foreach (['2026-03-30 02:30:00.123456', '2026-10-26 02:30:00.654321', '2011-12-31 12:00:00.111111', '2000-03-01 00:00:00'] as $date) {
                foreach ([Carbon::parse($date, $zone), CarbonImmutable::parse($date, $zone)] as $at) {
                    $original = serialize($at);
                    foreach ([-366, -1, 0, 1, 30, 365, 366] as $days) {
                        $expected = $at->copy()->subDays($days);
                        $actual = RemoveInactiveAccounts::cutoffNative($at, $days);
                        $this->assertSame($expected::class, $actual::class);
                        $this->assertSame($expected->format('Y-m-d H:i:s.u e P'), $actual->format('Y-m-d H:i:s.u e P'));
                    }
                    $this->assertSame($original, serialize($at));
                }
            }
        }
    }

    public function test_purge_evaluation_preserves_strict_boundaries_and_download_fallbacks(): void
    {
        $this->nativeFfi();
        $threshold = CarbonImmutable::parse('2026-01-01 00:00:00.000001', 'UTC');
        $old = $threshold->subMicrosecond();
        $new = $threshold->addMicrosecond();
        foreach ([false, true] as $enabled) {
            RemoveInactiveAccounts::setUseRustFfi($enabled);
            foreach ([null, $old, $threshold, $new] as $created) {
                foreach ([null, $old, $threshold, $new] as $updated) {
                    foreach ([null, $old, $threshold, $new] as $login) {
                        foreach ([null, $old, $threshold, $new] as $api) {
                            foreach ([null, $old, $threshold, $new] as $download) {
                                foreach ([-1, 0, 1, PHP_INT_MAX] as $grabs) {
                                    $stale = static fn ($date): bool => $date !== null && $date < $threshold;
                                    $expected = $stale($login ?? $created) && $stale($api ?? $created)
                                        && $stale($download ?? ($grabs > 0 ? $updated : $created));
                                    $this->assertSame($expected, RemoveInactiveAccounts::shouldPurge($created, $updated, $login, $api, $download, $grabs, $threshold));
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    public function test_disabled_or_missing_ffi_falls_back_in_a_fresh_process(): void
    {
        $vectors = [];
        foreach (self::workloads() as [$class, $method, $common]) {
            // Carbon input classes autoload normally in the child process.
            $vectors[] = [$class, $method, $common[0]];
        }
        $encoded = base64_encode(serialize($vectors));
        $script = 'require "tests/bootstrap.php"; foreach (unserialize(base64_decode("'.$encoded.'")) as [$class,$method,$args]) {'
            .'$class::setUseRustFfi(true); $php=$method."Php"; $native=$method."Native";'
            .'if (serialize($class::$php(...$args)) !== serialize($class::$native(...$args)) || serialize($class::$php(...$args)) !== serialize($class::$method(...$args))) throw new RuntimeException($method);'
            .'} echo "fallback verified";';
        foreach ([['-d', 'ffi.enable=0'], []] as $options) {
            $library = $options === [] ? '/tmp/wave25-2-deliberately-missing.dylib' : (getenv('NNTMUX_PIPELINE_FFI_LIB') ?: '');
            $process = new Process([PHP_BINARY, ...$options, '-r', $script], base_path(), ['NNTMUX_PIPELINE_FFI_LIB' => $library]);
            $process->mustRun();
            $this->assertSame('fallback verified', $process->getOutput());
        }
    }

    public function test_real_jobs_and_listeners_preserve_storage_throttle_and_logging(): void
    {
        $this->nativeFfi();
        $this->createTables();
        Carbon::setTestNow(Carbon::parse('2026-10-24 12:00:00.123456', 'UTC'));
        config(['nntmux.api.access_update_interval' => 60, 'nntmux.api.audit_queue' => 'audit-test']);
        foreach ([false, true] as $enabled) {
            foreach (self::CLASSES as $class) {
                $class::setUseRustFfi($enabled);
            }
            Cache::flush();
            DB::table('users')->delete();
            DB::table('user_requests')->delete();
            DB::table('users')->insert(['id' => 42, 'username' => 'test', 'host' => 'before']);
            $job = new RecordApiUsage(42, '/api?q=one', 'first', '2026-10-24 10:00:00');
            $this->assertSame('audit-test', $job->queue);
            $this->assertSame(4, $job->tries);
            $this->assertSame(15, $job->timeout);
            $job->handle();
            (new RecordApiUsage(42, '/api?q=two', 'second', '2026-10-24 10:00:01'))->handle();
            (new UpdateUserApiAccess(42, 'third', '2026-10-24 10:00:02'))->handle();
            $this->assertSame(['/api?q=one', '/api?q=two'], DB::table('user_requests')->orderBy('id')->pluck('request')->all());
            $this->assertSame('first', DB::table('users')->where('id', 42)->value('host'));
            Cache::flush();
            (new UpdateUserApiAccess(42, null, '2026-10-24 11:00:00'))->handle();
            $this->assertSame('first', DB::table('users')->where('id', 42)->first()->host);
            $user = User::findOrFail(42);
            DB::enableQueryLog();
            DB::flushQueryLog();
            (new UpdateUserAccessedApi)->handle(new UserAccessedApi($user, ''));
            $this->assertCount(1, DB::getQueryLog());
            DB::disableQueryLog();
            $this->assertSame('', DB::table('users')->where('id', 42)->value('host'));
            Log::shouldReceive('channel')->once()->with('user_login')->andReturnSelf();
            Log::shouldReceive('info')->once()->with('User logged in', \Mockery::on(static fn (array $context): bool => $context['user_id'] === 42 && $context['username'] === 'test' && $context['ip'] === '' && $context['time']->equalTo(now())));
            (new UpdateUserLoggedIn)->handle(new UserLoggedIn($user, ''));
            $this->assertSame('2026-10-24 12:00:00', DB::table('users')->where('id', 42)->value('lastlogin'));
        }
    }

    public function test_cache_degradation_keeps_audit_and_metadata_writes(): void
    {
        $this->nativeFfi();
        $this->createTables();
        foreach ([false, true] as $enabled) {
            RecordApiUsage::setUseRustFfi($enabled);
            UpdateUserApiAccess::setUseRustFfi($enabled);
            DB::table('users')->updateOrInsert(['id' => 42], ['host' => 'before']);
            Cache::shouldReceive('add')->twice()->with('api_access_update:42', true, 1)->andThrow(new RuntimeException('cache down'));
            config(['nntmux.api.access_update_interval' => -10]);
            (new RecordApiUsage(42, '/api?q=raw', 'after', '2026-01-01 00:00:00'))->handle();
            (new UpdateUserApiAccess(42, null, '2026-01-01 01:00:00'))->handle();
            $this->assertSame('after', DB::table('users')->where('id', 42)->value('host'));
            $this->assertSame('2026-01-01 01:00:00', DB::table('users')->where('id', 42)->value('apiaccess'));
        }
        $this->assertSame(2, DB::table('user_requests')->count());
    }

    public function test_real_purge_job_retains_roles_recent_activity_and_soft_delete_boundaries(): void
    {
        $this->nativeFfi();
        $this->createTables();
        Carbon::setTestNow(Carbon::parse('2026-10-24 12:00:00', 'UTC'));
        config(['nntmux.purge_inactive_users_days' => 30]);
        foreach ([false, true] as $enabled) {
            RemoveInactiveAccounts::setUseRustFfi($enabled);
            DB::table('users')->delete();
            for ($id = 1; $id <= 108; $id++) {
                DB::table('users')->insert(['id' => $id, 'username' => 'u'.$id, 'roles_id' => UserRole::USER->value,
                    'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00', 'grabs' => 0]);
            }
            foreach ([1 => ['roles_id' => UserRole::ADMIN->value], 2 => ['lastlogin' => '2026-09-24 12:00:00'],
                3 => ['apiaccess' => '2026-10-01 00:00:00'], 4 => ['lastdownload' => '2026-10-01 00:00:00'],
                5 => ['grabs' => 1, 'updated_at' => '2026-10-01 00:00:00'], 6 => ['created_at' => '2026-09-24 12:00:00'],
                7 => ['created_at' => null], 8 => ['grabs' => 1, 'updated_at' => null]] as $id => $attributes) {
                DB::table('users')->where('id', $id)->update($attributes);
            }
            (new RemoveInactiveAccounts)->handle();
            $this->assertSame(range(1, 8), User::query()->orderBy('id')->pluck('id')->all());
            $this->assertSame(108, DB::table('users')->count());
            $this->assertSame(100, DB::table('users')->whereNotNull('deleted_at')->count());
        }
    }

    public function test_raw_abi_operations_capacity_canaries_and_invalid_wire(): void
    {
        $ffi = $this->nativeFfi();
        $vectors = [
            [0, [PHP_INT_MIN, 0], ['key' => 'api_access_update:'.PHP_INT_MIN, 'interval' => 1]],
            [1, [42, "\0\xff", 'at'], ['users_id' => 42, 'request' => "\0\xff", 'timestamp' => 'at']],
            [2, ['at', null], ['apiaccess' => 'at']],
            [2, ['at', ''], ['apiaccess' => 'at', 'host' => '']],
            [3, ['at', ''], ['lastlogin' => 'at', 'host' => '']],
            [4, [2000, 3, 1, 1], [2000, 2, 29]],
            [5, [0, null, null, null, null, 0, 1], true],
            [5, [0, null, null, null, null, 1, 1], false],
            [6, [PHP_INT_MAX], 'api_access_update:'.PHP_INT_MAX],
            [7, [PHP_INT_MIN], 1],
            [8, [42, 'test', '', 'at'], ['user_id' => 42, 'username' => 'test', 'ip' => '', 'time' => 'at']],
        ];
        foreach ($vectors as [$op, $args, $expected]) {
            $input = serialize($args);
            $wire = serialize($expected);
            $this->assertSame($expected, RecordApiUsage::transformNative($op, $args), 'Raw native must succeed, without helper fallback.');
            foreach ([0, 1, strlen($wire) - 1, strlen($wire)] as $capacity) {
                $output = $ffi->new('char['.(strlen($wire) + 2).']');
                FFI::memset($output, 0x55, strlen($wire) + 2);
                // @phpstan-ignore method.notFound
                $rc = $ffi->nntmux_w25_api_audit_account_job($op, $input, strlen($input), FFI::addr($output[1]), $capacity);
                $this->assertSame($capacity < strlen($wire) ? -strlen($wire) : strlen($wire), $rc);
                $this->assertSame($capacity < strlen($wire) ? str_repeat('U', strlen($wire) + 2) : 'U'.$wire.'U', FFI::string($output, strlen($wire) + 2));
            }
        }
        foreach (['', 'N;', 'a:0:{}junk', 'a:1:{i:00;i:1;}', 'a:1:{i:0;O:8:"stdClass":0:{}}', 'a:1:{i:0;R:1;}'] as $input) {
            $output = $ffi->new('char[256]');
            FFI::memset($output, 0x55, 256);
            // @phpstan-ignore method.notFound
            $this->assertSame(-1, $ffi->nntmux_w25_api_audit_account_job(6, $input, strlen($input), $output, 256));
            $this->assertSame(str_repeat('U', 256), FFI::string($output, 256));
        }
    }

    public function test_unsupported_dates_and_oversized_native_inputs_retain_php_fallback(): void
    {
        $this->nativeFfi();
        $at = CarbonImmutable::parse('+12000-01-01 00:00:00.123456', 'UTC');
        $this->assertNull(RecordApiUsage::transformNative(4, [12000, 1, 1, 30]));
        $this->assertSame(serialize(RemoveInactiveAccounts::cutoffPhp($at, 30)), serialize(RemoveInactiveAccounts::cutoffNative($at, 30)));
        $far = CarbonImmutable::createFromTimestampUTC(9_000_000_000_001);
        $this->assertSame(RemoveInactiveAccounts::shouldPurgePhp($at, null, null, null, null, 0, $far), RemoveInactiveAccounts::shouldPurgeNative($at, null, null, null, null, 0, $far));
        $large = str_repeat('x', 67_108_864);
        $this->assertNull(RecordApiUsage::transformNative(1, [42, $large, 'at']));
        $this->assertSame(RecordApiUsage::buildAuditPayloadPhp(42, $large, 'at'), RecordApiUsage::buildAuditPayloadNative(42, $large, 'at'));
    }

    public function test_paired_benchmark(): void
    {
        if (getenv('API_AUDIT_ACCOUNT_JOB_BENCHMARK') !== '1') {
            $this->markTestSkipped('Opt in with API_AUDIT_ACCOUNT_JOB_BENCHMARK=1.');
        }
        $this->nativeFfi();
        $path = '/tmp/wave25-2-api-audit-account-job-'.gmdate('Ymd-His').'-'.getmypid().'.json';
        $report = [
            'protocol' => ['seed' => 25022026, 'rounds' => 3, 'pairs_per_round' => 41, 'calls_per_side_per_pair' => 200,
                'warmup_per_side_per_round' => 10, 'clock' => 'hrtime(true), individual ns', 'p99' => 'nearest rank',
                'order' => 'random side order and identical random input indices per pair',
                'gate' => 'Every common/uncommon mean AND p99 speedup >=1.5; no averaging across helpers.',
                'uncertainty' => 'Three-round min/max; descriptive, not a confidence interval. 8200 calls/side/round, 24600/cell.',
                'scope' => 'Pure Php/Native helpers including normalization, serialization, allocation, FFI and reconstruction. Excludes app boot and storage I/O; no production throughput claim.',
                'reproduce' => 'API_AUDIT_ACCOUNT_JOB_BENCHMARK=1 NNTMUX_PIPELINE_FFI_LIB=/tmp/cargo-w25-2/release/libnntmux_pipeline.dylib php artisan test --compact tests/Unit/RustApiAuditAccountJobParityTest.php --filter=test_paired_benchmark'],
            'head' => self::command(['git', 'rev-parse', 'HEAD']), 'status' => self::command(['git', 'status', '--short']),
            'host' => php_uname(), 'hardware' => self::command(PHP_OS_FAMILY === 'Darwin' ? ['sysctl', '-n', 'machdep.cpu.brand_string', 'hw.model', 'hw.ncpu', 'hw.memsize'] : ['lscpu']),
            'resource_limits' => self::command(['/bin/sh', '-c', 'ulimit -a']),
            'php' => ['version' => PHP_VERSION, 'binary' => PHP_BINARY, 'memory_limit' => ini_get('memory_limit'), 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')],
            'rustc' => self::command(['rustc', '--version']), 'cargo' => self::command(['cargo', '--version']),
            'build' => 'CARGO_TARGET_DIR=/tmp/cargo-w25-2 cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release',
            'source_sha256' => [], 'results' => [], 'failures' => [],
        ];
        foreach (['rust/pipeline-ffi/src/nfo.rs', 'rust/pipeline-ffi/Cargo.toml', 'rust/pipeline-ffi/Cargo.lock', 'composer.lock', 'vendor/composer/installed.php', 'app/Support/RustPipelineLibrary.php',
            'app/Jobs/RecordApiUsage.php', 'app/Jobs/UpdateUserApiAccess.php', 'app/Jobs/RemoveInactiveAccounts.php',
            'app/Listeners/UpdateUserAccessedApi.php', 'app/Listeners/UpdateUserLoggedIn.php', 'tests/Unit/RustApiAuditAccountJobParityTest.php'] as $file) {
            $report['source_sha256'][$file] = hash_file('sha256', base_path($file));
        }
        $library = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $this->assertNotFalse($library, 'Benchmark requires explicit library identity.');
        $report['library'] = ['path' => realpath($library), 'sha256' => hash_file('sha256', $library)];
        $report['protocol_sha256'] = hash('sha256', serialize($report['protocol']));
        $random = new Randomizer(new Mt19937(25022026));
        try {
            foreach (self::workloads() as [$class, $method, $common, $uncommon]) {
                $name = $class.'::'.$method;
                $php = $method.'Php';
                $native = $method.'Native';
                foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $inputs) {
                    $expected = [];
                    foreach ($inputs as $args) {
                        $expected[] = serialize($class::$php(...$args));
                        $this->assertSame(end($expected), serialize($class::$native(...$args)), $name.' preflight');
                    }
                    $cell = ['input_sha256' => hash('sha256', serialize($inputs)), 'rounds' => []];
                    $aggregate = ['php' => [], 'native' => []];
                    for ($round = 0; $round < 3; $round++) {
                        foreach ([$php, $native] as $warm) {
                            for ($i = 0; $i < 10; $i++) {
                                $class::$warm(...$inputs[$i % count($inputs)]);
                            }
                        }
                        $raw = ['php' => [], 'native' => []];
                        $pairs = [];
                        for ($pair = 0; $pair < 41; $pair++) {
                            $indices = [];
                            for ($i = 0; $i < 200; $i++) {
                                $indices[] = $random->getInt(0, count($inputs) - 1);
                            }
                            $order = $random->getInt(0, 1) === 0 ? ['php', 'native'] : ['native', 'php'];
                            $pairs[] = ['order' => $order, 'input_indices' => $indices];
                            foreach ($order as $side) {
                                $timed = $side === 'php' ? $php : $native;
                                foreach ($indices as $index) {
                                    $args = $inputs[$index];
                                    $start = hrtime(true);
                                    $result = $class::$timed(...$args);
                                    $elapsed = hrtime(true) - $start;
                                    if (serialize($result) !== $expected[$index]) {
                                        throw new RuntimeException($name.'/'.$kind.' mismatch during timing');
                                    }
                                    $raw[$side][] = $elapsed;
                                }
                            }
                        }
                        $phpStats = self::statistics($raw['php']);
                        $nativeStats = self::statistics($raw['native']);
                        $cell['rounds'][] = ['pairs' => $pairs, 'raw_ns' => $raw, 'php' => $phpStats, 'native' => $nativeStats,
                            'mean_speedup' => $phpStats['mean_ns'] / $nativeStats['mean_ns'], 'p99_speedup' => $phpStats['p99_ns'] / $nativeStats['p99_ns']];
                        foreach (['php', 'native'] as $side) {
                            array_push($aggregate[$side], ...$raw[$side]);
                        }
                    }
                    $phpStats = self::statistics($aggregate['php']);
                    $nativeStats = self::statistics($aggregate['native']);
                    $cell['aggregate'] = ['php' => $phpStats, 'native' => $nativeStats,
                        'mean_speedup' => $phpStats['mean_ns'] / $nativeStats['mean_ns'], 'p99_speedup' => $phpStats['p99_ns'] / $nativeStats['p99_ns']];
                    foreach (['mean_speedup', 'p99_speedup'] as $metric) {
                        $values = array_column($cell['rounds'], $metric);
                        $cell['round_range'][$metric] = ['min' => min($values), 'max' => max($values)];
                    }
                    $report['results'][$name][$kind] = $cell;
                }
                $report['results'][$name]['qualifies'] = $report['results'][$name]['common']['aggregate']['mean_speedup'] >= 1.5
                    && $report['results'][$name]['common']['aggregate']['p99_speedup'] >= 1.5
                    && $report['results'][$name]['uncommon']['aggregate']['mean_speedup'] >= 1.5
                    && $report['results'][$name]['uncommon']['aggregate']['p99_speedup'] >= 1.5;
            }
        } catch (Throwable $error) {
            $report['failures'][] = ['type' => $error::class, 'message' => $error->getMessage()];
            $report['partial'] = ['helper' => $name ?? null, 'kind' => $kind ?? null, 'round' => $round ?? null, 'pair' => $pair ?? null,
                'completed_rounds' => $cell ?? [], 'pairs' => $pairs ?? [], 'raw_ns' => $raw ?? []];
            throw $error;
        } finally {
            $report['finished_at_utc'] = gmdate(DATE_ATOM);
            if (file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)) === false) {
                throw new RuntimeException('Cannot preserve benchmark evidence at '.$path);
            }
            fwrite(STDERR, "\nWave 25.2 raw benchmark evidence: {$path}\n");
        }
        $this->assertFileExists($path);
    }

    /** @param list<int> $values
     * @return array{count: int, mean_ns: float, p99_ns: int, min_ns: int, max_ns: int}
     */
    private static function statistics(array $values): array
    {
        sort($values, SORT_NUMERIC);
        $count = count($values);

        return ['count' => $count, 'mean_ns' => array_sum($values) / $count, 'p99_ns' => $values[(int) ceil($count * 0.99) - 1],
            'min_ns' => $values[0], 'max_ns' => $values[$count - 1]];
    }

    /** @param list<string> $args */
    private static function command(array $args): string
    {
        $process = new Process($args, base_path());
        $process->mustRun();

        return trim($process->getOutput());
    }

    private function createTables(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge();
        DB::reconnect();
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('username')->default('test');
            $table->integer('roles_id')->default(1);
            $table->integer('grabs')->default(0);
            $table->string('host')->nullable();
            $table->string('deleted_by')->nullable();
            $table->string('email')->nullable();
            $table->string('api_token')->nullable();
            foreach (['apiaccess', 'lastlogin', 'lastdownload', 'created_at', 'updated_at', 'deleted_at'] as $field) {
                $table->dateTime($field)->nullable();
            }
        });
        Schema::create('user_activities', static function (Blueprint $table): void {
            $table->id();
            $table->integer('user_id')->nullable();
            $table->string('username');
            $table->string('activity_type');
            $table->text('description');
            $table->text('metadata')->nullable();
            $table->boolean('is_permanent')->default(false);
            $table->dateTime('created_at');
        });
        Schema::create('user_requests', static function (Blueprint $table): void {
            $table->id();
            $table->integer('users_id');
            $table->text('request');
            $table->dateTime('timestamp');
        });
    }

    private function nativeFfi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('w25-api-audit-account-job-test', RecordApiUsage::FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        $this->assertNotNull($ffi, 'The explicit release library and Wave 25.2 export are required; never silently skip native coverage.');

        return $ffi;
    }

    /** @return list<array{class-string, string, list<array<mixed>>, list<array<mixed>>}> */
    private static function workloads(): array
    {
        $old = CarbonImmutable::parse('2020-01-01 00:00:00.123456', 'UTC');
        $at = CarbonImmutable::parse('2026-10-24 13:14:15.123456', 'Europe/Madrid');
        $uncommon = str_repeat("é\0\xff", 2048);
        $workloads = [
            [RecordApiUsage::class, 'cacheKey', [[1], [42]], [[PHP_INT_MIN], [PHP_INT_MAX], [0]]],
            [RecordApiUsage::class, 'normalizeInterval', [[60], [1]], [[PHP_INT_MIN], [PHP_INT_MAX], [0]]],
            [RecordApiUsage::class, 'buildAuditPayload', [[42, '/api?q=test', '2026-10-24 12:00:00']], [[PHP_INT_MIN, $uncommon, ''], [PHP_INT_MAX, '', "\0\xff"]]],
            [RemoveInactiveAccounts::class, 'cutoff', [[$at, 30], [$at, 365]], [[$at, -366], [CarbonImmutable::parse('2000-03-01', 'UTC'), 1],
                [Carbon::parse('2026-03-30 02:30:00.123456', 'Europe/Madrid'), 1],
                [CarbonImmutable::parse('2026-10-26 02:30:00.654321', 'Europe/Madrid'), 1]]],
            [RemoveInactiveAccounts::class, 'shouldPurge', [[$old, $old, null, null, null, 0, $at], [$old, $old, $at, null, null, 1, $at]], [[null, null, null, null, null, PHP_INT_MAX, $at], [$old, $at, $old, $old, $old, -1, $at]]],
            [UpdateUserLoggedIn::class, 'buildUpdateAttributes', [[$at, '127.0.0.1']], [['', ''], [$at, $uncommon]]],
            [UpdateUserLoggedIn::class, 'buildLogPayload', [[42, 'test', '127.0.0.1', $at]], [[PHP_INT_MIN, $uncommon, '', $at], [PHP_INT_MAX, '', "\0\xff", '']]],
        ];
        foreach ([RecordApiUsage::class, UpdateUserApiAccess::class] as $class) {
            $workloads[] = [$class, 'buildAccessBucket', [[42, 60], [1, 1]], [[PHP_INT_MIN, PHP_INT_MAX], [PHP_INT_MAX, PHP_INT_MIN]]];
        }
        foreach ([RecordApiUsage::class, UpdateUserApiAccess::class, UpdateUserAccessedApi::class] as $class) {
            $workloads[] = [$class, 'buildUpdateAttributes', [['2026-10-24 12:00:00', null], [$at, '127.0.0.1']], [['', ''], [$at, $uncommon]]];
        }

        return $workloads;
    }
}
