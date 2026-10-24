<?php

declare(strict_types=1);

namespace App\Services\Mcp;

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
}
