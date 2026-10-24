<?php

declare(strict_types=1);

namespace App\Services\Runners;

use App\Services\Configuration\ConfigurationProvider;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BackfillRunner extends BaseRunner
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

    /**
     * @param  array<string, mixed>  $options
     */
    public function backfill(array $options = []): void
    {
        $select = 'SELECT name';
        if (($options[0] ?? false) !== false) {
            $select .= ', '.$options[0].' AS max';
        }
        $select .= ' FROM usenet_groups WHERE backfill = 1';
        $work = DB::select($select);

        $maxProcesses = (int) app(ConfigurationProvider::class)->ingestion()->backfillThreads;

        $count = count($work);
        if ($count === 0) {
            $this->headerNone();

            return;
        }

        // Streaming mode
        if ((bool) config('nntmux.stream_fork_output', false) === true) {
            $commands = [];
            foreach ($work as $group) {
                $commands[] = [PHP_BINARY, base_path('artisan'), 'update:backfill', (string) $group->name, ...(isset($group->max) ? [(string) $group->max] : [])];
            }
            $this->runStreamingCommands($commands, $maxProcesses, 'backfill');

            return;
        }

        $this->headerStart('backfill', $count, $maxProcesses);

        // Build commands array for parallel execution
        $commands = [];
        foreach ($work as $group) {
            $commands[$group->name] = [PHP_BINARY, base_path('artisan'), 'update:backfill', (string) $group->name, ...(isset($group->max) ? [(string) $group->max] : [])];
        }

        // Process using parallel commands with configurable timeout
        $results = $this->runParallelCommands($commands, $maxProcesses);

        foreach ($results as $groupName => $output) {
            echo $output;
            cli()->primary('Backfilled group '.$groupName);
        }
    }

    public function safeBackfill(): void
    {
        // make sure short_groups is up-to-date - Updated to use new script location (modernized)
        $this->executeCommand([PHP_BINARY, base_path('artisan'), 'groups:update']);

        $backfill_qty = (int) app(ConfigurationProvider::class)->ingestion()->backfillQuantity;
        $backfill_order = (int) app(ConfigurationProvider::class)->ingestion()->backfillOrder;
        $backfill_days = (int) app(ConfigurationProvider::class)->ingestion()->backfillDaysMode;
        $maxMessages = (int) app(ConfigurationProvider::class)->ingestion()->maxMessages;
        $threads = (int) app(ConfigurationProvider::class)->ingestion()->backfillThreads;

        $orderby = $this->resolveBackfillOrderSql($backfill_order);
        $backfilldays = $this->resolveBackfillDaysExpression($backfill_days, $backfill_days === 2
            ? (string) now()->diffInDays(Carbon::createFromFormat('Y-m-d', app(ConfigurationProvider::class)->ingestion()->safeBackfillDate), true)
            : '0');

        $sql = 'SELECT g.name,
                g.first_record AS our_first,
                MAX(a.first_record) AS their_first,
                MAX(a.last_record) AS their_last
            FROM usenet_groups g
            INNER JOIN short_groups a ON g.name = a.name
            WHERE g.first_record IS NOT NULL
            AND g.first_record_postdate IS NOT NULL
            AND g.backfill = 1
            AND (NOW() - INTERVAL '.$backfilldays.' DAY ) < g.first_record_postdate
            GROUP BY a.name, a.last_record, g.name, g.first_record
            '.$orderby.' LIMIT 1';

        $data = DB::select($sql);

        $groupName = '';
        $count = 0;
        if (! empty($data) && isset($data[0]->name)) {
            $groupName = $data[0]->name;
            $count = ($data[0]->our_first - $data[0]->their_first);
        }

        if ($count <= 0) {
            $this->headerNone();
            if (config('nntmux.echocli') && $groupName !== '') {
                cli()->primary('No backfill needed for group '.$groupName);
            }

            return;
        }

        $queues = $this->planSafeBackfillQueues($groupName, (int) $data[0]->our_first, (int) $data[0]->their_first, $backfill_qty, $threads, $maxMessages);

        // Streaming mode
        if ((bool) config('nntmux.stream_fork_output', false) === true) {
            $commands = [];
            foreach ($queues as $queue) {
                $commands[] = $this->buildDnrCommand($queue);
            }
            $this->runStreamingCommands($commands, $threads, 'safe_backfill');

            return;
        }

        $this->headerStart('safe_backfill', count($queues), $threads);

        // Build commands array for parallel execution
        $commands = [];
        foreach ($queues as $idx => $queue) {
            $commands[$idx] = $this->buildDnrCommand($queue);
        }

        // Process using parallel commands with configurable timeout
        $results = $this->runParallelCommands($commands, $threads);

        foreach ($results as $idx => $output) {
            echo $output;
            cli()->primary('Backfilled group '.$groupName);
        }
    }

    public function resolveBackfillOrderSql(int $order): string
    {
        return self::usesRustFfi() ? $this->resolveBackfillOrderSqlNative($order) : $this->resolveBackfillOrderSqlPhp($order);
    }

    public function resolveBackfillOrderSqlPhp(int $order): string
    {
        return match ($order) {
            1 => 'ORDER BY first_record_postdate DESC',
            2 => 'ORDER BY first_record_postdate ASC',
            3 => 'ORDER BY name ASC',
            4 => 'ORDER BY name DESC',
            5 => 'ORDER BY a.last_record DESC',
            default => 'ORDER BY a.last_record ASC',
        };
    }

    public function resolveBackfillOrderSqlNative(int $order): string
    {
        return self::cleaningNative(2, [(string) $order])[0] ?? $this->resolveBackfillOrderSqlPhp($order);
    }

    public function resolveBackfillDaysExpression(int $mode, string $dateDifference = '0'): string
    {
        return self::usesRustFfi() ? $this->resolveBackfillDaysExpressionNative($mode, $dateDifference) : $this->resolveBackfillDaysExpressionPhp($mode, $dateDifference);
    }

    public function resolveBackfillDaysExpressionPhp(int $mode, string $dateDifference = '0'): string
    {
        return match ($mode) {
            1 => 'g.backfill_target', 2 => $dateDifference, default => '0'
        };
    }

    public function resolveBackfillDaysExpressionNative(int $mode, string $dateDifference = '0'): string
    {
        return self::cleaningNative(3, [(string) $mode, $dateDifference])[0] ?? $this->resolveBackfillDaysExpressionPhp($mode, $dateDifference);
    }

    /** @return list<string> */
    public function planSafeBackfillQueues(string $groupName, int $ourFirst, int $theirFirst, int $quantity, int $threads, int $maxMessages): array
    {
        return self::usesRustFfi() ? $this->planSafeBackfillQueuesNative($groupName, $ourFirst, $theirFirst, $quantity, $threads, $maxMessages) : $this->planSafeBackfillQueuesPhp($groupName, $ourFirst, $theirFirst, $quantity, $threads, $maxMessages);
    }

    /** @return list<string> */
    public function planSafeBackfillQueuesPhp(string $groupName, int $ourFirst, int $theirFirst, int $quantity, int $threads, int $maxMessages): array
    {
        $count = $ourFirst - $theirFirst;
        if ($count <= 0) {
            return [];
        }
        $getEach = (int) ceil(min($count, $quantity * $threads) / $maxMessages);
        $queues = [];
        for ($i = 0; $i < $getEach; $i++) {
            $queues[] = sprintf('get_range  backfill  %s  %s  %s  %s', $groupName, $ourFirst - $i * $maxMessages - $maxMessages, $ourFirst - $i * $maxMessages - 1, $i + 1);
        }

        return $queues;
    }

    /** @return list<string> */
    public function planSafeBackfillQueuesNative(string $groupName, int $ourFirst, int $theirFirst, int $quantity, int $threads, int $maxMessages): array
    {
        return self::cleaningNative(4, [$groupName, (string) $ourFirst, (string) $theirFirst, (string) $quantity, (string) $threads, (string) $maxMessages])
            ?? $this->planSafeBackfillQueuesPhp($groupName, $ourFirst, $theirFirst, $quantity, $threads, $maxMessages);
    }
}
