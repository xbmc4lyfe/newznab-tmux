<?php

declare(strict_types=1);

namespace App\Services\Predb\Stream;

use RuntimeException;

/**
 * Minimal RFC 6455 WebSocket client for reading JSON push feeds.
 *
 * Supports ws:// and wss://, text and binary frames, fragmentation, ping/pong and close. Client frames
 * are masked as the RFC requires. It is a reader: send() exists for pongs and an optional subscribe message.
 */
class WebSocketClient
{
    private const OP_CONTINUATION = 0x0;

    private const OP_TEXT = 0x1;

    private const OP_BINARY = 0x2;

    private const OP_CLOSE = 0x8;

    private const OP_PING = 0x9;

    private const OP_PONG = 0xA;

    /**
     * Refuse frames larger than this (the feeds send small JSON rows).
     */
    private const MAX_FRAME_BYTES = 4 * 1024 * 1024;

    /** @var resource|null */
    private $stream = null;

    private string $buffer = '';

    private int $lastFrameAt = 0;

    public function __construct(
        private readonly string $url,
        private readonly int $timeout = 15,
        private readonly string $userAgent = 'NNTmux-PreDB-Importer/1.0',
    ) {}

    public function connect(): void
    {
        $parts = parse_url($this->url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if (! in_array($scheme, ['ws', 'wss'], true) || $host === '') {
            throw new RuntimeException("Invalid WebSocket URL [{$this->url}].");
        }

        $secure = $scheme === 'wss';
        $port = (int) ($parts['port'] ?? ($secure ? 443 : 80));
        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        $context = stream_context_create($secure ? streamSslContextOptions() : []);
        $stream = @stream_socket_client(($secure ? 'tls' : 'tcp')."://{$host}:{$port}", $errno, $error, $this->timeout, STREAM_CLIENT_CONNECT, $context);

        if ($stream === false) {
            throw new RuntimeException("WebSocket connect to {$host}:{$port} failed: {$error} ({$errno})");
        }

        stream_set_timeout($stream, $this->timeout);
        $this->stream = $stream;
        $this->buffer = '';

        $key = base64_encode(random_bytes(16));
        $origin = ($secure ? 'https' : 'http').'://'.$host;
        $this->write("GET {$path} HTTP/1.1\r\nHost: {$host}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n".
            "Sec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\nOrigin: {$origin}\r\nUser-Agent: {$this->userAgent}\r\n\r\n");

        $headers = $this->readHandshake();
        if (! preg_match('#^HTTP/1\.[01] 101#', $headers)) {
            $this->close();
            throw new RuntimeException('WebSocket handshake rejected: '.strtok($headers, "\r\n"));
        }

        $expected = base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if (! preg_match('/^Sec-WebSocket-Accept:\s*(\S+)/mi', $headers, $m) || $m[1] !== $expected) {
            $this->close();
            throw new RuntimeException('WebSocket handshake returned an invalid Sec-WebSocket-Accept.');
        }
    }

    /**
     * Read the next text or binary message, answering pings on the way.
     *
     * @return string|null The message, or null when no data arrived within $idleSeconds.
     *
     * @throws RuntimeException When the server closes the connection or the stream fails.
     */
    public function receive(int $idleSeconds): ?string
    {
        $message = '';
        $deadline = time() + $idleSeconds;

        while (true) {
            $frame = $this->readFrame($deadline);
            if ($frame === null) {
                return null;
            }

            [$fin, $opcode, $payload] = $frame;
            $this->lastFrameAt = time();

            switch ($opcode) {
                case self::OP_PING:
                    $this->sendFrame(self::OP_PONG, $payload);
                    break;
                case self::OP_PONG:
                    break;
                case self::OP_CLOSE:
                    $code = strlen($payload) >= 2 ? unpack('n', substr($payload, 0, 2))[1] : 1005;
                    $this->close();
                    throw new RuntimeException("WebSocket closed by server ({$code}): ".substr($payload, 2));
                case self::OP_TEXT:
                case self::OP_BINARY:
                case self::OP_CONTINUATION:
                    $message .= $payload;
                    if ($fin) {
                        return $message;
                    }
                    break;
                default:
                    throw new RuntimeException("Unsupported WebSocket opcode {$opcode}.");
            }
        }
    }

    /**
     * Unix time of the last frame of any kind (including pings and heartbeats), 0 before the first.
     */
    public function lastFrameAt(): int
    {
        return $this->lastFrameAt;
    }

    public function send(string $text): void
    {
        $this->sendFrame(self::OP_TEXT, $text);
    }

    public function ping(): void
    {
        $this->sendFrame(self::OP_PING, '');
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }
        $this->stream = null;
    }

    /**
     * Encode one masked client frame.
     */
    public static function encodeFrame(int $opcode, string $payload, ?string $mask = null): string
    {
        $mask ??= random_bytes(4);
        $length = strlen($payload);
        $header = chr(0x80 | $opcode);

        if ($length < 126) {
            $header .= chr(0x80 | $length);
        } elseif ($length < 65536) {
            $header .= chr(0x80 | 126).pack('n', $length);
        } else {
            $header .= chr(0x80 | 127).pack('J', $length);
        }

        return $header.$mask.($payload ^ str_repeat($mask, intdiv($length, 4) + 1));
    }

    /**
     * Decode one frame from the start of $bytes.
     *
     * @return array{0: bool, 1: int, 2: string, 3: int}|null [fin, opcode, payload, bytes consumed], or null if incomplete.
     */
    public static function decodeFrame(string $bytes): ?array
    {
        if (strlen($bytes) < 2) {
            return null;
        }

        $first = ord($bytes[0]);
        $second = ord($bytes[1]);
        $length = $second & 0x7F;
        $offset = 2;

        if ($length === 126) {
            if (strlen($bytes) < 4) {
                return null;
            }
            $length = unpack('n', substr($bytes, 2, 2))[1];
            $offset = 4;
        } elseif ($length === 127) {
            if (strlen($bytes) < 10) {
                return null;
            }
            $length = unpack('J', substr($bytes, 2, 8))[1];
            $offset = 10;
        }

        if ($length > self::MAX_FRAME_BYTES) {
            throw new RuntimeException("WebSocket frame of {$length} bytes exceeds the limit.");
        }

        $mask = '';
        if (($second & 0x80) !== 0) {
            $mask = substr($bytes, $offset, 4);
            $offset += 4;
        }

        if (strlen($bytes) < $offset + $length) {
            return null;
        }

        $payload = substr($bytes, $offset, $length);
        if ($mask !== '' && strlen($mask) === 4) {
            $payload ^= str_repeat($mask, intdiv($length, 4) + 1);
            $payload = substr($payload, 0, $length);
        }

        return [($first & 0x80) !== 0, $first & 0x0F, $payload, $offset + $length];
    }

    /**
     * @return array{0: bool, 1: int, 2: string}|null
     */
    private function readFrame(int $deadline): ?array
    {
        while (true) {
            $frame = self::decodeFrame($this->buffer);
            if ($frame !== null) {
                $this->buffer = substr($this->buffer, $frame[3]);

                return [$frame[0], $frame[1], $frame[2]];
            }

            if (time() >= $deadline) {
                return null;
            }

            $this->fill();
        }
    }

    private function fill(): void
    {
        if (! is_resource($this->stream)) {
            throw new RuntimeException('WebSocket is not connected.');
        }

        $chunk = fread($this->stream, 65536);

        if ($chunk === false || $chunk === '') {
            // A read timeout returns false (or '') without EOF: no data yet, the connection is fine.
            if (! feof($this->stream) && stream_get_meta_data($this->stream)['timed_out']) {
                return;
            }

            $this->close();
            throw new RuntimeException('WebSocket connection lost.');
        }

        $this->buffer .= $chunk;
    }

    private function readHandshake(): string
    {
        $deadline = time() + $this->timeout;

        while (! str_contains($this->buffer, "\r\n\r\n")) {
            if (time() >= $deadline || strlen($this->buffer) > 16384) {
                throw new RuntimeException('WebSocket handshake timed out.');
            }
            $this->fill();
        }

        [$headers, $this->buffer] = explode("\r\n\r\n", $this->buffer, 2);

        return $headers;
    }

    private function sendFrame(int $opcode, string $payload): void
    {
        $this->write(self::encodeFrame($opcode, $payload));
    }

    private function write(string $bytes): void
    {
        if (! is_resource($this->stream) || @fwrite($this->stream, $bytes) !== strlen($bytes)) {
            $this->close();
            throw new RuntimeException('WebSocket write failed.');
        }
    }
}
