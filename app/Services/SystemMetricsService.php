<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SystemMetric;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\Log;
use Throwable;

class SystemMetricsService
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_metrics_parse_cpu_count(const char *cpuinfo, size_t cpuinfo_len);
        int64_t nntmux_metrics_parse_meminfo(const char *meminfo, size_t meminfo_len);
        ssize_t nntmux_metrics_parse_cpu_info(const char *cpuinfo, size_t cpuinfo_len, char *out_buf, size_t out_cap);
        C;

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

    /**
     * Collect and store current system metrics
     */
    public function collectMetrics(): void
    {
        $cpuUsage = $this->getCpuUsage();
        $ramUsage = $this->getRamUsage();

        $now = now();

        // Store CPU metric
        SystemMetric::create([
            'metric_type' => 'cpu',
            'value' => $cpuUsage,
            'recorded_at' => $now,
        ]);

        // Store RAM metric (percentage)
        SystemMetric::create([
            'metric_type' => 'ram',
            'value' => $ramUsage['percentage'],
            'recorded_at' => $now,
        ]);
    }

    /**
     * Get historical metrics grouped by hour for the last N hours
     *
     * @return array<string, mixed>
     */
    public function getHourlyMetrics(string $type, int $hours = 24): array
    {
        $startDate = now()->subHours($hours);

        $metrics = SystemMetric::where('metric_type', $type)
            ->where('recorded_at', '>=', $startDate)
            ->orderBy('recorded_at')
            ->get();

        // Group by hour and calculate average
        $hourlyData = [];
        foreach ($metrics as $metric) {
            $hourKey = $metric->recorded_at->format('Y-m-d H:00');
            $hourLabel = $metric->recorded_at->format('H:i');

            if (! isset($hourlyData[$hourKey])) {
                $hourlyData[$hourKey] = [
                    'time' => $hourLabel,
                    'values' => [],
                ];
            }
            $hourlyData[$hourKey]['values'][] = $metric->value;
        }

        // Calculate averages
        $result = [];
        foreach ($hourlyData as $data) {
            $result[] = [
                'time' => $data['time'],
                'value' => round(array_sum($data['values']) / count($data['values']), 2),
            ];
        }

        // Fill missing hours with null or last known value
        return $this->fillMissingHours($result, $hours); // @phpstan-ignore argument.type
    }

    /**
     * Get historical metrics grouped by day for the last N days
     *
     * @return list<array<string, float|string>>
     */
    public function getDailyMetrics(string $type, int $days = 30): array
    {
        $startDate = now()->subDays($days);

        $metrics = SystemMetric::where('metric_type', $type)
            ->where('recorded_at', '>=', $startDate)
            ->orderBy('recorded_at')
            ->get();

        // Group by day and calculate average
        $dailyData = [];
        foreach ($metrics as $metric) {
            $dayKey = $metric->recorded_at->format('Y-m-d');
            $dayLabel = $metric->recorded_at->format('M d');

            if (! isset($dailyData[$dayKey])) {
                $dailyData[$dayKey] = [
                    'time' => $dayLabel,
                    'values' => [],
                ];
            }
            $dailyData[$dayKey]['values'][] = $metric->value;
        }

        // Calculate averages
        $result = [];
        foreach ($dailyData as $data) {
            $result[] = [
                'time' => $data['time'],
                'value' => round(array_sum($data['values']) / count($data['values']), 2),
            ];
        }

        return $result;
    }

    /**
     * Fill missing hours with previous value or 0
     *
     * @param  array<string, mixed>  $data
     * @return list<array<string, float|string>>
     */
    protected function fillMissingHours(array $data, int $hours): array
    {
        if (empty($data)) {
            // Generate empty data points for all hours
            $result = [];
            for ($i = $hours - 1; $i >= 0; $i--) {
                $time = now()->subHours($i);
                $result[] = [
                    'time' => $time->format('H:i'),
                    'value' => 0,
                ];
            }

            return $result;
        }

        return $data;
    }

    /**
     * Get current CPU usage percentage
     */
    public function getCpuUsage(): float
    {
        try {
            if (PHP_OS_FAMILY === 'Windows') {
                $output = shell_exec('wmic cpu get loadpercentage');
                if ($output) {
                    preg_match('/\d+/', $output, $matches);

                    return (float) ($matches[0] ?? 0);
                }
            } else {
                // Linux - use load average
                $load = sys_getloadavg();
                if ($load !== false) {
                    $cpuCount = $this->getCpuCount();

                    return round(($load[0] / $cpuCount) * 100, 2);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Could not get CPU usage: '.$e->getMessage());
        }

        return 0;
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
     * Get number of CPU cores
     */
    public function getCpuCount(): int
    {
        try {
            if (PHP_OS_FAMILY === 'Windows') {
                $output = shell_exec('wmic cpu get NumberOfLogicalProcessors');
                if ($output) {
                    preg_match('/\d+/', $output, $matches);

                    return (int) ($matches[0] ?? 1);
                }
            } else {
                $cpuinfo = file_get_contents('/proc/cpuinfo');
                if ($cpuinfo === false) {
                    return 1;
                }

                return $this->parseCpuCount($cpuinfo);
            }
        } catch (\Exception $e) {
            return 1;
        }

        return 1;
    }

    public function parseCpuCount(string $cpuinfo): int
    {
        if (self::$useRustFfi) {
            $ffi = self::$ffiResolved ? self::$ffi : self::ffi();
            if ($ffi !== null) {
                // @phpstan-ignore method.notFound
                $res = $ffi->nntmux_metrics_parse_cpu_count($cpuinfo, strlen($cpuinfo));
                if (is_int($res) && $res >= 1) {
                    return $res;
                }
            }
        }

        return $this->parseCpuCountPhp($cpuinfo);
    }

    public static function parseCpuCountNative(string $cpuinfo): ?int
    {
        $ffi = self::$ffiResolved ? self::$ffi : self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $res = $ffi->nntmux_metrics_parse_cpu_count($cpuinfo, strlen($cpuinfo));

            return is_int($res) && $res >= 1 ? $res : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function parseCpuCountPhp(string $cpuinfo): int
    {
        preg_match_all('/^processor/m', $cpuinfo, $matches);

        return count($matches[0]) ?: 1;
    }

    /**
     * Get RAM usage information
     *
     * @return array<string, mixed>
     */
    public function getRamUsage(): array
    {
        try {
            if (PHP_OS_FAMILY === 'Windows') {
                $output = shell_exec('wmic OS get FreePhysicalMemory,TotalVisibleMemorySize /Value');
                if ($output) {
                    preg_match('/FreePhysicalMemory=(\d+)/', $output, $free);
                    preg_match('/TotalVisibleMemorySize=(\d+)/', $output, $total);

                    if (isset($free[1]) && isset($total[1])) {
                        $freeKb = (float) $free[1];
                        $totalKb = (float) $total[1];
                        $usedKb = $totalKb - $freeKb;

                        return [
                            'used' => round($usedKb / 1024 / 1024, 2),
                            'total' => round($totalKb / 1024 / 1024, 2),
                            'percentage' => round(($usedKb / $totalKb) * 100, 2),
                        ];
                    }
                }
            } else {
                $meminfo = file_get_contents('/proc/meminfo');
                if ($meminfo !== false) {
                    return $this->parseMeminfo($meminfo);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Could not get RAM usage: '.$e->getMessage());
        }

        return [
            'used' => 0,
            'total' => 0,
            'percentage' => 0,
        ];
    }

    /**
     * @return array{used: int|float, total: int|float, percentage: int|float}
     */
    public function parseMeminfo(string $meminfo): array
    {
        return $this->parseMeminfoPhp($meminfo);
    }

    /**
     * @return array{used: int|float, total: int|float, percentage: int|float}|null
     */
    public static function parseMeminfoNative(string $meminfo): ?array
    {
        $ffi = self::$ffiResolved ? self::$ffi : self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $packed = $ffi->nntmux_metrics_parse_meminfo($meminfo, strlen($meminfo));
            if (! is_int($packed) || $packed < 0) {
                return null;
            }
            if ($packed === 0) {
                return [
                    'used' => 0,
                    'total' => 0,
                    'percentage' => 0,
                ];
            }

            return [
                'used' => ($packed >> 40) / 100.0,
                'total' => (($packed >> 18) & 0x3FFFFF) / 100.0,
                'percentage' => (($packed >> 1) & 0x1FFFF) / 100.0,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{used: int|float, total: int|float, percentage: int|float}
     */
    private function parseMeminfoPhp(string $meminfo): array
    {
        preg_match('/MemTotal:\s+(\d+)/', $meminfo, $total);
        preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $available);

        if (isset($total[1]) && isset($available[1])) {
            $totalKb = (float) $total[1];
            $availableKb = (float) $available[1];
            $usedKb = $totalKb - $availableKb;

            return [
                'used' => round($usedKb / 1024 / 1024, 2),
                'total' => round($totalKb / 1024 / 1024, 2),
                'percentage' => round(($usedKb / $totalKb) * 100, 2),
            ];
        }

        return [
            'used' => 0,
            'total' => 0,
            'percentage' => 0,
        ];
    }

    /**
     * Get detailed CPU information (cores, threads, model)
     *
     * @return array<string, mixed>
     */
    public function getCpuInfo(): array
    {
        $info = [
            'cores' => 0,
            'threads' => 0,
            'model' => 'Unknown',
        ];

        try {
            if (PHP_OS_FAMILY === 'Windows') {
                $coresOutput = shell_exec('wmic cpu get NumberOfCores');
                if ($coresOutput) {
                    preg_match('/\d+/', $coresOutput, $matches);
                    $info['cores'] = (int) ($matches[0] ?? 0);
                }

                $threadsOutput = shell_exec('wmic cpu get NumberOfLogicalProcessors');
                if ($threadsOutput) {
                    preg_match('/\d+/', $threadsOutput, $matches);
                    $info['threads'] = (int) ($matches[0] ?? 0);
                }

                $modelOutput = shell_exec('wmic cpu get Name');
                if ($modelOutput) {
                    $lines = explode("\n", trim($modelOutput));
                    if (isset($lines[1])) {
                        $info['model'] = trim($lines[1]);
                    }
                }
            } else {
                $cpuinfo = file_get_contents('/proc/cpuinfo');
                if ($cpuinfo !== false) {
                    return $this->parseCpuInfo($cpuinfo);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Could not get CPU info: '.$e->getMessage());
        }

        return $info;
    }

    /**
     * @return array{cores: int, threads: int, model: string}
     */
    public function parseCpuInfo(string $cpuinfo): array
    {
        if (self::$useRustFfi) {
            $native = self::parseCpuInfoNative($cpuinfo);
            if ($native !== null) {
                return $native;
            }
        }

        return $this->parseCpuInfoPhp($cpuinfo);
    }

    /**
     * @return array{cores: int, threads: int, model: string}|null
     */
    public static function parseCpuInfoNative(string $cpuinfo): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 512, $cap);
        if ($scratch === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_metrics_parse_cpu_info($cpuinfo, strlen($cpuinfo), $scratch, $cap);
            if (! is_int($written) || $written === -1) {
                return null;
            }
            if ($written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_metrics_parse_cpu_info($cpuinfo, strlen($cpuinfo), $scratch, $cap);
                if (! is_int($written) || $written <= 0) {
                    return null;
                }
            }
            $parts = explode("\0", FFI::string($scratch, $written), 3);
            if (count($parts) !== 3) {
                return null;
            }

            return [
                'cores' => (int) $parts[0],
                'threads' => (int) $parts[1],
                'model' => $parts[2],
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{cores: int, threads: int, model: string}
     */
    private function parseCpuInfoPhp(string $cpuinfo): array
    {
        $info = [
            'cores' => 0,
            'threads' => 0,
            'model' => 'Unknown',
        ];

        preg_match_all('/^cpu cores\s*:\s*(\d+)/m', $cpuinfo, $coresMatches);
        if (! empty($coresMatches[1])) {
            $info['cores'] = (int) $coresMatches[1][0];
        }

        preg_match_all('/^processor/m', $cpuinfo, $processorMatches);
        $info['threads'] = count($processorMatches[0]) ?: 0;

        preg_match('/^model name\s*:\s*(.+)$/m', $cpuinfo, $modelMatches);
        if (! empty($modelMatches[1])) {
            $info['model'] = trim($modelMatches[1]);
        }

        if ($info['cores'] === 0) {
            preg_match_all('/^physical id\s*:\s*(\d+)/m', $cpuinfo, $physicalMatches);
            $uniquePhysical = ! empty($physicalMatches[1]) ? count(array_unique($physicalMatches[1])) : 1;
            $info['cores'] = (int) ($info['threads'] / $uniquePhysical);
        }

        return $info;
    }

    /**
     * Get system load average
     *
     * @return array<string, mixed>
     */
    public function getLoadAverage(): array
    {
        $loadAvg = [
            '1min' => 0,
            '5min' => 0,
            '15min' => 0,
        ];

        try {
            if (PHP_OS_FAMILY === 'Windows') {
                $output = shell_exec('wmic path Win32_PerfFormattedData_PerfOS_System get ProcessorQueueLength');
                if ($output) {
                    preg_match('/\d+/', $output, $matches);
                    $queueLength = (int) ($matches[0] ?? 0);
                    $loadAvg['1min'] = round($queueLength / 2, 2);
                    $loadAvg['5min'] = round($queueLength / 2, 2);
                    $loadAvg['15min'] = round($queueLength / 2, 2);
                }
            } else {
                $load = sys_getloadavg();
                if ($load !== false) {
                    $loadAvg['1min'] = round($load[0], 2);
                    $loadAvg['5min'] = round($load[1], 2);
                    $loadAvg['15min'] = round($load[2], 2);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Could not get load average: '.$e->getMessage());
        }

        return $loadAvg;
    }

    /**
     * Get disk space information formatted as human-readable string
     */
    public function getDiskSpace(): string
    {
        try {
            $bytes = disk_free_space('/');
            $units = ['B', 'KB', 'MB', 'GB', 'TB'];

            for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
                $bytes /= 1024;
            }

            return round($bytes, 2).' '.$units[$i];
        } catch (\Exception $e) {
            return 'N/A';
        }
    }

    /**
     * Clean up old metrics (keep last 60 days)
     */
    public function cleanupOldMetrics(int $daysToKeep = 60): int
    {
        return SystemMetric::cleanupOldMetrics($daysToKeep);
    }

    /** Enabled: three paired runs cleared every 1.5x gate (common mean 7.46-7.84x, p99 5.71-6.99x; uncommon mean 5.68-5.91x, p99 4.22-4.76x); see RustSystemTempUserInactivityStatsSeriesW51ParityTest. */
    private const bool W51_RUST_FFI_DEFAULT = true;

    public const string W51_FFI_CDEF = <<<'C'
        ssize_t nntmux_w513_metric_hourly_bucket_batch(const char *blob, size_t blob_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
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

    /**
     * Buckets raw `system_metrics` rows by hour, as getHourlyMetrics() groups them, in exact
     * hundredths. One word per row: its bucket index in first-appearance order, or -1 when
     * `recorded_at` is not a valid `Y-m-d H:i:s` (or `T`-separated) calendar timestamp or `value`
     * is not a decimal with at most nine integer and two fractional digits. Then six words per
     * bucket (hour key `YYYYMMDDHH`, row count, sum, average rounded half away from zero, min,
     * max, all values in hundredths) and finally the bucket and invalid row counts. Values are
     * read through their string form, so Carbon instances and floats behave as implode() sees them.
     *
     * @param  array<int, array<string, mixed>>  $rows  recorded_at, value
     * @return list<int>
     */
    public static function w51HourlyBucketBatch(array $rows): array
    {
        return self::usesW51RustFfi() ? self::w51HourlyBucketBatchNative($rows) : self::w51HourlyBucketBatchPhp($rows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<int>
     */
    public static function w51HourlyBucketBatchNative(array $rows): array
    {
        $count = \count($rows);
        $columns = self::w51Columns($rows, ['recorded_at' => '', 'value' => '']);

        return self::w51Invoke(
            implode("\0", $columns['recorded_at'])."\0".implode("\0", $columns['value']),
            '',
            $count,
            $count * 7 + 2,
        )
            ?? self::w51HourlyBucketBatchPhp($rows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<int>
     */
    public static function w51HourlyBucketBatchPhp(array $rows): array
    {
        $out = [];
        $slots = [];
        $buckets = [];
        $invalid = 0;
        foreach ($rows as $row) {
            $key = self::w51HourKey((string) ($row['recorded_at'] ?? ''));
            $value = self::w51Centi((string) ($row['value'] ?? ''));
            if ($key === null || $value === null) {
                $invalid++;
                $out[] = -1;

                continue;
            }
            if (! isset($slots[$key])) {
                $slots[$key] = \count($buckets);
                $buckets[] = [$key, 0, 0, $value, $value];
            }
            $slot = $slots[$key];
            $buckets[$slot][1]++;
            $buckets[$slot][2] += $value;
            $buckets[$slot][3] = min($buckets[$slot][3], $value);
            $buckets[$slot][4] = max($buckets[$slot][4], $value);
            $out[] = $slot;
        }
        foreach ($buckets as [$key, $n, $sum, $min, $max]) {
            $average = intdiv(2 * abs($sum) + $n, 2 * $n);
            array_push($out, $key, $n, $sum, $sum < 0 ? -$average : $average, $min, $max);
        }
        array_push($out, \count($buckets), $invalid);

        return $out;
    }

    /** `YYYYMMDDHH` of a valid calendar timestamp, else null. */
    private static function w51HourKey(string $stamp): ?int
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})$/D', $stamp, $m) !== 1) {
            return null;
        }
        [$year, $month, $day, $hour] = [(int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4]];
        if (! checkdate($month, $day, $year) || $hour > 23 || (int) $m[5] > 59 || (int) $m[6] > 59) {
            return null;
        }

        return $year * 1_000_000 + $month * 10_000 + $day * 100 + $hour;
    }

    /** Hundredths of a decimal with at most nine integer and two fractional digits, else null. */
    private static function w51Centi(string $value): ?int
    {
        if (preg_match('/^(-?)(\d{1,9})(?:\.(\d{1,2}))?$/D', $value, $m) !== 1) {
            return null;
        }
        $centi = (int) $m[2] * 100;
        if (isset($m[3])) {
            $centi += \strlen($m[3]) === 1 ? (int) $m[3] * 10 : (int) $m[3];
        }

        return $m[1] === '-' ? -$centi : $centi;
    }

    /**
     * One column per key of $defaults. Rows missing a key read its default; present nulls stay
     * null, which pack() and implode() read as 0 and '' like the defaults they replace.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $defaults
     * @return array<string, list<mixed>>
     */
    private static function w51Columns(array $rows, array $defaults): array
    {
        $count = \count($rows);
        $columns = [];
        foreach ($defaults as $key => $default) {
            $column = array_column($rows, $key);
            if (\count($column) !== $count) {
                $filled = [];
                foreach ($rows as $row) {
                    $filled[] = $row + $defaults;
                }
                $columns = [];
                foreach ($defaults as $name => $unused) {
                    $columns[$name] = array_column($filled, $name);
                }

                return $columns;
            }
            $columns[$key] = $column;
        }

        return $columns;
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
            $written = $ffi->nntmux_w513_metric_hourly_bucket_batch($blob, \strlen($blob), $ints, \strlen($ints), $count, self::$w51Out, self::$w51OutCap);
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
