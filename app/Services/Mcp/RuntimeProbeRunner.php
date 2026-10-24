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
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;

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
}
