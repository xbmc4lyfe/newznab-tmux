<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Hands the tmux monitor's latest statistics to the metrics exporter.
 *
 * The monitor loop is the only place these (expensive) counts are computed,
 * so it publishes a trimmed copy to the cache and the scheduled exporter
 * reads it back instead of re-running the queries.
 *
 * @phpstan-type Snapshot array{
 *     collected_at: int,
 *     is_running: int,
 *     counts: array<string, int|float>,
 *     connections: array<string, array{active: int, total: int}>,
 *     query_timers: array<string, float>
 * }
 */
class TmuxMetricsSnapshot
{
    public const string FFI_CDEF = <<<'C'
        int64_t nntmux_tmux_snapshot_age_and_stale(int64_t collected_at, int64_t now_ts, int64_t max_age_seconds, int32_t *out_is_stale);
        ssize_t nntmux_tmux_snapshot_normalize_numeric_json(const char *input, size_t input_len, uint32_t force_float, char *out_buf, size_t out_cap);
        ssize_t nntmux_tmux_snapshot_normalize_connections_json(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        C;

    public static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private static ?CData $staleOut = null;

    public function __construct(private readonly CacheRepository $cache) {}

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function mediaFfi(): ?FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi === false ? null : self::$ffi;
        }

        self::$ffi = RustPipelineLibrary::resolveDomain('media', self::FFI_CDEF) ?? false;

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * @param  array<string, mixed>  $runVar  The array returned by TmuxMonitorService::collectStatistics().
     */
    public function publish(array $runVar, ?int $collectedAt = null): void
    {
        $this->cache->put(
            (string) config('monitoring.tmux_snapshot.key'),
            [
                'collected_at' => $collectedAt ?? time(),
                'is_running' => (int) ($runVar['settings']['is_running'] ?? 0),
                'counts' => $this->numericValues($runVar['counts']['now'] ?? []),
                'connections' => $this->connections($runVar['conncounts'] ?? []),
                'query_timers' => $this->queryTimers($runVar['timers']['query'] ?? []),
            ],
            (int) config('monitoring.tmux_snapshot.ttl', 900),
        );
    }

    /**
     * @return Snapshot|null
     */
    public function read(): ?array
    {
        $snapshot = $this->cache->get((string) config('monitoring.tmux_snapshot.key'));

        if (! is_array($snapshot) || ! isset($snapshot['collected_at'], $snapshot['counts'])) {
            return null;
        }

        /** @var Snapshot $snapshot */
        return $snapshot;
    }

    public function ageSeconds(int $collectedAt, ?int $now = null): int
    {
        $nowTs = $now ?? time();

        return self::$useRustFfi
            ? $this->ageSecondsNative($collectedAt, $nowTs)
            : $this->ageSecondsPhp($collectedAt, $nowTs);
    }

    public function ageSecondsPhp(int $collectedAt, int $now): int
    {
        return max(0, $now - $collectedAt);
    }

    public function ageSecondsNative(int $collectedAt, int $now): int
    {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return $this->ageSecondsPhp($collectedAt, $now);
        }

        $staleBuf = self::$staleOut ??= $ffi->new('int32_t[1]');
        // @phpstan-ignore method.notFound
        $age = $ffi->nntmux_tmux_snapshot_age_and_stale($collectedAt, $now, 0, FFI::addr($staleBuf[0]));

        return \is_int($age) && $age >= 0 ? $age : $this->ageSecondsPhp($collectedAt, $now);
    }

    public function isStale(int $collectedAt, int $maxAgeSeconds, ?int $now = null): bool
    {
        $nowTs = $now ?? time();

        return self::$useRustFfi
            ? $this->isStaleNative($collectedAt, $maxAgeSeconds, $nowTs)
            : $this->isStalePhp($collectedAt, $maxAgeSeconds, $nowTs);
    }

    public function isStalePhp(int $collectedAt, int $maxAgeSeconds, int $now): bool
    {
        return $this->ageSecondsPhp($collectedAt, $now) > max(0, $maxAgeSeconds);
    }

    public function isStaleNative(int $collectedAt, int $maxAgeSeconds, int $now): bool
    {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return $this->isStalePhp($collectedAt, $maxAgeSeconds, $now);
        }

        $staleBuf = self::$staleOut ??= $ffi->new('int32_t[1]');
        // @phpstan-ignore method.notFound
        $age = $ffi->nntmux_tmux_snapshot_age_and_stale($collectedAt, $now, $maxAgeSeconds, FFI::addr($staleBuf[0]));
        if (! \is_int($age) || $age < 0) {
            return $this->isStalePhp($collectedAt, $maxAgeSeconds, $now);
        }

        return (int) $staleBuf[0] === 1;
    }

    /**
     * @return array<string, int|float>
     */
    public function numericValues(mixed $values): array
    {
        return self::$useRustFfi
            ? $this->numericValuesNative($values)
            : $this->numericValuesPhp($values);
    }

    /**
     * @return array<string, int|float>
     */
    public function numericValuesPhp(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $numeric = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)))) {
                $numeric[$key] = $value + 0;
            }
        }

        return $numeric;
    }

    /**
     * @return array<string, int|float>
     */
    public function numericValuesNative(mixed $values): array
    {
        if (! is_array($values) || $values === []) {
            return [];
        }

        $decoded = $this->normalizeNumericViaFfi($values, false);

        return $decoded ?? $this->numericValuesPhp($values);
    }

    /**
     * @return array<string, float>
     */
    public function queryTimers(mixed $values): array
    {
        return self::$useRustFfi
            ? $this->queryTimersNative($values)
            : $this->queryTimersPhp($values);
    }

    /**
     * @return array<string, float>
     */
    public function queryTimersPhp(mixed $values): array
    {
        return array_map('floatval', $this->numericValuesPhp($values));
    }

    /**
     * @return array<string, float>
     */
    public function queryTimersNative(mixed $values): array
    {
        if (! is_array($values) || $values === []) {
            return [];
        }

        /** @var array<string, float>|null $decoded */
        $decoded = $this->normalizeNumericViaFfi($values, true);

        return $decoded ?? $this->queryTimersPhp($values);
    }

    /**
     * @return array<string, array{active: int, total: int}>
     */
    public function connections(mixed $connections): array
    {
        return self::$useRustFfi
            ? $this->connectionsNative($connections)
            : $this->connectionsPhp($connections);
    }

    /**
     * @return array<string, array{active: int, total: int}>
     */
    public function connectionsPhp(mixed $connections): array
    {
        if (! is_array($connections)) {
            return [];
        }

        $result = [];

        foreach ($connections as $server => $counts) {
            if (is_string($server) && is_array($counts)) {
                $result[$server] = [
                    'active' => (int) ($counts['active'] ?? 0),
                    'total' => (int) ($counts['total'] ?? 0),
                ];
            }
        }

        return $result;
    }

    /**
     * @return array<string, array{active: int, total: int}>
     */
    public function connectionsNative(mixed $connections): array
    {
        if (! is_array($connections) || $connections === []) {
            return [];
        }

        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return $this->connectionsPhp($connections);
        }

        $encoded = json_encode($connections, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! \is_string($encoded)) {
            return $this->connectionsPhp($connections);
        }

        $len = \strlen($encoded);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, $len * 2 + 64), $cap);
        if ($scratch === null) {
            return $this->connectionsPhp($connections);
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_tmux_snapshot_normalize_connections_json($encoded, $len, $scratch, $cap);
        if (! \is_int($written) || $written === -1) {
            return $this->connectionsPhp($connections);
        }
        if ($written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return $this->connectionsPhp($connections);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tmux_snapshot_normalize_connections_json($encoded, $len, $scratch, $cap);
            if (! \is_int($written) || $written <= 0) {
                return $this->connectionsPhp($connections);
            }
        }
        if ($written <= 0) {
            return $this->connectionsPhp($connections);
        }

        /** @var array<string, array{active: int, total: int}>|null $decoded */
        $decoded = json_decode(FFI::string($scratch, $written), true);

        return \is_array($decoded) ? $decoded : $this->connectionsPhp($connections);
    }

    /**
     * @param  array<mixed, mixed>  $values
     * @return array<string, int|float>|null
     */
    private function normalizeNumericViaFfi(array $values, bool $forceFloat): ?array
    {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return null;
        }

        $encoded = json_encode($values, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! \is_string($encoded)) {
            return null;
        }

        $len = \strlen($encoded);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, $len * 2 + 64), $cap);
        if ($scratch === null) {
            return null;
        }

        $flag = $forceFloat ? 1 : 0;
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_tmux_snapshot_normalize_numeric_json($encoded, $len, $flag, $scratch, $cap);
        if (! \is_int($written) || $written === -1) {
            return null;
        }
        if ($written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tmux_snapshot_normalize_numeric_json($encoded, $len, $flag, $scratch, $cap);
            if (! \is_int($written) || $written <= 0) {
                return null;
            }
        }
        if ($written <= 0) {
            return null;
        }

        /** @var array<string, int|float>|null $decoded */
        $decoded = json_decode(FFI::string($scratch, $written), true);

        return \is_array($decoded) ? $decoded : null;
    }
}
