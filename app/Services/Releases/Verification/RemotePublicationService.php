<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Publishes one remote worker's spot-checked candidate on the host holding NZB storage. The
 * worker's lease token must still own the candidate; the publisher revalidates it under lock.
 * The single and the batch endpoints share this per-item logic, so a candidate publishes the
 * same way whichever carried it.
 */
final class RemotePublicationService
{
    public const string STALE_MESSAGE = 'Stale verification result cannot publish.';

    /** Version of the batch request and response; a mismatch makes the worker fall back to single requests. */
    public const int BATCH_PROTOCOL = 1;

    /** No configuration raises the item count above this. */
    public const int BATCH_HARD_MAX_ITEMS = 64;

    /** Seconds past the batch budget that one started item may still run, for set_time_limit(). */
    public const int BATCH_TIME_MARGIN = 60;

    public const string PAUSED_MESSAGE = 'Verification is paused for exclusive maintenance; retry later.';

    /** @param (\Closure(): float)|null $clock Seconds on a monotonic clock; tests inject one, production uses hrtime(). */
    public function __construct(private readonly VerifiedReleasePublisher $publisher, private readonly ManifestStore $store,
        private readonly ?\Closure $clock = null) {}

    /**
     * The validation rules for one publication, as the single endpoint's request and each batch item.
     *
     * @return array<string, list<string>>
     */
    public static function itemRules(): array
    {
        return [
            'claim.id' => ['required', 'integer', 'min:1'],
            'claim.version' => ['required', 'integer', 'min:0'],
            'claim.manifest_hash' => ['required', 'string', 'size:64'],
            'claim.lease_token' => ['required', 'string', 'max:64'],
            'reason' => ['required', 'string', 'max:2000'],
            'evidence.articles' => ['required', 'integer', 'min:1', 'max:16'],
            'evidence.bytes' => ['required', 'integer', 'min:1', 'max:1048576'],
            'evidence.sampled_files' => ['required', 'integer', 'min:1', 'max:16'],
            'evidence.verified_file_names' => ['present', 'array', 'max:16'],
            'evidence.verified_file_names.*' => ['string', 'max:1024'],
            'evidence.identity_hints' => ['present', 'array', 'max:16'],
            'evidence.identity_hints.*' => ['string', 'max:1024'],
            'evidence.partial' => ['sometimes', 'boolean'],
            'evidence.parity_file_keys' => ['sometimes', 'array', 'max:16'],
            'evidence.parity_file_keys.*' => ['string', 'max:1024'],
        ];
    }

    /** @return array{items: int, bytes: int, seconds: float} */
    public static function batchLimits(): array
    {
        return [
            'items' => max(1, min(self::BATCH_HARD_MAX_ITEMS, (int) config('release_verification.publish_batch_max_items', 16))),
            'bytes' => max(1, (int) config('release_verification.publish_batch_max_bytes', 1048576)),
            'seconds' => max(0.0, (float) config('release_verification.publish_batch_seconds', 15)),
        ];
    }

    /**
     * Publish a batch one item at a time, each in its own transaction exactly as a single request
     * publishes it. Items are validated independently; one invalid or failed item never affects
     * another. The worker lock is taken once, on the first item that needs it, and kept to the end.
     *
     * Items not started when the time budget ends are answered "retry" without being read, and the
     * first item always starts so a tiny budget cannot starve a worker. The budget is measured on
     * a monotonic clock.
     *
     * @param  list<mixed>  $items  Raw request items.
     * @return list<array{index: int, claim_id: ?int, claim_version: ?int, status: string, release_id: ?int, error: ?string}>
     */
    public function publishBatch(array $items, float $budgetSeconds): array
    {
        $size = count($items);
        $startedAt = $this->clock();
        // One sample decision per batch; each sampled item then reports its own timing.
        $sampled = PublicationTiming::sample() !== null;
        $gate = new PublicationWorkerGate(retain: true);
        $results = [];
        $exhausted = false;
        try {
            foreach ($items as $index => $item) {
                $item = is_array($item) ? $item : [];
                $exhausted = $exhausted || ($index > 0 && $this->clock() - $startedAt >= $budgetSeconds);
                $outcome = $exhausted
                    ? new PublicationOutcome(PublicationOutcome::RETRY, null, 'Batch time budget exhausted; resend this item.')
                    : $this->publishBatchItem($item, $sampled ? PublicationTiming::start() : null, $size, $index, $gate);
                $results[] = ['index' => $index, 'claim_id' => $this->integerAt($item, 'id'), 'claim_version' => $this->integerAt($item, 'version'),
                    'status' => $outcome->status, 'release_id' => $outcome->releaseId, 'error' => $outcome->error];
                unset($outcome);
                // A manifest, its parse and the compressed body are large; none outlives its item.
                gc_collect_cycles();
            }
        } finally {
            $gate->close();
        }

        return $results;
    }

    /** @param array<mixed> $item */
    private function publishBatchItem(array $item, ?PublicationTiming $timing, int $size, int $index, PublicationWorkerGate $gate): PublicationOutcome
    {
        $timing?->setBatch($size, $index);
        $validator = Validator::make($item, self::itemRules());
        if ($validator->fails()) {
            return new PublicationOutcome(PublicationOutcome::INVALID, null, mb_strcut(implode(' ', $validator->errors()->all()), 0, 500, 'UTF-8'));
        }
        $timing?->requestPhase('validation');
        try {
            $outcome = $this->publishItem($validator->validated(), $timing, $gate, replayResolved: true);

            // UNRESOLVED is the single endpoint's bare null; a batch item names it an error.
            return $outcome->status === PublicationOutcome::UNRESOLVED
                ? new PublicationOutcome(PublicationOutcome::ERROR, null, $outcome->error ?? 'Publication produced no release.') : $outcome;
        } catch (Throwable $error) {
            // Even the recovery lookup failed; the item may or may not have published, and a resend decides.
            return new PublicationOutcome(PublicationOutcome::ERROR, null, mb_strcut($error->getMessage(), 0, 500, 'UTF-8'));
        }
    }

    /** @param array<mixed> $item */
    private function integerAt(array $item, string $key): ?int
    {
        $value = is_array($item['claim'] ?? null) ? ($item['claim'][$key] ?? null) : null;

        return is_int($value) || (is_string($value) && ctype_digit($value) && strlen($value) < 19) ? (int) $value : null;
    }

    private function clock(): float
    {
        return $this->clock !== null ? ($this->clock)() : hrtime(true) / 1_000_000_000;
    }

    /**
     * The execution limit a batch needs, or null to leave it alone: only ever a raise, so a
     * transaction is never cut short by a limit lower than the host already allows. A limit of 0
     * is already unlimited.
     */
    public static function raisedTimeLimit(int $current, float $budgetSeconds): ?int
    {
        if ($current === 0) {
            return null;
        }
        $needed = (int) ceil($budgetSeconds) + self::BATCH_TIME_MARGIN;

        return $needed > $current ? $needed : null;
    }

    /**
     * Publish one validated item.
     *
     * With $replayResolved (batch items), a claim whose exact generation was already filtered or
     * sent to metadata review answers with that status, so a resent batch is idempotent; the
     * single endpoint keeps reporting such a claim as stale.
     *
     * @param  array<string, mixed>  $data  The item as validated by itemRules().
     */
    public function publishItem(array $data, ?PublicationTiming $timing, PublicationWorkerGate $gate, bool $replayResolved = false): PublicationOutcome
    {
        $claim = (object) ['id' => (int) $data['claim']['id'], 'version' => (int) $data['claim']['version'],
            'manifest_hash' => (string) $data['claim']['manifest_hash'], 'lease_token' => (string) $data['claim']['lease_token']];
        $evidence = ['articles' => (int) $data['evidence']['articles'], 'bytes' => (int) $data['evidence']['bytes'],
            'sampled_files' => (int) $data['evidence']['sampled_files'],
            'verified_file_names' => array_values(array_map('strval', $data['evidence']['verified_file_names'])),
            'identity_hints' => array_values(array_map('strval', $data['evidence']['identity_hints'])),
            'partial' => (bool) ($data['evidence']['partial'] ?? false)];
        if (isset($data['evidence']['parity_file_keys']) && is_array($data['evidence']['parity_file_keys'])) {
            $evidence['parity_file_keys'] = array_values(array_map('strval', $data['evidence']['parity_file_keys']));
        }
        try {
            $settled = $this->settled($claim, $replayResolved);
            $timing?->requestPhase('published_check');
            if ($settled !== null) {
                return $settled;
            }
            $candidate = DB::table('release_verification_candidates')->where('id', $claim->id)->first();
            if ($candidate === null || (int) $candidate->version !== $claim->version || $candidate->manifest_hash !== $claim->manifest_hash
                || $candidate->lease_token !== $claim->lease_token || $candidate->manifest_path === '') {
                // A concurrent first attempt may have published and cleared the lease since the check above.
                return $this->settled($claim, $replayResolved) ?? new PublicationOutcome(PublicationOutcome::STALE, null, self::STALE_MESSAGE);
            }
            $timing?->requestPhase('candidate_read');
            $archived = $this->store->read($candidate->manifest_path);
            $evidence['partial'] = (new CandidateHeaderInventory)->assessment($archived)['status'] === 'waiting_upload';
            $manifest = SpotCheckVerifier::withEvidence($archived, $evidence);
            $timing?->requestPhase('manifest_read');
            if (! $gate->enter()) {
                return new PublicationOutcome(PublicationOutcome::PAUSED, null, self::PAUSED_MESSAGE);
            }
            $timing?->requestPhase('worker_lock');
            try {
                $releaseId = $this->publisher->publish($claim, new VerificationResult('sampled', (string) $data['reason'], $manifest),
                    'remote', $timing ?? false, ['path' => (string) $candidate->manifest_path, 'manifest' => $archived]);
            } catch (PartialPublicationDeferredException $deferred) {
                // The sending worker returns the partial to waiting for one more hold window.
                return new PublicationOutcome(PublicationOutcome::DEFERRED, null, $deferred->getMessage());
            } finally {
                $gate->leave();
            }
            if ($releaseId !== null) {
                return new PublicationOutcome(PublicationOutcome::PUBLISHED, $releaseId);
            }
            // No release: policy filtered the candidate, or a covered release lacks its metadata.
            // The single endpoint reports both as a bare null and reads nothing more.
            $status = $replayResolved ? $this->resolvedStatus($claim) : null;

            return new PublicationOutcome($status ?? PublicationOutcome::UNRESOLVED, null,
                $status === null && $replayResolved ? 'Publication produced no release.' : null);
        } catch (Throwable $error) {
            // The worker's retry after a lost response finds the release its first request published.
            // A batch item also settles when an overlapping attempt filtered it or sent it to review.
            $settled = $this->settled($claim, $replayResolved);
            if ($settled !== null) {
                return $settled;
            }
            $message = mb_strcut($error->getMessage(), 0, 500, 'UTF-8');

            return new PublicationOutcome(str_starts_with($message, 'Stale verification result cannot')
                ? PublicationOutcome::STALE : PublicationOutcome::ERROR, null, $message);
        }
    }

    /** The release already published for this exact candidate generation, or (when replaying) its terminal status. */
    private function settled(object $claim, bool $replayResolved): ?PublicationOutcome
    {
        $published = $this->publishedRelease($claim);
        if ($published !== null) {
            return new PublicationOutcome(PublicationOutcome::PUBLISHED, $published);
        }
        $status = $replayResolved ? $this->resolvedStatus($claim) : null;

        return $status === null ? null : new PublicationOutcome($status);
    }

    /** The release already published for this exact candidate generation. */
    private function publishedRelease(object $claim): ?int
    {
        $releaseId = DB::table('release_verification_candidates')->where('id', $claim->id)->where('version', $claim->version)
            ->where('manifest_hash', $claim->manifest_hash)->whereNotNull('release_id')->value('release_id');

        return $releaseId === null ? null : (int) $releaseId;
    }

    /** filtered or metadata_review when this exact generation ended there without a release. */
    private function resolvedStatus(object $claim): ?string
    {
        $status = DB::table('release_verification_candidates')->where('id', $claim->id)->where('version', $claim->version)
            ->where('manifest_hash', $claim->manifest_hash)->whereNull('release_id')
            ->whereIn('status', [PublicationOutcome::FILTERED, PublicationOutcome::METADATA_REVIEW])->value('status');

        return is_string($status) ? $status : null;
    }
}
