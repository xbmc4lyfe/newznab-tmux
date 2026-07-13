<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Models\Release;
use App\Models\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class NzbCreationCandidateQuery
{
    public const string CLAIMED_AT_COLUMN = 'nzb_creation_claimed_at';

    public const string CLAIM_TOKEN_COLUMN = 'nzb_creation_claim_token';

    public const string ATTEMPTS_COLUMN = 'nzb_creation_attempts';

    public const string LAST_ERROR_COLUMN = 'nzb_creation_last_error';

    /**
     * @return Builder<Release>
     */
    public static function baseBuilder(int|string|null $groupID = null, bool $includeClaimed = false): Builder
    {
        $query = Release::query()
            ->from('releases as r')
            ->where('r.nzbstatus', NzbService::NZB_NONE);

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
     * @return EloquentCollection<int, Release>
     */
    public static function claimBatch(
        int|string|null $groupID,
        int $limit,
        string $token,
        array $columns = ['*'],
    ): EloquentCollection {
        $effectiveLimit = max(1, $limit);

        return DB::transaction(function () use ($groupID, $effectiveLimit, $token, $columns): EloquentCollection {
            $supportsClaims = self::supportsClaims();
            $query = self::baseBuilder($groupID)
                ->select('r.id')
                ->orderByDesc('r.postdate')
                ->orderBy('r.id')
                ->limit($effectiveLimit);

            if (DB::getDriverName() !== 'sqlite') {
                $query->lockForUpdate();
            }

            $ids = $query
                ->pluck('r.id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

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

            return Release::query()
                ->whereIn('id', $ids)
                ->with('category.parent')
                ->select(self::selectableColumns($columns, $supportsClaims))
                ->orderByRaw(self::idOrderExpression($ids))
                ->get();
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
        if (! Schema::hasTable('releases')) {
            return false;
        }

        return Schema::hasColumn('releases', self::CLAIMED_AT_COLUMN)
            && Schema::hasColumn('releases', self::CLAIM_TOKEN_COLUMN)
            && Schema::hasColumn('releases', self::ATTEMPTS_COLUMN)
            && Schema::hasColumn('releases', self::LAST_ERROR_COLUMN);
    }

    /**
     * @return array<string, mixed>
     */
    public static function failureUpdateValues(string $reason, bool $incrementAttempts): array
    {
        if (! self::supportsClaims()) {
            return [];
        }

        $values = [
            self::CLAIMED_AT_COLUMN => null,
            self::CLAIM_TOKEN_COLUMN => null,
            self::LAST_ERROR_COLUMN => mb_substr($reason, 0, 1000),
        ];

        if ($incrementAttempts) {
            $values[self::ATTEMPTS_COLUMN] = DB::raw(self::ATTEMPTS_COLUMN.' + 1');
        }

        return $values;
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
        $timeout = (int) (Settings::settingValue('releaseprocessingtimeout') ?: 120);

        return max(300, $timeout * 2);
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    private static function selectableColumns(array $columns, bool $supportsClaims): array
    {
        if ($supportsClaims || $columns === ['*']) {
            return $columns;
        }

        return array_values(array_filter(
            $columns,
            static fn (string $column): bool => ! in_array($column, [
                self::CLAIMED_AT_COLUMN,
                self::CLAIM_TOKEN_COLUMN,
                self::ATTEMPTS_COLUMN,
                self::LAST_ERROR_COLUMN,
            ], true),
        ));
    }

    /**
     * @param  list<int>  $ids
     */
    private static function idOrderExpression(array $ids): string
    {
        if (DB::getDriverName() !== 'sqlite') {
            return 'FIELD(id, '.implode(',', $ids).')';
        }

        $cases = [];
        foreach ($ids as $position => $id) {
            $cases[] = 'WHEN '.(int) $id.' THEN '.(int) $position;
        }

        return 'CASE id '.implode(' ', $cases).' ELSE '.count($ids).' END';
    }
}
