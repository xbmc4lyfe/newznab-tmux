<?php

declare(strict_types=1);

namespace App\Services\LogViewer\Index;

use App\Models\LogIndexFile;
use App\Services\LogViewer\LogFile;
use App\Services\LogViewer\LogSearcher;
use App\Services\LogViewer\SearchQuery;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Routes each file of a log search to the Manticore index or to the grep/PHP scanner.
 *
 * A file goes to Manticore when the index is reachable, the file is caught up and the search can be
 * expressed there (not a regex); everything else, including files whose index query fails, falls back
 * to {@see LogSearcher}. Results keep the request's file order and say which engine produced them.
 */
class LogSearchCoordinator
{
    /**
     * Benchmark-gated default for {@see routeFileBatch()}: stays PHP because framing and decoding outweigh
     * the cheap per-file checks (Rust was slower); see
     * tests/Unit/RustLogViewerIndexIndexerCoordinatorManticoreSearcherW47ParityTest.php.
     */
    public const bool W47_LOG_SEARCH_COORDINATOR_RUST_FFI_DEFAULT = false;

    private static ?bool $useW47LogSearchCoordinatorRustFfi = null;

    public function __construct(
        private readonly LogSearcher $grep,
        private readonly ManticoreLogSearcher $manticore,
        private readonly LogIndexer $indexer,
        private readonly LogIndex $index,
    ) {}

    /**
     * The engine a plain search is expected to use, for display.
     */
    public function engine(): string
    {
        return $this->index->isAvailable() ? 'manticore' : $this->grep->engine();
    }

    /**
     * @param  list<LogFile>  $files
     * @return array{
     *     engine: string,
     *     elapsed_ms: int,
     *     results: list<array<string, mixed>>
     * }
     */
    public function search(SearchQuery $query, array $files, ?int $before, int $limit): array
    {
        $startedAt = hrtime(true);
        $results = [];

        foreach ($this->indexedFiles($query, $files) as [$row, $file]) {
            try {
                $results[$file->path] = ['engine' => 'manticore', ...$this->manticore->searchFile($query, $file, $row, $before, $limit)];
            } catch (Throwable $exception) {
                Log::warning('Log index search failed; falling back to grep: '.$exception->getMessage());

                break;
            }
        }

        $remaining = array_values(array_filter($files, static fn (LogFile $file): bool => ! isset($results[$file->path])));

        if ($remaining !== []) {
            $fallback = $this->grep->search($query, $remaining, $before, $limit);

            foreach ($fallback['results'] as $result) {
                $results[$result['file']] = ['engine' => $fallback['engine'], 'total_is_estimate' => false, ...$result];
            }
        }

        $ordered = [];

        foreach ($files as $file) {
            if (isset($results[$file->path])) {
                $ordered[] = $results[$file->path];
            }
        }

        return [
            'engine' => self::combinedEngine(array_column($ordered, 'engine')),
            'elapsed_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
            'results' => $ordered,
        ];
    }

    /**
     * The single engine that produced every result, otherwise `mixed`. Rust is opt-in.
     *
     * @param  list<mixed>  $engines
     */
    public static function combinedEngine(array $engines, bool $useRustFfi = false): string
    {
        return ($useRustFfi ? self::combinedEngineNative($engines) : null) ?? self::combinedEnginePhp($engines);
    }

    /** @param  list<mixed>  $engines */
    public static function combinedEnginePhp(array $engines): string
    {
        $engines = array_values(array_unique($engines));

        return count($engines) === 1 ? (string) $engines[0] : 'mixed';
    }

    /** @param  list<mixed>  $engines */
    public static function combinedEngineNative(array $engines): ?string
    {
        foreach ($engines as $engine) {
            if (! is_string($engine) || str_contains($engine, "\0")) {
                return null;
            }
        }
        static $ffi;
        $ffi ??= RustPipelineLibrary::resolveDomain('search', 'ssize_t nntmux_log_search_combined_engine(const char *engines, size_t len, size_t count, char *out, size_t cap);') ?? false;
        if ($ffi === false) {
            return null;
        }
        try {
            $joined = implode("\0", $engines);
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, strlen($joined) + 8, $cap);
            if ($scratch === null) {
                return null;
            }
            $written = $ffi->nntmux_log_search_combined_engine($joined, strlen($joined), count($engines), $scratch, $cap);

            return is_int($written) && $written >= 0 && $written <= $cap ? FFI::string($scratch, $written) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Pass null to restore {@see W47_LOG_SEARCH_COORDINATOR_RUST_FFI_DEFAULT}. */
    public static function useW47LogSearchCoordinatorRustFfi(?bool $enable = true): void
    {
        self::$useW47LogSearchCoordinatorRustFfi = $enable;
    }

    public static function usesW47LogSearchCoordinatorRustFfi(): bool
    {
        return self::$useW47LogSearchCoordinatorRustFfi ?? self::W47_LOG_SEARCH_COORDINATOR_RUST_FFI_DEFAULT;
    }

    public static function resetW47LogSearchCoordinatorRustFfi(): void
    {
        self::$useW47LogSearchCoordinatorRustFfi = null;
    }

    /**
     * Routes files `[path, size, indexed_offset, indexed_at]` (offset -1 without a tracking row, indexed_at
     * 0 when never indexed) like {@see search()}. Per file: `[route, state, indexed_bytes, indexed_before,
     * first_index]` — 1 for Manticore when the index is usable and the file is caught up within
     * $lagTolerance, the {@see LogIndexer::status()} state (0 none, 1 partial, 2 indexed), indexed bytes,
     * the index cursor `min($before, indexed_offset)` (-1 off the index) and the position of the first
     * file with the same path, which owns the result.
     *
     * @param  list<array{string, int, int, int}>  $files
     * @return list<list<int>>
     */
    public static function routeFileBatch(array $files, bool $indexUsable, int $lagTolerance, ?int $before): array
    {
        return self::usesW47LogSearchCoordinatorRustFfi()
            ? self::routeFileBatchNative($files, $indexUsable, $lagTolerance, $before)
            : self::routeFileBatchPhp($files, $indexUsable, $lagTolerance, $before);
    }

    /**
     * @param  list<array{string, int, int, int}>  $files
     * @return list<list<int>>
     */
    public static function routeFileBatchPhp(array $files, bool $indexUsable, int $lagTolerance, ?int $before): array
    {
        $rows = [];
        $first = [];
        $index = 0;

        foreach ($files as [$path, $size, $offset, $indexedAt]) {
            $hasRow = $offset >= 0;
            $state = ! $hasRow || $indexedAt <= 0 ? 0 : ($size - $offset <= $lagTolerance && $size >= $offset ? 2 : 1);
            $route = $indexUsable && $state === 2;
            $rows[] = [
                $route ? 1 : 0,
                $state,
                $hasRow ? min($offset, $size) : 0,
                $route ? ($before === null ? $offset : min($before, $offset)) : -1,
                $first[$path] ??= $index,
            ];
            $index++;
        }

        return $rows;
    }

    /**
     * @param  list<array{string, int, int, int}>  $files
     * @return list<list<int>>
     */
    public static function routeFileBatchNative(array $files, bool $indexUsable, int $lagTolerance, ?int $before): array
    {
        $count = \count($files);

        if ($count === 0) {
            return [];
        }

        $paths = array_column($files, 0);
        $sizes = array_column($files, 1);
        $offsets = array_column($files, 2);
        $indexedAt = array_column($files, 3);

        if (\count($paths) !== $count || \count($sizes) !== $count || \count($offsets) !== $count || \count($indexedAt) !== $count) {
            return self::routeFileBatchPhp($files, $indexUsable, $lagTolerance, $before);
        }

        $input = pack('P*', $count, $indexUsable ? 1 : 0, $lagTolerance, $before === null ? 0 : 1, $before ?? 0, ...$sizes, ...$offsets, ...$indexedAt)
            .LogIndex::w47Joined($paths);
        $raw = LogIndex::evaluateW47Native(3, $input, 40 * $count);

        return $raw === null ? self::routeFileBatchPhp($files, $indexUsable, $lagTolerance, $before) : LogIndex::w47Rows($raw, 5);
    }

    /**
     * Level and channel counts from the index; unavailable when the index cannot answer the search.
     *
     * @param  list<LogFile>  $files
     * @return array{available: bool, partial?: bool, levels?: array<string, int>, channels?: array<string, int>}
     */
    public function facets(SearchQuery $query, array $files): array
    {
        if (! $this->index->isAvailable() || ! LogIndexQuery::supports($query)) {
            return ['available' => false];
        }

        $rows = $this->indexer->rowsFor($files);
        $partial = array_any($files, fn (LogFile $file): bool => ! isset($rows[$file->path]) || ! $this->indexer->isCaughtUp($rows[$file->path], $file));

        try {
            $facets = $this->manticore->facets($query, array_values($rows));
        } catch (Throwable $exception) {
            Log::warning('Log index facets failed: '.$exception->getMessage());

            return ['available' => false];
        }

        return ['available' => true, 'partial' => $partial, ...$facets];
    }

    /**
     * Index progress per file path, or null when the index is unavailable.
     *
     * @param  list<LogFile>  $files
     * @return array<string, array{state: string, indexed_bytes: int, indexed_at: string|null}>|null
     */
    public function fileStatus(array $files): ?array
    {
        return $this->index->isAvailable() ? $this->indexer->status($files) : null;
    }

    /**
     * @param  list<LogFile>  $files
     * @return list<array{LogIndexFile, LogFile}>
     */
    private function indexedFiles(SearchQuery $query, array $files): array
    {
        if (! $this->index->isAvailable() || ! LogIndexQuery::supports($query)) {
            return [];
        }

        $rows = $this->indexer->rowsFor($files);
        $indexed = [];

        foreach ($files as $file) {
            $row = $rows[$file->path] ?? null;

            if ($row !== null && $this->indexer->isCaughtUp($row, $file)) {
                $indexed[] = [$row, $file];
            }
        }

        return $indexed;
    }
}
