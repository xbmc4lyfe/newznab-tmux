<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ReleaseImageService;
use GdImage;
use Illuminate\Support\Facades\File;
use Spatie\LaravelImageOptimizer\Facades\ImageOptimizer;
use Tests\TestCase;

class ReleaseImageServiceTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        config(['image.default' => 'gd']);

        $this->temporaryDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'release-image-'.uniqid('', true).DIRECTORY_SEPARATOR;
        File::makeDirectory($this->temporaryDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    public function test_it_converts_and_proportionally_resizes_a_local_image_to_jpeg(): void
    {
        $source = $this->createPng('source.png', 400, 200);
        $destination = $this->temporaryDirectory.'cover.jpg';

        ImageOptimizer::shouldReceive('optimize')->once()->with($destination);

        $result = (new ReleaseImageService)->saveImage('cover', $source, $this->temporaryDirectory, 100, 100);

        $this->assertSame(1, $result);
        $this->assertSame('image/jpeg', File::mimeType($destination));
        $this->assertImageDimensions($destination, 100, 50);
    }

    public function test_it_does_not_upscale_or_create_a_thumbnail_when_no_resize_occurs(): void
    {
        $source = $this->createPng('small.png', 40, 20);
        $destination = $this->temporaryDirectory.'cover.jpg';

        ImageOptimizer::shouldReceive('optimize')->once()->with($destination);

        $result = (new ReleaseImageService)->saveImage('cover', $source, $this->temporaryDirectory, 250, 250, true);

        $this->assertSame(1, $result);
        $this->assertImageDimensions($destination, 40, 20);
        $this->assertFileDoesNotExist($this->temporaryDirectory.'cover_thumb.jpg');
    }

    public function test_it_preserves_the_existing_small_dimension_resize_threshold(): void
    {
        $source = $this->createPng('short.png', 400, 20);
        $destination = $this->temporaryDirectory.'cover.jpg';

        ImageOptimizer::shouldReceive('optimize')->once()->with($destination);

        $result = (new ReleaseImageService)->saveImage('cover', $source, $this->temporaryDirectory, 100, 100);

        $this->assertSame(1, $result);
        $this->assertImageDimensions($destination, 400, 20);
    }

    public function test_it_writes_and_optimizes_the_main_image_and_thumbnail(): void
    {
        $source = $this->createPng('source.png', 200, 100);
        $destination = $this->temporaryDirectory.'cover.jpg';
        $thumbnail = $this->temporaryDirectory.'cover_thumb.jpg';

        ImageOptimizer::shouldReceive('optimize')->once()->with($thumbnail);
        ImageOptimizer::shouldReceive('optimize')->once()->with($destination);

        $result = (new ReleaseImageService)->saveImage('cover', $source, $this->temporaryDirectory, 100, 100, true);

        $this->assertSame(1, $result);
        $this->assertImageDimensions($destination, 100, 50);
        $this->assertImageDimensions($thumbnail, 100, 50);
    }

    public function test_it_returns_zero_for_empty_missing_invalid_and_unwritable_inputs(): void
    {
        ImageOptimizer::shouldReceive('optimize')->never();

        $service = new ReleaseImageService;

        $this->assertSame(0, $service->saveImage('empty', '', $this->temporaryDirectory));
        $this->assertSame(0, $service->saveImage('missing', $this->temporaryDirectory.'missing.png', $this->temporaryDirectory));

        $invalid = $this->temporaryDirectory.'invalid.png';
        File::put($invalid, 'not an image');
        $this->assertSame(0, $service->saveImage('invalid', $invalid, $this->temporaryDirectory));

        $source = $this->createPng('source.png', 100, 50);
        $notDirectory = $this->temporaryDirectory.'not-a-directory';
        File::put($notDirectory, 'file');
        $this->assertSame(0, $service->saveImage('unwritable', $source, $notDirectory.DIRECTORY_SEPARATOR));
    }

    private function createPng(string $filename, int $width, int $height): string
    {
        $path = $this->temporaryDirectory.$filename;
        $image = imagecreatetruecolor($width, $height);

        $this->assertInstanceOf(GdImage::class, $image);

        $color = imagecolorallocate($image, 50, 100, 150);
        imagefill($image, 0, 0, $color);
        imagepng($image, $path);

        return $path;
    }

    private function assertImageDimensions(string $path, int $width, int $height): void
    {
        $dimensions = getimagesize($path);

        $this->assertIsArray($dimensions);
        $this->assertSame($width, $dimensions[0]);
        $this->assertSame($height, $dimensions[1]);
    }
}
