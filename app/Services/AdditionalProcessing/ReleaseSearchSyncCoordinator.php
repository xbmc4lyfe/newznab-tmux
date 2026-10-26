<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use App\Facades\Search;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use FFI\CData;
use InvalidArgumentException;
use Throwable;

final class ReleaseSearchSyncCoordinator
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_rssc_decide(int32_t op, int32_t coalesce, int32_t has_scope, int64_t scope_id, int32_t pending, int64_t arg_id, int64_t *out, size_t out_count);
        C;

    public const int OP_BEGIN = 0;

    public const int OP_REQUEST = 1;

    public const int OP_DISCARD = 2;

    public const int OP_FINISH = 3;

    // PHP remains the production default until benchmarks qualify Rust.
    public static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private static ?CData $decision = null;

    private ?int $releaseId = null;

    private bool $pending = false;

    /** @var Closure(int): void */
    private readonly Closure $synchronize;

    /**
     * @param  (Closure(int): void)|null  $synchronize
     */
    public function __construct(
        private readonly PersistenceMetricsCollector $metrics,
        ?Closure $synchronize = null,
        private readonly bool $coalesce = true,
    ) {
        $this->synchronize = $synchronize ?? static function (int $releaseId): void {
            Search::updateRelease($releaseId);
        };
    }

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
        $this->apply(self::OP_BEGIN, $releaseId);
    }

    public function request(int $releaseId): void
    {
        $this->metrics->recordSearchSyncRequest($releaseId);

        $this->apply(self::OP_REQUEST, $releaseId);
    }

    public function discard(int $releaseId): void
    {
        $this->apply(self::OP_DISCARD, $releaseId);
    }

    public function finishReleaseScope(): void
    {
        $this->apply(self::OP_FINISH, 0);
    }

    /**
     * Next coalescing state for one operation: the scoped release, whether a sync is
     * pending for it, and the release to synchronize now (if any).
     *
     * @return array{0: ?int, 1: bool, 2: ?int}
     */
    public static function decide(int $op, bool $coalesce, ?int $scope, bool $pending, int $releaseId): array
    {
        return self::$useRustFfi
            ? self::decideNative($op, $coalesce, $scope, $pending, $releaseId)
            : self::decidePhp($op, $coalesce, $scope, $pending, $releaseId);
    }

    /**
     * @return array{0: ?int, 1: bool, 2: ?int}
     */
    public static function decidePhp(int $op, bool $coalesce, ?int $scope, bool $pending, int $releaseId): array
    {
        return match ($op) {
            self::OP_BEGIN => $coalesce ? [$releaseId, false, null] : [$scope, $pending, null],
            self::OP_REQUEST => $coalesce && $scope === $releaseId
                ? [$scope, true, null]
                : [$scope, $pending, $releaseId],
            self::OP_DISCARD => $coalesce && $scope === $releaseId
                ? [$scope, false, null]
                : [$scope, $pending, null],
            self::OP_FINISH => $coalesce
                ? [null, false, $scope !== null && $pending ? $scope : null]
                : [$scope, $pending, null],
            default => throw new InvalidArgumentException("Unknown search sync operation {$op}."),
        };
    }

    /**
     * @return array{0: ?int, 1: bool, 2: ?int}
     */
    public static function decideNative(int $op, bool $coalesce, ?int $scope, bool $pending, int $releaseId): array
    {
        $ffi = self::additionalFfi();
        if ($ffi === null) {
            return self::decidePhp($op, $coalesce, $scope, $pending, $releaseId);
        }

        try {
            $out = self::$decision ??= $ffi->new('int64_t[5]');
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_rssc_decide(
                $op,
                $coalesce ? 1 : 0,
                $scope === null ? 0 : 1,
                $scope ?? 0,
                $pending ? 1 : 0,
                $releaseId,
                $out,
                5,
            );
            if ($status !== 0) {
                return self::decidePhp($op, $coalesce, $scope, $pending, $releaseId);
            }

            return [$out[0] === 1 ? $out[1] : null, $out[2] === 1, $out[3] === 1 ? $out[4] : null];
        } catch (Throwable) {
            return self::decidePhp($op, $coalesce, $scope, $pending, $releaseId);
        }
    }

    private function apply(int $op, int $releaseId): void
    {
        [$this->releaseId, $this->pending, $execute] = self::decide(
            $op,
            $this->coalesce,
            $this->releaseId,
            $this->pending,
            $releaseId,
        );

        if ($execute !== null) {
            $this->execute($execute);
        }
    }

    private function execute(int $releaseId): void
    {
        $this->metrics->recordSearchSyncExecution($releaseId);
        ($this->synchronize)($releaseId);
    }

    // Wave 42.6 is independent of every earlier FFI toggle.
    public const bool W426_RUST_FFI_DEFAULT = false;

    private const string W426_FFI_CDEF = 'int32_t nntmux_w426_additional_file_manager_fallback_sync_sevenzip(uint8_t operation, const char *input, size_t input_len, char *output, size_t output_cap);';

    /** @return array{enabled: ?bool, ffi: FFI|false|null} */
    private static function &w426State(): array
    {
        // A static local also supports readonly configuration objects.
        static $state = ['enabled' => null, 'ffi' => null];

        return $state;
    }

    public static function useW426RustFfi(bool $enable = true): void
    {
        $state = &self::w426State();
        $state['enabled'] = $enable;
    }

    public static function usesW426RustFfi(): bool
    {
        return self::w426State()['enabled'] ?? self::W426_RUST_FFI_DEFAULT;
    }

    public static function resetW426RustFfi(): void
    {
        $state = &self::w426State();
        $state = ['enabled' => null, 'ffi' => null];
    }

    /** @return list<int>|null */
    private static function w426Slots(int $operation, string $input, int $count): ?array
    {
        if (PHP_INT_SIZE !== 8 || \strlen($input) > 16_777_216) {
            return null;
        }
        try {
            $state = &self::w426State();
            $state['ffi'] ??= RustPipelineLibrary::resolveDomain('additional', self::W426_FFI_CDEF) ?? false;
            if ($state['ffi'] === false) {
                return null;
            }
            $bytes = $count * 8;
            $buffer = $state['ffi']->new('char['.$bytes.']');
            // @phpstan-ignore method.notFound
            $written = $state['ffi']->nntmux_w426_additional_file_manager_fallback_sync_sevenzip($operation, $input, \strlen($input), $buffer, $bytes);
            if ($written !== $bytes) {
                return null;
            }
            $values = unpack('q*', FFI::string($buffer, $bytes));
            if ($values === false || \count($values) !== $count) {
                return null;
            }
            $values = array_values($values);
            foreach ($values as $index => $value) {
                if (! \is_int($value) || ($value < 0 && ! ($operation === 3 && $index === 7))) {
                    return null;
                }
            }

            return $values;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Simulate a fresh scope over ordered events, without synchronizing search or recording metrics.
     * Malformed rows are counted and skipped; release IDs retain their signed integer identity.
     *
     * @param  array<array-key, mixed>  $events
     * @return array{total: int, requests: int, executions: int, deferred_requests: int, discarded_pending: int, pending: bool, scoped_release: ?int, invalid: int}
     */
    public static function summarizeSearchSyncBatch(array $events, bool $coalesce = true): array
    {
        return self::usesW426RustFfi()
            ? self::summarizeSearchSyncBatchNative($events, $coalesce)
            : self::summarizeSearchSyncBatchPhp($events, $coalesce);
    }

    /**
     * @param  array<array-key, mixed>  $events
     * @return array{total: int, requests: int, executions: int, deferred_requests: int, discarded_pending: int, pending: bool, scoped_release: ?int, invalid: int}
     */
    public static function summarizeSearchSyncBatchPhp(array $events, bool $coalesce = true): array
    {
        $result = ['total' => \count($events), 'requests' => 0, 'executions' => 0, 'deferred_requests' => 0, 'discarded_pending' => 0, 'pending' => false, 'scoped_release' => null, 'invalid' => 0];
        $scope = null;
        $pending = false;
        foreach ($events as $row) {
            if (! self::w426ValidRow($row)) {
                $result['invalid']++;

                continue;
            }
            if ($row['op'] === self::OP_REQUEST) {
                $result['requests']++;
                $result['deferred_requests'] += (int) ($coalesce && $scope === $row['release_id']);
            } elseif ($row['op'] === self::OP_DISCARD && $coalesce && $scope === $row['release_id'] && $pending) {
                $result['discarded_pending']++;
            }
            [$scope, $pending, $execute] = self::decidePhp($row['op'], $coalesce, $scope, $pending, $row['release_id']);
            $result['executions'] += (int) ($execute !== null);
        }
        $result['pending'] = $pending;
        $result['scoped_release'] = $scope;

        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $events
     * @return array{total: int, requests: int, executions: int, deferred_requests: int, discarded_pending: int, pending: bool, scoped_release: ?int, invalid: int}
     */
    public static function summarizeSearchSyncBatchNative(array $events, bool $coalesce = true): array
    {
        $total = \count($events);
        if (PHP_INT_SIZE !== 8 || $total > 100_000) {
            return self::summarizeSearchSyncBatchPhp($events, $coalesce);
        }
        $frame = pack('qV', (int) $coalesce, $total);
        foreach ($events as $row) {
            if (! self::w426ValidRow($row)) {
                return self::summarizeSearchSyncBatchPhp($events, $coalesce);
            }
            $frame .= pack('qq', $row['op'], $row['release_id']);
        }
        $slots = self::w426Slots(3, $frame, 10);
        if ($slots === null || $slots[0] !== $total || $slots[1] > $total || $slots[2] > $total || $slots[3] > $slots[1] || $slots[4] > $total || $slots[5] > 1 || $slots[6] > 1 || ($slots[6] === 0 && ($slots[5] !== 0 || $slots[7] !== 0)) || $slots[8] !== 0 || $slots[9] !== 0) {
            return self::summarizeSearchSyncBatchPhp($events, $coalesce);
        }

        return ['total' => $slots[0], 'requests' => $slots[1], 'executions' => $slots[2], 'deferred_requests' => $slots[3], 'discarded_pending' => $slots[4], 'pending' => $slots[5] === 1, 'scoped_release' => $slots[6] === 1 ? $slots[7] : null, 'invalid' => $slots[8]];
    }

    /** @phpstan-assert-if-true array{op: int, release_id: int} $row */
    private static function w426ValidRow(mixed $row): bool
    {
        return \is_array($row) && \is_int($row['op'] ?? null) && $row['op'] >= self::OP_BEGIN && $row['op'] <= self::OP_FINISH && \is_int($row['release_id'] ?? null);
    }

    /** Enabled: three paired runs cleared every 1.5x gate (common mean 3.19-3.51x, p99 2.67-10.23x; uncommon mean 3.36-3.49x, p99 2.70-3.00x); see RustReleaseFileArchiveProcessorSyncUsenetW52ParityTest. */
    private const bool W52_RUST_FFI_DEFAULT = true;

    public const string W52_FFI_CDEF = <<<'C'
        ssize_t nntmux_w523_search_sync_decide_batch(const char *blob, size_t blob_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    private static ?bool $useW52RustFfi = null;

    private static FFI|false|null $w52Ffi = null;

    private static ?CData $w52Out = null;

    private static int $w52OutCap = 0;

    public static function usesW52RustFfi(): bool
    {
        return self::$useW52RustFfi ?? self::W52_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 52 Rust path forced on or off, then restores the previous setting. */
    public static function withW52RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$useW52RustFfi;
        self::$useW52RustFfi = $enabled;
        try {
            return $callback();
        } finally {
            self::$useW52RustFfi = $previous;
        }
    }

    /**
     * Folds decidePhp() over a sequence of `(op, releaseId)` events from the given scope state. One
     * `[flags, sync]` pair per event, where flags are 1 pending, 2 scope set and 4 a release to
     * synchronize (sync is that release, else 0), then the final `[hasScope, scope, pending]`.
     *
     * @param  array<int, int>  $ops
     * @param  array<int, int>  $releaseIds
     * @return list<int>
     *
     * @throws InvalidArgumentException for an unknown operation or mismatched columns
     */
    public static function w52DecideSequenceBatch(array $ops, array $releaseIds, bool $coalesce = true, ?int $scope = null, bool $pending = false): array
    {
        return self::usesW52RustFfi()
            ? self::w52DecideSequenceBatchNative($ops, $releaseIds, $coalesce, $scope, $pending)
            : self::w52DecideSequenceBatchPhp($ops, $releaseIds, $coalesce, $scope, $pending);
    }

    /**
     * @param  array<int, int>  $ops
     * @param  array<int, int>  $releaseIds
     * @return list<int>
     */
    public static function w52DecideSequenceBatchNative(array $ops, array $releaseIds, bool $coalesce = true, ?int $scope = null, bool $pending = false): array
    {
        $count = \count($ops);
        if (\count($releaseIds) !== $count) {
            return self::w52DecideSequenceBatchPhp($ops, $releaseIds, $coalesce, $scope, $pending);
        }
        if (! array_is_list($ops) || ! array_is_list($releaseIds)) {
            $ops = array_values($ops);
            $releaseIds = array_values($releaseIds);
        }
        $ints = pack('q*', (int) $coalesce, (int) ($scope !== null), $scope ?? 0, (int) $pending, ...$ops, ...$releaseIds);

        return self::w52Invoke('', $ints, $count, 2 * $count + 3)
            ?? self::w52DecideSequenceBatchPhp($ops, $releaseIds, $coalesce, $scope, $pending);
    }

    /**
     * @param  array<int, int>  $ops
     * @param  array<int, int>  $releaseIds
     * @return list<int>
     */
    public static function w52DecideSequenceBatchPhp(array $ops, array $releaseIds, bool $coalesce = true, ?int $scope = null, bool $pending = false): array
    {
        if (\count($releaseIds) !== \count($ops)) {
            throw new InvalidArgumentException('Search sync operations and release ids must have the same length.');
        }
        $releaseIds = array_values($releaseIds);
        $out = [];
        foreach (array_values($ops) as $i => $op) {
            [$scope, $pending, $sync] = self::decidePhp((int) $op, $coalesce, $scope, $pending, (int) $releaseIds[$i]);
            $out[] = (int) $pending | ($scope !== null ? 2 : 0) | ($sync !== null ? 4 : 0);
            $out[] = $sync ?? 0;
        }
        $out[] = (int) ($scope !== null);
        $out[] = $scope ?? 0;
        $out[] = (int) $pending;

        return $out;
    }

    /**
     * Calls the Wave 52 export with NUL-joined strings and packed `i64` integers. Null when the
     * library is missing, the call declines (for example a string holding a NUL byte) or it writes
     * more than $cap words, so the caller falls back to PHP.
     *
     * @return list<int>|null
     */
    private static function w52Invoke(string $blob, string $ints, int $count, int $cap): ?array
    {
        self::$w52Ffi ??= RustPipelineLibrary::resolveDomain('categorize_w52', self::W52_FFI_CDEF) ?? false;
        $ffi = self::$w52Ffi;
        if ($ffi === false) {
            return null;
        }
        try {
            if (self::$w52Out === null || self::$w52OutCap < $cap) {
                self::$w52OutCap = max(1024, $cap * 2);
                self::$w52Out = $ffi->new('int64_t['.self::$w52OutCap.']');
            }
            // @phpstan-ignore method.notFound (Declared by W52_FFI_CDEF.)
            $written = $ffi->nntmux_w523_search_sync_decide_batch($blob, \strlen($blob), $ints, \strlen($ints), $count, self::$w52Out, self::$w52OutCap);
            if (! \is_int($written) || $written <= 0 || $written > $cap) {
                return null;
            }
            $values = unpack('P*', FFI::string(self::$w52Out, $written * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    // ---- Wave 65.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 8.63x/6.74x, uncommon 8.28x/4.92x); see RustAdditionalNzbParserFileManagerArchiveFallbackProcessorSyncW65ParityTest. */
    public const bool W65_RUST_FFI_DEFAULT = true;

    private const string W65_SEARCH_SYNC_COORDINATOR_FFI_CDEF = <<<'C'
        intptr_t nntmux_w652_search_sync_plan_batch(const char *items, size_t items_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Summary words after the per-row words, the digest included. */
    public const int W65_SEARCH_SYNC_COORDINATOR_TAIL = 35;

    /**
     * Wave 65 routing, binding and output buffer, kept in function-local statics (no static or
     * instance properties). Operations: null reads the routing override, 'set' replaces it
     * (returning the previous one), 'reset' also drops the binding and buffer, 'ffi' resolves the
     * binding and 'out' returns `[binding, buffer, capacity]` with room for at least $val words
     * (null without the library).
     */
    private static function w65RustFfiState(?string $op = null, mixed $val = null): mixed
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
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w65', self::W65_SEARCH_SYNC_COORDINATOR_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w65RustFfiState('ffi');
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

    /** Routes w65SearchSyncCoordinatorPlanBatch() to Rust until reset. */
    public static function enableW65RustFfi(): void
    {
        self::w65RustFfiState('set', true);
    }

    /** Routes w65SearchSyncCoordinatorPlanBatch() to PHP until reset. */
    public static function disableW65RustFfi(): void
    {
        self::w65RustFfiState('set', false);
    }

    /** Restores W65_RUST_FFI_DEFAULT and drops the cached binding and buffer; earlier toggles are untouched. */
    public static function resetW65RustFfi(): void
    {
        self::w65RustFfiState('reset');
    }

    public static function usesW65RustFfi(): bool
    {
        $enabled = self::w65RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W65_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with w65SearchSyncCoordinatorPlanBatch() routed to Rust ($enabled) or PHP, then restores the
     * previous routing.
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
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w65SearchSyncCoordinatorPlanBatch(array $rows): array
    {
        return self::usesW65RustFfi() ? self::w65SearchSyncCoordinatorPlanBatchNative($rows) : self::w65SearchSyncCoordinatorPlanBatchPhp($rows);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a row holds a NUL byte or the
     * column is not a list.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w65SearchSyncCoordinatorPlanBatchNative(array $rows): array
    {
        $count = \count($rows);
        $words = $count + self::W65_SEARCH_SYNC_COORDINATOR_TAIL;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w65RustFfiState('out', $words);
        if ($state === null || ! array_is_list($rows)) {
            return self::w65SearchSyncCoordinatorPlanBatchPhp($rows);
        }
        $blob = implode("\0", $rows);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W65_SEARCH_SYNC_COORDINATOR_FFI_CDEF.)
            $written = $ffi->nntmux_w652_search_sync_plan_batch($blob, \strlen($blob), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : self::w65SearchSyncCoordinatorPlanBatchPhp($rows);
    }

    /** @param  list<int>  $out */
    private static function w65Digest(array $out): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));

        return $digest[1];
    }

    /** A trimmed field: null when empty, its value for 1 to $max ASCII digits, otherwise false. */
    private static function w65Int(string $value, int $max): int|false|null
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $length = \strlen($value);

        return $length <= $max && strspn($value, '0123456789') === $length ? (int) $value : false;
    }

    /**
     * Known-token bit mask and unknown-token count of a comma list (trimmed, non-empty tokens
     * looked up case-insensitively in $names, token => bit).
     *
     * @param  array<string, int>  $names
     * @return array{int, int}
     */
    private static function w65Tokens(string $list, array $names): array
    {
        $mask = $unknown = 0;
        foreach (explode(',', $list) as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            $bit = $names[strtolower($token)] ?? null;
            if ($bit === null) {
                $unknown++;
            } else {
                $mask |= 1 << $bit;
            }
        }

        return [$mask, $unknown];
    }

    /** Operation token => code (4 unknown). */
    private const array W65_SYNC_OPS = ['update' => 0, 'insert' => 1, 'delete' => 2, 'flush' => 3];

    /** Search document field => dirty bit. */
    private const array W65_SYNC_FIELDS = ['name' => 0, 'searchname' => 1, 'fromname' => 2, 'categories_id' => 3, 'filename' => 4, 'size' => 5, 'postdate' => 6,
        'adddate' => 7, 'nfo' => 8, 'imdbid' => 9, 'tvdb' => 10, 'haspreview' => 11, 'passwordstatus' => 12];

    private const int W65_SYNC_ALL_FIELDS = 8191;

    /** Pending releases or payload bytes that flush a dispatch batch. */
    private const int W65_SYNC_FLUSH_RELEASES = 100;

    private const int W65_SYNC_FLUSH_BYTES = 1_048_576;

    /**
     * Wave 65.2 search sync plan. Per `release_id\top\tfields\tbytes` row `action | op << 3 |
     * dirty_mask << 6 | pending_mask << 19 | flushed << 32 | min(batches, 65535) << 33 |
     * min(pending, 1023) << 49 | min(unknown_fields, 15) << 59`. Ops (trimmed, case-insensitive):
     * 0 update, 1 insert (every field dirty), 2 delete, 3 flush, 4 unknown; fields are a w65Tokens()
     * list of W65_SYNC_FIELDS; release id and bytes are w65Int() fields of 12 and 9 digits. An
     * unknown op, unparsable bytes or (except for a flush) no positive release id is malformed
     * (action 0). Otherwise: flush 6; delete 2 when it cancels a pending release (dropping its
     * bytes), else 3; an update or insert of a pending release 4 when it adds dirty fields, else 5
     * (redundant); a new one 1 (queued), or 7 (no-op) without a dirty field. The pending mask is the
     * release's merged mask after a queue, coalesce or redundant row. A flush, W65_SYNC_FLUSH_RELEASES
     * pending releases or W65_SYNC_FLUSH_BYTES pending bytes dispatch the pending releases as one
     * batch (when any). Then eight action, five op and 13 field counts (valid updates and inserts),
     * batches, dispatched releases, releases and bytes still pending, the largest batch, malformed
     * rows, distinct releases, total valid bytes and the XXH64 digest of all earlier words.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w65SearchSyncCoordinatorPlanBatchPhp(array $rows): array
    {
        $out = $pending = $ids = [];
        $actions = array_fill(0, 8, 0);
        $ops = array_fill(0, 5, 0);
        $fieldCounts = array_fill(0, 13, 0);
        $batches = $flushedReleases = $pendingBytes = $maxBatch = $malformedRows = $totalBytes = 0;
        foreach ($rows as $row) {
            [$rid, $op, $list, $bytes] = explode("\t", (string) $row, 4) + ['', '', '', ''];
            $rid = self::w65Int($rid, 12);
            $bytes = self::w65Int($bytes, 9);
            $op = self::W65_SYNC_OPS[strtolower(trim($op))] ?? 4;
            [$mask, $unknown] = self::w65Tokens($list, self::W65_SYNC_FIELDS);
            if ($op === 1) {
                $mask = self::W65_SYNC_ALL_FIELDS;
            }
            $id = \is_int($rid) && $rid > 0 ? $rid : 0;
            $malformed = $op === 4 || $bytes === false || ($op !== 3 && $id === 0);
            $size = \is_int($bytes) ? $bytes : 0;
            $merged = 0;
            if ($malformed) {
                $action = 0;
            } elseif ($op === 3) {
                $action = 6;
            } elseif ($op === 2) {
                if (isset($pending[$id])) {
                    $pendingBytes -= $pending[$id][1];
                    unset($pending[$id]);
                    $action = 2;
                } else {
                    $action = 3;
                }
            } elseif (isset($pending[$id])) {
                [$held, $heldBytes] = $pending[$id];
                $merged = $held | $mask;
                $action = $merged === $held ? 5 : 4;
                $pending[$id] = [$merged, $heldBytes + $size];
                $pendingBytes += $size;
            } elseif ($mask === 0) {
                $action = 7;
            } else {
                $pending[$id] = [$mask, $size];
                $pendingBytes += $size;
                $merged = $mask;
                $action = 1;
            }
            if (! $malformed) {
                $totalBytes += $size;
                if ($op !== 3) {
                    $ids[$id] = true;
                }
                if ($op <= 1) {
                    for ($k = 0; $k < 13; $k++) {
                        $fieldCounts[$k] += $mask >> $k & 1;
                    }
                }
            }
            $flushed = ! $malformed && ($op === 3 || \count($pending) >= self::W65_SYNC_FLUSH_RELEASES || $pendingBytes >= self::W65_SYNC_FLUSH_BYTES) && $pending !== [];
            if ($flushed) {
                $batches++;
                $flushedReleases += \count($pending);
                $maxBatch = max($maxBatch, \count($pending));
                $pending = [];
                $pendingBytes = 0;
            }
            $actions[$action]++;
            $ops[$op]++;
            $malformedRows += (int) $malformed;
            $out[] = $action | $op << 3 | $mask << 6 | $merged << 19 | (int) $flushed << 32 | min($batches, 0xFFFF) << 33 | min(\count($pending), 0x3FF) << 49
                | min($unknown, 15) << 59;
        }
        array_push($out, ...$actions, ...$ops, ...$fieldCounts);
        array_push($out, $batches, $flushedReleases, \count($pending), $pendingBytes, $maxBatch, $malformedRows, \count($ids), $totalBytes);
        $out[] = self::w65Digest($out);

        return $out;
    }
}
