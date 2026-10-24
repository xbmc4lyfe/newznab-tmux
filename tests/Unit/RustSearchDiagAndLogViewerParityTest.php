<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\NntmuxSearchDiag;
use App\Console\Commands\NntmuxSearchRepair;
use App\Enums\SecondarySearchIndex;
use App\Models\Release;
use App\Services\LogViewer\LogEntryParser;
use App\Services\LogViewer\LogFile;
use App\Services\LogViewer\LogFileRepository;
use App\Services\Search\Drivers\ManticoreSearchDriver;
use App\Services\Search\Support\ManticoreIndexRegistry;
use App\Support\ReleaseSearchIndexDocument;
use App\Support\RustPipelineLibrary;
use Carbon\Carbon;
use FFI;
use Illuminate\Console\OutputStyle;
use Manticoresearch\Client;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Source contract: fork master 1ffaec04df4002afdd48af5ca9524c3264619dde.
 * This source uses a timestamp backoff, reverse table-name lookup, CRC32 of all
 * four anime key fields, and one-decimal grouped byte sizes. These are preserved.
 *
 * Paired screen: Apple M5, PHP 8.5.12, rustc 1.99.1, release LTO,
 * CLI OPcache off; two repetitions of 10,000 single-call pairs per workload.
 * Conservative speedups (minimum across repetitions), common mean/p99;
 * uncommon mean/p99, including dispatch and serialization/file I/O:
 * classify:        0.208/0.160; 0.070/0.038
 * sqlRows:         0.146/0.117; 0.034/0.060
 * backoff:         0.930/0.949; 0.976/0.935
 * logicalName:     3.587/5.491; 1.633/0.966
 * animeId:         0.640/0.608; 0.325/0.387
 * formatBytes:     0.440/0.450; 0.448/0.600
 * normalize:       0.416/0.445; 1.301/1.375
 * looksStructured: 1.004/0.993; 6.670/6.470
 * No method qualifies on every metric; production defaults remain PHP.
 * Reproduce with NNTMUX_WAVE14_BENCHMARK=1; raw pairs/provenance are saved
 * outside the checkout and their artifact path is printed by the opt-in test.
 */
final class RustSearchDiagAndLogViewerParityTest extends TestCase
{
    public function test_native_helpers_match_existing_php_contracts(): void
    {
        $this->requireNative();
        $random = new Randomizer(new Mt19937(1401));
        $bytes = [PHP_INT_MIN, -1024, -1, 0, 1, 1023, 1024, 1536, 1024 ** 2, 1024 ** 4, PHP_INT_MAX];
        foreach ([1024, 1024 ** 2, 1024 ** 3, 1024 ** 4] as $unit) {
            foreach ([1.05, 1.15, 1.25, 1.95, 1023.95] as $value) {
                foreach ([-1, 0, 1] as $offset) {
                    $bytes[] = (int) ($value * $unit) + $offset;
                }
            }
        }
        for ($i = 0; $i < 300; $i++) {
            $bytes[] = $random->getInt(0, PHP_INT_MAX);
        }
        foreach ($bytes as $value) {
            self::assertSame(LogFile::formatBytesPhp($value), LogFile::formatBytesNative($value), (string) $value);
            foreach ([false, true] as $enabled) {
                self::assertSame(LogFile::formatBytesPhp($value), LogFile::formatBytes($value, $enabled));
            }
        }

        $repo = new LogFileRepository(new LogEntryParser, sys_get_temp_dir());
        foreach (['', '/', ' /a\\b.log ', "\0\t /x\r\n", '///a//../b', "\x0c\\raw\xff.log\x0b", '日志/é.log'] as $path) {
            self::assertSame($repo->normalizePhp($path), $repo->normalizeNative($path));
        }
        foreach (['', 'foo', 'releases', 'tv_rt', 'tvshows_rt', 'anime_rt', 'releases_rt', 'movies_rt', 'unknown_rt'] as $table) {
            foreach ([[], ['custom' => $table], ['first' => $table, 'second' => $table]] as $configured) {
                self::assertSame(ManticoreIndexRegistry::logicalNamePhp($table, $configured), ManticoreIndexRegistry::logicalNameNative($table, $configured));
            }
        }
        foreach ([PHP_INT_MIN, -10, 0, 1, 5, 10, 11, PHP_INT_MAX] as $attempt) {
            self::assertSame(NntmuxSearchRepair::backoffDelayPhp($attempt), NntmuxSearchRepair::backoffDelayNative($attempt));
        }
        for ($i = 0; $i < 200; $i++) {
            $args = [$random->getInt(-10, PHP_INT_MAX), ['main', 'short', 'official'][$i % 3], ['en', 'ja', 'x|y'][$i % 3], ['Cowboy Bebop', '進撃の巨人', "binary\xff\0title", str_repeat('long|title', 100)][$i % 4]];
            self::assertSame(SecondarySearchIndex::animeTitleDocumentIdPhp(...$args), SecondarySearchIndex::animeTitleDocumentIdNative(...$args));
        }
    }

    public function test_classification_preserves_status_precedence_fields_and_timestamp_tolerance(): void
    {
        $this->requireNative();
        $diag = new NntmuxSearchDiag;
        $expected = ['searchname' => 'A.Title', 'name' => 'A', 'categories_id' => 5030, 'passwordstatus' => 0, 'size' => PHP_INT_MAX, 'groups_id' => 1, 'nzbstatus' => 1, 'postdate_ts' => 1000, 'adddate_ts' => 2000];
        $cases = [[$expected, null, false], [$expected, $expected, false]];
        foreach (array_keys($expected) as $field) {
            $row = $expected;
            $row[$field] = $field === 'name' ? 'changed' : 42;
            $cases[] = [$expected, $row, false];
        }
        foreach ([-3, -2, 2, 3] as $offset) {
            $cases[] = [$expected, [...$expected, 'postdate_ts' => 1000 + $offset], false];
        }
        foreach ([false, true] as $showPasswords) {
            $hidden = [...$expected, 'passwordstatus' => 1];
            $cases[] = [$hidden, $hidden, $showPasswords];
            $cases[] = [$hidden, [...$hidden, 'name' => 'stale'], $showPasswords];
        }
        $random = new Randomizer(new Mt19937(1402));
        $values = [null, '', false, true, 0, -1, PHP_INT_MAX, 1.5, '0', '5030', '日本語😀'];
        for ($i = 0; $i < 300; $i++) {
            $db = $row = [];
            foreach (array_keys($expected) as $field) {
                if (str_ends_with($field, '_ts')) {
                    $db[$field] = $random->getInt(PHP_INT_MIN, PHP_INT_MAX);
                    $row[$field] = $i % 2 === 0 ? $db[$field] : $random->getInt(PHP_INT_MIN, PHP_INT_MAX);
                } else {
                    $db[$field] = $values[$random->getInt(0, count($values) - 1)];
                    $row[$field] = $i % 2 === 0 ? $db[$field] : $values[$random->getInt(0, count($values) - 1)];
                }
            }
            $cases[] = [$db, $row, $i % 3 === 0];
        }
        $cases[] = [[], [], false];
        $cases[] = [['name' => false], ['name' => null], false];
        $cases[] = [['name' => "日本語\0😀"], ['name' => "日本語\0😀"], false];
        foreach ($cases as [$db, $row, $show]) {
            self::assertSame($diag->classifyPhp($db, $row, $show), $diag->classifyNative($db, $row, $show));
        }
        self::assertSame(['OK', []], $diag->classifyPhp($expected, $expected, false));
        self::assertSame(['STALE_INDEX', ['postdate_ts']], $diag->classifyPhp($expected, [...$expected, 'postdate_ts' => 1003], false));
    }

    public function test_sql_response_shapes_keep_keyed_row_reconstruction(): void
    {
        $this->requireNative();
        $diag = new NntmuxSearchDiag;
        foreach ([null, false, 'error', [], [42 => ['name' => 'hello', 'ratio' => 1.0, 'zero' => -0.0, 'flag' => false, 'nil' => null]], [42 => 42], [0 => ['id' => 11, 'size' => 0]], ['data' => [['id' => 3]]], [['data' => [['id' => 3]]]], ['data' => ['x' => ['id' => 5]]], ['error' => 'bad'], ['001' => ['id' => null], -9 => ['name' => 'negative']], [4 => ['name' => '😀', 'meta' => ['a', 'b']]], [7 => ['id' => null, 'after' => 7], 2 => ['id' => 99]], ['000' => [], '18446744073709551616' => ['x' => 1]], [4 => ['\"' => 'escaped', 'foo' => [1.0, 2, true, null]]]] as $response) {
            self::assertSame($diag->manticoreSqlRowsPhp($response), $diag->manticoreSqlRowsNative($response));
        }
    }

    public function test_structured_sniff_uses_the_real_file_and_8192_byte_boundary(): void
    {
        $this->requireNative();
        $path = tempnam(sys_get_temp_dir(), 'wave14-log-');
        self::assertNotFalse($path);
        $repo = new LogFileRepository(new LogEntryParser, sys_get_temp_dir());
        try {
            foreach (['', 'console output', '[2026-10-24 10:00:00.'.str_repeat('1', 300).'] local.INFO: long fraction', "console\n[2026-10-24 12:01:02] local.INFO: hello\r\ntrace", "[2026-10-24T12:01:02.123+0200] a.b-c.UNKNOWN:\0", "[2026-10-24 12:01:02] local\v.INFO: no", ' [2026-10-24 12:01:02] local.INFO: no', '[2026-10-24 12:01:02] local.info: no', str_repeat('x', 8192)."\n[2026-10-24 12:01:02] local.INFO: outside", str_repeat("x\n", 4000).'[2026-10-24 12:01:02Z] local.ERROR: inside'] as $sample) {
                file_put_contents($path, $sample);
                self::assertSame($repo->looksStructuredPhp($path), $repo->looksStructuredNative($path));
            }
        } finally {
            unlink($path);
        }
        self::assertFalse($repo->looksStructuredPhp($path));
        self::assertFalse($repo->looksStructuredNative($path));
    }

    public function test_wrappers_preserve_php_behavior_for_both_toggles(): void
    {
        $this->requireNative();
        $diag = new NntmuxSearchDiag;
        $repair = new NntmuxSearchRepair;
        $repo = new LogFileRepository(new LogEntryParser, sys_get_temp_dir());
        $normalize = new ReflectionMethod($repo, 'normalize');
        $backoff = new ReflectionMethod($repair, 'backoff');
        Carbon::setTestNow(Carbon::parse('2026-10-24 10:00:00.123456', 'UTC'));
        try {
            foreach ([false, true] as $enabled) {
                self::assertSame(['OK', []], $diag->classifyFields([], [], false, $enabled));
                self::assertSame([['name' => 'test', 'id' => 42]], $diag->extractSqlRows([42 => ['name' => 'test']], $enabled));
                self::assertSame('a/b.log', $normalize->invoke($repo, ' /a\\b.log ', $enabled));
                foreach ([-3, 0, 1, 10, 1000] as $attempt) {
                    self::assertEquals($repair->backoffPhp($attempt), $backoff->invoke($repair, $attempt, $enabled));
                    self::assertEquals($repair->backoffPhp($attempt), $repair->backoffNative($attempt));
                }
                foreach (['anime_rt', 'tvshows_rt', 'unknown_rt'] as $table) {
                    self::assertSame(ManticoreIndexRegistry::logicalNamePhp($table), ManticoreIndexRegistry::logicalName($table, [], $enabled));
                }
                self::assertSame(SecondarySearchIndex::animeTitleDocumentIdPhp(42, 'main', 'ja', '日本語'), SecondarySearchIndex::animeTitleDocumentId(42, 'main', 'ja', '日本語', $enabled));
            }
            $object = (object) ['field' => 'preserve identity'];
            self::assertNull($diag->manticoreSqlRowsNative([42 => ['value' => $object]]));
            self::assertSame([['value' => $object, 'id' => 42]], $diag->extractSqlRows([42 => ['value' => $object]], true));
            self::assertNull($diag->manticoreSqlRowsNative([42 => ['name' => "invalid\xff"]]));
            self::assertSame([['name' => "invalid\xff", 'id' => 42]], $diag->extractSqlRows([42 => ['name' => "invalid\xff"]], true));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_real_diagnostic_command_keeps_verbose_output_and_sql_row_handling(): void
    {
        $diag = new NntmuxSearchDiag;
        $output = new BufferedOutput;
        $diag->setOutput(new OutputStyle(new ArrayInput([]), $output));
        $release = new Release;
        $release->setRawAttributes(['id' => 42, 'name' => 'Title', 'searchname' => 'Title.2026', 'passwordstatus' => 1, 'size' => 1234]);
        $expected = ReleaseSearchIndexDocument::normalize($release->toArray());
        $classify = new ReflectionMethod($diag, 'classify');
        self::assertSame('MISSING_IN_INDEX', $classify->invoke($diag, $release, null, false, true));
        self::assertSame('STATUS_HIDDEN', $classify->invoke($diag, $release, $expected, false, true));
        self::assertSame('OK', $classify->invoke($diag, $release, $expected, true, true));
        self::assertSame('', $output->fetch());
        self::assertSame('STALE_INDEX', $classify->invoke($diag, $release, [...$expected, 'name' => 'Old', 'postdate_ts' => 100], false, true));
        self::assertSame("  drift: name db=\"Title\" ix=\"Old\"\n", $output->fetch());
        $client = new class extends Client
        {
            public function __construct() {}

            /** @return array<int, array{name: string}> */
            public function sql(mixed ...$params): mixed
            {
                return [42 => ['name' => 'Title']];
            }
        };
        $driver = (new ReflectionClass(ManticoreSearchDriver::class))->newInstanceWithoutConstructor();
        $driver->manticoreSearch = $client;
        $rows = new ReflectionMethod($diag, 'manticoreSqlRows');
        self::assertSame([['name' => 'Title', 'id' => 42]], $rows->invoke($diag, $driver, 'SELECT * FROM releases_rt WHERE id=42'));
    }

    public function test_native_unavailability_falls_back_without_changing_results(): void
    {
        $code = <<<'PHP'
            require $argv[1];
            $diag = new \App\Console\Commands\NntmuxSearchDiag;
            $repo = new \App\Services\LogViewer\LogFileRepository(new \App\Services\LogViewer\LogEntryParser, sys_get_temp_dir());
            $normalize = new \ReflectionMethod($repo, 'normalize');
            echo json_encode([
                \App\Services\LogViewer\LogFile::formatBytes(1536, true),
                \App\Services\Search\Support\ManticoreIndexRegistry::logicalName('releases_rt', [], true),
                \App\Enums\SecondarySearchIndex::animeTitleDocumentId(42, 'main', 'en', 'Title', true) === \App\Enums\SecondarySearchIndex::animeTitleDocumentIdPhp(42, 'main', 'en', 'Title'),
                $diag->classifyFields([], [], false, true),
                $diag->extractSqlRows([42 => ['name' => 'a']], true),
                $normalize->invoke($repo, ' /a/b ', true),
                \App\Services\LogViewer\LogFile::formatBytesNative(1536),
            ], JSON_THROW_ON_ERROR);
            PHP;
        $process = proc_open([PHP_BINARY, '-d', 'ffi.enable=0', '-r', $code, __DIR__.'/../bootstrap.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);
        self::assertSame(['1.5 KB', 'releases', true, ['OK', []], [['name' => 'a', 'id' => 42]], 'a/b', null], json_decode((string) $output, true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * Opt in: NNTMUX_WAVE14_BENCHMARK=1 php artisan test --compact tests/Unit/RustSearchDiagAndLogViewerParityTest.php
     * Two repetitions, each with 500 warmups and 10,000 randomized single-call pairs.
     * Includes PHP dispatch, JSON, FFI, allocation, and real file open/read/close costs.
     * Every raw pair, selected fixture/order, source/runtime identity and p99 is saved
     * to an owned temporary artifact. No production endpoints are accessed.
     */
    public function test_paired_randomized_benchmarks(): void
    {
        if (getenv('NNTMUX_WAVE14_BENCHMARK') !== '1') {
            self::markTestSkipped('Opt-in paired benchmark.');
        }
        $this->requireNative();
        $random = new Randomizer(new Mt19937(1401));
        $diag = new NntmuxSearchDiag;
        $repair = new NntmuxSearchRepair;
        $repo = new LogFileRepository(new LogEntryParser, sys_get_temp_dir());
        $normalize = new ReflectionMethod($repo, 'normalize');
        $structured = new ReflectionMethod($repo, 'looksStructured');
        $backoff = new ReflectionMethod($repair, 'backoff');
        $base = ['searchname' => 'A.Title.2026', 'name' => 'A', 'categories_id' => 5030, 'passwordstatus' => 0, 'size' => 1234567890, 'groups_id' => 1, 'nzbstatus' => 1, 'postdate_ts' => 1700000000, 'adddate_ts' => 1700000100];
        $workloads = [];
        $files = [];
        $artifact = ['protocol' => 'wave14-v1', 'seed' => 1401, 'repetitions' => 2, 'warmups' => 500, 'pairs_per_repetition' => 10000, 'p99' => 'nearest-rank single-call latency', 'profile' => 'cargo release opt-level=3,lto=true,codegen-units=1', 'php' => PHP_VERSION, 'host' => php_uname(), 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'), 'head' => trim((string) shell_exec('git rev-parse HEAD')), 'rustc' => trim((string) shell_exec('rustc --version')), 'cpu' => trim((string) shell_exec(PHP_OS_FAMILY === 'Darwin' ? 'sysctl -n machdep.cpu.brand_string' : 'uname -m')), 'sources' => [], 'results' => [], 'failures' => []];
        foreach (['app/Console/Commands/NntmuxSearchDiag.php', 'app/Console/Commands/NntmuxSearchRepair.php', 'app/Enums/SecondarySearchIndex.php', 'app/Services/Search/Support/ManticoreIndexRegistry.php', 'app/Services/LogViewer/LogFile.php', 'app/Services/LogViewer/LogFileRepository.php', 'tests/Unit/RustSearchDiagAndLogViewerParityTest.php', 'rust/pipeline-ffi/src/search.rs', 'composer.lock', 'rust/pipeline-ffi/Cargo.lock'] as $source) {
            $artifact['sources'][$source] = hash_file('sha256', dirname(__DIR__, 2).'/'.$source);
        }
        $suffix = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
        foreach (['storage/app/nntmux-native', 'rust/pipeline-ffi/target/release', '/usr/local/lib/nntmux'] as $directory) {
            $library = str_starts_with($directory, '/') ? $directory.'/libnntmux_pipeline.'.$suffix : dirname(__DIR__, 2).'/'.$directory.'/libnntmux_pipeline.'.$suffix;
            if (is_file($library)) {
                $artifact['sources'][$library] = hash_file('sha256', $library);
            }
        }
        $artifactPath = tempnam(sys_get_temp_dir(), 'nntmux-wave14-');
        self::assertNotFalse($artifactPath);
        try {
            foreach (['common', 'uncommon'] as $kind) {
                $rare = $kind === 'uncommon';
                $classifyInputs = $rare
                    ? [[$base, null, false], [$base, [...$base, 'name' => str_repeat('長い😀', 300), 'postdate_ts' => 0], false], [[...$base, 'passwordstatus' => 1], [...$base, 'passwordstatus' => 1], false]]
                    : [[$base, $base, false], [$base, [...$base, 'size' => 99], false], [$base, [...$base, 'postdate_ts' => $base['postdate_ts'] + 2], false]];
                $sqlInputs = $rare
                    ? [['data' => array_map(static fn (int $id): array => ['id' => $id, 'name' => 'Title '.$id, 'size' => 1234567890], range(1, 500))], array_fill_keys(range(2000, 2499), ['name' => str_repeat('x', 80), 'size' => 42]), [['data' => [['id' => 5]]]]]
                    : [[42 => ['name' => 'Title', 'size' => 42]], [42 => 42, 43 => 43], ['data' => [['id' => 3]]]];
                $attempts = $rare ? [10, 11, 1000, PHP_INT_MAX] : [1, 2, 3, 5];
                $tables = $rare ? ['anime_rt', 'tvshows_rt', 'unknown_rt'] : ['releases_rt', 'predb_rt', 'movies_rt'];
                $titles = $rare ? [[PHP_INT_MAX, 'x|y', 'ja', str_repeat('長い😀|title', 200)], [-1, '', '', str_repeat('binary', 500)]] : [[42, 'main', 'en', 'Cowboy Bebop'], [123, 'short', 'ja', '進撃の巨人']];
                $sizes = $rare ? [PHP_INT_MIN, PHP_INT_MAX, 1024 ** 4, 1024 ** 4 * 1024] : [0, 1023, 1536, 1234567];
                $paths = $rare ? ["\0 /".str_repeat('sub\\dir/', 100).'../log.log', str_repeat('/', 1000).'log.log'] : ['laravel.log', ' /sub\\log.log ', '/logs/app.log'];
                $samples = $rare
                    ? [str_repeat("console output\n", 540)."[2026-10-24 10:00:00] local.ERROR: tail\n", str_repeat("x\n", 4096)]
                    : ["[2026-10-24 10:00:00] local.INFO: hello\ntrace\n", "console output\n"];
                $fileInputs = [];
                foreach ($samples as $sample) {
                    $path = tempnam(sys_get_temp_dir(), 'wave14-sniff-');
                    self::assertNotFalse($path);
                    $files[] = $path;
                    file_put_contents($path, $sample);
                    $fileInputs[] = $path;
                }
                $artifact['fixtures'][$kind] = ['classify' => $classifyInputs, 'sqlRows' => $sqlInputs, 'backoff' => $attempts, 'logicalName' => $tables, 'animeId' => $titles, 'formatBytes' => $sizes, 'normalize' => $paths, 'looksStructured' => $samples];
                $workloads['classify/'.$kind] = [$classifyInputs, static fn ($args) => $diag->classifyFields(...[...$args, false]), static fn ($args) => $diag->classifyFields(...[...$args, true])];
                $workloads['sqlRows/'.$kind] = [$sqlInputs, static fn ($input) => $diag->extractSqlRows($input, false), static fn ($input) => $diag->extractSqlRows($input, true)];
                $workloads['backoff/'.$kind] = [$attempts, static fn ($input) => $backoff->invoke($repair, $input, false), static fn ($input) => $backoff->invoke($repair, $input, true)];
                $workloads['logicalName/'.$kind] = [$tables, static fn ($input) => ManticoreIndexRegistry::logicalName($input, [], false), static fn ($input) => ManticoreIndexRegistry::logicalName($input, [], true)];
                $workloads['animeId/'.$kind] = [$titles, static fn ($args) => SecondarySearchIndex::animeTitleDocumentId(...[...$args, false]), static fn ($args) => SecondarySearchIndex::animeTitleDocumentId(...[...$args, true])];
                $workloads['formatBytes/'.$kind] = [$sizes, static fn ($input) => LogFile::formatBytes($input, false), static fn ($input) => LogFile::formatBytes($input, true)];
                $workloads['normalize/'.$kind] = [$paths, static fn ($input) => $normalize->invoke($repo, $input, false), static fn ($input) => $normalize->invoke($repo, $input, true)];
                $workloads['looksStructured/'.$kind] = [$fileInputs, static fn ($input) => $structured->invoke($repo, $input, false), static fn ($input) => $structured->invoke($repo, $input, true)];
            }
            Carbon::setTestNow(Carbon::parse('2026-10-24 10:00:00', 'UTC'));
            for ($repeat = 0; $repeat < 2; $repeat++) {
                foreach ($random->shuffleArray(array_keys($workloads)) as $name) {
                    [$inputs, $php, $rust] = $workloads[$name];
                    foreach ($inputs as $input) {
                        self::assertEquals($php($input), $rust($input), $name);
                    }
                    for ($i = 0; $i < 500; $i++) {
                        $input = $inputs[$i % count($inputs)];
                        $php($input);
                        $rust($input);
                    }
                    $pairs = [];
                    for ($i = 0; $i < 10000; $i++) {
                        $fixture = $random->getInt(0, count($inputs) - 1);
                        $input = $inputs[$fixture];
                        $first = $random->getInt(0, 1);
                        $pair = [];
                        foreach ([$first, 1 - $first] as $side) {
                            $start = hrtime(true);
                            ($side === 0 ? $php : $rust)($input);
                            $pair[$side] = hrtime(true) - $start;
                        }
                        $pairs[] = [$pair[0], $pair[1], $fixture, $first];
                    }
                    $p = array_column($pairs, 0);
                    $r = array_column($pairs, 1);
                    sort($p);
                    sort($r);
                    $result = ['repeat' => $repeat + 1, 'php_mean_ns' => array_sum($p) / count($p), 'rust_mean_ns' => array_sum($r) / count($r), 'php_p99_ns' => $p[9899], 'rust_p99_ns' => $r[9899], 'mean_speedup' => array_sum($p) / array_sum($r), 'p99_speedup' => $p[9899] / $r[9899], 'pairs_ns' => $pairs];
                    $artifact['results'][$name][] = $result;
                    fwrite(STDERR, sprintf("%s repeat %d mean %.3fx p99 %.3fx\n", $name, $repeat + 1, $result['mean_speedup'], $result['p99_speedup']));
                }
            }
            // Qualification is conservative: every metric in both repetitions must pass.
            foreach (['classify', 'sqlRows', 'backoff', 'logicalName', 'animeId', 'formatBytes', 'normalize', 'looksStructured'] as $method) {
                $ratios = [];
                foreach (['common', 'uncommon'] as $kind) {
                    foreach ($artifact['results'][$method.'/'.$kind] as $result) {
                        $ratios[] = $result['mean_speedup'];
                        $ratios[] = $result['p99_speedup'];
                    }
                }
                $artifact['qualified'][$method] = min($ratios) >= 1.5;
            }
        } catch (\Throwable $error) {
            $artifact['failures'][] = $error->getMessage();
            throw $error;
        } finally {
            Carbon::setTestNow();
            foreach ($files as $path) {
                unlink($path);
            }
            file_put_contents($artifactPath, json_encode($artifact, JSON_THROW_ON_ERROR));
            fwrite(STDERR, 'Raw benchmark artifact: '.$artifactPath.PHP_EOL);
        }
    }

    private function requireNative(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('search', 'double nntmux_search_repair_backoff(int64_t attempts);');
        self::assertNotNull($ffi, 'Build the release Rust library before running parity checks.');

        return $ffi;
    }
}
