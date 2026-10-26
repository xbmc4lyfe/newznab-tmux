<?php

declare(strict_types=1);

namespace App\Services\Api\V1;

use App\Models\Release;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ReleaseRows
{
    /** The Rust helper is opt-in; it did not beat PHP by 1.5x in the paired benchmark. */
    private const string FFI_CDEF = 'int64_t nntmux_api_release_rows_total(const char *input, size_t len);';

    /**
     * The Wave 46.5 `_totalrows` summary defaults to Rust: every common and uncommon mean and p99 ratio
     * cleared 1.5x (min 2.39/1.78/3.63/2.83x); see
     * tests/Unit/RustApiV1V2PresenterPayloadRowsXmlContextW46ParityTest.php.
     */
    public const bool W465_RUST_FFI_DEFAULT = true;

    private static ?bool $useW465RustFfi = null;

    /**
     * @var list<mixed>|null
     */
    private ?array $rows = null;

    public function __construct(private readonly mixed $releases) {}

    /**
     * @return list<mixed>
     */
    public function rows(): array
    {
        return $this->rows ??= $this->withPartialCounts($this->normalizedRows());
    }

    /**
     * Search and browse projections, including search-index documents, omit the partial-publication
     * counts; attach them by GUID with one query so clients see incompleteness before downloading.
     *
     * @param  list<mixed>  $rows
     * @return list<mixed>
     */
    private function withPartialCounts(array $rows): array
    {
        $guids = array_values(array_filter(array_map(fn (mixed $row): mixed => $this->value($row, 'guid'), $rows), 'is_string'));
        if ($guids === []) {
            return $rows;
        }
        try {
            if (! Release::hasPartialArticleCounts()) {
                return $rows;
            }
            $counts = DB::table('releases')->whereIn('guid', $guids)->whereNotNull('articles_expected')
                ->get(['guid', 'articles_found', 'articles_expected', 'files_found', 'files_expected'])->keyBy('guid');
        } catch (Throwable) {
            return $rows;
        }
        foreach ($rows as $index => $row) {
            $count = $counts->get($this->value($row, 'guid'));
            if ($count === null) {
                continue;
            }
            foreach (['articles_found', 'articles_expected', 'files_found', 'files_expected'] as $key) {
                if (\is_array($row)) {
                    $rows[$index][$key] = $count->{$key};
                } elseif (\is_object($row)) {
                    $row->{$key} = $count->{$key};
                }
            }
        }

        return $rows;
    }

    /**
     * @return list<mixed>
     */
    private function normalizedRows(): array
    {
        if ($this->releases === null || $this->releases === false || $this->releases === []) {
            return [];
        }

        if ($this->releases instanceof Collection) {
            return $this->releases->values()->all();
        }

        if (\is_array($this->releases)) {
            return array_values($this->releases);
        }

        if ($this->releases instanceof \Traversable) {
            return array_values(iterator_to_array($this->releases));
        }

        if (\is_object($this->releases)) {
            return [$this->releases];
        }

        return [];
    }

    public function totalRows(): int
    {
        $rows = $this->rows();
        if ($rows === []) {
            return 0;
        }

        return self::totalRowsFrom($this->value($rows[0], '_totalrows'), \count($rows));
    }

    /**
     * The `_totalrows` projection value as an int, else the row count.
     */
    public static function totalRowsFrom(mixed $totalRows, int $rowCount, bool $useRustFfi = false): int
    {
        return ($useRustFfi ? self::totalRowsFromNative($totalRows) : null) ?? self::totalRowsFromPhp($totalRows, $rowCount);
    }

    public static function totalRowsFromPhp(mixed $totalRows, int $rowCount): int
    {
        if ($totalRows !== null) {
            return (int) $totalRows;
        }

        return $rowCount;
    }

    /**
     * Rust `(int)` cast for plain leading-integer strings; null (PHP decides) for
     * other values and for float forms, overflow or strings without digits.
     */
    public static function totalRowsFromNative(mixed $totalRows): ?int
    {
        if (! \is_string($totalRows)) {
            return null;
        }
        static $ffi;
        $ffi ??= RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false;
        if ($ffi === false) {
            return null;
        }
        try {
            $total = $ffi->nntmux_api_release_rows_total($totalRows, \strlen($totalRows));
        } catch (Throwable) {
            return null;
        }

        return \is_int($total) && $total !== PHP_INT_MIN ? $total : null;
    }

    /** Pass null to restore {@see W465_RUST_FFI_DEFAULT}. */
    public static function useW465RustFfi(?bool $enable = true): void
    {
        self::$useW465RustFfi = $enable;
    }

    public static function usesW465RustFfi(): bool
    {
        return self::$useW465RustFfi ?? self::W465_RUST_FFI_DEFAULT;
    }

    public static function resetW465RustFfi(): void
    {
        self::$useW465RustFfi = null;
    }

    /**
     * Summarizes {@see totalRowsFromPhp()} over string `_totalrows` projection values: `numeric` values
     * (is_numeric()), `zero` and `negative` totals, `max_total`/`min_total` (0 without strings) and
     * `distinct_totals`. Non-string values are invalid.
     *
     * @param  array<array-key, mixed>  $values
     * @return array{values: int, invalid: int, numeric: int, zero: int, negative: int, max_total: int, min_total: int, distinct_totals: int}
     */
    public static function summarizeTotalRowsBatch(array $values): array
    {
        return self::usesW465RustFfi() ? self::summarizeTotalRowsBatchNative($values) : self::summarizeTotalRowsBatchPhp($values);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array{values: int, invalid: int, numeric: int, zero: int, negative: int, max_total: int, min_total: int, distinct_totals: int}
     */
    public static function summarizeTotalRowsBatchPhp(array $values): array
    {
        $summary = ['values' => \count($values), 'invalid' => 0, 'numeric' => 0, 'zero' => 0, 'negative' => 0, 'max_total' => 0, 'min_total' => 0, 'distinct_totals' => 0];
        $distinct = [];
        $max = null;
        $min = null;
        foreach ($values as $value) {
            if (! \is_string($value)) {
                $summary['invalid']++;

                continue;
            }
            $total = self::totalRowsFromPhp($value, 0);
            $summary['numeric'] += (int) is_numeric($value);
            $summary['zero'] += (int) ($total === 0);
            $summary['negative'] += (int) ($total < 0);
            $max = $max === null ? $total : max($max, $total);
            $min = $min === null ? $total : min($min, $total);
            $distinct[$total] = true;
        }
        $summary['max_total'] = $max ?? 0;
        $summary['min_total'] = $min ?? 0;
        $summary['distinct_totals'] = \count($distinct);

        return $summary;
    }

    /**
     * Rust declines float forms, integer overflow and strings holding a NUL; PHP then serves the batch.
     *
     * @param  array<array-key, mixed>  $values
     * @return array{values: int, invalid: int, numeric: int, zero: int, negative: int, max_total: int, min_total: int, distinct_totals: int}
     */
    public static function summarizeTotalRowsBatchNative(array $values): array
    {
        $strings = [];
        foreach ($values as $value) {
            if (\is_string($value)) {
                $strings[] = $value;
            }
        }
        $fields = ApiV1Presenter::evaluateW465Counters(3, ApiV1Presenter::w465Joined($strings), 6);
        if ($fields === null) {
            return self::summarizeTotalRowsBatchPhp($values);
        }

        return ['values' => \count($values), 'invalid' => \count($values) - \count($strings)]
            + array_combine(['numeric', 'zero', 'negative', 'max_total', 'min_total', 'distinct_totals'], $fields);
    }

    private function value(mixed $row, string $key): mixed
    {
        if (\is_array($row) && \array_key_exists($key, $row)) {
            return $row[$key];
        }

        if (\is_object($row) && isset($row->{$key})) {
            return $row->{$key};
        }

        return null;
    }

    public const string W54_FFI_CDEF = 'int64_t nntmux_w545_release_rows_search_name_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W54.5 parity test's QUALIFICATION). */
    private const bool W54_RUST_FFI_DEFAULT = true;

    /** Resolution of a lower-cased search name token. */
    private const array W54_RESOLUTIONS = ['2160p' => 2160, '4k' => 2160, 'uhd' => 2160, '1080p' => 1080, '1080i' => 1080, '720p' => 720, '576p' => 576, '480p' => 480];

    /**
     * Wave 54.5 routing and FFI cache, kept in function-local statics. Without $op it reports the routing;
     * 'set' swaps the override for $val and returns the previous one; 'reset' replaces the cached handle
     * with $val (null re-resolves, false disables); 'buffer' returns `[FFI, int64_t[], capacity]` with room
     * for at least $val words, or null without the library.
     */
    private static function w54RustFfiState(?string $op = null, mixed $val = null): mixed
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
                    $ffi = RustPipelineLibrary::resolveDomain('w54_5', self::W54_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
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
                return $enabled ?? self::W54_RUST_FFI_DEFAULT;
        }
    }

    public static function usesW54RustFfi(): bool
    {
        return self::w54RustFfiState() === true;
    }

    /**
     * Runs $callback with searchNamePlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW54RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w54RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w54RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 54.5 Rust helper over $count NUL-joined records, or null
     * to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w54Words(int $count, string $data, int $words): ?array
    {
        try {
            $handle = self::w54RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w545_release_rows_search_name_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
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
     * Batch shape of release search names split on `[._\- ]+`. Four words per name: `[tokens, the first
     * 19xx/20xx token (or 0), the first resolution token (2160 for 2160p/4K/UHD, 1080 for 1080p/1080i,
     * 720, 576, 480, else 0), flags (1 non-ASCII byte, 2 PROPER/REPACK token, 4 `-GROUP` suffix)]`.
     *
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function searchNamePlanBatch(array $names): array
    {
        return self::usesW54RustFfi() ? self::searchNamePlanBatchNative($names) : self::searchNamePlanBatchPhp($names);
    }

    /**
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function searchNamePlanBatchPhp(array $names): array
    {
        $out = [];
        foreach ($names as $name) {
            $tokens = preg_split('/[._\- ]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $year = 0;
            $resolution = 0;
            $flags = preg_match('/[\x80-\xff]/', $name) === 1 ? 1 : 0;
            foreach ($tokens as $token) {
                $lower = strtolower($token);
                if ($year === 0 && \strlen($token) === 4 && (str_starts_with($token, '19') || str_starts_with($token, '20')) && ctype_digit($token)) {
                    $year = (int) $token;
                }
                if ($resolution === 0) {
                    $resolution = self::W54_RESOLUTIONS[$lower] ?? 0;
                }
                if ($lower === 'proper' || $lower === 'repack') {
                    $flags |= 2;
                }
            }
            if (preg_match('/-[A-Za-z0-9]+\z/', $name) === 1) {
                $flags |= 4;
            }
            array_push($out, \count($tokens), $year, $resolution, $flags);
        }

        return $out;
    }

    /**
     * Rust batch over the NUL-joined values; falls back to PHP without the library or when a value holds
     * a NUL.
     *
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function searchNamePlanBatchNative(array $names): array
    {
        $count = \count($names);
        if ($count === 0) {
            return [];
        }

        return self::w54Words($count, implode("\0", $names), 4 * $count) ?? self::searchNamePlanBatchPhp($names);
    }

    // Rust is the default only while the paired W65.6 benchmark clears 1.5x on all four metrics.
    public const bool W65_RUST_FFI_DEFAULT = true;

    private const string W65_ROWS_FFI_CDEF = <<<'C'
        int32_t nntmux_w656_release_rows_batch(const char *strings, size_t strings_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t offset, int64_t limit, int64_t *out, size_t out_cap);
        C;

    public const int W65_ROWS_HEADER = 22;

    /** Integer columns in the order Rust reads them. */
    public const array W65_ROWS_COLUMNS = ['categories_id', 'size', 'grabs', 'comments'];

    private const int W65_MAX_ITEMS = 1_048_576;

    /** API v1 page size for a limit at or below 0 (and its cap), size clamp (2^50), count clamp and total cap. */
    private const int W65_ROWS_LIMIT = 100;

    private const int W65_SIZE_CAP = 1_125_899_906_842_624;

    private const int W65_COUNT_CAP = 2_147_483_647;

    private const int W65_SUM_CAP = 4_611_686_018_427_387_904;

    /**
     * Plan an API v1 page of release rows: GUIDs, `_totalrows` projection values and the `categories_id`, `size`,
     * `grabs` and `comments` columns. The total is the first row's `_totalrows` when it is 1-18 digits, else the
     * row count; the offset clamps to 0..total, the limit (100 at or below 0) caps at 100 and the page holds
     * min(rows, limit) rows. Sizes clamp to 0..2^50 and counts to 0..2^31-1; roots are `intdiv(category, 1000)`
     * for 1000-9999, else 0.
     *
     * Layout: `[0]` count, `[1]` total, `[2]` offset, `[3]` page rows, `[4]` more pages, `[5..15]` per root,
     * `[15]` valid GUIDs (40 lowercase hex), `[16]` total size (capped at 2^62), `[17]` grabs, `[18]` comments,
     * `[19]` largest size, `[20]` first invalid GUID index (-1), `[21]` FNV-1a digest; then per row `root | mask
     * << 4 | min(grabs, 2^20-1) << 10 | min(comments, 2^16-1) << 30 | min(GUID bytes, 255) << 46` (mask: 1 size,
     * 2 grabs, 4 comments, 8 valid GUID, 16 on the page, 32 numeric `_totalrows`).
     *
     * @param  array<array-key, int|string>  $guids
     * @param  array<array-key, int|string|null>  $totals
     * @param  array<string, array<array-key, int>>  $metrics
     * @return list<int>
     */
    public static function w65ReleaseRowsPlanBatch(array $guids, array $totals, array $metrics, int $offset = 0, int $limit = 0): array
    {
        return (self::usesW65RustFfi() ? self::w65ReleaseRowsPlanBatchNative($guids, $totals, $metrics, $offset, $limit) : null)
            ?? self::w65ReleaseRowsPlanBatchPhp($guids, $totals, $metrics, $offset, $limit);
    }

    /**
     * @param  array<array-key, int|string>  $guids
     * @param  array<array-key, int|string|null>  $totals
     * @param  array<string, array<array-key, int>>  $metrics
     * @return list<int>
     */
    public static function w65ReleaseRowsPlanBatchPhp(array $guids, array $totals, array $metrics, int $offset = 0, int $limit = 0): array
    {
        $guids = array_values($guids);
        $count = \count($guids);
        $totals = self::w65Column($totals, $count, '');
        [$categories, $sizes, $grabs, $comments] = self::w65Metrics($metrics, $count);
        $limit = $limit <= 0 ? self::W65_ROWS_LIMIT : min($limit, self::W65_ROWS_LIMIT);
        $total = ($count > 0 ? self::w65Digits((string) $totals[0]) : null) ?? $count;
        $offset = min(max($offset, 0), $total);
        $page = min($count, $limit);
        $out = array_fill(0, self::W65_ROWS_HEADER, 0);
        $out[0] = $count;
        $out[1] = $total;
        $out[2] = $offset;
        $out[3] = $page;
        $out[4] = (int) ($offset + $page < $total);
        $out[20] = -1;
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($guids as $at => $guid) {
            $guid = (string) $guid;
            $category = $categories[$at];
            $size = max(0, min($sizes[$at], self::W65_SIZE_CAP));
            $grab = max(0, min($grabs[$at], self::W65_COUNT_CAP));
            $comment = max(0, min($comments[$at], self::W65_COUNT_CAP));
            $root = $category >= 1000 && $category < 10_000 ? intdiv($category, 1000) : 0;
            $valid = \strlen($guid) === 40 && strspn($guid, '0123456789abcdef') === 40;
            $mask = ($size > 0 ? 1 : 0) | ($grab > 0 ? 2 : 0) | ($comment > 0 ? 4 : 0) | ($valid ? 8 : 0) | ($at < $page ? 16 : 0)
                | (self::w65Digits((string) $totals[$at]) !== null ? 32 : 0);
            $out[5 + $root]++;
            $out[15] += (int) $valid;
            $out[16] = min($out[16] + $size, self::W65_SUM_CAP);
            $out[17] += $grab;
            $out[18] += $comment;
            $out[19] = max($out[19], $size);
            if (! $valid && $out[20] < 0) {
                $out[20] = $at;
            }
            $row = $root | $mask << 4 | min($grab, 0xFFFFF) << 10 | min($comment, 0xFFFF) << 30 | min(\strlen($guid), 255) << 46;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[21] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust plan, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, int|string>  $guids
     * @param  array<array-key, int|string|null>  $totals
     * @param  array<string, array<array-key, int>>  $metrics
     * @return list<int>|null
     */
    public static function w65ReleaseRowsPlanBatchNative(array $guids, array $totals, array $metrics, int $offset = 0, int $limit = 0): ?array
    {
        $count = \count($guids);
        if ($count > self::W65_MAX_ITEMS) {
            return null;
        }
        [$joined, $twin] = self::w65Strings([...array_values($guids), ...self::w65Column($totals, $count, '')]);
        $ints = self::w65Pack($metrics, $count);
        $words = self::W65_ROWS_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w65Call($words, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w656_release_rows_batch($joined, \strlen($joined), $twin, \strlen($twin), $ints, \strlen($ints), $count, $offset, $limit, $out, $words));
    }

    /**
     * `(int)` of a 1-18 byte `ctype_digit()` string, else null.
     */
    private static function w65Digits(string $value): ?int
    {
        return \strlen($value) <= 18 && ctype_digit($value) ? (int) $value : null;
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
            $ffi ??= RustPipelineLibrary::resolveDomain('api_w656', self::W65_ROWS_FFI_CDEF) ?? false;
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
     * The named integer columns of {@see W65_ROWS_COLUMNS}, each cut or padded with 0 to `$count`.
     *
     * @param  array<string, array<array-key, int>>  $metrics
     * @return list<list<int>>
     */
    private static function w65Metrics(array $metrics, int $count): array
    {
        $columns = [];
        foreach (self::W65_ROWS_COLUMNS as $key) {
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
