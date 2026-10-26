<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

enum IncidentImpactEnum: string
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_incident_impact_label(const char *value_ptr, size_t value_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_incident_impact_values_nul(size_t *out_count, char *out_buf, size_t out_cap);
        C;

    case None = 'none';
    case Minor = 'minor';
    case Major = 'major';
    case Critical = 'critical';

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
            self::None => 'None',
            self::Minor => 'Minor',
            self::Major => 'Major',
            self::Critical => 'Critical',
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
            $written = $ffi->nntmux_incident_impact_label($val, $len, $scratch, $cap);
            if (\is_int($written) && $written < -2) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return $this->labelPhp();
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_incident_impact_label($val, $len, $scratch, $cap);
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
            $written = $ffi->nntmux_incident_impact_values_nul($outCount, $scratch, $cap);
            if (\is_int($written) && $written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return self::valuesPhp();
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_incident_impact_values_nul($outCount, $scratch, $cap);
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

    private const string W62_IMPACT_FFI_CDEF = <<<'C'
        int32_t nntmux_w626_incident_impact_batch(const char *strings, size_t strings_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    public const int W62_IMPACT_HEADER = 20;

    private const int W62_MAX_ITEMS = 1_048_576;

    private const int W62_AGE_CAP = 2_147_483_647;

    /** Exact token => `case | source << 3`. */
    private const array W62_TOKENS = ['none' => 0, 'minor' => 1, 'major' => 2, 'critical' => 3, 'None' => 8, 'Minor' => 9, 'Major' => 10, 'Critical' => 11];

    /** Lowercased trimmed alias => case. */
    private const array W62_ALIASES = [
        'none' => 0, 'ok' => 0, 'operational' => 0, 'normal' => 0, '0' => 0,
        'minor' => 1, 'low' => 1, 'degraded' => 1, 'sev3' => 1, 'p3' => 1, '1' => 1,
        'major' => 2, 'high' => 2, 'partial' => 2, 'partial-outage' => 2, 'sev2' => 2, 'p2' => 2, '2' => 2,
        'critical' => 3, 'severe' => 3, 'outage' => 3, 'major-outage' => 3, 'down' => 3, 'sev1' => 3, 'p1' => 3, '3' => 3,
    ];

    /** Response target minutes per case (unknown escalates like major). */
    private const array W62_TARGETS = [0, 240, 60, 15, 60];

    /**
     * Plan incident impacts against their ages in minutes, in order. Impacts resolve by backing value (source 0),
     * case name (1), an alias of `strtolower(trim($impact))` (2), else unknown case 4 (3). Ages clamp to
     * 0..2^31-1. Escalation is 0 within the case's response target (none, minor, major, critical, unknown: 0,
     * 240, 60, 15, 60 minutes; always 0 for none), 1 past it, 2 past twice and 3 past four times it; notify
     * when major, critical or escalated at least twice.
     *
     * Layout: `[0]` count, `[1..5]` per case, `[6..9]` per source, `[10..13]` per escalation, `[14]` breaches,
     * `[15]` notifications, `[16]` first breach (-1), `[17]` highest known severity (-1), `[18]` total age,
     * `[19]` FNV-1a digest; then per incident `case | source << 3 | escalation << 5 | breach << 7 | notify << 8
     * | target << 9 | age << 18 | min(token bytes, 255) << 49`.
     *
     * @param  array<array-key, int|string>  $impacts
     * @param  array<array-key, int>  $ageMinutes
     * @return list<int>
     */
    public static function planIncidentImpactBatch(array $impacts, array $ageMinutes): array
    {
        return (self::usesW62RustFfi() ? self::planIncidentImpactBatchNative($impacts, $ageMinutes) : null)
            ?? self::planIncidentImpactBatchPhp($impacts, $ageMinutes);
    }

    /**
     * @param  array<array-key, int|string>  $impacts
     * @param  array<array-key, int>  $ageMinutes
     * @return list<int>
     */
    public static function planIncidentImpactBatchPhp(array $impacts, array $ageMinutes): array
    {
        $impacts = array_values($impacts);
        $count = \count($impacts);
        $ages = self::w62Column($ageMinutes, $count);
        $out = array_fill(0, self::W62_IMPACT_HEADER, 0);
        $out[0] = $count;
        $out[16] = -1;
        $out[17] = -1;
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($impacts as $at => $impact) {
            $impact = (string) $impact;
            $code = self::W62_TOKENS[$impact] ?? null;
            if ($code === null) {
                $case = self::W62_ALIASES[strtolower(trim($impact))] ?? 4;
                $source = $case === 4 ? 3 : 2;
            } else {
                $case = $code & 7;
                $source = $code >> 3;
            }
            $target = self::W62_TARGETS[$case];
            $age = min(max((int) $ages[$at], 0), self::W62_AGE_CAP);
            $escalation = match (true) {
                $target === 0 || $age <= $target => 0,
                $age <= 2 * $target => 1,
                $age <= 4 * $target => 2,
                default => 3,
            };
            $breach = $escalation > 0 ? 1 : 0;
            $notify = $case === 2 || $case === 3 || $escalation >= 2 ? 1 : 0;
            $out[1 + $case]++;
            $out[6 + $source]++;
            $out[10 + $escalation]++;
            $out[14] += $breach;
            $out[15] += $notify;
            if ($breach === 1 && $out[16] < 0) {
                $out[16] = $at;
            }
            if ($case < 4 && $case > $out[17]) {
                $out[17] = $case;
            }
            $out[18] += $age;
            $row = $case | $source << 3 | $escalation << 5 | $breach << 7 | $notify << 8 | $target << 9 | $age << 18 | min(\strlen($impact), 255) << 49;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[19] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust plan, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, int|string>  $impacts
     * @param  array<array-key, int>  $ageMinutes
     * @return list<int>|null
     */
    public static function planIncidentImpactBatchNative(array $impacts, array $ageMinutes): ?array
    {
        $count = \count($impacts);
        if ($count > self::W62_MAX_ITEMS) {
            return null;
        }
        [$joined, $twin] = self::w62Strings(array_values($impacts));
        $ints = pack('q*', ...self::w62Column($ageMinutes, $count));
        $words = self::W62_IMPACT_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w62Call($words, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w626_incident_impact_batch($joined, \strlen($joined), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $words));
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
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w626', self::W62_IMPACT_FFI_CDEF) ?? false;
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
