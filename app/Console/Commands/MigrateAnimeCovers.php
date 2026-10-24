<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

class MigrateAnimeCovers extends Command
{
    private const FFI_CDEF = <<<'CDEF'
    ssize_t nntmux_migrate_anime_covers_resolve_filename(
        const char *filename_ptr,
        size_t filename_len,
        int64_t *out_anidbid,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_migrate_anime_covers_format_summary(
        uint32_t dry_run,
        int64_t renamed,
        int64_t skipped,
        size_t *out_lens,
        char *out_buf,
        size_t out_cap
    );
    CDEF;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustMovieAnimeTvReprocessCommandsParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'anime:migrate-covers {--dry-run : Show what would be renamed without actually renaming}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate anime cover files from old format ({id}.jpg) to new format ({id}-cover.jpg)';

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = (bool) $useRustFfi;
    }

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = false;
        self::$ffi = null;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $animeCoverPath = storage_path('covers/anime/');
        $dryRun = $this->option('dry-run');

        if (! is_dir($animeCoverPath)) {
            $this->error("Anime covers directory does not exist: {$animeCoverPath}");

            return 1;
        }

        $this->info("Scanning anime covers directory: {$animeCoverPath}");

        $files = File::files($animeCoverPath);
        $renamed = 0;
        $skipped = 0;

        foreach ($files as $file) {
            $filename = $file->getFilename();

            // Check if file matches old format: {id}.jpg (where id is numeric)
            $resolved = self::resolveNewCoverFilename($filename);
            if ($resolved !== null) {
                $newFilename = $resolved['new_filename'];
                $newPath = $animeCoverPath.$newFilename;

                // Skip if new format already exists
                if (file_exists($newPath)) {
                    $this->warn("Skipping {$filename} - {$newFilename} already exists");
                    $skipped++;

                    continue;
                }

                if ($dryRun) {
                    $this->line("Would rename: {$filename} -> {$newFilename}");
                    $renamed++;
                } else {
                    if (rename($file->getPathname(), $newPath)) {
                        $this->info("Renamed: {$filename} -> {$newFilename}");
                        $renamed++;
                    } else {
                        $this->error("Failed to rename: {$filename}");
                    }
                }
            }
        }

        foreach (self::formatMigrationSummary((bool) $dryRun, $renamed, $skipped) as $line) {
            $this->info($line);
        }

        return 0;
    }

    /**
     * The new-format name for an old-format `{id}.jpg` cover, or `null` when the name doesn't match.
     *
     * @return array{anidbid: int, new_filename: string}|null
     */
    public static function resolveNewCoverFilename(string $filename): ?array
    {
        if (self::$useRustFfi) {
            return self::resolveNewCoverFilenameNative($filename);
        }

        return self::resolveNewCoverFilenamePhp($filename);
    }

    /**
     * @return array{anidbid: int, new_filename: string}|null
     */
    public static function resolveNewCoverFilenamePhp(string $filename): ?array
    {
        if (preg_match('/^(\d+)\.jpg$/', $filename, $matches) !== 1) {
            return null;
        }

        return ['anidbid' => (int) $matches[1], 'new_filename' => "{$matches[1]}-cover.jpg"];
    }

    /**
     * @return array{anidbid: int, new_filename: string}|null
     */
    public static function resolveNewCoverFilenameNative(string $filename): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::resolveNewCoverFilenamePhp($filename);
        }

        try {
            $anidbid = $ffi->new('int64_t[1]');
            $out = self::callBufferFfi(
                $ffi,
                strlen($filename) + 16,
                'nntmux_migrate_anime_covers_resolve_filename',
                $filename,
                strlen($filename),
                $anidbid,
            );
            if ($out === null) {
                return self::resolveNewCoverFilenamePhp($filename);
            }

            return $out === '' ? null : ['anidbid' => (int) $anidbid[0], 'new_filename' => $out];
        } catch (Throwable) {
            return self::resolveNewCoverFilenamePhp($filename);
        }
    }

    /**
     * @return list<string>
     */
    public static function formatMigrationSummary(bool $dryRun, int $renamed, int $skipped): array
    {
        if (self::$useRustFfi) {
            return self::formatMigrationSummaryNative($dryRun, $renamed, $skipped);
        }

        return self::formatMigrationSummaryPhp($dryRun, $renamed, $skipped);
    }

    /**
     * @return list<string>
     */
    public static function formatMigrationSummaryPhp(bool $dryRun, int $renamed, int $skipped): array
    {
        if ($dryRun) {
            return [
                "\nDry run complete. Would rename {$renamed} file(s), skipped {$skipped}.",
                'Run without --dry-run to perform the migration.',
            ];
        }

        return ["\nMigration complete. Renamed {$renamed} file(s), skipped {$skipped}."];
    }

    /**
     * @return list<string>
     */
    public static function formatMigrationSummaryNative(bool $dryRun, int $renamed, int $skipped): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::formatMigrationSummaryPhp($dryRun, $renamed, $skipped);
        }

        try {
            $lens = $ffi->new('size_t[2]');
            $out = self::callBufferFfi(
                $ffi,
                192,
                'nntmux_migrate_anime_covers_format_summary',
                $dryRun ? 1 : 0,
                $renamed,
                $skipped,
                $lens,
            );
            if ($out === null) {
                return self::formatMigrationSummaryPhp($dryRun, $renamed, $skipped);
            }

            $first = (int) $lens[0];
            $lines = [substr($out, 0, $first)];
            if ($dryRun) {
                $lines[] = substr($out, $first, (int) $lens[1]);
            }

            return $lines;
        } catch (Throwable) {
            return self::formatMigrationSummaryPhp($dryRun, $renamed, $skipped);
        }
    }

    /**
     * Runs a buffer-writing export, appending the scratch buffer and its capacity; `null` when it declines.
     */
    private static function callBufferFfi(FFI $ffi, int $minBytes, string $symbol, mixed ...$args): ?string
    {
        $cap = 0;
        $buf = RustPipelineLibrary::obtainScratch($ffi, max(128, $minBytes), $cap);
        if ($buf === null) {
            return null;
        }

        $rc = (int) $ffi->$symbol(...[...$args, $buf, $cap]);
        if ($rc < 0) {
            return null;
        }

        return $rc > 0 ? FFI::string($buf, $rc) : '';
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $envLib = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $ffi = RustPipelineLibrary::resolveDomain(
            'metadata',
            self::FFI_CDEF,
            is_string($envLib) && $envLib !== '' ? $envLib : null,
        );
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
