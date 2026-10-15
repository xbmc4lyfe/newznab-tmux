<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Yenc\ExperimentalStrictArticleDecoder;
use App\Services\Yenc\PayloadDecoder;
use App\Services\Yenc\PhpPayloadDecoder;
use App\Services\YencService;
use FFI;
use FFI\Exception;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Fixtures\YencArticles;
use Tests\Support\YencNntpHarness;

final class YencStrictArticleExperimentTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            foreach (['wrapper.c', 'wrapper.so'] as $name) {
                if (is_file($directory.'/'.$name)) {
                    unlink($directory.'/'.$name);
                }
            }
            rmdir($directory);
        }
        parent::tearDown();
    }

    private function libraryPath(): string
    {
        $suffix = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';

        return dirname(__DIR__, 2).'/docker/app/nzb-yenc-ffi/target/release/libnzbyenc.'.$suffix;
    }

    private function native(): ExperimentalStrictArticleDecoder
    {
        if (PHP_SAPI !== 'cli' || ! extension_loaded('FFI')) {
            self::markTestSkipped('Strict article experiment requires CLI FFI.');
        }
        $path = $this->libraryPath();
        if (! is_file($path)) {
            self::markTestSkipped('Build the experimental-strict-article Rust feature first.');
        }
        try {
            FFI::cdef('uint32_t nntmux_yenc_decode_strict_article_v1(const void*, size_t, void*, size_t, uint64_t, void*);', $path);
        } catch (Exception) {
            self::markTestSkipped('Optional strict article symbol is absent or CLI FFI is disabled.');
        }

        return new ExperimentalStrictArticleDecoder($path);
    }

    public function test_explicit_native_capability_decodes_canonical_single_and_multipart_articles(): void
    {
        $decoder = $this->native();
        self::assertTrue($decoder->isAvailable(), 'Experimental symbol and known answers must qualify.');
        foreach ([
            ["=ybegin line=128 size=3 name=x\r\nklm\r\n=yend size=3 crc32=a3830348", 'ABC'],
            ["=ybegin part=2 total=3 line=128 size=9 name=x\n=ypart begin=4 end=6\nklm\n=yend size=3 pcrc32=a3830348", 'ABC'],
            ["=YBEGIN SIZE=0003 LiNe=invalid NAME=x size=999\nklm\n=YEND SIZE=003\n", 'ABC'],
            ["=ybegin line= size=0 name=\n=yend size=0 crc32=0", ''],
            ["=ybegin line=128 size=4 name=x\nk=\r\n@lm\n=yend size=4 crc32=a672946b", "A\xd6BC"],
            ["=ybegin line=0 size=3 total=0 name=x\nklm\n=yend size=3 pcrc32=deadbeef", 'ABC'],
        ] as [$article, $expected]) {
            self::assertSame($expected, $decoder->decode($article));
            self::assertSame($expected, (new YencService(new PhpPayloadDecoder, $decoder))->decode($article));
        }
    }

    public function test_service_uses_explicit_capability_instead_of_the_payload_fallback(): void
    {
        $decoder = $this->native();
        self::assertTrue($decoder->isAvailable());
        $fallback = new class implements PayloadDecoder
        {
            public function decode(string $payload): string
            {
                throw new RuntimeException('Unexpected fallback for a canonical article.');
            }
        };
        $article = "=ybegin line=128 size=3 name=x\nklm\n=yend size=3 crc32=a3830348";
        self::assertSame('ABC', (new YencService($fallback, $decoder))->decode($article));
    }

    public function test_handled_errors_preserve_php_precedence_and_never_return_partial_output(): void
    {
        $decoder = $this->native();
        self::assertTrue($decoder->isAvailable());
        foreach ([
            ["=ybegin line=128 size=0 name=x\nk=\n=yend size=99 crc32=bad", 'Unmatched yEnc escape marker. The file is probably corrupt.'],
            ["=ybegin part=0 line=128 size=3 name=x\n=ypart begin=1 end=3\nklm\n=yend size=999", 'Invalid yEnc multipart range or part number.'],
            ["=ybegin part=1 line=128 size=3 name=x\n=ypart begin=1 end=3\nklm\n=yend size=999", 'Missing yEnc part checksum.'],
            ["=ybegin line=128 size=9 name=x\nklm\n=yend size=3 crc32=bad", 'Declared and decoded yEnc sizes do not match. The file is probably corrupt.'],
            ["=ybegin line=128 size=3 name=x\nklm\n=yend size=3 crc32=deadbeef", 'CRC32 checksums do not match. The file is probably corrupt.'],
        ] as [$article, $expected]) {
            foreach ([new YencService, new YencService(new PhpPayloadDecoder, $decoder)] as $service) {
                try {
                    $service->decode($article);
                    self::fail('Expected strict rejection.');
                } catch (RuntimeException $exception) {
                    self::assertSame($expected, $exception->getMessage());
                }
            }
        }
    }

    public function test_unsupported_grammar_preserves_first_frame_and_php_fallback_rules(): void
    {
        $decoder = $this->native();
        self::assertTrue($decoder->isAvailable());
        $single = "=ybegin line=128 size=3 name=x\nklm\n=yend size=3 crc32=a3830348";
        foreach (["preamble\n".$single, str_replace('size=3 name=', 'size=999 size=3 name=', $single), $single."\n".$single] as $article) {
            self::assertNull($decoder->decode($article));
            self::assertSame('ABC', (new YencService(new PhpPayloadDecoder, $decoder))->decode($article));
        }
        $article = "=ybegin line=128 size=3 name=x\nklm\n=yend size=3 name=ignored crc32=deadbeef";
        self::assertNull($decoder->decode($article));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CRC32 checksums do not match');
        (new YencService(new PhpPayloadDecoder, $decoder))->decode($article);
    }

    public function test_ambiguous_first_frame_and_numeric_fallback_do_not_select_a_later_success(): void
    {
        $decoder = $this->native();
        self::assertTrue($decoder->isAvailable());
        $valid = "=ybegin line=1 size=3 name=x\nklm\n=yend size=3 crc32=a3830348";
        foreach ([
            [str_replace('a3830348', 'deadbeef', $valid)."\n".$valid, 'CRC32 checksums do not match. The file is probably corrupt.'],
            ["=ybegin line=1 size=oops name=x\nklm=\n=yend size=99 crc32=bad", 'Unmatched yEnc escape marker. The file is probably corrupt.'],
            ["=ybegin line=1 size=99999999999999999999999999 name=x\nklm\n=yend size=3", 'Invalid or missing yEnc size.'],
        ] as [$article, $message]) {
            self::assertNull($decoder->decode($article));
            try {
                (new YencService(new PhpPayloadDecoder, $decoder))->decode($article);
                self::fail('Expected the first complete frame or original numeric validation to fail.');
            } catch (RuntimeException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }
        foreach (["=ybegin line=1 name=x size=3\nklm\n=yend size=3", 'ordinary text'] as $article) {
            self::assertNull($decoder->decode($article));
            self::assertFalse((new YencService(new PhpPayloadDecoder, $decoder))->decode($article));
        }
    }

    public function test_reuse_and_cloning_copy_only_actual_output_without_mutating_articles(): void
    {
        $decoder = $this->native();
        self::assertTrue($decoder->isAvailable());
        $clone = clone $decoder;
        foreach ([str_repeat('A', 70_000), '', 'ABC', YencArticles::bytes(), 'x'] as $data) {
            $article = YencArticles::article($data);
            $original = $article;
            self::assertSame($data, $decoder->decode($article));
            self::assertSame($data, $clone->decode($article));
            self::assertSame($original, $article);
        }
    }

    public function test_absent_optional_library_keeps_incumbent_service_working(): void
    {
        $decoder = new ExperimentalStrictArticleDecoder('/no/such/strict-article-library');
        self::assertFalse($decoder->isAvailable());
        $article = "=ybegin line=128 size=3 name=x\nklm\n=yend size=3 crc32=a3830348";
        self::assertNull($decoder->decode($article));
        self::assertSame('ABC', (new YencService(new PhpPayloadDecoder, $decoder))->decode($article));
    }

    public function test_strict_success_without_checksum_is_not_validated_reuse(): void
    {
        $decoder = $this->native();
        self::assertTrue($decoder->isAvailable());
        $service = new YencService(new PhpPayloadDecoder, $decoder);
        $article = "=ybegin line=128 size=3 name=x\nklm\n=yend size=3";
        self::assertSame('ABC', $service->decode($article));
        self::assertFalse($service->hasValidatedIntegrity($article, 'ABC'));
        $article .= ' crc32=a3830348';
        self::assertTrue($service->hasValidatedIntegrity($article, 'ABC'));
        self::assertFalse($service->hasValidatedIntegrity($article, 'OTHER'));
    }

    public function test_existing_common_fixture_shapes_keep_strict_tolerant_and_body_results(): void
    {
        $decoder = $this->native();
        self::assertTrue($decoder->isAvailable());
        foreach ([4096, 524288, 1048576, 8388608] as $size) {
            foreach ([YencArticles::bytes(), "\xd6\xe0\xe3\x13"] as $seed) {
                $data = substr(str_repeat($seed, (int) ceil($size / strlen($seed))), 0, $size);
                $article = YencArticles::article($data);
                self::assertSame($data, $decoder->decode($article));
                foreach ([new YencService, new YencService(new PhpPayloadDecoder, $decoder)] as $service) {
                    $input = $article;
                    self::assertSame($data, $service->decode($input));
                    self::assertSame($article, $input);
                    self::assertSame($data, $service->decodeIgnore($input));
                    self::assertSame($data, $input);
                    $stream = fopen('php://memory', 'w+');
                    self::assertIsResource($stream);
                    try {
                        fwrite($stream, preg_replace('/^\./m', '..', $article)."\r\n.\r\n");
                        rewind($stream);
                        self::assertSame($data, (new YencNntpHarness($stream, $service))->fetch());
                    } finally {
                        fclose($stream);
                    }
                }
            }
        }
    }

    public function test_existing_corrupt_corpus_retains_exception_precedence_through_opt_in_service(): void
    {
        $decoder = $this->native();
        self::assertTrue($decoder->isAvailable());
        foreach (YencDecoderTest::corruptArticles() as [$article]) {
            try {
                (new YencService)->decode($article);
                self::fail('Expected incumbent rejection.');
            } catch (RuntimeException $incumbent) {
                try {
                    (new YencService(new PhpPayloadDecoder, $decoder))->decode($article);
                    self::fail('Expected matching candidate rejection.');
                } catch (RuntimeException $candidate) {
                    self::assertSame($incumbent->getMessage(), $candidate->getMessage());
                }
            }
        }
    }

    public function test_malformed_native_responses_fail_closed_after_capability_admission(): void
    {
        self::assertTrue($this->native()->isAvailable());
        $path = $this->wrapper();
        $decoder = new ExperimentalStrictArticleDecoder($path);
        self::assertTrue($decoder->isAvailable());
        $control = FFI::cdef('void fixture_mode(int value);', $path);
        $article = "=ybegin line=128 size=3 name=x\nklm\n=yend size=3 crc32=a3830348";
        foreach (range(1, 9) as $mode) {
            $control->fixture_mode($mode); // @phpstan-ignore method.notFound
            try {
                (new YencService(new PhpPayloadDecoder, $decoder))->decode($article);
                self::fail('Malformed native response must not silently fall back.');
            } catch (RuntimeException $exception) {
                self::assertSame('Experimental Rust yEnc contract failure.', $exception->getMessage(), "mode {$mode}");
            }
        }
        $control->fixture_mode(0); // @phpstan-ignore method.notFound
        self::assertSame('ABC', $decoder->decode($article));
    }

    public function test_failed_known_answer_disables_only_the_optional_strict_capability(): void
    {
        self::assertTrue($this->native()->isAvailable());
        $decoder = new ExperimentalStrictArticleDecoder($this->wrapper(4));
        self::assertFalse($decoder->isAvailable());
        $article = "=ybegin line=128 size=3 name=x\nklm\n=yend size=3 crc32=a3830348";
        self::assertNull($decoder->decode($article));
        self::assertSame('ABC', (new YencService(new PhpPayloadDecoder, $decoder))->decode($article));
    }

    public function test_nested_callback_cannot_overwrite_outer_output_or_result_storage(): void
    {
        self::assertTrue($this->native()->isAvailable());
        $path = $this->wrapper();
        $decoder = new ExperimentalStrictArticleDecoder($path);
        self::assertTrue($decoder->isAvailable());
        $control = FFI::cdef('void fixture_callback(void (*callback)(void));', $path);
        $nested = null;
        $failure = null;
        $callback = function () use ($control, $decoder, &$nested, &$failure): void {
            try {
                $control->fixture_callback(null); // @phpstan-ignore method.notFound
                $nested = $decoder->decode(YencArticles::article('ZZZZZZZZZZ'));
            } catch (\Throwable $exception) {
                // Exceptions must not unwind through an FFI callback.
                $failure = $exception;
            }
        };
        $control->fixture_callback($callback); // @phpstan-ignore method.notFound
        try {
            $outer = $decoder->decode(YencArticles::article('ABC'));
        } finally {
            $control->fixture_callback(null); // @phpstan-ignore method.notFound
        }
        self::assertNull($failure);
        self::assertSame('ZZZZZZZZZZ', $nested);
        self::assertSame('ABC', $outer);
        self::assertSame('ABC', $decoder->decode(YencArticles::article('ABC')));
    }

    private function wrapper(int $initialMode = 0): string
    {
        $compiler = (new ExecutableFinder)->find('cc');
        if ($compiler === null) {
            self::markTestSkipped('Native contract response checks require a C compiler.');
        }
        $directory = sys_get_temp_dir().'/nntmux-strict-article-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $this->temporaryDirectories[] = $directory;
        $path = json_encode($this->libraryPath(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        // Forward normal calls into real Rust; corrupt only the response under test.
        $source = <<<C
            #include <stdint.h>
            #include <stddef.h>
            #include <dlfcn.h>
            typedef struct { uint64_t written; uint32_t crc32; uint32_t flags; } result_t;
            typedef uint32_t (*decode_t)(const void*, size_t, void*, size_t, uint64_t, result_t*);
            static int mode = {$initialMode};
            static void (*after_call)(void);
            void fixture_mode(int value) { mode = value; }
            void fixture_callback(void (*value)(void)) { after_call = value; }
            uint32_t nntmux_yenc_decode_strict_article_v1(const void* s, size_t n, void* d, size_t c, uint64_t m, result_t* r) {
                static decode_t call;
                if (!call) call = (decode_t)dlsym(dlopen({$path}, RTLD_NOW | RTLD_LOCAL), "nntmux_yenc_decode_strict_article_v1");
                if (!call) return 255;
                uint32_t status = call(s,n,d,c,m,r);
                if (after_call) after_call();
                switch (mode) {
                    case 1: return 255;
                    case 2: return 2;
                    case 3: return 99;
                    case 4: r->flags = 4; return 0;
                    case 5: r->written = UINT64_MAX; return 0;
                    case 6: r->flags = 1; return 0;
                    case 7: r->flags = 0; r->crc32 = 1; return 0;
                    case 8: r->written = 1; r->flags = 0; r->crc32 = 0; return 3;
                    case 9: r->flags = 3; return 5;
                    default: return status;
                }
            }
            C;
        file_put_contents($directory.'/wrapper.c', $source);
        (new Process([$compiler, '-shared', '-fPIC', '-O2', $directory.'/wrapper.c', '-o', $directory.'/wrapper.so', ...(PHP_OS_FAMILY === 'Linux' ? ['-ldl'] : [])]))->mustRun();

        return $directory.'/wrapper.so';
    }
}
