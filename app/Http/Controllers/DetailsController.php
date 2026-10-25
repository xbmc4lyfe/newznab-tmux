<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Country;
use App\Models\DnzbFailure;
use App\Models\Predb;
use App\Models\Release;
use App\Models\ReleaseComment;
use App\Models\ReleaseFingerprintMetadata;
use App\Models\ReleaseRegex;
use App\Models\ReleaseReport;
use App\Models\Video;
use App\Services\AnidbService;
use App\Services\BookService;
use App\Services\Configuration\ConfigurationProvider;
use App\Services\ConsoleService;
use App\Services\GamesService;
use App\Services\MovieService;
use App\Services\MusicService;
use App\Services\PopulateAniListService;
use App\Services\ReleaseExtraService;
use App\Services\Releases\ReleaseSearchService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\Request;
use Throwable;

class DetailsController extends BasePageController
{
    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustReleaseModelDetailsFeedSourceParityTest.php is below 1.5x.
     */
    public const bool RUST_FFI_DEFAULT = false;

    private const string DETAILS_PLAN_FFI_CDEF = <<<'C'
        ssize_t nntmux_w374_details_lookup_plan(
            int64_t videos_id,
            int64_t gamesinfo_id,
            int64_t musicinfo_id,
            int64_t bookinfo_id,
            int64_t consoleinfo_id,
            int64_t anidb_id,
            uint32_t flags,
            const char *imdb,
            size_t imdb_len,
            const char *searchname,
            size_t searchname_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /** Lookup names in show() order; bit i of the native mask selects entry i. */
    private const array DETAILS_LOOKUPS = ['video', 'movie', 'game', 'music', 'book', 'console', 'anidb'];

    private const string DETAILS_FIELD_SEPARATOR = "\x1F";

    /** Kept apart from BasePageController's toggle so page helpers are unaffected. */
    private static ?bool $detailsUseRustFfi = null;

    private static FFI|false|null $detailsFfi = null;

    private ReleaseSearchService $releaseSearchService;

    private MovieService $movieService;

    private ReleaseExtraService $releaseExtraService;

    public function __construct(
        ReleaseSearchService $releaseSearchService,
        MovieService $movieService,
        ReleaseExtraService $releaseExtraService
    ) {
        parent::__construct();
        $this->releaseSearchService = $releaseSearchService;
        $this->movieService = $movieService;
        $this->releaseExtraService = $releaseExtraService;
    }

    public function show(Request $request, string $guid): mixed
    {
        $data = Release::getByGuid($guid);
        $releaseRegex = '';
        if (! empty($data)) {
            $releaseRegex = ReleaseRegex::query()->where('releases_id', '=', $data['id'])->first();
        }

        if (! $data) {
            return redirect()->back();
        }

        if ($this->isPostBack($request)) {
            ReleaseComment::addComment((int) $data['id'], (string) $request->input('txtAddComment'), (int) $this->userdata->id, $request->ip());

            return redirect()->route('details', ['guid' => $guid])->with('success', 'Comment posted successfully!');
        }

        $reVideo = $this->releaseExtraService->getVideo($data['id']);
        $reAudio = $this->releaseExtraService->getAudio($data['id']);
        $reSubs = $this->releaseExtraService->getSubs($data['id']);
        $comments = ReleaseComment::getComments($data['id']);
        $similars = $this->releaseSearchService->searchSimilar($data['id'], $data['searchname'], (array) $this->userdata->categoryexclusions);
        $failed = DnzbFailure::getFailedCount($data['id']);
        $reportData = ReleaseReport::query()
            ->with('responder')
            ->where('releases_id', $data['id'])
            ->whereIn('status', ['pending', 'reviewed', 'resolved'])
            ->get();
        $reportCount = $reportData->count();
        $reportReasons = ReleaseReport::reasonKeysToLabels($reportData->pluck('reason')->unique()->implode(', '));
        $originalReportData = ReleaseReport::query()
            ->where('releases_id', $data['id'])
            ->orderByDesc('created_at')
            ->get();
        $totalReportCount = $originalReportData->count();
        $allReportReasons = ReleaseReport::reasonKeysToLabels($originalReportData->pluck('reason')->unique()->implode(', '));
        $publicReportResponses = ReleaseReport::query()
            ->with('responder')
            ->where('releases_id', $data['id'])
            ->where('response_is_public', true)
            ->whereNotNull('response')
            ->where('response', '!=', '')
            ->orderByDesc('responded_at')
            ->get();
        $showInfo = '';
        if ($data['videos_id'] > 0) {
            $showInfo = Video::getByVideoID($data['videos_id']);
        }

        $mov = '';
        if (imdb_id_is_valid($data['imdbid'])) {
            $mov = $this->movieService->getMovieInfo($data['imdbid']);
            if (! empty($mov['title'])) {
                $mov['title'] = str_replace(['/', '\\'], '', $mov['title']);
                if (! empty($mov['actors'])) {
                    $mov['actors'] = makeFieldLinks($mov, 'actors', 'movies');
                }
                if (! empty($mov['genre'])) {
                    $mov['genre'] = makeFieldLinks($mov, 'genre', 'movies');
                }
                if (! empty($mov['director'])) {
                    $mov['director'] = makeFieldLinks($mov, 'director', 'movies');
                }
                if (app(ConfigurationProvider::class)->site()->trailersDisplay) {
                    $trailer = empty($mov['trailer']) ? $this->movieService->getTrailer($data['imdbid']) : $mov['trailer'];
                    if ($trailer) {
                        $mov['trailer'] = sprintf('<iframe width="%d" height="%d" src="%s"></iframe>', app(ConfigurationProvider::class)->site()->trailerWidth, app(ConfigurationProvider::class)->site()->trailerHeight, e($trailer));
                    }
                }
            }
        }

        $game = '';
        if ((int) $data['gamesinfo_id'] > 0) {
            $game = (new GamesService)->getGamesInfoById($data['gamesinfo_id']);
        }

        $mus = '';
        if ((int) $data['musicinfo_id'] > 0) {
            $mus = (new MusicService)->getMusicInfo($data['musicinfo_id']);
        }

        $book = '';
        if ((int) $data['bookinfo_id'] > 0) {
            $book = (new BookService)->getBookInfo($data['bookinfo_id']);
        }

        $con = '';
        if ((int) $data['consoleinfo_id'] > 0) {
            $con = (new ConsoleService)->getConsoleInfo($data['consoleinfo_id']);
        }

        $AniDBAPIArray = '';
        if ($data['anidbid'] > 0) {
            $AniDBAPIArray = (new AnidbService)->getAnimeInfo($data['anidbid']);

            // If we have anilist_id but missing details, fetch from AniList
            if ($AniDBAPIArray && ! empty($AniDBAPIArray->anilist_id)) {
                $anilistId = $AniDBAPIArray->anilist_id;
                if (empty($AniDBAPIArray->country) && empty($AniDBAPIArray->media_type)) {
                    // Fetch fresh data from AniList if country/media_type is missing
                    try {
                        $palist = new PopulateAniListService;
                        $palist->populateTable('info', $anilistId);
                        // Refresh the data
                        $AniDBAPIArray = (new AnidbService)->getAnimeInfo($data['anidbid']);
                    } catch (\Exception $e) {
                        // Silently fail, use existing data
                    }
                }
            }
        }

        $pre = Predb::getForRelease($data['predb_id']);
        $fingerprintMetadata = ReleaseFingerprintMetadata::query()->find((int) $data['id']);

        // Resolve AniDB country code to a Country model/name here so the view stays query-free
        $anidbCountryCode = null;
        if (is_object($AniDBAPIArray)) {
            $anidbCountryCode = $AniDBAPIArray->country ?? null;
        } elseif (is_array($AniDBAPIArray)) {
            $anidbCountryCode = $AniDBAPIArray['country'] ?? null;
        }
        $anidbCountryModel = null;
        $anidbCountryName = null;
        if (! empty($anidbCountryCode)) {
            $anidbCountryModel = Country::query()->find($anidbCountryCode);
            $anidbCountryName = $anidbCountryModel->name ?? $anidbCountryCode;
        }

        $this->viewData = array_merge($this->viewData, [
            'release' => $data,
            'fingerprintMetadata' => $fingerprintMetadata,
            'reVideo' => $reVideo,
            'reAudio' => $reAudio,
            'reSubs' => $reSubs,
            'show' => $showInfo,
            'movie' => $mov,
            'anidb' => $AniDBAPIArray,
            'anidbCountryModel' => $anidbCountryModel,
            'anidbCountryName' => $anidbCountryName,
            'music' => $mus,
            'con' => $con,
            'game' => $game,
            'book' => $book,
            'predb' => $pre,
            'comments' => $comments,
            'searchname' => getSimilarName($data['searchname']),
            'similars' => $similars !== false ? $similars : [],
            'privateprofiles' => config('nntmux_settings.private_profiles'),
            'failed' => $failed,
            'reportCount' => $reportCount,
            'reportReasons' => $reportReasons,
            'originalReportData' => $originalReportData,
            'totalReportCount' => $totalReportCount,
            'allReportReasons' => $allReportReasons,
            'publicReportResponses' => $publicReportResponses,
            'regex' => $releaseRegex,
            'meta_title' => 'View NZB',
            'meta_keywords' => 'view,nzb,description,details',
            'meta_description' => 'View NZB for '.$data['searchname'],
        ]);

        return view('details.index', $this->viewData);
    }

    public static function useRustFfi(?bool $enabled = true): void
    {
        self::$detailsUseRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$detailsUseRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function resetRustFfi(): void
    {
        self::$detailsUseRustFfi = null;
        self::$detailsFfi = null;
    }

    /**
     * Plans the metadata lookups show() performs for a release (positive ids and a valid IMDb id)
     * and its meta description.
     *
     * @return array{lookups: list<string>, lookup_count: int, meta_description: string}
     */
    public static function planDetailsLookups(int $videosId, int|string|null $imdbId, int $gamesinfoId, int $musicinfoId, int $bookinfoId, int $consoleinfoId, int $anidbId, string $searchname): array
    {
        return self::usesRustFfi()
            ? self::planDetailsLookupsNative($videosId, $imdbId, $gamesinfoId, $musicinfoId, $bookinfoId, $consoleinfoId, $anidbId, $searchname)
            : self::planDetailsLookupsPhp($videosId, $imdbId, $gamesinfoId, $musicinfoId, $bookinfoId, $consoleinfoId, $anidbId, $searchname);
    }

    /**
     * @return array{lookups: list<string>, lookup_count: int, meta_description: string}
     */
    private static function planDetailsLookupsNative(int $videosId, int|string|null $imdbId, int $gamesinfoId, int $musicinfoId, int $bookinfoId, int $consoleinfoId, int $anidbId, string $searchname): array
    {
        $fields = self::callDetailsNative([$videosId, $gamesinfoId, $musicinfoId, $bookinfoId, $consoleinfoId, $anidbId], $imdbId === null ? null : (string) $imdbId, $searchname);
        if ($fields === null) {
            return self::planDetailsLookupsPhp($videosId, $imdbId, $gamesinfoId, $musicinfoId, $bookinfoId, $consoleinfoId, $anidbId, $searchname);
        }

        $mask = (int) $fields[1];
        $lookups = [];
        foreach (self::DETAILS_LOOKUPS as $bit => $lookup) {
            if (($mask & (1 << $bit)) !== 0) {
                $lookups[] = $lookup;
            }
        }

        return ['lookups' => $lookups, 'lookup_count' => (int) $fields[0], 'meta_description' => $fields[2]];
    }

    /**
     * @return array{lookups: list<string>, lookup_count: int, meta_description: string}
     */
    private static function planDetailsLookupsPhp(int $videosId, int|string|null $imdbId, int $gamesinfoId, int $musicinfoId, int $bookinfoId, int $consoleinfoId, int $anidbId, string $searchname): array
    {
        $wanted = [
            $videosId > 0,
            imdb_id_is_valid($imdbId),
            $gamesinfoId > 0,
            $musicinfoId > 0,
            $bookinfoId > 0,
            $consoleinfoId > 0,
            $anidbId > 0,
        ];
        $lookups = [];
        foreach (self::DETAILS_LOOKUPS as $index => $lookup) {
            if ($wanted[$index]) {
                $lookups[] = $lookup;
            }
        }

        return ['lookups' => $lookups, 'lookup_count' => count($lookups), 'meta_description' => 'View NZB for '.$searchname];
    }

    /**
     * @param  list<int>  $ids
     * @return array{0: string, 1: string, 2: string}|null
     */
    private static function callDetailsNative(array $ids, ?string $imdb, string $searchname): ?array
    {
        self::$detailsFfi ??= RustPipelineLibrary::resolveDomain('metadata_w374', self::DETAILS_PLAN_FFI_CDEF) ?? false;
        if (self::$detailsFfi === false) {
            return null;
        }
        $ffi = self::$detailsFfi;
        $args = [...$ids, $imdb === null ? 0 : 1, $imdb ?? '', strlen($imdb ?? ''), $searchname, strlen($searchname)];

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, strlen($searchname) + 64, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w374_details_lookup_plan(...[...$args, $buffer, $capacity]);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w374_details_lookup_plan(...[...$args, $buffer, $capacity]);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }
            // The meta description is the last field and may itself contain the separator.
            $fields = explode(self::DETAILS_FIELD_SEPARATOR, FFI::string($buffer, $written), 3);

            return count($fields) === 3 ? [$fields[0], $fields[1], $fields[2]] : null;
        } catch (Throwable) {
            return null;
        }
    }
}
