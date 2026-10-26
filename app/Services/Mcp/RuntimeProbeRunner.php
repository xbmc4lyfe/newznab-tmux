<?php

declare(strict_types=1);

namespace App\Services\Mcp;

use App\Services\StatusProbes\Contracts\ServiceProbeInterface;
use App\Services\StatusProbes\DatabaseProbe;
use App\Services\StatusProbes\DiskProbe;
use App\Services\StatusProbes\NntpProbe;
use App\Services\StatusProbes\QueueProbe;
use App\Services\StatusProbes\RedisProbe;
use App\Services\StatusProbes\SearchProbe;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Throwable;

class RuntimeProbeRunner
{
    // Opt-in only until correctness and end-to-end performance gates pass.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi && RustPipelineLibrary::resolveDomain('mcp-tool-runner', ProcessRunner::FFI_CDEF) !== null;
    }

    /**
     * @var array<string, class-string<ServiceProbeInterface>>
     */
    private const array PROBES = [
        'database' => DatabaseProbe::class,
        'disk' => DiskProbe::class,
        'nntp' => NntpProbe::class,
        'queue' => QueueProbe::class,
        'redis' => RedisProbe::class,
        'search' => SearchProbe::class,
    ];

    public function __construct(
        private readonly Container $container,
        private readonly SensitiveValueRedactor $redactor,
    ) {}

    /**
     * @param  list<string>  $identifiers
     * @return list<array<string, mixed>>
     */
    public function run(array $identifiers): array
    {
        $startedAt = hrtime(true);
        $results = [];

        foreach (self::deduplicateIdentifiers($identifiers) as $identifier) {
            $probe = $this->container->make(self::PROBES[$identifier]);
            $result = $probe->probe();
            $results[] = self::formatProbeEntry(
                $identifier,
                $result->ok,
                $result->responseTimeMs,
                $result->impact?->value,
                $this->redactor->redact($result->reason),
                $this->redactor->redactValue($result->metadata),
            );
        }

        Log::channel('nntmux_mcp')->info('MCP tool completed', [
            'tool' => 'probe-runtime',
            'safe_paths' => [],
            'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
            'status' => self::resolveProbeBatchStatus($results),
            'truncated' => false,
        ]);

        return $results;
    }

    /**
     * @param  array<array-key, string>  $identifiers
     * @return list<string>
     */
    public static function deduplicateIdentifiers(array $identifiers): array
    {
        return (self::$useRustFfi ? self::deduplicateIdentifiersNative($identifiers) : null)
            ?? self::deduplicateIdentifiersPhp($identifiers);
    }

    /**
     * @param  array<array-key, string>  $identifiers
     * @return list<string>
     */
    public static function deduplicateIdentifiersPhp(array $identifiers): array
    {
        return array_values(array_unique($identifiers));
    }

    /**
     * @param  array<array-key, string>  $identifiers
     * @return list<string>|null
     */
    public static function deduplicateIdentifiersNative(array $identifiers): ?array
    {
        $result = ProcessRunner::transformNative(2, [$identifiers]);

        return is_array($result) ? $result : null;
    }

    /**
     * @param  array<array-key, array<string, mixed>>  $results
     */
    public static function resolveProbeBatchStatus(array $results): string
    {
        return (self::$useRustFfi ? self::resolveProbeBatchStatusNative($results) : null)
            ?? self::resolveProbeBatchStatusPhp($results);
    }

    /**
     * @param  array<array-key, array<string, mixed>>  $results
     */
    public static function resolveProbeBatchStatusPhp(array $results): string
    {
        return array_all($results, fn (array $result): bool => $result['ok']) ? 'passed' : 'failed';
    }

    /**
     * @param  array<array-key, array<string, mixed>>  $results
     */
    public static function resolveProbeBatchStatusNative(array $results): ?string
    {
        $result = ProcessRunner::transformNative(3, [$results]);

        return is_string($result) ? $result : null;
    }

    /**
     * @param  array<array-key, mixed>  $metadata
     * @return array{id: string, ok: bool, latency_ms: int, impact: string|null, reason: string, metadata: array<array-key, mixed>}
     */
    public static function formatProbeEntry(string $identifier, bool $ok, int $latencyMs, ?string $impact, string $reason, array $metadata): array
    {
        return (self::$useRustFfi ? self::formatProbeEntryNative($identifier, $ok, $latencyMs, $impact, $reason, $metadata) : null)
            ?? self::formatProbeEntryPhp($identifier, $ok, $latencyMs, $impact, $reason, $metadata);
    }

    /**
     * @param  array<array-key, mixed>  $metadata
     * @return array{id: string, ok: bool, latency_ms: int, impact: string|null, reason: string, metadata: array<array-key, mixed>}
     */
    public static function formatProbeEntryPhp(string $identifier, bool $ok, int $latencyMs, ?string $impact, string $reason, array $metadata): array
    {
        return [
            'id' => $identifier,
            'ok' => $ok,
            'latency_ms' => $latencyMs,
            'impact' => $impact,
            'reason' => $reason,
            'metadata' => $metadata,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $metadata
     * @return array{id: string, ok: bool, latency_ms: int, impact: string|null, reason: string, metadata: array<array-key, mixed>}|null
     */
    public static function formatProbeEntryNative(string $identifier, bool $ok, int $latencyMs, ?string $impact, string $reason, array $metadata): ?array
    {
        $result = ProcessRunner::transformNative(4, [$identifier, $ok, $latencyMs, $impact, $reason, $metadata]);

        return is_array($result) ? $result : null;
    }

    /** Wave 54.2 paired benchmarks decide this default; see RustMcpProcessProbeVerificationPlannerRunnerDbFpCollectorW54ParityTest. */
    private const bool W54_RUST_FFI_DEFAULT = true;

    private const string W54_FFI_CDEF = <<<'C'
        intptr_t nntmux_w542_runtime_probe_plan_batch(const char *ids, size_t ids_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /**
     * Wave 54 routing, binding and output buffer, kept in function-local statics. Operations:
     * null reads the routing override, 'set' replaces it (returning the previous one), 'ffi'
     * resolves the binding and 'out' returns `[binding, buffer, capacity]` with room for at
     * least $val words (null without the library).
     */
    private static function w54RustFfiState(?string $op = null, mixed $val = null): mixed
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
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w54', self::W54_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w54RustFfiState('ffi');
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

    public static function usesW54RustFfi(): bool
    {
        $enabled = self::w54RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W54_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with planProbeBatch() routed to Rust ($enabled) or PHP, then restores the
     * previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW54RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w54RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w54RustFfiState('set', $previous);
        }
    }

    /**
     * Batch probe plan, as run() deduplicates identifiers (array_unique() keeps the first) and
     * resolveProbeBatchStatus() reduces the kept results. One word per result:
     * `flags | first index << 8 | crc32(identifier) << 32`. Flags: 1 duplicate (dropped), 2 ok,
     * 4 known probe, 8 empty identifier, 16 latency of at least 1000 ms. Unique results, ok
     * unique results, unknown unique identifiers, the batch status (1 passed, 0 failed), the
     * highest and lowest unique latency (0 without results) and the XXH64 digest of all
     * earlier words follow.
     *
     * @param  list<string>  $identifiers
     * @param  list<bool>  $oks
     * @param  list<int>  $latencies
     * @return list<int>
     */
    public static function planProbeBatch(array $identifiers, array $oks, array $latencies): array
    {
        return self::usesW54RustFfi()
            ? self::planProbeBatchNative($identifiers, $oks, $latencies)
            : self::planProbeBatchPhp($identifiers, $oks, $latencies);
    }

    /**
     * @param  list<string>  $identifiers
     * @param  list<bool>  $oks
     * @param  list<int>  $latencies
     * @return list<int>
     */
    public static function planProbeBatchPhp(array $identifiers, array $oks, array $latencies): array
    {
        [$oks, $latencies] = [array_values($oks), array_values($latencies)];
        $out = $first = [];
        $unique = $okUnique = $unknown = 0;
        $allOk = true;
        $high = $low = null;
        foreach (array_values($identifiers) as $i => $identifier) {
            $ok = (bool) ($oks[$i] ?? false);
            $latency = $latencies[$i] ?? 0;
            $known = isset(self::PROBES[$identifier]);
            $duplicate = isset($first[$identifier]);
            if (! $duplicate) {
                $first[$identifier] = $i;
                $unique++;
                $okUnique += (int) $ok;
                $unknown += (int) ! $known;
                $allOk = $allOk && $ok;
                $high = $high === null ? $latency : max($high, $latency);
                $low = $low === null ? $latency : min($low, $latency);
            }
            $out[] = ($duplicate ? 1 : 0) | ($ok ? 2 : 0) | ($known ? 4 : 0) | ($identifier === '' ? 8 : 0) | ($latency >= 1000 ? 16 : 0)
                | $first[$identifier] << 8 | crc32($identifier) << 32;
        }
        array_push($out, $unique, $okUnique, $unknown, $allOk ? 1 : 0, $high ?? 0, $low ?? 0);
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));
        $out[] = $digest[1];

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, the lists differ in length or
     * are not lists, or an identifier holds a NUL byte.
     *
     * @param  list<string>  $identifiers
     * @param  list<bool>  $oks
     * @param  list<int>  $latencies
     * @return list<int>
     */
    public static function planProbeBatchNative(array $identifiers, array $oks, array $latencies): array
    {
        $count = \count($identifiers);
        $words = $count + 7;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w54RustFfiState('out', $words);
        if ($state === null || \count($oks) !== $count || \count($latencies) !== $count
            || ! array_is_list($identifiers) || ! array_is_list($oks) || ! array_is_list($latencies)) {
            return self::planProbeBatchPhp($identifiers, $oks, $latencies);
        }
        $ids = implode("\0", $identifiers);
        $ints = pack('P*', ...$oks, ...$latencies);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W54_FFI_CDEF.)
            $written = $ffi->nntmux_w542_runtime_probe_plan_batch($ids, \strlen($ids), $ints, \strlen($ints), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : self::planProbeBatchPhp($identifiers, $oks, $latencies);
    }
}
