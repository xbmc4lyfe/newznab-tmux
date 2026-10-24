<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Release;
use App\Models\ReleaseFingerprintMetadata;
use App\Services\Nzb\NzbSourceGuard;
use App\Services\Releases\Fingerprint\CompactFingerprintCollector;
use App\Services\Releases\Fingerprint\DatabaseFingerprintQueue;
use App\Services\Releases\Fingerprint\ReleaseFingerprintCollector;
use App\Support\ReleaseArchiveNamingEvidence;
use App\Support\RustPipelineLibrary;
use FFI;
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
    public const string FFI_CDEF = <<<'C'
        intptr_t nntmux_w18_verification_metadata_fingerprint(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);
        C;

    // Enable only after paired common/uncommon mean and p99 all reach 1.5x.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    /** Decline objects/resources/cycles before serialize() could invoke user code. */
    private static function nativeValueSupported(mixed $value, int &$remaining, int $depth): bool
    {
        if (--$remaining < 0 || $depth > 24) {
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (! self::nativeValueSupported($item, $remaining, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || is_scalar($value);
    }

    /**
     * @param  list<mixed>  $arguments
     * @return array{value: mixed}|null An envelope distinguishes a native null from failure.
     */
    private static function computeNative(int $operation, array $arguments): ?array
    {
        if (! self::$useRustFfi) {
            return null;
        }
        try {
            $remaining = 100000;
            if (! self::nativeValueSupported($arguments, $remaining, 0)) {
                return null;
            }
            $ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF);
            if ($ffi === null) {
                return null;
            }
            $input = serialize($arguments);
            if (strlen($input) > 64 * 1024 * 1024) {
                return null;
            }
            $capacity = 0;
            $output = RustPipelineLibrary::obtainScratch($ffi, max(4096, strlen($input) + 1024), $capacity);
            if ($output === null) {
                return null;
            }
            // @phpstan-ignore method.notFound (Declared by FFI_CDEF.)
            $written = $ffi->nntmux_w18_verification_metadata_fingerprint($operation, $input, strlen($input), $output, $capacity);
            if (! is_int($written) || $written < 2 || $written > $capacity) {
                return null;
            }

            return ['value' => unserialize(FFI::string($output, $written), ['allowed_classes' => false])];
        } catch (Throwable) {
            return null;
        }
    }

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
        return self::$useRustFfi ? ($this->uniqueIdNative() ?? $this->uniqueIdPhp()) : $this->uniqueIdPhp();
    }

    public function uniqueIdPhp(): string
    {
        return $this->generation === '' ? (string) $this->releaseId : $this->releaseId.':'.$this->generation;
    }

    public function uniqueIdNative(): ?string
    {
        $result = self::computeNative(7, [$this->releaseId, $this->generation])['value'] ?? null;

        return is_string($result) ? $result : null;
    }

    /** Format marker of the configured collector's records; null for the legacy full collector. */
    public static function currentFormat(): ?string
    {
        return self::$useRustFfi ? (self::currentFormatNative() ?? self::currentFormatPhp()) : self::currentFormatPhp();
    }

    public static function currentFormatPhp(): ?string
    {
        return self::compactFormat() ? CompactFingerprintCollector::FORMAT : null;
    }

    public static function currentFormatNative(): ?string
    {
        if (! self::$useRustFfi) {
            return null;
        }

        $result = self::computeNative(8, [self::compactFormat(), CompactFingerprintCollector::FORMAT])['value'] ?? null;

        return is_string($result) ? $result : null;
    }

    /** Schema version the configured collector writes; any other or missing version is collected again. */
    public static function currentSchemaVersion(): int
    {
        return self::$useRustFfi ? (self::currentSchemaVersionNative() ?? self::currentSchemaVersionPhp()) : self::currentSchemaVersionPhp();
    }

    public static function currentSchemaVersionPhp(): int
    {
        return self::compactFormat() ? CompactFingerprintCollector::SCHEMA_VERSION : ReleaseFingerprintCollector::SCHEMA_VERSION;
    }

    public static function currentSchemaVersionNative(): ?int
    {
        if (! self::$useRustFfi) {
            return null;
        }

        $result = self::computeNative(9, [self::compactFormat(), CompactFingerprintCollector::SCHEMA_VERSION, ReleaseFingerprintCollector::SCHEMA_VERSION])['value'] ?? null;

        return is_int($result) ? $result : null;
    }

    /** A JSON column can decode to a scalar; anything but the exact current markers is stale. */
    public static function isCurrent(mixed $metadata): bool
    {
        return self::$useRustFfi ? (self::isCurrentNative($metadata) ?? self::isCurrentPhp($metadata)) : self::isCurrentPhp($metadata);
    }

    public static function isCurrentPhp(mixed $metadata): bool
    {
        return is_array($metadata) && ($metadata['format'] ?? null) === self::currentFormatPhp()
            && ($metadata['schema_version'] ?? null) === self::currentSchemaVersionPhp();
    }

    public static function isCurrentNative(mixed $metadata): ?bool
    {
        if (! self::$useRustFfi) {
            return null;
        }

        // Real metadata can be large; only its markers participate in this predicate.
        $markers = is_array($metadata) ? array_intersect_key($metadata, ['format' => true, 'schema_version' => true]) : $metadata;
        $result = self::computeNative(10, [$markers, self::currentFormatPhp(), self::currentSchemaVersionPhp()])['value'] ?? null;

        return is_bool($result) ? $result : null;
    }

    private static function compactFormat(): bool
    {
        return config('nntmux.fingerprint_worker', 'rust') === 'rust' || config('nntmux.fingerprint_format', 'compact') !== 'full';
    }

    public function handle(CompactFingerprintCollector|ReleaseFingerprintCollector|null $collector = null): void
    {
        if (config('nntmux.fingerprint_worker', 'rust') === 'rust') {
            if (! app(DatabaseFingerprintQueue::class)->enqueue($this->releaseId)) {
                throw new \RuntimeException('Fingerprint source must be imported to the database before processing.');
            }

            return;
        }
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
                // Only metadata of the committed source: the generation an upgrade replaced, read before its staged
                // successor was swapped in after commit, must not be saved; this run then collects again. The
                // evidence row lock waits for an upgrade still in flight.
                $digest = $result['source_digest'] ?? null;
                if (self::tracksSources()) {
                    $committed = app(ReleaseArchiveNamingEvidence::class)->committedSourceDigestLocked((object) ['id' => $release->id, 'guid' => $release->guid]);
                    if ($committed !== null && (is_string($digest) ? ! hash_equals($committed, $digest)
                        // Unreadable while the committed generation is still staged beside it: not its final verdict.
                        : app(NzbSourceGuard::class)->hasStagedGenerations((string) $release->guid))) {
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
        // Still preempted, or it only ever read a replaced generation: hand the record back as stale (only while
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
        return self::$useRustFfi ? ($this->claimTokenNative() ?? $this->claimTokenPhp()) : $this->claimTokenPhp();
    }

    public function claimTokenPhp(): string
    {
        return $this->runToken !== '' ? $this->runToken : substr(hash('sha256', 'legacy:'.$this->releaseId.':'.$this->generation), 0, 16);
    }

    public function claimTokenNative(): ?string
    {
        $result = self::computeNative(11, [$this->releaseId, $this->generation, $this->runToken])['value'] ?? null;

        return is_string($result) ? $result : null;
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
                // An in-place upgrade's refresh must still happen: stale, which the scheduled sweep requeues.
                'status' => $this->generation !== '' ? 'stale' : 'failed',
                'last_error' => $exception === null ? 'QueueJobFailed' : class_basename($exception),
                'collected_at' => now(),
            ]);
    }
}
