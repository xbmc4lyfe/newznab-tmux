<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Exceptions\CandidateMetadataBudgetExceeded;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Remembers collections whose headers exceeded the metadata budget, so archival stops reading every part of
 * them again on each pass. More articles only make an upload larger, so a retry can only succeed once the
 * budget is raised (which ends the deferral at once) or after the backoff, which covers anything else that
 * changed. Their headers stay in the ingestion tables meanwhile. Bookkeeping failures never stop archival:
 * a collection whose deferral cannot be read or written is simply tried again.
 */
final class OversizedCollectionDeferrals
{
    // Scalar FFI candidates remain experimental until the paired caller gate passes.
    private static bool $useRustFfi = false;

    private const string FFI_CDEF = <<<'C'
        int64_t nntmux_wave15_metadata_budget(int64_t);
        int32_t nntmux_wave15_raised_budget(const char *, size_t, const char *, size_t, int64_t, int64_t);
        C;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    private const string TABLE = 'release_verification_oversized_collections';

    private const int FIRST_BACKOFF_SECONDS = 21600;

    private const int MAX_BACKOFF_SECONDS = 604800;

    private const string PRUNE_LOCK = 'release-verification:oversized-prune';

    private const int PRUNE_SECONDS = 3600;

    private const int PRUNE_ROWS = 1000;

    private ?bool $available = null;

    /** The most header metadata one candidate may hold, counted as in CandidateRepository's snapshot. */
    public static function budget(): int
    {
        return self::$useRustFfi ? self::budgetNative() : self::budgetPhp();
    }

    public static function budgetPhp(): int
    {
        return max(1, (int) config('release_verification.metadata_bytes', 268435456));
    }

    public static function budgetNative(): int
    {
        $budget = (int) config('release_verification.metadata_bytes', 268435456);
        $ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF);
        if ($ffi === null) {
            return max(1, $budget);
        }

        // @phpstan-ignore method.notFound
        return $ffi->nntmux_wave15_metadata_budget($budget);
    }

    public static function raisedBudgetMatchesPhp(?object $row, string $hash, int $budget): bool
    {
        return $row !== null && $row->collectionhash === $hash && (int) $row->budget_bytes < $budget;
    }

    public static function raisedBudgetMatchesNative(?object $row, string $hash, int $budget): bool
    {
        if ($row === null || $row->collectionhash !== $hash) {
            return self::raisedBudgetMatchesPhp($row, $hash, $budget);
        }
        $ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF);
        if ($ffi === null) {
            return self::raisedBudgetMatchesPhp($row, $hash, $budget);
        }

        // @phpstan-ignore method.notFound
        return $ffi->nntmux_wave15_raised_budget($row->collectionhash, strlen($row->collectionhash), $hash, strlen($hash), (int) $row->budget_bytes, $budget) === 1;
    }

    /** Whether a deferred collection is now eligible for archival because the configured budget was raised. */
    public function unlockedByRaisedBudget(int $collectionId, string $collectionHash): bool
    {
        return $this->guarded(function () use ($collectionId, $collectionHash): bool {
            if (! $this->available()) {
                return false;
            }
            $row = DB::table(self::TABLE)->where('collection_id', $collectionId)->first(['collectionhash', 'budget_bytes']);

            return self::$useRustFfi ? self::raisedBudgetMatchesNative($row, $collectionHash, self::budget())
                : self::raisedBudgetMatchesPhp($row, $collectionHash, self::budget());
        }, false);
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, true>
     */
    public function unlockedIds(array $ids): array
    {
        return $this->guarded(function () use ($ids): array {
            if ($ids === [] || ! $this->available()) {
                return [];
            }
            $budget = self::budget();
            $unlocked = [];
            foreach (array_chunk($ids, 500) as $chunk) {
                $current = DB::table('collections')->whereIn('id', $chunk)->pluck('collectionhash', 'id')->all();
                foreach (DB::table(self::TABLE)->whereIn('collection_id', $chunk)->get(['collection_id', 'collectionhash', 'budget_bytes']) as $row) {
                    $id = (int) $row->collection_id;
                    if (($current[$id] ?? null) === $row->collectionhash && (int) $row->budget_bytes < $budget) {
                        $unlocked[$id] = true;
                    }
                }
            }

            return $unlocked;
        }, []);
    }

    /**
     * Archive each collection unless it is waiting out an over-budget backoff at the current budget.
     *
     * @param  list<int>  $ids
     * @param  callable(int): void  $archive
     * @return array<int, array{error: Throwable|null, retry_after: string|null}> The ids not archived:
     *                                                                            deferred (no error) or failed.
     */
    public function archiveEach(array $ids, callable $archive): array
    {
        $recorded = $this->guarded(fn (): array => $this->lookup($ids), []);
        $kept = [];
        foreach ($ids as $id) {
            if ($recorded[$id] ?? false) {
                $kept[$id] = ['error' => null, 'retry_after' => null];

                continue;
            }
            try {
                $archive($id);
            } catch (Throwable $error) {
                $kept[$id] = ['error' => $error, 'retry_after' => $error instanceof CandidateMetadataBudgetExceeded
                    ? $this->guarded(fn (): ?string => $this->record($id), null) : null];
            }
        }
        // A collection archive() left in place (another archiver held it, or it was not ready) keeps its record.
        $settled = array_values(array_filter(array_keys($recorded), static fn (int $id): bool => ! isset($kept[$id])));
        $this->guarded(function () use ($settled): void {
            $this->dropStale($settled);
        }, null);

        return $kept;
    }

    /**
     * Drop the records of collections deleted without archival.
     *
     * @param  list<int>  $ids
     */
    public function forget(array $ids): void
    {
        $this->guarded(function () use ($ids): void {
            $this->dropStale($ids);
        }, null);
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, bool> Whether each recorded collection is still deferred; ids without a record are absent.
     */
    private function lookup(array $ids): array
    {
        if ($ids === [] || ! $this->available()) {
            return [];
        }
        if (Cache::add(self::PRUNE_LOCK, true, self::PRUNE_SECONDS)) {
            $this->dropStale(DB::table(self::TABLE)->orderBy('collection_id')->limit(self::PRUNE_ROWS)
                ->pluck('collection_id')->map(static fn ($id): int => (int) $id)->all());
        }
        $now = now()->format('Y-m-d H:i:s');
        $budget = self::budget();
        $found = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $current = DB::table('collections')->whereIn('id', $chunk)->pluck('collectionhash', 'id')->all();
            foreach (DB::table(self::TABLE)->whereIn('collection_id', $chunk)->get() as $row) {
                $id = (int) $row->collection_id;
                // A deferral left by a replaced collection under a reused id never holds back the new one.
                $found[$id] = ($current[$id] ?? null) === $row->collectionhash
                    && (int) $row->budget_bytes >= $budget && (string) $row->retry_after > $now;
            }
        }

        return $found;
    }

    /** Record an over-budget failure, doubling the backoff on each repeat at the same budget. */
    private function record(int $collectionId): ?string
    {
        if (! $this->available()) {
            return null;
        }
        $hash = DB::table('collections')->where('id', $collectionId)->value('collectionhash');
        if (! is_string($hash)) {
            return null;
        }
        $budget = self::budget();
        $previous = DB::table(self::TABLE)->where('collection_id', $collectionId)->first(['collectionhash', 'budget_bytes', 'attempts']);
        $attempts = $previous !== null && $previous->collectionhash === $hash && (int) $previous->budget_bytes === $budget
            ? (int) $previous->attempts + 1 : 1;
        $now = now();
        $retryAfter = $now->copy()->addSeconds(min(self::MAX_BACKOFF_SECONDS, self::FIRST_BACKOFF_SECONDS * 2 ** min(10, $attempts - 1)));
        DB::table(self::TABLE)->upsert([['collection_id' => $collectionId, 'collectionhash' => $hash, 'budget_bytes' => $budget,
            'attempts' => $attempts, 'failed_at' => $now->format('Y-m-d H:i:s'), 'retry_after' => $retryAfter->format('Y-m-d H:i:s')]],
            ['collection_id'], ['collectionhash', 'budget_bytes', 'attempts', 'failed_at', 'retry_after']);

        return $retryAfter->toIso8601String();
    }

    /**
     * Drop these ids' records whose collection is gone or was replaced: archived, deleted or reused. A record
     * for a collection that still holds its headers is kept, whoever last wrote it. Collections are read
     * without locks; records are deleted by primary key and recorded hash only.
     *
     * @param  list<int>  $ids
     */
    private function dropStale(array $ids): void
    {
        if ($ids === [] || ! $this->available()) {
            return;
        }
        foreach (array_chunk($ids, 500) as $chunk) {
            $current = DB::table('collections')->whereIn('id', $chunk)->pluck('collectionhash', 'id')->all();
            foreach (DB::table(self::TABLE)->whereIn('collection_id', $chunk)->get(['collection_id', 'collectionhash']) as $row) {
                if (($current[(int) $row->collection_id] ?? null) !== $row->collectionhash) {
                    DB::table(self::TABLE)->where('collection_id', $row->collection_id)->where('collectionhash', $row->collectionhash)->delete();
                }
            }
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @param  T  $fallback
     * @return T
     */
    private function guarded(callable $work, mixed $fallback): mixed
    {
        try {
            return $work();
        } catch (Throwable $error) {
            Log::warning('Over-budget collection bookkeeping failed; archival continues without it.', ['error_type' => $error::class]);

            return $fallback;
        }
    }

    private function available(): bool
    {
        return $this->available ??= Schema::hasTable(self::TABLE);
    }

    /** Wave 51.2 paired benchmarks decide this default; see RustVerificationManifestNntpReaderDeferralsPayloadW51ParityTest. */
    private const bool W51_RUST_FFI_DEFAULT = true;

    public const string W51_FFI_CDEF = <<<'C'
        intptr_t nntmux_w512_oversized_deferral_batch(const char *ints, size_t ints_len, const char *strings, size_t strings_len, size_t count, int64_t budget, const char *now, size_t now_len, int64_t *out, size_t out_cap);
        C;

    /**
     * Wave 51 routing, binding and output buffer, kept in function-local statics. Operations:
     * null reads the routing override, 'set' replaces it (returning the previous one), 'ffi'
     * resolves the binding and 'out' returns a buffer of at least $val words (its capacity
     * through 'cap').
     */
    private static function w51RustFfiState(?string $op = null, mixed $val = null): mixed
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
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w51', self::W51_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w51RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return $out;
            case 'cap':
                return $capacity;
            default:
                return $enabled;
        }
    }

    public static function usesW51RustFfi(): bool
    {
        $enabled = self::w51RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W51_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with classifyDeferrals() routed to Rust ($enabled) or PHP, then restores the
     * previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW51RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w51RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w51RustFfiState('set', $previous);
        }
    }

    /**
     * Batch classification of deferral records against each collection's current hash ('' when
     * the collection is gone), the current budget and now. Two words per record: `flags |
     * crc32(recorded hash) << 32` (flags: 1 current hash matches, 2 recorded budget at least the
     * current one, 4 retry_after later than now, compared bytewise, 8 still deferred: 1, 2 and 4,
     * 16 unlocked by a raised budget, 32 stale, 64 repeated id, 128 same hash and budget), then
     * the backoff seconds a new over-budget failure would record (the attempt count continues
     * only at the same hash and budget; negative counts read as zero). Deferred, unlocked, stale
     * and repeated counts and the XXH64 digest of all earlier words follow.
     *
     * @param  list<int>  $ids
     * @param  list<string>  $currentHashes
     * @param  list<string>  $recordedHashes
     * @param  list<int>  $budgetBytes
     * @param  list<int>  $attempts
     * @param  list<string>  $retryAfters
     * @return list<int>
     */
    public static function classifyDeferrals(array $ids, array $currentHashes, array $recordedHashes, array $budgetBytes,
        array $attempts, array $retryAfters, int $budget, string $now): array
    {
        return self::usesW51RustFfi()
            ? self::classifyDeferralsNative($ids, $currentHashes, $recordedHashes, $budgetBytes, $attempts, $retryAfters, $budget, $now)
            : self::classifyDeferralsPhp($ids, $currentHashes, $recordedHashes, $budgetBytes, $attempts, $retryAfters, $budget, $now);
    }

    /**
     * @param  list<int>  $ids
     * @param  list<string>  $currentHashes
     * @param  list<string>  $recordedHashes
     * @param  list<int>  $budgetBytes
     * @param  list<int>  $attempts
     * @param  list<string>  $retryAfters
     * @return list<int>
     */
    public static function classifyDeferralsPhp(array $ids, array $currentHashes, array $recordedHashes, array $budgetBytes,
        array $attempts, array $retryAfters, int $budget, string $now): array
    {
        $out = $seen = [];
        $deferred = $unlocked = $stale = $repeated = 0;
        foreach ($ids as $i => $id) {
            $recorded = $recordedHashes[$i] ?? '';
            $current = $currentHashes[$i] ?? '';
            $recordedBudget = $budgetBytes[$i] ?? 0;
            $matches = $current !== '' && $current === $recorded;
            $flags = (int) $matches | (int) ($recordedBudget >= $budget) << 1 | (int) (strcmp($retryAfters[$i] ?? '', $now) > 0) << 2;
            if (($flags & 7) === 7) {
                $flags |= 8;
                $deferred++;
            }
            if ($matches && $recordedBudget < $budget) {
                $flags |= 16;
                $unlocked++;
            }
            if (! $matches) {
                $flags |= 32;
                $stale++;
            }
            if (isset($seen[$id])) {
                $flags |= 64;
                $repeated++;
            } else {
                $seen[$id] = true;
            }
            $exponent = 0;
            if ($matches && $recordedBudget === $budget) {
                $flags |= 128;
                $exponent = max(0, min(10, $attempts[$i] ?? 0));
            }
            $out[] = $flags | crc32($recorded) << 32;
            $out[] = min(self::MAX_BACKOFF_SECONDS, self::FIRST_BACKOFF_SECONDS << $exponent);
        }
        $out[] = $deferred;
        $out[] = $unlocked;
        $out[] = $stale;
        $out[] = $repeated;
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));
        $out[] = $digest[1];

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, the lists differ in length or
     * are not lists, or a hash or time holds a NUL byte.
     *
     * @param  list<int>  $ids
     * @param  list<string>  $currentHashes
     * @param  list<string>  $recordedHashes
     * @param  list<int>  $budgetBytes
     * @param  list<int>  $attempts
     * @param  list<string>  $retryAfters
     * @return list<int>
     */
    public static function classifyDeferralsNative(array $ids, array $currentHashes, array $recordedHashes, array $budgetBytes,
        array $attempts, array $retryAfters, int $budget, string $now): array
    {
        $count = \count($ids);
        $ffi = $count === 0 ? null : self::w51RustFfiState('ffi');
        if (! $ffi instanceof FFI || \count($currentHashes) !== $count || \count($recordedHashes) !== $count || \count($budgetBytes) !== $count
            || \count($attempts) !== $count || \count($retryAfters) !== $count || ! array_is_list($ids) || ! array_is_list($currentHashes)
            || ! array_is_list($recordedHashes) || ! array_is_list($budgetBytes) || ! array_is_list($attempts) || ! array_is_list($retryAfters)) {
            return self::classifyDeferralsPhp($ids, $currentHashes, $recordedHashes, $budgetBytes, $attempts, $retryAfters, $budget, $now);
        }
        $table = pack('P*', ...$ids, ...$budgetBytes, ...$attempts);
        $strings = implode("\0", $currentHashes)."\0".implode("\0", $recordedHashes)."\0".implode("\0", $retryAfters);
        $words = 2 * $count + 5;
        $values = false;
        try {
            $out = self::w51RustFfiState('out', $words);
            if ($out instanceof CData) {
                // @phpstan-ignore method.notFound (Declared by W51_FFI_CDEF.)
                $written = $ffi->nntmux_w512_oversized_deferral_batch($table, \strlen($table), $strings, \strlen($strings), $count, $budget, $now, \strlen($now), $out, self::w51RustFfiState('cap'));
                $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;
            }
        } catch (Throwable) {
            $values = false;
        }

        /** @var list<int>|null $result */
        $result = \is_array($values) ? array_values($values) : null;

        return $result ?? self::classifyDeferralsPhp($ids, $currentHashes, $recordedHashes, $budgetBytes, $attempts, $retryAfters, $budget, $now);
    }
}
