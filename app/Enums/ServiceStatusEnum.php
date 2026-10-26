<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Current health of a monitored service (stored in service_statuses.status).
 */
enum ServiceStatusEnum: string
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_service_status_label(const char *value_ptr, size_t value_len, char *out_buf, size_t out_cap);
        int32_t nntmux_service_status_severity(const char *value_ptr, size_t value_len);
        ssize_t nntmux_service_status_values_nul(size_t *out_count, char *out_buf, size_t out_cap);
        C;

    case Operational = 'operational';
    case Degraded = 'degraded';
    case PartialOutage = 'partial_outage';
    case MajorOutage = 'major_outage';
    case Maintenance = 'maintenance';

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
            self::Operational => 'Operational',
            self::Degraded => 'Degraded',
            self::PartialOutage => 'Partial outage',
            self::MajorOutage => 'Major outage',
            self::Maintenance => 'Maintenance',
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
            $written = $ffi->nntmux_service_status_label($val, $len, $scratch, $cap);
            if (\is_int($written) && $written < -2) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return $this->labelPhp();
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_service_status_label($val, $len, $scratch, $cap);
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
     * Higher value = worse condition for aggregation (worst wins).
     */
    public function severity(): int
    {
        return self::usesRustFfi() ? $this->severityNative() : $this->severityPhp();
    }

    public function severityPhp(): int
    {
        return match ($this) {
            self::Operational => 0,
            self::Degraded => 1,
            self::Maintenance => 2,
            self::PartialOutage => 3,
            self::MajorOutage => 4,
        };
    }

    public function severityNative(): int
    {
        $ffi = self::categorizeFfi();
        if ($ffi === null) {
            return $this->severityPhp();
        }

        $val = $this->value;
        try {
            // @phpstan-ignore method.notFound
            $sev = $ffi->nntmux_service_status_severity($val, \strlen($val));
            if (\is_int($sev) && $sev >= 0 && $sev <= 4) {
                return $sev;
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return $this->severityPhp();
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
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 128, $cap);
        if ($scratch === null) {
            return self::valuesPhp();
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_service_status_values_nul($outCount, $scratch, $cap);
            if (\is_int($written) && $written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return self::valuesPhp();
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_service_status_values_nul($outCount, $scratch, $cap);
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

    // Rust is the default only while the paired W63.6 benchmark clears 1.5x on all four metrics.
    public const bool W63_RUST_FFI_DEFAULT = true;

    private const string W63_SERVICE_FFI_CDEF = <<<'C'
        int32_t nntmux_w636_service_status_batch(const char *strings, size_t strings_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t window, int64_t *out, size_t out_cap);
        C;

    public const int W63_SERVICE_HEADER = 19;

    private const int W63_MAX_ITEMS = 1_048_576;

    /** Duration and window clamp (2^24 - 1 seconds). */
    private const int W63_SERVICE_CAP = 16_777_215;

    /** Uptime window used for a window at or below 0. */
    private const int W63_SERVICE_WINDOW = 86_400;

    /** A degraded service opens an incident after this many seconds. */
    private const int W63_DEGRADED_INCIDENT = 1_800;

    /** `severityPhp()` per case; unknown carries 7. */
    private const array W63_SEVERITY = [0, 1, 3, 4, 2, 7];

    /** Exact token => `case | source << 3`. */
    private const array W63_SERVICE_TOKENS = [
        'operational' => 0, 'degraded' => 1, 'partial_outage' => 2, 'major_outage' => 3, 'maintenance' => 4,
        'Operational' => 8, 'Degraded' => 9, 'PartialOutage' => 10, 'Partial outage' => 10, 'MajorOutage' => 11, 'Major outage' => 11,
        'Maintenance' => 12,
    ];

    /** Lowercased trimmed alias => case. */
    private const array W63_SERVICE_ALIASES = [
        'operational' => 0, 'ok' => 0, 'up' => 0, 'healthy' => 0, 'green' => 0, 'normal' => 0, 'online' => 0,
        'degraded' => 1, 'slow' => 1, 'yellow' => 1, 'warning' => 1, 'degraded_performance' => 1, 'degraded-performance' => 1,
        'partial' => 2, 'partial_outage' => 2, 'partial-outage' => 2, 'partial outage' => 2, 'orange' => 2,
        'major' => 3, 'major_outage' => 3, 'major-outage' => 3, 'major outage' => 3, 'down' => 3, 'outage' => 3, 'red' => 3, 'offline' => 3,
        'maintenance' => 4, 'maint' => 4, 'scheduled' => 4, 'under_maintenance' => 4, 'under maintenance' => 4,
    ];

    /**
     * Plan monitored service health in order. Statuses resolve by backing value (source 0), case name or label
     * (1), an alias of `strtolower(trim($status))` (2), else unknown case 5 (3). Seconds-in-status and the
     * uptime window (at or below 0 means 86,400) clamp to 0..2^24-1. An incident is a partial or major outage,
     * or degradation longer than 1,800 seconds; stale is a known status held longer than the window. Downtime
     * is the window-capped duration of a major outage and half of it for a partial one; uptime ppm is
     * `intdiv((window - downtime) * 10^6, window)` (0 for unknown).
     *
     * Layout: `[0]` count, `[1..6]` per case, `[7..10]` per source, `[11]` worst known severity (-1), `[12]`
     * its first index (-1), `[13]` incidents, `[14]` stale, `[15]` total downtime, `[16]` mean uptime ppm over
     * known statuses (-1 when none), `[17]` available (operational or maintenance), `[18]` FNV-1a digest; then
     * per status `case | source << 3 | severity << 5 | incident << 8 | stale << 9 | ppm << 10 | duration << 30
     * | min(token bytes, 255) << 54`.
     *
     * @param  array<array-key, int|string>  $statuses
     * @param  array<array-key, int>  $durations
     * @return list<int>
     */
    public static function w63ServiceStatusPlanBatch(array $statuses, array $durations, int $window = self::W63_SERVICE_WINDOW): array
    {
        return (self::usesW63RustFfi() ? self::w63ServiceStatusPlanBatchNative($statuses, $durations, $window) : null)
            ?? self::w63ServiceStatusPlanBatchPhp($statuses, $durations, $window);
    }

    /**
     * @param  array<array-key, int|string>  $statuses
     * @param  array<array-key, int>  $durations
     * @return list<int>
     */
    public static function w63ServiceStatusPlanBatchPhp(array $statuses, array $durations, int $window = self::W63_SERVICE_WINDOW): array
    {
        $statuses = array_values($statuses);
        $count = \count($statuses);
        $durations = self::w63Column($durations, $count);
        $window = $window <= 0 ? self::W63_SERVICE_WINDOW : min($window, self::W63_SERVICE_CAP);
        $out = array_fill(0, self::W63_SERVICE_HEADER, 0);
        $out[0] = $count;
        $out[11] = $out[12] = -1;
        $ppmSum = $known = 0;
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($statuses as $at => $status) {
            $status = (string) $status;
            $code = self::W63_SERVICE_TOKENS[$status] ?? null;
            if ($code === null) {
                $case = self::W63_SERVICE_ALIASES[strtolower(trim($status))] ?? 5;
                $source = $case === 5 ? 3 : 2;
            } else {
                $case = $code & 7;
                $source = $code >> 3;
            }
            $severity = self::W63_SEVERITY[$case];
            $duration = max(0, min((int) $durations[$at], self::W63_SERVICE_CAP));
            $incident = $case === 2 || $case === 3 || ($case === 1 && $duration > self::W63_DEGRADED_INCIDENT) ? 1 : 0;
            $stale = $case < 5 && $duration > $window ? 1 : 0;
            $down = match ($case) {
                3 => min($duration, $window),
                2 => intdiv(min($duration, $window), 2),
                default => 0,
            };
            $ppm = $case < 5 ? intdiv(($window - $down) * 1_000_000, $window) : 0;
            $out[1 + $case]++;
            $out[7 + $source]++;
            if ($case < 5) {
                $known++;
                $ppmSum += $ppm;
                if ($severity > $out[11]) {
                    $out[11] = $severity;
                    $out[12] = $at;
                }
            }
            $out[13] += $incident;
            $out[14] += $stale;
            $out[15] += $down;
            $out[17] += $case === 0 || $case === 4 ? 1 : 0;
            $row = $case | $source << 3 | $severity << 5 | $incident << 8 | $stale << 9 | $ppm << 10 | $duration << 30 | min(\strlen($status), 255) << 54;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[16] = $known > 0 ? intdiv($ppmSum, $known) : -1;
        $out[18] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust plan, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, int|string>  $statuses
     * @param  array<array-key, int>  $durations
     * @return list<int>|null
     */
    public static function w63ServiceStatusPlanBatchNative(array $statuses, array $durations, int $window = self::W63_SERVICE_WINDOW): ?array
    {
        $count = \count($statuses);
        if ($count > self::W63_MAX_ITEMS) {
            return null;
        }
        [$joined, $twin] = self::w63Strings(array_values($statuses));
        $ints = pack('q*', ...self::w63Column($durations, $count));
        $words = self::W63_SERVICE_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w63Call($words, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w636_service_status_batch($joined, \strlen($joined), $twin, \strlen($twin), $ints, \strlen($ints), $count, $window, $out, $words));
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
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w636', self::W63_SERVICE_FFI_CDEF) ?? false;
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
