<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Facades\Search;
use App\Models\Category;
use App\Models\Release;
use App\Services\Categorization\CategorizationService;
use App\Services\Releases\ReleaseBrowseService;
use App\Services\Search\Contracts\BulkReleaseIndexUpdater;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class RecategorizeOnlyFans extends Command
{
    public const string FFI_CDEF = <<<'CDEF'
        int32_t nntmux_cat_pipe_cmd_onlyfans_eligible(int64_t category_id, const char *searchname_ptr, size_t searchname_len);
        ssize_t nntmux_cat_pipe_cmd_onlyfans_preview(int64_t id, const char *searchname_ptr, size_t searchname_len, const char *old_title_ptr, size_t old_title_len, int64_t old_id, const char *new_title_ptr, size_t new_title_len, int64_t new_id, char *out_buf, size_t out_cap);
        ssize_t nntmux_cat_pipe_cmd_onlyfans_summary(uint8_t apply, int64_t inspected, int64_t eligible, int64_t updated, int64_t refresh_confirmed, int64_t unconfirmed, int64_t skipped, int64_t failed, char *out_buf, size_t out_cap);
        CDEF;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    protected $signature = 'releases:recategorize-onlyfans
                            {--apply : Apply the category corrections (otherwise preview only)}';

    protected $description = 'Preview or repair OF. releases in XXX categories that belong in OnlyFans';

    public function handle(CategorizationService $categorizationService): int
    {
        $categories = Category::query()
            ->where('root_categories_id', Category::XXX_ROOT)
            ->pluck('title', 'id');

        if (! $categories->has(Category::XXX_ONLYFANS)) {
            $this->error('XXX > OnlyFans category (6047) is missing. No releases were changed.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $inspected = $eligible = $updated = $refreshConfirmed = $unconfirmed = $skipped = $failed = 0;

        if (! $apply) {
            $this->info('Preview only. Run with --apply to save these category corrections.');
        }

        Release::query()
            ->select(['id', 'searchname', 'categories_id', 'groups_id', 'fromname'])
            ->whereIn('categories_id', $categories->keys()->all())
            ->where('categories_id', '!=', Category::XXX_ONLYFANS)
            ->whereRaw('LOWER(searchname) LIKE ?', ['of.%'])
            ->chunkById(250, function (Collection $releases) use (
                $categorizationService, $categories, $apply,
                &$inspected, &$eligible, &$updated, &$refreshConfirmed, &$unconfirmed, &$skipped, &$failed
            ): void {
                foreach ($releases as $release) {
                    $inspected++;

                    try {
                        $result = $categorizationService->determineCategory(
                            $release->groups_id,
                            $release->searchname,
                            $release->fromname ?? '',
                        );

                        if (! self::isEligible((int) $result['categories_id'], (string) $release->searchname)) {
                            $skipped++;

                            continue;
                        }

                        $eligible++;

                        if (! $apply) {
                            $this->line(self::previewLine(
                                (int) $release->id, (string) $release->searchname,
                                self::titleOrNull($categories->get($release->categories_id)), (int) $release->categories_id,
                                self::titleOrNull($categories->get(Category::XXX_ONLYFANS)), Category::XXX_ONLYFANS,
                            ));

                            continue;
                        }

                        if (! $this->updateCategory($release)) {
                            $skipped++;
                            $this->warn(sprintf('#%d changed during repair; skipped.', $release->id));

                            continue;
                        }

                        $updated++;
                    } catch (Throwable $exception) {
                        $failed++;
                        $this->error(sprintf('#%d category correction failed: %s', $release->id, $exception->getMessage()));

                        continue;
                    }

                    try {
                        $driver = Search::driver();
                        if ($driver instanceof BulkReleaseIndexUpdater) {
                            if ($driver->updateReleases([(int) $release->id]) !== []) {
                                throw new RuntimeException('The driver could not refresh this release');
                            }
                            $refreshConfirmed++;
                        } else {
                            Search::updateRelease((int) $release->id);
                            $unconfirmed++;
                            $failed++;
                            $this->warn(sprintf(
                                '#%d category saved; search refresh requested but unconfirmed: the driver cannot acknowledge completion. Reindex this release before considering its repair complete.',
                                $release->id,
                            ));
                        }
                    } catch (Throwable $exception) {
                        $failed++;
                        $this->error(sprintf(
                            '#%d category saved, but search refresh failed: %s. Reindex this release before considering its repair complete.',
                            $release->id, $exception->getMessage(),
                        ));
                    }
                }
            });

        if ($updated > 0) {
            ReleaseBrowseService::bumpCacheVersion();
            Release::clearAdminReleasesRangeCache();
        }

        foreach (self::summaryLines($apply, $inspected, $eligible, $updated, $refreshConfirmed, $unconfirmed, $skipped, $failed) as $line) {
            $this->info($line);
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function updateCategory(Release $release): bool
    {
        return DB::transaction(function () use ($release): bool {
            $current = Release::query()->lockForUpdate()->find($release->id, ['searchname', 'categories_id']);

            if ($current === null || $current->searchname !== $release->searchname ||
                $current->categories_id !== $release->categories_id) {
                return false;
            }

            return Release::query()->whereKey($release->id)->update(['categories_id' => Category::XXX_ONLYFANS]) === 1;
        });
    }

    /**
     * Overrides the PHP default for this command's helpers; `null` restores the PHP default.
     */
    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi ?? false;
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
     * The categorizer chose `XXX > OnlyFans` for a searchname with the selected `of.` prefix.
     */
    public static function isEligible(int $categoryId, string $searchname): bool
    {
        return self::$useRustFfi ? self::isEligibleNative($categoryId, $searchname) : self::isEligiblePhp($categoryId, $searchname);
    }

    public static function isEligiblePhp(int $categoryId, string $searchname): bool
    {
        return $categoryId === Category::XXX_ONLYFANS && str_starts_with(strtolower($searchname), 'of.');
    }

    public static function isEligibleNative(int $categoryId, string $searchname): bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::isEligiblePhp($categoryId, $searchname);
        }

        try {
            // @phpstan-ignore method.notFound
            $eligible = $ffi->nntmux_cat_pipe_cmd_onlyfans_eligible($categoryId, $searchname, \strlen($searchname));
        } catch (Throwable) {
            return self::isEligiblePhp($categoryId, $searchname);
        }

        return $eligible === 0 || $eligible === 1 ? $eligible === 1 : self::isEligiblePhp($categoryId, $searchname);
    }

    /**
     * Preview line for one eligible release; a missing category title renders as empty.
     */
    public static function previewLine(int $id, string $searchname, ?string $oldTitle, int $oldId, ?string $newTitle, int $newId): string
    {
        return self::$useRustFfi
            ? self::previewLineNative($id, $searchname, $oldTitle, $oldId, $newTitle, $newId)
            : self::previewLinePhp($id, $searchname, $oldTitle, $oldId, $newTitle, $newId);
    }

    public static function previewLinePhp(int $id, string $searchname, ?string $oldTitle, int $oldId, ?string $newTitle, int $newId): string
    {
        return sprintf('#%d %s: XXX > %s (%d) -> XXX > %s (%d)', $id, $searchname, $oldTitle, $oldId, $newTitle, $newId);
    }

    public static function previewLineNative(int $id, string $searchname, ?string $oldTitle, int $oldId, ?string $newTitle, int $newId): string
    {
        $ffi = self::ffi();
        if ($ffi === null || PHP_INT_SIZE !== 8) {
            return self::previewLinePhp($id, $searchname, $oldTitle, $oldId, $newTitle, $newId);
        }

        $old = $oldTitle ?? '';
        $new = $newTitle ?? '';

        return self::callForString(
            $ffi,
            'nntmux_cat_pipe_cmd_onlyfans_preview',
            \strlen($searchname) + \strlen($old) + \strlen($new) + 96,
            $id, $searchname, \strlen($searchname), $old, \strlen($old), $oldId, $new, \strlen($new), $newId,
        ) ?? self::previewLinePhp($id, $searchname, $oldTitle, $oldId, $newTitle, $newId);
    }

    /**
     * The two summary lines printed after a preview or repair run.
     *
     * @return list<string>
     */
    public static function summaryLines(bool $apply, int $inspected, int $eligible, int $updated, int $refreshConfirmed, int $unconfirmed, int $skipped, int $failed): array
    {
        return self::$useRustFfi
            ? self::summaryLinesNative($apply, $inspected, $eligible, $updated, $refreshConfirmed, $unconfirmed, $skipped, $failed)
            : self::summaryLinesPhp($apply, $inspected, $eligible, $updated, $refreshConfirmed, $unconfirmed, $skipped, $failed);
    }

    /**
     * @return list<string>
     */
    public static function summaryLinesPhp(bool $apply, int $inspected, int $eligible, int $updated, int $refreshConfirmed, int $unconfirmed, int $skipped, int $failed): array
    {
        return [
            sprintf('Inspected: %d; Eligible: %d; Skipped: %d; Failed: %d.', $inspected, $eligible, $skipped, $failed),
            $apply
                ? sprintf('Database categories updated: %d; Search refreshes confirmed: %d; Unconfirmed: %d.', $updated, $refreshConfirmed, $unconfirmed)
                : sprintf('Would update: %d. No releases were changed.', $eligible),
        ];
    }

    /**
     * @return list<string>
     */
    public static function summaryLinesNative(bool $apply, int $inspected, int $eligible, int $updated, int $refreshConfirmed, int $unconfirmed, int $skipped, int $failed): array
    {
        $ffi = self::ffi();
        if ($ffi === null || PHP_INT_SIZE !== 8) {
            return self::summaryLinesPhp($apply, $inspected, $eligible, $updated, $refreshConfirmed, $unconfirmed, $skipped, $failed);
        }

        $joined = self::callForString(
            $ffi,
            'nntmux_cat_pipe_cmd_onlyfans_summary',
            256,
            $apply ? 1 : 0, $inspected, $eligible, $updated, $refreshConfirmed, $unconfirmed, $skipped, $failed,
        );
        $lines = $joined === null ? [] : explode("\n", $joined);

        return \count($lines) === 2
            ? $lines
            : self::summaryLinesPhp($apply, $inspected, $eligible, $updated, $refreshConfirmed, $unconfirmed, $skipped, $failed);
    }

    private static function titleOrNull(mixed $title): ?string
    {
        return $title === null ? null : (string) $title;
    }

    /**
     * Calls a byte-output export with a scratch buffer, growing it once when Rust reports a larger requirement.
     */
    private static function callForString(FFI $ffi, string $symbol, int $estimate, mixed ...$args): ?string
    {
        try {
            $capacity = 0;
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, max(1, $estimate), $capacity);
            if ($outBuf === null) {
                return null;
            }
            $written = $ffi->$symbol(...[...$args, $outBuf, $capacity]);
            if (\is_int($written) && $written < -1 && -$written > $capacity) {
                $outBuf = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($outBuf === null) {
                    return null;
                }
                $written = $ffi->$symbol(...[...$args, $outBuf, $capacity]);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return $written === 0 ? '' : FFI::string($outBuf, $written);
        } catch (Throwable) {
            return null;
        }
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        try {
            $ffi = RustPipelineLibrary::resolveDomain('w29-categorization-pipes-commands', self::FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        } catch (Throwable) {
            $ffi = null;
        }
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
