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
            $this->cleanupTerminalScratch($root);
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

    /** Recover interrupted terminal cleanup without delaying claims indefinitely. */
    private function cleanupTerminalScratch(string $root): void
    {
        $scratch = $root.'/scratch';
        if (! is_dir($scratch) || is_link($scratch)) {
            return;
        }
        $cursorPath = $root.'/terminal-scratch.cursor';
        $cursor = is_file($cursorPath) ? json_decode((string) file_get_contents($cursorPath), true) : null;
        $offset = max(0, (int) ($cursor['offset'] ?? 0));
        $ceiling = max(0, (int) ($cursor['ceiling'] ?? 0));
        $handle = opendir($scratch);
        if ($handle === false) {
            throw new RuntimeException('Cannot scan verification scratch.');
        }
        $entries = [];
        $atEnd = false;
        try {
            if ($ceiling === 0) {
                while (($name = readdir($handle)) !== false) {
                    if ($name !== '.' && $name !== '..') {
                        $ceiling++;
                    }
                }
                rewinddir($handle);
                $offset = 0;
            }
            $skipped = 0;
            while ($skipped < $offset && ($name = readdir($handle)) !== false) {
                if ($name !== '.' && $name !== '..') {
                    $skipped++;
                }
            }
            while (count($entries) < min(100, max(0, $ceiling - $offset))) {
                $name = readdir($handle);
                if ($name === false) {
                    if ($entries === [] && $offset > 0) {
                        rewinddir($handle);
                        $offset = 0;

                        continue;
                    }
                    $atEnd = true;
                    break;
                }
                if ($name !== '.' && $name !== '..') {
                    $entries[] = $name;
                }
            }
        } finally {
            closedir($handle);
        }
        $remaining = 1000;
        $exhausted = false;
        $processed = 0;
        $removed = 0;
        foreach ($entries as $name) {
            $directory = $scratch.'/'.$name;
            if (preg_match('/\A([1-9][0-9]*)-([1-9][0-9]*)\z/', $name, $match) === 1 && is_dir($directory) && ! is_link($directory)) {
                $candidate = DB::table('release_verification_candidates')->where('id', $match[1])
                    ->where('version', '>=', $match[2])
                    ->where(function ($query): void {
                        $query->whereIn('status', ['expired', 'filtered'])
                            ->orWhere(fn ($published) => $published->whereNotNull('release_id')->whereIn('status', ['complete', 'repaired']));
                    })
                    ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<', now()))
                    ->first(['id', 'version']);
                if ($candidate !== null) {
                    try {
                        $this->deleteStaleScratch($directory, static function () use (&$remaining, &$exhausted): void {
                            if ($remaining-- <= 0) {
                                $exhausted = true;
                                throw new RuntimeException('Terminal scratch cleanup will resume on the next run.');
                            }
                        });
                        $removed++;
                    } catch (Throwable $error) {
                        if ($exhausted) {
                            break;
                        }
                        $this->warn('Terminal scratch cleanup will retry: '.$error->getMessage());
                    }
                }
            }
            $processed++;
        }
        // Directory positions move when entries disappear; a full pass always wraps.
        $offset = max(0, $offset + $processed - $removed);
        $ceiling = max(0, $ceiling - $removed);
        if (! $exhausted && ($atEnd || $offset >= $ceiling)) {
            $offset = 0;
            $ceiling = 0;
        }
        $checkpoint = json_encode(['offset' => $offset, 'ceiling' => $ceiling], JSON_THROW_ON_ERROR);
        if (file_put_contents($cursorPath.'.tmp', $checkpoint, LOCK_EX) === false || ! rename($cursorPath.'.tmp', $cursorPath)) {
            throw new RuntimeException('Cannot checkpoint terminal scratch cleanup.');
        }
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
