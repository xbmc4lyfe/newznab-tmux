<?php

declare(strict_types=1);

namespace App\Services\Runners;

use App\Models\UsenetGroup;
use App\Services\Configuration\ConfigurationProvider;
use App\Services\NameFixing\NameFixingQueryService;
use App\Support\RustPipelineLibrary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ReleasesRunner extends BaseRunner
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
            $written = $ffi->nntmux_w386_tmux_drain_stop_runners(5, $flags, $input, $length, $scratch, min($capacity, 16_777_216));
            if (is_int($written) && $written < -1 && -$written <= 16_777_216) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w386_tmux_drain_stop_runners(5, $flags, $input, $length, $scratch, min($capacity, 16_777_216));
            }
            if (! is_int($written) || $written < 0 || $written > min($capacity, 16_777_216)) {
                return null;
            }

            return $written === 0 ? '' : \FFI::string($scratch, $written);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Unique ready-pass group IDs in first-insertion order, as in command keys.
     *
     * @param  list<int>  $groupIds
     * @return list<int>
     */
    public static function readyGroupPlan(array $groupIds): array
    {
        return (self::usesRustW386() ? self::readyGroupPlanNative($groupIds) : null)
            ?? self::readyGroupPlanPhp($groupIds);
    }

    /**
     * @param  list<int>  $groupIds
     * @return list<int>
     */
    public static function readyGroupPlanPhp(array $groupIds): array
    {
        $seen = [];
        foreach ($groupIds as $id) {
            $seen[$id] = true;
        }

        return array_keys($seen);
    }

    /**
     * @param  list<int>  $groupIds
     * @return list<int>|null
     */
    public static function readyGroupPlanNative(array $groupIds): ?array
    {
        $input = pack('V', count($groupIds));
        foreach ($groupIds as $id) {
            $input .= pack('q', $id);
        }
        $output = self::w386Transform(0, $input);
        if ($output === null || strlen($output) < 4 || (strlen($output) - 4) % 8 !== 0) {
            return null;
        }
        /** @var array<int, int> $decoded */
        $decoded = unpack('q*', substr($output, 4));

        return array_values($decoded);
    }

    /** Seconds between the starts of one group's passes while a round still waits on slower groups; 0 disables. */
    private int $rollingInterval = 0;

    public function releases(): void
    {
        $groups = DB::select('SELECT id, name FROM usenet_groups WHERE (active = 1 OR backfill = 1)');
        $maxProcesses = (int) app(ConfigurationProvider::class)->ingestion()->releaseThreads;

        $hasCollections = [];
        if ($groups !== []) {
            $groupIds = array_map(static fn (object $group): int => (int) $group->id, $groups);
            $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
            try {
                $presence = DB::select(
                    'SELECT id,
                        EXISTS (SELECT 1 FROM collections WHERE collections.groups_id = usenet_groups.id) AS has_collections
                     FROM usenet_groups WHERE id IN ('.$placeholders.')',
                    $groupIds
                );
                foreach ($presence as $group) {
                    $hasCollections[(int) $group->id] = (bool) $group->has_collections;
                }
            } catch (\PDOException) {
                // Preserve per-group recovery when the batched collection lookup fails.
                $hasCollections = null;
            }
        }

        $uGroups = [];
        foreach ($groups as $group) {
            try {
                $hasCollection = $hasCollections === null
                    ? ! empty(DB::select(sprintf('SELECT id FROM collections WHERE groups_id = %d LIMIT 1', $group->id)))
                    : ($hasCollections[(int) $group->id] ?? false);
                if ($hasCollection) {
                    $uGroups[] = ['id' => $group->id, 'name' => $group->name];
                }
            } catch (\PDOException $e) {
                if (config('app.debug') === true) {
                    Log::debug($e->getMessage());
                }
            }
        }

        $count = count($uGroups);
        if ($count === 0) {
            $this->headerNone();

            return;
        }

        $streaming = (bool) config('nntmux.stream_fork_output', false);
        $this->runReadyReleasePass(array_map(static fn (array $group): int => (int) $group['id'], $uGroups), $maxProcesses, $streaming);

        // Streaming mode
        if ((bool) config('nntmux.stream_fork_output', false) === true) {
            $commands = [];
            foreach ($uGroups as $group) {
                $commands[$group['id']] = $this->buildDnrCommand('releases  '.$group['id']);
            }
            $this->rolling(fn () => $this->runStreamingCommands($commands, $maxProcesses, 'releases'));

            return;
        }

        $this->headerStart('releases', $count, $maxProcesses);

        $tasks = [];
        foreach ($uGroups as $group) {
            $tasks[$group['id']] = $this->buildDnrCommand('releases  '.$group['id']);
        }

        try {
            $this->rolling(fn () => $this->runParallelCommandsWithCompletion($tasks, $maxProcesses, static function (int|string $groupId, string $output): void {
                echo $output;
                cli()->primary('Finished performing release processing for group ID: '.$groupId);
            }));
        } catch (\Throwable $e) {
            Log::error('Release processing batch failed: '.$e->getMessage());
            cli()->error('Release processing failed: '.$e->getMessage());
            throw new RuntimeException('Worker batch failed.', 0, $e);
        }
    }

    public function updatePerGroup(): void
    {
        $groups = DB::select('SELECT id , name FROM usenet_groups WHERE (active = 1 OR backfill = 1)');
        $maxProcesses = (int) app(ConfigurationProvider::class)->ingestion()->releaseThreads;

        $count = count($groups);
        if ($count === 0) {
            $this->headerNone();

            return;
        }

        $streaming = (bool) config('nntmux.stream_fork_output', false);
        $this->runReadyReleasePass(array_map(static fn (object $group): int => (int) $group->id, $groups), $maxProcesses, $streaming);

        // Streaming mode
        if ((bool) config('nntmux.stream_fork_output', false) === true) {
            $commands = [];
            foreach ($groups as $group) {
                $commands[] = $this->buildDnrCommand('update_per_group  '.$group->id);
            }
            $this->runStreamingCommands($commands, $maxProcesses, 'update_per_group');

            return;
        }

        $this->headerStart('update_per_group', $count, $maxProcesses);

        $tasks = [];
        foreach ($groups as $group) {
            $tasks[$group->id] = $this->buildDnrCommand('update_per_group  '.$group->id);
        }

        try {
            $this->runParallelCommandsWithCompletion($tasks, $maxProcesses, static function (int|string $groupId, string $output): void {
                echo $output;
                $name = UsenetGroup::getNameByID($groupId);
                cli()->primary('Finished updating binaries, processing releases and additional postprocessing for group: '.$name);
            });
        } catch (\Throwable $e) {
            Log::error('Update per group batch failed: '.$e->getMessage());
            cli()->error('Update per group failed: '.$e->getMessage());
            throw new RuntimeException('Worker batch failed.', 0, $e);
        }
    }

    /**
     * Run a round's full passes so a group whose pass finishes starts another one while slower
     * groups are still working, instead of idling until the round's slowest group ends.
     */
    private function rolling(callable $round): void
    {
        $this->rollingInterval = max(0, (int) config('nntmux.cbp.release_rolling_interval', 60));
        try {
            $round();
        } finally {
            $this->rollingInterval = 0;
        }
    }

    protected function workerFollowUp(string|int $key, float $startedAt): ?array
    {
        return self::usesRustFfi() ? $this->workerFollowUpNative($key, $startedAt) : $this->workerFollowUpPhp($key, $startedAt);
    }

    /** @return array{command: list<string>, not_before: float}|null */
    public function workerFollowUpNative(string|int $key, float $startedAt): ?array
    {
        if ($this->rollingInterval === 0) {
            return null;
        }
        $deadline = self::followUpDeadlineNative($startedAt, $this->rollingInterval);
        if ($deadline === null) {
            return $this->workerFollowUpPhp($key, $startedAt);
        }

        return ['command' => $this->buildDnrCommand('releases  '.$key), 'not_before' => $deadline];
    }

    /** @return array{command: list<string>, not_before: float}|null */
    public function workerFollowUpPhp(string|int $key, float $startedAt): ?array
    {
        if ($this->rollingInterval === 0) {
            return null;
        }

        return [
            'command' => $this->buildDnrCommand('releases  '.$key),
            'not_before' => $startedAt + $this->rollingInterval,
        ];
    }

    /** @param list<int> $groupIds */
    private function runReadyReleasePass(array $groupIds, int $maxProcesses, bool $streaming): void
    {
        $commands = [];
        foreach ($groupIds as $groupId) {
            $commands[$groupId] = [...$this->buildDnrCommand('releases  '.$groupId), '--ready-only'];
        }
        try {
            if ($streaming) {
                $this->runStreamingCommands($commands, $maxProcesses, 'ready_releases');
            } else {
                $this->headerStart('ready releases', count($commands), $maxProcesses);
                $this->runParallelCommandsWithCompletion($commands, $maxProcesses, static function (int|string $groupId, string $output): void {
                    echo $output;
                    cli()->primary('Finished ready release processing for group ID: '.$groupId);
                });
            }
            if ($this->hasCancellationRequest()) {
                throw new RuntimeException('Ready release pass stopped by cancellation.');
            }
        } catch (\Throwable $exception) {
            if ($this->hasCancellationRequest()
                || array_filter($this->workerOutcomes, static fn (array $outcome): bool => $outcome['status'] === 'cancellation') !== []) {
                throw $exception;
            }
            Log::error('Ready release pass failed: '.$exception->getMessage());

            return;
        }
    }

    public function fixRelNames(string $mode, int $maxPerRun, int $maxThreads): void
    {
        $maxThreads = max(1, min(16, $maxThreads));

        $leftGuids = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', 'a', 'b', 'c', 'd', 'e', 'f'];

        if ($mode === 'predbft') {
            $preCount = app(NameFixingQueryService::class)->countPredbLauncherCandidates($maxPerRun, count($leftGuids));
            if ($preCount > 0 && $maxPerRun > 0) {
                $workers = intdiv($preCount, $maxPerRun) + ($preCount % $maxPerRun === 0 ? 0 : 1);
                $leftGuids = \array_slice($leftGuids, 0, $workers);
            } else {
                $leftGuids = [];
            }
        }

        $queues = [];
        $idx = 0;
        $workerCount = count($leftGuids);
        foreach ($leftGuids as $leftGuid) {
            if ($maxPerRun > 0) {
                $idx++;
                $queues[$idx] = sprintf('%s %s %s %s %s', $mode, $leftGuid, $maxPerRun, $idx, $workerCount);
            }
        }

        $count = count($queues);
        if ($count === 0) {
            $this->headerNone();

            return;
        }

        // Streaming mode
        if ((bool) config('nntmux.stream_fork_output', false) === true) {
            $commands = [];
            foreach ($queues as $queue) {
                // Updated to use new script location (modernized)
                $commands[] = [PHP_BINARY, base_path('app/Services/Tmux/Scripts/groupfixrelnames.php'), $queue];
            }
            $this->runStreamingCommands($commands, $maxThreads, 'fixRelNames_'.$mode);

            return;
        }

        $this->headerStart('fixRelNames_'.$mode, $count, $maxThreads);

        // Run bounded batches through the shared worker pool
        $batches = array_chunk($queues, max(1, $maxThreads), true);

        foreach ($batches as $batchIndex => $batch) {
            $tasks = [];
            foreach ($batch as $idx => $queue) {
                // Updated to use new script location (modernized)
                $command = [PHP_BINARY, base_path('app/Services/Tmux/Scripts/groupfixrelnames.php'), $queue];
                $tasks[$idx] = $command;
            }

            try {
                $results = $this->runParallelCommands($tasks, $maxThreads);

                foreach ($results as $taskIdx => $output) {
                    echo $output;
                    cli()->primary('Task #'.$taskIdx.' Finished fixing releases names');
                }
            } catch (\Throwable $e) {
                Log::error('Fix rel names batch failed: '.$e->getMessage());
                cli()->error('Batch '.($batchIndex + 1).' failed: '.$e->getMessage());
                throw new RuntimeException('Worker batch failed.', 0, $e);
            }
        }
    }
}
