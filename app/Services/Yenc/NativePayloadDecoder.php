<?php

declare(strict_types=1);

namespace App\Services\Yenc;

use FFI;
use RuntimeException;

final class NativePayloadDecoder implements PayloadDecoder
{
    /** @var array<string, FFI> */
    private static array $libraries = [];

    private readonly FFI $library;

    public function __construct(string $path)
    {
        if (PHP_OS_FAMILY !== 'Linux' || PHP_SAPI !== 'cli' || PHP_ZTS || ! extension_loaded('FFI')) {
            throw new RuntimeException('Native yEnc requires Linux CLI, non-threaded PHP and FFI.');
        }
        $resolved = realpath($path);
        if ($path === '' || $resolved === false || ! is_file($resolved)) {
            throw new RuntimeException('YENC_NATIVE_LIBRARY must point to a readable RapidYenc shared library.');
        }
        $key = getmypid().':'.$resolved;
        if (isset(self::$libraries[$key])) {
            $this->library = self::$libraries[$key];

            return;
        }
        $this->library = FFI::cdef(<<<'C'
            void rapidyenc_decode_init(void);
            size_t rapidyenc_decode_ex(int is_raw, const void* src, void* dest, size_t src_length, int* state);
            C, $resolved);
        // FFI exposes symbols from the pinned C header as dynamic methods.
        $this->library->rapidyenc_decode_init(); // @phpstan-ignore method.notFound
        if ($this->decode("klm=@=J=M=}..\x0b==") !== "ABC\xd6\xe0\xe3\x13\x04\x04\xe1\xd3") {
            throw new RuntimeException('RapidYenc failed its known-answer check.');
        }
        self::$libraries[$key] = $this->library;
    }

    public function decode(string $payload): string
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
}
