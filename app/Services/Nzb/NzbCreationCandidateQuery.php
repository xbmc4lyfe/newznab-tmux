<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Models\Release;
use App\Services\Configuration\ConfigurationProvider;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class NzbCreationCandidateQuery
{
    public const string FFI_CDEF = <<<'C'
        int64_t nntmux_nzb_claim_ttl(int64_t timeout);
        intptr_t nntmux_nzb_selectable_columns(const char *input, size_t input_len, int supports_claims, char *out, size_t cap);
        intptr_t nntmux_nzb_id_order(const char *input, size_t input_len, int sqlite, char *out, size_t cap);
        C;

    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi && RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF) !== null;
    }

    /** @param list<int|string> $arguments */
    private static function nativeBytes(string $function, array $arguments, int $minimumCapacity): ?string
    {
        try {
            $ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF);
            if ($ffi === null) {
                return null;
            }
            $capacity = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, $minimumCapacity), $capacity);
            if ($scratch === null) {
                return null;
            }
            $written = $ffi->{$function}(...[...$arguments, $scratch, $capacity]);
            if (is_int($written) && $written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($scratch === null) {
                    return null;
                }
                $written = $ffi->{$function}(...[...$arguments, $scratch, $capacity]);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return $written === 0 ? '' : FFI::string($scratch, $written);
        } catch (Throwable) {
            return null;
        }
    }

    public const string CLAIMED_AT_COLUMN = 'nzb_creation_claimed_at';

    public const string CLAIM_TOKEN_COLUMN = 'nzb_creation_claim_token';

    private static ?bool $supportsClaims = null;

    private static ?bool $supportsFailureState = null;

    /**
     * @return Builder<Release>
     */
    public static function baseBuilder(int|string|null $groupID = null, bool $includeClaimed = false): Builder
    {
        $query = Release::query()
            ->from('releases as r')
            ->where('r.nzbstatus', NzbService::NZB_NONE);

        // Successful verification writes the exact NZB directly. Header-only
        // legacy rows must not be claimed or retried into public availability.
        if (config('release_verification.required', true)) {
            return $query->whereRaw('1 = 0');
        }

        if ($groupID !== null && $groupID !== '' && $groupID !== 0 && $groupID !== '0') {
            $query->where('r.groups_id', $groupID);
        }

        if (! $includeClaimed) {
            self::applyClaimWindow($query);
        }

        return $query;
    }

    /**
     * @param  list<string>  $columns
     * @param  list<int>  $preferredIds
     * @return EloquentCollection<int, Release>
     */
    public static function claimBatch(
        int|string|null $groupID,
        int $limit,
        string $token,
        array $columns = ['*'],
        array $preferredIds = [],
    ): EloquentCollection {
        $effectiveLimit = max(1, $limit);

        return DB::transaction(function () use ($groupID, $effectiveLimit, $token, $columns, $preferredIds): EloquentCollection {
            $supportsClaims = self::supportsClaims();
            $query = self::baseBuilder($groupID)
                ->select('r.id')
                ->orderByDesc('r.postdate')
                ->orderBy('r.id')
                ->limit($effectiveLimit);

            if (DB::getDriverName() !== 'sqlite') {
                $query->lockForUpdate();
            }

            $ids = $preferredIds === [] ? [] : (clone $query)->whereIn('r.id', $preferredIds)
                ->pluck('r.id')->map(static fn (mixed $id): int => (int) $id)->all();
            if (count($ids) < $effectiveLimit) {
                $ids = [...$ids, ...$query->when($ids !== [], static fn ($q) => $q->whereNotIn('r.id', $ids))
                    ->limit($effectiveLimit - count($ids))->pluck('r.id')
                    ->map(static fn (mixed $id): int => (int) $id)->all()];
            }

            if ($ids === []) {
                return (new Release)->newCollection();
            }

            if ($supportsClaims) {
                Release::query()
                    ->whereIn('id', $ids)
                    ->update([
                        self::CLAIMED_AT_COLUMN => now(),
                        self::CLAIM_TOKEN_COLUMN => $token,
                    ]);
            }

            $releaseQuery = Release::query()
                ->whereIn('id', $ids)
                ->with('category.parent')
                ->select(self::selectableColumns($columns, $supportsClaims))
                ->orderByRaw(self::idOrderExpression($ids));

            if (self::supportsFailureState()) {
                $releaseQuery->with('nzbCreationFailure');
            }

            return $releaseQuery->get();
        }, 3);
    }

    public static function clearClaim(int $releaseId, ?string $token = null): void
    {
        if (! self::supportsClaims()) {
            return;
        }

        $query = Release::query()->where('id', $releaseId);
        if ($token !== null && $token !== '') {
            $query->where(self::CLAIM_TOKEN_COLUMN, $token);
        }

        $query->update([
            self::CLAIMED_AT_COLUMN => null,
            self::CLAIM_TOKEN_COLUMN => null,
        ]);
    }

    public static function supportsClaims(): bool
    {
        if (self::$supportsClaims !== null) {
            return self::$supportsClaims;
        }

        if (! Schema::hasTable('releases')) {
            return self::$supportsClaims = false;
        }

        return self::$supportsClaims = Schema::hasColumn('releases', self::CLAIMED_AT_COLUMN)
            && Schema::hasColumn('releases', self::CLAIM_TOKEN_COLUMN);
    }

    public static function supportsFailureState(): bool
    {
        return self::$supportsFailureState ??= Schema::hasTable('release_nzb_creation_failures');
    }

    /**
     * Discard the memoized schema capability flags. Only needed when the schema
     * changes inside a single process, such as between tests.
     */
    public static function flushCapabilityCache(): void
    {
        self::$supportsClaims = null;
        self::$supportsFailureState = null;
    }

    /**
     * @param  Builder<Release>  $query
     */
    private static function applyClaimWindow(Builder $query): void
    {
        if (! self::supportsClaims()) {
            return;
        }

        $staleBefore = now()->subSeconds(self::claimTtlSeconds());

        $query->where(function (Builder $claimQuery) use ($staleBefore): void {
            $claimQuery
                ->whereNull('r.'.self::CLAIMED_AT_COLUMN)
                ->orWhere('r.'.self::CLAIMED_AT_COLUMN, '<', $staleBefore);
        });
    }

    private static function claimTtlSeconds(): int
    {
        return self::$useRustFfi ? self::claimTtlSecondsNative() : self::claimTtlSecondsPhp();
    }

    public static function claimTtlSecondsNative(): int
    {
        $timeout = (int) (app(ConfigurationProvider::class)->postProcessing()->releaseTimeoutSeconds ?: 120);
        try {
            $ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF);
            // PHP promotes a positive overflowing product to float; preserve its return-type error.
            if ($ffi !== null && $timeout <= intdiv(PHP_INT_MAX, 2)) {
                // @phpstan-ignore method.notFound
                $result = $ffi->nntmux_nzb_claim_ttl($timeout);
                if (is_int($result)) {
                    return $result;
                }
            }
        } catch (Throwable) {
            // The configuration/provider lookup has already happened exactly once.
        }

        return max(300, $timeout * 2);
    }

    public static function claimTtlSecondsPhp(): int
    {
        $timeout = (int) (app(ConfigurationProvider::class)->postProcessing()->releaseTimeoutSeconds ?: 120);

        return max(300, $timeout * 2);
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    private static function selectableColumns(array $columns, bool $supportsClaims): array
    {
        return self::$useRustFfi ? self::selectableColumnsNative($columns, $supportsClaims) : self::selectableColumnsPhp($columns, $supportsClaims);
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    public static function selectableColumnsNative(array $columns, bool $supportsClaims): array
    {
        if (! array_is_list($columns)) {
            return self::selectableColumnsPhp($columns, $supportsClaims);
        }
        $input = '';
        foreach ($columns as $column) {
            if (! is_string($column) || strlen($column) > 0xFFFFFFFF) {
                return self::selectableColumnsPhp($columns, $supportsClaims);
            }
            $input .= pack('N', strlen($column)).$column;
        }
        $output = self::nativeBytes('nntmux_nzb_selectable_columns', [$input, strlen($input), (int) $supportsClaims], strlen($input));
        if ($output === null) {
            return self::selectableColumnsPhp($columns, $supportsClaims);
        }
        $result = [];
        $length = strlen($output);
        for ($offset = 0; $offset < $length;) {
            if ($length - $offset < 4) {
                return self::selectableColumnsPhp($columns, $supportsClaims);
            }
            $size = unpack('Nlength', $output, $offset)['length'];
            $offset += 4;
            if ($size > $length - $offset) {
                return self::selectableColumnsPhp($columns, $supportsClaims);
            }
            $result[] = substr($output, $offset, $size);
            $offset += $size;
        }

        return $result;
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    public static function selectableColumnsPhp(array $columns, bool $supportsClaims): array
    {
        if ($supportsClaims || $columns === ['*']) {
            return $columns;
        }

        return array_values(array_filter(
            $columns,
            static fn (string $column): bool => ! in_array($column, [
                self::CLAIMED_AT_COLUMN,
                self::CLAIM_TOKEN_COLUMN,
            ], true),
        ));
    }

    /**
     * @param  list<int>  $ids
     */
    private static function idOrderExpression(array $ids): string
    {
        return self::$useRustFfi ? self::idOrderExpressionNative($ids) : self::idOrderExpressionPhp($ids);
    }

    /** @param list<int> $ids */
    public static function idOrderExpressionNative(array $ids): string
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        if (array_is_list($ids)) {
            $integers = true;
            foreach ($ids as $id) {
                if (! is_int($id)) {
                    $integers = false;
                    break;
                }
            }
            if ($integers) {
                $input = $ids === [] ? '' : pack('q*', ...$ids);
                $output = self::nativeBytes('nntmux_nzb_id_order', [$input, strlen($input), (int) $sqlite], count($ids) * 48 + 64);
                if ($output !== null) {
                    return $output;
                }
            }
        }

        return self::idOrderExpressionForDriverPhp($ids, $sqlite);
    }

    /** @param list<int> $ids */
    public static function idOrderExpressionPhp(array $ids): string
    {
        return self::idOrderExpressionForDriverPhp($ids, DB::getDriverName() === 'sqlite');
    }

    /** @param list<int> $ids */
    private static function idOrderExpressionForDriverPhp(array $ids, bool $sqlite): string
    {
        if (! $sqlite) {
            return 'FIELD(id, '.implode(',', $ids).')';
        }

        $cases = [];
        foreach ($ids as $position => $id) {
            $cases[] = 'WHEN '.(int) $id.' THEN '.(int) $position;
        }

        return 'CASE id '.implode(' ', $cases).' ELSE '.count($ids).' END';
    }

    /**
     * Default for the Wave 41.2 toggle only; it stays false because the paired benchmark in
     * tests/Unit/RustNzbSyncNfoImportCoverageCandidateW41ParityTest.php is below 1.5x.
     */
    public const bool W412_RUST_FFI_DEFAULT = false;

    private static ?bool $useW412RustFfi = null;

    public static function useW412RustFfi(bool $enable = true): void
    {
        self::$useW412RustFfi = $enable;
    }

    public static function usesW412RustFfi(): bool
    {
        return self::$useW412RustFfi ?? self::W412_RUST_FFI_DEFAULT;
    }

    public static function resetW412RustFfi(): void
    {
        self::$useW412RustFfi = null;
    }

    /**
     * Summarize an NZB creation claim window over candidates (`id`, `claimed_at` Unix seconds). Ids are valid only as
     * positive integers; repeats of a valid id are duplicates. Every candidate's claim is unclaimed (null or missing),
     * future (after $now), stale (claimed at least max(0, $ttlSeconds) before $now), fresh, or malformed (any other
     * value). A valid id is admissible when its first occurrence is unclaimed or stale.
     *
     * @param  array<array-key, mixed>  $candidates
     * @return array{candidates: int, invalid_ids: int, duplicate_ids: int, unclaimed: int, fresh: int, stale: int, future: int, malformed_claims: int, admissible: int}
     */
    public static function summarizeClaimWindow(array $candidates, int $now, int $ttlSeconds): array
    {
        return (self::usesW412RustFfi() ? self::summarizeClaimWindowNative($candidates, $now, $ttlSeconds) : null)
            ?? self::summarizeClaimWindowPhp($candidates, $now, $ttlSeconds);
    }

    /**
     * @param  array<array-key, mixed>  $candidates
     * @return array{candidates: int, invalid_ids: int, duplicate_ids: int, unclaimed: int, fresh: int, stale: int, future: int, malformed_claims: int, admissible: int}
     */
    public static function summarizeClaimWindowPhp(array $candidates, int $now, int $ttlSeconds): array
    {
        $ttl = max(0, $ttlSeconds);
        $invalid = $duplicates = $unclaimed = $fresh = $stale = $future = $malformed = $admissible = 0;
        $seen = [];
        foreach ($candidates as $candidate) {
            [$id, $kind, $claimed] = self::claimWindowFields($candidate);
            if ($kind === 0) {
                $unclaimed++;
                $open = true;
            } elseif ($kind === 2) {
                $malformed++;
                $open = false;
            } elseif ($claimed > $now) {
                $future++;
                $open = false;
            } elseif ($claimed < 0 ? $now >= $ttl + $claimed : $now - $claimed >= $ttl) {
                // Both forms avoid overflow: ttl + claimed stays in range for a negative claim, now - claimed for the rest.
                $stale++;
                $open = true;
            } else {
                $fresh++;
                $open = false;
            }
            if ($id <= 0) {
                $invalid++;
            } elseif (isset($seen[$id])) {
                $duplicates++;
            } else {
                $seen[$id] = true;
                $admissible += $open ? 1 : 0;
            }
        }

        return ['candidates' => \count($candidates), 'invalid_ids' => $invalid, 'duplicate_ids' => $duplicates, 'unclaimed' => $unclaimed, 'fresh' => $fresh,
            'stale' => $stale, 'future' => $future, 'malformed_claims' => $malformed, 'admissible' => $admissible];
    }

    /**
     * @param  array<array-key, mixed>  $candidates
     * @return array{candidates: int, invalid_ids: int, duplicate_ids: int, unclaimed: int, fresh: int, stale: int, future: int, malformed_claims: int, admissible: int}|null
     */
    public static function summarizeClaimWindowNative(array $candidates, int $now, int $ttlSeconds): ?array
    {
        if (\count($candidates) * 3 + 2 > DirectorySyncBridge::W412_MAX_INPUT / 8) {
            return null;
        }
        $words = [$now, $ttlSeconds];
        foreach ($candidates as $candidate) {
            array_push($words, ...self::claimWindowFields($candidate));
        }
        $slots = DirectorySyncBridge::evaluateW412Native(3, pack('q*', ...$words), 9);
        if ($slots === null) {
            return null;
        }
        [$count, $invalid, $duplicates, $unclaimed, $fresh, $stale, $future, $malformed, $admissible] = $slots;
        if ($count !== \count($candidates) || min($slots) < 0 || $unclaimed + $fresh + $stale + $future + $malformed !== $count
            || $invalid + $duplicates + $admissible > $count) {
            return null;
        }

        return ['candidates' => $count, 'invalid_ids' => $invalid, 'duplicate_ids' => $duplicates, 'unclaimed' => $unclaimed, 'fresh' => $fresh,
            'stale' => $stale, 'future' => $future, 'malformed_claims' => $malformed, 'admissible' => $admissible];
    }

    /** @return array{int, 0|1|2, int} the id (0 unless an integer), claim kind (0 none, 1 timestamp, 2 malformed) and timestamp */
    private static function claimWindowFields(mixed $candidate): array
    {
        if (! \is_array($candidate)) {
            return [0, 0, 0];
        }
        $id = $candidate['id'] ?? 0;
        $claimed = $candidate['claimed_at'] ?? null;

        return [\is_int($id) ? $id : 0, $claimed === null ? 0 : (\is_int($claimed) ? 1 : 2), \is_int($claimed) ? $claimed : 0];
    }

    /** Wave 56.2 paired benchmarks decide this default; see RustDirectorySyncCoverageCreationImportNzbServiceW56ParityTest. */
    private const bool W56_RUST_FFI_DEFAULT = true;

    private const string W56_FFI_CDEF = <<<'C'
        intptr_t nntmux_w56_creation_candidate_plan_batch(const char *ints, size_t ints_len, size_t count, int64_t min_completion, int64_t limit, int64_t *out, size_t out_cap);
        C;

    /**
     * Wave 56 routing, binding and output buffer, kept in function-local statics. Operations:
     * null reads the routing override, 'set' replaces it (returning the previous one), 'ffi'
     * resolves the binding and 'out' returns `[binding, buffer, capacity]` with room for at
     * least $val words (null without the library).
     */
    private static function w56RustFfiState(?string $op = null, mixed $val = null): mixed
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
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w56', self::W56_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w56RustFfiState('ffi');
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

    public static function usesW56RustFfi(): bool
    {
        $enabled = self::w56RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W56_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with planCandidateBatch() routed to Rust ($enabled) or PHP, then restores the
     * previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW56RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w56RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w56RustFfiState('set', $previous);
        }
    }

    /** @param  list<int>  $out */
    private static function w56Digest(array $out): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));

        return $digest[1];
    }

    /**
     * The words the export wrote, or null when it declined (returning anything but $words).
     *
     * @return list<int>|null
     */
    private static function w56Words(mixed $written, CData $out, int $words): ?array
    {
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : null;
    }

    /**
     * Batch NZB creation candidate plan over (id, received parts, total parts, postdate). Per
     * candidate: `flags | completion_pct << 8` and its rank in claimBatch() order (postdate
     * descending, then id) among qualifiers (-1 otherwise). Non-integer values count as 0. The
     * completion is exact `min(max(0, received), total) * 100 / total` up to PHP_INT_MAX / 100,
     * else `min(100, received / (total / 100))`, and 0 without a positive total. Flags: 1
     * positive id, 2 repeats an earlier id, 4 every part received, 8 qualifies (first positive
     * id, positive total, completion >= clamp($minCompletionPct, 0, 100)), 16 selected (rank
     * below $limit, or any rank when $limit <= 0). Unique ids, duplicates, complete, qualifying,
     * selected and the XXH64 digest of all earlier words follow.
     *
     * @param  list<int>  $ids
     * @param  list<int>  $received
     * @param  list<int>  $totals
     * @param  list<int>  $postdates
     * @return list<int>
     */
    public static function planCandidateBatch(array $ids, array $received, array $totals, array $postdates, int $minCompletionPct = 100, int $limit = 0): array
    {
        return self::usesW56RustFfi() ? self::planCandidateBatchNative($ids, $received, $totals, $postdates, $minCompletionPct, $limit)
            : self::planCandidateBatchPhp($ids, $received, $totals, $postdates, $minCompletionPct, $limit);
    }

    /**
     * @param  list<int>  $ids
     * @param  list<int>  $received
     * @param  list<int>  $totals
     * @param  list<int>  $postdates
     * @return list<int>
     */
    public static function planCandidateBatchPhp(array $ids, array $received, array $totals, array $postdates, int $minCompletionPct = 100, int $limit = 0): array
    {
        $min = min(100, max(0, $minCompletionPct));
        [$received, $totals, $postdates] = [array_values($received), array_values($totals), array_values($postdates)];
        $ids = array_map(static fn (mixed $id): int => \is_int($id) ? $id : 0, array_values($ids));
        $dates = $seen = $flags = $qualifiers = [];
        $unique = $duplicates = $complete = 0;
        foreach ($ids as $i => $id) {
            $got = $received[$i] ?? 0;
            $got = \is_int($got) ? $got : 0;
            $total = $totals[$i] ?? 0;
            $total = \is_int($total) ? $total : 0;
            $date = $postdates[$i] ?? 0;
            $dates[$i] = \is_int($date) ? $date : 0;
            $f = $pct = 0;
            if ($id > 0) {
                $f = 1;
                if (isset($seen[$id])) {
                    $f |= 2;
                    $duplicates++;
                } else {
                    $seen[$id] = true;
                    $unique++;
                }
            }
            if ($total > 0) {
                if ($got >= $total) {
                    $f |= 4;
                    $complete++;
                }
                $got = min(max(0, $got), $total);
                $pct = $total <= 92233720368547758 ? intdiv($got * 100, $total) : min(100, intdiv($got, intdiv($total, 100)));
                if (($f & 3) === 1 && $pct >= $min) {
                    $f |= 8;
                    $qualifiers[] = $i;
                }
            }
            $flags[$i] = $f | $pct << 8;
        }
        usort($qualifiers, static fn (int $a, int $b): int => $dates[$b] <=> $dates[$a] ?: $ids[$a] <=> $ids[$b]);
        $ranks = array_fill(0, \count($ids), -1);
        $selected = 0;
        foreach ($qualifiers as $rank => $i) {
            $ranks[$i] = $rank;
            if ($limit <= 0 || $rank < $limit) {
                $flags[$i] |= 16;
                $selected++;
            }
        }
        $out = [];
        foreach ($flags as $i => $f) {
            array_push($out, $f, $ranks[$i]);
        }
        array_push($out, $unique, $duplicates, $complete, \count($qualifiers), $selected);
        $out[] = self::w56Digest($out);

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or the columns are not integer
     * lists of one length.
     *
     * @param  list<int>  $ids
     * @param  list<int>  $received
     * @param  list<int>  $totals
     * @param  list<int>  $postdates
     * @return list<int>
     */
    public static function planCandidateBatchNative(array $ids, array $received, array $totals, array $postdates, int $minCompletionPct = 100, int $limit = 0): array
    {
        $count = \count($ids);
        $words = 2 * $count + 6;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 || \count($received) !== $count || \count($totals) !== $count || \count($postdates) !== $count
            ? null : self::w56RustFfiState('out', $words);
        if ($state === null || ! array_is_list($ids) || ! array_is_list($received) || ! array_is_list($totals) || ! array_is_list($postdates)) {
            return self::planCandidateBatchPhp($ids, $received, $totals, $postdates, $minCompletionPct, $limit);
        }
        foreach ($ids as $i => $id) {
            if (! \is_int($id) || ! \is_int($received[$i]) || ! \is_int($totals[$i]) || ! \is_int($postdates[$i])) {
                return self::planCandidateBatchPhp($ids, $received, $totals, $postdates, $minCompletionPct, $limit);
            }
        }
        $table = pack('P*', ...$ids, ...$received, ...$totals, ...$postdates);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W56_FFI_CDEF.)
            $written = $ffi->nntmux_w56_creation_candidate_plan_batch($table, \strlen($table), $count, $minCompletionPct, $limit, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }

        return self::w56Words($written, $out, $words) ?? self::planCandidateBatchPhp($ids, $received, $totals, $postdates, $minCompletionPct, $limit);
    }
}
