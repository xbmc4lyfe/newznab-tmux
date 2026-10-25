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

    /**
     * Wave 48.3 batch local video/episode title, season/episode/airdate and external-id candidate matching.
     * Enabled: seven paired runs cleared 1.5x on every gate (min common mean 2.60x, p99 2.17x; uncommon mean 1.89x, p99 1.72x; see the W48 parity test).
     */
    public const bool W48_LOCAL_DB_RUST_FFI_DEFAULT = true;

    public const string W48_FFI_CDEF = <<<'C'
        ssize_t nntmux_w48_tv_local_db_candidate_batch(const char *blob, size_t blob_len, const char *ints, size_t ints_len, size_t count, char *out, size_t out_cap);
        C;

    /** Totals at or above these thresholds are a match (2) or a probable match (1). */
    public const int W48_MATCH_TOTAL = 150;

    public const int W48_PROBABLE_TOTAL = 90;

    private static ?bool $w48RustFfiOverride = null;

    private static FFI|false|null $w48RustFfiHandle = null;

    /** Null restores W48_LOCAL_DB_RUST_FFI_DEFAULT. */
    public static function useW48LocalDbRustFfi(?bool $enable = true): void
    {
        self::$w48RustFfiOverride = $enable;
        if ($enable === null) {
            self::$w48RustFfiHandle = null;
        }
    }

    public static function usesW48LocalDbRustFfi(): bool
    {
        return self::$w48RustFfiOverride ?? self::W48_LOCAL_DB_RUST_FFI_DEFAULT;
    }

    /**
     * Five values per release/candidate pair: the title score (100 for equal non-empty
     * normalized titles, else the token Jaccard percent), the episode code (2 when season and
     * episode are both >= 0 and equal the candidate's, else 1 for an equal non-empty airdate,
     * else 0), the total (title score + 50/40 for episode code 2/1 + 30 per equal positive
     * external id - 60 per differing positive pair over tvdb and tmdb), the decision (0 on any
     * id conflict, else 2 from W48_MATCH_TOTAL, 1 from W48_PROBABLE_TOTAL, else 0) and the
     * FNV-1a 32 digest of the normalized candidate title. Then the three decision counts, the
     * index of the best total (first on ties; -1 for an empty batch) and that total (0 when
     * empty). Unknown seasons and episodes are -1; missing keys read as '' or 0.
     *
     * @param  list<array<string, mixed>>  $items  title, candidate_title, airdate, candidate_airdate, season, episode, candidate_season, candidate_episode, tvdb, candidate_tvdb, tmdb, candidate_tmdb
     * @return list<int>
     */
    public static function w48CandidateBatch(array $items): array
    {
        return self::usesW48LocalDbRustFfi()
            ? self::w48CandidateBatchNative($items)
            : self::w48CandidateBatchPhp($items);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<int>
     */
    public static function w48CandidateBatchNative(array $items): array
    {
        $count = \count($items);
        $c = self::w48Columns($items, [
            'title' => '', 'candidate_title' => '', 'airdate' => '', 'candidate_airdate' => '',
            'season' => 0, 'episode' => 0, 'candidate_season' => 0, 'candidate_episode' => 0,
            'tvdb' => 0, 'candidate_tvdb' => 0, 'tmdb' => 0, 'candidate_tmdb' => 0,
        ]);

        return self::w48Invoke(
            self::w48RustFfi(),
            'nntmux_w48_tv_local_db_candidate_batch',
            [$c['title'], $c['candidate_title'], $c['airdate'], $c['candidate_airdate']],
            [$c['season'], $c['episode'], $c['candidate_season'], $c['candidate_episode'], $c['tvdb'], $c['candidate_tvdb'], $c['tmdb'], $c['candidate_tmdb']],
            $count,
            $count * 5 + 5,
        ) ?? self::w48CandidateBatchPhp($items);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<int>
     */
    public static function w48CandidateBatchPhp(array $items): array
    {
        $out = [];
        $decisions = [0, 0, 0];
        $best = -1;
        $bestTotal = 0;
        $index = 0;
        foreach ($items as $item) {
            $title = self::w48Normalize((string) ($item['title'] ?? ''));
            $candidate = self::w48Normalize((string) ($item['candidate_title'] ?? ''));
            if ($title !== '' && $title === $candidate) {
                $titleScore = 100;
            } else {
                $a = $title === '' ? [] : array_flip(explode(' ', $title));
                $b = $candidate === '' ? [] : array_flip(explode(' ', $candidate));
                $shared = \count(array_intersect_key($a, $b));
                $union = \count($a) + \count($b) - $shared;
                $titleScore = $union === 0 ? 0 : intdiv(100 * $shared, $union);
            }
            $season = (int) ($item['season'] ?? 0);
            $episode = (int) ($item['episode'] ?? 0);
            $airdate = (string) ($item['airdate'] ?? '');
            $episodeCode = match (true) {
                $season >= 0 && $episode >= 0 && $season === (int) ($item['candidate_season'] ?? 0)
                    && $episode === (int) ($item['candidate_episode'] ?? 0) => 2,
                $airdate !== '' && $airdate === (string) ($item['candidate_airdate'] ?? '') => 1,
                default => 0,
            };
            $matches = 0;
            $conflicts = 0;
            foreach ([['tvdb', 'candidate_tvdb'], ['tmdb', 'candidate_tmdb']] as [$key, $candidateKey]) {
                $id = (int) ($item[$key] ?? 0);
                $candidateId = (int) ($item[$candidateKey] ?? 0);
                if ($id > 0 && $candidateId > 0) {
                    $id === $candidateId ? $matches++ : $conflicts++;
                }
            }
            $total = $titleScore + [0, 40, 50][$episodeCode] + 30 * $matches - 60 * $conflicts;
            $decision = match (true) {
                $conflicts > 0 => 0,
                $total >= self::W48_MATCH_TOTAL => 2,
                $total >= self::W48_PROBABLE_TOTAL => 1,
                default => 0,
            };
            $decisions[$decision]++;
            if ($best < 0 || $total > $bestTotal) {
                $best = $index;
                $bestTotal = $total;
            }
            array_push($out, $titleScore, $episodeCode, $total, $decision, self::w48Fnv($candidate));
            $index++;
        }
        array_push($out, ...$decisions);
        array_push($out, $best, $bestTotal);

        return $out;
    }

    private static function w48RustFfi(): ?FFI
    {
        if (self::$w48RustFfiHandle === null) {
            self::$w48RustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w48', self::W48_FFI_CDEF) ?? false;
        }

        return self::$w48RustFfiHandle === false ? null : self::$w48RustFfiHandle;
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
