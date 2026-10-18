<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\NNTP\NNTPService;
use App\Services\Releases\Verification\ManifestStore;
use App\Services\Releases\Verification\PublicationOutbox;
use App\Services\Releases\Verification\PublicationOutboxEntry;
use App\Services\Releases\Verification\PublicationOutcome;
use App\Services\Releases\Verification\RemotePublicationService;
use App\Services\Releases\Verification\RemotePublishItem;
use App\Services\Releases\Verification\RemoteReleasePublisher;
use App\Services\Releases\Verification\SpotCheckVerifier;
use App\Services\Releases\Verification\VerificationWorkerLocks;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Tests\Fixtures\YencArticles;
use Tests\Support\PublicationBatchFixtures;

/**
 * The per-host publication outbox: remote workers hand verified results to it, and forwarder
 * processes publish them to the primary (served in-process through Http::fake) in batches.
 */
class PublicationOutboxTest extends ReleaseVerificationCandidatesTest
{
    use PublicationBatchFixtures;

    private const string SINGLE = 'http://primary.test/internal/release-verification/publish';

    private const string BATCH = 'http://primary.test/internal/release-verification/publish-batch';

    /** @var list<string> */
    private array $requested = [];

    /** @var list<int> */
    private array $batchSizes = [];

    private function configureAsync(bool $batch = true, bool $async = true): void
    {
        config(['release_verification.publish_url' => self::SINGLE, 'release_verification.publish_batch_url' => $batch ? self::BATCH : null,
            'release_verification.worker_only' => true, 'release_verification.publish_async' => $async,
            'release_verification.spot_check_articles' => 1, 'release_verification.free_bytes' => 0]);
        $this->requested = [];
        $this->batchSizes = [];
    }

    /** Serve the forwarder's requests from this application, as the primary would; $batch overrides the batch answer. */
    private function servePrimary(?callable $batch = null): void
    {
        Http::fake(function (HttpRequest $request) use ($batch) {
            $this->requested[] = $request->url();
            if (str_ends_with($request->url(), '/publish-batch')) {
                $this->batchSizes[] = count($request->data()['items']);
                if ($batch !== null) {
                    return $batch($request);
                }
                $response = $this->withServerVariables(self::PEER)->postJson('/internal/release-verification/publish-batch', $request->data());
            } else {
                $response = $this->withServerVariables(self::PEER)->postJson('/internal/release-verification/publish', $request->data());
            }

            return Http::response($response->json(), $response->status());
        });
    }

    /** A batch answer giving every item the same status. */
    private function answerAll(HttpRequest $request, string $status, ?string $error = null): mixed
    {
        $results = [];
        foreach ($request->data()['items'] as $index => $item) {
            $results[] = ['index' => $index, 'claim_id' => $item['claim']['id'], 'claim_version' => $item['claim']['version'],
                'status' => $status, 'release_id' => null, 'error' => $error];
        }

        return Http::response(['protocol' => 1, 'results' => $results]);
    }

    private function outbox(): PublicationOutbox
    {
        return new PublicationOutbox;
    }

    private function handOff(object $claim): string
    {
        return $this->outbox()->enqueue($claim, 'Sampled.', $this->evidence());
    }

    private function pendingPath(object $claim): string
    {
        return $this->outbox()->directory().'/pending/'.PublicationOutboxEntry::nameFor((int) $claim->id, (int) $claim->version);
    }

    private function inflightPath(object $claim, int $slot = 1): string
    {
        return $this->outbox()->inflightDirectory($slot).'/'.PublicationOutboxEntry::nameFor((int) $claim->id, (int) $claim->version);
    }

    /** @return array<string, mixed> */
    private function entryDocument(string $path): array
    {
        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $change */
    private function changeEntry(string $path, callable $change): void
    {
        file_put_contents($path, json_encode($change($this->entryDocument($path)), JSON_THROW_ON_ERROR));
    }

    private function markForwarderAlive(int $slot = 1, int $ageSeconds = 0): void
    {
        $this->outbox()->prepare();
        touch($this->outbox()->directory().'/forwarder-'.$slot.'.alive', time() - $ageSeconds);
    }

    /** @param array<string, mixed> $options */
    private function forward(array $options = []): int
    {
        return Artisan::call('releases:forward-verified-publications', ['--slot' => 1, '--passes' => 1, ...$options]);
    }

    /** @param list<array<string, mixed>> $summaries */
    private function captureSummaries(array &$summaries, string $title): void
    {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->andReturnUsing(static function (string $message, array $report) use (&$summaries, $title): void {
            self::assertSame($title, $message);
            $summaries[] = $report;
        });
        Log::shouldReceive('channel')->with('daily')->andReturn($logger);
        Log::shouldReceive('warning')->andReturnNull();
    }

    private function pendingNames(): array
    {
        return array_values(array_filter(@scandir($this->outbox()->directory().'/pending') ?: [], static fn (string $file): bool => str_ends_with($file, '.json')));
    }

    private function candidateStatus(object $claim): string
    {
        return (string) DB::table('release_verification_candidates')->where('id', $claim->id)->value('status');
    }

    // ---- the worker side -------------------------------------------------------------------------------------

    /** A fresh candidate for a worker to claim and spot check against the mocked article. */
    private function workerCandidate(): void
    {
        $manifest = ['identity' => sha1('async-fixture'), 'metadata_source' => 'import', 'subject' => 'Movie', 'fromname' => 'poster',
            'date' => '2026-10-11 12:00:00', 'groups_id' => 1, 'groups' => ['alt.test'], 'expected_files' => 1, 'regex_id' => 0,
            'verified_bytes' => 5, 'verified_file_names' => ['movie.mkv'], 'verified_file_count' => 1,
            'files' => [['key' => 'file', 'subject' => 'movie.mkv', 'totalparts' => 1,
                'segments' => [['number' => 1, 'messageid' => 'verified@article', 'bytes' => 5]]]]];
        $store = app(ManifestStore::class);
        $path = $store->write($manifest);
        $bytes = $store->size($path);
        DB::table('release_verification_candidates')->insert(['identity' => sha1('async-fixture'), 'groups_id' => 1,
            'manifest_path' => $path, 'manifest_hash' => substr($path, 0, 64), 'manifest_bytes' => $bytes,
            'last_unique_at' => now(), 'next_attempt_at' => now()]);
        DB::table('release_verification_state')->update(['pending_bytes' => $bytes]);
        DB::table('release_verification_scratch_budget')->where('id', 1)->update(['initialized' => true]);
        $article = YencArticles::part('hello', 'movie.mkv', 5, 1, 1, 1);
        $this->mock(NNTPService::class, static function ($mock) use ($article): void {
            $mock->shouldReceive('doConnect')->with(false)->andReturn(true);
            $mock->shouldReceive('verificationArticle')->with('verified@article', Mockery::type('int'))->andReturn($article);
            $mock->shouldReceive('doQuit')->andReturn(null);
        });
    }

    /** @return array<string, mixed> the worker run's summary */
    private function runWorker(): array
    {
        $reports = [];
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->andReturnUsing(static function (string $message, array $report) use (&$reports): void {
            $reports[] = $report;
        });
        Log::shouldReceive('channel')->with('daily')->andReturn($logger);
        Log::shouldReceive('warning')->andReturnNull();
        $this->assertSame(0, Artisan::call('releases:verify-candidates', ['--limit' => 1, '--no-summary' => true, '--diagnostics' => true]));
        $this->assertCount(1, $reports);

        return $reports[0];
    }

    public function test_a_worker_hands_a_passing_result_to_the_outbox_and_frees_its_scratch(): void
    {
        $this->batchSchema();
        $this->configureAsync();
        $this->workerCandidate();
        $this->markForwarderAlive();
        Http::fake();

        $report = $this->runWorker();

        Http::assertNothingSent();
        $row = DB::table('release_verification_candidates')->first();
        $this->assertSame('verifying', $row->status);
        $this->assertNull($row->release_id);
        $entries = $this->pendingNames();
        $this->assertSame([PublicationOutboxEntry::nameFor((int) $row->id, (int) $row->version)], $entries);
        $document = $this->entryDocument($this->outbox()->directory().'/pending/'.$entries[0]);
        $this->assertSame(['id' => (int) $row->id, 'version' => (int) $row->version, 'manifest_hash' => $row->manifest_hash, 'lease_token' => $row->lease_token], $document['claim']);
        $this->assertSame(1, $document['evidence']['articles']);
        $this->assertSame(['movie.mkv'], $document['evidence']['verified_file_names']);
        $this->assertIsString($document['reason']);
        $this->assertEqualsWithDelta(microtime(true), $document['enqueued_at'], 30.0);
        // Scratch is gone from disk and from the inventory; the lease was renewed for the forwarder.
        $this->assertSame([], glob(config('release_verification.storage_path').'/scratch/*') ?: []);
        $this->assertSame(0, DB::table('release_verification_scratch')->count());
        $this->assertTrue(Carbon::parse($row->lease_until)->greaterThan(now()->addSeconds(250)));
        $this->assertSame([], glob($this->outbox()->directory().'/pending/.*.tmp') ?: []);
        $this->assertSame(3, $report['schema_version']);
        $this->assertSame(1, $report['handed_off']);
        $this->assertSame(0, $report['publish_returned']);
        $this->assertSame(0, $report['outbox_fallbacks']);
        $this->assertSame(0, $report['candidate_errors']);
    }

    public function test_a_handed_off_result_publishes_through_the_forwarder_exactly_once(): void
    {
        $this->batchSchema(releases: 1);
        $this->configureAsync();
        $this->workerCandidate();
        $this->markForwarderAlive();
        $this->runWorker();
        $this->servePrimary();
        $name = $this->pendingNames()[0];
        $path = $this->outbox()->directory().'/pending/'.$name;
        $document = (string) file_get_contents($path);

        $this->assertSame(0, $this->forward());
        // A crash after the primary answered but before the unlink resends the entry; the primary replays it.
        file_put_contents($path, $document);
        $this->assertSame(0, $this->forward());

        $this->assertSame(1, DB::table('releases')->count());
        $this->assertSame('sampled', DB::table('release_verification_candidates')->value('status'));
        $this->assertSame([], $this->pendingNames());
        $this->assertSame([self::BATCH, self::BATCH], $this->requested);
    }

    public function test_without_a_live_forwarder_the_worker_publishes_synchronously(): void
    {
        $this->batchSchema(releases: 1);
        $this->configureAsync();
        $this->workerCandidate();
        // No marker at all.
        $this->servePrimary();

        $report = $this->runWorker();

        $this->assertSame([self::SINGLE], $this->requested);
        $this->assertSame('sampled', DB::table('release_verification_candidates')->value('status'));
        $this->assertSame(1, DB::table('releases')->count());
        $this->assertSame(0, $report['handed_off']);
        $this->assertSame(1, $report['publish_returned']);
        $this->assertSame(1, $report['outbox_fallbacks']);
        $this->assertSame([], $this->pendingNames());
    }

    public function test_a_stale_forwarder_marker_does_not_count_as_alive(): void
    {
        $this->configureAsync();
        $this->markForwarderAlive(1, 60);
        $this->assertFalse($this->outbox()->forwarderAlive());
        $this->markForwarderAlive(2, 0);
        $this->assertTrue($this->outbox()->forwarderAlive());
        $this->assertFalse($this->outbox()->forwarderAlive(time() + 60));
        $this->assertTrue($this->outbox()->forwarderAlive(time() + 60, 120));
    }

    public function test_a_forwarder_in_the_middle_of_a_batch_round_trip_still_counts_as_alive(): void
    {
        $this->configureAsync();
        // The default window outlasts the primary's batch budget plus the item it has in flight.
        $this->assertSame(30, (int) config('release_verification.publish_forwarder_alive_seconds'));
        $this->assertGreaterThanOrEqual((int) ceil((float) config('release_verification.publish_batch_seconds')) + 10,
            (int) config('release_verification.publish_forwarder_alive_seconds'));
        $this->markForwarderAlive(1, 29);
        $this->assertTrue($this->outbox()->forwarderAlive());
        $this->markForwarderAlive(1, 45);
        $this->assertFalse($this->outbox()->forwarderAlive());
        // The window follows the configuration.
        config(['release_verification.publish_forwarder_alive_seconds' => 60]);
        $this->assertTrue($this->outbox()->forwarderAlive());
    }

    public function test_a_worker_hands_off_while_every_forwarder_is_mid_send(): void
    {
        $this->batchSchema();
        $this->configureAsync();
        $this->workerCandidate();
        // Both forwarders touched their markers before sending a 15 s batch; neither has touched since.
        $this->markForwarderAlive(1, 20);
        $this->markForwarderAlive(2, 25);
        Http::fake();

        $report = $this->runWorker();

        Http::assertNothingSent();
        $this->assertSame(1, $report['handed_off']);
        $this->assertSame(0, $report['outbox_fallbacks']);
    }

    public function test_a_full_outbox_holds_the_worker_with_heartbeats_until_there_is_room(): void
    {
        $this->batchSchema();
        $this->configureAsync();
        config(['release_verification.publish_outbox_max' => 2]);
        $this->markForwarderAlive();
        $first = $this->claimedCandidate('one@article');
        $second = $this->claimedCandidate('two@article');
        $this->handOff($first);
        $this->handOff($second);
        $this->assertSame(2, $this->outbox()->depth());
        $beats = 0;

        $room = $this->outbox()->awaitRoom(function () use (&$beats, $first): void {
            // The forwarder sends one entry while the worker waits.
            if (++$beats === 3) {
                unlink($this->pendingPath($first));
            }
        }, 10.0);

        $this->assertTrue($room);
        $this->assertGreaterThanOrEqual(3, $beats);
        $this->assertSame(1, $this->outbox()->depth());
    }

    public function test_a_full_outbox_sends_the_worker_to_synchronous_publication_after_the_wait(): void
    {
        $this->batchSchema(releases: 1);
        $this->configureAsync();
        config(['release_verification.publish_outbox_max' => 1, 'release_verification.publish_outbox_wait_seconds' => 1]);
        $this->markForwarderAlive();
        $this->handOff($this->claimedCandidate('queued@article'));
        // The occupant is the only entry; the worker's candidate comes after the fixture.
        $this->workerCandidate();
        $this->servePrimary();

        $report = $this->runWorker();

        $this->assertSame([self::SINGLE], $this->requested);
        $this->assertSame(1, $report['publish_returned']);
        $this->assertSame(1, $report['outbox_fallbacks']);
        $this->assertGreaterThanOrEqual(900.0, $report['outbox_wait_ms']);
        $this->assertSame(1, DB::table('releases')->count());
        $this->assertSame(1, $this->outbox()->depth());
    }

    public function test_the_wait_ends_at_once_when_the_forwarder_disappears(): void
    {
        $this->configureAsync();
        config(['release_verification.publish_outbox_max' => 1]);
        $this->markForwarderAlive();
        $this->handOff((object) ['id' => 5, 'version' => 1, 'manifest_hash' => str_repeat('a', 64), 'lease_token' => 'x']);

        $started = microtime(true);
        $room = $this->outbox()->awaitRoom(function (): void {
            unlink($this->outbox()->directory().'/forwarder-1.alive');
        }, 30.0);

        $this->assertFalse($room);
        $this->assertLessThan(5.0, microtime(true) - $started);
    }

    public function test_with_the_flag_off_nothing_is_handed_off_even_with_a_forwarder_marker(): void
    {
        $this->batchSchema(releases: 1);
        $this->configureAsync(async: false);
        $this->workerCandidate();
        $this->markForwarderAlive();
        $this->servePrimary();

        $report = $this->runWorker();

        $this->assertSame([self::SINGLE], $this->requested);
        $this->assertSame('sampled', DB::table('release_verification_candidates')->value('status'));
        $this->assertSame([], $this->pendingNames());
        $this->assertSame(0, $report['handed_off']);
        $this->assertSame(0, $report['outbox_fallbacks']);
        $this->assertSame(1, $report['publish_returned']);
        $this->assertSame([], glob(config('release_verification.storage_path').'/scratch/*') ?: []);
    }

    public function test_the_primary_never_hands_off(): void
    {
        $this->configureAsync();
        config(['release_verification.worker_only' => false]);
        $this->assertFalse(PublicationOutbox::enabled());
        config(['release_verification.worker_only' => true, 'release_verification.publish_url' => '']);
        $this->assertFalse(PublicationOutbox::enabled());
        config(['release_verification.publish_url' => self::SINGLE]);
        $this->assertTrue(PublicationOutbox::enabled());
    }

    public function test_an_outbox_that_cannot_be_written_falls_back_to_synchronous_publication(): void
    {
        $this->batchSchema(releases: 1);
        $this->configureAsync();
        $this->workerCandidate();
        $this->markForwarderAlive();
        // A file where the pending directory belongs: the entry cannot be written.
        rmdir($this->outbox()->directory().'/pending');
        file_put_contents($this->outbox()->directory().'/pending', 'blocked');
        $this->servePrimary();

        $report = $this->runWorker();

        $this->assertSame([self::SINGLE], $this->requested);
        $this->assertSame('sampled', DB::table('release_verification_candidates')->value('status'));
        $this->assertSame(0, $report['handed_off']);
        $this->assertSame(1, $report['outbox_fallbacks']);
        $this->assertSame(1, $report['publish_returned']);
    }

    // ---- the forwarder ---------------------------------------------------------------------------------------

    public function test_a_forwarder_sends_waiting_entries_in_one_batch_and_settles_each_outcome(): void
    {
        $this->batchSchema(releases: 1);
        $this->configureAsync();
        $published = $this->claimedCandidate('fresh@article');
        $filtered = $this->claimedCandidate('filtered@article', groupId: 2);
        $this->handOff($published);
        $this->handOff($filtered);
        $this->servePrimary();
        $summaries = [];
        $this->captureSummaries($summaries, 'Publication forwarder run summary.');

        $this->assertSame(0, $this->forward(['--diagnostics' => true]));

        $this->assertSame([self::BATCH], $this->requested);
        $this->assertSame([2], $this->batchSizes);
        $this->assertSame([], $this->pendingNames());
        $this->assertSame([], glob($this->outbox()->inflightDirectory(1).'/*') ?: []);
        $this->assertSame('sampled', $this->candidateStatus($published));
        $this->assertSame('filtered', $this->candidateStatus($filtered));
        $this->assertSame(1, DB::table('releases')->count());
        $this->assertFileDoesNotExist($this->outbox()->directory().'/forwarder-1.alive');
        $this->assertCount(1, $summaries);
        $this->assertSame(1, $summaries[0]['schema_version']);
        $this->assertSame(1, $summaries[0]['batches_sent']);
        $this->assertSame(2, $summaries[0]['items_sent']);
        $this->assertSame(['min' => 2, 'max' => 2, 'mean' => 2.0], $summaries[0]['items_per_batch']);
        $this->assertSame(1, $summaries[0]['outcomes']['published']);
        $this->assertSame(1, $summaries[0]['outcomes']['filtered']);
        $this->assertSame(0, $summaries[0]['transport_failures']);
        $this->assertGreaterThan(0.0, $summaries[0]['round_trip_ms']['total']);
        $this->assertSame('passes', $summaries[0]['stop_reason']);
        $this->assertStringNotContainsString('lease_token', json_encode($summaries, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('manifest_hash', json_encode($summaries, JSON_THROW_ON_ERROR));
    }

    public function test_a_forwarder_batch_holds_at_most_the_configured_item_count_oldest_first(): void
    {
        $this->batchSchema(releases: 2);
        $this->configureAsync();
        config(['release_verification.publish_forward_items' => 2]);
        $claims = [$this->claimedCandidate('one@article'), $this->claimedCandidate('two@article'), $this->claimedCandidate('three@article')];
        foreach ($claims as $index => $claim) {
            $this->handOff($claim);
            $this->changeEntry($this->pendingPath($claim), static fn (array $document): array => ['enqueued_at' => time() - 100 + $index] + $document);
        }
        $this->servePrimary();

        $this->forward();

        $this->assertSame([2], $this->batchSizes);
        $this->assertSame(['sampled', 'sampled', 'verifying'], array_map($this->candidateStatus(...), $claims));
        $this->assertSame([PublicationOutboxEntry::nameFor((int) $claims[2]->id, (int) $claims[2]->version)], $this->pendingNames());
    }

    /** @return array<string, array{string}> */
    public static function failedStatuses(): array
    {
        return ['stale' => ['stale'], 'invalid' => ['invalid'], 'error' => ['error'], 'paused' => ['paused']];
    }

    #[DataProvider('failedStatuses')]
    public function test_a_failed_outcome_finishes_the_candidate_unknown_and_drops_the_entry(string $status): void
    {
        $this->batchSchema();
        $this->configureAsync();
        $claim = $this->claimedCandidate('one@article');
        $this->handOff($claim);
        $this->servePrimary(fn (HttpRequest $request) => $this->answerAll($request, $status, 'The primary said '.$status.'.'));

        $this->forward();

        $row = $this->candidateRow($claim);
        $this->assertSame('unknown', $row->status);
        $this->assertSame('The primary said '.$status.'.', $row->reason);
        $this->assertNull($row->lease_token);
        $this->assertSame([], $this->pendingNames());
        $this->assertSame([], glob($this->outbox()->inflightDirectory(1).'/*') ?: []);
    }

    public function test_finishing_after_a_stale_answer_is_fenced_by_the_lease_token(): void
    {
        $this->batchSchema();
        $this->configureAsync();
        $claim = $this->claimedCandidate('one@article');
        $this->handOff($claim);
        // A newer attempt now owns the candidate.
        DB::table('release_verification_candidates')->where('id', $claim->id)->update(['lease_token' => 'newer-owner']);
        $this->servePrimary();

        $this->forward();

        $row = $this->candidateRow($claim);
        $this->assertSame('verifying', $row->status);
        $this->assertSame('newer-owner', $row->lease_token);
        $this->assertSame([], $this->pendingNames());
    }

    public function test_a_retry_answer_returns_the_entry_to_pending_with_a_delay_and_it_is_then_sent_again(): void
    {
        $this->batchSchema(releases: 1);
        $this->configureAsync();
        $claim = $this->claimedCandidate('one@article');
        $this->handOff($claim);
        $answer = true;
        $this->servePrimary(function (HttpRequest $request) use (&$answer) {
            if ($answer) {
                $answer = false;

                return $this->answerAll($request, 'retry', 'Batch time budget exhausted; resend this item.');
            }

            return Http::response($this->withServerVariables(self::PEER)->postJson('/internal/release-verification/publish-batch', $request->data())->json());
        });

        $this->forward();

        $document = $this->entryDocument($this->pendingPath($claim));
        $this->assertSame(1, $document['attempts']);
        $this->assertGreaterThan(microtime(true), $document['not_before']);
        $this->assertSame('verifying', $this->candidateStatus($claim));
        $this->assertFileDoesNotExist($this->inflightPath($claim));
        // Not due yet: the next pass sends nothing.
        $this->changeEntry($this->pendingPath($claim), static fn (array $document): array => ['not_before' => time() + 3600] + $document);
        $this->forward();
        $this->assertSame(1, count($this->requested));
        $this->changeEntry($this->pendingPath($claim), static fn (array $document): array => ['not_before' => 0] + $document);
        $this->forward();
        $this->assertSame(2, count($this->requested));
        $this->assertSame('sampled', $this->candidateStatus($claim));
        $this->assertSame([], $this->pendingNames());
    }

    public function test_a_failed_request_returns_every_entry_to_pending_and_a_later_success_publishes_them(): void
    {
        $this->batchSchema(releases: 2);
        $this->configureAsync();
        $claims = [$this->claimedCandidate('one@article'), $this->claimedCandidate('two@article')];
        array_map($this->handOff(...), $claims);
        $failing = true;
        $this->servePrimary(function (HttpRequest $request) use (&$failing) {
            if ($failing) {
                return Http::response(['error' => 'Database unavailable.'], 500);
            }

            return Http::response($this->withServerVariables(self::PEER)->postJson('/internal/release-verification/publish-batch', $request->data())->json());
        });
        $summaries = [];
        $this->captureSummaries($summaries, 'Publication forwarder run summary.');

        $this->forward(['--diagnostics' => true]);

        foreach ($claims as $claim) {
            $this->assertSame(1, $this->entryDocument($this->pendingPath($claim))['attempts']);
            $this->assertSame('verifying', $this->candidateStatus($claim));
        }
        $this->assertSame(1, $summaries[0]['transport_failures']);
        $this->assertSame(2, $summaries[0]['requeued']);
        $failing = false;
        foreach ($claims as $claim) {
            $this->changeEntry($this->pendingPath($claim), static fn (array $document): array => ['not_before' => 0] + $document);
        }
        $this->forward();
        $this->assertSame(['sampled', 'sampled'], array_map($this->candidateStatus(...), $claims));
        $this->assertSame([], $this->pendingNames());
    }

    public function test_a_connection_failure_is_a_retry_too(): void
    {
        Sleep::fake();
        $this->batchSchema();
        $this->configureAsync();
        $claim = $this->claimedCandidate('one@article');
        $this->handOff($claim);
        Http::fake(static fn () => throw new ConnectionException('Connection refused.'));

        $this->forward();

        $this->assertSame(1, $this->entryDocument($this->pendingPath($claim))['attempts']);
        $this->assertSame('verifying', $this->candidateStatus($claim));
    }

    public function test_an_entry_past_the_attempt_limit_is_finished_unknown(): void
    {
        $this->batchSchema();
        $this->configureAsync();
        config(['release_verification.publish_forward_max_attempts' => 3]);
        $claim = $this->claimedCandidate('one@article');
        $this->handOff($claim);
        $this->changeEntry($this->pendingPath($claim), static fn (array $document): array => ['attempts' => 2] + $document);
        $this->servePrimary(fn () => Http::response(['error' => 'Database unavailable.'], 500));

        $this->forward();

        $row = $this->candidateRow($claim);
        $this->assertSame('unknown', $row->status);
        $this->assertStringContainsString('Database unavailable', $row->reason);
        $this->assertSame([], $this->pendingNames());
    }

    public function test_an_entry_older_than_the_age_limit_is_finished_unknown_without_a_request(): void
    {
        $this->batchSchema();
        $this->configureAsync();
        $claim = $this->claimedCandidate('one@article');
        $this->handOff($claim);
        $limit = PublicationOutbox::maxAgeSeconds();
        $this->assertLessThan(300.0, $limit);
        // Even a delayed retry is claimed once it is too old, so it cannot hold its lease forever.
        $this->changeEntry($this->pendingPath($claim), static fn (array $document): array => ['enqueued_at' => microtime(true) - $limit - 5, 'not_before' => time() + 3600] + $document);
        $this->servePrimary();

        $this->forward();

        $this->assertSame([], $this->requested);
        $row = $this->candidateRow($claim);
        $this->assertSame('unknown', $row->status);
        $this->assertNull($row->lease_token);
        $this->assertSame([], $this->pendingNames());
    }

    public function test_an_entry_left_in_flight_by_a_crash_is_adopted_and_sent(): void
    {
        $this->batchSchema(releases: 1);
        $this->configureAsync();
        $claim = $this->claimedCandidate('one@article');
        $this->handOff($claim);
        $this->outbox()->prepare(1);
        rename($this->pendingPath($claim), $this->inflightPath($claim));
        // An earlier send may even have reached the primary before the crash.
        $this->servePrimary();
        $summaries = [];
        $this->captureSummaries($summaries, 'Publication forwarder run summary.');

        $this->forward(['--diagnostics' => true]);

        $this->assertSame('sampled', $this->candidateStatus($claim));
        $this->assertFileDoesNotExist($this->inflightPath($claim));
        $this->assertSame(1, $summaries[0]['adopted']);
        $this->assertSame(1, DB::table('releases')->count());
    }

    public function test_an_unreadable_entry_is_removed_and_the_others_still_go(): void
    {
        $this->batchSchema(releases: 1);
        $this->configureAsync();
        $claim = $this->claimedCandidate('one@article');
        $this->handOff($claim);
        file_put_contents($this->outbox()->directory().'/pending/99-1.json', '{"claim":');
        file_put_contents($this->outbox()->directory().'/pending/.100-1.json.abc.tmp', 'partial');
        $this->servePrimary();

        $this->forward();

        $this->assertSame('sampled', $this->candidateStatus($claim));
        $this->assertFileDoesNotExist($this->outbox()->directory().'/pending/99-1.json');
        $this->assertSame([1], $this->batchSizes);
    }

    public function test_waiting_entries_keep_their_lease_alive_every_third_of_the_lease(): void
    {
        $this->batchSchema();
        $this->configureAsync();
        $fresh = $this->claimedCandidate('fresh@article');
        $due = $this->claimedCandidate('due@article');
        foreach ([$fresh, $due] as $claim) {
            $this->handOff($claim);
            // Not due for sending, so only the lease is in question.
            $this->changeEntry($this->pendingPath($claim), static fn (array $document): array => ['not_before' => time() + 3600] + $document);
        }
        $before = $this->candidateRow($due)->lease_until;
        // The handed-off file is older than a third of the lease for $due only.
        touch($this->pendingPath($fresh));
        touch($this->pendingPath($due), time() - 120);
        $this->travel(110)->seconds();
        $this->servePrimary();

        $this->forward();

        $this->assertGreaterThan($before, $this->candidateRow($due)->lease_until);
        $this->assertEquals($before, $this->candidateRow($fresh)->lease_until);
        $this->assertSame([], $this->requested);
        $this->assertCount(2, $this->pendingNames());
    }

    public function test_an_in_flight_entry_is_heartbeated_too(): void
    {
        $this->batchSchema();
        $this->configureAsync();
        $claim = $this->claimedCandidate('one@article');
        $this->handOff($claim);
        $this->outbox()->prepare(1);
        rename($this->pendingPath($claim), $this->inflightPath($claim));
        touch($this->inflightPath($claim), time() - 150);
        $before = $this->candidateRow($claim)->lease_until;
        $this->travel(120)->seconds();
        $heartbeats = [];
        $this->servePrimary(function (HttpRequest $request) use (&$heartbeats, $claim) {
            $heartbeats[] = $this->candidateRow($claim)->lease_until;

            return $this->answerAll($request, 'retry');
        });

        $this->forward();

        // Renewed before the send.
        $this->assertGreaterThan($before, $heartbeats[0]);
    }

    public function test_a_lost_lease_drops_the_entry_and_nothing_is_sent_for_it(): void
    {
        $this->batchSchema();
        $this->configureAsync();
        $lost = $this->claimedCandidate('lost@article');
        $expired = $this->claimedCandidate('expired@article');
        foreach ([$lost, $expired] as $claim) {
            $this->handOff($claim);
            $this->changeEntry($this->pendingPath($claim), static fn (array $document): array => ['not_before' => time() + 3600] + $document);
            touch($this->pendingPath($claim), time() - 200);
        }
        DB::table('release_verification_candidates')->where('id', $lost->id)->update(['lease_token' => 'new-owner']);
        DB::table('release_verification_candidates')->where('id', $expired->id)->update(['lease_until' => now()->subSeconds(5)]);
        $this->servePrimary();
        $summaries = [];
        $this->captureSummaries($summaries, 'Publication forwarder run summary.');

        $this->forward(['--diagnostics' => true]);

        $this->assertSame([], $this->pendingNames());
        $this->assertSame([], $this->requested);
        $this->assertSame(2, $summaries[0]['lease_lost']);
        // The new owner is untouched.
        $this->assertSame('new-owner', $this->candidateRow($lost)->lease_token);
    }

    public function test_the_forwarder_holds_the_worker_group_and_its_own_lock_while_it_works(): void
    {
        $this->batchSchema(releases: 1);
        $this->configureAsync();
        $claim = $this->claimedCandidate('one@article');
        $this->handOff($claim);
        $observed = [];
        $this->servePrimary(function (HttpRequest $request) use (&$observed) {
            $probe = new VerificationWorkerLocks;
            $observed['barrier_blocked'] = ! $probe->tryExclusive();
            $observed['duplicate_blocked'] = ! $probe->startForwarder(1);
            $observed['alive'] = $this->outbox()->forwarderAlive();
            $probe->close();
            $observed['other_forwarder'] = $probe->startForwarder(2);
            $probe->close();

            return Http::response($this->withServerVariables(self::PEER)->postJson('/internal/release-verification/publish-batch', $request->data())->json());
        });

        $this->forward();

        $this->assertSame(['barrier_blocked' => true, 'duplicate_blocked' => true, 'alive' => true, 'other_forwarder' => true], $observed);
        $probe = new VerificationWorkerLocks;
        try {
            $this->assertTrue($probe->tryExclusive());
        } finally {
            $probe->close();
        }
        $this->assertFalse($this->outbox()->forwarderAlive());
    }

    public function test_a_pause_that_appears_while_working_finishes_the_waiting_entries_unknown_and_stops(): void
    {
        $this->batchSchema(releases: 1);
        $this->configureAsync();
        config(['release_verification.publish_forward_items' => 1]);
        $first = $this->claimedCandidate('one@article');
        $second = $this->claimedCandidate('two@article');
        foreach ([$first, $second] as $index => $claim) {
            $this->handOff($claim);
            $this->changeEntry($this->pendingPath($claim), static fn (array $document): array => ['enqueued_at' => time() - 50 + $index] + $document);
        }
        $this->servePrimary(function (HttpRequest $request) {
            $response = $this->withServerVariables(self::PEER)->postJson('/internal/release-verification/publish-batch', $request->data());
            VerificationWorkerLocks::pause('test-barrier', 'maintenance');

            return Http::response($response->json(), $response->status());
        });
        $summaries = [];
        $this->captureSummaries($summaries, 'Publication forwarder run summary.');

        $this->assertSame(0, Artisan::call('releases:forward-verified-publications', ['--slot' => 1, '--max-seconds' => 60, '--diagnostics' => true]));

        $this->assertSame('sampled', $this->candidateStatus($first));
        $row = $this->candidateRow($second);
        $this->assertSame('unknown', $row->status);
        $this->assertSame(RemotePublicationService::PAUSED_MESSAGE, $row->reason);
        $this->assertSame([], $this->pendingNames());
        $this->assertSame(1, count($this->requested));
        $this->assertSame('draining', $summaries[0]['stop_reason']);
        $this->assertSame(1, $summaries[0]['finished_for_drain']);
        $this->assertFileDoesNotExist($this->outbox()->directory().'/forwarder-1.alive');
        // It let go of the worker group, so the barrier can proceed once the pause is lifted.
        VerificationWorkerLocks::unpause('test-barrier');
        $probe = new VerificationWorkerLocks;
        try {
            $this->assertTrue($probe->tryExclusive());
        } finally {
            $probe->close();
        }
    }

    public function test_a_forwarder_does_not_start_under_a_pause_and_leaves_its_entries_alone(): void
    {
        $this->batchSchema();
        $this->configureAsync();
        $claim = $this->claimedCandidate('one@article');
        $this->handOff($claim);
        VerificationWorkerLocks::pause('test-barrier', 'maintenance');
        $this->servePrimary();

        $this->assertSame(0, $this->forward());

        $this->assertSame([], $this->requested);
        $this->assertSame('verifying', $this->candidateStatus($claim));
        $this->assertCount(1, $this->pendingNames());
        $this->assertFileDoesNotExist($this->outbox()->directory().'/forwarder-1.alive');
    }

    public function test_a_forwarder_does_nothing_when_asynchronous_publication_is_off_or_its_number_is_too_high(): void
    {
        $this->batchSchema();
        $this->configureAsync(async: false);
        $claim = $this->claimedCandidate('one@article');
        $this->handOff($claim);
        $this->servePrimary();

        $this->assertSame(0, $this->forward());
        $this->configureAsync();
        config(['release_verification.publish_forwarders' => 2]);
        $this->assertSame(0, $this->forward(['--slot' => 3]));

        $this->assertSame([], $this->requested);
        $this->assertCount(1, $this->pendingNames());
        $this->assertFileDoesNotExist($this->outbox()->directory().'/forwarder-1.alive');
        $this->assertFileDoesNotExist($this->outbox()->directory().'/forwarder-3.alive');
    }

    public function test_without_a_batch_url_the_forwarder_uses_the_single_endpoint_for_each_entry(): void
    {
        $this->batchSchema(releases: 1);
        $this->configureAsync(batch: false);
        $published = $this->claimedCandidate('fresh@article');
        $filtered = $this->claimedCandidate('filtered@article', groupId: 2);
        $this->handOff($published);
        $this->handOff($filtered);
        $this->servePrimary();

        $this->forward();

        $this->assertSame([self::SINGLE, self::SINGLE], $this->requested);
        $this->assertSame('sampled', $this->candidateStatus($published));
        // The single endpoint reports a filtered candidate as a bare null; the row says what became of it.
        $this->assertSame('filtered', $this->candidateStatus($filtered));
        $this->assertSame([], $this->pendingNames());
        $this->assertSame([], glob($this->outbox()->inflightDirectory(1).'/*') ?: []);
    }

    public function test_a_connection_failure_on_the_single_endpoint_requeues_every_entry_with_backoff(): void
    {
        Sleep::fake();
        $this->batchSchema(releases: 2);
        $this->configureAsync(batch: false);
        $claims = [$this->claimedCandidate('one@article'), $this->claimedCandidate('two@article')];
        array_map($this->handOff(...), $claims);
        $failing = true;
        Http::fake(function (HttpRequest $request) use (&$failing) {
            $this->requested[] = $request->url();
            if ($failing) {
                throw new ConnectionException('cURL error 28: Operation timed out.');
            }

            return Http::response($this->withServerVariables(self::PEER)->postJson('/internal/release-verification/publish', $request->data())->json());
        });
        $summaries = [];
        $this->captureSummaries($summaries, 'Publication forwarder run summary.');

        $this->forward(['--diagnostics' => true]);

        // The primary was not reached: nothing is finished, both are queued again with a delay, and the
        // second item did not wait out the same timeouts (the tries were the first item's alone).
        $this->assertSame(array_fill(0, 3, self::SINGLE), $this->requested);
        foreach ($claims as $claim) {
            $document = $this->entryDocument($this->pendingPath($claim));
            $this->assertSame(1, $document['attempts']);
            $this->assertGreaterThan(microtime(true), $document['not_before']);
            $this->assertSame('verifying', $this->candidateStatus($claim));
            $this->assertNotNull($this->candidateRow($claim)->lease_token);
        }
        $this->assertSame(2, $summaries[0]['outcomes']['transport']);
        $this->assertSame(2, $summaries[0]['requeued']);
        $this->assertSame(0, $summaries[0]['outcomes']['error']);
        $failing = false;
        foreach ($claims as $claim) {
            $this->changeEntry($this->pendingPath($claim), static fn (array $document): array => ['not_before' => 0] + $document);
        }

        $this->forward();

        $this->assertSame(['sampled', 'sampled'], array_map($this->candidateStatus(...), $claims));
        $this->assertSame([], $this->pendingNames());
    }

    public function test_an_error_response_on_the_single_endpoint_still_finishes_the_candidate_unknown(): void
    {
        $this->batchSchema();
        $this->configureAsync(batch: false);
        $claim = $this->claimedCandidate('one@article');
        $this->handOff($claim);
        Http::fake(['primary.test/*' => Http::response(['error' => 'Stale verification result cannot publish.'], 409)]);

        $this->forward();

        $row = $this->candidateRow($claim);
        $this->assertSame('unknown', $row->status);
        $this->assertStringContainsString('Stale verification result cannot publish.', $row->reason);
        $this->assertSame([], $this->pendingNames());
    }

    public function test_publish_many_reports_an_unreachable_primary_as_transport_and_an_error_response_as_error(): void
    {
        Sleep::fake();
        $this->batchSchema();
        $this->configureAsync(batch: false);
        $claims = [$this->claimedCandidate('one@article'), $this->claimedCandidate('two@article')];
        $items = array_map(fn (object $claim): RemotePublishItem => new RemotePublishItem($claim, $this->sampled($claim)), $claims);
        $publisher = app(RemoteReleasePublisher::class);
        $answer = 'unreachable';
        Http::fake(static function () use (&$answer) {
            return $answer === 'unreachable' ? throw new ConnectionException('Connection refused.') : Http::response(['error' => 'Database unavailable.'], 500);
        });

        $unreachable = $publisher->publishMany($items);
        $answer = 'refused';
        $refused = $publisher->publishMany($items);

        $this->assertSame(['transport', 'transport'], array_map(static fn (PublicationOutcome $outcome): string => $outcome->status, $unreachable));
        $this->assertStringContainsString('Connection refused.', (string) $unreachable[0]->error);
        $this->assertFalse($unreachable[0]->published());
        $this->assertSame(['error', 'error'], array_map(static fn (PublicationOutcome $outcome): string => $outcome->status, $refused));
        $this->assertSame('Remote publication failed: Database unavailable.', $refused[0]->error);
    }

    public function test_a_forwarder_run_stops_at_its_deadline(): void
    {
        $this->batchSchema();
        $this->configureAsync();
        $this->servePrimary();
        $summaries = [];
        $this->captureSummaries($summaries, 'Publication forwarder run summary.');

        $started = microtime(true);
        $this->assertSame(0, Artisan::call('releases:forward-verified-publications', ['--slot' => 1, '--max-seconds' => 1, '--diagnostics' => true]));

        $this->assertGreaterThanOrEqual(1.0, microtime(true) - $started);
        $this->assertLessThan(5.0, microtime(true) - $started);
        $this->assertSame('deadline', $summaries[0]['stop_reason']);
        $this->assertSame(0, $summaries[0]['batches_sent']);
        $this->assertFileDoesNotExist($this->outbox()->directory().'/forwarder-1.alive');
    }

    public function test_an_entry_reproduces_the_evidence_it_was_handed_off_with(): void
    {
        $claim = (object) ['id' => 7, 'version' => 3, 'manifest_hash' => str_repeat('b', 64), 'lease_token' => 'token'];
        $evidence = ['articles' => 2, 'bytes' => 300, 'sampled_files' => 2, 'verified_file_names' => ['a.mkv', 'b.nfo'], 'identity_hints' => ['a.mkv']];
        $this->configureAsync();

        $name = $this->outbox()->enqueue($claim, 'Reason.', $evidence);
        $entry = $this->outbox()->pending()[0];

        $this->assertSame('7-3.json', $name);
        $this->assertSame($evidence, SpotCheckVerifier::evidence($entry->item()->result->manifest));
        $this->assertSame('Reason.', $entry->item()->result->reason);
        $this->assertSame('sampled', $entry->item()->result->verdict);
        $this->assertSame(7, $entry->item()->claim->id);
        $this->assertSame('token', $entry->item()->claim->lease_token);
    }

    // ---- scheduling ------------------------------------------------------------------------------------------

    /** @return list<string> */
    private function scheduledCommands(): array
    {
        $this->app->instance(Schedule::class, $schedule = new Schedule);
        Facade::clearResolvedInstances();
        require base_path('routes/console.php');

        return array_map(static fn ($event): string => (string) ($event->command ?? $event->description), $schedule->events());
    }

    /** @param list<string> $commands */
    private function forwarderCommands(array $commands): array
    {
        return array_values(array_filter($commands, static fn (string $command): bool => str_contains($command, 'releases:forward-verified-publications')));
    }

    public function test_the_scheduler_registers_numbered_forwarders_only_when_asynchronous_publication_is_enabled(): void
    {
        config(['release_verification.worker_only' => true, 'release_verification.candidate_workers' => 3, 'release_verification.slot_offset' => 128,
            'release_verification.publish_url' => self::SINGLE, 'release_verification.publish_async' => true,
            'release_verification.publish_forwarders' => 3, 'release_verification.publish_forwarder_seconds' => 300]);

        $commands = $this->scheduledCommands();
        $forwarders = $this->forwarderCommands($commands);

        $this->assertCount(3, $forwarders);
        foreach ([1, 2, 3] as $index => $number) {
            $this->assertStringContainsString('--slot='.$number.' ', $forwarders[$index]);
            $this->assertStringContainsString('--max-seconds='.(300 + $index * 60).' ', $forwarders[$index]);
            $this->assertStringContainsString('--diagnostics', $forwarders[$index]);
        }
        // The verification slots are untouched.
        $this->assertCount(3, array_filter($commands, static fn (string $command): bool => str_contains($command, 'releases:verify-candidates')));
        $this->assertCount(6, $commands);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function disabledConfigurations(): array
    {
        return [
            'flag off' => [['release_verification.publish_async' => false]],
            'no publish url' => [['release_verification.publish_url' => null]],
            'primary host' => [['release_verification.worker_only' => false]],
        ];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('disabledConfigurations')]
    public function test_the_scheduler_registers_no_forwarder_otherwise(array $override): void
    {
        config(['release_verification.worker_only' => true, 'release_verification.candidate_workers' => 2,
            'release_verification.publish_url' => self::SINGLE, 'release_verification.publish_async' => true,
            'release_verification.publish_forwarders' => 4, ...$override]);

        $this->assertSame([], $this->forwarderCommands($this->scheduledCommands()));
    }
}
