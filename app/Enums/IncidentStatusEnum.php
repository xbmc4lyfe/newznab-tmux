<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

enum IncidentStatusEnum: string
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_incident_status_label(const char *value_ptr, size_t value_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_incident_status_values_nul(size_t *out_count, char *out_buf, size_t out_cap);
        C;

    case Investigating = 'investigating';
    case Identified = 'identified';
    case Monitoring = 'monitoring';
    case Resolved = 'resolved';

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

    public function label(): string
    {
        return self::usesRustFfi() ? $this->labelNative() : $this->labelPhp();
    }

    public function labelPhp(): string
    {
        return match ($this) {
            self::Investigating => 'Investigating',
            self::Identified => 'Identified',
            self::Monitoring => 'Monitoring',
            self::Resolved => 'Resolved',
        };
    }

    public function labelNative(): string
    {
        $ffi = self::categorizeFfi();
        if ($ffi === null) {
            return $this->labelPhp();
        }

        $val = $this->value;
        $len = \strlen($val);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 32, $cap);
        if ($scratch === null) {
            return $this->labelPhp();
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_incident_status_label($val, $len, $scratch, $cap);
            if (\is_int($written) && $written < -2) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return $this->labelPhp();
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_incident_status_label($val, $len, $scratch, $cap);
            }
            if (! \is_int($written) || $written <= 0 || $written > $cap) {
                return $this->labelPhp();
            }

            return FFI::string($scratch, $written);
        } catch (Throwable) {
            return $this->labelPhp();
        }
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return self::usesRustFfi() ? self::valuesNative() : self::valuesPhp();
    }

    /**
     * @return array<int, string>
     */
    public static function valuesPhp(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<int, string>
     */
    public static function valuesNative(): array
    {
        $ffi = self::categorizeFfi();
        $outCount = $ffi === null ? null : self::countSlot($ffi);
        if ($ffi === null || $outCount === null) {
            return self::valuesPhp();
        }

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 64, $cap);
        if ($scratch === null) {
            return self::valuesPhp();
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_incident_status_values_nul($outCount, $scratch, $cap);
            if (\is_int($written) && $written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return self::valuesPhp();
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_incident_status_values_nul($outCount, $scratch, $cap);
            }
            if (! \is_int($written) || $written <= 0 || $written > $cap) {
                return self::valuesPhp();
            }

            /** @var int $expected */
            $expected = $outCount[0];
            $parts = explode("\0", FFI::string($scratch, $written));

            return \count($parts) === $expected ? $parts : self::valuesPhp();
        } catch (Throwable) {
            return self::valuesPhp();
        }
    }

    private static function countSlot(FFI $ffi): ?CData
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

    // Rust is the default only while the paired W62.6 benchmark clears 1.5x on all four metrics.
    public const bool W62_RUST_FFI_DEFAULT = true;

    private const string W62_STATUS_FFI_CDEF = <<<'C'
        int32_t nntmux_w626_incident_status_batch(const char *strings, size_t strings_len, const char *twin, size_t twin_len, size_t count, int64_t *out, size_t out_cap);
        C;

    public const int W62_STATUS_HEADER = 22;

    private const int W62_MAX_ITEMS = 1_048_576;

    /** Exact token => `case | source << 3`. */
    private const array W62_TOKENS = [
        'investigating' => 0, 'identified' => 1, 'monitoring' => 2, 'resolved' => 3,
        'Investigating' => 8, 'Identified' => 9, 'Monitoring' => 10, 'Resolved' => 11,
    ];

    /** Lowercased trimmed alias => case. */
    private const array W62_ALIASES = [
        'investigating' => 0, 'open' => 0, 'new' => 0, 'triage' => 0, 'acknowledged' => 0,
        'identified' => 1, 'diagnosed' => 1, 'fixing' => 1, 'root-caused' => 1,
        'monitoring' => 2, 'watching' => 2, 'mitigated' => 2, 'recovering' => 2,
        'resolved' => 3, 'closed' => 3, 'fixed' => 3, 'done' => 3, 'completed' => 3,
    ];

    /**
     * Plan incident status changes in order. Statuses and targets resolve by backing value (source 0), case
     * name (1), an alias of `strtolower(trim($token))` (2), else unknown case 4 (3); a blank current status is
     * unknown and a blank target (case 5) requests nothing. Transitions: 0 none (blank or same target), 1
     * forward, 2 allowed regression (back to an earlier active stage, or reopening a resolved incident to
     * investigating), 3 forbidden (resolved back to identified or monitoring), 4 unknown target, 5 unknown
     * status. The effective case is the target after a forward or regression transition, else the current one.
     *
     * Layout: `[0]` count, `[1..5]` per case, `[6..9]` per source, `[10..15]` per transition, `[16]` active and
     * `[17]` resolved, `[18]` effectively active, `[19]` reopened, `[20]` first forbidden (-1), `[21]` FNV-1a
     * digest; then per incident `case | source << 3 | target << 5 | target source << 8 | transition << 10 |
     * active << 13 | effective << 14 | effectively active << 17 | min(status bytes, 255) << 18 | min(target
     * bytes, 255) << 26`.
     *
     * @param  array<array-key, int|string>  $statuses
     * @param  array<array-key, int|string>  $targets
     * @return list<int>
     */
    public static function planIncidentStatusBatch(array $statuses, array $targets): array
    {
        return (self::usesW62RustFfi() ? self::planIncidentStatusBatchNative($statuses, $targets) : null)
            ?? self::planIncidentStatusBatchPhp($statuses, $targets);
    }

    /**
     * @param  array<array-key, int|string>  $statuses
     * @param  array<array-key, int|string>  $targets
     * @return list<int>
     */
    public static function planIncidentStatusBatchPhp(array $statuses, array $targets): array
    {
        $statuses = array_values($statuses);
        $count = \count($statuses);
        $targets = self::w62Column($targets, $count, '');
        $out = array_fill(0, self::W62_STATUS_HEADER, 0);
        $out[0] = $count;
        $out[20] = -1;
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($statuses as $at => $status) {
            $status = (string) $status;
            [$case, $source] = self::w62Resolve($status);
            if ($case === 5) {
                $case = 4;
            }
            $target = (string) $targets[$at];
            [$next, $nextSource] = self::w62Resolve($target);
            $transition = match (true) {
                $case === 4 => 5,
                $next === 5 || $next === $case => 0,
                $next === 4 => 4,
                $next > $case => 1,
                $case === 3 && $next !== 0 => 3,
                default => 2,
            };
            $effective = $transition === 1 || $transition === 2 ? $next : $case;
            $active = $case <= 2 ? 1 : 0;
            $effectiveActive = $effective <= 2 ? 1 : 0;
            $out[1 + $case]++;
            $out[6 + $source]++;
            $out[10 + $transition]++;
            $out[16] += $active;
            $out[17] += $case === 3 ? 1 : 0;
            $out[18] += $effectiveActive;
            $out[19] += $case === 3 && $transition === 2 ? 1 : 0;
            if ($transition === 3 && $out[20] < 0) {
                $out[20] = $at;
            }
            $row = $case | $source << 3 | $next << 5 | $nextSource << 8 | $transition << 10 | $active << 13 | $effective << 14 | $effectiveActive << 17
                | min(\strlen($status), 255) << 18 | min(\strlen($target), 255) << 26;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[21] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust plan, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, int|string>  $statuses
     * @param  array<array-key, int|string>  $targets
     * @return list<int>|null
     */
    public static function planIncidentStatusBatchNative(array $statuses, array $targets): ?array
    {
        $count = \count($statuses);
        if ($count > self::W62_MAX_ITEMS) {
            return null;
        }
        [$joined, $twin] = self::w62Strings([...array_values($statuses), ...self::w62Column($targets, $count, '')]);
        $words = self::W62_STATUS_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w62Call($words, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w626_incident_status_batch($joined, \strlen($joined), $twin, \strlen($twin), $count, $out, $words));
    }

    /**
     * Case (4 unknown, 5 blank) and source of one status token.
     *
     * @return array{0: int, 1: int}
     */
    private static function w62Resolve(string $token): array
    {
        $code = self::W62_TOKENS[$token] ?? null;
        if ($code !== null) {
            return [$code & 7, $code >> 3];
        }
        $lower = strtolower(trim($token));
        if ($lower === '') {
            return [5, 3];
        }
        $case = self::W62_ALIASES[$lower] ?? 4;

        return [$case, $case === 4 ? 3 : 2];
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
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w626', self::W62_STATUS_FFI_CDEF) ?? false;
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
