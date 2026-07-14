<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Exceptions\NzbUploadException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

final class NzbUploadStagingService
{
    private const MAX_NFO_SIZE = 65535;

    public function __construct(private readonly Filesystem $filesystem) {}

    /**
     * @return array{name:string,files:array{nzb:array{filename:string,type:string},nfo:array{filename:string,type:string}|null}}
     */
    public function stage(UploadedFile $nzb, ?UploadedFile $nfo = null): array
    {
        [$nzbName, $nzbBase, $nzbContent] = $this->validateFile($nzb, 'nzb');
        $nfoDetails = $nfo === null ? null : $this->validateFile($nfo, 'nfo');

        if ($nfoDetails !== null && strcasecmp($nzbBase, $nfoDetails[1]) !== 0) {
            throw new NzbUploadException('NZB and NFO filenames must have matching basenames', 400);
        }

        $folder = config('nntmux.nzb_upload_folder');
        if (! is_string($folder) || trim($folder) === '') {
            throw new NzbUploadException('NZB upload folder is not configured', 500);
        }

        $folder = rtrim($folder, DIRECTORY_SEPARATOR);
        if (! $this->filesystem->isDirectory($folder)
            && ! $this->filesystem->makeDirectory($folder, 0775, true)) {
            throw new NzbUploadException('Failed to create NZB upload folder', 500);
        }

        $files = [[$nzbName, $nzbContent, 'nzb']];
        if ($nfoDetails !== null) {
            $files[] = [$nfoDetails[0], $nfoDetails[2], 'nfo'];
        }

        foreach ($files as [$filename]) {
            if ($this->filesystem->exists($folder.DIRECTORY_SEPARATOR.$filename)) {
                throw new NzbUploadException("A staged file named {$filename} already exists", 409);
            }
        }

        $created = [];
        try {
            foreach ($files as [$filename, $content, $type]) {
                $path = $folder.DIRECTORY_SEPARATOR.$filename;
                if ($this->filesystem->put($path, $content) === false) {
                    throw new NzbUploadException("Failed to write {$filename} to disk", 500);
                }
                $created[] = $path;
            }
        } catch (\Throwable $exception) {
            $rollbackFailed = false;
            foreach ($created as $path) {
                if ($this->filesystem->exists($path) && ! $this->filesystem->delete($path)) {
                    $rollbackFailed = true;
                }
            }

            if ($rollbackFailed) {
                Log::channel('nzb_upload')->error('Failed to roll back partial API v2 upload', [
                    'files' => array_map('basename', $created),
                ]);

                throw new NzbUploadException('Failed to write upload pair and roll back partial files', 500);
            }

            if ($exception instanceof NzbUploadException) {
                throw $exception;
            }

            throw new NzbUploadException('Failed to write upload pair to disk', 500);
        }

        foreach ($files as [$filename, , $type]) {
            Log::channel('nzb_upload')->info('File uploaded by API v2', [
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

    /** @return array{string,string,string} */
    private function validateFile(UploadedFile $file, string $expectedExtension): array
    {
        if (! $file->isValid()) {
            throw new NzbUploadException("Invalid {$expectedExtension} upload", 400);
        }

        $filename = $file->getClientOriginalName();
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

        try {
            $content = $file->getContent();
        } catch (\Throwable) {
            throw new NzbUploadException("Failed to read {$expectedExtension} upload", 400);
        }

        if ($expectedExtension === 'nzb' && ! isValidNewznabNzb($content)) {
            throw new NzbUploadException('Invalid NZB payload', 400);
        }
        if ($expectedExtension === 'nfo' && ($content === '' || strlen($content) > self::MAX_NFO_SIZE)) {
            throw new NzbUploadException('NFO upload must not be empty or exceed 65535 bytes', 400);
        }

        return [$filename, $basename, $content];
    }
}
