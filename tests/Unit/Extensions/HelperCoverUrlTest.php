<?php

declare(strict_types=1);

namespace Tests\Unit\Extensions;

use Tests\TestCase;

class HelperCoverUrlTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryCoverFiles = [];

    /** @var list<string> */
    private array $temporaryCoverDirectories = [];

    private mixed $originalCoversPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalCoversPath = config('nntmux_settings.covers_path');
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryCoverFiles as $path) {
            @unlink($path);
        }
        foreach (array_reverse($this->temporaryCoverDirectories) as $path) {
            @rmdir($path);
        }

        config(['nntmux_settings.covers_path' => $this->originalCoversPath]);

        parent::tearDown();
    }

    public function test_unzip_gzip_file_returns_uncompressed_contents(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'nntmux-gzip-');
        $this->assertIsString($path);

        file_put_contents($path, gzencode('gzip fixture contents'));

        try {
            $this->assertSame('gzip fixture contents', unzipGzipFile($path));
        } finally {
            @unlink($path);
        }
    }

    public function test_get_release_cover_returns_placeholder_for_negative_bookinfo_id(): void
    {
        $url = getReleaseCover((object) ['bookinfo_id' => -2]);

        $this->assertStringContainsString('assets/images/no-cover.png', $url);
    }

    public function test_get_release_cover_returns_placeholder_for_negative_music_id(): void
    {
        $url = getReleaseCover((object) ['musicinfo_id' => -2]);

        $this->assertStringContainsString('assets/images/no-cover.png', $url);
    }

    public function test_get_release_cover_emits_legacy_jpeg_extension_when_webp_does_not_exist(): void
    {
        $id = 987654321;
        $path = storage_path("covers/book/{$id}.jpg");
        $this->temporaryCoverFiles[] = $path;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, 'legacy jpeg fixture');

        $url = getReleaseCover((object) ['bookinfo_id' => $id]);

        $this->assertStringEndsWith("/covers/book/{$id}.jpg", $url);
    }

    public function test_get_release_cover_uses_the_real_extension_in_a_custom_covers_path(): void
    {
        $id = 987654322;
        $root = sys_get_temp_dir().'/nntmux-cover-url-'.bin2hex(random_bytes(6));
        $directory = $root.'/book';
        $jpegPath = $directory."/{$id}.jpg";
        $webpPath = $directory."/{$id}.webp";
        $this->temporaryCoverDirectories = [$root, $directory];
        $this->temporaryCoverFiles = [$jpegPath, $webpPath];
        mkdir($directory, 0777, true);
        config(['nntmux_settings.covers_path' => $root]);
        file_put_contents($jpegPath, 'legacy jpeg fixture');

        $this->assertStringEndsWith("/covers/book/{$id}.jpg", getReleaseCover((object) ['bookinfo_id' => $id]));

        file_put_contents($webpPath, 'webp fixture');

        $this->assertStringEndsWith("/covers/book/{$id}.webp", getReleaseCover((object) ['bookinfo_id' => $id]));
    }
}
