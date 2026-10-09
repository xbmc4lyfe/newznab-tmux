<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Services\Predb\Feeds\PredbFeedEntry;
use Carbon\CarbonImmutable;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

/**
 * Generic RSS 2.0 PreDB feed (e.g. predb.me). Only the item title (and pubDate when present) is used.
 */
final class RssFeedSource extends HttpFeedSource
{
    public function __construct(
        private readonly string $sourceKey,
        private readonly string $sourceLabel,
        string $endpoint,
        int $pageSize = 100,
        int $timeout = 15,
        string $userAgent = 'NNTmux-PreDB-Importer/1.0',
    ) {
        parent::__construct($endpoint, $pageSize, $timeout, $userAgent);
    }

    public function key(): string
    {
        return $this->sourceKey;
    }

    /**
     * RSS feeds expose only their latest items, so every page past the first is empty.
     */
    public function fetch(int $page = 1): array
    {
        if ($page > 1) {
            return [];
        }

        return $this->parseXml($this->http()->get($this->endpoint)->throw()->body());
    }

    /**
     * @return list<PredbFeedEntry>
     *
     * @throws RuntimeException When the body is not an RSS 2.0 document (e.g. an HTML error page).
     */
    public function parseXml(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($document === false || strtolower($document->getName()) !== 'rss' || ! isset($document->channel)) {
            throw new RuntimeException($this->sourceLabel.' returned an invalid RSS document.');
        }

        if (! isset($document->channel->item)) {
            return [];
        }

        $entries = [];

        foreach ($document->channel->item as $item) {
            $title = trim((string) $item->title);

            if ($title === '' || str_contains($title, ' ')) {
                continue;
            }

            $entries[] = new PredbFeedEntry(
                title: $title,
                source: $this->sourceLabel,
                predate: $this->parseDate((string) $item->pubDate),
            );

            if (count($entries) >= $this->pageSize) {
                break;
            }
        }

        return $entries;
    }

    private function parseDate(string $value): ?CarbonImmutable
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
