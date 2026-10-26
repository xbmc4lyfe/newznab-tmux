<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

use App\Services\AdditionalProcessing\Enums\ProcessingOutcome;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

final readonly class ReleaseProcessingResult
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_rpresult_outcome_successful(const char *value, size_t len);
        ssize_t nntmux_rpresult_duration_summary(const char *packed, size_t len, double elapsed, char *out, size_t cap);
        C;

    /**
     * @param  array<string, float>  $stageDurations
     * @param  list<string>  $unsupportedReasons
     */
    public function __construct(
        public int $releaseId,
        public string $guid,
        public ProcessingOutcome $outcome,
        public bool $artifactsCreated = false,
        public int $releaseFilesAdded = 0,
        public string $reason = '',
        public float $elapsedSeconds = 0.0,
        public array $stageDurations = [],
        public ?DownloadMetrics $downloadMetrics = null,
        public ?PersistenceMetrics $persistenceMetrics = null,
        public int $duplicateMessageIdCount = 0,
        public array $unsupportedReasons = [],
        // PHP remains the production default until benchmarks qualify Rust.
        public bool $useRustFfi = false,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->useRustFfi ? $this->isSuccessfulNative() : $this->isSuccessfulPhp();
    }

    public function isSuccessfulPhp(): bool
    {
        return $this->outcome->isSuccessful();
    }

    public function isSuccessfulNative(): bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return $this->isSuccessfulPhp();
        }

        $value = $this->outcome->value;
        // @phpstan-ignore method.notFound
        $successful = $ffi->nntmux_rpresult_outcome_successful($value, strlen($value));

        return $successful === 0 || $successful === 1 ? $successful === 1 : $this->isSuccessfulPhp();
    }

    /**
     * Stage count and total, the slowest stage (first on ties) and the elapsed time
     * not covered by any stage (never negative).
     *
     * @return array{stageCount: int, stageSeconds: float, slowestStage: ?string, slowestSeconds: float, unaccountedSeconds: float}
     */
    public function durationSummary(): array
    {
        return $this->useRustFfi ? $this->durationSummaryNative() : $this->durationSummaryPhp();
    }

    /**
     * @return array{stageCount: int, stageSeconds: float, slowestStage: ?string, slowestSeconds: float, unaccountedSeconds: float}
     */
    public function durationSummaryPhp(): array
    {
        $total = 0.0;
        $slowestStage = null;
        $slowestSeconds = 0.0;
        foreach ($this->stageDurations as $stage => $seconds) {
            $seconds = (float) $seconds;
            $total += $seconds;
            if ($slowestStage === null || $seconds > $slowestSeconds) {
                $slowestStage = (string) $stage;
                $slowestSeconds = $seconds;
            }
        }

        $unaccounted = $this->elapsedSeconds - $total;
        if ($unaccounted < 0.0) {
            $unaccounted = 0.0;
        }

        return [
            'stageCount' => count($this->stageDurations),
            'stageSeconds' => $total,
            'slowestStage' => $slowestStage,
            'slowestSeconds' => $slowestSeconds,
            'unaccountedSeconds' => $unaccounted,
        ];
    }

    /**
     * @return array{stageCount: int, stageSeconds: float, slowestStage: ?string, slowestSeconds: float, unaccountedSeconds: float}
     */
    public function durationSummaryNative(): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return $this->durationSummaryPhp();
        }

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 32, $cap);
        if ($scratch === null) {
            return $this->durationSummaryPhp();
        }

        $packed = $this->stageDurations === [] ? '' : pack('e*', ...array_values($this->stageDurations));
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_rpresult_duration_summary($packed, strlen($packed), $this->elapsedSeconds, $scratch, $cap);
        if ($written !== 32) {
            return $this->durationSummaryPhp();
        }

        $summary = unpack('etotal/eslowest/eunaccounted/Pindex', FFI::string($scratch, 32));
        if (! is_array($summary)) {
            return $this->durationSummaryPhp();
        }
        $index = (int) $summary['index'];

        return [
            'stageCount' => count($this->stageDurations),
            'stageSeconds' => (float) $summary['total'],
            'slowestStage' => $index === 0 ? null : (string) array_keys($this->stageDurations)[$index - 1],
            'slowestSeconds' => (float) $summary['slowest'],
            'unaccountedSeconds' => (float) $summary['unaccounted'],
        ];
    }

    /**
     * @param  array<string, float>  $stageDurations
     */
    public function withPerformance(
        float $elapsedSeconds,
        array $stageDurations,
        ?DownloadMetrics $downloadMetrics = null,
        ?PersistenceMetrics $persistenceMetrics = null,
    ): self {
        return new self(
            releaseId: $this->releaseId,
            guid: $this->guid,
            outcome: $this->outcome,
            artifactsCreated: $this->artifactsCreated,
            releaseFilesAdded: $this->releaseFilesAdded,
            reason: $this->reason,
            elapsedSeconds: $elapsedSeconds,
            stageDurations: $stageDurations,
            downloadMetrics: $downloadMetrics ?? $this->downloadMetrics,
            persistenceMetrics: $persistenceMetrics ?? $this->persistenceMetrics,
            duplicateMessageIdCount: $this->duplicateMessageIdCount,
            unsupportedReasons: $this->unsupportedReasons,
            useRustFfi: $this->useRustFfi,
        );
    }

    public static function ffi(): ?FFI
    {
        static $ffi = null;
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return $ffi === false ? null : $ffi;
    }

    // Rust is the default only while the paired W49.6 benchmark clears 1.5x on all four metrics.
    private const bool W49_RUST_FFI_DEFAULT = true;

    public const string W49_FFI_CDEF = <<<'C'
        int32_t nntmux_w49_release_result_summary(const char *outcomes, size_t outcomes_len, const char *outcome_twin, size_t outcome_twin_len, const char *ints, size_t ints_len, size_t count, int64_t slow_micros, int64_t *out, size_t out_count);
        C;

    public const int W49_RESULT_HEADER = 28;

    private const int W49_MAX_ITEMS = 1_048_576;

    private const int W49_VALUE_CAP = (1 << 40) - 1;

    private const int W49_TOTAL_CAP = 1 << 62;

    /** `ProcessingOutcome` backing value => case index; 12 is unknown. */
    private const array W49_OUTCOMES = [
        'completed' => 0, 'no-useful-artifacts' => 1, 'passworded' => 2, 'group-unavailable' => 3,
        'temporary-workspace-unavailable' => 4, 'timed-out' => 5, 'deleted-after-timeout' => 6,
        'deleted-broken-nzb' => 7, 'deleted-par2-only' => 8, 'not-found' => 9,
        'awaiting-late-articles' => 10, 'failed' => 11,
    ];

    public static function usesW49RustFfi(): bool
    {
        return self::w49RustFfiState() === true;
    }

    /**
     * Run `$callback` with the Wave 49.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW49RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w49RustFfiState('swap', $enabled);
        try {
            return $callback();
        } finally {
            self::w49RustFfiState('swap', $previous);
        }
    }

    /**
     * Summarize a batch of results from columns: outcome backing values, elapsed and staged
     * microseconds, release files added and duplicate message ids (missing values are 0; each
     * value is clamped to 0..2^40-1 and totals to 2^62). Completed, no-useful-artifacts and
     * passworded count as successful; a result is slow when `$slowMicros > 0` and its elapsed
     * time reaches it.
     *
     * Layout: `[0]` count, `[1..13]` per outcome case (13 unknown), `[14]` successes, `[15]`
     * slow, `[16]` elapsed, `[17]` staged, `[18]` unaccounted (elapsed minus staged, never
     * negative) and `[19]` files and `[20]` duplicate totals, `[21]` slowest elapsed and `[22]`
     * its first index or -1, `[23..25]` nearest-rank p50/p90/p99 elapsed, `[26]` success ppm,
     * `[27]` FNV-1a digest; then per result `case | successful << 4 | slow << 5 | unaccounted << 8`.
     *
     * @param  array<array-key, string>  $outcomes
     * @param  array<array-key, int>  $elapsedMicros
     * @param  array<array-key, int>  $stageMicros
     * @param  array<array-key, int>  $filesAdded
     * @param  array<array-key, int>  $duplicates
     * @return list<int>
     */
    public static function summarizeResultBatch(array $outcomes, array $elapsedMicros, array $stageMicros, array $filesAdded, array $duplicates, int $slowMicros = 0): array
    {
        return (self::usesW49RustFfi() ? self::summarizeResultBatchNative($outcomes, $elapsedMicros, $stageMicros, $filesAdded, $duplicates, $slowMicros) : null)
            ?? self::summarizeResultBatchPhp($outcomes, $elapsedMicros, $stageMicros, $filesAdded, $duplicates, $slowMicros);
    }

    /**
     * @param  array<array-key, string>  $outcomes
     * @param  array<array-key, int>  $elapsedMicros
     * @param  array<array-key, int>  $stageMicros
     * @param  array<array-key, int>  $filesAdded
     * @param  array<array-key, int>  $duplicates
     * @return list<int>
     */
    public static function summarizeResultBatchPhp(array $outcomes, array $elapsedMicros, array $stageMicros, array $filesAdded, array $duplicates, int $slowMicros = 0): array
    {
        $outcomes = array_values($outcomes);
        $elapsedMicros = array_values($elapsedMicros);
        $stageMicros = array_values($stageMicros);
        $filesAdded = array_values($filesAdded);
        $duplicates = array_values($duplicates);
        $count = \count($outcomes);
        $cap = self::W49_VALUE_CAP;
        $totalCap = self::W49_TOTAL_CAP;
        $out = array_fill(0, self::W49_RESULT_HEADER, 0);
        $out[0] = $count;
        $out[22] = -1;
        $digest = 2_166_136_261;
        $rows = $all = [];
        foreach ($outcomes as $index => $outcome) {
            $case = self::W49_OUTCOMES[$outcome] ?? 12;
            $successful = $case <= 2 ? 1 : 0;
            $elapsed = min(max($elapsedMicros[$index] ?? 0, 0), $cap);
            $stage = min(max($stageMicros[$index] ?? 0, 0), $cap);
            $unaccounted = $elapsed > $stage ? $elapsed - $stage : 0;
            $slow = $slowMicros > 0 && $elapsed >= $slowMicros ? 1 : 0;
            $out[1 + $case]++;
            $out[14] += $successful;
            $out[15] += $slow;
            $out[16] = min($out[16] + $elapsed, $totalCap);
            $out[17] = min($out[17] + $stage, $totalCap);
            $out[18] = min($out[18] + $unaccounted, $totalCap);
            $out[19] = min($out[19] + min(max($filesAdded[$index] ?? 0, 0), $cap), $totalCap);
            $out[20] = min($out[20] + min(max($duplicates[$index] ?? 0, 0), $cap), $totalCap);
            if ($out[22] < 0 || $elapsed > $out[21]) {
                $out[21] = $elapsed;
                $out[22] = $index;
            }
            $digest = (($digest ^ ($case | $successful << 4 | $slow << 5)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $case | $successful << 4 | $slow << 5 | $unaccounted << 8;
            $all[] = $elapsed;
        }
        if ($count > 0) {
            sort($all);
            $out[23] = $all[intdiv($count * 50 + 99, 100) - 1];
            $out[24] = $all[intdiv($count * 90 + 99, 100) - 1];
            $out[25] = $all[intdiv($count * 99 + 99, 100) - 1];
            $out[26] = intdiv($out[14] * 1_000_000, $count);
        }
        $out[27] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust summary, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, string>  $outcomes
     * @param  array<array-key, int>  $elapsedMicros
     * @param  array<array-key, int>  $stageMicros
     * @param  array<array-key, int>  $filesAdded
     * @param  array<array-key, int>  $duplicates
     * @return list<int>|null
     */
    public static function summarizeResultBatchNative(array $outcomes, array $elapsedMicros, array $stageMicros, array $filesAdded, array $duplicates, int $slowMicros = 0): ?array
    {
        $outcomes = array_values($outcomes);
        $count = \count($outcomes);
        $fields = self::W49_RESULT_HEADER + $count;
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = $count <= self::W49_MAX_ITEMS ? self::w49RustFfiState('buffer', $fields) : null;
        if ($binding === null) {
            return null;
        }
        [$ffi, $out] = $binding;
        [$joined, $twin] = self::w49Strings($outcomes);
        $ints = pack('q*', ...self::w49Column($elapsedMicros, $count), ...self::w49Column($stageMicros, $count),
            ...self::w49Column($filesAdded, $count), ...self::w49Column($duplicates, $count));
        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_w49_release_result_summary($joined, \strlen($joined), $twin, \strlen($twin), $ints, \strlen($ints), $count, $slowMicros, $out, $fields);
            if ($status !== 1) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $fields * 8));
        } catch (Throwable) {
            return null;
        }

        return $values === false ? null : array_values($values);
    }

    /**
     * Wave 49.6 routing: null reads the toggle, `swap` replaces it (null restores the default)
     * and returns the previous value, `buffer` returns the cached binding and an output buffer
     * of at least `$val` values. Enums and readonly classes cannot declare static properties,
     * so the state lives in function-local statics.
     */
    private static function w49RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $enabled */
        static $enabled = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $buffer */
        static $buffer = null;
        static $capacity = 0;
        if ($op === null) {
            return $enabled ?? self::W49_RUST_FFI_DEFAULT;
        }
        if ($op === 'swap') {
            $previous = $enabled;
            $enabled = \is_bool($val) ? $val : null;

            return $previous;
        }
        $fields = \is_int($val) ? $val : 0;
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w49', self::W49_FFI_CDEF) ?? false;
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
     * NUL-joined values and, when one holds a NUL, the same list joined with 0x01 so Rust
     * finds the separators where the two differ.
     *
     * @param  list<string>  $items
     * @return array{0: string, 1: string}
     */
    private static function w49Strings(array $items): array
    {
        $joined = implode("\0", $items);

        return $items !== [] && substr_count($joined, "\0") !== \count($items) - 1 ? [$joined, implode("\x01", $items)] : [$joined, ''];
    }

    /**
     * Values by position, cut or zero-padded to `$count`.
     *
     * @param  array<array-key, int>  $values
     * @return list<int>
     */
    private static function w49Column(array $values, int $count): array
    {
        $values = array_values($values);

        return \count($values) === $count ? $values : array_pad(\array_slice($values, 0, $count), $count, 0);
    }

    // Rust is the default only while the paired W60.6 benchmark clears 1.5x on all four metrics.
    public const bool W60_RUST_FFI_DEFAULT = true;

    private const string W60_RESULT_FFI_CDEF = <<<'C'
        int32_t nntmux_w606_release_result_batch(const char *strings, size_t strings_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t max_attempts, int64_t *out, size_t out_cap);
        C;

    public const int W60_RESULT_HEADER = 45;

    private const int W60_MAX_ITEMS = 1_048_576;

    private const int W60_VALUE_CAP = (1 << 40) - 1;

    private const int W60_TOTAL_CAP = 1 << 62;

    /** Slowest stage => index; 10 is unknown and 11 empty. */
    private const array W60_STAGES = [
        'download' => 0, 'archive' => 1, 'par2' => 2, 'media' => 3, 'sample' => 4, 'video' => 5, 'audio' => 6,
        'image' => 7, 'nfo' => 8, 'persist' => 9, '' => 11,
    ];

    /**
     * Classify results from outcome and slowest-stage columns with elapsed and staged microseconds
     * (clamped to 0..2^40-1), artifacts flags, files added and attempts (clamped to 0..2^20-1).
     * Disposition 0 success (completed, no-useful-artifacts, passworded), 1 retry (group or workspace
     * unavailable, timed out, awaiting late articles or failed with attempts below `$maxAttempts`), 2
     * retries exhausted, 3 deleted, 4 not found, 5 unknown. Retries back off `min(3600, 30 << min(attempts,
     * 10))` seconds; a mismatch is a non-success with artifacts or files.
     *
     * Layout: `[0]` count, `[1..13]` per outcome case (13 unknown), `[14..19]` per disposition, `[20..31]`
     * per stage, `[32]` artifacts, `[33]` files, `[34]` elapsed, `[35]` staged and `[36]` unaccounted totals
     * (capped at 2^62), `[37]` overruns (staged above elapsed), `[38]` mismatches, `[39]` slowest elapsed
     * and `[40]` its first index (-1), `[41]` first retry and `[42]` first exhausted index (-1), `[43]` max
     * backoff, `[44]` FNV-1a digest; then per result `case | disposition << 4 | stage << 7 | artifacts << 11
     * | mismatch << 12 | overrun << 13 | min(attempts, 255) << 14 | backoff << 22 | min(unaccounted,
     * 2^29-1) << 34`.
     *
     * @param  array<array-key, string>  $outcomes
     * @param  array<array-key, string>  $stages
     * @param  array<array-key, int>  $elapsedMicros
     * @param  array<array-key, int>  $stageMicros
     * @param  array<array-key, int>  $artifacts
     * @param  array<array-key, int>  $filesAdded
     * @param  array<array-key, int>  $attempts
     * @return list<int>
     */
    public static function classifyResultBatch(array $outcomes, array $stages, array $elapsedMicros, array $stageMicros, array $artifacts, array $filesAdded, array $attempts, int $maxAttempts = 3): array
    {
        return (self::usesW60RustFfi() ? self::classifyResultBatchNative($outcomes, $stages, $elapsedMicros, $stageMicros, $artifacts, $filesAdded, $attempts, $maxAttempts) : null)
            ?? self::classifyResultBatchPhp($outcomes, $stages, $elapsedMicros, $stageMicros, $artifacts, $filesAdded, $attempts, $maxAttempts);
    }

    /**
     * @param  array<array-key, string>  $outcomes
     * @param  array<array-key, string>  $stages
     * @param  array<array-key, int>  $elapsedMicros
     * @param  array<array-key, int>  $stageMicros
     * @param  array<array-key, int>  $artifacts
     * @param  array<array-key, int>  $filesAdded
     * @param  array<array-key, int>  $attempts
     * @return list<int>
     */
    public static function classifyResultBatchPhp(array $outcomes, array $stages, array $elapsedMicros, array $stageMicros, array $artifacts, array $filesAdded, array $attempts, int $maxAttempts = 3): array
    {
        $outcomes = array_values($outcomes);
        $stages = array_values($stages);
        $elapsedMicros = array_values($elapsedMicros);
        $stageMicros = array_values($stageMicros);
        $artifacts = array_values($artifacts);
        $filesAdded = array_values($filesAdded);
        $attempts = array_values($attempts);
        $count = \count($outcomes);
        $cap = self::W60_VALUE_CAP;
        $totalCap = self::W60_TOTAL_CAP;
        $out = array_fill(0, self::W60_RESULT_HEADER, 0);
        $out[0] = $count;
        $out[40] = $out[41] = $out[42] = -1;
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($outcomes as $at => $outcome) {
            $case = self::W49_OUTCOMES[(string) $outcome] ?? 12;
            $stage = self::W60_STAGES[(string) ($stages[$at] ?? '')] ?? 10;
            $elapsed = min(max((int) ($elapsedMicros[$at] ?? 0), 0), $cap);
            $staged = min(max((int) ($stageMicros[$at] ?? 0), 0), $cap);
            $artifact = (int) ($artifacts[$at] ?? 0) !== 0 ? 1 : 0;
            $files = min(max((int) ($filesAdded[$at] ?? 0), 0), $cap);
            $attempt = min(max((int) ($attempts[$at] ?? 0), 0), 1_048_575);
            if ($case <= 2) {
                $disposition = 0;
            } elseif ($case === 3 || $case === 4 || $case === 5 || $case === 10 || $case === 11) {
                $disposition = $attempt < $maxAttempts ? 1 : 2;
            } elseif ($case <= 8) {
                $disposition = 3;
            } else {
                $disposition = $case === 9 ? 4 : 5;
            }
            $backoff = $disposition === 1 ? min(3_600, 30 << min($attempt, 10)) : 0;
            $unaccounted = $elapsed > $staged ? $elapsed - $staged : 0;
            $overrun = $staged > $elapsed ? 1 : 0;
            $mismatch = $disposition !== 0 && ($artifact === 1 || $files > 0) ? 1 : 0;
            $out[1 + $case]++;
            $out[14 + $disposition]++;
            $out[20 + $stage]++;
            $out[32] += $artifact;
            $out[33] = min($out[33] + $files, $totalCap);
            $out[34] = min($out[34] + $elapsed, $totalCap);
            $out[35] = min($out[35] + $staged, $totalCap);
            $out[36] = min($out[36] + $unaccounted, $totalCap);
            $out[37] += $overrun;
            $out[38] += $mismatch;
            if ($out[40] < 0 || $elapsed > $out[39]) {
                $out[39] = $elapsed;
                $out[40] = $at;
            }
            if ($disposition === 1 && $out[41] < 0) {
                $out[41] = $at;
            } elseif ($disposition === 2 && $out[42] < 0) {
                $out[42] = $at;
            }
            $out[43] = max($out[43], $backoff);
            $row = $case | $disposition << 4 | $stage << 7 | $artifact << 11 | $mismatch << 12 | $overrun << 13 | min($attempt, 255) << 14
                | $backoff << 22 | min($unaccounted, (1 << 29) - 1) << 34;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[44] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust classification, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, string>  $outcomes
     * @param  array<array-key, string>  $stages
     * @param  array<array-key, int>  $elapsedMicros
     * @param  array<array-key, int>  $stageMicros
     * @param  array<array-key, int>  $artifacts
     * @param  array<array-key, int>  $filesAdded
     * @param  array<array-key, int>  $attempts
     * @return list<int>|null
     */
    public static function classifyResultBatchNative(array $outcomes, array $stages, array $elapsedMicros, array $stageMicros, array $artifacts, array $filesAdded, array $attempts, int $maxAttempts = 3): ?array
    {
        $count = \count($outcomes);
        if ($count > self::W60_MAX_ITEMS) {
            return null;
        }
        [$joined, $twin] = self::w60Strings([...array_values($outcomes), ...self::w60Column($stages, $count, '')]);
        $ints = pack('q*', ...self::w60Column($elapsedMicros, $count), ...self::w60Column($stageMicros, $count),
            ...self::w60Column($artifacts, $count), ...self::w60Column($filesAdded, $count), ...self::w60Column($attempts, $count));
        $fields = self::W60_RESULT_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w60Call($fields, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w606_release_result_batch($joined, \strlen($joined), $twin, \strlen($twin), $ints, \strlen($ints), $count, $maxAttempts, $out, $fields));
    }

    /**
     * Run `$callback` with the Wave 60.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW60RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w60RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w60RustFfiState('set', $previous);
        }
    }

    public static function usesW60RustFfi(): bool
    {
        $forced = self::w60RustFfiState();

        return \is_bool($forced) ? $forced : self::W60_RUST_FFI_DEFAULT;
    }

    /**
     * Wave 60.6 routing and binding in function statics (enums and readonly classes cannot declare properties).
     * No `$op` returns the forced routing (null for the default); `'set'` replaces it with `$val` and returns
     * the previous value; `'binding'` returns the cached binding and an output buffer of at least `$val`
     * values, or null.
     */
    private static function w60RustFfiState(?string $op = null, mixed $val = null): mixed
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
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w606', self::W60_RESULT_FFI_CDEF) ?? false;
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
     * The batch's `$fields` output words from one Wave 60.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w60Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w60RustFfiState('binding', $fields);
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
     * @param  list<string>  $items
     * @return array{0: string, 1: string}
     */
    private static function w60Strings(array $items): array
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
    private static function w60Column(array $values, int $count, int|string $pad = 0): array
    {
        $values = array_values($values);

        return \count($values) === $count ? $values : array_pad(\array_slice($values, 0, $count), $count, $pad);
    }
}
