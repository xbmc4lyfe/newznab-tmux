<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

use App\Services\AdditionalProcessing\Enums\ProcessingOutcome;
use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

final readonly class AdditionalBatchResult
{
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
