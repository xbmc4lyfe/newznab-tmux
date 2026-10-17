<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Releases\Verification\ManifestStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportVerificationManifests extends Command
{
    protected $signature = 'verification:import-manifests {--batch=1000 : Candidates read per page}
        {--limit=0 : Stop after this many manifests (0 = all)} {--since-id=0 : Start after this candidate ID}
        {--delete-files : Remove each legacy file once the database holds a verified copy}';

    protected $description = 'Copy legacy verification manifest files referenced by candidates into the database';

    public function handle(ManifestStore $store): int
    {
        if (config('release_verification.manifest_storage') !== 'database') {
            $this->error('RELEASE_VERIFICATION_MANIFEST_STORAGE is not "database"; nothing to import into.');

            return self::FAILURE;
        }
        $batch = max(1, (int) $this->option('batch'));
        $limit = max(0, (int) $this->option('limit'));
        $deleteFiles = (bool) $this->option('delete-files');
        $cursor = max(0, (int) $this->option('since-id'));
        $counts = ['imported' => 0, 'already' => 0, 'missing' => 0, 'invalid' => 0, 'conflict' => 0, 'unreferenced' => 0];
        $seen = 0;

        do {
            $rows = DB::table('release_verification_candidates')->where('id', '>', $cursor)->where('manifest_path', '<>', '')
                ->orderBy('id')->limit($batch)->get(['id', 'manifest_path']);
            if ($limit > 0 && $seen + $rows->count() > $limit) {
                $rows = $rows->take($limit - $seen);
            }
            if ($rows->isEmpty()) {
                break;
            }
            $cursor = (int) $rows->last()->id;
            $seen += $rows->count();
            foreach ($store->importLegacyFiles($rows->pluck('manifest_path')->map(static fn ($name): string => (string) $name)->all(), $deleteFiles) as $outcome => $count) {
                $counts[$outcome] += $count;
            }
        } while ($rows->count() === $batch && ($limit === 0 || $seen < $limit));

        $this->line(sprintf('imported=%d already=%d missing=%d invalid=%d conflict=%d unreferenced=%d last_candidate_id=%d',
            $counts['imported'], $counts['already'], $counts['missing'], $counts['invalid'], $counts['conflict'], $counts['unreferenced'], $cursor));

        return self::SUCCESS;
    }
}
