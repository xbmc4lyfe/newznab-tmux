<?php

declare(strict_types=1);

namespace App\Services\Releases\Fingerprint;

use App\Jobs\CollectReleaseFingerprintMetadata;
use App\Services\NNTP\NNTPService;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class FingerprintDispatch
{
    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustNntpServiceFingerprintDispatchCommandsParityTest.php is below 1.5x.
     */
    public const bool RUST_FFI_DEFAULT = false;

    private const array ROUTES = ['database', 'job', 'rejected'];

    private const array REASONS = ['queued', 'forced', 'generation', 'no_generation', 'invalid_release_id'];

    private const int HAS_FORCE = 1;

    private const int HAS_WORKER = 2;

    /**
     * Wave 47.2 production default for planEnqueueBatch() is Rust: the paired benchmark in
     * tests/Unit/RustFingerprintDispatchBreakerParserAnalyzerCollectorW47ParityTest.php measures 2.34-2.47x mean and
     * 2.27-2.44x p99 on common batches and 3.00-3.76x mean and 2.84-4.15x p99 on uncommon batches. PHP stays the
     * fallback when the library is unavailable or a generation holds a NUL byte.
     */
    public const bool W47_DISPATCH_RUST_FFI_DEFAULT = true;

    /** Domain shared by the Wave 47.2 fingerprint helpers; each class binds only its own export. */
    public const string W47_FFI_DOMAIN = 'nfo_w47';

    public const string W47_FFI_CDEF = <<<'C'
        ssize_t nntmux_w47_fingerprint_dispatch_batch(const char *blob, size_t blob_len, const char *aux, size_t aux_len, int64_t database, int64_t shards, char *out_buf, size_t out_cap);
        C;

    /** Native cap for either Wave 47.2 input buffer. */
    public const int W47_MAX_INPUT = 16777216;

    /** Native cap for the items in one Wave 47.2 batch. */
    public const int W47_MAX_ITEMS = 65536;

    private const array W47_DISPATCH_KEYS = ['total', 'queued', 'forced', 'generation', 'no_generation', 'invalid_release_id', 'duplicate',
        'distinct_generations', 'max_release_id', 'generation_bytes', 'busiest_shard', 'max_shard_load', 'digest'];

    private static ?bool $useRustFfi = null;

    private static ?bool $w47DispatchRustFfiOverride = null;

    private static FFI|false|null $w47DispatchRustFfiHandle = null;

    public static function enqueue(int $releaseId, string $generation = '', bool $force = false): void
    {
        if (config('nntmux.fingerprint_worker', 'rust') === 'rust') {
            if (! app(DatabaseFingerprintQueue::class)->enqueue($releaseId, $force)) {
                throw new RuntimeException('Fingerprint work has no database NZB source.');
            }

            return;
        }
        CollectReleaseFingerprintMetadata::dispatch($releaseId, $generation);
    }

    public static function useRustFfi(?bool $use): void
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
     * Plan where enqueue() sends a release without reading config or touching the queue or bus. $worker is the
     * configured nntmux.fingerprint_worker value (null reads as the "rust" default); exactly "rust" routes to the
     * database queue, where only $force applies, and anything else to the metadata job, where only the trimmed
     * $generation applies. Release ids below one are rejected before routing.
     *
     * @return array{route: string, reason: string, release_id: int, generation: string, force_applies: bool, generation_applies: bool}
     */
    public static function planEnqueue(int $releaseId, string $generation = '', bool $force = false, ?string $worker = 'rust'): array
    {
        return (self::usesRustFfi() ? self::planEnqueueNative($releaseId, $generation, $force, $worker) : null)
            ?? self::planEnqueuePhp($releaseId, $generation, $force, $worker);
    }

    /** @return array{route: string, reason: string, release_id: int, generation: string, force_applies: bool, generation_applies: bool} */
    private static function planEnqueuePhp(int $releaseId, string $generation, bool $force, ?string $worker): array
    {
        $trimmed = trim($generation);
        [$route, $reason] = match (true) {
            $releaseId < 1 => [2, 4],
            $worker === null || $worker === 'rust' => [0, $force ? 1 : 0],
            default => [1, $trimmed !== '' ? 2 : 3],
        };

        return self::plan($releaseId, $route, $reason, $trimmed, $route === 0 && $force, $route === 1 && $trimmed !== '');
    }

    /** @return array{route: string, reason: string, release_id: int, generation: string, force_applies: bool, generation_applies: bool}|null */
    private static function planEnqueueNative(int $releaseId, string $generation, bool $force, ?string $worker): ?array
    {
        $flags = ($force ? self::HAS_FORCE : 0) | ($worker !== null ? self::HAS_WORKER : 0);
        $slots = NNTPService::evaluateWave382Native(1, $flags,
            pack('q3', $releaseId, \strlen($generation), \strlen($worker ?? '')).$generation.($worker ?? ''), 6);
        if ($slots === null) {
            return null;
        }
        [$route, $reason, $start, $length, $forceApplies, $generationApplies] = $slots;
        if (! isset(self::ROUTES[$route], self::REASONS[$reason]) || $start < 0 || $length < 0 || $start > \strlen($generation) - $length
            || ($forceApplies !== 0 && $forceApplies !== 1) || ($generationApplies !== 0 && $generationApplies !== 1)) {
            return null;
        }

        return self::plan($releaseId, $route, $reason, substr($generation, $start, $length), $forceApplies === 1, $generationApplies === 1);
    }

    /** @return array{route: string, reason: string, release_id: int, generation: string, force_applies: bool, generation_applies: bool} */
    private static function plan(int $releaseId, int $route, int $reason, string $generation, bool $forceApplies, bool $generationApplies): array
    {
        return ['route' => self::ROUTES[$route], 'reason' => self::REASONS[$reason], 'release_id' => $releaseId, 'generation' => $generation,
            'force_applies' => $forceApplies, 'generation_applies' => $generationApplies];
    }

    /** Force the Wave 47.2 batch planner onto Rust (true) or PHP (false); null restores W47_DISPATCH_RUST_FFI_DEFAULT. */
    public static function useW47DispatchRustFfi(?bool $enable = true): void
    {
        self::$w47DispatchRustFfiOverride = $enable;
    }

    public static function usesW47DispatchRustFfi(): bool
    {
        return self::$w47DispatchRustFfiOverride ?? self::W47_DISPATCH_RUST_FFI_DEFAULT;
    }

    public static function resetW47DispatchRustFfi(): void
    {
        self::$w47DispatchRustFfiOverride = null;
        self::$w47DispatchRustFfiHandle = null;
    }

    /**
     * Summarize how planEnqueue() routes a batch of requests (request $i is $releaseIds[$i], $generations[$i] and
     * $forces[$i]) for one $worker: reason counts, repeated release ids among routed requests, distinct applied
     * generations and their bytes, the busiest of $shards (clamped to 1..1024) release_id % $shards partitions, and
     * an FNV-1a digest of every request's reason, release id and applied generation crc32() in order.
     *
     * @param  list<int>  $releaseIds
     * @param  list<string>  $generations
     * @param  list<bool>  $forces
     * @return array{total: int, database: int, job: int, rejected: int, queued: int, forced: int, generation: int, no_generation: int, invalid_release_id: int, duplicate: int, distinct_generations: int, max_release_id: int, generation_bytes: int, busiest_shard: int, max_shard_load: int, digest: int}
     *
     * @throws InvalidArgumentException when the three lists differ in length
     */
    public static function planEnqueueBatch(array $releaseIds, array $generations, array $forces, ?string $worker = 'rust', int $shards = 16): array
    {
        return (self::usesW47DispatchRustFfi() ? self::planEnqueueBatchNative($releaseIds, $generations, $forces, $worker, $shards) : null)
            ?? self::planEnqueueBatchPhp($releaseIds, $generations, $forces, $worker, $shards);
    }

    /**
     * @param  list<int>  $releaseIds
     * @param  list<string>  $generations
     * @param  list<bool>  $forces
     * @return array{total: int, database: int, job: int, rejected: int, queued: int, forced: int, generation: int, no_generation: int, invalid_release_id: int, duplicate: int, distinct_generations: int, max_release_id: int, generation_bytes: int, busiest_shard: int, max_shard_load: int, digest: int}
     */
    public static function planEnqueueBatchPhp(array $releaseIds, array $generations, array $forces, ?string $worker = 'rust', int $shards = 16): array
    {
        self::w47AssertSameLength(\count($releaseIds), $generations, $forces);
        $shards = max(1, min(1024, $shards));
        $database = $worker === null || $worker === 'rust';
        $generations = array_values($generations);
        $forces = array_values($forces);
        $reasons = [0, 0, 0, 0, 0];
        $duplicate = $maxId = $generationBytes = 0;
        $loads = array_fill(0, $shards, 0);
        $ids = $applied = [];
        $stream = '';
        foreach (array_values($releaseIds) as $i => $id) {
            $trimmed = trim($generations[$i]);
            $reason = $id < 1 ? 4 : ($database ? ($forces[$i] ? 1 : 0) : ($trimmed !== '' ? 2 : 3));
            $reasons[$reason]++;
            if ($reason !== 4) {
                if (isset($ids[$id])) {
                    $duplicate++;
                } else {
                    $ids[$id] = true;
                }
                $maxId = max($maxId, $id);
                $loads[$id % $shards]++;
            }
            if ($reason === 2) {
                $applied[$trimmed] = true;
                $generationBytes += \strlen($trimmed);
                $stream .= pack('CPV', 2, $id, crc32($trimmed));
            } else {
                $stream .= pack('CPV', $reason, $id, 0);
            }
        }
        $load = max($loads);
        $busiest = $load === 0 ? 0 : (int) array_search($load, $loads, true);

        return self::w47DispatchSummary([\count($releaseIds), ...$reasons, $duplicate, \count($applied), $maxId, $generationBytes, $busiest, $load,
            self::w47Fnv($stream)]);
    }

    /**
     * Null when the library is missing, a generation holds a NUL byte or the batch exceeds the native limits.
     *
     * @param  list<int>  $releaseIds
     * @param  list<string>  $generations
     * @param  list<bool>  $forces
     * @return array{total: int, database: int, job: int, rejected: int, queued: int, forced: int, generation: int, no_generation: int, invalid_release_id: int, duplicate: int, distinct_generations: int, max_release_id: int, generation_bytes: int, busiest_shard: int, max_shard_load: int, digest: int}|null
     */
    public static function planEnqueueBatchNative(array $releaseIds, array $generations, array $forces, ?string $worker = 'rust', int $shards = 16): ?array
    {
        $count = \count($releaseIds);
        self::w47AssertSameLength($count, $generations, $forces);
        $ffi = self::w47DispatchRustFfi();
        if ($ffi === null || $count > self::W47_MAX_ITEMS) {
            return null;
        }
        $aux = pack('q*', ...array_values($releaseIds)).pack('C*', ...array_values($forces));
        $values = self::callW47Native($ffi, 'nntmux_w47_fingerprint_dispatch_batch', implode("\0", $generations), $aux,
            $worker === null || $worker === 'rust' ? 1 : 0, max(1, min(1024, $shards)), \count(self::W47_DISPATCH_KEYS));
        if ($values === null || $values[0] !== $count || array_sum(\array_slice($values, 1, 5)) !== $count) {
            return null;
        }

        return self::w47DispatchSummary($values);
    }

    /**
     * @internal Call a Wave 47.2 export through the shared scratch buffer. Returns exactly $slots counters unpacked
     * from little-endian int64 values, or null when the export declines the batch.
     *
     * @return list<int>|null
     */
    public static function callW47Native(FFI $ffi, string $symbol, string $blob, string $aux, int $paramA, int $paramB, int $slots): ?array
    {
        if (\strlen($blob) > self::W47_MAX_INPUT || \strlen($aux) > self::W47_MAX_INPUT || $slots < 1) {
            return null;
        }
        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $slots * 8, $capacity);
            if ($buffer === null) {
                return null;
            }
            $written = $ffi->{$symbol}($blob, \strlen($blob), $aux, \strlen($aux), $paramA, $paramB, $buffer, $capacity);
            if (! \is_int($written) || $written !== $slots * 8 || $written > $capacity) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('P*', FFI::string($buffer, $written));

            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    /** @internal FNV-1a 64 of $bytes as the signed int with the same bits, as the Wave 47.2 exports report it. */
    public static function w47Fnv(string $bytes): int
    {
        /** @var array{1: int} $value */
        $value = unpack('J', hash('fnv1a64', $bytes, true));

        return $value[1];
    }

    /**
     * @param  array<array-key, mixed>  ...$lists
     *
     * @throws InvalidArgumentException
     */
    private static function w47AssertSameLength(int $count, array ...$lists): void
    {
        foreach ($lists as $list) {
            if (\count($list) !== $count) {
                throw new InvalidArgumentException('Wave 47.2 batch lists must have the same length.');
            }
        }
    }

    /**
     * @param  list<int>  $values
     * @return array{total: int, database: int, job: int, rejected: int, queued: int, forced: int, generation: int, no_generation: int, invalid_release_id: int, duplicate: int, distinct_generations: int, max_release_id: int, generation_bytes: int, busiest_shard: int, max_shard_load: int, digest: int}
     */
    private static function w47DispatchSummary(array $values): array
    {
        $summary = array_combine(self::W47_DISPATCH_KEYS, $values);

        return ['total' => $summary['total'], 'database' => $summary['queued'] + $summary['forced'],
            'job' => $summary['generation'] + $summary['no_generation'], 'rejected' => $summary['invalid_release_id'], ...$summary];
    }

    private static function w47DispatchRustFfi(): ?FFI
    {
        self::$w47DispatchRustFfiHandle ??= RustPipelineLibrary::resolveDomain(self::W47_FFI_DOMAIN, self::W47_FFI_CDEF) ?? false;

        return self::$w47DispatchRustFfiHandle === false ? null : self::$w47DispatchRustFfiHandle;
    }

    // ---- Wave 62.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 11.00x/8.56x, uncommon 7.81x/6.68x); see RustFingerprintQueueDispatchBreakerAnalyzerPar2ParserW62ParityTest. */
    public const bool W62_RUST_FFI_DEFAULT = true;

    private const string W62_FINGERPRINT_DISPATCH_FFI_CDEF = <<<'C'
        intptr_t nntmux_w622_dispatch_plan_batch(const char *items, size_t items_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Summary words after the per-row words, the digest included. */
    public const int W62_FINGERPRINT_DISPATCH_TAIL = 20;

    /**
     * Wave 62 routing, binding and output buffer, kept in function-local statics (no static
     * properties). Operations: null reads the routing override, 'set' replaces it (returning the
     * previous one), 'ffi' resolves the binding and 'out' returns `[binding, buffer, capacity]`
     * with room for at least $val words (null without the library).
     */
    private static function w62RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w62', self::W62_FINGERPRINT_DISPATCH_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w62RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    public static function usesW62RustFfi(): bool
    {
        $enabled = self::w62RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W62_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with w62DispatchPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous
     * routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW62RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w62RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w62RustFfiState('set', $previous);
        }
    }

    /**
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w62DispatchPlanBatch(array $rows): array
    {
        return self::usesW62RustFfi() ? self::w62DispatchPlanBatchNative($rows) : self::w62DispatchPlanBatchPhp($rows);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a row holds a NUL byte or the
     * column is not a list.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w62DispatchPlanBatchNative(array $rows): array
    {
        $count = \count($rows);
        $words = $count + self::W62_FINGERPRINT_DISPATCH_TAIL;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w62RustFfiState('out', $words);
        if ($state === null || ! array_is_list($rows)) {
            return self::w62DispatchPlanBatchPhp($rows);
        }
        $blob = implode("\0", $rows);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W62_FINGERPRINT_DISPATCH_FFI_CDEF.)
            $written = $ffi->nntmux_w622_dispatch_plan_batch($blob, \strlen($blob), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : self::w62DispatchPlanBatchPhp($rows);
    }

    /** @param  list<int>  $out */
    private static function w62Digest(array $out): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));

        return $digest[1];
    }

    /** Accepted rows per dispatch chunk in w62DispatchPlanBatch(). */
    public const int W62_DISPATCH_CHUNK = 64;

    /**
     * Wave 62.2 fingerprint dispatch plan. Per `release_id\tgeneration\tforce\tworker\tpriority` row
     * `route | reason << 2 | tier << 5 | duplicate << 7 | force_applies << 8 | priority_defaulted << 9
     * | shard << 10 | min(chunk, 255) << 14 | min(len(generation), 255) << 22 | min(release_id,
     * 2^32 - 1) << 30`, following planEnqueue(): a release id (w62Int(), up to 18 digits) below one
     * is rejected (route 2, reason 4), a trimmed worker that is empty or exactly `rust` routes to the
     * database queue (route 0, reason 1 when w62Truthy(force), else 0) and anything else to the
     * metadata job (route 1, reason 2 with a trimmed generation, else 3). Priority is a w62Int() of
     * up to 3 digits (5 and the defaulted flag otherwise); accepted rows are tier 1 when force
     * applies or priority <= 1, 2 when priority <= 4, else 3. Accepted rows shard by `release_id &
     * 15`; a release id seen on an earlier accepted row is a duplicate, and the others are numbered
     * per route into W62_DISPATCH_CHUNK-row chunks. Then three route, five reason and four tier
     * counts, the duplicates, the distinct non-empty job generations (by crc32), the busiest shard's
     * load and index, the database and job chunk counts, the largest accepted release id and the
     * XXH64 digest of all earlier words.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w62DispatchPlanBatchPhp(array $rows): array
    {
        $out = $seen = $generations = [];
        $routes = [0, 0, 0];
        $reasons = array_fill(0, 5, 0);
        $tiers = [0, 0, 0, 0];
        $shards = array_fill(0, 16, 0);
        $ordinals = [0, 0];
        $duplicates = $maxId = 0;
        foreach ($rows as $row) {
            [$id, $generation, $force, $worker, $priority] = explode("\t", (string) $row, 5) + array_fill(0, 5, '');
            $id = self::w62Int($id, 18);
            $id = \is_int($id) ? $id : 0;
            $generation = trim($generation);
            $force = self::w62Truthy($force);
            $worker = trim($worker);
            $priority = self::w62Int($priority, 3);
            $defaulted = ! \is_int($priority);
            $priority = \is_int($priority) ? $priority : 5;
            [$route, $reason] = match (true) {
                $id < 1 => [2, 4],
                $worker === '' || $worker === 'rust' => [0, (int) $force],
                default => [1, $generation === '' ? 3 : 2],
            };
            $forceApplies = $route === 0 && $force;
            $tier = match (true) {
                $route === 2 => 0,
                $forceApplies || $priority <= 1 => 1,
                $priority <= 4 => 2,
                default => 3,
            };
            $dup = false;
            $shard = $chunk = 0;
            if ($route !== 2) {
                $shard = $id & 15;
                if (isset($seen[$id])) {
                    $dup = true;
                    $duplicates++;
                } else {
                    $seen[$id] = true;
                    $chunk = intdiv($ordinals[$route], self::W62_DISPATCH_CHUNK);
                    $ordinals[$route]++;
                    $shards[$shard]++;
                    $maxId = max($maxId, $id);
                    if ($route === 1 && $generation !== '') {
                        $generations[crc32($generation)] = true;
                    }
                }
            }
            $routes[$route]++;
            $reasons[$reason]++;
            $tiers[$tier]++;
            $out[] = $route | $reason << 2 | $tier << 5 | (int) $dup << 7 | (int) $forceApplies << 8 | (int) $defaulted << 9 | $shard << 10
                | min($chunk, 255) << 14 | min(\strlen($generation), 255) << 22 | min($id, 0xFFFFFFFF) << 30;
        }
        $load = max($shards);
        $busiest = $load === 0 ? 0 : (int) array_search($load, $shards, true);
        array_push($out, ...$routes, ...$reasons, ...$tiers);
        array_push($out, $duplicates, \count($generations), $load, $busiest, intdiv($ordinals[0] + self::W62_DISPATCH_CHUNK - 1, self::W62_DISPATCH_CHUNK),
            intdiv($ordinals[1] + self::W62_DISPATCH_CHUNK - 1, self::W62_DISPATCH_CHUNK), $maxId);
        $out[] = self::w62Digest($out);

        return $out;
    }

    /** A trimmed field: null when empty, its value for 1 to $max ASCII digits, otherwise false. */
    private static function w62Int(string $value, int $max): int|false|null
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $length = \strlen($value);

        return $length <= $max && strspn($value, '0123456789') === $length ? (int) $value : false;
    }

    /** A trimmed `1`, `true`, `yes` or `on` in any case. */
    private static function w62Truthy(string $value): bool
    {
        return \in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }
}
