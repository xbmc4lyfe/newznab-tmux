<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\UsenetGroup;
use Illuminate\Http\Request;

class BrowseGroupController extends BasePageController
{
    // Keep PHP as the production default until the Rust performance gate passes.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi ?? false;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    /** @return array{meta_title: string, meta_keywords: string, meta_description: string} */
    public static function browseMeta(string $search): array
    {
        return self::$useRustFfi ? self::browseMetaNative($search) : self::browseMetaPhp($search);
    }

    /** @return array{meta_title: string, meta_keywords: string, meta_description: string} */
    public static function browseMetaPhp(string $search): array
    {
        return self::legacyBrowseMeta($search);
    }

    /** @return array{meta_title: string, meta_keywords: string, meta_description: string} */
    private static function legacyBrowseMeta(mixed $search): array
    {
        return [
            'meta_title' => 'Browse Groups'.(! empty($search) ? ' - Search: '.$search : ''),
            'meta_keywords' => 'browse,groups,description,details',
            'meta_description' => ! empty($search) ? 'Browse groups search results for '.$search : 'Browse groups',
        ];
    }

    /** @return array{meta_title: string, meta_keywords: string, meta_description: string} */
    public static function browseMetaNative(string $search): array
    {
        $result = BrowseController::transformNative(1, [$search]);
        if (is_array($result) && array_keys($result) === ['meta_title', 'meta_keywords', 'meta_description'] && is_string($result['meta_title']) && is_string($result['meta_keywords']) && is_string($result['meta_description'])) {
            return $result;
        }

        return self::browseMetaPhp($search);
    }

    /**
     * @throws \Exception
     */
    public function show(Request $request): mixed
    {
        // Get the search term from the request
        $search = $request->get('search', '') ?? '';

        // Get groups based on search term
        $groupList = UsenetGroup::getGroupsRange($search, true);

        $meta = is_string($search) ? self::browseMeta($search) : self::legacyBrowseMeta($search);

        // Render the content view
        $content = view('browsegroup.index', [
            'results' => $groupList,
            'search' => $search,
            'site' => $this->siteConfiguration,
        ])->render();

        // Prepare view data for main layout
        $this->viewData = array_merge($this->viewData, [
            'content' => $content,
        ], $meta);

        return $this->pagerender();
    }
}
