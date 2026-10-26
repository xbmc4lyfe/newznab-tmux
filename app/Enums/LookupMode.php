<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

enum LookupMode: int
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_lookup_mode_label(int64_t mode, char *out_buf, size_t out_cap);
        C;

    case Disabled = 0;
    case All = 1;
    case Renamed = 2;

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

    private static function toggleState(?bool $next = null, bool $write = false): ?bool
    {
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
            $ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF) ?? false;
        }

        return $ffi === false ? null : $ffi;
    }

    public function label(): string
    {
        return self::usesRustFfi()
            ? $this->labelNative()
            : $this->labelPhp();
    }

    public function labelNative(): string
    {
        if (($ffi = self::ffi()) === null) {
            return $this->labelPhp();
        }
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, 64, $capacity);
        if ($buffer === null) {
            return $this->labelPhp();
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_lookup_mode_label($this->value, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return $this->labelPhp();
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_lookup_mode_label($this->value, $buffer, $capacity);
            }
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return $this->labelPhp();
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return $this->labelPhp();
        }
    }

    public function labelPhp(): string
    {
        return match ($this) {
            self::Disabled => 'Disabled',
            self::All => 'All releases',
            self::Renamed => 'Renamed releases only',
        };
    }

    // Rust is the default only while the paired W62.6 benchmark clears 1.5x on all four metrics.
    public const bool W62_RUST_FFI_DEFAULT = true;

    private const string W62_LOOKUP_FFI_CDEF = <<<'C'
        int32_t nntmux_w626_lookup_mode_batch(const char *strings, size_t strings_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t limit, int64_t *out, size_t out_cap);
        C;

    public const int W62_LOOKUP_HEADER = 16;

    private const int W62_MAX_ITEMS = 1_048_576;

    private const int W62_SCOPE_CAP = (1 << 24) - 1;

    /** Exact token => `case | source << 2`. */
    private const array W62_TOKENS = ['0' => 0, '1' => 1, '2' => 2, 'Disabled' => 4, 'All' => 5, 'Renamed' => 6];

    /** Lowercased trimmed alias => case. */
    private const array W62_ALIASES = [
        'disabled' => 0, 'disable' => 0, 'off' => 0, 'none' => 0, 'false' => 0, 'no' => 0,
        'all' => 1, 'all releases' => 1, 'on' => 1, 'true' => 1, 'yes' => 1, 'enabled' => 1, 'every' => 1,
        'renamed' => 2, 'renamed releases only' => 2, 'renamed-only' => 2, 'renamed_only' => 2, 'rename' => 2,
    ];

    /**
     * Plan lookup runs in order. Modes resolve by backing value (source 0), case name (1), an alias of
     * `strtolower(trim($mode))` (2), else unknown case 3 (3), which looks nothing up. Candidate and renamed
     * counts and `$limit` clamp to 0..2^24-1 (limit 0 is unlimited). The scope is every candidate for All, the
     * renamed ones (at most every candidate) for Renamed, else none; the selection is the scope cut to the limit.
     *
     * Layout: `[0]` count, `[1..4]` per case, `[5..8]` per source, `[9]` enabled, `[10]` scope, `[11]` selected
     * and `[12]` deferred candidates, `[13]` truncated runs and `[14]` the first truncated index (-1), `[15]`
     * FNV-1a digest; then per run `case | source << 2 | enabled << 4 | truncated << 5 | selected << 6 | scope
     * << 30 | min(token bytes, 255) << 54`.
     *
     * @param  array<array-key, int|string>  $modes
     * @param  array<array-key, int>  $candidates
     * @param  array<array-key, int>  $renamed
     * @return list<int>
     */
    public static function planLookupModeBatch(array $modes, array $candidates, array $renamed, int $limit = 0): array
    {
        return (self::usesW62RustFfi() ? self::planLookupModeBatchNative($modes, $candidates, $renamed, $limit) : null)
            ?? self::planLookupModeBatchPhp($modes, $candidates, $renamed, $limit);
    }

    /**
     * @param  array<array-key, int|string>  $modes
     * @param  array<array-key, int>  $candidates
     * @param  array<array-key, int>  $renamed
     * @return list<int>
     */
    public static function planLookupModeBatchPhp(array $modes, array $candidates, array $renamed, int $limit = 0): array
    {
        $modes = array_values($modes);
        $count = \count($modes);
        $candidates = self::w62Column($candidates, $count);
        $renamed = self::w62Column($renamed, $count);
        $limit = min(max($limit, 0), self::W62_SCOPE_CAP);
        $out = array_fill(0, self::W62_LOOKUP_HEADER, 0);
        $out[0] = $count;
        $out[14] = -1;
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($modes as $at => $mode) {
            $mode = (string) $mode;
            $code = self::W62_TOKENS[$mode] ?? null;
            if ($code === null) {
                $case = self::W62_ALIASES[strtolower(trim($mode))] ?? 3;
                $source = $case === 3 ? 3 : 2;
            } else {
                $case = $code & 3;
                $source = $code >> 2;
            }
            $all = min(max((int) $candidates[$at], 0), self::W62_SCOPE_CAP);
            $scope = match ($case) {
                1 => $all,
                2 => min(min(max((int) $renamed[$at], 0), self::W62_SCOPE_CAP), $all),
                default => 0,
            };
            $selected = $limit > 0 ? min($scope, $limit) : $scope;
            $truncated = $selected < $scope ? 1 : 0;
            $enabled = $case === 1 || $case === 2 ? 1 : 0;
            $out[1 + $case]++;
            $out[5 + $source]++;
            $out[9] += $enabled;
            $out[10] += $scope;
            $out[11] += $selected;
            $out[12] += $scope - $selected;
            $out[13] += $truncated;
            if ($truncated === 1 && $out[14] < 0) {
                $out[14] = $at;
            }
            $row = $case | $source << 2 | $enabled << 4 | $truncated << 5 | $selected << 6 | $scope << 30 | min(\strlen($mode), 255) << 54;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[15] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust plan, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, int|string>  $modes
     * @param  array<array-key, int>  $candidates
     * @param  array<array-key, int>  $renamed
     * @return list<int>|null
     */
    public static function planLookupModeBatchNative(array $modes, array $candidates, array $renamed, int $limit = 0): ?array
    {
        $count = \count($modes);
        if ($count > self::W62_MAX_ITEMS) {
            return null;
        }
        [$joined, $twin] = self::w62Strings(array_values($modes));
        $ints = pack('q*', ...self::w62Column($candidates, $count), ...self::w62Column($renamed, $count));
        $words = self::W62_LOOKUP_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w62Call($words, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w626_lookup_mode_batch($joined, \strlen($joined), $twin, \strlen($twin), $ints, \strlen($ints), $count, $limit, $out, $words));
    }

    /**
     * Run `$callback` with the Wave 62.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW62RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w62RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w62RustFfiState('set', $previous);
        }
    }

    public static function usesW62RustFfi(): bool
    {
        $forced = self::w62RustFfiState();

        return \is_bool($forced) ? $forced : self::W62_RUST_FFI_DEFAULT;
    }

    /**
     * Wave 62.6 routing and binding in function statics (enums cannot declare properties). No `$op` returns
     * the forced routing (null for the default); `'set'` replaces it with `$val` and returns the previous
     * value; `'binding'` returns the cached binding and an output buffer of at least `$val` values, or null.
     */
    private static function w62RustFfiState(?string $op = null, mixed $val = null): mixed
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
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w626', self::W62_LOOKUP_FFI_CDEF) ?? false;
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
     * The batch's `$fields` output words from one Wave 62.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w62Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w62RustFfiState('binding', $fields);
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
    private static function w62Strings(array $items): array
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
    private static function w62Column(array $values, int $count, int|string $pad = 0): array
    {
        $values = array_values($values);

        return \count($values) === $count ? $values : array_pad(\array_slice($values, 0, $count), $count, $pad);
    }
}
