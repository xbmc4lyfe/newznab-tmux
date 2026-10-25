<?php

declare(strict_types=1);

namespace App\Services\StatusProbes;

use App\Enums\IncidentImpactEnum;
use App\Services\StatusProbes\Contracts\ServiceProbeInterface;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use InvalidArgumentException;
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

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W50 parity test). */
    private const W50_RUST_FFI_DEFAULT = true;

    private const string W50_FFI_CDEF = 'ssize_t nntmux_w501_disk_probe_evaluate_batch(const char *mounts, size_t mounts_len, const char *free_bytes, size_t free_bytes_len, size_t count, double warning_gb, double critical_gb, int64_t *out, size_t out_cap);';

    public static function usesW50RustFfi(): bool
    {
        $enabled = self::w50RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W50_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 50 batch routed to Rust or PHP, then restores the routing. */
    public static function withW50RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w50RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w50RustFfiState('set', $previous);
        }
    }

    /**
     * Batch threshold evaluation of mount free-space samples. One word per mount
     * (`verdict | min(0xFFFF, strlen(reason)) << 2 | centiGb << 18`, verdict 0 healthy, 1 minor,
     * 2 critical, as `evaluateThresholds()` reports for that mount alone with `centiGb =
     * round(freeGb * 100)`) plus a trailing word holding the index of the first mount with the
     * lowest free space (the mount `probe()` reports; -1 for an empty batch).
     *
     * @param  list<string>  $mounts
     * @param  list<int>  $freeBytes
     * @return list<int>
     */
    public static function evaluateMountBatch(array $mounts, array $freeBytes, float $warningThresholdGb, float $criticalThresholdGb): array
    {
        return self::usesW50RustFfi()
            ? self::evaluateMountBatchNative($mounts, $freeBytes, $warningThresholdGb, $criticalThresholdGb)
            : self::evaluateMountBatchPhp($mounts, $freeBytes, $warningThresholdGb, $criticalThresholdGb);
    }

    /**
     * @param  list<string>  $mounts
     * @param  list<int>  $freeBytes
     * @return list<int>
     */
    public static function evaluateMountBatchPhp(array $mounts, array $freeBytes, float $warningThresholdGb, float $criticalThresholdGb): array
    {
        self::w50Count($mounts, $freeBytes);
        $words = [];
        $worst = -1;
        $lowest = null;
        foreach ($mounts as $i => $mount) {
            $freeGb = self::bytesToFreeGbPhp($freeBytes[$i]);
            $result = self::evaluateThresholdsPhp($mount, $freeGb, $warningThresholdGb, $criticalThresholdGb);
            $verdict = match ($result->impact) {
                IncidentImpactEnum::Critical => 2,
                IncidentImpactEnum::Minor => 1,
                default => 0,
            };
            $words[] = $verdict | min(0xFFFF, \strlen($result->reason)) << 2 | (int) round($freeGb * 100) << 18;
            if ($lowest === null || $freeGb < $lowest) {
                $lowest = $freeGb;
                $worst = $i;
            }
        }
        $words[] = $worst;

        return $words;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a mount holds a NUL byte or a
     * byte count is negative.
     *
     * @param  list<string>  $mounts
     * @param  list<int>  $freeBytes
     * @return list<int>
     */
    public static function evaluateMountBatchNative(array $mounts, array $freeBytes, float $warningThresholdGb, float $criticalThresholdGb): array
    {
        $count = self::w50Count($mounts, $freeBytes);
        $ffi = $count === 0 ? null : self::w50Ffi();
        if ($ffi === null) {
            return self::evaluateMountBatchPhp($mounts, $freeBytes, $warningThresholdGb, $criticalThresholdGb);
        }
        $packed = implode("\0", $mounts);
        $bytes = pack('P*', ...$freeBytes);
        try {
            $words = self::w50Words($count + 1, static fn (CData $out, int $cap): mixed => $ffi->nntmux_w501_disk_probe_evaluate_batch( // @phpstan-ignore method.notFound
                $packed, \strlen($packed), $bytes, \strlen($bytes), $count, $warningThresholdGb, $criticalThresholdGb, $out, $cap));
        } catch (Throwable) {
            $words = null;
        }

        return $words ?? self::evaluateMountBatchPhp($mounts, $freeBytes, $warningThresholdGb, $criticalThresholdGb);
    }

    /**
     * @param  array<mixed>  ...$columns
     */
    private static function w50Count(array ...$columns): int
    {
        $count = \count($columns[0]);
        foreach ($columns as $column) {
            if (\count($column) !== $count) {
                throw new InvalidArgumentException('Status probe batch columns must have the same length.');
            }
        }

        return $count;
    }

    private static function w50Ffi(): ?FFI
    {
        $ffi = self::w50RustFfiState('ffi');

        return $ffi instanceof FFI ? $ffi : null;
    }

    /**
     * Wave 50 routing override (`set`), lazily resolved binding (`ffi`) and reusable `int64_t`
     * output buffer (`out`, sized for `$val` words), kept in function-local statics.
     */
    private static function w50RustFfiState(?string $op = null, mixed $val = null): mixed
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
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('status_probes_w50', self::W50_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                if (! $ffi instanceof FFI || ! \is_int($val)) {
                    return null;
                }
                if (! $out instanceof CData || $outCap < $val) {
                    $outCap = max(512, $val * 2);
                    $out = $ffi->new("int64_t[{$outCap}]");
                }

                return [$out, $outCap];
            default:
                return $enabled;
        }
    }

    /**
     * Copies `$words` output words out of the reusable buffer after `$call` filled it.
     *
     * @param  callable(CData, int): mixed  $call  Receives the buffer and its capacity in words.
     * @return list<int>|null
     */
    private static function w50Words(int $words, callable $call): ?array
    {
        $buffer = self::w50RustFfiState('out', $words);
        if (! \is_array($buffer) || ! $buffer[0] instanceof CData || $call($buffer[0], $buffer[1]) !== $words) {
            return null;
        }
        $decoded = unpack('P*', FFI::string($buffer[0], $words * 8));

        /** @var list<int>|null */
        return \is_array($decoded) ? array_values($decoded) : null;
    }
}
