<?php

declare(strict_types=1);

namespace App\Services\StatusProbes;

use App\Enums\IncidentImpactEnum;
use App\Services\StatusProbes\Contracts\ServiceProbeInterface;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

class DiskProbe implements ServiceProbeInterface
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_disk_probe_bytes_to_gb(double free_bytes, double *out_gb);
        ssize_t nntmux_disk_probe_evaluate(const char *mount_ptr, size_t mount_len, double lowest_free_gb, double warning_threshold_gb, double critical_threshold_gb, int64_t *out_verdict, char *out_buf, size_t out_cap);
        C;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private static ?CData $doubleSlot = null;

    private static ?CData $verdictSlot = null;

    private static ?FFI $slotOwner = null;

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

    public static function categorizeFfi(bool $reset = false, FFI|false|null $override = null): ?FFI
    {
        if ($reset) {
            self::$ffi = $override;
            if ($override !== null) {
                return self::$ffi === false ? null : self::$ffi;
            }
        }
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('categorize', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    public function identifier(): string
    {
        return 'disk';
    }

    public function probe(): ProbeResult
    {
        $mountPoints = (array) config('status-probes.disk.mount_points', ['/']);
        $warningThresholdGb = (float) config('status-probes.disk.warning_threshold_gb', 5.0);
        $criticalThresholdGb = (float) config('status-probes.disk.critical_threshold_gb', 1.0);

        $lowestFreeGb = null;
        $worstMount = null;

        foreach ($mountPoints as $mountPoint) {
            $freeBytes = @disk_free_space((string) $mountPoint);
            if ($freeBytes === false) {
                return new ProbeResult(
                    ok: false,
                    responseTimeMs: 0,
                    impact: IncidentImpactEnum::Major,
                    reason: sprintf('Unable to read disk usage for mount "%s"', $mountPoint),
                );
            }

            $freeGb = self::bytesToFreeGb($freeBytes);

            if ($lowestFreeGb === null || $freeGb < $lowestFreeGb) {
                $lowestFreeGb = $freeGb;
                $worstMount = (string) $mountPoint;
            }
        }

        if ($lowestFreeGb === null || $worstMount === null) {
            return new ProbeResult(
                ok: false,
                responseTimeMs: 0,
                impact: IncidentImpactEnum::Major,
                reason: 'No disk mount points configured',
            );
        }

        return self::evaluateThresholds($worstMount, $lowestFreeGb, $warningThresholdGb, $criticalThresholdGb);
    }

    public static function bytesToFreeGb(float|int $freeBytes): float
    {
        return self::$useRustFfi
            ? self::bytesToFreeGbNative($freeBytes)
            : self::bytesToFreeGbPhp($freeBytes);
    }

    public static function bytesToFreeGbPhp(float|int $freeBytes): float
    {
        return round(((float) $freeBytes) / 1_073_741_824, 2);
    }

    public static function bytesToFreeGbNative(float|int $freeBytes): float
    {
        $ffi = self::categorizeFfi();
        if ($ffi === null || ! self::ensureSlots($ffi)) {
            return self::bytesToFreeGbPhp($freeBytes);
        }

        try {
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_disk_probe_bytes_to_gb((float) $freeBytes, self::$doubleSlot);
            if ($rc === 1 && self::$doubleSlot !== null) {
                /** @var float $gb */
                $gb = self::$doubleSlot[0];

                return $gb;
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return self::bytesToFreeGbPhp($freeBytes);
    }

    public static function evaluateThresholds(
        string $worstMount,
        float $lowestFreeGb,
        float $warningThresholdGb,
        float $criticalThresholdGb,
    ): ProbeResult {
        return self::$useRustFfi
            ? self::evaluateThresholdsNative($worstMount, $lowestFreeGb, $warningThresholdGb, $criticalThresholdGb)
            : self::evaluateThresholdsPhp($worstMount, $lowestFreeGb, $warningThresholdGb, $criticalThresholdGb);
    }

    public static function evaluateThresholdsPhp(
        string $worstMount,
        float $lowestFreeGb,
        float $warningThresholdGb,
        float $criticalThresholdGb,
    ): ProbeResult {
        if ($lowestFreeGb <= $criticalThresholdGb) {
            return new ProbeResult(
                ok: false,
                responseTimeMs: 0,
                impact: IncidentImpactEnum::Critical,
                reason: sprintf('Low disk space on %s (%.2f GB free)', $worstMount, $lowestFreeGb),
            );
        }

        if ($lowestFreeGb <= $warningThresholdGb) {
            return new ProbeResult(
                ok: false,
                responseTimeMs: 0,
                impact: IncidentImpactEnum::Minor,
                reason: sprintf('Disk space warning on %s (%.2f GB free)', $worstMount, $lowestFreeGb),
            );
        }

        return new ProbeResult(
            ok: true,
            responseTimeMs: 0,
            impact: null,
            reason: sprintf('Disk healthy (lowest: %s %.2f GB free)', $worstMount, $lowestFreeGb),
        );
    }

    public static function evaluateThresholdsNative(
        string $worstMount,
        float $lowestFreeGb,
        float $warningThresholdGb,
        float $criticalThresholdGb,
    ): ProbeResult {
        $ffi = self::categorizeFfi();
        if ($ffi === null || ! self::ensureSlots($ffi) || self::$verdictSlot === null) {
            return self::evaluateThresholdsPhp($worstMount, $lowestFreeGb, $warningThresholdGb, $criticalThresholdGb);
        }

        $mountLen = \strlen($worstMount);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, $mountLen + 96), $cap);
        if ($scratch === null) {
            return self::evaluateThresholdsPhp($worstMount, $lowestFreeGb, $warningThresholdGb, $criticalThresholdGb);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_disk_probe_evaluate(
                $worstMount,
                $mountLen,
                $lowestFreeGb,
                $warningThresholdGb,
                $criticalThresholdGb,
                self::$verdictSlot,
                $scratch,
                $cap
            );
            if (\is_int($written) && $written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return self::evaluateThresholdsPhp($worstMount, $lowestFreeGb, $warningThresholdGb, $criticalThresholdGb);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_disk_probe_evaluate(
                    $worstMount,
                    $mountLen,
                    $lowestFreeGb,
                    $warningThresholdGb,
                    $criticalThresholdGb,
                    self::$verdictSlot,
                    $scratch,
                    $cap
                );
            }
            if (! \is_int($written) || $written <= 0 || $written > $cap) {
                return self::evaluateThresholdsPhp($worstMount, $lowestFreeGb, $warningThresholdGb, $criticalThresholdGb);
            }

            /** @var int $verdict */
            $verdict = self::$verdictSlot[0];
            $reason = FFI::string($scratch, $written);

            return match ($verdict) {
                2 => new ProbeResult(
                    ok: false,
                    responseTimeMs: 0,
                    impact: IncidentImpactEnum::Critical,
                    reason: $reason,
                ),
                1 => new ProbeResult(
                    ok: false,
                    responseTimeMs: 0,
                    impact: IncidentImpactEnum::Minor,
                    reason: $reason,
                ),
                0 => new ProbeResult(
                    ok: true,
                    responseTimeMs: 0,
                    impact: null,
                    reason: $reason,
                ),
                default => self::evaluateThresholdsPhp($worstMount, $lowestFreeGb, $warningThresholdGb, $criticalThresholdGb),
            };
        } catch (Throwable) {
            return self::evaluateThresholdsPhp($worstMount, $lowestFreeGb, $warningThresholdGb, $criticalThresholdGb);
        }
    }

    private static function ensureSlots(FFI $ffi): bool
    {
        if (self::$slotOwner !== $ffi || self::$doubleSlot === null || self::$verdictSlot === null) {
            try {
                self::$doubleSlot = $ffi->new('double[1]');
                self::$verdictSlot = $ffi->new('int64_t[1]');
                self::$slotOwner = $ffi;
            } catch (Throwable) {
                return false;
            }
        }

        return true;
    }
}
