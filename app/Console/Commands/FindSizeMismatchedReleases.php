<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Release;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Support\ReleaseNameDisplay;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class FindSizeMismatchedReleases extends Command
{
    /**
     * Production default for the static plan helpers below; it stays false because the paired
     * benchmark in tests/Unit/RustPredbMatchExceptionIdentityTransactionsParityTest.php is below
     * 1.5x. The instance `$useRustFfi` flag keeps controlling domain stripping only.
     */
    public const bool RUST_FFI_DEFAULT = false;

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w383_size_mismatch_plan(
            uint32_t flags,
            const char *threshold_ptr,
            size_t threshold_len,
            const char *limit_ptr,
            size_t limit_len,
            const char *direction_ptr,
            size_t direction_len,
            char *out_buf,
            size_t out_cap
        );
        ssize_t nntmux_w383_size_difference(
            const char *direction_ptr,
            size_t direction_len,
            int64_t release_bytes,
            int64_t files_bytes,
            int64_t threshold_bp,
            char *out_buf,
            size_t out_cap
        );
        C;

    /** Largest threshold or limit the option plan reports; larger values are clamped. */
    public const int MAX_OPTION_COUNT = 1_000_000;

    /** Largest release or file total (256 TiB) the size classification accepts. */
    public const int MAX_SIZE_BYTES = 281_474_976_710_656;

    /** Largest threshold, in basis points of the release size, the classification accepts. */
    public const int MAX_THRESHOLD_BASIS_POINTS = 100_000_000;

    private const array DIRECTIONS = ['bigger', 'smaller', 'any'];

    private const array SIGNS = ['bigger', 'smaller', 'equal', 'none'];

    private const string TRIM_CHARS = " \t\n\r\0\x0B\x0C";

    private const string FIELD_SEPARATOR = "\x1F";

    private static ?bool $sizeMismatchRustFfiOverride = null;

    private static FFI|false|null $sizeMismatchRustFfiHandle = null;

    public bool $useRustFfi = false;

    protected $signature = 'nntmux:find-size-mismatches {--threshold=20} {--limit=100} {--season-pack} {--direction=any} {--rename}';

    protected $description = 'Find releases where size differs significantly from release_files total. Use --direction=bigger|smaller|any';

    public function handle(): void
    {
        $threshold = $this->option('threshold'); // Percentage difference threshold
        $limit = $this->option('limit');
        $checkSeasonPack = $this->option('season-pack');
        $direction = $this->option('direction');
        $shouldRename = $this->option('rename');
        $nameFixingService = new NameFixingService;

        $query = Release::query()
            ->select([
                'releases.id',
                'releases.searchname',
                'releases.name',
                'releases.groups_id',
                'releases.categories_id',
                DB::raw('releases.size / POW(1024, 3) as release_size'),
                DB::raw('SUM(release_files.size) / POW(1024, 3) as files_total_size'),
                DB::raw('(releases.size - SUM(release_files.size)) / POW(1024, 3) as size_diff'),
                DB::raw('((releases.size - SUM(release_files.size)) / releases.size * 100) as diff_percent'),
            ])
            ->join('release_files', 'releases.id', '=', 'release_files.releases_id')
            ->where('releases.searchname', 'REGEXP', 'S[0-9]{1,3}E[0-9]{1,3}')
            ->groupBy('releases.id');

        // Apply direction filter
        if ($direction === 'bigger') {
            $query->having('size_diff', '>', 0)
                ->having('diff_percent', '>', $threshold);
        } elseif ($direction === 'smaller') {
            $query->having('size_diff', '<', 0)
                ->having('diff_percent', '<', -$threshold);
        } else {
            $query->having(DB::raw('ABS(diff_percent)'), '>', $threshold);
        }

        // Order by ID if renaming, otherwise by diff_percent
        $query->orderBy($shouldRename ? 'releases.id' : 'diff_percent', $shouldRename ? 'asc' : 'desc');

        if ($limit > 0) {
            $query->limit((int) $limit);
        }

        $mismatches = $query->get();

        if ($checkSeasonPack) {
            $mismatches = $mismatches->filter(function ($release) use ($nameFixingService) {
                return $nameFixingService->isSeasonPack($release->name);
            });
        }

        if ($mismatches->isEmpty()) {
            $this->info('No releases found with size mismatches above '.$threshold.'%'
                .($checkSeasonPack ? ' that are season packs' : ''));

            return;
        }

        if ($shouldRename) {
            $this->info("\nAttempting to rename ".$mismatches->count()." releases...\n");

            foreach ($mismatches as $release) {
                $this->attemptRename($release, $nameFixingService);
            }

            $this->outputReleaseIdsAsCsv($mismatches);

            return;
        }

        // Regular table output for non-rename mode
        $headers = ['Release ID', 'Searchname', 'Release Size', 'Files Total', 'Difference', 'Diff %'];
        $rows = $mismatches->map(function ($release) {
            return [
                $release->id,
                $release->searchname,
                number_format((float) ($release->release_size ?? 0), 2).' GiB',
                number_format((float) ($release->files_total_size ?? 0), 2).' GiB',
                number_format((float) ($release->size_diff ?? 0), 2).' GiB',
                number_format((float) ($release->diff_percent ?? 0), 2).'%',
            ];
        });

        $this->table($headers, $rows);
        $this->info("\nFound ".$mismatches->count().' releases with size mismatches above '.$threshold.'%');

        $this->outputReleaseIdsAsCsv($mismatches);
    }

    private function attemptRename(Release $release, NameFixingService $nameFixingService): ?string
    {
        if (preg_match(ReleaseUpdateService::PREDB_REGEX, $this->stripDomainFromString($release->name), $matches)) {
            $newName = $matches[1];
            $nameFixingService->getUpdateService()->updateRelease(
                release: $release,
                name: $newName,
                method: 'size-mismatch / season pack',
                echo: true,
                type: '',
                nameStatus: true,
                show: true,
                preId: 0
            );

            return $newName;
        }

        return null;
    }

    private function outputReleaseIdsAsCsv(mixed $mismatches): void
    {
        $releaseIds = $mismatches->pluck('id')->join(',');
        $this->line("\nRelease IDs in CSV format:");
        $this->line($releaseIds);
    }

    private function stripDomainFromString(string $str): string
    {
        return $this->useRustFfi ? $this->stripDomainFromStringNative($str) : $this->stripDomainFromStringPhp($str);
    }

    public function stripDomainFromStringNative(string $str): string
    {
        return ReleaseNameDisplay::transformNative(5, $str) ?? $this->stripDomainFromStringPhp($str);
    }

    public function stripDomainFromStringPhp(string $str): string
    {
        return preg_replace("/www\.[^\s]+\.[a-z]{2,4}/i", '', $str);
    }

    public static function useSizeMismatchRustFfi(?bool $enabled): void
    {
        self::$sizeMismatchRustFfiOverride = $enabled;
    }

    public static function usesSizeMismatchRustFfi(): bool
    {
        return self::$sizeMismatchRustFfiOverride ?? self::RUST_FFI_DEFAULT;
    }

    public static function resetSizeMismatchRustFfi(): void
    {
        self::$sizeMismatchRustFfiOverride = null;
        self::$sizeMismatchRustFfiHandle = null;
    }

    /**
     * Normalizes the command options with a strict decimal grammar: surrounding whitespace is
     * tolerated, absent options use the signature defaults (20% / 100 rows), invalid values fall
     * back to those defaults with `*_valid` false, and values above MAX_OPTION_COUNT are clamped.
     * Unknown directions behave like `any`, as handle() does.
     *
     * @return array{threshold_valid: bool, threshold: int, limit_valid: bool, limit: int, limited: bool, direction: string, direction_known: bool, order_column: string, order_direction: string}
     */
    public static function planSizeMismatchQuery(?string $threshold, ?string $limit, ?string $direction, bool $rename): array
    {
        return (self::usesSizeMismatchRustFfi() ? self::planSizeMismatchQueryNative($threshold, $limit, $direction, $rename) : null)
            ?? self::planSizeMismatchQueryPhp($threshold, $limit, $direction, $rename);
    }

    /**
     * @return array{threshold_valid: bool, threshold: int, limit_valid: bool, limit: int, limited: bool, direction: string, direction_known: bool, order_column: string, order_direction: string}|null
     */
    private static function planSizeMismatchQueryNative(?string $threshold, ?string $limit, ?string $direction, bool $rename): ?array
    {
        $flags = ($threshold === null ? 0 : 1) | ($limit === null ? 0 : 2) | ($direction === null ? 0 : 4) | ($rename ? 8 : 0);
        $fields = self::callSizeMismatchNative('nntmux_w383_size_mismatch_plan', [
            $flags, $threshold ?? '', strlen($threshold ?? ''), $limit ?? '', strlen($limit ?? ''), $direction ?? '', strlen($direction ?? ''),
        ], 96);
        if ($fields === null || count($fields) !== 9 || ! ctype_digit($fields[1]) || ! ctype_digit($fields[3])
            || ! in_array($fields[5], self::DIRECTIONS, true) || ! in_array($fields[7], ['releases.id', 'diff_percent'], true)
            || ! in_array($fields[8], ['asc', 'desc'], true)) {
            return null;
        }

        return [
            'threshold_valid' => $fields[0] === '1',
            'threshold' => (int) $fields[1],
            'limit_valid' => $fields[2] === '1',
            'limit' => (int) $fields[3],
            'limited' => $fields[4] === '1',
            'direction' => $fields[5],
            'direction_known' => $fields[6] === '1',
            'order_column' => $fields[7],
            'order_direction' => $fields[8],
        ];
    }

    /**
     * @return array{threshold_valid: bool, threshold: int, limit_valid: bool, limit: int, limited: bool, direction: string, direction_known: bool, order_column: string, order_direction: string}
     */
    private static function planSizeMismatchQueryPhp(?string $threshold, ?string $limit, ?string $direction, bool $rename): array
    {
        [$thresholdValid, $thresholdValue] = self::parseSizeMismatchCount($threshold, 20);
        [$limitValid, $limitValue] = self::parseSizeMismatchCount($limit, 100);

        return [
            'threshold_valid' => $thresholdValid,
            'threshold' => $thresholdValue,
            'limit_valid' => $limitValid,
            'limit' => $limitValue,
            'limited' => $limitValue > 0,
            'direction' => $direction === 'bigger' || $direction === 'smaller' ? $direction : 'any',
            'direction_known' => $direction === null || in_array($direction, self::DIRECTIONS, true),
            'order_column' => $rename ? 'releases.id' : 'diff_percent',
            'order_direction' => $rename ? 'asc' : 'desc',
        ];
    }

    /**
     * @return array{bool, int}
     */
    private static function parseSizeMismatchCount(?string $value, int $default): array
    {
        if ($value === null) {
            return [true, $default];
        }
        $trimmed = trim($value, self::TRIM_CHARS);
        if (! ctype_digit($trimmed)) {
            return [false, $default];
        }
        $significant = ltrim($trimmed, '0');
        if (strlen($significant) > 7) {
            return [true, self::MAX_OPTION_COUNT];
        }

        return [true, min((int) $significant, self::MAX_OPTION_COUNT)];
    }

    /**
     * Integer form of the mismatch filter: `basis_points` is the release/files difference in
     * hundredths of a percent of the release size (truncated toward zero), compared strictly with
     * the threshold for the requested direction. Sizes outside 0..MAX_SIZE_BYTES, thresholds
     * outside 0..MAX_THRESHOLD_BASIS_POINTS and unknown directions are invalid; an empty release
     * is not measurable, matching the NULL percentage SQL produces.
     *
     * @return array{valid: bool, measurable: bool, diff: int, basis_points: int, sign: string, mismatch: bool}
     */
    public static function classifySizeDifference(int $releaseBytes, int $filesBytes, int $thresholdBasisPoints, string $direction): array
    {
        return (self::usesSizeMismatchRustFfi() ? self::classifySizeDifferenceNative($releaseBytes, $filesBytes, $thresholdBasisPoints, $direction) : null)
            ?? self::classifySizeDifferencePhp($releaseBytes, $filesBytes, $thresholdBasisPoints, $direction);
    }

    /**
     * @return array{valid: bool, measurable: bool, diff: int, basis_points: int, sign: string, mismatch: bool}|null
     */
    private static function classifySizeDifferenceNative(int $releaseBytes, int $filesBytes, int $thresholdBasisPoints, string $direction): ?array
    {
        $fields = self::callSizeMismatchNative('nntmux_w383_size_difference', [
            $direction, strlen($direction), $releaseBytes, $filesBytes, $thresholdBasisPoints,
        ], 96);
        if ($fields === null || count($fields) !== 6 || ! in_array($fields[4], self::SIGNS, true)
            || preg_match('/\A-?[0-9]{1,19}\z/', $fields[2]) !== 1 || preg_match('/\A-?[0-9]{1,19}\z/', $fields[3]) !== 1) {
            return null;
        }

        return [
            'valid' => $fields[0] === '1',
            'measurable' => $fields[1] === '1',
            'diff' => (int) $fields[2],
            'basis_points' => (int) $fields[3],
            'sign' => $fields[4],
            'mismatch' => $fields[5] === '1',
        ];
    }

    /**
     * @return array{valid: bool, measurable: bool, diff: int, basis_points: int, sign: string, mismatch: bool}
     */
    private static function classifySizeDifferencePhp(int $releaseBytes, int $filesBytes, int $thresholdBasisPoints, string $direction): array
    {
        if (! in_array($direction, self::DIRECTIONS, true)
            || $releaseBytes < 0 || $releaseBytes > self::MAX_SIZE_BYTES
            || $filesBytes < 0 || $filesBytes > self::MAX_SIZE_BYTES
            || $thresholdBasisPoints < 0 || $thresholdBasisPoints > self::MAX_THRESHOLD_BASIS_POINTS) {
            return ['valid' => false, 'measurable' => false, 'diff' => 0, 'basis_points' => 0, 'sign' => 'none', 'mismatch' => false];
        }
        $diff = $releaseBytes - $filesBytes;
        $measurable = $releaseBytes > 0;
        $points = $measurable ? intdiv($diff * 10_000, $releaseBytes) : 0;
        $mismatch = $measurable && match ($direction) {
            'bigger' => $diff > 0 && $points > $thresholdBasisPoints,
            'smaller' => $diff < 0 && -$points > $thresholdBasisPoints,
            default => abs($points) > $thresholdBasisPoints,
        };

        return [
            'valid' => true,
            'measurable' => $measurable,
            'diff' => $diff,
            'basis_points' => $points,
            'sign' => $diff > 0 ? 'bigger' : ($diff < 0 ? 'smaller' : 'equal'),
            'mismatch' => $mismatch,
        ];
    }

    /**
     * @param  list<int|string>  $args  arguments preceding the caller-owned output buffer
     * @return list<string>|null
     */
    private static function callSizeMismatchNative(string $symbol, array $args, int $minBytes): ?array
    {
        $ffi = self::sizeMismatchRustFfi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $minBytes, $capacity);
            if ($buffer === null) {
                return null;
            }
            $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return explode(self::FIELD_SEPARATOR, FFI::string($buffer, $written));
        } catch (Throwable) {
            return null;
        }
    }

    private static function sizeMismatchRustFfi(): ?FFI
    {
        if (self::$sizeMismatchRustFfiHandle === null) {
            self::$sizeMismatchRustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w383', self::FFI_CDEF) ?? false;
        }

        return self::$sizeMismatchRustFfiHandle === false ? null : self::$sizeMismatchRustFfiHandle;
    }
}
