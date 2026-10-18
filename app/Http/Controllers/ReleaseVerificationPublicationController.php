<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Releases\Verification\PublicationOutcome;
use App\Services\Releases\Verification\PublicationTiming;
use App\Services\Releases\Verification\PublicationWorkerGate;
use App\Services\Releases\Verification\RemotePublicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Publishes a remote worker's spot-checked candidate on the host holding NZB storage. The
 * worker's lease token must still own the candidate; the publisher revalidates it under lock.
 * The batch endpoint publishes several the same way; both delegate to RemotePublicationService.
 */
class ReleaseVerificationPublicationController extends Controller
{
    public function __invoke(Request $request, RemotePublicationService $service): JsonResponse
    {
        // One sample decision per request: this timing is handed to publish(), which logs it as a
        // single line whose request_phases_ns hold the work done here before the publication.
        $timing = PublicationTiming::sample();
        $data = $request->validate(RemotePublicationService::itemRules());
        $timing?->requestPhase('validation');
        $outcome = $service->publishItem($data, $timing, new PublicationWorkerGate);

        return match ($outcome->status) {
            PublicationOutcome::PUBLISHED => response()->json(['release_id' => $outcome->releaseId]),
            // The worker reads the candidate row to learn why no release exists.
            PublicationOutcome::FILTERED, PublicationOutcome::METADATA_REVIEW, PublicationOutcome::UNRESOLVED => response()->json(['release_id' => null]),
            default => response()->json(['error' => $outcome->error], 409),
        };
    }
}
