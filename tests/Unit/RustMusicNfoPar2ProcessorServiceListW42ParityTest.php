<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\MusicProcessor;
use App\Services\NfoProcessor;
use App\Services\NfoService;
use App\Services\Par2FileList;
use App\Services\Par2Processor;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Wave 42.4 pure CPU music, NFO and PAR2 batch summaries. The opt-in benchmark
 * retains raw randomized pairs, input/source/binary hashes, warmups and p99 rank uncertainty.
 * Reproduce: NNTMUX_W424_BENCH=1 php vendor/bin/phpunit tests/Unit/RustMusicNfoPar2ProcessorServiceListW42ParityTest.php
 */
final class RustMusicNfoPar2ProcessorServiceListW42ParityTest extends TestCase
{
    private const array HELPERS = [
        [MusicProcessor::class, 'summarizeMusicReleaseBatch', 'nntmux_w424_music_release_batch'],
        [NfoProcessor::class, 'summarizeNfoCandidateBatch', 'nntmux_w424_nfo_candidate_batch'],
        [NfoService::class, 'summarizeNfoContentBatch', 'nntmux_w424_nfo_content_batch'],
        [Par2FileList::class, 'summarizePar2FileNameBatch', 'nntmux_w424_par2_file_list_batch'],
        [Par2Processor::class, 'summarizePar2RenameCandidateBatch', 'nntmux_w424_par2_rename_batch'],
    ];

    private const array DEFAULTS = [true, false, false, false, false];

    /** Earlier-wave public toggles that must stay intact. */
    private const array LEGACY_METHODS = [
        MusicProcessor::class => ['usesRustFfi', 'setUseRustFfi', 'dispatchPlanPhp'],
        NfoProcessor::class => ['usesRustFfi', 'setUseRustFfi', 'lookupFlagsPhp', 'lookupFlagsNative'],
        NfoService::class => ['clearSettingsCache'],
        Par2FileList::class => ['useRustFfi'],
        Par2Processor::class => ['useRustFfi'],
    ];

    private const int SEED = 4242026;

    protected function tearDown(): void
    {
        foreach (self::HELPERS as [$class]) {
            if (method_exists($class, 'resetW424RustFfi')) {
                $class::resetW424RustFfi();
            }
        }
        parent::tearDown();
    }

    public function test_required_additive_api_exists(): void
    {
        foreach (self::HELPERS as $index => [$class, $method]) {
            // ReflectionClass avoids method_exists() narrowing, which stalls PHPStan across these loops.
            $reflection = new \ReflectionClass($class);
            foreach ([$method, $method.'Php', $method.'Native', 'useW424RustFfi', 'usesW424RustFfi', 'resetW424RustFfi'] as $name) {
                self::assertTrue($reflection->hasMethod($name), $class.'::'.$name);
            }
            self::assertSame(self::DEFAULTS[$index], $reflection->getConstant('W424_RUST_FFI_DEFAULT'));
            foreach (self::LEGACY_METHODS[$class] as $name) {
                self::assertTrue($reflection->hasMethod($name), $class.'::'.$name);
            }
        }
    }

    /** @return list<array{array<array-key, mixed>, array<string, int>}> */
    private static function literalCases(): array
    {
        return [
            [[
                'Artist-Album-(CD)-FLAC-2021-GRP',
                ['searchname' => 'VA-Hits.320kbps.MP3.WEB.1999'],
                ['name' => 'Artist - Album V0 Vinyl'],
                ['title' => 'OST.Score.1234K.aac', 'searchname' => null],
                " \0\t",
                'Artist.DISCOGRAPHY.24BIT.VBR',
                "\fArtist.CDDA.3000",
                null,
                ['searchname' => ['nested']],
                2024,
            ], ['total_releases' => 10, 'empty_title_count' => 3, 'lossless_count' => 1, 'lossy_count' => 2, 'bitrate_tagged_count' => 3, 'source_tagged_count' => 4, 'compilation_count' => 3, 'year_tagged_count' => 3]],
            [[
                'release.NFO',
                ['filename' => 'file_id.diz'],
                ['name' => 'dir/readme.txt'],
                'proof\\sample.jpg',
                'nfo',
                '',
                'Release.Sample.nfo.bak',
                true,
                7,
            ], ['total_files' => 9, 'empty_name_count' => 2, 'nfo_count' => 1, 'diz_count' => 1, 'txt_count' => 1, 'sample_proof_count' => 2, 'nested_path_count' => 2]],
            [[
                'See HTTPS://WWW.IMDB.COM/title/TT1234567/',
                ['nfo' => 'imdb.com/title/tt123456 imdb.com/title/tt7654321'],
                ['content' => "http://thetvdb.com/x \xdb\xb2"],
                'TVMAZE.com',
                "a\nb\nc\nd\ne\nf\ng\nh\ni\nj\nk",
                "\n\n\n\n\n\n\n\n\n\nx",
                " \0",
                1.5,
            ], ['total_nfos' => 8, 'empty_nfo_count' => 2, 'imdb_link_count' => 2, 'tv_link_count' => 2, 'url_count' => 2, 'high_ascii_count' => 1, 'multi_line_count' => 1]],
            [[
                'movie.par2',
                ['name' => 'movie.part01.RAR'],
                ['filename' => 'movie.r07'],
                'movie.7z.001',
                'Sample/movie-sample.mkv',
                'movie.rx1',
                '',
                'movie.ZIP',
                [],
            ], ['total_files' => 9, 'empty_name_count' => 2, 'par2_count' => 1, 'rar_volume_count' => 2, 'split_archive_count' => 2, 'video_count' => 1, 'sample_count' => 1, 'path_count' => 1]],
            [[
                '0123456789abcdef0123456789ABCDEF.mkv',
                ['filename' => 'dir/Show.S01E01.720p-GRP.mkv'],
                ['name' => 'abcdefghijklmnop'],
                'Movie.vol07+08.PAR2',
                'Movie.vol+08.par2',
                'setup.EXE',
                'a.exe/file',
                '',
                false,
            ], ['total_files' => 9, 'empty_name_count' => 2, 'hex_hash_name_count' => 1, 'obfuscated_name_count' => 2, 'release_like_count' => 1, 'par2_volume_count' => 1, 'executable_count' => 1]],
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
                $class::useW424RustFfi($enabled);
                self::assertSame($expected, $class::{$method}($input));
                self::assertSame($empty, $class::{$method}([]));
                $single = ['x'];
                self::assertSame($class::{$method.'Php'}($single), $class::{$method}($single));
            }
        }
    }

    public function test_switches_are_independent_and_missing_library_falls_back(): void
    {
        foreach (self::HELPERS as $index => [$class]) {
            $class::resetW424RustFfi();
            self::assertSame(self::DEFAULTS[$index], $class::usesW424RustFfi());
            $class::useW424RustFfi(false);
        }
        foreach (self::HELPERS as $index => [$class]) {
            $class::useW424RustFfi();
            foreach (self::HELPERS as [$other]) {
                self::assertSame($class === $other, $other::usesW424RustFfi());
            }
            $legacy = method_exists($class, 'usesRustFfi') ? $class::usesRustFfi() : null;
            $class::resetW424RustFfi();
            self::assertSame(self::DEFAULTS[$index], $class::usesW424RustFfi());
            self::assertSame($legacy, method_exists($class, 'usesRustFfi') ? $class::usesRustFfi() : null, 'W424 reset must not touch earlier toggles.');
            $class::useW424RustFfi(false);
            self::assertFalse($class::usesW424RustFfi());
        }
        foreach (self::HELPERS as $i => [$class, $method]) {
            $class::useW424RustFfi(true);
            (new ReflectionProperty($class, 'w424Ffi'))->setValue(null, false);
            [$input, $expected] = self::literalCases()[$i];
            self::assertSame($expected, $class::{$method}($input));
            self::assertSame($expected, $class::{$method.'Native'}($input));
            self::assertFalse((new ReflectionProperty($class, 'w424Ffi'))->getValue());
        }
    }

    /** Reads the constant at run time so the gate assertions survive future default flips. */
    private static function defaultEnabled(string $class): bool
    {
        return (new \ReflectionClass($class))->getConstant('W424_RUST_FFI_DEFAULT') === true;
    }

    private static function export(FFI $ffi, string $symbol, string $wire, CData $out, int $capacity): int
    {
        $written = $ffi->{$symbol}($wire, strlen($wire), $out, $capacity);
        self::assertIsInt($written);

        return $written;
    }

    private static function ffi(string $class): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('metadata_w424', $class::W424_FFI_CDEF);
        self::assertNotNull($ffi, 'Build the release pipeline library with all five W424 symbols.');

        return $ffi;
    }

    /** @return list<string> */
    private static function entries(string $class, mixed $input): array
    {
        return (new ReflectionMethod($class, 'w424Entries'))->invoke(null, $input);
    }

    /** @param array<array-key, mixed> $input
     * @return array<string, int>
     */
    private static function rawResult(string $class, FFI $ffi, string $symbol, array $input): array
    {
        $wire = serialize([self::entries($class, $input)]);
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
        $class::useW424RustFfi(true);
        self::assertSame($expected, $class::{$method}($input));
        $wire = serialize($expected);
        self::assertSame($wire, FFI::string($out, strlen($wire)), 'Public dispatch must write the Rust output, rather than fall back.');
        self::assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'w424Ffi'))->getValue());
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
            $wire = serialize([['entry']]);
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
            $input = [$object, ['name' => $object, 'filename' => $object, 'searchname' => $object, 'nfo' => $object], ['title' => $object, 'content' => 'Ok.2020.nfo']];
            $class::useW424RustFfi(true);
            self::assertSame($class::{$method.'Php'}($input), $class::{$method}($input));
            $class::resetW424RustFfi();
            $class::useW424RustFfi(true);
            $oversized = [$large, ['name' => $large, 'nfo' => $large, 'searchname' => $large, 'filename' => $large]];
            self::assertSame($class::{$method.'Php'}($oversized), $class::{$method}($oversized));
            self::assertSame($class::{$method.'Php'}($oversized), $class::{$method.'Native'}($oversized));
            self::assertNull((new ReflectionProperty($class, 'w424Ffi'))->getValue(), 'Size guard must run before resolving FFI.');
        }
    }

    private const array VOCABULARY = [
        // Shared separators, control bytes, invalid UTF-8 and boundary text.
        '', ' ', '.', '-', '_', ' - ', '[', ']', '(', ')', '/', '\\', '+', "\0", "\t", "\v", "\f", "\n", "\n\n\n\n\n", "\r", "\xff", "\x80", "\xdb\xb2", '日本語',
        // Music.
        'FLAC', 'flac', 'ALAC', 'WAV', 'APE', 'WV', 'DSD', 'AIFF', 'MP3', 'mp3', 'AAC', 'OGG', 'OPUS', 'M4A', 'WMA', 'CBR', 'VBR', 'V0', 'v2', 'V3', '320kbps', '320K', '192k',
        '1234K', '24BIT', '96KHZ', 'CD', 'CDDA', 'WEB', 'Vinyl', 'LP', 'SACD', 'TAPE', 'VA', 'OST', 'Soundtrack', 'DISCOGRAPHY', 'Compilation', '1999', '2021', '1899', '3000', '20201',
        // NFO candidates and PAR2 file lists.
        'nfo', 'NFO', '.nfo', '.diz', '.txt', '.TXT', 'file_id', 'readme', 'SAMPLE', 'sample', 'Samples', 'PROOF', 'proof', '.par2', '.PAR2', '.rar', '.RAR', '.r00', '.r99', '.rx1',
        '.7z', '.zip', '.001', '.0001', '.mkv', '.MP4', '.avi', '.m2ts', '.ts', '.wmv', '.vob', 'part01', 'Movie', 'movie',
        // NFO contents.
        'imdb.com/title/tt', 'IMDB.COM/TITLE/TT', '1234567', '123456', '12345678', 'thetvdb.com', 'TheTVDB.Com', 'tvmaze.com', 'TVMAZE.COM', 'http://', 'HTTPS://', 'https:/', 'www',
        // PAR2 rename candidates.
        '0123456789abcdef0123456789ABCDEF', '0123456789abcdef0123456789abcdef01234567', 'abcdefghijklmnop', 'abcdefghijklmno', 'vol', 'VOL', '07', '08', '.exe', '.EXE', '.bat',
        '.ps1', '.js', '.lnk', 'setup', 'Show', 'S01E01', '720p', 'GRP',
    ];

    private static function randomEntry(Randomizer $random): string
    {
        $entry = '';
        for ($k = 0, $n = $random->getInt(0, 14); $k < $n; $k++) {
            $entry .= self::VOCABULARY[$random->getInt(0, count(self::VOCABULARY) - 1)];
            if ($random->getInt(0, 2) === 0) {
                $entry .= ['.', '-', ' ', '_', ' - ', '/', '\\', '+', "\n", "\xff", "\0"][$random->getInt(0, 10)];
            }
        }

        return $entry;
    }

    /** @return array<array-key, mixed> */
    private static function randomBatch(Randomizer $random): array
    {
        $rows = [];
        for ($j = 0, $n = $random->getInt(0, 24); $j < $n; $j++) {
            $entry = self::randomEntry($random);
            $field = ['searchname', 'name', 'title', 'filename', 'nfo', 'content'][$random->getInt(0, 5)];
            $rows['key'.$j] = match ($random->getInt(0, 9)) {
                0 => [$field => $entry],
                1 => [$field => $entry, 'name' => 'ignored'],
                2 => [$field => null, 'title' => $entry, 'content' => $entry],
                3 => [null, true, false, 1.5, -7, PHP_INT_MAX, [], new \stdClass][$random->getInt(0, 7)],
                4 => [$field => [$entry]],
                default => $entry,
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
                $class::useW424RustFfi(false);
                self::assertSame($expected, $class::{$method}($input));
                self::assertNativeDispatch($class, $method, $ffi, $input, $expected);
            }
        }
    }

    /** Native scans skip 32-byte chunks without anchor bytes; slide every pattern across chunk edges. */
    public function test_anchor_patterns_at_every_chunk_offset_match_php(): void
    {
        $patterns = [
            'imdb.com/title/tt1234567', 'IMDB.COM/TITLE/TT123456x', 'https://x', 'HTTP://', 'http:/', 'xhttps://', 'thetvdb.com', 'TheTVDB.co', 'TVMAZE.COM', 'tvmaze.co',
            str_repeat("x\n", 10), str_repeat("x\n", 9), "\xdb", 'SAMPLE', 'xsample', 'Sample-x', 'Proof.', 'proofs', '9proof', 'a/b', 'a\\b', '.nfo', '.r07', '.PAR2',
            'Movie.vol07+08.par2', '0123456789abcdef0123456789abcdef', 'FLAC.320kbps.WEB.2021', 'VA-OST.V0',
        ];
        foreach (self::HELPERS as [$class, $method, $symbol]) {
            $ffi = self::ffi($class);
            foreach (['a', '.', 's', 'v', '/'] as $filler) {
                for ($pad = 0; $pad <= 70; $pad++) {
                    $batch = [];
                    foreach ($patterns as $pattern) {
                        $batch[] = 'q'.str_repeat($filler, $pad).$pattern;
                        $batch[] = $pattern.str_repeat($filler, $pad).'q';
                    }
                    self::assertSame($class::{$method.'Php'}($batch), self::rawResult($class, $ffi, $symbol, $batch), $class.' pad '.$pad.' filler '.$filler);
                }
            }
        }
    }

    public function test_benchmark_qualified_defaults_dispatch_without_overrides(): void
    {
        foreach (self::HELPERS as $i => [$class, $method]) {
            $class::resetW424RustFfi();
            self::assertSame(self::DEFAULTS[$i], $class::usesW424RustFfi());
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
                self::assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'w424Ffi'))->getValue());
            } else {
                self::assertNull((new ReflectionProperty($class, 'w424Ffi'))->getValue());
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
            'reproduction_command' => 'NNTMUX_W424_BENCH=1 php vendor/bin/phpunit tests/Unit/RustMusicNfoPar2ProcessorServiceListW42ParityTest.php',
        ];
    }

    /** Typical NFO text: a CP437 banner, release details and lookup links. */
    private static function nfoDocument(int $repeat): string
    {
        $banner = str_repeat("\xdb\xdb\xb2\xb2\xb1\xb1\xb0\xb0 ", 8)."\r\n";
        $body = "  Release......: Some.Movie.2021.1080p.BluRay.x264-GRP\r\n  Size.........: 8.71 GB\r\n  Source.......: BluRay\r\n  Notes........: Enjoy this release and support the studio.\r\n";

        return str_repeat($banner, 4).str_repeat($body, $repeat).'  IMDb.........: https://www.imdb.com/title/tt1234567/'."\r\n".str_repeat($banner, 4);
    }

    /** Common batches contain 24 typical rows; uncommon batches contain 1024 rows
     * mixing array rows, missing fields, invalid bytes and long entries.
     *
     * @return array<array-key, mixed>
     */
    private static function benchmarkInput(int $index, bool $uncommon): array
    {
        $literal = self::literalCases()[$index][0];
        $source = array_values(array_filter($literal, static fn (mixed $row): bool => is_string($row) && $row !== ''));
        if ($index === 2) {
            $source = [self::nfoDocument(4), self::nfoDocument(8), ['nfo' => self::nfoDocument(2)], "Plain text NFO\nwithout links\n"];
        }
        if ($uncommon) {
            $long = str_repeat("日本語.\xff.Long.Entry.", 32);
            $source = [...$literal, $long.'VA-OST.320kbps.FLAC.CDDA.2021/Sample/file_id.vol07+08.PAR2', ['name' => $long.'0123456789abcdef0123456789abcdef.r07', 'filename' => $long.'.nfo']];
            if ($index === 2) {
                $source = [...$source, self::nfoDocument(64), ['content' => self::nfoDocument(16)."\nhttp://thetvdb.com/?id=1"]];
            }
        }
        $rows = [];
        for ($i = 0, $n = $uncommon ? 1024 : 24; $i < $n; $i++) {
            $rows[] = $source[$i % count($source)];
        }

        return $rows;
    }

    public function test_opt_in_paired_public_dispatch_benchmark(): void
    {
        if (getenv('NNTMUX_W424_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W424_BENCH=1 for 3000 paired samples per helper/workload.');
        }
        $artifact = getenv('NNTMUX_W424_BENCH_ARTIFACT') ?: tempnam(sys_get_temp_dir(), 'nntmux-w424-bench-');
        self::assertNotFalse($artifact);
        $report = ['metadata' => self::benchmarkMetadata(), 'status' => 'running', 'gate_passed' => false, 'helper_qualification' => [], 'summary' => [], 'raw_pairs' => []];
        fwrite(STDERR, "\nWave42.4 benchmark artifact: ".$artifact."\n");
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
                        $class::useW424RustFfi($native);
                        for ($warmup = 0; $warmup < 64; $warmup++) {
                            self::assertSame($expected, $class::{$method}($args));
                        }
                    }
                    for ($sample = 0; $sample < 3000; $sample++) {
                        $order = $random->getInt(0, 1) ? [false, true] : [true, false];
                        $pair = ['order' => $order[0] ? 'rust-first' : 'php-first'];
                        foreach ($order as $native) {
                            $class::useW424RustFfi($native);
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
                        'input_sha256' => hash('sha256', serialize(self::entries($class, $args))),
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
