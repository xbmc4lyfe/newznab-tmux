<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\NNTP\NNTPService;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\CandidateSampleBatch;
use App\Services\Releases\Verification\ManifestStore;
use App\Services\Releases\Verification\NntpArticleSource;
use App\Services\Releases\Verification\SpotCheckPlanner;
use App\Services\Releases\Verification\VerificationRunDiagnostics;
use App\Services\Releases\Verification\VerificationWorkerLocks;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;

class CandidateSampleBatchTest extends ReleaseVerificationCandidatesTest
{
    /** @return iterable<string, array{int, bool}> */
    public static function sampleCounts(): iterable
    {
        yield 'one sample' => [1, false];
        yield 'multiple samples' => [2, false];
        yield 'deadline returns unstarted work' => [1, true];
    }

    #[DataProvider('sampleCounts')]
    public function test_command_verifies_a_prefetched_batch_without_reissuing_bodies_or_publishing_bad_yenc(int $samples, bool $deadline): void
    {
        config(['release_verification.sample_batch_size' => 2, 'release_verification.article_bytes' => 1024,
            'release_verification.spot_check_articles' => $samples, 'release_verification.download_buffer_bytes' => 4096, 'release_verification.download_connections' => 1,
            'release_verification.pipeline_window' => 4, 'release_verification.free_bytes' => 0]);
        $firstIds = [];
        foreach (['a', 'b'] as $id) {
            $segments = [];
            for ($part = 1; $part <= $samples; $part++) {
                $segments[] = ['number' => $part, 'messageid' => $id.'-'.$part.'@test', 'bytes' => 100];
            }
            $manifest = ['identity' => $id, 'groups_id' => 1, 'expected_files' => 1,
                'files' => [['key' => 'file', 'totalparts' => $samples, 'segments' => $segments]]];
            app(CandidateRepository::class)->enqueue($manifest);
            $firstIds[] = (new SpotCheckPlanner)->select($manifest, $samples, 1024, 1024)[0]['messageid'];
        }
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        foreach ($firstIds as $id) {
            fwrite($pair[1], '222 0 <'.$id."> body\r\nbad yenc\r\n.\r\n");
        }
        $nntp = new class($pair[0], $deadline) extends NNTPService
        {
            public int $singleReads = 0;

            /** @param resource $socket */
            public function __construct($socket, private readonly bool $pause)
            {
                $this->_socket = $socket;
                $this->_socketTimeout = 1;
            }

            public function doConnect(bool $compression = true, bool $alternate = false): mixed
            {
                if ($this->pause) {
                    usleep(1_100_000);
                }

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
            $this->assertSame(0, Artisan::call('releases:verify-candidates', ['--limit' => 2, '--slot' => 1, '--no-summary' => true, '--max-seconds' => $deadline ? 1 : 0]));
            $this->assertSame(0, $nntp->singleReads);
            $this->assertSame($deadline ? 1 : 2, DB::table('release_verification_candidates')->where('status', 'unknown')->whereNull('release_id')->count());
            $this->assertSame($deadline ? 1 : 0, DB::table('release_verification_candidates')->where('status', 'ready')->whereNull('lease_token')->whereNull('lease_until')->count());
            stream_set_blocking($pair[1], false);
            $this->assertSame(implode('', array_map(static fn (string $id): string => 'BODY <'.$id.">\r\n", $firstIds)), stream_get_contents($pair[1]));
        } finally {
            $nntp->doQuit();
            fclose($pair[1]);
        }
    }

    public function test_verified_rate_includes_prefetch_and_accumulates_only_within_one_batch(): void
    {
        config(['release_verification.sample_batch_size' => 2, 'release_verification.article_bytes' => 1024,
            'release_verification.download_buffer_bytes' => 4096, 'release_verification.download_connections' => 1,
            'release_verification.pipeline_window' => 4]);
        $repository = app(CandidateRepository::class);
        foreach (['a', 'b', 'c'] as $id) {
            $repository->enqueue(['identity' => $id, 'groups_id' => 1, 'expected_files' => 1,
                'files' => [['key' => 'file', 'totalparts' => 1, 'segments' => [['number' => 1, 'messageid' => $id.'@test', 'bytes' => 5]]]]]);
        }
        $time = 10.0;
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('doConnect')->andReturnUsing(static function () use (&$time): bool {
            $time += 2.0;

            return false;
        });
        $locks = new VerificationWorkerLocks;
        $this->assertTrue($locks->start(1));
        $batch = new CandidateSampleBatch($repository, app(ManifestStore::class), $locks,
            new NntpArticleSource($nntp, maximumBytes: 1024, allowAlternateFallback: false), (array) config('release_verification'),
            clock: static function () use (&$time): float {
                return $time;
            });
        try {
            $first = $batch->next(3, static fn (): bool => true);
            $time = 13.0;
            $this->assertSame(100.0, $batch->verifiedBytesPerSecond(300, 12.0));
            $locks->releaseCandidate((int) $first->id);
            $second = $batch->next(3, static fn (): bool => false);
            $time = 15.0;
            $this->assertSame(160.0, $batch->verifiedBytesPerSecond(500, 13.0));
            $locks->releaseCandidate((int) $second->id);
            $this->assertNotNull($batch->next(3, static fn (): bool => true));
            $time = 17.0;
            $this->assertSame(200.0, $batch->verifiedBytesPerSecond(400, 15.0));
        } finally {
            $locks->close();
        }
    }

    public function test_a_null_claim_releases_a_lock_acquired_before_transaction_retry(): void
    {
        $repository = app(CandidateRepository::class);
        $repository->enqueue(['identity' => 'retry', 'groups_id' => 1, 'expected_files' => 1,
            'files' => [['key' => 'file', 'totalparts' => 1, 'segments' => [['number' => 1, 'messageid' => 'retry@test', 'bytes' => 5]]]]]);
        $id = (int) DB::table('release_verification_candidates')->value('id');
        $injected = false;
        $rolledBack = false;
        DB::listen(static function (QueryExecuted $query) use (&$injected): void {
            if (! $injected && str_starts_with($query->sql, 'update "release_verification_candidates"')
                && ($query->bindings[0] ?? null) === 'verifying') {
                $injected = true;
                throw new \PDOException('database is locked');
            }
        });
        Event::listen(TransactionRolledBack::class, static function () use (&$injected, &$rolledBack, $id): void {
            if ($injected && ! $rolledBack) {
                $rolledBack = true;
                DB::table('release_verification_candidates')->where('id', $id)->update(['status' => 'dirty']);
            }
        });
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldNotReceive('doConnect');
        $locks = new VerificationWorkerLocks;
        $this->assertTrue($locks->start(1));
        $batch = new CandidateSampleBatch($repository, app(ManifestStore::class), $locks,
            new NntpArticleSource($nntp), (array) config('release_verification'));
        try {
            $this->assertNull($batch->next(1, static fn (): bool => true));
            $this->assertTrue($injected);
            $this->assertTrue($rolledBack);
            $this->assertFalse($locks->protects($id));
        } finally {
            $locks->close();
        }
    }

    public function test_releasing_unstarted_work_returns_its_lease_without_requeueing_an_invalidated_generation(): void
    {
        config(['release_verification.sample_batch_size' => 3, 'release_verification.article_bytes' => 1024,
            'release_verification.download_buffer_bytes' => 8192, 'release_verification.download_connections' => 1,
            'release_verification.pipeline_window' => 4]);
        $repository = app(CandidateRepository::class);
        foreach (['a', 'b', 'c'] as $id) {
            $repository->enqueue(['identity' => $id, 'groups_id' => 1, 'expected_files' => 1,
                'files' => [['key' => 'file', 'totalparts' => 1, 'segments' => [['number' => 1, 'messageid' => $id.'@test', 'bytes' => 5]]]]]);
        }
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('doConnect')->andReturn(false);
        $locks = new VerificationWorkerLocks;
        $this->assertTrue($locks->start(1));
        $diagnostics = new VerificationRunDiagnostics(hrtime(true), '2026-10-20T10:00:00Z', 3, 0, 1);
        $batch = new CandidateSampleBatch($repository, app(ManifestStore::class), $locks,
            new NntpArticleSource($nntp), (array) config('release_verification'), $diagnostics);
        try {
            $first = $batch->next(3, static fn (): bool => true);
            $heartbeats = 0;
            DB::listen(static function (QueryExecuted $query) use (&$heartbeats): void {
                if (str_starts_with($query->sql, 'update "release_verification_candidates"')
                    && str_contains($query->sql, 'lease_until')) {
                    $heartbeats++;
                }
            });
            $batch->pulse();
            $initialHeartbeats = $heartbeats;
            for ($index = 0; $index < 100; $index++) {
                $batch->pulse();
            }
            $this->assertGreaterThan(0, $initialHeartbeats);
            $this->assertSame($initialHeartbeats, $heartbeats, 'Per-file callbacks coalesce queued lease renewals.');
            $invalid = DB::table('release_verification_candidates')->where('identity', 'c')->first();
            DB::table('release_verification_candidates')->where('id', $invalid->id)->update(['status' => 'dirty',
                'lease_token' => null, 'version' => (int) $invalid->version + 1]);
            $batch->releasePending();
            $this->assertFalse($batch->hasPending());
            $report = $diagnostics->report(hrtime(true));
            $this->assertSame(3, $report['claims_admitted']);
            $this->assertSame(1, $report['queued_returned']);
            $this->assertSame(1, $report['queued_lost']);
            $this->assertSame(0, $report['queued_return_failed']);
            $returned = DB::table('release_verification_candidates')->where('identity', 'b')->first();
            $this->assertSame('ready', $returned->status);
            $this->assertNull($returned->lease_token);
            $this->assertNull($returned->lease_until);
            $this->assertFalse($locks->protects((int) $returned->id));
            $this->assertSame('dirty', DB::table('release_verification_candidates')->where('identity', 'c')->value('status'));
            $this->assertTrue($locks->protects((int) $first->id), 'The active candidate is not returned by queued cleanup.');
        } finally {
            $locks->close();
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function queuedRenewalTransactions(): iterable
    {
        yield 'outside publication transaction' => [false];
        yield 'inside publication transaction' => [true];
    }

    #[DataProvider('queuedRenewalTransactions')]
    public function test_queued_renewal_failure_is_isolated_only_outside_a_publication_transaction(bool $transaction): void
    {
        config(['release_verification.sample_batch_size' => 2, 'release_verification.download_connections' => 1,
            'release_verification.pipeline_window' => 4]);
        $repository = app(CandidateRepository::class);
        foreach (['a', 'b'] as $id) {
            $repository->enqueue(['identity' => $id, 'groups_id' => 1, 'expected_files' => 1,
                'files' => [['key' => 'file', 'totalparts' => 1, 'segments' => [['number' => 1, 'messageid' => $id.'@test', 'bytes' => 5]]]]]);
        }
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('doConnect')->andReturn(false);
        $locks = new VerificationWorkerLocks;
        $this->assertTrue($locks->start(1));
        $batch = new CandidateSampleBatch($repository, app(ManifestStore::class), $locks,
            new NntpArticleSource($nntp), (array) config('release_verification'));
        try {
            $first = $batch->next(2, static fn (): bool => true);
            $queuedId = (int) DB::table('release_verification_candidates')->where('identity', 'b')->value('id');
            $armed = true;
            $failure = new \RuntimeException('Queued lease database failure');
            DB::listen(static function (QueryExecuted $query) use (&$armed, $queuedId, $failure): void {
                if ($armed && str_starts_with($query->sql, 'update "release_verification_candidates"')
                    && in_array($queuedId, $query->bindings, true)) {
                    $armed = false;
                    throw $failure;
                }
            });
            if ($transaction) {
                DB::beginTransaction();
            }
            try {
                $batch->heartbeat();
                $this->assertFalse($transaction, 'An error inside publication must escape rather than mask a rollback.');
            } catch (\RuntimeException $error) {
                $this->assertTrue($transaction);
                $this->assertSame($failure, $error);
            } finally {
                if ($transaction) {
                    DB::rollBack();
                }
            }
            $this->assertSame('verifying', DB::table('release_verification_candidates')->where('id', $first->id)->value('status'));
            if (! $transaction) {
                $this->assertSame('ready', DB::table('release_verification_candidates')->where('id', $queuedId)->value('status'));
                $this->assertFalse($batch->hasPending());
                $this->assertFalse($locks->protects($queuedId));
            }
        } finally {
            $batch->releasePending();
            $locks->close();
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
            $first = $batch->next(3, static fn (): bool => true);
            $this->assertSame('a', $first->identity);
            $this->assertSame(3, DB::table('release_verification_candidates')->where('status', 'verifying')->count());
            $lost = DB::table('release_verification_candidates')->where('identity', 'b')->first();
            DB::table('release_verification_candidates')->where('id', $lost->id)->update(['status' => 'dirty', 'lease_token' => null]);
            $batch->heartbeat();
            $this->assertFalse($locks->protects((int) $lost->id));
            $this->assertSame('c', $batch->next(3, static fn (): bool => false)->identity);
            $this->assertNull($batch->next(3, static fn (): bool => true));
            $this->assertSame('ready', DB::table('release_verification_candidates')->where('identity', 'd')->value('status'));
        } finally {
            $locks->close();
        }
    }
}
