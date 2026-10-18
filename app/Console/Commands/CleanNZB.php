<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Release;
use App\Services\Nzb\NzbService;
use App\Services\Nzb\NzbSourceGuard;
use App\Services\ReleaseImageService;
use App\Services\Releases\ReleaseManagementService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class CleanNZB extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nntmux:nzbclean
    {--notindb : Delete NZBs that dont exist in database}
    {--notondisk : Delete release in database that dont have a NZB on disk}
    {--temps : Delete stale temporary NZB files left by interrupted creation}
    {--temp-age-hours=24 : Minimum temporary NZB age in hours before deletion}
    {--chunksize=25000 : Chunk size for releases query}
    {--delete : Pass this argument to actually delete the files. Otherwise it\'s just a dry run.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Find NZBs that dont have a release, or releases that have no NZBs.';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        // Check if any options are false
        if (! $this->option('notindb') && ! $this->option('notondisk') && ! $this->option('temps')) {
            $this->error('You must specify at least one option. See: --help');
            exit();
        }
        if ($this->option('notindb')) {
            $this->GetNZBsWithNoDatabaseEntry($this->option('delete'));
        }
        if ($this->option('notondisk')) {
            $this->GetReleasesWithNoNZBOnDisk($this->option('delete'));
        }
        if ($this->option('temps')) {
            $this->cleanTemporaryNzbFiles($this->option('delete'));
        }
    }

    private function GetNZBsWithNoDatabaseEntry(mixed $delete = false): void
    {
        $this->info('Getting list of NZB files on disk to check if they exist in database');
        $releases = new Release;
        $checked = $deleted = 0;
        // Get the list of NZBs in the NZB folder
        $dirItr = new \RecursiveDirectoryIterator(config('nntmux_settings.path_to_nzbs'));
        $itr = new \RecursiveIteratorIterator($dirItr, \RecursiveIteratorIterator::LEAVES_ONLY);

        // Checking filename GUIDs against the releases table
        foreach ($itr as $filePath) {
            // Side files belong to --temps, which ages them out whether or not a release exists.
            if (NzbService::isTemporaryNzbPathName((string) $filePath)) {
                continue;
            }
            if (NzbService::isPreservedNzbPathName((string) $filePath)) {
                $this->removeStalePreservedNzb((string) $filePath, $delete, $checked, $deleted);

                continue;
            }
            $guid = stristr($filePath->getFilename(), '.nzb.gz', true);
            if (File::isFile($filePath) && $guid) {
                // If NZB file guid is not present in DB delete the file from disk
                if (! $releases->whereGuid($guid)->exists()) {
                    if ($delete) {
                        if (! app(NzbSourceGuard::class)->deleteOrphan($guid, (string) $filePath)) {
                            $this->warn("Retained orphan file pending publication ownership verification: $guid.nzb.gz");
                            $checked++;

                            continue;
                        }
                    }
                    $deleted++;
                    $this->line("Deleted orphan file: $guid.nzb.gz");
                }
                $checked++;
            }
            echo "Checked: $checked / Deleted: $deleted\r";
        }
        $this->info("Checked: $checked / Deleted: $deleted");
    }

    private function removeStalePreservedNzb(string $path, mixed $delete, int &$checked, int &$deleted): void
    {
        $nzb = app(NzbService::class);
        $olderThanSeconds = max(1, (int) $this->option('temp-age-hours')) * 3600;
        $checked++;
        $outcome = $nzb->reconcileStalePreservedNzb($path, $olderThanSeconds, apply: (bool) $delete);
        if ($outcome === 'deleted') {
            $deleted++;
            $this->line(($delete ? 'Deleted' : 'Would delete').' replaced NZB source: '.$path);
        } elseif ($outcome === 'restored') {
            $this->line(($delete ? 'Restored' : 'Would restore').' replaced NZB source: '.$path);
        } elseif ($nzb->isStalePreservedNzb($path, $olderThanSeconds)) {
            $this->line('Kept replaced NZB source for review: '.$path);
        }
    }

    private function GetReleasesWithNoNZBOnDisk(mixed $delete = false): void
    {
        // Setup
        $nzb = app(NzbService::class);
        $releaseManagement = app(ReleaseManagementService::class);
        $checked = $deleted = 0;

        $this->info('Getting list of releases from database to check if they have a corresponding NZB on disk');
        $total = Release::count();
        $this->alert("Total releases to check: $total");

        Release::where('nzbstatus', 1)->chunkById((int) $this->option('chunksize'), function (Collection $releases) use ($delete, &$checked, &$deleted, $nzb, $releaseManagement) {
            echo 'Total done: '.$checked."\r";
            foreach ($releases as $r) {

                if (! $nzb->nzbPath($r->guid)) {
                    if ($delete) {
                        $releaseManagement->deleteSingleWithService(['g' => $r->guid, 'i' => $r->id], $nzb, new ReleaseImageService);
                    }
                    $deleted++;
                    $this->line("Deleted: $r->searchname -> $r->guid");
                }
                $checked++;
            }
        });
        $this->info("Checked: $checked / Deleted: $deleted");
    }

    private function cleanTemporaryNzbFiles(mixed $delete = false): void
    {
        $nzb = app(NzbService::class);
        $ageHours = max(1, (int) $this->option('temp-age-hours'));
        $olderThanSeconds = $ageHours * 3600;
        $outcomes = ['deleted' => 0, 'restored' => 0, 'kept' => 0];

        foreach ($nzb->findStaleTemporaryNzbPaths($olderThanSeconds) as $path) {
            if (NzbService::isPreservedNzbPathName($path)) {
                // A replaced source is settled by its upgrade's outcome, never just deleted.
                $outcome = $nzb->reconcileStalePreservedNzb($path, $olderThanSeconds, apply: (bool) $delete);
                $outcomes[$outcome]++;
                $this->line(match ($outcome) {
                    'deleted' => ($delete ? 'Deleted' : 'Would delete').' replaced NZB source: '.$path,
                    'restored' => ($delete ? 'Restored' : 'Would restore').' replaced NZB source: '.$path,
                    default => 'Kept replaced NZB source for review: '.$path,
                });

                continue;
            }
            if (! $delete) {
                $outcomes['deleted']++;
                $this->line("Would delete stale temporary NZB: {$path}");

                continue;
            }
            if ($nzb->deleteStaleTemporaryNzb($path)) {
                $outcomes['deleted']++;
            } else {
                $outcomes['kept']++;
                $this->warn("Could not delete stale temporary NZB: {$path}");
            }
        }

        if ($delete && $outcomes['deleted'] + $outcomes['restored'] > 0) {
            Log::channel('nzb_creation')->warning('Cleaned stale temporary NZB files', $outcomes + ['older_than_hours' => $ageHours]);
        }
        $this->info(sprintf('%s stale temporary NZBs: %d deleted, %d restored, %d kept.', $delete ? 'Cleaned' : 'Checked (dry run)',
            $outcomes['deleted'], $outcomes['restored'], $outcomes['kept']));
    }
}
