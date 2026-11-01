<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Facades\Search;
use App\Models\Predb;
use App\Models\Release;
use App\Services\Search\Drivers\ManticoreSearchDriver;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class NntmuxOffsetPopulate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nntmux:offset-populate
                                       {--manticore : Use ManticoreSearch}
                                       {--releases : Populates the releases index}
                                       {--predb : Populates the predb index}
                                       {--parallel=8 : Number of parallel processes}
                                       {--batch-size=10000 : Batch size for bulk operations}
                                       {--disable-keys : Disable database keys during population}
                                       {--memory-limit=4G : Memory limit for each process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Populate search indexes using offset-based parallel processing for maximum performance';

    private const SUPPORTED_ENGINES = ['manticore'];

    private const SUPPORTED_INDEXES = ['releases', 'predb'];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $engine = $this->getSelectedEngine();
        $index = $this->getSelectedIndex();

        if (! $engine || ! $index) {
            $this->error('You must specify both an engine (--manticore) and an index (--releases or --predb).');

            return Command::FAILURE;
        }

        $this->info("Starting offset-based parallel {$engine} {$index} population...");
        $startTime = microtime(true);

        $result = $this->populateIndexParallel($engine, $index);

        $executionTime = round(microtime(true) - $startTime, 2);
        if ($result === Command::SUCCESS) {
            $this->info("Offset-based parallel population completed in {$executionTime} seconds.");
        }

        return $result;
    }

    /**
     * Populate index using offset-based parallel processing
     */
    private function populateIndexParallel(string $engine, string $index): int
    {
        $total = $this->getTotalRecords($index);
        if (! $total) {
            $this->warn("{$index} table is empty. Nothing to do.");

            return Command::SUCCESS;
        }

        $parallelProcesses = (int) $this->option('parallel');
        $recordsPerProcess = ceil($total / $parallelProcesses);

        $this->info(sprintf(
            'Processing %s records with %d parallel processes, ~%d records per process',
            number_format($total),
            $parallelProcesses,
            $recordsPerProcess
        ));

        // Clear the index first
        $this->clearIndex($engine, $index);

        // Create offset-based ranges for parallel processing
        $ranges = $this->createOffsetRanges($total, $parallelProcesses);
        $processes = [];

        // Start parallel processes
        foreach ($ranges as $i => $range) {
            $command = $this->buildWorkerCommand($engine, $index, $range['offset'], $range['limit'], $i); // @phpstan-ignore argument.type
            $this->info("Starting worker process {$i}: processing {$range['limit']} records from offset {$range['offset']}");

            $process = Process::start($command);
            $processes[] = [
                'process' => $process,
                'id' => $i,
                'range' => $range,
            ];
        }

        // Monitor processes
        $this->monitorProcesses($processes); // @phpstan-ignore argument.type

        // Verify final count
        if (! $this->verifyIndexPopulation($engine, $index, $total)) {
            return Command::FAILURE;
        }

        $this->info('All parallel processes completed successfully!');

        return Command::SUCCESS;
    }

    /**
     * Create offset-based ranges for parallel execution
     *
     * @return array<string, mixed>
     */
    private function createOffsetRanges(int $total, int $processes): array
    {
        $ranges = [];
        $recordsPerProcess = ceil($total / $processes);

        for ($i = 0; $i < $processes; $i++) {
            $offset = $i * $recordsPerProcess;
            $limit = min($recordsPerProcess, $total - $offset);

            if ($limit > 0) {
                $ranges[] = [
                    'offset' => $offset,
                    'limit' => $limit,
                    'worker_id' => $i,
                ];
            }
        }

        return $ranges;
    }

    /**
     * Build worker command for offset-based parallel processing
     *
     * @return list<array<string, float|int<0, max>>>
     */
    private function buildWorkerCommand(string $engine, string $index, int $offset, int $limit, int $workerId): string
    {
        $memoryLimit = $this->option('memory-limit') ?? '4G';
        $batchSize = $this->option('batch-size');

        $artisanPath = base_path('artisan');

        $options = [
            "--{$engine}",
            "--{$index}",
            "--offset={$offset}",
            "--limit={$limit}",
            "--worker-id={$workerId}",
            "--batch-size={$batchSize}",
            "--memory-limit={$memoryLimit}",
        ];

        if ($this->option('disable-keys')) {
            $options[] = '--disable-keys';
        }

        return sprintf(
            'php -d memory_limit=%s "%s" nntmux:offset-worker %s 2>&1',
            $memoryLimit,
            $artisanPath,
            implode(' ', $options)
        );
    }

    /**
     * Monitor parallel processes
     *
     * @param  array<string, mixed>  $processes
     */
    private function monitorProcesses(array $processes): void
    {
        $bar = $this->output->createProgressBar(count($processes));
        $bar->setFormat('verbose');
        $bar->start();

        $completed = 0;
        $completedProcesses = [];
        $failedProcesses = [];

        while ($completed < count($processes)) {
            foreach ($processes as $index => $processInfo) {
                $process = $processInfo['process'];
                $processId = $processInfo['id'];

                if (in_array($processId, $completedProcesses)) {
                    continue;
                }

                if (! $process->running()) {
                    $completedProcesses[] = $processId;
                    $completed++;
                    $bar->advance();

                    try {
                        $result = $process->wait();
                        $exitCode = $result->exitCode();

                        if ($exitCode === 0) {
                            $this->info("Worker {$processId} completed successfully");
                        } else {
                            $failedProcesses[] = $processId;
                            $this->error("Worker {$processId} failed with exit code: {$exitCode}");

                            $output = $result->output();
                            $errorOutput = $result->errorOutput();

                            if ($output) {
                                $this->error("Worker {$processId} output: ".substr($output, -500));
                            }
                            if ($errorOutput) {
                                $this->error("Worker {$processId} error: ".substr($errorOutput, -500));
                            }
                        }
                    } catch (Exception $e) {
                        $failedProcesses[] = $processId;
                        $this->error("Worker {$processId} exception: {$e->getMessage()}");
                    }
                }
            }

            usleep(500000); // 0.5 second delay
        }

        $bar->finish();
        $this->newLine();

        if (! empty($failedProcesses)) {
            $this->error('Failed workers: '.implode(', ', $failedProcesses));
        }
    }

    /**
     * Verify index population
     */
    private function verifyIndexPopulation(string $engine, string $index, int $expectedTotal): bool
    {
        $this->info('Verifying index population...');

        $indexName = (string) config("search.drivers.manticore.indexes.{$index}", $index.'_rt');
        try {
            $actualCount = app(ManticoreSearchDriver::class)->countIndexDocuments($indexName);
            $this->info('Expected: '.number_format($expectedTotal));
            $this->info('Actual: '.number_format($actualCount));
            if ($actualCount < $expectedTotal) {
                $this->warn('Manticore index population is incomplete.');

                return false;
            }
        } catch (Exception $e) {
            $this->warn("Could not verify Manticore population: {$e->getMessage()}");

            return false;
        }

        return true;
    }

    /**
     * Clear the search index
     */
    private function clearIndex(string $engine, string $index): void
    {
        $indexName = (string) config("search.drivers.manticore.indexes.{$index}", $index.'_rt');
        Search::truncateIndex([$indexName]);
        $this->info("Truncated ManticoreSearch index: {$indexName}");
    }

    /**
     * Get total records
     */
    private function getTotalRecords(string $index): int
    {
        return $index === 'releases' ? Release::count() : Predb::count();
    }

    /**
     * Get selected engine
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
     * Get selected index
     */
    private function getSelectedIndex(): ?string
    {
        foreach (self::SUPPORTED_INDEXES as $index) {
            if ($this->option($index)) {
                return $index;
            }
        }

        return null;
    }
}
