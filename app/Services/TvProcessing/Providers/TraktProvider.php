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
        ssize_t nntmux_w49_tv_trakt_ids_batch(const char *text, size_t text_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
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
            $written = $ffi->nntmux_w49_tv_trakt_ids_batch($text, \strlen($text), $ints, \strlen($ints), $count, $out, self::$w49OutCap);
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
}
