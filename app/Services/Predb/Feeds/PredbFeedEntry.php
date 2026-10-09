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
    ) {}

    /**
     * Format a size given in megabytes the way IRC pre bots announce it, or null when unknown.
     */
    public static function sizeFromMegabytes(int|float|null $megabytes): ?string
    {
        if ($megabytes === null || $megabytes <= 0) {
            return null;
        }

        return rtrim(rtrim(number_format((float) $megabytes, 2, '.', ''), '0'), '.').'MB';
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
