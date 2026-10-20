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

    protected $description = 'Copy retained NZBs into the shared database and enqueue Rust fingerprints; preserve originals';

    public function handle(): int
    {
        $roots = array_map(static fn (string $path): string => rtrim($path, '/'), $this->option('root'));
        if ($roots === [] || array_filter($roots, static fn (string $path): bool => ! is_dir($path)) !== []) {
            $this->error('Supply existing legacy directories with --root. Files are never removed by this command.');

            return self::FAILURE;
        }
        $name = (string) $this->option('name');
        if ($name === '' || strlen($name) > 120) {
            return self::INVALID;
        }
        $cursor = $this->option('after-id') !== null ? max(0, (int) $this->option('after-id'))
            : (int) (DB::table('nzb_storage_imports')->where('name', $name)->value('last_release_id') ?? 0);
        $limit = max(1, min(10000, (int) $this->option('limit')));
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
                            app(DatabaseNzbStore::class)->putLocked($current, $body);
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
        $this->info(($this->option('dry-run') ? 'Dry run: ' : '')."Imported {$copied}; already stored {$existing}; resume after release {$cursor}.");

        return self::SUCCESS;
    }

    /**
     * Search all supported split depths without recursively loading the archive inventory.
     *
     * @param  list<string>  $roots
     */
    private function locate(array $roots, string $guid): string
    {
        if (preg_match('/^[a-zA-Z0-9_-]{1,40}$/D', $guid) !== 1) {
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
}
