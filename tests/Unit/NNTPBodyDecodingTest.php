<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\NNTP\NNTPService;
use App\Services\YencService;
use DariusIII\NetNntp\Error as NntpError;
use Illuminate\Container\Container;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Tests\Fixtures\YencArticles;
use Tests\Support\YencNntpHarness;

final class NNTPBodyDecodingTest extends TestCase
{
    public function test_decoder_is_resolved_once_when_a_body_is_read(): void
    {
        $previous = Container::getInstance();
        $container = new Container;
        $resolutions = 0;
        $container->bind(YencService::class, function () use (&$resolutions): YencService {
            $resolutions++;

            return new YencService;
        });
        Container::setInstance($container);
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, YencArticles::article('ABC')."\r\n.\r\n");
        rewind($stream);
        try {
            $harness = new YencNntpHarness($stream);
            $this->assertSame(0, $resolutions);
            $this->assertSame('ABC', $harness->fetch());
            rewind($stream);
            $this->assertSame('ABC', $harness->fetch());
            $this->assertSame(1, $resolutions);
        } finally {
            Container::setInstance($previous);
            fclose($stream);
        }
    }

    public function test_required_decoder_failure_is_not_hidden_when_reading_a_body(): void
    {
        $previous = Container::getInstance();
        $container = new Container;
        $container->bind(YencService::class, function (): never {
            throw new RuntimeException('Native yEnc is unavailable');
        });
        Container::setInstance($container);
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, YencArticles::article('ABC')."\r\n.\r\n");
        rewind($stream);
        try {
            $harness = new YencNntpHarness($stream);
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Native yEnc is unavailable');
            $harness->fetch();
        } finally {
            Container::setInstance($previous);
            fclose($stream);
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function entryPaths(): iterable
    {
        yield 'group BODY' => [true];
        yield 'message ID BODY' => [false];
    }

    #[DataProvider('entryPaths')]
    public function test_unstuffs_once_and_leaves_the_next_response_unread(bool $withGroup): void
    {
        $data = str_repeat("\x04", 260).YencArticles::bytes();
        $article = YencArticles::article($data);
        $wire = preg_replace('/^\./m', '..', $article)."\r\n.\r\nNEXT\r\n";
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, $wire);
        rewind($stream);
        try {
            $harness = new YencNntpHarness($stream, new YencService);
            $this->assertSame($data, $harness->fetch($withGroup));
            $this->assertSame(['BODY <test@example>'], $harness->commands);
            $this->assertSame("NEXT\r\n", fgets($stream));
        } finally {
            fclose($stream);
        }
    }

    #[DataProvider('entryPaths')]
    public function test_long_fragmented_plain_text_lines_are_not_unstuffed_mid_line(bool $withGroup): void
    {
        $data = str_repeat('x', 8191)."..continued\r\n.leading dot\r\n";
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, str_repeat('x', 8191)."..continued\r\n..leading dot\r\n.\r\nNEXT\r\n");
        rewind($stream);
        try {
            $this->assertSame($data, (new YencNntpHarness($stream, new YencService))->fetch($withGroup));
            $this->assertSame("NEXT\r\n", fgets($stream));
        } finally {
            fclose($stream);
        }
    }

    #[DataProvider('entryPaths')]
    public function test_bad_checksum_remains_tolerant(bool $withGroup): void
    {
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, "=ybegin line=128 size=9 name=x\r\nklm=\r\n=yend size=3 crc32=deadbeef\r\n.\r\n");
        rewind($stream);
        try {
            $this->assertSame('ABC', (new YencNntpHarness($stream, new YencService))->fetch($withGroup));
        } finally {
            fclose($stream);
        }
    }

    #[DataProvider('entryPaths')]
    public function test_connection_loss_returns_transport_error(bool $withGroup): void
    {
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, "partial body\r\n");
        rewind($stream);
        try {
            $this->assertInstanceOf(NntpError::class, (new YencNntpHarness($stream, new YencService))->fetch($withGroup));
        } finally {
            fclose($stream);
        }
    }

    /** @return iterable<string, array{string, bool, int}> */
    public static function incompleteRawBodies(): iterable
    {
        yield 'first read reaches EOF' => ['', false, 0];
        yield 'stream is already at EOF' => ['', true, 0];
        yield 'unterminated partial line' => ['partial body', false, 1];
        yield 'complete line without DOT terminator' => ["partial body\r\n", false, 1];
        yield 'bare LF DOT is not a terminator' => ["partial body\r\n.\n", false, 2];
    }

    #[DataProvider('incompleteRawBodies')]
    public function test_incomplete_raw_body_returns_an_error_instead_of_partial_payload(string $wire, bool $alreadyAtEof, int $expectedReads): void
    {
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, $wire);
        rewind($stream);
        $reads = 0;
        try {
            if ($alreadyAtEof) {
                self::assertFalse(fgets($stream));
                self::assertTrue(feof($stream));
            }
            $result = (new ReflectionMethod(NNTPService::class, 'readRawBody'))->invoke(
                new YencNntpHarness($stream),
                static function () use (&$reads): bool {
                    $reads++;

                    return true;
                },
            );

            self::assertInstanceOf(NntpError::class, $result);
            self::assertSame($expectedReads, $reads);
        } finally {
            fclose($stream);
        }
    }

    public function test_empty_raw_body_leaves_the_next_response_unread(): void
    {
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, ".\r\nNEXT\r\n");
        rewind($stream);
        $reads = 0;
        try {
            $result = (new ReflectionMethod(NNTPService::class, 'readRawBody'))->invoke(
                new YencNntpHarness($stream),
                static function () use (&$reads): bool {
                    $reads++;

                    return true;
                },
            );

            self::assertSame('', $result);
            self::assertSame(1, $reads);
            self::assertSame("NEXT\r\n", fgets($stream));
        } finally {
            fclose($stream);
        }
    }

    public function test_non_eof_read_failure_returns_an_error_instead_of_partial_payload(): void
    {
        $protocol = 'nntpbodyreadfailure';
        self::assertTrue(stream_wrapper_register($protocol, NNTPBodyReadFailureStream::class));
        $stream = null;
        try {
            $stream = fopen($protocol.'://body', 'r');
            self::assertIsResource($stream);
            $reads = 0;
            $result = (new ReflectionMethod(NNTPService::class, 'readRawBody'))->invoke(
                new YencNntpHarness($stream),
                static function () use (&$reads): bool {
                    $reads++;

                    return true;
                },
            );

            self::assertInstanceOf(NntpError::class, $result);
            self::assertSame('Failed to read line from socket.', $result->getMessage());
            self::assertSame(1, $reads);
            self::assertFalse(feof($stream));
            self::assertFalse(fgets($stream));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            stream_wrapper_unregister($protocol);
        }
    }

    public function test_ownership_cancellation_closes_the_session_without_returning_partial_body(): void
    {
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, "accepted line\r\n".str_repeat('x', 8191)."tail\r\n.\r\nNEXT\r\n");
        rewind($stream);
        $harness = new class($stream) extends NNTPService
        {
            public bool $quitCalled = false;

            /** @param resource $stream */
            public function __construct($stream)
            {
                $this->_socket = $stream;
            }

            public function doQuit(bool $force = false): mixed
            {
                $this->quitCalled = true;
                if (is_resource($this->_socket)) {
                    fclose($this->_socket);
                }
                $this->_socket = null;

                return true;
            }
        };
        $reads = 0;
        try {
            $result = (new ReflectionMethod(NNTPService::class, 'readRawBody'))->invoke(
                $harness,
                static function () use (&$reads): bool {
                    return ++$reads < 2;
                },
            );

            self::assertInstanceOf(NntpError::class, $result);
            self::assertStringContainsString('processing lock ownership was lost', $result->getMessage());
            self::assertSame(2, $reads);
            self::assertTrue($harness->quitCalled);
            self::assertFalse(is_resource($stream));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}

final class NNTPBodyReadFailureStream
{
    public mixed $context;

    private string $body = "partial body\r\n";

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): string|false
    {
        if ($this->body === '') {
            return false;
        }
        $fragment = substr($this->body, 0, $count);
        $this->body = substr($this->body, strlen($fragment));

        return $fragment;
    }

    public function stream_eof(): bool
    {
        return false;
    }
}
