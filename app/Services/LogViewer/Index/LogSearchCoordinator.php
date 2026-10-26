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

    public const string W54_FFI_CDEF = 'int64_t nntmux_w545_log_search_path_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W54.5 parity test's QUALIFICATION). */
    private const bool W54_RUST_FFI_DEFAULT = true;

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
     * Runs $callback with pathPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
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
            $written = $ffi->nntmux_w545_log_search_path_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
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
     * Batch shape of searched log paths. Four words per path: `[basename offset, daily date as Ymd from a
     * `name-Y-m-d.log[.gz]` basename (or 0), flags (1 `.log`, 2 `.gz`, 4 hidden basename), position of the
     * first path with the same bytes, which owns the result]`.
     *
     * @param  list<string>  $paths
     * @return list<int>
     */
    public static function pathPlanBatch(array $paths): array
    {
        return self::usesW54RustFfi() ? self::pathPlanBatchNative($paths) : self::pathPlanBatchPhp($paths);
    }

    /**
     * @param  list<string>  $paths
     * @return list<int>
     */
    public static function pathPlanBatchPhp(array $paths): array
    {
        $out = [];
        $first = [];
        $index = 0;
        foreach ($paths as $path) {
            $slash = strrpos($path, '/');
            $offset = $slash === false ? 0 : $slash + 1;
            $base = substr($path, $offset);
            $date = preg_match('/-(\d{4})-(\d{2})-(\d{2})\.log(?:\.gz)?\z/', $base, $m) === 1 ? (int) ($m[1].$m[2].$m[3]) : 0;
            $flags = (str_ends_with($base, '.log') ? 1 : 0) | (str_ends_with($base, '.gz') ? 2 : 0) | (str_starts_with($base, '.') ? 4 : 0);
            array_push($out, $offset, $date, $flags, $first[$path] ??= $index);
            $index++;
        }

        return $out;
    }

    /**
     * Rust batch over the NUL-joined values; falls back to PHP without the library or when a value holds
     * a NUL.
     *
     * @param  list<string>  $paths
     * @return list<int>
     */
    public static function pathPlanBatchNative(array $paths): array
    {
        $count = \count($paths);
        if ($count === 0) {
            return [];
        }

        return self::w54Words($count, implode("\0", $paths), 4 * $count) ?? self::pathPlanBatchPhp($paths);
    }

    /** C signature of the Wave 67.5 {@see self::w67LogSearchCoordinatorPlanBatch()} helper. */
    private const string W67_LOG_SEARCH_COORDINATOR_PLAN_FFI_CDEF = 'int64_t nntmux_w675_log_search_coordinator_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /**
     * The Wave 67.5 plan batch defaults to Rust: every common and uncommon mean and p99 ratio cleared 1.5x
     * (min 6.85/5.98/7.33/5.35x); see tests/Unit/RustLogViewerIndexCoordinatorManticoreEntryParserW67ParityTest.php.
     */
    public const bool W67_RUST_FFI_DEFAULT = true;

    /**
     * Wave 67.5 routing and FFI cache, kept in function-local statics (no properties). Without $op it
     * reports the routing; 'set' swaps the override for $val and returns the previous one; 'reset' replaces the
     * cached handle with $val (null re-resolves, false disables); 'buffer' returns `[FFI, int64_t[], capacity]`
     * with room for at least $val words, or null without the library.
     */
    private static function w67RustFfiState(?string $op = null, mixed $val = null): mixed
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
                    $ffi = RustPipelineLibrary::resolveDomain('w67_5', self::W67_LOG_SEARCH_COORDINATOR_PLAN_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
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
                return $enabled ?? self::W67_RUST_FFI_DEFAULT;
        }
    }

    /** Route the Wave 67.5 plan batch to Rust until disabled or reset. */
    public static function enableW67RustFfi(): void
    {
        self::w67RustFfiState('set', true);
    }

    /** Route the Wave 67.5 plan batch to PHP until enabled or reset. */
    public static function disableW67RustFfi(): void
    {
        self::w67RustFfiState('set', false);
    }

    /** Restore W67_RUST_FFI_DEFAULT routing and drop the cached Wave 67.5 handle and buffer. */
    public static function resetW67RustFfi(): void
    {
        self::w67RustFfiState('set', null);
        self::w67RustFfiState('reset', null);
    }

    public static function usesW67RustFfi(): bool
    {
        return self::w67RustFfiState() === true;
    }

    /**
     * Runs $callback with w67LogSearchCoordinatorPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW67RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w67RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w67RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 67.5 Rust helper over $count NUL-joined records, or null
     * to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w67Words(int $count, string $data, int $words): ?array
    {
        try {
            $handle = self::w67RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w675_log_search_coordinator_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('q*', FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Trimmed 1-15 ASCII digits as an integer; empty yields $default, anything else sets bit 1 and yields $default. */
    private static function w67Number(string $field, int $default, int &$flags): int
    {
        $text = trim($field);
        if ($text === '') {
            return $default;
        }
        if (\strlen($text) <= 15 && strspn($text, '0123456789') === \strlen($text)) {
            return (int) $text;
        }
        $flags |= 1;

        return $default;
    }

    /** Wave 67.5 level filter bits, in {@see LogEntryParser::LEVELS} order. */
    private const array W67_LEVEL_BITS = ['debug' => 1, 'info' => 2, 'notice' => 4, 'warning' => 8, 'error' => 16, 'critical' => 32, 'alert' => 64, 'emergency' => 128];

    /** Wave 67.5 channel name bytes (Monolog `[\w.\-]`). */
    private const string W67_CHANNEL_BYTES = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_.-';

    /**
     * Batch search routing plan over `term\tregex\tlevels\tchannels\tindex_available\tcaught_up\tbefore\tlimit\tfrom\tto`,
     * levels and channels comma-separated. Eight words per record: `[engine, term_tokens, level_mask, channel_count,
     * limit, before, window, flags]`. Numbers are trimmed 1-15 ASCII digits; empty or invalid ones take their default
     * (0, before -1, limit 50, from/to -1); regex, index_available and caught_up are true when non-zero. Term tokens
     * are maximal runs of ASCII alphanumerics, `_` and non-ASCII bytes. The level mask ORs W67_LEVEL_BITS of the
     * trimmed, non-empty levels; channel_count counts distinct trimmed, non-empty channels. The limit is clamped to
     * 1-500. The window is `to - from + 1` seconds when both bounds are set (0 when inverted), else -1. Engine: 0 no
     * search (invalid level/channel filter or inverted window), 1 Manticore (index available, caught up and no
     * term or one with a {@see LogIndexQuery::matchExpression()} part: edge tokens need MIN_INFIX_LENGTH bytes),
     * 3 PCRE file scan for regex, 2 fixed-string file scan. Flags: 1 invalid number, 2 not exactly ten fields,
     * 4 unknown level, 8 channel outside `[A-Za-z0-9_.-]`, 16 limit clamped, 32 inverted window, 64 plain term
     * without a match expression, 128 regex.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w67LogSearchCoordinatorPlanBatch(array $records): array
    {
        return self::usesW67RustFfi() ? self::w67LogSearchCoordinatorPlanBatchNative($records) : self::w67LogSearchCoordinatorPlanBatchPhp($records);
    }

    /**
     * Rust batch over the NUL-joined records; falls back to PHP without the library or when a record holds
     * a NUL.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w67LogSearchCoordinatorPlanBatchNative(array $records): array
    {
        $count = \count($records);
        if ($count === 0) {
            return [];
        }

        return self::w67Words($count, implode("\0", $records), 8 * $count) ?? self::w67LogSearchCoordinatorPlanBatchPhp($records);
    }

    /**
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w67LogSearchCoordinatorPlanBatchPhp(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $fields = explode("\t", $record);
            $flags = \count($fields) === 10 ? 0 : 2;
            $term = $fields[0];
            $regex = self::w67Number($fields[1] ?? '', 0, $flags) !== 0;
            $levelMask = 0;
            foreach (explode(',', $fields[2] ?? '') as $level) {
                $level = trim($level);
                if ($level === '') {
                    continue;
                }
                $bit = self::W67_LEVEL_BITS[$level] ?? 0;
                if ($bit === 0) {
                    $flags |= 4;
                }
                $levelMask |= $bit;
            }
            $channels = [];
            foreach (explode(',', $fields[3] ?? '') as $channel) {
                $channel = trim($channel);
                if ($channel === '') {
                    continue;
                }
                if (strspn($channel, self::W67_CHANNEL_BYTES) !== \strlen($channel)) {
                    $flags |= 8;
                }
                $channels[$channel] = true;
            }
            $available = self::w67Number($fields[4] ?? '', 0, $flags) !== 0;
            $caughtUp = self::w67Number($fields[5] ?? '', 0, $flags) !== 0;
            $before = self::w67Number($fields[6] ?? '', -1, $flags);
            $limit = self::w67Number($fields[7] ?? '', 50, $flags);
            if ($limit < 1 || $limit > 500) {
                $flags |= 16;
                $limit = max(1, min(500, $limit));
            }
            $from = self::w67Number($fields[8] ?? '', -1, $flags);
            $to = self::w67Number($fields[9] ?? '', -1, $flags);
            $window = -1;
            if ($from >= 0 && $to >= 0) {
                if ($from > $to) {
                    $flags |= 32;
                    $window = 0;
                } else {
                    $window = $to - $from + 1;
                }
            }
            $tokens = $parts = 0;
            if ($term !== '') {
                $words = preg_split('/[^A-Za-z0-9_\x80-\xff]+/', $term, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_OFFSET_CAPTURE) ?: [];
                $tokens = \count($words);
                foreach ($words as $index => [$word, $at]) {
                    $edge = ($index === 0 && $at === 0) || ($index === $tokens - 1 && $at + \strlen($word) === \strlen($term));
                    if (! $edge || \strlen($word) >= LogIndexQuery::MIN_INFIX_LENGTH) {
                        $parts++;
                    }
                }
            }
            if ($regex) {
                $flags |= 128;
            } elseif ($term !== '' && $parts === 0) {
                $flags |= 64;
            }
            $engine = match (true) {
                ($flags & 44) !== 0 => 0,
                $available && $caughtUp && ($flags & 192) === 0 => 1,
                $regex => 3,
                default => 2,
            };
            array_push($out, $engine, $tokens, $levelMask, \count($channels), $limit, $before, $window, $flags);
        }

        return $out;
    }
}
