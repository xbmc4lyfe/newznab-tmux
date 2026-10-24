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
}
