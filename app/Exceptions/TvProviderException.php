<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Support\RustPipelineLibrary;
use FFI;
use RuntimeException;
use Throwable;

/**
 * A TV metadata provider failed transiently (rate limit, 5xx, timeout, connection error).
 *
 * A 404 or an empty result is never transient; providers return false/null for those.
 */
class TvProviderException extends RuntimeException
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_tv_provider_is_transient_status(int64_t status);
        ssize_t nntmux_tv_provider_parse_retry_after(uint32_t has_header, const char *header_ptr, size_t header_len, int64_t now_epoch, int64_t *out_seconds);
        C;

    /** Null selects the benchmark-qualified production default (PHP; see RustTvProviderLookupVideoMovieObserverParityTest). */
    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public function __construct(
        public readonly string $provider,
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?int $retryAfterSeconds = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode ?? 0, $previous);
    }

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use;
        if ($use === null) {
            self::$ffi = null;
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::$ffi = $override;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    public static function isTransientStatus(int $status): bool
    {
        return self::usesRustFfi()
            ? self::isTransientStatusNative($status)
            : self::isTransientStatusPhp($status);
    }

    public static function isTransientStatusNative(int $status): bool
    {
        if (($ffi = self::ffi()) === null) {
            return self::isTransientStatusPhp($status);
        }
        try {
            // @phpstan-ignore method.notFound
            $res = $ffi->nntmux_tv_provider_is_transient_status($status);
            if (\is_int($res) && ($res === 0 || $res === 1)) {
                return $res === 1;
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return self::isTransientStatusPhp($status);
    }

    public static function isTransientStatusPhp(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    /**
     * Seconds from a Retry-After header (delta-seconds or HTTP-date), or null when absent.
     */
    public static function parseRetryAfter(?string $header, ?int $now = null): ?int
    {
        return self::usesRustFfi()
            ? self::parseRetryAfterNative($header, $now)
            : self::parseRetryAfterPhp($header, $now);
    }

    public static function parseRetryAfterNative(?string $header, ?int $now = null): ?int
    {
        if (($ffi = self::ffi()) === null) {
            return self::parseRetryAfterPhp($header, $now);
        }
        $nowEpoch = $now ?? time();
        $hasHeader = $header !== null ? 1 : 0;
        $headerStr = $header ?? '';
        $headerLen = \strlen($headerStr);
        try {
            $outSeconds = $ffi->new('int64_t[1]');
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_tv_provider_parse_retry_after($hasHeader, $headerStr, $headerLen, $nowEpoch, $outSeconds);
            if ($status === 0) {
                return null;
            }
            if ($status === 1) {
                return (int) $outSeconds[0];
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return self::parseRetryAfterPhp($header, $nowEpoch);
    }

    public static function parseRetryAfterPhp(?string $header, ?int $now = null): ?int
    {
        if ($header === null || trim($header) === '') {
            return null;
        }

        if (is_numeric($header)) {
            return max(0, (int) $header);
        }

        $timestamp = strtotime($header);

        return $timestamp === false ? null : max(0, $timestamp - ($now ?? time()));
    }
}
