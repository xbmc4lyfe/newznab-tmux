<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Yenc\DecoderFactory;
use App\Services\Yenc\NativePayloadDecoder;
use App\Services\Yenc\PhpPayloadDecoder;
use App\Services\YencService;
use FFI;
use FFI\Exception as FfiException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Fixtures\YencArticles;

final class YencNativeLibraryTest extends TestCase
{
    private ?string $directory = null;

    private string $compiler;

    protected function setUp(): void
    {
        parent::setUp();
        if (PHP_SAPI !== 'cli' || ! extension_loaded('FFI')
            || in_array(strtolower((string) ini_get('ffi.enable')), ['', '0', 'false', 'off'], true)) {
            $this->markTestSkipped('Native ABI regressions require CLI PHP with FFI.');
        }
        $compiler = (new ExecutableFinder)->find('cc');
        if ($compiler === null) {
            $this->markTestSkipped('Native ABI regressions require a C compiler.');
        }
        $this->compiler = $compiler;
        $this->directory = sys_get_temp_dir().'/nntmux-yenc-abi-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        if ($this->directory !== null) {
            $library = $this->directory.'/library.so';
            if (is_file($library)) {
                unlink($library);
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    /** @return iterable<string, array{bool}> */
    public static function crcBuilds(): iterable
    {
        yield 'decode-only library' => [false];
        yield 'CRC-enabled library' => [true];
    }

    #[DataProvider('crcBuilds')]
    public function test_optional_crc_preserves_native_selection_and_strict_checksums(bool $withCrc): void
    {
        $this->requireLinuxConstructor();
        $path = $this->compile($withCrc ? ['WITH_CRC'] : []);
        $data = YencArticles::bytes();
        foreach (['auto', 'native'] as $mode) {
            $decoder = (new DecoderFactory(new NullLogger))->make($mode, $path);
            $this->assertInstanceOf(NativePayloadDecoder::class, $decoder);
            $this->assertSame('1.1.1', $decoder->version());
            $this->assertSame($withCrc ? hash('crc32b', $data) : null, $decoder->crc32($data));
            $article = YencArticles::article($data);
            $this->assertSame($data, (new YencService($decoder))->decode($article));
        }

        $article = str_replace(hash('crc32b', $data), 'deadbeef', YencArticles::article($data));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CRC32 checksums do not match');
        (new YencService(new NativePayloadDecoder($path)))->decode($article);
    }

    public function test_bad_crc_self_check_is_not_treated_as_an_absent_capability(): void
    {
        $this->requireLinuxConstructor();
        $path = $this->compile(['WITH_CRC', 'BAD_CRC']);
        $factory = new DecoderFactory(new NullLogger);
        $this->assertInstanceOf(PhpPayloadDecoder::class, $factory->make('auto', $path));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RapidYenc failed its CRC32 known-answer check.');
        $factory->make('native', $path);
    }

    public function test_missing_required_decoder_symbol_is_not_swallowed(): void
    {
        $this->requireLinuxConstructor();
        $path = $this->compile(['OMIT_DECODER']);
        $this->expectException(FfiException::class);
        $this->expectExceptionMessage("Failed resolving C function 'rapidyenc_decode_ex'");
        new NativePayloadDecoder($path);
    }

    public function test_split_escape_capability_is_cached_for_the_loaded_library(): void
    {
        $this->requireLinuxConstructor();
        $path = $this->compile(['WITH_SPLIT_ESCAPE_CAPABILITY', 'SPLIT_ESCAPE_DECODER']);
        $first = new NativePayloadDecoder($path);
        $second = new NativePayloadDecoder($path);
        $this->assertTrue($first->supportsSplitEscapes());
        $this->assertTrue($second->supportsSplitEscapes());
        $probe = FFI::cdef('int fixture_split_escape_capability_calls(void);', $path);
        $this->assertSame(1, $probe->fixture_split_escape_capability_calls()); // @phpstan-ignore method.notFound
    }

    #[DataProvider('crcBuilds')]
    public function test_span_decoder_ignores_bytes_outside_the_payload_and_returns_decoded_crc(bool $withCrc): void
    {
        $path = $this->compile(['WITH_SPAN_DECODER', 'SPLIT_ESCAPE_DECODER', ...($withCrc ? ['WITH_CRC'] : [])]);
        $decoder = $this->portableDecoder($path);
        $data = YencArticles::bytes();
        $article = YencArticles::article($data);
        $start = strpos($article, "\r\n") + 2;
        $end = strpos($article, "\r\n=yend");
        self::assertIsInt($end);
        $payload = substr($article, $start, $end - $start);
        $prefix = "preamble\0";
        $source = $prefix.$payload."\r\n=yend size=256 crc32=deadbeef\r\ntrailer";

        self::assertSame(['decoded' => $data, 'crc32' => hash('crc32b', $data)], $decoder->decodeSpan($source, strlen($prefix), strlen($payload), true));
        self::assertSame(['decoded' => $data, 'crc32' => null], $decoder->decodeSpan($source, strlen($prefix), strlen($payload), false));
        self::assertSame(['decoded' => '', 'crc32' => '00000000'], $decoder->decodeSpan($source, strlen($source), 0, true));
        self::assertSame(['decoded' => "A\xd6BC", 'crc32' => 'a672946b'], $decoder->decodeSpan('ignore|'."k=\r\n@lm".'|not-payload', 7, 7, true));
    }

    /** @return iterable<string, array{list<string>}> */
    public static function unavailableSpanBuilds(): iterable
    {
        yield 'legacy decoder has no span export' => [[]];
        yield 'span returns an oversized length' => [['WITH_SPAN_DECODER', 'SPLIT_ESCAPE_DECODER', 'BAD_SPAN_LENGTH']];
        yield 'span returns an incorrect checksum' => [['WITH_SPAN_DECODER', 'SPLIT_ESCAPE_DECODER', 'BAD_SPAN_CRC']];
        yield 'span cannot preserve split escapes' => [['WITH_SPAN_DECODER']];
    }

    /** @param list<string> $defines */
    #[DataProvider('unavailableSpanBuilds')]
    public function test_unavailable_or_invalid_span_decoder_preserves_legacy_decode(array $defines): void
    {
        $decoder = $this->portableDecoder($this->compile($defines));
        self::assertNull($decoder->decodeSpan('prefixklmsuffix', 6, 3, true));
        self::assertNull($decoder->decodeSpan('payload', -1, PHP_INT_MAX, true));
        self::assertSame('ABC', $decoder->decodeRaw('klm'));
    }

    /** @param list<string> $defines */
    #[DataProvider('unavailableSpanBuilds')]
    public function test_optional_span_failure_preserves_constructor_native_selection(array $defines): void
    {
        $this->requireLinuxConstructor();
        $path = $this->compile($defines);
        $article = YencArticles::article('ABC');
        foreach (['auto', 'native'] as $mode) {
            $decoder = (new DecoderFactory(new NullLogger))->make($mode, $path);
            self::assertInstanceOf(NativePayloadDecoder::class, $decoder);
            self::assertNull($decoder->decodeSpan('klm', 0, 3, true));
            self::assertSame('ABC', (new YencService($decoder))->decode($article));
        }
    }

    public function test_span_abi_rejects_invalid_arguments_without_writing_output_or_crc(): void
    {
        $library = $this->spanBinding($this->compile(['WITH_SPAN_DECODER', 'SPLIT_ESCAPE_DECODER']));
        $output = $library->new('unsigned char[16]');
        $crc = $library->new('uint32_t[1]');
        foreach ([
            ['klm', 3, 4, 0, $output, 16],
            ['klm', 3, 1, 3, $output, 16],
            ['klm', 3, 0, 3, $output, 2],
            [null, 3, 0, 3, $output, 16],
            ['klm', 3, 0, 3, null, 3],
            ['klm', 3, 0, -1, $output, 16],
            ['klm', -1, 0, 0, $output, 16],
        ] as [$source, $sourceLength, $offset, $length, $destination, $capacity]) {
            FFI::memset($output, 0x5A, 16);
            $crc[0] = 0x12345678;
            self::assertSame(-1, $library->nntmux_yenc_decode_span_v1($source, $sourceLength, $offset, $length, $destination, $capacity, $crc)); // @phpstan-ignore method.notFound
            self::assertSame(str_repeat('Z', 16), FFI::string($output, 16));
            self::assertSame(0x12345678, $crc[0]);
        }
        FFI::memset($output, 0x5A, 16);
        self::assertSame(0, $library->nntmux_yenc_decode_span_v1('klm', 3, 3, 0, $output, 0, $crc)); // @phpstan-ignore method.notFound
        self::assertSame(0, $crc[0]);
        self::assertSame(str_repeat('Z', 16), FFI::string($output, 16));
        foreach ([$output, null] as $destination) {
            $crc[0] = 0x12345678;
            self::assertSame(0, $library->nntmux_yenc_decode_span_v1(null, 0, 0, 0, $destination, 0, $crc)); // @phpstan-ignore method.notFound
            self::assertSame(0, $crc[0]);
            self::assertSame(str_repeat('Z', 16), FFI::string($output, 16));
            self::assertSame(0, $library->nntmux_yenc_decode_span_v1(null, 0, 0, 0, $destination, 0, null)); // @phpstan-ignore method.notFound
        }
        self::assertSame(3, $library->nntmux_yenc_decode_span_v1('prefixklmsuffix', 15, 6, 3, $output, 3, null)); // @phpstan-ignore method.notFound
        self::assertSame('ABC', FFI::string($output, 3));
    }

    public function test_span_capability_probe_is_cached_for_the_process_and_resolved_library(): void
    {
        $this->requireLinuxConstructor();
        $path = $this->compile(['WITH_SPAN_DECODER', 'SPLIT_ESCAPE_DECODER']);
        $probe = FFI::cdef('int fixture_span_decode_calls(void);', $path);
        $first = new NativePayloadDecoder($path);
        $calls = $probe->fixture_span_decode_calls(); // @phpstan-ignore method.notFound
        self::assertGreaterThan(0, $calls);
        $second = new NativePayloadDecoder($path);
        self::assertSame($calls, $probe->fixture_span_decode_calls()); // @phpstan-ignore method.notFound
        self::assertSame(['decoded' => 'ABC', 'crc32' => 'a3830348'], $first->decodeSpan('klm', 0, 3, true));
        self::assertSame(['decoded' => 'ABC', 'crc32' => null], $second->decodeSpan('klm', 0, 3, false));
    }

    private function requireLinuxConstructor(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('Production native selection and process cache require Linux; portable span ABI tests still run.');
        }
    }

    private function portableDecoder(string $path): NativePayloadDecoder
    {
        $reflection = new ReflectionClass(NativePayloadDecoder::class);
        $decoder = $reflection->newInstanceWithoutConstructor();
        $library = FFI::cdef('size_t rapidyenc_decode_ex(int is_raw, const void* src, void* dest, size_t length, int* state);', $path);
        $reflection->getProperty('library')->setValue($decoder, $library);
        $reflection->getProperty('crcLibrary')->setValue($decoder, null);
        $reflection->getProperty('splitEscapesSupported')->setValue($decoder, false);
        $span = (new ReflectionMethod($decoder, 'loadSpanLibrary'))->invoke($decoder, $path);
        $reflection->getProperty('spanLibrary')->setValue($decoder, $span);

        return $decoder;
    }

    private function spanBinding(string $path): FFI
    {
        return FFI::cdef('size_t nntmux_yenc_decode_span_v1(const void* src, size_t source_length, size_t payload_offset, size_t payload_length, void* dest, size_t destination_capacity, uint32_t* crc32_output);', $path);
    }

    /** @param list<string> $defines */
    private function compile(array $defines): string
    {
        $path = $this->directory.'/library.so';
        $command = [$this->compiler, '-shared', '-fPIC', '-O2'];
        foreach ($defines as $define) {
            $command[] = '-D'.$define;
        }
        (new Process([...$command, __DIR__.'/../Fixtures/rapidyenc-abi.c', '-o', $path]))->mustRun();

        return $path;
    }
}
