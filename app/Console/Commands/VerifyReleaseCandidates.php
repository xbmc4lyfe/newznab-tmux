<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\NNTP\NNTPService;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\ManifestStore;
use App\Services\Releases\Verification\NntpArticleSource;
use App\Services\Releases\Verification\PayloadVerifier;
use App\Services\Releases\Verification\VerificationResult;
use App\Services\Releases\Verification\VerifiedReleasePublisher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

class VerifyReleaseCandidates extends Command
{
    protected $signature = 'releases:verify-candidates {--limit=1 : Maximum candidates in this run}';

    protected $description = 'Download and verify pending candidates before publishing their NZBs';

    public function handle(CandidateRepository $repository, ManifestStore $store, VerifiedReleasePublisher $publisher): int
    {
        $root = (string) config('release_verification.storage_path');
        File::ensureDirectoryExists($root, 0700);
        $lock = fopen($root.'/worker.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            $this->info('Verification worker is already running.');

            return self::SUCCESS;
        }
        try {
            $repository->expire();
            $verifier = new PayloadVerifier(new NntpArticleSource(app(NNTPService::class)), (array) config('release_verification'));
            for ($index = 0; $index < max(1, (int) $this->option('limit')); $index++) {
                $claim = $repository->claim();
                if ($claim === null) {
                    break;
                }
                $directory = $root.'/scratch/'.$claim->id.'-'.$claim->version;
                try {
                    $heartbeat = static fn () => $repository->heartbeat((int) $claim->id, $claim->lease_token);
                    $heartbeat();
                    foreach (glob($root.'/scratch/'.$claim->id.'-*', GLOB_ONLYDIR) ?: [] as $oldDirectory) {
                        if (preg_match('/\A'.$claim->id.'-([0-9]+)\z/', basename($oldDirectory), $match) === 1 && (int) $match[1] < (int) $claim->version) {
                            $this->deleteStaleScratch($oldDirectory, $heartbeat);
                        }
                    }
                    $heartbeat();
                    $manifest = $store->read($claim->manifest_path);
                    $started = microtime(true);
                    $result = $verifier->verify($manifest, $directory, $heartbeat);
                    if ($result->passed()) {
                        if ($publisher->publish($claim, $result) === null) {
                            $result = $this->filteredResult($claim) ?? throw new RuntimeException('Publication did not produce a verified current release.');
                        }
                        File::deleteDirectory($directory);
                    } else {
                        $repository->finish($claim, $result);
                    }
                    $bytes = $result->passed() ? (int) ($result->manifest['verified_bytes'] ?? 0) : 0;
                    $scratchBytes = 0;
                    if (is_dir($root.'/scratch')) {
                        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/scratch', \FilesystemIterator::SKIP_DOTS));
                        foreach ($iterator as $file) {
                            if ($file->isFile()) {
                                $scratchBytes += $file->getSize();
                            }
                        }
                    }
                    $snapshot = json_encode(['verified_bytes_per_second' => $bytes / max(0.001, microtime(true) - $started),
                        'scratch_bytes' => $scratchBytes], JSON_THROW_ON_ERROR);
                    if (file_put_contents($root.'/status.json.tmp', $snapshot, LOCK_EX) !== false) {
                        rename($root.'/status.json.tmp', $root.'/status.json');
                    }
                    $this->line($claim->id.': '.$result->verdict.' — '.$result->reason);
                } catch (Throwable $error) {
                    $filtered = $this->filteredResult($claim);
                    if ($filtered !== null) {
                        $this->line($claim->id.': filtered — '.$filtered->reason);
                    } else {
                        $repository->finish($claim, new VerificationResult('unknown', $error->getMessage()));
                        $this->warn($claim->id.': retry pending — '.$error->getMessage());
                    }
                }
            }
            $counts = DB::table('release_verification_candidates')->selectRaw('status, COUNT(*) AS count')->groupBy('status')->get();
            foreach ($counts as $count) {
                $this->line($count->status.': '.$count->count);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return self::SUCCESS;
    }

    private function deleteStaleScratch(string $directory, callable $heartbeat): void
    {
        $heartbeat();
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
            ->where('manifest_hash', $claim->manifest_hash)->where('status', 'filtered')->whereNull('release_id')->first();

        return $candidate === null ? null : new VerificationResult('filtered', (string) $candidate->reason);
    }
}
