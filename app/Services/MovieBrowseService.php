<?php

declare(strict_types=1);

namespace App\Services;

use App\Facades\Search;
use App\Models\Category;
use App\Models\MovieInfo;
use App\Services\Releases\ReleaseBrowseService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Service class for movie browsing operations (frontend).
 */
class MovieBrowseService
{
    protected string $showPasswords;

    // Scalar FFI overhead failed the 1.5x gates; PHP remains the production default.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    /**
     * Production default for planMovieBrowseFilter() stays false: the paired benchmark in
     * tests/Unit/RustPredbSourcesHttpNswdb3dsdbRateParityTest.php is below 1.5x.
     */
    public const bool MOVIE_BROWSE_FILTER_RUST_FFI_DEFAULT = false;

    /** Separate from the getMovieOrder() toggle above. */
    private const string MOVIE_BROWSE_FILTER_FFI_CDEF = <<<'C'
        ssize_t nntmux_w384_movie_browse_filter_plan(
            const char *field,
            size_t field_len,
            const char *value,
            size_t value_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /** Same request keys, in the same order, as getBrowseBy(). */
    private const array MOVIE_BROWSE_FILTER_FIELDS = ['title', 'director', 'actors', 'genre', 'rating', 'year', 'imdb'];

    private const string MOVIE_BROWSE_FILTER_FIELD_SEPARATOR = "\x1F";

    private static ?bool $movieBrowseFilterUseRustFfi = null;

    private static FFI|false|null $movieBrowseFilterFfi = null;

    public static function useMovieBrowseFilterRustFfi(?bool $enabled = true): void
    {
        self::$movieBrowseFilterUseRustFfi = $enabled;
    }

    public static function usesMovieBrowseFilterRustFfi(): bool
    {
        return self::$movieBrowseFilterUseRustFfi ?? self::MOVIE_BROWSE_FILTER_RUST_FFI_DEFAULT;
    }

    public static function resetMovieBrowseFilterRustFfi(): void
    {
        self::$movieBrowseFilterUseRustFfi = null;
        self::$movieBrowseFilterFfi = null;
    }

    public function __construct()
    {
        $this->showPasswords = app(ReleaseBrowseService::class)->showPasswords();
    }

    /**
     * Get movie releases with covers for movie browse page.
     *
     * Uses three separate queries instead of GROUP_CONCAT:
     * 1. COUNT query for total results (replaces SQL_CALC_FOUND_ROWS)
     * 2. Paginated movie list with only needed columns
     * 3. Top 2 releases per movie using UNION ALL with LIMIT 2 per imdbid
     *
     * @param  array<string, mixed>  $cat
     * @param  array<string, mixed>  $excludedCats
     */
    public function getMovieRange(int $page, array $cat, int $start, int $num, string $orderBy, int $maxAge = -1, array $excludedCats = []): mixed
    {
        $page = max(1, $page);
        $start = max(0, $start);

        // Build effective category filter: merge inclusion and exclusion into a single IN clause
        // to avoid redundant IN + NOT IN predicates and help the optimizer
        $catArray = [];
        if (count($cat) > 0 && $cat[0] !== -1) { // @phpstan-ignore offsetAccess.notFound
            $catArray = (array) (Category::getCategorySearch($cat, null, true) ?? []);
        }

        if (! empty($catArray) && ! empty($excludedCats)) {
            $catArray = array_values(array_diff($catArray, array_map('intval', $excludedCats)));
        }

        if (! empty($catArray)) {
            $catFilter = ' AND r.categories_id IN ('.implode(',', $catArray).') ';
            $whereExcluded = '';
        } else {
            $catFilter = '';
            $whereExcluded = count($excludedCats) > 0 ? ' AND r.categories_id NOT IN ('.implode(',', $excludedCats).')' : '';
        }

        $order = $this->getMovieOrder($orderBy);
        $expiresAt = now()->addMinutes(config('nntmux.cache_expiry_medium'));

        $whereAge = $maxAge > 0 ? 'AND r.postdate > NOW() - INTERVAL '.$maxAge.' DAY ' : '';

        $skipBrowseLikeFields = [];
        $indexImdbClause = '';
        if (Search::isAvailable()) {
            $fieldTerms = [];
            foreach (['title', 'director', 'actors', 'genre'] as $field) {
                if (request()->has($field)) {
                    $value = request()->input($field);
                    if (is_array($value)) {
                        continue;
                    }
                    $value = stripslashes((string) $value);
                    if ($value !== '') {
                        $fieldTerms[$field] = $value;
                    }
                }
            }
            if ($fieldTerms !== []) {
                $found = Search::searchMoviesByFields($fieldTerms, 8000);
                if ($found['imdbids'] === []) {
                    return collect();
                }
                $quoted = array_map(static fn (string $id): string => escapeString($id), $found['imdbids']);
                $indexImdbClause = ' AND m.imdbid IN ('.implode(',', $quoted).') ';
                $skipBrowseLikeFields = ['title', 'director', 'actors', 'genre'];
            }
        }

        $browseBy = $this->getBrowseBy($skipBrowseLikeFields);

        $baseWhere = "m.title != '' AND m.imdbid IS NOT NULL AND m.imdbid != '' "
            ."AND r.passwordstatus {$this->showPasswords} "
            .$browseBy.' '
            .$indexImdbClause
            .$catFilter
            .$whereAge
            .$whereExcluded;

        // Build a cache key from all the query parameters
        $cacheKey = md5('movie_range_v2_'.$baseWhere.$order[0].$order[1].$start.$num.$page);

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Step 1: Count total distinct movies matching filters.
        // Cached separately with a longer TTL (30 min) since the total changes slowly
        // and this query scans all 84K+ movieinfo rows joined to releases (~0.5s).
        $countCacheKey = md5('movie_count_'.$baseWhere);
        $totalCount = Cache::get($countCacheKey);

        if ($totalCount === null) {
            $countSql = 'SELECT COUNT(DISTINCT m.imdbid) AS total '
                .'FROM movieinfo m '
                .'INNER JOIN releases r ON r.imdbid = m.imdbid '
                .'WHERE '.$baseWhere;

            $totalResult = DB::select($countSql);
            $totalCount = $totalResult[0]->total ?? 0;

            Cache::put($countCacheKey, $totalCount, now()->addMinutes(30));
        }

        if ($totalCount === 0) {
            return collect();
        }

        // Step 2: Get paginated movie list using two-phase subquery.
        // Inner query aggregates by just imdbid (small temp table, fast filesort on ~53K
        // narrow rows instead of 53K wide rows with TEXT columns like plot/genre/actors).
        // Outer query joins back to movieinfo for full details on only the top N movies.
        $isAggregateOrder = ($order[0] === 'MAX(r.postdate)');

        if ($isAggregateOrder) {
            $innerOrderBy = 'latest_postdate';
            $innerExtraGroupBy = '';
            $outerOrderBy = 'stats.latest_postdate';
        } else {
            // orderField is like 'm.title', 'm.year', 'm.rating'
            $innerOrderBy = $order[0];
            $innerExtraGroupBy = ', '.$order[0];
            $outerOrderBy = $order[0];
        }

        $moviesSql = 'SELECT m.imdbid, m.tmdbid, m.traktid, m.title, m.year, m.rating, '
            .'m.plot, m.genre, m.director, m.actors, m.cover, '
            .'stats.latest_postdate, stats.total_releases '
            .'FROM ('
            .'SELECT m.imdbid, MAX(r.postdate) AS latest_postdate, COUNT(r.id) AS total_releases '
            .'FROM movieinfo m '
            .'INNER JOIN releases r ON r.imdbid = m.imdbid '
            .'WHERE '.$baseWhere.' '
            .'GROUP BY m.imdbid'.$innerExtraGroupBy.' '
            ."ORDER BY {$innerOrderBy} {$order[1]} "
            ."LIMIT {$num} OFFSET {$start}"
            .') stats '
            .'INNER JOIN movieinfo m ON m.imdbid = stats.imdbid '
            ."ORDER BY {$outerOrderBy} {$order[1]}";

        $movies = MovieInfo::fromQuery($moviesSql);

        if ($movies->isEmpty()) {
            return collect();
        }

        // Build list of movie IMDB IDs for release query
        $movieImdbIds = $movies->pluck('imdbid')->toArray();

        // Step 3: Get top 2 releases per movie using UNION ALL with LIMIT 2 per imdbid.
        $unionParts = [];
        foreach ($movieImdbIds as $id) {
            $quotedId = escapeString((string) $id);
            $unionParts[] = '(SELECT r.id, r.imdbid, r.guid, r.searchname, '
                .'r.size, r.postdate, r.adddate, r.haspreview, r.jpgstatus, r.nfostatus, re.releases_id AS reid '
                .'FROM releases r '
                .'LEFT JOIN video_data re ON re.releases_id = r.id '
                .'WHERE r.imdbid = '.$quotedId.' '
                ."AND r.passwordstatus {$this->showPasswords} "
                .$catFilter
                .$whereExcluded
                .$whereAge
                .'ORDER BY r.postdate DESC LIMIT 2)';
        }

        $releasesSql = implode(' UNION ALL ', $unionParts);
        $releases = DB::select($releasesSql);

        // Group by imdbid
        $releasesByMovie = [];
        foreach ($releases as $release) {
            $releasesByMovie[$release->imdbid][] = $release;
        }

        // Attach releases to each movie
        foreach ($movies as $movie) {
            $movie->releases = $releasesByMovie[$movie->imdbid] ?? []; // @phpstan-ignore assign.propertyReadOnly
        }

        // Set total count on first item (matches existing pattern used by controllers)
        if ($movies->isNotEmpty()) {
            $movies[0]->_totalcount = $totalCount; // @phpstan-ignore property.notFound
        }

        Cache::put($cacheKey, $movies, $expiresAt);

        return $movies;
    }

    /**
     * Get all releases for a single movie by IMDB ID.
     *
     * @param  array<string, mixed>  $excludedCats
     * @return array<int, object>
     */
    public function getMovieReleases(string $imdbid, array $excludedCats = []): array
    {
        $whereExcluded = count($excludedCats) > 0 ? ' AND r.categories_id NOT IN ('.implode(',', $excludedCats).')' : '';
        $quotedId = escapeString($imdbid);

        $sql = 'SELECT r.id, r.guid, r.searchname, r.size, r.postdate, r.adddate, r.haspreview, '
            .'r.jpgstatus, r.nfostatus, re.releases_id AS reid '
            .'FROM releases r '
            .'LEFT JOIN video_data re ON re.releases_id = r.id '
            .'WHERE r.imdbid = '.$quotedId.' '
            ."AND r.passwordstatus {$this->showPasswords} "
            .$whereExcluded.' '
            .'ORDER BY r.postdate DESC';

        return DB::select($sql);
    }

    /**
     * Get the order type the user requested on the movies page.
     *
     * @return array{0: string, 1: string}
     */
    protected function getMovieOrder(string $orderBy): array
    {
        return self::$useRustFfi ? $this->getMovieOrderNative($orderBy) : $this->getMovieOrderPhp($orderBy);
    }

    /** @return array{0: string, 1: string} */
    public function getMovieOrderNative(string $orderBy): array
    {
        $result = ReleaseBrowseService::searchSqlTransformNative(5, $orderBy);

        return $result === null ? $this->getMovieOrderPhp($orderBy) : explode("\0", $result, 2);
    }

    /** @return array{0: string, 1: string} */
    public function getMovieOrderPhp(string $orderBy): array
    {
        $orderArr = explode('_', (($orderBy === '') ? 'MAX(r.postdate)' : $orderBy));
        $orderField = match ($orderArr[0]) {
            'title' => 'm.title',
            'year' => 'm.year',
            'rating' => 'm.rating',
            default => 'MAX(r.postdate)',
        };

        return [$orderField, isset($orderArr[1]) && preg_match('/^asc|desc$/i', $orderArr[1]) ? $orderArr[1] : 'desc'];
    }

    /**
     * Order types for movies page.
     *
     * @return array<int, string>
     */
    public function getMovieOrdering(): array
    {
        return ['title_asc', 'title_desc', 'year_asc', 'year_desc', 'rating_asc', 'rating_desc'];
    }

    /**
     * @param  array<int, string>  $skipFields  Request keys to ignore (e.g. already applied via search index).
     */
    protected function getBrowseBy(array $skipFields = []): string
    {
        $browseBy = ' ';
        $browseByArr = ['title', 'director', 'actors', 'genre', 'rating', 'year', 'imdb'];
        foreach ($browseByArr as $bb) {
            if ($skipFields !== [] && in_array($bb, $skipFields, true)) {
                continue;
            }
            if (request()->has($bb) && ! empty(request()->input($bb))) {
                $bbv = request()->input($bb);
                if (is_array($bbv)) {
                    continue;
                }
                $bbv = stripslashes((string) $bbv);
                if ($bb === 'year') {
                    if (preg_match('/^(19|20)\d{2}$/', $bbv)) {
                        $browseBy .= ' AND m.year = '.escapeString($bbv);
                    }

                    continue;
                }
                if ($bb === 'rating') {
                    $bbv .= '.';
                }
                if ($bb === 'imdb') {
                    $browseBy .= ' AND m.imdbid = '.escapeString($bbv);
                } else {
                    $browseBy .= ' AND m.'.$bb.' '.'LIKE '.escapeString('%'.$bbv.'%');
                }
            }
        }

        return $browseBy;
    }

    /**
     * Get IMDB genres.
     *
     * @return array<int, string>
     */
    public function getGenres(): array
    {
        return [
            'Action',
            'Adventure',
            'Animation',
            'Biography',
            'Comedy',
            'Crime',
            'Documentary',
            'Drama',
            'Family',
            'Fantasy',
            'Film-Noir',
            'Game-Show',
            'History',
            'Horror',
            'Music',
            'Musical',
            'Mystery',
            'News',
            'Reality-TV',
            'Romance',
            'Sci-Fi',
            'Sport',
            'Talk-Show',
            'Thriller',
            'War',
            'Western',
        ];
    }

    /**
     * Mirrors one getBrowseBy() request key before SQL escaping: unknown keys and empty values
     * ('' or '0') are skipped, the value is stripslashes()-ed, a year must match 19xx/20xx,
     * imdb compares m.imdbid exactly, rating appends '.', and other keys become LIKE '%value%'.
     *
     * @return array{applied: bool, operator: string, column: string, value: string}
     */
    public static function planMovieBrowseFilter(string $field, string $value): array
    {
        return self::usesMovieBrowseFilterRustFfi()
            ? self::planMovieBrowseFilterNative($field, $value)
            : self::planMovieBrowseFilterPhp($field, $value);
    }

    /**
     * @return array{applied: bool, operator: string, column: string, value: string}
     */
    private static function planMovieBrowseFilterNative(string $field, string $value): array
    {
        $output = self::callMovieBrowseFilterNative([$field, strlen($field), $value, strlen($value)], strlen($value) + 32);
        // The value is the last field and may itself contain the separator.
        $fields = $output === null ? [] : explode(self::MOVIE_BROWSE_FILTER_FIELD_SEPARATOR, $output, 4);
        if (count($fields) !== 4) {
            return self::planMovieBrowseFilterPhp($field, $value);
        }

        return ['applied' => $fields[0] === '1', 'operator' => $fields[1], 'column' => $fields[2], 'value' => $fields[3]];
    }

    /**
     * @return array{applied: bool, operator: string, column: string, value: string}
     */
    private static function planMovieBrowseFilterPhp(string $field, string $value): array
    {
        $skip = ['applied' => false, 'operator' => '', 'column' => '', 'value' => ''];
        if (! in_array($field, self::MOVIE_BROWSE_FILTER_FIELDS, true) || empty($value)) {
            return $skip;
        }
        $value = stripslashes($value);
        if ($field === 'year') {
            return preg_match('/^(19|20)\d{2}$/', $value)
                ? ['applied' => true, 'operator' => '=', 'column' => 'm.year', 'value' => $value]
                : $skip;
        }
        if ($field === 'imdb') {
            return ['applied' => true, 'operator' => '=', 'column' => 'm.imdbid', 'value' => $value];
        }
        if ($field === 'rating') {
            $value .= '.';
        }

        return ['applied' => true, 'operator' => 'LIKE', 'column' => 'm.'.$field, 'value' => '%'.$value.'%'];
    }

    /**
     * @param  list<int|string>  $args
     */
    private static function callMovieBrowseFilterNative(array $args, int $hint): ?string
    {
        self::$movieBrowseFilterFfi ??= RustPipelineLibrary::resolveDomain('metadata_w384', self::MOVIE_BROWSE_FILTER_FFI_CDEF) ?? false;
        if (self::$movieBrowseFilterFfi === false) {
            return null;
        }
        $ffi = self::$movieBrowseFilterFfi;

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $hint, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w384_movie_browse_filter_plan(...[...$args, $buffer, $capacity]);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w384_movie_browse_filter_plan(...[...$args, $buffer, $capacity]);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return null;
        }
    }
}
