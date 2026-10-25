<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Facades\Elasticsearch;
use App\Services\Search\Support\ElasticsearchResponseHelper;
use App\Support\PredbSearchDocument;
use Elastic\Elasticsearch\Client;
use Illuminate\Console\Command;

class NntmuxCreateESIndexes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nntmux:create-es-indexes {--create-missing : Create absent indexes without replacing existing indexes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create Elasticsearch indexes (releases, predb, movies, tvshows, and metadata indexes)';

    /**
     * Rust stays opt-in: the FFI round trip is slower than the PHP helper (< 1.5x); see
     * tests/Unit/RustSearchIndexOffsetWorkerCommandParityTest.php.
     */
    public const bool RUST_FFI_DEFAULT = false;

    /** @var list<string> Logical indexes in createIndexes() order. */
    private const array SEARCH_INDEXES = ['releases', 'predb', 'movies', 'tvshows', 'music', 'books', 'games', 'console', 'steam', 'anime'];

    private const string INVALID_INDEX_BYTES = '\\/*?"<>| ,#:';

    private const int INDEX_MAX_BYTES = 255;

    private static ?bool $useRustFfi = null;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $this->createIndexes();

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Elasticsearch index creation failed. Existing indexes are preserved when --create-missing is used.');

            return self::FAILURE;
        }
    }

    private function createIndexes(): void
    {
        /** @var Client $client */
        $client = app('elasticsearch');

        $names = config('search.drivers.elasticsearch.indexes', []);
        $releasesIndex = (string) ($names['releases'] ?? 'releases');
        $predbIndex = (string) ($names['predb'] ?? 'predb');

        $defaultSettings = [
            'number_of_shards' => 2,
            'number_of_replicas' => 0,
            'analysis' => [
                'analyzer' => [
                    'release_analyzer' => [
                        'type' => 'custom',
                        'tokenizer' => 'standard',
                        'filter' => ['lowercase', 'asciifolding'],
                    ],
                    'metadata_analyzer' => [
                        'type' => 'custom',
                        'tokenizer' => 'standard',
                        'filter' => ['lowercase', 'asciifolding'],
                    ],
                ],
            ],
        ];

        $this->recreateIndex($client, $releasesIndex, [
            'settings' => $defaultSettings,
            'mappings' => [
                'properties' => [
                    'id' => [
                        'type' => 'long',
                        'index' => false,
                    ],
                    'name' => [
                        'type' => 'text',
                        'analyzer' => 'release_analyzer',
                    ],
                    'searchname' => [
                        'type' => 'text',
                        'analyzer' => 'release_analyzer',
                        'fields' => [
                            'keyword' => [
                                'type' => 'keyword',
                                'ignore_above' => 256,
                            ],
                            'sort' => [
                                'type' => 'keyword',
                            ],
                        ],
                    ],
                    'plainsearchname' => [
                        'type' => 'text',
                        'analyzer' => 'release_analyzer',
                        'fields' => [
                            'keyword' => [
                                'type' => 'keyword',
                                'ignore_above' => 256,
                            ],
                            'sort' => [
                                'type' => 'keyword',
                            ],
                        ],
                    ],
                    'categories_id' => [
                        'type' => 'integer',
                    ],
                    'fromname' => [
                        'type' => 'text',
                        'analyzer' => 'release_analyzer',
                    ],
                    'filename' => [
                        'type' => 'text',
                        'analyzer' => 'release_analyzer',
                    ],
                    'imdbid' => [
                        'type' => 'keyword',
                        'ignore_above' => 32,
                    ],
                    'add_date' => [
                        'type' => 'date',
                        'format' => 'yyyy-MM-dd HH:mm:ss',
                    ],
                    'post_date' => [
                        'type' => 'date',
                        'format' => 'yyyy-MM-dd HH:mm:ss',
                    ],
                    'postdate_ts' => ['type' => 'long'],
                    'adddate_ts' => ['type' => 'long'],
                    'size' => ['type' => 'long'],
                    'totalpart' => ['type' => 'integer'],
                    'grabs' => ['type' => 'integer'],
                    'passwordstatus' => ['type' => 'integer'],
                    'groups_id' => ['type' => 'integer'],
                    'nzbstatus' => ['type' => 'integer'],
                    'haspreview' => ['type' => 'integer'],
                    'tmdbid' => ['type' => 'integer'],
                    'traktid' => ['type' => 'integer'],
                    'tvdb' => ['type' => 'integer'],
                    'tvmaze' => ['type' => 'integer'],
                    'tvrage' => ['type' => 'integer'],
                    'videos_id' => ['type' => 'integer'],
                    'movieinfo_id' => ['type' => 'integer'],
                    'media_movie_name' => ['type' => 'text', 'analyzer' => 'metadata_analyzer'],
                    'media_file_name' => ['type' => 'text', 'analyzer' => 'metadata_analyzer'],
                    'media_unique_id' => ['type' => 'keyword', 'ignore_above' => 256],
                    'media_container_format' => ['type' => 'text', 'analyzer' => 'metadata_analyzer'],
                    'media_video_format' => ['type' => 'text', 'analyzer' => 'metadata_analyzer'],
                    'media_video_codec' => ['type' => 'text', 'analyzer' => 'metadata_analyzer'],
                    'media_video_width' => ['type' => 'integer'],
                    'media_video_height' => ['type' => 'integer'],
                    'media_audio_format' => ['type' => 'text', 'analyzer' => 'metadata_analyzer'],
                    'media_audio_channels' => ['type' => 'text', 'analyzer' => 'metadata_analyzer'],
                    'media_audio_language' => ['type' => 'text', 'analyzer' => 'metadata_analyzer'],
                    'media_subtitle_language' => ['type' => 'text', 'analyzer' => 'metadata_analyzer'],
                    'has_media_info' => ['type' => 'integer'],
                ],
            ],
        ]);

        $this->recreateIndex($client, $predbIndex, [
            'settings' => [
                'number_of_shards' => 2,
                'number_of_replicas' => 0,
            ],
            'mappings' => [
                'properties' => [
                    'id' => [
                        'type' => 'long',
                        'index' => false,
                    ],
                    'title' => [
                        'type' => 'text',
                        'fields' => [
                            'sort' => [
                                'type' => 'keyword',
                            ],
                        ],
                    ],
                    'filename' => ['type' => 'text'],
                    'source' => ['type' => 'text'],
                    ...PredbSearchDocument::elasticsearchExactMappings(),
                ],
            ],
        ]);

        $metaSettings = $defaultSettings;

        $moviesIndex = (string) ($names['movies'] ?? 'movies');
        $this->recreateIndex($client, $moviesIndex, [
            'settings' => $metaSettings,
            'mappings' => [
                'properties' => [
                    'id' => ['type' => 'long', 'index' => false],
                    'imdbid' => ['type' => 'keyword', 'ignore_above' => 32],
                    'tmdbid' => ['type' => 'long'],
                    'traktid' => ['type' => 'long'],
                    'title' => ['type' => 'text', 'analyzer' => 'metadata_analyzer'],
                    'year' => ['type' => 'keyword', 'ignore_above' => 16],
                    'genre' => ['type' => 'text', 'analyzer' => 'metadata_analyzer'],
                    'actors' => ['type' => 'text', 'analyzer' => 'metadata_analyzer'],
                    'director' => ['type' => 'text', 'analyzer' => 'metadata_analyzer'],
                    'rating' => ['type' => 'keyword', 'ignore_above' => 32],
                    'plot' => ['type' => 'text', 'analyzer' => 'metadata_analyzer'],
                ],
            ],
        ]);

        $tvshowsIndex = (string) ($names['tvshows'] ?? 'tvshows');
        $this->recreateIndex($client, $tvshowsIndex, [
            'settings' => $metaSettings,
            'mappings' => [
                'properties' => [
                    'id' => ['type' => 'long', 'index' => false],
                    'title' => ['type' => 'text', 'analyzer' => 'metadata_analyzer'],
                    'tvdb' => ['type' => 'integer'],
                    'trakt' => ['type' => 'integer'],
                    'tvmaze' => ['type' => 'integer'],
                    'tvrage' => ['type' => 'integer'],
                    'imdb' => ['type' => 'keyword', 'ignore_above' => 32],
                    'tmdb' => ['type' => 'integer'],
                    'started' => ['type' => 'keyword', 'ignore_above' => 32],
                    'type' => ['type' => 'integer'],
                ],
            ],
        ]);

        $musicIndex = (string) ($names['music'] ?? 'music');
        $this->recreateIndex($client, $musicIndex, [
            'settings' => $metaSettings,
            'mappings' => [
                'properties' => [
                    'title' => ['type' => 'text', 'analyzer' => 'metadata_analyzer'],
                    'artist' => ['type' => 'text', 'analyzer' => 'metadata_analyzer'],
                    'year' => ['type' => 'keyword', 'ignore_above' => 16],
                    'genres_id' => ['type' => 'integer'],
                    'cover' => ['type' => 'integer'],
                ],
            ],
        ]);

        $booksIndex = (string) ($names['books'] ?? 'books');
        $this->recreateIndex($client, $booksIndex, [
            'settings' => $metaSettings,
            'mappings' => [
                'properties' => [
                    'title' => ['type' => 'text', 'analyzer' => 'metadata_analyzer'],
                    'author' => ['type' => 'text', 'analyzer' => 'metadata_analyzer'],
                    'publishdate' => ['type' => 'keyword', 'ignore_above' => 32],
                    'cover' => ['type' => 'integer'],
                ],
            ],
        ]);

        $gamesIndex = (string) ($names['games'] ?? 'games');
        $this->recreateIndex($client, $gamesIndex, [
            'settings' => $metaSettings,
            'mappings' => [
                'properties' => [
                    'title' => ['type' => 'text', 'analyzer' => 'metadata_analyzer'],
                    'genres_id' => ['type' => 'integer'],
                    'releasedate_ts' => ['type' => 'long'],
                    'cover' => ['type' => 'integer'],
                ],
            ],
        ]);

        $consoleIndex = (string) ($names['console'] ?? 'console');
        $this->recreateIndex($client, $consoleIndex, [
            'settings' => $metaSettings,
            'mappings' => [
                'properties' => [
                    'title' => ['type' => 'text', 'analyzer' => 'metadata_analyzer'],
                    'platform' => ['type' => 'keyword', 'ignore_above' => 128],
                    'genres_id' => ['type' => 'integer'],
                    'releasedate_ts' => ['type' => 'long'],
                    'cover' => ['type' => 'integer'],
                ],
            ],
        ]);

        $steamIndex = (string) ($names['steam'] ?? 'steam');
        $this->recreateIndex($client, $steamIndex, [
            'settings' => $metaSettings,
            'mappings' => [
                'properties' => [
                    'name' => ['type' => 'text', 'analyzer' => 'metadata_analyzer'],
                    'appid' => ['type' => 'integer'],
                ],
            ],
        ]);

        $animeIndex = (string) ($names['anime'] ?? 'anime');
        $this->recreateIndex($client, $animeIndex, [
            'settings' => $metaSettings,
            'mappings' => [
                'properties' => [
                    'title' => ['type' => 'text', 'analyzer' => 'metadata_analyzer'],
                    'anidbid' => ['type' => 'integer'],
                    'anilist_id' => ['type' => 'integer'],
                    'mal_id' => ['type' => 'integer'],
                    'lang' => ['type' => 'keyword', 'ignore_above' => 16],
                    'title_type' => ['type' => 'keyword', 'ignore_above' => 32],
                    'media_type' => ['type' => 'keyword', 'ignore_above' => 32],
                    'status' => ['type' => 'keyword', 'ignore_above' => 32],
                ],
            ],
        ]);

        $this->info('All Elasticsearch indexes are created (or recreated).');
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function recreateIndex(Client $client, string $indexName, array $body): void
    {
        if (ElasticsearchResponseHelper::boolResponse($client, fn (Client $elasticClient) => $elasticClient->indices()->exists(['index' => $indexName]))) {
            if ($this->option('create-missing')) {
                $this->info("Index {$indexName} already exists; preserved.");

                return;
            }
            Elasticsearch::indices()->delete(['index' => $indexName]);
        }

        Elasticsearch::indices()->create([
            'index' => $indexName,
            'body' => $body,
        ]);

        $this->info("Index {$indexName} created successfully.");
    }

    public static function useRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = null;
    }

    /**
     * Plans index creation for the configured Elasticsearch names (a null or missing entry falls back
     * to the logical name, as createIndexes() does). Names must follow Elasticsearch rules (non-empty,
     * at most 255 bytes, no uppercase, control or `\/*?"<>| ,#:` bytes, not `.`/`..`, no leading
     * `-`, `_` or `+`); a valid name already planned by an earlier logical index is a duplicate.
     * Existing indexes are preserved with `--create-missing` and recreated otherwise.
     *
     * @param  array<string, ?string>  $configured
     * @param  list<string>  $existing
     * @return array{indexes: list<array{logical: string, name: string, valid: bool, exists: bool, action: string}>, create: int, recreate: int, preserve: int, invalid: int, duplicate: int}
     */
    public static function planIndexCreation(array $configured, array $existing, bool $createMissing): array
    {
        return self::usesRustFfi()
            ? self::planIndexCreationNative($configured, $existing, $createMissing)
            : self::planIndexCreationPhp($configured, $existing, $createMissing);
    }

    /**
     * @param  array<string, ?string>  $configured
     * @param  list<string>  $existing
     * @return array{indexes: list<array{logical: string, name: string, valid: bool, exists: bool, action: string}>, create: int, recreate: int, preserve: int, invalid: int, duplicate: int}
     */
    private static function planIndexCreationNative(array $configured, array $existing, bool $createMissing): array
    {
        $parts = [$createMissing ? '1' : '0'];
        foreach (self::SEARCH_INDEXES as $logical) {
            $name = $configured[$logical] ?? null;
            $parts[] = $name === null ? '0' : '1';
            $parts[] = $name ?? '';
        }
        $fields = NntmuxPopulateSearchIndexes::evaluateNative(1, [...$parts, ...$existing]);
        if ($fields === null || \count($fields) !== 5 + 4 * \count(self::SEARCH_INDEXES)) {
            return self::planIndexCreationPhp($configured, $existing, $createMissing);
        }

        $indexes = [];
        foreach (self::SEARCH_INDEXES as $i => $logical) {
            $row = 5 + 4 * $i;
            $indexes[] = [
                'logical' => $logical,
                'name' => $fields[$row],
                'valid' => $fields[$row + 1] === '1',
                'exists' => $fields[$row + 2] === '1',
                'action' => $fields[$row + 3],
            ];
        }

        return [
            'indexes' => $indexes,
            'create' => (int) $fields[0],
            'recreate' => (int) $fields[1],
            'preserve' => (int) $fields[2],
            'invalid' => (int) $fields[3],
            'duplicate' => (int) $fields[4],
        ];
    }

    /**
     * @param  array<string, ?string>  $configured
     * @param  list<string>  $existing
     * @return array{indexes: list<array{logical: string, name: string, valid: bool, exists: bool, action: string}>, create: int, recreate: int, preserve: int, invalid: int, duplicate: int}
     */
    private static function planIndexCreationPhp(array $configured, array $existing, bool $createMissing): array
    {
        $counts = ['create' => 0, 'recreate' => 0, 'preserve' => 0, 'invalid' => 0, 'duplicate' => 0];
        $indexes = [];
        $seen = [];
        foreach (self::SEARCH_INDEXES as $logical) {
            $name = $configured[$logical] ?? $logical;
            $valid = self::isValidIndexName($name);
            $exists = \in_array($name, $existing, true);
            $action = match (true) {
                ! $valid => 'invalid',
                isset($seen[$name]) => 'duplicate',
                $exists => $createMissing ? 'preserve' : 'recreate',
                default => 'create',
            };
            if ($valid) {
                $seen[$name] = true;
            }
            $counts[$action]++;
            $indexes[] = ['logical' => $logical, 'name' => $name, 'valid' => $valid, 'exists' => $exists, 'action' => $action];
        }

        return ['indexes' => $indexes, ...$counts];
    }

    private static function isValidIndexName(string $name): bool
    {
        $length = \strlen($name);

        return $length > 0
            && $length <= self::INDEX_MAX_BYTES
            && $name !== '.'
            && $name !== '..'
            && ! \in_array($name[0], ['-', '_', '+'], true)
            && strcspn($name, self::INVALID_INDEX_BYTES) === $length
            && preg_match('/[A-Z\x00-\x1F\x7F]/', $name) !== 1;
    }
}
