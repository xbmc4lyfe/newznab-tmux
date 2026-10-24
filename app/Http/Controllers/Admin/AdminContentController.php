<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Http\Requests\Admin\AdminContentRequest;
use App\Models\Content;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

class AdminContentController extends BasePageController
{
    public const string FFI_CDEF = <<<'CDEF'
    ssize_t nntmux_admin_ctl_content_normalize_url(
        const char *url_ptr,
        size_t url_len,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_admin_ctl_content_reorder(
        const int64_t *ordered_ptr,
        size_t ordered_len,
        const int64_t *expected_ptr,
        size_t expected_len,
        int64_t *out_ordinals,
        size_t out_cap
    );
    int64_t nntmux_admin_ctl_content_toggle_status(int64_t status, int64_t enabled, int64_t disabled);
    CDEF;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustAdminCategoryReleaseContentControllersParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * Display list of all content.
     *
     * @throws \Exception
     */
    public function index(): mixed
    {
        $this->setAdminPrefs();

        $contentList = Content::query()->ordered()->get();
        $contentGroups = $contentList
            ->groupBy('contenttype')
            ->sortKeys()
            ->map(function ($items, $contentType) {
                /** @var Collection<int, Content> $items */
                return [
                    'contenttype' => (int) $contentType,
                    'label' => $items->first()?->getContentTypeLabel() ?? 'Unknown',
                    'items' => $items,
                ];
            })
            ->values();

        $this->viewData = array_merge($this->viewData, [
            'contentlist' => $contentList,
            'contentGroups' => $contentGroups,
            'meta_title' => 'Content List',
            'title' => 'Content List',
        ]);

        return view('admin.content.index', $this->viewData);
    }

    /**
     * Show form to create or edit content.
     *
     * @return \Illuminate\Contracts\Foundation\Application|Application|RedirectResponse|Redirector|View
     *
     * @throws \Exception
     */
    public function create(AdminContentRequest $request)
    {
        $this->setAdminPrefs();
        $meta_title = 'Content Add';

        // Set the current action.
        $action = $this->formAction($request, ['view', 'add']);

        $content = [
            'id' => '',
            'title' => '',
            'url' => '',
            'body' => '',
            'metadescription' => '',
            'metakeywords' => '',
            'contenttype' => '',
            'status' => '',
            'ordinal' => '',
            'created_at' => '',
            'role' => '',
        ];

        switch ($action) {
            case 'add':
                $meta_title = 'Content Add';
                $content['status'] = Content::STATUS_ENABLED;
                $content['contenttype'] = Content::TYPE_USEFUL;
                break;

            case 'submit':
                $validated = $request->validated();

                if ($request->missing('id') || empty($request->input('id'))) {
                    $returnid = $this->addContent($validated);
                    $message = 'Content created successfully';
                } else {
                    $this->updateContent($validated);
                    $returnid = (int) $request->input('id');
                    $message = 'Content updated successfully';
                }

                return redirect()
                    ->route('admin.content-add', ['id' => $returnid])
                    ->with('success', $message);

            case 'view':
            default:
                if ($request->has('id')) {
                    $meta_title = 'Content Edit';
                    $id = (int) $request->input('id');
                    $content = $this->getContentById($id);
                }
                break;
        }

        $contenttypelist = [
            Content::TYPE_USEFUL => 'Useful Link',
            Content::TYPE_INDEX => 'Homepage',
        ];

        $rolelist = [
            Content::ROLE_EVERYONE => 'Everyone',
            Content::ROLE_LOGGED_IN => 'Logged in Users',
            Content::ROLE_ADMIN => 'Admins',
        ];

        $this->viewData = array_merge($this->viewData, [
            'status_ids' => [Content::STATUS_ENABLED, Content::STATUS_DISABLED],
            'status_names' => ['Enabled', 'Disabled'],
            'yesno_ids' => [1, 0],
            'yesno_names' => ['Yes', 'No'],
            'contenttypelist' => $contenttypelist,
            'content' => $content,
            'rolelist' => $rolelist,
            'meta_title' => $meta_title,
            'title' => $meta_title,
        ]);

        return view('admin.content.add', $this->viewData);
    }

    /**
     * Toggle content status (enable/disable).
     */
    public function toggleStatus(Request $request): mixed
    {
        if ($request->has('id')) {
            $content = Content::query()->find($request->input('id'));

            if ($content) {
                $newStatus = self::toggledStatus($content->status);

                $content->update(['status' => $newStatus, 'updated_at' => now()]);

                return response()->json([
                    'success' => true,
                    'status' => $newStatus,
                    'message' => $newStatus === Content::STATUS_ENABLED ? 'Content enabled' : 'Content disabled',
                ]);
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'Content not found',
        ], 404);
    }

    /**
     * Persist a new global content order.
     */
    public function reorder(Request $request): mixed
    {
        $validated = $request->validate([
            'contenttype' => ['required', 'integer'],
            'ordered_ids' => ['required', 'array', 'min:1'],
            'ordered_ids.*' => ['integer', 'distinct', 'exists:content,id'],
        ]);

        $contentType = (int) $validated['contenttype'];
        $orderedIds = array_map('intval', $validated['ordered_ids']);
        $expectedIds = Content::query()
            ->where('contenttype', $contentType)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $ordinals = self::reorderOrdinals($orderedIds, $expectedIds);
        if ($ordinals === null) {
            return response()->json([
                'success' => false,
                'message' => 'Reorder payload must include every content row for the selected group.',
            ], 422);
        }

        $timestamp = now();

        DB::transaction(function () use ($contentType, $ordinals, $timestamp): void {
            foreach ($ordinals as $contentId => $ordinal) {
                Content::query()
                    ->whereKey($contentId)
                    ->where('contenttype', $contentType)
                    ->update([
                        'ordinal' => $ordinal,
                        'updated_at' => $timestamp,
                    ]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Content order updated successfully',
            'contenttype' => $contentType,
            'ordinals' => $ordinals,
        ]);
    }

    /**
     * Delete content by ID.
     */
    public function destroy(Request $request): mixed
    {
        if ($request->has('id')) {
            $content = Content::query()->find($request->input('id'));

            if ($content) {
                $content->delete();

                // If AJAX request, return JSON
                if ($request->wantsJson() || $request->ajax()) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Content deleted successfully',
                    ]);
                }

                return redirect()->route('admin.content-list')->with('success', 'Content deleted successfully');
            }

            // If AJAX request, return error JSON
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Content not found',
                ], 404);
            }
        }

        $referrer = $request->server('HTTP_REFERER');

        return redirect()->to($referrer)->with('error', 'Invalid request');
    }

    /**
     * Add new content.
     *
     * @param  array<string, mixed>  $data
     */
    protected function addContent(array $data): int
    {
        // Normalize URL
        $data = $this->normalizeContentUrl($data);

        return Content::query()->insertGetId([
            'role' => $data['role'] ?? Content::ROLE_EVERYONE,
            'title' => $data['title'] ?? '',
            'url' => $data['url'] ?? '/',
            'body' => $data['body'] ?? '',
            'metadescription' => $data['metadescription'] ?? '',
            'metakeywords' => $data['metakeywords'] ?? '',
            'contenttype' => $data['contenttype'] ?? Content::TYPE_USEFUL,
            'status' => $data['status'] ?? Content::STATUS_ENABLED,
            'ordinal' => $this->nextBottomOrdinal((int) ($data['contenttype'] ?? Content::TYPE_USEFUL)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Update existing content.
     *
     * @param  array<string, mixed>  $data
     */
    protected function updateContent(array $data): int
    {
        // Normalize URL
        $data = $this->normalizeContentUrl($data);

        $content = Content::query()->findOrFail($data['id']);
        $updatedContentType = (int) ($data['contenttype'] ?? $content->contenttype);
        $ordinal = $content->contenttype === $updatedContentType
            ? $content->ordinal
            : $this->nextBottomOrdinal($updatedContentType);

        return Content::query()
            ->whereKey($content->id)
            ->update([
                'role' => $data['role'] ?? Content::ROLE_EVERYONE,
                'title' => $data['title'] ?? '',
                'url' => $data['url'] ?? '/',
                'body' => $data['body'] ?? '',
                'metadescription' => $data['metadescription'] ?? '',
                'metakeywords' => $data['metakeywords'] ?? '',
                'contenttype' => $updatedContentType,
                'status' => $data['status'] ?? Content::STATUS_ENABLED,
                'ordinal' => $ordinal,
                'updated_at' => now(),
            ]);
    }

    /**
     * Get content by ID for admin viewing.
     */
    protected function getContentById(int $id): ?Content
    {
        return Content::query()->find($id);
    }

    /**
     * Normalize content URL to ensure proper formatting.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalizeContentUrl(array $data): array
    {
        return self::normalizeContentUrlData($data);
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
     * Internal URLs gain a leading and trailing `/`; external (`http://`, `https://` or domain) URLs are unchanged.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeContentUrlData(array $data): array
    {
        return self::$useRustFfi ? self::normalizeContentUrlDataNative($data) : self::normalizeContentUrlDataPhp($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeContentUrlDataPhp(array $data): array
    {
        if (isset($data['url']) && $data['url'] !== '') {
            $url = $data['url'];

            // Check if URL is external (has protocol or is a domain pattern)
            $hasProtocol = str_starts_with($url, 'http://') || str_starts_with($url, 'https://');
            $isDomain = ! str_starts_with($url, '/') && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9-]*\.[a-zA-Z]{2,}/', $url);

            // Only normalize internal URLs (those starting with / or root)
            if (! $hasProtocol && ! $isDomain) {
                // Ensure internal URL starts with /
                if ($url !== '/' && ! str_starts_with($url, '/')) {
                    $data['url'] = '/'.$url;
                }

                // Ensure internal URL ends with /
                if (! str_ends_with($data['url'], '/')) {
                    $data['url'] .= '/';
                }
            }
            // External URLs are left as-is
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeContentUrlDataNative(array $data): array
    {
        $url = $data['url'] ?? null;
        $ffi = self::ffi();
        if ($ffi === null || ! \is_string($url) || $url === '') {
            return self::normalizeContentUrlDataPhp($data);
        }

        $normalized = self::callForString($ffi, 'nntmux_admin_ctl_content_normalize_url', \strlen($url) + 2, $url, \strlen($url));
        if ($normalized === null) {
            return self::normalizeContentUrlDataPhp($data);
        }
        $data['url'] = $normalized;

        return $data;
    }

    /**
     * 1-based ordinals keyed by content id, or `null` unless the ordered ids are exactly the expected ids.
     *
     * @param  array<int, int>  $orderedIds
     * @param  array<int, int>  $expectedIds
     * @return array<int, int>|null
     */
    public static function reorderOrdinals(array $orderedIds, array $expectedIds): ?array
    {
        return self::$useRustFfi ? self::reorderOrdinalsNative($orderedIds, $expectedIds) : self::reorderOrdinalsPhp($orderedIds, $expectedIds);
    }

    /**
     * @param  array<int, int>  $orderedIds
     * @param  array<int, int>  $expectedIds
     * @return array<int, int>|null
     */
    public static function reorderOrdinalsPhp(array $orderedIds, array $expectedIds): ?array
    {
        if (
            count($orderedIds) !== count($expectedIds)
            || array_diff($orderedIds, $expectedIds) !== []
            || array_diff($expectedIds, $orderedIds) !== []
        ) {
            return null;
        }

        $ordinals = [];
        foreach ($orderedIds as $index => $contentId) {
            $ordinals[$contentId] = $index + 1;
        }

        return $ordinals;
    }

    /**
     * @param  array<int, int>  $orderedIds
     * @param  array<int, int>  $expectedIds
     * @return array<int, int>|null
     */
    public static function reorderOrdinalsNative(array $orderedIds, array $expectedIds): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null || PHP_INT_SIZE !== 8 || ! array_is_list($orderedIds) || ! array_is_list($expectedIds)) {
            return self::reorderOrdinalsPhp($orderedIds, $expectedIds);
        }

        try {
            $orderedCount = count($orderedIds);
            $expectedCount = count($expectedIds);
            $ordered = $ffi->new('int64_t['.max(1, $orderedCount).']');
            $expected = $ffi->new('int64_t['.max(1, $expectedCount).']');
            $out = $ffi->new('int64_t['.max(1, $orderedCount).']');
            foreach ($orderedIds as $index => $id) {
                if (! \is_int($id)) {
                    return self::reorderOrdinalsPhp($orderedIds, $expectedIds);
                }
                $ordered[$index] = $id;
            }
            foreach ($expectedIds as $index => $id) {
                if (! \is_int($id)) {
                    return self::reorderOrdinalsPhp($orderedIds, $expectedIds);
                }
                $expected[$index] = $id;
            }

            $result = self::callFfi($ffi, 'nntmux_admin_ctl_content_reorder', $ordered, $orderedCount, $expected, $expectedCount, $out, max(1, $orderedCount));
            if ($result === 0) {
                return null;
            }
            if ($result !== 1) {
                return self::reorderOrdinalsPhp($orderedIds, $expectedIds);
            }

            $ordinals = [];
            foreach ($orderedIds as $index => $contentId) {
                $ordinals[$contentId] = (int) $out[$index];
            }

            return $ordinals;
        } catch (Throwable) {
            return self::reorderOrdinalsPhp($orderedIds, $expectedIds);
        }
    }

    /**
     * Enabled content becomes disabled; any other status becomes enabled.
     */
    public static function toggledStatus(mixed $status): int
    {
        return self::$useRustFfi ? self::toggledStatusNative($status) : self::toggledStatusPhp($status);
    }

    public static function toggledStatusPhp(mixed $status): int
    {
        return $status === Content::STATUS_ENABLED
            ? Content::STATUS_DISABLED
            : Content::STATUS_ENABLED;
    }

    public static function toggledStatusNative(mixed $status): int
    {
        $ffi = self::ffi();
        if ($ffi === null || PHP_INT_SIZE !== 8 || ! \is_int($status)) {
            return self::toggledStatusPhp($status);
        }

        try {
            $result = self::callFfi($ffi, 'nntmux_admin_ctl_content_toggle_status', $status, Content::STATUS_ENABLED, Content::STATUS_DISABLED);

            return $result === Content::STATUS_ENABLED || $result === Content::STATUS_DISABLED ? $result : self::toggledStatusPhp($status);
        } catch (Throwable) {
            return self::toggledStatusPhp($status);
        }
    }

    /**
     * Get the ordinal that places new content at the bottom of the list.
     */
    protected function nextBottomOrdinal(int $contentType): int
    {
        $maximumOrdinal = Content::query()
            ->where('contenttype', $contentType)
            ->max('ordinal');

        if ($maximumOrdinal === null) {
            return 1;
        }

        return (int) $maximumOrdinal + 1;
    }

    /**
     * Calls a byte-output export with a scratch buffer, growing it once when Rust reports a larger requirement.
     */
    private static function callForString(FFI $ffi, string $symbol, int $estimate, mixed ...$args): ?string
    {
        try {
            $capacity = 0;
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, max(1, $estimate), $capacity);
            if ($outBuf === null) {
                return null;
            }
            $written = self::callFfi($ffi, $symbol, ...[...$args, $outBuf, $capacity]);
            if (\is_int($written) && $written < -1 && -$written > $capacity) {
                $outBuf = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($outBuf === null) {
                    return null;
                }
                $written = self::callFfi($ffi, $symbol, ...[...$args, $outBuf, $capacity]);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return $written === 0 ? '' : FFI::string($outBuf, $written);
        } catch (Throwable) {
            return null;
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

        $ffi = RustPipelineLibrary::resolveDomain('w28-admin-category-release-content-controllers', self::FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
