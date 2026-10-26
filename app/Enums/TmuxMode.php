<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

enum TmuxMode: int
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_tmux_mode_tasks_nul(int64_t mode, size_t *out_pair_count, char *out_buf, size_t out_cap);
        C;

    case Full = 0;
    case Basic = 1;
    case Stripped = 2;

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::toggleState($use, true);
        if ($use === null) {
            self::categorizeFfi(true);
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::toggleState() ?? false;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::categorizeFfi(true, $override);
    }

    private static function toggleState(?bool $next = null, bool $write = false): ?bool
    {
        static $useRustFfi = null;
        if ($write) {
            $useRustFfi = $next;
        }

        return $useRustFfi;
    }

    public static function categorizeFfi(bool $reset = false, FFI|false|null $override = null): ?FFI
    {
        static $ffi = null;
        if ($reset) {
            $ffi = $override;
            if ($override !== null) {
                return $ffi === false ? null : $ffi;
            }
        }
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('categorize', self::FFI_CDEF) ?? false;
        }

        return $ffi === false ? null : $ffi;
    }

    /** @return array<string, TmuxPaneRole> */
    public function tasks(): array
    {
        return self::usesRustFfi() ? $this->tasksNative() : $this->tasksPhp();
    }

    /** @return array<string, TmuxPaneRole> */
    public function tasksPhp(): array
    {
        $tasks = match ($this) {
            self::Full => ['binaries' => TmuxPaneRole::Binaries, 'backfill' => TmuxPaneRole::Backfill, 'releases' => TmuxPaneRole::Releases],
            self::Basic => ['releases' => TmuxPaneRole::Releases],
            self::Stripped => ['main' => TmuxPaneRole::Sequential],
        };
        $tasks['fixnames'] = TmuxPaneRole::FixNames;
        if ($this !== self::Stripped) {
            $tasks += ['removecrap' => TmuxPaneRole::RemoveCrap, 'ppadditional' => TmuxPaneRole::PostAdditional, 'tv' => TmuxPaneRole::PostTv, 'movies' => TmuxPaneRole::PostMovies];
        }
        $tasks['amazon'] = TmuxPaneRole::PostMetadata;
        $tasks['scraper'] = TmuxPaneRole::IrcScraper;

        return $tasks;
    }

    /** @return array<string, TmuxPaneRole> */
    public function tasksNative(): array
    {
        $ffi = self::categorizeFfi();
        $outPairCount = $ffi === null ? null : self::pairCountSlot($ffi);
        if ($ffi === null || $outPairCount === null) {
            return $this->tasksPhp();
        }

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 512, $cap);
        if ($scratch === null) {
            return $this->tasksPhp();
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tmux_mode_tasks_nul($this->value, $outPairCount, $scratch, $cap);
            if (\is_int($written) && $written < -2) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return $this->tasksPhp();
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_tmux_mode_tasks_nul($this->value, $outPairCount, $scratch, $cap);
            }
            if (! \is_int($written) || $written <= 0 || $written > $cap) {
                return $this->tasksPhp();
            }

            /** @var int $expectedPairs */
            $expectedPairs = $outPairCount[0];
            $tokens = explode("\0", FFI::string($scratch, $written));
            if (\count($tokens) !== $expectedPairs * 2) {
                return $this->tasksPhp();
            }

            $tasks = [];
            for ($i = 0; $i < $expectedPairs; $i++) {
                $key = $tokens[$i * 2];
                $role = TmuxPaneRole::tryFrom($tokens[$i * 2 + 1]);
                if ($key === '' || $role === null) {
                    return $this->tasksPhp();
                }
                $tasks[$key] = $role;
            }

            return $tasks;
        } catch (Throwable) {
            return $this->tasksPhp();
        }
    }

    private static function pairCountSlot(FFI $ffi): ?CData
    {
        static $slot = null;
        static $owner = null;
        if ($owner !== $ffi || $slot === null) {
            try {
                $slot = $ffi->new('size_t[1]');
                $owner = $ffi;
            } catch (Throwable) {
                return null;
            }
        }

        return $slot;
    }

    // Rust is the default only while the paired W63.6 benchmark clears 1.5x on all four metrics.
    public const bool W63_RUST_FFI_DEFAULT = true;

    private const string W63_TMUX_FFI_CDEF = <<<'C'
        int32_t nntmux_w636_tmux_mode_batch(const char *strings, size_t strings_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t capacity, int64_t *out, size_t out_cap);
        C;

    public const int W63_TMUX_HEADER = 21;

    private const int W63_MAX_ITEMS = 1_048_576;

    /** Active runner clamp (2^16 - 1) and capacity cap (2^40). */
    private const int W63_ACTIVE_CAP = 65_535;

    private const int W63_CAPACITY_CAP = 1_099_511_627_776;

    /** `count(tasksPhp())`, default and maximum runners per case (unknown 0). */
    private const array W63_PANES = [10, 8, 4, 0];

    private const array W63_DEFAULT_RUNNERS = [4, 2, 1, 0];

    private const array W63_MAX_RUNNERS = [16, 8, 1, 0];

    /** Exact token => `case | source << 2`. */
    private const array W63_TMUX_TOKENS = ['0' => 0, '1' => 1, '2' => 2, 'Full' => 4, 'Basic' => 5, 'Stripped' => 6];

    /** Lowercased trimmed alias => case. */
    private const array W63_TMUX_ALIASES = [
        'full' => 0, 'complete' => 0, 'all' => 0, 'everything' => 0,
        'basic' => 1, 'standard' => 1, 'default' => 1, 'normal' => 1,
        'stripped' => 2, 'minimal' => 2, 'sequential' => 2, 'lite' => 2, 'simple' => 2,
    ];

    /**
     * Plan tmux runner admission in order. Modes resolve by backing value (source 0), case name (1), an alias
     * of `strtolower(trim($mode))` (2), else unknown case 3 (3). A known mode targets its default runners (Full
     * 4, Basic 2, Stripped 1) for a request at or below 0 (flag 2), else the request capped to its maximum (16,
     * 8, 1; flag 1 when capped). Active runners clamp to 0..2^16-1. In order, each known mode starts
     * `max(target - active, 0)` runners while the shared capacity (at or below 0 is unlimited, else capped at
     * 2^40) remains. State 0 admitted, 1 partially admitted, 2 deferred, 3 rejected (unknown), 4 saturated.
     *
     * Layout: `[0]` count, `[1..4]` per case, `[5..8]` per source, `[9..13]` per state, `[14]` total panes,
     * `[15]` total target runners, `[16]` runners started, `[17]` remaining capacity (-1 unlimited), `[18]`
     * first partial or deferred (-1), `[19]` first rejected (-1), `[20]` FNV-1a digest; then per mode `case |
     * source << 2 | panes << 4 | target << 8 | flags << 13 | state << 15 | started << 18 | active << 23 |
     * min(token bytes, 255) << 39`.
     *
     * @param  array<array-key, int|string>  $modes
     * @param  array<array-key, int>  $requested
     * @param  array<array-key, int>  $active
     * @return list<int>
     */
    public static function w63TmuxModePlanBatch(array $modes, array $requested, array $active, int $capacity = 0): array
    {
        return (self::usesW63RustFfi() ? self::w63TmuxModePlanBatchNative($modes, $requested, $active, $capacity) : null)
            ?? self::w63TmuxModePlanBatchPhp($modes, $requested, $active, $capacity);
    }

    /**
     * @param  array<array-key, int|string>  $modes
     * @param  array<array-key, int>  $requested
     * @param  array<array-key, int>  $active
     * @return list<int>
     */
    public static function w63TmuxModePlanBatchPhp(array $modes, array $requested, array $active, int $capacity = 0): array
    {
        $modes = array_values($modes);
        $count = \count($modes);
        $requested = self::w63Column($requested, $count);
        $active = self::w63Column($active, $count);
        $remaining = $capacity <= 0 ? -1 : min($capacity, self::W63_CAPACITY_CAP);
        $out = array_fill(0, self::W63_TMUX_HEADER, 0);
        $out[0] = $count;
        $out[18] = $out[19] = -1;
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($modes as $at => $mode) {
            $mode = (string) $mode;
            $code = self::W63_TMUX_TOKENS[$mode] ?? null;
            if ($code === null) {
                $case = self::W63_TMUX_ALIASES[strtolower(trim($mode))] ?? 3;
                $source = $case === 3 ? 3 : 2;
            } else {
                $case = $code & 3;
                $source = $code >> 2;
            }
            $request = (int) $requested[$at];
            $running = max(0, min((int) $active[$at], self::W63_ACTIVE_CAP));
            if ($case === 3) {
                $target = $flags = 0;
            } elseif ($request <= 0) {
                $target = self::W63_DEFAULT_RUNNERS[$case];
                $flags = 2;
            } else {
                $target = min($request, self::W63_MAX_RUNNERS[$case]);
                $flags = $request > self::W63_MAX_RUNNERS[$case] ? 1 : 0;
            }
            $available = max($target - $running, 0);
            $started = 0;
            if ($case === 3) {
                $state = 3;
            } elseif ($available === 0) {
                $state = 4;
            } else {
                $started = $remaining < 0 ? $available : min($available, $remaining);
                if ($remaining >= 0) {
                    $remaining -= $started;
                }
                $state = $started === $available ? 0 : ($started > 0 ? 1 : 2);
            }
            $panes = self::W63_PANES[$case];
            $out[1 + $case]++;
            $out[5 + $source]++;
            $out[9 + $state]++;
            $out[14] += $panes;
            $out[15] += $target;
            $out[16] += $started;
            if (($state === 1 || $state === 2) && $out[18] < 0) {
                $out[18] = $at;
            }
            if ($state === 3 && $out[19] < 0) {
                $out[19] = $at;
            }
            $row = $case | $source << 2 | $panes << 4 | $target << 8 | $flags << 13 | $state << 15 | $started << 18 | $running << 23 | min(\strlen($mode), 255) << 39;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[17] = $remaining;
        $out[20] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust plan, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, int|string>  $modes
     * @param  array<array-key, int>  $requested
     * @param  array<array-key, int>  $active
     * @return list<int>|null
     */
    public static function w63TmuxModePlanBatchNative(array $modes, array $requested, array $active, int $capacity = 0): ?array
    {
        $count = \count($modes);
        if ($count > self::W63_MAX_ITEMS) {
            return null;
        }
        [$joined, $twin] = self::w63Strings(array_values($modes));
        $ints = pack('q*', ...self::w63Column($requested, $count), ...self::w63Column($active, $count));
        $words = self::W63_TMUX_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w63Call($words, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w636_tmux_mode_batch($joined, \strlen($joined), $twin, \strlen($twin), $ints, \strlen($ints), $count, $capacity, $out, $words));
    }

    /**
     * Route the Wave 63.6 batch plan through Rust for the rest of the process.
     */
    public static function enableW63RustFfi(): void
    {
        self::w63RustFfiState('set', true);
    }

    /**
     * Route the Wave 63.6 batch plan through PHP for the rest of the process.
     */
    public static function disableW63RustFfi(): void
    {
        self::w63RustFfiState('set', false);
    }

    /**
     * Restore the default Wave 63.6 routing and drop the cached binding and scratch buffer.
     */
    public static function resetW63RustFfi(): void
    {
        self::w63RustFfiState('reset');
    }

    public static function usesW63RustFfi(): bool
    {
        $forced = self::w63RustFfiState();

        return \is_bool($forced) ? $forced : self::W63_RUST_FFI_DEFAULT;
    }

    /**
     * Run `$callback` with the Wave 63.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW63RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w63RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w63RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 63.6 routing and binding in function statics (enums cannot declare properties). No `$op` returns
     * the forced routing (null for the default); `'set'` replaces it with `$val` and returns the previous
     * value; `'reset'` clears the routing, binding and buffer; `'binding'` returns the cached binding and an
     * output buffer of at least `$val` values, or null.
     */
    private static function w63RustFfiState(?string $op = null, mixed $val = null): mixed
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
        if ($op === 'reset') {
            $enabled = $ffi = $buffer = null;
            $capacity = 0;

            return null;
        }
        if ($op !== 'binding') {
            return $enabled;
        }
        $fields = \is_int($val) ? $val : 0;
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w636', self::W63_TMUX_FFI_CDEF) ?? false;
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
     * The batch's `$fields` output words from one Wave 63.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w63Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w63RustFfiState('binding', $fields);
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
    private static function w63Strings(array $items): array
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
    private static function w63Column(array $values, int $count, int|string $pad = 0): array
    {
        $values = array_values($values);

        return \count($values) === $count ? $values : array_pad(\array_slice($values, 0, $count), $count, $pad);
    }
}
