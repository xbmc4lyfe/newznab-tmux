<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Support\RustPipelineLibrary;
use FFI;
use RuntimeException;
use Throwable;

/** A captured, unchanged companion is permanently invalid, rather than retryable storage failure. */
final class NfoPayloadRejected extends RuntimeException
{
    /** Shared by the Wave 35.2 NZB/NFO/PAR2 exception and recovery helpers. */
    public const string FFI_CDEF = 'int64_t nntmux_w35_nzb_nfo_par2_exceptions_recovery(uint32_t operation,const char *input,size_t length,char *output,size_t capacity);';

    /** The NFO body limit enforced by upload staging and companion capture. */
    public const int NFO_LIMIT = 65535;

    private const int NATIVE_MAX_BYTES = 16777216;

    private const array KINDS = ['empty', 'oversize', 'encoding', 'invalid'];

    /** Rust stays opt-in: the FFI round trip is slower than this PHP helper (< 1.5x). */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    /** @return array{kind: string, failure_kind: string, payload_bytes: int, within_limit: bool, terminal: bool} */
    public function classification(int $payloadBytes = 0): array
    {
        return self::classifyRejectionReason($this->getMessage(), $payloadBytes);
    }

    /**
     * Classify why a companion was rejected: an oversized byte count wins, an "empty" reason counts only for an
     * empty (or unknown, 0) payload, then "exceeds"/"size limit", then "encoding" (ASCII case-insensitive), else
     * invalid. Negative byte counts read as zero. A rejection is always terminal.
     *
     * @return array{kind: string, failure_kind: string, payload_bytes: int, within_limit: bool, terminal: bool}
     */
    public static function classifyRejectionReason(string $reason, int $payloadBytes = 0): array
    {
        return self::usesRustFfi() ? self::classifyRejectionReasonNative($reason, $payloadBytes) : self::classifyRejectionReasonPhp($reason, $payloadBytes);
    }

    /** @return array{kind: string, failure_kind: string, payload_bytes: int, within_limit: bool, terminal: bool} */
    public static function classifyRejectionReasonPhp(string $reason, int $payloadBytes = 0): array
    {
        $payload = max(0, $payloadBytes);
        $lower = strtolower($reason);
        $kind = match (true) {
            $payload > self::NFO_LIMIT => 'oversize',
            $payload === 0 && str_contains($lower, 'empty') => 'empty',
            str_contains($lower, 'exceeds') || str_contains($lower, 'size limit') => 'oversize',
            str_contains($lower, 'encoding') => 'encoding',
            default => 'invalid',
        };

        return ['kind' => $kind, 'failure_kind' => 'invalid_payload', 'payload_bytes' => $payload,
            'within_limit' => $payload >= 1 && $payload <= self::NFO_LIMIT, 'terminal' => true];
    }

    /** @return array{kind: string, failure_kind: string, payload_bytes: int, within_limit: bool, terminal: bool} */
    public static function classifyRejectionReasonNative(string $reason, int $payloadBytes = 0): array
    {
        $slots = self::nativeSlots(self::evaluateNative(0, pack('q', $payloadBytes).$reason), 3);
        if ($slots !== null && isset(self::KINDS[$slots[0]]) && $slots[1] >= 0 && \in_array($slots[2], [0, 1], true)) {
            return ['kind' => self::KINDS[$slots[0]], 'failure_kind' => 'invalid_payload', 'payload_bytes' => $slots[1],
                'within_limit' => $slots[2] === 1, 'terminal' => true];
        }

        return self::classifyRejectionReasonPhp($reason, $payloadBytes);
    }

    /**
     * Run one Wave 35.2 Rust operation over packed native-endian slots and string bytes; null when the library is
     * missing, the input is out of contract or the call fails, so callers fall back to PHP.
     */
    public static function evaluateNative(int $operation, string $input): ?string
    {
        try {
            if ($operation < 0 || $input === '' || \strlen($input) > self::NATIVE_MAX_BYTES) {
                return null;
            }
            $ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
            if (! $ffi instanceof FFI) {
                return null;
            }
            $capacity = 0;
            $output = RustPipelineLibrary::obtainScratch($ffi, 256, $capacity);
            if ($output === null) {
                return null;
            }
            $capacity = min($capacity, self::NATIVE_MAX_BYTES);
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w35_nzb_nfo_par2_exceptions_recovery($operation, $input, \strlen($input), $output, $capacity);
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return $written === 0 ? '' : FFI::string($output, $written);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<int>|null */
    public static function nativeSlots(?string $result, int $count): ?array
    {
        if ($result === null || \strlen($result) !== $count * 8) {
            return null;
        }
        $slots = array_values(unpack('q'.$count, $result) ?: []);

        return \count($slots) === $count ? $slots : null;
    }
}
