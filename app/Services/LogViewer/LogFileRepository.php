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

    /** C signature of the Wave 67.6 {@see self::w67LogFileRepositoryPlanBatch()} helper. */
    private const string W67_LOG_FILE_REPOSITORY_PLAN_FFI_CDEF = 'int64_t nntmux_w676_log_file_repository_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /**
     * The Wave 67.6 plan batch defaults to Rust: every common and uncommon mean and p99 ratio cleared 1.5x
     * (min 5.43/4.46/4.36/4.59x); see tests/Unit/RustLogViewerRepoReaderSearcherQueryMcpProcessRunnerW67ParityTest.php.
     */
    public const bool W67_RUST_FFI_DEFAULT = true;

    /** ASCII control bytes. */
    private const string W67_CONTROL = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x7f";

    /** ASCII letters for drive prefixes. */
    private const string W67_ALPHA = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * Wave 67.6 routing and FFI cache, kept in function-local statics (no properties). Without $op it
     * reports the routing; 'set' swaps the override for $val and returns the previous one; 'reset' replaces the
     * cached handle with $val (null re-resolves, false disables); 'buffer' returns `[FFI, int64_t[], capacity]`
     * with room for at least $val words, or null without the library.
     */
    private static function w67RustFfiState(?string $op = null, mixed $val = null): mixed
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
                    $ffi = RustPipelineLibrary::resolveDomain('w67_6', self::W67_LOG_FILE_REPOSITORY_PLAN_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
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
                return $enabled ?? self::W67_RUST_FFI_DEFAULT;
        }
    }

    /** Route the Wave 67.6 plan batch to Rust until disabled or reset. */
    public static function enableW67RustFfi(): void
    {
        self::w67RustFfiState('set', true);
    }

    /** Route the Wave 67.6 plan batch to PHP until enabled or reset. */
    public static function disableW67RustFfi(): void
    {
        self::w67RustFfiState('set', false);
    }

    /** Restore W67_RUST_FFI_DEFAULT routing and drop the cached Wave 67.6 handle and buffer. */
    public static function resetW67RustFfi(): void
    {
        self::w67RustFfiState('set', null);
        self::w67RustFfiState('reset', null);
    }

    public static function usesW67RustFfi(): bool
    {
        return self::w67RustFfiState() === true;
    }

    /**
     * Runs $callback with w67LogFileRepositoryPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW67RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w67RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w67RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 67.6 Rust helper over $count NUL-joined records, or null
     * to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w67Words(int $count, string $data, int $words): ?array
    {
        try {
            $handle = self::w67RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w676_log_file_repository_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('q*', FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Trimmed 1-15 ASCII digits as an integer; empty yields $default, anything else sets bit 1 and yields $default. */
    private static function w67Number(string $field, int $default, int &$flags): int
    {
        $text = trim($field);
        if ($text === '') {
            return $default;
        }
        if (\strlen($text) <= 15 && strspn($text, '0123456789') === \strlen($text)) {
            return (int) $text;
        }
        $flags |= 1;

        return $default;
    }

    /**
     * Batch listing plan over `path\tsize`. The path is normalized as {@see normalizePhp()} does. Eight words
     * per record: `[normalized length, flags, depth ("/" count), date, rotation, kind, size class, errors]`.
     * Flags: 1 `..` segment, 2 empty or `.` segment, 4 absolute (leading slash or drive letter), 8 empty path,
     * 16 hidden basename, 32 control byte, 64 `.gz` suffix (case-insensitive), 128 dated, 256 accepted (none
     * of flags 1-32 and a known kind). Date: the basename's first `\d{4}-\d{2}-\d{2}` as `YYYYMMDD` (0 when
     * none or the month/day is out of range). Rotation: a 1-9 digit `.N` suffix before any `.gz` (0 none).
     * Kind of the remaining stem: 1 `.log`, 2 `.txt`, 3 `.json`, 0 other. Size class: 0 empty, 1 under 1 MiB,
     * 2 under 64 MiB, 3 larger. Numbers are trimmed 1-15 ASCII digits; errors: 1 invalid size, 2 not exactly
     * two fields.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w67LogFileRepositoryPlanBatch(array $records): array
    {
        return self::usesW67RustFfi() ? self::w67LogFileRepositoryPlanBatchNative($records) : self::w67LogFileRepositoryPlanBatchPhp($records);
    }

    /**
     * Rust batch over the NUL-joined records; falls back to PHP without the library or when a record holds
     * a NUL.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w67LogFileRepositoryPlanBatchNative(array $records): array
    {
        $count = \count($records);
        if ($count === 0) {
            return [];
        }

        return self::w67Words($count, implode("\0", $records), 8 * $count) ?? self::w67LogFileRepositoryPlanBatchPhp($records);
    }

    /**
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w67LogFileRepositoryPlanBatchPhp(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $fields = explode("\t", $record);
            $errors = \count($fields) === 2 ? 0 : 2;
            $trimmed = trim($fields[0]);
            $first = $trimmed[0] ?? '';
            $flags = $first === '/' || $first === '\\' || (($trimmed[1] ?? '') === ':' && strspn($first, self::W67_ALPHA) === 1) ? 4 : 0;
            $normalized = ltrim(str_replace('\\', '/', $trimmed), '/');
            $depth = 0;
            if ($normalized === '') {
                $flags |= 8;
            } else {
                foreach (explode('/', $normalized) as $segment) {
                    if ($segment === '..') {
                        $flags |= 1;
                    } elseif ($segment === '' || $segment === '.') {
                        $flags |= 2;
                    }
                }
                $depth = substr_count($normalized, '/');
                if (strcspn($normalized, self::W67_CONTROL) !== \strlen($normalized)) {
                    $flags |= 32;
                }
            }
            $slash = strrpos($normalized, '/');
            $base = $slash === false ? $normalized : substr($normalized, $slash + 1);
            if (($base[0] ?? '') === '.') {
                $flags |= 16;
            }
            $stem = strtolower($base);
            if (str_ends_with($stem, '.gz')) {
                $flags |= 64;
                $stem = substr($stem, 0, -3);
            }
            $rotation = 0;
            $dot = strrpos($stem, '.');
            if ($dot !== false) {
                $suffix = substr($stem, $dot + 1);
                if ($suffix !== '' && \strlen($suffix) <= 9 && strspn($suffix, '0123456789') === \strlen($suffix)) {
                    $rotation = (int) $suffix;
                    $stem = substr($stem, 0, $dot);
                }
            }
            $kind = str_ends_with($stem, '.log') ? 1 : (str_ends_with($stem, '.txt') ? 2 : (str_ends_with($stem, '.json') ? 3 : 0));
            $date = 0;
            if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $base, $match) === 1) {
                $month = (int) $match[2];
                $day = (int) $match[3];
                if ($month >= 1 && $month <= 12 && $day >= 1 && $day <= 31) {
                    $date = (int) $match[1] * 10_000 + $month * 100 + $day;
                    $flags |= 128;
                }
            }
            if (($flags & 63) === 0 && $kind !== 0) {
                $flags |= 256;
            }
            $size = self::w67Number($fields[1] ?? '', 0, $errors);
            $sizeClass = match (true) {
                $size === 0 => 0,
                $size < 1_048_576 => 1,
                $size < 67_108_864 => 2,
                default => 3,
            };
            array_push($out, \strlen($normalized), $flags, $depth, $date, $rotation, $kind, $sizeClass, $errors);
        }

        return $out;
    }
}
