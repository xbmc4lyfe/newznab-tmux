<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\IncompletePredbMatchException;
use App\Models\Release;
use App\Services\NameFixing\Checkers\AbstractNameChecker;
use App\Services\NameFixing\Data\FilenamePass;
use App\Services\NameFixing\NameFixingQueryService;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\Predb\PredbIdentityPolicy;
use App\Support\RustPipelineLibrary;
use Carbon\CarbonImmutable;
use FFI;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class RustNameFixingQueryPassParityTest extends TestCase
{
    private const string CDEF = 'ssize_t nntmux_name_fixing_query_pass(uint32_t mode, const char *input, size_t len, int64_t first, int64_t second, char *out, size_t cap);';

    private function invoke(object|string $object, string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod($object, $method))->invoke(is_object($object) ? $object : null, ...$arguments);
    }

    private function service(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    private function ffi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('cleaning', self::CDEF);
        self::assertNotNull($ffi, 'Build the optimized Wave 17 library before native parity tests.');

        return $ffi;
    }

    /** @param list<string> $fields */
    private function fields(array $fields): string
    {
        return implode('', array_map(static fn (string $value): string => pack('V', strlen($value)).$value, $fields));
    }

    public function test_php_helpers_have_explicit_native_and_php_paths(): void
    {
        foreach ([
            NameFixingService::class => ['isSeasonPack', 'diagnosticSource', 'processingStatusColumn', 'validatePredbCandidatePage', 'distinctValues'],
            NameFixingQueryService::class => ['pendingWorkCondition', 'candidateWhere', 'placeholders', 'escapeLike', 'groupByReleaseId', 'groupByKey'],
            ReleaseUpdateService::class => ['isTrustedSource', 'getStatusColumnsForType', 'renamesSeasonPackToEpisode', 'completedReleaseSnapshot'],
            FilenamePass::class => ['complete', 'hasMatches', 'uniqueMatches'],
            AbstractNameChecker::class => ['patternNameToMethod', 'matchPattern'],
        ] as $class => $methods) {
            foreach ($methods as $method) {
                self::assertTrue(method_exists($class, $method.'Native'), $class.'::'.$method.'Native');
                self::assertTrue(method_exists($class, $method.'Php'), $class.'::'.$method.'Php');
            }
        }
    }

    public function test_modified_identity_definitions_retain_current_policy_markers(): void
    {
        $policy = new PredbIdentityPolicy;
        foreach ([NameFixingService::class, NameFixingQueryService::class, ReleaseUpdateService::class, FilenamePass::class] as $class) {
            self::assertTrue($policy->definitionIsCurrent($class), $class.' source fingerprint must match its marker.');
        }
    }

    protected function tearDown(): void
    {
        foreach ([NameFixingService::class, NameFixingQueryService::class, ReleaseUpdateService::class, AbstractNameChecker::class] as $class) {
            $class::setUseRustFfi(false);
        }
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function checker(): AbstractNameChecker
    {
        return new class extends AbstractNameChecker
        {
            protected function getPatterns(): array
            {
                return ['LITERAL_NAME' => '/hello/i', 'CAPTURE_PATTERN' => '/world(.*)/'];
            }

            protected function formatMethod(string $patternName): string
            {
                return $this->patternNameToMethod($patternName);
            }
        };
    }

    /** Verify direct FFI and both production dispatch settings.
     * @param  list<mixed>  $arguments
     */
    private function parity(object $service, string $method, array $arguments, bool $supported = true): void
    {
        $expected = $this->invoke($service, $method.'Php', ...$arguments);
        $native = $this->invoke($service, $method.'Native', ...$arguments);
        if ($supported) {
            self::assertSame($expected, $native, get_class($service).'::'.$method);
        } else {
            self::assertNull($native);
        }
        foreach ([false, true] as $enabled) {
            $service::setUseRustFfi($enabled);
            self::assertSame($expected, $this->invoke($service, $method, ...$arguments));
        }
    }

    public function test_string_classification_status_and_diagnostic_parity(): void
    {
        $this->ffi();
        $service = $this->service(NameFixingService::class);
        $updater = $this->service(ReleaseUpdateService::class);
        foreach (['', 'Show.S01', 'Show.S0001D02', 'Show.Season.1', 'Show.Complete.Series', 'Show.S01E01', 'Show.1x01', 'Atmos7.1', "Show\0.S01", '番組.S01', "Show.S01\n"] as $name) {
            $this->parity($service, 'isSeasonPack', [$name], ! str_contains($name, "\n"));
            foreach (['Show.S01E01', 'Show.S01', "Other.E01\n"] as $newName) {
                $this->parity($updater, 'renamesSeasonPackToEpisode', [$name, $newName], ! str_contains($name.$newName, "\n"));
            }
        }
        foreach (['', 'irc', str_repeat('a', 50), str_repeat('a', 51), "a\0b", "a\nb", "a\x7fb", 'Été', "a\u{200B}b", "\xff", str_repeat('界', 51)] as $source) {
            $this->parity($service, 'diagnosticSource', [$source], preg_match('/[\x80-\xff]/', $source) !== 1);
        }
        foreach (['', 'NFO, ', 'PAR2, ', 'Filenames, ', 'file matched source: ', 'PreDB FT Exact, ', 'sorter, ', 'UID, ', 'Mediainfo, ', 'PAR2 hash, ', 'SRR, ', 'CRC32, ', 'PreDb custom', 'predb', "NFO, \0"] as $type) {
            $this->parity($service, 'processingStatusColumn', [$type]);
            $this->parity($updater, 'getStatusColumnsForType', [$type]);
            foreach (['', 'title MATCH', 'FILE MATCHED SOURCE: x', 'preDB', "\xffPreDb", 'untrusted'] as $method) {
                foreach ([-1, 0, 1, PHP_INT_MAX] as $preId) {
                    $this->parity($updater, 'isTrustedSource', [$type, $method, $preId]);
                }
            }
        }
        self::assertSame(['isrenamed' => 1, 'iscategorized' => 1, 'proc_nfo' => 1], $this->invoke($updater, 'getStatusColumnsForTypeNative', 'NFO, '));
        self::assertFalse($this->invoke($updater, 'isTrustedSourceNative', 'predb', '', 0));
    }

    public function test_snapshot_preserves_missing_nullable_empty_and_binary_values(): void
    {
        $this->ffi();
        $service = $this->service(ReleaseUpdateService::class);
        $valid = (object) ['releases_id' => '42', 'id' => 100, 'guid' => "g\0\xff", 'name' => null, 'searchname' => ''];
        foreach ([(object) [], (object) ['guid' => 'g', 'name' => 'n'], $valid,
            (object) ['id' => PHP_INT_MAX, 'guid' => '', 'name' => "\xff", 'searchname' => null],
            new Release(['id' => 7, 'guid' => 'g', 'name' => null, 'searchname' => 'old'])] as $release) {
            foreach ([null, '', "New\0Title"] as $newTitle) {
                $this->parity($service, 'completedReleaseSnapshot', [$release, $newTitle]);
            }
        }
        self::assertSame(['id' => 42, 'guid' => "g\0\xff", 'name' => null, 'searchname' => ''], $this->invoke($service, 'completedReleaseSnapshotNative', $valid));
    }

    public function test_filename_pass_agreement_completion_and_original_match_order(): void
    {
        $this->ffi();
        $sets = [[], [[true, null]], [[false, null]], [[true, 1], [true, 1]], [[true, 1], [true, 2]], [[true, 1], [false, 1]], [[true, null], [true, 1], [true, 1]]];
        foreach ($sets as $set) {
            $lookups = [];
            $filenames = [];
            foreach ($set as $index => [$complete, $id]) {
                $filenames[] = $filename = 'file'.$index.'.mkv';
                $lookups[] = ['filename' => $filename, 'match' => $id === null ? null : ['id' => $id, 'title' => $index % 2 ? 'ÉTÉ.Show-GRP' : 'été.show-grp', 'filename' => 'file'], 'evidence' => $id !== null, 'complete' => $complete];
            }
            foreach ([false, true] as $enabled) {
                $pass = new FilenamePass($filenames, $lookups, useRustFfi: $enabled);
                foreach (['complete', 'hasMatches', 'uniqueMatches'] as $method) {
                    $expected = $this->invoke($pass, $method.'Php');
                    self::assertSame($expected, $this->invoke($pass, $method.'Native'));
                    self::assertSame($expected, $pass->{$method}());
                }
            }
        }
        $pass = new FilenamePass(['a', 'b'], [
            ['filename' => 'a', 'match' => ['id' => 1, 'title' => 'Title-GRP', 'filename' => 'a'], 'evidence' => true, 'complete' => true],
            ['filename' => 'b', 'match' => ['id' => 1, 'title' => 'Other-GRP', 'filename' => 'b'], 'evidence' => true, 'complete' => true],
        ], useRustFfi: true);
        self::assertSame([], $pass->uniqueMatches());
    }

    public function test_grouping_escaping_and_placeholders_preserve_php_key_and_byte_semantics(): void
    {
        $this->ffi();
        $service = new NameFixingQueryService($this->createStub(ConnectionInterface::class));
        foreach ([0, 1, 2, 1000] as $count) {
            $this->parity($service, 'placeholders', [$count]);
        }
        self::assertSame('?,?,?', $this->invoke($service, 'placeholdersNative', 3));
        self::assertNull($this->invoke($service, 'placeholdersNative', -1));
        try {
            $this->invoke($service, 'placeholders', -1);
            self::fail('Negative placeholder counts retain ValueError.');
        } catch (\ValueError $exception) {
            self::assertStringContainsString('greater than or equal to 0', $exception->getMessage());
        }
        foreach (['', '%_\\', "\0\xff_a%", 'already\\%escaped', 'Été_100%'] as $text) {
            $this->parity($service, 'escapeLike', [$text]);
        }
        $rows = [];
        foreach ([1, '1', '01', '-1', -1, '', null, "a\0b", "\xff", PHP_INT_MAX, (string) PHP_INT_MAX] as $key) {
            $rows[] = (object) ['releases_id' => $key, 'match_key' => $key];
        }
        foreach ([[], $rows, array_reverse($rows)] as $input) {
            $this->parity($service, 'groupByKey', [$input, 'match_key']);
            $this->parity($service, 'groupByReleaseId', [$input]);
        }
        $grouped = $this->invoke($service, 'groupByKeyNative', $rows, 'match_key');
        self::assertSame([$rows[0], $rows[1]], $grouped[1]);
        $names = $this->service(NameFixingService::class);
        $this->parity($names, 'distinctValues', [[1 => [(object) ['v' => '01'], (object) ['v' => '1'], (object) ['v' => '01'], (object) ['v' => 0], (object) ['v' => null], (object) []]], 'v']);
    }

    public function test_query_predicates_match_exact_sql_bindings_and_config_scopes(): void
    {
        $this->ffi();
        CarbonImmutable::setTestNow('2026-10-24 12:34:56');
        Schema::create('releases', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('late_article_candidate_id')->nullable();
        });
        $service = new NameFixingQueryService(DB::connection());
        foreach (['nfo', 'files', 'srr', 'crc', 'uid', 'hash', 'par2', 'xxx', 'media_movie'] as $source) {
            foreach ([0, 1, 2] as $time) {
                foreach ([0, 1, 2, 3] as $categories) {
                    $this->parity($service, 'candidateWhere', [$source, $time, $categories]);
                }
            }
        }
        foreach (['all', 'other', 'unexpected'] as $scope) {
            foreach ([false, true] as $full) {
                config(['tmux.fix_names.category' => $scope, 'tmux.fix_names.full_backlog' => $full]);
                $this->parity($service, 'pendingWorkCondition', []);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->invoke($service, 'candidateWhere', "nfo\0", 1, 1);
    }

    public function test_candidate_page_shape_boundaries_order_and_integer_errors(): void
    {
        $this->ffi();
        $service = $this->service(NameFixingService::class);
        foreach ([[[], false, 0], [[1, 4, PHP_INT_MAX], false, 0], [range(1, 21), true, 0], [[], false, PHP_INT_MAX]] as [$ids, $more, $after]) {
            $this->parity($service, 'validatePredbCandidatePage', [['ids' => $ids, 'has_more' => $more], $after]);
        }
        foreach ([[], ['ids' => 'bad', 'has_more' => false], ['ids' => [1], 'has_more' => true], ['ids' => [2, 1], 'has_more' => false], ['ids' => ['1'], 'has_more' => false], ['ids' => [1 => 1], 'has_more' => false], ['ids' => range(1, 22), 'has_more' => false]] as $page) {
            $errors = [];
            foreach (['validatePredbCandidatePagePhp', 'validatePredbCandidatePage'] as $method) {
                NameFixingService::setUseRustFfi(true);
                try {
                    $this->invoke($service, $method, $page, 0);
                    self::fail('Malformed candidate pages must throw.');
                } catch (IncompletePredbMatchException $exception) {
                    $errors[] = $exception->getMessage();
                }
            }
            self::assertSame($errors[0], $errors[1]);
        }
    }

    public function test_abstract_checker_literal_matches_and_pcre_fallbacks(): void
    {
        $this->ffi();
        $checker = $this->checker();
        foreach (['', 'SOME_NAME', 'Title__Match', "ÉTÉ_\xff_A\0B"] as $patternName) {
            $this->parity($checker, 'patternNameToMethod', [$patternName]);
        }
        foreach ([['/hello/i', 'xxHeLLo!'], ['/^hello$/', 'hello'], ['/^hello$/', 'xhello'], ['/literal/', 'absent'], ['/a\\.b/', 'a.b']] as $input) {
            $this->parity($checker, 'matchPattern', $input);
        }
        foreach ([['/world(.*)/', 'world and more'], ['/hello/u', 'hello'], ['/hello/', "hello\n"], ['/é/i', 'É'], ['/a+/', 'aaa'], ['/\\d/', '2']] as $input) {
            $this->parity($checker, 'matchPattern', $input, false);
        }
        AbstractNameChecker::setUseRustFfi(true);
        self::assertSame('HeLLo', $checker->check((object) [], 'HeLLo world')->newName);
        self::assertSame('world-tail', $checker->check((object) [], 'world-tail')->newName);
        self::assertNull($checker->check((object) [], 'no match'));
    }

    public function test_abi_rejects_bad_pointers_lengths_frames_modes_and_small_buffers_without_writes(): void
    {
        $ffi = $this->ffi();
        $symbol = 'nntmux_name_fixing_query_pass';
        $buffer = $ffi->new('char[16]');
        FFI::memset($buffer, 0x5A, 16);
        foreach ([[0, null, 1, 0, 0, 16, -1], [0, 'x', -1, 0, 0, 16, -1], [0, 'x', 1, 0, 0, -1, -1], [999, '', 0, 0, 0, 16, -2], [5, "\x04\0", 2, 0, 0, 16, -1], [2, '', 0, PHP_INT_MAX, 0, 16, -1], [2, '', 0, 1000, 0, 16, -3], [3, '%', 1, 0, 0, 1, -3]] as [$mode, $input, $len, $first, $second, $cap, $expected]) {
            // @phpstan-ignore method.notFound (Declared by the runtime CDEF.)
            self::assertSame($expected, $ffi->$symbol($mode, $input, $len, $first, $second, $buffer, $cap));
            self::assertSame(str_repeat('Z', 16), FFI::string($buffer, 16));
        }
        $trusted = $this->fields(['UID, ', '']);
        // @phpstan-ignore method.notFound (Declared by the runtime CDEF.)
        self::assertSame(1, $ffi->$symbol(5, $trusted, strlen($trusted), 0, 0, $buffer, 16));
        // @phpstan-ignore method.notFound (Declared by the runtime CDEF.)
        self::assertSame(-1, $ffi->$symbol(0, '', 0, 0, 0, null, 1));
        // @phpstan-ignore method.notFound (Declared by the runtime CDEF.)
        self::assertSame(-3, $ffi->$symbol(0, '', 0, 0, 0, null, 0));
        // @phpstan-ignore method.notFound (Declared by the runtime CDEF.)
        self::assertSame(0, $ffi->$symbol(2, null, 0, 0, 0, null, 0));
        // @phpstan-ignore method.notFound (Declared by the runtime CDEF.)
        self::assertSame(2, $ffi->$symbol(3, '%', 1, 0, 0, $buffer, 2));
        self::assertSame('\\%', FFI::string($buffer, 2));
    }

    public function test_unavailable_domain_library_falls_back_for_every_class(): void
    {
        $property = new \ReflectionProperty(RustPipelineLibrary::class, 'domainLibraries');
        $saved = $property->getValue();
        try {
            foreach ([NameFixingService::class, NameFixingQueryService::class, ReleaseUpdateService::class, FilenamePass::class, AbstractNameChecker::class] as $class) {
                $cdef = (new ReflectionClass($class))->getConstant('FFI_CDEF');
                $unavailable = $property->getValue();
                $unavailable['cleaning|default|'.hash('xxh3', $cdef)] = false;
                $property->setValue(null, $unavailable);
            }
            NameFixingService::setUseRustFfi(true);
            NameFixingQueryService::setUseRustFfi(true);
            ReleaseUpdateService::setUseRustFfi(true);
            AbstractNameChecker::setUseRustFfi(true);
            self::assertTrue($this->service(NameFixingService::class)->isSeasonPack('Show.S01'));
            self::assertSame('a\\%', $this->invoke(new NameFixingQueryService($this->createStub(ConnectionInterface::class)), 'escapeLike', 'a%'));
            self::assertTrue($this->invoke($this->service(ReleaseUpdateService::class), 'isTrustedSource', 'UID, ', '', 0));
            self::assertTrue((new FilenamePass([], [], useRustFfi: true))->complete());
            self::assertSame('hello', $this->checker()->check((object) [], 'hello')->newName);
        } finally {
            $property->setValue(null, $saved);
        }
    }

    /**
     * Reproduce with NNTMUX_NAME_FIXING_QUERY_PASS_BENCH=1 php artisan test
     * --compact tests/Unit/RustNameFixingQueryPassParityTest.php.
     * Two repeated 5,000-pair runs, 100 untimed warmups, seeded paired order.
     * Timings include marshalling, FFI, output decoding and declined-call fallback.
     * Recorded on Apple M5/macOS arm64, PHP 8.5.12, Cargo 1.99.1 (release).
     * 20 helpers x 2 workloads x 2 repetitions x 5,000 pairs = 400,000 pairs.
     * None passed all four 1.5x gates: every Rust toggle stays false by default.
     * Conservative minima over the two repetitions, common mean / p99 then
     * uncommon mean / p99 (PHP latency / native caller latency):
     * season               0.20 / 0.22 / 0.70 / 0.80
     * diagnostic           0.18 / 0.19 / 0.14 / 0.18
     * processing_column    0.06 / 0.06 / 0.07 / 0.07
     * candidate_page       0.20 / 0.24 / 0.19 / 0.18
     * distinct_values      0.13 / 0.06 / 0.25 / 0.30
     * pending_sql          0.90 / 0.82 / 0.90 / 0.82
     * candidate_sql        0.62 / 0.75 / 0.88 / 0.90
     * placeholders         0.17 / 0.27 / 1.96 / 3.13
     * escape_like          0.14 / 0.20 / 0.68 / 0.66
     * group_release        0.11 / 0.09 / 0.11 / 0.13
     * group_key            0.11 / 0.09 / 0.13 / 0.15
     * trusted              0.10 / 0.08 / 0.26 / 0.20
     * status_columns       0.05 / 0.04 / 0.06 / 0.12
     * season_rename        0.29 / 0.17 / 0.92 / 0.92
     * snapshot             0.10 / 0.08 / 0.06 / 0.06
     * pattern_name         0.15 / 0.18 / 0.75 / 0.44
     * pattern_match        0.11 / 0.18 / 0.08 / 0.08
     * pass_complete        0.08 / 0.11 / 0.27 / 0.19
     * pass_has_matches     0.06 / 0.12 / 0.01 / 0.02
     * pass_unique          0.30 / 0.28 / 0.45 / 0.38
     * Raw nanoseconds, input hashes, build/source identity and p99 rank interval
     * estimates are preserved in a uniquely named temporary JSON artifact.
     */
    public function test_opt_in_paired_randomized_caller_benchmarks(): void
    {
        if (getenv('NNTMUX_NAME_FIXING_QUERY_PASS_BENCH') !== '1') {
            self::markTestSkipped('Opt-in paired benchmarks.');
        }
        $this->ffi();
        Schema::create('releases', static function (Blueprint $table): void {
            $table->id();
        });
        CarbonImmutable::setTestNow('2026-10-24 12:34:56');
        $names = $this->service(NameFixingService::class);
        $update = $this->service(ReleaseUpdateService::class);
        $query = new NameFixingQueryService(DB::connection());
        $checker = $this->checker();
        $long = str_repeat('Long.Title_', 256);
        $rows = [];
        $lookups = [];
        $filenames = [];
        for ($i = 0; $i < 128; $i++) {
            $rows[] = (object) ['releases_id' => $i % 32, 'match_key' => (string) ($i % 32), 'v' => 'value'.($i % 32)];
            $filenames[] = $filename = 'file'.$i.'.mkv';
            $lookups[] = ['filename' => $filename, 'match' => ['id' => 1, 'title' => 'Title-GRP', 'filename' => 'file'], 'complete' => true, 'evidence' => true];
        }
        $passCommon = new FilenamePass(array_slice($filenames, 0, 3), array_slice($lookups, 0, 3));
        $passUncommon = new FilenamePass($filenames, $lookups);
        $cases = [
            ['season', $names, 'isSeasonPack', [['Show.S01']], [[$long.'S01E01'], ["Show.S01\n"]]],
            ['diagnostic', $names, 'diagnosticSource', [['irc']], [[str_repeat('x', 201)], ['Été']]],
            ['processing_column', $names, 'processingStatusColumn', [['NFO, '], ['UID, ']], [['untrusted'], ['PAR2 hash, ']]],
            ['candidate_page', $names, 'validatePredbCandidatePage', [[['ids' => [1, 2, 3], 'has_more' => false], 0]], [[['ids' => range(1, 21), 'has_more' => true], 0]]],
            ['distinct_values', $names, 'distinctValues', [[[array_slice($rows, 0, 3)], 'v']], [[[$rows, $rows], 'v']]],
            ['pending_sql', $query, 'pendingWorkCondition', [[]], [[]]],
            ['candidate_sql', $query, 'candidateWhere', [['files', 2, 1], ['uid', 0, 3]], [['hash', 1, 1], ['par2', 1, 2]]],
            ['placeholders', $query, 'placeholders', [[3], [10]], [[1000], [0]]],
            ['escape_like', $query, 'escapeLike', [['Show.Name_100%'], ['safe']], [[$long.'%_\\'], ["\xff\0%_"]]],
            ['group_release', $query, 'groupByReleaseId', [[array_slice($rows, 0, 3)]], [[$rows]]],
            ['group_key', $query, 'groupByKey', [[array_slice($rows, 0, 3), 'match_key']], [[$rows, 'match_key']]],
            ['trusted', $update, 'isTrustedSource', [['UID, ', '', 0], ['', 'Title Match', 0]], [['untrusted', $long.'file matched source', 0], ['unknown', $long, -1]]],
            ['status_columns', $update, 'getStatusColumnsForType', [['NFO, '], ['UID, ']], [['sorter, '], ['unknown']]],
            ['season_rename', $update, 'renamesSeasonPackToEpisode', [['Show.S01', 'Show.S01E01']], [[$long.'S01', $long.'S01E01'], ["Show.S01\n", 'Show.S01E01']]],
            ['snapshot', $update, 'completedReleaseSnapshot', [[(object) ['id' => 1, 'guid' => 'g', 'name' => 'n', 'searchname' => 's'], null]], [[(object) ['releases_id' => PHP_INT_MAX, 'guid' => "g\0\xff", 'name' => null, 'searchname' => $long], $long.'new']]],
            ['pattern_name', $checker, 'patternNameToMethod', [['TV_SOURCE_GROUP']], [[$long.'ÉTÉ_']]],
            ['pattern_match', $checker, 'matchPattern', [['/hello/i', 'xxHello!']], [['/literal/i', $long.'literal'], ['/hello/u', 'hello']]],
            ['pass_complete', $passCommon, 'complete', [[]], [[]]],
            ['pass_has_matches', $passCommon, 'hasMatches', [[]], [[]]],
            ['pass_unique', $passCommon, 'uniqueMatches', [[]], [[]]],
        ];
        $report = [
            'protocol' => '2 repetitions x 5000 randomized pairs; 100 warmups; ns; nearest-rank p99',
            'seed' => 17012026, 'php' => PHP_VERSION, 'pcre' => PCRE_VERSION,
            'host' => php_uname(), 'memory_limit' => ini_get('memory_limit'),
            'jit' => ini_get('opcache.jit'), 'opcache_cli' => ini_get('opcache.enable_cli'),
            'build_profile' => 'release opt-level=3 lto=true codegen-units=1',
            'head' => trim((string) shell_exec('git rev-parse HEAD')),
            'cargo' => trim((string) shell_exec('cargo --version')),
            'rustc' => trim((string) shell_exec('rustc --version')),
            'library_sha256' => hash_file('sha256', base_path('rust/pipeline-ffi/target/release/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so'))),
            'sources' => [], 'results' => [],
        ];
        foreach (['rust/pipeline-ffi/src/cleaning.rs', 'rust/pipeline-ffi/Cargo.lock', 'composer.lock', 'app/Services/NameFixing/NameFixingService.php', 'app/Services/NameFixing/NameFixingQueryService.php', 'app/Services/NameFixing/ReleaseUpdateService.php', 'app/Services/NameFixing/Data/FilenamePass.php', 'app/Services/NameFixing/Checkers/AbstractNameChecker.php', 'tests/Unit/RustNameFixingQueryPassParityTest.php'] as $file) {
            $report['sources'][$file] = hash_file('sha256', base_path($file));
        }
        $random = new Randomizer(new Mt19937(17012026));
        foreach ($cases as [$name, $service, $method, $common, $uncommon]) {
            foreach (['common' => $common, 'uncommon' => $uncommon] as $workload => $fixtures) {
                $target = $service instanceof FilenamePass && $workload === 'uncommon' ? $passUncommon : $service;
                $php = (new ReflectionMethod($target, $method.'Php'))->getClosure($target);
                $nativeMethod = (new ReflectionMethod($target, $method.'Native'))->getClosure($target);
                $native = static fn (array $arguments): mixed => $nativeMethod(...$arguments) ?? $php(...$arguments);
                // Configuration reads stay in both timed callers. Vary realistic SQL scope.
                if ($name === 'pending_sql') {
                    config(['tmux.fix_names.category' => $workload === 'common' ? 'other' : 'all', 'tmux.fix_names.full_backlog' => $workload === 'common']);
                }
                for ($warm = 0; $warm < 100; $warm++) {
                    foreach ($fixtures as $arguments) {
                        self::assertSame($php(...$arguments), $native($arguments));
                    }
                }
                for ($repetition = 1; $repetition <= 2; $repetition++) {
                    $pairs = [];
                    $phpTimes = [];
                    $nativeTimes = [];
                    for ($pair = 0; $pair < 5000; $pair++) {
                        $fixture = $random->getInt(0, count($fixtures) - 1);
                        $arguments = $fixtures[$fixture];
                        $nativeFirst = (bool) $random->getInt(0, 1);
                        if ($nativeFirst) {
                            $start = hrtime(true);
                            $actual = $native($arguments);
                            $nativeNs = hrtime(true) - $start;
                            $start = hrtime(true);
                            $expected = $php(...$arguments);
                            $phpNs = hrtime(true) - $start;
                        } else {
                            $start = hrtime(true);
                            $expected = $php(...$arguments);
                            $phpNs = hrtime(true) - $start;
                            $start = hrtime(true);
                            $actual = $native($arguments);
                            $nativeNs = hrtime(true) - $start;
                        }
                        self::assertSame($expected, $actual);
                        $pairs[] = [$fixture, $nativeFirst, $phpNs, $nativeNs];
                        $phpTimes[] = $phpNs;
                        $nativeTimes[] = $nativeNs;
                    }
                    sort($phpTimes);
                    sort($nativeTimes);
                    $p99 = 4949;
                    // Approximate 95% binomial rank interval for n=5000, p=.99.
                    $meanSpeedup = array_sum($phpTimes) / array_sum($nativeTimes);
                    $p99Speedup = $phpTimes[$p99] / max(1, $nativeTimes[$p99]);
                    $result = ['method' => $name, 'workload' => $workload, 'repetition' => $repetition,
                        'fixture_sha256' => hash('sha256', serialize($fixtures)),
                        'fixtures_base64_serialized' => base64_encode(serialize($fixtures)),
                        'php_mean_ns' => array_sum($phpTimes) / 5000, 'rust_mean_ns' => array_sum($nativeTimes) / 5000,
                        'php_p99_ns' => $phpTimes[$p99], 'rust_p99_ns' => $nativeTimes[$p99],
                        'p99_rank_interval' => [4935, 4964], 'php_p99_interval_ns' => [$phpTimes[4934], $phpTimes[4963]],
                        'rust_p99_interval_ns' => [$nativeTimes[4934], $nativeTimes[4963]],
                        'mean_speedup' => $meanSpeedup, 'p99_speedup' => $p99Speedup, 'pairs' => $pairs];
                    $report['results'][] = $result;
                    fwrite(STDERR, sprintf("%s %s run%d mean=%.2fx p99=%.2fx\n", $name, $workload, $repetition, $meanSpeedup, $p99Speedup));
                }
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'wave17-name-query-bench-');
        self::assertNotFalse($path);
        self::assertNotFalse(file_put_contents($path, json_encode($report, JSON_THROW_ON_ERROR)));
        fwrite(STDERR, 'Wave 17 paired benchmark raw results: '.$path.PHP_EOL);
    }
}
