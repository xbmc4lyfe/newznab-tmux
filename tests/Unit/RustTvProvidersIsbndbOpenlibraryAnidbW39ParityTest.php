<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AnidbService;
use App\Services\IsbnDbService;
use App\Services\OpenLibraryService;
use App\Services\TvProcessing\Providers\TvdbProvider;
use App\Services\TvProcessing\Providers\TvMazeProvider;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;

/**
 * Wave 39.4 end-to-end FFI qualification on macOS / Apple M5, PHP 8.5.12,
 * rustc 1.99.1, optimized release build. Each workload has 64 warmups per
 * dispatch and 3000 identical-input pairs in fixed-seed randomized order.
 * Public dispatch includes projection, serialization, FFI and result decoding.
 * The opt-in test saves raw pairs, source/binary hashes and p99 rank intervals.
 *
 * Measured PHP/Rust latency ratios (common mean/p99; uncommon mean/p99):
 * TVMaze:       0.334/0.324; 0.342/0.360
 * TVDB:         0.309/0.315; 0.465/0.475
 * ISBNdb:       1.255/1.304; 3.452/3.324
 * OpenLibrary:  0.354/0.381; 0.505/0.517
 * AniDB:        0.201/0.207; 0.180/0.143
 * No helper meets all four >=1.5x gates, so every W394 default remains PHP.
 * Reproduce: NNTMUX_W394_BENCH=1 php vendor/bin/phpunit tests/Unit/RustTvProvidersIsbndbOpenlibraryAnidbW39ParityTest.php
 */
final class RustTvProvidersIsbndbOpenlibraryAnidbW39ParityTest extends TestCase
{
    private const array HELPERS = [
        [TvMazeProvider::class, 'summarizeTvMazeEpisodeCatalog', 'nntmux_w394_tvmaze_episode_catalog'],
        [TvdbProvider::class, 'summarizeTvdbSeriesTranslations', 'nntmux_w394_tvdb_series_translations'],
        [IsbnDbService::class, 'normalizeIsbnBatchCandidates', 'nntmux_w394_isbn_batch_candidates'],
        [OpenLibraryService::class, 'summarizeOpenLibraryWorkEditions', 'nntmux_w394_openlibrary_work_editions'],
        [AnidbService::class, 'planAnidbRateLimitWindow', 'nntmux_w394_anidb_rate_limit_window'],
    ];

    protected function tearDown(): void
    {
        foreach (self::HELPERS as [$class]) {
            if (method_exists($class, 'resetW394RustFfi')) {
                $class::resetW394RustFfi();
            }
        }
        parent::tearDown();
    }

    public function test_required_additive_api_exists(): void
    {
        foreach (self::HELPERS as [$class, $method]) {
            foreach ([$method, $method.'Php', 'useW394RustFfi', 'usesW394RustFfi', 'resetW394RustFfi'] as $name) {
                self::assertTrue(method_exists($class, $name), $class.'::'.$name);
            }
            self::assertFalse((new \ReflectionClass($class))->getConstant('W394_RUST_FFI_DEFAULT'));
        }
    }

    public function test_default_parameters_and_named_arguments(): void
    {
        foreach ([false, true] as $native) {
            foreach (self::HELPERS as [$class]) {
                $class::useW394RustFfi($native);
            }
            self::assertSame(TvMazeProvider::summarizeTvMazeEpisodeCatalogPhp([], 0), TvMazeProvider::summarizeTvMazeEpisodeCatalog(episodes: []));
            self::assertSame(TvdbProvider::summarizeTvdbSeriesTranslationsPhp([], 'eng'), TvdbProvider::summarizeTvdbSeriesTranslations(translations: []));
            self::assertSame(IsbnDbService::normalizeIsbnBatchCandidatesPhp([]), IsbnDbService::normalizeIsbnBatchCandidates(rawTokens: []));
            self::assertSame(OpenLibraryService::summarizeOpenLibraryWorkEditionsPhp([], 0), OpenLibraryService::summarizeOpenLibraryWorkEditions(editions: []));
            self::assertSame(AnidbService::planAnidbRateLimitWindowPhp(100, 101, 0, 2), AnidbService::planAnidbRateLimitWindow(lastRequestUnix: 100, nowUnix: 101, consecutiveFailures: 0));
            self::assertSame(AnidbService::planAnidbRateLimitWindowPhp(100, 101, 1, 3), AnidbService::planAnidbRateLimitWindow(baseCooldownSeconds: 3, consecutiveFailures: 1, nowUnix: 101, lastRequestUnix: 100));
            self::assertSame(TvMazeProvider::summarizeTvMazeEpisodeCatalogPhp([]), TvMazeProvider::summarizeTvMazeEpisodeCatalog([]));
            self::assertSame(TvdbProvider::summarizeTvdbSeriesTranslationsPhp([]), TvdbProvider::summarizeTvdbSeriesTranslations([]));
            self::assertSame(OpenLibraryService::summarizeOpenLibraryWorkEditionsPhp([]), OpenLibraryService::summarizeOpenLibraryWorkEditions([]));
            self::assertSame(AnidbService::planAnidbRateLimitWindowPhp(lastRequestUnix: 100, nowUnix: 101, consecutiveFailures: 0), AnidbService::planAnidbRateLimitWindow(100, 101, 0));
        }
    }

    /** @return array<int, list<array{0: list<mixed>, 1: array<string, int|string|bool>}>> */
    private static function literalCases(): array
    {
        return [
            [
                [[[], 0], ['total_episodes' => 0, 'matching_season_count' => 0, 'special_count' => 0, 'missing_airdate_count' => 0, 'max_episode_number' => 0]],
                [[[['season' => 0, 'number' => 4, 'airdate' => " \0\t"], ['season' => '0', 'number' => '9', 'airdate' => '日本語'], null, ['season' => 2, 'number' => 7, 'airdate' => "\f"], ['season' => null, 'number' => -1]], 0], ['total_episodes' => 5, 'matching_season_count' => 1, 'special_count' => 1, 'missing_airdate_count' => 3, 'max_episode_number' => 7]],
            ],
            [
                [[[], 'eng'], ['total_entries' => 0, 'preferred_language_index' => -1, 'english_language_index' => -1, 'non_empty_overview_count' => 0, 'alias_count' => 0]],
                [[[null, ['language' => ' ENG ', 'overview' => "\0\t", 'aliases' => ['', ' a ', 1, "\0", 'a', '日本語']], ['language' => 'SPA', 'overview' => "\f", 'aliases' => 'x'], ['language' => 'eng', 'overview' => "x\0"]], ' spa '], ['total_entries' => 4, 'preferred_language_index' => 2, 'english_language_index' => 1, 'non_empty_overview_count' => 2, 'alias_count' => 3]],
            ],
            [
                [[[]], ['valid_isbn13_count' => 0, 'valid_isbn10_count' => 0, 'invalid_count' => 0, 'deduplicated_count' => 0, 'first_canonical_isbn13' => '']],
                [[['978-0-306-40615-7', '0 306\t40615 2', '0306406152', '080442957x', '9780306406158', '9790306406155', 9780306406157, "\0", '９780306406157']], ['valid_isbn13_count' => 1, 'valid_isbn10_count' => 2, 'invalid_count' => 6, 'deduplicated_count' => 2, 'first_canonical_isbn13' => '9780306406157']],
            ],
            [
                [[[], 2001], ['total_editions' => 0, 'with_isbn13_count' => 0, 'with_cover_count' => 0, 'exact_year_match_count' => 0, 'earliest_publication_year' => 0]],
                [[[['isbn_13' => ["\0", ' 日本語 '], 'covers' => ['1', -1, 2], 'publish_date' => 'December 2001'], ['isbn_13' => [''], 'covers' => [0], 'publish_date' => '0000 then 1999'], null, ['publish_date' => '120001 1899'], ['publish_date' => '9999\0 1900']], 2001], ['total_editions' => 5, 'with_isbn13_count' => 1, 'with_cover_count' => 1, 'exact_year_match_count' => 1, 'earliest_publication_year' => 1899]],
            ],
            [
                [[0, 10, 0, 5], ['elapsed_seconds' => 10, 'required_wait_seconds' => 5, 'remaining_sleep_seconds' => 0, 'backoff_multiplier' => 1, 'ready_to_request' => true]],
                [[20, 10, 2, 3], ['elapsed_seconds' => 0, 'required_wait_seconds' => 12, 'remaining_sleep_seconds' => 12, 'backoff_multiplier' => 4, 'ready_to_request' => false]],
                [[PHP_INT_MIN, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX], ['elapsed_seconds' => PHP_INT_MAX, 'required_wait_seconds' => PHP_INT_MAX, 'remaining_sleep_seconds' => 0, 'backoff_multiplier' => 1073741824, 'ready_to_request' => true]],
                [[PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN], ['elapsed_seconds' => 0, 'required_wait_seconds' => 0, 'remaining_sleep_seconds' => 0, 'backoff_multiplier' => 1, 'ready_to_request' => true]],
            ],
        ];
    }

    public function test_hand_calculated_contracts_on_both_dispatches(): void
    {
        foreach (self::literalCases() as $index => $cases) {
            [$class, $method] = self::HELPERS[$index];
            foreach ($cases as [$args, $expected]) {
                self::assertSame($expected, $class::{$method.'Php'}(...$args));
                foreach ([false, true] as $enabled) {
                    $class::useW394RustFfi($enabled);
                    self::assertSame($expected, $class::{$method}(...$args));
                }
            }
        }
    }

    public function test_independent_switches_and_missing_library_fallback(): void
    {
        foreach (self::HELPERS as [$class]) {
            $class::resetW394RustFfi();
            self::assertFalse($class::usesW394RustFfi());
        }
        foreach (self::HELPERS as [$class]) {
            $class::useW394RustFfi(true);
            foreach (self::HELPERS as [$other]) {
                self::assertSame($class === $other, $other::usesW394RustFfi());
            }
            $class::useW394RustFfi(null);
            self::assertFalse($class::usesW394RustFfi());
        }
        foreach (self::HELPERS as $index => [$class, $method]) {
            $class::useW394RustFfi(true);
            (new ReflectionProperty($class, 'w394Ffi'))->setValue(null, false);
            foreach (self::literalCases()[$index] as [$args, $expected]) {
                self::assertSame($expected, $class::{$method}(...$args));
            }
        }
    }

    /** @return array<int, list<list<mixed>>> */
    private static function parityCases(): array
    {
        $cases = array_map(static fn (array $rows): array => array_column($rows, 0), self::literalCases());
        $cases[0][] = [[['season' => -1, 'number' => PHP_INT_MAX, 'airdate' => "\v"], false, ['season' => 0.0, 'number' => 4.0, 'airdate' => "\u{a0}"]], -1];
        $cases[1][] = [[['language' => '', 'overview' => false, 'aliases' => ["\f", "\v", "\0", "\u{a0}"]]], ''];
        $cases[1][] = [[['language' => "\0ENg\t", 'overview' => "\v", 'aliases' => []], ['language' => 'İNG']], ' ENG '];
        $cases[2][] = [[" 0-306\t40615\r2\n", '9780306406157', '9790306406156', "080442957x\v\f", false, null, [], 1.0, '9780306406157X', '0000000000', "978\u{a0}0306406157", "9780306406157\0"]];
        $cases[3][] = [[['isbn_13' => [true, "\v", "\f"], 'covers' => [1.0, '2', PHP_INT_MAX], 'publish_date' => '0001'], ['publish_date' => '0000 2001'], ['publish_date' => '99999 2020'], ['publish_date' => "\0日本語1987年"]], 1];
        foreach ([[PHP_INT_MAX, PHP_INT_MAX, 30, 0], [0, 0, 31, 1], [1, 5, 29, 2], [1, 2, 1, intdiv(PHP_INT_MAX, 2) + 1], [PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN]] as $args) {
            $cases[4][] = $args;
        }
        $random = new Randomizer(new Mt19937(3942026));
        $strings = ['', ' ', "\0\t\n", "\v\f", '日本語', "\xff", "eng\0", 'ENG', 'spa', '2001', '0000 1999', '9780306406157', '0306406152', '080442957X', '9790306406156'];
        $values = [null, false, true, [], 0, 1, -1, 1.5, '0'];
        for ($i = 0; $i < 250; $i++) {
            $episodes = $translations = $tokens = $editions = [];
            for ($j = 0, $n = $random->getInt(0, 30); $j < $n; $j++) {
                $text = $strings[$random->getInt(0, count($strings) - 1)];
                $mixed = $values[$random->getInt(0, count($values) - 1)];
                $episodes[] = $j % 7 === 0 ? $mixed : ['season' => $j % 5 === 0 ? $mixed : $random->getInt(-1, 3), 'number' => $j % 4 === 0 ? $mixed : $random->getInt(-3, 100), 'airdate' => $text];
                $translations[] = $j % 7 === 0 ? $mixed : ['language' => $text, 'overview' => $j % 3 === 0 ? $mixed : $text, 'aliases' => [$text, $mixed, $text]];
                $tokens[] = $j % 3 === 0 ? $mixed : $text;
                $editions[] = $j % 7 === 0 ? $mixed : ['isbn_13' => [$mixed, $text], 'covers' => [$mixed, $random->getInt(-1, 2)], 'publish_date' => $text];
            }
            $cases[0][] = [$episodes, $random->getInt(-1, 3)];
            $cases[1][] = [$translations, $strings[$random->getInt(0, count($strings) - 1)]];
            $cases[2][] = [$tokens];
            $cases[3][] = [$editions, $random->getInt(-1, 2026)];
            $cases[4][] = [$random->getInt(PHP_INT_MIN, PHP_INT_MAX), $random->getInt(PHP_INT_MIN, PHP_INT_MAX), $random->getInt(-3, 35), $random->getInt(-3, 1000000)];
        }

        return $cases;
    }

    private static function ffi(string $class): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('metadata_w394', $class::W394_FFI_CDEF);
        self::assertNotNull($ffi, 'Build the release pipeline library containing all five Wave39.4 symbols.');

        return $ffi;
    }

    /** @param list<mixed> $args
     * @return array<string, int|string|bool>
     */
    private static function rawResult(FFI $ffi, string $symbol, array $args): array
    {
        $input = serialize($args);
        $out = $ffi->new('char[2048]');
        $written = $ffi->{$symbol}($input, strlen($input), $out, 2048);
        self::assertGreaterThan(0, $written, $symbol.' must execute, not silently fall back.');
        self::assertLessThanOrEqual(2048, $written);
        $result = unserialize(FFI::string($out, $written), ['allowed_classes' => false]);
        self::assertIsArray($result);

        return $result;
    }

    /**
     * Detect a dispatch that silently falls back despite a resolvable library.
     *
     * @param  list<mixed>  $args
     * @param  array<string, int|string|bool>  $expected
     */
    private static function assertNativeDispatch(string $class, string $method, FFI $ffi, array $args, array $expected): void
    {
        $capacity = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 2048, $capacity);
        self::assertNotNull($scratch);
        FFI::memset($scratch, 90, 2048);
        $class::useW394RustFfi(true);
        self::assertSame($expected, $class::{$method}(...$args));
        $serialized = serialize($expected);
        self::assertSame($serialized, FFI::string($scratch, strlen($serialized)), 'Public dispatch must write the native summary rather than silently use PHP.');
    }

    public function test_raw_native_and_real_dispatch_parity(): void
    {
        foreach (self::parityCases() as $index => $cases) {
            [$class, $method, $symbol] = self::HELPERS[$index];
            $ffi = self::ffi($class);
            foreach ($cases as $args) {
                $expected = $class::{$method.'Php'}(...$args);
                self::assertSame($expected, self::rawResult($ffi, $symbol, $args));
                foreach ([false, true] as $enabled) {
                    $class::useW394RustFfi($enabled);
                    self::assertSame($expected, $class::{$method}(...$args));
                }
                self::assertNativeDispatch($class, $method, $ffi, $args, $expected);
                self::assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'w394Ffi'))->getValue());
            }
        }
    }

    public function test_objects_are_never_serialized_and_oversized_input_falls_back(): void
    {
        $object = new class
        {
            public function __serialize(): array
            {
                throw new \LogicException('Untrusted object serialization must never run.');
            }
        };
        $args = [
            [[$object, ['season' => $object, 'number' => $object, 'airdate' => $object, 'unused' => $object]], 0],
            [[$object, ['language' => $object, 'overview' => $object, 'aliases' => [$object], 'unused' => $object]], 'eng'],
            [[$object]],
            [[$object, ['isbn_13' => [$object], 'covers' => [$object], 'publish_date' => $object, 'unused' => $object]], 2001],
        ];
        foreach ($args as $index => $input) {
            [$class, $method] = self::HELPERS[$index];
            $class::useW394RustFfi(true);
            self::assertSame($class::{$method.'Php'}(...$input), $class::{$method}(...$input));
        }
        $large = str_repeat('x', 16777217);
        $oversized = [
            [[[['airdate' => $large]], 0]],
            [[[['overview' => $large]], 'eng']],
            [[[$large]]],
            [[[['publish_date' => $large]], 0]],
        ];
        foreach ($oversized as $index => $rows) {
            [$class, $method] = self::HELPERS[$index];
            $class::resetW394RustFfi();
            $class::useW394RustFfi(true);
            self::assertSame($class::{$method.'Php'}(...$rows[0]), $class::{$method}(...$rows[0]));
            self::assertNull((new ReflectionProperty($class, 'w394Ffi'))->getValue(), 'Oversized input must decline before resolving native state.');
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
                $ffi = FFI::cdef("uint32_t nntmux_pipeline_abi_version(void);\n".implode("\n", array_map(static fn (array $helper): string => $helper[0]::W394_FFI_CDEF, self::HELPERS)), $path);
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
            'seed' => 3942026,
            'warmups_per_mode' => 64,
            'paired_samples_per_workload' => 3000,
            'order' => 'fixed-seed randomized paired',
            'p99' => 'nearest rank; approximate 95 percent binomial rank interval',
            'required_speedup' => 1.5,
            'source_sha256' => $hashes,
            'reproduction_command' => 'NNTMUX_W394_BENCH=1 php vendor/bin/phpunit tests/Unit/RustTvProvidersIsbndbOpenlibraryAnidbW39ParityTest.php',
        ];
    }

    /** @return array<int, list<mixed>> */
    private static function commonBenchmarkArguments(): array
    {
        $episodes = [];
        for ($number = 1; $number <= 24; $number++) {
            $episodes[] = ['season' => $number <= 12 ? 1 : 2, 'number' => ($number - 1) % 12 + 1, 'airdate' => sprintf('2024-01-%02d', $number)];
        }

        return [
            [$episodes, 2],
            [[['language' => 'eng', 'overview' => 'An English overview.', 'aliases' => ['Example', 'Example Show']], ['language' => 'spa', 'overview' => 'Una serie.', 'aliases' => ['Ejemplo']], ['language' => 'jpn', 'overview' => '日本語', 'aliases' => ['シリーズ']]], 'spa'],
            [['978-0-306-40615-7', '0-8044-2957-X', '9790306406156', '0306406152']],
            [[['isbn_13' => ['9780306406157'], 'covers' => [12345], 'publish_date' => '1980'], ['isbn_13' => ['9780804429573'], 'covers' => [23456], 'publish_date' => 'June 2001'], ['isbn_13' => ['9790306406156'], 'covers' => [34567], 'publish_date' => '2001'], ['isbn_13' => [], 'covers' => [], 'publish_date' => 'December 1985'], ['isbn_13' => ['9780306406157'], 'covers' => [45678], 'publish_date' => '2020']], 2001],
            [1700000000, 1700000001, 0, 2],
        ];
    }

    public function test_opt_in_paired_public_dispatch_benchmark(): void
    {
        if (getenv('NNTMUX_W394_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W394_BENCH=1 for 3000 paired samples per helper/workload.');
        }
        $metadata = self::benchmarkMetadata();
        $artifact = tempnam(sys_get_temp_dir(), 'nntmux-w394-bench-');
        self::assertNotFalse($artifact);
        $journal = $artifact.'.jsonl';
        $report = ['metadata' => $metadata, 'status' => 'running', 'gate_passed' => false, 'helper_qualification' => [], 'summary' => [], 'raw_pairs' => []];
        self::assertNotFalse(file_put_contents($artifact, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)));
        self::assertNotFalse(file_put_contents($journal, json_encode(['event' => 'started', 'metadata' => $metadata], JSON_THROW_ON_ERROR)."\n"));
        fwrite(STDERR, "\nWave39.4 benchmark artifact: ".$artifact.'; incremental journal: '.$journal."\n");
        $random = new Randomizer(new Mt19937(3942026));
        $pairs = [];
        $active = null;
        try {
            foreach (self::HELPERS as $index => [$class, $method, $symbol]) {
                $common = self::commonBenchmarkArguments()[$index];
                $uncommon = self::literalCases()[$index][1][0];
                if ($index < 4) {
                    $uncommon[0] = array_merge(...array_fill(0, 256, $uncommon[0]));
                } else {
                    $uncommon = [PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX, PHP_INT_MAX];
                }
                $ffi = self::ffi($class);
                foreach (['common' => $common, 'uncommon' => $uncommon] as $workload => $args) {
                    $active = ['method' => $method, 'workload' => $workload, 'input_sha256' => hash('sha256', serialize($args))];
                    $pairs = [];
                    $expected = $class::{$method.'Php'}(...$args);
                    self::assertSame($expected, self::rawResult($ffi, $symbol, $args));
                    self::assertNativeDispatch($class, $method, $ffi, $args, $expected);
                    foreach ([false, true] as $native) {
                        $class::useW394RustFfi($native);
                        for ($warmup = 0; $warmup < 64; $warmup++) {
                            self::assertSame($expected, $class::{$method}(...$args));
                        }
                    }
                    for ($sample = 0; $sample < 3000; $sample++) {
                        $order = $random->getInt(0, 1) ? [false, true] : [true, false];
                        $pair = ['sample_index' => $sample, 'order' => array_map(static fn (bool $native): string => $native ? 'rust' : 'php', $order)];
                        foreach ($order as $native) {
                            $class::useW394RustFfi($native);
                            $start = hrtime(true);
                            $actual = $class::{$method}(...$args);
                            $pair[$native ? 'rust_ns' : 'php_ns'] = hrtime(true) - $start;
                            self::assertSame($expected, $actual);
                        }
                        $pairs[] = $pair;
                    }
                    self::assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'w394Ffi'))->getValue());
                    $php = self::statistics(array_column($pairs, 'php_ns'));
                    $rust = self::statistics(array_column($pairs, 'rust_ns'));
                    $summary = ['php' => $php, 'rust' => $rust, 'mean_speedup' => $php['mean_ns'] / max(1, $rust['mean_ns']), 'p99_speedup' => $php['p99_ns'] / max(1, $rust['p99_ns']), 'input_sha256' => $active['input_sha256']];
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
                ];
                $report['helper_qualification'][$method] = ['ratios' => $ratios, 'qualified' => min($ratios) >= 1.5];
            }
            $report['gate_passed'] = ! in_array(false, array_column($report['helper_qualification'], 'qualified'), true);
            $report['status'] = 'completed';
            foreach (self::HELPERS as [$class]) {
                self::assertFalse((new \ReflectionClass($class))->getConstant('W394_RUST_FFI_DEFAULT'), 'Defaults remain disabled pending reviewed qualification.');
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
        fwrite(STDERR, 'Wave39.4 all mean/p99 gates: '.($report['gate_passed'] ? 'PASS' : 'FAIL; defaults remain PHP')."\n");
    }
}
