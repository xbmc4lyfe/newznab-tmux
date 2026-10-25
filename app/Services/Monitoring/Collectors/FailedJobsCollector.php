<?php

declare(strict_types=1);

namespace App\Services\Monitoring\Collectors;

use App\Providers\HorizonServiceProvider;
use App\Services\Monitoring\Prometheus\MetricFamily;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;

class FailedJobsCollector implements MetricsCollector
{
    public const string FFI_CDEF = HorizonServiceProvider::FFI_CDEF;

    // Retain PHP until paired caller benchmarks meet the 1.5x adoption gate.
    private const bool RUST_FFI_DEFAULT = false;

    public static function setUseRustFfi(?bool $use): void
    {
        HorizonServiceProvider::setRustFfiToggle(self::class, $use);
    }

    public static function usesRustFfi(): bool
    {
        return HorizonServiceProvider::rustFfiToggle(self::class) ?? self::RUST_FFI_DEFAULT;
    }

    /**
     * Clamp each counter independently: snapshots can span different retention windows.
     * No database access, sums, or narrowing conversions are performed.
     *
     * @return array{total_failed: int, recent_failed_last_hour: int, recent_failed_last_day: int, has_failures: bool}
     */
    public static function summarizeFailedJobCounts(int $totalFailed, int $recentFailedLastHour, int $recentFailedLastDay): array
    {
        return (self::usesRustFfi() ? self::summarizeFailedJobCountsNative($totalFailed, $recentFailedLastHour, $recentFailedLastDay) : null)
            ?? self::summarizeFailedJobCountsPhp($totalFailed, $recentFailedLastHour, $recentFailedLastDay);
    }

    /** @return array{total_failed: int, recent_failed_last_hour: int, recent_failed_last_day: int, has_failures: bool} */
    public static function summarizeFailedJobCountsPhp(int $totalFailed, int $recentFailedLastHour, int $recentFailedLastDay): array
    {
        return ['total_failed' => max(0, $totalFailed), 'recent_failed_last_hour' => max(0, $recentFailedLastHour), 'recent_failed_last_day' => max(0, $recentFailedLastDay), 'has_failures' => $totalFailed > 0];
    }

    /** @return array{total_failed: int, recent_failed_last_hour: int, recent_failed_last_day: int, has_failures: bool}|null */
    public static function summarizeFailedJobCountsNative(int $totalFailed, int $recentFailedLastHour, int $recentFailedLastDay): ?array
    {
        $output = HorizonServiceProvider::callRustFfi(self::class, 5, pack('q3', $totalFailed, $recentFailedLastHour, $recentFailedLastDay));
        if ($output === null || strlen($output) !== 25 || ord($output[24]) > 1) {
            return null;
        }
        $counts = unpack('qtotal_failed/qrecent_failed_last_hour/qrecent_failed_last_day', $output);
        if (min($counts) < 0 || ($output[24] === "\1") !== ($counts['total_failed'] > 0)) {
            return null;
        }

        return [...$counts, 'has_failures' => $output[24] === "\1"];
    }

    public function __construct(private readonly FailedJobProviderInterface $failer) {}

    public function collect(): iterable
    {
        if (! $this->failer instanceof CountableFailedJobProvider) {
            return;
        }

        yield MetricFamily::gauge('nntmux_failed_jobs', 'Jobs in the failed jobs store.')->add($this->failer->count());
    }
}
