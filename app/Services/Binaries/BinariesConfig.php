<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use App\Models\Settings;

/**
 * Configuration DTO for Binaries processing.
 * Encapsulates all settings in an immutable object for easier testing and injection.
 */
final readonly class BinariesConfig
{
    public function __construct(
        public int $messageBuffer = 20000,
        public bool $compressedHeaders = true,
        public bool $partRepair = true,
        public bool $newGroupScanByDays = false,
        public int $newGroupMessagesToScan = 50000,
        public int $newGroupDaysToScan = 3,
        public int $partRepairLimit = 15000,
        public int $partRepairMaxTries = 3,
        public int $partsChunkSize = 5000,
        public int $binariesUpdateChunkSize = 1000,
        public bool $echoCli = false,
        // Number of headers processed (and bulk-inserted) at a time inside
        // HeaderStorageService. This MUST stay small because each chunk
        // produces multi-row INSERTs/SELECTs whose binding count and SQL size
        // grow linearly with the value. Defaulting it to partsChunkSize
        // (which used to only control single-row part flushes) caused MySQL
        // and PHP to allocate hundreds of MB per scan and run out of RAM.
        public int $headerChunkSize = 500,
        // Hard upper bound applied internally to bulk SELECT/INSERT/UPDATE
        // operations regardless of caller-provided chunk size, so a
        // misconfiguration cannot blow up server memory.
        public int $bulkSqlChunkSize = 500,
    ) {}

    /**
     * Create configuration from application settings.
     */
    public static function fromSettings(): self
    {
        return new self(
            messageBuffer: self::getSettingInt('maxmssgs', 20000),
            compressedHeaders: (bool) config('nntmux_nntp.compressed_headers'),
            partRepair: self::getSettingInt('partrepair', 1) === 1,
            newGroupScanByDays: self::getSettingInt('newgroupscanmethod', 0) === 1,
            newGroupMessagesToScan: self::getSettingInt('newgroupmsgstoscan', 50000),
            newGroupDaysToScan: self::getSettingInt('newgroupdaystoscan', 3),
            partRepairLimit: self::getSettingInt('maxpartrepair', 15000),
            partRepairMaxTries: self::getSettingInt('partrepairmaxtries', 3),
            partsChunkSize: max(100, (int) config('nntmux.parts_chunk_size', 5000)),
            binariesUpdateChunkSize: max(100, min(1000, (int) config('nntmux.binaries_update_chunk_size', 1000))),
            echoCli: (bool) config('nntmux.echocli'),
            headerChunkSize: max(50, min(2000, (int) config('nntmux.header_chunk_size', 500))),
            bulkSqlChunkSize: max(50, min(1000, (int) config('nntmux.bulk_sql_chunk_size', 500))),
        );
    }

    private static function getSettingInt(string $key, int $default): int
    {
        $value = Settings::settingValue($key);

        return $value !== '' ? (int) $value : $default;
    }
}
