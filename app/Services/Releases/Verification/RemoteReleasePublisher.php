<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;
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
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w16_remote(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);
        C;

    // Candidate helpers retain PHP defaults until every paired mean/p99 gate passes.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    /** Reject objects before serialize() can invoke user code; bound cycles and nesting. */
    private static function nativeValueSupported(mixed $value, int &$remaining, int $depth): bool
    {
        if (--$remaining < 0 || $depth > 24) {
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (! self::nativeValueSupported($item, $remaining, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || is_scalar($value);
    }

    /** @param list<mixed> $arguments */
    private static function computeNative(int $operation, array $arguments): mixed
    {
        if (! self::$useRustFfi) {
            return null;
        }
        try {
            $ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF);
            if ($ffi === null) {
                return null;
            }
            $remaining = 100000;
            if (! self::nativeValueSupported($arguments, $remaining, 0)) {
                return null;
            }
            $input = serialize($arguments);
            $capacity = 0;
            $output = RustPipelineLibrary::obtainScratch($ffi, max(4096, strlen($input) + 512), $capacity);
            if ($output === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w16_remote($operation, $input, strlen($input), $output, $capacity);
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return unserialize(FFI::string($output, $written), ['allowed_classes' => false]);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function publicationGuard(bool $passed, string $verdict): int
    {
        return self::publicationGuardNative($passed, $verdict) ?? self::publicationGuardPhp($passed, $verdict);
    }

    public static function publicationGuardPhp(bool $passed, string $verdict): int
    {
        return ! $passed ? 0 : ($verdict === 'sampled' ? 1 : 2);
    }

    public static function publicationGuardNative(bool $passed, string $verdict): ?int
    {
        $result = self::computeNative(0, [$passed, $verdict]);

        return is_int($result) ? $result : null;
    }

    public static function endpointUrl(string $url): string
    {
        return self::endpointUrlNative($url) ?? self::endpointUrlPhp($url);
    }

    public static function endpointUrlPhp(string $url): string
    {
        return trim($url);
    }

    public static function endpointUrlNative(string $url): ?string
    {
        $result = self::computeNative(1, [$url]);

        return is_string($result) ? $result : null;
    }

    public static function batchFallback(int $status, bool $protocol, bool $maxItems): bool
    {
        return self::batchFallbackNative($status, $protocol, $maxItems) ?? self::batchFallbackPhp($status, $protocol, $maxItems);
    }

    public static function batchFallbackPhp(int $status, bool $protocol, bool $maxItems): bool
    {
        return in_array($status, [404, 405, 413], true) || ($status === 422 && ($protocol || $maxItems));
    }

    public static function batchFallbackNative(int $status, bool $protocol, bool $maxItems): ?bool
    {
        $result = self::computeNative(2, [$status, $protocol, $maxItems]);

        return is_bool($result) ? $result : null;
    }

    public static function errorMessage(string $message): string
    {
        return self::errorMessageNative($message) ?? self::errorMessagePhp($message);
    }

    public static function errorMessagePhp(string $message): string
    {
        return mb_strcut($message, 0, 500, 'UTF-8');
    }

    public static function errorMessageNative(string $message): ?string
    {
        $result = self::computeNative(3, [$message]);

        return is_string($result) ? $result : null;
    }

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
        if (self::publicationGuard($result->passed(), $result->verdict) === 0) {
            return null;
        }
        if (self::publicationGuard($result->passed(), $result->verdict) === 2) {
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
                ->post(self::endpointUrl((string) config('release_verification.publish_url')), $payload);
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
                .self::errorMessage(is_string($error) ? $error : 'HTTP '.$response->status()));
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
                if (self::publicationGuard($item->result->passed(), $item->result->verdict) === 2) {
                    throw new RuntimeException('Only spot-checked results can be published by a remote worker.');
                }
                $payloads[$position] = ['claim' => ['id' => (int) $item->claim->id, 'version' => (int) $item->claim->version,
                    'manifest_hash' => (string) $item->claim->manifest_hash, 'lease_token' => (string) $item->claim->lease_token],
                    'reason' => $item->result->reason, 'evidence' => SpotCheckVerifier::evidence($item->result->manifest)];
            } catch (\Throwable $error) {
                $outcomes[$position] = new PublicationOutcome(PublicationOutcome::ERROR, null, self::errorMessage($error->getMessage()));
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
        $sizes = [];
        $emptySize = $this->bodySize([]);
        foreach ($payloads as $position => $payload) {
            $sizes[$position] = $this->bodySize([$payload]);
            if ($sizes[$position] < $emptySize) {
                // Preserve json_encode's failure behavior, including mixed valid/invalid items.
                return $this->groupsPhp($payloads);
            }
        }
        $groups = [];
        foreach (RemotePublicationService::batchPartition($sizes, $this->bodySize([]), $limit) as $group) {
            $selected = [];
            foreach ($group['positions'] as $position) {
                $selected[$position] = $payloads[$position];
            }
            $groups[] = ['batch' => $group['batch'], 'payloads' => $selected];
        }

        return $groups;
    }

    /**
     * Split the payloads into requests that fit the byte limit the primary advertised. Without one,
     * all go in one request. A payload too large for a request even alone goes through the single
     * endpoint (its own group, `batch` false), whose body limit is not the batch's.
     *
     * @param  array<int, array<string, mixed>>  $payloads  By position.
     * @return list<array{batch: bool, payloads: array<int, array<string, mixed>>}>
     */
    private function groupsPhp(array $payloads): array
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
                ->post(self::endpointUrl((string) config('release_verification.publish_batch_url')),
                    ['protocol' => RemotePublicationService::BATCH_PROTOCOL, 'items' => array_values($payloads)]);
        } catch (\Throwable $error) {
            $timing?->reportRoundTrip('threw', null, count($payloads));

            throw $error;
        }
        $timing?->reportRoundTrip($response->successful() ? 'returned' : 'failed', $response->status(), count($payloads));
        $status = $response->status();
        if (self::batchFallback($status, $response->json('supported_protocol') !== null, false) && $status !== 413) {
            // An older primary, a disabled endpoint or another protocol: the single endpoint does the work.
            $this->markBatchUnavailable();

            return null;
        }
        if (self::batchFallback($status, false, $response->json('max_items') !== null)) {
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
                .self::errorMessage(is_string($error) ? $error : 'HTTP '.$status));
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
                $unreachable = self::errorMessage('Remote publication could not reach the primary: '.$error->getMessage());
                $outcomes[$position] = new PublicationOutcome(PublicationOutcome::TRANSPORT, null, $unreachable);
            } catch (\Throwable $error) {
                // An error response is an answer, not a transport failure. One that says verification is paused for
                // maintenance (the single endpoint answers 409) is the batch endpoint's `paused`: retry later.
                $outcomes[$position] = new PublicationOutcome(str_contains($error->getMessage(), RemotePublicationService::PAUSED_MESSAGE)
                    ? PublicationOutcome::PAUSED : PublicationOutcome::ERROR, null, self::errorMessage($error->getMessage()));
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

    public const string W58_FFI_CDEF = 'int64_t nntmux_w585_publisher_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W58.5 parity test's QUALIFICATION). */
    private const bool W58_RUST_FFI_DEFAULT = true;

    /**
     * Wave 58.5 routing and FFI cache, kept in function-local statics (readonly verification classes cannot declare
     * static properties, so every W58 class follows the same shape). Without $op it reports the routing; 'set' swaps
     * the override for $val and returns the previous one; 'reset' replaces the cached handle with $val (null
     * re-resolves, false disables); 'buffer' returns `[FFI, int64_t[], capacity]` with room for at least $val words,
     * or null without the library.
     */
    private static function w58RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'reset':
                $ffi = $val;
                $out = null;
                $outCap = 0;

                return null;
            case 'buffer':
                if ($ffi === null) {
                    $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
                    $ffi = RustPipelineLibrary::resolveDomain('w58_5', self::W58_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
                }
                if (! $ffi instanceof FFI || ! \is_int($val)) {
                    return null;
                }
                if ($out === null || $outCap < $val) {
                    $outCap = max(1024, $val);
                    $out = $ffi->new('int64_t['.$outCap.']');
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled ?? self::W58_RUST_FFI_DEFAULT;
        }
    }

    public static function usesW58RustFfi(): bool
    {
        return self::w58RustFfiState() === true;
    }

    /**
     * Runs $callback with publisherPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW58RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w58RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w58RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 58.5 Rust helper over $count NUL-joined records, or null
     * to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w58Words(int $count, string $data, int $words): ?array
    {
        try {
            $handle = self::w58RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w585_publisher_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('q*', FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Batch plan over tab-separated `passed, verdict, id, version, manifest_hash, http_status` publisher results. Four
     * words per record: `[guard, key, fallback, flags]`. Guard follows publicationGuardPhp() (0 not passed, 1 sampled,
     * 2 other verdicts); key is the FNV-1a 32 idempotency hash of the raw `id:version:manifest_hash`; fallback is 1
     * for 404, 405 and 413, 2 for a conditional 422, else 0. Flags: 1 passed not `0`/`1` (read as not passed), 2 id
     * not 1-18 digits or zero, 4 version not 1-18 digits, 8 hash not 64 lowercase hex, 16 non-empty status that is
     * not three digits, 32 not exactly six fields.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function publisherPlanBatch(array $records): array
    {
        return self::usesW58RustFfi() ? self::publisherPlanBatchNative($records) : self::publisherPlanBatchPhp($records);
    }

    /**
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function publisherPlanBatchPhp(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $fields = explode("\t", $record);
            $flags = \count($fields) === 6 ? 0 : 32;
            $passed = $fields[0] === '1';
            if (! $passed && $fields[0] !== '0') {
                $flags |= 1;
            }
            $guard = self::publicationGuardPhp($passed, $fields[1] ?? '');
            $id = $fields[2] ?? '';
            if (\strlen($id) > 18 || ! ctype_digit($id) || (int) $id === 0) {
                $flags |= 2;
            }
            $version = $fields[3] ?? '';
            if (\strlen($version) > 18 || ! ctype_digit($version)) {
                $flags |= 4;
            }
            $hash = $fields[4] ?? '';
            if (\strlen($hash) !== 64 || strspn($hash, '0123456789abcdef') !== 64) {
                $flags |= 8;
            }
            $status = $fields[5] ?? '';
            $fallback = 0;
            if (\strlen($status) === 3 && ctype_digit($status)) {
                $code = (int) $status;
                $fallback = $code === 404 || $code === 405 || $code === 413 ? 1 : ($code === 422 ? 2 : 0);
            } elseif ($status !== '') {
                $flags |= 16;
            }
            array_push($out, $guard, (int) hexdec(hash('fnv1a32', $id.':'.$version.':'.$hash)), $fallback, $flags);
        }

        return $out;
    }

    /**
     * Rust batch over the NUL-joined records; falls back to PHP without the library or when a record holds
     * a NUL.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function publisherPlanBatchNative(array $records): array
    {
        $count = \count($records);
        if ($count === 0) {
            return [];
        }

        return self::w58Words($count, implode("\0", $records), 4 * $count) ?? self::publisherPlanBatchPhp($records);
    }
}
