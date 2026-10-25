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

    public const bool W466_RUST_FFI_DEFAULT = false;

    public const string W466_FFI_CDEF = <<<'C'
        int32_t nntmux_w466_pmc_apply_batch(const char *integers, size_t integers_len, const char *milliseconds, size_t milliseconds_len, size_t count, int32_t has_scope, int64_t scope_id, int64_t statements, double total_ms, int64_t requests, int64_t executions, int64_t *out, size_t out_count, double *out_ms);
        C;

    public const int EVENT_DATABASE_STATEMENT = 0;

    public const int EVENT_SEARCH_SYNC_REQUEST = 1;

    public const int EVENT_SEARCH_SYNC_EXECUTION = 2;

    // PHP remains the production default until benchmarks qualify Rust.
    public static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private static ?CData $counters = null;

    private static ?CData $milliseconds = null;

    private static ?bool $w466RustFfi = null;

    private static FFI|false|null $w466Ffi = null;

    private static ?CData $w466Counters = null;

    private static ?CData $w466Milliseconds = null;

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

    private static function w466Ffi(): ?FFI
    {
        if (self::$w466Ffi === null) {
            self::$w466Ffi = RustPipelineLibrary::resolveDomain('additional', self::W466_FFI_CDEF) ?? false;
        }

        return self::$w466Ffi === false ? null : self::$w466Ffi;
    }

    public static function useW466RustFfi(?bool $enabled): void
    {
        self::$w466RustFfi = $enabled;
    }

    public static function usesW466RustFfi(): bool
    {
        return self::$w466RustFfi ?? self::W466_RUST_FFI_DEFAULT;
    }

    public static function resetW466RustFfi(): void
    {
        self::$w466RustFfi = null;
        self::$w466Ffi = null;
        self::$w466Counters = null;
        self::$w466Milliseconds = null;
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

    /**
     * Fold `[event, release id, milliseconds]` rows through applyEvent() in order, with
     * `(int)`/`(float)` casts. The variadic signature lets PHP enforce array rows.
     *
     * @param  array{0: int, 1: float, 2: int, 3: int}  $counters
     * @param  array<array-key, mixed>  ...$events
     * @return array{0: int, 1: float, 2: int, 3: int}
     */
    public static function applyEvents(?int $scope, array $counters, array ...$events): array
    {
        return (self::usesW466RustFfi() ? self::eventsNative($scope, $counters, $events) : null)
            ?? self::eventsPhp($scope, $counters, $events);
    }

    /**
     * @param  array{0: int, 1: float, 2: int, 3: int}  $counters
     * @param  array<array-key, mixed>  ...$events
     * @return array{0: int, 1: float, 2: int, 3: int}
     */
    public static function applyEventsPhp(?int $scope, array $counters, array ...$events): array
    {
        return self::eventsPhp($scope, $counters, $events);
    }

    /**
     * Rust fold, or null when PHP must apply the rows (rows without all three columns,
     * an unknown event, counter overflow or an unavailable library).
     *
     * @param  array{0: int, 1: float, 2: int, 3: int}  $counters
     * @param  array<array-key, mixed>  ...$events
     * @return array{0: int, 1: float, 2: int, 3: int}|null
     */
    public static function applyEventsNative(?int $scope, array $counters, array ...$events): ?array
    {
        return self::eventsNative($scope, $counters, $events);
    }

    /**
     * @param  array{0: int, 1: float, 2: int, 3: int}  $counters
     * @param  array<array-key, array<array-key, mixed>>  $events
     * @return array{0: int, 1: float, 2: int, 3: int}
     */
    private static function eventsPhp(?int $scope, array $counters, array $events): array
    {
        foreach ($events as $row) {
            [$event, $releaseId, $milliseconds] = $row;
            $counters = self::applyEventPhp((int) $event, $scope, (int) $releaseId, (float) $milliseconds, $counters);
        }

        return $counters;
    }

    /**
     * @param  array{0: int, 1: float, 2: int, 3: int}  $counters
     * @param  array<array-key, array<array-key, mixed>>  $events
     * @return array{0: int, 1: float, 2: int, 3: int}|null
     */
    private static function eventsNative(?int $scope, array $counters, array $events): ?array
    {
        $ffi = self::$w466Ffi instanceof FFI ? self::$w466Ffi : self::w466Ffi();
        if ($ffi === null || ! \is_int($counters[0]) || ! \is_float($counters[1]) || ! \is_int($counters[2]) || ! \is_int($counters[3])) {
            return null;
        }
        if ($events === []) {
            return $counters;
        }
        $count = \count($events);
        $kinds = array_column($events, 0);
        $ids = array_column($events, 1);
        $milliseconds = array_column($events, 2);
        if (\count($kinds) !== $count || \count($ids) !== $count || \count($milliseconds) !== $count) {
            return null;
        }
        // pack() reads values with the same conversions as the (int)/(float) casts:
        // event kinds then release IDs as int64, milliseconds as doubles.
        $integers = pack('q*', ...$kinds, ...$ids);
        $milliseconds = pack('e*', ...$milliseconds);

        try {
            $out = self::$w466Counters ??= $ffi->new('int64_t[3]');
            $total = self::$w466Milliseconds ??= $ffi->new('double[1]');
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_w466_pmc_apply_batch(
                $integers, \strlen($integers), $milliseconds, \strlen($milliseconds), $count,
                $scope === null ? 0 : 1, $scope ?? 0, $counters[0], $counters[1], $counters[2], $counters[3], $out, 3, $total,
            );
        } catch (Throwable) {
            return null;
        }

        return $status === 1 ? [$out[0], $total[0], $out[1], $out[2]] : null;
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
