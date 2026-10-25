<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds;

use App\Support\RustPipelineLibrary;
use FFI;
use RuntimeException;
use Throwable;

/**
 * A feed reported throttling in its response body (e.g. an HTTP 200 "rate limit" envelope);
 * the importer backs off and retries the page as it does for HTTP 429.
 */
final class FeedRateLimitedException extends RuntimeException
{
    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustPredbSourcesHttpNswdb3dsdbRateParityTest.php is below 1.5x.
     */
    public const bool RUST_FFI_DEFAULT = false;

    private const string RATE_LIMIT_FFI_CDEF = <<<'C'
        ssize_t nntmux_w384_rate_limit_backoff_plan(
            int64_t attempt,
            int64_t retry_after,
            const char *message,
            size_t message_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /** Lowercase body markers that identify a throttling envelope. */
    private const array RATE_LIMIT_MARKERS = ['rate limit', 'ratelimit', 'too many requests', 'throttl'];

    private const int RATE_LIMIT_BASE_BACKOFF = 15;

    private const int RATE_LIMIT_MAX_BACKOFF = 900;

    private const int RATE_LIMIT_MAX_EXPONENT = 7;

    private const int RATE_LIMIT_GIVE_UP_AFTER = 5;

    private const int RATE_LIMIT_REASON_BYTES = 120;

    private const string RATE_LIMIT_FIELD_SEPARATOR = "\x1F";

    private static ?bool $rateLimitUseRustFfi = null;

    private static FFI|false|null $rateLimitFfi = null;

    public static function useRustFfi(?bool $enabled = true): void
    {
        self::$rateLimitUseRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$rateLimitUseRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function resetRustFfi(): void
    {
        self::$rateLimitUseRustFfi = null;
        self::$rateLimitFfi = null;
    }

    /**
     * Plans the backoff for a throttled page: whether the body text carries a rate-limit marker
     * (ASCII case-insensitive), the attempt (at least one), the wait (a positive Retry-After
     * capped at 900 seconds, else 15 s doubling per attempt up to the same cap), whether to give
     * up after five attempts and the trimmed reason (first 120 bytes) for logging.
     *
     * @return array{throttled: bool, attempt: int, backoff_seconds: int, give_up: bool, reason: string}
     */
    public static function planRateLimitBackoff(string $message, int $attempt, int $retryAfterSeconds): array
    {
        return self::usesRustFfi()
            ? self::planRateLimitBackoffNative($message, $attempt, $retryAfterSeconds)
            : self::planRateLimitBackoffPhp($message, $attempt, $retryAfterSeconds);
    }

    /**
     * @return array{throttled: bool, attempt: int, backoff_seconds: int, give_up: bool, reason: string}
     */
    private static function planRateLimitBackoffNative(string $message, int $attempt, int $retryAfterSeconds): array
    {
        $output = self::callRateLimitNative([$attempt, $retryAfterSeconds, $message, strlen($message)], self::RATE_LIMIT_REASON_BYTES + 64);
        // The reason is the last field and may itself contain the separator.
        $fields = $output === null ? [] : explode(self::RATE_LIMIT_FIELD_SEPARATOR, $output, 5);
        if (count($fields) !== 5) {
            return self::planRateLimitBackoffPhp($message, $attempt, $retryAfterSeconds);
        }

        return [
            'throttled' => $fields[0] === '1',
            'attempt' => (int) $fields[1],
            'backoff_seconds' => (int) $fields[2],
            'give_up' => $fields[3] === '1',
            'reason' => $fields[4],
        ];
    }

    /**
     * @return array{throttled: bool, attempt: int, backoff_seconds: int, give_up: bool, reason: string}
     */
    private static function planRateLimitBackoffPhp(string $message, int $attempt, int $retryAfterSeconds): array
    {
        $lower = strtolower($message);
        $throttled = false;
        foreach (self::RATE_LIMIT_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                $throttled = true;
                break;
            }
        }
        $attempt = max(1, $attempt);
        $backoff = $retryAfterSeconds > 0
            ? min($retryAfterSeconds, self::RATE_LIMIT_MAX_BACKOFF)
            : min(self::RATE_LIMIT_BASE_BACKOFF << (min($attempt, self::RATE_LIMIT_MAX_EXPONENT) - 1), self::RATE_LIMIT_MAX_BACKOFF);

        return [
            'throttled' => $throttled,
            'attempt' => $attempt,
            'backoff_seconds' => $backoff,
            'give_up' => $attempt > self::RATE_LIMIT_GIVE_UP_AFTER,
            'reason' => substr(trim($message), 0, self::RATE_LIMIT_REASON_BYTES),
        ];
    }

    /**
     * @param  list<int|string>  $args
     */
    private static function callRateLimitNative(array $args, int $hint): ?string
    {
        self::$rateLimitFfi ??= RustPipelineLibrary::resolveDomain('metadata_w384', self::RATE_LIMIT_FFI_CDEF) ?? false;
        if (self::$rateLimitFfi === false) {
            return null;
        }
        $ffi = self::$rateLimitFfi;

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $hint, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w384_rate_limit_backoff_plan(...[...$args, $buffer, $capacity]);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w384_rate_limit_backoff_plan(...[...$args, $buffer, $capacity]);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return null;
        }
    }
}
