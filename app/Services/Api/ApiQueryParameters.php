<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Models\Category;
use App\Models\UsenetGroup;
use App\Services\Configuration\ConfigurationProvider;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Http\Request;
use Throwable;

final class ApiQueryParameters
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_api_parse_categories(const char *input_ptr, size_t input_len, uint32_t is_array, int32_t webdl_mode, char *out_buf, size_t out_cap);
        ssize_t nntmux_api_normalize_sort(const char *input_ptr, size_t input_len, char *out_buf, size_t out_cap);
        int32_t nntmux_api_is_valid_sort(const char *input_ptr, size_t input_len);
        ssize_t nntmux_api_validate_sort(const char *input_ptr, size_t input_len, char *out_buf, size_t out_cap);
        int32_t nntmux_api_is_non_negative_int_str(const char *input_ptr, size_t input_len);
        ssize_t nntmux_api_combine_tv_airdate(const char *series_ptr, size_t series_len, const char *episode_ptr, size_t episode_len, char *out_buf, size_t out_cap);
        int64_t nntmux_page_resolve_page(const char *input_ptr, size_t input_len);
        int64_t nntmux_page_parse_integer(const char *input_ptr, size_t input_len, int64_t default_val);
        int32_t nntmux_page_classify_return_url(const char *from_ptr, size_t from_len, const char *req_scheme_ptr, size_t req_scheme_len, const char *req_host_ptr, size_t req_host_len, int64_t req_port);
        int32_t nntmux_series_classify_title_bucket(const char *title_ptr, size_t title_len);
        ssize_t nntmux_series_classify_titles_nul(const char *titles_ptr, size_t titles_len, size_t count, char *out_buf, size_t out_cap);
        int64_t nntmux_series_resolve_season(const char *input_ptr, size_t input_len);
        C;

    /**
     * The Wave 45.5 sort summary defaults to Rust: every common and uncommon mean and p99 ratio cleared
     * 1.5x (min 2.36/2.05/3.19/2.90x); see
     * tests/Unit/RustApiCapabilitiesCanonicalizerQueryCacheUsageW45ParityTest.php.
     */
    public const bool W455_RUST_FFI_DEFAULT = true;

    private const string SORT_PATTERN = '/^(cat|name|size|files|stats|posted)_(asc|desc)$/';

    private static FFI|false|null $ffi = null;

    private static ?CData $outBuf512 = null;

    private static ?bool $useRustFfi = null;

    private static bool $adoptCategoriesFfi = false;

    private static bool $adoptSortFfi = false;

    private static bool $adoptHasValidSortFfi = false;

    private static ?bool $useW455RustFfi = null;

    public static function setUseRustFfi(?bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::$adoptCategoriesFfi;
    }

    public static function ffi(): ?FFI
    {
        return self::$ffi !== null ? (self::$ffi === false ? null : self::$ffi) : self::resolveFfi();
    }

    /** @return array<int, string|int> */
    public function categories(Request $request): array
    {
        if (self::$useRustFfi ?? self::$adoptCategoriesFfi) {
            $supported = false;
            $native = $this->categoriesNative($request, $supported);
            if ($supported && $native !== null) {
                return $native;
            }
        }

        return $this->categoriesPhp($request);
    }

    /**
     * @param-out bool $supported
     *
     * @return array<int, string|int>|null
     */
    public function categoriesNative(Request $request, bool &$supported = false): ?array
    {
        $supported = false;
        if (self::$useRustFfi === false) {
            return null;
        }
        if (! $request->has('cat')) {
            $supported = true;

            return [-1];
        }

        $raw = $request->input('cat');
        $isArray = 0;
        if (\is_array($raw)) {
            $parts = [];
            foreach ($raw as $item) {
                $str = (string) $item;
                if (\str_contains($str, "\0")) {
                    return null;
                }
                $parts[] = $str;
            }
            $payload = \implode("\0", $parts);
            $isArray = 1;
        } elseif (\is_scalar($raw)) {
            $payload = (string) $raw;
        } else {
            $supported = true;

            return [-1];
        }

        $ffi = self::$ffi ?? self::resolveFfi();
        if ($ffi === null || $ffi === false) {
            return null;
        }

        $len = \strlen($payload);
        $cap = 512;
        $scratch = $len <= 256 ? self::$outBuf512 : RustPipelineLibrary::obtainScratch($ffi, $len + 32, $cap);
        if ($scratch === null) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_api_parse_categories($payload, $len, $isArray, -1, $scratch, $cap);
            if ($written === -3) {
                $webdlMode = (int) app(ConfigurationProvider::class)->ingestion()->categorizeWebDl === 0 ? 1 : 0;
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_api_parse_categories($payload, $len, $isArray, $webdlMode, $scratch, $cap);
            } else {
                $webdlMode = 0;
            }
            if (! \is_int($written)) {
                return null;
            }
            if ($written <= -4) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_api_parse_categories($payload, $len, $isArray, $webdlMode, $scratch, $cap);
                if (! \is_int($written)) {
                    return null;
                }
            }
            if ($written === -2) {
                $supported = true;

                return [-1];
            }
            if ($written < 0) {
                return null;
            }
            $supported = true;

            return $written === 0 ? [] : \explode("\n", FFI::string($scratch, $written));
        } catch (Throwable) {
            $supported = false;

            return null;
        }
    }

    /** @return array<int, string|int> */
    public function categoriesPhp(Request $request): array
    {
        if (! $request->has('cat')) {
            return [-1];
        }

        $raw = $request->input('cat');
        if (is_array($raw)) {
            $value = implode(',', array_values(array_filter(array_map(
                static fn (mixed $id): string => urldecode(trim((string) $id)),
                $raw
            ), static fn (string $id): bool => $id !== '')));
        } elseif (is_scalar($raw)) {
            $value = urldecode(trim((string) $raw));
        } else {
            return [-1];
        }

        if ($value === '') {
            return [-1];
        }

        if (str_contains($value, (string) Category::TV_HD)
            && ! str_contains($value, (string) Category::TV_WEBDL)
            && (int) app(ConfigurationProvider::class)->ingestion()->categorizeWebDl === 0) {
            $value .= ','.Category::TV_WEBDL;
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $id): bool => $id !== ''
        ));
    }

    public function group(Request $request): string|int|bool
    {
        if (! $request->has('group')) {
            return -1;
        }

        $group = UsenetGroup::isValidGroup($request->input('group'));

        return $group === false ? -1 : $group;
    }

    /**
     * Page size, clamped to 1..maxLimit(); defaultLimit() when the request omits it.
     */
    public function limit(Request $request): int
    {
        return $request->has('limit') && is_numeric($request->input('limit'))
            ? max(1, min($this->maxLimit(), (int) $request->input('limit')))
            : $this->defaultLimit();
    }

    /**
     * Largest page size served, advertised in capabilities as <limits max>.
     */
    public function maxLimit(): int
    {
        return max(1, (int) config('nntmux.api.max_limit', 500));
    }

    /**
     * Page size used when the request omits limit, advertised in capabilities as <limits default>.
     */
    public function defaultLimit(): int
    {
        return max(1, min($this->maxLimit(), (int) config('nntmux.api.default_limit', 250)));
    }

    public function offset(Request $request): int
    {
        return $request->has('offset') && is_numeric($request->input('offset'))
            ? max(0, (int) $request->input('offset'))
            : 0;
    }

    public function minimumSize(Request $request): int
    {
        return $request->has('minsize') && is_numeric($request->input('minsize'))
            ? max(0, (int) $request->input('minsize'))
            : 0;
    }

    public function maximumAge(Request $request): int
    {
        return (int) $request->input('maxage', -1);
    }

    public function sort(Request $request): string
    {
        if (self::$useRustFfi ?? self::$adoptSortFfi) {
            $supported = false;
            $native = $this->sortNative($request, $supported);
            if ($supported && $native !== null) {
                return $native;
            }
        }

        return $this->sortPhp($request);
    }

    /**
     * @param-out bool $supported
     */
    public function sortNative(Request $request, bool &$supported = false): ?string
    {
        $supported = false;
        if (self::$useRustFfi === false) {
            return null;
        }
        $ffi = self::$ffi ?? self::resolveFfi();
        if ($ffi === null || $ffi === false) {
            return null;
        }

        $raw = (string) $request->input('sort', 'posted_desc');
        $len = \strlen($raw);
        if ($len === 0) {
            $supported = true;

            return '';
        }

        $cap = 512;
        $scratch = $len <= 512 ? self::$outBuf512 : RustPipelineLibrary::obtainScratch($ffi, $len, $cap);
        if ($scratch === null) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_api_normalize_sort($raw, $len, $scratch, $cap);
            if (! \is_int($written) || $written < 0) {
                return null;
            }
            $supported = true;

            return $written === 0 ? '' : FFI::string($scratch, $written);
        } catch (Throwable) {
            $supported = false;

            return null;
        }
    }

    public function sortPhp(Request $request): string
    {
        return strtolower(trim((string) $request->input('sort', 'posted_desc')));
    }

    public function hasValidSort(Request $request): bool
    {
        if (self::$useRustFfi ?? self::$adoptHasValidSortFfi) {
            $supported = false;
            $native = $this->hasValidSortNative($request, $supported);
            if ($supported && $native !== null) {
                return $native;
            }
        }

        return $this->hasValidSortPhp($request);
    }

    /**
     * @param-out bool $supported
     */
    public function hasValidSortNative(Request $request, bool &$supported = false): ?bool
    {
        $supported = false;
        if (self::$useRustFfi === false) {
            return null;
        }
        if (! $request->has('sort')) {
            $supported = true;

            return true;
        }

        $ffi = self::$ffi ?? self::resolveFfi();
        if ($ffi === null || $ffi === false) {
            return null;
        }

        $rawStr = (string) $request->input('sort', 'posted_desc');
        try {
            // @phpstan-ignore method.notFound
            $res = $ffi->nntmux_api_is_valid_sort($rawStr, \strlen($rawStr));
            if (! \is_int($res) || $res < 0) {
                return null;
            }
            $supported = true;

            return $res === 1;
        } catch (Throwable) {
            $supported = false;

            return null;
        }
    }

    public function hasValidSortPhp(Request $request): bool
    {
        return ! $request->has('sort')
            || preg_match(self::SORT_PATTERN, $this->sortPhp($request)) === 1;
    }

    /** Pass null to restore {@see W455_RUST_FFI_DEFAULT}. */
    public static function useW455RustFfi(?bool $enable = true): void
    {
        self::$useW455RustFfi = $enable;
    }

    public static function usesW455RustFfi(): bool
    {
        return self::$useW455RustFfi ?? self::W455_RUST_FFI_DEFAULT;
    }

    public static function resetW455RustFfi(): void
    {
        self::$useW455RustFfi = null;
    }

    /**
     * Summarizes {@see sortPhp()} and {@see hasValidSortPhp()} over raw scalar `sort` inputs (cast to
     * string): `changed` and `empty` normalized values, `valid` sorts split by direction and column, and
     * `distinct_sorts`. Non-scalar values are invalid.
     *
     * @param  array<array-key, mixed>  $values
     * @return array{values: int, invalid: int, changed: int, empty: int, valid: int, asc: int, desc: int, cat: int, name: int, size: int, files: int, stats: int, posted: int, distinct_sorts: int}
     */
    public static function summarizeSortBatch(array $values): array
    {
        return self::usesW455RustFfi() ? self::summarizeSortBatchNative($values) : self::summarizeSortBatchPhp($values);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array{values: int, invalid: int, changed: int, empty: int, valid: int, asc: int, desc: int, cat: int, name: int, size: int, files: int, stats: int, posted: int, distinct_sorts: int}
     */
    public static function summarizeSortBatchPhp(array $values): array
    {
        $summary = ['values' => \count($values), 'invalid' => 0, 'changed' => 0, 'empty' => 0, 'valid' => 0, 'asc' => 0, 'desc' => 0, 'cat' => 0, 'name' => 0, 'size' => 0, 'files' => 0, 'stats' => 0, 'posted' => 0, 'distinct_sorts' => 0];
        $distinct = [];
        foreach ($values as $value) {
            if (! \is_scalar($value)) {
                $summary['invalid']++;

                continue;
            }
            $raw = (string) $value;
            $sort = strtolower(trim($raw));
            $summary['changed'] += (int) ($sort !== $raw);
            $summary['empty'] += (int) ($sort === '');
            if (preg_match(self::SORT_PATTERN, $sort, $match) === 1) {
                /** @var 'cat'|'name'|'size'|'files'|'stats'|'posted' $column */
                $column = $match[1];
                $summary['valid']++;
                $summary[$match[2] === 'asc' ? 'asc' : 'desc']++;
                $summary[$column]++;
            }
            $distinct[$sort] = true;
        }
        $summary['distinct_sorts'] = \count($distinct);

        return $summary;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array{values: int, invalid: int, changed: int, empty: int, valid: int, asc: int, desc: int, cat: int, name: int, size: int, files: int, stats: int, posted: int, distinct_sorts: int}
     */
    public static function summarizeSortBatchNative(array $values): array
    {
        $sources = ApiInputCanonicalizer::scalarStrings($values);
        $fields = ApiInputCanonicalizer::evaluateW455Native(3, $sources, 12);
        if ($fields === null) {
            return self::summarizeSortBatchPhp($values);
        }

        return ['values' => \count($values), 'invalid' => \count($values) - \count($sources)]
            + array_combine(['changed', 'empty', 'valid', 'asc', 'desc', 'cat', 'name', 'size', 'files', 'stats', 'posted', 'distinct_sorts'], $fields);
    }

    private static function resolveFfi(): ?FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi === false ? null : self::$ffi;
        }
        $ffi = RustPipelineLibrary::resolveDomain('categorize', self::FFI_CDEF);
        if ($ffi === null) {
            self::$ffi = false;

            return null;
        }
        try {
            self::$outBuf512 = $ffi->new('char[512]');
            self::$ffi = $ffi;

            return $ffi;
        } catch (Throwable) {
            self::$ffi = false;

            return null;
        }
    }
}
