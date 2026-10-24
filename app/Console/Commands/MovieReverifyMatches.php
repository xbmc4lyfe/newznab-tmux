<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Release;
use App\Services\MovieService;
use App\Support\ReleaseSearchIndexSync;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Throwable;

class MovieReverifyMatches extends Command
{
    private const FFI_CDEF = <<<'CDEF'
    int32_t nntmux_movie_reverify_normalize_limit(
        uint32_t kind,
        int64_t int_value,
        const char *str_ptr,
        size_t str_len,
        int64_t *out_limit
    );
    ssize_t nntmux_movie_reverify_format_mismatch_line(
        int64_t release_id,
        const char *search_name_ptr,
        size_t search_name_len,
        const char *imdb_ptr,
        size_t imdb_len,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_movie_reverify_format_summary(
        int64_t checked,
        int64_t skipped,
        int64_t mismatched,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_movie_reverify_format_outcome(
        uint32_t dry_run,
        int64_t mismatched,
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

    protected $signature = 'movie:reverify-matches
                            {--dry-run : Only report mismatches without changing anything}
                            {--limit= : Maximum number of releases to check}';

    protected $description = 'Re-verify movie release IMDb matches against the title/year parsed from their searchname';

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

    public function handle(MovieService $movieService): int
    {
        $movieService->echooutput = false;

        $dryRun = (bool) $this->option('dry-run');
        $limit = self::normalizePositiveLimit($this->option('limit'));

        $query = Release::query()
            ->select(['id', 'searchname', 'imdbid'])
            ->whereBetween('categories_id', [Category::MOVIE_ROOT, Category::MOVIE_OTHER])
            ->whereNotNull('imdbid')
            ->where('imdbid', '<>', '')
            ->whereNotIn('imdbid', imdb_id_pending_values());

        $checked = 0;
        $skipped = 0;
        $mismatched = [];

        $process = function ($releases) use (&$checked, &$skipped, &$mismatched, $movieService, $limit): bool {
            foreach ($releases as $release) {
                if ($limit !== null && $checked >= $limit) {
                    return false;
                }

                $checked++;
                $result = $movieService->verifyReleaseMovieMatch($release->searchname, (string) $release->imdbid);

                if ($result === null) {
                    $skipped++;

                    continue;
                }

                if ($result === false) {
                    $mismatched[] = (int) $release->id;
                    $this->warn(self::formatMismatchLine((int) $release->id, (string) $release->searchname, (string) $release->imdbid));
                }
            }

            return true;
        };

        if ($limit !== null) {
            $process($query->limit($limit)->get());
        } else {
            $query->chunkById(500, $process);
        }

        $this->info(self::formatSummaryMessage($checked, $skipped, count($mismatched)));

        if ($dryRun) {
            $outcome = self::formatOutcomeMessage(true, count($mismatched));
            if ($outcome !== null) {
                $this->info($outcome);
            }

            return self::SUCCESS;
        }

        foreach (array_chunk($mismatched, 100) as $chunk) {
            Release::query()->whereIn('id', $chunk)->update(['imdbid' => null, 'movieinfo_id' => null]);
            ReleaseSearchIndexSync::forIds($chunk);
        }

        $outcome = self::formatOutcomeMessage(false, count($mismatched));
        if ($outcome !== null) {
            $this->info($outcome);
        }

        return self::SUCCESS;
    }

    /**
     * The `--limit` option as a positive int, or `null` for no limit.
     */
    public static function normalizePositiveLimit(mixed $limitOpt): ?int
    {
        if (self::$useRustFfi) {
            return self::normalizePositiveLimitNative($limitOpt);
        }

        return self::normalizePositiveLimitPhp($limitOpt);
    }

    public static function normalizePositiveLimitPhp(mixed $limitOpt): ?int
    {
        return $limitOpt !== null && is_numeric($limitOpt) && (int) $limitOpt > 0 ? (int) $limitOpt : null;
    }

    public static function normalizePositiveLimitNative(mixed $limitOpt): ?int
    {
        $kind = match (true) {
            $limitOpt === null => 0,
            is_int($limitOpt) => 1,
            is_string($limitOpt) => 2,
            default => null,
        };
        $ffi = $kind !== null ? self::ffi() : null;
        if ($ffi === null) {
            return self::normalizePositiveLimitPhp($limitOpt);
        }

        $str = is_string($limitOpt) ? $limitOpt : '';

        try {
            $out = $ffi->new('int64_t[1]');
            $rc = (int) self::callFfi(
                $ffi,
                'nntmux_movie_reverify_normalize_limit',
                $kind,
                is_int($limitOpt) ? $limitOpt : 0,
                $str,
                strlen($str),
                $out,
            );

            return match ($rc) {
                1 => (int) $out[0],
                0 => null,
                default => self::normalizePositiveLimitPhp($limitOpt),
            };
        } catch (Throwable) {
            return self::normalizePositiveLimitPhp($limitOpt);
        }
    }

    public static function formatMismatchLine(int $releaseId, string $searchName, string $imdbId): string
    {
        if (self::$useRustFfi) {
            return self::formatMismatchLineNative($releaseId, $searchName, $imdbId);
        }

        return self::formatMismatchLinePhp($releaseId, $searchName, $imdbId);
    }

    public static function formatMismatchLinePhp(int $releaseId, string $searchName, string $imdbId): string
    {
        return "Mismatch: release {$releaseId} ({$searchName}) matched to tt{$imdbId}";
    }

    public static function formatMismatchLineNative(int $releaseId, string $searchName, string $imdbId): string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::formatMismatchLinePhp($releaseId, $searchName, $imdbId);
        }

        try {
            return self::callBufferFfi(
                $ffi,
                strlen($searchName) + strlen($imdbId) + 64,
                'nntmux_movie_reverify_format_mismatch_line',
                $releaseId,
                $searchName,
                strlen($searchName),
                $imdbId,
                strlen($imdbId),
            ) ?? self::formatMismatchLinePhp($releaseId, $searchName, $imdbId);
        } catch (Throwable) {
            return self::formatMismatchLinePhp($releaseId, $searchName, $imdbId);
        }
    }

    public static function formatSummaryMessage(int $checked, int $skipped, int $mismatched): string
    {
        if (self::$useRustFfi) {
            return self::formatSummaryMessageNative($checked, $skipped, $mismatched);
        }

        return self::formatSummaryMessagePhp($checked, $skipped, $mismatched);
    }

    public static function formatSummaryMessagePhp(int $checked, int $skipped, int $mismatched): string
    {
        return "Checked {$checked} movie releases, skipped {$skipped} unverifiable, found {$mismatched} mismatches.";
    }

    public static function formatSummaryMessageNative(int $checked, int $skipped, int $mismatched): string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::formatSummaryMessagePhp($checked, $skipped, $mismatched);
        }

        try {
            return self::callBufferFfi(
                $ffi,
                128,
                'nntmux_movie_reverify_format_summary',
                $checked,
                $skipped,
                $mismatched,
            ) ?? self::formatSummaryMessagePhp($checked, $skipped, $mismatched);
        } catch (Throwable) {
            return self::formatSummaryMessagePhp($checked, $skipped, $mismatched);
        }
    }

    /**
     * The closing message after the summary, or `null` when nothing mismatched.
     */
    public static function formatOutcomeMessage(bool $dryRun, int $mismatched): ?string
    {
        if (self::$useRustFfi) {
            return self::formatOutcomeMessageNative($dryRun, $mismatched);
        }

        return self::formatOutcomeMessagePhp($dryRun, $mismatched);
    }

    public static function formatOutcomeMessagePhp(bool $dryRun, int $mismatched): ?string
    {
        if ($mismatched <= 0) {
            return null;
        }

        return $dryRun ? 'Dry run: no changes made.' : "Reset imdbid/movieinfo_id for {$mismatched} mismatched releases.";
    }

    public static function formatOutcomeMessageNative(bool $dryRun, int $mismatched): ?string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::formatOutcomeMessagePhp($dryRun, $mismatched);
        }

        try {
            $out = self::callBufferFfi(
                $ffi,
                128,
                'nntmux_movie_reverify_format_outcome',
                $dryRun ? 1 : 0,
                $mismatched,
            );
            if ($out === null) {
                return self::formatOutcomeMessagePhp($dryRun, $mismatched);
            }

            return $out !== '' ? $out : null;
        } catch (Throwable) {
            return self::formatOutcomeMessagePhp($dryRun, $mismatched);
        }
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
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
