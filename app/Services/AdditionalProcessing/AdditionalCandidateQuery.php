<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use App\Models\Release;
use App\Services\Configuration\ConfigurationProvider;
use App\Services\Runners\PostProcessRunner;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Single source of truth for "needs additional postprocessing" release selection.
 *
 * Both the bucket-fanout query in
 * {@see PostProcessRunner::processAdditional()} and the
 * per-worker release fetch in
 * {@see AdditionalProcessingOrchestrator::fetchReleases()}
 * MUST go through this class so their predicates can never drift apart.
 *
 * History: the two queries were maintained independently, and a mismatch on
 * size filters / nzbstatus caused releases to be advertised by the bucket
 * query but rejected by the orchestrator, accumulating forever in the
 * "passwordstatus=-1, haspreview=-1" backlog.
 */
final class AdditionalCandidateQuery
{
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    /** Default size lower bound when the setting is empty/unset (bytes, 1 MB). */
    public const int DEFAULT_MIN_SIZE_BYTES = 1048576;

    /** Default size upper bound when the setting is empty/unset (bytes, 100 GB). */
    public const int DEFAULT_MAX_SIZE_BYTES = 107374182400;

    /**
     * Hard cap on the bucket fan-out. `leftguid` is the first character of a
     * hex GUID, so there are at most 16 distinct values (0-9, a-f). There is
     * no reason to dispatch more buckets than that per scheduler cycle.
     * Per-cycle concurrency is governed by the existing `postthreads` setting
     * inside {@see PostProcessRunner::runPostProcess()},
     * so a separate setting is unnecessary.
     */
    public const int BUCKET_LIMIT = 16;

    /** Candidates read per claimed release, so rows held by other workers can be skipped. */
    public const int CLAIM_CANDIDATE_FACTOR = 4;

    public const string CLAIMED_AT_COLUMN = 'additional_pp_claimed_at';

    public const string CLAIM_TOKEN_COLUMN = 'additional_pp_claim_token';

    /** Set once when an unreadable archive is queued for its single retry; never cleared. */
    public const string ARCHIVE_RETRY_COLUMN = 'archive_retry_at';

    private static ?bool $supportsClaims = null;

    /**
     * Resolve the minimum-size filter (bytes). Returns 0 when disabled.
     *
     * An explicit '0' setting means "no minimum size filter". An empty/null
     * setting falls back to {@see self::DEFAULT_MIN_SIZE_BYTES}.
     */
    public static function minSizeBytes(): int
    {
        $value = app(ConfigurationProvider::class)->postProcessing()->minSizeToPostProcess;
        if ($value === '' || $value === null) {
            return self::DEFAULT_MIN_SIZE_BYTES;
        }

        return max(0, (int) $value);
    }

    /**
     * Resolve the maximum-size filter (bytes). Returns 0 when disabled.
     *
     * An explicit '0' setting means "no maximum size filter". An empty/null
     * setting falls back to {@see self::DEFAULT_MAX_SIZE_BYTES}.
     */
    public static function maxSizeBytes(): int
    {
        $value = app(ConfigurationProvider::class)->postProcessing()->maxSizeToPostProcess;
        if ($value === '' || $value === null) {
            return self::DEFAULT_MAX_SIZE_BYTES;
        }

        return max(0, (int) $value);
    }

    /**
     * Apply the candidate-selection predicates to an Eloquent builder.
     *
     * The builder MUST already be aliased as `r` for releases and joined to
     * `categories as c`. Optional group / GUID-character constraints can be
     * applied on top.
     *
     * @param  Builder<Release>  $query
     * @return Builder<Release>
     */
    public static function applyPredicates(
        Builder $query,
        int|string $groupID = '',
        string $guidChar = '',
        ?int $minSizeBytes = null,
        ?int $maxSizeBytes = null,
        bool $includeClaimed = false,
    ): Builder {
        self::applyPasswordStatus($query);

        return self::applyProcessingPredicates($query, $groupID, $guidChar, $minSizeBytes, $maxSizeBytes, $includeClaimed);
    }

    /**
     * @param  Builder<Release>  $query
     * @return Builder<Release>
     */
    private static function applyProcessingPredicates(
        Builder $query,
        int|string $groupID = '',
        string $guidChar = '',
        ?int $minSizeBytes = null,
        ?int $maxSizeBytes = null,
        bool $includeClaimed = false,
        bool $joinedCategory = true,
    ): Builder {
        $min = $minSizeBytes ?? self::minSizeBytes();
        $max = $maxSizeBytes ?? self::maxSizeBytes();
        $query
            ->where('r.haspreview', -1)
            ->where('r.nzbstatus', 1);
        // A partial awaiting late articles may have its NZB replaced; process it once that can't happen.
        Release::whereNzbSettled($query, 'r');
        if ($joinedCategory) {
            $query->where('c.disablepreview', 0);
        } else {
            // A scalar primary-key lookup cannot reorder the release queue via
            // a block nested-loop category join before the prefix LIMIT.
            $query->where(
                static function (QueryBuilder $categoryQuery): void {
                    $categoryQuery->select('c.disablepreview')->from('categories as c')->whereColumn('c.id', 'r.categories_id');
                },
                0,
            );
        }
        self::applyArchiveRetryWindow($query);
        if ($min > 0) {
            $query->where('r.size', '>', $min);
        }
        if ($max > 0) {
            $query->where('r.size', '<', $max);
        }
        if ($groupID !== '' && $groupID !== 0 && $groupID !== '0') {
            $query->where('r.groups_id', $groupID);
        }
        if ($guidChar !== '') {
            $query->where('r.leftguid', $guidChar);
        }
        if (! $includeClaimed) {
            self::applyClaimWindow($query);
        }

        return $query;
    }

    /**
     * Return a fresh Eloquent builder, joined and predicate-applied, ready for
     * the orchestrator to add selects / order / limit.
     *
     * @return Builder<Release>
     */
    public static function baseBuilder(
        int|string $groupID = '',
        string $guidChar = '',
        ?int $minSizeBytes = null,
        ?int $maxSizeBytes = null,
        bool $includeClaimed = false,
    ): Builder {
        return self::applyPredicates(self::joinedBuilder(), $groupID, $guidChar, $minSizeBytes, $maxSizeBytes, $includeClaimed);
    }

    /** @return Builder<Release> */
    private static function joinedBuilder(): Builder
    {
        return Release::query()
            ->from('releases as r')
            ->leftJoin('categories as c', 'c.id', '=', 'r.categories_id');
    }

    /**
     * Return up to {@see self::BUCKET_LIMIT} distinct GUID first-characters
     * that have at least one release matching the candidate predicates.
     *
     * The fan-out is capped at 16 because `leftguid` is a single hex digit.
     * Worker concurrency is then capped further by the `postthreads` setting
     * in {@see PostProcessRunner::runPostProcess()}.
     *
     * @return array<int, string>
     */
    public static function bucketChars(?int $limit = null): array
    {
        $effectiveLimit = $limit !== null && $limit > 0
            ? min($limit, self::BUCKET_LIMIT)
            : self::BUCKET_LIMIT;

        return array_slice(
            array_column(self::availableBucketCounts(), 'bucket'),
            0,
            $effectiveLimit,
        );
    }

    /**
     * Return available candidate counts keyed by GUID bucket.
     *
     * @return list<array{bucket: string, count: int}>
     */
    public static function availableBucketCounts(): array
    {
        $counts = [];

        foreach (self::bucketBacklog() as $backlog) {
            if ($backlog['available'] > 0) {
                $counts[] = [
                    'bucket' => $backlog['bucket'],
                    'count' => $backlog['available'],
                ];
            }
        }

        return $counts;
    }

    /**
     * Return total and currently claimable candidates for every GUID bucket.
     *
     * @return list<array{bucket: string, total: int, available: int}>
     */
    public static function bucketBacklog(): array
    {
        $query = self::baseBuilder(includeClaimed: true)
            ->select('r.leftguid')
            ->selectRaw('COUNT(*) AS total_count')
            ->groupBy('r.leftguid')
            ->orderBy('r.leftguid');

        if (self::supportsClaims()) {
            $query->selectRaw(
                'SUM(CASE WHEN r.'.self::CLAIMED_AT_COLUMN.' IS NULL OR r.'.self::CLAIMED_AT_COLUMN.' < ? THEN 1 ELSE 0 END) AS available_count',
                [self::claimStaleBefore()],
            );
        } else {
            $query->selectRaw('COUNT(*) AS available_count');
        }

        $backlog = [];
        foreach ($query->get() as $row) {
            $bucket = strtolower(substr((string) ($row->leftguid ?? ''), 0, 1));
            if ($bucket === '') {
                continue;
            }

            $backlog[] = [
                'bucket' => $bucket,
                'total' => (int) ($row->total_count ?? 0),
                'available' => (int) ($row->available_count ?? 0),
            ];
        }

        return $backlog;
    }

    /**
     * @return array{total: int, available: int}
     */
    public static function backlogCounts(bool $sequential = false): array
    {
        $query = self::baseBuilder(includeClaimed: true)
            ->selectRaw('COUNT(*) AS total_count');

        if ($sequential) {
            $query->whereRaw(self::sequentialGroupCondition());
        }

        if (self::supportsClaims()) {
            $query->selectRaw(
                'SUM(CASE WHEN r.'.self::CLAIMED_AT_COLUMN.' IS NULL OR r.'.self::CLAIMED_AT_COLUMN.' < ? THEN 1 ELSE 0 END) AS available_count',
                [self::claimStaleBefore()],
            );
        } else {
            $query->selectRaw('COUNT(*) AS available_count');
        }

        /** @var object{total_count: int|string|null, available_count: int|string|null}|null $counts */
        $counts = $query->toBase()->first();

        return [
            'total' => (int) ($counts->total_count ?? 0),
            'available' => (int) ($counts->available_count ?? 0),
        ];
    }

    /** Groups selected by the stripped-mode update-per-group runner. */
    public static function sequentialGroupCondition(): string
    {
        return 'EXISTS (SELECT 1 FROM usenet_groups sequential_group'
            .' WHERE sequential_group.id = r.groups_id AND (sequential_group.active = 1 OR sequential_group.backfill = 1))';
    }

    /**
     * True when there is at least one candidate release anywhere (any char).
     * Used by the drain command to know when to stop looping.
     */
    public static function hasAnyCandidate(): bool
    {
        return self::baseBuilder()->limit(1)->exists();
    }

    /**
     * Claim a bounded batch of release rows for one worker.
     *
     * @param  list<string>  $columns
     * @param  list<int>  $excludedReleaseIds
     * @return EloquentCollection<int, Release>
     */
    public static function claimBatch(
        string $guidChar,
        int $limit,
        string $token,
        int|string $groupID = '',
        ?int $minSizeBytes = null,
        ?int $maxSizeBytes = null,
        array $columns = ['*'],
        array $excludedReleaseIds = [],
    ): EloquentCollection {
        $effectiveLimit = max(1, $limit);

        return DB::transaction(function () use ($guidChar, $effectiveLimit, $token, $groupID, $minSizeBytes, $maxSizeBytes, $columns, $excludedReleaseIds): EloquentCollection {
            $supportsClaims = self::supportsClaims();
            $passwordChecksEnabled = config('nntmux_settings.check_passworded_rars') === true;
            // Pick candidates without a lock: FOR UPDATE would also lock the joined
            // category rows, which every release shares, and serialize all workers.
            $query = self::applyProcessingPredicates(
                $passwordChecksEnabled ? self::joinedBuilder() : Release::query()->from('releases as r'),
                $groupID, $guidChar, $minSizeBytes, $maxSizeBytes,
                joinedCategory: $passwordChecksEnabled,
            )
                ->select('r.id', 'r.postdate')
                ->orderByDesc('r.postdate')
                ->orderBy('r.id')
                ->limit($effectiveLimit * self::CLAIM_CANDIDATE_FACTOR);

            if ($excludedReleaseIds !== []) {
                $query->whereNotIn('r.id', $excludedReleaseIds);
            }

            if ($passwordChecksEnabled) {
                $candidateIds = $query->where('r.passwordstatus', -1)->pluck('r.id');
            } else {
                // Each equality range can retain the queue index's ordering. Sort
                // only two bounded prefixes, rather than the entire combined backlog.
                $unchecked = (clone $query)->where('r.passwordstatus', -1)->toBase();
                $passwordDisabled = (clone $query)->where('r.passwordstatus', 0)->toBase();
                $candidateIds = DB::query()
                    ->fromSub($unchecked->unionAll($passwordDisabled), 'additional_candidates')
                    ->orderByDesc('postdate')
                    ->orderBy('id')
                    ->limit($effectiveLimit * self::CLAIM_CANDIDATE_FACTOR)
                    ->pluck('id');
            }

            $candidateIds = $candidateIds
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
            if ($candidateIds === []) {
                return (new Release)->newCollection();
            }

            // Lock only release rows, in small chunks in candidate order, skipping rows
            // another worker holds.
            $ids = [];
            foreach (array_chunk($candidateIds, $effectiveLimit) as $chunk) {
                $lockQuery = Release::query()
                    ->from('releases as r')
                    ->select('r.id')
                    ->whereIn('r.id', $chunk);
                self::applyPasswordStatus($lockQuery);
                $lockQuery
                    ->where('r.haspreview', -1)
                    ->where('r.nzbstatus', 1);
                Release::whereNzbSettled($lockQuery, 'r');
                self::applyArchiveRetryWindow($lockQuery);
                self::applyClaimWindow($lockQuery);

                if (DB::getDriverName() !== 'sqlite') {
                    // A locking read locks every row it scans; the primary key keeps that to this chunk.
                    $lockQuery->forceIndex('PRIMARY')->lock('for update skip locked');
                }

                $locked = $lockQuery
                    ->pluck('r.id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all();
                array_push($ids, ...array_values(array_intersect($chunk, $locked)));
                if (count($ids) >= $effectiveLimit) {
                    break;
                }
            }
            $ids = array_slice($ids, 0, $effectiveLimit);

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

    /**
     * @return array<string, null>
     */
    public static function claimResetValues(): array
    {
        if (! self::supportsClaims()) {
            return [];
        }

        return [
            self::CLAIMED_AT_COLUMN => null,
            self::CLAIM_TOKEN_COLUMN => null,
        ];
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

    /**
     * Insertion uses zero when password checks are disabled; pending media still needs processing.
     *
     * @param  Builder<Release>  $query
     */
    private static function applyPasswordStatus(Builder $query): void
    {
        if (config('nntmux_settings.check_passworded_rars') === true) {
            $query->where('r.passwordstatus', -1);
        } else {
            $query->whereIn('r.passwordstatus', [-1, 0]);
        }
    }

    /**
     * @param  Builder<Release>  $query
     */
    private static function applyClaimWindow(Builder $query): void
    {
        if (! self::supportsClaims()) {
            return;
        }

        $staleBefore = self::claimStaleBefore();

        $query->where(function (Builder $claimQuery) use ($staleBefore): void {
            $claimQuery
                ->whereNull('r.'.self::CLAIMED_AT_COLUMN)
                ->orWhere('r.'.self::CLAIMED_AT_COLUMN, '<', $staleBefore);
        });
    }

    /**
     * Keep a release queued for an archive retry out of the candidates until it is due.
     *
     * @param  Builder<Release>  $query
     */
    private static function applyArchiveRetryWindow(Builder $query): void
    {
        $query->where(function (Builder $retryQuery): void {
            $retryQuery
                ->whereNull('r.'.self::ARCHIVE_RETRY_COLUMN)
                ->orWhere('r.'.self::ARCHIVE_RETRY_COLUMN, '<=', now());
        });
    }

    public static function archiveRetryDelaySeconds(): int
    {
        return max(0, (int) config('nntmux.archive_retry_delay', 86400));
    }

    public static function claimTtlSeconds(): int
    {
        $timeout = (int) (app(ConfigurationProvider::class)->postProcessing()->releaseTimeoutSeconds ?: 120);

        return max(300, $timeout * 2);
    }

    public static function claimStaleBefore(): Carbon
    {
        return now()->subSeconds(self::claimTtlSeconds());
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
            static fn (string $column): bool => ! in_array($column, [self::CLAIMED_AT_COLUMN, self::CLAIM_TOKEN_COLUMN], true),
        ));
    }

    /**
     * @param  list<int>  $ids
     */
    public static function idOrderExpression(array $ids, ?string $driver = null): string
    {
        $driver ??= DB::getDriverName();

        return self::$useRustFfi ? (self::idOrderExpressionNative($ids, $driver) ?? self::idOrderExpressionPhp($ids, $driver)) : self::idOrderExpressionPhp($ids, $driver);
    }

    /** @param list<int> $ids */
    public static function idOrderExpressionNative(array $ids, string $driver): ?string
    {
        $value = self::nativeJson(['ids' => $ids, 'driver' => $driver]);

        return is_string($value) ? $value : null;
    }

    /** @param list<int> $ids */
    public static function idOrderExpressionPhp(array $ids, string $driver): string
    {
        if ($driver !== 'sqlite') {
            return 'FIELD(id, '.implode(',', $ids).')';
        }

        $cases = [];
        foreach ($ids as $position => $id) {
            $cases[] = 'WHEN '.(int) $id.' THEN '.(int) $position;
        }

        return 'CASE id '.implode(' ', $cases).' ELSE '.count($ids).' END';
    }

    /** @param array<mixed> $input */
    private static function nativeJson(array $input): mixed
    {
        try {
            $ffi = RustPipelineLibrary::resolveDomain('additional',
                'ssize_t nntmux_additional_id_order_expression_json(const char *input, size_t len, char *out, size_t cap);');
            if ($ffi === null) {
                return null;
            }
            $json = json_encode($input, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, strlen($json)), $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound (domain C ABI)
            $written = $ffi->nntmux_additional_id_order_expression_json($json, strlen($json), $scratch, $cap);
            if ($written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound (domain C ABI)
                $written = $ffi->nntmux_additional_id_order_expression_json($json, strlen($json), $scratch, $cap);
            }
            if ($written < 0 || $written > $cap) {
                return null;
            }

            return json_decode(FFI::string($scratch, $written), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
    }

    public const bool W416_RUST_FFI_DEFAULT = false;

    private const string W416_FFI_CDEF = 'int32_t nntmux_w416_additional_candidate_diag_orch_console_nzb(uint8_t operation, const char *input, size_t input_len, char *out_buf, size_t out_cap);';

    private static ?bool $w416UseRustFfi = null;

    private static FFI|false|null $w416Ffi = null;

    public static function useW416RustFfi(bool $enable = true): void
    {
        self::$w416UseRustFfi = $enable;
    }

    public static function usesW416RustFfi(): bool
    {
        return self::$w416UseRustFfi ?? self::W416_RUST_FFI_DEFAULT;
    }

    public static function resetW416RustFfi(): void
    {
        self::$w416UseRustFfi = null;
        self::$w416Ffi = null;
    }

    /** @return list<int>|null */
    private static function w416Slots(int $operation, string $input, int $count): ?array
    {
        if (\strlen($input) > 16_777_216) {
            return null;
        }
        try {
            self::$w416Ffi ??= RustPipelineLibrary::resolveDomain('additional', self::W416_FFI_CDEF) ?? false;
            if (self::$w416Ffi === false) {
                return null;
            }
            $bytes = $count * 8;
            $buffer = self::$w416Ffi->new('char['.$bytes.']');
            // @phpstan-ignore method.notFound
            $written = self::$w416Ffi->nntmux_w416_additional_candidate_diag_orch_console_nzb($operation, $input, \strlen($input), $buffer, $bytes);
            if ($written !== $bytes) {
                return null;
            }
            $values = unpack('q*', FFI::string($buffer, $bytes));
            if ($values === false || \count($values) !== $count) {
                return null;
            }
            foreach ($values as $value) {
                if (! \is_int($value) || $value < 0) {
                    return null;
                }
            }

            return array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<array-key, mixed>  $candidates
     * @return array{total: int, eligible: int, too_small: int, too_large: int, blocked: int, invalid: int}
     */
    public static function summarizeCandidateEligibilityBatch(array $candidates, int $minBytes = 0, int $maxBytes = 0): array
    {
        return self::usesW416RustFfi()
            ? self::summarizeCandidateEligibilityBatchNative($candidates, $minBytes, $maxBytes)
            : self::summarizeCandidateEligibilityBatchPhp($candidates, $minBytes, $maxBytes);
    }

    /**
     * @param  array<array-key, mixed>  $candidates
     * @return array{total: int, eligible: int, too_small: int, too_large: int, blocked: int, invalid: int}
     */
    public static function summarizeCandidateEligibilityBatchPhp(array $candidates, int $minBytes = 0, int $maxBytes = 0): array
    {
        $result = ['total' => \count($candidates), 'eligible' => 0, 'too_small' => 0, 'too_large' => 0, 'blocked' => 0, 'invalid' => 0];
        foreach ($candidates as $row) {
            if (! \is_array($row) || ! \is_int($row['size'] ?? null) || ! \is_int($row['passwordstatus'] ?? null) || ! \is_int($row['haspreview'] ?? null) || ! \is_int($row['nzbstatus'] ?? null) || $row['size'] < 0) {
                $result['invalid']++;

                continue;
            }
            if ($row['passwordstatus'] !== -1 || $row['haspreview'] !== -1 || $row['nzbstatus'] !== 1) {
                $result['blocked']++;
            } elseif ($row['size'] < max(0, $minBytes)) {
                $result['too_small']++;
            } elseif ($maxBytes > 0 && $row['size'] > $maxBytes) {
                $result['too_large']++;
            } else {
                $result['eligible']++;
            }
        }

        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $candidates
     * @return array{total: int, eligible: int, too_small: int, too_large: int, blocked: int, invalid: int}
     */
    public static function summarizeCandidateEligibilityBatchNative(array $candidates, int $minBytes = 0, int $maxBytes = 0): array
    {
        $total = \count($candidates);
        if ($total > 100_000) {
            return self::summarizeCandidateEligibilityBatchPhp($candidates, $minBytes, $maxBytes);
        }
        $frame = pack('qqV', $minBytes, $maxBytes, $total);
        foreach ($candidates as $row) {
            if (! \is_array($row) || ! \is_int($row['size'] ?? null) || ! \is_int($row['passwordstatus'] ?? null) || ! \is_int($row['haspreview'] ?? null) || ! \is_int($row['nzbstatus'] ?? null) || $row['size'] < 0) {
                return self::summarizeCandidateEligibilityBatchPhp($candidates, $minBytes, $maxBytes);
            }
            $frame .= pack('qqqq', $row['size'], $row['passwordstatus'], $row['haspreview'], $row['nzbstatus']);
        }
        $slots = self::w416Slots(1, $frame, 6);
        if ($slots === null || $slots[0] !== $total || $slots[5] !== 0 || $slots[1] > $total || $slots[2] > $total || $slots[3] > $total || $slots[4] > $total || $total !== $slots[1] + $slots[2] + $slots[3] + $slots[4]) {
            return self::summarizeCandidateEligibilityBatchPhp($candidates, $minBytes, $maxBytes);
        }

        return [
            'total' => $slots[0],
            'eligible' => $slots[1],
            'too_small' => $slots[2],
            'too_large' => $slots[3],
            'blocked' => $slots[4],
            'invalid' => $slots[5],
        ];
    }

    // Rust is the default only while the paired W51.6 benchmark clears 1.5x on all four metrics.
    private const bool W51_RUST_FFI_DEFAULT = true;

    public const string W51_FFI_CDEF = <<<'C'
        int32_t nntmux_w516_candidate_claim_batch(const char *guids, size_t guids_len, const char *guid_twin, size_t guid_twin_len, const char *ints, size_t ints_len, const char *params, size_t params_len, size_t count, int64_t *out, size_t out_count);
        C;

    public const int W51_CLAIM_HEADER = 46;

    /** Integer columns of {@see self::planClaimBatch()}, in their packed order. */
    private const array W51_CLAIM_COLUMNS = ['id', 'postdate', 'size', 'passwordstatus', 'haspreview', 'nzbstatus', 'claimed_at', 'retry_at'];

    private const array W51_HEX_BUCKETS = [
        '0' => 0, '1' => 1, '2' => 2, '3' => 3, '4' => 4, '5' => 5, '6' => 6, '7' => 7,
        '8' => 8, '9' => 9, 'a' => 10, 'b' => 11, 'c' => 12, 'd' => 13, 'e' => 14, 'f' => 15,
    ];

    private const int W51_MAX_ITEMS = 1_048_576;

    private static ?bool $w51RustFfi = null;

    private static FFI|false|null $w51Ffi = null;

    private static ?CData $w51Out = null;

    private static int $w51Capacity = 0;

    /**
     * Apply the candidate predicates and the claim order to in-memory release rows.
     *
     * `$columns` holds a `guid` list and integer lists `id`, `postdate`, `size`,
     * `passwordstatus`, `haspreview`, `nzbstatus`, `claimed_at` and `retry_at` (Unix seconds,
     * 0 for NULL); a missing value is 0 and the GUID list sets the row count. Size bounds of 0
     * are disabled and the limit is at least 1, as in {@see self::claimBatch()}.
     *
     * Reasons (first failing predicate wins): 0 eligible, 1 password status, 2 preview, 3 NZB
     * status, 4 archive retry pending, 5 too small, 6 too large, 7 actively claimed. Layout:
     * `[0]` count, `[1..8]` per reason, `[9..25]` per GUID bucket (0-f, then other) eligible or
     * claimed rows, `[26..42]` eligible rows per bucket, `[43]` selected rows, `[44]` GUIDs that
     * are not 40 hex digits, `[45]` FNV-1a digest; then per row `reason | bucket << 3 |
     * selected << 8 | well_formed << 9 | order << 10`, where `order` is the 1-based claim
     * position of an eligible row (postdate descending, id, input order) and 0 otherwise.
     *
     * @param  array<string, list<int>|list<string>>  $columns
     * @return list<int>
     */
    public static function planClaimBatch(array $columns, int $minSizeBytes, int $maxSizeBytes, int $now, int $staleBefore, bool $passwordChecks, int $limit): array
    {
        return (self::usesW51RustFfi() ? self::planClaimBatchNative($columns, $minSizeBytes, $maxSizeBytes, $now, $staleBefore, $passwordChecks, $limit) : null)
            ?? self::planClaimBatchPhp($columns, $minSizeBytes, $maxSizeBytes, $now, $staleBefore, $passwordChecks, $limit);
    }

    /**
     * @param  array<string, list<int>|list<string>>  $columns
     * @return list<int>
     */
    public static function planClaimBatchPhp(array $columns, int $minSizeBytes, int $maxSizeBytes, int $now, int $staleBefore, bool $passwordChecks, int $limit): array
    {
        /** @var list<string> $guids */
        $guids = array_values($columns['guid'] ?? []);
        $count = \count($guids);
        $ints = [];
        foreach (self::W51_CLAIM_COLUMNS as $name) {
            /** @var list<int> $values */
            $values = array_values($columns[$name] ?? []);
            $ints[] = $values;
        }
        [$ids, $postdates, $sizes, $passwords, $previews, $nzbs, $claims, $retries] = $ints;
        $out = array_fill(0, self::W51_CLAIM_HEADER, 0);
        $out[0] = $count;
        $rows = $eligiblePostdates = $eligibleIds = $eligible = [];
        foreach ($guids as $index => $guid) {
            $password = $passwords[$index] ?? 0;
            $size = $sizes[$index] ?? 0;
            $retry = $retries[$index] ?? 0;
            $claim = $claims[$index] ?? 0;
            $reason = match (true) {
                ! ($password === -1 || (! $passwordChecks && $password === 0)) => 1,
                ($previews[$index] ?? 0) !== -1 => 2,
                ($nzbs[$index] ?? 0) !== 1 => 3,
                $retry !== 0 && $retry > $now => 4,
                $minSizeBytes > 0 && $size <= $minSizeBytes => 5,
                $maxSizeBytes > 0 && $size >= $maxSizeBytes => 6,
                $claim !== 0 && $claim >= $staleBefore => 7,
                default => 0,
            };
            $bucket = self::W51_HEX_BUCKETS[strtolower(substr($guid, 0, 1))] ?? 16;
            $wellFormed = \strlen($guid) === 40 && ctype_xdigit($guid);
            $out[1 + $reason]++;
            $out[44] += (int) ! $wellFormed;
            if ($reason === 0 || $reason === 7) {
                $out[9 + $bucket]++;
            }
            if ($reason === 0) {
                $out[26 + $bucket]++;
                $eligiblePostdates[] = $postdates[$index] ?? 0;
                $eligibleIds[] = $ids[$index] ?? 0;
                $eligible[] = $index;
            }
            $rows[] = $reason | $bucket << 3 | (int) $wellFormed << 9;
        }
        if ($eligible !== []) {
            array_multisort($eligiblePostdates, SORT_DESC, SORT_NUMERIC, $eligibleIds, SORT_ASC, SORT_NUMERIC, $eligible, SORT_ASC, SORT_NUMERIC);
        }
        $selected = min(\count($eligible), max(1, $limit));
        $out[43] = $selected;
        foreach ($eligible as $position => $index) {
            $rows[$index] |= ($position + 1) << 10 | ($position < $selected ? 1 << 8 : 0);
        }
        $digest = 2_166_136_261;
        foreach ($rows as $row) {
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
        }
        $out[45] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust planning, or null when PHP must compute it (unavailable library, missing values or
     * rejected input).
     *
     * @param  array<string, list<int>|list<string>>  $columns
     * @return list<int>|null
     */
    public static function planClaimBatchNative(array $columns, int $minSizeBytes, int $maxSizeBytes, int $now, int $staleBefore, bool $passwordChecks, int $limit): ?array
    {
        $guids = $columns['guid'] ?? [];
        $count = \count($guids);
        $fields = self::W51_CLAIM_HEADER + $count;
        $binding = $count <= self::W51_MAX_ITEMS ? self::w51Binding($fields) : null;
        if ($binding === null) {
            return null;
        }
        [$ffi, $out] = $binding;
        foreach (self::W51_CLAIM_COLUMNS as $name) {
            if (\count($columns[$name] ?? []) !== $count) {
                return null;
            }
        }
        /** @var array<array-key, string> $guids */
        [$joined, $twin] = self::w51Strings($guids);
        try {
            $packed = pack('q*', ...($columns['id'] ?? []), ...($columns['postdate'] ?? []), ...($columns['size'] ?? []), ...($columns['passwordstatus'] ?? []),
                ...($columns['haspreview'] ?? []), ...($columns['nzbstatus'] ?? []), ...($columns['claimed_at'] ?? []), ...($columns['retry_at'] ?? []));
            $params = pack('q6', $minSizeBytes, $maxSizeBytes, $now, $staleBefore, $passwordChecks ? 1 : 0, $limit);
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_w516_candidate_claim_batch($joined, \strlen($joined), $twin, \strlen($twin), $packed, \strlen($packed), $params, \strlen($params), $count, $out, $fields);

            return self::w51Decode(\is_int($status) ? $status : -1, $out, $fields);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Run `$callback` with the Wave 51.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW51RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$w51RustFfi;
        self::$w51RustFfi = $enabled;
        try {
            return $callback();
        } finally {
            self::$w51RustFfi = $previous;
        }
    }

    public static function usesW51RustFfi(): bool
    {
        return self::$w51RustFfi ?? self::W51_RUST_FFI_DEFAULT;
    }

    /**
     * The cached Wave 51.6 binding and an output buffer of at least `$fields` values, or null.
     *
     * @return array{0: FFI, 1: CData}|null
     */
    private static function w51Binding(int $fields): ?array
    {
        try {
            self::$w51Ffi ??= RustPipelineLibrary::resolveDomain('additional_w51', self::W51_FFI_CDEF) ?? false;
            if (self::$w51Ffi === false) {
                return null;
            }
            $out = self::$w51Out;
            if ($out === null || self::$w51Capacity < $fields) {
                self::$w51Capacity = max(256, $fields);
                $out = self::$w51Out = self::$w51Ffi->new('int64_t['.self::$w51Capacity.']');
            }
        } catch (Throwable) {
            return null;
        }

        return [self::$w51Ffi, $out];
    }

    /**
     * Decode `$fields` output values after a successful Wave 51.6 call.
     *
     * @return list<int>|null
     */
    private static function w51Decode(int $status, CData $out, int $fields): ?array
    {
        if ($status !== 1) {
            return null;
        }
        $values = unpack('P*', FFI::string($out, $fields * 8));

        return $values === false ? null : array_values($values);
    }

    /**
     * NUL-joined values and, when one holds a NUL, the same list joined with 0x01 so Rust
     * finds the separators where the two differ.
     *
     * @param  array<array-key, string>  $items
     * @return array{0: string, 1: string}
     */
    private static function w51Strings(array $items): array
    {
        $joined = implode("\0", $items);

        return $items !== [] && substr_count($joined, "\0") !== \count($items) - 1 ? [$joined, implode("\x01", $items)] : [$joined, ''];
    }

    // ========================================================================
    // WAVE 64.1 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 5.35x, p99 4.76x; uncommon mean 4.69x, p99 2.80x); see RustAdditionalQueryDiagnosticsOrchestratorPlannerExtractionW64ParityTest. */
    public const bool W64_RUST_FFI_DEFAULT = true;

    public const string W64_CANDIDATE_QUERY_FFI_CDEF = <<<'C'
        ssize_t nntmux_w641_candidate_query_plan_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of w64CandidateQueryPlanBatch(). */
    public const int W64_CANDIDATE_QUERY_TRAILER = 44;

    public static function usesW64RustFfi(): bool
    {
        $override = self::w64RustFfiState();

        return \is_bool($override) ? $override : self::W64_RUST_FFI_DEFAULT;
    }

    /** Forces the AdditionalCandidateQuery Wave 64 Rust path on until reset. */
    public static function enableW64RustFfi(): void
    {
        self::w64RustFfiState('set', true);
    }

    /** Forces the AdditionalCandidateQuery Wave 64 PHP path until reset. */
    public static function disableW64RustFfi(): void
    {
        self::w64RustFfiState('set', false);
    }

    /** Restores the benchmark default and drops the cached library handle and scratch buffers; earlier toggles are untouched. */
    public static function resetW64RustFfi(): void
    {
        self::w64RustFfiState('reset');
    }

    /**
     * Runs $callback with the AdditionalCandidateQuery Wave 64 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW64RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w64RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w64RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 64.1 candidate query plans, one per release row `size|passwordstatus|haspreview|nzbstatus|disablepreview|retry|claim_age|guid|attempts`
     * (trimmed fields). Size, disablepreview, claim age and attempts are 1-15 digits; the three statuses and the seconds
     * until the archive retry is due are optionally signed. The reason mirrors applyPredicates() with the default size
     * bounds, a 300-second claim TTL and three attempts: 0 eligible, 1 passwordstatus other than -1 or 0, 2 haspreview
     * not -1, 3 nzbstatus not 1, 4 category previews disabled, 5 archive retry not due, 6 at or below the minimum size,
     * 7 at or above the maximum size, 8 actively claimed, 9 attempts exhausted, 10 malformed (an empty size or status,
     * or a bad number), 11 empty row. Bucket: the lowercased first GUID byte `0-9a-f` as 0-15, else 16. Priority of an
     * eligible row: 3 under 100 MiB, 2 under 4 GiB, else 1. Flags: 1 40-hex GUID, 2 unchecked password (0), 4 stale
     * claim, 8 retry due, 16 non-ASCII, 32 uppercase GUID, 64 GUID repeated in the batch, 128 control byte. Per item
     * `reason | bucket << 4 | flags << 9 | priority << 17 | min(attempts, 15) << 19 | bitLength(size) << 23 |
     * (fnv1a32(lowercase GUID) & 0xFFFF) << 29 | min(eligibleRank, 1023) << 45`; then 12 reason counts, 17 eligible
     * bucket counts, 8 flag counts, 3 priority counts, the eligible size sum and maximum, the distinct GUIDs and the
     * attempt sum.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w64CandidateQueryPlanBatch(array $items): array
    {
        return self::usesW64RustFfi() ? self::w64CandidateQueryPlanBatchNative($items) : self::w64CandidateQueryPlanBatchPhp($items);
    }

    /**
     * Rust plan; falls back to the PHP reference when the library is missing or the call declines.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w64CandidateQueryPlanBatchNative(array $items): array
    {
        return self::w64Invoke($items, \count($items) + self::W64_CANDIDATE_QUERY_TRAILER) ?? self::w64CandidateQueryPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w64CandidateQueryPlanBatchPhp(array $items): array
    {
        $out = [];
        $tail = array_fill(0, self::W64_CANDIDATE_QUERY_TRAILER, 0);
        $guids = [];
        $eligibleRank = 0;
        foreach ($items as $item) {
            $item = (string) $item;
            [$rawSize, $rawPassword, $rawPreview, $rawNzb, $rawCategory, $rawRetry, $rawClaim, $rawGuid, $rawAttempts] = explode('|', $item, 9)
                + ['', '', '', '', '', '', '', '', ''];
            $size = self::w64Uint($rawSize, -1);
            $password = self::w64Sint(trim($rawPassword));
            $preview = self::w64Sint(trim($rawPreview));
            $nzb = self::w64Sint(trim($rawNzb));
            $category = self::w64Uint($rawCategory, 0);
            $retryRaw = trim($rawRetry);
            $retry = $retryRaw === '' ? null : self::w64Sint($retryRaw);
            $claim = self::w64Uint($rawClaim, -2);
            $attempts = self::w64Uint($rawAttempts, 0);
            $guid = trim($rawGuid);
            $guidLength = \strlen($guid);
            $lower = strtolower($guid);
            $bucket = $lower === '' ? 16 : (self::W64_HEX_BUCKETS[$lower[0]] ?? 16);
            $reason = match (true) {
                trim($item) === '' => 11,
                $size < 0 || $password === null || $preview === null || $nzb === null || $category < 0 || ($retryRaw !== '' && $retry === null)
                    || $claim === -1 || $attempts < 0 => 10,
                $password !== -1 && $password !== 0 => 1,
                $preview !== -1 => 2,
                $nzb !== 1 => 3,
                $category !== 0 => 4,
                $retry !== null && $retry > 0 => 5,
                $size <= self::DEFAULT_MIN_SIZE_BYTES => 6,
                $size >= self::DEFAULT_MAX_SIZE_BYTES => 7,
                $attempts >= 3 => 9,
                $claim >= 0 && $claim < 300 => 8,
                default => 0,
            };
            $flags = ($guidLength === 40 && strspn($guid, '0123456789abcdefABCDEF') === 40 ? 1 : 0) | ($password === 0 ? 2 : 0)
                | ($claim >= 300 ? 4 : 0) | ($retry !== null && $retry <= 0 ? 8 : 0) | (preg_match('/[\x80-\xff]/', $item) === 1 ? 16 : 0)
                | (strcspn($guid, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ') !== $guidLength ? 32 : 0)
                | (strcspn($item, self::W64_CONTROL_BYTES) !== \strlen($item) ? 128 : 0);
            if ($lower !== '') {
                if (isset($guids[$lower])) {
                    $flags |= 64;
                } else {
                    $guids[$lower] = true;
                }
            }
            $eligible = $reason === 0;
            $priority = match (true) {
                ! $eligible => 0,
                $size < 104857600 => 3,
                $size < 4294967296 => 2,
                default => 1,
            };
            $rank = $eligible ? ++$eligibleRank : 0;
            $tail[$reason]++;
            if ($eligible) {
                $tail[12 + $bucket]++;
                $tail[40] += min($size, 1 << 40);
                $tail[41] = max($tail[41], $size);
            }
            for ($b = 0; $b < 8; $b++) {
                $tail[29 + $b] += $flags >> $b & 1;
            }
            if ($priority > 0) {
                $tail[36 + $priority]++;
            }
            $tail[43] += max(0, $attempts);
            $out[] = $reason | $bucket << 4 | $flags << 9 | $priority << 17 | max(0, min($attempts, 15)) << 19 | self::w64BitLength($size) << 23
                | ((int) hexdec(hash('fnv1a32', $lower)) & 0xFFFF) << 29 | min($rank, 1023) << 45;
        }
        $tail[42] = \count($guids);
        array_push($out, ...$tail);

        return $out;
    }

    /** @var array<int|string, int> */
    private const array W64_HEX_BUCKETS = ['0' => 0, '1' => 1, '2' => 2, '3' => 3, '4' => 4, '5' => 5, '6' => 6, '7' => 7, '8' => 8, '9' => 9,
        'a' => 10, 'b' => 11, 'c' => 12, 'd' => 13, 'e' => 14, 'f' => 15];

    private const string W64_CONTROL_BYTES = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F";

    private const string W64_DIGITS = '0123456789';

    /** A trimmed field of 1-15 digits; empty is $empty, anything else -1. */
    private static function w64Uint(string $raw, int $empty): int
    {
        $value = trim($raw);
        if ($value === '') {
            return $empty;
        }
        $length = \strlen($value);

        return $length <= 15 && strspn($value, self::W64_DIGITS) === $length ? (int) $value : -1;
    }

    /** An optional `-` and 1-15 digits of an already trimmed field, else null. */
    private static function w64Sint(string $value): ?int
    {
        $negative = str_starts_with($value, '-');
        $digits = $negative ? substr($value, 1) : $value;
        $length = \strlen($digits);
        if ($length === 0 || $length > 15 || strspn($digits, self::W64_DIGITS) !== $length) {
            return null;
        }

        return $negative ? -(int) $digits : (int) $digits;
    }

    private static function w64BitLength(int $value): int
    {
        $bits = 0;
        for (; $value > 0; $value >>= 1) {
            $bits++;
        }

        return $bits;
    }

    /**
     * Wave 64 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'reset' clears the override, library handle and buffers, 'ffi'
     * resolves the library (null when unavailable) and 'out' and 'lens' return `int64_t` output and
     * length-column buffers of at least $val words.
     */
    private static function w64RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'reset') {
            $override = $ffi = $out = $lens = null;
            $outCap = $lensCap = 0;

            return null;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_candidate_query_w641', self::W64_CANDIDATE_QUERY_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w64RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w64RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 64.1 AdditionalCandidateQuery export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w64Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w64RustFfiState('ffi');
            $out = self::w64RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w64RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W64_CANDIDATE_QUERY_FFI_CDEF.)
            $written = $ffi->nntmux_w641_candidate_query_plan_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
