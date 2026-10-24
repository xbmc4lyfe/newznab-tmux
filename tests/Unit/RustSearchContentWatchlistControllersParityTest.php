<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\ContentController;
use App\Http\Controllers\MyMoviesController;
use App\Http\Controllers\MyShowsController;
use App\Http\Controllers\ReleaseReportController;
use App\Http\Controllers\SearchController;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Parity, C-ABI contract, fallback, and paired benchmark verification for Wave 27 Slice 27.5:
 * SearchController, ContentController, MyMoviesController, MyShowsController and
 * ReleaseReportController helpers.
 *
 * Benchmarks run only with NNTMUX_SEARCH_CONTENT_WATCHLIST_BENCH=1:
 *   CARGO_TARGET_DIR=/tmp/cargo-w27-5 cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release
 *   NNTMUX_PIPELINE_FFI_LIB=/tmp/cargo-w27-5/release/libnntmux_pipeline.dylib \
 *     NNTMUX_SEARCH_CONTENT_WATCHLIST_BENCH=1 php artisan test --compact tests/Unit/RustSearchContentWatchlistControllersParityTest.php
 *
 * Paired randomized benchmark evidence (3 repetitions x 41 paired samples x 200 inner loops,
 * worst-repetition PHP latency / Rust latency, FFI call overhead included):
 *
 * Apple M5 (arm64), PHP 8.5.12 NTS, release cdylib, base commit a6c7ccd9d. Raw per-repetition
 * JSON lines are written to STDERR by the benchmark test.
 *
 * | Method                                     | Common (Mean / P99) | Uncommon (Mean / P99) | >= 1.5x | Default     |
 * |--------------------------------------------|---------------------|-----------------------|---------|-------------|
 * | SearchController::reconcilePosterQuery     | 0.26x / 0.26x       | 0.53x / 0.53x         | No      | PHP (false) |
 * | SearchController::searchFieldMap           | 0.10x / 0.09x       | 0.10x / 0.09x         | No      | PHP (false) |
 * | ContentController::isAdminRole             | 0.32x / 0.28x       | 0.34x / 0.34x         | No      | PHP (false) |
 * | ContentController::pageMode                | 0.27x / 0.24x       | 0.41x / 0.45x         | No      | PHP (false) |
 * | ContentController::pageMeta                | 0.14x / 0.14x       | 0.25x / 0.21x         | No      | PHP (false) |
 * | MyMoviesController::postGateBlocked        | 0.29x / 0.29x       | 0.30x / 0.25x         | No      | PHP (false) |
 * | MyMoviesController::watchlistViewState     | 0.27x / 0.27x       | 0.34x / 0.26x         | No      | PHP (false) |
 * | MyShowsController::postGateBlocked         | 0.29x / 0.24x       | 0.25x / 0.15x         | No      | PHP (false) |
 * | MyShowsController::watchlistViewState      | 0.27x / 0.28x       | 0.34x / 0.31x         | No      | PHP (false) |
 * | ReleaseReportController::reportAttributes  | 0.11x / 0.10x       | 0.11x / 0.11x         | No      | PHP (false) |
 * | ReleaseReportController::reportResponse    | 0.09x / 0.08x       | 0.08x / 0.07x         | No      | PHP (false) |
 */
class RustSearchContentWatchlistControllersParityTest extends TestCase
{
    /** @var list<class-string> */
    private const array TOGGLED_CLASSES = [
        SearchController::class,
        ContentController::class,
        MyMoviesController::class,
        MyShowsController::class,
        ReleaseReportController::class,
    ];

    /** @var list<class-string<MyMoviesController|MyShowsController>> */
    private const array WATCHLIST_CLASSES = [
        MyMoviesController::class,
        MyShowsController::class,
    ];

    private const int DECLINE = -2147483648;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetFfiState();
        if (self::library(SearchController::FFI_CDEF) === null) {
            $this->markTestSkipped('Rust pipeline library is not built; run cargo build --release.');
        }
    }

    protected function tearDown(): void
    {
        $this->resetFfiState();
        parent::tearDown();
    }

    private function resetFfiState(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::setUseRustFfi(null);
            $class::setFfiForTesting(null);
        }
    }

    private static function library(string $cdef): ?FFI
    {
        $path = getenv('NNTMUX_PIPELINE_FFI_LIB');

        return RustPipelineLibrary::resolveDomain('search', $cdef, \is_string($path) && $path !== '' ? $path : null);
    }

    /**
     * Point every toggled class at the library under test.
     */
    private function useLibraryUnderTest(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $ffi = self::library($class::FFI_CDEF);
            $this->assertNotNull($ffi, $class);
            $class::setFfiForTesting($ffi);
        }
    }

    public function test_production_defaults_follow_benchmark_gate(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $this->assertFalse(
                $class::usesRustFfi(),
                "{$class} must keep PHP as default until its Rust path passes the 1.5x gate across common and uncommon mean/p99"
            );
        }
    }

    public function test_toggle_api_accepts_nullable_bool(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::setUseRustFfi(true);
            $this->assertTrue($class::usesRustFfi());
            $class::setUseRustFfi(false);
            $this->assertFalse($class::usesRustFfi());
            $class::setUseRustFfi(true);
            $class::setUseRustFfi(null);
            $this->assertFalse($class::usesRustFfi());
        }
    }

    // ---------------------------------------------------------------------
    // 1. SearchController poster reconciliation and field map
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $listQuery
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function reconcileReference(bool $hasPoster, bool $hasAdvanced, string $exact, string $advanced, array $listQuery): array
    {
        if ($hasPoster && $hasAdvanced && $advanced !== $exact) {
            $exact = $advanced;
            if ($exact === '') {
                unset($listQuery['poster']);
            } else {
                $listQuery['poster'] = $exact;
            }
        } elseif (! $hasPoster) {
            $exact = '';
        }
        if ($exact !== '' && ! array_key_exists('searchadvposter', $listQuery)) {
            $listQuery['searchadvposter'] = $exact;
        }

        return [$exact, $listQuery];
    }

    public function test_poster_reconcile_matrix_parity(): void
    {
        $this->useLibraryUnderTest();
        $values = ['', 'alice', 'bob', 'alice ', "nul\0poster", 'ünïcode', '0'];
        $queries = [
            [],
            ['search' => 'x', 'ob' => 'posted_desc'],
            ['poster' => 'alice', 'search_type' => 'adv'],
            ['poster' => 'alice', 'searchadvposter' => 'bob'],
            ['searchadvposter' => null],
            ['searchadvposter' => ''],
        ];
        foreach ([true, false] as $hasPoster) {
            foreach ([true, false] as $hasAdvanced) {
                foreach ($values as $exact) {
                    foreach ($values as $advanced) {
                        foreach ($queries as $query) {
                            $expected = self::reconcileReference($hasPoster, $hasAdvanced, $exact, $advanced, $query);
                            $args = [$hasPoster, $hasAdvanced, $exact, $advanced, $query];
                            $this->assertSame($expected, SearchController::reconcilePosterQueryPhp(...$args));
                            $this->assertSame($expected, SearchController::reconcilePosterQueryNative(...$args));
                        }
                    }
                }
            }
        }
        foreach ([true, false] as $use) {
            SearchController::setUseRustFfi($use);
            $this->assertSame(
                ['bob', ['poster' => 'bob', 'searchadvposter' => 'bob']],
                SearchController::reconcilePosterQuery(true, true, 'alice', 'bob', ['poster' => 'alice', 'searchadvposter' => 'bob']),
            );
            $this->assertSame(['', []], SearchController::reconcilePosterQuery(true, true, 'alice', '', ['poster' => 'alice']));
        }
    }

    public function test_poster_reconcile_preserves_list_query_order(): void
    {
        $this->useLibraryUnderTest();
        $query = ['search' => 'x', 'poster' => 'a', 'ob' => 'size_asc', 'search_type' => 'adv'];
        $php = SearchController::reconcilePosterQueryPhp(true, true, 'a', 'b', $query);
        $native = SearchController::reconcilePosterQueryNative(true, true, 'a', 'b', $query);
        $this->assertSame($php, $native);
        $this->assertSame(['search', 'poster', 'ob', 'search_type', 'searchadvposter'], array_keys($native[1]));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    public static function searchFieldProvider(): array
    {
        return [
            'all empty' => ['', '', '', '', ''],
            'basic search' => ['ubuntu', '', '', '', ''],
            'advanced poster text match' => ['ubuntu', 'iso', '', 'poster@example.com', 'ubuntu.nzb'],
            'exact poster suppresses fromname' => ['ubuntu', 'iso', 'poster@example.com', 'poster@example.com', 'ubuntu.nzb'],
            'binary and utf8' => ["a\0b", "sübject\xff", '', "pöster\0", "file\0name"],
            'long fields' => [str_repeat('search ', 2000), str_repeat('s', 5000), '', str_repeat('p', 3000), str_repeat('f', 4000)],
            'over native input limit' => [str_repeat('x', (1 << 20) + 1), 'y', '', 'z', 'w'],
        ];
    }

    #[DataProvider('searchFieldProvider')]
    public function test_search_field_map_parity(string $search, string $subject, string $exact, string $advanced, string $filename): void
    {
        $this->useLibraryUnderTest();
        $expected = [
            'searchname' => $search,
            'name' => $subject,
            'fromname' => $exact === '' ? $advanced : '',
            'filename' => $filename,
        ];
        $args = [$search, $subject, $exact, $advanced, $filename];
        $this->assertSame($expected, SearchController::searchFieldMapPhp(...$args));
        $this->assertSame($expected, SearchController::searchFieldMapNative(...$args));
        foreach ([true, false] as $use) {
            SearchController::setUseRustFfi($use);
            $this->assertSame($expected, SearchController::searchFieldMap(...$args));
        }
    }

    public function test_search_randomized_parity(): void
    {
        $this->useLibraryUnderTest();
        $pool = ['', 'a', 'alice', 'bob', ' ', "\0", 'ü', '0', '|'];
        mt_srand(2750);
        for ($round = 0; $round < 300; $round++) {
            $pick = static fn (): string => $pool[mt_rand(0, \count($pool) - 1)].$pool[mt_rand(0, \count($pool) - 1)];
            $query = mt_rand(0, 1) === 1 ? ['searchadvposter' => $pick()] : [];
            if (mt_rand(0, 1) === 1) {
                $query['poster'] = $pick();
            }
            $args = [mt_rand(0, 1) === 1, mt_rand(0, 1) === 1, $pick(), $pick(), $query];
            $this->assertSame(SearchController::reconcilePosterQueryPhp(...$args), SearchController::reconcilePosterQueryNative(...$args));
            $fields = [$pick(), $pick(), $pick(), $pick(), $pick()];
            $this->assertSame(SearchController::searchFieldMapPhp(...$fields), SearchController::searchFieldMapNative(...$fields));
        }
    }

    // ---------------------------------------------------------------------
    // 2. ContentController admin role, page mode and meta
    // ---------------------------------------------------------------------

    public function test_content_admin_role_parity(): void
    {
        $this->useLibraryUnderTest();
        foreach ([-1, 0, 1, 2, 3, 4, 5, PHP_INT_MIN, PHP_INT_MAX] as $role) {
            $expected = $role === 2 || $role === 4;
            $this->assertSame($expected, ContentController::isAdminRolePhp($role), "role {$role}");
            $this->assertSame($expected, ContentController::isAdminRoleNative($role), "role {$role}");
            foreach ([true, false] as $use) {
                ContentController::setUseRustFfi($use);
                $this->assertSame($expected, ContentController::isAdminRole($role));
            }
        }
    }

    /**
     * @return array<string, array{0: int, 1: string, 2: int}>
     */
    public static function pageModeProvider(): array
    {
        return [
            'content listing' => [0, 'content', ContentController::PAGE_LISTING],
            'front default' => [0, '', ContentController::PAGE_FRONT],
            'case mismatch is front' => [0, 'Content', ContentController::PAGE_FRONT],
            'trailing space is front' => [0, 'content ', ContentController::PAGE_FRONT],
            'id with content page' => [5, 'content', ContentController::PAGE_BY_ID],
            'id with any page' => [5, 'x', ContentController::PAGE_BY_ID],
            'negative id' => [-3, '0', ContentController::PAGE_BY_ID],
            'id without page is front' => [5, '', ContentController::PAGE_FRONT],
            'integer extremes' => [PHP_INT_MIN, "nul\0", ContentController::PAGE_BY_ID],
            'over native input limit' => [1, str_repeat('p', (1 << 20) + 1), ContentController::PAGE_BY_ID],
        ];
    }

    #[DataProvider('pageModeProvider')]
    public function test_content_page_mode_parity(int $contentId, string $page, int $expected): void
    {
        $this->useLibraryUnderTest();
        $this->assertSame($expected, ContentController::pageModePhp($contentId, $page));
        $this->assertSame($expected, ContentController::pageModeNative($contentId, $page));
        foreach ([true, false] as $use) {
            ContentController::setUseRustFfi($use);
            $this->assertSame($expected, ContentController::pageMode($contentId, $page));
        }
    }

    public function test_content_page_meta_matrix_parity(): void
    {
        $this->useLibraryUnderTest();
        $titles = [null, '', ' ', "\t\n\r\0\x0B ", "\x0C", 'Home', ' Home ', 'ü', '0'];
        $keywords = [null, '', 'nzb,usenet', "k\0w"];
        $descriptions = [null, '', 'Welcome.', str_repeat('d', 3000)];
        foreach ([ContentController::PAGE_LISTING, ContentController::PAGE_BY_ID, ContentController::PAGE_FRONT, 3, -1] as $mode) {
            foreach ($titles as $title) {
                foreach ($keywords as $keyword) {
                    foreach ($descriptions as $description) {
                        $expected = $mode === ContentController::PAGE_FRONT
                            ? [
                                'meta_title' => filled($title) ? $title : 'Contents page',
                                'meta_keywords' => $keyword ?? 'contents',
                                'meta_description' => $description ?? 'This is the contents page.',
                            ]
                            : ['meta_title' => 'Contents page', 'meta_keywords' => 'contents', 'meta_description' => 'This is the contents page.'];
                        $args = [$mode, $title, $keyword, $description];
                        $this->assertSame($expected, ContentController::pageMetaPhp(...$args));
                        $this->assertSame($expected, ContentController::pageMetaNative(...$args));
                    }
                }
            }
        }
        foreach ([true, false] as $use) {
            ContentController::setUseRustFfi($use);
            $this->assertSame(
                ['meta_title' => 'Home', 'meta_keywords' => 'contents', 'meta_description' => 'd'],
                ContentController::pageMeta(ContentController::PAGE_FRONT, 'Home', null, 'd'),
            );
        }
    }

    // ---------------------------------------------------------------------
    // 3-4. MyMoviesController and MyShowsController watchlist helpers
    // ---------------------------------------------------------------------

    public function test_watchlist_post_gate_parity(): void
    {
        $this->useLibraryUnderTest();
        $actions = ['delete', 'doadd', 'doedit', '', 'add', 'edit', 'browse', 'Delete', 'delete ', "doadd\0", 'ëdit'];
        foreach (self::WATCHLIST_CLASSES as $class) {
            foreach ($actions as $action) {
                foreach ([true, false] as $isPost) {
                    $expected = \in_array($action, ['delete', 'doadd', 'doedit'], true) && ! $isPost;
                    $this->assertSame($expected, $class::postGateBlockedPhp($action, $isPost), "{$class} {$action}");
                    $this->assertSame($expected, $class::postGateBlockedNative($action, $isPost), "{$class} {$action}");
                    foreach ([true, false] as $use) {
                        $class::setUseRustFfi($use);
                        $this->assertSame($expected, $class::postGateBlocked($action, $isPost));
                    }
                }
            }
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function watchlistStateProvider(): array
    {
        return [
            'add ignores selection' => ['add', '5010|5020'],
            'edit empty' => ['edit', ''],
            'edit zero' => ['edit', '0'],
            'edit double zero' => ['edit', '00'],
            'edit single' => ['edit', '5030'],
            'edit multiple' => ['edit', '5010|5020|5030'],
            'edit empty segments' => ['edit', '|5010||5020|'],
            'edit separators only' => ['edit', '|||'],
            'edit binary' => ['edit', "50\0|ü|\xff"],
            'unknown type' => ['view', '5010'],
            'edit long' => ['edit', implode('|', range(1000, 9000))],
            'edit over native input limit' => ['edit', str_repeat('1|', (1 << 19) + 1)],
        ];
    }

    #[DataProvider('watchlistStateProvider')]
    public function test_watchlist_view_state_parity(string $type, string $selected): void
    {
        $this->useLibraryUnderTest();
        $categories = [5010 => 'WEB-DL', 5020 => 'Foreign', 5030 => 'SD'];
        $expectations = [
            MyMoviesController::class => $type === 'edit' && ! empty($selected) ? explode('|', $selected) : [],
            MyShowsController::class => $type === 'edit' ? explode('|', $selected) : [],
        ];
        foreach ($expectations as $class => $expectedSelected) {
            $expected = [
                'type' => $type,
                'cat_ids' => [5010, 5020, 5030],
                'cat_names' => $categories,
                'cat_selected' => $expectedSelected,
            ];
            $this->assertSame($expected, $class::watchlistViewStatePhp($type, $categories, $selected), $class);
            $this->assertSame($expected, $class::watchlistViewStateNative($type, $categories, $selected), $class);
            foreach ([true, false] as $use) {
                $class::setUseRustFfi($use);
                $this->assertSame($expected, $class::watchlistViewState($type, $categories, $selected), $class);
            }
        }
    }

    public function test_watchlist_randomized_parity(): void
    {
        $this->useLibraryUnderTest();
        $pool = ['', '|', '0', '5010', '5020', 'x', "\0"];
        mt_srand(2754);
        for ($round = 0; $round < 300; $round++) {
            $selected = '';
            foreach (range(0, mt_rand(0, 6)) as $_) {
                $selected .= $pool[mt_rand(0, \count($pool) - 1)];
            }
            $type = mt_rand(0, 3) === 0 ? 'add' : 'edit';
            foreach (self::WATCHLIST_CLASSES as $class) {
                $this->assertSame(
                    $class::watchlistViewStatePhp($type, [1 => 'a'], $selected),
                    $class::watchlistViewStateNative($type, [1 => 'a'], $selected),
                    "{$class} {$type} ".bin2hex($selected),
                );
            }
        }
    }

    // ---------------------------------------------------------------------
    // 5. ReleaseReportController attributes and response payloads
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{0: int, 1: int, 2: string, 3: ?string}>
     */
    public static function reportAttributesProvider(): array
    {
        return [
            'with description' => [10, 2, 'spam', 'Looks like spam.'],
            'null description' => [10, 2, 'fake', null],
            'empty description' => [1, 1, 'wrong_category', ''],
            'integer extremes' => [PHP_INT_MAX, PHP_INT_MIN, 'other', 'x'],
            'binary and utf8' => [5, 6, "re\0ason", "dëscription\xff\0"],
            'long description' => [7, 8, str_repeat('r', 255), str_repeat('d', 1000)],
            'over native input limit' => [7, 8, 'other', str_repeat('d', (1 << 20) + 1)],
        ];
    }

    #[DataProvider('reportAttributesProvider')]
    public function test_report_attributes_parity(int $releaseId, int $userId, string $reason, ?string $description): void
    {
        $this->useLibraryUnderTest();
        $expected = [
            'releases_id' => $releaseId,
            'users_id' => $userId,
            'reason' => $reason,
            'description' => $description,
            'status' => 'pending',
        ];
        $args = [$releaseId, $userId, $reason, $description];
        $this->assertSame($expected, ReleaseReportController::reportAttributesPhp(...$args));
        $this->assertSame($expected, ReleaseReportController::reportAttributesNative(...$args));
        foreach ([true, false] as $use) {
            ReleaseReportController::setUseRustFfi($use);
            $this->assertSame($expected, ReleaseReportController::reportAttributes(...$args));
        }
    }

    public function test_report_response_parity(): void
    {
        $this->useLibraryUnderTest();
        $expected = [
            ReleaseReportController::REPORT_NOT_FOUND => ['status' => 404, 'payload' => ['success' => false, 'message' => 'Release not found.']],
            ReleaseReportController::REPORT_DUPLICATE => ['status' => 409, 'payload' => ['success' => false, 'message' => 'You have already reported this release.']],
            ReleaseReportController::REPORT_CREATED => ['status' => 200, 'payload' => ['success' => true, 'message' => 'Report submitted successfully. Thank you for helping improve our content.']],
        ];
        foreach ($expected as $outcome => $response) {
            $this->assertSame($response, ReleaseReportController::reportResponsePhp($outcome));
            $this->assertSame($response, ReleaseReportController::reportResponseNative($outcome));
            foreach ([true, false] as $use) {
                ReleaseReportController::setUseRustFfi($use);
                $this->assertSame($response, ReleaseReportController::reportResponse($outcome));
            }
        }
    }

    public function test_report_response_rejects_unknown_outcome_on_both_paths(): void
    {
        $this->useLibraryUnderTest();
        foreach ([3, -1, PHP_INT_MAX] as $outcome) {
            $paths = [
                'php' => static fn (): array => ReleaseReportController::reportResponsePhp($outcome),
                'native' => static fn (): array => ReleaseReportController::reportResponseNative($outcome),
            ];
            foreach ($paths as $path => $call) {
                try {
                    $call();
                    $this->fail("{$path} reportResponse({$outcome}) must throw");
                } catch (InvalidArgumentException $e) {
                    $this->assertStringContainsString((string) $outcome, $e->getMessage());
                }
            }
        }
    }

    // ---------------------------------------------------------------------
    // C-ABI Contract & Fallback Verification
    // ---------------------------------------------------------------------

    private static function ffiCall(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    public function test_c_abi_search_contract(): void
    {
        $ffi = self::library(SearchController::FFI_CDEF);
        $this->assertNotNull($ffi);
        $this->assertSame(1 | 8 | 16, self::ffiCall($ffi, 'nntmux_search_poster_reconcile', 1, 1, 0, 'a', 1, 'b', 1));
        $this->assertSame(1 | 4, self::ffiCall($ffi, 'nntmux_search_poster_reconcile', 1, 1, 0, 'a', 1, '', 0));
        $this->assertSame(2, self::ffiCall($ffi, 'nntmux_search_poster_reconcile', 0, 1, 0, 'a', 1, 'b', 1));
        $this->assertSame(0, self::ffiCall($ffi, 'nntmux_search_poster_reconcile', 1, 0, 1, 'a', 1, 'b', 1));
        $this->assertSame(0, self::ffiCall($ffi, 'nntmux_search_poster_reconcile', 1, 1, 0, null, 0, null, 0));
        $this->assertSame(self::DECLINE, self::ffiCall($ffi, 'nntmux_search_poster_reconcile', 2, 1, 0, 'a', 1, 'b', 1));
        $this->assertSame(self::DECLINE, self::ffiCall($ffi, 'nntmux_search_poster_reconcile', 1, 1, 0, null, 1, 'b', 1));
        $this->assertSame(self::DECLINE, self::ffiCall($ffi, 'nntmux_search_poster_reconcile', 1, 1, 0, 'a', -1, 'b', 1));

        $lens = $ffi->new('size_t[4]');
        $out = $ffi->new('char[16]');
        foreach (range(0, 3) as $i) {
            $lens[$i] = 77;
        }
        $out[0] = 'Z';
        /** @param  array<int, mixed>  $override */
        $call = static fn (array $override = []): mixed => self::ffiCall($ffi, 'nntmux_search_field_map', ...array_replace(
            ['ab', 2, 'cd', 2, 1, 'ef', 2, 'gh', 2, $lens, $out, 16],
            $override,
        ));
        $this->assertSame(-8, $call([11 => 7]));
        $this->assertSame(-8, $call([10 => null, 11 => 0]));
        $this->assertSame('Z', $out[0]);
        $this->assertSame(77, $lens[0]);
        $this->assertSame(self::DECLINE, $call([4 => 2]));
        $this->assertSame(self::DECLINE, $call([0 => null]));
        $this->assertSame(self::DECLINE, $call([1 => -1]));
        $this->assertSame(self::DECLINE, $call([9 => null]));
        $this->assertSame(self::DECLINE, $call([10 => null]));
        $this->assertSame(self::DECLINE, $call([11 => -1]));
        $raw = $ffi->new('uint8_t[64]');
        $this->assertSame(self::DECLINE, $call([9 => $ffi->cast('size_t*', FFI::addr($raw[1]))]));
        $this->assertSame('Z', $out[0]);
        $this->assertSame(6, $call([4 => 0]));
        $this->assertSame('abcdgh', FFI::string($out, 6));
        $this->assertSame([2, 2, 0, 2], [$lens[0], $lens[1], $lens[2], $lens[3]]);
    }

    public function test_c_abi_content_contract(): void
    {
        $ffi = self::library(ContentController::FFI_CDEF);
        $this->assertNotNull($ffi);
        $this->assertSame(1, self::ffiCall($ffi, 'nntmux_content_controller_is_admin', 4, 2, 4));
        $this->assertSame(0, self::ffiCall($ffi, 'nntmux_content_controller_is_admin', 1, 2, 4));
        $this->assertSame(0, self::ffiCall($ffi, 'nntmux_content_controller_page_mode', 0, 'content', 7));
        $this->assertSame(2, self::ffiCall($ffi, 'nntmux_content_controller_page_mode', 0, null, 0));
        $this->assertSame(self::DECLINE, self::ffiCall($ffi, 'nntmux_content_controller_page_mode', 0, null, 3));
        $this->assertSame(self::DECLINE, self::ffiCall($ffi, 'nntmux_content_controller_page_mode', 0, 'x', -1));

        $lens = $ffi->new('size_t[3]');
        $out = $ffi->new('char[64]');
        $lens[0] = 77;
        $out[0] = 'Z';
        /** @param  array<int, mixed>  $override */
        $call = static fn (array $override = []): mixed => self::ffiCall($ffi, 'nntmux_content_controller_page_meta', ...array_replace(
            [2, 7, 'T', 1, 'K', 1, 'D', 1, $lens, $out, 64],
            $override,
        ));
        $this->assertSame(-3, $call([10 => 2]));
        $this->assertSame(-47, $call([0 => 0, 9 => null, 10 => 0]));
        $this->assertSame('Z', $out[0]);
        $this->assertSame(77, $lens[0]);
        $this->assertSame(self::DECLINE, $call([0 => 3]));
        $this->assertSame(self::DECLINE, $call([1 => 8]));
        $this->assertSame(self::DECLINE, $call([2 => null]));
        $this->assertSame(self::DECLINE, $call([3 => -1]));
        $this->assertSame(self::DECLINE, $call([8 => null]));
        $this->assertSame(self::DECLINE, $call([9 => null]));
        $this->assertSame(self::DECLINE, $call([10 => -1]));
        $raw = $ffi->new('uint8_t[64]');
        $this->assertSame(self::DECLINE, $call([8 => $ffi->cast('size_t*', FFI::addr($raw[1]))]));
        $this->assertSame('Z', $out[0]);
        $this->assertSame(3, $call());
        $this->assertSame('TKD', FFI::string($out, 3));
    }

    public function test_c_abi_watchlist_contract(): void
    {
        $ffi = self::library(MyMoviesController::FFI_CDEF);
        $this->assertNotNull($ffi);
        $this->assertSame(1, self::ffiCall($ffi, 'nntmux_watchlist_post_gate', 'delete', 6, 0));
        $this->assertSame(0, self::ffiCall($ffi, 'nntmux_watchlist_post_gate', 'delete', 6, 1));
        $this->assertSame(0, self::ffiCall($ffi, 'nntmux_watchlist_post_gate', null, 0, 0));
        $this->assertSame(self::DECLINE, self::ffiCall($ffi, 'nntmux_watchlist_post_gate', 'delete', 6, 2));
        $this->assertSame(self::DECLINE, self::ffiCall($ffi, 'nntmux_watchlist_post_gate', null, 6, 0));
        $this->assertSame(self::DECLINE, self::ffiCall($ffi, 'nntmux_watchlist_post_gate', 'delete', -1, 0));

        $lens = $ffi->new('size_t[3]');
        foreach (range(0, 2) as $i) {
            $lens[$i] = 77;
        }
        $this->assertSame(-3, self::ffiCall($ffi, 'nntmux_watchlist_selected_categories', 1, 'a|b|c', 5, $lens, 2));
        $this->assertSame(-3, self::ffiCall($ffi, 'nntmux_watchlist_selected_categories', 1, 'a|b|c', 5, null, 0));
        $this->assertSame(77, $lens[0]);
        $this->assertSame(0, self::ffiCall($ffi, 'nntmux_watchlist_selected_categories', 2, '0', 1, null, 0));
        $this->assertSame(0, self::ffiCall($ffi, 'nntmux_watchlist_selected_categories', 0, 'a|b', 3, null, 0));
        $this->assertSame(self::DECLINE, self::ffiCall($ffi, 'nntmux_watchlist_selected_categories', 3, 'a', 1, $lens, 3));
        $this->assertSame(self::DECLINE, self::ffiCall($ffi, 'nntmux_watchlist_selected_categories', 1, null, 1, $lens, 3));
        $this->assertSame(self::DECLINE, self::ffiCall($ffi, 'nntmux_watchlist_selected_categories', 1, 'a', -1, $lens, 3));
        $this->assertSame(self::DECLINE, self::ffiCall($ffi, 'nntmux_watchlist_selected_categories', 1, 'a', 1, null, 3));
        $this->assertSame(self::DECLINE, self::ffiCall($ffi, 'nntmux_watchlist_selected_categories', 1, 'a', 1, $lens, -1));
        $raw = $ffi->new('uint8_t[64]');
        $this->assertSame(self::DECLINE, self::ffiCall($ffi, 'nntmux_watchlist_selected_categories', 1, 'a', 1, $ffi->cast('size_t*', FFI::addr($raw[1])), 1));
        $this->assertSame(77, $lens[0]);
        $this->assertSame(3, self::ffiCall($ffi, 'nntmux_watchlist_selected_categories', 1, 'ab||c', 5, $lens, 3));
        $this->assertSame([2, 0, 1], [$lens[0], $lens[1], $lens[2]]);
    }

    public function test_c_abi_release_report_contract(): void
    {
        $ffi = self::library(ReleaseReportController::FFI_CDEF);
        $this->assertNotNull($ffi);
        $lens = $ffi->new('size_t[3]');
        $out = $ffi->new('char[32]');
        $lens[0] = 77;
        $out[0] = 'Z';
        /** @param  array<int, mixed>  $override */
        $call = static fn (array $override = []): mixed => self::ffiCall($ffi, 'nntmux_release_report_attributes', ...array_replace(
            ['spam', 4, 1, 'desc', 4, $lens, $out, 32],
            $override,
        ));
        $this->assertSame(-15, $call([7 => 14]));
        $this->assertSame(-15, $call([6 => null, 7 => 0]));
        $this->assertSame('Z', $out[0]);
        $this->assertSame(77, $lens[0]);
        $this->assertSame(self::DECLINE, $call([2 => 2]));
        $this->assertSame(self::DECLINE, $call([0 => null]));
        $this->assertSame(self::DECLINE, $call([3 => null]));
        $this->assertSame(self::DECLINE, $call([4 => -1]));
        $this->assertSame(self::DECLINE, $call([5 => null]));
        $this->assertSame(self::DECLINE, $call([6 => null]));
        $this->assertSame(self::DECLINE, $call([7 => -1]));
        $raw = $ffi->new('uint8_t[64]');
        $this->assertSame(self::DECLINE, $call([5 => $ffi->cast('size_t*', FFI::addr($raw[1]))]));
        $this->assertSame('Z', $out[0]);
        $this->assertSame(11, $call([2 => 0]));
        $this->assertSame('spampending', FFI::string($out, 11));
        $this->assertSame([4, 0, 7], [$lens[0], $lens[1], $lens[2]]);

        $meta = $ffi->new('int32_t[2]');
        $meta[0] = 77;
        $message = $ffi->new('char[128]');
        $this->assertSame(-18, self::ffiCall($ffi, 'nntmux_release_report_response', 0, $meta, $message, 4));
        $this->assertSame(-18, self::ffiCall($ffi, 'nntmux_release_report_response', 0, $meta, null, 0));
        $this->assertSame(77, $meta[0]);
        $this->assertSame(self::DECLINE, self::ffiCall($ffi, 'nntmux_release_report_response', 3, $meta, $message, 128));
        $this->assertSame(self::DECLINE, self::ffiCall($ffi, 'nntmux_release_report_response', 0, null, $message, 128));
        $this->assertSame(self::DECLINE, self::ffiCall($ffi, 'nntmux_release_report_response', 0, $meta, null, 128));
        $this->assertSame(self::DECLINE, self::ffiCall($ffi, 'nntmux_release_report_response', 0, $meta, $message, -1));
        $this->assertSame(self::DECLINE, self::ffiCall($ffi, 'nntmux_release_report_response', 0, $ffi->cast('int32_t*', FFI::addr($raw[1])), $message, 128));
        $this->assertSame(77, $meta[0]);
        $this->assertSame(39, self::ffiCall($ffi, 'nntmux_release_report_response', 1, $meta, $message, 128));
        $this->assertSame([409, 0], [$meta[0], $meta[1]]);
        $this->assertSame('You have already reported this release.', FFI::string($message, 39));
    }

    public function test_native_methods_fall_back_to_php_when_ffi_unavailable(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::setFfiForTesting(false);
            $class::setUseRustFfi(true);
        }

        $this->assertSame(
            SearchController::reconcilePosterQueryPhp(true, true, 'a', 'b', ['poster' => 'a']),
            SearchController::reconcilePosterQuery(true, true, 'a', 'b', ['poster' => 'a']),
        );
        $this->assertSame(
            SearchController::searchFieldMapPhp('s', 'n', '', 'p', 'f'),
            SearchController::searchFieldMapNative('s', 'n', '', 'p', 'f'),
        );
        $this->assertTrue(ContentController::isAdminRole(4));
        $this->assertSame(ContentController::PAGE_LISTING, ContentController::pageModeNative(0, 'content'));
        $this->assertSame(
            ['meta_title' => 'Contents page', 'meta_keywords' => 'k', 'meta_description' => 'This is the contents page.'],
            ContentController::pageMeta(ContentController::PAGE_FRONT, ' ', 'k', null),
        );
        foreach (self::WATCHLIST_CLASSES as $class) {
            $this->assertTrue($class::postGateBlocked('doedit', false));
            $this->assertSame(['5010', '5020'], $class::watchlistViewState('edit', [], '5010|5020')['cat_selected']);
        }
        $this->assertSame(
            ReleaseReportController::reportAttributesPhp(1, 2, 'spam', null),
            ReleaseReportController::reportAttributes(1, 2, 'spam', null),
        );
        $this->assertSame(404, ReleaseReportController::reportResponseNative(ReleaseReportController::REPORT_NOT_FOUND)['status']);
    }

    // ---------------------------------------------------------------------
    // Paired Randomized Benchmarks
    // ---------------------------------------------------------------------

    public function test_paired_randomized_benchmarks(): void
    {
        if (getenv('NNTMUX_SEARCH_CONTENT_WATCHLIST_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_SEARCH_CONTENT_WATCHLIST_BENCH=1 to run paired Rust/PHP benchmarks.');
        }
        $this->useLibraryUnderTest();

        $listCommon = ['search' => 'ubuntu', 'ob' => 'posted_desc', 'search_type' => 'basic'];
        $listUncommon = ['search' => 'ubuntu', 'poster' => 'old@example.com', 'ob' => 'size_asc', 'search_type' => 'adv', 'searchadvsubject' => 'iso'];
        foreach (range(1, 20) as $i) {
            $listUncommon["filter_{$i}"] = (string) $i;
        }
        $longPoster = str_repeat('poster', 40).'@example.com';
        $longText = str_repeat('ubuntu desktop amd64 iso ', 40);
        $categories = [2010 => 'Foreign', 2020 => 'Other', 2030 => 'SD', 2040 => 'HD', 2045 => 'UHD', 2050 => 'BluRay', 2060 => '3D', 2070 => 'WEB-DL'];
        $selectedUncommon = implode('|', range(1000, 1400));
        $descriptionUncommon = str_repeat('This release contains a password protected archive. ', 18);

        $workloads = [
            'SearchController::reconcilePosterQuery' => [
                SearchController::class,
                static fn () => SearchController::reconcilePosterQuery(false, false, '', '', $listCommon),
                static fn () => SearchController::reconcilePosterQuery(true, true, 'old@example.com', $longPoster, $listUncommon),
            ],
            'SearchController::searchFieldMap' => [
                SearchController::class,
                static fn () => SearchController::searchFieldMap('ubuntu', '', '', '', ''),
                static fn () => SearchController::searchFieldMap($longText, $longText, '', $longPoster, $longText),
            ],
            'ContentController::isAdminRole' => [
                ContentController::class,
                static fn () => ContentController::isAdminRole(1),
                static fn () => ContentController::isAdminRole(4),
            ],
            'ContentController::pageMode' => [
                ContentController::class,
                static fn () => ContentController::pageMode(0, ''),
                static fn () => ContentController::pageMode(PHP_INT_MAX, str_repeat('content', 40)),
            ],
            'ContentController::pageMeta' => [
                ContentController::class,
                static fn () => ContentController::pageMeta(ContentController::PAGE_FRONT, 'Welcome', 'nzb,usenet', 'Usenet indexer'),
                static fn () => ContentController::pageMeta(ContentController::PAGE_FRONT, str_repeat(' ', 200), $longText, $longText),
            ],
            'MyMoviesController::postGateBlocked' => [
                MyMoviesController::class,
                static fn () => MyMoviesController::postGateBlocked('', false),
                static fn () => MyMoviesController::postGateBlocked('doedit', false),
            ],
            'MyMoviesController::watchlistViewState' => [
                MyMoviesController::class,
                static fn () => MyMoviesController::watchlistViewState('edit', $categories, '2030|2040'),
                static fn () => MyMoviesController::watchlistViewState('edit', $categories, $selectedUncommon),
            ],
            'MyShowsController::postGateBlocked' => [
                MyShowsController::class,
                static fn () => MyShowsController::postGateBlocked('', false),
                static fn () => MyShowsController::postGateBlocked('delete', true),
            ],
            'MyShowsController::watchlistViewState' => [
                MyShowsController::class,
                static fn () => MyShowsController::watchlistViewState('edit', $categories, '5030|5040'),
                static fn () => MyShowsController::watchlistViewState('edit', $categories, $selectedUncommon),
            ],
            'ReleaseReportController::reportAttributes' => [
                ReleaseReportController::class,
                static fn () => ReleaseReportController::reportAttributes(123456, 42, 'spam', null),
                static fn () => ReleaseReportController::reportAttributes(PHP_INT_MAX, PHP_INT_MAX, 'password_protected', $descriptionUncommon),
            ],
            'ReleaseReportController::reportResponse' => [
                ReleaseReportController::class,
                static fn () => ReleaseReportController::reportResponse(ReleaseReportController::REPORT_CREATED),
                static fn () => ReleaseReportController::reportResponse(ReleaseReportController::REPORT_DUPLICATE),
            ],
        ];

        $rows = [];
        foreach ($workloads as $name => [$class, $common, $uncommon]) {
            $worst = ['common_mean' => INF, 'common_p99' => INF, 'uncommon_mean' => INF, 'uncommon_p99' => INF];
            for ($rep = 0; $rep < 3; $rep++) {
                foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $fn) {
                    $class::setUseRustFfi(true);
                    $rustOut = self::invoke($fn);
                    $class::setUseRustFfi(false);
                    $this->assertSame(self::invoke($fn), $rustOut, "{$name} {$kind} benchmark output parity");

                    $stats = $this->runPairedBenchmark($class, $fn, 200, 41, 2705 + $rep);
                    fwrite(STDERR, json_encode(['method' => $name, 'workload' => $kind, 'repetition' => $rep] + $stats).PHP_EOL);
                    $worst["{$kind}_mean"] = min($worst["{$kind}_mean"], $stats['mean_speedup']);
                    $worst["{$kind}_p99"] = min($worst["{$kind}_p99"], $stats['p99_speedup']);
                }
            }
            $class::setUseRustFfi(false);
            $qualified = min($worst) >= 1.5;
            $rows[] = sprintf(
                ' * | %-42s | %.2fx / %.2fx       | %.2fx / %.2fx         | %-7s | %-11s |',
                $name,
                $worst['common_mean'],
                $worst['common_p99'],
                $worst['uncommon_mean'],
                $worst['uncommon_p99'],
                $qualified ? 'Yes' : 'No',
                $qualified ? 'Rust (true)' : 'PHP (false)',
            );
        }
        $this->resetFfiState();
        foreach (self::TOGGLED_CLASSES as $class) {
            $this->assertFalse($class::usesRustFfi(), "{$class} must keep the PHP default");
        }

        fwrite(STDERR, implode(PHP_EOL, $rows).PHP_EOL);
    }

    private static function invoke(Closure $fn): mixed
    {
        return $fn();
    }

    /**
     * @param  class-string  $class
     * @return array{rust_mean_us: float, php_mean_us: float, rust_p99_us: float, php_p99_us: float, mean_speedup: float, p99_speedup: float, samples: int, inner_loops: int}
     */
    private function runPairedBenchmark(string $class, Closure $fn, int $innerLoops, int $samples, int $seed): array
    {
        foreach ([true, false] as $use) {
            $class::setUseRustFfi($use);
            for ($w = 0; $w < 50; $w++) {
                $fn();
            }
        }

        $times = [1 => [], 0 => []];
        mt_srand($seed);
        for ($s = 0; $s < $samples; $s++) {
            $order = mt_rand(0, 1) === 0 ? [true, false] : [false, true];
            foreach ($order as $use) {
                $class::setUseRustFfi($use);
                $t0 = hrtime(true);
                for ($i = 0; $i < $innerLoops; $i++) {
                    $fn();
                }
                $times[(int) $use][] = (hrtime(true) - $t0) / 1000.0 / $innerLoops;
            }
        }

        $rust = $times[1];
        $php = $times[0];
        sort($rust);
        sort($php);
        $p99 = (int) floor((\count($rust) - 1) * 0.99);
        $rustMean = array_sum($rust) / \count($rust);
        $phpMean = array_sum($php) / \count($php);

        return [
            'rust_mean_us' => $rustMean,
            'php_mean_us' => $phpMean,
            'rust_p99_us' => $rust[$p99],
            'php_p99_us' => $php[$p99],
            'mean_speedup' => $phpMean / $rustMean,
            'p99_speedup' => $php[$p99] / $rust[$p99],
            'samples' => $samples,
            'inner_loops' => $innerLoops,
        ];
    }
}
