<?php

declare(strict_types=1);

namespace App\Services\LogViewer\Index;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Manticoresearch\Client;
use Throwable;

/**
 * The Manticore RT table holding one document per log entry, keyed `(file_id << 40) | byte_offset`.
 *
 * The table is independent of the release search tables (and of `SEARCH_DRIVER`): it is created here,
 * not by `manticore:create-indexes`, so rebuilding the search tables never wipes the log index.
 */
class LogIndex
{
    public const int OFFSET_BITS = 40;

    private const int AVAILABILITY_TTL_SECONDS = 30;

    /**
     * Rust parity exports; both helpers stay in PHP (below the 1.5x adoption gate, see
     * `tests/Unit/RustLogViewerAndAuditParserParityTest.php`).
     */
    public const string FFI_CDEF = <<<'C'
        int64_t nntmux_logviewer_document_id(int64_t file_id, int64_t offset);
        int32_t nntmux_logviewer_valid_table_name(const char *name, size_t len);
        C;

    /**
     * Wave 47.5 batch export shared by LogIndex, LogIndexer, LogSearchCoordinator, ManticoreLogSearcher
     * and LogSearcher; every mode returns little-endian i64 fields.
     */
    public const string W47_FFI_CDEF = 'int64_t nntmux_w47_logviewer_index_indexer_coordinator_manticore_searcher(uint32_t mode, const char *input, size_t input_len, char *out, size_t cap);';

    /**
     * Benchmark-gated default for {@see indexEntryRangeBatch()}: stays PHP because common p99 missed
     * 1.5x (packing tuples and unpacking rows costs about as much as the PHP loop); see
     * tests/Unit/RustLogViewerIndexIndexerCoordinatorManticoreSearcherW47ParityTest.php.
     */
    public const bool W47_LOG_INDEX_RUST_FFI_DEFAULT = false;

    private const int W47_MAX_BYTES = 67108864;

    private static bool $useRustFfi = true;

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

    private static ?bool $useW47LogIndexRustFfi = null;

    private static FFI|false|null $w47Ffi = null;

    private static ?CData $w47Out = null;

    private static int $w47OutCap = 0;

    public function __construct(
        private readonly Client $client,
        private readonly string $table,
        private readonly bool $enabled,
    ) {
        if (! self::isSafeTableName($table)) {
            throw new InvalidArgumentException('Unsafe Manticore log index table name.');
        }
    }

    /**
     * @return array{settings: array<string, int>, columns: array<string, array{type: string}>}
     */
    public static function definition(): array
    {
        return [
            'settings' => ['min_infix_len' => LogIndexQuery::MIN_INFIX_LENGTH, 'index_field_lengths' => 0],
            'columns' => [
                'file_id' => ['type' => 'bigint'],
                'byte_offset' => ['type' => 'bigint'],
                'byte_end' => ['type' => 'bigint'],
                'line_no' => ['type' => 'bigint'],
                'logged_at' => ['type' => 'bigint'],
                'level' => ['type' => 'string'],
                'channel' => ['type' => 'string'],
                'structured' => ['type' => 'integer'],
                'truncated' => ['type' => 'integer'],
                'first_line' => ['type' => 'text'],
                'body' => ['type' => 'text'],
            ],
        ];
    }

    public static function documentId(int $fileId, int $offset): int
    {
        return ($fileId << self::OFFSET_BITS) | $offset;
    }

    /**
     * Rust parity helper for {@see documentId()}; null when the library is unavailable or disabled.
     */
    public static function documentIdNative(int $fileId, int $offset): ?int
    {
        $ffi = self::ffi();

        if ($ffi === null) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $id = $ffi->nntmux_logviewer_document_id($fileId, $offset);
        } catch (Throwable) {
            return null;
        }

        return is_int($id) ? $id : null;
    }

    public static function isSafeTableName(string $table): bool
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $table) === 1;
    }

    /**
     * Rust parity helper for {@see isSafeTableName()}; null when the library is unavailable or disabled.
     */
    public static function isSafeTableNameNative(string $table): ?bool
    {
        $ffi = self::ffi();

        if ($ffi === null) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $valid = $ffi->nntmux_logviewer_valid_table_name($table, strlen($table));
        } catch (Throwable) {
            return null;
        }

        return is_int($valid) && $valid >= 0 ? $valid === 1 : null;
    }

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    private static function ffi(): ?FFI
    {
        if (! self::$useRustFfi) {
            return null;
        }

        if (! self::$ffiResolved) {
            self::$ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF);
            self::$ffiResolved = true;
        }

        return self::$ffi;
    }

    /** Pass null to restore {@see W47_LOG_INDEX_RUST_FFI_DEFAULT}. */
    public static function useW47LogIndexRustFfi(?bool $enable = true): void
    {
        self::$useW47LogIndexRustFfi = $enable;
    }

    public static function usesW47LogIndexRustFfi(): bool
    {
        return self::$useW47LogIndexRustFfi ?? self::W47_LOG_INDEX_RUST_FFI_DEFAULT;
    }

    public static function resetW47LogIndexRustFfi(): void
    {
        self::$useW47LogIndexRustFfi = null;
    }

    /**
     * Validates index entries `[file_id, byte_offset, byte_end, logged_at]` (in file order) and bounds
     * the `before` cursors over them. Each entry gets its {@see documentId()} and flags: 1 offset fits
     * the 40 offset bits, 2 end >= offset, 4 file id keeps the id positive, 8 starts at or after the
     * previous entry's end, 16 has a timestamp; flags 1-8 make it valid. Each cursor gets the binary-search
     * lower bound over the entry offsets, the valid entries below it and their newest `logged_at` (or 0).
     *
     * @param  list<array{int, int, int, int}>  $entries
     * @param  list<int>  $cursors
     * @return array{entries: list<list<int>>, ranges: list<list<int>>}
     */
    public static function indexEntryRangeBatch(array $entries, array $cursors): array
    {
        return self::usesW47LogIndexRustFfi() ? self::indexEntryRangeBatchNative($entries, $cursors) : self::indexEntryRangeBatchPhp($entries, $cursors);
    }

    /**
     * @param  list<array{int, int, int, int}>  $entries
     * @param  list<int>  $cursors
     * @return array{entries: list<list<int>>, ranges: list<list<int>>}
     */
    public static function indexEntryRangeBatchPhp(array $entries, array $cursors): array
    {
        $rows = [];
        $offsets = [];
        $validBelow = [0];
        $newestBelow = [0];
        $valid = 0;
        $newest = 0;
        $previousEnd = null;

        foreach ($entries as [$fileId, $offset, $end, $loggedAt]) {
            $flags = ($offset >= 0 && $offset < 1 << self::OFFSET_BITS ? 1 : 0)
                | ($end >= $offset ? 2 : 0)
                | ($fileId >= 0 && $fileId < 1 << (63 - self::OFFSET_BITS) ? 4 : 0)
                | ($previousEnd === null || $offset >= $previousEnd ? 8 : 0)
                | ($loggedAt > 0 ? 16 : 0);
            $previousEnd = $end;

            if (($flags & 15) === 15) {
                $valid++;
                $newest = max($newest, $loggedAt);
            }

            $validBelow[] = $valid;
            $newestBelow[] = $newest;
            $offsets[] = $offset;
            $rows[] = [($fileId << self::OFFSET_BITS) | $offset, $flags];
        }

        $ranges = [];
        $count = \count($offsets);

        foreach ($cursors as $cursor) {
            $low = 0;
            $high = $count;

            while ($low < $high) {
                $middle = ($low + $high) >> 1;

                if ($offsets[$middle] < $cursor) {
                    $low = $middle + 1;
                } else {
                    $high = $middle;
                }
            }

            $ranges[] = [$low, $validBelow[$low], $newestBelow[$low]];
        }

        return ['entries' => $rows, 'ranges' => $ranges];
    }

    /**
     * @param  list<array{int, int, int, int}>  $entries
     * @param  list<int>  $cursors
     * @return array{entries: list<list<int>>, ranges: list<list<int>>}
     */
    public static function indexEntryRangeBatchNative(array $entries, array $cursors): array
    {
        $count = \count($entries);
        $flat = $count === 0 ? [] : array_merge(...$entries);

        if (\count($flat) !== 4 * $count) {
            return self::indexEntryRangeBatchPhp($entries, $cursors);
        }

        $raw = self::evaluateW47Native(1, pack('P*', $count, \count($cursors), ...$flat, ...$cursors), 16 * $count + 24 * \count($cursors));

        if ($raw === null) {
            return self::indexEntryRangeBatchPhp($entries, $cursors);
        }

        return [
            'entries' => self::w47Rows(substr($raw, 0, 16 * $count), 2),
            'ranges' => self::w47Rows($raw, 3, 16 * $count),
        ];
    }

    /**
     * Exactly $fields little-endian i64 values from a Wave 47.5 mode, or null to fall back to PHP.
     *
     * @return list<int>|null
     */
    public static function evaluateW47Fields(int $mode, string $input, int $fields): ?array
    {
        $raw = $fields < 0 ? null : self::evaluateW47Native($mode, $input, $fields * 8);
        $values = $raw === null ? false : ($raw === '' ? [] : unpack('P*', $raw));

        /** @var list<int>|null */
        return \is_array($values) ? array_values($values) : null;
    }

    /**
     * Exactly $bytes of output (packed little-endian i64 fields) from a Wave 47.5 mode, or null to fall
     * back to PHP.
     */
    public static function evaluateW47Native(int $mode, string $input, int $bytes): ?string
    {
        $ffi = self::w47Ffi();
        $length = \strlen($input);

        if ($ffi === null || $mode < 1 || $bytes < 0 || $bytes > self::W47_MAX_BYTES || $length > self::W47_MAX_BYTES) {
            return null;
        }

        try {
            if (self::$w47Out === null || $bytes > self::$w47OutCap) {
                $cap = max(4096, $bytes);
                self::$w47Out = $ffi->new('char['.$cap.']');
                self::$w47OutCap = $cap;
            }

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w47_logviewer_index_indexer_coordinator_manticore_searcher($mode, $input, $length, self::$w47Out, self::$w47OutCap);

            if ($written !== $bytes) {
                return null;
            }

            return $bytes === 0 ? '' : FFI::string(self::$w47Out, $bytes);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Rows of $width fields from packed output.
     *
     * @return list<list<int>>
     */
    public static function w47Rows(string $raw, int $width, int $offset = 0): array
    {
        $values = \strlen($raw) > $offset ? unpack('P*', $raw, $offset) : [];

        /** @var list<list<int>> */
        return \is_array($values) ? array_chunk($values, $width) : [];
    }

    /**
     * NUL-joined strings; Rust declines the batch when a string holds a NUL (the piece count disagrees).
     *
     * @param  list<string>  $strings
     */
    public static function w47Joined(array $strings): string
    {
        return implode("\0", $strings);
    }

    private static function w47Ffi(): ?FFI
    {
        if (self::$w47Ffi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            self::$w47Ffi = RustPipelineLibrary::resolveDomain('search_w47', self::W47_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
            self::$w47Out = null;
            self::$w47OutCap = 0;
        }

        return self::$w47Ffi instanceof FFI ? self::$w47Ffi : null;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Whether the index is enabled and Manticore answers; cached briefly so page loads do not each probe it.
     */
    public function isAvailable(): bool
    {
        if (! $this->enabled) {
            return false;
        }

        return (bool) Cache::remember('log_viewer:index:available', self::AVAILABILITY_TTL_SECONDS, function (): bool {
            try {
                $this->client->nodes()->status();

                return true;
            } catch (Throwable) {
                return false;
            }
        });
    }

    public function ensureTable(): void
    {
        $this->client->tables()->create(['index' => $this->table, 'body' => [...self::definition(), 'silent' => true]]);
    }

    public function drop(): void
    {
        $this->client->tables()->drop(['index' => $this->table, 'body' => ['silent' => true]]);
    }

    public function count(): int
    {
        $response = $this->client->sql("SELECT COUNT(*) AS total FROM `{$this->table}`", true);

        foreach (is_array($response) ? $response : [] as $value) {
            if (is_numeric($value)) {
                return (int) $value;
            }

            if (is_array($value) && isset($value['total']) && is_numeric($value['total'])) {
                return (int) $value['total'];
            }
        }

        return 0;
    }

    /**
     * @param  list<array<string, int|string>>  $documents  each with an `id`
     */
    public function replace(array $documents): void
    {
        if ($documents === []) {
            return;
        }

        $body = [];

        foreach ($documents as $document) {
            $id = $document['id'];
            unset($document['id']);
            $body[] = ['replace' => ['table' => $this->table, 'id' => $id, 'doc' => $document]];
        }

        $this->client->bulk(['body' => $body]);
    }

    public function purgeFile(int $fileId): void
    {
        $this->client->delete(['body' => ['table' => $this->table, 'query' => ['equals' => ['file_id' => $fileId]]]]);
    }

    /**
     * @param  array<string, mixed>  $query  a Manticore JSON query
     * @param  list<array<string, string>>  $sort
     * @return array{total: int, exact: bool, hits: list<array<string, mixed>>}
     */
    public function search(array $query, array $sort, int $limit): array
    {
        $response = $this->client->search(['body' => [
            'table' => $this->table,
            'query' => $query,
            'sort' => $sort,
            'limit' => $limit,
            'max_matches' => max(1000, $limit),
        ]]);

        $hits = [];

        foreach ((array) ($response['hits']['hits'] ?? []) as $hit) {
            $hits[] = ['id' => (int) ($hit['_id'] ?? 0), ...(array) ($hit['_source'] ?? [])];
        }

        return [
            'total' => (int) ($response['hits']['total'] ?? 0),
            'exact' => ($response['hits']['total_relation'] ?? 'eq') === 'eq',
            'hits' => $hits,
        ];
    }

    /**
     * Document counts per value of a string attribute (empty values are left out).
     *
     * @param  array<string, mixed>  $query
     * @return array<string, int>
     */
    public function facet(array $query, string $field, int $size): array
    {
        $response = $this->client->search(['body' => [
            'table' => $this->table,
            'query' => $query,
            'limit' => 0,
            'aggs' => [$field => ['terms' => ['field' => $field, 'size' => $size]]],
        ]]);

        $counts = [];

        foreach ((array) ($response['aggregations'][$field]['buckets'] ?? []) as $bucket) {
            $key = (string) ($bucket['key'] ?? '');

            if ($key !== '') {
                $counts[$key] = (int) ($bucket['doc_count'] ?? 0);
            }
        }

        return $counts;
    }

    public const string W54_FFI_CDEF = 'int64_t nntmux_w545_log_index_header_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W54.5 parity test's QUALIFICATION). */
    private const bool W54_RUST_FFI_DEFAULT = true;

    /** Index entry `level` ordinals of the PSR-3 levels. */
    private const array W54_LEVELS = ['debug' => 1, 'info' => 2, 'notice' => 3, 'warning' => 4, 'error' => 5, 'critical' => 6, 'alert' => 7, 'emergency' => 8];

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
     * Runs $callback with headerPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
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
            $written = $ffi->nntmux_w545_log_index_header_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
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
     * Batch parse of Laravel entry headers `[Y-m-d H:i:s...] channel.LEVEL: ` for index documents. Four
     * words per first line: `[logged_at (UTC), level (1 debug ... 8 emergency, 0 other), channel length,
     * message offset]`; `[0, 0, 0, 0]` without a header or with an out-of-range (or pre-1970) stamp.
     *
     * @param  list<string>  $lines
     * @return list<int>
     */
    public static function headerPlanBatch(array $lines): array
    {
        return self::usesW54RustFfi() ? self::headerPlanBatchNative($lines) : self::headerPlanBatchPhp($lines);
    }

    /**
     * @param  list<string>  $lines
     * @return list<int>
     */
    public static function headerPlanBatchPhp(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            if (preg_match('/^\[(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})[^\]]*\] (\w+)\.(\w+): /', $line, $m) !== 1) {
                array_push($out, 0, 0, 0, 0);

                continue;
            }
            [$year, $month, $day, $hour, $minute, $second] = [(int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5], (int) $m[6]];
            if ($year < 1970 || $month < 1 || $month > 12 || $day < 1 || $day > 31 || $hour > 23 || $minute > 59 || $second > 59) {
                array_push($out, 0, 0, 0, 0);

                continue;
            }
            array_push($out, (int) gmmktime($hour, $minute, $second, $month, $day, $year), self::W54_LEVELS[strtolower($m[8])] ?? 0, \strlen($m[7]), \strlen($m[0]));
        }

        return $out;
    }

    /**
     * Rust batch over the NUL-joined values; falls back to PHP without the library or when a value holds
     * a NUL.
     *
     * @param  list<string>  $lines
     * @return list<int>
     */
    public static function headerPlanBatchNative(array $lines): array
    {
        $count = \count($lines);
        if ($count === 0) {
            return [];
        }

        return self::w54Words($count, implode("\0", $lines), 4 * $count) ?? self::headerPlanBatchPhp($lines);
    }
}
