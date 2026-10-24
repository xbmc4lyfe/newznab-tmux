<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;

/**
 * Bounded per-run counters; never retain candidate metadata or exception text.
 *
 * @phpstan-type ReadPhase 'file_read'|'gzip_decode'|'hash'|'json_decode'|'assessment'
 * @phpstan-type Distribution array{count: int, failures: int, total_ms: float, max_ms: float, latency_buckets: array<string, int>}
 */
final class PreparationRunDiagnostics
{
    private const string FFI_CDEF = <<<'C'
        ssize_t nntmux_verification_diagnostic_phase(const char *, size_t, double, uint8_t, char *, size_t);
        int32_t nntmux_verification_diagnostic_size(uint8_t, int64_t);
        int32_t nntmux_verification_diagnostic_projection(int64_t, int64_t, int64_t, int64_t, int64_t, uint8_t, uint8_t);
        C;

    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    /** @var array<string, float> */
    private const array LATENCY_BOUNDS = ['le_0_1' => 0.1, 'le_1' => 1.0, 'le_10' => 10.0,
        'le_100' => 100.0, 'le_1000' => 1000.0, 'le_10000' => 10000.0];

    /** @var array<ReadPhase, Distribution> */
    private array $phaseDistributions = [];

    /** @var array<string, array<string, int>> */
    private array $manifestSizes = [
        'compressed_bytes' => ['le_4096' => 0, 'le_65536' => 0, 'le_1048576' => 0, 'le_16777216' => 0, 'gt_16777216' => 0],
        'json_bytes' => ['le_4096' => 0, 'le_65536' => 0, 'le_1048576' => 0, 'le_16777216' => 0, 'gt_16777216' => 0],
        'files' => ['le_1' => 0, 'le_10' => 0, 'le_100' => 0, 'le_1000' => 0, 'gt_1000' => 0],
    ];

    /** @var array{ready: int, waiting_upload: int, unknown: int} */
    private array $assessments = ['ready' => 0, 'waiting_upload' => 0, 'unknown' => 0, 'header_rejected' => 0];

    /** @var array{ready: int, waiting_upload: int, unknown: int} */
    private array $updated = ['ready' => 0, 'waiting_upload' => 0, 'unknown' => 0, 'header_rejected' => 0];

    /** @var array{selection: float, read_assessment: float, update: float} */
    private array $durations = ['selection' => 0.0, 'read_assessment' => 0.0, 'update' => 0.0];

    private int $zeroRowUpdates = 0;

    private int $readAssessmentFailures = 0;

    private int $uninspectedSelected = 0;

    public function __construct(private readonly int $started)
    {
        foreach (['file_read', 'gzip_decode', 'hash', 'json_decode', 'assessment'] as $phase) {
            $this->phaseDistributions[$phase] = ['count' => 0, 'failures' => 0, 'total_ms' => 0.0, 'max_ms' => 0.0,
                'latency_buckets' => [...array_fill_keys(array_keys(self::LATENCY_BOUNDS), 0), 'gt_10000' => 0]];
        }
    }

    /**
     * Wall spans include descheduling and cache effects; they are not disk or CPU time.
     *
     * @template T
     *
     * @param  ReadPhase  $phase
     * @param  callable(): T  $operation
     * @return T
     */
    public function measurePhase(string $phase, callable $operation): mixed
    {
        $started = hrtime(true);
        $failed = true;
        try {
            $result = $operation();
            // JSON false is a successful decode; manifest shape is checked by the reader.
            $failed = $result === false && $phase !== 'json_decode';

            return $result;
        } finally {
            try {
                $this->recordPhase($phase, (hrtime(true) - $started) / 1_000_000, $failed);
            } catch (\Throwable) {
                // Reporting cannot replace the operation's result, warning, or exception.
            }
        }
    }

    /** @param ReadPhase $phase */
    public function recordPhase(string $phase, float $milliseconds, bool $failed): void
    {
        if (self::$useRustFfi && $this->recordPhaseNative($phase, $milliseconds, $failed)) {
            return;
        }
        $this->recordPhasePhp($phase, $milliseconds, $failed);
    }

    /** @param ReadPhase $phase */
    public function recordPhaseNative(string $phase, float $milliseconds, bool $failed): bool
    {
        if (! isset($this->phaseDistributions[$phase])) {
            return true;
        }
        if (! self::$useRustFfi || ($ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF)) === null) {
            return false;
        }
        $distribution = $this->phaseDistributions[$phase];
        $counts = [$distribution['count'], $distribution['failures'], ...array_values($distribution['latency_buckets'])];
        foreach ($counts as $count) {
            // PHP promotes overflowing counters to float; leave those cases in PHP.
            if (! is_int($count)) {
                return false;
            }
        }
        $input = pack('q2d2q7', $counts[0], $counts[1], $distribution['total_ms'], $distribution['max_ms'], ...array_slice($counts, 2));
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, 88, $capacity);
        if ($buffer === null) {
            return false;
        }
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_verification_diagnostic_phase($input, strlen($input), $milliseconds, (int) $failed, $buffer, $capacity);
        if ($written !== 88) {
            return false;
        }
        $row = unpack('qcount/qfailures/dtotal_ms/dmax_ms/q7buckets', FFI::string($buffer, 88));
        if ($row === false) {
            return false;
        }
        $buckets = [];
        foreach (array_keys($distribution['latency_buckets']) as $index => $bucket) {
            $buckets[$bucket] = $row['buckets'.($index + 1)];
        }
        $this->phaseDistributions[$phase] = ['count' => $row['count'], 'failures' => $row['failures'],
            'total_ms' => $row['total_ms'], 'max_ms' => $row['max_ms'], 'latency_buckets' => $buckets];

        return true;
    }

    /** @param ReadPhase $phase */
    public function recordPhasePhp(string $phase, float $milliseconds, bool $failed): void
    {
        if (! isset($this->phaseDistributions[$phase])) {
            return;
        }
        $distribution = &$this->phaseDistributions[$phase];
        $distribution['count']++;
        $distribution['failures'] += (int) $failed;
        $distribution['total_ms'] += $milliseconds;
        $distribution['max_ms'] = max($distribution['max_ms'], $milliseconds);
        foreach (self::LATENCY_BOUNDS as $bucket => $bound) {
            if ($milliseconds <= $bound) {
                $distribution['latency_buckets'][$bucket]++;

                return;
            }
        }
        $distribution['latency_buckets']['gt_10000']++;
    }

    /** @param 'compressed_bytes'|'json_bytes'|'files' $kind */
    public function recordSize(string $kind, int $size): void
    {
        if (self::$useRustFfi && $this->recordSizeNative($kind, $size)) {
            return;
        }
        $this->recordSizePhp($kind, $size);
    }

    /** @param 'compressed_bytes'|'json_bytes'|'files' $kind */
    public function recordSizeNative(string $kind, int $size): bool
    {
        if (! isset($this->manifestSizes[$kind])) {
            return true;
        }
        if (! self::$useRustFfi || ($ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF)) === null) {
            return false;
        }
        // @phpstan-ignore method.notFound
        $index = $ffi->nntmux_verification_diagnostic_size((int) ($kind === 'files'), $size);
        $keys = array_keys($this->manifestSizes[$kind]);
        if (! isset($keys[$index])) {
            return false;
        }
        $this->manifestSizes[$kind][$keys[$index]]++;

        return true;
    }

    /** @param 'compressed_bytes'|'json_bytes'|'files' $kind */
    public function recordSizePhp(string $kind, int $size): void
    {
        if (! isset($this->manifestSizes[$kind])) {
            return;
        }
        try {
            foreach ($this->manifestSizes[$kind] as $bucket => $count) {
                if (str_starts_with($bucket, 'gt_') || $size <= (int) substr($bucket, 3)) {
                    $this->manifestSizes[$kind][$bucket]++;

                    return;
                }
            }
        } catch (\Throwable) {
            // Sizes come from already read data; diagnostics cannot fail the read.
        }
    }

    public function start(): int
    {
        return hrtime(true);
    }

    /** @param 'selection'|'read_assessment'|'update' $phase */
    public function finish(string $phase, ?int $started): void
    {
        if ($started !== null) {
            $this->durations[$phase] += (hrtime(true) - $started) / 1_000_000;
        }
    }

    public function selected(int $count): void
    {
        $this->uninspectedSelected += $count;
    }

    public function admitted(): void
    {
        $this->uninspectedSelected--;
    }

    /** @param 'ready'|'waiting_upload'|'unknown'|'header_rejected' $status */
    public function assessed(string $status): void
    {
        $this->assessments[$status]++;
    }

    public function readAssessmentFailed(): void
    {
        $this->readAssessmentFailures++;
    }

    /**
     * Affected rows describe the update result, not an outer transaction's commit.
     *
     * @param  'ready'|'waiting_upload'|'unknown'|'header_rejected'  $status
     */
    public function updated(string $status, int $affected, int $attempted = 1): void
    {
        $this->updated[$status] += $affected;
        $this->zeroRowUpdates += max(0, $attempted - $affected);
    }

    /** @return array<string, mixed> */
    public function report(int $limit, int $seconds, int $inspected, int $deadline, bool $exhausted, bool $error): array
    {
        $finished = hrtime(true);

        return (self::$useRustFfi ? $this->reportAtNative($limit, $seconds, $inspected, $deadline, $exhausted, $error, $finished) : null)
            ?? $this->reportAtPhp($limit, $seconds, $inspected, $deadline, $exhausted, $error, $finished);
    }

    /** Explicit clock input keeps differential tests and paired benchmarks deterministic.
     * @return array<string, mixed>|null
     */
    public function reportAtNative(int $limit, int $seconds, int $inspected, int $deadline, bool $exhausted, bool $error, int $finished): ?array
    {
        if (! self::$useRustFfi || ($ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF)) === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $flags = $ffi->nntmux_verification_diagnostic_projection($limit, $inspected, $finished, $deadline, $this->uninspectedSelected, (int) $exhausted, (int) $error);
        if ($flags < 0 || $flags > 15) {
            return null;
        }

        return $this->projectReport($limit, $seconds, $inspected, $finished, (bool) ($flags & 1), (bool) ($flags & 2), (bool) ($flags & 4), (bool) ($flags & 8));
    }

    /** @return array<string, mixed> */
    public function reportAtPhp(int $limit, int $seconds, int $inspected, int $deadline, bool $exhausted, bool $error, int $finished): array
    {
        $limitReached = $inspected >= $limit;
        $deadlineReached = $finished >= $deadline;
        $queuesExhausted = $exhausted && $this->uninspectedSelected === 0;

        return $this->projectReport($limit, $seconds, $inspected, $finished, $limitReached, $deadlineReached, $queuesExhausted, $error);
    }

    /** @return array<string, mixed> */
    private function projectReport(int $limit, int $seconds, int $inspected, int $finished, bool $limitReached, bool $deadlineReached, bool $queuesExhausted, bool $error): array
    {
        $stopReason = match (true) {
            $error => 'error',
            $limitReached && $deadlineReached => 'limit_and_deadline',
            $limitReached => 'limit',
            $deadlineReached => 'deadline',
            default => 'exhausted',
        };

        return [
            'limit' => $limit,
            'max_seconds' => $seconds,
            'inspected' => $inspected,
            'assessments' => $this->assessments,
            'updated' => $this->updated,
            'ready_promotions' => $this->updated['ready'],
            'zero_row_updates' => $this->zeroRowUpdates,
            'read_assessment_failures' => $this->readAssessmentFailures,
            'stop_reason' => $stopReason,
            'limit_reached' => $limitReached,
            'deadline_reached' => $deadlineReached,
            'queues_exhausted' => $queuesExhausted,
            'durations_ms' => [...$this->durations, 'total' => ($finished - $this->started) / 1_000_000],
            'phase_distributions' => $this->phaseDistributions,
            'manifest_sizes' => $this->manifestSizes,
        ];
    }
}
