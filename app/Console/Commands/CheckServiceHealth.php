<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SiteStatusService;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Console\Command;
use Throwable;

class CheckServiceHealth extends Command
{
    private const FFI_CDEF = <<<'CDEF'
int32_t nntmux_check_service_health_is_valid_app_url(const char *url_ptr, size_t url_len);
ssize_t nntmux_check_service_health_result_row(
    const char *name_ptr,
    size_t name_len,
    const char *type_ptr,
    size_t type_len,
    int64_t status_code,
    int64_t response_time_ms,
    uint8_t ok,
    const char *reason_ptr,
    size_t reason_len,
    uint8_t *out_buf,
    size_t out_cap,
    int64_t *out_lens
);
ssize_t nntmux_check_service_health_summary_row(
    const char *name_ptr,
    size_t name_len,
    const char *label_ptr,
    size_t label_len,
    double uptime_percentage,
    uint8_t has_last_checked,
    const char *checked_ptr,
    size_t checked_len,
    uint8_t *out_buf,
    size_t out_cap,
    int64_t *out_lens
);
CDEF;

    private const HEALTH_ROW_SEGMENTS = 5;

    private const SUMMARY_ROW_SEGMENTS = 4;

    /**
     * Rust FFI stays opt-in: these table projections are too small for FFI to beat PHP by 1.5x.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    protected $signature = 'nntmux:check-service-health
                            {--days=30 : Rolling window in days for uptime calculation}';

    protected $description = 'Ping service endpoints, auto-create/resolve incidents, and recalculate uptime';

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

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = (bool) $useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi = null): void
    {
        self::$ffi = $ffi;
    }

    public function handle(SiteStatusService $statusService): int
    {
        $days = (int) $this->option('days');

        if (! self::isValidAppUrl((string) config('app.url'))) {
            $this->components->warn('APP_URL is not set — health checks will be skipped. Set APP_URL in .env to enable endpoint monitoring.');
        }

        $services = $statusService->getEnabledServices();

        $rows = [];

        foreach ($services as $service) {
            if ($service->endpoint_url === null || $service->endpoint_url === '') {
                if ($service->check_type === 'probe') {
                    $result = $statusService->checkServiceHealth($service);
                    $statusService->handleHealthCheckResult($service, $result);

                    $rows[] = self::projectHealthResultRow($service->name, $service->check_type, $result);
                } else {
                    $rows[] = [$service->name, strtoupper($service->check_type), '—', '—', 'Skipped (no endpoint)'];
                }

                continue;
            }

            $result = $statusService->checkServiceHealth($service);
            $statusService->handleHealthCheckResult($service, $result);

            $rows[] = self::projectHealthResultRow($service->name, $service->check_type, $result);
        }

        $statusService->refreshAllServiceUptime($days);

        $this->table(['Service', 'Type', 'HTTP', 'Response', 'Result'], $rows);

        $services = $statusService->getEnabledServices();
        $summary = $services->map(fn ($s) => self::projectServiceSummaryRow(
            $s->name,
            $s->status->label(),
            (float) $s->uptime_percentage,
            $s->last_checked_at?->diffForHumans()
        ))->all();

        $this->newLine();
        $this->table(['Service', 'Status', 'Uptime', 'Checked'], $summary);

        return self::SUCCESS;
    }

    public static function isValidAppUrl(string $baseUrl): bool
    {
        if (self::$useRustFfi) {
            return self::isValidAppUrlNative($baseUrl);
        }

        return self::isValidAppUrlPhp($baseUrl);
    }

    public static function isValidAppUrlPhp(string $baseUrl): bool
    {
        $trimmed = rtrim($baseUrl, '/');

        return $trimmed !== '' && str_starts_with($trimmed, 'http');
    }

    public static function isValidAppUrlNative(string $baseUrl): bool
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::isValidAppUrlPhp($baseUrl);
        }

        try {
            // @phpstan-ignore method.notFound
            $result = $ffi->nntmux_check_service_health_is_valid_app_url($baseUrl, strlen($baseUrl));

            return match ($result) {
                1 => true,
                0 => false,
                default => self::isValidAppUrlPhp($baseUrl),
            };
        } catch (Throwable) {
            return self::isValidAppUrlPhp($baseUrl);
        }
    }

    /**
     * @param  array<string, mixed>  $result  `SiteStatusService::checkServiceHealth()` result.
     * @return list<string>
     */
    public static function projectHealthResultRow(string $name, string $checkType, array $result): array
    {
        if (self::$useRustFfi) {
            return self::projectHealthResultRowNative($name, $checkType, $result);
        }

        return self::projectHealthResultRowPhp($name, $checkType, $result);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    public static function projectHealthResultRowPhp(string $name, string $checkType, array $result): array
    {
        return [
            $name,
            strtoupper($checkType),
            (string) ($result['status_code'] ?: '—'),
            $result['response_time_ms'] ? $result['response_time_ms'].'ms' : '—',
            $result['ok'] ? 'Healthy' : (string) $result['reason'],
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    public static function projectHealthResultRowNative(string $name, string $checkType, array $result): array
    {
        $statusCode = $result['status_code'] ?? null;
        $responseTimeMs = $result['response_time_ms'] ?? null;
        $ok = $result['ok'] ?? null;
        $reason = $ok === true ? '' : ($result['reason'] ?? null);
        if (! is_int($statusCode) || ! is_int($responseTimeMs) || ! is_bool($ok) || ! is_string($reason)) {
            return self::projectHealthResultRowPhp($name, $checkType, $result);
        }

        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::projectHealthResultRowPhp($name, $checkType, $result);
        }

        try {
            $cap = strlen($name) + strlen($checkType) + strlen($reason) + 96;
            $outBuf = $ffi->new("uint8_t[{$cap}]");
            $outLens = $ffi->new('int64_t['.self::HEALTH_ROW_SEGMENTS.']');

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_check_service_health_result_row(
                $name,
                strlen($name),
                $checkType,
                strlen($checkType),
                $statusCode,
                $responseTimeMs,
                $ok ? 1 : 0,
                $reason,
                strlen($reason),
                $outBuf,
                $cap,
                $outLens
            );
            $row = is_int($written) ? self::decodeSegments($outBuf, $outLens, self::HEALTH_ROW_SEGMENTS, $written) : null;

            return $row ?? self::projectHealthResultRowPhp($name, $checkType, $result);
        } catch (Throwable) {
            return self::projectHealthResultRowPhp($name, $checkType, $result);
        }
    }

    /**
     * @return list<string>
     */
    public static function projectServiceSummaryRow(string $name, string $statusLabel, float $uptimePercentage, ?string $lastChecked): array
    {
        if (self::$useRustFfi) {
            return self::projectServiceSummaryRowNative($name, $statusLabel, $uptimePercentage, $lastChecked);
        }

        return self::projectServiceSummaryRowPhp($name, $statusLabel, $uptimePercentage, $lastChecked);
    }

    /**
     * @return list<string>
     */
    public static function projectServiceSummaryRowPhp(string $name, string $statusLabel, float $uptimePercentage, ?string $lastChecked): array
    {
        return [
            $name,
            $statusLabel,
            number_format($uptimePercentage, 2).'%',
            $lastChecked ?? 'never',
        ];
    }

    /**
     * @return list<string>
     */
    public static function projectServiceSummaryRowNative(string $name, string $statusLabel, float $uptimePercentage, ?string $lastChecked): array
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::projectServiceSummaryRowPhp($name, $statusLabel, $uptimePercentage, $lastChecked);
        }

        try {
            $checked = $lastChecked ?? '';
            $cap = strlen($name) + strlen($statusLabel) + strlen($checked) + 64;
            $outBuf = $ffi->new("uint8_t[{$cap}]");
            $outLens = $ffi->new('int64_t['.self::SUMMARY_ROW_SEGMENTS.']');

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_check_service_health_summary_row(
                $name,
                strlen($name),
                $statusLabel,
                strlen($statusLabel),
                $uptimePercentage,
                $lastChecked !== null ? 1 : 0,
                $checked,
                strlen($checked),
                $outBuf,
                $cap,
                $outLens
            );
            $row = is_int($written) ? self::decodeSegments($outBuf, $outLens, self::SUMMARY_ROW_SEGMENTS, $written) : null;

            return $row ?? self::projectServiceSummaryRowPhp($name, $statusLabel, $uptimePercentage, $lastChecked);
        } catch (Throwable) {
            return self::projectServiceSummaryRowPhp($name, $statusLabel, $uptimePercentage, $lastChecked);
        }
    }

    /**
     * Splits length-prefixed segments written by Rust (segments may contain NUL bytes).
     *
     * @return list<string>|null
     */
    private static function decodeSegments(CData $outBuf, CData $outLens, int $count, int $written): ?array
    {
        if ($written <= 0) {
            return null;
        }

        $bytes = FFI::string($outBuf, $written);
        $segments = [];
        $offset = 0;
        for ($i = 0; $i < $count; $i++) {
            $length = $outLens[$i];
            if (! is_int($length) || $length < 0 || $offset + $length > $written) {
                return null;
            }
            $segments[] = substr($bytes, $offset, $length);
            $offset += $length;
        }

        return $offset === $written ? $segments : null;
    }

    private static function additionalFfi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
