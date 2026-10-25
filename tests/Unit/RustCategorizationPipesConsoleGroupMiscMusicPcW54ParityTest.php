<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Category;
use App\Services\Categorization\Pipes\ConsolePipe;
use App\Services\Categorization\Pipes\GroupNamePipe;
use App\Services\Categorization\Pipes\MiscPipe;
use App\Services\Categorization\Pipes\MusicPipe;
use App\Services\Categorization\Pipes\PcPipe;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use Tests\TestCase;

final class RustCategorizationPipesConsoleGroupMiscMusicPcW54ParityTest extends TestCase
{
    /** Per class: the batch entry, its Rust export and the trailer word count after the per-record words. */
    private const TARGETS = [
        ConsolePipe::class => ['w54ConsolePlatformFlagBatch', 'nntmux_w543_console_platform_flag_batch', 7],
        GroupNamePipe::class => ['w54GroupNameRouteFlagBatch', 'nntmux_w543_group_name_route_flag_batch', 10],
        MiscPipe::class => ['w54MiscSubjectShapeBatch', 'nntmux_w543_misc_subject_shape_batch', 6],
        MusicPipe::class => ['w54AudioTokenFlagBatch', 'nntmux_w543_music_audio_flag_batch', 8],
        PcPipe::class => ['w54PcPlatformFlagBatch', 'nntmux_w543_pc_platform_flag_batch', 8],
    ];

    /** Benchmark-gated production defaults (see the benchmark test below). */
    private const DEFAULTS = [
        ConsolePipe::class => true,
        GroupNamePipe::class => true,
        MiscPipe::class => true,
        MusicPipe::class => true,
        PcPipe::class => true,
    ];

    /** Edge cases shared by every helper: whitespace, NUL and control bytes, non-ASCII, boundary and overlong tokens. */
    private const EDGE = ['', ' ', "  \t\n", "\0", "\x0b", "\x0c", "\r\n", '---', '...', 'x', 'X', "a\0b", "\0PS4\0", "Gam\xc3\xa9.PS5-GRP",
        "\xff\xfe.ISO", "caf\xc3\xa9 FLAC", 'PLAYSTATIONPLAYSTATION', 'averyveryverylongtokenwithdigits123', 'S01E02', 's1e1', 'S1234', 'S01E12345',
        'S01E', 'SE01', 'S999E9999', 'V', 'v1', 'V01', 'Vx', 'V123456789012345678901', 'R00', 'r99', 'R0', 'R100', 'PART1', 'part0001', 'PART',
        'PART12345', '1899', '1900', '2099', '2100', '0019', '19999', '96k', '320KBPS', '1K', '1000K', '320', '128kbit', 'DSD', 'DSD64', 'dsd128',
        'a3f9c2d1e8b7a6f5c4d3e2f1a0b9c8d7', 'A3F9C2D1E8B7A6F5', 'A3F9C2D1E8B7A6F', '1234567890123456', 'abcdefghijklmnopqrstuvwxyz',
        'Abc123Def456Ghi789', '[01/10] - "file.part01.rar" yEnc (1/1)', 'Game.USA.PS4.Switch-GRP', 'Show.S01E02.Xbox360', 'Retro.SNES.PAL.ps3',
        'Game.GOG.Win64', 'alt.binaries.sounds.mp3', 'alt.binaries.hdtv.x264', 'a.b.ebook.anime', 'alt.binaries.apps.mac', 'comp.lang.c',
        'Artist-Album-2019-WEB-FLAC-24BIT', 'VA - Hits 1999 (320kbps MP3)', 'App.v2.1.x64.Incl.Keygen-GRP', 'App.MacOS.Linux.DMG',
        'Game.Android.APK.Build.12', 'Tool.Setup.ISO.Win32', 'Game.Name-GRP ', ' Game.Name-GRP', "Title\vPS4", 'ps4ps4.wiiu_wii'];

    protected function tearDown(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->setOverride($class, null);
        }
        parent::tearDown();
    }

    public function test_w54_methods_and_constants_exist(): void
    {
        $missing = [];
        foreach (self::TARGETS as $class => [$entry]) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW54RustFfi', 'withW54RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                if (! $reflection->hasMethod($method) || ! $reflection->getMethod($method)->isStatic() || ! $reflection->getMethod($method)->isPublic()) {
                    $missing[] = $class.'::'.$method;
                }
            }
            $default = $reflection->getReflectionConstant('W54_RUST_FFI_DEFAULT');
            if ($default === false || ! $default->isPrivate() || $default->getDeclaringClass()->getName() !== $class || ! \is_bool($default->getValue())) {
                $missing[] = $class.'::W54_RUST_FFI_DEFAULT';
            }
            if (! $reflection->hasConstant('W54_FFI_CDEF')) {
                $missing[] = $class.'::W54_FFI_CDEF';
            }
            $state = new ReflectionMethod($class, 'w54RustFfiState');
            $params = array_map(static fn ($p): string => $p->getName().':'.$p->getType().'='.var_export($p->getDefaultValue(), true), $state->getParameters());
            if (! $state->isPrivate() || ! $state->isStatic() || $state->getDeclaringClass()->getName() !== $class
                || $params !== ['op:?string=NULL', 'val:mixed=NULL'] || (string) $state->getReturnType() !== 'mixed') {
                $missing[] = $class.'::w54RustFfiState';
            }
            $uses = new ReflectionMethod($class, 'usesW54RustFfi');
            $with = new ReflectionMethod($class, 'withW54RustFfi');
            $withParams = array_map(static fn ($p): string => $p->getName().':'.$p->getType(), $with->getParameters());
            if (! $uses->getReturnType() instanceof ReflectionNamedType || $uses->getReturnType()->getName() !== 'bool' || $uses->getNumberOfParameters() !== 0
                || $withParams !== ['enabled:bool', 'callback:callable'] || (string) $with->getReturnType() !== 'mixed') {
                $missing[] = $class.' W54 toggle signatures';
            }
        }
        $this->assertSame([], $missing);
    }

    public function test_w54_defaults_and_scoped_toggle(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->assertSame(self::DEFAULTS[$class], $this->defaultOf($class), $class);
            $this->assertSame(self::DEFAULTS[$class], $class::usesW54RustFfi(), $class);
            $this->assertTrue($class::withW54RustFfi(true, static fn (): bool => $class::usesW54RustFfi()));
            $this->assertFalse($class::withW54RustFfi(false, static fn (): bool => $class::usesW54RustFfi()));
            $this->assertTrue($class::withW54RustFfi(false, static fn (): bool => $class::withW54RustFfi(true, static fn (): bool => $class::usesW54RustFfi())));
            $this->assertSame(self::DEFAULTS[$class], $class::usesW54RustFfi(), $class);
            try {
                $class::withW54RustFfi(! $class::usesW54RustFfi(), $this->throwing('boom'));
                $this->fail('expected the callback exception');
            } catch (RuntimeException $e) {
                $this->assertSame('boom', $e->getMessage());
            }
            $this->assertSame(self::DEFAULTS[$class], $class::usesW54RustFfi(), $class.' restores after an exception');
        }
        // Each class keeps an independent toggle, separate from the earlier waves.
        ConsolePipe::withW54RustFfi(! ConsolePipe::usesW54RustFfi(), function (): void {
            $this->assertSame(self::DEFAULTS[GroupNamePipe::class], GroupNamePipe::usesW54RustFfi());
            $this->assertSame(ConsolePipe::W403_RUST_FFI_DEFAULT, ConsolePipe::usesW403RustFfi());
            $this->assertSame(GroupNamePipe::W403_RUST_FFI_DEFAULT, GroupNamePipe::usesW403RustFfi());
        });
        MiscPipe::withW54RustFfi(! MiscPipe::usesW54RustFfi(), function (): void {
            $this->assertSame(self::DEFAULTS[MusicPipe::class], MusicPipe::usesW54RustFfi());
            $this->assertSame(self::DEFAULTS[PcPipe::class], PcPipe::usesW54RustFfi());
            $this->assertSame(MiscPipe::W393_RUST_FFI_DEFAULT, MiscPipe::usesW393RustFfi());
        });
        MusicPipe::withW54RustFfi(! MusicPipe::usesW54RustFfi(), function (): void {
            $this->assertSame(MusicPipe::W413_RUST_FFI_DEFAULT, MusicPipe::usesW413RustFfi());
            $this->assertSame(PcPipe::W413_RUST_FFI_DEFAULT, PcPipe::usesW413RustFfi());
        });
    }

    public function test_w54_exports_resolve_and_guard_their_buffers(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [$entry, $symbol, $trailer]) {
            $cdef = \constant($class.'::W54_FFI_CDEF');
            $this->assertStringContainsString($symbol, $cdef);
            $ffi = RustPipelineLibrary::resolveDomain('categorize_w54', $cdef);
            $this->assertInstanceOf(FFI::class, $ffi, $class);
            $out = $ffi->new('int64_t[32]');
            $words = $trailer + 1;
            $this->assertSame($words, $this->callExport($ffi, $symbol, 'PS4.FLAC', 8, 1, $out, 32), $class);
            $this->assertSame($class::{$entry.'Php'}(['PS4.FLAC']), array_values((array) unpack('P*', FFI::string($out, $words * 8))), $class);
            // A NUL inside an item, a short buffer, a null output, a null blob and a count mismatch all decline.
            $this->assertSame(-1, $this->callExport($ffi, $symbol, "a\0b", 3, 1, $out, 32), $class);
            $this->assertSame(-$words, $this->callExport($ffi, $symbol, 'PS4.FLAC', 8, 1, $out, $words - 1), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, 'PS4.FLAC', 8, 1, null, 32), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, null, 8, 1, $out, 32), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, 'a', 1, 2, $out, 32), $class);
            $this->assertSame($trailer, $this->callExport($ffi, $symbol, null, 0, 0, $out, 32), $class);
        }
    }

    public function test_w54_native_path_runs_for_every_class(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [$entry, , $trailer]) {
            $items = ['Game.USA.PS4-GRP', 'alt.binaries.sounds.mp3', 'Artist-Album-2019-WEB-FLAC', 'App.v2.x64.Keygen', '', "a\0b"];
            $words = \count($items) + $trailer;
            $invoke = new ReflectionMethod($class, 'w54Invoke');
            $raw = $invoke->invoke(null, $items, $words);
            $this->assertIsArray($raw, $class.' stays on Rust, including the NUL item through its stand-in byte');
            $this->assertSame($class::{$entry.'Php'}($items), $raw, $class);
            $this->assertNull($invoke->invoke(null, $items, $words + 1), $class.' rejects a word count mismatch');
            $this->assertSame($class::{$entry.'Php'}([]), $invoke->invoke(null, [], $trailer), $class.' empty batch');
            $this->assertCount($trailer, $class::{$entry.'Native'}([]), $class);
        }
    }

    /**
     * @param  class-string  $class
     * @param  list<string>  $items
     * @param  list<int>  $expected
     */
    #[DataProvider('knownCases')]
    public function test_known_cases(string $class, array $items, array $expected): void
    {
        $this->assertSame($expected, $class::{self::TARGETS[$class][0].'Php'}($items));
        $this->assertParity($class, [$items], 'known case');
    }

    /**
     * @return array<string, array{class-string, list<string>, list<int>}>
     */
    public static function knownCases(): array
    {
        return [
            'console platforms, regions, seasons and stores' => [ConsolePipe::class,
                ['Game.USA.PS4.Switch-GRP', 'Show.S01E02.Xbox360', ' ', 'Retro.SNES.PAL.ps3', 'Game.GOG.Win64', 'game.ps4'],
                [2 | 8 | Category::GAME_PS4 << 16, 2 | 16 | Category::GAME_XBOX360 << 16, 1, 2 | 4 | 8 | Category::GAME_OTHER << 16, 32,
                    2 | Category::GAME_PS4 << 16, 1, 4, 1, 2, 1, 1, 3]],
            'group routing families and token counts' => [GroupNamePipe::class,
                ['alt.binaries.sounds.mp3', 'alt.binaries.hdtv.x264', '', 'alt.binaries.erotica', 'a.b.ebook.anime', 'alt.binaries.apps.mac', 'comp.lang.c'],
                [2 | 16 | 4 << 16, 2 | 4 | 8 | 4 << 16, 1, 2 | 128 | 3 << 16, 64 | 512 | 4 << 16, 2 | 256 | 4 << 16, 3 << 16, 1, 4, 1, 1, 1, 0, 1, 1, 1, 1]],
            'misc subject shapes' => [MiscPipe::class,
                ['a3f9c2d1e8b7a6f5c4d3e2f1a0b9c8d7.rar', '[01/10] - "Abc123Def456Ghi789" yEnc (1/1)', '1234567890123456789', "\t",
                    'Some.Title.part01.r00', 'abcdefghijklmnopqrstuvwxyz', '---'],
                [2 | 8 | 32 << 8 | 2 << 32, 4 | 32 | 18 << 8 | 6 << 32, 16 | 19 << 8 | 1 << 32, 1, 8 | 6 << 8 | 4 << 32, 26 << 8 | 1 << 32, 0, 1, 1, 1, 2, 1, 1]],
            'audio formats, quality and first year' => [MusicPipe::class,
                ['Artist-Album-2019-WEB-FLAC-24BIT', 'VA - Hits 1999 (320kbps MP3)', 'Artist.Discography.DSD.Vinyl', '', 'Song.V0.CBR'],
                [2 | 16 | 64 | 2019 << 8, 4 | 8 | 32 | 1999 << 8, 2 | 16 | 32 | 64, 1, 8, 1, 2, 1, 2, 2, 2, 2, 2]],
            'pc platform families and first platform' => [PcPipe::class,
                ['App.v2.1.x64.Incl.Keygen-GRP', 'App.MacOS.Linux.DMG', 'Game.Android.APK.Build.12', 'Tool.Setup.ISO.Win32', "\t", 'Vx.V'],
                [2 | 32 | 128 | 1 << 16, 4 | 8 | 2 << 16, 16 | 128 | 4 << 16, 2 | 64 | 1 << 16, 1, 0, 1, 2, 1, 1, 1, 1, 1, 2]],
            'console empty batch' => [ConsolePipe::class, [], [0, 0, 0, 0, 0, 0, 0]],
            'misc empty batch' => [MiscPipe::class, [], [0, 0, 0, 0, 0, 0]],
        ];
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('targetClasses')]
    public function test_edge_case_parity(string $class): void
    {
        $this->assertParity($class, [self::EDGE], 'edge batch');
        foreach (self::EDGE as $item) {
            $this->assertParity($class, [[$item]], 'edge item '.bin2hex($item));
        }
        // Keys are ignored and the per-record words keep the input order.
        $keyed = ['b' => 'PS4.FLAC.x64', 7 => 'alt.binaries.tv', 'a' => ''];
        $this->assertParity($class, [$keyed], 'keyed batch');
        $this->assertCount(3 + self::TARGETS[$class][2], $class::{self::TARGETS[$class][0].'Php'}($keyed));
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('targetClasses')]
    public function test_randomized_realistic_parity(string $class): void
    {
        foreach ([31, 32, 33] as $seed) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity($class, $this->benchArgs($class, new Randomizer(new Mt19937($seed)), $uncommon), 'seed '.$seed);
            }
        }
        // Random byte soup, including NUL, control and high bytes.
        $random = new Randomizer(new Mt19937(5443));
        $soup = [];
        for ($i = 0; $i < 300; $i++) {
            $length = $random->getInt(0, 40);
            $soup[] = $length === 0 ? '' : $random->getBytesFromString("PS4WIIXBOX360SNESflacmp3v12S01E02rRpartPART019abcdefABCDEF .-_\0\t\n\xff\xc3\xa9", $length);
        }
        $this->assertParity($class, [$soup], 'byte soup');
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function targetClasses(): array
    {
        $cases = [];
        foreach (array_keys(self::TARGETS) as $class) {
            $cases[(new ReflectionClass($class))->getShortName()] = [$class];
        }

        return $cases;
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of each helper through its public dispatcher, including joining,
        // the FFI call, decoding and any PHP fallback (release dylib, 5 warmups, 401 paired samples per helper
        // and workload, 20 iterations per common sample and 10 per uncommon sample). Common = one typical batch of
        // 128 well-formed names, group names or subjects; uncommon = 256 long, noisy and malformed rows (edge
        // cases, non-ASCII bytes, boundary and overlong tokens, empty and whitespace-only rows).
        // Speedup = PHP latency / Rust latency, ranges over 3 rounds (PHP 8.5 NTS, release dylib, Apple Silicon,
        // shared host at load average ~4.3-5.5):
        // - ConsolePipe:   common mean 5.43-5.63x / p99 4.68-5.91x, uncommon mean 4.34-4.62x / p99 3.44-5.30x
        // - GroupNamePipe: common mean 5.69-6.63x / p99 4.43-4.56x, uncommon mean 4.20-4.26x / p99 3.81-4.21x
        // - MiscPipe:      common mean 5.99-6.07x / p99 5.40-6.17x, uncommon mean 5.44-5.50x / p99 4.84-5.20x
        // - MusicPipe:     common mean 6.40-6.70x / p99 5.59-7.40x, uncommon mean 5.17-5.34x / p99 4.24-5.03x
        // - PcPipe:        common mean 5.94-6.32x / p99 4.84-5.85x, uncommon mean 4.73-5.01x / p99 4.09-4.84x
        // Every helper clears every gate in every round and defaults to Rust.
        // Each helper defaults to Rust only when it clears 1.5x on both workloads' mean and p99, per
        // .ai/rules/rust-performance.md; the assertion below requires every default to match its gates.
        // Reproduce:
        // cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
        // php artisan test --compact tests/Unit/RustCategorizationPipesConsoleGroupMiscMusicPcW54ParityTest.php --filter benchmark
        $this->requireLibrary();
        $random = new Randomizer(new Mt19937(5436));
        $warmup = 5;
        $samples = 401;
        $sink = 0;
        $speedups = [];
        foreach (self::TARGETS as $class => [$entry]) {
            $workloads = [
                'common' => [$this->benchArgs($class, new Randomizer(new Mt19937(5437)), false), 20],
                'uncommon' => [$this->benchArgs($class, new Randomizer(new Mt19937(5438)), true), 10],
            ];
            foreach ($workloads as $name => [$args, $iterations]) {
                $this->assertSame($class::{$entry.'Php'}(...$args), $class::{$entry.'Native'}(...$args), $class.' '.$name);
                for ($w = 0; $w < $warmup; $w++) {
                    foreach ([false, true] as $useRust) {
                        $sink += \count($class::withW54RustFfi($useRust, static fn (): array => $class::$entry(...$args)));
                    }
                }
                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < $samples; $s++) {
                    $order = $random->getInt(0, 1) === 1 ? [true, false] : [false, true];
                    foreach ($order as $useRust) {
                        $this->setOverride($class, $useRust);
                        $start = hrtime(true);
                        for ($i = 0; $i < $iterations; $i++) {
                            $sink += \count($class::$entry(...$args));
                        }
                        $times[$useRust ? 'rust' : 'php'][] = (hrtime(true) - $start) / 1_000 / $iterations;
                    }
                }
                $this->setOverride($class, null);
                $speedups[$class][$name] = [
                    'mean' => round($this->mean($times['php']) / $this->mean($times['rust']), 3),
                    'p99' => round($this->p99($times['php']) / $this->p99($times['rust']), 3),
                    'php_mean_us' => round($this->mean($times['php']), 2),
                    'rust_mean_us' => round($this->mean($times['rust']), 2),
                ];
            }
        }

        $this->assertGreaterThan(0, $sink);
        $summary = json_encode($speedups, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES) ?: '';
        fwrite(STDERR, "\nRustCategorizationPipesConsoleGroupMiscMusicPcW54 speedup: {$summary}\n");
        foreach (array_keys(self::TARGETS) as $class) {
            $qualifies = true;
            foreach ($speedups[$class] as $speedup) {
                $qualifies = $qualifies && $speedup['mean'] >= 1.5 && $speedup['p99'] >= 1.5;
            }
            $this->assertSame($qualifies, $class::usesW54RustFfi(), $class.' default must match its 1.5x gates: '.$summary);
        }
    }

    /**
     * @param  list<mixed>  $args
     */
    private function assertParity(string $class, array $args, string $message): void
    {
        $entry = self::TARGETS[$class][0];
        $php = $class::{$entry.'Php'}(...$args);
        $this->assertSame($php, $class::{$entry.'Native'}(...$args), $class.' '.$message);
        $this->assertSame($php, $class::withW54RustFfi(true, static fn (): array => $class::$entry(...$args)), $class.' '.$message);
        $this->assertSame($php, $class::withW54RustFfi(false, static fn (): array => $class::$entry(...$args)), $class.' '.$message);
    }

    /**
     * Realistic rows for each helper; uncommon batches are larger and mix in edge cases, long noisy rows,
     * lowercase and non-ASCII words.
     *
     * @return list<mixed>
     */
    private function benchArgs(string $class, Randomizer $random, bool $uncommon): array
    {
        $size = $uncommon ? 256 : 128;
        $rows = [];
        for ($i = 0; $i < $size; $i++) {
            if ($uncommon && $random->getInt(0, 2) === 0) {
                $rows[] = $this->pick($random, self::EDGE);

                continue;
            }
            $row = match ($class) {
                ConsolePipe::class => $this->releaseName($random, ['PS4', 'PS3', 'PSV', 'PSP', 'XBOXONE', 'X360', 'XBOX', 'WiiU', 'Wii', '3DS', 'NDS',
                    'Switch', 'NSW', 'PS5', 'SNES', 'N64', 'GBA', 'PS2', 'USA', 'EUR', 'PAL', 'NTSC', 'JPN', 'MULTi5', 'ISO', 'NSP', 'Update', 'S01E02', 'GOG',
                    'Win64'], $uncommon),
                GroupNamePipe::class => $this->groupName($random, $uncommon),
                MiscPipe::class => $this->subject($random, $uncommon),
                MusicPipe::class => $this->releaseName($random, ['FLAC', 'MP3', 'WEB', 'CD', 'Vinyl', '24BIT', '96kHz', '320kbps', 'V0', 'CBR', 'VBR',
                    'Discography', 'VA', 'OST', 'Single', 'EP', 'AAC', 'OPUS', '2019', '1987', '2024', 'Remastered', 'Deluxe', 'DSD'], $uncommon),
                default => $this->releaseName($random, ['x64', 'x86', 'Win', 'Windows', 'MacOS', 'OSX', 'Linux', 'Android', 'APK', 'iOS', 'Keygen',
                    'Crack', 'Patch', 'Incl', 'Setup', 'ISO', 'Portable', 'v2', 'V10', 'Build', '1234', 'Multilingual', 'Retail', 'Pro'], $uncommon),
            };
            $rows[] = $uncommon && $random->getInt(0, 3) === 0 ? strtolower($row) : $row;
        }

        return [$rows];
    }

    /**
     * @param  list<string>  $tokens
     */
    private function releaseName(Randomizer $random, array $tokens, bool $noisy): string
    {
        $words = [$this->pick($random, ['Some', 'Artist', 'App', 'Game', 'The']), $this->pick($random, ['Title', 'Name', 'Album', 'Studio', 'Quest'])];
        for ($t = $random->getInt(1, $noisy ? 12 : 4); $t > 0; $t--) {
            $words[] = $this->pick($random, $tokens);
        }
        if ($noisy && $random->getInt(0, 3) === 0) {
            $words[] = "\xc3\xa9dition";
        }

        return implode($this->pick($random, ['.', ' ', '_', '-', '.']), $words).$this->pick($random, ['-CODEX', '-PLAZA', '-FLT', '-GRP', '', ' [x]']);
    }

    private function groupName(Randomizer $random, bool $noisy): string
    {
        $parts = [$this->pick($random, ['alt.binaries', 'alt.binaries', 'a.b', 'alt', 'comp'])];
        for ($t = $random->getInt(1, $noisy ? 6 : 2); $t > 0; $t--) {
            $parts[] = $this->pick($random, ['sounds', 'mp3', 'lossless', 'flac', 'tv', 'hdtv', 'teevee', 'x264', 'movies', 'dvd', 'bluray', 'games',
                'xbox360', 'wii', 'nintendo', 'ebook', 'comics', 'erotica', 'apps', 'mac', 'anime', 'boneless', 'misc', 'pictures', 'complete', 'big']);
        }

        return implode('.', $parts);
    }

    private function subject(Randomizer $random, bool $noisy): string
    {
        $stem = match ($random->getInt(0, 4)) {
            0 => bin2hex($random->getBytes(16)),
            1 => $random->getBytesFromString('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', $random->getInt(12, 40)),
            2 => 'Some.Release.Name.'.$random->getInt(1990, 2030).'.1080p.WEB-GRP',
            3 => (string) $random->getInt(100000, PHP_INT_MAX),
            default => 'Title '.$random->getInt(1, 999).($noisy ? str_repeat(' and more words', $random->getInt(1, 10)) : ''),
        };
        $ext = $this->pick($random, ['.rar', '.part01.rar', '.r00', '.par2', '.vol01+02.par2', '.7z', '.zip', '.nfo', '.sfv', '.nzb', '.mkv']);

        return $this->pick($random, ['', '[01/10] - ', 'N:/NZB [1/3] ', '(1/1) '])
            .'"'.$stem.$ext.'"'.$this->pick($random, [' yEnc (1/50)', ' yEnc', '', ' 1.2 GB']);
    }

    /**
     * A callback that throws a RuntimeException with $message.
     *
     * @return callable(): mixed
     */
    private function throwing(string $message): callable
    {
        return static function () use ($message): mixed {
            throw new RuntimeException($message);
        };
    }

    /**
     * Calls a Wave 54.3 export by name.
     */
    private function callExport(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function setOverride(string $class, ?bool $enabled): void
    {
        (new ReflectionMethod($class, 'w54RustFfiState'))->invoke(null, 'set', $enabled);
    }

    private function defaultOf(string $class): bool
    {
        return (bool) (new ReflectionClass($class))->getConstant('W54_RUST_FFI_DEFAULT');
    }

    private function requireLibrary(): void
    {
        if (! \extension_loaded('FFI') || RustPipelineLibrary::resolveDomain('categorize_w54', ConsolePipe::W54_FFI_CDEF) === null) {
            $this->markTestSkipped('libnntmux_pipeline is not available.');
        }
    }

    /**
     * @param  list<float>  $values
     */
    private function mean(array $values): float
    {
        return array_sum($values) / max(1, \count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private function p99(array $values): float
    {
        sort($values);

        return $values[(int) min(\count($values) - 1, (int) ceil(\count($values) * 0.99) - 1)];
    }

    /**
     * @template T
     *
     * @param  list<T>  $values
     * @return T
     */
    private function pick(Randomizer $random, array $values): mixed
    {
        return $values[$random->getInt(0, \count($values) - 1)];
    }
}
