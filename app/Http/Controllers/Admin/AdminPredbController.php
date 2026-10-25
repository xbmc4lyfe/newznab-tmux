<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Models\Predb;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class AdminPredbController extends BasePageController
{
    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustPredbCheckRebuildSuggestionsImportParityTest.php is below 1.5x.
     */
    public const bool RUST_FFI_DEFAULT = false;

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w373_admin_predb_browse(
            const char *search_ptr,
            size_t search_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    private const string TRIM_CHARS = " \t\n\r\0\x0B\x0C";

    private const string FIELD_SEPARATOR = "\x1F";

    private static ?bool $rustFfiOverride = null;

    private static FFI|false|null $rustFfiHandle = null;

    /**
     * Display a listing of PreDB entries
     */
    public function index(Request $request): View
    {
        $this->setAdminPrefs();

        $lastSearch = $this->scalarInput($request, 'presearch');

        if ($lastSearch) {
            $results = Predb::getAll($lastSearch);
        } else {
            $results = Predb::getAll();
        }

        $title = 'Browse PreDb';
        $meta_title = 'View PreDb info';
        $meta_keywords = 'view,predb,info,description,details';
        $meta_description = 'View PreDb info';

        return view('admin.predb.index', compact(
            'results',
            'lastSearch',
            'title',
            'meta_title',
            'meta_keywords',
            'meta_description'
        ));
    }

    public static function useRustFfi(?bool $enabled): void
    {
        self::$rustFfiOverride = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$rustFfiOverride ?? self::RUST_FFI_DEFAULT;
    }

    public static function resetRustFfi(): void
    {
        self::$rustFfiOverride = null;
        self::$rustFfiHandle = null;
    }

    /**
     * Describes how index() treats a `presearch` value: falsy strings ('' and '0') browse
     * everything, any other value is passed to Predb::getAll() unchanged (its cache key).
     *
     * @return array{search: string, filtered: bool, bytes: int, trimmed_bytes: int, blank: bool, valid_utf8: bool, terms: int}
     */
    public static function planPredbBrowse(string $presearch): array
    {
        return (self::usesRustFfi() ? self::planPredbBrowseNative($presearch) : null) ?? self::planPredbBrowsePhp($presearch);
    }

    /**
     * @return array{search: string, filtered: bool, bytes: int, trimmed_bytes: int, blank: bool, valid_utf8: bool, terms: int}|null
     */
    private static function planPredbBrowseNative(string $presearch): ?array
    {
        $fields = self::callNative('nntmux_w373_admin_predb_browse', [$presearch, strlen($presearch)], 64 + strlen($presearch), 7);
        if ($fields === null || count($fields) !== 7 || ! ctype_digit($fields[1]) || ! ctype_digit($fields[2]) || ! ctype_digit($fields[5])) {
            return null;
        }

        return [
            'search' => $fields[6],
            'filtered' => $fields[0] === '1',
            'bytes' => (int) $fields[1],
            'trimmed_bytes' => (int) $fields[2],
            'blank' => $fields[3] === '1',
            'valid_utf8' => $fields[4] === '1',
            'terms' => (int) $fields[5],
        ];
    }

    /**
     * @return array{search: string, filtered: bool, bytes: int, trimmed_bytes: int, blank: bool, valid_utf8: bool, terms: int}
     */
    private static function planPredbBrowsePhp(string $presearch): array
    {
        $filtered = $presearch !== '' && $presearch !== '0';
        $trimmed = trim($presearch, self::TRIM_CHARS);
        $terms = preg_split('/[ \t\n\r\0\x0B\x0C]+/', $presearch, -1, PREG_SPLIT_NO_EMPTY);

        return [
            'search' => $filtered ? $presearch : '',
            'filtered' => $filtered,
            'bytes' => strlen($presearch),
            'trimmed_bytes' => strlen($trimmed),
            'blank' => $filtered && $trimmed === '',
            'valid_utf8' => mb_check_encoding($presearch, 'UTF-8'),
            'terms' => $terms === false ? 0 : count($terms),
        ];
    }

    /**
     * @param  list<int|string>  $args  arguments preceding the caller-owned output buffer
     * @return list<string>|null
     */
    private static function callNative(string $symbol, array $args, int $minBytes, int $limit = PHP_INT_MAX): ?array
    {
        $ffi = self::rustFfi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $minBytes, $capacity);
            if ($buffer === null) {
                return null;
            }
            $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return explode(self::FIELD_SEPARATOR, FFI::string($buffer, $written), $limit);
        } catch (Throwable) {
            return null;
        }
    }

    private static function rustFfi(): ?FFI
    {
        if (self::$rustFfiHandle === null) {
            self::$rustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w373', self::FFI_CDEF) ?? false;
        }

        return self::$rustFfiHandle === false ? null : self::$rustFfiHandle;
    }
}
