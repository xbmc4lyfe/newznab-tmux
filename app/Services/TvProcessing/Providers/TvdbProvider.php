<?php

declare(strict_types=1);

namespace App\Services\TvProcessing\Providers;

use App\Exceptions\TvProviderException;
use App\Support\MetadataSources;
use App\Support\RustPipelineLibrary;
use CanIHaveSomeCoffee\TheTVDbAPI\Exception\ResourceNotFoundException;
use CanIHaveSomeCoffee\TheTVDbAPI\Exception\UnauthorizedException;
use CanIHaveSomeCoffee\TheTVDbAPI\Model\SeriesExtendedRecord;
use CanIHaveSomeCoffee\TheTVDbAPI\TheTVDbAPI;
use FFI;
use FFI\CData;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Class TvdbProvider -- functions used to post process releases against TVDB (API v4).
 */
class TvdbProvider extends AbstractTvProvider
{
    /** Disabled until correctness and the common-path and p99 benchmark gates qualify. */
    public const bool W394_RUST_FFI_DEFAULT = false;

    public const string W394_FFI_CDEF = <<<'C'
        ssize_t nntmux_w394_tvdb_series_translations(const char *input, size_t len, char *out, size_t cap);
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
            $written = $ffi->nntmux_w394_tvdb_series_translations($input, strlen($input), $out, $capacity);
            if (is_int($written) && $written < -1 && $written >= -16777216) {
                $out = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($out === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound (Declared by W394_FFI_CDEF.)
                $written = $ffi->nntmux_w394_tvdb_series_translations($input, strlen($input), $out, $capacity);
            }
            if (! is_int($written) || $written <= 0 || $written > $capacity || $written > 16777216) {
                return null;
            }
            $result = @unserialize(FFI::string($out, $written), ['allowed_classes' => false]);
            $types = ['total_entries' => 'int', 'preferred_language_index' => 'int', 'english_language_index' => 'int', 'non_empty_overview_count' => 'int', 'alias_count' => 'int'];
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

    /** @param array<array-key, mixed> $translations
     * @return array<string, int|string|bool>
     */
    public static function summarizeTvdbSeriesTranslations(array $translations, string $preferredLang = 'eng'): array
    {
        if (self::usesW394RustFfi()) {
            $safe = [];
            foreach ($translations as $row) {
                $row = is_array($row) ? $row : [];
                $aliases = [];
                foreach (is_array($row['aliases'] ?? null) ? $row['aliases'] : [] as $alias) {
                    if (is_string($alias)) {
                        $aliases[] = $alias;
                    }
                }
                $safe[] = [
                    'language' => is_string($row['language'] ?? null) ? $row['language'] : null,
                    'overview' => is_string($row['overview'] ?? null) ? $row['overview'] : null,
                    'aliases' => $aliases,
                ];
            }
            $native = self::w394Call([$safe, $preferredLang]);
            if ($native !== null) {
                return $native;
            }
        }

        return self::summarizeTvdbSeriesTranslationsPhp($translations, $preferredLang);
    }

    /** @param array<array-key, mixed> $translations
     * @return array<string, int>
     */
    public static function summarizeTvdbSeriesTranslationsPhp(array $translations, string $preferredLang = 'eng'): array
    {
        $preferredLang = strtolower(trim($preferredLang));
        $preferred = $english = -1;
        $overviewCount = $aliasCount = $position = 0;
        foreach ($translations as $row) {
            $row = is_array($row) ? $row : [];
            $language = is_string($row['language'] ?? null) ? strtolower(trim($row['language'])) : '';
            if ($preferred === -1 && $language === $preferredLang) {
                $preferred = $position;
            }
            if ($english === -1 && $language === 'eng') {
                $english = $position;
            }
            $overviewCount += (int) (is_string($row['overview'] ?? null) && trim($row['overview']) !== '');
            foreach (is_array($row['aliases'] ?? null) ? $row['aliases'] : [] as $alias) {
                $aliasCount += (int) (is_string($alias) && trim($alias) !== '');
            }
            $position++;
        }

        return ['total_entries' => count($translations), 'preferred_language_index' => $preferred, 'english_language_index' => $english, 'non_empty_overview_count' => $overviewCount, 'alias_count' => $aliasCount];
    }

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean 3.77x, p99 3.61x; uncommon mean 2.79x, p99 2.49x); see RustTvCandidatePipelineTvMazeTvdbHydrationW50ParityTest. */
    private const W50_RUST_FFI_DEFAULT = true;

    public const string W50_FFI_CDEF = <<<'C'
        ssize_t nntmux_w503_tvdb_episode_match_batch(const char *blob, size_t blob_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
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
     * Formats and matches a batch of TVDB episode records as fetchAllEpisodes()/fetchEpisode()
     * do. Six values per record: valid (checkRequiredAttr('tvdbE'): seasonNumber and number
     * neither null nor ''), the series and episode (`(int)` casts), the FNV-1a 32 hash of
     * se_complete, the untrimmed aired date as `YYYYMMDD` (a valid `YYYY-MM-DD` with a non-zero
     * year, else 0) and flags (1 a valid record matching $season and $episode, 2 non-blank name).
     * Then the valid count, the first matching index (-1 when none), the latest aired date and
     * the undated count among valid records.
     *
     * @param  list<array<string, mixed>>  $records  seasonNumber, number, aired, name
     * @return list<int>
     */
    public static function w50EpisodeMatchBatch(array $records, int $season, int $episode): array
    {
        return self::usesW50RustFfi()
            ? self::w50EpisodeMatchBatchNative($records, $season, $episode)
            : self::w50EpisodeMatchBatchPhp($records, $season, $episode);
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return list<int>
     */
    public static function w50EpisodeMatchBatchNative(array $records, int $season, int $episode): array
    {
        $count = \count($records);
        $columns = self::w50Columns($records, ['seasonNumber' => null, 'number' => null, 'aired' => '', 'name' => '']);
        $seasons = $columns['seasonNumber'];
        $numbers = $columns['number'];
        $ints = pack('q*', $season, $episode);
        foreach ([array_keys($seasons, null, true), array_keys($seasons, '', true), array_keys($numbers, null, true), array_keys($numbers, '', true)] as $mask) {
            $ints .= pack('q*', \count($mask), ...$mask);
        }
        $ints .= pack('q*', ...$seasons).pack('q*', ...$numbers);
        $blob = implode("\0", $columns['aired'])."\0".implode("\0", $columns['name']);

        return self::w50Invoke($count === 0 ? '' : $blob, $ints, $count, $count * 6 + 4)
            ?? self::w50EpisodeMatchBatchPhp($records, $season, $episode);
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return list<int>
     */
    public static function w50EpisodeMatchBatchPhp(array $records, int $season, int $episode): array
    {
        $out = [];
        $valid = $latest = $undated = 0;
        $first = -1;
        $index = 0;
        foreach ($records as $record) {
            $seasonNumber = $record['seasonNumber'] ?? null;
            $number = $record['number'] ?? null;
            $isValid = $seasonNumber !== null && $seasonNumber !== '' && $number !== null && $number !== '';
            $series = (int) $seasonNumber;
            $episodeNumber = (int) $number;
            $aired = 0;
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', (string) ($record['aired'] ?? ''), $m) === 1
                && (int) $m[1] > 0 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                $aired = (int) $m[1] * 10000 + (int) $m[2] * 100 + (int) $m[3];
            }
            $matched = $isValid && $series === $season && $episodeNumber === $episode;
            if ($isValid) {
                $valid++;
                $latest = max($latest, $aired);
                $undated += $aired === 0 ? 1 : 0;
                if ($matched && $first < 0) {
                    $first = $index;
                }
            }
            array_push(
                $out,
                $isValid ? 1 : 0,
                $series,
                $episodeNumber,
                (int) hexdec(hash('fnv1a32', 'S'.sprintf('%02d', $series).'E'.sprintf('%02d', $episodeNumber))),
                $aired,
                ($matched ? 1 : 0) | (trim((string) ($record['name'] ?? '')) !== '' ? 2 : 0),
            );
            $index++;
        }
        array_push($out, $valid, $first, $latest, $undated);

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
            $written = $ffi->nntmux_w503_tvdb_episode_match_batch($blob, \strlen($blob), $ints, \strlen($ints), $count, self::$w50Out, self::$w50OutCap);
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
            $imdbId = self::w56TvdbImdbId((string) ($extended->getIMDBId() ?? ''));
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

    // ---------------------------------------------------------------------
    // Wave 56.4 batch helper
    // ---------------------------------------------------------------------

    /** Disabled: three paired runs missed the 1.5x uncommon gates (min common mean 2.21x, p99 2.17x; uncommon mean 1.15x, p99 1.20x); see RustTmdbTvMazeTvdbPar2AccumulatorCompactFpW56ParityTest. */
    public const bool W56_RUST_FFI_DEFAULT = false;

    public const string W56_FFI_CDEF = <<<'C'
        ssize_t nntmux_w564_tvdb_show_row_batch(const char *text, size_t text_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /**
     * TVDB show rows as formatShowInfo(), formatEpisodeInfo() and alias matching see them. Each
     * record yields five words: flags (valid aired date 1, trimmed case-insensitive title
     * match 2, alias match 4, tt\d{6,} IMDB id 8, numbered episode 16), the IMDB digits span
     * (offset << 32 | length, or -1), the aired Y-m-d prefix as YYYYMMDD (month 1-12 and day
     * 1-31, else -1), the first alias whose trimmed value matches the name case-insensitively
     * (-1) and season * 1000000 + episode for season 0-9999 and episode 1-999999 (-1).
     *
     * @param  list<string>  $imdbIds
     * @param  list<string>  $aired
     * @param  list<string>  $titles
     * @param  list<string>  $ours
     * @param  list<list<string>>  $aliases
     * @param  list<int>  $seasons
     * @param  list<int>  $episodes
     * @return list<int>
     */
    public static function tvdbShowRowBatch(array $imdbIds, array $aired, array $titles, array $ours, array $aliases, array $seasons, array $episodes): array
    {
        return self::usesW56RustFfi()
            ? self::tvdbShowRowBatchNative($imdbIds, $aired, $titles, $ours, $aliases, $seasons, $episodes)
            : self::tvdbShowRowBatchPhp($imdbIds, $aired, $titles, $ours, $aliases, $seasons, $episodes);
    }

    /**
     * Aliases travel US-joined (0x1f); a record whose aliases hold that byte sends none and
     * has its alias word and flag computed in PHP. Any NUL byte falls back to PHP.
     *
     * @param  list<string>  $imdbIds
     * @param  list<string>  $aired
     * @param  list<string>  $titles
     * @param  list<string>  $ours
     * @param  list<list<string>>  $aliases
     * @param  list<int>  $seasons
     * @param  list<int>  $episodes
     * @return list<int>
     */
    public static function tvdbShowRowBatchNative(array $imdbIds, array $aired, array $titles, array $ours, array $aliases, array $seasons, array $episodes): array
    {
        self::w56TvdbColumns($imdbIds, $aired, $titles, $ours, $aliases, $seasons, $episodes);
        $count = \count($imdbIds);
        if ($count === 0) {
            return [];
        }
        $joined = [];
        $ints = [];
        $patch = [];
        foreach ($aliases as $i => $list) {
            $alias = implode("\x1f", $list);
            if ($list !== [] && substr_count($alias, "\x1f") !== \count($list) - 1) {
                $patch[] = $i;
                $alias = '';
            }
            $joined[] = $alias;
            $ints[] = $seasons[$i];
            $ints[] = $episodes[$i];
        }
        $text = implode("\0", $imdbIds)."\0".implode("\0", $aired)."\0".implode("\0", $titles)
            ."\0".implode("\0", $ours)."\0".implode("\0", $joined);
        $out = self::w56TvdbCall($text, pack('P*', ...$ints), $count, 5);
        if ($out === null) {
            return self::tvdbShowRowBatchPhp($imdbIds, $aired, $titles, $ours, $aliases, $seasons, $episodes);
        }
        foreach ($patch as $i) {
            $alias = self::w56TvdbAliasIndex($aliases[$i], trim($ours[$i]));
            $out[$i * 5 + 3] = $alias;
            $out[$i * 5] |= $alias >= 0 ? 4 : 0;
        }

        return $out;
    }

    /**
     * @param  list<string>  $imdbIds
     * @param  list<string>  $aired
     * @param  list<string>  $titles
     * @param  list<string>  $ours
     * @param  list<list<string>>  $aliases
     * @param  list<int>  $seasons
     * @param  list<int>  $episodes
     * @return list<int>
     */
    public static function tvdbShowRowBatchPhp(array $imdbIds, array $aired, array $titles, array $ours, array $aliases, array $seasons, array $episodes): array
    {
        self::w56TvdbColumns($imdbIds, $aired, $titles, $ours, $aliases, $seasons, $episodes);
        $out = [];
        foreach ($imdbIds as $i => $imdb) {
            $name = trim($ours[$i]);
            $lower = strtolower($name);
            $span = self::w56TvdbImdbSpan($imdb);
            $date = -1;
            if (preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})/', $aired[$i], $match) === 1) {
                [$year, $month, $day] = [(int) $match[1], (int) $match[2], (int) $match[3]];
                $date = $month >= 1 && $month <= 12 && $day >= 1 && $day <= 31 ? $year * 10000 + $month * 100 + $day : -1;
            }
            $alias = self::w56TvdbAliasIndex($aliases[$i], $name);
            [$season, $episode] = [$seasons[$i], $episodes[$i]];
            $key = $season >= 0 && $season <= 9999 && $episode >= 1 && $episode <= 999999 ? $season * 1000000 + $episode : -1;
            $flags = ($date >= 0 ? 1 : 0)
                | ($name !== '' && strtolower(trim($titles[$i])) === $lower ? 2 : 0)
                | ($alias >= 0 ? 4 : 0)
                | ($span >= 0 ? 8 : 0)
                | ($key >= 0 ? 16 : 0);
            array_push($out, $flags, $span, $date, $alias, $key);
        }

        return $out;
    }

    /**
     * The first alias whose trimmed value matches the trimmed name case-insensitively; -1 for
     * none or a blank name.
     *
     * @param  list<string>  $aliases
     */
    private static function w56TvdbAliasIndex(array $aliases, string $name): int
    {
        if ($name !== '') {
            $lower = strtolower($name);
            foreach ($aliases as $k => $value) {
                if (strtolower(trim($value)) === $lower) {
                    return $k;
                }
            }
        }

        return -1;
    }

    /** formatShowInfo()'s preg_match('/tt(?P<imdbid>\d{6,})$/i') capture, through the W56 toggle. */
    private static function w56TvdbImdbId(string $raw): string
    {
        $span = self::usesW56RustFfi()
            ? self::tvdbShowRowBatchNative([$raw], [''], [''], [''], [[]], [0], [0])[1]
            : self::w56TvdbImdbSpan($raw);

        return $span < 0 ? '' : substr($raw, $span >> 32, $span & 0xFFFFFFFF);
    }

    private static function w56TvdbImdbSpan(string $value): int
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
    private static function w56TvdbColumns(array ...$columns): void
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
    private static function w56TvdbCall(string $text, string $ints, int $count, int $words): ?array
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
            $written = $ffi->nntmux_w564_tvdb_show_row_batch($text, \strlen($text), $ints, \strlen($ints), $count, $out, $cap);
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
