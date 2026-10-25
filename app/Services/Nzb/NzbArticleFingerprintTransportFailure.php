<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Services\NNTP\NntpArticleMissingException;
use RuntimeException;

final class NzbArticleFingerprintTransportFailure extends RuntimeException
{
    public const string FFI_CDEF = NntpArticleMissingException::FFI_CDEF;

    private const array KINDS = ['worker_stopped', 'incomplete', 'invalid', 'request', 'timeout', 'io'];

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

    /**
     * Classify a fingerprint transport failure by the first of "stopped", "incomplete", "invalid", "request failed"
     * or "timed out"/"timeout" its reason contains (ASCII case-insensitive), else an I/O failure. Only an invalid
     * response is not retryable. The message-id and reason are trimmed (the message-id also loses one pair of
     * enclosing angle brackets), null when empty; negative byte counts read as zero.
     *
     * @return array{message_id: ?string, reason: ?string, kind: string, bytes_read: int, partial: bool, retryable: bool}
     */
    public static function classifyTransportFailure(?string $messageId, ?string $reason, int $bytesRead): array
    {
        return self::usesRustFfi() ? self::classifyTransportFailureNative($messageId, $reason, $bytesRead)
            : self::classifyTransportFailurePhp($messageId, $reason, $bytesRead);
    }

    /** @return array{message_id: ?string, reason: ?string, kind: string, bytes_read: int, partial: bool, retryable: bool} */
    public static function classifyTransportFailurePhp(?string $messageId, ?string $reason, int $bytesRead): array
    {
        $lower = strtolower($reason ?? '');
        $kind = match (true) {
            str_contains($lower, 'stopped') => 'worker_stopped',
            str_contains($lower, 'incomplete') => 'incomplete',
            str_contains($lower, 'invalid') => 'invalid',
            str_contains($lower, 'request failed') => 'request',
            str_contains($lower, 'timed out') || str_contains($lower, 'timeout') => 'timeout',
            default => 'io',
        };
        $trimmed = trim($reason ?? '');
        $bytes = max(0, $bytesRead);

        return ['message_id' => NntpArticleMissingException::normalizeMessageId($messageId), 'reason' => $trimmed === '' ? null : $trimmed, 'kind' => $kind,
            'bytes_read' => $bytes, 'partial' => $bytes > 0, 'retryable' => $kind !== 'invalid'];
    }

    /** @return array{message_id: ?string, reason: ?string, kind: string, bytes_read: int, partial: bool, retryable: bool} */
    public static function classifyTransportFailureNative(?string $messageId, ?string $reason, int $bytesRead): array
    {
        $slots = NntpArticleMissingException::nativeSlots(NntpArticleMissingException::evaluateNative(3,
            pack('q5', (int) ($messageId !== null), \strlen($messageId ?? ''), (int) ($reason !== null), \strlen($reason ?? ''), $bytesRead)
            .($messageId ?? '').($reason ?? '')), 10);
        if ($slots !== null) {
            $id = NntpArticleMissingException::nativeRange($messageId, $slots[0], $slots[1], $slots[2]);
            $trimmed = NntpArticleMissingException::nativeRange($reason, $slots[3], $slots[4], $slots[5]);
            if ($id !== false && $trimmed !== false && isset(self::KINDS[$slots[6]]) && $slots[7] >= 0 && \in_array($slots[8], [0, 1], true)
                && \in_array($slots[9], [0, 1], true)) {
                return ['message_id' => $id, 'reason' => $trimmed, 'kind' => self::KINDS[$slots[6]], 'bytes_read' => $slots[7], 'partial' => $slots[8] === 1,
                    'retryable' => $slots[9] === 1];
            }
        }

        return self::classifyTransportFailurePhp($messageId, $reason, $bytesRead);
    }
}
