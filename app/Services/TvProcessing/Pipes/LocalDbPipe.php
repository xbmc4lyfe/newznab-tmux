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
use FFI\CData;
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

    // ========================================================================
    // WAVE 62.3 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 7.25x, p99 5.70x; uncommon mean 4.76x, p99 3.79x); see RustTvParsedReleaseLocalDbParseInfoTmdbTraktPipeW62ParityTest. */
    public const bool W62_RUST_FFI_DEFAULT = true;

    public const string W62_LOCAL_DB_PIPE_FFI_CDEF = <<<'C'
        ssize_t nntmux_w623_local_db_pipe_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of the Wave 62.3 batch. */
    public const int W62_LOCAL_DB_PIPE_TRAILER = 12;

    public static function usesW62RustFfi(): bool
    {
        $override = self::w62RustFfiState();

        return \is_bool($override) ? $override : self::W62_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the LocalDbPipe Wave 62 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW62RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w62RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w62RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 62.3 local DB lookup plans. Each item is `title|year|country|candidateTitle|candidateYear|candidateCountry|
     * videoId|episodeId|seasonPack`. Titles become lowercase `[a-z0-9]+` tokens less a leading `the` while another
     * token follows. Per item `titleScore | yearCode << 7 | countryCode << 9 | videoState << 11 | episodeState << 13 |
     * seasonPack << 15 | outcome << 16 | decision << 18 | score << 20 | min(titleTokens, 255) << 29 |
     * min(candidateTokens, 255) << 37 | exact << 45`: the title score is the distinct-token Jaccard percent (0 when
     * both are empty); the year code is 0 unless both trimmed fields are 19xx/20xx years, then 1 equal, 2 one apart
     * or 3 conflicting; the country code is 0 unless both are two letters, then 1 equal (any case) or 2 differing;
     * ids are 0 empty, 1 a positive trimmed `[+-]?\d{1,18}` (not negative) or 2 other; seasonPack is a 1/true/yes/on
     * flag; the outcome follows classifyOutcome() (0 no valid video, 1 season pack, 2 valid episode, 3 episode
     * missing); the score is the title score + 20/10 for year code 1/2 + 10 for an equal country - 40 for a year
     * conflict - 20 for a country conflict, at least 0; the decision is 2 from 110, 1 from 80, else 0; exact marks
     * equal non-empty token lists. Then the four outcome and three decision counts, the 1-based index of the first
     * best score (0 when empty), that score, the distinct valid video ids, the score sum and the exact matches.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w62LocalPlanBatch(array $items): array
    {
        return self::usesW62RustFfi() ? self::w62LocalPlanBatchNative($items) : self::w62LocalPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w62LocalPlanBatchNative(array $items): array
    {
        return self::w62Invoke($items, \count($items) + self::W62_LOCAL_DB_PIPE_TRAILER) ?? self::w62LocalPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w62LocalPlanBatchPhp(array $items): array
    {
        $out = [];
        $outcomes = [0, 0, 0, 0];
        $decisions = [0, 0, 0];
        $best = $bestScore = $scoreSum = $exacts = $index = 0;
        $videos = [];
        foreach ($items as $item) {
            $index++;
            [$title, $year, $country, $candidate, $candidateYear, $candidateCountry, $video, $episode, $pack] = explode('|', (string) $item, 9)
                + ['', '', '', '', '', '', '', '', ''];
            $a = self::w62LocalTokens($title);
            $b = self::w62LocalTokens($candidate);
            $exact = $a !== [] && $a === $b ? 1 : 0;
            $setA = array_flip($a);
            $setB = array_flip($b);
            $shared = \count(array_intersect_key($setA, $setB));
            $union = \count($setA) + \count($setB) - $shared;
            $titleScore = $union === 0 ? 0 : intdiv(100 * $shared, $union);
            $p = self::w62Year(trim($year));
            $q = self::w62Year(trim($candidateYear));
            $yearCode = $p === null || $q === null ? 0 : ($p === $q ? 1 : (abs($p - $q) === 1 ? 2 : 3));
            $ca = trim($country);
            $cb = trim($candidateCountry);
            $countryCode = self::w62Country2($ca) && self::w62Country2($cb) ? (strtolower($ca) === strtolower($cb) ? 1 : 2) : 0;
            [$videoState, $videoId] = self::w62SiteId($video);
            [$episodeState] = self::w62SiteId($episode);
            $seasonPack = \in_array(strtolower(trim($pack)), ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
            $outcome = match (true) {
                $videoState !== 1 => 0,
                $seasonPack === 1 => 1,
                $episodeState === 1 => 2,
                default => 3,
            };
            $score = max(0, $titleScore + [0, 20, 10, 0][$yearCode] + ($countryCode === 1 ? 10 : 0) - ($yearCode === 3 ? 40 : 0) - ($countryCode === 2 ? 20 : 0));
            $decision = $score >= 110 ? 2 : ($score >= 80 ? 1 : 0);
            $outcomes[$outcome]++;
            $decisions[$decision]++;
            if ($best === 0 || $score > $bestScore) {
                $best = $index;
                $bestScore = $score;
            }
            if ($videoState === 1) {
                $videos[$videoId] = true;
            }
            $scoreSum += $score;
            $exacts += $exact;
            $out[] = $titleScore | $yearCode << 7 | $countryCode << 9 | $videoState << 11 | $episodeState << 13 | $seasonPack << 15 | $outcome << 16
                | $decision << 18 | min($score, 511) << 20 | min(\count($a), 255) << 29 | min(\count($b), 255) << 37 | $exact << 45;
        }
        array_push($out, ...$outcomes, ...$decisions);
        array_push($out, $best, $bestScore, \count($videos), $scoreSum, $exacts);

        return $out;
    }

    /** Two ASCII letters. */
    private static function w62Country2(string $text): bool
    {
        return \strlen($text) === 2 && strspn($text, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz') === 2;
    }

    /**
     * Title tokens without a leading `the` while another token follows.
     *
     * @return list<string>
     */
    private static function w62LocalTokens(string $title): array
    {
        $tokens = self::w62Tokens($title);
        if (\count($tokens) > 1 && $tokens[0] === 'the') {
            array_shift($tokens);
        }

        return $tokens;
    }

    /**
     * (0 empty, 1 positive, 2 other; the id) for a trimmed `[+-]?\d{1,18}` site id.
     *
     * @return array{int, int}
     */
    private static function w62SiteId(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [0, 0];
        }
        $negative = $text[0] === '-';
        $value = self::w62Digits($negative || $text[0] === '+' ? substr($text, 1) : $text, 1, 18);

        return $value !== null && ! $negative && $value > 0 ? [1, $value] : [2, 0];
    }

    /** $min to $max ASCII digits as their value, else null. */
    private static function w62Digits(string $text, int $min, int $max): ?int
    {
        $length = \strlen($text);

        return $length >= $min && $length <= $max && strspn($text, '0123456789') === $length ? (int) $text : null;
    }

    /** A 19xx/20xx four-digit year, else null. */
    private static function w62Year(string $text): ?int
    {
        return \strlen($text) === 4 && (str_starts_with($text, '19') || str_starts_with($text, '20')) ? self::w62Digits($text, 4, 4) : null;
    }

    /**
     * Lowercase `[a-z0-9]+` tokens.
     *
     * @return list<string>
     */
    private static function w62Tokens(string $text): array
    {
        return preg_split('/[^a-z0-9]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Wave 62 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'ffi' resolves the library (null when unavailable) and 'out' and
     * 'lens' return `int64_t` output and length-column buffers of at least $val words.
     */
    private static function w62RustFfiState(?string $op = null, mixed $val = null): mixed
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
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('tv_w623', self::W62_LOCAL_DB_PIPE_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w62RustFfiState('ffi');
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
                $handle = self::w62RustFfiState('ffi');
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
     * Calls the Wave 62.3 LocalDbPipe export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w62Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w62RustFfiState('ffi');
            $out = self::w62RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w62RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W62_LOCAL_DB_PIPE_FFI_CDEF.)
            $written = $ffi->nntmux_w623_local_db_pipe_batch($blob, \strlen($blob), $lens, $count, $out, $words);
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
