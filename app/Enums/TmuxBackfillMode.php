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

    /**
     * Enabled: the paired benchmark in the W59 parity test measures 7.228x common mean, 6.401x
     * common p99, 5.125x uncommon mean and 3.146x uncommon p99 (per-metric minimum of four runs),
     * above the 1.5x rule.
     */
    public const bool W59_RUST_FFI_DEFAULT = true;

    private const string W59_TMUX_BACKFILL_FFI_CDEF = 'ssize_t nntmux_w591_tmux_backfill_plan_batch(const char *rows, size_t rows_len, size_t count, int64_t *out, size_t out_cap);';

    public const int W59_TMUX_BACKFILL_WORDS = 5;

    public const int W59_SOURCE_SHIFT = 4;

    public const int W59_SOURCE_VALUE = 1;

    public const int W59_SOURCE_NAME = 2;

    public const int W59_SOURCE_ALIAS = 3;

    public const int W59_TMUX_VALID = 64;

    public const int W59_TMUX_ENABLED = 128;

    public const int W59_TMUX_QTY_DEFAULTED = 256;

    public const int W59_TMUX_QTY_CLAMPED = 512;

    public const int W59_TMUX_PROCESSES_CLAMPED = 1024;

    public const int W59_TMUX_MALFORMED = 2048;

    public const int W59_TMUX_ORDER_SHIFT = 12;

    public const int W59_ORDER_ROUND_ROBIN = 1;

    public const int W59_ORDER_OLDEST_FIRST = 2;

    private const array W59_NAMES = ['disabled' => 0, 'all' => 1, 'safe' => 4];

    private const array W59_ALIASES = [
        'off' => 0, 'none' => 0, 'no' => 0, 'false' => 0, 'disable' => 0,
        'allgroups' => 1, 'full' => 1, 'backfill' => 1, 'on' => 1, 'enabled' => 1,
        'safebackfill' => 4, 'oldest' => 4, 'single' => 4, 'onebyone' => 4,
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
    public static function tmuxBackfillPlanBatch(array $rows): array
    {
        return self::usesW59RustFfi() ? self::tmuxBackfillPlanBatchNative($rows) : self::tmuxBackfillPlanBatchPhp($rows);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or Rust declines the batch (a
     * row holding a NUL byte).
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function tmuxBackfillPlanBatchNative(array $rows): array
    {
        $count = \count($rows);
        $words = $count * self::W59_TMUX_BACKFILL_WORDS;
        /** @var array{0: FFI, 1: CData, 2: int}|null $buffer */
        $buffer = $count === 0 ? null : self::w59RustFfiState('out', $words);
        if ($buffer === null) {
            return self::tmuxBackfillPlanBatchPhp($rows);
        }
        [$ffi, $out, $cap] = $buffer;
        $joined = implode("\0", $rows);
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w591_tmux_backfill_plan_batch($joined, \strlen($joined), $count, $out, $cap);
        } catch (\Throwable) {
            $written = -1;
        }
        $result = $written === $count ? unpack('q*', FFI::string($out, $words * 8)) : false;
        if (! \is_array($result)) {
            return self::tmuxBackfillPlanBatchPhp($rows);
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
                $ffi ??= RustPipelineLibrary::resolveDomain('tmux_backfill_mode_w59', self::W59_TMUX_BACKFILL_FFI_CDEF) ?? false;
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
     * Five words per `mode\tquantity\tgroup_count\tprocesses` row: the resolved value, the
     * token source `<< W59_SOURCE_SHIFT`, the valid, enabled, quantity-defaulted (below 1 uses
     * 20000), quantity-clamped (to 10000000) and processes-clamped (to 1-32) flags and the group
     * order (round robin for all, oldest first for safe) `<< W59_TMUX_ORDER_SHIFT`; then the
     * articles per group, the normalizeBackfillDispatchPhp() groups per pass, the chunk count
     * and the chunk size. Unknown modes disable backfill; a malformed row yields
     * `[W59_TMUX_MALFORMED, 0, 0, 0, 0]`.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function tmuxBackfillPlanBatchPhp(array $rows): array
    {
        $words = [];
        foreach ($rows as $row) {
            $fields = explode("\t", $row);
            $ints = [];
            if (\count($fields) === 4) {
                for ($i = 1; $i <= 3 && preg_match('/\A-?[0-9]{1,18}\z/', $fields[$i]) === 1; $i++) {
                    $ints[] = (int) $fields[$i];
                }
            }
            if (\count($ints) !== 3) {
                array_push($words, self::W59_TMUX_MALFORMED, 0, 0, 0, 0);

                continue;
            }
            [$quantity, $groupCount, $processes] = $ints;
            [$value, $source] = self::w59ResolveMode($fields[0]);
            $resolved = $value ?? self::Disabled->value;
            $flags = $resolved | $source << self::W59_SOURCE_SHIFT
                | ($value !== null ? self::W59_TMUX_VALID : 0)
                | ($resolved !== self::Disabled->value ? self::W59_TMUX_ENABLED : 0);
            if ($quantity < 1) {
                $quantity = 20000;
                $flags |= self::W59_TMUX_QTY_DEFAULTED;
            } elseif ($quantity > 10000000) {
                $quantity = 10000000;
                $flags |= self::W59_TMUX_QTY_CLAMPED;
            }
            [$perPass, $order] = match ($resolved) {
                self::All->value => [max(1, min($groupCount, self::UINT32_MAX)), self::W59_ORDER_ROUND_ROBIN],
                self::Safe->value => [1, self::W59_ORDER_OLDEST_FIRST],
                default => [0, 0],
            };
            $clamped = max(1, min(32, $processes));
            $flags |= $clamped !== $processes ? self::W59_TMUX_PROCESSES_CLAMPED : 0;
            $chunks = min($perPass, $clamped);
            array_push($words, $flags | $order << self::W59_TMUX_ORDER_SHIFT, $quantity, $perPass, $chunks, $chunks === 0 ? 0 : intdiv($perPass + $chunks - 1, $chunks));
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
