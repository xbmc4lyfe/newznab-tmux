<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Support\RustPipelineLibrary;
use FFI;
use GuzzleHttp\Psr7\NoSeekStream;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
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
