<?php

declare(strict_types=1);

namespace App\Support;

use FFI;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Throwable;

class UpdatePerformanceHelper
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

    /** @return list<string>|null */
    private static function decodeFrames(string $output): ?array
    {
        $values = [];
        $offset = 0;
        $size = strlen($output);
        while ($offset < $size) {
            if ($size - $offset < 4) {
                return null;
            }
            $header = unpack('Vlength', substr($output, $offset, 4));
            $length = $header['length'];
            $offset += 4;
            if ($length > $size - $offset) {
                return null;
            }
            $values[] = substr($output, $offset, $length);
            $offset += $length;
        }

        return $values;
    }

    /**
     * Check if a file has changed since last update
     */
    public static function hasFileChanged(string $filePath, ?string $cacheKey = null): bool
    {
        if (! File::exists($filePath)) {
            return false;
        }

        $cacheKey = $cacheKey ?? 'file_hash_'.md5($filePath);
        $currentHash = md5_file($filePath);
        $lastHash = Cache::get($cacheKey);

        if ($currentHash !== $lastHash) {
            Cache::put($cacheKey, $currentHash, now()->addDays(7));

            return true;
        }

        return false;
    }

    /**
     * Execute commands in parallel where possible
     *
     * @param  array<string, mixed>  $commands
     * @return array<string, mixed>
     */
    public static function executeParallel(array $commands, int $timeout = 300): array
    {
        $processes = [];
        $results = [];

        // Start all processes
        foreach ($commands as $key => $command) {
            $processes[$key] = Process::timeout($timeout)->start($command);
        }

        // Wait for all processes to complete
        foreach ($processes as $key => $process) {
            $result = $process->wait();
            $results[$key] = [
                'successful' => $result->successful(),
                'output' => $result->output(),
                'errorOutput' => $result->errorOutput(),
                'exitCode' => $result->exitCode(),
            ];
        }

        return $results;
    }

    /**
     * Clear various application caches efficiently
     *
     * @return array<string, mixed>
     */
    public static function clearAllCaches(): array
    {
        $cacheOperations = [
            'config' => fn () => Artisan::call('config:clear'),
            'route' => fn () => Artisan::call('route:clear'),
            'view' => fn () => Artisan::call('view:clear'),
            'cache' => fn () => Artisan::call('cache:clear'),
            'opcache' => fn () => function_exists('opcache_reset') ? opcache_reset() : true,
        ];

        $results = [];
        foreach ($cacheOperations as $type => $operation) {
            try {
                $operation();
                $results[$type] = 'success';
            } catch (\Exception $e) {
                $results[$type] = 'failed: '.$e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Optimize file permissions for better performance
     */
    public static function optimizePermissions(): void
    {
        $paths = [
            base_path('bootstrap/cache'),
            base_path('storage'),
            base_path('storage/logs'),
            base_path('storage/framework/cache'),
            base_path('storage/framework/sessions'),
            base_path('storage/framework/views'),
        ];

        foreach ($paths as $path) {
            if (File::exists($path)) {
                chmod($path, 0755);
            }
        }
    }

    /**
     * Check system resources and recommend optimizations
     *
     * @return array<string, mixed>
     */
    public static function checkSystemResources(): array
    {
        $recommendations = [];

        // Check system memory usage
        $memInfo = self::getSystemMemoryInfo();
        if ($memInfo && $memInfo['usage_percent'] > 80) {
            $recommendations[] = sprintf(
                'High system memory usage: %.1f%% (%.1fGB of %.1fGB used)',
                $memInfo['usage_percent'],
                $memInfo['used'] / 1024 / 1024 / 1024,
                $memInfo['total'] / 1024 / 1024 / 1024
            );
        }

        // Still check PHP memory separately
        $phpMemoryUsage = memory_get_usage(true);
        $phpMemoryLimit = self::parseMemoryLimit(ini_get('memory_limit'));
        if ($phpMemoryLimit > 0 && $phpMemoryUsage > ($phpMemoryLimit * 0.8)) {
            $recommendations[] = sprintf(
                'PHP memory usage high: %.1fMB of %s',
                $phpMemoryUsage / 1024 / 1024,
                ini_get('memory_limit')
            );
        }

        // Check disk space
        $freeSpace = disk_free_space(base_path());
        $totalSpace = disk_total_space(base_path());

        if ($freeSpace < ($totalSpace * 0.1)) {
            $recommendations[] = 'Low disk space detected';
        }

        // Check PHP extensions
        $requiredExtensions = ['pdo', 'mbstring', 'openssl', 'json', 'curl'];
        foreach ($requiredExtensions as $ext) {
            if (! extension_loaded($ext)) {
                $recommendations[] = "Missing PHP extension: $ext";
            }
        }

        return $recommendations;
    }

    /**
     * Get system memory information
     *
     * @return array{total: int|float, available: int|float, used: int|float, usage_percent: float|int}|null
     */
    private static function getSystemMemoryInfo(): ?array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return null; // Windows memory check would require different approach
        }

        $memInfo = @file_get_contents('/proc/meminfo');
        if (! $memInfo) {
            return null;
        }

        return self::parseMemoryInfo($memInfo) ?: null;
    }

    /** @return array{total: int|float, available: int|float, used: int|float, usage_percent: float|int}|false */
    public static function parseMemoryInfo(string $memInfo): array|false
    {
        return (self::$useRustFfi ? self::parseMemoryInfoNative($memInfo) : null) ?? self::parseMemoryInfoPhp($memInfo);
    }

    /** @return array{total: int|float, available: int|float, used: int|float, usage_percent: float|int}|false */
    public static function parseMemoryInfoPhp(string $memInfo): array|false
    {
        preg_match('/MemTotal:\s+(\d+)/', $memInfo, $totalMatch);
        preg_match('/MemAvailable:\s+(\d+)/', $memInfo, $availableMatch);

        if (! isset($totalMatch[1]) || ! isset($availableMatch[1])) {
            return false;
        }

        $total = (int) $totalMatch[1] * 1024; // Convert from KB to bytes
        $available = (int) $availableMatch[1] * 1024;
        $used = $total - $available;

        return [
            'total' => $total,
            'available' => $available,
            'used' => $used,
            'usage_percent' => ($used / $total) * 100,
        ];
    }

    /**
     * Parse memory limit string to bytes
     */
    public static function parseMemoryLimit(string $limit): int
    {
        return (self::$useRustFfi ? self::parseMemoryLimitNative($limit) : null) ?? self::parseMemoryLimitPhp($limit);
    }

    public static function parseMemoryLimitPhp(string $limit): int
    {
        $limit = trim($limit);
        if ($limit === '-1') {
            return -1;
        }

        $last = strtolower($limit[-1]);

        $value = match ($last) {
            'g' => (int) $limit * 1024 * 1024 * 1024,
            'm' => (int) $limit * 1024 * 1024,
            'k' => (int) $limit * 1024,
            default => (int) $limit,
        };

        return $value;
    }

    /**
     * Create a system performance snapshot
     *
     * @return array<string, mixed>
     */
    public static function createPerformanceSnapshot(): array
    {
        return [
            'timestamp' => now()->toISOString(),
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'execution_time' => microtime(true) - LARAVEL_START,
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'disk_free' => disk_free_space(base_path()),
            'load_average' => function_exists('sys_getloadavg') ? sys_getloadavg() : null,
        ];
    }

    /** @return array{total: int|float, available: int|float, used: int|float, usage_percent: float|int}|false|null */
    public static function parseMemoryInfoNative(string $memInfo): array|false|null
    {
        if ((int) ini_get('pcre.backtrack_limit') < strlen($memInfo) + 16) {
            return null;
        }
        $output = self::transformNative(10, $memInfo);
        if (! is_string($output)) {
            return $output;
        }
        $parts = self::decodeFrames($output);
        if ($parts === null || count($parts) !== 2) {
            return null;
        }
        $total = (int) $parts[0] * 1024;
        $available = (int) $parts[1] * 1024;
        $used = $total - $available;

        return ['total' => $total, 'available' => $available, 'used' => $used, 'usage_percent' => ($used / $total) * 100];
    }

    public static function parseMemoryLimitNative(string $limit): ?int
    {
        $output = self::transformNative(11, $limit);

        return is_string($output) ? (int) $output : null;
    }
}
