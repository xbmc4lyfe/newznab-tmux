<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Release;
use App\Models\ReleaseFingerprintMetadata;
use App\Services\Releases\Fingerprint\ReleaseFingerprintCollector;
use App\Support\ReleaseArchiveNamingEvidence;
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

    /**
     * This job's claim on the metadata record, kept across its queue attempts so failed() only ends its own run.
     * Empty for a job queued before claims existed.
     */
    private string $runToken = '';

    public function __construct(private readonly int $releaseId, string $generation = '')
    {
        $this->generation = $generation;
        $this->runToken = bin2hex(random_bytes(8));
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
            $token = $this->claimToken();
            ReleaseFingerprintMetadata::query()->whereKey($record->getKey())->update(['status' => 'processing', 'last_error' => null]
                + (self::tracksRuns() ? ['run_token' => $token] : []));
            $result = $collector->collect($release->refresh());
            $encodedMetadata = json_encode($result['data'], JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
            $safeMetadata = json_decode($encodedMetadata, true, 512, JSON_THROW_ON_ERROR);
            $saved = DB::transaction(function () use ($record, $token, $result, $safeMetadata, $release): int {
                // Only metadata of the committed source: a replacement read before its upgrade rolled back (which
                // also undoes that upgrade's stale marking) must not be saved. The evidence row lock waits for
                // an upgrade still in flight.
                $digest = $result['source_digest'] ?? null;
                if (is_string($digest) && self::tracksSources()) {
                    $committed = app(ReleaseArchiveNamingEvidence::class)->committedSourceDigestLocked((object) ['id' => $release->id, 'guid' => $release->guid]);
                    if ($committed !== null && ! hash_equals($committed, $digest)) {
                        return 0;
                    }
                }

                return ReleaseFingerprintMetadata::query()->whereKey($record->getKey())->where('status', 'processing')
                    ->when(self::tracksRuns(), fn ($query) => $query->where('run_token', $token))->update([
                        'status' => $result['status'],
                        // A builder update skips the model's array cast.
                        'metadata' => json_encode($safeMetadata, JSON_THROW_ON_ERROR),
                        'last_error' => $result['status'] === 'unavailable' ? ($result['data']['reason'] ?? 'NZB unavailable.') : null,
                        'collected_at' => now(),
                    ]);
            });
            if ($saved === 1) {
                return;
            }
        }
        // Still preempted, or it only ever read an uncommitted replacement: hand the record back as stale (only while
        // this run still owns it), which the scheduled stale sweep and the next upgrade's dispatch pick up.
        ReleaseFingerprintMetadata::query()->whereKey($record->getKey())->where('status', 'processing')
            ->when(self::tracksRuns(), fn ($query) => $query->where('run_token', $this->claimToken()))->update(['status' => 'stale']);
    }

    /**
     * The token this job claims the record with. A job queued before tokens existed derives a stable one from its
     * identity, so failed() (which runs on a freshly unserialized copy) still ends only its own run; jobs sharing
     * that identity are one unique job.
     */
    private function claimToken(): string
    {
        return $this->runToken !== '' ? $this->runToken : substr(hash('sha256', 'legacy:'.$this->releaseId.':'.$this->generation), 0, 16);
    }

    /** @var \WeakMap<\PDO, bool>|null */
    private static ?\WeakMap $sourceEvidenceSupport = null;

    /** Whether installations record their committed source digest. */
    private static function tracksSources(): bool
    {
        $pdo = DB::connection()->getPdo();
        self::$sourceEvidenceSupport ??= new \WeakMap;

        return self::$sourceEvidenceSupport[$pdo] ??= Schema::hasTable('release_archive_naming_evidence');
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
            // Never end a run that took the record over meanwhile.
            ->when(self::tracksRuns(), fn ($query) => $query->where('run_token', $this->claimToken()))
            ->update([
                'status' => 'failed',
                'last_error' => $exception === null ? 'QueueJobFailed' : class_basename($exception),
                'collected_at' => now(),
            ]);
    }
}
