<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

#[Signature('releases:optimize-preflight {--json : Output machine-readable JSON}')]
#[Description('Read-only safety and data-volume report for the releases normalization migration')]
class ReleasesOptimizePreflight extends Command
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_maintenance_verification(const char *input, size_t len, uint32_t mode, char *out, size_t cap);
        C;

    // Single-call candidates remain opt-in until both performance gates pass.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi && RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF) !== null;
    }

    private static function transformNative(int $mode, string $input): string|false|null
    {
        if (! self::$useRustFfi || strlen($input) > 1_048_576) {
            return null;
        }
        $ffi = RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF);
        if ($ffi === null) {
            return null;
        }
        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, strlen($input) * 4 + 256), $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_maintenance_verification($input, strlen($input), $mode, $scratch, $cap);
            if ($written === -3) {
                return false;
            }
            if (! is_int($written) || $written < 0 || $written > $cap) {
                return null;
            }

            return $written === 0 ? '' : FFI::string($scratch, $written);
        } catch (Throwable) {
            return null;
        }
    }

    /** @param list<string> $values */
    private static function encodeFrames(array $values): string
    {
        $input = '';
        foreach ($values as $value) {
            $input .= pack('V', strlen($value)).$value;
        }

        return $input;
    }

    /** @return list<string>|null */
    private static function decodeFrames(string $output): ?array
    {
        $values = [];
        $offset = 0;
        $size = strlen($output);
        while ($offset < $size) {
            if ($size - $offset < 4) {
                return null;
            }
            $header = unpack('Vlength', substr($output, $offset, 4));
            $length = $header['length'];
            $offset += 4;
            if ($length > $size - $offset) {
                return null;
            }
            $values[] = substr($output, $offset, $length);
            $offset += $length;
        }

        return $values;
    }

    /** @var list<string> */
    private const array REMOVED_INDEXES = [
        'ix_releases_adddate_only',
        'ix_releases_videos_id',
        'ix_releases_movieinfo_id',
        'ix_releases_imdbid',
        'ix_releases_tv_search_covering',
        'ix_releases_passwordstatus',
        'ix_releases_haspreview_passwordstatus',
        'ix_releases_postdate_searchname',
        'ix_releases_predb_id_searchname',
        'ix_releases_size_cat',
        'ix_releases_add_pp_claim_queue',
        'ix_releases_nzb_creation_queue',
    ];

    /** @var list<string> */
    private const array NEW_INDEXES = [
        'ux_releases_guid',
        'ix_releases_predb_id',
        'ix_releases_size',
        'ix_releases_add_pp_claim_queue',
        'ix_releases_nzb_creation_group_queue',
        'ix_releases_nzb_creation_global_queue',
    ];

    /** @var array<string, mixed> */
    private array $report = [];

    public function handle(): int
    {
        if (! Schema::hasTable('releases')) {
            return $this->finish([
                'ok' => false,
                'database_driver' => DB::getDriverName(),
                'blockers' => [['code' => 'missing-releases-table', 'count' => 1]],
            ]);
        }

        $identifierCounts = $this->identifierCounts();
        $blockers = [];
        foreach ($identifierCounts as $code => $count) {
            if ($count > 0) {
                $blockers[] = ['code' => $code, 'count' => $count];
            }
        }

        $indexes = array_values(array_filter(array_map(
            static fn (array $index): string => (string) $index['name'],
            Schema::getIndexes('releases'),
        )));
        sort($indexes);

        $this->report = [
            'ok' => $blockers === [],
            'database_driver' => DB::getDriverName(),
            'releases' => [
                'rows' => DB::table('releases')->count(),
                ...$identifierCounts,
            ],
            'storage' => $this->storage(),
            'migration_data' => [
                'nzb_password_rows' => $this->countNonEmpty('releases', 'nzb_password'),
                'nzb_creation_failure_rows' => $this->retryStateCount(),
                'visible_comment_rows' => $this->visibleCommentCount(),
                'release_comment_counter_mismatches' => $this->commentCounterMismatchCount(),
            ],
            'discarded_data' => $this->discardedDataCounts(),
            'indexes' => [
                'present' => $indexes,
                'scheduled_for_removal' => self::indexDiff(self::REMOVED_INDEXES, $indexes, true),
                'already_present_replacements' => self::indexDiff(self::NEW_INDEXES, $indexes, true),
            ],
            'blockers' => $blockers,
        ];

        return $this->finish($this->report);
    }

    /** @return array<string, int> */
    private function identifierCounts(): array
    {
        $duplicateIds = DB::query()->fromSub(
            DB::table('releases')->select('id')->groupBy('id')->havingRaw('COUNT(*) > 1'),
            'duplicate_release_ids',
        )->count();
        $duplicateGuids = DB::query()->fromSub(
            DB::table('releases')->selectRaw('LOWER(guid) AS normalized_guid')->groupByRaw('LOWER(guid)')->havingRaw('COUNT(*) > 1'),
            'duplicate_release_guids',
        )->count();

        if (in_array(DB::getDriverName(), ['mariadb', 'mysql'], true)) {
            $invalidGuids = DB::table('releases')
                ->whereNull('guid')
                ->orWhereRaw("guid NOT REGEXP '^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}$'")
                ->count();
        } else {
            $invalidGuids = DB::table('releases')->pluck('guid')->filter(
                static fn (mixed $guid): bool => ! is_string($guid)
                    || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $guid) !== 1,
            )->count();
        }

        return [
            'duplicate_ids' => $duplicateIds,
            'invalid_guids' => $invalidGuids,
            'case_insensitive_duplicate_guids' => $duplicateGuids,
            'leftguid_mismatches' => DB::table('releases')
                ->whereRaw('LOWER(leftguid) <> LOWER(SUBSTR(guid, 1, 1))')
                ->count(),
        ];
    }

    /** @return array{data_bytes: int|null, index_bytes: int|null, total_bytes: int|null, required_free_bytes: int|null} */
    private function storage(): array
    {
        if (! in_array(DB::getDriverName(), ['mariadb', 'mysql'], true)) {
            return ['data_bytes' => null, 'index_bytes' => null, 'total_bytes' => null, 'required_free_bytes' => null];
        }

        $tableName = DB::getTablePrefix().'releases';
        $row = DB::selectOne(
            'SELECT DATA_LENGTH AS data_bytes, INDEX_LENGTH AS index_bytes
             FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$tableName],
        );
        $dataBytes = isset($row->data_bytes) ? (int) $row->data_bytes : 0;
        $indexBytes = isset($row->index_bytes) ? (int) $row->index_bytes : 0;
        $totalBytes = $dataBytes + $indexBytes;

        return [
            'data_bytes' => $dataBytes,
            'index_bytes' => $indexBytes,
            'total_bytes' => $totalBytes,
            'required_free_bytes' => $totalBytes * 2,
        ];
    }

    /** @return array<string, int|null> */
    private function discardedDataCounts(): array
    {
        $counts = [];
        foreach ([
            'updatetime' => ['not_null', null],
            'gid' => ['non_empty', null],
            'source' => ['not_null', null],
            'proc_sorter' => ['non_zero', null],
            'audiostatus' => ['non_zero', null],
        ] as $column => [$condition]) {
            $counts['releases.'.$column] = $this->countByCondition('releases', $column, $condition);
        }

        foreach (['gid', 'cid', 'shareid', 'siteid'] as $column) {
            $counts['release_comments.'.$column] = $this->countByCondition('release_comments', $column, 'non_empty');
        }
        foreach (['issynced', 'shared', 'sourceid'] as $column) {
            $counts['release_comments.'.$column] = $this->countByCondition(
                'release_comments',
                $column,
                $column === 'sourceid' ? 'not_null' : 'non_zero',
            );
        }

        return $counts;
    }

    private function retryStateCount(): int
    {
        if (! Schema::hasColumn('releases', 'nzb_creation_attempts')) {
            return Schema::hasTable('release_nzb_creation_failures')
                ? DB::table('release_nzb_creation_failures')->count()
                : 0;
        }

        return DB::table('releases')->where(function ($query): void {
            $query->where('nzb_creation_attempts', '>', 0)->orWhereNotNull('nzb_creation_last_error');
        })->count();
    }

    private function visibleCommentCount(): int
    {
        return Schema::hasTable('release_comments')
            ? DB::table('release_comments')->where('isvisible', 1)->count()
            : 0;
    }

    private function commentCounterMismatchCount(): int
    {
        if (! Schema::hasTable('release_comments') || ! Schema::hasColumn('releases', 'comments')) {
            return 0;
        }

        // Raw SQL with explicit prefixed names: Laravel prefixes query aliases
        // too, so `releases as r` would not match a raw `r.comments` reference.
        $releases = $this->prefixedTable('releases');
        $comments = $this->prefixedTable('release_comments');

        return count(DB::select(
            "SELECT r.`id` FROM {$releases} r LEFT JOIN {$comments} c
               ON c.`releases_id` = r.`id` AND c.`isvisible` = 1
             GROUP BY r.`id`, r.`comments`
             HAVING r.`comments` <> COUNT(c.`id`)"
        ));
    }

    private function prefixedTable(string $name): string
    {
        return '`'.DB::getTablePrefix().$name.'`';
    }

    private function countNonEmpty(string $table, string $column): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return $table === 'releases' && Schema::hasTable('release_nzb_passwords')
                ? DB::table('release_nzb_passwords')->count()
                : 0;
        }

        return DB::table($table)->whereNotNull($column)->where($column, '<>', '')->count();
    }

    private function countByCondition(string $table, string $column, string $condition): ?int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return null;
        }

        $query = DB::table($table);

        return match ($condition) {
            'non_empty' => $query->whereNotNull($column)->where($column, '<>', '')->count(),
            'non_zero' => $query->where($column, '<>', 0)->count(),
            default => $query->whereNotNull($column)->count(),
        };
    }

    /** @param array<string, mixed> $report */
    private function finish(array $report): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->renderHumanReport($report);
        }

        return ($report['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<string, mixed> $report */
    private function renderHumanReport(array $report): void
    {
        if (! isset($report['releases'])) {
            $this->error('The releases table does not exist.');

            return;
        }

        $this->table(['Safety check', 'Count'], [
            ['Duplicate IDs', $report['releases']['duplicate_ids']],
            ['Invalid GUIDs', $report['releases']['invalid_guids']],
            ['Case-insensitive duplicate GUIDs', $report['releases']['case_insensitive_duplicate_guids']],
            ['Mismatched leftguid values', $report['releases']['leftguid_mismatches']],
        ]);
        $this->table(['Migration data', 'Rows'], array_map(
            static fn (string $key, int $value): array => [$key, $value],
            array_keys($report['migration_data']),
            array_values($report['migration_data']),
        ));

        $this->renderStorageSection($report);
        $this->renderDiscardedDataSection($report);
        $this->renderIndexSection($report);

        if ($report['ok']) {
            $this->info('Releases optimization preflight passed.');
        } else {
            $this->error('Releases optimization preflight failed. Resolve every blocker before migration.');
        }
    }

    /**
     * Print the on-disk footprint and the free space the rebuild needs.
     *
     * @param  array<string, mixed>  $report
     */
    private function renderStorageSection(array $report): void
    {
        $storage = $report['storage'] ?? [];
        if (! is_array($storage) || ($storage['total_bytes'] ?? null) === null) {
            $this->line('Storage: not reported for the '.$report['database_driver'].' driver.');

            return;
        }

        $this->table(['Storage', 'Size'], [
            ['Data', self::formatBytes((int) $storage['data_bytes'])],
            ['Indexes', self::formatBytes((int) $storage['index_bytes'])],
            ['Total', self::formatBytes((int) $storage['total_bytes'])],
            ['Free space required', self::formatBytes((int) $storage['required_free_bytes'])],
        ]);
        $this->warn(
            'The rebuild copies the table, so at least '.self::formatBytes((int) $storage['required_free_bytes'])
            .' must be free on the MySQL data volume before you migrate.'
        );
    }

    /**
     * Print how many values each dropped column is about to lose.
     *
     * @param  array<string, mixed>  $report
     */
    private function renderDiscardedDataSection(array $report): void
    {
        $discarded = $report['discarded_data'] ?? [];
        if (! is_array($discarded) || $discarded === []) {
            return;
        }

        $rows = [];
        foreach ($discarded as $column => $count) {
            $rows[] = [$column, $count === null ? 'already dropped' : (string) $count];
        }

        $this->table(['Dropped column', 'Values destroyed'], $rows);

        $destroyed = array_sum(array_map(static fn (?int $count): int => $count ?? 0, $discarded));
        if ($destroyed > 0) {
            $this->warn('These '.$destroyed.' values are permanently destroyed by the migration. Back up first.');
        }
    }

    /**
     * Print the index changes the migration will make.
     *
     * @param  array<string, mixed>  $report
     */
    private function renderIndexSection(array $report): void
    {
        $indexes = $report['indexes'] ?? [];
        if (! is_array($indexes)) {
            return;
        }

        $removal = $indexes['scheduled_for_removal'] ?? [];
        $existing = $indexes['already_present_replacements'] ?? [];
        $missing = self::indexDiff(self::NEW_INDEXES, $existing);

        $this->table(['Index change', 'Names'], [
            ['Dropped', $removal === [] ? 'none' : implode(', ', $removal)],
            ['Created', $missing === [] ? 'none' : implode(', ', $missing)],
            ['Already present', $existing === [] ? 'none' : implode(', ', $existing)],
        ]);
    }

    public static function formatBytes(int $bytes): string
    {
        return (self::$useRustFfi ? self::formatBytesNative($bytes) : null) ?? self::formatBytesPhp($bytes);
    }

    public static function formatBytesNative(int $bytes): ?string
    {
        if (localeconv()['decimal_point'] !== '.') {
            return null;
        }
        $output = self::transformNative(2, (string) $bytes);

        return is_string($output) ? $output : null;
    }

    public static function formatBytesPhp(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $value = (float) $bytes;
        $unit = 0;
        while ($value >= 1024.0 && $unit < count($units) - 1) {
            $value /= 1024.0;
            $unit++;
        }

        return $unit === 0
            ? $bytes.' B'
            : sprintf('%.2f %s', $value, $units[$unit]);
    }

    /** @param list<string> $left
     * @param  list<string>  $right
     * @return list<string>
     */
    public static function indexDiff(array $left, array $right, bool $intersect = false): array
    {
        return (self::$useRustFfi ? self::indexDiffNative($left, $right, $intersect) : null) ?? self::indexDiffPhp($left, $right, $intersect);
    }

    /** @param list<string> $left
     * @param  list<string>  $right
     * @return list<string>
     */
    public static function indexDiffPhp(array $left, array $right, bool $intersect = false): array
    {
        return array_values($intersect ? array_intersect($left, $right) : array_diff($left, $right));
    }

    /** @param list<string> $left
     * @param  list<string>  $right
     * @return list<string>|null
     */
    public static function indexDiffNative(array $left, array $right, bool $intersect = false): ?array
    {
        $output = self::transformNative(3, self::encodeFrames([$intersect ? 'intersect' : 'diff', (string) count($left), ...array_values($left), ...array_values($right)]));

        return is_string($output) ? self::decodeFrames($output) : null;
    }
}
