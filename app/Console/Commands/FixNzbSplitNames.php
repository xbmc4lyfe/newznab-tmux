<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Facades\Search;
use App\Models\Release;
use App\Services\Categorization\CategorizationService;
use App\Services\NameFixing\NzbSplitUnwrapper;
use App\Services\TvProcessing\TvProcessingCandidateQuery;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Throwable;

class FixNzbSplitNames extends Command
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

    protected $signature = 'releases:fix-nzbsplit
                            {--dry-run : Preview changes without updating the database}
                            {--include-renamed : Include releases already marked as renamed}';

    protected $description = 'Fix NZBSPLIT-wrapped release names for unrenamed releases by default and recategorize the affected releases.';

    public static function formatDryRunDiffLine(int $id, string $oldName, string $newName, int $oldCat, int $newCat): string
    {
        return self::$useRustFfi
            ? self::formatDryRunDiffLineNative($id, $oldName, $newName, $oldCat, $newCat)
            : self::formatDryRunDiffLinePhp($id, $oldName, $newName, $oldCat, $newCat);
    }

    public static function formatDryRunDiffLinePhp(int $id, string $oldName, string $newName, int $oldCat, int $newCat): string
    {
        return "[dry-run] #{$id} {$oldName} -> {$newName} ({$oldCat} -> {$newCat})";
    }

    public static function formatDryRunDiffLineNative(int $id, string $oldName, string $newName, int $oldCat, int $newCat): string
    {
        if (self::callNative(['fix_nzb_split.format_dry_run_diff_line', $id, $oldName, $newName, $oldCat, $newCat], $result) && is_string($result)) {
            return $result;
        }

        return self::formatDryRunDiffLinePhp($id, $oldName, $newName, $oldCat, $newCat);
    }

    /**
     * Renamed titles redo every title-derived lookup.
     *
     * @return array<string, int|string|null>
     */
    public static function buildResetUpdatePayload(string $newName, int $newCategoryId): array
    {
        return self::$useRustFfi
            ? self::buildResetUpdatePayloadNative($newName, $newCategoryId)
            : self::buildResetUpdatePayloadPhp($newName, $newCategoryId);
    }

    /**
     * @return array<string, int|string|null>
     */
    public static function buildResetUpdatePayloadPhp(string $newName, int $newCategoryId): array
    {
        return [
            'name' => $newName,
            'searchname' => $newName,
            'categories_id' => $newCategoryId,
            'isrenamed' => 1,
            'iscategorized' => 1,
            'videos_id' => 0,
            'tv_episodes_id' => 0,
            'imdbid' => null,
            'musicinfo_id' => null,
            'consoleinfo_id' => null,
            'gamesinfo_id' => 0,
            'bookinfo_id' => null,
            'anidbid' => null,
            'movieinfo_id' => null,
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    public static function buildResetUpdatePayloadNative(string $newName, int $newCategoryId): array
    {
        $expected = self::buildResetUpdatePayloadPhp($newName, $newCategoryId);
        if (! self::callNative(['fix_nzb_split.build_reset_update_payload', $newName, $newCategoryId], $result)
            || ! is_array($result) || array_keys($result) !== array_keys($expected)) {
            return $expected;
        }
        $payload = [];
        foreach ($result as $key => $value) {
            if (! is_string($key) || ! ($value === null || is_int($value) || is_string($value))) {
                return $expected;
            }
            $payload[$key] = $value;
        }

        return $payload;
    }

    public static function formatCompletionSummary(bool $dryRun, int $fixed, int $checked): string
    {
        return self::$useRustFfi
            ? self::formatCompletionSummaryNative($dryRun, $fixed, $checked)
            : self::formatCompletionSummaryPhp($dryRun, $fixed, $checked);
    }

    public static function formatCompletionSummaryPhp(bool $dryRun, int $fixed, int $checked): string
    {
        return $dryRun
            ? sprintf('Dry run complete. %d NZBSPLIT-wrapped releases inspected.', $checked)
            : sprintf('Updated %d NZBSPLIT-wrapped releases.', $fixed);
    }

    public static function formatCompletionSummaryNative(bool $dryRun, int $fixed, int $checked): string
    {
        if (self::callNative(['fix_nzb_split.format_completion_summary', $dryRun, $fixed, $checked], $result) && is_string($result)) {
            return $result;
        }

        return self::formatCompletionSummaryPhp($dryRun, $fixed, $checked);
    }

    public function handle(CategorizationService $categorizationService, NzbSplitUnwrapper $nzbSplitUnwrapper): int
    {
        $includeRenamed = (bool) $this->option('include-renamed');

        $query = Release::query()
            ->select(['id', 'name', 'searchname', 'fromname', 'groups_id', 'categories_id'])
            ->where(function ($builder): void {
                $builder
                    ->where('name', 'like', '%__NZBSPLIT__%')
                    ->orWhere('searchname', 'like', '%__NZBSPLIT__%');
            })
            ->orderBy('id');

        if (! $includeRenamed) {
            $query->where('isrenamed', 0);
        }

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info($includeRenamed
                ? 'No NZBSPLIT-wrapped releases found.'
                : 'No unrenamed NZBSPLIT-wrapped releases found.'
            );

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $updated = 0;
        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $query->chunkById(250, function ($releases) use ($categorizationService, $nzbSplitUnwrapper, $dryRun, &$updated, $bar): void {
            foreach ($releases as $release) {
                $bar->advance();

                $newTitle = $nzbSplitUnwrapper->unwrap((string) $release->searchname)
                    ?? $nzbSplitUnwrapper->unwrap((string) $release->name);

                if ($newTitle === null) {
                    continue;
                }

                $category = $categorizationService->determineCategory(
                    $release->groups_id,
                    $newTitle,
                    (string) ($release->fromname ?? '')
                );

                $newCategoryId = (int) ($category['categories_id'] ?? $release->categories_id);

                if ($dryRun) {
                    $this->newLine();
                    $this->line(self::formatDryRunDiffLine(
                        (int) $release->id,
                        (string) $release->searchname,
                        $newTitle,
                        (int) $release->categories_id,
                        $newCategoryId
                    ));

                    continue;
                }

                Release::query()
                    ->where('id', $release->id)
                    ->update([
                        ...self::buildResetUpdatePayload($newTitle, $newCategoryId),
                        // The TV lookup state also carries its retry counters.
                        ...TvProcessingCandidateQuery::resetColumns(),
                    ]);

                Search::updateRelease((int) $release->id);
                $updated++;
            }
        });

        $bar->finish();
        $this->newLine(2);

        if ($dryRun) {
            $this->info(self::formatCompletionSummary(true, $updated, $count));

            return self::SUCCESS;
        }

        $this->info(self::formatCompletionSummary(false, $updated, $count));

        return self::SUCCESS;
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
