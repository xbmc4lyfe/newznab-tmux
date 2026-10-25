<?php

declare(strict_types=1);

namespace App\Exceptions;

final class NzbPasswordMetadataRejected extends \RuntimeException
{
    public const string FFI_CDEF = NfoPayloadRejected::FFI_CDEF;

    /** The per-value NZB password metadata limit. */
    public const int VALUE_LIMIT = 4096;

    private const array KINDS = ['structure', 'limit', 'unknown'];

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

    /** @return array{kind: string, value_length: int, over_limit: bool, retryable: bool} */
    public function classification(int $valueLength = 0): array
    {
        return self::classifyPasswordRejection($this->getMessage(), $valueLength);
    }

    /**
     * Classify a password metadata rejection: a "structure" reason (ASCII case-insensitive) first, then an
     * over-limit value or a "limit" reason, else unknown. Negative lengths read as zero; never retryable.
     *
     * @return array{kind: string, value_length: int, over_limit: bool, retryable: bool}
     */
    public static function classifyPasswordRejection(string $reason, int $valueLength = 0): array
    {
        return self::usesRustFfi() ? self::classifyPasswordRejectionNative($reason, $valueLength) : self::classifyPasswordRejectionPhp($reason, $valueLength);
    }

    /** @return array{kind: string, value_length: int, over_limit: bool, retryable: bool} */
    public static function classifyPasswordRejectionPhp(string $reason, int $valueLength = 0): array
    {
        $length = max(0, $valueLength);
        $over = $length > self::VALUE_LIMIT;
        $lower = strtolower($reason);
        $kind = match (true) {
            str_contains($lower, 'structure') => 'structure',
            $over || str_contains($lower, 'limit') => 'limit',
            default => 'unknown',
        };

        return ['kind' => $kind, 'value_length' => $length, 'over_limit' => $over, 'retryable' => false];
    }

    /** @return array{kind: string, value_length: int, over_limit: bool, retryable: bool} */
    public static function classifyPasswordRejectionNative(string $reason, int $valueLength = 0): array
    {
        $slots = NfoPayloadRejected::nativeSlots(NfoPayloadRejected::evaluateNative(2, pack('q', $valueLength).$reason), 3);
        if ($slots !== null && isset(self::KINDS[$slots[0]]) && $slots[1] >= 0 && \in_array($slots[2], [0, 1], true)) {
            return ['kind' => self::KINDS[$slots[0]], 'value_length' => $slots[1], 'over_limit' => $slots[2] === 1, 'retryable' => false];
        }

        return self::classifyPasswordRejectionPhp($reason, $valueLength);
    }
}
