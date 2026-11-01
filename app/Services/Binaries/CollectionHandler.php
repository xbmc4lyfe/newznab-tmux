<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use App\Enums\CollectionFileCheckStatus;
use App\Models\Collection;
use App\Services\CollectionCleanupService;
use App\Services\CollectionsCleaningService;
use App\Services\XrefService;
use App\Support\RustPipelineLibrary;
use App\Support\SqlError;
use App\Support\Utf8;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Handles collection record creation and retrieval during header storage.
 */
final class CollectionHandler
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_collection_unique_ids(const char *ids, size_t len, char *out, size_t cap);
        ssize_t nntmux_collection_unique_hashes(const char *packed, size_t len, size_t width, char *out, size_t cap);
        C;

    /** Raw SHA-1 width of every collectionhash key. */
    private const int HASH_WIDTH = 20;

    // PHP remains the production default: FFI marshaling stays below the 1.5x gate.
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

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
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * Hard upper bound on rows packed into a single SQL statement
     * (multi-row INSERT, IN(...) lookup, etc.). Keeps the generated SQL
     * and PDO parameter count bounded regardless of caller batch size.
     */
    private int $sqlChunkSize;

    private CollectionsCleaningService $collectionsCleaning;

    private XrefService $xrefService;

    /** @var array<string, int> Cached collection IDs by key */
    private array $collectionIds = [];

    /** @var array<int, true> IDs of collections created in this batch */
    private array $insertedCollectionIds = [];

    /** @var array<string, true> Collection hashes touched in this batch */
    private array $batchCollectionHashes = [];

    /** @var array<string, int> Cached collection IDs by collectionhash (populated by bulk prefetch) */
    private array $existingIdsByHash = [];

    private ?Throwable $lastException = null;

    public function __construct(
        ?CollectionsCleaningService $collectionsCleaning = null,
        ?XrefService $xrefService = null,
        int $sqlChunkSize = 500,
    ) {
        $this->collectionsCleaning = $collectionsCleaning ?? new CollectionsCleaningService;
        $this->xrefService = $xrefService ?? new XrefService;
        $this->sqlChunkSize = max(1, min(1000, $sqlChunkSize));
    }

    /**
     * Reset state for a new batch.
     */
    public function reset(): void
    {
        $this->collectionIds = [];
        $this->insertedCollectionIds = [];
        $this->batchCollectionHashes = [];
        $this->existingIdsByHash = [];
        $this->lastException = null;
    }

    /**
     * Get or create a collection for the given header.
     *
     * @param  array<string, mixed>  $header
     * @return int|null Collection ID or null on failure
     */
    public function getOrCreateCollection(
        array $header,
        int $groupId,
        string $groupName,
        int $totalFiles,
        string $batchNoise
    ): ?int {
        $collMatch = $this->collectionsCleaning->collectionsCleaner(
            $header['matches'][1],
            $groupName
        );

        $collectionKey = $collMatch['name'].$totalFiles;

        // Return cached ID if already processed this batch
        if (isset($this->collectionIds[$collectionKey])) {
            return $this->collectionIds[$collectionKey];
        }

        $collectionHash = sha1($collectionKey, true);
        $this->batchCollectionHashes[$collectionHash] = true;

        $headerDate = is_numeric($header['Date']) ? (int) $header['Date'] : strtotime($header['Date']);
        $now = now()->timestamp;
        $unixtime = min($headerDate, $now) ?: $now;

        $headerTokens = $this->xrefService->extractTokens($header['Xref'] ?? '');

        $subject = substr(Utf8::clean($header['matches'][1]), 0, 255);
        $fromName = Utf8::clean($header['From']);

        $driver = DB::getDriverName();

        try {
            $collectionId = $this->insertOrGetCollection(
                $driver,
                $subject,
                $fromName,
                $unixtime,
                $headerTokens,
                $groupId,
                $totalFiles,
                $collectionHash,
                $collMatch['id'],
                $batchNoise
            );

            if ($collectionId > 0) {
                $this->collectionIds[$collectionKey] = $collectionId;
                $this->insertCollectionGroups([
                    $collectionKey => array_fill_keys(
                        $this->xrefService->extractGroupNames($header['Xref'] ?? '') ?: [$groupName],
                        true
                    ),
                ]);

                return $collectionId;
            }
        } catch (Throwable $e) {
            $this->lastException = $e;
            if (config('app.debug') === true) {
                SqlError::logFailure('Collection insert failed', $e);
            }
        }

        return null;
    }

    /**
     * Resolve collections for a chunk of headers with one bulk insert and one id lookup.
     *
     * @param  array<int, array<string, mixed>>  $headers
     * @param  array<int, int>  $totalFilesByIndex
     * @return array<int, int> Collection ids keyed by header index
     */
    public function getOrCreateCollections(
        array $headers,
        int $groupId,
        string $groupName,
        array $totalFilesByIndex,
        string $batchNoise
    ): array {
        $resolved = [];
        $pending = [];
        $indexByCollectionKey = [];
        $hashesToPrefetch = [];
        $groupNamesByCollectionKey = [];

        $groupsBatchByIndex = null;
        if (\get_class($this->xrefService) === XrefService::class && \count($headers) > 1) {
            $xrefList = [];
            $indexList = [];
            foreach ($headers as $index => $header) {
                $xrefList[] = (string) ($header['Xref'] ?? '');
                $indexList[] = $index;
            }
            $extracted = $this->xrefService->extractGroupNamesBatch($xrefList);
            $groupsBatchByIndex = [];
            foreach ($indexList as $pos => $index) {
                $groupsBatchByIndex[$index] = $extracted[$pos];
            }
        }

        foreach ($headers as $index => $header) {
            $totalFiles = (int) ($totalFilesByIndex[$index] ?? 0);
            $collMatch = $this->collectionsCleaning->collectionsCleaner(
                $header['matches'][1],
                $groupName
            );

            $collectionKey = $collMatch['name'].$totalFiles;
            $groupNames = $groupsBatchByIndex !== null
                ? $groupsBatchByIndex[$index]
                : $this->xrefService->extractGroupNames($header['Xref'] ?? '');
            if ($groupNames === []) {
                $groupNames = [$groupName];
            }
            foreach ($groupNames as $xrefGroupName) {
                $groupNamesByCollectionKey[$collectionKey][$xrefGroupName] = true;
            }

            if (isset($this->collectionIds[$collectionKey])) {
                $resolved[$index] = $this->collectionIds[$collectionKey];

                continue;
            }

            $indexByCollectionKey[$collectionKey][] = $index;
            if (isset($pending[$collectionKey])) {
                continue;
            }

            $collectionHash = sha1($collectionKey, true);
            $this->batchCollectionHashes[$collectionHash] = true;

            $hashesToPrefetch[$collectionKey] = $collectionHash;

            $headerDate = is_numeric($header['Date']) ? (int) $header['Date'] : strtotime($header['Date']);
            $now = now()->timestamp;
            $unixtime = min($headerDate, $now) ?: $now;
            $headerTokens = $this->xrefService->extractTokens($header['Xref'] ?? '');

            $pending[$collectionKey] = [
                'subject' => substr(Utf8::clean($header['matches'][1]), 0, 255),
                'fromname' => Utf8::clean($header['From']),
                'unixtime' => $unixtime,
                'xref' => implode(' ', $headerTokens),
                'groups_id' => $groupId,
                'totalfiles' => $totalFiles,
                'collectionhash' => $collectionHash,
                'collection_regexes_id' => (int) $collMatch['id'],
                'noise' => $batchNoise,
            ];
        }

        if ($pending === []) {
            $this->insertCollectionGroups($groupNamesByCollectionKey);

            return $resolved;
        }

        $this->prefetchExistingCollections($hashesToPrefetch);

        try {
            $idsByHash = $this->bulkInsertAndResolve($pending);
            foreach ($pending as $collectionKey => $row) {
                $collectionId = $idsByHash[$row['collectionhash']] ?? 0;
                if ($collectionId <= 0) {
                    continue;
                }

                $this->collectionIds[$collectionKey] = $collectionId;
                foreach ($indexByCollectionKey[$collectionKey] ?? [] as $index) {
                    $resolved[$index] = $collectionId;
                }
            }
            $this->insertCollectionGroups($groupNamesByCollectionKey);
        } catch (Throwable $e) {
            $this->lastException = $e;
            if (config('app.debug') === true) {
                SqlError::logFailure('Bulk collection insert failed', $e);
            }
        }

        return $resolved;
    }

    /**
     * Prefetch existing collections in one round-trip. Populates both
     * $existingIdsByHash so the
     * subsequent bulkInsertAndResolve() can skip its existence-check SELECT
     * and only re-query for the freshly inserted rows.
     *
     * @param  array<string, string>  $collectionHashByKey
     */
    private function prefetchExistingCollections(array $collectionHashByKey): void
    {
        $missing = array_filter(
            $collectionHashByKey,
            fn (string $hash): bool => ! isset($this->existingIdsByHash[$hash]),
            ARRAY_FILTER_USE_BOTH
        );

        if ($missing === []) {
            return;
        }

        $rows = [];
        foreach (array_chunk(array_values($missing), $this->sqlChunkSize) as $chunk) {
            $placeholders = implode(',', array_fill(0, \count($chunk), '?'));
            $existingRows = DB::select(
                "SELECT id, collectionhash FROM collections WHERE collectionhash IN ({$placeholders})",
                $chunk
            );
            foreach ($existingRows as $row) {
                $rows[(string) $row->collectionhash] = [
                    'id' => (int) $row->id,
                ];
            }
        }

        foreach ($missing as $collectionKey => $hash) {
            if (isset($rows[$hash])) {
                $this->existingIdsByHash[$hash] = $rows[$hash]['id'];
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $rowsByCollectionKey
     * @return array<string, int> Collection ids keyed by collectionhash
     */
    private function bulkInsertAndResolve(array $rowsByCollectionKey): array
    {
        $hashes = array_values(array_column($rowsByCollectionKey, 'collectionhash'));

        // The prefetch step has already populated $existingIdsByHash, so we
        // know which hashes existed before the INSERT without issuing a
        // separate "existingHashes" SELECT.
        $existingHashes = [];
        $idsByHash = [];
        foreach ($hashes as $hash) {
            if (isset($this->existingIdsByHash[$hash])) {
                $existingHashes[$hash] = true;
                $idsByHash[$hash] = $this->existingIdsByHash[$hash];
            }
        }

        // Concurrent header workers upsert overlapping cross-posted collections; inserting in
        // unique-key order makes them take those locks in the same sequence instead of deadlocking.
        $sortedRows = $rowsByCollectionKey;
        uasort($sortedRows, static fn (array $a, array $b): int => strcmp((string) $a['collectionhash'], (string) $b['collectionhash']));

        if (DB::getDriverName() === 'sqlite') {
            $this->bulkInsertCollectionsSqlite($sortedRows);
        } else {
            $this->bulkInsertCollectionsMysql($sortedRows);
        }

        // Only resolve ids for the hashes we couldn't satisfy from the
        // prefetch cache (i.e. the freshly inserted rows). For chunks where
        // every collection already existed this issues zero extra SELECTs.
        $newHashes = array_values(array_diff(array_unique($hashes), array_keys($idsByHash)));
        if ($newHashes !== []) {
            foreach ($this->resolveIdsByHash($newHashes) as $hash => $id) {
                $idsByHash[$hash] = $id;
                $this->existingIdsByHash[$hash] = $id;
            }
        }

        foreach ($idsByHash as $hash => $id) {
            if (! isset($existingHashes[$hash])) {
                $this->insertedCollectionIds[$id] = true;
            }
        }

        return $idsByHash;
    }

    /**
     * @param  array<string, array<string, mixed>>  $rowsByCollectionKey
     */
    private function bulkInsertCollectionsSqlite(array $rowsByCollectionKey): void
    {
        $rows = [];
        foreach ($rowsByCollectionKey as $row) {
            $rows[] = [
                'subject' => $row['subject'],
                'fromname' => $row['fromname'],
                'date' => date('Y-m-d H:i:s', (int) $row['unixtime']),
                'xref' => $row['xref'],
                'groups_id' => $row['groups_id'],
                'totalfiles' => $row['totalfiles'],
                'collectionhash' => $row['collectionhash'],
                'collection_regexes_id' => $row['collection_regexes_id'],
                'dateadded' => now(),
                'noise' => $row['noise'],
            ];
        }

        foreach (array_chunk($rows, $this->sqlChunkSize) as $chunk) {
            DB::table('collections')->insertOrIgnore($chunk);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $rowsByCollectionKey
     */
    private function bulkInsertCollectionsMysql(array $rowsByCollectionKey): void
    {
        foreach (array_chunk(array_values($rowsByCollectionKey), $this->sqlChunkSize) as $chunk) {
            $placeholders = [];
            $bindings = [];
            foreach ($chunk as $row) {
                $placeholders[] = '(?, ?, FROM_UNIXTIME(?), ?, ?, ?, ?, ?, NOW(), ?)';
                array_push(
                    $bindings,
                    $row['subject'],
                    $row['fromname'],
                    $row['unixtime'],
                    $row['xref'],
                    $row['groups_id'],
                    $row['totalfiles'],
                    $row['collectionhash'],
                    $row['collection_regexes_id'],
                    $row['noise']
                );
            }

            // ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id) is the standard
            // "insert or do nothing" idiom that avoids re-writing existing
            // rows (and the redo/binlog churn that comes with it) while still
            // letting LAST_INSERT_ID() return the existing row's id.
            DB::statement(
                'INSERT INTO collections (subject, fromname, date, xref, groups_id, totalfiles, collectionhash, collection_regexes_id, dateadded, noise) VALUES '
                .implode(',', $placeholders)
                .' ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
                $bindings
            );
        }

        // Cross-post groups are normalized in collection_groups. Avoid
        // rewriting the wide collections row for every new article number.
    }

    /**
     * @param  list<string>  $hashes
     * @return array<string, int>
     */
    private function resolveIdsByHash(array $hashes): array
    {
        if ($hashes === []) {
            return [];
        }

        $resolved = [];
        foreach (array_chunk($hashes, $this->sqlChunkSize) as $chunk) {
            $placeholders = implode(',', array_fill(0, \count($chunk), '?'));
            foreach (DB::select(
                "SELECT id, collectionhash FROM collections WHERE collectionhash IN ({$placeholders})",
                $chunk
            ) as $row) {
                $resolved[(string) $row->collectionhash] = (int) $row->id;
            }
        }

        return $resolved;
    }

    /**
     * @param  array<string, array<string, true>>  $groupNamesByCollectionKey
     */
    private function insertCollectionGroups(array $groupNamesByCollectionKey): void
    {
        $rows = [];
        foreach ($groupNamesByCollectionKey as $collectionKey => $groupNames) {
            $collectionId = $this->collectionIds[$collectionKey] ?? null;
            if ($collectionId === null) {
                continue;
            }
            foreach (array_keys($groupNames) as $groupName) {
                $rows[] = ['collections_id' => $collectionId, 'group_name' => $groupName];
            }
        }
        // Primary-key order, so concurrent workers lock shared rows in the same sequence.
        usort($rows, static fn (array $a, array $b): int => [$a['collections_id'], $a['group_name']] <=> [$b['collections_id'], $b['group_name']]);

        foreach (array_chunk($rows, $this->sqlChunkSize) as $chunk) {
            if (DB::getDriverName() === 'sqlite') {
                DB::table('collection_groups')->insertOrIgnore($chunk);

                continue;
            }

            $values = [];
            $bindings = [];
            foreach ($chunk as $row) {
                $values[] = '(?, ?)';
                $bindings[] = $row['collections_id'];
                $bindings[] = $row['group_name'];
            }
            DB::statement(
                'INSERT IGNORE INTO collection_groups (collections_id, group_name) VALUES '.implode(',', $values),
                $bindings
            );
        }
    }

    /**
     * @param  array<string, mixed>  $headerTokens
     */
    private function insertOrGetCollection(
        string $driver,
        string $subject,
        string $fromName,
        int $unixtime,
        array $headerTokens,
        int $groupId,
        int $totalFiles,
        string $collectionHash,
        int $regexId,
        string $batchNoise
    ): int {
        if ($driver === 'sqlite') {
            return $this->insertCollectionSqlite(
                $subject,
                $fromName,
                $unixtime,
                $headerTokens,
                $groupId,
                $totalFiles,
                $collectionHash,
                $regexId,
                $batchNoise
            );
        }

        return $this->insertCollectionMysql(
            $subject,
            $fromName,
            $unixtime,
            $headerTokens,
            $groupId,
            $totalFiles,
            $collectionHash,
            $regexId,
            $batchNoise
        );
    }

    /**
     * @param  array<string, mixed>  $headerTokens
     */
    private function insertCollectionSqlite(
        string $subject,
        string $fromName,
        int $unixtime,
        array $headerTokens,
        int $groupId,
        int $totalFiles,
        string $collectionHash,
        int $regexId,
        string $batchNoise
    ): int {
        $affected = DB::table('collections')->insertOrIgnore([
            'subject' => $subject,
            'fromname' => $fromName,
            'date' => date('Y-m-d H:i:s', $unixtime),
            'xref' => implode(' ', $headerTokens),
            'groups_id' => $groupId,
            'totalfiles' => $totalFiles,
            'collectionhash' => $collectionHash,
            'collection_regexes_id' => $regexId,
            'dateadded' => now(),
            'noise' => $batchNoise,
        ]);

        if ($affected > 0 && ($lastId = (int) DB::connection()->getPdo()->lastInsertId()) > 0) {
            $this->insertedCollectionIds[$lastId] = true;

            return $lastId;
        }

        return (int) (Collection::whereCollectionhash($collectionHash)->value('id') ?? 0);
    }

    /**
     * @param  array<string, mixed>  $headerTokens
     */
    private function insertCollectionMysql(
        string $subject,
        string $fromName,
        int $unixtime,
        array $headerTokens,
        int $groupId,
        int $totalFiles,
        string $collectionHash,
        int $regexId,
        string $batchNoise
    ): int {
        // ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id) lets LAST_INSERT_ID()
        // return the existing row's id without rewriting the row (avoids the
        // redo/binlog churn of `dateadded = NOW()`).
        $insertSql = 'INSERT INTO collections '
            .'(subject, fromname, date, xref, groups_id, totalfiles, collectionhash, collection_regexes_id, dateadded, noise) '
            .'VALUES (?, ?, FROM_UNIXTIME(?), ?, ?, ?, ?, ?, NOW(), ?) '
            .'ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)';

        $bindings = [
            $subject,
            $fromName,
            $unixtime,
            implode(' ', $headerTokens),
            $groupId,
            $totalFiles,
            $collectionHash,
            $regexId,
            $batchNoise,
        ];

        // affectingStatement distinguishes a brand-new insert (rowCount = 1)
        // from a duplicate-key hit (rowCount = 0). LAST_INSERT_ID(id) in
        // the ODKU clause makes lastInsertId() return the existing row id
        // even on a duplicate, so we can't rely on lastInsertId() alone.
        $affected = (int) DB::affectingStatement($insertSql, $bindings);
        $lastId = (int) DB::connection()->getPdo()->lastInsertId();

        if ($lastId > 0) {
            if ($affected === 1) {
                $this->insertedCollectionIds[$lastId] = true;
            }

            return $lastId;
        }

        return (int) (Collection::whereCollectionhash($collectionHash)->value('id') ?? 0);
    }

    /**
     * Refresh size and readiness for only the collections touched by a header
     * chunk. Binary and part aggregates have already been refreshed, so this
     * replaces broad table-wide completion scans on the normal ingestion path.
     *
     * @param  list<int>  $collectionIds
     */
    public function refreshAggregates(array $collectionIds, int $chunkSize = 500): bool
    {
        $collectionIds = array_values(array_unique(array_map('intval', $collectionIds)));
        if ($collectionIds === []) {
            return true;
        }

        $inProgressStatuses = [
            CollectionFileCheckStatus::Default->value,
            CollectionFileCheckStatus::CompleteCollection->value,
            CollectionFileCheckStatus::CompleteParts->value,
            CollectionFileCheckStatus::TempComplete->value,
            CollectionFileCheckStatus::ZeroPart->value,
        ];

        try {
            $chunkSize = max(1, min($chunkSize, $this->sqlChunkSize));
            if (DB::getDriverName() === 'sqlite') {
                foreach ($collectionIds as $collectionId) {
                    $aggregate = DB::selectOne(
                        'SELECT COUNT(*) AS currentfiles,
                                COALESCE(SUM(CASE WHEN partcheck = 1 THEN 1 ELSE 0 END), 0) AS completefiles,
                                COALESCE(SUM(partsize), 0) AS filesize
                         FROM binaries WHERE collections_id = ?',
                        [$collectionId]
                    );
                    $collection = DB::selectOne(
                        'SELECT totalfiles, filecheck FROM collections WHERE id = ?',
                        [$collectionId]
                    );
                    if ($collection === null) {
                        continue;
                    }

                    $currentFiles = (int) $aggregate->currentfiles;
                    $totalFiles = (int) $collection->totalfiles;
                    $ready = \in_array((int) $collection->filecheck, $inProgressStatuses, true)
                        && $totalFiles > 0
                        && ($currentFiles === $totalFiles || $currentFiles === $totalFiles + 1)
                        && (int) $aggregate->completefiles >= $totalFiles;

                    DB::update(
                        'UPDATE collections SET filesize = ?, last_seen_at = ?, filecheck = ? WHERE id = ?',
                        [
                            (int) $aggregate->filesize,
                            now()->format('Y-m-d H:i:s'),
                            $ready ? CollectionFileCheckStatus::Sized->value : (int) $collection->filecheck,
                            $collectionId,
                        ]
                    );
                }

                return true;
            }

            // As in BinaryHandler::refreshAggregates(): lock the collections rows in id
            // order, so concurrent writers to the same collection serialize, then read the
            // binaries totals with a plain SELECT and write them back by primary key.
            sort($collectionIds);
            $statusPlaceholders = implode(',', array_fill(0, \count($inProgressStatuses), '?'));
            foreach (array_chunk($collectionIds, $chunkSize) as $chunk) {
                // A covering secondary scan can lock tentative duplicate inserts outside this ID set.
                DB::table('collections')->forceIndex('PRIMARY')->whereIn('id', $chunk)->orderBy('id')->lockForUpdate()->pluck('id');
                $aggregates = DB::table('binaries')
                    ->whereIn('collections_id', $chunk)
                    ->groupBy('collections_id')
                    ->orderBy('collections_id')
                    ->selectRaw('collections_id, COUNT(*) AS currentfiles,
                        SUM(CASE WHEN partcheck = 1 THEN 1 ELSE 0 END) AS completefiles,
                        COALESCE(SUM(partsize), 0) AS filesize')
                    ->get();
                if ($aggregates->isEmpty()) {
                    continue;
                }

                $fileSize = $currentFiles = $completeFiles = '';
                $bindings = ['filesize' => [], 'currentfiles' => [], 'completefiles' => [], 'ids' => []];
                foreach ($aggregates as $aggregate) {
                    $id = (int) $aggregate->collections_id;
                    $fileSize .= ' WHEN ? THEN ?';
                    $currentFiles .= ' WHEN ? THEN ?';
                    $completeFiles .= ' WHEN ? THEN ?';
                    array_push($bindings['filesize'], $id, (int) $aggregate->filesize);
                    array_push($bindings['currentfiles'], $id, (int) $aggregate->currentfiles);
                    array_push($bindings['completefiles'], $id, (int) $aggregate->completefiles);
                    $bindings['ids'][] = $id;
                }
                $idPlaceholders = implode(',', array_fill(0, \count($bindings['ids']), '?'));
                DB::update(
                    "UPDATE collections
                     SET filesize = CASE id{$fileSize} END,
                         last_seen_at = NOW(),
                         filecheck = CASE
                             WHEN filecheck IN ({$statusPlaceholders})
                              AND totalfiles > 0
                              AND (CASE id{$currentFiles} END) IN (totalfiles, totalfiles + 1)
                              AND (CASE id{$completeFiles} END) >= totalfiles
                             THEN ? ELSE filecheck END
                     WHERE id IN ({$idPlaceholders})",
                    [
                        ...$bindings['filesize'],
                        ...$inProgressStatuses,
                        ...$bindings['currentfiles'],
                        ...$bindings['completefiles'],
                        CollectionFileCheckStatus::Sized->value,
                        ...$bindings['ids'],
                    ]
                );
            }

            return true;
        } catch (Throwable $e) {
            $this->lastException = $e;
            if (config('app.debug') === true) {
                SqlError::logFailure('Collection aggregate refresh failed', $e);
            }

            return false;
        }
    }

    /**
     * Get IDs created in this batch.
     *
     * @return list<int>
     */
    public function getInsertedIds(): array
    {
        return array_keys($this->insertedCollectionIds);
    }

    public function getLastException(): ?Throwable
    {
        return $this->lastException;
    }

    /**
     * Get all collection IDs processed this batch.
     *
     * @return list<int>
     */
    public function getAllIds(): array
    {
        return (self::$useRustFfi ? $this->getAllIdsNative() : null) ?? $this->getAllIdsPhp();
    }

    /**
     * @return list<int>
     */
    public function getAllIdsPhp(): array
    {
        return array_values(array_unique(array_map('intval', $this->collectionIds)));
    }

    /**
     * @return list<int>|null Null when the Rust library is unavailable.
     */
    public function getAllIdsNative(): ?array
    {
        if ($this->collectionIds === []) {
            return [];
        }
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $packed = pack('q*', ...array_map('intval', array_values($this->collectionIds)));
        $packed = self::callPacked($ffi, $packed, static fn (FFI $ffi, string $in, CData $out, int $cap): int => $ffi->nntmux_collection_unique_ids($in, \strlen($in), $out, $cap)); // @phpstan-ignore method.notFound
        if ($packed === null) {
            return null;
        }

        return $packed === '' ? [] : array_values(unpack('q*', $packed) ?: []);
    }

    /**
     * Get all collection hashes processed this batch.
     *
     * @return list<string>
     */
    public function getBatchHashes(): array
    {
        return (self::$useRustFfi ? $this->getBatchHashesNative() : null) ?? $this->getBatchHashesPhp();
    }

    /**
     * @return list<string>
     */
    public function getBatchHashesPhp(): array
    {
        return array_keys($this->batchCollectionHashes);
    }

    /**
     * @return list<string>|null Null when Rust is unavailable or a key is not a raw SHA-1.
     */
    public function getBatchHashesNative(): ?array
    {
        if ($this->batchCollectionHashes === []) {
            return [];
        }
        $keys = array_keys($this->batchCollectionHashes);
        foreach ($keys as $key) {
            if (! \is_string($key) || \strlen($key) !== self::HASH_WIDTH) {
                return null;
            }
        }
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $packed = self::callPacked($ffi, implode('', $keys), static fn (FFI $ffi, string $in, CData $out, int $cap): int => $ffi->nntmux_collection_unique_hashes($in, \strlen($in), self::HASH_WIDTH, $out, $cap)); // @phpstan-ignore method.notFound

        return $packed === null ? null : str_split($packed, self::HASH_WIDTH);
    }

    /**
     * Run a packed-input export whose output never exceeds its input length.
     *
     * @param  \Closure(FFI, string, CData, int): int  $call
     */
    private static function callPacked(FFI $ffi, string $input, \Closure $call): ?string
    {
        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, max(1, \strlen($input)), $cap);
            if ($scratch === null) {
                return null;
            }
            $written = $call($ffi, $input, $scratch, $cap);
            if ($written < 0) {
                return null;
            }

            return $written === 0 ? '' : FFI::string($scratch, $written);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * PHP stays the default for summarizeCollectionUpsertBatch(): the paired benchmark in
     * tests/Unit/RustCollectionCleaningRemoverBlacklistHandlerW40ParityTest.php does not clear 1.5x.
     */
    public const bool W401_RUST_FFI_DEFAULT = false;

    private const array W401_UPSERT_KEYS = ['collections', 'invalid_rows', 'valid', 'oversized_parts', 'missing_subject', 'total_expected_parts'];

    private static ?bool $w401UseRustFfi = null;

    private static FFI|false|null $w401Ffi = null;

    /**
     * Summarizes collection upsert payloads (`subject`, `totalfiles`): a subject that is not a string or trim()s to '' is
     * missing, a positive part count above `$maxPartsPerCollection` is oversized, and payloads with a subject and 1 to
     * `$maxPartsPerCollection` parts are valid. Positive part counts add to the expected total (saturating at PHP_INT_MAX);
     * non-array payloads are invalid.
     *
     * @param  array<array-key, mixed>  $collections
     * @return array<string, int>
     */
    public static function summarizeCollectionUpsertBatch(array $collections, int $maxPartsPerCollection = 50000): array
    {
        return (self::usesW401RustFfi() ? self::summarizeCollectionUpsertBatchNative($collections, $maxPartsPerCollection) : null)
            ?? self::summarizeCollectionUpsertBatchPhp($collections, $maxPartsPerCollection);
    }

    /**
     * @param  array<array-key, mixed>  $collections
     * @return array<string, int>
     */
    private static function summarizeCollectionUpsertBatchPhp(array $collections, int $maxParts): array
    {
        $summary = array_fill_keys(self::W401_UPSERT_KEYS, 0);
        $summary['collections'] = \count($collections);
        foreach ($collections as $collection) {
            if (! \is_array($collection)) {
                $summary['invalid_rows']++;

                continue;
            }
            $subject = $collection['subject'] ?? null;
            $missing = ! \is_string($subject) || trim($subject) === '';
            $parts = CollectionCleanupService::w401Int($collection['totalfiles'] ?? null) ?? 0;
            $oversized = $parts > 0 && $parts > $maxParts;
            if ($parts > 0) {
                $total = $summary['total_expected_parts'];
                $summary['total_expected_parts'] = $parts > PHP_INT_MAX - $total ? PHP_INT_MAX : $total + $parts;
            }
            $summary['missing_subject'] += (int) $missing;
            $summary['oversized_parts'] += (int) $oversized;
            $summary['valid'] += (int) (! $missing && $parts > 0 && ! $oversized);
        }

        return $summary;
    }

    /**
     * @param  array<array-key, mixed>  $collections
     * @return array<string, int>|null
     */
    private static function summarizeCollectionUpsertBatchNative(array $collections, int $maxParts): ?array
    {
        $words = [$maxParts, \count($collections)];
        $blob = [];
        foreach ($collections as $collection) {
            if (\is_array($collection)) {
                CollectionCleanupService::w401PackRow($words, $blob, [$collection['subject'] ?? null, $collection['totalfiles'] ?? null]);
            } else {
                $words[] = 0;
            }
        }
        $values = CollectionCleanupService::w401Invoke(self::$w401Ffi, 5, $words, $blob);

        return $values !== null && \count($values) === \count(self::W401_UPSERT_KEYS) ? array_combine(self::W401_UPSERT_KEYS, $values) : null;
    }

    public static function useW401RustFfi(?bool $enabled): void
    {
        self::$w401UseRustFfi = $enabled;
    }

    public static function usesW401RustFfi(): bool
    {
        return self::$w401UseRustFfi ?? self::W401_RUST_FFI_DEFAULT;
    }

    public static function resetW401RustFfi(): void
    {
        self::$w401UseRustFfi = null;
        self::$w401Ffi = null;
    }

    /** Wave 52.2 paired benchmarks decide this default; see RustBinariesCollectionHeaderMissedPartHandlerW52ParityTest. */
    private const bool W52_RUST_FFI_DEFAULT = true;

    public const string W52_FFI_CDEF = <<<'C'
        intptr_t nntmux_w522_collection_key_plan_batch(const char *names, size_t names_len, const char *totals, size_t totals_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /**
     * Wave 52 routing, binding and output buffer, kept in function-local statics. Operations:
     * null reads the routing override, 'set' replaces it (returning the previous one), 'ffi'
     * resolves the binding and 'out' returns `[binding, buffer, capacity]` with room for at
     * least $val words (null without the library).
     */
    private static function w52RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w52', self::W52_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w52RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    public static function usesW52RustFfi(): bool
    {
        $enabled = self::w52RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W52_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with planCollectionKeys() routed to Rust ($enabled) or PHP, then restores the
     * previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW52RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w52RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w52RustFfiState('set', $previous);
        }
    }

    /**
     * Batch collection-key plan, as getOrCreateCollections() derives `name . totalFiles` keys and
     * their sha1 collectionhash. Two words per header: `flags | first << 8 | crc32(key) << 32`, then
     * the first eight bytes of sha1(key, true) as a big-endian signed word. Flags: 1 first
     * occurrence, 2 repeated key (`first` is the key's first row), 4 empty name, 8 total <= 0,
     * 16 name longer than 255 bytes. Unique keys, repeats, empty names, non-positive totals and the
     * XXH64 digest of all earlier words follow.
     *
     * @param  list<string>  $names  Cleaned collection names (collectionsCleaner()['name'])
     * @param  list<int>  $totalFiles
     * @return list<int>
     */
    public static function planCollectionKeys(array $names, array $totalFiles): array
    {
        return self::usesW52RustFfi()
            ? self::planCollectionKeysNative($names, $totalFiles)
            : self::planCollectionKeysPhp($names, $totalFiles);
    }

    /**
     * @param  list<string>  $names
     * @param  list<int>  $totalFiles
     * @return list<int>
     */
    public static function planCollectionKeysPhp(array $names, array $totalFiles): array
    {
        $out = $seen = [];
        $repeats = $empty = $nonPositive = 0;
        foreach ($names as $i => $name) {
            $total = $totalFiles[$i] ?? 0;
            $key = $name.$total;
            $flags = ($name === '' ? 4 : 0) | ($total <= 0 ? 8 : 0) | (\strlen($name) > 255 ? 16 : 0);
            $empty += (int) ($name === '');
            $nonPositive += (int) ($total <= 0);
            if (isset($seen[$key])) {
                $flags |= 2;
                $repeats++;
                [$first, $tag, $prefix] = $seen[$key];
            } else {
                $flags |= 1;
                /** @var array{1: int} $head */
                $head = unpack('J', sha1($key, true));
                [$first, $tag, $prefix] = $seen[$key] = [$i, crc32($key) << 32, $head[1]];
            }
            $out[] = $flags | $first << 8 | $tag;
            $out[] = $prefix;
        }
        $out[] = \count($seen);
        $out[] = $repeats;
        $out[] = $empty;
        $out[] = $nonPositive;
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));
        $out[] = $digest[1];

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, the lists differ in length or
     * are not lists, or a name holds a NUL byte.
     *
     * @param  list<string>  $names
     * @param  list<int>  $totalFiles
     * @return list<int>
     */
    public static function planCollectionKeysNative(array $names, array $totalFiles): array
    {
        $count = \count($names);
        $words = 2 * $count + 5;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w52RustFfiState('out', $words);
        if ($state === null || \count($totalFiles) !== $count || ! array_is_list($names) || ! array_is_list($totalFiles)) {
            return self::planCollectionKeysPhp($names, $totalFiles);
        }
        [$ffi, $out, $cap] = $state;
        $joined = implode("\0", $names);
        $totals = pack('P*', ...$totalFiles);
        try {
            // @phpstan-ignore method.notFound (Declared by W52_FFI_CDEF.)
            $written = $ffi->nntmux_w522_collection_key_plan_batch($joined, \strlen($joined), $totals, \strlen($totals), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : self::planCollectionKeysPhp($names, $totalFiles);
    }
}
