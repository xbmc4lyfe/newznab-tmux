<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Releases\Verification\ManifestStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportVerificationManifests extends Command
{
    private const array SUMMARY_KEYS = ['imported', 'already', 'missing', 'invalid', 'conflict', 'unreferenced'];

    // Keep PHP as the production default until the Rust performance gate passes.
    private static ?bool $useRustFfi = null;

    protected $signature = 'verification:import-manifests {--batch=1000 : Candidates read per page}
        {--limit=0 : Stop after this many manifests (0 = all)} {--since-id=0 : Start after this candidate ID}
        {--delete-files : Remove each legacy file once the database holds a verified copy}';

    protected $description = 'Copy legacy verification manifest files referenced by candidates into the database';

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::useRustFfi();
    }

    /**
     * @return array{batch: int, limit: int, cursor: int}
     */
    public static function normalizeBatchOptions(int $batch, int $limit, int $sinceId): array
    {
        return self::useRustFfi() ? self::normalizeBatchOptionsNative($batch, $limit, $sinceId) : self::normalizeBatchOptionsPhp($batch, $limit, $sinceId);
    }

    /**
     * @return array{batch: int, limit: int, cursor: int}
     */
    public static function normalizeBatchOptionsPhp(int $batch, int $limit, int $sinceId): array
    {
        return ['batch' => max(1, $batch), 'limit' => max(0, $limit), 'cursor' => max(0, $sinceId)];
    }

    /**
     * @return array{batch: int, limit: int, cursor: int}
     */
    public static function normalizeBatchOptionsNative(int $batch, int $limit, int $sinceId): array
    {
        $result = ImportNfos::transformNative(10, [$batch, $limit, $sinceId]);
        if (is_array($result) && array_keys($result) === ['batch', 'limit', 'cursor']
            && is_int($result['batch']) && is_int($result['limit']) && is_int($result['cursor'])) {
            return $result;
        }

        return self::normalizeBatchOptionsPhp($batch, $limit, $sinceId);
    }

    /**
     * Rows to request for the next page; `$limit = 0` means unbounded.
     */
    public static function computeChunkLimit(int $batch, int $limit, int $seen): int
    {
        return self::useRustFfi() ? self::computeChunkLimitNative($batch, $limit, $seen) : self::computeChunkLimitPhp($batch, $limit, $seen);
    }

    public static function computeChunkLimitPhp(int $batch, int $limit, int $seen): int
    {
        if ($limit <= 0) {
            return $batch;
        }
        // An overflowing subtraction becomes a float that always exceeds $batch, so min() keeps $batch.
        $remaining = $seen < 0 && $limit > PHP_INT_MAX + $seen ? $batch : max(0, $limit - $seen);

        return min($batch, $remaining);
    }

    public static function computeChunkLimitNative(int $batch, int $limit, int $seen): int
    {
        $result = ImportNfos::transformNative(11, [$batch, $limit, $seen]);

        return is_int($result) ? $result : self::computeChunkLimitPhp($batch, $limit, $seen);
    }

    /**
     * @param  array<string, int>  $totals
     */
    public static function formatSummaryLine(array $totals, int $cursor): string
    {
        return self::useRustFfi() ? self::formatSummaryLineNative($totals, $cursor) : self::formatSummaryLinePhp($totals, $cursor);
    }

    /**
     * @param  array<string, int>  $totals
     */
    public static function formatSummaryLinePhp(array $totals, int $cursor): string
    {
        return sprintf('imported=%d already=%d missing=%d invalid=%d conflict=%d unreferenced=%d last_candidate_id=%d',
            ...[...self::summaryCounts($totals), $cursor]);
    }

    /**
     * @param  array<string, int>  $totals
     */
    public static function formatSummaryLineNative(array $totals, int $cursor): string
    {
        $result = ImportNfos::transformNative(12, [...self::summaryCounts($totals), $cursor]);

        return is_string($result) ? $result : self::formatSummaryLinePhp($totals, $cursor);
    }

    public function handle(ManifestStore $store): int
    {
        if (config('release_verification.manifest_storage') !== 'database') {
            $this->error('RELEASE_VERIFICATION_MANIFEST_STORAGE is not "database"; nothing to import into.');

            return self::FAILURE;
        }
        ['batch' => $batch, 'limit' => $limit, 'cursor' => $cursor] = self::normalizeBatchOptions(
            (int) $this->option('batch'), (int) $this->option('limit'), (int) $this->option('since-id'));
        $deleteFiles = (bool) $this->option('delete-files');
        $counts = array_fill_keys(self::SUMMARY_KEYS, 0);
        $seen = 0;

        do {
            $rows = DB::table('release_verification_candidates')->where('id', '>', $cursor)->where('manifest_path', '<>', '')
                ->orderBy('id')->limit($batch)->get(['id', 'manifest_path']);
            $chunk = self::computeChunkLimit($batch, $limit, $seen);
            if ($rows->count() > $chunk) {
                $rows = $rows->take($chunk);
            }
            if ($rows->isEmpty()) {
                break;
            }
            $cursor = (int) $rows->last()->id;
            $seen += $rows->count();
            foreach ($store->importLegacyFiles($rows->pluck('manifest_path')->map(static fn ($name): string => (string) $name)->all(), $deleteFiles) as $outcome => $count) {
                $counts[$outcome] += $count;
            }
        } while ($rows->count() === $batch && ($limit === 0 || $seen < $limit));

        $this->line(self::formatSummaryLine($counts, $cursor));

        return self::SUCCESS;
    }

    /**
     * `sprintf('%d')` coerces exactly like an `(int)` cast; missing keys print 0.
     *
     * @param  array<string, int>  $totals
     * @return list<int>
     */
    private static function summaryCounts(array $totals): array
    {
        return array_map(static fn (string $key): int => (int) ($totals[$key] ?? 0), self::SUMMARY_KEYS);
    }

    private static function useRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }
}
