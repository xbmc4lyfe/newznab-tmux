<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Models\Release;
use App\Models\ReleaseRegex;
use App\Services\Nzb\NzbService;
use App\Services\Releases\ReleaseDuplicateFinder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class VerifiedReleasePublisher
{
    public function __construct(private readonly VerifiedNzbWriter $writer,
        private readonly ReleaseMetadataResolver $metadata,
        private readonly ReleaseDuplicateFinder $duplicates) {}

    public function publish(object $claim, VerificationResult $result): ?int
    {
        if (! $result->passed()) {
            return null;
        }

        return DB::transaction(function () use ($claim, $result): ?int {
            DB::table('release_verification_state')->where('id', 1)->lockForUpdate()->first();
            $candidate = DB::table('release_verification_candidates')->where('id', $claim->id)->lockForUpdate()->first();
            if ($candidate === null || $candidate->version !== $claim->version
                || $candidate->manifest_hash !== $claim->manifest_hash || $candidate->lease_token !== $claim->lease_token
                || $candidate->lease_until === null || $candidate->lease_until < now()->format('Y-m-d H:i:s')) {
                throw new RuntimeException('Stale verification result cannot publish.');
            }
            if ($candidate->release_id !== null) {
                return (int) $candidate->release_id;
            }
            $manifest = $result->manifest;
            $filteredReason = app(CollectionReleasePolicy::class)->verifiedRejection($manifest);
            if ($filteredReason !== null) {
                app(CandidateRepository::class)->rejectByPolicy($claim, $filteredReason);

                return null;
            }
            $metadata = $this->metadata->resolve($manifest);
            $size = 0;
            foreach ($manifest['files'] as $file) {
                $size += (int) ($file['decoded_size'] ?? array_sum(array_column($file['segments'], 'bytes')));
            }
            $size = (int) ($manifest['verified_bytes'] ?? $size);
            [$duplicate] = $this->duplicates->findDuplicate($metadata['name'], $metadata['searchname'], $metadata['predb_id'], $size);
            $duplicateGuid = $duplicate === null ? null : DB::table('releases')->where('id', $duplicate->id)->value('guid');
            if (is_string($duplicateGuid) && $this->writer->matches($manifest, $duplicateGuid)) {
                $this->markPublished($candidate->id, (int) $duplicate->id, $result);

                return (int) $duplicate->id;
            }
            $guid = (string) Str::uuid();
            $path = $this->writer->write($manifest, $guid);
            DB::afterRollBack(static function () use ($path): void {
                if (is_file($path)) {
                    unlink($path);
                }
            });
            $id = Release::insertRelease($metadata + [
                'totalpart' => ($manifest['metadata_source'] ?? null) !== 'import'
                    ? count(array_unique($manifest['verified_file_names'])) : max((int) $manifest['expected_files'], count($manifest['files'])),
                'groups_id' => (int) $manifest['groups_id'], 'guid' => $guid, 'postdate' => $manifest['date'],
                'size' => $size, 'nzbstatus' => NzbService::NZB_ADDED]);
            if ($id === null) {
                throw new RuntimeException('Verified release insertion failed.');
            }
            ReleaseRegex::insertOrIgnore(['releases_id' => $id, 'collection_regex_id' => $manifest['regex_id'],
                'naming_regex_id' => $metadata['naming_regex_id']]);
            foreach (DB::table('usenet_groups')->whereIn('name', $manifest['groups'])->pluck('id') as $groupId) {
                DB::table('releases_groups')->insertOrIgnore(['releases_id' => $id, 'groups_id' => $groupId]);
            }
            $this->markPublished($candidate->id, (int) $id, $result);

            return (int) $id;
        }, 5);
    }

    private function markPublished(int $candidateId, int $releaseId, VerificationResult $result): void
    {
        $candidate = DB::table('release_verification_candidates')->where('id', $candidateId)->first();
        DB::table('release_verification_state')->where('id', 1)->decrement('pending_bytes', (int) $candidate->manifest_bytes);
        DB::table('release_verification_candidates')->where('id', $candidateId)->update([
            'release_id' => $releaseId, 'status' => $result->verdict, 'reason' => $result->reason,
            'verified_at' => now(), 'lease_token' => null, 'lease_until' => null, 'manifest_bytes' => 0, 'manifest_path' => '',
        ]);
        DB::afterCommit(function () use ($candidate): void {
            app(ManifestStore::class)->deleteIfUnreferenced($candidate->manifest_path);
        });
    }
}
