<?php

declare(strict_types=1);

namespace App\Services\TvProcessing;

use App\Enums\TvLookupStatus;
use App\Models\Release;
use App\Services\Releases\ReleaseBrowseService;
use App\Support\RustPipelineLibrary;
use Carbon\CarbonInterface;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
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

    // ---------------------------------------------------------------------
    // Wave 49.4 batch helper
    // ---------------------------------------------------------------------

    /** Enabled: minimum of three paired runs cleared every 1.5x gate (common mean 2.21x, p99 2.05x; uncommon mean 2.66x, p99 2.22x); see RustTvProvidersLocalDbTraktOutcomePosterAdderW49ParityTest. */
    private const bool W49_RUST_FFI_DEFAULT = true;

    public const string W49_FFI_CDEF = <<<'C'
        ssize_t nntmux_w49_tv_outcome_resolve_batch(const char *text, size_t text_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    private static ?bool $useW49RustFfi = null;

    private static FFI|false|null $w49Ffi = null;

    private static ?CData $w49Out = null;

    private static int $w49OutCap = 0;

    public static function usesW49RustFfi(): bool
    {
        return self::$useW49RustFfi ?? self::W49_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W49 batch dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW49RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$useW49RustFfi;
        self::$useW49RustFfi = $enabled;
        try {
            return $callback();
        } finally {
            self::$useW49RustFfi = $previous;
        }
    }

    /**
     * resolveOutcomePhp() plus shouldBumpCacheVersionPhp() for a batch. Each outcome supplies
     * seven ints in $scalars ([previous video id, previous status, previous attempts, video id,
     * episode id, transient 0/1, retry after]) and its status string, and yields four ints:
     * [tv_episodes_id, tv_lookup_attempts, retry delay seconds or -1, flags]. Flags: 1 link
     * changed, 2 bump the browse cache, 4 videos_id is the input video id (otherwise 0).
     * Attempt counters saturate at PHP_INT_MAX instead of overflowing to float.
     *
     * @param  list<int>  $scalars
     * @param  list<string>  $statuses
     * @return list<int>
     */
    public static function resolveOutcomeBatch(array $scalars, array $statuses): array
    {
        return self::usesW49RustFfi()
            ? self::resolveOutcomeBatchNative($scalars, $statuses)
            : self::resolveOutcomeBatchPhp($scalars, $statuses);
    }

    /**
     * @param  list<int>  $scalars
     * @param  list<string>  $statuses
     * @return list<int>
     */
    public static function resolveOutcomeBatchNative(array $scalars, array $statuses): array
    {
        self::w49AssertOutcomeShape($scalars, $statuses);
        if ($statuses === []) {
            return [];
        }

        return self::w49Call(implode("\0", $statuses), pack('P*', ...$scalars), \count($statuses))
            ?? self::resolveOutcomeBatchPhp($scalars, $statuses);
    }

    /**
     * @param  list<int>  $scalars
     * @param  list<string>  $statuses
     * @return list<int>
     */
    public static function resolveOutcomeBatchPhp(array $scalars, array $statuses): array
    {
        self::w49AssertOutcomeShape($scalars, $statuses);
        $out = [];
        $at = 0;
        foreach ($statuses as $status) {
            $previousVideoId = $scalars[$at];
            $previousStatus = $scalars[$at + 1];
            $previousAttempts = $scalars[$at + 2];
            $retryAfter = $scalars[$at + 6];
            $next = $previousAttempts < PHP_INT_MAX ? $previousAttempts + 1 : PHP_INT_MAX;
            $retryDelay = -1;
            $attempts = 0;
            $keepsVideo = 4;
            $videoId = $scalars[$at + 3];
            switch ($status) {
                case TvProcessingResult::STATUS_MATCHED:
                    $episodeStatus = $scalars[$at + 4];
                    break;

                case TvProcessingResult::STATUS_SEASON_PACK:
                    $episodeStatus = TvLookupStatus::SeasonPack->value;
                    break;

                case TvProcessingResult::STATUS_EPISODE_MISSING:
                    $episodeStatus = TvLookupStatus::EpisodeMissing->value;
                    $attempts = $previousStatus === TvLookupStatus::EpisodeMissing->value ? $next : 1;
                    if ($scalars[$at + 5] !== 0) {
                        $retryDelay = max($retryAfter, TvProcessingCandidateQuery::KNOWN_SHOW_TRANSIENT_DELAY);
                    } elseif ($attempts < TvProcessingCandidateQuery::MAX_EPISODE_ATTEMPTS) {
                        $retryDelay = TvProcessingCandidateQuery::episodeDelayPhp($attempts);
                    }
                    break;

                case TvProcessingResult::STATUS_TRANSIENT:
                    $videoId = 0;
                    $keepsVideo = 0;
                    $attempts = $previousStatus === TvLookupStatus::Pending->value ? $next : 1;
                    if ($attempts >= TvProcessingCandidateQuery::MAX_TRANSIENT_ATTEMPTS) {
                        $episodeStatus = TvLookupStatus::ShowNotFound->value;
                        $attempts = 0;
                    } else {
                        $episodeStatus = TvLookupStatus::Pending->value;
                        $retryDelay = TvProcessingCandidateQuery::transientDelayPhp($attempts, $retryAfter);
                    }
                    break;

                case TvProcessingResult::STATUS_PARSE_FAILED:
                    $videoId = 0;
                    $keepsVideo = 0;
                    $episodeStatus = TvLookupStatus::ParseFailed->value;
                    break;

                default:
                    $videoId = 0;
                    $keepsVideo = 0;
                    $episodeStatus = TvLookupStatus::ShowNotFound->value;
                    break;
            }
            $linked = self::hasLinkChangedPhp($previousVideoId, $previousStatus, $videoId, $episodeStatus);
            $flags = ($linked ? 1 : 0) | (self::shouldBumpCacheVersionPhp($linked, $status) ? 2 : 0) | $keepsVideo;
            array_push($out, $episodeStatus, $attempts, $retryDelay, $flags);
            $at += 7;
        }

        return $out;
    }

    /**
     * @param  list<int>  $scalars
     * @param  list<string>  $statuses
     */
    private static function w49AssertOutcomeShape(array $scalars, array $statuses): void
    {
        self::w49AssertColumns($statuses);
        if (\count($scalars) !== 7 * \count($statuses) || ! array_is_list($scalars)) {
            throw new InvalidArgumentException('W49 outcome batches need seven scalars per status.');
        }
    }

    /**
     * One FFI crossing for the batch: NUL-joined text columns, little-endian int64 scalars,
     * 4 int64 words back per record. Null when the library is missing or declines.
     *
     * @return list<int>|null
     */
    private static function w49Call(string $text, string $ints, int $count): ?array
    {
        try {
            self::$w49Ffi ??= RustPipelineLibrary::resolveDomain('metadata_w49', self::W49_FFI_CDEF) ?? false;
            $ffi = self::$w49Ffi;
            if ($ffi === false) {
                return null;
            }
            $need = $count * 4;
            $out = self::$w49Out;
            if ($out === null || self::$w49OutCap < $need) {
                self::$w49OutCap = max(1024, $need);
                $out = self::$w49Out = $ffi->new('int64_t['.self::$w49OutCap.']');
            }
            // @phpstan-ignore method.notFound (Declared by W49_FFI_CDEF.)
            $written = $ffi->nntmux_w49_tv_outcome_resolve_batch($text, \strlen($text), $ints, \strlen($ints), $count, $out, self::$w49OutCap);
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
     * @param  array<array-key, mixed>  ...$columns
     */
    private static function w49AssertColumns(array ...$columns): void
    {
        $count = \count($columns[0]);
        foreach ($columns as $column) {
            if (\count($column) !== $count || ! array_is_list($column)) {
                throw new InvalidArgumentException('W49 batch columns must be lists of equal length.');
            }
        }
    }

    // ========================================================================
    // WAVE 62.4 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three paired runs reached 2.23x common mean, 2.01x common p99, 1.99x uncommon mean and 1.64x uncommon p99; see RustTvMazeTvdbPipeOutcomeWriterPosterResolverRunStateW62ParityTest. */
    public const bool W62_RUST_FFI_DEFAULT = true;

    private const string W62_OUTCOME_WRITER_FFI_CDEF = 'ssize_t nntmux_w624_tv_lookup_outcome_plan_row_batch(const char *blob, size_t blob_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);';

    /** int64 words per lookupOutcomePlanRowBatch() record. */
    public const int W62_ROW_WORDS = 5;

    /** int64 summary words after the lookupOutcomePlanRowBatch() records (none for an empty batch). */
    public const int W62_TRAILER_WORDS = 8;

    private const array W62_STATUSES = [
        TvProcessingResult::STATUS_MATCHED => 0,
        TvProcessingResult::STATUS_SEASON_PACK => 1,
        TvProcessingResult::STATUS_EPISODE_MISSING => 2,
        TvProcessingResult::STATUS_NOT_FOUND => 3,
        TvProcessingResult::STATUS_PARSE_FAILED => 4,
        TvProcessingResult::STATUS_TRANSIENT => 5,
        TvProcessingResult::STATUS_SKIPPED => 6,
        TvProcessingResult::STATUS_PENDING => 7,
    ];

    private const array W62_PROVIDERS = ['tvdb' => 1, 'tvmaze' => 2, 'tmdb' => 3, 'trakt' => 4, 'local db' => 5];

    private const int W62_MAX_ATTEMPTS = 1_000_000;

    private const int W62_MAX_RETRY_AFTER = 604_800;

    /**
     * TV lookup outcome plan rows for [status, provider, previous videos id, previous
     * tv_episodes_id status, previous attempts, videos id, episode id, transient, retry after] items.
     *
     * Statuses are trimmed and lowercased (code 0-7 as TvProcessingResult, -1 unknown, stored + 1);
     * providers are trimmed and lowercased (0 empty, 1 tvdb, 2 tvmaze, 3 tmdb, 4 trakt, 5 local db,
     * 6 other). Previous attempts clamp to 0..1000000 and retry after to 0..604800; the outcome
     * follows resolveOutcomePhp() with negative videos ids linked as 0. Transition: 1 retry
     * scheduled, 2 terminal, 3 matched episode, 4 episode missing without a retry.
     *
     * Rows [status code + 1 | provider << 4 | transition << 8 | flags << 12 (1 link changed, 2 cache
     * bump, 4 invalid input, 8 transient, 16 attempts exhausted), videos id, tv_episodes_id,
     * attempts, retry delay (-1)], then the trailer [retries, terminal, matched, invalid, link
     * changes, cache bumps, max delay (-1), FNV-1a digest].
     *
     * @param  list<array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int, 6: int, 7: int, 8: int}>  $items
     * @return list<int>
     */
    public static function lookupOutcomePlanRowBatch(array $items): array
    {
        return self::usesW62RustFfi() ? self::lookupOutcomePlanRowBatchNative($items) : self::lookupOutcomePlanRowBatchPhp($items);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int, 6: int, 7: int, 8: int}>  $items
     * @return list<int>
     */
    public static function lookupOutcomePlanRowBatchPhp(array $items): array
    {
        self::w62Items($items);
        if ($items === []) {
            return [];
        }
        $out = [];
        $summary = [0, 0, 0, 0, 0, 0, -1, 0];
        $digest = 2_166_136_261;
        $pending = TvLookupStatus::Pending->value;
        $missing = TvLookupStatus::EpisodeMissing->value;
        $notFound = TvLookupStatus::ShowNotFound->value;
        foreach ($items as [$status, $provider, $previousVideoId, $previousStatus, $previousAttempts, $videoId, $episodeId, $transient, $retryAfter]) {
            $code = self::W62_STATUSES[strtolower(trim($status))] ?? -1;
            $name = strtolower(trim($provider));
            if ($name === '') {
                $providerClass = $providerHash = 0;
            } else {
                $providerClass = self::W62_PROVIDERS[$name] ?? 6;
                $providerHash = self::w62Fnv($name);
            }
            $previousAttempts = max(0, min(self::W62_MAX_ATTEMPTS, $previousAttempts));
            $retryAfter = max(0, min(self::W62_MAX_RETRY_AFTER, $retryAfter));
            $transient = $transient !== 0;
            $linked = max(0, $videoId);
            $flags = 0;
            $attempts = 0;
            $delay = -1;
            if ($code === 0) {
                $newVideoId = $linked;
                $newStatus = $episodeId;
            } elseif ($code === 1) {
                $newVideoId = $linked;
                $newStatus = TvLookupStatus::SeasonPack->value;
            } elseif ($code === 2) {
                $newVideoId = $linked;
                $newStatus = $missing;
                $attempts = $previousStatus === $missing ? $previousAttempts + 1 : 1;
                if ($transient) {
                    $delay = max($retryAfter, TvProcessingCandidateQuery::KNOWN_SHOW_TRANSIENT_DELAY);
                } elseif ($attempts < TvProcessingCandidateQuery::MAX_EPISODE_ATTEMPTS) {
                    $delay = TvProcessingCandidateQuery::episodeDelayPhp($attempts);
                } else {
                    $flags = 16;
                }
            } elseif ($code === 5) {
                $newVideoId = 0;
                $attempts = $previousStatus === $pending ? $previousAttempts + 1 : 1;
                if ($attempts >= TvProcessingCandidateQuery::MAX_TRANSIENT_ATTEMPTS) {
                    $newStatus = $notFound;
                    $attempts = 0;
                    $flags = 16;
                } else {
                    $newStatus = $pending;
                    $delay = TvProcessingCandidateQuery::transientDelayPhp($attempts, $retryAfter);
                }
            } elseif ($code === 4) {
                $newVideoId = 0;
                $newStatus = TvLookupStatus::ParseFailed->value;
            } else {
                $newVideoId = 0;
                $newStatus = $notFound;
            }
            $link = self::hasLinkChangedPhp($previousVideoId, $previousStatus, $newVideoId, $newStatus);
            $bump = $link && ($code === 0 || $code === 1);
            $invalid = $videoId < 0 || $code < 0 || ($code === 0 && $episodeId <= 0) || ($code <= 2 && $videoId === 0);
            $flags |= ($link ? 1 : 0) | ($bump ? 2 : 0) | ($invalid ? 4 : 0) | ($transient ? 8 : 0);
            $transition = $delay >= 0 ? 1 : ($newStatus > 0 ? 3 : ($newStatus === $missing ? 4 : 2));
            $word = ($code + 1) | $providerClass << 4 | $transition << 8 | $flags << 12;
            array_push($out, $word, $newVideoId, $newStatus, $attempts, $delay);
            $summary[$transition === 1 ? 0 : ($transition === 3 ? 2 : 1)]++;
            $summary[3] += $invalid ? 1 : 0;
            $summary[4] += $link ? 1 : 0;
            $summary[5] += $bump ? 1 : 0;
            $summary[6] = max($summary[6], $delay);
            $value = $word ^ $newStatus << 16 ^ $delay ^ $providerHash << 40;
            $digest = (($digest ^ (($value ^ ($value >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
        }
        $summary[7] = $digest;

        return [...$out, ...$summary];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int, 6: int, 7: int, 8: int}>  $items
     * @return list<int>
     */
    public static function lookupOutcomePlanRowBatchNative(array $items): array
    {
        self::w62Items($items);
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        [$blob, $twin] = self::w62Join([array_column($items, 0), array_column($items, 1)], $count);
        $ints = pack('P*', ...array_column($items, 2), ...array_column($items, 3), ...array_column($items, 4), ...array_column($items, 5), ...array_column($items, 6), ...array_column($items, 7), ...array_column($items, 8));

        return self::w62Call($blob, $twin, $ints, $count, $count * self::W62_ROW_WORDS + self::W62_TRAILER_WORDS)
            ?? self::lookupOutcomePlanRowBatchPhp($items);
    }

    /** @param array<array-key, mixed> $items */
    private static function w62Items(array $items): void
    {
        if (! array_is_list($items)) {
            throw new InvalidArgumentException('W62 TV lookup outcome plan batch items must be a list.');
        }
        foreach ($items as $item) {
            if (! \is_array($item) || \count($item) !== 9 || ! array_is_list($item) || ! \is_string($item[0]) || ! \is_string($item[1]) || ! \is_int($item[2]) || ! \is_int($item[3]) || ! \is_int($item[4]) || ! \is_int($item[5]) || ! \is_int($item[6]) || ! \is_int($item[7]) || ! \is_int($item[8])) {
                throw new InvalidArgumentException('W62 TV lookup outcome plan batch items must be [string status, string provider, int previous videos id, int previous status, int previous attempts, int videos id, int episode id, int transient, int retry after] lists.');
            }
        }
    }

    public static function usesW62RustFfi(): bool
    {
        $enabled = self::w62RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W62_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W62 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW62RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w62RustFfiState('enabled');
        self::w62RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w62RustFfiState('enabled', $previous);
        }
    }

    /**
     * String columns NUL-joined field-major and, when a value holds a NUL, the same columns
     * joined by 0x01 so Rust finds the real separators.
     *
     * @param  list<list<string>>  $columns
     * @return array{0: string, 1: string}
     */
    private static function w62Join(array $columns, int $count): array
    {
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = implode("\0", $column);
        }
        $joined = implode("\0", $parts);
        if (substr_count($joined, "\0") === \count($columns) * $count - 1) {
            return [$joined, ''];
        }
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = implode("\x01", $column);
        }

        return [$joined, implode("\x01", $parts)];
    }

    /**
     * One FFI crossing for the batch: $need int64 words back. Null when the library is missing
     * or declines a malformed frame, so the caller falls back to PHP; a declined call with a
     * resolved library is counted in the 'misses' state.
     *
     * @return list<int>|null
     */
    private static function w62Call(string $blob, string $twin, string $ints, int $count, int $need): ?array
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        $state = self::w62RustFfiState();
        $ffi = $state['ffi'];
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata_w62', self::W62_OUTCOME_WRITER_FFI_CDEF) ?? false;
            self::w62RustFfiState('ffi', $ffi);
        }
        if (! $ffi instanceof FFI) {
            return null;
        }
        try {
            $out = $state['out'];
            $cap = $state['cap'];
            if (! $out instanceof CData || $cap < $need) {
                $cap = max(4096, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w62RustFfiState('out', $out);
                self::w62RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W62_OUTCOME_WRITER_FFI_CDEF.)
            $written = $ffi->nntmux_w624_tv_lookup_outcome_plan_row_batch($blob, \strlen($blob), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $cap);
            $values = $written === $need ? unpack('P*', FFI::string($out, $need * 8)) : false;
            if ($values !== false) {
                return array_values($values);
            }
        } catch (Throwable) {
        }
        self::w62RustFfiState('misses', $state['misses'] + 1);

        return null;
    }

    /**
     * W62 routing override, cached binding, reusable output buffer and declined-call count, kept
     * in a function static: no arguments returns the state, one argument reads a key ('reset'
     * clears everything) and two arguments write a key.
     */
    private static function w62RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;

            return null;
        }

        return $state[$op] ?? null;
    }

    /** FNV-1a 32 of $bytes as an unsigned int. */
    private static function w62Fnv(string $bytes): int
    {
        /** @var array{1: int} $hash */
        $hash = unpack('N', hash('fnv1a32', $bytes, true));

        return $hash[1];
    }
}
