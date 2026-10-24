<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\RolePromotion;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Carbon;
use Throwable;

class RolePromotionObserver
{
    private const FFI_CDEF = <<<'CDEF'
int32_t nntmux_role_promotion_observer_evaluate(
    uint8_t is_active,
    uint8_t has_end_date,
    int64_t end_timestamp_us,
    int64_t now_timestamp_us,
    int32_t *out_flags
);
CDEF;

    /**
     * Production default stays PHP until paired benchmarks show >= 1.5x Rust speedup.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = false;
        self::$ffi = null;
    }

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    /**
     * Handle the RolePromotion "saving" event.
     * Automatically disable promotion if end date has passed
     */
    public function saving(RolePromotion $promotion): void
    {
        $endTimestampUs = $promotion->end_date ? (int) Carbon::parse($promotion->end_date)->getPreciseTimestamp(6) : null;
        $nowTimestampUs = (int) Carbon::now()->getPreciseTimestamp(6);
        $decision = self::evaluatePromotionExpiry((bool) $promotion->is_active, $endTimestampUs, $nowTimestampUs);

        // If the promotion has an end date that has passed, automatically disable it
        if ($decision['should_disable_on_saving']) {
            $promotion->is_active = false;
        }
    }

    /**
     * Handle the RolePromotion "retrieved" event.
     * This helps ensure that when a promotion is loaded from the database,
     * we check if it should be disabled
     */
    public function retrieved(RolePromotion $promotion): void
    {
        $endTimestampUs = $promotion->end_date ? (int) Carbon::parse($promotion->end_date)->getPreciseTimestamp(6) : null;
        $nowTimestampUs = (int) Carbon::now()->getPreciseTimestamp(6);
        $decision = self::evaluatePromotionExpiry((bool) $promotion->is_active, $endTimestampUs, $nowTimestampUs);

        // If the promotion has an end date that has passed but is still marked as active,
        // we update it (this will trigger saving event)
        if ($decision['should_persist_quietly_on_retrieved']) {
            $promotion->is_active = false;
            $promotion->saveQuietly(); // Save without triggering events to avoid recursion
        }
    }

    /**
     * @return array{
     *     is_expired: bool,
     *     next_is_active: bool,
     *     should_disable_on_saving: bool,
     *     should_persist_quietly_on_retrieved: bool
     * }
     */
    public static function evaluatePromotionExpiry(
        bool $isActive,
        ?int $endTimestampUs,
        int $nowTimestampUs
    ): array {
        if (self::$useRustFfi) {
            return self::evaluatePromotionExpiryNative($isActive, $endTimestampUs, $nowTimestampUs);
        }

        return self::evaluatePromotionExpiryPhp($isActive, $endTimestampUs, $nowTimestampUs);
    }

    /**
     * @return array{
     *     is_expired: bool,
     *     next_is_active: bool,
     *     should_disable_on_saving: bool,
     *     should_persist_quietly_on_retrieved: bool
     * }
     */
    public static function evaluatePromotionExpiryPhp(
        bool $isActive,
        ?int $endTimestampUs,
        int $nowTimestampUs
    ): array {
        $isExpired = $endTimestampUs !== null && $nowTimestampUs > $endTimestampUs;

        return [
            'is_expired' => $isExpired,
            'next_is_active' => $isExpired ? false : $isActive,
            'should_disable_on_saving' => $isExpired,
            'should_persist_quietly_on_retrieved' => $isActive && $isExpired,
        ];
    }

    /**
     * @return array{
     *     is_expired: bool,
     *     next_is_active: bool,
     *     should_disable_on_saving: bool,
     *     should_persist_quietly_on_retrieved: bool
     * }
     */
    public static function evaluatePromotionExpiryNative(
        bool $isActive,
        ?int $endTimestampUs,
        int $nowTimestampUs
    ): array {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::evaluatePromotionExpiryPhp($isActive, $endTimestampUs, $nowTimestampUs);
        }

        try {
            $outFlags = $ffi->new('int32_t[1]');
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_role_promotion_observer_evaluate(
                $isActive ? 1 : 0,
                $endTimestampUs !== null ? 1 : 0,
                $endTimestampUs ?? 0,
                $nowTimestampUs,
                $outFlags
            );
            $mask = $outFlags[0];
            if ($rc !== 1 || ! is_int($mask)) {
                return self::evaluatePromotionExpiryPhp($isActive, $endTimestampUs, $nowTimestampUs);
            }

            return [
                'is_expired' => ($mask & 1) !== 0,
                'next_is_active' => ($mask & 2) !== 0,
                'should_disable_on_saving' => ($mask & 4) !== 0,
                'should_persist_quietly_on_retrieved' => ($mask & 8) !== 0,
            ];
        } catch (Throwable) {
            return self::evaluatePromotionExpiryPhp($isActive, $endTimestampUs, $nowTimestampUs);
        }
    }

    private static function additionalFfi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
