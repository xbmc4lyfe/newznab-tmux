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

    public const string W55_FFI_CDEF = 'int64_t nntmux_w555_log_file_repository_path_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W55.5 parity test's QUALIFICATION). */
    private const bool W55_RUST_FFI_DEFAULT = true;

    /**
     * Wave 55.5 routing and FFI cache, kept in function-local statics. Without $op it reports the
     * routing; 'set' swaps the override for $val and returns the previous one; 'reset' replaces the cached
     * handle with $val (null re-resolves, false disables); 'buffer' returns `[FFI, int64_t[], capacity]`
     * with room for at least $val words, or null without the library.
     */
    private static function w55RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'reset':
                $ffi = $val;
                $out = null;
                $outCap = 0;

                return null;
            case 'buffer':
                if ($ffi === null) {
                    $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
                    $ffi = RustPipelineLibrary::resolveDomain('w55_5', self::W55_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
                }
                if (! $ffi instanceof FFI || ! \is_int($val)) {
                    return null;
                }
                if ($out === null || $outCap < $val) {
                    $outCap = max(1024, $val);
                    $out = $ffi->new('int64_t['.$outCap.']');
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled ?? self::W55_RUST_FFI_DEFAULT;
        }
    }

    public static function usesW55RustFfi(): bool
    {
        return self::w55RustFfiState() === true;
    }

    /**
     * Runs $callback with relativePathPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW55RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w55RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w55RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 55.5 Rust helper over $count NUL-joined records, or null
     * to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w55Words(int $count, string $data, int $words): ?array
    {
        try {
            $handle = self::w55RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w555_log_file_repository_path_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('P*', FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Batch plan of listing paths normalized as {@see normalizePhp()} does. Four words per path:
     * `[normalized length, depth ("/" count), basename offset, flags]`. Flags: 1 `..` segment, 2 empty or
     * `.` segment, 4 hidden basename, 8 `.log` suffix, 16 `.gz` suffix, 32 empty path (`[0, 0, 0, 32]`).
     *
     * @param  list<string>  $paths
     * @return list<int>
     */
    public static function relativePathPlanBatch(array $paths): array
    {
        return self::usesW55RustFfi() ? self::relativePathPlanBatchNative($paths) : self::relativePathPlanBatchPhp($paths);
    }

    /**
     * @param  list<string>  $paths
     * @return list<int>
     */
    public static function relativePathPlanBatchPhp(array $paths): array
    {
        $out = [];
        foreach ($paths as $path) {
            $normalized = ltrim(str_replace('\\', '/', trim($path)), '/');
            if ($normalized === '') {
                array_push($out, 0, 0, 0, 32);

                continue;
            }
            $flags = 0;
            foreach (explode('/', $normalized) as $segment) {
                if ($segment === '..') {
                    $flags |= 1;
                } elseif ($segment === '' || $segment === '.') {
                    $flags |= 2;
                }
            }
            $slash = strrpos($normalized, '/');
            $offset = $slash === false ? 0 : $slash + 1;
            $base = substr($normalized, $offset);
            $flags |= (str_starts_with($base, '.') ? 4 : 0) | (str_ends_with($base, '.log') ? 8 : 0) | (str_ends_with($base, '.gz') ? 16 : 0);
            array_push($out, \strlen($normalized), substr_count($normalized, '/'), $offset, $flags);
        }

        return $out;
    }

    /**
     * Rust batch over the NUL-joined values; falls back to PHP without the library or when a value holds
     * a NUL.
     *
     * @param  list<string>  $paths
     * @return list<int>
     */
    public static function relativePathPlanBatchNative(array $paths): array
    {
        $count = \count($paths);
        if ($count === 0) {
            return [];
        }

        return self::w55Words($count, implode("\0", $paths), 4 * $count) ?? self::relativePathPlanBatchPhp($paths);
    }
}
