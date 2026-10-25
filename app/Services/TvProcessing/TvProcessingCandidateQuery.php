<?php

declare(strict_types=1);

namespace App\Services\TvProcessing;

use App\Enums\TvLookupStatus;
use App\Models\Category;
use App\Models\Release;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * SQL shared by the TV bucket dispatcher, the pipeline workers, the tmux ready-work
 * count and the episode retry command, so they can never drift apart.
 */
final class TvProcessingCandidateQuery
{
    /** Transient failures before a release with no show is given up as ShowNotFound. */
    public const int MAX_TRANSIENT_ATTEMPTS = 8;

    /** Episode lookups for a known show before the release stops being retried. */
    public const int MAX_EPISODE_ATTEMPTS = 6;

    /** Delay after a transient failure on a release whose show is already known. */
    public const int KNOWN_SHOW_TRANSIENT_DELAY = 900;

    public const string FFI_CDEF = <<<'C'
        int64_t nntmux_tv_candidate_backoff_seconds(int32_t kind, int64_t attempts, int64_t provider_retry_after);
        C;

    /** Null selects the benchmark-qualified production default (PHP; see RustTvShowAdderAndMetadataParityTest). */
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
        return '(categories_id BETWEEN '.Category::TV_ROOT.' AND '.Category::TV_OTHER
            .' AND categories_id <> '.Category::TV_ANIME.' AND size > 1048576)';
    }

    /** Releases with no show yet that are due for the bucket workers. */
    public static function workCondition(int $lookupMode): string
    {
        if ($lookupMode <= 0) {
            return '0 = 1';
        }

        return self::categoryCondition()
            .' AND videos_id = 0 AND tv_episodes_id = '.TvLookupStatus::Pending->value
            .' AND '.self::settledCondition()
            .' AND '.self::dueCondition()
            .($lookupMode === 2 ? ' AND isrenamed = 1' : '');
    }

    /** Releases linked to a show whose episode is due for another lookup. */
    public static function episodeRetryCondition(bool $ignoreSchedule = false): string
    {
        $condition = self::categoryCondition()
            .' AND videos_id > 0 AND tv_episodes_id = '.TvLookupStatus::EpisodeMissing->value
            .' AND '.self::settledCondition();

        if ($ignoreSchedule) {
            return $condition;
        }

        return $condition.' AND tv_lookup_attempts < '.self::MAX_EPISODE_ATTEMPTS.' AND '.self::dueCondition();
    }

    public static function transientDelay(int $attempts, int $providerRetryAfter = 0): int
    {
        return self::usesRustFfi()
            ? self::transientDelayNative($attempts, $providerRetryAfter)
            : self::transientDelayPhp($attempts, $providerRetryAfter);
    }

    public static function transientDelayNative(int $attempts, int $providerRetryAfter = 0): int
    {
        return self::nativeBackoff(0, $attempts, $providerRetryAfter)
            ?? self::transientDelayPhp($attempts, $providerRetryAfter);
    }

    public static function transientDelayPhp(int $attempts, int $providerRetryAfter = 0): int
    {
        return max($providerRetryAfter, match ($attempts) {
            1 => 300,
            2 => 900,
            3 => 3600,
            default => 21600,
        });
    }

    public static function episodeDelay(int $attempts): int
    {
        return self::usesRustFfi()
            ? self::episodeDelayNative($attempts)
            : self::episodeDelayPhp($attempts);
    }

    public static function episodeDelayNative(int $attempts): int
    {
        return self::nativeBackoff(1, $attempts, 0) ?? self::episodeDelayPhp($attempts);
    }

    public static function episodeDelayPhp(int $attempts): int
    {
        return match ($attempts) {
            1 => 3600,
            2 => 21600,
            3 => 86400,
            4 => 259200,
            default => 604800,
        };
    }

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean 2.95x, p99 2.69x; uncommon mean 2.19x, p99 1.97x); see RustTvCandidatePipelineTvMazeTvdbHydrationW50ParityTest. */
    private const W50_RUST_FFI_DEFAULT = true;

    public const string W50_FFI_CDEF = <<<'C'
        ssize_t nntmux_w503_tv_candidate_plan_batch(const char *blob, size_t blob_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    private static ?bool $useW50RustFfi = null;

    private static FFI|false|null $w50Ffi = null;

    private static ?CData $w50Out = null;

    private static int $w50OutCap = 0;

    public static function usesW50RustFfi(): bool
    {
        return self::$useW50RustFfi ?? self::W50_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 50 Rust path forced on or off, then restores the previous setting. */
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
     * Plans a batch of release rows against workCondition()/episodeRetryCondition() at $now.
     * Four values per row: the bucket (0 outside the TV categories or size floor, 1 work due,
     * 2 work waiting, 3 episode retry due, 4 episode retry waiting, 5 episode attempts exhausted,
     * 6 unsettled by a late-article candidate, 7 otherwise or excluded by $lookupMode), the
     * tv_lookup_retry_at state (0 empty, 1 a valid UTC `Y-m-d H:i:s` or `Y-m-d\TH:i:s`, 2 invalid,
     * which never falls due), its Unix epoch (0 unless valid) and the next delay
     * (transientDelayPhp() for work, episodeDelayPhp() for retries, keyed by tv_lookup_attempts;
     * else 0). Then the eight bucket counts and the earliest valid waiting retry epoch
     * (PHP_INT_MAX when none). Integer fields read as `(int)`; a non-zero
     * late_article_candidate_id marks the row unsettled.
     *
     * @param  list<array<string, mixed>>  $rows  categories_id, size, videos_id, tv_episodes_id, tv_lookup_attempts, isrenamed, late_article_candidate_id, tv_lookup_retry_at
     * @return list<int>
     */
    public static function w50CandidatePlanBatch(array $rows, int $now, int $lookupMode = 1): array
    {
        return self::usesW50RustFfi()
            ? self::w50CandidatePlanBatchNative($rows, $now, $lookupMode)
            : self::w50CandidatePlanBatchPhp($rows, $now, $lookupMode);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<int>
     */
    public static function w50CandidatePlanBatchNative(array $rows, int $now, int $lookupMode = 1): array
    {
        $count = \count($rows);
        $columns = self::w50Columns($rows, self::W50_COLUMNS);
        $ints = pack('q*', $now, $lookupMode);
        foreach (self::W50_COLUMNS as $field => $default) {
            if ($field !== 'tv_lookup_retry_at') {
                $ints .= pack('q*', ...$columns[$field]);
            }
        }

        return self::w50Invoke(implode("\0", $columns['tv_lookup_retry_at']), $ints, $count, $count * 4 + 9)
            ?? self::w50CandidatePlanBatchPhp($rows, $now, $lookupMode);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<int>
     */
    public static function w50CandidatePlanBatchPhp(array $rows, int $now, int $lookupMode = 1): array
    {
        $out = [];
        $tally = array_fill(0, 8, 0);
        $wake = PHP_INT_MAX;
        foreach ($rows as $row) {
            $category = (int) ($row['categories_id'] ?? 0);
            $videos = (int) ($row['videos_id'] ?? 0);
            $episodes = (int) ($row['tv_episodes_id'] ?? 0);
            $attempts = (int) ($row['tv_lookup_attempts'] ?? 0);
            $retry = (string) ($row['tv_lookup_retry_at'] ?? '');
            $state = 0;
            $epoch = 0;
            if ($retry !== '') {
                $state = 2;
                if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})$/D', $retry, $m) === 1
                    && (int) $m[1] > 0 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])
                    && (int) $m[4] < 24 && (int) $m[5] < 60 && (int) $m[6] < 60) {
                    $state = 1;
                    $epoch = self::w50DaysFromCivil((int) $m[1], (int) $m[2], (int) $m[3]) * 86400
                        + (int) $m[4] * 3600 + (int) $m[5] * 60 + (int) $m[6];
                }
            }
            $due = $state === 0 || ($state === 1 && $epoch <= $now);
            if ($category < Category::TV_ROOT || $category > Category::TV_OTHER || $category === Category::TV_ANIME
                || (int) ($row['size'] ?? 0) <= 1048576) {
                $bucket = 0;
            } elseif ((int) ($row['late_article_candidate_id'] ?? 0) !== 0) {
                $bucket = 6;
            } elseif ($videos === 0 && $episodes === TvLookupStatus::Pending->value) {
                $bucket = $lookupMode <= 0 || ($lookupMode === 2 && (int) ($row['isrenamed'] ?? 0) !== 1) ? 7 : ($due ? 1 : 2);
            } elseif ($videos > 0 && $episodes === TvLookupStatus::EpisodeMissing->value) {
                $bucket = $attempts >= self::MAX_EPISODE_ATTEMPTS ? 5 : ($due ? 3 : 4);
            } else {
                $bucket = 7;
            }
            $delay = match ($bucket) {
                1, 2 => self::transientDelayPhp($attempts),
                3, 4 => self::episodeDelayPhp($attempts),
                default => 0,
            };
            if (($bucket === 2 || $bucket === 4) && $state === 1) {
                $wake = min($wake, $epoch);
            }
            $tally[$bucket]++;
            array_push($out, $bucket, $state, $epoch, $delay);
        }
        array_push($out, ...$tally);
        $out[] = $wake;

        return $out;
    }

    /** Integer columns packed after the `now, lookupMode` header in Rust column order, then the retry string column. */
    private const array W50_COLUMNS = ['categories_id' => 0, 'size' => 0, 'videos_id' => 0, 'tv_episodes_id' => 0, 'tv_lookup_attempts' => 0,
        'isrenamed' => 0, 'late_article_candidate_id' => 0, 'tv_lookup_retry_at' => ''];

    /** Days since 1970-01-01 of a proleptic Gregorian date with a positive year. */
    private static function w50DaysFromCivil(int $year, int $month, int $day): int
    {
        $year -= $month <= 2 ? 1 : 0;
        $era = intdiv($year, 400);
        $yoe = $year - $era * 400;
        $doy = intdiv(153 * ($month + ($month > 2 ? -3 : 9)) + 2, 5) + $day - 1;

        return $era * 146097 + $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy - 719468;
    }

    /**
     * One column per key of $defaults. Rows missing a key read its default; present nulls stay
     * null, which pack() and implode() read as 0 and '' like the defaults they replace.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $defaults
     * @return array<string, list<mixed>>
     */
    private static function w50Columns(array $rows, array $defaults): array
    {
        $count = \count($rows);
        $columns = [];
        foreach ($defaults as $key => $default) {
            $column = array_column($rows, $key);
            if (\count($column) !== $count) {
                $filled = [];
                foreach ($rows as $row) {
                    $filled[] = $row + $defaults;
                }
                $columns = [];
                foreach ($defaults as $name => $unused) {
                    $columns[$name] = array_column($filled, $name);
                }

                return $columns;
            }
            $columns[$key] = $column;
        }

        return $columns;
    }

    /**
     * Calls the Wave 50 export with NUL-joined strings and packed `i64` integers. Null when the
     * library is missing or the call declines (for example a string holding a NUL byte), so the
     * caller falls back to PHP.
     *
     * @return list<int>|null
     */
    private static function w50Invoke(string $blob, string $ints, int $count, int $words): ?array
    {
        self::$w50Ffi ??= RustPipelineLibrary::resolveDomain('categorize_w50', self::W50_FFI_CDEF) ?? false;
        $ffi = self::$w50Ffi;
        if ($ffi === false) {
            return null;
        }
        try {
            if (self::$w50Out === null || self::$w50OutCap < $words) {
                self::$w50OutCap = max(1024, $words * 2);
                self::$w50Out = $ffi->new('int64_t['.self::$w50OutCap.']');
            }
            // @phpstan-ignore method.notFound (Declared by W50_FFI_CDEF.)
            $written = $ffi->nntmux_w503_tv_candidate_plan_batch($blob, \strlen($blob), $ints, \strlen($ints), $count, self::$w50Out, self::$w50OutCap);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string(self::$w50Out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    private static function nativeBackoff(int $kind, int $attempts, int $providerRetryAfter): ?int
    {
        if (($ffi = self::ffi()) === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $seconds = $ffi->nntmux_tv_candidate_backoff_seconds($kind, $attempts, $providerRetryAfter);
        } catch (Throwable) {
            return null;
        }

        // Valid kinds never yield a negative delay; -1 is the decline sentinel.
        return \is_int($seconds) && $seconds >= 0 ? $seconds : null;
    }

    /**
     * Columns that hand a release back to TV processing from scratch.
     *
     * @return array{videos_id: int, tv_episodes_id: int, tv_lookup_attempts: int, tv_lookup_retry_at: null}
     */
    public static function resetColumns(): array
    {
        return [
            'videos_id' => 0,
            'tv_episodes_id' => TvLookupStatus::Pending->value,
            'tv_lookup_attempts' => 0,
            'tv_lookup_retry_at' => null,
        ];
    }

    /** A partial awaiting a late-article upgrade may be renamed and reset, so a lookup on its title would go stale. */
    public static function settledCondition(): string
    {
        return Release::hasLateArticleFlag() ? 'late_article_candidate_id IS NULL' : '1 = 1';
    }

    private static function dueCondition(): string
    {
        return '(tv_lookup_retry_at IS NULL OR tv_lookup_retry_at <= CURRENT_TIMESTAMP)';
    }
}
