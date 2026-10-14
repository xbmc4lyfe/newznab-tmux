<?php

namespace Tests\Unit;

use App\Services\Yenc\NativePayloadDecoder;
use App\Services\Yenc\PhpPayloadDecoder;
use App\Services\Yenc\RawPayloadDecoder;
use App\Services\YencService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Fixtures\YencArticles;

class YencServiceTest extends TestCase
{
    private YencService $yencService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->yencService = new YencService;
    }

    public function test_enabled_returns_true(): void
    {
        $this->assertTrue($this->yencService->enabled());
    }

    public function test_reports_php_decoder(): void
    {
        $this->assertSame('PHP', $this->yencService->decoderName());
    }

    public function test_encode_and_decode_round_trip(): void
    {
        $originalData = 'Hello, World! This is a test of yEnc encoding.';
        $filename = 'test.txt';

        // Encode
        $encoded = $this->yencService->encode($originalData, $filename);

        // Verify encoding contains headers
        $this->assertStringContainsString('=ybegin', $encoded);
        $this->assertStringContainsString('=yend', $encoded);
        $this->assertStringContainsString('name=test.txt', $encoded);
        $this->assertStringContainsString('crc32=', $encoded);

        // Decode
        $decoded = $this->yencService->decode($encoded);

        $this->assertEquals($originalData, $decoded);
    }

    public function test_encode_respects_line_length(): void
    {
        $data = str_repeat('A', 500);
        $filename = 'test.txt';

        $encoded = $this->yencService->encode($data, $filename, 100);

        // Check that no content line exceeds 100 characters
        $lines = explode("\r\n", $encoded);
        foreach ($lines as $line) {
            // Skip header and trailer lines
            if (str_starts_with($line, '=y')) {
                continue;
            }
            $this->assertLessThanOrEqual(100, strlen($line));
        }
    }

    public function test_encode_caps_line_length_at_254(): void
    {
        $data = 'Test data';
        $filename = 'test.txt';

        $encoded = $this->yencService->encode($data, $filename, 300);

        // Should contain line=254 in header
        $this->assertStringContainsString('line=254', $encoded);
    }

    public function test_encode_throws_exception_for_invalid_line_length(): void
    {
        $this->expectException(RuntimeException::class);

        $this->yencService->encode('data', 'test.txt', 0);
    }

    public function test_decode_returns_false_for_non_yenc_data(): void
    {
        $nonYencData = 'This is just plain text.';

        $result = $this->yencService->decode($nonYencData);

        $this->assertFalse($result);
    }

    public function test_decode_ignore_returns_original_for_non_yenc_data(): void
    {
        $nonYencData = 'This is just plain text.';

        $result = $this->yencService->decodeIgnore($nonYencData);

        $this->assertEquals($nonYencData, $result);
    }

    public function test_decode_ignore_decodes_yenc_data(): void
    {
        $originalData = 'Test content for yEnc';
        $encoded = $this->yencService->encode($originalData, 'test.txt');

        $decoded = $this->yencService->decodeIgnore($encoded);

        $this->assertEquals($originalData, $decoded);
    }

    public function test_is_yenc_encoded_returns_true_for_yenc(): void
    {
        $encoded = $this->yencService->encode('test', 'test.txt');

        $this->assertTrue($this->yencService->isYencEncoded($encoded));
    }

    public function test_is_yenc_encoded_returns_false_for_plain_text(): void
    {
        $this->assertFalse($this->yencService->isYencEncoded('plain text'));
    }

    public function test_extract_metadata_returns_metadata(): void
    {
        $data = 'Test content';
        $encoded = $this->yencService->encode($data, 'myfile.dat', 128, true);

        $metadata = $this->yencService->extractMetadata($encoded);

        $this->assertNotNull($metadata);
        $this->assertEquals('myfile.dat', $metadata['name']);
        $this->assertEquals(strlen($data), $metadata['size']);
        $this->assertEquals(128, $metadata['line']);
        $this->assertNotNull($metadata['crc32']);
    }

    public function test_extract_metadata_returns_null_for_non_yenc(): void
    {
        $metadata = $this->yencService->extractMetadata('plain text');

        $this->assertNull($metadata);
    }

    public function test_encode_without_crc32(): void
    {
        $encoded = $this->yencService->encode('test', 'test.txt', 128, false);

        $this->assertStringNotContainsString('crc32=', $encoded);
    }

    public function test_encode_with_special_characters(): void
    {
        // Test with characters that need escaping (NULL, TAB, LF, CR, space, ., =)
        $data = "Hello\x00World\tTest\nNew\rLine . = End";

        $encoded = $this->yencService->encode($data, 'special.bin');
        $decoded = $this->yencService->decode($encoded);

        $this->assertEquals($data, $decoded);
    }

    public function test_encode_with_binary_data(): void
    {
        // Test with all possible byte values
        $data = '';
        for ($i = 0; $i < 256; $i++) {
            $data .= chr($i);
        }

        $encoded = $this->yencService->encode($data, 'binary.bin');
        $decoded = $this->yencService->decode($encoded);

        $this->assertEquals($data, $decoded);
    }

    public function test_raw_capable_decoder_receives_unstripped_payload(): void
    {
        $decoder = new class implements RawPayloadDecoder
        {
            public ?string $received = null;

            public function decode(string $payload): string
            {
                throw new RuntimeException('Stripped payload path must not be used.');
            }

            public function decodeRaw(string $payload): string
            {
                $this->received = $payload;

                return (new PhpPayloadDecoder)->decode(str_replace(["\r", "\n"], '', $payload));
            }
        };
        $data = YencArticles::randomBytes(4096);
        $article = 'preamble'."\r\n".YencArticles::article($data)."\r\n".'suffix';
        $service = new YencService($decoder);

        $strict = $article;
        $this->assertSame($data, $service->decode($strict));
        $this->assertNotNull($decoder->received);
        $this->assertStringContainsString("\r\n", $decoder->received);

        $decoder->received = null;
        $tolerant = $article;
        $this->assertSame($data, $service->decodeIgnore($tolerant));
        $this->assertNotNull($decoder->received);
        $this->assertStringContainsString("\r\n", $decoder->received);
    }

    public function test_tolerant_dangling_escape_bypasses_raw_decoder(): void
    {
        $decoder = new class implements RawPayloadDecoder
        {
            public function decode(string $payload): string
            {
                throw new RuntimeException('Backend must not receive an unmatched escape.');
            }

            public function decodeRaw(string $payload): string
            {
                throw new RuntimeException('Backend must not receive an unmatched escape.');
            }
        };
        $article = "=ybegin line=128 size=2 name=x\r\n====k=\r\n=yend size=2";
        $this->assertSame("\xd3\xd3A", (new YencService($decoder))->decodeIgnore($article));
    }

    public function test_escape_at_line_end_falls_back_to_stripped_decode(): void
    {
        $decoder = new class implements RawPayloadDecoder
        {
            public ?string $receivedRaw = null;

            public ?string $receivedStripped = null;

            public function decode(string $payload): string
            {
                $this->receivedStripped = $payload;

                return (new PhpPayloadDecoder)->decode($payload);
            }

            public function decodeRaw(string $payload): string
            {
                $this->receivedRaw = $payload;

                return (new PhpPayloadDecoder)->decode(str_replace(["\r", "\n"], '', $payload));
            }
        };
        $article = "=ybegin line=128 size=4 name=x\r\nklm=\r\nM\r\n=yend size=4";
        $this->assertSame("ABC\xe3", (new YencService($decoder))->decode($article));
        $this->assertNull($decoder->receivedRaw);
        $this->assertSame('klm=M', $decoder->receivedStripped);
    }

    /** @return iterable<string, array{list<string>, bool}> */
    public static function splitEscapeCapabilities(): iterable
    {
        yield 'supported' => [['WITH_SPLIT_ESCAPE_CAPABILITY', 'SPLIT_ESCAPE_DECODER'], true];
        yield 'absent with capable decoder' => [['SPLIT_ESCAPE_DECODER'], false];
        yield 'legacy decoder' => [[], false];
        yield 'explicit zero' => [['WITH_SPLIT_ESCAPE_CAPABILITY', 'SPLIT_ESCAPE_CAPABILITY=0', 'SPLIT_ESCAPE_DECODER'], false];
        yield 'unsupported positive' => [['WITH_SPLIT_ESCAPE_CAPABILITY', 'SPLIT_ESCAPE_CAPABILITY=2', 'SPLIT_ESCAPE_DECODER'], false];
        yield 'unsupported negative' => [['WITH_SPLIT_ESCAPE_CAPABILITY', 'SPLIT_ESCAPE_CAPABILITY=-1', 'SPLIT_ESCAPE_DECODER'], false];
        yield 'false advertisement' => [['WITH_SPLIT_ESCAPE_CAPABILITY'], false];
        yield 'invalid known-answer length' => [['WITH_SPLIT_ESCAPE_CAPABILITY', 'SPLIT_ESCAPE_DECODER', 'BAD_SPLIT_LENGTH'], false];
    }

    /** @param list<string> $defines */
    #[DataProvider('splitEscapeCapabilities')]
    public function test_only_verified_native_capability_receives_split_escapes(array $defines, bool $supported): void
    {
        $this->withNativeFixture($defines, function (NativePayloadDecoder $decoder, \FFI $probe) use ($supported): void {
            $this->assertSame($supported, $decoder->supportsSplitEscapes());
            foreach (["=\n@", "=\r@", "=\r\n\r\n@", "=\r\n=\n=\r="] as $payload) {
                $expected = str_ends_with($payload, '@') ? "\xd6" : "\xd3\xd3";
                $article = '=ybegin line=128 size='.strlen($expected)." name=x\n".$payload
                    ."\n=yend size=".strlen($expected).' crc32='.hash('crc32b', $expected);
                $service = new YencService($decoder);
                $this->assertSame($expected, $service->decode($article));
                $this->assertSame($supported ? 1 : 0, $probe->fixture_last_payload_had_split_escape()); // @phpstan-ignore method.notFound
                $this->assertSame($expected, $service->decodeIgnore($article));
                $this->assertSame($supported ? 1 : 0, $probe->fixture_last_payload_had_split_escape()); // @phpstan-ignore method.notFound
            }
        });
    }

    public function test_verified_split_escape_decoder_preserves_strict_validation_and_dangling_recovery(): void
    {
        $this->withNativeFixture(['WITH_SPLIT_ESCAPE_CAPABILITY', 'SPLIT_ESCAPE_DECODER'], function (NativePayloadDecoder $decoder): void {
            $service = new YencService($decoder);
            foreach ([
                "=ybegin line=128 size=2 name=x\n=\n@\n=yend size=2",
                "=ybegin line=128 size=1 name=x\n=\n@\n=yend size=1 crc32=deadbeef",
                "=ybegin line=128 size=1 name=x\n=\n==\n=yend size=1",
            ] as $article) {
                try {
                    $service->decode($article);
                    $this->fail('Strict native decoding must reject corrupt size, checksum, or dangling escape.');
                } catch (RuntimeException) {
                    $this->addToAssertionCount(1);
                }
            }
            $dangling = "=ybegin line=128 size=1 name=x\n=\n==\n=yend size=1";
            $this->assertSame("\xd3", $service->decodeIgnore($dangling));
        });
    }

    /** @return iterable<string, array{list<string>, bool}> */
    public static function spanDecoderCapabilities(): iterable
    {
        yield 'supported' => [['WITH_SPAN_DECODER', 'SPLIT_ESCAPE_DECODER', 'WITH_CRC'], true];
        yield 'absent' => [['SPLIT_ESCAPE_DECODER', 'WITH_CRC'], false];
        yield 'bad checksum' => [['WITH_SPAN_DECODER', 'SPLIT_ESCAPE_DECODER', 'WITH_CRC', 'BAD_SPAN_CRC'], false];
        yield 'invalid length' => [['WITH_SPAN_DECODER', 'SPLIT_ESCAPE_DECODER', 'WITH_CRC', 'BAD_SPAN_LENGTH'], false];
    }

    /** @param list<string> $defines */
    #[DataProvider('spanDecoderCapabilities')]
    public function test_verified_span_decoder_handles_all_frame_paths_and_reuses_its_checksum(array $defines, bool $supported): void
    {
        $this->withNativeFixture($defines, function (NativePayloadDecoder $decoder, \FFI $probe) use ($supported): void {
            $probe->fixture_reset_span_decode_calls(); // @phpstan-ignore method.notFound
            $probe->fixture_reset_crc_calls(); // @phpstan-ignore method.notFound
            $article = "preamble=\n=ybegin line=128 size=4 name=span.bin\r\nk=\r\n@lm\r\n=yend size=4 crc32=a672946b\n=\nsuffix";
            $original = $article;
            $service = new YencService($decoder);
            $this->assertSame("A\xd6BC", $service->decode($article));
            $this->assertSame($original, $article);
            $this->assertSame($supported ? 1 : 0, $probe->fixture_span_decode_calls()); // @phpstan-ignore method.notFound
            $this->assertSame($supported ? 1 : 0, $probe->fixture_span_crc_requests()); // @phpstan-ignore method.notFound
            $this->assertSame($supported ? 0 : 1, $probe->fixture_crc_calls()); // @phpstan-ignore method.notFound
            $this->assertSame("A\xd6BC", $service->decodeIgnore($article));
            [$decoded, $part] = $service->decodeWithFrame($original);
            $this->assertSame("A\xd6BC", $decoded);
            $this->assertSame('span.bin', $part?->name);
            $this->assertSame($supported ? 3 : 0, $probe->fixture_span_decode_calls()); // @phpstan-ignore method.notFound
            $this->assertSame($supported ? 1 : 0, $probe->fixture_span_crc_requests()); // @phpstan-ignore method.notFound
            $multipart = "=ybegin part=2 total=3 line=128 size=9 name=span.bin\n=ypart begin=4 end=7\nk=\r\n@lm\n=yend size=4 part=2 pcrc32=a672946b crc32=deadbeef";
            $this->assertSame("A\xd6BC", $service->decode($multipart));
            $withoutChecksum = "=ybegin line=128 size=4 name=span.bin\nk=\r\n@lm\n=yend size=4";
            $this->assertSame("A\xd6BC", $service->decode($withoutChecksum));
            $this->assertSame($supported ? 5 : 0, $probe->fixture_span_decode_calls()); // @phpstan-ignore method.notFound
            $this->assertSame($supported ? 2 : 0, $probe->fixture_span_crc_requests()); // @phpstan-ignore method.notFound
        });
    }

    public function test_span_decode_bounds_and_optional_checksums(): void
    {
        $this->withNativeFixture(['WITH_SPAN_DECODER', 'SPLIT_ESCAPE_DECODER'], function (NativePayloadDecoder $decoder, \FFI $probe): void {
            $probe->fixture_reset_span_decode_calls(); // @phpstan-ignore method.notFound
            $source = "left|k=\r\n@lm|right";
            $this->assertSame(['decoded' => "A\xd6BC", 'crc32' => 'a672946b'], $decoder->decodeSpan($source, 5, 7, true));
            $this->assertSame(['decoded' => "A\xd6BC", 'crc32' => null], $decoder->decodeSpan($source, 5, 7, false));
            $this->assertSame(['decoded' => '', 'crc32' => '00000000'], $decoder->decodeSpan('', 0, 0, true));
            $this->assertSame(['decoded' => '', 'crc32' => null], $decoder->decodeSpan($source, strlen($source), 0, false));
            $calls = $probe->fixture_span_decode_calls(); // @phpstan-ignore method.notFound
            foreach ([[-1, 0], [0, -1], [strlen($source) + 1, 0], [1, strlen($source)], [PHP_INT_MAX, 1], [1, PHP_INT_MAX]] as [$offset, $length]) {
                try {
                    $decoder->decodeSpan($source, $offset, $length, true);
                    $this->fail('An invalid span must be rejected before the native call.');
                } catch (RuntimeException) {
                    $this->addToAssertionCount(1);
                }
            }
            $this->assertSame($calls, $probe->fixture_span_decode_calls()); // @phpstan-ignore method.notFound
        });
    }

    public function test_span_path_preserves_corruption_checks_and_bounded_dangling_recovery(): void
    {
        $this->withNativeFixture(['WITH_SPAN_DECODER', 'SPLIT_ESCAPE_DECODER'], function (NativePayloadDecoder $decoder, \FFI $probe): void {
            $service = new YencService($decoder);
            foreach (YencDecoderTest::corruptArticles() as [$article]) {
                $original = $article;
                $this->assertSame('ABC', $service->decodeIgnore($article));
                try {
                    $service->decode($original);
                    $this->fail('The span path must preserve all strict frame checks.');
                } catch (RuntimeException) {
                    $this->addToAssertionCount(1);
                }
            }
            $probe->fixture_reset_span_decode_calls(); // @phpstan-ignore method.notFound
            $article = "prefix\n=ybegin line=128 size=1 name=x\n=\n==\n=yend size=1\n=\nsuffix";
            $original = $article;
            $this->assertSame("\xd3", $service->decodeIgnore($article));
            $this->assertSame("\xd3", $service->decodeWithFrame($original)[0]);
            try {
                $service->decode($original);
                $this->fail('Strict decoding must reject a dangling escape.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Unmatched yEnc escape marker', $exception->getMessage());
            }
            $this->assertSame(0, $probe->fixture_span_decode_calls()); // @phpstan-ignore method.notFound
        });
    }

    /**
     * Exercise the real FFI adapter on non-Linux test hosts without changing its production platform guard.
     *
     * @param  list<string>  $defines
     * @param  callable(NativePayloadDecoder, \FFI): void  $assertions
     */
    private function withNativeFixture(array $defines, callable $assertions): void
    {
        if (! extension_loaded('FFI') || in_array(strtolower((string) ini_get('ffi.enable')), ['', '0', 'false', 'off'], true)) {
            $this->markTestSkipped('Native capability probes require CLI FFI.');
        }
        $compiler = (new ExecutableFinder)->find('cc');
        if ($compiler === null) {
            $this->markTestSkipped('Native capability probes require a C compiler.');
        }
        $directory = sys_get_temp_dir().'/nntmux-yenc-capability-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $path = $directory.'/library.so';
        try {
            $command = [$compiler, '-shared', '-fPIC', '-O2'];
            foreach ($defines as $define) {
                $command[] = '-D'.$define;
            }
            (new Process([...$command, __DIR__.'/../Fixtures/rapidyenc-abi.c', '-o', $path]))->mustRun();
            if (PHP_OS_FAMILY === 'Linux') {
                $decoder = new NativePayloadDecoder($path);
            } else {
                $reflection = new ReflectionClass(NativePayloadDecoder::class);
                $decoder = $reflection->newInstanceWithoutConstructor();
                $library = \FFI::cdef('size_t rapidyenc_decode_ex(int, const void*, void*, size_t, int*);', $path);
                $reflection->getProperty('library')->setValue($decoder, $library);
                $reflection->getProperty('crcLibrary')->setValue($decoder, $reflection->getMethod('loadCrcLibrary')->invoke($decoder, $path));
                $supported = $reflection->getMethod('detectSplitEscapeSupport')->invoke($decoder, $path);
                $reflection->getProperty('splitEscapesSupported')->setValue($decoder, $supported);
                $reflection->getProperty('spanLibrary')->setValue($decoder, $reflection->getMethod('loadSpanLibrary')->invoke($decoder, $path));
            }
            $probe = \FFI::cdef(<<<'C'
                int fixture_last_payload_had_split_escape(void);
                int fixture_span_decode_calls(void);
                void fixture_reset_span_decode_calls(void);
                int fixture_span_crc_requests(void);
                int fixture_crc_calls(void);
                void fixture_reset_crc_calls(void);
                C, $path);
            $assertions($decoder, $probe);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    public function test_decode_with_frame_reports_where_a_part_sits_in_its_file(): void
    {
        $article = YencArticles::part('second part bytes', 'Movie.2024.mkv', 1000, 2, 101, 10);

        [$data, $part] = $this->yencService->decodeWithFrame($article);

        $this->assertSame('second part bytes', $data);
        $this->assertNotNull($part);
        $this->assertSame('Movie.2024.mkv', $part->name);
        $this->assertSame(1000, $part->size);
        $this->assertSame(2, $part->part);
        $this->assertSame(100, $part->offset());
        $this->assertSame(17, $part->length());
    }

    public function test_decode_with_frame_returns_unframed_text_unchanged(): void
    {
        $this->assertSame(['plain body', null], $this->yencService->decodeWithFrame('plain body'));
    }
}
