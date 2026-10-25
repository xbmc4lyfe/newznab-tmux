<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\NNTP\NNTPService;
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

    /**
     * Default for the Wave 38.2 toggle only; it stays false because the paired benchmark in
     * tests/Unit/RustNntpServiceFingerprintDispatchCommandsParityTest.php is below 1.5x.
     */
    public const bool RUST_FFI_DEFAULT = false;

    // Keep PHP as the production default until the Rust performance gate passes.
    private static ?bool $useRustFfi = null;

    private static ?bool $useWave382RustFfi = null;

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

    public static function useWave382RustFfi(?bool $use): void
    {
        self::$useWave382RustFfi = $use;
    }

    public static function usesWave382RustFfi(): bool
    {
        return self::$useWave382RustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function resetWave382RustFfi(): void
    {
        self::$useWave382RustFfi = null;
    }

    /**
     * Summarize one batch of release ids against the resume cursor (negative reads as zero) and the clamped limit:
     * ids at or below the cursor and ids not above the last accepted id are skipped, scanning stops once limit ids
     * were accepted (truncated when ids remain), and next_cursor is the last accepted id.
     *
     * @param  list<int>  $releaseIds
     * @return array{processed: int, accepted: int, below_cursor: int, out_of_order: int, truncated: bool, next_cursor: int, span: int}
     */
    public static function summarizeImportBatch(int $cursor, int $limit, array $releaseIds): array
    {
        return (self::usesWave382RustFfi() ? self::summarizeImportBatchNative($cursor, $limit, $releaseIds) : null)
            ?? self::summarizeImportBatchPhp($cursor, $limit, $releaseIds);
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

    /**
     * @param  list<int>  $releaseIds
     * @return array{processed: int, accepted: int, below_cursor: int, out_of_order: int, truncated: bool, next_cursor: int, span: int}
     */
    private static function summarizeImportBatchPhp(int $cursor, int $limit, array $releaseIds): array
    {
        $cursor = max(0, $cursor);
        $limit = self::clampLimitPhp($limit);
        $processed = $accepted = $below = $outOfOrder = 0;
        $truncated = false;
        $previous = $cursor;
        foreach ($releaseIds as $id) {
            if ($accepted === $limit) {
                $truncated = true;
                break;
            }
            $processed++;
            if ($id <= $cursor) {
                $below++;
            } elseif ($id <= $previous) {
                $outOfOrder++;
            } else {
                $accepted++;
                $previous = $id;
            }
        }

        return ['processed' => $processed, 'accepted' => $accepted, 'below_cursor' => $below, 'out_of_order' => $outOfOrder,
            'truncated' => $truncated, 'next_cursor' => $previous, 'span' => $previous - $cursor];
    }

    /**
     * @param  list<int>  $releaseIds
     * @return array{processed: int, accepted: int, below_cursor: int, out_of_order: int, truncated: bool, next_cursor: int, span: int}|null
     */
    private static function summarizeImportBatchNative(int $cursor, int $limit, array $releaseIds): ?array
    {
        if (count($releaseIds) > 2097150) {
            return null;
        }
        $slots = NNTPService::evaluateWave382Native(2, 0, pack('q2', $cursor, $limit).pack('q*', ...$releaseIds), 7);
        if ($slots === null) {
            return null;
        }
        [$processed, $accepted, $below, $outOfOrder, $truncated, $next, $span] = $slots;
        if ($processed < 0 || $processed > count($releaseIds) || $accepted + $below + $outOfOrder !== $processed
            || ($truncated !== 0 && $truncated !== 1) || $next < max(0, $cursor) || $span !== $next - max(0, $cursor)) {
            return null;
        }

        return ['processed' => $processed, 'accepted' => $accepted, 'below_cursor' => $below, 'out_of_order' => $outOfOrder,
            'truncated' => $truncated === 1, 'next_cursor' => $next, 'span' => $span];
    }

    private static function useRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }
}
