<?php

declare(strict_types=1);

namespace App\Support\Forum;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use TeamTeaTime\Forum\Models\Category;
use TeamTeaTime\Forum\Support\Access\CategoryAccess;
use Throwable;

final class CategoryTreeBuilder
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_category_root_index(const int64_t *lefts, const uint8_t *present, size_t count);
        C;

    /**
     * The Rust helper stays opt-in: it missed the 1.5x paired benchmark gate (common 0.35x mean /
     * 0.38x p99, uncommon 0.72x / 0.74x;
     * tests/Unit/RustSecurityForumSupportParityTest.php).
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * @param  EloquentCollection<int, Category>  $categories
     * @return EloquentCollection<int, Category>
     */
    public function build(EloquentCollection $categories): EloquentCollection
    {
        if ($categories->isEmpty()) {
            return new EloquentCollection;
        }

        $rootParentId = $this->resolveRootParentId($categories);
        $categories->linkNodes();

        /** @var EloquentCollection<int, Category> $tree */
        $tree = $categories
            ->filter(static fn (Category $category): bool => $category->getParentId() === $rootParentId)
            ->values();

        return $this->removeParentRelationships($tree);
    }

    /**
     * @return EloquentCollection<int, Category>
     */
    public function buildAccessibleTo(mixed $user): EloquentCollection
    {
        return $this->build(CategoryAccess::getFilteredTreeFor($user));
    }

    /**
     * @return EloquentCollection<int, Category>
     */
    public function buildThreadDestinations(): EloquentCollection
    {
        return $this->build(Category::query()->threadDestinations()->get());
    }

    /**
     * @param  EloquentCollection<int, Category>  $categories
     */
    public function hideManageColumns(EloquentCollection $categories): void
    {
        $categories->each(function (Category $category): void {
            $category->makeHidden(['_lft', '_rgt', 'thread_count', 'post_count']);

            if ($category->relationLoaded('children') && $category->children->isNotEmpty()) {
                /** @var EloquentCollection<int, Category> $children */
                $children = $category->children;
                $this->hideManageColumns($children);
            }
        });
    }

    /**
     * @param  EloquentCollection<int, Category>  $categories
     */
    private function resolveRootParentId(EloquentCollection $categories): int|string|null
    {
        $nodes = [];
        foreach ($categories as $category) {
            $nodes[] = ['_lft' => $category->getLft(), 'parent_id' => $category->getParentId()];
        }

        return self::resolveRootParentIdFromNodes($nodes);
    }

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = false;
        self::$ffi = null;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
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
     * The parent ID of the node with the lowest `_lft`, in node order: a node replaces the
     * candidate while the candidate's `_lft` is null, or when its own `_lft` is lower.
     *
     * @param  array<int|string, array{_lft: mixed, parent_id: mixed}>  $nodes
     */
    public static function resolveRootParentIdFromNodes(array $nodes): int|string|null
    {
        return self::usesRustFfi()
            ? self::resolveRootParentIdFromNodesNative($nodes)
            : self::resolveRootParentIdFromNodesPhp($nodes);
    }

    /**
     * @param  array<int|string, array{_lft: mixed, parent_id: mixed}>  $nodes
     */
    public static function resolveRootParentIdFromNodesPhp(array $nodes): int|string|null
    {
        $rootParentId = null;
        $lowestLeft = null;

        foreach ($nodes as $node) {
            $left = $node['_lft'];

            if ($lowestLeft === null || ($left !== null && $left < $lowestLeft)) {
                $lowestLeft = $left;
                $rootParentId = $node['parent_id'];
            }
        }

        /** @var int|string|null $rootParentId */
        return $rootParentId;
    }

    /**
     * @param  array<int|string, array{_lft: mixed, parent_id: mixed}>  $nodes
     */
    public static function resolveRootParentIdFromNodesNative(array $nodes): int|string|null
    {
        $count = \count($nodes);
        $ffi = self::ffi();
        if ($ffi === null || $count === 0) {
            return self::resolveRootParentIdFromNodesPhp($nodes);
        }

        $list = array_values($nodes);

        try {
            $lefts = $ffi->new("int64_t[{$count}]");
            $present = $ffi->new("uint8_t[{$count}]");
            foreach ($list as $i => $node) {
                $left = $node['_lft'];
                if ($left === null) {
                    $present[$i] = 0;
                } elseif (\is_int($left)) {
                    $lefts[$i] = $left;
                    $present[$i] = 1;
                } else {
                    return self::resolveRootParentIdFromNodesPhp($nodes);
                }
            }

            // @phpstan-ignore method.notFound
            $index = $ffi->nntmux_category_root_index($lefts, $present, $count);
        } catch (Throwable) {
            return self::resolveRootParentIdFromNodesPhp($nodes);
        }

        if (! \is_int($index) || $index < 0 || $index >= $count) {
            return self::resolveRootParentIdFromNodesPhp($nodes);
        }

        /** @var int|string|null $parentId */
        $parentId = $list[$index]['parent_id'];

        return $parentId;
    }

    /**
     * @param  EloquentCollection<int, Category>  $categories
     * @return EloquentCollection<int, Category>
     */
    private function removeParentRelationships(EloquentCollection $categories): EloquentCollection
    {
        $categories->each(function (Category $category): void {
            $category->setRelation('parent', null);

            if (! $category->relationLoaded('children')) {
                return;
            }

            /** @var EloquentCollection<int, Category> $children */
            $children = $category->children;

            $category->setRelation('children', $this->removeParentRelationships($children));
        });

        return $categories;
    }
}
