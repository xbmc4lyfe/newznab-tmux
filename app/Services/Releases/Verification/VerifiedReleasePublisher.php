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

    /**
     * @param  PublicationTiming|false|null  $timing  A sample the caller already drew (the remote controller, which
     *                                                times its own request work first); false when it drew none; null to draw here.
     * @param  array{path: string, manifest: array<string, mixed>}|null  $archived  The archived manifest the caller already read, and its path.
     *                                                                              Manifests are named by the hash of their contents, so it is
     *                                                                              reused only while the claim's row names that same path.
     */
    public function publish(object $claim, VerificationResult $result, string $source = 'local', PublicationTiming|false|null $timing = null, ?array $archived = null): ?int
    {
        if (! $result->passed()) {
            return null;
        }

        $timing = $timing === false ? null : ($timing ?? PublicationTiming::sample());
        $timing?->setSource($source);
        $outcome = 'threw';
        $status = 'threw';
        // Resolve the duplicate-search keys once, before taking any lock, so the publication locks
        // exactly the keys its search will use. Without them it falls back to the shared row. An
        // outer transaction would take its REPEATABLE READ view from these reads, before the locks,
        // so there metadata is resolved after the exclusive fallback, as before striping.
        $preResolved = null;
        $keys = [];
        // The incoming NZB parse and fingerprint depend only on the manifest; computing them here,
        // before any lock, keeps that CPU work out of the serialized publication section.
        $incoming = null;
        $size = null;
        // A plain read is harmless outside a transaction; it skips the work for a stale claim,
        // which the locked validation below rejects anyway.
        $claimedPath = DB::transactionLevel() === 0 ? $this->currentManifestPath($claim) : null;
        $current = $claimedPath !== null;
        $timing?->preLockPhase('claim_check');
        if ($current) {
            // The step in progress, so a failure is timed under the step that threw.
            $step = 'metadata_resolve';
            try {
                $preResolved = $this->metadata->resolve($result->manifest);
                $timing?->preLockPhase($step);
                $step = 'incoming_document';
                $incoming = $this->incomingDocument($result->manifest);
                $timing?->preLockPhase($step);
                $step = 'key_derivation';
                $size = $this->publicationSize($result->manifest);
                $keys = VerificationLedger::publicationKeys($size, $incoming['fingerprint'],
                    (string) $preResolved['name'], (string) $preResolved['searchname'], (int) $preResolved['predb_id']);
            } catch (Throwable) {
                if ($step !== 'key_derivation') {
                    $timing?->preLockPhase($step);
                }
                $preResolved = null;
                $incoming = null;
                $size = null;
                $keys = [];
            }
        }
        $timing?->preLockPhase('key_derivation');
        // Everything below depends only on the manifest and unlocked reads, so it runs before the
        // locks; the locked section applies each result exactly where it used to compute it.
        $prelock = $current ? $this->prelock($result, (string) $claimedPath, $archived, $incoming, $size, $timing) : new PublicationPrelock;
        if ($timing !== null) {
            $segments = 0;
            foreach ((array) ($result->manifest['files'] ?? []) as $file) {
                $segments += is_array($file) ? count((array) ($file['segments'] ?? [])) : 0;
            }
            $timing->setManifestFacts($segments, $keys !== []);
        }
        try {
            $id = DB::transaction(function () use ($claim, $result, $timing, $preResolved, $keys, $incoming, $prelock, &$outcome): ?int {
                $timing?->beginAttempt();
                $returned = false;
                $outcome = 'threw';
                try {
                    // Duplicate detection must see each committed release sharing a search key;
                    // admissions, expiry and unrelated releases no longer wait behind this lock.
                    app(VerificationLedger::class)->enterPublication($keys, timing: $timing);
                    $timing?->nextPhase('candidate_policy');
                    $candidate = DB::table('release_verification_candidates')->where('id', $claim->id)->lockForUpdate()->first();
                    if ($candidate === null || $candidate->version !== $claim->version
                        || $candidate->manifest_hash !== $claim->manifest_hash || $candidate->lease_token !== $claim->lease_token
                        || $candidate->lease_until === null || $candidate->lease_until < now()->format('Y-m-d H:i:s')) {
                        $outcome = 'stale';

                        throw new RuntimeException('Stale verification result cannot publish.');
                    }
                    if ($candidate->release_id !== null) {
                        $returned = true;
                        $outcome = 'duplicate';

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
                    $filteredReason = $prelock->policyKnown ? $prelock->policyRejection
                        : app(CollectionReleasePolicy::class)->verifiedRejection($manifest);
                    if ($filteredReason !== null) {
                        app(CandidateRepository::class)->rejectByPolicy($claim, $filteredReason, $timing);

                        $returned = true;
                        $outcome = 'filtered';

                        return null;
                    }
                    $timing?->nextPhase('metadata_size');
                    $metadata = $preResolved ?? $this->metadata->resolve($manifest);
                    $size = $prelock->size ?? $this->publicationSize($manifest);
                    if ($size < 1 && ($manifest['verification_mode'] ?? null) === 'spot_check') {
                        throw new RuntimeException('Spot-checked release has no positive declared size.');
                    }
                    $timing?->nextPhase('archive_legacy');
                    $legacy = null;
                    // The archived manifest is content-addressed: the pre-lock copy is this row's only if the row still names its path.
                    $archived = $prelock->archived !== null && $prelock->archivedPath === $candidate->manifest_path
                        ? $prelock->archived : app(ManifestStore::class)->read($candidate->manifest_path);
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
                    $duplicate = $legacy === null ? $this->findCoveredDuplicate($claim, $manifest, $metadata, $size, $timing, $incoming, $prelock->coverageIds) : ['release_id' => null, 'metadata_conflict' => false];
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
                            $outcome = 'metadata_review';

                            return null;
                        }
                        $this->recordGroups($duplicateId, $manifest['groups']);
                        app(CandidateRepository::class)->heartbeat((int) $claim->id, $claim->lease_token);
                        $this->markPublished($candidate->id, $duplicateId, $result, $timing);

                        $returned = true;
                        $outcome = 'duplicate';

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
                        DB::table('releases')->where('id', $id)->update($parameters + ['iscategorized' => 1]);
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
                    $receipt = $this->writer->writeWithReceipt($manifest, $guid, $release, $timing, $prelock->body);
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
                    $this->markPublished($candidate->id, (int) $id, $result, $timing);

                    $returned = true;
                    $outcome = 'published';

                    return (int) $id;
                } finally {
                    $timing?->endAttempt($returned);
                }
            }, 5);
            $status = 'returned';

            return $id;
        } finally {
            // An exception after the callback (a failed commit or after-commit hook) is a throw, not a publication.
            $timing?->setOutcome($status === 'returned' || $outcome === 'stale' ? $outcome : 'threw');
            $timing?->report($status);
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $metadata
     * @param  array{document: string, xml: ?\SimpleXMLElement, fingerprint: ?string}|null  $incoming  The pre-lock parse, when it ran.
     * @param  array<string, true>|null  $coverageIds  coverageIds() of that parse, when computed before the lock.
     * @return array{release_id: ?int, metadata_conflict: bool}
     */
    private function findCoveredDuplicate(object $claim, array $manifest, array $metadata, int $size, ?PublicationTiming $timing,
        ?array $incoming = null, ?array $coverageIds = null): array
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
        ['xml' => $xml, 'fingerprint' => $fingerprint] = $incoming ?? $this->incomingDocument($manifest);
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
            onCandidate: $heartbeat, acceptCovered: $acceptCovered, requiredIds: $coverageIds);
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

    /** The manifest path of the candidate row this claim still owns, or null when the claim is stale. */
    private function currentManifestPath(object $claim): ?string
    {
        $row = DB::table('release_verification_candidates')->where('id', $claim->id)->where('version', $claim->version)
            ->where('manifest_hash', $claim->manifest_hash)->where('lease_token', $claim->lease_token)->whereNull('release_id')
            ->where('lease_until', '>=', now()->format('Y-m-d H:i:s'))->first(['manifest_path']);

        return $row === null ? null : (string) $row->manifest_path;
    }

    /**
     * Compute, before any lock, what the locked section would otherwise compute. Each step is
     * independent and best effort: one that throws stays unset and is recomputed (and throws
     * again, as it always did) in the locked section, in its original order.
     *
     * @param  array{path: string, manifest: array<string, mixed>}|null  $supplied
     * @param  array{document: string, xml: ?\SimpleXMLElement, fingerprint: ?string}|null  $incoming
     */
    private function prelock(VerificationResult $result, string $claimedPath, ?array $supplied, ?array $incoming, ?int $size, ?PublicationTiming $timing): PublicationPrelock
    {
        $manifest = $result->manifest;
        $archived = null;
        try {
            $archived = $supplied !== null && $supplied['path'] === $claimedPath ? $supplied['manifest']
                : app(ManifestStore::class)->read($claimedPath);
        } catch (Throwable) {
        }
        $timing?->preLockPhase('archive_read');
        $policyKnown = false;
        $rejection = null;
        try {
            // Reads only the group's and the settings' size and file-count limits, which no publication lock guards.
            $rejection = app(CollectionReleasePolicy::class)->verifiedRejection($manifest);
            $policyKnown = true;
        } catch (Throwable) {
        }
        $timing?->preLockPhase('policy_check');
        $body = null;
        $coverageIds = null;
        // A filtered candidate never writes or compares an NZB, and a legacy row is never searched for covering releases.
        if ($incoming !== null && $rejection === null) {
            try {
                $body = $this->writer->compress($incoming['document']);
            } catch (Throwable) {
            }
            $timing?->preLockPhase('document_gzip');
            try {
                if ($incoming['xml'] !== null && ($manifest['legacy_release_id'] ?? null) === null) {
                    $coverageIds = (new NzbArticleCoverage(app(NzbService::class), $this->duplicates))->coverageIds($incoming['xml']);
                }
            } catch (Throwable) {
            }
            $timing?->preLockPhase('coverage_ids');
        }

        return new PublicationPrelock($size, $claimedPath, $archived, $policyKnown, $rejection, $body, $coverageIds);
    }

    /**
     * The incoming NZB that duplicate detection compares, parsed once, and its article fingerprint.
     *
     * @param  array<string, mixed>  $manifest
     * @return array{document: string, xml: ?\SimpleXMLElement, fingerprint: ?string}
     */
    private function incomingDocument(array $manifest): array
    {
        $document = $this->writer->document($manifest);
        $xml = (new NzbArticleCoverage(app(NzbService::class), $this->duplicates))->parseXml($document);

        return ['document' => $document, 'xml' => $xml, 'fingerprint' => $xml === null ? null : NzbArticleFingerprint::fromXml($xml)];
    }

    /**
     * The release size duplicate detection matches exactly.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function publicationSize(array $manifest): int
    {
        if (($manifest['verification_mode'] ?? null) === 'spot_check') {
            return (new CandidateHeaderInventory)->bytes($manifest);
        }
        $size = 0;
        foreach ($manifest['files'] as $file) {
            $size += (int) ($file['decoded_size'] ?? array_sum(array_column($file['segments'], 'bytes')));
        }

        return (int) ($manifest['verified_bytes'] ?? $size);
    }

    /** @param list<string> $names */
    private function recordGroups(int $releaseId, array $names): void
    {
        foreach (array_unique(app(ReleaseGroupResolver::class)->idsByName($names)) as $groupId) {
            DB::table('releases_groups')->insertOrIgnore(['releases_id' => $releaseId, 'groups_id' => $groupId]);
        }
    }

    private function markPublished(int $candidateId, int $releaseId, VerificationResult $result, ?PublicationTiming $timing = null): void
    {
        $candidate = DB::table('release_verification_candidates')->where('id', $candidateId)->first();
        DB::table('release_verification_candidates')->where('id', $candidateId)->update([
            'release_id' => $releaseId, 'status' => $result->verdict, 'reason' => $result->reason,
            'verified_at' => now(), 'lease_token' => null, 'lease_until' => null, 'manifest_bytes' => 0, 'manifest_path' => '',
        ]);
        // Release capacity last so the shared ledger row is held only until commit.
        $previous = $timing?->enterNestedPhase('ledger_adjust');
        app(VerificationLedger::class)->adjust(-(int) $candidate->manifest_bytes);
        $timing?->nextPhase((string) $previous);
        DB::afterCommit(function () use ($candidate): void {
            app(ManifestStore::class)->deleteIfUnreferenced($candidate->manifest_path);
        });
    }
}
