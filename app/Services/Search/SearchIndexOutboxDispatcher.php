<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Enums\SecondarySearchIndex;
use App\Facades\Search;
use App\Services\Search\Support\ElasticsearchResponseHelper;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\Log;
use JsonException;
use stdClass;
use Throwable;

/**
 * Dispatches compacted search outbox rows to the active search driver.
 *
 * The outbox is written outside PHP (database triggers), so this class accepts a
 * small set of action/entity aliases to keep the consumer tolerant of table and
 * model naming differences while still routing only to the existing Search API.
 */
final class SearchIndexOutboxDispatcher
{
    // Every candidate missed at least one 1.5x workload gate; see the parity benchmark.
    private static bool $useRustFfi = false;

    /**
     * Rust stays opt-in for the Wave 42.5 summary: the FFI round trip misses the 1.5x gate; see
     * tests/Unit/RustSearchOutboxElasticManticoreSupportW42ParityTest.php.
     */
    public const bool W425_RUST_FFI_DEFAULT = false;

    /** Normalized entity types {@see secondaryIndexFromEntityType()} routes to a secondary index. */
    private const array W425_SECONDARY_TYPES = [
        'music', 'musicinfo', 'music_info', 'book', 'books', 'bookinfo', 'book_info',
        'game', 'games', 'gamesinfo', 'games_info', 'console', 'consoleinfo', 'console_info',
        'steam', 'steamapp', 'steamapps', 'steam_app', 'steam_apps',
        'anime', 'anidb', 'anidbtitle', 'anidbtitles', 'anidb_title', 'anidb_titles', 'anidbinfo', 'anidb_info',
    ];

    private static ?bool $useW425RustFfi = null;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    /** Shared search-domain byte transform; null means unavailable or declined. */
    private static function searchSqlTransformNative(int $mode, string $input): ?string
    {
        $ffi = RustPipelineLibrary::resolveDomain('search', 'ssize_t nntmux_search_outbox_browse_sql(uint32_t mode, const char *input, size_t input_len, char *output, size_t output_cap);');
        if ($ffi === null) {
            return null;
        }
        $cap = 0;
        $out = RustPipelineLibrary::obtainScratch($ffi, max(64, strlen($input) + 128), $cap);
        if ($out === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $n = $ffi->nntmux_search_outbox_browse_sql($mode, $input, strlen($input), $out, $cap);
            if (is_int($n) && $n < -2) {
                $out = RustPipelineLibrary::obtainScratch($ffi, -$n, $cap);
                if ($out === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $n = $ffi->nntmux_search_outbox_browse_sql($mode, $input, strlen($input), $out, $cap);
            }

            return is_int($n) && $n >= 0 ? FFI::string($out, $n) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Keep only the latest row for each logical search document in a batch.
     *
     * @param  array<int, object>  $rows
     * @return array<int, object>
     */
    public static function compactRows(array $rows): array
    {
        return self::$useRustFfi ? self::compactRowsNative($rows) : self::compactRowsPhp($rows);
    }

    /** @param array<int, object> $rows
     * @return array<int, object>
     */
    public static function compactRowsNative(array $rows): array
    {
        // Decline before parsing payloads so fallback does not duplicate or reorder warnings.
        foreach ($rows as $row) {
            if (! self::supportsDocumentRow($row)) {
                return self::compactRowsPhp($rows);
            }
        }
        $input = '';
        $rows = array_values($rows);
        foreach ($rows as $row) {
            $input .= self::packDocumentRow($row);
            $id = pack('q', self::rowId($row));
            $input .= pack('V', strlen($id)).$id;
        }
        $result = self::searchSqlTransformNative(3, $input);
        if ($result === null) {
            return self::compactRowsPhp($rows);
        }

        return $result === '' ? [] : array_map(static fn (string $index): object => $rows[(int) $index], explode(',', $result));
    }

    /** @param array<int, object> $rows
     * @return array<int, object>
     */
    public static function compactRowsPhp(array $rows): array
    {
        usort(
            $rows,
            static fn (object $a, object $b): int => self::rowId($a) <=> self::rowId($b)
        );

        /** @var array<string, object> $compacted */
        $compacted = [];

        foreach ($rows as $row) {
            $compacted[self::documentKeyPhp($row)] = $row;
        }

        $latestRows = array_values($compacted);

        usort(
            $latestRows,
            static fn (object $a, object $b): int => self::rowId($a) <=> self::rowId($b)
        );

        return $latestRows;
    }

    /**
     * Dispatch one compacted outbox row.
     */
    public function dispatchRow(object $row): void
    {
        $payload = self::payload($row);
        $entityId = self::entityId($row, $payload);
        $entityType = self::normalizeEntityType($row->entity_type ?? '');
        $action = self::normalizeAction($row->action ?? '');

        if ($entityId <= 0) {
            Log::warning('Search outbox row skipped because it has no entity id', [
                'row_id' => self::rowId($row),
                'entity_type' => $row->entity_type ?? null,
                'action' => $row->action ?? null,
            ]);

            return;
        }

        match ($entityType) {
            'release' => $this->dispatchRelease($action, $entityId, $payload),
            'predb' => $this->dispatchPredb($action, $entityId, $payload),
            'movie' => $this->dispatchMovie($action, $entityId, $payload),
            'tvshow' => $this->dispatchTvShow($action, $entityId, $payload),
            'secondary' => $this->dispatchSecondaryRow($action, $entityId, $payload, $row),
            default => $this->dispatchSecondaryEntity($action, $entityType, $entityId, $payload, $row),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatchRelease(string $action, int $entityId, array $payload): void
    {
        match ($action) {
            'delete' => Search::deleteRelease($entityId),
            'insert', 'upsert' => empty($payload)
                ? Search::updateRelease($entityId)
                : Search::insertRelease(self::withId($payload, $entityId)),
            'update' => Search::updateRelease($entityId),
            default => self::logUnknownAction($action, 'release', $entityId),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatchPredb(string $action, int $entityId, array $payload): void
    {
        if ($action === 'delete') {
            Search::deletePreDb($entityId);

            return;
        }

        if (empty($payload)) {
            Log::warning('Search outbox predb row skipped because it has no payload', [
                'action' => $action,
                'entity_id' => $entityId,
            ]);

            return;
        }

        match ($action) {
            'insert', 'upsert' => Search::insertPredb(self::withId($payload, $entityId)),
            'update' => Search::updatePreDb(self::withId($payload, $entityId)),
            default => self::logUnknownAction($action, 'predb', $entityId),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatchMovie(string $action, int $entityId, array $payload): void
    {
        match ($action) {
            'delete' => Search::deleteMovie($entityId),
            'insert', 'upsert' => empty($payload)
                ? Search::updateMovie($entityId)
                : Search::insertMovie(self::withId($payload, $entityId)),
            'update' => Search::updateMovie($entityId),
            default => self::logUnknownAction($action, 'movie', $entityId),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatchTvShow(string $action, int $entityId, array $payload): void
    {
        match ($action) {
            'delete' => Search::deleteTvShow($entityId),
            'insert', 'upsert' => empty($payload)
                ? Search::updateTvShow($entityId)
                : Search::insertTvShow(self::withId($payload, $entityId)),
            'update' => Search::updateTvShow($entityId),
            default => self::logUnknownAction($action, 'tvshow', $entityId),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatchSecondaryRow(string $action, int $entityId, array $payload, object $row): void
    {
        $index = self::secondaryIndexFromPayload($payload);

        if ($index === null) {
            Log::warning('Search outbox secondary row skipped because it has no index', [
                'row_id' => self::rowId($row),
                'entity_id' => $entityId,
                'action' => $action,
            ]);

            return;
        }

        $this->dispatchSecondary($action, $index, $entityId, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatchSecondaryEntity(string $action, string $entityType, int $entityId, array $payload, object $row): void
    {
        $index = self::secondaryIndexFromEntityType($entityType);

        if ($index === null) {
            Log::warning('Search outbox row skipped because it has an unknown entity type', [
                'row_id' => self::rowId($row),
                'entity_type' => $row->entity_type ?? null,
                'normalized_entity_type' => $entityType,
                'entity_id' => $entityId,
                'action' => $action,
            ]);

            return;
        }

        $this->dispatchSecondary($action, $index, $entityId, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatchSecondary(string $action, SecondarySearchIndex $index, int $entityId, array $payload): void
    {
        if ($action === 'delete') {
            Search::deleteSecondary($index, $entityId);

            return;
        }

        $document = self::secondaryDocument($payload);

        match ($action) {
            'insert', 'upsert' => empty($document)
                ? Search::updateSecondary($index, $entityId)
                : Search::insertSecondary($index, $entityId, $document),
            'update' => empty($document)
                ? Search::updateSecondary($index, $entityId)
                : Search::insertSecondary($index, $entityId, $document),
            default => self::logUnknownAction($action, $index->value, $entityId),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(object $row): array
    {
        $payload = $row->payload ?? null;

        if ($payload === null || $payload === '') {
            return [];
        }

        if (is_array($payload)) {
            return $payload;
        }

        if ($payload instanceof stdClass) {
            return (array) $payload;
        }

        if (! is_string($payload)) {
            return [];
        }

        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            Log::warning('Search outbox row has invalid JSON payload', [
                'row_id' => self::rowId($row),
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function entityId(object $row, array $payload): int
    {
        $entityId = $row->entity_id ?? ($payload['id'] ?? null);

        return is_numeric($entityId) ? (int) $entityId : 0;
    }

    private static function rowId(object $row): int
    {
        $id = $row->id ?? 0;

        return is_numeric($id) ? (int) $id : 0;
    }

    public static function documentKey(object $row): string
    {
        return self::$useRustFfi ? self::documentKeyNative($row) : self::documentKeyPhp($row);
    }

    private static function supportsDocumentRow(object $row): bool
    {
        if ($row::class !== stdClass::class) {
            return false;
        }
        $rawType = $row->entity_type ?? '';
        if (! is_scalar($rawType)) {
            return false;
        }
        $type = (string) $rawType;
        $payload = $row->payload ?? null;

        return ! preg_match('/[\x80-\xff]/', $type)
            && (! is_string($payload) || $payload === '' || json_validate($payload));
    }

    private static function packDocumentRow(object $row): string
    {
        $payload = self::payload($row);
        $type = (string) ($row->entity_type ?? '');
        $secondary = self::normalizeEntityTypePhp($type) === 'secondary'
            ? (self::secondaryIndexFromPayload($payload)->value ?? '') : '';
        $fields = [$type, (string) self::entityId($row, $payload), $secondary];
        $input = '';
        foreach ($fields as $field) {
            $input .= pack('V', strlen($field)).$field;
        }

        return $input;
    }

    public static function documentKeyNative(object $row): string
    {
        if (! self::supportsDocumentRow($row)) {
            return self::documentKeyPhp($row);
        }

        return self::searchSqlTransformNative(2, self::packDocumentRow($row)) ?? self::documentKeyPhp($row);
    }

    public static function documentKeyPhp(object $row): string
    {
        $payload = self::payload($row);
        $entityType = self::normalizeEntityTypePhp($row->entity_type ?? '');
        $entityId = self::entityId($row, $payload);
        $secondaryIndex = $entityType === 'secondary'
            ? self::secondaryIndexFromPayload($payload)
            : self::secondaryIndexFromEntityType($entityType);

        if ($secondaryIndex !== null) {
            return 'secondary:'.$secondaryIndex->value.':'.$entityId;
        }

        return $entityType.':'.$entityId;
    }

    private static function normalizeEntityType(mixed $entityType): string
    {
        return self::$useRustFfi ? self::normalizeEntityTypeNative($entityType) : self::normalizeEntityTypePhp($entityType);
    }

    public static function normalizeEntityTypeNative(mixed $entityType): string
    {
        $entityType = (string) $entityType;

        return self::searchSqlTransformNative(0, $entityType) ?? self::normalizeEntityTypePhp($entityType);
    }

    public static function normalizeEntityTypePhp(mixed $entityType): string
    {
        $type = strtolower((string) $entityType);
        $type = str_replace('\\', '/', $type);
        $type = basename($type);
        $type = str_replace(['-', ' '], '_', $type);

        return match ($type) {
            'release', 'releases' => 'release',
            'pre', 'pres', 'predb', 'predbs' => 'predb',
            'movie', 'movies', 'movieinfo', 'movie_info' => 'movie',
            'tv', 'video', 'videos', 'tvshow', 'tvshows', 'tv_show', 'tv_shows' => 'tvshow',
            'secondary', 'secondary_search', 'secondary_search_index' => 'secondary',
            default => $type,
        };
    }

    private static function normalizeAction(mixed $action): string
    {
        return self::$useRustFfi ? self::normalizeActionNative($action) : self::normalizeActionPhp($action);
    }

    public static function normalizeActionNative(mixed $action): string
    {
        $action = (string) $action;

        return self::searchSqlTransformNative(1, $action) ?? self::normalizeActionPhp($action);
    }

    public static function normalizeActionPhp(mixed $action): string
    {
        $value = strtolower((string) $action);
        $value = str_replace(['-', ' '], '_', $value);

        return match ($value) {
            'insert', 'inserted', 'create', 'created' => 'insert',
            'upsert', 'upserted', 'replace', 'replaced', 'save', 'saved', 'index', 'indexed' => 'upsert',
            'update', 'updated' => 'update',
            'delete', 'deleted', 'destroy', 'destroyed', 'remove', 'removed' => 'delete',
            default => $value,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function withId(array $payload, int $id): array
    {
        $payload['id'] ??= $id;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function secondaryIndexFromPayload(array $payload): ?SecondarySearchIndex
    {
        foreach (['index', 'secondary', 'secondary_index', 'secondarySearchIndex', 'type', 'entity', 'entity_type'] as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key])) {
                $index = self::secondaryIndexFromEntityType((string) $payload[$key]);

                if ($index !== null) {
                    return $index;
                }
            }
        }

        return null;
    }

    private static function secondaryIndexFromEntityType(string $entityType): ?SecondarySearchIndex
    {
        return match (self::normalizeEntityType($entityType)) {
            'music', 'musicinfo', 'music_info' => SecondarySearchIndex::Music,
            'book', 'books', 'bookinfo', 'book_info' => SecondarySearchIndex::Books,
            'game', 'games', 'gamesinfo', 'games_info' => SecondarySearchIndex::Games,
            'console', 'consoleinfo', 'console_info' => SecondarySearchIndex::Console,
            'steam', 'steamapp', 'steamapps', 'steam_app', 'steam_apps' => SecondarySearchIndex::Steam,
            'anime', 'anidb', 'anidbtitle', 'anidbtitles', 'anidb_title', 'anidb_titles', 'anidbinfo', 'anidb_info' => SecondarySearchIndex::Anime,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function secondaryDocument(array $payload): array
    {
        if (isset($payload['document']) && is_array($payload['document'])) {
            return $payload['document'];
        }

        foreach (['index', 'secondary', 'secondary_index', 'secondarySearchIndex', 'type', 'entity', 'entity_type'] as $metadataKey) {
            unset($payload[$metadataKey]);
        }

        return $payload;
    }

    public static function useW425RustFfi(bool $enable = true): void
    {
        self::$useW425RustFfi = $enable;
    }

    public static function usesW425RustFfi(): bool
    {
        return self::$useW425RustFfi ?? self::W425_RUST_FFI_DEFAULT;
    }

    public static function resetW425RustFfi(): void
    {
        self::$useW425RustFfi = null;
    }

    /**
     * Summarizes how {@see dispatchRow()} would route a batch of outbox rows without dispatching,
     * logging or decoding payloads. Non-objects and rows whose `entity_type` or `action` is neither
     * scalar nor null are invalid. The entity ID comes from a numeric `entity_id` only; rows without a
     * positive one count as `missing_entity_id` and are not classified. `secondary` covers the
     * `secondary` type and every secondary index alias; `distinct_documents` counts distinct
     * normalized type and entity ID pairs. `max_row_id` covers every valid row and starts at 0.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array{rows: int, invalid: int, missing_entity_id: int, inserts: int, upserts: int, updates: int, deletes: int, unknown_actions: int, releases: int, predb: int, movies: int, tvshows: int, secondary: int, other_types: int, distinct_documents: int, max_row_id: int}
     */
    public static function summarizeOutboxBatch(array $rows): array
    {
        return self::usesW425RustFfi() ? self::summarizeOutboxBatchNative($rows) : self::summarizeOutboxBatchPhp($rows);
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array{rows: int, invalid: int, missing_entity_id: int, inserts: int, upserts: int, updates: int, deletes: int, unknown_actions: int, releases: int, predb: int, movies: int, tvshows: int, secondary: int, other_types: int, distinct_documents: int, max_row_id: int}
     */
    public static function summarizeOutboxBatchPhp(array $rows): array
    {
        $invalid = 0;
        $missing = 0;
        $maxRowId = 0;
        $actions = ['insert' => 0, 'upsert' => 0, 'update' => 0, 'delete' => 0, 'unknown' => 0];
        $types = ['release' => 0, 'predb' => 0, 'movie' => 0, 'tvshow' => 0, 'secondary' => 0, 'other' => 0];
        $documents = [];
        foreach ($rows as $row) {
            $fields = self::w425RowFields($row);
            if ($fields === null) {
                $invalid++;

                continue;
            }
            [$type, $action, $entityId, $rowId] = $fields;
            $maxRowId = max($maxRowId, $rowId);
            if ($entityId <= 0) {
                $missing++;

                continue;
            }
            $action = self::normalizeActionPhp($action);
            $actions[isset($actions[$action]) ? $action : 'unknown']++;
            $type = self::normalizeEntityTypePhp($type);
            $types[match (true) {
                isset($types[$type]) && $type !== 'other' => $type,
                \in_array($type, self::W425_SECONDARY_TYPES, true) => 'secondary',
                default => 'other',
            }]++;
            $documents[$type.':'.$entityId] = true;
        }

        return [
            'rows' => \count($rows),
            'invalid' => $invalid,
            'missing_entity_id' => $missing,
            'inserts' => $actions['insert'],
            'upserts' => $actions['upsert'],
            'updates' => $actions['update'],
            'deletes' => $actions['delete'],
            'unknown_actions' => $actions['unknown'],
            'releases' => $types['release'],
            'predb' => $types['predb'],
            'movies' => $types['movie'],
            'tvshows' => $types['tvshow'],
            'secondary' => $types['secondary'],
            'other_types' => $types['other'],
            'distinct_documents' => \count($documents),
            'max_row_id' => $maxRowId,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array{rows: int, invalid: int, missing_entity_id: int, inserts: int, upserts: int, updates: int, deletes: int, unknown_actions: int, releases: int, predb: int, movies: int, tvshows: int, secondary: int, other_types: int, distinct_documents: int, max_row_id: int}
     */
    public static function summarizeOutboxBatchNative(array $rows): array
    {
        $parts = [];
        foreach ($rows as $row) {
            $fields = self::w425RowFields($row);
            if ($fields === null) {
                array_push($parts, '0', '', '', '0', '0');

                continue;
            }
            array_push($parts, '1', $fields[0], $fields[1], (string) $fields[2], (string) $fields[3]);
        }
        $fields = ElasticsearchResponseHelper::evaluateW425Native(1, $parts);
        if ($fields === null || \count($fields) !== 16) {
            return self::summarizeOutboxBatchPhp($rows);
        }

        return [
            'rows' => (int) $fields[0],
            'invalid' => (int) $fields[1],
            'missing_entity_id' => (int) $fields[2],
            'inserts' => (int) $fields[3],
            'upserts' => (int) $fields[4],
            'updates' => (int) $fields[5],
            'deletes' => (int) $fields[6],
            'unknown_actions' => (int) $fields[7],
            'releases' => (int) $fields[8],
            'predb' => (int) $fields[9],
            'movies' => (int) $fields[10],
            'tvshows' => (int) $fields[11],
            'secondary' => (int) $fields[12],
            'other_types' => (int) $fields[13],
            'distinct_documents' => (int) $fields[14],
            'max_row_id' => (int) $fields[15],
        ];
    }

    /**
     * Raw entity type, raw action, entity ID and row ID of a summarizable row.
     *
     * @return array{string, string, int, int}|null
     */
    private static function w425RowFields(mixed $row): ?array
    {
        if (! \is_object($row)) {
            return null;
        }
        $type = $row->entity_type ?? null;
        $action = $row->action ?? null;
        if (($type !== null && ! \is_scalar($type)) || ($action !== null && ! \is_scalar($action))) {
            return null;
        }
        $entityId = $row->entity_id ?? null;

        return [(string) $type, (string) $action, is_numeric($entityId) ? (int) $entityId : 0, self::rowId($row)];
    }

    private static function logUnknownAction(string $action, string $entityType, int $entityId): void
    {
        Log::warning('Search outbox row skipped because it has an unknown action', [
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]);
    }
}
