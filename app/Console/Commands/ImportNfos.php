<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Nzb\NfoImportService;
use App\Services\Nzb\NzbUploadManifestService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

final class ImportNfos extends Command
{
    public const string FFI_CDEF = 'int64_t nntmux_w30_nfo_nzb_manifest_import_yenc_commands(uint32_t operation,const char *input,size_t length,char *output,size_t capacity);';

    private const int NATIVE_MAX_BYTES = 16777216;

    // Keep PHP as the production default until the Rust performance gate passes.
    private static ?bool $useRustFfi = null;

    protected $signature = 'nntmux:import-nfos
        {--folder= : Import folder path}
        {--delete : Delete NFO files after import}
        {--delete-failed : Delete NFO files after terminal or failed imports}';

    protected $description = 'Import paired NFO files for previously imported NZB releases';

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
        $result = self::transformNative(0, [$folder]);

        return is_string($result) ? $result : self::normalizeFolderPathPhp($folder);
    }

    /**
     * @param  array<array-key, array{dirname: string, status: string, message: string}>  $outcomes
     * @return array{imported: int, failed: int, skipped: int, warnings: list<string>}
     */
    public static function tallyManifestOutcomes(array $outcomes): array
    {
        return self::useRustFfi() ? self::tallyManifestOutcomesNative($outcomes) : self::tallyManifestOutcomesPhp($outcomes);
    }

    /**
     * @param  array<array-key, array{dirname: string, status: string, message: string}>  $outcomes
     * @return array{imported: int, failed: int, skipped: int, warnings: list<string>}
     */
    public static function tallyManifestOutcomesPhp(array $outcomes): array
    {
        $imported = $failed = $skipped = 0;
        $warnings = [];
        foreach ($outcomes as $outcome) {
            match ($outcome['status']) {
                'imported' => $imported++,
                'failed' => $failed++,
                default => $skipped++,
            };

            if ($outcome['status'] === 'failed') {
                $warnings[] = $outcome['dirname'].': '.$outcome['message'];
            }
        }

        return ['imported' => $imported, 'failed' => $failed, 'skipped' => $skipped, 'warnings' => $warnings];
    }

    /**
     * @param  array<array-key, array{dirname: string, status: string, message: string}>  $outcomes
     * @return array{imported: int, failed: int, skipped: int, warnings: list<string>}
     */
    public static function tallyManifestOutcomesNative(array $outcomes): array
    {
        foreach ($outcomes as $outcome) {
            // Rust only accepts the exact string shape; anything else keeps PHP semantics.
            if (! is_array($outcome) || ! is_string($outcome['dirname'] ?? null) || ! is_string($outcome['status'] ?? null) || ! is_string($outcome['message'] ?? null)) {
                return self::tallyManifestOutcomesPhp($outcomes);
            }
        }
        $result = self::transformNative(1, [$outcomes]);
        if (is_array($result) && array_keys($result) === ['imported', 'failed', 'skipped', 'warnings']
            && is_int($result['imported']) && is_int($result['failed']) && is_int($result['skipped'])
            && is_array($result['warnings']) && array_is_list($result['warnings'])
            && array_filter($result['warnings'], static fn (mixed $warning): bool => ! is_string($warning)) === []) {
            /** @var array{imported: int, failed: int, skipped: int, warnings: list<string>} $result */
            return $result;
        }

        return self::tallyManifestOutcomesPhp($outcomes);
    }

    public static function formatImportSummary(int $imported, int $skipped, int $failed): string
    {
        return self::useRustFfi() ? self::formatImportSummaryNative($imported, $skipped, $failed) : self::formatImportSummaryPhp($imported, $skipped, $failed);
    }

    public static function formatImportSummaryPhp(int $imported, int $skipped, int $failed): string
    {
        return "Processed {$imported} NFOs, skipped {$skipped}, failed {$failed}.";
    }

    public static function formatImportSummaryNative(int $imported, int $skipped, int $failed): string
    {
        $result = self::transformNative(2, [$imported, $skipped, $failed]);

        return is_string($result) ? $result : self::formatImportSummaryPhp($imported, $skipped, $failed);
    }

    /**
     * Shared Wave 30.2 JSON bridge; returns null whenever Rust declines or is unavailable.
     *
     * @param  array<array-key, mixed>  $args  Positional arguments; a non-list declines.
     */
    public static function transformNative(int $operation, array $args): mixed
    {
        try {
            if (! array_is_list($args) || ! self::validWireValue($args, 0)) {
                return null;
            }
            $input = json_encode($args, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            if (strlen($input) > self::NATIVE_MAX_BYTES) {
                return null;
            }
            $ffi = RustPipelineLibrary::resolveDomain('w30-nfo-nzb-manifest-import-yenc-commands', self::FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
            if (! $ffi instanceof FFI) {
                return null;
            }
            $capacity = 0;
            $output = RustPipelineLibrary::obtainScratch($ffi, min(self::NATIVE_MAX_BYTES, max(256, strlen($input) + 256)), $capacity);
            if ($output === null) {
                return null;
            }
            $capacity = min($capacity, self::NATIVE_MAX_BYTES);
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w30_nfo_nzb_manifest_import_yenc_commands($operation, $input, strlen($input), $output, $capacity);
            if (is_int($written) && $written < -1 && -$written > $capacity && -$written <= self::NATIVE_MAX_BYTES) {
                $output = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($output === null) {
                    return null;
                }
                $capacity = min($capacity, self::NATIVE_MAX_BYTES);
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w30_nfo_nzb_manifest_import_yenc_commands($operation, $input, strlen($input), $output, $capacity);
            }
            if (! is_int($written) || $written <= 0 || $written > $capacity) {
                return null;
            }

            return json_decode(FFI::string($output, $written), true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
    }

    public function handle(NzbUploadManifestService $manifests, NfoImportService $importer): int
    {
        $folderOption = $this->option('folder');
        if (! is_string($folderOption) || trim($folderOption) === '') {
            $this->error('Folder path must not be empty');

            return self::FAILURE;
        }

        $folder = self::normalizeFolderPath($folderOption);
        if (! File::isDirectory($folder)) {
            $this->error('Folder path does not exist: '.$folder);

            return self::FAILURE;
        }

        try {
            $manifestPaths = $manifests->findManifests($folder);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $outcomes = [];
        foreach ($manifestPaths as $manifestPath) {
            $result = $importer->importManifest(
                $manifestPath,
                (bool) $this->option('delete'),
                (bool) $this->option('delete-failed'),
            );
            $outcomes[] = ['dirname' => basename(dirname($manifestPath)), 'status' => $result['status'], 'message' => $result['message']];
        }

        $tally = self::tallyManifestOutcomes($outcomes);
        foreach ($tally['warnings'] as $warning) {
            $this->warn($warning);
        }
        $failed = $tally['failed'];
        $this->info(self::formatImportSummary($tally['imported'], $tally['skipped'], $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private static function useRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }

    private static function validWireValue(mixed $value, int $depth): bool
    {
        if ($depth > 30) {
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (! self::validWireValue($item, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || is_bool($value) || is_int($value) || is_string($value);
    }
}
