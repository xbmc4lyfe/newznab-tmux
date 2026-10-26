<?php

declare(strict_types=1);

namespace App\Data\Api;

use App\Models\RootCategory;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Throwable;

/**
 * API v2 representation of a {@see RootCategory} with its sub-categories.
 *
 * Replaces the legacy `App\Transformers\CategoryTransformer`.
 */
#[TypeScript]
final class CategoryData extends Data
{
    private const FFI_CDEF = <<<'CDEF'
    ssize_t nntmux_category_data_normalize_payload(
        int64_t id,
        const char *title_ptr,
        size_t title_len,
        int64_t *out_id,
        char *out_buf,
        size_t out_cap
    );
    CDEF;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustCoverCartFailedControllersParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = false;
        self::$ffi = null;
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
     * @param  array<int|string, string>  $subcategories  Map of sub-category ID → title.
     */
    public function __construct(
        public int $id,
        public string $name,
        public array $subcategories,
    ) {}

    public static function fromCategory(RootCategory $category): self
    {
        /** @var array<int|string, string> $subcategories */
        $subcategories = $category->categories()->pluck('title', 'id')->all();
        $normalized = self::normalizeCategoryPayload($category->id, $category->title, $subcategories);

        return new self(
            id: $normalized['id'],
            name: $normalized['name'],
            subcategories: $normalized['subcategories'],
        );
    }

    /**
     * @param  array<int|string, mixed>  $subcategories
     * @return array{id: int, name: string, subcategories: array<int|string, string>}
     */
    public static function normalizeCategoryPayload(
        int|string|null $id,
        ?string $title,
        array $subcategories,
    ): array {
        if (self::$useRustFfi) {
            return self::normalizeCategoryPayloadNative($id, $title, $subcategories);
        }

        return self::normalizeCategoryPayloadPhp($id, $title, $subcategories);
    }

    /**
     * @param  array<int|string, mixed>  $subcategories
     * @return array{id: int, name: string, subcategories: array<int|string, string>}
     */
    public static function normalizeCategoryPayloadPhp(
        int|string|null $id,
        ?string $title,
        array $subcategories,
    ): array {
        $normalizedSubcategories = [];
        foreach ($subcategories as $subId => $subTitle) {
            $normalizedSubcategories[$subId] = is_scalar($subTitle) || $subTitle === null ? (string) $subTitle : '';
        }

        return [
            'id' => (int) $id,
            'name' => (string) $title,
            'subcategories' => $normalizedSubcategories,
        ];
    }

    /**
     * @param  array<int|string, mixed>  $subcategories
     * @return array{id: int, name: string, subcategories: array<int|string, string>}
     */
    public static function normalizeCategoryPayloadNative(
        int|string|null $id,
        ?string $title,
        array $subcategories,
    ): array {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::normalizeCategoryPayloadPhp($id, $title, $subcategories);
        }

        try {
            $titleStr = (string) $title;
            $outId = $ffi->new('int64_t[1]');
            $cap = 0;
            $buf = RustPipelineLibrary::obtainScratch($ffi, max(32, strlen($titleStr) + 8), $cap);
            if ($buf === null) {
                return self::normalizeCategoryPayloadPhp($id, $title, $subcategories);
            }

            $rc = (int) self::callFfi(
                $ffi,
                'nntmux_category_data_normalize_payload',
                (int) $id,
                $titleStr,
                strlen($titleStr),
                $outId,
                $buf,
                $cap
            );
            if ($rc < -1) {
                $buf = RustPipelineLibrary::obtainScratch($ffi, -$rc, $cap);
                if ($buf === null) {
                    return self::normalizeCategoryPayloadPhp($id, $title, $subcategories);
                }
                $rc = (int) self::callFfi(
                    $ffi,
                    'nntmux_category_data_normalize_payload',
                    (int) $id,
                    $titleStr,
                    strlen($titleStr),
                    $outId,
                    $buf,
                    $cap
                );
            }
            if ($rc < 0) {
                return self::normalizeCategoryPayloadPhp($id, $title, $subcategories);
            }

            $normalizedSubcategories = [];
            foreach ($subcategories as $subId => $subTitle) {
                $normalizedSubcategories[$subId] = is_scalar($subTitle) || $subTitle === null ? (string) $subTitle : '';
            }

            return [
                'id' => (int) $outId[0],
                'name' => $rc === 0 ? '' : FFI::string($buf, $rc),
                'subcategories' => $normalizedSubcategories,
            ];
        } catch (Throwable) {
            return self::normalizeCategoryPayloadPhp($id, $title, $subcategories);
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

        $ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }

    // Rust is the default only while the paired W65.6 benchmark clears 1.5x on all four metrics.
    public const bool W65_RUST_FFI_DEFAULT = true;

    private const string W65_CATEGORY_FFI_CDEF = <<<'C'
        int32_t nntmux_w656_category_data_batch(const char *strings, size_t strings_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    public const int W65_CATEGORY_HEADER = 17;

    /** Integer columns in the order Rust reads them. */
    public const array W65_CATEGORY_COLUMNS = ['id', 'parentid', 'status'];

    private const int W65_MAX_ITEMS = 1_048_576;

    /** Largest category id the tree accepts and the id clamp of a row (2^31 - 1). */
    private const int W65_CATEGORY_ID_CAP = 99_999;

    private const int W65_COUNT_CAP = 2_147_483_647;

    /**
     * Plan the API category tree: titles with the `id`, `parentid` and `status` columns. Roots are
     * `Category::ROOT_CATEGORIES` ids (1 and the thousands 1000-9000) without a parent (parent at or below 0).
     * Kind 0 root, 1 sub-category (its parent is a root in the batch, it shares the parent's thousand and differs
     * from it), 2 orphan (its parent is no root in the batch), 3 invalid (id at or below 0 or above 99999, a
     * parentless non-root id or a sub-category outside its parent's range). Status 0 inactive, 1 active, 2
     * disabled (`Category::STATUS_*`), 3 unknown. Flags: 1 duplicate id, 2 blank title (after `trim()`), 4
     * non-ASCII title, 8 visible (active root or sub-category).
     *
     * Layout: `[0]` count, `[1..5]` per kind, `[5..9]` per status, `[9]` distinct roots, `[10]` duplicates,
     * `[11]` blank titles, `[12]` most sub-categories of one parent, `[13]` parents with sub-categories, `[14]`
     * first invalid and `[15]` first orphan index (-1), `[16]` FNV-1a digest; then per category `kind | status
     * << 2 | flags << 4 | min(intdiv(id, 1000), 15) << 8 | min(title bytes, 255) << 12 | id (0..2^31-1) << 20`.
     *
     * @param  array<array-key, int|string|null>  $titles
     * @param  array<string, array<array-key, int>>  $metrics
     * @return list<int>
     */
    public static function w65CategoryDataPlanBatch(array $titles, array $metrics): array
    {
        return (self::usesW65RustFfi() ? self::w65CategoryDataPlanBatchNative($titles, $metrics) : null)
            ?? self::w65CategoryDataPlanBatchPhp($titles, $metrics);
    }

    /**
     * @param  array<array-key, int|string|null>  $titles
     * @param  array<string, array<array-key, int>>  $metrics
     * @return list<int>
     */
    public static function w65CategoryDataPlanBatchPhp(array $titles, array $metrics): array
    {
        $titles = array_values($titles);
        $count = \count($titles);
        [$ids, $parents, $statuses] = self::w65Metrics($metrics, $count);
        $out = array_fill(0, self::W65_CATEGORY_HEADER, 0);
        $out[0] = $count;
        $out[14] = $out[15] = -1;
        $roots = $seen = $children = [];
        foreach ($ids as $at => $id) {
            if ($parents[$at] <= 0 && self::w65RootId($id)) {
                $roots[$id] = true;
            }
        }
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($titles as $at => $title) {
            $title = (string) $title;
            $id = $ids[$at];
            $parent = $parents[$at];
            $kind = match (true) {
                $id <= 0 || $id > self::W65_CATEGORY_ID_CAP => 3,
                $parent <= 0 => self::w65RootId($id) ? 0 : 3,
                ! isset($roots[$parent]) => 2,
                intdiv($id, 1000) === intdiv($parent, 1000) && $id !== $parent => 1,
                default => 3,
            };
            $status = $statuses[$at] >= 0 && $statuses[$at] <= 2 ? $statuses[$at] : 3;
            $duplicate = isset($seen[$id]);
            $seen[$id] = true;
            $blank = trim($title) === '';
            $flags = ($duplicate ? 1 : 0) | ($blank ? 2 : 0) | (preg_match('/[\x80-\xff]/', $title) === 1 ? 4 : 0) | ($status === 1 && $kind <= 1 ? 8 : 0);
            if ($kind === 1) {
                $children[$parent] = ($children[$parent] ?? 0) + 1;
            }
            $out[1 + $kind]++;
            $out[5 + $status]++;
            $out[10] += (int) $duplicate;
            $out[11] += (int) $blank;
            if ($kind === 3 && $out[14] < 0) {
                $out[14] = $at;
            }
            if ($kind === 2 && $out[15] < 0) {
                $out[15] = $at;
            }
            $row = $kind | $status << 2 | $flags << 4 | min(intdiv(max($id, 0), 1000), 15) << 8 | min(\strlen($title), 255) << 12
                | max(0, min($id, self::W65_COUNT_CAP)) << 20;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[9] = \count($roots);
        $out[12] = $children === [] ? 0 : max($children);
        $out[13] = \count($children);
        $out[16] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust plan, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, int|string|null>  $titles
     * @param  array<string, array<array-key, int>>  $metrics
     * @return list<int>|null
     */
    public static function w65CategoryDataPlanBatchNative(array $titles, array $metrics): ?array
    {
        $count = \count($titles);
        if ($count > self::W65_MAX_ITEMS) {
            return null;
        }
        [$joined, $twin] = self::w65Strings(array_values($titles));
        $ints = self::w65Pack($metrics, $count);
        $words = self::W65_CATEGORY_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w65Call($words, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w656_category_data_batch($joined, \strlen($joined), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $words));
    }

    /**
     * A `Category::ROOT_CATEGORIES` id: `OTHER_ROOT` (1) or a thousand from 1000 to 9000.
     */
    private static function w65RootId(int $id): bool
    {
        return $id === 1 || ($id >= 1000 && $id <= 9000 && $id % 1000 === 0);
    }

    /**
     * Route the Wave 65.6 batch plan through Rust for the rest of the process.
     */
    public static function enableW65RustFfi(): void
    {
        self::w65RustFfiState('set', true);
    }

    /**
     * Route the Wave 65.6 batch plan through PHP for the rest of the process.
     */
    public static function disableW65RustFfi(): void
    {
        self::w65RustFfiState('set', false);
    }

    /**
     * Restore the default Wave 65.6 routing and drop the cached binding and scratch buffer.
     */
    public static function resetW65RustFfi(): void
    {
        self::w65RustFfiState('reset');
    }

    public static function usesW65RustFfi(): bool
    {
        $forced = self::w65RustFfiState();

        return \is_bool($forced) ? $forced : self::W65_RUST_FFI_DEFAULT;
    }

    /**
     * Run `$callback` with the Wave 65.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW65RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w65RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w65RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 65.6 routing and binding in function statics, so the class gains no properties. No `$op` returns
     * the forced routing (null for the default); `'set'` replaces it with `$val` and returns the previous
     * value; `'reset'` clears the routing, binding and buffer; `'binding'` returns the cached binding and an
     * output buffer of at least `$val` values, or null.
     */
    private static function w65RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $enabled */
        static $enabled = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $buffer */
        static $buffer = null;
        static $capacity = 0;
        if ($op === 'set') {
            $previous = $enabled;
            $enabled = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'reset') {
            $enabled = $ffi = $buffer = null;
            $capacity = 0;

            return null;
        }
        if ($op !== 'binding') {
            return $enabled;
        }
        $fields = \is_int($val) ? $val : 0;
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('api_w656', self::W65_CATEGORY_FFI_CDEF) ?? false;
            if ($ffi === false) {
                return null;
            }
            if ($buffer === null || $capacity < $fields) {
                $capacity = max(256, $fields);
                $buffer = $ffi->new('int64_t['.$capacity.']');
            }
        } catch (Throwable) {
            return null;
        }

        return $buffer === null ? null : [$ffi, $buffer];
    }

    /**
     * The batch's `$fields` output words from one Wave 65.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w65Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w65RustFfiState('binding', $fields);
        if ($binding === null) {
            return null;
        }
        try {
            if ($call($binding[0], $binding[1]) !== 1) {
                return null;
            }
            $values = unpack('P*', FFI::string($binding[1], $fields * 8));
        } catch (Throwable) {
            return null;
        }

        return $values === false ? null : array_values($values);
    }

    /**
     * Values NUL-joined and, when one holds a NUL, the same values joined with 0x01 so Rust finds the
     * separators where the two differ.
     *
     * @param  list<int|string|null>  $items
     * @return array{0: string, 1: string}
     */
    private static function w65Strings(array $items): array
    {
        $joined = implode("\0", $items);

        return $items !== [] && substr_count($joined, "\0") !== \count($items) - 1 ? [$joined, implode("\x01", $items)] : [$joined, ''];
    }

    /**
     * Values by position, cut or padded with `$pad` to `$count`.
     *
     * @template T of int|string|null
     *
     * @param  array<array-key, T>  $values
     * @param  T  $pad
     * @return list<T>
     */
    private static function w65Column(array $values, int $count, int|string|null $pad = 0): array
    {
        $values = array_values($values);

        return \count($values) === $count ? $values : array_pad(\array_slice($values, 0, $count), $count, $pad);
    }

    /**
     * The named integer columns of {@see W65_CATEGORY_COLUMNS}, each cut or padded with 0 to `$count`.
     *
     * @param  array<string, array<array-key, int>>  $metrics
     * @return list<list<int>>
     */
    private static function w65Metrics(array $metrics, int $count): array
    {
        $columns = [];
        foreach (self::W65_CATEGORY_COLUMNS as $key) {
            $columns[] = self::w65Column($metrics[$key] ?? [], $count);
        }

        return $columns;
    }

    /**
     * The named integer columns packed one after another as machine-order `int64_t`.
     *
     * @param  array<string, array<array-key, int>>  $metrics
     */
    private static function w65Pack(array $metrics, int $count): string
    {
        $packed = '';
        foreach (self::w65Metrics($metrics, $count) as $column) {
            $packed .= pack('q*', ...$column);
        }

        return $packed;
    }
}
