<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\CoverController;
use GdImage;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class CoverControllerTest extends TestCase
{
    /** @var list<string> */
    private array $createdFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    protected function tearDown(): void
    {
        File::delete($this->createdFiles);

        parent::tearDown();
    }

    public function test_webp_request_falls_back_to_storage_backed_jpeg(): void
    {
        $name = 'fallback-'.uniqid();
        $this->createImage(storage_path('covers/preview/'.$name.'.jpg'), 'jpg');

        $response = (new CoverController)->show('preview', $name.'.webp');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
    }

    public function test_legacy_jpeg_request_falls_back_to_storage_backed_webp(): void
    {
        $name = 'fallback-'.uniqid();
        $this->createImage(storage_path('covers/sample/'.$name.'.webp'), 'webp');

        $response = (new CoverController)->show('sample', $name.'.jpg');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/webp', $response->headers->get('Content-Type'));
    }

    public function test_missing_public_extension_falls_back_without_moving_the_asset(): void
    {
        $name = 'public-'.uniqid();
        $path = public_path('covers/movies/'.$name.'-cover.jpg');
        $this->createImage($path, 'jpg');

        $response = (new CoverController)->show('movies', $name.'-cover.webp');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertFileExists($path);
        $this->assertFileDoesNotExist(public_path('covers/movies/'.$name.'-cover.webp'));
    }

    public function test_tvshows_are_accepted_by_the_cover_route(): void
    {
        $id = (string) random_int(8000000, 8999999);
        $this->createImage(storage_path('covers/tvshows/'.$id.'.webp'), 'webp');

        $response = (new CoverController)->show('tvshows', $id.'.webp');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/webp', $response->headers->get('Content-Type'));

        $route = app('router')->getRoutes()->getByName('covers.show');
        $this->assertNotNull($route);
        $this->assertStringContainsString('tvshows', $route->wheres['type']);
    }

    public function test_traversal_filename_is_rejected(): void
    {
        $this->expectException(NotFoundHttpException::class);

        (new CoverController)->show('preview', '../.env.webp');
    }

    private function createImage(string $path, string $format): void
    {
        File::ensureDirectoryExists(dirname($path));
        $image = imagecreatetruecolor(20, 10);
        $this->assertInstanceOf(GdImage::class, $image);
        imagefill($image, 0, 0, imagecolorallocate($image, 20, 40, 60));

        if ($format === 'webp') {
            imagewebp($image, $path, 82);
        } else {
            imagejpeg($image, $path, 82);
        }

        $this->createdFiles[] = $path;
    }
}
