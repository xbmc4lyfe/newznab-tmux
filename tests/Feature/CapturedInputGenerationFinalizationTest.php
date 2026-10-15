<?php

namespace Tests\Feature;

use App\Services\Nzb\NzbUploadManifestService;
use Illuminate\Filesystem\Filesystem;

class CapturedInputGenerationFinalizationTest extends ReleaseVerificationCandidatesTest
{
    public function test_post_digest_replacement_is_rejected_before_capture_persistence(): void
    {
        $directory = config('release_verification.storage_path').'/post-digest';
        app('files')->ensureDirectoryExists($directory);
        $path = $directory.'/incoming.nzb';
        $xml = '<nzb />';
        file_put_contents($path, $xml);
        $filesystem = new class extends Filesystem
        {
            public ?\Closure $replace = null;

            public function get($path, $lock = false)
            {
                $bytes = parent::get($path, $lock);
                if ($this->replace !== null && basename($path) === 'incoming.nzb') {
                    $replace = $this->replace;
                    $this->replace = null;
                    $replace();
                }

                return $bytes;
            }
        };
        $service = new NzbUploadManifestService($filesystem);
        $service->create($directory, 'post-digest', 'incoming.nzb', null);
        $reservation = $service->reserveInput($path);
        $filesystem->replace = static function () use ($path): void {
            copy($path, dirname($path).'/replacement.nzb');
            rename(dirname($path).'/replacement.nzb', $path);
        };
        self::assertNull($service->finalizeInput($path, $xml, $reservation));
        self::assertArrayNotHasKey('input_capture', $service->read($directory.'/'.NzbUploadManifestService::FILENAME));
        self::assertTrue($service->shouldImportNzb($path));
        self::assertNotNull($service->finalizeInput($path, $xml, $service->reserveInput($path)));
    }
}
