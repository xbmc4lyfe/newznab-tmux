<?php

declare(strict_types=1);

namespace App\Services\TvProcessing\Providers;

use App\Services\TvMazeClient;
use App\Support\RustPipelineLibrary;
use DariusIII\TVMaze\Episode;
use DariusIII\TVMaze\TVShow;
use FFI;
use Throwable;

/**
 * Class TvMazeProvider.
 *
 * Process information retrieved from the TVMaze API.
 */
class TvMazeProvider extends AbstractTvProvider
{
    /** Disabled until correctness and the common-path and p99 benchmark gates qualify. */
    public const bool W394_RUST_FFI_DEFAULT = false;

    public const string W394_FFI_CDEF = <<<'C'
        ssize_t nntmux_w394_tvmaze_episode_catalog(const char *input, size_t len, char *out, size_t cap);
        C;

    private static ?bool $w394UseRustFfi = null;

    private static FFI|false|null $w394Ffi = null;

    public static function useW394RustFfi(?bool $use = true): void
    {
        self::$w394UseRustFfi = $use;
        if ($use === null) {
            self::$w394Ffi = null;
        }
    }

    public static function usesW394RustFfi(): bool
    {
        return self::$w394UseRustFfi ?? self::W394_RUST_FFI_DEFAULT;
    }

    public static function resetW394RustFfi(): void
    {
        self::$w394UseRustFfi = null;
        self::$w394Ffi = null;
    }

    /**
     * Arguments contain only projected scalar fields, never caller-owned objects.
     *
     * @param  list<mixed>  $arguments
     * @return array<string, int|string|bool>|null
     */
    private static function w394Call(array $arguments): ?array
    {
        try {
            $input = serialize($arguments);
            if (strlen($input) > 16777216) {
                return null;
            }
            if (self::$w394Ffi === null) {
                self::$w394Ffi = RustPipelineLibrary::resolveDomain('metadata_w394', self::W394_FFI_CDEF) ?? false;
            }
            $ffi = self::$w394Ffi;
            if ($ffi === false) {
                return null;
            }
            $capacity = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, 2048, $capacity);
            if ($out === null) {
                return null;
            }
            // @phpstan-ignore method.notFound (Declared by W394_FFI_CDEF.)
            $written = $ffi->nntmux_w394_tvmaze_episode_catalog($input, strlen($input), $out, $capacity);
            if (is_int($written) && $written < -1 && $written >= -16777216) {
                $out = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($out === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound (Declared by W394_FFI_CDEF.)
                $written = $ffi->nntmux_w394_tvmaze_episode_catalog($input, strlen($input), $out, $capacity);
            }
            if (! is_int($written) || $written <= 0 || $written > $capacity || $written > 16777216) {
                return null;
            }
            $result = @unserialize(FFI::string($out, $written), ['allowed_classes' => false]);
            $types = ['total_episodes' => 'int', 'matching_season_count' => 'int', 'special_count' => 'int', 'missing_airdate_count' => 'int', 'max_episode_number' => 'int'];
            if (! is_array($result) || array_keys($result) !== array_keys($types)) {
                return null;
            }
            foreach ($types as $key => $type) {
                if (get_debug_type($result[$key]) !== $type) {
                    return null;
                }
            }

            return $result;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<array-key, mixed> $episodes
     * @return array<string, int|string|bool>
     */
    public static function summarizeTvMazeEpisodeCatalog(array $episodes, int $targetSeason = 0): array
    {
        if (self::usesW394RustFfi()) {
            $safe = [];
            foreach ($episodes as $row) {
                $row = is_array($row) ? $row : [];
                $safe[] = [
                    'season' => is_int($row['season'] ?? null) ? $row['season'] : null,
                    'number' => is_int($row['number'] ?? null) ? $row['number'] : null,
                    'airdate' => is_string($row['airdate'] ?? null) ? $row['airdate'] : null,
                ];
            }
            $native = self::w394Call([$safe, $targetSeason]);
            if ($native !== null) {
                return $native;
            }
        }

        return self::summarizeTvMazeEpisodeCatalogPhp($episodes, $targetSeason);
    }

    /** @param array<array-key, mixed> $episodes
     * @return array<string, int>
     */
    public static function summarizeTvMazeEpisodeCatalogPhp(array $episodes, int $targetSeason = 0): array
    {
        $matching = $special = $missing = $maximum = 0;
        foreach ($episodes as $row) {
            $row = is_array($row) ? $row : [];
            $season = $row['season'] ?? null;
            if (is_int($season)) {
                $matching += (int) ($season === $targetSeason);
                $special += (int) ($season === 0);
            }
            $number = $row['number'] ?? null;
            if (is_int($number) && $number > $maximum) {
                $maximum = $number;
            }
            $airdate = $row['airdate'] ?? null;
            $missing += (int) (! is_string($airdate) || trim($airdate) === '');
        }

        return ['total_episodes' => count($episodes), 'matching_season_count' => $matching, 'special_count' => $special, 'missing_airdate_count' => $missing, 'max_episode_number' => $maximum];
    }

    public TvMazeClient $client;

    /**
     * Candidate selection through Rust; null selects the benchmark-qualified production default
     * (PHP: the batch call misses the 1.5x gate, see tests/Unit/RustTvProvidersTraktMatchingParityTest.php).
     */
    public ?bool $useRustMatchFfi = null;

    public function __construct(?TvMazeClient $client = null)
    {
        parent::__construct();
        $this->client = $client ?? app(TvMazeClient::class);
    }

    /**
     * Calls the API to find the best matching show for a title.
     *
     * @return array<string, mixed>|false
     */
    public function getShowInfo(string $name, ?int $year = null, string $country = ''): array|false
    {
        // TVMaze does NOT like shows with the year in them even without the parentheses
        $name = (string) preg_replace('# \((19|20)\d{2}\)$#', '', $name);

        // The single search embeds AKAs; the full search ranks better but carries none.
        $candidates = [];
        $single = $this->client->singleSearchAkas($name);
        if ($single !== null) {
            $candidates[] = $single;
        }
        $return = $this->matchShowInfo($candidates, $name, $year, $country);

        if ($return === false) {
            $return = $this->matchShowInfo($this->client->searchShows($name), $name, $year, $country);
        }

        if (\is_array($return) && empty($return['aliases']) && (int) $return['tvmaze'] > 0) {
            $return['aliases'] = $this->client->akas((int) $return['tvmaze']);
        }

        return $return;
    }

    /**
     * Attempts to find the best matching show by title or aliases.
     *
     * @param  list<TVShow>  $shows
     * @return array<string, mixed>|false
     */
    private function matchShowInfo(array $shows, string $cleanName, ?int $year, string $country): array|false
    {
        $highest = ($this->useRustMatchFfi ?? false)
            ? $this->selectBestShowNative($shows, $cleanName, $year, $country)
            : $this->selectBestShowPhp($shows, $cleanName, $year, $country);

        return $highest !== null ? $this->formatShowInfo($highest) : false;
    }

    /**
     * The first show whose name or an AKA scores strictly highest above zero.
     *
     * @param  list<TVShow>  $shows
     */
    public function selectBestShowPhp(array $shows, string $cleanName, ?int $year, string $country): ?TVShow
    {
        $highestScore = 0.0;
        $highest = null;

        foreach ($shows as $show) {
            if (! $this->checkRequiredAttr($show, 'tvmazeS')) {
                continue;
            }

            $names = [(string) $show->name];
            foreach (\is_array($show->akas) ? $show->akas : [] as $aka) {
                if (\is_array($aka) && ! empty($aka['name'])) {
                    $names[] = (string) $aka['name'];
                }
            }

            foreach ($names as $candidateName) {
                $score = $this->scoreCandidate($cleanName, $year, $candidateName, $show->premiered, $country, (string) $show->country);
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
     * @param  list<TVShow>  $shows
     */
    public function selectBestShowNative(array $shows, string $cleanName, ?int $year, string $country): ?TVShow
    {
        $titles = $starteds = $countries = $owners = [];
        foreach ($shows as $key => $show) {
            if (! $this->checkRequiredAttr($show, 'tvmazeS')) {
                continue;
            }

            $started = TmdbProvider::encodeCandidateStarted($show->premiered);
            $candidateCountry = (string) $show->country;
            $titles[] = (string) $show->name;
            $starteds[] = $started;
            $countries[] = $candidateCountry;
            $owners[] = $key;
            foreach (\is_array($show->akas) ? $show->akas : [] as $aka) {
                if (\is_array($aka) && ! empty($aka['name'])) {
                    $titles[] = (string) $aka['name'];
                    $starteds[] = $started;
                    $countries[] = $candidateCountry;
                    $owners[] = $key;
                }
            }
        }

        if ($titles === []) {
            return null;
        }

        $index = TmdbProvider::bestCandidateIndexNative($cleanName, $year, $country, $titles, $starteds, $countries);
        if ($index === null) {
            return $this->selectBestShowPhp($shows, $cleanName, $year, $country);
        }

        return $index >= 0 ? $shows[$owners[$index]] : null;
    }

    public function fetchAllEpisodes(int $siteId, int $videoId): int
    {
        if ($siteId <= 0 || $videoId <= 0) {
            return 0;
        }

        return $this->addEpisodes($videoId, $this->formatEpisodes($this->client->episodesByShow($siteId)));
    }

    public function supportsAirdateLookup(): bool
    {
        return true;
    }

    public function fetchEpisodesByAirdate(int $siteId, int $videoId, string $airdate): int
    {
        if ($siteId <= 0 || $videoId <= 0) {
            return 0;
        }

        return $this->addEpisodes($videoId, $this->formatEpisodes($this->client->episodesByDate($siteId, $airdate)));
    }

    /**
     * @return array<string, mixed>|false
     */
    public function fetchEpisode(int $siteId, int $season, int $episode, int $videoId = 0): array|false
    {
        if ($siteId <= 0 || $season < 0 || $episode <= 0) {
            return false;
        }

        $response = $this->client->episodeByNumber($siteId, $season, $episode);

        return $response !== null && $this->checkRequiredAttr($response, 'tvmazeE')
            ? $this->formatEpisodeInfo($response)
            : false;
    }

    /**
     * @param  list<Episode>  $episodes
     * @return list<array<string, mixed>>
     */
    private function formatEpisodes(array $episodes): array
    {
        $formatted = [];
        foreach ($episodes as $episode) {
            // Specials carry season but no number; addEpisodes() keys those by airdate.
            if ($episode->season !== null) {
                $formatted[] = $this->formatEpisodeInfo($episode);
            }
        }

        return $formatted;
    }

    /**
     * Assigns API show response values to a formatted array for insertion
     * Returns the formatted array.
     *
     * @return array<string, mixed>
     */
    public function formatShowInfo(mixed $show): array
    {
        $this->posterUrl = (string) ($show->originalImage ?? $show->mediumImage ?? '');

        $tvdbId = (int) ($show->externalIDs['thetvdb'] ?? 0);
        $imdbId = '';

        if (! empty($show->externalIDs['imdb'])) {
            preg_match('/tt(?P<imdbid>\d{6,})$/i', (string) $show->externalIDs['imdb'], $imdb);
            $imdbId = (string) ($imdb['imdbid'] ?? '');
        }

        $externalIds = $this->lookupExternalIds($tvdbId, $imdbId);

        return [
            'type' => parent::TYPE_TV,
            'title' => (string) $show->name,
            'summary' => (string) $show->summary,
            'started' => (string) $show->premiered,
            'publisher' => (string) ($show->network ?? ($show->webChannel['name'] ?? '')),
            'country' => (string) $show->country,
            'source' => parent::SOURCE_TVMAZE,
            'imdb' => $imdbId,
            'tvdb' => $tvdbId,
            'tvmaze' => (int) $show->id,
            'trakt' => $externalIds['trakt'],
            'tvrage' => (int) ($show->externalIDs['tvrage'] ?? 0),
            'tmdb' => $externalIds['tmdb'],
            'aliases' => ! empty($show->akas) ? (array) $show->akas : '',
            'localzone' => (string) ($show->network_array['country']['timezone'] ?? ($show->webChannel['country']['timezone'] ?? '')),
            'poster' => $this->posterUrl,
        ];
    }

    /**
     * Assigns API episode response values to a formatted array for insertion
     * Returns the formatted array.
     *
     * @return array<string, mixed>
     */
    public function formatEpisodeInfo(mixed $episode): array
    {
        return [
            'title' => (string) $episode->name,
            'series' => (int) $episode->season,
            'episode' => (int) $episode->number,
            'se_complete' => 'S'.sprintf('%02d', $episode->season).'E'.sprintf('%02d', $episode->number),
            'firstaired' => (string) $episode->airdate,
            'summary' => (string) $episode->summary,
        ];
    }
}
