<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Services\Predb\Feeds\PredbFeedEntry;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * Opt-in, latest-only NIMA4K catalogue names. The 1 MiB default bounds HTML download and DOM parsing.
 * Listing dates have no timezone and delivery metadata is not PRE metadata, so both stay unknown.
 */
final class Nima4kSource extends BoundedHttpFeedSource
{
    public function __construct(
        string $endpoint,
        int $pageSize = 100,
        int $timeout = 15,
        string $userAgent = 'NNTmux-PreDB-Importer/1.0',
        int $maximumBodyBytes = 1048576,
    ) {
        if ($maximumBodyBytes < 1 || $maximumBodyBytes >= PHP_INT_MAX || $pageSize < 1) {
            throw new RuntimeException('NIMA4K body and record limits must be positive.');
        }
        parent::__construct($endpoint, $pageSize, $timeout, $userAgent, $maximumBodyBytes);
    }

    public function key(): string
    {
        return 'nima4k';
    }

    /** @return list<PredbFeedEntry> */
    public function fetch(int $page = 1): array
    {
        if ($page > 1) {
            return [];
        }

        return $this->parseHtml($this->downloadBody());
    }

    protected function bodyLimitException(): RuntimeException
    {
        return new RuntimeException('NIMA4K HTML exceeds the body limit.');
    }

    /**
     * Require one literal subtitle and one agreeing cover label per observed release card.
     *
     * @return list<PredbFeedEntry>
     */
    public function parseHtml(string $html): array
    {
        if (strlen($html) > $this->maximumBodyBytes) {
            throw new RuntimeException('NIMA4K HTML exceeds the body limit.');
        }
        if ($html === '' || preg_match('//u', $html) !== 1) {
            throw new RuntimeException('NIMA4K returned invalid HTML.');
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $loaded) {
            throw new RuntimeException('NIMA4K returned invalid HTML.');
        }

        $xpath = new DOMXPath($document);
        $articles = $xpath->query('//div['.$this->classToken('article').']');
        if ($articles === false || $articles->length === 0) {
            throw new RuntimeException('NIMA4K returned no recognized release cards.');
        }
        $entries = [];
        foreach ($articles as $article) {
            $subtitles = $xpath->query('.//span['.$this->classToken('subtitle').']', $article);
            $titles = $xpath->query('./div['.$this->classToken('article-right').']/h2/span['.$this->classToken('subtitle').']', $article);
            $covers = $xpath->query('.//div['.$this->classToken('cover-box').']//img[@alt]', $article);
            if ($subtitles === false || $titles === false || $covers === false || $subtitles->length !== 1 || $titles->length !== 1 || $covers->length !== 1) {
                throw new RuntimeException('NIMA4K returned an ambiguous release card.');
            }
            $subtitle = $titles->item(0);
            $cover = $covers->item(0);
            if (! $subtitle instanceof DOMElement || ! $cover instanceof DOMElement || $subtitle->getElementsByTagName('*')->length !== 0) {
                throw new RuntimeException('NIMA4K returned an invalid release subtitle.');
            }
            $title = trim($subtitle->textContent);
            if ($title === '' || preg_match('/[\s\p{Z}\p{C}]/u', $title) !== 0 || $cover->getAttribute('alt') !== $title) {
                throw new RuntimeException('NIMA4K returned a nonliteral or inconsistent release name.');
            }
            if (count($entries) < $this->pageSize) {
                $entries[] = new PredbFeedEntry(title: $title, source: 'nima4k.org');
            }
        }

        return $entries;
    }

    private function classToken(string $class): string
    {
        return 'contains(concat(" ", normalize-space(@class), " "), " '.$class.' ")';
    }
}
