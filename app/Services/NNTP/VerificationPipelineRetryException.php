<?php

declare(strict_types=1);

namespace App\Services\NNTP;

/** No correlated article outcome was received; a single primary request may recover it. */
final class VerificationPipelineRetryException extends \RuntimeException
{
    public const string FFI_CDEF = NntpArticleMissingException::FFI_CDEF;

    public const string DEFAULT_REASON = 'No correlated article outcome was received.';

    private const int MAX_BASE_BACKOFF_MS = 60000;

    private const int MAX_BACKOFF_MS = 300000;

    private const array REASON_KINDS = ['unavailable', 'closed', 'interrupted', 'ended', 'unknown'];

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
     * Summarize the next retry: negative attempts read as zero, at least one attempt is allowed and the base backoff
     * is clamped to 0-60000 ms. The backoff doubles per attempt up to 300000 ms and is zero once exhausted. The
     * reason is trimmed (defaulting when empty) and classified by the first of "unavailable", "closed",
     * "interrupted" or "ended" it contains (ASCII case-insensitive).
     *
     * @return array{attempt: int, max_attempts: int, base_backoff_ms: int, remaining: int, exhausted: bool, backoff_ms: int, reason: string, reason_kind: string}
     */
    public static function summarizeRetryPlan(int $attempt, int $maxAttempts, int $baseBackoffMs, ?string $reason): array
    {
        return self::usesRustFfi() ? self::summarizeRetryPlanNative($attempt, $maxAttempts, $baseBackoffMs, $reason)
            : self::summarizeRetryPlanPhp($attempt, $maxAttempts, $baseBackoffMs, $reason);
    }

    /** @return array{attempt: int, max_attempts: int, base_backoff_ms: int, remaining: int, exhausted: bool, backoff_ms: int, reason: string, reason_kind: string} */
    public static function summarizeRetryPlanPhp(int $attempt, int $maxAttempts, int $baseBackoffMs, ?string $reason): array
    {
        $attempt = max(0, $attempt);
        $max = max(1, $maxAttempts);
        $base = min(self::MAX_BASE_BACKOFF_MS, max(0, $baseBackoffMs));
        $exhausted = $attempt >= $max;
        $lower = strtolower($reason ?? '');
        $trimmed = trim($reason ?? '');

        return ['attempt' => $attempt, 'max_attempts' => $max, 'base_backoff_ms' => $base, 'remaining' => max(0, $max - $attempt), 'exhausted' => $exhausted,
            'backoff_ms' => $exhausted ? 0 : min(self::MAX_BACKOFF_MS, $base << min($attempt, 20)),
            'reason' => $trimmed === '' ? self::DEFAULT_REASON : $trimmed,
            'reason_kind' => match (true) {
                str_contains($lower, 'unavailable') => 'unavailable',
                str_contains($lower, 'closed') => 'closed',
                str_contains($lower, 'interrupted') => 'interrupted',
                str_contains($lower, 'ended') => 'ended',
                default => 'unknown',
            }];
    }

    /** @return array{attempt: int, max_attempts: int, base_backoff_ms: int, remaining: int, exhausted: bool, backoff_ms: int, reason: string, reason_kind: string} */
    public static function summarizeRetryPlanNative(int $attempt, int $maxAttempts, int $baseBackoffMs, ?string $reason): array
    {
        $slots = NntpArticleMissingException::nativeSlots(NntpArticleMissingException::evaluateNative(2,
            pack('q5', $attempt, $maxAttempts, $baseBackoffMs, (int) ($reason !== null), \strlen($reason ?? '')).($reason ?? '')), 10);
        if ($slots !== null) {
            $trimmed = NntpArticleMissingException::nativeRange($reason, $slots[6], $slots[7], $slots[8]);
            if ($trimmed !== false && $slots[0] >= 0 && $slots[1] >= 1 && $slots[2] >= 0 && $slots[2] <= self::MAX_BASE_BACKOFF_MS && $slots[3] >= 0
                && \in_array($slots[4], [0, 1], true) && $slots[5] >= 0 && $slots[5] <= self::MAX_BACKOFF_MS && isset(self::REASON_KINDS[$slots[9]])) {
                return ['attempt' => $slots[0], 'max_attempts' => $slots[1], 'base_backoff_ms' => $slots[2], 'remaining' => $slots[3],
                    'exhausted' => $slots[4] === 1, 'backoff_ms' => $slots[5], 'reason' => $trimmed ?? self::DEFAULT_REASON, 'reason_kind' => self::REASON_KINDS[$slots[9]]];
            }
        }

        return self::summarizeRetryPlanPhp($attempt, $maxAttempts, $baseBackoffMs, $reason);
    }
}
