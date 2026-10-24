<?php

declare(strict_types=1);

namespace App\Services\LogViewer\Index;

use App\Support\RustPipelineLibrary;
use FFI;
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

    private static bool $useRustFfi = true;

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

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
}
