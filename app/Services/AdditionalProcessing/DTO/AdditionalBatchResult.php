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
}
