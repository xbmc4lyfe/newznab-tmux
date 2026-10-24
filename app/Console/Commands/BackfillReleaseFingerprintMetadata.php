<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\CollectReleaseFingerprintMetadata;
use App\Services\Releases\Fingerprint\FingerprintDispatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class BackfillReleaseFingerprintMetadata extends Command
{
    // Keep PHP as the production default: the JSON FFI round trip is slower than these helpers (< 1.5x).
    private static ?bool $useRustFfi = null;

    protected $signature = 'releases:fingerprint-backfill
                            {--limit=100 : Maximum number of releases to queue}
                            {--after-id=0 : Resume after this release ID}
                            {--delay-ms=0 : Delay between queue submissions in milliseconds}
                            {--dry-run : Show matching releases without queueing jobs}
                            {--stale : Only re-queue records left stale (an upgrade replaced their NZB) or stuck processing}';

    protected $description = 'Queue resumable collection of public release format and NNTP metadata';

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::useRustFfi();
    }

    /** @return array{limit: int, after_id: int, delay_ms: int} */
    public static function normalizeBackfillOptions(mixed $limit, mixed $afterId, mixed $delayMs): array
    {
        return self::useRustFfi() ? self::normalizeBackfillOptionsNative($limit, $afterId, $delayMs) : self::normalizeBackfillOptionsPhp($limit, $afterId, $delayMs);
    }

    /** @return array{limit: int, after_id: int, delay_ms: int} */
    public static function normalizeBackfillOptionsPhp(mixed $limit, mixed $afterId, mixed $delayMs): array
    {
        return ['limit' => max(1, min(10_000, (int) $limit)), 'after_id' => max(0, (int) $afterId), 'delay_ms' => max(0, min(60_000, (int) $delayMs))];
    }

    /** @return array{limit: int, after_id: int, delay_ms: int} */
    public static function normalizeBackfillOptionsNative(mixed $limit, mixed $afterId, mixed $delayMs): array
    {
        if (GetArticleRange::transformNative(12, [$limit, $afterId, $delayMs], $result) && \is_array($result)
            && array_keys($result) === ['limit', 'after_id', 'delay_ms']
            && \is_int($result['limit']) && \is_int($result['after_id']) && \is_int($result['delay_ms'])) {
            return ['limit' => $result['limit'], 'after_id' => $result['after_id'], 'delay_ms' => $result['delay_ms']];
        }

        return self::normalizeBackfillOptionsPhp($limit, $afterId, $delayMs);
    }

    /**
     * The job's exact currency rule over each marker's JSON type and textual value. The legacy full
     * collector writes no format marker, so a null expected format requires the marker to be absent.
     */
    public static function markersAreCurrent(?string $versionValue, ?string $versionType, ?string $formatValue, ?string $formatType, int $expectedVersion, ?string $expectedFormat): bool
    {
        return self::useRustFfi()
            ? self::markersAreCurrentNative($versionValue, $versionType, $formatValue, $formatType, $expectedVersion, $expectedFormat)
            : self::markersAreCurrentPhp($versionValue, $versionType, $formatValue, $formatType, $expectedVersion, $expectedFormat);
    }

    public static function markersAreCurrentPhp(?string $versionValue, ?string $versionType, ?string $formatValue, ?string $formatType, int $expectedVersion, ?string $expectedFormat): bool
    {
        $metadata = [];
        $versionType = strtolower((string) $versionType);
        if ($versionType === 'integer' && preg_match('/\A-?[0-9]+\z/', (string) $versionValue) === 1) {
            $metadata['schema_version'] = (int) $versionValue;
        } elseif ($versionType !== '') {
            $metadata['schema_version'] = (string) $versionValue;
        }
        $formatType = strtolower((string) $formatType);
        if (in_array($formatType, ['string', 'text'], true)) {
            $metadata['format'] = (string) $formatValue;
        } elseif ($formatType !== '' && $formatType !== 'null') {
            // Arrays, objects, numbers and booleans are never a valid format marker.
            $metadata['format'] = [$formatType];
        }

        return ($metadata['format'] ?? null) === $expectedFormat && ($metadata['schema_version'] ?? null) === $expectedVersion;
    }

    public static function markersAreCurrentNative(?string $versionValue, ?string $versionType, ?string $formatValue, ?string $formatType, int $expectedVersion, ?string $expectedFormat): bool
    {
        return GetArticleRange::transformNative(13, [$versionValue, $versionType, $formatValue, $formatType, $expectedVersion, $expectedFormat], $result)
            && \is_bool($result)
            ? $result
            : self::markersAreCurrentPhp($versionValue, $versionType, $formatValue, $formatType, $expectedVersion, $expectedFormat);
    }

    public static function formatSummaryLine(bool $dryRun, int $queued, int $resumeAfterId = 0): string
    {
        return self::useRustFfi() ? self::formatSummaryLineNative($dryRun, $queued, $resumeAfterId) : self::formatSummaryLinePhp($dryRun, $queued, $resumeAfterId);
    }

    public static function formatSummaryLinePhp(bool $dryRun, int $queued, int $resumeAfterId = 0): string
    {
        return $dryRun
            ? 'Dry run: '.$queued.' matching releases; nothing was queued.'
            : 'Queued '.$queued.' releases. Resume with --after-id='.$resumeAfterId.'.';
    }

    public static function formatSummaryLineNative(bool $dryRun, int $queued, int $resumeAfterId = 0): string
    {
        return GetArticleRange::transformNative(14, [$dryRun, $queued, $resumeAfterId], $result) && \is_string($result)
            ? $result
            : self::formatSummaryLinePhp($dryRun, $queued, $resumeAfterId);
    }

    public function handle(): int
    {
        ['limit' => $limit, 'after_id' => $afterId, 'delay_ms' => $delayMs] = self::normalizeBackfillOptions(
            $this->option('limit'), $this->option('after-id'), $this->option('delay-ms'));
        $releaseIds = collect($this->option('stale') ? $this->interruptedReleaseIds($afterId, $limit) : $this->staleReleaseIds($afterId, $limit));

        if ($this->option('dry-run')) {
            $this->table(['release_id'], $releaseIds->map(static fn (int|string $id): array => [(string) $id])->all());
            $this->info(self::formatSummaryLine(true, $releaseIds->count()));

            return self::SUCCESS;
        }

        foreach ($releaseIds as $releaseId) {
            // A recovered refresh keeps its intent: one that exhausts its tries returns to stale, not failed.
            FingerprintDispatch::enqueue((int) $releaseId, $this->option('stale') ? 'stale-sweep' : '', true);
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        $this->info(self::formatSummaryLine(false, $releaseIds->count(), (int) ($releaseIds->last() ?? $afterId)));

        return self::SUCCESS;
    }

    /**
     * Release IDs after $afterId whose fingerprint is missing or not current. Currency is decided in PHP
     * with the job's exact rule, from each marker's JSON type and value, so database type coercion
     * (e.g. "1" or 1.0 comparing equal to 1, or JSON_VALUE ignoring arrays) cannot hide a stale record.
     *
     * @return list<int>
     */
    private function staleReleaseIds(int $afterId, int $limit): array
    {
        $ids = [];
        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        $markers = $sqlite
            ? "json_type(m.metadata, '$.schema_version') AS version_type, json_extract(m.metadata, '$.schema_version') AS version_value, "
                ."json_type(m.metadata, '$.format') AS format_type, json_extract(m.metadata, '$.format') AS format_value"
            : "JSON_TYPE(JSON_EXTRACT(m.metadata, '$.schema_version')) AS version_type, JSON_UNQUOTE(JSON_EXTRACT(m.metadata, '$.schema_version')) AS version_value, "
                ."JSON_TYPE(JSON_EXTRACT(m.metadata, '$.format')) AS format_type, JSON_UNQUOTE(JSON_EXTRACT(m.metadata, '$.format')) AS format_value";
        $cursor = $afterId;
        while (count($ids) < $limit) {
            $rows = DB::table('releases')
                ->leftJoin('release_fingerprint_metadata as m', 'm.releases_id', '=', 'releases.id')
                ->where('releases.nzbstatus', 1)
                ->where('releases.id', '>', $cursor)
                ->orderBy('releases.id')
                ->limit(1_000)
                ->selectRaw('releases.id AS id, m.status AS status, '.$markers)
                ->get();
            if ($rows->isEmpty()) {
                break;
            }
            foreach ($rows as $row) {
                $cursor = (int) $row->id;
                if (! self::rowIsCurrent($row)) {
                    $ids[] = $cursor;
                    if (count($ids) >= $limit) {
                        break;
                    }
                }
            }
        }

        return $ids;
    }

    /**
     * Records an in-place upgrade marked stale, and runs still processing long past the job timeout (the
     * worker died), after $afterId. The status index serves both ranges.
     *
     * @return list<int>
     */
    private function interruptedReleaseIds(int $afterId, int $limit): array
    {
        return DB::table('release_fingerprint_metadata')
            // Stale for an hour: an upgrade queues its own refresh first, and a refresh that keeps failing (which
            // returns to stale) is retried hourly rather than every pass.
            ->where(fn ($query) => $query->where(fn ($stale) => $stale->where('status', 'stale')->where('updated_at', '<', now()->subHour()))
                ->orWhere(fn ($stuck) => $stuck->where('status', 'processing')
                    ->where('updated_at', '<', now()->subSeconds(2 * CollectReleaseFingerprintMetadata::TIMEOUT_SECONDS))))
            ->where('releases_id', '>', $afterId)->orderBy('releases_id')->limit($limit)
            ->pluck('releases_id')->map(static fn (mixed $id): int => (int) $id)->all();
    }

    private static function rowIsCurrent(object $row): bool
    {
        if (($row->status ?? null) !== 'complete') {
            return false;
        }

        return self::markersAreCurrent(self::markerText($row->version_value ?? null), self::markerText($row->version_type ?? null),
            self::markerText($row->format_value ?? null), self::markerText($row->format_type ?? null),
            CollectReleaseFingerprintMetadata::currentSchemaVersion(), CollectReleaseFingerprintMetadata::currentFormat());
    }

    /** Drivers return markers as strings or numbers; compare their PHP string form, as the job's JSON would. */
    private static function markerText(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private static function useRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }
}
