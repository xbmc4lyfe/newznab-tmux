<?php

declare(strict_types=1);

namespace App\Services\Releases\Fingerprint;

use App\Models\ReleaseNfo;
use App\Services\Nzb\DatabaseNzbStore;
use App\Services\Nzb\NzbService;
use App\Services\Nzb\NzbSourceGuard;
use App\Services\Nzb\Par2CandidateRecoveryResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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
}
