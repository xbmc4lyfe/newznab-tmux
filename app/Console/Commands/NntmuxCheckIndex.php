<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Facades\Elasticsearch;
use App\Services\Search\Support\ElasticsearchResponseHelper;
use App\Services\Search\Support\ManticoreClientFactory;
use App\Support\RustPipelineLibrary;
use Elastic\Elasticsearch\Client as ElasticsearchClient;
use Exception;
use FFI;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Console\Command;
use Throwable;

class NntmuxCheckIndex extends Command
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_checkindex_format_bytes(int64_t bytes, char *out, size_t out_cap);
        int32_t nntmux_checkindex_table_code(const char *input, size_t len);
        C;

    private const array MANTICORE_TABLES = [
        'releases_rt', 'predb_rt', 'movies_rt', 'tvshows_rt', 'music_rt',
        'books_rt', 'games_rt', 'console_rt', 'steam_rt', 'anime_rt',
    ];

    private static FFI|false|null $checkIndexFfi = null;

    private static ?bool $useRustFfi = null;

    private static bool $adoptFormatBytesFfi = false;

    private static bool $adoptTableNameFfi = false;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nntmux:check-index
                                       {--manticore : Check ManticoreSearch}
                                       {--elastic : Check ElasticSearch}
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
            $this->error('You must specify both an engine (--manticore or --elastic) and an index option (e.g. --releases, --predb, --movies, --music, ...).');

            return Command::FAILURE;
        }

        $this->info("Checking {$engine} {$index} index...");

        try {
            if ($engine === 'elastic') {
                $this->checkElasticIndex($index);
            } else {
                $this->checkManticoreIndex($index);
            }

            return Command::SUCCESS;
        } catch (Exception $e) {
            $this->error("Error checking index: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }

    /**
     * Check ElasticSearch index
     */
    private function checkElasticIndex(string $index): void
    {
        $index = $this->elasticIndexName($index);

        try {
            // Check if index exists
            /** @var ElasticsearchClient $client */
            $client = app('elasticsearch');
            $exists = ElasticsearchResponseHelper::boolResponse($client, fn (ElasticsearchClient $elasticClient) => $elasticClient->indices()->exists(['index' => $index]));

            if (! $exists) {
                $this->error("ElasticSearch index '{$index}' does not exist.");

                return;
            }

            $this->info("ElasticSearch index '{$index}' exists.");

            // Get index stats
            $stats = Elasticsearch::indices()->stats(['index' => $index]);
            $docCount = $stats['indices'][$index]['total']['docs']['count'] ?? 0;
            $storeSize = $stats['indices'][$index]['total']['store']['size_in_bytes'] ?? 0;

            $this->info('Document count: '.number_format($docCount));
            $this->info('Index size: '.self::formatBytes($storeSize));

            // Get a sample document
            if ($docCount > 0) {
                $sample = Elasticsearch::search([
                    'index' => $index,
                    'size' => 1,
                    'body' => [
                        'query' => ['match_all' => (object) []],
                    ],
                ]);

                if (isset($sample['hits']['hits'][0])) {
                    $this->info('Sample document:');
                    $this->info(json_encode($sample['hits']['hits'][0]['_source'], JSON_PRETTY_PRINT));
                }
            }

        } catch (Exception $e) {
            $this->error("Error checking ElasticSearch index: {$e->getMessage()}");
        }
    }

    /**
     * Check ManticoreSearch index
     */
    private function checkManticoreIndex(string $index): void
    {
        try {
            $indexName = self::manticoreTableName($index);
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

    public static function setUseRustFfi(?bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::$adoptFormatBytesFfi;
    }

    public static function checkIndexFfi(): ?FFI
    {
        return self::resolveCheckIndexFfi();
    }

    /**
     * Manticore table for an index option; unknown options use the releases table.
     */
    public static function manticoreTableName(string $index): string
    {
        if (self::$useRustFfi ?? self::$adoptTableNameFfi) {
            $supported = false;
            $native = self::manticoreTableNameNative($index, $supported);
            if ($supported && $native !== null) {
                return $native;
            }
        }

        return self::manticoreTableNamePhp($index);
    }

    /**
     * @param-out bool $supported
     */
    public static function manticoreTableNameNative(string $index, bool &$supported = false): ?string
    {
        $supported = false;
        if (self::$useRustFfi === false) {
            return null;
        }
        $ffi = self::resolveCheckIndexFfi();
        if ($ffi === null) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $code = $ffi->nntmux_checkindex_table_code($index, \strlen($index));
        } catch (Throwable) {
            return null;
        }
        if (! \is_int($code) || ! isset(self::MANTICORE_TABLES[$code])) {
            return null;
        }
        $supported = true;

        return self::MANTICORE_TABLES[$code];
    }

    public static function manticoreTableNamePhp(string $index): string
    {
        return match ($index) {
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
    }

    /**
     * Format bytes to human readable format
     */
    public static function formatBytes(int $bytes): string
    {
        if (self::$useRustFfi ?? self::$adoptFormatBytesFfi) {
            $supported = false;
            $native = self::formatBytesNative($bytes, $supported);
            if ($supported && $native !== null) {
                return $native;
            }
        }

        return self::formatBytesPhp($bytes);
    }

    /**
     * Mirrors PHP 8.4+ `round()` (the locked platform) and float string conversion; values Rust cannot render as a
     * plain decimal defer to {@see formatBytesPhp()}.
     *
     * @param-out bool $supported
     */
    public static function formatBytesNative(int $bytes, bool &$supported = false): ?string
    {
        $supported = false;
        if (self::$useRustFfi === false) {
            return null;
        }
        $precision = (int) ini_get('precision');
        if ($precision !== 14 && $precision !== -1) {
            return null;
        }
        $ffi = self::resolveCheckIndexFfi();
        if ($ffi === null) {
            return null;
        }
        $capacity = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 64, $capacity);
        if ($scratch === null) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_checkindex_format_bytes($bytes, $scratch, $capacity);
        } catch (Throwable) {
            return null;
        }
        if (! \is_int($written) || $written <= 0) {
            return null;
        }
        $supported = true;

        return FFI::string($scratch, $written);
    }

    public static function formatBytesPhp(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, 2).' '.$units[$pow];
    }

    private static function resolveCheckIndexFfi(): ?FFI
    {
        if (self::$checkIndexFfi === null) {
            self::$checkIndexFfi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false;
        }

        return self::$checkIndexFfi === false ? null : self::$checkIndexFfi;
    }

    /**
     * Get selected engine
     */
    private function getSelectedEngine(): ?string
    {
        return $this->option('manticore') ? 'manticore' : ($this->option('elastic') ? 'elastic' : null);
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

    /**
     * Resolve ElasticSearch index name for logical keys.
     */
    private function elasticIndexName(string $index): string
    {
        $configured = config('search.drivers.elasticsearch.indexes.'.$index);

        return is_string($configured) && $configured !== '' ? $configured : $index;
    }
}
