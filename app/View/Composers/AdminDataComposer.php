<?php

declare(strict_types=1);

namespace App\View\Composers;

use App\Models\Settings;
use App\Models\User;
use App\Services\SiteLogoService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class AdminDataComposer
{
    /**
     * Cache TTL in seconds (5 minutes).
     */
    private const CACHE_TTL = 300;

    public function __construct(private readonly SiteLogoService $siteLogoService) {}

    /**
     * Bind lightweight admin data to the view.
     */
    public function compose(View $view): void
    {
        $user = Auth::user();
        $isNntmuxUser = $user instanceof User;
        $site = $this->rememberWithCacheFallback('site_settings_array', self::CACHE_TTL, function () {
            return Settings::query()
                ->pluck('value', 'name')
                ->map(fn ($value) => Settings::convertValue($value))
                ->all();
        });
        $siteLogoPath = is_array($site) ? ($site['site_logo'] ?? null) : null;

        $view->with([
            'serverroot' => url('/'),
            'site' => $site,
            'siteLogoUrl' => $this->siteLogoService->url($siteLogoPath),
            'userdata' => $user,
            'loggedin' => $user !== null,
            'isadmin' => $isNntmuxUser && $user->hasRole('Admin'),
            'ismod' => $isNntmuxUser && $user->hasRole('Moderator'),
            'userTheme' => $isNntmuxUser ? ($user->theme_preference ?? 'light') : 'light',
            'userColorScheme' => $isNntmuxUser ? ($user->color_scheme ?? 'blue') : 'blue',
        ]);
    }

    /**
     * @param  callable(): mixed  $callback
     */
    private function rememberWithCacheFallback(string $key, int|\DateInterval $ttl, callable $callback): mixed
    {
        try {
            if (Cache::has($key)) {
                return Cache::get($key);
            }

            $value = $callback();
            Cache::put($key, $value, $ttl);

            return $value;
        } catch (\Throwable $e) {
            if (config('app.debug')) {
                Log::debug('AdminDataComposer cache bypassed: '.$e->getMessage());
            }

            return $callback();
        }
    }
}
