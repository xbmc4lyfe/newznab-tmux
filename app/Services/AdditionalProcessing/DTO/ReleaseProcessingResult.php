<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

use App\Services\AdditionalProcessing\Enums\ProcessingOutcome;
use App\Support\RustPipelineLibrary;
use FFI;

final readonly class ReleaseProcessingResult
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_rpresult_outcome_successful(const char *value, size_t len);
        ssize_t nntmux_rpresult_duration_summary(const char *packed, size_t len, double elapsed, char *out, size_t cap);
        C;

    /**
     * @param  array<string, float>  $stageDurations
     * @param  list<string>  $unsupportedReasons
     */
    public function __construct(
        public int $releaseId,
        public string $guid,
        public ProcessingOutcome $outcome,
        public bool $artifactsCreated = false,
        public int $releaseFilesAdded = 0,
        public string $reason = '',
        public float $elapsedSeconds = 0.0,
        public array $stageDurations = [],
        public ?DownloadMetrics $downloadMetrics = null,
        public ?PersistenceMetrics $persistenceMetrics = null,
        public int $duplicateMessageIdCount = 0,
        public array $unsupportedReasons = [],
        // PHP remains the production default until benchmarks qualify Rust.
        public bool $useRustFfi = false,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->useRustFfi ? $this->isSuccessfulNative() : $this->isSuccessfulPhp();
    }

    public function isSuccessfulPhp(): bool
    {
        return $this->outcome->isSuccessful();
    }

    public function isSuccessfulNative(): bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return $this->isSuccessfulPhp();
        }

        $value = $this->outcome->value;
        // @phpstan-ignore method.notFound
        $successful = $ffi->nntmux_rpresult_outcome_successful($value, strlen($value));

        return $successful === 0 || $successful === 1 ? $successful === 1 : $this->isSuccessfulPhp();
    }

    /**
     * Stage count and total, the slowest stage (first on ties) and the elapsed time
     * not covered by any stage (never negative).
     *
     * @return array{stageCount: int, stageSeconds: float, slowestStage: ?string, slowestSeconds: float, unaccountedSeconds: float}
     */
    public function durationSummary(): array
    {
        return $this->useRustFfi ? $this->durationSummaryNative() : $this->durationSummaryPhp();
    }

    /**
     * @return array{stageCount: int, stageSeconds: float, slowestStage: ?string, slowestSeconds: float, unaccountedSeconds: float}
     */
    public function durationSummaryPhp(): array
    {
        $total = 0.0;
        $slowestStage = null;
        $slowestSeconds = 0.0;
        foreach ($this->stageDurations as $stage => $seconds) {
            $seconds = (float) $seconds;
            $total += $seconds;
            if ($slowestStage === null || $seconds > $slowestSeconds) {
                $slowestStage = (string) $stage;
                $slowestSeconds = $seconds;
            }
        }

        $unaccounted = $this->elapsedSeconds - $total;
        if ($unaccounted < 0.0) {
            $unaccounted = 0.0;
        }

        return [
            'stageCount' => count($this->stageDurations),
            'stageSeconds' => $total,
            'slowestStage' => $slowestStage,
            'slowestSeconds' => $slowestSeconds,
            'unaccountedSeconds' => $unaccounted,
        ];
    }

    /**
     * @return array{stageCount: int, stageSeconds: float, slowestStage: ?string, slowestSeconds: float, unaccountedSeconds: float}
     */
    public function durationSummaryNative(): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return $this->durationSummaryPhp();
        }

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 32, $cap);
        if ($scratch === null) {
            return $this->durationSummaryPhp();
        }

        $packed = $this->stageDurations === [] ? '' : pack('e*', ...array_values($this->stageDurations));
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_rpresult_duration_summary($packed, strlen($packed), $this->elapsedSeconds, $scratch, $cap);
        if ($written !== 32) {
            return $this->durationSummaryPhp();
        }

        $summary = unpack('etotal/eslowest/eunaccounted/Pindex', FFI::string($scratch, 32));
        if (! is_array($summary)) {
            return $this->durationSummaryPhp();
        }
        $index = (int) $summary['index'];

        return [
            'stageCount' => count($this->stageDurations),
            'stageSeconds' => (float) $summary['total'],
            'slowestStage' => $index === 0 ? null : (string) array_keys($this->stageDurations)[$index - 1],
            'slowestSeconds' => (float) $summary['slowest'],
            'unaccountedSeconds' => (float) $summary['unaccounted'],
        ];
    }

    /**
     * @param  array<string, float>  $stageDurations
     */
    public function withPerformance(
        float $elapsedSeconds,
        array $stageDurations,
        ?DownloadMetrics $downloadMetrics = null,
        ?PersistenceMetrics $persistenceMetrics = null,
    ): self {
        return new self(
            releaseId: $this->releaseId,
            guid: $this->guid,
            outcome: $this->outcome,
            artifactsCreated: $this->artifactsCreated,
            releaseFilesAdded: $this->releaseFilesAdded,
            reason: $this->reason,
            elapsedSeconds: $elapsedSeconds,
            stageDurations: $stageDurations,
            downloadMetrics: $downloadMetrics ?? $this->downloadMetrics,
            persistenceMetrics: $persistenceMetrics ?? $this->persistenceMetrics,
            duplicateMessageIdCount: $this->duplicateMessageIdCount,
            unsupportedReasons: $this->unsupportedReasons,
            useRustFfi: $this->useRustFfi,
        );
    }

    public static function ffi(): ?FFI
    {
        static $ffi = null;
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return $ffi === false ? null : $ffi;
    }
}
