<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Models\Predb;
use App\Services\Predb\Feeds\PredbFeedEntry;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * api.predb.net JSON API.
 *
 * Row shape: {release, section, size (MB), files, pretime (unix), status (0 ok, 1 nuke, 2 unnuke, 3 delpre, 4 undelpre), reason, group}
 */
final class PredbNetSource extends HttpFeedSource
{
    public function key(): string
    {
        return 'predb_net';
    }

    public function fetch(int $page = 1): array
    {
        $response = $this->http()->get($this->endpoint, [
            'limit' => $this->pageSize,
            'page' => max(1, $page),
        ])->throw();

        if ($response->json('status') === 'error') {
            throw new RuntimeException('predb.net API error: '.(string) $response->json('message', 'unknown'));
        }

        $rows = $response->json('data');

        return is_array($rows) ? $this->parseRows($rows) : [];
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
                source: 'predb.net',
                category: is_string($row['section'] ?? null) && $row['section'] !== '' ? $row['section'] : null,
                size: PredbFeedEntry::sizeFromMegabytes(is_numeric($row['size'] ?? null) ? (float) $row['size'] : null),
                files: PredbFeedEntry::filesFromCount(is_numeric($row['files'] ?? null) ? (int) $row['files'] : null),
                predate: is_numeric($row['pretime'] ?? null) ? CarbonImmutable::createFromTimestampUTC((int) $row['pretime']) : null,
                nuked: match ($status) {
                    0 => Predb::PRE_NONUKE,
                    2, 4 => Predb::PRE_UNNUKED, // unnuke, undelpre
                    default => Predb::PRE_NUKED,
                },
                nukeReason: $status === 0 ? null : $reason,
            );
        }

        return $entries;
    }
}
