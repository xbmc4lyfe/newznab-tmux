<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\VerificationWorkerLocks;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PDOException;

class VerificationCandidateClaimTest extends ReleaseVerificationCandidatesTest
{
    public function test_claim_priority_merges_due_times_and_id_ties_across_statuses(): void
    {
        $this->freezeTime();
        $repository = app(CandidateRepository::class);
        foreach (['pending', 'unknown', 'verifying', 'pending'] as $number => $status) {
            $repository->enqueue(['identity' => sha1('priority-'.$number), 'groups_id' => 1, 'files' => []]);
            DB::table('release_verification_candidates')->where('identity', sha1('priority-'.$number))
                ->update(['status' => $status, 'next_attempt_at' => now()->subMinutes([1, 3, 2, 2][$number])]);
        }
        $attempts = [];
        $claim = $repository->claim(static function (int $id) use (&$attempts): bool {
            $attempts[] = $id;

            return false;
        });

        $this->assertNull($claim);
        $this->assertSame([2, 3, 4, 1], $attempts);
    }

    public function test_claim_window_remains_one_hundred_eligible_candidates_across_statuses(): void
    {
        $this->freezeTime();
        $rows = [];
        for ($number = 1; $number <= 307; $number++) {
            $rows[] = ['identity' => sha1('window-'.$number), 'groups_id' => 1, 'manifest_path' => '',
                'manifest_bytes' => 0, 'manifest_hash' => str_repeat('0', 64),
                'status' => ['pending', 'unknown', 'verifying'][$number % 3],
                'last_unique_at' => now(), 'next_attempt_at' => now()->subHour()];
        }
        DB::table('release_verification_candidates')->insert($rows);
        DB::table('release_verification_candidates')->where('id', 1)->update(['release_id' => 123]);
        DB::table('release_verification_candidates')->where('id', 2)->update(['lease_until' => now()->addHour()]);
        DB::table('release_verification_candidates')->where('id', 3)->update(['next_attempt_at' => now()->addHour()]);
        DB::table('release_verification_candidates')->where('id', 4)->update(['status' => 'dirty']);
        $attempts = [];
        $claim = app(CandidateRepository::class)->claim(static function (int $id) use (&$attempts): bool {
            $attempts[] = $id;

            return false;
        });

        $this->assertNull($claim);
        $this->assertSame(range(5, 104), $attempts);
        $this->assertSame(0, DB::table('release_verification_candidates')->whereNotNull('lease_token')->count());
    }

    public function test_a_window_drained_by_concurrent_claimers_is_read_again(): void
    {
        $this->freezeTime();
        $rows = [];
        for ($number = 1; $number <= 150; $number++) {
            $rows[] = ['identity' => sha1('drained-'.$number), 'groups_id' => 1, 'manifest_path' => '',
                'manifest_bytes' => 0, 'manifest_hash' => str_repeat('0', 64), 'status' => 'unknown',
                'last_unique_at' => now(), 'next_attempt_at' => now()->subHour()->addSeconds($number)];
        }
        DB::table('release_verification_candidates')->insert($rows);
        $windows = 0;
        DB::listen(static function (QueryExecuted $query) use (&$windows): void {
            if (str_starts_with($query->sql, 'select') && str_contains($query->sql, 'order by')
                && in_array('unknown', $query->bindings, true) && ++$windows === 1) {
                // Other workers lease every row of this worker's first window before it reaches them.
                DB::table('release_verification_candidates')->where('id', '<=', 100)
                    ->update(['status' => 'verifying', 'lease_token' => 'other', 'lease_until' => now()->addMinute()]);
            }
        });
        $attempts = [];
        $claim = app(CandidateRepository::class)->claim(static function (int $id) use (&$attempts): bool {
            $attempts[] = $id;

            return true;
        }, false);

        $this->assertNotNull($claim);
        $this->assertSame(101, (int) $claim->id);
        $this->assertSame([101], $attempts);
        $this->assertGreaterThan(1, $windows);
    }

    public function test_a_window_that_stays_contended_stops_after_bounded_rereads(): void
    {
        $this->freezeTime();
        DB::table('release_verification_candidates')->insert(['identity' => sha1('contended'), 'groups_id' => 1,
            'manifest_path' => '', 'manifest_bytes' => 0, 'manifest_hash' => str_repeat('0', 64), 'status' => 'pending',
            'last_unique_at' => now(), 'next_attempt_at' => now()->subHour()]);
        $windows = 0;
        $lease = static fn (?string $until) => DB::table('release_verification_candidates')->update(['lease_until' => $until]);
        DB::listen(static function (QueryExecuted $query) use (&$windows, $lease): void {
            if (! str_starts_with($query->sql, 'select')) {
                return;
            }
            if (str_contains($query->sql, 'order by') && in_array('pending', $query->bindings, true)) {
                // Another worker leases the row after every window read and releases it after this attempt.
                $windows++;
                $lease(now()->addMinute()->format('Y-m-d H:i:s'));
            } elseif (str_contains($query->sql, '"id" = ?')) {
                $lease(null);
            }
        });

        $this->assertNull(app(CandidateRepository::class)->claim(static fn (): bool => true, false));
        $this->assertSame(10, $windows);
    }

    public function test_status_changes_between_queue_reads_do_not_duplicate_claim_attempts(): void
    {
        $this->freezeTime();
        $repository = app(CandidateRepository::class);
        foreach (['first', 'second'] as $name) {
            $repository->enqueue(['identity' => sha1($name), 'groups_id' => 1, 'files' => []]);
        }
        $changed = false;
        DB::listen(static function (QueryExecuted $query) use (&$changed): void {
            if (! $changed && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'order by')
                && in_array('pending', $query->bindings, true) && ! in_array('unknown', $query->bindings, true)) {
                $changed = true;
                DB::table('release_verification_candidates')->where('id', 1)->update(['status' => 'unknown']);
            }
        });
        $attempts = [];
        $this->assertNull($repository->claim(static function (int $id) use (&$attempts): bool {
            $attempts[] = $id;

            return false;
        }));
        $this->assertSame([1, 2], $attempts);
    }

    public function test_a_retry_that_loses_eligibility_does_not_advance_with_a_held_file_mutex(): void
    {
        $repository = app(CandidateRepository::class);
        foreach (['first', 'second'] as $name) {
            $repository->enqueue(['identity' => sha1($name), 'groups_id' => 1, 'files' => []]);
        }
        $first = (int) DB::table('release_verification_candidates')->orderBy('id')->value('id');
        $once = false;
        DB::listen(static function (QueryExecuted $query) use ($first, &$once): void {
            if (! $once && str_starts_with($query->sql, 'update "release_verification_candidates"')
                && in_array('verifying', $query->bindings, true)) {
                $once = true;
                DB::afterRollBack(static function () use ($first): void {
                    DB::table('release_verification_candidates')->where('id', $first)
                        ->update(['lease_until' => now()->addHour()]);
                });
                throw new PDOException('Deadlock found when trying to get lock; try restarting transaction', 40001);
            }
        });
        $locks = new VerificationWorkerLocks;
        $attempts = [];
        try {
            $this->assertTrue($locks->start());
            $claim = $repository->claim(static function (int $id) use ($locks, &$attempts): bool {
                $attempts[] = $id;

                return $locks->tryCandidate($id);
            });
            $this->assertNull($claim);
            $this->assertSame([$first], $attempts);
            $this->assertTrue($once);
        } finally {
            $locks->close();
        }
    }
}
