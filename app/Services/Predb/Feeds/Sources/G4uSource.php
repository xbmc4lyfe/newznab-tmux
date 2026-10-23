<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Support\RustPipelineLibrary;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;
use FFI;
use RuntimeException;

/**
 * Latest-only public game catalogue. Names come from literal card fields, never editorial titles.
 * The today date groups updates; it supplies neither a publication timestamp nor a PRE time.
 */
final class G4uSource extends BoundedHttpFeedSource
{
    private const NATIVE_CDEF = <<<'C'
        int32_t nntmux_predb_html_validate_numeric_refs(const char *html, size_t len, uint32_t mode);
        C;

    private static FFI|false|null $native = null;

    public function key(): string
    {
        return 'g4u';
    }

    /** @return list<PredbFeedEntry> */
    public function fetch(int $page = 1): array
    {
        if ($page > 1) {
            return [];
        }

        return $this->parseHtml($this->downloadBody());
    }

    /** @return list<PredbFeedEntry> */
    public function parseHtml(string $html): array
    {
        if (strlen($html) > $this->maximumBodyBytes) {
            throw new RuntimeException('G4U HTML exceeds the body limit.');
        }
        if ($html === '' || preg_match('//u', $html) !== 1 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $html) === 1) {
            throw new RuntimeException('G4U returned invalid HTML.');
        }
        $this->validateNumericReferences($html);

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $loaded) {
            throw new RuntimeException('G4U returned invalid HTML.');
        }

        $xpath = new DOMXPath($document);
        $header = $this->todayHeader($xpath);
        $this->singleElement($xpath, 'parent::div['.$this->classToken('w3-content').' and '.$this->classToken('w3-center').' and '.$this->classToken('w3-light-grey').']', $header);
        $countNode = $this->singleElement($xpath, './span['.$this->classToken('w3-right').']', $header);
        $declaredCount = trim($countNode->textContent);
        if ($countNode->getElementsByTagName('*')->length !== 0 || ! ctype_digit($declaredCount)) {
            throw new RuntimeException('G4U returned an invalid today card count.');
        }

        $entries = [];
        $cardCount = 0;
        for ($sibling = $header->nextSibling; $sibling !== null; $sibling = $sibling->nextSibling) {
            if (! $sibling instanceof DOMElement) {
                if ($sibling instanceof DOMText && trim($sibling->textContent) !== '') {
                    throw new RuntimeException('G4U returned unrecognized today section content.');
                }

                continue;
            }

            $headings = $xpath->query('.//h1|.//h2', $sibling);
            if ($headings === false) {
                throw new RuntimeException('G4U returned an invalid section boundary.');
            }
            if (in_array($sibling->tagName, ['h1', 'h2'], true) || $headings->length > 0) {
                if (! in_array($sibling->tagName, ['div', 'h1', 'h2'], true) || $headings->length > 1) {
                    throw new RuntimeException('G4U returned an ambiguous section boundary.');
                }

                break;
            }

            $title = $this->cardTitle($xpath, $sibling);
            $cardCount++;
            if (count($entries) < $this->pageSize) {
                $entries[] = new PredbFeedEntry(title: $title, source: 'g4u.to');
            }
        }

        if ($cardCount !== (int) $declaredCount) {
            throw new RuntimeException('G4U today card count does not match its listing.');
        }

        return $entries;
    }

    private function todayHeader(DOMXPath $xpath): DOMElement
    {
        $headings = $xpath->query('//h1');
        if ($headings === false) {
            throw new RuntimeException('G4U returned no recognized today heading.');
        }
        $matches = [];
        foreach ($headings as $heading) {
            if ($heading instanceof DOMElement && str_starts_with(trim($heading->textContent), 'Updates from today,')) {
                $matches[] = $heading;
            }
        }
        if (count($matches) !== 1) {
            throw new RuntimeException('G4U returned no unique today heading.');
        }
        $heading = $matches[0];
        if ($heading->getElementsByTagName('*')->length !== 0 || preg_match('/^Updates from today, (\d{4})-(\d{2})-(\d{2})$/D', trim($heading->textContent), $date) !== 1 || ! checkdate((int) $date[2], (int) $date[3], (int) $date[1])) {
            throw new RuntimeException('G4U returned an invalid today heading.');
        }

        return $this->singleElement($xpath, 'parent::div', $heading);
    }

    private function cardTitle(DOMXPath $xpath, DOMElement $link): string
    {
        if ($link->tagName !== 'a' || preg_match('~^/(?:en|de)/[0-9]+-[^/?#]+$~D', $link->getAttribute('href')) !== 1 || $link->getElementsByTagName('ul')->length !== 1) {
            throw new RuntimeException('G4U returned an unrecognized game card.');
        }
        $card = $this->singleElement($xpath, './ul['.$this->classToken('mgame').']', $link);
        $titleContainer = $this->singleElement($xpath, './li['.$this->classToken('mtitel').']', $card);
        $this->singleElement($xpath, './/li['.$this->classToken('mtitel').']', $card);
        $literal = $this->singleElement($xpath, './div['.$this->classToken('w3-opacity-min').']', $titleContainer);
        if ($literal->getElementsByTagName('*')->length !== 0) {
            throw new RuntimeException('G4U returned nested release-name markup.');
        }

        $title = $literal->textContent;
        if ($title === '' || mb_strlen($title, 'UTF-8') > 255 || preg_match('/[\s\p{Z}\p{C}]/u', $title) !== 0) {
            throw new RuntimeException('G4U returned a nonliteral release name.');
        }

        return $title;
    }

    /**
     * libxml silently discards invalid numeric references, which would change a literal name.
     */
    private function validateNumericReferences(string $html): void
    {
        // Reference-free pages do not amortize an FFI call. Keep this common
        // case in PHP's native string search rather than regressing its latency.
        if (! str_contains($html, '&#')) {
            return;
        }
        $ffi = self::$native ??= RustPipelineLibrary::resolveDomain('predb_sql_html', self::NATIVE_CDEF) ?? false;
        if ($ffi !== false) {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_predb_html_validate_numeric_refs($html, strlen($html), 0);
            if ($status === 1) {
                return;
            }
            if ($status === 0) {
                throw new RuntimeException('G4U returned an invalid character reference.');
            }
        }

        $this->validateNumericReferencesPhp($html);
    }

    private function validateNumericReferencesPhp(string $html): void
    {
        $offset = 0;
        while (preg_match('/&#(?:(\d+)|x([0-9a-f]+));?/i', $html, $reference, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $decimal = $reference[1][0];
            $digits = ltrim($decimal !== '' ? $decimal : $reference[2][0], '0');
            $base = $decimal !== '' ? 10 : 16;
            if (strlen($digits) > ($base === 10 ? 7 : 6)) {
                throw new RuntimeException('G4U returned an invalid character reference.');
            }
            $codepoint = intval($digits, $base);
            if (($codepoint < 0x20 && ! in_array($codepoint, [9, 10, 13], true)) || $codepoint > 0x10FFFF || ($codepoint >= 0xD800 && $codepoint <= 0xDFFF)) {
                throw new RuntimeException('G4U returned an invalid character reference.');
            }
            $offset = $reference[0][1] + strlen($reference[0][0]);
        }
    }

    private function singleElement(DOMXPath $xpath, string $expression, DOMNode $context): DOMElement
    {
        $nodes = $xpath->query($expression, $context);
        if ($nodes === false || $nodes->length !== 1 || ! $nodes->item(0) instanceof DOMElement) {
            throw new RuntimeException('G4U returned a missing or ambiguous listing field.');
        }

        return $nodes->item(0);
    }

    private function classToken(string $class): string
    {
        return 'contains(concat(" ", normalize-space(@class), " "), " '.$class.' ")';
    }
}
