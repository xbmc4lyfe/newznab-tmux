<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Support\RustPipelineLibrary;
use FFI;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * An API search failed in the search engine and there is no result to fall
 * back on. Rendered as a retryable 503 in the calling API version's format.
 */
final class SearchUnavailableException extends RuntimeException
{
    public const int RETRY_AFTER_SECONDS = 30;

    /** Render through the legacy XML API error helper. */
    public const int RENDER_LEGACY_XML = 0;

    /** Render through the v2 JSON API error helper. */
    public const int RENDER_V2_JSON = 1;

    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_search_unavailable_render_driver(const char *version, size_t version_len);
        intptr_t nntmux_search_unavailable_retry_after(int64_t seconds, char *out, size_t cap);
        C;

    /**
     * The Rust helpers stay opt-in: they did not beat PHP by 1.5x in the paired benchmark
     * (tests/Unit/RustSearchUnavailableObserversFailedRelease2faParityTest.php).
     */
    private const bool DEFAULT_USE_RUST_FFI = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public function __construct(public readonly string $apiVersion)
    {
        parent::__construct('Search is temporarily unavailable, retry shortly');
    }

    public function render(): Response
    {
        $response = self::renderDriver($this->apiVersion) === self::RENDER_V2_JSON
            ? apiJsonError(900, $this->getMessage())
            : showApiError(900, $this->getMessage());

        $response->setStatusCode(503);
        $response->headers->set('Retry-After', self::retryAfterHeaderValue(self::RETRY_AFTER_SECONDS));

        return $response;
    }

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = null;
        self::$ffi = null;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::DEFAULT_USE_RUST_FFI;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::$ffi = $override;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * One of the `RENDER_*` constants for the calling API version.
     */
    public static function renderDriver(string $apiVersion): int
    {
        return self::usesRustFfi()
            ? self::renderDriverNative($apiVersion)
            : self::renderDriverPhp($apiVersion);
    }

    public static function renderDriverPhp(string $apiVersion): int
    {
        return $apiVersion === 'v2' ? self::RENDER_V2_JSON : self::RENDER_LEGACY_XML;
    }

    public static function renderDriverNative(string $apiVersion): int
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::renderDriverPhp($apiVersion);
        }

        try {
            // @phpstan-ignore method.notFound
            $result = $ffi->nntmux_search_unavailable_render_driver($apiVersion, \strlen($apiVersion));
        } catch (Throwable) {
            return self::renderDriverPhp($apiVersion);
        }

        return $result === self::RENDER_V2_JSON || $result === self::RENDER_LEGACY_XML
            ? $result
            : self::renderDriverPhp($apiVersion);
    }

    /**
     * The `Retry-After` header value for a delay in seconds.
     */
    public static function retryAfterHeaderValue(int $seconds): string
    {
        return self::usesRustFfi()
            ? self::retryAfterHeaderValueNative($seconds)
            : self::retryAfterHeaderValuePhp($seconds);
    }

    public static function retryAfterHeaderValuePhp(int $seconds): string
    {
        return (string) $seconds;
    }

    public static function retryAfterHeaderValueNative(int $seconds): string
    {
        $ffi = self::ffi();
        $cap = 0;
        $scratch = $ffi !== null ? RustPipelineLibrary::obtainScratch($ffi, 32, $cap) : null;
        if ($ffi === null || $scratch === null) {
            return self::retryAfterHeaderValuePhp($seconds);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_search_unavailable_retry_after($seconds, $scratch, $cap);
        } catch (Throwable) {
            return self::retryAfterHeaderValuePhp($seconds);
        }

        return \is_int($written) && $written > 0 && $written <= $cap
            ? FFI::string($scratch, $written)
            : self::retryAfterHeaderValuePhp($seconds);
    }
}
