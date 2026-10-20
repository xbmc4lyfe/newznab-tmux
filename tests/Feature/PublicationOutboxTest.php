<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\NNTP\NNTPService;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\ManifestStore;
use App\Services\Releases\Verification\PublicationOutbox;
use App\Services\Releases\Verification\PublicationOutboxEntry;
use App\Services\Releases\Verification\PublicationOutcome;
use App\Services\Releases\Verification\RemotePublicationService;
use App\Services\Releases\Verification\RemotePublishItem;
use App\Services\Releases\Verification\RemoteReleasePublisher;
use App\Services\Releases\Verification\SpotCheckVerifier;
use App\Services\Releases\Verification\VerificationResetService;
use App\Services\Releases\Verification\VerificationWorkerLocks;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Signals;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\File;
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
        return $this->outbox()->directory().'/pending/'.PublicationOutboxEntry::nameFor((int) $claim->id, (int) $claim->version, (string) $claim->lease_token);
    }

    private function inflightPath(object $claim, int $slot = 1): string
    {
        return $this->outbox()->inflightDirectory($slot).'/'.PublicationOutboxEntry::nameFor((int) $claim->id, (int) $claim->version, (string) $claim->lease_token);
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
        $this->assertSame([PublicationOutboxEntry::nameFor((int) $row->id, (int) $row->version, (string) $row->lease_token)], $entries);
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
        $this->assertSame(4, $report['schema_version']);
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
        $this->assertSame([PublicationOutboxEntry::nameFor((int) $claims[2]->id, (int) $claims[2]->version, (string) $claims[2]->lease_token)], $this->pendingNames());
    }

    /** @return array<string, array{string}> */
    public static function failedStatuses(): array
    {
        return ['stale' => ['stale'], 'invalid' => ['invalid'], 'error' => ['error']];
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
        // The primary never started the item, so no attempt was used up; the backoff still counts it.
        $this->assertSame(0, $document['attempts']);
        $this->assertSame(1, $document['deferrals']);
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

        $document = $this->entryDocument($this->pendingPath($claim));
        $this->assertSame([0, 1], [$document['attempts'], $document['deferrals']]);
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
        $this->changeEntry($this->pendingPath($fresh), static fn (array $document): array => ['heartbeat_at' => microtime(true) + 110] + $document);
        $this->changeEntry($this->pendingPath($due), static fn (array $document): array => ['heartbeat_at' => microtime(true) - 120] + $document);
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
        $this->changeEntry($this->inflightPath($claim), static fn (array $document): array => ['heartbeat_at' => microtime(true) - 150] + $document);
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
            $this->changeEntry($this->pendingPath($claim), static fn (array $document): array => ['heartbeat_at' => microtime(true) - 200] + $document);
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

    public function test_a_pause_that_appears_while_working_leaves_the_waiting_entries_alone_until_it_lifts(): void
    {
        Sleep::fake();
        $this->batchSchema(releases: 2);
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

        $this->assertSame(0, $this->forward(['--passes' => 3, '--diagnostics' => true]));

        // The first batch went; the pause kept the forwarder out of the worker group for the second.
        $this->assertSame('sampled', $this->candidateStatus($first));
        $this->assertSame('verifying', $this->candidateStatus($second));
        $this->assertNotNull($this->candidateRow($second)->lease_token);
        $this->assertSame(1, count($this->requested));
        // Refused the group, it did not even move the entry into its in-flight folder.
        $this->assertFileExists($this->pendingPath($second));
        $this->assertGreaterThanOrEqual(1, $summaries[0]['lock_blocked']);
        // A refused forwarder does not advertise itself, so workers publish for themselves.
        $this->assertFileDoesNotExist($this->outbox()->directory().'/forwarder-1.alive');
        // Once the pause lifts the same entry is sent, still fenced by its lease.
        VerificationWorkerLocks::unpause('test-barrier');
        $this->forward();
        $this->assertSame('sampled', $this->candidateStatus($second));
        $this->assertSame(2, count($this->requested));
    }

    public function test_a_forwarder_started_under_a_pause_touches_nothing_and_does_not_advertise_itself(): void
    {
        Sleep::fake();
        $this->batchSchema();
        $this->configureAsync();
        $claim = $this->claimedCandidate('one@article');
        $this->handOff($claim);
        VerificationWorkerLocks::pause('test-barrier', 'maintenance');
        $this->servePrimary();

        $this->assertSame(0, $this->forward(['--passes' => 2]));

        $this->assertSame([], $this->requested);
        $this->assertSame('verifying', $this->candidateStatus($claim));
        $this->assertNotNull($this->candidateRow($claim)->lease_token);
        $this->assertFileExists($this->pendingPath($claim));
        $this->assertFileDoesNotExist($this->outbox()->directory().'/forwarder-1.alive');
    }

    public function test_an_idle_forwarder_neither_holds_the_worker_group_nor_queries_the_database(): void
    {
        $this->batchSchema();
        $this->configureAsync();
        $queries = 0;
        $afterFirstSleep = null;
        $probing = false;
        $probes = [];
        Sleep::fake();
        Sleep::whenFakingSleep(function () use (&$probes, &$probing, &$queries, &$afterFirstSleep): void {
            $afterFirstSleep ??= $queries;
            $probing = true;
            $barrier = new VerificationWorkerLocks;
            try {
                $probes[] = ['exclusive' => $barrier->tryExclusive()];
            } finally {
                $barrier->close();
            }
            $probes[] = ['alive' => $this->outbox()->forwarderAlive()];
            $probing = false;
        });
        DB::listen(function () use (&$queries, &$probing): void {
            $queries += $probing ? 0 : 1;
        });

        $this->forward(['--passes' => 4]);

        // The first pass advertises the forwarder (a join and a leave); the idle passes after it query nothing.
        $this->assertLessThanOrEqual(6, $afterFirstSleep);
        $this->assertSame($afterFirstSleep, $queries);
        $this->assertCount(8, $probes);
        foreach ($probes as $probe) {
            $this->assertTrue(current($probe), key($probe));
        }
    }

    public function test_a_forwarder_leaves_the_worker_group_as_soon_as_the_outbox_is_quiet(): void
    {
        $this->batchSchema(releases: 1);
        $this->configureAsync();
        $claim = $this->claimedCandidate('one@article');
        $this->handOff($claim);
        $this->servePrimary();
        $exclusive = [];
        Sleep::fake();
        Sleep::whenFakingSleep(function () use (&$exclusive): void {
            $barrier = new VerificationWorkerLocks;
            try {
                $exclusive[] = $barrier->tryExclusive();
            } finally {
                $barrier->close();
            }
        });

        $this->forward(['--passes' => 3]);

        $this->assertSame('sampled', $this->candidateStatus($claim));
        // Pass 1 sent the batch; passes 2 and 3 found nothing and slept outside the group.
        $this->assertSame([true, true], $exclusive);
    }

    public function test_waiting_entries_are_renewed_inside_the_group_and_the_group_is_left_again(): void
    {
        $this->batchSchema();
        $this->configureAsync();
        $claim = $this->claimedCandidate('one@article');
        $this->handOff($claim);
        $this->changeEntry($this->pendingPath($claim), static fn (array $document): array => ['not_before' => time() + 3600] + $document);
        $this->changeEntry($this->pendingPath($claim), static fn (array $document): array => ['heartbeat_at' => microtime(true) - 200] + $document);
        $before = $this->candidateRow($claim)->lease_until;
        $this->travel(150)->seconds();
        $this->servePrimary();
        Sleep::fake();
        $free = [];
        Sleep::whenFakingSleep(function () use (&$free): void {
            $barrier = new VerificationWorkerLocks;
            try {
                $free[] = $barrier->tryExclusive();
            } finally {
                $barrier->close();
            }
        });

        $this->forward(['--passes' => 2]);

        $this->assertGreaterThan($before, $this->candidateRow($claim)->lease_until);
        $this->assertSame([true, true], $free);
        $this->assertSame([], $this->requested);
    }

    public function test_the_forwarder_stops_promptly_on_sigterm(): void
    {
        if (! function_exists('posix_kill') || ! defined('SIGTERM')) {
            $this->markTestSkipped('Requires pcntl and posix.');
        }
        $this->batchSchema();
        $this->configureAsync();
        $sleeps = 0;
        // Command signal traps are off under unit tests; this one verifies the trap itself.
        Signals::resolveAvailabilityUsing(static fn (): bool => true);
        Sleep::fake();
        Sleep::whenFakingSleep(function () use (&$sleeps): void {
            if (++$sleeps === 2) {
                posix_kill(getmypid(), SIGTERM);
            }
        });
        $summaries = [];
        $this->captureSummaries($summaries, 'Publication forwarder run summary.');

        try {
            $this->assertSame(0, Artisan::call('releases:forward-verified-publications', ['--slot' => 1, '--max-seconds' => 3600, '--diagnostics' => true]));
        } finally {
            Signals::resolveAvailabilityUsing(static fn (): bool => false);
        }

        $this->assertSame('terminated', $summaries[0]['stop_reason']);
        $this->assertLessThan(10, $sleeps);
        $this->assertFileDoesNotExist($this->outbox()->directory().'/forwarder-1.alive');
    }

    public function test_entries_of_a_forwarder_number_nobody_runs_are_adopted_and_sent(): void
    {
        Sleep::fake();
        $this->batchSchema(releases: 2);
        $this->configureAsync();
        config(['release_verification.publish_forwarders' => 4]);
        $beyond = $this->claimedCandidate('beyond@article');
        $idle = $this->claimedCandidate('idle@article');
        $running = $this->claimedCandidate('running@article');
        foreach ([[$beyond, 9], [$idle, 3], [$running, 2]] as [$claim, $slot]) {
            $this->handOff($claim);
            $this->outbox()->prepare($slot);
            rename($this->pendingPath($claim), $this->inflightPath($claim, $slot));
        }
        // Forwarder 2 is alive and keeps its entries; 3 never restarted; 9 is above the configured count.
        $other = new VerificationWorkerLocks;
        $this->assertTrue($other->startForwarder(2));
        $this->servePrimary();
        $summaries = [];
        $this->captureSummaries($summaries, 'Publication forwarder run summary.');
        $this->assertSame(3, $this->outbox()->depth());

        try {
            $this->forward(['--passes' => 2, '--diagnostics' => true]);
        } finally {
            $other->close();
        }

        $this->assertSame(['sampled', 'sampled', 'verifying'], array_map($this->candidateStatus(...), [$beyond, $idle, $running]));
        $this->assertSame(2, $summaries[0]['orphans_adopted']);
        $this->assertDirectoryDoesNotExist($this->outbox()->inflightDirectory(9));
        $this->assertFileExists($this->inflightPath($running, 2));
        $this->assertSame(1, $this->outbox()->depth());
    }

    public function test_a_second_hand_off_of_the_same_candidate_generation_never_collides_with_the_first(): void
    {
        $this->batchSchema(releases: 1);
        $this->configureAsync();
        $old = $this->claimedCandidate('one@article');
        $this->handOff($old);
        // The lease lapsed and the same generation was claimed again.
        $new = (object) ['id' => $old->id, 'version' => $old->version, 'manifest_hash' => $old->manifest_hash, 'lease_token' => 'second-lease'];
        DB::table('release_verification_candidates')->where('id', $old->id)->update(['lease_token' => 'second-lease', 'lease_until' => now()->addSeconds(300)]);
        $this->handOff($new);
        $this->assertNotSame($this->pendingPath($old), $this->pendingPath($new));
        $this->assertCount(2, $this->pendingNames());
        $newDocument = file_get_contents($this->pendingPath($new));
        // Sending the old entry back (after a failure) replaces only its own document.
        $this->outbox()->prepare(1);
        rename($this->pendingPath($old), $this->inflightPath($old));
        $this->outbox()->requeue(current($this->outbox()->inflight(1))->withFailure(microtime(true) + 60, true));
        $this->assertSame($newDocument, file_get_contents($this->pendingPath($new)));
        $this->assertSame(1, $this->entryDocument($this->pendingPath($old))['attempts']);
        $this->assertFileDoesNotExist($this->inflightPath($old));
        $this->changeEntry($this->pendingPath($old), static fn (array $document): array => ['heartbeat_at' => microtime(true) - 200] + $document);
        $this->servePrimary();

        $this->forward();

        // The old lease token no longer owns the candidate: its entry is dropped by lease loss, the new one publishes.
        $this->assertFileDoesNotExist($this->pendingPath($old));
        $this->assertSame('sampled', $this->candidateStatus($old));
        $this->assertSame([], $this->pendingNames());
        $this->assertSame(1, DB::table('releases')->count());
    }

    public function test_work_the_primary_never_processed_does_not_use_up_attempts_but_an_error_does(): void
    {
        Sleep::fake();
        $this->batchSchema();
        $this->configureAsync();
        config(['release_verification.publish_forward_max_attempts' => 5]);
        $claim = $this->claimedCandidate('one@article');
        $this->handOff($claim);
        $this->changeEntry($this->pendingPath($claim), static fn (array $document): array => ['attempts' => 4] + $document);
        $answer = 'retry';
        Http::fake(function (HttpRequest $request) use (&$answer) {
            return match ($answer) {
                'retry' => $this->answerAll($request, 'retry', 'Batch time budget exhausted; resend this item.'),
                'transport' => $this->answerAll($request, 'transport', 'unreachable'),
                'unreachable' => throw new ConnectionException('Connection refused.'),
                default => Http::response(['error' => 'Database unavailable.'], 500),
            };
        });
        $due = fn () => $this->changeEntry($this->pendingPath($claim), static fn (array $document): array => ['not_before' => 0] + $document);

        foreach (['retry', 'unreachable', 'retry'] as $round => $kind) {
            $answer = $kind;
            $due();
            $this->forward();
            $document = $this->entryDocument($this->pendingPath($claim));
            $this->assertSame(4, $document['attempts']);
            $this->assertSame($round + 1, $document['deferrals']);
            $this->assertSame('verifying', $this->candidateStatus($claim));
        }
        // An error the primary produced is the fifth counted failure.
        $answer = 'error';
        $due();
        $this->forward();

        $this->assertSame('unknown', $this->candidateStatus($claim));
        $this->assertSame([], $this->pendingNames());
    }

    public function test_a_failure_after_the_hand_off_does_not_finish_the_candidate(): void
    {
        $this->batchSchema(releases: 1);
        $this->configureAsync();
        $this->workerCandidate();
        $this->markForwarderAlive();
        // A status file that cannot be replaced, as on a full or damaged disk.
        mkdir(config('release_verification.storage_path').'/status.json');
        Http::fake();

        $report = $this->runWorker();

        Http::assertNothingSent();
        $row = DB::table('release_verification_candidates')->first();
        $this->assertSame('verifying', $row->status);
        $this->assertNotNull($row->lease_token);
        $this->assertSame(1, $report['handed_off']);
        $this->assertSame(0, $report['finish_returned']);
        $this->assertSame(1, $report['candidate_errors']);
        $this->assertCount(1, $this->pendingNames());
        // The queued result still publishes: its lease was never cleared.
        $this->servePrimary();
        $this->forward();
        $this->assertSame('sampled', $this->candidateStatus($row));
        $this->assertSame(1, DB::table('releases')->count());
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
            $this->assertSame([0, 1], [$document['attempts'], $document['deferrals']]);
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

    public function test_a_forwarder_writes_nothing_while_a_reset_holds_the_worker_gate(): void
    {
        Sleep::fake();
        $this->batchSchema();
        $this->configureAsync();
        $this->handOff($this->claimedCandidate('one@article'));
        $this->markForwarderAlive();
        $reset = new VerificationWorkerLocks;
        $this->assertTrue($reset->tryExclusive());
        // A reset clears the verification storage, outbox included, while it holds the gate.
        File::deleteDirectory($this->outbox()->directory());
        $summaries = [];
        $this->captureSummaries($summaries, 'Publication forwarder run summary.');
        $this->servePrimary();

        try {
            $this->forward(['--passes' => 6, '--diagnostics' => true]);
            // No marker, no folder, no claim: the forwarder did not touch the storage the reset is emptying.
            $this->assertDirectoryDoesNotExist($this->outbox()->directory());
            $this->assertSame([], $this->requested);
            $this->assertGreaterThanOrEqual(1, $summaries[0]['lock_blocked']);
        } finally {
            $reset->close();
        }
    }

    public function test_a_reset_between_an_idle_forwarders_passes_clears_the_outbox_and_the_forwarder_stays_out(): void
    {
        $this->batchSchema();
        $this->configureAsync();
        $claim = $this->claimedCandidate('one@article');
        $this->handOff($claim);
        $this->changeEntry($this->pendingPath($claim), static fn (array $document): array => ['not_before' => time() + 3600] + $document);
        $resets = 0;
        $present = [];
        Sleep::fake();
        Sleep::whenFakingSleep(function () use (&$resets, &$present): void {
            if ($resets++ === 0) {
                app(VerificationResetService::class)->run(static fn () => null);
            }
            $present[] = is_dir($this->outbox()->directory());
        });

        $this->forward(['--passes' => 4]);

        // The idle forwarder held no group, so the reset ran; its later passes wrote nothing back.
        $this->assertGreaterThanOrEqual(1, $resets);
        $this->assertSame([false, false, false, false], $present);
    }

    public function test_the_marker_is_refreshed_while_a_request_waits_for_the_primary(): void
    {
        $this->batchSchema(releases: 1);
        $this->configureAsync();
        $claim = $this->claimedCandidate('one@article');
        $this->handOff($claim);
        $ages = [];
        Http::fake(function (HttpRequest $request, array $options) use (&$ages) {
            $marker = $this->outbox()->directory().'/forwarder-1.alive';
            // A request that has waited 20 s: the marker is that old, until cURL's progress callback fires.
            touch($marker, time() - 20);
            $ages['before'] = time() - filemtime($marker);
            ($options['progress'] ?? static fn () => null)(0, 0, 0, 0);
            clearstatcache();
            $ages['after'] = time() - filemtime($marker);
            $ages['alive'] = $this->outbox()->forwarderAlive(null, 5);

            return Http::response($this->withServerVariables(self::PEER)->postJson('/internal/release-verification/publish-batch', $request->data())->json());
        });

        $this->forward();

        $this->assertGreaterThanOrEqual(20, $ages['before']);
        $this->assertLessThanOrEqual(1, $ages['after']);
        $this->assertTrue($ages['alive']);
        $this->assertSame('sampled', $this->candidateStatus($claim));
    }

    public function test_the_forwarder_claims_no_more_than_the_limit_the_primary_advertises(): void
    {
        $this->batchSchema(releases: 6);
        $this->configureAsync();
        config(['release_verification.publish_forward_items' => 3, 'release_verification.publish_batch_max_items' => 2]);
        $claims = [];
        foreach (range(1, 6) as $number) {
            $claims[] = $claim = $this->claimedCandidate($number.'@article');
            $this->handOff($claim);
            $this->changeEntry($this->pendingPath($claim), static fn (array $document): array => ['enqueued_at' => time() - 100 + $number] + $document);
        }
        $this->servePrimary();
        $summaries = [];
        $this->captureSummaries($summaries, 'Publication forwarder run summary.');

        $this->forward(['--passes' => 3, '--diagnostics' => true]);

        // The first batch of three is refused with max_items 2 (and goes out one by one); the rest respect it.
        $this->assertSame([3, 2, 1], $this->batchSizes);
        $this->assertSame(array_fill(0, 6, 'sampled'), array_map($this->candidateStatus(...), $claims));
        $this->assertSame(2, $summaries[0]['advertised_item_limit']);
        $this->assertSame([], $this->pendingNames());
    }

    public function test_an_entry_that_cannot_be_removed_is_set_aside_not_sent_again(): void
    {
        $this->configureAsync();
        $this->outbox()->prepare(1);
        $directory = $this->outbox()->directory();
        // An unremovable file, as a directory: unlink fails, rename can still move it.
        mkdir($directory.'/pending/9-1-abcdef123456.json');
        $entry = new PublicationOutboxEntry('9-1-abcdef123456.json', $directory.'/pending/9-1-abcdef123456.json',
            ['id' => 9, 'version' => 1, 'manifest_hash' => str_repeat('a', 64), 'lease_token' => 't'], 'r', $this->evidence(), microtime(true));

        $this->assertFalse($this->outbox()->remove($entry));

        $this->assertDirectoryDoesNotExist($directory.'/pending/9-1-abcdef123456.json');
        $this->assertDirectoryExists($directory.'/failed/9-1-abcdef123456.json');
        $this->assertSame(0, $this->outbox()->depth());
    }

    public function test_an_entry_that_cannot_even_be_moved_is_counted_and_never_claimed_again(): void
    {
        if (! function_exists('posix_geteuid') || posix_geteuid() === 0) {
            $this->markTestSkipped('Needs a user that file permissions bind.');
        }
        Sleep::fake();
        $this->batchSchema(releases: 1);
        $this->configureAsync();
        $claim = $this->claimedCandidate('one@article');
        $this->handOff($claim);
        $this->outbox()->prepare(1);
        rename($this->pendingPath($claim), $this->inflightPath($claim));
        $summaries = [];
        $this->captureSummaries($summaries, 'Publication forwarder run summary.');
        $folder = $this->outbox()->inflightDirectory(1);
        // The folder becomes read-only once the entry is in hand, so removing and moving it both fail.
        $locked = false;
        Http::fake(function (HttpRequest $request) use (&$locked, $folder) {
            $this->requested[] = $request->url();
            $locked = chmod($folder, 0500);

            return Http::response($this->withServerVariables(self::PEER)->postJson('/internal/release-verification/publish-batch', $request->data())->json());
        });

        try {
            $this->forward(['--passes' => 4, '--diagnostics' => true]);
        } finally {
            chmod($folder, 0700);
        }

        $this->assertTrue($locked);
        $this->assertSame('sampled', $this->candidateStatus($claim));
        $this->assertCount(1, $this->requested);
        $this->assertSame(1, $summaries[0]['quarantined']);
        $this->assertFileExists($this->inflightPath($claim));
    }

    public function test_leases_of_entries_waiting_for_the_one_by_one_fallback_are_renewed_between_requests(): void
    {
        $this->batchSchema(releases: 3);
        $this->configureAsync(batch: false);
        $claims = [];
        foreach (range(1, 3) as $number) {
            $claims[] = $claim = $this->claimedCandidate($number.'@article');
            $this->handOff($claim);
            $this->changeEntry($this->pendingPath($claim), static fn (array $document): array => ['enqueued_at' => time() - 100 + $number] + $document);
        }
        $leases = [];
        $summaries = [];
        $this->captureSummaries($summaries, 'Publication forwarder run summary.');
        Http::fake(function (HttpRequest $request, array $options) use (&$leases, $claims) {
            $this->requested[] = $request->url();
            $leases[] = $this->candidateRow($claims[2])->lease_until;
            if (count($this->requested) === 1) {
                // The first slow request has taken longer than a third of the lease.
                $this->travel(150)->seconds();
            }

            return Http::response($this->withServerVariables(self::PEER)->postJson('/internal/release-verification/publish', $request->data())->json());
        });

        $this->forward(['--diagnostics' => true]);

        $this->assertCount(3, $this->requested);
        // The third entry's lease was renewed before the second request, though nothing had sent it yet.
        $this->assertGreaterThan($leases[0], $leases[1]);
        $this->assertSame(array_fill(0, 3, 'sampled'), array_map($this->candidateStatus(...), $claims));
        // Entries already published are not renewed (that would find their lease gone and drop them).
        $this->assertSame(0, $summaries[0]['lease_lost']);
    }

    public function test_repeated_retry_requeues_do_not_postpone_lease_renewal(): void
    {
        $this->batchSchema();
        $this->configureAsync();
        $claim = $this->claimedCandidate('one@article');
        $this->handOff($claim);
        // Renewed long ago; every pass below requeues the entry, rewriting its file and so its mtime.
        $this->changeEntry($this->pendingPath($claim), static fn (array $document): array => ['heartbeat_at' => microtime(true) - 150] + $document);
        $this->servePrimary(fn (HttpRequest $request) => $this->answerAll($request, 'retry', 'Batch time budget exhausted; resend this item.'));
        $leases = [];

        foreach ([1, 2, 3] as $round) {
            if ($round > 1) {
                $this->travel(110)->seconds();
            }
            $this->changeEntry($this->pendingPath($claim), static fn (array $document): array => ['not_before' => 0] + $document);
            $this->forward();
            $leases[] = $this->candidateRow($claim)->lease_until;
            $document = $this->entryDocument($this->pendingPath($claim));
            $this->assertSame($round, $document['deferrals']);
            // The requeued document remembers the real renewal, which is what the schedule follows.
            $this->assertGreaterThan(now()->getTimestamp() - 5, $document['heartbeat_at']);
            $this->assertGreaterThan(time() - 5, filemtime($this->pendingPath($claim)));
        }

        // Each round, 110 s later, renewed the lease again although the file was always fresh.
        $this->assertLessThan($leases[1], $leases[0]);
        $this->assertLessThan($leases[2], $leases[1]);
    }

    public function test_an_outbox_of_only_malformed_documents_is_cleaned_inside_the_group(): void
    {
        Sleep::fake();
        $this->batchSchema();
        $this->configureAsync();
        $this->outbox()->prepare(1);
        $directory = $this->outbox()->directory();
        file_put_contents($directory.'/pending/1-1-aaaaaaaaaaaa.json', 'not json at all');
        file_put_contents($directory.'/pending/2-1-bbbbbbbbbbbb.json', '{"claim":{"id":"x"},"evidence":[]}');
        file_put_contents($directory.'/pending/3-1-cccccccccccc.json', '');
        file_put_contents($directory.'/inflight/1/4-1-dddddddddddd.json', '[1,2');
        $this->assertSame(4, $this->outbox()->depth());
        VerificationWorkerLocks::pause('test-barrier', 'maintenance');
        $this->servePrimary();

        // Refused the group, the forwarder deletes nothing.
        $this->forward(['--passes' => 3]);
        $this->assertSame(4, $this->outbox()->depth());

        VerificationWorkerLocks::unpause('test-barrier');
        $summaries = [];
        $this->captureSummaries($summaries, 'Publication forwarder run summary.');
        $this->forward(['--passes' => 3, '--diagnostics' => true]);

        $this->assertSame(0, $this->outbox()->depth());
        $this->assertSame([], $this->requested);
        $this->assertSame(0, $summaries[0]['quarantined']);
    }

    public function test_cancellation_starts_no_further_request_and_defers_the_unsent_items(): void
    {
        $this->batchSchema(releases: 1);
        $this->configureAsync(batch: false);
        $claims = [$this->claimedCandidate('one@article'), $this->claimedCandidate('two@article'), $this->claimedCandidate('three@article')];
        $items = array_map(fn (object $claim): RemotePublishItem => new RemotePublishItem($claim, $this->sampled($claim)), $claims);
        $this->servePrimary();
        $publisher = app(RemoteReleasePublisher::class);

        $outcomes = $publisher->publishMany($items, null, fn (): bool => count($this->requested) >= 1);

        $this->assertSame([self::SINGLE], $this->requested);
        $this->assertSame(['published', 'retry', 'retry'], array_map(static fn (PublicationOutcome $outcome): string => $outcome->status, $outcomes));
        // Already cancelled before the batch request: nothing is sent at all.
        $this->configureAsync();
        $outcomes = $publisher->publishMany(array_slice($items, 1), null, fn (): bool => true);
        $this->assertSame([], $this->requested);
        $this->assertSame(['retry', 'retry'], array_map(static fn (PublicationOutcome $outcome): string => $outcome->status, $outcomes));
    }

    public function test_cancellation_after_a_failed_attempt_stops_the_transport_retries(): void
    {
        Sleep::fake();
        $this->batchSchema();
        $claim = $this->claimedCandidate('one@article');
        $items = [new RemotePublishItem($claim, $this->sampled($claim))];
        $publisher = app(RemoteReleasePublisher::class);

        foreach ([false, true] as $batch) {
            $this->configureAsync(batch: $batch);
            Http::fake(function (HttpRequest $request) {
                $this->requested[] = $request->url();
                throw new ConnectionException('cURL error 28: Operation timed out.');
            });

            // Without a cancellation check the client keeps its three attempts, as before.
            try {
                $publisher->publishMany($items);
            } catch (ConnectionException) {
            }
            $this->assertCount(3, $this->requested);

            // Cancelled once the first attempt has failed: it is the only one.
            $this->requested = [];
            try {
                $outcomes = $publisher->publishMany($items, null, fn (): bool => count($this->requested) >= 1);
                $this->assertSame(['transport'], array_map(static fn (PublicationOutcome $outcome): string => $outcome->status, $outcomes));
            } catch (ConnectionException) {
                $this->assertTrue($batch, 'only the batch request throws');
            }
            $this->assertCount(1, $this->requested);
        }
    }

    public function test_sigterm_in_the_one_by_one_fallback_sends_no_further_request_and_requeues_the_rest(): void
    {
        if (! function_exists('posix_kill') || ! defined('SIGTERM')) {
            $this->markTestSkipped('Requires pcntl and posix.');
        }
        $this->batchSchema(releases: 1);
        $this->configureAsync(batch: false);
        $claims = [];
        foreach (range(1, 3) as $number) {
            $claims[] = $claim = $this->claimedCandidate($number.'@article');
            $this->handOff($claim);
            $this->changeEntry($this->pendingPath($claim), static fn (array $document): array => ['enqueued_at' => time() - 100 + $number] + $document);
        }
        Signals::resolveAvailabilityUsing(static fn (): bool => true);
        Http::fake(function (HttpRequest $request) {
            $this->requested[] = $request->url();
            posix_kill(getmypid(), SIGTERM);

            return Http::response($this->withServerVariables(self::PEER)->postJson('/internal/release-verification/publish', $request->data())->json());
        });
        $summaries = [];
        $this->captureSummaries($summaries, 'Publication forwarder run summary.');

        try {
            $this->assertSame(0, Artisan::call('releases:forward-verified-publications', ['--slot' => 1, '--max-seconds' => 60, '--diagnostics' => true]));
        } finally {
            Signals::resolveAvailabilityUsing(static fn (): bool => false);
        }

        $this->assertCount(1, $this->requested);
        $this->assertSame(['sampled', 'verifying', 'verifying'], array_map($this->candidateStatus(...), $claims));
        foreach ([$claims[1], $claims[2]] as $claim) {
            $this->assertSame(1, $this->entryDocument($this->pendingPath($claim))['deferrals']);
        }
        $this->assertSame('terminated', $summaries[0]['stop_reason']);
    }

    public function test_the_run_deadline_stops_the_one_by_one_fallback_after_the_request_under_way(): void
    {
        $this->batchSchema(releases: 1);
        $this->configureAsync(batch: false);
        $claims = [];
        foreach (range(1, 3) as $number) {
            $claims[] = $claim = $this->claimedCandidate($number.'@article');
            $this->handOff($claim);
            $this->changeEntry($this->pendingPath($claim), static fn (array $document): array => ['enqueued_at' => time() - 100 + $number] + $document);
        }
        Http::fake(function (HttpRequest $request) {
            $this->requested[] = $request->url();
            // The first request outlasts the run's one second.
            usleep(1_200_000);

            return Http::response($this->withServerVariables(self::PEER)->postJson('/internal/release-verification/publish', $request->data())->json());
        });

        $this->assertSame(0, Artisan::call('releases:forward-verified-publications', ['--slot' => 1, '--max-seconds' => 1]));

        $this->assertCount(1, $this->requested);
        $this->assertSame(['sampled', 'verifying', 'verifying'], array_map($this->candidateStatus(...), $claims));
        foreach ([$claims[1], $claims[2]] as $claim) {
            $this->assertSame(1, $this->entryDocument($this->pendingPath($claim))['deferrals']);
        }
    }

    /** Close the running forwarder's locks, as the end of a database lock session would. */
    private function loseForwarderLocks(): void
    {
        $command = Artisan::all()['releases:forward-verified-publications'];
        $property = new \ReflectionProperty($command, 'locks');
        $property->getValue($command)->close();
    }

    public function test_a_forwarder_that_lost_its_locks_during_a_request_writes_nothing_afterwards(): void
    {
        $this->batchSchema(releases: 1);
        $this->configureAsync(batch: false);
        $claims = [$this->claimedCandidate('one@article'), $this->claimedCandidate('two@article')];
        foreach ($claims as $index => $claim) {
            $this->handOff($claim);
            $this->changeEntry($this->pendingPath($claim), static fn (array $document): array => ['enqueued_at' => time() - 100 + $index] + $document);
        }
        $markerWritten = null;
        Http::fake(function (HttpRequest $request, array $options) use (&$markerWritten) {
            $this->requested[] = $request->url();
            $marker = $this->outbox()->directory().'/forwarder-1.alive';
            $this->loseForwarderLocks();
            touch($marker, time() - 20);
            ($options['progress'] ?? static fn () => null)(0, 0, 0, 0);
            clearstatcache();
            $markerWritten = time() - filemtime($marker) < 10;

            return Http::response($this->withServerVariables(self::PEER)->postJson('/internal/release-verification/publish', $request->data())->json());
        });
        $summaries = [];
        $this->captureSummaries($summaries, 'Publication forwarder run summary.');

        try {
            Artisan::call('releases:forward-verified-publications', ['--slot' => 1, '--passes' => 1, '--diagnostics' => true]);
            $this->fail('A forwarder without its locks must stop.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Publication forwarder locks were lost.', $error->getMessage());
        }

        $this->assertFalse($markerWritten);
        // The second request never started, and neither entry was settled or requeued.
        $this->assertCount(1, $this->requested);
        foreach ($claims as $claim) {
            $this->assertFileExists($this->inflightPath($claim));
        }
        $this->assertSame('locks_lost', $summaries[0]['stop_reason']);
    }

    public function test_leases_of_entries_beyond_the_claimed_batch_are_renewed_during_a_long_fallback(): void
    {
        $this->batchSchema(releases: 2);
        $this->configureAsync(batch: false);
        config(['release_verification.publish_forward_items' => 2]);
        $claims = [];
        foreach (range(1, 3) as $number) {
            $claims[] = $claim = $this->claimedCandidate($number.'@article');
            $this->handOff($claim);
            $this->changeEntry($this->pendingPath($claim), static fn (array $document): array => ['enqueued_at' => time() - 100 + $number] + $document);
        }
        $leases = [];
        Http::fake(function (HttpRequest $request) use (&$leases, $claims) {
            $this->requested[] = $request->url();
            // The third entry is not in the claimed batch of two.
            $leases[] = $this->candidateRow($claims[2])->lease_until;
            if (count($this->requested) === 1) {
                $this->travel(150)->seconds();
            }

            return Http::response($this->withServerVariables(self::PEER)->postJson('/internal/release-verification/publish', $request->data())->json());
        });

        $this->forward();

        $this->assertCount(2, $this->requested);
        $this->assertGreaterThan($leases[0], $leases[1]);
        $this->assertSame(['sampled', 'sampled', 'verifying'], array_map($this->candidateStatus(...), $claims));
    }

    public function test_stray_temporary_and_quarantined_files_are_swept_even_with_nothing_else_to_do(): void
    {
        Sleep::fake();
        $this->configureAsync();
        $this->outbox()->prepare(1);
        $directory = $this->outbox()->directory();
        mkdir($directory.'/failed');
        file_put_contents($directory.'/pending/.9-1-abc.json.old.tmp', 'x');
        file_put_contents($directory.'/pending/.9-1-abc.json.new.tmp', 'x');
        file_put_contents($directory.'/failed/9-1-abc.json', 'x');
        file_put_contents($directory.'/failed/9-2-abc.json', 'x');
        touch($directory.'/pending/.9-1-abc.json.old.tmp', time() - 700);
        touch($directory.'/failed/9-1-abc.json', time() - 4000);

        $this->forward(['--passes' => 2]);

        $this->assertFileDoesNotExist($directory.'/pending/.9-1-abc.json.old.tmp');
        $this->assertFileDoesNotExist($directory.'/failed/9-1-abc.json');
        $this->assertFileExists($directory.'/pending/.9-1-abc.json.new.tmp');
        $this->assertFileExists($directory.'/failed/9-2-abc.json');
    }

    public function test_a_symlinked_foreign_slot_is_never_scanned_swept_or_adopted(): void
    {
        Sleep::fake();
        $this->batchSchema();
        $this->configureAsync();
        $this->outbox()->prepare(1);
        $outside = config('release_verification.storage_path').'/outside';
        mkdir($outside);
        file_put_contents($outside.'/.old.tmp', 'keep');
        touch($outside.'/.old.tmp', time() - 5000);
        $claim = $this->claimedCandidate('one@article');
        $this->handOff($claim);
        rename($this->pendingPath($claim), $outside.'/'.basename($this->pendingPath($claim)));
        symlink($outside, $this->outbox()->directory().'/inflight/7');
        $this->servePrimary();

        $this->forward(['--passes' => 2]);

        $this->assertFileExists($outside.'/.old.tmp');
        $this->assertFileExists($outside.'/'.basename($this->pendingPath($claim)));
        $this->assertSame([], $this->requested);
        $this->assertSame([], $this->outbox()->foreignSlots(1));
        $this->assertSame(0, $this->outbox()->depth());
        $this->assertSame(0, $this->outbox()->adopt(7));
    }

    public function test_a_claim_won_after_the_entry_was_requeued_with_a_backoff_puts_it_back(): void
    {
        $this->batchSchema();
        $this->configureAsync();
        $claim = $this->claimedCandidate('one@article');
        $this->handOff($claim);
        // What the first forwarder listed: due. What is on disk by the time its rename lands: backing off.
        $listed = current($this->outbox()->pending());
        $this->changeEntry($this->pendingPath($claim), static fn (array $document): array => ['not_before' => microtime(true) + 60, 'attempts' => 1] + $document);
        $this->outbox()->prepare(1);

        $claimed = $this->outbox()->claimOne(1, $listed, microtime(true));

        $this->assertNull($claimed);
        $this->assertFileExists($this->pendingPath($claim));
        $this->assertFileDoesNotExist($this->inflightPath($claim));
        $this->assertSame(1, $this->entryDocument($this->pendingPath($claim))['attempts']);
        // Once due, the same call wins it.
        $this->changeEntry($this->pendingPath($claim), static fn (array $document): array => ['not_before' => 0] + $document);
        $this->assertNotNull($this->outbox()->claimOne(1, $listed, microtime(true)));
        $this->assertFileExists($this->inflightPath($claim));
    }

    public function test_a_period_shorter_than_the_scheduler_cadence_is_clamped_so_no_minute_is_left_uncovered(): void
    {
        foreach ([30, 60, 90, 119] as $period) {
            config(['release_verification.publish_forwarder_seconds' => $period]);
            foreach (range(1, 8) as $number) {
                // Exits 10 s before the second tick after its start; the restart follows at once.
                $this->assertSame(110, PublicationOutbox::forwarderLifetime($number));
            }
        }
        config(['release_verification.publish_forwarder_seconds' => 30, 'release_verification.publish_forwarders' => 4,
            'release_verification.worker_only' => true, 'release_verification.publish_url' => self::SINGLE, 'release_verification.publish_async' => true]);
        $commands = $this->forwarderCommands($this->scheduledCommands());
        $this->assertCount(4, $commands);
        foreach ($commands as $command) {
            $this->assertStringContainsString('--max-seconds=110 ', $command);
        }
    }

    public function test_a_symlinked_quarantine_directory_is_never_swept_or_filled(): void
    {
        $this->configureAsync();
        $this->outbox()->prepare(1);
        $outside = config('release_verification.storage_path').'/outside';
        mkdir($outside);
        file_put_contents($outside.'/precious.json', 'keep');
        touch($outside.'/precious.json', time() - 90000);
        symlink($outside, $this->outbox()->directory().'/failed');

        // Looking, sweeping and parking all refuse; prepare() names the problem.
        $this->assertSame([], $this->outbox()->staleFiles());
        $this->outbox()->sweepTemporaryFiles();
        $this->assertFileExists($outside.'/precious.json');
        $this->expectException(\RuntimeException::class);
        $this->outbox()->prepare(1);
    }

    public function test_outbox_directories_that_are_symlinks_are_neither_read_swept_nor_moved_into(): void
    {
        $this->configureAsync();
        $this->outbox()->prepare(1);
        $directory = $this->outbox()->directory();
        $outside = config('release_verification.storage_path').'/outside';
        mkdir($outside);
        mkdir($outside.'/1');
        $name = '9-1-abcdef123456.json';
        $document = json_encode(['claim' => ['id' => 9, 'version' => 1, 'manifest_hash' => str_repeat('a', 64), 'lease_token' => 't'],
            'reason' => 'r', 'evidence' => $this->evidence(), 'enqueued_at' => microtime(true)], JSON_THROW_ON_ERROR);
        foreach (['/pending', '/inflight/1', '/inflight'] as $linked) {
            // Swap one directory for a link to a folder holding a valid entry and an old temporary file.
            $original = $directory.$linked;
            rename($original, $original.'.real');
            $target = $outside.str_replace('/', '_', $linked);
            mkdir($target);
            file_put_contents($target.'/'.$name, $document);
            file_put_contents($target.'/.x.tmp', 'keep');
            touch($target.'/.x.tmp', time() - 5000);
            symlink($target, $original);

            $this->assertSame(0, $this->outbox()->depth(), $linked);
            $this->assertSame([], $this->outbox()->pending(), $linked);
            $this->assertSame([], $this->outbox()->foreignSlots(5), $linked);
            $this->assertSame([], $this->outbox()->staleFiles(), $linked);
            $this->outbox()->sweepTemporaryFiles();
            $this->assertFileExists($target.'/.x.tmp');
            $this->assertFileExists($target.'/'.$name);

            unlink($original);
            rename($original.'.real', $original);
        }
        // The whole outbox, replaced by a link, is no better.
        rename($directory, $directory.'.real');
        symlink($outside, $directory);
        $this->assertSame(0, $this->outbox()->depth());
        $this->assertSame([], $this->outbox()->staleFiles());
        $this->assertSame(0, $this->outbox()->adopt(1));
        unlink($directory);
        rename($directory.'.real', $directory);
    }

    public function test_each_fallback_request_is_reported_as_its_own_request(): void
    {
        $this->batchSchema(releases: 3);
        $this->configureAsync(batch: false);
        foreach (range(1, 3) as $number) {
            $this->handOff($this->claimedCandidate($number.'@article'));
        }
        $this->servePrimary();
        $summaries = [];
        $this->captureSummaries($summaries, 'Publication forwarder run summary.');

        $this->forward(['--diagnostics' => true]);

        $this->assertSame(3, $summaries[0]['batches_sent']);
        $this->assertSame(3, $summaries[0]['items_sent']);
        $this->assertSame(['min' => 1, 'max' => 1, 'mean' => 1.0], $summaries[0]['items_per_batch']);
        $this->assertSame(0, $summaries[0]['transport_failures']);
        $this->assertSame(3, $summaries[0]['outcomes']['published']);
        // Three round trips, each its own: the longest is not the whole call.
        $this->assertGreaterThan($summaries[0]['round_trip_ms']['max'], $summaries[0]['round_trip_ms']['total']);
    }

    public function test_a_refused_batch_followed_by_single_requests_reports_all_of_them(): void
    {
        $this->batchSchema(releases: 2);
        $this->configureAsync();
        $claims = [$this->claimedCandidate('one@article'), $this->claimedCandidate('two@article')];
        array_map($this->handOff(...), $claims);
        $this->servePrimary(fn () => Http::response(['message' => 'Not found.'], 404));
        $summaries = [];
        $this->captureSummaries($summaries, 'Publication forwarder run summary.');

        $this->forward(['--diagnostics' => true]);

        // The refused batch request of two, then one request for each.
        $this->assertSame(3, $summaries[0]['batches_sent']);
        $this->assertSame(['min' => 1, 'max' => 2, 'mean' => 1.33], $summaries[0]['items_per_batch']);
    }

    public function test_a_connection_failure_in_the_fallback_counts_as_a_transport_failure(): void
    {
        Sleep::fake();
        $this->batchSchema();
        $this->configureAsync(batch: false);
        foreach (range(1, 3) as $number) {
            $this->handOff($this->claimedCandidate($number.'@article'));
        }
        Http::fake(static fn () => throw new ConnectionException('Connection refused.'));
        $summaries = [];
        $this->captureSummaries($summaries, 'Publication forwarder run summary.');

        $this->forward(['--diagnostics' => true]);

        // One request was attempted and failed; the other two items were not tried against a dead primary.
        $this->assertSame(1, $summaries[0]['batches_sent']);
        $this->assertSame(1, $summaries[0]['transport_failures']);
        $this->assertSame(3, $summaries[0]['outcomes']['transport']);
    }

    public function test_the_alive_marker_is_never_written_or_removed_through_a_linked_outbox_root(): void
    {
        $this->configureAsync();
        $outbox = $this->outbox();
        $outbox->prepare(1);
        $outbox->touchAlive(1);
        $directory = $outbox->directory();
        $moved = $directory.'-elsewhere';
        // The root becomes a link to the folder that holds this process's own marker.
        rename($directory, $moved);
        symlink($moved, $directory);

        $outbox->clearAlive(1);
        $this->assertFileExists($moved.'/forwarder-1.alive');
        $this->assertFalse($outbox->forwarderAlive());
        try {
            $outbox->touchAlive(1);
            $this->fail('A marker must not be written through a link.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('symlink', $error->getMessage());
        }
        unlink($directory);
        rename($moved, $directory);
        $outbox->clearAlive(1);
        $this->assertFileDoesNotExist($directory.'/forwarder-1.alive');
    }

    public function test_cancellation_during_the_retry_delay_makes_no_further_attempt(): void
    {
        $cancelled = false;
        Sleep::fake();
        // The retry helper asks its predicate first, then sleeps; the cancellation arrives during the sleep.
        Sleep::whenFakingSleep(function () use (&$cancelled): void {
            $cancelled = true;
        });
        $this->batchSchema();
        $claim = $this->claimedCandidate('one@article');
        $items = [new RemotePublishItem($claim, $this->sampled($claim))];
        $publisher = app(RemoteReleasePublisher::class);

        foreach ([false, true] as $batch) {
            $this->configureAsync(batch: $batch);
            $cancelled = false;
            Http::fake(function (HttpRequest $request) {
                $this->requested[] = $request->url();
                throw new ConnectionException('cURL error 28: Operation timed out.');
            });

            try {
                $outcomes = $publisher->publishMany($items, null, function () use (&$cancelled): bool {
                    return $cancelled;
                });
                $this->assertSame(['transport'], array_map(static fn (PublicationOutcome $outcome): string => $outcome->status, $outcomes));
            } catch (ConnectionException) {
                $this->assertTrue($batch);
            }

            // One request went out; the attempt after the delay was stopped before it was sent.
            $this->assertCount(1, $this->requested);
        }
    }

    public function test_a_marker_written_by_a_replacement_forwarder_survives_the_old_ones_exit(): void
    {
        $first = new PublicationOutbox;
        $second = new PublicationOutbox;
        $first->prepare(1);
        $first->touchAlive(1);
        $second->touchAlive(1);

        $first->clearAlive(1);
        $this->assertFileExists($this->outbox()->directory().'/forwarder-1.alive');
        $this->assertTrue($second->forwarderAlive());
        $second->clearAlive(1);
        $this->assertFileDoesNotExist($this->outbox()->directory().'/forwarder-1.alive');
    }

    public function test_after_lost_locks_the_unwinding_forwarder_leaves_the_replacements_marker(): void
    {
        $this->batchSchema(releases: 1);
        $this->configureAsync(batch: false);
        $claim = $this->claimedCandidate('one@article');
        $this->handOff($claim);
        $marker = $this->outbox()->directory().'/forwarder-1.alive';
        Http::fake(function (HttpRequest $request) use ($marker) {
            $this->requested[] = $request->url();
            $this->loseForwarderLocks();
            // A replacement forwarder took the slot and wrote its own marker meanwhile.
            file_put_contents($marker, 'replacement-token');

            return Http::response($this->withServerVariables(self::PEER)->postJson('/internal/release-verification/publish', $request->data())->json());
        });

        try {
            $this->forward();
            $this->fail('The forwarder must stop when it loses its locks.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Publication forwarder locks were lost.', $error->getMessage());
        }

        $this->assertSame('replacement-token', file_get_contents($marker));
    }

    public function test_orphans_are_adopted_without_the_diagnostics_option(): void
    {
        Sleep::fake();
        $this->batchSchema(releases: 1);
        $this->configureAsync();
        config(['release_verification.publish_forwarders' => 4]);
        $claim = $this->claimedCandidate('orphan@article');
        $this->handOff($claim);
        $this->outbox()->prepare(9);
        rename($this->pendingPath($claim), $this->inflightPath($claim, 9));
        $this->servePrimary();

        // No --diagnostics: nothing records the adoption, yet it must still happen.
        $this->forward(['--passes' => 2]);

        $this->assertSame('sampled', $this->candidateStatus($claim));
        $this->assertDirectoryDoesNotExist($this->outbox()->inflightDirectory(9));
        $this->assertSame(0, $this->outbox()->depth());
    }

    /** @return array<string, array{int}> */
    public static function periods(): array
    {
        return ['131 s' => [131], '150 s' => [150], '300 s' => [300], '119 s' => [119], '611 s' => [611]];
    }

    #[DataProvider('periods')]
    public function test_forwarder_lifetimes_end_just_before_a_scheduler_tick_and_leave_no_gap(int $period): void
    {
        config(['release_verification.publish_forwarder_seconds' => $period]);
        $minutes = intdiv(max(120, $period), 60);
        $lifetimes = array_map(PublicationOutbox::forwarderLifetime(...), range(1, 12));

        foreach ($lifetimes as $lifetime) {
            // 10 s short of a whole number of minutes, so the next tick restarts the forwarder at once.
            $this->assertSame(50, $lifetime % 60);
            $this->assertLessThanOrEqual($minutes * 60, $lifetime);
            $this->assertGreaterThanOrEqual(110, $lifetime);
            // Started at a tick, it ends 10 s before another: the gap is far below the alive window.
            $this->assertLessThan((int) config('release_verification.publish_forwarder_alive_seconds'), 60 - ($lifetime % 60));
        }
        if ($minutes === 2) {
            $this->assertSame([110], array_values(array_unique($lifetimes)));
        }
        if ($period === 300) {
            $this->assertSame([290, 230, 170, 110, 290, 230], array_slice($lifetimes, 0, 6));
        }
    }

    public function test_a_paused_outcome_requeues_the_entry_with_its_lease_instead_of_finishing_it(): void
    {
        $this->batchSchema(releases: 1);
        $this->configureAsync();
        $claim = $this->claimedCandidate('one@article');
        $this->handOff($claim);
        $paused = true;
        $this->servePrimary(function (HttpRequest $request) use (&$paused) {
            if ($paused) {
                return $this->answerAll($request, 'paused', RemotePublicationService::PAUSED_MESSAGE);
            }

            return Http::response($this->withServerVariables(self::PEER)->postJson('/internal/release-verification/publish-batch', $request->data())->json());
        });

        $this->forward();

        // Transient maintenance on the primary: the candidate is untouched and the attempt budget unspent.
        $row = $this->candidateRow($claim);
        $this->assertSame('verifying', $row->status);
        $this->assertSame($claim->lease_token, $row->lease_token);
        $document = $this->entryDocument($this->pendingPath($claim));
        $this->assertSame([0, 1], [$document['attempts'], $document['deferrals']]);
        $this->assertGreaterThan(microtime(true), $document['not_before']);

        $paused = false;
        $this->changeEntry($this->pendingPath($claim), static fn (array $document): array => ['not_before' => 0] + $document);
        $this->forward();

        $this->assertSame('sampled', $this->candidateStatus($claim));
        $this->assertSame([], $this->pendingNames());
    }

    public function test_the_single_endpoints_paused_answer_is_also_a_requeue_while_a_worker_still_fails_it(): void
    {
        $this->batchSchema();
        $this->configureAsync(batch: false);
        $claim = $this->claimedCandidate('one@article');
        $this->handOff($claim);
        Http::fake(['primary.test/*' => Http::response(['error' => RemotePublicationService::PAUSED_MESSAGE], 409)]);

        $this->forward();

        $this->assertSame('verifying', $this->candidateStatus($claim));
        $this->assertSame(1, $this->entryDocument($this->pendingPath($claim))['deferrals']);
        // A synchronous worker publishing the same answer gets an exception, and finishes the candidate as before.
        try {
            app(RemoteReleasePublisher::class)->publish($claim, $this->sampled($claim));
            $this->fail('A paused primary must fail a synchronous publication.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString(RemotePublicationService::PAUSED_MESSAGE, $error->getMessage());
        }
    }

    /** A stalled upload in the strict group, promoted as a partial and claimed: one of three articles, far below its minimum size. */
    private function claimedPartial(): object
    {
        config(['release_verification.partial_after_hours' => 4]);
        $collection = DB::table('collections')->insertGetId(['collectionhash' => sha1(random_bytes(8), true), 'subject' => 'Movie partial',
            'fromname' => 'poster', 'totalfiles' => 1, 'groups_id' => 2, 'date' => '2026-10-11 12:00:00', 'filecheck' => 3, 'filesize' => 100000]);
        $binary = DB::table('binaries')->insertGetId(['collections_id' => $collection, 'binaryhash' => md5('partial', true), 'name' => 'movie.mkv', 'totalparts' => 3]);
        DB::table('parts')->insert(['binaries_id' => $binary, 'partnumber' => 1, 'messageid' => 'partial@article', 'size' => 100000]);
        $repository = app(CandidateRepository::class);
        $repository->archive($collection);
        $this->travel(5)->hours();
        $this->assertSame(1, $repository->promotePartial(10));

        return $repository->claim();
    }

    /** As a worker's own deferred publication: back to waiting_upload for the hold window, lease cleared, nothing published. */
    private function assertDeferredToWaiting(object $claim): void
    {
        $row = $this->candidateRow($claim);
        $this->assertSame('waiting_upload', $row->status);
        $this->assertStringStartsWith('Partial publication deferred: ', (string) $row->reason);
        $this->assertNull($row->lease_token);
        $this->assertNull($row->lease_until);
        $this->assertNull($row->release_id);
        $this->assertTrue(Carbon::parse($row->next_attempt_at)->greaterThan(now()->addMinutes(30)), 'Not retried every retry interval.');
        $this->assertSame(0, DB::table('releases')->count());
        $this->assertSame([], $this->pendingNames());
        $this->assertSame([], glob($this->outbox()->inflightDirectory(1).'/*') ?: []);
    }

    public function test_a_deferred_batch_outcome_returns_the_partial_to_waiting_instead_of_finishing_it(): void
    {
        $this->batchSchema();
        $this->configureAsync();
        $claim = $this->claimedPartial();
        $this->handOff($claim);
        $this->servePrimary();
        $summaries = [];
        $this->captureSummaries($summaries, 'Publication forwarder run summary.');

        $this->assertSame(0, $this->forward(['--diagnostics' => true]));

        $this->assertSame([self::BATCH], $this->requested);
        $this->assertDeferredToWaiting($claim);
        $this->assertSame(1, $summaries[0]['outcomes']['deferred']);
        $this->assertSame(0, $summaries[0]['outcomes']['other']);
        $this->assertSame([0, 0, 0], [$summaries[0]['requeued'], $summaries[0]['expired'], $summaries[0]['finish_failures']]);
    }

    public function test_the_single_endpoints_deferred_answer_also_returns_the_partial_to_waiting(): void
    {
        $this->batchSchema();
        $this->configureAsync(batch: false);
        $claim = $this->claimedPartial();
        $this->handOff($claim);
        $this->servePrimary();

        $this->assertSame(0, $this->forward());

        $this->assertSame([self::SINGLE], $this->requested);
        $this->assertDeferredToWaiting($claim);
    }

    public function test_batches_are_split_by_the_byte_limit_the_primary_advertised(): void
    {
        $this->batchSchema(releases: 12);
        $this->configureAsync();
        $claims = array_map(fn (int $number): object => $this->claimedCandidate($number.'@article'), range(1, 12));
        $items = array_map(fn (object $claim): RemotePublishItem => new RemotePublishItem($claim, $this->sampled($claim)), $claims);
        $single = max(array_map(fn (object $claim): int => strlen(json_encode(['protocol' => 1, 'items' => [$this->item($claim)]])), $claims));
        $this->servePrimary();

        // One request fits, two do not. The first batch of three is refused (and goes out one by one)...
        config(['release_verification.publish_batch_max_bytes' => $single + 20]);
        $publisher = app(RemoteReleasePublisher::class);
        $publisher->publishMany(array_slice($items, 0, 3));
        $this->assertSame($single + 20, $publisher->advertisedMaxBytes());
        $this->assertSame([self::BATCH, self::SINGLE, self::SINGLE, self::SINGLE], $this->requested);
        // ...and the next three are split by size up front, each in a request of its own.
        $this->requested = [];
        $this->batchSizes = [];
        $outcomes = $publisher->publishMany(array_slice($items, 3, 3));
        $this->assertSame([self::BATCH, self::BATCH, self::BATCH], $this->requested);
        $this->assertSame([1, 1, 1], $this->batchSizes);
        $this->assertSame(['published', 'published', 'published'], array_map(static fn (PublicationOutcome $outcome): string => $outcome->status, $outcomes));

        // A limit under one item's size: every item goes through the single endpoint, with no batch request.
        config(['release_verification.publish_batch_max_bytes' => $single - 30]);
        $small = app(RemoteReleasePublisher::class);
        $small->publishMany(array_slice($items, 6, 3));
        $this->assertSame($single - 30, $small->advertisedMaxBytes());
        $this->requested = [];
        $outcomes = $small->publishMany(array_slice($items, 9, 3));
        $this->assertSame([self::SINGLE, self::SINGLE, self::SINGLE], $this->requested);
        $this->assertSame(['published', 'published', 'published'], array_map(static fn (PublicationOutcome $outcome): string => $outcome->status, $outcomes));
        $this->assertSame(12, DB::table('releases')->count());
    }

    public function test_idle_writes_are_refused_once_a_database_session_has_dropped_the_locks(): void
    {
        $this->batchSchema(releases: 0);
        $this->configureAsync();
        config(['release_verification.publish_forward_items' => 1]);
        $stale = $this->claimedCandidate('stale@article');
        $second = $this->claimedCandidate('second@article');
        foreach ([$stale, $second] as $index => $claim) {
            $this->handOff($claim);
            $this->changeEntry($this->pendingPath($claim), static fn (array $document): array => ['enqueued_at' => time() - 100 + $index] + $document);
        }
        // A newer attempt owns the first candidate, so the primary answers stale and the forwarder finishes (fences) it.
        DB::table('release_verification_candidates')->where('id', $stale->id)->update(['lease_token' => 'newer-owner']);
        $junk = $this->outbox()->directory().'/pending/99-1-aaaaaaaaaaaa.json';
        $lost = false;
        DB::listen(function ($query) use (&$lost, $junk): void {
            // After the post-request ownership check, while settling: the session drops, and a stray document appears.
            if (! $lost && str_contains($query->sql, 'update "release_verification_candidates"') && in_array('unknown', $query->bindings, true)) {
                $lost = true;
                $this->loseForwarderLocks();
                file_put_contents($junk, 'not json');
            }
        });
        $this->servePrimary();

        try {
            $this->forward(['--passes' => 3]);
            $this->fail('A forwarder whose locks are gone must not go on writing.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Publication forwarder locks were lost.', $error->getMessage());
        }

        $this->assertTrue($lost);
        // The cached join would have let the cleanup delete this; ownership is proved first.
        $this->assertFileExists($junk);
        $this->assertFileExists($this->pendingPath($second));
        $this->assertSame('verifying', $this->candidateStatus($second));
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
        // A scheduled exit leaves the marker, touched on the way out, for the replacement to take over.
        $this->assertFileExists($this->outbox()->directory().'/forwarder-1.alive');
        $this->assertTrue($this->outbox()->forwarderAlive());
        // Ten seconds on (the gap before the next tick) it still counts as alive.
        $this->assertTrue($this->outbox()->forwarderAlive(time() + 10));
        // The replacement process takes the marker over rather than finding a stranger's.
        $this->assertSame(0, $this->forward());
        $this->assertFileDoesNotExist($this->outbox()->directory().'/forwarder-1.alive');
    }

    public function test_a_deadline_exit_that_was_refused_the_group_leaves_no_marker(): void
    {
        Sleep::fake();
        $this->batchSchema();
        $this->configureAsync();
        VerificationWorkerLocks::pause('test-barrier', 'maintenance');
        $this->outbox()->prepare(1);

        $this->assertSame(0, Artisan::call('releases:forward-verified-publications', ['--slot' => 1, '--max-seconds' => 1]));

        $this->assertFileDoesNotExist($this->outbox()->directory().'/forwarder-1.alive');
    }

    public function test_the_alive_window_has_a_floor_that_outlasts_the_restart_gap(): void
    {
        $this->configureAsync();
        config(['release_verification.publish_forwarder_alive_seconds' => 5]);
        $this->markForwarderAlive(1, 15);
        $this->assertTrue($this->outbox()->forwarderAlive());
        $this->markForwarderAlive(1, 25);
        $this->assertFalse($this->outbox()->forwarderAlive());
        $this->assertGreaterThan(10, PublicationOutbox::MINIMUM_ALIVE_SECONDS);
    }

    public function test_a_symlinked_marker_is_not_a_live_forwarder(): void
    {
        $this->configureAsync();
        $this->outbox()->prepare(1);
        $outside = config('release_verification.storage_path').'/outside-marker';
        file_put_contents($outside, 'fresh');
        symlink($outside, $this->outbox()->directory().'/forwarder-1.alive');

        $this->assertFalse($this->outbox()->forwarderAlive());
        // A real marker beside it still counts.
        $this->markForwarderAlive(2);
        $this->assertTrue($this->outbox()->forwarderAlive());
    }

    public function test_an_entry_reproduces_the_evidence_it_was_handed_off_with(): void
    {
        $claim = (object) ['id' => 7, 'version' => 3, 'manifest_hash' => str_repeat('b', 64), 'lease_token' => 'token'];
        $evidence = ['articles' => 2, 'bytes' => 300, 'sampled_files' => 2, 'verified_file_names' => ['a.mkv', 'b.nfo'], 'identity_hints' => ['a.mkv']];
        $this->configureAsync();

        $name = $this->outbox()->enqueue($claim, 'Reason.', $evidence);
        $entry = $this->outbox()->pending()[0];

        $this->assertSame('7-3-'.substr(sha1('token'), 0, 12).'.json', $name);
        // An entry carries no partial flag: the primary decides partial status from its stored manifest.
        $this->assertSame($evidence + ['partial' => false], SpotCheckVerifier::evidence($entry->item()->result->manifest));
        $this->assertSame('Reason.', $entry->item()->result->reason);
        $this->assertSame('sampled', $entry->item()->result->verdict);
        $this->assertSame(7, $entry->item()->claim->id);
        $this->assertSame('token', $entry->item()->claim->lease_token);
    }

    public function test_no_forwarder_outlives_the_configured_period_and_the_staggers_stay_inside_it(): void
    {
        foreach ([300, 600, 120, 60, 30, 3600] as $period) {
            config(['release_verification.publish_forwarder_seconds' => $period]);
            $lifetimes = array_map(PublicationOutbox::forwarderLifetime(...), range(1, 64));
            $this->assertLessThanOrEqual(max($period, PublicationOutbox::MINIMUM_PERIOD_SECONDS), max($lifetimes));
            // Long enough to span a scheduler tick and the restart after it.
            $this->assertGreaterThanOrEqual(100, min($lifetimes));
        }
        config(['release_verification.publish_forwarder_seconds' => 300]);
        $this->assertSame([290, 230, 170, 110, 290, 230], array_map(PublicationOutbox::forwarderLifetime(...), range(1, 6)));
        $this->assertSame(110, PublicationOutbox::forwarderLifetime(64));
        // With 64 forwarders a container stop still waits for no run longer than the period.
        config(['release_verification.publish_forwarder_seconds' => 3600]);
        $this->assertLessThanOrEqual(3600, PublicationOutbox::forwarderLifetime(64));
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
            $this->assertStringContainsString('--max-seconds='.[290, 230, 170][$index].' ', $forwarders[$index]);
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

    /**
     * Hand off $count new candidates, oldest first.
     *
     * @return list<object>
     */
    private function handOffMany(int $count, int $first = 1): array
    {
        $claims = [];
        foreach (range($first, $first + $count - 1) as $number) {
            $claims[] = $claim = $this->claimedCandidate($number.'@article');
            $this->handOff($claim);
            // Handed off in the future, so the wait is not shortened by the time the test itself takes.
            $this->changeEntry($this->pendingPath($claim), static fn (array $document): array => ['enqueued_at' => microtime(true) + 1000 + $number / 100] + $document);
        }

        return $claims;
    }

    /** @param array<string, mixed> $summary */
    private function assertLinger(array $summary, int $started, int $fill, int $timeout, int $interrupted): void
    {
        $this->assertSame($started, $summary['linger']['waits_started']);
        $this->assertSame($fill, $summary['linger']['ended_by_fill']);
        $this->assertSame($timeout, $summary['linger']['ended_by_timeout']);
        $this->assertSame($interrupted, $summary['linger']['interrupted']);
    }

    public function test_a_linger_of_zero_sends_at_once_without_waiting(): void
    {
        $this->batchSchema(releases: 2);
        $this->configureAsync();
        config(['release_verification.publish_forward_linger_ms' => 0]);
        $claims = $this->handOffMany(2);
        $this->servePrimary();
        Sleep::fake();
        $summaries = [];
        $this->captureSummaries($summaries, 'Publication forwarder run summary.');

        $this->forward(['--diagnostics' => true]);

        Sleep::assertNeverSlept();
        $this->assertSame([2], $this->batchSizes);
        $this->assertSame(['sampled', 'sampled'], array_map($this->candidateStatus(...), $claims));
        $this->assertLinger($summaries[0], 0, 0, 0, 0);
        $this->assertSame(0.0, $summaries[0]['linger']['total_ms']);
    }

    public function test_the_wait_ends_as_soon_as_the_due_entries_fill_the_batch(): void
    {
        $this->batchSchema(releases: 3);
        $this->configureAsync();
        config(['release_verification.publish_forward_linger_ms' => 5000, 'release_verification.publish_forward_items' => 3]);
        $first = $this->handOffMany(1);
        $late = [$this->claimedCandidate('2@article'), $this->claimedCandidate('3@article')];
        $this->servePrimary();
        $sleeps = 0;
        $exclusive = [];
        Sleep::fake(syncWithCarbon: true);
        Sleep::whenFakingSleep(function () use (&$sleeps, &$exclusive, $late): void {
            $sleeps++;
            // The shared worker group is free while the forwarder lingers.
            $probe = new VerificationWorkerLocks;
            try {
                $exclusive[] = $probe->tryExclusive();
            } finally {
                $probe->close();
            }
            if ($sleeps === 2) {
                foreach ($late as $claim) {
                    $this->handOff($claim);
                }
            }
        });
        $summaries = [];
        $this->captureSummaries($summaries, 'Publication forwarder run summary.');

        $this->forward(['--diagnostics' => true]);

        $this->assertSame(5, $sleeps);
        $this->assertSame([true, true, true, true, true], $exclusive);
        $this->assertSame([3], $this->batchSizes);
        $this->assertSame(['sampled', 'sampled', 'sampled'], array_map($this->candidateStatus(...), [...$first, ...$late]));
        $this->assertLinger($summaries[0], 1, 1, 0, 0);
        $this->assertEqualsWithDelta(250.0, $summaries[0]['linger']['total_ms'], 5.0);
    }

    public function test_the_wait_ends_at_the_timeout_with_a_partial_batch(): void
    {
        $this->batchSchema(releases: 2);
        $this->configureAsync();
        config(['release_verification.publish_forward_linger_ms' => 200, 'release_verification.publish_forward_items' => 16]);
        $claims = $this->handOffMany(2);
        $this->servePrimary();
        Sleep::fake(syncWithCarbon: true);
        $summaries = [];
        $this->captureSummaries($summaries, 'Publication forwarder run summary.');

        $this->forward(['--diagnostics' => true]);

        Sleep::assertSleptTimes(4);
        $this->assertSame([2], $this->batchSizes);
        $this->assertSame(['sampled', 'sampled'], array_map($this->candidateStatus(...), $claims));
        $this->assertLinger($summaries[0], 1, 0, 1, 0);
        $this->assertEqualsWithDelta(200.0, $summaries[0]['linger']['total_ms'], 5.0);
    }

    public function test_a_backlog_at_or_above_the_limit_is_sent_without_lingering(): void
    {
        $this->batchSchema(releases: 2);
        $this->configureAsync();
        config(['release_verification.publish_forward_linger_ms' => 5000, 'release_verification.publish_forward_items' => 2]);
        $claims = $this->handOffMany(3);
        $this->servePrimary();
        Sleep::fake(syncWithCarbon: true);
        $summaries = [];
        $this->captureSummaries($summaries, 'Publication forwarder run summary.');

        $this->forward(['--diagnostics' => true]);

        Sleep::assertNeverSlept();
        $this->assertSame([2], $this->batchSizes);
        $this->assertSame(['sampled', 'sampled', 'verifying'], array_map($this->candidateStatus(...), $claims));
        $this->assertLinger($summaries[0], 0, 0, 0, 0);
    }

    public function test_a_deadline_during_the_linger_stops_cleanly_and_sends_nothing(): void
    {
        $this->configureAsync();
        config(['release_verification.publish_forward_linger_ms' => 30000]);
        $claims = $this->handOffMany(1);
        $this->servePrimary();
        Sleep::fake();
        // The run deadline is monotonic real time: let a second really pass during the first wait.
        Sleep::whenFakingSleep(static function (): void {
            usleep(1_200_000);
        });
        $summaries = [];
        $this->captureSummaries($summaries, 'Publication forwarder run summary.');

        $this->assertSame(0, Artisan::call('releases:forward-verified-publications', ['--slot' => 1, '--max-seconds' => 1, '--diagnostics' => true]));

        $this->assertSame('deadline', $summaries[0]['stop_reason']);
        $this->assertSame([], $this->batchSizes);
        $this->assertLinger($summaries[0], 1, 0, 0, 1);
        $this->assertFileExists($this->pendingPath($claims[0]));
        $barrier = new VerificationWorkerLocks;
        try {
            $this->assertTrue($barrier->tryExclusive(), 'The worker group was left free.');
        } finally {
            $barrier->close();
        }
    }

    public function test_sigterm_during_the_linger_stops_cleanly_and_sends_nothing(): void
    {
        if (! function_exists('posix_kill') || ! defined('SIGTERM')) {
            $this->markTestSkipped('Requires pcntl and posix.');
        }
        $this->configureAsync();
        config(['release_verification.publish_forward_linger_ms' => 30000]);
        $claims = $this->handOffMany(1);
        $this->servePrimary();
        Signals::resolveAvailabilityUsing(static fn (): bool => true);
        $sleeps = 0;
        Sleep::fake(syncWithCarbon: true);
        Sleep::whenFakingSleep(function () use (&$sleeps): void {
            if (++$sleeps === 2) {
                posix_kill(getmypid(), SIGTERM);
            }
        });
        $summaries = [];
        $this->captureSummaries($summaries, 'Publication forwarder run summary.');

        try {
            $this->assertSame(0, Artisan::call('releases:forward-verified-publications', ['--slot' => 1, '--max-seconds' => 3600, '--diagnostics' => true]));
        } finally {
            Signals::resolveAvailabilityUsing(static fn (): bool => false);
        }

        $this->assertSame('terminated', $summaries[0]['stop_reason']);
        $this->assertSame(2, $sleeps);
        $this->assertSame([], $this->batchSizes);
        $this->assertLinger($summaries[0], 1, 0, 0, 1);
        $this->assertFileExists($this->pendingPath($claims[0]));
        $this->assertFileDoesNotExist($this->outbox()->directory().'/forwarder-1.alive');
    }

    public function test_entries_already_in_flight_are_sent_without_lingering(): void
    {
        $this->batchSchema(releases: 1);
        $this->configureAsync();
        config(['release_verification.publish_forward_linger_ms' => 5000]);
        $claims = $this->handOffMany(1);
        $this->outbox()->prepare(1);
        rename($this->pendingPath($claims[0]), $this->inflightPath($claims[0]));
        $this->servePrimary();
        Sleep::fake(syncWithCarbon: true);
        $summaries = [];
        $this->captureSummaries($summaries, 'Publication forwarder run summary.');

        $this->forward(['--diagnostics' => true]);

        Sleep::assertNeverSlept();
        $this->assertSame([1], $this->batchSizes);
        $this->assertLinger($summaries[0], 0, 0, 0, 0);
    }

    public function test_entries_adopted_from_a_dead_forwarder_are_sent_without_lingering(): void
    {
        $this->batchSchema(releases: 1);
        $this->configureAsync();
        config(['release_verification.publish_forwarders' => 4, 'release_verification.publish_forward_linger_ms' => 5000]);
        $claims = $this->handOffMany(1);
        $this->outbox()->prepare(3);
        rename($this->pendingPath($claims[0]), $this->inflightPath($claims[0], 3));
        $this->servePrimary();
        Sleep::fake(syncWithCarbon: true);
        $summaries = [];
        $this->captureSummaries($summaries, 'Publication forwarder run summary.');

        $this->forward(['--diagnostics' => true]);

        Sleep::assertNeverSlept();
        $this->assertSame([1], $this->batchSizes);
        $this->assertSame(1, $summaries[0]['orphans_adopted']);
        $this->assertLinger($summaries[0], 0, 0, 0, 0);
    }

    public function test_an_entry_past_its_age_limit_is_not_made_to_wait(): void
    {
        $this->batchSchema();
        $this->configureAsync();
        config(['release_verification.publish_forward_linger_ms' => 5000]);
        $claims = $this->handOffMany(1);
        $this->changeEntry($this->pendingPath($claims[0]), static fn (array $document): array => ['enqueued_at' => microtime(true) - 100_000] + $document);
        $this->servePrimary();
        Sleep::fake(syncWithCarbon: true);

        $this->forward();

        Sleep::assertNeverSlept();
        $this->assertSame('unknown', $this->candidateStatus($claims[0]));
    }

    public function test_the_linger_is_clamped_to_zero_through_thirty_seconds(): void
    {
        $this->assertSame(0, PublicationOutbox::lingerMilliseconds());
        foreach ([[-5, 0], [0, 0], [250, 250], [30000, 30000], [99999, 30000]] as [$configured, $effective]) {
            config(['release_verification.publish_forward_linger_ms' => $configured]);
            $this->assertSame($effective, PublicationOutbox::lingerMilliseconds(), (string) $configured);
        }
    }

    public function test_the_survey_follows_the_claim_eligibility_rules(): void
    {
        $this->configureAsync();
        $now = microtime(true);
        $outbox = $this->outbox();
        $claims = $this->handOffMany(4);
        $this->outbox()->prepare(1);
        $this->outbox()->prepare(2);
        // Backed off: not fresh, but it sets the wake time.
        $this->changeEntry($this->pendingPath($claims[0]), static fn (array $document): array => ['not_before' => $now + 60] + $document);

        $survey = $outbox->survey($now, 1, 100, 10.0);
        $this->assertSame(['fresh' => 3, 'urgent' => false, 'retried' => false], array_intersect_key($survey, array_flip(['fresh', 'urgent', 'retried'])));
        $this->assertGreaterThan($now, $survey['oldest']);
        $this->assertEqualsWithDelta($now + 60, $survey['wake'], 0.001);
        // Stops counting at the cap.
        $this->assertSame(2, $outbox->survey($now, 1, 2)['fresh']);
        // An entry tried before is reported and ends the pass.
        $this->changeEntry($this->pendingPath($claims[3]), static fn (array $document): array => ['attempts' => 1] + $document);
        $this->assertTrue($outbox->survey($now, 1, 100)['retried']);
        $this->changeEntry($this->pendingPath($claims[3]), static fn (array $document): array => ['attempts' => 0] + $document);
        // Another slot's in-flight entry does not matter; this slot's own is urgent.
        rename($this->pendingPath($claims[2]), $this->inflightPath($claims[2], 2));
        $this->assertFalse($outbox->survey($now, 1, 100)['urgent']);
        $this->assertTrue($outbox->survey($now, 2, 100)['urgent']);
        rename($this->inflightPath($claims[2], 2), $this->inflightPath($claims[2], 1));
        $this->assertTrue($outbox->survey($now, 1, 100)['urgent']);
        rename($this->inflightPath($claims[2], 1), $this->pendingPath($claims[2]));
        // An expired entry is urgent even when it is backed off.
        $this->changeEntry($this->pendingPath($claims[0]), static fn (array $document): array => ['enqueued_at' => $now - 100_000] + $document);
        $this->assertTrue($outbox->survey($now, 1, 100)['urgent']);
        $this->changeEntry($this->pendingPath($claims[0]), static fn (array $document): array => ['enqueued_at' => $now + 1000] + $document);
        // Read-only: nothing moved, and a broken document is only reported for purging.
        file_put_contents($this->outbox()->directory().'/pending/9-9-broken.json', 'not json');
        $this->assertSame(3, $outbox->survey($now, 1, 100)['fresh']);
        $this->assertTrue($outbox->hasInvalid());
        $this->assertFileExists($this->pendingPath($claims[1]));
    }

    public function test_the_survey_never_follows_a_symlinked_directory(): void
    {
        $this->configureAsync();
        $this->handOffMany(2);
        $this->outbox()->prepare(1);
        $directory = $this->outbox()->directory();
        $outside = config('release_verification.storage_path').'/outside-due';
        mkdir($outside);
        foreach (glob($directory.'/pending/*.json') as $file) {
            copy($file, $outside.'/'.basename($file));
        }
        rename($directory.'/pending', $directory.'/pending.real');
        symlink($outside, $directory.'/pending');

        try {
            $this->assertSame(0, $this->outbox()->survey(microtime(true), 1, 10)['fresh']);
        } finally {
            unlink($directory.'/pending');
            rename($directory.'/pending.real', $directory.'/pending');
        }
        $this->assertSame(2, $this->outbox()->survey(microtime(true), 1, 10)['fresh']);
    }

    public function test_the_group_is_free_while_lingering_right_after_a_send(): void
    {
        $this->batchSchema(releases: 2);
        $this->configureAsync();
        config(['release_verification.publish_forward_linger_ms' => 100]);
        $this->handOffMany(1);
        $second = $this->claimedCandidate('2@article');
        $sent = false;
        $this->servePrimary(function (HttpRequest $request) use (&$sent, $second) {
            $response = $this->withServerVariables(self::PEER)->postJson('/internal/release-verification/publish-batch', $request->data());
            // Work arrives while the first batch is in flight; the forwarder still holds the group afterwards.
            $this->handOff($second);
            $sent = true;

            return Http::response($response->json(), $response->status());
        });
        $exclusive = [];
        Sleep::fake(syncWithCarbon: true);
        Sleep::whenFakingSleep(function () use (&$sent, &$exclusive): void {
            if (! $sent) {
                return;
            }
            $probe = new VerificationWorkerLocks;
            try {
                $exclusive[] = $probe->tryExclusive();
            } finally {
                $probe->close();
            }
        });

        $this->forward(['--passes' => 2]);

        $this->assertSame([1, 1], $this->batchSizes);
        $this->assertNotSame([], $exclusive);
        $this->assertSame(array_fill(0, count($exclusive), true), $exclusive);
        $this->assertSame('sampled', $this->candidateStatus($second));
    }

    public function test_a_pause_during_the_wait_ends_it_and_the_forwarder_writes_nothing(): void
    {
        $this->configureAsync();
        config(['release_verification.publish_forward_linger_ms' => 30000]);
        $claims = $this->handOffMany(1);
        $this->servePrimary();
        $sleeps = 0;
        Sleep::fake(syncWithCarbon: true);
        Sleep::whenFakingSleep(function () use (&$sleeps): void {
            if (++$sleeps === 3) {
                VerificationWorkerLocks::pause('test-barrier', 'maintenance');
            }
        });
        $summaries = [];
        $this->captureSummaries($summaries, 'Publication forwarder run summary.');

        $this->forward(['--diagnostics' => true]);

        $this->assertSame(20, $sleeps);
        $this->assertSame([], $this->batchSizes);
        $this->assertLinger($summaries[0], 1, 0, 0, 1);
        $this->assertGreaterThanOrEqual(1, $summaries[0]['lock_blocked']);
        $this->assertFileExists($this->pendingPath($claims[0]));
        $this->assertFileDoesNotExist($this->outbox()->directory().'/forwarder-1.alive');
    }

    public function test_the_marker_and_leases_keep_their_cadence_during_a_long_wait(): void
    {
        $this->batchSchema(releases: 1);
        $this->configureAsync();
        config(['release_verification.publish_forward_linger_ms' => 30000]);
        $claims = $this->handOffMany(1);
        // Not yet a third of the lease old when the wait starts, but past it ten seconds later.
        $this->changeEntry($this->pendingPath($claims[0]), static fn (array $document): array => ['heartbeat_at' => microtime(true) - 95] + $document);
        $before = $this->candidateRow($claims[0])->lease_until;
        $this->servePrimary();
        $sleeps = 0;
        $marker = $this->outbox()->directory().'/forwarder-1.alive';
        $observed = [];
        Sleep::fake(syncWithCarbon: true);
        Sleep::whenFakingSleep(function () use (&$sleeps, &$observed, $marker, $claims, $before): void {
            $sleeps++;
            if ($sleeps === 5) {
                touch($marker, time() - 100);
            }
            if ($sleeps === 230) {
                clearstatcache();
                $observed = ['marker' => filemtime($marker), 'lease' => $this->candidateRow($claims[0])->lease_until > $before];
            }
        });

        $this->forward();

        $this->assertGreaterThanOrEqual(time() - 20, $observed['marker']);
        $this->assertTrue($observed['lease']);
        $this->assertSame([1], $this->batchSizes);
    }

    public function test_a_retried_entry_is_not_made_to_wait(): void
    {
        $this->batchSchema(releases: 1);
        $this->configureAsync();
        config(['release_verification.publish_forward_linger_ms' => 5000]);
        $claims = $this->handOffMany(1);
        $this->changeEntry($this->pendingPath($claims[0]), static fn (array $document): array => ['deferrals' => 1] + $document);
        $this->servePrimary();
        Sleep::fake(syncWithCarbon: true);

        $this->forward();

        Sleep::assertNeverSlept();
        $this->assertSame([1], $this->batchSizes);
    }

    public function test_an_entry_too_close_to_its_age_limit_is_not_made_to_wait(): void
    {
        $this->batchSchema(releases: 1);
        $this->configureAsync();
        config(['release_verification.publish_forward_linger_ms' => 30000]);
        $claims = $this->handOffMany(1);
        // The age limit is 240 s here; with the safety margin left, 200 s old leaves no room to wait.
        $this->changeEntry($this->pendingPath($claims[0]), static fn (array $document): array => ['enqueued_at' => microtime(true) - 200] + $document);
        $this->servePrimary();
        Sleep::fake(syncWithCarbon: true);

        $this->forward();

        Sleep::assertNeverSlept();
        $this->assertSame([1], $this->batchSizes);
        $this->assertSame('sampled', $this->candidateStatus($claims[0]));
    }

    public function test_the_wait_is_anchored_to_the_oldest_entrys_age_not_restarted(): void
    {
        $this->batchSchema(releases: 1);
        $this->configureAsync();
        config(['release_verification.publish_forward_linger_ms' => 1000]);
        $claims = $this->handOffMany(1);
        $this->changeEntry($this->pendingPath($claims[0]), static fn (array $document): array => ['enqueued_at' => microtime(true) - 0.7] + $document);
        $this->servePrimary();
        Sleep::fake(syncWithCarbon: true);
        $summaries = [];
        $this->captureSummaries($summaries, 'Publication forwarder run summary.');

        $this->forward(['--diagnostics' => true]);

        // Only the remainder of the linger (about 300 ms of the 1000) is waited.
        $this->assertLinger($summaries[0], 1, 0, 1, 0);
        $this->assertLessThanOrEqual(350.0, $summaries[0]['linger']['total_ms']);
        $this->assertGreaterThan(0.0, $summaries[0]['linger']['total_ms']);
    }

    public function test_urgency_does_not_outlive_the_adopted_work_that_caused_it(): void
    {
        $this->batchSchema(releases: 1);
        $this->configureAsync();
        config(['release_verification.publish_forwarders' => 4, 'release_verification.publish_forward_linger_ms' => 100]);
        $orphan = $this->claimedCandidate('orphan@article');
        $this->handOff($orphan);
        $this->changeEntry($this->pendingPath($orphan), static fn (array $document): array => ['not_before' => microtime(true) + 3600] + $document);
        $this->outbox()->prepare(3);
        rename($this->pendingPath($orphan), $this->inflightPath($orphan, 3));
        $fresh = $this->claimedCandidate('fresh@article');
        $steps = 0;
        $handedOff = false;
        $this->servePrimary();
        Sleep::fake(syncWithCarbon: true);
        Sleep::whenFakingSleep(function ($duration) use (&$steps, &$handedOff, $fresh): void {
            if ((int) $duration->totalMicroseconds === 50_000) {
                $steps++;
            } elseif (! $handedOff) {
                // The idle pass after the adoption: new work arrives.
                $handedOff = true;
                $this->handOff($fresh);
            }
        });

        $this->forward(['--passes' => 3]);

        // The adopted entry was not due, so nothing was urgent: the fresh one waited for its batch.
        $this->assertGreaterThanOrEqual(1, $steps);
        $this->assertSame([1], $this->batchSizes);
        $this->assertSame('sampled', $this->candidateStatus($fresh));
    }

    public function test_the_wait_ends_when_a_backed_off_entry_becomes_due(): void
    {
        $this->batchSchema(releases: 1);
        $this->configureAsync();
        config(['release_verification.publish_forward_linger_ms' => 30000]);
        $this->handOffMany(1);
        $retried = $this->claimedCandidate('retry@article');
        $this->handOff($retried);
        $this->changeEntry($this->pendingPath($retried), static fn (array $document): array => ['deferrals' => 1, 'not_before' => microtime(true) + 0.3] + $document);
        $this->servePrimary();
        Sleep::fake(syncWithCarbon: true);
        $summaries = [];
        $this->captureSummaries($summaries, 'Publication forwarder run summary.');

        $this->forward(['--diagnostics' => true]);

        // Not the whole 30 s: the wait stops when the retried entry's backoff ends, so it is not held back.
        $this->assertLinger($summaries[0], 1, 0, 1, 0);
        $this->assertLessThanOrEqual(350.0, $summaries[0]['linger']['total_ms']);
        $this->assertSame([1], $this->batchSizes);
    }

    public function test_a_backed_off_entry_near_its_age_limit_keeps_the_forwarder_from_waiting(): void
    {
        $this->batchSchema(releases: 1);
        $this->configureAsync();
        config(['release_verification.publish_forward_linger_ms' => 30000]);
        $this->handOffMany(1);
        $old = $this->claimedCandidate('old@article');
        $this->handOff($old);
        $this->changeEntry($this->pendingPath($old), static fn (array $document): array => ['deferrals' => 1, 'not_before' => microtime(true) + 3600, 'enqueued_at' => microtime(true) - 200] + $document);
        $this->servePrimary();
        Sleep::fake(syncWithCarbon: true);

        $this->forward();

        Sleep::assertNeverSlept();
        $this->assertSame([1], $this->batchSizes);
    }

    public function test_a_retried_entry_requeued_by_another_forwarder_ends_the_wait(): void
    {
        $this->batchSchema(releases: 2);
        $this->configureAsync();
        config(['release_verification.publish_forward_linger_ms' => 30000]);
        $this->handOffMany(1);
        $requeued = $this->claimedCandidate('requeued@article');
        $sleeps = 0;
        $this->servePrimary();
        Sleep::fake(syncWithCarbon: true);
        Sleep::whenFakingSleep(function () use (&$sleeps, $requeued): void {
            if (++$sleeps === 2) {
                // Another forwarder hit its deadline after a RETRY and put the entry back, due again.
                $this->handOff($requeued);
                $this->changeEntry($this->pendingPath($requeued), static fn (array $document): array => ['deferrals' => 1] + $document);
            }
        });
        $summaries = [];
        $this->captureSummaries($summaries, 'Publication forwarder run summary.');

        $this->forward(['--diagnostics' => true]);

        $this->assertSame(5, $sleeps);
        $this->assertLinger($summaries[0], 1, 0, 0, 1);
        $this->assertSame([2], $this->batchSizes);
    }

    public function test_a_backed_off_entry_that_appears_during_the_wait_caps_it(): void
    {
        $this->batchSchema(releases: 1);
        $this->configureAsync();
        config(['release_verification.publish_forward_linger_ms' => 30000]);
        $this->handOffMany(1);
        $backed = $this->claimedCandidate('backed@article');
        $sleeps = 0;
        $this->servePrimary();
        Sleep::fake(syncWithCarbon: true);
        Sleep::whenFakingSleep(function () use (&$sleeps, $backed): void {
            if (++$sleeps === 1) {
                $this->handOff($backed);
                $this->changeEntry($this->pendingPath($backed), static fn (array $document): array => ['deferrals' => 1, 'not_before' => microtime(true) + 0.4] + $document);
            }
        });
        $summaries = [];
        $this->captureSummaries($summaries, 'Publication forwarder run summary.');

        $this->forward(['--diagnostics' => true]);

        // Seen at the next look (step 5); its backoff ends by step 10, which ends the wait instead of 30 s.
        $this->assertLessThanOrEqual(10, $sleeps);
        $this->assertLinger($summaries[0], 1, 0, 0, 1);
        $this->assertSame([1], $this->batchSizes);
    }

    public function test_a_full_outbox_counts_as_a_filled_batch_before_the_wait(): void
    {
        $this->batchSchema(releases: 2);
        $this->configureAsync();
        // Workers block at two entries, so a batch of sixteen can never fill.
        config(['release_verification.publish_forward_linger_ms' => 30000, 'release_verification.publish_forward_items' => 16, 'release_verification.publish_outbox_max' => 2]);
        $this->handOffMany(2);
        $this->servePrimary();
        Sleep::fake(syncWithCarbon: true);
        $summaries = [];
        $this->captureSummaries($summaries, 'Publication forwarder run summary.');

        $this->forward(['--diagnostics' => true]);

        Sleep::assertNeverSlept();
        $this->assertSame([2], $this->batchSizes);
        $this->assertLinger($summaries[0], 0, 0, 0, 0);
    }

    public function test_the_wait_ends_as_filled_when_the_outbox_reaches_its_capacity(): void
    {
        $this->batchSchema(releases: 3);
        $this->configureAsync();
        config(['release_verification.publish_forward_linger_ms' => 30000, 'release_verification.publish_forward_items' => 16, 'release_verification.publish_outbox_max' => 3]);
        $this->handOffMany(2);
        $late = $this->claimedCandidate('3@article');
        $sleeps = 0;
        $this->servePrimary();
        Sleep::fake(syncWithCarbon: true);
        Sleep::whenFakingSleep(function () use (&$sleeps, $late): void {
            if (++$sleeps === 2) {
                $this->handOff($late);
            }
        });
        $summaries = [];
        $this->captureSummaries($summaries, 'Publication forwarder run summary.');

        $this->forward(['--diagnostics' => true]);

        // The third hand-off fills the outbox; the next look ends the wait at once rather than after 30 s.
        $this->assertSame(5, $sleeps);
        $this->assertLinger($summaries[0], 1, 1, 0, 0);
        $this->assertSame([3], $this->batchSizes);
    }
}
