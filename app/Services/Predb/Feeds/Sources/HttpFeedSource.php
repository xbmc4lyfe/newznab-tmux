<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Services\Predb\Feeds\Contracts\PredbFeedSource;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Shared HTTP plumbing for remote PreDB feeds.
 */
abstract class HttpFeedSource implements PredbFeedSource
{
    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustPredbSourcesHttpNswdb3dsdbRateParityTest.php is below 1.5x.
     */
    public const bool HTTP_FEED_RUST_FFI_DEFAULT = false;

    /** Distinct from the bounded, srrDB and xREL subclass toggles and FFI_CDEF constants. */
    private const string HTTP_FEED_FFI_CDEF = <<<'C'
        ssize_t nntmux_w384_http_feed_request_plan(
            int64_t timeout,
            int64_t status,
            uint32_t connection_error,
            const char *user_agent,
            size_t user_agent_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    private const int HTTP_FEED_ATTEMPTS = 2;

    private const int HTTP_FEED_RETRY_SLEEP_MS = 500;

    private const int HTTP_FEED_CONNECT_TIMEOUT_CAP = 10;

    private const string HTTP_FEED_FIELD_SEPARATOR = "\x1F";

    /** Shared by every subclass: only this class's methods touch the private toggle. */
    private static ?bool $httpFeedUseRustFfi = null;

    private static FFI|false|null $httpFeedFfi = null;

    public function __construct(
        protected readonly string $endpoint,
        protected readonly int $pageSize = 100,
        protected readonly int $timeout = 15,
        protected readonly string $userAgent = 'NNTmux-PreDB-Importer/1.0',
    ) {}

    protected function http(): PendingRequest
    {
        return Http::timeout($this->timeout)
            ->connectTimeout(min($this->timeout, 10))
            ->withUserAgent($this->userAgent)
            // Transient failures only (connection errors, 5xx). 4xx are not retried here; a 429 goes
            // straight to the command's Retry-After backoff.
            ->retry(2, 500, static fn (Throwable $e): bool => $e instanceof ConnectionException
                || ($e instanceof RequestException && $e->response->serverError()), throw: false);
    }

    public static function useHttpFeedRustFfi(?bool $enabled = true): void
    {
        self::$httpFeedUseRustFfi = $enabled;
    }

    public static function usesHttpFeedRustFfi(): bool
    {
        return self::$httpFeedUseRustFfi ?? self::HTTP_FEED_RUST_FFI_DEFAULT;
    }

    public static function resetHttpFeedRustFfi(): void
    {
        self::$httpFeedUseRustFfi = null;
        self::$httpFeedFfi = null;
    }

    /**
     * Mirrors http(): the request and connect timeouts, the attempt count and retry sleep, and how
     * a response status (or connection error) is classified: retried (connection errors, 5xx),
     * rate limited (429, handed to the command's Retry-After backoff) or another client error.
     *
     * @return array{timeout: int, connect_timeout: int, attempts: int, retry_sleep_ms: int, retryable: bool, rate_limited: bool, client_error: bool, user_agent: string}
     */
    public static function planHttpFeedRequest(int $timeout, int $status, bool $connectionError, string $userAgent): array
    {
        return self::usesHttpFeedRustFfi()
            ? self::planHttpFeedRequestNative($timeout, $status, $connectionError, $userAgent)
            : self::planHttpFeedRequestPhp($timeout, $status, $connectionError, $userAgent);
    }

    /**
     * @return array{timeout: int, connect_timeout: int, attempts: int, retry_sleep_ms: int, retryable: bool, rate_limited: bool, client_error: bool, user_agent: string}
     */
    private static function planHttpFeedRequestNative(int $timeout, int $status, bool $connectionError, string $userAgent): array
    {
        $output = self::callHttpFeedNative([$timeout, $status, (int) $connectionError, $userAgent, strlen($userAgent)], strlen($userAgent) + 96);
        // The user agent is the last field and may itself contain the separator.
        $fields = $output === null ? [] : explode(self::HTTP_FEED_FIELD_SEPARATOR, $output, 8);
        if (count($fields) !== 8) {
            return self::planHttpFeedRequestPhp($timeout, $status, $connectionError, $userAgent);
        }

        return [
            'timeout' => (int) $fields[0],
            'connect_timeout' => (int) $fields[1],
            'attempts' => (int) $fields[2],
            'retry_sleep_ms' => (int) $fields[3],
            'retryable' => $fields[4] === '1',
            'rate_limited' => $fields[5] === '1',
            'client_error' => $fields[6] === '1',
            'user_agent' => $fields[7],
        ];
    }

    /**
     * @return array{timeout: int, connect_timeout: int, attempts: int, retry_sleep_ms: int, retryable: bool, rate_limited: bool, client_error: bool, user_agent: string}
     */
    private static function planHttpFeedRequestPhp(int $timeout, int $status, bool $connectionError, string $userAgent): array
    {
        return [
            'timeout' => $timeout,
            'connect_timeout' => min($timeout, self::HTTP_FEED_CONNECT_TIMEOUT_CAP),
            'attempts' => self::HTTP_FEED_ATTEMPTS,
            'retry_sleep_ms' => self::HTTP_FEED_RETRY_SLEEP_MS,
            'retryable' => $connectionError || ($status >= 500 && $status < 600),
            'rate_limited' => ! $connectionError && $status === 429,
            'client_error' => ! $connectionError && $status >= 400 && $status < 500,
            'user_agent' => trim($userAgent),
        ];
    }

    /**
     * @param  list<int|string>  $args
     */
    private static function callHttpFeedNative(array $args, int $hint): ?string
    {
        self::$httpFeedFfi ??= RustPipelineLibrary::resolveDomain('metadata_w384', self::HTTP_FEED_FFI_CDEF) ?? false;
        if (self::$httpFeedFfi === false) {
            return null;
        }
        $ffi = self::$httpFeedFfi;

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $hint, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w384_http_feed_request_plan(...[...$args, $buffer, $capacity]);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w384_http_feed_request_plan(...[...$args, $buffer, $capacity]);
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
