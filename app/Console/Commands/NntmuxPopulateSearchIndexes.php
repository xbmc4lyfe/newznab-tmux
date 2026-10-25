<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SecondarySearchIndex;
use App\Facades\Elasticsearch;
use App\Facades\Search;
use App\Models\BookInfo;
use App\Models\ConsoleInfo;
use App\Models\GamesInfo;
use App\Models\MovieInfo;
use App\Models\MusicInfo;
use App\Models\Predb;
use App\Models\Release;
use App\Models\SteamApp;
use App\Models\Video;
use App\Services\Search\Drivers\ManticoreSearchDriver;
use App\Services\Search\Support\ReleaseIndexProjection;
use App\Support\PredbSearchDocument;
use App\Support\ReleaseSearchIndexDocument;
use App\Support\RustPipelineLibrary;
use App\Support\SecondaryIndexDocuments;
use Exception;
use FFI;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

class NntmuxPopulateSearchIndexes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nntmux:populate
                                       {--manticore : Use ManticoreSearch}
                                       {--elastic : Use ElasticSearch}
                                       {--releases : Populates the releases index}
                                       {--predb : Populates the predb index}
                                       {--movies : Populates the movies index}
                                       {--tvshows : Populates the TV shows index}
                                       {--music : Populates the music metadata index}
                                       {--books : Populates the books metadata index}
                                       {--games : Populates the games metadata index}
                                       {--console : Populates the console metadata index}
                                       {--steam : Populates the Steam apps index}
                                       {--anime : Populates the anime titles index}
                                       {--all : Populate all supported indexes for the selected engine}
                                       {--count=5000 : Sets the database chunk size}
                                       {--parallel=4 : Number of parallel processes}
                                       {--batch-size=5000 : Batch size for bulk operations}
                                       {--disable-keys : Disable database keys during population}
                                       {--optimize : Optimize ManticoreSearch indexes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Populate Manticore/Elasticsearch indexes (releases, predb, movies, tvshows, music, books, games, console, steam, anime)';

    private const SUPPORTED_ENGINES = ['manticore', 'elastic'];

    private const SUPPORTED_INDEXES = [
        'releases', 'predb', 'movies', 'tvshows',
        'music', 'books', 'games', 'console', 'steam', 'anime',
    ];

    private const GROUP_CONCAT_MAX_LEN = 16384;

    private const DEFAULT_CHUNK_SIZE = 5000;

    /** @phpstan-ignore classConstant.unused */
    private const DEFAULT_PARALLEL_PROCESSES = 4;

    private const DEFAULT_BATCH_SIZE = 5000;

    public const string FFI_CDEF = 'ptrdiff_t nntmux_w37_search_index_offset_worker_command(uint32_t mode, const char *input, size_t input_len, char *out, size_t cap);';

    /**
     * Rust stays opt-in: the FFI round trip is slower than the PHP helper (< 1.5x); see
     * tests/Unit/RustSearchIndexOffsetWorkerCommandParityTest.php.
     */
    public const bool RUST_FFI_DEFAULT = false;

    private const int NATIVE_MAX_PARTS = 4096;

    private const int NATIVE_MAX_BYTES = 16_777_216;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            if ($this->option('optimize')) {
                return $this->handleOptimize();
            }

            $engine = $this->getSelectedEngine();
            $indexes = $this->getSelectedIndexes();

            if (! $engine || $indexes === []) {
                $this->error('You must specify an engine (--manticore or --elastic) and at least one index (or --all).');
                $this->info('Use --help to see all available options.');

                return Command::FAILURE;
            }

            $exit = Command::SUCCESS;
            foreach ($indexes as $index) {
                $result = $this->populateIndex($engine, $index);
                if ($result !== Command::SUCCESS) {
                    $exit = $result;
                }
            }

            return $exit;

        } catch (Exception $e) {
            $this->error("An error occurred: {$e->getMessage()}");

            if ($this->output->isVerbose()) {
                $this->error($e->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }

    /**
     * Get the selected search engine from options
     */
    private function getSelectedEngine(): ?string
    {
        foreach (self::SUPPORTED_ENGINES as $engine) {
            if ($this->option($engine)) {
                return $engine;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function getSelectedIndexes(): array
    {
        if ($this->option('all')) {
            return self::SUPPORTED_INDEXES;
        }

        $selected = [];
        foreach (self::SUPPORTED_INDEXES as $index) {
            if ($this->option($index)) {
                $selected[] = $index;
            }
        }

        return $selected;
    }

    /**
     * Handle the optimize command
     */
    private function handleOptimize(): int
    {
        $this->info('Optimizing search indexes...');

        try {
            Search::optimizeIndex();
            $this->info('Optimization completed successfully!');

            return Command::SUCCESS;
        } catch (Exception $e) {
            $this->error("Optimization failed: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }

    /**
     * Populate the specified index with the specified engine
     */
    private function populateIndex(string $engine, string $index): int
    {
        $methodName = "{$engine}".ucfirst($index);

        if (! method_exists($this, $methodName)) {
            $this->error("Method {$methodName} not implemented.");

            return Command::FAILURE;
        }

        $this->info("Starting {$engine} {$index} population...");

        $startTime = microtime(true);
        $result = $this->{$methodName}();
        $executionTime = round(microtime(true) - $startTime, 2);

        if ($result === Command::SUCCESS) {
            $this->info("Population completed in {$executionTime} seconds.");
        }

        return $result;
    }

    private function manticoreReleases(): int
    {
        $indexName = (string) config('search.drivers.manticore.indexes.releases', 'releases_rt');

        Search::truncateIndex([$indexName]);

        $total = Release::count();
        if (! $total) {
            $this->warn('Releases table is empty. Nothing to do.');

            return Command::SUCCESS;
        }

        $query = ReleaseIndexProjection::query()->orderBy('r.id');

        return $this->processManticoreData(
            $indexName,
            $total,
            $query,
            function ($item) {
                return ReleaseSearchIndexDocument::normalize((array) $item);
            }
        );
    }

    /**
     * Populate ManticoreSearch predb index
     */
    private function manticorePredb(): int
    {
        $indexName = (string) config('search.drivers.manticore.indexes.predb', 'predb_rt');

        Search::truncateIndex([$indexName]);

        $total = Predb::count();
        if (! $total) {
            $this->warn('PreDB table is empty. Nothing to do.');

            return Command::SUCCESS;
        }

        $query = Predb::query()
            ->select(['id', 'title', 'filename', 'source'])
            ->orderBy('id');

        return $this->processManticoreData(
            $indexName,
            $total,
            $query,
            function ($item) {
                return [
                    'id' => $item->id,
                    'title' => (string) ($item->title ?? ''),
                    'filename' => (string) ($item->filename ?? ''),
                    'source' => (string) ($item->source ?? ''),
                ];
            }
        );
    }

    /**
     * Populate ManticoreSearch movies index
     */
    private function manticoreMovies(): int
    {
        $indexName = (string) config('search.drivers.manticore.indexes.movies', 'movies_rt');

        Search::truncateIndex([$indexName]);

        $total = MovieInfo::count();
        if (! $total) {
            $this->warn('MovieInfo table is empty. Nothing to do.');

            return Command::SUCCESS;
        }

        $query = MovieInfo::query()
            ->select([
                'id',
                'imdbid',
                'tmdbid',
                'traktid',
                'title',
                'year',
                'genre',
                'actors',
                'director',
                'rating',
                'plot',
            ])
            ->orderBy('id');

        return $this->processManticoreMoviesData(
            $indexName,
            $total,
            $query,
            function ($item) {
                return [
                    'id' => $item->id,
                    'imdbid' => (string) ($item->imdbid ?? ''),
                    'tmdbid' => (int) ($item->tmdbid ?? 0),
                    'traktid' => (int) ($item->traktid ?? 0),
                    'title' => (string) ($item->title ?? ''),
                    'year' => (string) ($item->year ?? ''),
                    'genre' => (string) ($item->genre ?? ''),
                    'actors' => (string) ($item->actors ?? ''),
                    'director' => (string) ($item->director ?? ''),
                    'rating' => (string) ($item->rating ?? ''),
                    'plot' => (string) ($item->plot ?? ''),
                ];
            }
        );
    }

    /**
     * Populate ManticoreSearch TV shows index
     */
    private function manticoreTvshows(): int
    {
        $indexName = (string) config('search.drivers.manticore.indexes.tvshows', 'tvshows_rt');

        Search::truncateIndex([$indexName]);

        $total = Video::count();
        if (! $total) {
            $this->warn('Videos table is empty. Nothing to do.');

            return Command::SUCCESS;
        }

        $query = Video::query()
            ->select([
                'id',
                'title',
                'tvdb',
                'trakt',
                'tvmaze',
                'tvrage',
                'imdb',
                'tmdb',
                'started',
                'type',
            ])
            ->orderBy('id');

        return $this->processManticoreTvShowsData(
            $indexName,
            $total,
            $query,
            function ($item) {
                return [
                    'id' => $item->id,
                    'title' => (string) ($item->title ?? ''),
                    'tvdb' => (int) ($item->tvdb ?? 0),
                    'trakt' => (int) ($item->trakt ?? 0),
                    'tvmaze' => (int) ($item->tvmaze ?? 0),
                    'tvrage' => (int) ($item->tvrage ?? 0),
                    'imdb' => (string) ($item->imdb ?? ''),
                    'tmdb' => (int) ($item->tmdb ?? 0),
                    'started' => (string) ($item->started ?? ''),
                    'type' => (int) ($item->type ?? 0),
                ];
            }
        );
    }

    private function manticoreMusic(): int
    {
        $indexName = (string) config('search.drivers.manticore.indexes.music', 'music_rt');
        Search::truncateIndex([$indexName]);
        $total = MusicInfo::count();
        if (! $total) {
            $this->warn('MusicInfo table is empty. Nothing to do.');

            return Command::SUCCESS;
        }
        $query = MusicInfo::query()->orderBy('id');

        return $this->processManticoreData(
            $indexName,
            $total,
            $query,
            fn (MusicInfo $m): array => array_merge(['id' => $m->id], SecondaryIndexDocuments::music($m))
        );
    }

    private function manticoreBooks(): int
    {
        $indexName = (string) config('search.drivers.manticore.indexes.books', 'books_rt');
        Search::truncateIndex([$indexName]);
        $total = BookInfo::count();
        if (! $total) {
            $this->warn('BookInfo table is empty. Nothing to do.');

            return Command::SUCCESS;
        }
        $query = BookInfo::query()->orderBy('id');

        return $this->processManticoreData(
            $indexName,
            $total,
            $query,
            fn (BookInfo $b): array => array_merge(['id' => $b->id], SecondaryIndexDocuments::book($b))
        );
    }

    private function manticoreGames(): int
    {
        $indexName = (string) config('search.drivers.manticore.indexes.games', 'games_rt');
        Search::truncateIndex([$indexName]);
        $total = GamesInfo::count();
        if (! $total) {
            $this->warn('GamesInfo table is empty. Nothing to do.');

            return Command::SUCCESS;
        }
        $query = GamesInfo::query()->orderBy('id');

        return $this->processManticoreData(
            $indexName,
            $total,
            $query,
            fn (GamesInfo $g): array => array_merge(['id' => $g->id], SecondaryIndexDocuments::games($g))
        );
    }

    private function manticoreConsole(): int
    {
        $indexName = (string) config('search.drivers.manticore.indexes.console', 'console_rt');
        Search::truncateIndex([$indexName]);
        $total = ConsoleInfo::count();
        if (! $total) {
            $this->warn('ConsoleInfo table is empty. Nothing to do.');

            return Command::SUCCESS;
        }
        $query = ConsoleInfo::query()->orderBy('id');

        return $this->processManticoreData(
            $indexName,
            $total,
            $query,
            fn (ConsoleInfo $c): array => array_merge(['id' => $c->id], SecondaryIndexDocuments::console($c))
        );
    }

    private function manticoreSteam(): int
    {
        $indexName = (string) config('search.drivers.manticore.indexes.steam', 'steam_rt');
        Search::truncateIndex([$indexName]);
        $total = SteamApp::count();
        if (! $total) {
            $this->warn('Steam apps table is empty. Nothing to do.');

            return Command::SUCCESS;
        }
        $query = SteamApp::query()->orderBy('id');

        return $this->processManticoreData(
            $indexName,
            $total,
            $query,
            fn (SteamApp $s): array => array_merge(['id' => (int) $s->id], SecondaryIndexDocuments::steam($s))
        );
    }

    private function manticoreAnime(): int
    {
        $indexName = (string) config('search.drivers.manticore.indexes.anime', 'anime_rt');
        Search::truncateIndex([$indexName]);
        $total = (int) DB::table('anidb_titles')->count();
        if (! $total) {
            $this->warn('anidb_titles table is empty. Nothing to do.');

            return Command::SUCCESS;
        }
        $query = DB::table('anidb_titles as t')
            ->leftJoin('anidb_info as i', 't.anidbid', '=', 'i.anidbid')
            ->orderBy('t.anidbid')
            ->select([
                't.anidbid', 't.type', 't.lang', 't.title',
                'i.anilist_id', 'i.mal_id', 'i.media_type', 'i.status',
            ]);

        return $this->processManticoreData(
            $indexName,
            $total,
            $query,
            function ($row) {
                $docId = SecondarySearchIndex::animeTitleDocumentId(
                    (int) $row->anidbid,
                    (string) $row->type,
                    (string) $row->lang,
                    (string) $row->title
                );

                return array_merge(['id' => $docId], [
                    'title' => (string) $row->title,
                    'anidbid' => (int) $row->anidbid,
                    'anilist_id' => (int) ($row->anilist_id ?? 0),
                    'mal_id' => (int) ($row->mal_id ?? 0),
                    'lang' => (string) $row->lang,
                    'title_type' => (string) $row->type,
                    'media_type' => (string) ($row->media_type ?? ''),
                    'status' => (string) ($row->status ?? ''),
                ]);
            }
        );
    }

    /**
     * Process data for ManticoreSearch with optimizations
     */
    private function processManticoreData(string $indexName, int $total, mixed $query, callable $transformer): int
    {
        $chunkSize = $this->getChunkSize();
        $batchSize = $this->getBatchSize();

        $this->optimizeDatabase();
        $this->setGroupConcatMaxLen();

        $this->info(sprintf(
            "Populating search index '%s' with %s rows using chunks of %s and batch size of %s.",
            $indexName,
            number_format($total),
            number_format($chunkSize),
            number_format($batchSize)
        ));

        $bar = $this->output->createProgressBar($total);
        $bar->setFormat('verbose');
        $bar->start();

        $processedCount = 0;
        $errorCount = 0;
        $batchData = [];

        try {
            $processItems = function ($items) use ($indexName, $transformer, $bar, &$processedCount, &$errorCount, $batchSize, &$batchData): void {
                foreach ($items as $item) {
                    try {
                        $batchData[] = $transformer($item);
                        $processedCount++;

                        // Process in optimized batch sizes
                        if (count($batchData) >= $batchSize) {
                            $this->processBatch($indexName, $batchData); // @phpstan-ignore argument.type
                            $batchData = [];
                        }
                    } catch (Exception $e) {
                        $errorCount++;
                        if ($this->output->isVerbose()) {
                            $this->error("Error processing item {$item->id}: {$e->getMessage()}");
                        }
                    }
                    $bar->advance();
                }
            };

            if ($indexName === (string) config('search.drivers.manticore.indexes.releases', 'releases_rt')) {
                $query->chunkById($chunkSize, $processItems, 'r.id', 'id');
            } else {
                $query->chunk($chunkSize, $processItems);
            }

            // Process remaining items
            if (! empty($batchData)) {
                $this->processBatch($indexName, $batchData); // @phpstan-ignore argument.type
            }

            $bar->finish();
            $this->newLine();

            if ($errorCount > 0) {
                $this->warn("Completed with {$errorCount} errors out of {$processedCount} processed items.");

                return Command::FAILURE;
            }

            try {
                $actualCount = app(ManticoreSearchDriver::class)->countIndexDocuments($indexName);
                if ($actualCount < $total) {
                    $this->error(sprintf('Manticore verification failed for %s: expected %d, found %d.', $indexName, $total, $actualCount));

                    return Command::FAILURE;
                }
                $this->info(sprintf('Search index population completed successfully (%d documents verified).', $actualCount));
            } catch (Exception $e) {
                $this->warn('Manticore population completed, but verification failed: '.$e->getMessage());
            }

            return Command::SUCCESS;

        } catch (Exception $e) {
            $bar->finish();
            $this->newLine();
            $this->error("Failed to populate ManticoreSearch: {$e->getMessage()}");

            return Command::FAILURE;
        } finally {
            $this->restoreDatabase();
        }
    }

    /**
     * Process data for ManticoreSearch movies index
     */
    private function processManticoreMoviesData(string $indexName, int $total, mixed $query, callable $transformer): int
    {
        $chunkSize = $this->getChunkSize();
        $batchSize = $this->getBatchSize();

        $this->optimizeDatabase();

        $this->info(sprintf(
            "Populating search index '%s' with %s rows using chunks of %s and batch size of %s.",
            $indexName,
            number_format($total),
            number_format($chunkSize),
            number_format($batchSize)
        ));

        $bar = $this->output->createProgressBar($total);
        $bar->setFormat('verbose');
        $bar->start();

        $processedCount = 0;
        $errorCount = 0;
        $batchData = [];

        try {
            $query->chunk($chunkSize, function ($items) use ($transformer, $bar, &$processedCount, &$errorCount, $batchSize, &$batchData) {
                foreach ($items as $item) {
                    try {
                        $batchData[] = $transformer($item);
                        $processedCount++;

                        // Process in optimized batch sizes
                        if (count($batchData) >= $batchSize) {
                            $this->processMoviesBatch($batchData); // @phpstan-ignore argument.type
                            $batchData = [];
                        }
                    } catch (Exception $e) {
                        $errorCount++;
                        if ($this->output->isVerbose()) {
                            $this->error("Error processing item {$item->id}: {$e->getMessage()}");
                        }
                    }
                    $bar->advance();
                }
            });

            // Process remaining items
            if (! empty($batchData)) {
                $this->processMoviesBatch($batchData); // @phpstan-ignore argument.type
            }

            $bar->finish();
            $this->newLine();

            if ($errorCount > 0) {
                $this->warn("Completed with {$errorCount} errors out of {$processedCount} processed items.");
            } else {
                $this->info('Movies index population completed successfully!');
            }

            return Command::SUCCESS;

        } catch (Exception $e) {
            $bar->finish();
            $this->newLine();
            $this->error("Failed to populate movies index: {$e->getMessage()}");

            return Command::FAILURE;
        } finally {
            $this->restoreDatabase();
        }
    }

    /**
     * Process data for ManticoreSearch TV shows index
     */
    private function processManticoreTvShowsData(string $indexName, int $total, mixed $query, callable $transformer): int
    {
        $chunkSize = $this->getChunkSize();
        $batchSize = $this->getBatchSize();

        $this->optimizeDatabase();

        $this->info(sprintf(
            "Populating search index '%s' with %s rows using chunks of %s and batch size of %s.",
            $indexName,
            number_format($total),
            number_format($chunkSize),
            number_format($batchSize)
        ));

        $bar = $this->output->createProgressBar($total);
        $bar->setFormat('verbose');
        $bar->start();

        $processedCount = 0;
        $errorCount = 0;
        $batchData = [];

        try {
            $query->chunk($chunkSize, function ($items) use ($transformer, $bar, &$processedCount, &$errorCount, $batchSize, &$batchData) {
                foreach ($items as $item) {
                    try {
                        $batchData[] = $transformer($item);
                        $processedCount++;

                        // Process in optimized batch sizes
                        if (count($batchData) >= $batchSize) {
                            $this->processTvShowsBatch($batchData); // @phpstan-ignore argument.type
                            $batchData = [];
                        }
                    } catch (Exception $e) {
                        $errorCount++;
                        if ($this->output->isVerbose()) {
                            $this->error("Error processing item {$item->id}: {$e->getMessage()}");
                        }
                    }
                    $bar->advance();
                }
            });

            // Process remaining items
            if (! empty($batchData)) {
                $this->processTvShowsBatch($batchData); // @phpstan-ignore argument.type
            }

            $bar->finish();
            $this->newLine();

            if ($errorCount > 0) {
                $this->warn("Completed with {$errorCount} errors out of {$processedCount} processed items.");
            } else {
                $this->info('TV shows index population completed successfully!');
            }

            return Command::SUCCESS;

        } catch (Exception $e) {
            $bar->finish();
            $this->newLine();
            $this->error("Failed to populate TV shows index: {$e->getMessage()}");

            return Command::FAILURE;
        } finally {
            $this->restoreDatabase();
        }
    }

    /**
     * Populate ElasticSearch releases index
     */
    private function elasticReleases(): int
    {
        $total = Release::count();
        if (! $total) {
            $this->warn('Releases table is empty. Nothing to do.');

            return Command::SUCCESS;
        }

        $query = ReleaseIndexProjection::query()->orderBy('r.id');

        return $this->processElasticData(
            'releases',
            $total,
            $query,
            fn ($item): array => ReleaseSearchIndexDocument::normalize((array) $item)
        );
    }

    /**
     * Populate ElasticSearch predb index
     */
    private function elasticPredb(): int
    {
        $indexName = (string) config('search.drivers.elasticsearch.indexes.predb', 'predb');
        Elasticsearch::indices()->putMapping([
            'index' => $indexName,
            'body' => ['properties' => PredbSearchDocument::elasticsearchExactMappings()],
        ]);

        $total = Predb::count();
        if (! $total) {
            $this->warn('PreDB table is empty. Nothing to do.');

            return Command::SUCCESS;
        }

        $query = Predb::query()
            ->select(['id', 'title', 'filename', 'source'])
            ->orderBy('id');

        return $this->processElasticData(
            $indexName,
            $total,
            $query,
            function ($item) {
                return PredbSearchDocument::forElasticsearch($item->getAttributes());
            }
        );
    }

    private function elasticMusic(): int
    {
        $ix = (string) config('search.drivers.elasticsearch.indexes.music', 'music');
        Search::truncateIndex([$ix]);
        $total = MusicInfo::count();
        if (! $total) {
            $this->warn('MusicInfo table is empty. Nothing to do.');

            return Command::SUCCESS;
        }
        $query = MusicInfo::query()->orderBy('id');

        return $this->processSecondaryElasticChunks(
            SecondarySearchIndex::Music,
            $total,
            $query,
            fn (MusicInfo $m): array => array_merge(['id' => $m->id], SecondaryIndexDocuments::music($m))
        );
    }

    private function elasticBooks(): int
    {
        $ix = (string) config('search.drivers.elasticsearch.indexes.books', 'books');
        Search::truncateIndex([$ix]);
        $total = BookInfo::count();
        if (! $total) {
            $this->warn('BookInfo table is empty. Nothing to do.');

            return Command::SUCCESS;
        }
        $query = BookInfo::query()->orderBy('id');

        return $this->processSecondaryElasticChunks(
            SecondarySearchIndex::Books,
            $total,
            $query,
            fn (BookInfo $b): array => array_merge(['id' => $b->id], SecondaryIndexDocuments::book($b))
        );
    }

    private function elasticGames(): int
    {
        $ix = (string) config('search.drivers.elasticsearch.indexes.games', 'games');
        Search::truncateIndex([$ix]);
        $total = GamesInfo::count();
        if (! $total) {
            $this->warn('GamesInfo table is empty. Nothing to do.');

            return Command::SUCCESS;
        }
        $query = GamesInfo::query()->orderBy('id');

        return $this->processSecondaryElasticChunks(
            SecondarySearchIndex::Games,
            $total,
            $query,
            fn (GamesInfo $g): array => array_merge(['id' => $g->id], SecondaryIndexDocuments::games($g))
        );
    }

    private function elasticConsole(): int
    {
        $ix = (string) config('search.drivers.elasticsearch.indexes.console', 'console');
        Search::truncateIndex([$ix]);
        $total = ConsoleInfo::count();
        if (! $total) {
            $this->warn('ConsoleInfo table is empty. Nothing to do.');

            return Command::SUCCESS;
        }
        $query = ConsoleInfo::query()->orderBy('id');

        return $this->processSecondaryElasticChunks(
            SecondarySearchIndex::Console,
            $total,
            $query,
            fn (ConsoleInfo $c): array => array_merge(['id' => $c->id], SecondaryIndexDocuments::console($c))
        );
    }

    private function elasticSteam(): int
    {
        $ix = (string) config('search.drivers.elasticsearch.indexes.steam', 'steam');
        Search::truncateIndex([$ix]);
        $total = SteamApp::count();
        if (! $total) {
            $this->warn('Steam apps table is empty. Nothing to do.');

            return Command::SUCCESS;
        }
        $query = SteamApp::query()->orderBy('id');

        return $this->processSecondaryElasticChunks(
            SecondarySearchIndex::Steam,
            $total,
            $query,
            fn (SteamApp $s): array => array_merge(['id' => (int) $s->id], SecondaryIndexDocuments::steam($s))
        );
    }

    private function elasticAnime(): int
    {
        $ix = (string) config('search.drivers.elasticsearch.indexes.anime', 'anime');
        Search::truncateIndex([$ix]);
        $total = (int) DB::table('anidb_titles')->count();
        if (! $total) {
            $this->warn('anidb_titles table is empty. Nothing to do.');

            return Command::SUCCESS;
        }
        $query = DB::table('anidb_titles as t')
            ->leftJoin('anidb_info as i', 't.anidbid', '=', 'i.anidbid')
            ->orderBy('t.anidbid')
            ->select([
                't.anidbid', 't.type', 't.lang', 't.title',
                'i.anilist_id', 'i.mal_id', 'i.media_type', 'i.status',
            ]);

        return $this->processSecondaryElasticChunks(
            SecondarySearchIndex::Anime,
            $total,
            $query,
            function ($row) {
                $docId = SecondarySearchIndex::animeTitleDocumentId(
                    (int) $row->anidbid,
                    (string) $row->type,
                    (string) $row->lang,
                    (string) $row->title
                );

                return array_merge(['id' => $docId], [
                    'title' => (string) $row->title,
                    'anidbid' => (int) $row->anidbid,
                    'anilist_id' => (int) ($row->anilist_id ?? 0),
                    'mal_id' => (int) ($row->mal_id ?? 0),
                    'lang' => (string) $row->lang,
                    'title_type' => (string) $row->type,
                    'media_type' => (string) ($row->media_type ?? ''),
                    'status' => (string) ($row->status ?? ''),
                ]);
            }
        );
    }

    /**
     * @param  Builder|\Illuminate\Database\Query\Builder  $query
     */
    private function processSecondaryElasticChunks(SecondarySearchIndex $enum, int $total, mixed $query, callable $transformer): int
    {
        $chunkSize = $this->getChunkSize();
        $batchSize = $this->getBatchSize();

        $this->optimizeDatabase();
        $this->setGroupConcatMaxLen();

        $this->info(sprintf(
            'Populating Elasticsearch secondary index %s with %s rows.',
            $enum->value,
            number_format($total)
        ));

        $bar = $this->output->createProgressBar($total);
        $bar->setFormat('verbose');
        $bar->start();

        $processedCount = 0;
        $errorCount = 0;
        $batchData = [];

        try {
            $query->chunk($chunkSize, function ($items) use ($enum, $transformer, $bar, &$processedCount, &$errorCount, $batchSize, &$batchData) {
                foreach ($items as $item) {
                    try {
                        $batchData[] = $transformer($item);
                        $processedCount++;

                        if (count($batchData) >= $batchSize) {
                            Search::bulkInsertSecondary($enum, $batchData);
                            $batchData = [];
                        }
                    } catch (Exception $e) {
                        $errorCount++;
                        if ($this->output->isVerbose()) {
                            $this->error('Error processing secondary index row: '.$e->getMessage());
                        }
                    }
                    $bar->advance();
                }
            });

            if ($batchData !== []) {
                Search::bulkInsertSecondary($enum, $batchData);
            }

            $bar->finish();
            $this->newLine();

            return $errorCount > 0 ? Command::FAILURE : Command::SUCCESS;
        } catch (Exception $e) {
            $bar->finish();
            $this->newLine();
            $this->error('Failed to populate secondary Elasticsearch index: '.$e->getMessage());

            return Command::FAILURE;
        } finally {
            $this->restoreDatabase();
        }
    }

    /**
     * Process data for ElasticSearch with optimizations
     */
    private function processElasticData(string $indexName, int $total, mixed $query, callable $transformer): int
    {
        $chunkSize = $this->getChunkSize();
        $batchSize = $this->getBatchSize();

        $this->optimizeDatabase();
        $this->setGroupConcatMaxLen();

        $this->info(sprintf(
            "Populating ElasticSearch index '%s' with %s rows using chunks of %s and batch size of %s.",
            $indexName,
            number_format($total),
            number_format($chunkSize),
            number_format($batchSize)
        ));

        $bar = $this->output->createProgressBar($total);
        $bar->setFormat('verbose');
        $bar->start();

        $processedCount = 0;
        $errorCount = 0;

        try {
            $query->chunk($chunkSize, function ($items) use ($indexName, $transformer, $bar, &$processedCount, &$errorCount, $batchSize) {
                // Process in optimized batches for ElasticSearch
                foreach ($items->chunk($batchSize) as $batch) {
                    $data = ['body' => []];

                    foreach ($batch as $item) {
                        try {
                            $transformedData = $transformer($item);

                            $data['body'][] = [
                                'index' => [
                                    '_index' => $indexName,
                                    '_id' => $item->id,
                                ],
                            ];
                            $data['body'][] = $transformedData;

                            $processedCount++;
                        } catch (Exception $e) {
                            $errorCount++;
                            if ($this->output->isVerbose()) {
                                $this->error("Error processing item {$item->id}: {$e->getMessage()}");
                            }
                        }

                        $bar->advance();
                    }

                    if (! empty($data['body'])) {
                        $this->processElasticBatch($data, $errorCount);
                    }
                }
            });

            $bar->finish();
            $this->newLine();

            if ($errorCount > 0) {
                $this->warn("Completed with {$errorCount} errors out of {$processedCount} processed items.");
            } else {
                $this->info('ElasticSearch population completed successfully!');
            }

            return Command::SUCCESS;

        } catch (Exception $e) {
            $bar->finish();
            $this->newLine();
            $this->error("Failed to populate ElasticSearch: {$e->getMessage()}");

            return Command::FAILURE;
        } finally {
            $this->restoreDatabase();
        }
    }

    /**
     * Process search index batch with retry logic
     *
     * @param  array<string, mixed>  $data
     */
    private function processBatch(string $indexName, array $data): void
    {
        $retries = 3;
        $attempt = 0;

        while ($attempt < $retries) {
            try {
                // Use the correct bulk insert method based on index name
                $result = [];
                if ($indexName === (string) config('search.drivers.manticore.indexes.releases', 'releases_rt')) {
                    $result = Search::bulkInsertReleases($data);
                } elseif ($indexName === (string) config('search.drivers.manticore.indexes.predb', 'predb_rt')) {
                    $result = Search::bulkInsertPredb($data);
                } elseif ($indexName === (string) config('search.drivers.manticore.indexes.music', 'music_rt')) {
                    $result = Search::bulkInsertSecondary(SecondarySearchIndex::Music, $data);
                } elseif ($indexName === (string) config('search.drivers.manticore.indexes.books', 'books_rt')) {
                    $result = Search::bulkInsertSecondary(SecondarySearchIndex::Books, $data);
                } elseif ($indexName === (string) config('search.drivers.manticore.indexes.games', 'games_rt')) {
                    $result = Search::bulkInsertSecondary(SecondarySearchIndex::Games, $data);
                } elseif ($indexName === (string) config('search.drivers.manticore.indexes.console', 'console_rt')) {
                    $result = Search::bulkInsertSecondary(SecondarySearchIndex::Console, $data);
                } elseif ($indexName === (string) config('search.drivers.manticore.indexes.steam', 'steam_rt')) {
                    $result = Search::bulkInsertSecondary(SecondarySearchIndex::Steam, $data);
                } elseif ($indexName === (string) config('search.drivers.manticore.indexes.anime', 'anime_rt')) {
                    $result = Search::bulkInsertSecondary(SecondarySearchIndex::Anime, $data);
                } else {
                    throw new Exception("Unknown index: {$indexName}");
                }
                if (($result['errors'] ?? 0) > 0) {
                    throw new Exception('Bulk operation reported '.(int) $result['errors'].' error(s).');
                }
                break;
            } catch (Exception $e) {
                $attempt++;
                if ($attempt >= $retries) {
                    throw $e;
                }
                usleep(100000); // 100ms delay before retry
            }
        }
    }

    /**
     * Process movies batch with retry logic
     *
     * @param  array<string, mixed>  $data
     */
    private function processMoviesBatch(array $data): void
    {
        $retries = 3;
        $attempt = 0;

        while ($attempt < $retries) {
            try {
                Search::bulkInsertMovies($data);
                break;
            } catch (Exception $e) {
                $attempt++;
                if ($attempt >= $retries) {
                    throw $e;
                }
                usleep(100000); // 100ms delay before retry
            }
        }
    }

    /**
     * Process TV shows batch with retry logic
     *
     * @param  array<string, mixed>  $data
     */
    private function processTvShowsBatch(array $data): void
    {
        $retries = 3;
        $attempt = 0;

        while ($attempt < $retries) {
            try {
                Search::bulkInsertTvShows($data);
                break;
            } catch (Exception $e) {
                $attempt++;
                if ($attempt >= $retries) {
                    throw $e;
                }
                usleep(100000); // 100ms delay before retry
            }
        }
    }

    /**
     * Process ElasticSearch batch with retry logic
     *
     * @param  array<string, mixed>  $data
     */
    private function processElasticBatch(array $data, int &$errorCount): void
    {
        $retries = 3;
        $attempt = 0;

        while ($attempt < $retries) {
            try {
                $response = Elasticsearch::bulk($data);

                // Check for errors in bulk response
                if (isset($response['errors']) && $response['errors']) {
                    foreach ($response['items'] as $item) {
                        if (isset($item['index']['error'])) {
                            $errorCount++;
                            if ($this->output->isVerbose()) {
                                $this->error('ElasticSearch error: '.json_encode($item['index']['error']));
                            }
                        }
                    }
                }
                break;
            } catch (Exception $e) {
                $attempt++;
                if ($attempt >= $retries) {
                    throw $e;
                }
                usleep(100000); // 100ms delay before retry
            }
        }
    }

    /**
     * Optimize database settings for bulk operations
     */
    private function optimizeDatabase(): void
    {
        if ($this->option('disable-keys')) {
            $this->info('Disabling database keys for faster bulk operations...');

            try {
                // Disable foreign key checks
                DB::statement('SET FOREIGN_KEY_CHECKS = 0');
                DB::statement('SET UNIQUE_CHECKS = 0');
                DB::statement('SET AUTOCOMMIT = 0');

                // Increase session buffer sizes (innodb_buffer_pool_size is global-only)
                DB::statement('SET SESSION bulk_insert_buffer_size = 268435456'); // 256MB
                DB::statement('SET SESSION read_buffer_size = 2097152'); // 2MB
                DB::statement('SET SESSION sort_buffer_size = 16777216'); // 16MB

            } catch (Exception $e) {
                $this->warn("Could not optimize database settings: {$e->getMessage()}");
            }
        }
    }

    /**
     * Restore database settings after bulk operations
     */
    private function restoreDatabase(): void
    {
        if ($this->option('disable-keys')) {
            $this->info('Restoring database settings...');

            try {
                DB::statement('SET FOREIGN_KEY_CHECKS = 1');
                DB::statement('SET UNIQUE_CHECKS = 1');
                DB::statement('SET AUTOCOMMIT = 1');
                DB::statement('COMMIT');
            } catch (Exception $e) {
                $this->warn("Could not restore database settings: {$e->getMessage()}");
            }
        }
    }

    /**
     * Get the chunk size from options
     */
    private function getChunkSize(): int
    {
        $chunkSize = (int) $this->option('count');

        return $chunkSize > 0 ? $chunkSize : self::DEFAULT_CHUNK_SIZE;
    }

    /**
     * Get the batch size from options
     */
    private function getBatchSize(): int
    {
        $batchSize = (int) $this->option('batch-size');

        return $batchSize > 0 ? $batchSize : self::DEFAULT_BATCH_SIZE;
    }

    /**
     * Set the GROUP_CONCAT max length for the session
     */
    private function setGroupConcatMaxLen(): void
    {
        DB::statement('SET SESSION group_concat_max_len = ?', [self::GROUP_CONCAT_MAX_LEN]);
    }

    public static function useRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    /**
     * Clears the toggle and the cached library handle shared by the Wave 37.5 command helpers.
     */
    public static function resetRustFfi(): void
    {
        self::$useRustFfi = null;
        self::$ffi = null;
    }

    /**
     * Plans a population run from CLI flags without touching the database: the first engine flag
     * wins (manticore before elastic), requested index names are trimmed, lowercased and returned in
     * SUPPORTED_INDEXES order (`$all` selects every index), unknown names are counted and
     * non-positive sizes fall back to the defaults. `--optimize` takes precedence over population.
     *
     * @param  list<string>  $requested
     * @return array{mode: string, engine: ?string, indexes: list<string>, methods: list<string>, unknown: int, chunk_size: int, batch_size: int}
     */
    public static function planPopulation(bool $manticore, bool $elastic, array $requested, bool $all = false, int $count = self::DEFAULT_CHUNK_SIZE, int $batchSize = self::DEFAULT_BATCH_SIZE, bool $optimize = false): array
    {
        return self::usesRustFfi()
            ? self::planPopulationNative($manticore, $elastic, $requested, $all, $count, $batchSize, $optimize)
            : self::planPopulationPhp($manticore, $elastic, $requested, $all, $count, $batchSize, $optimize);
    }

    /**
     * @param  list<string>  $requested
     * @return array{mode: string, engine: ?string, indexes: list<string>, methods: list<string>, unknown: int, chunk_size: int, batch_size: int}
     */
    private static function planPopulationNative(bool $manticore, bool $elastic, array $requested, bool $all, int $count, int $batchSize, bool $optimize): array
    {
        $fields = self::evaluateNative(5, [
            $manticore ? '1' : '0', $elastic ? '1' : '0', $all ? '1' : '0', $optimize ? '1' : '0',
            (string) $count, (string) $batchSize, ...$requested,
        ]);
        $selected = $fields !== null && \count($fields) >= 7 ? (int) $fields[6] : -1;
        $methods = $fields !== null && $fields[0] === 'populate' ? $selected : 0;
        if ($fields === null || $selected < 0 || \count($fields) !== 7 + $selected + $methods) {
            return self::planPopulationPhp($manticore, $elastic, $requested, $all, $count, $batchSize, $optimize);
        }

        return [
            'mode' => $fields[0],
            'engine' => $fields[1] === '1' ? $fields[2] : null,
            'indexes' => \array_slice($fields, 7, $selected),
            'methods' => \array_slice($fields, 7 + $selected),
            'unknown' => (int) $fields[3],
            'chunk_size' => (int) $fields[4],
            'batch_size' => (int) $fields[5],
        ];
    }

    /**
     * @param  list<string>  $requested
     * @return array{mode: string, engine: ?string, indexes: list<string>, methods: list<string>, unknown: int, chunk_size: int, batch_size: int}
     */
    private static function planPopulationPhp(bool $manticore, bool $elastic, array $requested, bool $all, int $count, int $batchSize, bool $optimize): array
    {
        $engine = $manticore ? 'manticore' : ($elastic ? 'elastic' : null);
        $wanted = [];
        $unknown = 0;
        foreach ($requested as $name) {
            $name = strtolower(trim($name));
            if (\in_array($name, self::SUPPORTED_INDEXES, true)) {
                $wanted[$name] = true;
            } else {
                $unknown++;
            }
        }
        $indexes = $all
            ? self::SUPPORTED_INDEXES
            : array_values(array_filter(self::SUPPORTED_INDEXES, static fn (string $index): bool => isset($wanted[$index])));
        $mode = $optimize ? 'optimize' : ($engine !== null && $indexes !== [] ? 'populate' : 'invalid');

        return [
            'mode' => $mode,
            'engine' => $engine,
            'indexes' => $indexes,
            'methods' => $mode === 'populate' ? array_map(static fn (string $index): string => $engine.ucfirst($index), $indexes) : [],
            'unknown' => $unknown,
            'chunk_size' => $count > 0 ? $count : self::DEFAULT_CHUNK_SIZE,
            'batch_size' => $batchSize > 0 ? $batchSize : self::DEFAULT_BATCH_SIZE,
        ];
    }

    /**
     * Shared Wave 37.5 bridge for the search index and offset worker commands: count- and
     * length-prefixed (little-endian u32) packets preserve PHP byte strings. Returns the output parts,
     * or null when Rust declined the input or is unavailable.
     *
     * @param  list<string>  $parts
     * @return list<string>|null
     */
    public static function evaluateNative(int $mode, array $parts): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null || $mode < 0 || \count($parts) > self::NATIVE_MAX_PARTS) {
            return null;
        }

        try {
            $input = pack('V', \count($parts));
            foreach ($parts as $part) {
                $input .= pack('V', \strlen($part)).$part;
            }
            if (\strlen($input) > self::NATIVE_MAX_BYTES) {
                return null;
            }

            $minBytes = \strlen($input) * 2 + 1024;
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $capacity = 0;
                $out = RustPipelineLibrary::obtainScratch($ffi, $minBytes, $capacity);
                if ($out === null) {
                    return null;
                }
                $capacity = min($capacity, self::NATIVE_MAX_BYTES);
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w37_search_index_offset_worker_command($mode, $input, \strlen($input), $out, $capacity);
                if (! \is_int($written)) {
                    return null;
                }
                if ($written >= 4 && $written <= $capacity) {
                    return self::unpackNative(FFI::string($out, $written));
                }
                if ($written > -4 || -$written <= $capacity || -$written > self::NATIVE_MAX_BYTES) {
                    return null;
                }
                $minBytes = -$written;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /** @return list<string>|null */
    private static function unpackNative(string $packet): ?array
    {
        $length = \strlen($packet);
        $header = unpack('V', $packet);
        $count = \is_array($header) ? $header[1] : null;
        if (! \is_int($count) || $count > intdiv($length - 4, 4)) {
            return null;
        }

        $offset = 4;
        $parts = [];
        for ($i = 0; $i < $count; $i++) {
            if ($offset + 4 > $length) {
                return null;
            }
            $size = unpack('V', $packet, $offset);
            $size = \is_array($size) ? $size[1] : null;
            $offset += 4;
            if (! \is_int($size) || $size > $length - $offset) {
                return null;
            }
            $parts[] = substr($packet, $offset, $size);
            $offset += $size;
        }

        return $offset === $length ? $parts : null;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            self::$ffi = RustPipelineLibrary::resolveDomain('search_w375', self::FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
        }

        return self::$ffi instanceof FFI ? self::$ffi : null;
    }
}
