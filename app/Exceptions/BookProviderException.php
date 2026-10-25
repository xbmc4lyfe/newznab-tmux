<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Support\RustPipelineLibrary;
use FFI;
use RuntimeException;
use Throwable;

class BookProviderException extends RuntimeException
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w35_book_provider_failure_classify(
            uint32_t flags,
            int64_t status_code,
            int64_t retry_after_seconds,
            const char *provider_ptr,
            size_t provider_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustIgdbBookTvProviderMetadataParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private const int HAS_STATUS = 1;

    private const int HAS_RETRY_AFTER = 2;

    private const int RETRY_AFTER_MAX = 86400;

    private const int RATE_LIMIT_BACKOFF = 60;

    private const int RETRY_BACKOFF = 30;

    private const string FIELD_SEPARATOR = "\x1F";

    private const string NULL_MARK = "\x1E";

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

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * Classifies a book provider failure: the provider becomes a `[a-z0-9_]` slug (`unknown` when
     * blank), a null or `0` status is a transport error, 401/403 auth, 404 not_found, 408 timeout,
     * 429 rate_limited, other 4xx client, 5xx server and anything else unknown. A positive
     * retry-after is capped at one day; the backoff prefers it, then 60s for rate limits and 30s
     * for other retryable failures.
     *
     * @return array{provider: string, status_code: ?int, category: string, retryable: bool, retry_after_seconds: ?int, backoff_seconds: int}
     */
    public static function classifyProviderFailure(string $provider, ?int $statusCode, ?int $retryAfterSeconds): array
    {
        return self::usesRustFfi()
            ? self::classifyProviderFailureNative($provider, $statusCode, $retryAfterSeconds)
            : self::classifyProviderFailurePhp($provider, $statusCode, $retryAfterSeconds);
    }

    /**
     * @return array{provider: string, status_code: ?int, category: string, retryable: bool, retry_after_seconds: ?int, backoff_seconds: int}
     */
    public static function classifyProviderFailureNative(string $provider, ?int $statusCode, ?int $retryAfterSeconds): array
    {
        $flags = ($statusCode === null ? 0 : self::HAS_STATUS) | ($retryAfterSeconds === null ? 0 : self::HAS_RETRY_AFTER);
        $fields = self::callNative('nntmux_w35_book_provider_failure_classify', [$flags, $statusCode ?? 0, $retryAfterSeconds ?? 0, $provider, strlen($provider)]);
        if ($fields === null || count($fields) !== 6 || $fields[0] === null || $fields[2] === null || $fields[3] === null || $fields[5] === null) {
            return self::classifyProviderFailurePhp($provider, $statusCode, $retryAfterSeconds);
        }

        return [
            'provider' => $fields[0],
            'status_code' => $fields[1] === null ? null : (int) $fields[1],
            'category' => $fields[2],
            'retryable' => $fields[3] === '1',
            'retry_after_seconds' => $fields[4] === null ? null : (int) $fields[4],
            'backoff_seconds' => (int) $fields[5],
        ];
    }

    /**
     * @return array{provider: string, status_code: ?int, category: string, retryable: bool, retry_after_seconds: ?int, backoff_seconds: int}
     */
    public static function classifyProviderFailurePhp(string $provider, ?int $statusCode, ?int $retryAfterSeconds): array
    {
        $slug = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($provider)) ?? '', '_');
        $category = match (true) {
            $statusCode === null, $statusCode === 0 => 'transport',
            $statusCode === 401, $statusCode === 403 => 'auth',
            $statusCode === 404 => 'not_found',
            $statusCode === 408 => 'timeout',
            $statusCode === 429 => 'rate_limited',
            $statusCode >= 400 && $statusCode <= 499 => 'client',
            $statusCode >= 500 && $statusCode <= 599 => 'server',
            default => 'unknown',
        };
        $retryable = \in_array($category, ['transport', 'timeout', 'rate_limited', 'server'], true);
        $retryAfter = $retryAfterSeconds !== null && $retryAfterSeconds > 0 ? min($retryAfterSeconds, self::RETRY_AFTER_MAX) : null;

        return [
            'provider' => $slug === '' ? 'unknown' : $slug,
            'status_code' => $statusCode,
            'category' => $category,
            'retryable' => $retryable,
            'retry_after_seconds' => $retryAfter,
            'backoff_seconds' => $retryAfter ?? match (true) {
                $category === 'rate_limited' => self::RATE_LIMIT_BACKOFF,
                $retryable => self::RETRY_BACKOFF,
                default => 0,
            },
        ];
    }

    /**
     * @param  list<int|string>  $args
     * @return list<?string>|null
     */
    private static function callNative(string $symbol, array $args): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 1024, $capacity);
            if ($buffer === null) {
                return null;
            }
            $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return array_map(
                static fn (string $field): ?string => $field === self::NULL_MARK ? null : $field,
                explode(self::FIELD_SEPARATOR, FFI::string($buffer, $written)),
            );
        } catch (Throwable) {
            return null;
        }
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }
}
