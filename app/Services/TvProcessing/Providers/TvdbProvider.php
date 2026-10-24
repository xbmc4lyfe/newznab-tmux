<?php

declare(strict_types=1);

namespace App\Services\TvProcessing\Providers;

use App\Exceptions\TvProviderException;
use App\Support\MetadataSources;
use CanIHaveSomeCoffee\TheTVDbAPI\Exception\ResourceNotFoundException;
use CanIHaveSomeCoffee\TheTVDbAPI\Exception\UnauthorizedException;
use CanIHaveSomeCoffee\TheTVDbAPI\Model\SeriesExtendedRecord;
use CanIHaveSomeCoffee\TheTVDbAPI\TheTVDbAPI;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

/**
 * Class TvdbProvider -- functions used to post process releases against TVDB (API v4).
 */
class TvdbProvider extends AbstractTvProvider
{
    public TheTVDbAPI $client;

    /**
     * @var string Authorization token for the TVDB API
     */
    public string $token = '';

    /** True when TVDB is unconfigured or rejected our credentials. */
    private bool $local = false;

    private bool $authorized = false;

    /**
     * Candidate selection through Rust; null selects the benchmark-qualified production default
     * (PHP: the batch call misses the 1.5x gate, see tests/Unit/RustTvProvidersTraktMatchingParityTest.php).
     */
    public ?bool $useRustMatchFfi = null;

    /**
     * One client per process: the library's RouteFactory caches its route objects
     * statically, so every route keeps using the first TheTVDbAPI instance it saw.
     */
    private static ?TheTVDbAPI $sharedClient = null;

    public function __construct(?TheTVDbAPI $client = null)
    {
        parent::__construct();
        $this->client = $client ?? self::$sharedClient ??= new TheTVDbAPI('eng', '', new Client([
            'base_uri' => TheTVDbAPI::API_BASE_URI,
            'timeout' => (int) config('tvdb.timeout', 30),
            'connect_timeout' => 10,
            'headers' => ['accept' => 'application/json'],
        ]));
        $this->local = ! MetadataSources::isAvailable(MetadataSources::TVDB);
    }

    /**
     * @return array<string, mixed>|false
     */
    public function getShowInfo(string $name, ?int $year = null, string $country = ''): array|false
    {
        $name = (string) preg_replace('# \((19|20)\d{2}\)$#', '', $name);

        $response = $year !== null
            ? $this->call(fn (TheTVDbAPI $client) => $client->search()->search($name, ['type' => 'series', 'year' => $year]))
            : null;
        if (empty($response)) {
            $response = $this->call(fn (TheTVDbAPI $client) => $client->search()->search($name, ['type' => 'series']));
        }

        if (! \is_array($response)) {
            return false;
        }

        $highest = ($this->useRustMatchFfi ?? false)
            ? $this->selectBestShowNative($response, $name, $year, $country)
            : $this->selectBestShowPhp($response, $name, $year, $country);

        return $highest !== null ? $this->formatShowInfo($highest) : false;
    }

    /**
     * The first search result whose name or an alias scores strictly highest above zero.
     *
     * @param  array<int|string, mixed>  $response
     */
    public function selectBestShowPhp(array $response, string $name, ?int $year, string $country): mixed
    {
        $highestScore = 0.0;
        $highest = null;
        foreach ($response as $show) {
            if (! $this->checkRequiredAttr($show, 'tvdbS')) {
                continue;
            }

            $names = [(string) $show->name];
            foreach (\is_array($show->aliases ?? null) ? $show->aliases : [] as $alias) {
                if (\is_string($alias) && $alias !== '') {
                    $names[] = $alias;
                }
            }

            foreach ($names as $candidateName) {
                $score = $this->scoreCandidate($name, $year, $candidateName, $show->first_air_time ?? ($show->year ?? null), $country, (string) ($show->country ?? ''));
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
     * @param  array<int|string, mixed>  $response
     */
    public function selectBestShowNative(array $response, string $name, ?int $year, string $country): mixed
    {
        $titles = $starteds = $countries = $owners = [];
        foreach ($response as $key => $show) {
            if (! $this->checkRequiredAttr($show, 'tvdbS')) {
                continue;
            }

            $started = TmdbProvider::encodeCandidateStarted($show->first_air_time ?? ($show->year ?? null));
            $candidateCountry = (string) ($show->country ?? '');
            $titles[] = (string) $show->name;
            $starteds[] = $started;
            $countries[] = $candidateCountry;
            $owners[] = $key;
            foreach (\is_array($show->aliases ?? null) ? $show->aliases : [] as $alias) {
                if (\is_string($alias) && $alias !== '') {
                    $titles[] = $alias;
                    $starteds[] = $started;
                    $countries[] = $candidateCountry;
                    $owners[] = $key;
                }
            }
        }

        if ($titles === []) {
            return null;
        }

        $index = TmdbProvider::bestCandidateIndexNative($name, $year, $country, $titles, $starteds, $countries);
        if ($index === null) {
            return $this->selectBestShowPhp($response, $name, $year, $country);
        }

        return $index >= 0 ? $response[$owners[$index]] : null;
    }

    public function fetchAllEpisodes(int $siteId, int $videoId): int
    {
        if ($siteId <= 0 || $videoId <= 0) {
            return 0;
        }

        $episodes = $this->call(fn (TheTVDbAPI $client) => $client->series()->allEpisodes($siteId));
        if (! \is_array($episodes)) {
            return 0;
        }

        $formatted = [];
        foreach ($episodes as $episode) {
            if ($this->checkRequiredAttr($episode, 'tvdbE')) {
                $formatted[] = $this->formatEpisodeInfo($episode);
            }
        }

        return $this->addEpisodes($videoId, $formatted);
    }

    public function supportsAirdateLookup(): bool
    {
        return true;
    }

    public function fetchEpisodesByAirdate(int $siteId, int $videoId, string $airdate): int
    {
        $date = \DateTime::createFromFormat('!Y-m-d', $airdate);
        if ($siteId <= 0 || $videoId <= 0 || $date === false) {
            return 0;
        }

        $episodes = $this->call(fn (TheTVDbAPI $client) => $client->series()->episodes($siteId, 0, 0, 'default', '', -1, $date));
        if (! \is_array($episodes)) {
            return 0;
        }

        $formatted = [];
        foreach ($episodes as $episode) {
            if ($this->checkRequiredAttr($episode, 'tvdbE')) {
                $formatted[] = $this->formatEpisodeInfo($episode);
            }
        }

        return $this->addEpisodes($videoId, $formatted);
    }

    /**
     * @return array<string, mixed>|false
     */
    public function fetchEpisode(int $siteId, int $season, int $episode, int $videoId = 0): array|false
    {
        if ($siteId <= 0 || $season < 0 || $episode <= 0) {
            return false;
        }

        $episodes = $this->call(fn (TheTVDbAPI $client) => $client->series()->episodes($siteId, $season, 0, 'default', '', $episode));
        foreach (\is_array($episodes) ? $episodes : [] as $record) {
            if ($this->checkRequiredAttr($record, 'tvdbE') && (int) $record->seasonNumber === $season && (int) $record->number === $episode) {
                return $this->formatEpisodeInfo($record);
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function formatShowInfo(mixed $show): array
    {
        $this->posterUrl = '';
        $tvdbId = (int) $show->tvdb_id;

        $artworks = $this->callQuietly(fn (TheTVDbAPI $client) => $client->series()->artworks($tvdbId));
        $poster = collect(\is_array($artworks) ? $artworks : [])->where('type', 2)->sortByDesc('score')->first();
        $this->posterUrl = ! empty($poster->image) ? (string) $poster->image : '';

        $imdbId = '';
        $extended = $this->callQuietly(fn (TheTVDbAPI $client) => $client->series()->extended($tvdbId));
        if (\is_object($extended)) {
            preg_match('/tt(?P<imdbid>\d{6,})$/i', (string) ($extended->getIMDBId() ?? ''), $imdb);
            $imdbId = (string) ($imdb['imdbid'] ?? '');
        }

        $externalIds = $this->lookupExternalIds($tvdbId, $imdbId);

        return [
            'type' => parent::TYPE_TV,
            'title' => (string) $show->name,
            'summary' => (string) ($show->overview ?? ''),
            'started' => (string) ($show->first_air_time ?? ''),
            'publisher' => \is_object($extended) ? (string) ($extended->originalNetwork->name ?? '') : '',
            'country' => (string) ($show->country ?? ''),
            'poster' => $this->posterUrl,
            'source' => parent::SOURCE_TVDB,
            'imdb' => $imdbId,
            'tvdb' => $tvdbId,
            'trakt' => $externalIds['trakt'],
            'tvrage' => 0,
            'tvmaze' => 0,
            'tmdb' => $externalIds['tmdb'],
            'aliases' => ! empty($show->aliases) ? $show->aliases : '',
            'localzone' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatEpisodeInfo(mixed $episode): array
    {
        return [
            'title' => (string) ($episode->name ?? ''),
            'series' => (int) $episode->seasonNumber,
            'episode' => (int) $episode->number,
            'se_complete' => 'S'.sprintf('%02d', $episode->seasonNumber).'E'.sprintf('%02d', $episode->number),
            'firstaired' => (string) ($episode->aired ?? ''),
            'summary' => (string) ($episode->overview ?? ''),
        ];
    }

    /**
     * The library client, logged in; null when TVDB is unconfigured or rejected the credentials.
     */
    public function authorizedClient(): ?TheTVDbAPI
    {
        return $this->authorize() ? $this->client : null;
    }

    /**
     * The extended record of a series, or null when TVDB has none.
     */
    public function seriesExtended(int $tvdbId): ?SeriesExtendedRecord
    {
        return $this->call(fn (TheTVDbAPI $client) => $client->series()->extended($tvdbId));
    }

    /**
     * Run a TVDB request: null when TVDB has nothing (404) or is unusable here.
     *
     * Re-authorises once on 401. Rate limits, server errors and connection
     * failures surface as TvProviderException so the release is retried later.
     *
     * @template T
     *
     * @param  callable(TheTVDbAPI): T  $request
     * @return T|null
     */
    private function call(callable $request): mixed
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            if (! $this->authorize($attempt > 0)) {
                return null;
            }

            try {
                return $request($this->client);
            } catch (ResourceNotFoundException) {
                return null;
            } catch (UnauthorizedException) {
                $this->authorized = false;

                continue;
            } catch (GuzzleException $e) {
                throw new TvProviderException('tvdb', 'TVDB request failed: '.$e->getMessage(), null, null, $e);
            } catch (\Exception $e) {
                if (preg_match('/Got status code (\d{3})/', $e->getMessage(), $status) === 1
                    && TvProviderException::isTransientStatus((int) $status[1])) {
                    throw new TvProviderException('tvdb', $e->getMessage(), (int) $status[1], null, $e);
                }

                Log::warning('TVDB request failed', ['error' => $e->getMessage()]);

                return null;
            }
        }

        return null;
    }

    /**
     * Like call(), for optional enrichment: transient failures are logged instead of thrown.
     *
     * @template T
     *
     * @param  callable(TheTVDbAPI): T  $request
     * @return T|null
     */
    private function callQuietly(callable $request): mixed
    {
        try {
            return $this->call($request);
        } catch (TvProviderException $e) {
            Log::info('TVDB enrichment request failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Log in on first use, or again when $force is set after a 401.
     */
    private function authorize(bool $force = false): bool
    {
        if ($this->local) {
            return false;
        }

        if ($this->authorized && ! $force) {
            return true;
        }

        $pin = config('tvdb.user_pin');

        try {
            $this->token = $this->client->authentication()->login(
                (string) config('tvdb.api_key'),
                \is_string($pin) && $pin !== '' ? $pin : null,
            );
        } catch (UnauthorizedException|ResourceNotFoundException $e) {
            Log::warning('TVDB rejected the configured credentials; TVDB lookups are disabled for this run.', ['error' => $e->getMessage()]);
            $this->local = true;

            return false;
        } catch (GuzzleException $e) {
            throw new TvProviderException('tvdb', 'TVDB login failed: '.$e->getMessage(), null, null, $e);
        }

        $this->client->setToken($this->token);
        $this->authorized = true;

        return true;
    }
}
