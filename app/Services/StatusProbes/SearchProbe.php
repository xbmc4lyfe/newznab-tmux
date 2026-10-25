<?php

declare(strict_types=1);

namespace App\Services\StatusProbes;

use App\Enums\IncidentImpactEnum;
use App\Services\Search\SearchService;
use App\Services\StatusProbes\Contracts\ServiceProbeInterface;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Str;
use Throwable;

class SearchProbe implements ServiceProbeInterface
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_search_probe_evaluate(uint8_t available, const char *driver_ptr, size_t driver_len, int64_t start_ns, int64_t end_ns, int64_t *out_elapsed_ms, int32_t *out_verdict, char *out_buf, size_t out_cap);
        ssize_t nntmux_status_probe_format_failure(uint8_t probe_kind, const char *error_ptr, size_t error_len, char *out_buf, size_t out_cap);
        C;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private static ?CData $elapsedSlot = null;

    private static ?CData $verdictSlot = null;

    private static ?FFI $slotOwner = null;

    public function __construct(
        private readonly SearchService $searchService,
    ) {}

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

    public static function mediaFfi(bool $reset = false, FFI|false|null $override = null): ?FFI
    {
        if ($reset) {
            self::$ffi = $override;
            if ($override !== null) {
                return self::$ffi === false ? null : self::$ffi;
            }
        }
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('media', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    public function identifier(): string
    {
        return 'search';
    }

    public function probe(): ProbeResult
    {
        try {
            $start = hrtime(true);
            $available = $this->searchService->isAvailable();
            $end = hrtime(true);

            return self::evaluateAvailability($available, $this->searchService->getCurrentDriver(), $start, $end);
        } catch (Throwable $e) {
            return self::formatFailureResult($e->getMessage());
        }
    }

    public static function evaluateAvailability(
        bool $available,
        string $driver,
        int $startNs,
        int $endNs,
    ): ProbeResult {
        return self::$useRustFfi
            ? self::evaluateAvailabilityNative($available, $driver, $startNs, $endNs)
            : self::evaluateAvailabilityPhp($available, $driver, $startNs, $endNs);
    }

    public static function evaluateAvailabilityPhp(
        bool $available,
        string $driver,
        int $startNs,
        int $endNs,
    ): ProbeResult {
        $elapsed = (int) (($endNs - $startNs) / 1_000_000);

        if (! $available) {
            return new ProbeResult(
                ok: false,
                responseTimeMs: $elapsed,
                impact: IncidentImpactEnum::Critical,
                reason: sprintf('Search driver "%s" unavailable', $driver),
            );
        }

        return new ProbeResult(
            ok: true,
            responseTimeMs: $elapsed,
            impact: null,
            reason: sprintf('Search driver "%s" available', $driver),
        );
    }

    public static function evaluateAvailabilityNative(
        bool $available,
        string $driver,
        int $startNs,
        int $endNs,
    ): ProbeResult {
        $ffi = self::mediaFfi();
        if (
            $ffi === null
            || ! self::ensureSlots($ffi)
            || self::$elapsedSlot === null
            || self::$verdictSlot === null
        ) {
            return self::evaluateAvailabilityPhp($available, $driver, $startNs, $endNs);
        }

        $driverLen = \strlen($driver);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(128, $driverLen + 48), $cap);
        if ($scratch === null) {
            return self::evaluateAvailabilityPhp($available, $driver, $startNs, $endNs);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_search_probe_evaluate(
                $available ? 1 : 0,
                $driver,
                $driverLen,
                $startNs,
                $endNs,
                self::$elapsedSlot,
                self::$verdictSlot,
                $scratch,
                $cap,
            );
            if (\is_int($written) && $written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return self::evaluateAvailabilityPhp($available, $driver, $startNs, $endNs);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_search_probe_evaluate(
                    $available ? 1 : 0,
                    $driver,
                    $driverLen,
                    $startNs,
                    $endNs,
                    self::$elapsedSlot,
                    self::$verdictSlot,
                    $scratch,
                    $cap,
                );
            }
            if (! \is_int($written) || $written <= 0 || $written > $cap) {
                return self::evaluateAvailabilityPhp($available, $driver, $startNs, $endNs);
            }

            /** @var int $elapsed */
            $elapsed = self::$elapsedSlot[0];
            /** @var int $verdict */
            $verdict = self::$verdictSlot[0];
            $reason = FFI::string($scratch, $written);

            return match ($verdict) {
                0 => new ProbeResult(
                    ok: true,
                    responseTimeMs: $elapsed,
                    impact: null,
                    reason: $reason,
                ),
                2 => new ProbeResult(
                    ok: false,
                    responseTimeMs: $elapsed,
                    impact: IncidentImpactEnum::Critical,
                    reason: $reason,
                ),
                default => self::evaluateAvailabilityPhp($available, $driver, $startNs, $endNs),
            };
        } catch (Throwable) {
            return self::evaluateAvailabilityPhp($available, $driver, $startNs, $endNs);
        }
    }

    public static function formatFailureResult(string $errorMessage): ProbeResult
    {
        return self::$useRustFfi
            ? self::formatFailureResultNative($errorMessage)
            : self::formatFailureResultPhp($errorMessage);
    }

    public static function formatFailureResultPhp(string $errorMessage): ProbeResult
    {
        return new ProbeResult(
            ok: false,
            responseTimeMs: 0,
            impact: IncidentImpactEnum::Critical,
            reason: 'Search check failed: '.Str::limit($errorMessage, 120),
        );
    }

    public static function formatFailureResultNative(string $errorMessage): ProbeResult
    {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return self::formatFailureResultPhp($errorMessage);
        }

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 256, $cap);
        if ($scratch === null) {
            return self::formatFailureResultPhp($errorMessage);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_status_probe_format_failure(
                3,
                $errorMessage,
                \strlen($errorMessage),
                $scratch,
                $cap,
            );
            if (\is_int($written) && $written < -2) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return self::formatFailureResultPhp($errorMessage);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_status_probe_format_failure(
                    3,
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

        return self::formatFailureResultPhp($errorMessage);
    }

    private static function ensureSlots(FFI $ffi): bool
    {
        if (self::$slotOwner !== $ffi || self::$elapsedSlot === null || self::$verdictSlot === null) {
            try {
                self::$elapsedSlot = $ffi->new('int64_t[1]');
                self::$verdictSlot = $ffi->new('int32_t[1]');
                self::$slotOwner = $ffi;
            } catch (Throwable) {
                return false;
            }
        }

        return true;
    }

    public const string W50_FFI_CDEF = 'int64_t nntmux_w505_search_probe_outcome(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W50.5 parity test's QUALIFICATION). */
    private const W50_RUST_FFI_DEFAULT = true;

    private static ?bool $w50RustFfi = null;

    private static FFI|false|null $w50Ffi = null;

    private static ?CData $w50Out = null;

    private static int $w50OutCap = 0;

    public static function usesW50RustFfi(): bool
    {
        return self::$w50RustFfi ?? self::W50_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the Wave 50.5 batch helper routed to Rust ($enabled) or PHP, then restores the previous
     * routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW50RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$w50RustFfi;
        self::$w50RustFfi = $enabled;

        try {
            return $callback();
        } finally {
            self::$w50RustFfi = $previous;
        }
    }

    /** PHP `hash('xxh64')` of $value read as a signed big-endian word, as the Rust helper reports it. */
    private static function w50Digest(string $value): int
    {
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('J', hash('xxh64', $value, true));

        return $unpacked[1];
    }

    /**
     * Batch outcome of probe() for many availability checks. Four words per check, aligned with $drivers:
     * `[code (0 available, 2 unavailable, 3 check failed), responseTimeMs, reason length, xxh64(reason)]`,
     * following evaluateAvailabilityPhp() and, for a check that threw ($errors holds its message),
     * formatFailureResultPhp().
     *
     * @param  list<bool>  $available
     * @param  list<string>  $drivers
     * @param  list<int>  $startNs
     * @param  list<int>  $endNs
     * @param  array<int, string>  $errors  check index => exception message
     * @return list<int>
     */
    public static function availabilityOutcomeBatch(array $available, array $drivers, array $startNs, array $endNs, array $errors = []): array
    {
        return self::usesW50RustFfi()
            ? self::availabilityOutcomeBatchNative($available, $drivers, $startNs, $endNs, $errors)
            : self::availabilityOutcomeBatchPhp($available, $drivers, $startNs, $endNs, $errors);
    }

    /**
     * @param  list<bool>  $available
     * @param  list<string>  $drivers
     * @param  list<int>  $startNs
     * @param  list<int>  $endNs
     * @param  array<int, string>  $errors
     * @return list<int>
     */
    public static function availabilityOutcomeBatchPhp(array $available, array $drivers, array $startNs, array $endNs, array $errors = []): array
    {
        $out = [];
        foreach ($drivers as $i => $driver) {
            if (isset($errors[$i])) {
                $reason = 'Search check failed: '.Str::limit($errors[$i], 120);
                array_push($out, 3, 0, \strlen($reason), self::w50Digest($reason));

                continue;
            }
            $elapsed = (int) (($endNs[$i] - $startNs[$i]) / 1_000_000);
            $reason = sprintf($available[$i] ? 'Search driver "%s" available' : 'Search driver "%s" unavailable', $driver);
            array_push($out, $available[$i] ? 0 : 2, $elapsed, \strlen($reason), self::w50Digest($reason));
        }

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, the columns differ in length or a value holds
     * a NUL byte, and computes non-ASCII errors and overflowing timings with PHP.
     *
     * @param  list<bool>  $available
     * @param  list<string>  $drivers
     * @param  list<int>  $startNs
     * @param  list<int>  $endNs
     * @param  array<int, string>  $errors
     * @return list<int>
     */
    public static function availabilityOutcomeBatchNative(array $available, array $drivers, array $startNs, array $endNs, array $errors = []): array
    {
        $count = \count($drivers);
        if ($count === 0 || \count($available) !== $count || \count($startNs) !== $count || \count($endNs) !== $count || ! array_is_list($drivers)) {
            return self::availabilityOutcomeBatchPhp($available, $drivers, $startNs, $endNs, $errors);
        }
        $data = $errors === [] ? implode("\0", $drivers) : implode("\0", $drivers)."\0".implode("\0", $errors);
        $words = self::w50Words($count, $data, pack('P*', \count($errors), ...$available, ...$startNs, ...$endNs, ...array_keys($errors)), 4 * $count, $patches);
        if ($words === null) {
            return self::availabilityOutcomeBatchPhp($available, $drivers, $startNs, $endNs, $errors);
        }
        if ($patches > 0) {
            foreach (array_keys($words, -1, true) as $key) {
                if ($key % 4 === 0) {
                    $i = intdiv($key, 4);
                    [$words[$key], $words[$key + 1], $words[$key + 2], $words[$key + 3]] = self::availabilityOutcomeBatchPhp([$available[$i]], [$drivers[$i]], [$startNs[$i]], [$endNs[$i]], isset($errors[$i]) ? [$errors[$i]] : []);
                }
            }
        }

        return $words;
    }

    /**
     * Exactly $words output words from the Wave 50.5 Rust helper, or null to fall back to PHP. The helper
     * leads with the number of rows PHP must compute, returned in $patches.
     *
     * @return list<int>|null
     */
    private static function w50Words(int $count, string $data, string $ints, int $words, ?int &$patches = null): ?array
    {
        if (self::$w50Ffi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            self::$w50Ffi = RustPipelineLibrary::resolveDomain('search_w50', self::W50_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
            self::$w50Out = null;
            self::$w50OutCap = 0;
        }
        $ffi = self::$w50Ffi;
        if (! $ffi instanceof FFI) {
            return null;
        }

        try {
            if (self::$w50Out === null || $words >= self::$w50OutCap) {
                $cap = max(1024, $words + 1);
                self::$w50Out = $ffi->new('int64_t['.$cap.']');
                self::$w50OutCap = $cap;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w505_search_probe_outcome($count, 0, $data, \strlen($data), $ints, \strlen($ints), self::$w50Out, self::$w50OutCap);
            if ($written !== $words + 1) {
                return null;
            }
            $patches = self::$w50Out[0];
            /** @var array<int, int>|false $values */
            $values = unpack('P*', FFI::string(self::$w50Out, $written * 8), 8);

            return \is_array($values) ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
