<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\NNTP\NNTPService;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\YencService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class PublishedVerificationScratchCleanupTest extends ReleaseVerificationCandidatesTest
{
    /** @return iterable<string, array{bool}> */
    public static function publicationInterruptions(): iterable
    {
        yield 'worker killed after publication' => [false];
        yield 'after-commit callback failed' => [true];
    }

    #[DataProvider('publicationInterruptions')]
    public function test_next_worker_cleans_durable_publication_after_interruption(bool $callbackFailure): void
    {
        $directory = $this->candidateScratch(1, 'pending');
        try {
            DB::transaction(function () use ($callbackFailure): void {
                DB::table('release_verification_candidates')->where('id', 1)->update(['status' => 'complete', 'release_id' => 77]);
                if ($callbackFailure) {
                    DB::afterCommit(static function (): void {
                        throw new RuntimeException('Simulated failed publication callback.');
                    });
                }
            });
        } catch (RuntimeException $error) {
            $this->assertTrue($callbackFailure);
            $this->assertSame('Simulated failed publication callback.', $error->getMessage());
        }
        $this->assertFileExists($directory.'/payload');

        $this->artisan('releases:verify-candidates')->assertSuccessful();

        $this->assertDirectoryDoesNotExist($directory);
        $this->assertSame(77, DB::table('release_verification_candidates')->value('release_id'));
    }

    public function test_failed_file_removal_is_retried_on_next_worker(): void
    {
        $directory = $this->candidateScratch(1);
        $filesystem = new Filesystem;
        $attempts = 0;
        File::partialMock()->shouldReceive('delete')->andReturnUsing(function ($path) use ($filesystem, &$attempts): bool {
            return ++$attempts === 1 ? false : $filesystem->delete($path);
        });

        $this->artisan('releases:verify-candidates')->assertSuccessful();
        $this->assertFileExists($directory.'/payload');
        $this->artisan('releases:verify-candidates')->assertSuccessful();
        $this->assertDirectoryDoesNotExist($directory);
        $this->assertSame(2, $attempts);
    }

    public function test_sweep_preserves_pending_active_and_newer_reimport_versions(): void
    {
        $published = $this->candidateScratch(1);
        $pending = $this->candidateScratch(2, 'pending');
        $active = $this->candidateScratch(3, 'verifying');
        $unrepairable = $this->candidateScratch(4, 'unrepairable');
        DB::table('release_verification_candidates')->where('id', 3)->update(['lease_token' => 'active', 'lease_until' => now()->addHour()]);
        $newer = dirname($published).'/1-2';
        mkdir($newer);
        file_put_contents($newer.'/payload', 'new version');
        $filesystem = new Filesystem;
        File::partialMock()->shouldReceive('delete')->andReturnUsing(function ($path) use ($filesystem): bool {
            DB::table('release_verification_candidates')->where('id', 1)->update(['status' => 'pending', 'release_id' => null, 'version' => 2]);

            return $filesystem->delete($path);
        });

        $this->artisan('releases:verify-candidates')->assertSuccessful();

        $this->assertDirectoryDoesNotExist($published);
        foreach ([$pending, $active, $unrepairable, $newer] as $directory) {
            $this->assertFileExists($directory.'/payload');
        }
    }

    public function test_sweep_recovers_expired_filtered_and_repaired_scratch_left_after_cleanup_failure(): void
    {
        $directories = [];
        foreach (['expired', 'filtered', 'repaired'] as $index => $status) {
            $directories[] = $this->candidateScratch($index + 1, $status);
        }

        $this->artisan('releases:verify-candidates')->assertSuccessful();

        foreach ($directories as $directory) {
            $this->assertDirectoryDoesNotExist($directory);
        }
    }

    public function test_worker_lock_prevents_recovery_during_another_worker_run(): void
    {
        $directory = $this->candidateScratch(1);
        $lock = fopen(config('release_verification.storage_path').'/worker.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $this->artisan('releases:verify-candidates')->assertSuccessful();
            $this->assertFileExists($directory.'/payload');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $this->artisan('releases:verify-candidates')->assertSuccessful();
        $this->assertDirectoryDoesNotExist($directory);
    }

    public function test_reclaimed_space_is_available_before_next_payload_download(): void
    {
        $directory = $this->candidateScratch(1);
        file_put_contents($directory.'/payload', str_repeat('x', 1024));
        config(['release_verification.scratch_bytes' => 512, 'release_verification.free_bytes' => 0, 'release_verification.bytes_per_second' => 0]);
        app(CandidateRepository::class)->enqueue(['identity' => sha1('new-payload'), 'groups_id' => 1, 'expected_files' => 2,
            'files' => [['key' => 'file', 'subject' => 'movie.mkv', 'totalparts' => 1,
                'segments' => [['number' => 1, 'messageid' => 'new@article', 'bytes' => 5]]]]]);
        $this->mock(NNTPService::class, function ($mock): void {
            $mock->shouldReceive('doConnect')->once()->with(false)->andReturn(true);
            $mock->shouldReceive('verificationArticle')->once()->andReturn((new YencService)->encode('hello', 'movie.mkv'));
            $mock->shouldReceive('doQuit')->andReturn(null);
        });

        $this->artisan('releases:verify-candidates')->assertSuccessful();

        $this->assertDirectoryDoesNotExist($directory);
        $candidate = DB::table('release_verification_candidates')->whereNull('release_id')->sole();
        $this->assertSame('Declared file inventory cannot be proved complete.', $candidate->reason);
        $this->assertFileExists(dirname($directory).'/'.$candidate->id.'-1/articles/'.hash('sha256', 'new@article'));
    }

    public function test_sweep_is_bounded_and_resumes_after_an_entry_budget(): void
    {
        $directory = $this->candidateScratch(1);
        unlink($directory.'/payload');
        foreach (range(1, 1001) as $number) {
            file_put_contents($directory.'/'.$number, 'x');
        }
        $this->artisan('releases:verify-candidates')->assertSuccessful();
        $this->assertGreaterThan(0, count(glob($directory.'/*')));
        $this->assertLessThan(1001, count(glob($directory.'/*')));
        $this->artisan('releases:verify-candidates')->assertSuccessful();
        $this->assertDirectoryDoesNotExist($directory);
    }

    public function test_sweep_advances_past_one_hundred_actual_directories(): void
    {
        foreach (range(1, 101) as $id) {
            $this->candidateScratch($id);
        }
        $root = config('release_verification.storage_path').'/scratch';
        $this->artisan('releases:verify-candidates')->assertSuccessful();
        $this->assertCount(1, glob($root.'/*'));
        $this->candidateScratch(102);
        $this->artisan('releases:verify-candidates')->assertSuccessful();
        $this->assertCount(1, glob($root.'/*'));
        $this->artisan('releases:verify-candidates')->assertSuccessful();
        $this->assertCount(0, glob($root.'/*'));
    }

    public function test_historical_rows_without_scratch_do_not_delay_recovery(): void
    {
        foreach (range(1, 101) as $id) {
            $directory = $this->candidateScratch($id);
            File::deleteDirectory($directory);
        }
        $directory = $this->candidateScratch(102);

        $this->artisan('releases:verify-candidates')->assertSuccessful();

        $this->assertDirectoryDoesNotExist($directory);
    }

    public function test_terminal_candidate_recovers_older_versions_but_preserves_future_versions(): void
    {
        $directory = $this->candidateScratch(1, 'expired');
        DB::table('release_verification_candidates')->where('id', 1)->update(['version' => 2]);
        $future = dirname($directory).'/1-3';
        mkdir($future);
        file_put_contents($future.'/payload', 'future');

        $this->artisan('releases:verify-candidates')->assertSuccessful();

        $this->assertDirectoryDoesNotExist($directory);
        $this->assertFileExists($future.'/payload');
    }

    public function test_sweep_resumes_past_preserved_pending_directories(): void
    {
        foreach (range(1, 101) as $id) {
            $this->candidateScratch($id, 'pending');
        }
        $entries = iterator_to_array(new \FilesystemIterator(config('release_verification.storage_path').'/scratch'));
        $last = end($entries)->getPathname();
        $id = (int) explode('-', basename($last))[0];
        $this->artisan('releases:verify-candidates')->assertSuccessful();
        DB::table('release_verification_candidates')->where('id', $id)->update(['status' => 'filtered']);

        $this->artisan('releases:verify-candidates')->assertSuccessful();

        $this->assertDirectoryDoesNotExist($last);
        $this->assertCount(100, glob(config('release_verification.storage_path').'/scratch/*'));
    }

    public function test_failed_cleanup_is_retried_even_while_pending_directories_keep_arriving(): void
    {
        foreach (range(1, 101) as $id) {
            $this->candidateScratch($id, 'pending');
        }
        $root = config('release_verification.storage_path').'/scratch';
        $entries = new \FilesystemIterator($root);
        $failed = $entries->current()->getPathname();
        $id = (int) explode('-', basename($failed))[0];
        DB::table('release_verification_candidates')->where('id', $id)->update(['status' => 'filtered']);
        $filesystem = new Filesystem;
        $attempts = 0;
        File::partialMock()->shouldReceive('delete')->andReturnUsing(function ($path) use ($filesystem, &$attempts): bool {
            return ++$attempts === 1 ? false : $filesystem->delete($path);
        });
        $this->artisan('releases:verify-candidates')->assertSuccessful();
        $this->assertFileExists($failed.'/payload');

        foreach (range(1, 3) as $run) {
            foreach (range(1, 101) as $newId) {
                $this->candidateScratch($run * 101 + $newId, 'pending');
            }
            $this->artisan('releases:verify-candidates')->assertSuccessful();
        }

        $this->assertDirectoryDoesNotExist($failed);
        $this->assertSame(2, $attempts);
        $this->assertCount(403, glob($root.'/*'));
    }

    public function test_sweep_does_not_follow_candidate_or_child_symlinks(): void
    {
        $linked = $this->candidateScratch(1);
        $directory = $this->candidateScratch(2);
        $outside = config('release_verification.storage_path').'/outside';
        mkdir($outside);
        file_put_contents($outside.'/sentinel', 'preserved');
        File::deleteDirectory($linked);
        symlink($outside, $linked);
        symlink($outside, $directory.'/linked');

        $this->artisan('releases:verify-candidates')->assertSuccessful();

        $this->assertFileExists($outside.'/sentinel');
        $this->assertTrue(is_link($linked));
        $this->assertDirectoryDoesNotExist($directory);
    }

    public function test_sweep_does_not_follow_a_symlinked_scratch_root(): void
    {
        $directory = $this->candidateScratch(1);
        $root = config('release_verification.storage_path');
        rename($root.'/scratch', $root.'/outside');
        symlink($root.'/outside', $root.'/scratch');

        $this->artisan('releases:verify-candidates')->assertSuccessful();

        $this->assertFileExists($directory.'/payload');
        $this->assertFileExists($root.'/outside/1-1/payload');
    }

    private function candidateScratch(int $id, string $status = 'complete'): string
    {
        DB::table('release_verification_candidates')->insert(['id' => $id, 'identity' => sha1('scratch-'.$id), 'groups_id' => 1,
            'version' => 1, 'status' => $status, 'release_id' => in_array($status, ['complete', 'repaired'], true) ? 77 : null,
            'manifest_path' => '', 'manifest_hash' => str_repeat('a', 64), 'manifest_bytes' => 0,
            'last_unique_at' => now(), 'next_attempt_at' => now()->addHour()]);
        $directory = config('release_verification.storage_path').'/scratch/'.$id.'-1';
        mkdir($directory, 0700, true);
        file_put_contents($directory.'/payload', 'old payload');

        return $directory;
    }
}
