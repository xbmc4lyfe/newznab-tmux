<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdditionalProcessing\DTO\MediaSourceHints;
use App\Services\AdditionalProcessing\Enums\VideoContainer;
use App\Services\AdditionalProcessing\Media\FrameQualityScorer;
use App\Services\AdditionalProcessing\Media\ImageInspector;
use App\Services\AdditionalProcessing\Media\ThumbnailFrameSelector;
use App\Services\AdditionalProcessing\Media\VideoFrameGrabber;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;
use Tests\Unit\AdditionalProcessing\CreatesProcessingConfiguration;

/**
 * Parity and benchmark suite for Wave 8 Slice 8.5 media exports in `rust/pipeline-ffi/src/media.rs`:
 * - `nntmux_image_is_complete_tail` & `nntmux_image_is_complete_file` (`ImageInspector`)
 * - `nntmux_frame_luma_stats_rgb` & `nntmux_frame_luma_stats_bmp` (`FrameQualityScorer`)
 * - `nntmux_thumbnail_looks_like_text` & `nntmux_thumbnail_window` (`ThumbnailFrameSelector`)
 *
 * Paired randomized benchmark (`WAVE8_IMAGE_FRAME_BENCHMARK=1 php artisan test --compact
 * tests/Unit/RustImageInspectorFrameScorerParityTest.php --filter=benchmark`):
 * - `FrameQualityScorer::measureRgb`: ~32x mean / ~25x p99 (64x36 common and 64x64 uncommon)
 *   -> ADOPTED in production.
 * - `FrameQualityScorer::measureSample`: ~4.5x mean / ~3.4x p99 (64x36 common) and ~6.4x mean /
 *   ~19x p99 (64x64 uncommon) -> ADOPTED in production.
 * - `FrameQualityScorer::measure` (full JPEG decode + scale + sample score),
 *   `ImageInspector::isComplete` / `isCompleteTail`, `ThumbnailFrameSelector::looksLikeText`, and
 *   `ThumbnailFrameSelector::window` measure below 1.5x on common workloads because libgd
 *   decode/scale, kernel VFS syscalls, `strncasecmp`, or 3-op float math dominate; they retain
 *   native PHP in production and expose `*Native` helpers for parity.
 */
final class RustImageInspectorFrameScorerParityTest extends TestCase
{
    use CreatesProcessingConfiguration;

    private const string CDEF = <<<'C'
        int32_t nntmux_image_is_complete_tail(uint32_t type_code, uint64_t file_size, const char *head_or_tail_ptr, size_t head_or_tail_len);
        int32_t nntmux_image_is_complete_file(const char *path_ptr, size_t path_len, uint32_t type_code);
        int32_t nntmux_frame_luma_stats_rgb(const char *rgb_bytes_ptr, size_t pixel_count, double *out_mean, double *out_stddev);
        int32_t nntmux_frame_luma_stats_bmp(const char *bmp_ptr, size_t bmp_len, double *out_mean, double *out_stddev);
        int32_t nntmux_thumbnail_looks_like_text(const char *head_ptr, size_t head_len);
        int32_t nntmux_thumbnail_window(uint32_t position_is_mid, double duration, uint32_t has_duration, int64_t bit_rate, uint32_t has_bit_rate, int64_t available_bytes, int64_t total_bytes, uint32_t has_hints, int32_t attempts, double *out_from, double *out_spacing);
        C;

    private const int SEED = 20261024;

    private string $tmpPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpPath = sys_get_temp_dir().'/rust-image-frame-parity-'.uniqid('', true).'/';
        (new Filesystem)->makeDirectory($this->tmpPath, 0777, true, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->tmpPath);
        parent::tearDown();
    }

    private function ffi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('image_frame_parity_test', self::CDEF);
        if ($ffi === null) {
            self::markTestSkipped('Rust media image/frame/thumbnail FFI exports are unavailable.');
        }

        return $ffi;
    }

    /**
     * @return array<string, array{int, int, string, bool}>
     */
    public static function imageTailFixtures(): array
    {
        $webp24 = 'RIFF'.pack('V', 24).'WEBPVP8 '.str_repeat('x', 16);

        return [
            'webp exact declared size' => [IMAGETYPE_WEBP, 32, $webp24, true],
            'webp larger than declared size' => [IMAGETYPE_WEBP, 48, $webp24, true],
            'webp 1 byte short of declared size' => [IMAGETYPE_WEBP, 31, $webp24, false],
            'webp file size under 16 bytes' => [IMAGETYPE_WEBP, 15, $webp24, false],
            'webp header shorter than 8 bytes' => [IMAGETYPE_WEBP, 32, 'RIFF12', false],
            'png IEND at end' => [IMAGETYPE_PNG, 200, str_repeat('p', 120)."IEND\xAE\x42\x60\x82", true],
            'png IEND followed by rtrimmed bytes' => [IMAGETYPE_PNG, 200, str_repeat('p', 120)."IEND\xAE\x42\x60\x82\0\r\n  ", true],
            'png IEND exactly 64 bytes from rtrimmed end' => [IMAGETYPE_PNG, 200, 'IEND'.str_repeat('x', 60)."\r\n ", true],
            'png IEND 65 bytes from rtrimmed end' => [IMAGETYPE_PNG, 200, 'IEND'.str_repeat('x', 61), false],
            'png tab not rtrimmed' => [IMAGETYPE_PNG, 200, 'IEND'.str_repeat('x', 60)."\t", false],
            'png file size under 16 bytes' => [IMAGETYPE_PNG, 15, str_repeat('p', 20).'IEND', false],
            'jpeg EOI at end' => [IMAGETYPE_JPEG, 500, str_repeat('j', 200)."\xFF\xD9", true],
            'jpeg EOI followed by rtrimmed whitespace and NULs' => [IMAGETYPE_JPEG, 500, str_repeat('j', 200)."\xFF\xD9 \0\r\n", true],
            'jpeg EOI exactly 1024 bytes from rtrimmed end' => [IMAGETYPE_JPEG, 2000, "\xFF\xD9".str_repeat('j', 1022).'  ', true],
            'jpeg EOI 1025 bytes from rtrimmed end' => [IMAGETYPE_JPEG, 2000, "\xFF\xD9".str_repeat('j', 1023), false],
            'jpeg missing EOI' => [IMAGETYPE_JPEG, 500, str_repeat("\xFF\xD8", 100), false],
            'jpeg file size under 16 bytes' => [IMAGETYPE_JPEG, 12, str_repeat('j', 20)."\xFF\xD9", false],
        ];
    }

    #[DataProvider('imageTailFixtures')]
    public function test_image_is_complete_tail_and_file_parity(int $type, int $fileSize, string $headOrTail, bool $expected): void
    {
        $this->ffi();
        $supported = false;
        self::assertSame($expected, ImageInspector::isCompleteTailPhp($type, $fileSize, $headOrTail));
        self::assertSame($expected, ImageInspector::isCompleteTailNative($type, $fileSize, $headOrTail, $supported));
        self::assertTrue($supported);

        // Also test file-backed isComplete / isCompleteNative when buffer represents a full file of $fileSize
        $inspector = new ImageInspector(new FrameQualityScorer(12, 24, 232), 12);
        $filePath = $this->tmpPath.'fixture.bin';
        $fileBytes = strlen($headOrTail) <= $fileSize
            ? ($type === IMAGETYPE_WEBP
                ? str_pad($headOrTail, $fileSize, 'x')
                : str_repeat('a', $fileSize - strlen($headOrTail)).$headOrTail)
            : substr($headOrTail, 0, $fileSize);
        file_put_contents($filePath, $fileBytes);

        $fileSupported = false;
        $phpFileResult = $inspector->isCompletePhp($filePath, $type);
        self::assertSame($phpFileResult, $inspector->isComplete($filePath, $type));
        self::assertSame($phpFileResult, $inspector->isCompleteNative($filePath, $type, $fileSupported));
        self::assertTrue($fileSupported);
    }

    public function test_image_inspector_is_usable_and_native_parity(): void
    {
        $this->ffi();
        $scorer = new FrameQualityScorer(12, 24, 232);
        $inspector = new ImageInspector($scorer, 12);

        $detailed = $this->writeJpeg('detailed.jpg', 320, 240, static fn (int $x, int $y): int => (($x * 7 + $y * 13) % 255) * 0x010101);
        $blank = $this->writeJpeg('blank.jpg', 320, 240, static fn (): int => 0x202020);
        $icon = $this->writeJpeg('icon.jpg', 64, 64, static fn (int $x, int $y): int => (($x * 11 + $y * 5) % 255) * 0x010101);
        $truncated = $this->tmpPath.'truncated.jpg';
        file_put_contents($truncated, substr((string) file_get_contents($detailed), 0, -1500));
        $notImage = $this->tmpPath.'not-image.jpg';
        file_put_contents($notImage, 'plain text');

        foreach ([$detailed => true, $blank => false, $icon => false, $truncated => false, $notImage => false] as $path => $expected) {
            $supported = false;
            self::assertSame($expected, $inspector->isUsable($path), basename($path).' isUsable');
            self::assertSame($expected, $inspector->isUsableNative($path, $supported), basename($path).' isUsableNative');
            self::assertTrue($supported, basename($path).' supported');
        }
    }

    public function test_frame_quality_scorer_bit_exact_parity_across_dimensions_and_frames(): void
    {
        $this->ffi();
        $random = new Randomizer(new Mt19937(self::SEED));
        $scorer = new FrameQualityScorer(12, 24, 232);

        foreach ([[64, 36], [64, 48], [64, 64], [63, 35], [17, 29], [1, 1]] as [$w, $h]) {
            $sample = imagecreatetruecolor($w, $h);
            $rgbBytes = '';
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $r = $random->getInt(0, 255);
                    $g = $random->getInt(0, 255);
                    $b = $random->getInt(0, 255);
                    imagesetpixel($sample, $x, $y, ($r << 16) | ($g << 8) | $b);
                    $rgbBytes .= chr($r).chr($g).chr($b);
                }
            }

            $phpSample = FrameQualityScorer::measureSamplePhp($sample);
            $sampleSupported = false;
            $nativeSample = FrameQualityScorer::measureSample($sample, $sampleSupported);
            self::assertTrue($sampleSupported, "{$w}x{$h} sample supported");
            self::assertSame($phpSample, $nativeSample, "{$w}x{$h} measureSample bit-identical");

            $phpRgb = FrameQualityScorer::measureRgbPhp($rgbBytes);
            $rgbSupported = false;
            $nativeRgb = FrameQualityScorer::measureRgb($rgbBytes, $rgbSupported);
            self::assertTrue($rgbSupported, "{$w}x{$h} rgb supported");
            self::assertSame($phpRgb, $nativeRgb, "{$w}x{$h} measureRgb bit-identical");
            self::assertSame($phpSample, $nativeRgb, "{$w}x{$h} sample vs rgb bit-identical");
        }

        self::assertNull(FrameQualityScorer::measureRgb(''));
        self::assertNull(FrameQualityScorer::measureRgb('ab'));

        $black = $this->writePng('black.png', 64, 48, static fn (): int => 0);
        $dark = $this->writePng('dark.png', 64, 48, static fn (int $x, int $y): int => ($x + $y) % 2 === 0 ? 0x000000 : 0x2A2A2A);
        $detailed = $this->writeJpeg('detailed_frame.jpg', 320, 180, static fn (int $x, int $y): int => (($x * 7 + $y * 13) % 255) * 0x010101);

        foreach ([$black, $dark, $detailed, $this->tmpPath.'missing.jpg'] as $path) {
            $supported = false;
            self::assertSame($scorer->measurePhp($path), $scorer->measureNative($path, $supported));
            self::assertTrue($supported);
        }

        $bestSupported = false;
        self::assertSame(
            $scorer->best([$black, $dark, $detailed]),
            $scorer->bestNative([$black, $dark, $detailed], $bestSupported),
        );
        self::assertTrue($bestSupported);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function looksLikeTextFixtures(): array
    {
        return [
            'plain extm3u' => ["#EXTM3U\n#EXT-X-VERSION:3\n", true],
            'utf8 bom and whitespace extm3u lowercase' => ["\xEF\xBB\xBF \t\r\n#extm3u\nfile:///etc/passwd", true],
            'repeated partial bom bytes trimmed by byte mask' => ["\xBF\xEF\xBB \n#ExTm3U", true],
            'ffconcat header' => ["  \r\n\tffconcat version 1.0\nfile 'a.mkv'\n", true],
            'ffconcat uppercase' => ['FFCONCAT version 1.0', true],
            'extm3u not at start after non-whitespace' => ["x#EXTM3U\n", false],
            'ffconcat after NUL byte' => ["\0ffconcat version 1.0", false],
            'matroska magic' => ["\x1A\x45\xDF\xA3".str_repeat("\0", 32), false],
            'empty string' => ['', false],
            'only bom and whitespace' => ["\xEF\xBB\xBF \t\r\n", false],
            'truncated #EXTM3' => ['#EXTM3', false],
            'truncated ffconca' => ['ffconca', false],
        ];
    }

    #[DataProvider('looksLikeTextFixtures')]
    public function test_thumbnail_looks_like_text_parity(string $head, bool $expected): void
    {
        $this->ffi();
        $supported = false;
        self::assertSame($expected, ThumbnailFrameSelector::looksLikeTextPhp($head));
        self::assertSame($expected, ThumbnailFrameSelector::looksLikeTextNative($head, $supported));
        self::assertSame($expected, ThumbnailFrameSelector::looksLikeText($head));
        self::assertTrue($supported);
    }

    public function test_thumbnail_window_and_select_randomized_parity(): void
    {
        $this->ffi();
        $random = new Randomizer(new Mt19937(self::SEED));
        $video = $this->tmpPath.'video.mkv';
        file_put_contents($video, "\x1A\x45\xDF\xA3".str_repeat('v', 4096));

        for ($case = 0; $case < 500; $case++) {
            $duration = $random->getInt(0, 3) === 0 ? null : $random->getInt(1, 720000) / 100.0;
            $bitRate = $random->getInt(0, 3) === 0 ? null : $random->getInt(1000, 50_000_000);
            $attempts = $random->getInt(1, 8);
            $hints = match ($random->getInt(0, 4)) {
                0 => null,
                1 => MediaSourceHints::complete(),
                2 => MediaSourceHints::mid($random->getInt(0, 5_000_000)),
                3 => MediaSourceHints::head($random->getInt(10_000, 100_000_000), $random->getInt(0, 50_000_000)),
                default => MediaSourceHints::head(null, $random->getInt(0, 50_000_000)),
            };

            $config = $this->makeConfig(['thumbnailFrameAttempts' => $attempts]);
            $grabber = new ParityFakeFrameGrabber($duration, $bitRate);
            $selector = new ThumbnailFrameSelector($config, $grabber, new FrameQualityScorer(12, 24, 232));

            $supported = false;
            $expected = $selector->windowPhp($video, VideoContainer::Matroska, $hints);
            $actual = $selector->windowNative($video, VideoContainer::Matroska, $hints, $supported);
            self::assertTrue($supported, "window case #{$case}");
            self::assertSame($expected, $actual, "window case #{$case}");
        }

        // Explicit regression for head(realSize > 0, availableBytes <= 0) with non-empty file on disk
        foreach ([null, 8000] as $bitRate) {
            $selector = new ThumbnailFrameSelector(
                $this->makeConfig(['thumbnailFrameAttempts' => 4]),
                new ParityFakeFrameGrabber(120.0, $bitRate),
                new FrameQualityScorer(12, 24, 232),
            );
            $hints = MediaSourceHints::head(100_000, 0);
            $supported = false;
            self::assertSame(
                $selector->windowPhp($video, VideoContainer::Matroska, $hints),
                $selector->windowNative($video, VideoContainer::Matroska, $hints, $supported),
            );
            self::assertTrue($supported);
        }

        // Verify select() vs selectNative() with fallback retry window
        $black = $this->writePng('sel_black.png', 64, 48, static fn (): int => 0);
        $detailed = $this->writePng('sel_detailed.png', 64, 48, static fn (int $x, int $y): int => (($x * 7 + $y * 13) % 255) * 0x010101);
        $config = $this->makeConfig();
        $grabber1 = new ParityFakeFrameGrabber(40.0, 8000, [[$black], [$detailed]]);
        $grabber2 = new ParityFakeFrameGrabber(40.0, 8000, [[$black], [$detailed]]);
        $scorer = new FrameQualityScorer(12, 24, 232);

        $sel1 = new ThumbnailFrameSelector($config, $grabber1, $scorer);
        $sel2 = new ThumbnailFrameSelector($config, $grabber2, $scorer);
        $supported = false;
        self::assertSame(
            $sel1->select($video, $this->tmpPath, MediaSourceHints::complete()),
            $sel2->selectNative($video, $this->tmpPath, MediaSourceHints::complete(), $supported),
        );
        self::assertTrue($supported);
        self::assertSame($grabber1->grabs, $grabber2->grabs);
    }

    public function test_c_abi_error_contracts_and_fallback_when_unavailable(): void
    {
        $ffi = $this->ffi();
        $out = $ffi->new('double[2]');
        $a = FFI::addr($out[0]);
        $b = FFI::addr($out[1]);

        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_image_is_complete_tail(99, 100, 'abcd', 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_image_is_complete_tail(IMAGETYPE_JPEG, 100, null, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_image_is_complete_file(null, 4, IMAGETYPE_JPEG));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_image_is_complete_file("a\0b", 3, IMAGETYPE_JPEG));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_frame_luma_stats_rgb(null, 1, $a, $b));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_frame_luma_stats_rgb('rgb', 0, $a, $b));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_frame_luma_stats_bmp('not-a-bmp', 9, $a, $b));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_thumbnail_looks_like_text(null, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_thumbnail_window(0, 10.0, 1, 0, 1, 100, 1000, 1, 4, $a, $b));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_thumbnail_window(0, 10.0, 1, 8000, 1, 100, 1000, 1, 0, $a, $b));

        foreach ([FrameQualityScorer::class, ImageInspector::class, ThumbnailFrameSelector::class] as $class) {
            $resolved = new ReflectionProperty($class, 'ffiResolved');
            $handle = new ReflectionProperty($class, 'ffi');
            $prev = [$resolved->getValue(), $handle->getValue()];
            $resolved->setValue(null, true);
            $handle->setValue(null, null);
            try {
                if ($class === FrameQualityScorer::class) {
                    $supported = true;
                    self::assertNotNull(FrameQualityScorer::measureRgb("\x00\x00\x00\xFF\xFF\xFF", $supported));
                    self::assertFalse($supported);
                } elseif ($class === ImageInspector::class) {
                    $supported = true;
                    self::assertTrue(ImageInspector::isCompleteTailNative(IMAGETYPE_JPEG, 100, str_repeat('j', 40)."\xFF\xD9", $supported));
                    self::assertFalse($supported);
                } else {
                    $supported = true;
                    self::assertTrue(ThumbnailFrameSelector::looksLikeTextNative("#EXTM3U\n", $supported));
                    self::assertFalse($supported);
                }
            } finally {
                $resolved->setValue(null, $prev[0]);
                $handle->setValue(null, $prev[1]);
            }
        }
    }

    /**
     * Opt-in paired randomized benchmark:
     * WAVE8_IMAGE_FRAME_BENCHMARK=1 php artisan test --compact tests/Unit/RustImageInspectorFrameScorerParityTest.php --filter=benchmark
     */
    public function test_paired_randomized_benchmark(): void
    {
        if (getenv('WAVE8_IMAGE_FRAME_BENCHMARK') !== '1') {
            self::markTestSkipped('Opt-in paired randomized benchmark.');
        }
        $this->ffi();
        $random = new Randomizer(new Mt19937(self::SEED));
        $pairs = max(100, min(2000, (int) (getenv('WAVE8_IMAGE_FRAME_BENCHMARK_PAIRS') ?: 400)));

        $binHead = "\x00\x00\x01\xBA".$random->getBytes(4092);
        $m3uHead = "\xEF\xBB\xBF  \r\n\t#EXTM3U\n".$random->getBytes(4070);
        $rgbCommon = $random->getBytes(64 * 36 * 3);
        $rgbUncommon = $random->getBytes(64 * 64 * 3);

        $sampleCommon = imagecreatetruecolor(64, 36);
        $sampleUncommon = imagecreatetruecolor(64, 64);
        for ($y = 0; $y < 64; $y++) {
            for ($x = 0; $x < 64; $x++) {
                $c = $random->getInt(0, 0xFFFFFF);
                if ($y < 36) {
                    imagesetpixel($sampleCommon, $x, $y, $c);
                }
                imagesetpixel($sampleUncommon, $x, $y, $c);
            }
        }

        $jpgPath = $this->writeJpeg('bench_400x300.jpg', 400, 300, static fn (int $x, int $y): int => (($x * 7 + $y * 13) % 255) * 0x010101);
        $scorer = new FrameQualityScorer(12, 24, 232);
        $inspector = new ImageInspector($scorer, 12);
        $selector = new ThumbnailFrameSelector(
            $this->makeConfig(),
            new ParityFakeFrameGrabber(100.0, 8000),
            $scorer,
        );
        $hints = MediaSourceHints::head(10000, 1000);

        $workloads = [
            'looks_like_text_binary_common' => [
                false,
                static function () use ($binHead): bool {
                    for ($i = 0; $i < 20; $i++) {
                        $r = ThumbnailFrameSelector::looksLikeTextPhp($binHead);
                    }

                    return $r;
                },
                static function () use ($binHead): bool {
                    for ($i = 0; $i < 20; $i++) {
                        $r = ThumbnailFrameSelector::looksLikeTextNative($binHead);
                    }

                    return $r;
                },
            ],
            'looks_like_text_playlist_uncommon' => [
                false,
                static function () use ($m3uHead): bool {
                    for ($i = 0; $i < 20; $i++) {
                        $r = ThumbnailFrameSelector::looksLikeTextPhp($m3uHead);
                    }

                    return $r;
                },
                static function () use ($m3uHead): bool {
                    for ($i = 0; $i < 20; $i++) {
                        $r = ThumbnailFrameSelector::looksLikeTextNative($m3uHead);
                    }

                    return $r;
                },
            ],
            'measure_rgb_64x36_common' => [
                true,
                static fn (): ?array => FrameQualityScorer::measureRgbPhp($rgbCommon),
                static fn (): ?array => FrameQualityScorer::measureRgbNative($rgbCommon),
            ],
            'measure_rgb_64x64_uncommon' => [
                true,
                static fn (): ?array => FrameQualityScorer::measureRgbPhp($rgbUncommon),
                static fn (): ?array => FrameQualityScorer::measureRgbNative($rgbUncommon),
            ],
            'measure_sample_64x36_common' => [
                true,
                static fn (): array => FrameQualityScorer::measureSamplePhp($sampleCommon),
                static fn (): array => FrameQualityScorer::measureSampleNative($sampleCommon),
            ],
            'measure_sample_64x64_uncommon' => [
                true,
                static fn (): array => FrameQualityScorer::measureSamplePhp($sampleUncommon),
                static fn (): array => FrameQualityScorer::measureSampleNative($sampleUncommon),
            ],
            'measure_full_jpeg_400x300' => [
                false,
                static fn (): ?array => $scorer->measurePhp($jpgPath),
                static fn (): ?array => $scorer->measureNative($jpgPath),
            ],
            'image_is_complete_file_jpeg' => [
                false,
                static fn (): bool => $inspector->isCompletePhp($jpgPath, IMAGETYPE_JPEG),
                static fn (): bool => $inspector->isCompleteNative($jpgPath, IMAGETYPE_JPEG),
            ],
            'thumbnail_window_head' => [
                false,
                static fn (): array => $selector->windowPhp($jpgPath, VideoContainer::Matroska, $hints),
                static fn (): array => $selector->windowNative($jpgPath, VideoContainer::Matroska, $hints),
            ],
        ];

        foreach ($workloads as $name => [$expectedAdopted, $phpFn, $rustFn]) {
            for ($w = 0; $w < 5; $w++) {
                self::assertSame($phpFn(), $rustFn(), $name.' warmup parity');
            }
            $phpTimes = [];
            $rustTimes = [];
            for ($pair = 0; $pair < $pairs; $pair++) {
                if ($random->getInt(0, 1) === 0) {
                    $t0 = hrtime(true);
                    $phpFn();
                    $phpTimes[] = (hrtime(true) - $t0) / 1e3;
                    $t1 = hrtime(true);
                    $rustFn();
                    $rustTimes[] = (hrtime(true) - $t1) / 1e3;
                } else {
                    $t1 = hrtime(true);
                    $rustFn();
                    $rustTimes[] = (hrtime(true) - $t1) / 1e3;
                    $t0 = hrtime(true);
                    $phpFn();
                    $phpTimes[] = (hrtime(true) - $t0) / 1e3;
                }
            }
            sort($phpTimes);
            sort($rustTimes);
            $p99Idx = (int) ceil($pairs * 0.99) - 1;
            $phpMean = array_sum($phpTimes) / $pairs;
            $rustMean = array_sum($rustTimes) / $pairs;
            $phpP99 = $phpTimes[$p99Idx];
            $rustP99 = $rustTimes[$p99Idx];
            $meanSpeedup = $phpMean / max(0.0001, $rustMean);
            $p99Speedup = $phpP99 / max(0.0001, $rustP99);

            fwrite(STDOUT, json_encode([
                'workload' => $name,
                'pairs' => $pairs,
                'seed' => self::SEED,
                'php_us' => ['mean' => round($phpMean, 3), 'p99' => round($phpP99, 3)],
                'rust_us' => ['mean' => round($rustMean, 3), 'p99' => round($rustP99, 3)],
                'speedup' => ['mean' => round($meanSpeedup, 2), 'p99' => round($p99Speedup, 2)],
                'adopted' => $expectedAdopted,
            ], JSON_THROW_ON_ERROR)."\n");

            if ($expectedAdopted) {
                self::assertGreaterThanOrEqual(1.5, $meanSpeedup, "{$name} mean speedup >= 1.5x");
                self::assertGreaterThanOrEqual(1.5, $p99Speedup, "{$name} p99 speedup >= 1.5x");
            }
        }
    }

    /**
     * @param  callable(int, int): int  $color
     */
    private function writeJpeg(string $name, int $width, int $height, callable $color): string
    {
        $image = imagecreatetruecolor($width, $height);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                imagesetpixel($image, $x, $y, $color($x, $y));
            }
        }
        $path = $this->tmpPath.$name;
        imagejpeg($image, $path, 90);

        return $path;
    }

    /**
     * @param  callable(int, int): int  $color
     */
    private function writePng(string $name, int $width, int $height, callable $color): string
    {
        $image = imagecreatetruecolor($width, $height);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                imagesetpixel($image, $x, $y, $color($x, $y));
            }
        }
        $path = $this->tmpPath.$name;
        imagepng($image, $path);

        return $path;
    }
}

final class ParityFakeFrameGrabber implements VideoFrameGrabber
{
    /** @var list<array{0: VideoContainer|null, 1: float, 2: float}> */
    public array $grabs = [];

    /**
     * @param  list<list<string>>  $frames
     */
    public function __construct(
        private readonly ?float $duration = null,
        private readonly ?int $bitRate = null,
        private array $frames = [],
    ) {}

    public function grab(string $videoPath, ?VideoContainer $container, float $from, float $spacing, int $count, string $outputDirectory): array
    {
        $this->grabs[] = [$container, $from, $spacing];

        return array_shift($this->frames) ?? [];
    }

    public function probe(string $videoPath, ?VideoContainer $container): array
    {
        return ['duration' => $this->duration, 'bitRate' => $this->bitRate];
    }
}
