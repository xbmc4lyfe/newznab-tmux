<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Release;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class FetchSamples extends Command
{
    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_fetch_samples_parse_category_ids(
    uint8_t has_option,
    const char *option_ptr,
    size_t option_len,
    int64_t *out_ids,
    size_t out_cap
);
int64_t nntmux_fetch_samples_effective_total(int64_t limit, int64_t total_all);
ssize_t nntmux_fetch_samples_format_completion_summary(
    int64_t succeeded,
    int64_t failed,
    uint8_t *out_buf,
    size_t out_cap
);
CDEF;

    /**
     * Rust FFI stays opt-in: option parsing and summaries are too small for FFI to beat PHP by 1.5x.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    protected $signature = 'releases:fetch-samples
                                {--category= : Category id or comma-separated list of category ids (required)}
                                {--limit=0 : Max number of releases to process (0 = all)}
                                {--chunk=500 : Chunk size when iterating releases}
                                {--dry-run : Show how many and which GUIDs would be processed without running}
                                {--show-output : Display output from each releases:additional invocation}';

    protected $description = 'Fetch/generate samples by running additional postprocessing (with reset) for releases in the supplied category / categories having jpgstatus = 0.';

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

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = (bool) $useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi = null): void
    {
        self::$ffi = $ffi;
    }

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $chunkSize = (int) $this->option('chunk');
        $dryRun = (bool) $this->option('dry-run');
        $showOutput = (bool) $this->option('show-output');
        $categoryOpt = $this->option('category');

        // Validate the required category option.
        if ($categoryOpt === null || trim((string) $categoryOpt) === '') {
            $this->info('Category option is empty. Provide --category with one or more numeric category IDs. Command will not run.');

            return self::SUCCESS;
        }

        // Parse categories: allow comma or whitespace separated values.
        $catIds = self::parseCategoryIdsOption((string) $categoryOpt);

        if ($catIds === []) {
            $this->info('Category option provided but no valid numeric IDs parsed. Command will not run.');

            return self::SUCCESS;
        }

        if ($limit < 0) {
            $this->error('Limit must be >= 0');

            return self::FAILURE;
        }
        if ($chunkSize < 1) {
            $this->error('Chunk size must be >= 1');

            return self::FAILURE;
        }

        // Build base query now
        $baseQuery = Release::query()
            ->whereIn('categories_id', $catIds)
            ->where('jpgstatus', 0)
            ->orderBy('id', 'desc');

        $totalAll = $baseQuery->count();
        if ($totalAll === 0) {
            $this->info('No matching releases found (categories_id IN ['.implode(',', $catIds).'] AND jpgstatus = 0).');

            return self::SUCCESS;
        }

        $effectiveTotal = self::computeEffectiveTotal($limit, $totalAll);
        $this->info('Categories: ['.implode(',', $catIds).']');
        $this->info("Found {$totalAll} matching release(s). Processing {$effectiveTotal}.".($dryRun ? ' (dry-run)' : ''));

        if ($dryRun) {
            $previewQuery = clone $baseQuery;
            if ($limit > 0) {
                $previewQuery->limit($limit);
            }
            $previewGuids = $previewQuery->pluck('guid');
            $this->line('Dry run: GUIDs to process (with --reset):');
            foreach ($previewGuids as $g) {
                $this->line($g);
            }
            $this->info('Dry run complete.');

            return self::SUCCESS;
        }

        $processed = 0;
        $failed = 0;
        $remaining = $effectiveTotal;

        $bar = $this->output->createProgressBar($effectiveTotal);
        $bar->start();

        $query = clone $baseQuery;

        // Use chunkByIdDesc to process newest releases first
        $query->chunkByIdDesc($chunkSize, function ($releases) use (&$processed, &$failed, &$remaining, $bar, $showOutput) {
            foreach ($releases as $release) {
                if ($remaining <= 0) {
                    return false; // stop chunking
                }

                $guid = $release->guid;
                try {
                    // Call the existing single-release additional processing command with this GUID.
                    $exitCode = Artisan::call('releases:additional', [
                        'guid' => $guid, // pass GUID explicitly
                        '--reset' => true,
                    ]);
                    $subOutput = trim(Artisan::output());

                    if ($exitCode === 0) {
                        $processed++;
                        if ($showOutput && $subOutput !== '') {
                            $this->getOutput()->writeln("\n<info>{$guid}</info> -> {$subOutput}");
                        }
                    } else {
                        $failed++;
                        $this->getOutput()->writeln("\n<error>Non-zero exit code ({$exitCode}) for GUID {$guid}</error>".($showOutput && $subOutput !== '' ? "\n  Output: {$subOutput}" : ''));
                    }
                } catch (Throwable $e) {
                    $failed++;
                    $this->getOutput()->writeln("\n<error>Error processing GUID {$guid}: {$e->getMessage()}</error>");
                }

                $remaining--;
                $bar->advance();

                if ($remaining <= 0) {
                    break; // exit foreach to stop further work
                }
            }

            if ($remaining <= 0) {
                return false; // signal chunkById to stop
            }

            return true; // continue chunking
        }, 'id');

        $bar->finish();
        $this->newLine();
        $this->info(self::formatCompletionSummary($processed, $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Parses comma- or whitespace-separated numeric category IDs, keeping first-seen order.
     *
     * @return list<int>
     */
    public static function parseCategoryIdsOption(?string $categoryOption): array
    {
        if (self::$useRustFfi) {
            return self::parseCategoryIdsOptionNative($categoryOption);
        }

        return self::parseCategoryIdsOptionPhp($categoryOption);
    }

    /**
     * @return list<int>
     */
    public static function parseCategoryIdsOptionPhp(?string $categoryOption): array
    {
        if ($categoryOption === null) {
            return [];
        }

        $tokens = preg_split('/[\s,]+/', trim($categoryOption), -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens === false) {
            return [];
        }

        $ids = [];
        foreach ($tokens as $token) {
            $token = trim($token);
            if (ctype_digit($token)) {
                $ids[] = (int) $token;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<int>
     */
    public static function parseCategoryIdsOptionNative(?string $categoryOption): array
    {
        if ($categoryOption === null) {
            return [];
        }

        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::parseCategoryIdsOptionPhp($categoryOption);
        }

        try {
            $cap = intdiv(strlen($categoryOption), 2) + 1;
            $outIds = $ffi->new("int64_t[{$cap}]");

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_fetch_samples_parse_category_ids(
                1,
                $categoryOption,
                strlen($categoryOption),
                $outIds,
                $cap
            );
            if (! is_int($written) || $written < 0 || $written > $cap) {
                return self::parseCategoryIdsOptionPhp($categoryOption);
            }

            $ids = [];
            for ($i = 0; $i < $written; $i++) {
                $id = $outIds[$i];
                if (! is_int($id)) {
                    return self::parseCategoryIdsOptionPhp($categoryOption);
                }
                $ids[] = $id;
            }

            return $ids;
        } catch (Throwable) {
            return self::parseCategoryIdsOptionPhp($categoryOption);
        }
    }

    public static function computeEffectiveTotal(int $limit, int $totalAll): int
    {
        if (self::$useRustFfi) {
            return self::computeEffectiveTotalNative($limit, $totalAll);
        }

        return self::computeEffectiveTotalPhp($limit, $totalAll);
    }

    public static function computeEffectiveTotalPhp(int $limit, int $totalAll): int
    {
        return $limit > 0 ? min($limit, $totalAll) : $totalAll;
    }

    public static function computeEffectiveTotalNative(int $limit, int $totalAll): int
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::computeEffectiveTotalPhp($limit, $totalAll);
        }

        try {
            // @phpstan-ignore method.notFound
            $result = $ffi->nntmux_fetch_samples_effective_total($limit, $totalAll);

            return is_int($result) ? $result : self::computeEffectiveTotalPhp($limit, $totalAll);
        } catch (Throwable) {
            return self::computeEffectiveTotalPhp($limit, $totalAll);
        }
    }

    public static function formatCompletionSummary(int $succeeded, int $failed): string
    {
        if (self::$useRustFfi) {
            return self::formatCompletionSummaryNative($succeeded, $failed);
        }

        return self::formatCompletionSummaryPhp($succeeded, $failed);
    }

    public static function formatCompletionSummaryPhp(int $succeeded, int $failed): string
    {
        return "Processing complete. Success: {$succeeded}, Failed: {$failed}.";
    }

    public static function formatCompletionSummaryNative(int $succeeded, int $failed): string
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::formatCompletionSummaryPhp($succeeded, $failed);
        }

        try {
            $cap = 96;
            $outBuf = $ffi->new("uint8_t[{$cap}]");

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_fetch_samples_format_completion_summary($succeeded, $failed, $outBuf, $cap);
            if (! is_int($written) || $written <= 0) {
                return self::formatCompletionSummaryPhp($succeeded, $failed);
            }

            return FFI::string($outBuf, $written);
        } catch (Throwable) {
            return self::formatCompletionSummaryPhp($succeeded, $failed);
        }
    }

    private static function additionalFfi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
