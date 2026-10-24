<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Category;
use App\Models\UsenetGroup;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\CategorizationService;
use App\Services\Categorization\Pipes\CategorizationPassable;
use App\Services\Categorization\Pipes\MiscPipe;
use App\Services\Categorization\Pipes\MiscSafetyNetPipe;
use App\Services\Categorization\ReleaseContext;
use Error;
use FFI;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;

/**
 * Wave 15 Slice 5 parity and paired benchmark suite for `CategorizationService`,
 * `CategorizationPassable`, `MiscPipe`, `MiscSafetyNetPipe`, `Category`, and
 * `UsenetGroup` (`rust/pipeline-ffi/src/categorize.rs`).
 *
 * Reproduce paired randomized benchmarks:
 *   NNTMUX_CATEGORIZATION_PIPES_BENCH=1 php artisan test --compact tests/Unit/RustCategorizationPipesAndGroupParityTest.php --filter=test_benchmark_paired_workloads
 *
 * Protocol: seed 20261024; 512 warmups; 3 repetitions of 4,096 randomized paired
 * single calls per workload (common and uncommon); nearest-rank p99; output equality
 * verified before timing. Adoption requires >= 1.5x speedup on all 4 metrics
 * (common mean, common p99, uncommon mean, uncommon p99) per `.ai/rules/rust-performance.md`.
 *
 * Paired speedup results (`PHP latency / Rust FFI latency`, mean/p99 across 3 reps):
 * - `shouldStopProcessing`:     common 0.32x/0.50x, 0.29x/0.25x, 0.29x/0.25x | uncommon 0.31x/0.50x, 0.30x/0.50x, 0.29x/0.50x
 * - `updateBestResult`:         common 0.88x/0.91x, 0.90x/0.89x, 0.94x/0.91x | uncommon 0.90x/0.92x, 0.92x/0.92x, 0.91x/0.86x
 * - `toArray`:                  common 0.18x/0.22x, 0.18x/0.25x, 0.18x/0.25x | uncommon 0.26x/0.29x, 0.27x/0.29x, 0.26x/0.27x
 * - `miscPipeShouldLock`:       common 0.53x/0.60x, 0.54x/0.60x, 0.54x/0.60x | uncommon 0.46x/0.40x, 0.46x/0.40x, 0.46x/0.40x
 * - `safetyNetShouldDowngrade`: common 0.29x/0.40x, 0.29x/0.40x, 0.28x/0.40x | uncommon 0.36x/0.40x, 0.35x/0.40x, 0.36x/0.40x
 * - `buildComparisonResult`:    common 0.26x/0.33x, 0.26x/0.31x, 0.26x/0.31x | uncommon 0.30x/0.30x, 0.29x/0.29x, 0.30x/0.29x
 * - `isParent`:                 common 0.56x/0.50x, 0.56x/0.60x, 0.57x/0.60x | uncommon 0.75x/0.73x, 0.74x/0.83x, 0.77x/0.83x
 * - `getCategoryOthersGroup`:   common 0.37x/0.43x, 0.38x/0.29x, 0.38x/0.34x | uncommon 0.39x/0.34x, 0.39x/0.43x, 0.39x/0.34x
 * - `getCategoryValue`:         common 0.51x/0.60x, 0.52x/0.50x, 0.60x/0.50x | uncommon 0.57x/0.50x, 0.58x/0.50x, 0.58x/0.50x
 * - `isValidGroup`:             common 0.37x/0.37x, 0.36x/0.38x, 0.36x/0.38x | uncommon 0.48x/0.62x, 0.49x/0.62x, 0.51x/0.61x
 * - `formatGroupStatusMessage`: common 0.29x/0.29x, 0.28x/0.29x, 0.29x/0.29x | uncommon 0.29x/0.33x, 0.31x/0.38x, 0.31x/0.38x
 *
 * All 11 target operations are sub-microsecond scalar/short-string predicates or array
 * builders where single-call PHP FFI boundary overhead exceeds the native computation
 * cost. Per `.ai/rules/rust-performance.md`, `$useRustFfi = false` is retained as the
 * production default across all six classes while keeping `*Native` and `*Php` methods
 * tested for 100% parity.
 */
final class RustCategorizationPipesAndGroupParityTest extends TestCase
{
    protected function tearDown(): void
    {
        CategorizationService::setUseRustFfi(false);
        CategorizationPassable::setUseRustFfi(false);
        MiscPipe::setUseRustFfi(false);
        MiscSafetyNetPipe::setUseRustFfi(false);
        Category::setUseRustFfi(false);
        UsenetGroup::setUseRustFfi(false);
        parent::tearDown();
    }

    private static function ffi(): FFI
    {
        $ffi = CategorizationService::categorizeFfi();
        self::assertNotNull($ffi, 'Expected libnntmux_pipeline categorize domain FFI to be available.');

        return $ffi;
    }

    public function test_categorization_passable_should_stop_processing_matches_php(): void
    {
        self::ffi();
        $ctx = new ReleaseContext('Show.Name.S01E01.1080p.WEB-DL-GRP', 1, 'alt.binaries.teevee');

        $confidences = [-0.1, 0.0, 0.5, 0.94, 0.9499999, 0.95, 0.9500001, 0.99, 1.0];
        foreach ([false, true] as $locked) {
            foreach ($confidences as $conf) {
                $passable = new CategorizationPassable($ctx);
                $passable->lockedToMisc = $locked;
                $passable->bestResult = new CategorizationResult(Category::TV_HD, $conf, 'tv_hd');

                $expected = $passable->shouldStopProcessingPhp();
                self::assertSame($expected, $passable->shouldStopProcessingNative(), 'locked='.(int) $locked." conf={$conf}");
                foreach ([false, true] as $toggle) {
                    CategorizationPassable::setUseRustFfi($toggle);
                    self::assertSame($expected, $passable->shouldStopProcessing());
                }
            }
        }
    }

    public function test_categorization_passable_update_best_result_matches_php(): void
    {
        self::ffi();
        $ctx = new ReleaseContext('Movie.Title.2025.1080p.BluRay.x264-GRP', 1, 'alt.binaries.moovee');

        $candidates = [
            CategorizationResult::noMatch(),
            new CategorizationResult(Category::OTHER_MISC, 0.0, 'none'),
            new CategorizationResult(Category::OTHER_MISC, 0.45, 'archive_extension'),
            new CategorizationResult(Category::OTHER_MISC, 0.50, 'hash_md5'),
            new CategorizationResult(Category::OTHER_MISC, 0.50, 'obfuscated_random'),
            new CategorizationResult(Category::OTHER_MISC, 0.50, 'gibberish_consonants'),
            new CategorizationResult(Category::OTHER_MISC, 0.65, 'group_only_low_signal'),
            new CategorizationResult(Category::OTHER_HASHED, 0.95, 'hash_sha256'),
            new CategorizationResult(Category::MOVIE_HD, 0.50, 'group_name_movie'),
            new CategorizationResult(Category::MOVIE_HD, 0.59, 'movie_weak'),
            new CategorizationResult(Category::MOVIE_HD, 0.60, 'movie_threshold'),
            new CategorizationResult(Category::MOVIE_HD, 0.85, 'movie_hd_bluray'),
            new CategorizationResult(Category::TV_HD, 0.85, 'tv_hd_pattern'),
            new CategorizationResult(Category::MOVIE_UHD, 0.95, 'movie_uhd_2160p'),
        ];

        foreach ([false, true] as $debug) {
            foreach ($candidates as $i => $initial) {
                foreach ($candidates as $j => $incoming) {
                    $phpPassable = new CategorizationPassable($ctx, $debug);
                    $phpPassable->bestResult = $initial;
                    $phpPassable->updateBestResultPhp($incoming, "Pipe_{$j}");

                    $rustPassable = new CategorizationPassable($ctx, $debug);
                    $rustPassable->bestResult = $initial;
                    $rustPassable->updateBestResultNative($incoming, "Pipe_{$j}");

                    self::assertSame($phpPassable->bestResult, $rustPassable->bestResult, 'bestResult debug='.(int) $debug." {$i}->{$j}");
                    self::assertSame($phpPassable->allResults, $rustPassable->allResults, 'allResults debug='.(int) $debug." {$i}->{$j}");

                    foreach ([false, true] as $toggle) {
                        CategorizationPassable::setUseRustFfi($toggle);
                        $dispPassable = new CategorizationPassable($ctx, $debug);
                        $dispPassable->bestResult = $initial;
                        $dispPassable->updateBestResult($incoming, "Pipe_{$j}");
                        self::assertSame($phpPassable->bestResult, $dispPassable->bestResult);
                        self::assertSame($phpPassable->allResults, $dispPassable->allResults);
                    }
                }
            }
        }
    }

    public function test_categorization_passable_to_array_matches_php(): void
    {
        self::ffi();

        $contexts = [
            new ReleaseContext('Ubuntu.24.04.LTS.Desktop.amd64.ISO-GRP', 1, 'alt.binaries.linux'),
            new ReleaseContext('', 0, ''),
            new ReleaseContext("Release\0With\0Nul", 2, 'alt.binaries.misc'),
            new ReleaseContext(str_repeat('Long.Release.Segment.', 24).'2160p.WEB-DL', 3, 'alt.binaries.hdtv'),
        ];

        foreach ($contexts as $ctx) {
            foreach ([false, true] as $debug) {
                foreach ([false, true] as $locked) {
                    $passable = new CategorizationPassable($ctx, $debug);
                    $passable->lockedToMisc = $locked;
                    $passable->miscAnalysis = ['lowSignal' => $locked, 'tokens' => 4];
                    $passable->updateBestResultPhp(
                        new CategorizationResult(Category::PC_ISO, 0.9, 'iso_extension', ['rule' => 'iso']),
                        'Pc'
                    );

                    $expected = $passable->toArrayPhp();
                    self::assertSame($expected, $passable->toArrayNative());
                    foreach ([false, true] as $toggle) {
                        CategorizationPassable::setUseRustFfi($toggle);
                        self::assertSame($expected, $passable->toArray());
                    }
                }
            }
        }
    }

    public function test_misc_pipe_should_lock_and_handle_match_php(): void
    {
        self::ffi();
        $pipe = new MiscPipe;

        $categories = [
            Category::OTHER_MISC,
            Category::OTHER_HASHED,
            Category::MOVIE_HD,
            Category::TV_HD,
        ];
        $matchedBys = [
            'no_match',
            'hash_md5',
            'hash_sha1',
            'obfuscated_uppercase',
            'gibberish_transitions',
            'archive_rar',
            'group_only_low_signal',
            'hash',
            'obfuscated',
            'gibberish',
            '',
        ];

        foreach ($categories as $cat) {
            foreach ($matchedBys as $mb) {
                $res = new CategorizationResult($cat, 0.8, $mb);
                $expected = $pipe->shouldLockPhp($res);
                self::assertSame($expected, $pipe->shouldLockNative($res), "cat={$cat} mb={$mb}");
                foreach ([false, true] as $toggle) {
                    MiscPipe::setUseRustFfi($toggle);
                    self::assertSame($expected, $pipe->shouldLock($res));
                }
            }
        }

        // Verify MiscPipe::handle preserves existing MiscCategorizer Rust calls and matches across toggle states
        $releaseNames = [
            'a1b2c3d4e5f60718293a4b5c6d7e8f90',
            'Show.Name.S02E05.1080p.WEB-DL.DDP5.1.H.264-GRP',
            'X9K2M7P4Q8R1Z5W3Y6V0B2N4',
        ];
        foreach ($releaseNames as $name) {
            $ctx = new ReleaseContext($name, 1, 'alt.binaries.teevee');
            MiscPipe::setUseRustFfi(false);
            $pPhp = $pipe->handle(new CategorizationPassable($ctx, true), static fn (CategorizationPassable $p) => $p);
            MiscPipe::setUseRustFfi(true);
            $pRust = $pipe->handle(new CategorizationPassable($ctx, true), static fn (CategorizationPassable $p) => $p);
            self::assertSame($pPhp->toArrayPhp(), $pRust->toArrayPhp());
        }
    }

    public function test_misc_safety_net_pipe_should_downgrade_and_handle_match_php(): void
    {
        self::ffi();
        $pipe = new MiscSafetyNetPipe;
        $ctx = new ReleaseContext(' mysterypost ', 1, 'alt.binaries.teevee');

        $lowSignalValues = [true, false, null, 1, 0, 'true'];
        $matchedBys = [
            'group_name_tv',
            'group_name_movie_hd',
            'group_name_',
            'group_name',
            'tv_hd_regex',
            'hash_md5',
            'no_match',
            '',
        ];

        foreach ([false, true] as $locked) {
            foreach ($lowSignalValues as $lowSig) {
                foreach ($matchedBys as $mb) {
                    $passable = new CategorizationPassable($ctx, true);
                    $passable->lockedToMisc = $locked;
                    $passable->miscAnalysis = $lowSig === null ? [] : ['lowSignal' => $lowSig];
                    $passable->bestResult = new CategorizationResult(Category::TV_OTHER, 0.5, $mb);

                    $expected = $pipe->shouldDowngradeToMiscPhp($passable);
                    self::assertSame($expected, $pipe->shouldDowngradeToMiscNative($passable));
                    foreach ([false, true] as $toggle) {
                        MiscSafetyNetPipe::setUseRustFfi($toggle);
                        self::assertSame($expected, $pipe->shouldDowngradeToMisc($passable));
                    }

                    MiscSafetyNetPipe::setUseRustFfi(false);
                    $p0 = clone $passable;
                    $outPhp = $pipe->handle($p0, static fn (CategorizationPassable $p) => $p);

                    MiscSafetyNetPipe::setUseRustFfi(true);
                    $p1 = clone $passable;
                    $outRust = $pipe->handle($p1, static fn (CategorizationPassable $p) => $p);

                    self::assertSame($outPhp->toArrayPhp(), $outRust->toArrayPhp());
                }
            }
        }
    }

    public function test_categorization_service_comparison_helpers_match_php(): void
    {
        self::ffi();
        $service = (new ReflectionClass(CategorizationService::class))->newInstanceWithoutConstructor();

        $cases = [
            [Category::TV_HD, 'TV > HD', Category::TV_HD, 'TV > HD', 0],
            [Category::MOVIE_UHD, 'Movies > UHD', Category::MOVIE_HD, 'Movies > HD', 0],
            [Category::OTHER_MISC, null, Category::OTHER_MISC, null, 0],
            [Category::PC_ISO, 'PC > ISO', Category::OTHER_MISC, null, 1],
            [Category::BOOKS_EBOOK, 'Books > Ebook', Category::OTHER_MISC, null, 2],
            [Category::MUSIC_MP3, '', Category::MUSIC_MP3, '', 0],
            [Category::GAME_PS4, "Game\0Title", Category::GAME_PS4, 'PS4', 0],
            [Category::TV_WEBDL, str_repeat('Category > ', 30).'WEB-DL', Category::TV_HD, 'TV > HD', 0],
        ];

        foreach ($cases as [$pId, $pTitle, $lId, $lTitle, $state]) {
            $expected = $service->buildComparisonResultPhp($pId, $pTitle, $lId, $lTitle, $state);
            self::assertSame($expected, $service->buildComparisonResultNative($pId, $pTitle, $lId, $lTitle, $state));
            foreach ([false, true] as $toggle) {
                CategorizationService::setUseRustFfi($toggle);
                self::assertSame($expected, $service->buildComparisonResult($pId, $pTitle, $lId, $lTitle, $state));
            }
        }

        // Also exercise compare / comparePhp / compareNative via an in-memory stubbed subclass
        $stubService = new class extends CategorizationService
        {
            public function __construct() {}

            protected function resolveComparisonInputs(int|string $groupId, string $releaseName): array
            {
                return [Category::TV_HD, 'TV > HD', Category::TV_HD, 'TV > HD', 0];
            }
        };

        $expectedCompare = $stubService->comparePhp(1, 'Show.S01E01.1080p.WEB-DL-GRP');
        self::assertSame($expectedCompare, $stubService->compareNative(1, 'Show.S01E01.1080p.WEB-DL-GRP'));
        foreach ([false, true] as $toggle) {
            CategorizationService::setUseRustFfi($toggle);
            self::assertSame($expectedCompare, $stubService->compare(1, 'Show.S01E01.1080p.WEB-DL-GRP'));
        }
    }

    public function test_category_helpers_match_php(): void
    {
        self::ffi();

        // 1. getCategoryOthersGroup
        $expectedOthers = Category::getCategoryOthersGroupPhp();
        self::assertSame('7999,1999,2999,3999,4040,5999,20,6999,10', $expectedOthers);
        self::assertSame($expectedOthers, Category::getCategoryOthersGroupNative());
        foreach ([false, true] as $toggle) {
            Category::setUseRustFfi($toggle);
            self::assertSame($expectedOthers, Category::getCategoryOthersGroup());
        }

        // 2. getCategoryValue across all Category constants
        $constants = (new ReflectionClass(Category::class))->getConstants();
        foreach ($constants as $name => $val) {
            $expectedVal = Category::getCategoryValuePhp($name);
            self::assertSame($val, $expectedVal);
            self::assertSame($expectedVal, Category::getCategoryValueNative($name), "const {$name}");
            foreach ([false, true] as $toggle) {
                Category::setUseRustFfi($toggle);
                self::assertSame($expectedVal, Category::getCategoryValue($name), "dispatch const {$name}");
            }
        }

        // Undefined constant should throw Error in both Php and Native
        $threwPhp = false;
        try {
            Category::getCategoryValuePhp('DOES_NOT_EXIST_123');
        } catch (Error) {
            $threwPhp = true;
        }
        $threwNative = false;
        try {
            Category::getCategoryValueNative('DOES_NOT_EXIST_123');
        } catch (Error) {
            $threwNative = true;
        }
        self::assertTrue($threwPhp);
        self::assertTrue($threwNative);

        // 3. isParent / isParentCategoryId
        $parentInputs = [
            1,
            1000,
            2000,
            3000,
            4000,
            5000,
            6000,
            7000,
            0,
            -1,
            10,
            20,
            2040,
            5040,
            7999,
            8000,
            '1',
            '1000',
            ' 2000 ',
            '+3000',
            '004000',
            "5000\n",
            '6000',
            '7000',
            '2040',
            '-1000',
            '',
            '   ',
            'abc',
            '2000.0',
            null,
            false,
            true,
            2000.0,
        ];

        foreach ($parentInputs as $idx => $cid) {
            $expected = Category::isParentCategoryIdPhp($cid);
            self::assertSame($expected, Category::isParentCategoryIdNative($cid), "isParentCategoryId case {$idx}");
            self::assertSame($expected, Category::isParentPhp($cid), "isParentPhp case {$idx}");
            self::assertSame($expected, Category::isParentNative($cid), "isParentNative case {$idx}");
            foreach ([false, true] as $toggle) {
                Category::setUseRustFfi($toggle);
                self::assertSame($expected, Category::isParentCategoryId($cid));
                self::assertSame($expected, Category::isParent($cid));
            }
        }
    }

    public function test_usenet_group_validation_and_status_formatting_match_php(): void
    {
        self::ffi();

        $groups = [
            'alt.binaries.teevee',
            'alt.binaries.moovee',
            'alt.binaries.hdtv.x264',
            'a.b.teevee',
            'A.B.Moovee',
            'a.B.games_pc-iso',
            'a.b',
            'a.b.c',
            "alt.binaries.teevee\n",
            "a.b.teevee\n",
            "a.b.teevee\n\n",
            "a.b\n.teevee",
            'alt',
            '',
            '.alt.binaries',
            'alt.binaries.',
            'alt..binaries',
            'alt.binaries.c++',
            'alt.binaries.test group',
            "alt.binaries.test\0group",
            str_repeat('sub_group-1.', 30).'leaf',
            'a.b.'.str_repeat('segment-name_', 25).'end',
        ];

        $random = new Randomizer(new Mt19937(15005));
        $alphabet = "abABcd019._- \n\0+";
        for ($i = 0; $i < 300; $i++) {
            $groups[] = $random->getBytesFromString($alphabet, $random->getInt(1, 24));
        }

        foreach ($groups as $groupName) {
            $expected = UsenetGroup::isValidGroupPhp($groupName);
            self::assertSame($expected, UsenetGroup::isValidGroupNative($groupName), 'group '.bin2hex($groupName));
            foreach ([false, true] as $toggle) {
                UsenetGroup::setUseRustFfi($toggle);
                self::assertSame($expected, UsenetGroup::isValidGroup($groupName));
            }
        }

        $ids = [0, 1, 42, 999999, -1, -42, PHP_INT_MAX];
        $statuses = [0, 1, 2, -1];
        foreach ($ids as $id) {
            foreach ($statuses as $status) {
                $expectedMsg = UsenetGroup::formatGroupStatusMessagePhp($id, $status);
                self::assertSame($expectedMsg, UsenetGroup::formatGroupStatusMessageNative($id, $status));
                foreach ([false, true] as $toggle) {
                    UsenetGroup::setUseRustFfi($toggle);
                    self::assertSame($expectedMsg, UsenetGroup::formatGroupStatusMessage($id, $status));
                }
            }
        }
    }

    public function test_direct_c_abi_null_and_small_buffer_contracts(): void
    {
        $ffi = self::ffi();
        $small = $ffi->new('char[4]');

        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_categorize_passable_should_stop(2, 0.5));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_categorize_passable_should_update_best(10, 0.5, null, 3, 10, 0.0, 'none', 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_categorize_passable_pack_summary(1, null, 3, 'a', 1, 'b', 1, $small, 4));
        // @phpstan-ignore method.notFound
        self::assertLessThan(-4, $ffi->nntmux_categorize_passable_pack_summary(1, 'tv_hd', 5, 'Release.Name', 12, 'alt.binaries.tv', 15, $small, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_categorize_misc_pipe_should_lock(10, null, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_categorize_safety_net_should_downgrade(2, 1, 'group_name_tv', 13));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_categorize_service_compare_summary(10, 1, null, 3, 10, 0, '', 0, 0, $small, 4));
        // @phpstan-ignore method.notFound
        self::assertLessThan(-4, $ffi->nntmux_categorize_service_compare_summary(10, 1, 'TV > HD', 7, 10, 1, 'TV > HD', 7, 0, $small, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_category_is_parent_str(null, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-40, $ffi->nntmux_category_others_group($small, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_category_value(null, 5));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_usenet_group_normalize_valid(null, 5, $small, 4));
        // @phpstan-ignore method.notFound
        self::assertLessThan(-4, $ffi->nntmux_usenet_group_normalize_valid('a.b.teevee', 10, $small, 4));
        // @phpstan-ignore method.notFound
        self::assertLessThan(-4, $ffi->nntmux_usenet_group_format_status_message(42, 0, $small, 4));
    }

    /**
     * Run with:
     * NNTMUX_CATEGORIZATION_PIPES_BENCH=1 php artisan test --compact tests/Unit/RustCategorizationPipesAndGroupParityTest.php --filter=test_benchmark_paired_workloads
     */
    public function test_benchmark_paired_workloads(): void
    {
        if (getenv('NNTMUX_CATEGORIZATION_PIPES_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_CATEGORIZATION_PIPES_BENCH=1 to run paired benchmarks.');
        }

        self::ffi();
        $service = (new ReflectionClass(CategorizationService::class))->newInstanceWithoutConstructor();
        $miscPipe = new MiscPipe;
        $safetyPipe = new MiscSafetyNetPipe;

        $ctxCommon = new ReleaseContext('The.Bear.S03E01.1080p.WEB-DL.DDP5.1.H.264-FLUX', 1, 'alt.binaries.teevee');
        $ctxUncommon = new ReleaseContext(str_repeat('Long.Series.Title.Segment.', 10).'S05E12.2160p.WEB-DL.DV.HDR10.HEVC-GRP', 2, 'alt.binaries.hdtv.x265');

        $passableCommon = new CategorizationPassable($ctxCommon, false);
        $passableCommon->bestResult = new CategorizationResult(Category::TV_WEBDL, 0.85, 'tv_webdl');

        $passableUncommon = new CategorizationPassable($ctxUncommon, true);
        $passableUncommon->lockedToMisc = false;
        $passableUncommon->miscAnalysis = ['lowSignal' => true, 'length' => 180];
        $passableUncommon->bestResult = new CategorizationResult(Category::TV_OTHER, 0.50, 'group_name_tv');

        $candCommon = new CategorizationResult(Category::TV_HD, 0.90, 'tv_hd_regex');
        $candUncommon = new CategorizationResult(Category::MOVIE_HD, 0.55, 'movie_fallback_heuristic');
        $hashResult = new CategorizationResult(Category::OTHER_MISC, 0.95, 'obfuscated_random_alnum');

        $workloads = [
            'shouldStopProcessing/common' => [
                static fn () => $passableCommon->shouldStopProcessingPhp(),
                static fn () => $passableCommon->shouldStopProcessingNative(),
            ],
            'shouldStopProcessing/uncommon' => [
                static fn () => $passableUncommon->shouldStopProcessingPhp(),
                static fn () => $passableUncommon->shouldStopProcessingNative(),
            ],
            'updateBestResult/common' => [
                static function () use ($ctxCommon, $candCommon): int {
                    $p = new CategorizationPassable($ctxCommon, false);
                    $p->updateBestResultPhp($candCommon, 'Tv');

                    return $p->bestResult->categoryId;
                },
                static function () use ($ctxCommon, $candCommon): int {
                    $p = new CategorizationPassable($ctxCommon, false);
                    $p->updateBestResultNative($candCommon, 'Tv');

                    return $p->bestResult->categoryId;
                },
            ],
            'updateBestResult/uncommon' => [
                static function () use ($ctxUncommon, $hashResult, $candUncommon): int {
                    $p = new CategorizationPassable($ctxUncommon, true);
                    $p->bestResult = $hashResult;
                    $p->updateBestResultPhp($candUncommon, 'Movie');

                    return $p->bestResult->categoryId;
                },
                static function () use ($ctxUncommon, $hashResult, $candUncommon): int {
                    $p = new CategorizationPassable($ctxUncommon, true);
                    $p->bestResult = $hashResult;
                    $p->updateBestResultNative($candUncommon, 'Movie');

                    return $p->bestResult->categoryId;
                },
            ],
            'toArray/common' => [
                static fn () => $passableCommon->toArrayPhp(),
                static fn () => $passableCommon->toArrayNative(),
            ],
            'toArray/uncommon' => [
                static fn () => $passableUncommon->toArrayPhp(),
                static fn () => $passableUncommon->toArrayNative(),
            ],
            'miscPipeShouldLock/common' => [
                static fn () => $miscPipe->shouldLockPhp($candCommon),
                static fn () => $miscPipe->shouldLockNative($candCommon),
            ],
            'miscPipeShouldLock/uncommon' => [
                static fn () => $miscPipe->shouldLockPhp($hashResult),
                static fn () => $miscPipe->shouldLockNative($hashResult),
            ],
            'safetyNetShouldDowngrade/common' => [
                static fn () => $safetyPipe->shouldDowngradeToMiscPhp($passableCommon),
                static fn () => $safetyPipe->shouldDowngradeToMiscNative($passableCommon),
            ],
            'safetyNetShouldDowngrade/uncommon' => [
                static fn () => $safetyPipe->shouldDowngradeToMiscPhp($passableUncommon),
                static fn () => $safetyPipe->shouldDowngradeToMiscNative($passableUncommon),
            ],
            'buildComparisonResult/common' => [
                static fn () => $service->buildComparisonResultPhp(Category::TV_HD, 'TV > HD', Category::TV_HD, 'TV > HD', 0),
                static fn () => $service->buildComparisonResultNative(Category::TV_HD, 'TV > HD', Category::TV_HD, 'TV > HD', 0),
            ],
            'buildComparisonResult/uncommon' => [
                static fn () => $service->buildComparisonResultPhp(Category::MOVIE_UHD, str_repeat('Movies > ', 12).'UHD', Category::OTHER_MISC, null, 2),
                static fn () => $service->buildComparisonResultNative(Category::MOVIE_UHD, str_repeat('Movies > ', 12).'UHD', Category::OTHER_MISC, null, 2),
            ],
            'isParent/common' => [
                static fn () => Category::isParentPhp(2000),
                static fn () => Category::isParentNative(2000),
            ],
            'isParent/uncommon' => [
                static fn () => Category::isParentPhp(' 005000 '),
                static fn () => Category::isParentNative(' 005000 '),
            ],
            'getCategoryOthersGroup/common' => [
                static fn () => Category::getCategoryOthersGroupPhp(),
                static fn () => Category::getCategoryOthersGroupNative(),
            ],
            'getCategoryOthersGroup/uncommon' => [
                static fn () => Category::getCategoryOthersGroupPhp(),
                static fn () => Category::getCategoryOthersGroupNative(),
            ],
            'getCategoryValue/common' => [
                static fn () => Category::getCategoryValuePhp('TV_HD'),
                static fn () => Category::getCategoryValueNative('TV_HD'),
            ],
            'getCategoryValue/uncommon' => [
                static fn () => Category::getCategoryValuePhp('BOOKS_TECHNICAL'),
                static fn () => Category::getCategoryValueNative('BOOKS_TECHNICAL'),
            ],
            'isValidGroup/common' => [
                static fn () => UsenetGroup::isValidGroupPhp('alt.binaries.teevee'),
                static fn () => UsenetGroup::isValidGroupNative('alt.binaries.teevee'),
            ],
            'isValidGroup/uncommon' => [
                static fn () => UsenetGroup::isValidGroupPhp('A.B.'.str_repeat('multimedia.sub_group-', 8).'x265'),
                static fn () => UsenetGroup::isValidGroupNative('A.B.'.str_repeat('multimedia.sub_group-', 8).'x265'),
            ],
            'formatGroupStatusMessage/common' => [
                static fn () => UsenetGroup::formatGroupStatusMessagePhp(42, 1),
                static fn () => UsenetGroup::formatGroupStatusMessageNative(42, 1),
            ],
            'formatGroupStatusMessage/uncommon' => [
                static fn () => UsenetGroup::formatGroupStatusMessagePhp(987654321, 0),
                static fn () => UsenetGroup::formatGroupStatusMessageNative(987654321, 0),
            ],
        ];

        $percentile99 = static function (array $ns): float {
            sort($ns);

            return (float) $ns[(int) ceil(count($ns) * 0.99) - 1];
        };

        $random = new Randomizer(new Mt19937(20261024));
        foreach ($workloads as $name => [$phpCall, $rustCall]) {
            self::assertSame($phpCall(), $rustCall(), $name);
            for ($w = 0; $w < 512; $w++) {
                $phpCall();
                $rustCall();
            }

            $reps = [];
            for ($rep = 0; $rep < 3; $rep++) {
                $samples = [[], []];
                for ($pair = 0; $pair < 4096; $pair++) {
                    $first = $random->getInt(0, 1);
                    foreach ([$first, 1 - $first] as $side) {
                        $fn = $side === 0 ? $phpCall : $rustCall;
                        $t0 = hrtime(true);
                        $fn();
                        $samples[$side][] = hrtime(true) - $t0;
                    }
                }
                $phpMean = array_sum($samples[0]) / count($samples[0]);
                $rustMean = array_sum($samples[1]) / count($samples[1]);
                $phpP99 = $percentile99($samples[0]);
                $rustP99 = $percentile99($samples[1]);
                $reps[] = sprintf('rep%d=%.2fx/%.2fx', $rep + 1, $phpMean / $rustMean, $phpP99 / $rustP99);
            }
            fwrite(STDOUT, sprintf("%-34s %s\n", $name, implode('  ', $reps)));
        }
    }
}
