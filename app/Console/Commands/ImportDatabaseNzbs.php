<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Nzb\DatabaseNzbStore;
use App\Services\Nzb\NzbSourceGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class ImportDatabaseNzbs extends Command
{
    protected $signature = 'nzbs:import-database {--root=* : Legacy NZB roots, searched in this order}
        {--limit=1000 : Maximum releases per invocation} {--after-id= : Override the stored resume cursor}
        {--name=published-nzbs : Database checkpoint name} {--dry-run : Inspect without writing}';

    protected $description = 'Copy retained NZBs into the shared database without adding historical fingerprint work; preserve originals';

    // Keep PHP as the production default until the Rust performance gate passes.
    private static ?bool $useRustFfi = null;

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::useRustFfi();
    }

    public static function isValidCheckpointName(string $checkpoint): bool
    {
        return self::useRustFfi() ? self::isValidCheckpointNameNative($checkpoint) : self::isValidCheckpointNamePhp($checkpoint);
    }

    public static function isValidCheckpointNamePhp(string $checkpoint): bool
    {
        return $checkpoint !== '' && strlen($checkpoint) <= 120;
    }

    public static function isValidCheckpointNameNative(string $checkpoint): bool
    {
        $result = ImportNfos::transformNative(6, [$checkpoint]);

        return is_bool($result) ? $result : self::isValidCheckpointNamePhp($checkpoint);
    }

    public static function clampLimit(int $limit): int
    {
        return self::useRustFfi() ? self::clampLimitNative($limit) : self::clampLimitPhp($limit);
    }

    public static function clampLimitPhp(int $limit): int
    {
        return max(1, min(10000, $limit));
    }

    public static function clampLimitNative(int $limit): int
    {
        $result = ImportNfos::transformNative(7, [$limit]);

        return is_int($result) ? $result : self::clampLimitPhp($limit);
    }

    /**
     * The anchored pattern rejects trailing newlines (`D`), matching the legacy lookup guard.
     */
    public static function isValidReleaseGuid(string $guid): bool
    {
        return self::useRustFfi() ? self::isValidReleaseGuidNative($guid) : self::isValidReleaseGuidPhp($guid);
    }

    public static function isValidReleaseGuidPhp(string $guid): bool
    {
        return preg_match('/^[a-zA-Z0-9_-]{1,40}$/D', $guid) === 1;
    }

    public static function isValidReleaseGuidNative(string $guid): bool
    {
        $result = ImportNfos::transformNative(8, [$guid]);

        return is_bool($result) ? $result : self::isValidReleaseGuidPhp($guid);
    }

    public static function formatSummaryLine(bool $dryRun, int $processed, int $imported, int $alreadyInDb, int $missingFile, int $readFailed, int $cursor): string
    {
        return self::useRustFfi()
            ? self::formatSummaryLineNative($dryRun, $processed, $imported, $alreadyInDb, $missingFile, $readFailed, $cursor)
            : self::formatSummaryLinePhp($dryRun, $processed, $imported, $alreadyInDb, $missingFile, $readFailed, $cursor);
    }

    public static function formatSummaryLinePhp(bool $dryRun, int $processed, int $imported, int $alreadyInDb, int $missingFile, int $readFailed, int $cursor): string
    {
        $line = ($dryRun ? 'Dry run: ' : '')."Imported {$imported}; already stored {$alreadyInDb}; resume after release {$cursor}.";
        if ($missingFile !== 0 || $readFailed !== 0) {
            $line .= " Skipped {$missingFile} missing and {$readFailed} unreadable of {$processed} processed.";
        }

        return $line;
    }

    public static function formatSummaryLineNative(bool $dryRun, int $processed, int $imported, int $alreadyInDb, int $missingFile, int $readFailed, int $cursor): string
    {
        $result = ImportNfos::transformNative(9, [$dryRun, $processed, $imported, $alreadyInDb, $missingFile, $readFailed, $cursor]);

        return is_string($result) ? $result : self::formatSummaryLinePhp($dryRun, $processed, $imported, $alreadyInDb, $missingFile, $readFailed, $cursor);
    }

    public function handle(): int
    {
        $roots = array_map(static fn (string $path): string => rtrim($path, '/'), $this->option('root'));
        if ($roots === [] || array_filter($roots, static fn (string $path): bool => ! is_dir($path)) !== []) {
            $this->error('Supply existing legacy directories with --root. Files are never removed by this command.');

            return self::FAILURE;
        }
        $name = (string) $this->option('name');
        if (! self::isValidCheckpointName($name)) {
            return self::INVALID;
        }
        $cursor = $this->option('after-id') !== null ? max(0, (int) $this->option('after-id'))
            : (int) (DB::table('nzb_storage_imports')->where('name', $name)->value('last_release_id') ?? 0);
        $limit = self::clampLimit((int) $this->option('limit'));
        $rows = DB::table('releases')->where('id', '>', $cursor)->where('nzbstatus', 1)->orderBy('id')->limit($limit)->get(['id', 'guid']);
        $copied = $existing = 0;
        foreach ($rows as $release) {
            try {
                $isExisting = DB::table('release_nzb_contents')->where('guid', $release->guid)->exists();
                $body = null;
                if (! $isExisting) {
                    $path = $this->locate($roots, (string) $release->guid);
                    $body = file_get_contents($path, false, null, 0, DatabaseNzbStore::MAX_COMPRESSED_BYTES + 1);
                    if ($body === false || strlen($body) > DatabaseNzbStore::MAX_COMPRESSED_BYTES
                        || DatabaseNzbStore::decode($body, DatabaseNzbStore::MAX_DECODED_BYTES) === false) {
                        throw new RuntimeException('Legacy NZB is corrupt or exceeds the import ceiling.');
                    }
                }
                if (! $this->option('dry-run')) {
                    DB::transaction(function () use ($release, $body, $name): void {
                        $current = DB::table('releases')->where('id', $release->id)->where('guid', $release->guid)->lockForUpdate()->first(['id', 'guid']);
                        if ($current !== null && ! DB::table('release_nzb_contents')->where('guid', $current->guid)->lockForUpdate()->exists()) {
                            if ($body === null) {
                                throw new RuntimeException('Database source disappeared; retry this release.');
                            }
                            $xml = DatabaseNzbStore::decode($body, DatabaseNzbStore::MAX_DECODED_BYTES);
                            $committed = DB::table('release_archive_naming_evidence')->where('releases_id', $current->id)->value('source_sha256');
                            if ($committed !== null && (! is_string($xml) || ! hash_equals($committed, NzbSourceGuard::digest($xml)))) {
                                throw new RuntimeException('Legacy source differs from the committed generation; settle its upgrade before import.');
                            }
                            app(DatabaseNzbStore::class)->putLocked($current, $body, enqueueWork: false);
                        }
                        DB::table('nzb_storage_imports')->updateOrInsert(['name' => $name], ['last_release_id' => $release->id, 'updated_at' => now('UTC')]);
                    });
                }
                $isExisting ? $existing++ : $copied++;
                $cursor = (int) $release->id;
            } catch (Throwable $exception) {
                $this->error('Stopped at release '.$release->id.': '.$exception->getMessage());
                $this->line('Last committed release ID: '.$cursor.'. No original files were deleted.');

                return self::FAILURE;
            }
        }
        // The import stops at the first unreadable or missing source, so a completed run has none.
        $this->info(self::formatSummaryLine((bool) $this->option('dry-run'), $copied + $existing, $copied, $existing, 0, 0, $cursor));

        return self::SUCCESS;
    }

    /**
     * Search all supported split depths without recursively loading the archive inventory.
     *
     * @param  list<string>  $roots
     */
    private function locate(array $roots, string $guid): string
    {
        if (! self::isValidReleaseGuid($guid)) {
            throw new RuntimeException('Invalid release GUID.');
        }
        foreach ($roots as $root) {
            $prefix = $root.'/';
            for ($level = 0; $level <= min(32, strlen($guid)); $level++) {
                $path = $prefix.$guid.'.nzb.gz';
                if (is_file($path) && ! is_link($path)) {
                    return $path;
                }
                if ($level < strlen($guid)) {
                    $prefix .= $guid[$level].'/';
                }
            }
        }
        throw new RuntimeException('Legacy NZB is missing.');
    }

    private static function useRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }
}
