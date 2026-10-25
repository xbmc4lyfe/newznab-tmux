<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\TmuxCleanupRule;

/**
 * Enums cannot declare static properties, so the Rust toggle and FFI handle live in
 * {@see TmuxCleanupRule} keyed by this class.
 */
enum GroupScanMode: int
{
    case Posts = 0;
    case Days = 1;

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w35_group_scan_mode_normalize(
            int64_t raw_mode,
            int64_t days_to_scan,
            int64_t messages_to_scan,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustIngestionTmuxEnumsCleanupModelsParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    /** Mirrors `IngestionConfiguration::SCAN_DAYS_MAX`. */
    private const int SCAN_DAYS_MAX = 36500;

    private const int UINT32_MAX = 4294967295;

    public static function usesRustFfi(): bool
    {
        return TmuxCleanupRule::enumRustFfiToggle(self::class) ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        TmuxCleanupRule::setEnumRustFfiToggle(self::class, $use);
    }

    /**
     * Resolves a new-group scan: days mode scans 1-36500 days, any other value scans by post
     * count clamped to `uint32`.
     *
     * @return array{mode: self, is_valid: bool, unit: string, target: int}
     */
    public static function normalizeScanSelection(int $rawMode, int $daysToScan, int $messagesToScan): array
    {
        return self::usesRustFfi()
            ? self::normalizeScanSelectionNative($rawMode, $daysToScan, $messagesToScan)
            : self::normalizeScanSelectionPhp($rawMode, $daysToScan, $messagesToScan);
    }

    /**
     * @return array{mode: self, is_valid: bool, unit: string, target: int}
     */
    public static function normalizeScanSelectionNative(int $rawMode, int $daysToScan, int $messagesToScan): array
    {
        $fields = TmuxCleanupRule::callCleaningNative(
            TmuxCleanupRule::enumFfi(self::class, self::FFI_CDEF),
            'nntmux_w35_group_scan_mode_normalize',
            [$rawMode, $daysToScan, $messagesToScan],
        );
        $mode = $fields !== null && \count($fields) === 4 && $fields[0] !== null && ctype_digit($fields[0]) ? self::tryFrom((int) $fields[0]) : null;
        if ($mode === null || ($fields[1] !== '0' && $fields[1] !== '1') || $fields[2] !== strtolower($mode->name)
            || $fields[3] === null || ! ctype_digit($fields[3])) {
            return self::normalizeScanSelectionPhp($rawMode, $daysToScan, $messagesToScan);
        }

        return [
            'mode' => $mode,
            'is_valid' => $fields[1] === '1',
            'unit' => $fields[2],
            'target' => (int) $fields[3],
        ];
    }

    /**
     * @return array{mode: self, is_valid: bool, unit: string, target: int}
     */
    public static function normalizeScanSelectionPhp(int $rawMode, int $daysToScan, int $messagesToScan): array
    {
        $mode = $rawMode === self::Days->value ? self::Days : self::Posts;

        return [
            'mode' => $mode,
            'is_valid' => self::tryFrom($rawMode) !== null,
            'unit' => strtolower($mode->name),
            'target' => $mode === self::Days
                ? max(1, min($daysToScan, self::SCAN_DAYS_MAX))
                : max(0, min($messagesToScan, self::UINT32_MAX)),
        ];
    }
}
