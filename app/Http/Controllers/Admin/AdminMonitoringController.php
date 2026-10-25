<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Console\Commands\NntmuxSearchReconcile;
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

    /**
     * Rust stays opt-in for the Wave 39.5 summary: the FFI round trip misses the 1.5x gate; see
     * tests/Unit/RustAdminSettingsMonitoringLogsReconcileW39ParityTest.php.
     */
    public const bool W395_RUST_FFI_DEFAULT = false;

    private static ?bool $useW395RustFfi = null;

    /** Latency samples above one day are rejected as invalid. */
    private const int MAX_LATENCY_MS = 86_400_000;

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
    public static function useW395RustFfi(?bool $use = true): void
    {
        self::$useW395RustFfi = $use;
    }

    public static function usesW395RustFfi(): bool
    {
        return self::$useW395RustFfi ?? self::W395_RUST_FFI_DEFAULT;
    }

    public static function resetW395RustFfi(): void
    {
        self::$useW395RustFfi = null;
    }

    /**
     * Summarizes queue latency samples (milliseconds). Only integers from 0 to one day are valid;
     * other samples count as invalid. A valid sample above the SLA threshold is a breach, and the
     * queue is healthy without breaches or invalid samples. The mean is rounded to three decimals.
     *
     * @param  array<array-key, mixed>  $samplesMs
     * @return array{samples: int, valid: int, invalid: int, min_ms: ?int, max_ms: ?int, mean_ms: float, sla_breaches: int, healthy: bool}
     */
    public static function summarizeQueueLatencySnapshots(array $samplesMs, int $slaThresholdMs = 5000): array
    {
        return self::usesW395RustFfi()
            ? self::summarizeQueueLatencySnapshotsNative($samplesMs, $slaThresholdMs)
            : self::summarizeQueueLatencySnapshotsPhp($samplesMs, $slaThresholdMs);
    }

    /**
     * @param  array<array-key, mixed>  $samplesMs
     * @return array{samples: int, valid: int, invalid: int, min_ms: ?int, max_ms: ?int, mean_ms: float, sla_breaches: int, healthy: bool}
     */
    private static function summarizeQueueLatencySnapshotsNative(array $samplesMs, int $slaThresholdMs): array
    {
        $parts = [(string) $slaThresholdMs];
        foreach ($samplesMs as $ms) {
            $parts[] = \is_int($ms) ? (string) $ms : '';
        }
        $fields = NntmuxSearchReconcile::evaluateW395Native(2, $parts);
        if ($fields === null || \count($fields) !== 8) {
            return self::summarizeQueueLatencySnapshotsPhp($samplesMs, $slaThresholdMs);
        }

        return self::latencySummary(
            (int) $fields[0],
            (int) $fields[1],
            $fields[3] === '' ? null : (int) $fields[3],
            $fields[4] === '' ? null : (int) $fields[4],
            (int) $fields[5],
            (int) $fields[6],
        );
    }

    /**
     * @param  array<array-key, mixed>  $samplesMs
     * @return array{samples: int, valid: int, invalid: int, min_ms: ?int, max_ms: ?int, mean_ms: float, sla_breaches: int, healthy: bool}
     */
    private static function summarizeQueueLatencySnapshotsPhp(array $samplesMs, int $slaThresholdMs): array
    {
        $valid = $sum = $breaches = 0;
        $min = $max = null;
        foreach ($samplesMs as $ms) {
            if (! \is_int($ms) || $ms < 0 || $ms > self::MAX_LATENCY_MS) {
                continue;
            }
            $valid++;
            $sum += $ms;
            $min = $min === null ? $ms : min($min, $ms);
            $max = $max === null ? $ms : max($max, $ms);
            if ($ms > $slaThresholdMs) {
                $breaches++;
            }
        }

        return self::latencySummary(\count($samplesMs), $valid, $min, $max, $sum, $breaches);
    }

    /**
     * @return array{samples: int, valid: int, invalid: int, min_ms: ?int, max_ms: ?int, mean_ms: float, sla_breaches: int, healthy: bool}
     */
    private static function latencySummary(int $samples, int $valid, ?int $min, ?int $max, int $sum, int $breaches): array
    {
        return [
            'samples' => $samples,
            'valid' => $valid,
            'invalid' => $samples - $valid,
            'min_ms' => $min,
            'max_ms' => $max,
            'mean_ms' => $valid > 0 ? round($sum / $valid, 3) : 0.0,
            'sla_breaches' => $breaches,
            'healthy' => $samples === $valid && $breaches === 0,
        ];
    }

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
