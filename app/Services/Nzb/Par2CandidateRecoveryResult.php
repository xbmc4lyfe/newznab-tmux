<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Enums\Par2RenameOutcome;
use App\Exceptions\NfoPayloadRejected;
use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

/** Each property is readonly; the class itself cannot be, because it holds the static Rust toggle. */
final class Par2CandidateRecoveryResult
{
    public const string FFI_CDEF = NfoPayloadRejected::FFI_CDEF;

    private const array OUTCOMES = ['renamed', 'no_match', 'retryable', 'deferred'];

    /** Rust stays opt-in: the FFI round trip is slower than this PHP helper (< 1.5x). */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    public function __construct(public readonly Par2RenameOutcome $outcome, public readonly int $probes, public readonly int $articles) {}

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    /** @return array{outcome: ?string, terminal: bool, retry: bool, probes: int, articles: int, total_reads: int, articles_per_probe: int} */
    public function metrics(): array
    {
        return self::summarizeRecoveryMetrics($this->outcome->value, $this->probes, $this->articles);
    }

    /**
     * Summarize one recovery attempt: the outcome (null when unknown), whether it is terminal (renamed or no match)
     * or retryable, clamped non-negative probe and article counts, their saturating total and the whole articles
     * read per probe (0 without probes).
     *
     * @return array{outcome: ?string, terminal: bool, retry: bool, probes: int, articles: int, total_reads: int, articles_per_probe: int}
     */
    public static function summarizeRecoveryMetrics(string $outcomeValue, int $probes, int $articles): array
    {
        return self::usesRustFfi() ? self::summarizeRecoveryMetricsNative($outcomeValue, $probes, $articles)
            : self::summarizeRecoveryMetricsPhp($outcomeValue, $probes, $articles);
    }

    /** @return array{outcome: ?string, terminal: bool, retry: bool, probes: int, articles: int, total_reads: int, articles_per_probe: int} */
    public static function summarizeRecoveryMetricsPhp(string $outcomeValue, int $probes, int $articles): array
    {
        $outcome = Par2RenameOutcome::tryFrom($outcomeValue);
        $probes = max(0, $probes);
        $articles = max(0, $articles);

        return [
            'outcome' => $outcome?->value,
            'terminal' => $outcome === Par2RenameOutcome::Renamed || $outcome === Par2RenameOutcome::NoMatch,
            'retry' => $outcome === Par2RenameOutcome::Retryable,
            'probes' => $probes,
            'articles' => $articles,
            'total_reads' => $probes > PHP_INT_MAX - $articles ? PHP_INT_MAX : $probes + $articles,
            'articles_per_probe' => $probes > 0 ? intdiv($articles, $probes) : 0,
        ];
    }

    /** @return array{outcome: ?string, terminal: bool, retry: bool, probes: int, articles: int, total_reads: int, articles_per_probe: int} */
    public static function summarizeRecoveryMetricsNative(string $outcomeValue, int $probes, int $articles): array
    {
        $slots = NfoPayloadRejected::nativeSlots(NfoPayloadRejected::evaluateNative(4, pack('q2', $probes, $articles).$outcomeValue), 7);
        if ($slots !== null && ($slots[0] === -1 || isset(self::OUTCOMES[$slots[0]])) && \in_array($slots[1], [0, 1], true) && \in_array($slots[2], [0, 1], true)
            && $slots[3] >= 0 && $slots[4] >= 0 && $slots[5] >= 0 && $slots[6] >= 0) {
            return [
                'outcome' => self::OUTCOMES[$slots[0]] ?? null,
                'terminal' => $slots[1] === 1,
                'retry' => $slots[2] === 1,
                'probes' => $slots[3],
                'articles' => $slots[4],
                'total_reads' => $slots[5],
                'articles_per_probe' => $slots[6],
            ];
        }

        return self::summarizeRecoveryMetricsPhp($outcomeValue, $probes, $articles);
    }

    /** The Wave 43.2 export shared by the recovery result, fingerprint store, worker, identity and queue helpers. */
    public const string W432_FFI_CDEF = <<<'C'
        int32_t nntmux_w432_nzb_par2_result_fingerprint_store_worker_queue(
            uint32_t operation,
            uint32_t flags,
            const char *input,
            size_t input_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    public const string W432_FFI_DOMAIN = 'nfo_w432';

    /**
     * Default for the Wave 43.2 toggle only (summarizeRecoveryBatch); true because the paired benchmark in
     * tests/Unit/RustNzbPar2ResultFingerprintStoreWorkerQueueW43ParityTest.php clears 1.5x on common and uncommon mean and p99.
     */
    public const bool W432_RUST_FFI_DEFAULT = true;

    public const int W432_MAX_INPUT = 16777216;

    private static ?bool $useW432RustFfi = null;

    /** @var array<string, FFI|false> resolved Wave 43.2 handles per explicit library path ('' for the default search) */
    private static array $w432Ffi = [];

    /** Force the Wave 43.2 helpers onto Rust (true) or PHP (false); null restores W432_RUST_FFI_DEFAULT. */
    public static function useW432RustFfi(?bool $enable = true): void
    {
        self::$useW432RustFfi = $enable;
    }

    public static function usesW432RustFfi(): bool
    {
        return self::$useW432RustFfi ?? self::W432_RUST_FFI_DEFAULT;
    }

    public static function resetW432RustFfi(): void
    {
        self::$useW432RustFfi = null;
    }

    /**
     * Run one Wave 43.2 operation over `[0, $rows]`, the row slots and the text tail; null whenever the library, input
     * or answer is unusable, so callers fall back to PHP.
     *
     * @param  list<int>  $slots
     * @return list<int>|null
     */
    public static function evaluateW432Native(int $operation, int $rows, array $slots, string $tail, int $outSlots): ?array
    {
        try {
            if ($operation < 0 || $operation > 0xFFFFFFFF || $outSlots < 1 || $rows < 0
                || 16 + \count($slots) * 8 + \strlen($tail) > self::W432_MAX_INPUT) {
                return null;
            }
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB') ?: '';
            $ffi = self::$w432Ffi[$path] ??= RustPipelineLibrary::resolveDomain(self::W432_FFI_DOMAIN, self::W432_FFI_CDEF, $path === '' ? null : $path) ?? false;
            if ($ffi === false) {
                return null;
            }
            $input = pack('q*', 0, $rows, ...$slots).$tail;
            $needed = $outSlots * 8;
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $capacity = 0;
                $output = RustPipelineLibrary::obtainScratch($ffi, $needed, $capacity);
                if ($output === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w432_nzb_par2_result_fingerprint_store_worker_queue($operation, 0, $input, \strlen($input), $output, $capacity);
                if (! \is_int($written) || $written === -1 || $written > $capacity) {
                    return null;
                }
                if ($written < 0) {
                    // Too small: retry once with the reported capacity.
                    $needed = -$written;

                    continue;
                }
                if ($written !== $outSlots * 8) {
                    return null;
                }
                $values = array_values(unpack('q'.$outSlots, FFI::string($output, $written)) ?: []);

                return \count($values) === $outSlots ? $values : null;
            }
        } catch (Throwable) {
        }

        return null;
    }

    /**
     * Summarize a batch of recovery attempts (instances, or arrays of a string or Par2RenameOutcome `outcome` with integer
     * `probes` and `articles`; anything else is invalid) with summarizeRecoveryMetricsPhp() per attempt: outcome tallies
     * (unknown when the value is not an outcome), terminal and retry counts, saturating probe/article/read totals and the
     * largest whole articles-per-probe ratio.
     *
     * @param  array<array-key, mixed>  $attempts
     * @return array{attempts: int, invalid: int, unknown: int, renamed: int, no_match: int, retryable: int, deferred: int, terminal: int, retry: int, probes: int, articles: int, total_reads: int, max_articles_per_probe: int}
     */
    public static function summarizeRecoveryBatch(array $attempts): array
    {
        return (self::usesW432RustFfi() ? self::summarizeRecoveryBatchNative($attempts) : null)
            ?? self::summarizeRecoveryBatchPhp($attempts);
    }

    /**
     * @param  array<array-key, mixed>  $attempts
     * @return array{attempts: int, invalid: int, unknown: int, renamed: int, no_match: int, retryable: int, deferred: int, terminal: int, retry: int, probes: int, articles: int, total_reads: int, max_articles_per_probe: int}
     */
    public static function summarizeRecoveryBatchPhp(array $attempts): array
    {
        $summary = ['attempts' => \count($attempts), 'invalid' => 0, 'unknown' => 0, 'renamed' => 0, 'no_match' => 0, 'retryable' => 0,
            'deferred' => 0, 'terminal' => 0, 'retry' => 0, 'probes' => 0, 'articles' => 0, 'total_reads' => 0, 'max_articles_per_probe' => 0];
        foreach ($attempts as $attempt) {
            $fields = self::recoveryFields($attempt);
            if ($fields === null) {
                $summary['invalid']++;

                continue;
            }
            $metrics = self::summarizeRecoveryMetricsPhp(...$fields);
            $summary[$metrics['outcome'] ?? 'unknown']++;
            $summary['terminal'] += (int) $metrics['terminal'];
            $summary['retry'] += (int) $metrics['retry'];
            foreach (['probes', 'articles', 'total_reads'] as $key) {
                $summary[$key] = $summary[$key] > PHP_INT_MAX - $metrics[$key] ? PHP_INT_MAX : $summary[$key] + $metrics[$key];
            }
            $summary['max_articles_per_probe'] = max($summary['max_articles_per_probe'], $metrics['articles_per_probe']);
        }

        return $summary;
    }

    /**
     * @param  array<array-key, mixed>  $attempts
     * @return array{attempts: int, invalid: int, unknown: int, renamed: int, no_match: int, retryable: int, deferred: int, terminal: int, retry: int, probes: int, articles: int, total_reads: int, max_articles_per_probe: int}|null
     */
    public static function summarizeRecoveryBatchNative(array $attempts): ?array
    {
        $slots = [];
        $tail = '';
        foreach ($attempts as $attempt) {
            $fields = self::recoveryFields($attempt);
            if ($fields === null) {
                array_push($slots, -1, 0, 0);

                continue;
            }
            array_push($slots, \strlen($fields[0]), $fields[1], $fields[2]);
            $tail .= $fields[0];
        }
        $values = self::evaluateW432Native(0, \count($attempts), $slots, $tail, 13);
        if ($values === null) {
            return null;
        }
        [$count, $invalid, $unknown, $renamed, $noMatch, $retryable, $deferred, $terminal, $retry, $probes, $articles, $reads, $ratio] = $values;
        if ($count !== \count($attempts) || min($values) < 0 || $invalid + $unknown + $renamed + $noMatch + $retryable + $deferred !== $count
            || $terminal !== $renamed + $noMatch || $retry !== $retryable) {
            return null;
        }

        return ['attempts' => $count, 'invalid' => $invalid, 'unknown' => $unknown, 'renamed' => $renamed, 'no_match' => $noMatch,
            'retryable' => $retryable, 'deferred' => $deferred, 'terminal' => $terminal, 'retry' => $retry, 'probes' => $probes,
            'articles' => $articles, 'total_reads' => $reads, 'max_articles_per_probe' => $ratio];
    }

    /** @return array{string, int, int}|null the outcome value, probes and articles of one attempt */
    private static function recoveryFields(mixed $attempt): ?array
    {
        if ($attempt instanceof self) {
            return [$attempt->outcome->value, $attempt->probes, $attempt->articles];
        }
        if (! \is_array($attempt)) {
            return null;
        }
        $outcome = $attempt['outcome'] ?? null;
        $outcome = $outcome instanceof Par2RenameOutcome ? $outcome->value : $outcome;
        $probes = $attempt['probes'] ?? null;
        $articles = $attempt['articles'] ?? null;

        return \is_string($outcome) && \is_int($probes) && \is_int($articles) ? [$outcome, $probes, $articles] : null;
    }
}
