<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Models\ReleaseComment;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Throwable;

class AdminCommentsController extends BasePageController
{
    public const string FFI_CDEF = <<<'CDEF'
        int32_t nntmux_cat_mac_comment_target(uint8_t kind, int64_t int_value, const char *text_ptr, size_t text_len, int64_t *out_id);
        CDEF;

    /** Rust stays opt-in: the FFI round trip is slower than this PHP check (< 1.5x). */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * Display a listing of comments
     */
    public function index(): View
    {
        $this->setAdminPrefs();

        $meta_title = $title = 'Comments List';

        $commentsList = ReleaseComment::getCommentsRange();

        return view('admin.comments.index', compact('commentsList', 'title', 'meta_title'));
    }

    /**
     * Delete a comment
     */
    public function destroy(int $id): RedirectResponse
    {
        $target = self::normalizeCommentDeleteTarget($id);
        if ($target['delete']) {
            ReleaseComment::deleteComment($target['id']);
        }

        return redirect()->back()->with('success', 'Comment deleted successfully');
    }

    /**
     * Overrides the PHP default for this controller's helpers; `null` restores the default.
     */
    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    /**
     * Whether `$id` names a comment to delete: a non-zero int, or a non-zero
     * `FILTER_VALIDATE_INT` string; every other value deletes nothing.
     *
     * @return array{delete: bool, id: int}
     */
    public static function normalizeCommentDeleteTarget(mixed $id): array
    {
        return self::usesRustFfi()
            ? self::normalizeCommentDeleteTargetNative($id)
            : self::normalizeCommentDeleteTargetPhp($id);
    }

    /**
     * @return array{delete: bool, id: int}
     */
    public static function normalizeCommentDeleteTargetPhp(mixed $id): array
    {
        $candidate = match (true) {
            \is_int($id) => $id,
            \is_string($id) => filter_var($id, FILTER_VALIDATE_INT),
            default => false,
        };

        return $candidate === false || $candidate === 0
            ? ['delete' => false, 'id' => 0]
            : ['delete' => true, 'id' => $candidate];
    }

    /**
     * @return array{delete: bool, id: int}
     */
    public static function normalizeCommentDeleteTargetNative(mixed $id): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::normalizeCommentDeleteTargetPhp($id);
        }

        try {
            $out = $ffi->new('int64_t');
            $text = \is_string($id) ? $id : '';
            $kind = match (true) {
                $id === null => 0,
                \is_int($id) => 2,
                \is_string($id) => 4,
                default => 5,
            };
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_cat_mac_comment_target($kind, \is_int($id) ? $id : 0, $text, \strlen($text), FFI::addr($out));
            if ($rc !== 0 && $rc !== 1) {
                return self::normalizeCommentDeleteTargetPhp($id);
            }

            // @phpstan-ignore property.notFound
            return ['delete' => $rc === 1, 'id' => $rc === 1 ? (int) $out->cdata : 0];
        } catch (Throwable) {
            return self::normalizeCommentDeleteTargetPhp($id);
        }
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        try {
            $ffi = RustPipelineLibrary::resolveDomain('categorize', self::FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        } catch (Throwable) {
            $ffi = null;
        }
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
