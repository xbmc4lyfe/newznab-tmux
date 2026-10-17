<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\NNTP\NNTPService;
use App\Services\Releases\Verification\AuditReleaseDeletion;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\LeaseHeartbeat;
use App\Services\Releases\Verification\ManifestStore;
use App\Services\Releases\Verification\NntpArticleSource;
use App\Services\Releases\Verification\RemoteReleasePublisher;
use App\Services\Releases\Verification\ScratchBudget;
use App\Services\Releases\Verification\ScratchInventory;
use App\Services\Releases\Verification\SpotCheckVerifier;
use App\Services\Releases\Verification\VerificationResult;
use App\Services\Releases\Verification\VerificationRunDiagnostics;
use App\Services\Releases\Verification\VerificationWorkerLocks;
use App\Services\Releases\Verification\VerifiedReleasePublisher;
use App\Services\YencService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class VerifyReleaseCandidates extends Command
{
    protected $signature = 'releases:verify-candidates {--limit=1 : Maximum candidates in this run} {--slot=0 : Worker slot; zero selects a free slot} {--max-seconds=0 : Stop claiming after this duration; finish the active candidate} {--no-summary : Skip global candidate status counts} {--diagnostics : Log bounded natural verification run counters and timings}';

    protected $description = 'Spot check bounded articles before publishing complete candidate NZBs';

    public function handle(CandidateRepository $repository, ManifestStore $store, VerifiedReleasePublisher $publisher, ScratchInventory $scratchInventory, ScratchBudget $scratchBudget): int
    {
        $diagnostics = $this->option('diagnostics') ? new VerificationRunDiagnostics(hrtime(true), now()->utc()->toIso8601String(),
            max(1, (int) $this->option('limit')), max(0, (int) $this->option('max-seconds')), max(0, (int) $this->option('slot'))) : null;
        try {
            $exitCode = $this->runVerification($repository, $store, $publisher, $scratchInventory, $scratchBudget, $diagnostics);
            $diagnostics?->returned($exitCode);

            return $exitCode;
        } catch (Throwable $error) {
            $diagnostics?->failed(hrtime(true));

            throw $error;
        } finally {
            $diagnostics?->observe(static function (array $report): void {
                Log::channel('daily')->info('Candidate verification run summary.', $report);
            });
        }
    }

    private function runVerification(CandidateRepository $repository, ManifestStore $store, VerifiedReleasePublisher $publisher,
        ScratchInventory $scratchInventory, ScratchBudget $scratchBudget, ?VerificationRunDiagnostics $diagnostics): int
    {
        $root = (string) config('release_verification.storage_path');
        File::ensureDirectoryExists($root, 0700);
        try {
            if (! $scratchBudget->initialize()) {
                $diagnostics?->stop('initialization_unavailable', hrtime(true));
                $this->info('Verification scratch accounting is waiting for exclusive access.');

                return self::SUCCESS;
            }
        } catch (Throwable $error) {
            $diagnostics?->stop('initialization_error', hrtime(true));
            $this->error($error->getMessage());

            return self::FAILURE;
        }
        $locks = new VerificationWorkerLocks;
        if (! $locks->start(max(0, (int) $this->option('slot')))) {
            $diagnostics?->stop('worker_unavailable', hrtime(true));
            $this->info('Verification worker slots are occupied or exclusive maintenance is running.');

            return self::SUCCESS;
        }
        $diagnostics?->workerAcquired();
        try {
            if (! $this->scratchRootIsSafe($root.'/scratch')) {
                $diagnostics?->stop('unsafe_scratch_root', hrtime(true));
                $this->error('Verification scratch root must be an accessible directory, not a symlink.');

                return self::FAILURE;
            }
            $diagnostics?->maintenanceSkipped();
            if ($locks->tryGc()) {
                $diagnostics?->maintenanceStarted(hrtime(true));
                $maintenanceSuccessful = false;
                try {
                    // A slow maintenance backlog must not monopolize a verification slot.
                    // Finish admitted transactions and individual filesystem operations; retain partial directories.
                    $expiryFirst = $locks->preferExpiry();
                    $maintenanceDeadline = hrtime(true) + 5_000_000_000;
                    $mayMaintain = static fn (): bool => hrtime(true) < $maintenanceDeadline;
                    // Each host cleans its own scratch; expiry is shared, so another host may hold it.
                    $expire = $locks->tryExpiry();
                    if ($expiryFirst && $expire) {
                        $repository->expire($mayMaintain);
                    }
                    $this->cleanupTerminalScratch($root, $locks, $mayMaintain);
                    if (! $expiryFirst && $expire) {
                        $repository->expire($mayMaintain);
                    }
                    $maintenanceSuccessful = true;
                } finally {
                    $locks->releaseGc();
                    $diagnostics?->maintenanceFinished(hrtime(true), $maintenanceSuccessful);
                }
            }
            $decoder = app(YencService::class);
            $runStarted = hrtime(true);
            $diagnostics?->loopStarted($runStarted);
            $maxSeconds = max(0, (int) $this->option('max-seconds'));
            for ($index = 0; $index < max(1, (int) $this->option('limit')); $index++) {
                if ($index > 0 && $maxSeconds > 0 && (hrtime(true) - $runStarted) / 1e9 >= $maxSeconds) {
                    $diagnostics?->stop('deadline', hrtime(true));
                    break;
                }
                // Database locks end with their session; stop rather than run unprotected.
                if (! $locks->held()) {
                    throw new RuntimeException('Verification worker locks were lost.');
                }
                $preferReady = $locks->preferReady();
                $diagnostics?->claimCalled();
                $claim = $repository->claim(fn (int $id): bool => $locks->tryCandidate($id), $preferReady);
                if ($claim === null) {
                    $diagnostics?->stop('no_claim', hrtime(true));
                    break;
                }
                $diagnostics?->claimed();
                $directory = $root.'/scratch/'.$claim->id.'-'.$claim->version;
                try {
                    $heartbeat = static fn () => $repository->heartbeat((int) $claim->id, $claim->lease_token);
                    $heartbeat();
                    $progressHeartbeat = new LeaseHeartbeat($heartbeat, max(0.1, min(30, (int) config('release_verification.lease_seconds') / 3)));
                    $scratchInventory->register((int) $claim->id, (int) $claim->version);
                    $scratchEntryId = $scratchInventory->entryId((int) $claim->id, (int) $claim->version);
                    $scratchBudget->reconcile($scratchEntryId, $locks, $progressHeartbeat);
                    $verificationOptions = (array) config('release_verification');
                    $source ??= new NntpArticleSource(app(NNTPService::class), decoder: $decoder,
                        maximumBytes: min((int) $verificationOptions['spot_check_bytes'], (int) $verificationOptions['spot_check_article_bytes']),
                        allowAlternateFallback: false);
                    $verifier = new SpotCheckVerifier($source, $verificationOptions, $decoder);
                    foreach ($scratchInventory->olderVersions((int) $claim->id, (int) $claim->version) as $old) {
                        $oldDirectory = $root.'/scratch/'.$old->candidate_id.'-'.$old->version;
                        $heartbeat();
                        if (is_dir($oldDirectory) || is_link($oldDirectory)) {
                            $this->deleteStaleScratch($oldDirectory, $heartbeat);
                        } elseif (file_exists($oldDirectory)) {
                            throw new RuntimeException('Stale verification scratch is not a directory.');
                        }
                        $scratchInventory->forget((int) $old->id);
                    }
                    $heartbeat();
                    $manifest = $store->read($claim->manifest_path);
                    $started = microtime(true);
                    $result = $verifier->verify($manifest, $progressHeartbeat);
                    $diagnostics?->verified($result->verdict);
                    if ($result->passed()) {
                        $released = config('release_verification.publish_url')
                            ? app(RemoteReleasePublisher::class)->publish($claim, $result) : $publisher->publish($claim, $result);
                        if ($released === null) {
                            $result = $this->filteredResult($claim) ?? throw new RuntimeException('Publication did not produce a verified current release.');
                            $diagnostics?->filtered();
                        } else {
                            $diagnostics?->published();
                        }
                        if (File::deleteDirectory($directory) || (! file_exists($directory) && ! is_link($directory))) {
                            $scratchInventory->forget($scratchEntryId);
                        }
                    } else {
                        $repository->finish($claim, $result);
                        $diagnostics?->finished();
                    }
                    $bytes = $result->passed()
                        ? (int) ($result->manifest['spot_check']['bytes'] ?? $result->manifest['verified_bytes'] ?? 0) : 0;
                    $snapshot = json_encode(['verified_bytes_per_second' => $bytes / max(0.001, microtime(true) - $started),
                        'scratch_bytes' => null, 'scratch_bytes_status' => 'unknown'], JSON_THROW_ON_ERROR);
                    $statusTemporary = $root.'/status-'.bin2hex(random_bytes(8)).'.tmp';
                    try {
                        if (file_put_contents($statusTemporary, $snapshot, LOCK_EX) !== false) {
                            rename($statusTemporary, $root.'/status.json');
                        }
                    } finally {
                        if (is_file($statusTemporary)) {
                            unlink($statusTemporary);
                        }
                    }
                    $this->line($claim->id.': '.$result->verdict.' — '.$result->reason);
                } catch (Throwable $error) {
                    $diagnostics?->candidateFailed();
                    $filtered = $this->filteredResult($claim);
                    if ($filtered !== null) {
                        $diagnostics?->filtered();
                        $this->line($claim->id.': '.$filtered->verdict.' — '.$filtered->reason);
                    } else {
                        $repository->finish($claim, new VerificationResult('unknown', $error->getMessage()));
                        $diagnostics?->finished();
                        $this->warn($claim->id.': retry pending — '.$error->getMessage());
                    }
                } finally {
                    unset($verifier, $manifest, $result, $progressHeartbeat, $heartbeat);
                    $locks->releaseCandidate();
                }
            }
            $diagnostics?->stop('limit', hrtime(true));
            if (! $this->option('no-summary')) {
                $counts = DB::table('release_verification_candidates')->selectRaw('status, COUNT(*) AS count')->groupBy('status')->get();
                foreach ($counts as $count) {
                    $this->line($count->status.': '.$count->count);
                }
            }
        } catch (Throwable $error) {
            $diagnostics?->failed(hrtime(true));

            throw $error;
        } finally {
            unset($source);
            $locks->close();
        }

        return self::SUCCESS;
    }

    /**
     * Recover interrupted terminal cleanup without delaying claims indefinitely.
     *
     * @param  (callable(): bool)|null  $mayStart
     */
    private function cleanupTerminalScratch(string $root, VerificationWorkerLocks $locks, ?callable $mayStart = null): void
    {
        $scratch = $root.'/scratch';
        if (! $this->scratchRootIsSafe($scratch)) {
            throw new RuntimeException('Verification scratch root is inaccessible or linked; inventory retained.');
        }
        if ($mayStart !== null && ! $mayStart()) {
            return;
        }
        $cursorPath = $root.'/indexed-scratch.cursor';
        $cursor = is_file($cursorPath) ? json_decode((string) file_get_contents($cursorPath), true) : null;
        $after = max(0, (int) ($cursor['after'] ?? 0));
        $ceiling = max(0, (int) ($cursor['ceiling'] ?? 0));
        if ($ceiling === 0) {
            $ceiling = (int) DB::table('release_verification_scratch')->where('host', ScratchInventory::host())->max('id');
        }
        // Another host's directories are not here; only their owner can prove them absent.
        $entries = DB::table('release_verification_scratch')->where('host', ScratchInventory::host())
            ->where('id', '>', $after)->where('id', '<=', $ceiling)
            ->orderBy('id')->limit(100)->get();
        if ($entries->isEmpty() && $ceiling === 0) {
            return;
        }
        $remaining = 1000;
        $exhausted = false;
        foreach ($entries as $entry) {
            if ($mayStart !== null && ! $mayStart()) {
                $exhausted = true;
                break;
            }
            if ($entry->candidate_id !== null && ! $locks->tryCandidate((int) $entry->candidate_id)) {
                $after = (int) $entry->id;

                continue;
            }
            try {
                $directory = $scratch.'/'.app(ScratchInventory::class)->directoryName($entry);
                if ($entry->delete_guid !== null) {
                    try {
                        app(AuditReleaseDeletion::class)->cleanup($entry);
                    } catch (Throwable $error) {
                        $this->warn('Terminal audit cleanup will retry: '.$error->getMessage());
                        $after = (int) $entry->id;

                        continue;
                    }
                }
                if (! file_exists($directory) && ! is_link($directory)) {
                    app(ScratchInventory::class)->forget((int) $entry->id);
                } elseif (is_dir($directory) && ! is_link($directory)) {
                    // A reset marks the scratch records it keeps for other hosts terminal.
                    $terminal = $entry->audit_release_id !== null ? (bool) $entry->terminal
                        : (bool) $entry->terminal || DB::table('release_verification_candidates')->where('id', $entry->candidate_id)
                            ->where('version', '>=', $entry->version)
                            ->where(function ($query): void {
                                $query->whereIn('status', ['expired', 'filtered', 'metadata_review', 'metadata_deferred', 'metadata_expired'])
                                    ->orWhere(fn ($published) => $published->whereNotNull('release_id')->whereIn('status', ['complete', 'repaired', 'sampled']));
                            })
                            ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<', now()))
                            ->exists();
                    if ($terminal) {
                        try {
                            $this->deleteStaleScratch($directory, static function () use (&$remaining, &$exhausted, $mayStart): void {
                                if ($remaining-- <= 0 || ($mayStart !== null && ! $mayStart())) {
                                    $exhausted = true;
                                    throw new RuntimeException('Terminal scratch cleanup will resume on the next run.');
                                }
                            });
                            app(ScratchInventory::class)->forget((int) $entry->id);
                        } catch (Throwable $error) {
                            if ($exhausted) {
                                $after = (int) $entry->id;
                                break;
                            }
                            $this->warn('Terminal scratch cleanup will retry: '.$error->getMessage());
                        }
                    }
                }
                $after = (int) $entry->id;
            } finally {
                $locks->releaseCandidate();
            }
        }
        if ((! $exhausted && $entries->count() < 100) || $after >= $ceiling) {
            $after = 0;
            $ceiling = 0;
        }
        $checkpoint = json_encode(['after' => $after, 'ceiling' => $ceiling], JSON_THROW_ON_ERROR);
        if (file_put_contents($cursorPath.'.tmp', $checkpoint, LOCK_EX) === false || ! rename($cursorPath.'.tmp', $cursorPath)) {
            throw new RuntimeException('Cannot checkpoint terminal scratch cleanup.');
        }
    }

    private function scratchRootIsSafe(string $directory): bool
    {
        return ! is_link($directory) && (! file_exists($directory)
            || (is_dir($directory) && is_readable($directory) && is_executable($directory)));
    }

    private function deleteStaleScratch(string $directory, callable $heartbeat): void
    {
        $heartbeat();
        if (is_link($directory)) {
            throw new RuntimeException('Stale verification scratch cannot be a symlink.');
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $heartbeat();
            $removed = $entry->isDir() && ! $entry->isLink()
                ? rmdir($entry->getPathname())
                : File::delete($entry->getPathname());
            if (! $removed) {
                throw new RuntimeException('Cannot remove stale verification scratch.');
            }
        }
        $heartbeat();
        if (! rmdir($directory)) {
            throw new RuntimeException('Cannot remove stale verification scratch.');
        }
    }

    private function filteredResult(object $claim): ?VerificationResult
    {
        $candidate = DB::table('release_verification_candidates')->where('id', $claim->id)->where('version', $claim->version)
            ->where('manifest_hash', $claim->manifest_hash)->whereIn('status', ['filtered', 'metadata_review'])->whereNull('release_id')->first();

        return $candidate === null ? null : new VerificationResult((string) $candidate->status, (string) $candidate->reason);
    }
}
