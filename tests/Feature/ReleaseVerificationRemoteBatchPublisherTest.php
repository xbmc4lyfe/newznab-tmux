<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Releases\Verification\PublicationOutcome;
use App\Services\Releases\Verification\RemotePublishItem;
use App\Services\Releases\Verification\RemoteReleasePublisher;
use App\Services\Releases\Verification\VerificationResult;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PublicationBatchFixtures;

class ReleaseVerificationRemoteBatchPublisherTest extends ReleaseVerificationCandidatesTest
{
    use PublicationBatchFixtures;

    private const string SINGLE = 'http://primary.test/internal/release-verification/publish';

    private const string BATCH = 'http://primary.test/internal/release-verification/publish-batch';

    /** @var list<string> */
    private array $requested = [];

    private function configureWorker(bool $batch = true): void
    {
        config(['release_verification.publish_url' => self::SINGLE, 'release_verification.publish_batch_url' => $batch ? self::BATCH : null]);
        $this->requested = [];
    }

    /** Serve the worker's requests from this application, as the primary would; $batch overrides the batch endpoint's answer. */
    private function servePrimary(?callable $batch = null): void
    {
        Http::fake(function (HttpRequest $request) use ($batch) {
            $this->requested[] = $request->url();
            if (str_ends_with($request->url(), '/publish-batch')) {
                if ($batch !== null) {
                    return $batch($request);
                }
                $response = $this->postBatchBody($request->data());
            } else {
                $response = $this->withServerVariables(self::PEER)->postJson('/internal/release-verification/publish', $request->data());
            }

            return Http::response($response->json(), $response->status());
        });
    }

    /** @param array<string, mixed> $body */
    private function postBatchBody(array $body): TestResponse
    {
        return $this->withServerVariables(self::PEER)->postJson('/internal/release-verification/publish-batch', $body);
    }

    private function batchRequests(): int
    {
        return count(array_filter($this->requested, static fn (string $url): bool => str_ends_with($url, '/publish-batch')));
    }

    public function test_publish_many_sends_one_request_and_maps_each_outcome_to_its_item(): void
    {
        $this->batchSchema(releases: 1);
        $this->configureWorker();
        $fresh = $this->claimedCandidate('fresh@article');
        $stale = $this->claimedCandidate('stale@article');
        $filtered = $this->claimedCandidate('filtered@article', groupId: 2);
        $staleClaim = (object) ['id' => $stale->id, 'version' => $stale->version, 'manifest_hash' => $stale->manifest_hash, 'lease_token' => 'lost'];
        $items = [new RemotePublishItem($fresh, $this->sampled($fresh)), new RemotePublishItem($staleClaim, $this->sampled($stale)),
            new RemotePublishItem($filtered, $this->sampled($filtered)), new RemotePublishItem($fresh, new VerificationResult('unknown', 'Not verified.')),
            new RemotePublishItem($fresh, new VerificationResult('complete', 'Not spot-checked.'))];
        $this->servePrimary();

        $outcomes = app(RemoteReleasePublisher::class)->publishMany($items);

        $this->assertSame([self::BATCH], $this->requested);
        $this->assertSame(['published', 'stale', 'filtered', 'no_release', 'error'], array_map(static fn (PublicationOutcome $outcome): string => $outcome->status, $outcomes));
        $releaseId = (int) DB::table('release_verification_candidates')->where('id', $fresh->id)->value('release_id');
        $this->assertGreaterThan(0, $releaseId);
        $this->assertSame($releaseId, $outcomes[0]->releaseId);
        $this->assertTrue($outcomes[0]->published());
        $this->assertSame('Stale verification result cannot publish.', $outcomes[1]->error);
        $this->assertNull($outcomes[2]->releaseId);
        $this->assertSame('Only spot-checked results can be published by a remote worker.', $outcomes[4]->error);
        $this->assertSame('verifying', DB::table('release_verification_candidates')->where('id', $stale->id)->value('status'));
        // Only the three publishable items travelled, with the batch protocol.
        $this->assertSame(1, $this->batchRequests());
        $sent = Http::recorded()[0][0]->data();
        $this->assertSame(1, $sent['protocol']);
        $this->assertCount(3, $sent['items']);
        $this->assertArrayNotHasKey('files', $sent['items'][0]['evidence']);
    }

    public function test_resending_a_batch_returns_the_same_outcomes_and_publishes_nothing_twice(): void
    {
        $this->batchSchema(releases: 1);
        $this->configureWorker();
        $claim = $this->claimedCandidate('fresh@article');
        $items = [new RemotePublishItem($claim, $this->sampled($claim))];
        $this->servePrimary();

        $first = app(RemoteReleasePublisher::class)->publishMany($items);
        $again = app(RemoteReleasePublisher::class)->publishMany($items);

        $this->assertEquals($first, $again);
        $this->assertSame(1, DB::table('releases')->count());
        $this->assertSame(2, $this->batchRequests());
    }

    public function test_a_batch_response_without_an_items_result_is_an_error_for_that_item_only(): void
    {
        $this->batchSchema();
        $this->configureWorker();
        $first = $this->claimedCandidate('one@article');
        $second = $this->claimedCandidate('two@article');
        $items = [new RemotePublishItem($first, $this->sampled($first)), new RemotePublishItem($second, $this->sampled($second))];
        $this->servePrimary(fn () => Http::response(['protocol' => 1, 'results' => [
            ['index' => 0, 'claim_id' => (int) $first->id, 'claim_version' => (int) $first->version, 'status' => 'published', 'release_id' => 41, 'error' => null],
            // A result naming another claim is never trusted for this item.
            ['index' => 1, 'claim_id' => 999, 'claim_version' => 1, 'status' => 'published', 'release_id' => 42, 'error' => null],
        ]]));

        $outcomes = app(RemoteReleasePublisher::class)->publishMany($items);

        $this->assertSame(['published', 'error'], array_map(static fn (PublicationOutcome $outcome): string => $outcome->status, $outcomes));
        $this->assertSame(41, $outcomes[0]->releaseId);
        $this->assertNull($outcomes[1]->releaseId);
    }

    /** @return array<string, array{int}> */
    public static function unavailableStatuses(): array
    {
        return ['not found' => [404], 'method not allowed' => [405]];
    }

    #[DataProvider('unavailableStatuses')]
    public function test_a_primary_without_the_batch_endpoint_falls_back_to_single_requests_for_ten_minutes(int $status): void
    {
        $this->batchSchema(releases: 2);
        $this->configureWorker();
        $first = $this->claimedCandidate('one@article');
        $second = $this->claimedCandidate('two@article');
        $items = [new RemotePublishItem($first, $this->sampled($first)), new RemotePublishItem($second, $this->sampled($second))];
        $this->servePrimary(fn () => Http::response(['message' => 'Not found.'], $status));
        $publisher = app(RemoteReleasePublisher::class);
        $this->assertTrue($publisher->batchAvailable());

        $outcomes = $publisher->publishMany($items);

        $this->assertSame([self::BATCH, self::SINGLE, self::SINGLE], $this->requested);
        $this->assertSame(['published', 'published'], array_map(static fn (PublicationOutcome $outcome): string => $outcome->status, $outcomes));
        $this->assertSame(2, DB::table('releases')->count());
        $this->assertFalse($publisher->batchAvailable());
        // Within ten minutes the batch endpoint is not tried again; resending is idempotent through the single endpoint.
        $this->travel(9)->minutes();
        $again = $publisher->publishMany($items);
        $this->assertSame([self::BATCH, self::SINGLE, self::SINGLE, self::SINGLE, self::SINGLE], $this->requested);
        $this->assertEquals($outcomes, $again);
        $this->travel(2)->minutes();
        $this->assertTrue($publisher->batchAvailable());
        $publisher->publishMany($items);
        $this->assertSame(2, $this->batchRequests());
        $this->assertSame(2, DB::table('releases')->count());
    }

    public function test_a_protocol_mismatch_falls_back_to_single_requests(): void
    {
        $this->batchSchema(releases: 1);
        $this->configureWorker();
        $claim = $this->claimedCandidate('one@article');
        $this->servePrimary(fn () => Http::response(['error' => 'Unsupported batch protocol.', 'supported_protocol' => 2], 422));
        $publisher = app(RemoteReleasePublisher::class);

        $outcomes = $publisher->publishMany([new RemotePublishItem($claim, $this->sampled($claim))]);

        $this->assertSame([self::BATCH, self::SINGLE], $this->requested);
        $this->assertTrue($outcomes[0]->published());
        $this->assertFalse($publisher->batchAvailable());
    }

    public function test_a_real_primary_refusing_the_protocol_triggers_the_fallback(): void
    {
        $this->batchSchema(releases: 1);
        $this->configureWorker();
        $claim = $this->claimedCandidate('one@article');
        // The worker speaks protocol 1; a primary that no longer does answers 422 with the protocol it supports.
        $this->servePrimary(fn (HttpRequest $request) => Http::response($this->postBatchBody(['protocol' => 2] + $request->data())->json(), 422));

        $outcomes = app(RemoteReleasePublisher::class)->publishMany([new RemotePublishItem($claim, $this->sampled($claim))]);

        $this->assertSame([self::BATCH, self::SINGLE], $this->requested);
        $this->assertTrue($outcomes[0]->published());
    }

    public function test_a_batch_over_the_primarys_limits_goes_single_without_disabling_the_endpoint(): void
    {
        $this->batchSchema(releases: 3);
        $this->configureWorker();
        config(['release_verification.publish_batch_max_items' => 2]);
        $claims = [$this->claimedCandidate('one@article'), $this->claimedCandidate('two@article'), $this->claimedCandidate('three@article')];
        $items = array_map(fn (object $claim): RemotePublishItem => new RemotePublishItem($claim, $this->sampled($claim)), $claims);
        $this->servePrimary();
        $publisher = app(RemoteReleasePublisher::class);

        $outcomes = $publisher->publishMany($items);

        $this->assertSame([self::BATCH, self::SINGLE, self::SINGLE, self::SINGLE], $this->requested);
        $this->assertSame(['published', 'published', 'published'], array_map(static fn (PublicationOutcome $outcome): string => $outcome->status, $outcomes));
        $this->assertTrue($publisher->batchAvailable());
    }

    public function test_a_failing_batch_request_throws_and_does_not_disable_the_endpoint(): void
    {
        $this->batchSchema();
        $this->configureWorker();
        $claim = $this->claimedCandidate('one@article');
        $this->servePrimary(fn () => Http::response(['error' => 'Database unavailable.'], 500));
        $publisher = app(RemoteReleasePublisher::class);

        try {
            $publisher->publishMany([new RemotePublishItem($claim, $this->sampled($claim))]);
            $this->fail('An unusable batch answer must be reported, not guessed at.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Remote batch publication failed: Database unavailable.', $error->getMessage());
        }

        $this->assertSame([self::BATCH], $this->requested);
        $this->assertTrue($publisher->batchAvailable());
    }

    public function test_without_a_batch_url_publish_many_uses_the_single_endpoint_alone(): void
    {
        $this->batchSchema(releases: 1);
        $this->configureWorker(batch: false);
        $claim = $this->claimedCandidate('one@article');
        $this->servePrimary();
        $publisher = app(RemoteReleasePublisher::class);

        $outcomes = $publisher->publishMany([new RemotePublishItem($claim, $this->sampled($claim))]);

        $this->assertFalse($publisher->batchAvailable());
        $this->assertSame([self::SINGLE], $this->requested);
        $this->assertTrue($outcomes[0]->published());
    }

    public function test_the_batch_round_trip_is_timed_once_with_its_size(): void
    {
        config(['release_verification.publication_timing_sample_every' => 1]);
        $this->batchSchema(releases: 2);
        $this->configureWorker();
        $claims = [$this->claimedCandidate('one@article'), $this->claimedCandidate('two@article')];
        $this->servePrimary();
        $trips = [];
        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('info')->andReturnUsing(static function (string $message, array $context) use (&$trips): void {
            if ($message === 'Remote publication round trip timing.') {
                $trips[] = $context;
            }
        });

        app(RemoteReleasePublisher::class)->publishMany(array_map(fn (object $claim): RemotePublishItem => new RemotePublishItem($claim, $this->sampled($claim)), $claims));

        $this->assertCount(1, $trips);
        $this->assertSame(['status', 'http_status', 'round_trip_ns', 'batch_size'], array_keys($trips[0]));
        $this->assertSame(2, $trips[0]['batch_size']);
        $this->assertSame(200, $trips[0]['http_status']);
    }
}
