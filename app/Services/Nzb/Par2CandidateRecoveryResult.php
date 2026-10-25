<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Enums\Par2RenameOutcome;
use App\Exceptions\NfoPayloadRejected;

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
}
