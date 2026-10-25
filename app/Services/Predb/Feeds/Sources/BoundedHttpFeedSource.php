<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Support\RustPipelineLibrary;
use FFI;
use GuzzleHttp\Psr7\NoSeekStream;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Stream a public catalogue with independent wire and decoded byte caps (1 MiB by default).
 * Only identity and complete gzip members are accepted; body reads and decode share a deadline.
 */
abstract class BoundedHttpFeedSource extends HttpFeedSource
{
    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustReleaseModelDetailsFeedSourceParityTest.php is below 1.5x.
     */
    public const bool RUST_FFI_DEFAULT = false;

    /** Distinct from subclass FFI_CDEF constants (Nima4kSource declares its own). */
    private const string BOUNDED_FEED_FFI_CDEF = <<<'C'
        ssize_t nntmux_w374_bounded_body_read_plan(
            int64_t maximum_body_bytes,
            int64_t received_bytes,
            const char *encoding,
            size_t encoding_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    private const int BOUNDED_FEED_READ_CHUNK = 8192;

    private const string BOUNDED_FEED_FIELD_SEPARATOR = "\x1F";

    /** Shared by every subclass: only this class's methods touch the private toggle. */
    private static ?bool $boundedFeedUseRustFfi = null;

    private static FFI|false|null $boundedFeedFfi = null;

    /**
     * Wave 48.2 production default for planBoundedResponseBatch() is Rust: the paired benchmark in
     * tests/Unit/RustPredbEntryFactoryBoundedHttpUpdateLockW48ParityTest.php measures 6.29-7.08x mean and 5.67-6.62x p99 on common batches and 7.90-8.51x mean and 6.71-8.64x p99 on uncommon batches.
     * PHP stays the fallback when the library is unavailable or a header block holds a NUL byte.
     */
    public const bool W48_BOUNDED_RUST_FFI_DEFAULT = true;

    public const string W48_FFI_CDEF = <<<'C'
        ssize_t nntmux_w48_bounded_response_batch(const char *blob, size_t blob_len, const char *aux, size_t aux_len, int64_t maximum_body_bytes, int64_t reserved, char *out_buf, size_t out_cap);
        C;

    /** Largest wire cap the Wave 48.2 planner accepts, so every budget sum stays exact. */
    private const int W48_BOUNDED_MAX_SPAN = 1 << 40;

    private const array W48_BOUNDED_KEYS = ['total', 'invalid_status', 'accepted', 'rejected_encoding', 'over_limit', 'rate_limited', 'server_error',
        'client_error', 'other_status', 'status_2xx', 'status_3xx', 'status_4xx', 'status_5xx', 'gzip', 'chunked', 'unsupported_encoding',
        'declared_length', 'invalid_length', 'conflicting_length', 'max_declared_length', 'next_read_bytes', 'remaining_budget', 'retry_after',
        'max_retry_after', 'retry_after_total', 'retry_after_date', 'headers', 'malformed_headers', 'digest'];

    private static ?bool $w48BoundedRustFfiOverride = null;

    private static FFI|false|null $w48BoundedRustFfiHandle = null;

    public function __construct(
        string $endpoint,
        int $pageSize = 100,
        int $timeout = 15,
        string $userAgent = 'NNTmux-PreDB-Importer/1.0',
        protected readonly int $maximumBodyBytes = 1048576,
    ) {
        if ($maximumBodyBytes < 1 || $maximumBodyBytes >= PHP_INT_MAX || $pageSize < 1 || $timeout < 1) {
            throw new RuntimeException('Feed body, record and timeout limits must be positive.');
        }

        parent::__construct($endpoint, $pageSize, $timeout, $userAgent);
    }

    protected function downloadBody(): string
    {
        $response = $this->http()
            ->withHeaders(['Accept-Encoding' => 'gzip'])
            ->withOptions(['stream' => true, 'read_timeout' => $this->timeout, 'decode_content' => false])
            // Error summaries must not read the body outside the explicit wire byte cap.
            ->withResponseMiddleware(static fn (ResponseInterface $response): ResponseInterface => $response->withBody(new NoSeekStream($response->getBody())))
            ->retry($this->requestAttempts(), 500, static function (?Throwable $exception): bool {
                if ($exception instanceof RequestException && $exception->response->serverError()) {
                    $exception->response->toPsrResponse()->getBody()->close();

                    return true;
                }

                return $exception instanceof ConnectionException;
            }, throw: false)
            ->get($this->endpoint);
        $stream = $response->toPsrResponse()->getBody();
        $deadline = hrtime(true) / 1_000_000_000 + $this->timeout;

        try {
            $response->throw();
            if (! $response->successful()) {
                throw new RuntimeException(strtoupper($this->key()).' returned an unsuccessful listing response.');
            }

            $encoding = strtolower(trim($response->header('Content-Encoding')));
            if (! in_array($encoding, ['', 'identity', 'gzip'], true)) {
                throw new RuntimeException(strtoupper($this->key()).' returned an unsupported content encoding.');
            }

            $body = '';
            while (true) {
                $this->assertDeadline($deadline);
                $chunk = $stream->read(min(8192, $this->maximumBodyBytes + 1 - strlen($body)));
                $this->assertDeadline($deadline);
                if ($chunk === '') {
                    if (! $stream->eof()) {
                        throw new RuntimeException(strtoupper($this->key()).' response stopped before EOF.');
                    }

                    break;
                }
                $body .= $chunk;
                if (strlen($body) > $this->maximumBodyBytes) {
                    throw $this->bodyLimitException();
                }
            }

            if ($encoding === 'gzip') {
                $body = $this->decodeGzip($body, $deadline);
            }
            $this->assertDeadline($deadline);

            return $body;
        } finally {
            $stream->close();
        }
    }

    protected function requestAttempts(): int
    {
        return 2;
    }

    protected function bodyLimitException(): RuntimeException
    {
        return new RuntimeException(strtoupper($this->key()).' response exceeds the body limit.');
    }

    private function assertDeadline(float $deadline): void
    {
        if (hrtime(true) / 1_000_000_000 >= $deadline) {
            throw new RuntimeException(strtoupper($this->key()).' response exceeded the total body-read deadline.');
        }
    }

    private function decodeGzip(string $body, float $deadline): string
    {
        $decoded = '';
        $offset = 0;

        do {
            $this->assertDeadline($deadline);
            $remainingBudget = $this->maximumBodyBytes - strlen($decoded);
            $memberInput = substr($body, $offset);
            $member = @gzdecode($memberInput, $remainingBudget + 1);
            $this->assertDeadline($deadline);
            if ($member === false) {
                throw new RuntimeException(strtoupper($this->key()).' gzip response is malformed or exceeds the decoded body limit.');
            }
            if (strlen($member) > $remainingBudget) {
                throw $this->bodyLimitException();
            }

            /*
             * gzdecode can accept truncated trailers and ignores bytes after its first member. This
             * member has now been proven to fit the remaining cap, so validating it again is bounded.
             * Require a complete zlib end; any remaining wire bytes must form another valid member.
             */
            $inflater = inflate_init(ZLIB_ENCODING_GZIP);
            $verified = $inflater === false ? false : @inflate_add($inflater, $memberInput, ZLIB_FINISH);
            $this->assertDeadline($deadline);
            if ($inflater === false || $verified !== $member || inflate_get_status($inflater) !== ZLIB_STREAM_END || inflate_get_read_len($inflater) < 1) {
                throw new RuntimeException(strtoupper($this->key()).' returned malformed or incomplete gzip content.');
            }
            $offset += inflate_get_read_len($inflater);
            $decoded .= $member;
        } while ($offset < strlen($body));

        return $decoded;
    }

    public static function useRustFfi(?bool $enabled = true): void
    {
        self::$boundedFeedUseRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$boundedFeedUseRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function resetRustFfi(): void
    {
        self::$boundedFeedUseRustFfi = null;
        self::$boundedFeedFfi = null;
    }

    /**
     * Plans the next downloadBody() read: the normalized Content-Encoding and whether it is
     * accepted, the next wire read size (up to one byte past the cap), the remaining decoded
     * budget and whether the bytes received so far already exceed the cap.
     *
     * @return array{encoding: string, supported: bool, gzip: bool, limits_valid: bool, next_read: int, remaining_budget: int, over_limit: bool}
     */
    public static function planBoundedBodyRead(string $contentEncoding, int $maximumBodyBytes, int $receivedBytes): array
    {
        return self::usesRustFfi()
            ? self::planBoundedBodyReadNative($contentEncoding, $maximumBodyBytes, $receivedBytes)
            : self::planBoundedBodyReadPhp($contentEncoding, $maximumBodyBytes, $receivedBytes);
    }

    /**
     * @return array{encoding: string, supported: bool, gzip: bool, limits_valid: bool, next_read: int, remaining_budget: int, over_limit: bool}
     */
    private static function planBoundedBodyReadNative(string $contentEncoding, int $maximumBodyBytes, int $receivedBytes): array
    {
        $output = self::callBoundedFeedNative([$maximumBodyBytes, $receivedBytes, $contentEncoding, strlen($contentEncoding)], strlen($contentEncoding) + 64);
        // The encoding is the last field and may itself contain the separator.
        $fields = $output === null ? [] : explode(self::BOUNDED_FEED_FIELD_SEPARATOR, $output, 7);
        if (count($fields) !== 7) {
            return self::planBoundedBodyReadPhp($contentEncoding, $maximumBodyBytes, $receivedBytes);
        }

        return [
            'encoding' => $fields[6],
            'supported' => $fields[0] === '1',
            'gzip' => $fields[1] === '1',
            'limits_valid' => $fields[2] === '1',
            'next_read' => (int) $fields[3],
            'remaining_budget' => (int) $fields[4],
            'over_limit' => $fields[5] === '1',
        ];
    }

    /**
     * @return array{encoding: string, supported: bool, gzip: bool, limits_valid: bool, next_read: int, remaining_budget: int, over_limit: bool}
     */
    private static function planBoundedBodyReadPhp(string $contentEncoding, int $maximumBodyBytes, int $receivedBytes): array
    {
        $encoding = strtolower(trim($contentEncoding));
        $valid = $maximumBodyBytes >= 1 && $maximumBodyBytes < PHP_INT_MAX;
        $received = max(0, $receivedBytes);

        return [
            'encoding' => $encoding,
            'supported' => in_array($encoding, ['', 'identity', 'gzip'], true),
            'gzip' => $encoding === 'gzip',
            'limits_valid' => $valid,
            'next_read' => $valid ? max(0, min(self::BOUNDED_FEED_READ_CHUNK, $maximumBodyBytes + 1 - $received)) : 0,
            'remaining_budget' => $valid ? max(0, $maximumBodyBytes - $received) : 0,
            'over_limit' => $valid && $received > $maximumBodyBytes,
        ];
    }

    /** Force the Wave 48.2 response planner onto Rust (true) or PHP (false); null restores W48_BOUNDED_RUST_FFI_DEFAULT. */
    public static function useW48BoundedRustFfi(?bool $enable = true): void
    {
        self::$w48BoundedRustFfiOverride = $enable;
    }

    public static function usesW48BoundedRustFfi(): bool
    {
        return self::$w48BoundedRustFfiOverride ?? self::W48_BOUNDED_RUST_FFI_DEFAULT;
    }

    public static function resetW48BoundedRustFfi(): void
    {
        self::$w48BoundedRustFfiOverride = null;
        self::$w48BoundedRustFfiHandle = null;
    }

    /**
     * Plan how downloadBody() treats a batch of raw response header blocks ($receivedBytes[$i] wire bytes already read for
     * $headerBlocks[$i]) under a $maximumBodyBytes cap (clamped to 1..2^40): the status line, Content-Encoding support,
     * declared, invalid or conflicting Content-Length, chunked transfer, accepted bodies with their next read and
     * remaining budget, over-limit, rate-limited, server and client errors, Retry-After seconds or dates, header counts
     * and an FNV-1a digest of every response's outcome, status, flags and next read in order.
     *
     * @param  list<string>  $headerBlocks
     * @param  list<int>  $receivedBytes
     * @return array<string, int>
     *
     * @throws InvalidArgumentException when the lists differ in length
     */
    public static function planBoundedResponseBatch(array $headerBlocks, array $receivedBytes, int $maximumBodyBytes = 1048576): array
    {
        return (self::usesW48BoundedRustFfi() ? self::planBoundedResponseBatchNative($headerBlocks, $receivedBytes, $maximumBodyBytes) : null)
            ?? self::planBoundedResponseBatchPhp($headerBlocks, $receivedBytes, $maximumBodyBytes);
    }

    /**
     * @param  list<string>  $headerBlocks
     * @param  list<int>  $receivedBytes
     * @return array<string, int>
     */
    public static function planBoundedResponseBatchPhp(array $headerBlocks, array $receivedBytes, int $maximumBodyBytes = 1048576): array
    {
        self::w48BoundedAssertSameLength(\count($headerBlocks), $receivedBytes);
        $receivedBytes = array_values($receivedBytes);
        $maximum = max(1, min(self::W48_BOUNDED_MAX_SPAN, $maximumBodyBytes));
        $c = array_fill(0, \count(self::W48_BOUNDED_KEYS), 0);
        $c[19] = $c[23] = -1;
        $stream = '';
        foreach (array_values($headerBlocks) as $i => $block) {
            $c[0]++;
            $lines = explode("\n", $block);
            if (preg_match('/^HTTP\/[0-9.]+ ([1-5][0-9]{2})(?: |$)/D', rtrim($lines[0], "\r"), $match) !== 1) {
                $c[1]++;
                $stream .= pack('Cx11', 0);

                continue;
            }
            $status = (int) $match[1];
            $encoding = '';
            $length = $retry = -1;
            $lengthInvalid = $conflict = $chunked = $retryDate = false;
            for ($l = 1, $n = \count($lines); $l < $n; $l++) {
                $line = rtrim($lines[$l], "\r");
                if ($line === '') {
                    continue;
                }
                $colon = strpos($line, ':');
                $name = $colon === false ? '' : trim(substr($line, 0, $colon), " \t");
                if ($name === '') {
                    $c[27]++;

                    continue;
                }
                $c[26]++;
                $value = trim(substr($line, $colon + 1), " \t");
                switch (strtolower($name)) {
                    case 'content-encoding':
                        $encoding = $value;
                        break;
                    case 'content-length':
                        $size = \strlen($value);
                        if ($size > 0 && $size <= 15 && strspn($value, '0123456789') === $size) {
                            if ($length >= 0 && $length !== (int) $value) {
                                $conflict = true;
                            }
                            $length = (int) $value;
                        } else {
                            $lengthInvalid = true;
                        }
                        break;
                    case 'transfer-encoding':
                        $chunked = \strlen($value) >= 7 && strcasecmp(substr($value, -7), 'chunked') === 0;
                        break;
                    case 'retry-after':
                        $size = \strlen($value);
                        if ($size > 0 && $size <= 9 && strspn($value, '0123456789') === $size) {
                            $retry = (int) $value;
                            $retryDate = false;
                        } elseif ($size > 0) {
                            $retry = -1;
                            $retryDate = true;
                        }
                        break;
                }
            }
            $received = max(0, $receivedBytes[$i]);
            $declared = $chunked || $lengthInvalid || $conflict ? -1 : $length;
            $gzip = strcasecmp($encoding, 'gzip') === 0;
            $supported = $encoding === '' || $gzip || strcasecmp($encoding, 'identity') === 0;
            $flags = ($gzip ? 1 : 0) | ($chunked ? 2 : 0) | ($declared >= 0 ? 4 : 0) | ($lengthInvalid ? 8 : 0) | ($conflict ? 16 : 0)
                | ($retry >= 0 ? 32 : 0) | ($retryDate ? 64 : 0) | ($supported ? 0 : 128);
            $c[13] += $gzip ? 1 : 0;
            $c[14] += $chunked ? 1 : 0;
            $c[15] += $supported ? 0 : 1;
            if ($declared >= 0) {
                $c[16]++;
                $c[19] = max($c[19], $declared);
            }
            $c[17] += $lengthInvalid ? 1 : 0;
            $c[18] += $conflict ? 1 : 0;
            if ($status >= 200) {
                $c[9 + intdiv($status, 100) - 2]++;
            }
            $next = 0;
            if ($status < 300 && $status >= 200) {
                if (! $supported) {
                    $outcome = 2;
                } elseif ($received > $maximum || $declared > $maximum) {
                    $outcome = 3;
                } else {
                    $outcome = 1;
                    $next = max(0, min(8192, $maximum + 1 - $received));
                    $c[20] += $next;
                    $c[21] += $maximum - $received;
                }
            } else {
                $outcome = $status === 429 ? 4 : ($status >= 500 ? 5 : ($status >= 400 ? 6 : 7));
            }
            $c[$outcome === 1 ? 2 : $outcome + 1]++;
            if ($outcome === 4 || $outcome === 5) {
                if ($retry >= 0) {
                    $c[22]++;
                    $c[23] = max($c[23], $retry);
                    $c[24] += $retry;
                } elseif ($retryDate) {
                    $c[25]++;
                }
            }
            $stream .= pack('CvCP', $outcome, $status, $flags, $next);
        }
        $c[28] = PredbFeedEntry::w48Fnv($stream);

        return array_combine(self::W48_BOUNDED_KEYS, $c);
    }

    /**
     * Null when the library is missing, a header block holds a NUL byte or the batch exceeds the native limits.
     *
     * @param  list<string>  $headerBlocks
     * @param  list<int>  $receivedBytes
     * @return array<string, int>|null
     */
    public static function planBoundedResponseBatchNative(array $headerBlocks, array $receivedBytes, int $maximumBodyBytes = 1048576): ?array
    {
        $count = \count($headerBlocks);
        self::w48BoundedAssertSameLength($count, $receivedBytes);
        $ffi = self::w48BoundedRustFfi();
        if ($ffi === null || $count > PredbFeedEntry::W48_MAX_ITEMS) {
            return null;
        }
        $values = PredbFeedEntry::callW48Native($ffi, 'nntmux_w48_bounded_response_batch', implode("\0", $headerBlocks),
            pack('q*', ...array_values($receivedBytes)), $maximumBodyBytes, 0, \count(self::W48_BOUNDED_KEYS));
        if ($values === null || $values[0] !== $count || array_sum(\array_slice($values, 1, 8)) !== $count) {
            return null;
        }

        return array_combine(self::W48_BOUNDED_KEYS, $values);
    }

    /**
     * @param  array<array-key, mixed>  $list
     *
     * @throws InvalidArgumentException
     */
    private static function w48BoundedAssertSameLength(int $count, array $list): void
    {
        if (\count($list) !== $count) {
            throw new InvalidArgumentException('Wave 48.2 batch lists must have the same length.');
        }
    }

    private static function w48BoundedRustFfi(): ?FFI
    {
        self::$w48BoundedRustFfiHandle ??= RustPipelineLibrary::resolveDomain(PredbFeedEntry::W48_FFI_DOMAIN, self::W48_FFI_CDEF) ?? false;

        return self::$w48BoundedRustFfiHandle === false ? null : self::$w48BoundedRustFfiHandle;
    }

    /**
     * @param  list<int|string>  $args
     */
    private static function callBoundedFeedNative(array $args, int $hint): ?string
    {
        self::$boundedFeedFfi ??= RustPipelineLibrary::resolveDomain('metadata_w374', self::BOUNDED_FEED_FFI_CDEF) ?? false;
        if (self::$boundedFeedFfi === false) {
            return null;
        }
        $ffi = self::$boundedFeedFfi;

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $hint, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w374_bounded_body_read_plan(...[...$args, $buffer, $capacity]);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w374_bounded_body_read_plan(...[...$args, $buffer, $capacity]);
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
