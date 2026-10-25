<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\TmuxCleanupRule;

/**
 * Enums cannot declare static properties, so the Rust toggle and FFI handle live in
 * {@see TmuxCleanupRule} keyed by this class.
 */
enum TmuxBackfillMode: int
{
    case Disabled = 0;
    case All = 1;
    case Safe = 4;

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w35_tmux_backfill_mode_normalize(
            int64_t raw_mode,
            int64_t group_count,
            char *out_buf,
            size_t out_cap
        );
        C;

    /** `TmuxTaskRunner::runBackfill()` artisan commands per enabled mode. */
    public const array COMMANDS = [1 => 'multiprocessing:backfill', 4 => 'multiprocessing:safe backfill'];

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustIngestionTmuxEnumsCleanupModelsParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

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
     * Resolves the tmux backfill pane dispatch: unknown modes disable backfill, `All` runs
     * 1-uint32 groups per pass and `Safe` backfills one group at a time.
     *
     * @return array{mode: self, is_valid: bool, enabled: bool, command: ?string, group_count: int}
     */
    public static function normalizeBackfillDispatch(int $rawMode, int $groupCount): array
    {
        return self::usesRustFfi()
            ? self::normalizeBackfillDispatchNative($rawMode, $groupCount)
            : self::normalizeBackfillDispatchPhp($rawMode, $groupCount);
    }

    /**
     * @return array{mode: self, is_valid: bool, enabled: bool, command: ?string, group_count: int}
     */
    public static function normalizeBackfillDispatchNative(int $rawMode, int $groupCount): array
    {
        $fields = TmuxCleanupRule::callCleaningNative(
            TmuxCleanupRule::enumFfi(self::class, self::FFI_CDEF),
            'nntmux_w35_tmux_backfill_mode_normalize',
            [$rawMode, $groupCount],
        );
        $mode = $fields !== null && \count($fields) === 5 && $fields[0] !== null && ctype_digit($fields[0]) ? self::tryFrom((int) $fields[0]) : null;
        if ($mode === null || ($fields[1] !== '0' && $fields[1] !== '1') || ($fields[2] !== '0' && $fields[2] !== '1')
            || $fields[3] !== (self::COMMANDS[$mode->value] ?? null)
            || $fields[4] === null || ! ctype_digit($fields[4])) {
            return self::normalizeBackfillDispatchPhp($rawMode, $groupCount);
        }

        return [
            'mode' => $mode,
            'is_valid' => $fields[1] === '1',
            'enabled' => $fields[2] === '1',
            'command' => $fields[3],
            'group_count' => (int) $fields[4],
        ];
    }

    /**
     * @return array{mode: self, is_valid: bool, enabled: bool, command: ?string, group_count: int}
     */
    public static function normalizeBackfillDispatchPhp(int $rawMode, int $groupCount): array
    {
        $mode = self::tryFrom($rawMode);
        $resolved = $mode ?? self::Disabled;

        return [
            'mode' => $resolved,
            'is_valid' => $mode !== null,
            'enabled' => $resolved !== self::Disabled,
            'command' => self::COMMANDS[$resolved->value] ?? null,
            'group_count' => match ($resolved) {
                self::All => max(1, min($groupCount, self::UINT32_MAX)),
                self::Safe => 1,
                self::Disabled => 0,
            },
        ];
    }
}
