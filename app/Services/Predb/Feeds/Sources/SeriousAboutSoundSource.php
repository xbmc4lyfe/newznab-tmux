<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Services\Predb\Feeds\PredbFeedEntry;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * Latest public music catalogue headings. Forum timestamps and track counts are not PRE metadata.
 * The numeric topic link and publisher author are structural evidence, not verified original PREs.
 */
final class SeriousAboutSoundSource extends BoundedHttpFeedSource
{
    private const ATTRIBUTE_REDEFINED = 42;

    public function key(): string
    {
        return 'seriousaboutsound';
    }

    /** @return list<PredbFeedEntry> */
    public function fetch(int $page = 1): array
    {
        if ($page < 1) {
            throw new RuntimeException('SERIOUSABOUTSOUND pages start at one.');
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
            throw new RuntimeException('SERIOUSABOUTSOUND returned invalid HTML.');
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
                    throw new RuntimeException('SERIOUSABOUTSOUND returned duplicate HTML attributes.');
                }
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $loaded) {
            throw new RuntimeException('SERIOUSABOUTSOUND returned invalid HTML.');
        }
        $xpath = new DOMXPath($document);
        $scopes = $xpath->query('//ol[@data-role="tableRows" and '.$this->classToken('cForumTopicTable').']');
        if ($scopes === false || $scopes->length !== 1) {
            throw new RuntimeException('SERIOUSABOUTSOUND returned an ambiguous topic scope.');
        }
        $scope = $scopes->item(0);
        $rows = $xpath->query('./*', $scope);
        $allRows = $xpath->query('.//*[@data-rowid]', $scope);
        if ($rows === false || $rows->length === 0 || $allRows === false || $rows->length !== $allRows->length) {
            throw new RuntimeException('SERIOUSABOUTSOUND returned an incomplete topic listing.');
        }
        $entries = [];
        $ids = [];
        $titles = [];
        foreach ($rows as $row) {
            if (! $row instanceof DOMElement || $row->tagName !== 'li'
                || preg_match('/^[1-9][0-9]*$/D', $row->getAttribute('data-rowid')) !== 1) {
                throw new RuntimeException('SERIOUSABOUTSOUND returned an invalid topic row.');
            }
            $id = $row->getAttribute('data-rowid');
            if (isset($ids[$id])) {
                throw new RuntimeException('SERIOUSABOUTSOUND returned duplicate topic identities.');
            }
            $ids[$id] = true;
            $pinned = $xpath->query('.//*[@title="Pinned" and '.$this->classToken('ipsBadge').']', $row);
            if ($pinned !== false && $pinned->length > 0) {
                $discussion = $xpath->query('.//a[@href="https://seriousaboutsound.net/topic/1374-renegades-corner/"]', $row);
                if ($id !== '1374' || $pinned->length !== 1 || $discussion === false || $discussion->length !== 1) {
                    throw new RuntimeException('SERIOUSABOUTSOUND returned an unknown pinned topic.');
                }

                continue;
            }
            $headings = $xpath->query('./div['.$this->classToken('ipsDataItem_main').']/h4['.$this->classToken('ipsDataItem_title').']', $row);
            if ($headings === false || $headings->length !== 1) {
                throw new RuntimeException('SERIOUSABOUTSOUND returned an ambiguous topic heading.');
            }
            $heading = $headings->item(0);
            if (! $heading instanceof DOMElement || $heading->childElementCount !== 1) {
                throw new RuntimeException('SERIOUSABOUTSOUND returned additional heading structure.');
            }
            $canonicalLinks = $xpath->query('./span['.$this->classToken('ipsType_break').']/a', $heading);
            if ($canonicalLinks === false || $canonicalLinks->length !== 1) {
                throw new RuntimeException('SERIOUSABOUTSOUND returned no canonical heading link.');
            }
            $links = $xpath->query('.//a', $heading);
            $authors = $xpath->query('./div['.$this->classToken('ipsDataItem_main').']/div['.$this->classToken('ipsDataItem_meta').']//a[contains(@href,"/profile/")]', $row);
            if ($links === false || $links->length !== 1 || $authors === false || $authors->length !== 1) {
                throw new RuntimeException('SERIOUSABOUTSOUND returned ambiguous topic links or author evidence.');
            }
            $link = $links->item(0);
            $author = $authors->item(0);
            if (! $link instanceof DOMElement || ! $author instanceof DOMElement
                || $author->getAttribute('href') !== 'https://seriousaboutsound.net/profile/1294-rls-bot/'
                || trim($author->textContent) !== 'RLS Bot'
                || preg_match('~^https://seriousaboutsound\.net/topic/([1-9][0-9]*)-[a-z0-9_-]+/$~D', $link->getAttribute('href'), $match) !== 1
                || $match[1] !== $id) {
                throw new RuntimeException('SERIOUSABOUTSOUND returned an invalid topic association or publisher.');
            }
            $spans = $xpath->query('./span', $link);
            if ($spans === false || $spans->length !== 1 || $link->childElementCount !== 1
                || ! $spans->item(0) instanceof DOMElement
                || $spans->item(0)->childElementCount !== 0
                || trim($spans->item(0)->textContent) !== trim($link->textContent)) {
                throw new RuntimeException('SERIOUSABOUTSOUND returned editorial heading markup.');
            }
            $title = trim($link->textContent);
            if (trim($heading->textContent) !== $title || mb_strlen($title, 'UTF-8') > 255
                || preg_match('/[\s\p{Z}\p{C}]/u', $title) !== 0
                || str_contains($title, '...') || str_contains($title, '…')
                || preg_match("/^[\p{L}\p{N}_][\p{L}\p{M}\p{N}_.()&'+,-]*-[\p{L}\p{N}_]+$/uD", $title) !== 1
                || ! preg_match('/[._]/', $title) || isset($titles[mb_strtolower($title, 'UTF-8')])) {
                throw new RuntimeException('SERIOUSABOUTSOUND returned a malformed or duplicate literal heading.');
            }
            $titles[mb_strtolower($title, 'UTF-8')] = true;
            if (count($entries) < $this->pageSize) {
                $entries[] = new PredbFeedEntry(title: $title, source: 'seriousaboutsound.net');
            }
        }
        if ($entries === []) {
            throw new RuntimeException('SERIOUSABOUTSOUND returned no literal release headings.');
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
                throw new RuntimeException('SERIOUSABOUTSOUND returned an invalid character reference.');
            }
            $codepoint = intval($digits, $base);
            if (($codepoint < 0x20 && ! in_array($codepoint, [9, 10, 13], true))
                || $codepoint > 0x10FFFF || in_array($codepoint, [0xFFFE, 0xFFFF], true)
                || ($codepoint >= 0xD800 && $codepoint <= 0xDFFF)) {
                throw new RuntimeException('SERIOUSABOUTSOUND returned an invalid character reference.');
            }
            $offset = $reference[0][1] + strlen($reference[0][0]);
        }
    }

    /**
     * The publisher closes its document, topic rows and heading links explicitly.
     * Require those boundaries before libxml can close a truncated listing for us.
     */
    private function validateClosedListingStructure(string $html): void
    {
        $markup = preg_replace('~<!--.*?(?:-->|\z)|<(script|style)\b[^>]*>.*?(?:</\1\s*>|\z)~is', '', $html);
        if ($markup === null || preg_match('~</html\s*>\s*\z~i', $markup) !== 1
            || preg_match_all('~<(/?)(html|body|ol|ul|li|div|h4|a|span)\b(?:"[^"]*"|\'[^\']*\'|[^\'">])*>~is', $markup, $tags, PREG_SET_ORDER) === false) {
            throw new RuntimeException('SERIOUSABOUTSOUND returned an incomplete listing document.');
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
                throw new RuntimeException('SERIOUSABOUTSOUND returned an incomplete listing scope.');
            }
        }
        if ($stack !== [] || $documents !== 1 || $bodies !== 1) {
            throw new RuntimeException('SERIOUSABOUTSOUND returned an incomplete listing scope.');
        }
    }

    private function classToken(string $class): string
    {
        return 'contains(concat(" ", normalize-space(@class), " "), " '.$class.' ")';
    }
}
