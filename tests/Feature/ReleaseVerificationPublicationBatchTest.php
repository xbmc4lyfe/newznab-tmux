<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Releases\Verification\ManifestStore;
use App\Services\Releases\Verification\RemotePublicationService;
use App\Services\Releases\Verification\VerificationWorkerLocks;
use App\Services\Releases\Verification\VerifiedReleasePublisher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PublicationBatchFixtures;

class ReleaseVerificationPublicationBatchTest extends ReleaseVerificationCandidatesTest
{
    use PublicationBatchFixtures;

    private const string URI = '/internal/release-verification/publish-batch';

    public function test_a_mixed_batch_reports_each_item_without_affecting_the_others(): void
    {
        $this->batchSchema(releases: 2);
        $replayed = $this->claimedCandidate('replayed@article');
        $this->withServerVariables(self::PEER)->postJson('/internal/release-verification/publish', $this->item($replayed))->assertOk();
        $replayedRelease = (int) $this->candidateRow($replayed)->release_id;
        $fresh = $this->claimedCandidate('shared@article');
        $stale = $this->claimedCandidate('stale@article');
        $filtered = $this->claimedCandidate('filtered@article', groupId: 2);
        $covered = $this->claimedCandidate('shared@article');
        $conflict = $this->claimedCandidate('shared@article', manifest: ['passwords' => ['asserted by the uploader']]);
        $this->assertLedgerMatchesCandidates();
        $items = [$this->item($fresh), $this->item($stale, 'not-the-lease'), $this->item($filtered), $this->item($covered),
            $this->item($conflict), ['claim' => ['id' => 'abc'], 'reason' => 'x'], $this->item($replayed)];

        $response = $this->postBatch($items)->assertOk();

        $results = $response->json('results');
        $this->assertSame(1, $response->json('protocol'));
        $this->assertSame(range(0, 6), array_column($results, 'index'));
        $releaseId = (int) $this->candidateRow($fresh)->release_id;
        $this->assertGreaterThan(0, $releaseId);
        $this->assertSame([
            ['index' => 0, 'claim_id' => (int) $fresh->id, 'claim_version' => (int) $fresh->version, 'status' => 'published', 'release_id' => $releaseId, 'error' => null],
            ['index' => 1, 'claim_id' => (int) $stale->id, 'claim_version' => (int) $stale->version, 'status' => 'stale', 'release_id' => null, 'error' => 'Stale verification result cannot publish.'],
            ['index' => 2, 'claim_id' => (int) $filtered->id, 'claim_version' => (int) $filtered->version, 'status' => 'filtered', 'release_id' => null, 'error' => null],
            ['index' => 3, 'claim_id' => (int) $covered->id, 'claim_version' => (int) $covered->version, 'status' => 'published', 'release_id' => $releaseId, 'error' => null],
            ['index' => 4, 'claim_id' => (int) $conflict->id, 'claim_version' => (int) $conflict->version, 'status' => 'metadata_review', 'release_id' => null, 'error' => null],
            ['index' => 5, 'claim_id' => null, 'claim_version' => null, 'status' => 'invalid', 'release_id' => null, 'error' => $results[5]['error']],
            ['index' => 6, 'claim_id' => (int) $replayed->id, 'claim_version' => (int) $replayed->version, 'status' => 'published', 'release_id' => $replayedRelease, 'error' => null],
        ], $results);
        $this->assertStringContainsString('claim.id', $results[5]['error']);
        // Each candidate ended where its own item said, and nothing leaked between them.
        $this->assertSame('sampled', $this->candidateRow($fresh)->status);
        $this->assertSame($stale->lease_token, $this->candidateRow($stale)->lease_token);
        $this->assertSame('verifying', $this->candidateRow($stale)->status);
        $this->assertSame('filtered', $this->candidateRow($filtered)->status);
        $this->assertSame($releaseId, (int) $this->candidateRow($covered)->release_id);
        $conflicted = $this->candidateRow($conflict);
        $this->assertSame('metadata_review', $conflicted->status);
        $this->assertNull($conflicted->release_id);
        $this->assertNotSame('', $conflicted->manifest_path);
        $this->assertSame(2, DB::table('releases')->count());
        $this->assertSame(2, $this->installedNzbs());
        $this->assertLedgerMatchesCandidates();
    }

    public function test_resending_a_whole_batch_creates_no_second_release_or_nzb(): void
    {
        $this->batchSchema(releases: 1);
        $fresh = $this->claimedCandidate('shared@article');
        $stale = $this->claimedCandidate('stale@article');
        $filtered = $this->claimedCandidate('filtered@article', groupId: 2);
        $covered = $this->claimedCandidate('shared@article');
        $conflict = $this->claimedCandidate('shared@article', manifest: ['passwords' => ['asserted by the uploader']]);
        $items = [$this->item($fresh), $this->item($stale, 'not-the-lease'), $this->item($filtered), $this->item($covered), $this->item($conflict), []];
        $first = $this->postBatch($items)->assertOk()->json('results');
        $rows = DB::table('release_verification_candidates')->orderBy('id')->get()->all();

        $again = $this->postBatch($items)->assertOk()->json('results');

        $this->assertSame($first, $again);
        $this->assertEquals($rows, DB::table('release_verification_candidates')->orderBy('id')->get()->all());
        $this->assertSame(1, DB::table('releases')->count());
        $this->assertSame(1, $this->installedNzbs());
        $this->assertLedgerMatchesCandidates();
    }

    public function test_a_lost_response_is_recovered_by_resending_only_what_is_missing(): void
    {
        $this->batchSchema(releases: 2);
        $first = $this->claimedCandidate('first@article');
        $second = $this->claimedCandidate('second@article');
        $this->postBatch([$this->item($first)])->assertOk();

        $results = $this->postBatch([$this->item($first), $this->item($second)])->assertOk()->json('results');

        $this->assertSame(['published', 'published'], array_column($results, 'status'));
        $this->assertNotSame($results[0]['release_id'], $results[1]['release_id']);
        $this->assertSame(2, DB::table('releases')->count());
    }

    public function test_items_not_started_when_the_time_budget_ends_answer_retry_without_side_effects(): void
    {
        $this->batchSchema(releases: 3);
        config(['release_verification.publish_batch_seconds' => 15]);
        $claims = [$this->claimedCandidate('one@article'), $this->claimedCandidate('two@article'), $this->claimedCandidate('three@article')];
        $before = [DB::table('release_verification_candidates')->where('id', $claims[1]->id)->first(), DB::table('release_verification_candidates')->where('id', $claims[2]->id)->first()];
        $now = 1000.0;
        $this->app->instance(RemotePublicationService::class, new RemotePublicationService(app(VerifiedReleasePublisher::class),
            app(ManifestStore::class), static function () use (&$now): float {
                return $now;
            }));
        $advanced = false;
        // The first publication takes twenty seconds on the injected monotonic clock.
        DB::listen(static function (QueryExecuted $query) use (&$advanced, &$now): void {
            if (! $advanced && str_starts_with($query->sql, 'insert into "releases"')) {
                $advanced = true;
                $now += 20;
            }
        });

        $results = $this->postBatch(array_map($this->item(...), $claims))->assertOk()->json('results');

        $this->assertSame(['published', 'retry', 'retry'], array_column($results, 'status'));
        $this->assertNull($results[1]['release_id']);
        $this->assertEquals($before, [$this->candidateRow($claims[1]), $this->candidateRow($claims[2])]);
        $this->assertSame(1, DB::table('releases')->count());
        $this->assertSame(1, $this->installedNzbs());
        $this->assertLedgerMatchesCandidates();
        // The worker resends what was not started; the first item is already published.
        $again = $this->postBatch(array_map($this->item(...), $claims))->assertOk()->json('results');
        $this->assertSame(['published', 'published', 'published'], array_column($again, 'status'));
        $this->assertSame($results[0]['release_id'], $again[0]['release_id']);
        $this->assertSame(3, DB::table('releases')->count());
    }

    public function test_a_zero_budget_still_starts_the_first_item(): void
    {
        $this->batchSchema(releases: 1);
        config(['release_verification.publish_batch_seconds' => 0]);
        $claims = [$this->claimedCandidate('one@article'), $this->claimedCandidate('two@article')];

        $results = $this->postBatch(array_map($this->item(...), $claims))->assertOk()->json('results');

        $this->assertSame(['published', 'retry'], array_column($results, 'status'));
        $this->assertSame('verifying', $this->candidateRow($claims[1])->status);
    }

    public function test_a_pause_written_mid_batch_stops_the_remaining_items(): void
    {
        $this->batchSchema(releases: 3);
        $claims = [$this->claimedCandidate('one@article'), $this->claimedCandidate('two@article'), $this->claimedCandidate('three@article')];
        $second = $this->candidateRow($claims[1]);
        $paused = false;
        // A deployment barrier writes its pause right after the first item commits.
        DB::listen(static function (QueryExecuted $query) use (&$paused, $claims): void {
            if (! $paused && DB::transactionLevel() === 0 && str_contains($query->sql, '"release_id" is not null') && $query->bindings[0] === (int) $claims[1]->id) {
                $paused = true;
                VerificationWorkerLocks::pause('deploy', 'deployment');
            }
        });

        $results = $this->postBatch(array_map($this->item(...), $claims))->assertOk()->json('results');

        $this->assertTrue($paused);
        $this->assertSame(['published', 'paused', 'paused'], array_column($results, 'status'));
        $this->assertSame('Verification is paused for exclusive maintenance; retry later.', $results[1]['error']);
        $this->assertEquals($second, $this->candidateRow($claims[1]));
        $this->assertSame(1, DB::table('releases')->count());
        // Resent after the pause is lifted, the remainder publishes and the first is not repeated.
        VerificationWorkerLocks::unpause('deploy');
        $again = $this->postBatch(array_map($this->item(...), $claims))->assertOk()->json('results');
        $this->assertSame(['published', 'published', 'published'], array_column($again, 'status'));
        $this->assertSame($results[0]['release_id'], $again[0]['release_id']);
        $this->assertSame(3, DB::table('releases')->count());
    }

    public function test_an_exclusive_maintenance_holder_pauses_the_whole_batch_but_replays_still_resolve(): void
    {
        $this->batchSchema(releases: 1);
        $published = $this->claimedCandidate('one@article');
        $pending = $this->claimedCandidate('two@article');
        $this->postBatch([$this->item($published)])->assertOk();
        $exclusive = new VerificationWorkerLocks;
        try {
            $this->assertTrue($exclusive->tryExclusive());

            $results = $this->postBatch([$this->item($pending), $this->item($published)])->assertOk()->json('results');
        } finally {
            $exclusive->close();
        }

        $this->assertSame(['paused', 'published'], array_column($results, 'status'));
        $this->assertSame('verifying', $this->candidateRow($pending)->status);
        $this->assertSame(1, DB::table('releases')->count());
    }

    public function test_the_item_count_limit_is_enforced_and_capped(): void
    {
        $this->batchSchema();
        $claims = [$this->claimedCandidate('one@article'), $this->claimedCandidate('two@article'), $this->claimedCandidate('three@article')];
        config(['release_verification.publish_batch_max_items' => 2]);

        $this->postBatch(array_map($this->item(...), $claims))->assertStatus(422)->assertJson(['max_items' => 2]);
        $this->assertSame(0, DB::table('releases')->count());
        $this->assertSame(3, DB::table('release_verification_candidates')->where('status', 'verifying')->count());

        // No configuration raises the cap past 64.
        config(['release_verification.publish_batch_max_items' => 1000]);
        $this->postBatch(array_fill(0, 65, []))->assertStatus(422)->assertJson(['max_items' => 64]);
        $accepted = $this->postBatch(array_fill(0, 64, []))->assertOk()->json('results');
        $this->assertCount(64, $accepted);
        $this->assertSame(['invalid'], array_values(array_unique(array_column($accepted, 'status'))));
    }

    public function test_the_body_size_limit_checks_the_declared_length_and_the_real_body(): void
    {
        $this->batchSchema();
        $claim = $this->claimedCandidate('one@article');
        config(['release_verification.publish_batch_max_bytes' => 300]);

        $this->postBatch([$this->item($claim)])->assertStatus(413)->assertJson(['max_bytes' => 300]);

        config(['release_verification.publish_batch_max_bytes' => 100000]);
        $this->postBatch([$this->item($claim)], ['CONTENT_LENGTH' => '5000000'])->assertStatus(413);
        $this->assertSame(0, DB::table('releases')->count());
        $this->assertSame('verifying', $this->candidateRow($claim)->status);
    }

    public function test_a_malformed_top_level_is_refused_with_422(): void
    {
        $this->batchSchema(releases: 1);
        $item = $this->item($this->claimedCandidate('one@article'));
        $post = fn (array $body) => $this->withServerVariables(self::PEER)->postJson(self::URI, $body);

        $post(['protocol' => 2, 'items' => [$item]])->assertStatus(422)->assertJson(['supported_protocol' => 1]);
        $post(['items' => [$item]])->assertStatus(422)->assertJson(['supported_protocol' => 1]);
        $post(['protocol' => 1])->assertStatus(422);
        $post(['protocol' => 1, 'items' => []])->assertStatus(422);
        $post(['protocol' => 1, 'items' => 'x'])->assertStatus(422);
        $post(['protocol' => 1, 'items' => ['first' => $item]])->assertStatus(422);
        $this->assertSame(0, DB::table('releases')->count());

        // A malformed item is that item's problem only.
        $results = $post(['protocol' => 1, 'items' => ['not an object', $item]])->assertOk()->json('results');
        $this->assertSame(['invalid', 'published'], array_column($results, 'status'));
    }

    public function test_the_network_restriction_and_enable_switch_apply_to_the_batch_endpoint(): void
    {
        $this->batchSchema(releases: 1);
        $item = $this->item($this->claimedCandidate('one@article'));
        $body = ['protocol' => 1, 'items' => [$item]];

        config(['release_verification.publish_endpoint' => false]);
        $this->withServerVariables(self::PEER)->postJson(self::URI, $body)->assertNotFound();
        config(['release_verification.publish_endpoint' => true]);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->postJson(self::URI, $body)->assertForbidden();
        config(['release_verification.publish_allowed_networks' => '']);
        $this->withServerVariables(self::PEER)->postJson(self::URI, $body)->assertForbidden();
        $this->assertSame(0, DB::table('releases')->count());
        config(['release_verification.publish_allowed_networks' => '192.168.0.0/16']);
        $this->withServerVariables(self::PEER)->postJson(self::URI, $body)->assertOk();
        $this->assertSame(1, DB::table('releases')->count());
    }

    public function test_sampled_batches_report_each_published_item_with_its_batch_position(): void
    {
        config(['release_verification.publication_timing_sample_every' => 1]);
        $this->batchSchema(releases: 2);
        $claims = [$this->claimedCandidate('one@article'), $this->claimedCandidate('two@article')];
        $reports = [];
        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('info')->andReturnUsing(static function (string $message, array $context) use (&$reports): void {
            $reports[$message][] = $context;
        });

        $this->postBatch([$this->item($claims[0]), ['claim' => ['id' => 'abc']], $this->item($claims[1])])->assertOk();

        $timings = $reports['Verified release publication timing.'];
        $this->assertCount(2, $timings);
        $this->assertSame([[3, 0], [3, 2]], array_map(static fn (array $report): array => [$report['batch_size'], $report['batch_index']], $timings));
        foreach ($timings as $report) {
            $this->assertSame('remote', $report['source']);
            $this->assertSame('published', $report['outcome']);
            $this->assertSame(['validation', 'published_check', 'candidate_read', 'manifest_read', 'worker_lock'], array_keys($report['request_phases_ns']));
            $this->assertIsInt($report['pre_lock_ns']);
        }
    }

    public function test_the_single_endpoint_reports_no_batch_fields(): void
    {
        config(['release_verification.publication_timing_sample_every' => 1]);
        $this->batchSchema(releases: 1);
        $claim = $this->claimedCandidate('one@article');
        $reports = [];
        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('info')->andReturnUsing(static function (string $message, array $context) use (&$reports): void {
            $reports[] = $context;
        });

        $this->withServerVariables(self::PEER)->postJson('/internal/release-verification/publish', $this->item($claim))->assertOk();

        $this->assertCount(1, $reports);
        $this->assertArrayNotHasKey('batch_size', $reports[0]);
        $this->assertArrayNotHasKey('batch_index', $reports[0]);
    }

    public function test_a_filtered_claim_is_still_stale_on_the_single_endpoint(): void
    {
        $this->batchSchema();
        $filtered = $this->claimedCandidate('filtered@article', groupId: 2);
        $this->postBatch([$this->item($filtered)])->assertOk();

        // Only the batch endpoint replays a terminal status; the single endpoint's contract is unchanged.
        $this->withServerVariables(self::PEER)->postJson('/internal/release-verification/publish', $this->item($filtered))
            ->assertStatus(409)->assertJson(['error' => 'Stale verification result cannot publish.']);
    }

    /** @return array<string, array{?int, float, ?int}> */
    public static function timeLimits(): array
    {
        return [
            'unlimited stays unlimited' => [0, 15.0, null],
            'a higher limit is never lowered' => [300, 15.0, null],
            'an equal limit is left alone' => [75, 15.0, null],
            'a lower limit is raised to cover the budget and one item' => [30, 15.0, 75],
            'a fractional budget rounds up' => [10, 14.2, 75],
        ];
    }

    #[DataProvider('timeLimits')]
    public function test_the_batch_only_ever_raises_the_execution_limit(int $current, float $budget, ?int $expected): void
    {
        $this->assertSame($expected, RemotePublicationService::raisedTimeLimit($current, $budget));
    }

    public function test_a_batch_request_does_not_lower_the_hosts_execution_limit(): void
    {
        $this->batchSchema(releases: 2);
        $claims = [$this->claimedCandidate('one@article'), $this->claimedCandidate('two@article')];
        $original = (string) ini_get('max_execution_time');
        try {
            ini_set('max_execution_time', '300');
            $this->postBatch([$this->item($claims[0])])->assertOk();
            $this->assertSame('300', ini_get('max_execution_time'));

            ini_set('max_execution_time', '30');
            $this->postBatch([$this->item($claims[1])])->assertOk();
            $this->assertSame('75', ini_get('max_execution_time'));
        } finally {
            ini_set('max_execution_time', $original);
        }
    }

    /** @return array<string, array{string}> */
    public static function terminalStatuses(): array
    {
        return ['filtered' => ['filtered'], 'sent to metadata review' => ['metadata_review']];
    }

    #[DataProvider('terminalStatuses')]
    public function test_a_resend_overlapping_a_first_attempt_that_resolved_the_item_gets_that_status(string $status): void
    {
        $this->batchSchema();
        $claim = $this->claimedCandidate('one@article');
        $raced = false;
        // The first attempt resolves the candidate right after this request's candidate read.
        DB::listen(static function (QueryExecuted $query) use (&$raced, $claim, $status): void {
            if (! $raced && DB::transactionLevel() === 0 && str_starts_with($query->sql, 'select * from "release_verification_candidates" where "id" = ?')
                && $query->bindings[0] === (int) $claim->id) {
                $raced = true;
                DB::table('release_verification_candidates')->where('id', $claim->id)
                    ->update(['status' => $status, 'lease_token' => null, 'lease_until' => null]);
            }
        });

        $result = $this->postBatch([$this->item($claim)])->assertOk()->json('results.0');

        $this->assertTrue($raced);
        $this->assertSame($status, $result['status']);
        $this->assertNull($result['release_id']);
        $this->assertSame(0, DB::table('releases')->count());
    }

    public function test_the_single_endpoint_keeps_reporting_an_overlapped_resolution_as_stale(): void
    {
        $this->batchSchema();
        $claim = $this->claimedCandidate('one@article');
        $raced = false;
        DB::listen(static function (QueryExecuted $query) use (&$raced, $claim): void {
            if (! $raced && DB::transactionLevel() === 0 && str_starts_with($query->sql, 'select * from "release_verification_candidates" where "id" = ?')
                && $query->bindings[0] === (int) $claim->id) {
                $raced = true;
                DB::table('release_verification_candidates')->where('id', $claim->id)
                    ->update(['status' => 'filtered', 'lease_token' => null, 'lease_until' => null]);
            }
        });

        $this->withServerVariables(self::PEER)->postJson('/internal/release-verification/publish', $this->item($claim))
            ->assertStatus(409)->assertJson(['error' => 'Stale verification result cannot publish.']);
        $this->assertTrue($raced);
    }

    public function test_the_single_endpoint_reads_nothing_more_after_a_filtered_publication(): void
    {
        $this->batchSchema();
        $claim = $this->claimedCandidate('filtered@article', groupId: 2);
        $statusReads = 0;
        DB::listen(static function (QueryExecuted $query) use (&$statusReads): void {
            $statusReads += str_contains($query->sql, '"status" in (') ? 1 : 0;
        });

        $this->withServerVariables(self::PEER)->postJson('/internal/release-verification/publish', $this->item($claim))
            ->assertOk()->assertExactJson(['release_id' => null]);

        $this->assertSame(0, $statusReads);
        $this->assertSame('filtered', $this->candidateRow($claim)->status);
    }
}
