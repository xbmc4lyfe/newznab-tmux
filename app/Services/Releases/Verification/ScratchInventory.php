<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Candidate scratch is registered under the worker lock before directory creation.
 * Unregistered external directories and audit scratch are never discovered or deleted here.
 */
final class ScratchInventory
{
    public function register(int $candidateId, int $version): void
    {
        DB::table('release_verification_scratch')->insertOrIgnore(['candidate_id' => $candidateId, 'version' => $version]);
    }

    /** @return Collection<int, \stdClass> */
    public function olderVersions(int $candidateId, int $version): Collection
    {
        return DB::table('release_verification_scratch')->where('candidate_id', $candidateId)->where('version', '<', $version)
            ->orderBy('version')->limit(100)->get();
    }

    public function forget(int $id): void
    {
        DB::table('release_verification_scratch')->where('id', $id)->delete();
    }
}
