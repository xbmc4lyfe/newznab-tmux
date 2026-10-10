<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds;

use App\Models\Predb;
use Carbon\CarbonImmutable;

/**
 * One PRE announced by a remote PreDB feed, normalised to predb table semantics.
 */
final readonly class PredbFeedEntry
{
    public function __construct(
        public string $title,
        public string $source,
        public ?string $category = null,
        public ?string $size = null,
        public ?string $files = null,
        public ?CarbonImmutable $predate = null,
        public int $nuked = Predb::PRE_NONUKE,
        public ?string $nukeReason = null,
        // When the feed listed the entry, if that is not the PRE time (e.g. srrDB's upload time).
        // Used only to page history imports; never stored as predate.
        public ?CarbonImmutable $listedAt = null,
        // Details-only entry (e.g. an IRC INFO line): it may fill in an existing row but never creates one.
        public bool $enrichOnly = false,
    ) {}

    /**
     * Timestamp used to decide how far back a history import has paged.
     */
    public function pagingTime(): ?CarbonImmutable
    {
        return $this->predate ?? $this->listedAt;
    }

    /**
     * Format a size given in megabytes the way IRC pre bots announce it (KB below 1 MB), or null when unknown.
     */
    public static function sizeFromMegabytes(int|float|null $megabytes): ?string
    {
        if ($megabytes === null || $megabytes <= 0) {
            return null;
        }

        $format = static fn (float $value): string => rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return $megabytes < 1 ? $format($megabytes * 1024).'KB' : $format((float) $megabytes).'MB';
    }

    /**
     * Normalise a file count, treating zero as unknown.
     */
    public static function filesFromCount(int|string|null $count): ?string
    {
        if ($count === null || (int) $count <= 0) {
            return null;
        }

        return (string) (int) $count;
    }
}
