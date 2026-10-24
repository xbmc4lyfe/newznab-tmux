<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Category;
use App\Models\Release;
use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

final class BookProcessingCandidateQuery
{
    public const int MAX_FAILURES = 5;

    public const int EXHAUSTED = -3;

    public const string FFI_CDEF = <<<'C'
        int64_t nntmux_book_candidate_retry_delay(int64_t failures, int64_t provider_retry_after);
        ssize_t nntmux_book_candidate_sql_condition(int32_t kind, int64_t lookup_mode, uint32_t has_late_article_flag, char *out, size_t cap);
        C;

    /** Null selects the benchmark-qualified production default (PHP; see RustTvBookSeriesLookupParityTest). */
    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use;
        if ($use === null) {
            self::$ffi = null;
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    public static function categoryCondition(): string
    {
        return self::usesRustFfi()
            ? self::categoryConditionNative()
            : self::categoryConditionPhp();
    }

    public static function categoryConditionNative(): string
    {
        return self::nativeSqlCondition(0, 0, false) ?? self::categoryConditionPhp();
    }

    public static function categoryConditionPhp(): string
    {
        return '(categories_id BETWEEN '.Category::BOOKS_ROOT.' AND '.Category::BOOKS_UNKNOWN
            .' OR categories_id = '.Category::MUSIC_AUDIOBOOK.')';
    }

    public static function metadataCondition(int $lookupMode): string
    {
        return self::usesRustFfi()
            ? self::metadataConditionNative($lookupMode)
            : self::metadataConditionPhp($lookupMode);
    }

    public static function metadataConditionNative(int $lookupMode): string
    {
        return self::nativeSqlCondition(1, $lookupMode, false)
            ?? self::metadataConditionPhp($lookupMode);
    }

    public static function metadataConditionPhp(int $lookupMode): string
    {
        if ($lookupMode <= 0) {
            return '0 = 1';
        }

        return '(bookinfo_id IS NULL AND book_lookup_attempts < '.self::MAX_FAILURES
            .' AND (book_lookup_retry_at IS NULL OR book_lookup_retry_at <= CURRENT_TIMESTAMP)'
            .($lookupMode === 2 ? ' AND isrenamed = 1' : '').')';
    }

    public static function normalizationCondition(int $lookupMode): string
    {
        return self::usesRustFfi()
            ? self::normalizationConditionNative($lookupMode)
            : self::normalizationConditionPhp($lookupMode);
    }

    public static function normalizationConditionNative(int $lookupMode): string
    {
        return self::nativeSqlCondition(2, $lookupMode, false)
            ?? self::normalizationConditionPhp($lookupMode);
    }

    public static function normalizationConditionPhp(int $lookupMode): string
    {
        if ($lookupMode <= 0) {
            return '0 = 1';
        }

        return '(book_name_normalized_at IS NULL AND ('
            .($lookupMode === 2 ? '' : 'isrenamed = 0 OR ')
            ."searchname LIKE 'N:/NZB%' OR searchname LIKE 'N_NZB_%'"
            ." OR name LIKE 'N:/NZB%' OR name LIKE 'N_NZB_%'))";
    }

    /** Shared by dispatch, workers, and the tmux ready-work count. */
    public static function workCondition(int $lookupMode): string
    {
        return self::usesRustFfi()
            ? self::workConditionNative($lookupMode)
            : self::workConditionPhp($lookupMode);
    }

    public static function workConditionNative(int $lookupMode): string
    {
        return self::nativeSqlCondition(4, $lookupMode, Release::hasLateArticleFlag())
            ?? self::workConditionPhp($lookupMode);
    }

    public static function workConditionPhp(int $lookupMode): string
    {
        return self::categoryConditionPhp().' AND '.self::settledConditionPhp().' AND ('.self::metadataConditionPhp($lookupMode)
            .' OR '.self::normalizationConditionPhp($lookupMode).')';
    }

    /** A partial awaiting a late-article upgrade may be renamed and reset, so a lookup on its title would go stale. */
    public static function settledCondition(): string
    {
        return self::usesRustFfi()
            ? self::settledConditionNative()
            : self::settledConditionPhp();
    }

    public static function settledConditionNative(): string
    {
        return self::nativeSqlCondition(3, 0, Release::hasLateArticleFlag())
            ?? self::settledConditionPhp();
    }

    public static function settledConditionPhp(): string
    {
        return Release::hasLateArticleFlag() ? 'late_article_candidate_id IS NULL' : '1 = 1';
    }

    public static function retryDelay(int $failures, int $providerRetryAfter): int
    {
        return self::usesRustFfi()
            ? self::retryDelayNative($failures, $providerRetryAfter)
            : self::retryDelayPhp($failures, $providerRetryAfter);
    }

    public static function retryDelayNative(int $failures, int $providerRetryAfter): int
    {
        if (($ffi = self::ffi()) === null) {
            return self::retryDelayPhp($failures, $providerRetryAfter);
        }
        try {
            // @phpstan-ignore method.notFound
            $seconds = $ffi->nntmux_book_candidate_retry_delay($failures, $providerRetryAfter);
            if (\is_int($seconds) && $seconds >= 0) {
                return $seconds;
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return self::retryDelayPhp($failures, $providerRetryAfter);
    }

    public static function retryDelayPhp(int $failures, int $providerRetryAfter): int
    {
        return max($providerRetryAfter, match ($failures) {
            1 => 300,
            2 => 900,
            3 => 3600,
            default => 21600,
        });
    }

    private static function nativeSqlCondition(int $kind, int $lookupMode, bool $hasLateArticleFlag): ?string
    {
        if (($ffi = self::ffi()) === null) {
            return null;
        }
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, 512, $capacity);
        if ($buffer === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_book_candidate_sql_condition($kind, $lookupMode, $hasLateArticleFlag ? 1 : 0, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_book_candidate_sql_condition($kind, $lookupMode, $hasLateArticleFlag ? 1 : 0, $buffer, $capacity);
            }
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return null;
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return null;
        }
    }
}
