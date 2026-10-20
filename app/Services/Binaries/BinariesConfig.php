<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use App\Services\Configuration\ConfigurationProvider;

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
        public bool $echoCli = false,
        // Number of headers processed (and bulk-inserted) at a time inside
        // HeaderStorageService. This MUST stay small because each chunk
        // produces multi-row INSERTs/SELECTs whose binding count and SQL size
        // grow linearly with the value. Large unbounded chunks caused MySQL
        // and PHP to allocate hundreds of MB per scan and run out of RAM.
        public int $headerChunkSize = 500,
        // One shared hard upper bound for every bulk SELECT/INSERT/UPDATE.
        public int $sqlChunkSize = 500,
        public int $reconcileBatchSize = 500,
        public int $nzbStreamRows = 5000,
        // One release pass reconciles or sizes at most this many pages, and stops
        // starting new pages after this many seconds; later passes resume.
        public int $reconcilePagesPerPass = 1000,
        public int $reconcilePassSeconds = 300,
        // Skip a range's articles whose subject is randomised per article once this many
        // share a subject shape without repeating a name; 0 keeps them.
        public int $perArticleObfuscationMinArticles = 16,
    ) {}

    /**
     * Create configuration from application settings.
     */
    public static function fromSettings(): self
    {
        $configuration = app(ConfigurationProvider::class)->ingestion();

        return new self(
            messageBuffer: $configuration->maxMessages,
            compressedHeaders: (bool) config('nntmux_nntp.compressed_headers'),
            partRepair: $configuration->partRepair,
            newGroupScanByDays: $configuration->newGroupScanMethod === 1,
            newGroupMessagesToScan: $configuration->newGroupMessagesToScan,
            newGroupDaysToScan: $configuration->newGroupDaysToScan,
            partRepairLimit: $configuration->maxPartRepair,
            partRepairMaxTries: $configuration->partRepairMaxTries,
            echoCli: (bool) config('nntmux.echocli'),
            headerChunkSize: max(50, min(2000, (int) config('nntmux.cbp.header_chunk_size', 500))),
            sqlChunkSize: max(50, min(1000, (int) config('nntmux.cbp.sql_chunk_size', 500))),
            reconcileBatchSize: max(50, min(2000, (int) config('nntmux.cbp.reconcile_batch_size', 500))),
            nzbStreamRows: max(500, min(20000, (int) config('nntmux.cbp.nzb_stream_rows', 5000))),
            reconcilePagesPerPass: max(1, (int) config('nntmux.cbp.reconcile_pages_per_pass', 1000)),
            reconcilePassSeconds: max(1, (int) config('nntmux.cbp.reconcile_pass_seconds', 300)),
            perArticleObfuscationMinArticles: self::obfuscationMinArticles((int) config('nntmux.cbp.per_article_obfuscation_min_articles', 16)),
        );
    }

    /**
     * 0 or less disables the check; any other value is held to at least 8 so a handful of
     * unrelated single articles cannot form a group.
     */
    public static function obfuscationMinArticles(int $configured): int
    {
        return $configured <= 0 ? 0 : max(8, $configured);
    }
}
