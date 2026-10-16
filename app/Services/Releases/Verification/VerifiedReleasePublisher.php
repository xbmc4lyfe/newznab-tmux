<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Facades\Search;
use App\Jobs\CollectReleaseFingerprintMetadata;
use App\Models\Release;
use App\Models\ReleaseRegex;
use App\Services\Nzb\NzbArticleCoverage;
use App\Services\Nzb\NzbArticleFingerprint;
use App\Services\Nzb\NzbService;
use App\Services\Releases\ReleaseDuplicateFinder;
use App\Services\Releases\ReleaseGroupResolver;
use App\Support\NzbPasswordMetadata;
use App\Support\ReleasePackEvidence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

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

        $timing = PublicationTiming::sample();
        $status = 'threw';
        try {
            $id = DB::transaction(function () use ($claim, $result, $timing): ?int {
                $timing?->beginAttempt();
                $returned = false;
                try {
                    DB::table('release_verification_state')->where('id', 1)->lockForUpdate()->first();
                    $timing?->nextPhase('candidate_policy');
                    $candidate = DB::table('release_verification_candidates')->where('id', $claim->id)->lockForUpdate()->first();
                    if ($candidate === null || $candidate->version !== $claim->version
                        || $candidate->manifest_hash !== $claim->manifest_hash || $candidate->lease_token !== $claim->lease_token
                        || $candidate->lease_until === null || $candidate->lease_until < now()->format('Y-m-d H:i:s')) {
                        throw new RuntimeException('Stale verification result cannot publish.');
                    }
                    if ($candidate->release_id !== null) {
                        $returned = true;

                        return (int) $candidate->release_id;
                    }
                    $manifest = $result->manifest;
                    if ($result->verdict === 'sampled') {
                        if (($manifest['verification_mode'] ?? null) !== 'spot_check') {
                            throw new RuntimeException('Sampled verdict has no spot-check evidence.');
                        }
                        if (($rejection = (new CandidateHeaderInventory)->rejection($manifest)) !== null) {
                            throw new RuntimeException($rejection);
                        }
                    }
                    $filteredReason = app(CollectionReleasePolicy::class)->verifiedRejection($manifest);
                    if ($filteredReason !== null) {
                        app(CandidateRepository::class)->rejectByPolicy($claim, $filteredReason);

                        $returned = true;

                        return null;
                    }
                    $timing?->nextPhase('metadata_size');
                    $metadata = $this->metadata->resolve($manifest);
                    if (($manifest['verification_mode'] ?? null) === 'spot_check') {
                        $size = (new CandidateHeaderInventory)->bytes($manifest);
                        if ($size < 1) {
                            throw new RuntimeException('Spot-checked release has no positive declared size.');
                        }
                    } else {
                        $size = 0;
                        foreach ($manifest['files'] as $file) {
                            $size += (int) ($file['decoded_size'] ?? array_sum(array_column($file['segments'], 'bytes')));
                        }
                        $size = (int) ($manifest['verified_bytes'] ?? $size);
                    }
                    $timing?->nextPhase('archive_legacy');
                    $legacy = null;
                    $archived = app(ManifestStore::class)->read($candidate->manifest_path);
                    $legacyId = $archived['legacy_release_id'] ?? null;
                    if ($legacyId !== ($manifest['legacy_release_id'] ?? null)
                        || ($legacyId !== null && ($archived['metadata_source'] ?? null) === 'import')) {
                        throw new RuntimeException('Legacy release identity is not trusted.');
                    }
                    if ($legacyId !== null) {
                        $legacy = DB::table('releases')->where('id', $legacyId)->lockForUpdate()->first();
                        if ($legacy === null || (int) $legacy->nzbstatus !== NzbService::NZB_NONE
                            || (int) $legacy->groups_id !== (int) $manifest['groups_id']) {
                            throw new RuntimeException('Legacy release identity changed before publication.');
                        }
                    }
                    $duplicate = $legacy === null ? $this->findCoveredDuplicate($claim, $manifest, $metadata, $size, $timing) : ['release_id' => null, 'metadata_conflict' => false];
                    $duplicateId = $duplicate['release_id'];
                    if ($duplicateId !== null || $duplicate['metadata_conflict']) {
                        $timing?->nextPhase('sql_accounting');
                        if ($duplicateId === null) {
                            // Article reuse must not discard uploader assertions or create the same articles again.
                            DB::table('release_verification_candidates')->where('id', $candidate->id)->update([
                                'status' => 'metadata_review', 'reason' => 'Covered release does not preserve supplied metadata; review retained candidate.',
                                'lease_token' => null, 'lease_until' => null, 'verified_at' => null,
                            ]);

                            $returned = true;

                            return null;
                        }
                        $this->recordGroups($duplicateId, $manifest['groups']);
                        app(CandidateRepository::class)->heartbeat((int) $claim->id, $claim->lease_token);
                        $this->markPublished($candidate->id, $duplicateId, $result);

                        $returned = true;

                        return $duplicateId;
                    }
                    $guid = $legacy === null ? (string) Str::uuid() : (string) $legacy->guid;
                    $parameters = $metadata + [
                        'totalpart' => ($manifest['verification_mode'] ?? null) === 'spot_check'
                            ? max(0, (int) ($manifest['publication_file_count'] ?? $manifest['expected_files'] ?? count($manifest['files'])))
                            : (($manifest['metadata_source'] ?? null) !== 'import'
                                ? count(array_unique($manifest['verified_file_names'])) : max((int) $manifest['expected_files'], count($manifest['files']))),
                        'groups_id' => (int) $manifest['groups_id'], 'guid' => $guid, 'postdate' => $manifest['date'],
                        'size' => $size, 'nzbstatus' => NzbService::NZB_ADDED];
                    $timing?->nextPhase('release_identity_sql');
                    if ($legacy === null) {
                        $id = Release::insertRelease($parameters);
                    } else {
                        $id = (int) $legacy->id;
                        unset($parameters['naming_regex_id']);
                        $processingReset = ReleasePackEvidence::retireDerivedEvidence($id);
                        DB::table('releases')->where('id', $id)->update($processingReset + $parameters + [
                            'iscategorized' => 1,
                            ReleasePackEvidence::COLUMN => ReleasePackEvidence::tombstone($legacy),
                        ]);
                        DB::afterCommit(static fn () => Search::updateRelease($id));
                    }
                    if ($id === null) {
                        throw new RuntimeException('Verified release insertion failed.');
                    }
                    $release = DB::table('releases')->where('id', $id)->where('guid', $guid)->lockForUpdate()->first(['id', 'guid']);
                    if ($release === null) {
                        throw new RuntimeException('Verified release identity disappeared before NZB installation.');
                    }
                    $timing?->nextPhase('final_xml_gzip');
                    $receipt = $this->writer->writeWithReceipt($manifest, $guid, $release, $timing);
                    DB::afterRollBack(static fn () => $receipt->rollback());
                    DB::afterCommit(static fn () => $receipt->close());
                    $timing?->nextPhase('sql_accounting');
                    ReleaseRegex::insertOrIgnore(['releases_id' => $id, 'collection_regex_id' => $manifest['regex_id'],
                        'naming_regex_id' => $metadata['naming_regex_id']]);
                    DB::afterCommit(static function () use ($id): void {
                        try {
                            CollectReleaseFingerprintMetadata::dispatch((int) $id);
                        } catch (Throwable $dispatchException) {
                            Log::warning('Could not queue verified release fingerprint metadata collection.', [
                                'release_id' => $id,
                                'exception' => $dispatchException::class,
                            ]);
                        }
                    });
                    $this->recordGroups((int) $id, $manifest['groups']);
                    app(CandidateRepository::class)->heartbeat((int) $claim->id, $claim->lease_token);
                    $this->markPublished($candidate->id, (int) $id, $result);

                    $returned = true;

                    return (int) $id;
                } finally {
                    $timing?->endAttempt($returned);
                }
            }, 5);
            $status = 'returned';

            return $id;
        } finally {
            $timing?->report($status);
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $metadata
     * @return array{release_id: ?int, metadata_conflict: bool}
     */
    private function findCoveredDuplicate(object $claim, array $manifest, array $metadata, int $size, ?PublicationTiming $timing): array
    {
        $lastHeartbeat = 0.0;
        $repository = app(CandidateRepository::class);
        $heartbeat = function (Release $candidate) use ($repository, $claim, &$lastHeartbeat): void {
            if (microtime(true) - $lastHeartbeat >= 1) {
                $repository->heartbeat((int) $claim->id, $claim->lease_token);
                $lastHeartbeat = microtime(true);
            }
            // Hold the nominated published row stable while reading its retained generation.
            DB::table('releases')->where('id', $candidate->id)->where('nzbstatus', NzbService::NZB_ADDED)
                ->lockForUpdate()->first(['id', 'guid']);
        };
        $coverage = new NzbArticleCoverage(app(NzbService::class), $this->duplicates);
        $timing?->nextPhase('incoming_xml_fingerprint');
        $xml = $coverage->parseXml($this->writer->document($manifest));
        $fingerprint = $xml === null ? null : NzbArticleFingerprint::fromXml($xml);
        $timing?->nextPhase('duplicate_search_revalidation');
        $metadataConflict = false;
        $acceptCovered = function (Release $duplicate, \SimpleXMLElement $snapshot) use ($manifest, &$metadataConflict): bool {
            $current = DB::table('releases')->where('id', $duplicate->id)->where('nzbstatus', NzbService::NZB_ADDED)
                ->lockForUpdate()->first(['id', 'guid']);
            if ($current === null || $current->guid !== (string) $duplicate->guid) {
                return false;
            }
            if (! $this->duplicatePreservesPasswordMetadata($snapshot, $manifest)) {
                $metadataConflict = true;

                return false;
            }

            return true;
        };
        $duplicate = $xml === null ? null : $coverage->findCoveredUpload($size, $fingerprint, $xml,
            $metadata['name'], $metadata['searchname'], $metadata['predb_id'], publishedOnly: true,
            onCandidate: $heartbeat, acceptCovered: $acceptCovered);
        $repository->heartbeat((int) $claim->id, $claim->lease_token);

        return ['release_id' => $duplicate === null ? null : (int) $duplicate->id, 'metadata_conflict' => $metadataConflict];
    }

    /** @param array<string, mixed> $manifest */
    private function duplicatePreservesPasswordMetadata(\SimpleXMLElement $snapshot, array $manifest): bool
    {
        $passwords = $manifest['passwords'] ?? [];
        if ($passwords === []) {
            return true;
        }
        if (! NzbPasswordMetadata::isValid($passwords)) {
            return false;
        }
        $contents = $snapshot->asXML();
        if (! is_string($contents)) {
            return false;
        }
        try {
            $retained = app(NzbManifestReader::class)->read($contents, (object) [
                'groups_id' => $manifest['groups_id'], 'searchname' => $manifest['subject'],
                'fromname' => $manifest['fromname'], 'postdate' => $manifest['date'],
            ]);

            return ($retained['passwords'] ?? []) === $passwords;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param list<string> $names */
    private function recordGroups(int $releaseId, array $names): void
    {
        foreach (array_unique(app(ReleaseGroupResolver::class)->idsByName($names)) as $groupId) {
            DB::table('releases_groups')->insertOrIgnore(['releases_id' => $releaseId, 'groups_id' => $groupId]);
        }
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
