<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\DownloadStat;
use App\Models\GrabStat;
use App\Models\ReleaseStat;
use App\Models\RoleStat;
use App\Models\SignupStat;
use App\Support\RustPipelineLibrary;
use Carbon\Carbon;
use FFI;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

/**
 * Wave 26.2: scalar payload experiments only; SQL aggregation stays database-side.
 * Run the paired caller-inclusive benchmark with --group=benchmark. Raw timings,
 * randomized order, fixtures, source/library identity and failures go to /tmp.
 * PHP remains the production default unless every required cell qualifies.
 */
final class RustStatAggregationModelsParityTest extends TestCase
{
    private const array CLASSES = [DownloadStat::class, GrabStat::class, ReleaseStat::class, RoleStat::class, SignupStat::class];

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

    public function test_defaults_and_original_payload_values(): void
    {
        foreach (self::CLASSES as $class) {
            $this->assertTrue(method_exists($class, 'usesRustFfi'), $class.' toggle missing');
            $this->assertFalse((new ReflectionClass($class))->getDefaultProperties()['useRustFfi']);
            $this->assertFalse($class::usesRustFfi());
        }
        $this->assertSame(['searchname' => 'Release', 'guid' => 'guid', 'adddate' => 'at', 'grabs' => '001'], DownloadStat::buildRow('Release', 'guid', 'at', '001'));
        $this->assertSame(['attributes' => ['username' => 'name'], 'values' => ['grabs' => '1']], GrabStat::buildPayload('name', '1'));
        $this->assertSame('Movies > HD', ReleaseStat::displayName('HD', 'Movies', true));
        $this->assertSame(' > HD', ReleaseStat::displayName('HD', '', true));
        $this->assertNull(ReleaseStat::displayName(null, 'ignored', false));
        $this->assertSame([10, 20], ReleaseStat::excludedCategoryIds());
        $this->assertSame(['category' => 'Movies > HD', 'count' => '02'], ReleaseStat::buildRow('Movies > HD', '02'));
        $this->assertSame(['role' => 'Admin', 'users' => 2], RoleStat::buildRow('Admin', 2));
        $this->assertSame(['attributes' => ['month' => 'October 2026'], 'values' => ['signups' => '03', 'sort_date' => '2026-10-01']], SignupStat::buildPayload('October 2026', '03', '2026-10-01'));
    }

    public function test_native_and_dispatch_parity_preserves_binary_strings_nulls_and_numeric_types(): void
    {
        $this->nativeFfi();
        foreach (self::workloads() as [$class, $method, $common, $uncommon]) {
            foreach ([...$common, ...$uncommon] as $args) {
                $php = $method.'Php';
                $native = $method.'Native';
                $expected = serialize($class::$php(...$args));
                $this->assertSame($expected, serialize($class::$native(...$args)), $class.'::'.$native);
                foreach ([false, true] as $enabled) {
                    $class::setUseRustFfi($enabled);
                    $this->assertSame($expected, serialize($class::$method(...$args)), $class.'::'.$method);
                }
            }
        }
        foreach ([null, false, true, PHP_INT_MIN, PHP_INT_MAX, -0.0, 1.25, INF, -INF, NAN, '00042', "é\0\xff"] as $value) {
            foreach ([0 => [$value, $value, $value, $value], 1 => [$value, $value], 4 => [$value, $value], 5 => [$value, $value], 6 => [$value, $value, $value]] as $op => $args) {
                $expected = self::expected($op, $args);
                $this->assertSame(serialize($expected), serialize(DownloadStat::transformNative($op, $args)), 'Raw native must succeed without fallback.');
            }
        }
    }

    public function test_object_and_array_values_fall_back_without_serializing_or_losing_identity(): void
    {
        $this->nativeFfi();
        $at = Carbon::parse('2026-10-24 12:34:56.123456', 'Europe/Madrid');
        $object = new class
        {
            public function __serialize(): array
            {
                throw new RuntimeException('Objects must never be serialized by the native bridge.');
            }

            public function __toString(): string
            {
                return 'title';
            }
        };
        $this->assertNull(DownloadStat::transformNative(0, ['name', 'guid', $object, 1]));
        $this->assertSame($at, DownloadStat::buildRowNative('name', 'guid', $at, 1)['adddate']);
        $this->assertSame($object, GrabStat::buildPayloadNative($object, [1])['attributes']['username']);
        $this->assertSame($object, ReleaseStat::displayNameNative($object));
        $this->assertSame('title > title', ReleaseStat::displayNameNative($object, $object, true));
        $this->assertSame($object, ReleaseStat::buildRowNative($object, [1])['category']);
        $this->assertSame([1], RoleStat::buildRowNative('name', [1])['users']);
        $this->assertSame($at, SignupStat::buildPayloadNative('October 2026', 1, $at)['values']['sort_date']);
        $large = str_repeat('x', 67_108_864);
        $this->assertNull(DownloadStat::transformNative(0, [$large, '', '', 1]));
        $this->assertSame($large, DownloadStat::buildRowNative($large, '', '', 1)['searchname']);
    }

    public function test_missing_or_disabled_ffi_falls_back_in_fresh_processes(): void
    {
        $vectors = [];
        foreach (self::workloads() as [$class, $method, $common]) {
            $vectors[] = [$class, $method, $common[0]];
        }
        $encoded = base64_encode(serialize($vectors));
        $script = 'require "tests/bootstrap.php"; foreach (unserialize(base64_decode("'.$encoded.'")) as [$class,$method,$args]) {'
            .'$class::setUseRustFfi(true); $php=$method."Php"; $native=$method."Native";'
            .'if (serialize($class::$php(...$args)) !== serialize($class::$native(...$args)) || serialize($class::$php(...$args)) !== serialize($class::$method(...$args))) throw new RuntimeException($method);'
            .'} echo "fallback verified";';
        foreach ([['-d', 'ffi.enable=0'], []] as $options) {
            $library = $options === [] ? '/tmp/w26-2-deliberately-missing.dylib' : (getenv('NNTMUX_PIPELINE_FFI_LIB') ?: '');
            $process = new Process([PHP_BINARY, ...$options, '-r', $script], base_path(), ['NNTMUX_PIPELINE_FFI_LIB' => $library]);
            $process->mustRun();
            $this->assertSame('fallback verified', $process->getOutput());
        }
    }

    public function test_cached_loader_failure_and_ffi_call_errors_retain_php_fallback(): void
    {
        $this->nativeFfi();
        $property = (new ReflectionClass(RustPipelineLibrary::class))->getProperty('domainLibraries');
        $saved = $property->getValue();
        $this->assertIsArray($saved);
        $library = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $this->assertNotFalse($library);
        $key = 'w26-stat-aggregation-models|'.$library.'|'.hash('xxh3', DownloadStat::FFI_CDEF);
        // A genuine FFI handle without the export forces a call-time exception.
        $missingSymbol = FFI::cdef('uint32_t nntmux_pipeline_abi_version(void);', $library);
        try {
            foreach ([false, $missingSymbol] as $handle) {
                $property->setValue(null, [$key => $handle] + $saved);
                foreach (self::workloads() as [$class, $method, $common]) {
                    $php = $method.'Php';
                    $native = $method.'Native';
                    $class::setUseRustFfi(true);
                    $expected = serialize($class::$php(...$common[0]));
                    $this->assertSame($expected, serialize($class::$native(...$common[0])));
                    $this->assertSame($expected, serialize($class::$method(...$common[0])));
                }
            }
        } finally {
            $property->setValue(null, $saved);
        }
    }

    public function test_raw_abi_all_operations_capacity_canaries_and_invalid_wire(): void
    {
        $ffi = $this->nativeFfi();
        $vectors = [[0, ['name', 'guid', null, '0001']], [1, ['name', PHP_INT_MAX]], [2, ['HD', '', true]],
            [2, [null, null, false]], [3, []], [4, ["é\0\xff", '02']], [5, ['Admin', 1.25]], [6, ['', 0, null]]];
        foreach ($vectors as [$op, $args]) {
            $input = serialize($args);
            $expected = self::expected($op, $args);
            $wire = serialize($expected);
            $this->assertSame($wire, serialize(DownloadStat::transformNative($op, $args)));
            foreach ([0, 1, strlen($wire) - 1, strlen($wire), strlen($wire) + 1] as $capacity) {
                $output = $ffi->new('char['.(strlen($wire) + 3).']');
                FFI::memset($output, 0x55, strlen($wire) + 3);
                // @phpstan-ignore method.notFound
                $rc = $ffi->nntmux_w26_stat_aggregation_models($op, $input, strlen($input), FFI::addr($output[1]), $capacity);
                $this->assertSame($capacity < strlen($wire) ? -strlen($wire) : strlen($wire), $rc);
                $this->assertSame($capacity < strlen($wire) ? str_repeat('U', strlen($wire) + 3) : 'U'.$wire.'UU', FFI::string($output, strlen($wire) + 3));
            }
        }
        foreach (['', 'N;', 'a:0:{}junk', 'a:01:{i:0;i:1;}', 'a:1:{i:00;i:1;}', 'a:1:{i:0;i:+1;}',
            'a:1:{i:0;O:8:"stdClass":0:{}}', 'a:1:{i:0;R:1;}', 'a:1:{s:1:"0";i:1;}'] as $input) {
            $output = $ffi->new('char[256]');
            FFI::memset($output, 0x55, 256);
            // @phpstan-ignore method.notFound
            $this->assertSame(-1, $ffi->nntmux_w26_stat_aggregation_models(0, $input, strlen($input), $output, 256));
            $this->assertSame(str_repeat('U', 256), FFI::string($output, 256));
        }
        foreach ([[99, []], [0, []], [3, [1]], [2, ['title', null, true]], [2, ['title', 'root', 1]], [5, [[], 1]]] as [$op, $args]) {
            $this->assertNull(DownloadStat::transformNative($op, $args));
        }
    }

    public function test_database_callers_preserve_aggregation_limits_updates_and_read_order(): void
    {
        $this->nativeFfi();
        $this->createTables();
        Carbon::setTestNow(Carbon::parse('2026-10-24 12:00:00', 'UTC'));
        $snapshots = [];
        foreach ([false, true] as $enabled) {
            foreach (self::CLASSES as $class) {
                $class::setUseRustFfi($enabled);
            }
            foreach (['releases', 'users', 'root_categories', 'categories', 'roles', 'model_has_roles', 'download_stats', 'grab_stats', 'release_stats', 'role_stats', 'signup_stats'] as $table) {
                DB::table($table)->delete();
            }
            DB::table('root_categories')->insert(['id' => 2000, 'title' => 'Movies']);
            DB::table('categories')->insert([
                ['id' => 2010, 'root_categories_id' => 2000, 'title' => 'HD'],
                ['id' => 2020, 'root_categories_id' => null, 'title' => 'No parent'],
                ['id' => 10, 'root_categories_id' => null, 'title' => 'Misc'],
                ['id' => 20, 'root_categories_id' => null, 'title' => 'Hashed'],
            ]);
            DB::table('roles')->insert([['id' => 1, 'name' => 'Admin', 'guard_name' => 'web'], ['id' => 2, 'name' => 'Empty', 'guard_name' => 'web']]);
            for ($i = 1; $i <= 12; $i++) {
                DB::table('releases')->insert(['id' => $i, 'searchname' => 'Release '.$i, 'guid' => 'guid'.$i,
                    'adddate' => '2026-10-24 10:00:00', 'grabs' => $i, 'categories_id' => $i === 1 ? 2020 : 2010]);
                DB::table('users')->insert(['id' => $i, 'username' => 'User '.$i, 'grabs' => $i,
                    'created_at' => $i < 3 ? '2026-09-01 10:00:00' : '2026-10-01 10:00:00', 'deleted_at' => null]);
                DB::table('model_has_roles')->insert(['role_id' => 1, 'model_type' => 'App\\Models\\User', 'model_id' => $i]);
            }
            foreach ([10, 20, 2010] as $id) {
                DB::table('releases')->insert(['id' => 100 + $id, 'searchname' => 'excluded/old', 'guid' => 'extra'.$id,
                    'adddate' => $id === 2010 ? '2026-01-01 00:00:00' : '2026-10-24 10:00:00', 'grabs' => 0, 'categories_id' => $id]);
            }
            DB::table('users')->insert(['id' => 99, 'username' => 'Deleted', 'grabs' => 1000, 'created_at' => '2026-10-01', 'deleted_at' => '2026-10-02']);
            foreach ([100 => null, 101 => '0000-00-00 00:00:00'] as $id => $created) {
                DB::table('users')->insert(['id' => $id, 'username' => 'Invalid date '.$id, 'grabs' => 0, 'created_at' => $created, 'deleted_at' => null]);
            }
            for ($pass = 0; $pass < 2; $pass++) {
                DownloadStat::insertTopDownloads();
                GrabStat::insertTopGrabbers();
                ReleaseStat::insertRecentlyAdded();
                RoleStat::insertUsersByRole();
                SignupStat::insertUsersByMonth();
            }
            $downloads = DownloadStat::getTopDownloads();
            $grabs = GrabStat::getTopGrabbers();
            $releases = ReleaseStat::getRecentlyAdded();
            $roles = RoleStat::getUsersByRole();
            $signups = SignupStat::getUsersByMonth();
            $this->assertCount(10, $downloads);
            $this->assertSame('Release 12', $downloads[0]['searchname']);
            $this->assertSame('Release 3', $downloads[9]['searchname']);
            $this->assertCount(10, $grabs);
            $this->assertSame('User 12', $grabs[0]['username']);
            $this->assertSame([['category' => 'Movies > HD', 'count' => 11], ['category' => 'No parent', 'count' => 1]], $releases);
            $this->assertSame([['role' => 'Admin', 'users' => 12]], $roles);
            $this->assertSame([['month' => 'October 2026', 'signups' => 10], ['month' => 'September 2026', 'signups' => 2]], $signups);
            DB::table('releases')->insert(['id' => 9999, 'searchname' => 'new', 'guid' => 'new', 'adddate' => '2026-10-24 11:00:00', 'grabs' => 0, 'categories_id' => 2010]);
            DB::table('model_has_roles')->where('model_id', 1)->delete();
            DB::table('users')->where('id', 12)->update(['grabs' => 42, 'created_at' => '2026-09-01']);
            DB::table('releases')->where('id', 12)->update(['grabs' => 42]);
            DownloadStat::insertTopDownloads();
            // Grabs is intentionally part of DownloadStat's lookup attributes:
            // a changed count adds a row, matching the original updateOrCreate.
            $this->assertCount(11, DownloadStat::getTopDownloads());
            $this->assertSame(2, DownloadStat::query()->where('searchname', 'Release 12')->count());
            GrabStat::insertTopGrabbers();
            ReleaseStat::insertRecentlyAdded();
            RoleStat::insertUsersByRole();
            SignupStat::insertUsersByMonth();
            $this->assertSame(42, GrabStat::getTopGrabbers()[0]['grabs']);
            $this->assertSame(12, ReleaseStat::getRecentlyAdded()[0]['count']);
            $this->assertSame(11, RoleStat::getUsersByRole()[0]['users']);
            $this->assertSame([['month' => 'October 2026', 'signups' => 9], ['month' => 'September 2026', 'signups' => 3]], SignupStat::getUsersByMonth());
            $snapshots[] = [$downloads, $grabs, $releases, $roles, $signups];
        }
        $this->assertSame($snapshots[0], $snapshots[1]);
    }

    #[Group('benchmark')]
    public function test_paired_randomized_benchmarks(): void
    {
        if (! in_array('--group=benchmark', $_SERVER['argv'] ?? [], true)) {
            $this->markTestSkipped('Opt in with --group=benchmark.');
        }
        $this->nativeFfi();
        $path = '/tmp/wave26-2-stat-aggregation-models-'.gmdate('Ymd-His').'-'.getmypid().'.json';
        $report = [
            'protocol' => ['seed' => 26022026, 'rounds' => 3, 'pairs_per_round' => 41, 'calls_per_side_per_pair' => 200,
                'warmup_per_side_per_round' => 64, 'clock' => 'hrtime(true), individual ns', 'p99' => 'nearest rank',
                'order' => 'Random side order, identical randomized input indices per pair; toggle and parity outside timing.',
                'gate' => 'Each common/uncommon mean AND p99 speedup >=1.5 in every round; no averaging across helpers.',
                'uncertainty' => 'Three-round min/max; descriptive, not a confidence interval. 8200 calls/side/round, 24600/cell.',
                'scope' => 'Public helper dispatch including scalar normalization, serialization, allocation, FFI and reconstruction. SQL stays unchanged; excludes app boot and database I/O. No production throughput claim.',
                'reproduce' => 'NNTMUX_PIPELINE_FFI_LIB=/tmp/cargo-w26-2/release/libnntmux_pipeline.dylib php artisan test --compact tests/Unit/RustStatAggregationModelsParityTest.php --group=benchmark'],
            'started_at_utc' => gmdate(DATE_ATOM),
            'head' => self::command(['git', 'rev-parse', 'HEAD']), 'status' => self::command(['git', 'status', '--short']),
            'host' => php_uname(), 'hardware' => self::command(PHP_OS_FAMILY === 'Darwin' ? ['sysctl', '-n', 'machdep.cpu.brand_string', 'hw.model', 'hw.ncpu', 'hw.memsize'] : ['lscpu']),
            'resource_limits' => self::command(['/bin/sh', '-c', 'ulimit -a']),
            'php' => ['version' => PHP_VERSION, 'binary' => PHP_BINARY, 'memory_limit' => ini_get('memory_limit'), 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')],
            'rustc' => self::command(['rustc', '--version']), 'cargo' => self::command(['cargo', '--version']),
            'build' => 'CARGO_TARGET_DIR=/tmp/cargo-w26-2 cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release',
            'profile' => 'opt-level=3, lto=true, codegen-units=1',
            'source_sha256' => [], 'results' => [], 'failures' => [],
        ];
        foreach (['rust/pipeline-ffi/src/nfo.rs', 'rust/pipeline-ffi/Cargo.toml', 'rust/pipeline-ffi/Cargo.lock', 'composer.lock', 'vendor/composer/installed.php', 'app/Support/RustPipelineLibrary.php',
            'app/Models/DownloadStat.php', 'app/Models/GrabStat.php', 'app/Models/ReleaseStat.php', 'app/Models/RoleStat.php', 'app/Models/SignupStat.php', 'tests/Unit/RustStatAggregationModelsParityTest.php'] as $file) {
            $report['source_sha256'][$file] = hash_file('sha256', base_path($file));
        }
        $library = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $this->assertNotFalse($library, 'Benchmark requires explicit library identity.');
        $report['library'] = ['path' => realpath($library), 'sha256' => hash_file('sha256', $library)];
        $report['protocol_sha256'] = hash('sha256', serialize($report['protocol']));
        $random = new Randomizer(new Mt19937(26022026));
        try {
            foreach (self::workloads() as [$class, $method, $common, $uncommon]) {
                $name = $class.'::'.$method;
                $php = $method.'Php';
                foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $inputs) {
                    $expected = [];
                    foreach ($inputs as $args) {
                        $expected[] = serialize($class::$php(...$args));
                        $class::setUseRustFfi(true);
                        $this->assertSame(end($expected), serialize($class::$method(...$args)), $name.' preflight');
                    }
                    $cell = ['input_sha256' => hash('sha256', serialize($inputs)), 'input_base64_php_serialized' => base64_encode(serialize($inputs)), 'rounds' => []];
                    $aggregate = ['php' => [], 'native' => []];
                    for ($round = 0; $round < 3; $round++) {
                        foreach ([false, true] as $enabled) {
                            $class::setUseRustFfi($enabled);
                            for ($i = 0; $i < 64; $i++) {
                                $class::$method(...$inputs[$i % count($inputs)]);
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
                                $class::setUseRustFfi($side === 'native');
                                foreach ($indices as $index) {
                                    $args = $inputs[$index];
                                    $start = hrtime(true);
                                    $result = $class::$method(...$args);
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
                    $cell['qualifies'] = true;
                    foreach (['mean_speedup', 'p99_speedup'] as $metric) {
                        $values = array_column($cell['rounds'], $metric);
                        $cell['round_range'][$metric] = ['min' => min($values), 'max' => max($values)];
                        $cell['qualifies'] = $cell['qualifies'] && min($values) >= 1.5;
                    }
                    $report['results'][$name][$kind] = $cell;
                }
                $report['results'][$name]['qualifies'] = $report['results'][$name]['common']['qualifies'] && $report['results'][$name]['uncommon']['qualifies'];
                $class::setUseRustFfi(false);
                $this->assertFalse($class::usesRustFfi());
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
            fwrite(STDERR, "\nWave 26.2 raw benchmark evidence: {$path}\n");
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

    private function nativeFfi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('w26-stat-aggregation-models-test', DownloadStat::FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        $this->assertNotNull($ffi, 'The explicit release library and Wave 26.2 export are required; never skip native coverage.');

        return $ffi;
    }

    /** @param list<mixed> $args */
    private static function expected(int $op, array $args): mixed
    {
        return match ($op) {
            0 => ['searchname' => $args[0], 'guid' => $args[1], 'adddate' => $args[2], 'grabs' => $args[3]],
            1 => ['attributes' => ['username' => $args[0]], 'values' => ['grabs' => $args[1]]],
            2 => $args[2] ? $args[1].' > '.$args[0] : $args[0],
            3 => [10, 20],
            4 => ['category' => $args[0], 'count' => $args[1]],
            5 => ['role' => $args[0], 'users' => $args[1]],
            6 => ['attributes' => ['month' => $args[0]], 'values' => ['signups' => $args[1], 'sort_date' => $args[2]]],
            default => throw new RuntimeException('Unsupported test operation: '.$op),
        };
    }

    /** @return list<array{class-string, string, list<list<mixed>>, list<list<mixed>>}> */
    private static function workloads(): array
    {
        $long = str_repeat("é\0\xff", 2048);

        return [
            [DownloadStat::class, 'buildRow', [['Release', 'guid', '2026-10-24 12:00:00', 42], ['Release 2', 'guid2', null, '001']], [[$long, "\xff\0", '', PHP_INT_MAX], [null, false, -0.0, NAN]]],
            [GrabStat::class, 'buildPayload', [['name', 42], ['name 2', '001']], [[$long, PHP_INT_MIN], [null, null], ['', 1.25]]],
            [ReleaseStat::class, 'displayName', [['HD', 'Movies', true], ['No parent', null, false]], [[$long, "\0\xff", true], ['', '', true], [null, null, false], [1.25, false, true]]],
            [ReleaseStat::class, 'excludedCategoryIds', [[]], [[]]],
            [ReleaseStat::class, 'buildRow', [['Movies > HD', 42], ['No parent', '001']], [[$long, PHP_INT_MAX], [null, -0.0], ['', NAN]]],
            [RoleStat::class, 'buildRow', [['Admin', 42], ['Member', '001']], [[$long, PHP_INT_MAX], [null, null], ['', INF]]],
            [SignupStat::class, 'buildPayload', [['October 2026', 42, '2026-10-01']], [[$long, PHP_INT_MIN, ''], ['', '001', null], [null, -INF, false]]],
        ];
    }

    private function createTables(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge();
        DB::reconnect();
        DB::connection()->getPdo()->sqliteCreateFunction('DATE_FORMAT', static function (?string $value, string $format): ?string {
            if ($value === null || $value === '0000-00-00 00:00:00') {
                return null;
            }

            return Carbon::parse($value)->format($format === '%Y-%m-01' ? 'Y-m-01' : 'F Y');
        }, 2);
        Schema::create('releases', static function (Blueprint $table): void {
            $table->id();
            foreach (['searchname', 'guid', 'adddate'] as $column) {
                $table->string($column);
            }
            $table->integer('grabs');
            $table->integer('categories_id');
        });
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('username');
            $table->integer('grabs');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('deleted_at')->nullable();
        });
        Schema::create('root_categories', static function (Blueprint $table): void {
            $table->id();
            $table->string('title');
        });
        Schema::create('categories', static function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->integer('root_categories_id')->nullable();
        });
        Schema::create('roles', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
        });
        Schema::create('model_has_roles', static function (Blueprint $table): void {
            $table->integer('role_id');
            $table->string('model_type');
            $table->integer('model_id');
        });
        foreach (['download_stats' => ['searchname', 'guid', 'adddate', 'grabs'], 'grab_stats' => ['username', 'grabs'],
            'release_stats' => ['category', 'count'], 'role_stats' => ['role', 'users'], 'signup_stats' => ['month', 'signups', 'sort_date']] as $name => $columns) {
            Schema::create($name, static function (Blueprint $table) use ($columns): void {
                $table->id();
                foreach ($columns as $column) {
                    if (in_array($column, ['grabs', 'count', 'users', 'signups'], true)) {
                        $table->integer($column);
                    } else {
                        $table->string($column)->nullable();
                    }
                }
                $table->timestamps();
            });
        }
    }
}
