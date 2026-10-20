<?php

declare(strict_types=1);

namespace App\Services\Releases\Fingerprint;

use App\Services\Nzb\DatabaseNzbStore;
use App\Services\Nzb\NzbService;
use App\Services\Nzb\NzbSourceGuard;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Producers only enqueue; standalone Rust processes own all fingerprint execution. */
final class DatabaseFingerprintQueue
{
    public function enqueue(int $releaseId, bool $force = false, bool $importLegacy = false): bool
    {
        return DB::transaction(function () use ($releaseId, $force, $importLegacy): bool {
            $release = DB::table('releases')->where('id', $releaseId)->lockForUpdate()->first(['id', 'guid']);
            if ($release === null) {
                return false;
            }
            $content = DB::table('release_nzb_contents')->where('guid', $release->guid)->lockForUpdate()->first(['source_digest']);
            if ($content === null) {
                if (! $importLegacy || ! config('nntmux.nzb_legacy_reads', false)) {
                    return false;
                }
                $xml = app(NzbService::class)->readLegacyNzbContents((string) $release->guid);
                if (! is_string($xml)) {
                    return false;
                }
                $digest = NzbSourceGuard::digest($xml);
                $committed = DB::table('release_archive_naming_evidence')->where('releases_id', $release->id)->value('source_sha256');
                if ($committed !== null && ! hash_equals($committed, $digest)) {
                    throw new RuntimeException('Legacy NZB does not match the committed source generation.');
                }
                app(DatabaseNzbStore::class)->putLocked($release, gzencode($xml));

                return true;
            }
            $this->enqueueLocked($release, $content->source_digest, $force);

            return true;
        });
    }

    public function enqueueLocked(object $release, string $digest, bool $force = false): void
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('Fingerprint enqueue requires the source transaction.');
        }
        $job = DB::table('release_fingerprint_jobs')->where('release_id', $release->id)->lockForUpdate()->first();
        if ($job !== null && hash_equals($job->source_digest, $digest)
            && (! $force || in_array($job->status, ['pending', 'processing'], true))) {
            return;
        }
        // Replacing the source fences even an in-flight claim immediately in the same transaction.
        $values = ['source_digest' => $digest, 'status' => 'pending', 'attempts' => 0, 'available_at' => in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true) ? DB::raw('UTC_TIMESTAMP(6)') : now('UTC'),
            'lease_token' => null, 'lease_until' => null, 'worker_id' => null, 'last_error' => null, 'updated_at' => now('UTC')];
        if ($job === null) {
            DB::table('release_fingerprint_jobs')->insert(['release_id' => $release->id, 'created_at' => now('UTC')] + $values);
        } else {
            DB::table('release_fingerprint_jobs')->where('release_id', $release->id)->update($values);
        }
        DB::table('release_fingerprint_metadata')->where('releases_id', $release->id)->update(['status' => 'stale', 'run_token' => null, 'updated_at' => now('UTC')]);
    }
}
