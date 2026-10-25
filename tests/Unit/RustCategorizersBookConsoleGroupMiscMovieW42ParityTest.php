<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Category;
use App\Services\Categorization\Categorizers\BookCategorizer;
use App\Services\Categorization\Categorizers\ConsoleCategorizer;
use App\Services\Categorization\Categorizers\GroupNameCategorizer;
use App\Services\Categorization\Categorizers\MiscCategorizer;
use App\Services\Categorization\Categorizers\MovieCategorizer;
use App\Services\Categorization\ReleaseContext;
use App\Support\RustPipelineLibrary;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;
use Tests\TestCase;

final class RustCategorizersBookConsoleGroupMiscMovieW42ParityTest extends TestCase
{
    /** Every Wave 42.3 target shares the W423 toggle names and keeps its existing native paths separate. */
    private const TARGETS = [
        BookCategorizer::class,
        ConsoleCategorizer::class,
        GroupNameCategorizer::class,
        MiscCategorizer::class,
        MovieCategorizer::class,
    ];

    /** Production defaults: every helper clears 1.5x on every measure of the paired benchmark below. */
    private const DEFAULTS = [
        BookCategorizer::class => true,
        ConsoleCategorizer::class => true,
        GroupNameCategorizer::class => true,
        MiscCategorizer::class => true,
        MovieCategorizer::class => true,
    ];

    /** Helper per target: [class, method, export]. */
    private const HELPERS = [
        'book' => [BookCategorizer::class, 'summarizeBookFormatBatch', 'nntmux_w423_book_format_batch'],
        'console' => [ConsoleCategorizer::class, 'summarizeConsolePlatformBatch', 'nntmux_w423_console_platform_batch'],
        'group' => [GroupNameCategorizer::class, 'summarizeGroupNameBatch', 'nntmux_w423_group_name_batch'],
        'misc' => [MiscCategorizer::class, 'summarizeMiscContentBatch', 'nntmux_w423_misc_content_batch'],
        'movie' => [MovieCategorizer::class, 'summarizeMovieQualityBatch', 'nntmux_w423_movie_quality_batch'],
    ];

    /** Strings that exercise trimming, case, markers, prefixes, hashes, NUL bytes and invalid UTF-8. */
    private const TEXTS = [
        '', ' ', "\0", "\x1F", "\t\n", "\x0B", "\x0C", "\r", '-', '.', '_', ' . ', 'a', 'x-', 'Batman.001.2024.Digital.CBZ', 'Author - Title (Unabridged) M4B',
        'Some.Magazine.Weekly.Issue.12', 'Author.Title.2024.RETAIL.EPUB', 'Manga.Vol.3.epub', 'Retail.Only', '.cb', 'cb7', '.CB7', 'AZW3', 'e.pdf', 'audibl',
        'Game.PS4-GRP', 'Game.XBOX360.PAL', 'Game.NSW.USA', 'Game.Wii.NTSC', 'Some.PlayStation.Xbox.Mix', 'WIIU', 'wi', 'ps', 'Region.Free', 'region free', 'Palace',
        'alt.binaries.anime', 'ALT.BINARIES.HDTV.X264', 'alt.binaries.movies.divx', 'alt.binaries.erotica', 'alt.binaries.sounds.mp3', 'alt.binaries.games.xbox',
        'alt.binaries.warez', 'alt.binaries.e-book', 'alt.binaries.misc', 'alt.binaries', 'alt.binaries.', ' alt.binaries.tv', 'alt.binaries.teevee', 'a.b.0day',
        'd41d8cd98f00b204e9800998ecf8427e', '  DA39A3EE5E6B4B0D3255BFEF95601890AFD80709'."\t", 'd41d8cd98f00b204e9800998ecf8427', 'g41d8cd98f00b204e9800998ecf8427e',
        'abababababababababababababababababababababababababababababababab', 'AbAbAbAbAbAbAbAbAbAbAbAbAbAbAbAbAbAbAbAbAbAbAbAbAbAbAbAbAbAbAbAb0', "d41d8cd98f00b204e9800998ecf8427e\x0C", "\0d41d8cd98f00b204e9800998ecf8427e\0", 'Some.Archive.part01.RAR', 'Customer.Dataset.csv',
        'db.SQL.dump', 'x.7Z', 'a.ra', '.001', 'Backup', 'Movie.2024.2160p.UHD.BluRay.x265-GRP', 'Movie.2024.1080p.WEB-DL.H.265-GRP', 'Movie.2024.720p.BDRip.x264-GRP',
        'Movie.2004.DVDRip.XviD-GRP', 'Movie.4K.HDR', 'x.2160', 'Blu-Ray', 'h.26', '1080I', 'Plain.Name', "name-GR\xffP", 'Ünïcödé.EPUB', "bad \xff utf8", "\xc3", "4\xffk",
    ];

    /** Fragments for random names: markers, prefixes, hex runs, separators and noise. */
    private const WORDS = ['alt.binaries.', 'ALT.', 'binaries', 'Show', 'Movie', '.cbr', '.cbz', 'comic', 'manga', 'audiobook', '.m4b', 'unabridged', 'magazine',
        'weekly', 'issue', 'epub', 'mobi', '.pdf', 'ebook', 'retail', 'ps5', 'PS4', 'psp', 'xbox', 'x360', 'switch', 'nsw', 'wii', 'wiiu', '3ds', 'pal', 'ntsc', 'usa',
        'eur', 'jpn', 'region.free', 'anime', 'hdtv', '.tv', 'movies', 'dvd', 'bluray', 'x264', 'erotica', 'xxx', 'mp3', 'sounds', 'games', 'console', 'warez', '0day',
        'apps', 'e-book', 'comics', 'd41d8cd98f00b204', 'e9800998ecf8427e', 'DA39A3EE', '.rar', '.zip', '.7z', '.par2', '.001', 'dataset', '.csv', '.sql', 'dump',
        'backup', '2160p', '4k', 'uhd', '1080p', '720p', '480p', 'dvdrip', 'xvid', 'x265', 'hevc', 'h.265', 'blu-ray', 'bdrip', '-GRP'];

    protected function tearDown(): void
    {
        foreach (self::TARGETS as $class) {
            $class::resetW423RustFfi();
        }
        parent::tearDown();
    }

    public function test_rust_ffi_defaults_and_toggles(): void
    {
        foreach (self::TARGETS as $class) {
            $default = self::DEFAULTS[$class];
            $this->assertSame($default, constant($class.'::W423_RUST_FFI_DEFAULT'), $class);
            $this->assertSame($default, $class::usesW423RustFfi(), $class);
            $class::useW423RustFfi();
            $this->assertTrue($class::usesW423RustFfi(), $class);
            $class::useW423RustFfi(false);
            $this->assertFalse($class::usesW423RustFfi(), $class);
            $class::useW423RustFfi(true);
            $this->assertTrue($class::usesW423RustFfi(), $class);
            $class::resetW423RustFfi();
            $this->assertNull((new ReflectionProperty($class, 'w423RustFfiOverride'))->getValue(), $class);
            $this->assertNull((new ReflectionProperty($class, 'w423RustFfiHandle'))->getValue(), $class);
            $this->assertSame($default, $class::usesW423RustFfi(), $class);
        }

        // The W423 toggles never touch the categorizers' existing categorization paths.
        $context = new ReleaseContext('Movie.Title.2024.1080p.BluRay.x264-GRP', 1, 'alt.binaries.movies');
        foreach ([true, false] as $enable) {
            foreach (self::TARGETS as $class) {
                $class::useW423RustFfi($enable);
            }
            $this->assertSame('Book', (new BookCategorizer)->getName());
            $this->assertSame('Console', (new ConsoleCategorizer)->getName());
            $this->assertSame('GroupName', (new GroupNameCategorizer)->getName());
            $this->assertSame('Misc', (new MiscCategorizer)->getName());
            $this->assertSame('Movie', (new MovieCategorizer)->getName());
            $this->assertSame(5, (new GroupNameCategorizer)->getPriority());
            $group = (new GroupNameCategorizer)->categorize($context);
            $this->assertSame([Category::MOVIE_OTHER, 'group_name_movie'], [$group->categoryId, $group->matchedBy]);
            $movie = (new MovieCategorizer)->categorize($context);
            $this->assertSame((new MovieCategorizer)->categorizePhp($context)->categoryId, $movie->categoryId);
        }
    }

    public function test_php_reference_semantics(): void
    {
        $this->assertSame(
            ['total' => 9, 'comic' => 2, 'audiobook' => 1, 'magazine' => 1, 'ebook' => 1, 'unknown' => 4, 'retail' => 2],
            BookCategorizer::summarizeBookFormatBatch(['Batman.001.2024.Digital.CBZ', 'Author - Title (Unabridged) M4B', 'Some.Magazine.Weekly.Issue.12',
                'Author.Title.2024.RETAIL.EPUB', 'Manga.Vol.3.epub', 'Plain.Name', '', 'Retail.Only', null]),
        );

        $this->assertSame(
            ['total' => 8, 'playstation' => 2, 'xbox' => 1, 'nintendo' => 2, 'unknown' => 3, 'region' => 3],
            ConsoleCategorizer::summarizeConsolePlatformBatch(['Game.PS4-GRP', 'Game.XBOX360.PAL', 'Game.NSW.USA', 'Game.Wii.NTSC',
                'Some.PlayStation.Xbox.Mix', 'Plain.Name', '', 42]),
        );

        $this->assertSame(
            ['total' => 12, 'alt_binaries' => 9, 'anime' => 1, 'tv' => 1, 'movie' => 1, 'xxx' => 1, 'music' => 1, 'game' => 1, 'pc' => 1, 'book' => 1, 'other' => 4, 'empty' => 2],
            GroupNameCategorizer::summarizeGroupNameBatch(['alt.binaries.anime', 'ALT.BINARIES.HDTV.X264', 'alt.binaries.movies.divx', 'alt.binaries.erotica',
                'alt.binaries.sounds.mp3', 'alt.binaries.games.xbox', 'alt.binaries.warez', 'alt.binaries.e-book', 'alt.binaries.misc', 'alt.binaries', '', false]),
        );

        $this->assertSame(
            ['total' => 10, 'hash' => 2, 'archive' => 1, 'dataset' => 1, 'other' => 6, 'empty' => 3],
            MiscCategorizer::summarizeMiscContentBatch(['d41d8cd98f00b204e9800998ecf8427e', "  DA39A3EE5E6B4B0D3255BFEF95601890AFD80709\t",
                'd41d8cd98f00b204e9800998ecf8427', 'g41d8cd98f00b204e9800998ecf8427e', 'Some.Archive.part01.RAR', 'Customer.Dataset.csv', 'Plain.Name', " \0 ", '', ['x']]),
        );
        // trim() leaves form feeds, so the 33-byte name is no hash.
        $this->assertSame(0, MiscCategorizer::summarizeMiscContentBatch(["d41d8cd98f00b204e9800998ecf8427e\x0C"])['hash']);
        $this->assertSame(1, MiscCategorizer::summarizeMiscContentBatch([str_repeat('aB', 32)])['hash']);

        $this->assertSame(
            ['total' => 8, 'uhd' => 2, 'hd' => 2, 'sd' => 1, 'unknown' => 3, 'hevc' => 2, 'bluray' => 2],
            MovieCategorizer::summarizeMovieQualityBatch(['Movie.2024.2160p.UHD.BluRay.x265-GRP', 'Movie.2024.1080p.WEB-DL.H.265-GRP',
                'Movie.2024.720p.BDRip.x264-GRP', 'Movie.2004.DVDRip.XviD-GRP', 'Movie.4K.HDR', 'Plain.Name', '', 1.5]),
        );

        foreach (self::HELPERS as [$class, $method]) {
            $empty = $class::{$method.'Php'}([]);
            $this->assertSame(array_fill_keys(array_keys($empty), 0), $empty, $class);
        }
    }

    public function test_edge_cases_match_between_php_and_rust(): void
    {
        $this->ffiOrSkip();
        $texts = self::TEXTS;
        foreach (array_keys(self::HELPERS) as $helper) {
            $this->assertParity($helper, []);
            $this->assertParity($helper, $texts);
            $this->assertParity($helper, ['a' => 'alt.binaries.anime', 'b' => 'd41d8cd98f00b204e9800998ecf8427e', 'c' => 'PS4.2160p.EPUB']);
            $this->assertParity($helper, [str_repeat('alt.binaries.Movie.2160p.PS4.EPUB.rar-GRP ', 64)]);
            foreach ($texts as $index => $text) {
                $this->assertParity($helper, [$text]);
                $this->assertParity($helper, [strtoupper($text), ' '.$text.' ']);
                $this->assertParity($helper, array_slice([...$texts, ...$texts], $index, 1 + $index % 13));
            }
        }
    }

    public function test_randomized_inputs_match_between_php_and_rust(): void
    {
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(4231));
        $alphabet = ['a', 'b', 'c', 'd', 'e', 'f', 'A', 'F', 'g', 'x', 'p', 's', '0', '1', '2', '4', '9', '.', '-', '_', ' ', "\t", "\n", "\r", "\0", "\x0B", "\x0C",
            "\x1F", "\xff", "\xc3\xa9"];
        $text = static function () use ($random, $alphabet): string {
            if ($random->getInt(0, 9) === 0) {
                $hex = bin2hex($random->getBytes([16, 20, 32, 19][$random->getInt(0, 3)]));

                return match ($random->getInt(0, 3)) {
                    0 => strtoupper($hex),
                    1 => " \t".$hex."\0",
                    2 => $hex.'g',
                    default => $hex,
                };
            }
            $out = '';
            for ($i = $random->getInt(0, 20); $i > 0; $i--) {
                $out .= $random->getInt(0, 2) === 0
                    ? self::WORDS[$random->getInt(0, count(self::WORDS) - 1)]
                    : $alphabet[$random->getInt(0, count($alphabet) - 1)];
            }

            return match ($random->getInt(0, 5)) {
                0 => strtoupper($out),
                1 => ' '.$out."\t",
                default => $out,
            };
        };

        for ($i = 0; $i < 1000; $i++) {
            foreach (array_keys(self::HELPERS) as $helper) {
                $list = [];
                for ($n = $random->getInt(0, 30); $n > 0; $n--) {
                    $list[] = $random->getInt(0, 3) === 0 ? self::TEXTS[$random->getInt(0, count(self::TEXTS) - 1)] : $text();
                }
                $this->assertParity($helper, $list);
            }
        }
    }

    public function test_malformed_inputs_fall_back_to_php(): void
    {
        $oversized = str_repeat('alt.binaries.Movie.2160p.PS4.EPUB.rar-GRP ', 420_000);
        foreach (self::TARGETS as $class) {
            $class::useW423RustFfi(true);
        }
        foreach (self::HELPERS as [$class, $method]) {
            foreach ([['Movie.2160p', null], [1, 'x'], [1.5], [true], [['nested']], ['a', new \stdClass]] as $items) {
                $this->assertNull($class::{$method.'Native'}($items), $class);
                $this->assertSame($class::{$method.'Php'}($items), $class::$method($items), $class);
            }
            // Batches over the 16 MiB native input cap decline to PHP.
            $this->assertNull($class::{$method.'Native'}([$oversized]), $class);
            $this->assertSame($class::{$method.'Php'}([$oversized]), $class::$method([$oversized]), $class);
        }
    }

    public function test_native_exports_validate_input_and_capacity(): void
    {
        $ffi = $this->ffiOrSkip();

        $small = $ffi->new('char[4]');
        $out = $ffi->new('char[256]');
        foreach (self::HELPERS as [$class, $method, $symbol]) {
            $items = ['alt.binaries.PS4.2160p', 'hash_x'];
            $expected = implode("\x1F", $class::{$method.'Php'}($items));
            $args = ['alt.binaries.PS4.2160phash_x', 28, pack('V2', 22, 6), 8];
            // Tiny capacity reports -required without writing, then a retry at that size succeeds.
            FFI::memset($small, ord('Z'), 4);
            $needed = $this->callFfi($ffi, $symbol, ...[...$args, $small, 2]);
            $this->assertSame(-strlen($expected), $needed, $symbol);
            $this->assertSame('ZZZZ', FFI::string($small, 4), $symbol);
            $buffer = $ffi->new('char['.(-$needed).']');
            $this->assertSame(-$needed, $this->callFfi($ffi, $symbol, ...[...$args, $buffer, -$needed]), $symbol);
            $this->assertSame($expected, FFI::string($buffer, -$needed), $symbol);
            // Null buffers, null inputs and inconsistent lengths decline; empty lists succeed.
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, ...[...$args, null, 64]), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, null, 0, '', 0, $out, 256), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, '', 0, null, 0, $out, 256), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, 'x', 1, pack('V', 2), 4, $out, 256), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, 'xy', 2, pack('V', 1), 4, $out, 256), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, 'x', 1, pack('V', 1).'z', 5, $out, 256), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, 'x', 1, pack('V', 0xFFFFFFFF), 4, $out, 256), $symbol);
            $this->assertGreaterThan(0, $this->callFfi($ffi, $symbol, '', 0, '', 0, $out, 256), $symbol);

            // An output buffer overlapping an input declines without writing to it.
            $shared = $ffi->new('char[64]');
            FFI::memcpy($shared, 'sample'.pack('V', 6), 10);
            $input = FFI::addr($shared[0]);
            $lens = FFI::addr($shared[6]);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, $input, 6, $lens, 4, FFI::addr($shared[3]), 40), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, $input, 6, $lens, 4, FFI::addr($shared[9]), 40), $symbol);
            $this->assertSame('sample'.pack('V', 6), FFI::string($shared, 10), $symbol);
            $this->assertGreaterThan(0, $this->callFfi($ffi, $symbol, $input, 6, $lens, 4, FFI::addr($shared[10]), 40), $symbol);
        }

        foreach (self::HELPERS as [$class, $method]) {
            $class::useW423RustFfi(true);
            $this->assertNotNull($class::{$method.'Native'}(['alt.binaries.PS4.2160p.EPUB', 'hash_x']), $class);
            $this->assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'w423RustFfiHandle'))->getValue(), $class.' must resolve its native export');
        }
    }

    public function test_missing_library_falls_back_to_php(): void
    {
        $items = ['alt.binaries.PS4.2160p.EPUB', 'd41d8cd98f00b204e9800998ecf8427e', ''];
        foreach (self::HELPERS as [$class, $method]) {
            (new ReflectionProperty($class, 'w423RustFfiHandle'))->setValue(null, false);
            $class::useW423RustFfi(true);
            $this->assertNull($class::{$method.'Native'}($items), $class);
            $this->assertSame($class::{$method.'Php'}($items), $class::$method($items), $class);
            $class::resetW423RustFfi();
            $this->assertNull((new ReflectionProperty($class, 'w423RustFfiHandle'))->getValue(), $class);
        }
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of each helper through its public dispatcher, including
        // the PHP-side type checks, packing, FFI call and decoding (release dylib, 5 warmups, 301
        // samples per helper and workload; 40 iterations per common sample, 4 per uncommon sample).
        // Common = 32 typical scene release names (32 group names, 32 mixed hash/archive/dataset
        // names for misc); uncommon = 2000 padded, mixed-case, marker-free long names (2000 long
        // uppercase group names for group).
        // Speedup = PHP latency / Rust latency, ranges over 3 rounds (PHP 8.5.12 NTS, Apple M5, shared
        // host at load average ~3.8):
        // - book:    common mean 5.63-5.81x / p99 5.15-5.52x, uncommon mean 9.53-11.00x / p99 6.51-10.56x
        // - console: common mean 6.18-6.29x / p99 4.46-5.98x, uncommon mean 5.12-5.85x / p99 4.15-5.75x
        // - group:   common mean 6.18-6.21x / p99 4.57-6.00x, uncommon mean 12.15-12.95x / p99 11.42-12.68x
        // - misc:    common mean 2.85-3.02x / p99 2.05-3.48x, uncommon mean 7.20-8.33x / p99 5.68-8.10x
        // - movie:   common mean 4.65-5.16x / p99 4.64-7.12x, uncommon mean 4.16-4.85x / p99 3.60-4.88x
        // All five clear 1.5x on every measure in every round, so all five default to Rust.
        // Reproduce:
        // cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
        // php artisan test --compact tests/Unit/RustCategorizersBookConsoleGroupMiscMovieW42ParityTest.php --filter benchmark
        // Each helper defaults to Rust only when it clears 1.5x on both workloads' mean and p99,
        // per .ai/rules/rust-performance.md.
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(4232));
        $warmup = 5;
        $samples = 301;
        $sink = 0;

        $speedups = [];
        foreach (self::HELPERS as $helper => [$class, $method]) {
            foreach (['common' => [$this->commonWorkload($helper), 40], 'uncommon' => [$this->uncommonWorkload($helper), 4]] as $name => [$items, $iterations]) {
                for ($w = 0; $w < $warmup; $w++) {
                    $sink += $this->runWorkload($class, $method, $items, false) + $this->runWorkload($class, $method, $items, true);
                }
                $this->assertSame($class::{$method.'Php'}($items), $class::{$method.'Native'}($items), $helper.' '.$name);
                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < $samples; $s++) {
                    $order = $random->getInt(0, 1) === 1 ? [true, false] : [false, true];
                    foreach ($order as $useRust) {
                        $start = hrtime(true);
                        for ($i = 0; $i < $iterations; $i++) {
                            $sink += $this->runWorkload($class, $method, $items, $useRust);
                        }
                        $times[$useRust ? 'rust' : 'php'][] = (hrtime(true) - $start) / 1_000 / $iterations;
                    }
                }
                $class::resetW423RustFfi();
                $speedups[$helper][$name] = [
                    'mean' => round($this->mean($times['php']) / $this->mean($times['rust']), 3),
                    'p99' => round($this->p99($times['php']) / $this->p99($times['rust']), 3),
                    'php_mean_us' => round($this->mean($times['php']), 2),
                    'rust_mean_us' => round($this->mean($times['rust']), 2),
                    'samples' => $samples,
                ];
            }
        }

        $this->assertGreaterThan(0, $sink);
        $summary = json_encode($speedups, JSON_PRESERVE_ZERO_FRACTION) ?: '';
        fwrite(STDERR, "\nRustCategorizersBookConsoleGroupMiscMovieW42 speedup: {$summary}\n");
        foreach (self::HELPERS as $helper => [$class]) {
            $qualifies = true;
            foreach ($speedups[$helper] as $speedup) {
                $qualifies = $qualifies && $speedup['mean'] >= 1.5 && $speedup['p99'] >= 1.5;
                $this->assertGreaterThan(0.0, $speedup['mean'], $helper);
                $this->assertGreaterThan(0.0, $speedup['p99'], $helper);
            }
            if (! $qualifies) {
                $this->assertFalse(constant($class.'::W423_RUST_FFI_DEFAULT'), $class.' must keep PHP below 1.5x: '.$summary);
                $this->assertFalse($class::usesW423RustFfi(), $class.' must keep PHP below 1.5x: '.$summary);
            }
            if (constant($class.'::W423_RUST_FFI_DEFAULT')) {
                $this->assertTrue($qualifies, $class.' defaults to Rust only at 1.5x or more: '.$summary);
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $items
     */
    private function assertParity(string $helper, array $items): void
    {
        [$class, $method] = self::HELPERS[$helper];
        $expected = $class::{$method.'Php'}($items);
        $native = $class::{$method.'Native'}($items);
        $this->assertNotNull($native, $helper.' declined: '.json_encode($this->describe($items)));
        $this->assertSame($expected, $native, $helper.': '.json_encode($this->describe($items)));
        $class::useW423RustFfi(true);
        $this->assertSame($expected, $class::$method($items), $helper.' dispatcher');
        $class::resetW423RustFfi();
    }

    private function describe(mixed $value): mixed
    {
        return match (true) {
            is_string($value) => bin2hex($value),
            is_array($value) => array_map($this->describe(...), $value),
            default => $value,
        };
    }

    /**
     * @return list<string>
     */
    private function commonWorkload(string $helper): array
    {
        if ($helper === 'group') {
            return array_map(static fn (int $i): string => ['alt.binaries.teevee', 'alt.binaries.hdtv.x264', 'alt.binaries.movies.divx', 'alt.binaries.boneless',
                'alt.binaries.multimedia', 'alt.binaries.sounds.mp3', 'alt.binaries.e-book', 'alt.binaries.games', 'alt.binaries.warez', 'alt.binaries.misc',
                'alt.binaries.anime', 'a.b.erotica'][$i % 12], range(1, 32));
        }
        if ($helper === 'misc') {
            return array_map(static fn (int $i): string => match ($i % 4) {
                0 => md5((string) $i),
                1 => 'Some.Release.Name.'.$i.'.part01.rar',
                2 => 'Database.Backup.'.(2000 + $i).'.sql',
                default => 'Movie.Title.'.(1980 + $i).'.1080p.BluRay.x264-GRP',
            }, range(1, 32));
        }

        return array_map(static fn (int $i): string => match ($i % 8) {
            0 => 'Some.Show.S01E'.sprintf('%02d', $i).'.720p.WEB.h264-GRP',
            1 => 'Movie.Title.'.(1980 + $i).'.2160p.UHD.BluRay.x265-GRP',
            2 => 'Author - Book Title ('.(2000 + $i).') RETAIL EPUB',
            3 => 'Batman.'.sprintf('%03d', $i).'.2024.Digital.CBZ',
            4 => 'Game.Title.'.$i.'.PS4-DUPLEX',
            5 => 'Game.Title.'.$i.'.NSW.USA-VENOM',
            6 => 'Movie Title '.(1980 + $i).' 1080p BluRay x264-GRP',
            default => 'Some.Magazine.Weekly.Issue.'.$i.'.PDF',
        }, range(1, 32));
    }

    /**
     * @return list<string>
     */
    private function uncommonWorkload(string $helper): array
    {
        if ($helper === 'group') {
            return array_map(static fn (int $i): string => 'ALT.BINARIES.'.strtoupper(str_repeat('mirror.', 4)).'group'.$i, range(1, 2000));
        }

        return array_map(static fn (int $i): string => '  '.strtoupper(str_repeat('Obfuscated.Mirror.Release.', 6)).'Part'.$i.'.Of.2000.Name  ', range(1, 2000));
    }

    /**
     * @param  class-string  $class
     * @param  list<string>  $items
     */
    private function runWorkload(string $class, string $method, array $items, bool $useRust): int
    {
        $class::useW423RustFfi($useRust);

        return count($class::$method($items));
    }

    /**
     * @param  list<float>  $values
     */
    private function mean(array $values): float
    {
        return array_sum($values) / max(1, count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private function p99(array $values): float
    {
        sort($values);

        return $values[(int) min(count($values) - 1, (int) ceil(count($values) * 0.99) - 1)];
    }

    private function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function ffiOrSkip(): FFI
    {
        $cdef = implode("\n", array_map(static fn (string $class): string => constant($class.'::W423_FFI_CDEF'), self::TARGETS));
        $ffi = RustPipelineLibrary::resolveDomain('categorize_w423', $cdef);
        if ($ffi === null) {
            $this->markTestSkipped('libnntmux_pipeline with the Wave 42.3 exports is not available.');
        }

        return $ffi;
    }
}
