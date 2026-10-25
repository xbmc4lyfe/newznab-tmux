<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Categorization\Categorizers\MusicCategorizer;
use App\Services\Categorization\Categorizers\PcCategorizer;
use App\Services\Categorization\Categorizers\TvCategorizer;
use App\Services\Categorization\Categorizers\XxxCategorizer;
use App\Services\Categorization\Pipes\XxxPipe;
use App\Services\Categorization\ReleaseContext;
use App\Support\RustPipelineLibrary;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionProperty;
use Tests\TestCase;

final class RustCategorizersMusicPcTvXxxPipeW43ParityTest extends TestCase
{
    /** Every Wave 43.3 target shares the W433 toggle names and keeps its existing native paths separate. */
    private const TARGETS = [
        MusicCategorizer::class,
        PcCategorizer::class,
        TvCategorizer::class,
        XxxCategorizer::class,
        XxxPipe::class,
    ];

    /** Production defaults: every helper clears 1.5x on every measure of the paired benchmark below. */
    private const DEFAULTS = [
        MusicCategorizer::class => true,
        PcCategorizer::class => true,
        TvCategorizer::class => true,
        XxxCategorizer::class => true,
        XxxPipe::class => true,
    ];

    /** Helper per target: [class, method, export]. */
    private const HELPERS = [
        'music' => [MusicCategorizer::class, 'summarizeMusicContentBatch', 'nntmux_w433_music_content_batch'],
        'pc' => [PcCategorizer::class, 'summarizePcPlatformBatch', 'nntmux_w433_pc_platform_batch'],
        'tv' => [TvCategorizer::class, 'summarizeTvContentBatch', 'nntmux_w433_tv_content_batch'],
        'xxx' => [XxxCategorizer::class, 'summarizeXxxFormatBatch', 'nntmux_w433_xxx_format_batch'],
        'pipe' => [XxxPipe::class, 'summarizeAdultSignalBatch', 'nntmux_w433_xxx_pipe_adult_signal_batch'],
    ];

    /** Strings that exercise case, markers, truncated markers, separators, NUL bytes and invalid UTF-8. */
    private const TEXTS = [
        '', ' ', "\0", "\x1F", "\t\n", "\x0B", "\x0C", "\r", '-', '.', '_', ' . ', 'a', 'x-', 'Author - Title (Unabridged) AUDIOBOOK.M4B', 'Some.Podcast.Episode.12.MP3',
        'Artist-Live_Concert-1080p-MVID', 'Artist-Album-2024-24BIT-FLAC', 'Artist-Album-VINYL-FLAC', 'Artist-Album-WEB-320kbps', 'Hoerbuch', 'Radio.Show', 'fla', 'vinyl', 'Hi-Res',
        'App.v1.2.iPhone.iPad', 'App.v3.Android.APK', 'App.2024.macOS.DMG', 'Game.Title.MULTi5-CODEX', 'Office.2024.x64.ISO', 'Tool.v2.Win64.Incl.Keygen', 'Disc.ISO', 'mult',
        'GOG', 'Mac.OS.X', 'x6', '.ip', 'FitGirl.Repack', '[SubsPlease] Show - 01 (1080p)', 'NFL.2024.Week.1.Team.vs.Team.720p.WEB-DL', 'Nat.Geo.Documentary.2024.1080p',
        'Show.S01E01.2160p.WEBRip.x265', 'Show.S01E01.1080p.WEB.DL.H264', 'Show.S01E01.PDTV.XviD', 'Show.S01E01.4k', 'x.2160', 'web-d', 'Erai-raws', 'BBC.Earth',
        'OnlyFans.Model.Name.2024.1080p', 'Site.24.01.01.Model.VR180.Oculus', 'Site.24.01.01.Model.2160p.MP4', 'Site.24.01.01.Model.IMAGESET', 'Studio.Title.DVDRip',
        'Site.24.01.01.Model.480p.XviD', 'Site.SITERIP.Pack', 'SideBySide', 'dvd', 'PSVR', '.WMV', 'Brazzers.24.01.01.Model.XXX.1080p', '1Pondo.012424_001.XXX',
        'Some.MILF.Hardcore.Scene', 'Studio.XXX.Title', 'VIXEN.Lesbian', 'FC2-PPV-1234567', 'a.xx', 'Plain.Name', "name-GR\xffP", 'Ünïcödé.FLAC', "bad \xff utf8", "\xc3",
        "FL\xc3\x81C", "4\xffk",
    ];

    /** Fragments for random names: markers, separators and noise. */
    private const WORDS = ['Show', 'Movie', 'Artist', 'audiobook', 'audible', '.m4b', 'podcast', 'mvid', 'concert', 'flac', 'FLAC', 'alac', '24bit', 'hi-res', 'mp3', '320kbps',
        'vbr', 'vinyl', 'iphone', 'iPad', 'android', '.apk', 'macos', 'osx', '.dmg', 'pc.game', 'steamrip', 'CODEX', 'plaza', 'gog', '.iso', '.img', 'dvd9', 'keygen', 'win64',
        'x64', 'x86', 'portable', 'multi', 'anime', 'SubsPlease', 'nfl', 'ufc', 'formula1', 'docu', 'nat.geo', '2160p', 'uhd', '4k', '1080p', '720p', '480p', 'sdtv', 'pdtv',
        'dvdrip', 'xvid', 'web-dl', 'webrip', 'web.dl', 'onlyfans', 'fansly', 'vr180', 'oculus', 'psvr', 'imageset', 'pics', 'dvdr', '.wmv', 'mp4', 'pack', 'siterip',
        'brazzers', 'vixen', 'tushy', '1pondo', 'heyzo', 'porn', 'milf', 'lesbian', 'xxx', 'XXX', '-GRP'];

    protected function tearDown(): void
    {
        foreach (self::TARGETS as $class) {
            $class::resetW433RustFfi();
        }
        XxxPipe::setUseRustFfi(false);
        parent::tearDown();
    }

    public function test_rust_ffi_defaults_and_toggles(): void
    {
        foreach (self::TARGETS as $class) {
            $reflection = new ReflectionClass($class);
            foreach (['useW433RustFfi', 'usesW433RustFfi', 'resetW433RustFfi'] as $method) {
                $this->assertTrue($reflection->hasMethod($method), $class.'::'.$method);
            }
            $this->assertFalse($reflection->isReadOnly(), $class);
            $default = self::DEFAULTS[$class];
            $this->assertSame($default, constant($class.'::W433_RUST_FFI_DEFAULT'), $class);
            $this->assertSame($default, $class::usesW433RustFfi(), $class);
            $class::useW433RustFfi();
            $this->assertTrue($class::usesW433RustFfi(), $class);
            $class::useW433RustFfi(false);
            $this->assertFalse($class::usesW433RustFfi(), $class);
            $class::useW433RustFfi(null);
            $this->assertSame($default, $class::usesW433RustFfi(), $class);
            $class::useW433RustFfi(true);
            $this->assertTrue($class::usesW433RustFfi(), $class);
            $class::resetW433RustFfi();
            $this->assertNull((new ReflectionProperty($class, 'w433RustFfiOverride'))->getValue(), $class);
            $this->assertNull((new ReflectionProperty($class, 'w433RustFfiHandle'))->getValue(), $class);
            $this->assertSame($default, $class::usesW433RustFfi(), $class);
        }

        // The W433 toggles never touch the classes' existing categorization paths or pipe toggle.
        $xxx = new ReleaseContext('Brazzers.24.01.01.Model.Name.XXX.1080p.MP4-GRP', 1, 'alt.binaries.erotica');
        $tv = new ReleaseContext('Show.Name.S01E01.1080p.WEB-DL.H264-GRP', 1, 'alt.binaries.teevee');
        foreach ([true, false] as $enable) {
            foreach (self::TARGETS as $class) {
                $class::useW433RustFfi($enable);
            }
            $this->assertFalse(XxxPipe::usesRustFfi());
            $this->assertSame('Music', (new MusicCategorizer)->getName());
            $this->assertSame('PC', (new PcCategorizer)->getName());
            $this->assertSame('TV', (new TvCategorizer)->getName());
            $this->assertSame('XXX', (new XxxCategorizer)->getName());
            $this->assertSame('XXX', (new XxxPipe)->getName());
            $this->assertSame((new XxxCategorizer)->categorizePhp($xxx)->categoryId, (new XxxCategorizer)->categorize($xxx)->categoryId);
            $this->assertSame((new TvCategorizer)->categorizePhp($tv)->categoryId, (new TvCategorizer)->categorize($tv)->categoryId);
        }
    }

    public function test_php_reference_semantics(): void
    {
        $this->assertSame(
            ['total' => 10, 'audiobook' => 1, 'podcast' => 1, 'video' => 1, 'lossless' => 2, 'mp3' => 1, 'unknown' => 4, 'vinyl' => 2],
            MusicCategorizer::summarizeMusicContentBatch(['Author - Title (Unabridged) AUDIOBOOK.M4B', 'Some.Podcast.Episode.12.MP3', 'Artist-Live_Concert-1080p-MVID',
                'Artist-Album-2024-24BIT-FLAC', 'Artist-Album-VINYL-FLAC', 'Artist-Album-WEB-320kbps', 'Artist-Album-Vinyl-Rip', 'Plain.Name', '', null]),
        );

        $this->assertSame(
            ['total' => 10, 'phone' => 2, 'mac' => 1, 'game' => 1, 'iso' => 1, 'zeroday' => 1, 'unknown' => 4, 'multi' => 2],
            PcCategorizer::summarizePcPlatformBatch(['App.v1.2.iPhone.iPad', 'App.v3.Android.APK', 'App.2024.macOS.DMG', 'Game.Title.MULTi5-CODEX',
                'Office.2024.x64.ISO', 'Tool.v2.Win64.Incl.Keygen', 'Some.Title.MULTILINGUAL', 'Plain.Name', '', 42]),
        );

        $this->assertSame(
            ['total' => 11, 'anime' => 1, 'sport' => 1, 'documentary' => 1, 'uhd' => 2, 'hd' => 1, 'sd' => 1, 'unknown' => 4, 'web' => 4],
            TvCategorizer::summarizeTvContentBatch(['[SubsPlease] Show - 01 (1080p)', 'NFL.2024.Week.1.Team.vs.Team.720p.WEB-DL', 'Nat.Geo.Documentary.2024.1080p',
                'Show.S01E01.2160p.WEBRip.x265', 'Show.S01E01.1080p.WEB.DL.H264', 'Show.S01E01.PDTV.XviD', 'Show.S01E01.WEB-DL', 'Plain.Name', 'Show.S01E01.4k', '', false]),
        );

        $this->assertSame(
            ['total' => 11, 'onlyfans' => 1, 'vr' => 1, 'uhd' => 1, 'hd' => 1, 'imageset' => 1, 'dvd' => 1, 'sd' => 1, 'unknown' => 4, 'pack' => 1],
            XxxCategorizer::summarizeXxxFormatBatch(['OnlyFans.Model.Name.2024.1080p', 'Site.24.01.01.Model.VR180.Oculus', 'Site.24.01.01.Model.2160p.MP4',
                'Site.24.01.01.Model.720p', 'Site.24.01.01.Model.IMAGESET', 'Studio.Title.DVDRip', 'Site.24.01.01.Model.480p.XviD', 'Site.SITERIP.Pack', 'Plain.Name', '', ['x']]),
        );

        $this->assertSame(
            ['total' => 8, 'studio' => 2, 'jav' => 1, 'keyword' => 1, 'unknown' => 4, 'xxx' => 3],
            XxxPipe::summarizeAdultSignalBatch(['Brazzers.24.01.01.Model.XXX.1080p', '1Pondo.012424_001.XXX', 'Some.MILF.Hardcore.Scene', 'Studio.XXX.Title',
                'VIXEN.Lesbian', 'Plain.Name', '', 1.5]),
        );

        // Class order decides: ".iso" precedes the zero-day markers, "docu" precedes "1080p".
        $this->assertSame(1, PcCategorizer::summarizePcPlatformBatchPhp(['Disc.x64.ISO'])['iso']);
        $this->assertSame(1, TvCategorizer::summarizeTvContentBatchPhp(['Docu.1080p'])['documentary']);
        // strtolower() is ASCII-only, so a non-ASCII byte never forms a marker.
        $this->assertSame(1, MusicCategorizer::summarizeMusicContentBatchPhp(["FL\xc3\x81C"])['unknown']);

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
            $this->assertParity($helper, ['a' => 'Artist-Album-FLAC', 'b' => 'Game.MULTi-CODEX', 'c' => 'Brazzers.XXX.2160p']);
            $this->assertParity($helper, [str_repeat('Artist.FLAC.iPhone.WEB-DL.VR180.Brazzers.XXX-GRP ', 64)]);
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
        $random = new Randomizer(new Mt19937(4331));
        $alphabet = ['a', 'b', 'c', 'd', 'e', 'f', 'A', 'F', 'g', 'x', 'p', 's', 'v', 'r', '0', '1', '2', '4', '9', '.', '-', '_', ' ', "\t", "\n", "\0", "\x0B", "\x1F",
            "\xff", "\xc3\xa9"];
        $text = static function () use ($random, $alphabet): string {
            $out = '';
            for ($i = $random->getInt(0, 20); $i > 0; $i--) {
                $out .= $random->getInt(0, 2) === 0
                    ? self::WORDS[$random->getInt(0, count(self::WORDS) - 1)]
                    : $alphabet[$random->getInt(0, count($alphabet) - 1)];
            }

            return match ($random->getInt(0, 5)) {
                0 => strtoupper($out),
                1 => ' '.$out."\t",
                2 => ucwords($out, '.'),
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
        $oversized = str_repeat('Artist.FLAC.iPhone.WEB-DL.VR180.Brazzers.XXX-GRP ', 350_000);
        foreach (self::TARGETS as $class) {
            $class::useW433RustFfi(true);
        }
        foreach (self::HELPERS as [$class, $method]) {
            foreach ([['Artist.FLAC', null], [1, 'x'], [1.5], [true], [['nested']], ['a', new \stdClass]] as $items) {
                $this->assertNull($class::{$method.'Native'}($items), $class);
                $this->assertSame($class::{$method.'Php'}($items), $class::$method($items), $class);
            }
            // Batches over the 16 MiB native input cap decline to PHP.
            $this->assertGreaterThan(16 * 1024 * 1024, strlen($oversized));
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
            $items = ['Artist.FLAC.iPhone.4k', 'hash_x'];
            $expected = implode("\x1F", $class::{$method.'Php'}($items));
            $args = ['Artist.FLAC.iPhone.4khash_x', 27, pack('V2', 21, 6), 8];
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
            $class::useW433RustFfi(true);
            $this->assertNotNull($class::{$method.'Native'}(['Artist.FLAC.iPhone.4k', 'hash_x']), $class);
            $this->assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'w433RustFfiHandle'))->getValue(), $class.' must resolve its native export');
        }
    }

    public function test_missing_library_falls_back_to_php(): void
    {
        $items = ['Artist.FLAC.iPhone.4k', 'Brazzers.XXX', ''];
        foreach (self::HELPERS as [$class, $method]) {
            (new ReflectionProperty($class, 'w433RustFfiHandle'))->setValue(null, false);
            $class::useW433RustFfi(true);
            $this->assertNull($class::{$method.'Native'}($items), $class);
            $this->assertSame($class::{$method.'Php'}($items), $class::$method($items), $class);
            $class::resetW433RustFfi();
            $this->assertNull((new ReflectionProperty($class, 'w433RustFfiHandle'))->getValue(), $class);
        }
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of each helper through its public dispatcher, including
        // the PHP-side type checks, packing, FFI call and decoding (release dylib, 5 warmups, 301
        // samples per helper and workload; 40 iterations per common sample, 4 per uncommon sample).
        // Common = 32 typical release names for the helper's domain; uncommon = 2000 padded,
        // mixed-case, marker-free long names.
        // Speedup = PHP latency / Rust latency, ranges over 3 rounds (PHP 8.5.12 NTS, Apple M5, shared
        // host at load average ~6.0):
        // - music: common mean 4.11-4.56x / p99 3.33-3.67x, uncommon mean 6.07-7.21x / p99 4.89-6.45x
        // - pc:    common mean 4.26-4.61x / p99 3.39-4.36x, uncommon mean 10.28-11.91x / p99 7.50-10.63x
        // - tv:    common mean 4.79-5.05x / p99 3.43-4.65x, uncommon mean 7.70-7.76x / p99 6.23-6.82x
        // - xxx:   common mean 4.55-4.70x / p99 3.76-4.43x, uncommon mean 8.04-8.12x / p99 7.08-8.18x
        // - pipe:  common mean 3.98-4.08x / p99 3.18-3.98x, uncommon mean 5.00-5.21x / p99 3.79-4.72x
        // All five clear 1.5x on every measure in every round, so all five default to Rust.
        // Reproduce:
        // cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
        // php artisan test --compact tests/Unit/RustCategorizersMusicPcTvXxxPipeW43ParityTest.php --filter benchmark
        // Each helper defaults to Rust only when it clears 1.5x on both workloads' mean and p99,
        // per .ai/rules/rust-performance.md.
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(4332));
        $warmup = 5;
        $samples = 301;
        $sink = 0;

        $speedups = [];
        foreach (self::HELPERS as $helper => [$class, $method]) {
            foreach (['common' => [$this->commonWorkload($helper), 40], 'uncommon' => [$this->uncommonWorkload(), 4]] as $name => [$items, $iterations]) {
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
                $class::resetW433RustFfi();
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
        fwrite(STDERR, "\nRustCategorizersMusicPcTvXxxPipeW43 speedup: {$summary}\n");
        foreach (self::HELPERS as $helper => [$class]) {
            $qualifies = true;
            foreach ($speedups[$helper] as $speedup) {
                $qualifies = $qualifies && $speedup['mean'] >= 1.5 && $speedup['p99'] >= 1.5;
                $this->assertGreaterThan(0.0, $speedup['mean'], $helper);
                $this->assertGreaterThan(0.0, $speedup['p99'], $helper);
            }
            if (! $qualifies) {
                $this->assertFalse(constant($class.'::W433_RUST_FFI_DEFAULT'), $class.' must keep PHP below 1.5x: '.$summary);
                $this->assertFalse($class::usesW433RustFfi(), $class.' must keep PHP below 1.5x: '.$summary);
            }
            if (constant($class.'::W433_RUST_FFI_DEFAULT')) {
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
        $class::useW433RustFfi(true);
        $this->assertSame($expected, $class::$method($items), $helper.' dispatcher');
        $class::useW433RustFfi(false);
        $this->assertSame($expected, $class::$method($items), $helper.' php dispatcher');
        $class::resetW433RustFfi();
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
        $patterns = match ($helper) {
            'music' => ['Artist-Album-%d-WEB-FLAC-GRP', 'Artist-Album-%d-320kbps-MP3', 'Author - Title (%d) Unabridged M4B', 'Artist-Live_Concert-%d-1080p-MVID',
                'Some.Podcast.Episode.%d', 'Artist-Album-%d-VINYL-24BIT-FLAC', 'Various-Compilation-%d-CD-GRP', 'Artist-Single-%d-WEB-GRP'],
            'pc' => ['Game.Title.%d-CODEX', 'App.v%d.iPhone.iPad', 'App.v%d.Android.APK', 'App.%d.macOS.DMG', 'Office.%d.x64.ISO', 'Tool.v%d.Win64.Incl.Keygen',
                'Game.Title.%d.MULTi5-PLAZA', 'Utility.%d.Linux-GRP'],
            'tv' => ['Show.S01E%d.1080p.WEB-DL.H264-GRP', 'Show.S01E%d.720p.HDTV.x264-GRP', '[SubsPlease] Show - %d (1080p)', 'NFL.2024.Week.%d.720p',
                'Nature.Documentary.Part.%d.1080p', 'Show.S01E%d.2160p.WEBRip.x265-GRP', 'Show.S01E%d.PDTV.XviD-GRP', 'Show.S01E%d.HDTV-GRP'],
            'xxx' => ['Site.24.01.%d.Model.Name.XXX.1080p.MP4-GRP', 'OnlyFans.Model.%d.720p', 'Site.24.01.%d.Model.VR180.Oculus', 'Site.24.01.%d.Model.2160p',
                'Site.24.01.%d.Model.IMAGESET', 'Studio.Title.%d.DVDRip.XviD', 'Site.%d.SITERIP.Pack', 'Studio.Title.%d.XXX-GRP'],
            default => ['Brazzers.24.01.%d.Model.Name.XXX.1080p.MP4-GRP', 'Vixen.24.01.%d.Model.XXX.2160p', '1Pondo.%d_001.XXX.720p', 'Some.MILF.Scene.%d.XXX',
                'Site.24.01.%d.Model.Name.1080p', 'TeamSkeet.%d.Lesbian.Scene', 'Heyzo.%d.Model', 'Studio.Title.%d.WEB-GRP'],
        };

        return array_map(static fn (int $i): string => sprintf($patterns[$i % 8], $i), range(1, 32));
    }

    /**
     * @return list<string>
     */
    private function uncommonWorkload(): array
    {
        return array_map(static fn (int $i): string => '  '.strtoupper(str_repeat('Obfuscated.Mirror.Release.', 6)).'Part'.$i.'.Of.2000.Name  ', range(1, 2000));
    }

    /**
     * @param  class-string  $class
     * @param  list<string>  $items
     */
    private function runWorkload(string $class, string $method, array $items, bool $useRust): int
    {
        $class::useW433RustFfi($useRust);

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
        $cdef = implode("\n", array_map(static fn (string $class): string => constant($class.'::W433_FFI_CDEF'), self::TARGETS));
        $ffi = RustPipelineLibrary::resolveDomain('categorize_w433', $cdef);
        if ($ffi === null) {
            $this->markTestSkipped('libnntmux_pipeline with the Wave 43.3 exports is not available.');
        }

        return $ffi;
    }
}
