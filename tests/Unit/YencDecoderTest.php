<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Yenc\ArticleFrame;
use App\Services\Yenc\PayloadDecoder;
use App\Services\YencService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Fixtures\YencArticles;

class YencDecoderTest extends TestCase
{
    protected function service(): YencService
    {
        return new YencService;
    }

    /** @return iterable<string, array{string}> */
    public static function corpus(): iterable
    {
        yield 'all bytes' => [YencArticles::bytes()];
        yield 'empty' => [''];
        yield 'binary edges' => ["\xe1\xf6\xff\x00\xd6\xe1"];
        yield 'many escapes' => [str_repeat("\xd6\xe0\xe3\x13", 1024)];
        yield 'random' => [YencArticles::randomBytes(65536)];
        yield 'over PCRE limit' => [str_repeat('ABC', 400000)];
    }

    #[DataProvider('corpus')]
    public function test_decodes_independent_corpus_without_mutating_strict_input(string $data): void
    {
        foreach (["\r\n", "\n"] as $eol) {
            $service = $this->service();
            $article = YencArticles::article($data, $eol);
            $input = 'Article preamble'.$eol.$article.$eol.'Article signature';
            $original = $input;
            $this->assertTrue($service->isYencEncoded($input));
            $this->assertSame($data, $service->decode($input));
            $this->assertSame($original, $input);
            $this->assertSame($data, $service->decodeIgnore($input));
            $this->assertSame($data, $input);
        }
    }

    public function test_metadata_preserves_filename_with_parameter_like_words(): void
    {
        $article = str_replace('fixture file.bin', 'a size=999 name=file with spaces.bin', YencArticles::article('ABC'));
        $this->assertSame([
            'name' => 'a size=999 name=file with spaces.bin', 'size' => 3, 'line' => 128, 'crc32' => 'A3830348',
        ], $this->service()->extractMetadata($article));
    }

    /** @return iterable<string, array{string, array<string, string>, array<string, string>|null, array<string, string>, int, string}> */
    public static function framesWithOptionalTrailerFields(): iterable
    {
        $prefix = "=YbEgIn PaRt=2 ToTaL=3 LiNe=128 SiZe=9 NaMe=a size=999 name=with spaces.bin\r\n"
            ."=YpArT BeGiN=4 EnD=6\r\n";
        yield 'mixed case multipart and filename resembling fields' => [
            $prefix."klm\r\n=YeNd SiZe=3 PaRt=2 PcRc32=a3830348 CrC32=deadbeef",
            ['part' => '2', 'total' => '3', 'line' => '128', 'size' => '9', 'name' => 'a size=999 name=with spaces.bin'],
            ['begin' => '4', 'end' => '6'],
            ['size' => '3', 'part' => '2', 'pcrc32' => 'a3830348', 'crc32' => 'deadbeef'],
            strlen($prefix),
            "klm\r\n",
        ];

        $prefix = "=ybegin line=128 name=invalid\nnoise\n=ybegin line=128 size=3 name=valid.bin\n";
        yield 'invalid initial header followed by a valid frame' => [
            $prefix."klm\n=yend size=3 crc32=a3830348\nignored suffix",
            ['line' => '128', 'size' => '3', 'name' => 'valid.bin'],
            null,
            ['size' => '3', 'crc32' => 'a3830348'],
            strlen($prefix),
            "klm\n",
        ];

        $prefix = "=ybegin line=128 size=9 name=truncated.bin\nunfinished\n"
            ."=ybegin line=128 size=3 name=recovered.bin\n";
        yield 'a subsequent header replaces a truncated frame' => [
            $prefix."klm\n=yend size=3 crc32=a3830348",
            ['line' => '128', 'size' => '3', 'name' => 'recovered.bin'],
            null,
            ['size' => '3', 'crc32' => 'a3830348'],
            strlen($prefix),
            "klm\n",
        ];

        $prefix = "=ybegin line=128 size=0 name=empty.bin\n";
        yield 'empty payload and trailer without a final newline' => [
            $prefix.'=yend size=0 crc32=0',
            ['line' => '128', 'size' => '0', 'name' => 'empty.bin'],
            null,
            ['size' => '0', 'crc32' => '0'],
            strlen($prefix),
            '',
        ];
    }

    /**
     * @param  array<string, string>  $header
     * @param  array<string, string>|null  $part
     * @param  array<string, string>  $trailer
     */
    #[DataProvider('framesWithOptionalTrailerFields')]
    public function test_optional_trailer_parsing_preserves_frame_selection_and_payload_range(
        string $article,
        array $header,
        ?array $part,
        array $trailer,
        int $payloadOffset,
        string $payload,
    ): void {
        $fullFrame = ArticleFrame::parse($article);
        $payloadFrame = ArticleFrame::parse($article, false);

        foreach ([$fullFrame, $payloadFrame] as $frame) {
            self::assertNotNull($frame);
            self::assertSame($header, $frame->header);
            self::assertSame($part, $frame->part);
            self::assertSame($payloadOffset, $frame->payloadOffset);
            self::assertSame(strlen($payload), $frame->payloadLength);
            self::assertSame($payload, substr($article, $frame->payloadOffset, $frame->payloadLength));
        }
        self::assertSame($trailer, $fullFrame->trailer);
        self::assertSame([], $payloadFrame->trailer);
    }

    public function test_multipart_accepts_a_missing_trailer_part_with_valid_header_range_and_checksum(): void
    {
        $raw = YencArticles::part('abc', 'movie.mkv', 6, 1, 1, 2);
        $raw = str_replace('=yend size=3 part=1 ', '=yend size=3 ', $raw);
        $this->assertSame('abc', $this->service()->decode($raw));
    }

    public function test_missing_trailer_part_does_not_skip_the_part_checksum(): void
    {
        $raw = YencArticles::part('abc', 'movie.mkv', 6, 1, 1, 2);
        $raw = str_replace('=yend size=3 part=1 ', '=yend size=3 ', $raw);
        $raw = preg_replace('/pcrc32=[0-9a-f]+/', 'pcrc32=00000000', $raw);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CRC32 checksums do not match.');
        $this->service()->decode($raw);
    }

    public function test_present_trailer_part_must_still_match_the_header(): void
    {
        $raw = YencArticles::part('abc', 'movie.mkv', 6, 1, 1, 2);
        $raw = str_replace('=yend size=3 part=1 ', '=yend size=3 part=2 ', $raw);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid yEnc multipart range or part number.');
        $this->service()->decode($raw);
    }

    public function test_multipart_uses_part_size_and_part_checksum(): void
    {
        $article = "=ybegin part=2 total=3 line=128 size=9 name=multi.bin\r\n=ypart begin=4 end=6\r\nklm\r\n=yend size=3 part=2 pcrc32=a3830348 crc32=deadbeef";
        $this->assertSame('ABC', $this->service()->decode($article));
    }

    /** @return iterable<string, array{string}> */
    public static function corruptArticles(): iterable
    {
        yield 'size' => ["=ybegin line=128 size=9 name=x\nklm\n=yend size=3"];
        yield 'checksum' => ["=ybegin line=128 size=3 name=x\nklm\n=yend size=3 crc32=deadbeef"];
        yield 'invalid checksum' => ["=ybegin line=128 size=3 name=x\nklm\n=yend size=3 crc32=xyz"];
        yield 'missing trailer size' => ["=ybegin line=128 size=3 name=x\nklm\n=yend crc32=a3830348"];
        yield 'overflow' => ["=ybegin line=128 size=99999999999999999999999999 name=x\nklm\n=yend size=3"];
        yield 'dangling escape' => ["=ybegin line=128 size=3 name=x\nklm=\n=yend size=3"];
        yield 'missing range' => ["=ybegin part=1 line=128 size=9 name=x\nklm\n=yend size=3 part=1 pcrc32=a3830348"];
        yield 'reversed range' => ["=ybegin part=1 line=128 size=9 name=x\n=ypart begin=6 end=4\nklm\n=yend size=3 part=1 pcrc32=a3830348"];
        yield 'part mismatch' => ["=ybegin part=1 line=128 size=9 name=x\n=ypart begin=1 end=3\nklm\n=yend size=3 part=2 pcrc32=a3830348"];
        yield 'range starts at zero' => ["=ybegin part=1 line=128 size=9 name=x\n=ypart begin=0 end=2\nklm\n=yend size=3 part=1 pcrc32=a3830348"];
        yield 'range exceeds file' => ["=ybegin part=1 line=128 size=2 name=x\n=ypart begin=1 end=3\nklm\n=yend size=3 part=1 pcrc32=a3830348"];
        yield 'part exceeds total' => ["=ybegin part=2 total=1 line=128 size=9 name=x\n=ypart begin=1 end=3\nklm\n=yend size=3 part=2 pcrc32=a3830348"];
        yield 'total mismatch' => ["=ybegin part=1 total=3 line=128 size=9 name=x\n=ypart begin=1 end=3\nklm\n=yend size=3 part=1 total=2 pcrc32=a3830348"];
        yield 'trailer part without header' => ["=ybegin line=128 size=3 name=x\nklm\n=yend size=3 part=1 pcrc32=a3830348"];
        yield 'missing part crc' => ["=ybegin part=1 line=128 size=9 name=x\n=ypart begin=1 end=3\nklm\n=yend size=3 part=1 crc32=a3830348"];
        yield 'bad part crc' => ["=ybegin part=1 line=128 size=9 name=x\n=ypart begin=1 end=3\nklm\n=yend size=3 part=1 pcrc32=deadbeef crc32=a3830348"];
    }

    #[DataProvider('corruptArticles')]
    public function test_strict_rejects_corruption_while_tolerant_recovers(string $article): void
    {
        $original = $article;
        $this->assertSame('ABC', $this->service()->decodeIgnore($article));
        $this->expectException(RuntimeException::class);
        $this->service()->decode($original);
    }

    /** @return iterable<string, array{string}> */
    public static function incompleteArticles(): iterable
    {
        yield 'plain' => ['ordinary text'];
        yield 'header only' => ["=ybegin line=128 size=3 name=x\nklm"];
        yield 'quoted header' => ["quoted =ybegin line=128 size=3 name=x\nklm\n=yend size=3"];
        yield 'discussion' => ["=ybegin is an example\nabc\n=yend is the end"];
        yield 'wrong control prefix' => ["=ybeginning line=128 size=3 name=x\nklm\n=yend size=3"];
    }

    #[DataProvider('incompleteArticles')]
    public function test_unrecognized_input_is_preserved(string $article): void
    {
        $original = $article;
        $service = $this->service();
        $this->assertFalse($service->isYencEncoded($article));
        $this->assertNull($service->extractMetadata($article));
        $this->assertFalse($service->decode($article));
        $this->assertSame($original, $service->decodeIgnore($article));
        $this->assertSame($original, $article);
    }

    public function test_zero_padded_crc_and_case_are_accepted(): void
    {
        $article = "=YBEGIN line=128 size=0 name=empty\n\n=YEND size=0 crc32=00000000";
        $this->assertSame('', $this->service()->decode($article));
        $article = str_replace('00000000', '0', $article);
        $this->assertSame('', $this->service()->decode($article));
    }

    public function test_consecutive_escape_markers_are_decoded_in_pairs(): void
    {
        $article = "=ybegin line=128 size=2 name=x\n====\n=yend size=2";
        $this->assertSame("\xd3\xd3", $this->service()->decode($article));
    }

    public function test_escape_marker_before_line_end_decodes_consistently(): void
    {
        foreach (["\r\n", "\n"] as $eol) {
            $article = '=ybegin line=128 size=4 name=x'.$eol.'klm='.$eol.'M'.$eol.'=yend size=4';
            $this->assertSame("ABC\xe3", $this->service()->decode($article));
        }
    }

    public function test_escape_before_bare_carriage_return_preserves_tolerant_recovery(): void
    {
        $article = "=ybegin line=128 size=4 name=x\nklm=\rM\n=yend size=4";
        $service = $this->service();
        $this->assertSame("ABC\xe3", $service->decode($article));
        $this->assertSame("ABC\xe3", $service->decodeIgnore($article));
    }

    public function test_trailing_escape_pairs_can_span_line_endings(): void
    {
        foreach (["=\n=", "==\r=\n=", "=\r\n=\n=\r="] as $payload) {
            $expected = str_repeat("\xd3", intdiv(substr_count($payload, '='), 2));
            $article = '=ybegin line=128 size='.strlen($expected)." name=x\n".$payload
                ."\n=yend size=".strlen($expected);
            $service = $this->service();
            $this->assertSame($expected, $service->decode($article));
            $this->assertSame($expected, $service->decodeIgnore($article));
        }
    }

    public function test_strict_rejects_unmatched_escape_even_when_recovered_size_matches(): void
    {
        $article = "=ybegin line=128 size=1 name=x\n=\n==\n=yend size=1";
        $service = $this->service();
        $original = $article;
        $this->assertSame("\xd3", $service->decodeIgnore($article));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unmatched yEnc escape marker');
        $service->decode($original);
    }

    public function test_tolerant_dangling_escape_does_not_reach_native_backend(): void
    {
        $decoder = new class implements PayloadDecoder
        {
            public function decode(string $payload): string
            {
                throw new RuntimeException('Backend must not receive an unmatched escape');
            }
        };
        $article = "=ybegin line=128 size=2 name=x\n====k=\n=yend size=2";
        $this->assertSame("\xd3\xd3A", (new YencService($decoder))->decodeIgnore($article));
    }

    public function test_false_control_prefixes_and_truncated_previous_frame_are_skipped(): void
    {
        $article = "=ybeginning line=128 size=3 name=x\n=ybegin line=128 size=9 name=truncated\n"
            .YencArticles::article('ABC')."\nignored suffix";
        $this->assertSame('ABC', $this->service()->decode($article));
    }

    public function test_body_control_prefix_does_not_terminate_the_frame(): void
    {
        $article = "=ybegin line=128 size=5 name=x\n=yendx\n=yend size=5";
        $expected = chr(121 - 106).chr(101 - 42).chr(110 - 42).chr(100 - 42).chr(120 - 42);
        $this->assertSame($expected, $this->service()->decode($article));
    }

    public function test_encoder_rejects_filename_line_injection(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service()->encode('ABC', "name\n=yend");
    }

    public function test_encoder_preserves_escape_pairs_at_all_line_lengths(): void
    {
        foreach ([1, 2, 3, 127, 128, 254, 300] as $length) {
            $data = str_repeat(YencArticles::bytes(), 3);
            $article = $this->service()->encode($data, 'binary file', $length);
            $lines = explode("\r\n", $article);
            foreach (array_slice($lines, 1, -1) as $line) {
                $this->assertFalse(str_ends_with($line, '='));
                $this->assertLessThanOrEqual(min($length, 254) + 1, strlen($line));
            }
            $this->assertSame($data, $this->service()->decode($article));
        }
        $this->assertStringEndsWith('crc32=00000000', $this->service()->encode('', 'empty'));
    }
}
