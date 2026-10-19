<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

use App\Models\Category;
use App\Models\Release;
use App\Support\Par2FileHashEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Cache\DatabaseLock;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class NameFixingQueryService
{
    public const PREDB_IDENTITY_POLICY = 'ff9ba61a9f1d2e1adaf9d556da5a2d804b05f98b958845152a966abe69ee9fc9';

    public const SOURCE_NFO = 'nfo';

    public const SOURCE_FILES = 'files';

    public const SOURCE_SRR = 'srr';

    public const SOURCE_CRC = 'crc';

    public const SOURCE_UID = 'uid';

    public const SOURCE_HASH = 'hash';

    public const SOURCE_PAR2 = 'par2';

    public const SOURCE_XXX = 'xxx';

    public const SOURCE_MEDIA_MOVIE = 'media_movie';

    public const BATCH_SIZE = 1000;

    private ConnectionInterface $database;

    /**
     * @var array<string, string>
     */
    private const STATUS_COLUMNS = [
        self::SOURCE_NFO => 'proc_nfo',
        self::SOURCE_FILES => 'proc_files',
        self::SOURCE_SRR => 'proc_srr',
        self::SOURCE_CRC => 'proc_crc32',
        self::SOURCE_UID => 'proc_uid',
        self::SOURCE_HASH => 'proc_hash16k',
        self::SOURCE_PAR2 => 'proc_par2',
        self::SOURCE_XXX => 'proc_files',
        self::SOURCE_MEDIA_MOVIE => 'proc_uid',
    ];

    /**
     * @var array<string, string>
     */
    private const SOURCE_EXISTS = [
        self::SOURCE_NFO => 'EXISTS (SELECT 1 FROM release_nfos source_nfo WHERE source_nfo.releases_id = r.id)',
        self::SOURCE_FILES => 'EXISTS (SELECT 1 FROM release_files source_file WHERE source_file.releases_id = r.id)',
        self::SOURCE_SRR => "EXISTS (SELECT 1 FROM release_files source_srr WHERE source_srr.releases_id = r.id AND (source_srr.name LIKE '%.srr' OR source_srr.name LIKE '%.srs'))",
        self::SOURCE_CRC => "EXISTS (SELECT 1 FROM release_files source_crc WHERE source_crc.releases_id = r.id AND source_crc.crc32 IS NOT NULL AND source_crc.crc32 != '')",
        self::SOURCE_UID => "EXISTS (SELECT 1 FROM media_infos source_media WHERE source_media.releases_id = r.id AND source_media.unique_id IS NOT NULL AND source_media.unique_id != '')",
        self::SOURCE_HASH => "EXISTS (SELECT 1 FROM par_hashes source_hash WHERE source_hash.releases_id = r.id AND source_hash.hash != '')",
        self::SOURCE_PAR2 => 'r.nzbstatus = 1',
        self::SOURCE_XXX => "EXISTS (SELECT 1 FROM release_files source_xxx WHERE source_xxx.releases_id = r.id AND source_xxx.name LIKE '%SDPORN%')",
        self::SOURCE_MEDIA_MOVIE => "EXISTS (SELECT 1 FROM media_infos source_movie WHERE source_movie.releases_id = r.id AND source_movie.movie_name IS NOT NULL AND source_movie.movie_name != '')",
    ];

    public function __construct(?ConnectionInterface $database = null)
    {
        $this->database = $database ?? DB::connection();
    }

    /** Pending sources inspected by the scheduled fix-names passes, counted once per release. */
    public static function pendingWorkCondition(): string
    {
        $sources = [];
        foreach (self::STATUS_COLUMNS as $source => $column) {
            if ($source === self::SOURCE_PAR2) {
                continue;
            }
            $sourceCondition = self::SOURCE_EXISTS[$source];
            if ($source === self::SOURCE_HASH) {
                $sourceCondition .= ' AND NOT EXISTS (SELECT 1 FROM par2_hash_retry hr WHERE hr.releases_id = r.id'
                    .' AND HEX(hr.release_guid) = HEX(r.guid) AND hr.retry_at > CURRENT_TIMESTAMP)';
            }
            $sources[] = '(r.'.$column.' = 0 AND ('.$sourceCondition.'))';
        }

        $categoryScope = (string) config('tmux.fix_names.category', 'other');
        $categoryFilter = $categoryScope === 'all'
            ? ''
            : ' AND r.categories_id IN ('.implode(',', Category::OTHERS_GROUP).')';

        $condition = 'r.predb_id = 0 AND (r.isrenamed = 0 OR r.categories_id IN ('
            .Category::OTHER_MISC.', '.Category::OTHER_HASHED.'))'
            .$categoryFilter
            .' AND (CASE WHEN r.proc_par2 = 0 AND ('.self::SOURCE_EXISTS[self::SOURCE_PAR2].') AND '.Release::nzbSettledSql('r').' THEN 1'
            .' WHEN '.implode(' OR ', $sources).' THEN 1 ELSE 0 END = 1)'
            // An in-place upgrade resets each source's status while its rows still describe the smaller NZB.
            .' AND '.Release::nzbSettledSql('r');

        if (! (bool) config('tmux.fix_names.full_backlog', true)) {
            $cutoff = DB::getDriverName() === 'sqlite'
                ? "datetime(CURRENT_TIMESTAMP, '-6 hours')"
                : 'CURRENT_TIMESTAMP - INTERVAL 6 HOUR';
            $condition .= ' AND r.adddate > '.$cutoff;
        }

        return $condition;
    }

    /**
     * @return list<object>
     */
    public function candidateBatch(
        string $source,
        int $time,
        int $categories,
        int $afterId = 0,
        int $limit = self::BATCH_SIZE,
        ?int $throughId = null
    ): array {
        [$where, $bindings] = $this->candidateWhere($source, $time, $categories);
        $bindings[] = $afterId;
        $ceiling = '';
        if ($throughId !== null) {
            $ceiling = ' AND r.id <= ?';
            $bindings[] = $throughId;
        }
        $batchLimit = $limit;
        if ($limit === self::BATCH_SIZE) {
            $configuredLimit = config('tmux.fix_names.batch_limit');
            if ($configuredLimit !== null && (int) $configuredLimit > 0) {
                $batchLimit = (int) $configuredLimit;
            }
        }
        $bindings[] = max(1, $batchLimit);

        return $this->database->select(
            "SELECT r.id AS releases_id, r.id, r.name, r.searchname, r.fromname, r.groups_id,
                    r.categories_id, r.size AS relsize, r.guid, r.predb_id, r.nfostatus,
                    r.proc_nfo, r.proc_files, r.proc_par2, r.proc_uid, r.proc_srr,
                    r.proc_hash16k, r.proc_crc32, r.isrenamed
             FROM releases r
             WHERE {$where}
             AND r.id > ?{$ceiling}
             ORDER BY r.id ASC
             LIMIT ?",
            $bindings
        );
    }

    public function highWatermark(): int
    {
        $rows = $this->database->select('SELECT MAX(id) AS highest_id FROM releases');

        return (int) ($rows[0]->highest_id ?? 0);
    }

    public function countCandidates(string $source, int $time, int $categories): int
    {
        [$where, $bindings] = $this->candidateWhere($source, $time, $categories);
        $rows = $this->database->select(
            "SELECT COUNT(*) AS aggregate FROM releases r WHERE {$where}",
            $bindings
        );

        return (int) ($rows[0]->aggregate ?? 0);
    }

    /**
     * @return list<object>
     */
    public function standardCandidateBatch(string $leftGuid, int $limit, ?int $beforeId = null): array
    {
        $cursor = $beforeId === null ? '' : ' AND r.id < ?';
        $categoryScope = (string) config('tmux.fix_names.category', 'other');
        $categoryFilter = $categoryScope === 'all'
            ? ''
            : ' AND r.categories_id IN ('.implode(',', Category::OTHERS_GROUP).')';
        $bindings = [$leftGuid, CarbonImmutable::now()->toDateTimeString()];
        if ($beforeId !== null) {
            $bindings[] = $beforeId;
        }
        $bindings[] = max(1, $limit);

        return $this->database->select(
            'SELECT r.id AS releases_id, r.id, r.name, r.searchname, r.fromname, r.guid,
                    r.groups_id, r.categories_id, r.size AS relsize, r.predb_id, r.nfostatus,
                    r.proc_nfo, r.proc_uid, r.proc_files, r.proc_par2, r.proc_hash16k,
                    r.proc_srr, r.proc_crc32, r.isrenamed
             FROM releases r
             WHERE r.leftguid = ?
             AND r.isrenamed = 0
             AND r.predb_id = 0
             AND r.passwordstatus >= 0
             AND r.nfostatus > -1
             AND '.Release::nzbSettledSql('r').'
             AND (
                (r.nfostatus = 1 AND r.proc_nfo = 0)
                OR r.proc_files = 0
                OR r.proc_uid = 0
                OR r.proc_par2 = 0
                OR r.proc_srr = 0
                OR (r.proc_hash16k = 0 AND NOT EXISTS (SELECT 1 FROM par2_hash_retry hr
                    WHERE hr.releases_id = r.id AND HEX(hr.release_guid) = HEX(r.guid) AND hr.retry_at > ?))
                OR r.proc_crc32 = 0
             )
             '.$categoryFilter.$cursor.'
             ORDER BY r.id DESC
             LIMIT ?',
            $bindings
        );
    }

    /**
     * @param  list<int>  $releaseIds
     * @return list<object>
     */
    public function nfoRows(array $releaseIds): array
    {
        return $this->selectForReleaseIds(
            'SELECT rn.releases_id, UNCOMPRESS(rn.nfo) AS textstring
             FROM release_nfos rn
             WHERE rn.releases_id IN (%s)
             ORDER BY rn.releases_id',
            $releaseIds
        );
    }

    /**
     * @param  list<int>  $releaseIds
     * @return list<object>
     */
    public function fileRows(array $releaseIds, string $source = self::SOURCE_FILES): array
    {
        $filter = match ($source) {
            self::SOURCE_FILES => '',
            self::SOURCE_SRR => " AND (rf.name LIKE '%%.srr' OR rf.name LIKE '%%.srs')",
            self::SOURCE_CRC => " AND rf.crc32 IS NOT NULL AND rf.crc32 != ''",
            self::SOURCE_XXX => " AND rf.name LIKE '%%SDPORN%%'",
            default => throw new InvalidArgumentException("Unsupported release-file source [{$source}]."),
        };

        return $this->selectForReleaseIds(
            'SELECT rf.releases_id, rf.name AS textstring, rf.name AS filename, rf.crc32
             FROM release_files rf
             WHERE rf.releases_id IN (%s)'.$filter.'
             ORDER BY rf.releases_id, rf.name',
            $releaseIds
        );
    }

    /**
     * @param  list<int>  $releaseIds
     * @return list<object>
     */
    public function mediaRows(array $releaseIds): array
    {
        return $this->selectForReleaseIds(
            'SELECT mi.releases_id, mi.unique_id AS uid, mi.movie_name, mi.file_name
             FROM media_infos mi
             WHERE mi.releases_id IN (%s)
             ORDER BY mi.releases_id',
            $releaseIds
        );
    }

    /**
     * @param  list<int>  $releaseIds
     * @return list<object>
     */
    public function hashRows(array $releaseIds): array
    {
        return $this->selectForReleaseIds(
            'SELECT ph.releases_id, ph.hash
             FROM par_hashes ph
             WHERE ph.releases_id IN (%s)
             ORDER BY ph.releases_id, ph.hash',
            $releaseIds
        );
    }

    /** @param list<int> $releaseIds
     * @return list<object>
     */
    public function legacyHashRows(array $releaseIds): array
    {
        return $this->selectForReleaseIds('SELECT ph.releases_id, ph.hash FROM par_hashes ph
            WHERE ph.releases_id IN (%s) AND NOT EXISTS
                (SELECT 1 FROM par_file_hashes strong WHERE strong.releases_id = ph.releases_id)
            ORDER BY ph.releases_id, ph.hash', $releaseIds);
    }

    public function hashRetryDue(int $releaseId, string $guid): bool
    {
        return ! $this->database->table('par2_hash_retry')->where('releases_id', $releaseId)
            ->whereRaw('HEX(release_guid) = ?', [strtoupper(bin2hex($guid))])->where('retry_at', '>', CarbonImmutable::now()->toDateTimeString())->exists();
    }

    /**
     * @param  list<string>  $uniqueIds
     * @return array<string, list<object>>
     */
    public function uidDonors(array $uniqueIds): array
    {
        if ($uniqueIds === []) {
            return [];
        }

        $placeholders = $this->placeholders(count($uniqueIds));
        $bindings = array_merge($uniqueIds, ['nonscene@Ef.net (EF)']);
        $rows = $this->database->select(
            "SELECT mi.unique_id AS match_key, r.id AS releases_id, r.size AS relsize,
                    r.searchname, r.fromname, r.predb_id
             FROM media_infos mi
             INNER JOIN releases r ON r.id = mi.releases_id
             WHERE mi.unique_id IN ({$placeholders})
             AND (r.predb_id > 0 OR r.anidbid > 0 OR r.fromname = ?)",
            $bindings
        );

        return $this->groupByKey($rows, 'match_key');
    }

    /**
     * @param  list<string>  $hashes
     * @return array<string, list<object>>
     */
    public function hashDonors(array $hashes): array
    {
        return $this->donors(
            'SELECT ph.hash AS match_key, r.id AS releases_id, r.size AS relsize,
                    r.searchname, r.fromname, r.predb_id
             FROM par_hashes ph
             INNER JOIN releases r ON r.id = ph.releases_id
             WHERE ph.hash IN (%s)
             AND (r.predb_id > 0 OR r.anidbid > 0)',
            $hashes
        );
    }

    /**
     * All target keys participate in one catalogue snapshot. The 257th distinct donor
     * is a flood sentinel; SQL work remains dependent on the indexed input cardinality.
     * Target tuple validation uses bounded pages and never truncates descriptor coverage.
     * Require at least two distinct positive tuples and symmetric inventory coverage;
     * a lone shared support file or a partial inventory cannot identify a donor.
     *
     * @return array{known: bool, usable: bool, overflow: bool, donors: list<object>, generation: string}
     */
    public function fullHashDonorsForRelease(int $releaseId, string $expectedGuid): array
    {
        $known = $usable = $multipleTuples = false;
        $firstTuple = null;
        $cursor = 0;
        $count = 0;
        do {
            $rows = $this->database->table('par_file_hashes')->select(['id', 'release_guid', 'hash16k', 'full_md5', 'file_size'])
                ->where('releases_id', $releaseId)->where('id', '>', $cursor)->orderBy('id')->limit(128)->lockForUpdate()->get()->all();
            foreach ($rows as $row) {
                $known = true;
                $count++;
                $cursor = (int) $row->id;
                if ($row->release_guid !== $expectedGuid
                    || preg_match('/^[0-9a-f]{32}$/D', (string) $row->hash16k) !== 1
                    || preg_match('/^[0-9a-f]{32}$/D', (string) $row->full_md5) !== 1
                    || Par2FileHashEvidence::sizeDecimal($row->file_size) === null) {
                    $generation = $this->hashEvidenceGeneration($releaseId);

                    return ['known' => true, 'usable' => false, 'overflow' => false, 'donors' => [], 'generation' => $generation];
                }
                $usable = $usable || ($row->file_size !== '' && $row->file_size !== '0');
                if ($row->file_size !== '' && $row->file_size !== '0') {
                    $tuple = $row->full_md5.':'.$row->file_size;
                    $multipleTuples = $multipleTuples || ($firstTuple !== null && $firstTuple !== $tuple);
                    $firstTuple ??= $tuple;
                }
            }
        } while (count($rows) === 128);
        if (! $usable) {
            return ['known' => $known, 'usable' => false, 'overflow' => false, 'donors' => [], 'generation' => $cursor.':'.$count];
        }
        if (! $multipleTuples) {
            return ['known' => true, 'usable' => true, 'overflow' => false, 'donors' => [], 'generation' => $cursor.':'.$count];
        }
        $inventory = $this->database->table('par_file_hash_inventories')->where('releases_id', $releaseId)
            ->where('proof_version', Par2FileHashEvidence::INVENTORY_PROOF_VERSION)
            ->whereRaw('HEX(release_guid) = ?', [strtoupper(bin2hex($expectedGuid))])->first();
        if ($inventory === null || (int) $inventory->evidence_last_id !== $cursor || (int) $inventory->evidence_count !== $count) {
            return ['known' => true, 'usable' => true, 'overflow' => false, 'donors' => [], 'generation' => $cursor.':'.$count];
        }

        // One matching PAR2 file may be a shared readme or other support file. Only
        // nominate donors when at least two distinct files cover the complete target
        // inventory and the donor has no additional unmatched files.
        $donors = $this->database->select('SELECT DISTINCT r.id AS releases_id, r.guid AS donor_guid,
                r.searchname, r.fromname, r.predb_id, p.title AS canonical_title
            FROM par_file_hashes t
            INNER JOIN releases target ON target.id = t.releases_id AND HEX(target.guid) = HEX(t.release_guid)
            INNER JOIN par_file_hash_inventories target_inventory ON target_inventory.releases_id = target.id
                AND target_inventory.proof_version = ?
                AND HEX(target_inventory.release_guid) = HEX(target.guid)
                AND target_inventory.evidence_last_id = (SELECT COALESCE(MAX(target_generation.id), 0) FROM par_file_hashes target_generation
                    WHERE target_generation.releases_id = target.id AND HEX(target_generation.release_guid) = HEX(target.guid))
                AND target_inventory.evidence_count = (SELECT COUNT(*) FROM par_file_hashes target_generation
                    WHERE target_generation.releases_id = target.id AND HEX(target_generation.release_guid) = HEX(target.guid))
            INNER JOIN par_file_hashes d ON d.full_md5 = t.full_md5 AND d.file_size = t.file_size
                AND HEX(d.full_md5) = HEX(t.full_md5) AND HEX(d.file_size) = HEX(t.file_size)
            INNER JOIN releases r ON r.id = d.releases_id AND HEX(r.guid) = HEX(d.release_guid)
            INNER JOIN par_file_hash_inventories donor_inventory ON donor_inventory.releases_id = r.id
                AND donor_inventory.proof_version = ?
                AND HEX(donor_inventory.release_guid) = HEX(r.guid)
                AND donor_inventory.evidence_last_id = (SELECT COALESCE(MAX(donor_generation.id), 0) FROM par_file_hashes donor_generation
                    WHERE donor_generation.releases_id = r.id AND HEX(donor_generation.release_guid) = HEX(r.guid))
                AND donor_inventory.evidence_count = (SELECT COUNT(*) FROM par_file_hashes donor_generation
                    WHERE donor_generation.releases_id = r.id AND HEX(donor_generation.release_guid) = HEX(r.guid))
            INNER JOIN predb p ON p.id = r.predb_id
            WHERE t.releases_id = ? AND HEX(t.release_guid) = ? AND r.id <> ?
                AND t.id = (SELECT MIN(anchor.id) FROM par_file_hashes anchor
                    WHERE anchor.releases_id = t.releases_id AND HEX(anchor.release_guid) = HEX(t.release_guid)
                        AND anchor.file_size NOT IN (\'\', \'0\'))
                AND t.file_size NOT IN (?, ?)
                AND NOT EXISTS (
                    SELECT 1 FROM par_file_hashes wanted
                    WHERE wanted.releases_id = t.releases_id AND HEX(wanted.release_guid) = HEX(t.release_guid)
                        AND wanted.file_size <> ?
                        AND NOT EXISTS (
                            SELECT 1 FROM par_file_hashes provided
                            WHERE provided.releases_id = r.id AND HEX(provided.release_guid) = HEX(r.guid)
                                AND provided.full_md5 = wanted.full_md5 AND provided.file_size = wanted.file_size
                                AND HEX(provided.full_md5) = HEX(wanted.full_md5)
                                AND HEX(provided.file_size) = HEX(wanted.file_size)
                        )
                )
                AND NOT EXISTS (
                    SELECT 1 FROM par_file_hashes extra
                    WHERE extra.releases_id = r.id AND (
                        HEX(extra.release_guid) <> HEX(r.guid) OR extra.file_size = ?
                        OR (extra.file_size <> ? AND NOT EXISTS (
                            SELECT 1 FROM par_file_hashes present
                            WHERE present.releases_id = t.releases_id AND HEX(present.release_guid) = HEX(t.release_guid)
                                AND present.full_md5 = extra.full_md5 AND present.file_size = extra.file_size
                                AND HEX(present.full_md5) = HEX(extra.full_md5)
                                AND HEX(present.file_size) = HEX(extra.file_size)
                        ))
                    )
                )
            LIMIT 257', [Par2FileHashEvidence::INVENTORY_PROOF_VERSION, Par2FileHashEvidence::INVENTORY_PROOF_VERSION,
            $releaseId, strtoupper(bin2hex($expectedGuid)), $releaseId, '', '0', '0', '', '0']);

        return ['known' => true, 'usable' => true, 'overflow' => count($donors) === 257, 'donors' => $donors, 'generation' => $cursor.':'.$count];
    }

    public function hashEvidenceGeneration(int $releaseId): string
    {
        $row = $this->database->table('par_file_hashes')->where('releases_id', $releaseId)
            ->selectRaw('MAX(id) AS last_id, COUNT(*) AS total')->first();

        return (int) ($row->last_id ?? 0).':'.(int) ($row->total ?? 0);
    }

    /**
     * @param  list<string>  $crcs
     * @return array<string, list<object>>
     */
    public function crcDonors(array $crcs): array
    {
        return $this->donors(
            'SELECT rf.crc32 AS match_key, r.id AS releases_id, r.size AS relsize,
                    r.searchname, r.fromname, r.predb_id
             FROM release_files rf
             INNER JOIN releases r ON r.id = rf.releases_id
             WHERE rf.crc32 IN (%s)
             AND r.predb_id > 0',
            $crcs
        );
    }

    /**
     * Reserve a bounded ID window before matching. Unfinished titles stay eligible and are
     * revisited when the snapshot wraps, including after a worker crashes or its backend fails.
     *
     * @return list<object>
     */
    public function reservePredbBatch(int $worker, int $workers, int $limit): array
    {
        $workerCount = max(1, min(16, $workers));
        $workerSlot = max(1, min($workerCount, $worker)) - 1;
        $key = "predb:fulltext:v1:{$workerCount}:{$workerSlot}";
        $cache = Cache::store('database');
        $store = $cache->getStore();
        if (! $store instanceof DatabaseStore) {
            throw new RuntimeException('PreDB fulltext retries require the persistent database cache store.');
        }
        /** @var DatabaseLock $lock */
        $lock = $store->lock($key.':lock', 60);

        if (! $lock->get()) {
            throw new RuntimeException('Another worker is reserving this PreDB fulltext shard; retry later.');
        }

        try {
            $saved = $cache->get($key);
            $valid = is_array($saved) && isset($saved['last_id'], $saved['max_id'])
                && is_int($saved['last_id']) && is_int($saved['max_id'])
                && $saved['last_id'] >= 0 && $saved['last_id'] <= $saved['max_id'];
            $lastId = $valid ? $saved['last_id'] : 0;
            $maxId = $valid ? $saved['max_id'] : (int) $this->database->table('predb')->max('id');
            $rows = $this->predbBatch($worker, $workers, $limit, $lastId, $maxId);

            if ($rows === [] && $lastId > 0) {
                $maxId = (int) $this->database->table('predb')->max('id');
                $rows = $this->predbBatch($worker, $workers, $limit, 0, $maxId);
            }

            if (! $lock->isOwnedByCurrentProcess()) {
                throw new RuntimeException('PreDB fulltext reservation lock expired; retry later.');
            }

            if ($rows === []) {
                $cache->forget($key);
            } elseif (! $cache->forever($key, ['last_id' => (int) $rows[array_key_last($rows)]->predb_id, 'max_id' => $maxId])) {
                throw new RuntimeException('Unable to persist the PreDB fulltext retry cursor.');
            }

            return $rows;
        } finally {
            $lock->release();
        }
    }

    /** Count only enough eligible titles to fill the launcher's worker capacity. */
    public function countPredbLauncherCandidates(int $maxPerRun, int $maxWorkers): int
    {
        if ($maxPerRun <= 0 || $maxWorkers <= 0) {
            return 0;
        }

        $limit = $maxPerRun > intdiv(PHP_INT_MAX, $maxWorkers)
            ? PHP_INT_MAX
            : $maxPerRun * $maxWorkers;
        $cutoff = CarbonImmutable::now()->subDay()->toDateTimeString();
        $rows = $this->database->select(
            'SELECT COUNT(*) AS num FROM (
                SELECT p.id FROM predb p
                WHERE LENGTH(p.title) >= 15
                AND p.title NOT REGEXP \'["<> ]\'
                AND p.searched = 0
                AND (p.predate < ? OR (p.predate IS NULL AND p.imported_at < ?))
                LIMIT ?
             ) AS eligible',
            [$cutoff, $cutoff, $limit]
        );

        return (int) ($rows[0]->num ?? 0);
    }

    /**
     * @return list<object>
     */
    public function predbBatch(int $worker, int $workers, int $limit, int $afterId = 0, int $maxId = PHP_INT_MAX): array
    {
        $workerCount = max(1, min(16, $workers));
        $workerSlot = max(1, min($workerCount, $worker)) - 1;

        return $this->database->select(
            'SELECT p.id AS predb_id, p.title, p.source, p.searched
             FROM predb p
             WHERE LENGTH(p.title) >= 15
             AND p.title NOT REGEXP \'["<> ]\'
             AND p.searched = 0
             AND (p.predate < ? OR (p.predate IS NULL AND p.imported_at < ?))
             AND MOD(p.id, ?) = ?
             AND p.id > ? AND p.id <= ?
             ORDER BY p.id ASC
             LIMIT ?',
            [
                CarbonImmutable::now()->subDay()->toDateTimeString(),
                CarbonImmutable::now()->subDay()->toDateTimeString(),
                $workerCount,
                $workerSlot,
                max(0, $afterId),
                max(0, $maxId),
                max(1, $limit),
            ]
        );
    }

    /**
     * @param  list<int>  $candidateIds
     * @return list<object>
     */
    public function confirmPredbCandidates(array $candidateIds, string $title): array
    {
        if ($candidateIds === []) {
            return [];
        }

        $like = '%'.$this->escapeLike($title).'%';

        return $this->database->select(
            'SELECT r.id AS releases_id, r.guid, r.name, r.fromname, r.searchname,
                    r.groups_id, r.categories_id
             FROM releases r
             WHERE r.id IN ('.$this->placeholders(count($candidateIds)).')
             AND r.predb_id = 0
             AND (r.name LIKE ? ESCAPE \'\\\\\' OR r.searchname LIKE ? ESCAPE \'\\\\\')
             ORDER BY r.id ASC
             LIMIT 21',
            array_merge($candidateIds, [$like, $like])
        );
    }

    /**
     * Check only enumerated candidates for a concurrent attachment to the intended PreDB row.
     *
     * @param  list<int>  $candidateIds
     * @return list<object>
     */
    public function linkedPredbCandidates(array $candidateIds, int $predbId): array
    {
        if ($candidateIds === [] || $predbId <= 0) {
            return [];
        }

        return $this->database->select(
            'SELECT r.searchname FROM releases r
             WHERE r.id IN ('.$this->placeholders(count($candidateIds)).') AND r.predb_id = ?',
            array_merge($candidateIds, [$predbId]),
            false
        );
    }

    /**
     * Read a strictly capped indexed PreDB-ID range when discovery omitted an existing link.
     *
     * @return list<object>
     */
    public function existingPredbLinks(int $predbId): array
    {
        if ($predbId <= 0) {
            return [];
        }

        return $this->database->select(
            'SELECT r.searchname FROM releases r WHERE r.predb_id = ? ORDER BY r.id ASC LIMIT 17',
            [$predbId],
            false
        );
    }

    public function countPrefileCandidates(): int
    {
        $rows = $this->database->select(
            'SELECT COUNT(*) AS aggregate
             FROM releases r
             WHERE r.predb_id = 0
             AND r.isrenamed = 0
             AND r.categories_id IN ('.implode(',', Category::OTHERS_GROUP).')
             AND EXISTS (
                SELECT 1 FROM release_files rf
                WHERE rf.releases_id = r.id
                AND rf.name IS NOT NULL
             )'
        );

        return (int) ($rows[0]->aggregate ?? 0);
    }

    /**
     * @return list<object>
     */
    public function prefileCandidateBatch(int $cursor, int $limit, bool $descending): array
    {
        $operator = $descending ? '<' : '>';
        $direction = $descending ? 'DESC' : 'ASC';

        return $this->database->select(
            'SELECT r.id AS releases_id, r.guid, r.name, r.searchname, r.fromname,
                    r.groups_id, r.categories_id
             FROM releases r
             WHERE r.predb_id = 0
             AND r.isrenamed = 0
             AND r.categories_id IN ('.implode(',', Category::OTHERS_GROUP).")
             AND r.id {$operator} ?
             AND EXISTS (
                SELECT 1 FROM release_files rf
                WHERE rf.releases_id = r.id
                AND rf.name IS NOT NULL
             )
             ORDER BY r.id {$direction}
             LIMIT ?",
            [$cursor, max(1, $limit)]
        );
    }

    /**
     * @param  list<object>  $rows
     * @return array<int, list<object>>
     */
    public function groupByReleaseId(array $rows): array
    {
        return $this->groupByKey($rows, 'releases_id');
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function candidateWhere(string $source, int $time, int $categories): array
    {
        if (! isset(self::SOURCE_EXISTS[$source], self::STATUS_COLUMNS[$source])) {
            throw new InvalidArgumentException("Unsupported name-fixing source [{$source}].");
        }

        $where = ['r.predb_id = 0', '('.self::SOURCE_EXISTS[$source].')'];
        $bindings = [];

        if ($categories !== 3) {
            $statusColumn = self::STATUS_COLUMNS[$source];
            $where[] = '(r.isrenamed = 0 OR r.categories_id IN (?, ?))';
            $bindings[] = Category::OTHER_MISC;
            $bindings[] = Category::OTHER_HASHED;
            $where[] = "r.{$statusColumn} = 0";
        }

        if ($time === 1) {
            $where[] = 'r.adddate > ?';
            $bindings[] = CarbonImmutable::now()->subHours(6)->toDateTimeString();
        }

        if ($categories === 1) {
            $where[] = 'r.categories_id IN ('.implode(',', Category::OTHERS_GROUP).')';
        }

        // PAR2 names come from the NZB, and an in-place upgrade resets every other source's status while that
        // source's rows still describe the smaller NZB: a partial awaiting late articles is renamed once settled.
        $where[] = Release::nzbSettledSql('r');

        if ($source === self::SOURCE_HASH) {
            $where[] = 'NOT EXISTS (SELECT 1 FROM par2_hash_retry hr WHERE hr.releases_id = r.id
                AND HEX(hr.release_guid) = HEX(r.guid) AND hr.retry_at > ?)';
            $bindings[] = CarbonImmutable::now()->toDateTimeString();
        }

        return [implode(' AND ', $where), $bindings];
    }

    /**
     * @param  list<int>  $releaseIds
     * @return list<object>
     */
    private function selectForReleaseIds(
        string $sql,
        array $releaseIds,
        int $placeholderGroups = 1
    ): array {
        if ($releaseIds === []) {
            return [];
        }

        $placeholder = $this->placeholders((int) (count($releaseIds) / $placeholderGroups));
        $sql = vsprintf($sql, array_fill(0, $placeholderGroups, $placeholder));

        return $this->database->select($sql, $releaseIds);
    }

    /**
     * @param  list<string>  $values
     * @return array<string, list<object>>
     */
    private function donors(string $sql, array $values): array
    {
        if ($values === []) {
            return [];
        }

        $rows = $this->database->select(
            sprintf($sql, $this->placeholders(count($values))),
            $values
        );

        return $this->groupByKey($rows, 'match_key');
    }

    /**
     * @param  list<object>  $rows
     * @return array<int|string, list<object>>
     */
    private function groupByKey(array $rows, string $key): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $grouped[$row->{$key}][] = $row;
        }

        return $grouped;
    }

    private function placeholders(int $count): string
    {
        return implode(',', array_fill(0, $count, '?'));
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
