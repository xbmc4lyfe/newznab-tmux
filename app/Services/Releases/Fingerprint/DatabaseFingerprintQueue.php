<?php

declare(strict_types=1);

namespace App\Services\Releases\Fingerprint;

use App\Models\ReleaseNfo;
use App\Services\Nzb\DatabaseNzbStore;
use App\Services\Nzb\NzbService;
use App\Services\Nzb\NzbSourceGuard;
use App\Services\Nzb\Par2CandidateRecoveryResult;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Producers only enqueue; standalone Rust processes own all fingerprint execution. */
final class DatabaseFingerprintQueue
{
    // Paired common/uncommon mean and p99 must all reach 1.5x before adoption.
    private static bool $useRustFfi = false;

    /**
     * Default for the Wave 43.2 toggle only; it stays false because the paired benchmark in
     * tests/Unit/RustNzbPar2ResultFingerprintStoreWorkerQueueW43ParityTest.php is below 1.5x.
     */
    public const bool W432_RUST_FFI_DEFAULT = false;

    private static ?bool $useW432RustFfi = null;

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public function enqueue(int $releaseId, bool $force = false, bool $importLegacy = false): bool
    {
        return DB::transaction(function () use ($releaseId, $force, $importLegacy): bool {
            $release = DB::table('releases')->where('id', $releaseId)->lockForUpdate()->first(['id', 'guid']);
            if ($release === null) {
                return false;
            }
            $content = DB::table('release_nzb_contents')->where('guid', $release->guid)->lockForUpdate()->first(['source_digest']);
            if ($content === null) {
                if (! $importLegacy || ! config('nntmux.nzb_legacy_reads', false)) {
                    return false;
                }
                $xml = app(NzbService::class)->readLegacyNzbContents((string) $release->guid);
                if (! is_string($xml)) {
                    return false;
                }
                $digest = NzbSourceGuard::digest($xml);
                $committed = DB::table('release_archive_naming_evidence')->where('releases_id', $release->id)->value('source_sha256');
                if ($committed !== null && ! hash_equals($committed, $digest)) {
                    throw new RuntimeException('Legacy NZB does not match the committed source generation.');
                }
                app(DatabaseNzbStore::class)->putLocked($release, gzencode($xml));

                return true;
            }
            $this->enqueueLocked($release, $content->source_digest, $force);

            return true;
        });
    }

    public function enqueueLocked(object $release, string $digest, bool $force = false): void
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('Fingerprint enqueue requires the source transaction.');
        }
        $job = DB::table('release_fingerprint_jobs')->where('release_id', $release->id)->lockForUpdate()->first();
        if (self::shouldSkip($job, $digest, $force)) {
            return;
        }
        // Replacing the source fences even an in-flight claim immediately in the same transaction.
        $values = ['source_digest' => $digest, 'status' => 'pending', 'attempts' => 0, 'available_at' => in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true) ? DB::raw('UTC_TIMESTAMP(6)') : now('UTC'),
            'lease_token' => null, 'lease_until' => null, 'worker_id' => null, 'last_error' => null, 'updated_at' => now('UTC')];
        if ($job === null) {
            DB::table('release_fingerprint_jobs')->insert(['release_id' => $release->id, 'created_at' => now('UTC')] + $values);
        } else {
            DB::table('release_fingerprint_jobs')->where('release_id', $release->id)->update($values);
        }
        DB::table('release_fingerprint_metadata')->where('releases_id', $release->id)->update(['status' => 'stale', 'run_token' => null, 'updated_at' => now('UTC')]);
    }

    public static function shouldSkip(?object $job, string $digest, bool $force): bool
    {
        return (self::$useRustFfi ? self::shouldSkipNative($job, $digest, $force) : null) ?? self::shouldSkipPhp($job, $digest, $force);
    }

    public static function shouldSkipPhp(?object $job, string $digest, bool $force): bool
    {
        return $job !== null && hash_equals($job->source_digest, $digest)
            && (! $force || in_array($job->status, ['pending', 'processing'], true));
    }

    public static function shouldSkipNative(?object $job, string $digest, bool $force): ?bool
    {
        // Never serialize or evaluate a model/magic object's properties speculatively.
        if ($job !== null && get_class($job) !== \stdClass::class) {
            return null;
        }
        $fields = $job === null ? [] : get_object_vars($job);
        if ($job !== null && (! isset($fields['source_digest'], $fields['status'])
            || ! is_string($fields['source_digest']) || ! is_string($fields['status']))) {
            return null;
        }
        $result = ReleaseNfo::computeNative(4, [$fields['source_digest'] ?? null, $fields['status'] ?? null, $digest, $force]);

        return is_bool($result) ? $result : null;
    }

    /** Force the Wave 43.2 helper onto Rust (true) or PHP (false); null restores W432_RUST_FFI_DEFAULT. */
    public static function useW432RustFfi(?bool $enable = true): void
    {
        self::$useW432RustFfi = $enable;
    }

    public static function usesW432RustFfi(): bool
    {
        return self::$useW432RustFfi ?? self::W432_RUST_FFI_DEFAULT;
    }

    public static function resetW432RustFfi(): void
    {
        self::$useW432RustFfi = null;
    }

    /**
     * Tally enqueueLocked() decisions with shouldSkipPhp(): each row is an array with a `job` (null when the release has
     * none, else a stdClass or array with string `source_digest` and `status`), a string `digest` and a bool `force`;
     * anything else is invalid. A job is missing, its digest changed, skipped (also counted in skipped_while_forced when
     * a forced request found it pending or processing) or forced back to pending.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array{rows: int, invalid: int, missing_job: int, digest_changed: int, skipped: int, forced: int, skipped_while_forced: int}
     */
    public static function summarizeEnqueueDecisions(array $rows): array
    {
        return (self::usesW432RustFfi() ? self::summarizeEnqueueDecisionsNative($rows) : null)
            ?? self::summarizeEnqueueDecisionsPhp($rows);
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array{rows: int, invalid: int, missing_job: int, digest_changed: int, skipped: int, forced: int, skipped_while_forced: int}
     */
    public static function summarizeEnqueueDecisionsPhp(array $rows): array
    {
        $summary = ['rows' => \count($rows), 'invalid' => 0, 'missing_job' => 0, 'digest_changed' => 0, 'skipped' => 0, 'forced' => 0, 'skipped_while_forced' => 0];
        foreach ($rows as $row) {
            $decision = self::enqueueDecisionFields($row);
            if ($decision === null) {
                $summary['invalid']++;

                continue;
            }
            [$job, $digest, $force] = $decision;
            if (self::shouldSkipPhp($job, $digest, $force)) {
                $summary['skipped']++;
                $summary['skipped_while_forced'] += (int) $force;
            } else {
                $summary[$job === null ? 'missing_job' : (hash_equals($job->source_digest, $digest) ? 'forced' : 'digest_changed')]++;
            }
        }

        return $summary;
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array{rows: int, invalid: int, missing_job: int, digest_changed: int, skipped: int, forced: int, skipped_while_forced: int}|null
     */
    public static function summarizeEnqueueDecisionsNative(array $rows): ?array
    {
        $slots = [];
        $tail = [];
        foreach ($rows as $row) {
            $decision = self::enqueueDecisionFields($row);
            if ($decision === null) {
                array_push($slots, -1, -1, -1, 4);

                continue;
            }
            [$job, $digest, $force] = $decision;
            if ($job === null) {
                array_push($slots, -1, -1, \strlen($digest), (int) $force);
                $tail[] = $digest;
            } else {
                array_push($slots, \strlen($job->source_digest), \strlen($job->status), \strlen($digest), 2 | (int) $force);
                array_push($tail, $job->source_digest, $job->status, $digest);
            }
        }
        $values = Par2CandidateRecoveryResult::evaluateW432Native(4, \count($rows), $slots, implode('', $tail), 7);
        if ($values === null) {
            return null;
        }
        [$count, $invalid, $missing, $changed, $skipped, $forced, $skippedWhileForced] = $values;
        if ($count !== \count($rows) || min($values) < 0 || $invalid + $missing + $changed + $skipped + $forced !== $count || $skippedWhileForced > $skipped) {
            return null;
        }

        return ['rows' => $count, 'invalid' => $invalid, 'missing_job' => $missing, 'digest_changed' => $changed, 'skipped' => $skipped,
            'forced' => $forced, 'skipped_while_forced' => $skippedWhileForced];
    }

    /** @return array{object{source_digest: string, status: string}|null, string, bool}|null */
    private static function enqueueDecisionFields(mixed $row): ?array
    {
        if (! \is_array($row) || ! \is_string($row['digest'] ?? null) || ! \is_bool($row['force'] ?? null)) {
            return null;
        }
        $job = $row['job'] ?? null;
        if ($job === null) {
            return [null, $row['digest'], $row['force']];
        }
        if (\is_object($job) && get_class($job) === \stdClass::class) {
            $job = get_object_vars($job);
        }
        if (! \is_array($job) || ! \is_string($job['source_digest'] ?? null) || ! \is_string($job['status'] ?? null)) {
            return null;
        }

        return [(object) ['source_digest' => $job['source_digest'], 'status' => $job['status']], $row['digest'], $row['force']];
    }

    // ---- Wave 62.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 5.11x/5.01x, uncommon 6.21x/6.15x); see RustFingerprintQueueDispatchBreakerAnalyzerPar2ParserW62ParityTest. */
    public const bool W62_RUST_FFI_DEFAULT = true;

    private const string W62_DATABASE_FINGERPRINT_QUEUE_FFI_CDEF = <<<'C'
        intptr_t nntmux_w622_queue_lease_plan_batch(const char *items, size_t items_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Summary words after the per-row words, the digest included. */
    public const int W62_DATABASE_FINGERPRINT_QUEUE_TAIL = 23;

    /**
     * Wave 62 routing, binding and output buffer, kept in function-local statics (no static
     * properties). Operations: null reads the routing override, 'set' replaces it (returning the
     * previous one), 'ffi' resolves the binding and 'out' returns `[binding, buffer, capacity]`
     * with room for at least $val words (null without the library).
     */
    private static function w62RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w62', self::W62_DATABASE_FINGERPRINT_QUEUE_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w62RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    public static function usesW62RustFfi(): bool
    {
        $enabled = self::w62RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W62_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with w62QueueLeasePlanBatch() routed to Rust ($enabled) or PHP, then restores the previous
     * routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW62RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w62RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w62RustFfiState('set', $previous);
        }
    }

    /**
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w62QueueLeasePlanBatch(array $rows): array
    {
        return self::usesW62RustFfi() ? self::w62QueueLeasePlanBatchNative($rows) : self::w62QueueLeasePlanBatchPhp($rows);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a row holds a NUL byte or the
     * column is not a list.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w62QueueLeasePlanBatchNative(array $rows): array
    {
        $count = \count($rows);
        $words = $count + self::W62_DATABASE_FINGERPRINT_QUEUE_TAIL;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w62RustFfiState('out', $words);
        if ($state === null || ! array_is_list($rows)) {
            return self::w62QueueLeasePlanBatchPhp($rows);
        }
        $blob = implode("\0", $rows);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W62_DATABASE_FINGERPRINT_QUEUE_FFI_CDEF.)
            $written = $ffi->nntmux_w622_queue_lease_plan_batch($blob, \strlen($blob), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : self::w62QueueLeasePlanBatchPhp($rows);
    }

    /** @param  list<int>  $out */
    private static function w62Digest(array $out): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));

        return $digest[1];
    }

    /** Status name => code for w62QueueLeasePlanBatch(). */
    private const array W62_QUEUE_STATUSES = ['pending' => 1, 'processing' => 2, 'failed' => 3, 'completed' => 4, 'stale' => 5];

    /** Attempts after which a failed job is dead and a claim is the last one. */
    public const int W62_QUEUE_MAX_ATTEMPTS = 5;

    /** Claimable jobs one worker poll leases at most. */
    public const int W62_QUEUE_CLAIM_BATCH = 32;

    /**
     * Wave 62.2 fingerprint queue claim/lease plan. Per `status\tattempts\tavailable_at\tlease_until\t
     * now\tworker_id\tsource_digest\tlast_error` row (unix seconds; w62Int() fields, attempts up to
     * 9 digits and times up to 12) `decision | flags << 4 | min(attempts, 255) << 10 | min(wait,
     * 2^24 - 1) << 18 | backoff << 42 | (crc32(lowercase worker) & 0x1FF) << 54`. A row is valid
     * with a known trimmed status (any case), a 64-digit hex digest, a `now` and no malformed
     * number; decisions: 0 invalid, 1 pending and available, 2 deferred until available_at (wait =
     * available_at - now; also a retryable failed job), 3 processing without a worker or lease
     * (orphaned), 4 processing past its lease (stale-lease recovery), 5 leased (wait = lease_until -
     * now), 6 failed with W62_QUEUE_MAX_ATTEMPTS attempts (dead), 7 failed and retryable now, 8
     * completed, 9 stale. Decisions 1, 3, 4 and 7 are claimable: backoff is `min(30 << min(attempts,
     * 7), 3600)` and the exhausted flag marks a claim that would be the last attempt. Flags: worker
     * present, digest valid, exhausted, last_error present, available_at present, lease_until
     * present. Then ten decision and six flag counts, the distinct leased workers (by crc32), the
     * claimable rows, the first claim batch, the shortest deferral, the longest lease, the attempts
     * of valid rows and the XXH64 digest of all earlier words.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w62QueueLeasePlanBatchPhp(array $rows): array
    {
        $out = $workers = [];
        $decisions = array_fill(0, 10, 0);
        $flagCounts = array_fill(0, 6, 0);
        $claimable = $minBackoffWait = $maxLeaseWait = $attemptsSum = 0;
        foreach ($rows as $row) {
            [$status, $attempts, $available, $lease, $now, $worker, $digest, $error] = explode("\t", (string) $row, 8) + array_fill(0, 8, '');
            $code = self::W62_QUEUE_STATUSES[strtolower(trim($status))] ?? 0;
            $attempts = self::w62Int($attempts, 9);
            $available = self::w62Int($available, 12);
            $lease = self::w62Int($lease, 12);
            $now = self::w62Int($now, 12);
            $worker = trim($worker);
            $digest = trim($digest);
            $digestOk = \strlen($digest) === 64 && strspn($digest, '0123456789abcdefABCDEF') === 64;
            $att = \is_int($attempts) ? $attempts : 0;
            $decision = $wait = 0;
            if ($code !== 0 && $digestOk && \is_int($now) && $attempts !== false && $available !== false && $lease !== false) {
                [$decision, $wait] = match ($code) {
                    1 => $available !== null && $available > $now ? [2, $available - $now] : [1, 0],
                    2 => match (true) {
                        $worker === '' || $lease === null => [3, 0],
                        $lease <= $now => [4, 0],
                        default => [5, $lease - $now],
                    },
                    3 => match (true) {
                        $att >= self::W62_QUEUE_MAX_ATTEMPTS => [6, 0],
                        $available !== null && $available > $now => [2, $available - $now],
                        default => [7, 0],
                    },
                    default => [$code + 4, 0],
                };
                $attemptsSum += $att;
            }
            $claim = $decision === 1 || $decision === 3 || $decision === 4 || $decision === 7;
            $exhausted = $claim && $att + 1 >= self::W62_QUEUE_MAX_ATTEMPTS;
            $backoff = $claim ? min(30 << min($att, 7), 3600) : 0;
            $flags = (int) ($worker !== '') | (int) $digestOk << 1 | (int) $exhausted << 2 | (int) (trim($error) !== '') << 3
                | (int) \is_int($available) << 4 | (int) \is_int($lease) << 5;
            $crc = crc32(strtolower($worker));
            $decisions[$decision]++;
            for ($k = 0; $k < 6; $k++) {
                $flagCounts[$k] += $flags >> $k & 1;
            }
            if ($decision === 5) {
                $workers[$crc] = true;
                $maxLeaseWait = max($maxLeaseWait, $wait);
            }
            if ($decision === 2 && ($minBackoffWait === 0 || $wait < $minBackoffWait)) {
                $minBackoffWait = $wait;
            }
            $claimable += (int) $claim;
            $out[] = $decision | $flags << 4 | min($att, 255) << 10 | min($wait, 0xFFFFFF) << 18 | $backoff << 42 | ($crc & 0x1FF) << 54;
        }
        array_push($out, ...$decisions, ...$flagCounts);
        array_push($out, \count($workers), $claimable, min($claimable, self::W62_QUEUE_CLAIM_BATCH), $minBackoffWait, $maxLeaseWait, $attemptsSum);
        $out[] = self::w62Digest($out);

        return $out;
    }

    /** A trimmed field: null when empty, its value for 1 to $max ASCII digits, otherwise false. */
    private static function w62Int(string $value, int $max): int|false|null
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $length = \strlen($value);

        return $length <= $max && strspn($value, '0123456789') === $length ? (int) $value : false;
    }
}
