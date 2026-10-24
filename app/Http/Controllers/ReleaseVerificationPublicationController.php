<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Nzb\DirectorySyncBridge;
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
    public const string FFI_CDEF = DirectorySyncBridge::FFI_CDEF;

    /** Rust stays opt-in: the JSON FFI round trip is slower than this PHP helper (< 1.5x). */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    /**
     * The HTTP status and JSON body answering one publication outcome ($errorType is its status).
     *
     * @return array{status: int, body: array<string, int|string|null>}
     */
    public static function classifySinglePublicationResponse(?int $releaseId, ?string $errorType, ?string $errorMessage): array
    {
        return self::usesRustFfi()
            ? self::classifySinglePublicationResponseNative($releaseId, $errorType, $errorMessage)
            : self::classifySinglePublicationResponsePhp($releaseId, $errorType, $errorMessage);
    }

    /** @return array{status: int, body: array<string, int|string|null>} */
    public static function classifySinglePublicationResponsePhp(?int $releaseId, ?string $errorType, ?string $errorMessage): array
    {
        return match ($errorType) {
            PublicationOutcome::PUBLISHED => ['status' => 200, 'body' => ['release_id' => $releaseId]],
            // The sending worker returns the partial to waiting for one more hold window.
            PublicationOutcome::DEFERRED => ['status' => 409, 'body' => ['deferred' => $errorMessage]],
            // The worker reads the candidate row to learn why no release exists.
            PublicationOutcome::FILTERED, PublicationOutcome::METADATA_REVIEW, PublicationOutcome::UNRESOLVED => ['status' => 200, 'body' => ['release_id' => null]],
            default => ['status' => 409, 'body' => ['error' => $errorMessage]],
        };
    }

    /** @return array{status: int, body: array<string, int|string|null>} */
    public static function classifySinglePublicationResponseNative(?int $releaseId, ?string $errorType, ?string $errorMessage): array
    {
        if (DirectorySyncBridge::transformNative(5, [$releaseId, $errorType, $errorMessage], $result) && \is_array($result)
            && array_keys($result) === ['status', 'body'] && \is_int($result['status']) && \is_array($result['body'])) {
            $body = $result['body'];
            $key = array_key_first($body);
            if (\count($body) === 1 && \in_array($key, ['release_id', 'deferred', 'error'], true)
                && ($body[$key] === null || ($key === 'release_id' ? \is_int($body[$key]) : \is_string($body[$key])))) {
                /** @var array{status: int, body: array<string, int|string|null>} $result */
                return $result;
            }
        }

        return self::classifySinglePublicationResponsePhp($releaseId, $errorType, $errorMessage);
    }

    public function __invoke(Request $request, RemotePublicationService $service): JsonResponse
    {
        // One sample decision per request: this timing is handed to publish(), which logs it as a
        // single line whose request_phases_ns hold the work done here before the publication.
        $timing = PublicationTiming::sample();
        $data = $request->validate(RemotePublicationService::itemRules());
        $timing?->requestPhase('validation');
        $outcome = $service->publishItem($data, $timing, new PublicationWorkerGate);

        $response = self::classifySinglePublicationResponse($outcome->releaseId, $outcome->status, $outcome->error);

        return response()->json($response['body'], $response['status']);
    }
}
