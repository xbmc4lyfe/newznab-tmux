<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Release;
use App\Models\ReleaseFingerprintMetadata;
use App\Services\Releases\Fingerprint\CompactFingerprintCollector;
use App\Services\Releases\Fingerprint\ReleaseFingerprintCollector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class CollectReleaseFingerprintMetadata implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const TIMEOUT_SECONDS = 1800;

    public int $tries = 3;

    public int $timeout = self::TIMEOUT_SECONDS;

    public int $uniqueFor = 3600;

    public function __construct(private readonly int $releaseId)
    {
        // Enrichment must stay asynchronous even when the default queue uses sync.
        $this->onConnection('release-fingerprint');
        $this->onQueue('release-fingerprint');
    }

    public function uniqueId(): string
    {
        return (string) $this->releaseId;
    }

    /** Format marker of the configured collector's records; null for the legacy full collector. */
    public static function currentFormat(): ?string
    {
        return self::compactFormat() ? CompactFingerprintCollector::FORMAT : null;
    }

    /** Schema version the configured collector writes; any other or missing version is collected again. */
    public static function currentSchemaVersion(): int
    {
        return self::compactFormat() ? CompactFingerprintCollector::SCHEMA_VERSION : ReleaseFingerprintCollector::SCHEMA_VERSION;
    }

    /** @param array<string, mixed>|null $metadata */
    public static function isCurrent(?array $metadata): bool
    {
        return is_array($metadata) && ($metadata['format'] ?? null) === self::currentFormat()
            && (int) ($metadata['schema_version'] ?? 0) === self::currentSchemaVersion();
    }

    private static function compactFormat(): bool
    {
        return config('nntmux.fingerprint_format', 'compact') !== 'full';
    }

    public function handle(CompactFingerprintCollector|ReleaseFingerprintCollector|null $collector = null): void
    {
        $collector ??= self::compactFormat() ? app(CompactFingerprintCollector::class) : app(ReleaseFingerprintCollector::class);
        $release = Release::query()->find($this->releaseId);
        if ($release === null) {
            return;
        }

        $record = ReleaseFingerprintMetadata::query()->firstOrCreate(
            ['releases_id' => $this->releaseId],
            ['status' => 'pending'],
        );
        if ($record->status === 'complete' && self::isCurrent($record->metadata)) {
            return;
        }
        $record->update(['status' => 'processing', 'last_error' => null]);

        $result = $collector->collect($release);
        $encodedMetadata = json_encode($result['data'], JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        $safeMetadata = json_decode($encodedMetadata, true, 512, JSON_THROW_ON_ERROR);
        $record->update([
            'status' => $result['status'],
            'metadata' => $safeMetadata,
            'last_error' => $result['status'] === 'unavailable' ? ($result['data']['reason'] ?? 'NZB unavailable.') : null,
            'collected_at' => now(),
        ]);
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
