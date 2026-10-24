<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Release;
use App\Services\Nzb\DatabaseNzbStore;
use App\Services\Nzb\NzbService;
use App\Services\Nzb\NzbSourceGuard;
use App\Services\ReleaseImageService;
use App\Services\Releases\ReleaseManagementService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

class CleanNZB extends Command
{
    private const FFI_CDEF = <<<'CDEF'
int32_t nntmux_w30_nzb_release_cleanup_commands(
    const char *input,
    size_t len,
    uint8_t *out,
    size_t out_cap
);
CDEF;

    /** Rust stays opt-in: the JSON FFI round trip is slower than these PHP helpers (< 1.5x). */
    private const RUST_FFI_DEFAULT = false;

    private static bool $useRustFfi = self::RUST_FFI_DEFAULT;

    private static FFI|false|null $ffi = null;

    public static function useRustFfi(bool $enabled = true): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = self::RUST_FFI_DEFAULT;
        self::$ffi = null;
    }

    public static function setFfiForTesting(FFI|false|null $ffi = null): void
    {
        self::$ffi = $ffi;
    }

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

    public static function normalizeTempAgeSeconds(mixed $ageHours): int
    {
        return self::$useRustFfi ? self::normalizeTempAgeSecondsNative($ageHours) : self::normalizeTempAgeSecondsPhp($ageHours);
    }

    public static function normalizeTempAgeSecondsPhp(mixed $ageHours): int
    {
        return max(1, (int) $ageHours) * 3600;
    }

    public static function normalizeTempAgeSecondsNative(mixed $ageHours): int
    {
        if (($ageHours === null || is_scalar($ageHours))
            && self::callNative(['clean_nzb.normalize_temp_age_seconds', $ageHours], $result) && is_int($result)) {
            return $result;
        }

        return self::normalizeTempAgeSecondsPhp($ageHours);
    }

    public static function extractGuidFromNzbGzFilename(string $filename): ?string
    {
        return self::$useRustFfi ? self::extractGuidFromNzbGzFilenameNative($filename) : self::extractGuidFromNzbGzFilenamePhp($filename);
    }

    public static function extractGuidFromNzbGzFilenamePhp(string $filename): ?string
    {
        return str_ends_with($filename, '.nzb.gz') ? substr($filename, 0, -7) : null;
    }

    public static function extractGuidFromNzbGzFilenameNative(string $filename): ?string
    {
        if (self::callNative(['clean_nzb.extract_guid_from_nzb_gz_filename', $filename], $result) && ($result === null || is_string($result))) {
            return $result;
        }

        return self::extractGuidFromNzbGzFilenamePhp($filename);
    }

    public static function formatStagedOutcomeLine(bool $delete, int $applied, int $deleted, int $kept): string
    {
        return self::$useRustFfi
            ? self::formatStagedOutcomeLineNative($delete, $applied, $deleted, $kept)
            : self::formatStagedOutcomeLinePhp($delete, $applied, $deleted, $kept);
    }

    public static function formatStagedOutcomeLinePhp(bool $delete, int $applied, int $deleted, int $kept): string
    {
        return sprintf('%s stale temporary NZBs: %d deleted, %d applied, %d kept.', $delete ? 'Cleaned' : 'Checked (dry run)', $deleted, $applied, $kept);
    }

    public static function formatStagedOutcomeLineNative(bool $delete, int $applied, int $deleted, int $kept): string
    {
        if (self::callNative(['clean_nzb.format_staged_outcome_line', $delete, $applied, $deleted, $kept], $result) && is_string($result)) {
            return $result;
        }

        return self::formatStagedOutcomeLinePhp($delete, $applied, $deleted, $kept);
    }

    public static function formatTempCleanupLine(bool $delete, int $staleTemp): string
    {
        return self::$useRustFfi ? self::formatTempCleanupLineNative($delete, $staleTemp) : self::formatTempCleanupLinePhp($delete, $staleTemp);
    }

    public static function formatTempCleanupLinePhp(bool $delete, int $staleTemp): string
    {
        return $delete
            ? "Cleaning {$staleTemp} stale temporary NZB paths."
            : "Found {$staleTemp} stale temporary NZB paths (dry run).";
    }

    public static function formatTempCleanupLineNative(bool $delete, int $staleTemp): string
    {
        if (self::callNative(['clean_nzb.format_temp_cleanup_line', $delete, $staleTemp], $result) && is_string($result)) {
            return $result;
        }

        return self::formatTempCleanupLinePhp($delete, $staleTemp);
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (DatabaseNzbStore::enabled() && $this->option('notondisk')) {
            $this->error('Filesystem absence cannot qualify database releases for deletion; unimported sources must be retained.');

            return self::FAILURE;
        }
        // Check if any options are false
        if (! $this->option('notindb') && ! $this->option('notondisk') && ! $this->option('temps')) {
            $this->error('You must specify at least one option. See: --help');

            return self::FAILURE;
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

        return self::SUCCESS;
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
            if (NzbService::isStagedNzbPathName((string) $filePath)) {
                $this->reconcileStaleStagedNzb((string) $filePath, $delete, $checked, $deleted);

                continue;
            }
            $guid = self::extractGuidFromNzbGzFilename($filePath->getFilename());
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

    private function reconcileStaleStagedNzb(string $path, mixed $delete, int &$checked, int &$deleted): void
    {
        $nzb = app(NzbService::class);
        $olderThanSeconds = self::normalizeTempAgeSeconds($this->option('temp-age-hours'));
        $checked++;
        $outcome = $nzb->reconcileStaleStagedNzb($path, $olderThanSeconds, apply: (bool) $delete);
        if ($outcome === 'deleted') {
            $deleted++;
        }
        if ($outcome !== 'kept' || $nzb->isStaleStagedNzb($path, $olderThanSeconds)) {
            $this->line($this->stagedOutcomeLine($outcome, (bool) $delete, $path));
        }
    }

    private function stagedOutcomeLine(string $outcome, bool $delete, string $path): string
    {
        return match ($outcome) {
            'applied' => ($delete ? 'Applied' : 'Would apply').' staged NZB generation: '.$path,
            'deleted' => ($delete ? 'Deleted' : 'Would delete').' staged NZB generation: '.$path,
            default => 'Kept staged NZB generation for review: '.$path,
        };
    }

    private function hasNzbOnDisk(NzbService $nzb, string $guid, bool $delete): bool
    {
        if (DatabaseNzbStore::enabled() ? $nzb->hasNzb($guid) : $nzb->nzbPath($guid) !== false) {
            return true;
        }
        // A committed upgrade's NZB may still be staged beside the missing canonical file.
        $guard = app(NzbSourceGuard::class);
        if ($delete) {
            if ($guard->settleStagedGenerations($guid) && $nzb->nzbPath($guid) !== false) {
                return true;
            }
            // A staged generation that could not be put in place may be the only copy of the committed source.
            $kept = $guard->stagedGenerations($guid);
            foreach ($kept as $path) {
                $this->line($this->stagedOutcomeLine('kept', true, $path));
            }

            return $kept !== [];
        }
        // A dry run changes no file: it reports what --delete would do, which keeps any release with a staged generation.
        $staged = $guard->stagedGenerations($guid);
        foreach ($staged as $path) {
            $outcome = $guard->reconcileStaged($path, apply: false);
            $this->line($this->stagedOutcomeLine($outcome === 'applied' ? 'applied' : 'kept', false, $path));
        }

        return $staged !== [];
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

                if (! $this->hasNzbOnDisk($nzb, (string) $r->guid, (bool) $delete)) {
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
        $olderThanSeconds = self::normalizeTempAgeSeconds($ageHours);
        $outcomes = ['deleted' => 0, 'applied' => 0, 'kept' => 0];
        $paths = $nzb->findStaleTemporaryNzbPaths($olderThanSeconds);
        $this->line(self::formatTempCleanupLine((bool) $delete, count($paths)));

        foreach ($paths as $path) {
            if (NzbService::isStagedNzbPathName($path)) {
                // A staged generation is settled by its upgrade's outcome, never just deleted.
                $outcome = $nzb->reconcileStaleStagedNzb($path, $olderThanSeconds, apply: (bool) $delete);
                $outcomes[$outcome]++;
                $this->line($this->stagedOutcomeLine($outcome, (bool) $delete, $path));

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

        if ($delete && $outcomes['deleted'] + $outcomes['applied'] > 0) {
            Log::channel('nzb_creation')->warning('Cleaned stale temporary NZB files', $outcomes + ['older_than_hours' => $ageHours]);
        }
        $this->info(self::formatStagedOutcomeLine((bool) $delete, $outcomes['applied'], $outcomes['deleted'], $outcomes['kept']));
    }

    /**
     * Evaluates `[operation, ...arguments]` in Rust; false means the caller keeps its PHP result.
     *
     * @param  list<mixed>  $request
     *
     * @param-out mixed $result
     */
    private static function callNative(array $request, mixed &$result = null): bool
    {
        $ffi = self::nativeFfi();
        if (! $ffi instanceof FFI) {
            return false;
        }

        try {
            $json = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            $capacity = max(256, strlen($json) * 2 + 256);
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $buffer = $ffi->new("uint8_t[{$capacity}]");
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w30_nzb_release_cleanup_commands($json, strlen($json), $buffer, $capacity);
                if (! is_int($written) || $written === -1 || $written === 0) {
                    return false;
                }
                if ($written < 0) {
                    $capacity = -$written;

                    continue;
                }
                $decoded = json_decode(FFI::string($buffer, $written), true, 8, JSON_THROW_ON_ERROR);
                if (! is_array($decoded) || count($decoded) !== 1 || ! array_key_exists(0, $decoded)) {
                    return false;
                }
                $result = $decoded[0];

                return true;
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    private static function nativeFfi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }
        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }
        $ffi = RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
