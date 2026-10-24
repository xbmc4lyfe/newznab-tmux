<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Service for monitoring Redis server status and performance metrics.
 *
 * Provides a modern visual dashboard displaying Redis statistics including
 * memory usage, client connections, performance metrics, and keyspace data.
 */
class RedisMonitorService
{
    public const string FFI_CDEF = <<<'C'
        int64_t nntmux_redis_sum_db_keys_nul(const char *input, size_t input_len, size_t count);
        double nntmux_redis_hit_rate(int64_t hits, int64_t misses);
        ssize_t nntmux_redis_format_bytes(int64_t bytes, char *out_buf, size_t out_cap);
        C;

    private const array DB_KEY_MAP = [
        'db0' => true, 'db1' => true, 'db2' => true, 'db3' => true,
        'db4' => true, 'db5' => true, 'db6' => true, 'db7' => true,
        'db8' => true, 'db9' => true, 'db10' => true, 'db11' => true,
        'db12' => true, 'db13' => true, 'db14' => true, 'db15' => true,
    ];

    private static bool $useRustFfi = true;

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

    // ANSI color codes
    private const RED = "\033[0;31m";

    private const GREEN = "\033[0;32m";

    private const YELLOW = "\033[1;33m";

    /** @phpstan-ignore classConstant.unused */
    private const BLUE = "\033[0;34m";

    private const MAGENTA = "\033[0;35m";

    private const CYAN = "\033[0;36m";

    private const WHITE = "\033[1;37m";

    private const GRAY = "\033[0;90m";

    private const BOLD = "\033[1m";

    private const DIM = "\033[2m";

    private const NC = "\033[0m"; // No Color

    // Box drawing characters
    private const H_LINE = '─';

    private const V_LINE = '│';

    private const TL_CORNER = '┌';

    private const TR_CORNER = '┐';

    private const BL_CORNER = '└';

    private const BR_CORNER = '┘';

    private const T_RIGHT = '├';

    private const T_LEFT = '┤';

    private const CLEAR_EOL = "\033[K";

    protected string $host;

    protected int $port;

    protected int $refreshInterval;

    protected ?string $connection;

    protected int $termWidth;

    protected int $boxWidth;

    protected bool $running = true;

    /**
     * Create a new RedisMonitorService instance.
     *
     * @param  string|null  $connection  Redis connection name (from config/database.php)
     * @param  int  $refreshInterval  Refresh interval in seconds
     */
    public function __construct(?string $connection = 'default', int $refreshInterval = 30)
    {
        $this->connection = $connection;
        $this->refreshInterval = $refreshInterval;

        // Get connection config
        $config = config("database.redis.{$connection}", config('database.redis.default'));
        $this->host = $config['host'] ?? '127.0.0.1';
        $this->port = (int) ($config['port'] ?? 6379);

        // Get terminal width
        $this->termWidth = $this->getTerminalWidth();
        $this->boxWidth = min($this->termWidth - 2, 100); // Cap at 100 for readability
    }

    /**
     * Get terminal width.
     */
    protected function getTerminalWidth(): int
    {
        $width = 80;
        if (function_exists('exec')) {
            $output = [];
            @exec('tput cols 2>/dev/null', $output);
            if (! empty($output[0]) && is_numeric($output[0])) {
                $width = (int) $output[0];
            }
        }

        return max($width, 60);
    }

    /**
     * Get Redis INFO data.
     *
     * @return array<string, mixed>
     */
    public function getRedisInfo(): ?array
    {
        try {
            return Redis::connection($this->connection)->info();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Start the monitor loop.
     *
     * @param  callable|null  $shouldContinue  Optional callback to control loop continuation
     */
    public function monitor(?callable $shouldContinue = null): void
    {
        // Disable output buffering for immediate display
        while (ob_get_level()) {
            ob_end_flush();
        }

        // Hide cursor
        $this->hideCursor();

        // Setup cleanup handler
        $cleanup = function () {
            $this->showCursor();
            echo self::NC; // Reset colors
        };

        // Register shutdown handler
        register_shutdown_function($cleanup);

        // Handle signals if pcntl is available
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () use ($cleanup) {
                $this->running = false;
                $cleanup();
                exit(0);
            });
            pcntl_signal(SIGTERM, function () use ($cleanup) {
                $this->running = false;
                $cleanup();
                exit(0);
            });
        }

        // Initial clear
        $this->clearScreen();

        while ($this->running) {
            // Check if we should continue
            if ($shouldContinue !== null && ! $shouldContinue()) {
                break;
            }

            // Process signals if available
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            // Move cursor to top-left (prevents flicker, better than clearing)
            $this->moveToTop();

            // Get Redis info
            $info = $this->getRedisInfo();

            if ($info === null) {
                $this->displayConnectionError();
            } else {
                $this->displayDashboard($info);
            }

            // Flush output immediately
            if (function_exists('flush')) {
                flush();
            }

            // Sleep in small increments to be responsive to signals
            $sleepTime = $this->refreshInterval;
            while ($sleepTime > 0 && $this->running) {
                sleep(1);
                $sleepTime--;

                // Process signals during sleep
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
            }
        }

        $cleanup();
    }

    /**
     * Get a single snapshot of Redis stats without entering monitor mode.
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        $info = $this->getRedisInfo();

        if ($info === null) {
            return [
                'connected' => false,
                'host' => $this->host,
                'port' => $this->port,
            ];
        }

        return $this->parseRedisInfo($info);
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
     * Parse Redis INFO into structured data.
     *
     * @param  array<string, mixed>  $info
     * @return array<string, mixed>
     */
    protected function parseRedisInfo(array $info): array
    {
        if (! self::$useRustFfi) {
            return $this->parseRedisInfoPhp($info);
        }

        $dbs = null;
        if (isset($info['redis_version']) && ! is_array(end($info))) {
            $flatInfo = $info;
        } elseif ($info !== [] && is_array(reset($info)) && is_array(end($info))) {
            try {
                if (isset($info['Keyspace']) && is_array($info['Keyspace'])) {
                    $ks = $info['Keyspace'];
                    $copy = $info;
                    unset($copy['Keyspace']);
                    /** @var array<int, array<string, mixed>> $sections */
                    $sections = array_values($copy);
                    $flatInfo = $sections === [] ? [] : array_replace(...$sections);
                    $dbs = array_intersect_key($ks, self::DB_KEY_MAP) + array_intersect_key($flatInfo, self::DB_KEY_MAP);
                } else {
                    /** @var array<int, array<string, mixed>> $sections */
                    $sections = array_values($info);
                    $flatInfo = array_replace(...$sections);
                }
            } catch (\TypeError) {
                $dbs = null;
                $flatInfo = [];
                foreach ($info as $k => $v) {
                    if (is_array($v)) {
                        $flatInfo = $v + $flatInfo;
                    } else {
                        $flatInfo[$k] = $v;
                    }
                }
            }
        } else {
            $flatInfo = $info;
            foreach ($info as $value) {
                if (is_array($value)) {
                    $flatInfo = [];
                    foreach ($info as $k => $v) {
                        if (is_array($v)) {
                            $flatInfo = $v + $flatInfo;
                        } else {
                            $flatInfo[$k] = $v;
                        }
                    }
                    break;
                }
            }
        }

        $hits = (int) ($flatInfo['keyspace_hits'] ?? 0);
        $misses = (int) ($flatInfo['keyspace_misses'] ?? 0);
        $totalOps = $hits + $misses;
        $hitRate = $totalOps > 0 ? round(($hits * 100) / $totalOps, 2) : 0;

        $dbs ??= array_intersect_key($flatInfo, self::DB_KEY_MAP);
        $dbKeys = 0;
        if ($dbs !== []) {
            $allStrings = is_string(reset($dbs)) && is_string(end($dbs)) && (count($dbs) <= 2 || is_string(next($dbs)));
            if ($allStrings) {
                $ffi = self::$ffiResolved ? self::$ffi : self::ffi();
                $nativeSum = null;
                if ($ffi !== null) {
                    $joined = implode("\0", $dbs);
                    // @phpstan-ignore method.notFound
                    $sum = $ffi->nntmux_redis_sum_db_keys_nul($joined, strlen($joined), count($dbs));
                    if (is_int($sum) && $sum >= 0) {
                        $nativeSum = $sum;
                    }
                }
                if ($nativeSum !== null) {
                    $dbKeys = $nativeSum;
                } else {
                    foreach ($dbs as $spec) {
                        if (preg_match('/keys=(\d+)/', $spec, $matches)) {
                            $dbKeys += (int) $matches[1];
                        }
                    }
                }
            } else {
                $stringSpecs = [];
                foreach ($dbs as $dbValue) {
                    if (is_string($dbValue)) {
                        $stringSpecs[] = $dbValue;
                    } elseif (is_array($dbValue)) {
                        $dbKeys += (int) ($dbValue['keys'] ?? 0);
                    }
                }
                if ($stringSpecs !== []) {
                    $nativeSum = self::sumDbKeysNative($stringSpecs);
                    if ($nativeSum !== null) {
                        $dbKeys += $nativeSum;
                    } else {
                        foreach ($stringSpecs as $spec) {
                            if (preg_match('/keys=(\d+)/', $spec, $matches)) {
                                $dbKeys += (int) $matches[1];
                            }
                        }
                    }
                }
            }
        }

        return [
            'connected' => true,
            'host' => $this->host,
            'port' => $this->port,
            'version' => $flatInfo['redis_version'] ?? 'unknown',
            'uptime_days' => (int) ($flatInfo['uptime_in_days'] ?? 0),
            'uptime_seconds' => (int) ($flatInfo['uptime_in_seconds'] ?? 0),
            'role' => $flatInfo['role'] ?? 'standalone',
            'connected_clients' => (int) ($flatInfo['connected_clients'] ?? 0),
            'blocked_clients' => (int) ($flatInfo['blocked_clients'] ?? 0),
            'total_connections' => (int) ($flatInfo['total_connections_received'] ?? 0),
            'connected_slaves' => (int) ($flatInfo['connected_slaves'] ?? 0),
            'used_memory' => (int) ($flatInfo['used_memory'] ?? 0),
            'used_memory_peak' => (int) ($flatInfo['used_memory_peak'] ?? 0),
            'used_memory_rss' => (int) ($flatInfo['used_memory_rss'] ?? 0),
            'maxmemory' => (int) ($flatInfo['maxmemory'] ?? 0),
            'mem_fragmentation_ratio' => $flatInfo['mem_fragmentation_ratio'] ?? 'N/A',
            'total_commands' => (int) ($flatInfo['total_commands_processed'] ?? 0),
            'ops_per_sec' => (int) ($flatInfo['instantaneous_ops_per_sec'] ?? 0),
            'input_kbps' => (float) ($flatInfo['instantaneous_input_kbps'] ?? 0),
            'output_kbps' => (float) ($flatInfo['instantaneous_output_kbps'] ?? 0),
            'db_keys' => $dbKeys,
            'keyspace_hits' => $hits,
            'keyspace_misses' => $misses,
            'hit_rate' => $hitRate,
            'expired_keys' => (int) ($flatInfo['expired_keys'] ?? 0),
            'evicted_keys' => (int) ($flatInfo['evicted_keys'] ?? 0),
            'rdb_changes' => (int) ($flatInfo['rdb_changes_since_last_save'] ?? 0),
            'rdb_last_save' => (int) ($flatInfo['rdb_last_save_time'] ?? 0),
        ];
    }

    /**
     * @param  list<string>  $specs
     */
    public static function sumDbKeysNative(array $specs): ?int
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $count = count($specs);
        if ($count === 0) {
            return 0;
        }
        $joined = implode("\0", $specs);
        if (substr_count($joined, "\0") !== $count - 1) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $sum = $ffi->nntmux_redis_sum_db_keys_nul($joined, strlen($joined), $count);

            return is_int($sum) && $sum >= 0 ? $sum : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function hitRateNative(int $hits, int $misses): int|float|null
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        if ($hits + $misses <= 0) {
            return 0;
        }
        try {
            // @phpstan-ignore method.notFound
            $rate = $ffi->nntmux_redis_hit_rate($hits, $misses);

            return is_float($rate) && $rate >= 0.0 ? $rate : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $info
     * @return array<string, mixed>
     */
    private function parseRedisInfoPhp(array $info): array
    {
        // Flatten nested arrays if present
        $flatInfo = [];
        foreach ($info as $key => $value) {
            if (is_array($value)) {
                $flatInfo = array_merge($flatInfo, $value);
            } else {
                $flatInfo[$key] = $value;
            }
        }

        // Calculate hit rate
        $hits = (int) ($flatInfo['keyspace_hits'] ?? 0);
        $misses = (int) ($flatInfo['keyspace_misses'] ?? 0);
        $totalOps = $hits + $misses;
        $hitRate = $totalOps > 0 ? round(($hits * 100) / $totalOps, 2) : 0;

        // Parse db keys from all databases
        $dbKeys = 0;
        for ($i = 0; $i <= 15; $i++) {
            $dbKey = "db{$i}";
            if (isset($flatInfo[$dbKey])) {
                $dbValue = $flatInfo[$dbKey];
                if (is_array($dbValue)) {
                    // phpredis returns array with 'keys' key
                    $dbKeys += (int) ($dbValue['keys'] ?? 0);
                } elseif (is_string($dbValue)) {
                    // String format: "keys=123,expires=0,avg_ttl=0"
                    if (preg_match('/keys=(\d+)/', $dbValue, $matches)) {
                        $dbKeys += (int) $matches[1];
                    }
                }
            }
        }

        return [
            'connected' => true,
            'host' => $this->host,
            'port' => $this->port,
            'version' => $flatInfo['redis_version'] ?? 'unknown',
            'uptime_days' => (int) ($flatInfo['uptime_in_days'] ?? 0),
            'uptime_seconds' => (int) ($flatInfo['uptime_in_seconds'] ?? 0),
            'role' => $flatInfo['role'] ?? 'standalone',
            'connected_clients' => (int) ($flatInfo['connected_clients'] ?? 0),
            'blocked_clients' => (int) ($flatInfo['blocked_clients'] ?? 0),
            'total_connections' => (int) ($flatInfo['total_connections_received'] ?? 0),
            'connected_slaves' => (int) ($flatInfo['connected_slaves'] ?? 0),
            'used_memory' => (int) ($flatInfo['used_memory'] ?? 0),
            'used_memory_peak' => (int) ($flatInfo['used_memory_peak'] ?? 0),
            'used_memory_rss' => (int) ($flatInfo['used_memory_rss'] ?? 0),
            'maxmemory' => (int) ($flatInfo['maxmemory'] ?? 0),
            'mem_fragmentation_ratio' => $flatInfo['mem_fragmentation_ratio'] ?? 'N/A',
            'total_commands' => (int) ($flatInfo['total_commands_processed'] ?? 0),
            'ops_per_sec' => (int) ($flatInfo['instantaneous_ops_per_sec'] ?? 0),
            'input_kbps' => (float) ($flatInfo['instantaneous_input_kbps'] ?? 0),
            'output_kbps' => (float) ($flatInfo['instantaneous_output_kbps'] ?? 0),
            'db_keys' => $dbKeys,
            'keyspace_hits' => $hits,
            'keyspace_misses' => $misses,
            'hit_rate' => $hitRate,
            'expired_keys' => (int) ($flatInfo['expired_keys'] ?? 0),
            'evicted_keys' => (int) ($flatInfo['evicted_keys'] ?? 0),
            'rdb_changes' => (int) ($flatInfo['rdb_changes_since_last_save'] ?? 0),
            'rdb_last_save' => (int) ($flatInfo['rdb_last_save_time'] ?? 0),
        ];
    }

    /**
     * Display the Redis monitor dashboard.
     *
     * @param  array<string, mixed>  $info
     */
    protected function displayDashboard(array $info): void
    {
        $stats = $this->parseRedisInfo($info);
        $currentTime = now()->format('Y-m-d H:i:s');

        // Build the entire output as a single string for atomic write
        $buffer = '';

        // Header
        $buffer .= $this->printHeader("🔴 REDIS MONITOR v{$stats['version']} | {$this->host}:{$this->port}");

        // Server Status
        $buffer .= $this->printSection('Server Status');
        $buffer .= $this->printRow('Status', '● ONLINE', self::GREEN);
        $buffer .= $this->printRow('Role', $stats['role'], self::MAGENTA);
        $buffer .= $this->printRow('Uptime', "{$stats['uptime_days']} days (".$this->formatNumber($stats['uptime_seconds']).' seconds)', self::WHITE);
        $buffer .= $this->printRow('Last Updated', $currentTime, self::GRAY);

        // Memory Usage
        $buffer .= $this->printSection('Memory Usage');
        $buffer .= $this->printRow('Used Memory', $this->formatBytes($stats['used_memory']), self::CYAN);
        $buffer .= $this->printRow('Peak Memory', $this->formatBytes($stats['used_memory_peak']), self::YELLOW);
        $buffer .= $this->printRow('RSS Memory', $this->formatBytes($stats['used_memory_rss']), self::WHITE);
        $buffer .= $this->printRow('Fragmentation Ratio', (string) $stats['mem_fragmentation_ratio'], self::WHITE);

        if ($stats['maxmemory'] > 0) {
            $buffer .= $this->printMeter('Memory Usage', $stats['used_memory'], $stats['maxmemory']);
        }

        // Clients & Connections
        $buffer .= $this->printSection('Clients & Connections');
        $buffer .= $this->printRow('Connected Clients', $this->formatNumber($stats['connected_clients']), self::GREEN);
        $buffer .= $this->printRow('Blocked Clients', $this->formatNumber($stats['blocked_clients']), self::YELLOW);
        $buffer .= $this->printRow('Total Connections', $this->formatNumber($stats['total_connections']), self::WHITE);
        if ($stats['connected_slaves'] > 0) {
            $buffer .= $this->printRow('Connected Slaves', (string) $stats['connected_slaves'], self::MAGENTA);
        }

        // Performance Metrics
        $buffer .= $this->printSection('Performance Metrics');
        $buffer .= $this->printRow('Commands Processed', $this->formatNumber($stats['total_commands']), self::WHITE);
        $buffer .= $this->printRow('Operations/sec', $this->formatNumber($stats['ops_per_sec']).' ops/s', self::GREEN);
        $buffer .= $this->printRow('Network Input', sprintf('%.2f KB/s', $stats['input_kbps']), self::CYAN);
        $buffer .= $this->printRow('Network Output', sprintf('%.2f KB/s', $stats['output_kbps']), self::CYAN);

        // Keyspace Statistics
        $buffer .= $this->printSection('Keyspace Statistics');
        $buffer .= $this->printRow('Total Keys', $this->formatNumber($stats['db_keys']), self::WHITE);
        $buffer .= $this->printRow('Keyspace Hits', $this->formatNumber($stats['keyspace_hits']), self::GREEN);
        $buffer .= $this->printRow('Keyspace Misses', $this->formatNumber($stats['keyspace_misses']), self::RED);
        $buffer .= $this->printRow('Hit Rate', $stats['hit_rate'].'%', self::CYAN);
        $buffer .= $this->printRow('Expired Keys', $this->formatNumber($stats['expired_keys']), self::YELLOW);
        $buffer .= $this->printRow('Evicted Keys', $this->formatNumber($stats['evicted_keys']), self::RED);

        // Persistence
        $buffer .= $this->printSection('Persistence');
        $buffer .= $this->printRow('Changes Since Save', $this->formatNumber($stats['rdb_changes']), self::WHITE);
        if ($stats['rdb_last_save'] > 0) {
            $lastSave = date('Y-m-d H:i:s', $stats['rdb_last_save']);
            $buffer .= $this->printRow('Last Save', $lastSave, self::GRAY);
        }

        $buffer .= $this->printFooter();
        $buffer .= self::DIM." Press Ctrl+C to exit | Refresh: {$this->refreshInterval}s".self::NC.self::CLEAR_EOL."\n";

        // Clear any remaining content below
        $buffer .= "\033[J";

        // Output the entire buffer at once
        echo $buffer;
    }

    /**
     * Display connection error screen.
     */
    protected function displayConnectionError(): void
    {
        $buffer = '';
        $buffer .= $this->printHeader('⚠ REDIS MONITOR - CONNECTION FAILED');
        $buffer .= $this->printRow('Status', '● OFFLINE - Cannot connect to Redis', self::RED);
        $buffer .= $this->printRow('Host', "{$this->host}:{$this->port}", self::YELLOW);
        $buffer .= $this->printRow('Retrying in', "{$this->refreshInterval} seconds...", self::GRAY);
        $buffer .= $this->printFooter();
        $buffer .= self::DIM." Press Ctrl+C to exit | Refresh: {$this->refreshInterval}s".self::NC.self::CLEAR_EOL."\n";
        $buffer .= "\033[J";

        echo $buffer;
    }

    /**
     * Print dashboard header.
     */
    protected function printHeader(string $title): string
    {
        // Strip emoji for length calculation (emoji can be multi-byte)
        $titleLen = mb_strlen(preg_replace('/[\x{1F300}-\x{1F9FF}]/u', '  ', $title));
        $padding = max(0, (int) (($this->boxWidth - $titleLen - 2) / 2));
        $line = str_repeat(self::H_LINE, $this->boxWidth);

        $buffer = self::CYAN.self::TL_CORNER.$line.self::TR_CORNER.self::NC.self::CLEAR_EOL."\n";
        $buffer .= self::CYAN.self::V_LINE.self::NC;
        $buffer .= str_repeat(' ', $padding);
        $buffer .= self::BOLD.self::WHITE.$title.self::NC;
        $buffer .= str_repeat(' ', max(0, $this->boxWidth - $padding - $titleLen));
        $buffer .= self::CYAN.self::V_LINE.self::NC.self::CLEAR_EOL."\n";
        $buffer .= self::CYAN.self::T_RIGHT.$line.self::T_LEFT.self::NC.self::CLEAR_EOL."\n";

        return $buffer;
    }

    /**
     * Print section header.
     */
    protected function printSection(string $title): string
    {
        $remainingWidth = $this->boxWidth - mb_strlen($title) - 5;
        $line = str_repeat(self::H_LINE, max(0, $remainingWidth));

        return self::CYAN.self::T_RIGHT.self::H_LINE.self::H_LINE.self::NC.' '
            .self::YELLOW.self::BOLD.$title.self::NC.' '
            .self::CYAN.$line.self::T_LEFT.self::NC.self::CLEAR_EOL."\n";
    }

    /**
     * Print a data row.
     */
    protected function printRow(string $label, string $value, string $color = self::WHITE): string
    {
        $labelWidth = 25;
        $valueWidth = $this->boxWidth - $labelWidth - 3;

        $label = str_pad($label, $labelWidth);
        $value = str_pad(mb_substr($value, 0, $valueWidth), $valueWidth);

        return self::CYAN.self::V_LINE.self::NC.' '
            .self::DIM.$label.self::NC.' '
            .$color.$value.self::NC
            .self::CYAN.self::V_LINE.self::NC.self::CLEAR_EOL."\n";
    }

    /**
     * Print a progress meter.
     */
    protected function printMeter(string $label, int $current, int $max): string
    {
        $labelWidth = 25;
        $meterWidth = min(30, $this->boxWidth - $labelWidth - 10);

        $percentage = $max > 0 ? (int) (($current * 100) / $max) : 0;
        $filled = (int) (($percentage * $meterWidth) / 100);
        $empty = $meterWidth - $filled;

        // Choose color based on percentage
        $color = self::GREEN;
        if ($percentage > 80) {
            $color = self::RED;
        } elseif ($percentage > 60) {
            $color = self::YELLOW;
        }

        $meter = $color.str_repeat('█', $filled).self::GRAY.str_repeat('░', $empty).self::NC;

        $label = str_pad($label, $labelWidth);
        $percentStr = sprintf('%3d%%', $percentage);
        $remainingSpace = $this->boxWidth - $labelWidth - $meterWidth - strlen($percentStr) - 4;

        return self::CYAN.self::V_LINE.self::NC.' '
            .self::DIM.$label.self::NC.' '
            .$meter.' '.self::WHITE.$percentStr.self::NC
            .str_repeat(' ', max(0, $remainingSpace))
            .self::CYAN.self::V_LINE.self::NC.self::CLEAR_EOL."\n";
    }

    /**
     * Print dashboard footer.
     */
    protected function printFooter(): string
    {
        $line = str_repeat(self::H_LINE, $this->boxWidth);

        return self::CYAN.self::BL_CORNER.$line.self::BR_CORNER.self::NC.self::CLEAR_EOL."\n";
    }

    /**
     * Format bytes into human-readable string.
     */
    protected function formatBytes(int $bytes): string
    {
        return $this->formatBytesPhp($bytes);
    }

    public static function formatBytesNative(int $bytes): ?string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 64, $cap);
        if ($scratch === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_redis_format_bytes($bytes, $scratch, $cap);
            if (! is_int($written) || $written <= 0) {
                return null;
            }

            return FFI::string($scratch, $written);
        } catch (Throwable) {
            return null;
        }
    }

    private function formatBytesPhp(int $bytes): string
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
     * Format number with thousands separators.
     */
    protected function formatNumber(int $number): string
    {
        return number_format($number);
    }

    /**
     * Hide terminal cursor.
     */
    protected function hideCursor(): void
    {
        echo "\033[?25l";
    }

    /**
     * Show terminal cursor.
     */
    protected function showCursor(): void
    {
        echo "\033[?25h";
    }

    /**
     * Move cursor to top-left.
     */
    protected function moveToTop(): void
    {
        echo "\033[H";
    }

    /**
     * Clear the screen.
     */
    protected function clearScreen(): void
    {
        echo "\033[2J\033[H";
    }
}
