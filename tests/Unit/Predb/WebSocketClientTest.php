<?php

declare(strict_types=1);

namespace Tests\Unit\Predb;

use App\Services\Predb\Stream\WebSocketClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class WebSocketClientTest extends TestCase
{
    /**
     * @return iterable<string, array{int}>
     */
    public static function payloadSizes(): iterable
    {
        yield 'empty' => [0];
        yield 'small' => [125];
        yield '16-bit length' => [300];
        yield '64-bit length' => [70000];
    }

    #[Test]
    #[DataProvider('payloadSizes')]
    public function masked_client_frames_round_trip(int $size): void
    {
        $payload = str_repeat('{"a":1}', intdiv($size, 7) + 1);
        $payload = substr($payload, 0, $size);

        $frame = WebSocketClient::encodeFrame(0x1, $payload, "\x01\x02\x03\x04");
        $decoded = WebSocketClient::decodeFrame($frame.'trailing');

        $this->assertNotNull($decoded);
        [$fin, $opcode, $body, $consumed] = $decoded;
        $this->assertTrue($fin);
        $this->assertSame(0x1, $opcode);
        $this->assertSame($payload, $body);
        $this->assertSame(strlen($frame), $consumed);
    }

    #[Test]
    public function a_64_bit_length_with_the_reserved_high_bit_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('reserved high bit');

        WebSocketClient::decodeFrame("\x81\x7F\x80\x00\x00\x00\x00\x00\x00\x05hello");
    }

    #[Test]
    public function the_host_header_names_non_default_ports(): void
    {
        $this->assertSame('predb.club', WebSocketClient::authority('predb.club', 443, true));
        $this->assertSame('predb.club:8443', WebSocketClient::authority('predb.club', 8443, true));
        $this->assertSame('localhost', WebSocketClient::authority('localhost', 80, false));
        $this->assertSame('localhost:9000', WebSocketClient::authority('localhost', 9000, false));
        // parse_url() keeps IPv6 brackets, so the socket address and Host header are both bracketed.
        $this->assertSame('[::1]', parse_url('ws://[::1]:9000/ws', PHP_URL_HOST));
        $this->assertSame('[::1]:9000', WebSocketClient::authority('[::1]', 9000, false));
    }

    #[Test]
    public function a_fragmented_message_survives_an_idle_timeout_between_fragments(): void
    {
        [$client, $server] = $this->connectedPair();

        // Server frames are unmasked: first fragment (FIN=0, text), then the final continuation (FIN=1).
        fwrite($server, "\x01\x05{\"a\":");
        $this->assertNull($client->receive(1));

        fwrite($server, "\x80\x021}");
        $this->assertSame('{"a":1}', $client->receive(1));
    }

    #[Test]
    public function an_oversized_fragmented_message_closes_the_connection(): void
    {
        [$client, $server] = $this->connectedPair(maxMessageBytes: 8);

        fwrite($server, "\x01\x05aaaaa\x00\x05bbbbb");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exceeds 8 bytes');

        $client->receive(1);
    }

    #[Test]
    public function unmasked_server_frames_decode_and_partial_frames_wait_for_more_bytes(): void
    {
        // predb.club's heartbeat: an unmasked ping frame carrying "hb".
        $ping = "\x89\x02hb";

        $this->assertSame([true, 0x9, 'hb', 4], WebSocketClient::decodeFrame($ping));
        $this->assertNull(WebSocketClient::decodeFrame("\x89\x02h"));
        $this->assertNull(WebSocketClient::decodeFrame("\x81"));
    }

    /**
     * A client wired to one end of a local socket pair, skipping the HTTP handshake.
     *
     * @return array{0: WebSocketClient, 1: resource}
     */
    private function connectedPair(int $maxMessageBytes = 4 * 1024 * 1024): array
    {
        [$clientEnd, $serverEnd] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_timeout($clientEnd, 1);

        $client = new WebSocketClient('ws://localhost/ws', timeout: 1, maxMessageBytes: $maxMessageBytes);
        (new ReflectionProperty(WebSocketClient::class, 'stream'))->setValue($client, $clientEnd);

        return [$client, $serverEnd];
    }
}
