<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Nzb\NzbService;
use App\Services\Nzb\NzbSourceGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ImportNzbFiles extends Command
{
    protected $signature = 'nzb:import-files {--batch=500 : Releases read per page}
        {--limit=0 : Stop after this many releases (0 = all)} {--since-id=0 : Start after this release ID}
        {--delete-files : Remove each legacy file once the database holds the release\'s NZB}';

    protected $description = 'Copy legacy release NZB files into the database (release_nzbs), one release per transaction';

    public function handle(NzbService $nzb): int
    {
        if (! $nzb->usesDatabaseStorage()) {
            $this->error('NZB_STORAGE is not "database"; nothing to import into.');

            return self::FAILURE;
        }
        $batch = max(1, (int) $this->option('batch'));
        $limit = max(0, (int) $this->option('limit'));
        $deleteFiles = (bool) $this->option('delete-files');
        $cursor = max(0, (int) $this->option('since-id'));
        $guard = new NzbSourceGuard($nzb);
        $counts = ['imported' => 0, 'already' => 0, 'missing' => 0, 'unresolved' => 0, 'conflict' => 0, 'failed' => 0];
        $seen = 0;

        do {
            $rows = DB::table('releases')->leftJoin('release_nzbs', 'release_nzbs.releases_id', '=', 'releases.id')
                ->where('releases.id', '>', $cursor)->orderBy('releases.id')->limit($batch)
                ->get(['releases.id', 'releases.guid', 'release_nzbs.releases_id as stored']);
            if ($limit > 0 && $seen + $rows->count() > $limit) {
                $rows = $rows->take($limit - $seen);
            }
            if ($rows->isEmpty()) {
                break;
            }
            foreach ($rows as $row) {
                $guid = (string) $row->guid;
                try {
                    $outcome = $row->stored !== null ? 'already' : $guard->importLegacyFile($guid);
                    if ($deleteFiles && in_array($outcome, ['imported', 'already'], true)) {
                        // Only once the row is committed: removeLegacyCopies() checks it under the release lock.
                        $guard->removeLegacyCopies($guid);
                    }
                } catch (Throwable $exception) {
                    $outcome = 'failed';
                    Log::warning('Could not import a legacy NZB file.', ['release_id' => $row->id, 'exception' => $exception->getMessage()]);
                }
                $counts[$outcome]++;
            }
            $cursor = (int) $rows->last()->id;
            $seen += $rows->count();
        } while ($rows->count() === $batch && ($limit === 0 || $seen < $limit));

        $this->line(sprintf('imported=%d already=%d missing=%d unresolved=%d conflict=%d failed=%d last_release_id=%d',
            $counts['imported'], $counts['already'], $counts['missing'], $counts['unresolved'], $counts['conflict'], $counts['failed'], $cursor));

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
