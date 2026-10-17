<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Releases\Verification\ManifestStore;
use App\Services\Releases\Verification\PartialPublicationDeferredException;
use App\Services\Releases\Verification\SpotCheckVerifier;
use App\Services\Releases\Verification\VerificationLockStore;
use App\Services\Releases\Verification\VerificationResult;
use App\Services\Releases\Verification\VerificationWorkerLocks;
use App\Services\Releases\Verification\VerifiedReleasePublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Publishes a remote worker's spot-checked candidate on the host holding NZB storage. The
 * worker's lease token must still own the candidate; the publisher revalidates it under lock.
 */
class ReleaseVerificationPublicationController extends Controller
{
    public function __invoke(Request $request, VerifiedReleasePublisher $publisher, ManifestStore $store): JsonResponse
    {
        $data = $request->validate([
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
        ]);
        $claim = (object) ['id' => (int) $data['claim']['id'], 'version' => (int) $data['claim']['version'],
            'manifest_hash' => (string) $data['claim']['manifest_hash'], 'lease_token' => (string) $data['claim']['lease_token']];
        $evidence = ['articles' => (int) $data['evidence']['articles'], 'bytes' => (int) $data['evidence']['bytes'],
            'sampled_files' => (int) $data['evidence']['sampled_files'],
            'verified_file_names' => array_values(array_map('strval', $data['evidence']['verified_file_names'])),
            'identity_hints' => array_values(array_map('strval', $data['evidence']['identity_hints'])),
            'partial' => (bool) ($data['evidence']['partial'] ?? false)];
        try {
            $published = $this->publishedRelease($claim);
            if ($published !== null) {
                return response()->json(['release_id' => $published]);
            }
            $candidate = DB::table('release_verification_candidates')->where('id', $claim->id)->first();
            if ($candidate === null || (int) $candidate->version !== $claim->version || $candidate->manifest_hash !== $claim->manifest_hash
                || $candidate->lease_token !== $claim->lease_token || $candidate->manifest_path === '') {
                // A concurrent first attempt may have published and cleared the lease since the check above.
                $published = $this->publishedRelease($claim);

                return $published !== null ? response()->json(['release_id' => $published])
                    : response()->json(['error' => 'Stale verification result cannot publish.'], 409);
            }
            $manifest = SpotCheckVerifier::withEvidence($store->read($candidate->manifest_path), $evidence);
            // Publication writes as a worker does, so a reset, audit or deployment barrier that
            // holds the worker group excludes it even after the sending worker has gone.
            $locks = VerificationLockStore::make();
            $worker = $locks->shared('worker');
            if ($worker !== null && VerificationWorkerLocks::paused()) {
                $locks->release($worker);
                $worker = null;
            }
            if ($worker === null) {
                return response()->json(['error' => 'Verification is paused for exclusive maintenance; retry later.'], 409);
            }
            try {
                return response()->json(['release_id' => $publisher->publish($claim, new VerificationResult('sampled', (string) $data['reason'], $manifest))]);
            } catch (PartialPublicationDeferredException $deferred) {
                // The sending worker returns the partial to waiting for one more hold window.
                return response()->json(['deferred' => $deferred->getMessage()], 409);
            } finally {
                $locks->release($worker);
            }
        } catch (Throwable $error) {
            // The worker's retry after a lost response finds the release its first request published.
            $published = $this->publishedRelease($claim);
            if ($published !== null) {
                return response()->json(['release_id' => $published]);
            }

            return response()->json(['error' => mb_strcut($error->getMessage(), 0, 500, 'UTF-8')], 409);
        }
    }

    /** The release already published for this exact candidate generation. */
    private function publishedRelease(object $claim): ?int
    {
        $releaseId = DB::table('release_verification_candidates')->where('id', $claim->id)->where('version', $claim->version)
            ->where('manifest_hash', $claim->manifest_hash)->whereNotNull('release_id')->value('release_id');

        return $releaseId === null ? null : (int) $releaseId;
    }
}
