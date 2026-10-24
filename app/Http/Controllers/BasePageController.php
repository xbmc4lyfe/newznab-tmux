<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\User;
use App\Services\Api\ApiQueryParameters;
use App\Services\Configuration\ConfigurationProvider;
use App\Support\Configuration\SiteConfigurationData;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class BasePageController extends Controller
{
    private static FFI|false|null $baseFfi = null;

    private static ?bool $useBaseRustFfi = null;

    private static bool $adoptLocalReturnUrlFfi = false;

    private static bool $adoptResolvePageFfi = false;

    private static bool $adoptIntegerInputFfi = false;

    public SiteConfigurationData $siteConfiguration;

    public string $title = '';

    public string $content = '';

    public string $meta_keywords = '';

    public string $meta_title = '';

    public string $meta_description = '';

    /**
     * Current page the user is browsing. ie browse.
     */
    public string $page = '';

    public User $userdata;

    /**
     * User's theme.
     */
    protected string $theme = 'Gentele';

    /**
     * View data array for Blade templates
     *
     * @var array<string, mixed>
     */
    protected array $viewData = [];

    /**
     * BasePageController constructor.
     *
     * @throws \Exception
     */
    public function __construct()
    {
        $this->middleware(['auth', 'web', '2fa'])->except('api', 'contact', 'showContactForm', 'callback', 'btcPayCallback', 'getNzb', 'terms', 'privacyPolicy', 'capabilities', 'movie', 'apiSearch', 'tv', 'details', 'failed', 'showRssDesc', 'fullFeedRss', 'categoryFeedRss', 'cartRss', 'myMoviesRss', 'myShowsRss', 'trendingMoviesRss', 'trendingShowsRss', 'release', 'reset', 'showLinkRequestForm', 'showStatusPage');

        $this->siteConfiguration = app(ConfigurationProvider::class)->site();

        // Initialize view data FIRST with serverroot
        $this->viewData = [
            'serverroot' => url('/'),
        ];

        $this->viewData['site'] = $this->siteConfiguration;

        // Initialize userdata property for controllers that need it
        $this->middleware(function ($request, $next) {
            if (Auth::check()) {
                $userId = Auth::id();
                $this->userdata = User::find($userId);
                if (! $this->userdata?->hasVerifiedEmail()) {
                    return $next($request);
                }

                // Cache category exclusions per user (5 minutes)
                $this->userdata->categoryexclusions = $this->rememberWithCacheFallback(
                    User::categoryExclusionCacheKey($userId),
                    300,
                    fn () => User::getCategoryExclusionById($userId)
                );
            }

            return $next($request);
        });
    }

    /**
     * Use the cache when available; if the store is unreachable (e.g. Redis down), run the callback directly.
     *
     * @param  callable(): mixed  $callback
     */
    private function rememberWithCacheFallback(string $key, int|\DateInterval $ttl, callable $callback): mixed
    {
        try {
            return Cache::remember($key, $ttl, $callback);
        } catch (Throwable $e) {
            if (config('app.debug')) {
                Log::debug('BasePageController cache bypassed: '.$e->getMessage());
            }

            return $callback();
        }
    }

    /**
     * @return LengthAwarePaginator<int, mixed>
     */
    public function paginate(mixed $query, mixed $totalCount, mixed $items, mixed $page, mixed $path, mixed $reqQuery): LengthAwarePaginator
    {
        return new LengthAwarePaginator($query, $totalCount, $items, $page, ['path' => $path, 'query' => $reqQuery]);
    }

    public static function setUseRustFfi(?bool $enabled): void
    {
        self::$useBaseRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useBaseRustFfi ?? self::$adoptLocalReturnUrlFfi;
    }

    public static function baseFfi(): ?FFI
    {
        return self::$baseFfi !== null ? (self::$baseFfi === false ? null : self::$baseFfi) : self::resolveBaseFfi();
    }

    protected function resolvePage(Request $request, string $key = 'page'): int
    {
        if (self::$useBaseRustFfi ?? self::$adoptResolvePageFfi) {
            $supported = false;
            $native = $this->resolvePageNative($request, $key, $supported);
            if ($supported && $native !== null) {
                return $native;
            }
        }

        return $this->resolvePagePhp($request, $key);
    }

    /**
     * @param-out bool $supported
     */
    public function resolvePageNative(Request $request, string $key = 'page', bool &$supported = false): ?int
    {
        $supported = false;
        if (self::$useBaseRustFfi === false) {
            return null;
        }
        $page = $request->input($key, 1);
        if (! is_scalar($page)) {
            $supported = true;

            return 1;
        }

        $page = (string) $page;
        if ($page === '') {
            $supported = true;

            return 1;
        }

        $ffi = self::$baseFfi ?? self::resolveBaseFfi();
        if ($ffi === null || $ffi === false) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $res = $ffi->nntmux_page_resolve_page($page, \strlen($page));
            if (! \is_int($res) || $res < 1) {
                return null;
            }
            $supported = true;

            return $res;
        } catch (Throwable) {
            $supported = false;

            return null;
        }
    }

    public function resolvePagePhp(Request $request, string $key = 'page'): int
    {
        $page = $request->input($key, 1);
        if (! is_scalar($page)) {
            return 1;
        }

        $page = (string) $page;
        if ($page === '' || preg_match('/^\d+$/', $page) !== 1) {
            return 1;
        }

        return max(1, (int) $page);
    }

    /**
     * @param  array<int, string>  $ordering
     */
    protected function resolveOrderBy(Request $request, array $ordering, string $key = 'ob'): string
    {
        $orderByInput = $request->input($key, '');
        $orderBy = is_scalar($orderByInput) ? (string) $orderByInput : '';

        return \in_array($orderBy, $ordering, true) ? $orderBy : '';
    }

    protected function paginationOffset(int $page, int $perPage): int
    {
        return ($page - 1) * $perPage;
    }

    /**
     * @param  array<int, string>  $ordering
     * @return array<string, string>
     */
    protected function buildOrderByUrls(array $ordering, string $basePath, string $prefix = 'orderby'): array
    {
        $orderByUrls = [];
        foreach ($ordering as $orderType) {
            $orderByUrls[$prefix.$orderType] = url($basePath.'?ob='.$orderType);
        }

        return $orderByUrls;
    }

    protected function scalarInput(Request $request, string $key, string $default = ''): string
    {
        $value = $request->input($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * Resolve a legacy form action while preventing mutation actions on GET requests.
     *
     * @param  list<string>  $allowedReadActions
     */
    protected function formAction(Request $request, array $allowedReadActions = ['view']): string
    {
        $action = $this->scalarInput($request, 'action', 'view');

        if (! $request->isMethod('post') && ! in_array($action, $allowedReadActions, true)) {
            return 'view';
        }

        return $action;
    }

    protected function integerInput(Request $request, string $key, int $default = 0): int
    {
        if (self::$useBaseRustFfi ?? self::$adoptIntegerInputFfi) {
            $supported = false;
            $native = $this->integerInputNative($request, $key, $default, $supported);
            if ($supported && $native !== null) {
                return $native;
            }
        }

        return $this->integerInputPhp($request, $key, $default);
    }

    /**
     * @param-out bool $supported
     */
    public function integerInputNative(Request $request, string $key, int $default = 0, bool &$supported = false): ?int
    {
        $supported = false;
        if (self::$useBaseRustFfi === false || $default === PHP_INT_MIN) {
            return null;
        }
        $ffi = self::$baseFfi ?? self::resolveBaseFfi();
        if ($ffi === null || $ffi === false) {
            return null;
        }

        $value = $this->scalarInput($request, $key, (string) $default);
        try {
            // @phpstan-ignore method.notFound
            $res = $ffi->nntmux_page_parse_integer($value, \strlen($value), $default);
            if (! \is_int($res) || $res === PHP_INT_MIN) {
                return null;
            }
            $supported = true;

            return $res;
        } catch (Throwable) {
            $supported = false;

            return null;
        }
    }

    public function integerInputPhp(Request $request, string $key, int $default = 0): int
    {
        $value = $this->scalarInput($request, $key, (string) $default);

        return preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : $default;
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function arrayInput(Request $request, string $key): array
    {
        $value = $request->input($key, []);

        return \is_array($value) ? $value : [];
    }

    protected function localReturnUrl(Request $request, string $fallback, string $key = 'from'): string
    {
        if (self::$useBaseRustFfi ?? self::$adoptLocalReturnUrlFfi) {
            $supported = false;
            $native = $this->localReturnUrlNative($request, $fallback, $key, $supported);
            if ($supported && $native !== null) {
                return $native;
            }
        }

        return $this->localReturnUrlPhp($request, $fallback, $key);
    }

    /**
     * @param-out bool $supported
     */
    public function localReturnUrlNative(Request $request, string $fallback, string $key = 'from', bool &$supported = false): ?string
    {
        $supported = false;
        if (self::$useBaseRustFfi === false) {
            return null;
        }

        $from = \trim($this->scalarInput($request, $key));
        if ($from === '') {
            $supported = true;

            return url($fallback);
        }

        $ffi = self::$baseFfi ?? self::resolveBaseFfi();
        if ($ffi === null || $ffi === false) {
            return null;
        }

        $hasColon = $from[0] !== '/' && \str_contains($from, ':');
        $scheme = $hasColon ? $request->getScheme() : '';
        $host = $hasColon ? $request->getHost() : '';
        $port = $hasColon ? (int) $request->getPort() : -1;

        try {
            // @phpstan-ignore method.notFound
            $kind = $ffi->nntmux_page_classify_return_url(
                $from,
                \strlen($from),
                $scheme,
                \strlen($scheme),
                $host,
                \strlen($host),
                $port
            );
            if (! \is_int($kind) || $kind < 0) {
                return null;
            }
            $supported = true;

            return match ($kind) {
                1 => $from,
                2 => url($from),
                default => url($fallback),
            };
        } catch (Throwable) {
            $supported = false;

            return null;
        }
    }

    public function localReturnUrlPhp(Request $request, string $fallback, string $key = 'from'): string
    {
        $from = trim($this->scalarInput($request, $key));
        if ($from === '' || preg_match('/[\\x00-\\x20\\x7f\\\\\\\\]/', $from) === 1 || str_starts_with($from, '//')) {
            return url($fallback);
        }

        $parts = parse_url($from);
        if ($parts === false) {
            return url($fallback);
        }

        if (isset($parts['scheme']) || isset($parts['host'])) {
            $scheme = strtolower($parts['scheme'] ?? '');
            $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
            $sameOrigin = $scheme === $request->getScheme()
                && strtolower($parts['host'] ?? '') === strtolower($request->getHost())
                && $port === $request->getPort()
                && ! isset($parts['user']) && ! isset($parts['pass']);

            return $sameOrigin ? $from : url($fallback);
        }

        return url($from);
    }

    private static function resolveBaseFfi(): ?FFI
    {
        if (self::$baseFfi !== null) {
            return self::$baseFfi === false ? null : self::$baseFfi;
        }
        $ffi = RustPipelineLibrary::resolveDomain('categorize', ApiQueryParameters::FFI_CDEF);
        if ($ffi === null) {
            self::$baseFfi = false;

            return null;
        }
        self::$baseFfi = $ffi;

        return $ffi;
    }

    /**
     * Check if request is POST.
     */
    public function isPostBack(Request $request): bool
    {
        return $request->isMethod('POST');
    }

    /**
     * Show 404 page.
     *
     * @param  string|null  $message
     */
    public function show404($message = null): View
    {
        if ($message !== null) {
            return view('errors.404')->with('Message', $message);
        }

        return view('errors.404');
    }

    /**
     *  Set admin preferences.
     */
    public function setAdminPrefs(): void
    {
        $this->viewData['catClass'] = Category::class;
    }

    /**
     * Output the page using Blade views.
     */
    public function pagerender(): View
    {
        return view('layouts.main', $this->viewData);
    }

    /**
     * Output a page using the admin template.
     *
     * @throws \Exception
     */
    public function adminrender(): View
    {
        return view('layouts.admin', $this->viewData);
    }

    /**
     * @throws \Exception
     */
    public function adminBasePage(): View
    {
        $this->setAdminPrefs();
        $this->viewData = array_merge($this->viewData, [
            'meta_title' => 'Admin Home',
            'meta_description' => 'Admin home page',
        ]);

        return $this->adminrender();
    }
}
