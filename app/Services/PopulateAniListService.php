<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ImageAssetProfile;
use App\Models\AnidbInfo;
use App\Models\AnidbTitle;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use Throwable;

class PopulateAniListService
{
    /**
     * AniList GraphQL API endpoint
     */
    private const API_URL = 'https://graphql.anilist.co';

    /**
     * Rate limit: 20 requests per minute (conservative limit to avoid hitting AniList's 90/min limit)
     */
    private const RATE_LIMIT_PER_MINUTE = 20;

    public const NORMALIZER_FFI_CDEF = <<<'C'
        ssize_t nntmux_anilist_normalize_media(const char *input, size_t len, char *out, size_t cap);
        C;

    /** Null selects the benchmark-qualified production default. */
    public ?bool $useRustFfi = null;

    private static FFI|false|null $normalizerFfi = null;

    /**
     * Whether to echo message output.
     */
    public bool $echooutput;

    /**
     * The directory to store anime covers.
     */
    public string $imgSavePath;

    /**
     * HTTP client for API requests
     */
    protected Client $client;

    private ReleaseImageService $imageService;

    /**
     * Rate limiting: track requests and timestamps
     *
     * @var array<string, mixed>
     */
    private array $rateLimitQueue = [];

    /**
     * Track when we received a 429 error and need to pause.
     * Format: ['timestamp' => int, 'paused_until' => int]
     *
     * @var array<string, mixed>
     */
    private static ?array $rateLimitPause = null;

    /**
     * @throws \Exception
     */
    public function __construct(?ReleaseImageService $imageService = null)
    {
        $this->echooutput = config('nntmux.echocli');

        // Use storage_path directly to match CoverController expectations
        $this->imgSavePath = storage_path('covers/anime/');
        $this->imageService = $imageService ?? new ReleaseImageService;
        $this->client = new Client([
            'base_uri' => self::API_URL,
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * Main switch that initiates AniList table population.
     *
     * @throws \Exception
     */
    public function populateTable(string $type = '', int|string $anilistId = ''): void
    {
        switch ($type) {
            case 'info':
                $this->populateInfoTable((string) $anilistId);
                break;
        }
    }

    /**
     * Search for anime by title using AniList GraphQL API.
     *
     * @return array<string, mixed>|false
     *
     * @throws \Exception
     */
    public function searchAnime(string $title, int $limit = 10)
    {
        $query = '
            query ($search: String, $perPage: Int) {
                Page (perPage: $perPage) {
                    media (search: $search, type: ANIME) {
                        id
                        idMal
                        title {
                            romaji
                            english
                            native
                        }
                        type
                        format
                        status
                        countryOfOrigin
                        episodes
                        duration
                        source
                        popularity
                        favourites
                        startDate {
                            year
                            month
                            day
                        }
                        endDate {
                            year
                            month
                            day
                        }
                        description
                        averageScore
                        hashtag
                        coverImage {
                            large
                        }
                        genres
                        studios {
                            nodes {
                                name
                            }
                        }
                        characters {
                            nodes {
                                name {
                                    full
                                }
                            }
                        }
                        relations {
                            edges {
                                relationType
                                node {
                                    id
                                    title {
                                        romaji
                                        english
                                    }
                                }
                            }
                        }
                    }
                }
            }
        ';

        $variables = [
            'search' => $title,
            'perPage' => $limit,
        ];

        $response = $this->makeGraphQLRequest($query, $variables);

        if ($response === false || ! isset($response['data']['Page']['media'])) {
            return false;
        }

        return $response['data']['Page']['media'];
    }

    /**
     * Refresh AniList data for a specific anidbid.
     * If anilist_id exists in database, uses it. Otherwise returns false.
     *
     * @return bool True on success, false on failure
     *
     * @throws \Exception
     */
    public function refreshAnimeByAnidbid(int $anidbid): bool
    {
        // Get anilist_id from database
        $anidbInfo = AnidbInfo::query()->where('anidbid', $anidbid)->first();

        if (! $anidbInfo || ! $anidbInfo->anilist_id) {
            return false;
        }

        // Fetch fresh data from AniList
        $anilistData = $this->getAnimeById($anidbInfo->anilist_id);

        if ($anilistData === false) {
            return false;
        }

        // Update database with fresh data
        $this->insertAniListInfo($anidbid, $anilistData);

        return true;
    }

    /**
     * Get anime by AniList ID.
     *
     * @return array<string, mixed>|false
     *
     * @throws \Exception
     */
    public function getAnimeById(int $anilistId)
    {
        $query = '
            query ($id: Int) {
                Media (id: $id, type: ANIME) {
                    id
                    idMal
                    title {
                        romaji
                        english
                        native
                    }
                    type
                    format
                    status
                    countryOfOrigin
                    episodes
                    duration
                    source
                    popularity
                    favourites
                    startDate {
                        year
                        month
                        day
                    }
                    endDate {
                        year
                        month
                        day
                    }
                    description
                    averageScore
                    hashtag
                    coverImage {
                        large
                    }
                    genres
                    studios {
                        nodes {
                            name
                        }
                    }
                    characters {
                        nodes {
                            name {
                                full
                            }
                        }
                    }
                    relations {
                        edges {
                            relationType
                            node {
                                id
                                title {
                                    romaji
                                    english
                                }
                            }
                        }
                    }
                }
            }
        ';

        $variables = ['id' => $anilistId];

        $response = $this->makeGraphQLRequest($query, $variables);

        if ($response === false || ! isset($response['data']['Media'])) {
            return false;
        }

        return $response['data']['Media'];
    }

    /**
     * Make a GraphQL request to AniList API with rate limiting.
     *
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>|false
     *
     * @throws \Exception
     */
    private function makeGraphQLRequest(string $query, array $variables = [])
    {
        // Check if we're paused due to 429 error
        if (self::$rateLimitPause !== null) {
            $now = time();
            $pausedUntil = self::$rateLimitPause['paused_until'];

            if ($now < $pausedUntil) {
                $remainingSeconds = $pausedUntil - $now;
                if ($this->echooutput) {
                    cli()->warning("API calls paused due to 429 error. Resuming in {$remainingSeconds} seconds...");
                }

                // Throw exception to stop processing
                throw new \Exception('AniList API rate limit exceeded (429). Paused until '.date('Y-m-d H:i:s', $pausedUntil));
            } else {
                // Pause period expired, clear it
                self::$rateLimitPause = null;
            }
        }

        // Enforce rate limiting
        $this->enforceRateLimit();

        try {
            $response = $this->client->post('', [
                'json' => [
                    'query' => $query,
                    'variables' => $variables,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $body = json_decode($response->getBody()->getContents(), true);

            // Handle 429 Too Many Requests - pause for 15 minutes
            if ($statusCode === 429) {
                $pauseUntil = time() + (15 * 60); // 15 minutes from now
                self::$rateLimitPause = [
                    'timestamp' => time(),
                    'paused_until' => $pauseUntil,
                ];

                if ($this->echooutput) {
                    cli()->error('AniList API returned 429 (Too Many Requests). Pausing all API calls for 15 minutes.');
                }

                return false;
            }

            // Track rate limit from headers
            $remaining = (int) ($response->getHeader('X-RateLimit-Remaining')[0] ?? self::RATE_LIMIT_PER_MINUTE);
            $resetAt = (int) ($response->getHeader('X-RateLimit-Reset')[0] ?? time() + 60);

            // Record this request
            $this->rateLimitQueue[] = [
                'timestamp' => time(),
                'remaining' => $remaining,
                'resetAt' => $resetAt,
            ];

            // Clean old entries (older than 1 minute)
            $this->rateLimitQueue = array_filter($this->rateLimitQueue, function ($entry) {
                return $entry['timestamp'] > (time() - 60);
            });

            if ($statusCode === 200 && isset($body['data'])) {
                return $body;
            }

            if (isset($body['errors'])) {
                if ($this->echooutput) {
                    cli()->error('AniList API Error: '.json_encode($body['errors']));
                }

                return false;
            }

            return false;
        } catch (ClientException $e) {
            // Check if this is a 429 error from Guzzle
            $statusCode = $e->getResponse()->getStatusCode();
            if ($statusCode === 429) {
                $pauseUntil = time() + (15 * 60); // 15 minutes from now
                self::$rateLimitPause = [
                    'timestamp' => time(),
                    'paused_until' => $pauseUntil,
                ];

                if ($this->echooutput) {
                    cli()->error('AniList API returned 429 (Too Many Requests). Pausing all API calls for 15 minutes.');
                }

                throw new \Exception('AniList API rate limit exceeded (429). Paused until '.date('Y-m-d H:i:s', $pauseUntil));
            }

            if ($this->echooutput) {
                cli()->error('AniList API Request Failed: '.$e->getMessage());
            }

            return false;
        } catch (GuzzleException $e) {
            if ($this->echooutput) {
                cli()->error('AniList API Request Failed: '.$e->getMessage());
            }

            return false;
        }
    }

    /**
     * Enforce rate limiting: 90 requests per minute.
     *
     * @throws \Exception
     */
    private function enforceRateLimit(): void
    {
        // Count requests in the last minute
        $recentRequests = array_filter($this->rateLimitQueue, function ($entry) {
            return $entry['timestamp'] > (time() - 60);
        });

        $requestCount = count($recentRequests);

        // If we're approaching the limit, wait
        if ($requestCount >= (self::RATE_LIMIT_PER_MINUTE - 5)) {
            // Wait until the oldest request is more than 1 minute old
            if (! empty($this->rateLimitQueue)) {
                $oldestRequest = min(array_column($this->rateLimitQueue, 'timestamp'));
                $waitTime = 60 - (time() - $oldestRequest) + 1;

                if ($waitTime > 0 && $waitTime <= 60) {
                    if ($this->echooutput) {
                        cli()->warning("Rate limit approaching. Waiting {$waitTime} seconds...");
                    }
                    sleep($waitTime);
                }
            }
        }
    }

    /**
     * Insert or update AniList title data.
     */
    private function insertAniListTitle(int $anidbid, string $type, string $lang, string $title): void
    {
        $check = AnidbTitle::query()->where([
            'anidbid' => $anidbid,
            'type' => $type,
            'lang' => $lang,
            'title' => $title,
        ])->first();

        if ($check === null) {
            AnidbTitle::insertOrIgnore([
                'anidbid' => $anidbid,
                'type' => $type,
                'lang' => $lang,
                'title' => $title,
            ]);
        }
    }

    public static function normalizerFfi(): ?FFI
    {
        if (self::$normalizerFfi === null) {
            self::$normalizerFfi = RustPipelineLibrary::resolveDomain('metadata', self::NORMALIZER_FFI_CDEF) ?? false;
        }

        return self::$normalizerFfi === false ? null : self::$normalizerFfi;
    }

    /** `basename()` follows LC_CTYPE character tables outside the C locales. */
    public static function normalizerEnvironmentSupported(): bool
    {
        return \in_array(setlocale(LC_CTYPE, '0'), ['C', 'POSIX', 'C.UTF-8', 'C.utf8'], true);
    }

    /**
     * Normalize raw AniList GraphQL media payload into database-ready info and title records.
     *
     * @param  array<string, mixed>  $anilistData
     * @return array<string, mixed>
     */
    public function normalizeAniListMedia(array $anilistData): array
    {
        return ($this->useRustFfi ?? false)
            ? $this->normalizeAniListMediaNative($anilistData)
            : $this->normalizeAniListMediaPhp($anilistData);
    }

    /**
     * @param  array<string, mixed>  $anilistData
     * @return array<string, mixed>
     */
    public function normalizeAniListMediaNative(array $anilistData): array
    {
        if (! self::normalizerEnvironmentSupported() || ($ffi = self::normalizerFfi()) === null) {
            return $this->normalizeAniListMediaPhp($anilistData);
        }
        try {
            $input = serialize($anilistData);
            $len = \strlen($input);
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $len + 256, $capacity);
            if ($buffer === null) {
                return $this->normalizeAniListMediaPhp($anilistData);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_anilist_normalize_media($input, $len, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return $this->normalizeAniListMediaPhp($anilistData);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_anilist_normalize_media($input, $len, $buffer, $capacity);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return $this->normalizeAniListMediaPhp($anilistData);
            }
            $output = FFI::string($buffer, $written);
        } catch (Throwable) {
            return $this->normalizeAniListMediaPhp($anilistData);
        }
        $parts = explode("\0", $output);
        if (\count($parts) !== 13 || $parts[12] !== '') {
            return $this->normalizeAniListMediaPhp($anilistData);
        }

        $titles = [];
        if (isset($anilistData['title']['romaji']) && ! empty($anilistData['title']['romaji'])) {
            $titles[] = ['type' => 'main', 'lang' => 'x-jat', 'title' => $anilistData['title']['romaji']];
        }
        if (isset($anilistData['title']['english']) && ! empty($anilistData['title']['english'])) {
            $titles[] = ['type' => 'official', 'lang' => 'en', 'title' => $anilistData['title']['english']];
        }
        if (isset($anilistData['title']['native']) && ! empty($anilistData['title']['native'])) {
            $titles[] = ['type' => 'main', 'lang' => 'ja', 'title' => $anilistData['title']['native']];
        }

        return [
            'anilist_id' => $anilistData['id'] ?? null,
            'mal_id' => $anilistData['idMal'] ?? null,
            'country' => $anilistData['countryOfOrigin'] ?? null,
            'media_type' => $anilistData['type'] ?? null,
            'type' => $anilistData['format'] ?? null,
            'episodes' => isset($anilistData['episodes']) ? (int) $anilistData['episodes'] : null,
            'duration' => isset($anilistData['duration']) ? (int) $anilistData['duration'] : null,
            'status' => $anilistData['status'] ?? null,
            'source' => $anilistData['source'] ?? null,
            'hashtag' => $anilistData['hashtag'] ?? null,
            'startdate' => $parts[0] === '1' ? $parts[1] : null,
            'enddate' => $parts[2] === '1' ? $parts[3] : null,
            'description' => $parts[4],
            'rating' => isset($anilistData['averageScore']) ? (string) $anilistData['averageScore'] : null,
            'picture' => $parts[5] === '1' ? $parts[6] : null,
            'categories' => $parts[7],
            'characters' => $parts[8],
            'creators' => $parts[9],
            'related' => $parts[10],
            'similar' => $parts[11],
            'titles' => $titles,
        ];
    }

    /**
     * @param  array<string, mixed>  $anilistData
     * @return array<string, mixed>
     */
    public function normalizeAniListMediaPhp(array $anilistData): array
    {
        $startDate = null;
        if (isset($anilistData['startDate']['year'], $anilistData['startDate']['month'], $anilistData['startDate']['day'])) {
            $startDate = sprintf(
                '%04d-%02d-%02d',
                $anilistData['startDate']['year'],
                $anilistData['startDate']['month'] ?? 1,
                $anilistData['startDate']['day'] ?? 1
            );
        }

        $endDate = null;
        if (isset($anilistData['endDate']['year'], $anilistData['endDate']['month'], $anilistData['endDate']['day'])) {
            $endDate = sprintf(
                '%04d-%02d-%02d',
                $anilistData['endDate']['year'],
                $anilistData['endDate']['month'] ?? 1,
                $anilistData['endDate']['day'] ?? 1
            );
        }

        $description = $anilistData['description'] ?? '';
        // Remove HTML tags from description
        $description = strip_tags($description);
        $description = html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $rating = isset($anilistData['averageScore']) ? (string) $anilistData['averageScore'] : null;

        $picture = null;
        if (isset($anilistData['coverImage']['large'])) {
            $picture = basename(parse_url($anilistData['coverImage']['large'], PHP_URL_PATH));
        }

        $genres = '';
        if (isset($anilistData['genres']) && is_array($anilistData['genres'])) {
            $genres = implode(', ', $anilistData['genres']);
        }

        $creators = '';
        if (isset($anilistData['studios']['nodes']) && is_array($anilistData['studios']['nodes'])) {
            $studioNames = array_map(function ($studio) {
                return $studio['name'] ?? '';
            }, $anilistData['studios']['nodes']);
            $creators = implode(', ', array_filter($studioNames));
        }

        $characters = '';
        if (isset($anilistData['characters']['nodes']) && is_array($anilistData['characters']['nodes'])) {
            $characterNames = array_map(function ($character) {
                return $character['name']['full'] ?? '';
            }, array_slice($anilistData['characters']['nodes'], 0, 10)); // Limit to 10 characters
            $characters = implode(', ', array_filter($characterNames));
        }

        $related = '';
        $similar = '';
        if (isset($anilistData['relations']['edges']) && is_array($anilistData['relations']['edges'])) {
            $relatedItems = [];
            $similarItems = [];
            foreach ($anilistData['relations']['edges'] as $edge) {
                $relationType = $edge['relationType'] ?? '';
                $title = $edge['node']['title']['english'] ?? $edge['node']['title']['romaji'] ?? '';
                $id = $edge['node']['id'] ?? '';

                if (in_array($relationType, ['SEQUEL', 'PREQUEL', 'SIDE_STORY', 'PARENT', 'SPIN_OFF'], true)) {
                    $relatedItems[] = $title.' ('.$id.')';
                } elseif (in_array($relationType, ['ALTERNATIVE', 'CHARACTER'], true)) {
                    $similarItems[] = $title.' ('.$id.')';
                }
            }
            $related = implode(', ', array_slice($relatedItems, 0, 20));
            $similar = implode(', ', array_slice($similarItems, 0, 20));
        }

        $titles = [];
        if (isset($anilistData['title']['romaji']) && ! empty($anilistData['title']['romaji'])) {
            $titles[] = ['type' => 'main', 'lang' => 'x-jat', 'title' => $anilistData['title']['romaji']];
        }
        if (isset($anilistData['title']['english']) && ! empty($anilistData['title']['english'])) {
            $titles[] = ['type' => 'official', 'lang' => 'en', 'title' => $anilistData['title']['english']];
        }
        if (isset($anilistData['title']['native']) && ! empty($anilistData['title']['native'])) {
            $titles[] = ['type' => 'main', 'lang' => 'ja', 'title' => $anilistData['title']['native']];
        }

        return [
            'anilist_id' => $anilistData['id'] ?? null,
            'mal_id' => $anilistData['idMal'] ?? null,
            'country' => $anilistData['countryOfOrigin'] ?? null,
            'media_type' => $anilistData['type'] ?? null,
            'type' => $anilistData['format'] ?? null,
            'episodes' => isset($anilistData['episodes']) ? (int) $anilistData['episodes'] : null,
            'duration' => isset($anilistData['duration']) ? (int) $anilistData['duration'] : null,
            'status' => $anilistData['status'] ?? null,
            'source' => $anilistData['source'] ?? null,
            'hashtag' => $anilistData['hashtag'] ?? null,
            'startdate' => $startDate,
            'enddate' => $endDate,
            'description' => $description,
            'rating' => $rating,
            'picture' => $picture,
            'categories' => $genres,
            'characters' => $characters,
            'creators' => $creators,
            'related' => $related,
            'similar' => $similar,
            'titles' => $titles,
        ];
    }

    /**
     * Insert or update AniList info data.
     *
     * @param  array<string, mixed>  $anilistData
     */
    protected function insertAniListInfo(int $anidbid, array $anilistData): void
    {
        $normalized = $this->normalizeAniListMedia($anilistData);
        $startDate = $normalized['startdate'];
        $endDate = $normalized['enddate'];
        $description = $normalized['description'];
        $rating = $normalized['rating'];
        $picture = $normalized['picture'];
        $genres = $normalized['categories'];
        $characters = $normalized['characters'];
        $creators = $normalized['creators'];
        $related = $normalized['related'];
        $similar = $normalized['similar'];
        $anilistId = $normalized['anilist_id'];
        $malId = $normalized['mal_id'];
        $country = $normalized['country'];
        $mediaType = $normalized['media_type'];
        $episodes = $normalized['episodes'];
        $duration = $normalized['duration'];
        $status = $normalized['status'];
        $source = $normalized['source'];
        $hashtag = $normalized['hashtag'];

        // Use updateOrInsert to handle race conditions atomically
        // This prevents duplicate key errors when multiple processes try to insert the same record
        $check = AnidbInfo::query()->where('anidbid', $anidbid)->first();

        $data = [
            'anidbid' => $anidbid,
            'anilist_id' => $anilistId,
            'mal_id' => $malId,
            'country' => $country,
            'media_type' => $mediaType,
            'type' => $normalized['type'],
            'episodes' => $episodes,
            'duration' => $duration,
            'status' => $status,
            'source' => $source,
            'hashtag' => $hashtag,
            'startdate' => $startDate,
            'enddate' => $endDate,
            'description' => $description,
            'rating' => $rating,
            'picture' => $picture,
            'categories' => $genres,
            'characters' => $characters,
            'creators' => $creators,
            'related' => $related,
            'similar' => $similar,
            'updated' => now(),
        ];

        // If record exists, preserve existing values for null fields
        if ($check !== null) {
            $data['anilist_id'] = $anilistId ?? $check->anilist_id;
            $data['mal_id'] = $malId ?? $check->mal_id;
            $data['country'] = $country ?? $check->country;
            $data['media_type'] = $mediaType ?? $check->media_type;
            $data['type'] = $anilistData['format'] ?? $anilistData['type'] ?? $check->type;
            $data['episodes'] = $episodes ?? $check->episodes;
            $data['duration'] = $duration ?? $check->duration;
            $data['status'] = $status ?? $check->status;
            $data['source'] = $source ?? $check->source;
            $data['hashtag'] = $hashtag ?? $check->hashtag;
            $data['startdate'] = $startDate ?? $check->startdate;
            $data['enddate'] = $endDate ?? $check->enddate;
            $data['description'] = $description ?: $check->description;
            $data['rating'] = $rating ?? $check->rating;
            $data['picture'] = $picture ?? $check->picture;
            $data['categories'] = $genres ?: $check->categories;
            $data['characters'] = $characters ?: $check->characters;
            $data['creators'] = $creators ?: $check->creators;
            $data['related'] = $related ?: $check->related;
            $data['similar'] = $similar ?: $check->similar;
        }

        // Use updateOrInsert to atomically handle insert/update (prevents race conditions)
        AnidbInfo::query()->updateOrInsert(
            ['anidbid' => $anidbid],
            $data
        );

        // Insert titles
        foreach ($normalized['titles'] as $titleRecord) {
            $this->insertAniListTitle($anidbid, $titleRecord['type'], $titleRecord['lang'], $titleRecord['title']);
        }

        // Download cover image if available
        if (! empty($picture) && isset($anilistData['coverImage']['large'])) {
            $this->downloadCoverImage($anidbid, $anilistData['coverImage']['large']);
        }
    }

    /**
     * Download cover image from AniList.
     */
    private function downloadCoverImage(int $anidbid, string $imageUrl): void
    {
        $coverName = $anidbid.'-cover';
        if ($this->imageService->imageExists($this->imgSavePath, $coverName)) {
            return; // Already exists
        }

        try {
            $result = $this->imageService->saveRemoteImage(
                $coverName,
                $imageUrl,
                $this->imgSavePath,
                ImageAssetProfile::Original,
            );

            if (! $result->success) {
                throw new \RuntimeException($result->failureReason ?? 'Failed to process anime cover.');
            }

            if ($this->echooutput) {
                cli()->info("Downloaded cover image for ID {$anidbid} from AniList to {$result->path}");
            }
        } catch (GuzzleException $e) {
            if ($this->echooutput) {
                cli()->warning("Failed to download cover image for ID {$anidbid}: ".$e->getMessage());
            }
        } catch (\Exception $e) {
            if ($this->echooutput) {
                cli()->error("Error saving cover image for ID {$anidbid}: ".$e->getMessage());
            }
        }
    }

    /**
     * Directs flow for populating the AniList Info table.
     *
     * @throws \Exception
     */
    private function populateInfoTable(string $anilistId = ''): void
    {
        if (empty($anilistId)) {
            // Get titles that need updating (missing anilist_id or stale)
            $anidbIds = AnidbTitle::query()
                ->selectRaw('DISTINCT anidb_titles.anidbid')
                ->leftJoin('anidb_info as ai', 'ai.anidbid', '=', 'anidb_titles.anidbid')
                ->where(function ($query) {
                    $query->whereNull('ai.anilist_id')
                        ->orWhere('ai.updated', '<', now()->subWeek());
                })
                ->limit(100) // Process in batches
                ->get();

            foreach ($anidbIds as $anidb) {
                // Try to find by title first
                $title = AnidbTitle::query()
                    ->where('anidbid', $anidb->anidbid)
                    ->where('lang', 'en')
                    ->value('title');

                if ($title) {
                    $searchResults = $this->searchAnime($title, 1);
                    if ($searchResults) {
                        $anilistData = $searchResults[0]; // @phpstan-ignore offsetAccess.notFound
                        $this->insertAniListInfo($anidb->anidbid, $anilistData);
                        // Rate limiting is handled in makeGraphQLRequest
                    }
                }
            }
        } else {
            // Get specific anime by AniList ID
            $anilistData = $this->getAnimeById((int) $anilistId);

            if ($anilistData === false) {
                if ($this->echooutput) {
                    cli()->info("Anime with AniList ID: {$anilistId} not found.");
                }

                return;
            }

            // We need to find or create an anidbid for this
            // For now, use anilist_id as anidbid if not found
            $anidbid = AnidbInfo::query()
                ->where('anilist_id', $anilistId)
                ->value('anidbid');

            if (! $anidbid) {
                // Create a new entry using anilist_id as anidbid
                $anidbid = (int) $anilistId;
            }

            $this->insertAniListInfo($anidbid, $anilistData);
        }
    }

    /** Enabled: seven paired runs cleared 1.5x on every gate (min common mean 2.16x, p99 1.82x; uncommon mean 2.40x, p99 2.21x; see the W47 parity test). */
    public const bool W47_MEDIA_RUST_FFI_DEFAULT = true;

    public const string W47_FFI_CDEF = <<<'C'
        ssize_t nntmux_w47_anilist_media_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W47_MEDIA_KEYS = ['total_media', 'untitled_count', 'english_title_count', 'native_non_ascii_count', 'synonym_count', 'html_description_count', 'entity_description_count', 'scored_count', 'rank_score_sum', 'long_title_count', 'sanitized_title_count', 'max_rank_score'];

    private static ?bool $w47UseRustFfi = null;

    private static FFI|false|null $w47Ffi = null;

    /** Null restores the benchmark-qualified default. */
    public static function useW47MediaRustFfi(?bool $enable = true): void
    {
        self::$w47UseRustFfi = $enable;
    }

    public static function usesW47MediaRustFfi(): bool
    {
        return self::$w47UseRustFfi ?? self::W47_MEDIA_RUST_FFI_DEFAULT;
    }

    public static function resetW47MediaRustFfi(): void
    {
        self::$w47UseRustFfi = null;
        self::$w47Ffi = null;
    }

    /**
     * Summarize AniList GraphQL media payloads. Titles and synonyms are
     * sanitized by collapsing runs of bytes 0x00-0x20 and 0x7f into one
     * space and trimming spaces. Counts untitled rows, English titles,
     * non-ASCII native titles, synonyms that are non-empty and differ
     * (ASCII case-insensitively) from the romaji and English titles,
     * descriptions with HTML tags or entities, valid 0-100 averageScore
     * values, titles over 100 bytes and romaji titles sanitizing changed.
     * Each row's rank is 4 * averageScore + min(episodes, 100) + 10 for an
     * English title + 2 * min(synonyms, 5); the sum and maximum are kept.
     *
     * @param  array<array-key, mixed>  $media
     * @return array<string, int>
     */
    public static function summarizeMediaBatch(array $media): array
    {
        return self::usesW47MediaRustFfi()
            ? self::summarizeMediaBatchNative($media)
            : self::summarizeMediaBatchPhp($media);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $media
     * @return array<string, int>
     */
    public static function summarizeMediaBatchNative(array $media): array
    {
        $fields = self::w47Fields($media);

        return self::w47Call($fields) ?? self::w47Summary($fields);
    }

    /**
     * @param  array<array-key, mixed>  $media
     * @return array<string, int>
     */
    public static function summarizeMediaBatchPhp(array $media): array
    {
        return self::w47Summary(self::w47Fields($media));
    }

    /**
     * Only string and integer values participate; objects are never cast or serialized.
     *
     * @param  array<array-key, mixed>  $media
     * @return list<int|string>
     */
    private static function w47Fields(array $media): array
    {
        $fields = [];
        foreach ($media as $row) {
            if (! is_array($row)) {
                $fields[] = is_string($row) ? $row : (is_int($row) ? (string) $row : '');
                array_push($fields, '', '', '', '', '', '');

                continue;
            }
            $title = $row['title'] ?? null;
            if (is_array($title)) {
                $v = $title['romaji'] ?? null;
                $fields[] = is_string($v) ? $v : (is_int($v) ? (string) $v : '');
                $v = $title['english'] ?? null;
                $fields[] = is_string($v) ? $v : (is_int($v) ? (string) $v : '');
                $v = $title['native'] ?? null;
                $fields[] = is_string($v) ? $v : (is_int($v) ? (string) $v : '');
            } else {
                array_push($fields, '', '', '');
            }
            $v = $row['synonyms'] ?? null;
            if (is_array($v)) {
                $v = implode("\x1e", array_filter($v, is_string(...)));
            }
            $fields[] = is_string($v) ? $v : (is_int($v) ? (string) $v : '');
            $v = $row['description'] ?? null;
            $fields[] = is_string($v) ? $v : (is_int($v) ? (string) $v : '');
            // Integers stay integers: serialize() writes their decimal text, which Rust reads as the string.
            $v = $row['averageScore'] ?? null;
            $fields[] = is_string($v) || is_int($v) ? $v : '';
            $v = $row['episodes'] ?? null;
            $fields[] = is_string($v) || is_int($v) ? $v : '';
        }

        return $fields;
    }

    private static function w47Sanitize(string $title): string
    {
        return trim((string) preg_replace('/[\x00-\x20\x7f]+/', ' ', $title), ' ');
    }

    /**
     * @param  list<int|string>  $fields
     * @return array<string, int>
     */
    private static function w47Summary(array $fields): array
    {
        $summary = array_fill_keys(self::W47_MEDIA_KEYS, 0);
        for ($i = 0, $n = count($fields); $i < $n; $i += 7) {
            $summary['total_media']++;
            $romaji = self::w47Sanitize((string) $fields[$i]);
            $english = self::w47Sanitize((string) $fields[$i + 1]);
            $native = self::w47Sanitize((string) $fields[$i + 2]);
            $summary['untitled_count'] += (int) ($romaji === '' && $english === '' && $native === '');
            $summary['english_title_count'] += (int) ($english !== '');
            $summary['native_non_ascii_count'] += (int) (preg_match('/[\x80-\xff]/', $native) === 1);
            $lowerRomaji = strtolower($romaji);
            $lowerEnglish = strtolower($english);
            $synonyms = 0;
            foreach (explode("\x1e", (string) $fields[$i + 3]) as $synonym) {
                $synonym = strtolower(self::w47Sanitize($synonym));
                $synonyms += (int) ($synonym !== '' && $synonym !== $lowerRomaji && $synonym !== $lowerEnglish);
            }
            $summary['synonym_count'] += $synonyms;
            $description = (string) $fields[$i + 4];
            $summary['html_description_count'] += (int) (preg_match('#<[A-Za-z/!]#', $description) === 1);
            $summary['entity_description_count'] += (int) (preg_match('/&(?:[A-Za-z]+|#[0-9]+|#[xX][0-9A-Fa-f]+);/', $description) === 1);
            // Integers behave as their decimal text: 1-3 digits is 0..999, 1-4 digits 0..9999.
            $score = $fields[$i + 5];
            $score = is_int($score)
                ? ($score >= 0 && $score <= 100 ? $score : -1)
                : (strlen($score) <= 3 && ctype_digit($score) && (int) $score <= 100 ? (int) $score : -1);
            $summary['scored_count'] += (int) ($score >= 0);
            $episodes = $fields[$i + 6];
            $episodes = is_int($episodes)
                ? ($episodes >= 0 && $episodes <= 9999 ? $episodes : 0)
                : (strlen($episodes) <= 4 && ctype_digit($episodes) ? (int) $episodes : 0);
            $rank = 4 * max(0, $score)
                + min(100, $episodes)
                + ($english !== '' ? 10 : 0)
                + 2 * min(5, $synonyms);
            $summary['rank_score_sum'] += $rank;
            $summary['long_title_count'] += (int) (strlen($romaji) > 100 || strlen($english) > 100 || strlen($native) > 100);
            $summary['sanitized_title_count'] += (int) ($romaji !== $fields[$i]);
            $summary['max_rank_score'] = max($summary['max_rank_score'], $rank);
        }

        return $summary;
    }

    /**
     * @param  list<int|string>  $fields
     * @return array<string, int>|null
     */
    private static function w47Call(array $fields): ?array
    {
        try {
            $input = serialize($fields);
            if (strlen($input) > 16777216) {
                return null;
            }
            self::$w47Ffi ??= RustPipelineLibrary::resolveDomain('metadata_w47', self::W47_FFI_CDEF) ?? false;
            $ffi = self::$w47Ffi;
            if ($ffi === false) {
                return null;
            }
            // obtainScratch() returns at least the 96 bytes requested.
            $out = RustPipelineLibrary::obtainScratch($ffi, 96);
            // @phpstan-ignore method.notFound (Declared by W47_FFI_CDEF.)
            if ($out === null || $ffi->nntmux_w47_anilist_media_batch($input, strlen($input), $out, 96) !== 96) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('P*', FFI::string($out, 96));
            if ($values === false || count($values) !== 12 || count($fields) !== $values[1] * 7) {
                return null;
            }

            return array_combine(self::W47_MEDIA_KEYS, $values);
        } catch (Throwable) {
            return null;
        }
    }

    // ========================================================================
    // WAVE 61.4 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of four paired runs reached 1.75x common mean, 1.60x common p99, 1.63x uncommon mean and 1.66x uncommon p99; see RustUpdateLockPasswordBreachAniListRecaptchaRegistrationLogW61ParityTest. */
    public const bool W61_RUST_FFI_DEFAULT = true;

    private const string W61_ANILIST_FFI_CDEF = 'ssize_t nntmux_w614_anilist_population_plan_row_batch(const char *blob, size_t blob_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);';

    /** int64 words per aniListPopulationPlanRowBatch() record. */
    public const int W61_ROW_WORDS = 7;

    /** int64 summary words after the aniListPopulationPlanRowBatch() records (none for an empty batch). */
    public const int W61_TRAILER_WORDS = 8;

    private const int W61_ID_MAX = 2_147_483_647;

    private const array W61_STATUSES = ['FINISHED' => 1, 'RELEASING' => 2, 'NOT_YET_RELEASED' => 3, 'CANCELLED' => 4, 'HIATUS' => 5];

    private const array W61_FORMATS = ['TV' => 1, 'TV_SHORT' => 2, 'MOVIE' => 3, 'SPECIAL' => 4, 'OVA' => 5, 'ONA' => 6, 'MUSIC' => 7];

    private const string W61_CONTROL = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x7f";

    /**
     * AniList population plan rows for [romaji title, english title, native title, status,
     * format, anilist id, mal id, episodes, season year] items. Titles are trimmed; status and
     * format are trimmed and uppercased (status 1 FINISHED, 2 RELEASING, 3 NOT_YET_RELEASED, 4
     * CANCELLED, 5 HIATUS; format 1 TV, 2 TV_SHORT, 3 MOVIE, 4 SPECIAL, 5 OVA, 6 ONA, 7 MUSIC; 0
     * empty, 15 other). The anilist id must be 1..2^31-1 (else -1), the mal id 0 (none) or
     * 1..2^31-1, episodes 0..10000 and the season year 0 (none) or 1940..2100 (else -1). The
     * primary title is english, then romaji, then native (source 1-3, 0 none).
     *
     * Status: 1 invalid anilist id, 2 untitled, 3 a title is not UTF-8, 4 needs review (unknown
     * status or format, or an invalid mal id, episode count or year), 0 ready. Completeness
     * scores 20 id + 15 romaji + 15 english + 10 native + 10 mal + 10 episodes + 10 year + 5
     * status + 5 format. Rows [status | source << 4 | media status << 8 | format << 12 | flags
     * << 16 (1 romaji, 2 english, 4 native, 8 native non-ASCII, 16 english equals romaji
     * case-insensitively, 32 control byte, 64 not UTF-8, 128 movie with several episodes),
     * anilist id, mal id, episodes, season year, primary title bytes, completeness], then the
     * trailer [ready, invalid id, untitled, bad encoding, review, completeness sum, max primary
     * bytes, FNV-1a digest].
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: int, 6: int, 7: int, 8: int}>  $items
     * @return list<int>
     */
    public static function aniListPopulationPlanRowBatch(array $items): array
    {
        return self::usesW61RustFfi() ? self::aniListPopulationPlanRowBatchNative($items) : self::aniListPopulationPlanRowBatchPhp($items);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: int, 6: int, 7: int, 8: int}>  $items
     * @return list<int>
     */
    public static function aniListPopulationPlanRowBatchPhp(array $items): array
    {
        self::w61Items($items);
        if ($items === []) {
            return [];
        }
        $out = [];
        $summary = [0, 0, 0, 0, 0, 0, 0, 0];
        $digest = 2_166_136_261;
        foreach ($items as [$romaji, $english, $native, $mediaStatus, $format, $anilist, $mal, $episodes, $year]) {
            $romaji = trim($romaji);
            $english = trim($english);
            $native = trim($native);
            $mediaStatus = strtoupper(trim($mediaStatus));
            $statusCode = $mediaStatus === '' ? 0 : (self::W61_STATUSES[$mediaStatus] ?? 15);
            $format = strtoupper(trim($format));
            $formatCode = $format === '' ? 0 : (self::W61_FORMATS[$format] ?? 15);
            $anilist = $anilist >= 1 && $anilist <= self::W61_ID_MAX ? $anilist : -1;
            $mal = $mal === 0 || ($mal >= 1 && $mal <= self::W61_ID_MAX) ? $mal : -1;
            $episodes = $episodes >= 0 && $episodes <= 10_000 ? $episodes : -1;
            $year = $year === 0 || ($year >= 1940 && $year <= 2100) ? $year : -1;
            $r = \strlen($romaji);
            $e = \strlen($english);
            $n = \strlen($native);
            $badUtf8 = ! mb_check_encoding($romaji, 'UTF-8') || ! mb_check_encoding($english, 'UTF-8') || ! mb_check_encoding($native, 'UTF-8');
            $flags = ($r > 0 ? 1 : 0) | ($e > 0 ? 2 : 0) | ($n > 0 ? 4 : 0) | (mb_check_encoding($native, 'ASCII') ? 0 : 8)
                | ($e > 0 && strcasecmp($english, $romaji) === 0 ? 16 : 0)
                | (strcspn($romaji, self::W61_CONTROL) !== $r || strcspn($english, self::W61_CONTROL) !== $e || strcspn($native, self::W61_CONTROL) !== $n ? 32 : 0)
                | ($badUtf8 ? 64 : 0) | ($formatCode === 3 && $episodes > 1 ? 128 : 0);
            [$source, $primary] = $e > 0 ? [1, $e] : ($r > 0 ? [2, $r] : ($n > 0 ? [3, $n] : [0, 0]));
            $status = match (true) {
                $anilist === -1 => 1,
                $source === 0 => 2,
                $badUtf8 => 3,
                $statusCode === 15 || $formatCode === 15 || $mal === -1 || $episodes === -1 || $year === -1 => 4,
                default => 0,
            };
            $score = ($anilist > 0 ? 20 : 0) + ($r > 0 ? 15 : 0) + ($e > 0 ? 15 : 0) + ($n > 0 ? 10 : 0) + ($mal > 0 ? 10 : 0)
                + ($episodes > 0 ? 10 : 0) + ($year > 0 ? 10 : 0) + ($statusCode >= 1 && $statusCode <= 5 ? 5 : 0)
                + ($formatCode >= 1 && $formatCode <= 7 ? 5 : 0);
            $word = $status | $source << 4 | $statusCode << 8 | $formatCode << 12 | $flags << 16;
            array_push($out, $word, $anilist, $mal, $episodes, $year, $primary, $score);
            $summary[$status]++;
            $summary[5] += $score;
            $summary[6] = max($summary[6], $primary);
            $value = $word ^ $anilist << 24 ^ $score;
            $digest = (($digest ^ (($value ^ ($value >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
        }
        $summary[7] = $digest;

        return [...$out, ...$summary];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: int, 6: int, 7: int, 8: int}>  $items
     * @return list<int>
     */
    public static function aniListPopulationPlanRowBatchNative(array $items): array
    {
        self::w61Items($items);
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        [$blob, $twin] = self::w61Join([array_column($items, 0), array_column($items, 1), array_column($items, 2), array_column($items, 3), array_column($items, 4)], $count);
        $ints = pack('P*', ...array_column($items, 5), ...array_column($items, 6), ...array_column($items, 7), ...array_column($items, 8));

        return self::w61Call($blob, $twin, $ints, $count, $count * self::W61_ROW_WORDS + self::W61_TRAILER_WORDS)
            ?? self::aniListPopulationPlanRowBatchPhp($items);
    }

    /** @param array<array-key, mixed> $items */
    private static function w61Items(array $items): void
    {
        if (! array_is_list($items)) {
            throw new InvalidArgumentException('W61 AniList population plan batch items must be a list.');
        }
        foreach ($items as $item) {
            if (! \is_array($item) || \count($item) !== 9 || ! array_is_list($item) || ! \is_string($item[0]) || ! \is_string($item[1]) || ! \is_string($item[2]) || ! \is_string($item[3]) || ! \is_string($item[4]) || ! \is_int($item[5]) || ! \is_int($item[6]) || ! \is_int($item[7]) || ! \is_int($item[8])) {
                throw new InvalidArgumentException('W61 AniList population plan batch items must be [string romaji, string english, string native, string status, string format, int anilist id, int mal id, int episodes, int season year] lists.');
            }
        }
    }

    public static function usesW61RustFfi(): bool
    {
        $enabled = self::w61RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W61_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W61 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW61RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w61RustFfiState('enabled');
        self::w61RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w61RustFfiState('enabled', $previous);
        }
    }

    /**
     * String columns NUL-joined field-major and, when a value holds a NUL, the same columns
     * joined by 0x01 so Rust finds the real separators.
     *
     * @param  list<list<string>>  $columns
     * @return array{0: string, 1: string}
     */
    private static function w61Join(array $columns, int $count): array
    {
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = implode("\0", $column);
        }
        $joined = implode("\0", $parts);
        if (substr_count($joined, "\0") === \count($columns) * $count - 1) {
            return [$joined, ''];
        }
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = implode("\x01", $column);
        }

        return [$joined, implode("\x01", $parts)];
    }

    /**
     * One FFI crossing for the batch: $need int64 words back. Null when the library is missing
     * or declines a malformed frame, so the caller falls back to PHP; a declined call with a
     * resolved library is counted in the 'misses' state.
     *
     * @return list<int>|null
     */
    private static function w61Call(string $blob, string $twin, string $ints, int $count, int $need): ?array
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        $state = self::w61RustFfiState();
        $ffi = $state['ffi'];
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata_w61', self::W61_ANILIST_FFI_CDEF) ?? false;
            self::w61RustFfiState('ffi', $ffi);
        }
        if (! $ffi instanceof FFI) {
            return null;
        }
        try {
            $out = $state['out'];
            $cap = $state['cap'];
            if (! $out instanceof CData || $cap < $need) {
                $cap = max(4096, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w61RustFfiState('out', $out);
                self::w61RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W61_ANILIST_FFI_CDEF.)
            $written = $ffi->nntmux_w614_anilist_population_plan_row_batch($blob, \strlen($blob), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $cap);
            $values = $written === $need ? unpack('P*', FFI::string($out, $need * 8)) : false;
            if ($values !== false) {
                return array_values($values);
            }
        } catch (Throwable) {
        }
        self::w61RustFfiState('misses', $state['misses'] + 1);

        return null;
    }

    /**
     * W61 routing override, cached binding, reusable output buffer and declined-call count, kept
     * in a function static: no arguments returns the state, one argument reads a key ('reset'
     * clears everything) and two arguments write a key.
     */
    private static function w61RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;

            return null;
        }

        return $state[$op] ?? null;
    }
}
