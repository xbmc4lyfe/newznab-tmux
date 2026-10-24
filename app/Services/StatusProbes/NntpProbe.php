<?php

declare(strict_types=1);

namespace App\Services\StatusProbes;

use App\Enums\IncidentImpactEnum;
use App\Services\NNTP\NNTPService;
use App\Services\StatusProbes\Contracts\ServiceProbeInterface;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Str;
use Throwable;

class NntpProbe implements ServiceProbeInterface
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_nntp_probe_classify_connection(uint8_t is_success, uint8_t is_error_obj, const char *error_msg_ptr, size_t error_msg_len, int64_t start_ns, int64_t end_ns, int64_t *out_elapsed_ms, int32_t *out_ok, char *out_buf, size_t out_cap);
        ssize_t nntmux_nntp_probe_evaluate(uint8_t primary_ok, int64_t primary_elapsed_ms, const char *primary_reason_ptr, size_t primary_reason_len, uint8_t check_alternate, uint8_t alternate_ok, const char *alternate_reason_ptr, size_t alternate_reason_len, int64_t *out_response_ms, int32_t *out_verdict, char *out_buf, size_t out_cap);
        ssize_t nntmux_status_probe_format_failure(uint8_t probe_kind, const char *error_ptr, size_t error_len, char *out_buf, size_t out_cap);
        C;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private static ?CData $int64Slot = null;

    private static ?CData $int32Slot = null;

    private static ?FFI $slotOwner = null;

    public function __construct(
        private readonly NNTPService $nntp,
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
        return 'nntp';
    }

    public function probe(): ProbeResult
    {
        $checkAlternate = (bool) config('status-probes.nntp.check_alternate', false);

        try {
            $primary = $this->probeConnection(false);
            if (! $primary['ok']) {
                return self::evaluateConnections($primary, $checkAlternate, null);
            }

            $alternate = $checkAlternate ? $this->probeConnection(true) : null;

            return self::evaluateConnections($primary, $checkAlternate, $alternate);
        } catch (Throwable $e) {
            return self::formatFailureResult($e->getMessage());
        } finally {
            $this->nntp->doQuit();
        }
    }

    /**
     * @return array{ok: bool, reason: string, responseTimeMs: int}
     */
    public static function classifyConnectionAttempt(
        bool $isSuccess,
        bool $isErrorObject,
        string $errorMessage,
        int $startNs,
        int $endNs,
    ): array {
        return self::$useRustFfi
            ? self::classifyConnectionAttemptNative($isSuccess, $isErrorObject, $errorMessage, $startNs, $endNs)
            : self::classifyConnectionAttemptPhp($isSuccess, $isErrorObject, $errorMessage, $startNs, $endNs);
    }

    /**
     * @return array{ok: bool, reason: string, responseTimeMs: int}
     */
    public static function classifyConnectionAttemptPhp(
        bool $isSuccess,
        bool $isErrorObject,
        string $errorMessage,
        int $startNs,
        int $endNs,
    ): array {
        $elapsed = (int) (($endNs - $startNs) / 1_000_000);

        if ($isSuccess) {
            return ['ok' => true, 'reason' => 'Connected', 'responseTimeMs' => $elapsed];
        }

        if ($isErrorObject) {
            return ['ok' => false, 'reason' => $errorMessage, 'responseTimeMs' => $elapsed];
        }

        return ['ok' => false, 'reason' => 'Unknown connection result', 'responseTimeMs' => $elapsed];
    }

    /**
     * @return array{ok: bool, reason: string, responseTimeMs: int}
     */
    public static function classifyConnectionAttemptNative(
        bool $isSuccess,
        bool $isErrorObject,
        string $errorMessage,
        int $startNs,
        int $endNs,
    ): array {
        $ffi = self::mediaFfi();
        if (
            $ffi === null
            || ! self::ensureSlots($ffi)
            || self::$int64Slot === null
            || self::$int32Slot === null
        ) {
            return self::classifyConnectionAttemptPhp($isSuccess, $isErrorObject, $errorMessage, $startNs, $endNs);
        }

        $msgLen = \strlen($errorMessage);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(128, $msgLen + 32), $cap);
        if ($scratch === null) {
            return self::classifyConnectionAttemptPhp($isSuccess, $isErrorObject, $errorMessage, $startNs, $endNs);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_nntp_probe_classify_connection(
                $isSuccess ? 1 : 0,
                $isErrorObject ? 1 : 0,
                $errorMessage,
                $msgLen,
                $startNs,
                $endNs,
                self::$int64Slot,
                self::$int32Slot,
                $scratch,
                $cap,
            );
            if (\is_int($written) && $written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return self::classifyConnectionAttemptPhp($isSuccess, $isErrorObject, $errorMessage, $startNs, $endNs);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_nntp_probe_classify_connection(
                    $isSuccess ? 1 : 0,
                    $isErrorObject ? 1 : 0,
                    $errorMessage,
                    $msgLen,
                    $startNs,
                    $endNs,
                    self::$int64Slot,
                    self::$int32Slot,
                    $scratch,
                    $cap,
                );
            }
            if (! \is_int($written) || $written < 0 || $written > $cap) {
                return self::classifyConnectionAttemptPhp($isSuccess, $isErrorObject, $errorMessage, $startNs, $endNs);
            }

            /** @var int $elapsed */
            $elapsed = self::$int64Slot[0];
            /** @var int $okFlag */
            $okFlag = self::$int32Slot[0];
            $reason = $written === 0 ? '' : FFI::string($scratch, $written);

            return [
                'ok' => $okFlag === 1,
                'reason' => $reason,
                'responseTimeMs' => $elapsed,
            ];
        } catch (Throwable) {
            return self::classifyConnectionAttemptPhp($isSuccess, $isErrorObject, $errorMessage, $startNs, $endNs);
        }
    }

    /**
     * @param  array{ok: bool, reason: string, responseTimeMs: int}  $primary
     * @param  array{ok: bool, reason: string, responseTimeMs: int}|null  $alternate
     */
    public static function evaluateConnections(
        array $primary,
        bool $checkAlternate,
        ?array $alternate = null,
    ): ProbeResult {
        return self::$useRustFfi
            ? self::evaluateConnectionsNative($primary, $checkAlternate, $alternate)
            : self::evaluateConnectionsPhp($primary, $checkAlternate, $alternate);
    }

    /**
     * @param  array{ok: bool, reason: string, responseTimeMs: int}  $primary
     * @param  array{ok: bool, reason: string, responseTimeMs: int}|null  $alternate
     */
    public static function evaluateConnectionsPhp(
        array $primary,
        bool $checkAlternate,
        ?array $alternate = null,
    ): ProbeResult {
        if (! $primary['ok']) {
            return new ProbeResult(
                ok: false,
                responseTimeMs: 0,
                impact: IncidentImpactEnum::Critical,
                reason: 'Primary NNTP failed: '.$primary['reason'],
            );
        }

        if ($checkAlternate && $alternate !== null && ! $alternate['ok']) {
            return new ProbeResult(
                ok: false,
                responseTimeMs: 0,
                impact: IncidentImpactEnum::Major,
                reason: 'Alternate NNTP failed: '.$alternate['reason'],
            );
        }

        return new ProbeResult(
            ok: true,
            responseTimeMs: (int) $primary['responseTimeMs'],
            impact: null,
            reason: $checkAlternate ? 'Primary and alternate NNTP connected' : 'Primary NNTP connected',
        );
    }

    /**
     * @param  array{ok: bool, reason: string, responseTimeMs: int}  $primary
     * @param  array{ok: bool, reason: string, responseTimeMs: int}|null  $alternate
     */
    public static function evaluateConnectionsNative(
        array $primary,
        bool $checkAlternate,
        ?array $alternate = null,
    ): ProbeResult {
        $ffi = self::mediaFfi();
        if (
            $ffi === null
            || ! self::ensureSlots($ffi)
            || self::$int64Slot === null
            || self::$int32Slot === null
        ) {
            return self::evaluateConnectionsPhp($primary, $checkAlternate, $alternate);
        }

        $primaryReason = (string) $primary['reason'];
        $alternateOk = $alternate === null ? true : (bool) $alternate['ok'];
        $alternateReason = $alternate === null ? '' : (string) $alternate['reason'];
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch(
            $ffi,
            max(128, \strlen($primaryReason) + \strlen($alternateReason) + 64),
            $cap
        );
        if ($scratch === null) {
            return self::evaluateConnectionsPhp($primary, $checkAlternate, $alternate);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_nntp_probe_evaluate(
                $primary['ok'] ? 1 : 0,
                (int) $primary['responseTimeMs'],
                $primaryReason,
                \strlen($primaryReason),
                $checkAlternate ? 1 : 0,
                $alternateOk ? 1 : 0,
                $alternateReason,
                \strlen($alternateReason),
                self::$int64Slot,
                self::$int32Slot,
                $scratch,
                $cap,
            );
            if (\is_int($written) && $written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return self::evaluateConnectionsPhp($primary, $checkAlternate, $alternate);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_nntp_probe_evaluate(
                    $primary['ok'] ? 1 : 0,
                    (int) $primary['responseTimeMs'],
                    $primaryReason,
                    \strlen($primaryReason),
                    $checkAlternate ? 1 : 0,
                    $alternateOk ? 1 : 0,
                    $alternateReason,
                    \strlen($alternateReason),
                    self::$int64Slot,
                    self::$int32Slot,
                    $scratch,
                    $cap,
                );
            }
            if (! \is_int($written) || $written <= 0 || $written > $cap) {
                return self::evaluateConnectionsPhp($primary, $checkAlternate, $alternate);
            }

            /** @var int $responseTimeMs */
            $responseTimeMs = self::$int64Slot[0];
            /** @var int $verdict */
            $verdict = self::$int32Slot[0];
            $reason = FFI::string($scratch, $written);

            return match ($verdict) {
                0 => new ProbeResult(
                    ok: true,
                    responseTimeMs: $responseTimeMs,
                    impact: null,
                    reason: $reason,
                ),
                1 => new ProbeResult(
                    ok: false,
                    responseTimeMs: $responseTimeMs,
                    impact: IncidentImpactEnum::Major,
                    reason: $reason,
                ),
                2 => new ProbeResult(
                    ok: false,
                    responseTimeMs: $responseTimeMs,
                    impact: IncidentImpactEnum::Critical,
                    reason: $reason,
                ),
                default => self::evaluateConnectionsPhp($primary, $checkAlternate, $alternate),
            };
        } catch (Throwable) {
            return self::evaluateConnectionsPhp($primary, $checkAlternate, $alternate);
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
            reason: 'NNTP probe failed: '.Str::limit($errorMessage, 120),
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
                1,
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
                    1,
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

    /**
     * @return array{ok: bool, reason: string, responseTimeMs: int}
     */
    private function probeConnection(bool $alternate): array
    {
        $start = hrtime(true);
        $result = $this->nntp->doConnect(compression: false, alternate: $alternate);
        $end = hrtime(true);

        $isSuccess = $result === true;
        $isErrorObject = ! $isSuccess && NNTPService::isError($result);
        $errorMessage = $isErrorObject ? (string) $result->getMessage() : '';

        return self::classifyConnectionAttempt($isSuccess, $isErrorObject, $errorMessage, $start, $end);
    }

    private static function ensureSlots(FFI $ffi): bool
    {
        if (self::$slotOwner !== $ffi || self::$int64Slot === null || self::$int32Slot === null) {
            try {
                self::$int64Slot = $ffi->new('int64_t[1]');
                self::$int32Slot = $ffi->new('int32_t[1]');
                self::$slotOwner = $ffi;
            } catch (Throwable) {
                return false;
            }
        }

        return true;
    }
}
