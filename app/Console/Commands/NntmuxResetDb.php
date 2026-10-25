<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Facades\Elasticsearch;
use App\Facades\Search;
use App\Models\UsenetGroup;
use App\Services\Releases\Verification\VerificationResetService;
use App\Services\Search\Support\ElasticsearchResponseHelper;
use App\Support\PredbSearchDocument;
use App\Support\RustPipelineLibrary;
use Elastic\Elasticsearch\Client as ElasticsearchClient;
use FFI;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

class NntmuxResetDb extends Command
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w371_fix_names_reset_verify(uint32_t operation, uint32_t flags, const char *input, size_t input_len, char *out_buf, size_t out_cap);
        C;

    /**
     * PHP remains the default: the paired benchmark in
     * tests/Unit/RustFixReleaseNamesResetVerifyCommandParityTest.php is below 1.5x.
     */
    public const bool RUST_FFI_DEFAULT = false;

    private const int RUST_MAX_INPUT = 16_777_216;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    private const array RESET_ACTIONS = [1 => 'refuse_environment', 2 => 'abort', 3 => 'reset'];

    private const array RESET_SEARCH_BACKENDS = [1 => 'elasticsearch', 2 => 'manticore'];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nntmux:resetdb';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This command will reset your database to blank state (will retain settings)';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * @throws \Exception
     */
    public function handle(VerificationResetService $verification): int
    {
        // Allow database reset only if app environment is local
        if (app()->environment() !== 'local') {
            $this->error('This command can only be run in local environment');

            return self::SUCCESS;
        }
        if ($this->confirm('This command removes all releases, nzb files, samples, previews , nfos, truncates all article tables and resets all groups. Are you sure you want reset the DB?')) {
            try {
                $verification->run(fn () => $this->resetDatabase());
            } catch (Throwable $error) {
                $this->error('Database reset stopped: '.$error->getMessage());

                return self::FAILURE;
            }
        } else {
            $this->info('Script execution stopped');
        }

        return self::SUCCESS;
    }

    private function resetDatabase(): void
    {
        $timestart = now();

        DB::statement('SET FOREIGN_KEY_CHECKS = 0;');
        try {

            UsenetGroup::query()->update([
                'first_record' => 0,
                'first_record_postdate' => null,
                'last_record' => 0,
                'last_record_postdate' => null,
                'last_updated' => null,
            ]);
            $this->info('Reseting all groups completed.');

            $arr = [
                'binaries',
                'collections',
                'collection_groups',
                'parts',
                'missed_parts',
                'videos',
                'videos_aliases',
                'tv_episodes',
                'tv_info',
                'release_nfos',
                'nfo_cleanup_commits',
                'release_comments',
                'users_releases',
                'user_movies',
                'user_series',
                'movieinfo',
                'musicinfo',
                'bookinfo',
                'consoleinfo',
                'gamesinfo',
                'release_files',
                'audio_data',
                'release_subtitles',
                'video_data',
                'media_infos',
                'par_hashes',
                'par_file_hashes',
                'par_file_hash_inventories',
                'par2_descriptor_evidence',
                'par2_hash_retry',
                'par2_rename_candidate_progress',
                'par2_rename_progress',
                'release_regexes',
                'release_unique',
                'release_reports',
                'release_nzb_creation_failures',
                'release_nzb_passwords',
                'releases',
                'anidb_titles',
                'anidb_info',
                'releases_groups',
            ];
            foreach ($arr as &$value) {
                DB::statement("TRUNCATE TABLE $value");
                $this->info('Truncating '.$value.' completed.');
            }
            unset($value);

            if (config('search.default') === 'elasticsearch') {
                /** @var ElasticsearchClient $client */
                $client = app('elasticsearch');

                if (ElasticsearchResponseHelper::boolResponse($client, fn (ElasticsearchClient $elasticClient) => $elasticClient->indices()->exists(['index' => 'releases']))) {
                    Elasticsearch::indices()->delete(['index' => 'releases']);
                }
                $releases_index = [
                    'index' => 'releases',
                    'body' => [
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
                                'name' => ['type' => 'text'],
                                'searchname' => [
                                    'type' => 'text',
                                    'fields' => [
                                        'sort' => [
                                            'type' => 'keyword',
                                        ],
                                    ],
                                ],
                                'plainsearchname' => [
                                    'type' => 'text',
                                    'fields' => [
                                        'sort' => [
                                            'type' => 'keyword',
                                        ],
                                    ],
                                ],
                                'fromname' => ['type' => 'text'],
                                'filename' => ['type' => 'text'],
                                'add_date' => ['type' => 'date', 'format' => 'yyyy-MM-dd HH:mm:ss'],
                                'post_date' => ['type' => 'date', 'format' => 'yyyy-MM-dd HH:mm:ss'],
                                'media_movie_name' => ['type' => 'text'],
                                'media_file_name' => ['type' => 'text'],
                                'media_unique_id' => ['type' => 'keyword'],
                                'media_container_format' => ['type' => 'text'],
                                'media_video_format' => ['type' => 'text'],
                                'media_video_codec' => ['type' => 'text'],
                                'media_video_width' => ['type' => 'integer'],
                                'media_video_height' => ['type' => 'integer'],
                                'media_audio_format' => ['type' => 'text'],
                                'media_audio_channels' => ['type' => 'text'],
                                'media_audio_language' => ['type' => 'text'],
                                'media_subtitle_language' => ['type' => 'text'],
                                'has_media_info' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                ];

                Elasticsearch::indices()->create($releases_index);

                $predbIndex = (string) config('search.drivers.elasticsearch.indexes.predb', 'predb');
                if (ElasticsearchResponseHelper::boolResponse($client, fn (ElasticsearchClient $elasticClient) => $elasticClient->indices()->exists(['index' => $predbIndex]))) {
                    Elasticsearch::indices()->delete(['index' => $predbIndex]);
                }
                $predb_index = [
                    'index' => $predbIndex,
                    'body' => [
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
                    ],

                ];

                Elasticsearch::indices()->create($predb_index);

                // Delete and recreate movies index
                if (ElasticsearchResponseHelper::boolResponse($client, fn (ElasticsearchClient $elasticClient) => $elasticClient->indices()->exists(['index' => 'movies']))) {
                    Elasticsearch::indices()->delete(['index' => 'movies']);
                }
                $movies_index = [
                    'index' => 'movies',
                    'body' => [
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
                                'imdbid' => ['type' => 'keyword'],
                                'tmdbid' => ['type' => 'integer'],
                                'traktid' => ['type' => 'integer'],
                                'title' => [
                                    'type' => 'text',
                                    'fields' => [
                                        'sort' => [
                                            'type' => 'keyword',
                                        ],
                                    ],
                                ],
                                'year' => ['type' => 'text'],
                                'genre' => ['type' => 'text'],
                                'actors' => ['type' => 'text'],
                                'director' => ['type' => 'text'],
                                'rating' => ['type' => 'text'],
                                'plot' => ['type' => 'text'],
                            ],
                        ],
                    ],
                ];

                Elasticsearch::indices()->create($movies_index);

                // Delete and recreate tvshows index
                if (ElasticsearchResponseHelper::boolResponse($client, fn (ElasticsearchClient $elasticClient) => $elasticClient->indices()->exists(['index' => 'tvshows']))) {
                    Elasticsearch::indices()->delete(['index' => 'tvshows']);
                }
                $tvshows_index = [
                    'index' => 'tvshows',
                    'body' => [
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
                                'tvdb' => ['type' => 'integer'],
                                'trakt' => ['type' => 'integer'],
                                'tvmaze' => ['type' => 'integer'],
                                'tvrage' => ['type' => 'integer'],
                                'imdb' => ['type' => 'keyword'],
                                'tmdb' => ['type' => 'integer'],
                                'started' => ['type' => 'text'],
                                'type' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                ];

                Elasticsearch::indices()->create($tvshows_index);

                $this->info('All done! ElasticSearch indexes are deleted and recreated.');
            } else {
                Search::truncateIndex([
                    'releases_rt', 'movies_rt', 'tvshows_rt',
                    'music_rt', 'books_rt', 'games_rt', 'console_rt', 'steam_rt', 'anime_rt',
                ]);
            }

            $this->info('Deleting nzbfiles subfolders.');
            $files = File::allFiles(config('nntmux_settings.path_to_nzbs'));
            File::delete($files);

            $this->info('Deleting all images, previews and samples that still remain.');

            $files = File::allFiles(storage_path('covers/'));
            foreach ($files as $file) {
                $path = $file->getPathname();
                if (basename($path) !== '.gitignore' && basename($path) !== 'no-cover.jpg' && basename($path) !== 'no-backdrop.jpg') {
                    File::delete($path);
                }
            }

            $this->call('cache:clear');

            $this->info('Deleted all releases, images, previews and samples. This script finished '.now()->diffForHumans($timestart).' start');
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1;');
        }
    }

    /**
     * Plans a reset: anything but the `local` environment is refused, an unconfirmed prompt
     * aborts, and any driver except `elasticsearch` uses the Manticore indexes. Table names are
     * trimmed, must be `[A-Za-z_][A-Za-z0-9_]*` of at most 64 bytes and are deduplicated
     * case-insensitively in first-seen order; non-strings count as invalid.
     *
     * @param  array<mixed>  $tables
     * @return array{action: string, search_backend: string, tables: list<string>, invalid: int, duplicates: int}
     */
    public static function normalizeResetPlan(string $environment, bool $confirmed, string $searchDriver, array $tables): array
    {
        return (self::usesRustFfi() ? self::normalizeResetPlanNative($environment, $confirmed, $searchDriver, $tables) : null)
            ?? self::normalizeResetPlanPhp($environment, $confirmed, $searchDriver, $tables);
    }

    /**
     * @param  array<mixed>  $tables
     * @return array{action: string, search_backend: string, tables: list<string>, invalid: int, duplicates: int}
     */
    private static function normalizeResetPlanPhp(string $environment, bool $confirmed, string $searchDriver, array $tables): array
    {
        $kept = [];
        $seen = [];
        $invalid = 0;
        $duplicates = 0;
        foreach ($tables as $table) {
            if (! is_string($table)) {
                $invalid++;

                continue;
            }
            $table = trim($table);
            if (strlen($table) > 64 || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $table) !== 1) {
                $invalid++;

                continue;
            }
            $key = strtolower($table);
            if (isset($seen[$key])) {
                $duplicates++;

                continue;
            }
            $seen[$key] = true;
            $kept[] = $table;
        }

        return [
            'action' => match (true) {
                $environment !== 'local' => 'refuse_environment',
                ! $confirmed => 'abort',
                default => 'reset',
            },
            'search_backend' => $searchDriver === 'elasticsearch' ? 'elasticsearch' : 'manticore',
            'tables' => $kept,
            'invalid' => $invalid,
            'duplicates' => $duplicates,
        ];
    }

    /**
     * @param  array<mixed>  $tables
     * @return array{action: string, search_backend: string, tables: list<string>, invalid: int, duplicates: int}|null
     */
    private static function normalizeResetPlanNative(string $environment, bool $confirmed, string $searchDriver, array $tables): ?array
    {
        $input = self::packRustString($environment).self::packRustString($searchDriver).pack('q', count($tables));
        foreach ($tables as $table) {
            $input .= is_string($table) ? self::packRustString($table) : pack('q', -1);
        }
        $bytes = self::callRustFfi(5, $confirmed ? 1 : 0, $input);
        if ($bytes === null || strlen($bytes) < 40) {
            return null;
        }
        $header = self::unpackRustInts(substr($bytes, 0, 40), 5);
        if ($header === null) {
            return null;
        }
        [$action, $search, $invalid, $duplicates, $count] = $header;
        $actionName = self::RESET_ACTIONS[$action] ?? null;
        $searchName = self::RESET_SEARCH_BACKENDS[$search] ?? null;
        if ($actionName === null || $searchName === null || $count < 0 || $count > count($tables)) {
            return null;
        }
        $kept = [];
        $offset = 40;
        $size = strlen($bytes);
        for ($i = 0; $i < $count; $i++) {
            if ($size - $offset < 8) {
                return null;
            }
            $length = unpack('q', $bytes, $offset)[1] ?? -1;
            $offset += 8;
            if (! is_int($length) || $length < 0 || $length > $size - $offset) {
                return null;
            }
            $kept[] = substr($bytes, $offset, $length);
            $offset += $length;
        }
        if ($offset !== $size) {
            return null;
        }

        return [
            'action' => $actionName,
            'search_backend' => $searchName,
            'tables' => $kept,
            'invalid' => $invalid,
            'duplicates' => $duplicates,
        ];
    }

    public static function useRustFfi(?bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = null;
        self::$ffi = null;
    }

    private static function packRustString(string $value): string
    {
        return pack('q', strlen($value)).$value;
    }

    /**
     * @return list<int>|null
     */
    private static function unpackRustInts(?string $bytes, int $count): ?array
    {
        if ($bytes === null || strlen($bytes) !== $count * 8) {
            return null;
        }
        $values = unpack('q*', $bytes);

        return $values === false ? null : array_values($values);
    }

    /**
     * Calls the Wave 37.1 export, retrying once with a larger scratch buffer when it reports `-required`.
     */
    private static function callRustFfi(int $operation, int $flags, string $input): ?string
    {
        if (strlen($input) > self::RUST_MAX_INPUT) {
            return null;
        }
        try {
            self::$ffi ??= RustPipelineLibrary::resolveDomain('cleaning_w371', self::FFI_CDEF) ?? false;
            if (self::$ffi === false) {
                return null;
            }
            $ffi = self::$ffi;
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, strlen($input) + 256, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w371_fix_names_reset_verify($operation, $flags, $input, strlen($input), $buffer, $capacity);
            if (is_int($written) && $written < -1) {
                if (-$written <= $capacity || -$written > self::RUST_MAX_INPUT) {
                    return null;
                }
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w371_fix_names_reset_verify($operation, $flags, $input, strlen($input), $buffer, $capacity);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return null;
        }
    }
}
