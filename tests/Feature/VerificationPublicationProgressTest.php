<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\NNTP\NNTPService;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\CandidateSampleBatch;
use App\Services\Releases\Verification\LeaseHeartbeat;
use App\Services\Releases\Verification\ManifestStore;
use App\Services\Releases\Verification\NntpArticleSource;
use App\Services\Releases\Verification\RemotePublishItem;
use App\Services\Releases\Verification\RemoteReleasePublisher;
use App\Services\Releases\Verification\VerificationWorkerLocks;
use App\Services\Releases\Verification\VerifiedReleasePublisher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\PublicationBatchFixtures;

class VerificationPublicationProgressTest extends ReleaseVerificationCandidatesTest
{
    use PublicationBatchFixtures;

    /** @return array{CandidateSampleBatch, VerificationWorkerLocks, object, object} */
    private function queuedBatch(): array
    {
        $this->travelTo(now()->startOfSecond());
        config(['release_verification.lease_seconds' => 300, 'release_verification.sample_batch_size' => 2,
            'release_verification.download_connections' => 1, 'release_verification.pipeline_window' => 2,
            'release_verification.download_buffer_bytes' => 4096, 'release_verification.article_bytes' => 1024]);
        $active = $this->claimedCandidate('active@publication');
        $queued = $this->claimedCandidate('queued@publication');
        $repository = app(CandidateRepository::class);
        $repository->returnQueuedClaim($active);
        $repository->returnQueuedClaim($queued);
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('doConnect')->andReturnFalse();
        $nntp->shouldReceive('doQuit')->andReturnTrue();
        $locks = new VerificationWorkerLocks;
        $this->assertTrue($locks->start(1));
        $batch = new CandidateSampleBatch($repository, app(ManifestStore::class), $locks,
            new NntpArticleSource($nntp, maximumBytes: 1024, allowAlternateFallback: false), (array) config('release_verification'),
            clock: static fn (): float => (float) now()->getTimestamp());
        try {
            $active = $batch->next(2, static fn (): bool => true);
            $this->assertNotNull($active);
            $queued = DB::table('release_verification_candidates')->where('id', '!=', $active->id)->first();
            $this->assertNotNull($queued);

            return [$batch, $locks, $active, $queued];
        } catch (\Throwable $error) {
            $locks->close();

            throw $error;
        }
    }

    public function test_local_publication_renews_queued_work_at_active_heartbeat_seams(): void
    {
        $this->batchSchema(releases: 1);
        [$batch, $locks, $claim, $queued] = $this->queuedBatch();
        $started = now()->toImmutable();
        $scanned = false;
        $inserted = false;
        DB::listen(function (QueryExecuted $query) use ($started, &$scanned, &$inserted): void {
            if (! $scanned && str_contains($query->sql, 'from "releases"')) {
                $scanned = true;
                $this->travelTo($started->addSeconds(200));
            }
            if (str_starts_with($query->sql, 'insert into "releases"')) {
                $inserted = true;
                $this->travelTo($started->addSeconds(350));
            }
        });
        $pulses = 0;
        try {
            $id = app(VerifiedReleasePublisher::class)->publish($claim, $this->sampled($claim), progress: function () use ($batch, &$pulses): void {
                $this->assertGreaterThan(0, DB::transactionLevel());
                $pulses++;
                $batch->pulse();
            });
            $this->assertIsInt($id);
            $this->assertTrue($scanned);
            $this->assertTrue($inserted);
            $this->assertGreaterThanOrEqual(2, $pulses);
            $this->assertGreaterThan($queued->lease_until, now()->format('Y-m-d H:i:s'));
            $this->assertGreaterThan(now()->format('Y-m-d H:i:s'), $this->candidateRow($queued)->lease_until);
            $next = $batch->next(2, static fn (): bool => true);
            $this->assertNotNull($next);
            $this->assertSame((int) $queued->id, (int) $next->id);
        } finally {
            $batch->releasePending();
            $locks->close();
        }
    }

    public function test_local_progress_database_failure_rolls_back_publication_and_nzb_installation(): void
    {
        $this->batchSchema();
        $claim = $this->claimedCandidate('rollback@publication');
        $queued = $this->claimedCandidate('queued@publication');
        $before = $this->candidateRow($queued)->lease_until;
        $this->travel(100)->seconds();
        $failure = new PDOException('database is locked');
        $failures = 0;
        try {
            app(VerifiedReleasePublisher::class)->publish($claim, $this->sampled($claim), progress: function () use ($queued, $failure, &$failures): void {
                $this->assertGreaterThan(0, DB::transactionLevel());
                app(CandidateRepository::class)->heartbeat((int) $queued->id, $queued->lease_token);
                if (DB::table('releases')->exists()) {
                    $this->assertSame(1, $this->installedNzbs());
                    $failures++;
                    throw $failure;
                }
            });
            $this->fail('A queued renewal failure inside publication must propagate.');
        } catch (PDOException $error) {
            $this->assertSame($failure, $error);
        }
        $this->assertSame(5, $failures, 'Each deadlock retry must rerun the entire transaction.');
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(0, DB::table('releases')->count());
        $this->assertSame(0, $this->installedNzbs());
        $this->assertNull($this->candidateRow($claim)->release_id);
        $this->assertSame($before, $this->candidateRow($queued)->lease_until);
    }

    public function test_remote_publication_pulses_active_and_queued_work_during_http_progress_and_retries(): void
    {
        $this->batchSchema();
        [$batch, $locks, $claim, $queued] = $this->queuedBatch();
        config(['release_verification.publish_url' => 'http://primary.test/publish']);
        Sleep::fake();
        $requests = 0;
        $pulses = 0;
        $progressHeartbeat = new LeaseHeartbeat(static function () use ($batch, $claim): void {
            $batch->pulse();
            app(CandidateRepository::class)->heartbeat((int) $claim->id, $claim->lease_token);
        }, 30, static fn (): float => (float) now()->getTimestamp());
        Http::fake(function (Request $request, array $options) use (&$requests, &$pulses, $claim, $queued) {
            $requests++;
            $this->assertSame($requests === 1 ? 1 : 3, $pulses, 'Every attempt, including a retry, pulses before transport.');
            $this->assertArrayHasKey('progress', $options);
            if ($requests === 1) {
                $this->travel(200)->seconds();
                $options['progress'](0, 0, 0, 0);
                throw new ConnectionException('Lost response; retry the idempotent publication.');
            }
            $this->travel(150)->seconds();
            $options['progress'](0, 0, 0, 0);
            $this->assertGreaterThan($queued->lease_until, now()->format('Y-m-d H:i:s'));
            $this->assertGreaterThan(now()->format('Y-m-d H:i:s'), $this->candidateRow($queued)->lease_until);
            $this->assertGreaterThan(now()->format('Y-m-d H:i:s'), $this->candidateRow($claim)->lease_until);

            return Http::response(['release_id' => 42]);
        });
        try {
            $id = app(RemoteReleasePublisher::class)->publish($claim, $this->sampled($claim), progress: function () use ($progressHeartbeat, &$pulses): void {
                $pulses++;
                $progressHeartbeat();
            });
            $this->assertSame(42, $id);
            $this->assertSame(2, $requests);
            $this->assertSame(4, $pulses);
            $this->assertTrue($locks->protects((int) $queued->id));
        } finally {
            $batch->releasePending();
            $locks->close();
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function progressFailures(): iterable
    {
        yield 'before transport' => [false];
        yield 'during transport' => [true];
    }

    #[DataProvider('progressFailures')]
    public function test_remote_caller_progress_failure_propagates_without_retry(bool $duringTransport): void
    {
        $this->batchSchema();
        $claim = $this->claimedCandidate('failure@publication');
        config(['release_verification.publish_url' => 'http://primary.test/publish']);
        $requests = 0;
        $pulses = 0;
        $failure = new RuntimeException('Queued lease storage unavailable.');
        Http::fake(function (Request $request, array $options) use (&$requests) {
            $requests++;
            $options['progress'](0, 0, 0, 0);

            return Http::response(['release_id' => 42]);
        });
        try {
            app(RemoteReleasePublisher::class)->publish($claim, $this->sampled($claim), progress: static function () use (&$pulses, $failure, $duringTransport): void {
                $pulses++;
                if (! $duringTransport || $pulses > 1) {
                    throw $failure;
                }
            });
            $this->fail('Caller ownership failures must not be swallowed as best-effort batch progress.');
        } catch (RuntimeException $error) {
            $this->assertSame($failure, $error);
        }
        $this->assertSame($duringTransport ? 1 : 0, $requests);
        $this->assertSame($duringTransport ? 2 : 1, $pulses);
    }

    public function test_publish_many_keeps_best_effort_progress_for_single_endpoint_fallback(): void
    {
        $this->batchSchema();
        $claim = $this->claimedCandidate('outbox@publication');
        config(['release_verification.publish_url' => 'http://primary.test/publish', 'release_verification.publish_batch_url' => null]);
        Http::fake(function (Request $request, array $options) {
            $options['progress'](0, 0, 0, 0);

            return Http::response(['release_id' => 42]);
        });
        $progress = [];
        $outcomes = app(RemoteReleasePublisher::class)->publishMany([new RemotePublishItem($claim, $this->sampled($claim))],
            static function (int $completed) use (&$progress): void {
                $progress[] = $completed;
                throw new RuntimeException('Best-effort outbox marker failure.');
            });
        $this->assertTrue($outcomes[0]->published());
        $this->assertSame(42, $outcomes[0]->releaseId);
        $this->assertSame([0, 0], $progress);
    }
}
