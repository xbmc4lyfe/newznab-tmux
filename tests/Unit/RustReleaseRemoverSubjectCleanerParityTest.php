<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\ReleasesCleanSubjectNames;
use App\Console\Commands\ReleasesNormalizeGuids;
use App\Services\ReleaseCleaningService;
use App\Services\ReleaseRemoverService;
use App\Support\RustPipelineLibrary;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Wave 11 rejection evidence: baseline fead5ae34261, PHP 8.5.12, rustc 1.99.1,
 * release opt-level=3/LTO/one codegen unit; Darwin arm64 Mac17,3, 10 CPUs,
 * 32 GiB RAM; host process, no imposed caps, OPcache CLI/JIT off, LC_CTYPE C.UTF-8.
 * 1,024 warmups then 5 x 4,096 randomized paired single calls per workload.
 * Ratios are PHP/Rust mean and nearest-rank p99, including PHP wrappers,
 * serialization, FFI, allocation and fallback; GUID batches contain 1,000 rows.
 *
 * Candidate                    common mean/p99    uncommon mean/p99
 * cleanSpaces                  0.24 / 0.20        1.36 / 1.40
 * formatLike                   0.28 / 0.28        1.32 / 1.24
 * formatCriteriaQuery          0.64 / 0.39        0.91 / 1.07
 * buildCriteriaClause          0.28 / 0.11        0.85 / 1.04
 * buildNumericClause           0.17 / 0.06        0.17 / 0.13
 * buildImdbClause              0.17 / 0.13        0.18 / 0.12
 * buildCompletionClause        0.18 / 0.18        0.17 / 0.15
 * formatBlacklistID            0.16 / 0.14        0.21 / 0.08
 * subjectCandidate             0.30 / 0.30        0.60 / 0.67
 * isUnsupportedGuid            0.28 / 0.27        0.33 / 0.29
 * classifyGuids                0.87 / 0.88        1.02 / 0.91
 *
 * None reaches >=1.5x mean AND p99 for BOTH workloads: production retains PHP.
 * Shared-host tail noise is visible in the raw five-block results; no positive
 * capacity/throughput claim is made. Subject timings isolate only the changed
 * post-filter, not cleaning or database work, and cannot justify caller adoption.
 * Raw samples, orders, inputs, source/library/lock hashes and block uncertainty:
 * /tmp/wave11-worker2-benchmark.json. Reproduce with benchmark() below.
 * Two earlier screens also rejected adoption; final fixtures include SQL whitespace.
 */
final class RustReleaseRemoverSubjectCleanerParityTest extends TestCase
{
    protected function tearDown(): void
    {
        ReleaseRemoverService::useRustFfi(false);
        ReleasesCleanSubjectNames::useRustFfi(false);
        ReleasesNormalizeGuids::useRustFfi(false);
        parent::tearDown();
    }

    public function test_candidates_are_available_before_comparing_parity(): void
    {
        self::assertSame('a b', $this->remover()->cleanSpacesNative(' a  b '));
        self::assertTrue(ReleasesCleanSubjectNames::subjectCandidateNative('Movie.2026'));
        self::assertFalse((new ReleasesNormalizeGuids)->isUnsupportedGuidNative(str_repeat('a', 40)));
    }

    public function test_whitespace_and_like_preserve_binary_strings_and_empty_terms(): void
    {
        $service = $this->remover();
        foreach (['', '0', ' a  b ', "a\tb", "a\t\nb", "\0 a\0 ", "a\fb", "\fa\f", 'café  title', str_repeat('x  ', 150000)] as $value) {
            self::assertSame($this->invoke($service, 'cleanSpaces', $value), $service->cleanSpacesNative($value));
            foreach (['searchname', "name\0column"] as $column) {
                self::assertSame($this->invoke($service, 'formatLike', $value, $column), $service->formatLikeNative($value, $column));
            }
        }
        self::assertSame(" LIKE '%a%' AND name LIKE '%%' AND name LIKE '%b%' ", $service->formatLikeNative('a  a b ', 'name'));
    }

    public function test_criteria_and_error_state_match_through_real_dispatch(): void
    {
        $service = $this->remover();
        $arguments = ['ignore', 'IGNORE', 'size=equals=123', 'size=bigger= 1e3 ', 'size=smaller=bad', 'totalpart=bigger=raw', 'imdbid=equals=NULL', 'imdbid=smaller=2', 'completion=smaller=99.5', 'completion=equals=90', 'adddate=bigger=24', 'postdate=smaller=+2.5', 'categories_id=equals=raw', 'name=like=one  one two', 'searchname=like=', 'invalid=equals=a', 'size=equals=1=2', ' size = bigger = 12 ', "size=equals=\01\0"];
        foreach ($arguments as $argument) {
            $php = $this->remover();
            $rust = $this->remover();
            ReleaseRemoverService::useRustFfi(false);
            $expected = $this->invoke($php, 'formatCriteriaQuery', $argument);
            ReleaseRemoverService::useRustFfi(true);
            self::assertSame($expected, $this->invoke($rust, 'formatCriteriaQuery', $argument), $argument);
            foreach (['error', 'ignoreUserCheck'] as $property) {
                $ref = new ReflectionProperty(ReleaseRemoverService::class, $property);
                self::assertSame($ref->getValue($php), $ref->getValue($rust));
            }
        }
        ReleaseRemoverService::useRustFfi(false);
        foreach (['0', '-1', '1.2', '.5', '1.', '+1e-3', ' 42 ', "1\f", '1e999', '0x10', 'INF', 'NAN', '', "1\0", '1,2', '1e+', "\v2\r"] as $value) {
            foreach (['equals', 'bigger', 'smaller', 'invalid'] as $modifier) {
                foreach (['size', 'totalpart', 'adddate', 'postdate', 'completion', 'imdbid', 'unknown'] as $column) {
                    self::assertSame($this->invoke($service, 'buildCriteriaClause', $column, $modifier, $value), $service->buildCriteriaClauseNative($column, $modifier, $value), "$column/$modifier/".bin2hex($value));
                }
                self::assertSame($this->invoke($service, 'buildNumericClause', 'size', $modifier, $value), $service->buildNumericClauseNative('size', $modifier, $value));
                self::assertSame($this->invoke($service, 'buildImdbClause', $modifier, $value), $service->buildImdbClauseNative($modifier, $value));
                self::assertSame($this->invoke($service, 'buildCompletionClause', $modifier, $value), $service->buildCompletionClauseNative($modifier, $value));
            }
        }
    }

    public function test_blacklist_numeric_coercion_matches_php_including_overflow(): void
    {
        $service = $this->remover();
        foreach (['', 0, -1, PHP_INT_MIN, PHP_INT_MAX, '0012', '+12', '-0', ' 42 ', '1.5', '1e3', '1e999', '9223372036854775808', '-9223372036854775809', 'x', "1\0"] as $value) {
            self::assertSame($this->invoke($service, 'formatBlacklistID', $value), $service->formatBlacklistIDNative($value), (string) $value);
        }
    }

    public function test_subject_filter_preserves_current_name_and_predb_contract(): void
    {
        $command = new ReleasesCleanSubjectNames;
        foreach (['' => false, '0' => true, '[1/2] Movie' => false, '[ 01 / 20 ]' => false, '[1/x] Movie' => true, 'Movie.2026' => true, "Movie\n" => false, 'Café' => false, "bad\0name" => false, ' [1/2] Movie' => true] as $name => $accepted) {
            $name = (string) $name;
            self::assertSame($accepted, ReleasesCleanSubjectNames::subjectCandidateNative($name));
            $cleaner = $this->getMockBuilder(ReleaseCleaningService::class)->disableOriginalConstructor()->onlyMethods(['releaseCleaner'])->getMock();
            $cleaner->method('releaseCleaner')->willReturn(['cleansubject' => $name, 'predb' => 42]);
            $row = (object) ['searchname' => 'raw', 'fromname' => '', 'group_name' => 'alt.binaries.test'];
            ReleasesCleanSubjectNames::useRustFfi(false);
            $expected = $this->invoke($command, 'cleanName', $cleaner, $row);
            ReleasesCleanSubjectNames::useRustFfi(true);
            self::assertSame($expected, $this->invoke($command, 'cleanName', $cleaner, $row));
        }
    }

    public function test_guid_single_and_batch_keep_ascii_and_hex_classification_distinct(): void
    {
        $command = new ReleasesNormalizeGuids;
        $values = [null, 12, false, [], new \stdClass, '', ' ', 'g', str_repeat('a', 31), str_repeat('A', 32), str_repeat('f', 40), str_repeat('a', 41), 'a1b2c3d4-0000-1234-abcd-0123456789ab', "a\0b", "abc\n", 'Café'];
        foreach ($values as $guid) {
            ReleasesNormalizeGuids::useRustFfi(false);
            self::assertSame($this->invoke($command, 'isUnsupportedGuid', $guid), $command->isUnsupportedGuidNative($guid));
        }
        $expected = array_map(static fn (mixed $guid): array => ['unsupported' => ! is_string($guid) || preg_match('/^[\x20-\x7E]{1,40}$/D', $guid) !== 1, 'hex' => is_string($guid) && preg_match('/\A[0-9a-fA-F]{32,40}\z/', $guid) === 1], $values);
        self::assertSame($expected, $command->classifyGuidsNative($values));
        self::assertSame([], $command->classifyGuidsNative([]));
        mt_srand(1102);
        for ($i = 0; $i < 500; $i++) {
            $value = '';
            for ($n = mt_rand(0, 60); $n > 0; $n--) {
                $value .= chr(mt_rand(0, 255));
            }
            self::assertSame($this->invoke($command, 'isUnsupportedGuid', $value), $command->isUnsupportedGuidNative($value));
        }
    }

    public function test_database_escaping_and_group_lookup_remain_in_php(): void
    {
        $original = DB::getFacadeRoot();
        $capsule = new Manager;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        DB::swap($capsule->getDatabaseManager());
        try {
            DB::statement('CREATE TABLE usenet_groups (id INTEGER, name TEXT)');
            DB::table('usenet_groups')->insert([['id' => 1, 'name' => 'alt.binaries.test'], ['id' => 2, 'name' => 'alt.binaries.movies']]);
            foreach (["name=equals=O'Reilly", "fromname=equals=poster' OR 1=1", 'guid=equals=abc', 'groupname=equals=alt.binaries.test', 'groupname=equals=absent', 'groupname=like=alt.binaries', 'groupname=like=absent', 'groupname=invalid=value'] as $argument) {
                $php = $this->remover();
                $rust = $this->remover();
                ReleaseRemoverService::useRustFfi(false);
                $expected = $this->invoke($php, 'formatCriteriaQuery', $argument);
                ReleaseRemoverService::useRustFfi(true);
                self::assertSame($expected, $this->invoke($rust, 'formatCriteriaQuery', $argument));
                $error = new ReflectionProperty(ReleaseRemoverService::class, 'error');
                self::assertSame($error->getValue($php), $error->getValue($rust));
            }
            self::assertSame(" AND name = 'O''Reilly'", $this->remover()->formatCriteriaQueryNative("name=equals=O'Reilly"));
        } finally {
            $capsule->getDatabaseManager()->disconnect();
            if ($original === null) {
                DB::clearResolvedInstance('db');
            } else {
                DB::swap($original);
            }
        }
    }

    public function test_disabled_ffi_and_declined_input_use_the_original_paths(): void
    {
        $service = $this->remover();
        ReleaseRemoverService::useRustFfi(true);
        self::assertSame(' AND name = 12', $service->buildNumericClauseNative('name', 'equals', '12'));
        self::assertSame(' AND size = 1', $this->invoke($service, 'formatCriteriaQuery', "size=equals=\0".'1'."\0"));
        $before = ini_get('pcre.backtrack_limit');
        try {
            ini_set('pcre.backtrack_limit', '1');
            self::assertNull($service->cleanSpacesNative('a  b'));
            self::assertNull($service->formatCriteriaQueryNative('size=equals=1'));
            self::assertNull(ReleasesCleanSubjectNames::subjectCandidateNative('[1/2] title'));
        } finally {
            ini_set('pcre.backtrack_limit', $before);
        }
    }

    public function test_missing_library_falls_back_without_changing_results(): void
    {
        $cache = new ReflectionProperty(RustPipelineLibrary::class, 'domainLibraries');
        $original = $cache->getValue();
        $blocked = $original;
        foreach ([
            (new ReflectionClass(ReleaseRemoverService::class))->getConstant('REMOVER_CDEF'),
            (new ReflectionClass(ReleasesNormalizeGuids::class))->getConstant('GUID_CDEF'),
            'int32_t nntmux_subject_candidate(const char *input, size_t input_len);',
        ] as $cdef) {
            $blocked['cleaning|default|'.hash('xxh3', $cdef)] = false;
        }
        $cache->setValue(null, $blocked);
        try {
            ReleaseRemoverService::useRustFfi(true);
            ReleasesNormalizeGuids::useRustFfi(true);
            ReleasesCleanSubjectNames::useRustFfi(true);
            $service = $this->remover();
            self::assertNull($service->cleanSpacesNative('a  b'));
            self::assertSame('a b', $this->invoke($service, 'cleanSpaces', 'a  b'));
            self::assertSame(' AND size > 10', $this->invoke($service, 'formatCriteriaQuery', 'size=bigger=10'));
            $command = new ReleasesNormalizeGuids;
            self::assertFalse($this->invoke($command, 'isUnsupportedGuid', 'legacy-guid'));
            self::assertSame([['unsupported' => false, 'hex' => false]], $command->classifyGuids(['legacy-guid']));
            $cleaner = $this->getMockBuilder(ReleaseCleaningService::class)->disableOriginalConstructor()->onlyMethods(['releaseCleaner'])->getMock();
            $cleaner->method('releaseCleaner')->willReturn(['cleansubject' => 'Movie.2026', 'predb' => 42]);
            self::assertSame(['Movie.2026', 42], $this->invoke(new ReleasesCleanSubjectNames, 'cleanName', $cleaner, (object) ['searchname' => 'raw', 'fromname' => '', 'group_name' => 'test']));
        } finally {
            $cache->setValue(null, $original);
        }
    }

    public function test_seeded_binary_whitespace_and_criteria_parity(): void
    {
        $service = $this->remover();
        mt_srand(1103);
        for ($i = 0; $i < 500; $i++) {
            $value = '';
            for ($j = mt_rand(0, 128); $j > 0; $j--) {
                $value .= chr(mt_rand(0, 255));
            }
            self::assertSame($this->invoke($service, 'cleanSpaces', $value), $service->cleanSpacesNative($value));
            self::assertSame($this->invoke($service, 'formatLike', $value, 'name'), $service->formatLikeNative($value, 'name'));
            $argument = 'totalpart=equals='.$value;
            ReleaseRemoverService::useRustFfi(false);
            $expected = $this->invoke($service, 'formatCriteriaQuery', $argument);
            ReleaseRemoverService::useRustFfi(true);
            self::assertSame($expected, $this->invoke($service, 'formatCriteriaQuery', $argument));
            ReleaseRemoverService::useRustFfi(false);
        }
    }

    /** Reproduce outside timed test runs: self::benchmark('/tmp/wave11-worker2-benchmark.json'). */
    public static function benchmark(string $outputPath): void
    {
        $service = (new ReflectionClass(ReleaseRemoverService::class))->newInstanceWithoutConstructor();
        $command = new ReleasesNormalizeGuids;
        ReleaseRemoverService::useRustFfi(false);
        ReleasesCleanSubjectNames::useRustFfi(false);
        ReleasesNormalizeGuids::useRustFfi(false);
        $common = ['Movie.2026.1080p.WEB-DL-GRP', 'Show S01E02', 'Artist Album FLAC'];
        $uncommon = [str_repeat('very long  title ', 300), "\0\tTitle\r\n\fwith\tspaces\0", "Café  quoted 'title' %_ "];
        $cases = [];
        foreach (['common' => $common, 'uncommon' => $uncommon] as $workload => $names) {
            foreach (['cleanSpaces', 'formatLike'] as $method) {
                $php = \Closure::bind(fn (string $name): string => $method === 'cleanSpaces' ? $this->cleanSpaces($name) : $this->formatLike($name, 'searchname'), $service, ReleaseRemoverService::class);
                $native = fn (string $name): ?string => $method === 'cleanSpaces' ? $service->cleanSpacesNative($name) : $service->formatLikeNative($name, 'searchname');
                $inputs = $method === 'cleanSpaces' ? ($workload === 'common' ? [' size ', "SELECT id, guid, searchname\n FROM releases WHERE 1=1  AND size > 1000000", "\tname  equals  Movie\t2026 "] : [str_repeat(" OR name  LIKE  '%Movie%'\n", 200), ...$uncommon]) : $names;
                $cases[$method.'/'.$workload] = [$inputs, $php, $native];
            }
            $subjectNames = $workload === 'common' ? [...$names, '[01/10] - movie.rar yEnc'] : [...$names, '', '[ 123 / 999 ] file.rar', '[1/x] Movie'];
            $cases['subjectCandidate/'.$workload] = [$subjectNames, \Closure::bind(static fn (string $name): bool => self::subjectCandidatePhp($name), null, ReleasesCleanSubjectNames::class), ReleasesCleanSubjectNames::subjectCandidateNative(...)];
            $guids = $workload === 'common' ? [str_repeat('a', 32), str_repeat('F', 40), 'a1b2c3d4-0000-1234-abcd-0123456789ab'] : ['', "bad\0guid", str_repeat('x', 500), 'legacy-name'];
            $cases['isUnsupportedGuid/'.$workload] = [$guids, \Closure::bind(fn (mixed $guid): bool => $this->isUnsupportedGuid($guid), $command, ReleasesNormalizeGuids::class), $command->isUnsupportedGuidNative(...)];
            $batch = array_map(static fn (int $i): mixed => $guids[$i % count($guids)], range(0, 999));
            $cases['classifyGuids/'.$workload] = [[$batch], $command->classifyGuids(...), $command->classifyGuidsNative(...)];
        }
        foreach (['common' => ['size=bigger=1000000', 'name=like=Movie 2026', 'imdbid=equals=NULL'], 'uncommon' => ['completion=smaller=1e999', ' size = smaller =  42 ', 'bad=equals=value', 'name=like='.str_repeat('term word ', 100)]] as $workload => $arguments) {
            $cases['formatCriteriaQuery/'.$workload] = [$arguments, \Closure::bind(fn (string $argument): bool|string => $this->formatCriteriaQuery($argument), $service, ReleaseRemoverService::class), $service->formatCriteriaQueryNative(...)];
        }
        foreach (['common' => [['size', 'bigger', '1000000'], ['totalpart', 'equals', '100'], ['imdbid', 'equals', 'NULL']], 'uncommon' => [['completion', 'smaller', '1e999'], ['postdate', 'bigger', '.25'], ['name', 'like', str_repeat('term word ', 100)], ['size', 'equals', 'invalid']]] as $workload => $arguments) {
            foreach (['buildCriteriaClause', 'buildNumericClause'] as $method) {
                $inputs = $method === 'buildNumericClause' ? ($workload === 'common' ? [['size', 'bigger', '1000000'], ['totalpart', 'equals', '200'], ['size', 'smaller', '50000000']] : [['totalpart', 'equals', '1e999'], ['size', 'invalid', '-1'], ['totalpart', 'equals', str_repeat('9', 1000)]]) : $arguments;
                $cases[$method.'/'.$workload] = [$inputs, \Closure::bind(fn (array $args): bool|string => $this->{$method}(...$args), $service, ReleaseRemoverService::class), fn (array $args): bool|string|null => $service->{$method.'Native'}(...$args)];
            }
            foreach (['buildImdbClause', 'buildCompletionClause'] as $method) {
                $values = $method === 'buildImdbClause' ? ($workload === 'common' ? [['equals', 'NULL'], ['equals', '1234567'], ['equals', 'tt1234567']] : [['smaller', '1234567'], ['equals', 'null'], ['equals', str_repeat('1', 1000)]]) : ($workload === 'common' ? [['smaller', '90'], ['smaller', '99.5'], ['smaller', '100']] : [['smaller', '1e9999'], ['smaller', 'bad'], ['equals', '99']]);
                $cases[$method.'/'.$workload] = [$values, \Closure::bind(fn (array $args): bool|string => $this->{$method}(...$args), $service, ReleaseRemoverService::class), fn (array $args): bool|string|null => $service->{$method.'Native'}(...$args)];
            }
            $values = $workload === 'common' ? [1, 42, '1200', ''] : ['1e3', '1e999', '9223372036854775808', ' 005 ', 'invalid'];
            $cases['formatBlacklistID/'.$workload] = [$values, \Closure::bind(fn (int|string $id): string => $this->formatBlacklistID($id), $service, ReleaseRemoverService::class), $service->formatBlacklistIDNative(...)];
        }
        $report = ['seed' => 1102, 'warmups' => 1024, 'samples_per_case' => 20480, 'protocol' => 'randomized paired single calls, hrtime(true), 5 blocks x 4096, nearest-rank p99; includes wrapper, serialization, FFI and result allocation; no result cache', 'php' => PHP_VERSION, 'os' => php_uname(), 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'), 'baseline_commit' => 'fead5ae34261e809689d58a71b3231eb8a6144cc', 'rustc' => '1.99.1 (58cf8f9c3 2026-10-13)', 'build' => 'cargo release opt-level=3, lto=true, codegen-units=1', 'hardware' => 'Darwin arm64 Mac17,3; 10 CPUs; 32 GiB RAM; no imposed resource caps; shared host', 'lc_ctype' => setlocale(LC_CTYPE, '0'), 'pcre_backtrack_limit' => ini_get('pcre.backtrack_limit'), 'pcre_recursion_limit' => ini_get('pcre.recursion_limit'), 'source_sha256' => [], 'results' => []];
        foreach (['app/Services/ReleaseRemoverService.php', 'app/Console/Commands/ReleasesCleanSubjectNames.php', 'app/Console/Commands/ReleasesNormalizeGuids.php', 'rust/pipeline-ffi/src/cleaning.rs', 'composer.lock', 'rust/pipeline-ffi/Cargo.lock', 'vendor/composer/installed.php', 'storage/app/nntmux-native/libnntmux_pipeline.dylib'] as $path) {
            $report['source_sha256'][$path] = hash_file('sha256', $path);
        }
        $method = new ReflectionMethod(self::class, 'benchmark');
        $report['benchmark_body_sha256'] = hash('sha256', implode('', array_slice(file(__FILE__), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1)));
        mt_srand(1102);
        $stats = static function (array $samples): array {
            sort($samples);

            return ['mean_ns' => array_sum($samples) / count($samples), 'p99_ns' => $samples[(int) ceil(count($samples) * 0.99) - 1]];
        };
        foreach ($cases as $key => [$inputs, $php, $native]) {
            foreach ($inputs as $input) {
                self::assertSame($php($input), $native($input), $key);
            }
            for ($i = 0; $i < 1024; $i++) {
                $php($inputs[$i % count($inputs)]);
                $native($inputs[$i % count($inputs)]);
            }
            $calls = [$php, $native];
            $samples = [[], []];
            $orders = $indices = [];
            for ($i = 0; $i < 20480; $i++) {
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
                $blocks[] = ['mean_speedup' => $bp['mean_ns'] / $br['mean_ns'], 'p99_speedup' => $bp['p99_ns'] / $br['p99_ns']];
            }
            $report['results'][$key] = ['php' => $p, 'rust' => $r, 'mean_speedup' => $p['mean_ns'] / $r['mean_ns'], 'p99_speedup' => $p['p99_ns'] / $r['p99_ns'], 'blocks' => $blocks, 'inputs' => $inputs, 'samples' => $samples, 'orders' => $orders, 'indices' => $indices];
            echo $key.' '.json_encode([$p['mean_ns'] / $r['mean_ns'], $p['p99_ns'] / $r['p99_ns']]).PHP_EOL;
        }
        file_put_contents($outputPath, json_encode($report, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function remover(): ReleaseRemoverService
    {
        return (new ReflectionClass(ReleaseRemoverService::class))->newInstanceWithoutConstructor();
    }

    private function invoke(object $object, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($object, $method))->invoke($object, ...$args);
    }
}
