<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

use App\Services\AdditionalProcessing\Enums\ProcessingOutcome;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

final readonly class AdditionalBatchResult
{
    // Rust qualified (seed 476, 2,000 pairs): 3.21x/2.12x common, 3.07x/2.52x uncommon mean/p99.
    public const bool W47_OUTCOME_TELEMETRY_RUST_FFI_DEFAULT = true;

    public const string W47_FFI_CDEF = <<<'C'
        int32_t nntmux_w47_batch_outcome_telemetry(const char *outcomes, size_t outcomes_len, const char *outcome_lens, size_t outcome_lens_len, const char *errors, size_t errors_len, const char *error_lens, size_t error_lens_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_count);
        C;

    public const int W47_OUTCOME_FIELDS = 33;

    /** `ProcessingOutcome` case order; anything else counts as case 12. */
    private const array W47_OUTCOME_CASES = [
        'completed' => 0, 'no-useful-artifacts' => 1, 'passworded' => 2, 'group-unavailable' => 3,
        'temporary-workspace-unavailable' => 4, 'timed-out' => 5, 'deleted-after-timeout' => 6, 'deleted-broken-nzb' => 7,
        'deleted-par2-only' => 8, 'not-found' => 9, 'awaiting-late-articles' => 10, 'failed' => 11,
    ];

    private const int W47_VALUE_CAP = 1_099_511_627_775;

    private const int W47_TOTAL_CAP = 4_611_686_018_427_387_904;

    /**
     * @param  list<int>  $claimedIds
     * @param  list<ReleaseProcessingResult>  $results
     */
    public function __construct(
        public array $claimedIds = [],
        public array $results = [],
        public string $setupFailure = '',
        public float $elapsedSeconds = 0.0,
        public int $peakMemoryBytes = 0,
        // PHP remains the production default until caller-level benchmarks qualify Rust.
        public bool $useRustFfi = false,
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    public static function setupFailed(string $reason): self
    {
        return new self(setupFailure: $reason);
    }

    public function claimedCount(): int
    {
        return count($this->claimedIds);
    }

    public function attemptedCount(): int
    {
        return count($this->results);
    }

    public function successfulCount(): int
    {
        return count(array_filter(
            $this->results,
            static fn (ReleaseProcessingResult $result): bool => $result->isSuccessful(),
        ));
    }

    public function unsuccessfulCount(): int
    {
        return $this->attemptedCount() - $this->successfulCount();
    }

    /**
     * @return array<string, int>
     */
    public function outcomeCounts(): array
    {
        return $this->useRustFfi ? ($this->outcomeCountsNative() ?? $this->outcomeCountsPhp()) : $this->outcomeCountsPhp();
    }

    /** @return array<string, int> */
    public function outcomeCountsPhp(): array
    {
        $counts = [];

        foreach ($this->results as $result) {
            $outcome = $result->outcome->value;
            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
        }

        return $counts;
    }

    public function firstResult(): ?ReleaseProcessingResult
    {
        return $this->results[0] ?? null;
    }

    public function hasOutcome(ProcessingOutcome $outcome): bool
    {
        foreach ($this->results as $result) {
            if ($result->outcome === $outcome) {
                return true;
            }
        }

        return false;
    }

    public function withPerformance(float $elapsedSeconds, int $peakMemoryBytes): self
    {
        return new self(
            claimedIds: $this->claimedIds,
            results: $this->results,
            setupFailure: $this->setupFailure,
            elapsedSeconds: $elapsedSeconds,
            peakMemoryBytes: $peakMemoryBytes,
            useRustFfi: $this->useRustFfi,
        );
    }

    public function releasesPerSecond(): float
    {
        if ($this->elapsedSeconds <= 0.0) {
            return 0.0;
        }

        return $this->attemptedCount() / $this->elapsedSeconds;
    }

    public function averageReleaseSeconds(): float
    {
        if ($this->attemptedCount() === 0) {
            return 0.0;
        }

        return array_sum(array_map(
            static fn (ReleaseProcessingResult $result): float => $result->elapsedSeconds,
            $this->results,
        )) / $this->attemptedCount();
    }

    public function artifactsCreatedCount(): int
    {
        return count(array_filter(
            $this->results,
            static fn (ReleaseProcessingResult $result): bool => $result->artifactsCreated,
        ));
    }

    public function artifactYieldPercent(): float
    {
        return $this->useRustFfi ? ($this->artifactYieldPercentNative() ?? $this->artifactYieldPercentPhp()) : $this->artifactYieldPercentPhp();
    }

    public function artifactYieldPercentPhp(): float
    {
        if ($this->successfulCount() === 0) {
            return 0.0;
        }

        $successfulArtifacts = count(array_filter(
            $this->results,
            static fn (ReleaseProcessingResult $result): bool => $result->isSuccessful() && $result->artifactsCreated,
        ));

        return ($successfulArtifacts / $this->successfulCount()) * 100;
    }

    public function releaseFilesAdded(): int
    {
        return array_sum(array_map(
            static fn (ReleaseProcessingResult $result): int => $result->releaseFilesAdded,
            $this->results,
        ));
    }

    public function downloadMetrics(): DownloadMetrics
    {
        return $this->useRustFfi ? ($this->downloadMetricsNative() ?? $this->downloadMetricsPhp()) : $this->downloadMetricsPhp();
    }

    public function downloadMetricsPhp(): DownloadMetrics
    {
        $logicalRequests = 0;
        $networkRequests = 0;
        $cacheHits = 0;
        $bytesDownloaded = 0;
        $bytesReused = 0;
        $mediaBytesDownloaded = 0;

        foreach ($this->results as $result) {
            if ($result->downloadMetrics === null) {
                continue;
            }

            $logicalRequests += $result->downloadMetrics->logicalRequests;
            $networkRequests += $result->downloadMetrics->networkRequests;
            $cacheHits += $result->downloadMetrics->cacheHits;
            $bytesDownloaded += $result->downloadMetrics->bytesDownloaded;
            $bytesReused += $result->downloadMetrics->bytesReused;
            $mediaBytesDownloaded += $result->downloadMetrics->mediaBytesDownloaded;
        }

        return new DownloadMetrics(
            logicalRequests: $logicalRequests,
            networkRequests: $networkRequests,
            cacheHits: $cacheHits,
            bytesDownloaded: $bytesDownloaded,
            bytesReused: $bytesReused,
            mediaBytesDownloaded: $mediaBytesDownloaded,
        );
    }

    public function persistenceMetrics(): PersistenceMetrics
    {
        return $this->useRustFfi ? ($this->persistenceMetricsNative() ?? $this->persistenceMetricsPhp()) : $this->persistenceMetricsPhp();
    }

    public function persistenceMetricsPhp(): PersistenceMetrics
    {
        $databaseStatements = 0;
        $databaseMilliseconds = 0.0;
        $searchSyncRequests = 0;
        $searchSyncExecutions = 0;

        foreach ($this->results as $result) {
            if ($result->persistenceMetrics === null) {
                continue;
            }

            $databaseStatements += $result->persistenceMetrics->databaseStatements;
            $databaseMilliseconds += $result->persistenceMetrics->databaseMilliseconds;
            $searchSyncRequests += $result->persistenceMetrics->searchSyncRequests;
            $searchSyncExecutions += $result->persistenceMetrics->searchSyncExecutions;
        }

        return new PersistenceMetrics(
            databaseStatements: $databaseStatements,
            databaseMilliseconds: $databaseMilliseconds,
            searchSyncRequests: $searchSyncRequests,
            searchSyncExecutions: $searchSyncExecutions,
        );
    }

    public function duplicateMessageIdCount(): int
    {
        return array_sum(array_map(
            static fn (ReleaseProcessingResult $result): int => $result->duplicateMessageIdCount,
            $this->results,
        ));
    }

    /**
     * @return array<string, int>
     */
    public function unsupportedReasonCounts(): array
    {
        return $this->useRustFfi ? ($this->unsupportedReasonCountsNative() ?? $this->unsupportedReasonCountsPhp()) : $this->unsupportedReasonCountsPhp();
    }

    /** @return array<string, int> */
    public function unsupportedReasonCountsPhp(): array
    {
        $counts = [];

        foreach ($this->results as $result) {
            foreach ($result->unsupportedReasons as $reason) {
                $counts[$reason] = ($counts[$reason] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * @return array<string, float>
     */
    public function stageDurationTotals(): array
    {
        return $this->useRustFfi ? ($this->stageDurationTotalsNative() ?? $this->stageDurationTotalsPhp()) : $this->stageDurationTotalsPhp();
    }

    /** @return array<string, float> */
    public function stageDurationTotalsPhp(): array
    {
        $totals = [];

        foreach ($this->results as $result) {
            foreach ($result->stageDurations as $stage => $duration) {
                $totals[$stage] = ($totals[$stage] ?? 0.0) + $duration;
            }
        }

        return $totals;
    }

    public static function useW47OutcomeTelemetryRustFfi(?bool $enabled): void
    {
        self::w47Toggle(true, $enabled);
    }

    public static function usesW47OutcomeTelemetryRustFfi(): bool
    {
        return self::w47Toggle() ?? self::W47_OUTCOME_TELEMETRY_RUST_FFI_DEFAULT;
    }

    public static function resetW47OutcomeTelemetryRustFfi(): void
    {
        self::w47Toggle(true);
        self::w47Binding(0, true);
    }

    /**
     * Fold a batch of release outcomes into W47_OUTCOME_FIELDS counters: `[0]` count, `[1..13]`
     * ProcessingOutcome case counts (unknown last), `[14]` successful, `[15]` deleted, `[16..21]`
     * error retry classes (none, transient network, missing articles, corrupt data, permanent,
     * unknown), `[22]` retryable, `[23]` retry-exhausted (3+ attempts), `[24]` elapsed total,
     * `[25]` elapsed max, `[26..30]` <100ms/<1s/<10s/<60s/longer, `[31]` first slowest index or
     * -1 and `[32]` an FNV-1a digest. Missing parallel values count as '' or 0.
     *
     * @param  array<array-key, string>  $outcomes  ProcessingOutcome values
     * @param  array<array-key, string>  $errors
     * @param  array<array-key, int>  $elapsedMicros
     * @param  array<array-key, int>  $attempts
     * @return list<int>
     */
    public static function summarizeOutcomeTelemetry(array $outcomes, array $errors = [], array $elapsedMicros = [], array $attempts = []): array
    {
        return (self::usesW47OutcomeTelemetryRustFfi() ? self::summarizeOutcomeTelemetryNative($outcomes, $errors, $elapsedMicros, $attempts) : null)
            ?? self::summarizeOutcomeTelemetryPhp($outcomes, $errors, $elapsedMicros, $attempts);
    }

    /**
     * @param  array<array-key, string>  $outcomes
     * @param  array<array-key, string>  $errors
     * @param  array<array-key, int>  $elapsedMicros
     * @param  array<array-key, int>  $attempts
     * @return list<int>
     */
    public static function summarizeOutcomeTelemetryPhp(array $outcomes, array $errors = [], array $elapsedMicros = [], array $attempts = []): array
    {
        $outcomes = array_values($outcomes);
        $errors = array_values($errors);
        $elapsedMicros = array_values($elapsedMicros);
        $attempts = array_values($attempts);
        $count = \count($outcomes);
        $out = array_fill(0, self::W47_OUTCOME_FIELDS, 0);
        $out[0] = $count;
        $out[31] = -1;
        $digest = 2_166_136_261;
        $total = 0;
        $max = -1;
        for ($i = 0; $i < $count; $i++) {
            $case = self::W47_OUTCOME_CASES[$outcomes[$i]] ?? 12;
            $out[1 + $case]++;
            $class = self::w47RetryClass(strtolower($errors[$i] ?? ''));
            $out[16 + $class]++;
            $tries = max(0, $attempts[$i] ?? 0);
            $successful = $case <= 2;
            $deleted = $case >= 6 && $case <= 8;
            if ($successful) {
                $out[14]++;
            }
            if ($deleted) {
                $out[15]++;
            }
            if (! $successful && ! $deleted && ($class === 1 || $class === 2 || $case === 3 || $case === 4 || $case === 5 || $case === 10)) {
                $out[$tries < 3 ? 22 : 23]++;
            }
            $micros = min(max($elapsedMicros[$i] ?? 0, 0), self::W47_VALUE_CAP);
            $total = min($total + $micros, self::W47_TOTAL_CAP);
            if ($micros > $max) {
                $max = $micros;
                $out[31] = $i;
            }
            $out[$micros < 100_000 ? 26 : ($micros < 1_000_000 ? 27 : ($micros < 10_000_000 ? 28 : ($micros < 60_000_000 ? 29 : 30)))]++;
            $digest = (($digest ^ ($case | $class << 4 | min($tries, 15) << 8)) * 16_777_619) & 0xFFFFFFFF;
        }
        $out[24] = $total;
        $out[25] = max($max, 0);
        $out[32] = $digest;

        return $out;
    }

    /**
     * Rust fold, or null when PHP must summarize (unavailable library or rejected input).
     *
     * @param  array<array-key, string>  $outcomes
     * @param  array<array-key, string>  $errors
     * @param  array<array-key, int>  $elapsedMicros
     * @param  array<array-key, int>  $attempts
     * @return list<int>|null
     */
    public static function summarizeOutcomeTelemetryNative(array $outcomes, array $errors = [], array $elapsedMicros = [], array $attempts = []): ?array
    {
        $binding = self::w47Binding(self::W47_OUTCOME_FIELDS);
        if ($binding === null) {
            return null;
        }
        [$ffi, $out] = $binding;
        $outcomes = array_values($outcomes);
        $count = \count($outcomes);
        [$outcomeBytes, $outcomeLens] = self::w47Strings($outcomes);
        [$errorBytes, $errorLens] = self::w47Strings(self::w47Fit($errors, $count, ''));
        $ints = pack('q*', ...self::w47Fit($elapsedMicros, $count, 0), ...self::w47Fit($attempts, $count, 0));
        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_w47_batch_outcome_telemetry(
                $outcomeBytes, \strlen($outcomeBytes), $outcomeLens, \strlen($outcomeLens),
                $errorBytes, \strlen($errorBytes), $errorLens, \strlen($errorLens),
                $ints, \strlen($ints), $count, $out, self::W47_OUTCOME_FIELDS,
            );
            if ($status !== 1) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, self::W47_OUTCOME_FIELDS * 8));
        } catch (Throwable) {
            return null;
        }

        return $values === false ? null : array_values($values);
    }

    /** 0 none, 1 transient network, 2 missing articles, 3 corrupt data, 4 permanent, 5 unknown. */
    private static function w47RetryClass(string $lower): int
    {
        if ($lower === '') {
            return 0;
        }
        if (str_contains($lower, 'password') || str_contains($lower, 'encrypted') || str_contains($lower, 'unsupported')) {
            return 4;
        }
        if (str_contains($lower, 'crc') || str_contains($lower, 'corrupt') || str_contains($lower, 'checksum') || str_contains($lower, 'bad data')) {
            return 3;
        }
        if (str_contains($lower, '430') || str_contains($lower, 'no such article') || str_contains($lower, 'missing') || str_contains($lower, 'incomplete')) {
            return 2;
        }
        if (str_contains($lower, 'timeout') || str_contains($lower, 'timed out') || str_contains($lower, 'connection')
            || str_contains($lower, 'reset by peer') || str_contains($lower, 'refused') || str_contains($lower, 'temporar')) {
            return 1;
        }

        return 5;
    }

    /**
     * NUL-joined items, or their concatenation with `pack('V*')` lengths when an item holds a NUL.
     *
     * @param  list<string>  $items
     * @return array{0: string, 1: string}
     */
    private static function w47Strings(array $items): array
    {
        $joined = implode("\0", $items);
        if ($items === [] || substr_count($joined, "\0") === \count($items) - 1) {
            return [$joined, ''];
        }

        return [implode('', $items), pack('V*', ...array_map(strlen(...), $items))];
    }

    /**
     * @template T of int|string
     *
     * @param  array<array-key, T>  $values
     * @param  T  $default
     * @return list<T>
     */
    private static function w47Fit(array $values, int $count, int|string $default): array
    {
        return \count($values) === $count && array_is_list($values) ? $values : array_pad(\array_slice(array_values($values), 0, $count), $count, $default);
    }

    /** Readonly classes keep the nullable toggle in a function static. */
    private static function w47Toggle(bool $set = false, ?bool $enabled = null): ?bool
    {
        /** @var bool|null $state */
        static $state = null;
        if ($set) {
            $state = $enabled;
        }

        return $state;
    }

    /**
     * Cached binding and an output buffer of at least `$fields` values; `$reset` drops both.
     *
     * @return array{0: FFI, 1: CData}|null
     */
    private static function w47Binding(int $fields, bool $reset = false): ?array
    {
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $buffer */
        static $buffer = null;
        if ($reset) {
            $ffi = $buffer = null;

            return null;
        }
        $ffi ??= RustPipelineLibrary::resolveDomain('additional_w47', self::W47_FFI_CDEF) ?? false;
        if ($ffi === false) {
            return null;
        }
        $buffer ??= $ffi->new('int64_t['.max(256, $fields).']');

        return $buffer === null ? null : [$ffi, $buffer];
    }

    /** @return array<string, int> */
    public function outcomeCountsNative(): ?array
    {
        return $this->metricsNative()['outcomeCounts'] ?? null;
    }

    /** @return array<string, int> */
    public function unsupportedReasonCountsNative(): ?array
    {
        return $this->metricsNative()['unsupportedReasonCounts'] ?? null;
    }

    /** @return array<string, float> */
    public function stageDurationTotalsNative(): ?array
    {
        return $this->metricsNative()['stageDurationTotals'] ?? null;
    }

    public function downloadMetricsNative(): ?DownloadMetrics
    {
        $value = $this->metricsNative()['downloadMetrics'] ?? null;

        return $value === null ? null : new DownloadMetrics(...$value);
    }

    public function persistenceMetricsNative(): ?PersistenceMetrics
    {
        $value = $this->metricsNative()['persistenceMetrics'] ?? null;

        return $value === null ? null : new PersistenceMetrics(...$value);
    }

    public function artifactYieldPercentNative(): ?float
    {
        return $this->metricsNative()['artifactYieldPercent'] ?? null;
    }

    /** @return array<string, mixed>|null */
    private function metricsNative(): ?array
    {
        $rows = [];
        foreach ($this->results as $result) {
            $rows[] = [
                'outcome' => $result->outcome->value,
                'successful' => $result->isSuccessful(),
                'artifactsCreated' => $result->artifactsCreated,
                'unsupportedReasons' => $result->unsupportedReasons,
                'stageDurations' => (object) $result->stageDurations,
                'downloadMetrics' => $result->downloadMetrics,
                'persistenceMetrics' => $result->persistenceMetrics,
            ];
        }
        $value = self::nativeJson($rows);
        if (! is_array($value)) {
            return null;
        }
        foreach ($value['stageDurationTotals'] as &$duration) {
            $duration = self::decodeFloat($duration);
        }
        unset($duration);
        $value['persistenceMetrics']['databaseMilliseconds'] = self::decodeFloat($value['persistenceMetrics']['databaseMilliseconds']);
        $value['artifactYieldPercent'] = self::decodeFloat($value['artifactYieldPercent']);

        return $value;
    }

    private static function decodeFloat(string $bits): float
    {
        return unpack('d', pack('q', (int) $bits))[1];
    }

    /** @param array<mixed> $input */
    private static function nativeJson(array $input): mixed
    {
        try {
            $ffi = RustPipelineLibrary::resolveDomain('additional',
                'ssize_t nntmux_additional_batch_result_metrics_json(const char *input, size_t len, char *out, size_t cap);');
            if ($ffi === null) {
                return null;
            }
            $json = json_encode($input, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, strlen($json)), $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound (domain C ABI)
            $written = $ffi->nntmux_additional_batch_result_metrics_json($json, strlen($json), $scratch, $cap);
            if ($written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound (domain C ABI)
                $written = $ffi->nntmux_additional_batch_result_metrics_json($json, strlen($json), $scratch, $cap);
            }
            if ($written < 0 || $written > $cap) {
                return null;
            }

            return json_decode(FFI::string($scratch, $written), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
    }

    // Rust is the default only while the paired W54.6 benchmark clears 1.5x on all four metrics.
    private const bool W54_RUST_FFI_DEFAULT = true;

    public const string W54_FFI_CDEF = <<<'C'
        int32_t nntmux_w546_run_summary_batch(const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    public const int W54_RUN_HEADER = 13;

    private const int W54_MAX_ITEMS = 1_048_576;

    private const int W54_COUNT_CAP = 1_048_575;

    private const int W54_VALUE_CAP = 1_099_511_627_775;

    private const int W54_TOTAL_CAP = 4_611_686_018_427_387_904;

    /**
     * Throughput, success and artifact-yield summary of many batch runs (one `AdditionalBatchResult` each).
     *
     * Counts clamp to [0, 2^20), elapsed microseconds and peak memory to [0, 2^40); missing values are 0.
     * Layout: `[0]` count, `[1..5]` claimed/attempted/successful/artifact/elapsed totals (capped at 2^62),
     * `[6]` peak memory and `[7]` its first run (-1 when none), `[8]` first fastest run by throughput (-1
     * when none attempted releases in measurable time), `[9]` first slowest run by mean release time (-1
     * when none attempted releases), `[10]` flagged runs, `[11]` mean success ppm over runs that attempted
     * releases, `[12]` FNV-1a digest; then two words per run: `throughput | flags << 40` (releases per
     * 1,000 seconds, capped at 2^40-1; flag bit 0 claimed without attempts, bit 1 more attempted than
     * claimed, bit 2 more successful than attempted, bit 3 more artifact releases than successful, bit 4
     * attempts in zero time) and `success_ppm | yield_ppm << 20 | mean_ms << 40` (mean_ms capped at 2^20-1).
     *
     * @param  array<array-key, int>  $claimed
     * @param  array<array-key, int>  $attempted
     * @param  array<array-key, int>  $successful
     * @param  array<array-key, int>  $artifacts
     * @param  array<array-key, int>  $elapsedMicros
     * @param  array<array-key, int>  $peakMemoryBytes
     * @return list<int>
     */
    public static function summarizeRunBatch(array $claimed, array $attempted, array $successful, array $artifacts, array $elapsedMicros, array $peakMemoryBytes): array
    {
        return (self::usesW54RustFfi() ? self::summarizeRunBatchNative($claimed, $attempted, $successful, $artifacts, $elapsedMicros, $peakMemoryBytes) : null)
            ?? self::summarizeRunBatchPhp($claimed, $attempted, $successful, $artifacts, $elapsedMicros, $peakMemoryBytes);
    }

    /**
     * @param  array<array-key, int>  $claimed
     * @param  array<array-key, int>  $attempted
     * @param  array<array-key, int>  $successful
     * @param  array<array-key, int>  $artifacts
     * @param  array<array-key, int>  $elapsedMicros
     * @param  array<array-key, int>  $peakMemoryBytes
     * @return list<int>
     */
    public static function summarizeRunBatchPhp(array $claimed, array $attempted, array $successful, array $artifacts, array $elapsedMicros, array $peakMemoryBytes): array
    {
        $claimed = array_values($claimed);
        $attempted = array_values($attempted);
        $successful = array_values($successful);
        $artifacts = array_values($artifacts);
        $elapsedMicros = array_values($elapsedMicros);
        $peakMemoryBytes = array_values($peakMemoryBytes);
        $count = \count($claimed);
        $countCap = self::W54_COUNT_CAP;
        $valueCap = self::W54_VALUE_CAP;
        $totalCap = self::W54_TOTAL_CAP;
        $out = array_fill(0, self::W54_RUN_HEADER, 0);
        $out[0] = $count;
        $peakAt = $fastAt = $slowAt = -1;
        $peak = $fast = $slow = 0;
        $successSum = $successRuns = 0;
        $digest = 2_166_136_261;
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $c = min(max($claimed[$i], 0), $countCap);
            $a = min(max($attempted[$i] ?? 0, 0), $countCap);
            $s = min(max($successful[$i] ?? 0, 0), $countCap);
            $art = min(max($artifacts[$i] ?? 0, 0), $countCap);
            $e = min(max($elapsedMicros[$i] ?? 0, 0), $valueCap);
            $m = min(max($peakMemoryBytes[$i] ?? 0, 0), $valueCap);
            $out[1] = min($out[1] + $c, $totalCap);
            $out[2] = min($out[2] + $a, $totalCap);
            $out[3] = min($out[3] + $s, $totalCap);
            $out[4] = min($out[4] + $art, $totalCap);
            $out[5] = min($out[5] + $e, $totalCap);
            if ($peakAt < 0 || $m > $peak) {
                $peakAt = $i;
                $peak = $m;
            }
            $throughput = $e > 0 ? min(intdiv($a * 1_000_000_000, $e), $valueCap) : 0;
            if ($a > 0 && $e > 0 && ($fastAt < 0 || $throughput > $fast)) {
                $fastAt = $i;
                $fast = $throughput;
            }
            $success = $meanMs = 0;
            if ($a > 0) {
                $success = min(intdiv($s * 1_000_000, $a), 1_000_000);
                $meanMs = min(intdiv($e, $a * 1000), $countCap);
                if ($slowAt < 0 || $meanMs > $slow) {
                    $slowAt = $i;
                    $slow = $meanMs;
                }
                $successSum += $success;
                $successRuns++;
            }
            $yield = $s > 0 ? min(intdiv($art * 1_000_000, $s), 1_000_000) : 0;
            $flags = ($c > 0 && $a === 0 ? 1 : 0) | ($a > $c ? 2 : 0) | ($s > $a ? 4 : 0) | ($art > $s ? 8 : 0) | ($a > 0 && $e === 0 ? 16 : 0);
            if ($flags !== 0) {
                $out[10]++;
            }
            $first = $throughput | $flags << 40;
            $second = $success | $yield << 20 | $meanMs << 40;
            $digest = (($digest ^ (($first ^ ($first >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $digest = (($digest ^ (($second ^ ($second >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $first;
            $rows[] = $second;
        }
        $out[6] = $peak;
        $out[7] = $peakAt;
        $out[8] = $fastAt;
        $out[9] = $slowAt;
        if ($successRuns > 0) {
            $out[11] = intdiv($successSum, $successRuns);
        }
        $out[12] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust summary, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, int>  $claimed
     * @param  array<array-key, int>  $attempted
     * @param  array<array-key, int>  $successful
     * @param  array<array-key, int>  $artifacts
     * @param  array<array-key, int>  $elapsedMicros
     * @param  array<array-key, int>  $peakMemoryBytes
     * @return list<int>|null
     */
    public static function summarizeRunBatchNative(array $claimed, array $attempted, array $successful, array $artifacts, array $elapsedMicros, array $peakMemoryBytes): ?array
    {
        $count = \count($claimed);
        if ($count > self::W54_MAX_ITEMS) {
            return null;
        }
        $ints = pack(
            'q*',
            ...self::w54Fit($claimed, $count), ...self::w54Fit($attempted, $count), ...self::w54Fit($successful, $count),
            ...self::w54Fit($artifacts, $count), ...self::w54Fit($elapsedMicros, $count), ...self::w54Fit($peakMemoryBytes, $count),
        );
        $fields = self::W54_RUN_HEADER + 2 * $count;

        // @phpstan-ignore method.notFound
        return self::w54Call($fields, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w546_run_summary_batch($ints, \strlen($ints), $count, $out, $fields));
    }

    /**
     * Run `$callback` with the Wave 54.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW54RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w54RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w54RustFfiState('set', $previous);
        }
    }

    public static function usesW54RustFfi(): bool
    {
        $forced = self::w54RustFfiState();

        return \is_bool($forced) ? $forced : self::W54_RUST_FFI_DEFAULT;
    }

    /**
     * Wave 54.6 routing and binding, kept in function statics because readonly classes cannot declare
     * static properties. No `$op` returns the forced routing (null for the default); `'set'` replaces it
     * with `$val` and returns the previous value; `'binding'` returns the cached `additional_w54` binding
     * and an output buffer of at least `$val` values, or null.
     */
    private static function w54RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $enabled */
        static $enabled = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $buffer */
        static $buffer = null;
        static $capacity = 0;
        if ($op === 'set') {
            $previous = $enabled;
            $enabled = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op !== 'binding') {
            return $enabled;
        }
        $fields = \is_int($val) ? $val : 0;
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w54', self::W54_FFI_CDEF) ?? false;
            if ($ffi === false) {
                return null;
            }
            if ($buffer === null || $capacity < $fields) {
                $capacity = max(256, $fields);
                $buffer = $ffi->new('int64_t['.$capacity.']');
            }
        } catch (Throwable) {
            return null;
        }

        return $buffer === null ? null : [$ffi, $buffer];
    }

    /**
     * The batch's `$fields` output words from one Wave 54.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w54Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w54RustFfiState('binding', $fields);
        if ($binding === null) {
            return null;
        }
        try {
            if ($call($binding[0], $binding[1]) !== 1) {
                return null;
            }
            $values = unpack('P*', FFI::string($binding[1], $fields * 8));
        } catch (Throwable) {
            return null;
        }

        return $values === false ? null : array_values($values);
    }

    /**
     * Exactly `$count` values: the list itself when it already fits, otherwise padded with `$default`.
     *
     * @param  array<array-key, int>  $values
     * @return list<int>
     */
    private static function w54Fit(array $values, int $count, int $default = 0): array
    {
        return \count($values) === $count && array_is_list($values) ? $values : array_pad(\array_slice(array_values($values), 0, $count), $count, $default);
    }

    // ---- Wave 64.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 8.98x/7.87x, uncommon 9.01x/8.16x); see RustAdditionalConfigConsoleBatchResultWorkPlanArchiveCandidateW64ParityTest. */
    public const bool W64_RUST_FFI_DEFAULT = true;

    private const string W64_ADDITIONAL_BATCH_RESULT_FFI_CDEF = <<<'C'
        intptr_t nntmux_w642_batch_result_plan_batch(const char *items, size_t items_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Summary words after the per-row words, the digest included. */
    public const int W64_ADDITIONAL_BATCH_RESULT_TAIL = 48;

    /**
     * Wave 64 routing, binding and output buffer, kept in function-local statics (no static or
     * instance properties). Operations: null reads the routing override, 'set' replaces it
     * (returning the previous one), 'reset' also drops the binding and buffer, 'ffi' resolves the
     * binding and 'out' returns `[binding, buffer, capacity]` with room for at least $val words
     * (null without the library).
     */
    private static function w64RustFfiState(?string $op = null, mixed $val = null): mixed
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
            case 'reset':
                $previous = $enabled;
                [$enabled, $ffi, $out, $capacity] = [null, null, null, 0];

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w64', self::W64_ADDITIONAL_BATCH_RESULT_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w64RustFfiState('ffi');
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

    /** Routes w64BatchResultPlanBatch() to Rust until reset. */
    public static function enableW64RustFfi(): void
    {
        self::w64RustFfiState('set', true);
    }

    /** Routes w64BatchResultPlanBatch() to PHP until reset. */
    public static function disableW64RustFfi(): void
    {
        self::w64RustFfiState('set', false);
    }

    /** Restores W64_RUST_FFI_DEFAULT and drops the cached binding and buffer; earlier toggles are untouched. */
    public static function resetW64RustFfi(): void
    {
        self::w64RustFfiState('reset');
    }

    public static function usesW64RustFfi(): bool
    {
        $enabled = self::w64RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W64_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with w64BatchResultPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous
     * routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW64RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w64RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w64RustFfiState('set', $previous);
        }
    }

    /**
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w64BatchResultPlanBatch(array $rows): array
    {
        return self::usesW64RustFfi() ? self::w64BatchResultPlanBatchNative($rows) : self::w64BatchResultPlanBatchPhp($rows);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a row holds a NUL byte or the
     * column is not a list.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w64BatchResultPlanBatchNative(array $rows): array
    {
        $count = \count($rows);
        $words = $count + self::W64_ADDITIONAL_BATCH_RESULT_TAIL;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w64RustFfiState('out', $words);
        if ($state === null || ! array_is_list($rows)) {
            return self::w64BatchResultPlanBatchPhp($rows);
        }
        $blob = implode("\0", $rows);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W64_ADDITIONAL_BATCH_RESULT_FFI_CDEF.)
            $written = $ffi->nntmux_w642_batch_result_plan_batch($blob, \strlen($blob), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : self::w64BatchResultPlanBatchPhp($rows);
    }

    /** @param  list<int>  $out */
    private static function w64Digest(array $out): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));

        return $digest[1];
    }

    /** A trimmed field: null when empty, its value for 1 to $max ASCII digits, otherwise false. */
    private static function w64Int(string $value, int $max): int|false|null
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $length = \strlen($value);

        return $length <= $max && strspn($value, '0123456789') === $length ? (int) $value : false;
    }

    /** ProcessingOutcome values => codes 1-12 in case order. */
    private const array W64_OUTCOMES = ['completed' => 1, 'no-useful-artifacts' => 2, 'passworded' => 3, 'group-unavailable' => 4,
        'temporary-workspace-unavailable' => 5, 'timed-out' => 6, 'deleted-after-timeout' => 7, 'deleted-broken-nzb' => 8, 'deleted-par2-only' => 9,
        'not-found' => 10, 'awaiting-late-articles' => 11, 'failed' => 12];

    /** Extracted media artifact => bit. */
    private const array W64_MEDIA = ['sample' => 0, 'jpg' => 1, 'video' => 2, 'audio' => 3, 'mediainfo' => 4, 'nfo' => 5, 'filelist' => 6];

    private const array W64_PASSWORD = ['yes' => 1, '1' => 1, 'true' => 1, 'no' => 2, '0' => 2, 'false' => 2];

    /** Elapsed-milliseconds bucket bounds. */
    private const array W64_ELAPSED_BOUNDS = [1_000, 10_000, 60_000, 300_000];

    private const int W64_MAX_ATTEMPTS = 3;

    /** Error class: 0 none, 1 timeout, 2 connection, 3 missing article, 4 password, 5 corrupt, 6 other. */
    private static function w64ErrorClass(string $error): int
    {
        $error = strtolower(trim($error));

        return match (true) {
            $error === '' => 0,
            str_contains($error, 'timeout') || str_contains($error, 'timed out') => 1,
            str_contains($error, 'connection') || str_contains($error, 'socket') => 2,
            str_contains($error, 'no such article') || str_contains($error, '430') => 3,
            str_contains($error, 'password') => 4,
            str_contains($error, 'crc') || str_contains($error, 'corrupt') => 5,
            default => 6,
        };
    }

    /**
     * Wave 64.2 release result rollup. Per `release_id\toutcome\telapsed_ms\tattempts\tpassword\t
     * media\terror` row `outcome | error_class << 4 | password << 7 | media << 9 | flags << 16 |
     * elapsed_bucket << 22 | min(attempts, 255) << 25 | min(elapsed_ms / 1000, 2^20 - 1) << 33 |
     * min(unknown_media, 7) << 53`. The outcome is a trimmed, case-insensitive ProcessingOutcome
     * value (W64_OUTCOMES, else 0); release_id, elapsed_ms and attempts are w64Int() fields (12, 9
     * and 3 digits, empty or unparsable 0). Password: 0 empty, 1 yes/1/true, 2 no/0/false, 3
     * other. Media: comma-separated, trimmed, case-insensitive W64_MEDIA names. Error class: see
     * w64ErrorClass(). Flags: successful (completed, no useful artifacts, passworded), deleted
     * (the three deleted outcomes), retryable (any other known outcome but not-found, with a
     * timeout or connection error or a group, workspace, timeout or late-article outcome, under
     * W64_MAX_ATTEMPTS attempts), password conflict (passworded but marked clear, or completed but
     * marked passworded), duplicate (a positive release id seen earlier in the batch) and
     * malformed (an unparsable number or an unknown outcome). Elapsed buckets split at
     * W64_ELAPSED_BOUNDS. Then 13 outcome, seven error class, four password, seven media, six flag
     * and five bucket counts, total and longest elapsed milliseconds, total attempts, distinct
     * positive release ids, total media artifacts and the XXH64 digest of all earlier words.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w64BatchResultPlanBatchPhp(array $rows): array
    {
        $out = $ids = [];
        $outcomes = array_fill(0, 13, 0);
        $errors = $mediaCounts = array_fill(0, 7, 0);
        $passwords = array_fill(0, 4, 0);
        $flagCounts = array_fill(0, 6, 0);
        $buckets = array_fill(0, 5, 0);
        $totalElapsed = $maxElapsed = $totalAttempts = $artifacts = 0;
        foreach ($rows as $row) {
            [$id, $outcome, $elapsed, $attempts, $password, $media, $error] = explode("\t", (string) $row, 7) + array_fill(0, 7, '');
            $id = self::w64Int($id, 12);
            $elapsed = self::w64Int($elapsed, 9);
            $attempts = self::w64Int($attempts, 3);
            $outcome = self::W64_OUTCOMES[strtolower(trim($outcome))] ?? 0;
            $errorClass = self::w64ErrorClass($error);
            $password = trim($password);
            $passwordState = $password === '' ? 0 : (self::W64_PASSWORD[strtolower($password)] ?? 3);
            $mask = $unknown = 0;
            foreach (explode(',', $media) as $token) {
                $token = strtolower(trim($token));
                if ($token === '') {
                    continue;
                }
                $bit = self::W64_MEDIA[$token] ?? null;
                if ($bit === null) {
                    $unknown++;
                } else {
                    $mask |= 1 << $bit;
                }
            }
            $ms = \is_int($elapsed) ? $elapsed : 0;
            $tries = \is_int($attempts) ? $attempts : 0;
            $successful = $outcome >= 1 && $outcome <= 3;
            $deleted = $outcome >= 7 && $outcome <= 9;
            $retryable = $outcome !== 0 && ! $successful && ! $deleted && $outcome !== 10
                && ($errorClass === 1 || $errorClass === 2 || \in_array($outcome, [4, 5, 6, 11], true)) && $tries < self::W64_MAX_ATTEMPTS;
            $conflict = ($outcome === 3 && $passwordState === 2) || ($outcome === 1 && $passwordState === 1);
            $duplicate = false;
            if (\is_int($id) && $id > 0) {
                $duplicate = isset($ids[$id]);
                $ids[$id] = true;
            }
            $malformed = $id === false || $elapsed === false || $attempts === false || $outcome === 0;
            $flags = (int) $successful | (int) $deleted << 1 | (int) $retryable << 2 | (int) $conflict << 3 | (int) $duplicate << 4 | (int) $malformed << 5;
            $bucket = 0;
            foreach (self::W64_ELAPSED_BOUNDS as $bound) {
                $bucket += (int) ($ms >= $bound);
            }
            $outcomes[$outcome]++;
            $errors[$errorClass]++;
            $passwords[$passwordState]++;
            for ($k = 0; $k < 7; $k++) {
                $mediaCounts[$k] += $mask >> $k & 1;
            }
            for ($k = 0; $k < 6; $k++) {
                $flagCounts[$k] += $flags >> $k & 1;
            }
            $buckets[$bucket]++;
            $totalElapsed += $ms;
            $maxElapsed = max($maxElapsed, $ms);
            $totalAttempts += $tries;
            $artifacts += substr_count(decbin($mask), '1');
            $out[] = $outcome | $errorClass << 4 | $passwordState << 7 | $mask << 9 | $flags << 16 | $bucket << 22 | min($tries, 255) << 25
                | min(intdiv($ms, 1000), 0xFFFFF) << 33 | min($unknown, 7) << 53;
        }
        array_push($out, ...$outcomes, ...$errors, ...$passwords, ...$mediaCounts, ...$flagCounts, ...$buckets);
        array_push($out, $totalElapsed, $maxElapsed, $totalAttempts, \count($ids), $artifacts);
        $out[] = self::w64Digest($out);

        return $out;
    }
}
