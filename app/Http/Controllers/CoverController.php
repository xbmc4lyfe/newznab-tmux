<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CoverController extends Controller
{
    /** @var list<string> */
    private const array VALID_TYPES = [
        'anime', 'audio', 'audiosample', 'book', 'console', 'games', 'movies',
        'music', 'preview', 'sample', 'tvrage', 'video', 'tvshows',
    ];

    /** @var list<string> */
    private const array NUMERIC_ID_TYPES = ['anime', 'book', 'console', 'games', 'music', 'tvshows'];

    /** @return BinaryFileResponse|Response */
    public function show(string $type, string $filename)
    {
        if (! in_array($type, self::VALID_TYPES, true) || ! $this->isValidFilename($filename)) {
            abort(404);
        }

        if (in_array($type, self::NUMERIC_ID_TYPES, true) && $this->isInvalidNumericCoverFilename($filename)) {
            return $this->respondWithPlaceholder();
        }

        $filePath = $this->resolveImagePath($type, $filename);
        if ($filePath === null) {
            return $this->respondWithPlaceholder();
        }

        $contentType = File::mimeType($filePath);
        if (! is_string($contentType) || ! in_array($contentType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            abort(404);
        }

        return response()->file($filePath, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'public, max-age=31536000',
        ]);
    }

    private function resolveImagePath(string $type, string $filename): ?string
    {
        $pathInfo = pathinfo($filename);
        $basename = $pathInfo['filename'];
        $requestedExtension = strtolower($pathInfo['extension']);
        $basenames = [$basename];

        if (in_array($type, ['preview', 'sample'], true) && ! str_ends_with($basename, '_thumb')) {
            array_unshift($basenames, $basename.'_thumb');
        }

        if ($type === 'anime' && preg_match('/^(\d+)-cover$/', $basename, $matches) === 1) {
            $basenames[] = $matches[1];
        }

        $extensions = array_values(array_unique([$requestedExtension, 'webp', 'jpg', 'jpeg']));
        $roots = [storage_path('covers'), public_path('covers')];

        foreach ($basenames as $candidateBasename) {
            foreach ($extensions as $extension) {
                foreach ($roots as $root) {
                    $candidate = $root.DIRECTORY_SEPARATOR.$type.DIRECTORY_SEPARATOR.$candidateBasename.'.'.$extension;
                    if (File::isFile($candidate) && File::isReadable($candidate)) {
                        return $candidate;
                    }
                }
            }
        }

        return null;
    }

    private function isValidFilename(string $filename): bool
    {
        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]*\.(?:webp|jpe?g|png|gif)\z/iD', $filename) === 1;
    }

    private function isInvalidNumericCoverFilename(string $filename): bool
    {
        if (preg_match('/^(-?\d+)(?:-cover)?\.(?:webp|jpe?g)$/i', $filename, $matches) !== 1) {
            return false;
        }

        return (int) $matches[1] <= 0;
    }

    private function respondWithPlaceholder(): Response|BinaryFileResponse
    {
        $placeholderPath = public_path('assets/images/no-cover.png');
        if (File::isFile($placeholderPath)) {
            return response()->file($placeholderPath);
        }

        abort(404);
    }
}
