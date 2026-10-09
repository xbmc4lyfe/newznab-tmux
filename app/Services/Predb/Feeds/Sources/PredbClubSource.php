<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Models\Predb;
use App\Services\Predb\Feeds\FeedRateLimitedException;
use App\Services\Predb\Feeds\PredbFeedEntry;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * predb.club JSON API (predb.ovh-compatible v1 schema).
 *
 * Row shape: {id, name, team, cat, size (MB), files, preAt (unix), nuke: null|{type, reason, ...}}
 */
final class PredbClubSource extends HttpFeedSource
{
    /** predb.club caps `count` at 100. */
    private const int MAX_PAGE_SIZE = 100;

    public function key(): string
    {
        return 'predb_club';
    }

    public function fetch(int $page = 1): array
    {
        $response = $this->http()->get($this->endpoint, [
            'count' => min($this->pageSize, self::MAX_PAGE_SIZE),
            'page' => max(1, $page),
        ])->throw();

        if ($response->json('status') === 'error') {
            $message = (string) $response->json('message', 'unknown');

            throw str_contains(strtolower($message), 'rate limit')
                ? new FeedRateLimitedException('predb.club rate limited: '.$message)
                : new RuntimeException('predb.club API error: '.$message);
        }

        $rows = $response->json('data.rows');
        if (! is_array($rows)) {
            throw new RuntimeException('predb.club returned an unexpected response (no data.rows).');
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
            if (! is_array($row) || ! is_string($row['name'] ?? null) || trim($row['name']) === '') {
                continue;
            }

            [$nuked, $reason] = $this->nukeStatus($row['nuke'] ?? null);

            $entries[] = new PredbFeedEntry(
                title: trim($row['name']),
                source: 'predb.club',
                category: is_string($row['cat'] ?? null) && $row['cat'] !== '' ? $row['cat'] : null,
                size: PredbFeedEntry::sizeFromMegabytes(is_numeric($row['size'] ?? null) ? (float) $row['size'] : null),
                files: PredbFeedEntry::filesFromCount(is_numeric($row['files'] ?? null) ? (int) $row['files'] : null),
                predate: is_numeric($row['preAt'] ?? null) ? CarbonImmutable::createFromTimestampUTC((int) $row['preAt']) : null,
                nuked: $nuked,
                nukeReason: $reason,
            );
        }

        return $entries;
    }

    /**
     * @return array{0: int, 1: string|null}
     */
    private function nukeStatus(mixed $nuke): array
    {
        if (! is_array($nuke)) {
            return [Predb::PRE_NONUKE, null];
        }

        $reason = is_string($nuke['reason'] ?? null) && $nuke['reason'] !== '' ? $nuke['reason'] : null;
        $status = match (strtolower((string) ($nuke['type'] ?? 'nuke'))) {
            'unnuke', 'undelpre' => Predb::PRE_UNNUKED,
            'modnuke' => Predb::PRE_MODNUKE,
            'renuke' => Predb::PRE_RENUKED,
            'oldnuke' => Predb::PRE_OLDNUKE,
            default => Predb::PRE_NUKED,
        };

        return [$status, $reason];
    }
}
