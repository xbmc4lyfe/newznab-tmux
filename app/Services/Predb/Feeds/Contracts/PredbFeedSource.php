<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Contracts;

use App\Services\Predb\Feeds\FeedRateLimitedException;
use App\Services\Predb\Feeds\PredbFeedEntry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use RuntimeException;

interface PredbFeedSource
{
    /**
     * Stable key used in config (`predb_feeds.sources`) and stored as predb.source.
     */
    public function key(): string;

    /**
     * Fetch one page (1-based, newest first) of PREs.
     *
     * @return list<PredbFeedEntry>
     *
     * @throws ConnectionException|RequestException When the remote feed cannot be read.
     * @throws FeedRateLimitedException When the feed reports throttling in its response body.
     * @throws RuntimeException When the feed returns an error or an unexpected response.
     */
    public function fetch(int $page = 1): array;
}
