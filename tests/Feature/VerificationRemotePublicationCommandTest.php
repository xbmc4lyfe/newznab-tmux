<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\NNTP\NNTPService;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\VerificationWorkerLocks;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Tests\Fixtures\YencArticles;
use Tests\Support\PublicationBatchFixtures;

class VerificationRemotePublicationCommandTest extends ReleaseVerificationCandidatesTest
{
    use PublicationBatchFixtures;

    /** @return array{object, object} */
    private function prepareWorker(): array
    {
        $this->batchSchema(releases: 1);
        config(['release_verification.publish_url' => 'http://primary.test/internal/release-verification/publish',
            'release_verification.publish_async' => false, 'release_verification.lease_seconds' => 1,
            'release_verification.sample_batch_size' => 2, 'release_verification.download_connections' => 1,
            'release_verification.pipeline_window' => 2, 'release_verification.publication_timing_sample_every' => 0,
            'release_verification.free_bytes' => 0]);
        $active = $this->claimedCandidate('active@publication');
        $queued = $this->claimedCandidate('queued@publication');
        $repository = app(CandidateRepository::class);
        $repository->returnQueuedClaim($active);
        $repository->returnQueuedClaim($queued);
        $article = YencArticles::part('hello', 'movie.mkv', 5, 1, 1);
        $this->mock(NNTPService::class, static function ($mock) use ($article): void {
            $mock->shouldReceive('doConnect')->with(false)->andReturnTrue();
            $mock->shouldReceive('isConnected')->andReturnTrue();
            // These tests exercise publication; the ordinary bounded single BODY supplies its sample.
            $mock->shouldReceive('verificationPipelineAvailable')->andReturnFalse();
            $mock->shouldReceive('verificationArticle')->andReturn($article);
            $mock->shouldReceive('doQuit')->andReturnTrue();
        });
        Sleep::fake();

        return [$active, $queued];
    }

    /** @return array<string, mixed> */
    private function runWorker(): array
    {
        $reports = [];
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->andReturnUsing(static function (string $message, array $report) use (&$reports): void {
            $reports[] = $report;
        });
        Log::shouldReceive('channel')->with('daily')->andReturn($logger);
        Log::shouldReceive('warning')->andReturnNull();
        $this->assertSame(0, Artisan::call('releases:verify-candidates', ['--slot' => 1, '--limit' => 2,
            '--diagnostics' => true, '--no-summary' => true]));
        $this->assertCount(1, $reports);

        return $reports[0];
    }

    private function retainScratch(object $claim): void
    {
        $directory = config('release_verification.storage_path').'/scratch/'.$claim->id.'-'.$claim->version;
        app('files')->ensureDirectoryExists($directory);
        file_put_contents($directory.'/retained-part', 'Previously downloaded scratch.');
    }

    /** @return iterable<string, array{string, int}> */
    public static function responses(): iterable
    {
        yield 'response arrives after primary clears lease' => ['returned', 1];
        yield 'first response lost, idempotent retry succeeds' => ['first_lost', 2];
        yield 'every response lost, exact committed generation recovered' => ['all_lost', 3];
    }

    #[DataProvider('responses')]
    public function test_remote_progress_never_renews_the_primarys_active_row_and_success_cleans_scratch(string $mode, int $expectedRequests): void
    {
        [$active, $queued] = $this->prepareWorker();
        $requests = 0;
        $inFlight = false;
        $renewed = [];
        DB::listen(static function (QueryExecuted $query) use (&$inFlight, &$renewed, $active, $queued): void {
            if ($inFlight && str_starts_with($query->sql, 'update "release_verification_candidates" set "lease_until" =')) {
                foreach ([$active->id, $queued->id] as $id) {
                    if (in_array($id, $query->bindings, true)) {
                        $renewed[] = (int) $id;
                    }
                }
            }
        });
        Http::fake(function (Request $request, array $options) use (&$requests, &$inFlight, $active, $mode) {
            $requests++;
            if ($requests === 1) {
                $this->retainScratch((object) $request['claim']);
            }
            $response = $this->withServerVariables(self::PEER)->postJson('/internal/release-verification/publish', $request->data());
            $this->assertSame(200, $response->status());
            $this->assertIsInt($response->json('release_id'));
            $this->assertNull($this->candidateRow($active)->lease_token);
            VerificationWorkerLocks::pause('publication-progress-test', 'Stop after this publication.');
            // Cross the real coalescing interval (1s lease / 3) without a 30s production wait.
            usleep(350_000);
            $inFlight = true;
            try {
                $options['progress'](0, 0, 0, 0);
            } finally {
                $inFlight = false;
            }
            if ($mode === 'all_lost' || ($mode === 'first_lost' && $requests === 1)) {
                throw new ConnectionException('The primary committed but its response was lost.');
            }

            return Http::response($response->json(), $response->status());
        });

        $report = $this->runWorker();

        $this->assertSame($expectedRequests, $requests);
        $this->assertNotContains((int) $active->id, $renewed, 'The HTTP callback must not update the row the primary owns.');
        $this->assertContains((int) $queued->id, $renewed, 'Queued renewals must continue independently of the cleared active lease.');
        $this->assertSame(1, DB::table('releases')->count());
        $this->assertSame(1, $this->installedNzbs());
        $this->assertSame('sampled', $this->candidateRow($active)->status);
        $this->assertSame('ready', $this->candidateRow($queued)->status);
        $this->assertNull($this->candidateRow($queued)->lease_token);
        $this->assertSame(1, $report['publish_returned']);
        $this->assertSame(0, $report['candidate_errors']);
        $this->assertSame(0, $report['finish_returned']);
        $this->assertStringNotContainsString('retry pending', Artisan::output());
        $root = (string) config('release_verification.storage_path');
        $this->assertSame([], glob($root.'/scratch/*') ?: []);
        $this->assertSame(0, DB::table('release_verification_scratch')->count());
        $this->assertFileExists($root.'/status.json');
        $this->assertGreaterThan(0, json_decode(file_get_contents($root.'/status.json'), true, flags: JSON_THROW_ON_ERROR)['verified_bytes_per_second']);
    }

    /** @return iterable<string, array{string}> */
    public static function changedGenerations(): iterable
    {
        yield 'version changed' => ['version'];
        yield 'manifest changed' => ['manifest_hash'];
        yield 'publication mapping absent' => ['release_id'];
        yield 'candidate mapping deleted' => ['deleted'];
    }

    #[DataProvider('changedGenerations')]
    public function test_lost_responses_cannot_recover_a_different_or_unpublished_generation(string $changed): void
    {
        [$active] = $this->prepareWorker();
        $requests = 0;
        Http::fake(function (Request $request) use (&$requests, $active, $changed) {
            if (++$requests === 1) {
                $this->retainScratch((object) $request['claim']);
                $response = $this->withServerVariables(self::PEER)->postJson('/internal/release-verification/publish', $request->data());
                $this->assertSame(200, $response->status());
                $row = $this->candidateRow($active);
                $candidate = DB::table('release_verification_candidates')->where('id', $active->id);
                if ($changed === 'deleted') {
                    $candidate->delete();
                } else {
                    $candidate->update([
                        $changed => match ($changed) {
                            'version' => (int) $row->version + 1,
                            'manifest_hash' => str_repeat('f', 64),
                            'release_id' => null,
                        },
                    ]);
                }
                VerificationWorkerLocks::pause('publication-progress-test', 'Stop after this publication.');
            }
            throw new ConnectionException('Lost response for a generation that cannot be recovered.');
        });

        $report = $this->runWorker();

        $this->assertSame(3, $requests);
        $this->assertSame(0, $report['publish_returned']);
        $this->assertSame(1, $report['candidate_errors']);
        $this->assertSame(1, $report['finish_returned']);
        $this->assertStringContainsString('retry pending', Artisan::output());
        $root = (string) config('release_verification.storage_path');
        $this->assertFileDoesNotExist($root.'/status.json');
        $this->assertSame(1, DB::table('release_verification_scratch')->count());
        $this->assertNotEmpty(glob($root.'/scratch/*') ?: []);
        $this->assertSame(1, DB::table('releases')->count());
        $this->assertSame(1, $this->installedNzbs());
    }
}
