<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Exceptions\NzbUploadException;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class NzbUploadStagingService
{
    /**
     * Hidden directory (relative to the upload folder) in which uploads are assembled.
     */
    public const STAGING_DIRECTORY = '.staging';

    private const MAX_NFO_SIZE = 65535;

    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_upload_staging_filename(const char *name, size_t name_len, const char *extension, size_t extension_len);
        int32_t nntmux_upload_staging_prefix(const char *data, size_t data_len, uint32_t kind);
        C;

    private static FFI|false|null $ffi = null;

    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    private static function resolveFfi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    private NzbUploadManifestService $manifests;

    public function __construct(
        private readonly Filesystem $filesystem,
        ?NzbUploadManifestService $manifests = null,
    ) {
        $this->manifests = $manifests ?? new NzbUploadManifestService($filesystem);
    }

    /**
     * @return array{name:string,files:array{nzb:array{filename:string,type:string},nfo:array{filename:string,type:string}|null}}
     */
    public function stage(UploadedFile $nzb, ?UploadedFile $nfo = null): array
    {
        [$nzbName, $nzbBase, $nzbContent] = $this->validateFile($nzb, 'nzb');
        $nfoDetails = $nfo === null ? null : $this->validateFile($nfo, 'nfo');
        $folder = $this->ensureUploadFolder();

        $uploadId = Str::uuid()->toString();
        $stagingDirectory = $this->ensureStagingFolder($folder).DIRECTORY_SEPARATOR.$uploadId;
        $uploadDirectory = $folder.DIRECTORY_SEPARATOR.$uploadId;
        if (! $this->filesystem->makeDirectory($stagingDirectory, 0775, true)) {
            throw new NzbUploadException('Failed to create upload staging directory', 500);
        }

        $files = [[$nzbName, $nzbContent, 'nzb']];
        if ($nfoDetails !== null) {
            $files[] = [$nfoDetails[0], $nfoDetails[2], 'nfo'];
        }

        try {
            foreach ($files as [$filename, $content, $type]) {
                $path = $stagingDirectory.DIRECTORY_SEPARATOR.$filename;
                if ($this->filesystem->put($path, $content) === false) {
                    throw new NzbUploadException("Failed to write {$filename} to disk", 500);
                }
            }

            $this->manifests->create(
                $stagingDirectory,
                $uploadId,
                $nzbName,
                $nfoDetails[0] ?? null,
                $nfoDetails[2] ?? null,
            );

            $this->publish($stagingDirectory, $uploadDirectory);
        } catch (\Throwable $exception) {
            if ($this->filesystem->isDirectory($stagingDirectory)
                && ! $this->filesystem->deleteDirectory($stagingDirectory)) {
                Log::channel('nzb_upload')->error('Failed to roll back partial API upload', [
                    'upload_id' => $uploadId,
                    'files' => array_column($files, 0),
                ]);

                throw new NzbUploadException('Failed to write upload pair and roll back partial files', 500);
            }

            if ($exception instanceof NzbUploadException) {
                throw $exception;
            }

            throw new NzbUploadException('Failed to write upload pair to disk', 500);
        }

        foreach ($files as [$filename, , $type]) {
            Log::channel('nzb_upload')->info('File staged by API', [
                'upload_id' => $uploadId,
                'filename' => $filename,
                'type' => $type,
            ]);
        }

        return [
            'name' => $nzbBase,
            'files' => [
                'nzb' => ['filename' => $nzbName, 'type' => 'nzb'],
                'nfo' => $nfoDetails === null ? null : ['filename' => $nfoDetails[0], 'type' => 'nfo'],
            ],
        ];
    }

    /**
     * Preserve the API v1 single-file fallback while routing NZBs through manifested staging.
     *
     * @return array{name:string,filename:string,type:'nzb'|'nfo'}
     */
    public function stageLegacyFile(UploadedFile $file): array
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (! in_array($extension, ['nzb', 'nfo'], true)) {
            throw new NzbUploadException('File is not an NZB or NFO file', 400);
        }

        if ($extension === 'nzb') {
            $staged = $this->stage($file);

            return [
                'name' => $staged['name'],
                'filename' => $staged['files']['nzb']['filename'],
                'type' => 'nzb',
            ];
        }

        [$filename, $basename, $content] = $this->validateFile($file, 'nfo');
        $folder = $this->ensureUploadFolder();
        $path = $folder.DIRECTORY_SEPARATOR.$filename;
        if ($this->filesystem->exists($path)) {
            throw new NzbUploadException("A staged file named {$filename} already exists", 409);
        }
        $temporaryPath = $this->ensureStagingFolder($folder).DIRECTORY_SEPARATOR.Str::uuid()->toString().'.nfo';
        try {
            if ($this->filesystem->put($temporaryPath, $content) === false) {
                throw new NzbUploadException("Failed to write {$filename} to disk", 500);
            }

            $this->publish($temporaryPath, $path);
        } finally {
            if ($this->filesystem->exists($temporaryPath)) {
                $this->filesystem->delete($temporaryPath);
            }
        }

        Log::channel('nzb_upload')->info('Legacy NFO file staged by API v1', [
            'filename' => $filename,
            'type' => 'nfo',
        ]);

        return ['name' => $basename, 'filename' => $filename, 'type' => 'nfo'];
    }

    /** @return array{string,string,string} */
    private function validateFile(UploadedFile $file, string $expectedExtension): array
    {
        if (! $file->isValid()) {
            throw new NzbUploadException("Invalid {$expectedExtension} upload", 400);
        }

        $filename = $file->getClientOriginalName();
        $basename = self::$useRustFfi
            ? (self::validateFilenameNative($filename, $expectedExtension) ?? self::validateFilenamePhp($filename, $expectedExtension))
            : self::validateFilenamePhp($filename, $expectedExtension);

        try {
            $content = $file->getContent();
        } catch (\Throwable) {
            throw new NzbUploadException("Failed to read {$expectedExtension} upload", 400);
        }

        if ($expectedExtension === 'nzb' && ! self::xmlPayload($content)) {
            throw new NzbUploadException('Invalid NZB payload', 400);
        }
        if ($expectedExtension === 'nfo' && ($content === '' || strlen($content) > self::MAX_NFO_SIZE)) {
            throw new NzbUploadException('NFO upload must not be empty or exceed 65535 bytes', 400);
        }

        return [$filename, $basename, $content];
    }

    /** Validate without rewriting the filename; return the original pathinfo basename. */
    public static function validateFilenamePhp(string $filename, string $expectedExtension): string
    {
        if ($filename === ''
            || $filename === '.'
            || $filename === '..'
            || str_contains($filename, '/')
            || str_contains($filename, '\\')
            || str_contains($filename, "\0")) {
            throw new NzbUploadException("Unsafe {$expectedExtension} filename", 400);
        }

        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        $basename = (string) pathinfo($filename, PATHINFO_FILENAME);
        if ($extension !== $expectedExtension || trim($basename) === '') {
            throw new NzbUploadException("The {$expectedExtension} field must contain a .{$expectedExtension} file", 400);
        }

        return $basename;
    }

    public static function validateFilenameNative(string $filename, string $expectedExtension): ?string
    {
        $ffi = self::$useRustFfi ? self::resolveFfi() : null;
        if ($ffi === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $status = $ffi->nntmux_upload_staging_filename($filename, strlen($filename), $expectedExtension, strlen($expectedExtension));
        if ($status < 0) {
            return null;
        }
        if ($status === 1) {
            throw new NzbUploadException("Unsafe {$expectedExtension} filename", 400);
        }
        if ($status === 2) {
            throw new NzbUploadException("The {$expectedExtension} field must contain a .{$expectedExtension} file", 400);
        }

        return (string) pathinfo($filename, PATHINFO_FILENAME);
    }

    public static function hasGzipMagicPhp(string $content): bool
    {
        return str_starts_with($content, "\x1f\x8b");
    }

    public static function hasGzipMagicNative(string $content): ?bool
    {
        return self::prefixNative($content, 0);
    }

    public static function hasUtf8BomPhp(string $content): bool
    {
        return str_starts_with($content, "\xef\xbb\xbf");
    }

    public static function hasUtf8BomNative(string $content): ?bool
    {
        return self::prefixNative($content, 1);
    }

    private static function prefixNative(string $content, int $kind): ?bool
    {
        $ffi = self::$useRustFfi ? self::resolveFfi() : null;
        if ($ffi === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $status = $ffi->nntmux_upload_staging_prefix($content, strlen($content), $kind);

        return $status < 0 ? null : $status === 1;
    }

    private static function xmlPayload(string $content): bool
    {
        return self::$useRustFfi
            ? (self::xmlPayloadNative($content) ?? self::xmlPayloadPhp($content))
            : self::xmlPayloadPhp($content);
    }

    public static function xmlPayloadPhp(string $content): bool
    {
        return isValidNewznabNzb($content);
    }

    /** Prefix preflight only: libxml retains namespace, encoding and XML semantics. */
    public static function xmlPayloadNative(string $content): ?bool
    {
        if (! self::$useRustFfi || self::resolveFfi() === null) {
            return null;
        }
        // Match PHP empty(), including its special string "0" case, before libxml state changes.
        if ($content === '' || $content === '0') {
            return false;
        }
        $gzip = self::hasGzipMagicNative($content) ?? self::hasGzipMagicPhp($content);
        $bomOnly = strlen($content) === 3
            && (self::hasUtf8BomNative($content) ?? self::hasUtf8BomPhp($content));
        if ($gzip || $bomOnly) {
            // These prefixes cannot form an XML document. Preserve the original helper's
            // global libxml error-mode and clearing side effects on parser rejection.
            libxml_use_internal_errors(true);
            libxml_clear_errors();

            return false;
        }

        // Do not strip BOMs or inflate gzip: staging preserves the exact uploaded bytes.
        return self::xmlPayloadPhp($content);
    }

    /**
     * Create the hidden work area where uploads are assembled before publication.
     *
     * It lives inside the upload folder so publishing is a same-filesystem rename, and its
     * dot-prefixed name keeps Finder-based scans (`ImportNzbs`, `findManifests()`) from ever
     * seeing in-progress or abandoned uploads.
     */
    private function ensureStagingFolder(string $folder): string
    {
        $stagingFolder = $folder.DIRECTORY_SEPARATOR.self::STAGING_DIRECTORY;
        // Forced (error-suppressed) so concurrent uploads racing to create it don't fail.
        $this->filesystem->makeDirectory($stagingFolder, 0775, true, true);
        if (! $this->filesystem->isDirectory($stagingFolder)) {
            throw new NzbUploadException('Failed to create upload staging directory', 500);
        }

        return $stagingFolder;
    }

    /**
     * Atomically move a fully written upload from the staging area to where importers scan.
     */
    private function publish(string $stagingPath, string $publishedPath): void
    {
        try {
            $moved = $this->filesystem->move($stagingPath, $publishedPath);
        } catch (\Throwable) {
            $moved = false;
        }

        if (! $moved) {
            throw new NzbUploadException('Failed to publish staged upload', 500);
        }
    }

    private function ensureUploadFolder(): string
    {
        $folder = config('nntmux.nzb_upload_folder');
        if (! is_string($folder) || trim($folder) === '') {
            throw new NzbUploadException('NZB upload folder is not configured', 500);
        }

        $folder = rtrim($folder, DIRECTORY_SEPARATOR);
        if (! $this->filesystem->isDirectory($folder)
            && ! $this->filesystem->makeDirectory($folder, 0775, true)) {
            throw new NzbUploadException('Failed to create NZB upload folder', 500);
        }

        return $folder;
    }
}
