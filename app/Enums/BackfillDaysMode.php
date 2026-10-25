<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\TmuxCleanupRule;

/**
 * Enums cannot declare static properties, so the Rust toggle and FFI handle live in
 * {@see TmuxCleanupRule} keyed by this class.
 */
enum BackfillDaysMode: int
{
    case DaysPerGroup = 1;
    case SafeDate = 2;

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w35_backfill_days_mode_normalize(
            int64_t raw_mode,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustIngestionTmuxEnumsCleanupModelsParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    public static function usesRustFfi(): bool
    {
        return TmuxCleanupRule::enumRustFfiToggle(self::class) ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        TmuxCleanupRule::setEnumRustFfiToggle(self::class, $use);
    }

    /**
     * Resolves a stored backfill days mode; unknown values fall back to days per group.
     *
     * @return array{mode: self, is_valid: bool, uses_group_target: bool, uses_safe_date: bool}
     */
    public static function normalizeModeSelection(int $rawMode): array
    {
        return self::usesRustFfi() ? self::normalizeModeSelectionNative($rawMode) : self::normalizeModeSelectionPhp($rawMode);
    }

    /**
     * @return array{mode: self, is_valid: bool, uses_group_target: bool, uses_safe_date: bool}
     */
    public static function normalizeModeSelectionNative(int $rawMode): array
    {
        $fields = TmuxCleanupRule::callCleaningNative(
            TmuxCleanupRule::enumFfi(self::class, self::FFI_CDEF),
            'nntmux_w35_backfill_days_mode_normalize',
            [$rawMode],
        );
        $mode = $fields !== null && \count($fields) === 4 && $fields[0] !== null && ctype_digit($fields[0]) ? self::tryFrom((int) $fields[0]) : null;
        if ($mode === null || ! self::isFlag($fields[1]) || ! self::isFlag($fields[2]) || ! self::isFlag($fields[3])) {
            return self::normalizeModeSelectionPhp($rawMode);
        }

        return [
            'mode' => $mode,
            'is_valid' => $fields[1] === '1',
            'uses_group_target' => $fields[2] === '1',
            'uses_safe_date' => $fields[3] === '1',
        ];
    }

    /**
     * @return array{mode: self, is_valid: bool, uses_group_target: bool, uses_safe_date: bool}
     */
    public static function normalizeModeSelectionPhp(int $rawMode): array
    {
        $mode = self::tryFrom($rawMode);
        $resolved = $mode ?? self::DaysPerGroup;

        return [
            'mode' => $resolved,
            'is_valid' => $mode !== null,
            'uses_group_target' => $resolved === self::DaysPerGroup,
            'uses_safe_date' => $resolved === self::SafeDate,
        ];
    }

    private static function isFlag(?string $field): bool
    {
        return $field === '0' || $field === '1';
    }
}
