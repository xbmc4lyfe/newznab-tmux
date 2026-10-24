<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Support\RustPipelineLibrary;
use FFI;

/**
 * Builds Grafana dashboard and panel URLs for the admin pages.
 *
 * URLs never contain the auth token (see config monitoring.grafana.auth); the
 * Alpine components only append the theme.
 */
class GrafanaEmbedService
{
    public const string AUTH_PROXY = 'proxy';

    public const string AUTH_COOKIE = 'cookie';

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_grafana_embed_url(const char *base_url, size_t base_url_len, const char *uid, size_t uid_len, const char *slug, size_t slug_len, int64_t org_id, const char *from_range, size_t from_range_len, const char *refresh, size_t refresh_len, uint32_t is_panel, uint32_t kiosk, int64_t panel_id, char *out_buf, size_t out_cap);
        ssize_t nntmux_grafana_frame_origin(const char *grafana_url, size_t grafana_url_len, const char *app_url, size_t app_url_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_grafana_cookie_path(const char *base_url, size_t base_url_len, char *out_buf, size_t out_cap);
        C;

    public static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public function __construct(private readonly GrafanaJwtIssuer $issuer) {}

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function mediaFfi(): ?FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi === false ? null : self::$ffi;
        }

        self::$ffi = RustPipelineLibrary::resolveDomain('media', self::FFI_CDEF) ?? false;

        return self::$ffi === false ? null : self::$ffi;
    }

    public function isEnabled(): bool
    {
        return (bool) config('monitoring.enabled') && $this->issuer->hasUsableKey();
    }

    public function authMode(): string
    {
        return config('monitoring.grafana.auth') === self::AUTH_COOKIE ? self::AUTH_COOKIE : self::AUTH_PROXY;
    }

    /**
     * Endpoint the embeds call to set and refresh the login cookie; null in
     * proxy mode, where nginx authenticates every Grafana request itself.
     */
    public function tokenUrl(): ?string
    {
        return $this->authMode() === self::AUTH_COOKIE ? route('admin.monitoring.token') : null;
    }

    /**
     * Path the login cookie is scoped to, so it is only sent to Grafana.
     */
    public function cookiePath(): string
    {
        return $this->formatCookiePath((string) config('monitoring.grafana.url', '/grafana'));
    }

    public function formatCookiePath(string $rawBaseUrl): string
    {
        return self::$useRustFfi
            ? $this->formatCookiePathNative($rawBaseUrl)
            : $this->formatCookiePathPhp($rawBaseUrl);
    }

    public function formatCookiePathPhp(string $rawBaseUrl): string
    {
        $path = parse_url(rtrim($rawBaseUrl, '/'), PHP_URL_PATH);

        return rtrim(is_string($path) ? $path : '', '/').'/';
    }

    public function formatCookiePathNative(string $rawBaseUrl): string
    {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return $this->formatCookiePathPhp($rawBaseUrl);
        }

        $len = \strlen($rawBaseUrl);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, $len + 16), $cap);
        if ($scratch === null) {
            return $this->formatCookiePathPhp($rawBaseUrl);
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_grafana_cookie_path($rawBaseUrl, $len, $scratch, $cap);
        if (! \is_int($written) || $written === -1) {
            return $this->formatCookiePathPhp($rawBaseUrl);
        }
        if ($written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return $this->formatCookiePathPhp($rawBaseUrl);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_grafana_cookie_path($rawBaseUrl, $len, $scratch, $cap);
            if (! \is_int($written) || $written <= 0) {
                return $this->formatCookiePathPhp($rawBaseUrl);
            }
        }

        return $written <= 0 ? $this->formatCookiePathPhp($rawBaseUrl) : FFI::string($scratch, $written);
    }

    /**
     * @return array<string, array{uid: string, slug: string, title: string, icon: string, url: string, open_url: string}>
     */
    public function dashboards(): array
    {
        $dashboards = [];

        foreach ($this->configuredDashboards() as $key => $dashboard) {
            $dashboards[$key] = $dashboard + [
                'url' => $this->dashboardUrl($key),
                'open_url' => $this->dashboardUrl($key, kiosk: false),
            ];
        }

        return $dashboards;
    }

    public function dashboardUrl(string $key, bool $kiosk = true): string
    {
        $dashboard = $this->dashboard($key);

        return $this->buildDashboardEmbedUrl(
            (string) config('monitoring.grafana.url', '/grafana'),
            $dashboard['uid'],
            $dashboard['slug'],
            (int) config('monitoring.grafana.org_id', 1),
            (string) config('monitoring.grafana.default_range', 'now-6h'),
            (string) config('monitoring.grafana.default_refresh', '1m'),
            $kiosk,
        );
    }

    public function buildDashboardEmbedUrl(
        string $baseUrl,
        string $uid,
        string $slug,
        int $orgId,
        string $from,
        string $refresh,
        bool $kiosk = true,
    ): string {
        return self::$useRustFfi
            ? $this->buildDashboardEmbedUrlNative($baseUrl, $uid, $slug, $orgId, $from, $refresh, $kiosk)
            : $this->buildDashboardEmbedUrlPhp($baseUrl, $uid, $slug, $orgId, $from, $refresh, $kiosk);
    }

    public function buildDashboardEmbedUrlPhp(
        string $baseUrl,
        string $uid,
        string $slug,
        int $orgId,
        string $from,
        string $refresh,
        bool $kiosk = true,
    ): string {
        $query = [
            'orgId' => $orgId,
            'from' => $from,
            'to' => 'now',
            'refresh' => $refresh,
        ];

        $url = rtrim($baseUrl, '/').'/d/'.rawurlencode($uid).'/'.rawurlencode($slug).'?'.http_build_query($query);

        // Grafana treats a bare `kiosk` flag as full kiosk mode.
        return $kiosk ? $url.'&kiosk' : $url;
    }

    public function buildDashboardEmbedUrlNative(
        string $baseUrl,
        string $uid,
        string $slug,
        int $orgId,
        string $from,
        string $refresh,
        bool $kiosk = true,
    ): string {
        $built = $this->callEmbedUrlFfi($baseUrl, $uid, $slug, $orgId, $from, $refresh, false, $kiosk, 0);

        return $built ?? $this->buildDashboardEmbedUrlPhp($baseUrl, $uid, $slug, $orgId, $from, $refresh, $kiosk);
    }

    public function panelUrl(string $key, int $panelId): string
    {
        $dashboard = $this->dashboard($key);

        return $this->buildPanelEmbedUrl(
            (string) config('monitoring.grafana.url', '/grafana'),
            $dashboard['uid'],
            $dashboard['slug'],
            (int) config('monitoring.grafana.org_id', 1),
            (string) config('monitoring.grafana.default_range', 'now-6h'),
            (string) config('monitoring.grafana.default_refresh', '1m'),
            $panelId,
        );
    }

    public function buildPanelEmbedUrl(
        string $baseUrl,
        string $uid,
        string $slug,
        int $orgId,
        string $from,
        string $refresh,
        int $panelId,
    ): string {
        return self::$useRustFfi
            ? $this->buildPanelEmbedUrlNative($baseUrl, $uid, $slug, $orgId, $from, $refresh, $panelId)
            : $this->buildPanelEmbedUrlPhp($baseUrl, $uid, $slug, $orgId, $from, $refresh, $panelId);
    }

    public function buildPanelEmbedUrlPhp(
        string $baseUrl,
        string $uid,
        string $slug,
        int $orgId,
        string $from,
        string $refresh,
        int $panelId,
    ): string {
        return rtrim($baseUrl, '/').'/d-solo/'.rawurlencode($uid).'/'.rawurlencode($slug).'?'.http_build_query([
            'orgId' => $orgId,
            'from' => $from,
            'to' => 'now',
            'refresh' => $refresh,
            'panelId' => $panelId,
        ]);
    }

    public function buildPanelEmbedUrlNative(
        string $baseUrl,
        string $uid,
        string $slug,
        int $orgId,
        string $from,
        string $refresh,
        int $panelId,
    ): string {
        $built = $this->callEmbedUrlFfi($baseUrl, $uid, $slug, $orgId, $from, $refresh, true, false, $panelId);

        return $built ?? $this->buildPanelEmbedUrlPhp($baseUrl, $uid, $slug, $orgId, $from, $refresh, $panelId);
    }

    /**
     * Panels embedded on the admin dashboard; empty when monitoring is off so
     * the page falls back to the Chart.js CPU/RAM history.
     *
     * @return list<array{title: string, url: string}>
     */
    public function dashboardPanels(): array
    {
        if (! $this->isEnabled()) {
            return [];
        }

        $panels = [];

        /** @var list<array{dashboard: string, panel: int, title: string}> $configured */
        $configured = config('monitoring.grafana.dashboard_panels', []);

        foreach ($configured as $panel) {
            $panels[] = [
                'title' => $panel['title'],
                'url' => $this->panelUrl($panel['dashboard'], (int) $panel['panel']),
            ];
        }

        return $panels;
    }

    /**
     * Origin to allow in frame-src when Grafana is served from another host;
     * null when it shares the app origin (already covered by 'self').
     */
    public static function frameOrigin(?string $grafanaUrl, ?string $appUrl): ?string
    {
        return self::$useRustFfi
            ? self::frameOriginNative($grafanaUrl, $appUrl)
            : self::frameOriginPhp($grafanaUrl, $appUrl);
    }

    public static function frameOriginPhp(?string $grafanaUrl, ?string $appUrl): ?string
    {
        $grafana = parse_url((string) $grafanaUrl);

        if (! is_array($grafana) || ! isset($grafana['scheme'], $grafana['host'])) {
            return null;
        }

        $origin = strtolower($grafana['scheme'].'://'.$grafana['host']).(isset($grafana['port']) ? ':'.$grafana['port'] : '');
        $app = parse_url((string) $appUrl);

        if (is_array($app) && isset($app['scheme'], $app['host'])) {
            $appOrigin = strtolower($app['scheme'].'://'.$app['host']).(isset($app['port']) ? ':'.$app['port'] : '');

            if ($appOrigin === $origin) {
                return null;
            }
        }

        return $origin;
    }

    public static function frameOriginNative(?string $grafanaUrl, ?string $appUrl): ?string
    {
        $gStr = (string) $grafanaUrl;
        if ($gStr === '') {
            return null;
        }

        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return self::frameOriginPhp($grafanaUrl, $appUrl);
        }

        $aStr = (string) $appUrl;
        $gLen = \strlen($gStr);
        $aLen = \strlen($aStr);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, $gLen + 16), $cap);
        if ($scratch === null) {
            return self::frameOriginPhp($grafanaUrl, $appUrl);
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_grafana_frame_origin($gStr, $gLen, $aStr, $aLen, $scratch, $cap);
        if (! \is_int($written) || $written === -1) {
            return self::frameOriginPhp($grafanaUrl, $appUrl);
        }
        if ($written === 0) {
            return null;
        }
        if ($written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return self::frameOriginPhp($grafanaUrl, $appUrl);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_grafana_frame_origin($gStr, $gLen, $aStr, $aLen, $scratch, $cap);
            if (! \is_int($written) || $written < 0) {
                return self::frameOriginPhp($grafanaUrl, $appUrl);
            }
            if ($written === 0) {
                return null;
            }
        }

        return FFI::string($scratch, $written);
    }

    private function callEmbedUrlFfi(
        string $baseUrl,
        string $uid,
        string $slug,
        int $orgId,
        string $from,
        string $refresh,
        bool $isPanel,
        bool $kiosk,
        int $panelId,
    ): ?string {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return null;
        }

        $baseLen = \strlen($baseUrl);
        $uidLen = \strlen($uid);
        $slugLen = \strlen($slug);
        $fromLen = \strlen($from);
        $refreshLen = \strlen($refresh);
        $needed = $baseLen + ($uidLen + $slugLen + $fromLen + $refreshLen) * 3 + 128;

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, $needed), $cap);
        if ($scratch === null) {
            return null;
        }

        $panelFlag = $isPanel ? 1 : 0;
        $kioskFlag = $kiosk ? 1 : 0;
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_grafana_embed_url(
            $baseUrl,
            $baseLen,
            $uid,
            $uidLen,
            $slug,
            $slugLen,
            $orgId,
            $from,
            $fromLen,
            $refresh,
            $refreshLen,
            $panelFlag,
            $kioskFlag,
            $panelId,
            $scratch,
            $cap,
        );
        if (! \is_int($written) || $written === -1) {
            return null;
        }
        if ($written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_grafana_embed_url(
                $baseUrl,
                $baseLen,
                $uid,
                $uidLen,
                $slug,
                $slugLen,
                $orgId,
                $from,
                $fromLen,
                $refresh,
                $refreshLen,
                $panelFlag,
                $kioskFlag,
                $panelId,
                $scratch,
                $cap,
            );
            if (! \is_int($written) || $written <= 0) {
                return null;
            }
        }

        return $written <= 0 ? null : FFI::string($scratch, $written);
    }

    /**
     * @return array<string, array{uid: string, slug: string, title: string, icon: string}>
     */
    private function configuredDashboards(): array
    {
        /** @var array<string, array{uid: string, slug: string, title: string, icon: string}> $dashboards */
        $dashboards = config('monitoring.grafana.dashboards', []);

        return $dashboards;
    }

    /**
     * @return array{uid: string, slug: string, title: string, icon: string}
     */
    private function dashboard(string $key): array
    {
        $dashboards = $this->configuredDashboards();

        if (! isset($dashboards[$key])) {
            throw new \InvalidArgumentException("Unknown Grafana dashboard [{$key}].");
        }

        return $dashboards[$key];
    }
}
