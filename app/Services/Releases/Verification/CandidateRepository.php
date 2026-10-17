<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Exceptions\CandidateMetadataBudgetExceeded;
use App\Models\Release;
use App\Services\CollectionCleanupService;
use App\Services\Nzb\NzbArticleCoverage;
use App\Services\Nzb\NzbService;
use App\Support\NzbPasswordMetadata;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

final class CandidateRepository
{
    private const array CLAIMABLE_STATUSES = ['ready', 'pending', 'unknown', 'verifying'];

    /** Window reads per claim while concurrent claimers keep winning the rows this worker tried. */
    private const int CLAIM_WINDOW_PASSES = 10;

    private int $claimSelections = 0;

    public function __construct(private readonly ManifestStore $store, private readonly CollectionCleanupService $cleanup,
        private readonly VerificationLedger $ledger = new VerificationLedger) {}

    public function archive(int $collectionId): void
    {
        app(VerificationResetService::class)->withAdmission(function () use ($collectionId): void {
            $currentReads = DB::transactionLevel() > 0;
            $hash = DB::table('collections')->where('id', $collectionId)->value('collectionhash');
            if (! is_string($hash)) {
                return;
            }
            DB::transaction(function () use ($collectionId, $hash, $currentReads): void {
                $collection = $this->lockArchiveCollection($collectionId, $hash);
                if ($collection === null) {
                    return;
                }
                if ((int) $collection->filecheck === 4
                    && ! $this->cleanup->verificationEligibleCollections()->where('collections.id', $collectionId)->exists()) {
                    return;
                }
                $sourceIdentity = bin2hex($collection->collectionhash);
                // The parent lock protects the header snapshot; unrelated admissions need no lock yet.
                // Keep terminal replays on their existing cheap, locked validation path.
                $observed = DB::table('release_verification_candidates')->where('source_identity', $sourceIdentity)
                    ->orderByDesc('id')->first(['status', 'manifest_path', 'release_id']);
                $filtered = $observed?->status === 'filtered';
                $manifest = null;
                $prepared = null;
                $headerAssessment = null;
                $snapshotFailure = null;
                if (! $filtered) {
                    try {
                        $manifest = $this->snapshotHeaders($collection, $currentReads);
                        // Prepare new, non-legacy admissions while only the parent is held.
                        // Existing manifests and legacy rows still require locked reconciliation.
                        if ((int) $collection->filecheck !== 4
                            && ($observed === null || $observed->manifest_path === '' || $observed->release_id !== null)) {
                            $prepared = $this->store->prepare($manifest);
                            $headerAssessment = (new CandidateHeaderInventory)->assessment($manifest);
                        }
                    } catch (CandidateMetadataBudgetExceeded $error) {
                        // A stale read view can hide a committed filtered replay; validate it first.
                        $snapshotFailure = $error;
                    }
                }
                $this->ledger->enter();
                $existing = $this->lockLatestCandidate($sourceIdentity, $currentReads);
                if ($existing !== null && $existing->status === 'filtered') {
                    if ($existing->release_id !== null || (int) $existing->groups_id !== (int) $collection->groups_id
                        || $existing->manifest_path !== '' || (int) $existing->manifest_bytes !== 0) {
                        return;
                    }
                    $usablePart = DB::table('binaries as b')->join('parts as p', 'p.binaries_id', '=', 'b.id')
                        ->where('b.collections_id', $collectionId)->where('p.size', '>', 0)->where('p.messageid', '<>', '')
                        ->lockForUpdate()->first(['p.binaries_id']);
                    if ($usablePart !== null) {
                        $this->cleanup->deleteCollectionsAndDescendants([$collectionId], 'Policy-filtered replay', false, manifestArchived: true);
                    }

                    return;
                }
                if ($snapshotFailure !== null) {
                    throw $snapshotFailure;
                }
                $identity = $existing->identity ?? $sourceIdentity;
                // A partial publication retains its inventory: late articles start a generation that
                // merges it and replaces that release, instead of publishing only the late fragment.
                $previous = null;
                if ($existing !== null && $existing->release_id !== null) {
                    $identity = sha1($sourceIdentity.':generation:'.$existing->id);
                    $previous = $existing->manifest_path !== '' && $existing->status === 'sampled'
                        && Release::hasPartialArticleCounts()
                        && DB::table('releases')->where('id', $existing->release_id)->whereNotNull('articles_expected')->exists() ? $existing : null;
                    $existing = null;
                }
                $manifest ??= $this->snapshotHeaders($collection, $currentReads);
                $manifest['legacy_release_id'] = $this->legacyReleaseId($collection);
                if ($previous !== null) {
                    $retained = $this->store->read($previous->manifest_path);
                    $manifest = $this->merge($retained, $manifest);
                    $manifest['replaces_release_id'] = (int) $previous->release_id;
                    $prepared = null;
                    $headerAssessment = null;
                }
                $newArticles = true;
                if ($existing !== null && $existing->manifest_path !== '') {
                    $oldManifest = $this->store->read($existing->manifest_path);
                    $manifest = $this->merge($oldManifest, $manifest);
                    $newArticles = $this->articleIdentity($oldManifest) !== $this->articleIdentity($manifest);
                    // A candidate may have appeared since the advisory read; persist its merge.
                    $prepared = null;
                    $headerAssessment = null;
                }
                $path = $prepared === null ? $this->store->write($manifest) : $this->store->writePrepared($prepared);
                $size = $this->store->size($path);
                $arrivalAt = Carbon::parse($collection->last_seen_at ?? $collection->dateadded ?? $collection->added ?? now())->format('Y-m-d H:i:s');
                $lastUniqueAt = $existing === null ? $arrivalAt : ($newArticles ? max($arrivalAt, $existing->last_unique_at) : $existing->last_unique_at);
                $expired = $lastUniqueAt < now()->subHours((int) config('release_verification.expiry_hours'))->format('Y-m-d H:i:s');
                $delta = ($expired ? 0 : $size) - (int) ($existing->manifest_bytes ?? 0) - (int) ($previous->manifest_bytes ?? 0);
                $full = function () use ($existing, $previous, $path): never {
                    if (($existing === null || $existing->manifest_path !== $path) && $previous?->manifest_path !== $path) {
                        $this->store->deleteIfUnreferenced($path);
                    }
                    throw new RuntimeException('Pending verification capacity is full; original headers retained.');
                };
                if (! $this->ledger->fits($delta)) {
                    $full();
                }
                $changed = $existing === null || $existing->manifest_path !== $path;
                $dirty = $existing !== null && $existing->status === 'dirty';
                $values = ['identity' => $identity, 'source_identity' => $sourceIdentity, 'groups_id' => (int) $collection->groups_id, 'manifest_path' => $path,
                    'manifest_bytes' => $size, 'manifest_hash' => substr($path, 0, 64)];
                if ($changed || $dirty) {
                    $assessment = $headerAssessment ?? (new CandidateHeaderInventory)->assessment($manifest);
                    $values += ['version' => (int) ($existing->version ?? 0) + 1, 'last_unique_at' => $lastUniqueAt,
                        'next_attempt_at' => now(), 'status' => $assessment['status'], 'lease_token' => null, 'lease_until' => null, 'reason' => $assessment['reason']];
                }
                if ($expired) {
                    $values = array_replace($values, ['status' => 'expired', 'last_unique_at' => $lastUniqueAt,
                        'manifest_path' => '', 'manifest_bytes' => 0, 'lease_token' => null, 'lease_until' => null]);
                }
                if ($existing === null) {
                    DB::table('release_verification_candidates')->insert($values);
                } else {
                    DB::table('release_verification_candidates')->where('id', $existing->id)->update($values);
                }
                if ($previous !== null) {
                    // The new generation now holds the retained inventory and its capacity.
                    DB::table('release_verification_candidates')->where('id', $previous->id)->update(['manifest_path' => '', 'manifest_bytes' => 0]);
                    if ($previous->manifest_path !== $path) {
                        DB::afterCommit(fn () => $this->store->deleteIfUnreferenced($previous->manifest_path));
                    }
                }
                $this->cleanup->deleteCollectionsAndDescendants([$collectionId], 'Archived for verification', false, manifestArchived: true);
                // Last lock request: a full ledger rolls back the deletion, retaining the original headers.
                if (! $this->ledger->adjust($delta)) {
                    $full();
                }
                if ($expired) {
                    DB::afterCommit(function () use ($path): void {
                        $this->store->deleteIfUnreferenced($path);
                    });
                }
                if ($existing !== null && $existing->manifest_path !== $path) {
                    DB::afterCommit(function () use ($existing): void {
                        $this->store->deleteIfUnreferenced($existing->manifest_path);
                    });
                }
            }, 5);
        });
    }

    /**
     * Lock the newest candidate for an archived collection's source.
     *
     * A locking read of the source index also locks its gaps, and concurrent archives inserting new
     * candidates into one gap then deadlock. The collection lock already serializes admissions of
     * this source, and this transaction's read view was taken after acquiring it, so a plain read
     * finds the newest committed candidate and the primary key locks only that row. Outer caller
     * transactions and legacy file manifests keep the locking read.
     */
    private function lockLatestCandidate(string $sourceIdentity, bool $currentReads): ?object
    {
        $latest = static fn () => DB::table('release_verification_candidates')->where('source_identity', $sourceIdentity)->orderByDesc('id');
        if ($currentReads || ! $this->ledger->concurrent()) {
            return $latest()->lockForUpdate()->first();
        }
        $id = $latest()->value('id');
        if ($id === null) {
            return null;
        }
        $row = DB::table('release_verification_candidates')->where('id', $id)->lockForUpdate()->first();

        return $row !== null && $row->source_identity === $sourceIdentity ? $row : $latest()->lockForUpdate()->first();
    }

    /**
     * Lock an import admission's candidate by identity.
     *
     * Locking an absent identity gap-locks the unique index, and concurrent first-time imports in one
     * gap then deadlock on each other's inserts. With database manifests and no outer transaction, a
     * plain read (this transaction's first, so its view is current) finds a committed candidate to lock
     * by primary key; an identity committed after that view surfaces as a duplicate key on insert, and
     * the admission then reruns through this locking read.
     */
    private function lockIdentityCandidate(string $identity, bool $lockedLookup): ?object
    {
        $query = static fn () => DB::table('release_verification_candidates')->where('identity', $identity);
        if ($lockedLookup || DB::transactionLevel() > 1 || ! $this->ledger->concurrent()) {
            return $query()->lockForUpdate()->first();
        }
        $id = $query()->value('id');

        return $id === null ? null : DB::table('release_verification_candidates')->where('id', $id)->lockForUpdate()->first();
    }

    /** Lock through ingestion's unique hash index before taking the shared capacity row. */
    private function lockArchiveCollection(int $collectionId, string $hash): ?object
    {
        $query = DB::table('collections')->where('collectionhash', $hash)->where('id', $collectionId);
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $query->forceIndex('ix_collection_collectionhash')->lock('for update skip locked');
        } else {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /** @param array<string, mixed> $manifest */
    public function enqueue(array $manifest, ?\Closure $onAdmitted = null): bool
    {
        if (array_key_exists('passwords', $manifest) && ! NzbPasswordMetadata::isValid($manifest['passwords'])) {
            return false;
        }
        $accepted = false;
        $capacityDeferred = false;
        $publicationDeferred = false;
        app(VerificationResetService::class)->withAdmission(function () use ($manifest, &$accepted, &$capacityDeferred, &$publicationDeferred, $onAdmitted): void {
            // Prepare incoming assertions before capacity ownership. The authoritative
            // locked comparison below admits only this exact manifest/prepared pair.
            // Passwordless known replays retain their existing cheap path.
            $observed = DB::table('release_verification_candidates')->where('identity', (string) $manifest['identity'])->first(['release_id']);
            $prepared = ($manifest['passwords'] ?? []) !== [] || $observed === null
                ? $this->store->prepare($manifest) : null;
            $replayXml = $observed?->release_id !== null && ($manifest['passwords'] ?? []) !== []
                ? app(VerifiedNzbWriter::class)->document($manifest) : null;
            $headerAssessment = $prepared === null ? null : (new CandidateHeaderInventory)->assessment($manifest);
            $accepted = DB::transaction(function () use ($manifest, $prepared, $headerAssessment, $replayXml, &$capacityDeferred, &$publicationDeferred, $onAdmitted): bool {
                $admit = function (bool $lockedLookup = false) use ($manifest, $prepared, $headerAssessment, $replayXml, &$capacityDeferred, &$publicationDeferred, &$admit): bool {
                    $capacityDeferred = false;
                    $publicationDeferred = false;
                    $this->ledger->enter();
                    $identity = (string) $manifest['identity'];
                    $existing = $this->lockIdentityCandidate($identity, $lockedLookup);
                    $release = $existing?->release_id === null ? null
                        : DB::table('releases')->where('id', $existing->release_id)->lockForUpdate()->first(['id', 'guid', 'nzbstatus']);
                    $metadataReadmission = $existing !== null && $existing->status === 'metadata_expired'
                        && $existing->release_id === null && $existing->manifest_path === '' && (int) $existing->manifest_bytes === 0;
                    if ($metadataReadmission && (($manifest['passwords'] ?? []) === []
                        || ($manifest['metadata_source'] ?? null) !== 'import' || ($manifest['conflict'] ?? false))) {
                        return false;
                    }
                    if ($existing !== null && ! $metadataReadmission && ($existing->release_id === null || $release !== null)) {
                        if ($release !== null && ($manifest['passwords'] ?? []) !== []) {
                            if ($replayXml === null) {
                                $publicationDeferred = true;

                                return false;
                            }

                            return $this->identicalPublishedReplay($existing, $release, $manifest, $prepared, $replayXml);
                        }

                        return $this->enrichImportedPasswordMetadata($existing, $manifest, $capacityDeferred, $prepared);
                    }
                    $path = $prepared === null ? $this->store->write($manifest) : $this->store->writePrepared($prepared);
                    $bytes = $this->store->size($path);
                    $delta = $bytes - (int) ($existing->manifest_bytes ?? 0);
                    $full = function () use ($existing, $path): never {
                        if ($existing === null || $existing->manifest_path !== $path) {
                            $this->store->deleteIfUnreferenced($path);
                        }
                        throw new CandidateCapacityException('Candidate capacity is full; import retained for retry.');
                    };
                    if (! $this->ledger->fits($delta)) {
                        $full();
                    }
                    $assessment = $headerAssessment ?? (new CandidateHeaderInventory)->assessment($manifest);
                    $values = ['identity' => $identity, 'source_identity' => $identity,
                        'groups_id' => (int) $manifest['groups_id'], 'manifest_path' => $path, 'manifest_bytes' => $bytes,
                        'manifest_hash' => substr($path, 0, 64), 'last_unique_at' => now(), 'next_attempt_at' => now(),
                        'version' => (int) ($existing->version ?? 0) + 1, 'status' => $assessment['status'], 'reason' => $assessment['reason'],
                        'release_id' => null, 'verified_at' => null, 'lease_token' => null, 'lease_until' => null];
                    if ($existing === null) {
                        try {
                            DB::table('release_verification_candidates')->insert($values);
                        } catch (UniqueConstraintViolationException $race) {
                            if ($lockedLookup) {
                                throw $race;
                            }

                            // A concurrent first admission committed this identity after the plain
                            // lookup; admit again against its row through the locking lookup, and
                            // drop this pass's manifest after commit unless the rerun references it.
                            $this->store->deleteIfUnreferenced($path);

                            return $admit(true);
                        }
                    } else {
                        DB::table('release_verification_candidates')->where('id', $existing->id)->update($values);
                        if ($existing->manifest_path !== '' && $existing->manifest_path !== $path) {
                            DB::afterCommit(function () use ($existing): void {
                                $this->store->deleteIfUnreferenced($existing->manifest_path);
                            });
                        }
                    }
                    if (! $this->ledger->adjust($delta)) {
                        $full();
                    }

                    return true;
                };
                $ok = $admit();
                if ($ok && $onAdmitted !== null) {
                    $row = DB::table('release_verification_candidates')->where('identity', $manifest['identity'])->lockForUpdate()->first();
                    if ($row === null) {
                        throw new RuntimeException('Accepted candidate disappeared under admission lock');
                    }
                    $onAdmitted(new CandidateAdmission((int) $row->id, (string) $row->identity, (int) $row->version, (string) $row->manifest_hash));
                }

                return $ok;
            }, 5);
        });

        if ($publicationDeferred) {
            throw new CandidateAdmissionDeferredException('Publication changed during admission; import retained for retry.');
        }

        if ($capacityDeferred) {
            // Signal after the admission transaction commits its lease fence.
            throw new CandidateCapacityException('Metadata capacity is full; import retained for retry.');
        }

        return $accepted;
    }

    /**
     * Return the exact accepted revision while its admission transaction owns the locks.
     *
     * @param  array<string, mixed>  $manifest
     */
    public function enqueueWithAdmission(array $manifest): ?CandidateAdmission
    {
        $receipt = null;
        $accepted = $this->enqueue($manifest, static function (CandidateAdmission $value) use (&$receipt): void {
            $receipt = $value;
        });

        return $accepted ? $receipt : null;
    }

    /** @param array<string, mixed> $manifest */
    private function identicalPublishedReplay(object $existing, object $release, array $manifest, ?PreparedManifest $prepared, ?string $replayXml): bool
    {
        // The caller owns the admission and candidate locks in that order.
        if ((string) $release->guid === '' || (int) $release->nzbstatus !== NzbService::NZB_ADDED
            || ! in_array($existing->status, ['complete', 'repaired', 'sampled'], true) || $existing->verified_at === null
            || $existing->manifest_path !== '' || (int) $existing->manifest_bytes !== 0
            || $prepared === null || $replayXml === null || ! hash_equals((string) $existing->manifest_hash, substr($prepared->name, 0, 64))
            || ($manifest['metadata_source'] ?? null) !== 'import' || ($manifest['conflict'] ?? false)) {
            return false;
        }
        $coverage = app(NzbArticleCoverage::class);
        $incoming = $coverage->parseXml($replayXml);
        if ($incoming === null || ($snapshot = $coverage->coveredSnapshot($incoming, (string) $release->guid)) === null) {
            throw new CandidateAdmissionDeferredException('Publication evidence is unavailable for exact metadata replay.');
        }

        try {
            $retained = (new NzbManifestReader)->read((string) $snapshot->asXML(), (object) [
                'searchname' => $manifest['subject'], 'fromname' => $manifest['fromname'],
                'postdate' => $manifest['date'], 'groups_id' => $manifest['groups_id'],
            ]);
        } catch (RuntimeException) {
            throw new CandidateAdmissionDeferredException('Retained publication metadata cannot be read.');
        }

        return ($retained['passwords'] ?? []) === ($manifest['passwords'] ?? []);
    }

    /**
     * Fill explicit uploader metadata only for an otherwise identical unpublished import.
     * Caller holds the admission and candidate locks in admission order.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function enrichImportedPasswordMetadata(object $existing, array $manifest, bool &$capacityDeferred, ?PreparedManifest $prepared): bool
    {
        if (! array_key_exists('passwords', $manifest) || $manifest['passwords'] === []) {
            $capacityDeferred = $existing->status === 'metadata_deferred';

            return ! in_array($existing->status, ['metadata_deferred', 'metadata_review', 'metadata_expired'], true);
        }
        if ($existing->release_id !== null || $existing->manifest_path === ''
            || ! in_array($existing->status, ['ready', 'waiting_upload', 'pending', 'unknown', 'verifying', 'metadata_deferred'], true)
            || ($manifest['metadata_source'] ?? null) !== 'import' || ($manifest['conflict'] ?? false)) {
            return false;
        }
        $passwords = $manifest['passwords'] ?? [];
        $original = $this->store->read($existing->manifest_path);
        if (($original['metadata_source'] ?? null) !== 'import' || ($original['conflict'] ?? false)) {
            return false;
        }
        $incoming = $manifest;
        $comparison = $original;
        unset($incoming['passwords'], $comparison['passwords']);
        // Compare exactly the bytes that preparation hashes, without compressing either
        // throwaway comparison. Preserve key order, types, and UTF-8 substitution policy.
        if (json_encode($incoming, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)
            !== json_encode($comparison, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)) {
            return false;
        }
        if (($original['passwords'] ?? []) !== []) {
            return $original['passwords'] === $passwords;
        }
        if ($prepared === null) {
            throw new RuntimeException('Explicit metadata must be prepared before admission.');
        }
        // Current locked metadata matches the complete incoming manifest. Install
        // its exact precomputed pair without compression, then reserve its capacity.
        $original = $manifest;
        $path = $this->store->writePrepared($prepared);
        $bytes = $this->store->size($path);
        $delta = $bytes - (int) $existing->manifest_bytes;
        $assessment = (new CandidateHeaderInventory)->assessment($original);
        // The reservation is this transaction's last lock request; only the held candidate row follows.
        if (! $this->ledger->fits($delta) || ! $this->ledger->adjust($delta)) {
            $this->store->deleteIfUnreferenced($path);
            // Keep the original bounded manifest/capacity, but prevent its passwordless publication.
            // Expiry releases this reservation while retaining a metadata fence; the
            // uploader source owns the additional metadata outside this reservation.
            DB::table('release_verification_candidates')->where('id', $existing->id)->update([
                'status' => 'metadata_deferred', 'reason' => 'Explicit metadata awaits capacity.',
                'version' => (int) $existing->version + 1, 'lease_token' => null, 'lease_until' => null,
            ]);
            $capacityDeferred = true;

            return false;
        }
        DB::table('release_verification_candidates')->where('id', $existing->id)->update([
            'manifest_path' => $path, 'manifest_hash' => substr($path, 0, 64), 'manifest_bytes' => $bytes,
            'version' => (int) $existing->version + 1, 'status' => $assessment['status'], 'reason' => $assessment['reason'],
            'next_attempt_at' => now(), 'lease_token' => null, 'lease_until' => null,
        ]);
        DB::afterCommit(function () use ($existing): void {
            $this->store->deleteIfUnreferenced($existing->manifest_path);
        });

        return true;
    }

    /** @param list<int> $ids */
    public function invalidateHotCollections(array $ids): void
    {
        foreach (DB::table('collections')->whereIn('id', $ids)->orderBy('id')->get() as $collection) {
            $identity = bin2hex($collection->collectionhash);
            $candidates = DB::table('release_verification_candidates')->where('source_identity', $identity)
                ->whereNull('release_id')->whereNotIn('status', ['expired'])->lockForUpdate()->get();
            foreach ($candidates as $candidate) {
                if ($candidate->manifest_path === '') {
                    continue;
                }
                $old = $this->store->read($candidate->manifest_path);
                $merged = $this->merge($old, $this->snapshot($collection));
                if ($this->articleIdentity($old) === $this->articleIdentity($merged) && ! ($merged['conflict'] ?? false)) {
                    continue;
                }
                DB::table('release_verification_candidates')->where('id', $candidate->id)->update([
                    'status' => 'dirty', 'lease_token' => null, 'lease_until' => null, 'version' => $candidate->version + 1,
                    'last_unique_at' => $this->articleIdentity($old) !== $this->articleIdentity($merged) ? now() : $candidate->last_unique_at]);
                DB::table('collections')->where('id', $collection->id)->where('filecheck', '!=', 4)->update(['filecheck' => 3]);
            }
        }
    }

    /** @param array<string, mixed> $manifest */
    private function articleIdentity(array $manifest): string
    {
        $ids = [];
        foreach ($manifest['files'] as $file) {
            foreach ($file['segments'] as $part) {
                $ids[] = $file['key'].':'.$part['number'].':'.$part['messageid'].':'.$part['bytes'];
            }
        }
        sort($ids);

        return hash('sha256', implode("\n", $ids));
    }

    /** An upload whose missing files or articles have stopped arriving may publish as partial. */
    public function partialAllowed(object $candidate): bool
    {
        $hours = (int) config('release_verification.partial_after_hours', 4);

        return $hours > 0 && ($candidate->release_id ?? null) === null && is_string($candidate->last_unique_at ?? null)
            && $candidate->last_unique_at < now()->subHours($hours)->format('Y-m-d H:i:s');
    }

    /**
     * Queue uploads whose missing articles stopped arriving for partial publication. Uploads still
     * inside the hold window are deferred to its end, so each is read about once per window.
     */
    public function promotePartial(int $limit): int
    {
        $hours = (int) config('release_verification.partial_after_hours', 4);
        if ($hours < 1) {
            return 0;
        }
        $now = now();
        $cutoff = $now->copy()->subHours($hours)->format('Y-m-d H:i:s');
        // The verification_due index serves the status / next_attempt_at order.
        $rows = DB::table('release_verification_candidates')->where('status', 'waiting_upload')
            ->where('next_attempt_at', '<=', $now)->whereNull('release_id')->whereNull('lease_token')
            ->orderBy('next_attempt_at')->orderBy('id')->limit(max(1, $limit))
            ->get(['id', 'last_unique_at', 'next_attempt_at']);
        $eligible = [];
        foreach ($rows as $row) {
            if ($row->last_unique_at < $cutoff) {
                $eligible[] = (int) $row->id;

                continue;
            }
            DB::table('release_verification_candidates')->where('id', $row->id)->where('status', 'waiting_upload')
                ->where('next_attempt_at', $row->next_attempt_at)->whereNull('release_id')->whereNull('lease_token')
                ->update(['next_attempt_at' => Carbon::parse($row->last_unique_at)->addHours($hours)->format('Y-m-d H:i:s')]);
        }
        if ($eligible === []) {
            return 0;
        }

        return DB::transaction(function () use ($eligible, $cutoff, $now): int {
            // Skip rows ingestion holds rather than wait on them in an order it does not share. The
            // predicates are rechecked under the lock: a new article since the read keeps it waiting.
            $query = DB::table('release_verification_candidates')->whereIn('id', $eligible)->where('status', 'waiting_upload')
                ->whereNull('release_id')->whereNull('lease_token')->where('last_unique_at', '<', $cutoff)->orderBy('id');
            $locked = (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) ? $query->lock('for update skip locked') : $query->lockForUpdate())
                ->pluck('id')->all();
            if ($locked === []) {
                return 0;
            }

            return DB::table('release_verification_candidates')->whereIn('id', $locked)->update(['status' => 'ready',
                'next_attempt_at' => $now->format('Y-m-d H:i:s'),
                'reason' => 'Upload stopped growing before it was complete; queued for partial publication.']);
        });
    }

    /** Return a claimed partial upload that fails publication policy to waiting, for one more hold window. */
    public function deferPartial(object $claim, string $reason): void
    {
        DB::table('release_verification_candidates')->where('id', $claim->id)->where('lease_token', $claim->lease_token)
            ->where('version', $claim->version)->whereNull('release_id')->update(['status' => 'waiting_upload',
                'reason' => mb_strcut('Partial publication deferred: '.$reason, 0, 2000, 'UTF-8'), 'lease_token' => null, 'lease_until' => null,
                'next_attempt_at' => now()->addHours(max(1, (int) config('release_verification.partial_after_hours', 4)))]);
    }

    public function capacityAvailable(): bool
    {
        if (! config('release_verification.required', true) || ! Schema::hasTable('release_verification_state')) {
            return true;
        }

        $capacity = (int) config('release_verification.pending_bytes');
        $reserve = min(33554432, (int) ($capacity / 10));

        return (int) DB::table('release_verification_state')->where('id', 1)->value('pending_bytes') < $capacity - $reserve;
    }

    /** @return array<string, mixed> */
    private function snapshot(object $collection, bool $currentReads = false): array
    {
        $manifest = $this->snapshotHeaders($collection, $currentReads);
        $manifest['legacy_release_id'] = $this->legacyReleaseId($collection);

        return $manifest;
    }

    /** @return array<string, mixed> */
    private function snapshotHeaders(object $collection, bool $currentReads): array
    {
        $groups = Schema::hasTable('collection_groups')
            ? $this->snapshotQuery('collection_groups', $currentReads)->where('collections_id', $collection->id)->pluck('group_name')->all() : [];
        if ($groups === []) {
            $groups = [(string) $this->snapshotQuery('usenet_groups', $currentReads)->where('id', $collection->groups_id)->value('name')];
        }
        sort($groups);
        $files = [];
        $metadataBytes = 0;
        $binaries = $this->snapshotQuery('binaries', $currentReads)->where('collections_id', $collection->id)->orderBy('binaryhash')
            ->select(['id', 'binaryhash', 'name', 'totalparts'])->cursor();
        foreach ($binaries->chunk(100) as $batch) {
            $segmentsByBinary = [];
            foreach ($this->snapshotQuery('parts', $currentReads)->whereIn('binaries_id', $batch->pluck('id')->all())
                ->orderBy('binaries_id')->orderBy('partnumber')
                ->select(['binaries_id', 'partnumber', 'messageid', 'size'])->cursor() as $part) {
                $metadataBytes += 128 + strlen($part->messageid);
                if ($metadataBytes > 16777216) {
                    throw new CandidateMetadataBudgetExceeded('Candidate exceeds metadata budget; headers retained.');
                }
                $segmentsByBinary[$part->binaries_id][] = ['number' => (int) $part->partnumber,
                    'messageid' => $part->messageid, 'bytes' => (int) $part->size];
            }
            foreach ($batch as $binary) {
                $files[] = ['key' => bin2hex($binary->binaryhash), 'subject' => $binary->name,
                    'totalparts' => (int) $binary->totalparts, 'segments' => $segmentsByBinary[$binary->id] ?? []];
            }
        }

        $expected = (int) $collection->totalfiles;
        if ((int) $collection->filecheck === 4) {
            $expected = 0;
            foreach ($files as $file) {
                if (preg_match('/"[^"\r\n]+"|\'[^\'\r\n]+\'/', $file['subject']) === 1) {
                    $expected = max($expected, NzbManifestReader::declaredFileCount($file['subject']));
                }
            }
        }

        return ['legacy_release_id' => null,
            'identity' => bin2hex($collection->collectionhash), 'subject' => $collection->subject,
            'fromname' => $collection->fromname, 'date' => $collection->date, 'groups_id' => (int) $collection->groups_id,
            // Legacy insertion could overwrite the declared count with observed
            // files. Recover explicit counters outside quoted filenames only.
            'regex_id' => (int) $collection->collection_regexes_id,
            'expected_files' => $expected,
            'groups' => $groups, 'files' => $files, 'conflict' => false];
    }

    /** Legacy publication takes capacity before its release row; retain that lock order. */
    private function legacyReleaseId(object $collection): ?int
    {
        if ((int) $collection->filecheck !== 4 || ! isset($collection->releases_id)) {
            return null;
        }
        $id = DB::table('releases')->where('id', $collection->releases_id)
            ->where('nzbstatus', NzbService::NZB_NONE)->lockForUpdate()->value('id');

        return $id === null ? null : (int) $id;
    }

    /** Current reads avoid a caller-owned repeatable-read snapshot omitting committed headers. */
    private function snapshotQuery(string $table, bool $currentReads): Builder
    {
        $query = DB::table($table);

        return $currentReads ? $query->sharedLock() : $query;
    }

    /** @param array<string, mixed> $old
     * @param  array<string, mixed>  $new
     * @return array<string, mixed>
     */
    private function merge(array $old, array $new): array
    {
        $files = array_column($old['files'], null, 'key');
        foreach ($new['files'] as $file) {
            $key = $file['key'];
            if (! isset($files[$key])) {
                $files[$key] = $file;

                continue;
            }
            $previous = $files[$key];
            if ($previous['subject'] !== $file['subject'] || $previous['totalparts'] !== $file['totalparts']) {
                $old['conflict'] = true;
            }
            $parts = array_column($previous['segments'], null, 'number');
            foreach ($file['segments'] as $segment) {
                if (isset($parts[$segment['number']]) && $parts[$segment['number']] !== $segment) {
                    $old['conflict'] = true;
                } else {
                    $parts[$segment['number']] = $segment;
                }
            }
            ksort($parts);
            $files[$key]['segments'] = array_values($parts);
        }
        ksort($files);
        $old['files'] = array_values($files);
        $old['groups'] = array_values(array_unique(array_merge($old['groups'], $new['groups'])));
        sort($old['groups']);
        $oldExpected = (int) $old['expected_files'];
        $newExpected = (int) $new['expected_files'];
        if ($old['fromname'] !== $new['fromname'] || ($oldExpected > 0 && $newExpected > 0 && $oldExpected !== $newExpected)) {
            $old['conflict'] = true;
        }
        $old['expected_files'] = max(0, $oldExpected, $newExpected);
        if (($new['legacy_release_id'] ?? null) !== null) {
            if (($old['legacy_release_id'] ?? null) !== null && $old['legacy_release_id'] !== $new['legacy_release_id']) {
                $old['conflict'] = true;
            } else {
                $old['legacy_release_id'] = $new['legacy_release_id'];
            }
        }

        return $old;
    }

    /** @param (callable(): bool)|null $mayStart */
    public function expire(?callable $mayStart = null): void
    {
        if ($mayStart !== null && ! $mayStart()) {
            return;
        }
        $ids = DB::table('release_verification_candidates')->whereNull('release_id')
            ->whereIn('status', ['ready', 'waiting_upload', 'pending', 'unknown', 'unrepairable', 'verifying', 'dirty', 'metadata_deferred', 'metadata_review'])
            ->where('last_unique_at', '<', now()->subHours((int) config('release_verification.expiry_hours')))
            ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<', now()))
            ->orderBy('id')->limit(100)->pluck('id');
        foreach ($ids as $id) {
            if ($mayStart !== null && ! $mayStart()) {
                break;
            }
            DB::transaction(function () use ($id): void {
                $this->ledger->enter();
                $row = DB::table('release_verification_candidates')->where('id', $id)->lockForUpdate()->first();
                if ($row === null || $row->release_id !== null
                    || ! in_array($row->status, ['ready', 'waiting_upload', 'pending', 'unknown', 'unrepairable', 'verifying', 'dirty', 'metadata_deferred', 'metadata_review'], true)
                    || $row->last_unique_at >= now()->subHours((int) config('release_verification.expiry_hours'))->format('Y-m-d H:i:s')
                    || ($row->lease_until !== null && $row->lease_until >= now()->format('Y-m-d H:i:s'))) {
                    return;
                }
                DB::table('release_verification_candidates')->where('id', $id)->update(['status' => in_array($row->status, ['metadata_deferred', 'metadata_review'], true) ? 'metadata_expired' : 'expired',
                    'version' => (int) $row->version + 1,
                    'manifest_path' => '', 'manifest_bytes' => 0, 'lease_token' => null, 'lease_until' => null]);
                $this->ledger->adjust(-(int) $row->manifest_bytes);
                DB::afterCommit(function () use ($row): void {
                    $this->store->deleteIfUnreferenced($row->manifest_path);
                });
            }, 5);
        }
        $this->expireRetainedPartials($mayStart);
    }

    /**
     * Release the inventory a partial publication kept for late articles once none arrive within the expiry window.
     *
     * @param  (callable(): bool)|null  $mayStart
     */
    private function expireRetainedPartials(?callable $mayStart): void
    {
        $cutoff = now()->subHours((int) config('release_verification.expiry_hours'))->format('Y-m-d H:i:s');
        $ids = DB::table('release_verification_candidates')->whereNotNull('release_id')->where('manifest_path', '<>', '')
            ->where('last_unique_at', '<', $cutoff)->orderBy('id')->limit(100)->pluck('id');
        foreach ($ids as $id) {
            if ($mayStart !== null && ! $mayStart()) {
                break;
            }
            DB::transaction(function () use ($id, $cutoff): void {
                $this->ledger->enter();
                $row = DB::table('release_verification_candidates')->where('id', $id)->lockForUpdate()->first();
                if ($row === null || $row->release_id === null || $row->manifest_path === '' || $row->last_unique_at >= $cutoff) {
                    return;
                }
                DB::table('release_verification_candidates')->where('id', $id)->update(['manifest_path' => '', 'manifest_bytes' => 0]);
                $this->ledger->adjust(-(int) $row->manifest_bytes);
                DB::afterCommit(function () use ($row): void {
                    $this->store->deleteIfUnreferenced($row->manifest_path);
                });
            }, 5);
        }
    }

    /**
     * @param  (callable(int): bool)|null  $acquireCandidate
     *
     * @phpstan-impure
     */
    public function claim(?callable $acquireCandidate = null, ?bool $preferReady = null): ?object
    {
        $preferReady ??= ++$this->claimSelections % 5 !== 0;
        // Keep legacy/stale leases progressing even while ready candidates are arriving.
        $queues = $preferReady ? [['ready'], ['pending', 'unknown', 'verifying']] : [['pending', 'unknown', 'verifying'], ['ready']];
        // Every worker reads the same oldest window. When concurrent claimers drained the rows this
        // worker tried, read it again rather than stopping while eligible work remains due.
        for ($pass = 0; $pass < self::CLAIM_WINDOW_PASSES; $pass++) {
            $contended = false;
            foreach ($queues as $statuses) {
                $ids = $this->claimQueueIds($statuses);
                $spread = false;
                while ($ids !== []) {
                    $id = array_shift($ids);
                    $acquired = false;
                    $eligible = false;
                    $claimed = DB::transaction(function () use ($id, $acquireCandidate, &$acquired, &$eligible): ?object {
                        $eligible = false;
                        $query = $this->claimableCandidates()->where('id', $id);
                        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                            $query->forceIndex('PRIMARY')->lock('for update skip locked');
                        } else {
                            $query->lockForUpdate();
                        }
                        $row = $query->first();
                        if ($row === null) {
                            return null;
                        }
                        $eligible = true;
                        if ($acquireCandidate !== null) {
                            if (! $acquireCandidate((int) $row->id)) {
                                return null;
                            }
                            $acquired = true;
                        }
                        $token = (string) Str::uuid();
                        DB::table('release_verification_candidates')->where('id', $row->id)->update([
                            'status' => 'verifying', 'lease_token' => $token,
                            'lease_until' => now()->addSeconds((int) config('release_verification.lease_seconds')),
                        ]);
                        $row->lease_token = $token;

                        return $row;
                    }, 5);
                    // A rolled-back lease may still own its file mutex; let the caller release it.
                    if ($claimed !== null || $acquired) {
                        return $claimed;
                    }
                    if (! $eligible) {
                        $contended = true;
                        // Losers would otherwise advance through the window in lock-step behind
                        // the winner; spread them over the rest of it once they have lost a row.
                        if (! $spread) {
                            shuffle($ids);
                            $spread = true;
                        }
                    }
                }
            }
            if (! $contended) {
                break;
            }
        }

        return null;
    }

    /** @param list<string> $statuses
     * @return list<int>
     */
    private function claimQueueIds(array $statuses): array
    {
        $cutoff = now();
        $rows = [];
        // Fixing the leading status key lets verification_due serve each ORDER BY / LIMIT.
        foreach ($statuses as $status) {
            foreach ($this->claimableCandidates($status, $cutoff)->orderBy('next_attempt_at')->orderBy('id')
                ->limit(100)->get(['id', 'next_attempt_at']) as $row) {
                // A status change between reads must not consume two slots in the claim window.
                $rows[(int) $row->id] ??= $row;
            }
        }
        usort($rows, static fn (object $a, object $b): int => [$a->next_attempt_at, (int) $a->id]
            <=> [$b->next_attempt_at, (int) $b->id]);

        return array_map(static fn (object $row): int => (int) $row->id, array_slice($rows, 0, 100));
    }

    private function claimableCandidates(?string $status = null, ?Carbon $cutoff = null): Builder
    {
        $cutoff ??= now();
        $query = DB::table('release_verification_candidates')->whereNull('release_id');
        if ($status === null) {
            $query->whereIn('status', self::CLAIMABLE_STATUSES);
        } else {
            $query->where('status', $status);
        }

        return $query->where('next_attempt_at', '<=', $cutoff)
            ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<', $cutoff));
    }

    public function heartbeat(int $id, string $token): void
    {
        $changed = DB::table('release_verification_candidates')->where('id', $id)->where('lease_token', $token)
            ->where('lease_until', '>=', now())->update(['lease_until' => now()->addSeconds((int) config('release_verification.lease_seconds'))]);
        if ($changed !== 1 && ! DB::table('release_verification_candidates')->where('id', $id)->where('lease_token', $token)->where('lease_until', '>=', now())->exists()) {
            throw new RuntimeException('Verification lease was lost.');
        }
    }

    public function finish(object $claim, VerificationResult $result): void
    {
        DB::table('release_verification_candidates')->where('id', $claim->id)->where('lease_token', $claim->lease_token)
            ->where('version', $claim->version)->update(['status' => $result->waitingForHeaders ? 'waiting_upload' : ($result->verdict === 'unrepairable' ? 'unrepairable' : 'unknown'), 'reason' => mb_strcut($result->reason, 0, 2000, 'UTF-8'),
                'lease_token' => null, 'lease_until' => null, 'next_attempt_at' => now()->addSeconds((int) config('release_verification.retry_seconds'))]);
    }

    public function rejectByPolicy(object $claim, string $reason): void
    {
        DB::transaction(function () use ($claim, $reason): void {
            $this->ledger->enter();
            $candidate = DB::table('release_verification_candidates')->where('id', $claim->id)
                ->where('lease_token', $claim->lease_token)->where('version', $claim->version)->whereNull('release_id')->lockForUpdate()->first();
            if ($candidate === null) {
                throw new RuntimeException('Stale verification result cannot filter a candidate.');
            }
            DB::table('release_verification_candidates')->where('id', $claim->id)->update(['status' => 'filtered',
                'reason' => mb_strcut($reason, 0, 2000, 'UTF-8'), 'lease_token' => null, 'lease_until' => null,
                'manifest_path' => '', 'manifest_bytes' => 0]);
            $this->ledger->adjust(-(int) $candidate->manifest_bytes);
            DB::afterCommit(function () use ($candidate): void {
                $this->store->deleteIfUnreferenced($candidate->manifest_path);
            });
        }, 5);
    }
}
