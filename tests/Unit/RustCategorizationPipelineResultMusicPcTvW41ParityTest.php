<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Categorization\CategorizationPipeline;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\Pipes\MusicPipe;
use App\Services\Categorization\Pipes\PcPipe;
use App\Services\Categorization\Pipes\TvPipe;
use App\Support\RustPipelineLibrary;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;
use Tests\TestCase;

final class RustCategorizationPipelineResultMusicPcTvW41ParityTest extends TestCase
{
    /** Every Wave 41.3 target shares the W413 toggle names and keeps its existing toggles separate. */
    private const TARGETS = [
        CategorizationPipeline::class,
        CategorizationResult::class,
        MusicPipe::class,
        PcPipe::class,
        TvPipe::class,
    ];

    /** Production defaults: only the audio format and PC platform helpers clear 1.5x on every measure. */
    private const DEFAULTS = [
        CategorizationPipeline::class => false,
        CategorizationResult::class => false,
        MusicPipe::class => true,
        PcPipe::class => true,
        TvPipe::class => false,
    ];

    /** Helper per target: [class, method, export]. */
    private const HELPERS = [
        'shape' => [CategorizationPipeline::class, 'summarizeReleaseNameShapeBatch', 'nntmux_w413_release_name_shape_batch'],
        'matched' => [CategorizationResult::class, 'summarizeMatchedByBatch', 'nntmux_w413_matched_by_batch'],
        'audio' => [MusicPipe::class, 'summarizeAudioFormatBatch', 'nntmux_w413_audio_format_batch'],
        'platform' => [PcPipe::class, 'summarizePcPlatformBatch', 'nntmux_w413_pc_platform_batch'],
        'episode' => [TvPipe::class, 'summarizeEpisodeTokenBatch', 'nntmux_w413_episode_token_batch'],
    ];

    /** Strings that exercise trimming, case, separators, markers, tokens, NUL bytes and invalid UTF-8. */
    private const TEXTS = [
        '', ' ', "\0", "\x1F", "\t\n", "\x0B", "\x0C", '-', '.', '_', ' . ', 'a', 'x-', '-x', 'a-bc', 'a-'.'0123456789abcde', 'a-'.'0123456789abcdef',
        'Movie.Title.2024.1080p.BluRay.x264-GRP', '  Some Show S01E01 720p-Group  ', 'Two Words.Here', 'a.b c', 'a b.c.d', "name-GR\xffP", 'name-GRP ',
        'no_match', 'none', 'NONE', 'hash_', 'hash_md5', 'hash', 'obfuscated_', 'obfuscated_pattern', 'gibberish_', 'gibberish_long', 'group_only_low_signal',
        'group_only_low_signal ', ' group_only_low_signal', 'tv_episode', '1', '01', '1.0', 'Artist-Album-2024-FLAC', 'Artist - Album [MP3 320kbps]',
        'Artist-Discography-MP3', 'Artist.Live.At.Wembley.MVID', 'Hi-Res.24BIT', 'Music.Video.Concert', 'VBRaac', 'OGGOPUS', 'fla', 'mp', 'Adobe.Photoshop.2024.Win64.Cracked',
        'Some.App.v1.MacOS.x64.dmg', 'Tool.v2.Linux.x64', 'Game.Android.APK', 'Office.2021.x86.ISO', 'ios.app', 'scenarios', 'x.CUE.bin', 'Keygen.Only',
        'Show.S01E02.720p', 'show s3.e104 x', 'Show.S12-E7', 'Show.S12--E7', 'Show.S12E', 'Show.2024.05.17.Guest', 'Show.S02.Complete', 'Show.S123E01',
        'xS01E01', 'Show.S01E1234', 'Show.2024.13.01', '12024.05.17', '2024.05.170', '1999-12-31', '1999-12-32', '2000_02_29', '2100.01.01', 'Show S1',
        's1', 's1e', 'S0E0', 's00e000', 's99e999', 'a.s02e05.s09e99', "\xffs01e02", "s01\xffe02", 'Ünïcödé.S01E01', "bad \xff utf8", "\xc3",
    ];

    /** Fragments for random names: markers, tokens, separators and noise. */
    private const WORDS = ['alt', 'Show', 'Movie', 'S01', 'E02', 's', 'e', '1', '12', '123', '2024', '1999', '05', '13', '31', '32', 'flac', 'FLAC', 'mp3',
        'aac', 'lossless', 'mvid', 'live.at', 'discography', 'macos', 'osx', '.dmg', 'linux', '.deb', 'android', '.apk', 'ios.', 'win64', 'x64', 'x86',
        '.iso', '.cue', 'crack', 'keygen', 'serial', 'no_match', 'none', 'hash_', 'obfuscated_', 'gibberish_', 'group_only_low_signal', '-GRP', '-x'];

    protected function tearDown(): void
    {
        foreach (self::TARGETS as $class) {
            $class::resetW413RustFfi();
        }
        parent::tearDown();
    }

    public function test_rust_ffi_defaults_and_toggles(): void
    {
        foreach (self::TARGETS as $class) {
            $default = self::DEFAULTS[$class];
            $this->assertSame($default, constant($class.'::W413_RUST_FFI_DEFAULT'), $class);
            $this->assertSame($default, $class::usesW413RustFfi(), $class);
            $class::useW413RustFfi();
            $this->assertTrue($class::usesW413RustFfi(), $class);
            $class::useW413RustFfi(false);
            $this->assertFalse($class::usesW413RustFfi(), $class);
            $class::useW413RustFfi(true);
            $this->assertTrue($class::usesW413RustFfi(), $class);
            $class::resetW413RustFfi();
            $this->assertNull((new ReflectionProperty($class, 'w413RustFfiOverride'))->getValue(), $class);
            $this->assertNull((new ReflectionProperty($class, 'w413RustFfiHandle'))->getValue(), $class);
            $this->assertSame($default, $class::usesW413RustFfi(), $class);
        }

        // The W413 toggles never touch the classes' existing Rust FFI toggles.
        foreach (self::TARGETS as $class) {
            $class::useW413RustFfi(! self::DEFAULTS[$class]);
        }
        $this->assertFalse(CategorizationResult::$useRustFfi);
        $this->assertFalse(MusicPipe::usesRustFfi());
        $this->assertFalse(PcPipe::usesRustFfi());
        $this->assertFalse(TvPipe::usesRustFfi());
        $this->assertSame('Music', MusicPipe::pipeName());
        $this->assertSame(30, PcPipe::pipePriority());
        $this->assertSame('TV', TvPipe::pipeName());
        $this->assertTrue((new CategorizationResult(5040, 0.9, 'tv'))->isSuccessful());
    }

    public function test_php_reference_semantics(): void
    {
        $this->assertSame(
            ['total' => 10, 'empty' => 3, 'dotted' => 1, 'spaced' => 2, 'compact' => 4, 'group_suffix' => 2, 'overlong' => 1, 'total_bytes' => 368, 'max_length' => 256],
            CategorizationPipeline::summarizeReleaseNameShapeBatch(['Movie.Title.2024.1080p.BluRay.x264-GRP', '  Some Show S01E01 720p-Group  ', 'Compact_Name',
                "\t\n\0", '', 'Two Words.Here', 'name-x', 'ends-with-dash-', str_repeat('a', 256), null]),
        );

        $this->assertSame(
            ['total' => 12, 'no_match' => 1, 'none' => 1, 'hash' => 2, 'obfuscated' => 1, 'gibberish' => 1, 'group_only_low_signal' => 1, 'other' => 3, 'empty' => 2, 'distinct' => 10],
            CategorizationResult::summarizeMatchedByBatch(['no_match', 'none', 'hash_md5', 'hash_', 'obfuscated_pattern', 'gibberish_long', 'group_only_low_signal',
                'group_only_low_signal_x', '', 'tv_episode', 'tv_episode', 7]),
        );
        $this->assertSame(3, CategorizationResult::summarizeMatchedByBatch(['1', 1, '01', 1.0])['distinct']);

        $this->assertSame(
            ['total' => 8, 'lossless' => 2, 'lossy' => 2, 'video' => 1, 'unknown' => 3, 'discography' => 1],
            MusicPipe::summarizeAudioFormatBatch(['Artist-Album-2024-FLAC', 'Artist - Album (2024) [MP3 320kbps]', 'Artist-Discography-MP3',
                'Artist.Live.At.Wembley.MVID', 'Artist - Album FLAC + MP3', 'Some.Random.Thing', '', ['flac']]),
        );

        $this->assertSame(
            ['total' => 7, 'windows' => 2, 'mac' => 1, 'linux' => 1, 'mobile' => 1, 'unknown' => 2, 'disc_image' => 1, 'cracked' => 2],
            PcPipe::summarizePcPlatformBatch(['Adobe.Photoshop.2024.Win64.Cracked', 'Some.App.v1.MacOS.x64.dmg', 'Tool.v2.Linux.x64', 'Game.Android.APK',
                'Office.2021.x86.ISO', 'Plain.Software.Name', 'Keygen.Only']),
        );

        $this->assertSame(
            ['total' => 13, 'episode' => 3, 'daily' => 1, 'season_pack' => 2, 'none' => 7, 'max_season' => 12, 'max_episode' => 104],
            TvPipe::summarizeEpisodeTokenBatch(['Show.S01E02.720p', 'show s3.e104 x', 'Show.S12-E7', 'Show.2024.05.17.Guest', 'Show.S02.Complete', 'Show.S123E01',
                'xS01E01', 'Show.S01E1234', 'Show.2024.13.01', '12024.05.17', 'Show S1', '', false]),
        );
        $this->assertSame(['max_season' => 2, 'max_episode' => 5], array_intersect_key(
            TvPipe::summarizeEpisodeTokenBatch(['a.s02e05.s09e99']), ['max_season' => 0, 'max_episode' => 0]));
    }

    public function test_edge_cases_match_between_php_and_rust(): void
    {
        $this->ffiOrSkip();
        $texts = self::TEXTS;
        foreach (array_keys(self::HELPERS) as $helper) {
            $this->assertParity($helper, []);
            $this->assertParity($helper, $texts);
            $this->assertParity($helper, ['a' => 'Show.S01E01', 'b' => 'hash_x', 'c' => 'flac.win64']);
            $this->assertParity($helper, [str_repeat('Show.S01E01.flac.win64-GRP ', 64)]);
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
        $random = new Randomizer(new Mt19937(4131));
        $alphabet = ['a', 'b', 'e', 's', 'x', 'S', 'E', 'X', '0', '1', '2', '3', '9', '.', '-', '_', ' ', "\t", "\n", "\0", "\x0B", "\x1F", "\xff", "\xc3\xa9"];
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
        $oversized = str_repeat('Show.S01E01.flac.win64-GRP ', 640_000);
        foreach (self::TARGETS as $class) {
            $class::useW413RustFfi(true);
        }
        foreach (self::HELPERS as [$class, $method]) {
            foreach ([['Show.S01E01', null], [1, 'x'], [1.5], [true], [['nested']], ['a', new \stdClass]] as $items) {
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
            $items = ['Show.S01E02.flac', 'hash_x'];
            $expected = implode("\x1F", $class::{$method.'Php'}($items));
            $args = ['Show.S01E02.flachash_x', 22, pack('V2', 16, 6), 8];
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
            $class::useW413RustFfi(true);
            $this->assertNotNull($class::{$method.'Native'}(['Show.S01E01.FLAC.Win64-GRP', 'hash_x']), $class);
            $this->assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'w413RustFfiHandle'))->getValue(), $class.' must resolve its native export');
        }
    }

    public function test_missing_library_falls_back_to_php(): void
    {
        $items = ['Show.S01E01.FLAC.Win64-GRP', 'hash_x', ''];
        foreach (self::HELPERS as [$class, $method]) {
            (new ReflectionProperty($class, 'w413RustFfiHandle'))->setValue(null, false);
            $class::useW413RustFfi(true);
            $this->assertNull($class::{$method.'Native'}($items), $class);
            $this->assertSame($class::{$method.'Php'}($items), $class::$method($items), $class);
            $class::resetW413RustFfi();
            $this->assertNull((new ReflectionProperty($class, 'w413RustFfiHandle'))->getValue(), $class);
        }
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of each helper through its public dispatcher, including
        // the PHP-side type checks, packing, FFI call and decoding (release dylib, 5 warmups, 301
        // samples per helper and workload; 40 iterations per common sample, 4 per uncommon sample).
        // Common = 32 typical scene release names (64 matchedBy labels); uncommon = 2000 padded,
        // mixed-case, marker-free long names (5000 labels with many distinct values).
        // Speedup = PHP latency / Rust latency, ranges over 3 rounds (PHP 8.5.12 NTS, Apple M5, shared
        // host at load average ~5):
        // - shape:    common mean 1.69-1.72x / p99 1.38-1.74x, uncommon mean 3.89-3.95x / p99 2.99-3.58x
        // - matched:  common mean 1.17-1.20x / p99 1.04-1.20x, uncommon mean 1.54-1.61x / p99 1.44-1.70x
        // - audio:    common mean 4.81-4.88x / p99 3.41-4.25x, uncommon mean 6.14-6.26x / p99 4.97-5.76x
        // - platform: common mean 8.24-8.27x / p99 6.02-6.91x, uncommon mean 11.62-11.70x / p99 7.88-9.25x
        // - episode:  common mean 1.99-2.04x / p99 1.33-1.93x, uncommon mean 3.94-4.04x / p99 3.23-3.45x
        // Audio and platform default to Rust. Shape and episode miss 1.5x on the common p99 in at least
        // one round, and matched labels lose to PHP's hash of interned strings once the labels are packed
        // for the FFI call, so all three keep PHP.
        // Reproduce:
        // cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
        // php artisan test --compact tests/Unit/RustCategorizationPipelineResultMusicPcTvW41ParityTest.php --filter benchmark
        // Each helper defaults to Rust only when it clears 1.5x on both workloads' mean and p99,
        // per .ai/rules/rust-performance.md.
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(4132));
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
                $class::resetW413RustFfi();
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
        fwrite(STDERR, "\nRustCategorizationPipelineResultMusicPcTvW41 speedup: {$summary}\n");
        foreach (self::HELPERS as $helper => [$class]) {
            $qualifies = true;
            foreach ($speedups[$helper] as $speedup) {
                $qualifies = $qualifies && $speedup['mean'] >= 1.5 && $speedup['p99'] >= 1.5;
                $this->assertGreaterThan(0.0, $speedup['mean'], $helper);
                $this->assertGreaterThan(0.0, $speedup['p99'], $helper);
            }
            if (! $qualifies) {
                $this->assertFalse(constant($class.'::W413_RUST_FFI_DEFAULT'), $class.' must keep PHP below 1.5x: '.$summary);
                $this->assertFalse($class::usesW413RustFfi(), $class.' must keep PHP below 1.5x: '.$summary);
            }
            if (constant($class.'::W413_RUST_FFI_DEFAULT')) {
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
        $class::useW413RustFfi(true);
        $this->assertSame($expected, $class::$method($items), $helper.' dispatcher');
        $class::resetW413RustFfi();
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
        if ($helper === 'matched') {
            return array_map(static fn (int $i): string => ['tv_episode', 'movie_imdb', 'no_match', 'hash_md5', 'obfuscated_pattern', 'music_flac', 'group_only_low_signal',
                'pc_iso', 'none', 'gibberish_long', 'xxx_onlyfans', 'tv_daily'][$i % 12], range(1, 64));
        }

        return array_map(static fn (int $i): string => match ($i % 8) {
            0 => 'Some.Show.S01E'.sprintf('%02d', $i).'.720p.WEB.h264-GRP',
            1 => 'Daily.Show.2024.05.'.sprintf('%02d', 1 + $i % 28).'.1080p.WEB-GRP',
            2 => 'Artist-Album-'.(2000 + $i).'-FLAC-GRP',
            3 => 'Artist - Album ('.(1990 + $i).') [MP3 320kbps]',
            4 => 'Adobe.Photoshop.2024.v25.'.$i.'.Win64.Cracked-GRP',
            5 => 'Some.App.v'.$i.'.MacOS.x64.dmg',
            6 => 'Movie Title '.(1980 + $i).' 1080p BluRay x264-GRP',
            default => 'Show.S0'.($i % 9).'.Complete.720p.HDTV-GRP',
        }, range(1, 32));
    }

    /**
     * @return list<string>
     */
    private function uncommonWorkload(string $helper): array
    {
        if ($helper === 'matched') {
            return array_map(static fn (int $i): string => match ($i % 5) {
                0 => 'hash_'.dechex($i * 7919),
                1 => 'obfuscated_'.($i % 50),
                2 => 'categorizer_signal_'.($i % 400),
                3 => 'group_only_low_signal',
                default => 'gibberish_'.($i % 13),
            }, range(1, 5000));
        }

        return array_map(static fn (int $i): string => '  '.strtoupper(str_repeat('Obfuscated.Mirror.Release.', 6)).'Part'.$i.'.Of.2000.Name  ', range(1, 2000));
    }

    /**
     * @param  class-string  $class
     * @param  list<string>  $items
     */
    private function runWorkload(string $class, string $method, array $items, bool $useRust): int
    {
        $class::useW413RustFfi($useRust);

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
        $cdef = implode("\n", array_map(static fn (string $class): string => constant($class.'::W413_FFI_CDEF'), self::TARGETS));
        $ffi = RustPipelineLibrary::resolveDomain('categorize_w413', $cdef);
        if ($ffi === null) {
            $this->markTestSkipped('libnntmux_pipeline with the Wave 41.3 exports is not available.');
        }

        return $ffi;
    }
}
