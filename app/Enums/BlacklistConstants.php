<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Constants for blacklist/whitelist operations.
 * Migrated from Blacklight\Binaries.
 */
final class BlacklistConstants
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_wave21_blacklist_constant(const char *input, size_t len);
        C;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    // Operation type constants
    public const OPTYPE_BLACKLIST = 1;

    public const OPTYPE_WHITELIST = 2;

    // Blacklist status constants
    public const BLACKLIST_ENABLED = 1;

    // Blacklist field constants
    public const BLACKLIST_FIELD_SUBJECT = 1;

    public const BLACKLIST_FIELD_FROM = 2;

    public const BLACKLIST_FIELD_MESSAGEID = 3;

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

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function resolveConstant(string $name): ?int
    {
        if (self::$useRustFfi) {
            $resolved = self::resolveConstantNative($name);
            if ($resolved !== null) {
                return $resolved['value'];
            }
        }

        return self::resolveConstantPhp($name);
    }

    /** @return array{value: ?int}|null */
    public static function resolveConstantNative(string $name): ?array
    {
        try {
            self::$ffi ??= RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF) ?? false;
            if (self::$ffi === false) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $value = self::$ffi->nntmux_wave21_blacklist_constant($name, strlen($name));
            if (! is_int($value) || $value < 0) {
                return null;
            }

            return ['value' => $value === 0 ? null : $value];
        } catch (Throwable) {
            return null;
        }
    }

    public static function resolveConstantPhp(string $name): ?int
    {
        return match ($name) {
            'OPTYPE_BLACKLIST' => self::OPTYPE_BLACKLIST,
            'OPTYPE_WHITELIST' => self::OPTYPE_WHITELIST,
            'BLACKLIST_ENABLED' => self::BLACKLIST_ENABLED,
            'BLACKLIST_FIELD_SUBJECT' => self::BLACKLIST_FIELD_SUBJECT,
            'BLACKLIST_FIELD_FROM' => self::BLACKLIST_FIELD_FROM,
            'BLACKLIST_FIELD_MESSAGEID' => self::BLACKLIST_FIELD_MESSAGEID,
            default => null,
        };
    }

    // Rust is the default only while the paired W61.6 benchmark clears 1.5x on all four metrics.
    public const bool W61_RUST_FFI_DEFAULT = true;

    private const string W61_BLACKLIST_FFI_CDEF = <<<'C'
        int32_t nntmux_w616_blacklist_rule_batch(const char *strings, size_t strings_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    public const int W61_BLACKLIST_HEADER = 20;

    private const int W61_MAX_ITEMS = 1_048_576;

    /** Longest pattern the `binaryblacklist.regex` column holds. */
    private const int W61_PATTERN_CAP = 2_000;

    /** Constant name => operation type. */
    private const array W61_OPTYPE_NAMES = ['OPTYPE_BLACKLIST' => 1, 'OPTYPE_WHITELIST' => 2];

    /** Lowercased trimmed alias => operation type. */
    private const array W61_OPTYPE_ALIASES = [
        'blacklist' => 1, 'black' => 1, 'block' => 1, 'deny' => 1, '1' => 1, 'whitelist' => 2, 'white' => 2, 'allow' => 2, '2' => 2,
    ];

    /** Constant name => message column. */
    private const array W61_FIELD_NAMES = ['BLACKLIST_FIELD_SUBJECT' => 1, 'BLACKLIST_FIELD_FROM' => 2, 'BLACKLIST_FIELD_MESSAGEID' => 3];

    /** Lowercased trimmed alias => message column. */
    private const array W61_FIELD_ALIASES = [
        'subject' => 1, 'name' => 1, '1' => 1, 'from' => 2, 'poster' => 2, 'fromname' => 2, '2' => 2,
        'messageid' => 3, 'message-id' => 3, 'message_id' => 3, 'msgid' => 3, '3' => 3,
    ];

    /** Trimmed patterns that match every header. */
    private const array W61_CATCH_ALL = ['.*' => true, '.+' => true, '^.*$' => true, '.*?' => true, '^' => true, '$' => true];

    /**
     * Plan blacklist rules in order. Operation types and message columns resolve by constant name (source 0),
     * an alias of `strtolower(trim($value))` (1), else unknown 0 (2). Flags: 1 unknown type, 2 unknown column,
     * 4 status not 0/1, 8 blank pattern, 16 NUL in pattern, 32 pattern over 2,000 bytes, 64 catch-all pattern;
     * a rule is valid without flags and active when valid and enabled.
     *
     * Layout: `[0]` count, `[1..3]` per type, `[4..7]` per column, `[8..9]` types by name / alias, `[10..11]`
     * columns by name / alias, `[12]` valid, `[13]` active blacklist and `[14]` whitelist rules, `[15]` invalid
     * and `[16]` the first invalid index (-1), `[17]` pattern bytes, `[18]` longest pattern, `[19]` FNV-1a digest;
     * then per rule `type | column << 2 | type source << 4 | column source << 6 | flags << 8 | valid << 15 |
     * active << 16 | min(pattern bytes, 2^20-1) << 17`.
     *
     * @param  array<array-key, int|string>  $opTypes
     * @param  array<array-key, int|string>  $fields
     * @param  array<array-key, string>  $patterns
     * @param  array<array-key, int>  $statuses
     * @return list<int>
     */
    public static function planBlacklistRuleBatch(array $opTypes, array $fields, array $patterns, array $statuses): array
    {
        return (self::usesW61RustFfi() ? self::planBlacklistRuleBatchNative($opTypes, $fields, $patterns, $statuses) : null)
            ?? self::planBlacklistRuleBatchPhp($opTypes, $fields, $patterns, $statuses);
    }

    /**
     * @param  array<array-key, int|string>  $opTypes
     * @param  array<array-key, int|string>  $fields
     * @param  array<array-key, string>  $patterns
     * @param  array<array-key, int>  $statuses
     * @return list<int>
     */
    public static function planBlacklistRuleBatchPhp(array $opTypes, array $fields, array $patterns, array $statuses): array
    {
        $opTypes = array_values($opTypes);
        $count = \count($opTypes);
        $fields = self::w61Column($fields, $count, '');
        $patterns = self::w61Column($patterns, $count, '');
        $statuses = self::w61Column($statuses, $count);
        $out = array_fill(0, self::W61_BLACKLIST_HEADER, 0);
        $out[0] = $count;
        $out[16] = -1;
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($opTypes as $at => $opType) {
            $opType = (string) $opType;
            $typeSource = 0;
            $type = self::W61_OPTYPE_NAMES[$opType] ?? null;
            if ($type === null) {
                $type = self::W61_OPTYPE_ALIASES[strtolower(trim($opType))] ?? 0;
                $typeSource = $type === 0 ? 2 : 1;
            }
            $field = (string) $fields[$at];
            $columnSource = 0;
            $column = self::W61_FIELD_NAMES[$field] ?? null;
            if ($column === null) {
                $column = self::W61_FIELD_ALIASES[strtolower(trim($field))] ?? 0;
                $columnSource = $column === 0 ? 2 : 1;
            }
            $pattern = (string) $patterns[$at];
            $trimmed = trim($pattern);
            $length = \strlen($pattern);
            $status = (int) $statuses[$at];
            $flags = ($type === 0 ? 1 : 0)
                | ($column === 0 ? 2 : 0)
                | ($status !== 0 && $status !== 1 ? 4 : 0)
                | ($trimmed === '' ? 8 : 0)
                | (str_contains($pattern, "\0") ? 16 : 0)
                | ($length > self::W61_PATTERN_CAP ? 32 : 0)
                | (isset(self::W61_CATCH_ALL[$trimmed]) ? 64 : 0);
            $valid = $flags === 0 ? 1 : 0;
            $active = $valid === 1 && $status === 1 ? 1 : 0;
            $out[1 + $type]++;
            $out[4 + $column]++;
            if ($typeSource < 2) {
                $out[8 + $typeSource]++;
            }
            if ($columnSource < 2) {
                $out[10 + $columnSource]++;
            }
            $out[12] += $valid;
            if ($active === 1) {
                $out[12 + $type]++;
            }
            if ($valid === 0) {
                $out[15]++;
                if ($out[16] < 0) {
                    $out[16] = $at;
                }
            }
            $out[17] += $length;
            $out[18] = max($out[18], $length);
            $row = $type | $column << 2 | $typeSource << 4 | $columnSource << 6 | $flags << 8 | $valid << 15 | $active << 16 | min($length, 1_048_575) << 17;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[19] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust plan, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, int|string>  $opTypes
     * @param  array<array-key, int|string>  $fields
     * @param  array<array-key, string>  $patterns
     * @param  array<array-key, int>  $statuses
     * @return list<int>|null
     */
    public static function planBlacklistRuleBatchNative(array $opTypes, array $fields, array $patterns, array $statuses): ?array
    {
        $count = \count($opTypes);
        if ($count > self::W61_MAX_ITEMS) {
            return null;
        }
        [$joined, $twin] = self::w61Strings([...array_values($opTypes), ...self::w61Column($fields, $count, ''), ...self::w61Column($patterns, $count, '')]);
        $ints = pack('q*', ...self::w61Column($statuses, $count));
        $words = self::W61_BLACKLIST_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w61Call($words, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w616_blacklist_rule_batch($joined, \strlen($joined), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $words));
    }

    /**
     * Run `$callback` with the Wave 61.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW61RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w61RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w61RustFfiState('set', $previous);
        }
    }

    public static function usesW61RustFfi(): bool
    {
        $forced = self::w61RustFfiState();

        return \is_bool($forced) ? $forced : self::W61_RUST_FFI_DEFAULT;
    }

    /**
     * Wave 61.6 routing and binding in function statics (kept out of class properties like the enum waves). No `$op` returns
     * the forced routing (null for the default); `'set'` replaces it with `$val` and returns the previous
     * value; `'binding'` returns the cached binding and an output buffer of at least `$val` values, or null.
     */
    private static function w61RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $enabled */
        static $enabled = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $buffer */
        static $buffer = null;
        static $capacity = 0;
        if ($op === 'set') {
            $previous = $enabled;
            $enabled = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op !== 'binding') {
            return $enabled;
        }
        $fields = \is_int($val) ? $val : 0;
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w616', self::W61_BLACKLIST_FFI_CDEF) ?? false;
            if ($ffi === false) {
                return null;
            }
            if ($buffer === null || $capacity < $fields) {
                $capacity = max(256, $fields);
                $buffer = $ffi->new('int64_t['.$capacity.']');
            }
        } catch (Throwable) {
            return null;
        }

        return $buffer === null ? null : [$ffi, $buffer];
    }

    /**
     * The batch's `$fields` output words from one Wave 61.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w61Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w61RustFfiState('binding', $fields);
        if ($binding === null) {
            return null;
        }
        try {
            if ($call($binding[0], $binding[1]) !== 1) {
                return null;
            }
            $values = unpack('P*', FFI::string($binding[1], $fields * 8));
        } catch (Throwable) {
            return null;
        }

        return $values === false ? null : array_values($values);
    }

    /**
     * Values NUL-joined and, when one holds a NUL, the same values joined with 0x01 so Rust finds the
     * separators where the two differ.
     *
     * @param  list<int|string>  $items
     * @return array{0: string, 1: string}
     */
    private static function w61Strings(array $items): array
    {
        $joined = implode("\0", $items);

        return $items !== [] && substr_count($joined, "\0") !== \count($items) - 1 ? [$joined, implode("\x01", $items)] : [$joined, ''];
    }

    /**
     * Values by position, cut or padded with `$pad` to `$count`.
     *
     * @template T of int|string
     *
     * @param  array<array-key, T>  $values
     * @param  T  $pad
     * @return list<T>
     */
    private static function w61Column(array $values, int $count, int|string $pad = 0): array
    {
        $values = array_values($values);

        return \count($values) === $count ? $values : array_pad(\array_slice($values, 0, $count), $count, $pad);
    }
}
