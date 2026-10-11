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
            $cursor = $this->option('resume') ? (int) DB::table('release_verification_state')->where('id', 1)->value('audit_cursor') : (int) $this->option('since-id');
            $verifier = new PayloadVerifier(new NntpArticleSource(app(NNTPService::class)), (array) config('release_verification'));
            foreach (Release::query()->where('id', '>', $cursor)->orderBy('id')->limit(max(1, (int) $this->option('limit')))->cursor() as $release) {
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
                DB::table('release_verification_state')->where('id', 1)->update(['audit_cursor' => $release->id]);
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
