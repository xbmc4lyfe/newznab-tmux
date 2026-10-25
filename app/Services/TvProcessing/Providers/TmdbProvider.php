<?php

declare(strict_types=1);

namespace App\Services\TvProcessing\Providers;

use App\Services\TmdbClient;
use App\Services\TraktService;
use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

class TmdbProvider extends AbstractTvProvider
{
    private const string MATCH_FFI_CDEF = <<<'C'
        ssize_t nntmux_tv_provider_best_candidate_nul(const char *ours, size_t ours_len, int64_t our_year, uint32_t flags, const char *our_country, size_t our_country_len, const char *titles, size_t titles_len, const char *starteds, size_t starteds_len, const char *countries, size_t countries_len, size_t count);
        C;

    private static ?FFI $matchFfi = null;

    private static bool $matchFfiResolved = false;

    /**
     * Candidate selection through Rust; null selects the benchmark-qualified production default
     * (PHP: the batch call misses the 1.5x gate, see tests/Unit/RustTvProvidersTraktMatchingParityTest.php).
     */
    public ?bool $useRustMatchFfi = null;

    /**
     * TMDB client that throws TvProviderException on transient failures.
     */
    protected TmdbClient $tmdbClient;

    public function __construct(?TmdbClient $client = null)
    {
        parent::__construct();
        $this->tmdbClient = ($client ?? app(TmdbClient::class))->throwingOnTransientFailure();
    }

    /**
     * Calls the API to perform initial show name match to TMDB title
     * Returns a formatted array of show data or false if no match.
     *
     * @return array<string, mixed>|false
     */
    public function getShowInfo(string $name, ?int $year = null, string $country = ''): array|false
    {
        if (! $this->tmdbClient->isConfigured()) {
            return false;
        }

        $name = (string) preg_replace('# \((19|20)\d{2}\)$#', '', $name);

        $response = $year !== null ? $this->tmdbClient->searchTv($name, 1, $year) : null;
        if (empty($response['results'])) {
            $response = $this->tmdbClient->searchTv($name);
        }

        if (empty($response['results']) || ! \is_array($response['results'])) {
            return false;
        }

        return $this->matchShowInfo($response['results'], $name, $year, $country);
    }

    /**
     * @param  array<int|string, mixed>  $shows
     * @return array<string, mixed>|false
     */
    private function matchShowInfo(array $shows, string $cleanName, ?int $year, string $country): array|false
    {
        $highest = ($this->useRustMatchFfi ?? false)
            ? $this->selectBestShowNative($shows, $cleanName, $year, $country)
            : $this->selectBestShowPhp($shows, $cleanName, $year, $country);

        if ($highest === null) {
            return false;
        }

        $showId = TmdbClient::getInt($highest, 'id');
        if ($showId === 0) {
            return false;
        }

        // One request for the details the search result lacks (network, external ids, aliases).
        $details = $this->tmdbClient->getTvShow($showId, ['external_ids', 'alternative_titles']) ?? [];

        $alternativeTitles = [];
        foreach (TmdbClient::getArray(TmdbClient::getArray($details, 'alternative_titles'), 'results') as $aka) {
            if (\is_array($aka) && isset($aka['title'])) {
                $alternativeTitles[] = $aka['title'];
            }
        }
        $highest['alternative_titles'] = $alternativeTitles;

        $networks = array_values(TmdbClient::getArray($details, 'networks'));
        $highest['network'] = \is_array($networks[0] ?? null) ? (string) ($networks[0]['name'] ?? '') : '';
        $highest['external_ids'] = TmdbClient::getArray($details, 'external_ids');

        return $this->formatShowInfo($highest);
    }

    /**
     * The first search result whose name or original name scores strictly highest above zero.
     *
     * @param  array<int|string, mixed>  $shows
     * @return array<int|string, mixed>|null
     */
    public function selectBestShowPhp(array $shows, string $cleanName, ?int $year, string $country): ?array
    {
        $highestScore = 0.0;
        $highest = null;

        foreach ($shows as $show) {
            if (! \is_array($show) || ! $this->checkRequiredAttr($show, 'tmdbS')) {
                continue;
            }

            $originCountry = TmdbClient::getArray($show, 'origin_country');
            foreach (array_unique([TmdbClient::getString($show, 'name'), TmdbClient::getString($show, 'original_name')]) as $candidateName) {
                $score = $this->scoreCandidate(
                    $cleanName,
                    $year,
                    $candidateName,
                    TmdbClient::getString($show, 'first_air_date'),
                    $country,
                    (string) ($originCountry[0] ?? ''),
                );
                if ($score > $highestScore) {
                    $highestScore = $score;
                    $highest = $show;
                }
            }
        }

        return $highest;
    }

    /**
     * selectBestShowPhp() scored in one Rust call; falls back to PHP when Rust declines.
     *
     * @param  array<int|string, mixed>  $shows
     * @return array<int|string, mixed>|null
     */
    public function selectBestShowNative(array $shows, string $cleanName, ?int $year, string $country): ?array
    {
        $titles = $starteds = $countries = $owners = [];
        foreach ($shows as $key => $show) {
            if (! \is_array($show) || ! $this->checkRequiredAttr($show, 'tmdbS')) {
                continue;
            }

            $originCountry = TmdbClient::getArray($show, 'origin_country');
            $started = 's'.TmdbClient::getString($show, 'first_air_date');
            $candidateCountry = (string) ($originCountry[0] ?? '');
            foreach (array_unique([TmdbClient::getString($show, 'name'), TmdbClient::getString($show, 'original_name')]) as $candidateName) {
                $titles[] = $candidateName;
                $starteds[] = $started;
                $countries[] = $candidateCountry;
                $owners[] = $key;
            }
        }

        if ($titles === []) {
            return null;
        }

        $index = self::bestCandidateIndexNative($cleanName, $year, $country, $titles, $starteds, $countries);
        if ($index === null) {
            return $this->selectBestShowPhp($shows, $cleanName, $year, $country);
        }

        return $index >= 0 ? $shows[$owners[$index]] : null;
    }

    /**
     * `$candidateStarted` framing for bestCandidateIndexNative(): `i`+int, `s`+string or `n`.
     */
    public static function encodeCandidateStarted(mixed $started): string
    {
        return \is_int($started) ? 'i'.$started : (\is_string($started) ? 's'.$started : 'n');
    }

    /**
     * Rust `nntmux_tv_provider_best_candidate_nul`: index of the first candidate scoring strictly
     * highest above zero (as the providers' scoreCandidate() loops), -1 when none does, or null
     * when the library is unavailable or declines (NUL bytes, titles outside ASCII/Latin-1).
     *
     * @param  list<string>  $titles
     * @param  list<string>  $starteds  encodeCandidateStarted() values
     * @param  list<string>  $countries
     */
    public static function bestCandidateIndexNative(string $ourName, ?int $ourYear, string $ourCountry, array $titles, array $starteds, array $countries): ?int
    {
        $count = \count($titles);
        if ($count === 0 || \count($starteds) !== $count || \count($countries) !== $count) {
            return null;
        }
        if (! self::$matchFfiResolved) {
            self::$matchFfi = RustPipelineLibrary::resolveDomain('tv_provider_matching', self::MATCH_FFI_CDEF);
            self::$matchFfiResolved = true;
        }
        if (self::$matchFfi === null) {
            return null;
        }

        $titles = implode("\0", $titles);
        $starteds = implode("\0", $starteds);
        $countries = implode("\0", $countries);
        try {
            // @phpstan-ignore method.notFound
            $index = self::$matchFfi->nntmux_tv_provider_best_candidate_nul($ourName, \strlen($ourName), $ourYear ?? 0, $ourYear !== null ? 1 : 0, $ourCountry, \strlen($ourCountry), $titles, \strlen($titles), $starteds, \strlen($starteds), $countries, \strlen($countries), $count);
        } catch (Throwable) {
            return null;
        }

        if ($index === -2) {
            return -1;
        }

        return \is_int($index) && $index >= 0 && $index < $count ? $index : null;
    }

    public function fetchAllEpisodes(int $siteId, int $videoId): int
    {
        if ($siteId <= 0 || $videoId <= 0 || ! $this->tmdbClient->isConfigured()) {
            return 0;
        }

        $tvDetails = $this->tmdbClient->getTvShow($siteId);
        if ($tvDetails === null) {
            return 0;
        }

        $formatted = [];
        foreach (TmdbClient::getArray($tvDetails, 'seasons') as $seasonInfo) {
            if (! \is_array($seasonInfo)) {
                continue;
            }

            // Season 0 holds the specials.
            $seasonNumber = TmdbClient::getInt($seasonInfo, 'season_number', -1);
            if ($seasonNumber < 0) {
                continue;
            }

            $seasonData = $this->tmdbClient->getTvSeason($siteId, $seasonNumber);
            foreach (TmdbClient::getArray($seasonData ?? [], 'episodes') as $episode) {
                if (\is_array($episode) && $this->checkRequiredAttr($episode, 'tmdbE')) {
                    $formatted[] = $this->formatEpisodeInfo($episode);
                }
            }
        }

        return $this->addEpisodes($videoId, $formatted);
    }

    /**
     * Single episode lookup, falling back to the show's TVDB and IMDB ids.
     *
     * @return array<string, mixed>|false
     */
    public function fetchEpisode(int $siteId, int $season, int $episode, int $videoId = 0): array|false
    {
        if ($season < 0 || $episode <= 0 || ! $this->tmdbClient->isConfigured()) {
            return false;
        }

        if ($videoId > 0) {
            $ids = $this->getAllSiteIdsFromVideoID($videoId);
            if ($siteId > 0) {
                $ids['tmdb'] = $siteId;
            }
            $response = $this->tmdbClient->getTvEpisodeWithFallback($ids, $season, $episode);
        } elseif ($siteId > 0) {
            $response = $this->tmdbClient->getTvEpisode($siteId, $season, $episode);
        } else {
            return false;
        }

        return \is_array($response) && $this->checkRequiredAttr($response, 'tmdbE')
            ? $this->formatEpisodeInfo($response)
            : false;
    }

    /**
     * Assigns API show response values to a formatted array for insertion
     * Returns the formatted array.
     *
     * @return array<string, mixed>
     */
    public function formatShowInfo(mixed $show): array
    {
        $this->posterUrl = '';
        if (! \is_array($show)) {
            return [];
        }

        $posterPath = TmdbClient::getString($show, 'poster_path');
        $this->posterUrl = $posterPath !== '' ? 'https://image.tmdb.org/t/p/w500'.$posterPath : '';

        $imdbId = '';
        $externalIds = TmdbClient::getArray($show, 'external_ids');
        if (! empty($externalIds['imdb_id'])) {
            preg_match('/tt(?P<imdbid>\d{6,})$/i', (string) $externalIds['imdb_id'], $imdb);
            $imdbId = (string) ($imdb['imdbid'] ?? '');
        }

        $originCountry = TmdbClient::getArray($show, 'origin_country');
        $alternativeTitles = TmdbClient::getArray($show, 'alternative_titles');

        $traktId = 0;
        $tmdbId = TmdbClient::getInt($show, 'id');
        if ($tmdbId > 0) {
            $traktId = $this->lookupTraktId($tmdbId, $imdbId, TmdbClient::getInt($externalIds, 'tvdb_id'));
        }

        return [
            'type' => parent::TYPE_TV,
            'title' => TmdbClient::getString($show, 'name'),
            'summary' => TmdbClient::getString($show, 'overview'),
            'started' => TmdbClient::getString($show, 'first_air_date'),
            'publisher' => TmdbClient::getString($show, 'network'),
            'country' => (string) ($originCountry[0] ?? ''),
            'source' => parent::SOURCE_TMDB,
            'imdb' => $imdbId,
            'tvdb' => TmdbClient::getInt($externalIds, 'tvdb_id'),
            'trakt' => $traktId,
            'tvrage' => TmdbClient::getInt($externalIds, 'tvrage_id'),
            'tvmaze' => 0,
            'tmdb' => $tmdbId,
            'aliases' => ! empty($alternativeTitles) ? $alternativeTitles : '',
            'localzone' => '',
            'poster' => $this->posterUrl,
        ];
    }

    /**
     * Look up Trakt ID using available external IDs.
     * Tries TMDB ID first, then IMDB, then TVDB.
     *
     * @param  int|string  $imdbId  IMDB ID (numeric, without 'tt' prefix)
     * @return int Trakt ID or 0 if not found
     */
    protected function lookupTraktId(int $tmdbId, int|string $imdbId, int $tvdbId): int
    {
        try {
            $traktService = app(TraktService::class);

            if (! $traktService->isConfigured()) {
                return 0;
            }

            $lookups = [['tmdb', $tmdbId], ['imdb', $imdbId], ['tvdb', $tvdbId]];
            foreach ($lookups as [$type, $id]) {
                if (empty($id) || ($type === 'imdb' && ! imdb_id_is_valid($id))) {
                    continue;
                }
                $ids = $traktService->lookupShowIds($id, $type);
                if (! empty($ids['trakt'])) {
                    return (int) $ids['trakt'];
                }
            }
        } catch (Throwable) {
            // Trakt ID lookup is optional enrichment.
        }

        return 0;
    }

    /**
     * Assigns API episode response values to a formatted array for insertion
     * Returns the formatted array.
     *
     * @return array<string, mixed>
     */
    public function formatEpisodeInfo(mixed $episode): array
    {
        if (! \is_array($episode)) {
            return [];
        }

        $seasonNumber = TmdbClient::getInt($episode, 'season_number');
        $episodeNumber = TmdbClient::getInt($episode, 'episode_number');

        return [
            'title' => TmdbClient::getString($episode, 'name'),
            'series' => $seasonNumber,
            'episode' => $episodeNumber,
            'se_complete' => 'S'.sprintf('%02d', $seasonNumber).'E'.sprintf('%02d', $episodeNumber),
            'firstaired' => TmdbClient::getString($episode, 'air_date'),
            'summary' => TmdbClient::getString($episode, 'overview'),
        ];
    }

    /** Disabled: seven paired runs missed the 1.5x common p99 gate (min common mean 1.73x, p99 1.17x; uncommon mean 2.88x, p99 2.33x; see the W44 parity test). */
    public const bool W444_RUST_FFI_DEFAULT = false;

    public const string W444_FFI_CDEF = <<<'C'
        ssize_t nntmux_w444_tmdb_show_name_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W444_SUMMARY_KEYS = ['total_names', 'empty_name_count', 'year_suffix_count', 'imdb_id_count', 'article_prefix_count', 'colon_count', 'parenthetical_count', 'non_ascii_count', 'numeric_count'];

    private static ?bool $w444UseRustFfi = null;

    private static FFI|false|null $w444Ffi = null;

    /** Null restores the benchmark-qualified default. */
    public static function useW444RustFfi(?bool $enable = true): void
    {
        self::$w444UseRustFfi = $enable;
    }

    public static function usesW444RustFfi(): bool
    {
        return self::$w444UseRustFfi ?? self::W444_RUST_FFI_DEFAULT;
    }

    public static function resetW444RustFfi(): void
    {
        self::$w444UseRustFfi = null;
        self::$w444Ffi = null;
    }

    /**
     * Summarize TMDB show names as getShowInfo()/formatShowInfo() see them:
     * the " (19xx|20xx)" suffix getShowInfo() strips, a trailing tt\d{6,} IMDB
     * id, a leading the/a/an article, colons, parentheses, non-ASCII bytes and
     * digits. Entries come from strings, integers, or the name, original_name
     * or title field; other values count as empty.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeTmdbShowNameBatch(array $entries): array
    {
        return self::usesW444RustFfi()
            ? self::summarizeTmdbShowNameBatchNative($entries)
            : self::summarizeTmdbShowNameBatchPhp($entries);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeTmdbShowNameBatchNative(array $entries): array
    {
        return self::w444Call(self::w444Entries($entries)) ?? self::summarizeTmdbShowNameBatchPhp($entries);
    }

    /**
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeTmdbShowNameBatchPhp(array $entries): array
    {
        $summary = array_fill_keys(self::W444_SUMMARY_KEYS, 0);
        foreach (self::w444Entries($entries) as $name) {
            $summary['total_names']++;
            $name = trim($name);
            if ($name === '') {
                $summary['empty_name_count']++;

                continue;
            }
            $summary['year_suffix_count'] += (int) (preg_match('# \((?:19|20)\d{2}\)$#', $name) === 1);
            $summary['imdb_id_count'] += (int) (preg_match('/tt\d{6,}$/i', $name) === 1);
            $summary['article_prefix_count'] += (int) (preg_match('/^(?:the|a|an)\s/i', $name) === 1);
            $summary['colon_count'] += (int) str_contains($name, ':');
            $summary['parenthetical_count'] += (int) str_contains($name, '(');
            $summary['non_ascii_count'] += (int) (preg_match('/[\x80-\xFF]/', $name) === 1);
            $summary['numeric_count'] += (int) (strpbrk($name, '0123456789') !== false);
        }

        return $summary;
    }

    /** Only integer and string entries participate; objects are never cast or serialized.
     *
     * @param  array<array-key, mixed>  $input
     * @return list<string>
     */
    private static function w444Entries(array $input): array
    {
        $entries = [];
        foreach ($input as $item) {
            if (is_array($item)) {
                $item = $item['name'] ?? $item['original_name'] ?? $item['title'] ?? null;
            }
            $entries[] = is_string($item) || is_int($item) ? (string) $item : '';
        }

        return $entries;
    }

    /** @param list<string> $entries
     * @return array<string, int>|null
     */
    private static function w444Call(array $entries): ?array
    {
        try {
            $input = serialize([$entries]);
            if (strlen($input) > 16777216) {
                return null;
            }
            self::$w444Ffi ??= RustPipelineLibrary::resolveDomain('metadata_w444', self::W444_FFI_CDEF) ?? false;
            $ffi = self::$w444Ffi;
            if ($ffi === false) {
                return null;
            }
            $capacity = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, 2048, $capacity);
            if ($out === null) {
                return null;
            }
            // @phpstan-ignore method.notFound (Declared by W444_FFI_CDEF.)
            $written = $ffi->nntmux_w444_tmdb_show_name_batch($input, strlen($input), $out, $capacity);
            if (is_int($written) && $written < -1 && $written >= -16777216) {
                $out = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($out === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound (Declared by W444_FFI_CDEF.)
                $written = $ffi->nntmux_w444_tmdb_show_name_batch($input, strlen($input), $out, $capacity);
            }
            if (! is_int($written) || $written <= 0 || $written > $capacity || $written > 16777216) {
                return null;
            }
            $result = @unserialize(FFI::string($out, $written), ['allowed_classes' => false]);
            if (! is_array($result) || array_keys($result) !== self::W444_SUMMARY_KEYS || $result['total_names'] !== count($entries)) {
                return null;
            }
            $summary = [];
            foreach (self::W444_SUMMARY_KEYS as $key) {
                $value = $result[$key];
                if (! is_int($value) || $value < 0 || $value > count($entries)) {
                    return null;
                }
                $summary[$key] = $value;
            }

            return $summary;
        } catch (Throwable) {
            return null;
        }
    }
}
