<?php

declare(strict_types=1);

namespace App\Services\TvProcessing;

use App\Enums\TvLookupStatus;
use App\Models\Release;
use App\Services\Releases\ReleaseBrowseService;
use App\Support\RustPipelineLibrary;
use Carbon\CarbonInterface;
use FFI;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The single place that writes a TV lookup outcome back to its release.
 *
 * Writes only when the searchname is unchanged, so a rename during processing isn't
 * overwritten. A changed show or episode link is saved through Eloquent so
 * ReleaseObserver reindexes the release; lookup-state-only changes skip the reindex,
 * because the search index treats every tv_episodes_id <= 0 the same.
 */
class TvLookupOutcomeWriter
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_tv_outcome_has_link_changed(int64_t prev_video_id, int64_t prev_episode_id, int64_t new_video_id, int64_t new_episode_id);
        int32_t nntmux_tv_outcome_should_bump_cache(uint32_t linked, const char *status, size_t status_len);
        int32_t nntmux_tv_outcome_resolve(int64_t prev_video_id, int64_t prev_episode_id, int64_t prev_attempts, const char *status, size_t status_len, int64_t video_id, int64_t episode_id, uint32_t transient, int64_t retry_after, int64_t *out_video_id, int64_t *out_episode_status, int64_t *out_attempts, int64_t *out_retry_delay, int32_t *out_link_changed);
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

    public function record(TvReleaseContext $context, TvProcessingResult $result): void
    {
        $linked = DB::transaction(function () use ($context, $result): bool {
            /** @var Release|null $release */
            $release = Release::query()
                ->whereKey($context->releaseId)
                ->where('searchname', $context->searchName)
                ->lockForUpdate()
                ->first(['id', 'searchname', 'videos_id', 'tv_episodes_id', 'tv_lookup_attempts', 'tv_lookup_retry_at']);

            if ($release === null) {
                return false;
            }

            $columns = $this->columnsFor($release, $result);
            $linkChanged = self::hasLinkChanged(
                (int) $release->videos_id,
                (int) $release->tv_episodes_id,
                $columns['videos_id'],
                $columns['tv_episodes_id'],
            );

            if ($linkChanged) {
                $release->forceFill($columns)->save();
            } else {
                Release::query()->whereKey($release->id)->update($columns);
            }

            return $linkChanged;
        });

        if (self::shouldBumpCacheVersion($linked, $result->status)) {
            ReleaseBrowseService::bumpCacheVersion();
        }
    }

    public static function hasLinkChanged(
        int $previousVideoId,
        int $previousEpisodeId,
        int $newVideoId,
        int $newEpisodeId,
    ): bool {
        return self::usesRustFfi()
            ? self::hasLinkChangedNative($previousVideoId, $previousEpisodeId, $newVideoId, $newEpisodeId)
            : self::hasLinkChangedPhp($previousVideoId, $previousEpisodeId, $newVideoId, $newEpisodeId);
    }

    public static function hasLinkChangedNative(
        int $previousVideoId,
        int $previousEpisodeId,
        int $newVideoId,
        int $newEpisodeId,
    ): bool {
        if (($ffi = self::ffi()) === null) {
            return self::hasLinkChangedPhp($previousVideoId, $previousEpisodeId, $newVideoId, $newEpisodeId);
        }
        try {
            // @phpstan-ignore method.notFound
            $res = $ffi->nntmux_tv_outcome_has_link_changed($previousVideoId, $previousEpisodeId, $newVideoId, $newEpisodeId);
        } catch (Throwable) {
            return self::hasLinkChangedPhp($previousVideoId, $previousEpisodeId, $newVideoId, $newEpisodeId);
        }

        return match ($res) {
            1 => true,
            0 => false,
            default => self::hasLinkChangedPhp($previousVideoId, $previousEpisodeId, $newVideoId, $newEpisodeId),
        };
    }

    public static function hasLinkChangedPhp(
        int $previousVideoId,
        int $previousEpisodeId,
        int $newVideoId,
        int $newEpisodeId,
    ): bool {
        return $newVideoId !== $previousVideoId
            || ($newEpisodeId !== $previousEpisodeId
                && max($newEpisodeId, $previousEpisodeId) > 0);
    }

    public static function shouldBumpCacheVersion(bool $linked, string $status): bool
    {
        return self::usesRustFfi()
            ? self::shouldBumpCacheVersionNative($linked, $status)
            : self::shouldBumpCacheVersionPhp($linked, $status);
    }

    public static function shouldBumpCacheVersionNative(bool $linked, string $status): bool
    {
        if (($ffi = self::ffi()) === null) {
            return self::shouldBumpCacheVersionPhp($linked, $status);
        }
        try {
            // @phpstan-ignore method.notFound
            $res = $ffi->nntmux_tv_outcome_should_bump_cache($linked ? 1 : 0, $status, \strlen($status));
        } catch (Throwable) {
            return self::shouldBumpCacheVersionPhp($linked, $status);
        }

        return match ($res) {
            1 => true,
            0 => false,
            default => self::shouldBumpCacheVersionPhp($linked, $status),
        };
    }

    public static function shouldBumpCacheVersionPhp(bool $linked, string $status): bool
    {
        return $linked && \in_array($status, [TvProcessingResult::STATUS_MATCHED, TvProcessingResult::STATUS_SEASON_PACK], true);
    }

    /**
     * @return array{videos_id: int, tv_episodes_id: int, tv_lookup_attempts: int, retry_delay_seconds: int|null, link_changed: bool}
     */
    public static function resolveOutcome(
        int $previousVideoId,
        int $previousStatus,
        int $previousAttempts,
        TvProcessingResult $result,
    ): array {
        return self::usesRustFfi()
            ? self::resolveOutcomeNative($previousVideoId, $previousStatus, $previousAttempts, $result)
            : self::resolveOutcomePhp($previousVideoId, $previousStatus, $previousAttempts, $result);
    }

    /**
     * @return array{videos_id: int, tv_episodes_id: int, tv_lookup_attempts: int, retry_delay_seconds: int|null, link_changed: bool}
     */
    public static function resolveOutcomeNative(
        int $previousVideoId,
        int $previousStatus,
        int $previousAttempts,
        TvProcessingResult $result,
    ): array {
        if (($ffi = self::ffi()) === null) {
            return self::resolveOutcomePhp($previousVideoId, $previousStatus, $previousAttempts, $result);
        }
        try {
            $outVideoId = $ffi->new('int64_t[1]');
            $outEpisodeStatus = $ffi->new('int64_t[1]');
            $outAttempts = $ffi->new('int64_t[1]');
            $outRetryDelay = $ffi->new('int64_t[1]');
            $outLinkChanged = $ffi->new('int32_t[1]');
            $status = $result->status;
            // @phpstan-ignore method.notFound
            $res = $ffi->nntmux_tv_outcome_resolve(
                $previousVideoId,
                $previousStatus,
                $previousAttempts,
                $status,
                \strlen($status),
                (int) $result->videoId,
                (int) $result->episodeId,
                $result->transient ? 1 : 0,
                $result->retryAfter ?? 0,
                $outVideoId,
                $outEpisodeStatus,
                $outAttempts,
                $outRetryDelay,
                $outLinkChanged,
            );
            if ($res === 1) {
                $delay = (int) $outRetryDelay[0];

                return [
                    'videos_id' => (int) $outVideoId[0],
                    'tv_episodes_id' => (int) $outEpisodeStatus[0],
                    'tv_lookup_attempts' => (int) $outAttempts[0],
                    'retry_delay_seconds' => $delay >= 0 ? $delay : null,
                    'link_changed' => (int) $outLinkChanged[0] === 1,
                ];
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return self::resolveOutcomePhp($previousVideoId, $previousStatus, $previousAttempts, $result);
    }

    /**
     * @return array{videos_id: int, tv_episodes_id: int, tv_lookup_attempts: int, retry_delay_seconds: int|null, link_changed: bool}
     */
    public static function resolveOutcomePhp(
        int $previousVideoId,
        int $previousStatus,
        int $previousAttempts,
        TvProcessingResult $result,
    ): array {
        switch ($result->status) {
            case TvProcessingResult::STATUS_MATCHED:
                $videoId = (int) $result->videoId;
                $episodeStatus = (int) $result->episodeId;
                $attempts = 0;
                $retryDelay = null;
                break;

            case TvProcessingResult::STATUS_SEASON_PACK:
                $videoId = (int) $result->videoId;
                $episodeStatus = TvLookupStatus::SeasonPack->value;
                $attempts = 0;
                $retryDelay = null;
                break;

            case TvProcessingResult::STATUS_EPISODE_MISSING:
                $videoId = (int) $result->videoId;
                $episodeStatus = TvLookupStatus::EpisodeMissing->value;
                $attempts = $previousStatus === TvLookupStatus::EpisodeMissing->value ? $previousAttempts + 1 : 1;
                $retryDelay = null;
                if ($result->transient) {
                    $retryDelay = max($result->retryAfter ?? 0, TvProcessingCandidateQuery::KNOWN_SHOW_TRANSIENT_DELAY);
                } elseif ($attempts < TvProcessingCandidateQuery::MAX_EPISODE_ATTEMPTS) {
                    $retryDelay = TvProcessingCandidateQuery::episodeDelayPhp($attempts);
                }
                break;

            case TvProcessingResult::STATUS_TRANSIENT:
                $attempts = $previousStatus === TvLookupStatus::Pending->value ? $previousAttempts + 1 : 1;
                if ($attempts >= TvProcessingCandidateQuery::MAX_TRANSIENT_ATTEMPTS) {
                    $videoId = 0;
                    $episodeStatus = TvLookupStatus::ShowNotFound->value;
                    $attempts = 0;
                    $retryDelay = null;
                } else {
                    $videoId = 0;
                    $episodeStatus = TvLookupStatus::Pending->value;
                    $retryDelay = TvProcessingCandidateQuery::transientDelayPhp($attempts, $result->retryAfter ?? 0);
                }
                break;

            case TvProcessingResult::STATUS_PARSE_FAILED:
                $videoId = 0;
                $episodeStatus = TvLookupStatus::ParseFailed->value;
                $attempts = 0;
                $retryDelay = null;
                break;

            default:
                $videoId = 0;
                $episodeStatus = TvLookupStatus::ShowNotFound->value;
                $attempts = 0;
                $retryDelay = null;
                break;
        }

        return [
            'videos_id' => $videoId,
            'tv_episodes_id' => $episodeStatus,
            'tv_lookup_attempts' => $attempts,
            'retry_delay_seconds' => $retryDelay,
            'link_changed' => self::hasLinkChangedPhp($previousVideoId, $previousStatus, $videoId, $episodeStatus),
        ];
    }

    /**
     * @return array{videos_id: int, tv_episodes_id: int, tv_lookup_attempts: int, tv_lookup_retry_at: CarbonInterface|null}
     */
    public function columnsFor(Release $release, TvProcessingResult $result): array
    {
        return self::usesRustFfi()
            ? $this->columnsForNative($release, $result)
            : $this->columnsForPhp($release, $result);
    }

    /**
     * @return array{videos_id: int, tv_episodes_id: int, tv_lookup_attempts: int, tv_lookup_retry_at: CarbonInterface|null}
     */
    public function columnsForNative(Release $release, TvProcessingResult $result): array
    {
        $resolved = self::resolveOutcomeNative(
            (int) $release->videos_id,
            (int) $release->tv_episodes_id,
            (int) $release->tv_lookup_attempts,
            $result,
        );
        $retryAt = $resolved['retry_delay_seconds'] !== null
            ? now()->addSeconds($resolved['retry_delay_seconds'])
            : null;

        return $this->columns(
            $resolved['videos_id'],
            $resolved['tv_episodes_id'],
            $resolved['tv_lookup_attempts'],
            $retryAt,
        );
    }

    /**
     * @return array{videos_id: int, tv_episodes_id: int, tv_lookup_attempts: int, tv_lookup_retry_at: CarbonInterface|null}
     */
    public function columnsForPhp(Release $release, TvProcessingResult $result): array
    {
        $resolved = self::resolveOutcomePhp(
            (int) $release->videos_id,
            (int) $release->tv_episodes_id,
            (int) $release->tv_lookup_attempts,
            $result,
        );
        $retryAt = $resolved['retry_delay_seconds'] !== null
            ? now()->addSeconds($resolved['retry_delay_seconds'])
            : null;

        return $this->columns(
            $resolved['videos_id'],
            $resolved['tv_episodes_id'],
            $resolved['tv_lookup_attempts'],
            $retryAt,
        );
    }

    /**
     * @return array{videos_id: int, tv_episodes_id: int, tv_lookup_attempts: int, tv_lookup_retry_at: CarbonInterface|null}
     */
    private function columns(int $videoId, int $episodeStatus, int $attempts = 0, ?CarbonInterface $retryAt = null): array
    {
        return [
            'videos_id' => $videoId,
            'tv_episodes_id' => $episodeStatus,
            'tv_lookup_attempts' => $attempts,
            'tv_lookup_retry_at' => $retryAt,
        ];
    }
}
