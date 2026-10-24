<?php

declare(strict_types=1);

namespace App\View\Composers;

use App\Events\UserLoggedIn;
use App\Models\Category;
use App\Models\Content;
use App\Models\User;
use App\Services\Configuration\ConfigurationProvider;
use App\Services\SiteLogoService;
use App\Support\RustPipelineLibrary;
use DateTimeInterface;
use FFI;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class GlobalDataComposer
{
    /**
     * Cache TTL in seconds (5 minutes).
     */
    private const CACHE_TTL = 300;

    private const THREE_HOURS_SECONDS = 10800;

    private const FFI_CDEF = <<<'CDEF'
    ssize_t nntmux_global_composer_format_cache_keys(
        int64_t user_id,
        size_t *out_lens,
        char *out_buf,
        size_t out_cap
    );
    int32_t nntmux_global_composer_should_refresh_last_login(
        int64_t last_login_ts,
        int64_t now_ts
    );
    ssize_t nntmux_global_composer_resolve_display_prefs(
        uint32_t has_theme,
        const char *theme_ptr,
        size_t theme_len,
        uint32_t has_color,
        const char *color_ptr,
        size_t color_len,
        uint32_t logged_in,
        uint32_t is_admin,
        uint32_t is_mod,
        uint32_t *out_flags,
        size_t *out_lens,
        char *out_buf,
        size_t out_cap
    );
    CDEF;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustMonitoringProbeComposerParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * Resolved data for this request, so we only build it once
     * even when the composer matches multiple views (layout + child).
     *
     * @var array<string, mixed>|null
     */
    private static ?array $resolvedData = null;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
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

    public function __construct(
        private readonly SiteLogoService $siteLogoService,
        private readonly ConfigurationProvider $configuration,
    ) {}

    /**
     * Bind data to the view.
     */
    public function compose(View $view): void
    {
        if (self::$resolvedData === null) {
            self::$resolvedData = $this->resolveData();
        }

        $view->with(self::$resolvedData);
    }

    /**
     * Build the global view data (called once per request).
     *
     * @return array<string, mixed>
     */
    private function resolveData(): array
    {
        $site = $this->configuration->site();

        $viewData = [
            'serverroot' => url('/'),
            'site' => $site,
            'siteLogoUrl' => $this->siteLogoService->url($site->logoPath),
        ];

        $guestKeys = self::formatUserCacheKeys(0);

        // Cached useful links for sidebar
        $viewData['usefulLinks'] = $this->rememberWithCacheFallback($guestKeys['useful_links'], self::CACHE_TTL, function () {
            return Content::active()
                ->ofType(Content::TYPE_USEFUL)
                ->ordered()
                ->get();
        });

        if (Auth::check()) {
            $userId = Auth::id();
            $cacheKeys = is_int($userId) || is_numeric($userId)
                ? self::formatUserCacheKeys((int) $userId)
                : [
                    'useful_links' => 'content_useful_links',
                    'user' => 'composer_user_'.$userId,
                    'menu' => 'menu_user_'.$userId,
                ];

            // User data with category exclusions (short cache per user)
            $userdata = $this->rememberWithCacheFallback($cacheKeys['user'], self::CACHE_TTL, function () use ($userId) {
                $user = User::find($userId);
                $user->categoryexclusions = User::getCachedCategoryExclusionById($userId);

                return $user;
            });

            // Update last login every 3 hours (outside of cache to avoid stale checks)
            $shouldRefresh = $userdata->lastlogin instanceof DateTimeInterface
                ? self::shouldRefreshLastLogin($userdata->lastlogin->getTimestamp(), now()->getTimestamp())
                : (now()->subHours(3) > $userdata->lastlogin);
            if ($shouldRefresh) {
                $userIp = config('nntmux_settings.store_user_ip') ? (request()->ip() ?? request()->getClientIp()) : '';
                event(new UserLoggedIn($userdata, $userIp));
            }

            // Cached menu categories per user (depends on their exclusions)
            $parentcatlist = $this->rememberWithCacheFallback($cacheKeys['menu'], self::CACHE_TTL, function () use ($userdata) {
                return Category::getForMenu($userdata->categoryexclusions);
            });

            $prefs = self::resolveUserDisplayPreferences(
                is_string($userdata->theme_preference ?? null) ? $userdata->theme_preference : null,
                is_string($userdata->color_scheme ?? null) ? $userdata->color_scheme : null,
                true,
                $userdata->hasRole('Admin'),
                $userdata->hasRole('Moderator'),
            );

            $viewData = array_merge($viewData, [
                'userdata' => $userdata,
                'loggedin' => $prefs['loggedin'],
                'isadmin' => $prefs['isadmin'],
                'ismod' => $prefs['ismod'],
                'parentcatlist' => $parentcatlist,
                'header_menu_cat' => request()->input('t', ''),
                'userTheme' => $prefs['userTheme'],
                'userColorScheme' => $prefs['userColorScheme'],
            ]);
        } else {
            $viewData = array_merge($viewData, self::resolveUserDisplayPreferences(null, null, false, false, false));
        }

        return $viewData;
    }

    /**
     * @return array{useful_links: string, user: string, menu: string}
     */
    public static function formatUserCacheKeys(int $userId): array
    {
        return self::$useRustFfi
            ? self::formatUserCacheKeysNative($userId)
            : self::formatUserCacheKeysPhp($userId);
    }

    /**
     * @return array{useful_links: string, user: string, menu: string}
     */
    public static function formatUserCacheKeysNative(int $userId): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::formatUserCacheKeysPhp($userId);
        }

        try {
            $lensSlot = $ffi->new('size_t[3]');
            $capacity = 0;
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, 128, $capacity);
            if ($outBuf === null) {
                return self::formatUserCacheKeysPhp($userId);
            }

            $written = self::callFfi($ffi, 'nntmux_global_composer_format_cache_keys', $userId, $lensSlot, $outBuf, $capacity);
            if (is_int($written) && $written > 0 && $written <= $capacity) {
                $len0 = (int) $lensSlot[0];
                $len1 = (int) $lensSlot[1];
                $len2 = (int) $lensSlot[2];
                if ($len0 + $len1 + $len2 === $written) {
                    $payload = FFI::string($outBuf, $written);

                    return [
                        'useful_links' => substr($payload, 0, $len0),
                        'user' => substr($payload, $len0, $len1),
                        'menu' => substr($payload, $len0 + $len1, $len2),
                    ];
                }
            }
        } catch (Throwable) {
        }

        return self::formatUserCacheKeysPhp($userId);
    }

    /**
     * @return array{useful_links: string, user: string, menu: string}
     */
    public static function formatUserCacheKeysPhp(int $userId): array
    {
        return [
            'useful_links' => 'content_useful_links',
            'user' => 'composer_user_'.$userId,
            'menu' => 'menu_user_'.$userId,
        ];
    }

    public static function shouldRefreshLastLogin(int $lastLoginTs, int $nowTs): bool
    {
        return self::$useRustFfi
            ? self::shouldRefreshLastLoginNative($lastLoginTs, $nowTs)
            : self::shouldRefreshLastLoginPhp($lastLoginTs, $nowTs);
    }

    public static function shouldRefreshLastLoginNative(int $lastLoginTs, int $nowTs): bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::shouldRefreshLastLoginPhp($lastLoginTs, $nowTs);
        }

        try {
            $rc = self::callFfi($ffi, 'nntmux_global_composer_should_refresh_last_login', $lastLoginTs, $nowTs);
            if ($rc === 1) {
                return true;
            }
            if ($rc === 0) {
                return false;
            }
        } catch (Throwable) {
        }

        return self::shouldRefreshLastLoginPhp($lastLoginTs, $nowTs);
    }

    public static function shouldRefreshLastLoginPhp(int $lastLoginTs, int $nowTs): bool
    {
        return ($nowTs - self::THREE_HOURS_SECONDS) > $lastLoginTs;
    }

    /**
     * @return array{isadmin: bool, ismod: bool, loggedin: bool, userTheme: string, userColorScheme: string}
     */
    public static function resolveUserDisplayPreferences(
        ?string $theme,
        ?string $colorScheme,
        bool $loggedIn,
        bool $isAdmin,
        bool $isMod,
    ): array {
        return self::$useRustFfi
            ? self::resolveUserDisplayPreferencesNative($theme, $colorScheme, $loggedIn, $isAdmin, $isMod)
            : self::resolveUserDisplayPreferencesPhp($theme, $colorScheme, $loggedIn, $isAdmin, $isMod);
    }

    /**
     * @return array{isadmin: bool, ismod: bool, loggedin: bool, userTheme: string, userColorScheme: string}
     */
    public static function resolveUserDisplayPreferencesNative(
        ?string $theme,
        ?string $colorScheme,
        bool $loggedIn,
        bool $isAdmin,
        bool $isMod,
    ): array {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::resolveUserDisplayPreferencesPhp($theme, $colorScheme, $loggedIn, $isAdmin, $isMod);
        }

        $hasTheme = $theme !== null;
        $themeStr = $theme ?? '';
        $hasColor = $colorScheme !== null;
        $colorStr = $colorScheme ?? '';

        try {
            $flagsSlot = $ffi->new('uint32_t[3]');
            $lensSlot = $ffi->new('size_t[2]');
            $capacity = 0;
            $requiredCap = max(128, strlen($themeStr) + strlen($colorStr) + 32);
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, $requiredCap, $capacity);
            if ($outBuf === null) {
                return self::resolveUserDisplayPreferencesPhp($theme, $colorScheme, $loggedIn, $isAdmin, $isMod);
            }

            $written = self::callFfi(
                $ffi,
                'nntmux_global_composer_resolve_display_prefs',
                $hasTheme ? 1 : 0,
                $themeStr,
                strlen($themeStr),
                $hasColor ? 1 : 0,
                $colorStr,
                strlen($colorStr),
                $loggedIn ? 1 : 0,
                $isAdmin ? 1 : 0,
                $isMod ? 1 : 0,
                $flagsSlot,
                $lensSlot,
                $outBuf,
                $capacity
            );

            if (is_int($written) && $written >= 0 && $written <= $capacity) {
                $lenTheme = (int) $lensSlot[0];
                $lenColor = (int) $lensSlot[1];
                if ($lenTheme + $lenColor === $written) {
                    $payload = $written === 0 ? '' : FFI::string($outBuf, $written);

                    return [
                        'isadmin' => ((int) $flagsSlot[1]) === 1,
                        'ismod' => ((int) $flagsSlot[2]) === 1,
                        'loggedin' => ((int) $flagsSlot[0]) === 1,
                        'userTheme' => $lenTheme === 0 ? '' : substr($payload, 0, $lenTheme),
                        'userColorScheme' => $lenColor === 0 ? '' : substr($payload, $lenTheme, $lenColor),
                    ];
                }
            }
        } catch (Throwable) {
        }

        return self::resolveUserDisplayPreferencesPhp($theme, $colorScheme, $loggedIn, $isAdmin, $isMod);
    }

    /**
     * @return array{isadmin: bool, ismod: bool, loggedin: bool, userTheme: string, userColorScheme: string}
     */
    public static function resolveUserDisplayPreferencesPhp(
        ?string $theme,
        ?string $colorScheme,
        bool $loggedIn,
        bool $isAdmin,
        bool $isMod,
    ): array {
        if ($loggedIn) {
            return [
                'isadmin' => $isAdmin,
                'ismod' => $isMod,
                'loggedin' => true,
                'userTheme' => $theme ?? 'light',
                'userColorScheme' => $colorScheme ?? 'blue',
            ];
        }

        return [
            'isadmin' => false,
            'ismod' => false,
            'loggedin' => false,
            'userTheme' => 'light',
            'userColorScheme' => 'blue',
        ];
    }

    /**
     * @param  callable(): mixed  $callback
     */
    private function rememberWithCacheFallback(string $key, int|\DateInterval $ttl, callable $callback): mixed
    {
        try {
            return Cache::remember($key, $ttl, $callback);
        } catch (Throwable $e) {
            if (config('app.debug')) {
                Log::debug('GlobalDataComposer cache bypassed: '.$e->getMessage());
            }

            return $callback();
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

        $ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
