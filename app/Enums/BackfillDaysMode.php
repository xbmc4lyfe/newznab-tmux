<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\TmuxCleanupRule;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;

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

    /**
     * Enabled: the paired benchmark in the W59 parity test measures 6.036x common mean, 2.429x
     * common p99, 4.692x uncommon mean and 3.159x uncommon p99 (per-metric minimum of four runs),
     * above the 1.5x rule.
     */
    public const bool W59_RUST_FFI_DEFAULT = true;

    private const string W59_BACKFILL_DAYS_FFI_CDEF = 'ssize_t nntmux_w591_backfill_days_plan_batch(const char *rows, size_t rows_len, size_t count, int64_t *out, size_t out_cap);';

    public const int W59_BACKFILL_DAYS_WORDS = 4;

    public const int W59_SOURCE_SHIFT = 4;

    public const int W59_SOURCE_VALUE = 1;

    public const int W59_SOURCE_NAME = 2;

    public const int W59_SOURCE_ALIAS = 3;

    public const int W59_DAYS_VALID = 64;

    public const int W59_DAYS_GROUP_TARGET = 128;

    public const int W59_DAYS_SAFE_DATE = 256;

    public const int W59_DAYS_REACHED = 512;

    public const int W59_DAYS_CLAMPED = 1024;

    public const int W59_DAYS_MALFORMED = 2048;

    private const array W59_NAMES = ['dayspergroup' => 1, 'safedate' => 2];

    private const array W59_ALIASES = [
        'days' => 1, 'group' => 1, 'pergroup' => 1, 'groupdays' => 1,
        'safe' => 2, 'date' => 2, 'safebackfill' => 2, 'backfilldate' => 2,
    ];

    public static function usesW59RustFfi(): bool
    {
        $enabled = self::w59RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W59_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 59 batch routed to Rust or PHP, then restores the routing. */
    public static function withW59RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w59RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w59RustFfiState('set', $previous);
        }
    }

    /**
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function backfillDaysPlanBatch(array $rows): array
    {
        return self::usesW59RustFfi() ? self::backfillDaysPlanBatchNative($rows) : self::backfillDaysPlanBatchPhp($rows);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or Rust declines the batch (a
     * row holding a NUL byte).
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function backfillDaysPlanBatchNative(array $rows): array
    {
        $count = \count($rows);
        $words = $count * self::W59_BACKFILL_DAYS_WORDS;
        /** @var array{0: FFI, 1: CData, 2: int}|null $buffer */
        $buffer = $count === 0 ? null : self::w59RustFfiState('out', $words);
        if ($buffer === null) {
            return self::backfillDaysPlanBatchPhp($rows);
        }
        [$ffi, $out, $cap] = $buffer;
        $joined = implode("\0", $rows);
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w591_backfill_days_plan_batch($joined, \strlen($joined), $count, $out, $cap);
        } catch (\Throwable) {
            $written = -1;
        }
        $result = $written === $count ? unpack('q*', FFI::string($out, $words * 8)) : false;
        if (! \is_array($result)) {
            return self::backfillDaysPlanBatchPhp($rows);
        }

        /** @var list<int> */
        return array_values($result);
    }

    /**
     * Wave 59 routing override (`set`) plus the lazily resolved binding and reusable `int64_t`
     * output buffer (`out`, sized for `$val` words), kept in function-local statics because
     * enums cannot declare properties.
     */
    private static function w59RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'out':
                $ffi ??= RustPipelineLibrary::resolveDomain('backfill_days_mode_w59', self::W59_BACKFILL_DAYS_FFI_CDEF) ?? false;
                if (! $ffi instanceof FFI || ! \is_int($val) || $val < 1) {
                    return null;
                }
                if (! $out instanceof CData || $outCap < $val) {
                    $outCap = max(1024, $val * 2);
                    $out = $ffi->new("int64_t[{$outCap}]");
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled;
        }
    }

    /**
     * Four words per `mode\tgroup_days\tsafe_days\tage_days\tnow` row: the resolved value, the
     * token source `<< W59_SOURCE_SHIFT` and the valid, group-target, safe-date, reached and
     * clamped flags; then the target days (the mode's day count clamped to 1-36500), the days
     * left after the age (clamped to 0-36500) and the window cutoff `now - target * 86400`.
     * Unknown modes resolve to days per group; a malformed row yields
     * `[W59_DAYS_MALFORMED, 0, 0, 0]`.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function backfillDaysPlanBatchPhp(array $rows): array
    {
        $words = [];
        foreach ($rows as $row) {
            $fields = explode("\t", $row);
            $ints = [];
            if (\count($fields) === 5) {
                for ($i = 1; $i <= 4 && preg_match('/\A-?[0-9]{1,18}\z/', $fields[$i]) === 1; $i++) {
                    $ints[] = (int) $fields[$i];
                }
            }
            if (\count($ints) !== 4) {
                array_push($words, self::W59_DAYS_MALFORMED, 0, 0, 0);

                continue;
            }
            [$groupDays, $safeDays, $age, $now] = $ints;
            [$value, $source] = self::w59ResolveMode($fields[0]);
            $resolved = $value ?? self::DaysPerGroup->value;
            $raw = $resolved === self::DaysPerGroup->value ? $groupDays : $safeDays;
            $target = max(1, min(36500, $raw));
            $age = max(0, min(36500, $age));
            $words[] = $resolved | $source << self::W59_SOURCE_SHIFT
                | ($value !== null ? self::W59_DAYS_VALID : 0)
                | ($resolved === self::DaysPerGroup->value ? self::W59_DAYS_GROUP_TARGET : self::W59_DAYS_SAFE_DATE)
                | ($age >= $target ? self::W59_DAYS_REACHED : 0)
                | ($target !== $raw ? self::W59_DAYS_CLAMPED : 0);
            $words[] = $target;
            $words[] = max(0, $target - $age);
            $words[] = $now - $target * 86400;
        }

        return $words;
    }

    /**
     * Resolves a mode token to `[case value or null, source]`: a strict integer must be a case
     * value; otherwise the trimmed, lowercased token without `_`, `-` and spaces is looked up
     * among case names, then aliases.
     *
     * @return array{0: ?int, 1: int}
     */
    private static function w59ResolveMode(string $token): array
    {
        $token = trim($token);
        if (preg_match('/\A-?[0-9]{1,18}\z/', $token) === 1) {
            $value = (int) $token;

            return self::tryFrom($value) === null ? [null, 0] : [$value, self::W59_SOURCE_VALUE];
        }
        $key = str_replace(['_', '-', ' '], '', strtolower($token));
        if (isset(self::W59_NAMES[$key])) {
            return [self::W59_NAMES[$key], self::W59_SOURCE_NAME];
        }

        return isset(self::W59_ALIASES[$key]) ? [self::W59_ALIASES[$key], self::W59_SOURCE_ALIAS] : [null, 0];
    }
}
