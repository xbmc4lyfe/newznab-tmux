<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

class TempWorkspaceService
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_maintenance_verification(const char *input, size_t len, uint32_t mode, char *out, size_t cap);
        C;

    // Single-call candidates remain opt-in until both performance gates pass.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi && RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF) !== null;
    }

    private static function transformNative(int $mode, string $input): string|false|null
    {
        if (! self::$useRustFfi || strlen($input) > 1_048_576) {
            return null;
        }
        $ffi = RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF);
        if ($ffi === null) {
            return null;
        }
        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, strlen($input) * 4 + 256), $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_maintenance_verification($input, strlen($input), $mode, $scratch, $cap);
            if ($written === -3) {
                return false;
            }
            if (! is_int($written) || $written < 0 || $written > $cap) {
                return null;
            }

            return $written === 0 ? '' : FFI::string($scratch, $written);
        } catch (Throwable) {
            return null;
        }
    }

    /** @param list<string> $values */
    private static function encodeFrames(array $values): string
    {
        $input = '';
        foreach ($values as $value) {
            $input .= pack('V', strlen($value)).$value;
        }

        return $input;
    }

    /**
     * Ensure the main temp path exists and is namespaced by groupID or guidChar.
     * Returns the resolved main temp path.
     */
    public function ensureMainTempPath(
        string $basePath,
        string $guidChar = '',
        string $groupID = '',
        string $workerToken = ''
    ): string {
        $basePath = (self::$useRustFfi ? self::mainTempPathNative($basePath, $guidChar, $groupID, $workerToken) : null)
            ?? self::mainTempPathPhp($basePath, $guidChar, $groupID, $workerToken);

        $this->ensureWritableDirectory($basePath, 'Additional post-processing temp path');

        return $basePath;
    }

    /**
     * Create a per-release temp folder and return its path.
     */
    public function createReleaseTempFolder(string $mainTmpPath, string $guid): string
    {
        $this->assertWritableDirectory(rtrim($mainTmpPath, '/\\'), 'Additional post-processing temp path');

        $tmpPath = rtrim($mainTmpPath, '/\\').'/'.$guid.'/';
        $this->ensureWritableDirectory($tmpPath, 'Release temp path');

        return $tmpPath;
    }

    /**
     * Delete files recursively. If $preserveRoot is true, only clear contents of $path.
     */
    public function clearDirectory(string $path, bool $preserveRoot = true): void
    {
        if ($path === '' || ! File::exists($path)) {
            return;
        }

        if (File::isDirectory($path)) {
            // Delete all files recursively
            foreach (File::allFiles($path) as $file) {
                File::delete($file->getPathname());
            }
            // Delete sub-directories
            foreach (File::directories($path) as $dir) {
                File::deleteDirectory($dir);
            }
            if (! $preserveRoot) {
                File::deleteDirectory($path);
            }
        } elseif (File::isFile($path)) {
            File::delete($path);
        }
    }

    public function pruneStaleWorkerDirectories(string $bucketPath, int $staleAfterSeconds): int
    {
        if ($bucketPath === '' || ! File::isDirectory($bucketPath)) {
            return 0;
        }

        $removed = 0;
        $staleBefore = time() - max(1, $staleAfterSeconds);

        foreach (File::directories($bucketPath) as $directory) {
            if (File::lastModified($directory) >= $staleBefore) {
                continue;
            }

            if (File::deleteDirectory($directory)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * List files under a directory.
     * If $pattern is provided, return an array of preg_match($pattern, $relativePath, $matches) arrays,
     * where $matches[0] is the ABSOLUTE path for convenience (remaining capture groups preserved if present).
     * Otherwise return an array of SplFileInfo.
     *
     * @return array<int, array<int,string>|\SplFileInfo>
     */
    public function listFiles(string $path, string $pattern = ''): array
    {
        try {
            $files = File::allFiles($path);
        } catch (Throwable $e) {
            throw new RuntimeException('ERROR: Could not open temp dir: '.$e->getMessage());
        }

        if ($pattern !== '') {
            $filtered = [];
            $base = rtrim($path, '/\\');
            foreach ($files as $file) {
                $relative = $file->getRelativePathname();
                if (preg_match($pattern, $relative, $matches)) {
                    // Overwrite full match with absolute path to make downstream file ops straightforward
                    $matches[0] = $base.'/'.$relative;
                    $filtered[] = $matches;
                }
            }

            return $filtered;
        }

        return $files;
    }

    private function ensureWritableDirectory(string $path, string $label): void
    {
        if (File::isDirectory($path)) {
            $this->repairDirectoryIfPossible($path);
        } else {
            try {
                if (! File::makeDirectory($path, 0777, true, true) && ! File::isDirectory($path)) { // @phpstan-ignore booleanNot.alwaysTrue
                    throw new RuntimeException('mkdir returned false');
                }
            } catch (Throwable $e) {
                throw new RuntimeException(sprintf(
                    '%s "%s" could not be created: %s',
                    $label,
                    $path,
                    $e->getMessage()
                ), 0, $e);
            }
        }

        $this->assertWritableDirectory($path, $label);
    }

    private function repairDirectoryIfPossible(string $path): void
    {
        if (is_writable($path)) {
            return;
        }

        @chmod($path, 0777);
        clearstatcache(true, $path);
        if (is_writable($path)) {
            return;
        }

        $normalizedPath = rtrim($path, '/\\');
        $parent = dirname($normalizedPath);
        if (! File::isDirectory($parent) || ! is_writable($parent)) {
            return;
        }

        File::deleteDirectory($normalizedPath);
        if (! File::isDirectory($normalizedPath)) {
            File::makeDirectory($normalizedPath, 0777, true, true);
        }
    }

    private function assertWritableDirectory(string $path, string $label): void
    {
        if (! File::isDirectory($path)) {
            throw new RuntimeException(sprintf('%s "%s" is not a directory', $label, $path));
        }

        if (! is_writable($path)) {
            throw new RuntimeException(sprintf(
                '%s "%s" is not writable by the PHP/tmux user. Check TEMP_UNRAR_PATH ownership and permissions.',
                $label,
                $path
            ));
        }
    }

    public static function mainTempPathPhp(string $basePath, string $guidChar = '', string $groupID = '', string $workerToken = ''): string
    {
        if (! str_ends_with($basePath, '/') && ! str_ends_with($basePath, '\\')) {
            $basePath .= '/';
        }
        if ($groupID !== '') {
            $basePath .= $groupID.'/';
        } elseif ($guidChar !== '') {
            $basePath .= $guidChar.'/';
        }
        if ($workerToken !== '') {
            $basePath .= hash('sha256', $workerToken).'/';
        }

        return $basePath;
    }

    public static function mainTempPathNative(string $basePath, string $guidChar = '', string $groupID = '', string $workerToken = ''): ?string
    {
        $output = self::transformNative(9, self::encodeFrames([$basePath, $guidChar, $groupID, $workerToken]));

        return is_string($output) ? $output : null;
    }

    /** Enabled: three paired runs cleared every 1.5x gate (common mean 3.60-3.89x, p99 2.41-3.30x; uncommon mean 3.69-3.84x, p99 2.63-3.89x); see RustSystemTempUserInactivityStatsSeriesW51ParityTest. */
    private const bool W51_RUST_FFI_DEFAULT = true;

    public const string W51_FFI_CDEF = <<<'C'
        ssize_t nntmux_w513_temp_file_class_batch(const char *blob, size_t blob_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    private static ?bool $useW51RustFfi = null;

    private static FFI|false|null $w51Ffi = null;

    private static ?CData $w51Out = null;

    private static int $w51OutCap = 0;

    public static function usesW51RustFfi(): bool
    {
        return self::$useW51RustFfi ?? self::W51_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 51 Rust path forced on or off, then restores the previous setting. */
    public static function withW51RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$useW51RustFfi;
        self::$useW51RustFfi = $enabled;
        try {
            return $callback();
        } finally {
            self::$useW51RustFfi = $previous;
        }
    }

    /** ReleaseProcessor::processExtractedFiles() archive pattern. */
    private const string W51_COMPRESSED_PATTERN = '/.*\.([rz]\d{2,}|rar|zipx?|0{0,2}1)($|[^a-z0-9])/i';

    /**
     * Classifies extracted temp-file paths with the fixed ReleaseProcessor patterns. One word per
     * path: `action | flags << 4 | depth << 16`, where flags are 1 archive, 2 `.`/`..` entry,
     * 4 PAR2, 8 NFO and 16 image, the action is the first of the entry (1), PAR2 (2), NFO (3) and
     * image (4) checks the processor applies, else 0, and depth counts `/` and `\` separators.
     * Then the five flag counts.
     *
     * @param  array<int, string>  $paths
     * @return list<int>
     */
    public static function w51ExtractedFileClassBatch(array $paths): array
    {
        return self::usesW51RustFfi() ? self::w51ExtractedFileClassBatchNative($paths) : self::w51ExtractedFileClassBatchPhp($paths);
    }

    /**
     * @param  array<int, string>  $paths
     * @return list<int>
     */
    public static function w51ExtractedFileClassBatchNative(array $paths): array
    {
        $count = \count($paths);

        return self::w51Invoke(implode("\0", $paths), '', $count, $count + 5)
            ?? self::w51ExtractedFileClassBatchPhp($paths);
    }

    /**
     * @param  array<int, string>  $paths
     * @return list<int>
     */
    public static function w51ExtractedFileClassBatchPhp(array $paths): array
    {
        $out = [];
        $totals = [0, 0, 0, 0, 0];
        foreach ($paths as $path) {
            $path = (string) $path;
            $flags = (preg_match(self::W51_COMPRESSED_PATTERN, $path) === 1 ? 1 : 0)
                | (preg_match('/[\/\\\\]\.{1,2}$/', $path) === 1 ? 2 : 0)
                | (preg_match('/\.par2$/i', $path) === 1 ? 4 : 0)
                | (preg_match('/(\.(nfo|inf|ofn|diz)|info\.txt)$/i', $path) === 1 ? 8 : 0)
                | (preg_match('/\.(jpe?g|png|webp)$/i', $path) === 1 ? 16 : 0);
            for ($bit = 0; $bit < 5; $bit++) {
                $totals[$bit] += ($flags >> $bit) & 1;
            }
            $action = match (true) {
                ($flags & 2) !== 0 => 1,
                ($flags & 4) !== 0 => 2,
                ($flags & 8) !== 0 => 3,
                ($flags & 16) !== 0 => 4,
                default => 0,
            };
            $depth = substr_count($path, '/') + substr_count($path, '\\');
            $out[] = $action | $flags << 4 | $depth << 16;
        }
        array_push($out, ...$totals);

        return $out;
    }

    /**
     * Calls the Wave 51 export with NUL-joined strings and packed `i64` integers. Null when the
     * library is missing, the call declines (for example a string holding a NUL byte) or it writes
     * more than $cap words, so the caller falls back to PHP.
     *
     * @return list<int>|null
     */
    private static function w51Invoke(string $blob, string $ints, int $count, int $cap): ?array
    {
        self::$w51Ffi ??= RustPipelineLibrary::resolveDomain('categorize_w51', self::W51_FFI_CDEF) ?? false;
        $ffi = self::$w51Ffi;
        if ($ffi === false) {
            return null;
        }
        try {
            if (self::$w51Out === null || self::$w51OutCap < $cap) {
                self::$w51OutCap = max(1024, $cap * 2);
                self::$w51Out = $ffi->new('int64_t['.self::$w51OutCap.']');
            }
            // @phpstan-ignore method.notFound (Declared by W51_FFI_CDEF.)
            $written = $ffi->nntmux_w513_temp_file_class_batch($blob, \strlen($blob), $ints, \strlen($ints), $count, self::$w51Out, self::$w51OutCap);
            if (! \is_int($written) || $written <= 0 || $written > $cap) {
                return null;
            }
            $values = unpack('P*', FFI::string(self::$w51Out, $written * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
