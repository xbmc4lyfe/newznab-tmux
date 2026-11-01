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
 *
 * Opt-in feeds that publish human-readable titles ("Show S01E01 720p WEB h264-GROUP", scnsrc.me, EZTV) set
 * $spacedTitles: whitespace becomes dots and a trailing " EZTV" tag is dropped. That is lossy (the original
 * dots, underscores and casing are unrecoverable), so those sources must stay opt-in. $mapCategory copies the
 * first RSS <category> element into the entry category. Catalogues can set $publicationDatesOnly
 * so listing timestamps are never treated as PRE times.
 */
final class RssFeedSource extends BoundedHttpFeedSource
{
    private const MAXIMUM_ITEMS = 5000;

    public function __construct(
        private readonly string $sourceKey,
        private readonly string $sourceLabel,
        string $endpoint,
        int $pageSize = 100,
        int $timeout = 15,
        string $userAgent = 'NNTmux-PreDB-Importer/1.0',
        private readonly bool $spacedTitles = false,
        private readonly bool $mapCategory = false,
        private readonly bool $publicationDatesOnly = false,
        private readonly bool $stripDisplaySizeSuffix = false,
        ?int $maximumBodyBytes = null,
    ) {
        parent::__construct(
            $endpoint,
            $pageSize,
            $timeout,
            $userAgent,
            $maximumBodyBytes ?? 1048576,
        );
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

        return $this->parseXml($this->downloadBody());
    }

    /**
     * @return list<PredbFeedEntry>
     *
     * @throws RuntimeException When the body is not an RSS 2.0 document (e.g. an HTML error page).
     */
    public function parseXml(string $xml): array
    {
        if (strlen($xml) > $this->maximumBodyBytes) {
            throw $this->bodyLimitException();
        }
        if (preg_match('/<!(?:DOCTYPE|ENTITY)\b/i', $xml) === 1) {
            throw new RuntimeException($this->sourceLabel.' returned an invalid RSS document.');
        }
        $xml = preg_replace('/\A[ \t\r\n]+(?=<\?xml[ \t\r\n])/', '', $xml) ?? $xml;
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

        if (count($document->channel->item) > self::MAXIMUM_ITEMS) {
            throw new RuntimeException($this->sourceLabel.' RSS document exceeds the item limit.');
        }

        $entries = [];

        foreach ($document->channel->item as $item) {
            $title = trim((string) $item->title);

            if ($this->stripDisplaySizeSuffix) {
                $title = preg_replace('/ \x{2013} [0-9]+(?:\.[0-9]+)? (?:MB|GB)$/u', '', $title) ?? $title;
            }

            if ($this->spacedTitles) {
                $title = $this->dotted($title);
            }

            if ($title === '' || str_contains($title, ' ')) {
                continue;
            }

            $category = $this->mapCategory ? trim((string) $item->category) : '';
            $publishedAt = $this->parseDate((string) $item->pubDate);

            $entries[] = new PredbFeedEntry(
                title: $title,
                source: $this->sourceLabel,
                category: $category !== '' ? $category : null,
                predate: $this->publicationDatesOnly ? null : $publishedAt,
                listedAt: $this->publicationDatesOnly ? $publishedAt : null,
            );

            if (count($entries) >= $this->pageSize) {
                break;
            }
        }

        return $entries;
    }

    private function dotted(string $title): string
    {
        $title = (string) preg_replace('/\s+EZTV$/i', '', trim($title));

        return (string) preg_replace('/\s+/', '.', $title);
    }

    private function parseDate(string $value): ?CarbonImmutable
    {
        if (trim($value) === '') {
            return null;
        }

        if ($this->publicationDatesOnly && ! isset(date_parse($value)['zone_type'])) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
