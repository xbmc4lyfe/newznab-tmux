<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use App\Support\RustPipelineLibrary;
use App\Support\SqlError;
use App\Support\Utf8;
use FFI\CData;
use Illuminate\Support\Facades\DB;

/**
 * Handles binary record creation and updates during header storage.
 */
final class BinaryHandler
{
    /**
     * Hard upper bound on the number of rows packed into a single SQL
     * statement (multi-row INSERT, OR-clause SELECT, etc.). Keeps the
     * generated SQL string and PDO parameter list bounded regardless of
     * how many headers the caller passes in.
     */
    private int $sqlChunkSize;

    /** @var array<int, true> IDs of binaries created in this batch */
    private array $insertedBinaryIds = [];

    /** @var array<string, array{CollectionID: int, BinaryID: int}> Processed articles */
    private array $articles = [];

    private ?\Throwable $lastException = null;

    public function __construct(int $sqlChunkSize = 500)
    {
        $this->sqlChunkSize = max(1, min(1000, $sqlChunkSize));
    }

    /**
     * Reset state for a new batch.
     */
    public function reset(): void
    {
        $this->insertedBinaryIds = [];
        $this->articles = [];
        $this->lastException = null;
    }

    /**
     * Get or create a binary for the given header.
     *
     * @param  array<string, mixed>  $header
     * @return int|null Binary ID or null on failure
     */
    public function getOrCreateBinary(
        array $header,
        int $collectionId,
        int $groupId,
        int $fileNumber
    ): ?int {
        $hash = $this->identityHash($header, $fileNumber);
        $articleKey = $this->binaryLookupKey($hash, $collectionId);

        // Return cached if already processed
        if (isset($this->articles[$articleKey])) {
            return $this->articles[$articleKey]['BinaryID'];
        }

        $driver = DB::getDriverName();

        try {
            $binaryId = $this->insertOrGetBinary(
                $driver,
                $hash,
                $header,
                $collectionId,
                $fileNumber
            );

            if ($binaryId > 0) {
                $this->articles[$articleKey] = [
                    'CollectionID' => $collectionId,
                    'BinaryID' => $binaryId,
                ];

                return $binaryId;
            }
        } catch (\Throwable $e) {
            $this->lastException = $e;
            if (config('app.debug') === true) {
                SqlError::logFailure('Binary insert failed', $e);
            }
        }

        return null;
    }

    /**
     * Resolve binaries for a chunk of headers with one bulk insert and one id lookup.
     *
     * @param  array<int, array{header: array<string, mixed>, collection_id: int, file_number: int}>  $records
     * @return array<int, int> Binary ids keyed by header index
     */
    public function getOrCreateBinaries(array $records, int $groupId): array
    {
        $resolved = [];
        $pending = [];
        $indexesByArticleKey = [];
        $hashesByIndex = \count($records) > 1 ? $this->identityHashesBatch($records) : null;

        foreach ($records as $index => $record) {
            $header = $record['header'];
            $collectionId = (int) $record['collection_id'];
            $fileNumber = (int) $record['file_number'];
            $hash = $hashesByIndex[$index] ?? $this->identityHash($header, $fileNumber);
            $articleKey = $this->binaryLookupKey($hash, $collectionId);

            if (isset($this->articles[$articleKey])) {
                $binaryId = $this->articles[$articleKey]['BinaryID'];
                $resolved[$index] = $binaryId;

                continue;
            }

            $indexesByArticleKey[$articleKey][] = $index;
            if (isset($pending[$articleKey])) {
                continue;
            }

            $pending[$articleKey] = [
                'hash' => $hash,
                'name' => Utf8::clean($header['matches'][1]),
                'collections_id' => $collectionId,
                'totalparts' => (int) $header['matches'][3],
                'filenumber' => $fileNumber,
            ];
        }

        if ($pending === []) {
            return $resolved;
        }

        try {
            $result = $this->bulkInsertAndResolve($pending);
            $idsByKey = $result['ids'];
            foreach ($pending as $articleKey => $row) {
                $lookupKey = $this->binaryLookupKey($row['hash'], (int) $row['collections_id']);
                $binaryId = $idsByKey[$lookupKey] ?? 0;
                if ($binaryId <= 0) {
                    continue;
                }

                $this->articles[$articleKey] = [
                    'CollectionID' => (int) $row['collections_id'],
                    'BinaryID' => $binaryId,
                ];

                foreach ($indexesByArticleKey[$articleKey] ?? [] as $index) {
                    $resolved[$index] = $binaryId;
                }
            }
        } catch (\Throwable $e) {
            $this->lastException = $e;
            if (config('app.debug') === true) {
                SqlError::logFailure('Bulk binary insert failed', $e);
            }
        }

        return $resolved;
    }

    /**
     * @param  array<string, array<string, mixed>>  $rowsByArticleKey
     * @return array{ids: array<string, int>, existing: array<string, true>}
     */
    private function bulkInsertAndResolve(array $rowsByArticleKey): array
    {
        $lookupRows = array_values($rowsByArticleKey);

        // One SELECT establishes both "did this row exist?" and "what is its
        // id?", instead of two identical SELECTs (existingBinaryKeys +
        // resolveBinaryIds) that the previous implementation issued.
        $idsByKey = $this->selectBinaryIdsByKey($lookupRows);
        $existingKeys = [];
        foreach ($idsByKey as $key => $_id) {
            $existingKeys[$key] = true;
        }

        // Unique-key order (collections_id, binaryhash), so concurrent workers upserting the
        // same cross-posted binaries take their locks in the same sequence instead of deadlocking.
        $sortedRows = $lookupRows;
        usort($sortedRows, static fn (array $a, array $b): int => [(int) $a['collections_id'], (string) $a['hash']] <=> [(int) $b['collections_id'], (string) $b['hash']]);

        if (DB::getDriverName() === 'sqlite') {
            $this->bulkInsertBinariesSqlite($sortedRows);
        } else {
            $this->bulkInsertBinariesMysql($sortedRows);
        }

        // Only re-query for rows we couldn't satisfy from the prefetch
        // (i.e. those that didn't exist before the bulk INSERT).
        $missingRows = [];
        foreach ($lookupRows as $row) {
            $key = $this->binaryLookupKey((string) $row['hash'], (int) $row['collections_id']);
            if (! isset($idsByKey[$key])) {
                $missingRows[] = $row;
            }
        }

        if ($missingRows !== []) {
            foreach ($this->selectBinaryIdsByKey($missingRows) as $key => $id) {
                $idsByKey[$key] = $id;
            }
        }

        foreach ($idsByKey as $key => $id) {
            if (! isset($existingKeys[$key])) {
                $this->insertedBinaryIds[$id] = true;
            }
        }

        return ['ids' => $idsByKey, 'existing' => $existingKeys];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int> id keyed by binaryLookupKey(hash, collectionId)
     */
    private function selectBinaryIdsByKey(array $rows): array
    {
        $resolved = [];
        foreach ($this->selectBinaryRows($rows) as $row) {
            $resolved[$this->binaryLookupKey((string) $row->hashvalue, (int) $row->collections_id)] = (int) $row->id;
        }

        return $resolved;
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function bulkInsertBinariesSqlite(array $rows): void
    {
        foreach (array_chunk($rows, $this->sqlChunkSize) as $chunk) {
            $insertRows = [];
            foreach ($chunk as $row) {
                $insertRows[] = [
                    'binaryhash' => $row['hash'],
                    'name' => $row['name'],
                    'collections_id' => $row['collections_id'],
                    'totalparts' => $row['totalparts'],
                    'currentparts' => 0,
                    'filenumber' => $row['filenumber'],
                    'partsize' => 0,
                ];
            }

            DB::table('binaries')->insertOrIgnore($insertRows);
        }
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function bulkInsertBinariesMysql(array $rows): void
    {
        foreach (array_chunk($rows, $this->sqlChunkSize) as $chunk) {
            $placeholders = [];
            $bindings = [];
            foreach ($chunk as $row) {
                $placeholders[] = '(UNHEX(?), ?, ?, ?, 0, ?, 0)';
                array_push(
                    $bindings,
                    $row['hash'],
                    $row['name'],
                    $row['collections_id'],
                    $row['totalparts'],
                    $row['filenumber']
                );
            }

            DB::statement(
                'INSERT INTO binaries (binaryhash, name, collections_id, totalparts, currentparts, filenumber, partsize) VALUES '
                .implode(',', $placeholders)
                .' ON DUPLICATE KEY UPDATE totalparts = GREATEST(totalparts, VALUES(totalparts)), id = LAST_INSERT_ID(id)',
                $bindings
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<object>
     */
    private function selectBinaryRows(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $driver = DB::getDriverName();
        $hashExpression = $driver === 'sqlite' ? 'binaryhash' : 'LOWER(HEX(binaryhash))';

        // Resolve bounded composite keys together: per-collection lookups add round trips
        // while the header transaction holds parent locks across cross-posted collections.
        $results = [];
        foreach (array_chunk($rows, $this->sqlChunkSize) as $chunk) {
            $tuples = implode(',', array_fill(0, \count($chunk), $driver === 'sqlite' ? '(?, ?)' : '(?, UNHEX(?))'));
            $bindings = [];
            foreach ($chunk as $row) {
                $bindings[] = (int) $row['collections_id'];
                $bindings[] = (string) $row['hash'];
            }

            $rowsResult = DB::select(
                "SELECT id, {$hashExpression} AS hashvalue, collections_id FROM binaries "
                ."WHERE (collections_id, binaryhash) IN ({$tuples})",
                $bindings
            );

            foreach ($rowsResult as $row) {
                $results[] = $row;
            }
        }

        return $results;
    }

    private function binaryLookupKey(string $hash, int $collectionId): string
    {
        return strtolower($hash).':'.$collectionId;
    }

    /**
     * @param  array<string, mixed>  $header
     */
    private function insertOrGetBinary(
        string $driver,
        string $hash,
        array $header,
        int $collectionId,
        int $fileNumber
    ): int {
        $name = Utf8::clean($header['matches'][1]);
        $totalParts = (int) $header['matches'][3];
        if ($driver === 'sqlite') {
            return $this->insertBinarySqlite($hash, $name, $collectionId, $totalParts, $fileNumber);
        }

        return $this->insertBinaryMysql($hash, $name, $collectionId, $totalParts, $fileNumber);
    }

    private function insertBinarySqlite(
        string $hash,
        string $name,
        int $collectionId,
        int $totalParts,
        int $fileNumber
    ): int {
        $affected = DB::table('binaries')->insertOrIgnore([
            'binaryhash' => $hash,
            'name' => $name,
            'collections_id' => $collectionId,
            'totalparts' => $totalParts,
            'currentparts' => 0,
            'filenumber' => $fileNumber,
            'partsize' => 0,
        ]);

        if ($affected > 0 && ($lastId = (int) DB::connection()->getPdo()->lastInsertId()) > 0) {
            $this->insertedBinaryIds[$lastId] = true;

            return $lastId;
        }

        $bin = DB::selectOne(
            'SELECT id FROM binaries WHERE binaryhash = ? AND collections_id = ? LIMIT 1',
            [$hash, $collectionId]
        );

        return (int) ($bin->id ?? 0);
    }

    private function insertBinaryMysql(
        string $hash,
        string $name,
        int $collectionId,
        int $totalParts,
        int $fileNumber
    ): int {

        $sql = 'INSERT INTO binaries '
            .'(binaryhash, name, collections_id, totalparts, currentparts, filenumber, partsize) '
            .'VALUES (UNHEX(?), ?, ?, ?, 0, ?, 0) '
            .'ON DUPLICATE KEY UPDATE totalparts = GREATEST(totalparts, VALUES(totalparts)), id = LAST_INSERT_ID(id)';

        DB::statement($sql, [$hash, $name, $collectionId, $totalParts, $fileNumber]);

        $lastId = (int) DB::connection()->getPdo()->lastInsertId();
        if ($lastId > 0) {
            $this->insertedBinaryIds[$lastId] = true;

            return $lastId;
        }

        $bin = DB::selectOne(
            'SELECT id FROM binaries WHERE binaryhash = UNHEX(?) AND collections_id = ? LIMIT 1',
            [$hash, $collectionId]
        );

        return (int) ($bin->id ?? 0);
    }

    /**
     * Rebuild aggregates from stored parts for the binaries touched by a chunk.
     * This is deliberately idempotent: ignored duplicate part inserts cannot
     * inflate currentparts or partsize.
     *
     * @param  list<int>  $binaryIds
     */
    public function refreshAggregates(array $binaryIds, int $chunkSize = 500): bool
    {
        $binaryIds = array_values(array_unique(array_map('intval', $binaryIds)));
        if ($binaryIds === []) {
            return true;
        }

        try {
            if (DB::getDriverName() === 'sqlite') {
                foreach ($binaryIds as $binaryId) {
                    $aggregate = DB::selectOne(
                        'SELECT COUNT(*) AS currentparts, COALESCE(SUM(size), 0) AS partsize FROM parts WHERE binaries_id = ?',
                        [$binaryId]
                    );
                    DB::update(
                        'UPDATE binaries SET currentparts = ?, partsize = ?, partcheck = CASE WHEN ? >= totalparts THEN 1 ELSE 0 END WHERE id = ?',
                        [(int) $aggregate->currentparts, (int) $aggregate->partsize, (int) $aggregate->currentparts, $binaryId]
                    );
                }

                return true;
            }

            // An UPDATE that joins parts locks every parts row it reads, even under
            // READ-COMMITTED, and concurrent header writers on the same group deadlocked
            // on those locks. Instead lock just the binaries rows, in id order, so a
            // concurrent writer to the same binary commits first; then a plain SELECT
            // (READ COMMITTED, see HeaderStorageTransaction) sees its parts and ours.
            $chunkSize = max(1, min($chunkSize, $this->sqlChunkSize));
            sort($binaryIds);
            foreach (array_chunk($binaryIds, $chunkSize) as $chunk) {
                DB::table('binaries')->whereIn('id', $chunk)->orderBy('id')->lockForUpdate()->pluck('id');
                $aggregates = DB::table('parts')
                    ->whereIn('binaries_id', $chunk)
                    ->groupBy('binaries_id')
                    ->orderBy('binaries_id')
                    ->selectRaw('binaries_id, COUNT(*) AS currentparts, COALESCE(SUM(size), 0) AS partsize')
                    ->get();
                if ($aggregates->isEmpty()) {
                    continue;
                }

                $currentParts = '';
                $partSizes = '';
                $bindings = ['currentparts' => [], 'partsize' => [], 'partcheck' => [], 'ids' => []];
                foreach ($aggregates as $aggregate) {
                    $currentParts .= ' WHEN ? THEN ?';
                    $partSizes .= ' WHEN ? THEN ?';
                    array_push($bindings['currentparts'], (int) $aggregate->binaries_id, (int) $aggregate->currentparts);
                    array_push($bindings['partsize'], (int) $aggregate->binaries_id, (int) $aggregate->partsize);
                    array_push($bindings['partcheck'], (int) $aggregate->binaries_id, (int) $aggregate->currentparts);
                    $bindings['ids'][] = (int) $aggregate->binaries_id;
                }
                $placeholders = implode(',', array_fill(0, \count($bindings['ids']), '?'));
                DB::update(
                    "UPDATE binaries
                     SET currentparts = CASE id{$currentParts} END,
                         partsize = CASE id{$partSizes} END,
                         partcheck = CASE WHEN (CASE id{$currentParts} END) >= totalparts THEN 1 ELSE 0 END
                     WHERE id IN ({$placeholders})",
                    [...$bindings['currentparts'], ...$bindings['partsize'], ...$bindings['partcheck'], ...$bindings['ids']]
                );
            }

            return true;
        } catch (\Throwable $e) {
            $this->lastException = $e;
            if (config('app.debug') === true) {
                SqlError::logFailure('Binaries aggregate update failed', $e);
            }

            return false;
        }
    }

    /**
     * Check if article is already processed.
     */
    public function hasArticle(string $articleKey): bool
    {
        return isset($this->articles[$articleKey]);
    }

    /**
     * Get IDs created in this batch.
     *
     * @return list<int>
     */
    public function getInsertedIds(): array
    {
        return array_keys($this->insertedBinaryIds);
    }

    public function getLastException(): ?\Throwable
    {
        return $this->lastException;
    }

    /**
     * Compute binary identity hashes for a batch of header records.
     *
     * @param  array<int, array{header: array<string, mixed>, collection_id?: int, file_number: int}>  $records
     * @return array<int, string>
     */
    public function identityHashesBatch(array $records, bool $useNative = true): array
    {
        if ($records === []) {
            return [];
        }

        if ($useNative) {
            $flatPairs = [];
            foreach ($records as $record) {
                $fileNumber = (int) $record['file_number'];
                if ($fileNumber > 0) {
                    $flatPairs[] = $fileNumber."\0\x01";
                } else {
                    $header = $record['header'];
                    $flatPairs[] = ($header['matches'][1] ?? '')."\0".($header['From'] ?? '');
                }
            }
            $allResolved = false;
            $nativeHashes = RustPipelineLibrary::binaryIdentityHashesBatch($flatPairs, \count($records), null, $allResolved);
            if ($nativeHashes !== null) {
                if ($allResolved && array_is_list($records)) {
                    return $nativeHashes;
                }
                $result = [];
                $pos = 0;
                foreach ($records as $index => $record) {
                    $hash = $nativeHashes[$pos++];
                    if ($hash === '') {
                        $hash = $this->identityHashPhp($record['header'], (int) $record['file_number']);
                    }
                    $result[$index] = $hash;
                }

                return $result;
            }
        }

        $result = [];
        foreach ($records as $index => $record) {
            $result[$index] = $this->identityHashPhp($record['header'], (int) $record['file_number']);
        }

        return $result;
    }

    /** @param array<string, mixed> $header */
    public function identityHash(array $header, int $fileNumber, bool $useNative = true): string
    {
        if ($fileNumber > 0) {
            return md5('file:'.$fileNumber);
        }
        if ($useNative) {
            $subject = (string) ($header['matches'][1] ?? '');
            $poster = (string) ($header['From'] ?? '');
            $native = RustPipelineLibrary::binaryIdentityHash($subject, $poster, 0);
            if ($native !== null) {
                return $native;
            }
        }

        return $this->identityHashPhp($header, $fileNumber);
    }

    /** @param array<string, mixed> $header */
    public function identityHashPhp(array $header, int $fileNumber): string
    {
        if ($fileNumber > 0) {
            return md5('file:'.$fileNumber);
        }

        $subject = preg_replace('/\s+/u', ' ', Utf8::clean((string) ($header['matches'][1] ?? ''))) ?? '';
        $poster = preg_replace('/\s+/u', ' ', Utf8::clean((string) ($header['From'] ?? ''))) ?? '';

        return md5('subject:'.mb_strtolower(trim($subject))."\0poster:".mb_strtolower(trim($poster)));
    }

    /**
     * PHP stays the default for summarizeBinaryRecordBatch(): the paired benchmark in
     * tests/Unit/RustBinariesConfigServiceHandlerHeaderW41ParityTest.php does not clear 1.5x.
     */
    public const bool W411_RUST_FFI_DEFAULT = false;

    private const array W411_RECORD_KEYS = ['records', 'invalid_rows', 'bad_hash', 'bad_collection', 'duplicate_keys', 'file_over_total', 'total_parts'];

    private static ?bool $w411UseRustFfi = null;

    private static \FFI|false|null $w411Ffi = null;

    /**
     * Summarizes pending binary rows (`hash`, `collections_id`, `totalparts`, `filenumber`): a hash that is not a
     * 32-character lowercase hex string is bad, a collection id that is not a positive int (or 1-18 digit string) is
     * bad, and rows with both repeat an earlier row's collection/hash key as duplicates. A file number above a positive
     * part total is flagged, and positive part totals add to the total (saturating at PHP_INT_MAX); non-array rows are
     * invalid.
     *
     * @param  array<array-key, mixed>  $records
     * @return array<string, int>
     */
    public static function summarizeBinaryRecordBatch(array $records): array
    {
        return (self::usesW411RustFfi() ? self::summarizeBinaryRecordBatchNative($records) : null)
            ?? self::summarizeBinaryRecordBatchPhp($records);
    }

    /**
     * @param  array<array-key, mixed>  $records
     * @return array<string, int>
     */
    public static function summarizeBinaryRecordBatchPhp(array $records): array
    {
        $summary = array_fill_keys(self::W411_RECORD_KEYS, 0);
        $summary['records'] = \count($records);
        $seen = [];
        foreach ($records as $record) {
            if (! \is_array($record)) {
                $summary['invalid_rows']++;

                continue;
            }
            $hash = $record['hash'] ?? null;
            $hashOk = \is_string($hash) && \strlen($hash) === 32 && strspn($hash, '0123456789abcdef') === 32;
            $collection = BinariesService::w411Int($record['collections_id'] ?? null);
            $collectionOk = $collection !== null && $collection > 0;
            $summary['bad_hash'] += (int) ! $hashOk;
            $summary['bad_collection'] += (int) ! $collectionOk;
            if ($hashOk && $collectionOk) {
                $key = $collection.':'.$hash;
                if (isset($seen[$key])) {
                    $summary['duplicate_keys']++;
                } else {
                    $seen[$key] = true;
                }
            }
            $parts = BinariesService::w411Int($record['totalparts'] ?? null);
            $number = BinariesService::w411Int($record['filenumber'] ?? null);
            $summary['file_over_total'] += (int) ($parts !== null && $number !== null && $parts > 0 && $number > $parts);
            if ($parts !== null && $parts > 0) {
                $total = $summary['total_parts'];
                $summary['total_parts'] = $parts > PHP_INT_MAX - $total ? PHP_INT_MAX : $total + $parts;
            }
        }

        return $summary;
    }

    /**
     * @param  array<array-key, mixed>  $records
     * @return array<string, int>|null
     */
    public static function summarizeBinaryRecordBatchNative(array $records): ?array
    {
        $words = [\count($records)];
        $blob = [];
        foreach ($records as $record) {
            if (\is_array($record)) {
                BinariesService::w411PackRow($words, $blob, [$record['hash'] ?? null, $record['collections_id'] ?? null,
                    $record['totalparts'] ?? null, $record['filenumber'] ?? null]);
            } else {
                $words[] = 0;
            }
        }
        $values = BinariesService::w411Invoke(self::$w411Ffi, 3, $words, $blob);

        return $values !== null && \count($values) === \count(self::W411_RECORD_KEYS) ? array_combine(self::W411_RECORD_KEYS, $values) : null;
    }

    public static function useW411RustFfi(bool $enable = true): void
    {
        self::$w411UseRustFfi = $enable;
    }

    public static function usesW411RustFfi(): bool
    {
        return self::$w411UseRustFfi ?? self::W411_RUST_FFI_DEFAULT;
    }

    public static function resetW411RustFfi(): void
    {
        self::$w411UseRustFfi = null;
        self::$w411Ffi = null;
    }

    /** Wave 52.2 paired benchmarks decide this default; see RustBinariesCollectionHeaderMissedPartHandlerW52ParityTest. */
    private const bool W52_RUST_FFI_DEFAULT = true;

    public const string W52_FFI_CDEF = <<<'C'
        intptr_t nntmux_w522_binary_key_plan_batch(const char *hashes, size_t hashes_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
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
                    $buffer = $resolved instanceof \FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
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
     * Runs $callback with planBinaryKeys() routed to Rust ($enabled) or PHP, then restores the
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
     * Batch lookup-key plan for pending binary rows, as getOrCreateBinaries() groups them. One word
     * per row: `flags | first << 8 | crc32(strtolower(hash) . ':' . collection) << 32`. Flags: 1 hash
     * of 32 hex digits, 2 positive collection id, 4 repeated lookup key (valid rows only; `first`
     * is the first row with that key, else the row itself), 8 hash holding an uppercase byte, 16 file
     * number above a positive part total, 32 file number <= 0. Valid, unique and repeated counts,
     * case-folded hashes, files over their total and the XXH64 digest of all earlier words follow.
     *
     * @param  list<string>  $hashes
     * @param  list<int>  $collectionIds
     * @param  list<int>  $fileNumbers
     * @param  list<int>  $totalParts
     * @return list<int>
     */
    public static function planBinaryKeys(array $hashes, array $collectionIds, array $fileNumbers, array $totalParts): array
    {
        return self::usesW52RustFfi()
            ? self::planBinaryKeysNative($hashes, $collectionIds, $fileNumbers, $totalParts)
            : self::planBinaryKeysPhp($hashes, $collectionIds, $fileNumbers, $totalParts);
    }

    /**
     * @param  list<string>  $hashes
     * @param  list<int>  $collectionIds
     * @param  list<int>  $fileNumbers
     * @param  list<int>  $totalParts
     * @return list<int>
     */
    public static function planBinaryKeysPhp(array $hashes, array $collectionIds, array $fileNumbers, array $totalParts): array
    {
        $out = $first = [];
        $valid = $repeats = $folded = $over = 0;
        foreach ($hashes as $i => $hash) {
            $collection = $collectionIds[$i] ?? 0;
            $file = $fileNumbers[$i] ?? 0;
            $total = $totalParts[$i] ?? 0;
            $lower = strtolower($hash);
            $key = $lower.':'.$collection;
            $hashOk = \strlen($hash) === 32 && strspn($hash, '0123456789abcdefABCDEF') === 32;
            $flags = ($hashOk ? 1 : 0) | ($collection > 0 ? 2 : 0);
            $index = $i;
            if ($flags === 3) {
                $valid++;
                if (isset($first[$key])) {
                    $index = $first[$key];
                    $flags |= 4;
                    $repeats++;
                } else {
                    $first[$key] = $i;
                }
            }
            if ($lower !== $hash) {
                $flags |= 8;
                $folded++;
            }
            if ($file <= 0) {
                $flags |= 32;
            }
            if ($total > 0 && $file > $total) {
                $flags |= 16;
                $over++;
            }
            $out[] = $flags | $index << 8 | crc32($key) << 32;
        }
        $out[] = $valid;
        $out[] = $valid - $repeats;
        $out[] = $repeats;
        $out[] = $folded;
        $out[] = $over;
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));
        $out[] = $digest[1];

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, the columns differ in length or
     * are not lists, or a hash holds a NUL byte.
     *
     * @param  list<string>  $hashes
     * @param  list<int>  $collectionIds
     * @param  list<int>  $fileNumbers
     * @param  list<int>  $totalParts
     * @return list<int>
     */
    public static function planBinaryKeysNative(array $hashes, array $collectionIds, array $fileNumbers, array $totalParts): array
    {
        $count = \count($hashes);
        $words = $count + 6;
        /** @var array{\FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w52RustFfiState('out', $words);
        if ($state === null || \count($collectionIds) !== $count || \count($fileNumbers) !== $count || \count($totalParts) !== $count
            || ! array_is_list($hashes) || ! array_is_list($collectionIds) || ! array_is_list($fileNumbers) || ! array_is_list($totalParts)) {
            return self::planBinaryKeysPhp($hashes, $collectionIds, $fileNumbers, $totalParts);
        }
        [$ffi, $out, $cap] = $state;
        $joined = implode("\0", $hashes);
        $ints = pack('P*', ...$collectionIds, ...$fileNumbers, ...$totalParts);
        try {
            // @phpstan-ignore method.notFound (Declared by W52_FFI_CDEF.)
            $written = $ffi->nntmux_w522_binary_key_plan_batch($joined, \strlen($joined), $ints, \strlen($ints), $count, $out, $cap);
        } catch (\Throwable) {
            $written = -1;
        }
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', \FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : self::planBinaryKeysPhp($hashes, $collectionIds, $fileNumbers, $totalParts);
    }
}
