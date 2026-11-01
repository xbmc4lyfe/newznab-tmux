<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds;

use RuntimeException;

/**
 * A feed reported throttling in its response body (e.g. an HTTP 200 "rate limit" envelope);
 * the importer backs off and retries the page as it does for HTTP 429.
 */
final class FeedRateLimitedException extends RuntimeException {}
