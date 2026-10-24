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
}
