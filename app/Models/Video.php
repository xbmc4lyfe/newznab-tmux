<?php

declare(strict_types=1);

namespace App\Models;

use App\Facades\Search;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * App\Models\Video.
 *
 * @property int $id Show ID to be used in other tables as reference
 * @property int $type 0 = TV, 1 = Film, 2 = Anime
 * @property string $title Name of the video.
 * @property string $countries_id Two character country code (FK to countries table).
 * @property string $started Date (UTC) of production's first airing.
 * @property int $anidb ID number for anidb site
 * @property string $imdb ID number for IMDB site (without the 'tt' prefix).
 * @property int $tmdb ID number for TMDB site.
 * @property int $trakt ID number for TraktTV site.
 * @property int $tvdb ID number for TVDB site
 * @property int $tvmaze ID number for TVMaze site.
 * @property int $tvrage ID number for TVRage site.
 * @property int $source Which site did we use for info?
 * @property-read Collection|VideoAlias[] $alias
 * @property-read Collection|TvEpisode[] $episode
 * @property-read Collection|Release[] $release
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Video whereAnidb($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Video whereCountriesId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Video whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Video whereImdb($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Video whereSource($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Video whereStarted($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Video whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Video whereTmdb($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Video whereTrakt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Video whereTvdb($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Video whereTvmaze($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Video whereTvrage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Video whereType($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Video newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Video newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Video query()
 */
class Video extends Model
{
    protected $dateFormat = false;

    /**
     * @var array<string>
     */
    protected $guarded = [];

    /**
     * @var bool
     */
    public $timestamps = false;

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_video_series_list_cache_key(const char *uid_ptr, size_t uid_len, const char *letter_ptr, size_t letter_len, const char *showname_ptr, size_t showname_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_video_series_letter_filter(const char *letter_ptr, size_t letter_len, size_t *out_sql_len, uint32_t *out_has_binding, char *out_buf, size_t out_cap);
        ssize_t nntmux_video_series_showname_filter(const char *showname_ptr, size_t showname_len, uint32_t search_available, const int64_t *ids_ptr, size_t ids_len, size_t *out_sql_len, uint32_t *out_has_binding, uint32_t *out_empty_hits, char *out_buf, size_t out_cap);
        C;

    /** Null selects the benchmark-qualified production default (PHP; see RustTvProviderLookupVideoMovieObserverParityTest). */
    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use;
        if ($use === null) {
            self::$ffi = null;
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::$ffi = $override;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * Build the cache key for {@see getSeriesList}.
     */
    public static function buildSeriesListCacheKey(mixed $uid, string $letter = '', string $showname = ''): string
    {
        return self::usesRustFfi()
            ? self::buildSeriesListCacheKeyNative($uid, $letter, $showname)
            : self::buildSeriesListCacheKeyPhp($uid, $letter, $showname);
    }

    public static function buildSeriesListCacheKeyNative(mixed $uid, string $letter = '', string $showname = ''): string
    {
        if (($ffi = self::ffi()) === null || \is_array($uid) || (\is_object($uid) && ! $uid instanceof \Stringable)) {
            return self::buildSeriesListCacheKeyPhp($uid, $letter, $showname);
        }
        $uidStr = (string) $uid;
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, 64, $capacity);
        if ($buffer === null) {
            return self::buildSeriesListCacheKeyPhp($uid, $letter, $showname);
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_video_series_list_cache_key(
                $uidStr,
                \strlen($uidStr),
                $letter,
                \strlen($letter),
                $showname,
                \strlen($showname),
                $buffer,
                $capacity,
            );
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::buildSeriesListCacheKeyPhp($uid, $letter, $showname);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_video_series_list_cache_key(
                    $uidStr,
                    \strlen($uidStr),
                    $letter,
                    \strlen($letter),
                    $showname,
                    \strlen($showname),
                    $buffer,
                    $capacity,
                );
            }
            if (! \is_int($written) || $written !== 50 || $written > $capacity) {
                return self::buildSeriesListCacheKeyPhp($uid, $letter, $showname);
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return self::buildSeriesListCacheKeyPhp($uid, $letter, $showname);
        }
    }

    public static function buildSeriesListCacheKeyPhp(mixed $uid, string $letter = '', string $showname = ''): string
    {
        return 'video_series_list:'.md5(serialize([(string) $uid, $letter, $showname]));
    }

    /**
     * Build the SQL condition and optional `:letter` parameter binding for {@see getSeriesListUncached}.
     *
     * @return array{sql: string, binding: ?string}
     */
    public static function buildSeriesLetterFilter(string $letter): array
    {
        return self::usesRustFfi()
            ? self::buildSeriesLetterFilterNative($letter)
            : self::buildSeriesLetterFilterPhp($letter);
    }

    /**
     * @return array{sql: string, binding: ?string}
     */
    public static function buildSeriesLetterFilterNative(string $letter): array
    {
        if (($ffi = self::ffi()) === null) {
            return self::buildSeriesLetterFilterPhp($letter);
        }
        $letterLen = \strlen($letter);
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, max(128, $letterLen + 64), $capacity);
        if ($buffer === null) {
            return self::buildSeriesLetterFilterPhp($letter);
        }
        try {
            $outSqlLen = $ffi->new('size_t[1]');
            $outHasBinding = $ffi->new('uint32_t[1]');
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_video_series_letter_filter($letter, $letterLen, $outSqlLen, $outHasBinding, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::buildSeriesLetterFilterPhp($letter);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_video_series_letter_filter($letter, $letterLen, $outSqlLen, $outHasBinding, $buffer, $capacity);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return self::buildSeriesLetterFilterPhp($letter);
            }
            if ($written === 0) {
                return ['sql' => '', 'binding' => null];
            }
            $sqlLen = (int) $outSqlLen[0];
            $hasBinding = (int) $outHasBinding[0] !== 0;
            if ($sqlLen < 0 || $sqlLen > $written) {
                return self::buildSeriesLetterFilterPhp($letter);
            }
            $blob = FFI::string($buffer, $written);

            return [
                'sql' => substr($blob, 0, $sqlLen),
                'binding' => $hasBinding ? substr($blob, $sqlLen) : null,
            ];
        } catch (Throwable) {
            return self::buildSeriesLetterFilterPhp($letter);
        }
    }

    /**
     * @return array{sql: string, binding: ?string}
     */
    public static function buildSeriesLetterFilterPhp(string $letter): array
    {
        if ($letter === '') {
            return ['sql' => '', 'binding' => null];
        }
        if ($letter === '0-9' || $letter === '[0-9]') {
            return ['sql' => "AND videos.title REGEXP '^[0-9]'", 'binding' => null];
        }

        return [
            'sql' => 'AND videos.title LIKE :letter',
            'binding' => $letter.'%',
        ];
    }

    /**
     * Build the SQL condition and optional `:showname` binding for {@see getSeriesListUncached},
     * or return `null` when search is available for a non-empty `$showname` and yields no matching show IDs.
     *
     * @param  array<int|string, mixed>  $rawHitIds
     * @return array{sql: string, binding: ?string}|null
     */
    public static function buildSeriesShownameFilter(string $showname, bool $searchAvailable, array $rawHitIds = []): ?array
    {
        return self::usesRustFfi()
            ? self::buildSeriesShownameFilterNative($showname, $searchAvailable, $rawHitIds)
            : self::buildSeriesShownameFilterPhp($showname, $searchAvailable, $rawHitIds);
    }

    /**
     * @param  array<int|string, mixed>  $rawHitIds
     * @return array{sql: string, binding: ?string}|null
     */
    public static function buildSeriesShownameFilterNative(string $showname, bool $searchAvailable, array $rawHitIds = []): ?array
    {
        if (($ffi = self::ffi()) === null) {
            return self::buildSeriesShownameFilterPhp($showname, $searchAvailable, $rawHitIds);
        }
        $shownameLen = \strlen($showname);
        $idsCount = $searchAvailable && $showname !== '' ? \count($rawHitIds) : 0;
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, max(128, $shownameLen + ($idsCount * 22) + 64), $capacity);
        if ($buffer === null) {
            return self::buildSeriesShownameFilterPhp($showname, $searchAvailable, $rawHitIds);
        }
        try {
            $idsBuf = null;
            if ($idsCount > 0) {
                $idsBuf = $ffi->new("int64_t[{$idsCount}]");
                $idx = 0;
                foreach ($rawHitIds as $id) {
                    $idsBuf[$idx] = (int) $id;
                    $idx++;
                }
            }
            $outSqlLen = $ffi->new('size_t[1]');
            $outHasBinding = $ffi->new('uint32_t[1]');
            $outEmptyHits = $ffi->new('uint32_t[1]');
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_video_series_showname_filter(
                $showname,
                $shownameLen,
                $searchAvailable ? 1 : 0,
                $idsBuf,
                $idsCount,
                $outSqlLen,
                $outHasBinding,
                $outEmptyHits,
                $buffer,
                $capacity,
            );
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::buildSeriesShownameFilterPhp($showname, $searchAvailable, $rawHitIds);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_video_series_showname_filter(
                    $showname,
                    $shownameLen,
                    $searchAvailable ? 1 : 0,
                    $idsBuf,
                    $idsCount,
                    $outSqlLen,
                    $outHasBinding,
                    $outEmptyHits,
                    $buffer,
                    $capacity,
                );
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return self::buildSeriesShownameFilterPhp($showname, $searchAvailable, $rawHitIds);
            }
            if ((int) $outEmptyHits[0] !== 0) {
                return null;
            }
            if ($written === 0) {
                return ['sql' => '', 'binding' => null];
            }
            $sqlLen = (int) $outSqlLen[0];
            $hasBinding = (int) $outHasBinding[0] !== 0;
            if ($sqlLen < 0 || $sqlLen > $written) {
                return self::buildSeriesShownameFilterPhp($showname, $searchAvailable, $rawHitIds);
            }
            $blob = FFI::string($buffer, $written);

            return [
                'sql' => substr($blob, 0, $sqlLen),
                'binding' => $hasBinding ? substr($blob, $sqlLen) : null,
            ];
        } catch (Throwable) {
            return self::buildSeriesShownameFilterPhp($showname, $searchAvailable, $rawHitIds);
        }
    }

    /**
     * @param  array<int|string, mixed>  $rawHitIds
     * @return array{sql: string, binding: ?string}|null
     */
    public static function buildSeriesShownameFilterPhp(string $showname, bool $searchAvailable, array $rawHitIds = []): ?array
    {
        if ($showname === '') {
            return ['sql' => '', 'binding' => null];
        }
        if ($searchAvailable) {
            $vids = array_values(array_filter(array_map(static fn ($id): int => (int) $id, $rawHitIds)));
            if ($vids === []) {
                return null;
            }

            return [
                'sql' => 'AND videos.id IN ('.implode(',', $vids).')',
                'binding' => null,
            ];
        }

        return [
            'sql' => 'AND videos.title LIKE :showname',
            'binding' => '%'.$showname.'%',
        ];
    }

    /**
     * @return HasMany<VideoAlias, $this>
     */
    public function alias(): HasMany
    {
        return $this->hasMany(VideoAlias::class, 'videos_id');
    }

    /**
     * @return HasMany<Release, $this>
     */
    public function release(): HasMany
    {
        return $this->hasMany(Release::class, 'videos_id');
    }

    /**
     * @return HasMany<TvEpisode, $this>
     */
    public function episode(): HasMany
    {
        return $this->hasMany(TvEpisode::class, 'videos_id');
    }

    /**
     * @return HasOne<TvInfo, $this>
     */
    public function tvInfo(): HasOne
    {
        return $this->hasOne(TvInfo::class, 'videos_id');
    }

    /**
     * Get info from tables for the provided ID.
     *
     *
     * @return Model|null|static
     */
    public static function getByVideoID(mixed $id)
    {
        return self::query()
            ->select(['videos.*', 'tv_info.summary', 'tv_info.publisher', 'tv_info.image'])
            ->where('videos.id', $id)
            ->join('tv_info', 'videos.id', '=', 'tv_info.videos_id')
            ->first();
    }

    /**
     * Retrieves a range of all shows for the show-edit admin list.
     */
    public static function getRange(string $showname = ''): LengthAwarePaginator // @phpstan-ignore missingType.generics
    {
        $sql = self::query()
            ->select(['videos.*', 'tv_info.summary', 'tv_info.publisher', 'tv_info.image'])
            ->join('tv_info', 'videos.id', '=', 'tv_info.videos_id');

        if ($showname !== '') {
            $sql->where('videos.title', 'like', '%'.$showname.'%');
        }

        return $sql->paginate(config('nntmux.items_per_page'));
    }

    /**
     * Returns a count of all shows -- usually used by pager.
     */
    public static function getCount(string $showname = ''): int
    {
        $res = self::query()->join('tv_info', 'videos.id', '=', 'tv_info.videos_id');

        if ($showname !== '') {
            $res->where('videos.title', 'like', '%'.$showname.'%');
        }

        return $res->count('videos.id');
    }

    /**
     * Retrieves and returns a list of shows with eligible releases.
     *
     * @return array<string, mixed>
     */
    public static function getSeriesList(mixed $uid, string $letter = '', string $showname = ''): array
    {
        $cacheKey = self::buildSeriesListCacheKey($uid, $letter, $showname);

        return Cache::remember($cacheKey, now()->addMinutes((int) config('nntmux.cache_expiry_medium', 60)), function () use ($uid, $letter, $showname): array {
            return self::getSeriesListUncached($uid, $letter, $showname);
        });
    }

    /**
     * @internal Used by {@see getSeriesList} with cache wrapper.
     */
    private static function getSeriesListUncached(mixed $uid, string $letter = '', string $showname = ''): array
    {
        $params = [
            'uid' => $uid,
            'tv_root' => Category::TV_ROOT,
            'tv_other' => Category::TV_OTHER,
            'now' => now()->toDateTimeString(),
        ];

        $letterFilter = self::buildSeriesLetterFilter($letter);
        $letterCondition = $letterFilter['sql'];
        if ($letterFilter['binding'] !== null) {
            $params['letter'] = $letterFilter['binding'];
        }

        $shownameCondition = '';
        if ($showname !== '') {
            $searchAvailable = Search::isAvailable();
            $rawHitIds = [];
            if ($searchAvailable) {
                $hits = Search::searchTvShows($showname, 800);
                $rawHitIds = \is_array($hits['id'] ?? null) ? $hits['id'] : [];
            }
            $shownameFilter = self::buildSeriesShownameFilter($showname, $searchAvailable, $rawHitIds);
            if ($shownameFilter === null) {
                return [];
            }
            $shownameCondition = $shownameFilter['sql'];
            if ($shownameFilter['binding'] !== null) {
                $params['showname'] = $shownameFilter['binding'];
            }
        }

        $sql = "
            SELECT
                videos.*,
                tve.firstaired AS prevdate,
                tve.title AS previnfo,
                tvi.publisher,
                us.id AS userseriesid
            FROM videos
            INNER JOIN tv_info AS tvi ON videos.id = tvi.videos_id
            INNER JOIN (
                SELECT videos_id, MAX(firstaired) AS max_firstaired
                FROM tv_episodes
                WHERE firstaired < :now
                GROUP BY videos_id
            ) AS latest_ep ON videos.id = latest_ep.videos_id
            INNER JOIN tv_episodes AS tve
                ON videos.id = tve.videos_id
                AND tve.firstaired = latest_ep.max_firstaired
            LEFT JOIN user_series AS us
                ON videos.id = us.videos_id
                AND us.users_id = :uid
            WHERE EXISTS (
                SELECT 1
                FROM releases AS r
                WHERE r.videos_id = videos.id
                AND r.categories_id BETWEEN :tv_root AND :tv_other
            )
            {$letterCondition}
            {$shownameCondition}
            GROUP BY videos.id
            ORDER BY videos.title ASC
        ";

        $results = DB::select($sql, $params);

        // Convert stdClass objects to arrays for backward compatibility
        return array_map(fn ($row) => (array) $row, $results);
    }
}
