<?php

declare(strict_types=1);

namespace App\Services\Nzb;

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

    /** Whether the table exists, checked once per instance in files mode. */
    private ?bool $tableExists = null;

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
        $query = DB::table(self::TABLE)->where('releases_id', $releaseId);
        if ($forUpdate) {
            $query->lockForUpdate();
        } elseif (DB::transactionLevel() > 0) {
            $query->sharedLock();
        }
        $body = $query->value('body');

        return is_string($body) ? $body : null;
    }

    public function bytes(int $releaseId): ?int
    {
        if (! $this->tableAvailable()) {
            return null;
        }
        $bytes = DB::table(self::TABLE)->where('releases_id', $releaseId)->value('bytes');

        return $bytes === null ? null : (int) $bytes;
    }

    public function exists(int $releaseId): bool
    {
        return $this->tableAvailable() && DB::table(self::TABLE)->where('releases_id', $releaseId)->exists();
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
        $values = ['body' => $compressed, 'bytes' => strlen($compressed), 'source_sha256' => $digest, 'updated_at' => now()];
        // One statement per row: a large NZB must stay under the packet limit.
        if (DB::table(self::TABLE)->where('releases_id', $releaseId)->lockForUpdate()->exists()) {
            DB::table(self::TABLE)->where('releases_id', $releaseId)->update($values);
        } else {
            DB::table(self::TABLE)->insert(['releases_id' => $releaseId] + $values);
        }
    }

    public function delete(int $releaseId): bool
    {
        return $this->tableAvailable() && DB::table(self::TABLE)->where('releases_id', $releaseId)->delete() > 0;
    }
}
