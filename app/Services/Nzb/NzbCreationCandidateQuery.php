<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Models\Release;
use App\Services\Configuration\ConfigurationProvider;
use App\Support\RustPipelineLibrary;
use FFI;
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
}
