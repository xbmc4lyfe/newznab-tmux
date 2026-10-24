<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * App\Models\UserExcludedCategory.
 *
 * @property int $id
 * @property int $users_id
 * @property int $categories_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Category $category
 *
 * @method static \Illuminate\Database\Eloquent\Builder|UserExcludedCategory whereUsersId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserExcludedCategory whereCategoriesId($value)
 *
 * @mixin \Eloquent
 */
class UserExcludedCategory extends Model
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_user_excluded_category_pairs(const int64_t *ids, size_t count, int64_t *out, size_t cap);
        C;

    /**
     * The Rust helper stays opt-in: it did not beat PHP by 1.5x in the paired benchmark
     * (tests/Unit/RustSearchSyncObserversHistoryParityTest.php).
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    protected $table = 'user_excluded_categories';

    protected $fillable = [
        'users_id',
        'categories_id',
    ];

    protected function casts(): array
    {
        return [
            'users_id' => 'integer',
            'categories_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saved(static function (self $excludedCategory): void {
            Cache::forget(User::categoryExclusionCacheKey($excludedCategory->users_id));
        });

        static::deleted(static function (self $excludedCategory): void {
            Cache::forget(User::categoryExclusionCacheKey($excludedCategory->users_id));
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'users_id');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'categories_id');
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::$ffi = $override;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * Exclusion rows for one user: each category id is cast with `(int)`, like
     * `User::syncExcludedCategories()`, and kept once in first-occurrence order.
     *
     * @param  array<array-key, int|string|float|bool|null>  $categoryIds
     * @return list<array{users_id: int, categories_id: int}>
     */
    public static function normalizeExclusionPairs(int $userId, array $categoryIds): array
    {
        return self::usesRustFfi()
            ? self::normalizeExclusionPairsNative($userId, $categoryIds)
            : self::normalizeExclusionPairsPhp($userId, $categoryIds);
    }

    /**
     * @param  array<array-key, int|string|float|bool|null>  $categoryIds
     * @return list<array{users_id: int, categories_id: int}>
     */
    public static function normalizeExclusionPairsPhp(int $userId, array $categoryIds): array
    {
        $pairs = [];
        $seen = [];
        foreach ($categoryIds as $categoryId) {
            $categoryId = (int) $categoryId;
            if (isset($seen[$categoryId])) {
                continue;
            }
            $seen[$categoryId] = true;
            $pairs[] = ['users_id' => $userId, 'categories_id' => $categoryId];
        }

        return $pairs;
    }

    /**
     * @param  array<array-key, int|string|float|bool|null>  $categoryIds
     * @return list<array{users_id: int, categories_id: int}>
     */
    public static function normalizeExclusionPairsNative(int $userId, array $categoryIds): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::normalizeExclusionPairsPhp($userId, $categoryIds);
        }

        try {
            $count = \count($categoryIds);
            $ids = $ffi->new('int64_t['.max(1, $count).']');
            $i = 0;
            foreach ($categoryIds as $categoryId) {
                $ids[$i++] = (int) $categoryId;
            }
            // The export collects before writing, so the input buffer doubles as output.
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_user_excluded_category_pairs($ids, $count, $ids, $count);
            if (! \is_int($written) || $written < 0 || $written > $count) {
                return self::normalizeExclusionPairsPhp($userId, $categoryIds);
            }
            $pairs = [];
            for ($i = 0; $i < $written; $i++) {
                $pairs[] = ['users_id' => $userId, 'categories_id' => (int) $ids[$i]];
            }

            return $pairs;
        } catch (Throwable) {
            return self::normalizeExclusionPairsPhp($userId, $categoryIds);
        }
    }
}
