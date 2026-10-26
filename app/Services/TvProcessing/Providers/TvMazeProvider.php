<?php

declare(strict_types=1);

namespace App\Services\TvProcessing\Providers;

use App\Services\TvMazeClient;
use App\Support\RustPipelineLibrary;
use DariusIII\TVMaze\Episode;
use DariusIII\TVMaze\TVShow;
use FFI;
use FFI\CData;
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

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean 2.91x, p99 2.62x; uncommon mean 2.30x, p99 1.79x); see RustTvCandidatePipelineTvMazeTvdbHydrationW50ParityTest. */
    private const W50_RUST_FFI_DEFAULT = true;

    public const string W50_FFI_CDEF = <<<'C'
        ssize_t nntmux_w503_tvmaze_episode_format_batch(const char *blob, size_t blob_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
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
     * Formats a batch of TVMaze episodes as formatEpisodes()/formatEpisodeInfo() do. Six values
     * per episode: kept (1 when season is not null), the series and episode (`(int)` casts), the
     * FNV-1a 32 hash of se_complete, the trimmed airdate as `YYYYMMDD` (a valid `YYYY-MM-DD`
     * with a non-zero year, else 0) and flags (1 number is not null, 2 non-blank name, 4
     * non-blank summary). Then the kept and dropped counts, and among kept episodes the special
     * (series 0) and undated counts and the max non-null episode number (at least 0).
     *
     * @param  list<array<string, mixed>>  $episodes  season, number, airdate, name, summary
     * @return list<int>
     */
    public static function w50EpisodeFormatBatch(array $episodes): array
    {
        return self::usesW50RustFfi() ? self::w50EpisodeFormatBatchNative($episodes) : self::w50EpisodeFormatBatchPhp($episodes);
    }

    /**
     * @param  list<array<string, mixed>>  $episodes
     * @return list<int>
     */
    public static function w50EpisodeFormatBatchNative(array $episodes): array
    {
        $count = \count($episodes);
        $columns = self::w50Columns($episodes, ['season' => null, 'number' => null, 'airdate' => '', 'name' => '', 'summary' => '']);
        $nullSeasons = array_keys($columns['season'], null, true);
        $nullNumbers = array_keys($columns['number'], null, true);
        $blob = implode("\0", $columns['airdate'])."\0".implode("\0", $columns['name'])."\0".implode("\0", $columns['summary']);
        $ints = pack('q*', \count($nullSeasons), ...$nullSeasons).pack('q*', \count($nullNumbers), ...$nullNumbers)
            .pack('q*', ...$columns['season']).pack('q*', ...$columns['number']);

        return self::w50Invoke($count === 0 ? '' : $blob, $ints, $count, $count * 6 + 5)
            ?? self::w50EpisodeFormatBatchPhp($episodes);
    }

    /**
     * @param  list<array<string, mixed>>  $episodes
     * @return list<int>
     */
    public static function w50EpisodeFormatBatchPhp(array $episodes): array
    {
        $out = [];
        $kept = $dropped = $specials = $undated = $maxEpisode = 0;
        foreach ($episodes as $item) {
            $season = $item['season'] ?? null;
            $number = $item['number'] ?? null;
            $series = (int) $season;
            $episode = (int) $number;
            $airdate = 0;
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', trim((string) ($item['airdate'] ?? '')), $m) === 1
                && (int) $m[1] > 0 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                $airdate = (int) $m[1] * 10000 + (int) $m[2] * 100 + (int) $m[3];
            }
            if ($season === null) {
                $dropped++;
            } else {
                $kept++;
                $specials += $series === 0 ? 1 : 0;
                $undated += $airdate === 0 ? 1 : 0;
                if ($number !== null) {
                    $maxEpisode = max($maxEpisode, $episode);
                }
            }
            array_push(
                $out,
                $season === null ? 0 : 1,
                $series,
                $episode,
                (int) hexdec(hash('fnv1a32', 'S'.sprintf('%02d', $series).'E'.sprintf('%02d', $episode))),
                $airdate,
                ($number !== null ? 1 : 0) | (trim((string) ($item['name'] ?? '')) !== '' ? 2 : 0)
                    | (trim((string) ($item['summary'] ?? '')) !== '' ? 4 : 0),
            );
        }
        array_push($out, $kept, $dropped, $specials, $undated, $maxEpisode);

        return $out;
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
            $written = $ffi->nntmux_w503_tvmaze_episode_format_batch($blob, \strlen($blob), $ints, \strlen($ints), $count, self::$w50Out, self::$w50OutCap);
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
            $imdbId = self::w56TvMazeImdbId((string) $show->externalIDs['imdb']);
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

    // ---------------------------------------------------------------------
    // Wave 56.4 batch helper
    // ---------------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean 4.19x, p99 3.69x; uncommon mean 32.55x, p99 23.47x); see RustTmdbTvMazeTvdbPar2AccumulatorCompactFpW56ParityTest. */
    public const bool W56_RUST_FFI_DEFAULT = true;

    public const string W56_FFI_CDEF = <<<'C'
        ssize_t nntmux_w564_tvmaze_show_row_batch(const char *text, size_t text_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /**
     * TVMaze show rows as formatShowInfo() and candidate matching see them. Each record yields
     * five words: flags (two-letter uppercase country 1, non-blank network 2, trimmed
     * case-insensitive title match 4, tt\d{6,} IMDB id 8, summary with tags 16, summary with
     * '&' 32), the IMDB digits span (offset << 32 | length, or -1), the summary length once
     * every <...> tag is removed, the removed tag count and similar_text() of the lowercased
     * trimmed title and name (-1 past 255 bytes).
     *
     * @param  list<string>  $summaries
     * @param  list<string>  $imdbIds
     * @param  list<string>  $countries
     * @param  list<string>  $networks
     * @param  list<string>  $titles
     * @param  list<string>  $ours
     * @return list<int>
     */
    public static function tvMazeShowRowBatch(array $summaries, array $imdbIds, array $countries, array $networks, array $titles, array $ours): array
    {
        return self::usesW56RustFfi()
            ? self::tvMazeShowRowBatchNative($summaries, $imdbIds, $countries, $networks, $titles, $ours)
            : self::tvMazeShowRowBatchPhp($summaries, $imdbIds, $countries, $networks, $titles, $ours);
    }

    /**
     * @param  list<string>  $summaries
     * @param  list<string>  $imdbIds
     * @param  list<string>  $countries
     * @param  list<string>  $networks
     * @param  list<string>  $titles
     * @param  list<string>  $ours
     * @return list<int>
     */
    public static function tvMazeShowRowBatchNative(array $summaries, array $imdbIds, array $countries, array $networks, array $titles, array $ours): array
    {
        self::w56TvMazeColumns($summaries, $imdbIds, $countries, $networks, $titles, $ours);
        $count = \count($summaries);
        if ($count === 0) {
            return [];
        }
        $text = implode("\0", $summaries)."\0".implode("\0", $imdbIds)."\0".implode("\0", $countries)
            ."\0".implode("\0", $networks)."\0".implode("\0", $titles)."\0".implode("\0", $ours);

        return self::w56TvMazeCall($text, '', $count, 5)
            ?? self::tvMazeShowRowBatchPhp($summaries, $imdbIds, $countries, $networks, $titles, $ours);
    }

    /**
     * @param  list<string>  $summaries
     * @param  list<string>  $imdbIds
     * @param  list<string>  $countries
     * @param  list<string>  $networks
     * @param  list<string>  $titles
     * @param  list<string>  $ours
     * @return list<int>
     */
    public static function tvMazeShowRowBatchPhp(array $summaries, array $imdbIds, array $countries, array $networks, array $titles, array $ours): array
    {
        self::w56TvMazeColumns($summaries, $imdbIds, $countries, $networks, $titles, $ours);
        $out = [];
        foreach ($summaries as $i => $summary) {
            $title = trim($titles[$i]);
            $name = trim($ours[$i]);
            $span = self::w56TvMazeImdbSpan($imdbIds[$i]);
            $tags = 0;
            $visible = \strlen((string) preg_replace('/<[^>]*>/', '', $summary, -1, $tags));
            $flags = (preg_match('/\A[A-Z]{2}\z/', $countries[$i]) === 1 ? 1 : 0)
                | (trim($networks[$i]) !== '' ? 2 : 0)
                | ($name !== '' && strtolower($title) === strtolower($name) ? 4 : 0)
                | ($span >= 0 ? 8 : 0)
                | ($tags > 0 ? 16 : 0)
                | (str_contains($summary, '&') ? 32 : 0);
            array_push(
                $out,
                $flags,
                $span,
                $visible,
                $tags,
                \strlen($title) > 255 || \strlen($name) > 255 ? -1 : similar_text(strtolower($title), strtolower($name)),
            );
        }

        return $out;
    }

    /** formatShowInfo()'s preg_match('/tt(?P<imdbid>\d{6,})$/i') capture, through the W56 toggle. */
    private static function w56TvMazeImdbId(string $raw): string
    {
        $span = self::usesW56RustFfi()
            ? self::tvMazeShowRowBatchNative([''], [$raw], [''], [''], [''], [''])[1]
            : self::w56TvMazeImdbSpan($raw);

        return $span < 0 ? '' : substr($raw, $span >> 32, $span & 0xFFFFFFFF);
    }

    private static function w56TvMazeImdbSpan(string $value): int
    {
        return preg_match('/tt(\d{6,})$/i', $value, $match, PREG_OFFSET_CAPTURE) === 1
            ? ($match[1][1] << 32) | \strlen($match[1][0])
            : -1;
    }

    public static function usesW56RustFfi(): bool
    {
        $enabled = self::w56RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W56_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W56 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW56RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w56RustFfiState('enabled');
        self::w56RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w56RustFfiState('enabled', $previous);
        }
    }

    /**
     * Equal-length list columns, or InvalidArgumentException.
     *
     * @param  array<array-key, mixed>  ...$columns
     */
    private static function w56TvMazeColumns(array ...$columns): void
    {
        $count = \count($columns[0]);
        foreach ($columns as $column) {
            if (! array_is_list($column) || \count($column) !== $count) {
                throw new \InvalidArgumentException('W56 batch columns must be lists of equal length.');
            }
        }
    }

    /**
     * One FFI crossing for the batch: `$words` int64 words back per record. Null when the
     * library is missing or declines a malformed frame, so the caller falls back to PHP.
     *
     * @return list<int>|null
     */
    private static function w56TvMazeCall(string $text, string $ints, int $count, int $words): ?array
    {
        try {
            /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int} $state */
            $state = self::w56RustFfiState();
            $ffi = $state['ffi'];
            if ($ffi === null) {
                $ffi = RustPipelineLibrary::resolveDomain('metadata_w56', self::W56_FFI_CDEF) ?? false;
                self::w56RustFfiState('ffi', $ffi);
            }
            if (! $ffi instanceof FFI) {
                return null;
            }
            $need = $count * $words;
            $out = $state['out'];
            $cap = $state['cap'];
            if (! $out instanceof CData || $cap < $need) {
                $cap = max(1024, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w56RustFfiState('out', $out);
                self::w56RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W56_FFI_CDEF.)
            $written = $ffi->nntmux_w564_tvmaze_show_row_batch($text, \strlen($text), $ints, \strlen($ints), $count, $out, $cap);
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
     * W56 routing override and cached binding plus reusable output buffer, kept in a function
     * static: no arguments returns the state, one argument reads a key ('reset' clears
     * everything) and two arguments write a key.
     */
    private static function w56RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;
        }

        return $state[$op] ?? null;
    }
}
