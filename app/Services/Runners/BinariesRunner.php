<?php

declare(strict_types=1);

namespace App\Services\Runners;

use App\Services\Configuration\ConfigurationProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class BinariesRunner extends BaseRunner
{
    private const string ROTATION_KEY = 'binaries:rotation:last-group';

    /** @var array<string|int, int> */
    private array $scheduledGroups = [];

    public function binaries(int $maxPerGroup): void
    {
        $work = DB::select(
            sprintf(
                'SELECT name, %d AS max FROM usenet_groups WHERE active = 1',
                $maxPerGroup
            )
        );

        $maxProcesses = (int) app(ConfigurationProvider::class)->ingestion()->binaryThreads;

        $count = count($work);
        if ($count === 0) {
            $this->headerNone();

            return;
        }

        // Streaming mode
        if ((bool) config('nntmux.stream_fork_output', false) === true) {
            $commands = [];
            foreach ($work as $group) {
                $commands[] = [PHP_BINARY, base_path('artisan'), 'update:binaries', (string) $group->name, (string) $group->max];
            }
            $this->runStreamingCommands($commands, $maxProcesses, 'binaries');

            return;
        }

        $this->headerStart('binaries', $count, $maxProcesses);

        // Build commands array for parallel execution
        $commands = [];
        foreach ($work as $group) {
            $commands[$group->name] = [PHP_BINARY, base_path('artisan'), 'update:binaries', (string) $group->name, (string) $group->max];
        }

        // Process using parallel commands with configurable timeout
        $results = $this->runParallelCommands($commands, $maxProcesses);

        foreach ($results as $groupName => $output) {
            echo $output;
            cli()->primary('Updated group '.$groupName);
        }
    }

    public function safeBinaries(): void
    {
        // update group stats - Updated to use new script location (modernized)
        $this->executeCommand([PHP_BINARY, base_path('artisan'), 'groups:update']);

        $maxHeaders = (int) app(ConfigurationProvider::class)->ingestion()->maxHeadersPerIteration ?: 1000000;
        $maxMessages = (int) app(ConfigurationProvider::class)->ingestion()->maxMessages;

        // Prevent division by zero - ensure maxmssgs is at least 1
        if ($maxMessages < 1) {
            $defaultMaxMessages = 20000;
            cli()->warning('maxmssgs setting is invalid or not set, using default of '.$defaultMaxMessages);
            $maxMessages = $defaultMaxMessages;
        }

        $maxProcesses = (int) app(ConfigurationProvider::class)->ingestion()->binaryThreads;

        $groups = DB::select(
            '
            SELECT g.id, g.name AS groupname, g.last_record AS our_last,
                a.last_record AS their_last
            FROM usenet_groups g
            INNER JOIN short_groups a ON g.active = 1 AND g.name = a.name
            ORDER BY g.id'
        );

        if (empty($groups)) {
            $this->headerNone();

            return;
        }

        $lastGroup = (int) Cache::get(self::ROTATION_KEY, 0);
        $groups = [...array_filter($groups, static fn (object $group): bool => (int) $group->id > $lastGroup),
            ...array_filter($groups, static fn (object $group): bool => (int) $group->id <= $lastGroup)];
        $perGroup = [];
        foreach ($groups as $group) {
            $groupId = (int) $group->id;
            $perGroup[$groupId] = [];
            if ((int) $group->our_last === 0) {
                $perGroup[$groupId][] = [...$this->buildDnrCommand('update_group_headers  '.$group->groupname), '--max='.$maxMessages];
            } else {
                $count = $group->their_last - $group->our_last - 20000; // skip first 20k
                if ($count <= $maxMessages * 2) {
                    $perGroup[$groupId][] = [...$this->buildDnrCommand('update_group_headers  '.$group->groupname), '--max='.$maxMessages];
                } else {
                    $end = (int) $group->our_last + min($count, $maxHeaders);
                    for ($first = (int) $group->our_last + 1; $first <= $end; $first = $last + 1) {
                        $last = min($first + $maxMessages - 1, $end);
                        $perGroup[$groupId][] = [...$this->buildDnrCommand(sprintf('get_range  binaries  %s  %d  %d', $group->groupname, $first, $last)), '--contiguous',
                            ...($first === (int) $group->our_last + 1 ? ['--repair'] : [])];
                    }
                }
            }
        }

        // Interleave bounded work while the pool serializes commands belonging to one group.
        $commands = [];
        $this->scheduledGroups = [];
        $rounds = max(array_map(count(...), $perGroup));
        for ($round = 0; $round < $rounds; $round++) {
            foreach ($perGroup as $groupId => $groupCommands) {
                if (isset($groupCommands[$round])) {
                    $key = $groupId.':'.$round;
                    $commands[$key] = $groupCommands[$round];
                    $this->scheduledGroups[$key] = $groupId;
                }
            }
        }

        if ((bool) config('nntmux.stream_fork_output', false) === true) {
            $this->runStreamingCommands($commands, $maxProcesses, 'safe_binaries');

            return;
        }

        $this->headerStart('safe_binaries', count($commands), $maxProcesses);
        $this->runParallelCommandsWithCompletion($commands, $maxProcesses, static function (string|int $key, string $output): void {
            echo $output;
        });
    }

    protected function workerConcurrencyKey(string|int $key): string|int|null
    {
        return $this->scheduledGroups[$key] ?? null;
    }

    protected function workerStarting(string|int $key): void
    {
        if (isset($this->scheduledGroups[$key])) {
            Cache::forever(self::ROTATION_KEY, $this->scheduledGroups[$key]);
        }
    }
}
