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
