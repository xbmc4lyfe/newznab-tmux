<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ReleaseStat extends Model
{
    use HasFactory; // @phpstan-ignore missingType.generics

    protected $guarded = [];

    // Opt-in experiment: retain PHP unless every caller-inclusive performance gate qualifies.
    private static bool $useRustFfi = false;

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    /** @return list<int> */
    public static function excludedCategoryIds(): array
    {
        return self::$useRustFfi ? self::excludedCategoryIdsNative() : self::excludedCategoryIdsPhp();
    }

    /** @return list<int> */
    public static function excludedCategoryIdsPhp(): array
    {
        return [Category::OTHER_MISC, Category::OTHER_HASHED];
    }

    /** @return list<int> */
    public static function excludedCategoryIdsNative(): array
    {
        $result = DownloadStat::transformNative(3, []);

        return $result === [Category::OTHER_MISC, Category::OTHER_HASHED] ? $result : self::excludedCategoryIdsPhp();
    }

    public static function displayName(mixed $title, mixed $parentTitle = null, bool $hasParent = false): mixed
    {
        return self::$useRustFfi ? self::displayNameNative($title, $parentTitle, $hasParent) : self::displayNamePhp($title, $parentTitle, $hasParent);
    }

    public static function displayNamePhp(mixed $title, mixed $parentTitle = null, bool $hasParent = false): mixed
    {
        return $hasParent ? $parentTitle.' > '.$title : $title;
    }

    public static function displayNameNative(mixed $title, mixed $parentTitle = null, bool $hasParent = false): mixed
    {
        // PHP owns scalar-to-string formatting (including floats and locale).
        // A present parent with a null/empty title still contributes the prefix.
        if (($title !== null && ! is_scalar($title)) || ($parentTitle !== null && ! is_scalar($parentTitle))) {
            return self::displayNamePhp($title, $parentTitle, $hasParent);
        }
        $result = DownloadStat::transformNative(2, [$hasParent ? (string) $title : $title, $hasParent ? (string) $parentTitle : null, $hasParent]);

        return $result !== null && (! $hasParent || is_string($result)) ? $result : self::displayNamePhp($title, $parentTitle, $hasParent);
    }

    /** @return array{category: mixed, count: mixed} */
    public static function buildRow(mixed $category, mixed $count): array
    {
        return self::$useRustFfi ? self::buildRowNative($category, $count) : self::buildRowPhp($category, $count);
    }

    /** @return array{category: mixed, count: mixed} */
    public static function buildRowPhp(mixed $category, mixed $count): array
    {
        return ['category' => $category, 'count' => $count];
    }

    /** @return array{category: mixed, count: mixed} */
    public static function buildRowNative(mixed $category, mixed $count): array
    {
        $result = DownloadStat::transformNative(4, [$category, $count]);

        return is_array($result) && array_keys($result) === ['category', 'count']
            ? $result : self::buildRowPhp($category, $count);
    }

    public static function insertRecentlyAdded(): void
    {
        $categories = Category::query()
            ->with('parent')
            ->where('r.adddate', '>', now()->subWeek())
            ->select([
                'categories.id',
                'root_categories_id',
                DB::raw('COUNT(r.id) as count'),
                'categories.title',
            ])
            ->join('releases as r', 'r.categories_id', '=', 'categories.id')
            ->whereNotIn('categories.id', self::excludedCategoryIds()) // Exclude OTHER_MISC and OTHER_HASHED
            ->groupBy('categories.id', 'root_categories_id', 'categories.title')
            ->orderByDesc('count')
            ->get();

        foreach ($categories as $category) {
            // Build the category display name with root category prefix
            $categoryDisplay = self::displayName($category->title, $category->parent?->title, $category->parent !== null);
            $row = self::buildRow($categoryDisplay, $category->count);

            // Check if we already have the information and if we do just update the count
            if (self::query()->where('category', $categoryDisplay)->exists()) {
                self::query()->where('category', $categoryDisplay)->update(['count' => $row['count']]);

                continue;
            }
            self::query()->create($row);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function getRecentlyAdded(): array
    {
        return self::query()->select(['category', 'count'])->get()->toArray();
    }
}
