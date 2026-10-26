<?php

declare(strict_types=1);

namespace App\Services\Runners;

use App\Models\Category;
use App\Models\Release;
use App\Services\AdditionalProcessing\AdditionalCandidateQuery;
use App\Services\AdditionalProcessing\AdditionalProcessingOrchestrator;
use App\Services\BookProcessingCandidateQuery;
use App\Services\Configuration\ConfigurationProvider;
use App\Services\NfoService;
use App\Services\TempWorkspaceService;
use App\Services\TvProcessing\TvProcessingCandidateQuery;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PostProcessRunner extends BaseRunner
{
    public const string W386_FFI_CDEF = 'ssize_t nntmux_w386_tmux_drain_stop_runners(uint8_t operation, uint8_t flags, const char *input, size_t input_len, char *out_buf, size_t out_cap);';

    // Paired caller benchmarks must qualify before enabling this separate candidate.
    private static ?bool $useRustW386 = null;

    public static function setUseRustW386(?bool $enabled): void
    {
        self::$useRustW386 = $enabled;
    }

    public static function usesRustW386(): bool
    {
        return self::$useRustW386 ?? false;
    }

    private static function w386Transform(int $flags, string $input): ?string
    {
        $length = strlen($input);
        if ($length > 16_777_216) {
            return null;
        }
        $ffi = RustPipelineLibrary::resolveDomain('additional_w386', self::W386_FFI_CDEF);
        if ($ffi === null) {
            return null;
        }
        try {
            $capacity = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, 256, $capacity);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w386_tmux_drain_stop_runners(4, $flags, $input, $length, $scratch, min($capacity, 16_777_216));
            if (is_int($written) && $written < -1 && -$written <= 16_777_216) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w386_tmux_drain_stop_runners(4, $flags, $input, $length, $scratch, min($capacity, 16_777_216));
            }
            if (! is_int($written) || $written < 0 || $written > min($capacity, 16_777_216)) {
                return null;
            }

            return $written === 0 ? '' : FFI::string($scratch, $written);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * First-byte GUID buckets, preserving input order, empties and duplicates.
     *
     * @param  list<string>  $ids
     * @return list<string>
     */
    public static function guidBucketPlan(array $ids): array
    {
        return (self::usesRustW386() ? self::guidBucketPlanNative($ids) : null)
            ?? self::guidBucketPlanPhp($ids);
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    public static function guidBucketPlanPhp(array $ids): array
    {
        return array_map(static fn (string $id): string => substr($id, 0, 1), $ids);
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>|null
     */
    public static function guidBucketPlanNative(array $ids): ?array
    {
        $input = pack('V', count($ids));
        foreach ($ids as $id) {
            $input .= pack('V', strlen($id)).$id;
        }
        $output = self::w386Transform(0, $input);
        if ($output === null || strlen($output) < 4) {
            return null;
        }
        $offset = 4;
        $buckets = [];
        foreach ($ids as $id) {
            if ($offset + 4 > strlen($output)) {
                return null;
            }
            /** @var array{size: int} $decoded */
            $decoded = unpack('Vsize', $output, $offset);
            $size = $decoded['size'];
            $offset += 4;
            if ($size > 1 || $offset + $size > strlen($output)) {
                return null;
            }
            $buckets[] = substr($output, $offset, $size);
            $offset += $size;
        }

        return $offset === strlen($output) ? $buckets : null;
    }

    public const int ADDITIONAL_WORKER_MAX_BATCHES = 4;

    private function guidBucketExpression(string $column = 'leftguid'): string
    {
        return self::usesRustFfi() ? $this->guidBucketExpressionNative($column) : $this->guidBucketExpressionPhp($column);
    }

    public function guidBucketExpressionNative(string $column = 'leftguid'): string
    {
        return self::runnerTransformNative(6, [DB::getDriverName(), $column]) ?? $this->guidBucketExpressionPhp($column);
    }

    public function guidBucketExpressionPhp(string $column = 'leftguid'): string
    {
        return DB::getDriverName() === 'sqlite'
            ? 'substr('.$column.', 1, 1)'
            : 'LEFT('.$column.', 1)';
    }

    /**
     * @param  array<int, object{id?: mixed}>  $releases
     */
    private function runPostProcess(array $releases, int $maxProcesses, string $type, string $desc): void
    {
        if (empty($releases)) {
            $this->headerNone();

            return;
        }

        // Additional post-processing can run for a long time per bucket. Always
        // stream it so tmux shows active parallel children instead of buffering
        // all output until the entire pool finishes.
        if ((bool) config('nntmux.stream_fork_output', false) === true || $type === 'additional') {
            $commands = [];
            foreach ($releases as $release) {
                // id may already be a single GUID bucket char; if not, take first char defensively
                $char = isset($release->id) ? substr((string) $release->id, 0, 1) : '';
                // Use postprocess:guid command which accepts the GUID character
                $command = [PHP_BINARY, base_path('artisan'), 'postprocess:guid', $type, $char];
                if ($type === 'additional') {
                    $command = [...$command, '--worker', '--max-batches='.self::ADDITIONAL_WORKER_MAX_BATCHES];
                }
                $commands[] = $command;
            }
            $this->runStreamingCommands($commands, $maxProcesses, $desc);

            return;
        }

        $count = count($releases);
        $this->headerStart('postprocess: '.$desc, $count, $maxProcesses);

        if ($count <= 1 || $maxProcesses <= 1) {
            foreach ($releases as $release) {
                $char = isset($release->id) ? substr((string) $release->id, 0, 1) : '';
                $command = [PHP_BINARY, base_path('artisan'), 'postprocess:guid', $type, $char];
                echo $this->executeCommand($command);
                cli()->primary('Finished task for '.$desc);
            }

            return;
        }

        $commands = [];
        foreach ($releases as $idx => $release) {
            $char = isset($release->id) ? substr((string) $release->id, 0, 1) : '';
            $commands[$idx] = [PHP_BINARY, base_path('artisan'), 'postprocess:guid', $type, $char];
        }

        try {
            $results = $this->runParallelCommands($commands, $maxProcesses, $this->concurrencyTimeout());

            foreach ($results as $output) {
                echo $output;
                cli()->primary('Finished task for '.$desc);
            }
        } catch (\Throwable $e) {
            Log::error('Postprocess batch failed: '.$e->getMessage());
            cli()->error('Postprocess batch failed: '.$e->getMessage());
            throw new RuntimeException('Worker batch failed.', 0, $e);
        }
    }

    /**
     * @param  array<int, object{type: string, id: string}>  $tasks
     */
    private function runPostProcessMixed(array $tasks, int $maxProcesses, string $desc): void
    {
        if ($tasks === []) {
            $this->headerNone();

            return;
        }

        if ((bool) config('nntmux.stream_fork_output', false) === true) {
            $commands = [];
            foreach ($tasks as $task) {
                $char = substr((string) $task->id, 0, 1);
                $commands[] = [PHP_BINARY, base_path('artisan'), 'postprocess:guid', $task->type, $char];
            }
            $this->runStreamingCommands($commands, $maxProcesses, $desc);

            return;
        }

        $count = count($tasks);
        $this->headerStart('postprocess: '.$desc, $count, $maxProcesses);

        if ($count <= 1 || $maxProcesses <= 1) {
            foreach ($tasks as $task) {
                $char = substr((string) $task->id, 0, 1);
                $command = [PHP_BINARY, base_path('artisan'), 'postprocess:guid', $task->type, $char];
                echo $this->executeCommand($command);
                cli()->primary('Finished task for '.$desc);
            }

            return;
        }

        $batches = array_chunk($tasks, max(1, $maxProcesses));

        foreach ($batches as $batchIndex => $batch) {
            $runTasks = [];
            foreach ($batch as $idx => $task) {
                $char = substr((string) $task->id, 0, 1);
                $command = [PHP_BINARY, base_path('artisan'), 'postprocess:guid', $task->type, $char];
                $runTasks[$idx] = $command;
            }

            try {
                $results = $this->runParallelCommands($runTasks, $maxProcesses);

                foreach ($results as $output) {
                    echo $output;
                    cli()->primary('Finished task for '.$desc);
                }
            } catch (\Throwable $e) {
                Log::error('Postprocess mixed batch failed: '.$e->getMessage());
                cli()->error('Batch '.($batchIndex + 1).' failed: '.$e->getMessage());
                throw new RuntimeException('Worker batch failed.', 0, $e);
            }
        }
    }

    /**
     * @return array<int, object{id: string}>
     */
    private function getBooksBuckets(): array
    {
        return self::usesRustFfi() ? $this->getBooksBucketsNative() : $this->getBooksBucketsPhp();
    }

    /** @return array<int, object{id: string}> */
    public function getBooksBucketsNative(): array
    {
        return DB::select($this->getBooksBucketsSqlNative());
    }

    /** @return array<int, object{id: string}> */
    public function getBooksBucketsPhp(): array
    {
        return DB::select($this->getBooksBucketsSqlPhp());
    }

    public function getBooksBucketsSqlPhp(): string
    {
        $bucketExpr = $this->guidBucketExpressionPhp();
        $workCondition = BookProcessingCandidateQuery::workCondition(
            (int) app(ConfigurationProvider::class)->metadata()->bookLookup->value
        );

        return '
            SELECT DISTINCT '.$bucketExpr.' AS id
            FROM releases
            WHERE '.$workCondition.'
            LIMIT 16';
    }

    public function getBooksBucketsSqlNative(): string
    {
        $bucketExpr = $this->guidBucketExpressionNative();
        $workCondition = BookProcessingCandidateQuery::workCondition(
            (int) app(ConfigurationProvider::class)->metadata()->bookLookup->value
        );

        $condition = $workCondition;

        return self::runnerTransformNative(7, [$bucketExpr, $condition]) ?? $this->getBooksBucketsSqlPhp();
    }

    /**
     * @return array<int, object{id: string}>
     */
    private function getMusicBuckets(): array
    {
        return self::usesRustFfi() ? $this->getMusicBucketsNative() : $this->getMusicBucketsPhp();
    }

    /** @return array<int, object{id: string}> */
    public function getMusicBucketsNative(): array
    {
        return DB::select($this->getMusicBucketsSqlNative());
    }

    /** @return array<int, object{id: string}> */
    public function getMusicBucketsPhp(): array
    {
        return DB::select($this->getMusicBucketsSqlPhp());
    }

    public function getMusicBucketsSqlPhp(): string
    {
        $bucketExpr = $this->guidBucketExpressionPhp();

        return '
            SELECT DISTINCT '.$bucketExpr.' AS id
            FROM releases
            WHERE categories_id IN ('.Category::MUSIC_MP3.', '.Category::MUSIC_LOSSLESS.', '.Category::MUSIC_OTHER.')
            AND musicinfo_id IS NULL
            AND '.Release::nzbSettledSql().'
            LIMIT 16';
    }

    public function getMusicBucketsSqlNative(): string
    {
        $bucketExpr = $this->guidBucketExpressionNative();

        $condition = 'categories_id IN ('.Category::MUSIC_MP3.', '.Category::MUSIC_LOSSLESS.', '.Category::MUSIC_OTHER.')
            AND musicinfo_id IS NULL
            AND '.Release::nzbSettledSql();

        return self::runnerTransformNative(7, [$bucketExpr, $condition]) ?? $this->getMusicBucketsSqlPhp();
    }

    /**
     * @return array<int, object{id: string}>
     */
    private function getConsoleBuckets(): array
    {
        return self::usesRustFfi() ? $this->getConsoleBucketsNative() : $this->getConsoleBucketsPhp();
    }

    /** @return array<int, object{id: string}> */
    public function getConsoleBucketsNative(): array
    {
        return DB::select($this->getConsoleBucketsSqlNative());
    }

    /** @return array<int, object{id: string}> */
    public function getConsoleBucketsPhp(): array
    {
        return DB::select($this->getConsoleBucketsSqlPhp());
    }

    public function getConsoleBucketsSqlPhp(): string
    {
        $bucketExpr = $this->guidBucketExpressionPhp();
        $renamedFilter = (int) app(ConfigurationProvider::class)->metadata()->gameLookup->value === 2 ? 'AND isrenamed = 1' : '';

        return '
            SELECT DISTINCT '.$bucketExpr.' AS id
            FROM releases
            WHERE categories_id BETWEEN '.Category::GAME_ROOT.' AND '.Category::GAME_OTHER.'
            AND consoleinfo_id IS NULL
            AND '.Release::nzbSettledSql().'
            '.$renamedFilter.'
            LIMIT 16';
    }

    public function getConsoleBucketsSqlNative(): string
    {
        $bucketExpr = $this->guidBucketExpressionNative();
        $renamedFilter = (int) app(ConfigurationProvider::class)->metadata()->gameLookup->value === 2 ? 'AND isrenamed = 1' : '';

        $condition = 'categories_id BETWEEN '.Category::GAME_ROOT.' AND '.Category::GAME_OTHER.'
            AND consoleinfo_id IS NULL
            AND '.Release::nzbSettledSql().'
            '.$renamedFilter;

        return self::runnerTransformNative(7, [$bucketExpr, $condition]) ?? $this->getConsoleBucketsSqlPhp();
    }

    /**
     * @return array<int, object{id: string}>
     */
    private function getGamesBuckets(): array
    {
        return self::usesRustFfi() ? $this->getGamesBucketsNative() : $this->getGamesBucketsPhp();
    }

    /** @return array<int, object{id: string}> */
    public function getGamesBucketsNative(): array
    {
        return DB::select($this->getGamesBucketsSqlNative());
    }

    /** @return array<int, object{id: string}> */
    public function getGamesBucketsPhp(): array
    {
        return DB::select($this->getGamesBucketsSqlPhp());
    }

    public function getGamesBucketsSqlPhp(): string
    {
        $bucketExpr = $this->guidBucketExpressionPhp();
        $renamedFilter = (int) app(ConfigurationProvider::class)->metadata()->gameLookup->value === 2 ? 'AND isrenamed = 1' : '';

        return '
            SELECT DISTINCT '.$bucketExpr.' AS id
            FROM releases
            WHERE categories_id = '.Category::PC_GAMES.'
            AND gamesinfo_id = 0
            AND '.Release::nzbSettledSql().'
            '.$renamedFilter.'
            LIMIT 16';
    }

    public function getGamesBucketsSqlNative(): string
    {
        $bucketExpr = $this->guidBucketExpressionNative();
        $renamedFilter = (int) app(ConfigurationProvider::class)->metadata()->gameLookup->value === 2 ? 'AND isrenamed = 1' : '';

        $condition = 'categories_id = '.Category::PC_GAMES.'
            AND gamesinfo_id = 0
            AND '.Release::nzbSettledSql().'
            '.$renamedFilter;

        return self::runnerTransformNative(7, [$bucketExpr, $condition]) ?? $this->getGamesBucketsSqlPhp();
    }

    public function processAdditional(): void
    {
        // Bucket-selection predicates and size filters are owned by
        // AdditionalCandidateQuery so they cannot drift away from the
        // per-worker fetch in AdditionalProcessingOrchestrator::fetchReleases().
        $bucketCounts = AdditionalCandidateQuery::availableBucketCounts();
        $chars = array_column($bucketCounts, 'bucket');

        $maxProcesses = (int) app(ConfigurationProvider::class)->postProcessing()->postThreads;
        $queryLimit = (int) (app(ConfigurationProvider::class)->postProcessing()->maxAdditionalProcessed ?: 25);

        // Normalize to the shape the rest of runPostProcess() expects:
        // an array of objects with an `id` (first GUID char) property. If the
        // backlog is concentrated in fewer buckets than configured threads,
        // repeat buckets so claimBatch() can split one hot bucket across
        // multiple workers.
        $queue = $this->additionalQueue($bucketCounts, $maxProcesses, $queryLimit);
        if (! $this->prepareAdditionalTempBuckets($chars)) {
            return;
        }

        if ($maxProcesses <= 1) {
            if ($queue === []) {
                $this->headerNone();

                return;
            }

            $this->headerStart('postprocess: additional postprocessing', count($queue), 1);
            $orchestrator = app(AdditionalProcessingOrchestrator::class);
            foreach ($chars as $char) {
                $workerToken = bin2hex(random_bytes(16));
                $excludedReleaseIds = [];
                try {
                    for ($batch = 0; $batch < self::ADDITIONAL_WORKER_MAX_BATCHES; $batch++) {
                        $result = $orchestrator->start('', $char, $workerToken, $excludedReleaseIds);
                        if ($result->claimedCount() === 0) {
                            break;
                        }
                        $excludedReleaseIds = array_values(array_unique([
                            ...$excludedReleaseIds,
                            ...$result->claimedIds,
                        ]));
                    }
                } finally {
                    $orchestrator->finish();
                }
                cli()->primary('Finished task for additional postprocessing');
            }

            return;
        }

        $this->runPostProcess($queue, $maxProcesses, 'additional', 'additional postprocessing');
    }

    /**
     * @param  list<array{bucket: string, count: int}>  $bucketCounts
     * @return list<object{id: string}>
     */
    private function additionalQueue(array $bucketCounts, int $maxProcesses, int $queryLimit): array
    {
        if ($bucketCounts === []) {
            return [];
        }

        $chars = array_column($bucketCounts, 'bucket');
        $queue = array_map(static fn (string $c): object => (object) ['id' => $c], $chars);
        $allocations = array_fill(0, count($bucketCounts), 1);
        $demand = [];
        foreach ($bucketCounts as $index => $bucketCount) {
            // Demand is intentionally measured in immediately claimable batches:
            // postthreads remains the hard connection cap while hot buckets use
            // available parallelism instead of draining all batches serially.
            $demand[$index] = max(1, (int) ceil($bucketCount['count'] / max(1, $queryLimit)));
        }

        $target = max(count($queue), min(max(1, $maxProcesses), array_sum($demand)));

        while (count($queue) < $target) {
            $nextIndex = null;
            $largestRemainingDemand = 0;

            foreach ($chars as $index => $char) {
                $remainingDemand = $demand[$index] - $allocations[$index];
                if ($remainingDemand > $largestRemainingDemand) {
                    $largestRemainingDemand = $remainingDemand;
                    $nextIndex = $index;
                }
            }

            if ($nextIndex === null) {
                break;
            }

            $queue[] = (object) ['id' => $chars[$nextIndex]];
            $allocations[$nextIndex]++;
        }

        return $queue;
    }

    /**
     * @param  list<string>  $chars
     */
    private function prepareAdditionalTempBuckets(array $chars): bool
    {
        if ($chars === []) {
            return true;
        }

        $tempWorkspace = app(TempWorkspaceService::class);
        $basePath = (string) config('nntmux.tmp_unrar_path');

        try {
            foreach ($chars as $char) {
                $bucketPath = $tempWorkspace->ensureMainTempPath($basePath, $char);
                $tempWorkspace->pruneStaleWorkerDirectories(
                    $bucketPath,
                    max(
                        3600,
                        (int) config('nntmux.multiprocessing_max_child_time', 1800) * 2,
                    ),
                );
            }
        } catch (\Throwable $e) {
            cli()->warning('Additional post-processing skipped: '.$e->getMessage());
            Log::error('Additional post-processing temp bucket preflight failed', [
                'tmp_unrar_path' => $basePath,
                'exception' => $e,
            ]);

            return false;
        }

        return true;
    }

    public function processNfo(): void
    {
        if ((int) app(ConfigurationProvider::class)->postProcessing()->lookupNfo !== 1) {
            $this->headerNone();

            return;
        }

        $nfoQuery = NfoService::NfoQueryString();

        $checkSql = 'SELECT r.id FROM releases r WHERE 1=1 '.$nfoQuery.' LIMIT 1';
        if (count(DB::select($checkSql)) === 0) {
            $this->headerNone();

            return;
        }

        $bucketExpr = $this->guidBucketExpression('r.leftguid');
        $sql = '
            SELECT DISTINCT '.$bucketExpr.' AS id
            FROM releases r
            WHERE 1=1 '.$nfoQuery.'
            LIMIT 16';
        $queue = DB::select($sql);

        $maxProcesses = (int) app(ConfigurationProvider::class)->postProcessing()->nfoThreads;
        $this->runPostProcess($queue, $maxProcesses, 'nfo', 'nfo postprocessing');
    }

    public function processMovies(bool $renamedOnly): void
    {
        if ((int) app(ConfigurationProvider::class)->metadata()->movieLookup->value <= 0) {
            $this->headerNone();

            return;
        }

        $condLookup = ((int) app(ConfigurationProvider::class)->metadata()->movieLookup->value === 2 ? 'AND isrenamed = 1' : '');
        $condRenamedOnly = ($renamedOnly ? 'AND isrenamed = 1' : '');

        $checkSql = '
            SELECT id
            FROM releases
            WHERE categories_id BETWEEN 2000 AND 2999
            AND '.imdb_id_needs_lookup_sql('imdbid').'
            AND '.Release::nzbSettledSql().'
            '.$condLookup.' '.$condRenamedOnly.'
            LIMIT 1';
        if (count(DB::select($checkSql)) === 0) {
            $this->headerNone();

            return;
        }

        $renamedFlag = ($renamedOnly ? 2 : 1);
        $bucketExpr = $this->guidBucketExpression();
        $sql = '
            SELECT DISTINCT '.$bucketExpr.' AS id, '.$renamedFlag.' AS renamed
            FROM releases
            WHERE categories_id BETWEEN 2000 AND 2999
            AND '.imdb_id_needs_lookup_sql('imdbid').'
            AND '.Release::nzbSettledSql().'
            '.$condLookup.' '.$condRenamedOnly.'
            LIMIT 16';
        $queue = DB::select($sql);

        $maxProcesses = (int) app(ConfigurationProvider::class)->postProcessing()->postThreadsNon;
        $this->runPostProcess($queue, $maxProcesses, 'movie', 'movies postprocessing');
    }

    public function processTv(bool $renamedOnly): void
    {
        $workCondition = $this->tvWorkCondition($renamedOnly);
        if ($workCondition === null || ! $this->hasTvWork($renamedOnly)) {
            $this->headerNone();

            return;
        }

        // Workers re-apply the configured "renamed only" mode themselves; flag 2 makes it explicit.
        $renamedFlag = ($renamedOnly || (int) app(ConfigurationProvider::class)->metadata()->tvLookup->value === 2) ? 2 : 1;
        $bucketExpr = $this->guidBucketExpression();
        $sql = '
            SELECT DISTINCT '.$bucketExpr.' AS id, '.$renamedFlag.' AS renamed
            FROM releases
            WHERE '.$workCondition.'
            LIMIT 16';
        $queue = DB::select($sql);

        $maxProcesses = (int) app(ConfigurationProvider::class)->postProcessing()->postThreadsNon;

        $this->runPostProcessTvPipeline($queue, $maxProcesses, 'tv postprocessing (pipelined)', $renamedOnly);
    }

    /**
     * SQL selecting the releases the TV workers will pick up, or null when TV lookups are disabled.
     */
    private function tvWorkCondition(bool $renamedOnly): ?string
    {
        return self::usesRustFfi() ? $this->tvWorkConditionNative($renamedOnly) : $this->tvWorkConditionPhp($renamedOnly);
    }

    public function tvWorkConditionNative(bool $renamedOnly): ?string
    {
        $lookupMode = (int) app(ConfigurationProvider::class)->metadata()->tvLookup->value;
        $mode = self::runnerTransformNative(8, [(string) $lookupMode, $renamedOnly ? '1' : '0']);
        if ($mode === null) {
            return $this->tvWorkConditionPhp($renamedOnly);
        }

        // Keep shared provider eligibility and schema-dependent policy in its existing owner.
        return $mode === '-1' ? null : TvProcessingCandidateQuery::workCondition((int) $mode);
    }

    public function tvWorkConditionPhp(bool $renamedOnly): ?string
    {
        $lookupMode = (int) app(ConfigurationProvider::class)->metadata()->tvLookup->value;
        if ($lookupMode <= 0) {
            return null;
        }

        return TvProcessingCandidateQuery::workCondition($renamedOnly ? 2 : $lookupMode);
    }

    /**
     * Run pipelined TV post-processing across multiple GUID buckets in parallel.
     * Each parallel process runs the full provider pipeline sequentially.
     *
     * @param  array<string, mixed>  $releases
     */
    private function runPostProcessTvPipeline(array $releases, int $maxProcesses, string $desc, bool $renamedOnly): void
    {
        if (empty($releases)) {
            $this->headerNone();

            return;
        }

        // If streaming is enabled, run commands with real-time output
        if ((bool) config('nntmux.stream_fork_output', false) === true) {
            $commands = [];
            foreach ($releases as $release) {
                $char = isset($release->id) ? substr((string) $release->id, 0, 1) : '';
                $renamed = isset($release->renamed) ? $release->renamed : '';
                $commands[] = [PHP_BINARY, base_path('artisan'), 'postprocess:tv-pipeline', $char, ...($renamed ? [(string) $renamed] : [])];
            }
            $this->runStreamingCommands($commands, $maxProcesses, $desc);

            return;
        }

        $count = count($releases);
        $this->headerStart('postprocess: '.$desc, $count, $maxProcesses);

        // Run bounded batches through the shared worker pool
        $batches = array_chunk($releases, max(1, $maxProcesses));

        foreach ($batches as $batchIndex => $batch) {
            $tasks = [];
            foreach ($batch as $idx => $release) {
                $char = isset($release->id) ? substr((string) $release->id, 0, 1) : '';
                $renamed = isset($release->renamed) ? $release->renamed : '';
                $command = [PHP_BINARY, base_path('artisan'), 'postprocess:tv-pipeline', $char, ...($renamed ? [(string) $renamed] : [])];
                $tasks[$idx] = $command;
            }

            try {
                $results = $this->runParallelCommands($tasks, $maxProcesses);

                foreach ($results as $taskIdx => $output) {
                    echo $output;
                    cli()->primary('Finished task for '.$desc);
                }
            } catch (\Throwable $e) {
                Log::error('TV pipeline batch failed: '.$e->getMessage());
                cli()->error('Batch '.($batchIndex + 1).' failed: '.$e->getMessage());
                throw new RuntimeException('Worker batch failed.', 0, $e);
            }
        }
    }

    /**
     * Lightweight check to determine if there is any TV work to process.
     */
    public function hasTvWork(bool $renamedOnly): bool
    {
        $workCondition = $this->tvWorkCondition($renamedOnly);

        return $workCondition !== null && DB::table('releases')->whereRaw($workCondition)->exists();
    }

    public function processAnime(): void
    {
        if ((int) app(ConfigurationProvider::class)->metadata()->animeLookup->value <= 0) {
            $this->headerNone();

            return;
        }

        $checkSql = '
            SELECT id
            FROM releases
            WHERE categories_id = 5070
            AND anidbid IS NULL
            AND '.Release::nzbSettledSql().'
            LIMIT 1';
        if (count(DB::select($checkSql)) === 0) {
            $this->headerNone();

            return;
        }

        $bucketExpr = $this->guidBucketExpression();
        $sql = '
            SELECT DISTINCT '.$bucketExpr.' AS id
            FROM releases
            WHERE categories_id = 5070
            AND anidbid IS NULL
            AND '.Release::nzbSettledSql().'
            LIMIT 16';
        $queue = DB::select($sql);

        $maxProcesses = (int) app(ConfigurationProvider::class)->postProcessing()->postThreadsNon;
        $this->runPostProcess($queue, $maxProcesses, 'anime', 'anime postprocessing');
    }

    public function processBooks(): void
    {
        if ((int) app(ConfigurationProvider::class)->metadata()->bookLookup->value <= 0) {
            $this->headerNone();

            return;
        }

        $queue = $this->getBooksBuckets();

        $maxProcesses = (int) app(ConfigurationProvider::class)->postProcessing()->postThreadsAmazon;
        $this->runPostProcess($queue, $maxProcesses, 'books', 'books postprocessing');
    }

    public function processMusic(): void
    {
        if ((int) app(ConfigurationProvider::class)->metadata()->musicLookup->value <= 0) {
            $this->headerNone();

            return;
        }

        $checkSql = '
            SELECT id
            FROM releases
            WHERE categories_id IN ('.Category::MUSIC_MP3.', '.Category::MUSIC_LOSSLESS.', '.Category::MUSIC_OTHER.')
            AND musicinfo_id IS NULL
            AND '.Release::nzbSettledSql().'
            LIMIT 1';
        if (count(DB::select($checkSql)) === 0) {
            $this->headerNone();

            return;
        }

        $queue = $this->getMusicBuckets();
        $maxProcesses = (int) app(ConfigurationProvider::class)->postProcessing()->postThreadsAmazon;
        $this->runPostProcess($queue, $maxProcesses, 'music', 'music postprocessing');
    }

    public function processConsoles(): void
    {
        if ((int) app(ConfigurationProvider::class)->metadata()->gameLookup->value <= 0) {
            $this->headerNone();

            return;
        }

        $renamedFilter = (int) app(ConfigurationProvider::class)->metadata()->gameLookup->value === 2 ? 'AND isrenamed = 1' : '';
        $checkSql = '
            SELECT id
            FROM releases
            WHERE categories_id BETWEEN '.Category::GAME_ROOT.' AND '.Category::GAME_OTHER.'
            AND consoleinfo_id IS NULL
            AND '.Release::nzbSettledSql().'
            '.$renamedFilter.'
            LIMIT 1';
        if (count(DB::select($checkSql)) === 0) {
            $this->headerNone();

            return;
        }

        $queue = $this->getConsoleBuckets();
        $maxProcesses = (int) app(ConfigurationProvider::class)->postProcessing()->postThreadsAmazon;
        $this->runPostProcess($queue, $maxProcesses, 'console', 'console postprocessing');
    }

    public function processGames(): void
    {
        if ((int) app(ConfigurationProvider::class)->metadata()->gameLookup->value <= 0) {
            $this->headerNone();

            return;
        }

        $renamedFilter = (int) app(ConfigurationProvider::class)->metadata()->gameLookup->value === 2 ? 'AND isrenamed = 1' : '';
        $checkSql = '
            SELECT id
            FROM releases
            WHERE categories_id = '.Category::PC_GAMES.'
            AND gamesinfo_id = 0
            AND '.Release::nzbSettledSql().'
            '.$renamedFilter.'
            LIMIT 1';
        if (count(DB::select($checkSql)) === 0) {
            $this->headerNone();

            return;
        }

        $queue = $this->getGamesBuckets();
        $maxProcesses = (int) app(ConfigurationProvider::class)->postProcessing()->postThreadsAmazon;
        $this->runPostProcess($queue, $maxProcesses, 'games', 'games postprocessing');
    }

    public function processAmazon(): void
    {
        $maxProcesses = (int) app(ConfigurationProvider::class)->postProcessing()->postThreadsAmazon;
        $tasks = [];

        if ((int) app(ConfigurationProvider::class)->metadata()->bookLookup->value > 0) {
            foreach ($this->getBooksBuckets() as $row) {
                $tasks[] = (object) ['type' => 'books', 'id' => (string) $row->id];
            }
        }

        if ((int) app(ConfigurationProvider::class)->metadata()->musicLookup->value > 0) {
            foreach ($this->getMusicBuckets() as $row) {
                $tasks[] = (object) ['type' => 'music', 'id' => (string) $row->id];
            }
        }

        if ((int) app(ConfigurationProvider::class)->metadata()->gameLookup->value > 0) {
            foreach ($this->getConsoleBuckets() as $row) {
                $tasks[] = (object) ['type' => 'console', 'id' => (string) $row->id];
            }

            foreach ($this->getGamesBuckets() as $row) {
                $tasks[] = (object) ['type' => 'games', 'id' => (string) $row->id];
            }
        }

        $this->runPostProcessMixed($tasks, $maxProcesses, 'amazon (books+music+console+games)');
    }

    // ========================================================================
    // WAVE 58.3 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 3.37x, p99 3.26x; uncommon mean 3.02x, p99 2.78x); see RustPostProcessReleasesRunnerDowntimeCompensatorTmuxCommandW58ParityTest. */
    public const bool W58_RUST_FFI_DEFAULT = true;

    public const string W58_POSTPROCESS_RUNNER_FFI_CDEF = <<<'C'
        ssize_t nntmux_w58_postprocess_runner_work_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** ASCII-lowercased postprocess mode => mode index of w58PostProcessWorkBatch(). */
    public const array W58_POSTPROCESS_MODES = ['additional' => 1, 'nfo' => 2, 'movie' => 3, 'tv' => 4, 'amazon' => 5];

    /** Base score per mode index of w58PostProcessWorkBatch(). */
    public const array W58_POSTPROCESS_MODE_WEIGHTS = [0, 40, 30, 50, 50, 20];

    /** Trailer words after the per-item words of w58PostProcessWorkBatch(). */
    public const int W58_POSTPROCESS_TRAILER = 25;

    public static function usesW58RustFfi(): bool
    {
        $override = self::w58RustFfiState();

        return \is_bool($override) ? $override : self::W58_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the Wave 58 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW58RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w58RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w58RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 58.3 postprocess work items `mode:guid:retries` (split as explode(':', $item, 3)). Per item
     * `mode | bucket << 3 | valid << 8 | numeric << 9 | retries << 10 | score << 17 | min(length, 1023) << 25`:
     * mode is the W58_POSTPROCESS_MODES index of the ASCII-lowercased first field (0 unknown), bucket the hex
     * value of the guid's first byte (16 when it is missing or not hex), valid a 40-byte hex guid, numeric an
     * all-digit retry field, retries its value (99 past two digits, 0 when not numeric) and score
     * max(0, mode weight + 20 valid + 5 hex bucket - 5 * min(retries, 10)). Then the six mode counts, the 17
     * bucket counts, the valid count and the max score.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w58PostProcessWorkBatch(array $items): array
    {
        return self::usesW58RustFfi() ? self::w58PostProcessWorkBatchNative($items) : self::w58PostProcessWorkBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w58PostProcessWorkBatchNative(array $items): array
    {
        return self::w58Invoke($items, \count($items) + self::W58_POSTPROCESS_TRAILER) ?? self::w58PostProcessWorkBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w58PostProcessWorkBatchPhp(array $items): array
    {
        $out = [];
        $modes = array_fill(0, 6, 0);
        $buckets = array_fill(0, 17, 0);
        $validCount = 0;
        $maxScore = 0;
        foreach ($items as $item) {
            $item = (string) $item;
            $parts = explode(':', $item, 3);
            $mode = self::W58_POSTPROCESS_MODES[strtolower($parts[0])] ?? 0;
            $guid = $parts[1] ?? '';
            $retry = $parts[2] ?? '';
            $bucket = $guid === '' ? false : strpos('0123456789abcdef', strtolower($guid[0]));
            $bucket = $bucket === false ? 16 : $bucket;
            $valid = \strlen($guid) === 40 && strspn($guid, '0123456789abcdefABCDEF') === 40;
            $numeric = $retry !== '' && strspn($retry, '0123456789') === \strlen($retry);
            $retries = $numeric ? (\strlen($retry) > 2 ? 99 : (int) $retry) : 0;
            $score = max(0, self::W58_POSTPROCESS_MODE_WEIGHTS[$mode] + ($valid ? 20 : 0) + ($bucket < 16 ? 5 : 0) - 5 * min($retries, 10));
            $modes[$mode]++;
            $buckets[$bucket]++;
            $validCount += $valid ? 1 : 0;
            $maxScore = max($maxScore, $score);
            $out[] = $mode | $bucket << 3 | ($valid ? 1 : 0) << 8 | ($numeric ? 1 : 0) << 9 | $retries << 10 | $score << 17
                | min(\strlen($item), 1023) << 25;
        }
        array_push($out, ...$modes);
        array_push($out, ...$buckets);
        array_push($out, $validCount, $maxScore);

        return $out;
    }

    /**
     * Wave 58 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'ffi' resolves the library (null when unavailable) and 'out' and
     * 'lens' return `int64_t` output and length-column buffers of at least $val words.
     */
    private static function w58RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('categorize_w58', self::W58_POSTPROCESS_RUNNER_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w58RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w58RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 58.3 export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w58Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w58RustFfiState('ffi');
            $out = self::w58RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w58RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W58_POSTPROCESS_RUNNER_FFI_CDEF.)
            $written = $ffi->nntmux_w58_postprocess_runner_work_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (\Throwable) {
            return null;
        }
    }
}
