<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Binary file completion status during collection processing.
 */
enum FileCompletionStatus: int
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_fcs_classify(int64_t value);
        ssize_t nntmux_fcs_description(int64_t value, char *out, size_t cap);
        C;

    /** File/binary is still incomplete (missing parts) */
    case Incomplete = 0;

    /** File/binary has all parts */
    case Complete = 1;

    // PHP remains the production default until benchmarks qualify Rust.
    public static function setUseRustFfi(?bool $use = true): void
    {
        self::toggleState($use, true);
        if ($use === null) {
            self::ffi(true);
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::toggleState() ?? false;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::ffi(true, $override);
    }

    /**
     * Get human-readable description.
     */
    public function description(): string
    {
        return self::usesRustFfi() ? $this->descriptionNative() : $this->descriptionPhp();
    }

    public function descriptionPhp(): string
    {
        return match ($this) {
            self::Incomplete => 'Incomplete',
            self::Complete => 'Complete',
        };
    }

    public function descriptionNative(): string
    {
        if (($ffi = self::ffi()) === null) {
            return $this->descriptionPhp();
        }
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, 16, $capacity);
        if ($buffer === null) {
            return $this->descriptionPhp();
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_fcs_description($this->value, $buffer, $capacity);
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return $this->descriptionPhp();
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return $this->descriptionPhp();
        }
    }

    /**
     * Check if file is complete.
     */
    public function isComplete(): bool
    {
        return self::usesRustFfi() ? $this->isCompleteNative() : $this->isCompletePhp();
    }

    public function isCompletePhp(): bool
    {
        return $this === self::Complete;
    }

    public function isCompleteNative(): bool
    {
        $status = self::classifyNative($this->value);

        return $status === null ? $this->isCompletePhp() : $status === self::Complete;
    }

    /**
     * Classify a stored completion flag; null when it names no status.
     */
    public static function resolve(int $value): ?self
    {
        return self::usesRustFfi() ? self::resolveNative($value) : self::resolvePhp($value);
    }

    public static function resolvePhp(int $value): ?self
    {
        return self::tryFrom($value);
    }

    public static function resolveNative(int $value): ?self
    {
        return self::classifyNative($value) ?? self::resolvePhp($value);
    }

    /**
     * Status from `nntmux_fcs_classify`, or null for an unknown value or unavailable library.
     */
    private static function classifyNative(int $value): ?self
    {
        if (($ffi = self::ffi()) === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $code = $ffi->nntmux_fcs_classify($value);
        } catch (Throwable) {
            return null;
        }

        return \is_int($code) && $code >= 0 ? self::tryFrom($code) : null;
    }

    private static function toggleState(?bool $next = null, bool $write = false): ?bool
    {
        // Enums cannot declare static properties.
        static $useRustFfi = null;
        if ($write) {
            $useRustFfi = $next;
        }

        return $useRustFfi;
    }

    private static function ffi(bool $reset = false, FFI|false|null $override = null): ?FFI
    {
        static $ffi = null;
        if ($reset) {
            $ffi = $override;
            if ($override !== null) {
                return $ffi === false ? null : $ffi;
            }
        }
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return $ffi === false ? null : $ffi;
    }

    // Rust is the default only while the paired W61.6 benchmark clears 1.5x on all four metrics.
    public const bool W61_RUST_FFI_DEFAULT = true;

    private const string W61_COMPLETION_FFI_CDEF = <<<'C'
        int32_t nntmux_w616_file_completion_batch(const char *strings, size_t strings_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t repairable_permille, int64_t *out, size_t out_cap);
        C;

    public const int W61_COMPLETION_HEADER = 24;

    private const int W61_MAX_ITEMS = 1_048_576;

    private const int W61_PART_CAP = (1 << 24) - 1;

    /** Exact token => `case | source << 2`. */
    private const array W61_TOKENS = ['0' => 0, '1' => 1, 'Incomplete' => 4, 'Complete' => 5];

    /** Lowercased trimmed alias => case. */
    private const array W61_ALIASES = [
        'incomplete' => 0, 'partial' => 0, 'missing' => 0, 'false' => 0, 'no' => 0, '0' => 0,
        'complete' => 1, 'completed' => 1, 'done' => 1, 'full' => 1, 'true' => 1, 'yes' => 1, '1' => 1,
    ];

    /**
     * Plan stored completion flags against part counts in order. Statuses resolve by backing value (source 0),
     * case name (1), an alias of `strtolower(trim($status))` (2), else unknown case 2 (3). Received and total
     * parts clamp to 0..2^24-1. Ratio classes: 0 no parts, 1 none received, 2 partial, 3 partial at or above
     * `$repairablePermille` (clamped to 0..1000), 4 complete, 5 more received than total. Transitions to the
     * derived status (complete when every part arrived): 0 unchanged, 1 promote, 2 demote, 3 assign.
     *
     * Layout: `[0]` count, `[1..3]` per case, `[4..7]` per source, `[8..13]` per class, `[14..17]` per transition,
     * `[18]` derived complete, `[19]` first demotion (-1), `[20]` received and `[21]` total parts, `[22]` overall
     * permille, `[23]` FNV-1a digest; then per file `case | source << 2 | class << 4 | transition << 7 | derived
     * << 9 | permille << 10 | received << 20 | min(token bytes, 255) << 44`.
     *
     * @param  array<array-key, int|string>  $statuses
     * @param  array<array-key, int>  $received
     * @param  array<array-key, int>  $total
     * @return list<int>
     */
    public static function planFileCompletionBatch(array $statuses, array $received, array $total, int $repairablePermille = 950): array
    {
        return (self::usesW61RustFfi() ? self::planFileCompletionBatchNative($statuses, $received, $total, $repairablePermille) : null)
            ?? self::planFileCompletionBatchPhp($statuses, $received, $total, $repairablePermille);
    }

    /**
     * @param  array<array-key, int|string>  $statuses
     * @param  array<array-key, int>  $received
     * @param  array<array-key, int>  $total
     * @return list<int>
     */
    public static function planFileCompletionBatchPhp(array $statuses, array $received, array $total, int $repairablePermille = 950): array
    {
        $statuses = array_values($statuses);
        $count = \count($statuses);
        $received = self::w61Column($received, $count);
        $total = self::w61Column($total, $count);
        $threshold = min(max($repairablePermille, 0), 1_000);
        $out = array_fill(0, self::W61_COMPLETION_HEADER, 0);
        $out[0] = $count;
        $out[19] = -1;
        $covered = 0;
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($statuses as $at => $status) {
            $status = (string) $status;
            $code = self::W61_TOKENS[$status] ?? null;
            if ($code === null) {
                $case = self::W61_ALIASES[strtolower(trim($status))] ?? 2;
                $source = $case === 2 ? 3 : 2;
            } else {
                $case = $code & 3;
                $source = $code >> 2;
            }
            $got = min(max((int) $received[$at], 0), self::W61_PART_CAP);
            $parts = min(max((int) $total[$at], 0), self::W61_PART_CAP);
            $have = min($got, $parts);
            $permille = $parts > 0 ? intdiv($have * 1_000, $parts) : 0;
            $class = match (true) {
                $parts === 0 => 0,
                $got === 0 => 1,
                $got < $parts => $permille >= $threshold ? 3 : 2,
                $got === $parts => 4,
                default => 5,
            };
            $derived = $parts > 0 && $got >= $parts ? 1 : 0;
            $transition = $case === 2 ? 3 : ($case === $derived ? 0 : ($derived === 1 ? 1 : 2));
            $out[1 + $case]++;
            $out[4 + $source]++;
            $out[8 + $class]++;
            $out[14 + $transition]++;
            $out[18] += $derived;
            if ($transition === 2 && $out[19] < 0) {
                $out[19] = $at;
            }
            $out[20] += $got;
            $out[21] += $parts;
            $covered += $have;
            $row = $case | $source << 2 | $class << 4 | $transition << 7 | $derived << 9 | $permille << 10 | $got << 20 | min(\strlen($status), 255) << 44;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        if ($out[21] > 0) {
            $out[22] = intdiv($covered * 1_000, $out[21]);
        }
        $out[23] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust plan, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, int|string>  $statuses
     * @param  array<array-key, int>  $received
     * @param  array<array-key, int>  $total
     * @return list<int>|null
     */
    public static function planFileCompletionBatchNative(array $statuses, array $received, array $total, int $repairablePermille = 950): ?array
    {
        $count = \count($statuses);
        if ($count > self::W61_MAX_ITEMS) {
            return null;
        }
        [$joined, $twin] = self::w61Strings(array_values($statuses));
        $ints = pack('q*', ...self::w61Column($received, $count), ...self::w61Column($total, $count));
        $words = self::W61_COMPLETION_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w61Call($words, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w616_file_completion_batch($joined, \strlen($joined), $twin, \strlen($twin), $ints, \strlen($ints), $count, $repairablePermille, $out, $words));
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
     * Wave 61.6 routing and binding in function statics (enums cannot declare properties). No `$op` returns
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
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w616', self::W61_COMPLETION_FFI_CDEF) ?? false;
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
