<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Yenc\ArticleFrame;
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
        self::assertSame(['output' => null, 'capacity' => 0, 'crc' => null], $this->spanCache($decoder));
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

    public function test_verified_span_reuses_instance_buffers_and_copies_returned_strings(): void
    {
        $path = $this->compile(['WITH_SPAN_DECODER', 'SPLIT_ESCAPE_DECODER']);
        $decoder = $this->portableDecoder($path);
        self::assertSame(['output' => null, 'capacity' => 0, 'crc' => null], $this->spanCache($decoder));
        self::assertSame(['decoded' => '', 'crc32' => null], $decoder->decodeSpan('', 0, 0, false));
        self::assertSame(['output' => null, 'capacity' => 0, 'crc' => null], $this->spanCache($decoder));
        $first = $decoder->decodeSpan('prefixklmsuffix', 6, 3, false);
        self::assertSame(['decoded' => 'ABC', 'crc32' => null], $first);
        $withoutCrc = $this->spanCache($decoder);
        self::assertInstanceOf(FFI\CData::class, $withoutCrc['output']);
        self::assertSame(256, $withoutCrc['capacity']);
        self::assertSame(256, FFI::sizeof($withoutCrc['output']));
        self::assertNull($withoutCrc['crc']);

        $second = $decoder->decodeSpan('nopq', 0, 4, true);
        self::assertSame(['decoded' => 'DEFG', 'crc32' => hash('crc32b', 'DEFG')], $second);
        $withCrc = $this->spanCache($decoder);
        self::assertSame($withoutCrc['output'], $withCrc['output']);
        self::assertInstanceOf(FFI\CData::class, $withCrc['crc']);
        self::assertSame(4, FFI::sizeof($withCrc['crc']));
        $third = $decoder->decodeSpan("k=\r\n@lm", 0, 7, true);
        self::assertSame(['decoded' => "A\xd6BC", 'crc32' => 'a672946b'], $third);
        self::assertSame($withCrc, $this->spanCache($decoder));
        self::assertSame(['decoded' => 'ABC', 'crc32' => null], $first);
        self::assertSame(['decoded' => 'DEFG', 'crc32' => hash('crc32b', 'DEFG')], $second);
        self::assertSame(['decoded' => '', 'crc32' => '00000000'], $decoder->decodeSpan('', 0, 0, true));
        self::assertSame($withCrc, $this->spanCache($decoder));

        $other = $this->portableDecoder($path);
        self::assertSame(['output' => null, 'capacity' => 0, 'crc' => null], $this->spanCache($other));
        self::assertSame(['decoded' => 'ABC', 'crc32' => 'a3830348'], $other->decodeSpan('klm', 0, 3, true));
        $otherCache = $this->spanCache($other);
        self::assertNotSame($withCrc['output'], $otherCache['output']);
        self::assertNotSame($withCrc['crc'], $otherCache['crc']);
        self::assertSame($withCrc, $this->spanCache($decoder));
    }

    public function test_span_output_cache_grows_within_the_budget_and_large_calls_are_transient(): void
    {
        $decoder = $this->portableDecoder($this->compile(['WITH_SPAN_DECODER', 'SPLIT_ESCAPE_DECODER']));
        $previous = null;
        foreach ([[1, 256], [256, 256], [257, 512], [513, 1024], [32769, 65536], [65536, 65536]] as [$length, $capacity]) {
            $encoded = str_repeat('k', $length);
            self::assertSame(['decoded' => str_repeat('A', $length), 'crc32' => null], $decoder->decodeSpan($encoded, 0, $length, false));
            $cache = $this->spanCache($decoder);
            self::assertSame($capacity, $cache['capacity']);
            self::assertInstanceOf(FFI\CData::class, $cache['output']);
            self::assertSame($capacity, FFI::sizeof($cache['output']));
            self::assertNull($cache['crc']);
            if ($previous !== null) {
                if ($previous['capacity'] === $capacity) {
                    self::assertSame($previous['output'], $cache['output']);
                } else {
                    self::assertNotSame($previous['output'], $cache['output']);
                }
            }
            $previous = $cache;
        }
        $large = str_repeat('l', 65537);
        $retained = $decoder->decodeSpan($large, 0, strlen($large), true);
        self::assertSame(['decoded' => str_repeat('B', 65537), 'crc32' => hash('crc32b', str_repeat('B', 65537))], $retained);
        $afterLarge = $this->spanCache($decoder);
        self::assertSame($previous['output'], $afterLarge['output']);
        self::assertSame(65536, $afterLarge['capacity']);
        self::assertSame(['decoded' => 'ABC', 'crc32' => 'a3830348'], $decoder->decodeSpan('klm', 0, 3, true));
        self::assertSame($afterLarge, $this->spanCache($decoder));
        self::assertSame(str_repeat('B', 65537), $retained['decoded']);
    }

    public function test_large_and_empty_spans_do_not_seed_an_output_cache(): void
    {
        $decoder = $this->portableDecoder($this->compile(['WITH_SPAN_DECODER', 'SPLIT_ESCAPE_DECODER']));
        $large = str_repeat('m', 65537);
        self::assertSame(['decoded' => str_repeat('C', 65537), 'crc32' => null], $decoder->decodeSpan($large, 0, strlen($large), false));
        self::assertSame(['output' => null, 'capacity' => 0, 'crc' => null], $this->spanCache($decoder));
        self::assertSame(['decoded' => '', 'crc32' => '00000000'], $decoder->decodeSpan('prefix', 6, 0, true));
        $emptyCache = $this->spanCache($decoder);
        self::assertNull($emptyCache['output']);
        self::assertSame(0, $emptyCache['capacity']);
        self::assertInstanceOf(FFI\CData::class, $emptyCache['crc']);
        self::assertSame(4, FFI::sizeof($emptyCache['crc']));
    }

    public function test_cached_span_buffers_keep_binary_results_independent_and_crc_disjoint(): void
    {
        $path = $this->compile(['WITH_SPAN_DECODER', 'SPLIT_ESCAPE_DECODER']);
        $decoder = $this->portableDecoder($path);
        $probe = FFI::cdef('int fixture_span_crc_overlaps(void); void fixture_reset_span_decode_calls(void);', $path);
        $probe->fixture_reset_span_decode_calls(); // @phpstan-ignore method.notFound
        $results = [];
        $crcBuffer = null;
        foreach ([YencArticles::bytes(), YencArticles::randomBytes(1200), "\0\xff\x01\x80", YencArticles::randomBytes(129), YencArticles::bytes()] as $data) {
            $source = "unselected\0\r\n".YencArticles::article($data)."\r\ntrailing=bytes";
            $original = $source;
            $frame = ArticleFrame::parse($source);
            self::assertNotNull($frame);
            $result = $decoder->decodeSpan($source, $frame->payloadOffset, $frame->payloadLength, true);
            self::assertSame(['decoded' => $data, 'crc32' => hash('crc32b', $data)], $result);
            self::assertSame($original, $source);
            $cache = $this->spanCache($decoder);
            if ($crcBuffer !== null) {
                self::assertSame($crcBuffer, $cache['crc']);
            }
            $crcBuffer = $cache['crc'];
            $results[] = [$result, $data];
        }
        foreach ($results as [$result, $data]) {
            self::assertSame(['decoded' => $data, 'crc32' => hash('crc32b', $data)], $result);
        }
        self::assertSame(0, $probe->fixture_span_crc_overlaps()); // @phpstan-ignore method.notFound
    }

    public function test_span_bounds_and_native_failure_preserve_cached_buffers_for_the_next_decode(): void
    {
        $path = $this->compile(['WITH_SPAN_DECODER', 'SPLIT_ESCAPE_DECODER']);
        $decoder = $this->portableDecoder($path);
        $probe = FFI::cdef('void fixture_fail_next_span_decode(void); int fixture_span_decode_calls(void);', $path);
        self::assertSame(['decoded' => 'ABC', 'crc32' => 'a3830348'], $decoder->decodeSpan('klm', 0, 3, true));
        $cache = $this->spanCache($decoder);
        $calls = $probe->fixture_span_decode_calls(); // @phpstan-ignore method.notFound
        foreach ([[-1, 1], [1, PHP_INT_MAX], [4, 0], [0, -1]] as [$offset, $length]) {
            try {
                $decoder->decodeSpan('klm', $offset, $length, true);
                self::fail('Invalid bounds must be rejected before calling the native decoder.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('Invalid yEnc payload span', $exception->getMessage());
            }
            self::assertSame($cache, $this->spanCache($decoder));
        }
        self::assertSame($calls, $probe->fixture_span_decode_calls()); // @phpstan-ignore method.notFound
        $probe->fixture_fail_next_span_decode(); // @phpstan-ignore method.notFound
        try {
            $decoder->decodeSpan('nop', 0, 3, true);
            self::fail('A native error length must be rejected without returning stale cached output.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('invalid decoded length', $exception->getMessage());
        }
        self::assertSame($cache, $this->spanCache($decoder));
        self::assertSame(['decoded' => 'DEF', 'crc32' => hash('crc32b', 'DEF')], $decoder->decodeSpan('nop', 0, 3, true));
        self::assertSame($cache, $this->spanCache($decoder));
    }

    public function test_cloning_a_warmed_decoder_resets_its_mutable_span_cache(): void
    {
        $decoder = $this->portableDecoder($this->compile(['WITH_SPAN_DECODER', 'SPLIT_ESCAPE_DECODER']));
        self::assertSame(['decoded' => 'ABC', 'crc32' => 'a3830348'], $decoder->decodeSpan('klm', 0, 3, true));
        $originalCache = $this->spanCache($decoder);

        $clone = clone $decoder;
        self::assertSame(['output' => null, 'capacity' => 0, 'crc' => null], $this->spanCache($clone));
        self::assertFalse($this->spanBuffersBorrowed($clone));
        self::assertSame(['decoded' => 'DEFG', 'crc32' => hash('crc32b', 'DEFG')], $clone->decodeSpan('nopq', 0, 4, true));
        $cloneCache = $this->spanCache($clone);
        self::assertNotSame($originalCache['output'], $cloneCache['output']);
        self::assertNotSame($originalCache['crc'], $cloneCache['crc']);
        self::assertSame($originalCache, $this->spanCache($decoder));
        self::assertSame('ABC', FFI::string($originalCache['output'], 3));
        self::assertSame(0xA3830348, $originalCache['crc'][0]);
        self::assertSame(['decoded' => 'ABC', 'crc32' => 'a3830348'], $decoder->decodeSpan('klm', 0, 3, true));
    }

    /** @return iterable<string, array{bool}> */
    public static function nestedSpanTargets(): iterable
    {
        yield 'same decoder reenters with transient buffers' => [false];
        yield 'clone made during borrowed call owns fresh buffers' => [true];
    }

    #[DataProvider('nestedSpanTargets')]
    public function test_nested_span_decode_cannot_overwrite_outer_results_before_copyout(bool $cloneTarget): void
    {
        $path = $this->compile(['WITH_SPAN_DECODER', 'SPLIT_ESCAPE_DECODER']);
        $decoder = $this->portableDecoder($path);
        $probe = FFI::cdef('void fixture_set_span_decode_callback(void (*callback)(void));', $path);
        // Prewarming prevents a nested allocation from hiding shared-buffer writes.
        self::assertSame(['decoded' => 'DEFG', 'crc32' => hash('crc32b', 'DEFG')], $decoder->decodeSpan('nopq', 0, 4, true));
        $cache = $this->spanCache($decoder);
        $outerSource = 'prefixklmsuffix';
        $nestedSource = 'unselectednopqtrailer';
        $nested = null;
        $nestedDecoder = null;
        $cloneCacheBeforeDecode = null;
        $callback = function () use ($decoder, $cloneTarget, $nestedSource, &$nested, &$nestedDecoder, &$cloneCacheBeforeDecode): void {
            $nestedDecoder = $cloneTarget ? clone $decoder : $decoder;
            if ($cloneTarget) {
                $cloneCacheBeforeDecode = $this->spanCache($nestedDecoder);
            }
            $nested = $nestedDecoder->decodeSpan($nestedSource, 10, 4, true);
        };
        $probe->fixture_set_span_decode_callback($callback); // @phpstan-ignore method.notFound
        try {
            $outer = $decoder->decodeSpan($outerSource, 6, 3, true);
        } finally {
            $probe->fixture_set_span_decode_callback(null); // @phpstan-ignore method.notFound
        }

        self::assertSame(['decoded' => 'ABC', 'crc32' => 'a3830348'], $outer);
        self::assertSame(['decoded' => 'DEFG', 'crc32' => hash('crc32b', 'DEFG')], $nested);
        self::assertSame('prefixklmsuffix', $outerSource);
        self::assertSame('unselectednopqtrailer', $nestedSource);
        self::assertSame($cache, $this->spanCache($decoder));
        self::assertSame('ABC', FFI::string($cache['output'], 3));
        self::assertSame(0xA3830348, $cache['crc'][0]);
        self::assertFalse($this->spanBuffersBorrowed($decoder));
        self::assertInstanceOf(NativePayloadDecoder::class, $nestedDecoder);
        self::assertFalse($this->spanBuffersBorrowed($nestedDecoder));
        if ($cloneTarget) {
            self::assertSame(['output' => null, 'capacity' => 0, 'crc' => null], $cloneCacheBeforeDecode);
            $cloneCache = $this->spanCache($nestedDecoder);
            self::assertInstanceOf(FFI\CData::class, $cloneCache['output']);
            self::assertInstanceOf(FFI\CData::class, $cloneCache['crc']);
            self::assertNotSame($cache['output'], $cloneCache['output']);
            self::assertNotSame($cache['crc'], $cloneCache['crc']);
        }
        self::assertSame(['decoded' => 'HIJ', 'crc32' => hash('crc32b', 'HIJ')], $decoder->decodeSpan('rst', 0, 3, true));
        self::assertSame(['decoded' => 'ABC', 'crc32' => 'a3830348'], $outer);
        self::assertSame(['decoded' => 'DEFG', 'crc32' => hash('crc32b', 'DEFG')], $nested);
    }

    public function test_native_span_failure_releases_the_buffer_borrow_for_the_next_call(): void
    {
        $path = $this->compile(['WITH_SPAN_DECODER', 'SPLIT_ESCAPE_DECODER']);
        $decoder = $this->portableDecoder($path);
        $probe = FFI::cdef('void fixture_fail_next_span_decode(void);', $path);
        self::assertSame(['decoded' => 'ABC', 'crc32' => 'a3830348'], $decoder->decodeSpan('klm', 0, 3, true));
        $cache = $this->spanCache($decoder);
        $probe->fixture_fail_next_span_decode(); // @phpstan-ignore method.notFound
        try {
            $decoder->decodeSpan('nop', 0, 3, true);
            self::fail('A native error length must release the cached-buffer borrow.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('invalid decoded length', $exception->getMessage());
        }

        self::assertFalse($this->spanBuffersBorrowed($decoder));
        self::assertSame($cache, $this->spanCache($decoder));
        self::assertSame(['decoded' => 'DEF', 'crc32' => hash('crc32b', 'DEF')], $decoder->decodeSpan('nop', 0, 3, true));
        self::assertSame($cache, $this->spanCache($decoder));
        self::assertFalse($this->spanBuffersBorrowed($decoder));
    }

    private function spanBuffersBorrowed(NativePayloadDecoder $decoder): bool
    {
        return (new ReflectionClass($decoder))->getProperty('spanBuffersBorrowed')->getValue($decoder);
    }

    /** @return array{output: FFI\CData|null, capacity: int, crc: FFI\CData|null} */
    private function spanCache(NativePayloadDecoder $decoder): array
    {
        $reflection = new ReflectionClass($decoder);

        return [
            'output' => $reflection->getProperty('spanOutput')->getValue($decoder),
            'capacity' => $reflection->getProperty('spanOutputCapacity')->getValue($decoder),
            'crc' => $reflection->getProperty('spanCrc')->getValue($decoder),
        ];
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
