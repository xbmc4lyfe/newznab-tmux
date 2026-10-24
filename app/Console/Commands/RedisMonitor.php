<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\RedisMonitorService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Throwable;

class RedisMonitor extends Command
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_redis_format_bytes(int64_t bytes, char *out_buf, size_t out_cap);
        ssize_t nntmux_redis_format_duration(int64_t seconds, char *out_buf, size_t out_cap);
        double nntmux_redis_hit_rate(int64_t hits, int64_t misses);
        C;

    private static bool $useRustFfi = false;

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    private static function ffi(): ?FFI
    {
        if (! self::$ffiResolved) {
            self::$ffi = RustPipelineLibrary::resolveDomain('irc', self::FFI_CDEF);
            self::$ffiResolved = true;
        }

        return self::$ffi;
    }

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'redis:monitor
                            {--connection=default : Redis connection name from config/database.php}
                            {--refresh=30 : Refresh interval in seconds}
                            {--once : Display stats once and exit}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor Redis server with a visual dashboard';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $connection = $this->option('connection');
        $refresh = (int) $this->option('refresh');
        $once = $this->option('once');

        try {
            $service = new RedisMonitorService($connection, $refresh);

            if ($once) {
                $stats = $service->getStats();

                if (! $stats['connected']) {
                    $this->error("Cannot connect to Redis at {$stats['host']}:{$stats['port']}");

                    return Command::FAILURE;
                }

                $this->displayStats($stats);

                return Command::SUCCESS;
            }

            // Start the continuous monitor
            $service->monitor();

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Redis Monitor failed: '.$e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * Display stats in table format (for --once mode).
     *
     * @param  array<string, mixed>  $stats
     */
    protected function displayStats(array $stats): void
    {
        $this->newLine();
        $this->line("🔴 Redis Monitor - {$stats['host']}:{$stats['port']} (v{$stats['version']})");
        $this->newLine();

        $this->line('📊 Server Status');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Status', '● ONLINE'],
                ['Role', $stats['role']],
                ['Uptime', "{$stats['uptime_days']} days"],
            ]
        );

        $this->newLine();
        $this->line('💾 Memory Usage');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Used Memory', $this->formatBytes($stats['used_memory'])],
                ['Peak Memory', $this->formatBytes($stats['used_memory_peak'])],
                ['RSS Memory', $this->formatBytes($stats['used_memory_rss'])],
                ['Fragmentation Ratio', $stats['mem_fragmentation_ratio']],
            ]
        );

        $this->newLine();
        $this->line('👥 Clients & Connections');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Connected Clients', number_format($stats['connected_clients'])],
                ['Blocked Clients', number_format($stats['blocked_clients'])],
                ['Total Connections', number_format($stats['total_connections'])],
            ]
        );

        $this->newLine();
        $this->line('⚡ Performance Metrics');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Commands Processed', number_format($stats['total_commands'])],
                ['Operations/sec', number_format($stats['ops_per_sec']).' ops/s'],
                ['Network Input', $stats['input_kbps'].' KB/s'],
                ['Network Output', $stats['output_kbps'].' KB/s'],
            ]
        );

        $this->newLine();
        $this->line('🔑 Keyspace Statistics');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Keys (db0)', number_format($stats['db_keys'])],
                ['Keyspace Hits', number_format($stats['keyspace_hits'])],
                ['Keyspace Misses', number_format($stats['keyspace_misses'])],
                ['Hit Rate', $stats['hit_rate'].'%'],
                ['Expired Keys', number_format($stats['expired_keys'])],
                ['Evicted Keys', number_format($stats['evicted_keys'])],
            ]
        );
    }

    /**
     * Format bytes into human-readable string.
     */
    public function formatBytes(int $bytes): string
    {
        if (self::$useRustFfi) {
            $native = $this->formatBytesNative($bytes);
            if ($native !== null) {
                return $native;
            }
        }

        return $this->formatBytesPhp($bytes);
    }

    public function formatBytesNative(int $bytes): ?string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, 64, $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $rc = (int) $ffi->nntmux_redis_format_bytes($bytes, $scratch, $cap);
            if ($rc <= -2) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$rc, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $rc = (int) $ffi->nntmux_redis_format_bytes($bytes, $scratch, $cap);
            }
            if ($rc <= 0) {
                return null;
            }

            return FFI::string($scratch, $rc);
        } catch (Throwable) {
            return null;
        }
    }

    public function formatBytesPhp(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return sprintf('%.2f GB', $bytes / 1073741824);
        } elseif ($bytes >= 1048576) {
            return sprintf('%.2f MB', $bytes / 1048576);
        } elseif ($bytes >= 1024) {
            return sprintf('%.2f KB', $bytes / 1024);
        }

        return $bytes.' B';
    }

    /**
     * Format duration in seconds into a human-readable string (`{d}d {h}h {m}m {s}s`).
     */
    public function formatDuration(int $seconds): string
    {
        if (self::$useRustFfi) {
            $native = $this->formatDurationNative($seconds);
            if ($native !== null) {
                return $native;
            }
        }

        return $this->formatDurationPhp($seconds);
    }

    public function formatDurationNative(int $seconds): ?string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, 64, $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $rc = (int) $ffi->nntmux_redis_format_duration($seconds, $scratch, $cap);
            if ($rc <= -2) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$rc, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $rc = (int) $ffi->nntmux_redis_format_duration($seconds, $scratch, $cap);
            }
            if ($rc <= 0) {
                return null;
            }

            return FFI::string($scratch, $rc);
        } catch (Throwable) {
            return null;
        }
    }

    public function formatDurationPhp(int $seconds): string
    {
        $prefix = $seconds < 0 ? '-' : '';
        $u = abs($seconds);
        $days = intdiv($u, 86400);
        $hours = intdiv($u % 86400, 3600);
        $mins = intdiv($u % 3600, 60);
        $secs = $u % 60;

        if ($days > 0) {
            return sprintf('%s%dd %dh %dm %ds', $prefix, $days, $hours, $mins, $secs);
        }
        if ($hours > 0) {
            return sprintf('%s%dh %dm %ds', $prefix, $hours, $mins, $secs);
        }
        if ($mins > 0) {
            return sprintf('%s%dm %ds', $prefix, $mins, $secs);
        }

        return sprintf('%s%ds', $prefix, $secs);
    }

    /**
     * Calculate Redis keyspace hit rate percentage (`round(($hits * 100) / ($hits + $misses), 2)`).
     */
    public function calculateHitRate(int $hits, int $misses): float
    {
        if (self::$useRustFfi) {
            $native = $this->calculateHitRateNative($hits, $misses);
            if ($native !== null) {
                return $native;
            }
        }

        return $this->calculateHitRatePhp($hits, $misses);
    }

    public function calculateHitRateNative(int $hits, int $misses): ?float
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $rate = (float) $ffi->nntmux_redis_hit_rate($hits, $misses);
            if ($rate < 0.0) {
                return null;
            }

            return $rate;
        } catch (Throwable) {
            return null;
        }
    }

    public function calculateHitRatePhp(int $hits, int $misses): float
    {
        $totalOps = $hits + $misses;

        return $totalOps > 0 ? round(($hits * 100) / $totalOps, 2) : 0.0;
    }
}
