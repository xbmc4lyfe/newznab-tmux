<?php

declare(strict_types=1);

namespace App\Services\NNTP;

use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

/**
 * The provider answered that the article does not exist (430 or 423), as opposed to a protocol,
 * authentication or transport failure. It extends RuntimeException so existing callers keep working.
 */
final class NntpArticleMissingException extends \RuntimeException
{
    /** Shared by the Wave 36.2 NNTP/fingerprint exception and yEnc codec policy helpers. */
    public const string FFI_CDEF = 'int64_t nntmux_w36_nntp_fingerprint_exceptions_yenc(uint32_t operation,const char *input,size_t length,char *output,size_t capacity);';

    /** Attempts after which a confirmed-missing article stops being retried. */
    public const int MAX_ATTEMPTS = 3;

    private const int NATIVE_MAX_BYTES = 16777216;

    private const array REASONS = ['no_such_article', 'no_such_article_number', 'unknown'];

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
     * Classify a missing-article answer: 430 is no such article, 423 no such article number, anything else unknown
     * (not a confirmed miss). The message-id is trimmed and loses one pair of enclosing angle brackets; negative
     * attempt counts read as zero, and an article stays retryable until MAX_ATTEMPTS attempts.
     *
     * @return array{message_id: ?string, status: int, reason: string, confirmed_missing: bool, attempts: int, retryable: bool}
     */
    public static function classifyMissingArticle(?string $messageId, int $statusCode, int $attemptCount): array
    {
        return self::usesRustFfi() ? self::classifyMissingArticleNative($messageId, $statusCode, $attemptCount)
            : self::classifyMissingArticlePhp($messageId, $statusCode, $attemptCount);
    }

    /** @return array{message_id: ?string, status: int, reason: string, confirmed_missing: bool, attempts: int, retryable: bool} */
    public static function classifyMissingArticlePhp(?string $messageId, int $statusCode, int $attemptCount): array
    {
        $attempts = max(0, $attemptCount);
        $reason = match ($statusCode) {
            430 => 'no_such_article',
            423 => 'no_such_article_number',
            default => 'unknown',
        };

        return ['message_id' => self::normalizeMessageId($messageId), 'status' => $statusCode, 'reason' => $reason,
            'confirmed_missing' => $reason !== 'unknown', 'attempts' => $attempts, 'retryable' => $attempts < self::MAX_ATTEMPTS];
    }

    /** @return array{message_id: ?string, status: int, reason: string, confirmed_missing: bool, attempts: int, retryable: bool} */
    public static function classifyMissingArticleNative(?string $messageId, int $statusCode, int $attemptCount): array
    {
        $slots = self::nativeSlots(self::evaluateNative(0, pack('q4', (int) ($messageId !== null), \strlen($messageId ?? ''), $statusCode, $attemptCount)
            .($messageId ?? '')), 7);
        if ($slots !== null) {
            $id = self::nativeRange($messageId, $slots[0], $slots[1], $slots[2]);
            if ($id !== false && isset(self::REASONS[$slots[3]]) && \in_array($slots[4], [0, 1], true) && $slots[5] >= 0 && \in_array($slots[6], [0, 1], true)) {
                return ['message_id' => $id, 'status' => $statusCode, 'reason' => self::REASONS[$slots[3]], 'confirmed_missing' => $slots[4] === 1,
                    'attempts' => $slots[5], 'retryable' => $slots[6] === 1];
            }
        }

        return self::classifyMissingArticlePhp($messageId, $statusCode, $attemptCount);
    }

    /** The trimmed message-id without one pair of enclosing angle brackets; null when nothing remains. */
    public static function normalizeMessageId(?string $messageId): ?string
    {
        $id = trim($messageId ?? '');
        if (\strlen($id) >= 2 && $id[0] === '<' && $id[-1] === '>') {
            $id = substr($id, 1, -1);
        }

        return $id === '' ? null : $id;
    }

    /**
     * Run one Wave 36.2 Rust operation over packed native-endian slots and string bytes; null when the library is
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
            $written = $ffi->nntmux_w36_nntp_fingerprint_exceptions_yenc($operation, $input, \strlen($input), $output, $capacity);
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

    /** The substring a Rust `[present, start, length]` triple selects: null when absent, false when out of range. */
    public static function nativeRange(?string $subject, int $present, int $start, int $length): string|false|null
    {
        if ($present === 0 && $start === 0 && $length === 0) {
            return null;
        }
        if ($present !== 1 || $subject === null || $start < 0 || $length < 1 || $start > \strlen($subject) - $length) {
            return false;
        }

        return substr($subject, $start, $length);
    }
}
