<?php

declare(strict_types=1);

namespace App\View\Components;

use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class SortDropdown extends Component
{
    /**
     * Rust candidate for `resolveSortState()`. It stays opt-in: it did not beat PHP
     * by 1.5x in the paired benchmark
     * (tests/Unit/RustSearchPageProxySortDropdownParityTest.php).
     */
    public const string FFI_CDEF = <<<'C'
        intptr_t nntmux_sort_dropdown_resolve(const char *sort, size_t sort_len, int32_t sort_is_string, const char *keys, size_t keys_len, const int64_t *key_lens, size_t option_count, const char *base, size_t base_len, const char *params, size_t params_len, const int32_t *param_tags, const int64_t *param_lens, size_t param_count, int32_t *param_keep, char *out, size_t cap, int64_t *url_lens, int64_t *current);
        C;

    /**
     * Default sort options for releases.
     *
     * @var array<string, array{label: string, icon: string}>
     */
    public const array DEFAULT_SORT_OPTIONS = [
        'posted_desc' => ['label' => 'Posted (Newest)', 'icon' => 'fa-calendar-alt'],
        'posted_asc' => ['label' => 'Posted (Oldest)', 'icon' => 'fa-calendar-alt'],
        'added_desc' => ['label' => 'Added (Newest)', 'icon' => 'fa-clock'],
        'added_asc' => ['label' => 'Added (Oldest)', 'icon' => 'fa-clock'],
        'name_asc' => ['label' => 'Name (A-Z)', 'icon' => 'fa-font'],
        'name_desc' => ['label' => 'Name (Z-A)', 'icon' => 'fa-font'],
        'cat_asc' => ['label' => 'Category (Ascending)', 'icon' => 'fa-tags'],
        'cat_desc' => ['label' => 'Category (Descending)', 'icon' => 'fa-tags'],
        'size_desc' => ['label' => 'Size (Largest)', 'icon' => 'fa-hdd'],
        'size_asc' => ['label' => 'Size (Smallest)', 'icon' => 'fa-hdd'],
        'files_desc' => ['label' => 'Files (Most)', 'icon' => 'fa-file'],
        'files_asc' => ['label' => 'Files (Least)', 'icon' => 'fa-file'],
        'stats_desc' => ['label' => 'Grabs (Most)', 'icon' => 'fa-download'],
        'stats_asc' => ['label' => 'Grabs (Least)', 'icon' => 'fa-download'],
        'comments_desc' => ['label' => 'Comments (Most)', 'icon' => 'fa-comment'],
        'comments_asc' => ['label' => 'Comments (Least)', 'icon' => 'fa-comment'],
    ];

    public static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * The current sort value.
     */
    public string $currentSort;

    /**
     * Available sort options.
     *
     * @var array<string, array{label: string, icon: string}>
     */
    public array $sortOptions;

    /**
     * The base URL for sorting links.
     */
    public string $baseUrl;

    /**
     * Query parameters to preserve.
     *
     * @var array<string, mixed>
     */
    public array $queryParams;

    /**
     * Current sort label.
     */
    public string $currentLabel;

    /**
     * Current sort icon.
     */
    public string $currentIcon;

    /**
     * Pre-computed sort URLs.
     *
     * @var array<string, string>
     */
    public array $sortUrls;

    /**
     * Create a new component instance.
     *
     * @param  array<string, array{label: string, icon: string}>|null  $options
     * @param  array<string, mixed>|null  $queryParams
     */
    public function __construct(
        ?string $currentSort = null,
        ?array $options = null,
        ?string $baseUrl = null,
        ?array $queryParams = null
    ) {
        $requestedSort = $currentSort ?? request('ob', 'posted_desc');
        $this->baseUrl = $baseUrl ?? request()->url();

        $state = self::resolveSortState(
            $requestedSort,
            $options ?? self::DEFAULT_SORT_OPTIONS,
            $this->baseUrl,
            $queryParams ?? request()->query(),
        );
        $this->currentSort = $state['currentSort'];
        $this->sortOptions = $state['sortOptions'];
        $this->queryParams = $state['queryParams'];
        $this->currentLabel = $state['currentLabel'];
        $this->currentIcon = $state['currentIcon'];
        $this->sortUrls = $state['sortUrls'];
    }

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function searchFfi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * Resolve the selected sort, its label/icon, the preserved query parameters and
     * every option's sort URL.
     *
     * @param  array<array-key, mixed>  $sortOptions
     * @param  array<array-key, mixed>  $queryParams
     * @return array{currentSort: string, sortOptions: array<string, array{label: string, icon: string}>, queryParams: array<string, mixed>, currentLabel: string, currentIcon: string, sortUrls: array<string, string>}
     */
    public static function resolveSortState(mixed $requestedSort, array $sortOptions, string $baseUrl, array $queryParams): array
    {
        return self::$useRustFfi
            ? self::resolveSortStateNative($requestedSort, $sortOptions, $baseUrl, $queryParams)
            : self::resolveSortStatePhp($requestedSort, $sortOptions, $baseUrl, $queryParams);
    }

    /**
     * @param  array<array-key, mixed>  $sortOptions
     * @param  array<array-key, mixed>  $queryParams
     * @return array{currentSort: string, sortOptions: array<string, array{label: string, icon: string}>, queryParams: array<string, mixed>, currentLabel: string, currentIcon: string, sortUrls: array<string, string>}
     */
    public static function resolveSortStatePhp(mixed $requestedSort, array $sortOptions, string $baseUrl, array $queryParams): array
    {
        $currentSort = is_string($requestedSort) ? $requestedSort : 'posted_desc';
        $queryParams = array_filter($queryParams, is_scalar(...));
        unset($queryParams['ob'], $queryParams['page']);

        if (! array_key_exists($currentSort, $sortOptions)) {
            $currentSort = array_key_exists('posted_desc', $sortOptions) ? 'posted_desc' : (string) array_key_first($sortOptions);
        }

        // Pre-compute all sort URLs
        $sortUrls = [];
        foreach ($sortOptions as $sortKey => $sortData) {
            $params = array_merge($queryParams, ['ob' => $sortKey]);
            $sortUrls[$sortKey] = $baseUrl.'?'.http_build_query($params);
        }

        return [
            'currentSort' => $currentSort,
            'sortOptions' => $sortOptions,
            'queryParams' => $queryParams,
            // Pre-compute current label and icon
            'currentLabel' => $sortOptions[$currentSort]['label'] ?? 'Posted (Newest)',
            'currentIcon' => $sortOptions[$currentSort]['icon'] ?? 'fa-calendar-alt',
            'sortUrls' => $sortUrls,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $sortOptions
     * @param  array<array-key, mixed>  $queryParams
     * @return array{currentSort: string, sortOptions: array<string, array{label: string, icon: string}>, queryParams: array<string, mixed>, currentLabel: string, currentIcon: string, sortUrls: array<string, string>}
     */
    public static function resolveSortStateNative(mixed $requestedSort, array $sortOptions, string $baseUrl, array $queryParams): array
    {
        $ffi = self::searchFfi();
        $optionCount = \count($sortOptions);
        $paramCount = \count($queryParams);
        // http_build_query() honours arg_separator.output and formats floats with
        // `precision`; the Rust path only reproduces the default separator.
        if ($ffi === null || \ini_get('arg_separator.output') !== '&') {
            return self::resolveSortStatePhp($requestedSort, $sortOptions, $baseUrl, $queryParams);
        }

        $keys = '';
        $keyLens = $ffi->new('int64_t['.max(1, $optionCount).']');
        $i = 0;
        foreach ($sortOptions as $sortKey => $sortData) {
            $key = (string) $sortKey;
            $keys .= $key;
            $keyLens[$i++] = \strlen($key);
        }

        $params = '';
        $tags = $ffi->new('int32_t['.max(1, $paramCount).']');
        $paramLens = $ffi->new('int64_t['.max(2, 2 * $paramCount).']');
        $paramKeys = [];
        $i = 0;
        foreach ($queryParams as $paramKey => $value) {
            $paramKeys[] = $paramKey;
            $tag = (\is_int($paramKey) ? 2 : 0) | (\is_scalar($value) ? 1 : 0) | (\is_float($value) ? 4 : 0);
            $key = (string) $paramKey;
            $encoded = match (true) {
                \is_string($value) => $value,
                \is_int($value) => (string) $value,
                \is_bool($value) => $value ? '1' : '0',
                default => '',
            };
            $params .= $key.$encoded;
            $tags[$i] = $tag;
            $paramLens[2 * $i] = \strlen($key);
            $paramLens[2 * $i + 1] = \strlen($encoded);
            $i++;
        }

        // Each URL is at most the base, `?`, every parameter percent-encoded with a
        // renumbered integer key and separators, and `ob=` plus the encoded key.
        $minBytes = $optionCount * (\strlen($baseUrl) + 3 * \strlen($params) + 24 * $paramCount + 8) + 3 * \strlen($keys) + 1;
        $capacity = 0;
        $out = RustPipelineLibrary::obtainScratch($ffi, $minBytes, $capacity);
        if ($out === null) {
            return self::resolveSortStatePhp($requestedSort, $sortOptions, $baseUrl, $queryParams);
        }
        $keep = $ffi->new('int32_t['.max(1, $paramCount).']');
        $urlLens = $ffi->new('int64_t['.max(1, $optionCount).']');
        $current = $ffi->new('int64_t[1]');
        $sort = \is_string($requestedSort) ? $requestedSort : '';
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_sort_dropdown_resolve(
            $sort, \strlen($sort), (int) \is_string($requestedSort),
            $keys, \strlen($keys), $keyLens, $optionCount,
            $baseUrl, \strlen($baseUrl),
            $params, \strlen($params), $tags, $paramLens, $paramCount,
            $keep, $out, $capacity, $urlLens, $current,
        );
        if (! \is_int($written) || $written < 0 || $written > $capacity) {
            return self::resolveSortStatePhp($requestedSort, $sortOptions, $baseUrl, $queryParams);
        }

        $kept = [];
        foreach ($paramKeys as $i => $paramKey) {
            if ($keep[$i] === 1) {
                $kept[$paramKey] = $queryParams[$paramKey];
            }
        }

        $urls = FFI::string($out, $written);
        $sortUrls = [];
        $offset = 0;
        $i = 0;
        $currentSort = '';
        $currentIndex = (int) $current[0];
        foreach ($sortOptions as $sortKey => $sortData) {
            $length = (int) $urlLens[$i];
            $sortUrls[$sortKey] = substr($urls, $offset, $length);
            $offset += $length;
            if ($i === $currentIndex) {
                $currentSort = (string) $sortKey;
            }
            $i++;
        }

        return [
            'currentSort' => $currentSort,
            'sortOptions' => $sortOptions,
            'queryParams' => $kept,
            'currentLabel' => $sortOptions[$currentSort]['label'] ?? 'Posted (Newest)',
            'currentIcon' => $sortOptions[$currentSort]['icon'] ?? 'fa-calendar-alt',
            'sortUrls' => $sortUrls,
        ];
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        return view('components.sort-dropdown');
    }
}
