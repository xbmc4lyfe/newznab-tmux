<?php

declare(strict_types=1);

namespace App\Services\TvProcessing\Providers;

use App\Services\TraktService;
use App\Services\TvMazeClient;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Throwable;

/**
 * Class TraktProvider.
 *
 * Process information retrieved from the Trakt API.
 */
class TraktProvider extends AbstractTvProvider
{
    public TraktService $client;

    /**
     * The URL to grab the TV fanart.
     */
    public string $fanartUrl = '';

    /**
     * The localized (network airing) timezone of the show being processed.
     */
    private string $localizedTZ = '';

    /**
     * Candidate selection and imageUrl() through Rust; null selects the benchmark-qualified
     * production default (PHP: both miss the 1.5x gate, see tests/Unit/RustTvProvidersTraktMatchingParityTest.php).
     */
    public ?bool $useRustFfi = null;

    public function __construct(?TraktService $client = null)
    {
        parent::__construct();
        $this->client = $client ?? new TraktService;
    }

    /**
     * Get show information from Trakt by name.
     *
     * @return array<string, mixed>|false
     */
    public function getShowInfo(string $name, ?int $year = null, string $country = ''): array|false
    {
        $client = $this->tvClient();
        if (! $client->isConfigured()) {
            return false;
        }

        // Trakt does NOT like shows with the year in them even without the parentheses
        $name = (string) preg_replace('# \((19|20)\d{2}\)$#', '', $name);

        $results = (array) $client->searchShows($name);
        $highest = ($this->useRustFfi ?? false)
            ? $this->selectBestShowNative($results, $name, $year)
            : $this->selectBestShowPhp($results, $name, $year);

        if ($highest === null || empty($highest['ids']['trakt'])) {
            return false;
        }

        $fullShow = $client->getShowSummary($highest['ids']['trakt']);

        return \is_array($fullShow) && $this->checkRequiredAttr($fullShow, 'traktS')
            ? $this->formatShowInfo($fullShow)
            : false;
    }

    /**
     * The first search result whose title scores strictly highest above zero.
     *
     * @param  array<int|string, mixed>  $results
     * @return array<int|string, mixed>|null
     */
    public function selectBestShowPhp(array $results, string $name, ?int $year): ?array
    {
        $highestScore = 0.0;
        $highest = null;
        foreach ($results as $result) {
            $show = \is_array($result) ? ($result['show'] ?? null) : null;
            if (! \is_array($show) || empty($show['title'])) {
                continue;
            }

            $score = $this->scoreCandidate($name, $year, (string) $show['title'], isset($show['year']) ? (int) $show['year'] : null);
            if ($score > $highestScore) {
                $highestScore = $score;
                $highest = $show;
            }
        }

        return $highest;
    }

    /**
     * selectBestShowPhp() scored in one Rust call; falls back to PHP when Rust declines.
     *
     * @param  array<int|string, mixed>  $results
     * @return array<int|string, mixed>|null
     */
    public function selectBestShowNative(array $results, string $name, ?int $year): ?array
    {
        $titles = $starteds = $countries = $shows = [];
        foreach ($results as $result) {
            $show = \is_array($result) ? ($result['show'] ?? null) : null;
            if (! \is_array($show) || empty($show['title'])) {
                continue;
            }

            $titles[] = (string) $show['title'];
            $starteds[] = isset($show['year']) ? 'i'.(int) $show['year'] : 'n';
            $countries[] = '';
            $shows[] = $show;
        }

        if ($titles === []) {
            return null;
        }

        $index = TmdbProvider::bestCandidateIndexNative($name, $year, '', $titles, $starteds, $countries);
        if ($index === null) {
            return $this->selectBestShowPhp($results, $name, $year);
        }

        return $index >= 0 ? $shows[$index] : null;
    }

    public function fetchAllEpisodes(int $siteId, int $videoId): int
    {
        $client = $this->tvClient();
        if ($siteId <= 0 || $videoId <= 0 || ! $client->isConfigured()) {
            return 0;
        }

        $this->localizedTZ = $this->getLocalZoneFromVideoID($videoId);

        $seasons = $client->getShowSeasons((string) $siteId, 'full,episodes');
        if (! \is_array($seasons)) {
            return 0;
        }

        $formatted = [];
        foreach ($seasons as $seasonInfo) {
            if (! \is_array($seasonInfo) || (int) ($seasonInfo['number'] ?? -1) < 0) {
                continue;
            }

            foreach (\is_array($seasonInfo['episodes'] ?? null) ? $seasonInfo['episodes'] : [] as $episode) {
                if (\is_array($episode) && $this->checkRequiredAttr($episode, 'traktE')) {
                    $formatted[] = $this->formatEpisodeInfo($episode);
                }
            }
        }

        return $this->addEpisodes($videoId, $formatted);
    }

    /**
     * Single episode lookup, falling back to the show's TMDB, TVDB and IMDB ids.
     *
     * @return array<string, mixed>|false
     */
    public function fetchEpisode(int $siteId, int $season, int $episode, int $videoId = 0): array|false
    {
        $client = $this->tvClient();
        if ($season < 0 || $episode <= 0 || ! $client->isConfigured()) {
            return false;
        }

        if ($videoId > 0) {
            $this->localizedTZ = $this->getLocalZoneFromVideoID($videoId);
            $ids = $this->getAllSiteIdsFromVideoID($videoId);
            if ($siteId > 0) {
                $ids['trakt'] = $siteId;
            }
            $response = $client->getEpisodeSummaryWithFallback($ids, $season, $episode, 'full');
        } elseif ($siteId > 0) {
            $response = $client->getEpisodeSummary($siteId, $season, $episode, 'full');
        } else {
            return false;
        }

        return \is_array($response) && $this->checkRequiredAttr($response, 'traktE')
            ? $this->formatEpisodeInfo($response)
            : false;
    }

    /**
     * Save the poster, falling back to the fan art.
     */
    public function getPoster(int $videoId): int
    {
        if ($this->savePoster($videoId, $this->posterUrl) || $this->savePoster($videoId, $this->fanartUrl)) {
            return 1;
        }

        return 0;
    }

    /**
     * Assigns API show response values to a formatted array for insertion
     * Returns the formatted array.
     *
     * @return array<string, mixed>
     */
    public function formatShowInfo(mixed $show): array
    {
        $this->posterUrl = $this->fanartUrl = $this->localizedTZ = '';
        if (! \is_array($show)) {
            return [];
        }

        $ids = \is_array($show['ids'] ?? null) ? $show['ids'] : [];
        $airs = \is_array($show['airs'] ?? null) ? $show['airs'] : [];
        $images = \is_array($show['images'] ?? null) ? $show['images'] : [];

        preg_match('/tt(?P<imdbid>\d{6,})$/i', (string) ($ids['imdb'] ?? ''), $imdb);
        $this->posterUrl = $this->imageUrl($images['poster'] ?? null);
        $this->fanartUrl = $this->imageUrl($images['fanart'] ?? null);
        $this->localizedTZ = (string) ($airs['timezone'] ?? '');

        $imdbId = (string) ($imdb['imdbid'] ?? '');
        $tvdbId = (int) ($ids['tvdb'] ?? 0);

        return [
            'type' => parent::TYPE_TV,
            'title' => (string) ($show['title'] ?? ''),
            'summary' => (string) ($show['overview'] ?? ''),
            'started' => $this->formatTraktDate($show['first_aired'] ?? null),
            'publisher' => (string) ($show['network'] ?? ''),
            'country' => (string) ($show['country'] ?? ''),
            'source' => parent::SOURCE_TRAKT,
            'imdb' => $imdbId,
            'tvdb' => $tvdbId,
            'trakt' => (int) ($ids['trakt'] ?? 0),
            'tvrage' => (int) ($ids['tvrage'] ?? 0),
            'tvmaze' => $this->lookupTvMazeId($tvdbId, $imdbId),
            'tmdb' => (int) ($ids['tmdb'] ?? 0),
            'aliases' => ! empty($show['aliases']) ? $show['aliases'] : '',
            'localzone' => $this->localizedTZ,
            'poster' => $this->posterUrl,
        ];
    }

    /**
     * Trakt image fields are either the legacy {thumb, full, medium} object or a list of
     * host paths without a scheme.
     */
    private function imageUrl(mixed $image): string
    {
        return ($this->useRustFfi ?? false) ? $this->imageUrlNative($image) : $this->imageUrlPhp($image);
    }

    public function imageUrlPhp(mixed $image): string
    {
        if (! \is_array($image)) {
            return '';
        }

        $url = (string) ($image['full'] ?? $image['medium'] ?? $image['thumb'] ?? (\is_string($image[0] ?? null) ? $image[0] : ''));

        if ($url !== '' && ! str_starts_with($url, 'http')) {
            $url = 'https://'.ltrim($url, '/');
        }

        return $url;
    }

    /**
     * imageUrlPhp() with the scheme normalisation in Rust `nntmux_trakt_image_url`.
     */
    public function imageUrlNative(mixed $image): string
    {
        if (! \is_array($image)) {
            return '';
        }

        $url = (string) ($image['full'] ?? $image['medium'] ?? $image['thumb'] ?? (\is_string($image[0] ?? null) ? $image[0] : ''));
        if (($ffi = TraktService::traktFfi()) !== null) {
            $len = \strlen($url);
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, $len + 16, $cap);
            if ($scratch !== null) {
                try {
                    // @phpstan-ignore method.notFound
                    $written = $ffi->nntmux_trakt_image_url($url, $len, $scratch, $cap);
                    if (\is_int($written) && $written >= 0) {
                        return $written === 0 ? '' : FFI::string($scratch, $written);
                    }
                } catch (Throwable) {
                    // Older native libraries retain the PHP implementation.
                }
            }
        }

        if ($url !== '' && ! str_starts_with($url, 'http')) {
            $url = 'https://'.ltrim($url, '/');
        }

        return $url;
    }

    /**
     * Look up TVMaze ID using TVDB ID or IMDB ID.
     *
     * @param  int|string  $imdbId  IMDB ID (numeric, without 'tt' prefix)
     * @return int TVMaze ID or 0 if not found
     */
    protected function lookupTvMazeId(int $tvdbId, int|string $imdbId): int
    {
        try {
            $tvmaze = app(TvMazeClient::class);

            $show = $tvdbId > 0 ? $tvmaze->lookup('thetvdb', $tvdbId) : null;
            if ($show === null && ! empty($imdbId) && imdb_id_is_valid($imdbId)) {
                $show = $tvmaze->lookup('imdb', 'tt'.$imdbId);
            }

            return (int) ($show->id ?? 0);
        } catch (Throwable) {
            // TVMaze ID lookup is optional enrichment.
            return 0;
        }
    }

    /**
     * Assigns API episode response values to a formatted array for insertion
     * Returns the formatted array.
     *
     * @return array<string, mixed>
     */
    public function formatEpisodeInfo(mixed $episode): array
    {
        $season = (int) ($episode['season'] ?? 0);
        $episodeNumber = (int) ($episode['number'] ?? ($episode['episode'] ?? 0));

        return [
            'title' => (string) ($episode['title'] ?? ''),
            'series' => $season,
            'episode' => $episodeNumber,
            'se_complete' => 'S'.sprintf('%02d', $season).'E'.sprintf('%02d', $episodeNumber),
            'firstaired' => $this->formatTraktDate($episode['first_aired'] ?? null),
            'summary' => (string) ($episode['overview'] ?? ''),
        ];
    }

    /**
     * Trakt dates are UTC instants; the airdate is the local date in the show's timezone.
     */
    private function formatTraktDate(mixed $date): string
    {
        if (empty($date)) {
            return '';
        }

        try {
            return Carbon::parse((string) $date)
                ->setTimezone($this->localizedTZ !== '' ? $this->localizedTZ : 'UTC')
                ->format('Y-m-d');
        } catch (Throwable) {
            try {
                return Carbon::parse((string) $date)->format('Y-m-d');
            } catch (Throwable) {
                return '';
            }
        }
    }

    /**
     * Trakt client for TV processing: transient failures are thrown so releases are retried later.
     */
    private function tvClient(): TraktService
    {
        return $this->client->throwingOnTransientFailure();
    }

    // ---------------------------------------------------------------------
    // Wave 49.4 batch helper
    // ---------------------------------------------------------------------

    /** Enabled: minimum of three paired runs cleared every 1.5x gate (common mean 2.65x, p99 2.12x; uncommon mean 4.23x, p99 2.86x); see RustTvProvidersLocalDbTraktOutcomePosterAdderW49ParityTest. */
    private const bool W49_RUST_FFI_DEFAULT = true;

    public const string W49_FFI_CDEF = <<<'C'
        ssize_t nntmux_w49_tv_trakt_provider_ids_batch(const char *text, size_t text_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
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
     * Trakt id/image normalization for a batch: two ints per show, [imdb number, image kind].
     *
     * The imdb number is formatShowInfo()'s /tt(\d{6,})$/i capture as an integer (0 without a
     * match, -1 above 18 digits). The image kind follows imageUrlPhp() for the chosen URL:
     * 0 empty, 1 kept as is (starts with 'http'), or 2 | (leading slash count << 2) when the
     * URL becomes 'https://' plus the URL without those slashes.
     *
     * @param  list<string>  $imdbIds
     * @param  list<string>  $imageUrls
     * @return list<int>
     */
    public static function traktIdsBatch(array $imdbIds, array $imageUrls): array
    {
        return self::usesW49RustFfi()
            ? self::traktIdsBatchNative($imdbIds, $imageUrls)
            : self::traktIdsBatchPhp($imdbIds, $imageUrls);
    }

    /**
     * @param  list<string>  $imdbIds
     * @param  list<string>  $imageUrls
     * @return list<int>
     */
    public static function traktIdsBatchNative(array $imdbIds, array $imageUrls): array
    {
        self::w49AssertColumns($imdbIds, $imageUrls);
        if ($imdbIds === []) {
            return [];
        }

        return self::w49Call(implode("\0", $imdbIds)."\0".implode("\0", $imageUrls), '', \count($imdbIds))
            ?? self::traktIdsBatchPhp($imdbIds, $imageUrls);
    }

    /**
     * @param  list<string>  $imdbIds
     * @param  list<string>  $imageUrls
     * @return list<int>
     */
    public static function traktIdsBatchPhp(array $imdbIds, array $imageUrls): array
    {
        self::w49AssertColumns($imdbIds, $imageUrls);
        $out = [];
        foreach ($imdbIds as $i => $imdb) {
            $number = 0;
            if (preg_match('/tt(\d{6,})$/i', $imdb, $match) === 1) {
                $number = \strlen($match[1]) > 18 ? -1 : (int) $match[1];
            }
            $url = $imageUrls[$i];
            $image = $url === '' ? 0 : (str_starts_with($url, 'http') ? 1 : 2 | (strspn($url, '/') << 2));
            array_push($out, $number, $image);
        }

        return $out;
    }

    /**
     * One FFI crossing for the batch: NUL-joined text columns, little-endian int64 scalars,
     * 2 int64 words back per record. Null when the library is missing or declines.
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
            $need = $count * 2;
            $out = self::$w49Out;
            if ($out === null || self::$w49OutCap < $need) {
                self::$w49OutCap = max(1024, $need);
                $out = self::$w49Out = $ffi->new('int64_t['.self::$w49OutCap.']');
            }
            // @phpstan-ignore method.notFound (Declared by W49_FFI_CDEF.)
            $written = $ffi->nntmux_w49_tv_trakt_provider_ids_batch($text, \strlen($text), $ints, \strlen($ints), $count, $out, self::$w49OutCap);
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
    // WAVE 63.3 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 6.38x, p99 5.95x; uncommon mean 5.63x, p99 4.84x); see RustTvBaseVideoLocalDbTraktProviderContextAdderW63ParityTest. */
    public const bool W63_RUST_FFI_DEFAULT = true;

    public const string W63_TRAKT_PROVIDER_FFI_CDEF = <<<'C'
        ssize_t nntmux_w633_trakt_provider_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of the Wave 63.3 batch. */
    public const int W63_TRAKT_PROVIDER_TRAILER = 12;

    private const string W63_CONTROL_CHARS = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F";

    public static function enableW63RustFfi(): void
    {
        self::w63RustFfiState('set', true);
    }

    public static function disableW63RustFfi(): void
    {
        self::w63RustFfiState('set', false);
    }

    /** Restores W63_RUST_FFI_DEFAULT and drops the cached library handle and scratch buffers. */
    public static function resetW63RustFfi(): void
    {
        self::w63RustFfiState('reset');
    }

    public static function usesW63RustFfi(): bool
    {
        $override = self::w63RustFfiState();

        return \is_bool($override) ? $override : self::W63_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the TraktProvider Wave 63 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW63RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w63RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w63RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 63.3 Trakt show/episode/id/image resolution and candidate scoring plans. Each item is `name|year|candidateTitle|
     * candidateYear|traktId|tvdbId|imdbId|tmdbId|poster|fanart|timezone|season|episode`. The trimmed name loses a trailing
     * ` (YYYY)` (19xx/20xx, as getShowInfo()), whose year applies unless the trimmed year field is a 19xx/20xx year. Per item
     * `nameState | stripped << 2 | traktState << 3 | tvdbState << 5 | imdbState << 7 | tmdbState << 9 | posterState << 11 |
     * fanartState << 13 | zoneState << 15 | seasonState << 17 | episodeState << 19 | route << 21 | episodeQuery << 23 |
     * yearCode << 25 | score << 27 | min(posterLength, 255) << 35 | min(fanartLength, 255) << 43 |
     * min(candidateTokens, 255) << 51`: the name state is 0 empty, 1 up to 255 bytes without control bytes, 2 otherwise;
     * trakt, tvdb and tmdb ids are 0 empty, 1 a trimmed positive 1-10 digit id, 2 invalid; the imdb state is 1 when the
     * trimmed field ends in `tt` (any case) and six or more digits (formatShowInfo()); images are 0 empty, 1 starting with
     * `http`, 2 a host path resolved to `https://` + the path without leading slashes (imageUrl()), with the resolved
     * length; the zone is 0 empty, 1 `UTC` or an up to 64-byte `[A-Za-z0-9/_+-]` name holding an inner `/` without `//`,
     * 2 other; season and episode are 0 empty, 1 up to 3/4 digits, 2 other; the route is 2 a summary by trakt id, 1 a name
     * search (valid name with tokens), else 0; the episode query (valid season and positive episode) is 1 by trakt id, 2
     * through the tvdb/imdb/tmdb fallback, else 0; the year code is 0 unless both are years, then 1 equal, 2 one apart,
     * 3 conflicting; the score is 0 without candidate tokens, else the distinct-token Jaccard percent + 20/10 for year
     * codes 1/2 - 30 for a conflict, clamped to 0-120. Then the three route and three episode query counts, the resolved
     * posters, the fanart fallbacks (no poster), the 1-based index of the first strictly highest positive score (0 when
     * none), that score, the distinct valid trakt ids and the distinct name-search slugs.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w63TraktProviderPlanBatch(array $items): array
    {
        return self::usesW63RustFfi() ? self::w63TraktProviderPlanBatchNative($items) : self::w63TraktProviderPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w63TraktProviderPlanBatchNative(array $items): array
    {
        return self::w63Invoke($items, \count($items) + self::W63_TRAKT_PROVIDER_TRAILER) ?? self::w63TraktProviderPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w63TraktProviderPlanBatchPhp(array $items): array
    {
        $out = [];
        $routes = [0, 0, 0];
        $episodeQueries = [0, 0, 0];
        $posters = $fanarts = $best = $bestScore = $index = 0;
        $trakts = $slugs = [];
        foreach ($items as $item) {
            $index++;
            [$rawName, $rawYear, $rawCandidate, $rawCandidateYear, $rawTrakt, $rawTvdb, $rawImdb, $rawTmdb, $rawPoster, $rawFanart, $rawZone, $rawSeason, $rawEpisode]
                = explode('|', (string) $item, 13) + ['', '', '', '', '', '', '', '', '', '', '', '', ''];
            $raw = trim($rawName);
            $n = \strlen($raw);
            $strippedYear = $n >= 7 && $raw[$n - 7] === ' ' && $raw[$n - 6] === '(' && $raw[$n - 1] === ')' ? self::w63Year(substr($raw, $n - 5, 4)) : null;
            $name = $strippedYear !== null ? rtrim(substr($raw, 0, $n - 7)) : $raw;
            $nameState = self::w63TextState($name);
            $ours = self::w63Year(trim($rawYear)) ?? $strippedYear;
            $nameTokens = self::w63Tokens($name);
            $candidate = self::w63Tokens($rawCandidate);
            [$traktState, $trakt] = self::w63IdState($rawTrakt);
            [$tvdbState] = self::w63IdState($rawTvdb);
            [$tmdbState] = self::w63IdState($rawTmdb);
            $imdb = trim($rawImdb);
            $run = strspn(strrev($imdb), '0123456789');
            $at = \strlen($imdb) - $run;
            $imdbState = $imdb === '' ? 0 : ($run >= 6 && $at >= 2 && strtolower(substr($imdb, $at - 2, 2)) === 'tt' ? 1 : 2);
            [$posterState, $posterLength] = self::w63ImageState($rawPoster);
            [$fanartState, $fanartLength] = self::w63ImageState($rawFanart);
            $zone = self::w63ZoneState($rawZone);
            [$seasonState] = self::w63CountState($rawSeason, 3);
            [$episodeState, $episode] = self::w63CountState($rawEpisode, 4);
            $route = $traktState === 1 ? 2 : ($nameState === 1 && $nameTokens !== [] ? 1 : 0);
            $episodeQuery = 0;
            if ($seasonState === 1 && $episodeState === 1 && $episode > 0) {
                $episodeQuery = $traktState === 1 ? 1 : ($tvdbState === 1 || $imdbState === 1 || $tmdbState === 1 ? 2 : 0);
            }
            $yearCode = self::w63YearCode($ours, self::w63Year(trim($rawCandidateYear)));
            $score = $candidate === [] ? 0 : max(0, min(120, self::w63Jaccard($nameTokens, $candidate) + [0, 20, 10, 0][$yearCode] - ($yearCode === 3 ? 30 : 0)));
            $routes[$route]++;
            $episodeQueries[$episodeQuery]++;
            $posters += $posterState !== 0 ? 1 : 0;
            $fanarts += $posterState === 0 && $fanartState !== 0 ? 1 : 0;
            if ($score > $bestScore) {
                $best = $index;
                $bestScore = $score;
            }
            if ($traktState === 1) {
                $trakts[$trakt] = true;
            }
            if ($route === 1) {
                $slugs[implode('-', $nameTokens)] = true;
            }
            $out[] = $nameState | ($strippedYear !== null ? 1 : 0) << 2 | $traktState << 3 | $tvdbState << 5 | $imdbState << 7 | $tmdbState << 9
                | $posterState << 11 | $fanartState << 13 | $zone << 15 | $seasonState << 17 | $episodeState << 19 | $route << 21
                | $episodeQuery << 23 | $yearCode << 25 | $score << 27 | min($posterLength, 255) << 35 | min($fanartLength, 255) << 43
                | min(\count($candidate), 255) << 51;
        }
        array_push($out, ...$routes, ...$episodeQueries);
        array_push($out, $posters, $fanarts, $best, $bestScore, \count($trakts), \count($slugs));

        return $out;
    }

    /**
     * (0 empty, 1 an `http` URL, 2 a host path gaining `https://`; the resolved URL length) for the trimmed field.
     *
     * @return array{int, int}
     */
    private static function w63ImageState(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [0, 0];
        }

        return str_starts_with($text, 'http') ? [1, \strlen($text)] : [2, 8 + \strlen(ltrim($text, '/'))];
    }

    /** 0 empty, 1 `UTC` or an `Area/City` zone name, 2 other, for the trimmed field. */
    private static function w63ZoneState(string $text): int
    {
        $text = trim($text);
        $length = \strlen($text);
        if ($length === 0) {
            return 0;
        }

        return $text === 'UTC' || ($length <= 64 && strspn($text, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789/_-+') === $length
            && str_contains($text, '/') && $text[0] !== '/' && $text[$length - 1] !== '/' && ! str_contains($text, '//')) ? 1 : 2;
    }

    /**
     * (0 empty, 1 one to $max digits, 2 other; the value) for the trimmed field.
     *
     * @return array{int, int}
     */
    private static function w63CountState(string $text, int $max): array
    {
        $text = trim($text);
        if ($text === '') {
            return [0, 0];
        }
        $value = self::w63Digits($text, 1, $max);

        return $value === null ? [2, 0] : [1, $value];
    }

    /** $min to $max ASCII digits as their value, else null. */
    private static function w63Digits(string $text, int $min, int $max): ?int
    {
        $length = \strlen($text);

        return $length >= $min && $length <= $max && strspn($text, '0123456789') === $length ? (int) $text : null;
    }

    /** A 19xx/20xx four-digit year, else null. */
    private static function w63Year(string $text): ?int
    {
        return \strlen($text) === 4 && (str_starts_with($text, '19') || str_starts_with($text, '20')) ? self::w63Digits($text, 4, 4) : null;
    }

    /** 0 empty, 1 up to 255 bytes without C0 controls or DEL, 2 otherwise. */
    private static function w63TextState(string $text): int
    {
        $length = \strlen($text);

        return $length === 0 ? 0 : ($length <= 255 && strcspn($text, self::W63_CONTROL_CHARS) === $length ? 1 : 2);
    }

    /**
     * Lowercase `[a-z0-9]+` tokens.
     *
     * @return list<string>
     */
    private static function w63Tokens(string $text): array
    {
        return preg_split('/[^a-z0-9]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * The distinct-token Jaccard percent (0 when both are empty).
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private static function w63Jaccard(array $a, array $b): int
    {
        $setA = array_flip($a);
        $setB = array_flip($b);
        $shared = \count(array_intersect_key($setA, $setB));
        $union = \count($setA) + \count($setB) - $shared;

        return $union === 0 ? 0 : intdiv(100 * $shared, $union);
    }

    /** 0 unless both years are set, then 1 equal, 2 one apart or 3 conflicting. */
    private static function w63YearCode(?int $a, ?int $b): int
    {
        return $a === null || $b === null ? 0 : ($a === $b ? 1 : (abs($a - $b) === 1 ? 2 : 3));
    }

    /**
     * (0 empty, 1 a trimmed positive 1-10 digit id, 2 invalid; the id).
     *
     * @return array{int, int}
     */
    private static function w63IdState(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [0, 0];
        }
        $value = self::w63Digits($text, 1, 10);

        return $value !== null && $value > 0 ? [1, $value] : [2, 0];
    }

    /**
     * Wave 63 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and returns the
     * previous override, 'reset' clears the override, library handle and buffers, 'ffi' resolves the library
     * (null when unavailable) and 'out' and 'lens' return `int64_t` output and length-column buffers of at least
     * $val words.
     */
    private static function w63RustFfiState(?string $op = null, mixed $val = null): mixed
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
        if ($op === 'reset') {
            $override = $ffi = $out = $lens = null;
            $outCap = $lensCap = 0;

            return null;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('tv_w633', self::W63_TRAKT_PROVIDER_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w63RustFfiState('ffi');
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
                $handle = self::w63RustFfiState('ffi');
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
     * Calls the Wave 63.3 TraktProvider export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call declines
     * or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w63Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w63RustFfiState('ffi');
            $out = self::w63RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w63RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W63_TRAKT_PROVIDER_FFI_CDEF.)
            $written = $ffi->nntmux_w633_trakt_provider_batch($blob, \strlen($blob), $lens, $count, $out, $words);
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
