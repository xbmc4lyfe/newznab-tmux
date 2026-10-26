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

    // Rust is the default only while the paired W55.6 benchmark clears 1.5x on all four metrics.
    private const bool W55_RUST_FFI_DEFAULT = true;

    public const string W55_FFI_CDEF = <<<'C'
        int32_t nntmux_w556_scoped_event_batch(const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    public const int W55_EVENT_HEADER = 12;

    private const int W55_MAX_ITEMS = 1_048_576;

    private const int W55_COUNT_CAP = 1_048_575;

    private const int W55_SHORT_CAP = 65_535;

    private const int W55_VALUE_CAP = 1_099_511_627_775;

    private const int W55_TOTAL_CAP = 4_611_686_018_427_387_904;

    /**
     * Fold collector events across many release scopes in one pass, as consecutive
     * beginReleaseScope()/record*() calls would: a scope differing from the previous event's opens a
     * new scope with reset counters. Columns per event: kind (`EVENT_*`; others are unknown), scope
     * release ID (negative is no scope), event release ID and statement microseconds (clamped to
     * 0..2^40-1); missing values are 0. Statements count under any scope; sync requests and executions
     * only for the scope's own release. Running counters saturate at 2^20-1 statements, 2^40-1
     * microseconds and 2^16-1 requests/executions.
     *
     * Layout: `[0]` count, `[1]` statements, `[2]` statement microseconds (capped at 2^62), `[3]`
     * requests, `[4]` executions, `[5]` ignored, `[6]` unknown, `[7]` scopes opened, `[8]` slowest
     * statement microseconds and `[9]` its first event (-1 when none), `[10]` scopes closed with
     * requests differing from executions, `[11]` FNV-1a digest; then per event `status | opened << 2 |
     * kind << 3 | statements << 5 | requests << 25 | executions << 41` (status 0 ignored, 1 applied, 2
     * unknown; kind 3 for unknown; the scope's running counters after the event).
     *
     * @param  array<array-key, int>  $kinds
     * @param  array<array-key, int>  $scopes
     * @param  array<array-key, int>  $releaseIds
     * @param  array<array-key, int>  $micros
     * @return list<int>
     */
    public static function foldScopedEventBatch(array $kinds, array $scopes, array $releaseIds, array $micros): array
    {
        return (self::usesW55RustFfi() ? self::foldScopedEventBatchNative($kinds, $scopes, $releaseIds, $micros) : null)
            ?? self::foldScopedEventBatchPhp($kinds, $scopes, $releaseIds, $micros);
    }

    /**
     * @param  array<array-key, int>  $kinds
     * @param  array<array-key, int>  $scopes
     * @param  array<array-key, int>  $releaseIds
     * @param  array<array-key, int>  $micros
     * @return list<int>
     */
    public static function foldScopedEventBatchPhp(array $kinds, array $scopes, array $releaseIds, array $micros): array
    {
        $kinds = array_values($kinds);
        $scopes = array_values($scopes);
        $releaseIds = array_values($releaseIds);
        $micros = array_values($micros);
        $count = \count($kinds);
        $valueCap = self::W55_VALUE_CAP;
        $out = array_fill(0, self::W55_EVENT_HEADER, 0);
        $out[0] = $count;
        $slowAt = -1;
        $slow = 0;
        $scope = null;
        $started = false;
        $statements = $elapsed = $requests = $executions = 0;
        $digest = 2_166_136_261;
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $kind = $kinds[$i];
            $raw = $scopes[$i] ?? 0;
            $current = $raw >= 0 ? $raw : null;
            $release = $releaseIds[$i] ?? 0;
            $m = min(max($micros[$i] ?? 0, 0), $valueCap);
            $opened = 0;
            if (! $started || $current !== $scope) {
                if ($started && $scope !== null && $requests !== $executions) {
                    $out[10]++;
                }
                $started = true;
                $scope = $current;
                $statements = $elapsed = $requests = $executions = 0;
                if ($current !== null) {
                    $out[7]++;
                    $opened = 1;
                }
            }
            if ($kind === self::EVENT_DATABASE_STATEMENT && $current !== null) {
                $statements = min($statements + 1, self::W55_COUNT_CAP);
                $elapsed = min($elapsed + $m, $valueCap);
                $out[1]++;
                $out[2] = min($out[2] + $m, self::W55_TOTAL_CAP);
                if ($slowAt < 0 || $m > $slow) {
                    $slowAt = $i;
                    $slow = $m;
                }
                $status = 1;
                $code = 0;
            } elseif ($kind === self::EVENT_SEARCH_SYNC_REQUEST && $current === $release) {
                $requests = min($requests + 1, self::W55_SHORT_CAP);
                $out[3]++;
                $status = 1;
                $code = 1;
            } elseif ($kind === self::EVENT_SEARCH_SYNC_EXECUTION && $current === $release) {
                $executions = min($executions + 1, self::W55_SHORT_CAP);
                $out[4]++;
                $status = 1;
                $code = 2;
            } elseif ($kind >= 0 && $kind <= 2) {
                $out[5]++;
                $status = 0;
                $code = $kind;
            } else {
                $out[6]++;
                $status = 2;
                $code = 3;
            }
            $row = $status | $opened << 2 | $code << 3 | $statements << 5 | $requests << 25 | $executions << 41;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        if ($started && $scope !== null && $requests !== $executions) {
            $out[10]++;
        }
        $out[8] = $slow;
        $out[9] = $slowAt;
        $out[11] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust fold, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, int>  $kinds
     * @param  array<array-key, int>  $scopes
     * @param  array<array-key, int>  $releaseIds
     * @param  array<array-key, int>  $micros
     * @return list<int>|null
     */
    public static function foldScopedEventBatchNative(array $kinds, array $scopes, array $releaseIds, array $micros): ?array
    {
        $count = \count($kinds);
        if ($count > self::W55_MAX_ITEMS) {
            return null;
        }
        $ints = pack('q*', ...self::w55Fit($kinds, $count), ...self::w55Fit($scopes, $count), ...self::w55Fit($releaseIds, $count), ...self::w55Fit($micros, $count));
        $fields = self::W55_EVENT_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w55Call($fields, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w556_scoped_event_batch($ints, \strlen($ints), $count, $out, $fields));
    }

    /**
     * Run `$callback` with the Wave 55.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW55RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w55RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w55RustFfiState('set', $previous);
        }
    }

    public static function usesW55RustFfi(): bool
    {
        $forced = self::w55RustFfiState();

        return \is_bool($forced) ? $forced : self::W55_RUST_FFI_DEFAULT;
    }

    /**
     * Wave 55.6 routing and binding, kept in function statics so earlier waves' static state stays
     * untouched. No `$op` returns the forced routing (null for the default); `'set'` replaces it with
     * `$val` and returns the previous value; `'binding'` returns the cached `additional_w55` binding and
     * an output buffer of at least `$val` values, or null.
     */
    private static function w55RustFfiState(?string $op = null, mixed $val = null): mixed
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
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w55', self::W55_FFI_CDEF) ?? false;
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
     * The batch's `$fields` output words from one Wave 55.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w55Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w55RustFfiState('binding', $fields);
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
     * Exactly `$count` values: the list itself when it already fits, otherwise padded with 0.
     *
     * @param  array<array-key, int>  $values
     * @return list<int>
     */
    private static function w55Fit(array $values, int $count): array
    {
        return \count($values) === $count && array_is_list($values) ? $values : array_pad(\array_slice(array_values($values), 0, $count), $count, 0);
    }

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 7.52x, p99 5.93x; uncommon mean 5.59x, p99 4.17x); see RustAdditional7zParserBudgetPersistenceMetricsContextW65ParityTest. */
    public const bool W65_RUST_FFI_DEFAULT = true;

    public const string W65_PERSISTENCE_COLLECTOR_FFI_CDEF = <<<'C'
        ssize_t nntmux_w653_persistence_collector_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of the Wave 65.3 batch. */
    public const int W65_PERSISTENCE_COLLECTOR_TRAILER = 21;

    /** Cap for the Wave 65.3 running totals. */
    private const int W65_TOTAL_CAP = 4_611_686_018_427_387_904;

    public static function enableW65RustFfi(): void
    {
        self::w65RustFfiState('set', true);
    }

    public static function disableW65RustFfi(): void
    {
        self::w65RustFfiState('set', false);
    }

    /** Restores W65_RUST_FFI_DEFAULT and drops the cached library handle and scratch buffers. */
    public static function resetW65RustFfi(): void
    {
        self::w65RustFfiState('reset');
    }

    public static function usesW65RustFfi(): bool
    {
        $override = self::w65RustFfiState();

        return \is_bool($override) ? $override : self::W65_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the PersistenceMetricsCollector Wave 65 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW65RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w65RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w65RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 65.3 persistence event stream plan with applyEvent()'s scoping rules, folded in order across the batch. Each
     * item is `event|releaseId|micros`; the trimmed event is 0 empty, 1 `begin`, 2 `statement`, 3 `request`, 4
     * `execution`, 5 `finish` (any case) or 6 other, and the release ID and duration (microseconds) are 0 empty, 1 up to
     * 12 trimmed ASCII digits, 2 other. Begin needs a release ID, opens its scope and zeroes the counters (an already
     * open scope counts as abandoned); statement counts with its duration inside a scope; request and execution need a
     * release ID and count only for the scoped release; finish rolls the scope's counters into the totals and closes
     * it. The outcome is 0 applied, 1 outside any scope, 2 another release, 3 invalid (event 0 or 6, a state 2, or a
     * missing release ID), 4 scope opened or 5 scope finished. Per item `event | releaseState << 3 | microsState << 5 |
     * outcome << 7 | dirty << 10 | scopeOpen << 11 | min(statements, 2^20 - 1) << 12 | min(requests, 4095) << 32 |
     * min(executions, 4095) << 44`, with the counters after the event and dirty when any of them is positive. Then the
     * six outcome counts, the seven event counts, the finished and abandoned scope counts, the finished statement,
     * duration, request and execution totals (capped at 2^62), the open scope's release ID + 1 (0 when none) and its
     * dirty flag.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w65PersistenceCollectorPlanBatch(array $items): array
    {
        return self::usesW65RustFfi() ? self::w65PersistenceCollectorPlanBatchNative($items) : self::w65PersistenceCollectorPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w65PersistenceCollectorPlanBatchNative(array $items): array
    {
        return self::w65Invoke($items, \count($items) + self::W65_PERSISTENCE_COLLECTOR_TRAILER) ?? self::w65PersistenceCollectorPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w65PersistenceCollectorPlanBatchPhp(array $items): array
    {
        $out = [];
        $outcomes = [0, 0, 0, 0, 0, 0];
        $events = [0, 0, 0, 0, 0, 0, 0];
        $scope = null;
        $statements = $micros = $requests = $executions = 0;
        $finished = $abandoned = $totalStatements = $totalMicros = $totalRequests = $totalExecutions = 0;
        foreach ($items as $item) {
            [$rawEvent, $rawRelease, $rawMicros] = explode('|', (string) $item, 3) + ['', '', ''];
            $eventText = strtolower(trim($rawEvent));
            $event = $eventText === '' ? 0 : (self::W65_PERSISTENCE_EVENTS[$eventText] ?? 6);
            [$releaseState, $release] = self::w65Count($rawRelease, 12);
            [$microsState, $duration] = self::w65Count($rawMicros, 12);
            if ($event === 0 || $event === 6 || $releaseState === 2 || $microsState === 2) {
                $outcome = 3;
            } elseif ($event === 1) {
                if ($releaseState !== 1) {
                    $outcome = 3;
                } else {
                    $abandoned += $scope === null ? 0 : 1;
                    $scope = $release;
                    $statements = $micros = $requests = $executions = 0;
                    $outcome = 4;
                }
            } elseif ($event === 2) {
                if ($scope === null) {
                    $outcome = 1;
                } else {
                    $statements++;
                    $micros = min($micros + $duration, self::W65_TOTAL_CAP);
                    $outcome = 0;
                }
            } elseif ($event <= 4) {
                if ($releaseState !== 1) {
                    $outcome = 3;
                } elseif ($scope === null) {
                    $outcome = 1;
                } elseif ($scope !== $release) {
                    $outcome = 2;
                } else {
                    $event === 3 ? $requests++ : $executions++;
                    $outcome = 0;
                }
            } elseif ($scope === null) {
                $outcome = 1;
            } else {
                $finished++;
                $totalStatements = min($totalStatements + $statements, self::W65_TOTAL_CAP);
                $totalMicros = min($totalMicros + $micros, self::W65_TOTAL_CAP);
                $totalRequests = min($totalRequests + $requests, self::W65_TOTAL_CAP);
                $totalExecutions = min($totalExecutions + $executions, self::W65_TOTAL_CAP);
                $scope = null;
                $statements = $micros = $requests = $executions = 0;
                $outcome = 5;
            }
            $outcomes[$outcome]++;
            $events[$event]++;
            $dirty = $statements > 0 || $requests > 0 || $executions > 0;
            $out[] = $event | $releaseState << 3 | $microsState << 5 | $outcome << 7 | ($dirty ? 1 : 0) << 10 | ($scope === null ? 0 : 1) << 11
                | min($statements, 1_048_575) << 12 | min($requests, 4095) << 32 | min($executions, 4095) << 44;
        }
        array_push($out, ...$outcomes, ...$events);
        array_push($out, $finished, $abandoned, $totalStatements, $totalMicros, $totalRequests, $totalExecutions, $scope === null ? 0 : $scope + 1,
            $statements > 0 || $requests > 0 || $executions > 0 ? 1 : 0);

        return $out;
    }

    /**
     * (0 empty, 1 one to $max ASCII digits, 2 other; the value) for the trimmed field.
     *
     * @return array{int, int}
     */
    private static function w65Count(string $text, int $max): array
    {
        $text = trim($text);
        if ($text === '') {
            return [0, 0];
        }
        $length = \strlen($text);

        return $length <= $max && strspn($text, '0123456789') === $length ? [1, (int) $text] : [2, 0];
    }

    /** Lower-cased persistence event => code (empty 0, anything else 6). */
    private const array W65_PERSISTENCE_EVENTS = ['begin' => 1, 'statement' => 2, 'request' => 3, 'execution' => 4, 'finish' => 5];

    /**
     * Wave 65 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and returns the
     * previous override, 'reset' clears the override, library handle and buffers, 'ffi' resolves the library
     * (null when unavailable) and 'out' and 'lens' return `int64_t` output and length-column buffers of at least
     * $val words. Function statics keep the class's property set unchanged.
     */
    private static function w65RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'reset') {
            $override = $ffi = $out = $lens = null;
            $outCap = $lensCap = 0;

            return null;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w653', self::W65_PERSISTENCE_COLLECTOR_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w65RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w65RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 65.3 PersistenceMetricsCollector export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call declines
     * or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w65Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w65RustFfiState('ffi');
            $out = self::w65RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w65RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W65_PERSISTENCE_COLLECTOR_FFI_CDEF.)
            $written = $ffi->nntmux_w653_persistence_collector_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
