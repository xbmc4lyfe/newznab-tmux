<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Services\Predb\Feeds\FeedRateLimitedException;
use App\Services\Predb\Feeds\PredbFeedEntry;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * predataba.se search API (newest first).
 *
 * The API returns ten rows per request, ignores `page`, and pages only through the `next_page_id`
 * cursor. Anonymous clients may read five requests deep; an API key lifts that limit. One page of
 * this source chains up to `pageSize / 10` requests, and later pages continue the same cursor.
 *
 * Row shape: {rlsname, section, size (MB), files, ctime (unix), status, grp, has_nfo, traces}.
 * Only status 0 has been observed, so nuke status is left to the other feeds.
 */
final class PredatabaseSource extends HttpFeedSource
{
    public const ROWS_PER_REQUEST = 10;

    public const ANONYMOUS_REQUEST_LIMIT = 5;

    private ?string $cursor = null;

    private int $lastPage = 0;

    private int $requests = 0;

    public function __construct(
        string $endpoint,
        int $pageSize = 100,
        int $timeout = 15,
        string $userAgent = 'NNTmux-PreDB-Importer/1.0',
        private readonly string $apiKey = '',
    ) {
        parent::__construct($endpoint, $pageSize, $timeout, $userAgent);
    }

    public function key(): string
    {
        return 'predatabase';
    }

    public function fetch(int $page = 1): array
    {
        $page = max(1, $page);

        if ($page === 1) {
            $this->cursor = null;
            $this->requests = 0;
        } elseif ($page !== $this->lastPage && $page !== $this->lastPage + 1) {
            // The same page again is a retry after throttling; it resumes from the saved cursor.
            throw new RuntimeException('predataba.se pages through a cursor; fetch pages in order starting at 1.');
        } elseif ($this->cursor === null) {
            return [];
        }

        $this->lastPage = $page;
        $entries = [];
        $requestsPerPage = max(1, intdiv($this->pageSize + self::ROWS_PER_REQUEST - 1, self::ROWS_PER_REQUEST));

        for ($i = 0; $i < $requestsPerPage; $i++) {
            if ($this->apiKey === '' && $this->requests >= self::ANONYMOUS_REQUEST_LIMIT) {
                $this->cursor = null;
                break;
            }

            [$rows, $this->cursor] = $this->request($this->cursor);
            $this->requests++;
            array_push($entries, ...$this->parseRows($rows));

            if ($this->cursor === null || count($rows) < self::ROWS_PER_REQUEST) {
                $this->cursor = null;
                break;
            }
        }

        return $entries;
    }

    /**
     * @return array{0: array<int, mixed>, 1: ?string} The rows and the cursor for the next request.
     */
    private function request(?string $cursor): array
    {
        $request = $this->http();
        if ($this->apiKey !== '') {
            $request = $request->withToken($this->apiKey);
        }

        $response = $request->get($this->endpoint, array_filter(['page_id' => $cursor]));

        // The API answers 403 when the anonymous rate or depth limit is exceeded.
        if ($response->status() === 403) {
            throw new FeedRateLimitedException('predataba.se rate limited: '.(string) $response->json('message', 'forbidden'));
        }

        $response->throw();

        $rows = $response->json('results');
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new RuntimeException('predataba.se returned an unexpected response (no results).');
        }

        $next = $response->json('next_page_id');

        return [$rows, is_string($next) && $next !== '' ? $next : null];
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return list<PredbFeedEntry>
     */
    public function parseRows(array $rows): array
    {
        $entries = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['rlsname'] ?? null) || trim($row['rlsname']) === '') {
                continue;
            }

            $entries[] = new PredbFeedEntry(
                title: trim($row['rlsname']),
                source: 'predataba.se',
                category: is_string($row['section'] ?? null) && $row['section'] !== '' ? $row['section'] : null,
                size: PredbFeedEntry::sizeFromMegabytes(is_numeric($row['size'] ?? null) ? (float) $row['size'] : null),
                files: PredbFeedEntry::filesFromCount(is_numeric($row['files'] ?? null) ? (int) $row['files'] : null),
                predate: is_numeric($row['ctime'] ?? null) && (int) $row['ctime'] > 0
                    ? CarbonImmutable::createFromTimestampUTC((int) $row['ctime'])
                    : null,
            );
        }

        return $entries;
    }
}
