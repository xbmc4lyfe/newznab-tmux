<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\NNTP\NNTPService;
use App\Services\YencService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class NNTPServiceReadBodyTest extends TestCase
{
    /** @return iterable<string, array{mixed, string}> */
    public static function messageIdentifiers(): iterable
    {
        yield 'scalar Message-ID' => ['test@example', 'BODY <test@example>'];
        yield 'scalar article number' => [42, 'BODY 42'];
        yield 'numeric string' => ['42', 'BODY 42'];
        yield 'array Message-ID' => [['test@example'], 'BODY <test@example>'];
    }

    #[DataProvider('messageIdentifiers')]
    public function test_get_messages_refreshes_ownership_and_preserves_body_framing(mixed $identifier, string $command): void
    {
        [$reader, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_timeout($reader, 1);
        stream_set_timeout($writer, 1);
        $connection = $this->messageConnection($reader);
        unset($reader);
        $prefix = str_repeat('x', 1024);
        fwrite($writer, "222 body follows\r\n".$prefix."tail\r\n..dot\r\n.\r\nNEXT\r\n");
        $reads = 0;

        try {
            $result = $connection->getMessages('alt.test', $identifier, false, static function () use (&$reads): bool {
                $reads++;

                return true;
            });

            $this->assertSame($prefix."tail\r\n.dot\r\n", $result);
            $this->assertSame(3, $reads);
            $this->assertSame($command."\r\n", fgets($writer));
            $this->assertSame("NEXT\r\n", $connection->nextLine());
        } finally {
            $connection->doQuit();
            fclose($writer);
        }
    }

    #[DataProvider('messageIdentifiers')]
    public function test_get_messages_stops_without_returning_a_prefix_when_ownership_is_lost(mixed $identifier, string $command): void
    {
        [$reader, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_timeout($reader, 1);
        stream_set_timeout($writer, 1);
        $connection = $this->messageConnection($reader);
        unset($reader);
        fwrite($writer, "222 body follows\r\naccepted\r\nremaining\r\n.\r\n");
        $reads = 0;

        try {
            $result = $connection->getMessages('alt.test', $identifier, false, static function () use (&$reads): bool {
                return ++$reads < 2;
            });

            $this->assertTrue(NNTPService::isError($result));
            $this->assertStringContainsString('processing lock ownership was lost', $result->getMessage());
            $this->assertSame(2, $reads);
            $this->assertSame($command."\r\n", fgets($writer));
            $this->assertSame('', stream_get_contents($writer));
            $this->assertTrue(feof($writer));
        } finally {
            $connection->doQuit();
            fclose($writer);
        }
    }

    /** @return iterable<string, array{mixed, string, bool}> */
    public static function alternateMessageIdentifiers(): iterable
    {
        foreach (self::messageIdentifiers() as $name => [$identifier, $command]) {
            yield $name.' completes' => [$identifier, $command, false];
            yield $name.' cancels' => [$identifier, $command, true];
        }
    }

    #[DataProvider('alternateMessageIdentifiers')]
    public function test_get_messages_keeps_ownership_checks_on_the_alternate_provider(mixed $identifier, string $command, bool $cancel): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('The bounded loopback NNTP fixture requires pcntl.');
        }
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorString);
        $this->assertNotFalse($server, $errorString);
        $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($parent);
            pcntl_alarm(5);
            $socket = stream_socket_accept($server, 4);
            fclose($server);
            if ($socket === false) {
                exit(1);
            }
            stream_set_timeout($socket, 4);
            fwrite($socket, "200 fixture ready\r\n211 1 42 42 alt.test\r\n222 body follows\r\naccepted\r\n..dot\r\n.\r\n");
            $commands = [];
            while (($line = @fgets($socket)) !== false) {
                $commands[] = rtrim($line, "\r\n");
            }
            $closed = feof($socket);
            fclose($socket);
            fwrite($child, json_encode(['commands' => $commands, 'closed' => $closed], JSON_THROW_ON_ERROR));
            fclose($child);
            exit($closed ? 0 : 2);
        }
        fclose($child);
        fclose($server);
        stream_set_timeout($parent, 5);
        config([
            'nntmux.echocli' => false,
            'nntmux_nntp.server' => '127.0.0.1',
            'nntmux_nntp.alternate_server' => '127.0.0.1',
            'nntmux_nntp.alternate_server_port' => $port,
            'nntmux_nntp.alternate_server_ssl' => false,
            'nntmux_nntp.alternate_server_username' => '',
            'nntmux_nntp.alternate_server_password' => '',
            'nntmux_nntp.alternate_server_socket_timeout' => 1,
            'nntmux_nntp.compressed_headers' => false,
        ]);
        $this->app->instance(YencService::class, new YencService);
        [$reader, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_timeout($reader, 1);
        stream_set_timeout($writer, 1);
        $connection = $this->messageConnection($reader);
        unset($reader);
        fwrite($writer, "430 article unavailable\r\n");
        $reads = 0;

        try {
            $result = $connection->getMessages('alt.test', $identifier, true, static function () use (&$reads, $cancel): bool {
                return ++$reads < 2 || ! $cancel;
            });

            if ($cancel) {
                $this->assertTrue(NNTPService::isError($result));
                $this->assertStringContainsString('processing lock ownership was lost', $result->getMessage());
                $this->assertSame(2, $reads);
            } else {
                $this->assertSame("accepted\r\n.dot\r\n", $result);
                $this->assertSame(3, $reads);
            }
            $this->assertSame($command."\r\n", fgets($writer));
            $fixture = json_decode(stream_get_contents($parent), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(['GROUP alt.test', $command], $fixture['commands']);
            $this->assertTrue($fixture['closed']);
            $this->assertSame($pid, pcntl_waitpid($pid, $status));
            $pid = 0;
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
        } finally {
            $connection->doQuit();
            fclose($writer);
            fclose($parent);
            if ($pid > 0 && pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }
        }
    }

    public function test_raw_article_reader_runs_heartbeat_while_receiving_body_lines(): void
    {
        [$reader, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($writer, "first\r\nsecond\r\n.\r\n");
        $connection = new class($reader) extends NNTPService
        {
            public function __construct($socket)
            {
                $this->_socket = $socket;
            }

            public function read(callable $heartbeat): mixed
            {
                return $this->readRawBody($heartbeat);
            }
        };
        $heartbeats = 0;

        $body = $connection->read(function () use (&$heartbeats): bool {
            $heartbeats++;

            return true;
        });

        fclose($reader);
        fclose($writer);
        $this->assertSame("first\r\nsecond\r\n", $body);
        $this->assertSame(3, $heartbeats);
    }

    public function test_raw_article_reader_stops_when_heartbeat_reports_lost_ownership(): void
    {
        [$reader, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($writer, "first\r\nsecond\r\n.\r\n");
        $connection = new class($reader) extends NNTPService
        {
            public function __construct($socket)
            {
                $this->_socket = $socket;
            }

            public function read(callable $heartbeat): mixed
            {
                return $this->readRawBody($heartbeat);
            }

            public function doQuit(bool $force = false): mixed
            {
                return true;
            }
        };

        $result = $connection->read(static fn (): bool => false);

        fclose($reader);
        fclose($writer);
        $this->assertTrue(NNTPService::isError($result));
    }

    /** @param resource $socket */
    private function messageConnection($socket): NNTPService
    {
        return new class($socket) extends NNTPService
        {
            /** @param resource $socket */
            public function __construct($socket)
            {
                $this->_socket = $socket;
                $this->_currentGroup = 'alt.test';
                $this->_selectedGroupSummary = ['group' => 'alt.test', 'count' => 1, 'first' => 42, 'last' => 42];
                $this->_currentServer = $this->_configServer = '127.0.0.1';
                $this->_configCompressedHeaders = false;
                $this->_yencService = new YencService;
            }

            public function nextLine(): string|false
            {
                return fgets($this->_socket);
            }
        };
    }
}
