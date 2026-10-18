<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A worker host without the primary's NZB storage, search index and queue asks the primary host
 * to publish its spot-checked candidate. Only the sampled evidence travels; the primary rebuilds
 * the manifest from its stored copy and publishes through the same locked transaction.
 */
final class RemoteReleasePublisher
{
    public function publish(object $claim, VerificationResult $result): ?int
    {
        if (! $result->passed()) {
            return null;
        }
        if ($result->verdict !== 'sampled') {
            throw new RuntimeException('Only spot-checked results can be published by a remote worker.');
        }
        $payload = ['claim' => ['id' => (int) $claim->id, 'version' => (int) $claim->version,
            'manifest_hash' => (string) $claim->manifest_hash, 'lease_token' => (string) $claim->lease_token],
            'reason' => $result->reason, 'evidence' => SpotCheckVerifier::evidence($result->manifest)];
        // A repeated request after a lost response returns the release it already published.
        // Sampled like the primary's publication timing; includes connection retries and their delay.
        $timing = PublicationTiming::sample();
        try {
            $response = Http::acceptJson()->asJson()->connectTimeout(5)
                ->timeout(max(10, (int) config('release_verification.publish_timeout', 120)))
                ->retry(3, 1000, static fn (\Throwable $error): bool => $error instanceof ConnectionException, throw: false)
                ->post((string) config('release_verification.publish_url'), $payload);
        } catch (\Throwable $error) {
            $timing?->reportRoundTrip('threw', null);

            throw $error;
        }
        $timing?->reportRoundTrip($response->successful() ? 'returned' : 'failed', $response->status());
        if (! $response->successful()) {
            $error = $response->json('error');
            throw new RuntimeException('Remote publication failed: '
                .mb_strcut(is_string($error) ? $error : 'HTTP '.$response->status(), 0, 500, 'UTF-8'));
        }
        $releaseId = $response->json('release_id');

        return $releaseId === null ? null : (int) $releaseId;
    }
}
