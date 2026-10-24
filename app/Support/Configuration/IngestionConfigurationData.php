<?php

declare(strict_types=1);

namespace App\Support\Configuration;

use App\Models\IngestionConfiguration;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

final readonly class IngestionConfigurationData
{
    public const string FFI_CDEF = <<<'C'
        intptr_t nntmux_icd_defaults(int64_t *out, size_t out_count, char *date, size_t date_cap);
        int32_t nntmux_icd_normalize(const int64_t *raw, size_t count, int64_t *out, size_t out_count);
        C;

    /** Property names in declaration order (the `toArray()` keys). */
    public const array FIELDS = [
        'binaryThreads', 'backfillThreads', 'releaseThreads', 'collectionDelayHours', 'collectionTimeoutHours',
        'crossPostHours', 'completionPercent', 'grabStatus', 'maxHeadersPerIteration', 'maxMessages',
        'maxReleasesCreated', 'nntpRetries', 'nzbSplitLevel', 'partRetentionHours', 'releaseRetentionDays',
        'miscOtherRetentionHours', 'miscHashedRetentionHours', 'minFilesToFormRelease', 'minSizeToFormRelease',
        'maxSizeToFormRelease', 'newGroupScanMethod', 'newGroupDaysToScan', 'newGroupMessagesToScan',
        'safeBackfillDate', 'disableBackfillGroup', 'partRepair', 'safePartRepair', 'maxPartRepair',
        'partRepairMaxTries', 'categorizeForeign', 'categorizeWebDl', 'showPasswordedReleases',
        'deletePasswordedReleases', 'backfillDaysMode', 'backfillOrder', 'backfillQuantity',
    ];

    /** Position of the only string field; the other 35 cross the C ABI as `int64_t`. */
    private const int DATE_FIELD = 23;

    private const int NUMERIC_COUNT = 35;

    /** Boolean field positions within {@see FIELDS}. */
    private const array BOOL_FIELDS = [7 => true, 24 => true, 25 => true, 26 => true, 29 => true, 30 => true, 31 => true, 32 => true];

    public function __construct(
        public int $binaryThreads,
        public int $backfillThreads,
        public int $releaseThreads,
        public int $collectionDelayHours,
        public int $collectionTimeoutHours,
        public int $crossPostHours,
        public int $completionPercent,
        public bool $grabStatus,
        public int $maxHeadersPerIteration,
        public int $maxMessages,
        public int $maxReleasesCreated,
        public int $nntpRetries,
        public int $nzbSplitLevel,
        public int $partRetentionHours,
        public int $releaseRetentionDays,
        public int $miscOtherRetentionHours,
        public int $miscHashedRetentionHours,
        public int $minFilesToFormRelease,
        public int $minSizeToFormRelease,
        public int $maxSizeToFormRelease,
        public int $newGroupScanMethod,
        public int $newGroupDaysToScan,
        public int $newGroupMessagesToScan,
        public string $safeBackfillDate,
        public bool $disableBackfillGroup,
        public bool $partRepair,
        public bool $safePartRepair,
        public int $maxPartRepair,
        public int $partRepairMaxTries,
        public bool $categorizeForeign,
        public bool $categorizeWebDl,
        public bool $showPasswordedReleases,
        public bool $deletePasswordedReleases,
        public int $backfillDaysMode,
        public int $backfillOrder,
        public int $backfillQuantity,
    ) {}

    // PHP remains the production default until benchmarks qualify Rust.
    private static function rustEnabled(?bool $enabled = null): bool
    {
        // Readonly classes cannot declare static properties.
        static $useRustFfi = false;
        if ($enabled !== null) {
            $useRustFfi = $enabled;
        }

        return $useRustFfi;
    }

    public static function setUseRustFfi(bool $enabled): void
    {
        self::rustEnabled($enabled);
    }

    public static function usesRustFfi(): bool
    {
        return self::rustEnabled();
    }

    /**
     * Cached `additional` domain handle; `$reset` replaces it with `$override` (null re-resolves).
     */
    public static function additionalFfi(bool $reset = false, FFI|false|null $override = null): ?FFI
    {
        static $ffi = null;
        if ($reset) {
            $ffi = $override;
        }
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return $ffi === false ? null : $ffi;
    }

    public static function defaults(): self
    {
        return self::rustEnabled() ? self::defaultsNative() : self::defaultsPhp();
    }

    public static function defaultsPhp(): self
    {
        return new self(1, 1, 1, 2, 48, 2, 95, true, 1000000, 20000, 1000, 10, 4, 72, 0, 0, 0, 1, 0, 0, 0, 1, 100000, '2012-06-24', false, true, false, 15000, 3, true, false, false, false, 1, 2, 100000);
    }

    public static function fromModel(IngestionConfiguration $model): self
    {
        return new self(
            (int) $model->binary_threads,
            (int) $model->backfill_threads,
            (int) $model->release_threads,
            (int) $model->collection_delay_hours,
            (int) $model->collection_timeout_hours,
            (int) $model->cross_post_hours,
            (int) $model->completion_percent,
            (bool) $model->grab_status,
            (int) $model->max_headers_per_iteration,
            (int) $model->max_messages,
            (int) $model->max_releases_created,
            (int) $model->nntp_retries,
            (int) $model->nzb_split_level,
            (int) $model->part_retention_hours,
            (int) $model->release_retention_days,
            (int) $model->misc_other_retention_hours,
            (int) $model->misc_hashed_retention_hours,
            (int) $model->min_files_to_form_release,
            (int) $model->min_size_to_form_release,
            (int) $model->max_size_to_form_release,
            $model->new_group_scan_method->value,
            (int) $model->new_group_days_to_scan,
            (int) $model->new_group_messages_to_scan,
            $model->safe_backfill_date->format('Y-m-d'),
            (bool) $model->disable_backfill_group,
            (bool) $model->part_repair,
            (bool) $model->safe_part_repair,
            (int) $model->max_part_repair,
            (int) $model->part_repair_max_tries,
            (bool) $model->categorize_foreign,
            (bool) $model->categorize_web_dl,
            (bool) $model->show_passworded_releases,
            (bool) $model->delete_passworded_releases,
            $model->backfill_days_mode->value,
            (int) $model->backfill_order,
            (int) $model->backfill_quantity,
        );
    }

    public static function defaultsNative(): self
    {
        $ffi = self::additionalFfi();
        $buffers = $ffi === null ? null : self::buffers($ffi);
        if ($ffi === null || $buffers === null) {
            return self::defaultsPhp();
        }
        [$values, $date] = $buffers;

        try {
            // @phpstan-ignore method.notFound
            $dateLength = $ffi->nntmux_icd_defaults($values, self::NUMERIC_COUNT, $date, 32);
            if (! is_int($dateLength) || $dateLength <= 0 || $dateLength > 32) {
                return self::defaultsPhp();
            }
            $numeric = unpack('q'.self::NUMERIC_COUNT, FFI::string($values, self::NUMERIC_COUNT * 8));
            $dateString = FFI::string($date, $dateLength);
        } catch (Throwable) {
            return self::defaultsPhp();
        }
        if (! is_array($numeric) || count($numeric) !== self::NUMERIC_COUNT) {
            return self::defaultsPhp();
        }

        return new self(...self::decodeFields(array_values($numeric), $dateString));
    }

    /** @return array<string, int|bool|string> */
    public function toArray(): array
    {
        return self::rustEnabled() ? $this->toArrayNative() : $this->toArrayPhp();
    }

    /** @return array<string, int|bool|string> */
    public function toArrayPhp(): array
    {
        return get_object_vars($this);
    }

    /** @return array<string, int|bool|string> */
    public function toArrayNative(): array
    {
        $ffi = self::additionalFfi();
        $buffers = $ffi === null ? null : self::buffers($ffi);
        if ($ffi === null || $buffers === null) {
            return $this->toArrayPhp();
        }
        [$values] = $buffers;

        $raw = [
            $this->binaryThreads, $this->backfillThreads, $this->releaseThreads, $this->collectionDelayHours,
            $this->collectionTimeoutHours, $this->crossPostHours, $this->completionPercent, (int) $this->grabStatus,
            $this->maxHeadersPerIteration, $this->maxMessages, $this->maxReleasesCreated, $this->nntpRetries,
            $this->nzbSplitLevel, $this->partRetentionHours, $this->releaseRetentionDays, $this->miscOtherRetentionHours,
            $this->miscHashedRetentionHours, $this->minFilesToFormRelease, $this->minSizeToFormRelease,
            $this->maxSizeToFormRelease, $this->newGroupScanMethod, $this->newGroupDaysToScan,
            $this->newGroupMessagesToScan, (int) $this->disableBackfillGroup, (int) $this->partRepair,
            (int) $this->safePartRepair, $this->maxPartRepair, $this->partRepairMaxTries, (int) $this->categorizeForeign,
            (int) $this->categorizeWebDl, (int) $this->showPasswordedReleases, (int) $this->deletePasswordedReleases,
            $this->backfillDaysMode, $this->backfillOrder, $this->backfillQuantity,
        ];

        try {
            FFI::memcpy($values, pack('q*', ...$raw), self::NUMERIC_COUNT * 8);
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_icd_normalize($values, self::NUMERIC_COUNT, $values, self::NUMERIC_COUNT);
            if ($written !== self::NUMERIC_COUNT) {
                return $this->toArrayPhp();
            }
            $numeric = unpack('q'.self::NUMERIC_COUNT, FFI::string($values, self::NUMERIC_COUNT * 8));
        } catch (Throwable) {
            return $this->toArrayPhp();
        }
        if (! is_array($numeric) || count($numeric) !== self::NUMERIC_COUNT) {
            return $this->toArrayPhp();
        }

        return array_combine(self::FIELDS, self::decodeFields(array_values($numeric), $this->safeBackfillDate));
    }

    /**
     * Rebuild the 36 constructor arguments from the 35 numeric C ABI values and the date.
     *
     * @param  list<mixed>  $numeric
     * @return list<int|bool|string>
     */
    private static function decodeFields(array $numeric, string $date): array
    {
        $fields = [];
        $next = 0;
        for ($position = 0, $total = self::NUMERIC_COUNT + 1; $position < $total; $position++) {
            if ($position === self::DATE_FIELD) {
                $fields[] = $date;

                continue;
            }
            $value = (int) $numeric[$next++];
            $fields[] = isset(self::BOOL_FIELDS[$position]) ? $value !== 0 : $value;
        }

        return $fields;
    }

    /**
     * Reusable `int64_t[35]` value and `char[32]` date buffers for `$ffi`.
     *
     * @return array{0: CData, 1: CData}|null
     */
    private static function buffers(FFI $ffi): ?array
    {
        static $buffers = null;
        static $owner = null;
        if ($owner !== $ffi || $buffers === null) {
            try {
                $buffers = [$ffi->new('int64_t['.self::NUMERIC_COUNT.']'), $ffi->new('char[32]')];
                $owner = $ffi;
            } catch (Throwable) {
                return null;
            }
        }

        return $buffers;
    }
}
