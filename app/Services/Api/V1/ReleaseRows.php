<?php

declare(strict_types=1);

namespace App\Services\Api\V1;

use App\Models\Release;
use App\Support\RustPipelineLibrary;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ReleaseRows
{
    /** The Rust helper is opt-in; it did not beat PHP by 1.5x in the paired benchmark. */
    private const string FFI_CDEF = 'int64_t nntmux_api_release_rows_total(const char *input, size_t len);';

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
}
