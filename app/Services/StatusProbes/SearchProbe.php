<?php

declare(strict_types=1);

namespace App\Services\StatusProbes;

use App\Enums\IncidentImpactEnum;
use App\Services\Search\SearchService;
use App\Services\StatusProbes\Contracts\ServiceProbeInterface;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Str;
use Throwable;

class SearchProbe implements ServiceProbeInterface
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_search_probe_evaluate(uint8_t available, const char *driver_ptr, size_t driver_len, int64_t start_ns, int64_t end_ns, int64_t *out_elapsed_ms, int32_t *out_verdict, char *out_buf, size_t out_cap);
        ssize_t nntmux_status_probe_format_failure(uint8_t probe_kind, const char *error_ptr, size_t error_len, char *out_buf, size_t out_cap);
        C;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private static ?CData $elapsedSlot = null;

    private static ?CData $verdictSlot = null;

    private static ?FFI $slotOwner = null;

    public function __construct(
        private readonly SearchService $searchService,
    ) {}

    public static function enableRustFfi(): void
    {
        self::setUseRustFfi(true);
    }

    public static function disableRustFfi(): void
    {
        self::setUseRustFfi(false);
    }

    public static function resetRustFfi(): void
    {
        self::setUseRustFfi(null);
    }

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use ?? false;
        if ($use === null) {
            self::$ffi = null;
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::$ffi = $override;
    }

    public static function mediaFfi(bool $reset = false, FFI|false|null $override = null): ?FFI
    {
        if ($reset) {
            self::$ffi = $override;
            if ($override !== null) {
                return self::$ffi === false ? null : self::$ffi;
            }
        }
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('media', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    public function identifier(): string
    {
        return 'search';
    }

    public function probe(): ProbeResult
    {
        try {
            $start = hrtime(true);
            $available = $this->searchService->isAvailable();
            $end = hrtime(true);

            return self::evaluateAvailability($available, $this->searchService->getCurrentDriver(), $start, $end);
        } catch (Throwable $e) {
            return self::formatFailureResult($e->getMessage());
        }
    }

    public static function evaluateAvailability(
        bool $available,
        string $driver,
        int $startNs,
        int $endNs,
    ): ProbeResult {
        return self::$useRustFfi
            ? self::evaluateAvailabilityNative($available, $driver, $startNs, $endNs)
            : self::evaluateAvailabilityPhp($available, $driver, $startNs, $endNs);
    }

    public static function evaluateAvailabilityPhp(
        bool $available,
        string $driver,
        int $startNs,
        int $endNs,
    ): ProbeResult {
        $elapsed = (int) (($endNs - $startNs) / 1_000_000);

        if (! $available) {
            return new ProbeResult(
                ok: false,
                responseTimeMs: $elapsed,
                impact: IncidentImpactEnum::Critical,
                reason: sprintf('Search driver "%s" unavailable', $driver),
            );
        }

        return new ProbeResult(
            ok: true,
            responseTimeMs: $elapsed,
            impact: null,
            reason: sprintf('Search driver "%s" available', $driver),
        );
    }

    public static function evaluateAvailabilityNative(
        bool $available,
        string $driver,
        int $startNs,
        int $endNs,
    ): ProbeResult {
        $ffi = self::mediaFfi();
        if (
            $ffi === null
            || ! self::ensureSlots($ffi)
            || self::$elapsedSlot === null
            || self::$verdictSlot === null
        ) {
            return self::evaluateAvailabilityPhp($available, $driver, $startNs, $endNs);
        }

        $driverLen = \strlen($driver);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(128, $driverLen + 48), $cap);
        if ($scratch === null) {
            return self::evaluateAvailabilityPhp($available, $driver, $startNs, $endNs);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_search_probe_evaluate(
                $available ? 1 : 0,
                $driver,
                $driverLen,
                $startNs,
                $endNs,
                self::$elapsedSlot,
                self::$verdictSlot,
                $scratch,
                $cap,
            );
            if (\is_int($written) && $written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return self::evaluateAvailabilityPhp($available, $driver, $startNs, $endNs);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_search_probe_evaluate(
                    $available ? 1 : 0,
                    $driver,
                    $driverLen,
                    $startNs,
                    $endNs,
                    self::$elapsedSlot,
                    self::$verdictSlot,
                    $scratch,
                    $cap,
                );
            }
            if (! \is_int($written) || $written <= 0 || $written > $cap) {
                return self::evaluateAvailabilityPhp($available, $driver, $startNs, $endNs);
            }

            /** @var int $elapsed */
            $elapsed = self::$elapsedSlot[0];
            /** @var int $verdict */
            $verdict = self::$verdictSlot[0];
            $reason = FFI::string($scratch, $written);

            return match ($verdict) {
                0 => new ProbeResult(
                    ok: true,
                    responseTimeMs: $elapsed,
                    impact: null,
                    reason: $reason,
                ),
                2 => new ProbeResult(
                    ok: false,
                    responseTimeMs: $elapsed,
                    impact: IncidentImpactEnum::Critical,
                    reason: $reason,
                ),
                default => self::evaluateAvailabilityPhp($available, $driver, $startNs, $endNs),
            };
        } catch (Throwable) {
            return self::evaluateAvailabilityPhp($available, $driver, $startNs, $endNs);
        }
    }

    public static function formatFailureResult(string $errorMessage): ProbeResult
    {
        return self::$useRustFfi
            ? self::formatFailureResultNative($errorMessage)
            : self::formatFailureResultPhp($errorMessage);
    }

    public static function formatFailureResultPhp(string $errorMessage): ProbeResult
    {
        return new ProbeResult(
            ok: false,
            responseTimeMs: 0,
            impact: IncidentImpactEnum::Critical,
            reason: 'Search check failed: '.Str::limit($errorMessage, 120),
        );
    }

    public static function formatFailureResultNative(string $errorMessage): ProbeResult
    {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return self::formatFailureResultPhp($errorMessage);
        }

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 256, $cap);
        if ($scratch === null) {
            return self::formatFailureResultPhp($errorMessage);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_status_probe_format_failure(
                3,
                $errorMessage,
                \strlen($errorMessage),
                $scratch,
                $cap,
            );
            if (\is_int($written) && $written < -2) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return self::formatFailureResultPhp($errorMessage);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_status_probe_format_failure(
                    3,
                    $errorMessage,
                    \strlen($errorMessage),
                    $scratch,
                    $cap,
                );
            }
            if (\is_int($written) && $written > 0 && $written <= $cap) {
                return new ProbeResult(
                    ok: false,
                    responseTimeMs: 0,
                    impact: IncidentImpactEnum::Critical,
                    reason: FFI::string($scratch, $written),
                );
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return self::formatFailureResultPhp($errorMessage);
    }

    private static function ensureSlots(FFI $ffi): bool
    {
        if (self::$slotOwner !== $ffi || self::$elapsedSlot === null || self::$verdictSlot === null) {
            try {
                self::$elapsedSlot = $ffi->new('int64_t[1]');
                self::$verdictSlot = $ffi->new('int32_t[1]');
                self::$slotOwner = $ffi;
            } catch (Throwable) {
                return false;
            }
        }

        return true;
    }
}
