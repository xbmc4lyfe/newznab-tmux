<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Models\Release;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class VerificationResetService
{
    /** Hold both locks across legacy TRUNCATE statements, which implicitly commit on MySQL. */
    public function run(callable $reset): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Database reset cannot run inside an open transaction.');
        }
        $store = VerificationLockStore::make();
        $admissionLock = $this->lock($store->exclusiveGroup('reset'));
        try {
            $workerLock = $this->lock($store->exclusiveGroup('worker'));
            try {
                // Checked while holding both gates, so a pause cannot appear unseen before the work.
                if (VerificationWorkerLocks::paused()) {
                    throw new RuntimeException('A deployment pause holds verification workers; retry reset after it is released.');
                }
                // A durable pause fences the whole reset, including filesystem cleanup that a lost
                // session could not stop. Removing it needs the pinned session, so after a lost
                // session it stays until an operator runs releases:verification-barrier --release --all.
                $pause = VerificationWorkerLocks::pauseIfAvailable('reset-'.bin2hex(random_bytes(8)), 'reset');
                try {
                    $this->clearVerification();
                    $reset();
                } finally {
                    if ($pause !== null) {
                        VerificationWorkerLocks::unpause($pause);
                    }
                }
            } finally {
                $store->release($workerLock);
            }
        } finally {
            $store->release($admissionLock);
        }
    }

    /** Retain admission protection until the caller's outer transaction finishes. */
    public function withAdmission(callable $admit): void
    {
        $store = VerificationLockStore::make();
        $lock = $this->lock($store->shared('reset'));
        // A reset that lost its session keeps its durable pause while its cleanup may still run.
        if (VerificationWorkerLocks::paused(null, 'reset', current: true)) {
            $store->release($lock);

            throw new RuntimeException('Verification worker, admission or reset is active; retry reset later.');
        }
        try {
            $admit();
        } finally {
            if (DB::transactionLevel() > 0) {
                $release = static fn () => $store->release($lock);
                DB::afterCommit($release);
                DB::afterRollBack($release);
            } else {
                $store->release($lock);
            }
        }
    }

    private function clearVerification(): void
    {
        DB::transaction(function (): void {
            if (Schema::hasTable('release_verification_state')) {
                DB::table('release_verification_state')->where('id', 1)->lockForUpdate()->first();
            }
            // Other worker hosts keep their scratch records and budgets, marked terminal: only they
            // can remove their directories.
            $hosts = Schema::hasTable('release_verification_scratch') && Schema::hasColumn('release_verification_scratch', 'host');
            if ($hosts) {
                DB::table('release_verification_scratch')->where('host', '<>', ScratchInventory::host())->update(['terminal' => true]);
            }
            if (Release::hasLateArticleFlag()) {
                // Partials keep no inventory once candidates are gone; let NZB-reading workers process them.
                DB::table('releases')->whereNotNull('late_article_candidate_id')->update(['late_article_candidate_id' => null]);
            }
            foreach (['release_verification_scratch', 'release_verification_candidates', 'release_verification_audit_retries', 'release_verification_manifests'] as $table) {
                if (Schema::hasTable($table)) {
                    $query = DB::table($table);
                    if ($table === 'release_verification_scratch' && $hosts) {
                        $query->where('host', ScratchInventory::host());
                    }
                    $query->delete();
                }
            }
            if (Schema::hasTable('release_verification_scratch_budget')) {
                if (Schema::hasColumn('release_verification_scratch_budget', 'host')) {
                    DB::table('release_verification_scratch_budget')->where('host', ScratchInventory::host())->delete();
                    if (ScratchInventory::host() === '') {
                        DB::table('release_verification_scratch_budget')->insert(['id' => 1]);
                    }
                } else {
                    DB::table('release_verification_scratch_budget')->delete();
                    DB::table('release_verification_scratch_budget')->insert(['id' => 1]);
                }
            }
            if (Schema::hasTable('release_verification_state')) {
                DB::table('release_verification_state')->delete();
                $state = ['id' => 1, 'pending_bytes' => 0, 'audit_cursor' => 0];
                if (Schema::hasColumn('release_verification_state', 'audit_retry_turn')) {
                    $state['audit_retry_turn'] = false;
                }
                DB::table('release_verification_state')->insert($state);
            }
        });

        $root = (string) config('release_verification.storage_path');
        File::ensureDirectoryExists($root, 0700);
        foreach (new \FilesystemIterator($root, \FilesystemIterator::SKIP_DOTS) as $entry) {
            if (in_array($entry->getFilename(), ['worker.lock', 'reset.lock', 'locks', '.gitignore'], true)) {
                continue;
            }
            $deleted = $entry->isDir() && ! $entry->isLink()
                ? File::deleteDirectory($entry->getPathname())
                : File::delete($entry->getPathname());
            if (! $deleted) {
                throw new RuntimeException('Cannot remove verification reset artifacts.');
            }
        }
    }

    private function lock(?VerificationLock $lock): VerificationLock
    {
        return $lock ?? throw new RuntimeException('Verification worker, admission or reset is active; retry reset later.');
    }
}
