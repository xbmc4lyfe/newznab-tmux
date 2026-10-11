<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Release;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseImageService;
use App\Services\Releases\ReleaseManagementService;
use App\Services\Releases\Verification\NntpArticleSource;
use App\Services\Releases\Verification\NzbManifestReader;
use App\Services\Releases\Verification\PayloadVerifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

class VerifyExistingReleases extends Command
{
    protected $signature = 'releases:verify-repairable {--dry-run : Report without deleting}
        {--delete : Delete only proven unrepairable releases} {--limit=100 : Maximum releases}
        {--since-id=0 : Start after this release ID} {--resume : Use persisted audit cursor}';

    protected $description = 'Download and audit existing NZBs with resumable progress; unknown results are retained';

    public function handle(NzbService $nzb, NzbManifestReader $reader, ReleaseManagementService $management, ReleaseImageService $images): int
    {
        $root = (string) config('release_verification.storage_path');
        File::ensureDirectoryExists($root, 0700);
        $lock = fopen($root.'/worker.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            $this->warn('The new-release verifier has priority; retry the audit later.');

            return self::SUCCESS;
        }
        try {
            if (DB::table('release_verification_candidates')->whereNull('release_id')->whereIn('status', ['pending', 'unknown'])
                ->where('next_attempt_at', '<=', now())->exists()) {
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
            $verifier = new PayloadVerifier(new NntpArticleSource(app(NNTPService::class)), (array) config('release_verification'));
            $attempted = 0;
            while ($attempted < $limit && ($freshIds !== [] || $retryIds !== [])) {
                $isRetry = $retryIds !== [] && ($retryTurn || $freshIds === []);
                $id = (int) ($isRetry ? array_shift($retryIds) : array_shift($freshIds));
                $release = Release::query()->find($id);
                if ($release === null) {
                    DB::table('release_verification_audit_retries')->where('release_id', $id)->delete();

                    continue;
                }
                $attempted++;
                $directory = $root.'/scratch/audit-'.$release->id;
                try {
                    $xml = $nzb->readNzbContents($release->guid);
                    if ($xml === false) {
                        throw new \RuntimeException('Release NZB is unavailable.');
                    }
                    $result = $verifier->verify($reader->read($xml, $release), $directory, static function (): void {});
                    $record = ['id' => $release->id, 'verdict' => $result->verdict, 'reason' => $result->reason];
                    if (! $this->option('dry-run') && $this->option('delete') && $result->verdict === 'unrepairable') {
                        $management->deleteSingle(['g' => $release->guid, 'i' => $release->id], $nzb, $images);
                        $record['deleted'] = true;
                    }
                } catch (Throwable $error) {
                    $record = ['id' => $release->id, 'verdict' => 'unknown', 'reason' => $error->getMessage()];
                }
                $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
                if (file_put_contents($root.'/audit.jsonl', $line."\n", FILE_APPEND | LOCK_EX) === false) {
                    throw new \RuntimeException('Cannot persist audit report.');
                }
                DB::transaction(function () use ($id, $isRetry, $record): void {
                    $current = DB::table('release_verification_state')->where('id', 1)->lockForUpdate()->first();
                    if ($current === null) {
                        throw new \RuntimeException('Audit state is unavailable.');
                    }
                    if ($record['verdict'] === 'unknown') {
                        DB::table('release_verification_audit_retries')->updateOrInsert(['release_id' => $id], [
                            'next_attempt_at' => now()->addSeconds(max(1, (int) config('release_verification.retry_seconds'))),
                            'reason' => mb_strcut($record['reason'], 0, 2000, 'UTF-8'),
                        ]);
                    } else {
                        DB::table('release_verification_audit_retries')->where('release_id', $id)->delete();
                    }
                    DB::table('release_verification_state')->where('id', 1)->update([
                        'audit_cursor' => max((int) $current->audit_cursor, $id), 'audit_retry_turn' => ! $isRetry,
                    ]);
                }, 5);
                $retryTurn = ! $isRetry;
                $this->line($line);
                File::deleteDirectory($directory);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return self::SUCCESS;
    }
}
