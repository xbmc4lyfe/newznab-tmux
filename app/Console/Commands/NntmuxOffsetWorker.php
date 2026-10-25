<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Facades\Elasticsearch;
use App\Facades\Search;
use App\Models\Predb;
use App\Services\Search\Support\ReleaseIndexProjection;
use App\Support\PredbSearchDocument;
use App\Support\ReleaseSearchIndexDocument;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NntmuxOffsetWorker extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nntmux:offset-worker
                                       {--manticore : Use ManticoreSearch}
                                       {--elastic : Use ElasticSearch}
                                       {--releases : Populates the releases index}
                                       {--predb : Populates the predb index}
                                       {--offset=0 : Start offset}
                                       {--limit=0 : Number of records to process}
                                       {--worker-id=0 : Worker ID}
                                       {--batch-size=10000 : Batch size for bulk operations}
                                       {--disable-keys : Disable database keys}
                                       {--memory-limit=4G : Memory limit}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Offset-based worker process for parallel index population';

    private const GROUP_CONCAT_MAX_LEN = 65535;

    /**
     * Rust stays opt-in: the FFI round trip is slower than the PHP helper (< 1.5x); see
     * tests/Unit/RustSearchIndexOffsetWorkerCommandParityTest.php.
     */
    public const bool RUST_FFI_DEFAULT = false;

    private const int DEFAULT_BATCH_SIZE = 10000;

    private static ?bool $useRustFfi = null;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $workerId = (int) $this->option('worker-id');
        $offset = (int) $this->option('offset');
        $limit = (int) $this->option('limit');

        $this->info("Worker {$workerId} starting: processing {$limit} records from offset {$offset}");

        try {
            $this->optimizeWorkerSettings();

            $engine = $this->getSelectedEngine();
            $index = $this->getSelectedIndex();

            if (! $engine || ! $index) {
                $this->error('Engine and index must be specified');

                return Command::FAILURE;
            }

            $result = $this->processOffset($engine, $index, $offset, $limit, $workerId);

            if ($result === Command::SUCCESS) {
                $this->info("Worker {$workerId} completed successfully");
            }

            return $result;

        } catch (Exception $e) {
            $this->error("Worker {$workerId} failed: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }

    /**
     * Process a specific offset range of records
     */
    private function processOffset(string $engine, string $index, int $offset, int $limit, int $workerId): int
    {
        $batchSize = (int) $this->option('batch-size');
        $query = $this->buildOffsetQuery($index, $offset, $limit);
        $transformer = $this->getTransformer($engine, $index);

        $indexName = $this->getIndexName($engine, $index);
        $processed = 0;
        $errors = 0;

        $this->info("Worker {$workerId}: Processing {$limit} records with batch size {$batchSize}");
        $startTime = microtime(true);

        if ($engine === 'manticore') {
            $batchData = [];

            $query->chunk($batchSize, function ($items) use ($transformer, $index, &$processed, &$errors, &$batchData, $batchSize, $workerId) {
                $this->info("Worker {$workerId}: Processing chunk of {$items->count()} items");

                foreach ($items as $item) {
                    try {
                        $batchData[] = $transformer($item);
                        $processed++;

                        if (count($batchData) >= $batchSize) {
                            $this->processSearchBatch($batchData, $index, $workerId); // @phpstan-ignore argument.type
                            $this->info("Worker {$workerId}: Inserted batch of ".count($batchData).' records');
                            $batchData = [];
                        }
                    } catch (Exception $e) {
                        $errors++;
                        $this->error("Worker {$workerId}: Error processing item {$item->id}: {$e->getMessage()}");
                    }
                }
            });

            // Process remaining items
            if (! empty($batchData)) {
                $this->processSearchBatch($batchData, $index, $workerId); // @phpstan-ignore argument.type
                $this->info("Worker {$workerId}: Inserted final batch of ".count($batchData).' records');
            }

        } else { // ElasticSearch
            if ($index === 'predb') {
                Elasticsearch::indices()->putMapping([
                    'index' => $indexName,
                    'body' => ['properties' => PredbSearchDocument::elasticsearchExactMappings()],
                ]);
            }

            $query->chunk($batchSize, function ($items) use ($indexName, $transformer, &$processed, &$errors, $workerId) {
                $this->info("Worker {$workerId}: Processing chunk of {$items->count()} items");
                $data = ['body' => []];

                foreach ($items as $item) {
                    try {
                        $transformedData = $transformer($item);

                        $data['body'][] = [
                            'index' => [
                                '_index' => $indexName,
                                '_id' => $item->id,
                            ],
                        ];
                        $data['body'][] = $transformedData;

                        $processed++;
                    } catch (Exception $e) {
                        $errors++;
                        $this->error("Worker {$workerId}: Error processing item {$item->id}: {$e->getMessage()}");
                    }
                }

                if (! empty($data['body'])) {
                    $this->processElasticBatch($data, $workerId);
                    $this->info("Worker {$workerId}: Inserted batch of ".(count($data['body']) / 2).' records');
                }
            });
        }

        $executionTime = round(microtime(true) - $startTime, 2);
        $this->info("Worker {$workerId}: Completed processing {$processed} records with {$errors} errors in {$executionTime} seconds");

        return Command::SUCCESS;
    }

    /**
     * Build offset-based query
     */
    private function buildOffsetQuery(string $index, int $offset, int $limit): mixed
    {
        if ($index === 'releases') {
            return ReleaseIndexProjection::query()
                ->orderBy('r.id')
                ->offset($offset)
                ->limit($limit);
        } else {
            return Predb::query()
                ->select(['id', 'title', 'filename', 'source'])
                ->orderBy('id')
                ->offset($offset)
                ->limit($limit);
        }
    }

    /**
     * Get transformer function
     */
    private function getTransformer(string $engine, string $index): callable
    {
        if ($index === 'releases') {
            return function ($item) use ($engine): array {
                $row = (array) $item;

                return $engine === 'manticore'
                    ? ReleaseSearchIndexDocument::normalize($row)
                    : array_merge($row, [
                        'plainsearchname' => str_replace(['.', '-'], ' ', (string) ($item->searchname ?? '')),
                    ]);
            };
        } else { // predb
            return function ($item) use ($engine): array {
                $document = [
                    'id' => $item->id,
                    'title' => (string) ($item->title ?? ''),
                    'filename' => (string) ($item->filename ?? ''),
                    'source' => (string) ($item->source ?? ''),
                ];

                return $engine === 'elastic'
                    ? PredbSearchDocument::forElasticsearch($document)
                    : $document;
            };
        }
    }

    /**
     * Get index name
     */
    private function getIndexName(string $engine, string $index): string
    {
        if ($engine === 'manticore') {
            return (string) config("search.drivers.manticore.indexes.{$index}", $index.'_rt');
        } else {
            return (string) config("search.drivers.elasticsearch.indexes.{$index}", $index);
        }
    }

    /**
     * Process search batch
     *
     * @param  array<string, mixed>  $data
     */
    private function processSearchBatch(array $data, string $index, int $workerId): void
    {
        $retries = 3;
        $attempt = 0;

        while ($attempt < $retries) {
            try {
                $result = [];
                if ($index === 'releases') {
                    $result = Search::bulkInsertReleases($data);
                } else {
                    $result = Search::bulkInsertPredb($data);
                }
                if (($result['errors'] ?? 0) > 0) {
                    throw new Exception('Bulk operation reported '.(int) $result['errors'].' error(s).');
                }
                break;
            } catch (Exception $e) {
                $attempt++;
                if ($attempt >= $retries) {
                    throw new Exception("Worker {$workerId}: Failed to process search batch after {$retries} attempts: {$e->getMessage()}");
                }
                usleep(200000); // 200ms delay before retry
            }
        }
    }

    /**
     * Process ElasticSearch batch
     *
     * @param  array<string, mixed>  $data
     */
    private function processElasticBatch(array $data, int $workerId): void
    {
        $retries = 3;
        $attempt = 0;

        while ($attempt < $retries) {
            try {
                $response = Elasticsearch::bulk($data);

                if (isset($response['errors']) && $response['errors']) {
                    $errorCount = 0;
                    foreach ($response['items'] as $item) {
                        if (isset($item['index']['error'])) {
                            $errorCount++;
                        }
                    }
                    if ($errorCount > 0) {
                        $this->warn("Worker {$workerId}: ElasticSearch batch had {$errorCount} errors");
                    }
                }
                break;
            } catch (Exception $e) {
                $attempt++;
                if ($attempt >= $retries) {
                    throw new Exception("Worker {$workerId}: Failed to process ElasticSearch batch after {$retries} attempts: {$e->getMessage()}");
                }
                usleep(200000); // 200ms delay before retry
            }
        }
    }

    /**
     * Optimize worker settings
     */
    private function optimizeWorkerSettings(): void
    {
        // Set memory limit
        ini_set('memory_limit', $this->option('memory-limit'));

        // Database optimizations
        if ($this->option('disable-keys')) {
            try {
                DB::statement('SET SESSION FOREIGN_KEY_CHECKS = 0');
                DB::statement('SET SESSION UNIQUE_CHECKS = 0');
                DB::statement('SET SESSION AUTOCOMMIT = 0');
                DB::statement('SET SESSION read_buffer_size = 2097152');
                DB::statement('SET SESSION sort_buffer_size = 16777216');
            } catch (Exception $e) {
                $this->warn("Could not optimize database settings: {$e->getMessage()}");
            }
        }

        // Set GROUP_CONCAT max length
        DB::statement('SET SESSION group_concat_max_len = ?', [self::GROUP_CONCAT_MAX_LEN]);

        // PHP optimizations
        gc_enable();
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
        return $this->option('releases') ? 'releases' : ($this->option('predb') ? 'predb' : null);
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
     * Plans a worker run from its CLI options without touching the database: the engine and index
     * follow getSelectedEngine()/getSelectedIndex() precedence, negative offset, limit and worker ID
     * clamp to zero, a non-positive batch size falls back to 10000 and the end offset saturates at
     * PHP_INT_MAX. Batches use integer ceil division; the last batch is the remainder or a full batch.
     *
     * @return array{valid: bool, engine: ?string, index: ?string, offset: int, limit: int, end_offset: int, worker_id: int, batch_size: int, batches: int, last_batch: int, start_message: string}
     */
    public static function planWorkerRun(bool $manticore, bool $elastic, bool $releases, bool $predb, int $offset, int $limit, int $workerId = 0, int $batchSize = self::DEFAULT_BATCH_SIZE): array
    {
        return self::usesRustFfi()
            ? self::planWorkerRunNative($manticore, $elastic, $releases, $predb, $offset, $limit, $workerId, $batchSize)
            : self::planWorkerRunPhp($manticore, $elastic, $releases, $predb, $offset, $limit, $workerId, $batchSize);
    }

    /**
     * @return array{valid: bool, engine: ?string, index: ?string, offset: int, limit: int, end_offset: int, worker_id: int, batch_size: int, batches: int, last_batch: int, start_message: string}
     */
    private static function planWorkerRunNative(bool $manticore, bool $elastic, bool $releases, bool $predb, int $offset, int $limit, int $workerId, int $batchSize): array
    {
        $fields = NntmuxPopulateSearchIndexes::evaluateNative(4, [
            $manticore ? '1' : '0', $elastic ? '1' : '0', $releases ? '1' : '0', $predb ? '1' : '0',
            (string) $offset, (string) $limit, (string) $workerId, (string) $batchSize,
        ]);
        if ($fields === null || \count($fields) !== 13) {
            return self::planWorkerRunPhp($manticore, $elastic, $releases, $predb, $offset, $limit, $workerId, $batchSize);
        }

        return [
            'valid' => $fields[0] === '1',
            'engine' => $fields[1] === '1' ? $fields[2] : null,
            'index' => $fields[3] === '1' ? $fields[4] : null,
            'offset' => (int) $fields[5],
            'limit' => (int) $fields[6],
            'end_offset' => (int) $fields[7],
            'worker_id' => (int) $fields[8],
            'batch_size' => (int) $fields[9],
            'batches' => (int) $fields[10],
            'last_batch' => (int) $fields[11],
            'start_message' => $fields[12],
        ];
    }

    /**
     * @return array{valid: bool, engine: ?string, index: ?string, offset: int, limit: int, end_offset: int, worker_id: int, batch_size: int, batches: int, last_batch: int, start_message: string}
     */
    private static function planWorkerRunPhp(bool $manticore, bool $elastic, bool $releases, bool $predb, int $offset, int $limit, int $workerId, int $batchSize): array
    {
        $engine = $manticore ? 'manticore' : ($elastic ? 'elastic' : null);
        $index = $releases ? 'releases' : ($predb ? 'predb' : null);
        $offset = max(0, $offset);
        $limit = max(0, $limit);
        $workerId = max(0, $workerId);
        $batchSize = $batchSize > 0 ? $batchSize : self::DEFAULT_BATCH_SIZE;
        $remainder = $limit % $batchSize;

        return [
            'valid' => $engine !== null && $index !== null,
            'engine' => $engine,
            'index' => $index,
            'offset' => $offset,
            'limit' => $limit,
            'end_offset' => $limit > PHP_INT_MAX - $offset ? PHP_INT_MAX : $offset + $limit,
            'worker_id' => $workerId,
            'batch_size' => $batchSize,
            'batches' => intdiv($limit, $batchSize) + ($remainder !== 0 ? 1 : 0),
            'last_batch' => $limit === 0 ? 0 : ($remainder !== 0 ? $remainder : $batchSize),
            'start_message' => "Worker {$workerId} starting: processing {$limit} records from offset {$offset}",
        ];
    }
}
