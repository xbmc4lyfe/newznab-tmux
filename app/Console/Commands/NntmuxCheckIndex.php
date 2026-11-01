<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Search\Support\ManticoreClientFactory;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Console\Command;

class NntmuxCheckIndex extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nntmux:check-index
                                       {--manticore : Check ManticoreSearch}
                                       {--releases : Check the releases index}
                                       {--predb : Check the predb index}
                                       {--movies : Check the movies index}
                                       {--tvshows : Check the TV shows index}
                                       {--music : Check the music index}
                                       {--books : Check the books index}
                                       {--games : Check the games index}
                                       {--console : Check the console index}
                                       {--steam : Check the steam index}
                                       {--anime : Check the anime index}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check if data exists in search indexes';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $engine = $this->getSelectedEngine();
        $index = $this->getSelectedIndex();

        if (! $engine || ! $index) {
            $this->error('You must specify both an engine (--manticore) and an index option (e.g. --releases, --predb, --movies, --music, ...).');

            return Command::FAILURE;
        }

        $this->info("Checking {$engine} {$index} index...");

        try {
            $this->checkManticoreIndex($index);

            return Command::SUCCESS;
        } catch (Exception $e) {
            $this->error("Error checking index: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }

    /**
     * Check ManticoreSearch index
     */
    private function checkManticoreIndex(string $index): void
    {
        try {
            $indexName = match ($index) {
                'releases' => 'releases_rt',
                'predb' => 'predb_rt',
                'movies' => 'movies_rt',
                'tvshows' => 'tvshows_rt',
                'music' => 'music_rt',
                'books' => 'books_rt',
                'games' => 'games_rt',
                'console' => 'console_rt',
                'steam' => 'steam_rt',
                'anime' => 'anime_rt',
                default => 'releases_rt',
            };
            $manticoreConfig = config('search.drivers.manticore', []);
            $host = (string) ($manticoreConfig['host'] ?? '127.0.0.1');
            $port = (int) ($manticoreConfig['port'] ?? 9308);
            $scheme = (string) ($manticoreConfig['scheme'] ?? 'http');

            // Use HTTP API endpoint
            $baseUrl = "{$scheme}://{$host}:{$port}";

            // Check if table exists using HTTP API
            $client = new Client(ManticoreClientFactory::guzzleOptions($manticoreConfig, [
                'base_uri' => $baseUrl,
                'timeout' => 10,
                'http_errors' => false, // Don't throw on HTTP errors
            ]));

            // Use raw mode which seems to work
            $query = "SELECT COUNT(*) FROM {$indexName}";

            $response = $client->post('/sql?mode=raw', [
                'body' => $query,
                'headers' => [
                    'Content-Type' => 'text/plain',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->getBody()->getContents();

            if ($statusCode !== 200) {
                $this->error("HTTP Status: {$statusCode}");

                // Check if it's a table not found error
                if (str_contains($body, 'no such table') ||
                    str_contains($body, 'unknown table') ||
                    str_contains($body, "doesn't exist")) {
                    $this->error("ManticoreSearch table '{$indexName}' does not exist.");

                    return;
                }
                $this->error("Response: {$body}");

                return;
            }

            // Parse the JSON response
            $jsonData = json_decode($body, true);
            $docCount = 0;

            if ($jsonData !== null && isset($jsonData['data'])) {
                // ManticoreSearch returns data as array of arrays
                $docCount = $jsonData['data'][0]['COUNT(*)'] ?? $jsonData['data'][0][0] ?? 0;
            }

            $this->info("ManticoreSearch table '{$indexName}' exists.");
            $this->info('Document count: '.number_format($docCount));

            // Get a sample document if there are any
            if ($docCount > 0) {
                $sampleQuery = "SELECT * FROM {$indexName} LIMIT 1";

                $sampleResponse = $client->post('/sql?mode=raw', [
                    'body' => $sampleQuery,
                    'headers' => [
                        'Content-Type' => 'text/plain',
                    ],
                ]);

                if ($sampleResponse->getStatusCode() === 200) {
                    $sampleBody = $sampleResponse->getBody()->getContents();
                    $sampleJson = json_decode($sampleBody, true);

                    if ($sampleJson !== null && isset($sampleJson['data'][0])) {
                        $this->info('Sample document:');

                        $document = [];
                        $columns = $sampleJson['columns'] ?? [];
                        $data = $sampleJson['data'][0] ?? [];

                        foreach ($columns as $i => $column) {
                            // Extract column name from the nested structure
                            $columnName = array_keys($column)[0];
                            $document[$columnName] = $data[$i] ?? null;
                        }

                        $this->info(json_encode($document, JSON_PRETTY_PRINT));
                    }
                }
            }

            // Get table structure
            try {
                $describeQuery = "DESCRIBE {$indexName}";
                $describeResponse = $client->post('/sql?mode=raw', [
                    'body' => $describeQuery,
                    'headers' => [
                        'Content-Type' => 'text/plain',
                    ],
                ]);

                if ($describeResponse->getStatusCode() === 200) {
                    $describeBody = $describeResponse->getBody()->getContents();
                    $describeJson = json_decode($describeBody, true);

                    if ($describeJson !== null && isset($describeJson['data'])) {
                        $this->info("\nTable structure:");
                        $this->table(
                            ['Field', 'Type', 'Properties'],
                            array_map(function ($row) {
                                return [
                                    $row['Field'] ?? $row[0] ?? '',
                                    $row['Type'] ?? $row[1] ?? '',
                                    $row['Properties'] ?? $row[2] ?? '',
                                ];
                            }, $describeJson['data'])
                        );
                    }
                }
            } catch (Exception $e) {
                // Ignore describe errors
            }

        } catch (Exception $e) {
            $this->error("Error checking ManticoreSearch table: {$e->getMessage()}");
            if ($e instanceof RequestException && $e->hasResponse()) {
                $this->error('Response body: '.$e->getResponse()->getBody());
            }
        }
    }

    /**
     * Get selected engine
     */
    private function getSelectedEngine(): ?string
    {
        return $this->option('manticore') ? 'manticore' : null;
    }

    /**
     * Get selected index
     */
    private function getSelectedIndex(): ?string
    {
        $options = [
            'releases', 'predb', 'movies', 'tvshows', 'music', 'books', 'games', 'console', 'steam', 'anime',
        ];
        foreach ($options as $opt) {
            if ($this->option($opt)) {
                return $opt;
            }
        }

        return null;
    }
}
