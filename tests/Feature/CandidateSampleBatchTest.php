<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\NNTP\NNTPService;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\CandidateSampleBatch;
use App\Services\Releases\Verification\ManifestStore;
use App\Services\Releases\Verification\NntpArticleSource;
use App\Services\Releases\Verification\VerificationWorkerLocks;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class CandidateSampleBatchTest extends ReleaseVerificationCandidatesTest
{
    public function test_command_verifies_a_prefetched_batch_without_reissuing_bodies_or_publishing_bad_yenc(): void
    {
        config(['release_verification.sample_batch_size' => 2, 'release_verification.article_bytes' => 1024,
            'release_verification.download_buffer_bytes' => 2048, 'release_verification.download_connections' => 1,
            'release_verification.pipeline_window' => 2, 'release_verification.free_bytes' => 0]);
        foreach (['a', 'b'] as $id) {
            app(CandidateRepository::class)->enqueue(['identity' => $id, 'groups_id' => 1, 'expected_files' => 1,
                'files' => [['key' => 'file', 'totalparts' => 1, 'segments' => [['number' => 1, 'messageid' => $id.'@test', 'bytes' => 100]]]]]);
        }
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($pair[1], "222 0 <a@test> body\r\nbad yenc\r\n.\r\n222 0 <b@test> body\r\nbad yenc\r\n.\r\n");
        $nntp = new class($pair[0]) extends NNTPService
        {
            public int $singleReads = 0;

            /** @param resource $socket */
            public function __construct($socket)
            {
                $this->_socket = $socket;
                $this->_socketTimeout = 1;
            }

            public function doConnect(bool $compression = true, bool $alternate = false): mixed
            {
                return $this->isConnected();
            }

            public function verificationArticle(string $messageId, int $maximumBytes, ?array &$responseMetadata = null): string
            {
                $this->singleReads++;
                throw new \RuntimeException('A prefetched article must not be fetched again.');
            }

            public function doQuit(bool $force = false): mixed
            {
                if (is_resource($this->_socket)) {
                    fclose($this->_socket);
                }
                $this->_socket = null;

                return true;
            }
        };
        $this->app->instance(NNTPService::class, $nntp);
        try {
            $this->assertSame(0, Artisan::call('releases:verify-candidates', ['--limit' => 2, '--slot' => 1, '--no-summary' => true]));
            $this->assertSame(0, $nntp->singleReads);
            $this->assertSame(2, DB::table('release_verification_candidates')->where('status', 'unknown')->whereNull('release_id')->count());
            stream_set_blocking($pair[1], false);
            $this->assertSame("BODY <a@test>\r\nBODY <b@test>\r\n", stream_get_contents($pair[1]));
        } finally {
            $nntp->doQuit();
            fclose($pair[1]);
        }
    }

    public function test_batch_claims_are_bounded_and_a_lost_queued_lease_is_never_returned(): void
    {
        config(['release_verification.sample_batch_size' => 3, 'release_verification.article_bytes' => 1024,
            'release_verification.download_buffer_bytes' => 8192, 'release_verification.download_connections' => 1,
            'release_verification.pipeline_window' => 4]);
        $repository = app(CandidateRepository::class);
        foreach (['a', 'b', 'c', 'd'] as $id) {
            $repository->enqueue(['identity' => $id, 'groups_id' => 1, 'expected_files' => 1,
                'files' => [['key' => 'file', 'totalparts' => 1, 'segments' => [['number' => 1, 'messageid' => $id.'@test', 'bytes' => 5]]]]]);
        }
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('doConnect')->andReturn(false);
        $locks = new VerificationWorkerLocks;
        $this->assertTrue($locks->start(1));
        $batch = new CandidateSampleBatch($repository, app(ManifestStore::class), $locks,
            new NntpArticleSource($nntp, maximumBytes: 1024, allowAlternateFallback: false), (array) config('release_verification'));
        try {
            $first = $batch->next(4, static fn (): bool => true);
            $this->assertSame('a', $first->identity);
            $this->assertSame(3, DB::table('release_verification_candidates')->where('status', 'verifying')->count());
            $lost = DB::table('release_verification_candidates')->where('identity', 'b')->first();
            DB::table('release_verification_candidates')->where('id', $lost->id)->update(['status' => 'dirty', 'lease_token' => null]);
            $batch->heartbeat();
            $this->assertFalse($locks->protects((int) $lost->id));
            $this->assertSame('c', $batch->next(3, static fn (): bool => false)->identity);
            $this->assertNull($batch->next(2, static fn (): bool => false));
            $this->assertSame('ready', DB::table('release_verification_candidates')->where('identity', 'd')->value('status'));
        } finally {
            $locks->close();
        }
    }
}
