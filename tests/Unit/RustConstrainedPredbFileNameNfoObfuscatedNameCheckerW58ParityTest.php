<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\NameFixing\ConstrainedPredbMatcher;
use App\Services\NameFixing\Extractors\FileNameExtractor;
use App\Services\NameFixing\Extractors\NfoNameExtractor;
use App\Services\NameFixing\Extractors\ObfuscatedSubjectExtractor;
use App\Services\NameFixing\NameCheckerService;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

final class RustConstrainedPredbFileNameNfoObfuscatedNameCheckerW58ParityTest extends TestCase
{
    /** Class => batch entry point; each also has `*Php()` and `*Native()` variants. */
    private const ENTRIES = [
        ConstrainedPredbMatcher::class => 'rankCandidateBatch',
        FileNameExtractor::class => 'planFileNameBatch',
        NfoNameExtractor::class => 'planNfoBatch',
        ObfuscatedSubjectExtractor::class => 'planSubjectBatch',
        NameCheckerService::class => 'planDispatchBatch',
    ];

    /** Class => Wave 58.2 export. */
    private const SYMBOLS = [
        ConstrainedPredbMatcher::class => 'nntmux_w58_predb_candidate_rank_batch',
        FileNameExtractor::class => 'nntmux_w58_filename_candidate_plan_batch',
        NfoNameExtractor::class => 'nntmux_w58_nfo_title_plan_batch',
        ObfuscatedSubjectExtractor::class => 'nntmux_w58_obfuscated_subject_plan_batch',
        NameCheckerService::class => 'nntmux_w58_name_checker_dispatch_plan_batch',
    ];

    /** Class => summary words after the per-item words (the digest included). */
    private const TAIL = [
        ConstrainedPredbMatcher::class => 5,
        FileNameExtractor::class => 6,
        NfoNameExtractor::class => 5,
        ObfuscatedSubjectExtractor::class => 6,
        NameCheckerService::class => 7,
    ];

    /**
     * Common mean/p99 then Uncommon mean/p99 speedups (PHP ns / Rust ns), each the minimum of
     * three runs of test_paired_randomized_common_and_uncommon_benchmarks (seed 58, 60
     * interleaved rounds of 10 pairs per class and workload after 2 warmups, 600 pooled samples
     * per metric, each side the fastest of 3 back-to-back calls, batches of 320 Common or
     * 320-640 Uncommon items, release Rust, PHP 8.5.12 CLI, Apple Silicon macOS on a shared host
     * at load average 6-11). Timings include dispatch, column checks, packing, the FFI call and
     * unpack('P*'). A class defaults to Rust only when all four reach 1.5x.
     */
    private const QUALIFICATION = [
        ConstrainedPredbMatcher::class => [5.883, 5.152, 2.965, 3.089],
        FileNameExtractor::class => [4.064, 3.666, 3.644, 3.592],
        NfoNameExtractor::class => [3.688, 3.783, 4.059, 3.997],
        ObfuscatedSubjectExtractor::class => [4.41, 3.526, 4.224, 3.653],
        NameCheckerService::class => [7.669, 6.929, 7.996, 8.364],
    ];

    /** Interleaved benchmark rounds; each times PAIRS pairs per class and workload. */
    private const ROUNDS = 60;

    private const PAIRS = 10;

    private const WARMUPS = 2;

    /** Back-to-back calls per side of a pair; the fastest is kept (drops preemption spikes). */
    private const REPEATS = 3;

    /** Batches per class and workload in the randomized parity sweep. */
    private const PARITY_BATCHES = 6;

    private const RELEASES = [
        'Some.Show.S01E02.720p.HDTV.x264-GRP', 'The.Great.Movie.2019.1080p.BluRay.x264-SPARKS', 'Another.Film.2021.2160p.WEB-DL.DDP5.1.HEVC-FLUX',
        'Docu.Series.S03E10.1080p.WEB.h264-GOSSIP', 'Some.Game.PS5-DUPLEX', 'Big.Game.Remastered-CODEX', 'Office.Suite.v2.1.x64.Incl.Keygen-XYZ',
        'Photo.Tool.Pro.v11.Multilingual.macOS-TNT', 'Old.Classic.1984.DVDRip.XviD-FiCO', 'Band.Name-Album.Title-2020-FLAC-WEB-GRP',
        'Night_Show_2023_10_05_720p_WEB_h264-JEBAITED', 'Cartoon.1x05.Episode.Name.PDTV-LOL', 'Racing.Game.NSW-VENOM',
        'Utility.2024.v3.Portable.Win64-P2P', 'Sci.Fi.Saga.Part.II.2008.720p.BRRip.x265-RARBG',
    ];

    private const WORDS = ['the', 'great', 'movie', 'show', 'night', 'big', 'game', 'tool', 'pro', 'film', 'series', 'docu', 'saga', 'old', 'new'];

    private const GROUPS = ['GRP', 'SPARKS', 'FLUX', 'GOSSIP', 'DUPLEX', 'CODEX', 'XYZ', 'TNT', 'FiCO', 'LOL', 'NTb', 'rarbg'];

    public function test_wave_methods_and_constants_exist(): void
    {
        foreach (self::ENTRIES as $class => $entry) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW58RustFfi', 'withW58RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                self::assertTrue($reflection->hasMethod($method), $class.'::'.$method);
                self::assertTrue($reflection->getMethod($method)->isPublic() && $reflection->getMethod($method)->isStatic(), $class.'::'.$method);
                self::assertSame($class, $reflection->getMethod($method)->getDeclaringClass()->getName(), $class.'::'.$method);
            }
            $state = $reflection->getMethod('w58RustFfiState');
            self::assertTrue($state->isPrivate() && $state->isStatic(), $class);
            self::assertSame(2, $state->getNumberOfParameters(), $class);
            self::assertTrue($reflection->hasConstant('W58_RUST_FFI_DEFAULT'), $class);
            self::assertIsBool($reflection->getConstant('W58_RUST_FFI_DEFAULT'), $class);
            self::assertTrue($reflection->getReflectionConstant('W58_RUST_FFI_DEFAULT')?->isPrivate(), $class);
            self::assertStringContainsString(self::SYMBOLS[$class], (string) $reflection->getConstant('W58_FFI_CDEF'), $class);
            foreach (array_keys($reflection->getStaticProperties()) as $property) {
                self::assertStringNotContainsString('w58', strtolower((string) $property), 'Wave 58 state lives in w58RustFfiState().');
            }
        }
    }

    public function test_existing_public_api_is_preserved(): void
    {
        $expected = [
            ConstrainedPredbMatcher::class => ['setUseRustFfi', 'usesRustFfi', 'parse', 'parseNative', 'parsePhp', 'suggest', 'suggestNative', 'suggestPhp',
                'completeEvidence', 'completeEvidenceNative', 'completeEvidencePhp'],
            FileNameExtractor::class => ['extractFromFile', 'extractFromFilePhp'],
            NfoNameExtractor::class => ['extractFromNfo'],
            ObfuscatedSubjectExtractor::class => ['extract', 'extractPhp'],
            NameCheckerService::class => ['addChecker', 'check', 'checkAll', 'checkWith', 'getCheckers', 'getStats'],
        ];
        foreach ($expected as $class => $methods) {
            $reflection = new ReflectionClass($class);
            foreach ($methods as $method) {
                self::assertTrue($reflection->hasMethod($method) && $reflection->getMethod($method)->isPublic(), $class.'::'.$method);
            }
        }
        self::assertSame('Some Movie 2019', (new ObfuscatedSubjectExtractor)->extractPhp('[01/10] - "some.movie.2019.part01.rar"'));
        self::assertNull((new FileNameExtractor)->extractFromFile(''));
    }

    public function test_defaults_match_recorded_qualification(): void
    {
        foreach (self::QUALIFICATION as $class => $speedups) {
            self::assertCount(4, $speedups, $class);
            self::assertSame(min($speedups) >= 1.5, $this->default($class), $class);
            self::assertSame($this->default($class), $class::usesW58RustFfi(), $class);
            if (min($speedups) < 1.5) {
                self::assertFalse($class::usesW58RustFfi(), $class.' retains PHP by default');
            }
        }
    }

    public function test_with_toggle_scopes_and_restores_routing(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $default = $this->default($class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW58RustFfi($enabled, static function () use ($class, $enabled): array {
                    $inner = $class::withW58RustFfi(! $enabled, static fn (): bool => $class::usesW58RustFfi());

                    return [$class::usesW58RustFfi(), $inner];
                });
                self::assertSame([$enabled, ! $enabled], $seen, $class);
                self::assertSame($default, $class::usesW58RustFfi(), $class);
            }
            $thrown = null;
            try {
                $class::withW58RustFfi(! $default, static fn () => throw new RuntimeException('boom'));
            } catch (RuntimeException $e) {
                $thrown = $e->getMessage();
            }
            self::assertSame('boom', $thrown, 'The callback exception must propagate.');
            self::assertSame($default, $class::usesW58RustFfi(), $class);
        }
    }

    public function test_toggles_stay_independent_of_each_other_and_earlier_switches(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $seen = $class::withW58RustFfi(! $this->default($class), static fn (): array => array_map(
                static fn (string $other): bool => $other::usesW58RustFfi(),
                array_keys(self::ENTRIES),
            ));
            foreach (array_keys(self::ENTRIES) as $i => $other) {
                self::assertSame($other === $class ? ! $this->default($other) : $this->default($other), $seen[$i], $class.' vs '.$other);
            }
        }
        // The Wave 58 toggles leave the earlier ConstrainedPredbMatcher switch untouched.
        $before = ConstrainedPredbMatcher::usesRustFfi();
        ConstrainedPredbMatcher::withW58RustFfi(! $this->default(ConstrainedPredbMatcher::class), static function () use ($before): void {
            self::assertSame($before, ConstrainedPredbMatcher::usesRustFfi());
        });
        self::assertSame($before, ConstrainedPredbMatcher::usesRustFfi());
    }

    public function test_entry_points_route_through_toggle(): void
    {
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(5800));
        foreach (array_keys(self::ENTRIES) as $class) {
            foreach ([false, true] as $uncommon) {
                $args = $this->fixture($class, $random, $uncommon);
                $php = $this->call($class, 'Php', $args);
                foreach ([true, false] as $native) {
                    self::assertSame($php, $class::withW58RustFfi($native, fn (): array => $this->call($class, '', $args)), $class);
                }
                self::assertSame($php, $this->call($class, 'Native', $args), $class);
            }
        }
    }

    public function test_native_helpers_fall_back_to_php_for_unsupported_input(): void
    {
        $this->requireBindings();
        // NUL bytes, keyed or ragged columns and non-integer table values keep the PHP semantics.
        $this->assertParity(ConstrainedPredbMatcher::class, ['Some.Movie.2019-GRP', 10, 1, ["Some.Movie\0.2019-GRP", 'x'], [10, 10], [1, 1]]);
        $this->assertParity(ConstrainedPredbMatcher::class, ['Some.Movie.2019-GRP', 10, 1, ['a' => 'Some.Movie.2019-GRP'], [10], [1]]);
        $this->assertParity(ConstrainedPredbMatcher::class, ['Some.Movie.2019-GRP', 10, 1, ['Some.Movie.2019-GRP', 'x'], [10], [1, 1]]);
        $this->assertParity(ConstrainedPredbMatcher::class, ['Some.Movie.2019-GRP', 10, 1, ['Some.Movie.2019-GRP'], [1 => 10], [1]]);
        $this->assertParity(ConstrainedPredbMatcher::class, ['Some.Movie.2019-GRP', 10, 1, ['Some.Movie.2019-GRP', 'b'], ['10', 9.5], [true, null]]);
        $this->assertParity(FileNameExtractor::class, [["a\0b.rar", 'x.mkv']]);
        $this->assertParity(FileNameExtractor::class, [[3 => 'Some.Show.S01E02-GRP.rar']]);
        $this->assertParity(NfoNameExtractor::class, [["Release: A.B.C\0-GRP", 'x']]);
        $this->assertParity(NfoNameExtractor::class, [['k' => 'Release: Some.Movie.2019-GRP']]);
        $this->assertParity(ObfuscatedSubjectExtractor::class, [["\"abc\0def\" yEnc", 'x']]);
        $this->assertParity(ObfuscatedSubjectExtractor::class, [[1 => 'x', 0 => 'y']]);
        $this->assertParity(NameCheckerService::class, [["Some.Show\0.S01E02-GRP", 'Some.Movie.2019.1080p-GRP']]);
        $this->assertParity(NameCheckerService::class, [['x' => 'Some.Show.S01E02-GRP']]);
    }

    public function test_exports_guard_null_short_and_malformed_batches(): void
    {
        $this->requireBindings();
        $ints = pack('P', 5);
        // [good args, malformed args, words, empty args, empty words] before (out, out_cap).
        $guards = [
            ConstrainedPredbMatcher::class => [['q', 1, 1, 1, 't', 1, $ints, 8, $ints, 8, 1], ['q', 1, 1, 1, "a\0b", 3, $ints, 8, $ints, 8, 1], 6,
                [null, 0, 0, 0, null, 0, null, 0, null, 0, 0], 5],
            FileNameExtractor::class => [['a', 1, 1], ["a\0b", 3, 1], 7, [null, 0, 0], 6],
            NfoNameExtractor::class => [['a', 1, 1], ["a\0b", 3, 1], 6, [null, 0, 0], 5],
            ObfuscatedSubjectExtractor::class => [['a', 1, 1], ["a\0b", 3, 1], 7, [null, 0, 0], 6],
            NameCheckerService::class => [['a', 1, 1], ["a\0b", 3, 1], 8, [null, 0, 0], 7],
        ];
        foreach ($guards as $class => [$good, $bad, $words, $empty, $emptyWords]) {
            $ffi = $this->ffi($class);
            $out = $ffi->new('int64_t[32]');
            $symbol = self::SYMBOLS[$class];
            self::assertSame($words, $this->invoke($ffi, $symbol, [...$good, $out, 32]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, [...$good, null, 32]), $class);
            self::assertSame(-$words, $this->invoke($ffi, $symbol, [...$good, $out, $words - 1]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, [...$bad, $out, 32]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, [null, $good[1], ...\array_slice($good, 2), $out, 32]), $class);
            self::assertSame($emptyWords, $this->invoke($ffi, $symbol, [...$empty, $out, 32]), $class);
        }
        // Ragged integer tables are refused.
        $ffi = $this->ffi(ConstrainedPredbMatcher::class);
        $out = $ffi->new('int64_t[32]');
        $symbol = self::SYMBOLS[ConstrainedPredbMatcher::class];
        self::assertSame(-1, $this->invoke($ffi, $symbol, ['q', 1, 1, 1, 't', 1, $ints, 7, $ints, 8, 1, $out, 32]));
        self::assertSame(-1, $this->invoke($ffi, $symbol, ['q', 1, 1, 1, 't', 1, $ints, 8, $ints.$ints, 16, 1, $out, 32]));
    }

    public function test_predb_rank_parity(): void
    {
        $this->requireBindings();
        $args = ['Some.Movie.2019.1080p.BluRay.x264-grp', 1000, 0,
            ['Other.Show.S01E01-GRP', 'Some.Movie.2019.1080p.BluRay.x264-GRP', 'Some.Movie.2019.720p.WEB-OTHER', 'Some Movie 2019 1080p BluRay x264 GRP'],
            [1000, 1050, 5000, 1101], [0, 0, 0, 0]];
        $words = [
            1 | 4 | 1 << 8 | 18 << 16 | 3 << 24,
            1 | 4 | 8 | 16 | 7 << 8 | 100 << 16,
            8 | 3 << 8 | 46 << 16 | 2 << 24,
            1 | 8 | 16 | 7 << 8 | 100 << 16 | 1 << 24,
            2, 1, 100, 7,
        ];
        $this->assertWords(ConstrainedPredbMatcher::class, $args, $words);
        $this->assertParity(ConstrainedPredbMatcher::class, $args);
        // A tie on eligibility, score and flags keeps index order; file counts gate eligibility.
        $tie = ['A.Movie.Title.2020-GRP', 100, 3, ['A.Movie.Title.2020-GRP', 'A.Movie.Title.2020-GRP', 'A.Movie.Title.2020-GRP'], [100, 100, 100], [3, 3, 2]];
        $plan = $this->call(ConstrainedPredbMatcher::class, 'Native', $tie);
        self::assertSame([0, 1, 2], array_map(static fn (int $w): int => $w >> 24, \array_slice($plan, 0, 3)));
        self::assertSame([31, 31, 13], array_map(static fn (int $w): int => $w & 0xFF, \array_slice($plan, 0, 3)));
        self::assertSame([2, 0, 100, 5], \array_slice($plan, 3, 4));
        $this->assertRandomParity(ConstrainedPredbMatcher::class, 5810);
    }

    public function test_filename_plan_parity(): void
    {
        $this->requireBindings();
        $names = ['dir\\Some.Show.S01E02.720p.HDTV.x264-GRP.part01.rar', 'a/b/sample-grp.mkv', '0123abcdef.r01', 'x/Some_Movie 2019..1080p-GRP.mkv',
            'Some.Movie.2019-GRP.vol003+04.PAR2', 'proof.jpg', '', '/', 'a.7z.001', 'a.b.c.d-GROUP.nzb'];
        $words = [
            $this->word(1 | 16 | 32, 'Some.Show.S01E02.720p.HDTV.x264-GRP'), $this->word(2 | 4, 'sample-grp'), $this->word(1 | 16 | 8, '0123abcdef'),
            $this->word(2 | 32 | 64, 'Some.Movie.2019.1080p-GRP'), $this->word(1 | 16 | 32, 'Some.Movie.2019-GRP'), $this->word(4, 'proof.jpg'),
            $this->word(8, ''), $this->word(8, ''), $this->word(1 | 16 | 8, 'a'), $this->word(1 | 32, 'a.b.c.d-GROUP'),
            4, 2, 4, 5, 4,
        ];
        $this->assertWords(FileNameExtractor::class, [$names], $words);
        $this->assertParity(FileNameExtractor::class, [$names]);
        $this->assertRandomParity(FileNameExtractor::class, 5820);
    }

    public function test_nfo_plan_parity(): void
    {
        $this->requireBindings();
        $nfo = "\u{2588}\u{2588} Fancy \u{2588}\u{2588}\r\nRelease....: Some.Movie.2019.1080p.BluRay.x264-GRP\r\nSize: 4.37 GB\nOther.Thing.Here";
        $nfos = [$nfo, '', "|| Title : The_Show_S01E01 ||\n  Some.Other.2001.Name-XYZ  ", "a\tb\nnope"];
        $words = [
            $this->word(17 | 32 | 64 | 128, 'Some.Movie.2019.1080p.BluRay.x264-GRP'), $this->word(0, ''),
            $this->word(10 | 32 | 64 | 128, 'The_Show_S01E01'), $this->word(0, ''),
            8, 16, 3, 2,
        ];
        $this->assertWords(NfoNameExtractor::class, [$nfos], $words);
        $this->assertParity(NfoNameExtractor::class, [$nfos]);
        $this->assertRandomParity(NfoNameExtractor::class, 5830);
    }

    public function test_subject_plan_parity(): void
    {
        $this->requireBindings();
        $subjects = ['[01/10] - "0123456789abcdef0123.part01.rar" yEnc (1/50)', '0f8fad5b-d9cb-469f-a165-70867728950e', 'aB3dE5fG7hJ9kL',
            '  plain subject (3/7) ', '[a]-[b] Some.Name.vol01+02.par2', '""x"" yEnc', '(1/2)'];
        $words = [
            $this->word(1 | 4 | 8 | 16 | 32, '0123456789abcdef0123'), $this->word(64, '0f8fad5b-d9cb-469f-a165-70867728950e'),
            $this->word(128, 'aB3dE5fG7hJ9kL'), $this->word(2, 'plain subject'), $this->word(8 | 16, 'Some.Name'), $this->word(1, 'x'), $this->word(2, ''),
            2, 1, 1, 1, 6,
        ];
        $this->assertWords(ObfuscatedSubjectExtractor::class, [$subjects], $words);
        $this->assertParity(ObfuscatedSubjectExtractor::class, [$subjects]);
        $this->assertRandomParity(ObfuscatedSubjectExtractor::class, 5840);
    }

    public function test_dispatch_plan_parity(): void
    {
        $this->requireBindings();
        $names = ['Some.Show.S01E02.720p.HDTV.x264-GRP', 'Some.Movie.2019.1080p.BluRay.x264-GRP', 'Some.Game.PS5-DUPLEX',
            'Some.App.v2.1.x64.Incl.Keygen-XYZ', 'plain words only', 'x', 'Show.1x05.2019.WEB-GRP', '12345678 90'];
        $words = [
            1 | 16 | 7 << 8, 2 | 16 | 1 << 5 | 7 << 8, 4 | 16 | 2 << 5 | 4 << 8, 8 | 16 | 3 << 5 | 8 << 8, 16 | 4 << 5 | 3 << 8, 4 << 5 | 1 << 8,
            1 | 2 | 16 | 5 << 8, 4 << 5 | 2 << 8,
            2, 1, 1, 1, 6, 1,
        ];
        $lowered = array_map(strtolower(...), $names);
        foreach ($lowered as $i => $lower) {
            $words[$i] |= crc32($lower) << 32;
        }
        $this->assertWords(NameCheckerService::class, [$names], $words);
        $this->assertParity(NameCheckerService::class, [$names]);
        $this->assertRandomParity(NameCheckerService::class, 5850);
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<mixed>  $args
     */
    #[DataProvider('edgeCases')]
    public function test_edge_case_parity(string $class, array $args): void
    {
        $this->requireBindings();
        $this->assertParity($class, $args);
    }

    /** @return iterable<string, array{key-of<self::ENTRIES>, list<mixed>}> */
    public static function edgeCases(): iterable
    {
        $long = str_repeat('Ab.', 25000);
        $binary = "\xff\xfe\x01".str_repeat("\xDB\xB2\x85", 30);
        $titles = ['', '-', '--', 'a-b', 'A-BC', 'x-'.str_repeat('a', 20), 'x-'.str_repeat('a', 21), '2019', '1999-GRP', '2100', '19999', 'The.The.The',
            $long, $binary, 'Some.Movie.2019-GRP', 'SOME.MOVIE.2019-grp', 'some movie 2019 grp', 'Some.Movie.2019-GR P', "Some\tMovie\n2019-GRP", '007.Bond-X1'];
        $n = \count($titles);
        yield 'predb empty' => [ConstrainedPredbMatcher::class, ['Some.Movie.2019-GRP', 1, 1, [], [], []]];
        yield 'predb empty query' => [ConstrainedPredbMatcher::class, ['', 0, 0, $titles, array_fill(0, $n, 0), array_fill(0, $n, 0)]];
        foreach ([[1 << 47, 1], [(1 << 47) + 1, 1], [PHP_INT_MAX, PHP_INT_MAX], [-1, -1], [PHP_INT_MIN, 0], [100, 0], [1, 1]] as [$size, $files]) {
            yield "predb sizes {$size} {$files}" => [ConstrainedPredbMatcher::class, ['Some.Movie.2019-GRP', $size, $files, $titles,
                [0, 1, -1, 90, 91, 100, 110, 111, 1 << 47, (1 << 47) + 1, PHP_INT_MAX, PHP_INT_MIN, $size, $size, $size, $size, $size, $size, 1, 2],
                [0, 1, -1, $files, $files, $files, PHP_INT_MAX, PHP_INT_MIN, $files, $files, $files, $files, $files, $files, $files, 0, 1, 2, 3, $files]]];
        }
        yield 'predb binary query' => [ConstrainedPredbMatcher::class, [$binary."\0-GRP", 5, 5, $titles, array_fill(0, $n, 5), array_fill(0, $n, 5)]];
        yield 'filenames empty' => [FileNameExtractor::class, [[]]];
        yield 'filenames bounds' => [FileNameExtractor::class, [[
            '', '.', '..', '.rar', 'rar', 'a.rar', 'a.RAR', 'a.part.rar', 'a.part1.rar', 'a.part12345.rar', 'a.part01.rar.rar', 'a.r0', 'a.r00', 'a.r000',
            'a.r0000', 'a.z1', 'a.z01', 'a.z001', 'a.00', 'a.001', 'a.0001', 'a.7z', 'a.7z.01', 'a.7z.001', 'a.7z.0001', 'a.vol1+2.par2', 'a.vol1-2.par2',
            'a.vol12345+1.par2', 'a.vol1*2.par2', 'a.par', 'a.par2', 'a.mkv.rar', 'a.rar.mkv', 'a.ts', 'a.TS', 'a..mkv', 'a/b\\c', 'a/b\\', '\\\\server\\x.mkv',
            '__a__b__-X1', '.-.-a.b-GRP-.-.', 'Some.Movie-'.str_repeat('G', 15), 'Some.Movie-'.str_repeat('G', 16), 'Some.Movie-G!', '-GROUP.Some',
            'SAMPLE.Some.Movie-GRP', 'Some.Movie.Subs-GRP.mkv', 'Some.Movie.Subtitles-GRP.mkv', 'abcdef0123456789', 'ABCDEF0123456789.rar',
            'ab cd', $long, $binary, $binary.'.rar', 'x/'.$long.'.r01',
        ]]];
        yield 'nfos empty' => [NfoNameExtractor::class, [[]]];
        yield 'nfos bounds' => [NfoNameExtractor::class, [[
            '', "\n", "\r\n\r\n", ':', 'a:', 'ab:', 'ab :x', ' ab : Some.Movie.2019-GRP ', 'Release:Some.Movie.2019-GRP', 'RELEASE ... : Some.Movie.2019-GRP',
            'Release Name: Some.Movie.2019-GRP', 'Rls Name. : A.B.C.D.E-F', 'Rls_Name: A.B.C.D.E-FG', 'Name1: Some.Movie.2019-GRP',
            str_repeat('k', 24).': Some.Movie.2019-GRP', str_repeat('k', 25).': Some.Movie.2019-GRP', 'Title: '.str_repeat('a.', 128),
            'Title: '.str_repeat('a.', 127).'a', 'Title: a.b', 'Title: a.b.c.d', 'Title: Some Movie 2019', 'Title: (Some).Movie.[2019]-GRP',
            "Title:\tSome.Movie.2019-GRP", "\x7fSome.Movie.2019-GRP", 'Some.Movie.20190-GRP', 'Some.Movie.2019x-GRP', 'Some.Movie-19999999-GRP',
            "Release: First.Name.2001-AB\nTitle: Second.Name.2001-AB", "Release: First.Name.2001-AB\r\nRelease: Better.Name.2001.Extra-AB",
            "\u{2554}\u{2550}\u{2557}\n\u{2551} Release: Boxed.Name.2020.1080p-GRP \u{2551}\n\u{255A}\u{2550}\u{255D}", $long, $binary, "x\x85y\x0bz",
        ]]];
        yield 'subjects empty' => [ObfuscatedSubjectExtractor::class, [[]]];
        yield 'subjects bounds' => [ObfuscatedSubjectExtractor::class, [[
            '', ' ', 'yEnc', 'yenc', 'a yEnc b yEnc c', '()', '(/)', '(1/)', '(1/2)', '(123456/1)', '(1/123456)', 'x (1/2) (3/4)', 'x(1/2)', '[', '[]', '[a]',
            '[a', '[a]x', '[a] - [b] - x', '['.str_repeat('a', 80).']x', '['.str_repeat('a', 81).']x', '"', '""', '"x"', '"x', 'a "b" "c"', "'x'",
            '.rar', 'x.RAR', 'x.part1.rar', 'x.vol1+1.par2', 'x.r01', 'x.001', 'x.7z', 'x.nfo', 'x.nzb.nfo', '-._x_.-', str_repeat('a', 15).'0',
            str_repeat('a', 16), str_repeat('A', 15).'0', '0F8FAD5B-D9CB-469F-A165-70867728950E', '0f8fad5b-d9cb-469f-a165-70867728950', '0f8fad5bxd9cb-469f-a165-70867728950e',
            'aaaaaaaaaaB1', 'aaaaaaaaaB1', str_repeat('aB1', 21).'x', str_repeat('aB1', 22), 'aaaaaaaaaaaaaaaa1B', 'Some Plain Name', $long, $binary, "\x0b\"q\"\x0b",
        ]]];
        yield 'names empty' => [NameCheckerService::class, [[]]];
        yield 'names bounds' => [NameCheckerService::class, [[
            '', 'abcdefg', 'abcdefgh', 'abcd efg', 'abcd.efg', '12345678', '1234567a b', str_repeat('a ', 127).'a', str_repeat('a ', 128), 's1e1 abcdefgh',
            's123e1 abcdefgh', 's12e1234 abcdefgh', 'S01E01E02 show', '1x01 show name', '1x001 show name', '123x01 show name', 'show 2019 web', 'show 1919 x264',
            'show 2100 x264', 'show 20190 x264', 'v1 tool name', 'v1234 tool name', 'V2 TOOL NAME', 'v tool name', 'PS5 ps4 game name', 'Win64 tool here',
            $long, $binary, $binary.' S01E01 hdtv', "tab\tseparated\tname",
        ]]];
    }

    /**
     * Paired randomized benchmark: ROUNDS interleaved rounds visit every class and workload in
     * turn; each round times PAIRS pairs (after WARMUPS) of the PHP and Rust batch entry point on
     * one randomly chosen fixture, alternating which runs first, each side keeping the fastest of
     * REPEATS back-to-back calls. Common batches hold 320 typical records, Uncommon batches
     * 320-640 malformed or adversarial ones. Mean and nearest-rank p99 speedups pool every
     * round's samples; per-round medians are reported too. Every pair must agree; raw pairs are
     * written outside the checkout. A class must default to Rust exactly when all four metrics
     * reach 1.5x. Gated behind NNTMUX_W582_BENCH=1.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        if (getenv('NNTMUX_W582_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W582_BENCH=1 to run the Wave 58.2 paired benchmark.');
        }
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(58));
        $fixtures = $samples = $records = [];
        foreach (array_keys(self::ENTRIES) as $class) {
            foreach (['Common', 'Uncommon'] as $workload) {
                for ($v = 0; $v < 8; $v++) {
                    $fixtures[$class][$workload][] = $this->fixture($class, $random, $workload === 'Uncommon');
                }
            }
        }
        for ($round = 0; $round < self::ROUNDS; $round++) {
            foreach (array_keys(self::ENTRIES) as $class) {
                foreach (['Common', 'Uncommon'] as $workload) {
                    for ($i = -self::WARMUPS; $i < self::PAIRS; $i++) {
                        $variant = $random->getInt(0, 7);
                        $order = ($i & 1) === 0 ? [false, true] : [true, false];
                        $results = $elapsed = [];
                        foreach ($order as $native) {
                            $args = $fixtures[$class][$workload][$variant];
                            [$results[(int) $native], $elapsed[(int) $native]] = $class::withW58RustFfi($native, fn (): array => $this->timed($class, $args));
                        }
                        self::assertSame($results[0], $results[1], $class.' '.$workload);
                        if ($i >= 0) {
                            $samples[$class][$workload][$round][] = [$elapsed[0], $elapsed[1]];
                            $records[] = [$class, $workload, $round, $variant, $order[0], $elapsed[0], $elapsed[1]];
                        }
                    }
                }
            }
        }
        $summary = [];
        foreach ($samples as $class => $workloads) {
            $metrics = [];
            foreach ($workloads as $workload => $rounds) {
                $php = $rust = $roundMeans = $roundP99s = [];
                foreach ($rounds as $pairs) {
                    $roundPhp = array_column($pairs, 0);
                    $roundRust = array_column($pairs, 1);
                    $roundMeans[] = array_sum($roundPhp) / array_sum($roundRust);
                    $roundP99s[] = $this->p99($roundPhp) / $this->p99($roundRust);
                    array_push($php, ...$roundPhp);
                    array_push($rust, ...$roundRust);
                }
                $summary[$class][$workload] = [
                    'mean_speedup' => round(array_sum($php) / array_sum($rust), 3), 'p99_speedup' => round($this->p99($php) / $this->p99($rust), 3),
                    'round_median_mean_speedup' => round($this->median($roundMeans), 3), 'round_median_p99_speedup' => round($this->median($roundP99s), 3),
                    'php_mean_ns' => (int) (array_sum($php) / \count($php)), 'rust_mean_ns' => (int) (array_sum($rust) / \count($rust)),
                    'php_p99_ns' => $this->p99($php), 'rust_p99_ns' => $this->p99($rust), 'samples' => \count($php),
                ];
                $metrics[] = $summary[$class][$workload]['mean_speedup'];
                $metrics[] = $summary[$class][$workload]['p99_speedup'];
            }
            $summary[$class]['qualifies'] = min($metrics) >= 1.5;
        }
        $artifact = sys_get_temp_dir().'/w582-benchmark-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($artifact, json_encode(['php' => PHP_VERSION, 'os' => php_uname(), 'rounds' => self::ROUNDS, 'pairs' => self::PAIRS, 'warmups' => self::WARMUPS,
            'repeats' => self::REPEATS, 'seed' => 58, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW58.2 paired benchmark artifact: {$artifact}\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach (array_keys(self::ENTRIES) as $class) {
            self::assertSame($summary[$class]['qualifies'], $class::usesW58RustFfi(), $class.' default must follow the measured 1.5x gate');
        }
    }

    /**
     * Every rule is byte-level ASCII, so an explicit setlocale() (which makes PHP build PCRE
     * tables from LC_CTYPE) changes neither implementation and the exports keep running.
     * Declared last: once a script sets a locale, later regexes keep the rebuilt tables.
     */
    public function test_parity_holds_under_locale_tables(): void
    {
        $this->requireBindings();
        $locale = setlocale(LC_CTYPE, '0');
        try {
            foreach (['C.UTF-8', 'en_US.ISO8859-1', 'tr_TR.ISO8859-9'] as $candidate) {
                if (setlocale(LC_CTYPE, $candidate) === false) {
                    continue;
                }
                $cases = [
                    ConstrainedPredbMatcher::class => ["S\xC9ME.MOVIE.2019-GR\xC9", 10, 0, ["s\xE9me.movie.2019-gr\xE9", 'SOME.MOVIE.2019-GRI', "I\xFDI.2019-I"], [10, 10, 10], [0, 0, 0]],
                    FileNameExtractor::class => [["S\xC9MPLE.Movie-GRP.R01", "\xC9.PART1.RAR", 'Title.II.2019-GRI.MKV']],
                    NfoNameExtractor::class => [["RELEASE: T\xC9ST.Name-GRP\nT\xCDTLE: X.Y.Z-AB", "TITLE: I.I.I.2019-II\n\xA0\xA0"]],
                    ObfuscatedSubjectExtractor::class => [["\"\xC9ABCDEF0123456789\" YENC", 'IIIIIIIIIIIi1', "AB\xC9DEF0123456789A"]],
                    NameCheckerService::class => [["S\xC9ME.SHOW.S01E02.HDTV-GRP", 'TITLE.2019.WEB.I-II', "I\xFDI.PS5.GAME-X"]],
                ];
                foreach ($cases as $class => $args) {
                    $this->assertParity($class, $args);
                    self::assertSame($this->call($class, 'Php', $args), $this->exported($class, $args), $class);
                }
            }
        } finally {
            setlocale(LC_CTYPE, (string) $locale);
        }
    }

    // ---- helpers -----------------------------------------------------------------------------

    private function word(int $flags, string $text): int
    {
        return $flags | min(\strlen($text), 0xFFFFFF) << 8 | crc32($text) << 32;
    }

    /**
     * The fastest of REPEATS back-to-back calls of the class's batch entry point.
     *
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<mixed>  $args
     * @return array{list<int>, int}
     */
    private function timed(string $class, array $args): array
    {
        $best = PHP_INT_MAX;
        $result = [];
        for ($r = 0; $r < self::REPEATS; $r++) {
            $start = hrtime(true);
            $result = $this->call($class, '', $args);
            $best = min($best, hrtime(true) - $start);
        }

        return [$result, $best];
    }

    /** @param  list<int>  $values */
    private function p99(array $values): int
    {
        sort($values);

        return $values[max(0, (int) ceil(\count($values) * 0.99) - 1)];
    }

    /** @param  array<int|float>  $values */
    private function median(array $values): int|float
    {
        sort($values);

        return $values[intdiv(\count($values), 2)] ?? 0;
    }

    /** @param  key-of<self::ENTRIES>  $class */
    private function default(string $class): bool
    {
        $value = (new ReflectionClass($class))->getConstant('W58_RUST_FFI_DEFAULT');
        self::assertIsBool($value);

        return $value;
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<mixed>  $args
     * @return list<int>
     */
    private function call(string $class, string $suffix, array $args): array
    {
        /** @var list<int> */
        return [$class, self::ENTRIES[$class].$suffix](...$args);
    }

    /**
     * The batch column of a fixture.
     *
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<mixed>  $args
     * @return array<array-key, mixed>
     */
    private function items(string $class, array $args): array
    {
        $items = $class === ConstrainedPredbMatcher::class ? $args[3] : $args[0];
        self::assertIsArray($items);

        return $items;
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<mixed>  $args
     */
    private function assertParity(string $class, array $args): void
    {
        $php = $class::withW58RustFfi(false, fn (): array => $this->call($class, '', $args));
        $rust = $class::withW58RustFfi(true, fn (): array => $this->call($class, '', $args));
        self::assertSame($php, $rust, $class);
        self::assertSame($php, $this->call($class, 'Php', $args), $class);
        self::assertSame($php, $this->call($class, 'Native', $args), $class);
        self::assertCount(\count($this->items($class, $args)) + self::TAIL[$class], $php, $class);
        self::assertSame($this->digest(\array_slice($php, 0, -1)), $php[\count($php) - 1], $class);
    }

    /**
     * Documented words (without the digest) from both implementations.
     *
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<mixed>  $args
     * @param  list<int>  $words
     */
    private function assertWords(string $class, array $args, array $words): void
    {
        foreach (['Php', 'Native'] as $suffix) {
            self::assertSame($words, \array_slice($this->call($class, $suffix, $args), 0, -1), $class.'::'.$suffix);
        }
    }

    /**
     * PARITY_BATCHES Common and Uncommon batches (at least 300 items each) must match, and the
     * Rust export must actually produce them (no silent PHP fallback).
     *
     * @param  key-of<self::ENTRIES>  $class
     */
    private function assertRandomParity(string $class, int $seed): void
    {
        $items = 0;
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < self::PARITY_BATCHES; $i++) {
                $args = $this->fixture($class, new Randomizer(new Mt19937($seed + 2 * $i + (int) $uncommon)), $uncommon);
                $this->assertParity($class, $args);
                self::assertSame($this->call($class, 'Php', $args), $this->exported($class, $args), $class);
                $items += \count($this->items($class, $args));
            }
        }
        self::assertGreaterThanOrEqual(300 * 2 * self::PARITY_BATCHES, $items, $class);
    }

    /**
     * Calls the export directly with the entry point's framing.
     *
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<mixed>  $args
     * @return list<int>
     */
    private function exported(string $class, array $args): array
    {
        $ffi = $this->ffi($class);
        $count = \count($this->items($class, $args));
        $cap = $count + 16;
        $out = $ffi->new('int64_t['.$cap.']');
        $call = match ($class) {
            ConstrainedPredbMatcher::class => [$args[0], \strlen($args[0]), $args[1], $args[2], $t = implode("\0", $args[3]), \strlen($t),
                $s = pack('P*', ...$args[4]), \strlen($s), $f = pack('P*', ...$args[5]), \strlen($f), $count],
            default => [$b = implode("\0", $args[0]), \strlen($b), $count],
        };
        $written = $this->invoke($ffi, self::SYMBOLS[$class], [...$call, $out, $cap]);
        self::assertIsInt($written);
        self::assertGreaterThan(0, $written, $class);
        /** @var array<int, int> $values */
        $values = unpack('P*', FFI::string($out, $written * 8));

        return array_values($values);
    }

    /** @param  key-of<self::ENTRIES>  $class */
    private function ffi(string $class): FFI
    {
        $ffi = (new ReflectionMethod($class, 'w58RustFfiState'))->invoke(null, 'ffi');
        self::assertInstanceOf(FFI::class, $ffi, $class.' must load the nfo_w58 binding');

        return $ffi;
    }

    /** Each Wave 58.2 export must resolve and accept a one-item batch, so parity runs Rust. */
    private function requireBindings(): void
    {
        self::assertTrue(\extension_loaded('FFI'), 'FFI extension required');
        $ints = pack('P', 5);
        $calls = [
            ConstrainedPredbMatcher::class => [['q', 1, 1, 1, 't', 1, $ints, 8, $ints, 8, 1], 6],
            FileNameExtractor::class => [['a', 1, 1], 7],
            NfoNameExtractor::class => [['a', 1, 1], 6],
            ObfuscatedSubjectExtractor::class => [['a', 1, 1], 7],
            NameCheckerService::class => [['a', 1, 1], 8],
        ];
        foreach ($calls as $class => [$args, $words]) {
            $ffi = $this->ffi($class);
            $out = $ffi->new('int64_t[32]');
            self::assertSame($words, $this->invoke($ffi, self::SYMBOLS[$class], [...$args, $out, 32]), $class);
        }
    }

    /**
     * Calls an export declared by a class's W58_FFI_CDEF.
     *
     * @param  list<mixed>  $args
     */
    private function invoke(FFI $ffi, string $symbol, array $args): mixed
    {
        return $ffi->{$symbol}(...$args);
    }

    /** @param  list<int>  $words */
    private function digest(array $words): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$words), true));

        return $digest[1];
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @return list<mixed>
     */
    private function fixture(string $class, Randomizer $random, bool $uncommon): array
    {
        return match ($class) {
            ConstrainedPredbMatcher::class => $this->predbBatch($random, $uncommon),
            FileNameExtractor::class => [$this->fileNameBatch($random, $uncommon)],
            NfoNameExtractor::class => [$this->nfoBatch($random, $uncommon)],
            ObfuscatedSubjectExtractor::class => [$this->subjectBatch($random, $uncommon)],
            NameCheckerService::class => [$this->nameBatch($random, $uncommon)],
        };
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

    /** Printable noise, or binary noise without NUL bytes. */
    private function noise(Randomizer $random, int $max, bool $binary = false): string
    {
        $length = $random->getInt(0, $max);
        if ($length === 0) {
            return '';
        }

        return $binary ? str_replace("\0", "\1", $random->getBytes($length))
            : $random->getBytesFromString('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789._-/ ', $length);
    }

    private function batchSize(Randomizer $random, bool $uncommon): int
    {
        return $uncommon ? $random->getInt(320, 640) : 320;
    }

    /** A random scene-style release name. */
    private function release(Randomizer $random): string
    {
        if ($random->getInt(0, 2) === 0) {
            return $this->pick($random, self::RELEASES);
        }
        $words = [];
        for ($w = $random->getInt(1, 4); $w > 0; $w--) {
            $words[] = ucfirst($this->pick($random, self::WORDS));
        }
        $words[] = $random->getInt(0, 1) === 0 ? (string) $random->getInt(1950, 2026) : \sprintf('S%02dE%02d', $random->getInt(1, 12), $random->getInt(1, 24));
        $words[] = $this->pick($random, ['720p', '1080p', '2160p', 'DVDRip']);
        $words[] = $this->pick($random, ['BluRay', 'WEB-DL', 'HDTV', 'WEBRip', 'BDRip']);
        $words[] = $this->pick($random, ['x264', 'x265', 'HEVC', 'XviD', 'h264']);

        return implode($this->pick($random, ['.', '.', '.', '_', ' ']), $words).'-'.$this->pick($random, self::GROUPS);
    }

    /** @return array{string, int, int, list<string>, list<int>, list<int>} */
    private function predbBatch(Randomizer $random, bool $uncommon): array
    {
        $query = $this->release($random);
        $querySize = $random->getInt(200_000_000, 20_000_000_000);
        $queryFiles = $random->getInt(0, 3) === 0 ? 0 : $random->getInt(1, 80);
        $titles = $sizes = $files = [];
        for ($i = $this->batchSize($random, $uncommon); $i > 0; $i--) {
            $title = match ($random->getInt(0, 5)) {
                0 => $query,
                1 => strtoupper($query),
                2 => (string) preg_replace('/-[^-]+$/', '-'.$this->pick($random, self::GROUPS), $query),
                3 => str_replace(['1080p', '720p', '2160p'], ['720p', '1080p', '1080p'], $query),
                default => $this->release($random),
            };
            $size = (int) ($querySize * $random->getFloat(0.8, 1.2));
            $count = $queryFiles + $random->getInt(-1, 1);
            if ($uncommon) {
                $title = match ($random->getInt(0, 9)) {
                    0 => '',
                    1 => $this->noise($random, 200, true),
                    2 => $title.'-'.$this->noise($random, 25),
                    3 => str_repeat($title.'.', $random->getInt(2, 20)),
                    4 => $this->noise($random, 60),
                    default => $title,
                };
                $size = match ($random->getInt(0, 5)) {
                    0 => $this->pick($random, [0, -1, PHP_INT_MIN, PHP_INT_MAX, 1 << 47, (1 << 47) + 1]),
                    1 => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    default => $size,
                };
                $count = $random->getInt(0, 4) === 0 ? $this->pick($random, [0, -5, PHP_INT_MAX, PHP_INT_MIN]) : $count;
            }
            $titles[] = $title;
            $sizes[] = $size;
            $files[] = $count;
        }
        if ($uncommon) {
            $query = match ($random->getInt(0, 4)) {
                0 => $this->noise($random, 120, true),
                1 => $query.'-'.$this->noise($random, 30),
                default => $query,
            };
            $querySize = $this->pick($random, [$querySize, 0, -1, PHP_INT_MAX, 1 << 47]);
            $queryFiles = $this->pick($random, [$queryFiles, 0, -1, PHP_INT_MAX]);
        }

        return [$query, $querySize, $queryFiles, $titles, $sizes, $files];
    }

    /** @return list<string> */
    private function fileNameBatch(Randomizer $random, bool $uncommon): array
    {
        $names = [];
        for ($i = $this->batchSize($random, $uncommon); $i > 0; $i--) {
            $release = $this->release($random);
            $name = match ($random->getInt(0, 9)) {
                0 => $release.'/'.strtolower($release).'.r'.\sprintf('%02d', $random->getInt(0, 99)),
                1 => $release.'\\'.$release.'.part'.\sprintf('%02d', $random->getInt(1, 40)).'.rar',
                2 => $release.'/Sample/'.strtolower($this->pick($random, self::GROUPS)).'-sample.mkv',
                3 => $release.'/Proof/'.strtolower($release).'-proof.jpg',
                4 => $release.'/Subs/'.strtolower($release).'.subs.rar',
                5 => bin2hex($random->getBytes(16)).'.'.\sprintf('%03d', $random->getInt(1, 200)),
                6 => $release.'.vol'.\sprintf('%03d', $random->getInt(0, 99)).'+'.\sprintf('%02d', $random->getInt(1, 64)).'.par2',
                7 => $release.'.mkv',
                8 => $release.'.nfo',
                default => $release.'.rar',
            };
            if ($uncommon) {
                $name = match ($random->getInt(0, 11)) {
                    0 => '',
                    1 => $this->noise($random, 160, true),
                    2 => strtoupper($name),
                    3 => str_replace(['.', '-'], ['_', ' '], $name),
                    4 => $name.$this->pick($random, ['.rar', '.001', '.7z.001', '.z01', '.mkv', '..', '.']),
                    5 => str_repeat('/', $random->getInt(1, 5)).$name,
                    6 => $this->noise($random, 20).'.'.$this->noise($random, 6),
                    7 => str_repeat($release.'.', $random->getInt(10, 60)).'rar',
                    default => $name,
                };
            }
            $names[] = $name;
        }

        return $names;
    }

    private function nfo(Randomizer $random): string
    {
        $art = ["\u{2588}\u{2588}\u{2588}\u{2584}\u{2584} \u{2580}\u{2580}\u{2588}\u{2588}", '|| ** == ~~ ^^ ||', '##########################',
            "\u{2554}\u{2550}\u{2550}\u{2550}\u{2550}\u{2550}\u{2557}", "\xDB\xDB\xB2\xB1\xB0 \xDC\xDF", '<<<< [ {   } ] >>>>'];
        $release = $this->release($random);
        $lines = [];
        for ($l = $random->getInt(2, 5); $l > 0; $l--) {
            $lines[] = '   '.$this->pick($random, $art).'   '.$this->pick($random, $art);
        }
        $keys = ['Release', 'Title', 'Name', 'RLS Name', 'Release Name', 'Release.....', 'Size', 'Date', 'Genre', 'Source', 'Video', 'Audio', 'Runtime', 'IMDb'];
        $values = ['4.37 GB', '2024-01-05', 'Drama / Thriller', 'BluRay', 'x264 @ 9800 kbps', 'DTS-HD MA 5.1', '1h 52m', 'https://www.imdb.com/title/tt1234567/'];
        for ($l = $random->getInt(8, 20); $l > 0; $l--) {
            $key = $this->pick($random, $keys);
            $value = \in_array($key, ['Release', 'Title', 'Name', 'RLS Name', 'Release Name', 'Release.....'], true) ? $release : $this->pick($random, $values);
            $lines[] = $this->pick($random, ['', '  ', '|| ', "\xDB "]).$key.$this->pick($random, [': ', ' : ', '....: ']).$value.$this->pick($random, ['', ' ||', "\r"]);
        }
        for ($l = $random->getInt(1, 4); $l > 0; $l--) {
            $lines[] = $this->pick($random, ['Greetings to all our friends', 'Enjoy this fine release', '', $release, 'Notes: none']);
        }

        return implode($this->pick($random, ["\n", "\r\n"]), $random->shuffleArray($lines));
    }

    /** @return list<string> */
    private function nfoBatch(Randomizer $random, bool $uncommon): array
    {
        $nfos = [];
        for ($i = $this->batchSize($random, $uncommon); $i > 0; $i--) {
            $nfo = $this->nfo($random);
            if ($uncommon) {
                $nfo = match ($random->getInt(0, 9)) {
                    0 => '',
                    1 => $this->noise($random, 800, true),
                    2 => str_repeat("\u{2588}", $random->getInt(100, 600)),
                    3 => str_repeat("Title: A.B.C.D-GRP\n", $random->getInt(10, 60)),
                    4 => strtoupper($nfo),
                    5 => str_replace(["\n", "\r\n"], "\r", $nfo),
                    6 => $nfo."\n".str_repeat('x.', $random->getInt(100, 400)),
                    default => $nfo,
                };
            }
            $nfos[] = $nfo;
        }

        return $nfos;
    }

    /** @return list<string> */
    private function subjectBatch(Randomizer $random, bool $uncommon): array
    {
        $subjects = [];
        for ($i = $this->batchSize($random, $uncommon); $i > 0; $i--) {
            $parts = $random->getInt(1, 120);
            $file = match ($random->getInt(0, 5)) {
                0 => bin2hex($random->getBytes(16)),
                1 => implode('-', [substr($h = bin2hex($random->getBytes(16)), 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20, 12)]),
                2 => $random->getBytesFromString('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', $random->getInt(12, 32)),
                default => $this->release($random),
            };
            $file .= $this->pick($random, ['.part'.\sprintf('%02d', $random->getInt(1, 50)).'.rar', '.r'.\sprintf('%02d', $random->getInt(0, 99)),
                '.vol00+01.par2', '.par2', '.nzb', '.nfo', '.7z', '.'.\sprintf('%03d', $random->getInt(1, 300)), '']);
            $subject = match ($random->getInt(0, 4)) {
                0 => \sprintf('[%02d/%02d] - "%s" yEnc (1/%d) %d', $random->getInt(1, 50), 50, $file, $parts, $random->getInt(1000, 99999999)),
                1 => \sprintf('[PRiVATE]-[WtFnZb]-[%s]-[%d/%d] - "%s" yEnc (%d/%d)', $this->release($random), $random->getInt(1, 30), 30, $file, 1, $parts),
                2 => \sprintf('%s - "%s" yEnc', $this->pick($random, ['Re: ', 'Posted by nzb', '']), $file),
                3 => \sprintf('"%s" (%d/%d)', $file, $random->getInt(1, 9), $parts),
                default => $file,
            };
            if ($uncommon) {
                $subject = match ($random->getInt(0, 9)) {
                    0 => '',
                    1 => $this->noise($random, 300, true),
                    2 => str_repeat('[x]', $random->getInt(1, 40)).$subject,
                    3 => str_repeat('"', $random->getInt(1, 5)).$subject,
                    4 => $subject.' yEnc yEnc (1/2)',
                    5 => " \t\x0b".$subject."\n\0 ",
                    6 => strtolower($subject),
                    default => $subject,
                };
                $subject = str_replace("\0", '', $subject);
            }
            $subjects[] = $subject;
        }

        return $subjects;
    }

    /** @return list<string> */
    private function nameBatch(Randomizer $random, bool $uncommon): array
    {
        $names = [];
        for ($i = $this->batchSize($random, $uncommon); $i > 0; $i--) {
            $name = $this->release($random);
            if ($random->getInt(0, 4) === 0) {
                $name = $this->pick($random, ['Various Artists - Summer Hits', 'Random.Words.Only-NOGRP', 'Some.App.v'.$random->getInt(1, 20).'.Win64-TNT',
                    'Some.Game.'.$this->pick($random, ['PS4', 'PS5', 'NSW', 'XBOXONE']).'-DUPLEX', 'Show.'.$random->getInt(1, 9).'x'.\sprintf('%02d', $random->getInt(1, 24)).'.PDTV-LOL']);
            }
            if ($uncommon) {
                $name = match ($random->getInt(0, 9)) {
                    0 => '',
                    1 => $this->noise($random, 300, true),
                    2 => $this->noise($random, 8),
                    3 => str_repeat($name.' ', $random->getInt(5, 30)),
                    4 => strtoupper($name),
                    5 => str_replace(['.', '-'], ["\xC9", "\xA0"], $name),
                    default => $name,
                };
            }
            $names[] = $name;
        }

        return $names;
    }
}
