<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Release;
use App\Models\ReleaseFingerprintMetadata;
use App\Services\Releases\Fingerprint\ReleaseFingerprintCollector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class CollectReleaseFingerprintMetadata implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const TIMEOUT_SECONDS = 1800;

    public int $tries = 3;

    public int $timeout = self::TIMEOUT_SECONDS;

    public int $uniqueFor = 3600;

    /**
     * Distinguishes the run an in-place partial upgrade requests from one already queued or running, whose
     * unique lock would otherwise drop it. A class default, not a promoted one: jobs queued before it existed
     * unserialize without it.
     */
    private string $generation = '';

    public function __construct(private readonly int $releaseId, string $generation = '')
    {
        $this->generation = $generation;
        // Enrichment must stay asynchronous even when the default queue uses sync.
        $this->onConnection('release-fingerprint');
        $this->onQueue('release-fingerprint');
    }

    public function uniqueId(): string
    {
        return $this->generation === '' ? (string) $this->releaseId : $this->releaseId.':'.$this->generation;
    }

    public function handle(ReleaseFingerprintCollector $collector): void
    {
        $release = Release::query()->find($this->releaseId);
        if ($release === null) {
            return;
        }

        $record = ReleaseFingerprintMetadata::query()->firstOrCreate(
            ['releases_id' => $this->releaseId],
            ['status' => 'pending'],
        );
        $metadataSchemaVersion = (int) ($record->metadata['schema_version'] ?? 0);
        if ($record->status === 'complete' && $metadataSchemaVersion >= ReleaseFingerprintCollector::SCHEMA_VERSION) {
            return;
        }
        // Only a run that still owns the record may save. An in-place partial upgrade marks it stale mid-run,
        // and its own dispatch was dropped by this job's unique lock, so this run collects again rather than
        // re-queuing (which would spend a try). Upgrades are rare; a bounded loop always finishes.
        for ($round = 0; $round < 3; $round++) {
            // A query update: the model still believes it is processing, so a model update would write nothing.
            // The run token makes this run the record's only writer until another run takes it over.
            $token = bin2hex(random_bytes(8));
            ReleaseFingerprintMetadata::query()->whereKey($record->getKey())->update(['status' => 'processing', 'last_error' => null]
                + (self::tracksRuns() ? ['run_token' => $token] : []));
            $result = $collector->collect($release->refresh());
            $encodedMetadata = json_encode($result['data'], JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
            $safeMetadata = json_decode($encodedMetadata, true, 512, JSON_THROW_ON_ERROR);
            $saved = ReleaseFingerprintMetadata::query()->whereKey($record->getKey())->where('status', 'processing')
                ->when(self::tracksRuns(), fn ($query) => $query->where('run_token', $token))->update([
                    'status' => $result['status'],
                    // A builder update skips the model's array cast.
                    'metadata' => json_encode($safeMetadata, JSON_THROW_ON_ERROR),
                    'last_error' => $result['status'] === 'unavailable' ? ($result['data']['reason'] ?? 'NZB unavailable.') : null,
                    'collected_at' => now(),
                ]);
            if ($saved === 1) {
                return;
            }
        }
        // Still preempted: leave it stale, which the backfill command and the next upgrade's dispatch pick up.
    }

    /** @var \WeakMap<\PDO, bool>|null */
    private static ?\WeakMap $runTokenSupport = null;

    private static function tracksRuns(): bool
    {
        $pdo = DB::connection()->getPdo();
        self::$runTokenSupport ??= new \WeakMap;

        return self::$runTokenSupport[$pdo] ??= Schema::hasColumn('release_fingerprint_metadata', 'run_token');
    }

    public function failed(?Throwable $exception): void
    {
        ReleaseFingerprintMetadata::query()
            ->where('releases_id', $this->releaseId)
            ->where('status', 'processing')
            ->update([
                'status' => 'failed',
                'last_error' => $exception === null ? 'QueueJobFailed' : class_basename($exception),
                'collected_at' => now(),
            ]);
    }
}
