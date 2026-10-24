<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Release;
use App\Models\User;
use App\Models\UserDownload;
use App\Models\UsersRelease;
use App\Services\Nzb\NzbService;
use App\Support\FilenameSanitizer;
use App\Support\RustPipelineLibrary;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use STS\ZipStream\Builder;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GetNzbController extends BasePageController
{
    // Keep PHP as the production default until the Rust performance gate passes.
    private static bool $useRustFfi = false;

    public const string FFI_CDEF = 'int32_t nntmux_w28_nzb_movie_log_dashboard_controllers(uint32_t operation,const char *input,size_t length,char *output,size_t capacity);';

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi ?? false;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function sanitizeNzbId(string $id): string
    {
        return self::$useRustFfi ? self::sanitizeNzbIdNative($id) : self::sanitizeNzbIdPhp($id);
    }

    public static function sanitizeNzbIdPhp(string $id): string
    {
        return str_ireplace(self::NZB_SUFFIX, '', $id);
    }

    public static function sanitizeNzbIdNative(string $id): string
    {
        $result = self::transformNative(0, [$id]);

        return is_string($result) ? $result : self::sanitizeNzbIdPhp($id);
    }

    /** @return array{guids: list<string>, count: int, exceeded: bool} */
    public static function zipDownloadPlan(string $id, int $requests, int $maxDownloads): array
    {
        return self::$useRustFfi ? self::zipDownloadPlanNative($id, $requests, $maxDownloads) : self::zipDownloadPlanPhp($id, $requests, $maxDownloads);
    }

    /** @return array{guids: list<string>, count: int, exceeded: bool} */
    public static function zipDownloadPlanPhp(string $id, int $requests, int $maxDownloads): array
    {
        $guids = explode(',', $id);
        $count = count($guids);

        return ['guids' => $guids, 'count' => $count, 'exceeded' => $requests + $count > $maxDownloads];
    }

    /** @return array{guids: list<string>, count: int, exceeded: bool} */
    public static function zipDownloadPlanNative(string $id, int $requests, int $maxDownloads): array
    {
        $result = self::transformNative(1, [$id, $requests, $maxDownloads]);

        return is_array($result) ? $result : self::zipDownloadPlanPhp($id, $requests, $maxDownloads);
    }

    public static function injectPoster(string $contents, int $uid): string
    {
        return self::$useRustFfi ? self::injectPosterNative($contents, $uid) : self::injectPosterPhp($contents, $uid);
    }

    public static function injectPosterPhp(string $contents, int $uid): string
    {
        return str_replace('file poster="', 'file poster="'.$uid.'-', $contents);
    }

    public static function injectPosterNative(string $contents, int $uid): string
    {
        $result = self::transformNative(2, [$contents, $uid]);

        return is_string($result) ? $result : self::injectPosterPhp($contents, $uid);
    }

    /**
     * @param  array{expires: string, failure_url: string, category: string, details_url: string, imdb_url: ?string, tvdb_url: ?string, nfo_url: ?string}  $values
     * @return array<string, string>
     */
    public static function buildDownloadHeaders(array $values): array
    {
        return self::$useRustFfi ? self::buildDownloadHeadersNative($values) : self::buildDownloadHeadersPhp($values);
    }

    /**
     * @param  array{expires: string, failure_url: string, category: string, details_url: string, imdb_url: ?string, tvdb_url: ?string, nfo_url: ?string}  $values
     * @return array<string, string>
     */
    public static function buildDownloadHeadersPhp(array $values): array
    {
        $headers = [
            'Content-Type' => 'application/x-nzb',
            'Expires' => $values['expires'],
            'X-DNZB-Failure' => $values['failure_url'],
            'X-DNZB-Category' => $values['category'],
            'X-DNZB-Details' => $values['details_url'],
        ];
        $moreInfo = $values['imdb_url'] ?? $values['tvdb_url'];
        if ($moreInfo !== null) {
            $headers['X-DNZB-MoreInfo'] = $moreInfo;
        }
        if ($values['nfo_url'] !== null) {
            $headers['X-DNZB-NFO'] = $values['nfo_url'];
        }
        $headers['X-DNZB-RCode'] = '200';
        $headers['X-DNZB-RText'] = 'OK, NZB content follows.';

        return $headers;
    }

    /**
     * @param  array{expires: string, failure_url: string, category: string, details_url: string, imdb_url: ?string, tvdb_url: ?string, nfo_url: ?string}  $values
     * @return array<string, string>
     */
    public static function buildDownloadHeadersNative(array $values): array
    {
        $result = self::transformNative(3, [$values]);

        return is_array($result) ? $result : self::buildDownloadHeadersPhp($values);
    }

    public static function failureQuery(string $releaseId, int $uid, ?string $rssToken): string
    {
        return self::$useRustFfi ? self::failureQueryNative($releaseId, $uid, $rssToken) : self::failureQueryPhp($releaseId, $uid, $rssToken);
    }

    public static function failureQueryPhp(string $releaseId, int $uid, ?string $rssToken): string
    {
        $params = ['guid' => $releaseId, 'userid' => $uid];
        if ($rssToken !== null && $rssToken !== '') {
            $params['api_token'] = $rssToken;
        }

        return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public static function failureQueryNative(string $releaseId, int $uid, ?string $rssToken): string
    {
        $result = self::transformNative(16, [$releaseId, $uid, $rssToken]);

        return is_string($result) ? $result : self::failureQueryPhp($releaseId, $uid, $rssToken);
    }

    /** @param list<mixed> $args */
    public static function transformNative(int $operation, array $args): mixed
    {
        try {
            $budget = 67108864;
            $nodes = 100000;
            if (! array_is_list($args) || ! self::validWireValue($args, 0, $budget, $nodes)) {
                return null;
            }
            $input = serialize($args);
            if (strlen($input) > 67108864) {
                return null;
            }
            $ffi = RustPipelineLibrary::resolveDomain('w28-nzb-movie-log-dashboard-controllers', self::FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
            if ($ffi === null) {
                return null;
            }
            $capacity = 0;
            $output = RustPipelineLibrary::obtainScratch($ffi, min(67108864, max(256, strlen($input) + 256)), $capacity);
            if ($output === null) {
                return null;
            }
            $capacity = min($capacity, 67108864);
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w28_nzb_movie_log_dashboard_controllers($operation, $input, strlen($input), $output, $capacity);
            if (is_int($written) && $written < -1 && -$written > $capacity && -$written <= 67108864) {
                $output = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($output === null) {
                    return null;
                }
                $capacity = min($capacity, 67108864);
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w28_nzb_movie_log_dashboard_controllers($operation, $input, strlen($input), $output, $capacity);
            }
            if (! is_int($written) || $written <= 0 || $written > $capacity) {
                return null;
            }
            $wire = \FFI::string($output, $written);
            $result = @unserialize($wire, ['allowed_classes' => false, 'max_depth' => 32]);
            $budget = 67108864;
            $nodes = 100000;
            if (! self::validWireValue($result, 0, $budget, $nodes) || serialize($result) !== $wire) {
                return null;
            }

            return $result;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function validWireValue(mixed $value, int $depth, int &$budget, int &$nodes): bool
    {
        if ($depth > 32 || --$nodes < 0) {
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (\ReflectionReference::fromArrayElement($value, $key) !== null || ! self::validWireValue($key, $depth + 1, $budget, $nodes) || ! self::validWireValue($item, $depth + 1, $budget, $nodes)) {
                    return false;
                }
            }

            return true;
        }
        if (is_string($value)) {
            $budget -= strlen($value);

            return $budget >= 0;
        }

        return $value === null || is_int($value) || is_float($value) || is_bool($value);
    }

    public const string REQUEST_USER_ATTRIBUTE = 'nntmux.getnzb.user';

    /**
     * Request attribute that switches error responses from newznab XML to JSON (API v2).
     */
    public const string JSON_ERRORS_ATTRIBUTE = 'nntmux.getnzb.json_errors';

    private const string NZB_SUFFIX = '.nzb';

    private bool $jsonErrors = false;

    /**
     * Download NZB file(s) for authenticated users
     *
     * @throws Exception
     */
    public function getNzb(Request $request, ?string $guid = null): JsonResponse|Response|StreamedResponse|Builder
    {
        $this->jsonErrors = $request->attributes->getBoolean(self::JSON_ERRORS_ATTRIBUTE);

        // Normalize guid parameter
        $this->normalizeGuidParameter($request, $guid);

        // Authenticate and authorize user
        $userData = $this->authenticateUser($request);
        if (! \is_array($userData)) {
            return $userData; // Return error response
        }

        ['uid' => $uid, 'userName' => $userName, 'maxDownloads' => $maxDownloads, 'rssToken' => $rssToken] = $userData;

        // Check download limits
        $downloadLimitError = $this->checkDownloadLimit($uid, $maxDownloads);
        if ($downloadLimitError !== null) {
            return $downloadLimitError;
        }

        // Validate and sanitize ID parameter
        $releaseId = $this->validateAndSanitizeId($request);
        if (! \is_string($releaseId)) {
            return $releaseId; // Return error response
        }

        // Handle zip download request
        if ($this->isZipRequest($request)) {
            return $this->handleZipDownload($request, $uid, $userName, $maxDownloads, $releaseId);
        }

        // Handle single NZB download
        return $this->handleSingleNzbDownload($request, $uid, $rssToken, $releaseId);
    }

    /**
     * Normalize the guid parameter into the request
     */
    private function normalizeGuidParameter(Request $request, ?string $guid): void
    {
        if ($guid !== null && ! $request->has('id')) {
            $request->merge(['id' => $guid]);
        }
    }

    /**
     * Authenticate user via session or RSS token
     *
     * @return array<string, mixed>|JsonResponse|Response
     */
    private function authenticateUser(Request $request): array|JsonResponse|Response
    {
        $resolvedUser = $request->attributes->get(self::REQUEST_USER_ATTRIBUTE);
        if ($resolvedUser instanceof User) {
            return $this->getUserDataFromResolvedUser($resolvedUser);
        }

        // Try session authentication first
        if ($request->user()) {
            return $this->getUserDataFromSession();
        }

        // Try RSS token authentication
        return $this->getUserDataFromRssToken($request);
    }

    /**
     * Get user data from a user resolved by an upstream API controller.
     *
     * @return array<string, mixed>|JsonResponse|Response
     */
    private function getUserDataFromResolvedUser(User $user): array|JsonResponse|Response
    {
        if (! $user->hasVerifiedEmail()) {
            return $this->apiError(100);
        }

        if ($user->is_disabled || $user->hasRole('Disabled')) {
            return $this->apiError(101);
        }

        $user->loadMissing('role');

        return [
            'uid' => $user->id,
            'userName' => $user->username,
            'maxDownloads' => $user->role->downloadrequests,
            'rssToken' => $user->api_token,
        ];
    }

    /**
     * Get user data from authenticated session
     *
     * @return array<string, mixed>|JsonResponse|Response
     */
    private function getUserDataFromSession(): array|JsonResponse|Response
    {
        if (! $this->userdata->hasVerifiedEmail()) {
            return $this->apiError(100);
        }

        if ($this->userdata->is_disabled || $this->userdata->hasRole('Disabled')) {
            return $this->apiError(101);
        }

        return [
            'uid' => $this->userdata->id,
            'userName' => $this->userdata->username,
            'maxDownloads' => $this->userdata->role->downloadrequests,
            'rssToken' => $this->userdata->api_token,
        ];
    }

    /**
     * Get user data from RSS token
     *
     * @return array<string, mixed>|JsonResponse|Response
     */
    private function getUserDataFromRssToken(Request $request): array|JsonResponse|Response
    {
        if ($request->missing('r')) {
            return $this->apiError(200);
        }

        $user = User::findVerifiedByApiToken((string) $request->input('r'));
        if (! $user) {
            return $this->apiError(100);
        }

        if ($user->is_disabled || $user->hasRole('Disabled')) {
            return $this->apiError(101);
        }

        return [
            'uid' => $user->id,
            'userName' => $user->username,
            'maxDownloads' => $user->role->downloadrequests,
            'rssToken' => $user->api_token,
        ];
    }

    /**
     * Check if user has exceeded download limits
     *
     *
     * @throws Exception
     */
    private function checkDownloadLimit(int $uid, int $maxDownloads): JsonResponse|Response|null
    {
        $requests = UserDownload::getDownloadRequests($uid);
        if ($requests >= $maxDownloads) {
            return $this->apiError(501);
        }

        return null;
    }

    /**
     * Validate and sanitize the release ID parameter
     */
    private function validateAndSanitizeId(Request $request): string|JsonResponse|Response
    {
        $id = $this->scalarInput($request, 'id');

        if ($id === '') {
            return $this->apiError(200, 'Parameter id is required');
        }

        // Remove .nzb suffix if present
        $sanitizedId = self::sanitizeNzbId($id);
        $request->merge(['id' => $sanitizedId]);

        return $sanitizedId;
    }

    /**
     * Check if this is a zip download request
     */
    private function isZipRequest(Request $request): bool
    {
        return $request->has('zip') && $request->input('zip') === '1';
    }

    /**
     * Handle zip download of multiple releases
     *
     * @throws Exception
     */
    private function handleZipDownload(
        Request $request,
        int $uid,
        string $userName,
        int $maxDownloads,
        string $releaseId
    ): JsonResponse|Response|Builder {
        $requests = UserDownload::getDownloadRequests($uid);
        $plan = self::zipDownloadPlan($releaseId, $requests, $maxDownloads);
        $guids = $plan['guids'];

        // Check if zip download would exceed limits
        if ($plan['exceeded']) {
            return $this->apiError(501);
        }

        $zip = getStreamingZip($guids);
        if ($zip === '') { // @phpstan-ignore identical.alwaysFalse
            return response()->json(['message' => 'Unable to create .zip file'], 404);
        }

        // Update statistics
        $this->updateZipDownloadStatistics($request, $uid, $guids);

        Log::channel('zipped')->info("User {$userName} downloaded zipped files from site with IP: {$request->ip()}");

        return $zip;
    }

    /**
     * Update statistics for zip downloads (batched to avoid N+1 queries).
     *
     * @param  list<string>  $guids
     */
    private function updateZipDownloadStatistics(Request $request, int $uid, array $guids): void
    {
        if ($guids === []) {
            return;
        }

        $guidCount = \count($guids);
        User::incrementGrabs($uid, $guidCount);

        // Resolve guids to release ids in one query for batch operations
        $releaseIds = Release::query()->whereIn('guid', $guids)->pluck('id')->all();

        Release::updateGrabsByGuids($guids);
        UserDownload::addDownloadRequestsBatch($uid, $releaseIds);

        $shouldDeleteFromCart = $request->has('del') && (int) $request->input('del') === 1;
        if ($shouldDeleteFromCart && $releaseIds !== []) {
            UsersRelease::delCartByUserAndReleases($uid, $releaseIds);
        }
    }

    /**
     * Handle single NZB file download
     */
    private function handleSingleNzbDownload(
        Request $request,
        int $uid,
        string $rssToken,
        string $releaseId
    ): JsonResponse|Response|StreamedResponse {
        // Get NZB file path and validate
        $nzbContents = app(NzbService::class)->readNzbContents($releaseId);
        if (! is_string($nzbContents)) {
            return $this->apiError(300, 'NZB file not found!');
        }

        // Get release data
        $releaseData = Release::getByGuid($releaseId);
        if ($releaseData === null) {
            return $this->apiError(300, 'Release not found!');
        }

        // Update statistics
        $this->updateDownloadStatistics($request, $uid, $releaseId, $releaseData->id);

        // Build response headers
        $headers = $this->buildNzbHeaders($request, $releaseId, $uid, $rssToken, $releaseData);

        // Stream modified NZB content
        $cleanName = FilenameSanitizer::sanitize($releaseData->searchname, "release-{$releaseId}");
        $asciiFallbackName = FilenameSanitizer::asciiFallback($cleanName, "release-{$releaseId}");

        $response = response()->stream(
            static function () use ($nzbContents, $uid): void {
                echo self::injectPoster($nzbContents, $uid);
            },
            200,
            $headers
        );

        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(
                'attachment',
                $cleanName.self::NZB_SUFFIX,
                $asciiFallbackName.self::NZB_SUFFIX
            )
        );

        return $response;
    }

    /**
     * Update download statistics for single NZB
     */
    private function updateDownloadStatistics(Request $request, int $uid, string $releaseId, int $releaseDbId): void
    {
        Release::updateGrab($releaseId);
        UserDownload::addDownloadRequest($uid, $releaseDbId);
        User::incrementGrabs($uid);

        if ($request->has('del') && (int) $request->input('del') === 1) {
            UsersRelease::delCartByUserAndRelease($releaseId, $uid);
        }
    }

    /**
     * Build headers for NZB download response
     *
     * @return array<string, string>
     */
    private function buildNzbHeaders(Request $request, string $releaseId, int $uid, string $rssToken, Release $releaseData): array
    {
        return self::buildDownloadHeaders([
            'expires' => now()->addYear()->toRfc7231String(),
            'failure_url' => url('/failed').'?'.self::failureQuery($releaseId, $uid, $request->user() ? null : $rssToken),
            'category' => e($releaseData->category_name),
            'details_url' => url("/details/{$releaseId}"),
            'imdb_url' => ! empty($releaseData->imdbid) && imdb_id_is_valid($releaseData->imdbid)
                ? "http://www.imdb.com/title/tt{$releaseData->imdbid}" : null,
            'tvdb_url' => ! empty($releaseData->tvdb) && $releaseData->tvdb > 0
                ? "http://www.thetvdb.com/?tab=series&id={$releaseData->tvdb}" : null,
            'nfo_url' => (int) $releaseData->nfostatus === 1 ? url("/nfo/{$releaseId}") : null,
        ]);
    }

    /**
     * Render an API error in the caller's format: newznab XML by default, JSON for API v2.
     */
    private function apiError(int $errorCode, string $errorText = ''): JsonResponse|Response
    {
        return $this->jsonErrors ? apiJsonError($errorCode, $errorText) : showApiError($errorCode, $errorText);
    }
}
