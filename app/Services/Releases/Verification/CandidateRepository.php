<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Exceptions\CandidateMetadataBudgetExceeded;
use App\Models\Release;
use App\Services\CollectionCleanupService;
use App\Services\Nzb\NzbArticleCoverage;
use App\Services\Nzb\NzbService;
use App\Services\Nzb\NzbSourceGuard;
use App\Services\Releases\ReleaseGroupResolver;
use App\Support\NzbPasswordMetadata;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class CandidateRepository
{
    public const string FFI_CDEF = 'int64_t nntmux_w34_candidate_repository_nfo_processor(uint32_t operation,const char *input,size_t length,char *output,size_t capacity);';

    private const int NATIVE_MAX_BYTES = 16777216;

    /**
     * Rust stays opt-in: the FFI round trip is slower than these PHP helpers (< 1.5x); see
     * tests/Unit/RustCandidateRepositoryNfoProcessorParityTest.php.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    private const string PARTIAL_QUEUED = 'Upload stopped growing before it was complete; queued for partial publication.';

    private const string PARTIAL_DEFERRED = 'Partial publication deferred: ';

    private const array CLAIMABLE_STATUSES = ['ready', 'pending', 'unknown', 'verifying'];

    private const array EXPIRABLE_STATUSES = [
        'ready',
        'waiting_upload',
        'pending',
        'unknown',
        'header_rejected',
        'unrepairable',
        'verifying',
        'dirty',
        'metadata_deferred',
        'metadata_review',
    ];

    /** Window reads per claim while concurrent claimers keep winning the rows this worker tried. */
    private const int CLAIM_WINDOW_PASSES = 10;

    private int $claimSelections = 0;

    public function __construct(private readonly ManifestStore $store, private readonly CollectionCleanupService $cleanup,
        private readonly VerificationLedger $ledger = new VerificationLedger) {}

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    /**
     * Run one Wave 34.2 helper (operations 0-6) in the Rust library over packed native-endian slots.
     * Null when the library is missing, the input is outside the contract, or Rust declines it.
     */
    public static function evaluateNative(int $operation, string $input): ?string
    {
        try {
            if ($operation < 0 || $input === '' || \strlen($input) > self::NATIVE_MAX_BYTES) {
                return null;
            }
            $ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
            if (! $ffi instanceof FFI) {
                return null;
            }
            $capacity = 0;
            $output = RustPipelineLibrary::obtainScratch($ffi, 256, $capacity);
            if ($output === null) {
                return null;
            }
            $capacity = min($capacity, self::NATIVE_MAX_BYTES);
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w34_candidate_repository_nfo_processor($operation, $input, \strlen($input), $output, $capacity);
            if (\is_int($written) && $written < -1 && -$written > $capacity && -$written <= self::NATIVE_MAX_BYTES) {
                $output = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($output === null) {
                    return null;
                }
                $capacity = min($capacity, self::NATIVE_MAX_BYTES);
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w34_candidate_repository_nfo_processor($operation, $input, \strlen($input), $output, $capacity);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return $written === 0 ? '' : FFI::string($output, $written);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Native-endian `i64` slots of a helper result, or null unless it holds exactly $count of them.
     *
     * @return list<int>|null
     */
    public static function nativeSlots(?string $result, int $count): ?array
    {
        if ($result === null || \strlen($result) !== $count * 8) {
            return null;
        }
        $slots = array_values(unpack('q'.$count, $result) ?: []);

        return \count($slots) === $count ? $slots : null;
    }

    /**
     * The order-independent digest of an upload's article ids: SHA-256 over the sorted ids, newline-joined.
     *
     * @param  list<string>  $ids
     */
    public static function articleIdentityDigest(array $ids): string
    {
        return self::usesRustFfi() ? self::articleIdentityDigestNative($ids) : self::articleIdentityDigestPhp($ids);
    }

    /** @param list<string> $ids */
    public static function articleIdentityDigestPhp(array $ids): string
    {
        sort($ids);

        return hash('sha256', implode("\n", $ids));
    }

    /** @param list<string> $ids */
    public static function articleIdentityDigestNative(array $ids): string
    {
        $lengths = array_map(strlen(...), $ids);
        $result = self::evaluateNative(0, pack('q*', \count($ids), ...$lengths).implode('', $ids));
        if ($result !== null && \strlen($result) === 64 && strspn($result, '0123456789abcdef') === 64) {
            return $result;
        }

        return self::articleIdentityDigestPhp($ids);
    }

    /**
     * A stalled upload not yet tried as a partial, or queued for that try: its last new article is no older
     * than $cutoff, and it waits for its upload without a deferred partial attempt or is queued for one.
     */
    public static function isOwedPartialAttempt(?string $lastUniqueAt, string $cutoff, string $status, ?string $reason): bool
    {
        return self::usesRustFfi() ? self::isOwedPartialAttemptNative($lastUniqueAt, $cutoff, $status, $reason)
            : self::isOwedPartialAttemptPhp($lastUniqueAt, $cutoff, $status, $reason);
    }

    public static function isOwedPartialAttemptPhp(?string $lastUniqueAt, string $cutoff, string $status, ?string $reason): bool
    {
        return $lastUniqueAt >= $cutoff
            && (($status === 'waiting_upload' && ! str_starts_with((string) ($reason ?? ''), self::PARTIAL_DEFERRED))
                || ($status === 'ready' && $reason === self::PARTIAL_QUEUED));
    }

    public static function isOwedPartialAttemptNative(?string $lastUniqueAt, string $cutoff, string $status, ?string $reason): bool
    {
        $slots = self::nativeSlots(self::evaluateNative(1, pack('q6', (int) ($lastUniqueAt !== null), \strlen($lastUniqueAt ?? ''), \strlen($cutoff),
            \strlen($status), (int) ($reason !== null), \strlen($reason ?? '')).$lastUniqueAt.$cutoff.$status.$reason), 1);
        if ($slots !== null && \in_array($slots[0], [0, 1], true)) {
            return $slots[0] === 1;
        }

        return self::isOwedPartialAttemptPhp($lastUniqueAt, $cutoff, $status, $reason);
    }

    /** Whether pending candidate bytes leave room under the capacity, less its reserve (a tenth, at most 32 MiB). */
    public static function hasPendingCapacity(int $pendingBytes, int $capacity): bool
    {
        return self::usesRustFfi() ? self::hasPendingCapacityNative($pendingBytes, $capacity) : self::hasPendingCapacityPhp($pendingBytes, $capacity);
    }

    public static function hasPendingCapacityPhp(int $pendingBytes, int $capacity): bool
    {
        $reserve = min(33554432, (int) ($capacity / 10));

        return $pendingBytes < $capacity - $reserve;
    }

    public static function hasPendingCapacityNative(int $pendingBytes, int $capacity): bool
    {
        $slots = self::nativeSlots(self::evaluateNative(2, pack('q2', $pendingBytes, $capacity)), 1);
        if ($slots !== null && \in_array($slots[0], [0, 1], true)) {
            return $slots[0] === 1;
        }

        return self::hasPendingCapacityPhp($pendingBytes, $capacity);
    }

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
                // Lock all of the upload's candidates first, in primary-key order like every batched candidate
                // update; the lookups below then only re-read rows this transaction already holds.
                $this->lockSourceCandidates($sourceIdentity, $currentReads, static fn (): bool => true);
                $existing = $this->lockLatestCandidate($sourceIdentity, $currentReads);
                // A partial's inventory is held by the candidate its release points at: the published partial
                // itself, or the pending generation late articles moved it to. Late articles join the newest
                // holder they extend without conflict, past any newer post under the same hash; headers that
                // conflict with a holder never merge into it.
                $holder = null;
                $apart = false;
                $holders = [];
                if ($existing !== null) {
                    $holders = $this->lockPartialHolders($sourceIdentity, $currentReads);
                    if ($holders !== [] && $snapshotFailure === null) {
                        try {
                            // A filtered newer post skipped the snapshot; these headers may still be a partial's.
                            $manifest ??= $this->snapshotHeaders($collection, $currentReads);
                            $holder = $this->lateArticleOwner($collection, $sourceIdentity, $holders, $manifest, $currentReads);
                        } catch (CandidateMetadataBudgetExceeded $error) {
                            $snapshotFailure = $error;
                        }
                        $apart = $holder === null && in_array((int) $existing->id, array_map(static fn (object $held): int => (int) $held->id, $holders), true);
                    }
                }
                // A pending generation whose partial was deleted holds no partial any more: retire it (its
                // retained articles must not publish anew) so it neither absorbs nor diverts later uploads.
                $retiredBytes = 0;
                $identityAfter = null;
                if ($existing !== null && self::tracksUpgrades()) {
                    $held = array_map(static fn (object $held): int => (int) $held->id, $holders);
                    $orphans = $this->lockSourceCandidates($sourceIdentity, $currentReads, fn (object $row): bool => $row->release_id === null
                        && $row->upgrades_release_id !== null && $row->manifest_path !== '' && ! in_array((int) $row->id, $held, true)
                        // One a worker is verifying is left to it: the publisher abandons it.
                        && ($row->lease_until === null || $row->lease_until < now()->format('Y-m-d H:i:s')));
                    foreach ($orphans as $orphan) {
                        // The version stays, as when a worker retires a candidate, so an outcome is still recognized.
                        DB::table('release_verification_candidates')->where('id', $orphan->id)->update(['status' => 'expired',
                            'reason' => 'The partial release it would upgrade was deleted.', 'manifest_path' => '', 'manifest_bytes' => 0,
                            'lease_token' => null, 'lease_until' => null, 'upgrades_release_id' => null]);
                        $retiredBytes += (int) $orphan->manifest_bytes;
                        DB::afterCommit(fn () => $this->store->deleteIfUnreferenced($orphan->manifest_path));
                    }
                    if ($orphans->contains('id', $existing->id)) {
                        // Never revive the retired row: its identity and timing belonged to the deleted partial.
                        $identityAfter = sha1($sourceIdentity.':generation:'.$existing->id);
                        $existing = null;
                    }
                }
                if ($holder === null && ($existing !== null || $identityAfter !== null)) {
                    // Not a partial's articles: they join the newest pending candidate that holds no partial's
                    // inventory. Before partials that was always the newest row; posts kept apart from a
                    // partial can leave one behind newer holders or generations, whatever became of them.
                    // Where partials share the hash, headers that would spoil that candidate are kept apart too.
                    $own = $this->lockLatestUnheld($sourceIdentity, array_map(static fn (object $held): int => (int) $held->id, $holders), $currentReads);
                    $shared = $holders !== [] || $identityAfter !== null || ($own !== null && (int) $own->id !== (int) ($existing->id ?? 0));
                    if ($own !== null && $shared && $snapshotFailure === null) {
                        $manifest ??= $this->snapshotHeaders($collection, $currentReads);
                        if ($this->spoils($this->store->read($own->manifest_path), $this->merge($this->store->read($own->manifest_path), $manifest))) {
                            $own = null;
                            $apart = $existing !== null;
                        }
                    }
                    if ($own !== null && (int) $own->id !== (int) ($existing->id ?? 0)) {
                        $existing = $own;
                        $apart = false;
                        $identityAfter = null;
                    } elseif ($own === null && $existing !== null && $existing->release_id === null && ($existing->upgrades_release_id ?? null) !== null) {
                        // The newest row is an orphaned generation still leased to a worker: start a new one.
                        $apart = true;
                    }
                }
                if ($holder !== null && ($holder->release_id === null || (int) $holder->id === (int) ($existing->id ?? 0))) {
                    // A pending generation is merged like any candidate; the published partial itself as the
                    // newest candidate takes the usual path below.
                    $existing = $holder;
                    $holder = null;
                }
                if ($existing !== null && $existing->status === 'filtered' && $holder === null && $holders !== [] && $snapshotFailure !== null) {
                    // Unreadable headers might be a live partial's late articles, not the filtered post's replay;
                    // keep them rather than delete them as one.
                    throw $snapshotFailure;
                }
                if ($existing !== null && $existing->status === 'filtered' && $holder === null) {
                    if ($existing->release_id !== null || (int) $existing->groups_id !== (int) $collection->groups_id
                        || $existing->manifest_path !== '' || (int) $existing->manifest_bytes !== 0) {
                        // Orphans retired above still give back their capacity.
                        if ($retiredBytes > 0) {
                            $this->ledger->adjust(-$retiredBytes);
                        }

                        return;
                    }
                    $usablePart = DB::table('binaries as b')->join('parts as p', 'p.binaries_id', '=', 'b.id')
                        ->where('b.collections_id', $collectionId)->where('p.size', '>', 0)->where('p.messageid', '<>', '')
                        ->lockForUpdate()->first(['p.binaries_id']);
                    if ($usablePart !== null) {
                        $this->cleanup->deleteCollectionsAndDescendants([$collectionId], 'Policy-filtered replay', false, manifestArchived: true);
                    }
                    if ($retiredBytes > 0) {
                        $this->ledger->adjust(-$retiredBytes);
                    }

                    return;
                }
                if ($snapshotFailure !== null) {
                    throw $snapshotFailure;
                }
                $identity = $existing->identity ?? ($identityAfter ?? $sourceIdentity);
                // A partial publication retains its inventory: late articles start a generation that
                // merges it and upgrades that release in place, instead of publishing only the late fragment.
                $previous = null;
                $merges = false;
                if ($holder !== null) {
                    $identity = $existing === null ? $identityAfter : sha1($sourceIdentity.':generation:'.$existing->id);
                    $previous = $holder;
                    $merges = true;
                    $existing = null;
                } elseif ($apart && $existing !== null) {
                    // Another post under a holder's hash: its own generation, leaving the holder untouched.
                    $identity = sha1($sourceIdentity.':generation:'.$existing->id);
                    $existing = null;
                } elseif ($existing !== null && $existing->release_id !== null) {
                    $identity = sha1($sourceIdentity.':generation:'.$existing->id);
                    // A retained inventory whose partial release is gone (deleted elsewhere) is released, not merged.
                    $previous = $existing->manifest_path !== '' ? $existing : null;
                    $merges = $previous !== null && $this->retainsLivePartial($previous);
                    $existing = null;
                }
                $manifest ??= $this->snapshotHeaders($collection, $currentReads);
                $manifest['legacy_release_id'] = $this->legacyReleaseId($collection);
                if ($merges) {
                    $retained = $this->store->read($previous->manifest_path);
                    // The publication consumed any legacy release; the next generation publishes anew.
                    $retained['legacy_release_id'] = null;
                    $merged = $this->merge($retained, $manifest);
                    if ($this->spoils($retained, $merged)) {
                        // Conflicting headers (another post under the same hash) would poison the retained
                        // inventory; leave the partial and its inventory as they are and archive these apart.
                        $previous = null;
                        $merges = false;
                    } else {
                        $manifest = $merged;
                    }
                }
                if ($merges) {
                    if ($this->articleIdentity($retained) === $this->articleIdentity($manifest) && ! ($manifest['conflict'] ?? false)) {
                        // A replay of headers the partial already holds adds no articles, but a crosspost replay
                        // can add groups: record those on the partial release before discarding the headers.
                        foreach (array_unique(app(ReleaseGroupResolver::class)->idsByName(array_values(array_diff($manifest['groups'], $retained['groups'])))) as $groupId) {
                            DB::table('releases_groups')->insertOrIgnore(['releases_id' => (int) $previous->release_id, 'groups_id' => $groupId]);
                        }
                        $this->cleanup->deleteCollectionsAndDescendants([$collectionId], 'Archived for verification', false, manifestArchived: true);
                        if ($retiredBytes > 0) {
                            $this->ledger->adjust(-$retiredBytes);
                        }

                        return;
                    }
                    $manifest['upgrades_release_id'] = (int) $previous->release_id;
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
                if (($merges || $existing !== null) && self::metadataBytes($manifest) > OversizedCollectionDeferrals::budget()) {
                    // Each snapshot fits, but the merged inventory would not: keep these headers for a later pass
                    // (or a raised budget) before anything is written or charged to the ledger.
                    throw new CandidateMetadataBudgetExceeded('Merged candidate exceeds metadata budget; headers retained.');
                }
                $path = $prepared === null ? $this->store->write($manifest) : $this->store->writePrepared($prepared);
                $size = $this->store->size($path);
                $arrivalAt = Carbon::parse($collection->last_seen_at ?? $collection->dateadded ?? $collection->added ?? now())->format('Y-m-d H:i:s');
                if (is_string($collection->collectionhash)
                    && app(OversizedCollectionDeferrals::class)->unlockedByRaisedBudget($collectionId, $collection->collectionhash)) {
                    $arrivalAt = max($arrivalAt, now()->format('Y-m-d H:i:s'));
                }
                $lastUniqueAt = $existing === null ? (! $merges ? $arrivalAt : max($arrivalAt, $previous->last_unique_at))
                    : ($newArticles ? max($arrivalAt, $existing->last_unique_at) : $existing->last_unique_at);
                $expired = $lastUniqueAt < now()->subHours((int) config('release_verification.expiry_hours'))->format('Y-m-d H:i:s');
                $delta = ($expired ? 0 : $size) - (int) ($existing->manifest_bytes ?? 0) - (int) ($previous->manifest_bytes ?? 0) - $retiredBytes;
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
                if ($merges && self::tracksUpgrades()) {
                    $values['upgrades_release_id'] = (int) $previous->release_id;
                }
                if ($changed || $dirty) {
                    $assessment = $headerAssessment ?? (new CandidateHeaderInventory)->assessment($manifest);
                    $values += ['version' => (int) ($existing->version ?? 0) + 1, 'last_unique_at' => $lastUniqueAt,
                        'next_attempt_at' => now(), 'status' => self::admittedStatus($assessment['status']), 'header_check_pending' => $assessment['status'] === CandidateHeaderInventory::REJECTED, 'lease_token' => null, 'lease_until' => null, 'reason' => $assessment['reason']];
                }
                if ($expired) {
                    $values = array_replace($values, ['status' => 'expired', 'last_unique_at' => $lastUniqueAt,
                        'manifest_path' => '', 'manifest_bytes' => 0, 'lease_token' => null, 'lease_until' => null]
                        + (self::tracksUpgrades() ? ['upgrades_release_id' => null] : []));
                    if ($existing !== null && Release::hasLateArticleFlag()) {
                        // An expired pending generation can no longer upgrade its partial.
                        // By primary key: an update through the pointer index would gap-lock it like a locking read.
                        $pointed = self::tracksUpgrades() ? array_values(array_filter([(int) ($existing->release_id ?? 0), (int) ($existing->upgrades_release_id ?? 0)])) : null;
                        if ($pointed === null || $pointed !== []) {
                            $this->releaseLateArticleFence($pointed ?? DB::table('releases')->where('late_article_candidate_id', $existing->id)->pluck('id')->all(), (int) $existing->id);
                        }
                    }
                }
                if ($existing !== null && self::tracksRetention() && ($existing->retained_for_release_id ?? null) !== null) {
                    // A retaining row keeps its expiry stamp in step with last_unique_at.
                    $values += $expired ? ['retained_for_release_id' => null, 'retained_last_unique_at' => null]
                        : (array_key_exists('last_unique_at', $values) ? ['retained_last_unique_at' => $values['last_unique_at']] : []);
                }
                if ($existing === null) {
                    $holderId = (int) DB::table('release_verification_candidates')->insertGetId($values);
                } else {
                    $holderId = (int) $existing->id;
                    DB::table('release_verification_candidates')->where('id', $existing->id)->update($values);
                }
                if ($previous !== null) {
                    // The new generation now holds the retained inventory and its capacity, and becomes the
                    // only candidate that may upgrade the partial; an already expired one settles it.
                    DB::table('release_verification_candidates')->where('id', $previous->id)->update(['manifest_path' => '', 'manifest_bytes' => 0]
                        + (self::tracksRetention() ? ['retained_for_release_id' => null, 'retained_last_unique_at' => null] : []));
                    if ($merges && Release::hasLateArticleFlag()) {
                        if ($expired) {
                            $this->releaseLateArticleFence([(int) $previous->release_id], (int) $previous->id);
                        } else {
                            DB::table('releases')->where('id', $previous->release_id)->where('late_article_candidate_id', $previous->id)
                                ->update(['late_article_candidate_id' => $holderId]);
                        }
                    }
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
                        'version' => (int) ($existing->version ?? 0) + 1, 'status' => self::admittedStatus($assessment['status']), 'header_check_pending' => $assessment['status'] === CandidateHeaderInventory::REJECTED, 'reason' => $assessment['reason'],
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
            || ! in_array($existing->status, ['ready', 'waiting_upload', 'pending', 'unknown', 'header_rejected', 'verifying', 'metadata_deferred'], true)
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
            'version' => (int) $existing->version + 1, 'status' => self::admittedStatus($assessment['status']), 'header_check_pending' => $assessment['status'] === CandidateHeaderInventory::REJECTED, 'reason' => $assessment['reason'],
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
        // Capacity released by settled holders is returned once every collection's candidates are locked: the
        // ledger row is the last lock, as for archive() and publication. With file manifests the global writer
        // lock comes first instead, as archive() takes it before any candidate lock.
        $released = 0;
        if (! $this->ledger->concurrent()) {
            try {
                $this->ledger->enter();
            } catch (RuntimeException) {
                // Verification capacity was never initialized, so nothing was archived to invalidate.
                return;
            }
        }
        foreach (DB::table('collections')->whereIn('id', $ids)->orderBy('id')->get() as $collection) {
            $identity = bin2hex($collection->collectionhash);
            $candidates = DB::table('release_verification_candidates')->where('source_identity', $identity)
                ->where(fn ($query) => $query->whereNull('release_id')->orWhere('manifest_path', '<>', ''))
                ->whereNotIn('status', ['expired'])->orderBy('id')->lockForUpdate()->get()->sortByDesc('id')->values();
            if ($candidates->every(static fn (object $candidate): bool => $candidate->manifest_path === '')) {
                continue;
            }
            try {
                $snapshot = $this->snapshot($collection);
            } catch (CandidateMetadataBudgetExceeded) {
                // Too large to compare (or for archive() to merge). A partial under the hash can never take these
                // late headers, and its retained inventory would otherwise wait on this collection forever.
                $released += $this->fenceOverBudget($collection, $candidates,
                    array_map(static fn (object $held): int => (int) $held->id, $this->lockPartialHolders($identity, true)));

                continue;
            }
            $heldIds = array_map(static fn (object $held): int => (int) $held->id, $this->lockPartialHolders($identity, true));
            $holders = array_values(array_filter($candidates->all(), static fn (object $candidate): bool => in_array((int) $candidate->id, $heldIds, true)));
            $owner = $holders === [] ? null : $this->lateArticleOwner($collection, $identity, $holders, $snapshot, true);
            if ($owner !== null) {
                // A partial's late articles: refresh its holder and archive them now, which merges them into the
                // generation that upgrades it. Other posts under the hash are left alone.
                $old = $this->store->read($owner->manifest_path);
                $merged = $this->merge($old, $snapshot);
                if (self::metadataBytes($merged) > OversizedCollectionDeferrals::budget()) {
                    // archive() keeps these headers for a later pass (or a raised budget) instead of merging them,
                    // so the holder keeps its inventory and its partial: only a pending upgrade under way is fenced.
                    $this->fenceOverBudget($collection, [$owner], []);

                    continue;
                }
                if ($this->articleIdentity($old) !== $this->articleIdentity($merged)) {
                    DB::table('release_verification_candidates')->where('id', $owner->id)->update($owner->release_id === null
                        ? ['status' => 'dirty', 'lease_token' => null, 'lease_until' => null, 'version' => $owner->version + 1, 'last_unique_at' => now()]
                        : ['last_unique_at' => now()] + (self::tracksRetention() && ($owner->retained_for_release_id ?? null) !== null ? ['retained_last_unique_at' => now()] : []));
                    DB::table('collections')->where('id', $collection->id)->where('filecheck', '!=', 4)->update(['filecheck' => 3]);
                }

                continue;
            }
            foreach ($candidates as $candidate) {
                // A partial's holder these headers don't extend is another post's; archive() keeps that apart.
                if ($candidate->manifest_path === '' || $candidate->release_id !== null || in_array((int) $candidate->id, $heldIds, true)
                    || ($candidate->upgrades_release_id ?? null) !== null) {
                    // An unheld generation is orphaned; archive() retires it.
                    continue;
                }
                $old = $this->store->read($candidate->manifest_path);
                $merged = $this->merge($old, $snapshot);
                // archive() keeps spoiling headers apart from this post wherever it shares the hash: with partials,
                // or behind a newer row (another post, or an orphaned generation it retires).
                if ($this->spoils($old, $merged) && ($heldIds !== [] || (int) DB::table('release_verification_candidates')
                    ->where('source_identity', $identity)->orderByDesc('id')->lockForUpdate()->value('id') !== (int) $candidate->id)) {
                    break;
                }
                if (self::metadataBytes($merged) > OversizedCollectionDeferrals::budget()) {
                    // archive() cannot merge these headers into it; it is verified again on what it has.
                    $released += $this->fenceOverBudget($collection, [$candidate], []);

                    continue 2;
                }
                if ($this->articleIdentity($old) !== $this->articleIdentity($merged) || ($merged['conflict'] ?? false)) {
                    DB::table('release_verification_candidates')->where('id', $candidate->id)->update([
                        'status' => 'dirty', 'lease_token' => null, 'lease_until' => null, 'version' => $candidate->version + 1,
                        'last_unique_at' => $this->articleIdentity($old) !== $this->articleIdentity($merged) ? now() : $candidate->last_unique_at]);
                    DB::table('collections')->where('id', $collection->id)->where('filecheck', '!=', 4)->update(['filecheck' => 3]);
                }

                // Only the newest pending candidate receives these headers in archive(); leave older ones alone.
                break;
            }
        }
        if ($released > 0) {
            $this->ledger->adjust(-$released);
        }
    }

    /**
     * Fence verification already under way for these candidates when late headers exceed the metadata budget:
     * it must not publish a result read before these headers. Each post stays claimable and is verified again
     * on what it has, as when ingestion failed on such a collection; the ingestion chunk is not aborted. A held
     * partial inventory is settled as it stands. Returns the capacity released, for the caller to adjust.
     *
     * @param  iterable<object>  $candidates
     * @param  list<int>  $heldIds
     */
    private function fenceOverBudget(object $collection, iterable $candidates, array $heldIds): int
    {
        $released = 0;
        foreach ($candidates as $candidate) {
            if ($candidate->manifest_path === '') {
                continue;
            }
            if (in_array((int) $candidate->id, $heldIds, true)) {
                $this->settleOverBudgetHolder($candidate);
                $released += (int) $candidate->manifest_bytes;
            } elseif ($candidate->release_id === null) {
                DB::table('release_verification_candidates')->where('id', $candidate->id)->update([
                    'lease_token' => null, 'lease_until' => null, 'version' => $candidate->version + 1]);
            }
        }
        DB::table('collections')->where('id', $collection->id)->where('filecheck', '!=', 4)->update(['filecheck' => 3]);

        return $released;
    }

    /**
     * Release a partial's retained inventory, held by its published candidate or a pending generation, when late
     * headers under its hash exceed the metadata budget: they can never be merged, so the partial stays as
     * published and workers may process it. The caller adjusts the ledger.
     */
    private function settleOverBudgetHolder(object $holder): void
    {
        $retention = self::tracksRetention() ? ['retained_for_release_id' => null, 'retained_last_unique_at' => null] : [];
        $release = $holder->release_id ?? ($holder->upgrades_release_id ?? null);
        if ($holder->release_id !== null) {
            DB::table('release_verification_candidates')->where('id', $holder->id)->update(['manifest_path' => '', 'manifest_bytes' => 0] + $retention);
        } else {
            DB::table('release_verification_candidates')->where('id', $holder->id)->update(['status' => 'expired',
                'reason' => 'Late headers under this upload exceed the metadata budget; the partial stays as published.',
                'manifest_path' => '', 'manifest_bytes' => 0, 'lease_token' => null, 'lease_until' => null, 'version' => $holder->version + 1]
                + (self::tracksUpgrades() ? ['upgrades_release_id' => null] : []) + $retention);
        }
        if ($release !== null && Release::hasLateArticleFlag()) {
            $this->releaseLateArticleFence([(int) $release], (int) $holder->id);
        }
        DB::afterCommit(fn () => $this->store->deleteIfUnreferenced($holder->manifest_path));
    }

    /**
     * The holder these headers are a partial's late articles for, as archive() routes them; hot-collection
     * invalidation must pick the same one, or it marks a candidate archive() never merges into.
     *
     * @param  list<object>  $holders
     * @param  array<string, mixed>  $manifest
     */
    private function lateArticleOwner(object $collection, string $sourceIdentity, array $holders, array $manifest, bool $currentReads): ?object
    {
        $holder = $this->partialOwner($holders, $manifest);
        if ($holder === null || ((int) $collection->filecheck === 4 && isset($collection->releases_id))) {
            // A legacy release's headers publish that release; merged into a partial, the generation could
            // upgrade neither, so they are kept apart.
            return null;
        }
        // Any established repost under the same hash (pending, holding no partial) that also takes these headers
        // makes them ambiguous: never upgrade the partial with possibly mixed articles. They then follow the
        // newest repost, as ordinary pending headers do.
        $heldIds = array_map(static fn (object $held): int => (int) $held->id, $holders);
        $upgrades = self::tracksUpgrades();
        $reposts = $this->lockSourceCandidates($sourceIdentity, $currentReads, static fn (object $row): bool => $row->release_id === null
            && $row->manifest_path !== '' && ! in_array($row->status, ['expired', 'filtered', 'metadata_expired'], true)
            && ! in_array((int) $row->id, $heldIds, true) && (! $upgrades || $row->upgrades_release_id === null));
        foreach ($reposts as $repost) {
            $old = $this->store->read($repost->manifest_path);
            if (! $this->spoils($old, $this->merge($old, $manifest))) {
                return null;
            }
        }
        // Another post under the hash published since the partial (a newer release, or one published into an
        // older legacy release after the partial's publication): its inventory is gone, so these headers'
        // compatibility with it can no longer be checked. Keep them apart.
        $partial = (int) ($holder->release_id ?? ($holder->upgrades_release_id ?? 0));
        $publishedAt = DB::table('releases')->where('id', $partial)->value('adddate');
        $published = $this->lockSourceCandidates($sourceIdentity, $currentReads, static fn (object $row): bool => $row->release_id !== null
            && (int) $row->release_id !== $partial && ! in_array((int) $row->id, $heldIds, true)
            && ((int) $row->release_id > $partial || ($publishedAt !== null && $row->verified_at !== null && $row->verified_at >= $publishedAt)));
        if ($published->isNotEmpty()) {
            return null;
        }

        return $holder;
    }

    /**
     * Candidates of this upload holding a partial's inventory (those a release's late_article_candidate_id
     * names), newest first, locked.
     *
     * @return list<object>
     */
    private function lockPartialHolders(string $sourceIdentity, bool $currentReads): array
    {
        if (! Release::hasLateArticleFlag()) {
            return [];
        }
        // Lock the upload's candidates, then read the pointers with a current (locking) read: a plain read
        // would use this transaction's older REPEATABLE READ snapshot and miss a just-published partial.
        $candidates = $this->lockSourceCandidates($sourceIdentity, $currentReads, static fn (object $row): bool => $row->manifest_path !== '');
        if ($candidates->isEmpty()) {
            return [];
        }
        $ids = array_flip(array_map('intval', $candidates->pluck('id')->all()));
        if (self::tracksUpgrades()) {
            // A holder is the published partial (release_id) or the generation upgrading it (upgrades_release_id):
            // lock those release rows by primary key. Locking by the pointer index would gap-lock its top range
            // and block, or deadlock with, every concurrent pointer move and new partial.
            $releaseIds = array_values(array_unique(array_filter(array_map('intval', [...$candidates->pluck('release_id')->all(),
                ...$candidates->pluck('upgrades_release_id')->all()]))));
            $pointers = $releaseIds === [] ? [] : DB::table('releases')->whereIn('id', $releaseIds)->orderBy('id')->sharedLock()
                ->pluck('late_article_candidate_id')->all();
        } else {
            $pointers = DB::table('releases')->whereIn('late_article_candidate_id', array_keys($ids))->sharedLock()->pluck('late_article_candidate_id')->all();
        }
        $held = array_intersect_key(array_flip(array_map('intval', array_filter($pointers, static fn (mixed $pointer): bool => $pointer !== null))), $ids);

        return array_values(array_filter($candidates->all(), static fn (object $candidate): bool => isset($held[(int) $candidate->id])));
    }

    /** @var \WeakMap<\PDO, bool>|null */
    private static ?\WeakMap $upgradeColumnSupport = null;

    /** @var \WeakMap<\PDO, bool>|null */
    private static ?\WeakMap $collectionAgeSupport = null;

    /** Whether collections record when they were added. */
    private static function tracksCollectionAge(): bool
    {
        $pdo = DB::connection()->getPdo();
        self::$collectionAgeSupport ??= new \WeakMap;

        return self::$collectionAgeSupport[$pdo] ??= Schema::hasColumn('collections', 'dateadded');
    }

    /** @var \WeakMap<\PDO, bool>|null */
    private static ?\WeakMap $retentionColumnSupport = null;

    /** Whether candidates record the release their retained inventory awaits late articles for. */
    public static function tracksRetention(): bool
    {
        $pdo = DB::connection()->getPdo();
        self::$retentionColumnSupport ??= new \WeakMap;

        return self::$retentionColumnSupport[$pdo] ??= Schema::hasColumn('release_verification_candidates', 'retained_last_unique_at');
    }

    /** Whether candidates record the partial release a pending generation upgrades. */
    private static function tracksUpgrades(): bool
    {
        $pdo = DB::connection()->getPdo();
        self::$upgradeColumnSupport ??= new \WeakMap;

        return self::$upgradeColumnSupport[$pdo] ??= Schema::hasColumn('release_verification_candidates', 'upgrades_release_id');
    }

    /**
     * A stalled upload not yet tried as a partial, or queued for that try, which expiry must leave: for one
     * expiry window past its hold, so a promotion backlog cannot keep inventory indefinitely.
     */
    private static function owedPartialAttempt(object $row): bool
    {
        return self::isOwedPartialAttempt($row->last_unique_at, self::owedPartialCutoff(), $row->status, $row->reason);
    }

    /** Uploads whose last new article is older than this are no longer owed a partial attempt. */
    private static function owedPartialCutoff(): string
    {
        return now()->subHours((int) config('release_verification.expiry_hours') + max(0, (int) config('release_verification.partial_after_hours', 4)))
            ->format('Y-m-d H:i:s');
    }

    /**
     * The newest pending candidate of this upload that holds no partial's inventory, locked.
     *
     * @param  list<int>  $heldIds
     */
    private function lockLatestUnheld(string $sourceIdentity, array $heldIds, bool $currentReads): ?object
    {
        // An unheld upgrade generation is orphaned (its partial was deleted): never admit new uploads into it,
        // even while a worker's lease keeps it from being retired.
        $upgrades = self::tracksUpgrades();

        return $this->lockSourceCandidates($sourceIdentity, $currentReads, static fn (object $row): bool => $row->release_id === null
            && ! in_array($row->status, ['expired', 'filtered', 'metadata_expired'], true) && ! in_array((int) $row->id, $heldIds, true)
            && (! $upgrades || $row->upgrades_release_id === null))->first();
    }

    /**
     * The upload's candidates matching $keep, newest first, locked. Rows for a collection hash are only
     * inserted under that collection's lock, which archive() holds, so with database manifests and no
     * caller snapshot a plain read finds them all, and they are locked by primary key: a locking read of
     * the source index would also lock its neighbours, blocking or deadlocking other uploads' admissions.
     *
     * @param  callable(object): bool  $keep
     * @return Collection<int, object>
     */
    private function lockSourceCandidates(string $sourceIdentity, bool $currentReads, callable $keep): Collection
    {
        $table = static fn () => DB::table('release_verification_candidates');
        if ($currentReads || ! $this->ledger->concurrent()) {
            $rows = $table()->where('source_identity', $sourceIdentity)->orderBy('id')->lockForUpdate()->get();
        } else {
            $ids = $table()->where('source_identity', $sourceIdentity)->pluck('id')->all();
            $rows = $ids === [] ? collect() : $table()
                ->when(in_array(DB::getDriverName(), ['mysql', 'mariadb'], true), fn ($query) => $query->forceIndex('PRIMARY'))
                ->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        }

        return $rows->filter(static fn (object $row): bool => $row->source_identity === $sourceIdentity && $keep($row))->sortByDesc('id')->values();
    }

    /**
     * The one holder whose inventory these headers extend without conflict, if exactly one does.
     *
     * @param  list<object>  $partials
     * @param  array<string, mixed>  $manifest
     */
    private function partialOwner(array $partials, array $manifest): ?object
    {
        // Only an unambiguous owner: a repost by the same poster under the same subject and file count shares
        // the hash, and a segment at an unseen part number fits either inventory. Mixing them would publish an
        // unusable NZB, so headers more than one holder could take are kept apart.
        $owners = [];
        foreach ($partials as $partial) {
            $retained = $this->store->read($partial->manifest_path);
            if (($retained['conflict'] ?? false) || ! $this->spoils($retained, $this->merge($retained, $manifest))) {
                $owners[] = $partial;
            }
        }

        return count($owners) === 1 ? $owners[0] : null;
    }

    /**
     * Whether merging made a clean retained inventory unusable: conflicting identities, or header defects that
     * later articles can never repair because merging only adds.
     *
     * @param  array<string, mixed>  $retained
     * @param  array<string, mixed>  $merged
     */
    private function spoils(array $retained, array $merged): bool
    {
        if ($retained['conflict'] ?? false) {
            return false;
        }
        $inventory = new CandidateHeaderInventory;

        return ($merged['conflict'] ?? false) || ($inventory->assessment($merged)['status'] === CandidateHeaderInventory::REJECTED
            && $inventory->assessment($retained)['status'] !== CandidateHeaderInventory::REJECTED);
    }

    /** A published candidate's retained inventory still belongs to a live partial release. */
    private function retainsLivePartial(object $candidate): bool
    {
        return $candidate->release_id !== null && $candidate->manifest_path !== '' && $candidate->status === 'sampled'
            && Release::hasPartialArticleCounts()
            && DB::table('releases')->where('id', $candidate->release_id)->whereNotNull('articles_expected')->sharedLock()->exists();
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

        return self::articleIdentityDigest($ids);
    }

    /** An upload whose missing files or articles have stopped arriving may publish as partial. */
    public function partialAllowed(object $candidate): bool
    {
        $hours = (int) config('release_verification.partial_after_hours', 4);

        return $hours > 0 && ($candidate->release_id ?? null) === null && is_string($candidate->last_unique_at ?? null)
            && $candidate->last_unique_at >= self::owedPartialCutoff()
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
        // Only into idle capacity: a ready backlog is worked first. The verification_due index bounds this count.
        $cap = max(0, (int) config('release_verification.partial_ready_limit', 500));
        $ready = DB::query()->fromSub(DB::table('release_verification_candidates')->where('status', 'ready')->select('id')->limit($cap), 'ready')->count();
        $limit = min($limit, $cap - $ready);
        if ($limit < 1) {
            return 0;
        }
        $now = now();
        $cutoff = $now->copy()->subHours($hours)->format('Y-m-d H:i:s');
        $owedCutoff = self::owedPartialCutoff();

        // The verification_due index serves the status / next_attempt_at order.
        if (DB::transactionLevel() === 0 && in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            // READ COMMITTED for this transaction only: the locking scan of the due range then takes no gap
            // locks, so admissions into the queue never wait behind promotion either.
            DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }

        return DB::transaction(function () use ($limit, $cutoff, $owedCutoff, $now, $hours): int {
            // Skip rows ingestion holds rather than wait on them, and take the next due ones instead, so a busy
            // prefix never hides later stalled uploads. Each row's predicates hold under its lock.
            $query = DB::table('release_verification_candidates')->where('status', 'waiting_upload')
                ->where('next_attempt_at', '>=', $owedCutoff)->where('next_attempt_at', '<=', $now)
                ->where('last_unique_at', '>=', $owedCutoff)->whereNull('release_id')->whereNull('lease_token')
                ->orderBy('next_attempt_at')->orderBy('id')->limit(max(1, $limit));
            $rows = (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) ? $query->lock('for update skip locked') : $query->lockForUpdate())
                ->get(['id', 'last_unique_at', 'reason']);
            $eligible = [];
            foreach ($rows as $row) {
                if (str_starts_with((string) ($row->reason ?? ''), self::PARTIAL_DEFERRED) || $row->last_unique_at < $owedCutoff) {
                    // Already tried and refused by policy, or past the partial-attempt window: only new headers change that.
                    DB::table('release_verification_candidates')->where('id', $row->id)
                        ->update(['next_attempt_at' => $now->copy()->addHours(max(1, (int) config('release_verification.expiry_hours')))->format('Y-m-d H:i:s')]);
                } elseif ($row->last_unique_at < $cutoff) {
                    $eligible[] = (int) $row->id;
                } else {
                    // Still inside the hold window: due again at its end, so each is read about once per window.
                    DB::table('release_verification_candidates')->where('id', $row->id)
                        ->update(['next_attempt_at' => Carbon::parse($row->last_unique_at)->addHours($hours)->format('Y-m-d H:i:s')]);
                }
            }
            if ($eligible === []) {
                return 0;
            }

            return DB::table('release_verification_candidates')->whereIn('id', $eligible)->update(['status' => 'ready',
                'next_attempt_at' => $now->format('Y-m-d H:i:s'),
                'reason' => self::PARTIAL_QUEUED]);
        });
    }

    /** Return a claimed partial upload that fails publication policy to waiting, for one more hold window. */
    public function deferPartial(object $claim, string $reason): void
    {
        DB::table('release_verification_candidates')->where('id', $claim->id)->where('lease_token', $claim->lease_token)
            ->where('version', $claim->version)->whereNull('release_id')->update(['status' => 'waiting_upload',
                'reason' => mb_strcut(self::PARTIAL_DEFERRED.$reason, 0, 2000, 'UTF-8'), 'lease_token' => null, 'lease_until' => null,
                // Its policy outcome depends only on its headers and configuration: retrying it each hold window
                // would spot check it again for the same result. New headers requeue it; expiry ends it.
                'next_attempt_at' => now()->addHours(max(1, (int) config('release_verification.expiry_hours')))]);
    }

    /**
     * A new version with header defects is claimed once; its verifier or the preparer then moves it
     * out of the claim queue instead of retrying it every retry interval.
     */
    private static function admittedStatus(string $status): string
    {
        return $status === CandidateHeaderInventory::REJECTED ? 'unknown' : $status;
    }

    public function capacityAvailable(): bool
    {
        if (! config('release_verification.required', true) || ! Schema::hasTable('release_verification_state')) {
            return true;
        }

        return self::hasPendingCapacity((int) DB::table('release_verification_state')->where('id', 1)->value('pending_bytes'),
            (int) config('release_verification.pending_bytes'));
    }

    /** @return array<string, mixed> */
    private function snapshot(object $collection, bool $currentReads = false): array
    {
        $manifest = $this->snapshotHeaders($collection, $currentReads);
        $manifest['legacy_release_id'] = $this->legacyReleaseId($collection);

        return $manifest;
    }

    /**
     * Header metadata a manifest holds, counted as snapshotHeaders() counts it against the budget.
     *
     * @param  array<string, mixed>  $manifest
     */
    private static function metadataBytes(array $manifest): int
    {
        $bytes = 0;
        foreach ($manifest['files'] as $file) {
            foreach ($file['segments'] as $segment) {
                $bytes += 128 + strlen($segment['messageid']);
            }
        }

        return $bytes;
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
        $budget = OversizedCollectionDeferrals::budget();
        $binaries = $this->snapshotQuery('binaries', $currentReads)->where('collections_id', $collection->id)->orderBy('binaryhash')
            ->select(['id', 'binaryhash', 'name', 'totalparts'])->cursor();
        foreach ($binaries->chunk(100) as $batch) {
            $segmentsByBinary = [];
            foreach ($this->snapshotQuery('parts', $currentReads)->whereIn('binaries_id', $batch->pluck('id')->all())
                ->orderBy('binaries_id')->orderBy('partnumber')
                ->select(['binaries_id', 'partnumber', 'messageid', 'size'])->cursor() as $part) {
                $metadataBytes += 128 + strlen($part->messageid);
                if ($metadataBytes > $budget) {
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
        $this->expireCandidates($mayStart);
        $this->expireRetainedPartials($mayStart);
        $this->settleLateArticleHolders($mayStart);
    }

    /**
     * Page through candidates by primary key, resuming where the last pass stopped so an expired or
     * owed prefix never makes maintenance a full-table scan.
     *
     * @param  (callable(): bool)|null  $mayStart
     */
    private function expireCandidates(?callable $mayStart): void
    {
        $cursorKey = 'release_verification:expiry_cursor';
        try {
            $after = max(0, (int) Cache::get($cursorKey, 0));
        } catch (Throwable) {
            $after = 0;
        }
        $startAfter = $after;
        $wrapped = $after === 0;
        $partials = (int) config('release_verification.partial_after_hours', 4) > 0;
        $expired = 0;
        try {
            for ($page = 0; $page < 10 && $expired < 100; $page++) {
                if ($page > 0 && $mayStart !== null && ! $mayStart()) {
                    return;
                }
                $through = $wrapped && $startAfter > 0 ? $startAfter : null;
                $bounds = $this->expiryPageBounds($after, $through);
                $pageEnd = isset($bounds[0]) ? (int) $bounds[0] : $through;
                $hasMore = isset($bounds[1]);
                foreach ($this->expirableCandidateIds($after, $pageEnd, 100 - $expired, $partials) as $id) {
                    if ($mayStart !== null && ! $mayStart()) {
                        return;
                    }
                    if ($this->expireCandidate((int) $id, $partials) === 'expired') {
                        $expired++;
                    }
                    $after = (int) $id;
                    if ($expired >= 100) {
                        return;
                    }
                }
                if ($hasMore && $pageEnd !== null) {
                    $after = $pageEnd;

                    continue;
                }
                $after = 0;
                if ($wrapped) {
                    return;
                }
                $wrapped = true;
            }
        } finally {
            try {
                Cache::put($cursorKey, $after, now()->addDay());
            } catch (Throwable) {
                // The next pass starts over.
            }
        }
    }

    /** @return list<int|string> */
    private function expiryPageBounds(int $after, ?int $through = null): array
    {
        return DB::table('release_verification_candidates')
            ->when(in_array(DB::getDriverName(), ['mysql', 'mariadb'], true), fn ($query) => $query->forceIndex('PRIMARY'))
            ->where('id', '>', $after)
            ->when($through !== null, fn ($query) => $query->where('id', '<=', $through))
            ->orderBy('id')
            ->offset(999)
            ->limit(2)
            ->pluck('id')
            ->all();
    }

    /** @return Collection<int, int|string> */
    private function expirableCandidateIds(int $after, ?int $through, int $limit, bool $partials): Collection
    {
        return DB::table('release_verification_candidates')
            ->when(in_array(DB::getDriverName(), ['mysql', 'mariadb'], true), fn ($query) => $query->forceIndex('PRIMARY'))
            ->where('id', '>', $after)
            ->when($through !== null, fn ($query) => $query->where('id', '<=', $through))
            ->whereNull('release_id')
            ->whereIn('status', self::EXPIRABLE_STATUSES)
            ->where('last_unique_at', '<', now()->subHours((int) config('release_verification.expiry_hours')))
            ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<', now()))
            // A stalled upload still owed its partial attempt is published, not expired (it would otherwise lose
            // the race with promotion, and always lose it when the hold reaches the expiry window).
            ->when($partials, fn ($query) => $query->where(fn ($owed) => $owed->where('last_unique_at', '<', self::owedPartialCutoff())
                ->orWhereNotIn('status', ['waiting_upload', 'ready'])
                ->orWhere(fn ($waiting) => $waiting->where('status', 'waiting_upload')->where('reason', 'like', self::PARTIAL_DEFERRED.'%'))
                ->orWhere(fn ($ready) => $ready->where('status', 'ready')->where(fn ($r) => $r->whereNull('reason')->orWhere('reason', '!=', self::PARTIAL_QUEUED)))))
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->pluck('id');
    }

    /** @return 'expired'|'retained'|'terminal' */
    private function expireCandidate(int $id, bool $partials): string
    {
        return DB::transaction(function () use ($id, $partials): string {
            $this->ledger->enter();
            $row = DB::table('release_verification_candidates')->where('id', $id)->lockForUpdate()->first();
            if ($row === null || $row->release_id !== null || ! in_array($row->status, self::EXPIRABLE_STATUSES, true)) {
                return 'terminal';
            }
            if ($row->last_unique_at >= now()->subHours((int) config('release_verification.expiry_hours'))->format('Y-m-d H:i:s')
                || ($row->lease_until !== null && $row->lease_until >= now()->format('Y-m-d H:i:s'))
                || ($partials && self::owedPartialAttempt($row))) {
                return 'retained';
            }
            DB::table('release_verification_candidates')->where('id', $id)->update(['status' => in_array($row->status, ['metadata_deferred', 'metadata_review'], true) ? 'metadata_expired' : 'expired',
                'version' => (int) $row->version + 1,
                'manifest_path' => '', 'manifest_bytes' => 0, 'lease_token' => null, 'lease_until' => null]
                + (self::tracksUpgrades() ? ['upgrades_release_id' => null] : []));
            if (($row->upgrades_release_id ?? null) !== null && Release::hasLateArticleFlag()) {
                // An expired generation can no longer upgrade its partial; workers may now process it.
                $this->releaseLateArticleFence([(int) $row->upgrades_release_id], $id);
            }
            $this->ledger->adjust(-(int) $row->manifest_bytes);
            DB::afterCommit(function () use ($row): void {
                $this->store->deleteIfUnreferenced($row->manifest_path);
            });

            return 'expired';
        }, 5);
    }

    /**
     * Release these partials' fence held by $candidateId, except where an upgrade's staged NZB still waits beside
     * the canonical file: those stay fenced until settleLateArticleHolders() installs it, so NZB-reading workers
     * never take the older generation for the upgraded release.
     *
     * @param  list<int>  $releaseIds
     * @param  int|null  $candidateId  null releases whichever candidate holds the fence
     */
    public function releaseLateArticleFence(array $releaseIds, ?int $candidateId): void
    {
        if ($releaseIds === []) {
            return;
        }
        $guard = app(NzbSourceGuard::class);
        $free = DB::table('releases')->whereIn('id', $releaseIds)->pluck('guid', 'id')
            ->filter(static fn ($guid): bool => ! is_string($guid) || ! $guard->hasStagedGenerations($guid))->keys()->all();
        if ($free !== []) {
            // By primary key: an update through the pointer index would gap-lock it like a locking read.
            DB::table('releases')->whereIn('id', $free)->when($candidateId !== null, static fn ($query) => $query->where('late_article_candidate_id', $candidateId))
                ->update(['late_article_candidate_id' => null]);
        }
    }

    /**
     * Clear a release's late-article candidate once that candidate can no longer upgrade it: it ended
     * unpublished (expired, filtered) or published elsewhere. The pointer only ever clears.
     */
    /** @param  (callable(): bool)|null  $mayStart */
    private function settleLateArticleHolders(?callable $mayStart = null): void
    {
        if (! Release::hasLateArticleFlag()) {
            return;
        }
        // A bounded page of the late_article_candidate_id index per pass (index-only: the pointer and the
        // release id), resuming where the last pass stopped, so live partials never make it a table scan.
        $cursorKey = 'release_verification:settle_cursor';
        try {
            $after = (int) Cache::get($cursorKey, 0);
        } catch (Throwable) {
            $after = 0;
        }
        $held = DB::table('releases')
            ->when(in_array(DB::getDriverName(), ['mysql', 'mariadb'], true), fn ($query) => $query->forceIndex('releases_late_article_candidate_id_index'))
            ->where('late_article_candidate_id', '>', $after)->orderBy('late_article_candidate_id')->limit(1000)
            ->get(['id', 'late_article_candidate_id']);
        if ($held->isEmpty()) {
            $this->putSettleCursor($cursorKey, 0);

            return;
        }
        $candidates = DB::table('release_verification_candidates')->whereIn('id', $held->pluck('late_article_candidate_id')->unique()->all())
            ->get(['id', 'release_id', 'manifest_path', 'status'])->keyBy('id');
        $guids = DB::table('releases')->whereIn('id', $held->pluck('id')->all())->pluck('guid', 'id');
        $guard = app(NzbSourceGuard::class);
        $checkpoint = $after;
        foreach ($held as $release) {
            if ($mayStart !== null && ! $mayStart()) {
                // Past the maintenance deadline: the next pass resumes after the last row settled here.
                $this->putSettleCursor($cursorKey, $checkpoint);

                return;
            }
            $candidate = $candidates->get((int) $release->late_article_candidate_id);
            // Settled once its holder ended unpublished, was published elsewhere, or released its inventory.
            $settled = $candidate === null
                || ($candidate->release_id !== null && ((int) $candidate->release_id !== (int) $release->id || $candidate->manifest_path === ''))
                || ($candidate->release_id === null && in_array($candidate->status, ['expired', 'filtered', 'metadata_expired'], true));
            // A committed upgrade's staged NZB is installed whether or not its holder still waits for late articles,
            // so readers of the canonical file see the generation the row describes; the fence clears only after.
            $guid = $guids->get($release->id);
            if (is_string($guid) && $guard->hasStagedGenerations($guid)) {
                try {
                    $settled = $guard->settleStagedGenerations($guid) && $settled;
                } catch (Throwable) {
                    $settled = false;
                }
            }
            if ($settled) {
                DB::table('releases')->where('id', $release->id)->where('late_article_candidate_id', $release->late_article_candidate_id)
                    ->update(['late_article_candidate_id' => null]);
            }
            $checkpoint = (int) $release->late_article_candidate_id;
        }
        $this->putSettleCursor($cursorKey, $held->count() < 1000 ? 0 : $checkpoint);
    }

    private function putSettleCursor(string $cursorKey, int $cursor): void
    {
        try {
            Cache::put($cursorKey, $cursor, now()->addDay());
        } catch (Throwable) {
            // The next pass starts over.
        }
    }

    /**
     * Release the inventory a partial publication kept for late articles once none arrive within the expiry window.
     *
     * @param  (callable(): bool)|null  $mayStart
     */
    private function expireRetainedPartials(?callable $mayStart): void
    {
        $cutoff = now()->subHours((int) config('release_verification.expiry_hours'))->format('Y-m-d H:i:s');
        // Page in expiry order, resuming where the last pass stopped: rows held back by late headers awaiting
        // archive must not starve the ones behind them, however many there are.
        $cursorKey = 'release_verification:retained_expiry_cursor';
        try {
            $stored = Cache::get($cursorKey);
        } catch (Throwable) {
            $stored = null;
        }
        $after = is_array($stored) && count($stored) === 2 ? [(string) $stored[0], (int) $stored[1]] : null;
        $released = 0;
        try {
            for ($page = 0; $page < 10 && $released < 100; $page++) {
                $rows = $this->retainedPartials($cutoff, $after);
                if ($rows->isEmpty()) {
                    // The end: the next pass starts over from the oldest.
                    $after = null;

                    return;
                }
                foreach ($rows as $row) {
                    if ($mayStart !== null && ! $mayStart()) {
                        return;
                    }
                    $released += $this->expireRetainedPartial((int) $row->id, $row->source_identity, $cutoff) ? 1 : 0;
                    $after = [(string) $row->retained_at, (int) $row->id];
                }
            }
        } finally {
            try {
                Cache::put($cursorKey, $after, now()->addDay());
            } catch (Throwable) {
                // The next pass starts over.
            }
        }
    }

    /**
     * Published candidates still retaining a partial's inventory with no new article since the cutoff, in
     * expiry order after the given position. retained_last_unique_at is set only on retaining rows, so its
     * index reads just the expirable ones, including those whose release was deleted meanwhile.
     *
     * @param  array{string, int}|null  $after
     * @return Collection<int, \stdClass>
     */
    private function retainedPartials(string $cutoff, ?array $after): Collection
    {
        if (! self::tracksRetention()) {
            return DB::table('release_verification_candidates')->whereNotNull('release_id')->where('manifest_path', '<>', '')
                ->where('last_unique_at', '<', $cutoff)->where('id', '>', $after[1] ?? 0)->orderBy('id')->limit(100)
                ->get(['id', 'source_identity', 'id as retained_at']);
        }

        return DB::table('release_verification_candidates')
            ->when(in_array(DB::getDriverName(), ['mysql', 'mariadb'], true), fn ($query) => $query->forceIndex('verification_retained_expiry'))
            ->where('retained_last_unique_at', '<', $cutoff)
            ->when($after !== null, fn ($query) => $query->where(fn ($position) => $position->where('retained_last_unique_at', '>', $after[0])
                ->orWhere(fn ($tie) => $tie->where('retained_last_unique_at', $after[0])->where('id', '>', $after[1]))))
            ->whereNotNull('release_id')->where('manifest_path', '<>', '')
            ->orderBy('retained_last_unique_at')->orderBy('id')->limit(100)
            ->get(['id', 'source_identity', 'retained_last_unique_at as retained_at']);
    }

    /** Release one retained inventory unless its upload has late headers still waiting to be archived. */
    private function expireRetainedPartial(int $id, mixed $sourceIdentity, string $cutoff): bool
    {
        return DB::transaction(function () use ($id, $sourceIdentity, $cutoff): bool {
            // Serialize with header storage and archive(), in their order (the upload's collections, the
            // ledger, then the candidate): late headers still waiting to be archived keep the inventory. A
            // collection added a whole expiry window ago and still unarchived never will be (archive() cannot
            // take it, as when it exceeds the metadata budget), so it no longer holds the inventory back.
            $hash = is_string($sourceIdentity) && ctype_xdigit($sourceIdentity) ? hex2bin($sourceIdentity) : false;
            if ($hash !== false && DB::table('collections')->where('collectionhash', $hash)
                ->when(self::tracksCollectionAge(), fn ($query) => $query->where('dateadded', '>=', $cutoff))->lockForUpdate()->exists()) {
                return false;
            }
            $this->ledger->enter();
            $row = DB::table('release_verification_candidates')->where('id', $id)->lockForUpdate()->first();
            if ($row === null || $row->release_id === null || $row->manifest_path === '' || $row->last_unique_at >= $cutoff) {
                return false;
            }
            DB::table('release_verification_candidates')->where('id', $id)->update(['manifest_path' => '', 'manifest_bytes' => 0]
                + (self::tracksRetention() ? ['retained_for_release_id' => null, 'retained_last_unique_at' => null] : []));
            if (Release::hasLateArticleFlag()) {
                // No late articles can merge any more: workers that read NZBs may now process it.
                $this->releaseLateArticleFence([(int) $row->release_id], (int) $id);
            }
            $this->ledger->adjust(-(int) $row->manifest_bytes);
            DB::afterCommit(function () use ($row): void {
                $this->store->deleteIfUnreferenced($row->manifest_path);
            });

            return true;
        }, 5);
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

    /** Return an admitted candidate that never began verification, without changing a newer generation. */
    public function returnQueuedClaim(object $claim): bool
    {
        return DB::table('release_verification_candidates')->where('id', $claim->id)->where('status', 'verifying')
            ->where('lease_token', $claim->lease_token)->where('version', $claim->version)->whereNull('release_id')
            ->update(['status' => $claim->status === 'verifying' ? 'pending' : $claim->status,
                'lease_token' => null, 'lease_until' => null]) === 1;
    }

    public function heartbeat(int $id, string $token): void
    {
        $changed = DB::table('release_verification_candidates')->where('id', $id)->where('lease_token', $token)
            ->where('lease_until', '>=', now())->update(['lease_until' => now()->addSeconds((int) config('release_verification.lease_seconds'))]);
        if ($changed !== 1 && ! DB::table('release_verification_candidates')->where('id', $id)->where('lease_token', $token)->where('lease_until', '>=', now())->exists()) {
            throw new LeaseLostException('Verification lease was lost.');
        }
    }

    public function finish(object $claim, VerificationResult $result): void
    {
        DB::table('release_verification_candidates')->where('id', $claim->id)->where('lease_token', $claim->lease_token)
            ->where('version', $claim->version)->update(['status' => $result->waitingForHeaders ? 'waiting_upload'
                : ($result->headerRejected ? CandidateHeaderInventory::REJECTED : ($result->verdict === 'unrepairable' ? 'unrepairable' : 'unknown')), 'reason' => mb_strcut($result->reason, 0, 2000, 'UTF-8'),
                'lease_token' => null, 'lease_until' => null, 'header_check_pending' => false,
                // A deterministic failure only changes with new headers, which requeue it at once.
                'next_attempt_at' => now()->addSeconds((int) config($result->deterministic
                    ? 'release_verification.deterministic_retry_seconds' : 'release_verification.retry_seconds'))]);
    }

    public function rejectByPolicy(object $claim, string $reason, ?PublicationTiming $timing = null): void
    {
        $this->retire($claim, $reason, 'filtered', $timing);
    }

    /**
     * End an upgrade generation whose partial was deleted or no longer awaits it. It is not filtered: a filtered
     * newest candidate would make archive() discard later uploads under the same hash as replays.
     */
    public function abandonUpgrade(object $claim, string $reason, ?PublicationTiming $timing = null): void
    {
        $this->retire($claim, $reason, 'expired', $timing);
    }

    private function retire(object $claim, string $reason, string $status, ?PublicationTiming $timing): void
    {
        DB::transaction(function () use ($claim, $reason, $status, $timing): void {
            $this->ledger->enter();
            $candidate = DB::table('release_verification_candidates')->where('id', $claim->id)
                ->where('lease_token', $claim->lease_token)->where('version', $claim->version)->whereNull('release_id')->lockForUpdate()->first();
            if ($candidate === null) {
                throw new RuntimeException('Stale verification result cannot filter a candidate.');
            }
            DB::table('release_verification_candidates')->where('id', $claim->id)->update(['status' => $status,
                'reason' => mb_strcut($reason, 0, 2000, 'UTF-8'), 'lease_token' => null, 'lease_until' => null,
                'manifest_path' => '', 'manifest_bytes' => 0] + (self::tracksUpgrades() ? ['upgrades_release_id' => null] : []));
            if (($candidate->upgrades_release_id ?? null) !== null && Release::hasLateArticleFlag()) {
                // A retired generation can no longer upgrade its partial: workers may process it now.
                $this->releaseLateArticleFence([(int) $candidate->upgrades_release_id], (int) $candidate->id);
            }
            $previous = $timing?->enterNestedPhase('ledger_adjust');
            $this->ledger->adjust(-(int) $candidate->manifest_bytes);
            $timing?->nextPhase((string) $previous);
            DB::afterCommit(function () use ($candidate): void {
                $this->store->deleteIfUnreferenced($candidate->manifest_path);
            });
        }, 5);
    }

    /** Wave 49.2 paired benchmarks decide this default; see RustVerificationAdmissionInventoryPrepRepoSampleW49ParityTest. */
    private const W49_RUST_FFI_DEFAULT = true;

    public const string W49_FFI_CDEF = <<<'C'
        intptr_t nntmux_w49_candidate_repository_partial_batch(const char *lasts, size_t lasts_len, const char *statuses, size_t statuses_len, const char *reasons, size_t reasons_len, size_t count, const char *owed, size_t owed_len, const char *hold, size_t hold_len, int64_t *out, size_t out_cap);
        C;

    private static ?bool $w49RustFfi = null;

    private static FFI|false|null $w49Ffi = null;

    private static ?CData $w49Out = null;

    private static int $w49OutCap = 0;

    public static function usesW49RustFfi(): bool
    {
        return self::$w49RustFfi ?? self::W49_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with classifyPartialPromotions() routed to Rust ($enabled) or PHP, then restores the previous
     * routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW49RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$w49RustFfi;
        self::$w49RustFfi = $enabled;
        try {
            return $callback();
        } finally {
            self::$w49RustFfi = $previous;
        }
    }

    /**
     * Batch classification of waiting candidates for promotePartial() and expiry. One word per
     * row: `action (0 deferred to expiry: reason starts with the deferred prefix or last unique
     * article older than $owedCutoff, 1 eligible: older than $holdCutoff, 2 still held) | 4
     * isOwedPartialAttemptPhp() | 8 deferred prefix | 16 queued reason | 32 last >= $owedCutoff |
     * 64 last < $holdCutoff | 128 waiting_upload | 256 ready`, compared with PHP's operators
     * (null as ''). Then eligible, deferred, held and owed counts and the XXH64 digest of all
     * earlier words.
     *
     * @param  list<string|null>  $lastUniqueAts
     * @param  list<string>  $statuses
     * @param  list<string|null>  $reasons
     * @return list<int>
     */
    public static function classifyPartialPromotions(array $lastUniqueAts, array $statuses, array $reasons, string $owedCutoff, string $holdCutoff): array
    {
        return self::usesW49RustFfi()
            ? self::classifyPartialPromotionsNative($lastUniqueAts, $statuses, $reasons, $owedCutoff, $holdCutoff)
            : self::classifyPartialPromotionsPhp($lastUniqueAts, $statuses, $reasons, $owedCutoff, $holdCutoff);
    }

    /**
     * @param  list<string|null>  $lastUniqueAts
     * @param  list<string>  $statuses
     * @param  list<string|null>  $reasons
     * @return list<int>
     */
    public static function classifyPartialPromotionsPhp(array $lastUniqueAts, array $statuses, array $reasons, string $owedCutoff, string $holdCutoff): array
    {
        $out = [];
        $eligible = $deferred = $held = $owed = 0;
        foreach ($lastUniqueAts as $i => $last) {
            $status = $statuses[$i] ?? '';
            $reason = $reasons[$i] ?? null;
            $isDeferred = str_starts_with((string) ($reason ?? ''), self::PARTIAL_DEFERRED);
            $queued = $reason === self::PARTIAL_QUEUED;
            $geOwed = $last >= $owedCutoff;
            $ltHold = $last < $holdCutoff;
            $waiting = $status === 'waiting_upload';
            $ready = $status === 'ready';
            $owes = $geOwed && (($waiting && ! $isDeferred) || ($ready && $queued));
            if ($isDeferred || ! $geOwed) {
                $action = 0;
                $deferred++;
            } elseif ($ltHold) {
                $action = 1;
                $eligible++;
            } else {
                $action = 2;
                $held++;
            }
            $owed += (int) $owes;
            $out[] = $action | (int) $owes << 2 | (int) $isDeferred << 3 | (int) $queued << 4 | (int) $geOwed << 5
                | (int) $ltHold << 6 | (int) $waiting << 7 | (int) $ready << 8;
        }
        $out[] = $eligible;
        $out[] = $deferred;
        $out[] = $held;
        $out[] = $owed;

        return self::w49Digest($out);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, the lists differ in length, a
     * value holds a NUL byte or a comparison may be numeric.
     *
     * @param  list<string|null>  $lastUniqueAts
     * @param  list<string>  $statuses
     * @param  list<string|null>  $reasons
     * @return list<int>
     */
    public static function classifyPartialPromotionsNative(array $lastUniqueAts, array $statuses, array $reasons, string $owedCutoff, string $holdCutoff): array
    {
        $count = \count($lastUniqueAts);
        $ffi = $count === 0 ? null : (self::$w49Ffi instanceof FFI ? self::$w49Ffi : self::w49Ffi());
        if ($ffi === null || \count($statuses) !== $count || \count($reasons) !== $count) {
            return self::classifyPartialPromotionsPhp($lastUniqueAts, $statuses, $reasons, $owedCutoff, $holdCutoff);
        }
        $joinedLasts = implode("\0", $lastUniqueAts);
        $joinedStatuses = implode("\0", $statuses);
        $joinedReasons = implode("\0", $reasons);
        $words = $count + 5;
        $values = false;
        // Rust rejects a column whose NUL count differs from $count - 1 (an embedded NUL byte).
        try {
            $out = self::w49Out($ffi, $words);
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w49_candidate_repository_partial_batch($joinedLasts, \strlen($joinedLasts), $joinedStatuses, \strlen($joinedStatuses), $joinedReasons, \strlen($joinedReasons), $count, $owedCutoff, \strlen($owedCutoff), $holdCutoff, \strlen($holdCutoff), $out, self::$w49OutCap);
            $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;
        } catch (Throwable) {
            $values = false;
        }

        /** @var list<int>|null $result */
        $result = \is_array($values) ? array_values($values) : null;

        return $result ?? self::classifyPartialPromotionsPhp($lastUniqueAts, $statuses, $reasons, $owedCutoff, $holdCutoff);
    }

    private static function w49Ffi(): ?FFI
    {
        self::$w49Ffi ??= RustPipelineLibrary::resolveDomain('nfo_w49', self::W49_FFI_CDEF) ?? false;

        return self::$w49Ffi === false ? null : self::$w49Ffi;
    }

    /** A reusable output buffer of at least $words `int64_t` words; FFI::new() throws on failure. */
    private static function w49Out(FFI $ffi, int $words): CData
    {
        if (self::$w49Out === null || self::$w49OutCap < $words) {
            self::$w49Out = $ffi->new('int64_t['.max(1024, $words).']');
            self::$w49OutCap = max(1024, $words);
        }

        return self::$w49Out;
    }

    /**
     * Appends the XXH64 digest of the words.
     *
     * @param  list<int>  $words
     * @return list<int>
     */
    private static function w49Digest(array $words): array
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$words), true));
        $words[] = $digest[1];

        return $words;
    }

    // ========================================================================
    // WAVE 63.4 BATCH HELPER
    // ========================================================================

    /** Disabled: the minimum of three 1000-round paired runs reached 1.55x common p99 but only 1.47x common mean, 1.27x uncommon mean and 1.05x uncommon p99, so PHP stays the default; see RustVerificationAdmissionInventoryPrepRepoSampleBatchW63ParityTest. */
    public const bool W63_RUST_FFI_DEFAULT = false;

    private const string W63_FFI_CDEF = 'ssize_t nntmux_w634_candidate_repository_plan_batch(const char *blob, size_t blob_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);';

    /** int64 words per w63CandidateRepoPlanBatch() record. */
    public const int W63_ROW_WORDS = 5;

    /** int64 summary words after the w63CandidateRepoPlanBatch() records (none for an empty batch). */
    public const int W63_TRAILER_WORDS = 9;

    private const array W63_STATUS_CODES = ['ready' => 1, 'pending' => 2, 'unknown' => 3, 'verifying' => 4, 'waiting_upload' => 5, 'header_rejected' => 6,
        'unrepairable' => 7, 'dirty' => 8, 'metadata_deferred' => 9, 'metadata_review' => 10, 'filtered' => 11, 'expired' => 12];

    private const int W63_TIME_MAX = 1 << 40;

    private const int W63_CLAIM_WINDOW = 100;

    /**
     * Claim, lease and retry plan rows for [status, lease token, reason, id, version, next attempt
     * at, lease until, now, release id, lease seconds, retry seconds, deterministic retry seconds,
     * deterministic] items (times in epoch seconds; a lease until <= 0 is no lease; now clamped
     * to 0..2^40, lease seconds to 1..86400, retry seconds to 0..31536000).
     *
     * Status codes: claimable 1 ready, 2 pending, 3 unknown, 4 verifying; then 5 waiting_upload,
     * 6 header_rejected, 7 unrepairable, 8 dirty, 9 metadata_deferred, 10 metadata_review, 11
     * filtered, 12 expired; 0 other. Lease tokens are 0 empty, 1 UUID, 2 other. A row is claimable
     * like claimableCandidates(): unpublished, claimable status, due and its lease free, and its id
     * not seen earlier (claimQueueIds() keeps one slot per id). Action: 1 claim (lease until now +
     * lease seconds), 2 renew a held verifying lease with a UUID token (same lease), 3 return a
     * stale verifying lease (lease 0), 4 published, 5 wait until due, 6 ineligible (lease -1 for
     * 4-6). Claimable rows are ranked by (next attempt at, id); the first 100 form the claim
     * window. The finish() retry is now + (deterministic ? deterministic retry : retry) seconds.
     *
     * Rows [action | status << 4 | token class << 8 | flags << 12 (1 due, 2 lease free, 4
     * published, 8 duplicate id, 16 in the claim window, 32 version < 1, 64 ready queue, 128
     * partial publication deferred reason), lease until, retry at, rank (-1), seconds until due (0
     * when due)], then the trailer [claim, renew, stale, published, wait and ineligible counts,
     * claim window size, earliest next attempt of a waiting row (-1), FNV-1a digest].
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: int, 4: int, 5: int, 6: int, 7: int, 8: int, 9: int, 10: int, 11: int, 12: int}>  $items
     * @return list<int>
     */
    public static function w63CandidateRepoPlanBatch(array $items): array
    {
        return self::usesW63RustFfi() ? self::w63CandidateRepoPlanBatchNative($items) : self::w63CandidateRepoPlanBatchPhp($items);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: int, 4: int, 5: int, 6: int, 7: int, 8: int, 9: int, 10: int, 11: int, 12: int}>  $items
     * @return list<int>
     */
    public static function w63CandidateRepoPlanBatchPhp(array $items): array
    {
        self::w63Items($items);
        if ($items === []) {
            return [];
        }
        $out = $ids = $nexts = $windowIds = $positions = [];
        $summary = [0, 0, 0, 0, 0, 0, 0, -1, 0];
        foreach ($items as $i => [$status, $leaseToken, $reason, $id, $version, $next, $lease, $now, $releaseId, $leaseSeconds, $retrySeconds, $deterministicSeconds, $deterministic]) {
            $code = self::W63_STATUS_CODES[$status] ?? 0;
            if ($leaseToken === '') {
                $token = 0;
            } else {
                $token = \strlen($leaseToken) === 36 && $leaseToken[8] === '-' && $leaseToken[13] === '-' && $leaseToken[18] === '-' && $leaseToken[23] === '-'
                    && strspn(str_replace('-', '', $leaseToken), '0123456789abcdefABCDEF') === 32 ? 1 : 2;
            }
            $now = max(0, min(self::W63_TIME_MAX, $now));
            $released = $releaseId > 0;
            $duplicate = isset($ids[$id]);
            $ids[$id] = true;
            $due = $next <= $now;
            $free = $lease <= 0 || $lease < $now;
            $claimable = ! $released && $code >= 1 && $code <= 4 && $due && $free && ! $duplicate;
            $leaseFor = max(1, min(86_400, $leaseSeconds));
            if ($claimable) {
                [$action, $leaseOut] = [1, $now + $leaseFor];
            } elseif ($released) {
                [$action, $leaseOut] = [4, -1];
            } elseif ($code === 4 && ! $free && $token === 1) {
                [$action, $leaseOut] = [2, $now + $leaseFor];
            } elseif ($code === 4 && $free) {
                [$action, $leaseOut] = [3, 0];
            } elseif (! $due) {
                [$action, $leaseOut] = [5, -1];
            } else {
                [$action, $leaseOut] = [6, -1];
            }
            $retryAt = $now + max(0, min(31_536_000, $deterministic !== 0 ? $deterministicSeconds : $retrySeconds));
            $flags = ($due ? 1 : 0) | ($free ? 2 : 0) | ($released ? 4 : 0) | ($duplicate ? 8 : 0) | ($version < 1 ? 32 : 0) | ($code === 1 ? 64 : 0)
                | (str_starts_with($reason, self::PARTIAL_DEFERRED) ? 128 : 0);
            array_push($out, $action | $code << 4 | $token << 8 | $flags << 12, $leaseOut, $retryAt, -1, $due ? 0 : min($next - $now, self::W63_TIME_MAX));
            $summary[$action - 1]++;
            if ($action === 5 && ($summary[7] < 0 || $next < $summary[7])) {
                $summary[7] = $next;
            }
            if ($claimable) {
                $nexts[] = $next;
                $windowIds[] = $id;
                $positions[] = $i;
            }
        }
        if ($positions !== []) {
            array_multisort($nexts, SORT_ASC, SORT_REGULAR, $windowIds, SORT_ASC, SORT_REGULAR, $positions);
            foreach ($positions as $rank => $i) {
                $out[5 * $i + 3] = $rank;
                if ($rank < self::W63_CLAIM_WINDOW) {
                    $out[5 * $i] |= 16 << 12;
                }
            }
        }
        $summary[6] = min(\count($positions), self::W63_CLAIM_WINDOW);
        $digest = 2_166_136_261;
        for ($k = 0, $words = \count($out); $k < $words; $k += 5) {
            $value = $out[$k] ^ $out[$k + 1] << 8 ^ $out[$k + 3];
            $digest = (($digest ^ (($value ^ ($value >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
        }
        $summary[8] = $digest;

        return [...$out, ...$summary];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: int, 4: int, 5: int, 6: int, 7: int, 8: int, 9: int, 10: int, 11: int, 12: int}>  $items
     * @return list<int>
     */
    public static function w63CandidateRepoPlanBatchNative(array $items): array
    {
        self::w63Items($items);
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        [$blob, $twin] = self::w63Join([array_column($items, 0), array_column($items, 1), array_column($items, 2)], $count);
        $ints = pack('P*', ...array_column($items, 3), ...array_column($items, 4), ...array_column($items, 5), ...array_column($items, 6), ...array_column($items, 7),
            ...array_column($items, 8), ...array_column($items, 9), ...array_column($items, 10), ...array_column($items, 11), ...array_column($items, 12));

        return self::w63Call($blob, $twin, $ints, $count, $count * self::W63_ROW_WORDS + self::W63_TRAILER_WORDS)
            ?? self::w63CandidateRepoPlanBatchPhp($items);
    }

    /** @param array<array-key, mixed> $items */
    private static function w63Items(array $items): void
    {
        if (! array_is_list($items)) {
            throw new InvalidArgumentException('W63 candidate repository plan batch items must be a list.');
        }
        foreach ($items as $item) {
            if (! \is_array($item) || \count($item) !== 13 || ! array_is_list($item) || ! \is_string($item[0]) || ! \is_string($item[1]) || ! \is_string($item[2]) || ! \is_int($item[3]) || ! \is_int($item[4]) || ! \is_int($item[5]) || ! \is_int($item[6]) || ! \is_int($item[7]) || ! \is_int($item[8]) || ! \is_int($item[9]) || ! \is_int($item[10]) || ! \is_int($item[11]) || ! \is_int($item[12])) {
                throw new InvalidArgumentException('W63 candidate repository plan batch items must be [string status, string lease token, string reason, int id, int version, int next attempt at, int lease until, int now, int release id, int lease seconds, int retry seconds, int deterministic retry seconds, int deterministic] lists.');
            }
        }
    }

    public static function usesW63RustFfi(): bool
    {
        $enabled = self::w63RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W63_RUST_FFI_DEFAULT;
    }

    public static function enableW63RustFfi(): void
    {
        self::w63RustFfiState('enabled', true);
    }

    public static function disableW63RustFfi(): void
    {
        self::w63RustFfiState('enabled', false);
    }

    /** Drop the W63 override, binding, output buffer and declined-call count; routing returns to W63_RUST_FFI_DEFAULT. */
    public static function resetW63RustFfi(): void
    {
        self::w63RustFfiState('reset');
    }

    /**
     * Run $callback with the W63 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW63RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w63RustFfiState('enabled');
        self::w63RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w63RustFfiState('enabled', $previous);
        }
    }

    /**
     * String columns NUL-joined field-major and, when a value holds a NUL, the same columns
     * joined by 0x01 so Rust finds the real separators.
     *
     * @param  list<list<string>>  $columns
     * @return array{0: string, 1: string}
     */
    private static function w63Join(array $columns, int $count): array
    {
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = implode("\0", $column);
        }
        $joined = implode("\0", $parts);
        if (substr_count($joined, "\0") === \count($columns) * $count - 1) {
            return [$joined, ''];
        }
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = implode("\x01", $column);
        }

        return [$joined, implode("\x01", $parts)];
    }

    /**
     * One FFI crossing for the batch: $need int64 words back. Null when the library is missing
     * or declines a malformed frame, so the caller falls back to PHP; a declined call with a
     * resolved library is counted in the 'misses' state.
     *
     * @return list<int>|null
     */
    private static function w63Call(string $blob, string $twin, string $ints, int $count, int $need): ?array
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        $state = self::w63RustFfiState();
        $ffi = $state['ffi'];
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata_w63', self::W63_FFI_CDEF) ?? false;
            self::w63RustFfiState('ffi', $ffi);
        }
        if (! $ffi instanceof FFI) {
            return null;
        }
        try {
            $out = $state['out'];
            $cap = $state['cap'];
            if (! $out instanceof CData || $cap < $need) {
                $cap = max(4096, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w63RustFfiState('out', $out);
                self::w63RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W63_FFI_CDEF.)
            $written = $ffi->nntmux_w634_candidate_repository_plan_batch($blob, \strlen($blob), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $cap);
            $values = $written === $need ? unpack('P*', FFI::string($out, $need * 8)) : false;
            if ($values !== false) {
                return array_values($values);
            }
        } catch (Throwable) {
        }
        self::w63RustFfiState('misses', $state['misses'] + 1);

        return null;
    }

    /**
     * W63 routing override, cached binding, reusable output buffer and declined-call count, kept
     * in a function static so the class declares no new property: no arguments returns the state,
     * one argument reads a key ('reset' clears everything) and two arguments write a key.
     */
    private static function w63RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;

            return null;
        }

        return $state[$op] ?? null;
    }
}
