<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Candidate and audit scratch are registered under the worker lock before creation.
 * Unregistered external directories are never discovered or deleted here, and each worker
 * host only measures, cleans or forgets the entries it created.
 */
final class ScratchInventory
{
    /** The worker host owning scratch created here; the primary host is ''. */
    public static function host(): string
    {
        return (string) config('release_verification.host', '');
    }

    public function register(int $candidateId, int $version): void
    {
        DB::table('release_verification_scratch')->insertOrIgnore(['host' => self::host(), 'candidate_id' => $candidateId, 'version' => $version]);
    }

    /** This host's inventory entry for a candidate version. */
    public function entryId(int $candidateId, int $version): int
    {
        return (int) DB::table('release_verification_scratch')->where('host', self::host())->where('candidate_id', $candidateId)
            ->where('version', $version)->value('id');
    }

    public function registerAudit(int $releaseId): \stdClass
    {
        $active = DB::table('release_verification_scratch')->where('host', self::host())->where('audit_release_id', $releaseId)
            ->where('terminal', false)->first();
        if ($active !== null) {
            return $active;
        }
        $id = DB::table('release_verification_scratch')->insertGetId(['host' => self::host(), 'audit_release_id' => $releaseId]);

        return DB::table('release_verification_scratch')->where('id', $id)->sole();
    }

    public function finishAudit(int $id): void
    {
        DB::table('release_verification_scratch')->where('id', $id)->whereNotNull('audit_release_id')->update(['terminal' => true]);
    }

    public function recordAudit(int $id, string $verdict, string $reason): void
    {
        DB::table('release_verification_scratch')->where('id', $id)->whereNotNull('audit_release_id')->update([
            'audit_verdict' => $verdict, 'audit_reason' => mb_strcut($reason, 0, 2000, 'UTF-8'),
        ]);
    }

    public function directoryName(object $entry): string
    {
        return $entry->audit_release_id === null ? $entry->candidate_id.'-'.$entry->version : 'audit-'.$entry->audit_release_id.'-'.$entry->id;
    }

    /** @return Collection<int, \stdClass> */
    public function olderVersions(int $candidateId, int $version): Collection
    {
        return DB::table('release_verification_scratch')->where('host', self::host())->where('candidate_id', $candidateId)->where('version', '<', $version)
            ->orderBy('version')->limit(100)->get();
    }

    public function forget(int $id): void
    {
        if (Schema::hasTable('release_verification_scratch_budget')) {
            app(ScratchBudget::class)->forget($id);
        } else {
            DB::table('release_verification_scratch')->where('id', $id)->delete();
        }
    }
}
