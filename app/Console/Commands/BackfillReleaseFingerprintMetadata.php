<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\CollectReleaseFingerprintMetadata;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class BackfillReleaseFingerprintMetadata extends Command
{
    protected $signature = 'releases:fingerprint-backfill
                            {--limit=100 : Maximum number of releases to queue}
                            {--after-id=0 : Resume after this release ID}
                            {--delay-ms=0 : Delay between queue submissions in milliseconds}
                            {--dry-run : Show matching releases without queueing jobs}
                            {--stale : Only re-queue records left stale (an upgrade replaced their NZB) or stuck processing}';

    protected $description = 'Queue resumable collection of public release format and NNTP metadata';

    public function handle(): int
    {
        $limit = max(1, min(10_000, (int) $this->option('limit')));
        $afterId = max(0, (int) $this->option('after-id'));
        $delayMs = max(0, min(60_000, (int) $this->option('delay-ms')));
        $releaseIds = collect($this->option('stale') ? $this->interruptedReleaseIds($afterId, $limit) : $this->staleReleaseIds($afterId, $limit));

        if ($this->option('dry-run')) {
            $this->table(['release_id'], $releaseIds->map(static fn (int|string $id): array => [(string) $id])->all());
            $this->info('Dry run: '.$releaseIds->count().' matching releases; nothing was queued.');

            return self::SUCCESS;
        }

        foreach ($releaseIds as $releaseId) {
            CollectReleaseFingerprintMetadata::dispatch((int) $releaseId);
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        $this->info('Queued '.$releaseIds->count().' releases. Resume with --after-id='.($releaseIds->last() ?? $afterId).'.');

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
                if (! self::markersAreCurrent($row)) {
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
            ->where(fn ($query) => $query->where('status', 'stale')
                ->orWhere(fn ($stuck) => $stuck->where('status', 'processing')
                    ->where('updated_at', '<', now()->subSeconds(2 * CollectReleaseFingerprintMetadata::TIMEOUT_SECONDS))))
            ->where('releases_id', '>', $afterId)->orderBy('releases_id')->limit($limit)
            ->pluck('releases_id')->map(static fn (mixed $id): int => (int) $id)->all();
    }

    private static function markersAreCurrent(object $row): bool
    {
        if (($row->status ?? null) !== 'complete') {
            return false;
        }
        $metadata = [];
        $versionType = strtolower((string) ($row->version_type ?? ''));
        if ($versionType === 'integer' && preg_match('/\A-?[0-9]+\z/', (string) $row->version_value) === 1) {
            $metadata['schema_version'] = (int) $row->version_value;
        } elseif ($versionType !== '') {
            $metadata['schema_version'] = (string) $row->version_value;
        }
        $formatType = strtolower((string) ($row->format_type ?? ''));
        if (in_array($formatType, ['string', 'text'], true)) {
            $metadata['format'] = (string) $row->format_value;
        } elseif ($formatType !== '' && $formatType !== 'null') {
            // Arrays, objects, numbers and booleans are never a valid format marker.
            $metadata['format'] = [$formatType];
        }

        return CollectReleaseFingerprintMetadata::isCurrent($metadata);
    }
}
