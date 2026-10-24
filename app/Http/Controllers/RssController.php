<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\UserAccessedApi;
use App\Http\Controllers\Api\RSS;
use App\Models\Category;
use App\Models\User;
use App\Models\UserDownload;
use App\Models\UserRequest;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Throwable;

class RssController extends BasePageController
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_rss_parse_category_ids(const char *input, size_t len, char *out, size_t out_cap);
        int32_t nntmux_rss_show_id(const char *input, size_t len, int64_t *out);
        int32_t nntmux_rss_abs_numeric_int(const char *input, size_t len, int64_t *out);
        C;

    private static FFI|false|null $rssFfi = null;

    private static ?CData $rssIntOut = null;

    private static ?bool $useRustFfi = null;

    private static bool $adoptParseCategoryIdsFfi = false;

    private static bool $adoptScalarParamFfi = false;

    private RSS $rss;

    public function __construct(RSS $rss)
    {
        parent::__construct();
        $this->rss = $rss;
    }

    /**
     * @return JsonResponse|Response
     *
     * @throws Throwable
     */
    public function myMoviesRss(Request $request)
    {
        $user = $this->userCheck($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $outputXML = ! ($request->has('o') && $request->input('o') === 'json');
        $userNum = $request->has('num') && is_numeric($request->input('num')) ? abs((int) $request->input('num')) : 0;

        $relData = $this->rss->getMyMoviesRss($userNum, $user['user_id'], User::getCachedCategoryExclusionById($user['user_id']));

        return $this->rss->output($relData, $user['params'], $outputXML, 0, 'rss');
    }

    /**
     * @return JsonResponse|Response
     *
     * @throws Throwable
     */
    public function myShowsRss(Request $request)
    {
        $user = $this->userCheck($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $userAirDate = $request->has('airdate') && is_numeric($request->input('airdate')) ? abs((int) $request->input('airdate')) : -1;
        $userNum = $request->has('num') && is_numeric($request->input('num')) ? abs((int) $request->input('num')) : 0;
        $outputXML = ! ($request->has('o') && $request->input('o') === 'json');

        $relData = $this->rss->getShowsRss($userNum, $user['user_id'], User::getCachedCategoryExclusionById($user['user_id']), $userAirDate);

        return $this->rss->output($relData, $user['params'], $outputXML, 0, 'rss');
    }

    /**
     * @return JsonResponse|Response
     *
     * @throws Throwable
     */
    public function fullFeedRss(Request $request)
    {
        $user = $this->userCheck($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        [$userShow, $userAnidb, $userAirDate, $userNum, $userLimit, $outputXML] = $this->parseCommonRssParams($request);

        $relData = $this->rss->getRss([0], $userShow, $userAnidb, $user['user_id'], $userAirDate, $userLimit, $userNum);

        return $this->rss->output($relData, $user['params'], $outputXML, 0, 'rss');
    }

    /**
     * @throws \Exception
     */
    public function showRssDesc(): mixed
    {
        $firstShow = $this->rss->getFirstInstance('videos_id', 'releases', 'id');
        $firstAni = $this->rss->getFirstInstance('anidbid', 'releases', 'id');

        $show = ($firstShow !== null) ? $firstShow->videos_id : 1;
        $anidb = ($firstAni !== null) ? $firstAni->anidbid : 1;

        $catExclusions = $this->userdata->categoryexclusions ?? [];

        $this->viewData = array_merge($this->viewData, [
            'show' => $show,
            'anidb' => $anidb,
            'categorylist' => Category::getCategories(true, $catExclusions),
            'parentcategorylist' => Category::getForMenu($catExclusions),
            'title' => 'Rss Info',
            'meta_title' => 'Rss Nzb Info',
            'meta_keywords' => 'view,nzb,description,details,rss,atom',
            'meta_description' => 'View information about NNTmux RSS Feeds.',
        ]);

        return view('rss.rssdesc', $this->viewData);
    }

    /**
     * @return JsonResponse|Response
     *
     * @throws Throwable
     */
    public function cartRss(Request $request)
    {
        $user = $this->userCheck($request);
        if ($user instanceof JsonResponse) {
            $user->headers->add($this->cartRssNoCacheHeaders());

            return $user;
        }

        [$userShow, $userAnidb, $userAirDate, $userNum, $userLimit, $outputXML] = $this->parseCommonRssParams($request);

        $relData = $this->rss->getRss([-2], $userShow, $userAnidb, $user['user_id'], $userAirDate, $userLimit, $userNum);

        return $this->rss->output($relData, $user['params'], $outputXML, 0, 'rss', $this->cartRssNoCacheHeaders());
    }

    /**
     * Cart RSS must reflect user cart changes immediately and must not be reused by browsers, proxies, or CDNs.
     *
     * @return array<string, string>
     */
    private function cartRssNoCacheHeaders(): array
    {
        return [
            'Cache-Control' => 'no-cache, no-store, must-revalidate, private, max-age=0, s-maxage=0',
            'Pragma' => 'no-cache',
            'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT',
            'CDN-Cache-Control' => 'no-store',
            'Cloudflare-CDN-Cache-Control' => 'no-store',
        ];
    }

    /**
     * @return JsonResponse|Response
     *
     * @throws Throwable
     */
    public function categoryFeedRss(Request $request)
    {
        $categoryInput = $this->scalarInput($request, 'id');
        if ($categoryInput === '') {
            return response()->json(['error' => 'Category ID is missing'], 403);
        }

        $user = $this->userCheck($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $categoryId = self::parseCategoryIds($categoryInput);
        if ($categoryId === []) {
            return response()->json(['error' => 'Category ID is missing'], 403);
        }

        [$userShow, $userAnidb, $userAirDate, $userNum, $userLimit, $outputXML] = $this->parseCommonRssParams($request);

        $relData = $this->rss->getRss($categoryId, $userShow, $userAnidb, $user['user_id'], $userAirDate, $userLimit, $userNum);

        return $this->rss->output($relData, $user['params'], $outputXML, 0, 'rss');
    }

    /**
     * @return JsonResponse|Response
     *
     * @throws Throwable
     */
    public function trendingMoviesRss(Request $request)
    {
        $user = $this->userCheck($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $outputXML = ! ($request->has('o') && $request->input('o') === 'json');
        $relData = $this->rss->getTrendingMoviesRss();

        return $this->rss->output($relData, $user['params'], $outputXML, 0, 'rss');
    }

    /**
     * @return JsonResponse|Response
     *
     * @throws Throwable
     */
    public function trendingShowsRss(Request $request)
    {
        $user = $this->userCheck($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $outputXML = ! ($request->has('o') && $request->input('o') === 'json');
        $relData = $this->rss->getTrendingShowsRss();

        return $this->rss->output($relData, $user['params'], $outputXML, 0, 'rss');
    }

    /**
     * Parse common RSS request parameters (show, anidb, airdate, num, limit, outputXML).
     *
     * @return array{int, int, int, int, int|string, bool}
     */
    private function parseCommonRssParams(Request $request): array
    {
        $userShow = $userAnidb = -1;
        if ($request->has('show')) {
            $userShow = self::normalizeFeedId($request->input('show'));
        } elseif ($request->has('anidb')) {
            $userAnidb = self::normalizeFeedId($request->input('anidb'));
        }

        $userAirDate = $request->has('airdate') ? self::absNumericOrDefault($request->input('airdate'), -1) : -1;
        $userNum = $request->has('num') ? self::absNumericOrDefault($request->input('num'), 0) : 0;
        $userLimit = $request->has('limit') && is_numeric($request->input('limit')) ? $request->input('limit') : 100;
        $outputXML = ! ($request->has('o') && $request->input('o') === 'json');

        return [$userShow, $userAnidb, $userAirDate, $userNum, $userLimit, $outputXML];
    }

    public static function setUseRustFfi(?bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::$adoptParseCategoryIdsFfi;
    }

    public static function rssFfi(): ?FFI
    {
        return self::resolveRssFfi();
    }

    /**
     * Category feed ids: comma-separated, trimmed, kept when they match `^-?\d+$`.
     *
     * @return list<string>
     */
    public static function parseCategoryIds(string $categoryInput): array
    {
        if (self::$useRustFfi ?? self::$adoptParseCategoryIdsFfi) {
            $supported = false;
            $native = self::parseCategoryIdsNative($categoryInput, $supported);
            if ($supported && $native !== null) {
                return $native;
            }
        }

        return self::parseCategoryIdsPhp($categoryInput);
    }

    /**
     * @param-out bool $supported
     *
     * @return list<string>|null
     */
    public static function parseCategoryIdsNative(string $categoryInput, bool &$supported = false): ?array
    {
        $supported = false;
        if (self::$useRustFfi === false) {
            return null;
        }
        $ffi = self::resolveRssFfi();
        if ($ffi === null) {
            return null;
        }

        $len = \strlen($categoryInput);
        $capacity = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, $len + 1, $capacity);
        if ($scratch === null) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_rss_parse_category_ids($categoryInput, $len, $scratch, $capacity);
        } catch (Throwable) {
            return null;
        }
        if (! \is_int($written) || $written < 0) {
            return null;
        }
        $supported = true;

        return $written === 0 ? [] : explode(',', FFI::string($scratch, $written));
    }

    /**
     * @return list<string>
     */
    public static function parseCategoryIdsPhp(string $categoryInput): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $categoryInput)),
            static fn (string $categoryId): bool => preg_match('/^-?\d+$/', $categoryId) === 1
        ));
    }

    /**
     * `show` / `anidb` request value: `-1` when it casts to `0`, otherwise its numeric value.
     */
    public static function normalizeFeedId(mixed $value): int|float
    {
        if (self::$useRustFfi ?? self::$adoptScalarParamFfi) {
            $supported = false;
            $native = self::normalizeFeedIdNative($value, $supported);
            if ($supported && $native !== null) {
                return $native;
            }
        }

        return self::normalizeFeedIdPhp($value);
    }

    /**
     * Handles string inputs whose PHP result is an integer; decimals, exponents, overflow and
     * leading-numeric strings (which warn in PHP) defer to {@see normalizeFeedIdPhp()}.
     *
     * @param-out bool $supported
     */
    public static function normalizeFeedIdNative(mixed $value, bool &$supported = false): ?int
    {
        $supported = false;
        if (self::$useRustFfi === false || ! \is_string($value)) {
            return null;
        }
        $ffi = self::resolveRssFfi();
        if ($ffi === null || self::$rssIntOut === null) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_rss_show_id($value, \strlen($value), self::$rssIntOut);
        } catch (Throwable) {
            return null;
        }
        if ($rc !== 1) {
            return null;
        }
        $supported = true;

        return (int) self::$rssIntOut[0];
    }

    public static function normalizeFeedIdPhp(mixed $value): int|float
    {
        return (int) $value === 0 ? -1 : $value + 0;
    }

    /**
     * `airdate` / `num` request value: `abs((int) $value)` when numeric, otherwise `$default`.
     */
    public static function absNumericOrDefault(mixed $value, int $default): int|float
    {
        if (self::$useRustFfi ?? self::$adoptScalarParamFfi) {
            $supported = false;
            $native = self::absNumericOrDefaultNative($value, $default, $supported);
            if ($supported && $native !== null) {
                return $native;
            }
        }

        return self::absNumericOrDefaultPhp($value, $default);
    }

    /**
     * @param-out bool $supported
     */
    public static function absNumericOrDefaultNative(mixed $value, int $default, bool &$supported = false): ?int
    {
        $supported = false;
        if (self::$useRustFfi === false || ! \is_string($value)) {
            return null;
        }
        $ffi = self::resolveRssFfi();
        if ($ffi === null || self::$rssIntOut === null) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_rss_abs_numeric_int($value, \strlen($value), self::$rssIntOut);
        } catch (Throwable) {
            return null;
        }
        if ($rc === 2) {
            $supported = true;

            return $default;
        }
        if ($rc !== 1) {
            return null;
        }
        $supported = true;

        return (int) self::$rssIntOut[0];
    }

    public static function absNumericOrDefaultPhp(mixed $value, int $default): int|float
    {
        return is_numeric($value) ? abs((int) $value) : $default;
    }

    private static function resolveRssFfi(): ?FFI
    {
        if (self::$rssFfi !== null) {
            return self::$rssFfi === false ? null : self::$rssFfi;
        }
        $ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF);
        if ($ffi === null) {
            self::$rssFfi = false;

            return null;
        }
        try {
            self::$rssIntOut = $ffi->new('int64_t[1]');
            self::$rssFfi = $ffi;

            return $ffi;
        } catch (Throwable) {
            self::$rssFfi = false;

            return null;
        }
    }

    /**
     * @return JsonResponse|array<string, mixed>
     *
     * @throws Throwable
     */
    private function userCheck(Request $request): JsonResponse|array
    {
        if ($request->missing('api_token')) {
            return apiJsonError(200, 'Missing parameter (api_token)');
        }

        $res = User::findVerifiedByApiToken((string) $request->input('api_token'));

        if ($res === null) {
            return apiJsonError(100);
        }

        $uid = $res['id'];
        $rssToken = $res['api_token'];
        $maxRequests = $res->role->apirequests;
        $maxDownloads = $res->role->downloadrequests;
        $usedRequests = UserRequest::getApiRequests($uid);
        $time = UserRequest::whereUsersId($uid)->min('timestamp');
        $apiOldestTime = $time !== null ? Carbon::createFromTimeString($time)->toRfc2822String() : '';
        $grabTime = UserDownload::whereUsersId($uid)->min('timestamp');
        $oldestGrabTime = $grabTime !== null ? Carbon::createFromTimeString($grabTime)->toRfc2822String() : '';

        if ($res->is_disabled || $res->hasRole('Disabled')) {
            return apiJsonError(101);
        }

        if ($usedRequests > $maxRequests) {
            return apiJsonError(500, 'Request limit reached ('.$usedRequests.'/'.$maxRequests.')');
        }

        UserRequest::addApiRequest($rssToken, $request->getRequestUri());
        event(new UserAccessedApi($res, $request->ip()));

        $params = [
            'dl' => $request->has('dl') && $request->input('dl') === '1' ? '1' : '0',
            'del' => $request->has('del') && $request->input('del') === '1' ? '1' : '0',
            'extended' => 1,
            'uid' => $uid,
            'token' => $rssToken,
            'apilimit' => $maxRequests,
            'requests' => $usedRequests,
            'downloadlimit' => $maxDownloads,
            'grabs' => UserDownload::getDownloadRequests($uid),
            'oldestapi' => $apiOldestTime,
            'oldestgrab' => $oldestGrabTime,
        ];

        return ['user' => $res, 'user_id' => $uid, 'rss_token' => $rssToken, 'max_requests' => $maxRequests, 'params' => $params];
    }
}
