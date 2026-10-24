<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Facades\Search;
use App\Models\Category;
use App\Models\Release;
use App\Services\BookProcessingCandidateQuery;
use App\Services\NameFixing\Extractors\ObfuscatedSubjectExtractor;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Throwable;

class RequeueFailedBooks extends Command
{
    private const FFI_CDEF = <<<'CDEF'
    int32_t nntmux_requeue_failed_books_evaluate_release(
        uint32_t has_bookinfo_id,
        int64_t bookinfo_id,
        const char *current_searchname_ptr,
        size_t current_searchname_len,
        uint32_t has_normalized,
        const char *normalized_ptr,
        size_t normalized_len,
        int64_t *out_flags
    );
    ssize_t nntmux_requeue_failed_books_format_dry_run_rename(
        int64_t release_id,
        const char *old_name_ptr,
        size_t old_name_len,
        const char *new_name_ptr,
        size_t new_name_len,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_requeue_failed_books_format_summary(
        uint32_t dry_run,
        int64_t total,
        int64_t queued,
        int64_t renamed,
        int64_t skipped,
        char *out_buf,
        size_t out_cap
    );
    CDEF;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustAdminAnidbBookMediaCommandsParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    protected $signature = 'books:requeue-failed
                            {--dry-run : Preview affected releases without writing changes}
                            {--limit=0 : Max releases to inspect (0 = no limit)}
                            {--only-obfuscated : Only requeue rows with obfuscated subject that can be normalized}
                            {--normalize-obfuscated-all : Normalize obfuscated names across all book/audiobook releases, not just failed rows}';

    protected $description = 'Requeue unmatched or retry-exhausted books (bookinfo_id = -2 or -3) and normalize obfuscated search names.';

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

    public function handle(ObfuscatedSubjectExtractor $extractor): int
    {
        $limit = max(0, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $onlyObfuscated = (bool) $this->option('only-obfuscated');
        $normalizeObfuscatedAll = (bool) $this->option('normalize-obfuscated-all');

        $query = Release::query()
            ->select(['id', 'name', 'searchname', 'categories_id', 'bookinfo_id'])
            ->where(function ($builder): void {
                $builder->whereBetween('categories_id', [Category::BOOKS_ROOT, Category::BOOKS_UNKNOWN])
                    ->orWhere('categories_id', Category::MUSIC_AUDIOBOOK);
            })
            ->orderBy('id');

        if ($normalizeObfuscatedAll) {
            $query->where(function ($builder): void {
                $builder
                    ->where('searchname', 'like', 'N:/NZB%')
                    ->orWhere('searchname', 'like', 'N_NZB_%')
                    ->orWhere('name', 'like', 'N:/NZB%')
                    ->orWhere('name', 'like', 'N_NZB_%');
            });
        } else {
            $query->whereIn('bookinfo_id', [-2, BookProcessingCandidateQuery::EXHAUSTED]);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $releases = $query->get();
        $total = $releases->count();

        if ($total === 0) {
            $this->info('No failed book releases found (bookinfo_id = -2 or -3).');

            return self::SUCCESS;
        }

        $queued = 0;
        $renamed = 0;
        $skipped = 0;

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($releases as $release) {
            $bar->advance();

            $normalized = $extractor->extract((string) $release->searchname)
                ?? $extractor->extract((string) $release->name);

            if ($onlyObfuscated && $normalized === null) {
                $skipped++;

                continue;
            }

            $updates = self::buildReleaseUpdates(
                $release->bookinfo_id !== null ? (int) $release->bookinfo_id : null,
                (string) $release->searchname,
                $normalized,
            );

            if ($updates === []) {
                $skipped++;

                continue;
            }

            if ($dryRun) {
                if (isset($updates['searchname'])) {
                    $this->newLine();
                    $this->line(self::formatDryRunRenameLine(
                        (int) $release->id,
                        (string) $release->searchname,
                        (string) $updates['searchname']
                    ));
                }

                $queued++;
                if (isset($updates['searchname'])) {
                    $renamed++;
                }

                continue;
            }

            Release::query()->where('id', $release->id)->update($updates);
            Search::updateRelease((int) $release->id);

            $queued++;
            if (isset($updates['searchname'])) {
                $renamed++;
            }
        }

        $bar->finish();
        $this->newLine(2);

        if ($dryRun) {
            $this->info(self::formatSummaryMessage(true, $total, $queued, $renamed, $skipped));

            return self::SUCCESS;
        }

        $this->info(self::formatSummaryMessage(false, $total, $queued, $renamed, $skipped));
        $this->line('Next step: run your books postprocess (e.g. `php artisan update:postprocess book`).');

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildReleaseUpdates(
        ?int $bookinfoId,
        string $currentSearchName,
        ?string $normalizedSearchName,
        mixed $normalizedAt = null,
    ): array {
        $timestamp = $normalizedAt ?? ($normalizedSearchName !== null && $normalizedSearchName !== $currentSearchName ? now() : null);

        if (self::$useRustFfi) {
            return self::buildReleaseUpdatesNative(
                $bookinfoId,
                $currentSearchName,
                $normalizedSearchName,
                $timestamp,
            );
        }

        return self::buildReleaseUpdatesPhp(
            $bookinfoId,
            $currentSearchName,
            $normalizedSearchName,
            $timestamp,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildReleaseUpdatesPhp(
        ?int $bookinfoId,
        string $currentSearchName,
        ?string $normalizedSearchName,
        mixed $normalizedAt = null,
    ): array {
        $updates = [];
        $shouldRequeue = in_array((int) $bookinfoId, [-2, BookProcessingCandidateQuery::EXHAUSTED], true);
        if ($shouldRequeue) {
            $updates['bookinfo_id'] = null;
            $updates['book_lookup_attempts'] = 0;
            $updates['book_lookup_retry_at'] = null;
            $updates['book_name_normalized_at'] = null;
        }
        if ($normalizedSearchName !== null && $normalizedSearchName !== $currentSearchName) {
            $updates['searchname'] = $normalizedSearchName;
            $updates['isrenamed'] = 1;
            $updates['book_name_normalized_at'] = $normalizedAt ?? now();
        }

        return $updates;
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildReleaseUpdatesNative(
        ?int $bookinfoId,
        string $currentSearchName,
        ?string $normalizedSearchName,
        mixed $normalizedAt = null,
    ): array {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::buildReleaseUpdatesPhp(
                $bookinfoId,
                $currentSearchName,
                $normalizedSearchName,
                $normalizedAt,
            );
        }

        $hasBookinfoId = $bookinfoId !== null ? 1 : 0;
        $hasNormalized = $normalizedSearchName !== null ? 1 : 0;
        $normalizedStr = $normalizedSearchName ?? '';

        try {
            $flags = $ffi->new('int64_t[2]');
            $rc = (int) self::callFfi(
                $ffi,
                'nntmux_requeue_failed_books_evaluate_release',
                $hasBookinfoId,
                (int) $bookinfoId,
                $currentSearchName,
                strlen($currentSearchName),
                $hasNormalized,
                $normalizedStr,
                strlen($normalizedStr),
                $flags
            );
            if ($rc !== 0) {
                return self::buildReleaseUpdatesPhp(
                    $bookinfoId,
                    $currentSearchName,
                    $normalizedSearchName,
                    $normalizedAt,
                );
            }

            $updates = [];
            if (((int) $flags[0]) === 1) {
                $updates['bookinfo_id'] = null;
                $updates['book_lookup_attempts'] = 0;
                $updates['book_lookup_retry_at'] = null;
                $updates['book_name_normalized_at'] = null;
            }
            if (((int) $flags[1]) === 1) {
                $updates['searchname'] = $normalizedSearchName;
                $updates['isrenamed'] = 1;
                $updates['book_name_normalized_at'] = $normalizedAt ?? now();
            }

            return $updates;
        } catch (Throwable) {
            return self::buildReleaseUpdatesPhp(
                $bookinfoId,
                $currentSearchName,
                $normalizedSearchName,
                $normalizedAt,
            );
        }
    }

    public static function formatDryRunRenameLine(
        int $releaseId,
        string $oldSearchName,
        string $newSearchName,
    ): string {
        if (self::$useRustFfi) {
            return self::formatDryRunRenameLineNative($releaseId, $oldSearchName, $newSearchName);
        }

        return self::formatDryRunRenameLinePhp($releaseId, $oldSearchName, $newSearchName);
    }

    public static function formatDryRunRenameLinePhp(
        int $releaseId,
        string $oldSearchName,
        string $newSearchName,
    ): string {
        return sprintf(
            '[dry-run] #%d searchname: "%s" -> "%s"',
            $releaseId,
            $oldSearchName,
            $newSearchName
        );
    }

    public static function formatDryRunRenameLineNative(
        int $releaseId,
        string $oldSearchName,
        string $newSearchName,
    ): string {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::formatDryRunRenameLinePhp($releaseId, $oldSearchName, $newSearchName);
        }

        $approxLen = strlen($oldSearchName) + strlen($newSearchName) + 64;

        try {
            $cap = 0;
            $buf = RustPipelineLibrary::obtainScratch($ffi, max(128, $approxLen), $cap);
            if ($buf === null) {
                return self::formatDryRunRenameLinePhp($releaseId, $oldSearchName, $newSearchName);
            }

            $rc = (int) self::callFfi(
                $ffi,
                'nntmux_requeue_failed_books_format_dry_run_rename',
                $releaseId,
                $oldSearchName,
                strlen($oldSearchName),
                $newSearchName,
                strlen($newSearchName),
                $buf,
                $cap
            );
            if ($rc < 0) {
                return self::formatDryRunRenameLinePhp($releaseId, $oldSearchName, $newSearchName);
            }

            return $rc > 0 ? FFI::string($buf, $rc) : '';
        } catch (Throwable) {
            return self::formatDryRunRenameLinePhp($releaseId, $oldSearchName, $newSearchName);
        }
    }

    public static function formatSummaryMessage(
        bool $dryRun,
        int $total,
        int $queued,
        int $renamed,
        int $skipped,
    ): string {
        if (self::$useRustFfi) {
            return self::formatSummaryMessageNative($dryRun, $total, $queued, $renamed, $skipped);
        }

        return self::formatSummaryMessagePhp($dryRun, $total, $queued, $renamed, $skipped);
    }

    public static function formatSummaryMessagePhp(
        bool $dryRun,
        int $total,
        int $queued,
        int $renamed,
        int $skipped,
    ): string {
        if ($dryRun) {
            return sprintf(
                'Dry run complete. Inspected: %d, would apply updates: %d, would normalize names: %d, skipped: %d.',
                $total,
                $queued,
                $renamed,
                $skipped
            );
        }

        return sprintf(
            'Requeued %d failed book release(s). Normalized %d searchname value(s). Skipped: %d.',
            $queued,
            $renamed,
            $skipped
        );
    }

    public static function formatSummaryMessageNative(
        bool $dryRun,
        int $total,
        int $queued,
        int $renamed,
        int $skipped,
    ): string {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::formatSummaryMessagePhp($dryRun, $total, $queued, $renamed, $skipped);
        }

        try {
            $cap = 0;
            $buf = RustPipelineLibrary::obtainScratch($ffi, 256, $cap);
            if ($buf === null) {
                return self::formatSummaryMessagePhp($dryRun, $total, $queued, $renamed, $skipped);
            }

            $rc = (int) self::callFfi(
                $ffi,
                'nntmux_requeue_failed_books_format_summary',
                $dryRun ? 1 : 0,
                $total,
                $queued,
                $renamed,
                $skipped,
                $buf,
                $cap
            );
            if ($rc < 0) {
                return self::formatSummaryMessagePhp($dryRun, $total, $queued, $renamed, $skipped);
            }

            return $rc > 0 ? FFI::string($buf, $rc) : '';
        } catch (Throwable) {
            return self::formatSummaryMessagePhp($dryRun, $total, $queued, $renamed, $skipped);
        }
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
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
