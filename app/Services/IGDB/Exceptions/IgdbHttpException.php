<?php

declare(strict_types=1);

namespace App\Services\IGDB\Exceptions;

use App\Support\RustPipelineLibrary;
use FFI;
use RuntimeException;
use Throwable;

class IgdbHttpException extends RuntimeException
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w35_igdb_http_failure_classify(
            int64_t status_code,
            uint32_t has_body,
            const char *input_ptr,
            size_t input_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustIgdbBookTvProviderMetadataParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private const string WHITESPACE = " \t\n\r\0\x0B\x0C";

    private const int BODY_EXCERPT_MAX = 256;

    private const string FIELD_SEPARATOR = "\x1F";

    private const string NULL_MARK = "\x1E";

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public function __construct(
        string $message,
        private readonly int $statusCode,
        private readonly ?string $responseBody = null,
    ) {
        parent::__construct($message, $statusCode);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getResponseBody(): ?string
    {
        return $this->responseBody;
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
     * Classifies an IGDB HTTP failure: `0` is a transport error, 401/403 auth, 404 not_found,
     * 408 timeout, 429 rate_limited, other 4xx client, 5xx server and anything else unknown.
     * Transport, timeout, rate-limit and server failures are retryable. The message is trimmed
     * with whitespace runs collapsed (blank gets a generic message) and the body becomes a trimmed
     * excerpt of at most 256 bytes (blank becomes null).
     *
     * @return array{status_code: int, category: string, retryable: bool, message: string, body_excerpt: ?string}
     */
    public static function classifyHttpFailure(int $statusCode, string $message, ?string $responseBody = null): array
    {
        return self::usesRustFfi()
            ? self::classifyHttpFailureNative($statusCode, $message, $responseBody)
            : self::classifyHttpFailurePhp($statusCode, $message, $responseBody);
    }

    /**
     * @return array{status_code: int, category: string, retryable: bool, message: string, body_excerpt: ?string}
     */
    public static function classifyHttpFailureNative(int $statusCode, string $message, ?string $responseBody = null): array
    {
        $input = $message.self::FIELD_SEPARATOR.($responseBody ?? '');
        $fields = self::callNative('nntmux_w35_igdb_http_failure_classify', [$statusCode, $responseBody === null ? 0 : 1, $input, strlen($input)]);
        if ($fields === null || count($fields) !== 5 || $fields[0] === null || $fields[1] === null || $fields[2] === null || $fields[3] === null) {
            return self::classifyHttpFailurePhp($statusCode, $message, $responseBody);
        }

        return [
            'status_code' => (int) $fields[0],
            'category' => $fields[1],
            'retryable' => $fields[2] === '1',
            'message' => $fields[3],
            'body_excerpt' => $fields[4],
        ];
    }

    /**
     * @return array{status_code: int, category: string, retryable: bool, message: string, body_excerpt: ?string}
     */
    public static function classifyHttpFailurePhp(int $statusCode, string $message, ?string $responseBody = null): array
    {
        $category = match (true) {
            $statusCode === 0 => 'transport',
            $statusCode === 401, $statusCode === 403 => 'auth',
            $statusCode === 404 => 'not_found',
            $statusCode === 408 => 'timeout',
            $statusCode === 429 => 'rate_limited',
            $statusCode >= 400 && $statusCode <= 499 => 'client',
            $statusCode >= 500 && $statusCode <= 599 => 'server',
            default => 'unknown',
        };
        $message = preg_replace('/[ \t\n\r\0\x0B\x0C]+/', ' ', trim($message, self::WHITESPACE)) ?? '';
        if ($message === '') {
            $message = "IGDB request failed with HTTP status {$statusCode}";
        }
        $excerpt = null;
        if ($responseBody !== null) {
            $body = trim($responseBody, self::WHITESPACE);
            $excerpt = $body === '' ? null : substr($body, 0, self::BODY_EXCERPT_MAX);
        }

        return [
            'status_code' => $statusCode,
            'category' => $category,
            'retryable' => \in_array($category, ['transport', 'timeout', 'rate_limited', 'server'], true),
            'message' => $message,
            'body_excerpt' => $excerpt,
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
