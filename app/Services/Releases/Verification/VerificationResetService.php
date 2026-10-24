<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Models\Release;
use App\Services\Nzb\DirectorySyncBridge;
use App\Services\Nzb\NzbSourceGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class VerificationResetService
{
    public const string FFI_CDEF = DirectorySyncBridge::FFI_CDEF;

    /** Rust stays opt-in: the JSON FFI round trip is slower than these PHP helpers (< 1.5x). */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    /**
     * The shape of a scratch record a reset retains for another host, marked terminal: an audit record names
     * no candidate, the unsigned version never goes negative, and an empty delete GUID names nothing.
     *
     * @return array{host: string, candidate_id: ?int, version: int, audit_release_id: ?int, terminal: true, delete_guid: ?string}
     */
    public static function buildTerminalScratchRecord(int $candidateId, int $version, string $host, ?int $auditReleaseId, ?string $deleteGuid): array
    {
        return self::usesRustFfi()
            ? self::buildTerminalScratchRecordNative($candidateId, $version, $host, $auditReleaseId, $deleteGuid)
            : self::buildTerminalScratchRecordPhp($candidateId, $version, $host, $auditReleaseId, $deleteGuid);
    }

    /** @return array{host: string, candidate_id: ?int, version: int, audit_release_id: ?int, terminal: true, delete_guid: ?string} */
    public static function buildTerminalScratchRecordPhp(int $candidateId, int $version, string $host, ?int $auditReleaseId, ?string $deleteGuid): array
    {
        return [
            'host' => $host,
            'candidate_id' => $auditReleaseId === null ? $candidateId : null,
            'version' => max(0, $version),
            'audit_release_id' => $auditReleaseId,
            'terminal' => true,
            'delete_guid' => $deleteGuid === '' ? null : $deleteGuid,
        ];
    }

    /** @return array{host: string, candidate_id: ?int, version: int, audit_release_id: ?int, terminal: true, delete_guid: ?string} */
    public static function buildTerminalScratchRecordNative(int $candidateId, int $version, string $host, ?int $auditReleaseId, ?string $deleteGuid): array
    {
        if (DirectorySyncBridge::transformNative(2, [$candidateId, $version, $host, $auditReleaseId, $deleteGuid], $result) && \is_array($result)
            && array_keys($result) === ['host', 'candidate_id', 'version', 'audit_release_id', 'terminal', 'delete_guid']
            && \is_string($result['host']) && ($result['candidate_id'] === null || \is_int($result['candidate_id']))
            && \is_int($result['version']) && ($result['audit_release_id'] === null || \is_int($result['audit_release_id']))
            && $result['terminal'] === true && ($result['delete_guid'] === null || \is_string($result['delete_guid']))) {
            /** @var array{host: string, candidate_id: ?int, version: int, audit_release_id: ?int, terminal: true, delete_guid: ?string} $result */
            return $result;
        }

        return self::buildTerminalScratchRecordPhp($candidateId, $version, $host, $auditReleaseId, $deleteGuid);
    }

    /** Whether $candidatePath names an entry strictly below $rootPath, with no `.` or `..` segment after it. */
    public static function isSafeSubpath(string $rootPath, string $candidatePath): bool
    {
        return self::usesRustFfi() ? self::isSafeSubpathNative($rootPath, $candidatePath) : self::isSafeSubpathPhp($rootPath, $candidatePath);
    }

    public static function isSafeSubpathPhp(string $rootPath, string $candidatePath): bool
    {
        if ($rootPath === '' || str_contains($rootPath, "\0") || str_contains($candidatePath, "\0")) {
            return false;
        }
        $prefix = rtrim($rootPath, '/').'/';
        if (! str_starts_with($candidatePath, $prefix)) {
            return false;
        }
        $named = false;
        // FilesystemIterator keeps a root's repeated trailing slashes, so empty segments are skipped.
        foreach (explode('/', substr($candidatePath, \strlen($prefix))) as $segment) {
            if ($segment === '') {
                continue;
            }
            if ($segment === '.' || $segment === '..') {
                return false;
            }
            $named = true;
        }

        return $named;
    }

    public static function isSafeSubpathNative(string $rootPath, string $candidatePath): bool
    {
        return DirectorySyncBridge::transformNative(3, [$rootPath, $candidatePath], $result) && \is_bool($result)
            ? $result
            : self::isSafeSubpathPhp($rootPath, $candidatePath);
    }

    /** Hold both locks across legacy TRUNCATE statements, which implicitly commit on MySQL. */
    public function run(callable $reset): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Database reset cannot run inside an open transaction.');
        }
        $store = VerificationLockStore::make();
        $admissionLock = $this->lock($store->exclusiveGroup('reset'));
        try {
            $workerLock = $this->lock($store->exclusiveGroup('worker'));
            try {
                // Checked while holding both gates, so a pause cannot appear unseen before the work.
                if (VerificationWorkerLocks::paused()) {
                    throw new RuntimeException('A deployment pause holds verification workers; retry reset after it is released.');
                }
                // A durable pause fences the whole reset, including filesystem cleanup that a lost
                // session could not stop. Removing it needs the pinned session, so after a lost
                // session it stays until an operator runs releases:verification-barrier --release --all.
                $pause = VerificationWorkerLocks::pauseIfAvailable('reset-'.bin2hex(random_bytes(8)), 'reset');
                try {
                    $this->clearVerification();
                    $reset();
                } finally {
                    if ($pause !== null) {
                        VerificationWorkerLocks::unpause($pause);
                    }
                }
            } finally {
                $store->release($workerLock);
            }
        } finally {
            $store->release($admissionLock);
        }
    }

    /** Retain admission protection until the caller's outer transaction finishes. */
    public function withAdmission(callable $admit): void
    {
        $store = VerificationLockStore::make();
        $lock = $this->lock($store->shared('reset'));
        // A reset that lost its session keeps its durable pause while its cleanup may still run.
        if (VerificationWorkerLocks::paused(null, 'reset', current: true)) {
            $store->release($lock);

            throw new RuntimeException('Verification worker, admission or reset is active; retry reset later.');
        }
        try {
            $admit();
        } finally {
            if (DB::transactionLevel() > 0) {
                $release = static fn () => $store->release($lock);
                DB::afterCommit($release);
                DB::afterRollBack($release);
            } else {
                $store->release($lock);
            }
        }
    }

    private function clearVerification(): void
    {
        $guard = app(NzbSourceGuard::class);
        if (Release::hasLateArticleFlag()) {
            // A committed upgrade whose post-commit swap did not run is installed first (each in its own transaction).
            DB::table('releases')->whereNotNull('late_article_candidate_id')->select(['id', 'guid'])->chunkById(1000, static function ($fenced) use ($guard): void {
                foreach ($fenced as $release) {
                    if (is_string($release->guid) && $guard->hasStagedGenerations($release->guid)) {
                        $guard->settleStagedGenerations($release->guid);
                    }
                }
            });
        }
        DB::transaction(function () use ($guard): void {
            if (Schema::hasTable('release_verification_state')) {
                DB::table('release_verification_state')->where('id', 1)->lockForUpdate()->first();
            }
            // Other worker hosts keep their scratch records and budgets, marked terminal: only they
            // can remove their directories.
            $hosts = Schema::hasTable('release_verification_scratch') && Schema::hasColumn('release_verification_scratch', 'host');
            if ($hosts) {
                $terminal = array_intersect_key(self::buildTerminalScratchRecord(0, 0, '', null, null), ['terminal' => true]);
                DB::table('release_verification_scratch')->where('host', '<>', ScratchInventory::host())->update($terminal);
            }
            if (Release::hasLateArticleFlag()) {
                // Partials keep no inventory once candidates are gone; let NZB-reading workers process them. One whose
                // staged NZB is still unresolved stays fenced: the settle sweep installs it before releasing the fence.
                $kept = DB::table('releases')->whereNotNull('late_article_candidate_id')->pluck('guid', 'id')
                    ->filter(static fn ($guid): bool => is_string($guid) && $guard->hasStagedGenerations($guid))->keys()->all();
                DB::table('releases')->whereNotNull('late_article_candidate_id')->whereNotIn('id', $kept)->update(['late_article_candidate_id' => null]);
            }
            foreach (['release_verification_scratch', 'release_verification_candidates', 'release_verification_audit_retries', 'release_verification_manifests', 'release_verification_oversized_collections'] as $table) {
                if (Schema::hasTable($table)) {
                    $query = DB::table($table);
                    if ($table === 'release_verification_scratch' && $hosts) {
                        $query->where('host', ScratchInventory::host());
                    }
                    $query->delete();
                }
            }
            if (Schema::hasTable('release_verification_scratch_budget')) {
                if (Schema::hasColumn('release_verification_scratch_budget', 'host')) {
                    DB::table('release_verification_scratch_budget')->where('host', ScratchInventory::host())->delete();
                    if (ScratchInventory::host() === '') {
                        DB::table('release_verification_scratch_budget')->insert(['id' => 1]);
                    }
                } else {
                    DB::table('release_verification_scratch_budget')->delete();
                    DB::table('release_verification_scratch_budget')->insert(['id' => 1]);
                }
            }
            if (Schema::hasTable('release_verification_state')) {
                DB::table('release_verification_state')->delete();
                $state = ['id' => 1, 'pending_bytes' => 0, 'audit_cursor' => 0];
                if (Schema::hasColumn('release_verification_state', 'audit_retry_turn')) {
                    $state['audit_retry_turn'] = false;
                }
                DB::table('release_verification_state')->insert($state);
            }
        });

        $root = (string) config('release_verification.storage_path');
        File::ensureDirectoryExists($root, 0700);
        foreach (new \FilesystemIterator($root, \FilesystemIterator::SKIP_DOTS) as $entry) {
            if (in_array($entry->getFilename(), ['worker.lock', 'reset.lock', 'locks', '.gitignore'], true)) {
                continue;
            }
            $path = $entry->getPathname();
            // Never delete outside the scratch root, whatever the iterator returned.
            if (! self::isSafeSubpath($root, $path)) {
                throw new RuntimeException('Cannot remove verification reset artifacts.');
            }
            $directory = $entry->isDir() && ! $entry->isLink();
            $deleted = $directory ? File::deleteDirectory($path) : File::delete($path);
            // A folder that something refilled meanwhile gets one more pass; one that is already gone is gone.
            if (! $deleted && $directory && is_dir($path)) {
                $deleted = File::deleteDirectory($path);
            }
            if (! $deleted && ! file_exists($path) && ! is_link($path)) {
                $deleted = true;
            }
            if (! $deleted) {
                throw new RuntimeException('Cannot remove verification reset artifacts.');
            }
        }
    }

    private function lock(?VerificationLock $lock): VerificationLock
    {
        return $lock ?? throw new RuntimeException('Verification worker, admission or reset is active; retry reset later.');
    }
}
