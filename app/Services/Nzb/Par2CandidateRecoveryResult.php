<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Enums\Par2RenameOutcome;
use App\Exceptions\NfoPayloadRejected;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
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

    // ---- Wave 60.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 7.49x/7.12x, uncommon 7.32x/7.11x); see RustNzbUploadStagingXmlElementsPar2RecoveryResultFingerprintStoreW60ParityTest. */
    public const bool W60_RUST_FFI_DEFAULT = true;

    private const string W60_RECOVERY_RESULT_FFI_CDEF = <<<'C'
        intptr_t nntmux_w602_par2_recovery_result_summary_batch(const char *items, size_t items_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Summary words after the per-row words, the digest included. */
    public const int W60_RECOVERY_RESULT_TAIL = 10;

    /**
     * Wave 60 routing, binding and output buffer, kept in function-local statics (no new static properties).
     * Operations: null reads the routing override, 'set' replaces it (returning the previous
     * one), 'ffi' resolves the binding and 'out' returns `[binding, buffer, capacity]` with room
     * for at least $val words (null without the library).
     */
    private static function w60RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w60', self::W60_RECOVERY_RESULT_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w60RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    public static function usesW60RustFfi(): bool
    {
        $enabled = self::w60RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W60_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with summarizeRecoveryResultBatch() routed to Rust ($enabled) or PHP, then restores the
     * previous routing.
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

    /** @param  list<int>  $out */
    private static function w60Digest(array $out): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));

        return $digest[1];
    }

    /**
     * The words the export wrote, or null when it declined (returning anything but $words).
     *
     * @return list<int>|null
     */
    private static function w60Words(mixed $written, CData $out, int $words): ?array
    {
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : null;
    }

    /** 1 to $max ASCII digits as an integer, otherwise null. */
    private static function w60Digits(string $value, int $max): ?int
    {
        $length = \strlen($value);

        return $length > 0 && $length <= $max && strspn($value, '0123456789') === $length ? (int) $value : null;
    }

    /** Outcome value => code (case position plus one). */
    private const array W60_OUTCOME_CODES = ['renamed' => 1, 'no_match' => 2, 'retryable' => 3, 'deferred' => 4];

    private const string W60_WS = " \t\r\n";

    /**
     * Per `outcome\tprobes\tarticles\tconfidence\toriginal\trenamed` row `code | bad_counts << 3 |
     * title << 4 | bucket << 7 | verdict << 9 | confidence << 12 | min(len(title), 0x1FFF) << 19 |
     * crc32(lower(title)) << 32`, where title is the renamed title trimmed of `[ \t\r\n]`. Code:
     * the exact Par2RenameOutcome value's case position plus one, 0 unknown. Bad counts: probes or
     * articles not 1-9 digits (each then counts as 0). Title: 0 empty, 1 the trimmed original
     * (ASCII case-insensitive), 2 obfuscated (16 or more hex digits only), 3 dotted (two or more
     * dots), 4 plain. Confidence: 0-100 as 1-3 digits, otherwise 0 in bucket 0; bucket 1 below 40,
     * 2 below 75, 3 otherwise. Verdict: 0 accepted (renamed with title class 2+ and bucket 2+), 1
     * review (other renames), 2 retry, 3 deferred, 4 dropped (no match or unknown). Then the
     * per-verdict counts, the probe, article and in-range confidence totals, the distinct
     * lowercase titles of renamed rows and the XXH64 digest of all earlier words.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function summarizeRecoveryResultBatchPhp(array $rows): array
    {
        $out = $seen = [];
        $verdicts = [0, 0, 0, 0, 0];
        $probesTotal = $articlesTotal = $confidenceTotal = 0;
        foreach ($rows as $row) {
            [$outcome, $probes, $articles, $confidence, $original, $renamed] = explode("\t", (string) $row, 6) + ['', '', '', '', '', ''];
            $code = self::W60_OUTCOME_CODES[$outcome] ?? 0;
            $probeCount = self::w60Digits($probes, 9);
            $articleCount = self::w60Digits($articles, 9);
            $title = trim($renamed, self::W60_WS);
            $length = \strlen($title);
            $class = match (true) {
                $title === '' => 0,
                strcasecmp($title, trim($original, self::W60_WS)) === 0 => 1,
                $length >= 16 && strspn($title, '0123456789abcdefABCDEF') === $length => 2,
                substr_count($title, '.') >= 2 => 3,
                default => 4,
            };
            $value = self::w60Digits($confidence, 3);
            [$score, $bucket] = $value !== null && $value <= 100 ? [$value, $value < 40 ? 1 : ($value < 75 ? 2 : 3)] : [0, 0];
            $verdict = match (true) {
                $code === 1 && $class >= 2 && $bucket >= 2 => 0,
                $code === 1 => 1,
                $code === 3 => 2,
                $code === 4 => 3,
                default => 4,
            };
            $verdicts[$verdict]++;
            $probesTotal += (int) $probeCount;
            $articlesTotal += (int) $articleCount;
            $confidenceTotal += $score;
            $lower = strtolower($title);
            if ($code === 1 && $title !== '') {
                $seen[$lower] = true;
            }
            $out[] = $code | ($probeCount === null || $articleCount === null ? 1 : 0) << 3 | $class << 4 | $bucket << 7 | $verdict << 9 | $score << 12
                | min($length, 0x1FFF) << 19 | crc32($lower) << 32;
        }
        array_push($out, ...$verdicts);
        array_push($out, $probesTotal, $articlesTotal, $confidenceTotal, \count($seen));
        $out[] = self::w60Digest($out);

        return $out;
    }

    /**
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function summarizeRecoveryResultBatch(array $rows): array
    {
        return self::usesW60RustFfi() ? self::summarizeRecoveryResultBatchNative($rows) : self::summarizeRecoveryResultBatchPhp($rows);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a row holds a NUL byte or the
     * column is not a list.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function summarizeRecoveryResultBatchNative(array $rows): array
    {
        $count = \count($rows);
        $words = $count + self::W60_RECOVERY_RESULT_TAIL;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w60RustFfiState('out', $words);
        if ($state === null || ! array_is_list($rows)) {
            return self::summarizeRecoveryResultBatchPhp($rows);
        }
        $blob = implode("\0", $rows);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W60_RECOVERY_RESULT_FFI_CDEF.)
            $written = $ffi->nntmux_w602_par2_recovery_result_summary_batch($blob, \strlen($blob), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }

        return self::w60Words($written, $out, $words) ?? self::summarizeRecoveryResultBatchPhp($rows);
    }
}
