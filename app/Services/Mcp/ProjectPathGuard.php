<?php

declare(strict_types=1);

namespace App\Services\Mcp;

use App\Services\Nzb\NzbParserService;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use InvalidArgumentException;
use Throwable;

class ProjectPathGuard
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_mcp_check_not_sensitive(const char *path, size_t path_len);
        int32_t nntmux_mcp_check_allowed_location(const char *path, size_t path_len, uint32_t use_defaults, const char *root_files_nul, size_t root_files_len, const char *allowed_roots_nul, size_t allowed_roots_len, const char *denied_segments_nul, size_t denied_segments_len);
        ssize_t nntmux_mcp_normalize_relative_path(const char *path, size_t path_len, uint32_t use_defaults, const char *root_files_nul, size_t root_files_len, const char *allowed_roots_nul, size_t allowed_roots_len, const char *denied_segments_nul, size_t denied_segments_len, int32_t *out_status, char *out_buf, size_t out_cap);
        C;

    private const array DEFAULT_ALLOWED_ROOT_FILES = [
        '.env.example',
        'AGENTS.md',
        'README.md',
        'composer.json',
        'composer.lock',
        'package.json',
        'package-lock.json',
        'phpstan.neon',
        'phpunit.xml',
        'pint.json',
        'vite.config.js',
    ];

    private const array DEFAULT_ALLOWED_ROOTS = [
        'app',
        'bootstrap',
        'config',
        'database',
        'resources',
        'routes',
        'tests',
    ];

    private const array DEFAULT_DENIED_SEGMENTS = [
        '.git',
        'node_modules',
        'public/build',
        'storage',
        'storage-runtime',
        'vendor',
    ];

    private static FFI|false|null $ffi = null;

    private static ?CData $outStatus = null;

    private static bool $useRustFfi = true;

    private static ?string $cachedBasePath = null;

    private static string|false|null $cachedProjectRoot = null;

    public static function setUseRustFfi(bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public function normalize(string $path): string
    {
        if (self::$useRustFfi) {
            $native = $this->normalizeNative($path);
            if ($native !== null) {
                return $native;
            }
        }

        return $this->normalizePhp($path);
    }

    public function normalizePhp(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));

        if ($path === '' || str_contains($path, "\0")) {
            throw new InvalidArgumentException('A non-empty repository-relative path is required.');
        }

        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path) === 1) {
            throw new InvalidArgumentException('Absolute paths are not allowed.');
        }

        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $segments = explode('/', $path);

        if (in_array('..', $segments, true) || in_array('.', $segments, true)) {
            throw new InvalidArgumentException('Path traversal is not allowed.');
        }

        $this->assertAllowedLocationPhp($path);
        $this->assertNotSensitivePhp($path);

        $absolutePath = realpath(base_path($path));
        if ($absolutePath === false) {
            throw new InvalidArgumentException("Path [{$path}] does not exist.");
        }

        $projectRoot = realpath(base_path());
        if ($projectRoot === false || ($absolutePath !== $projectRoot && ! str_starts_with($absolutePath, $projectRoot.DIRECTORY_SEPARATOR))) {
            throw new InvalidArgumentException('The resolved path is outside the project root.');
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', ltrim(substr($absolutePath, strlen($projectRoot)), DIRECTORY_SEPARATOR));
    }

    public function normalizeNative(string $path): ?string
    {
        if (! self::$useRustFfi) {
            return null;
        }

        $ffi = self::$ffi ?? self::resolveFfi();
        if ($ffi === null || $ffi === false || self::$outStatus === null) {
            return null;
        }

        $packed = self::packLocationConfig();
        if ($packed === null) {
            return null;
        }

        $len = \strlen($path);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, $len + 16), $cap);
        if ($scratch === null) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_mcp_normalize_relative_path(
                $path,
                $len,
                $packed['useDefaults'],
                $packed['rf'],
                \strlen($packed['rf']),
                $packed['ar'],
                \strlen($packed['ar']),
                $packed['ds'],
                \strlen($packed['ds']),
                FFI::addr(self::$outStatus),
                $scratch,
                $cap,
            );
            if (! \is_int($written) || $written === -1) {
                return null;
            }
            if ($written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_mcp_normalize_relative_path(
                    $path,
                    $len,
                    $packed['useDefaults'],
                    $packed['rf'],
                    \strlen($packed['rf']),
                    $packed['ar'],
                    \strlen($packed['ar']),
                    $packed['ds'],
                    \strlen($packed['ds']),
                    FFI::addr(self::$outStatus),
                    $scratch,
                    $cap,
                );
                if (! \is_int($written) || $written < 0) {
                    return null;
                }
            }
            // @phpstan-ignore property.notFound
            $status = (int) self::$outStatus->cdata;
        } catch (Throwable) {
            return null;
        }

        if ($status === 1) {
            throw new InvalidArgumentException('A non-empty repository-relative path is required.');
        }
        if ($status === 2) {
            throw new InvalidArgumentException('Absolute paths are not allowed.');
        }
        if ($status === 3) {
            throw new InvalidArgumentException('Path traversal is not allowed.');
        }

        $normalized = $written > 0 ? FFI::string($scratch, $written) : '';
        if ($status === 4) {
            throw new InvalidArgumentException("Path [{$normalized}] is outside the allowed source roots.");
        }
        if ($status === 5) {
            throw new InvalidArgumentException("Path [{$normalized}] is not available through MCP.");
        }
        if ($status === 6) {
            throw new InvalidArgumentException('Environment files other than .env.example are not available through MCP.');
        }
        if ($status === 7) {
            throw new InvalidArgumentException('Credential and private-key files are not available through MCP.');
        }
        if ($status !== 0 || $normalized === '') {
            return null;
        }

        $basePath = base_path();
        if (self::$cachedBasePath !== $basePath) {
            self::$cachedBasePath = $basePath;
            self::$cachedProjectRoot = realpath($basePath);
        }

        $absolutePath = realpath($basePath.DIRECTORY_SEPARATOR.$normalized);
        if ($absolutePath === false) {
            throw new InvalidArgumentException("Path [{$normalized}] does not exist.");
        }

        $projectRoot = self::$cachedProjectRoot;
        if ($projectRoot === false || $projectRoot === null || ($absolutePath !== $projectRoot && ! str_starts_with($absolutePath, $projectRoot.DIRECTORY_SEPARATOR))) {
            throw new InvalidArgumentException('The resolved path is outside the project root.');
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', ltrim(substr($absolutePath, strlen($projectRoot)), DIRECTORY_SEPARATOR));
    }

    /**
     * @param  array<int, string>  $paths
     * @return list<string>
     */
    public function normalizeMany(array $paths): array
    {
        $maximum = max(1, (int) config('nntmux-mcp.limits.max_paths', 20));
        if ($paths === [] || count($paths) > $maximum) {
            throw new InvalidArgumentException("Provide between 1 and {$maximum} paths.");
        }

        return array_values(array_unique(array_map($this->normalize(...), $paths)));
    }

    public function assertAllowedLocation(string $path): void
    {
        if (self::$useRustFfi && $this->assertAllowedLocationNative($path)) {
            return;
        }

        $this->assertAllowedLocationPhp($path);
    }

    public function assertAllowedLocationPhp(string $path): void
    {
        $rootFiles = (array) config('nntmux-mcp.allowed_root_files', []);
        if (in_array($path, $rootFiles, true)) {
            return;
        }

        $root = explode('/', $path, 2)[0];
        if (! in_array($root, (array) config('nntmux-mcp.allowed_roots', []), true)) {
            throw new InvalidArgumentException("Path [{$path}] is outside the allowed source roots.");
        }

        foreach ((array) config('nntmux-mcp.denied_segments', []) as $denied) {
            if ($path === $denied || str_starts_with($path, $denied.'/') || str_contains($path, '/'.$denied.'/')) {
                throw new InvalidArgumentException("Path [{$path}] is not available through MCP.");
            }
        }
    }

    public function assertAllowedLocationNative(string $path): bool
    {
        if (! self::$useRustFfi) {
            return false;
        }

        $ffi = self::$ffi ?? self::resolveFfi();
        if ($ffi === null || $ffi === false) {
            return false;
        }

        $packed = self::packLocationConfig();
        if ($packed === null) {
            return false;
        }

        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_mcp_check_allowed_location(
                $path,
                \strlen($path),
                $packed['useDefaults'],
                $packed['rf'],
                \strlen($packed['rf']),
                $packed['ar'],
                \strlen($packed['ar']),
                $packed['ds'],
                \strlen($packed['ds']),
            );
            if (! \is_int($status) || $status < 0) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        if ($status === 1) {
            throw new InvalidArgumentException("Path [{$path}] is outside the allowed source roots.");
        }
        if ($status === 2) {
            throw new InvalidArgumentException("Path [{$path}] is not available through MCP.");
        }

        return true;
    }

    public function assertNotSensitive(string $path): void
    {
        if (self::$useRustFfi && self::assertNotSensitiveNative($path)) {
            return;
        }

        $this->assertNotSensitivePhp($path);
    }

    public function assertNotSensitivePhp(string $path): void
    {
        foreach (explode('/', $path) as $segment) {
            $lower = strtolower($segment);

            if ($lower === '.env' || (str_starts_with($lower, '.env.') && $lower !== '.env.example')) {
                throw new InvalidArgumentException('Environment files other than .env.example are not available through MCP.');
            }

            if (preg_match('/(?:credential|private[_-]?key|id_rsa|id_ed25519|\.pem$|\.p12$|\.pfx$)/i', $segment) === 1) {
                throw new InvalidArgumentException('Credential and private-key files are not available through MCP.');
            }
        }
    }

    public static function assertNotSensitiveNative(string $path): bool
    {
        if (! self::$useRustFfi) {
            return false;
        }

        $ffi = self::$ffi ?? self::resolveFfi();
        if ($ffi === null || $ffi === false) {
            return false;
        }

        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_mcp_check_not_sensitive($path, \strlen($path));
            if (! \is_int($status) || $status < 0) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        if ($status === 1) {
            throw new InvalidArgumentException('Environment files other than .env.example are not available through MCP.');
        }
        if ($status === 2) {
            throw new InvalidArgumentException('Credential and private-key files are not available through MCP.');
        }

        return true;
    }

    /**
     * @return array{useDefaults: int, rf: string, ar: string, ds: string}|null
     */
    private static function packLocationConfig(): ?array
    {
        $mcp = (array) config('nntmux-mcp', []);
        $rootFiles = (array) ($mcp['allowed_root_files'] ?? []);
        $allowedRoots = (array) ($mcp['allowed_roots'] ?? []);
        $deniedSegments = (array) ($mcp['denied_segments'] ?? []);

        if (
            $rootFiles === self::DEFAULT_ALLOWED_ROOT_FILES
            && $allowedRoots === self::DEFAULT_ALLOWED_ROOTS
            && $deniedSegments === self::DEFAULT_DENIED_SEGMENTS
        ) {
            return ['useDefaults' => 1, 'rf' => '', 'ar' => '', 'ds' => ''];
        }

        foreach ([$rootFiles, $allowedRoots, $deniedSegments] as $list) {
            foreach ($list as $item) {
                if (! \is_string($item) || $item === '' || \str_contains($item, "\0")) {
                    return null;
                }
            }
        }

        return [
            'useDefaults' => 0,
            'rf' => implode("\0", $rootFiles),
            'ar' => implode("\0", $allowedRoots),
            'ds' => implode("\0", $deniedSegments),
        ];
    }

    private static function resolveFfi(): ?FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi === false ? null : self::$ffi;
        }
        $ffi = RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF);
        if ($ffi === null) {
            self::$ffi = false;

            return null;
        }
        try {
            self::$outStatus = $ffi->new('int32_t');
            self::$ffi = $ffi;

            return $ffi;
        } catch (Throwable) {
            self::$ffi = false;

            return null;
        }
    }

    // ---- Wave 57.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 4.99x/4.33x, uncommon 4.89x/4.38x); see RustMcpPathGuardRepoContextRedactorStatusTmuxCollectorW57ParityTest. */
    private const bool W57_RUST_FFI_DEFAULT = true;

    private const string W57_FFI_CDEF = <<<'C'
        intptr_t nntmux_w57_path_guard_plan_batch(const char *paths, size_t paths_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /**
     * Wave 57 routing, binding and output buffer, kept in function-local statics. Operations:
     * null reads the routing override, 'set' replaces it (returning the previous one), 'ffi'
     * resolves the binding and 'out' returns `[binding, buffer, capacity]` with room for at
     * least $val words (null without the library).
     */
    private static function w57RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w57', self::W57_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w57RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    public static function usesW57RustFfi(): bool
    {
        $enabled = self::w57RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W57_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with classifyPathBatch() routed to Rust ($enabled) or PHP, then restores
     * the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW57RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w57RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w57RustFfiState('set', $previous);
        }
    }

    /** @param  list<int>  $out */
    private static function w57Digest(array $out): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));

        return $digest[1];
    }

    /**
     * The words the export wrote, or null when it declined (returning anything but $words).
     *
     * @return list<int>|null
     */
    private static function w57Words(mixed $written, CData $out, int $words): ?array
    {
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : null;
    }

    /**
     * Batch safety classification under the default MCP path policy (the DEFAULT_* lists that
     * mirror config/nntmux-mcp.php): normalizePhp()'s checks short of realpath(), without
     * throwing. Per path: `status | min(length, 0xFFFFFF) << 8 | crc32(text) << 32`, text being
     * the trimmed, slash-converted path (status 1-2) or its slash-collapsed form (otherwise).
     * Status: 0 accepted, 1 empty or NUL, 2 absolute, 3 traversal, 4 outside the allowed roots,
     * 5 denied segment, 6 environment file, 7 credential or private key. The eight status
     * counts, the distinct accepted paths and the XXH64 digest of all earlier words follow.
     *
     * @param  list<string>  $paths
     * @return list<int>
     */
    public static function classifyPathBatch(array $paths): array
    {
        return self::usesW57RustFfi() ? self::classifyPathBatchNative($paths) : self::classifyPathBatchPhp($paths);
    }

    /**
     * @param  list<string>  $paths
     * @return list<int>
     */
    public static function classifyPathBatchPhp(array $paths): array
    {
        $out = $accepted = [];
        $counts = array_fill(0, 8, 0);
        foreach ($paths as $path) {
            $path = trim(str_replace('\\', '/', (string) $path));
            if ($path === '' || str_contains($path, "\0")) {
                $status = 1;
            } elseif (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path) === 1) {
                $status = 2;
            } else {
                $path = preg_replace('#/+#', '/', $path) ?? $path;
                $status = self::w57PathStatus($path, explode('/', $path));
                if ($status === 0) {
                    $accepted[$path] = true;
                }
            }
            $counts[$status]++;
            $out[] = $status | min(\strlen($path), 0xFFFFFF) << 8 | crc32($path) << 32;
        }
        array_push($out, ...$counts);
        $out[] = \count($accepted);
        $out[] = self::w57Digest($out);

        return $out;
    }

    /**
     * normalizePhp()'s traversal, location and sensitivity checks as a status (0, 3-7).
     *
     * @param  list<string>  $segments
     */
    private static function w57PathStatus(string $path, array $segments): int
    {
        if (\in_array('..', $segments, true) || \in_array('.', $segments, true)) {
            return 3;
        }
        if (! \in_array($path, self::DEFAULT_ALLOWED_ROOT_FILES, true)) {
            if (! \in_array($segments[0], self::DEFAULT_ALLOWED_ROOTS, true)) {
                return 4;
            }
            foreach (self::DEFAULT_DENIED_SEGMENTS as $denied) {
                if ($path === $denied || str_starts_with($path, $denied.'/') || str_contains($path, '/'.$denied.'/')) {
                    return 5;
                }
            }
        }
        foreach ($segments as $segment) {
            $lower = strtolower($segment);
            if ($lower === '.env' || (str_starts_with($lower, '.env.') && $lower !== '.env.example')) {
                return 6;
            }
            if (preg_match('/(?:credential|private[_-]?key|id_rsa|id_ed25519|\.pem$|\.p12$|\.pfx$)/i', $segment) === 1) {
                return 7;
            }
        }

        return 0;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a path holds a NUL byte, the
     * column is not a list or PCRE runs with locale-specific character tables.
     *
     * @param  list<string>  $paths
     * @return list<int>
     */
    public static function classifyPathBatchNative(array $paths): array
    {
        $count = \count($paths);
        $words = $count + 10;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w57RustFfiState('out', $words);
        if ($state === null || ! array_is_list($paths) || ! NzbParserService::pcreUsesDefaultTables()) {
            return self::classifyPathBatchPhp($paths);
        }
        $blob = implode("\0", $paths);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W57_FFI_CDEF.)
            $written = $ffi->nntmux_w57_path_guard_plan_batch($blob, \strlen($blob), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }

        return self::w57Words($written, $out, $words) ?? self::classifyPathBatchPhp($paths);
    }
}
