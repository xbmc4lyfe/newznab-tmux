<?php

declare(strict_types=1);

namespace App\Services\TvProcessing\Pipes;

use App\Services\TvProcessing\ParsedTvRelease;
use App\Services\TvProcessing\Providers\AbstractTvProvider;
use App\Services\TvProcessing\Providers\LocalDbProvider;
use App\Services\TvProcessing\TvProcessingPassable;
use App\Services\TvProcessing\TvProcessingResult;
use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

/**
 * Pipe for local database lookups.
 *
 * Matches the release to an already scraped show and episode before any API is
 * called. A show found here without the episode is handed on so the API pipes can
 * fetch the episode with their own id for the show.
 */
class LocalDbPipe extends AbstractTvProviderPipe
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_tv_local_pipe_should_lookup_episode(uint32_t season_pack, uint32_t has_season, int64_t season, uint32_t has_episode, int64_t episode, uint32_t has_airdate);
        int32_t nntmux_tv_local_pipe_has_valid_site_id(int32_t kind, int64_t int_val, const char *str_val, size_t str_len);
        int32_t nntmux_tv_local_pipe_classify_outcome(int64_t video_id, uint32_t season_pack, int64_t episode_id);
        C;

    /** Null selects the benchmark-qualified production default (PHP; see RustTvIgdbSteamMetadataParityTest). */
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

    protected int $priority = 10;

    public function __construct(private ?LocalDbProvider $localDb = null) {}

    public function getName(): string
    {
        return 'Local DB';
    }

    protected function process(TvProcessingPassable $passable): TvProcessingResult
    {
        /** @var ParsedTvRelease $parsed */
        $parsed = $passable->parsed;
        $localDb = $this->localDb ??= new LocalDbProvider;
        $title = $parsed->cleanName;
        $name = $this->getName();

        if (! $passable->hasShow()) {
            $videoId = $localDb->getByTitle($title, AbstractTvProvider::TYPE_TV);
            if (! self::hasValidSiteId($videoId)) {
                $this->outputNotFound($title);

                return self::buildOutcomeResult(0, false, 0, $title, $name);
            }

            $passable->setShow($videoId, $name);
        }

        $videoId = $passable->videoId;

        if ($parsed->seasonPack) {
            $passable->markSeasonPack($name);
            $this->outputSeasonPack($title);

            return self::buildOutcomeResult($videoId, true, 0, $title, $name);
        }

        $episodeId = self::shouldLookupEpisode($parsed)
            ? (int) $localDb->getBySeasonEp($videoId, $parsed->season ?? -1, $parsed->episode ?? 0, (string) $parsed->airdate)
            : 0;

        if (self::classifyOutcome($videoId, false, $episodeId) === 2) {
            $passable->markMatched($episodeId, $name);
            $this->outputMatch($title, $parsed);

            return self::buildOutcomeResult($videoId, false, $episodeId, $title, $name);
        }

        $this->outputEpisodeMissing($title, $parsed);

        return self::buildOutcomeResult($videoId, false, 0, $title, $name);
    }

    public static function shouldLookupEpisode(ParsedTvRelease $parsed): bool
    {
        return self::usesRustFfi()
            ? self::shouldLookupEpisodeNative($parsed)
            : self::shouldLookupEpisodePhp($parsed);
    }

    public static function shouldLookupEpisodeNative(ParsedTvRelease $parsed): bool
    {
        if (($ffi = self::ffi()) === null) {
            return self::shouldLookupEpisodePhp($parsed);
        }
        try {
            // @phpstan-ignore method.notFound
            $res = $ffi->nntmux_tv_local_pipe_should_lookup_episode(
                $parsed->seasonPack ? 1 : 0,
                $parsed->season !== null ? 1 : 0,
                $parsed->season ?? -1,
                $parsed->episode !== null ? 1 : 0,
                $parsed->episode ?? 0,
                $parsed->airdate !== null ? 1 : 0,
            );
            if ($res === 1) {
                return true;
            }
            if ($res === 0) {
                return false;
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return self::shouldLookupEpisodePhp($parsed);
    }

    public static function shouldLookupEpisodePhp(ParsedTvRelease $parsed): bool
    {
        return ! $parsed->seasonPack && $parsed->hasEpisodeReference();
    }

    public static function hasValidSiteId(int|string|bool|null $siteId): bool
    {
        return self::usesRustFfi()
            ? self::hasValidSiteIdNative($siteId)
            : self::hasValidSiteIdPhp($siteId);
    }

    public static function hasValidSiteIdNative(int|string|bool|null $siteId): bool
    {
        if (($ffi = self::ffi()) === null) {
            return self::hasValidSiteIdPhp($siteId);
        }
        $kind = 0;
        $intVal = 0;
        $strVal = '';
        if (\is_int($siteId)) {
            $kind = 1;
            $intVal = $siteId;
        } elseif (\is_string($siteId)) {
            $kind = 2;
            $strVal = $siteId;
        }
        try {
            // @phpstan-ignore method.notFound
            $res = $ffi->nntmux_tv_local_pipe_has_valid_site_id($kind, $intVal, $strVal, \strlen($strVal));
            if ($res === 1) {
                return true;
            }
            if ($res === 0) {
                return false;
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return self::hasValidSiteIdPhp($siteId);
    }

    public static function hasValidSiteIdPhp(int|string|bool|null $siteId): bool
    {
        if (\is_int($siteId)) {
            return $siteId > 0;
        }
        if (\is_string($siteId)) {
            $trimmed = trim($siteId);
            if ($trimmed !== '' && preg_match('/^[+-]?\d+$/', $trimmed) === 1) {
                return (int) $trimmed > 0;
            }
        }

        return false;
    }

    /**
     * Classify the LocalDbPipe outcome:
     * `0` = not_found, `1` = season_pack, `2` = matched, `3` = episode_missing.
     */
    public static function classifyOutcome(int $videoId, bool $seasonPack, int $episodeId): int
    {
        return self::usesRustFfi()
            ? self::classifyOutcomeNative($videoId, $seasonPack, $episodeId)
            : self::classifyOutcomePhp($videoId, $seasonPack, $episodeId);
    }

    public static function classifyOutcomeNative(int $videoId, bool $seasonPack, int $episodeId): int
    {
        if (($ffi = self::ffi()) === null) {
            return self::classifyOutcomePhp($videoId, $seasonPack, $episodeId);
        }
        try {
            // @phpstan-ignore method.notFound
            $code = $ffi->nntmux_tv_local_pipe_classify_outcome($videoId, $seasonPack ? 1 : 0, $episodeId);
            if (\is_int($code) && $code >= 0 && $code <= 3) {
                return $code;
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return self::classifyOutcomePhp($videoId, $seasonPack, $episodeId);
    }

    public static function classifyOutcomePhp(int $videoId, bool $seasonPack, int $episodeId): int
    {
        if ($videoId <= 0) {
            return 0;
        }
        if ($seasonPack) {
            return 1;
        }
        if ($episodeId > 0) {
            return 2;
        }

        return 3;
    }

    public static function buildOutcomeResult(
        int $videoId,
        bool $seasonPack,
        int $episodeId,
        string $title,
        string $providerName = 'Local DB',
    ): TvProcessingResult {
        return self::usesRustFfi()
            ? self::buildOutcomeResultNative($videoId, $seasonPack, $episodeId, $title, $providerName)
            : self::buildOutcomeResultPhp($videoId, $seasonPack, $episodeId, $title, $providerName);
    }

    public static function buildOutcomeResultNative(
        int $videoId,
        bool $seasonPack,
        int $episodeId,
        string $title,
        string $providerName = 'Local DB',
    ): TvProcessingResult {
        return match (self::classifyOutcomeNative($videoId, $seasonPack, $episodeId)) {
            0 => TvProcessingResult::notFound($providerName, ['title' => $title]),
            1 => TvProcessingResult::seasonPack($videoId, $providerName),
            2 => TvProcessingResult::matched($videoId, $episodeId, $providerName),
            default => TvProcessingResult::episodeMissing($videoId, $providerName),
        };
    }

    public static function buildOutcomeResultPhp(
        int $videoId,
        bool $seasonPack,
        int $episodeId,
        string $title,
        string $providerName = 'Local DB',
    ): TvProcessingResult {
        return match (self::classifyOutcomePhp($videoId, $seasonPack, $episodeId)) {
            0 => TvProcessingResult::notFound($providerName, ['title' => $title]),
            1 => TvProcessingResult::seasonPack($videoId, $providerName),
            2 => TvProcessingResult::matched($videoId, $episodeId, $providerName),
            default => TvProcessingResult::episodeMissing($videoId, $providerName),
        };
    }
}
