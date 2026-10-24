<?php

declare(strict_types=1);

namespace App\Services\StatusProbes;

use App\Enums\IncidentImpactEnum;
use App\Services\StatusProbes\Contracts\ServiceProbeInterface;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Str;
use Throwable;

class QueueProbe implements ServiceProbeInterface
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_queue_probe_evaluate(const char *connection_ptr, size_t connection_len, int64_t start_ns, int64_t end_ns, int64_t *out_elapsed_ms, int32_t *out_is_sync, char *out_buf, size_t out_cap);
        ssize_t nntmux_status_probe_format_failure(uint8_t probe_kind, const char *error_ptr, size_t error_len, char *out_buf, size_t out_cap);
        C;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private static ?CData $elapsedSlot = null;

    private static ?CData $isSyncSlot = null;

    private static ?FFI $slotOwner = null;

    public function __construct(
        private readonly QueueFactory $queue,
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
        return 'queue';
    }

    public function probe(): ProbeResult
    {
        try {
            $defaultConnection = (string) config('queue.default', 'sync');
            $start = hrtime(true);
            $queueConnection = $this->queue->connection($defaultConnection);
            $size = method_exists($queueConnection, 'size') ? (int) $queueConnection->size() : null;
            $end = hrtime(true);

            return self::evaluateQueueResult($defaultConnection, $size, $start, $end);
        } catch (Throwable $e) {
            return self::formatFailureResult($e->getMessage());
        }
    }

    public static function evaluateQueueResult(
        string $defaultConnection,
        ?int $size,
        int $startNs,
        int $endNs,
    ): ProbeResult {
        return self::$useRustFfi
            ? self::evaluateQueueResultNative($defaultConnection, $size, $startNs, $endNs)
            : self::evaluateQueueResultPhp($defaultConnection, $size, $startNs, $endNs);
    }

    public static function evaluateQueueResultPhp(
        string $defaultConnection,
        ?int $size,
        int $startNs,
        int $endNs,
    ): ProbeResult {
        $elapsed = (int) (($endNs - $startNs) / 1_000_000);

        if ($defaultConnection === 'sync') {
            return new ProbeResult(
                ok: true,
                responseTimeMs: $elapsed,
                impact: null,
                reason: 'Queue uses sync driver',
            );
        }

        return new ProbeResult(
            ok: true,
            responseTimeMs: $elapsed,
            impact: null,
            reason: sprintf('Queue "%s" reachable', $defaultConnection),
            metadata: ['queue_size' => $size],
        );
    }

    public static function evaluateQueueResultNative(
        string $defaultConnection,
        ?int $size,
        int $startNs,
        int $endNs,
    ): ProbeResult {
        $ffi = self::mediaFfi();
        if (
            $ffi === null
            || ! self::ensureSlots($ffi)
            || self::$elapsedSlot === null
            || self::$isSyncSlot === null
        ) {
            return self::evaluateQueueResultPhp($defaultConnection, $size, $startNs, $endNs);
        }

        $connLen = \strlen($defaultConnection);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(128, $connLen + 32), $cap);
        if ($scratch === null) {
            return self::evaluateQueueResultPhp($defaultConnection, $size, $startNs, $endNs);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_queue_probe_evaluate(
                $defaultConnection,
                $connLen,
                $startNs,
                $endNs,
                self::$elapsedSlot,
                self::$isSyncSlot,
                $scratch,
                $cap,
            );
            if (\is_int($written) && $written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return self::evaluateQueueResultPhp($defaultConnection, $size, $startNs, $endNs);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_queue_probe_evaluate(
                    $defaultConnection,
                    $connLen,
                    $startNs,
                    $endNs,
                    self::$elapsedSlot,
                    self::$isSyncSlot,
                    $scratch,
                    $cap,
                );
            }
            if (! \is_int($written) || $written <= 0 || $written > $cap) {
                return self::evaluateQueueResultPhp($defaultConnection, $size, $startNs, $endNs);
            }

            /** @var int $elapsed */
            $elapsed = self::$elapsedSlot[0];
            /** @var int $isSync */
            $isSync = self::$isSyncSlot[0];
            $reason = FFI::string($scratch, $written);

            return new ProbeResult(
                ok: true,
                responseTimeMs: $elapsed,
                impact: null,
                reason: $reason,
                metadata: $isSync === 1 ? [] : ['queue_size' => $size],
            );
        } catch (Throwable) {
            return self::evaluateQueueResultPhp($defaultConnection, $size, $startNs, $endNs);
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
            reason: 'Queue probe failed: '.Str::limit($errorMessage, 120),
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
                2,
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
                    2,
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
        if (self::$slotOwner !== $ffi || self::$elapsedSlot === null || self::$isSyncSlot === null) {
            try {
                self::$elapsedSlot = $ffi->new('int64_t[1]');
                self::$isSyncSlot = $ffi->new('int32_t[1]');
                self::$slotOwner = $ffi;
            } catch (Throwable) {
                return false;
            }
        }

        return true;
    }
}
