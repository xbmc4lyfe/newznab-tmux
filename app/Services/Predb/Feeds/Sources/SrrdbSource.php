<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Services\Predb\Feeds\PredbFeedEntry;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
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

    /** @var array<string, DateTimeZone|false> Zones whose local dates may skip Carbon's parser. */
    private static array $zones = [];

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
        $response = $this->http()->get(self::pageUrl($this->endpoint, max(1, $page)))->throw();
        $native = PredatabaseSource::nativeJsonFields($response->body(), 4);
        if ($native !== null) {
            return $this->nativeEntries($native[1]);
        }

        $rows = $response->json('results');
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

    /**
     * @param  list<string>  $fields  PredatabaseSource::nativeJsonFields() output (one empty header field).
     * @return list<PredbFeedEntry>
     */
    private function nativeEntries(array $fields): array
    {
        $zone = ! CarbonImmutable::hasTestNow() ? (self::$zones[$this->timezone] ??= in_array($this->timezone, DateTimeZone::listIdentifiers(), true) ? new DateTimeZone($this->timezone) : false) : false;
        $entries = [];
        for ($i = 1, $count = count($fields); $i < $count; $i += 7) {
            $date = $fields[$i + 4];
            $entries[] = new PredbFeedEntry($fields[$i], 'srrdb', listedAt: $date === '' ? null : $this->nativeDate($date, $zone));
        }

        return $entries;
    }

    /**
     * parseDate() for a trimmed date; "!" marks the canonical "Y-m-d H:i:s" shape, which the native
     * parser reads exactly as Carbon's createFromFormat() does.
     */
    private function nativeDate(string $date, DateTimeZone|false $zone): ?CarbonImmutable
    {
        if ($date[0] === '!' && $zone !== false) {
            $local = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', substr($date, 1), $zone);
            $timestamp = $local === false ? -1 : $local->getTimestamp();
            if ($timestamp >= 0 && $timestamp <= 253402300799) {
                return PredatabaseSource::nativeTime((string) $timestamp, named: true);
            }
        }

        return $this->parseDate(substr($date, 1));
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
