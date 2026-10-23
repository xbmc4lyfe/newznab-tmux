<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdditionalProcessing\ReleaseFileManager;
use App\Services\NameFixing\Extractors\NfoNameExtractor;
use App\Services\NfoService;
use App\Support\RustPipelineLibrary;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class NfoServiceRustParityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertTrue(RustPipelineLibrary::available(), 'Rust pipeline FFI library must be available.');
    }

    public function test_cp437_to_utf8_matches_iconv_for_all_256_bytes_and_mixed_nfos(): void
    {
        for ($b = 0; $b < 256; $b++) {
            $byte = \chr($b);
            $expected = (string) iconv('CP437', 'UTF-8//IGNORE//TRANSLIT', $byte);
            $actual = RustPipelineLibrary::cp437ToUtf8($byte);
            self::assertSame(
                bin2hex($expected),
                bin2hex((string) $actual),
                sprintf('CP437 mismatch at byte 0x%02X', $b)
            );
        }

        $allBytes = '';
        for ($b = 1; $b < 256; $b++) {
            $allBytes .= \chr($b);
        }
        self::assertSame(
            (string) iconv('CP437', 'UTF-8//IGNORE//TRANSLIT', $allBytes),
            RustPipelineLibrary::cp437ToUtf8($allBytes)
        );
        self::assertSame('', RustPipelineLibrary::cp437ToUtf8(''));
        self::assertSame('Pure ASCII text 12345', RustPipelineLibrary::cp437ToUtf8('Pure ASCII text 12345'));
    }

    public function test_normalize_nfo_encoding_matches_php_reference(): void
    {
        $refClass = new ReflectionClass(ReleaseFileManager::class);
        $instance = $refClass->newInstanceWithoutConstructor();
        $nativeMethod = new ReflectionMethod(ReleaseFileManager::class, 'normalizeNfoEncoding');
        $phpMethod = new ReflectionMethod(ReleaseFileManager::class, 'normalizeNfoEncodingPhp');

        $corpus = [
            '',
            'Plain ASCII NFO content',
            "UTF-8 with box drawing ┌─┐ and accents Rosalía\n",
            "\xEF\xBB\xBFUTF-8 with BOM and accents éèê\n",
            "\xEF\xBB\xBF\xDA\xC4\xBF CP437 after UTF-8 BOM\r\n",
            "\xDA\xC4\xC4\xBF\r\n\xB3 Scene NFO \xB0\xB1\xB2\xDB \xB3\r\n\xC0\xC4\xC4\xD9\r\n",
            "\xFF\xFE".mb_convert_encoding("UTF-16LE NFO Title\n", 'UTF-16LE', 'UTF-8'),
            "\xFE\xFF".mb_convert_encoding("UTF-16BE NFO Title\n", 'UTF-16BE', 'UTF-8'),
        ];

        foreach ($corpus as $index => $input) {
            self::assertSame(
                $phpMethod->invoke($instance, $input),
                $nativeMethod->invoke($instance, $input),
                "normalizeNfoEncoding mismatch at index {$index}"
            );
        }
    }

    public function test_clean_nfo_content_matches_php_reference(): void
    {
        $native = new NfoService;
        $php = new NfoService('/nonexistent/libnntmux_pipeline.dylib');

        $corpus = [
            '',
            "\n",
            "\r\n\r\n\r\n\r\n",
            "Line 1   \t  \r\nLine 2\r\r\r\rLine 3  \x0B\x00 \n\n\n\nLine 4",
            "\xDA\xC4\xBF   \r\n\xB3  \xB0\xB1\xB2\xDB  SCENE GROUP  \xDB\xB2\xB1\xB0  \xB3\t \r\n\xC0\xC4\xD9   \r\n\r\n\r\n\r\nRelease: Test.2026-GRP   ",
            "   \t  \r\n   \r\n   \nActual content   \n   ",
        ];

        foreach ($corpus as $index => $input) {
            self::assertSame(
                $php->cleanNfoContentPhp($input),
                $native->cleanNfoContent($input),
                "cleanNfoContent mismatch at index {$index}"
            );
        }
    }

    public function test_preconditions_binary_detection_and_content_validation_match_php_reference(): void
    {
        $native = new NfoService;
        $php = new NfoService('/nonexistent/libnntmux_pipeline.dylib');

        $detectNative = new ReflectionMethod(NfoService::class, 'detectBinaryFormat');
        $detectPhp = new ReflectionMethod(NfoService::class, 'detectBinaryFormatPhp');
        $validateNative = new ReflectionMethod(NfoService::class, 'validateNfoContent');
        $validatePhp = new ReflectionMethod(NfoService::class, 'validateNfoContentPhp');
        $headerProp = (new ReflectionClass(NfoService::class))->getProperty('_nonNfoHeaderRegex');
        $binaryCharsProp = (new ReflectionClass(NfoService::class))->getProperty('_binaryCharsRegex');
        $headerRegex = (string) $headerProp->getValue($php);
        $binaryCharsRegex = (string) $binaryCharsProp->getValue($php);

        $samples = [
            '',
            'short',
            "\x50\x4B\x03\x04zip archive content",
            "\x52\x61\x72\x21\x1A\x07\x00rar archive",
            "\x37\x7A\xBC\xAF\x27\x1C7z archive",
            "\x1F\x8B\x08gzip stream",
            "\x89PNG\r\n\x1a\npng image",
            "\xFF\xD8\xFF\xE0\x00\x10JFIFjpeg image",
            '<?xml version="1.0"?><nzb></nzb>',
            "  \r\n\t <?XML version=\"1.0\"?>",
            '=newz[NZB]= sample header',
            "RIFF\x00\x00\x00\x00WAVEfmt ",
            "  PAR2\x00PKT",
            "\x00\x00\x00\x18ftypmp42",
            "\x1A\x45\xDF\xA3\x93\x42\x82\x88matroska",
            "ID3\x03\x00\x00\x00\x00\x0F",
            "Header comment\r\n; Generated by QuickSFV v2.36\r\nfile.rar DEADBEEF\r\n",
            "\xFF\xFEUTF-16 BOM text that is not binary magic",
            "\xFE\xFFUTF-16 BE BOM text",
            "Valid text with binary control char \x07 bell inside",
            str_repeat("\xB0\xB1\xB2", 30).' too few printable ASCII chars',
            str_repeat('12345 67890 !@#$% ', 5).' enough length and printable but fewer than five words',
            "============================================================\nRelease : Example.Movie.2026.1080p.BluRay.x264-GRP\nDate    : 2026-10-23\nVideo   : x264 1080p\nAudio   : DTS\nhttps://www.imdb.com/title/tt1234567/\n",
            'Just some normal prose words without scene keywords but with https://www.imdb.com/title/tt7654321/ link inside.',
            "FieldOne: ValueOne\nFieldTwo: ValueTwo\nFieldThree: ValueThree\nFieldFour: ValueFour\nFieldFive: ValueFive\nFieldSix: ValueSix\n",
            'Plain prose paragraph with more than fifty characters and more than five words but zero scene indicators at all',
        ];

        foreach ($samples as $index => $sample) {
            self::assertSame(
                $detectPhp->invoke($php, $sample),
                $detectNative->invoke($native, $sample),
                "detectBinaryFormat mismatch at sample {$index}"
            );

            $flags = RustPipelineLibrary::nfoCheckPreconditions($sample);
            self::assertNotNull($flags);
            self::assertSame(
                (bool) preg_match($headerRegex, $sample),
                (($flags & 1) !== 0),
                "_nonNfoHeaderRegex mismatch at sample {$index}"
            );
            self::assertSame(
                (bool) preg_match($binaryCharsRegex, $sample),
                (($flags & 4) !== 0),
                "_binaryCharsRegex mismatch at sample {$index}"
            );

            self::assertSame(
                $validatePhp->invoke($php, $sample),
                $validateNative->invoke($native, $sample),
                "validateNfoContent mismatch at sample {$index}"
            );
        }
    }

    public function test_nfo_identity_text_matches_php_reference(): void
    {
        $native = new NfoNameExtractor;
        $php = new NfoNameExtractor('/nonexistent/libnntmux_pipeline.dylib');

        $corpus = [
            '',
            "Filename : Example.Movie.2026.1080p.BluRay.x264-TARGET\n",
            "Source : Example.Movie.2026.1080p.BluRay.x264-SOURCE\n",
            "Source : Example.Movie.2026.1080p.BluRay.x264-SOURCE\nFilename : Example.Movie.2026.1080p.BluRay.x264-TARGET\n",
            "Source :\n    Example.Show.S01E02.1080p.HDTV.x264-SOURCE\n    Filename : Example.Show.S01E02.1080p.HDTV.x264-TARGET\n",
            "  sOuRcE : Example.Movie.2026.1080p.BluRay.x264-SOURCE\r\n",
            "Source : Example.Movie.2026.1080p.BluRay.x264-SOURCE\n\n    Source.Code.Movie.2026.1080p.BluRay.x264-TARGET\n",
            "Source :\n    C:\\incoming\\Example.Show.S01E02.1080p.HDTV.x264-SOURCE.mkv\n    Filename : D:\\output\\Example.Show.S01E02.1080p.HDTV.x264-TARGET.mkv\n",
            "Source :\r\n\t/mnt/incoming/Upstream.Source.mkv\r\n\thttps://example.com/info\r\nRelease Name : Proper.Release.2026-GRP\r\n",
        ];

        foreach ($corpus as $index => $nfo) {
            self::assertSame(
                $php->identityTextPhp($nfo),
                $native->identityText($nfo),
                "identityText mismatch at index {$index}"
            );
        }
    }
}
