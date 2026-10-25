<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BackfillDaysMode;
use App\Enums\GroupScanMode;
use App\Models\Concerns\IsSingletonConfiguration;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * @property int $binary_threads
 * @property int $backfill_threads
 * @property int $release_threads
 * @property int $collection_delay_hours
 * @property int $collection_timeout_hours
 * @property int $cross_post_hours
 * @property int $completion_percent
 * @property bool $grab_status
 * @property int $max_headers_per_iteration
 * @property int $max_messages
 * @property int $max_releases_created
 * @property int $nntp_retries
 * @property int $nzb_split_level
 * @property int $part_retention_hours
 * @property int $release_retention_days
 * @property int $misc_other_retention_hours
 * @property int $misc_hashed_retention_hours
 * @property int $min_files_to_form_release
 * @property int $min_size_to_form_release
 * @property int $max_size_to_form_release
 * @property GroupScanMode $new_group_scan_method
 * @property int $new_group_days_to_scan
 * @property int $new_group_messages_to_scan
 * @property Carbon $safe_backfill_date
 * @property bool $disable_backfill_group
 * @property bool $part_repair
 * @property bool $safe_part_repair
 * @property int $max_part_repair
 * @property int $part_repair_max_tries
 * @property bool $categorize_foreign
 * @property bool $categorize_web_dl
 * @property bool $show_passworded_releases
 * @property bool $delete_passworded_releases
 * @property BackfillDaysMode $backfill_days_mode
 * @property int $backfill_order
 * @property int $backfill_quantity
 */
final class IngestionConfiguration extends Model
{
    use IsSingletonConfiguration;

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w34_ingestion_configuration_normalize_release_rules(
            int64_t completion_percent,
            int64_t min_files,
            int64_t min_size,
            int64_t max_size,
            int64_t collection_delay_hours,
            int64_t collection_timeout_hours,
            int64_t cross_post_hours,
            int64_t new_group_scan_method,
            int64_t new_group_days_to_scan,
            int64_t new_group_messages_to_scan,
            char *out_buf,
            size_t out_cap
        );
        C;

    /** Hour settings are clamped to 100 years so `* 3600` never overflows to float. */
    private const int HOURS_MAX = 876000;

    private const int SCAN_DAYS_MAX = 36500;

    private const int UINT32_MAX = 4294967295;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustRegexIngestionConfigModelsParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private const string FIELD_SEPARATOR = "\x1F";

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    protected $guarded = ['id'];

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * Clamps the release-formation and new-group scan settings and derives their runtime values.
     *
     * @return array{completion_percent: int, min_files_to_form_release: int, min_size_to_form_release: int, max_size_to_form_release: int, size_window_valid: bool, collection_delay_seconds: int, collection_timeout_seconds: int, cross_post_seconds: int, timeout_after_delay: bool, new_group_scan_method: int, new_group_scan_unit: string, new_group_scan_target: int}
     */
    public static function normalizeReleaseFormationRules(int $completionPercent, int $minFiles, int $minSize, int $maxSize, int $collectionDelayHours, int $collectionTimeoutHours, int $crossPostHours, int $newGroupScanMethod, int $newGroupDaysToScan, int $newGroupMessagesToScan): array
    {
        return self::usesRustFfi()
            ? self::normalizeReleaseFormationRulesNative($completionPercent, $minFiles, $minSize, $maxSize, $collectionDelayHours, $collectionTimeoutHours, $crossPostHours, $newGroupScanMethod, $newGroupDaysToScan, $newGroupMessagesToScan)
            : self::normalizeReleaseFormationRulesPhp($completionPercent, $minFiles, $minSize, $maxSize, $collectionDelayHours, $collectionTimeoutHours, $crossPostHours, $newGroupScanMethod, $newGroupDaysToScan, $newGroupMessagesToScan);
    }

    /**
     * @return array{completion_percent: int, min_files_to_form_release: int, min_size_to_form_release: int, max_size_to_form_release: int, size_window_valid: bool, collection_delay_seconds: int, collection_timeout_seconds: int, cross_post_seconds: int, timeout_after_delay: bool, new_group_scan_method: int, new_group_scan_unit: string, new_group_scan_target: int}
     */
    public static function normalizeReleaseFormationRulesNative(int $completionPercent, int $minFiles, int $minSize, int $maxSize, int $collectionDelayHours, int $collectionTimeoutHours, int $crossPostHours, int $newGroupScanMethod, int $newGroupDaysToScan, int $newGroupMessagesToScan): array
    {
        $fields = self::callNative('nntmux_w34_ingestion_configuration_normalize_release_rules', [$completionPercent, $minFiles, $minSize, $maxSize, $collectionDelayHours, $collectionTimeoutHours, $crossPostHours, $newGroupScanMethod, $newGroupDaysToScan, $newGroupMessagesToScan]);
        if ($fields === null || count($fields) !== 12) {
            return self::normalizeReleaseFormationRulesPhp($completionPercent, $minFiles, $minSize, $maxSize, $collectionDelayHours, $collectionTimeoutHours, $crossPostHours, $newGroupScanMethod, $newGroupDaysToScan, $newGroupMessagesToScan);
        }

        return [
            'completion_percent' => (int) $fields[0],
            'min_files_to_form_release' => (int) $fields[1],
            'min_size_to_form_release' => (int) $fields[2],
            'max_size_to_form_release' => (int) $fields[3],
            'size_window_valid' => $fields[4] === '1',
            'collection_delay_seconds' => (int) $fields[5],
            'collection_timeout_seconds' => (int) $fields[6],
            'cross_post_seconds' => (int) $fields[7],
            'timeout_after_delay' => $fields[8] === '1',
            'new_group_scan_method' => (int) $fields[9],
            'new_group_scan_unit' => $fields[10],
            'new_group_scan_target' => (int) $fields[11],
        ];
    }

    /**
     * @return array{completion_percent: int, min_files_to_form_release: int, min_size_to_form_release: int, max_size_to_form_release: int, size_window_valid: bool, collection_delay_seconds: int, collection_timeout_seconds: int, cross_post_seconds: int, timeout_after_delay: bool, new_group_scan_method: int, new_group_scan_unit: string, new_group_scan_target: int}
     */
    public static function normalizeReleaseFormationRulesPhp(int $completionPercent, int $minFiles, int $minSize, int $maxSize, int $collectionDelayHours, int $collectionTimeoutHours, int $crossPostHours, int $newGroupScanMethod, int $newGroupDaysToScan, int $newGroupMessagesToScan): array
    {
        $minSize = max(0, $minSize);
        $maxSize = max(0, $maxSize);
        $delay = max(0, min($collectionDelayHours, self::HOURS_MAX));
        $timeout = max(0, min($collectionTimeoutHours, self::HOURS_MAX));
        $crossPost = max(0, min($crossPostHours, self::HOURS_MAX));
        $mode = $newGroupScanMethod === GroupScanMode::Days->value ? GroupScanMode::Days : GroupScanMode::Posts;
        $byDays = $mode === GroupScanMode::Days;

        return [
            'completion_percent' => max(0, min($completionPercent, 100)),
            'min_files_to_form_release' => max(0, min($minFiles, self::UINT32_MAX)),
            'min_size_to_form_release' => $minSize,
            'max_size_to_form_release' => $maxSize,
            'size_window_valid' => $maxSize === 0 || $maxSize >= $minSize,
            'collection_delay_seconds' => $delay * 3600,
            'collection_timeout_seconds' => $timeout * 3600,
            'cross_post_seconds' => $crossPost * 3600,
            'timeout_after_delay' => $timeout >= $delay,
            'new_group_scan_method' => $mode->value,
            'new_group_scan_unit' => strtolower($mode->name),
            'new_group_scan_target' => $byDays
                ? max(1, min($newGroupDaysToScan, self::SCAN_DAYS_MAX))
                : max(0, min($newGroupMessagesToScan, self::UINT32_MAX)),
        ];
    }

    protected function casts(): array
    {
        return [
            'categorize_foreign' => 'boolean',
            'categorize_web_dl' => 'boolean',
            'delete_passworded_releases' => 'boolean',
            'disable_backfill_group' => 'boolean',
            'grab_status' => 'boolean',
            'new_group_scan_method' => GroupScanMode::class,
            'backfill_days_mode' => BackfillDaysMode::class,
            'part_repair' => 'boolean',
            'safe_part_repair' => 'boolean',
            'show_passworded_releases' => 'boolean',
            'safe_backfill_date' => 'date:Y-m-d',
        ];
    }

    /**
     * @param  list<int|string>  $args
     * @return list<string>|null
     */
    private static function callNative(string $symbol, array $args): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 4096, $capacity);
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

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }
}
