<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

final readonly class DownloadedArchive
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_dlarchive_summary(const char *flags, size_t count, const char *sizes, size_t sizes_len, int64_t *out, size_t out_len);
        C;

    private const int FLAG_IS_DIR = 1;

    private const int FLAG_PASSWORDED = 2;

    /**
     * @param  array<int, array<string, mixed>>  $files
     */
    public function __construct(
        public string $title,
        public string $data,
        public array $files,
        // PHP remains the production default: FFI marshaling stays below the 1.5x gate.
        public bool $useRustFfi = false,
    ) {}

    public function byteSize(): int
    {
        return strlen($this->data);
    }

    /**
     * Extracted (non-directory) file count and declared size, directory count and whether any
     * listed file is password protected.
     *
     * @return array{files: int, directories: int, bytes: int|float, passworded: bool}
     */
    public function fileSummary(): array
    {
        return ($this->useRustFfi ? $this->fileSummaryNative() : null) ?? $this->fileSummaryPhp();
    }

    /**
     * @return array{files: int, directories: int, bytes: int|float, passworded: bool}
     */
    public function fileSummaryPhp(): array
    {
        $files = 0;
        $directories = 0;
        $bytes = 0;
        $passworded = false;
        foreach ($this->files as $file) {
            if (! empty($file['is_dir'])) {
                $directories++;

                continue;
            }
            $files++;
            $bytes += (int) ($file['size'] ?? 0);
            if (! empty($file['pass'])) {
                $passworded = true;
            }
        }

        return ['files' => $files, 'directories' => $directories, 'bytes' => $bytes, 'passworded' => $passworded];
    }

    /**
     * @return array{files: int, directories: int, bytes: int, passworded: bool}|null Null when PHP must summarize.
     */
    public function fileSummaryNative(): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $flags = '';
        $sizes = [];
        foreach ($this->files as $file) {
            $flags .= \chr((! empty($file['is_dir']) ? self::FLAG_IS_DIR : 0) | (! empty($file['pass']) ? self::FLAG_PASSWORDED : 0));
            $sizes[] = (int) ($file['size'] ?? 0);
        }
        $packed = $sizes === [] ? '' : pack('q*', ...$sizes);
        try {
            $out = $ffi->new('int64_t[4]');
            // @phpstan-ignore method.notFound
            $code = $ffi->nntmux_dlarchive_summary($flags, \strlen($flags), $packed, \strlen($packed), $out, 4);
            if ($code !== 1) {
                return null;
            }

            return ['files' => (int) $out[0], 'directories' => (int) $out[1], 'bytes' => (int) $out[2], 'passworded' => (int) $out[3] === 1];
        } catch (Throwable) {
            return null;
        }
    }

    private static function ffi(): ?FFI
    {
        static $ffi = null;
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return $ffi === false ? null : $ffi;
    }
}
