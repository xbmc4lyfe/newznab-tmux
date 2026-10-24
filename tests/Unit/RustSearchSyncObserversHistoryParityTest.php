<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Category;
use App\Models\RegistrationStatusHistory;
use App\Models\RootCategory;
use App\Models\UsenetGroup;
use App\Models\UserExcludedCategory;
use App\Observers\CategoryObserver;
use App\Observers\RootCategoryObserver;
use App\Observers\UsenetGroupObserver;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Parity, C-ABI contract, fallback, and paired benchmark verification for Wave 26 Slice 26.5:
 * CategoryObserver, RootCategoryObserver, UsenetGroupObserver, UserExcludedCategory and
 * RegistrationStatusHistory.
 *
 * Benchmarks run only with NNTMUX_SEARCH_SYNC_OBSERVERS_HISTORY_BENCH=1:
 *   CARGO_TARGET_DIR=/tmp/cargo-w26-5 cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release
 *   NNTMUX_PIPELINE_FFI_LIB=/tmp/cargo-w26-5/release/libnntmux_pipeline.dylib \
 *     NNTMUX_SEARCH_SYNC_OBSERVERS_HISTORY_BENCH=1 php artisan test --compact tests/Unit/RustSearchSyncObserversHistoryParityTest.php
 *
 * Paired randomized benchmark evidence (3 repetitions x 41 paired samples x 200 inner loops,
 * worst-repetition PHP latency / Rust latency, FFI call overhead included):
 *
 * Apple M5 (arm64), PHP 8.5.12 NTS, release cdylib, base commit badf73a91. Raw per-repetition
 * JSON lines are written to STDERR by the benchmark test.
 *
 * | Method                                        | Common (Mean / P99) | Uncommon (Mean / P99) | >= 1.5x | Default     |
 * |-----------------------------------------------|---------------------|-----------------------|---------|-------------|
 * | CategoryObserver::requiresReindex             | 0.17x / 0.12x       | 0.05x / 0.07x         | No      | PHP (false) |
 * | RootCategoryObserver::requiresReindex         | 0.15x / 0.16x       | 0.04x / 0.03x         | No      | PHP (false) |
 * | UsenetGroupObserver::requiresReindex          | 0.16x / 0.15x       | 0.04x / 0.05x         | No      | PHP (false) |
 * | UserExcludedCategory::normalizeExclusionPairs | 0.52x / 0.41x       | 0.83x / 0.86x         | No      | PHP (false) |
 * | RegistrationStatusHistory::recordAttributes   | 0.10x / 0.10x       | 0.10x / 0.10x         | No      | PHP (false) |
 */
class RustSearchSyncObserversHistoryParityTest extends TestCase
{
    /** @var list<class-string> */
    private const array TOGGLED_CLASSES = [
        CategoryObserver::class,
        RootCategoryObserver::class,
        UsenetGroupObserver::class,
        UserExcludedCategory::class,
        RegistrationStatusHistory::class,
    ];

    /** @var array<class-string, list<string>> */
    private const array OBSERVER_FIELDS = [
        CategoryObserver::class => ['title', 'root_categories_id'],
        RootCategoryObserver::class => ['title'],
        UsenetGroupObserver::class => ['name'],
    ];

    private const int DECLINE = -2147483648;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetFfiState();
        if (self::library(CategoryObserver::FFI_CDEF) === null) {
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
            $class::setUseRustFfi(false);
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

    public function test_toggle_api(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::setUseRustFfi(true);
            $this->assertTrue($class::usesRustFfi());
            $class::setUseRustFfi(false);
            $this->assertFalse($class::usesRustFfi());
        }
    }

    // ---------------------------------------------------------------------
    // 1-3. Search-sync observers dirty-attribute inspection
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{0: array<array-key, mixed>}>
     */
    public static function dirtySetProvider(): array
    {
        return [
            'empty' => [[]],
            'timestamps only' => [['updated_at' => '2026-10-24 12:00:00']],
            'title change' => [['title' => 'Movies']],
            'root category move' => [['root_categories_id' => 2000, 'updated_at' => 1]],
            'name change' => [['name' => 'alt.binaries.test']],
            'null values still count' => [['title' => null, 'name' => null]],
            'unrelated columns' => [['status' => 1, 'description' => 'x', 'active' => 0, 'backfill' => 1]],
            'case and whitespace mismatches' => [['Title' => 1, 'title ' => 1, ' name' => 1, 'NAME' => 1]],
            'nul suffixed keys' => [["title\0" => 1, "name\0" => 1, "root_categories_id\0" => 1]],
            'integer keys' => [[0 => 'a', 1 => 'b', 42 => 'c']],
            'empty string key and indexed' => [['' => 1, 'root_categories_id' => 'x']],
            'utf8 keys' => [['títle' => 1, 'nämé' => 1]],
            'indexed field last' => [['first_record' => 1, 'last_record' => 2, 'name' => 'x']],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $dirty
     */
    #[DataProvider('dirtySetProvider')]
    public function test_observer_reindex_parity(array $dirty): void
    {
        $this->useLibraryUnderTest();
        foreach (self::OBSERVER_FIELDS as $class => $fields) {
            $expected = false;
            foreach ($fields as $field) {
                $expected = $expected || array_key_exists($field, $dirty);
            }
            $this->assertSame($expected, $class::requiresReindexPhp($dirty), $class);
            $this->assertSame($expected, $class::requiresReindexNative($dirty), $class);
            foreach ([true, false] as $use) {
                $class::setUseRustFfi($use);
                $this->assertSame($expected, $class::requiresReindex($dirty), $class);
            }
        }
    }

    public function test_observer_reindex_matches_model_is_dirty(): void
    {
        $this->useLibraryUnderTest();
        $models = [
            CategoryObserver::class => [
                static fn (): Category => new Category,
                ['title' => 'TV', 'root_categories_id' => 5000, 'status' => 1, 'description' => 'd'],
            ],
            RootCategoryObserver::class => [
                static fn (): RootCategory => new RootCategory,
                ['title' => 'Movies', 'status' => 1, 'disablepreview' => 0],
            ],
            UsenetGroupObserver::class => [
                static fn (): UsenetGroup => new UsenetGroup,
                ['name' => 'alt.binaries.test', 'active' => 1, 'backfill' => 0, 'description' => 'd'],
            ],
        ];
        foreach ($models as $class => [$factory, $original]) {
            foreach ([...array_keys($original), 'updated_at'] as $changed) {
                $model = $factory();
                $model->setRawAttributes($original, true);
                $model->setAttribute($changed, $changed === 'updated_at' ? '2026-10-24 12:00:00' : 'changed-'.$changed);
                $expected = $model->isDirty(self::OBSERVER_FIELDS[$class]);
                foreach ([true, false] as $use) {
                    $class::setUseRustFfi($use);
                    $this->assertSame($expected, $class::requiresReindex($model->getDirty()), "{$class} {$changed}");
                }
            }
        }
    }

    public function test_observer_randomized_reindex_parity(): void
    {
        $this->useLibraryUnderTest();
        $pool = ['title', 'name', 'root_categories_id', 'updated_at', 'status', 'Title', '', "name\0", 'description', 'active'];
        mt_srand(265);
        for ($round = 0; $round < 300; $round++) {
            $dirty = [];
            foreach (range(0, mt_rand(0, 6)) as $_) {
                $dirty[$pool[mt_rand(0, \count($pool) - 1)]] = mt_rand();
            }
            foreach (array_keys(self::OBSERVER_FIELDS) as $class) {
                $this->assertSame($class::requiresReindexPhp($dirty), $class::requiresReindexNative($dirty), $class);
            }
        }
    }

    // ---------------------------------------------------------------------
    // 4. UserExcludedCategory pair normalization
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{0: int, 1: array<array-key, int|string|float|bool|null>}>
     */
    public static function exclusionProvider(): array
    {
        return [
            'empty' => [7, []],
            'single' => [7, [5010]],
            'duplicates keep first occurrence' => [7, [5030, 2010, 5030, 2010, 7010]],
            'string ids cast like the caller' => [42, ['5010', ' 5020', '5010', '5020abc', 'abc', '']],
            'mixed scalars' => [1, [true, false, null, 3.9, -3.9, '1e3', 1000, 0]],
            'integer extremes' => [PHP_INT_MAX, [PHP_INT_MIN, PHP_INT_MAX, -1, 0, PHP_INT_MIN]],
            'keyed input order' => [3, ['b' => 2, 'a' => 1, 9 => 2, 'c' => 3]],
            'negative user id' => [-5, [1, 2, 3]],
        ];
    }

    /**
     * @param  array<array-key, int|string|float|bool|null>  $categoryIds
     */
    #[DataProvider('exclusionProvider')]
    public function test_excluded_category_pair_parity(int $userId, array $categoryIds): void
    {
        $this->useLibraryUnderTest();
        $expected = [];
        foreach ($categoryIds as $categoryId) {
            $row = ['users_id' => $userId, 'categories_id' => (int) $categoryId];
            if (! \in_array($row, $expected, true)) {
                $expected[] = $row;
            }
        }
        $this->assertSame($expected, UserExcludedCategory::normalizeExclusionPairsPhp($userId, $categoryIds));
        $this->assertSame($expected, UserExcludedCategory::normalizeExclusionPairsNative($userId, $categoryIds));
        foreach ([true, false] as $use) {
            UserExcludedCategory::setUseRustFfi($use);
            $this->assertSame($expected, UserExcludedCategory::normalizeExclusionPairs($userId, $categoryIds));
        }
    }

    public function test_excluded_category_randomized_parity(): void
    {
        $this->useLibraryUnderTest();
        mt_srand(2655);
        foreach ([0, 1, 31, 32, 33, 200, 2000] as $size) {
            $ids = [];
            for ($i = 0; $i < $size; $i++) {
                $ids[] = mt_rand(0, 3) === 0 ? (string) mt_rand(1000, 1100) : mt_rand(1000, 1100);
            }
            $this->assertSame(
                UserExcludedCategory::normalizeExclusionPairsPhp(9, $ids),
                UserExcludedCategory::normalizeExclusionPairsNative(9, $ids),
                "size {$size}",
            );
        }
    }

    // ---------------------------------------------------------------------
    // 5. RegistrationStatusHistory record attributes
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string, 2: ?int, 3: ?int, 4: ?int, 5: ?int, 6: array<string, mixed>}>
     */
    public static function historyProvider(): array
    {
        return [
            'manual status change' => [RegistrationStatusHistory::ACTION_MANUAL_STATUS_CHANGED, 'Registration status changed from Open to Closed.', 1, 0, 2, null, ['note' => 'maintenance']],
            'period created' => [RegistrationStatusHistory::ACTION_PERIOD_CREATED, 'Registration period "Spring" was created.', 3, null, null, 17, ['period' => ['id' => 17]]],
            'period completed by system' => [RegistrationStatusHistory::ACTION_PERIOD_COMPLETED, 'Done', null, null, null, 18, []],
            'all null' => [RegistrationStatusHistory::ACTION_PERIOD_DELETED, '', null, null, null, null, []],
            'empty strings' => ['', '', 0, 0, 0, 0, []],
            'integer extremes' => ['custom', 'x', PHP_INT_MIN, PHP_INT_MAX, -1, 0, ['nested' => ['a' => [1, 2]]]],
            'binary and utf8' => ["act\0ion", "désc\xff\0ription", 5, null, 7, null, ['k' => "\0"]],
            'long description' => [RegistrationStatusHistory::ACTION_PERIOD_UPDATED, str_repeat('long description ', 4000), 2, 1, 0, 9, []],
            'over native input limit' => [RegistrationStatusHistory::ACTION_PERIOD_TOGGLED, str_repeat('x', (1 << 20) + 1), 1, null, null, 2, []],
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    #[DataProvider('historyProvider')]
    public function test_history_record_attributes_parity(
        string $action,
        string $description,
        ?int $changedBy,
        ?int $oldStatus,
        ?int $newStatus,
        ?int $periodId,
        array $metadata,
    ): void {
        $this->useLibraryUnderTest();
        $expected = [
            'action' => $action,
            'description' => $description,
            'changed_by' => $changedBy,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'registration_period_id' => $periodId,
            'metadata' => $metadata,
        ];
        $args = [$action, $description, $changedBy, $oldStatus, $newStatus, $periodId, $metadata];
        $this->assertSame($expected, RegistrationStatusHistory::recordAttributesPhp(...$args));
        $this->assertSame($expected, RegistrationStatusHistory::recordAttributesNative(...$args));
        foreach ([true, false] as $use) {
            RegistrationStatusHistory::setUseRustFfi($use);
            $this->assertSame($expected, RegistrationStatusHistory::recordAttributes(...$args));
        }
    }

    public function test_history_record_attributes_mask_matrix(): void
    {
        $this->useLibraryUnderTest();
        $values = [11, PHP_INT_MIN, PHP_INT_MAX, 0];
        for ($mask = 0; $mask < 16; $mask++) {
            $ints = [];
            foreach ($values as $slot => $value) {
                $ints[] = ($mask & (1 << $slot)) !== 0 ? $value : null;
            }
            $args = ['period_toggled', "mask {$mask}", ...$ints, ['mask' => $mask]];
            $this->assertSame(
                RegistrationStatusHistory::recordAttributesPhp(...$args),
                RegistrationStatusHistory::recordAttributesNative(...$args),
                "mask {$mask}",
            );
        }
    }

    // ---------------------------------------------------------------------
    // C-ABI Contract & Fallback Verification
    // ---------------------------------------------------------------------

    private static function ffiCall(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    public function test_c_abi_reindex_contract(): void
    {
        $ffi = self::library(CategoryObserver::FFI_CDEF);
        $this->assertNotNull($ffi);
        $lens = $ffi->new('size_t[2]');
        $lens[0] = 5;
        $lens[1] = 4;
        $this->assertSame(1, self::ffiCall($ffi, 'nntmux_search_sync_reindex_required', 0, 'titlename', 9, $lens, 2));
        $this->assertSame(1, self::ffiCall($ffi, 'nntmux_search_sync_reindex_required', 2, 'titlename', 9, $lens, 2));
        $this->assertSame(self::DECLINE, self::ffiCall($ffi, 'nntmux_search_sync_reindex_required', 2, 'titlename', 9, $lens, 1));
        $this->assertSame(self::DECLINE, self::ffiCall($ffi, 'nntmux_search_sync_reindex_required', 3, 'titlename', 9, $lens, 2));
        $this->assertSame(self::DECLINE, self::ffiCall($ffi, 'nntmux_search_sync_reindex_required', 0, null, 9, $lens, 2));
        $this->assertSame(self::DECLINE, self::ffiCall($ffi, 'nntmux_search_sync_reindex_required', 0, 'titlename', 9, null, 2));
        $this->assertSame(self::DECLINE, self::ffiCall($ffi, 'nntmux_search_sync_reindex_required', 0, 'titlename', 9, $lens, PHP_INT_MAX));
        $this->assertSame(0, self::ffiCall($ffi, 'nntmux_search_sync_reindex_required', 1, null, 0, null, 0));
        $raw = $ffi->new('uint8_t[32]');
        $unaligned = $ffi->cast('size_t*', FFI::addr($raw[1]));
        $this->assertSame(self::DECLINE, self::ffiCall($ffi, 'nntmux_search_sync_reindex_required', 0, 'title', 5, $unaligned, 1));
        $lens[0] = PHP_INT_MAX;
        $this->assertSame(self::DECLINE, self::ffiCall($ffi, 'nntmux_search_sync_reindex_required', 0, 'titlename', 9, $lens, 2));
        // -1 wraps to SIZE_MAX, so the second length overflows the running offset.
        $lens[0] = 1;
        $lens[1] = -1;
        $this->assertSame(self::DECLINE, self::ffiCall($ffi, 'nntmux_search_sync_reindex_required', 0, 'titlename', 9, $lens, 2));
        $this->assertSame(self::DECLINE, self::ffiCall($ffi, 'nntmux_search_sync_reindex_required', 0, 'titlename', -1, $lens, 0));
    }

    public function test_c_abi_excluded_category_contract(): void
    {
        $ffi = self::library(UserExcludedCategory::FFI_CDEF);
        $this->assertNotNull($ffi);
        $ids = $ffi->new('int64_t[4]');
        foreach ([5, 3, 5, 9] as $i => $id) {
            $ids[$i] = $id;
        }
        $out = $ffi->new('int64_t[4]');
        foreach (range(0, 3) as $i) {
            $out[$i] = -99;
        }
        $this->assertSame(-3, self::ffiCall($ffi, 'nntmux_user_excluded_category_pairs', $ids, 4, $out, 2));
        foreach (range(0, 3) as $i) {
            $this->assertSame(-99, $out[$i]);
        }
        $this->assertSame(-3, self::ffiCall($ffi, 'nntmux_user_excluded_category_pairs', $ids, 4, null, 0));
        $this->assertSame(3, self::ffiCall($ffi, 'nntmux_user_excluded_category_pairs', $ids, 4, $out, 4));
        $this->assertSame([5, 3, 9, -99], [$out[0], $out[1], $out[2], $out[3]]);
        $this->assertSame(0, self::ffiCall($ffi, 'nntmux_user_excluded_category_pairs', null, 0, null, 0));
        $this->assertSame(self::DECLINE, self::ffiCall($ffi, 'nntmux_user_excluded_category_pairs', null, 2, $out, 4));
        $this->assertSame(self::DECLINE, self::ffiCall($ffi, 'nntmux_user_excluded_category_pairs', $ids, 4, null, 4));
        $this->assertSame(self::DECLINE, self::ffiCall($ffi, 'nntmux_user_excluded_category_pairs', $ids, PHP_INT_MAX, $out, 4));
        $this->assertSame(self::DECLINE, self::ffiCall($ffi, 'nntmux_user_excluded_category_pairs', $ids, 4, $out, -1));
        $this->assertSame(self::DECLINE, self::ffiCall($ffi, 'nntmux_user_excluded_category_pairs', $ids, -1, $out, 4));
        $raw = $ffi->new('uint8_t[40]');
        $unaligned = $ffi->cast('int64_t*', FFI::addr($raw[1]));
        $this->assertSame(self::DECLINE, self::ffiCall($ffi, 'nntmux_user_excluded_category_pairs', $unaligned, 1, $out, 4));
        $this->assertSame(self::DECLINE, self::ffiCall($ffi, 'nntmux_user_excluded_category_pairs', $ids, 1, $unaligned, 1));
    }

    public function test_c_abi_history_contract(): void
    {
        $ffi = self::library(RegistrationStatusHistory::FFI_CDEF);
        $this->assertNotNull($ffi);
        $ints = $ffi->new('int64_t[4]');
        $outInts = $ffi->new('int64_t[4]');
        $outMask = $ffi->new('uint32_t[1]');
        $outLens = $ffi->new('size_t[2]');
        $out = $ffi->new('char[8]');
        foreach (range(0, 3) as $i) {
            $ints[$i] = $i + 1;
            $outInts[$i] = 77;
        }
        $outMask[0] = 77;
        $outLens[0] = 77;
        $out[0] = 'Z';
        /** @param  array<int, mixed>  $override */
        $call = static fn (array $override = []): mixed => self::ffiCall($ffi, 'nntmux_registration_history_attributes', ...array_replace(
            ['action', 6, 'desc', 4, $ints, 15, $outInts, $outMask, $outLens, $out, 8],
            $override,
        ));

        $this->assertSame(-10, $call());
        $this->assertSame('Z', $out[0]);
        $this->assertSame(77, $outInts[0]);
        $this->assertSame(77, $outMask[0]);
        $this->assertSame(77, $outLens[0]);
        $this->assertSame(-10, $call([9 => null, 10 => 0]));
        $this->assertSame(self::DECLINE, $call([5 => 16]));
        $this->assertSame(self::DECLINE, $call([0 => null]));
        $this->assertSame(self::DECLINE, $call([2 => null]));
        $this->assertSame(self::DECLINE, $call([4 => null]));
        $this->assertSame(self::DECLINE, $call([6 => null]));
        $this->assertSame(self::DECLINE, $call([7 => null]));
        $this->assertSame(self::DECLINE, $call([8 => null]));
        $this->assertSame(self::DECLINE, $call([9 => null]));
        $this->assertSame(self::DECLINE, $call([10 => -1]));
        $this->assertSame(self::DECLINE, $call([1 => -1]));
        $raw = $ffi->new('uint8_t[64]');
        $this->assertSame(self::DECLINE, $call([4 => $ffi->cast('int64_t*', FFI::addr($raw[1]))]));
        $this->assertSame(self::DECLINE, $call([6 => $ffi->cast('int64_t*', FFI::addr($raw[1]))]));
        $this->assertSame(self::DECLINE, $call([7 => $ffi->cast('uint32_t*', FFI::addr($raw[1]))]));
        $this->assertSame(self::DECLINE, $call([8 => $ffi->cast('size_t*', FFI::addr($raw[1]))]));
        $this->assertSame('Z', $out[0]);
        $this->assertSame(77, $outMask[0]);

        $this->assertSame(4, $call([0 => 'ab', 1 => 2, 2 => 'cd', 3 => 2, 5 => 5]));
        $this->assertSame('abcd', FFI::string($out, 4));
        $this->assertSame([1, 0, 3, 0], [$outInts[0], $outInts[1], $outInts[2], $outInts[3]]);
        $this->assertSame(5, $outMask[0]);
        $this->assertSame([2, 2], [$outLens[0], $outLens[1]]);
    }

    public function test_native_methods_fall_back_to_php_when_ffi_unavailable(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::setFfiForTesting(false);
            $class::setUseRustFfi(true);
        }

        $this->assertTrue(CategoryObserver::requiresReindexNative(['root_categories_id' => 1]));
        $this->assertFalse(RootCategoryObserver::requiresReindexNative(['name' => 'x']));
        $this->assertTrue(UsenetGroupObserver::requiresReindex(['name' => 'x']));
        $this->assertSame(
            [['users_id' => 4, 'categories_id' => 2], ['users_id' => 4, 'categories_id' => 1]],
            UserExcludedCategory::normalizeExclusionPairs(4, [2, '1', 2]),
        );
        $this->assertSame(
            RegistrationStatusHistory::recordAttributesPhp('period_created', 'd', 1, null, null, 3, ['a' => 1]),
            RegistrationStatusHistory::recordAttributes('period_created', 'd', 1, null, null, 3, ['a' => 1]),
        );
    }

    // ---------------------------------------------------------------------
    // Paired Randomized Benchmarks
    // ---------------------------------------------------------------------

    public function test_paired_randomized_benchmarks(): void
    {
        if (getenv('NNTMUX_SEARCH_SYNC_OBSERVERS_HISTORY_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_SEARCH_SYNC_OBSERVERS_HISTORY_BENCH=1 to run paired Rust/PHP benchmarks.');
        }
        $this->useLibraryUnderTest();

        $categoryCommon = ['title' => 'Movies > HD', 'updated_at' => '2026-10-24 12:00:00'];
        $groupCommon = ['last_record' => 123456, 'last_updated' => '2026-10-24 12:00:00', 'updated_at' => '2026-10-24 12:00:00'];
        $dirtyUncommon = [];
        foreach (range(1, 40) as $i) {
            $dirtyUncommon["unindexed_column_{$i}"] = $i;
        }
        $idsCommon = [2010, 2020, 2030, 5010, 5020, 6010, 6020, 6030];
        $idsUncommon = [];
        mt_srand(26050);
        foreach (range(1, 400) as $_) {
            $idsUncommon[] = mt_rand(0, 1) === 0 ? mt_rand(1000, 8999) : (string) mt_rand(1000, 8999);
        }
        $descriptionUncommon = str_repeat('Registration period "Long Period Name" was updated. ', 40);

        $workloads = [
            'CategoryObserver::requiresReindex' => [
                CategoryObserver::class,
                static fn () => CategoryObserver::requiresReindex($categoryCommon),
                static fn () => CategoryObserver::requiresReindex($dirtyUncommon),
            ],
            'RootCategoryObserver::requiresReindex' => [
                RootCategoryObserver::class,
                static fn () => RootCategoryObserver::requiresReindex($categoryCommon),
                static fn () => RootCategoryObserver::requiresReindex($dirtyUncommon),
            ],
            'UsenetGroupObserver::requiresReindex' => [
                UsenetGroupObserver::class,
                static fn () => UsenetGroupObserver::requiresReindex($groupCommon),
                static fn () => UsenetGroupObserver::requiresReindex($dirtyUncommon),
            ],
            'UserExcludedCategory::normalizeExclusionPairs' => [
                UserExcludedCategory::class,
                static fn () => UserExcludedCategory::normalizeExclusionPairs(42, $idsCommon),
                static fn () => UserExcludedCategory::normalizeExclusionPairs(PHP_INT_MAX, $idsUncommon),
            ],
            'RegistrationStatusHistory::recordAttributes' => [
                RegistrationStatusHistory::class,
                static fn () => RegistrationStatusHistory::recordAttributes('manual_status_changed', 'Registration status changed from Open to Closed.', 1, 0, 2, null, ['note' => null]),
                static fn () => RegistrationStatusHistory::recordAttributes('period_updated', $descriptionUncommon, PHP_INT_MAX, null, PHP_INT_MIN, 17, ['before' => [], 'after' => []]),
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

                    $stats = $this->runPairedBenchmark($class, $fn, 200, 41, 2605 + $rep);
                    fwrite(STDERR, json_encode(['method' => $name, 'workload' => $kind, 'repetition' => $rep] + $stats).PHP_EOL);
                    $worst["{$kind}_mean"] = min($worst["{$kind}_mean"], $stats['mean_speedup']);
                    $worst["{$kind}_p99"] = min($worst["{$kind}_p99"], $stats['p99_speedup']);
                }
            }
            $class::setUseRustFfi(false);
            $qualified = min($worst) >= 1.5;
            $rows[] = sprintf(
                ' * | %-45s | %.2fx / %.2fx       | %.2fx / %.2fx         | %-7s | %-11s |',
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
