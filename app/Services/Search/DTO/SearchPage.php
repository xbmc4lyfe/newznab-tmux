<?php

declare(strict_types=1);

namespace App\Services\Search\DTO;

use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

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

    public const string W56_FFI_CDEF = 'int64_t nntmux_w565_search_page_id_list_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W56.5 parity test's QUALIFICATION). */
    private const bool W56_RUST_FFI_DEFAULT = true;

    /**
     * Wave 56.5 routing and FFI cache, kept in function-local statics (readonly classes cannot declare static properties). Without $op it reports
     * the routing; 'set' swaps the override for $val and returns the previous one; 'reset' replaces the
     * cached handle with $val (null re-resolves, false disables); 'buffer' returns `[FFI, int64_t[],
     * capacity]` with room for at least $val words, or null without the library.
     */
    private static function w56RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'reset':
                $ffi = $val;
                $out = null;
                $outCap = 0;

                return null;
            case 'buffer':
                if ($ffi === null) {
                    $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
                    $ffi = RustPipelineLibrary::resolveDomain('w56_5', self::W56_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
                }
                if (! $ffi instanceof FFI || ! \is_int($val)) {
                    return null;
                }
                if ($out === null || $outCap < $val) {
                    $outCap = max(1024, $val);
                    $out = $ffi->new('int64_t['.$outCap.']');
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled ?? self::W56_RUST_FFI_DEFAULT;
        }
    }

    public static function usesW56RustFfi(): bool
    {
        return self::w56RustFfiState() === true;
    }

    /**
     * Runs $callback with idListPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW56RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w56RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w56RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 56.5 Rust helper over $count NUL-joined records, or null
     * to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w56Words(int $count, string $data, int $words): ?array
    {
        try {
            $handle = self::w56RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w565_search_page_id_list_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('P*', FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Batch normalization of comma-separated page id lists. Items are trimmed and empty items skipped;
     * 1-18 ASCII digits are ids, anything else is invalid. Four words per list: `[valid, distinct, max,
     * invalid]`, where `distinct` counts distinct id values and `max` is 0 without an id.
     *
     * @param  list<string>  $idLists
     * @return list<int>
     */
    public static function idListPlanBatch(array $idLists): array
    {
        return self::usesW56RustFfi() ? self::idListPlanBatchNative($idLists) : self::idListPlanBatchPhp($idLists);
    }

    /**
     * @param  list<string>  $idLists
     * @return list<int>
     */
    public static function idListPlanBatchPhp(array $idLists): array
    {
        $out = [];
        foreach ($idLists as $list) {
            $valid = $invalid = $max = 0;
            $seen = [];
            foreach (explode(',', $list) as $item) {
                $token = trim($item);
                if ($token === '') {
                    continue;
                }
                if (\strlen($token) <= 18 && ctype_digit($token)) {
                    $id = (int) $token;
                    $valid++;
                    $seen[$id] = true;
                    $max = max($max, $id);
                } else {
                    $invalid++;
                }
            }
            array_push($out, $valid, \count($seen), $max, $invalid);
        }

        return $out;
    }

    /**
     * Rust batch over the NUL-joined values; falls back to PHP without the library or when a value holds
     * a NUL.
     *
     * @param  list<string>  $idLists
     * @return list<int>
     */
    public static function idListPlanBatchNative(array $idLists): array
    {
        $count = \count($idLists);
        if ($count === 0) {
            return [];
        }

        return self::w56Words($count, implode("\0", $idLists), 4 * $count) ?? self::idListPlanBatchPhp($idLists);
    }
}
