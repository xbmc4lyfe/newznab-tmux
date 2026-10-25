<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class NzbUploadException extends RuntimeException
{
    public const string FFI_CDEF = NfoPayloadRejected::FFI_CDEF;

    public const string DEFAULT_MESSAGE = 'Upload failed';

    private const array CATEGORIES = ['client', 'conflict', 'server'];

    /** Rust stays opt-in: the FFI round trip is slower than this PHP helper (< 1.5x). */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    /** @return array{status: int, category: string, retryable: bool, message: string} */
    public function failure(): array
    {
        return self::normalizeUploadFailure($this->getMessage(), $this->status);
    }

    /**
     * Normalize an upload failure for a response: a status outside 400-599 reads as 500; 409 is a conflict, other
     * 4xx client errors, 5xx retryable server errors. The message is trimmed, defaulting when empty.
     *
     * @return array{status: int, category: string, retryable: bool, message: string}
     */
    public static function normalizeUploadFailure(string $message, int $status): array
    {
        return self::usesRustFfi() ? self::normalizeUploadFailureNative($message, $status) : self::normalizeUploadFailurePhp($message, $status);
    }

    /** @return array{status: int, category: string, retryable: bool, message: string} */
    public static function normalizeUploadFailurePhp(string $message, int $status): array
    {
        $status = $status >= 400 && $status <= 599 ? $status : 500;
        $message = trim($message);

        return [
            'status' => $status,
            'category' => $status === 409 ? 'conflict' : ($status < 500 ? 'client' : 'server'),
            'retryable' => $status >= 500,
            'message' => $message === '' ? self::DEFAULT_MESSAGE : $message,
        ];
    }

    /** @return array{status: int, category: string, retryable: bool, message: string} */
    public static function normalizeUploadFailureNative(string $message, int $status): array
    {
        $slots = NfoPayloadRejected::nativeSlots(NfoPayloadRejected::evaluateNative(3, pack('q', $status).$message), 5);
        if ($slots !== null && $slots[0] >= 400 && $slots[0] <= 599 && isset(self::CATEGORIES[$slots[1]]) && \in_array($slots[2], [0, 1], true)
            && $slots[3] >= 0 && $slots[4] >= 0 && $slots[3] <= \strlen($message) - $slots[4]) {
            return [
                'status' => $slots[0],
                'category' => self::CATEGORIES[$slots[1]],
                'retryable' => $slots[2] === 1,
                'message' => $slots[4] === 0 ? self::DEFAULT_MESSAGE : substr($message, $slots[3], $slots[4]),
            ];
        }

        return self::normalizeUploadFailurePhp($message, $status);
    }
}
