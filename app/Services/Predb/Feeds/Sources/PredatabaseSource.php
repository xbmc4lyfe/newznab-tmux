<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Services\Predb\Feeds\FeedRateLimitedException;
use App\Services\Predb\Feeds\PredbFeedEntry;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use RuntimeException;
use Throwable;

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

    private const MAX_EMPTY_CONTINUATION_REQUESTS = 5;

    private ?string $cursor = null;

    private int $lastPage = 0;

    private int $requests = 0;

    private ?int $pendingPage = null;

    private int $pendingRequests = 0;

    /** @var array<string, true> */
    private array $visitedCursors = [];

    private ?int $oldestTimestamp = null;

    /** @var list<PredbFeedEntry> */
    private array $pendingEntries = [];

    /** @var list<PredbFeedEntry> */
    private array $completedEntries = [];

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

        if ($this->pendingPage !== null) {
            if ($page !== $this->pendingPage) {
                throw new RuntimeException('predataba.se has an unfinished page; retry it before fetching the next page.');
            }
        } else {
            if ($page === $this->lastPage) {
                return $this->completedEntries;
            }
            if ($page !== $this->lastPage + 1) {
                throw new RuntimeException('predataba.se pages through a cursor; fetch pages in order starting at 1.');
            }
            if ($this->lastPage > 0 && $this->cursor === null) {
                return [];
            }
            $this->pendingPage = $page;
            $this->pendingRequests = 0;
            $this->pendingEntries = [];
        }

        $requestsPerPage = max(1, intdiv($this->pageSize + self::ROWS_PER_REQUEST - 1, self::ROWS_PER_REQUEST));

        while ($this->pendingRequests < $requestsPerPage || ($this->pendingEntries === [] && $this->cursor !== null)) {
            if ($this->pendingRequests >= $requestsPerPage + self::MAX_EMPTY_CONTINUATION_REQUESTS) {
                throw new RuntimeException('predataba.se returned too many nonterminal pages without eligible titles; history coverage is incomplete.');
            }
            if ($this->apiKey === '' && $this->requests >= self::ANONYMOUS_REQUEST_LIMIT) {
                $this->cursor = null;
                break;
            }

            [$rows, $nextCursor] = $this->request($this->cursor);
            $entries = $this->parseRows($rows);
            if ($nextCursor !== null && ($nextCursor === $this->cursor || isset($this->visitedCursors[':'.$nextCursor]))) {
                throw new RuntimeException('predataba.se returned a repeated history cursor.');
            }
            $oldestTimestamp = $this->validateChronology($rows);
            if ($this->cursor !== null) {
                $this->visitedCursors[':'.$this->cursor] = true;
            }
            array_push($this->pendingEntries, ...$entries);
            $this->oldestTimestamp = $oldestTimestamp;
            $this->cursor = $nextCursor;
            $this->pendingRequests++;

            if ($this->cursor === null) {
                $this->cursor = null;
                break;
            }
        }

        $this->lastPage = $page;
        $this->completedEntries = $this->pendingEntries;
        $this->pendingPage = null;
        $this->pendingEntries = [];

        return $this->completedEntries;
    }

    /**
     * @return array{0: array<int, mixed>, 1: ?string} The rows and the cursor for the next request.
     */
    private function request(?string $cursor): array
    {
        $attempts = $this->apiKey === '' ? min(2, self::ANONYMOUS_REQUEST_LIMIT - $this->requests) : 2;
        $request = $this->http()
            ->retry($attempts, 500, static fn (Throwable $exception): bool => $exception instanceof ConnectionException
                || ($exception instanceof RequestException && $exception->response->serverError()), throw: false)
            ->beforeSending(function (): void {
                if ($this->apiKey === '' && $this->requests >= self::ANONYMOUS_REQUEST_LIMIT) {
                    throw new RuntimeException('predataba.se anonymous wire request allowance is exhausted.');
                }
                $this->requests++;
            });
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
        if ($next !== null && ! is_string($next)) {
            throw new RuntimeException('predataba.se returned a malformed history cursor.');
        }

        return [$rows, $next !== '' ? $next : null];
    }

    /**
     * Validate received dates before committing chunk state or allowing a CLI history cutoff.
     * Unknown dates stay unknown; only an explicit reversal of known dates is rejected.
     *
     * @param  array<int, mixed>  $rows
     */
    private function validateChronology(array $rows): ?int
    {
        $oldest = $this->oldestTimestamp;
        foreach ($rows as $row) {
            $timestamp = is_array($row) && is_numeric($row['ctime'] ?? null) ? (int) $row['ctime'] : 0;
            if ($timestamp <= 0) {
                continue;
            }
            if ($oldest !== null && $timestamp > $oldest) {
                throw new RuntimeException('predataba.se returned reversed chronological history.');
            }
            $oldest = $timestamp;
        }

        return $oldest;
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
