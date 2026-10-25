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
}
