<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ImageAssetProfile;
use App\Enums\SecondarySearchIndex;
use App\Facades\Search;
use App\Models\Category;
use App\Models\Genre;
use App\Models\MusicInfo;
use App\Models\Release;
use App\Services\Configuration\ConfigurationProvider;
use App\Services\Releases\ReleaseBrowseService;
use App\Support\MetadataSearchLookup;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Music Service - Handles music browsing and lookup operations.
 */
class MusicService
{
    /** Common p99 uncertainty crosses 1.5x; keep PHP until the gain is conclusive. */
    public const bool W404_RUST_FFI_DEFAULT = false;

    public const string W404_FFI_CDEF = <<<'C'
        ssize_t nntmux_w404_music_release_lookup_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private static ?bool $w404UseRustFfi = null;

    private static FFI|false|null $w404Ffi = null;

    public static function useW404RustFfi(?bool $use = true): void
    {
        self::$w404UseRustFfi = $use;
        if ($use === null) {
            self::$w404Ffi = null;
        }
    }

    public static function usesW404RustFfi(): bool
    {
        return self::$w404UseRustFfi ?? self::W404_RUST_FFI_DEFAULT;
    }

    public static function resetW404RustFfi(): void
    {
        self::$w404UseRustFfi = null;
        self::$w404Ffi = null;
    }

    /** @param list<list<string>> $rows
     * @return array<string, int>|null
     */
    private static function w404Call(array $rows): ?array
    {
        try {
            $input = serialize([$rows]);
            if (strlen($input) > 16777216) {
                return null;
            }
            self::$w404Ffi ??= RustPipelineLibrary::resolveDomain('metadata_w404', self::W404_FFI_CDEF) ?? false;
            $ffi = self::$w404Ffi;
            if ($ffi === false) {
                return null;
            }
            $capacity = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, 2048, $capacity);
            if ($out === null) {
                return null;
            }
            // @phpstan-ignore method.notFound (Declared by W404_FFI_CDEF.)
            $written = $ffi->nntmux_w404_music_release_lookup_batch($input, strlen($input), $out, $capacity);
            if (is_int($written) && $written < -1 && $written >= -16777216) {
                $out = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($out === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound (Declared by W404_FFI_CDEF.)
                $written = $ffi->nntmux_w404_music_release_lookup_batch($input, strlen($input), $out, $capacity);
            }
            if (! is_int($written) || $written <= 0 || $written > $capacity || $written > 16777216) {
                return null;
            }
            $result = @unserialize(FFI::string($out, $written), ['allowed_classes' => false]);
            $keys = ['total_candidates', 'lossless_flac_count', 'mp3_bitrate_count', 'has_year_count', 'artist_album_splitable_count'];
            if (! is_array($result) || array_keys($result) !== $keys) {
                return null;
            }
            $summary = [];
            foreach ($keys as $key) {
                $value = $result[$key];
                if (! is_int($value) || $value < 0 || $value > count($rows)) {
                    return null;
                }
                $summary[$key] = $value;
            }

            return $summary;
        } catch (Throwable) {
            return null;
        }
    }

    /** Only integer and string fields participate; objects are never cast or serialized. */
    private static function w404Text(mixed $value): string
    {
        return is_string($value) || is_int($value) ? trim((string) $value) : '';
    }

    /** @param array<array-key, mixed> $input
     * @return list<list<string>>
     */
    private static function w404Rows(array $input): array
    {
        $rows = [];
        foreach ($input as $row) {
            $row = is_array($row) ? $row : [];
            $rows[] = [self::w404Text($row['searchname'] ?? $row['title'] ?? null), self::w404Text($row['year'] ?? null)];
        }

        return $rows;
    }

    /**
     * Summarize searchname (or title) and year fields. FLAC/MP3/bitrate and
     * years use ASCII alphanumeric tokens; a year is 1000-2999. Splittable
     * means the first hyphen has nonempty trimmed text on both sides.
     *
     * @param  array<array-key, mixed>  $releases
     * @return array<string, int>
     */
    public static function summarizeMusicReleaseLookupBatch(array $releases): array
    {
        if (self::usesW404RustFfi()) {
            $native = self::w404Call(self::w404Rows($releases));
            if ($native !== null) {
                return $native;
            }
        }

        return self::summarizeMusicReleaseLookupBatchPhp($releases);
    }

    /** @param array<array-key, mixed> $releases
     * @return array<string, int>
     */
    public static function summarizeMusicReleaseLookupBatchPhp(array $releases): array
    {
        $summary = array_fill_keys(['total_candidates', 'lossless_flac_count', 'mp3_bitrate_count', 'has_year_count', 'artist_album_splitable_count'], 0);
        $summary['total_candidates'] = count($releases);
        foreach (self::w404Rows($releases) as [$title, $year]) {
            $tokens = preg_split('/[^A-Z0-9]+/', strtoupper($title), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $summary['lossless_flac_count'] += (int) in_array('FLAC', $tokens, true);
            $summary['mp3_bitrate_count'] += (int) (array_intersect(['MP3', '128', '192', '256', '320'], $tokens) !== []);
            $hasYear = preg_match('/\A[12][0-9]{3}\z/', $year) === 1;
            foreach ($tokens as $token) {
                $hasYear = $hasYear || preg_match('/\A[12][0-9]{3}\z/', $token) === 1;
            }
            $summary['has_year_count'] += (int) $hasYear;
            $separator = strpos($title, '-');
            $summary['artist_album_splitable_count'] += (int) ($separator !== false && trim(substr($title, 0, $separator)) !== '' && trim(substr($title, $separator + 1)) !== '');
        }

        return $summary;
    }

    protected const MATCH_PERCENT = 85;

    private const string FFI_CDEF = <<<'C'
        const char *nntmux_music_parse_artist(const char *input, size_t input_len);
        C;

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

    private static bool $useRustFfi = true;

    public bool $echooutput;

    public int $musicqty;

    public int $sleeptime;

    public string $imgSavePath;

    public mixed $renamed;

    /**
     * Store names of failed lookup items.
     *
     * @var array<string, mixed>
     */
    public array $failCache;

    public function __construct()
    {
        $this->echooutput = config('nntmux.echocli');

        $this->musicqty = app(ConfigurationProvider::class)->metadata()->maxMusicProcessed !== '' ? (int) app(ConfigurationProvider::class)->metadata()->maxMusicProcessed : 150;
        $this->sleeptime = app(ConfigurationProvider::class)->metadata()->amazonSleepMilliseconds !== '' ? (int) app(ConfigurationProvider::class)->metadata()->amazonSleepMilliseconds : 1000;
        $this->imgSavePath = config('nntmux_settings.covers_path').'/music/';
        $this->renamed = (int) app(ConfigurationProvider::class)->metadata()->musicLookup->value === 2 ? 'AND isrenamed = 1' : '';

        $this->failCache = [];
    }

    public static function setUseRustFfi(bool $use): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * Get music info by ID.
     */
    public function getMusicInfo(int $id): ?MusicInfo
    {
        return MusicInfo::query()->with('genre')->where('id', $id)->first();
    }

    /**
     * Get music info by name using full-text search.
     */
    public function getMusicInfoByName(string $artist, string $album): ?MusicInfo
    {
        $searchwords = '';
        $album = preg_replace('/( - | -|\(.+\)|\(|\))/', ' ', $album);
        $album = preg_replace('/[^\w ]+/', '', $album);
        $album = preg_replace('/(WEB|FLAC|CD)/', '', $album);
        $album = trim(trim(preg_replace('/\s\s+/i', ' ', $album)));

        foreach (explode(' ', $album) as $word) {
            $word = trim(rtrim(trim($word), '-'));
            if ($word !== '' && $word !== '-') {
                $word = '+'.$word;
                $searchwords .= sprintf('%s ', $word);
            }
        }
        $searchwords = trim($searchwords);

        if (Search::isAvailable()) {
            $q = MetadataSearchLookup::normalizeBooleanSearchWords($searchwords);
            if ($q !== '') {
                $hits = Search::searchSecondary(SecondarySearchIndex::Music, $q, 25);
                $musicIds = array_values(array_map('intval', $hits['id'] ?? []));
                if ($musicIds !== []) {
                    $rowsById = MusicInfo::query()
                        ->with('genre')
                        ->whereIn('id', $musicIds)
                        ->get()
                        ->keyBy('id');

                    foreach ($musicIds as $musicId) {
                        if ($rowsById->has($musicId)) {
                            /** @var MusicInfo $music */
                            $music = $rowsById->get($musicId);

                            return $music;
                        }
                    }
                }
            }

            return null;
        }

        return MusicInfo::query()
            ->whereRaw('MATCH (artist, title) AGAINST (? IN BOOLEAN MODE)', [$searchwords])
            ->first();
    }

    /**
     * Get paginated music range for browsing.
     *
     * @param  array<int|string, mixed>  $cat  Category IDs (list or associative)
     * @param  array<string, mixed>  $excludedCats
     */
    public function getMusicRange(int $page, array $cat, int $start, int $num, string $orderBy, array $excludedCats = []): mixed
    {
        $page = max(1, $page);
        $start = max(0, $start);

        $useIndexForArtistTitle = Search::isAvailable()
            && (! empty($_REQUEST['artist']) || ! empty($_REQUEST['title']));
        $musicIdsFromSearch = null;
        if ($useIndexForArtistTitle) {
            $q = trim(
                stripslashes((string) ($_REQUEST['artist'] ?? '')).' '
                .stripslashes((string) ($_REQUEST['title'] ?? ''))
            );
            if ($q === '') {
                $musicIdsFromSearch = [];
            } else {
                $musicIdsFromSearch = Search::searchSecondary(SecondarySearchIndex::Music, $q, 5000)['id'];
            }
            if ($musicIdsFromSearch === []) {
                return collect();
            }
        }

        $browseby = $this->getBrowseBy($useIndexForArtistTitle);
        $musicInClause = '';
        if (is_array($musicIdsFromSearch) && $musicIdsFromSearch !== []) {
            $musicInClause = ' AND m.id IN ('.implode(',', array_map('intval', $musicIdsFromSearch)).')';
        }
        $catsrch = '';
        if (\count($cat) > 0 && (int) $cat[0] !== -1) {
            $catsrch = Category::getCategorySearch($cat);
        }
        $exccatlist = '';
        if (\count($excludedCats) > 0) {
            $exccatlist = ' AND r.categories_id NOT IN ('.implode(',', $excludedCats).')';
        }
        $order = $this->getMusicOrder($orderBy);
        $expiresAt = now()->addMinutes(config('nntmux.cache_expiry_medium'));

        $releaseBrowseService = new ReleaseBrowseService;
        $showPasswords = $releaseBrowseService->showPasswords();

        $baseWhere = "m.title != '' AND m.cover = 1 "
            ."AND r.passwordstatus {$showPasswords} "
            .$browseby.' '
            .$musicInClause.' '
            .$catsrch.' '
            .$exccatlist;

        $cacheKey = md5('music_range_'.$baseWhere.$order[0].$order[1].$start.$num.$page);

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Step 1: Count total distinct music entities matching filters
        $countSql = 'SELECT COUNT(DISTINCT m.id) AS total '
            .'FROM musicinfo m '
            .'INNER JOIN releases r ON r.musicinfo_id = m.id '
            .'WHERE '.$baseWhere;

        $totalResult = DB::select($countSql);
        $totalCount = $totalResult[0]->total ?? 0;

        if ($totalCount === 0) {
            return collect();
        }

        // Step 2: Get paginated music entity list with only needed columns
        $musicSql = 'SELECT m.id, m.title, m.artist, m.cover, m.publisher, m.releasedate, m.review, m.url, m.year, '
            .'m.genres_id, '
            .'MAX(r.postdate) AS latest_postdate, '
            .'COUNT(r.id) AS total_releases '
            .'FROM musicinfo m '
            .'INNER JOIN releases r ON r.musicinfo_id = m.id '
            .'WHERE '.$baseWhere.' '
            .'GROUP BY m.id, m.title, m.artist, m.cover, m.publisher, m.releasedate, m.review, m.url, m.year, m.genres_id '
            ."ORDER BY {$order[0]} {$order[1]} "
            ."LIMIT {$num} OFFSET {$start}";

        $musicEntities = MusicInfo::fromQuery($musicSql);

        if ($musicEntities->isEmpty()) {
            return collect();
        }

        // Build list of music IDs for release query
        $musicIds = $musicEntities->pluck('id')->toArray();
        $inMusicIds = implode(',', array_map('intval', $musicIds));

        // Step 3: Get top 2 releases per music entity using ROW_NUMBER()
        $releasesSql = 'SELECT ranked.id, ranked.musicinfo_id, ranked.guid, ranked.searchname, '
            .'ranked.size, ranked.postdate, ranked.adddate, ranked.haspreview, ranked.grabs, '
            .'ranked.comments, ranked.totalpart, ranked.group_name, ranked.nfoid, ranked.failed_count '
            .'FROM ( '
            .'SELECT r.id, r.musicinfo_id, r.guid, r.searchname, r.size, r.postdate, r.adddate, '
            .'r.haspreview, r.grabs, r.comments, r.totalpart, g.name AS group_name, '
            .'rn.releases_id AS nfoid, df.failed AS failed_count, '
            .'ROW_NUMBER() OVER (PARTITION BY r.musicinfo_id ORDER BY r.postdate DESC) AS rn '
            .'FROM releases r '
            .'LEFT JOIN usenet_groups g ON g.id = r.groups_id '
            .'LEFT JOIN release_nfos rn ON rn.releases_id = r.id '
            .'LEFT JOIN dnzb_failures df ON df.release_id = r.id '
            ."WHERE r.musicinfo_id IN ({$inMusicIds}) "
            ."AND r.passwordstatus {$showPasswords} "
            .$catsrch.' '
            .$exccatlist
            .') ranked '
            .'WHERE ranked.rn <= 2 '
            .'ORDER BY ranked.musicinfo_id, ranked.postdate DESC';

        $releases = DB::select($releasesSql);

        // Group releases by musicinfo_id for fast lookup
        $releasesByMusic = [];
        foreach ($releases as $release) {
            $releasesByMusic[$release->musicinfo_id][] = $release;
        }

        // Attach releases to each music entity
        foreach ($musicEntities as $musicItem) {
            $musicItem->releases = $releasesByMusic[$musicItem->id] ?? []; // @phpstan-ignore property.notFound
        }

        // Set total count on first item
        if ($musicEntities->isNotEmpty()) {
            $musicEntities[0]->_totalcount = $totalCount;
        }

        Cache::put($cacheKey, $musicEntities, $expiresAt);

        return $musicEntities;
    }

    /**
     * Parse order by parameter and return order field and direction.
     *
     * @return array{0: string, 1: string}
     */
    public function getMusicOrder(string $orderBy): array
    {
        $order = ($orderBy === '') ? 'r.postdate' : $orderBy;
        $orderArr = explode('_', $order);

        $orderfield = match ($orderArr[0]) {
            'artist' => 'm.artist',
            'size' => 'r.size',
            'files' => 'r.totalpart',
            'stats' => 'r.grabs',
            'year' => 'm.year',
            'genre' => 'm.genres_id',
            default => 'r.postdate',
        };

        $ordersort = (isset($orderArr[1]) && preg_match('/^asc|desc$/i', $orderArr[1])) ? $orderArr[1] : 'desc';

        return [$orderfield, $ordersort];
    }

    /**
     * Get available ordering options.
     *
     * @return array<int, string>
     */
    public function getMusicOrdering(): array
    {
        return [
            'artist_asc', 'artist_desc',
            'posted_asc', 'posted_desc',
            'size_asc', 'size_desc',
            'files_asc', 'files_desc',
            'stats_asc', 'stats_desc',
            'year_asc', 'year_desc',
            'genre_asc', 'genre_desc',
        ];
    }

    /**
     * Get browse by options.
     *
     * @return array<string, mixed>
     */
    public function getBrowseByOptions(): array
    {
        return ['artist' => 'artist', 'title' => 'title', 'genre' => 'genres_id', 'year' => 'year'];
    }

    /**
     * Build browse by SQL clause.
     */
    public function getBrowseBy(bool $skipArtistTitleLike = false): string
    {
        $browseby = ' ';
        foreach ($this->getBrowseByOptions() as $bbk => $bbv) {
            if ($skipArtistTitleLike && ($bbk === 'artist' || $bbk === 'title')) {
                continue;
            }
            if (! empty($_REQUEST[$bbk])) {
                $bbs = stripslashes($_REQUEST[$bbk]);
                if (stripos($bbv, 'id') !== false) {
                    $browseby .= ' AND m.'.$bbv.' = '.$bbs;
                } else {
                    $browseby .= ' AND m.'.$bbv.' '.'LIKE '.escapeString('%'.$bbs.'%');
                }
            }
        }

        return $browseby;
    }

    /**
     * Update music info record.
     */
    public function update(
        int $id,
        string $title,
        ?string $asin,
        ?string $url,
        ?int $salesrank,
        ?string $artist,
        ?string $publisher,
        ?string $releasedate,
        ?string $year,
        ?string $tracks,
        int $cover,
        ?int $genres_id
    ): void {
        MusicInfo::query()->where('id', $id)->update([
            'title' => $title,
            'asin' => $asin,
            'url' => $url,
            'salesrank' => $salesrank,
            'artist' => $artist,
            'publisher' => $publisher,
            'releasedate' => $releasedate,
            'year' => $year,
            'tracks' => $tracks,
            'cover' => $cover,
            'genres_id' => $genres_id,
        ]);
    }

    /**
     * Update or create music info from external data.
     *
     * @param  array<string, mixed>  $amazdata
     *
     * @throws \Exception
     */
    public function updateMusicInfo(string $title, string $year, ?array $amazdata = null): int|false
    {
        $ri = new ReleaseImageService;

        $mus = [];
        if ($amazdata !== null) {
            $mus = $amazdata;
        } elseif ($title !== '') {
            $mus = $this->fetchItunesMusicProperties($title);
        }

        if ($mus === false) {
            return false;
        }

        $check = MusicInfo::query()->where('asin', $mus['asin'])->first(['id']);

        if ($check === null) {
            $musicId = MusicInfo::query()->insertGetId([
                'title' => $mus['title'],
                'asin' => $mus['asin'],
                'url' => $mus['url'],
                'salesrank' => $mus['salesrank'],
                'artist' => $mus['artist'],
                'publisher' => $mus['publisher'],
                'releasedate' => $mus['releasedate'],
                'review' => $mus['review'],
                'year' => $year,
                'genres_id' => (int) $mus['musicgenres_id'] === -1 ? null : $mus['musicgenres_id'],
                'tracks' => $mus['tracks'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $mus['cover'] = (int) $ri->saveRemoteImage(
                (string) $musicId,
                $mus['coverurl'],
                $this->imgSavePath,
                ImageAssetProfile::MetadataCover,
            )->success;
            MusicInfo::query()->where('id', $musicId)->update(['cover' => $mus['cover']]);
        } else {
            $musicId = $check['id'];
            $mus['cover'] = (int) $ri->saveRemoteImage(
                (string) $musicId,
                $mus['coverurl'],
                $this->imgSavePath,
                ImageAssetProfile::MetadataCover,
            )->success;
            MusicInfo::query()->where('id', $musicId)->update([
                'title' => $mus['title'],
                'asin' => $mus['asin'],
                'url' => $mus['url'],
                'salesrank' => $mus['salesrank'],
                'artist' => $mus['artist'],
                'publisher' => $mus['publisher'],
                'releasedate' => $mus['releasedate'],
                'review' => $mus['review'],
                'year' => $year,
                'genres_id' => (int) $mus['musicgenres_id'] === -1 ? null : $mus['musicgenres_id'],
                'tracks' => $mus['tracks'],
                'cover' => $mus['cover'],
            ]);
        }

        if ($musicId) {
            if ($this->echooutput) {
                cli()->header(
                    PHP_EOL.'Added/updated album: '.PHP_EOL.
                    '   Artist: '.$mus['artist'].PHP_EOL.
                    '   Title:  '.$mus['title'].PHP_EOL.
                    '   Year:   '.$year
                );
            }
            $mus['cover'] = (int) $ri->saveRemoteImage(
                (string) $musicId,
                $mus['coverurl'],
                $this->imgSavePath,
                ImageAssetProfile::MetadataCover,
            )->success;
        } elseif ($this->echooutput) {
            if ($mus['artist'] === '') {
                $artist = '';
            } else {
                $artist = 'Artist: '.$mus['artist'].', Album: ';
            }

            cli()->headerOver(
                'Nothing to update: '.$artist.$mus['title'].' ('.$year.')'
            );
        }

        return $musicId;
    }

    /**
     * Process music releases and lookup metadata.
     *
     * @throws \Exception
     */
    public function processMusicReleases(bool $local = false, string $groupID = '', string $guidChar = ''): void
    {
        $guidFilter = $guidChar !== '' ? 'AND leftguid LIKE '.DB::getPdo()->quote($guidChar.'%') : '';
        $groupFilter = $groupID !== '' ? 'AND groups_id = '.(int) $groupID : '';

        $res = DB::select(
            sprintf(
                '
                SELECT searchname, id
                FROM releases
                WHERE musicinfo_id IS NULL
                AND %s
                %s
                %s
                %s
                AND categories_id IN (%s, %s, %s)
                ORDER BY postdate DESC
                LIMIT %d',
                Release::nzbSettledSql(),
                $this->renamed,
                $guidFilter,
                $groupFilter,
                Category::MUSIC_MP3,
                Category::MUSIC_LOSSLESS,
                Category::MUSIC_OTHER,
                $this->musicqty
            )
        );

        if (! empty($res)) {
            foreach ($res as $arr) {
                $startTime = now();
                $usedAmazon = false;
                $album = $this->parseArtist($arr->searchname);

                if ($album !== false) {
                    $newname = $album['name'].' ('.$album['year'].')';

                    if ($this->echooutput) {
                        cli()->info('Looking up: '.$newname);
                    }

                    // Do a local lookup first
                    $musicCheck = $this->getMusicInfoByName('', $album['name']);

                    if ($musicCheck === null && \in_array($album['name'].$album['year'], $this->failCache, true)) {
                        // Lookup recently failed, no point trying again
                        if ($this->echooutput) {
                            cli()->headerOver('Cached previous failure. Skipping.');
                        }
                        $albumId = -2;
                    } elseif ($musicCheck === null && $local === false) {
                        $albumId = $this->updateMusicInfo($album['name'], $album['year']);
                        $usedAmazon = true;
                        if ($albumId === false) {
                            $albumId = -2;
                            $this->failCache[] = $album['name'].$album['year'];
                        }
                    } else {
                        $albumId = $musicCheck['id'];
                    }
                    $this->recordMusicMatch((int) $arr->id, (int) $albumId, (string) $arr->searchname);
                } else {
                    // No album found.
                    $this->recordMusicMatch((int) $arr->id, -2, (string) $arr->searchname);
                    echo '.';
                }

                // Sleep to not flood the API.
                $sleeptime = $this->sleeptime / 1000;
                $diff = now()->diffInSeconds($startTime, true);
                if ($sleeptime - $diff > 0 && $usedAmazon === true) {
                    sleep((int) ($sleeptime - $diff));
                }
            }

            if ($this->echooutput) {
                echo PHP_EOL;
            }
        } elseif ($this->echooutput) {
            cli()->header('No music releases to process.');
        }
    }

    /**
     * Parse artist and album name from release name.
     *
     * @return array<string, mixed>
     */
    public function parseArtist(string $releaseName): array|false
    {
        if (self::$useRustFfi) {
            $native = self::parseArtistNative($releaseName);
            if ($native !== null) {
                return $native;
            }
        }

        return $this->parseArtistPhp($releaseName);
    }

    /**
     * Rust `nntmux_music_parse_artist`; `null` when the library is unavailable or declines the input.
     *
     * @return array{year: string, name: string}|false|null
     */
    public static function parseArtistNative(string $releaseName): array|false|null
    {
        $ffi = self::$ffi ?? (self::$ffiResolved ? null : self::ffi());
        if ($ffi === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $parsed = $ffi->nntmux_music_parse_artist($releaseName, strlen($releaseName));
        } catch (Throwable) {
            return null;
        }
        if (! is_string($parsed)) {
            return null;
        }

        return $parsed === '' ? false : ['year' => substr($parsed, 0, 4), 'name' => substr($parsed, 4)];
    }

    /**
     * Native PHP `parseArtist()` implementation and fallback for inputs the Rust path declines.
     *
     * @return array<string, mixed>|false
     */
    protected function parseArtistPhp(string $releaseName): array|false
    {
        if (preg_match('/(.+?)(\d{1,2} \d{1,2} )?\(?(19\d{2}|20[0-1][\d])\b/', $releaseName, $name)) {
            $result = [];
            $result['year'] = $name[3];

            $a = preg_replace('/([ |-])(\d{1,2} \d{1,2} )?(Bootleg|Boxset|Clean.+Version|Compiled by.+|\dCD|Digipak|DIRFIX|DVBS|FLAC|(Ltd )?(Deluxe|Limited|Special).+Edition|Promo|PROOF|Reissue|Remastered|REPACK|RETAIL(.+UK)?|SACD|Sampler|SAT|Summer.+Mag|UK.+Import|Deluxe.+Version|VINYL|WEB)/i', ' ', $name[1]);
            $b = preg_replace('/([ |-])([a-z]+[\d]+[a-z]+[\d]+.+|[a-z]{2,}[\d]{2,}?.+|3FM|B00[a-z0-9]+|BRC482012|H056|UXM1DW086|(4WCD|ATL|bigFM|CDP|DST|ERE|FIM|MBZZ|MSOne|MVRD|QEDCD|RNB|SBD|SFT|ZYX)([ |-])\d.+)/i', ' ', $a);
            $c = preg_replace('/([ |-])(\d{1,2} \d{1,2} )?([A-Z])( ?$)|\(?[\d]{8,}\)?|([ |-])(CABLE|FREEWEB|LINE|MAG|MCD|YMRSMILES)|\(([a-z]{2,}[\d]{2,}|ost)\)|-web-/i', ' ', $b);
            $d = preg_replace('/VA([ |-])/', 'Various Artists ', $c);
            $e = preg_replace('/([ |-])(\d{1,2} \d{1,2} )?(DAB|DE|DVBC|EP|FIX|IT|Jap|NL|PL|(Pure )?FM|SSL|VLS)([ |-])/i', ' ', $d);
            $f = preg_replace('/([ |-])(\d{1,2} \d{1,2} )?(CABLE|CD(A|EP|M|R|S)?|QEDCD|SAT|SBD)([ |-])/i', ' ', $e);
            $g = str_replace(['_', '-'], ' ', $f);
            $h = trim(preg_replace('/\s\s+/', ' ', $g));
            $newname = trim(preg_replace('/ [a-z]{2}$| [a-z]{3} \d{2,}$|\d{5,} \d{5,}$|-WEB$/i', '', $h));

            if (! preg_match('/^[a-z0-9]+$/i', $newname) && strlen($newname) > 10) {
                $result['name'] = $newname;

                return $result;
            }

            return false;
        }

        return false;
    }

    private static function ffi(): ?FFI
    {
        if (! self::$ffiResolved) {
            self::$ffi = RustPipelineLibrary::resolveDomain('music_parse_artist', self::FFI_CDEF);
            self::$ffiResolved = true;
        }

        return self::$ffi;
    }

    /**
     * Match browse node ID to genre name.
     */
    public function matchBrowseNode(string $nodeId): string|false
    {
        $str = '';

        $str = match ($nodeId) {
            '163420' => 'Music Video & Concerts',
            '30', '624869011' => 'Alternative Rock',
            '31', '624881011' => 'Blues',
            '265640', '624894011' => 'Broadway & Vocalists',
            '173425', '624899011' => "Children's Music",
            '173429', '2231705011', '624905011' => 'Christian & Gospel',
            '67204', '624916011' => 'Classic Rock',
            '85', '624926011' => 'Classical',
            '16', '624976011' => 'Country',
            '7', '624988011' => 'Dance & Electronic',
            '32', '625003011' => 'Folk',
            '67207', '625011011' => 'Hard Rock & Metal',
            '33', '625021011' => 'World Music',
            '34', '625036011' => 'Jazz',
            '289122', '625054011' => 'Latin Music',
            '36', '625070011' => 'New Age',
            '625075011' => 'Opera & Vocal',
            '37', '625092011' => 'Pop',
            '39', '625105011' => 'R&B',
            '38', '625117011' => 'Rap & Hip-Hop',
            '40', '625129011' => 'Rock',
            '42', '625144011' => 'Soundtracks',
            '35', '625061011' => 'Miscellaneous',
            default => '',
        };

        return ($str !== '') ? $str : false;
    }

    /**
     * Fetch music properties from iTunes.
     *
     * @return array<string, mixed>
     */
    protected function fetchItunesMusicProperties(string $title): array|false
    {
        // Load genres.
        $defaultGenres = (new GenreService)->loadGenres((string) GenreService::MUSIC_TYPE);

        $itunes = new ItunesService;

        // Try to find album first
        $album = $itunes->findAlbum($title);

        if ($album === null) {
            // Try finding a track instead
            $track = $itunes->findTrack($title);
            if ($track === null) {
                return false;
            }
            // Use track info to build album-like data
            $album = [
                'name' => $track['album'] ?? $track['name'],
                'id' => $track['album_id'] ?? $track['id'],
                'artist' => $track['artist'],
                'artist_id' => $track['artist_id'],
                'genre' => $track['genre'],
                'release_date' => $track['release_date'],
                'cover' => $track['cover'],
                'store_url' => $track['store_url'],
            ];
        }

        $genreName = $album['genre'] ?? '';

        if (! empty($genreName)) {
            if (\in_array(strtolower($genreName), $defaultGenres, true)) {
                $genreKey = array_search(strtolower($genreName), $defaultGenres, true);
            } else {
                $genreKey = Genre::query()->insertGetId(['title' => $genreName, 'type' => GenreService::MUSIC_TYPE]);
            }
        } else {
            $genreKey = -1;
        }

        // Get artist name - either from album data or lookup
        $artistName = $album['artist'] ?? '';
        if (empty($artistName) && ! empty($album['artist_id'])) {
            $artistData = $itunes->lookupArtist($album['artist_id']);
            $artistName = $artistData['artistName'] ?? '';
        }

        return [
            'title' => $album['name'],
            'asin' => $album['id'],
            'url' => $album['store_url'] ?? '',
            'salesrank' => '',
            'artist' => $artistName,
            'publisher' => $album['copyright'] ?? '',
            'releasedate' => $album['release_date'],
            'review' => '',
            'coverurl' => $album['cover'],
            'tracks' => $album['track_count'] ?? '',
            'musicgenre' => $genreName,
            'musicgenres_id' => $genreKey,
        ];
    }

    /** Record the album match, only while the release still carries the title it was looked up with. */
    protected function recordMusicMatch(int $releaseId, int $albumId, ?string $searchname = null): int
    {
        // Only for the title it was matched on: an upgrade may have renamed it meanwhile.
        return Release::query()->where('id', $releaseId)
            ->when($searchname !== null, static fn ($q) => $q->where('searchname', $searchname))
            ->update(['musicinfo_id' => $albumId]);
    }

    // ---------------------------------------------------------------------
    // Wave 55.4 batch helper
    // ---------------------------------------------------------------------

    /** Enabled: minimum of three paired runs cleared every 1.5x gate (common mean 4.91x, p99 3.02x; uncommon mean 5.97x, p99 4.81x); see RustMoviesMusicProcessorMusicPostProcessTvMazeW55ParityTest. */
    private const bool W55_RUST_FFI_DEFAULT = true;

    public const string W55_FFI_CDEF = <<<'C'
        ssize_t nntmux_w554_music_lookup_row_batch(const char *text, size_t text_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** summarizeMusicReleaseLookupBatchPhp() MP3/bitrate tokens. */
    private const array W55_MP3_TOKENS = ['MP3' => true, '128' => true, '192' => true, '256' => true, '320' => true];

    /**
     * summarizeMusicReleaseLookupBatchPhp() per trimmed title and year instead of as totals.
     * Each row yields four words: flags (FLAC 1, MP3/bitrate 2, year 4, artist-album
     * splittable 8, valid year field 16), the ASCII alphanumeric token count, the year field
     * when it is 1000-2999 else the first such title token (-1) and the first hyphen's offset
     * in the trimmed title (-1).
     *
     * @param  list<string>  $titles
     * @param  list<string>  $years
     * @return list<int>
     */
    public static function musicLookupRowBatch(array $titles, array $years): array
    {
        return self::usesW55RustFfi()
            ? self::musicLookupRowBatchNative($titles, $years)
            : self::musicLookupRowBatchPhp($titles, $years);
    }

    /**
     * @param  list<string>  $titles
     * @param  list<string>  $years
     * @return list<int>
     */
    public static function musicLookupRowBatchNative(array $titles, array $years): array
    {
        self::w55AssertColumns($titles, $years);
        $count = \count($titles);
        if ($count === 0) {
            return [];
        }

        return self::w55Call(implode("\0", $titles)."\0".implode("\0", $years), '', $count, 4)
            ?? self::musicLookupRowBatchPhp($titles, $years);
    }

    /**
     * @param  list<string>  $titles
     * @param  list<string>  $years
     * @return list<int>
     */
    public static function musicLookupRowBatchPhp(array $titles, array $years): array
    {
        self::w55AssertColumns($titles, $years);
        $out = [];
        foreach ($titles as $i => $rawTitle) {
            $title = trim($rawTitle);
            $yearField = trim($years[$i]);
            $flags = 0;
            $year = -1;
            if (preg_match('/\A[12][0-9]{3}\z/', $yearField) === 1) {
                $flags = 20;
                $year = (int) $yearField;
            }
            $tokens = preg_split('/[^A-Z0-9]+/', strtoupper($title), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($tokens as $token) {
                if ($token === 'FLAC') {
                    $flags |= 1;
                } elseif (isset(self::W55_MP3_TOKENS[$token])) {
                    $flags |= 2;
                }
                if (preg_match('/\A[12][0-9]{3}\z/', $token) === 1) {
                    $flags |= 4;
                    if ($year < 0) {
                        $year = (int) $token;
                    }
                }
            }
            $hyphen = strpos($title, '-');
            if ($hyphen !== false && trim(substr($title, 0, $hyphen)) !== '' && trim(substr($title, $hyphen + 1)) !== '') {
                $flags |= 8;
            }
            array_push($out, $flags, \count($tokens), $year, $hyphen === false ? -1 : $hyphen);
        }

        return $out;
    }

    /**
     * Equal-length list columns, or InvalidArgumentException.
     *
     * @param  array<array-key, mixed>  ...$columns
     */
    private static function w55AssertColumns(array ...$columns): void
    {
        $count = \count($columns[0]);
        foreach ($columns as $column) {
            if (! array_is_list($column) || \count($column) !== $count) {
                throw new \InvalidArgumentException('W55 batch columns must be lists of equal length.');
            }
        }
    }

    public static function usesW55RustFfi(): bool
    {
        $enabled = self::w55RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W55_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W55 batch dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW55RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w55RustFfiState('enabled');
        self::w55RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w55RustFfiState('enabled', $previous);
        }
    }

    /**
     * One FFI crossing for the batch: NUL-joined text columns and packed int64 header in,
     * `$words` int64 words back per record. Null when the library is missing or declines
     * (a value holding a NUL byte adds a field, which the export rejects).
     *
     * @return list<int>|null
     */
    private static function w55Call(string $text, string $ints, int $count, int $words): ?array
    {
        try {
            $state = self::w55RustFfiState();
            $ffi = $state['ffi'] ?? null;
            if ($ffi === null) {
                $ffi = RustPipelineLibrary::resolveDomain('metadata_w55', self::W55_FFI_CDEF) ?? false;
                self::w55RustFfiState('ffi', $ffi);
            }
            if (! $ffi instanceof FFI) {
                return null;
            }
            $need = $count * $words;
            $out = $state['out'] ?? null;
            $cap = $state['cap'] ?? 0;
            if (! $out instanceof CData || ! \is_int($cap) || $cap < $need) {
                $cap = max(1024, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w55RustFfiState('out', $out);
                self::w55RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W55_FFI_CDEF.)
            $written = $ffi->nntmux_w554_music_lookup_row_batch($text, \strlen($text), $ints, \strlen($ints), $count, $out, $cap);
            if ($written !== $need) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $need * 8));

            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * W55 routing override and cached binding plus reusable output buffer, kept in a function
     * static: no arguments returns the state, one argument reads a key ('reset' clears
     * everything) and two arguments write a key.
     */
    private static function w55RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;
        }

        return $state[$op] ?? null;
    }
}
