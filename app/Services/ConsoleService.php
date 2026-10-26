<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ImageAssetProfile;
use App\Enums\SecondarySearchIndex;
use App\Facades\Search;
use App\Models\Category;
use App\Models\ConsoleInfo;
use App\Models\Genre;
use App\Models\Release;
use App\Services\Configuration\ConfigurationProvider;
use App\Services\IGDB\Exceptions\IgdbHttpException;
use App\Support\MetadataSearchLookup;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ConsoleService - Console/Game processing service.
 *
 * Features:
 * - Console game info retrieval and management
 * - Release processing with IGDB lookup
 * - Title parsing and matching
 * - Browse/search functionality
 * - Cover image handling
 */
class ConsoleService
{
    /** Qualified by paired common/uncommon mean and p99 benchmarks (including p99 rank bounds). */
    public const bool W404_RUST_FFI_DEFAULT = true;

    public const string W404_FFI_CDEF = <<<'C'
        ssize_t nntmux_w404_console_platform_batch(const char *input, size_t len, char *out, size_t cap);
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
            $written = $ffi->nntmux_w404_console_platform_batch($input, strlen($input), $out, $capacity);
            if (is_int($written) && $written < -1 && $written >= -16777216) {
                $out = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($out === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound (Declared by W404_FFI_CDEF.)
                $written = $ffi->nntmux_w404_console_platform_batch($input, strlen($input), $out, $capacity);
            }
            if (! is_int($written) || $written <= 0 || $written > $capacity || $written > 16777216) {
                return null;
            }
            $result = @unserialize(FFI::string($out, $written), ['allowed_classes' => false]);
            $keys = ['total_titles', 'nintendo_count', 'playstation_count', 'xbox_count', 'handheld_count', 'retro_count', 'region_tagged_count', 'unknown_platform_count'];
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
            $rows[] = [self::w404Text($row)];
        }

        return $rows;
    }

    /**
     * Summarize a list of titles using ASCII alphanumeric platform/region
     * tokens. Families overlap: NDS counts as Nintendo and Handheld, for example.
     * Unknown means no family matched; malformed values count as empty titles.
     *
     * @param  array<array-key, mixed>  $titles
     * @return array<string, int>
     */
    public static function summarizeConsolePlatformBatch(array $titles): array
    {
        if (self::usesW404RustFfi()) {
            $native = self::w404Call(self::w404Rows($titles));
            if ($native !== null) {
                return $native;
            }
        }

        return self::summarizeConsolePlatformBatchPhp($titles);
    }

    /** @param array<array-key, mixed> $titles
     * @return array<string, int>
     */
    public static function summarizeConsolePlatformBatchPhp(array $titles): array
    {
        $summary = array_fill_keys(['total_titles', 'nintendo_count', 'playstation_count', 'xbox_count', 'handheld_count', 'retro_count', 'region_tagged_count', 'unknown_platform_count'], 0);
        $summary['total_titles'] = count($titles);
        $families = [
            'nintendo_count' => ['NINTENDO', 'SWITCH', 'WII', 'WIIU', 'GAMECUBE', 'NGC', 'N64', 'NES', 'SNES', 'NDS', 'DS', '3DS', 'GBA', 'GBC', 'GAMEBOY'],
            'playstation_count' => ['PLAYSTATION', 'PSX', 'PS1', 'PS2', 'PS3', 'PS4', 'PS5', 'PSP', 'PSV', 'PSVITA', 'VITA'],
            'xbox_count' => ['XBOX', 'X360', 'XONE', 'XSX', 'XSS'],
            'handheld_count' => ['NDS', 'DS', '3DS', 'GBA', 'GBC', 'GAMEBOY', 'PSP', 'PSV', 'PSVITA', 'VITA'],
            'retro_count' => ['NES', 'SNES', 'N64', 'GAMEBOY', 'GBA', 'GBC', 'PSX', 'PS1', 'DREAMCAST', 'GENESIS', 'MEGADRIVE', 'SATURN'],
        ];
        foreach (self::w404Rows($titles) as [$title]) {
            $tokens = preg_split('/[^A-Z0-9]+/', strtoupper($title), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $known = false;
            foreach ($families as $key => $platforms) {
                $matched = array_intersect($platforms, $tokens) !== [];
                $summary[$key] += (int) $matched;
                $known = $known || $matched;
            }
            $summary['region_tagged_count'] += (int) (array_intersect(['USA', 'EUR', 'EUROPE', 'JAP', 'JPN', 'JAPAN', 'PAL', 'NTSC', 'REGIONFREE'], $tokens) !== []);
            $summary['unknown_platform_count'] += (int) ! $known;
        }

        return $summary;
    }

    public const int CONS_UPROC = 0; // Release has not been processed.

    public const int CONS_NTFND = -2;

    private const string FFI_CDEF = <<<'C'
        const char *nntmux_console_parse_title(const char *input, size_t input_len);
        int32_t nntmux_console_replace_platform(const char *input, size_t input_len);
        C;

    /**
     * `replacePlatform()` targets indexed by `nntmux_console_replace_platform` return codes.
     */
    private const array NATIVE_PLATFORM_NAMES = [
        'Xbox 360', 'Xbox One', 'Nintendo DS', 'Nintendo 3DS', 'PlayStation2', 'PlayStation 3',
        'PlayStation 4', 'Sony PSP', 'PlayStation Vita', 'PlayStation', 'Nintendo Wii U',
        'Nintendo Wii', 'GameCube', 'Nintendo 64', 'Nintendo NES', 'SNES',
    ];

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

    private static bool $useRustFfi = true;

    public bool $echoOutput;

    public int $gameQty;

    public int $lookupThrottleMs;

    public string $imgSavePath;

    public bool $renamed;

    /**
     * @var array<string, mixed>
     */
    public array $failCache;

    protected IGDBService $igdbService;

    protected ReleaseImageService $imageService;

    public function __construct(?ReleaseImageService $imageService = null, ?IGDBService $igdbService = null)
    {
        $this->echoOutput = config('nntmux.echocli');
        $this->imageService = $imageService ?? new ReleaseImageService;
        $this->igdbService = $igdbService ?? new IGDBService;

        $this->gameQty = (app(ConfigurationProvider::class)->metadata()->maxGamesProcessed !== '') ? (int) app(ConfigurationProvider::class)->metadata()->maxGamesProcessed : 150;
        $this->lookupThrottleMs = (app(ConfigurationProvider::class)->metadata()->amazonSleepMilliseconds !== '') ? (int) app(ConfigurationProvider::class)->metadata()->amazonSleepMilliseconds : 1000;
        $this->imgSavePath = config('nntmux_settings.covers_path').'/console/';
        $this->renamed = (int) app(ConfigurationProvider::class)->metadata()->gameLookup->value === 2;

        $this->failCache = [];
    }

    public static function setUseRustFfi(bool $use): void
    {
        self::$useRustFfi = $use;
    }

    // ========================================
    // Console Info Retrieval Methods
    // ========================================

    /**
     * Get console info by ID.
     */
    public function getConsoleInfo(int $id): ?Model
    {
        return ConsoleInfo::query()
            ->where('consoleinfo.id', $id)
            ->select('consoleinfo.*', 'genres.title as genres')
            ->leftJoin('genres', 'genres.id', '=', 'consoleinfo.genres_id')
            ->first();
    }

    /**
     * Get console info by name using full-text search.
     */
    public function getConsoleInfoByName(string $title, string $platform): Model|false
    {
        $searchWords = '';

        $title = preg_replace('/( - | -|\(.+\)|\(|\))/', ' ', $title);
        $title = preg_replace('/[^\w ]+/', '', $title);
        $title = trim(trim(preg_replace('/\s\s+/i', ' ', $title)));

        foreach (explode(' ', $title) as $word) {
            $word = trim(rtrim(trim($word), '-'));
            if ($word !== '' && $word !== '-') {
                $word = '+'.$word;
                $searchWords .= sprintf('%s ', $word);
            }
        }
        $searchWords = trim($searchWords.'+'.$platform);

        if (Search::isAvailable()) {
            $q = MetadataSearchLookup::normalizeBooleanSearchWords($searchWords);
            if ($q !== '') {
                $hits = Search::searchSecondary(SecondarySearchIndex::Console, $q, 25);
                $consoleIds = array_values(array_map('intval', $hits['id'] ?? []));
                if ($consoleIds !== []) {
                    $rowsById = ConsoleInfo::query()
                        ->whereIn('id', $consoleIds)
                        ->get()
                        ->keyBy('id');

                    foreach ($consoleIds as $consoleId) {
                        if ($rowsById->has($consoleId)) {
                            /** @var ConsoleInfo $console */
                            $console = $rowsById->get($consoleId);

                            return $console;
                        }
                    }
                }
            }

            return false;
        }

        $row = ConsoleInfo::query()
            ->whereRaw('MATCH (title, platform) AGAINST (? IN BOOLEAN MODE)', [$searchWords])
            ->first();

        return $row ?? false;
    }

    // ========================================
    // Browse/Range Methods
    // ========================================

    /**
     * Get console games range with pagination.
     *
     * @param  array<int|string, mixed>  $cat  Category IDs (list or associative)
     * @param  array<string, mixed>  $excludedCats
     *
     * @throws \Exception
     */
    public function getConsoleRange(int $page, array $cat, int $start, int $num, string $orderBy, array $excludedCats = []): mixed
    {
        $page = max(1, $page);
        $start = max(0, $start);

        $useIndexForTitlePlatform = Search::isAvailable()
            && (! empty($_REQUEST['title']) || ! empty($_REQUEST['platform']));
        $consoleIdsFromSearch = null;
        if ($useIndexForTitlePlatform) {
            $q = trim(
                stripslashes((string) ($_REQUEST['title'] ?? '')).' '
                .stripslashes((string) ($_REQUEST['platform'] ?? ''))
            );
            if ($q === '') {
                $consoleIdsFromSearch = [];
            } else {
                $consoleIdsFromSearch = Search::searchSecondary(SecondarySearchIndex::Console, $q, 5000)['id'];
            }
            if ($consoleIdsFromSearch === []) {
                return collect();
            }
        }

        $browseBy = $this->getBrowseBy($useIndexForTitlePlatform);
        $consoleInClause = '';
        if (is_array($consoleIdsFromSearch) && $consoleIdsFromSearch !== []) {
            $consoleInClause = ' AND con.id IN ('.implode(',', array_map('intval', $consoleIdsFromSearch)).')';
        }
        $catsrch = '';
        if (\count($cat) > 0 && (int) $cat[0] !== -1) {
            $catsrch = Category::getCategorySearch($cat);
        }
        $exccatlist = '';
        if (\count($excludedCats) > 0) {
            $exccatlist = ' AND r.categories_id NOT IN ('.implode(',', $excludedCats).')';
        }
        $order = $this->getConsoleOrder($orderBy);
        $expiresAt = now()->addMinutes(config('nntmux.cache_expiry_medium'));
        $showPasswords = app(Releases\ReleaseBrowseService::class)->showPasswords();

        $baseWhere = "con.title != '' AND con.cover = 1 "
            ."AND r.passwordstatus {$showPasswords} "
            .$browseBy.' '
            .$consoleInClause.' '
            .$catsrch.' '
            .$exccatlist;

        $cacheKey = md5('console_range_'.$baseWhere.$order[0].$order[1].$start.$num.$page);

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Step 1: Count total distinct consoles matching filters
        $countSql = 'SELECT COUNT(DISTINCT con.id) AS total '
            .'FROM consoleinfo con '
            .'INNER JOIN releases r ON con.id = r.consoleinfo_id '
            .'WHERE '.$baseWhere;

        $totalResult = DB::select($countSql);
        $totalCount = $totalResult[0]->total ?? 0;

        if ($totalCount === 0) {
            return collect();
        }

        // Step 2: Get paginated console entity list with only needed columns
        $consoleSql = 'SELECT con.id, con.title, con.cover, con.publisher, con.releasedate, con.review, con.url, '
            .'con.genres_id, genres.title AS genre, '
            .'MAX(r.postdate) AS latest_postdate, '
            .'COUNT(r.id) AS total_releases '
            .'FROM consoleinfo con '
            .'INNER JOIN releases r ON con.id = r.consoleinfo_id '
            .'LEFT JOIN genres ON con.genres_id = genres.id '
            .'WHERE '.$baseWhere.' '
            .'GROUP BY con.id, con.title, con.cover, con.publisher, con.releasedate, con.review, con.url, con.genres_id, genres.title '
            ."ORDER BY {$order[0]} {$order[1]} "
            ."LIMIT {$num} OFFSET {$start}";

        $consoles = ConsoleInfo::fromQuery($consoleSql);

        if ($consoles->isEmpty()) {
            return collect();
        }

        // Build list of console IDs for release query
        $consoleIds = $consoles->pluck('id')->toArray();
        $inConsoleIds = implode(',', array_map('intval', $consoleIds));

        // Step 3: Get top 2 releases per console using ROW_NUMBER()
        $releasesSql = 'SELECT ranked.id, ranked.consoleinfo_id, ranked.guid, ranked.searchname, '
            .'ranked.size, ranked.postdate, ranked.adddate, ranked.haspreview, ranked.grabs, '
            .'ranked.comments, ranked.totalpart, ranked.group_name, ranked.nfoid, ranked.failed_count '
            .'FROM ( '
            .'SELECT r.id, r.consoleinfo_id, r.guid, r.searchname, r.size, r.postdate, r.adddate, '
            .'r.haspreview, r.grabs, r.comments, r.totalpart, g.name AS group_name, '
            .'rn.releases_id AS nfoid, df.failed AS failed_count, '
            .'ROW_NUMBER() OVER (PARTITION BY r.consoleinfo_id ORDER BY r.postdate DESC) AS rn '
            .'FROM releases r '
            .'LEFT JOIN usenet_groups g ON g.id = r.groups_id '
            .'LEFT JOIN release_nfos rn ON rn.releases_id = r.id '
            .'LEFT JOIN dnzb_failures df ON df.release_id = r.id '
            ."WHERE r.consoleinfo_id IN ({$inConsoleIds}) "
            ."AND r.passwordstatus {$showPasswords} "
            .$catsrch.' '
            .$exccatlist
            .') ranked '
            .'WHERE ranked.rn <= 2 '
            .'ORDER BY ranked.consoleinfo_id, ranked.postdate DESC';

        $releases = DB::select($releasesSql);

        // Group releases by consoleinfo_id for fast lookup
        $releasesByConsole = [];
        foreach ($releases as $release) {
            $releasesByConsole[$release->consoleinfo_id][] = $release;
        }

        // Attach releases to each console entity
        foreach ($consoles as $console) {
            $console->releases = $releasesByConsole[$console->id] ?? []; // @phpstan-ignore assign.propertyReadOnly
        }

        // Set total count on first item
        if ($consoles->isNotEmpty()) {
            $consoles[0]->_totalcount = $totalCount; // @phpstan-ignore property.notFound
        }

        Cache::put($cacheKey, $consoles, $expiresAt);

        return $consoles;
    }

    /**
     * Get console order array.
     *
     * @return array<string, mixed>
     */
    /**
     * @return array{0: string, 1: string}
     */
    public function getConsoleOrder(string $orderBy): array
    {
        $order = ($orderBy === '') ? 'r.postdate' : $orderBy;
        $orderArr = explode('_', $order);

        $orderfield = match ($orderArr[0]) {
            'title' => 'con.title',
            'platform' => 'con.platform',
            'releasedate' => 'con.releasedate',
            'genre' => 'con.genres_id',
            'size' => 'r.size',
            'files' => 'r.totalpart',
            'stats' => 'r.grabs',
            default => 'r.postdate',
        };

        $ordersort = (isset($orderArr[1]) && preg_match('/^asc|desc$/i', $orderArr[1])) ? $orderArr[1] : 'desc';

        return [$orderfield, $ordersort];
    }

    /**
     * Get console ordering options.
     *
     * @return array<int, string>
     */
    public function getConsoleOrdering(): array
    {
        return [
            'title_asc', 'title_desc',
            'posted_asc', 'posted_desc',
            'size_asc', 'size_desc',
            'files_asc', 'files_desc',
            'stats_asc', 'stats_desc',
            'platform_asc', 'platform_desc',
            'releasedate_asc', 'releasedate_desc',
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
        return ['platform' => 'platform', 'title' => 'title', 'genre' => 'genres_id'];
    }

    /**
     * Get browse by SQL clause.
     */
    public function getBrowseBy(bool $skipTitlePlatformLike = false): string
    {
        $browseBy = ' ';
        foreach ($this->getBrowseByOptions() as $bbk => $bbv) {
            if ($skipTitlePlatformLike && ($bbk === 'title' || $bbk === 'platform')) {
                continue;
            }
            if (! empty($_REQUEST[$bbk])) {
                $bbs = stripslashes($_REQUEST[$bbk]);
                if (stripos($bbv, 'id') !== false) {
                    $browseBy .= ' AND con.'.$bbv.' = '.(int) $bbs;
                } else {
                    $browseBy .= ' AND con.'.$bbv.' LIKE '.escapeString('%'.$bbs.'%');
                }
            }
        }

        return $browseBy;
    }

    // ========================================
    // Update Methods
    // ========================================

    /**
     * Update console info record.
     */
    public function update(
        int $id,
        string $title,
        ?string $asin,
        ?string $url,
        ?int $salesrank,
        ?string $platform,
        ?string $publisher,
        ?string $releasedate,
        ?string $esrb,
        int $cover,
        ?int $genresId,
        string $review = 'review'
    ): void {
        $releasedate = $releasedate !== '' ? $releasedate : null;
        $review = $review === 'review' ? $review : substr($review, 0, 3000);

        ConsoleInfo::query()
            ->where('id', $id)
            ->update([
                'title' => $title,
                'asin' => $asin,
                'url' => $url,
                'salesrank' => $salesrank,
                'platform' => $platform,
                'publisher' => $publisher,
                'releasedate' => $releasedate,
                'esrb' => $esrb,
                'cover' => $cover,
                'genres_id' => $genresId,
                'review' => $review,
            ]);
    }

    // ========================================
    // IGDB Integration Methods
    // ========================================

    /**
     * Update console info from IGDB.
     *
     *
     * @param  array<string, mixed>  $gameInfo
     *
     * @throws \Exception
     */
    public function updateConsoleInfo(array $gameInfo): int
    {
        $consoleId = self::CONS_NTFND;

        $igdb = $this->fetchIGDBProperties($gameInfo['title'], $gameInfo['platform']);
        if ($igdb !== false) {
            if ($igdb['coverurl'] !== '') {
                $igdb['cover'] = 1;
            } else {
                $igdb['cover'] = 0;
            }

            $consoleId = $this->updateConsoleTable($igdb);

            if ($this->echoOutput && $consoleId !== -2) {
                cli()->header('Added/updated game: ').
                    cli()->alternateOver('   Title:    ').
                    cli()->primary($igdb['title']).
                    cli()->alternateOver('   Platform: ').
                    cli()->primary($igdb['platform']).
                    cli()->alternateOver('   Genre: ').
                    cli()->primary($igdb['consolegenre']);
            }
        }

        return $consoleId;
    }

    /**
     * Fetch IGDB properties for a game.
     *
     * @return array<string, mixed>
     *
     * @throws \Exception
     */
    public function fetchIGDBProperties(string $gameInfo, string $gamePlatform): bool|array|\StdClass
    {
        $gamePlatform = $this->replacePlatform($gamePlatform);

        if (! $this->igdbService->isConfigured()) {
            return false;
        }

        try {
            $game = $this->igdbService->searchConsole($gameInfo, $gamePlatform);
            if ($game === null) {
                cli()->notice('IGDB found no valid results');

                return false;
            }

            $igdb = $this->igdbService->buildConsoleData($game, $gamePlatform);
            $igdb['consolegenreid'] = $this->getGenreKey($igdb['consolegenre']);

            return $igdb;
        } catch (IgdbHttpException $e) {
            if ($e->getStatusCode() === 429) {
                return false;
            }
        } catch (\Exception $e) {
            cli()->error('Error fetching IGDB properties: '.$e->getMessage());

            return false;
        }

        return false;
    }

    // ========================================
    // Release Processing Methods
    // ========================================

    /**
     * Process console releases.
     *
     * @throws \Exception
     */
    public function processConsoleReleases(string $groupID = '', string $guidChar = ''): void
    {
        $query = Release::query()
            ->select(['searchname', 'id'])
            ->whereBetween('categories_id', [Category::GAME_ROOT, Category::GAME_OTHER])
            ->whereNull('consoleinfo_id');
        Release::whereNzbSettled($query);

        if ($guidChar !== '') {
            $query->where('leftguid', 'like', $guidChar.'%');
        }

        if ($groupID !== '') {
            $query->where('groups_id', $groupID);
        }

        if ($this->renamed === true) {
            $query->where('isrenamed', '=', 1);
        }

        $res = $query->limit($this->gameQty)->orderBy('postdate')->get();

        $releaseCount = $res->count();
        if ($res instanceof \Traversable && $releaseCount > 0) {
            if ($this->echoOutput) {
                cli()->header('Processing '.$releaseCount.' console release(s).');
            }

            foreach ($res as $arr) {
                $startTime = now()->timestamp;
                $usedExternalLookup = false;
                $gameId = self::CONS_NTFND;
                $gameInfo = $this->parseTitle($arr['searchname']);

                if ($gameInfo !== false) {
                    if ($this->echoOutput) {
                        cli()->info('Looking up: '.$gameInfo['title'].' ('.$gameInfo['platform'].')');
                    }

                    // Check for existing console entry.
                    $gameCheck = $this->getConsoleInfoByName($gameInfo['title'], $gameInfo['platform']);

                    if ($gameCheck === false && \in_array($gameInfo['title'].$gameInfo['platform'], $this->failCache, true)) {
                        // Lookup recently failed, no point trying again
                        if ($this->echoOutput) {
                            cli()->info('Cached previous failure. Skipping.');
                        }
                        $gameId = -2;
                    } elseif ($gameCheck === false) {
                        $gameId = $this->updateConsoleInfo($gameInfo);
                        $usedExternalLookup = true;
                        if ($gameId === self::CONS_NTFND) {
                            $this->failCache[] = $gameInfo['title'].$gameInfo['platform'];
                        }
                    } else {
                        if ($this->echoOutput) {
                            cli()->headerOver('Found Local: ').
                                cli()->primary("{$gameInfo['title']} - {$gameInfo['platform']}");
                        }
                        $gameId = $gameCheck['id'] ?? -2;
                    }
                } elseif ($this->echoOutput) {
                    echo '.';
                }

                // Update release.
                $this->recordConsoleMatch((int) $arr['id'], (int) $gameId, (string) $arr['searchname']);

                // Throttle external lookups using the legacy amazonsleep setting.
                $diff = floor((now()->timestamp - $startTime) * 1000000);
                if ($this->lookupThrottleMs * 1000 - $diff > 0 && $usedExternalLookup === true) {
                    usleep((int) ($this->lookupThrottleMs * 1000 - $diff));
                }
            }
        } elseif ($this->echoOutput) {
            cli()->header('No console releases to process.');
        }
    }

    // ========================================
    // Title Parsing Methods
    // ========================================

    /**
     * Parse release title for game info.
     *
     * @return array<string, mixed>
     */
    public function parseTitle(string $releaseName): array|false
    {
        if (self::$useRustFfi) {
            $native = self::parseTitleNative($releaseName);
            if ($native !== null) {
                return $native;
            }
        }

        return $this->parseTitlePhp($releaseName);
    }

    /**
     * Rust `nntmux_console_parse_title`; `null` when the library is unavailable or declines the input.
     *
     * @return array<string, string>|false|null
     */
    public static function parseTitleNative(string $releaseName): array|false|null
    {
        $ffi = self::$ffi ?? (self::$ffiResolved ? null : self::ffi());
        if ($ffi === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $parsed = $ffi->nntmux_console_parse_title($releaseName, strlen($releaseName));
        } catch (Throwable) {
            return null;
        }
        if (! is_string($parsed)) {
            return null;
        }
        if ($parsed === '') {
            return false;
        }
        $fields = explode("\x1F", $parsed, 3);
        if (count($fields) !== 3) {
            return null;
        }
        [$title, $platform, $release] = $fields;
        if ($title[0] === '1') {
            return ['title' => substr($title, 1), 'dlc' => '1', 'platform' => $platform, 'release' => $release];
        }

        return ['title' => substr($title, 1), 'platform' => $platform, 'release' => $release];
    }

    /**
     * Native PHP `parseTitle()` implementation and fallback for inputs the Rust path declines.
     *
     * @return array<string, mixed>|false
     */
    protected function parseTitlePhp(string $releaseName): array|false
    {
        $releaseName = preg_replace('/\sMulti\d?\s/i', '', $releaseName);
        $result = [];

        // Get name of the game from name of release.
        if (preg_match('/^(.+((abgx360EFNet|EFNet\sFULL|FULL\sabgxEFNet|abgx\sFULL|abgxbox360EFNet)\s|illuminatenboard\sorg|Place2(hom|us)e.net|united-forums? co uk|\(\d+\)))?(?P<title>.*?)[\.\-_ ](v\.?\d\.\d|PAL|NTSC|EUR|USA|JP|ASIA|JAP|JPN|AUS|MULTI(\.?\d{1,2})?|PATCHED|FULLDVD|DVD5|DVD9|DVDRIP|PROPER|REPACK|RETAIL|DEMO|DISTRIBUTION|REGIONFREE|[\. ]RF[\. ]?|READ\.?NFO|NFOFIX|PSX(2PSP)?|PS[2-4]|PSP|PSVITA|WIIU|WII|X\-?BOX|XBLA|X360|3DS|NDS|N64|NGC)/i', $releaseName, $hits)) {
            $title = $hits['title'];

            // Replace dots, underscores, or brackets with spaces.
            $result['title'] = str_replace(['.', '_', '%20', '[', ']'], ' ', $title);
            $result['title'] = str_replace([' RF ', '.RF.', '-RF-', '_RF_'], ' ', $result['title']);
            // Remove format tags from release title for match
            $result['title'] = trim(preg_replace('/PAL|MULTI(\d)?|NTSC-?J?|\(JAPAN\)/i', '', $result['title']));
            // Remove disc tags from release title for match
            $result['title'] = trim(preg_replace('/Dis[ck] \d.*$/i', '', $result['title']));

            // Needed to add code to handle DLC Properly.
            if (stripos($result['title'], 'dlc') !== false) {
                $result['dlc'] = '1';
                if (stripos($result['title'], 'Rock Band Network') !== false) {
                    $result['title'] = 'Rock Band';
                } elseif (str_contains($result['title'], '-')) {
                    $dlc = explode('-', $result['title']);
                    $result['title'] = $dlc[0];
                } elseif (preg_match('/(.*? .*?) /i', $result['title'], $dlc)) {
                    $result['title'] = $dlc[0];
                }
            }
        } else {
            $title = '';
        }

        // Get the platform of the release.
        if (preg_match('/[\.\-_ ](?P<platform>XBLA|WiiWARE|N64|SNES|NES|PS[2-4]|PS 3|PSX(2PSP)?|PSP|WIIU|WII|XBOX360|XBOXONE|X\-?BOX|X360|3DS|NDS|N?GC)/i', $releaseName, $hits)) {
            $platform = $hits['platform'];

            if (preg_match('/^N?GC$/i', $platform)) {
                $platform = 'NGC';
            }

            if (stripos($platform, 'PSX2PSP') === 0) {
                $platform = 'PSX';
            }

            if (! empty($title) && stripos($platform, 'XBLA') === 0 && stripos($title, 'dlc') !== false) {
                $platform = 'XBOX360';
            }

            $result['platform'] = $platform;
        }

        $result['release'] = $releaseName;
        $result = array_map('trim', $result);

        return (isset($result['title'], $result['platform']) && ! empty($result['title'])) ? $result : false;
    }

    // ========================================
    // Platform Methods
    // ========================================

    /**
     * Normalize a parsed release platform name to the external lookup equivalent.
     */
    public function replacePlatform(string $platform): string
    {
        // Native PHP: the Rust lookup cannot amortize the FFI call (see RustMusicConsoleIgdbParityTest).
        return match (strtoupper($platform)) {
            'X360', 'XBOX360' => 'Xbox 360',
            'XBOXONE', 'XBOX ONE' => 'Xbox One',
            'DSI', 'NDS' => 'Nintendo DS',
            '3DS' => 'Nintendo 3DS',
            'PS2' => 'PlayStation2',
            'PS3' => 'PlayStation 3',
            'PS4' => 'PlayStation 4',
            'PSP' => 'Sony PSP',
            'PSVITA' => 'PlayStation Vita',
            'PSX', 'PSX2PSP' => 'PlayStation',
            'WIIU' => 'Nintendo Wii U',
            'WII' => 'Nintendo Wii',
            'NGC' => 'GameCube',
            'N64' => 'Nintendo 64',
            'NES' => 'Nintendo NES',
            'SUPER NINTENDO', 'NINTENDO SUPER NES', 'SNES' => 'SNES',
            default => $platform,
        };
    }

    /**
     * Rust `nntmux_console_replace_platform` for parity testing; `null` when the library is unavailable.
     */
    public static function replacePlatformNative(string $platform): ?string
    {
        $ffi = self::$ffi ?? (self::$ffiResolved ? null : self::ffi());
        if ($ffi === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $code = $ffi->nntmux_console_replace_platform($platform, strlen($platform));
        } catch (Throwable) {
            return null;
        }
        if ($code === -2) {
            return $platform;
        }

        return is_int($code) ? (self::NATIVE_PLATFORM_NAMES[$code] ?? null) : null;
    }

    private static function ffi(): ?FFI
    {
        if (! self::$ffiResolved) {
            self::$ffi = RustPipelineLibrary::resolveDomain('console_parse_title', self::FFI_CDEF);
            self::$ffiResolved = true;
        }

        return self::$ffi;
    }

    // ========================================
    // Protected Helper Methods
    // ========================================

    /**
     * Update or create console info in the database.
     *
     * @param  array<string, mixed>  $con
     */
    protected function updateConsoleTable(array $con = []): int
    {
        $asin = isset($con['asin']) ? (string) $con['asin'] : null;
        $check = ConsoleInfo::query()->where('asin', $asin)->first();

        if ($check === null) {
            $consoleId = ConsoleInfo::query()
                ->insertGetId([
                    'title' => $con['title'],
                    'asin' => $asin,
                    'url' => $con['url'],
                    'salesrank' => $con['salesrank'],
                    'platform' => $con['platform'],
                    'publisher' => $con['publisher'],
                    'genres_id' => (int) $con['consolegenreid'] === -1 ? null : $con['consolegenreid'],
                    'esrb' => $con['esrb'],
                    'releasedate' => $con['releasedate'] !== '' ? $con['releasedate'] : null,
                    'review' => substr($con['review'], 0, 3000),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($con['cover'] === 1) {
                $con['cover'] = (int) $this->imageService->saveRemoteImage(
                    (string) $consoleId,
                    $con['coverurl'],
                    $this->imgSavePath,
                    ImageAssetProfile::MetadataCover,
                )->success;
            }
        } else {
            $consoleId = $check['id'];

            if ($con['cover'] === 1) {
                $con['cover'] = (int) $this->imageService->saveRemoteImage(
                    (string) $consoleId,
                    $con['coverurl'],
                    $this->imgSavePath,
                    ImageAssetProfile::MetadataCover,
                )->success;
            }

            $this->update(
                $consoleId,
                $con['title'],
                isset($con['asin']) ? (string) $con['asin'] : null,
                $con['url'],
                isset($con['salesrank']) && $con['salesrank'] !== '' ? (int) $con['salesrank'] : null,
                $con['platform'],
                $con['publisher'],
                $con['releasedate'] ?? null,
                $con['esrb'],
                $con['cover'],
                $con['consolegenreid'],
                $con['review'] ?? null
            );
        }

        return $consoleId;
    }

    /**
     * Get or create genre key.
     *
     *
     * @throws \Exception
     */
    protected function getGenreKey(string $genreName): false|int|string
    {
        $genreassoc = $this->loadGenres();

        if (\in_array(strtolower($genreName), $genreassoc, true)) {
            $genreKey = array_search(strtolower($genreName), $genreassoc, true);
        } else {
            $genreKey = Genre::query()->insertGetId(['title' => $genreName, 'type' => GenreService::CONSOLE_TYPE]);
        }

        return $genreKey;
    }

    /**
     * Load genres from database.
     *
     * @return array<string, mixed>
     *
     * @throws \Exception
     */
    protected function loadGenres(): array
    {
        $gen = new GenreService;

        return $gen->loadGenres((string) GenreService::CONSOLE_TYPE);
    }

    /** Record the console match, only while the release still carries the title it was looked up with. */
    protected function recordConsoleMatch(int $releaseId, int $gameId, ?string $searchname = null): int
    {
        // Only for the title it was matched on: an upgrade may have renamed it meanwhile.
        return Release::query()->where('id', $releaseId)
            ->when($searchname !== null, static fn ($q) => $q->where('searchname', $searchname))
            ->update(['consoleinfo_id' => $gameId]);
    }

    // ---- Wave 61.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 7.81x/5.41x, uncommon 6.88x/6.46x); see RustCategorizersTvXxxConsoleGamesServiceTitleParserW61ParityTest. */
    public const bool W61_RUST_FFI_DEFAULT = true;

    private const string W61_CONSOLE_SERVICE_FFI_CDEF = <<<'C'
        intptr_t nntmux_w612_console_lookup_plan_batch(const char *items, size_t items_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Summary words after the per-row words, the digest included. */
    public const int W61_CONSOLE_SERVICE_TAIL = 30;

    /** Lowercase token => `platform | region << 5 | flags << 11`. */
    private const array W61_CONSOLE_TOKENS = [
        'xbla' => 1, 'wiiware' => 2, 'n64' => 3, 'snes' => 4, 'nes' => 5, 'ps2' => 6, 'ps3' => 7, 'ps4' => 8, 'ps5' => 9, 'psx' => 10, 'psx2psp' => 11,
        'psp' => 12, 'psvita' => 13, 'vita' => 14, 'wiiu' => 15, 'wii' => 16, 'xbox360' => 17, 'xboxone' => 18, 'xbox' => 19, 'x360' => 20, '3ds' => 21,
        'nds' => 22, 'ngc' => 23, 'gc' => 24, 'switch' => 25, 'nsw' => 26,
        'pal' => 1 << 5, 'eur' => 1 << 5, 'eu' => 1 << 5, 'euro' => 1 << 5, 'ntsc' => 2 << 5, 'usa' => 2 << 5,
        'jpn' => 4 << 5, 'jap' => 4 << 5, 'jp' => 4 << 5, 'japan' => 4 << 5, 'ntscj' => 4 << 5, 'asia' => 8 << 5, 'kor' => 8 << 5, 'aus' => 16 << 5,
        'regionfree' => 32 << 5, 'rf' => 32 << 5, 'rfree' => 32 << 5,
        'proper' => 2 << 11, 'repack' => 4 << 11, 'retail' => 8 << 11, 'demo' => 16 << 11, 'dlc' => 32 << 11, 'patched' => 64 << 11,
        'nfofix' => 128 << 11, 'readnfo' => 128 << 11, 'dvd5' => 256 << 11, 'dvd9' => 256 << 11, 'fulldvd' => 256 << 11, 'dvdrip' => 256 << 11,
        'update' => 1024 << 11, 'patch' => 1024 << 11, 'iso' => 2048 << 11,
    ];

    /** replacePlatform() target (NATIVE_PLATFORM_NAMES index + 1, 0 unmapped) per W61_CONSOLE_TOKENS platform. */
    public const array W61_CONSOLE_LOOKUP = [0, 0, 0, 14, 16, 15, 5, 6, 7, 0, 10, 10, 8, 9, 9, 11, 12, 1, 2, 0, 1, 4, 3, 13, 13, 0, 0];

    /** Platform family (1 Xbox, 2 PlayStation, 3 Nintendo) per W61_CONSOLE_TOKENS platform. */
    public const array W61_CONSOLE_FAMILY = [0, 1, 3, 3, 3, 3, 2, 2, 2, 2, 2, 2, 2, 2, 2, 3, 3, 1, 1, 1, 1, 3, 3, 3, 3, 3, 3];

    /**
     * Wave 61 routing, binding and output buffer, kept in function-local statics (no static
     * properties). Operations: null reads the routing override, 'set' replaces it (returning the
     * previous one), 'ffi' resolves the binding and 'out' returns `[binding, buffer, capacity]`
     * with room for at least $val words (null without the library).
     */
    private static function w61RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w61', self::W61_CONSOLE_SERVICE_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w61RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    public static function usesW61RustFfi(): bool
    {
        $enabled = self::w61RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W61_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with w61ConsoleLookupPlanBatch() routed to Rust ($enabled) or PHP, then restores the
     * previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW61RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w61RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w61RustFfiState('set', $previous);
        }
    }

    /**
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w61ConsoleLookupPlanBatch(array $rows): array
    {
        return self::usesW61RustFfi() ? self::w61ConsoleLookupPlanBatchNative($rows) : self::w61ConsoleLookupPlanBatchPhp($rows);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a row holds a NUL byte or the
     * column is not a list.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w61ConsoleLookupPlanBatchNative(array $rows): array
    {
        $count = \count($rows);
        $words = $count + self::W61_CONSOLE_SERVICE_TAIL;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w61RustFfiState('out', $words);
        if ($state === null || ! array_is_list($rows)) {
            return self::w61ConsoleLookupPlanBatchPhp($rows);
        }
        $blob = implode("\0", $rows);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W61_CONSOLE_SERVICE_FFI_CDEF.)
            $written = $ffi->nntmux_w612_console_lookup_plan_batch($blob, \strlen($blob), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : self::w61ConsoleLookupPlanBatchPhp($rows);
    }

    /** @param  list<int>  $out */
    private static function w61Digest(array $out): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));

        return $digest[1];
    }

    /** $min to $max ASCII digits as an integer, otherwise null. */
    private static function w61Digits(string $value, int $min, int $max): ?int
    {
        $length = \strlen($value);

        return $length >= $min && $length <= $max && strspn($value, '0123456789') === $length ? (int) $value : null;
    }

    /**
     * The maximal `[a-z0-9]` runs of the ASCII-lowercased value.
     *
     * @return list<string>
     */
    private static function w61Tokens(string $value): array
    {
        $tokens = $value === '' ? false : preg_split('/[^a-z0-9]+/', strtolower($value), -1, PREG_SPLIT_NO_EMPTY);

        return $tokens === false ? [] : $tokens;
    }

    /**
     * Wave 61.2 console title, platform, region, group and lookup query plan. Per release-name
     * row `platform | family << 5 | lookup << 7 | region << 12 | flags << 18 | decision << 30 |
     * min(title tokens, 31) << 32 | min(len(query), 255) << 37 | group << 45 | (crc32(query) &
     * 0x1FFFF) << 46` over the w61Tokens() of the row: platform is the first W61_CONSOLE_TOKENS
     * platform (or 19 for an `x` token followed by `box`), with its W61_CONSOLE_FAMILY and
     * W61_CONSOLE_LOOKUP (1, Xbox 360, for XBLA DLC); region (1 PAL/EU, 2 NTSC/USA, 4 Japan, 8
     * Asia, 16 Australia, 32 region free) and flags (1 `multi` + 0-2 digits, 2 proper, 4 repack,
     * 8 retail, 16 demo, 32 DLC, 64 patched, 128 NFO fix, 256 DVD, 512 `v` + 1-7 digits, 1024
     * update, 2048 ISO) OR their tokens. The title is the tokens before the first platform,
     * region or flag token, the query those tokens joined by single spaces. Group: the suffix
     * after the last `-` is 1-20 bytes of `[A-Za-z0-9_]`. Decision: 0 no title, 1 no platform,
     * 3 DLC, else 2. Then four decision, four family, six region and twelve flag counts, the
     * distinct queries of rows deciding 2 or 3 and the distinct lowercase groups (by crc32),
     * the title tokens and the XXH64 digest of all earlier words.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w61ConsoleLookupPlanBatchPhp(array $rows): array
    {
        $out = $queries = $groups = [];
        $decisions = $families = [0, 0, 0, 0];
        $regions = array_fill(0, 6, 0);
        $flagCounts = array_fill(0, 12, 0);
        $titleTotal = 0;
        foreach ($rows as $row) {
            $row = (string) $row;
            $group = 0;
            $dash = strrpos($row, '-');
            if ($dash !== false) {
                $suffix = substr($row, $dash + 1);
                $length = \strlen($suffix);
                if ($length >= 1 && $length <= 20 && strspn($suffix, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_') === $length) {
                    $group = 1;
                    $groups[crc32(strtolower($suffix))] = true;
                }
            }
            $tokens = self::w61Tokens($row);
            $count = \count($tokens);
            $platform = $region = $flags = 0;
            $stop = $count;
            foreach ($tokens as $i => $token) {
                $code = self::W61_CONSOLE_TOKENS[$token] ?? 0;
                $id = $token === 'x' && ($tokens[$i + 1] ?? '') === 'box' ? 19 : $code & 31;
                $f = $code >> 11;
                if (str_starts_with($token, 'multi') && (\strlen($token) === 5 || self::w61Digits(substr($token, 5), 1, 2) !== null)) {
                    $f |= 1;
                }
                if ($token[0] === 'v' && self::w61Digits(substr($token, 1), 1, 7) !== null) {
                    $f |= 512;
                }
                $region |= $code >> 5 & 63;
                $flags |= $f;
                if ($platform === 0) {
                    $platform = $id;
                }
                if ($stop === $count && ($id !== 0 || $code !== 0 || $f !== 0)) {
                    $stop = $i;
                }
            }
            $query = implode(' ', \array_slice($tokens, 0, $stop));
            $dlc = ($flags & 32) !== 0;
            $lookup = $platform === 1 && $dlc ? 1 : self::W61_CONSOLE_LOOKUP[$platform];
            $family = self::W61_CONSOLE_FAMILY[$platform];
            $decision = $stop === 0 ? 0 : ($platform === 0 ? 1 : ($dlc ? 3 : 2));
            $crc = crc32($query);
            $decisions[$decision]++;
            $families[$family]++;
            for ($k = 0; $k < 6; $k++) {
                $regions[$k] += $region >> $k & 1;
            }
            for ($k = 0; $k < 12; $k++) {
                $flagCounts[$k] += $flags >> $k & 1;
            }
            if ($decision >= 2) {
                $queries[$crc] = true;
            }
            $titleTotal += $stop;
            $out[] = $platform | $family << 5 | $lookup << 7 | $region << 12 | $flags << 18 | $decision << 30 | min($stop, 31) << 32
                | min(\strlen($query), 255) << 37 | $group << 45 | ($crc & 0x1FFFF) << 46;
        }
        array_push($out, ...$decisions, ...$families, ...$regions, ...$flagCounts);
        array_push($out, \count($queries), \count($groups), $titleTotal);
        $out[] = self::w61Digest($out);

        return $out;
    }
}
