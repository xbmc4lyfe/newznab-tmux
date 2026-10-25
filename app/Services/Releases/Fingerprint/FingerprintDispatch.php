<?php

declare(strict_types=1);

namespace App\Services\Releases\Fingerprint;

use App\Jobs\CollectReleaseFingerprintMetadata;
use App\Services\NNTP\NNTPService;
use App\Support\RustPipelineLibrary;
use FFI;
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
}
