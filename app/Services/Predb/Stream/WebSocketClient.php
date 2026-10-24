<?php

declare(strict_types=1);

namespace App\Services\Predb\Stream;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use RuntimeException;

/**
 * Minimal RFC 6455 WebSocket client for reading JSON push feeds.
 *
 * Supports ws:// and wss://, text and binary frames, fragmentation, ping/pong and close. Client frames
 * are masked as the RFC requires. It is a reader: send() exists for pongs and an optional subscribe message.
 */
class WebSocketClient
{
    private const NATIVE_CDEF = <<<'C'
        ssize_t nntmux_ws_encode_frame(uint8_t opcode, const char *payload, size_t payload_len, const char *mask, size_t mask_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_ws_decode_frame(const char *bytes, size_t bytes_len, uint32_t *meta_out, char *out_buf, size_t out_cap);
        C;

    private static FFI|false|null $native = null;

    private static ?CData $nativeBuffer = null;

    private static int $nativeCapacity = 0;

    private static ?CData $nativeMeta = null;

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

    /**
     * Fragments of a message still being received; kept across receive() calls that time out.
     */
    private string $partial = '';

    public function __construct(
        private readonly string $url,
        private readonly int $timeout = 15,
        private readonly string $userAgent = 'NNTmux-PreDB-Importer/1.0',
        private readonly int $maxMessageBytes = self::MAX_FRAME_BYTES,
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
        $this->partial = '';

        $key = base64_encode(random_bytes(16));
        $authority = self::authority($host, $port, $secure);
        $origin = ($secure ? 'https' : 'http').'://'.$authority;
        $this->write("GET {$path} HTTP/1.1\r\nHost: {$authority}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n".
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
                    $this->partial .= $payload;
                    if (strlen($this->partial) > $this->maxMessageBytes) {
                        $this->partial = '';
                        $this->close();
                        throw new RuntimeException("WebSocket message exceeds {$this->maxMessageBytes} bytes.");
                    }
                    if ($fin) {
                        $message = $this->partial;
                        $this->partial = '';

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
     * Host header value: the host, plus the port when it is not the scheme's default.
     */
    public static function authority(string $host, int $port, bool $secure): string
    {
        $host = str_contains($host, ':') && ! str_starts_with($host, '[') ? '['.$host.']' : $host;

        return $port === ($secure ? 443 : 80) ? $host : $host.':'.$port;
    }

    /**
     * Encode one masked client frame.
     */
    public static function encodeFrame(int $opcode, string $payload, ?string $mask = null): string
    {
        $mask ??= random_bytes(4);
        // FFI overhead loses on short messages; preserve legacy non-four-byte masks.
        if (strlen($payload) >= 4096 && strlen($mask) === 4 && $opcode >= 0 && $opcode <= 255) {
            $frame = self::encodeFrameNative($opcode, $payload, $mask);
            if ($frame !== null) {
                return $frame;
            }
        }

        return self::encodeFramePhp($opcode, $payload, $mask);
    }

    private static function encodeFramePhp(int $opcode, string $payload, string $mask): string
    {
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
        // Unmasked server frames need only PHP's substring; avoid an FFI copy.
        if (strlen($bytes) >= 4096 && (ord($bytes[1]) & 0x80) !== 0) {
            $lengthCode = ord($bytes[1]) & 0x7F;
            // Route by this frame's payload, not a buffer of coalesced tiny frames.
            if (($lengthCode === 126 && ord($bytes[2]) >= 0x10)
                || ($lengthCode === 127 && substr_compare($bytes, "\0\0\0\0\0\0\x10\0", 2, 8) >= 0)) {
                $frame = self::decodeFrameNative($bytes);
                if ($frame !== false) {
                    return $frame;
                }
            }
        }

        return self::decodeFramePhp($bytes);
    }

    /** @return array{bool, int, string, int}|null */
    private static function decodeFramePhp(string $bytes): ?array
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

            // RFC 6455: the most significant bit of a 64-bit length must be 0 (it unpacks as negative).
            if ($length < 0) {
                throw new RuntimeException('WebSocket frame length has the reserved high bit set.');
            }
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

    private static function nativeLibrary(): ?FFI
    {
        if (self::$native === null) {
            self::$native = RustPipelineLibrary::resolveDomain('ws_frames', self::NATIVE_CDEF) ?? false;
        }

        return self::$native === false ? null : self::$native;
    }

    private static function nativeBuffer(FFI $ffi, int $needed): CData
    {
        if (self::$nativeBuffer === null || self::$nativeCapacity < $needed) {
            self::$nativeCapacity = max(4096, $needed);
            self::$nativeBuffer = $ffi->new('char['.self::$nativeCapacity.']');
        }

        return self::$nativeBuffer;
    }

    private static function encodeFrameNative(int $opcode, string $payload, string $mask): ?string
    {
        $ffi = self::nativeLibrary();
        if ($ffi === null) {
            return null;
        }
        $buffer = self::nativeBuffer($ffi, strlen($payload) + 14);
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_ws_encode_frame($opcode, $payload, strlen($payload), $mask, strlen($mask), $buffer, self::$nativeCapacity);

        return is_int($written) && $written >= 0 ? FFI::string($buffer, $written) : null;
    }

    /** @return array{bool, int, string, int}|false|null */
    private static function decodeFrameNative(string $bytes): array|false|null
    {
        $ffi = self::nativeLibrary();
        if ($ffi === null) {
            return false;
        }
        // A bounded scratch buffer is enough even when bytes contains many frames.
        $buffer = self::nativeBuffer($ffi, min(strlen($bytes), self::MAX_FRAME_BYTES));
        $meta = self::$nativeMeta ??= $ffi->new('uint32_t[5]');
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_ws_decode_frame($bytes, strlen($bytes), $meta, $buffer, self::$nativeCapacity);
        if ($written === -1) {
            return null;
        }
        if ($written === -2) {
            throw new RuntimeException('WebSocket frame length has the reserved high bit set.');
        }
        if ($written === -3) {
            $length = ((int) $meta[4] << 32) | (int) $meta[3];
            throw new RuntimeException("WebSocket frame of {$length} bytes exceeds the limit.");
        }
        if (! is_int($written) || $written < 0) {
            return false;
        }

        return [(bool) $meta[0], (int) $meta[1], $written === 0 ? '' : FFI::string($buffer, $written), (int) $meta[2]];
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
