<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Release;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbService;
use App\Services\Releases\Verification\AuditReleaseDeletion;
use App\Services\Releases\Verification\CandidateHeaderInventory;
use App\Services\Releases\Verification\NntpArticleSource;
use App\Services\Releases\Verification\NzbManifestReader;
use App\Services\Releases\Verification\ScratchBudget;
use App\Services\Releases\Verification\ScratchInventory;
use App\Services\Releases\Verification\SpotCheckVerifier;
use App\Services\Releases\Verification\VerificationLedger;
use App\Services\YencService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

class VerifyExistingReleases extends Command
{
    protected $signature = 'releases:verify-repairable {--dry-run : Report without deleting}
        {--delete : Delete only proven unrepairable releases} {--limit=100 : Maximum releases}
        {--since-id=0 : Start after this release ID} {--resume : Use persisted audit cursor}';

    protected $description = 'Spot check existing NZBs with bounded article reads; inconclusive results are retained';

    public function handle(NzbService $nzb, NzbManifestReader $reader, ScratchInventory $scratchInventory,
        AuditReleaseDeletion $deletion, ScratchBudget $scratchBudget, YencService $decoder): int
    {
        $root = (string) config('release_verification.storage_path');
        File::ensureDirectoryExists($root, 0700);
        $scratch = $root.'/scratch';
        if (is_link($scratch) || (file_exists($scratch) && (! is_dir($scratch) || ! is_readable($scratch) || ! is_executable($scratch)))) {
            throw new \RuntimeException('Audit scratch root is inaccessible or linked.');
        }
        if (! $scratchBudget->initialize()) {
            $this->warn('Scratch budget initialization is waiting for active verification workers.');

            return self::SUCCESS;
        }
        $lock = fopen($root.'/worker.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            $this->warn('The new-release verifier has priority; retry the audit later.');

            return self::SUCCESS;
        }
        try {
            $scratch = $root.'/scratch';
            if (is_link($scratch) || (file_exists($scratch) && (! is_dir($scratch) || ! is_readable($scratch) || ! is_executable($scratch)))) {
                throw new \RuntimeException('Audit scratch root is inaccessible or linked.');
            }
            if ($this->newCandidatesAreDue()) {
                $this->warn('New candidates are due; audit deferred.');

                return self::SUCCESS;
            }
            $state = DB::table('release_verification_state')->where('id', 1)->first();
            if ($state === null) {
                throw new \RuntimeException('Audit state is unavailable.');
            }
            $resume = (bool) $this->option('resume');
            $cursor = $resume ? (int) $state->audit_cursor : (int) $this->option('since-id');
            $limit = max(1, (int) $this->option('limit'));
            $freshIds = Release::query()->where('id', '>', $cursor)->orderBy('id')->limit($limit)->pluck('id')->all();
            $retryIds = $resume ? DB::table('release_verification_audit_retries')->where('next_attempt_at', '<=', now())
                ->orderBy('next_attempt_at')->orderBy('release_id')->limit($limit)->pluck('release_id')->all() : [];
            $retryTurn = (bool) $state->audit_retry_turn;
            $verificationOptions = (array) config('release_verification');
            $source = new NntpArticleSource(app(NNTPService::class), decoder: $decoder,
                maximumBytes: min((int) $verificationOptions['spot_check_bytes'], (int) $verificationOptions['spot_check_article_bytes']),
                allowAlternateFallback: false);
            $attempted = 0;
            while ($attempted < $limit && ($freshIds !== [] || $retryIds !== [])) {
                if ($this->newCandidatesAreDue()) {
                    $this->warn('New candidates are due; audit deferred.');

                    break;
                }
                $isRetry = $retryIds !== [] && ($retryTurn || $freshIds === []);
                $id = (int) ($isRetry ? array_shift($retryIds) : array_shift($freshIds));
                $release = Release::query()->find($id);
                if ($release === null) {
                    DB::transaction(static function () use ($id): void {
                        DB::table('release_verification_audit_retries')->where('release_id', $id)->delete();
                        DB::table('release_verification_scratch')->where('audit_release_id', $id)->update(['terminal' => true]);
                    });

                    continue;
                }
                $attempted++;
                $scratchEntry = $scratchInventory->registerAudit((int) $release->id);
                $directory = $root.'/scratch/'.$scratchInventory->directoryName($scratchEntry);
                File::ensureDirectoryExists($directory, 0700);
                $verifier = new SpotCheckVerifier($source, $verificationOptions, $decoder);
                try {
                    $xml = $nzb->readNzbContents($release->guid);
                    if ($xml === false) {
                        throw new \RuntimeException('Release NZB is unavailable.');
                    }
                    // A partial publication's NZB is knowingly incomplete and can never grow; audit what it has,
                    // but only while it still holds every article and file recorded at publication.
                    $manifest = $reader->read($xml, $release);
                    $partial = $release->getAttribute('articles_expected') !== null;
                    $found = $partial ? (new CandidateHeaderInventory)->completeness($manifest) : null;
                    if ($found !== null && ($found['articles_found'] !== (int) $release->getAttribute('articles_found')
                        || $found['files_found'] !== (int) $release->getAttribute('files_found'))) {
                        $record = ['id' => $release->id, 'verdict' => 'unrepairable',
                            'reason' => sprintf('Partial release NZB holds %d of %d recorded articles and %d of %d recorded files.',
                                $found['articles_found'], (int) $release->getAttribute('articles_found'),
                                $found['files_found'], (int) $release->getAttribute('files_found'))];
                    } else {
                        $result = $verifier->verify($manifest, static function (): void {}, allowPartial: $partial);
                        $record = ['id' => $release->id, 'verdict' => $result->verdict, 'reason' => $result->reason];
                    }
                } catch (Throwable $error) {
                    $record = ['id' => $release->id, 'verdict' => 'unknown', 'reason' => $error->getMessage()];
                }
                $scratchInventory->recordAudit((int) $scratchEntry->id, $record['verdict'], $record['reason']);
                $delete = ! $this->option('dry-run') && $this->option('delete') && $record['verdict'] === 'unrepairable';
                DB::transaction(function () use ($id, $isRetry, $record, $scratchEntry, $scratchInventory, $delete, $release, $deletion): void {
                    // Deletions serialize with publications that may choose this release as a
                    // duplicate (they share a search key); the shared ledger row is locked last.
                    app(VerificationLedger::class)->enterPublication(VerificationLedger::publicationKeys((int) $release->size,
                        $release->getAttribute('article_fingerprint'), (string) $release->name, (string) $release->searchname,
                        (int) $release->predb_id), 'Audit state is unavailable.');
                    if ($record['verdict'] === 'unknown') {
                        DB::table('release_verification_audit_retries')->updateOrInsert(['release_id' => $id], [
                            'next_attempt_at' => now()->addSeconds(max(1, (int) config('release_verification.retry_seconds'))),
                            'reason' => mb_strcut($record['reason'], 0, 2000, 'UTF-8'),
                        ]);
                    } else {
                        if ($delete) {
                            $deletion->commit($release, (int) $scratchEntry->id);
                        }
                        DB::table('release_verification_audit_retries')->where('release_id', $id)->delete();
                        $scratchInventory->finishAudit((int) $scratchEntry->id);
                    }
                    $current = DB::table('release_verification_state')->where('id', 1)->lockForUpdate()->first();
                    if ($current === null) {
                        throw new \RuntimeException('Audit state is unavailable.');
                    }
                    DB::table('release_verification_state')->where('id', 1)->update([
                        'audit_cursor' => max((int) $current->audit_cursor, $id), 'audit_retry_turn' => ! $isRetry,
                    ]);
                }, 5);
                if ($delete) {
                    $record['deleted'] = true;
                }
                $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
                if (file_put_contents($root.'/audit.jsonl', $line."\n", FILE_APPEND | LOCK_EX) === false) {
                    throw new \RuntimeException('Cannot persist audit report.');
                }
                $retryTurn = ! $isRetry;
                $this->line($line);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return self::SUCCESS;
    }

    private function newCandidatesAreDue(): bool
    {
        return DB::table('release_verification_candidates')->whereNull('release_id')->whereIn('status', ['ready', 'pending', 'unknown', 'verifying'])
            ->where('next_attempt_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<', now()))->exists();
    }
}
