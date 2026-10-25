<?php

declare(strict_types=1);

namespace App\Services\Search\DTO;

use App\Support\RustPipelineLibrary;
use FFI;

final readonly class SearchPage
{
    /**
     * Rust candidate for `legacy()`. It stays opt-in: it did not beat PHP by 1.5x in
     * the paired benchmark (tests/Unit/RustSearchPageProxySortDropdownParityTest.php).
     */
    public const string FFI_CDEF = <<<'C'
        intptr_t nntmux_search_page_legacy(const int64_t *ids, size_t count, int64_t total, int32_t fuzzy, int64_t *out_ids, size_t out_cap, int64_t *out_meta);
        C;

    /**
     * Rust stays opt-in for the Wave 41.5 summary: the FFI round trip misses the 1.5x gate; see
     * tests/Unit/RustSearchDtoQueryCursorPageMediaCodecW41ParityTest.php.
     */
    public const bool W415_RUST_FFI_DEFAULT = false;

    /**
     * @param  list<int>  $ids
     * @param  list<int|float|string>  $lastSortValues
     * @param  list<array<string, mixed>>  $documents
     */
    public function __construct(
        public array $ids,
        public int $total,
        public bool $fuzzy,
        public string $driver,
        public bool $available = true,
        public float $durationMs = 0.0,
        public array $lastSortValues = [],
        public bool $hasMore = false,
        public array $documents = [],
    ) {}

    private static function rustEnabled(?bool $enabled = null): bool
    {
        // Readonly classes cannot declare static properties.
        static $useRustFfi = false;
        if ($enabled !== null) {
            $useRustFfi = $enabled;
        }

        return $useRustFfi;
    }

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::rustEnabled($useRustFfi);
    }

    /**
     * Cached search-domain FFI handle; `false` records a missing library.
     */
    private static function ffiState(bool $assign = false, FFI|false|null $ffi = null): FFI|false|null
    {
        static $state = null;
        if ($assign) {
            $state = $ffi;
        }

        return $state;
    }

    public static function setSearchFfi(FFI|false|null $ffi): void
    {
        self::ffiState(true, $ffi);
    }

    public static function searchFfi(): ?FFI
    {
        if (self::ffiState() === null) {
            self::ffiState(true, RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false);
        }
        $ffi = self::ffiState();

        return $ffi instanceof FFI ? $ffi : null;
    }

    /**
     * Wave 41.5 toggle state; readonly classes cannot declare static properties.
     */
    private static function w415State(bool $assign = false, ?bool $enable = null): ?bool
    {
        static $useRustFfi = null;
        if ($assign) {
            $useRustFfi = $enable;
        }

        return $useRustFfi;
    }

    public static function useW415RustFfi(bool $enable = true): void
    {
        self::w415State(true, $enable);
    }

    public static function usesW415RustFfi(): bool
    {
        return self::w415State() ?? self::W415_RUST_FFI_DEFAULT;
    }

    public static function resetW415RustFfi(): void
    {
        self::w415State(true);
    }

    /**
     * Summarizes a batch of result pages. Non-page items are invalid. `id_count` counts every id of a
     * valid page and `distinct_ids` the distinct integer ids across them; non-integer ids are skipped
     * by `distinct_ids`. `max_total` is the largest page total (0 when none is larger) and
     * `distinct_drivers` counts distinct driver names.
     *
     * @param  array<array-key, mixed>  $pages
     * @return array{pages: int, invalid: int, available: int, fuzzy: int, has_more: int, id_count: int, distinct_ids: int, max_total: int, distinct_drivers: int}
     */
    public static function summarizePageBatch(array $pages): array
    {
        return self::usesW415RustFfi() ? self::summarizePageBatchNative($pages) : self::summarizePageBatchPhp($pages);
    }

    /**
     * @param  array<array-key, mixed>  $pages
     * @return array{pages: int, invalid: int, available: int, fuzzy: int, has_more: int, id_count: int, distinct_ids: int, max_total: int, distinct_drivers: int}
     */
    public static function summarizePageBatchPhp(array $pages): array
    {
        $invalid = 0;
        $available = 0;
        $fuzzy = 0;
        $hasMore = 0;
        $idCount = 0;
        $maxTotal = 0;
        $ids = [];
        $drivers = [];
        foreach ($pages as $page) {
            if (! $page instanceof self) {
                $invalid++;

                continue;
            }
            $available += (int) $page->available;
            $fuzzy += (int) $page->fuzzy;
            $hasMore += (int) $page->hasMore;
            $maxTotal = max($maxTotal, $page->total);
            $drivers[$page->driver] = true;
            $idCount += \count($page->ids);
            foreach ($page->ids as $id) {
                if (\is_int($id)) {
                    $ids[$id] = true;
                }
            }
        }

        return [
            'pages' => \count($pages),
            'invalid' => $invalid,
            'available' => $available,
            'fuzzy' => $fuzzy,
            'has_more' => $hasMore,
            'id_count' => $idCount,
            'distinct_ids' => \count($ids),
            'max_total' => $maxTotal,
            'distinct_drivers' => \count($drivers),
        ];
    }

    /**
     * Rust receives integer ids as little-endian 64-bit values; pages with non-list or non-integer
     * ids stay in PHP.
     *
     * @param  array<array-key, mixed>  $pages
     * @return array{pages: int, invalid: int, available: int, fuzzy: int, has_more: int, id_count: int, distinct_ids: int, max_total: int, distinct_drivers: int}
     */
    public static function summarizePageBatchNative(array $pages): array
    {
        $parts = [];
        foreach ($pages as $page) {
            if (! $page instanceof self) {
                array_push($parts, '0', '', '', '', '', '', '');

                continue;
            }
            $ids = $page->ids;
            if (! array_is_list($ids)) {
                return self::summarizePageBatchPhp($pages);
            }
            foreach ($ids as $id) {
                if (! \is_int($id)) {
                    return self::summarizePageBatchPhp($pages);
                }
            }
            array_push(
                $parts,
                '1',
                $page->available ? '1' : '0',
                $page->fuzzy ? '1' : '0',
                $page->hasMore ? '1' : '0',
                (string) $page->total,
                $page->driver,
                $ids === [] ? '' : pack('P*', ...$ids),
            );
        }
        $fields = SearchCursor::evaluateW415Native(3, $parts);
        if ($fields === null || \count($fields) !== 9) {
            return self::summarizePageBatchPhp($pages);
        }

        return [
            'pages' => (int) $fields[0],
            'invalid' => (int) $fields[1],
            'available' => (int) $fields[2],
            'fuzzy' => (int) $fields[3],
            'has_more' => (int) $fields[4],
            'id_count' => (int) $fields[5],
            'distinct_ids' => (int) $fields[6],
            'max_total' => (int) $fields[7],
            'distinct_drivers' => (int) $fields[8],
        ];
    }

    /** @return array{ids: list<int>, total: int, fuzzy: bool} */
    public function legacy(): array
    {
        return self::rustEnabled() ? $this->legacyNative() : $this->legacyPhp();
    }

    /** @return array{ids: list<int>, total: int, fuzzy: bool} */
    public function legacyPhp(): array
    {
        return ['ids' => $this->ids, 'total' => $this->total, 'fuzzy' => $this->fuzzy];
    }

    /** @return array{ids: list<int>, total: int, fuzzy: bool} */
    public function legacyNative(): array
    {
        $ids = $this->ids;
        $count = \count($ids);
        $ffi = self::searchFfi();
        if ($ffi === null || ! array_is_list($ids)) {
            return $this->legacyPhp();
        }

        $in = $ffi->new('int64_t['.max(1, $count).']');
        foreach ($ids as $i => $id) {
            if (! \is_int($id)) {
                return $this->legacyPhp();
            }
            $in[$i] = $id;
        }
        $out = $ffi->new('int64_t['.max(1, $count).']');
        $meta = $ffi->new('int64_t[2]');
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_search_page_legacy($in, $count, $this->total, (int) $this->fuzzy, $out, $count, $meta);
        if ($written !== $count) {
            return $this->legacyPhp();
        }

        $copied = [];
        for ($i = 0; $i < $count; $i++) {
            $copied[] = (int) $out[$i];
        }

        return ['ids' => $copied, 'total' => (int) $meta[0], 'fuzzy' => $meta[1] === 1];
    }
}
