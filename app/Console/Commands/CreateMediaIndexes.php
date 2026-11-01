<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Search\Support\ManticoreClientFactory;
use Illuminate\Console\Command;
use Manticoresearch\Client as ManticoreClient;
use Manticoresearch\Exceptions\ResponseException;

class CreateMediaIndexes extends Command
{
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

        if ($driver === 'manticore') {
            return $this->createManticoreIndexes();
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

        // Define movies and tvshows indexes
        $indexes = [
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
            // Check if the error is because the index already exists
            if (str_contains($e->getMessage(), 'already exists')) {
                $this->warn("Index {$indexName} already exists. Use --drop to recreate.");

                return true;
            }
            // Check for data_dir configuration error
            if (str_contains($e->getMessage(), 'data_dir')) {
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
        } catch (\Throwable $e) {
            $this->error("Unexpected error creating index {$indexName}: ".$e->getMessage());

            return false;
        }
    }
}
