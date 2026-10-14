<?php

declare(strict_types=1);

namespace App\Services\Yenc;

use FFI;
use FFI\Exception as FfiException;
use RuntimeException;

final class NativePayloadDecoder implements RawPayloadDecoder
{
    /** @var array<string, array{decoder: FFI, crc: FFI|null, split_escapes: bool, span: FFI|null}> */
    private static array $libraries = [];

    private readonly FFI $library;

    private readonly ?FFI $crcLibrary;

    private readonly bool $splitEscapesSupported;

    private readonly ?FFI $spanLibrary;

    public function __construct(string $path)
    {
        // Thread-safe (ZTS) builds are fine here: a CLI process runs one thread, and
        // FFI handles are cached per process. Server SAPIs stay on the PHP decoder.
        if (PHP_OS_FAMILY !== 'Linux' || PHP_SAPI !== 'cli' || ! extension_loaded('FFI')) {
            throw new RuntimeException('Native yEnc requires Linux CLI PHP with FFI.');
        }
        $resolved = realpath($path);
        if ($path === '' || $resolved === false || ! is_file($resolved)) {
            throw new RuntimeException('YENC_NATIVE_LIBRARY must point to a readable RapidYenc shared library.');
        }
        $key = getmypid().':'.$resolved;
        if (isset(self::$libraries[$key])) {
            $this->library = self::$libraries[$key]['decoder'];
            $this->crcLibrary = self::$libraries[$key]['crc'];
            $this->splitEscapesSupported = self::$libraries[$key]['split_escapes'];
            $this->spanLibrary = self::$libraries[$key]['span'];

            return;
        }
        $this->library = FFI::cdef(<<<'C'
            void rapidyenc_decode_init(void);
            size_t rapidyenc_decode_ex(int is_raw, const void* src, void* dest, size_t src_length, int* state);
            int rapidyenc_version(void);
            C, $resolved);
        // FFI exposes symbols from the pinned C header as dynamic methods.
        $this->library->rapidyenc_decode_init(); // @phpstan-ignore method.notFound
        if ($this->decode("klm=@=J=M=}..\x0b==") !== "ABC\xd6\xe0\xe3\x13\x04\x04\xe1\xd3") {
            throw new RuntimeException('RapidYenc failed its known-answer check.');
        }
        $this->crcLibrary = $this->loadCrcLibrary($resolved);
        if ($this->crcLibrary !== null && $this->crc32('ABC') !== 'a3830348') {
            throw new RuntimeException('RapidYenc failed its CRC32 known-answer check.');
        }
        $this->splitEscapesSupported = $this->detectSplitEscapeSupport($resolved);
        $this->spanLibrary = $this->loadSpanLibrary($resolved);
        self::$libraries[$key] = [
            'decoder' => $this->library,
            'crc' => $this->crcLibrary,
            'split_escapes' => $this->splitEscapesSupported,
            'span' => $this->spanLibrary,
        ];
    }

    public function decode(string $payload): string
    {
        return $this->decodeRaw($payload);
    }

    public function supportsSplitEscapes(): bool
    {
        return $this->splitEscapesSupported;
    }

    /** @return array{decoded: string, crc32: string|null}|null */
    public function decodeSpan(string $source, int $payloadOffset, int $payloadLength, bool $withCrc): ?array
    {
        // Capability absence selects the legacy path without inspecting a span.
        if ($this->spanLibrary === null) {
            return null;
        }
        $sourceLength = strlen($source);
        if ($payloadOffset < 0 || $payloadLength < 0 || $payloadOffset > $sourceLength
            || $payloadLength > $sourceLength - $payloadOffset) {
            throw new RuntimeException('Invalid yEnc payload span.');
        }

        return $this->decodeSpanWithLibrary($this->spanLibrary, $source, $payloadOffset, $payloadLength, $withCrc);
    }

    public function decodeRaw(string $payload): string
    {
        $length = strlen($payload);
        if ($length === 0) {
            return '';
        }
        $output = $this->library->new("char[{$length}]");
        /** @var int $written */
        $written = $this->library->rapidyenc_decode_ex(0, $payload, $output, $length, null); // @phpstan-ignore method.notFound
        if ($written < 0 || $written > $length) {
            throw new RuntimeException('RapidYenc returned an invalid decoded length.');
        }

        return FFI::string($output, $written);
    }

    /**
     * Hardware-accelerated CRC32 (identical to PHP's crc32b).
     *
     * @return string|null Lowercase eight-character hex digest, or null when the loaded library was built without CRC support.
     */
    public function crc32(string $data): ?string
    {
        if ($this->crcLibrary === null) {
            return null;
        }
        /** @var int $crc */
        $crc = $this->crcLibrary->rapidyenc_crc($data, strlen($data), 0); // @phpstan-ignore method.notFound

        return str_pad(dechex($crc), 8, '0', STR_PAD_LEFT);
    }

    /** RapidYenc version string (for example "1.1.1"). */
    public function version(): string
    {
        /** @var int $version */
        $version = $this->library->rapidyenc_version(); // @phpstan-ignore method.notFound

        return sprintf('%d.%d.%d', ($version >> 16) & 0xFF, ($version >> 8) & 0xFF, $version & 0xFF);
    }

    private function detectSplitEscapeSupport(string $path): bool
    {
        try {
            $capability = FFI::cdef('int nntmux_yenc_supports_split_escapes(void);', $path);
        } catch (FfiException $exception) {
            if ($exception->getMessage() !== "Failed resolving C function 'nntmux_yenc_supports_split_escapes'") {
                throw $exception;
            }

            return false;
        }
        if ($capability->nntmux_yenc_supports_split_escapes() !== 1) { // @phpstan-ignore method.notFound
            return false;
        }
        try {
            return $this->decodeRaw("k=\r\n\r\n@l=\nJm=\rM=\r\n=\n=\r=") === "A\xd6B\xe0C\xe3\xd3\xd3";
        } catch (RuntimeException) {
            return false;
        }
    }

    private function loadSpanLibrary(string $path): ?FFI
    {
        try {
            $library = FFI::cdef(<<<'C'
                size_t nntmux_yenc_decode_span_v1(const void* src, size_t source_length,
                    size_t payload_offset, size_t payload_length, void* dest,
                    size_t destination_capacity, uint32_t* crc32_output);
                C, $path);
        } catch (FfiException $exception) {
            if ($exception->getMessage() !== "Failed resolving C function 'nntmux_yenc_decode_span_v1'") {
                throw $exception;
            }

            return null;
        }
        try {
            $source = "ignore|k=\r\n@lm|not-payload";
            if ($this->decodeSpanWithLibrary($library, $source, 7, 7, true)
                !== ['decoded' => "A\xd6BC", 'crc32' => 'a672946b']
                || $this->decodeSpanWithLibrary($library, $source, 7, 7, false)
                !== ['decoded' => "A\xd6BC", 'crc32' => null]) {
                return null;
            }
        } catch (RuntimeException) {
            return null;
        }

        return $library;
    }

    /** @return array{decoded: string, crc32: string|null} */
    private function decodeSpanWithLibrary(FFI $library, string $source, int $payloadOffset, int $payloadLength, bool $withCrc): array
    {
        $output = $payloadLength === 0 ? null : $library->new("char[{$payloadLength}]");
        $crc = $withCrc ? $library->new('uint32_t[1]') : null;
        /** @var int $written */
        $written = $library->nntmux_yenc_decode_span_v1( // @phpstan-ignore method.notFound
            $source, strlen($source), $payloadOffset, $payloadLength, $output, $payloadLength,
            $crc,
        );
        if ($written < 0 || $written > $payloadLength) {
            throw new RuntimeException('RapidYenc returned an invalid decoded length.');
        }

        return [
            'decoded' => $output === null ? '' : FFI::string($output, $written),
            'crc32' => $crc === null ? null : str_pad(dechex($crc[0]), 8, '0', STR_PAD_LEFT),
        ];
    }

    private function loadCrcLibrary(string $path): ?FFI
    {
        try {
            $library = FFI::cdef(<<<'C'
                void rapidyenc_crc_init(void);
                uint32_t rapidyenc_crc(const void* src, size_t src_length, uint32_t init_crc);
                C, $path);
        } catch (FfiException $exception) {
            // CRC-disabled builds omit these exports; FFI resolves them during cdef, not the call.
            if (! in_array($exception->getMessage(), [
                "Failed resolving C function 'rapidyenc_crc_init'",
                "Failed resolving C function 'rapidyenc_crc'",
            ], true)) {
                throw $exception;
            }

            return null;
        }
        $library->rapidyenc_crc_init(); // @phpstan-ignore method.notFound

        return $library;
    }
}
