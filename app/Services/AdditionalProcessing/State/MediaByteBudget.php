<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\State;

use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

/**
 * Network bytes one release may still spend looking for preview sources.
 */
final class MediaByteBudget
{
    public const bool W45_RUST_FFI_DEFAULT = false;

    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_budget_eval(uint32_t op, int64_t limit, int64_t spent, int64_t bytes, int64_t *out);
        double nntmux_budget_utilization(int64_t limit, int64_t spent, int32_t *status);
        int32_t nntmux_w45_budget_eval(uint32_t op, int64_t limit, int64_t spent, int64_t bytes, int64_t *out);
        double nntmux_w45_budget_utilization(int64_t limit, int64_t spent, int32_t *status);
        C;

    private const int OP_ALLOWS = 0;

    private const int OP_SPEND = 1;

    private const int OP_REMAINING = 2;

    private const int OP_EXHAUSTED = 3;

    private const int OP_CLAMP = 4;

    // PHP remains the production default: FFI marshaling stays below the 1.5x gate.
    private static bool $useRustFfi = self::W45_RUST_FFI_DEFAULT;

    private static FFI|false|null $ffi = null;

    private int $spent = 0;

    public static function useW45RustFfi(?bool $enabled): void
    {
        self::$useRustFfi = $enabled ?? self::W45_RUST_FFI_DEFAULT;
    }

    public static function usesW45RustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public function __construct(public readonly int $limit) {}

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public function spent(): int
    {
        return $this->spent;
    }

    public function allows(int $bytes): bool
    {
        return (self::$useRustFfi ? $this->allowsNative($bytes) : null) ?? $this->allowsPhp($bytes);
    }

    public function allowsPhp(int $bytes): bool
    {
        return $this->spent + max(0, $bytes) <= $this->limit;
    }

    public function allowsNative(int $bytes): ?bool
    {
        $value = $this->evalNative(self::OP_ALLOWS, $bytes);

        return $value === null ? null : $value === 1;
    }

    public function spend(int $bytes): void
    {
        $this->spent = (self::$useRustFfi ? $this->spentAfterNative($bytes) : null) ?? $this->spentAfterPhp($bytes);
    }

    public function spentAfterPhp(int $bytes): int
    {
        $spent = $this->spent;
        $spent += max(0, $bytes);

        return $spent;
    }

    public function spentAfterNative(int $bytes): ?int
    {
        return $this->evalNative(self::OP_SPEND, $bytes);
    }

    public function remaining(): int
    {
        return (self::$useRustFfi ? $this->remainingNative() : null) ?? $this->remainingPhp();
    }

    public function remainingPhp(): int
    {
        return max(0, $this->limit - $this->spent);
    }

    public function remainingNative(): ?int
    {
        return $this->evalNative(self::OP_REMAINING, 0);
    }

    public function exhausted(): bool
    {
        return (self::$useRustFfi ? $this->exhaustedNative() : null) ?? $this->exhaustedPhp();
    }

    public function exhaustedPhp(): bool
    {
        return $this->spent >= $this->limit;
    }

    public function exhaustedNative(): ?bool
    {
        $value = $this->evalNative(self::OP_EXHAUSTED, 0);

        return $value === null ? null : $value === 1;
    }

    /**
     * Largest request that stays within the remaining budget.
     */
    public function clampRequestBytes(int $requested): int
    {
        return (self::$useRustFfi ? $this->clampRequestBytesNative($requested) : null) ?? $this->clampRequestBytesPhp($requested);
    }

    public function clampRequestBytesPhp(int $requested): int
    {
        return min($requested, $this->remainingPhp());
    }

    public function clampRequestBytesNative(int $requested): ?int
    {
        return $this->evalNative(self::OP_CLAMP, $requested);
    }

    /**
     * Share of the limit already spent, capped at 1.0; a non-positive limit counts as used up.
     */
    public function utilization(): float
    {
        return (self::$useRustFfi ? $this->utilizationNative() : null) ?? $this->utilizationPhp();
    }

    public function utilizationPhp(): float
    {
        if ($this->limit <= 0) {
            return 1.0;
        }
        $ratio = $this->spent / $this->limit;

        return $ratio >= 1 ? 1.0 : (float) $ratio;
    }

    public function utilizationNative(): ?float
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            $status = $ffi->new('int32_t');
            // @phpstan-ignore method.notFound
            $value = $ffi->nntmux_w45_budget_utilization($this->limit, $this->spent, FFI::addr($status));
            // @phpstan-ignore property.notFound
            $code = (int) $status->cdata;
        } catch (Throwable) {
            return null;
        }

        return $code === 1 ? (float) $value : null;
    }

    /**
     * Null when PHP must evaluate the operation (Rust unavailable or integer overflow).
     */
    private function evalNative(int $op, int $bytes): ?int
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            $out = $ffi->new('int64_t');
            // @phpstan-ignore method.notFound
            $code = $ffi->nntmux_w45_budget_eval($op, $this->limit, $this->spent, $bytes, FFI::addr($out));
            // @phpstan-ignore property.notFound
            $value = (int) $out->cdata;
        } catch (Throwable) {
            return null;
        }

        return $code === 1 ? $value : null;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }
}
