<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Services\Nzb\NzbArticleFingerprintUnavailable;
use App\Support\RustPipelineLibrary;
use FFI;
use RuntimeException;

/** A partial upload does not meet publication policy yet; later articles could still let it publish. */
final class PartialPublicationDeferredException extends RuntimeException
{
    public const string FFI_CDEF = NzbArticleFingerprintUnavailable::FFI_CDEF;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustVerificationCapacityPrefileSingletonParityTest.php is below 1.5x.
     */
    public const bool RUST_FFI_DEFAULT = false;

    /** Counts are clamped to [0, 2^40] so percentages cannot overflow. */
    private const int COUNT_MAX = 1099511627776;

    private const int DEFAULT_MAX_DEFERRALS = 10;

    private const int BASE_DELAY_MS = 1000;

    private const int MAX_DELAY_MS = 300000;

    private const array ACTIONS = ['publish', 'defer', 'abandon', 'reject_empty'];

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public static function useRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = null;
        self::$ffi = null;
    }

    /**
     * Decides what a partial upload's publication attempt should do. Counts are clamped to [0, 2^40] (present to
     * expected), the threshold to [1, 100] percent, and a deferral limit below one selects 10. An empty upload is
     * rejected, one meeting the threshold publishes, one out of deferrals is abandoned, otherwise it is deferred with
     * an exponential delay of 1 s doubling per deferral (capped at 300 s).
     *
     * @return array{articles_present: int, articles_expected: int, min_completion_percent: int, deferrals: int, max_deferrals: int, missing_articles: int, completion_percent: int, meets_threshold: bool, deferrals_exhausted: bool, action: string, can_still_publish: bool, retry_delay_ms: int}
     */
    public static function summarizePublicationPolicy(int $articlesPresent, int $articlesExpected, int $minCompletionPercent, int $deferrals, int $maxDeferrals): array
    {
        return (self::usesRustFfi() ? self::summarizePublicationPolicyNative($articlesPresent, $articlesExpected, $minCompletionPercent, $deferrals, $maxDeferrals) : null)
            ?? self::summarizePublicationPolicyPhp($articlesPresent, $articlesExpected, $minCompletionPercent, $deferrals, $maxDeferrals);
    }

    /**
     * @return array{articles_present: int, articles_expected: int, min_completion_percent: int, deferrals: int, max_deferrals: int, missing_articles: int, completion_percent: int, meets_threshold: bool, deferrals_exhausted: bool, action: string, can_still_publish: bool, retry_delay_ms: int}|null
     */
    private static function summarizePublicationPolicyNative(int $articlesPresent, int $articlesExpected, int $minCompletionPercent, int $deferrals, int $maxDeferrals): ?array
    {
        $s = NzbArticleFingerprintUnavailable::evaluateW372(self::ffi(), 2, 0,
            pack('q5', $articlesPresent, $articlesExpected, $minCompletionPercent, $deferrals, $maxDeferrals), 12);
        if ($s === null || $s[1] < 0 || $s[1] > self::COUNT_MAX || $s[0] < 0 || $s[0] > $s[1] || $s[2] < 1 || $s[2] > 100 || $s[3] < 0
            || $s[3] > self::COUNT_MAX || $s[4] < 1 || $s[4] > self::COUNT_MAX || $s[5] !== $s[1] - $s[0] || $s[6] < 0 || $s[6] > 100
            || ! \in_array($s[7], [0, 1], true) || ! \in_array($s[8], [0, 1], true) || ! isset(self::ACTIONS[$s[9]])
            || ! \in_array($s[10], [0, 1], true) || $s[11] < 0 || $s[11] > self::MAX_DELAY_MS) {
            return null;
        }

        return [
            'articles_present' => $s[0],
            'articles_expected' => $s[1],
            'min_completion_percent' => $s[2],
            'deferrals' => $s[3],
            'max_deferrals' => $s[4],
            'missing_articles' => $s[5],
            'completion_percent' => $s[6],
            'meets_threshold' => $s[7] === 1,
            'deferrals_exhausted' => $s[8] === 1,
            'action' => self::ACTIONS[$s[9]],
            'can_still_publish' => $s[10] === 1,
            'retry_delay_ms' => $s[11],
        ];
    }

    /**
     * @return array{articles_present: int, articles_expected: int, min_completion_percent: int, deferrals: int, max_deferrals: int, missing_articles: int, completion_percent: int, meets_threshold: bool, deferrals_exhausted: bool, action: string, can_still_publish: bool, retry_delay_ms: int}
     */
    private static function summarizePublicationPolicyPhp(int $articlesPresent, int $articlesExpected, int $minCompletionPercent, int $deferrals, int $maxDeferrals): array
    {
        $expected = max(0, min(self::COUNT_MAX, $articlesExpected));
        $present = min($expected, max(0, min(self::COUNT_MAX, $articlesPresent)));
        $minPercent = max(1, min(100, $minCompletionPercent));
        $deferred = max(0, min(self::COUNT_MAX, $deferrals));
        $limit = $maxDeferrals >= 1 ? min(self::COUNT_MAX, $maxDeferrals) : self::DEFAULT_MAX_DEFERRALS;
        $completion = $expected > 0 ? intdiv($present * 100, $expected) : 0;
        $meets = $expected > 0 && $completion >= $minPercent;
        $exhausted = $deferred >= $limit;
        $action = match (true) {
            $expected === 0 => 'reject_empty',
            $meets => 'publish',
            $exhausted => 'abandon',
            default => 'defer',
        };

        return [
            'articles_present' => $present,
            'articles_expected' => $expected,
            'min_completion_percent' => $minPercent,
            'deferrals' => $deferred,
            'max_deferrals' => $limit,
            'missing_articles' => $expected - $present,
            'completion_percent' => $completion,
            'meets_threshold' => $meets,
            'deferrals_exhausted' => $exhausted,
            'action' => $action,
            'can_still_publish' => $action === 'defer',
            'retry_delay_ms' => $action === 'defer' ? min(self::MAX_DELAY_MS, self::BASE_DELAY_MS << min(9, $deferred)) : 0,
        ];
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain(NzbArticleFingerprintUnavailable::FFI_DOMAIN, self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }
}
