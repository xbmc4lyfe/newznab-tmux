<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\BookService;
use App\Services\ConsoleService;
use App\Services\GamesService;
use App\Services\MovieService;
use App\Services\MusicService;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Wave 40.4 pure CPU summaries. The opt-in benchmark retains raw randomized
 * pairs, input/source/binary hashes, warmups and p99 rank uncertainty.
 * Reproduce: NNTMUX_W404_BENCH=1 php vendor/bin/phpunit tests/Unit/RustMovieMusicBookConsoleGamesServiceW40ParityTest.php
 */
final class RustMovieMusicBookConsoleGamesServiceW40ParityTest extends TestCase
{
    private const array HELPERS = [
        [MovieService::class, 'summarizeMovieMetadataCompleteness', 'nntmux_w404_movie_metadata_completeness'],
        [MusicService::class, 'summarizeMusicReleaseLookupBatch', 'nntmux_w404_music_release_lookup_batch'],
        [BookService::class, 'summarizeBookLookupBatch', 'nntmux_w404_book_lookup_batch'],
        [ConsoleService::class, 'summarizeConsolePlatformBatch', 'nntmux_w404_console_platform_batch'],
        [GamesService::class, 'summarizePcGameLookupBatch', 'nntmux_w404_pc_game_lookup_batch'],
    ];

    private const array DEFAULTS = [false, false, false, true, true];

    protected function tearDown(): void
    {
        foreach (self::HELPERS as [$class]) {
            if (method_exists($class, 'resetW404RustFfi')) {
                $class::resetW404RustFfi();
            }
        }
        parent::tearDown();
    }

    public function test_required_additive_api_exists(): void
    {
        foreach (self::HELPERS as $index => [$class, $method]) {
            foreach ([$method, $method.'Php', 'useW404RustFfi', 'usesW404RustFfi', 'resetW404RustFfi'] as $name) {
                self::assertTrue(method_exists($class, $name), $class.'::'.$name);
            }
            self::assertSame(self::DEFAULTS[$index], (new \ReflectionClass($class))->getConstant('W404_RUST_FFI_DEFAULT'));
        }
    }

    /** @return list<array{array<array-key, mixed>, array<string, int>}> */
    private static function literalCases(): array
    {
        return [
            [[['imdbid' => 'tt1234567', 'tmdbid' => 123, 'cover' => 1, 'year' => 2024], ['imdbid' => '0000000', 'tmdbid' => '0', 'cover' => '0', 'year' => '0000'], null, ['imdbid' => " \0tt01234567\t", 'tmdbid' => '00001', 'cover' => 2, 'year' => 2999]], ['total_rows' => 4, 'valid_imdb_id_count' => 2, 'has_tmdb_id_count' => 2, 'has_cover_count' => 2, 'missing_year_count' => 2, 'complete_record_count' => 2]],
            [[['searchname' => 'Artist-Album.FLAC.2024', 'year' => ''], ['title' => 'Singer - Record.MP3.320'], ['title' => 'FLACish.A192.12024', 'year' => 1999], null, ['title' => '-Only.FLAC']], ['total_candidates' => 5, 'lossless_flac_count' => 2, 'mp3_bitrate_count' => 1, 'has_year_count' => 2, 'artist_album_splitable_count' => 2]],
            [[['isbn' => '978-0-306-40615-7', 'author' => 'A', 'publishdate' => '2001', 'format' => 'epub'], ['isbn' => '0-8044-2957-x', 'author' => "\0\t", 'publish_date' => 'unknown', 'format' => 'PDF'], ['isbn' => '9780306406158', 'author' => ['x'], 'format' => 'epubx'], null], ['total_rows' => 4, 'valid_isbn_count' => 2, 'has_author_count' => 1, 'has_publish_date_count' => 2, 'ebook_format_count' => 2]],
            [['Mario.NDS.USA', 'Game.PS5.EUR', 'Game.XBOX.ONE', 'Old.SNES.JPN', 'Game.PSP.PAL', 'Unspecified', 'Game.PS50', null], ['total_titles' => 8, 'nintendo_count' => 2, 'playstation_count' => 2, 'xbox_count' => 1, 'handheld_count' => 2, 'retro_count' => 1, 'region_tagged_count' => 4, 'unknown_platform_count' => 3]],
            [[['searchname' => 'Example.Game-RUNE'], ['title' => 'Game.Update.v2-CODEX'], ['title' => 'Game.GOG.Repack'], ['title' => 'Game.Steam.DLC-TENOKE'], ['title' => 'Updated.Game-RUNE.more'], null], ['total_entries' => 6, 'scene_group_tagged_count' => 3, 'update_patch_dlc_count' => 2, 'repack_gog_steam_count' => 2, 'eligible_lookup_count' => 3]],
        ];
    }

    public function test_hand_calculated_contracts_on_both_dispatches(): void
    {
        foreach (self::literalCases() as $i => [$input, $expected]) {
            [$class, $method] = self::HELPERS[$i];
            self::assertSame($expected, $class::{$method.'Php'}($input));
            foreach ([false, true] as $enabled) {
                $class::useW404RustFfi($enabled);
                self::assertSame($expected, $class::{$method}($input));
                $empty = array_fill_keys(array_keys($expected), 0);
                self::assertSame($empty, $class::{$method}([]));
            }
        }
        self::assertSame(MovieService::summarizeMovieMetadataCompletenessPhp([]), MovieService::summarizeMovieMetadataCompleteness(movies: []));
        self::assertSame(MusicService::summarizeMusicReleaseLookupBatchPhp([]), MusicService::summarizeMusicReleaseLookupBatch(releases: []));
        self::assertSame(BookService::summarizeBookLookupBatchPhp([]), BookService::summarizeBookLookupBatch(books: []));
        self::assertSame(ConsoleService::summarizeConsolePlatformBatchPhp([]), ConsoleService::summarizeConsolePlatformBatch(titles: []));
        self::assertSame(GamesService::summarizePcGameLookupBatchPhp([]), GamesService::summarizePcGameLookupBatch(entries: []));
    }

    public function test_switches_are_independent_and_missing_library_falls_back(): void
    {
        foreach (self::HELPERS as $index => [$class]) {
            $class::resetW404RustFfi();
            self::assertSame(self::DEFAULTS[$index], $class::usesW404RustFfi());
            $class::useW404RustFfi(false);
        }
        foreach (self::HELPERS as $index => [$class]) {
            $class::useW404RustFfi(true);
            foreach (self::HELPERS as [$other]) {
                self::assertSame($class === $other, $other::usesW404RustFfi());
            }
            $class::useW404RustFfi(null);
            self::assertSame(self::DEFAULTS[$index], $class::usesW404RustFfi());
            $class::useW404RustFfi(false);
        }
        foreach (self::HELPERS as $i => [$class, $method]) {
            $class::useW404RustFfi(true);
            (new ReflectionProperty($class, 'w404Ffi'))->setValue(null, false);
            [$input, $expected] = self::literalCases()[$i];
            self::assertSame($expected, $class::{$method}($input));
        }
    }

    private static function ffi(string $class): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('metadata_w404', $class::W404_FFI_CDEF);
        self::assertNotNull($ffi, 'Build the release pipeline library with all five W404 symbols.');

        return $ffi;
    }

    /** @param array<array-key, mixed> $input
     * @return array<string, int>
     */
    private static function rawResult(string $class, FFI $ffi, string $symbol, array $input): array
    {
        $rows = (new ReflectionMethod($class, 'w404Rows'))->invoke(null, $input);
        $wire = serialize([$rows]);
        $out = $ffi->new('char[2048]');
        $written = $ffi->{$symbol}($wire, strlen($wire), $out, 2048);
        self::assertGreaterThan(0, $written, 'Native export must execute, not silently fall back.');
        self::assertLessThanOrEqual(2048, $written);
        $result = unserialize(FFI::string($out, $written), ['allowed_classes' => false]);
        self::assertIsArray($result);

        return $result;
    }

    /** @param array<array-key, mixed> $input
     * @param  array<string, int>  $expected
     */
    private static function assertNativeDispatch(string $class, string $method, FFI $ffi, array $input, array $expected): void
    {
        $capacity = 0;
        $out = RustPipelineLibrary::obtainScratch($ffi, 2048, $capacity);
        self::assertNotNull($out);
        FFI::memset($out, 90, 2048);
        $class::useW404RustFfi(true);
        self::assertSame($expected, $class::{$method}($input));
        $wire = serialize($expected);
        self::assertSame($wire, FFI::string($out, strlen($wire)), 'Public dispatch must write the Rust output, rather than fall back.');
        self::assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'w404Ffi'))->getValue());
    }

    public function test_raw_native_and_real_dispatch_randomized_parity(): void
    {
        $random = new Randomizer(new Mt19937(4042026));
        $values = ['', ' ', "\0\t\v", "\f", "\xff", '日本語', 2024, PHP_INT_MAX, -1, null, true, false, [], 1.5, 'tt1234567', 'tt0000000', '9780306406157', '080442957x', '9780306406158', '0', ' Artist-Album.FLAC.2024 ', 'Game.PS5.USA-RUNE', 'Game.DLC-GOG-GAMES', 'Game.PATCH-I_kNoW', 'Game.XUPDATE.DLCS-RUNE.extra', 'EPUB', 'GOG', '320', '20001', '999', '1000', '2999', '3000'];
        foreach (self::HELPERS as $i => [$class, $method, $symbol]) {
            $ffi = self::ffi($class);
            $cases = [[], self::literalCases()[$i][0]];
            for ($trial = 0; $trial < 250; $trial++) {
                $rows = [];
                for ($j = 0, $n = $random->getInt(0, 24); $j < $n; $j++) {
                    $row = [];
                    foreach (['imdbid', 'tmdbid', 'cover', 'year', 'searchname', 'title', 'isbn', 'author', 'publishdate', 'publish_date', 'format'] as $key) {
                        $row[$key] = $values[$random->getInt(0, count($values) - 1)];
                    }
                    $rows['key'.$j] = $i === 3 || $j % 7 === 0 ? $values[$random->getInt(0, count($values) - 1)] : $row;
                }
                $cases[] = $rows;
            }
            foreach ($cases as $input) {
                $expected = $class::{$method.'Php'}($input);
                self::assertSame($expected, self::rawResult($class, $ffi, $symbol, $input));
                $class::useW404RustFfi(false);
                self::assertSame($expected, $class::{$method}($input));
                self::assertNativeDispatch($class, $method, $ffi, $input, $expected);
            }
        }
    }

    public function test_benchmark_qualified_defaults_dispatch_without_overrides(): void
    {
        foreach (self::HELPERS as $i => [$class, $method]) {
            $class::resetW404RustFfi();
            self::assertSame(self::DEFAULTS[$i], $class::usesW404RustFfi());
            $ffi = self::ffi($class);
            $capacity = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, 2048, $capacity);
            self::assertNotNull($out);
            FFI::memset($out, 90, 2048);
            [$input, $expected] = self::literalCases()[$i];
            self::assertSame($expected, $class::{$method}($input));
            if (self::DEFAULTS[$i]) {
                $wire = serialize($expected);
                self::assertSame($wire, FFI::string($out, strlen($wire)));
                self::assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'w404Ffi'))->getValue());
            } else {
                self::assertNull((new ReflectionProperty($class, 'w404Ffi'))->getValue());
                self::assertSame(str_repeat('Z', 2048), FFI::string($out, 2048));
            }
        }
    }

    public function test_objects_are_not_serialized_and_oversized_inputs_fall_back(): void
    {
        $object = new class
        {
            public function __serialize(): array
            {
                throw new \LogicException('Caller-owned objects must not be serialized.');
            }

            public function __toString(): string
            {
                throw new \LogicException('Caller-owned objects must not be cast.');
            }
        };
        $large = str_repeat('x', 16777217);
        foreach (self::HELPERS as $i => [$class, $method]) {
            $input = $i === 3 ? [$object, $object] : [$object, array_fill_keys(['imdbid', 'year', 'title', 'isbn', 'author', 'format', 'unused'], $object)];
            $class::useW404RustFfi(true);
            self::assertSame($class::{$method.'Php'}($input), $class::{$method}($input));
            $class::resetW404RustFfi();
            $class::useW404RustFfi(true);
            $oversized = $i === 3 ? [$large] : [array_fill_keys(['imdbid', 'title', 'isbn'], $large)];
            self::assertSame($class::{$method.'Php'}($oversized), $class::{$method}($oversized));
            self::assertNull((new ReflectionProperty($class, 'w404Ffi'))->getValue(), 'Size guard must run before resolving FFI.');
        }
    }

    /** @param list<int> $samples
     * @return array{mean_ns: float, p99_ns: int, p99_approx_95pct_rank_interval_ns: array{int,int}}
     */
    private static function statistics(array $samples): array
    {
        sort($samples, SORT_NUMERIC);
        $n = count($samples);
        $radius = 1.96 * sqrt($n * 0.99 * 0.01);

        return [
            'mean_ns' => array_sum($samples) / $n,
            'p99_ns' => $samples[(int) ceil(0.99 * $n) - 1],
            'p99_approx_95pct_rank_interval_ns' => [$samples[max(0, (int) floor(0.99 * $n - $radius) - 1)], $samples[min($n - 1, (int) ceil(0.99 * $n + $radius) - 1)]],
        ];
    }

    /** @return array<string, mixed> */
    private static function benchmarkMetadata(): array
    {
        $root = dirname(__DIR__, 2);
        $hashes = [];
        foreach (self::HELPERS as [$class]) {
            $path = (new \ReflectionClass($class))->getFileName();
            self::assertNotFalse($path);
            $hashes[$path] = hash_file('sha256', $path);
        }
        foreach ([__FILE__, $root.'/rust/pipeline-ffi/src/metadata.rs', $root.'/rust/pipeline-ffi/Cargo.lock', $root.'/composer.lock', $root.'/vendor/composer/installed.json'] as $path) {
            $hashes[$path] = is_file($path) ? hash_file('sha256', $path) : null;
        }
        $suffix = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
        $native = [];
        foreach ([$root.'/storage/app/nntmux-native/libnntmux_pipeline.'.$suffix, $root.'/rust/pipeline-ffi/target/release/libnntmux_pipeline.'.$suffix, '/usr/local/lib/nntmux/libnntmux_pipeline.'.$suffix] as $path) {
            if (! is_file($path)) {
                continue;
            }
            try {
                $ffi = FFI::cdef("uint32_t nntmux_pipeline_abi_version(void);\n".implode("\n", array_map(static fn (array $helper): string => $helper[0]::W404_FFI_CDEF, self::HELPERS)), $path);
                // @phpstan-ignore method.notFound (Declared by the benchmark C definition.)
                if ($ffi->nntmux_pipeline_abi_version() === 1) {
                    $native = ['path' => realpath($path), 'sha256' => hash_file('sha256', $path), 'profile' => 'release'];
                    break;
                }
            } catch (\Throwable) {
                continue;
            }
        }
        $git = 'git -C '.escapeshellarg($root).' ';

        return [
            'utc' => gmdate(DATE_ATOM),
            'head' => trim((string) shell_exec($git.'rev-parse HEAD 2>&1')),
            'base_origin_master' => trim((string) shell_exec($git.'rev-parse origin/master 2>&1')),
            'rustc' => trim((string) shell_exec('rustc --version 2>&1')),
            'cargo' => trim((string) shell_exec('cargo --version 2>&1')),
            'native_library' => $native,
            'php' => PHP_VERSION,
            'platform' => php_uname(),
            'hardware' => trim((string) shell_exec(PHP_OS_FAMILY === 'Darwin' ? 'sysctl machdep.cpu.brand_string hw.memsize hw.logicalcpu 2>&1' : 'lscpu 2>&1')),
            'memory' => PHP_OS_FAMILY === 'Darwin' ? null : trim((string) shell_exec('getconf _PHYS_PAGES 2>&1')),
            'memory_page_size' => PHP_OS_FAMILY === 'Darwin' ? null : trim((string) shell_exec('getconf PAGE_SIZE 2>&1')),
            'limits' => trim((string) shell_exec('ulimit -a 2>&1')),
            'php_memory_limit' => ini_get('memory_limit'),
            'ffi_enable' => ini_get('ffi.enable'),
            'opcache_enable_cli' => ini_get('opcache.enable_cli'),
            'jit' => ini_get('opcache.jit'),
            'seed' => 4042026,
            'warmups_per_mode' => 64,
            'paired_samples_per_workload' => 3000,
            'order' => 'fixed-seed randomized paired',
            'p99' => 'nearest rank; approximate 95 percent binomial rank interval',
            'required_speedup' => 1.5,
            'source_sha256' => $hashes,
            'reproduction_command' => 'NNTMUX_W404_BENCH=1 php vendor/bin/phpunit tests/Unit/RustMovieMusicBookConsoleGamesServiceW40ParityTest.php',
        ];
    }

    /** Common batches contain 24 rows; uncommon batches contain 1024 rows,
     * mixed missing fields, boundary values, invalid bytes and long release names.
     *
     * @return array<array-key, mixed>
     */
    private static function benchmarkInput(int $index, bool $uncommon): array
    {
        $source = self::literalCases()[$index][0];
        if ($uncommon) {
            $long = str_repeat("日本語.\xff.Long.Title.", 32);
            $source[] = $index === 3 ? $long.'.SNES.PSP.PS5.USA' : [
                'imdbid' => 'tt9999999999', 'tmdbid' => PHP_INT_MAX, 'cover' => 1,
                'year' => 2999, 'title' => $long.'-Artist.FLAC.320.1999.GOG.DLC-GOG-GAMES',
                'isbn' => '9790306406156', 'author' => $long, 'publishdate' => 'unknown', 'format' => 'AZW3',
            ];
        }
        $rows = [];
        for ($i = 0, $n = $uncommon ? 1024 : 24; $i < $n; $i++) {
            $rows[] = $source[$i % count($source)];
        }

        return $rows;
    }

    public function test_opt_in_paired_public_dispatch_benchmark(): void
    {
        if (getenv('NNTMUX_W404_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W404_BENCH=1 for 3000 paired samples per helper/workload.');
        }
        $metadata = self::benchmarkMetadata();
        $artifact = tempnam(sys_get_temp_dir(), 'nntmux-w404-bench-');
        self::assertNotFalse($artifact);
        $journal = $artifact.'.jsonl';
        $report = ['metadata' => $metadata, 'status' => 'running', 'gate_passed' => false, 'helper_qualification' => [], 'summary' => [], 'raw_pairs' => []];
        self::assertNotFalse(file_put_contents($artifact, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)));
        self::assertNotFalse(file_put_contents($journal, json_encode(['event' => 'started', 'metadata' => $metadata], JSON_THROW_ON_ERROR)."\n"));
        fwrite(STDERR, "\nWave40.4 benchmark artifact: ".$artifact.'; incremental journal: '.$journal."\n");
        $random = new Randomizer(new Mt19937(4042026));
        $pairs = [];
        $active = null;
        try {
            foreach (self::HELPERS as $index => [$class, $method, $symbol]) {
                $common = self::benchmarkInput($index, false);
                $uncommon = self::benchmarkInput($index, true);
                $ffi = self::ffi($class);
                foreach (['common' => $common, 'uncommon' => $uncommon] as $workload => $args) {
                    $active = ['method' => $method, 'workload' => $workload, 'input_sha256' => hash('sha256', serialize($args)), 'row_count' => count($args), 'wire_sha256' => hash('sha256', serialize([(new ReflectionMethod($class, 'w404Rows'))->invoke(null, $args)]))];
                    $pairs = [];
                    $expected = $class::{$method.'Php'}($args);
                    self::assertSame($expected, self::rawResult($class, $ffi, $symbol, $args));
                    self::assertNativeDispatch($class, $method, $ffi, $args, $expected);
                    foreach ([false, true] as $native) {
                        $class::useW404RustFfi($native);
                        for ($warmup = 0; $warmup < 64; $warmup++) {
                            self::assertSame($expected, $class::{$method}($args));
                        }
                    }
                    for ($sample = 0; $sample < 3000; $sample++) {
                        $order = $random->getInt(0, 1) ? [false, true] : [true, false];
                        $pair = ['sample_index' => $sample, 'order' => array_map(static fn (bool $native): string => $native ? 'rust' : 'php', $order)];
                        foreach ($order as $native) {
                            $class::useW404RustFfi($native);
                            $start = hrtime(true);
                            $actual = $class::{$method}($args);
                            $pair[$native ? 'rust_ns' : 'php_ns'] = hrtime(true) - $start;
                            self::assertSame($expected, $actual);
                        }
                        $pairs[] = $pair;
                    }
                    self::assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'w404Ffi'))->getValue());
                    $php = self::statistics(array_column($pairs, 'php_ns'));
                    $rust = self::statistics(array_column($pairs, 'rust_ns'));
                    $summary = ['php' => $php, 'rust' => $rust, 'mean_speedup' => $php['mean_ns'] / max(1, $rust['mean_ns']), 'p99_speedup' => $php['p99_ns'] / max(1, $rust['p99_ns']), 'conservative_p99_speedup' => $php['p99_approx_95pct_rank_interval_ns'][0] / max(1, $rust['p99_approx_95pct_rank_interval_ns'][1]), 'input_sha256' => $active['input_sha256']];
                    $report['raw_pairs'][$method][$workload] = $pairs;
                    $report['summary'][$method][$workload] = $summary;
                    self::assertNotFalse(file_put_contents($journal, json_encode(['event' => 'workload_completed', 'active' => $active, 'summary' => $summary, 'raw_pairs' => $pairs], JSON_THROW_ON_ERROR)."\n", FILE_APPEND));
                    self::assertNotFalse(file_put_contents($artifact, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)));
                }
                $ratios = [
                    'common_mean_speedup' => $report['summary'][$method]['common']['mean_speedup'],
                    'common_p99_speedup' => $report['summary'][$method]['common']['p99_speedup'],
                    'uncommon_mean_speedup' => $report['summary'][$method]['uncommon']['mean_speedup'],
                    'uncommon_p99_speedup' => $report['summary'][$method]['uncommon']['p99_speedup'],
                    'common_conservative_p99_speedup' => $report['summary'][$method]['common']['conservative_p99_speedup'],
                    'uncommon_conservative_p99_speedup' => $report['summary'][$method]['uncommon']['conservative_p99_speedup'],
                ];
                $report['helper_qualification'][$method] = ['ratios' => $ratios, 'qualified' => min($ratios) >= 1.5];
            }
            $report['gate_passed'] = ! in_array(false, array_column($report['helper_qualification'], 'qualified'), true);
            $report['status'] = 'completed';
            foreach (self::HELPERS as [$class, $method]) {
                if ($class::W404_RUST_FFI_DEFAULT) {
                    self::assertTrue($report['helper_qualification'][$method]['qualified'], $method.' enabled without qualifying every mean/p99 gate and p99 rank bound.');
                }
            }
        } catch (\Throwable $failure) {
            $report['status'] = 'invalid';
            $report['failure'] = ['type' => $failure::class, 'message' => $failure->getMessage(), 'active' => $active, 'partial_raw_pairs' => $pairs];
            file_put_contents($journal, json_encode(['event' => 'failed', 'failure' => $report['failure']], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
            throw $failure;
        } finally {
            file_put_contents($artifact, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
        self::assertNotFalse(file_put_contents($journal, json_encode(['event' => 'finished', 'gate_passed' => $report['gate_passed'], 'helper_qualification' => $report['helper_qualification']], JSON_THROW_ON_ERROR)."\n", FILE_APPEND));
        fwrite(STDERR, 'Wave40.4 all mean/p99 gates: '.($report['gate_passed'] ? 'PASS' : 'FAIL; only qualified helpers may default to Rust')."\n");
    }
}
