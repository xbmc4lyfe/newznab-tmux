<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\NNTP\NNTPService;
use App\Services\Nzb\DatabaseNzbStore;
use App\Services\Nzb\NzbImportService;
use App\Services\Nzb\NzbUploadManifestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use SplFileInfo;
use Throwable;

class ImportNzbs extends Command
{
    /**
     * Default for the Wave 38.2 toggle only; it stays false because the paired benchmark in
     * tests/Unit/RustNntpServiceFingerprintDispatchCommandsParityTest.php is below 1.5x.
     */
    public const bool RUST_FFI_DEFAULT = false;

    private const array W382_KINDS = ['manifest', 'nzb_gz', 'nzb', 'other'];

    private const array W382_SIZE_CLASSES = ['empty', 'small', 'normal', 'oversized'];

    private const int W382_SMALL_NZB_BYTES = 1024;

    // Keep PHP as the production default until the Rust performance gate passes.
    private static ?bool $useRustFfi = null;

    private static ?bool $useWave382RustFfi = null;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nntmux:import-nzbs
        {--folder= : Import folder path}
        {--filename : Use filename true or false}
        {--delete : Delete files after import; verified imports require staged generation custody}
        {--delete-failed : Delete files after failed import}
        {--source= : Source of the NZB files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import nzb files for indexing';

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::useRustFfi();
    }

    public static function normalizeFolderPath(string $folder): string
    {
        return self::useRustFfi() ? self::normalizeFolderPathNative($folder) : self::normalizeFolderPathPhp($folder);
    }

    public static function normalizeFolderPathPhp(string $folder): string
    {
        return rtrim($folder, '/\\');
    }

    public static function normalizeFolderPathNative(string $folder): string
    {
        $result = ImportNfos::transformNative(0, [$folder]);

        return is_string($result) ? $result : self::normalizeFolderPathPhp($folder);
    }

    public static function isImportableNzbFilename(string $filename, string $manifestFilename = NzbUploadManifestService::FILENAME): bool
    {
        return self::useRustFfi() ? self::isImportableNzbFilenameNative($filename, $manifestFilename) : self::isImportableNzbFilenamePhp($filename, $manifestFilename);
    }

    public static function isImportableNzbFilenamePhp(string $filename, string $manifestFilename = NzbUploadManifestService::FILENAME): bool
    {
        if ($filename === $manifestFilename) {
            return false;
        }

        return Str::endsWith(strtolower($filename), ['.nzb', '.nzb.gz']);
    }

    public static function isImportableNzbFilenameNative(string $filename, string $manifestFilename = NzbUploadManifestService::FILENAME): bool
    {
        $result = ImportNfos::transformNative(3, [$filename, $manifestFilename]);

        return is_bool($result) ? $result : self::isImportableNzbFilenamePhp($filename, $manifestFilename);
    }

    public static function normalizeSourceId(mixed $source): int
    {
        return self::useRustFfi() ? self::normalizeSourceIdNative($source) : self::normalizeSourceIdPhp($source);
    }

    public static function normalizeSourceIdPhp(mixed $source): int
    {
        return $source ? (int) $source : 1;
    }

    public static function normalizeSourceIdNative(mixed $source): int
    {
        // Floats, arrays and objects keep PHP's own truthiness and cast rules.
        if ($source !== null && ! is_bool($source) && ! is_int($source) && ! is_string($source)) {
            return self::normalizeSourceIdPhp($source);
        }
        $result = ImportNfos::transformNative(4, [$source]);

        return is_int($result) ? $result : self::normalizeSourceIdPhp($source);
    }

    public static function formatCompletionSummary(int $imported, int $failed, int $skipped): string
    {
        return self::useRustFfi() ? self::formatCompletionSummaryNative($imported, $failed, $skipped) : self::formatCompletionSummaryPhp($imported, $failed, $skipped);
    }

    public static function formatCompletionSummaryPhp(int $imported, int $failed, int $skipped): string
    {
        return "Completed: {$imported} imported, {$failed} failed, {$skipped} skipped.";
    }

    public static function formatCompletionSummaryNative(int $imported, int $failed, int $skipped): string
    {
        $result = ImportNfos::transformNative(5, [$imported, $failed, $skipped]);

        return is_string($result) ? $result : self::formatCompletionSummaryPhp($imported, $failed, $skipped);
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
     * Classify one import candidate: the upload manifest first, then a case-insensitive ".nzb.gz" or ".nzb" suffix
     * (the isImportableNzbFilename() rule), with the stem before that suffix and a size class of empty (<= 0),
     * small (< 1 KiB), normal (<= DatabaseNzbStore::MAX_COMPRESSED_BYTES) or oversized.
     *
     * @return array{kind: string, importable: bool, compressed: bool, stem: string, size_class: string}
     */
    public static function summarizeImportFile(string $filename, int $sizeBytes, string $manifestFilename = NzbUploadManifestService::FILENAME): array
    {
        return (self::usesWave382RustFfi() ? self::summarizeImportFileNative($filename, $sizeBytes, $manifestFilename) : null)
            ?? self::summarizeImportFilePhp($filename, $sizeBytes, $manifestFilename);
    }

    /**
     * Execute the console command.
     */
    public function handle(NzbUploadManifestService $manifests): void
    {
        $folderOption = $this->option('folder');
        if (! is_string($folderOption) || trim($folderOption) === '') {
            $this->error('Folder path must not be empty');

            return;
        }

        $importFolder = self::normalizeFolderPath($folderOption);
        if (! File::isDirectory($importFolder)) {
            $this->error('Folder path does not exist: '.$importFolder);

            return;
        }

        $useNzbName = (bool) $this->option('filename');
        $deleteNZB = (bool) $this->option('delete');
        $deleteFailedNZB = (bool) $this->option('delete-failed');
        $source = self::normalizeSourceId($this->option('source'));

        try {
            $files = array_values(array_filter(
                File::allFiles($importFolder),
                static function (SplFileInfo $file) use ($manifests): bool {
                    $path = $file->getPathname();
                    if ($file->getFilename() === NzbUploadManifestService::FILENAME) {
                        $manifests->reconcilePending($path);

                        return false;
                    }
                    if (! self::isImportableNzbFilename($file->getFilename(), NzbUploadManifestService::FILENAME)) {
                        return true;
                    }

                    return $manifests->shouldImportNzb($path);
                }
            ));

            $this->info('Importing NZB files from '.$importFolder);
            $importer = new NzbImportService;
            $importer->beginImport(
                $files,
                $useNzbName,
                $deleteNZB,
                $deleteFailedNZB,
                $source,
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
        }
    }

    /** @return array{kind: string, importable: bool, compressed: bool, stem: string, size_class: string} */
    private static function summarizeImportFilePhp(string $filename, int $sizeBytes, string $manifestFilename): array
    {
        $lower = strtolower($filename);
        [$kind, $suffix] = match (true) {
            $filename === $manifestFilename => [0, 0],
            str_ends_with($lower, '.nzb.gz') => [1, 7],
            str_ends_with($lower, '.nzb') => [2, 4],
            default => [3, 0],
        };
        $sizeClass = match (true) {
            $sizeBytes <= 0 => 0,
            $sizeBytes < self::W382_SMALL_NZB_BYTES => 1,
            $sizeBytes <= DatabaseNzbStore::MAX_COMPRESSED_BYTES => 2,
            default => 3,
        };

        return self::importFileSummary($kind, substr($filename, 0, strlen($filename) - $suffix), $sizeClass);
    }

    /** @return array{kind: string, importable: bool, compressed: bool, stem: string, size_class: string}|null */
    private static function summarizeImportFileNative(string $filename, int $sizeBytes, string $manifestFilename): ?array
    {
        $slots = NNTPService::evaluateWave382Native(3, 0,
            pack('q3', $sizeBytes, strlen($filename), strlen($manifestFilename)).$filename.$manifestFilename, 5);
        if ($slots === null) {
            return null;
        }
        [$kind, $importable, $compressed, $stem, $sizeClass] = $slots;
        if (! isset(self::W382_KINDS[$kind], self::W382_SIZE_CLASSES[$sizeClass]) || $importable !== (int) ($kind === 1 || $kind === 2)
            || $compressed !== (int) ($kind === 1) || $stem < 0 || $stem > strlen($filename)) {
            return null;
        }

        return self::importFileSummary($kind, substr($filename, 0, $stem), $sizeClass);
    }

    /** @return array{kind: string, importable: bool, compressed: bool, stem: string, size_class: string} */
    private static function importFileSummary(int $kind, string $stem, int $sizeClass): array
    {
        return ['kind' => self::W382_KINDS[$kind], 'importable' => $kind === 1 || $kind === 2, 'compressed' => $kind === 1, 'stem' => $stem,
            'size_class' => self::W382_SIZE_CLASSES[$sizeClass]];
    }

    private static function useRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }
}
