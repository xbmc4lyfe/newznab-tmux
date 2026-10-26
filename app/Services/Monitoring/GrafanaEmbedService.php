<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

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

    /**
     * Enabled: the paired benchmark in the W58 parity test measures 3.933x common mean, 3.272x
     * common p99, 3.85x uncommon mean and 3.0x uncommon p99 (per-metric minimum of four runs),
     * above the 1.5x rule.
     */
    public const bool W58_RUST_FFI_DEFAULT = true;

    public const string W58_GRAFANA_EMBED_FFI_CDEF = 'ssize_t nntmux_w581_grafana_embed_batch(const char *rows, size_t rows_len, size_t count, int64_t *out, size_t out_cap);';

    public const int W58_EMBED_WORDS = 3;

    public const int W58_EMBED_DASHBOARD = 1;

    public const int W58_EMBED_PANEL = 2;

    public const int W58_EMBED_UID_VALID = 4;

    public const int W58_EMBED_SLUG_VALID = 8;

    public const int W58_EMBED_ORG_VALID = 16;

    public const int W58_EMBED_PANEL_VALID = 32;

    public const int W58_EMBED_RANGE_VALID = 64;

    public const int W58_EMBED_REFRESH_VALID = 128;

    public const int W58_EMBED_ABSOLUTE = 256;

    public const int W58_EMBED_TRAILING_SLASH = 512;

    public const int W58_EMBED_LENGTH_SHIFT = 16;

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

    public static function usesW58RustFfi(): bool
    {
        $enabled = self::w58RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W58_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 58 batch routed to Rust or PHP, then restores the routing. */
    public static function withW58RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w58RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w58RustFfiState('set', $previous);
        }
    }

    /**
     * Three words per `base\tuid\tslug\torgId\tfrom\trefresh\tpanelId` row: the kind
     * (W58_EMBED_DASHBOARD, or W58_EMBED_PANEL for a positive panel ID) with the UID, slug, org,
     * panel, time-range, refresh, absolute-base and trailing-slash validation flags plus the
     * embed URL length `<< W58_EMBED_LENGTH_SHIFT`; then `crc32()` of the URL that
     * buildDashboardEmbedUrlPhp()/buildPanelEmbedUrlPhp() assemble, and the query-string length.
     * A row without exactly seven fields yields three zeros.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function embedUrlBatch(array $rows): array
    {
        return self::usesW58RustFfi() ? self::embedUrlBatchNative($rows) : self::embedUrlBatchPhp($rows);
    }

    /**
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function embedUrlBatchPhp(array $rows): array
    {
        $words = [];
        foreach ($rows as $row) {
            $fields = explode("\t", $row);
            if (\count($fields) !== 7) {
                array_push($words, 0, 0, 0);

                continue;
            }
            [$baseUrl, $uid, $slug, $org, $from, $refresh, $panel] = $fields;
            $orgValid = preg_match('/\A-?[0-9]{1,18}\z/', $org) === 1;
            $orgId = $orgValid ? (int) $org : 0;
            $panelValid = preg_match('/\A-?[0-9]{1,18}\z/', $panel) === 1;
            $panelId = $panelValid ? (int) $panel : 0;
            $isPanel = $panelId > 0;
            $trimmed = rtrim($baseUrl, '/');
            $query = ['orgId' => $orgId, 'from' => $from, 'to' => 'now', 'refresh' => $refresh];
            if ($isPanel) {
                $query['panelId'] = $panelId;
            }
            $query = http_build_query($query, '', '&');
            $url = $trimmed.($isPanel ? '/d-solo/' : '/d/').rawurlencode($uid).'/'.rawurlencode($slug).'?'.$query.($isPanel ? '' : '&kiosk');
            $words[] = ($isPanel ? self::W58_EMBED_PANEL : self::W58_EMBED_DASHBOARD)
                | (preg_match('/\A[A-Za-z0-9_-]{1,40}\z/', $uid) === 1 ? self::W58_EMBED_UID_VALID : 0)
                | (preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) === 1 ? self::W58_EMBED_SLUG_VALID : 0)
                | ($orgValid && $orgId > 0 ? self::W58_EMBED_ORG_VALID : 0)
                | ($panelValid && $panelId >= 0 ? self::W58_EMBED_PANEL_VALID : 0)
                | (preg_match('/\A(?:now(?:-[0-9]{1,6}[smhdwMy])?|[0-9]{10,13})\z/', $from) === 1 ? self::W58_EMBED_RANGE_VALID : 0)
                | (preg_match('/\A(?:[0-9]{1,4}[smhd])?\z/', $refresh) === 1 ? self::W58_EMBED_REFRESH_VALID : 0)
                | (strncasecmp($trimmed, 'http://', 7) === 0 || strncasecmp($trimmed, 'https://', 8) === 0 ? self::W58_EMBED_ABSOLUTE : 0)
                | (\strlen($trimmed) !== \strlen($baseUrl) ? self::W58_EMBED_TRAILING_SLASH : 0)
                | \strlen($url) << self::W58_EMBED_LENGTH_SHIFT;
            $words[] = crc32($url);
            $words[] = \strlen($query);
        }

        return $words;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or Rust declines the batch (a
     * row holding a NUL byte).
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function embedUrlBatchNative(array $rows): array
    {
        $count = \count($rows);
        /** @var array{0: FFI, 1: CData, 2: int}|null $buffer */
        $buffer = $count === 0 ? null : self::w58RustFfiState('out', $count * self::W58_EMBED_WORDS);
        if ($buffer === null) {
            return self::embedUrlBatchPhp($rows);
        }
        [$ffi, $out, $cap] = $buffer;
        $joined = implode("\0", $rows);
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w581_grafana_embed_batch($joined, \strlen($joined), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        $words = $written === $count ? unpack('q*', FFI::string($out, $count * self::W58_EMBED_WORDS * 8)) : false;
        if (! \is_array($words)) {
            return self::embedUrlBatchPhp($rows);
        }

        /** @var list<int> */
        return array_values($words);
    }

    /**
     * Wave 58 routing override (`set`) plus the lazily resolved binding and reusable `int64_t`
     * output buffer (`out`, sized for `$val` words), kept in function-local statics.
     */
    private static function w58RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'out':
                $ffi ??= RustPipelineLibrary::resolveDomain('grafana_embed_w58', self::W58_GRAFANA_EMBED_FFI_CDEF) ?? false;
                if (! $ffi instanceof FFI || ! \is_int($val) || $val < 1) {
                    return null;
                }
                if (! $out instanceof CData || $outCap < $val) {
                    $outCap = max(1024, $val * 2);
                    $out = $ffi->new("int64_t[{$outCap}]");
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled;
        }
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

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 7.63x, p99 5.04x; uncommon mean 7.10x, p99 4.59x); see RustMonitoringHorizonVerificationStatusTmuxGrafanaEmbedW68ParityTest. */
    public const bool W68_RUST_FFI_DEFAULT = true;

    public const string W68_GRAFANA_EMBED_SERVICE_FFI_CDEF = <<<'C'
        ssize_t nntmux_w683_grafana_embed_service_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of the Wave 68.3 batch. */
    public const int W68_GRAFANA_EMBED_SERVICE_TRAILER = 30;

    public static function enableW68RustFfi(): void
    {
        self::w68RustFfiState('set', true);
    }

    public static function disableW68RustFfi(): void
    {
        self::w68RustFfiState('set', false);
    }

    /** Restores W68_RUST_FFI_DEFAULT and drops the cached library handle and scratch buffers. */
    public static function resetW68RustFfi(): void
    {
        self::w68RustFfiState('reset');
    }

    public static function usesW68RustFfi(): bool
    {
        $override = self::w68RustFfiState();

        return \is_bool($override) ? $override : self::W68_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the GrafanaEmbedService Wave 68 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW68RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w68RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w68RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 68.3 Grafana embed plan. Each item is `uid|panelId|from|to|theme|kiosk|orgId`. The trimmed UID is 0 empty,
     * 1 up to W68_GRAFANA_UID_MAX `[A-Za-z0-9_-]` bytes or 2 other. The panel (9 digits) makes kind 1 dashboard
     * (empty), 2 panel (positive) or 3 invalid. `from` and `to` are 0 empty (`now-6h` and `now`), 1 relative
     * (`now[-<1-6 digits><s|m|h|d|w|M|y>][/<unit>]`, as seconds before now), 2 epoch milliseconds (1-13 digits) or 3
     * invalid. Theme is 0 empty, 1 light, 2 dark, 3 system or 4 other; kiosk is 0 empty (kiosk), 1 kiosk (1, true,
     * kiosk), 2 tv, 3 off (0, false, off) or 4 other; the org (9 digits) is 0 empty (org 1), 1 positive or 2 other.
     * The span is from - to offsets for relative ranges or (to - from) / 1000 for absolute ones. Decision 5 malformed,
     * 0 skipped (no UID), 2 mixed relative and absolute range, 1 empty or inverted range, 4 panel or 3 dashboard
     * embed. Embeds get the proxy URL length (`/d/` or `/d-solo/` + uid + `?orgId=`, `&from=`, `&to=`, `&theme=`,
     * `&kiosk` or `&kiosk=tv`, `&panelId=`) and the 32-bit FNV-1a signature of `uid, panel, from, to, theme, kiosk,
     * org` joined by newlines. Per item `uidState | (kind - 1) << 2 | fromState << 4 | toState << 6 | theme << 8 |
     * kiosk << 11 | orgState << 14 | decision << 16 | urlLength (cap 1023) << 19 | signature << 29`. Then the six
     * decision, four from-state, four to-state, five theme and five kiosk counts, the distinct valid UIDs and
     * distinct embedded panels, the longest embedded span, the URL length sum, the 1-based first panel embed and the
     * XOR of the embed signatures.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w68GrafanaEmbedServicePlanBatch(array $items): array
    {
        return self::usesW68RustFfi() ? self::w68GrafanaEmbedServicePlanBatchNative($items) : self::w68GrafanaEmbedServicePlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w68GrafanaEmbedServicePlanBatchNative(array $items): array
    {
        return self::w68Invoke($items, \count($items) + self::W68_GRAFANA_EMBED_SERVICE_TRAILER) ?? self::w68GrafanaEmbedServicePlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w68GrafanaEmbedServicePlanBatchPhp(array $items): array
    {
        $out = [];
        $decisions = [0, 0, 0, 0, 0, 0];
        $fromStates = $toStates = [0, 0, 0, 0];
        $themes = $kiosks = [0, 0, 0, 0, 0];
        $maxSpan = $urlSum = $firstPanel = $signatures = 0;
        $uids = $panels = [];
        $index = 0;
        foreach ($items as $item) {
            [$rawUid, $rawPanel, $rawFrom, $rawTo, $rawTheme, $rawKiosk, $rawOrg] = explode('|', (string) $item, 7) + ['', '', '', '', '', '', ''];
            $uid = trim($rawUid);
            $uidLength = \strlen($uid);
            $uidState = $uidLength === 0 ? 0 : ($uidLength <= self::W68_GRAFANA_UID_MAX && strspn($uid, self::W68_GRAFANA_UID_BYTES) === $uidLength ? 1 : 2);
            [$panelState, $panel] = self::w68Count($rawPanel, 9);
            $kind = $panelState === 0 ? 1 : ($panelState === 1 && $panel > 0 ? 2 : 3);
            $from = trim($rawFrom);
            $to = trim($rawTo);
            [$fromState, $fromValue] = self::w68GrafanaRange($from);
            [$toState, $toValue] = self::w68GrafanaRange($to);
            if ($fromState === 0) {
                $from = 'now-6h';
                $fromValue = 21600;
            }
            if ($toState === 0) {
                $to = 'now';
            }
            $theme = self::w68Keyword($rawTheme, self::W68_GRAFANA_THEMES);
            $kiosk = self::W68_GRAFANA_KIOSK_MODES[self::w68Keyword($rawKiosk, self::W68_GRAFANA_KIOSK_WORDS)];
            [$orgState, $org] = self::w68Count($rawOrg, 9);
            if ($orgState === 0) {
                $org = 1;
            } elseif ($orgState === 1 && $org === 0) {
                $orgState = 2;
            }
            $relative = $fromState <= 1 && $toState <= 1;
            $absolute = $fromState === 2 && $toState === 2;
            $span = $relative ? $fromValue - $toValue : ($absolute ? intdiv($toValue - $fromValue, 1000) : 0);
            if ($uidState === 2 || $kind === 3 || $fromState === 3 || $toState === 3 || $theme === 4 || $kiosk === 4 || $orgState === 2) {
                $decision = 5;
            } elseif ($uidState === 0) {
                $decision = 0;
            } elseif (! $relative && ! $absolute) {
                $decision = 2;
            } elseif ($span <= 0) {
                $decision = 1;
            } elseif ($kind === 2) {
                $decision = 4;
            } else {
                $decision = 3;
            }
            $urlLength = $signature = 0;
            if ($decision === 3 || $decision === 4) {
                $urlLength = ($kind === 2 ? 17 + \strlen((string) $panel) : 3) + $uidLength + 7 + \strlen((string) $org) + 6 + \strlen($from) + 4 + \strlen($to)
                    + ($theme === 0 ? 0 : 7 + \strlen(self::W68_GRAFANA_THEMES[$theme - 1])) + ($kiosk <= 1 ? 6 : ($kiosk === 2 ? 9 : 0));
                $signature = unpack('N', hash('fnv1a32', $uid."\n".$panel."\n".$from."\n".$to."\n".$theme."\n".$kiosk."\n".$org, true))[1];
                $maxSpan = max($maxSpan, $span);
                $urlSum = min($urlSum + $urlLength, self::W68_TOTAL_CAP);
                $signatures ^= $signature;
            }
            if ($decision === 4) {
                $panels[$uid."\n".$panel] = true;
                if ($firstPanel === 0) {
                    $firstPanel = $index + 1;
                }
            }
            if ($uidState === 1) {
                $uids[$uid] = true;
            }
            $decisions[$decision]++;
            $fromStates[$fromState]++;
            $toStates[$toState]++;
            $themes[$theme]++;
            $kiosks[$kiosk]++;
            $out[] = $uidState | ($kind - 1) << 2 | $fromState << 4 | $toState << 6 | $theme << 8 | $kiosk << 11 | $orgState << 14 | $decision << 16
                | min($urlLength, 1023) << 19 | $signature << 29;
            $index++;
        }
        array_push($out, ...$decisions);
        array_push($out, ...$fromStates);
        array_push($out, ...$toStates);
        array_push($out, ...$themes);
        array_push($out, ...$kiosks);
        array_push($out, \count($uids), \count($panels), $maxSpan, $urlSum, $firstPanel, $signatures);

        return $out;
    }

    /** Wave 68.3 themes, in plan order. */
    private const array W68_GRAFANA_THEMES = ['light', 'dark', 'system'];

    /** Wave 68.3 kiosk words. */
    private const array W68_GRAFANA_KIOSK_WORDS = ['1', 'true', 'kiosk', 'tv', '0', 'false', 'off'];

    /** Kiosk mode by W68_GRAFANA_KIOSK_WORDS keyword: 0 default, 1 kiosk, 2 tv, 3 off, 4 other. */
    private const array W68_GRAFANA_KIOSK_MODES = [0, 1, 1, 1, 2, 3, 3, 3, 4];

    /** Seconds per Wave 68.3 relative time unit. */
    private const array W68_GRAFANA_UNITS = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800, 'M' => 2592000, 'y' => 31536000];

    /** Bytes allowed in a Wave 68.3 dashboard UID. */
    private const string W68_GRAFANA_UID_BYTES = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-';

    /** Longest valid Wave 68.3 dashboard UID. */
    public const int W68_GRAFANA_UID_MAX = 40;

    /** Cap for Wave 68.3 running totals. */
    private const int W68_TOTAL_CAP = 1 << 62;

    /**
     * (0 empty, 1 relative, 2 epoch milliseconds, 3 invalid; the seconds before now or the milliseconds) for a
     * trimmed Wave 68.3 time-range field.
     *
     * @return array{int, int}
     */
    private static function w68GrafanaRange(string $text): array
    {
        if ($text === '') {
            return [0, 0];
        }
        if (preg_match('/\Anow(?:-(\d{1,6})([smhdwMy]))?(?:\/[smhdwMy])?\z/', $text, $match) === 1) {
            return [1, isset($match[1]) && $match[1] !== '' ? (int) $match[1] * self::W68_GRAFANA_UNITS[$match[2]] : 0];
        }
        $length = \strlen($text);

        return $length <= 13 && strspn($text, '0123456789') === $length ? [2, (int) $text] : [3, 0];
    }

    /**
     * (0 empty, 1 one to $max ASCII digits, 2 other; the value) for the trimmed field.
     *
     * @return array{int, int}
     */
    private static function w68Count(string $text, int $max): array
    {
        $text = trim($text);
        if ($text === '') {
            return [0, 0];
        }
        $length = \strlen($text);

        return $length <= $max && strspn($text, '0123456789') === $length ? [1, (int) $text] : [2, 0];
    }

    /**
     * The trimmed field, lower-cased and matched against $words: 0 empty, 1-based index, or the word count + 1.
     *
     * @param  list<string>  $words
     */
    private static function w68Keyword(string $text, array $words): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }
        $position = array_search(strtolower($text), $words, true);

        return $position === false ? \count($words) + 1 : $position + 1;
    }

    /**
     * Wave 68 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and returns the
     * previous override, 'reset' clears the override, library handle and buffers, 'ffi' resolves the library
     * (null when unavailable) and 'out' and 'lens' return `int64_t` output and length-column buffers of at least
     * $val words. Function statics keep the class's property set unchanged.
     */
    private static function w68RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'reset') {
            $override = $ffi = $out = $lens = null;
            $outCap = $lensCap = 0;

            return null;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('monitoring_w683', self::W68_GRAFANA_EMBED_SERVICE_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w68RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w68RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 68.3 GrafanaEmbedService export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call declines
     * or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w68Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w68RustFfiState('ffi');
            $out = self::w68RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w68RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W68_GRAFANA_EMBED_SERVICE_FFI_CDEF.)
            $written = $ffi->nntmux_w683_grafana_embed_service_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
