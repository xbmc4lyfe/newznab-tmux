<?php

declare(strict_types=1);

namespace App\Services\LogViewer;

use App\Support\RustPipelineLibrary;
use Carbon\CarbonImmutable;
use FFI;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/**
 * Lists readable files under the log directory. The listing doubles as the access whitelist:
 * only paths it returns can be read, searched, downloaded, truncated or deleted.
 */
class LogFileRepository
{
    private const int STRUCTURE_SNIFF_BYTES = 8192;

    /**
     * Benchmark-gated default for {@see inventoryPathBatch()}: stays PHP because the common p99 ratio fell
     * to 1.42x and 1.46x in two of six strict runs although means were about 1.75x; see
     * tests/Unit/RustLogViewerEntryFileRepoReaderReleaseSearchW48ParityTest.php.
     */
    public const bool W48_LOG_FILE_REPOSITORY_RUST_FFI_DEFAULT = false;

    private static ?bool $useW48LogFileRepositoryRustFfi = null;

    private string $directory;

    /**
     * @var array<string, LogFile>|null
     */
    private ?array $files = null;

    public function __construct(private readonly LogEntryParser $parser, ?string $directory = null)
    {
        $directory = rtrim($directory ?? (string) config('nntmux.log_viewer.path', storage_path('logs')), '/\\');
        $realDirectory = realpath($directory);

        $this->directory = $realDirectory === false ? $directory : $realDirectory;
    }

    /** Pass null to restore {@see W48_LOG_FILE_REPOSITORY_RUST_FFI_DEFAULT}. */
    public static function useW48LogFileRepositoryRustFfi(?bool $enable = true): void
    {
        self::$useW48LogFileRepositoryRustFfi = $enable;
    }

    public static function usesW48LogFileRepositoryRustFfi(): bool
    {
        return self::$useW48LogFileRepositoryRustFfi ?? self::W48_LOG_FILE_REPOSITORY_RUST_FFI_DEFAULT;
    }

    public static function resetW48LogFileRepositoryRustFfi(): void
    {
        self::$useW48LogFileRepositoryRustFfi = null;
    }

    /**
     * Guards and filters relative paths for the inventory. Each path is trimmed, loses its leading
     * slashes and uses `/` separators. Per path: `[normalized_len, guard_flags, depth, glob_match,
     * duplicate_of, accepted, path_fnv64]` — flags 1 `..` segment, 2 NUL byte, 4 absolute (leading slash
     * or drive letter), 8 empty, 16 empty or `.` segment; depth counts `/`; glob_match tests the anchored
     * $pattern (`?` one byte but `/`, `*` a run without `/`, `**` any run; an empty pattern matches
     * everything); duplicate_of is the first index with the same normalized path (-1 for the first).
     * Paths without flags that match and are not duplicates are accepted. The summary is `[accepted,
     * rejected, duplicates, inventory_digest, max_depth]`: rejected counts flagged paths, the digest XORs
     * the accepted hashes and max_depth is the deepest accepted path.
     *
     * @param  list<string>  $paths
     * @return array{paths: list<list<int>>, summary: list<int>}
     */
    public static function inventoryPathBatch(array $paths, string $pattern): array
    {
        return self::usesW48LogFileRepositoryRustFfi() ? self::inventoryPathBatchNative($paths, $pattern) : self::inventoryPathBatchPhp($paths, $pattern);
    }

    /**
     * @param  list<string>  $paths
     * @return array{paths: list<list<int>>, summary: list<int>}
     */
    public static function inventoryPathBatchPhp(array $paths, string $pattern): array
    {
        $regex = $pattern === '' ? null : self::w48GlobRegex($pattern);
        $seen = [];
        $rows = [];
        $accepted = $rejected = $duplicates = $digest = $maxDepth = 0;

        foreach ($paths as $index => $path) {
            $trimmed = trim($path);
            $first = $trimmed[0] ?? '';
            $drive = \strlen($trimmed) >= 2 && $trimmed[1] === ':' && ctype_alpha($first) && \ord($first) < 0x80;
            $flags = $first === '/' || $first === '\\' || $drive ? 4 : 0;
            $normalized = str_replace('\\', '/', ltrim($trimmed, '/\\'));

            if ($normalized === '') {
                $flags |= 8;
            } else {
                foreach (explode('/', $normalized) as $segment) {
                    if ($segment === '..') {
                        $flags |= 1;
                    } elseif ($segment === '' || $segment === '.') {
                        $flags |= 16;
                    }
                }
                if (str_contains($normalized, "\0")) {
                    $flags |= 2;
                }
            }

            $depth = substr_count($normalized, '/');
            $matched = $regex === null || preg_match($regex, $normalized) === 1;
            $duplicate = $seen[$normalized] ??= $index;
            $duplicate = $duplicate === $index ? -1 : $duplicate;
            $hash = LogEntry::w48Hash64($normalized);
            $ok = $flags === 0 && $matched && $duplicate < 0;

            if ($ok) {
                $accepted++;
                $digest ^= $hash;
                $maxDepth = max($maxDepth, $depth);
            }
            if ($flags !== 0) {
                $rejected++;
            }
            if ($duplicate >= 0) {
                $duplicates++;
            }

            $rows[] = [\strlen($normalized), $flags, $depth, $matched ? 1 : 0, $duplicate, $ok ? 1 : 0, $hash];
        }

        return ['paths' => $rows, 'summary' => [$accepted, $rejected, $duplicates, $digest, $maxDepth]];
    }

    /**
     * @param  list<string>  $paths
     * @return array{paths: list<list<int>>, summary: list<int>}
     */
    public static function inventoryPathBatchNative(array $paths, string $pattern): array
    {
        $count = \count($paths);
        $bytes = 56 * $count + 40;
        $raw = LogReader::evaluateW48Native(3, pack('P', $count).LogReader::w48Strings([$pattern, ...$paths]), $bytes);

        if ($raw === null || \strlen($raw) !== $bytes) {
            return self::inventoryPathBatchPhp($paths, $pattern);
        }

        return ['paths' => LogReader::w48Rows(substr($raw, 0, $bytes - 40), 7), 'summary' => LogReader::w48Rows($raw, 5, $bytes - 40)[0]];
    }

    /**
     * Anchored regex for a glob: `**` any run, `*` a run without `/`, `?` one byte but `/`.
     */
    private static function w48GlobRegex(string $pattern): string
    {
        $regex = '';
        $length = \strlen($pattern);

        for ($at = 0; $at < $length; $at++) {
            $byte = $pattern[$at];

            if ($byte === '*' && ($pattern[$at + 1] ?? '') === '*') {
                $regex .= '.*';
                $at++;
            } else {
                $regex .= match ($byte) {
                    '*' => '[^\/]*',
                    '?' => '[^\/]',
                    default => preg_quote($byte, '/'),
                };
            }
        }

        return '/\A'.$regex.'\z/s';
    }

    /**
     * @return list<LogFile> newest first
     */
    public function all(): array
    {
        return array_values($this->map());
    }

    public function find(string $relativePath): ?LogFile
    {
        return $this->map()[$this->normalize($relativePath)] ?? null;
    }

    public function truncate(LogFile $file): void
    {
        $handle = @fopen($file->absolutePath, 'r+');

        if ($handle === false) {
            throw new RuntimeException('Log file could not be opened for writing.');
        }

        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Log file is locked by another process.');
            }

            ftruncate($handle, 0);
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
            clearstatcache(true, $file->absolutePath);
            $this->files = null;
        }
    }

    public function delete(LogFile $file): void
    {
        if (! @unlink($file->absolutePath)) {
            throw new RuntimeException('Log file could not be deleted.');
        }

        clearstatcache(true, $file->absolutePath);
        $this->files = null;
    }

    /**
     * @return array<string, LogFile>
     */
    private function map(): array
    {
        if ($this->files !== null) {
            return $this->files;
        }

        if (! File::isDirectory($this->directory)) {
            return $this->files = [];
        }

        $prefix = $this->directory.DIRECTORY_SEPARATOR;
        $files = [];

        foreach (File::allFiles($this->directory) as $file) {
            $absolutePath = $file->getPathname();
            $realPath = realpath($absolutePath);

            if ($realPath === false || ! str_starts_with($realPath, $prefix) || ! is_file($realPath) || ! is_readable($realPath)) {
                continue;
            }

            $relativePath = $this->normalize(substr($absolutePath, strlen($prefix)));
            $directory = dirname($relativePath);

            $files[$relativePath] = new LogFile(
                path: $relativePath,
                name: $file->getFilename(),
                directory: $directory === '.' ? null : $directory,
                absolutePath: $realPath,
                size: (int) $file->getSize(),
                modifiedAt: CarbonImmutable::createFromTimestamp($file->getMTime()),
                structured: $this->looksStructured($realPath),
            );
        }

        uasort($files, static function (LogFile $left, LogFile $right): int {
            return [$right->modifiedAt->getTimestamp(), $left->path] <=> [$left->modifiedAt->getTimestamp(), $right->path];
        });

        return $this->files = $files;
    }

    private function looksStructured(string $absolutePath, bool $useRustFfi = false): bool
    {
        return ($useRustFfi ? $this->looksStructuredNative($absolutePath) : null) ?? $this->looksStructuredPhp($absolutePath);
    }

    public function looksStructuredNative(string $absolutePath): ?bool
    {
        static $ffi;
        $ffi ??= RustPipelineLibrary::resolveDomain('search', 'int32_t nntmux_log_viewer_looks_structured(const char *input, size_t len);') ?? false;
        if ($ffi === false) {
            return null;
        }
        $sample = $this->readSample($absolutePath);
        if ($sample === null) {
            return false;
        }
        try {
            $result = $ffi->nntmux_log_viewer_looks_structured($sample, strlen($sample));

            return $result === 0 || $result === 1 ? (bool) $result : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function looksStructuredPhp(string $absolutePath): bool
    {
        $sample = $this->readSample($absolutePath);
        if ($sample === null) {
            return false;
        }
        foreach (explode("\n", $sample) as $line) {
            if ($this->parser->isHeader(rtrim($line, "\r"))) {
                return true;
            }
        }

        return false;
    }

    private function readSample(string $absolutePath): ?string
    {
        $handle = @fopen($absolutePath, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            return (string) fread($handle, self::STRUCTURE_SNIFF_BYTES);
        } finally {
            fclose($handle);
        }
    }

    private function normalize(string $relativePath, bool $useRustFfi = false): string
    {
        return ($useRustFfi ? $this->normalizeNative($relativePath) : null) ?? $this->normalizePhp($relativePath);
    }

    public function normalizeNative(string $relativePath): ?string
    {
        static $ffi;
        $ffi ??= RustPipelineLibrary::resolveDomain('search', 'ssize_t nntmux_log_viewer_normalize_path(const char *input, size_t len, char *out, size_t cap);') ?? false;
        if ($ffi === false) {
            return null;
        }
        try {
            $length = strlen($relativePath);
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, max(1, $length), $cap);
            if ($scratch === null) {
                return null;
            }
            $written = $ffi->nntmux_log_viewer_normalize_path($relativePath, $length, $scratch, $cap);

            return is_int($written) && $written >= 0 && $written <= $cap ? FFI::string($scratch, $written) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function normalizePhp(string $relativePath): string
    {
        return ltrim(str_replace('\\', '/', trim($relativePath)), '/');
    }
}
