<?php

declare(strict_types=1);

namespace App\Services\StatusProbes;

use App\Enums\IncidentImpactEnum;
use App\Services\StatusProbes\Contracts\ServiceProbeInterface;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class DatabaseProbe implements ServiceProbeInterface
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_database_probe_evaluate_connected(int64_t start_ns, int64_t end_ns, int64_t *out_elapsed_ms, char *out_buf, size_t out_cap);
        ssize_t nntmux_database_probe_format_unreachable(const char *error_ptr, size_t error_len, char *out_buf, size_t out_cap);
        C;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private static ?CData $elapsedSlot = null;

    private static ?FFI $slotOwner = null;

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
        return 'database';
    }

    public function probe(): ProbeResult
    {
        try {
            $start = hrtime(true);
            DB::connection()->getPdo();
            DB::select('SELECT 1');
            $end = hrtime(true);

            return self::evaluateConnectedResult($start, $end);
        } catch (Throwable $e) {
            return self::formatUnreachableResult($e->getMessage());
        }
    }

    public static function evaluateConnectedResult(int $startNs, int $endNs): ProbeResult
    {
        return self::$useRustFfi
            ? self::evaluateConnectedResultNative($startNs, $endNs)
            : self::evaluateConnectedResultPhp($startNs, $endNs);
    }

    public static function evaluateConnectedResultPhp(int $startNs, int $endNs): ProbeResult
    {
        $elapsed = (int) (($endNs - $startNs) / 1_000_000);

        return new ProbeResult(
            ok: true,
            responseTimeMs: $elapsed,
            impact: null,
            reason: 'Connected',
        );
    }

    public static function evaluateConnectedResultNative(int $startNs, int $endNs): ProbeResult
    {
        $ffi = self::categorizeFfi();
        if ($ffi === null || ! self::ensureSlots($ffi) || self::$elapsedSlot === null) {
            return self::evaluateConnectedResultPhp($startNs, $endNs);
        }

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 32, $cap);
        if ($scratch === null) {
            return self::evaluateConnectedResultPhp($startNs, $endNs);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_database_probe_evaluate_connected(
                $startNs,
                $endNs,
                self::$elapsedSlot,
                $scratch,
                $cap,
            );
            if (\is_int($written) && $written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return self::evaluateConnectedResultPhp($startNs, $endNs);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_database_probe_evaluate_connected(
                    $startNs,
                    $endNs,
                    self::$elapsedSlot,
                    $scratch,
                    $cap,
                );
            }
            if (! \is_int($written) || $written <= 0 || $written > $cap) {
                return self::evaluateConnectedResultPhp($startNs, $endNs);
            }

            /** @var int $elapsed */
            $elapsed = self::$elapsedSlot[0];

            return new ProbeResult(
                ok: true,
                responseTimeMs: $elapsed,
                impact: null,
                reason: FFI::string($scratch, $written),
            );
        } catch (Throwable) {
            return self::evaluateConnectedResultPhp($startNs, $endNs);
        }
    }

    public static function formatUnreachableResult(string $errorMessage): ProbeResult
    {
        return self::$useRustFfi
            ? self::formatUnreachableResultNative($errorMessage)
            : self::formatUnreachableResultPhp($errorMessage);
    }

    public static function formatUnreachableResultPhp(string $errorMessage): ProbeResult
    {
        return new ProbeResult(
            ok: false,
            responseTimeMs: 0,
            impact: IncidentImpactEnum::Critical,
            reason: 'Database unreachable: '.Str::limit($errorMessage, 120),
        );
    }

    public static function formatUnreachableResultNative(string $errorMessage): ProbeResult
    {
        $ffi = self::categorizeFfi();
        if ($ffi === null) {
            return self::formatUnreachableResultPhp($errorMessage);
        }

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 256, $cap);
        if ($scratch === null) {
            return self::formatUnreachableResultPhp($errorMessage);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_database_probe_format_unreachable(
                $errorMessage,
                \strlen($errorMessage),
                $scratch,
                $cap,
            );
            if (\is_int($written) && $written < -2) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return self::formatUnreachableResultPhp($errorMessage);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_database_probe_format_unreachable(
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

        return self::formatUnreachableResultPhp($errorMessage);
    }

    private static function ensureSlots(FFI $ffi): bool
    {
        if (self::$slotOwner !== $ffi || self::$elapsedSlot === null) {
            try {
                self::$elapsedSlot = $ffi->new('int64_t[1]');
                self::$slotOwner = $ffi;
            } catch (Throwable) {
                return false;
            }
        }

        return true;
    }
}
