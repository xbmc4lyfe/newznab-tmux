<?php

declare(strict_types=1);

namespace App\Services\TvProcessing;

use App\Enums\TvLookupStatus;
use App\Models\Category;
use App\Models\Release;
use App\Support\RustPipelineLibrary;
use FFI;
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
