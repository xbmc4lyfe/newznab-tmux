<?php

declare(strict_types=1);

namespace App\Services\Runners;

use App\Models\UsenetGroup;
use App\Services\Configuration\ConfigurationProvider;
use App\Services\NameFixing\NameFixingQueryService;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
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

            return $written === 0 ? '' : FFI::string($scratch, $written);
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

    // ========================================================================
    // WAVE 58.3 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 4.09x, p99 3.83x; uncommon mean 4.00x, p99 3.32x); see RustPostProcessReleasesRunnerDowntimeCompensatorTmuxCommandW58ParityTest. */
    public const bool W58_RUST_FFI_DEFAULT = true;

    public const string W58_RELEASES_RUNNER_FFI_CDEF = <<<'C'
        ssize_t nntmux_w58_releases_runner_work_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** ASCII-lowercased release stage => stage index of w58ReleaseWorkBatch(). */
    public const array W58_RELEASE_STAGES = ['releases' => 1, 'collections' => 2, 'groups' => 3, 'fixnames' => 4, 'nzbs' => 5, 'backfill' => 6];

    /** Base score per stage index of w58ReleaseWorkBatch(). */
    public const array W58_RELEASE_STAGE_WEIGHTS = [0, 60, 50, 20, 40, 30, 10];

    /** Trailer words after the per-item words of w58ReleaseWorkBatch(). */
    public const int W58_RELEASE_TRAILER = 11;

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
     * Wave 58.3 release work items `groupId:stage:limit` (split as explode(':', $item, 3)). Per item
     * `stage | groupValid << 3 | limitValid << 4 | qualified << 5 | score << 6 | limit << 13 | id << 29`: stage
     * is the W58_RELEASE_STAGES index of the ASCII-lowercased second field (0 unknown), the group id and limit
     * are valid as 1-9 ASCII digits (id 0 and limit 0 otherwise; the limit is capped at 65535), qualified
     * needs a valid positive id, a known stage and a valid limit, and score is the stage weight + 30 qualified
     * + min(limit, 1000) / 100. Then the seven stage counts, the qualified count, the distinct qualified
     * group ids, the max valid limit and the total score.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w58ReleaseWorkBatch(array $items): array
    {
        return self::usesW58RustFfi() ? self::w58ReleaseWorkBatchNative($items) : self::w58ReleaseWorkBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w58ReleaseWorkBatchNative(array $items): array
    {
        return self::w58Invoke($items, \count($items) + self::W58_RELEASE_TRAILER) ?? self::w58ReleaseWorkBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w58ReleaseWorkBatchPhp(array $items): array
    {
        $out = [];
        $stages = array_fill(0, 7, 0);
        $qualifiedCount = 0;
        $groups = [];
        $maxLimit = 0;
        $totalScore = 0;
        foreach ($items as $item) {
            [$group, $stage, $limit] = array_pad(explode(':', (string) $item, 3), 3, '');
            $stage = self::W58_RELEASE_STAGES[strtolower($stage)] ?? 0;
            $groupValid = self::w58Digits($group);
            $id = $groupValid ? (int) $group : 0;
            $limitValid = self::w58Digits($limit);
            $limit = $limitValid ? min((int) $limit, 65535) : 0;
            $qualified = $groupValid && $id > 0 && $stage !== 0 && $limitValid;
            $score = self::W58_RELEASE_STAGE_WEIGHTS[$stage] + ($qualified ? 30 : 0) + intdiv(min($limit, 1000), 100);
            $stages[$stage]++;
            if ($qualified) {
                $qualifiedCount++;
                $groups[$id] = true;
            }
            if ($limitValid) {
                $maxLimit = max($maxLimit, $limit);
            }
            $totalScore += $score;
            $out[] = $stage | ($groupValid ? 1 : 0) << 3 | ($limitValid ? 1 : 0) << 4 | ($qualified ? 1 : 0) << 5 | $score << 6
                | $limit << 13 | $id << 29;
        }
        array_push($out, ...$stages);
        array_push($out, $qualifiedCount, \count($groups), $maxLimit, $totalScore);

        return $out;
    }

    /** One to nine ASCII digits. */
    private static function w58Digits(string $value): bool
    {
        $length = \strlen($value);

        return $length >= 1 && $length <= 9 && strspn($value, '0123456789') === $length;
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
            $ffi ??= RustPipelineLibrary::resolveDomain('categorize_w58', self::W58_RELEASES_RUNNER_FFI_CDEF) ?? false;

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
            // @phpstan-ignore method.notFound (Declared by W58_RELEASES_RUNNER_FFI_CDEF.)
            $written = $ffi->nntmux_w58_releases_runner_work_batch($blob, \strlen($blob), $lens, $count, $out, $words);
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
