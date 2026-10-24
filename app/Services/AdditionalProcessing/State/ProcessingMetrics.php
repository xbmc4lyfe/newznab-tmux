<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\State;

use App\Services\AdditionalProcessing\Enums\ProcessingStage;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Throwable;

final class ProcessingMetrics
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_pmetrics_ns_to_seconds(const char *packed, size_t len, char *out, size_t cap);
        double nntmux_pmetrics_rate(int64_t count, double seconds);
        ssize_t nntmux_pmetrics_format_summary(const char *packed, size_t len, char *out, size_t cap);
        C;

    // PHP remains the production default: FFI marshaling stays below the 1.5x gate.
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private readonly int $startedAtNanoseconds;

    /**
     * @var array<string, int>
     */
    private array $stageNanoseconds = [];

    public function __construct()
    {
        $this->startedAtNanoseconds = hrtime(true);
    }

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $operation
     * @return TValue
     */
    public function measure(ProcessingStage $stage, Closure $operation): mixed
    {
        $startedAt = hrtime(true);

        try {
            return $operation();
        } finally {
            $elapsed = hrtime(true) - $startedAt;
            $this->stageNanoseconds[$stage->value] = ($this->stageNanoseconds[$stage->value] ?? 0) + $elapsed;
        }
    }

    public function elapsedSeconds(): float
    {
        return $this->nanosecondsToSeconds(hrtime(true) - $this->startedAtNanoseconds);
    }

    /**
     * @return array<string, float>
     */
    public function stageDurations(): array
    {
        return self::secondsFromNanoseconds($this->stageNanoseconds);
    }

    /**
     * Releases handled per second since construction.
     */
    public function releasesPerSecond(int $releases): float
    {
        return self::rate($releases, $this->elapsedSeconds());
    }

    /**
     * One-line `stage=seconds` summary of the stage durations.
     */
    public function summaryLine(): string
    {
        return self::formatStageSummary($this->stageDurations());
    }

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, int>  $nanoseconds
     * @return array<TKey, float>
     */
    public static function secondsFromNanoseconds(array $nanoseconds): array
    {
        return (self::$useRustFfi ? self::secondsFromNanosecondsNative($nanoseconds) : null) ?? self::secondsFromNanosecondsPhp($nanoseconds);
    }

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, int>  $nanoseconds
     * @return array<TKey, float>
     */
    public static function secondsFromNanosecondsPhp(array $nanoseconds): array
    {
        return array_map(self::nanosecondsToSecondsPhp(...), $nanoseconds);
    }

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, int|float>  $nanoseconds  an overflowed counter is a float
     * @return array<TKey, float>|null Null when PHP must convert the values.
     */
    public static function secondsFromNanosecondsNative(array $nanoseconds): ?array
    {
        if ($nanoseconds === []) {
            return [];
        }
        foreach ($nanoseconds as $value) {
            if (! \is_int($value)) {
                return null;
            }
        }
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            $packed = pack('q*', ...array_values($nanoseconds));
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, \strlen($packed), $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_pmetrics_ns_to_seconds($packed, \strlen($packed), $scratch, $cap);
            if ($written !== \strlen($packed)) {
                return null;
            }
            $seconds = unpack('e*', FFI::string($scratch, $written));
        } catch (Throwable) {
            return null;
        }
        if (! \is_array($seconds)) {
            return null;
        }

        /** @var list<float> $values */
        $values = array_values($seconds);

        return array_combine(array_keys($nanoseconds), $values);
    }

    /**
     * Events per second, or 0.0 when no time elapsed.
     */
    public static function rate(int $count, float $seconds): float
    {
        return (self::$useRustFfi ? self::rateNative($count, $seconds) : null) ?? self::ratePhp($count, $seconds);
    }

    public static function ratePhp(int $count, float $seconds): float
    {
        return $seconds > 0 ? $count / $seconds : 0.0;
    }

    public static function rateNative(int $count, float $seconds): ?float
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            return (float) $ffi->nntmux_pmetrics_rate($count, $seconds);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<array-key, float>  $durations
     */
    public static function formatStageSummary(array $durations): string
    {
        return (self::$useRustFfi ? self::formatStageSummaryNative($durations) : null) ?? self::formatStageSummaryPhp($durations);
    }

    /**
     * @param  array<array-key, float>  $durations
     */
    public static function formatStageSummaryPhp(array $durations): string
    {
        $parts = [];
        foreach ($durations as $stage => $seconds) {
            $parts[] = $stage.'='.sprintf('%.6F', $seconds).'s';
        }

        return implode(' ', $parts);
    }

    /**
     * @param  array<array-key, float>  $durations
     */
    public static function formatStageSummaryNative(array $durations): ?string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $packed = '';
        foreach ($durations as $stage => $seconds) {
            if (! \is_float($seconds) && ! \is_int($seconds)) {
                return null;
            }
            $key = (string) $stage;
            $packed .= pack('V', \strlen($key)).$key.pack('e', (float) $seconds);
        }
        if ($packed === '') {
            return '';
        }
        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, \strlen($packed) + 16 * \count($durations), $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_pmetrics_format_summary($packed, \strlen($packed), $scratch, $cap);
            if ($written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_pmetrics_format_summary($packed, \strlen($packed), $scratch, $cap);
            }

            return $written > 0 ? FFI::string($scratch, $written) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function nanosecondsToSecondsPhp(int $nanoseconds): float
    {
        return $nanoseconds / 1_000_000_000;
    }

    private function nanosecondsToSeconds(int $nanoseconds): float
    {
        return self::nanosecondsToSecondsPhp($nanoseconds);
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }
}
