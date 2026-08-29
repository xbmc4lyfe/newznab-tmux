<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class SiteLogoService
{
    public const DIRECTORY = 'site-logos';

    private const DISK = 'public';

    public function store(UploadedFile $logo): string
    {
        $path = $logo->store(self::DIRECTORY, self::DISK);

        if ($path === false) {
            throw new RuntimeException('The site logo could not be stored.');
        }

        return $path;
    }

    public function delete(mixed $path): void
    {
        if (! is_string($path) || ! $this->isManagedPath($path)) {
            return;
        }

        Storage::disk(self::DISK)->delete($path);
    }

    public function url(mixed $path): ?string
    {
        if (! is_string($path) || ! $this->isManagedPath($path)) {
            return null;
        }

        return '/storage/'.$path;
    }

    private function isManagedPath(string $path): bool
    {
        return $path !== ''
            && ! str_contains($path, '..')
            && str_starts_with($path, self::DIRECTORY.'/');
    }
}
