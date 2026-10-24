<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Requests\Admin\AdminGroupListRequest as Groups;
use App\Http\Requests\Admin\AdminReleaseListRequest as Releases;
use App\Http\Requests\Admin\AdminReleaseReportBulkActionRequest as Bulk;
use App\Http\Requests\Admin\AdminReleaseReportListRequest as Reports;
use App\Http\Requests\Admin\AdminUserListRequest as Users;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\Validator;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Wave 22.1: byte-exact parity, fallback, ABI safety and end-to-end paired benchmarks.
 * Reproduce: NNTMUX_WAVE22_BENCH=1 php artisan test --compact <this file>.
 * Protocol: release cdylib; 200 warmups; 3 repetitions x 41 randomized pairs x 200 calls.
 * p99 is the nearest-rank p99 of 41 per-pair batch latencies (not individual-call tails).
 *
 * Recorded on macOS arm64 / PHP 8.5.12 / rustc 1.99.1, optimized release cdylib.
 * Raw samples, randomized orders, fixture/source hashes: /tmp/nntmux-wave22-benchmark.json.
 * Each row: PHP mean/p99 ns; Rust mean/p99 ns; worst repetition mean/p99 speedup.
 * Means and p99 latencies below are averaged across the three repetitions.
 * AdminGroupListRequest::trimmedInputs (common):
 *   PHP 139.7/299.9; Rust 876.7/1526.2;
 *   worst speedup 0.152x/0.152x.
 * AdminGroupListRequest::trimmedInputs (uncommon):
 *   PHP 681.2/1175.1; Rust 3382.4/6233.9;
 *   worst speedup 0.197x/0.132x.
 * AdminGroupListRequest::groupNameFrom (common):
 *   PHP 57.0/92.8; Rust 290.7/442.6;
 *   worst speedup 0.189x/0.191x.
 * AdminGroupListRequest::groupNameFrom (uncommon):
 *   PHP 58.1/97.1; Rust 322.5/630.4;
 *   worst speedup 0.176x/0.108x.
 * AdminGroupListRequest::listOptionsFrom (common):
 *   PHP 228.4/386.0; Rust 1651.8/3163.8;
 *   worst speedup 0.134x/0.059x.
 * AdminGroupListRequest::listOptionsFrom (uncommon):
 *   PHP 469.7/636.0; Rust 4253.4/6910.0;
 *   worst speedup 0.110x/0.069x.
 * AdminReleaseListRequest::trimSearch (common):
 *   PHP 59.6/63.3; Rust 274.1/342.8;
 *   worst speedup 0.213x/0.146x.
 * AdminReleaseListRequest::trimSearch (uncommon):
 *   PHP 76.6/178.3; Rust 324.2/523.1;
 *   worst speedup 0.224x/0.203x.
 * AdminReleaseListRequest::searchTermFrom (common):
 *   PHP 57.1/61.7; Rust 287.0/402.8;
 *   worst speedup 0.193x/0.109x.
 * AdminReleaseListRequest::searchTermFrom (uncommon):
 *   PHP 62.7/131.1; Rust 336.9/735.5;
 *   worst speedup 0.179x/0.100x.
 * AdminReleaseListRequest::categoryIdFrom (common):
 *   PHP 72.1/168.6; Rust 340.2/534.6;
 *   worst speedup 0.202x/0.175x.
 * AdminReleaseListRequest::categoryIdFrom (uncommon):
 *   PHP 89.2/93.7; Rust 338.7/414.6;
 *   worst speedup 0.259x/0.182x.
 * AdminReleaseReportBulkActionRequest::reportIdsFrom (common):
 *   PHP 201.4/410.3; Rust 1146.7/2044.1;
 *   worst speedup 0.171x/0.138x.
 * AdminReleaseReportBulkActionRequest::reportIdsFrom (uncommon):
 *   PHP 4452.6/6762.3; Rust 23526.8/31984.0;
 *   worst speedup 0.182x/0.178x.
 * AdminReleaseReportBulkActionRequest::actionNameFrom (common):
 *   PHP 49.4/61.3; Rust 280.0/415.3;
 *   worst speedup 0.174x/0.132x.
 * AdminReleaseReportBulkActionRequest::actionNameFrom (uncommon):
 *   PHP 52.1/79.3; Rust 287.5/458.2;
 *   worst speedup 0.178x/0.144x.
 * AdminReleaseReportListRequest::statusFrom (common):
 *   PHP 56.5/68.8; Rust 278.2/440.1;
 *   worst speedup 0.200x/0.134x.
 * AdminReleaseReportListRequest::statusFrom (uncommon):
 *   PHP 64.0/92.6; Rust 293.0/447.8;
 *   worst speedup 0.204x/0.161x.
 * AdminUserListRequest::filtersFrom (common):
 *   PHP 350.0/663.9; Rust 1689.5/3066.5;
 *   worst speedup 0.199x/0.166x.
 * AdminUserListRequest::filtersFrom (uncommon):
 *   PHP 454.3/957.0; Rust 2366.1/3487.6;
 *   worst speedup 0.191x/0.197x.
 * AdminUserListRequest::orderByFrom (common):
 *   PHP 99.7/190.6; Rust 2079.3/3631.8;
 *   worst speedup 0.046x/0.032x.
 * AdminUserListRequest::orderByFrom (uncommon):
 *   PHP 127.3/209.0; Rust 1995.9/3487.1;
 *   worst speedup 0.063x/0.046x.
 * Every class fails the 1.5x gate; all five production defaults remain PHP (false).
 */
final class RustAdminGroupReleaseReportUserListRequestsParityTest extends TestCase
{
    private const array TARGETS = [Groups::class, Releases::class, Bulk::class, Reports::class, Users::class];

    private const string CDEF = 'ssize_t nntmux_wave22_admin_request(uint32_t mode, const char *input, size_t len, char *out, size_t cap);';

    protected function tearDown(): void
    {
        foreach (self::TARGETS as $class) {
            if (method_exists($class, 'resetRustFfi')) {
                $class::resetRustFfi();
            }
        }
        parent::tearDown();
    }

    private function requireFfi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('cleaning', self::CDEF);
        self::assertNotNull($ffi, 'Build the release library before running this parity contract.');
        foreach (self::TARGETS as $class) {
            $class::enableRustFfi();
        }

        return $ffi;
    }

    public function test_production_defaults_follow_benchmark_gate(): void
    {
        foreach (self::TARGETS as $class) {
            self::assertTrue(method_exists($class, 'resetRustFfi'), $class.' needs the parity toggle');
            $class::resetRustFfi();
            self::assertFalse($class::usesRustFfi());
            $class::enableRustFfi();
            self::assertTrue($class::usesRustFfi());
            $class::disableRustFfi();
            self::assertFalse($class::usesRustFfi());
        }
    }

    public function test_native_helpers_match_php_including_binary_strings_and_fallbacks(): void
    {
        $this->requireFfi();
        $strings = ['', '0', '  group  ', "\0\t\n\r\x0b ", "\xff\0a\xc3\xa9\0", "\xc2\xa0 x \x0c", 'unknown'];
        foreach ($strings as $value) {
            self::assertSame(Groups::groupNamePhp($value), Groups::groupNameNative($value));
            self::assertSame(Releases::trimSearchPhp($value), Releases::trimSearchNative($value));
            self::assertSame(Reports::statusPhp($value), Reports::statusNative($value));
            self::assertSame(Bulk::actionNamePhp($value), Bulk::actionNameNative($value));
            self::assertSame(Releases::searchTermPhp($value), Releases::searchTermFrom($value));
            $input = ['groupname' => $value, 'numeric' => 12, 'array' => [' x '], 'null' => null, 4 => ' z '];
            self::assertSame(Groups::trimmedInputsPhp($input), Groups::trimmedInputsNative($input));
        }
        foreach ([null, -1, 0, 1, PHP_INT_MIN, PHP_INT_MAX, '-1', '0', '9223372036854775807'] as $value) {
            self::assertSame(Releases::categoryIdPhp($value), Releases::categoryIdFrom($value));
        }
        foreach (['01', '+1', '-0', ' 1', '1e2', '1.5', '9223372036854775808', "1\0", false, [], 1.5] as $value) {
            self::assertNull(Releases::categoryIdNative($value));
            self::assertSame(Releases::categoryIdPhp($value), Releases::categoryIdFrom($value));
        }
        foreach ([[], [1, '2', 1, '-1', PHP_INT_MIN, PHP_INT_MAX], ['a' => '3', 'b' => 2, 9 => '3']] as $ids) {
            self::assertSame(Bulk::reportIdsPhp($ids), Bulk::reportIdsNative($ids));
        }
        foreach ([null, '1,2', ['01', '+1', '-0'], [true, null, 1.5, []]] as $ids) {
            self::assertNull(Bulk::reportIdsNative($ids));
            self::assertSame(Bulk::reportIdsPhp($ids), Bulk::reportIdsFrom($ids));
        }
        foreach ([[], ['sort' => null, 'direction' => '', 'groupname' => 'x', 'page' => '2'], ['sort' => 'name', 'active' => 0, 'backfill' => false, 'x' => ['a']]] as $values) {
            self::assertSame(Groups::listOptionsPhp($values), Groups::listOptionsFrom($values));
        }
        foreach ([[], ['username' => ' x ', 'email' => "a\0b", 'host' => false, 'role' => 2, 'verified' => true], ['username' => [], 'email' => null, 'host' => 1.5]] as $values) {
            self::assertSame(Users::filtersPhp($values), Users::filtersFrom($values));
        }
        foreach (array_merge(getUserBrowseOrdering(), $strings, [false, true, null, [], 1.5]) as $value) {
            self::assertSame(Users::orderByPhp($value), Users::orderByFrom($value));
        }
        foreach (self::TARGETS as $class) {
            $class::disableRustFfi();
        }
        self::assertNull(Reports::statusNative('resolved'));
        self::assertSame('resolved', Reports::statusFrom('resolved'));
    }

    public function test_request_entry_points_keep_original_preparation_and_validation_semantics(): void
    {
        $this->requireFfi();
        // Local validator instances avoid Laravel boot, database presence rules and production settings.
        $factory = new Factory(new Translator(new ArrayLoader, 'en'));
        foreach ([false, true] as $native) {
            foreach (self::TARGETS as $class) {
                $native ? $class::enableRustFfi() : $class::disableRustFfi();
            }
            $group = Groups::create('/', 'GET', ['groupname' => '  alt.test  ', 'sort' => 'name', 'active' => '0']);
            (new \ReflectionMethod($group, 'prepareForValidation'))->invoke($group);
            self::assertSame('alt.test', $group->input('groupname'));
            $group->setValidator($factory->make($group->all(), $group->rules()));
            self::assertSame('alt.test', $group->groupName());
            self::assertSame(['active' => '0', 'sort' => 'name', 'direction' => 'asc'], $group->listOptions());
            $release = Releases::create('/', 'GET', ['search' => ' 0 ', 'category_id' => '-1']);
            (new \ReflectionMethod($release, 'prepareForValidation'))->invoke($release);
            $release->setValidator($factory->make($release->all(), $release->rules()));
            self::assertSame('0', $release->searchTerm());
            self::assertNull($release->categoryId());
            $report = new Reports;
            $report->setValidator($factory->make(['status' => null], $report->rules()));
            self::assertSame('pending', $report->status());
            $bulk = new Bulk;
            $bulk->setValidator($factory->make(['action' => 'delete', 'report_ids' => ['2', 2, 1]], ['action' => 'string', 'report_ids' => 'array']));
            self::assertSame([2, 1], $bulk->reportIds());
            self::assertSame('delete', $bulk->actionName());
            $user = Users::create('/', 'GET', ['username' => ' 0 ', 'role' => 2, 'ob' => ' username_desc ']);
            self::assertSame('0', $user->filters()['username']);
            self::assertSame('2', $user->filters()['role']);
            self::assertSame('username_desc', $user->orderBy());
        }
    }

    public function test_seeded_randomized_parity_uses_the_native_path(): void
    {
        $this->requireFfi();
        $random = new Randomizer(new Mt19937(2201));
        for ($i = 0; $i < 150; $i++) {
            $value = " \0".$random->getBytes($random->getInt(1, 128))."\t\x0b";
            self::assertSame(Releases::trimSearchPhp($value), Releases::trimSearchNative($value));
            self::assertSame(Reports::statusPhp($value), Reports::statusNative($value));
            self::assertSame(Groups::groupNamePhp($value), Groups::groupNameNative($value));
            self::assertSame(Bulk::actionNamePhp($value), Bulk::actionNameNative($value));
            self::assertSame(Releases::searchTermPhp($value), Releases::searchTermNative($value));
            $fields = ['username' => $value, 'email' => $value, 'role' => $i, 'verified' => false];
            self::assertSame(Users::filtersPhp($fields), Users::filtersNative($fields));
            self::assertSame(Groups::trimmedInputsPhp($fields), Groups::trimmedInputsNative($fields));
            $options = ['groupname' => $value, 'page' => '5', 'sort' => $value, 'active' => false, 'backfill' => 0, 'releases_min' => null, 'releases_max' => ''];
            self::assertSame(Groups::listOptionsPhp($options), Groups::listOptionsNative($options));
            $ids = [$i, (string) $i, 0, -$i, PHP_INT_MIN, PHP_INT_MAX];
            self::assertSame(Bulk::reportIdsPhp($ids), Bulk::reportIdsNative($ids));
            self::assertSame($i, Releases::categoryIdNative((string) $i));
        }
    }

    /** FFI binds C symbols dynamically; retain a callable for direct ABI tests. */
    private static function nativeExport(FFI $ffi, string $symbol): \Closure
    {
        return static fn (...$arguments): mixed => $ffi->$symbol(...$arguments);
    }

    public function test_direct_abi_capacity_and_invalid_input_never_write_partial_output(): void
    {
        $ffi = $this->requireFfi();
        $out = $ffi->new('char[32]');
        $export = self::nativeExport($ffi, 'nntmux_wave22_admin_request');
        foreach ([0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10] as $mode) {
            FFI::memset($out, 0x5A, 32);
            self::assertSame(-1, $export($mode, null, 0, $out, 32));
            self::assertSame(str_repeat('Z', 32), FFI::string($out, 32));
            self::assertSame(-1, $export($mode, '', 0, null, 0));
        }
        foreach ([[1, 'abc'], [3, 'abc'], [5, '123'], [7, 'delete'], [8, ''], [0, pack('V', 3).'abc'], [6, pack('V', 3).'123']] as [$mode, $input]) {
            FFI::memset($out, 0x5A, 32);
            self::assertSame(-2, $export($mode, $input, strlen($input), $out, 1));
            self::assertSame(str_repeat('Z', 32), FFI::string($out, 32));
        }
        foreach ([[0, "\x01"], [2, pack('V', 0)], [6, pack('V', 2).'01'], [9, ''], [10, ''], [999, '']] as [$mode, $input]) {
            FFI::memset($out, 0x5A, 32);
            self::assertSame(-1, $export($mode, $input, strlen($input), $out, 32));
            self::assertSame(str_repeat('Z', 32), FFI::string($out, 32));
        }
    }

    /** @return list<array{class-string, string, string, string, array<mixed>, array<mixed>}> */
    private static function benchmarkCases(): array
    {
        $long = str_repeat("\t  alt.binaries.\xff\0", 32)." \r\n";
        $options = ['groupname' => 'x', 'page' => '2', 'sort' => 'name', 'direction' => 'desc', 'active' => '0'];
        $manyOptions = $options + array_fill_keys(['first_post_from', 'last_post_to', 'last_updated_from', 'min_files_min', 'min_files_max', 'min_size_min', 'backfill_days_max', 'releases_min'], $long);
        $manyIds = [];
        for ($i = 0; $i < 160; $i++) {
            $manyIds[] = (string) ($i % 71 - 30);
        }
        $filters = ['username' => ' alice ', 'email' => ' x@example.test ', 'role' => 2, 'verified' => '1'];

        return [
            [Groups::class, 'trimmedInputs', 'trimmedInputsPhp', 'trimmedInputsNative', [['groupname' => ' alt.test ', 'active' => ' 0 ']], [$manyOptions]],
            [Groups::class, 'groupNameFrom', 'groupNamePhp', 'groupNameNative', ['alt.binaries.test'], [$long]],
            [Groups::class, 'listOptionsFrom', 'listOptionsPhp', 'listOptionsNative', [$options], [$manyOptions]],
            [Releases::class, 'trimSearch', 'trimSearchPhp', 'trimSearchNative', [' release '], [$long]],
            [Releases::class, 'searchTermFrom', 'searchTermPhp', 'searchTermNative', ['release'], [$long]],
            [Releases::class, 'categoryIdFrom', 'categoryIdPhp', 'categoryIdNative', ['2000'], [(string) PHP_INT_MAX]],
            [Bulk::class, 'reportIdsFrom', 'reportIdsPhp', 'reportIdsNative', [[1, '2', 1, '3']], [$manyIds]],
            [Bulk::class, 'actionNameFrom', 'actionNamePhp', 'actionNameNative', ['dismiss'], ['revert']],
            [Reports::class, 'statusFrom', 'statusPhp', 'statusNative', ['pending'], ['dismissed']],
            [Users::class, 'filtersFrom', 'filtersPhp', 'filtersNative', [$filters], [array_fill_keys(['username', 'email', 'host', 'role', 'verified', 'created_from', 'created_to'], $long)]],
            [Users::class, 'orderByFrom', 'orderByPhp', 'orderByNative', ['username_asc'], [" \0rolechangedate_desc\t\x0b"]],
        ];
    }

    public function test_paired_randomized_benchmark(): void
    {
        if (getenv('NNTMUX_WAVE22_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_WAVE22_BENCH=1 to run paired randomized benchmarks.');
        }
        $this->requireFfi();
        $random = new Randomizer(new Mt19937(220141));
        $report = [
            'protocol' => ['repetitions' => 3, 'pairs' => 41, 'inner' => 200, 'warmups' => 200, 'seed' => 220141, 'p99' => 'nearest-rank of batch means', 'clock' => 'hrtime', 'caller' => 'production dispatch helper, including FFI and marshalling'],
            'runtime' => ['php' => PHP_VERSION, 'os' => php_uname(), 'jit' => ini_get('opcache.jit'), 'ffi' => ini_get('ffi.enable')],
            'source_sha256' => [],
            'library_sha256' => hash_file('sha256', __DIR__.'/../../storage/app/nntmux-native/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so')),
            'results' => [],
        ];
        foreach ([...array_map(static fn (string $class): string => (new \ReflectionClass($class))->getFileName(), self::TARGETS), __FILE__, __DIR__.'/../../rust/pipeline-ffi/src/cleaning.rs', __DIR__.'/../../rust/pipeline-ffi/Cargo.lock', __DIR__.'/../../composer.lock'] as $file) {
            $report['source_sha256'][$file] = hash_file('sha256', $file);
        }
        foreach (self::benchmarkCases() as [$class, $dispatch, $php, $native, $common, $uncommon]) {
            foreach (['common' => $common, 'uncommon' => $uncommon] as $workload => $args) {
                $class::enableRustFfi();
                self::assertNotNull($class::$native(...$args), $class.'::'.$native.' must actually execute Rust');
                self::assertSame($class::$php(...$args), $class::$native(...$args));
                for ($repetition = 0; $repetition < 3; $repetition++) {
                    foreach ([false, true] as $enabled) {
                        $enabled ? $class::enableRustFfi() : $class::disableRustFfi();
                        for ($warmup = 0; $warmup < 200; $warmup++) {
                            $class::$dispatch(...$args);
                        }
                    }
                    $samples = ['php' => [], 'rust' => []];
                    $orders = [];
                    for ($pair = 0; $pair < 41; $pair++) {
                        $order = $random->shuffleArray(['php', 'rust']);
                        $orders[] = $order;
                        foreach ($order as $engine) {
                            $engine === 'rust' ? $class::enableRustFfi() : $class::disableRustFfi();
                            $start = hrtime(true);
                            for ($inner = 0; $inner < 200; $inner++) {
                                $actual = $class::$dispatch(...$args);
                            }
                            $samples[$engine][] = (hrtime(true) - $start) / 200;
                            self::assertSame($class::$php(...$args), $actual);
                        }
                    }
                    $stats = [];
                    foreach ($samples as $engine => $latencies) {
                        sort($latencies);
                        $stats[$engine] = ['mean_ns' => array_sum($latencies) / count($latencies), 'p99_ns' => $latencies[(int) ceil(count($latencies) * 0.99) - 1]];
                    }
                    $report['results'][] = [
                        'class' => $class, 'method' => $dispatch, 'workload' => $workload, 'repetition' => $repetition,
                        'input_sha256' => hash('sha256', serialize($args)), 'samples_ns' => $samples, 'orders' => $orders, 'stats' => $stats,
                        'speedup_mean' => $stats['php']['mean_ns'] / $stats['rust']['mean_ns'],
                        'speedup_p99' => $stats['php']['p99_ns'] / $stats['rust']['p99_ns'],
                    ];
                }
            }
        }
        $path = getenv('NNTMUX_WAVE22_BENCH_OUTPUT') ?: sys_get_temp_dir().'/nntmux-wave22-benchmark.json';
        self::assertNotFalse(file_put_contents($path, json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)));
        // Each class has at least one required workload below the adoption threshold.
        foreach (self::TARGETS as $class) {
            $qualified = true;
            foreach ($report['results'] as $result) {
                if ($result['class'] === $class && min($result['speedup_mean'], $result['speedup_p99']) < 1.5) {
                    $qualified = false;
                }
            }
            $class::resetRustFfi();
            self::assertSame($qualified, $class::usesRustFfi(), $class.' default must follow the measured gate');
        }
    }
}
