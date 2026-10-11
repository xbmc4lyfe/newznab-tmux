<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Models\Predb;
use App\Services\Predb\Feeds\FeedRateLimitedException;
use App\Services\Predb\Feeds\PredbFeedEntry;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * predb.ovh JSON API (v1).
 *
 * UNVERIFIED: api.predb.ovh is currently DNS-dead, so this parser is written from the historical shape
 * {status, data: {rows: [{id, pretime (unix), release, section, files, size (MB), status, reason, group, genre}]}}
 * with status 0 ok, 1 nuke, 2 unnuke, 3 delpre, 4 undelpre (as predb.net). The paging parameters are the old
 * `?q=&page=`. Opt-in only: add `predb_ovh` to PREDB_FEED_SOURCES once the service is back and confirmed.
 */
final class PredbOvhSource extends HttpFeedSource
{
    public function key(): string
    {
        return 'predb_ovh';
    }

    public function fetch(int $page = 1): array
    {
        $response = $this->http()->get($this->endpoint, [
            'q' => '',
            'page' => max(1, $page),
        ])->throw();

        if ($response->json('status') === 'error') {
            $message = (string) $response->json('message', 'unknown');

            throw str_contains(strtolower($message), 'rate limit')
                ? new FeedRateLimitedException('predb.ovh rate limited: '.$message)
                : new RuntimeException('predb.ovh API error: '.$message);
        }

        $rows = $response->json('data.rows');
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new RuntimeException('predb.ovh returned an unexpected response (no data.rows).');
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

            $status = (int) ($row['status'] ?? 0);
            $reason = is_string($row['reason'] ?? null) && $row['reason'] !== '' ? $row['reason'] : null;

            $entries[] = new PredbFeedEntry(
                title: trim($row['release']),
                source: 'predb.ovh',
                category: is_string($row['section'] ?? null) && $row['section'] !== '' ? $row['section'] : null,
                size: PredbFeedEntry::sizeFromMegabytes(is_numeric($row['size'] ?? null) ? (float) $row['size'] : null),
                files: PredbFeedEntry::filesFromCount(is_numeric($row['files'] ?? null) ? (int) $row['files'] : null),
                predate: is_numeric($row['pretime'] ?? null) ? CarbonImmutable::createFromTimestampUTC((int) $row['pretime']) : null,
                nuked: match ($status) {
                    0 => Predb::PRE_NONUKE,
                    2, 4 => Predb::PRE_UNNUKED,
                    default => Predb::PRE_NUKED,
                },
                nukeReason: $status === 0 ? null : $reason,
            );
        }

        return $entries;
    }
}
