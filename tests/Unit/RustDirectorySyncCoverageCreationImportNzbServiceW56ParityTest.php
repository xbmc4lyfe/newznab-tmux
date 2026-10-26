<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Nzb\DirectorySyncBridge;
use App\Services\Nzb\NzbArticleCoverage;
use App\Services\Nzb\NzbCreationCandidateQuery;
use App\Services\Nzb\NzbImportService;
use App\Services\Nzb\NzbParserService;
use App\Services\Nzb\NzbService;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

final class RustDirectorySyncCoverageCreationImportNzbServiceW56ParityTest extends TestCase
{
    /** Class => batch entry point; each also has `*Php()` and `*Native()` variants. */
    private const ENTRIES = [
        DirectorySyncBridge::class => 'planDirectorySyncBatch',
        NzbArticleCoverage::class => 'planCoverageBatch',
        NzbCreationCandidateQuery::class => 'planCandidateBatch',
        NzbImportService::class => 'planImportFileBatch',
        NzbService::class => 'planStorageBatch',
    ];

    /** Class => Wave 56.2 export. */
    private const SYMBOLS = [
        DirectorySyncBridge::class => 'nntmux_w56_directory_sync_plan_batch',
        NzbArticleCoverage::class => 'nntmux_w56_article_coverage_plan_batch',
        NzbCreationCandidateQuery::class => 'nntmux_w56_creation_candidate_plan_batch',
        NzbImportService::class => 'nntmux_w56_import_file_plan_batch',
        NzbService::class => 'nntmux_w56_nzb_storage_plan_batch',
    ];

    /**
     * Common mean/p99 then Uncommon mean/p99 speedups (PHP ns / Rust ns) recorded by
     * test_paired_randomized_common_and_uncommon_benchmarks (seed 56, 15 interleaved rounds of
     * 100 pairs per class and workload after 10 warmups, 1500 pooled samples per metric, each
     * side the fastest of 3 back-to-back calls, batches of 320 Common or 320-640 Uncommon items,
     * release Rust, PHP 8.5.12 CLI, Apple Silicon macOS on a shared host at load average 10-12).
     * Timings include dispatch, column validation, packing, the FFI call and unpack('P*'). A
     * class defaults to Rust only when all four reach 1.5x.
     */
    private const QUALIFICATION = [
        DirectorySyncBridge::class => [3.512, 3.209, 3.494, 3.387],
        NzbArticleCoverage::class => [3.163, 3.321, 3.222, 3.493],
        NzbCreationCandidateQuery::class => [5.672, 5.756, 5.196, 6.031],
        NzbImportService::class => [4.57, 4.436, 4.333, 4.554],
        NzbService::class => [4.209, 4.269, 4.949, 5.479],
    ];

    /** Interleaved benchmark rounds; each times PAIRS pairs per class and workload. */
    private const ROUNDS = 15;

    private const PAIRS = 100;

    private const WARMUPS = 10;

    /** Back-to-back calls per side of a pair; the fastest is kept (drops preemption spikes). */
    private const REPEATS = 3;

    /** Batches per class and workload in the randomized parity sweep. */
    private const PARITY_BATCHES = 6;

    private const RELEASES = [
        'Movie.Name.2024.1080p.BluRay.x264-GRP', 'Show.Name.S01E05.720p.WEB-DL.DDP5.1.H.264-NTb', 'Artist-Album-2023-WEB-FLAC-OMA',
        'Some_Documentary_2022_2160p_UHD', 'Game.Title.v1.2.3-RUNE', 'Old.Film.1968.DVDRip.XviD-iMBT', 'Show - S01E07 [1080p]',
    ];

    private const MEDIA = ['mkv', 'mp4', 'avi', 'MOV', 'ts', 'm2ts', 'mpeg', 'mpg', 'iso', 'divx', 'srt', 'rar', 'nfo', 'Ts'];

    public function test_wave_methods_and_constants_exist(): void
    {
        foreach (self::ENTRIES as $class => $entry) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW56RustFfi', 'withW56RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                self::assertTrue($reflection->hasMethod($method), $class.'::'.$method);
                self::assertTrue($reflection->getMethod($method)->isPublic() && $reflection->getMethod($method)->isStatic(), $class.'::'.$method);
                self::assertSame($class, $reflection->getMethod($method)->getDeclaringClass()->getName(), $class.'::'.$method);
            }
            $state = $reflection->getMethod('w56RustFfiState');
            self::assertTrue($state->isPrivate() && $state->isStatic(), $class);
            self::assertSame(2, $state->getNumberOfParameters(), $class);
            self::assertTrue($reflection->hasConstant('W56_RUST_FFI_DEFAULT'), $class);
            self::assertIsBool($reflection->getConstant('W56_RUST_FFI_DEFAULT'), $class);
            self::assertTrue($reflection->getReflectionConstant('W56_RUST_FFI_DEFAULT')?->isPrivate(), $class);
            self::assertStringContainsString(self::SYMBOLS[$class], (string) $reflection->getConstant('W56_FFI_CDEF'), $class);
            foreach (array_keys($reflection->getStaticProperties()) as $property) {
                self::assertStringNotContainsString('w56', strtolower((string) $property), 'Wave 56 state lives in w56RustFfiState().');
            }
        }
    }

    public function test_defaults_match_recorded_qualification(): void
    {
        foreach (self::QUALIFICATION as $class => $speedups) {
            self::assertCount(4, $speedups, $class);
            self::assertSame(min($speedups) >= 1.5, $this->default($class), $class);
            self::assertSame($this->default($class), $class::usesW56RustFfi(), $class);
            if (min($speedups) < 1.5) {
                self::assertFalse($class::usesW56RustFfi(), $class.' retains PHP by default');
            }
        }
    }

    public function test_with_toggle_scopes_and_restores_routing(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $default = $this->default($class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW56RustFfi($enabled, static function () use ($class, $enabled): array {
                    $inner = $class::withW56RustFfi(! $enabled, static fn (): bool => $class::usesW56RustFfi());

                    return [$class::usesW56RustFfi(), $inner];
                });
                self::assertSame([$enabled, ! $enabled], $seen, $class);
                self::assertSame($default, $class::usesW56RustFfi(), $class);
            }
            $thrown = null;
            try {
                $class::withW56RustFfi(! $default, static fn () => throw new RuntimeException('boom'));
            } catch (RuntimeException $e) {
                $thrown = $e->getMessage();
            }
            self::assertSame('boom', $thrown, 'The callback exception must propagate.');
            self::assertSame($default, $class::usesW56RustFfi(), $class);
        }
    }

    public function test_toggles_stay_independent_of_each_other_and_earlier_waves(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $seen = $class::withW56RustFfi(! $this->default($class), static fn (): array => array_map(
                static fn (string $other): bool => $other::usesW56RustFfi(),
                array_keys(self::ENTRIES),
            ));
            foreach (array_keys(self::ENTRIES) as $i => $other) {
                self::assertSame($other === $class ? ! $this->default($other) : $this->default($other), $seen[$i], $class.' vs '.$other);
            }
        }
        // The Wave 56 toggles leave the earlier switches untouched.
        $earlier = static fn (): array => [DirectorySyncBridge::usesRustFfi(), DirectorySyncBridge::usesW412RustFfi(), NzbArticleCoverage::usesW412RustFfi(),
            NzbCreationCandidateQuery::usesW412RustFfi(), NzbImportService::usesRustFfi(), NzbImportService::usesW412RustFfi(), NzbService::usesRustFfi(),
            NzbService::usesW402RustFfi()];
        $before = $earlier();
        DirectorySyncBridge::withW56RustFfi(true, static function () use ($earlier, $before): void {
            NzbImportService::withW56RustFfi(true, static function () use ($earlier, $before): void {
                NzbService::withW56RustFfi(false, static function () use ($earlier, $before): void {
                    self::assertSame($before, $earlier());
                });
            });
        });
        self::assertSame($before, $earlier());
    }

    public function test_entry_points_route_through_toggle(): void
    {
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(5600));
        foreach (array_keys(self::ENTRIES) as $class) {
            foreach ([false, true] as $uncommon) {
                $args = $this->fixture($class, $random, $uncommon);
                $php = $this->call($class, 'Php', $args);
                foreach ([true, false] as $native) {
                    self::assertSame($php, $class::withW56RustFfi($native, fn (): array => $this->call($class, '', $args)), $class);
                }
                self::assertSame($php, $this->call($class, 'Native', $args), $class);
            }
        }
    }

    public function test_native_helpers_fall_back_to_php_for_unsupported_input(): void
    {
        $this->requireBindings();
        // NUL bytes, ragged or keyed columns and non-integer values keep the PHP semantics.
        $this->assertParity(DirectorySyncBridge::class, [["/a\0b", '/c']]);
        $this->assertParity(DirectorySyncBridge::class, [['x' => '/a', 'y' => '/a/']]);
        $this->assertParity(NzbArticleCoverage::class, [[3, 4], [1], 0, 10000]);
        $this->assertParity(NzbArticleCoverage::class, [[3, '4', 5.0, null], [1, 2, '3', 4], 0, 10000]);
        $this->assertParity(NzbArticleCoverage::class, [[1 => 3, 2 => 4], [1, 2], 0, 10000]);
        $this->assertParity(NzbCreationCandidateQuery::class, [[1, 2], [1, 2], [2, 2], [5], 50, 0]);
        $this->assertParity(NzbCreationCandidateQuery::class, [[1, '2', 3.5], [1, 2, 3], [2, 2, 3], [5, 6, 7], 50, 0]);
        $this->assertParity(NzbCreationCandidateQuery::class, [[1, 2], [1, 2], [2, 2], [5, '6'], 50, 0]);
        $this->assertParity(NzbImportService::class, [["a\0.nzb", 'b.nzb'], ['<', '<'], 10]);
        $this->assertParity(NzbImportService::class, [['a.nzb', 'b.nzb'], ['<'], 10]);
        $this->assertParity(NzbService::class, [["g\0", 'h'], ['s', 't'], 2]);
        $this->assertParity(NzbService::class, [['g', 'h'], ["s\0", 't'], 2]);
        $this->assertParity(NzbService::class, [['g', 'h'], ['s'], 2]);
    }

    public function test_exports_guard_null_short_and_malformed_batches(): void
    {
        $this->requireBindings();
        // [good args, malformed args, words, empty args, empty words] before (out, out_cap).
        $guards = [
            DirectorySyncBridge::class => [['/a', 2, 1], ["/a\0b", 4, 1], 8, [null, 0, 0], 6],
            NzbArticleCoverage::class => [[pack('P*', 3, 1), 16, 1, 0, 0], [pack('P', 3), 8, 1, 0, 0], 12, [null, 0, 0, 0, 0], 10],
            NzbCreationCandidateQuery::class => [[pack('P*', 1, 1, 1, 5), 32, 1, 100, 0], [pack('P*', 1, 1, 1), 24, 1, 100, 0], 8, [null, 0, 0, 0, 0], 6],
            NzbImportService::class => [['a.nzb', 5, '<', 1, pack('P', 1), 8, 1, 1], ['a.nzb', 5, '<', 1, pack('P', 2), 8, 1, 1], 8,
                [null, 0, null, 0, null, 0, 0, 1], 6],
            NzbService::class => [['g', 1, 's', 1, 1, 1], ['g', 1, "s\0t", 3, 1, 1], 8, [null, 0, null, 0, 0, 0], 6],
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
        // Negative frame lengths are refused.
        $import = $this->ffi(NzbImportService::class);
        $importOut = $import->new('int64_t[32]');
        self::assertSame(-1, $this->invoke($import, self::SYMBOLS[NzbImportService::class], ["a\0b", 3, '<<', 2, pack('P*', -1, 3), 16, 2, 1, $importOut, 32]));
    }

    public function test_directory_sync_plan_parity(): void
    {
        $this->requireBindings();
        $paths = ['/a/b', '/a//b/', '/a/c', '', 'x://y', 'd', '\\srv\\nzb\\', '///', '/'];
        $words = [
            $this->word(2 | 16, '/a/b'), 0, $this->word(2 | 4 | 8, '/a/b'), -1, $this->word(2 | 16, '/a/c'), 0, $this->word(32, ''), -1,
            $this->word(1, 'x://y'), -1, $this->word(16, 'd'), 5, $this->word(2 | 4 | 16, '/srv/nzb'), 6, $this->word(2 | 4 | 16, '/'), 7,
            $this->word(2 | 8, '/'), -1,
            5, 1, 2, 1, 4,
        ];
        $this->assertWords(DirectorySyncBridge::class, [$paths], $words);
        $this->assertParity(DirectorySyncBridge::class, [$paths]);
        $this->assertRandomParity(DirectorySyncBridge::class, 5610);
    }

    public function test_coverage_plan_parity(): void
    {
        $this->requireBindings();
        $args = [[10, 10, 10, 0, PHP_INT_MAX], [10, 4, -3, 5, PHP_INT_MAX - 1], 6, 4000];
        $words = [3 | 4 | 8 | 10000 << 8, 0, 2 | 4 | 8 | 4000 << 8, 6, 1, 10, 0, 0, 2 | 4 | 8 | 10000 << 8, 1, 1, 2, 1, 1, 3, 3, PHP_INT_MAX, 17, 10];
        $this->assertWords(NzbArticleCoverage::class, $args, $words);
        $this->assertParity(NzbArticleCoverage::class, $args);
        // The plan agrees with summarizeCoverageCandidatesPhp() on every shared total.
        [$required, $covered, $tolerance, $minRatio] = $this->fixture(NzbArticleCoverage::class, new Randomizer(new Mt19937(5620)), true);
        $plan = $this->call(NzbArticleCoverage::class, 'Native', [$required, $covered, $tolerance, $minRatio]);
        $summary = NzbArticleCoverage::summarizeCoverageCandidatesPhp(array_map(
            static fn (int $need, int $cov): array => ['required' => $need, 'covered' => $cov], $required, $covered), $tolerance);
        $tail = \array_slice($plan, 2 * \count($required), 9);
        self::assertSame([$summary['full'], $summary['partial'], $summary['none'], $summary['invalid'], $summary['acceptable']], \array_slice($tail, 0, 5));
        self::assertSame([$summary['total_required'], $summary['total_missing'], $summary['max_missing']], \array_slice($tail, 6, 3));
        $this->assertRandomParity(NzbArticleCoverage::class, 5630);
    }

    public function test_candidate_plan_parity(): void
    {
        $this->requireBindings();
        $args = [[5, 3, 5, 0, 9, 4], [10, 10, 10, 10, 1, 10], [10, 10, 10, 10, 10, 10], [100, 200, 300, 400, 500, 200], 100, 2];
        $words = [
            1 | 4 | 8 | 100 << 8, 2, 1 | 4 | 8 | 16 | 100 << 8, 0, 1 | 2 | 4 | 100 << 8, -1, 4 | 100 << 8, -1, 1 | 10 << 8, -1,
            1 | 4 | 8 | 16 | 100 << 8, 1,
            4, 1, 5, 3, 2,
        ];
        $this->assertWords(NzbCreationCandidateQuery::class, $args, $words);
        $this->assertParity(NzbCreationCandidateQuery::class, $args);
        // Selected candidates follow claimBatch()'s postdate-descending, id-ascending order.
        [$ids, $received, $totals, $postdates] = $this->fixture(NzbCreationCandidateQuery::class, new Randomizer(new Mt19937(5640)), false);
        $plan = $this->call(NzbCreationCandidateQuery::class, 'Native', [$ids, $received, $totals, $postdates, 0, 0]);
        $ranked = [];
        foreach ($ids as $i => $id) {
            if ($plan[2 * $i + 1] >= 0) {
                $ranked[$plan[2 * $i + 1]] = [$postdates[$i], $id];
            }
        }
        ksort($ranked);
        self::assertNotSame([], $ranked);
        self::assertSame(range(0, \count($ranked) - 1), array_keys($ranked), 'Ranks are dense.');
        $sorted = $ranked = array_values($ranked);
        usort($sorted, static fn (array $a, array $b): int => $b[0] <=> $a[0] ?: $a[1] <=> $b[1]);
        self::assertSame($sorted, $ranked);
        $this->assertRandomParity(NzbCreationCandidateQuery::class, 5650);
    }

    public function test_import_file_plan_parity(): void
    {
        $this->requireBindings();
        $paths = ['in/a.nzb', 'b.NZB.GZ', 'c.nzb.gz', 'x/a.mkv.nzb', 'd.txt', 'Movie.MKV.NZB'."\n", '...nzb'];
        $heads = ['<nzb', "\x1f\x8b\x08\x00", '<nzb', "\xEF\xBB\xBF \r\n<?xml", "\x1f\x8b", " \t<", '<'];
        $words = [
            $this->word(1 | 8 | 64, 'a'), 0, $this->word(2 | 4 | 64, 'b'), 0, $this->word(2 | 8 | 16, 'c'), -1, $this->word(1 | 8 | 32, 'a'), -1,
            $this->word(4, 'd.txt'), -1, $this->word(8, 'Movie'), -1, $this->word(1 | 8, ''), -1,
            5, 2, 2, 1, 1,
        ];
        $this->assertWords(NzbImportService::class, [$paths, $heads, 2], $words);
        $this->assertParity(NzbImportService::class, [$paths, $heads, 2]);
        // The names agree with deriveReleaseNameFromNzbPath().
        $service = (new ReflectionClass(NzbImportService::class))->newInstanceWithoutConstructor();
        [$paths, $heads, $size] = $this->fixture(NzbImportService::class, new Randomizer(new Mt19937(5660)), true);
        $plan = $this->call(NzbImportService::class, 'Native', [$paths, $heads, $size]);
        foreach ($paths as $i => $path) {
            $name = $service->deriveReleaseNameFromNzbPath($path);
            self::assertSame(crc32($name), $plan[2 * $i] >> 32 & 0xFFFFFFFF, $path);
            self::assertSame(min(\strlen($name), 0xFFFFFF), $plan[2 * $i] >> 8 & 0xFFFFFF, $path);
        }
        $this->assertRandomParity(NzbImportService::class, 5670);
    }

    public function test_storage_plan_parity(): void
    {
        $this->requireBindings();
        $guid = '0123456789abcdef0123456789abcdef01234567';
        $args = [[$guid, 'Z', $guid], ['a&b', 'plain', "<'\">"], 2];
        $words = [
            $this->word(1 | 4, '0/1/'.$guid.'.nzb.gz'), 1 | crc32('a&amp;b') << 32, $this->word(0, 'Z/Z.nzb.gz'), crc32('plain') << 32,
            $this->word(1 | 2 | 4, '0/1/'.$guid.'.nzb.gz'), 4 | crc32('&lt;&apos;&quot;&gt;') << 32,
            2, 1, 2, 5, 2,
        ];
        $this->assertWords(NzbService::class, $args, $words);
        $this->assertParity(NzbService::class, $args);
        // The subpaths agree with buildNzbPathAtBasePath() + getNzbPath()'s file name.
        $service = (new ReflectionClass(NzbService::class))->newInstanceWithoutConstructor();
        $build = new ReflectionMethod(NzbService::class, 'buildNzbPathAtBasePath');
        [$guids, $subjects] = $this->fixture(NzbService::class, new Randomizer(new Mt19937(5680)), false);
        foreach ([1, 2, 4] as $levels) {
            $plan = $this->call(NzbService::class, 'Native', [$guids, $subjects, $levels]);
            foreach ($guids as $i => $g) {
                $path = $build->invoke($service, '', $g, $levels).$g.'.nzb.gz';
                self::assertSame(crc32($path), $plan[2 * $i] >> 32 & 0xFFFFFFFF, $g);
            }
        }
        $this->assertRandomParity(NzbService::class, 5690);
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
        $long = str_repeat('k', 70000);
        $binary = "\xff\xfe\x01".str_repeat("\xDB\xB2", 30);
        yield 'sync empty' => [DirectorySyncBridge::class, [[]]];
        yield 'sync bounds' => [DirectorySyncBridge::class, [[
            '', '/', '//', '\\', '\\\\', 'a', 'a/', 'a//', '/a', '//a//b//', 'C:\\nzb\\x', 'phar:///x//y/', '://', ':/', 'a:/b', $long, $long.'/', '/'.$long.'/x',
            $binary, "/\xff/\xfe/", '.', './', '../a', 'a/./b', ' / ', "/a\n/", '/a/b', '/a/b/', '/a\\b', '/A/b',
        ]]];
        yield 'coverage empty' => [NzbArticleCoverage::class, [[], [], 0, 10000]];
        yield 'coverage bounds' => [NzbArticleCoverage::class, [
            [1, 1, 1, 2, 3, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, 922337203685477, 922337203685478, 922337203685478, -1, 0, PHP_INT_MIN, 10000, 9999],
            [0, 1, 2, 1, PHP_INT_MIN, PHP_INT_MAX, 0, 922337203685477, 922337203685476, 922337203685477, 92233720368547, 5, 5, 5, 9999, 1],
            PHP_INT_MAX, PHP_INT_MIN,
        ]];
        yield 'coverage saturating' => [NzbArticleCoverage::class, [[PHP_INT_MAX, PHP_INT_MAX, 7], [0, 0, 1], -5, PHP_INT_MAX]];
        yield 'candidate empty' => [NzbCreationCandidateQuery::class, [[], [], [], [], 100, 0]];
        yield 'candidate bounds' => [NzbCreationCandidateQuery::class, [
            [1, 2, 3, 4, 5, 6, 7, 8, PHP_INT_MAX, PHP_INT_MIN, 0, -1, 1, 9, 10],
            [0, 1, PHP_INT_MAX, PHP_INT_MIN, 92233720368547758, 92233720368547759, 99, 100, 1, 1, 1, 1, 1, -5, 3],
            [1, 1, PHP_INT_MAX, PHP_INT_MAX, 92233720368547758, 92233720368547759, 100, 100, 1, 1, 1, 1, 1, 0, 3],
            [PHP_INT_MAX, PHP_INT_MIN, 0, 0, 0, 0, 5, 5, 5, 5, 5, 5, 5, 5, PHP_INT_MAX],
            99, 3,
        ]];
        yield 'candidate ties' => [NzbCreationCandidateQuery::class, [[9, 8, 7, 6, 5, 4, 3, 2, 1], array_fill(0, 9, 4), array_fill(0, 9, 4), array_fill(0, 9, 100), -20, -1]];
        yield 'candidate limit overflow' => [NzbCreationCandidateQuery::class, [[1, 2, 3], [1, 1, 1], [1, 1, 1], [1, 2, 3], PHP_INT_MAX, PHP_INT_MAX]];
        yield 'import empty' => [NzbImportService::class, [[], [], 1]];
        yield 'import bounds' => [NzbImportService::class, [
            ['', '/', 'a/', '.nzb', '.NZB.GZ', 'x.nzb.gz.', 'x.nzb ', "x.nzb\n", "x.nzb\n\n", "x\n.nzb", "x.mkv\n.nzb", "x.mkv.nzb\n", 'x.nzb.gz.nzb', 'x.gz',
                'x.ts.nzb', 'x.TS.NZB', 'x.m2ts.nzb', 'x.mpeg.nzb', 'x.mpgg.nzb', 'x..mkv.nzb', 'x. .nzb', "x\x0B.nzb", "x\r.nzb", 'dir/sub/x.nzb', 'dir\\x.nzb',
                $long.'.nzb', $binary.'.nzb.gz', "\xff.nzb", 'ünï.mkv.nzb', 'a/b/c//', 'x.mkv', '.mkv.nzb', 'a.nzb', 'A.nzb'],
            ['', '<', "\x1f\x8b", "\x1f", "\xEF\xBB\xBF<", "\xEF\xBB<", "\x0B<", "\n\r\t <", "\0<", "\x1f\x8b\0\0", '<', '<', '<', "\x1f\x8b",
                '<', '<', '<', '<', '<', '<', '<', '<', '<', '<', '<', '<', "\x1f\x8b", '<', '<', '<', '<', '<', '<', '<'],
            3,
        ]];
        yield 'import zero batch' => [NzbImportService::class, [['a.nzb', 'b.nzb', 'c.nzb'], ['<', '<', '<'], PHP_INT_MIN]];
        yield 'import huge batch' => [NzbImportService::class, [['a.nzb', 'b.nzb', 'c.nzb'], ['<', '<', '<'], PHP_INT_MAX]];
        yield 'storage empty' => [NzbService::class, [[], [], 1]];
        yield 'storage bounds' => [NzbService::class, [
            ['', 'a', str_repeat('f', 40), str_repeat('F', 40), str_repeat('g', 40), str_repeat('a', 39), str_repeat('a', 41), $long, $binary, "\xff\xfe", 'a', ''],
            ['', '&', '&&&&', "'", '"', '<>', 'plain', $long, $binary, str_repeat('&<', 5000), "a\nb", ''],
            33,
        ]];
        foreach ([PHP_INT_MIN, -1, 0, 1, 31, 32, PHP_INT_MAX] as $levels) {
            yield 'storage levels '.$levels => [NzbService::class, [[str_repeat('ab', 20), 'xy', 'xy', str_repeat('9', 50)], ['a', 'b', 'c', 'd'], $levels]];
        }
    }

    /**
     * Paired randomized benchmark: ROUNDS interleaved rounds visit every class and workload in
     * turn; each round times PAIRS pairs (after WARMUPS) of the PHP and Rust batch entry point on
     * one randomly chosen fixture, alternating which runs first, each side keeping the fastest of
     * REPEATS back-to-back calls. Common batches hold 320 typical records, Uncommon batches
     * 320-640 malformed or adversarial ones. Mean and nearest-rank p99 speedups pool every
     * round's samples; per-round medians are reported too. Every pair must agree; raw pairs are
     * written outside the checkout. A class must default to Rust exactly when all four metrics
     * reach 1.5x. Gated behind NNTMUX_W562_BENCH=1.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        if (getenv('NNTMUX_W562_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W562_BENCH=1 to run the Wave 56.2 paired benchmark.');
        }
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(56));
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
                            [$results[(int) $native], $elapsed[(int) $native]] = $class::withW56RustFfi($native, fn (): array => $this->timed($class, $args));
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
        $artifact = sys_get_temp_dir().'/w562-benchmark-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($artifact, json_encode(['php' => PHP_VERSION, 'os' => php_uname(), 'rounds' => self::ROUNDS, 'pairs' => self::PAIRS, 'warmups' => self::WARMUPS,
            'repeats' => self::REPEATS, 'seed' => 56, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW56.2 paired benchmark artifact: {$artifact}\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach (array_keys(self::ENTRIES) as $class) {
            self::assertSame($summary[$class]['qualifies'], $class::usesW56RustFfi(), $class.' default must follow the measured 1.5x gate');
        }
    }

    /**
     * An explicit setlocale() makes PHP build PCRE tables from LC_CTYPE, so the regex-backed
     * import plan hands those batches to PHP. Declared last: once a script sets a locale, later
     * regexes keep the rebuilt tables.
     */
    public function test_native_helpers_fall_back_to_php_under_locale_tables(): void
    {
        $this->requireBindings();
        $locale = setlocale(LC_CTYPE, '0');
        try {
            foreach (['C.UTF-8', 'en_US.ISO8859-1', 'tr_TR.ISO8859-9'] as $candidate) {
                if (setlocale(LC_CTYPE, $candidate) === false) {
                    continue;
                }
                $this->assertParity(NzbImportService::class, [["caf\xE9.MKV.NZB", "x.\xC9.nzb", 'MOVIE.AVI.NZB', 'Show.DIVX.nzb'], ['<', '<', '<', '<'], 2]);
                $this->assertParity(DirectorySyncBridge::class, [["/caf\xE9//x/", '\\a\\b']]);
                $this->assertParity(NzbService::class, [["\xC9t\xE9", 'abc'], ["\xE9&", 'x'], 2]);
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
        $value = (new ReflectionClass($class))->getConstant('W56_RUST_FFI_DEFAULT');
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
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<mixed>  $args
     */
    private function assertParity(string $class, array $args): void
    {
        $php = $class::withW56RustFfi(false, fn (): array => $this->call($class, '', $args));
        $rust = $class::withW56RustFfi(true, fn (): array => $this->call($class, '', $args));
        self::assertSame($php, $rust, $class);
        self::assertSame($php, $this->call($class, 'Php', $args), $class);
        self::assertSame($php, $this->call($class, 'Native', $args), $class);
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
                $items += \count($args[0]);
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
        $count = \count($args[0]);
        $out = $ffi->new('int64_t['.(2 * $count + 16).']');
        $cap = 2 * $count + 16;
        $call = match ($class) {
            DirectorySyncBridge::class => [implode("\0", $args[0]), \strlen(implode("\0", $args[0])), $count],
            NzbArticleCoverage::class => [$t = pack('P*', ...$args[0], ...$args[1]), \strlen($t), $count, $args[2], $args[3]],
            NzbCreationCandidateQuery::class => [$t = pack('P*', ...$args[0], ...$args[1], ...$args[2], ...$args[3]), \strlen($t), $count, $args[4], $args[5]],
            NzbImportService::class => [$p = implode("\0", $args[0]), \strlen($p), $h = implode('', $args[1]), \strlen($h),
                $l = pack('P*', ...array_map(strlen(...), $args[1])), \strlen($l), $count, $args[2]],
            NzbService::class => [$g = implode("\0", $args[0]), \strlen($g), $s = implode("\0", $args[1]), \strlen($s), $count, $args[2]],
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
        $ffi = (new ReflectionMethod($class, 'w56RustFfiState'))->invoke(null, 'ffi');
        self::assertInstanceOf(FFI::class, $ffi, $class.' must load the nfo_w56 binding');

        return $ffi;
    }

    /** Each Wave 56.2 export must resolve and accept a one-item batch, so parity runs Rust. */
    private function requireBindings(): void
    {
        self::assertTrue(\extension_loaded('FFI'), 'FFI extension required');
        self::assertTrue(NzbParserService::pcreUsesDefaultTables(), 'PCRE must use its default tables so parity exercises Rust');
        $calls = [
            DirectorySyncBridge::class => [['/a', 2, 1], 8],
            NzbArticleCoverage::class => [[pack('P*', 3, 1), 16, 1, 0, 0], 12],
            NzbCreationCandidateQuery::class => [[pack('P*', 1, 1, 1, 5), 32, 1, 100, 0], 8],
            NzbImportService::class => [['a.nzb', 5, '<', 1, pack('P', 1), 8, 1, 1], 8],
            NzbService::class => [['g', 1, 's', 1, 1, 1], 8],
        ];
        foreach ($calls as $class => [$args, $words]) {
            $ffi = $this->ffi($class);
            $out = $ffi->new('int64_t[32]');
            self::assertSame($words, $this->invoke($ffi, self::SYMBOLS[$class], [...$args, $out, 32]), $class);
        }
    }

    /**
     * Calls an export declared by a class's W56_FFI_CDEF.
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
            DirectorySyncBridge::class => [$this->syncBatch($random, $uncommon)],
            NzbArticleCoverage::class => $this->coverageBatch($random, $uncommon),
            NzbCreationCandidateQuery::class => $this->candidateBatch($random, $uncommon),
            NzbImportService::class => $this->importBatch($random, $uncommon),
            NzbService::class => $this->storageBatch($random, $uncommon),
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
            : $random->getBytesFromString('abcdefghijklmnopqrstuvwxyz0123456789._-/', $length);
    }

    private function batchSize(Randomizer $random, bool $uncommon): int
    {
        return $uncommon ? $random->getInt(320, 640) : 320;
    }

    private function guid(Randomizer $random): string
    {
        return bin2hex($random->getBytes(20));
    }

    /** @return list<string> */
    private function syncBatch(Randomizer $random, bool $uncommon): array
    {
        $paths = [];
        $bases = ['/var/www/nntmux/storage/nzb/', '/data/nzb/', '/srv/nntmux/resources/nzb/', 'storage/app/nzb/'];
        for ($i = $this->batchSize($random, $uncommon); $i > 0; $i--) {
            $guid = $this->guid($random);
            $path = $this->pick($random, $bases).implode('/', str_split(substr($guid, 0, $random->getInt(0, 3)))).($random->getInt(0, 3) === 0 ? '/' : '');
            if ($paths !== [] && $random->getInt(0, 9) === 0) {
                $path = $this->pick($random, $paths);
            }
            if ($uncommon) {
                $path = match ($random->getInt(0, 11)) {
                    0 => '',
                    1 => str_replace('/', '\\', $path),
                    2 => str_replace('/', '//', $path),
                    3 => $this->pick($random, ['s3://bucket/', 'phar://x.phar/', 'file:///tmp/', 'php://memory/']).$this->noise($random, 40),
                    4 => $this->noise($random, 120, true),
                    5 => str_repeat('/', $random->getInt(1, 6)),
                    6 => $path.str_repeat('/', $random->getInt(1, 4)),
                    7 => $this->noise($random, 80),
                    default => $path,
                };
            }
            $paths[] = $path;
        }

        return $paths;
    }

    /** @return array{list<int>, list<int>, int, int} */
    private function coverageBatch(Randomizer $random, bool $uncommon): array
    {
        $required = $covered = [];
        for ($i = $this->batchSize($random, $uncommon); $i > 0; $i--) {
            $need = $random->getInt(1, 5000);
            $cov = $random->getInt(0, 4) === 0 ? $random->getInt(0, $need) : $need - $random->getInt(0, 3);
            if ($uncommon && $random->getInt(0, 2) === 0) {
                $need = $this->pick($random, [PHP_INT_MIN, -1, 0, 1, 2, PHP_INT_MAX, 922337203685477, 922337203685478, $random->getInt(0, PHP_INT_MAX), $need]);
                $cov = $this->pick($random, [PHP_INT_MIN, -1, 0, 1, PHP_INT_MAX, $need, max(PHP_INT_MIN + 1, $need) - 1, $random->getInt(PHP_INT_MIN, PHP_INT_MAX), $cov]);
            }
            $required[] = $need;
            $covered[] = $cov;
        }

        return [$required, $covered, $uncommon ? $this->pick($random, [PHP_INT_MIN, -1, 0, 3, 100, PHP_INT_MAX]) : $random->getInt(0, 5),
            $uncommon ? $this->pick($random, [PHP_INT_MIN, -5, 0, 5000, 9999, 10000, 10001, PHP_INT_MAX]) : $this->pick($random, [10000, 9900, 9500])];
    }

    /** @return array{list<int>, list<int>, list<int>, list<int>, int, int} */
    private function candidateBatch(Randomizer $random, bool $uncommon): array
    {
        $ids = $received = $totals = $postdates = [];
        $now = 1_790_000_000;
        for ($i = $this->batchSize($random, $uncommon); $i > 0; $i--) {
            $id = $ids !== [] && $random->getInt(0, 30) === 0 ? $this->pick($random, $ids) : $random->getInt(1, 10_000_000);
            $total = $random->getInt(1, 500);
            $got = $random->getInt(0, 5) === 0 ? $random->getInt(0, $total) : $total;
            $date = $now - $random->getInt(0, 86400 * 30) - ($random->getInt(0, 3) === 0 ? 0 : $random->getInt(0, 3));
            if ($uncommon && $random->getInt(0, 2) === 0) {
                $id = $this->pick($random, [PHP_INT_MIN, -1, 0, 1, PHP_INT_MAX, $id]);
                $total = $this->pick($random, [PHP_INT_MIN, -1, 0, 1, PHP_INT_MAX, 92233720368547758, 92233720368547759, $total]);
                $got = $this->pick($random, [PHP_INT_MIN, -1, 0, PHP_INT_MAX, $total, max(PHP_INT_MIN + 1, $total) - 1, $got]);
                $date = $this->pick($random, [PHP_INT_MIN, 0, PHP_INT_MAX, $now, $date]);
            }
            $ids[] = $id;
            $received[] = $got;
            $totals[] = $total;
            $postdates[] = $date;
        }

        return [$ids, $received, $totals, $postdates, $uncommon ? $this->pick($random, [PHP_INT_MIN, -5, 0, 50, 99, 100, 101, PHP_INT_MAX]) : $this->pick($random, [100, 95, 90]),
            $uncommon ? $this->pick($random, [PHP_INT_MIN, -1, 0, 1, 7, PHP_INT_MAX]) : $this->pick($random, [0, 100, 250])];
    }

    /** @return array{list<string>, list<string>, int} */
    private function importBatch(Randomizer $random, bool $uncommon): array
    {
        $paths = $heads = [];
        for ($i = $this->batchSize($random, $uncommon); $i > 0; $i--) {
            $name = $this->pick($random, self::RELEASES).($random->getInt(0, 2) === 0 ? '.'.$this->pick($random, self::MEDIA) : '');
            $gz = $random->getInt(0, 1) === 0;
            $path = $this->pick($random, ['/imports/', '/var/spool/nzb/incoming/', 'nzbs/', '']).$name.'-'.$random->getInt(1, 400).($gz ? '.nzb.gz' : '.nzb');
            $head = $gz ? "\x1f\x8b\x08\x00".$random->getBytes(12) : '<?xml version="1.0" encoding="UTF-8"?>';
            if ($uncommon) {
                $path = match ($random->getInt(0, 13)) {
                    0 => '',
                    1 => strtoupper($path),
                    2 => $path."\n",
                    3 => $path.$this->pick($random, ['.', ' ', "\t", '..', '/', '.bak']),
                    4 => $this->noise($random, 200, true).'.nzb',
                    5 => str_replace('.nzb', "\n.nzb", $path),
                    6 => $this->pick($random, ['.nzb', '...nzb.gz', 'x.mkv', 'a/b/', '.mkv.nzb']),
                    7 => $this->pick($random, ['/imports/a.nzb', '/imports/b.nzb.gz']),
                    default => $path,
                };
                $head = match ($random->getInt(0, 9)) {
                    0 => '',
                    1 => "\xEF\xBB\xBF".$this->pick($random, [' ', "\r\n", "\t\t", '']).'<nzb>',
                    2 => "\x1f\x8b",
                    3 => "\x1f",
                    4 => $random->getBytes($random->getInt(1, 40)),
                    5 => "\x0B<nzb>",
                    default => $head,
                };
            }
            $paths[] = $path;
            $heads[] = $head;
        }

        return [$paths, $heads, $uncommon ? $this->pick($random, [PHP_INT_MIN, -3, 0, 1, 7, 100, PHP_INT_MAX]) : 100];
    }

    /** @return array{list<string>, list<string>, int} */
    private function storageBatch(Randomizer $random, bool $uncommon): array
    {
        $guids = $subjects = [];
        for ($i = $this->batchSize($random, $uncommon); $i > 0; $i--) {
            $guid = $guids !== [] && $random->getInt(0, 40) === 0 ? $this->pick($random, $guids) : $this->guid($random);
            $base = $this->pick($random, self::RELEASES);
            $subject = match ($random->getInt(0, 4)) {
                0 => sprintf('[%02d/%02d] - "%s.part%02d.rar" yEnc (1/%d)', $random->getInt(1, 40), 40, $base, $random->getInt(1, 40), $random->getInt(1, 400)),
                1 => sprintf('%s - "%s.nfo" yEnc (1/1)', $this->pick($random, ['[NZBgeek]', 'Tom & Jerry', "<Group's>"]), $base),
                2 => 'Re: '.$base.' & more <'.$random->getInt(1, 99).'>',
                default => $base.' yEnc ('.$random->getInt(1, 9).'/'.$random->getInt(10, 99).')',
            };
            if ($uncommon) {
                $guid = match ($random->getInt(0, 8)) {
                    0 => '',
                    1 => strtoupper($guid),
                    2 => substr($guid, 0, $random->getInt(1, 39)),
                    3 => $this->noise($random, 60, true),
                    4 => $guid.$this->noise($random, 4),
                    default => $guid,
                };
                $subject = match ($random->getInt(0, 6)) {
                    0 => '',
                    1 => str_repeat('&<>"\'', $random->getInt(1, 200)),
                    2 => $this->noise($random, 400, true),
                    3 => $subject.$this->noise($random, 300),
                    default => $subject,
                };
            }
            $guids[] = $guid;
            $subjects[] = $subject;
        }

        return [$guids, $subjects, $uncommon ? $this->pick($random, [PHP_INT_MIN, -1, 0, 1, 2, 5, 32, 40, PHP_INT_MAX]) : $this->pick($random, [1, 2, 3])];
    }
}
