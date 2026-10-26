<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Category;
use App\Models\Release;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use InvalidArgumentException;
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

    // ---------------------------------------------------------------------
    // Wave 50.4 batch helper
    // ---------------------------------------------------------------------

    /** Enabled: minimum of three paired runs cleared every 1.5x gate (common mean 2.71x, p99 1.99x; uncommon mean 2.87x, p99 2.67x); see RustBookCandidateFanartForkingGenreInvitationW50ParityTest. */
    private const bool W50_RUST_FFI_DEFAULT = true;

    public const string W50_FFI_CDEF = <<<'C'
        ssize_t nntmux_w504_book_candidate_state_batch(const char *text, size_t text_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Candidate flags returned by candidateStateBatch(). */
    public const int W50_CATEGORY = 1;

    public const int W50_SETTLED = 2;

    public const int W50_METADATA = 4;

    public const int W50_NORMALIZATION = 8;

    public const int W50_WORK = 16;

    public const int W50_PLACEHOLDER = 32;

    private const int W50_SCALARS = 8;

    private static ?bool $useW50RustFfi = null;

    private static FFI|false|null $w50Ffi = null;

    private static ?CData $w50Out = null;

    private static int $w50OutCap = 0;

    public static function usesW50RustFfi(): bool
    {
        return self::$useW50RustFfi ?? self::W50_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W50 batch dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW50RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$useW50RustFfi;
        self::$useW50RustFfi = $enabled;
        try {
            return $callback();
        } finally {
            self::$useW50RustFfi = $previous;
        }
    }

    /**
     * Evaluate the SQL candidate predicates for a batch of fetched releases, two ints each:
     * [flags, next retry delay]. Each release supplies eight ints in $scalars
     * ([categories_id, isrenamed, has bookinfo_id 0/1, book_lookup_attempts, retry due 0/1
     * (book_lookup_retry_at NULL or past), normalized 0/1 (book_name_normalized_at set),
     * late candidate 0/1 (late_article_candidate_id set), provider retry after]) plus its
     * searchname and name.
     *
     * Flags mirror categoryCondition() (1), settledCondition() (2), metadataCondition() (4),
     * normalizationCondition() (8) and workCondition() (16); 32 marks a placeholder name
     * (LIKE 'N:/NZB%' or 'N_NZB_%', bytewise, ASCII case-insensitive). The delay is
     * retryDelay() for one more failure, or EXHAUSTED once that failure reaches MAX_FAILURES.
     *
     * @param  list<int>  $scalars
     * @param  list<string>  $searchnames
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function candidateStateBatch(int $lookupMode, bool $hasLateArticleFlag, array $scalars, array $searchnames, array $names): array
    {
        return self::usesW50RustFfi()
            ? self::candidateStateBatchNative($lookupMode, $hasLateArticleFlag, $scalars, $searchnames, $names)
            : self::candidateStateBatchPhp($lookupMode, $hasLateArticleFlag, $scalars, $searchnames, $names);
    }

    /**
     * @param  list<int>  $scalars
     * @param  list<string>  $searchnames
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function candidateStateBatchNative(int $lookupMode, bool $hasLateArticleFlag, array $scalars, array $searchnames, array $names): array
    {
        self::w50AssertShape($scalars, $searchnames, $names);
        if ($searchnames === []) {
            return [];
        }
        $ints = pack('P*', $lookupMode, (int) $hasLateArticleFlag, Category::BOOKS_ROOT, Category::BOOKS_UNKNOWN, Category::MUSIC_AUDIOBOOK, self::MAX_FAILURES, ...$scalars);

        return self::w50Call(implode("\0", $searchnames)."\0".implode("\0", $names), $ints, \count($searchnames))
            ?? self::candidateStateBatchPhp($lookupMode, $hasLateArticleFlag, $scalars, $searchnames, $names);
    }

    /**
     * @param  list<int>  $scalars
     * @param  list<string>  $searchnames
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function candidateStateBatchPhp(int $lookupMode, bool $hasLateArticleFlag, array $scalars, array $searchnames, array $names): array
    {
        self::w50AssertShape($scalars, $searchnames, $names);
        $out = [];
        $at = 0;
        foreach ($searchnames as $i => $searchname) {
            $category = $scalars[$at];
            $renamed = $scalars[$at + 1];
            $attempts = $scalars[$at + 3];
            $flags = 0;
            if (($category >= Category::BOOKS_ROOT && $category <= Category::BOOKS_UNKNOWN) || $category === Category::MUSIC_AUDIOBOOK) {
                $flags |= self::W50_CATEGORY;
            }
            if (! $hasLateArticleFlag || $scalars[$at + 6] === 0) {
                $flags |= self::W50_SETTLED;
            }
            if (self::w50Placeholder($searchname) || self::w50Placeholder($names[$i])) {
                $flags |= self::W50_PLACEHOLDER;
            }
            if ($lookupMode > 0) {
                if ($scalars[$at + 2] === 0 && $attempts < self::MAX_FAILURES && $scalars[$at + 4] !== 0 && ($lookupMode !== 2 || $renamed === 1)) {
                    $flags |= self::W50_METADATA;
                }
                if ($scalars[$at + 5] === 0 && (($lookupMode !== 2 && $renamed === 0) || ($flags & self::W50_PLACEHOLDER) !== 0)) {
                    $flags |= self::W50_NORMALIZATION;
                }
            }
            if (($flags & 3) === 3 && ($flags & 12) !== 0) {
                $flags |= self::W50_WORK;
            }
            $failures = $attempts < PHP_INT_MAX ? $attempts + 1 : PHP_INT_MAX;
            $out[] = $flags;
            $out[] = $failures >= self::MAX_FAILURES ? self::EXHAUSTED : self::retryDelayPhp($failures, $scalars[$at + 7]);
            $at += self::W50_SCALARS;
        }

        return $out;
    }

    /** LIKE 'N:/NZB%' OR LIKE 'N_NZB_%' under a case-insensitive collation, compared bytewise. */
    private static function w50Placeholder(string $name): bool
    {
        return isset($name[5]) && ($name[0] === 'N' || $name[0] === 'n')
            && (strncasecmp(substr($name, 2, 3), 'nzb', 3) === 0 || strncasecmp($name, 'n:/nzb', 6) === 0);
    }

    /**
     * One FFI crossing for the batch: NUL-joined text columns, little-endian int64 header and
     * scalars, two int64 words back per record. Null when the library is missing or declines.
     *
     * @return list<int>|null
     */
    private static function w50Call(string $text, string $ints, int $count): ?array
    {
        try {
            self::$w50Ffi ??= RustPipelineLibrary::resolveDomain('metadata_w50', self::W50_FFI_CDEF) ?? false;
            $ffi = self::$w50Ffi;
            if ($ffi === false) {
                return null;
            }
            $need = $count * 2;
            $out = self::$w50Out;
            if ($out === null || self::$w50OutCap < $need) {
                self::$w50OutCap = max(1024, $need);
                $out = self::$w50Out = $ffi->new('int64_t['.self::$w50OutCap.']');
            }
            // @phpstan-ignore method.notFound (Declared by W50_FFI_CDEF.)
            $written = $ffi->nntmux_w504_book_candidate_state_batch($text, \strlen($text), $ints, \strlen($ints), $count, $out, self::$w50OutCap);
            if ($written !== $need) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $need * 8));

            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<array-key, mixed>  $scalars
     * @param  array<array-key, mixed>  $searchnames
     * @param  array<array-key, mixed>  $names
     */
    private static function w50AssertShape(array $scalars, array $searchnames, array $names): void
    {
        $count = \count($searchnames);
        if (! array_is_list($scalars) || ! array_is_list($searchnames) || ! array_is_list($names)
            || \count($names) !== $count || \count($scalars) !== $count * self::W50_SCALARS) {
            throw new InvalidArgumentException('W50 book candidate batches need list columns of equal length and eight scalars per release.');
        }
    }

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 13.82x, p99 9.09x; uncommon mean 9.32x, p99 6.09x); see RustBinariesObfuscationPartHandlerGateBookQueryServiceW66ParityTest. */
    public const bool W66_RUST_FFI_DEFAULT = true;

    public const string W66_BOOK_CANDIDATE_QUERY_FFI_CDEF = <<<'C'
        ssize_t nntmux_w663_book_candidate_query_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of the Wave 66.3 batch. */
    public const int W66_BOOK_CANDIDATE_QUERY_TRAILER = 24;

    public static function enableW66RustFfi(): void
    {
        self::w66RustFfiState('set', true);
    }

    public static function disableW66RustFfi(): void
    {
        self::w66RustFfiState('set', false);
    }

    /** Restores W66_RUST_FFI_DEFAULT and drops the cached library handle and scratch buffers. */
    public static function resetW66RustFfi(): void
    {
        self::w66RustFfiState('reset');
    }

    public static function usesW66RustFfi(): bool
    {
        $override = self::w66RustFfiState();

        return \is_bool($override) ? $override : self::W66_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the BookProcessingCandidateQuery Wave 66 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW66RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w66RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w66RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 66.3 candidate query plan with retryDelayPhp() and MAX_FAILURES. Each item is
     * `categoryId|failures|lastAttemptAge|providerRetryAfter|lookupStatus|bookinfoId`. The category (up to 6 trimmed
     * digits) is 0 empty, 1 ebook (7020), 2 comics (7030), 3 magazines (7010), 4 technical (7040), 5 foreign (7060), 6
     * other 7000-7999, 7 not a book or 8 malformed. Failures (3 digits), age (10), retry-after (9) and bookinfo (10)
     * are 0 empty, 1 digits or 2 other; the status is 0 empty, 1 `pending`, 2 `matched`, 3 `failed`, 4 `skipped` (any
     * case) or 5 other. The retry delay is retryDelayPhp() after a failure (0 otherwise) and the window is open once the
     * age reaches it. The decision is 5 invalid (any state 2 or status 5), 0 skipped (no or non-book category), 1
     * settled (linked bookinfo or matched), 2 exhausted (MAX_FAILURES or skipped), 3 waiting or 4 eligible; eligible
     * items are ranked by fewest failures, then oldest attempt, then input order. Per item `category | categoryState
     * << 4 | failuresState << 6 | ageState << 8 | retryState << 10 | status << 12 | bookinfoState << 15 | linked << 17
     * | exhausted << 18 | windowOpen << 19 | decision << 20 | rank (1-based, cap 2^20 - 1) << 23 | wait (cap 2^20 - 1)
     * << 43`. Then the six decision, nine category and six status counts, the shortest wait among waiting items (0
     * none), the failure total and the 1-based index of the top-ranked item (0 none).
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w66BookCandidateQueryPlanBatch(array $items): array
    {
        return self::usesW66RustFfi() ? self::w66BookCandidateQueryPlanBatchNative($items) : self::w66BookCandidateQueryPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w66BookCandidateQueryPlanBatchNative(array $items): array
    {
        return self::w66Invoke($items, \count($items) + self::W66_BOOK_CANDIDATE_QUERY_TRAILER) ?? self::w66BookCandidateQueryPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w66BookCandidateQueryPlanBatchPhp(array $items): array
    {
        $out = [];
        $decisions = [0, 0, 0, 0, 0, 0];
        $kinds = [0, 0, 0, 0, 0, 0, 0, 0, 0];
        $statuses = [0, 0, 0, 0, 0, 0];
        $minWait = $failureSum = 0;
        $eligible = [];
        foreach ($items as $item) {
            [$rawCategory, $rawFailures, $rawAge, $rawRetry, $rawStatus, $rawBook] = explode('|', (string) $item, 6) + ['', '', '', '', '', ''];
            [$categoryState, $category] = self::w66Count($rawCategory, 6);
            $kind = match (true) {
                $categoryState === 0 => 0,
                $categoryState === 2 => 8,
                default => self::W66_BOOK_CATEGORIES[$category] ?? ($category >= 7000 && $category <= 7999 ? 6 : 7),
            };
            [$failuresState, $failures] = self::w66Count($rawFailures, 3);
            [$ageState, $age] = self::w66Count($rawAge, 10);
            [$retryState, $retry] = self::w66Count($rawRetry, 9);
            $statusText = strtolower(trim($rawStatus));
            $status = $statusText === '' ? 0 : (self::W66_LOOKUP_STATUSES[$statusText] ?? 5);
            [$bookState, $book] = self::w66Count($rawBook, 10);
            $linked = $bookState === 1 && $book > 0;
            $exhausted = $failures >= self::MAX_FAILURES;
            $delay = $failures > 0 ? self::retryDelayPhp($failures, $retry) : 0;
            $windowOpen = $age >= $delay;
            $wait = max(0, $delay - $age);
            if ($categoryState === 2 || $failuresState === 2 || $ageState === 2 || $retryState === 2 || $bookState === 2 || $status === 5) {
                $decision = 5;
            } elseif ($kind === 0 || $kind === 7) {
                $decision = 0;
            } elseif ($linked || $status === 2) {
                $decision = 1;
            } elseif ($exhausted || $status === 4) {
                $decision = 2;
            } else {
                $decision = $windowOpen ? 4 : 3;
            }
            $decisions[$decision]++;
            $kinds[$kind]++;
            $statuses[$status]++;
            $failureSum += $failures;
            if ($decision === 3 && ($minWait === 0 || $wait < $minWait)) {
                $minWait = $wait;
            }
            if ($decision === 4) {
                $eligible[] = [$failures, $age, \count($out)];
            }
            $out[] = $kind | $categoryState << 4 | $failuresState << 6 | $ageState << 8 | $retryState << 10 | $status << 12 | $bookState << 15
                | ($linked ? 1 : 0) << 17 | ($exhausted ? 1 : 0) << 18 | ($windowOpen ? 1 : 0) << 19 | $decision << 20 | min($wait, 1_048_575) << 43;
        }
        usort($eligible, static fn (array $a, array $b): int => [$a[0], $b[1], $a[2]] <=> [$b[0], $a[1], $b[2]]);
        foreach ($eligible as $rank => [, , $index]) {
            $out[$index] |= min($rank + 1, 1_048_575) << 23;
        }
        array_push($out, ...$decisions);
        array_push($out, ...$kinds);
        array_push($out, ...$statuses);
        array_push($out, $minWait, $failureSum, $eligible === [] ? 0 : $eligible[0][2] + 1);

        return $out;
    }

    /** Wave 66.3 book category id => plan code (other 7000-7999 ids are 6, the rest 7). */
    private const array W66_BOOK_CATEGORIES = [7020 => 1, 7030 => 2, 7010 => 3, 7040 => 4, 7060 => 5];

    /** Lower-cased lookup status => Wave 66.3 code (empty 0, anything else 5). */
    private const array W66_LOOKUP_STATUSES = ['pending' => 1, 'matched' => 2, 'failed' => 3, 'skipped' => 4];

    /**
     * (0 empty, 1 one to $max ASCII digits, 2 other; the value) for the trimmed field.
     *
     * @return array{int, int}
     */
    private static function w66Count(string $text, int $max): array
    {
        $text = trim($text);
        if ($text === '') {
            return [0, 0];
        }
        $length = \strlen($text);

        return $length <= $max && strspn($text, '0123456789') === $length ? [1, (int) $text] : [2, 0];
    }

    /**
     * Wave 66 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and returns the
     * previous override, 'reset' clears the override, library handle and buffers, 'ffi' resolves the library
     * (null when unavailable) and 'out' and 'lens' return `int64_t` output and length-column buffers of at least
     * $val words. Function statics keep the class's property set unchanged.
     */
    private static function w66RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'reset') {
            $override = $ffi = $out = $lens = null;
            $outCap = $lensCap = 0;

            return null;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('binaries_w663', self::W66_BOOK_CANDIDATE_QUERY_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w66RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w66RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 66.3 BookProcessingCandidateQuery export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call declines
     * or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w66Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w66RustFfiState('ffi');
            $out = self::w66RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w66RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W66_BOOK_CANDIDATE_QUERY_FFI_CDEF.)
            $written = $ffi->nntmux_w663_book_candidate_query_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
