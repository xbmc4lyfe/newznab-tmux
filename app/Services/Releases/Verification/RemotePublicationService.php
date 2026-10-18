<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use Illuminate\Support\Facades\DB;
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

    public const string PAUSED_MESSAGE = 'Verification is paused for exclusive maintenance; retry later.';

    public function __construct(private readonly VerifiedReleasePublisher $publisher, private readonly ManifestStore $store) {}

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
        ];
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
            'identity_hints' => array_values(array_map('strval', $data['evidence']['identity_hints']))];
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
            $manifest = SpotCheckVerifier::withEvidence($archived, $evidence);
            $timing?->requestPhase('manifest_read');
            if (! $gate->enter()) {
                return new PublicationOutcome(PublicationOutcome::PAUSED, null, self::PAUSED_MESSAGE);
            }
            $timing?->requestPhase('worker_lock');
            try {
                $releaseId = $this->publisher->publish($claim, new VerificationResult('sampled', (string) $data['reason'], $manifest),
                    'remote', $timing ?? false, ['path' => (string) $candidate->manifest_path, 'manifest' => $archived]);
            } finally {
                $gate->leave();
            }
            if ($releaseId !== null) {
                return new PublicationOutcome(PublicationOutcome::PUBLISHED, $releaseId);
            }
            // No release: policy filtered the candidate, or a covered release lacks its metadata.
            $status = $this->resolvedStatus($claim);

            return new PublicationOutcome($status ?? PublicationOutcome::UNRESOLVED, null,
                $status === null ? 'Publication produced no release.' : null);
        } catch (Throwable $error) {
            // The worker's retry after a lost response finds the release its first request published.
            $published = $this->publishedRelease($claim);
            if ($published !== null) {
                return new PublicationOutcome(PublicationOutcome::PUBLISHED, $published);
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
