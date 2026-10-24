<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class CoverController extends Controller
{
    private const FFI_CDEF = <<<'CDEF'
    ssize_t nntmux_cover_validate_and_expand(
        const char *type_ptr,
        size_t type_len,
        const char *filename_ptr,
        size_t filename_len,
        size_t *out_meta,
        char *out_buf,
        size_t out_cap
    );
    int32_t nntmux_cover_is_allowed_mime(
        const char *mime_ptr,
        size_t mime_len
    );
    CDEF;

    /** @var list<string> */
    private const array VALID_TYPES = [
        'anime', 'audio', 'audiosample', 'book', 'console', 'games', 'movies',
        'music', 'preview', 'sample', 'tvrage', 'video', 'tvshows',
    ];

    /** @var list<string> */
    private const array NUMERIC_ID_TYPES = ['anime', 'book', 'console', 'games', 'music', 'tvshows'];

    /** @var list<string> */
    private const array ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustCoverCartFailedControllersParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = false;
        self::$ffi = null;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    /** @return BinaryFileResponse|Response */
    public function show(string $type, string $filename)
    {
        $spec = self::validateAndExpandCoverSpec($type, $filename);
        if (! $spec['valid']) {
            abort(404);
        }

        if ($spec['placeholder']) {
            return $this->respondWithPlaceholder();
        }

        $filePath = $this->resolveImagePathFromSpec($type, $spec);
        if ($filePath === null) {
            return $this->respondWithPlaceholder();
        }

        $contentType = File::mimeType($filePath);
        if (! is_string($contentType) || ! self::isAllowedCoverMimeType($contentType)) {
            abort(404);
        }

        return response()->file($filePath, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'public, max-age=31536000',
        ]);
    }

    /**
     * @return array{valid: bool, placeholder: bool, basenames: list<string>, extensions: list<string>}
     */
    public static function validateAndExpandCoverSpec(string $type, string $filename): array
    {
        if (self::$useRustFfi) {
            return self::validateAndExpandCoverSpecNative($type, $filename);
        }

        return self::validateAndExpandCoverSpecPhp($type, $filename);
    }

    /**
     * @return array{valid: bool, placeholder: bool, basenames: list<string>, extensions: list<string>}
     */
    public static function validateAndExpandCoverSpecPhp(string $type, string $filename): array
    {
        if (! in_array($type, self::VALID_TYPES, true) || ! self::isValidCoverFilenamePhp($filename)) {
            return [
                'valid' => false,
                'placeholder' => false,
                'basenames' => [],
                'extensions' => [],
            ];
        }

        if (in_array($type, self::NUMERIC_ID_TYPES, true) && self::isInvalidNumericCoverFilenamePhp($filename)) {
            return [
                'valid' => true,
                'placeholder' => true,
                'basenames' => [],
                'extensions' => [],
            ];
        }

        $pathInfo = pathinfo($filename);
        $basename = $pathInfo['filename'];
        $requestedExtension = strtolower($pathInfo['extension'] ?? '');
        $basenames = [$basename];

        if (in_array($type, ['preview', 'sample'], true) && ! str_ends_with($basename, '_thumb')) {
            array_unshift($basenames, $basename.'_thumb');
        }

        if ($type === 'anime' && preg_match('/^(\d+)-cover$/', $basename, $matches) === 1) {
            $basenames[] = $matches[1];
        }

        /** @var list<string> $extensions */
        $extensions = array_values(array_unique([$requestedExtension, 'webp', 'jpg', 'jpeg']));

        return [
            'valid' => true,
            'placeholder' => false,
            'basenames' => $basenames,
            'extensions' => $extensions,
        ];
    }

    /**
     * @return array{valid: bool, placeholder: bool, basenames: list<string>, extensions: list<string>}
     */
    public static function validateAndExpandCoverSpecNative(string $type, string $filename): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::validateAndExpandCoverSpecPhp($type, $filename);
        }

        try {
            $meta = $ffi->new('size_t[4]');
            $cap = 0;
            $buf = RustPipelineLibrary::obtainScratch($ffi, max(128, strlen($filename) * 3 + 64), $cap);
            if ($buf === null) {
                return self::validateAndExpandCoverSpecPhp($type, $filename);
            }

            $rc = (int) self::callFfi(
                $ffi,
                'nntmux_cover_validate_and_expand',
                $type,
                strlen($type),
                $filename,
                strlen($filename),
                $meta,
                $buf,
                $cap
            );

            if ($rc < -1) {
                $buf = RustPipelineLibrary::obtainScratch($ffi, -$rc, $cap);
                if ($buf === null) {
                    return self::validateAndExpandCoverSpecPhp($type, $filename);
                }
                $rc = (int) self::callFfi(
                    $ffi,
                    'nntmux_cover_validate_and_expand',
                    $type,
                    strlen($type),
                    $filename,
                    strlen($filename),
                    $meta,
                    $buf,
                    $cap
                );
            }

            if ($rc < 0) {
                return self::validateAndExpandCoverSpecPhp($type, $filename);
            }

            $valid = ((int) $meta[0]) === 1;
            $placeholder = ((int) $meta[1]) === 1;
            $basenameCount = (int) $meta[2];
            $extensionCount = (int) $meta[3];

            if (! $valid || $placeholder || $rc === 0) {
                return [
                    'valid' => $valid,
                    'placeholder' => $placeholder,
                    'basenames' => [],
                    'extensions' => [],
                ];
            }

            $parts = explode("\0", FFI::string($buf, $rc));
            if (count($parts) !== $basenameCount + $extensionCount) {
                return self::validateAndExpandCoverSpecPhp($type, $filename);
            }

            /** @var list<string> $basenames */
            $basenames = array_slice($parts, 0, $basenameCount);
            /** @var list<string> $extensions */
            $extensions = array_slice($parts, $basenameCount, $extensionCount);

            return [
                'valid' => true,
                'placeholder' => false,
                'basenames' => $basenames,
                'extensions' => $extensions,
            ];
        } catch (Throwable) {
            return self::validateAndExpandCoverSpecPhp($type, $filename);
        }
    }

    public static function isAllowedCoverMimeType(string $contentType): bool
    {
        if (self::$useRustFfi) {
            return self::isAllowedCoverMimeTypeNative($contentType);
        }

        return self::isAllowedCoverMimeTypePhp($contentType);
    }

    public static function isAllowedCoverMimeTypePhp(string $contentType): bool
    {
        return in_array($contentType, self::ALLOWED_MIME_TYPES, true);
    }

    public static function isAllowedCoverMimeTypeNative(string $contentType): bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::isAllowedCoverMimeTypePhp($contentType);
        }

        try {
            $rc = (int) self::callFfi($ffi, 'nntmux_cover_is_allowed_mime', $contentType, strlen($contentType));
            if ($rc < 0) {
                return self::isAllowedCoverMimeTypePhp($contentType);
            }

            return $rc === 1;
        } catch (Throwable) {
            return self::isAllowedCoverMimeTypePhp($contentType);
        }
    }

    private function resolveImagePath(string $type, string $filename): ?string
    {
        $spec = self::validateAndExpandCoverSpec($type, $filename);

        return $this->resolveImagePathFromSpec($type, $spec);
    }

    /**
     * @param  array{valid: bool, placeholder: bool, basenames: list<string>, extensions: list<string>}  $spec
     */
    private function resolveImagePathFromSpec(string $type, array $spec): ?string
    {
        $roots = [storage_path('covers'), public_path('covers')];

        foreach ($spec['basenames'] as $candidateBasename) {
            foreach ($spec['extensions'] as $extension) {
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

    private static function isValidCoverFilenamePhp(string $filename): bool
    {
        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]*\.(?:webp|jpe?g|png|gif)\z/iD', $filename) === 1;
    }

    private static function isInvalidNumericCoverFilenamePhp(string $filename): bool
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

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
