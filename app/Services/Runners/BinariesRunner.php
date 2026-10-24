<?php

declare(strict_types=1);

namespace App\Services\Runners;

use App\Services\Configuration\ConfigurationProvider;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class BinariesRunner extends BaseRunner
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_runner_release_transform(uint32_t mode, const char *input, size_t len, char *out, size_t cap);
        C;

    // Retain PHP until common/uncommon mean and p99 all demonstrate a 1.5x gain.
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(?bool $enabled): void
    {
        self::$useRustFfi = $enabled ?? false;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    /** @param list<string> $fields
     * @return list<string>|null
     */
    private static function cleaningNative(int $mode, array $fields): ?array
    {
        try {
            self::$ffi ??= RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF) ?? false;
            if (self::$ffi === false) {
                return null;
            }
            $input = '';
            foreach ($fields as $field) {
                if (strlen($field) > 0xFFFFFFFF) {
                    return null;
                }
                $input .= pack('V', strlen($field)).$field;
            }
            for ($cap = max(256, strlen($input) * 2); $cap <= 16777216; $cap *= 2) {
                $out = self::$ffi->new('char['.$cap.']');
                // @phpstan-ignore method.notFound
                $written = self::$ffi->nntmux_runner_release_transform($mode, $input, strlen($input), $out, $cap);
                if ($written === -2) {
                    continue;
                }
                if (! is_int($written) || $written < 0 || $written > $cap) {
                    return null;
                }
                $bytes = $written === 0 ? '' : FFI::string($out, $written);
                $result = [];
                for ($offset = 0; $offset < $written;) {
                    if ($written - $offset < 4) {
                        return null;
                    }
                    $length = unpack('Vlength', substr($bytes, $offset, 4))['length'];
                    $offset += 4;
                    if ($length > $written - $offset) {
                        return null;
                    }
                    $result[] = substr($bytes, $offset, $length);
                    $offset += $length;
                }

                return $result;
            }
        } catch (\Throwable) {
            // Missing symbols, unavailable FFI and declined inputs retain PHP behavior.
        }

        return null;
    }

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
        // Current contiguous workers own their first-range repair. Standalone repair
        // specifications remain available to callers using the legacy total field.
        $perGroup = $this->planSafeBinariesCommands($groups, false, $maxHeaders, $maxMessages);
        $plan = $this->interleaveScheduledGroupCommands($perGroup);
        $commands = $plan['commands'];
        $this->scheduledGroups = $plan['scheduledGroups'];

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
        return self::usesRustFfi() ? $this->workerConcurrencyKeyNative($key) : $this->workerConcurrencyKeyPhp($key);
    }

    /** @param array<array-key, object> $groups
     * @return array<int, list<list<string>>>
     */
    public function planSafeBinariesCommands(array $groups, bool $partRepair, int $maxHeaders, int $maxMessages): array
    {
        return self::usesRustFfi()
            ? $this->planSafeBinariesCommandsNative($groups, $partRepair, $maxHeaders, $maxMessages)
            : $this->planSafeBinariesCommandsPhp($groups, $partRepair, $maxHeaders, $maxMessages);
    }

    /** @param array<array-key, object> $groups
     * @return array<int, list<list<string>>>
     */
    public function planSafeBinariesCommandsPhp(array $groups, bool $partRepair, int $maxHeaders, int $maxMessages): array
    {
        $maxMessages = $maxMessages < 1 ? 20000 : $maxMessages;
        $perGroup = [];
        foreach ($groups as $group) {
            $id = (int) $group->id;
            $perGroup[$id] = [];
            if ((int) $group->our_last === 0 || ($count = $group->their_last - $group->our_last - 20000) <= $maxMessages * 2) {
                $perGroup[$id][] = [...$this->buildDnrCommand('update_group_headers  '.$group->groupname), '--max='.$maxMessages];
            } else {
                $end = (int) $group->our_last + min($count, $maxHeaders);
                for ($first = (int) $group->our_last + 1; $first <= $end; $first = $last + 1) {
                    $last = min($first + $maxMessages - 1, $end);
                    $perGroup[$id][] = [...$this->buildDnrCommand(sprintf('get_range  binaries  %s  %d  %d', $group->groupname, $first, $last)), '--contiguous', ...($first === (int) $group->our_last + 1 ? ['--repair'] : [])];
                }
            }
            if ($partRepair && (int) ($group->total ?? 0) > 0) {
                $perGroup[$id][] = $this->buildDnrCommand('part_repair  '.$group->groupname);
            }
        }

        return $perGroup;
    }

    /** @param array<array-key, object> $groups
     * @return array<int, list<list<string>>>
     */
    public function planSafeBinariesCommandsNative(array $groups, bool $partRepair, int $maxHeaders, int $maxMessages): array
    {
        $fields = [(string) (int) $partRepair, (string) $maxHeaders, (string) $maxMessages];
        $plan = [];
        foreach ($groups as $group) {
            $id = (int) $group->id;
            if (array_key_exists($id, $plan)) {
                // PHP replaces an earlier row for a repeated group ID.
                return $this->planSafeBinariesCommandsPhp($groups, $partRepair, $maxHeaders, $maxMessages);
            }
            $plan[$id] = [];
            array_push($fields, (string) $id, (string) $group->groupname, (string) (int) $group->our_last, (string) $group->their_last, (string) (int) ($group->total ?? 0));
        }
        $rows = self::cleaningNative(0, $fields);
        if ($rows === null || count($rows) % 3 !== 0) {
            return $this->planSafeBinariesCommandsPhp($groups, $partRepair, $maxHeaders, $maxMessages);
        }
        foreach (array_chunk($rows, 3) as [$id, $command, $options]) {
            $plan[(int) $id][] = [...$this->buildDnrCommand($command), ...($options === '' ? [] : explode("\0", $options))];
        }

        return $plan;
    }

    /** @param array<int, list<list<string>>> $perGroup
     * @return array{commands: array<string, list<string>>, scheduledGroups: array<string, int>}
     */
    public function interleaveScheduledGroupCommands(array $perGroup): array
    {
        return self::usesRustFfi() ? $this->interleaveScheduledGroupCommandsNative($perGroup) : $this->interleaveScheduledGroupCommandsPhp($perGroup);
    }

    /** @param array<int, list<list<string>>> $perGroup
     * @return array{commands: array<string, list<string>>, scheduledGroups: array<string, int>}
     */
    public function interleaveScheduledGroupCommandsPhp(array $perGroup): array
    {
        $commands = $scheduledGroups = [];
        $rounds = $perGroup === [] ? 0 : max(array_map(count(...), $perGroup));
        for ($round = 0; $round < $rounds; $round++) {
            foreach ($perGroup as $id => $groupCommands) {
                if (isset($groupCommands[$round])) {
                    $key = $id.':'.$round;
                    $commands[$key] = $groupCommands[$round];
                    $scheduledGroups[$key] = $id;
                }
            }
        }

        return compact('commands', 'scheduledGroups');
    }

    /** @param array<int, list<list<string>>> $perGroup
     * @return array{commands: array<string, list<string>>, scheduledGroups: array<string, int>}
     */
    public function interleaveScheduledGroupCommandsNative(array $perGroup): array
    {
        $fields = [];
        foreach ($perGroup as $id => $commands) {
            array_push($fields, (string) $id, (string) count($commands));
        }
        $rows = self::cleaningNative(1, $fields);
        if ($rows === null || count($rows) % 2 !== 0) {
            return $this->interleaveScheduledGroupCommandsPhp($perGroup);
        }
        $commands = $scheduledGroups = [];
        foreach (array_chunk($rows, 2) as [$id, $round]) {
            $key = $id.':'.$round;
            $commands[$key] = $perGroup[(int) $id][(int) $round];
            $scheduledGroups[$key] = (int) $id;
        }

        return compact('commands', 'scheduledGroups');
    }

    public function workerConcurrencyKeyPhp(string|int $key): string|int|null
    {
        return $this->scheduledGroups[$key] ?? null;
    }

    public function workerConcurrencyKeyNative(string|int $key): string|int|null
    {
        $fields = [(string) $key];
        foreach ($this->scheduledGroups as $scheduledKey => $id) {
            array_push($fields, (string) $scheduledKey, (string) $id);
        }
        $result = self::cleaningNative(9, $fields);

        return $result === null ? $this->workerConcurrencyKeyPhp($key) : ($result === [] ? null : (int) $result[0]);
    }

    protected function workerStarting(string|int $key): void
    {
        if (isset($this->scheduledGroups[$key])) {
            Cache::forever(self::ROTATION_KEY, $this->scheduledGroups[$key]);
        }
    }
}
