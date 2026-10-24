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
