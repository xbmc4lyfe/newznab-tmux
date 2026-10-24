<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Models\Release;
use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

class AdminFailedReleasesController extends BasePageController
{
    public const string FFI_CDEF = <<<'CDEF'
        int32_t nntmux_cat_mac_index_state(uint8_t kind, int64_t int_value, const char *text_ptr, size_t text_len, int64_t per_page, int64_t *out, size_t out_cap);
        CDEF;

    /** Rust stays opt-in: the FFI round trip is slower than this PHP normalization (< 1.5x). */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * Display a listing of failed releases
     */
    public function index(): mixed
    {
        $meta_title = $title = 'Failed Releases List';
        $releaselist = Release::getFailedRange();

        return view('admin.releases.failed', compact('title', 'meta_title', 'releaselist'));
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
     * The listing's page state: Laravel's current-page rule (a `FILTER_VALIDATE_INT` page of
     * at least one, else `1`), a page size of at least one and the row offset, saturating at
     * `PHP_INT_MAX`.
     *
     * @return array{page: int, perPage: int, offset: int}
     */
    public static function normalizeFailedReleasesIndexState(int|string|null $page, int $perPage = 50): array
    {
        return self::usesRustFfi()
            ? self::normalizeFailedReleasesIndexStateNative($page, $perPage)
            : self::normalizeFailedReleasesIndexStatePhp($page, $perPage);
    }

    /**
     * @return array{page: int, perPage: int, offset: int}
     */
    public static function normalizeFailedReleasesIndexStatePhp(int|string|null $page, int $perPage = 50): array
    {
        $resolved = filter_var($page, FILTER_VALIDATE_INT) !== false && (int) $page >= 1 ? (int) $page : 1;
        $perPage = max(1, $perPage);
        $offset = $resolved - 1 > intdiv(PHP_INT_MAX, $perPage) ? PHP_INT_MAX : ($resolved - 1) * $perPage;

        return ['page' => $resolved, 'perPage' => $perPage, 'offset' => $offset];
    }

    /**
     * @return array{page: int, perPage: int, offset: int}
     */
    public static function normalizeFailedReleasesIndexStateNative(int|string|null $page, int $perPage = 50): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::normalizeFailedReleasesIndexStatePhp($page, $perPage);
        }

        try {
            $out = $ffi->new('int64_t[3]');
            $text = \is_string($page) ? $page : '';
            $kind = match (true) {
                $page === null => 0,
                \is_int($page) => 2,
                default => 4,
            };
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_cat_mac_index_state($kind, \is_int($page) ? $page : 0, $text, \strlen($text), $perPage, $out, 3);
            if ($rc !== 3) {
                return self::normalizeFailedReleasesIndexStatePhp($page, $perPage);
            }

            return ['page' => (int) $out[0], 'perPage' => (int) $out[1], 'offset' => (int) $out[2]];
        } catch (Throwable) {
            return self::normalizeFailedReleasesIndexStatePhp($page, $perPage);
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
