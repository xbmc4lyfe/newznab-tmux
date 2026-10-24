<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Search\Support\ElasticsearchClientFactory;
use App\Services\Search\Support\ElasticsearchResponseHelper;
use App\Services\Search\Support\ManticoreClientFactory;
use App\Support\RustPipelineLibrary;
use Elastic\Elasticsearch\Client as ElasticsearchClient;
use FFI;
use Illuminate\Console\Command;
use Manticoresearch\Client as ManticoreClient;
use Manticoresearch\Exceptions\ResponseException;
use Throwable;

class CreateMediaIndexes extends Command
{
    public const string FFI_CDEF = <<<'CDEF'
    int32_t nntmux_media_index_schema(uint32_t kind, char *out, size_t cap);
    int32_t nntmux_media_driver_kind(const char *driver, size_t driver_len);
    CDEF;

    public const int DRIVER_MANTICORE = 0;

    public const int DRIVER_ELASTICSEARCH = 1;

    public const int DRIVER_UNSUPPORTED = 2;

    private const int SCHEMA_MANTICORE = 0;

    private const int SCHEMA_ELASTICSEARCH = 1;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustApiV1ManticoreMediaIndexCommandsParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nntmux:create-media-indexes
                                        {--drop : Drop existing indexes before creating}
                                        {--populate : Populate indexes after creation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create movies and tvshows search indexes for the active search driver';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $driver = config('search.default', 'manticore');
        $this->info("Creating media search indexes for driver: {$driver}");

        $driverKind = self::mediaDriver($driver);
        if ($driverKind === self::DRIVER_MANTICORE) {
            return $this->createManticoreIndexes();
        } elseif ($driverKind === self::DRIVER_ELASTICSEARCH) {
            return $this->createElasticsearchIndexes();
        } else {
            $this->error("Unsupported search driver: {$driver}");

            return Command::FAILURE;
        }
    }

    /**
     * Create Manticore indexes for movies and tvshows.
     */
    private function createManticoreIndexes(): int
    {
        $client = ManticoreClientFactory::make(config('search.drivers.manticore', []));

        // Test connection
        try {
            $client->nodes()->status();
            $this->info('Connected to ManticoreSearch successfully.');
        } catch (\Exception $e) {
            $this->error('Failed to connect to ManticoreSearch: '.$e->getMessage());

            return Command::FAILURE;
        }

        $dropExisting = $this->option('drop');

        $indexes = self::manticoreMediaSchemas();

        $hasErrors = false;

        foreach ($indexes as $indexName => $schema) {
            if (! $this->createManticoreIndex($client, $indexName, $schema, $dropExisting)) {
                $hasErrors = true;
            }
        }

        if ($hasErrors) {
            $this->error('Some errors occurred during index creation.');

            return Command::FAILURE;
        }

        $this->info('Media indexes created successfully!');

        // Optionally populate the indexes
        if ($this->option('populate')) {
            $this->info('Populating indexes...');
            $this->call('nntmux:populate', [
                '--manticore' => true,
                '--movies' => true,
            ]);
            $this->call('nntmux:populate', [
                '--manticore' => true,
                '--tvshows' => true,
            ]);
        }

        return Command::SUCCESS;
    }

    /**
     * Create a single Manticore index.
     *
     * @param  array<string, mixed>  $schema
     */
    private function createManticoreIndex(ManticoreClient $client, string $indexName, array $schema, bool $dropExisting): bool
    {
        try {
            // Check if index exists
            $indexExists = false;
            try {
                $client->table($indexName)->describe();
                $indexExists = true;
            } catch (ResponseException $e) {
                // Index doesn't exist
            }

            if ($indexExists) {
                if ($dropExisting) {
                    $this->warn("Dropping existing index: {$indexName}");
                    $client->tables()->drop(['index' => $indexName, 'body' => ['silent' => true]]);
                } else {
                    $this->info("Index {$indexName} already exists. Use --drop to recreate.");

                    return true;
                }
            }

            // Create the index using the ManticoreSearch PHP client
            $this->info("Creating index: {$indexName}");

            // Use the same format as CreateManticoreIndexes - pass schema directly
            $response = $client->tables()->create([
                'index' => $indexName,
                'body' => $schema,
            ]);

            $this->info("Index {$indexName} created successfully.");
            $this->line('Response: '.json_encode($response, JSON_PRETTY_PRINT));

            return true;

        } catch (ResponseException $e) {
            $kind = CreateManticoreIndexes::createErrorKind($e->getMessage());
            // Check if the error is because the index already exists
            if ($kind === CreateManticoreIndexes::CREATE_ERROR_ALREADY_EXISTS) {
                $this->warn("Index {$indexName} already exists. Use --drop to recreate.");

                return true;
            }
            // Check for data_dir configuration error
            if ($kind === CreateManticoreIndexes::CREATE_ERROR_DATA_DIR) {
                $this->error("Failed to create index {$indexName}: ".$e->getMessage());
                $this->newLine();
                $this->warn('ManticoreSearch requires data_dir to be set in its configuration file.');
                $this->info('To fix this, edit your ManticoreSearch config file (usually /etc/manticoresearch/manticore.conf):');
                $this->line('  1. Add or uncomment: data_dir = /var/lib/manticore');
                $this->line('  2. Ensure the directory exists and is writable by the manticore user');
                $this->line('  3. Restart ManticoreSearch: sudo systemctl restart manticore');

                return false;
            }
            $this->error("Failed to create index {$indexName}: ".$e->getMessage());

            return false;
        } catch (Throwable $e) {
            $this->error("Unexpected error creating index {$indexName}: ".$e->getMessage());

            return false;
        }
    }

    /**
     * Create Elasticsearch indexes for movies and tvshows.
     */
    private function createElasticsearchIndexes(): int
    {
        $this->info('Creating Elasticsearch media indexes...');

        $dropExisting = $this->option('drop');

        ['movies' => $moviesMapping, 'tvshows' => $tvshowsMapping] = self::elasticsearchMediaMappings();

        try {
            $esConfig = config('search.drivers.elasticsearch');
            /** @var ElasticsearchClient $esClient */
            $esClient = ElasticsearchClientFactory::make($esConfig ?? []);

            // Create movies index
            $moviesIndex = config('search.drivers.elasticsearch.indexes.movies', 'movies');
            if ($dropExisting && (ElasticsearchResponseHelper::boolResponse($esClient, function (ElasticsearchClient $client) use ($moviesIndex) {
                return $client->indices()->exists(['index' => $moviesIndex]);
            }))) {
                $this->warn("Dropping existing index: {$moviesIndex}");
                $esClient->indices()->delete(['index' => $moviesIndex]);
            }

            if (! (ElasticsearchResponseHelper::boolResponse($esClient, function (ElasticsearchClient $client) use ($moviesIndex) {
                return $client->indices()->exists(['index' => $moviesIndex]);
            }))) {
                $this->info("Creating index: {$moviesIndex}");
                $esClient->indices()->create([
                    'index' => $moviesIndex,
                    'body' => $moviesMapping,
                ]);
                $this->info("Index {$moviesIndex} created successfully.");
            } else {
                $this->info("Index {$moviesIndex} already exists. Use --drop to recreate.");
            }

            // Create tvshows index
            $tvshowsIndex = config('search.drivers.elasticsearch.indexes.tvshows', 'tvshows');
            if ($dropExisting && (ElasticsearchResponseHelper::boolResponse($esClient, function (ElasticsearchClient $client) use ($tvshowsIndex) {
                return $client->indices()->exists(['index' => $tvshowsIndex]);
            }))) {
                $this->warn("Dropping existing index: {$tvshowsIndex}");
                $esClient->indices()->delete(['index' => $tvshowsIndex]);
            }

            if (! (ElasticsearchResponseHelper::boolResponse($esClient, function (ElasticsearchClient $client) use ($tvshowsIndex) {
                return $client->indices()->exists(['index' => $tvshowsIndex]);
            }))) {
                $this->info("Creating index: {$tvshowsIndex}");
                $esClient->indices()->create([
                    'index' => $tvshowsIndex,
                    'body' => $tvshowsMapping,
                ]);
                $this->info("Index {$tvshowsIndex} created successfully.");
            } else {
                $this->info("Index {$tvshowsIndex} already exists. Use --drop to recreate.");
            }

            $this->info('Elasticsearch media indexes created successfully!');

            // Optionally populate the indexes
            if ($this->option('populate')) {
                $this->info('Populating indexes...');
                $this->call('nntmux:populate', [
                    '--elastic' => true,
                    '--movies' => true,
                ]);
                $this->call('nntmux:populate', [
                    '--elastic' => true,
                    '--tvshows' => true,
                ]);
            }

            return Command::SUCCESS;

        } catch (Throwable $e) {
            $this->error('Failed to create Elasticsearch indexes: '.$e->getMessage());

            return Command::FAILURE;
        }
    }

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi ?? false;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    /**
     * The configured search driver: Manticore, Elasticsearch, or unsupported (strict comparison).
     */
    public static function mediaDriver(mixed $driver): int
    {
        return self::$useRustFfi ? self::mediaDriverNative($driver) : self::mediaDriverPhp($driver);
    }

    public static function mediaDriverPhp(mixed $driver): int
    {
        return match ($driver) {
            'manticore' => self::DRIVER_MANTICORE,
            'elasticsearch' => self::DRIVER_ELASTICSEARCH,
            default => self::DRIVER_UNSUPPORTED,
        };
    }

    public static function mediaDriverNative(mixed $driver): int
    {
        $ffi = self::ffi();
        if ($ffi === null || ! \is_string($driver)) {
            return self::mediaDriverPhp($driver);
        }

        try {
            $kind = self::callFfi($ffi, 'nntmux_media_driver_kind', $driver, \strlen($driver));

            return match ($kind) {
                self::DRIVER_MANTICORE, self::DRIVER_ELASTICSEARCH, self::DRIVER_UNSUPPORTED => $kind,
                default => self::mediaDriverPhp($driver),
            };
        } catch (Throwable) {
            return self::mediaDriverPhp($driver);
        }
    }

    /**
     * Manticore `movies_rt` and `tvshows_rt` table schemas.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function manticoreMediaSchemas(): array
    {
        return self::$useRustFfi ? self::manticoreMediaSchemasNative() : self::manticoreMediaSchemasPhp();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function manticoreMediaSchemasPhp(): array
    {
        return [
            'movies_rt' => [
                'settings' => [
                    'min_prefix_len' => 0,
                    'min_infix_len' => 2,
                ],
                'columns' => [
                    'imdbid' => ['type' => 'string'],
                    'tmdbid' => ['type' => 'integer'],
                    'traktid' => ['type' => 'integer'],
                    'title' => ['type' => 'text'],
                    'year' => ['type' => 'text'],
                    'genre' => ['type' => 'text'],
                    'actors' => ['type' => 'text'],
                    'director' => ['type' => 'text'],
                    'rating' => ['type' => 'text'],
                    'plot' => ['type' => 'text'],
                ],
            ],
            'tvshows_rt' => [
                'settings' => [
                    'min_prefix_len' => 0,
                    'min_infix_len' => 2,
                ],
                'columns' => [
                    'title' => ['type' => 'text'],
                    'tvdb' => ['type' => 'integer'],
                    'trakt' => ['type' => 'integer'],
                    'tvmaze' => ['type' => 'integer'],
                    'tvrage' => ['type' => 'integer'],
                    'imdb' => ['type' => 'string'],
                    'tmdb' => ['type' => 'integer'],
                    'started' => ['type' => 'text'],
                    'type' => ['type' => 'integer'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function manticoreMediaSchemasNative(): array
    {
        return self::nativeSchema(self::SCHEMA_MANTICORE) ?? self::manticoreMediaSchemasPhp();
    }

    /**
     * Elasticsearch `movies` and `tvshows` index mappings.
     *
     * @return array{movies: array<string, mixed>, tvshows: array<string, mixed>}
     */
    public static function elasticsearchMediaMappings(): array
    {
        return self::$useRustFfi ? self::elasticsearchMediaMappingsNative() : self::elasticsearchMediaMappingsPhp();
    }

    /**
     * @return array{movies: array<string, mixed>, tvshows: array<string, mixed>}
     */
    public static function elasticsearchMediaMappingsPhp(): array
    {
        return [
            'movies' => [
                'mappings' => [
                    'properties' => [
                        'imdbid' => ['type' => 'keyword'],
                        'tmdbid' => ['type' => 'integer'],
                        'traktid' => ['type' => 'integer'],
                        'title' => ['type' => 'text', 'analyzer' => 'standard'],
                        'year' => ['type' => 'keyword'],
                        'genre' => ['type' => 'text'],
                        'actors' => ['type' => 'text'],
                        'director' => ['type' => 'text'],
                        'rating' => ['type' => 'keyword'],
                        'plot' => ['type' => 'text'],
                    ],
                ],
                'settings' => [
                    'number_of_shards' => 1,
                    'number_of_replicas' => 0,
                ],
            ],
            'tvshows' => [
                'mappings' => [
                    'properties' => [
                        'title' => ['type' => 'text', 'analyzer' => 'standard'],
                        'tvdb' => ['type' => 'integer'],
                        'trakt' => ['type' => 'integer'],
                        'tvmaze' => ['type' => 'integer'],
                        'tvrage' => ['type' => 'integer'],
                        'imdb' => ['type' => 'keyword'],
                        'tmdb' => ['type' => 'integer'],
                        'started' => ['type' => 'keyword'],
                        'type' => ['type' => 'integer'],
                    ],
                ],
                'settings' => [
                    'number_of_shards' => 1,
                    'number_of_replicas' => 0,
                ],
            ],
        ];
    }

    /**
     * @return array{movies: array<string, mixed>, tvshows: array<string, mixed>}
     */
    public static function elasticsearchMediaMappingsNative(): array
    {
        $mappings = self::nativeSchema(self::SCHEMA_ELASTICSEARCH);
        if ($mappings === null || ! isset($mappings['movies'], $mappings['tvshows']) || \count($mappings) !== 2) {
            return self::elasticsearchMediaMappingsPhp();
        }

        return ['movies' => $mappings['movies'], 'tvshows' => $mappings['tvshows']];
    }

    /**
     * Decode a Rust-built schema JSON object, or null when unavailable or malformed.
     *
     * @return array<string, array<string, mixed>>|null
     */
    private static function nativeSchema(int $kind): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, 2048, $capacity);
            if ($out === null) {
                return null;
            }
            $written = self::callFfi($ffi, 'nntmux_media_index_schema', $kind, $out, $capacity);
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return null;
            }
            $decoded = json_decode(FFI::string($out, $written), true, 16, JSON_THROW_ON_ERROR);
            if (! \is_array($decoded)) {
                return null;
            }
            foreach ($decoded as $name => $schema) {
                if (! \is_string($name) || ! \is_array($schema)) {
                    return null;
                }
            }

            /** @var array<string, array<string, mixed>> $decoded */
            return $decoded;
        } catch (Throwable) {
            return null;
        }
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
