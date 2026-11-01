<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

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
}
