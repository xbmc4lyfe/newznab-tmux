<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Services\Predb\Feeds\Contracts\PredbFeedSource;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Shared HTTP plumbing for remote PreDB feeds.
 */
abstract class HttpFeedSource implements PredbFeedSource
{
    public function __construct(
        protected readonly string $endpoint,
        protected readonly int $pageSize = 100,
        protected readonly int $timeout = 15,
        protected readonly string $userAgent = 'NNTmux-PreDB-Importer/1.0',
    ) {}

    protected function http(): PendingRequest
    {
        return Http::timeout($this->timeout)
            ->connectTimeout(min($this->timeout, 10))
            ->withUserAgent($this->userAgent)
            ->retry(2, 500, throw: false);
    }
}
