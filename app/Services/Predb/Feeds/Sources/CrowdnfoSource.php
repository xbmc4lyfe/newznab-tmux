<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Services\Predb\Feeds\PredbFeedEntry;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * Opt-in, latest-only crowdNFO canonical catalogue headings, including contributed names.
 * File trust scores do not verify names; creation timestamps are not PRE times.
 * No aliases, payload metadata, or original spelling are inferred from the listing.
 */
final class CrowdnfoSource extends BoundedHttpFeedSource
{
    /** libxml2 XML_ERR_ATTRIBUTE_REDEFINED. */
    private const ATTRIBUTE_REDEFINED = 42;

    public function key(): string
    {
        return 'crowdnfo';
    }

    /** @return list<PredbFeedEntry> */
    public function fetch(int $page = 1): array
    {
        if ($page < 1) {
            throw new RuntimeException('CROWDNFO pages start at one.');
        }
        if ($page > 1) {
            return [];
        }

        return $this->parseHtml($this->downloadBody());
    }

    /** @return list<PredbFeedEntry> */
    public function parseHtml(string $html): array
    {
        if (strlen($html) > $this->maximumBodyBytes) {
            throw $this->bodyLimitException();
        }
        if ($html === '' || ! mb_check_encoding($html, 'UTF-8')
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $html) === 1
            || preg_match('/[\x{FFFE}\x{FFFF}]/u', $html) === 1) {
            throw new RuntimeException('CROWDNFO returned invalid HTML.');
        }
        $this->validateNumericReferences($html);
        $this->validateClosedListingStructure($html);

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
            foreach (libxml_get_errors() as $error) {
                if ($error->code === self::ATTRIBUTE_REDEFINED) {
                    throw new RuntimeException('CROWDNFO returned duplicate HTML attributes.');
                }
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $loaded) {
            throw new RuntimeException('CROWDNFO returned invalid HTML.');
        }
        $xpath = new DOMXPath($document);
        $headings = $xpath->query('//h2[normalize-space(.)="Recent Releases"]');
        if ($headings === false || $headings->length !== 1) {
            throw new RuntimeException('CROWDNFO returned an ambiguous recent release scope.');
        }
        $scopes = $xpath->query('following-sibling::*[1][self::div['.$this->classToken('row').']]', $headings->item(0));
        if ($scopes === false || $scopes->length !== 1) {
            throw new RuntimeException('CROWDNFO returned no recognized recent release listing.');
        }
        $scope = $scopes->item(0);
        $wrappers = $xpath->query('./*', $scope);
        $allTitles = $xpath->query('.//*['.$this->classToken('card-title').']', $scope);
        $allLinks = $xpath->query('.//a[starts-with(@href,"/release/")]', $scope);
        if ($wrappers === false || $wrappers->length === 0 || $allTitles === false || $allLinks === false
            || $allTitles->length !== $wrappers->length || $allLinks->length !== $wrappers->length) {
            throw new RuntimeException('CROWDNFO returned incomplete or ambiguous release cards.');
        }
        $entries = [];
        $identities = [];
        $titles = [];
        foreach ($wrappers as $wrapper) {
            $cards = $xpath->query('./div['.$this->classToken('card').']', $wrapper);
            $links = $xpath->query('./div['.$this->classToken('card').']/div['.$this->classToken('card-body').']/h5['.$this->classToken('card-title').']/a', $wrapper);
            if ($cards === false || $cards->length !== 1 || $links === false || $links->length !== 1) {
                throw new RuntimeException('CROWDNFO returned an ambiguous release card heading.');
            }
            $link = $links->item(0);
            if (! $link instanceof DOMElement || $link->getElementsByTagName('*')->length !== 0
                || preg_match('~^/release/([1-9][0-9]*)$~D', $link->getAttribute('href'), $match) !== 1) {
                throw new RuntimeException('CROWDNFO returned an invalid release detail link.');
            }
            $title = trim($link->textContent);
            $heading = $link->parentNode;
            if (! $heading instanceof DOMElement || $heading->childElementCount !== 1 || trim($heading->textContent) !== $title) {
                throw new RuntimeException('CROWDNFO returned additional heading content.');
            }
            if ($title === '' || mb_strlen($title, 'UTF-8') > 255
                || preg_match('/[\s\p{Z}\p{C}]/u', $title) !== 0
                || str_contains($title, '...') || str_contains($title, '…')
                || preg_match('/^[^.]+\.[^.]+(?:\.[^.]+)*-[\p{L}\p{N}_]+$/uD', $title) !== 1
                || isset($identities[$match[1]]) || isset($titles[$title])) {
                throw new RuntimeException('CROWDNFO returned a malformed or duplicate literal heading.');
            }
            $identities[$match[1]] = true;
            $titles[$title] = true;
            if (count($entries) < $this->pageSize) {
                $entries[] = new PredbFeedEntry(title: $title, source: 'crowdnfo.net');
            }
        }

        return $entries;
    }

    /** Reject references libxml could discard before literal title validation. */
    private function validateNumericReferences(string $html): void
    {
        $offset = 0;
        while (preg_match('/&#(?:(\d+)|x([0-9a-f]+));?/i', $html, $reference, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $decimal = $reference[1][0];
            $digits = ltrim($decimal !== '' ? $decimal : $reference[2][0], '0');
            $base = $decimal !== '' ? 10 : 16;
            if (strlen($digits) > ($base === 10 ? 7 : 6)) {
                throw new RuntimeException('CROWDNFO returned an invalid character reference.');
            }
            $codepoint = intval($digits, $base);
            if (($codepoint < 0x20 && ! in_array($codepoint, [9, 10, 13], true))
                || $codepoint > 0x10FFFF || in_array($codepoint, [0xFFFE, 0xFFFF], true)
                || ($codepoint >= 0xD800 && $codepoint <= 0xDFFF)) {
                throw new RuntimeException('CROWDNFO returned an invalid character reference.');
            }
            $offset = $reference[0][1] + strlen($reference[0][0]);
        }
    }

    /**
     * The publisher closes its document, rows, cards and heading links explicitly.
     * Require those boundaries before libxml can close a truncated listing for us.
     */
    private function validateClosedListingStructure(string $html): void
    {
        $markup = preg_replace('~<!--.*?(?:-->|\z)|<(script|style)\b[^>]*>.*?(?:</\1\s*>|\z)~is', '', $html);
        if ($markup === null || preg_match('~</html\s*>\s*\z~i', $markup) !== 1
            || preg_match_all('~<(/?)(html|body|div|h2|h5|a)\b(?:"[^"]*"|\'[^\']*\'|[^\'">])*>~is', $markup, $tags, PREG_SET_ORDER) === false) {
            throw new RuntimeException('CROWDNFO returned an incomplete listing document.');
        }

        $stack = [];
        $documents = $bodies = 0;
        foreach ($tags as $tag) {
            $name = strtolower($tag[2]);
            if ($tag[1] === '') {
                $documents += $name === 'html' ? 1 : 0;
                $bodies += $name === 'body' ? 1 : 0;
                $stack[] = $name;
            } elseif (array_pop($stack) !== $name) {
                throw new RuntimeException('CROWDNFO returned an incomplete listing scope.');
            }
        }
        if ($stack !== [] || $documents !== 1 || $bodies !== 1) {
            throw new RuntimeException('CROWDNFO returned an incomplete listing scope.');
        }
    }

    private function classToken(string $class): string
    {
        return 'contains(concat(" ", normalize-space(@class), " "), " '.$class.' ")';
    }
}
