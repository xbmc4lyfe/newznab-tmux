<?php

declare(strict_types=1);

namespace App\Services\IGDB\Exceptions;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
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

    // ---------------------------------------------------------------------
    // Wave 53.4 batch helper
    // ---------------------------------------------------------------------

    /** Enabled: minimum of three paired runs cleared every 1.5x gate (common mean 3.02x, p99 2.29x; uncommon mean 2.67x, p99 2.06x); see RustAnidbArchiveMovieBrowseMovieServiceIgdbExW53ParityTest. */
    private const bool W53_RUST_FFI_DEFAULT = true;

    public const string W53_FFI_CDEF = <<<'C'
        ssize_t nntmux_w534_igdb_failure_class_batch(const char *text, size_t text_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /**
     * classifyHttpFailurePhp() for many failures at once, as offsets instead of copies. Each
     * failure yields four words: the category code (transport 0, auth 1, not_found 2, timeout 3,
     * rate_limited 4, client 5, server 6, unknown 7) | 16 when retryable | 32 when the message is
     * blank, the trimmed message span (offset << 32 | length), the final message length and the
     * body excerpt span, or -1 when the body is null or blank.
     *
     * @param  list<int>  $statusCodes
     * @param  list<string>  $messages
     * @param  list<?string>  $bodies
     * @return list<int>
     */
    public static function classifyHttpFailureBatch(array $statusCodes, array $messages, array $bodies): array
    {
        return self::usesW53RustFfi()
            ? self::classifyHttpFailureBatchNative($statusCodes, $messages, $bodies)
            : self::classifyHttpFailureBatchPhp($statusCodes, $messages, $bodies);
    }

    /**
     * @param  list<int>  $statusCodes
     * @param  list<string>  $messages
     * @param  list<?string>  $bodies
     * @return list<int>
     */
    public static function classifyHttpFailureBatchNative(array $statusCodes, array $messages, array $bodies): array
    {
        $count = self::w53AssertColumns($statusCodes, $messages, $bodies);
        if ($count === 0) {
            return [];
        }

        // Messages then bodies; implode() writes a null body as ''.
        return self::w53Call(implode("\0", $messages)."\0".implode("\0", $bodies), pack('P*', ...$statusCodes), $count, 4)
            ?? self::classifyHttpFailureBatchPhp($statusCodes, $messages, $bodies);
    }

    /**
     * @param  list<int>  $statusCodes
     * @param  list<string>  $messages
     * @param  list<?string>  $bodies
     * @return list<int>
     */
    public static function classifyHttpFailureBatchPhp(array $statusCodes, array $messages, array $bodies): array
    {
        self::w53AssertColumns($statusCodes, $messages, $bodies);
        $out = [];
        foreach ($statusCodes as $i => $statusCode) {
            $category = match (true) {
                $statusCode === 0 => 16,
                $statusCode === 401, $statusCode === 403 => 1,
                $statusCode === 404 => 2,
                $statusCode === 408 => 3 | 16,
                $statusCode === 429 => 4 | 16,
                $statusCode >= 400 && $statusCode <= 499 => 5,
                $statusCode >= 500 && $statusCode <= 599 => 6 | 16,
                default => 7,
            };
            $message = $messages[$i];
            $trimmed = trim($message, self::WHITESPACE);
            $body = $bodies[$i] ?? '';
            $excerpt = trim($body, self::WHITESPACE);
            array_push(
                $out,
                $trimmed === '' ? $category | 32 : $category,
                strspn($message, self::WHITESPACE) << 32 | \strlen($trimmed),
                $trimmed === ''
                    ? \strlen("IGDB request failed with HTTP status {$statusCode}")
                    : \strlen(preg_replace('/[ \t\n\r\0\x0B\x0C]+/', ' ', $trimmed) ?? ''),
                $excerpt === '' ? -1 : strspn($body, self::WHITESPACE) << 32 | min(\strlen($excerpt), self::BODY_EXCERPT_MAX),
            );
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $statusCodes
     * @param  array<array-key, mixed>  $messages
     * @param  array<array-key, mixed>  $bodies
     */
    private static function w53AssertColumns(array $statusCodes, array $messages, array $bodies): int
    {
        self::w53AssertList($statusCodes);
        self::w53AssertList($messages);
        self::w53AssertList($bodies);
        $count = \count($statusCodes);
        if (\count($messages) !== $count || \count($bodies) !== $count) {
            throw new \InvalidArgumentException('W53 failure columns must have the same length.');
        }

        return $count;
    }

    public static function usesW53RustFfi(): bool
    {
        $enabled = self::w53RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W53_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W53 batch dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW53RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w53RustFfiState('enabled');
        self::w53RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w53RustFfiState('enabled', $previous);
        }
    }

    /** @param array<array-key, mixed> $values */
    private static function w53AssertList(array $values): void
    {
        if (! array_is_list($values)) {
            throw new \InvalidArgumentException('W53 batch input must be a list.');
        }
    }

    /**
     * One FFI crossing for the batch: NUL-joined text fields and packed int64 columns in,
     * `$words` int64 words back per record. Null when the library is missing or declines
     * (a value holding a NUL byte adds a field, which the export rejects).
     *
     * @return list<int>|null
     */
    private static function w53Call(string $text, string $ints, int $count, int $words): ?array
    {
        try {
            $state = self::w53RustFfiState();
            $ffi = $state['ffi'] ?? null;
            if ($ffi === null) {
                $ffi = RustPipelineLibrary::resolveDomain('metadata_w53', self::W53_FFI_CDEF) ?? false;
                self::w53RustFfiState('ffi', $ffi);
            }
            if (! $ffi instanceof FFI) {
                return null;
            }
            $need = $count * $words;
            $out = $state['out'] ?? null;
            $cap = $state['cap'] ?? 0;
            if (! $out instanceof CData || ! \is_int($cap) || $cap < $need) {
                $cap = max(1024, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w53RustFfiState('out', $out);
                self::w53RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W53_FFI_CDEF.)
            $written = $ffi->nntmux_w534_igdb_failure_class_batch($text, \strlen($text), $ints, \strlen($ints), $count, $out, $cap);
            if ($written !== $need) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $need * 8));

            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * W53 routing override and cached binding plus reusable output buffer, kept in a function
     * static: no arguments returns the state, one argument reads a key ('reset' clears
     * everything) and two arguments write a key.
     */
    private static function w53RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;
        }

        return $state[$op] ?? null;
    }
}
