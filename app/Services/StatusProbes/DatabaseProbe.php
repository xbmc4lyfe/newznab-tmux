<?php

declare(strict_types=1);

namespace App\Services\StatusProbes;

use App\Enums\IncidentImpactEnum;
use App\Services\StatusProbes\Contracts\ServiceProbeInterface;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class DatabaseProbe implements ServiceProbeInterface
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_database_probe_evaluate_connected(int64_t start_ns, int64_t end_ns, int64_t *out_elapsed_ms, char *out_buf, size_t out_cap);
        ssize_t nntmux_database_probe_format_unreachable(const char *error_ptr, size_t error_len, char *out_buf, size_t out_cap);
        C;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private static ?CData $elapsedSlot = null;

    private static ?FFI $slotOwner = null;

    public static function enableRustFfi(): void
    {
        self::setUseRustFfi(true);
    }

    public static function disableRustFfi(): void
    {
        self::setUseRustFfi(false);
    }

    public static function resetRustFfi(): void
    {
        self::setUseRustFfi(null);
    }

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use ?? false;
        if ($use === null) {
            self::$ffi = null;
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::$ffi = $override;
    }

    public static function categorizeFfi(bool $reset = false, FFI|false|null $override = null): ?FFI
    {
        if ($reset) {
            self::$ffi = $override;
            if ($override !== null) {
                return self::$ffi === false ? null : self::$ffi;
            }
        }
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('categorize', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    public function identifier(): string
    {
        return 'database';
    }

    public function probe(): ProbeResult
    {
        try {
            $start = hrtime(true);
            DB::connection()->getPdo();
            DB::select('SELECT 1');
            $end = hrtime(true);

            return self::evaluateConnectedResult($start, $end);
        } catch (Throwable $e) {
            return self::formatUnreachableResult($e->getMessage());
        }
    }

    public static function evaluateConnectedResult(int $startNs, int $endNs): ProbeResult
    {
        return self::$useRustFfi
            ? self::evaluateConnectedResultNative($startNs, $endNs)
            : self::evaluateConnectedResultPhp($startNs, $endNs);
    }

    public static function evaluateConnectedResultPhp(int $startNs, int $endNs): ProbeResult
    {
        $elapsed = (int) (($endNs - $startNs) / 1_000_000);

        return new ProbeResult(
            ok: true,
            responseTimeMs: $elapsed,
            impact: null,
            reason: 'Connected',
        );
    }

    public static function evaluateConnectedResultNative(int $startNs, int $endNs): ProbeResult
    {
        $ffi = self::categorizeFfi();
        if ($ffi === null || ! self::ensureSlots($ffi) || self::$elapsedSlot === null) {
            return self::evaluateConnectedResultPhp($startNs, $endNs);
        }

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 32, $cap);
        if ($scratch === null) {
            return self::evaluateConnectedResultPhp($startNs, $endNs);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_database_probe_evaluate_connected(
                $startNs,
                $endNs,
                self::$elapsedSlot,
                $scratch,
                $cap,
            );
            if (\is_int($written) && $written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return self::evaluateConnectedResultPhp($startNs, $endNs);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_database_probe_evaluate_connected(
                    $startNs,
                    $endNs,
                    self::$elapsedSlot,
                    $scratch,
                    $cap,
                );
            }
            if (! \is_int($written) || $written <= 0 || $written > $cap) {
                return self::evaluateConnectedResultPhp($startNs, $endNs);
            }

            /** @var int $elapsed */
            $elapsed = self::$elapsedSlot[0];

            return new ProbeResult(
                ok: true,
                responseTimeMs: $elapsed,
                impact: null,
                reason: FFI::string($scratch, $written),
            );
        } catch (Throwable) {
            return self::evaluateConnectedResultPhp($startNs, $endNs);
        }
    }

    public static function formatUnreachableResult(string $errorMessage): ProbeResult
    {
        return self::$useRustFfi
            ? self::formatUnreachableResultNative($errorMessage)
            : self::formatUnreachableResultPhp($errorMessage);
    }

    public static function formatUnreachableResultPhp(string $errorMessage): ProbeResult
    {
        return new ProbeResult(
            ok: false,
            responseTimeMs: 0,
            impact: IncidentImpactEnum::Critical,
            reason: 'Database unreachable: '.Str::limit($errorMessage, 120),
        );
    }

    public static function formatUnreachableResultNative(string $errorMessage): ProbeResult
    {
        $ffi = self::categorizeFfi();
        if ($ffi === null) {
            return self::formatUnreachableResultPhp($errorMessage);
        }

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 256, $cap);
        if ($scratch === null) {
            return self::formatUnreachableResultPhp($errorMessage);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_database_probe_format_unreachable(
                $errorMessage,
                \strlen($errorMessage),
                $scratch,
                $cap,
            );
            if (\is_int($written) && $written < -2) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return self::formatUnreachableResultPhp($errorMessage);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_database_probe_format_unreachable(
                    $errorMessage,
                    \strlen($errorMessage),
                    $scratch,
                    $cap,
                );
            }
            if (\is_int($written) && $written > 0 && $written <= $cap) {
                return new ProbeResult(
                    ok: false,
                    responseTimeMs: 0,
                    impact: IncidentImpactEnum::Critical,
                    reason: FFI::string($scratch, $written),
                );
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return self::formatUnreachableResultPhp($errorMessage);
    }

    private static function ensureSlots(FFI $ffi): bool
    {
        if (self::$slotOwner !== $ffi || self::$elapsedSlot === null) {
            try {
                self::$elapsedSlot = $ffi->new('int64_t[1]');
                self::$slotOwner = $ffi;
            } catch (Throwable) {
                return false;
            }
        }

        return true;
    }

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W50 parity test). */
    private const W50_RUST_FFI_DEFAULT = true;

    private const string W50_FFI_CDEF = 'ssize_t nntmux_w501_database_probe_summarize_batch(const char *starts, size_t starts_len, const char *ends, size_t ends_len, const char *errors, size_t errors_len, const char *nulls, size_t nulls_len, size_t count, int64_t *out, size_t out_cap);';

    public static function usesW50RustFfi(): bool
    {
        $enabled = self::w50RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W50_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 50 batch routed to Rust or PHP, then restores the routing. */
    public static function withW50RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w50RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w50RustFfiState('set', $previous);
        }
    }

    /**
     * Batch summary of probe samples. A null error is a connected sample timed by its
     * `hrtime(true)` start/end pair; otherwise the sample failed with that message. One word per
     * sample describes the ProbeResult `evaluateConnectedResult()` / `formatUnreachableResult()`
     * returns: `ok | impact << 1 | truncated << 4 | min(2047, strlen(reason)) << 5 |
     * responseTimeMs << 16` (impact codes: null 0, none 1, minor 2, major 3, critical 4;
     * `truncated` marks a message `Str::limit()` shortened).
     *
     * @param  list<int>  $startNs
     * @param  list<int>  $endNs
     * @param  list<string|null>  $errors
     * @return list<int>
     */
    public static function summarizeProbeSamples(array $startNs, array $endNs, array $errors): array
    {
        return self::usesW50RustFfi()
            ? self::summarizeProbeSamplesNative($startNs, $endNs, $errors)
            : self::summarizeProbeSamplesPhp($startNs, $endNs, $errors);
    }

    /**
     * @param  list<int>  $startNs
     * @param  list<int>  $endNs
     * @param  list<string|null>  $errors
     * @return list<int>
     */
    public static function summarizeProbeSamplesPhp(array $startNs, array $endNs, array $errors): array
    {
        self::w50Count($startNs, $endNs, $errors);
        $words = [];
        foreach ($errors as $i => $error) {
            $words[] = self::w50SampleWord($startNs[$i], $endNs[$i], $error);
        }

        return $words;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or a message holds a NUL byte,
     * and computes rows with non-ASCII messages (multibyte `Str::limit()` widths) in PHP.
     *
     * @param  list<int>  $startNs
     * @param  list<int>  $endNs
     * @param  list<string|null>  $errors
     * @return list<int>
     */
    public static function summarizeProbeSamplesNative(array $startNs, array $endNs, array $errors): array
    {
        $count = self::w50Count($startNs, $endNs, $errors);
        $ffi = $count === 0 ? null : self::w50Ffi();
        if ($ffi === null) {
            return self::summarizeProbeSamplesPhp($startNs, $endNs, $errors);
        }
        $starts = pack('P*', ...$startNs);
        $ends = pack('P*', ...$endNs);
        $packed = implode("\0", $errors);
        $nulls = pack('V*', ...array_keys($errors, null, true));
        try {
            $words = self::w50Words($count, static fn (CData $out, int $cap): mixed => $ffi->nntmux_w501_database_probe_summarize_batch( // @phpstan-ignore method.notFound
                $starts, \strlen($starts), $ends, \strlen($ends), $packed, \strlen($packed), $nulls, \strlen($nulls), $count, $out, $cap));
        } catch (Throwable) {
            $words = null;
        }
        if ($words === null) {
            return self::summarizeProbeSamplesPhp($startNs, $endNs, $errors);
        }
        if (\in_array(PHP_INT_MIN, $words, true)) {
            foreach (array_keys($words, PHP_INT_MIN, true) as $i) {
                $words[$i] = self::w50SampleWord($startNs[$i], $endNs[$i], $errors[$i]);
            }
        }

        return $words;
    }

    private static function w50SampleWord(int $startNs, int $endNs, ?string $error): int
    {
        return $error === null
            ? self::evaluateConnectedResultPhp($startNs, $endNs)->w50Word()
            : self::formatUnreachableResultPhp($error)->w50Word(mb_strwidth($error, 'UTF-8') > 120);
    }

    /**
     * @param  array<mixed>  ...$columns
     */
    private static function w50Count(array ...$columns): int
    {
        $count = \count($columns[0]);
        foreach ($columns as $column) {
            if (\count($column) !== $count) {
                throw new InvalidArgumentException('Status probe batch columns must have the same length.');
            }
        }

        return $count;
    }

    private static function w50Ffi(): ?FFI
    {
        $ffi = self::w50RustFfiState('ffi');

        return $ffi instanceof FFI ? $ffi : null;
    }

    /**
     * Wave 50 routing override (`set`), lazily resolved binding (`ffi`) and reusable `int64_t`
     * output buffer (`out`, sized for `$val` words), kept in function-local statics.
     */
    private static function w50RustFfiState(?string $op = null, mixed $val = null): mixed
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
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('status_probes_w50', self::W50_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                if (! $ffi instanceof FFI || ! \is_int($val)) {
                    return null;
                }
                if (! $out instanceof CData || $outCap < $val) {
                    $outCap = max(512, $val * 2);
                    $out = $ffi->new("int64_t[{$outCap}]");
                }

                return [$out, $outCap];
            default:
                return $enabled;
        }
    }

    /**
     * Copies `$words` output words out of the reusable buffer after `$call` filled it.
     *
     * @param  callable(CData, int): mixed  $call  Receives the buffer and its capacity in words.
     * @return list<int>|null
     */
    private static function w50Words(int $words, callable $call): ?array
    {
        $buffer = self::w50RustFfiState('out', $words);
        if (! \is_array($buffer) || ! $buffer[0] instanceof CData || $call($buffer[0], $buffer[1]) !== $words) {
            return null;
        }
        $decoded = unpack('P*', FFI::string($buffer[0], $words * 8));

        /** @var list<int>|null */
        return \is_array($decoded) ? array_values($decoded) : null;
    }
}
