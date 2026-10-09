<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Services\Predb\Feeds\PredbFeedEntry;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

/**
 * srrDB search API, newest first (`/v1/search/order:date-desc`), 45 results per page paged with `/skip:N`.
 *
 * Row shape: {release, date (SRR upload time, "Y-m-d H:i:s" in srrDB's local time), size (bytes), hasNFO, hasSRS, isForeign}
 */
final class SrrdbSource extends HttpFeedSource
{
    /** srrDB returns a fixed 45 results per search page. */
    public const int PAGE_SIZE = 45;

    public function __construct(
        string $endpoint,
        int $pageSize = 100,
        int $timeout = 15,
        string $userAgent = 'NNTmux-PreDB-Importer/1.0',
        private readonly string $timezone = 'Europe/Brussels',
    ) {
        parent::__construct($endpoint, $pageSize, $timeout, $userAgent);
    }

    public function key(): string
    {
        return 'srrdb';
    }

    public static function pageUrl(string $endpoint, int $page): string
    {
        $endpoint = rtrim($endpoint, '/');

        return $page > 1 ? $endpoint.'/skip:'.(($page - 1) * self::PAGE_SIZE) : $endpoint;
    }

    public function fetch(int $page = 1): array
    {
        $rows = $this->http()->get(self::pageUrl($this->endpoint, max(1, $page)))->throw()->json('results');
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new RuntimeException('srrDB returned an unexpected response (no results list).');
        }

        return $this->parseRows($rows);
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return list<PredbFeedEntry>
     */
    public function parseRows(array $rows): array
    {
        $entries = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['release'] ?? null) || trim($row['release']) === '') {
                continue;
            }

            // srrDB's `date` is when the SRR was added (possibly long after the PRE) and `size` can
            // describe only an NFO, so neither is stored as PRE data; the date only drives paging.
            $entries[] = new PredbFeedEntry(
                title: trim($row['release']),
                source: 'srrdb',
                listedAt: $this->parseDate($row['date'] ?? null),
            );
        }

        return $entries;
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d H:i:s', trim($value), $this->timezone)?->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
