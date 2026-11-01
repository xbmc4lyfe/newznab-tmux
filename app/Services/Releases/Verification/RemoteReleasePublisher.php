<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * A worker host without the primary's NZB storage, search index and queue asks the primary host
 * to publish its spot-checked candidate. Only the sampled evidence travels; the primary rebuilds
 * the manifest from its stored copy and publishes through the same locked transaction.
 */
final class RemoteReleasePublisher
{
    /** Seconds the single endpoint is used alone after the batch endpoint proved unavailable. */
    public const int BATCH_FALLBACK_SECONDS = 600;

    private const string BATCH_FALLBACK_KEY = 'release_verification:publish_batch_fallback';

    /** The most items per request the primary last advertised in a 422 answer, if it ever did. */
    private ?int $advertisedMaxItems = null;

    /** The largest request body in bytes the primary last advertised in a 413 answer, if it ever did. */
    private ?int $advertisedMaxBytes = null;

    /** @var (callable(int): void)|null Called while a {@see publishMany()} call waits for the primary. */
    private $progress = null;

    /** @var (callable(): bool)|null Asked before each request of a {@see publishMany()} call whether to start no more. */
    private $cancelled = null;

    /** @var list<array{items: int, started: int, finished: int, failed: bool}> HTTP requests of the last {@see publishMany()} call. */
    private array $requests = [];

    /** Items of the current {@see publishMany()} call that already have an outcome. */
    private int $completed = 0;

    /** @param (callable(): void)|null $progress Caller ownership checks; failures propagate, unlike publishMany's best-effort markers. */
    public function publish(object $claim, VerificationResult $result, ?callable $progress = null): ?int
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
                ->withOptions($this->progressOptions($progress))
                ->withRequestMiddleware($this->cancellationGate($progress))
                ->retry(3, 1000, fn (\Throwable $error): bool => $error instanceof ConnectionException && ! $this->cancelledNow(), throw: false)
                ->post((string) config('release_verification.publish_url'), $payload);
        } catch (\Throwable $error) {
            $timing?->reportRoundTrip('threw', null);

            throw $error;
        }
        $timing?->reportRoundTrip($response->successful() ? 'returned' : 'failed', $response->status());
        if (! $response->successful()) {
            $deferred = $response->json('deferred');
            if (is_string($deferred)) {
                // Handled as a local publication's deferral: back to waiting for late articles.
                throw new PartialPublicationDeferredException($deferred);
            }
            $error = $response->json('error');
            throw new RuntimeException('Remote publication failed: '
                .mb_strcut(is_string($error) ? $error : 'HTTP '.$response->status(), 0, 500, 'UTF-8'));
        }
        $releaseId = $response->json('release_id');

        return $releaseId === null ? null : (int) $releaseId;
    }

    /**
     * The seam for the per-host outbox: whether {@see publishMany()} would use the batch endpoint
     * now, that is, it is configured and was not found missing or incompatible in the last ten
     * minutes. The worker loop still publishes one candidate at a time through publish().
     */
    public function batchAvailable(): bool
    {
        $url = config('release_verification.publish_batch_url');
        if (! is_string($url) || $url === '') {
            return false;
        }
        try {
            return ! Cache::has(self::BATCH_FALLBACK_KEY);
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Publish several candidates in one request when the batch endpoint is configured and
     * available, otherwise (or when it proves unavailable) one request each through publish().
     * Every item has its own outcome, in input order; one item's failure never hides another's.
     * A transport failure or an unusable batch response throws instead, and the primary treats a
     * resent item idempotently, so the whole list may simply be sent again.
     *
     * $progress, when given, is called with the number of items that already have an outcome: before
     * each request and while one waits for the primary (about once a second). A caller uses it to keep
     * its own markers and its items' leases alive; a failure inside it never affects publication.
     *
     * $cancelled, when given, is asked before each request starts; once it answers true no further
     * request is sent, and every item not yet sent returns RETRY, which only defers it. A request
     * already under way is not interrupted.
     *
     * @param  list<RemotePublishItem>  $items
     * @param  (callable(int): void)|null  $progress
     * @param  (callable(): bool)|null  $cancelled
     * @return list<PublicationOutcome>
     */
    public function publishMany(array $items, ?callable $progress = null, ?callable $cancelled = null): array
    {
        $this->progress = $progress;
        $this->cancelled = $cancelled;
        $this->completed = 0;
        $this->requests = [];
        try {
            return $this->publishItems($items);
        } finally {
            $this->progress = null;
            $this->cancelled = null;
        }
    }

    /**
     * Every HTTP request the last {@see publishMany()} call made, one by one: the batch request, then
     * (when it was refused or unavailable) each single request. `failed` marks a request that produced
     * no usable answer (a connection failure or timeout, or an unusable batch response). Times are
     * hrtime() nanoseconds. Not recorded: items that were not attempted.
     *
     * @return list<array{items: int, started: int, finished: int, failed: bool}>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * The most items per request the primary last advertised when it refused a batch as too large.
     * A caller that builds batches should send no more than this.
     */
    public function advertisedMaxItems(): ?int
    {
        return $this->advertisedMaxItems;
    }

    /**
     * @param  list<RemotePublishItem>  $items
     * @return list<PublicationOutcome>
     */
    private function publishItems(array $items): array
    {
        $outcomes = [];
        $payloads = [];
        foreach ($items as $position => $item) {
            if (! $item->result->passed()) {
                $outcomes[$position] = new PublicationOutcome(PublicationOutcome::NO_RELEASE);

                continue;
            }
            try {
                if ($item->result->verdict !== 'sampled') {
                    throw new RuntimeException('Only spot-checked results can be published by a remote worker.');
                }
                $payloads[$position] = ['claim' => ['id' => (int) $item->claim->id, 'version' => (int) $item->claim->version,
                    'manifest_hash' => (string) $item->claim->manifest_hash, 'lease_token' => (string) $item->claim->lease_token],
                    'reason' => $item->result->reason, 'evidence' => SpotCheckVerifier::evidence($item->result->manifest)];
            } catch (\Throwable $error) {
                $outcomes[$position] = new PublicationOutcome(PublicationOutcome::ERROR, null, mb_strcut($error->getMessage(), 0, 500, 'UTF-8'));
            }
        }
        // Groups follow the caller's order; each one request, or (when it cannot be batched) one by one.
        foreach ($this->groups($payloads) as $group) {
            $positions = array_keys($group['payloads']);
            $this->completed = $positions[0];
            $sent = $this->cancelledNow() ? $this->notSent($positions)
                : ($group['batch'] && $this->batchAvailable() ? $this->timedBatch($group['payloads']) : null);
            foreach ($sent ?? $this->publishEach($items, $positions) as $position => $outcome) {
                $outcomes[$position] = $outcome;
            }
        }
        ksort($outcomes);

        return array_values($outcomes);
    }

    /**
     * Split the payloads into requests that fit the byte limit the primary advertised. Without one,
     * all go in one request. A payload too large for a request even alone goes through the single
     * endpoint (its own group, `batch` false), whose body limit is not the batch's.
     *
     * @param  array<int, array<string, mixed>>  $payloads  By position.
     * @return list<array{batch: bool, payloads: array<int, array<string, mixed>>}>
     */
    private function groups(array $payloads): array
    {
        if ($payloads === []) {
            return [];
        }
        $limit = $this->advertisedMaxBytes;
        if ($limit === null) {
            return [['batch' => true, 'payloads' => $payloads]];
        }
        $groups = [];
        $current = [];
        foreach ($payloads as $position => $payload) {
            if ($this->bodySize([$payload]) > $limit) {
                if ($current !== []) {
                    $groups[] = ['batch' => true, 'payloads' => $current];
                    $current = [];
                }
                $groups[] = ['batch' => false, 'payloads' => [$position => $payload]];

                continue;
            }
            if ($current !== [] && $this->bodySize([...array_values($current), $payload]) > $limit) {
                $groups[] = ['batch' => true, 'payloads' => $current];
                $current = [];
            }
            $current[$position] = $payload;
        }
        if ($current !== []) {
            $groups[] = ['batch' => true, 'payloads' => $current];
        }

        return $groups;
    }

    /**
     * The batch request body for these payloads, as the HTTP client encodes it.
     *
     * @param  list<array<string, mixed>>  $payloads
     */
    private function bodySize(array $payloads): int
    {
        return strlen((string) json_encode(['protocol' => RemotePublicationService::BATCH_PROTOCOL, 'items' => $payloads]));
    }

    /**
     * The most bytes per batch request body the primary last advertised when it refused one as too large.
     * A caller that builds requests should send no more than this.
     */
    public function advertisedMaxBytes(): ?int
    {
        return $this->advertisedMaxBytes;
    }

    /**
     * @param  array<int, array<string, mixed>>  $payloads  By the item's position in the caller's list.
     * @return array<int, PublicationOutcome>|null By position; null when the single endpoint must publish instead.
     */
    private function timedBatch(array $payloads): ?array
    {
        $started = hrtime(true);
        try {
            $sent = $this->sendBatch($payloads);
        } catch (\Throwable $error) {
            $this->requests[] = ['items' => count($payloads), 'started' => $started, 'finished' => hrtime(true), 'failed' => true];

            throw $error;
        }
        $this->requests[] = ['items' => count($payloads), 'started' => $started, 'finished' => hrtime(true), 'failed' => false];

        return $sent;
    }

    /**
     * @param  array<int, array<string, mixed>>  $payloads  By the item's position in the caller's list.
     * @return array<int, PublicationOutcome>|null By position; null when the single endpoint must publish instead.
     */
    private function sendBatch(array $payloads): ?array
    {
        $positions = array_keys($payloads);
        $timing = PublicationTiming::sample();
        try {
            $response = Http::acceptJson()->asJson()->connectTimeout(5)
                ->timeout(max(10, (int) config('release_verification.publish_timeout', 120)))
                ->withOptions($this->progressOptions())
                ->withRequestMiddleware($this->cancellationGate())
                ->retry(3, 1000, fn (\Throwable $error): bool => $error instanceof ConnectionException && ! $this->cancelledNow(), throw: false)
                ->post((string) config('release_verification.publish_batch_url'),
                    ['protocol' => RemotePublicationService::BATCH_PROTOCOL, 'items' => array_values($payloads)]);
        } catch (\Throwable $error) {
            $timing?->reportRoundTrip('threw', null, count($payloads));

            throw $error;
        }
        $timing?->reportRoundTrip($response->successful() ? 'returned' : 'failed', $response->status(), count($payloads));
        $status = $response->status();
        if (in_array($status, [404, 405], true) || ($status === 422 && $response->json('supported_protocol') !== null)) {
            // An older primary, a disabled endpoint or another protocol: the single endpoint does the work.
            $this->markBatchUnavailable();

            return null;
        }
        if ($status === 413 || ($status === 422 && $response->json('max_items') !== null)) {
            // This batch is too big for the primary's limits; the endpoint itself works.
            $advertised = $response->json('max_items');
            if ($status === 422 && is_int($advertised) && $advertised >= 1) {
                $this->advertisedMaxItems = $advertised;
            }
            $bytes = $response->json('max_bytes');
            if ($status === 413 && is_int($bytes) && $bytes >= 1) {
                $this->advertisedMaxBytes = $bytes;
            }

            return null;
        }
        if (! $response->successful()) {
            $error = $response->json('error');
            throw new RuntimeException('Remote batch publication failed: '
                .mb_strcut(is_string($error) ? $error : 'HTTP '.$status, 0, 500, 'UTF-8'));
        }
        $results = $response->json('results');
        if ($response->json('protocol') !== RemotePublicationService::BATCH_PROTOCOL || ! is_array($results)) {
            throw new RuntimeException('Remote batch publication returned an unexpected response.');
        }
        $byIndex = [];
        foreach ($results as $result) {
            if (is_array($result) && is_int($result['index'] ?? null)) {
                $byIndex[$result['index']] = $result;
            }
        }
        $outcomes = [];
        foreach ($positions as $index => $position) {
            $result = $byIndex[$index] ?? null;
            $claim = $payloads[$position]['claim'];
            // An absent or mismatched result is unknown, never success; resending is safe.
            if ($result === null || ($result['claim_id'] ?? null) !== $claim['id'] || ($result['claim_version'] ?? null) !== $claim['version']
                || ! is_string($result['status'] ?? null)) {
                $outcomes[$position] = new PublicationOutcome(PublicationOutcome::ERROR, null, 'The primary returned no matching result for this item.');

                continue;
            }
            $releaseId = $result['release_id'] ?? null;
            $outcomes[$position] = $result['status'] === PublicationOutcome::PUBLISHED && ! is_int($releaseId)
                ? new PublicationOutcome(PublicationOutcome::ERROR, null, 'The primary reported publication without a release.')
                : new PublicationOutcome($result['status'], is_int($releaseId) ? $releaseId : null,
                    is_string($result['error'] ?? null) ? $result['error'] : null);
        }

        return $outcomes;
    }

    /**
     * @param  list<RemotePublishItem>  $items
     * @param  list<int>  $positions
     * @return array<int, PublicationOutcome>
     */
    private function publishEach(array $items, array $positions): array
    {
        $outcomes = [];
        $unreachable = null;
        foreach ($positions as $position) {
            // Items before this position, in order, already have their outcomes.
            $this->completed = $position;
            $this->tick();
            if ($this->cancelledNow()) {
                $outcomes += $this->notSent(array_slice($positions, array_search($position, $positions, true)));

                break;
            }
            if ($unreachable !== null) {
                // The primary just proved unreachable; waiting out the same timeouts for each item helps no one.
                $outcomes[$position] = new PublicationOutcome(PublicationOutcome::TRANSPORT, null, $unreachable);

                continue;
            }
            $started = hrtime(true);
            $failed = false;
            try {
                $releaseId = $this->publish($items[$position]->claim, $items[$position]->result);
                $outcomes[$position] = $releaseId === null ? new PublicationOutcome(PublicationOutcome::NO_RELEASE)
                    : new PublicationOutcome(PublicationOutcome::PUBLISHED, $releaseId);
            } catch (PartialPublicationDeferredException $deferred) {
                // The primary answered: a partial below a recoverable policy goes back to waiting for late articles.
                $outcomes[$position] = new PublicationOutcome(PublicationOutcome::DEFERRED, null, $deferred->getMessage());
            } catch (ConnectionException $error) {
                $failed = true;
                // A refused or dropped connection or a timeout is no answer from the primary; an error
                // response (a RuntimeException from publish()) is, and stays an ERROR below.
                $unreachable = mb_strcut('Remote publication could not reach the primary: '.$error->getMessage(), 0, 500, 'UTF-8');
                $outcomes[$position] = new PublicationOutcome(PublicationOutcome::TRANSPORT, null, $unreachable);
            } catch (\Throwable $error) {
                // An error response is an answer, not a transport failure. One that says verification is paused for
                // maintenance (the single endpoint answers 409) is the batch endpoint's `paused`: retry later.
                $outcomes[$position] = new PublicationOutcome(str_contains($error->getMessage(), RemotePublicationService::PAUSED_MESSAGE)
                    ? PublicationOutcome::PAUSED : PublicationOutcome::ERROR, null, mb_strcut($error->getMessage(), 0, 500, 'UTF-8'));
            }
            $this->requests[] = ['items' => 1, 'started' => $started, 'finished' => hrtime(true), 'failed' => $failed];
        }

        return $outcomes;
    }

    /**
     * @param  (callable(): void)|null  $progress
     * @return array{progress?: callable} Guzzle's progress option, which cURL calls about once a second while waiting.
     */
    private function progressOptions(?callable $progress = null): array
    {
        return $this->progress === null && $progress === null ? [] : ['progress' => function () use ($progress): void {
            if ($progress !== null) {
                $progress();
            }
            $this->tick();
        }];
    }

    /**
     * Request middleware that runs before every transport attempt, retries included. The retry predicate
     * is asked before the delay, so a cancellation during the delay is only seen here; the attempt then
     * fails as an unreachable primary, without a request. Without a cancellation check it does nothing.
     *
     * @param  (callable(): void)|null  $progress
     * @return callable(RequestInterface): RequestInterface
     */
    private function cancellationGate(?callable $progress = null): callable
    {
        return function (RequestInterface $request) use ($progress): RequestInterface {
            if ($this->cancelledNow()) {
                throw new ConnectionException('Publication was cancelled before this attempt was sent.');
            }
            if ($progress !== null) {
                $progress();
            }

            return $request;
        };
    }

    private function cancelledNow(): bool
    {
        if ($this->cancelled === null) {
            return false;
        }
        try {
            return (bool) ($this->cancelled)();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  list<int>  $positions
     * @return array<int, PublicationOutcome>
     */
    private function notSent(array $positions): array
    {
        $outcomes = [];
        foreach ($positions as $position) {
            $outcomes[$position] = new PublicationOutcome(PublicationOutcome::RETRY, null, 'Publication was cancelled before this item was sent.');
        }

        return $outcomes;
    }

    private function tick(): void
    {
        if ($this->progress === null) {
            return;
        }
        try {
            ($this->progress)($this->completed);
        } catch (\Throwable) {
            // Keeping markers and leases fresh is best effort; it must not turn a publication into a failure.
        }
    }

    private function markBatchUnavailable(): void
    {
        try {
            Cache::put(self::BATCH_FALLBACK_KEY, true, self::BATCH_FALLBACK_SECONDS);
        } catch (\Throwable) {
            // Without a shared cache each batch simply tries the endpoint again.
        }
    }
}
