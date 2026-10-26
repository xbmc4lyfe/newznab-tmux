<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Models\Release;
use App\Services\Nzb\DirectorySyncBridge;
use App\Services\Nzb\NzbSourceGuard;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

final class VerificationResetService
{
    public const string FFI_CDEF = DirectorySyncBridge::FFI_CDEF;

    /** Rust stays opt-in: the JSON FFI round trip is slower than these PHP helpers (< 1.5x). */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    /** Wave 39.6 paired benchmarks decide this default; see RustVerificationStorePrelockManifestResetW39ParityTest. */
    public const bool W396_RUST_FFI_DEFAULT = false;

    private static ?bool $w396UseRustFfi = null;

    /** Reset classes: 0 eligible (failed or stuck), 1 in flight, 2 already verified; anything else is 3. */
    private const array W396_RESET_CLASSES = ['dirty' => 0, 'stale' => 0, 'expired' => 0, 'unknown' => 0, 'verifying' => 1, 'ready' => 2];

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

    public static function useW396RustFfi(?bool $enabled): void
    {
        self::$w396UseRustFfi = $enabled;
    }

    public static function usesW396RustFfi(): bool
    {
        return self::$w396UseRustFfi ?? self::W396_RUST_FFI_DEFAULT;
    }

    public static function resetW396RustFfi(): void
    {
        self::$w396UseRustFfi = null;
    }

    /**
     * Plan which candidates a verification reset re-queues. Failed or stuck statuses (dirty, stale, expired,
     * unknown) are eligible, as is verifying when $includeInFlight; otherwise verifying is skipped in flight,
     * ready is skipped as already verified, and any other value (including non-strings) is skipped as other.
     * Statuses match exactly. Eligible candidates are planned in chunks of max(1, $chunkSize).
     *
     * @param  array<array-key, mixed>  $candidateStates
     * @return array{inspected: int, eligible: int, skipped_in_flight: int, skipped_verified: int, skipped_other: int, chunks: int}
     */
    public static function summarizeResetScopePlan(array $candidateStates, bool $includeInFlight = false, int $chunkSize = 500): array
    {
        return (self::usesW396RustFfi() ? self::summarizeResetScopePlanNative($candidateStates, $includeInFlight, $chunkSize) : null)
            ?? self::summarizeResetScopePlanPhp($candidateStates, $includeInFlight, $chunkSize);
    }

    /**
     * @param  array<array-key, mixed>  $candidateStates
     * @return array{inspected: int, eligible: int, skipped_in_flight: int, skipped_verified: int, skipped_other: int, chunks: int}
     */
    public static function summarizeResetScopePlanPhp(array $candidateStates, bool $includeInFlight = false, int $chunkSize = 500): array
    {
        $counts = [0, 0, 0, 0];
        foreach ($candidateStates as $state) {
            $class = \is_string($state) ? (self::W396_RESET_CLASSES[$state] ?? 3) : 3;
            $counts[$class === 1 && $includeInFlight ? 0 : $class]++;
        }
        $chunk = max(1, $chunkSize);

        return [
            'inspected' => \count($candidateStates),
            'eligible' => $counts[0],
            'skipped_in_flight' => $counts[1],
            'skipped_verified' => $counts[2],
            'skipped_other' => $counts[3],
            'chunks' => intdiv($counts[0], $chunk) + ($counts[0] % $chunk === 0 ? 0 : 1),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $candidateStates
     * @return array{inspected: int, eligible: int, skipped_in_flight: int, skipped_verified: int, skipped_other: int, chunks: int}|null
     */
    public static function summarizeResetScopePlanNative(array $candidateStates, bool $includeInFlight = false, int $chunkSize = 500): ?array
    {
        $input = pack('qV', $chunkSize, \count($candidateStates));
        foreach ($candidateStates as $state) {
            $input .= \is_string($state) ? pack('V', \strlen($state)).$state : "\0\0\0\0";
        }
        $slots = CollectionReleasePolicy::w396Slots(5, (int) $includeInFlight, $input, 6);
        if ($slots === null || $slots[0] !== \count($candidateStates) || min($slots) < 0 || $slots[0] !== $slots[1] + $slots[2] + $slots[3] + $slots[4]
            || $slots[5] > $slots[1] || ($slots[1] > 0) !== ($slots[5] > 0)) {
            return null;
        }

        return [
            'inspected' => $slots[0],
            'eligible' => $slots[1],
            'skipped_in_flight' => $slots[2],
            'skipped_verified' => $slots[3],
            'skipped_other' => $slots[4],
            'chunks' => $slots[5],
        ];
    }

    /** C signature of the Wave 63.5 {@see self::w63VerificationResetPlanBatch()} helper. */
    private const string W63_VERIFICATION_RESET_PLAN_FFI_CDEF = 'int64_t nntmux_w635_verification_reset_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /**
     * The Wave 63.5 plan batch defaults to Rust: every common and uncommon mean and p99 ratio cleared 1.5x
     * (min 6.13/6.33/6.53/6.22x); see tests/Unit/RustVerificationPolicyPar2ProofPayloadUpdateResetW63ParityTest.php.
     */
    public const bool W63_RUST_FFI_DEFAULT = true;

    /**
     * Wave 63.5 routing and FFI cache, kept in function-local statics (no properties). Without $op it
     * reports the routing; 'set' swaps the override for $val and returns the previous one; 'reset' replaces the
     * cached handle with $val (null re-resolves, false disables); 'buffer' returns `[FFI, int64_t[], capacity]`
     * with room for at least $val words, or null without the library.
     */
    private static function w63RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'reset':
                $ffi = $val;
                $out = null;
                $outCap = 0;

                return null;
            case 'buffer':
                if ($ffi === null) {
                    $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
                    $ffi = RustPipelineLibrary::resolveDomain('w63_5', self::W63_VERIFICATION_RESET_PLAN_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
                }
                if (! $ffi instanceof FFI || ! \is_int($val)) {
                    return null;
                }
                if ($out === null || $outCap < $val) {
                    $outCap = max(1024, $val);
                    $out = $ffi->new('int64_t['.$outCap.']');
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled ?? self::W63_RUST_FFI_DEFAULT;
        }
    }

    /** Route the Wave 63.5 plan batch to Rust until disabled or reset. */
    public static function enableW63RustFfi(): void
    {
        self::w63RustFfiState('set', true);
    }

    /** Route the Wave 63.5 plan batch to PHP until enabled or reset. */
    public static function disableW63RustFfi(): void
    {
        self::w63RustFfiState('set', false);
    }

    /** Restore W63_RUST_FFI_DEFAULT routing and drop the cached Wave 63.5 handle and buffer. */
    public static function resetW63RustFfi(): void
    {
        self::w63RustFfiState('set', null);
        self::w63RustFfiState('reset', null);
    }

    public static function usesW63RustFfi(): bool
    {
        return self::w63RustFfiState() === true;
    }

    /**
     * Runs $callback with w63VerificationResetPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW63RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w63RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w63RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 63.5 Rust helper over $count NUL-joined records, or null
     * to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w63Words(int $count, string $data, int $words): ?array
    {
        try {
            $handle = self::w63RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w635_verification_reset_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('q*', FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Trimmed 1-15 ASCII digits as an integer; empty yields $default, anything else sets bit 1 and yields $default. */
    private static function w63Number(string $field, ?int $default, int &$flags): ?int
    {
        $text = trim($field);
        if ($text === '') {
            return $default;
        }
        if (\strlen($text) <= 15 && strspn($text, '0123456789') === \strlen($text)) {
            return (int) $text;
        }
        $flags |= 1;

        return $default;
    }

    /**
     * Batch verification reset plan over
     * `status\tlease_expires_at\tnow\tattempts\tmax_attempts\tinclude_in_flight\thost\tlocal_host\tscratch_path\troot_path`.
     * Nine words per record: `[class, lease_state, eligible, retry_budget, action, foreign_host, safe_path,
     * next_attempts, flags]`. The class follows summarizeResetScopePlanPhp() (exact status: 0 failed or stuck, 1
     * verifying, 2 ready, 3 other). Numbers are trimmed 1-15 ASCII digits (max attempts default 5, at least 1;
     * invalid sets flag 1). The lease is 0 none, 1 active (expiring after now, or without now), 2 stuck. Include in
     * flight is `1`/`true` or empty/`0`/`false` (trimmed, ASCII case-insensitive). Eligible: class 0, or verifying when
     * included or stuck. Action: 0 skip, 3 roll back a stuck verifying lease, 2 retry budget exhausted, else 1 requeue
     * (consuming one attempt). A trimmed host other than the trimmed local host is foreign; the path is safe per
     * isSafeSubpathPhp(). Flags: 1 invalid number, 2 not exactly ten fields, 4 invalid include flag, 8 other status,
     * 16 lease without now, 32 foreign host with a path, 64 unsafe non-empty path.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w63VerificationResetPlanBatch(array $records): array
    {
        return self::usesW63RustFfi() ? self::w63VerificationResetPlanBatchNative($records) : self::w63VerificationResetPlanBatchPhp($records);
    }

    /**
     * Rust batch over the NUL-joined records; falls back to PHP without the library or when a record holds
     * a NUL.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w63VerificationResetPlanBatchNative(array $records): array
    {
        $count = \count($records);
        if ($count === 0) {
            return [];
        }

        return self::w63Words($count, implode("\0", $records), 9 * $count) ?? self::w63VerificationResetPlanBatchPhp($records);
    }

    /**
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w63VerificationResetPlanBatchPhp(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $fields = explode("\t", $record);
            $flags = \count($fields) === 10 ? 0 : 2;
            $class = self::W396_RESET_CLASSES[$fields[0]] ?? 3;
            if ($class === 3) {
                $flags |= 8;
            }
            $lease = self::w63Number($fields[1] ?? '', null, $flags);
            $now = self::w63Number($fields[2] ?? '', null, $flags);
            if ($lease === null) {
                $leaseState = 0;
            } elseif ($now === null) {
                [$leaseState, $flags] = [1, $flags | 16];
            } else {
                $leaseState = $lease > $now ? 1 : 2;
            }
            $attempts = (int) self::w63Number($fields[3] ?? '', 0, $flags);
            $maxAttempts = max(1, (int) self::w63Number($fields[4] ?? '', 5, $flags));
            $include = match (strtolower(trim($fields[5] ?? ''))) {
                '1', 'true' => true,
                '', '0', 'false' => false,
                default => null,
            };
            if ($include === null) {
                [$include, $flags] = [false, $flags | 4];
            }
            $eligible = $class === 0 || ($class === 1 && ($include || $leaseState === 2));
            $remaining = max(0, $maxAttempts - $attempts);
            $action = match (true) {
                ! $eligible => 0,
                $class === 1 && $leaseState === 2 => 3,
                $remaining === 0 => 2,
                default => 1,
            };
            $host = trim($fields[6] ?? '');
            $foreign = $host !== '' && $host !== trim($fields[7] ?? '');
            $path = $fields[8] ?? '';
            $safe = $path !== '' && self::isSafeSubpathPhp($fields[9] ?? '', $path);
            if ($foreign && $path !== '') {
                $flags |= 32;
            }
            if ($path !== '' && ! $safe) {
                $flags |= 64;
            }
            array_push($out, $class, $leaseState, $eligible ? 1 : 0, $remaining, $action, $foreign ? 1 : 0, $safe ? 1 : 0, $action === 1 ? $attempts + 1 : $attempts, $flags);
        }

        return $out;
    }
}
