<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AnimeProcessor;
use App\Services\BooksProcessor;
use App\Services\ConsolesProcessor;
use App\Services\GamesProcessor;
use App\Services\MoviesProcessor;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Wave 41.4 pure CPU processor title summaries. The opt-in benchmark retains
 * raw randomized pairs, input/source/binary hashes, warmups and p99 rank uncertainty.
 * Reproduce: NNTMUX_W414_BENCH=1 php vendor/bin/phpunit tests/Unit/RustAnimeBooksConsolesGamesMoviesProcessorW41ParityTest.php
 */
final class RustAnimeBooksConsolesGamesMoviesProcessorW41ParityTest extends TestCase
{
    private const array HELPERS = [
        [AnimeProcessor::class, 'summarizeAnimeTitleBatch', 'nntmux_w414_anime_title_batch'],
        [BooksProcessor::class, 'summarizeBookReleaseBatch', 'nntmux_w414_book_release_batch'],
        [ConsolesProcessor::class, 'summarizeConsoleReleaseBatch', 'nntmux_w414_console_release_batch'],
        [GamesProcessor::class, 'summarizeGameReleaseBatch', 'nntmux_w414_game_release_batch'],
        [MoviesProcessor::class, 'summarizeMovieReleaseBatch', 'nntmux_w414_movie_release_batch'],
    ];

    private const array DEFAULTS = [true, true, true, true, true];

    private const int SEED = 4142026;

    protected function tearDown(): void
    {
        foreach (self::HELPERS as [$class]) {
            if (method_exists($class, 'resetW414RustFfi')) {
                $class::resetW414RustFfi();
            }
        }
        parent::tearDown();
    }

    public function test_required_additive_api_exists(): void
    {
        foreach (self::HELPERS as $index => [$class, $method]) {
            foreach ([$method, $method.'Php', $method.'Native', 'useW414RustFfi', 'usesW414RustFfi', 'resetW414RustFfi'] as $name) {
                self::assertTrue(method_exists($class, $name), $class.'::'.$name);
            }
            self::assertSame(self::DEFAULTS[$index], (new \ReflectionClass($class))->getConstant('W414_RUST_FFI_DEFAULT'));
            // Earlier dispatch helpers stay intact.
            foreach ($class === AnimeProcessor::class ? ['fixEncodingNative', 'fixEncodingPhp'] : ['usesRustFfi', 'setUseRustFfi', 'dispatchPlanPhp'] as $name) {
                self::assertTrue(method_exists($class, $name), $class.'::'.$name);
            }
        }
    }

    /** @return list<array{array<array-key, mixed>, array<string, int>}> */
    private static function literalCases(): array
    {
        return [
            [[
                '[SubsPlease] Show - 05 (1080p) [ABCDEF12]',
                '[] Show - 12345 [GHIJKL12]',
                " \0[Group] Show S01E02 720p(0123abcd)\t",
                ['searchname' => 'Show.EP1234.Batch'],
                ['name' => 'Show e12345 Complete 4K'],
                ['title' => 'Show - 1', 'searchname' => null],
                '',
                'Show S123E01 [ABCDEF1]',
                "\f[x]",
                null,
                ['searchname' => ['nested']],
                42,
            ], ['total_titles' => 12, 'empty_title_count' => 3, 'fansub_group_count' => 2, 'episode_tagged_count' => 4, 'batch_tagged_count' => 2, 'resolution_tagged_count' => 3, 'crc_tagged_count' => 2]],
            [[
                'Author - Title (2021) Retail EPUB',
                ['searchname' => 'Author.Title.Unabridged.M4B'],
                'Comic.Issue.001.CBZ',
                'Magazine.Weekly.1899.PDF',
                'Title.epubx.20201',
                "\xff\0mobi\xffretail",
                '',
                2024,
                true,
            ], ['total_releases' => 9, 'empty_title_count' => 2, 'ebook_count' => 3, 'audiobook_count' => 1, 'comic_count' => 1, 'magazine_count' => 2, 'retail_count' => 2, 'year_tagged_count' => 2]],
            [[
                'Game.NSW.USA.MULTi5.NSP-GROUP',
                ['name' => 'Game.PS2.PAL.ISO'],
                'Game.XBOX360.MULTIX',
                'Game.Update.v1.2.DLC',
                'Game_multi_NTSC-U',
                '',
                1.5,
            ], ['total_releases' => 7, 'empty_title_count' => 2, 'platform_tagged_count' => 2, 'region_tagged_count' => 3, 'multi_language_count' => 2, 'dump_format_count' => 2, 'update_dlc_count' => 1]],
            [[
                'Game.v1.2.3-CODEX',
                ['title' => 'Game.Build.123.CRACKFIX-I_KnoW'],
                'Game.MULTi12.REPACK-FitGirl',
                'Game.vX-',
                'Game-ABCDEFGHIJKLMNOPQRSTU',
                '-A',
                '',
                "Game-RUNE\n",
            ], ['total_entries' => 8, 'empty_title_count' => 1, 'version_tagged_count' => 2, 'crack_fix_count' => 1, 'multi_language_count' => 1, 'repack_count' => 1, 'group_suffix_count' => 5]],
            [[
                'Movie.2021.1080p.BluRay.x264-GRP',
                ['searchname' => 'Movie.2160p.WEB-DL.HDR.DV.HEVC'],
                'Movie 1999 720p WEBRip XviD',
                'Movie.3000.UHD',
                "\0",
                [],
            ], ['total_releases' => 6, 'empty_title_count' => 2, 'resolution_tagged_count' => 4, 'source_tagged_count' => 3, 'codec_tagged_count' => 3, 'hdr_tagged_count' => 1, 'year_tagged_count' => 2, 'complete_tag_count' => 2]],
        ];
    }

    public function test_hand_calculated_contracts_on_both_dispatches(): void
    {
        foreach (self::literalCases() as $i => [$input, $expected]) {
            [$class, $method] = self::HELPERS[$i];
            self::assertSame($expected, $class::{$method.'Php'}($input));
            self::assertSame($expected, $class::{$method.'Native'}($input));
            $empty = array_fill_keys(array_keys($expected), 0);
            foreach ([false, true] as $enabled) {
                $class::useW414RustFfi($enabled);
                self::assertSame($expected, $class::{$method}($input));
                self::assertSame($empty, $class::{$method}([]));
                $string = ['x'];
                self::assertSame($class::{$method.'Php'}($string), $class::{$method}($string));
            }
        }
    }

    public function test_switches_are_independent_and_missing_library_falls_back(): void
    {
        foreach (self::HELPERS as $index => [$class]) {
            $class::resetW414RustFfi();
            self::assertSame(self::DEFAULTS[$index], $class::usesW414RustFfi());
            $class::useW414RustFfi(false);
        }
        foreach (self::HELPERS as $index => [$class]) {
            $class::useW414RustFfi();
            foreach (self::HELPERS as [$other]) {
                self::assertSame($class === $other, $other::usesW414RustFfi());
            }
            $legacy = method_exists($class, 'usesRustFfi') ? $class::usesRustFfi() : null;
            $class::resetW414RustFfi();
            self::assertSame(self::DEFAULTS[$index], $class::usesW414RustFfi());
            self::assertSame($legacy, method_exists($class, 'usesRustFfi') ? $class::usesRustFfi() : null, 'W414 reset must not touch the W34 toggle.');
            $class::useW414RustFfi(false);
            self::assertFalse($class::usesW414RustFfi());
        }
        foreach (self::HELPERS as $i => [$class, $method]) {
            $class::useW414RustFfi(true);
            (new ReflectionProperty($class, 'w414Ffi'))->setValue(null, false);
            [$input, $expected] = self::literalCases()[$i];
            self::assertSame($expected, $class::{$method}($input));
            self::assertSame($expected, $class::{$method.'Native'}($input));
            self::assertFalse((new ReflectionProperty($class, 'w414Ffi'))->getValue());
        }
    }

    /** Reads the constant at run time so the gate assertions survive future default flips. */
    private static function defaultEnabled(string $class): bool
    {
        return (new \ReflectionClass($class))->getConstant('W414_RUST_FFI_DEFAULT') === true;
    }

    private static function export(FFI $ffi, string $symbol, string $wire, CData $out, int $capacity): int
    {
        $written = $ffi->{$symbol}($wire, strlen($wire), $out, $capacity);
        self::assertIsInt($written);

        return $written;
    }

    private static function ffi(string $class): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('metadata_w414', $class::W414_FFI_CDEF);
        self::assertNotNull($ffi, 'Build the release pipeline library with all five W414 symbols.');

        return $ffi;
    }

    /** @return list<string> */
    private static function titles(string $class, mixed $input): array
    {
        return (new ReflectionMethod($class, 'w414Titles'))->invoke(null, $input);
    }

    /** @param array<array-key, mixed> $input
     * @return array<string, int>
     */
    private static function rawResult(string $class, FFI $ffi, string $symbol, array $input): array
    {
        $wire = serialize([self::titles($class, $input)]);
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
        $class::useW414RustFfi(true);
        self::assertSame($expected, $class::{$method}($input));
        $wire = serialize($expected);
        self::assertSame($wire, FFI::string($out, strlen($wire)), 'Public dispatch must write the Rust output, rather than fall back.');
        self::assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'w414Ffi'))->getValue());
    }

    public function test_malformed_frames_are_rejected_without_writing(): void
    {
        $malformed = ['', 'N;', 'a:1:{i:0;', 'a:0:{}', 'a:1:{i:1;a:0:{}}', 'a:2:{i:0;a:0:{}i:1;a:0:{}}', 'a:1:{i:0;N;}', 'a:1:{i:0;a:1:{i:2;s:0:"";}}', 'a:1:{i:0;a:1:{s:1:"x";s:0:"";}}', 'a:1:{i:0;a:1:{i:0;N;}}', 'a:1:{i:0;a:1:{i:0;i:5;}}', 'a:1:{i:0;a:1:{i:0;a:0:{}}}', 'a:1:{i:0;a:1:{i:0;s:5:"x";}}', 'a:1:{i:0;a:0:{}}trailing', serialize(['x']), serialize([['x'], ['y']])];
        foreach (self::HELPERS as [$class, , $symbol]) {
            $ffi = self::ffi($class);
            $out = $ffi->new('char[2048]');
            foreach ($malformed as $wire) {
                FFI::memset($out, 90, 2048);
                self::assertSame(-1, self::export($ffi, $symbol, $wire, $out, 2048), $wire);
                self::assertSame(str_repeat('Z', 2048), FFI::string($out, 2048));
            }
            $wire = serialize([['title']]);
            $required = self::export($ffi, $symbol, $wire, $out, 1);
            self::assertLessThan(-1, $required, 'Insufficient capacity reports the required size.');
            self::assertSame(str_repeat('Z', 2048), FFI::string($out, 2048), 'Insufficient capacity must not write.');
            self::assertSame(-$required, self::export($ffi, $symbol, $wire, $out, -$required));
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
        foreach (self::HELPERS as [$class, $method]) {
            $input = [$object, ['searchname' => $object], ['title' => $object, 'name' => 'Ok.2020']];
            $class::useW414RustFfi(true);
            self::assertSame($class::{$method.'Php'}($input), $class::{$method}($input));
            $class::resetW414RustFfi();
            $class::useW414RustFfi(true);
            $oversized = [$large, ['searchname' => $large]];
            self::assertSame($class::{$method.'Php'}($oversized), $class::{$method}($oversized));
            self::assertSame($class::{$method.'Php'}($oversized), $class::{$method.'Native'}($oversized));
            self::assertNull((new ReflectionProperty($class, 'w414Ffi'))->getValue(), 'Size guard must run before resolving FFI.');
        }
    }

    private const array VOCABULARY = [
        // Shared separators, control bytes, invalid UTF-8 and boundary text.
        '', ' ', '.', '-', '_', ' - ', '[', ']', '(', ')', "\0", "\t", "\v", "\f", "\n", "\xff", "\x80", '日本語',
        // Anime.
        'SubsPlease', 'S01E02', 's1e1', 'S123E01', 'E12', 'ep1234', 'EP12345', 'E', 'S01', 'Batch', 'COMPLETE', '480p', '1080P', '4k', '4K2',
        'ABCDEF12', 'abcdef1', '0123ABCD', 'GHIJKL12', '05', '1', '12345', '9999',
        // Books.
        'EPUB', 'epubx', 'MOBI', 'azw3', 'PDF', 'KF8', 'djvu', 'Audiobook', 'M4B', 'Unabridged', 'MP3', 'CBR', 'cbz', 'Comics', 'Magazine', 'ISSUE', 'Retail', '1899', '2024', '2099', '1800', '3000', '20201',
        // Consoles.
        'NSW', 'Switch', 'PS5', 'PS50', 'XBOX360', 'X360', 'WiiU', 'GameCube', 'PAL', 'NTSC', 'NTSC-U', 'JPN', 'RegionFree', 'MULTi5', 'MULTI', 'Multi12', 'MULTIX', 'NSP', 'ISO', 'WBFS', 'RVZ', 'Update', 'DLC', 'PATCH',
        // Games.
        'v1', 'V12', 'vX', 'v', 'Build', 'CRACKFIX', 'Crack', 'HOTFIX', 'ReadNfo', 'REPACK', 'Rip', 'FitGirl', 'DODI', 'CODEX', 'I_KnoW', 'ABCDEFGHIJKLMNOPQRSTU', 'ABCDEFGHIJKLMNOPQRST',
        // Movies.
        '720p', '1080i', '2160P', 'UHD', 'BluRay', 'WEB', 'WEB-DL', 'WEBRip', 'HDTV', 'Remux', 'x264', 'H265', 'HEVC', 'AV1', 'XviD', 'HDR', 'HDR10', 'DV', 'DoVi', 'HLG', '1999', '2021',
    ];

    private static function randomTitle(Randomizer $random): string
    {
        $title = '';
        for ($k = 0, $n = $random->getInt(0, 12); $k < $n; $k++) {
            $title .= self::VOCABULARY[$random->getInt(0, count(self::VOCABULARY) - 1)];
            if ($random->getInt(0, 2) === 0) {
                $title .= ['.', '-', ' ', '_', ' - ', '[', ']', '(', ')', "\xff", "\0"][$random->getInt(0, 10)];
            }
        }

        return $title;
    }

    /** @return array<array-key, mixed> */
    private static function randomBatch(Randomizer $random): array
    {
        $rows = [];
        for ($j = 0, $n = $random->getInt(0, 24); $j < $n; $j++) {
            $title = self::randomTitle($random);
            $rows['key'.$j] = match ($random->getInt(0, 9)) {
                0 => ['searchname' => $title],
                1 => ['name' => $title, 'title' => 'ignored'],
                2 => ['title' => $title, 'searchname' => null],
                3 => [null, true, false, 1.5, -7, PHP_INT_MAX, [], new \stdClass][$random->getInt(0, 7)],
                4 => ['searchname' => [$title]],
                default => $title,
            };
        }

        return $rows;
    }

    public function test_raw_native_and_real_dispatch_randomized_parity(): void
    {
        $random = new Randomizer(new Mt19937(self::SEED));
        foreach (self::HELPERS as $i => [$class, $method, $symbol]) {
            $ffi = self::ffi($class);
            $cases = [[], self::literalCases()[$i][0]];
            for ($trial = 0; $trial < 500; $trial++) {
                $cases[] = self::randomBatch($random);
            }
            foreach ($cases as $input) {
                $expected = $class::{$method.'Php'}($input);
                self::assertSame($expected, self::rawResult($class, $ffi, $symbol, $input));
                self::assertSame($expected, $class::{$method.'Native'}($input));
                $class::useW414RustFfi(false);
                self::assertSame($expected, $class::{$method}($input));
                self::assertNativeDispatch($class, $method, $ffi, $input, $expected);
            }
        }
    }

    public function test_benchmark_qualified_defaults_dispatch_without_overrides(): void
    {
        foreach (self::HELPERS as $i => [$class, $method]) {
            $class::resetW414RustFfi();
            self::assertSame(self::DEFAULTS[$i], $class::usesW414RustFfi());
            $ffi = self::ffi($class);
            $capacity = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, 2048, $capacity);
            self::assertNotNull($out);
            FFI::memset($out, 90, 2048);
            [$input, $expected] = self::literalCases()[$i];
            self::assertSame($expected, $class::{$method}($input));
            if (self::defaultEnabled($class)) {
                $wire = serialize($expected);
                self::assertSame($wire, FFI::string($out, strlen($wire)));
                self::assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'w414Ffi'))->getValue());
            } else {
                self::assertNull((new ReflectionProperty($class, 'w414Ffi'))->getValue());
                self::assertSame(str_repeat('Z', 2048), FFI::string($out, 2048));
            }
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
        foreach ([__FILE__, $root.'/rust/pipeline-ffi/src/metadata.rs', $root.'/rust/pipeline-ffi/Cargo.lock', $root.'/composer.lock'] as $path) {
            $hashes[$path] = is_file($path) ? hash_file('sha256', $path) : null;
        }
        $suffix = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
        $native = [];
        foreach ([$root.'/storage/app/nntmux-native/libnntmux_pipeline.'.$suffix, $root.'/rust/pipeline-ffi/target/release/libnntmux_pipeline.'.$suffix, '/usr/local/lib/nntmux/libnntmux_pipeline.'.$suffix] as $path) {
            if (is_file($path)) {
                $native = ['path' => realpath($path), 'sha256' => hash_file('sha256', $path), 'profile' => 'release'];
                break;
            }
        }
        $git = 'git -C '.escapeshellarg($root).' ';

        return [
            'utc' => gmdate(DATE_ATOM),
            'head' => trim((string) shell_exec($git.'rev-parse HEAD 2>&1')),
            'base_origin_master' => trim((string) shell_exec($git.'rev-parse origin/master 2>&1')),
            'rustc' => trim((string) shell_exec('rustc --version 2>&1')),
            'native_library' => $native,
            'php' => PHP_VERSION,
            'platform' => php_uname(),
            'hardware' => trim((string) shell_exec(PHP_OS_FAMILY === 'Darwin' ? 'sysctl machdep.cpu.brand_string hw.memsize hw.logicalcpu 2>&1' : 'lscpu 2>&1')),
            'ffi_enable' => ini_get('ffi.enable'),
            'opcache_enable_cli' => ini_get('opcache.enable_cli'),
            'jit' => ini_get('opcache.jit'),
            'seed' => self::SEED,
            'warmups_per_mode' => 64,
            'paired_samples_per_workload' => 3000,
            'order' => 'fixed-seed randomized paired; timings include PHP packing, FFI call and decoding',
            'p99' => 'nearest rank; approximate 95 percent binomial rank interval',
            'required_speedup' => 1.5,
            'source_sha256' => $hashes,
            'reproduction_command' => 'NNTMUX_W414_BENCH=1 php vendor/bin/phpunit tests/Unit/RustAnimeBooksConsolesGamesMoviesProcessorW41ParityTest.php',
        ];
    }

    /** Common batches contain 24 typical rows; uncommon batches contain 1024 rows
     * mixing array rows, missing fields, invalid bytes and long release names.
     *
     * @return array<array-key, mixed>
     */
    private static function benchmarkInput(int $index, bool $uncommon): array
    {
        $source = array_values(array_filter(self::literalCases()[$index][0], static fn (mixed $row): bool => is_string($row) && $row !== ''));
        if ($uncommon) {
            $long = str_repeat("日本語.\xff.Long.Title.", 32);
            $source = [...self::literalCases()[$index][0], $long.'[ABCDEF12].S01E02.MULTi5.v1.2.1080p.BluRay.x264.EPUB.PS5.USA.2021-GROUP', ['searchname' => $long.' - 05 Retail NSP REPACK HDR10']];
        }
        $rows = [];
        for ($i = 0, $n = $uncommon ? 1024 : 24; $i < $n; $i++) {
            $rows[] = $source[$i % count($source)];
        }

        return $rows;
    }

    public function test_opt_in_paired_public_dispatch_benchmark(): void
    {
        if (getenv('NNTMUX_W414_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W414_BENCH=1 for 3000 paired samples per helper/workload.');
        }
        $artifact = getenv('NNTMUX_W414_BENCH_ARTIFACT') ?: tempnam(sys_get_temp_dir(), 'nntmux-w414-bench-');
        self::assertNotFalse($artifact);
        $report = ['metadata' => self::benchmarkMetadata(), 'status' => 'running', 'gate_passed' => false, 'helper_qualification' => [], 'summary' => [], 'raw_pairs' => []];
        fwrite(STDERR, "\nWave41.4 benchmark artifact: ".$artifact."\n");
        $random = new Randomizer(new Mt19937(self::SEED));
        try {
            foreach (self::HELPERS as $index => [$class, $method, $symbol]) {
                $ffi = self::ffi($class);
                foreach (['common' => false, 'uncommon' => true] as $workload => $uncommon) {
                    $args = self::benchmarkInput($index, $uncommon);
                    $pairs = [];
                    $expected = $class::{$method.'Php'}($args);
                    self::assertSame($expected, self::rawResult($class, $ffi, $symbol, $args));
                    self::assertNativeDispatch($class, $method, $ffi, $args, $expected);
                    foreach ([false, true] as $native) {
                        $class::useW414RustFfi($native);
                        for ($warmup = 0; $warmup < 64; $warmup++) {
                            self::assertSame($expected, $class::{$method}($args));
                        }
                    }
                    for ($sample = 0; $sample < 3000; $sample++) {
                        $order = $random->getInt(0, 1) ? [false, true] : [true, false];
                        $pair = ['order' => $order[0] ? 'rust-first' : 'php-first'];
                        foreach ($order as $native) {
                            $class::useW414RustFfi($native);
                            $start = hrtime(true);
                            $actual = $class::{$method}($args);
                            $pair[$native ? 'rust_ns' : 'php_ns'] = hrtime(true) - $start;
                            self::assertSame($expected, $actual);
                        }
                        $pairs[] = $pair;
                    }
                    $php = self::statistics(array_column($pairs, 'php_ns'));
                    $rust = self::statistics(array_column($pairs, 'rust_ns'));
                    $report['raw_pairs'][$method][$workload] = $pairs;
                    $report['summary'][$method][$workload] = [
                        'rows' => count($args),
                        'input_sha256' => hash('sha256', serialize(self::titles($class, $args))),
                        'php' => $php,
                        'rust' => $rust,
                        'mean_speedup' => $php['mean_ns'] / max(1, $rust['mean_ns']),
                        'p99_speedup' => $php['p99_ns'] / max(1, $rust['p99_ns']),
                        'conservative_p99_speedup' => $php['p99_approx_95pct_rank_interval_ns'][0] / max(1, $rust['p99_approx_95pct_rank_interval_ns'][1]),
                    ];
                }
                $ratios = [];
                foreach (['common', 'uncommon'] as $workload) {
                    $ratios[$workload.'_mean_speedup'] = $report['summary'][$method][$workload]['mean_speedup'];
                    $ratios[$workload.'_p99_speedup'] = $report['summary'][$method][$workload]['p99_speedup'];
                }
                $report['helper_qualification'][$method] = ['ratios' => $ratios, 'qualified' => min($ratios) >= 1.5];
                fwrite(STDERR, sprintf("%s: %s\n", $method, json_encode($ratios, JSON_THROW_ON_ERROR)));
            }
            $report['gate_passed'] = ! in_array(false, array_column($report['helper_qualification'], 'qualified'), true);
            $report['status'] = 'completed';
            foreach (self::HELPERS as [$class, $method]) {
                if (self::defaultEnabled($class)) {
                    self::assertTrue($report['helper_qualification'][$method]['qualified'], $method.' enabled without qualifying every mean/p99 gate.');
                }
            }
        } catch (\Throwable $failure) {
            $report['status'] = 'invalid';
            $report['failure'] = ['type' => $failure::class, 'message' => $failure->getMessage()];
            throw $failure;
        } finally {
            file_put_contents($artifact, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
    }
}
