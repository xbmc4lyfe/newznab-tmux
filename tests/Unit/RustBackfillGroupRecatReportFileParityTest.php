<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Listeners\RecategorizeReleaseAfterNameFix;
use App\Models\ReleaseFile;
use App\Models\ReleaseReport;
use App\Services\Backfill\BackfillConfig;
use App\Services\Categorization\CategorizationService;
use App\Services\Configuration\ConfigurationProvider;
use App\Services\Releases\ReleaseGroupResolver;
use App\Services\Search\SearchService;
use App\Support\Configuration\IngestionConfigurationData;
use App\Support\RustPipelineLibrary;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * Wave20 rejection evidence: baseline b007d8199b2c6421afd7d21ee79f2f8f4e86542d,
 * PHP 8.5.12; rustc 1.99.1; release opt-level=3/LTO/one codegen unit;
 * Darwin arm64 Mac17,3, 10 CPUs, 32 GiB RAM, shared host without imposed caps.
 * 1,024 warmups/side then 5 x 4,096 randomized pairs for all 22 workloads.
 * PHP/Rust common mean ratios: 0.075-0.300; common p99: 0.085-0.444.
 * Best uncommon mean/p99 is reasonKeysToLabels at 1.149/1.227: none reaches
 * the required 1.5x on common/uncommon mean AND p99; production retains PHP.
 * The five blocks retain tail variation; these pure-operation measurements
 * include wrappers/framing/FFI/allocation, and make no DB throughput claim.
 * Raw ns, orders, binary-safe inputs, per-block ratios, source/library hashes:
 * /tmp/wave20-worker2-benchmark.json. Reproduce from the repository root:
 * php -r 'require "tests/bootstrap.php"; require "tests/Unit/RustBackfillGroupRecatReportFileParityTest.php"; Tests\Unit\RustBackfillGroupRecatReportFileParityTest::benchmark("/tmp/wave20-worker2-benchmark.json");'
 * WAVE20_BENCHMARK_METADATA supplies hardware/resource metadata as JSON.
 */
final class RustBackfillGroupRecatReportFileParityTest extends TestCase
{
    private const CLASSES = [BackfillConfig::class, ReleaseGroupResolver::class, RecategorizeReleaseAfterNameFix::class, ReleaseReport::class, ReleaseFile::class];

    /** @var array<class-string, bool> */
    private array $previousRust = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRust = self::rustFlags();
        self::enableRust(false);
    }

    protected function tearDown(): void
    {
        self::restoreRustFlags($this->previousRust);
        parent::tearDown();
    }

    public function test_all_eleven_candidates_are_available_and_match_php_before_opt_in(): void
    {
        self::enableRust(false);
        foreach (self::cases() as $label => [$class, $method, $inputs]) {
            foreach ($inputs as $args) {
                $raw = $class::{$method.'Native'}(...$args);
                self::assertNotNull($raw, $label.' native library must be available; fallback is not parity');
                $expected = $class::$method(...$args);
                self::assertSame($expected, self::normalizeNative($method, $raw), $label.'/'.base64_encode(serialize($args)));
                $class::useRustFfi(true);
                try {
                    self::assertSame($expected, $class::$method(...$args), $label.' enabled wrapper');
                } finally {
                    $class::useRustFfi(false);
                }
            }
        }
    }

    public function test_candidates_default_to_php_and_do_not_become_model_attributes(): void
    {
        foreach (self::CLASSES as $class) {
            if ($class === BackfillConfig::class) {
                self::assertFalse((new ReflectionMethod($class, 'rustEnabled'))->getStaticVariables()['useRustFfi']);
            } else {
                self::assertFalse((new ReflectionProperty($class, 'useRustFfi'))->getDefaultValue());
                self::assertTrue((new ReflectionProperty($class, 'useRustFfi'))->isPrivate());
            }
        }
        foreach ([new ReleaseFile, new ReleaseReport] as $model) {
            self::assertArrayNotHasKey('useRustFfi', $model->getAttributes());
        }
    }

    public function test_backfill_boolean_coercion_keeps_repair_independent_of_group_disable(): void
    {
        foreach ([null, false, true, 0, 1, -1, '', '0', 'false', [], [0], new \stdClass] as $compressed) {
            foreach ([null, '', '0', '1', 0, [], [false]] as $echo) {
                foreach ([false, true] as $repair) {
                    foreach ([false, true] as $disable) {
                        $expected = [(bool) $compressed, (bool) $echo, "date\0\xff", $repair ? 'update' : 'backfill', $disable];
                        self::assertSame($expected, BackfillConfig::normalizeTupleNative($compressed, $echo, "date\0\xff", $repair, $disable));
                        self::assertSame($expected, BackfillConfig::normalizeTuple($compressed, $echo, "date\0\xff", $repair, $disable));
                        self::assertSame($repair ? 'update' : 'backfill', BackfillConfig::safePartRepairModeNative($repair));
                    }
                }
            }
        }
    }

    public function test_group_filter_retains_order_duplicates_and_exact_255_byte_boundary(): void
    {
        $edge = 'alt.'.str_repeat('x', 251);
        $over = $edge.'x';
        self::assertSame(255, strlen($edge));
        self::assertSame(['alt.binaries.test', $edge, 'a.b.other'], ReleaseGroupResolver::filterValidNamesNative(['alt.binaries.test', $edge, $over, 'alt.binaries.test', 'a.b.other', $edge]));
        self::assertSame([], ReleaseGroupResolver::filterValidNamesNative([]));
        $attributes = ReleaseGroupResolver::inactiveAttributesNative("alt.binaries.\0\xff");
        self::assertSame(ReleaseGroupResolver::inactiveAttributes("alt.binaries.\0\xff"), $attributes);
        self::assertSame(0, $attributes['active']);
        self::assertSame(0, $attributes['backfill']);
        self::assertSame('', $attributes['minfilestoformrelease']);
    }

    public function test_report_empty_trim_label_deduplication_and_count_types(): void
    {
        foreach ([null, '', '0'] as $reason) {
            self::assertSame('Unknown', ReleaseReport::reasonKeysToLabelsNative($reason));
        }
        self::assertSame('Other, Fake/Malicious Content, Duplicate Release', ReleaseReport::reasonKeysToLabelsNative("\0\tother\v,Other, fake ,duplicate,duplicate"));
        self::assertSame("\fother\f", ReleaseReport::reasonKeysToLabelsNative("\fother\f"));
        self::assertSame(', Other', ReleaseReport::reasonKeysToLabelsNative(',other,,'));
        $counts = ['pending' => '3', 'reviewed' => 2, 'resolved' => '0', 'unexpected' => '7'];
        self::assertSame(['pending' => '3', 'reviewed' => 2, 'resolved' => '0', 'dismissed' => 0, 'total' => 12], ReleaseReport::statusCountSummaryNative($counts));
        foreach ([['pending' => PHP_INT_MAX, 'unexpected' => 1], ['pending' => '9223372036854775808'], ['pending' => 1.5], ['pending' => '01'], ['pending' => null], ['pending' => -1]] as $unsupported) {
            self::assertNull(ReleaseReport::statusCountSummaryNative($unsupported));
            ReleaseReport::useRustFfi(false);
            $expected = ReleaseReport::statusCountSummary($unsupported);
            ReleaseReport::useRustFfi(true);
            self::assertSame($expected, ReleaseReport::statusCountSummary($unsupported));
        }
    }

    public function test_snapshot_checks_are_strict_binary_safe_and_category_guard_requires_one(): void
    {
        $snapshot = ['id' => 42, 'guid' => 'guid', 'name' => null, 'searchname' => "Movie\0\xff"];
        self::assertTrue(RecategorizeReleaseAfterNameFix::expectedSnapshotMatchesNative(null, 42, 'anything'));
        self::assertTrue(RecategorizeReleaseAfterNameFix::expectedSnapshotMatchesNative($snapshot, 42, "Movie\0\xff"));
        foreach ([[$snapshot, 41, "Movie\0\xff"], [$snapshot, 42, "movie\0\xff"], [array_replace($snapshot, ['searchname' => null]), 42, ''], [array_replace($snapshot, ['id' => '42']), 42, "Movie\0\xff"]] as $args) {
            self::assertFalse(RecategorizeReleaseAfterNameFix::expectedSnapshotMatchesNative(...$args));
            self::assertFalse(RecategorizeReleaseAfterNameFix::expectedSnapshotMatches(...$args));
        }
        foreach ([-1, 0, 1, 2, PHP_INT_MIN, PHP_INT_MAX] as $flag) {
            self::assertSame($flag === 1, RecategorizeReleaseAfterNameFix::categoryAlreadyCurrentNative(5000, 5000, $flag));
            self::assertFalse(RecategorizeReleaseAfterNameFix::categoryAlreadyCurrentNative(5000, 5001, $flag));
        }
        self::assertSame('00FF6141', RecategorizeReleaseAfterNameFix::uppercaseHexNative("\0\xffaA"));
    }

    public function test_hash_invalid_sentinel_and_snapshot_key_presence_including_null(): void
    {
        foreach (['', '0', str_repeat('a', 31), str_repeat('a', 33), str_repeat('g', 32), str_repeat('a', 31)."\0", str_repeat('é', 16), "\xff".str_repeat('a', 31), str_repeat('a', 32)."\n"] as $hash) {
            self::assertSame('', ReleaseFile::normalizeParHashNative($hash));
            foreach ([false, true] as $enabled) {
                ReleaseFile::useRustFfi($enabled);
                self::assertNull(ReleaseFile::normalizeParHash($hash));
            }
        }
        self::assertSame('abcdef0123456789abcdef0123456789', ReleaseFile::normalizeParHashNative('ABCDEF0123456789abcdef0123456789'));
        foreach ([[], ['proc_hash16k' => null], ['proc_hash16k' => 0], ['proc_hash16k' => false], ['proc_hash16k' => ''], ['other' => 1]] as $snapshot) {
            foreach ([-1, 0, 1, PHP_INT_MAX] as $predb) {
                foreach ([-1, 0, 1, 2] as $renamed) {
                    self::assertSame(array_key_exists('proc_hash16k', $snapshot) && $predb === 0 && in_array($renamed, [0, 1], true), ReleaseFile::shouldRefreshHashSnapshotNative($snapshot, $predb, $renamed));
                }
            }
        }
    }

    public function test_seeded_random_binary_inputs_match_without_fallback(): void
    {
        mt_srand(20020);
        self::enableRust(false);
        for ($i = 0; $i < 512; $i++) {
            $value = '';
            for ($j = mt_rand(0, 512); $j > 0; $j--) {
                $value .= chr(mt_rand(0, 255));
            }
            self::assertSame(ReleaseReport::reasonKeysToLabels($value), ReleaseReport::reasonKeysToLabelsNative($value));
            self::assertSame(RecategorizeReleaseAfterNameFix::uppercaseHex($value), RecategorizeReleaseAfterNameFix::uppercaseHexNative($value));
            self::assertSame(ReleaseFile::normalizeParHash($value) ?? '', ReleaseFile::normalizeParHashNative($value));
            self::assertSame(ReleaseGroupResolver::inactiveAttributes($value), ReleaseGroupResolver::inactiveAttributesNative($value));
            self::assertSame(ReleaseGroupResolver::filterValidNames([$value, $value, 'alt.binaries.test']), ReleaseGroupResolver::filterValidNamesNative([$value, $value, 'alt.binaries.test']));
            $id = mt_rand(-100000, 100000);
            $snapshot = ['id' => $id, 'guid' => $value, 'name' => null, 'searchname' => $value];
            self::assertTrue(RecategorizeReleaseAfterNameFix::expectedSnapshotMatchesNative($snapshot, $id, $value));
            self::assertFalse(RecategorizeReleaseAfterNameFix::expectedSnapshotMatchesNative($snapshot, $id + 1, $value));
        }
    }

    public function test_real_from_settings_and_group_resolver_preserve_configuration_and_storage(): void
    {
        $this->withDatabase(function (): void {
            $defaults = IngestionConfigurationData::defaultsPhp()->toArray();
            $columns = [];
            $row = [];
            foreach ($defaults as $key => $value) {
                $column = Str::snake($key);
                $columns[] = $column.' '.(is_string($value) ? 'TEXT' : 'INTEGER');
                $row[$column] = $value;
            }
            DB::statement('CREATE TABLE ingestion_configurations (id INTEGER PRIMARY KEY, '.implode(', ', $columns).')');
            DB::table('ingestion_configurations')->insert(['id' => 1, ...$row]);
            app('config')->set('nntmux_nntp.compressed_headers', '0');
            app('config')->set('nntmux.echocli', 'false');
            foreach ([false, true] as $enabled) {
                self::enableRust($enabled);
                foreach ([false, true] as $repair) {
                    foreach ([false, true] as $disable) {
                        DB::table('ingestion_configurations')->update(['safe_part_repair' => $repair, 'disable_backfill_group' => $disable]);
                        app('cache')->flush();
                        $config = BackfillConfig::fromSettings();
                        self::assertFalse($config->compressedHeaders);
                        self::assertTrue($config->echoCli);
                        self::assertSame($repair ? 'update' : 'backfill', $config->safePartRepair);
                        self::assertSame($disable, $config->disableBackfillGroup);
                        self::assertSame('2012-06-24', $config->safeBackFillDate);
                    }
                }
                DB::table('usenet_groups')->delete();
                DB::table('usenet_groups')->insert(['id' => 7, 'name' => 'alt.binaries.test', 'active' => 1]);
                $edge = 'alt.'.str_repeat('x', 251);
                $names = ['a.b.test', 'alt.binaries.test', 'a.b.new', 'a.b.test', 'alt.binaries.new', 'bad group', $edge, $edge.'x'];
                $ids = (new ReleaseGroupResolver)->idsByName($names);
                self::assertSame(7, $ids['a.b.test']);
                self::assertSame(7, $ids['alt.binaries.test']);
                self::assertSame($ids['a.b.new'], $ids['alt.binaries.new']);
                self::assertSame(['a.b.test', 'alt.binaries.test', 'a.b.new', 'alt.binaries.new', $edge], array_keys($ids));
                self::assertSame(3, DB::table('usenet_groups')->count());
                $created = (array) DB::table('usenet_groups')->where('id', $ids['a.b.new'])->first();
                foreach (ReleaseGroupResolver::inactiveAttributes('alt.binaries.new') as $key => $expected) {
                    self::assertSame($expected, $created[$key]);
                }
            }
        });
    }

    public function test_real_report_query_includes_unknown_status_in_total(): void
    {
        $this->withDatabase(function (): void {
            foreach (['pending', 'pending', 'resolved', 'unrecognized'] as $status) {
                DB::table('release_reports')->insert(['status' => $status]);
            }
            foreach ([false, true] as $enabled) {
                ReleaseReport::useRustFfi($enabled);
                self::assertSame(['pending' => 2, 'reviewed' => 0, 'resolved' => 1, 'dismissed' => 0, 'total' => 4], ReleaseReport::getCountByStatus());
            }
        });
    }

    public function test_real_listener_rejects_stale_case_binary_and_racing_snapshots_and_skips_noop(): void
    {
        $this->withDatabase(function (): void {
            foreach ([false, true] as $enabled) {
                RecategorizeReleaseAfterNameFix::useRustFfi($enabled);
                $name = "Movie\0\xff";
                $snapshot = ['id' => 42, 'guid' => 'guid', 'name' => null, 'searchname' => $name];
                foreach (['stale_event', 'stale_database', 'case', 'noop', 'race', 'update'] as $scenario) {
                    DB::table('releases')->delete();
                    DB::table('releases')->insert([...$snapshot, 'groups_id' => 1, 'categories_id' => 5000, 'iscategorized' => 1]);
                    if ($scenario === 'stale_database') {
                        DB::table('releases')->update(['guid' => 'replacement']);
                    }
                    $expectedSnapshot = $scenario === 'stale_event' ? array_replace($snapshot, ['id' => 41]) : $snapshot;
                    $eventName = $scenario === 'case' ? "movie\0\xff" : $name;
                    $shouldCategorize = ! in_array($scenario, ['stale_event', 'stale_database', 'case'], true);
                    $categorization = $this->getMockBuilder(CategorizationService::class)->disableOriginalConstructor()->onlyMethods(['determineCategory'])->getMock();
                    $categorization->expects($shouldCategorize ? $this->once() : $this->never())->method('determineCategory')
                        ->willReturnCallback(function () use ($scenario): array {
                            if ($scenario === 'race') {
                                DB::table('releases')->update(['name' => 'new owner']);
                            }

                            return ['categories_id' => $scenario === 'noop' ? 5000 : 5030];
                        });
                    DB::connection()->enableQueryLog();
                    DB::connection()->flushQueryLog();
                    (new RecategorizeReleaseAfterNameFix($categorization))->handle(new ReleaseNameFixed(42, 'old', $eventName, 5000, 1, '', $expectedSnapshot));
                    self::assertSame($scenario === 'update' ? 5030 : 5000, DB::table('releases')->value('categories_id'), $scenario);
                    if ($scenario === 'noop') {
                        self::assertSame([], array_values(array_filter(DB::getQueryLog(), static fn (array $query): bool => str_starts_with(strtolower($query['query']), 'update'))));
                    }
                    if ($scenario === 'race') {
                        self::assertSame('new owner', DB::table('releases')->value('name'));
                    }
                    DB::connection()->disableQueryLog();
                }
                DB::table('releases')->update(['searchname' => 'CASE']);
                $categorization = $this->getMockBuilder(CategorizationService::class)->disableOriginalConstructor()->onlyMethods(['determineCategory'])->getMock();
                $categorization->expects($this->never())->method('determineCategory');
                (new RecategorizeReleaseAfterNameFix($categorization))->handle(new ReleaseNameFixed(42, '', 'case', 5000, 1));
            }
        });
    }

    public function test_real_file_insert_hash_normalization_duplicate_and_missing_parent_effects(): void
    {
        $this->withDatabase(function (): void {
            $search = $this->getMockBuilder(SearchService::class)->disableOriginalConstructor()->onlyMethods(['updateRelease'])->getMock();
            $search->expects($this->exactly(4))->method('updateRelease')->with(42);
            Search::swap($search);
            foreach ([false, true] as $enabled) {
                ReleaseFile::useRustFfi($enabled);
                foreach (['release_files', 'par_hashes', 'releases'] as $table) {
                    DB::table($table)->delete();
                }
                DB::table('releases')->insert(['id' => 42, 'rarinnerfilecount' => 7]);
                $hash = 'ABCDEF0123456789ABCDEF0123456789';
                self::assertSame(1, ReleaseFile::addReleaseFiles(42, 'movie.mkv', 123, '2026-10-01 00:00:00', 0, $hash, '1234abcd'));
                $before = (array) DB::table('release_files')->first();
                self::assertSame(0, ReleaseFile::addReleaseFiles(42, 'movie.mkv', 999, '2026-10-02 00:00:00', 1, $hash));
                self::assertSame($before, (array) DB::table('release_files')->first());
                self::assertSame(1, DB::table('par_hashes')->count());
                self::assertSame(strtolower($hash), DB::table('par_hashes')->value('hash'));
                self::assertSame(1, ReleaseFile::addReleaseFiles(42, 'invalid.mkv', 12, '2026-10-01 00:00:00', 0, str_repeat('g', 32)));
                self::assertSame(1, DB::table('par_hashes')->count());
                self::assertSame(0, ReleaseFile::addReleaseFiles(999, 'missing.mkv', 12, '2026-10-01 00:00:00', 0, $hash));
                self::assertSame(2, DB::table('release_files')->count());
                self::assertSame(7, DB::table('releases')->value('rarinnerfilecount'));
            }
        });
    }

    public function test_missing_domain_libraries_decline_and_enabled_wrappers_fall_back(): void
    {
        $cache = new ReflectionProperty(RustPipelineLibrary::class, 'domainLibraries');
        $before = $cache->getValue();
        $blocked = $before;
        foreach (['backfill', 'group', 'recat', 'report', 'file'] as $domain) {
            $cdef = 'intptr_t nntmux_wave20_'.$domain.'(uint32_t mode, const char *input, size_t input_len, char *out, size_t out_cap);';
            $blocked['wave20-'.$domain.'|default|'.hash('xxh3', $cdef)] = false;
        }
        $cache->setValue(null, $blocked);
        try {
            foreach (self::cases() as $label => [$class, $method, $inputs]) {
                foreach ($inputs as $args) {
                    $class::useRustFfi(false);
                    $expected = $class::$method(...$args);
                    $class::useRustFfi(true);
                    if ($method === 'expectedSnapshotMatches' && $args[0] !== null && ! is_string($args[0]['searchname'])) {
                        // The strict PHP input guard can reject before attempting the ABI.
                        self::assertFalse($class::{$method.'Native'}(...$args), $label);
                    } else {
                        self::assertNull($class::{$method.'Native'}(...$args), $label);
                    }
                    self::assertSame($expected, $class::$method(...$args), $label);
                }
            }
        } finally {
            $cache->setValue(null, $before);
            self::enableRust(false);
        }
    }

    /** Run manually; normal PHPUnit runs never execute the benchmark. */
    public static function benchmark(string $outputPath): void
    {
        $previousRust = self::rustFlags();
        self::enableRust(false);
        $root = dirname(__DIR__, 2);
        $report = [
            'seed' => 20020, 'warmups_per_side' => 1024, 'blocks' => 5, 'pairs_per_block' => 4096,
            'protocol' => 'Single-call randomized paired order and input; hrtime ns; nearest-rank p99. Production PHP wrapper versus explicit native adapter, including framing, FFI, result allocation and invalid-hash normalization. DB/I/O excluded from these pure-operation timings; actual callers verified separately by PHPUnit.',
            'baseline_commit' => trim((string) shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD')),
            'php' => PHP_VERSION, 'php_binary' => PHP_BINARY, 'os' => php_uname(), 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'),
            'lc_ctype' => setlocale(LC_CTYPE, '0'), 'pcre_backtrack_limit' => ini_get('pcre.backtrack_limit'),
            'rustc' => trim((string) shell_exec('rustc --version 2>/dev/null')), 'cargo' => trim((string) shell_exec('cargo --version 2>/dev/null')),
            'build' => 'cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml; opt-level=3,lto=true,codegen-units=1',
            'caller_metadata' => json_decode(getenv('WAVE20_BENCHMARK_METADATA') ?: '{}', true, 512, JSON_THROW_ON_ERROR),
            'source_sha256' => [], 'results' => [], 'failures' => [],
        ];
        $paths = ['app/Services/Backfill/BackfillConfig.php', 'app/Services/Releases/ReleaseGroupResolver.php', 'app/Listeners/RecategorizeReleaseAfterNameFix.php', 'app/Models/ReleaseReport.php', 'app/Models/ReleaseFile.php', 'app/Support/RustPipelineLibrary.php', 'rust/pipeline-ffi/Cargo.toml', 'rust/pipeline-ffi/Cargo.lock', 'rust/pipeline-ffi/src/lib.rs', 'rust/pipeline-ffi/src/cleaning.rs', 'composer.lock', 'vendor/composer/installed.php'];
        foreach (glob($root.'/rust/pipeline-ffi/src/*wave20*') ?: [] as $path) {
            $paths[] = substr($path, strlen($root) + 1);
        }
        foreach (['storage/app/nntmux-native', 'rust/pipeline-ffi/target/release'] as $directory) {
            foreach (['dylib', 'so'] as $suffix) {
                $paths[] = $directory.'/libnntmux_pipeline.'.$suffix;
            }
        }
        foreach ($paths as $path) {
            $report['source_sha256'][$path] = is_file($root.'/'.$path) ? hash_file('sha256', $root.'/'.$path) : null;
        }
        $benchmarkMethod = new ReflectionMethod(self::class, 'benchmark');
        $report['benchmark_body_sha256'] = hash('sha256', implode('', array_slice(file(__FILE__), $benchmarkMethod->getStartLine() - 1, $benchmarkMethod->getEndLine() - $benchmarkMethod->getStartLine() + 1)));
        mt_srand(20020);
        $stats = static function (array $samples): array {
            sort($samples);

            return ['mean_ns' => array_sum($samples) / count($samples), 'p99_ns' => $samples[(int) ceil(count($samples) * 0.99) - 1]];
        };
        try {
            foreach (self::cases() as $label => [$class, $method, $inputs]) {
                $php = static fn (array $args): mixed => $class::$method(...$args);
                $native = static function (array $args) use ($class, $method, $label): mixed {
                    $raw = $class::{$method.'Native'}(...$args);
                    if ($raw === null) {
                        throw new RuntimeException($label.' native ABI declined; benchmark invalid');
                    }

                    return self::normalizeNative($method, $raw);
                };
                foreach ($inputs as $args) {
                    self::assertSame($php($args), $native($args), $label.' correctness precheck');
                }
                for ($i = 0; $i < 1024; $i++) {
                    $php($inputs[$i % count($inputs)]);
                    $native($inputs[$i % count($inputs)]);
                }
                $calls = [$php, $native];
                $samples = [[], []];
                $orders = $indices = [];
                for ($i = 0; $i < 5 * 4096; $i++) {
                    $index = mt_rand(0, count($inputs) - 1);
                    $first = mt_rand(0, 1);
                    $indices[] = $index;
                    $orders[] = $first;
                    foreach ([$first, 1 - $first] as $side) {
                        $start = hrtime(true);
                        $result = $calls[$side]($inputs[$index]);
                        $samples[$side][] = hrtime(true) - $start;
                        unset($result);
                    }
                }
                $p = $stats($samples[0]);
                $r = $stats($samples[1]);
                $blocks = [];
                for ($block = 0; $block < 5; $block++) {
                    $bp = $stats(array_slice($samples[0], $block * 4096, 4096));
                    $br = $stats(array_slice($samples[1], $block * 4096, 4096));
                    $blocks[] = ['php' => $bp, 'rust' => $br, 'mean_speedup' => $bp['mean_ns'] / $br['mean_ns'], 'p99_speedup' => $bp['p99_ns'] / $br['p99_ns']];
                }
                $report['results'][$label] = [
                    'php' => $p, 'rust' => $r, 'mean_speedup' => $p['mean_ns'] / $r['mean_ns'], 'p99_speedup' => $p['p99_ns'] / $r['p99_ns'],
                    'blocks' => $blocks, 'block_mean_speedup_range' => [min(array_column($blocks, 'mean_speedup')), max(array_column($blocks, 'mean_speedup'))],
                    'block_p99_speedup_range' => [min(array_column($blocks, 'p99_speedup')), max(array_column($blocks, 'p99_speedup'))],
                    'inputs_php_serialize_base64' => base64_encode(serialize($inputs)), 'samples_ns' => $samples, 'orders' => $orders, 'input_indices' => $indices,
                ];
                echo $label.' '.json_encode(['mean' => $p['mean_ns'] / $r['mean_ns'], 'p99' => $p['p99_ns'] / $r['p99_ns']], JSON_THROW_ON_ERROR).PHP_EOL;
            }
        } catch (\Throwable $exception) {
            $report['failures'][] = ['case' => $label ?? null, 'class' => $exception::class, 'message' => $exception->getMessage()];
            throw $exception;
        } finally {
            file_put_contents($outputPath, json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            self::restoreRustFlags($previousRust);
        }
    }

    private static function normalizeNative(string $method, mixed $result): mixed
    {
        return $method === 'normalizeParHash' && $result === '' ? null : $result;
    }

    /** @return array<class-string, bool> */
    private static function rustFlags(): array
    {
        $flags = [];
        foreach (self::CLASSES as $class) {
            $flags[$class] = $class === BackfillConfig::class
                ? (new ReflectionMethod($class, 'rustEnabled'))->invoke(null)
                : (new ReflectionProperty($class, 'useRustFfi'))->getValue();
        }

        return $flags;
    }

    /** @param array<class-string, bool> $flags */
    private static function restoreRustFlags(array $flags): void
    {
        foreach ($flags as $class => $enabled) {
            $class::useRustFfi($enabled);
        }
    }

    private static function enableRust(bool $enabled): void
    {
        foreach (self::CLASSES as $class) {
            $class::useRustFfi($enabled);
        }
    }

    /** @return array<string, array{class-string, string, list<list<mixed>>}> */
    private static function cases(): array
    {
        $b = BackfillConfig::class;
        $g = ReleaseGroupResolver::class;
        $c = RecategorizeReleaseAfterNameFix::class;
        $r = ReleaseReport::class;
        $f = ReleaseFile::class;
        $snapshot = ['id' => 42, 'guid' => 'guid', 'name' => 'raw', 'searchname' => 'Movie.2026'];
        $binarySnapshot = array_replace($snapshot, ['searchname' => "Movie\0\xff"]);

        return [
            'safePartRepairMode/common' => [$b, 'safePartRepairMode', [[true], [false]]],
            'safePartRepairMode/uncommon' => [$b, 'safePartRepairMode', [[false], [true], [false]]],
            'normalizeTuple/common' => [$b, 'normalizeTuple', [[true, false, '2012-08-14', false, false], ['1', '0', '2026-10-24', true, false]]],
            'normalizeTuple/uncommon' => [$b, 'normalizeTuple', [[null, [], "date\0\xff", false, true], [[false], 'false', str_repeat('date', 1024), true, true]]],
            'filterValidNames/common' => [$g, 'filterValidNames', [[['alt.binaries.test', 'alt.binaries.movies', 'alt.binaries.test']], [[]]]],
            'filterValidNames/uncommon' => [$g, 'filterValidNames', [[['alt.'.str_repeat('x', 251), 'alt.'.str_repeat('x', 252)]], [array_map(static fn (int $i): string => 'alt.binaries.group'.($i % 128), range(0, 511))]]],
            'inactiveAttributes/common' => [$g, 'inactiveAttributes', [['alt.binaries.test'], ['alt.binaries.movies']]],
            'inactiveAttributes/uncommon' => [$g, 'inactiveAttributes', [['alt.'.str_repeat('x', 251)], ["alt.binary\0\xff"]]],
            'expectedSnapshotMatches/common' => [$c, 'expectedSnapshotMatches', [[$snapshot, 42, 'Movie.2026'], [null, 42, 'Movie.2026']]],
            'expectedSnapshotMatches/uncommon' => [$c, 'expectedSnapshotMatches', [[$binarySnapshot, 42, "Movie\0\xff"], [$snapshot, 43, 'Movie.2026'], [array_replace($snapshot, ['searchname' => null]), 42, '']]],
            'uppercaseHex/common' => [$c, 'uppercaseHex', [['Movie.2026.1080p.WEB-DL-GRP'], ['a1234567890bcdef']]],
            'uppercaseHex/uncommon' => [$c, 'uppercaseHex', [["\0\xff\tname"], [str_repeat('binary', 1024)], ['']]],
            'categoryAlreadyCurrent/common' => [$c, 'categoryAlreadyCurrent', [[5000, 5000, 1], [5000, 5030, 0]]],
            'categoryAlreadyCurrent/uncommon' => [$c, 'categoryAlreadyCurrent', [[PHP_INT_MIN, PHP_INT_MIN, 2], [PHP_INT_MAX, PHP_INT_MAX, 1], [0, 0, -1]]],
            'reasonKeysToLabels/common' => [$r, 'reasonKeysToLabels', [['duplicate'], ['password,incomplete'], [null]]],
            'reasonKeysToLabels/uncommon' => [$r, 'reasonKeysToLabels', [["\0\tother\v,Other,\xff,\fother\f,,0"], [str_repeat('duplicate,other,', 256)], ['0']]],
            'statusCountSummary/common' => [$r, 'statusCountSummary', [[['pending' => 12, 'reviewed' => 4, 'resolved' => 2]], [[]]]],
            'statusCountSummary/uncommon' => [$r, 'statusCountSummary', [[['pending' => '3', 'resolved' => '0', 'unknown' => '14']], [array_fill_keys(array_map(static fn (int $i): string => 'unknown'.$i, range(0, 127)), 1)], [['pending' => PHP_INT_MAX]]]],
            'normalizeParHash/common' => [$f, 'normalizeParHash', [[str_repeat('a1B2', 8)], [str_repeat('F', 32)]]],
            'normalizeParHash/uncommon' => [$f, 'normalizeParHash', [[''], [str_repeat('g', 32)], [str_repeat('a', 31)."\0"], [str_repeat('a', 4096)]]],
            'shouldRefreshHashSnapshot/common' => [$f, 'shouldRefreshHashSnapshot', [[['proc_hash16k' => 0], 0, 0], [[], 0, 1], [['proc_hash16k' => 1], 1, 1]]],
            'shouldRefreshHashSnapshot/uncommon' => [$f, 'shouldRefreshHashSnapshot', [[['proc_hash16k' => null], 0, 1], [['proc_hash16k' => false], PHP_INT_MAX, -1], [['proc_hash16k' => '0'], 0, 2]]],
        ];
    }

    /** A private SQLite fixture; no application boot, runtime config, or production connection. */
    private function withDatabase(callable $test): void
    {
        $previousContainer = Container::getInstance();
        $previousFacadeApp = Facade::getFacadeApplication();
        $resolved = new ReflectionProperty(Facade::class, 'resolvedInstance');
        $previousResolved = $resolved->getValue();
        $previousResolver = Model::getConnectionResolver();
        $previousDispatcher = Model::getEventDispatcher();
        $booted = new ReflectionProperty(Model::class, 'booted');
        $previousBooted = $booted->getValue();
        $capsule = null;
        try {
            $app = new Application(dirname(__DIR__, 2));
            $app->instance('config', new Repository(['nntmux' => ['categorization' => ['log' => false]]]));
            $app->instance('date', new DateFactory);
            $app->instance('cache', new CacheRepository(new ArrayStore));
            $app->singleton(ConfigurationProvider::class);
            Container::setInstance($app);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($app);
            Model::unsetEventDispatcher();
            $capsule = new Manager($app);
            $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
            $capsule->bootEloquent();
            $app->instance('db', $capsule->getDatabaseManager());
            $app->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
            $capsule->getConnection()->setTransactionManager(new DatabaseTransactionsManager);
            DB::statement('CREATE TABLE usenet_groups (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE, description TEXT, backfill_target INTEGER, first_record INTEGER, last_record INTEGER, active INTEGER, backfill INTEGER, minfilestoformrelease TEXT, minsizetoformrelease TEXT)');
            DB::statement('CREATE TABLE release_reports (id INTEGER PRIMARY KEY AUTOINCREMENT, status TEXT)');
            DB::statement('CREATE TABLE releases (id INTEGER PRIMARY KEY, guid TEXT, name TEXT, searchname TEXT, groups_id INTEGER, fromname TEXT, categories_id INTEGER, iscategorized INTEGER, rarinnerfilecount INTEGER DEFAULT 0, updated_at TEXT)');
            DB::statement('CREATE TABLE release_files (releases_id INTEGER, name TEXT, size INTEGER, created_at TEXT, updated_at INTEGER, passworded INTEGER, crc32 TEXT, PRIMARY KEY (releases_id, name))');
            DB::statement('CREATE TABLE par_hashes (releases_id INTEGER, hash TEXT, PRIMARY KEY (releases_id, hash))');
            $test();
        } finally {
            $capsule?->getConnection()->disconnect();
            if ($previousResolver !== null) {
                Model::setConnectionResolver($previousResolver);
            } else {
                Model::unsetConnectionResolver();
            }
            if ($previousDispatcher !== null) {
                Model::setEventDispatcher($previousDispatcher);
            } else {
                Model::unsetEventDispatcher();
            }
            $booted->setValue(null, $previousBooted);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($previousFacadeApp);
            $resolved->setValue(null, $previousResolved);
            Container::setInstance($previousContainer);
            self::enableRust(false);
        }
    }
}
