<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Release NZBs stored as rows of release_nzbs: the gzipped document a legacy .nzb.gz file held, keyed
 * by release. With nntmux_settings.nzb_storage = database (the default) new NZBs are written here
 * inside the release's transaction; readers fall back to legacy files until nzb:import-files copies
 * them in. In files mode rows written earlier stay readable, so switching back loses nothing.
 */
final class NzbBlobStore
{
    public const string TABLE = 'release_nzbs';

    /** Room left in one statement for everything but the body. */
    private const int PACKET_OVERHEAD = 65536;

    /** Whether the table exists, checked once per instance in files mode. */
    private ?bool $tableExists = null;

    private ?int $packetLimit = null;

    public static function usesDatabase(): bool
    {
        return config('nntmux_settings.nzb_storage', 'database') === 'database';
    }

    public function tableAvailable(): bool
    {
        return self::usesDatabase() || ($this->tableExists ??= Schema::hasTable(self::TABLE));
    }

    /**
     * The stored (compressed) NZB of a release. $forUpdate takes a current read under the caller's
     * release lock; other reads inside a transaction share-lock the row so they see what the importer
     * committed after their snapshot, as manifest reads do.
     */
    public function body(int $releaseId, bool $forUpdate = false): ?string
    {
        if (! $this->tableAvailable()) {
            return null;
        }
        $body = $this->row($releaseId, $forUpdate)->value('body');

        return is_string($body) ? $body : null;
    }

    /** The stored size, read as body() reads the row. */
    public function bytes(int $releaseId): ?int
    {
        if (! $this->tableAvailable()) {
            return null;
        }
        $bytes = $this->row($releaseId)->value('bytes');

        return $bytes === null ? null : (int) $bytes;
    }

    public function exists(int $releaseId): bool
    {
        return $this->tableAvailable() && $this->row($releaseId)->exists();
    }

    /** The release a GUID names, or null; GUIDs never move between releases. */
    public function releaseId(string $guid): ?int
    {
        if ($guid === '' || ! $this->tableAvailable()) {
            return null;
        }
        $id = DB::table('releases')->where('guid', $guid)->value('id');

        return $id === null ? null : (int) $id;
    }

    /** Insert or replace a release's NZB. The caller holds the release row lock in its transaction. */
    public function store(int $releaseId, string $compressed, string $digest): void
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('NZB storage requires the caller transaction.');
        }
        if (preg_match('/\A[0-9a-f]{64}\z/', $digest) !== 1 || $compressed === '') {
            throw new RuntimeException('Invalid NZB row.');
        }
        $limit = $this->packetLimit();
        if ($limit !== null && strlen($compressed) + self::PACKET_OVERHEAD > $limit) {
            throw new RuntimeException(sprintf('Compressed NZB of %d bytes exceeds the database max_allowed_packet of %d bytes; raise it to store this NZB.',
                strlen($compressed), $limit));
        }
        // One statement, without a locking read first: the caller's release lock already serializes writers, and
        // locking an absent key gap-locks the primary index that concurrent installs insert into.
        DB::table(self::TABLE)->upsert([['releases_id' => $releaseId, 'body' => $compressed, 'bytes' => strlen($compressed),
            'source_sha256' => $digest, 'updated_at' => now()]], ['releases_id'], ['body', 'bytes', 'source_sha256', 'updated_at']);
    }

    /**
     * A current read inside a transaction: under REPEATABLE READ a plain read misses a row the importer committed
     * after the snapshot, while its file may already be gone.
     */
    private function row(int $releaseId, bool $forUpdate = false): Builder
    {
        $query = DB::table(self::TABLE)->where('releases_id', $releaseId);
        if ($forUpdate) {
            $query->lockForUpdate();
        } elseif (DB::transactionLevel() > 0) {
            $query->sharedLock();
        }

        return $query;
    }

    /** The server's max_allowed_packet on MySQL/MariaDB, read once per instance; null elsewhere. */
    private function packetLimit(): ?int
    {
        if ($this->packetLimit === null && in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->packetLimit = (int) (DB::selectOne('SELECT @@max_allowed_packet AS packet')->packet ?? 0);
        }

        return $this->packetLimit === null || $this->packetLimit <= 0 ? null : $this->packetLimit;
    }

    public function delete(int $releaseId): bool
    {
        return $this->tableAvailable() && DB::table(self::TABLE)->where('releases_id', $releaseId)->delete() > 0;
    }
}
