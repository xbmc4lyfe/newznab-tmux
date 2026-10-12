<?php

declare(strict_types=1);

namespace Tests\Unit\Predb;

use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Services\Predb\Feeds\Sources\BoundedHttpFeedSource;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class BoundedHttpFeedSourceTest extends TestCase
{
    protected function tearDown(): void
    {
        Http::clearResolvedInstance('http');
        parent::tearDown();
    }

    #[Test]
    #[DataProvider('positiveLimits')]
    public function invalid_body_or_record_limits_are_rejected(int $maximumBodyBytes, int $pageSize): void
    {
        $this->expectException(RuntimeException::class);
        $this->source($maximumBodyBytes, pageSize: $pageSize);
    }

    /** @return array<string, array{int, int}> */
    public static function positiveLimits(): array
    {
        return ['zero bytes' => [0, 1], 'negative bytes' => [-1, 1], 'overflowing bytes' => [PHP_INT_MAX, 1], 'zero records' => [100, 0], 'negative records' => [100, -1]];
    }

    #[Test]
    public function identity_wire_limit_allows_exact_bytes_and_short_reads(): void
    {
        $stream = $this->stream('exact', chunkSize: 2);
        $this->respond($stream, ['Content-Encoding' => 'identity', 'Content-Length' => '1']);

        $this->assertSame('exact', $this->source(5)->fetch()[0]->title);
        $this->assertSame(5, $stream->bytesRead);
        $this->assertTrue($stream->closed);
    }

    #[Test]
    #[DataProvider('wireEncodings')]
    public function raw_download_stops_at_cap_plus_one_for_identity_and_gzip(string $encoding): void
    {
        $stream = $this->stream(str_repeat('x', 1000));
        $this->respond($stream, ['Content-Encoding' => $encoding, 'Content-Length' => '1']);

        try {
            $this->source(50)->fetch();
            $this->fail('Oversized wire data must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('body limit', $exception->getMessage());
            $this->assertSame(51, $stream->bytesRead);
            $this->assertTrue($stream->closed);
        }
    }

    /** @return array<string, array{string}> */
    public static function wireEncodings(): array
    {
        return ['identity' => ['identity'], 'gzip' => ['gzip']];
    }

    #[Test]
    public function actual_gzip_decodes_a_payload_at_the_decoded_limit(): void
    {
        $stream = $this->stream(gzencode(str_repeat('a', 200)), chunkSize: 3);
        $this->respond($stream, ['Content-Encoding' => 'gzip']);

        $this->assertSame(str_repeat('a', 200), $this->source(200)->fetch()[0]->title);
        $this->assertTrue($stream->closed);
    }

    #[Test]
    public function complete_concatenated_gzip_members_share_the_decoded_budget(): void
    {
        $stream = $this->stream(gzencode(str_repeat('a', 100)).gzencode(str_repeat('b', 100)));
        $this->respond($stream, ['Content-Encoding' => 'gzip']);

        $this->assertSame(str_repeat('a', 100).str_repeat('b', 100), $this->source(200)->fetch()[0]->title);
        $this->assertTrue($stream->closed);
    }

    #[Test]
    public function concatenated_gzip_members_cannot_exceed_the_aggregate_decoded_budget(): void
    {
        $stream = $this->stream(gzencode(str_repeat('a', 100)).gzencode(str_repeat('b', 101)));
        $this->respond($stream, ['Content-Encoding' => 'gzip']);

        try {
            $this->source(200)->fetch();
            $this->fail('Member sizes must be checked against their aggregate decoded limit.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('body limit', $exception->getMessage());
            $this->assertTrue($stream->closed);
        }
    }

    #[Test]
    #[DataProvider('decodedLengths')]
    public function compressed_payload_cannot_expand_beyond_the_decoded_limit(int $decodedLength): void
    {
        $wire = gzencode(str_repeat('a', $decodedLength));
        $this->assertLessThan(200, strlen($wire));
        $stream = $this->stream($wire);
        $this->respond($stream, ['Content-Encoding' => 'gzip']);

        try {
            $this->source(200)->fetch();
            $this->fail('An oversized decoded response must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('body limit', $exception->getMessage());
            $this->assertTrue($stream->closed);
        }
    }

    /** @return array<string, array{int}> */
    public static function decodedLengths(): array
    {
        return ['cap plus one' => [201], 'compression bomb' => [10000]];
    }

    #[Test]
    #[DataProvider('badCompressedBodies')]
    public function malformed_gzip_is_rejected_without_leaving_the_stream_open(string $body): void
    {
        $stream = $this->stream($body);
        $this->respond($stream, ['Content-Encoding' => 'gzip']);

        try {
            $this->source(200)->fetch();
            $this->fail('Malformed or incomplete gzip must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('gzip', $exception->getMessage());
            $this->assertTrue($stream->closed);
        }
    }

    /** @return array<string, array{string}> */
    public static function badCompressedBodies(): array
    {
        $gzip = gzencode(str_repeat('a', 100));

        return ['not gzip' => ['ordinary text'], 'empty gzip' => [''], 'truncated trailer' => [substr($gzip, 0, -1)], 'trailing garbage' => [$gzip.'garbage'], 'truncated second member' => [$gzip.substr(gzencode('second'), 0, -1)]];
    }

    #[Test]
    #[DataProvider('unsupportedEncodings')]
    public function unsupported_or_stacked_encodings_are_rejected_before_reading(string $encoding): void
    {
        $stream = $this->stream('not decoded');
        $this->respond($stream, ['Content-Encoding' => $encoding]);

        try {
            $this->source()->fetch();
            $this->fail('Unsupported content encoding must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('encoding', $exception->getMessage());
            $this->assertSame(0, $stream->bytesRead);
            $this->assertTrue($stream->closed);
        }
    }

    /** @return array<string, array{string}> */
    public static function unsupportedEncodings(): array
    {
        return ['brotli' => ['br'], 'deflate' => ['deflate'], 'stacked gzip' => ['gzip, gzip'], 'stacked identity' => ['identity, gzip']];
    }

    #[Test]
    public function repeated_short_reads_cannot_extend_the_total_body_deadline(): void
    {
        $stream = $this->stream('eventually complete', chunkSize: 1, readDelayMicroseconds: 600000);
        $this->respond($stream, timeout: 1);

        try {
            $this->source(timeout: 1)->fetch();
            $this->fail('The total body-read deadline must be enforced.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('deadline', $exception->getMessage());
            $this->assertLessThanOrEqual(2, $stream->bytesRead);
            $this->assertTrue($stream->closed);
        }
    }

    #[Test]
    public function a_source_can_disable_fast_retries_after_a_server_failure(): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(['https://fixture.example/feed/' => Http::sequence()->push('unavailable', 503)->push('should not be requested', 200)]);

        try {
            $this->source(attempts: 1)->fetch();
            $this->fail('A source with one allowed attempt must propagate the failure.');
        } catch (RequestException $exception) {
            $this->assertSame(503, $exception->response->status());
            Http::assertSentCount(1);
        }
    }

    private function source(int $maximumBodyBytes = 1048576, int $timeout = 15, int $pageSize = 100, int $attempts = 2): BoundedHttpFeedSource
    {
        return new class('https://fixture.example/feed/', $pageSize, $timeout, $maximumBodyBytes, $attempts) extends BoundedHttpFeedSource
        {
            public function __construct(string $endpoint, int $pageSize, int $timeout, int $maximumBodyBytes, private readonly int $attempts)
            {
                parent::__construct($endpoint, $pageSize, $timeout, maximumBodyBytes: $maximumBodyBytes);
            }

            protected function requestAttempts(): int
            {
                return $this->attempts;
            }

            public function key(): string
            {
                return 'fixture';
            }

            public function fetch(int $page = 1): array
            {
                return [new PredbFeedEntry($this->downloadBody(), 'fixture')];
            }
        };
    }

    /** @param array<string, string> $headers */
    private function respond(StreamInterface $stream, array $headers = [], int $timeout = 15): void
    {
        $factory = (new Factory)->preventStrayRequests();
        $factory->fake(['https://fixture.example/feed/' => static function (Request $request, array $options) use ($stream, $headers, $timeout) {
            if (($options['stream'] ?? null) !== true || ($options['decode_content'] ?? null) !== false || ($options['read_timeout'] ?? null) !== $timeout || ! $request->hasHeader('Accept-Encoding', 'gzip')) {
                throw new RuntimeException('Wire content must arrive as a bounded raw stream.');
            }

            return Create::promiseFor(new Response(200, $headers, $stream));
        }]);
        Http::swap($factory);
    }

    private function stream(string $body, int $chunkSize = 8192, int $readDelayMicroseconds = 0): StreamInterface
    {
        return new class(Utils::streamFor($body), $chunkSize, $readDelayMicroseconds) implements StreamInterface
        {
            use StreamDecoratorTrait;

            public int $bytesRead = 0;

            public bool $closed = false;

            public function __construct(private StreamInterface $stream, private readonly int $chunkSize, private readonly int $readDelayMicroseconds) {}

            public function __toString(): string
            {
                throw new RuntimeException('Whole-body reads are forbidden.');
            }

            public function getContents(): string
            {
                throw new RuntimeException('Whole-body reads are forbidden.');
            }

            public function read(int $length): string
            {
                if ($this->readDelayMicroseconds > 0) {
                    usleep($this->readDelayMicroseconds);
                }
                $result = $this->stream->read(min($length, $this->chunkSize));
                $this->bytesRead += strlen($result);

                return $result;
            }

            public function close(): void
            {
                $this->closed = true;
                $this->stream->close();
            }
        };
    }
}
