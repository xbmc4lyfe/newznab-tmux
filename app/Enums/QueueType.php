<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

enum QueueType: int
{
    public const string FFI_CDEF = SignupError::FFI_CDEF;

    private const int FFI_KIND = 2;

    private const int DECLINE = -2147483648;

    case NONE = 0;
    case SABNZBD = 1;
    case NZBGET = 2;

    /** Opt-in only; see the paired measurements in RustSignupRoleQueueEnumComposerParityTest. */
    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::rustFfiToggle($useRustFfi);
    }

    public static function usesRustFfi(): bool
    {
        return self::rustFfiToggle();
    }

    public static function resetRustFfi(): void
    {
        self::rustFfiToggle(false);
        self::ffi(true);
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::ffi(true, $ffi);
    }

    public function label(): string
    {
        return self::usesRustFfi() ? $this->labelNative() : $this->labelPhp();
    }

    public function labelPhp(): string
    {
        return match ($this) {
            self::NONE => 'None',
            self::SABNZBD => 'SABnzbd',
            self::NZBGET => 'NZBGet',
        };
    }

    public function labelNative(): string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return $this->labelPhp();
        }

        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, 64, $cap);
            if ($scratch === null) {
                return $this->labelPhp();
            }
            $written = self::call($ffi, 'nntmux_signup_role_queue_label', self::FFI_KIND, $this->value, $scratch, $cap);
            if (\is_int($written) && $written < 0 && $written > self::DECLINE) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return $this->labelPhp();
                }
                $written = self::call($ffi, 'nntmux_signup_role_queue_label', self::FFI_KIND, $this->value, $scratch, $cap);
            }
            if (\is_int($written) && $written > 0 && $written <= $cap) {
                return FFI::string($scratch, $written);
            }
        } catch (Throwable) {
        }

        return $this->labelPhp();
    }

    /**
     * Resolve a case from its exact display label.
     */
    public static function tryFromLabel(string $label): ?self
    {
        return self::usesRustFfi() ? self::tryFromLabelNative($label) : self::tryFromLabelPhp($label);
    }

    public static function tryFromLabelPhp(string $label): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->labelPhp() === $label) {
                return $case;
            }
        }

        return null;
    }

    public static function tryFromLabelNative(string $label): ?self
    {
        $ffi = self::ffi();
        $slot = $ffi === null ? null : self::valueSlot($ffi);
        if ($ffi !== null && $slot !== null) {
            try {
                $rc = self::call($ffi, 'nntmux_signup_role_queue_resolve_label', self::FFI_KIND, $label, \strlen($label), $slot);
                if ($rc === 0) {
                    return null;
                }
                if ($rc === 1) {
                    $case = self::tryFrom((int) $slot[0]);
                    if ($case !== null) {
                        return $case;
                    }
                }
            } catch (Throwable) {
            }
        }

        return self::tryFromLabelPhp($label);
    }

    private static function call(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private static function valueSlot(FFI $ffi): ?CData
    {
        static $slot = null;
        static $owner = null;
        if ($owner !== $ffi || $slot === null) {
            try {
                $slot = $ffi->new('int64_t[1]');
                $owner = $ffi;
            } catch (Throwable) {
                return null;
            }
        }

        return $slot;
    }

    private static function rustFfiToggle(?bool $set = null): bool
    {
        static $enabled = false;
        if ($set !== null) {
            $enabled = $set;
        }

        return $enabled;
    }

    private static function ffi(bool $reset = false, FFI|false|null $override = null): ?FFI
    {
        static $ffi = null;
        if ($reset) {
            $ffi = $override;

            return null;
        }
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false;
        }

        return $ffi === false ? null : $ffi;
    }

    // Rust is the default only while the paired W62.6 benchmark clears 1.5x on all four metrics.
    public const bool W62_RUST_FFI_DEFAULT = true;

    private const string W62_QUEUE_FFI_CDEF = <<<'C'
        int32_t nntmux_w626_queue_type_batch(const char *strings, size_t strings_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    public const int W62_QUEUE_HEADER = 19;

    private const int W62_MAX_ITEMS = 1_048_576;

    /** Shortest requested timeout honoured, in seconds. */
    private const int W62_TIMEOUT_FLOOR = 5;

    /** Exact token => `case | source << 2`. */
    private const array W62_TOKENS = ['0' => 0, '1' => 1, '2' => 2, 'NONE' => 4, 'SABNZBD' => 5, 'NZBGET' => 6, 'None' => 8, 'SABnzbd' => 9, 'NZBGet' => 10];

    /** Lowercased trimmed alias => case. */
    private const array W62_ALIASES = [
        'none' => 0, 'off' => 0, 'disabled' => 0, 'no' => 0,
        'sabnzbd' => 1, 'sab' => 1, 'sabnzbd+' => 1,
        'nzbget' => 2, 'nzb-get' => 2, 'nzb_get' => 2,
    ];

    /** Default and maximum concurrency, default and maximum timeout seconds per case. */
    private const array W62_POLICY = [[0, 0, 0, 0], [4, 16, 120, 900], [8, 32, 90, 600], [0, 0, 0, 0]];

    /**
     * Plan download queue workers in order. Types resolve by backing value (source 0), case name (1), exact
     * label (2), an alias of `strtolower(trim($type))` (3), else unknown case 3 (4). The Horizon supervisor is
     * the case for SABnzbd (1) and NZBGet (2), else 0. A dispatchable type takes its default concurrency
     * (SABnzbd 4, NZBGet 8) or timeout (120, 90 seconds) for a request at or below 0, else the request capped to
     * its maximum (16, 32 workers; 900, 600 seconds); timeouts also rise to 5 seconds. Flags: 1 unknown type, 2
     * concurrency capped, 4 timeout capped, 8 timeout raised.
     *
     * Layout: `[0]` count, `[1..4]` per case, `[5..9]` per source, `[10..12]` per supervisor, `[13]`
     * dispatchable, `[14]` total concurrency, `[15]` longest timeout, `[16]` adjusted requests, `[17]` first
     * unknown index (-1), `[18]` FNV-1a digest; then per queue `case | source << 2 | supervisor << 5 | flags << 7
     * | dispatchable << 11 | concurrency << 12 | timeout << 18 | min(token bytes, 255) << 28`.
     *
     * @param  array<array-key, int|string>  $types
     * @param  array<array-key, int>  $concurrency
     * @param  array<array-key, int>  $timeouts
     * @return list<int>
     */
    public static function planQueueTypeBatch(array $types, array $concurrency, array $timeouts): array
    {
        return (self::usesW62RustFfi() ? self::planQueueTypeBatchNative($types, $concurrency, $timeouts) : null)
            ?? self::planQueueTypeBatchPhp($types, $concurrency, $timeouts);
    }

    /**
     * @param  array<array-key, int|string>  $types
     * @param  array<array-key, int>  $concurrency
     * @param  array<array-key, int>  $timeouts
     * @return list<int>
     */
    public static function planQueueTypeBatchPhp(array $types, array $concurrency, array $timeouts): array
    {
        $types = array_values($types);
        $count = \count($types);
        $concurrency = self::w62Column($concurrency, $count);
        $timeouts = self::w62Column($timeouts, $count);
        $out = array_fill(0, self::W62_QUEUE_HEADER, 0);
        $out[0] = $count;
        $out[17] = -1;
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($types as $at => $type) {
            $type = (string) $type;
            $code = self::W62_TOKENS[$type] ?? null;
            if ($code === null) {
                $case = self::W62_ALIASES[strtolower(trim($type))] ?? 3;
                $source = $case === 3 ? 4 : 3;
            } else {
                $case = $code & 3;
                $source = $code >> 2;
            }
            $dispatchable = $case === 1 || $case === 2 ? 1 : 0;
            $supervisor = $dispatchable === 1 ? $case : 0;
            $workers = $timeout = 0;
            $flags = $case === 3 ? 1 : 0;
            if ($dispatchable === 1) {
                [$defaultWorkers, $maxWorkers, $defaultTimeout, $maxTimeout] = self::W62_POLICY[$case];
                $requested = (int) $concurrency[$at];
                $workers = $requested <= 0 ? $defaultWorkers : min($requested, $maxWorkers);
                $flags |= ($requested > $maxWorkers ? 2 : 0);
                $requested = (int) $timeouts[$at];
                $timeout = $requested <= 0 ? $defaultTimeout : min(max($requested, self::W62_TIMEOUT_FLOOR), $maxTimeout);
                $flags |= ($requested > $maxTimeout ? 4 : 0) | ($requested > 0 && $requested < self::W62_TIMEOUT_FLOOR ? 8 : 0);
            }
            $out[1 + $case]++;
            $out[5 + $source]++;
            $out[10 + $supervisor]++;
            $out[13] += $dispatchable;
            $out[14] += $workers;
            $out[15] = max($out[15], $timeout);
            $out[16] += ($flags & 14) !== 0 ? 1 : 0;
            if ($case === 3 && $out[17] < 0) {
                $out[17] = $at;
            }
            $row = $case | $source << 2 | $supervisor << 5 | $flags << 7 | $dispatchable << 11 | $workers << 12 | $timeout << 18 | min(\strlen($type), 255) << 28;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[18] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust plan, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, int|string>  $types
     * @param  array<array-key, int>  $concurrency
     * @param  array<array-key, int>  $timeouts
     * @return list<int>|null
     */
    public static function planQueueTypeBatchNative(array $types, array $concurrency, array $timeouts): ?array
    {
        $count = \count($types);
        if ($count > self::W62_MAX_ITEMS) {
            return null;
        }
        [$joined, $twin] = self::w62Strings(array_values($types));
        $ints = pack('q*', ...self::w62Column($concurrency, $count), ...self::w62Column($timeouts, $count));
        $words = self::W62_QUEUE_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w62Call($words, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w626_queue_type_batch($joined, \strlen($joined), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $words));
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
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w626', self::W62_QUEUE_FFI_CDEF) ?? false;
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
