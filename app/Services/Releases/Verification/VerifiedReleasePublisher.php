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
use App\Services\Nzb\ReleaseArticleFingerprintStore;
use App\Services\Releases\ReleaseDuplicateFinder;
use App\Services\Releases\ReleaseGroupResolver;
use App\Services\TvProcessing\TvProcessingCandidateQuery;
use App\Support\NzbPasswordMetadata;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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
        // Resolve the duplicate-search keys once, before taking any lock, so the publication locks
        // exactly the keys its search will use. Without them it falls back to the shared row. An
        // outer transaction would take its REPEATABLE READ view from these reads, before the locks,
        // so there metadata is resolved after the exclusive fallback, as before striping.
        $preResolved = null;
        $keys = [];
        // A plain read is harmless outside a transaction; it skips the work for a stale claim,
        // which the locked validation below rejects anyway.
        if (DB::transactionLevel() === 0 && $this->claimIsCurrent($claim)) {
            try {
                $preResolved = $this->metadata->resolve($result->manifest);
                $keys = VerificationLedger::publicationKeys($this->publicationSize($result->manifest), $this->incomingFingerprint($result->manifest),
                    (string) $preResolved['name'], (string) $preResolved['searchname'], (int) $preResolved['predb_id']);
            } catch (Throwable) {
                $preResolved = null;
                $keys = [];
            }
        }
        if ($keys !== []) {
            // Upgrading a partial in place must serialize with every publication or audit that can reach
            // it under its current keys, so hold those too; a key that changes before the lock forces a retry.
            $keys = array_values(array_unique([...$keys, ...$this->supersededPartialKeys($result->manifest)]));
        }
        try {
            $id = DB::transaction(function () use ($claim, $result, $timing, $preResolved, $keys): ?int {
                $timing?->beginAttempt();
                $returned = false;
                try {
                    // Duplicate detection must see each committed release sharing a search key;
                    // admissions, expiry and unrelated releases no longer wait behind this lock.
                    app(VerificationLedger::class)->enterPublication($keys);
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
                        $inventory = new CandidateHeaderInventory;
                        if (isset($manifest['partial'])) {
                            // Recompute rather than trust the counts, and require the locked candidate to still qualify.
                            if ($manifest['partial'] !== $inventory->completeness($manifest)
                                || ! app(CandidateRepository::class)->partialAllowed($candidate)) {
                                throw new RuntimeException('Partial publication is no longer eligible.');
                            }
                            $rejection = $inventory->partialRejection($manifest);
                        } else {
                            $rejection = $inventory->rejection($manifest);
                        }
                        if ($rejection !== null) {
                            throw new RuntimeException($rejection);
                        }
                    }
                    $filteredReason = app(CollectionReleasePolicy::class)->verifiedRejection($manifest);
                    if ($filteredReason !== null && isset($manifest['partial']) && ! CollectionReleasePolicy::irrecoverable($filteredReason)) {
                        // Missing files or articles may still satisfy the policy; never filter terminally.
                        throw new PartialPublicationDeferredException($filteredReason);
                    }
                    if ($filteredReason !== null) {
                        app(CandidateRepository::class)->rejectByPolicy($claim, $filteredReason);

                        $returned = true;

                        return null;
                    }
                    $timing?->nextPhase('metadata_size');
                    $metadata = $preResolved ?? $this->metadata->resolve($manifest);
                    $size = $this->publicationSize($manifest);
                    if ($size < 1 && ($manifest['verification_mode'] ?? null) === 'spot_check') {
                        throw new RuntimeException('Spot-checked release has no positive declared size.');
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
                    $totalParts = ($manifest['verification_mode'] ?? null) === 'spot_check'
                        ? max(0, (int) ($manifest['publication_file_count'] ?? $manifest['expected_files'] ?? count($manifest['files'])))
                        : (($manifest['metadata_source'] ?? null) !== 'import'
                            ? count(array_unique($manifest['verified_file_names'])) : max((int) $manifest['expected_files'], count($manifest['files'])));
                    $upgrade = $legacy === null ? $this->lockUpgradablePartial($manifest, $keys, (int) $candidate->id) : null;
                    if ($upgrade !== null) {
                        $retain = isset($manifest['partial']) && $this->awaitsLateArticles($manifest);
                        $id = $this->upgradePartial($upgrade, $manifest, $metadata, $size, $totalParts, $retain ? (int) $candidate->id : null, $timing);
                        $this->recordGroups($id, $manifest['groups']);
                        app(CandidateRepository::class)->heartbeat((int) $claim->id, $claim->lease_token);
                        $this->markPublished($candidate->id, $id, $result, retainManifest: $retain);

                        $returned = true;

                        return $id;
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
                        // A covered candidate never keeps inventory: its articles are a subset of the reused release,
                        // and merging late articles into that subset could replace a more complete partial.
                        $this->markPublished($candidate->id, $duplicateId, $result);

                        $returned = true;

                        return $duplicateId;
                    }
                    $guid = $legacy === null ? (string) Str::uuid() : (string) $legacy->guid;
                    $parameters = $metadata + [
                        'totalpart' => $totalParts,
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
                    if (isset($manifest['partial'])) {
                        DB::table('releases')->where('id', $id)->update(['articles_found' => $manifest['partial']['articles_found'],
                            'articles_expected' => $manifest['partial']['articles_expected'],
                            'files_found' => $manifest['partial']['files_found'], 'files_expected' => $manifest['partial']['files_expected']]
                            // Until its inventory is released, workers that read NZBs leave it alone.
                            + (Release::hasLateArticleFlag() && $this->awaitsLateArticles($manifest) ? ['late_article_candidate_id' => (int) $candidate->id] : []));
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
                    // A partial release keeps its inventory so late articles can complete it.
                    $this->markPublished($candidate->id, (int) $id, $result, retainManifest: isset($manifest['partial']) && $this->awaitsLateArticles($manifest));

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

    private function claimIsCurrent(object $claim): bool
    {
        return DB::table('release_verification_candidates')->where('id', $claim->id)->where('version', $claim->version)
            ->where('manifest_hash', $claim->manifest_hash)->where('lease_token', $claim->lease_token)->whereNull('release_id')
            ->where('lease_until', '>=', now()->format('Y-m-d H:i:s'))->exists();
    }

    /**
     * The article fingerprint duplicate detection looks up, computed as {@see findCoveredDuplicate()} does.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function incomingFingerprint(array $manifest): ?string
    {
        $xml = (new NzbArticleCoverage(app(NzbService::class), $this->duplicates))->parseXml($this->writer->document($manifest));

        return $xml === null ? null : NzbArticleFingerprint::fromXml($xml);
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

    /**
     * Publication keys of the partial release this manifest upgrades; empty when it upgrades none.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    private function supersededPartialKeys(array $manifest): array
    {
        $previous = $manifest['upgrades_release_id'] ?? null;

        return is_int($previous) ? ($this->releaseKeys($previous) ?? []) : [];
    }

    /**
     * A release's publication keys. Inside the publication transaction pass $current: a locking read sees a
     * rename committed after this transaction's REPEATABLE READ snapshot.
     *
     * @return list<string>|null
     */
    private function releaseKeys(int $releaseId, bool $current = false): ?array
    {
        $release = DB::table('releases')->where('id', $releaseId)->when($current, fn ($query) => $query->lockForUpdate())->first();

        return $release === null ? null : VerificationLedger::publicationKeys((int) $release->size,
            $release->article_fingerprint ?? null, (string) $release->name, (string) $release->searchname, (int) $release->predb_id);
    }

    /**
     * Only Usenet-sourced inventories can grow: archive() merges late headers by collection hash, while an
     * imported NZB's candidate is keyed by its full article fingerprint and never receives more articles.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function awaitsLateArticles(array $manifest): bool
    {
        return ($manifest['metadata_source'] ?? null) !== 'import';
    }

    /**
     * Lock the published partial this manifest's generation upgrades, or return null to publish it as a new
     * release (the partial was deleted or completed elsewhere).
     *
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $keys  the publication keys this transaction holds; empty under the keyless lock
     */
    private function lockUpgradablePartial(array $manifest, array $keys, int $candidateId): ?object
    {
        $releaseId = $manifest['upgrades_release_id'] ?? null;
        if (! is_int($releaseId) || ! Release::hasPartialArticleCounts() || ! Release::hasLateArticleFlag()) {
            return null;
        }
        // Only while this generation holds the inventory: once the pointer clears, a worker may be reading its NZB.
        $release = DB::table('releases')->where('id', $releaseId)->whereNotNull('articles_expected')
            ->where('nzbstatus', NzbService::NZB_ADDED)->where('late_article_candidate_id', $candidateId)
            ->lockForUpdate()->first(['id', 'guid', 'predb_id', 'searchname']);
        if ($release === null) {
            return null;
        }
        $current = $this->releaseKeys($releaseId, current: true);
        if ($keys !== [] && ($current === null || array_diff($current, $keys) !== [])) {
            throw new RuntimeException('Partial release changed before its upgrade; retry.');
        }

        return $release;
    }

    /**
     * Rewrite a published partial in place with the merged inventory: the same row and GUID, a replaced NZB
     * (restored if this transaction rolls back), and counts cleared once nothing is missing.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $metadata
     */
    private function upgradePartial(object $release, array $manifest, array $metadata, int $size, int $totalParts, ?int $holder, ?PublicationTiming $timing): int
    {
        $id = (int) $release->id;
        $timing?->nextPhase('release_identity_sql');
        $partial = $manifest['partial'] ?? null;
        // No NZB-reading worker has touched a flagged partial, so its name came from publication; take the
        // fuller inventory's, unless PreDB has since confirmed one.
        $naming = (int) $release->predb_id > 0 || ($metadata['searchname'] ?? null) === $release->searchname ? [] : array_intersect_key($metadata,
            array_flip(['name', 'searchname', 'fromname', 'categories_id', 'isrenamed', 'predb_id']));
        if ($naming !== []) {
            // Title-derived lookups (TV, movie, book, music, console, anime) ran on the old name; redo them,
            // as any rename does.
            $naming += array_intersect_key([...TvProcessingCandidateQuery::resetColumns(), 'imdbid' => null, 'musicinfo_id' => null,
                'consoleinfo_id' => null, 'bookinfo_id' => null, 'book_lookup_attempts' => 0, 'book_lookup_retry_at' => null,
                'book_name_normalized_at' => null, 'anidbid' => null, 'iscategorized' => 1], array_flip(Schema::getColumnListing('releases')));
        }
        DB::table('releases')->where('id', $id)->update(['size' => $size, 'totalpart' => $totalParts,
            'articles_found' => $partial['articles_found'] ?? null, 'articles_expected' => $partial['articles_expected'] ?? null,
            'files_found' => $partial['files_found'] ?? null, 'files_expected' => $partial['files_expected'] ?? null,
            'late_article_candidate_id' => $holder] + $naming + $this->freshPostProcessingState());
        ReleaseArticleFingerprintStore::store($id, $this->incomingFingerprint($manifest));
        $timing?->nextPhase('final_xml_gzip');
        $receipt = $this->writer->writeWithReceipt($manifest, (string) $release->guid, $release, $timing, replace: true);
        DB::afterRollBack(static fn () => $receipt->rollback());
        DB::afterCommit(static fn () => $receipt->close());
        // After the replacement, and even if no collection has started yet: a run that read the smaller NZB
        // can then only save while its record still says processing, which this (row-locked until commit) ends.
        if (Schema::hasTable('release_fingerprint_metadata')) {
            DB::table('release_fingerprint_metadata')->upsert([['releases_id' => $id, 'status' => 'stale', 'created_at' => now(), 'updated_at' => now()]],
                ['releases_id'], ['status', 'updated_at']);
        }
        DB::afterCommit(static function () use ($id): void {
            Search::updateRelease($id);
            try {
                CollectReleaseFingerprintMetadata::dispatch($id);
            } catch (Throwable $dispatchException) {
                Log::warning('Could not queue upgraded release fingerprint metadata collection.', [
                    'release_id' => $id,
                    'exception' => $dispatchException::class,
                ]);
            }
        });

        return $id;
    }

    /**
     * Post-processing state as a new release starts with: everything derived from the smaller NZB
     * (completion, NFO, previews, archives, PAR2 renaming) must be redone on the upgraded one.
     *
     * @return array<string, int|null>
     */
    private function freshPostProcessingState(): array
    {
        $state = ['completion' => 0, 'passwordstatus' => config('nntmux_settings.check_passworded_rars') === true ? -1 : 0,
            'haspreview' => -1, 'nfostatus' => -1, 'jpgstatus' => 0, 'videostatus' => 0, 'proc_pp' => 0, 'proc_par2' => 0,
            'proc_nfo' => 0, 'proc_files' => 0, 'proc_uid' => 0, 'proc_srr' => 0, 'proc_hash16k' => 0, 'proc_crc32' => 0,
            'pp_timeout_count' => 0, 'archive_retry_at' => null];

        return array_intersect_key($state, array_flip(Schema::getColumnListing('releases')));
    }

    private function markPublished(int $candidateId, int $releaseId, VerificationResult $result, bool $retainManifest = false): void
    {
        $candidate = DB::table('release_verification_candidates')->where('id', $candidateId)->first();
        $values = ['release_id' => $releaseId, 'status' => $result->verdict, 'reason' => $result->reason,
            'verified_at' => now(), 'lease_token' => null, 'lease_until' => null];
        if ($retainManifest) {
            DB::table('release_verification_candidates')->where('id', $candidateId)->update($values);

            return;
        }
        DB::table('release_verification_candidates')->where('id', $candidateId)->update($values + ['manifest_bytes' => 0, 'manifest_path' => '']);
        // Release capacity last so the shared ledger row is held only until commit.
        app(VerificationLedger::class)->adjust(-(int) $candidate->manifest_bytes);
        DB::afterCommit(function () use ($candidate): void {
            app(ManifestStore::class)->deleteIfUnreferenced($candidate->manifest_path);
        });
    }
}
