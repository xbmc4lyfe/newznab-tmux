<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Releases\Verification\ManifestStore;
use Illuminate\Support\Facades\DB;

/**
 * Storage-neutral manifest assertions, so lifecycle tests hold for both
 * release_verification.manifest_storage modes (database rows or legacy files).
 */
trait StoredManifests
{
    protected function storedManifestBody(string $name): string|false
    {
        if ($this->manifestsInDatabase()) {
            $body = DB::table('release_verification_manifests')->where('name', $name)->value('body');
            if (is_string($body)) {
                return $body;
            }
        }

        return @file_get_contents(app(ManifestStore::class)->path($name));
    }

    protected function assertManifestStored(string $name): void
    {
        $this->assertNotFalse($this->storedManifestBody($name), "Manifest {$name} should be stored.");
    }

    protected function assertManifestNotStored(string $name): void
    {
        $this->assertFalse($this->storedManifestBody($name), "Manifest {$name} should not be stored.");
    }

    /** Place bytes under a manifest name, as an earlier writer would have left them. */
    protected function occupyManifest(string $name, string $body): void
    {
        if ($this->manifestsInDatabase()) {
            DB::table('release_verification_manifests')->updateOrInsert(['name' => $name], ['body' => $body, 'bytes' => strlen($body)]);

            return;
        }
        $path = app(ManifestStore::class)->path($name);
        app('files')->ensureDirectoryExists(dirname($path));
        file_put_contents($path, $body);
    }

    protected function removeStoredManifest(string $name): void
    {
        if ($this->manifestsInDatabase()) {
            DB::table('release_verification_manifests')->where('name', $name)->delete();
        }
        @unlink(app(ManifestStore::class)->path($name));
    }

    private function manifestsInDatabase(): bool
    {
        return config('release_verification.manifest_storage') === 'database';
    }
}
