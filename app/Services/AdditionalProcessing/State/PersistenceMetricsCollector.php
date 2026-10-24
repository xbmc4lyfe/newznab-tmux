<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\State;

use App\Services\AdditionalProcessing\DTO\PersistenceMetrics;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use InvalidArgumentException;
use Throwable;

final class PersistenceMetricsCollector
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_pmc_apply(int32_t event, int32_t has_scope, int64_t scope_id, int64_t arg_id, double arg_ms, int64_t *counters, size_t counter_count, double *milliseconds);
        C;

    public const int EVENT_DATABASE_STATEMENT = 0;

    public const int EVENT_SEARCH_SYNC_REQUEST = 1;

    public const int EVENT_SEARCH_SYNC_EXECUTION = 2;

    // PHP remains the production default until benchmarks qualify Rust.
    public static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private static ?CData $counters = null;

    private static ?CData $milliseconds = null;

    private ?int $releaseId = null;

    private int $databaseStatements = 0;

    private float $databaseMilliseconds = 0.0;

    private int $searchSyncRequests = 0;

    private int $searchSyncExecutions = 0;

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function additionalFfi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    public function beginReleaseScope(int $releaseId): void
    {
        $this->releaseId = $releaseId;
        $this->databaseStatements = 0;
        $this->databaseMilliseconds = 0.0;
        $this->searchSyncRequests = 0;
        $this->searchSyncExecutions = 0;
    }

    public function recordDatabaseStatement(float $milliseconds): void
    {
        $this->record(self::EVENT_DATABASE_STATEMENT, 0, $milliseconds);
    }

    public function recordSearchSyncRequest(int $releaseId): void
    {
        $this->record(self::EVENT_SEARCH_SYNC_REQUEST, $releaseId, 0.0);
    }

    public function recordSearchSyncExecution(int $releaseId): void
    {
        $this->record(self::EVENT_SEARCH_SYNC_EXECUTION, $releaseId, 0.0);
    }

    public function finishReleaseScope(): PersistenceMetrics
    {
        $metrics = new PersistenceMetrics(
            databaseStatements: $this->databaseStatements,
            databaseMilliseconds: $this->databaseMilliseconds,
            searchSyncRequests: $this->searchSyncRequests,
            searchSyncExecutions: $this->searchSyncExecutions,
        );

        $this->releaseId = null;
        $this->databaseStatements = 0;
        $this->databaseMilliseconds = 0.0;
        $this->searchSyncRequests = 0;
        $this->searchSyncExecutions = 0;

        return $metrics;
    }

    /**
     * Counters after one event: `[statements, milliseconds, sync requests, sync executions]`,
     * unchanged when the event falls outside the scoped release.
     *
     * @param  array{0: int, 1: float, 2: int, 3: int}  $counters
     * @return array{0: int, 1: float, 2: int, 3: int}
     */
    public static function applyEvent(int $event, ?int $scope, int $releaseId, float $milliseconds, array $counters): array
    {
        return self::$useRustFfi
            ? self::applyEventNative($event, $scope, $releaseId, $milliseconds, $counters)
            : self::applyEventPhp($event, $scope, $releaseId, $milliseconds, $counters);
    }

    /**
     * @param  array{0: int, 1: float, 2: int, 3: int}  $counters
     * @return array{0: int, 1: float, 2: int, 3: int}
     */
    public static function applyEventPhp(int $event, ?int $scope, int $releaseId, float $milliseconds, array $counters): array
    {
        switch ($event) {
            case self::EVENT_DATABASE_STATEMENT:
                if ($scope !== null) {
                    $counters[0]++;
                    $counters[1] += $milliseconds;
                }

                return $counters;
            case self::EVENT_SEARCH_SYNC_REQUEST:
                if ($scope === $releaseId) {
                    $counters[2]++;
                }

                return $counters;
            case self::EVENT_SEARCH_SYNC_EXECUTION:
                if ($scope === $releaseId) {
                    $counters[3]++;
                }

                return $counters;
            default:
                throw new InvalidArgumentException("Unknown persistence metrics event {$event}.");
        }
    }

    /**
     * @param  array{0: int, 1: float, 2: int, 3: int}  $counters
     * @return array{0: int, 1: float, 2: int, 3: int}
     */
    public static function applyEventNative(int $event, ?int $scope, int $releaseId, float $milliseconds, array $counters): array
    {
        $ffi = self::additionalFfi();
        if ($ffi === null) {
            return self::applyEventPhp($event, $scope, $releaseId, $milliseconds, $counters);
        }

        try {
            $values = self::$counters ??= $ffi->new('int64_t[3]');
            $total = self::$milliseconds ??= $ffi->new('double');
            $values[0] = $counters[0];
            $values[1] = $counters[2];
            $values[2] = $counters[3];
            // @phpstan-ignore property.notFound
            $total->cdata = $counters[1];
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_pmc_apply(
                $event,
                $scope === null ? 0 : 1,
                $scope ?? 0,
                $releaseId,
                $milliseconds,
                $values,
                3,
                FFI::addr($total),
            );
            if ($status === 0) {
                return $counters;
            }
            if ($status !== 1) {
                return self::applyEventPhp($event, $scope, $releaseId, $milliseconds, $counters);
            }

            return [$values[0], $total->cdata, $values[1], $values[2]];
        } catch (Throwable) {
            return self::applyEventPhp($event, $scope, $releaseId, $milliseconds, $counters);
        }
    }

    private function record(int $event, int $releaseId, float $milliseconds): void
    {
        [
            $this->databaseStatements,
            $this->databaseMilliseconds,
            $this->searchSyncRequests,
            $this->searchSyncExecutions,
        ] = self::applyEvent($event, $this->releaseId, $releaseId, $milliseconds, [
            $this->databaseStatements,
            $this->databaseMilliseconds,
            $this->searchSyncRequests,
            $this->searchSyncExecutions,
        ]);
    }
}
