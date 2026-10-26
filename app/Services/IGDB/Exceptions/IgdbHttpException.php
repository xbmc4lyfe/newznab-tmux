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

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 6.28x, p99 5.21x; uncommon mean 4.96x, p99 4.22x); see RustGamesProcessorGdprNotificationGenreIgdbClientExceptionW67ParityTest. */
    public const bool W67_RUST_FFI_DEFAULT = true;

    public const string W67_IGDB_HTTP_EXCEPTION_FFI_CDEF = <<<'C'
        ssize_t nntmux_w673_igdb_http_exception_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of the Wave 67.3 batch. */
    public const int W67_IGDB_HTTP_EXCEPTION_TRAILER = 27;

    public static function enableW67RustFfi(): void
    {
        self::w67RustFfiState('set', true);
    }

    public static function disableW67RustFfi(): void
    {
        self::w67RustFfiState('set', false);
    }

    /** Restores W67_RUST_FFI_DEFAULT and drops the cached library handle and scratch buffers. */
    public static function resetW67RustFfi(): void
    {
        self::w67RustFfiState('reset');
    }

    public static function usesW67RustFfi(): bool
    {
        $override = self::w67RustFfiState();

        return \is_bool($override) ? $override : self::W67_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the IgdbHttpException Wave 67 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW67RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w67RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w67RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 67.3 IGDB failure retry plan. Each item is `status|retryAfter|attempt|elapsedMs|body` (the body is last, so
     * it may hold `|`). Status (up to 3 trimmed digits), attempt (2) and elapsed (9) are 0 empty, 1 digits or 2 other.
     * Category follows classifyHttpFailurePhp(): 0 transport (no status or 0), 1 auth, 2 not_found, 3 timeout, 4
     * rate_limited, 5 client, 6 server, 7 unknown; a client error whose body names "too many requests" is
     * reclassified as rate_limited. Retryable: transport, timeout, rate_limited and server. Retry-After is 0 empty, 1
     * up to 9 digits (seconds), 2 an HTTP date (holding "GMT" caselessly and a comma) or 3 invalid. The body is
     * trimmed of WHITESPACE: kind 0 empty, 1 JSON (`[` or `{`), 2 markup (`<`) or 3 text; marker is the 1-based first
     * of W67_IGDB_BODY_MARKERS held caselessly. Backoff is 0 when not retryable, else the Retry-After seconds (cap
     * 3600) in ms, 60000 for a date, or 1000 << attempt (cap 300000); exhausted at W67_IGDB_MAX_ATTEMPTS attempts or
     * when elapsed plus backoff exceeds W67_IGDB_RETRY_BUDGET_MS. Decision 4 malformed, 0 fail fast, 1 exhausted, 2
     * retry after the header or 3 retry with backoff. Per item `category | statusState << 3 | retryable << 5 |
     * retryAfterState << 6 | bodyKind << 8 | marker << 10 | reclassified << 14 | attemptState << 15 | elapsedState << 17
     * | decision << 19 | excerptLength (cap BODY_EXCERPT_MAX) << 22 | status << 31 | backoff / 100 (cap 2^20 - 1) <<
     * 41`. Then the five decision, eight category, four Retry-After and four body-kind counts, the bodies with a
     * marker, the reclassified rows, the total and largest retry backoff, the 1-based first retried item and the
     * distinct status codes.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w67IgdbHttpExceptionPlanBatch(array $items): array
    {
        return self::usesW67RustFfi() ? self::w67IgdbHttpExceptionPlanBatchNative($items) : self::w67IgdbHttpExceptionPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w67IgdbHttpExceptionPlanBatchNative(array $items): array
    {
        return self::w67Invoke($items, \count($items) + self::W67_IGDB_HTTP_EXCEPTION_TRAILER) ?? self::w67IgdbHttpExceptionPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w67IgdbHttpExceptionPlanBatchPhp(array $items): array
    {
        $out = [];
        $decisions = [0, 0, 0, 0, 0];
        $categories = [0, 0, 0, 0, 0, 0, 0, 0];
        $retryStates = [0, 0, 0, 0];
        $bodyKinds = [0, 0, 0, 0];
        $markers = $reclassifiedCount = $backoffSum = $maxBackoff = $firstRetry = 0;
        $codes = [];
        $index = 0;
        foreach ($items as $item) {
            [$rawStatus, $rawRetry, $rawAttempt, $rawElapsed, $rawBody] = explode('|', (string) $item, 5) + ['', '', '', '', ''];
            [$statusState, $code] = self::w67Count($rawStatus, 3);
            $category = $statusState !== 1 || $code === 0 ? 0 : match (true) {
                $code === 401, $code === 403 => 1,
                $code === 404 => 2,
                $code === 408 => 3,
                $code === 429 => 4,
                $code >= 400 && $code <= 499 => 5,
                $code >= 500 && $code <= 599 => 6,
                default => 7,
            };
            $retry = trim($rawRetry);
            [$retryCountState, $seconds] = self::w67Count($rawRetry, 9);
            $retryState = match (true) {
                $retryCountState === 0 => 0,
                $retryCountState === 1 => 1,
                stripos($retry, 'gmt') !== false && str_contains($retry, ',') => 2,
                default => 3,
            };
            $body = trim($rawBody, self::WHITESPACE);
            $bodyKind = match (true) {
                $body === '' => 0,
                $body[0] === '[' || $body[0] === '{' => 1,
                $body[0] === '<' => 2,
                default => 3,
            };
            $marker = 0;
            foreach (self::W67_IGDB_BODY_MARKERS as $position => $needle) {
                if (stripos($body, $needle) !== false) {
                    $marker = $position + 1;
                    break;
                }
            }
            $reclassified = $category === 5 && $marker === 3;
            if ($reclassified) {
                $category = 4;
            }
            $retryable = $category === 0 || $category === 3 || $category === 4 || $category === 6;
            [$attemptState, $attempt] = self::w67Count($rawAttempt, 2);
            [$elapsedState, $elapsed] = self::w67Count($rawElapsed, 9);
            $backoff = match (true) {
                ! $retryable => 0,
                $retryState === 1 => min($seconds, 3600) * 1000,
                $retryState === 2 => 60_000,
                default => min(1000 << min($attempt, 16), 300_000),
            };
            $exhausted = $attempt >= self::W67_IGDB_MAX_ATTEMPTS || $elapsed + $backoff > self::W67_IGDB_RETRY_BUDGET_MS;
            $decision = match (true) {
                $statusState === 2 || $attemptState === 2 || $elapsedState === 2 => 4,
                ! $retryable => 0,
                $exhausted => 1,
                $retryState === 1 || $retryState === 2 => 2,
                default => 3,
            };
            $decisions[$decision]++;
            $categories[$category]++;
            $retryStates[$retryState]++;
            $bodyKinds[$bodyKind]++;
            $markers += $marker > 0 ? 1 : 0;
            $reclassifiedCount += $reclassified ? 1 : 0;
            if ($decision === 2 || $decision === 3) {
                $backoffSum = min($backoffSum + $backoff, self::W67_TOTAL_CAP);
                $maxBackoff = max($maxBackoff, $backoff);
                if ($firstRetry === 0) {
                    $firstRetry = $index + 1;
                }
            }
            if ($statusState === 1) {
                $codes[$code] = true;
            }
            $out[] = $category | $statusState << 3 | ($retryable ? 1 : 0) << 5 | $retryState << 6 | $bodyKind << 8 | $marker << 10
                | ($reclassified ? 1 : 0) << 14 | $attemptState << 15 | $elapsedState << 17 | $decision << 19
                | min(\strlen($body), self::BODY_EXCERPT_MAX) << 22 | $code << 31 | min(intdiv($backoff, 100), 1_048_575) << 41;
            $index++;
        }
        array_push($out, ...$decisions);
        array_push($out, ...$categories);
        array_push($out, ...$retryStates);
        array_push($out, ...$bodyKinds);
        array_push($out, $markers, $reclassifiedCount, $backoffSum, $maxBackoff, $firstRetry, \count($codes));

        return $out;
    }

    /** Body phrases, matched caselessly in order. */
    private const array W67_IGDB_BODY_MARKERS = ['authorization failure', 'invalid client', 'too many requests', 'syntax error', 'not found', 'internal server error',
        'service unavailable', 'timeout'];

    /** Attempts after which a retryable Wave 67.3 failure gives up. */
    public const int W67_IGDB_MAX_ATTEMPTS = 5;

    /** Wave 67.3 retry budget in milliseconds. */
    public const int W67_IGDB_RETRY_BUDGET_MS = 600_000;

    /** Cap for the Wave 67.3 running totals. */
    private const int W67_TOTAL_CAP = 4_611_686_018_427_387_904;

    /**
     * (0 empty, 1 one to $max ASCII digits, 2 other; the value) for the trimmed field.
     *
     * @return array{int, int}
     */
    private static function w67Count(string $text, int $max): array
    {
        $text = trim($text);
        if ($text === '') {
            return [0, 0];
        }
        $length = \strlen($text);

        return $length <= $max && strspn($text, '0123456789') === $length ? [1, (int) $text] : [2, 0];
    }

    /**
     * Wave 67 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and returns the
     * previous override, 'reset' clears the override, library handle and buffers, 'ffi' resolves the library
     * (null when unavailable) and 'out' and 'lens' return `int64_t` output and length-column buffers of at least
     * $val words. Function statics keep the class's property set unchanged.
     */
    private static function w67RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'reset') {
            $override = $ffi = $out = $lens = null;
            $outCap = $lensCap = 0;

            return null;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('services_w673', self::W67_IGDB_HTTP_EXCEPTION_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w67RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w67RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 67.3 IgdbHttpException export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call declines
     * or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w67Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w67RustFfiState('ffi');
            $out = self::w67RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w67RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W67_IGDB_HTTP_EXCEPTION_FFI_CDEF.)
            $written = $ffi->nntmux_w673_igdb_http_exception_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
