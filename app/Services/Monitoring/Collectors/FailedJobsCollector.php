<?php

declare(strict_types=1);

namespace App\Services\Monitoring\Collectors;

use App\Providers\HorizonServiceProvider;
use App\Services\Monitoring\Prometheus\MetricFamily;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Throwable;

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

    public const string W53_FFI_CDEF = 'int64_t nntmux_w535_failed_job_exception_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W53.5 parity test's QUALIFICATION). */
    private const bool W53_RUST_FFI_DEFAULT = true;

    /**
     * Wave 53.5 routing and FFI cache, kept in function-local statics so readonly classes can use them too.
     * Without $op it reports the routing; 'set' swaps the override for $val and returns the previous one;
     * 'reset' replaces the cached handle with $val (null re-resolves, false disables); 'buffer' returns
     * `[FFI, int64_t[], capacity]` with room for at least $val words, or null without the library.
     */
    private static function w53RustFfiState(?string $op = null, mixed $val = null): mixed
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
                    $ffi = RustPipelineLibrary::resolveDomain('w53_5', self::W53_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
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
                return $enabled ?? self::W53_RUST_FFI_DEFAULT;
        }
    }

    public static function usesW53RustFfi(): bool
    {
        return self::w53RustFfiState() === true;
    }

    /**
     * Runs $callback with exceptionPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW53RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w53RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w53RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 53.5 Rust helper, or null to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w53Words(int $count, string $data, string $ints, int $words): ?array
    {
        try {
            $handle = self::w53RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w535_failed_job_exception_plan($count, 0, $data, \strlen($data), $ints, \strlen($ints), $out, $cap);
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

    /** Bytes of a class name in a failed job's exception header. */
    private const string W53_CLASS_BYTES = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_\\';

    /**
     * Batch classification of failed job `exception` texts by their first line (without a trailing CR).
     * Three words per exception: `[category, class name length or 0, first line length]`. The class is
     * the text before the first colon (ASCII letters, digits, `_` and `\`, not starting with a digit);
     * categories are 0 unparseable, 1 timeout, 2 too many attempts, 3 model not found and 4 other.
     *
     * @param  list<string>  $exceptions
     * @return list<int>
     */
    public static function exceptionPlanBatch(array $exceptions): array
    {
        return self::usesW53RustFfi() ? self::exceptionPlanBatchNative($exceptions) : self::exceptionPlanBatchPhp($exceptions);
    }

    /**
     * @param  list<string>  $exceptions
     * @return list<int>
     */
    public static function exceptionPlanBatchPhp(array $exceptions): array
    {
        $out = [];
        foreach ($exceptions as $exception) {
            $line = substr($exception, 0, strcspn($exception, "\n"));
            if (str_ends_with($line, "\r")) {
                $line = substr($line, 0, -1);
            }
            $class = substr($line, 0, strcspn($line, ':'));
            $length = \strlen($class);
            $valid = $length > 0 && strspn($class, self::W53_CLASS_BYTES) === $length && strspn($class, '0123456789', 0, 1) === 0;
            $out[] = match (true) {
                ! $valid => 0,
                str_ends_with($class, 'TimeoutExceededException') || str_contains($line, 'has timed out') => 1,
                str_ends_with($class, 'MaxAttemptsExceededException') || str_contains($line, 'attempted too many times') => 2,
                str_ends_with($class, 'ModelNotFoundException') => 3,
                default => 4,
            };
            $out[] = $valid ? $length : 0;
            $out[] = \strlen($line);
        }

        return $out;
    }

    /**
     * Rust batch over the concatenated exceptions and their byte lengths; falls back to PHP without the library.
     *
     * @param  list<string>  $exceptions
     * @return list<int>
     */
    public static function exceptionPlanBatchNative(array $exceptions): array
    {
        $count = \count($exceptions);
        if ($count === 0) {
            return [];
        }

        return self::w53Words($count, implode('', $exceptions), pack('P*', ...array_map(strlen(...), $exceptions)), 3 * $count)
            ?? self::exceptionPlanBatchPhp($exceptions);
    }
}
