<?php

declare(strict_types=1);

namespace App\Services\LogViewer\Index;

use App\Models\LogIndexFile;
use App\Services\LogViewer\LogEntry;
use App\Services\LogViewer\LogEntryParser;
use App\Services\LogViewer\LogFile;
use App\Services\LogViewer\LogReader;
use App\Services\LogViewer\LogSearcher;
use App\Services\LogViewer\SearchQuery;
use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

/**
 * Answers log searches from the Manticore log index, with the same per-file result shape as
 * {@see LogSearcher}.
 *
 * Manticore supplies newest-first candidates; each is re-checked with {@see SearchQuery::matches()} so
 * results keep grep's substring and case semantics. The part of the file written since the last indexer
 * run (at most the lag tolerance) is scanned directly, so the newest entries are never missing.
 */
class ManticoreLogSearcher
{
    private const int CANDIDATE_FACTOR = 2;

    private const int MAX_ROUNDS = 5;

    private const int LEVEL_FACET_SIZE = 20;

    private const int CHANNEL_FACET_SIZE = 200;

    /**
     * Benchmark-gated default for {@see scoreHitBatch()}: stays PHP because mean and p99 stayed near 1.4x
     * against memchr-backed strpos/stripos; see
     * tests/Unit/RustLogViewerIndexIndexerCoordinatorManticoreSearcherW47ParityTest.php.
     */
    public const bool W47_MANTICORE_LOG_SEARCHER_RUST_FFI_DEFAULT = false;

    /** Occurrences counted per string, as `SearchQuery` caps highlights. */
    private const int W47_MAX_OCCURRENCES = 200;

    private static ?bool $useW47ManticoreLogSearcherRustFfi = null;

    public function __construct(
        private readonly LogIndex $index,
        private readonly LogReader $reader,
        private readonly LogEntryParser $parser,
    ) {}

    /**
     * @return array{file: string, total_matches: int, total_is_estimate: bool, has_more: bool, before: int|null, timed_out: bool, error: string|null, entries: list<array<string, mixed>>}
     */
    public function searchFile(SearchQuery $query, LogFile $file, LogIndexFile $row, ?int $before, int $limit): array
    {
        $tail = $this->tailMatches($query, $file, $row, $before);
        $shown = array_slice($tail, 0, $limit);
        $cursor = $shown === [] ? null : $shown[array_key_last($shown)]->offset;

        $indexedBefore = $before === null ? $row->indexed_offset : min($before, $row->indexed_offset);
        $indexed = $this->indexedMatches($query, $row, $indexedBefore, $limit - count($shown));

        $hasMore = count($tail) > count($shown) || $indexed['has_more'];

        if ($indexed['cursor'] !== null) {
            $cursor = $indexed['cursor'];
        }

        return [
            'file' => $file->path,
            'total_matches' => count($tail) + $indexed['total'],
            'total_is_estimate' => ! $indexed['exact'],
            'has_more' => $hasMore,
            'before' => $hasMore ? $cursor : null,
            'timed_out' => false,
            'error' => null,
            'entries' => array_map(
                static fn (LogEntry $entry): array => $entry->toArray($query),
                [...$shown, ...$indexed['entries']],
            ),
        ];
    }

    /**
     * Level and channel counts over the indexed part of the given files. Each facet ignores its own
     * filter, so the other values stay visible while some are selected.
     *
     * @param  list<LogIndexFile>  $rows
     * @return array{levels: array<string, int>, channels: array<string, int>}
     */
    public function facets(SearchQuery $query, array $rows): array
    {
        if ($rows === []) {
            return ['levels' => [], 'channels' => []];
        }

        $files = ['in' => ['file_id' => array_map(static fn (LogIndexFile $row): int => $row->id, $rows)]];

        return [
            'levels' => $this->index->facet(['bool' => ['must' => [...LogIndexQuery::clauses($query, withLevels: false), $files]]], 'level', self::LEVEL_FACET_SIZE),
            'channels' => $this->index->facet(['bool' => ['must' => [...LogIndexQuery::clauses($query, withChannels: false), $files]]], 'channel', self::CHANNEL_FACET_SIZE),
        ];
    }

    /**
     * Matching entries that start before `$before` in the region the index already covers, newest first.
     *
     * @return array{entries: list<LogEntry>, total: int, exact: bool, has_more: bool, cursor: int|null}
     */
    private function indexedMatches(SearchQuery $query, LogIndexFile $row, int $before, int $want): array
    {
        $clauses = [...LogIndexQuery::clauses($query), ['equals' => ['file_id' => $row->id]]];
        $verify = $query->hasTerm();

        if ($want <= 0) {
            $result = $this->index->search(['bool' => ['must' => [...$clauses, $this->below($before)]]], [['byte_offset' => 'desc']], 1);

            return ['entries' => [], 'total' => $result['total'], 'exact' => $result['exact'] && ! $verify, 'has_more' => $result['total'] > 0, 'cursor' => null];
        }

        $fetch = $verify ? $want * self::CANDIDATE_FACTOR : $want;
        $cursor = $before;
        $entries = [];
        $total = 0;
        $exact = true;
        $exhausted = false;

        for ($round = 0; $round < self::MAX_ROUNDS && count($entries) < $want; $round++) {
            $result = $this->index->search(['bool' => ['must' => [...$clauses, $this->below($cursor)]]], [['byte_offset' => 'desc']], $fetch);

            if ($round === 0) {
                $total = $result['total'];
                $exact = $result['exact'];
            }

            $hits = $result['hits'];

            foreach ($hits as $position => $hit) {
                $cursor = (int) $hit['byte_offset'];
                $entry = $this->entryFromHit($hit);

                if (! $query->acceptsEntry($entry) || ($verify && ! $this->textMatches($query, (string) ($hit['first_line'] ?? ''), $entry->body))) {
                    continue;
                }

                $entries[] = $entry;

                if (count($entries) >= $want) {
                    $exhausted = $position === count($hits) - 1 && count($hits) < $fetch;

                    break 2;
                }
            }

            if (count($hits) < $fetch) {
                $exhausted = true;

                break;
            }
        }

        if ($exhausted && $verify) {
            // Every candidate was re-checked, so the verified count is exact.
            return ['entries' => $entries, 'total' => count($entries), 'exact' => true, 'has_more' => false, 'cursor' => null];
        }

        return [
            'entries' => $entries,
            'total' => $total,
            'exact' => $exact && ! $verify,
            'has_more' => ! $exhausted,
            'cursor' => $exhausted ? null : $cursor,
        ];
    }

    /**
     * Matching entries written after the indexed offset (and before `$before`), newest first.
     *
     * @return list<LogEntry>
     */
    private function tailMatches(SearchQuery $query, LogFile $file, LogIndexFile $row, ?int $before): array
    {
        $end = min($before ?? PHP_INT_MAX, $file->size);

        if ($row->indexed_offset >= $end) {
            return [];
        }

        $matches = [];

        foreach ($this->reader->entriesForward($file, $row->indexed_offset, $row->indexed_line, PHP_INT_MAX, true) as [$entry, $firstLine]) {
            if ($entry->offset >= $end) {
                break;
            }

            if ($query->acceptsEntry($entry) && (! $query->hasTerm() || $this->textMatches($query, $firstLine, $entry->body))) {
                $matches[] = $entry;
            }
        }

        return array_reverse($matches);
    }

    private function textMatches(SearchQuery $query, string $firstLine, string $body): bool
    {
        return $query->matches($body === '' ? $firstLine : $firstLine."\n".$body);
    }

    /**
     * @param  array<string, mixed>  $hit
     */
    private function entryFromHit(array $hit): LogEntry
    {
        [$offset, $end, $line] = self::hitPosition($hit);

        return $this->parser->make(
            $offset,
            $end,
            (string) ($hit['first_line'] ?? ''),
            (string) ($hit['body'] ?? ''),
            (bool) ($hit['truncated'] ?? false),
            (bool) ($hit['structured'] ?? false),
            $line,
        );
    }

    /**
     * Byte offset, byte end and positive line number of an index hit. Rust is opt-in.
     *
     * @param  array<string, mixed>  $hit
     * @return array{int, int, int|null}
     */
    public static function hitPosition(array $hit, bool $useRustFfi = false): array
    {
        return ($useRustFfi ? self::hitPositionNative($hit) : null) ?? self::hitPositionPhp($hit);
    }

    /**
     * @param  array<string, mixed>  $hit
     * @return array{int, int, int|null}
     */
    public static function hitPositionPhp(array $hit): array
    {
        $line = (int) ($hit['line_no'] ?? 0);

        return [(int) $hit['byte_offset'], (int) ($hit['byte_end'] ?? $hit['byte_offset']), $line > 0 ? $line : null];
    }

    /**
     * Native casts for integer (or absent) hit fields; null defers other types to PHP.
     *
     * @param  array<string, mixed>  $hit
     * @return array{int, int, int|null}|null
     */
    public static function hitPositionNative(array $hit): ?array
    {
        $offset = $hit['byte_offset'] ?? null;
        $end = $hit['byte_end'] ?? null;
        $line = $hit['line_no'] ?? null;
        if (! is_int($offset) || ($end !== null && ! is_int($end)) || ($line !== null && ! is_int($line))) {
            return null;
        }
        static $ffi, $slots;
        $ffi ??= RustPipelineLibrary::resolveDomain('search', 'int32_t nntmux_log_hit_position(int64_t offset, int64_t end, uint32_t has_end, int64_t line, int64_t *out);') ?? false;
        if ($ffi === false) {
            return null;
        }
        try {
            $slots ??= $ffi->new('int64_t[3]');
            if ($ffi->nntmux_log_hit_position($offset, $end ?? 0, $end === null ? 0 : 1, $line ?? 0, $slots) !== 0) {
                return null;
            }
            $lineNumber = (int) $slots[2];

            return [(int) $slots[0], (int) $slots[1], $lineNumber > 0 ? $lineNumber : null];
        } catch (Throwable) {
            return null;
        }
    }

    /** Pass null to restore {@see W47_MANTICORE_LOG_SEARCHER_RUST_FFI_DEFAULT}. */
    public static function useW47ManticoreLogSearcherRustFfi(?bool $enable = true): void
    {
        self::$useW47ManticoreLogSearcherRustFfi = $enable;
    }

    public static function usesW47ManticoreLogSearcherRustFfi(): bool
    {
        return self::$useW47ManticoreLogSearcherRustFfi ?? self::W47_MANTICORE_LOG_SEARCHER_RUST_FFI_DEFAULT;
    }

    public static function resetW47ManticoreLogSearcherRustFfi(): void
    {
        self::$useW47ManticoreLogSearcherRustFfi = null;
    }

    /**
     * Re-checks and scores index hits `[byte_offset, byte_end, line_no, level, channel, first_line, body]`.
     * Per hit: `[offset, end, line, accepted, matched, score]` — the {@see hitPosition()} line (0 when not
     * positive), whether the level and channel filters accept it (empty filters accept all), whether the
     * term occurs in the entry text as {@see textMatches()} builds it (always for an empty term) and a
     * score of 4 per first-line occurrence plus 1 per body occurrence (each capped at 200, ASCII case
     * folding unless $caseSensitive).
     *
     * @param  list<array{int, int, int, string, string, string, string}>  $hits
     * @param  list<string>  $levels
     * @param  list<string>  $channels
     * @return list<list<int>>
     */
    public static function scoreHitBatch(array $hits, string $term, bool $caseSensitive, array $levels, array $channels): array
    {
        return self::usesW47ManticoreLogSearcherRustFfi()
            ? self::scoreHitBatchNative($hits, $term, $caseSensitive, $levels, $channels)
            : self::scoreHitBatchPhp($hits, $term, $caseSensitive, $levels, $channels);
    }

    /**
     * @param  list<array{int, int, int, string, string, string, string}>  $hits
     * @param  list<string>  $levels
     * @param  list<string>  $channels
     * @return list<list<int>>
     */
    public static function scoreHitBatchPhp(array $hits, string $term, bool $caseSensitive, array $levels, array $channels): array
    {
        $rows = [];

        foreach ($hits as [$offset, $end, $line, $level, $channel, $firstLine, $body]) {
            $accepted = ($levels === [] || \in_array($level, $levels, true)) && ($channels === [] || \in_array($channel, $channels, true));

            if ($term === '') {
                $matched = 1;
                $score = 0;
            } else {
                $text = $body === '' ? $firstLine : $firstLine."\n".$body;
                $matched = ($caseSensitive ? str_contains($text, $term) : stripos($text, $term) !== false) ? 1 : 0;
                $score = 4 * self::w47Occurrences($firstLine, $term, $caseSensitive) + self::w47Occurrences($body, $term, $caseSensitive);
            }

            $rows[] = [$offset, $end, $line > 0 ? $line : 0, $accepted ? 1 : 0, $matched, $score];
        }

        return $rows;
    }

    /**
     * @param  list<array{int, int, int, string, string, string, string}>  $hits
     * @param  list<string>  $levels
     * @param  list<string>  $channels
     * @return list<list<int>>
     */
    public static function scoreHitBatchNative(array $hits, string $term, bool $caseSensitive, array $levels, array $channels): array
    {
        $count = \count($hits);

        if ($count === 0) {
            return [];
        }

        $columns = [];

        for ($column = 0; $column < 7; $column++) {
            $columns[$column] = array_column($hits, $column);

            if (\count($columns[$column]) !== $count) {
                return self::scoreHitBatchPhp($hits, $term, $caseSensitive, $levels, $channels);
            }
        }

        $strings = $term;

        foreach ([$levels, $channels, $columns[3], $columns[4], $columns[5], $columns[6]] as $list) {
            if ($list !== []) {
                $strings .= "\0".implode("\0", $list);
            }
        }

        $input = pack('P*', $count, $caseSensitive ? 1 : 0, \count($levels), \count($channels), ...$columns[0], ...$columns[1], ...$columns[2]).$strings;
        $raw = LogIndex::evaluateW47Native(4, $input, 48 * $count);

        return $raw === null ? self::scoreHitBatchPhp($hits, $term, $caseSensitive, $levels, $channels) : LogIndex::w47Rows($raw, 6);
    }

    /**
     * Non-overlapping occurrences of a non-empty term, capped at {@see W47_MAX_OCCURRENCES}.
     */
    private static function w47Occurrences(string $text, string $term, bool $caseSensitive): int
    {
        $found = 0;
        $offset = 0;
        $length = \strlen($term);

        while ($found < self::W47_MAX_OCCURRENCES) {
            $position = $caseSensitive ? strpos($text, $term, $offset) : stripos($text, $term, $offset);

            if ($position === false) {
                break;
            }

            $found++;
            $offset = $position + $length;
        }

        return $found;
    }

    /**
     * @return array<string, mixed>
     */
    private function below(int $offset): array
    {
        return ['range' => ['byte_offset' => ['lt' => $offset]]];
    }

    public const string W55_FFI_CDEF = 'int64_t nntmux_w555_manticore_hit_text_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W55.5 parity test's QUALIFICATION). */
    private const bool W55_RUST_FFI_DEFAULT = true;

    /**
     * Wave 55.5 routing and FFI cache, kept in function-local statics. Without $op it reports the
     * routing; 'set' swaps the override for $val and returns the previous one; 'reset' replaces the cached
     * handle with $val (null re-resolves, false disables); 'buffer' returns `[FFI, int64_t[], capacity]`
     * with room for at least $val words, or null without the library.
     */
    private static function w55RustFfiState(?string $op = null, mixed $val = null): mixed
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
                    $ffi = RustPipelineLibrary::resolveDomain('w55_5', self::W55_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
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
                return $enabled ?? self::W55_RUST_FFI_DEFAULT;
        }
    }

    public static function usesW55RustFfi(): bool
    {
        return self::w55RustFfiState() === true;
    }

    /**
     * Runs $callback with hitTextPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW55RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w55RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w55RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 55.5 Rust helper over $count NUL-joined records, or null
     * to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w55Words(int $count, string $data, int $words): ?array
    {
        try {
            $handle = self::w55RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w555_manticore_hit_text_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
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
     * Batch summary of index hit texts (the first line, then "\n" and the body when present). Four words per
     * text: `[lines (0 when empty), first line length, stack frames (lines starting `#\d+ `), flags]`. Flags:
     * 1 non-ASCII byte, 2 first line ends (ignoring trailing spaces, tabs and "\r") with `}` or `]` (JSON
     * context), 4 holds `[stacktrace]`, 8 holds "\r".
     *
     * @param  list<string>  $texts
     * @return list<int>
     */
    public static function hitTextPlanBatch(array $texts): array
    {
        return self::usesW55RustFfi() ? self::hitTextPlanBatchNative($texts) : self::hitTextPlanBatchPhp($texts);
    }

    /**
     * @param  list<string>  $texts
     * @return list<int>
     */
    public static function hitTextPlanBatchPhp(array $texts): array
    {
        $out = [];
        foreach ($texts as $text) {
            $first = strcspn($text, "\n");
            $head = rtrim(substr($text, 0, $first), " \t\r");
            $last = $head === '' ? '' : $head[-1];
            $flags = (preg_match('/[\x80-\xff]/', $text) === 1 ? 1 : 0)
                | ($last === '}' || $last === ']' ? 2 : 0)
                | (str_contains($text, '[stacktrace]') ? 4 : 0)
                | (str_contains($text, "\r") ? 8 : 0);
            array_push($out, $text === '' ? 0 : substr_count($text, "\n") + 1, $first, (int) preg_match_all('/(?:^|\n)#\d+ /', $text), $flags);
        }

        return $out;
    }

    /**
     * Rust batch over the NUL-joined values; falls back to PHP without the library or when a value holds
     * a NUL.
     *
     * @param  list<string>  $texts
     * @return list<int>
     */
    public static function hitTextPlanBatchNative(array $texts): array
    {
        $count = \count($texts);
        if ($count === 0) {
            return [];
        }

        return self::w55Words($count, implode("\0", $texts), 4 * $count) ?? self::hitTextPlanBatchPhp($texts);
    }
}
