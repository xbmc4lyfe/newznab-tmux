<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Models\User;
use App\Services\Monitoring\GrafanaEmbedService;
use App\Services\Monitoring\GrafanaJwtIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Cookie;

class AdminMonitoringController extends BasePageController
{
    // PHP remains the default unless every common and uncommon workload passes the 1.5x gate.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi ?? false;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    /**
     * @return array{enabled: bool, monitoringConfigured: bool, defaultRange: string, defaultRefresh: string}
     */
    public static function buildViewConfiguration(bool $enabled, bool $monitoringConfigured, string $defaultRange, string $defaultRefresh): array
    {
        return self::$useRustFfi ? self::buildViewConfigurationNative($enabled, $monitoringConfigured, $defaultRange, $defaultRefresh) : self::buildViewConfigurationPhp($enabled, $monitoringConfigured, $defaultRange, $defaultRefresh);
    }

    /**
     * @return array{enabled: bool, monitoringConfigured: bool, defaultRange: string, defaultRefresh: string}
     */
    public static function buildViewConfigurationPhp(bool $enabled, bool $monitoringConfigured, string $defaultRange, string $defaultRefresh): array
    {
        return ['enabled' => $enabled, 'monitoringConfigured' => $monitoringConfigured, 'defaultRange' => $defaultRange, 'defaultRefresh' => $defaultRefresh];
    }

    /**
     * @return array{enabled: bool, monitoringConfigured: bool, defaultRange: string, defaultRefresh: string}
     */
    public static function buildViewConfigurationNative(bool $enabled, bool $monitoringConfigured, string $defaultRange, string $defaultRefresh): array
    {
        $result = AdminPaymentController::transformNative(5, [$enabled, $monitoringConfigured, $defaultRange, $defaultRefresh]);

        return is_array($result) && isset($result['enabled'], $result['monitoringConfigured'], $result['defaultRange'], $result['defaultRefresh']) ? $result : self::buildViewConfigurationPhp($enabled, $monitoringConfigured, $defaultRange, $defaultRefresh);
    }

    public static function cookieSecure(bool $secure, bool $sessionSecure): bool
    {
        return self::$useRustFfi ? self::cookieSecureNative($secure, $sessionSecure) : self::cookieSecurePhp($secure, $sessionSecure);
    }

    public static function cookieSecurePhp(bool $secure, bool $sessionSecure): bool
    {
        return $secure || $sessionSecure;
    }

    public static function cookieSecureNative(bool $secure, bool $sessionSecure): bool
    {
        $result = AdminPaymentController::transformNative(6, [$secure, $sessionSecure]);

        return is_bool($result) ? $result : self::cookieSecurePhp($secure, $sessionSecure);
    }

    public static function validAuthRequest(mixed $serverParam, bool $enabled): bool
    {
        return self::$useRustFfi ? self::validAuthRequestNative($serverParam, $enabled) : self::validAuthRequestPhp($serverParam, $enabled);
    }

    public static function validAuthRequestPhp(mixed $serverParam, bool $enabled): bool
    {
        return $serverParam === '1' && $enabled;
    }

    public static function validAuthRequestNative(mixed $serverParam, bool $enabled): bool
    {
        $result = AdminPaymentController::transformNative(7, [$serverParam, $enabled]);

        return is_bool($result) ? $result : self::validAuthRequestPhp($serverParam, $enabled);
    }

    /**
     * FastCGI parameter set only by the installer's internal nginx auth
     * location. Browsers cannot set it: client headers arrive as HTTP_*.
     */
    public const string AUTH_REQUEST_PARAM = 'NNTMUX_GRAFANA_AUTH_REQUEST';

    public const string AUTH_REQUEST_HEADER = 'X-NNTmux-Grafana-JWT';

    public function index(GrafanaEmbedService $grafana): View
    {
        $this->setAdminPrefs();

        $enabled = $grafana->isEnabled();

        return view('admin.monitoring', [
            ...self::buildViewConfiguration(
                $enabled,
                (bool) config('monitoring.enabled'),
                (string) config('monitoring.grafana.default_range', 'now-6h'),
                (string) config('monitoring.grafana.default_refresh', '1m'),
            ),
            'dashboards' => $enabled ? $grafana->dashboards() : [],
            'tokenUrl' => $grafana->tokenUrl(),
            'title' => 'Monitoring',
            'meta_title' => 'Monitoring',
            'meta_description' => 'Grafana dashboards for host, service and processing metrics.',
        ]);
    }

    /**
     * Cookie mode (Apache): sets the short-lived Grafana login token as an
     * HttpOnly cookie scoped to the Grafana path, where the web server copies
     * it into Grafana's JWT header. The token never reaches page scripts or URLs.
     */
    public function token(Request $request, GrafanaEmbedService $grafana, GrafanaJwtIssuer $issuer): JsonResponse
    {
        if (! $grafana->isEnabled() || $grafana->authMode() !== GrafanaEmbedService::AUTH_COOKIE) {
            return response()->json(['message' => 'Monitoring is not configured.'], 404)
                ->header('Cache-Control', 'no-store');
        }

        /** @var User $user */
        $user = $request->user();
        $issued = $issuer->issueFor($user);

        $response = response()->json(['expires_at' => $issued['expires_at'], 'ttl' => $issued['ttl']])
            ->header('Cache-Control', 'no-store');
        $response->headers->setCookie(Cookie::create(
            GrafanaJwtIssuer::COOKIE,
            $issued['token'],
            $issued['expires_at'],
            $grafana->cookiePath(),
            null,
            self::cookieSecure($request->isSecure(), (bool) config('session.secure')),
            true,
            false,
            Cookie::SAMESITE_STRICT,
        ));

        return $response;
    }

    /**
     * Proxy mode (nginx auth_request): nginx asks this on every Grafana request,
     * so logging out or losing the Admin role locks Grafana out immediately.
     * The token goes back in a header that nginx forwards to Grafana only.
     */
    public function authorizeGrafana(Request $request, GrafanaEmbedService $grafana, GrafanaJwtIssuer $issuer): Response
    {
        if (! self::validAuthRequest($request->server(self::AUTH_REQUEST_PARAM), $grafana->isEnabled())) {
            abort(404);
        }

        /** @var User $user */
        $user = $request->user();

        return response()->noContent()
            ->header(self::AUTH_REQUEST_HEADER, $issuer->issueFor($user)['token'])
            ->header('Cache-Control', 'no-store');
    }
}
