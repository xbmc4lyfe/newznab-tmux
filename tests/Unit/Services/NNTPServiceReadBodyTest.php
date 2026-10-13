<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\NNTP\NNTPService;
use Tests\TestCase;

final class NNTPServiceReadBodyTest extends TestCase
{
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
}
