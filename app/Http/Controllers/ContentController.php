<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Content;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class ContentController extends BasePageController
{
    public const string FFI_CDEF = <<<'CDEF'
    int32_t nntmux_content_controller_is_admin(int64_t role, int64_t admin, int64_t moderator);
    int32_t nntmux_content_controller_page_mode(int64_t content_id, const char *page, size_t page_len);
    int32_t nntmux_content_controller_page_meta(
        uint32_t mode,
        uint32_t present_mask,
        const char *title,
        size_t title_len,
        const char *keywords,
        size_t keywords_len,
        const char *description,
        size_t description_len,
        size_t *out_lens,
        char *out,
        size_t cap
    );
    CDEF;

    /** All content except the front page. */
    public const int PAGE_LISTING = 0;

    /** One content page by id, filtered by role. */
    public const int PAGE_BY_ID = 1;

    /** Front page content with index metadata. */
    public const int PAGE_FRONT = 2;

    private const string DEFAULT_META_TITLE = 'Contents page';

    private const string DEFAULT_META_KEYWORDS = 'contents';

    private const string DEFAULT_META_DESCRIPTION = 'This is the contents page.';

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustSearchContentWatchlistControllersParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * Display content page(s).
     *
     * @return JsonResponse|View
     *
     * @throws \Exception
     */
    public function show(Request $request)
    {
        $role = $this->contentRole();

        /* The role column in the content table values are:
         * 1 = logged in users
         * 2 = admins
         *
         * The user role values are:
         * 1 = user
         * 2 = admin
         * 3 = disabled
         * 4 = moderator
         *
         * Admins and mods should be the only ones to see admin content.
         */
        $isAdmin = self::isAdminRole($role);

        $contentId = $this->integerInput($request, 'id');
        $mode = self::pageMode($contentId, $this->scalarInput($request, 'page'));
        $index = null;

        if ($mode === self::PAGE_LISTING) {
            // Show all content except front page
            $content = $this->getAllButFront()->all();
        } elseif ($mode === self::PAGE_BY_ID) {
            // Show specific content by ID
            $contentItem = $this->getContentById($contentId, $role);
            $content = $contentItem ? [$contentItem] : [];
        } else {
            // Show front page content
            $content = $this->getFrontPageContent()->all();
            $index = $this->getIndexContent();
        }
        $isFront = $mode === self::PAGE_FRONT;
        ['meta_title' => $meta_title, 'meta_keywords' => $meta_keywords, 'meta_description' => $meta_description]
            = self::pageMeta($mode, $index?->title, $index?->metakeywords, $index?->metadescription);

        if (empty($content)) {
            return response()->json(['message' => 'There is nothing to see here, no content provided.'], 404);
        }

        $this->viewData = array_merge($this->viewData, [
            'content' => $content,
            'admin' => $isAdmin,
            'front' => $isFront,
            'meta_title' => $meta_title,
            'meta_keywords' => $meta_keywords,
            'meta_description' => $meta_description,
        ]);

        return view('content.index', $this->viewData);
    }

    private function contentRole(): int
    {
        if (! isset($this->userdata)) {
            return Content::ROLE_EVERYONE;
        }

        if ($this->userdata->hasRole('Admin')) {
            return UserRole::ADMIN->value;
        }

        if ($this->userdata->hasRole('Moderator')) {
            return UserRole::MODERATOR->value;
        }

        return Content::ROLE_LOGGED_IN;
    }

    /**
     * Get all active content ordered by type and ordinal.
     *
     * @return Collection<int, mixed>
     */
    protected function getActiveContent(): Collection
    {
        return Content::active()->ordered()->get();
    }

    /**
     * Get all content except the front page.
     *
     * @return Collection<int, mixed>
     */
    protected function getAllButFront(): Collection
    {
        return Content::query()
            ->where('id', '<>', 1)
            ->ordered()
            ->get();
    }

    /**
     * Get content by ID with role-based access control.
     */
    protected function getContentById(int $id, int $role): ?Content
    {
        return Content::query()
            ->where('id', $id)
            ->forRole($role)
            ->first();
    }

    /**
     * Get front page content.
     *
     * @return Collection<int, mixed>
     */
    protected function getFrontPageContent(): Collection
    {
        return Content::frontPage()->get();
    }

    /**
     * Get index content metadata.
     */
    protected function getIndexContent(): ?Content
    {
        return Content::active()
            ->ofType(Content::TYPE_INDEX)
            ->ordered()
            ->first();
    }

    /**
     * Overrides the base page toggle for this controller's helpers; `null` restores the PHP default.
     */
    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi ?? false;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    /**
     * Admins and moderators are the only roles that see admin content.
     */
    public static function isAdminRole(int $role): bool
    {
        return self::$useRustFfi ? self::isAdminRoleNative($role) : self::isAdminRolePhp($role);
    }

    public static function isAdminRolePhp(int $role): bool
    {
        return \in_array($role, [UserRole::ADMIN->value, UserRole::MODERATOR->value], true);
    }

    public static function isAdminRoleNative(int $role): bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::isAdminRolePhp($role);
        }

        try {
            $result = self::callFfi($ffi, 'nntmux_content_controller_is_admin', $role, UserRole::ADMIN->value, UserRole::MODERATOR->value);

            return match ($result) {
                1 => true,
                0 => false,
                default => self::isAdminRolePhp($role),
            };
        } catch (Throwable) {
            return self::isAdminRolePhp($role);
        }
    }

    /**
     * `PAGE_LISTING` for `page=content` without an id, `PAGE_BY_ID` for an id with any
     * page, otherwise `PAGE_FRONT`.
     */
    public static function pageMode(int $contentId, string $contentPage): int
    {
        return self::$useRustFfi ? self::pageModeNative($contentId, $contentPage) : self::pageModePhp($contentId, $contentPage);
    }

    public static function pageModePhp(int $contentId, string $contentPage): int
    {
        if ($contentId === 0 && $contentPage === 'content') {
            return self::PAGE_LISTING;
        }

        if ($contentId !== 0 && $contentPage !== '') {
            return self::PAGE_BY_ID;
        }

        return self::PAGE_FRONT;
    }

    public static function pageModeNative(int $contentId, string $contentPage): int
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::pageModePhp($contentId, $contentPage);
        }

        try {
            $mode = self::callFfi($ffi, 'nntmux_content_controller_page_mode', $contentId, $contentPage, \strlen($contentPage));

            return \in_array($mode, [self::PAGE_LISTING, self::PAGE_BY_ID, self::PAGE_FRONT], true)
                ? $mode
                : self::pageModePhp($contentId, $contentPage);
        } catch (Throwable) {
            return self::pageModePhp($contentId, $contentPage);
        }
    }

    /**
     * Page meta: defaults unless the front page index supplies a filled title or
     * non-null keywords/description.
     *
     * @return array{meta_title: string, meta_keywords: string, meta_description: string}
     */
    public static function pageMeta(int $mode, ?string $indexTitle, ?string $indexKeywords, ?string $indexDescription): array
    {
        return self::$useRustFfi
            ? self::pageMetaNative($mode, $indexTitle, $indexKeywords, $indexDescription)
            : self::pageMetaPhp($mode, $indexTitle, $indexKeywords, $indexDescription);
    }

    /**
     * @return array{meta_title: string, meta_keywords: string, meta_description: string}
     */
    public static function pageMetaPhp(int $mode, ?string $indexTitle, ?string $indexKeywords, ?string $indexDescription): array
    {
        if ($mode !== self::PAGE_FRONT) {
            return [
                'meta_title' => self::DEFAULT_META_TITLE,
                'meta_keywords' => self::DEFAULT_META_KEYWORDS,
                'meta_description' => self::DEFAULT_META_DESCRIPTION,
            ];
        }

        return [
            'meta_title' => filled($indexTitle) ? $indexTitle : self::DEFAULT_META_TITLE,
            'meta_keywords' => $indexKeywords ?? self::DEFAULT_META_KEYWORDS,
            'meta_description' => $indexDescription ?? self::DEFAULT_META_DESCRIPTION,
        ];
    }

    /**
     * @return array{meta_title: string, meta_keywords: string, meta_description: string}
     */
    public static function pageMetaNative(int $mode, ?string $indexTitle, ?string $indexKeywords, ?string $indexDescription): array
    {
        $ffi = self::ffi();
        if ($ffi === null || $mode < self::PAGE_LISTING || $mode > self::PAGE_FRONT) {
            return self::pageMetaPhp($mode, $indexTitle, $indexKeywords, $indexDescription);
        }

        try {
            $title = $indexTitle ?? '';
            $keywords = $indexKeywords ?? '';
            $description = $indexDescription ?? '';
            $mask = ($indexTitle !== null ? 1 : 0) | ($indexKeywords !== null ? 2 : 0) | ($indexDescription !== null ? 4 : 0);
            $lens = $ffi->new('size_t[3]');
            $capacity = 0;
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, max(64, \strlen($title) + \strlen($keywords) + \strlen($description) + 64), $capacity);
            if ($outBuf === null) {
                return self::pageMetaPhp($mode, $indexTitle, $indexKeywords, $indexDescription);
            }

            $written = self::callFfi(
                $ffi,
                'nntmux_content_controller_page_meta',
                $mode,
                $mask,
                $title,
                \strlen($title),
                $keywords,
                \strlen($keywords),
                $description,
                \strlen($description),
                $lens,
                $outBuf,
                $capacity,
            );
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return self::pageMetaPhp($mode, $indexTitle, $indexKeywords, $indexDescription);
            }

            $payload = $written === 0 ? '' : FFI::string($outBuf, $written);
            $meta = [];
            $offset = 0;
            foreach (['meta_title', 'meta_keywords', 'meta_description'] as $index => $key) {
                $len = (int) $lens[$index];
                $meta[$key] = (string) substr($payload, $offset, $len);
                $offset += $len;
            }
            if ($offset !== $written) {
                return self::pageMetaPhp($mode, $indexTitle, $indexKeywords, $indexDescription);
            }

            return $meta;
        } catch (Throwable) {
            return self::pageMetaPhp($mode, $indexTitle, $indexKeywords, $indexDescription);
        }
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
