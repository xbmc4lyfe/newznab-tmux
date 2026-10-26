<?php

declare(strict_types=1);

namespace App\Services\Runners;

use App\Services\Configuration\ConfigurationProvider;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BackfillRunner extends BaseRunner
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_runner_release_transform(uint32_t mode, const char *input, size_t len, char *out, size_t cap);
        C;

    /**
     * Enabled: the paired benchmark in the W58 parity test measures 4.786x common mean, 4.453x
     * common p99, 3.868x uncommon mean and 2.558x uncommon p99 (per-metric minimum of four runs),
     * above the 1.5x rule.
     */
    public const bool W58_RUST_FFI_DEFAULT = true;

    public const string W58_BACKFILL_RUNNER_FFI_CDEF = 'ssize_t nntmux_w581_backfill_work_batch(const char *rows, size_t rows_len, size_t count, int64_t *out, size_t out_cap);';

    public const int W58_BACKFILL_WORDS = 4;

    public const int W58_BACKFILL_NEEDED = 1;

    public const int W58_BACKFILL_TARGET_REACHED = 2;

    public const int W58_BACKFILL_COMPRESSED = 4;

    public const int W58_BACKFILL_THREADS_CLAMPED = 8;

    public const int W58_BACKFILL_MALFORMED = 16;

    public const int W58_BACKFILL_MESSAGES_ADJUSTED = 32;

    public const int W58_BACKFILL_GROUP_VALID = 64;

    public const int W58_BACKFILL_THREADS_SHIFT = 8;

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

    public static function usesW58RustFfi(): bool
    {
        $enabled = self::w58RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W58_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 58 batch routed to Rust or PHP, then restores the routing. */
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
     * Four words per
     * `group\tour_first\ttheir_first\tquantity\tthreads\tmax_messages\tpostdate_days\ttarget_days\tcompressed`
     * row: the needed, target-reached, compressed, threads-clamped, messages-adjusted and
     * group-valid flags plus the threads (clamped to 1-32) `<< W58_BACKFILL_THREADS_SHIFT`; then
     * the planSafeBackfillQueuesPhp() queue count, the lowest queued article and the work-item
     * score (days left to the target * 1000000 + the article gap up to 999999). A malformed row
     * yields `[W58_BACKFILL_MALFORMED, 0, 0, 0]`.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function backfillWorkBatch(array $rows): array
    {
        return self::usesW58RustFfi() ? self::backfillWorkBatchNative($rows) : self::backfillWorkBatchPhp($rows);
    }

    /**
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function backfillWorkBatchPhp(array $rows): array
    {
        $words = [];
        foreach ($rows as $row) {
            $fields = explode("\t", $row);
            $ints = [];
            if (\count($fields) === 9 && ($fields[8] === '0' || $fields[8] === '1')) {
                for ($i = 1; $i <= 7 && preg_match('/\A-?[0-9]{1,18}\z/', $fields[$i]) === 1; $i++) {
                    $ints[] = (int) $fields[$i];
                }
            }
            if (\count($ints) !== 7) {
                array_push($words, self::W58_BACKFILL_MALFORMED, 0, 0, 0);

                continue;
            }
            [$ourFirst, $theirFirst, $quantity, $threads, $maxMessages, $days, $target] = $ints;
            $clampedThreads = max(1, min(32, $threads));
            $flags = ($fields[8] === '1' ? self::W58_BACKFILL_COMPRESSED : 0)
                | ($clampedThreads !== $threads ? self::W58_BACKFILL_THREADS_CLAMPED : 0)
                | (preg_match('/\A[A-Za-z0-9][A-Za-z0-9.+_-]{0,254}\z/', $fields[0]) === 1 ? self::W58_BACKFILL_GROUP_VALID : 0);
            if ($maxMessages < 1) {
                $maxMessages = 20000;
                $flags |= self::W58_BACKFILL_MESSAGES_ADJUSTED;
            } elseif ($maxMessages > 1000000) {
                $maxMessages = 1000000;
                $flags |= self::W58_BACKFILL_MESSAGES_ADJUSTED;
            }
            $quantity = max(0, min(10000000, $quantity));
            $count = $ourFirst - $theirFirst;
            $queues = $count > 0 ? intdiv(min($count, $quantity * $clampedThreads) + $maxMessages - 1, $maxMessages) : 0;
            $days = max(0, min(36500, $days));
            $target = max(0, min(36500, $target));
            $flags |= ($count > 0 ? self::W58_BACKFILL_NEEDED : 0) | ($days >= $target ? self::W58_BACKFILL_TARGET_REACHED : 0);
            $words[] = $flags | $clampedThreads << self::W58_BACKFILL_THREADS_SHIFT;
            $words[] = $queues;
            $words[] = $ourFirst - $queues * $maxMessages;
            $words[] = $count > 0 && $days < $target ? ($target - $days) * 1000000 + min($count, 999999) : 0;
        }

        return $words;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or Rust declines the batch (a
     * row holding a NUL byte).
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function backfillWorkBatchNative(array $rows): array
    {
        $count = \count($rows);
        /** @var array{0: FFI, 1: CData, 2: int}|null $buffer */
        $buffer = $count === 0 ? null : self::w58RustFfiState('out', $count * self::W58_BACKFILL_WORDS);
        if ($buffer === null) {
            return self::backfillWorkBatchPhp($rows);
        }
        [$ffi, $out, $cap] = $buffer;
        $joined = implode("\0", $rows);
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w581_backfill_work_batch($joined, \strlen($joined), $count, $out, $cap);
        } catch (\Throwable) {
            $written = -1;
        }
        $words = $written === $count ? unpack('q*', FFI::string($out, $count * self::W58_BACKFILL_WORDS * 8)) : false;
        if (! \is_array($words)) {
            return self::backfillWorkBatchPhp($rows);
        }

        /** @var list<int> */
        return array_values($words);
    }

    /**
     * Wave 58 routing override (`set`) plus the lazily resolved binding and reusable `int64_t`
     * output buffer (`out`, sized for `$val` words), kept in function-local statics.
     */
    private static function w58RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'out':
                $ffi ??= RustPipelineLibrary::resolveDomain('backfill_runner_w58', self::W58_BACKFILL_RUNNER_FFI_CDEF) ?? false;
                if (! $ffi instanceof FFI || ! \is_int($val) || $val < 1) {
                    return null;
                }
                if (! $out instanceof CData || $outCap < $val) {
                    $outCap = max(1024, $val * 2);
                    $out = $ffi->new("int64_t[{$outCap}]");
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled;
        }
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
