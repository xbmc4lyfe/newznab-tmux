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
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use RuntimeException;

/**
 * Latest printed day of the public music directory catalogue, using anchor text only.
 * Its unzoned day groups listings and supplies neither publication nor PRE timestamps.
 */
final class GabberSource extends BoundedHttpFeedSource
{
    private const NATIVE_CDEF = <<<'C'
        int32_t nntmux_predb_html_validate_numeric_refs(const char *html, size_t len, uint32_t mode);
        C;

    private static FFI|false|null $native = null;

    private const array GENRE_LISTS = [
        'Hardcore' => '0day-hardcore',
        'Hardstyle' => '0day-hardstyle',
        'Happy Hardcore' => '0day-happy-hardcore',
        'Drum And Bass' => '0day-drum-and-bass',
    ];

    public function key(): string
    {
        return 'gabber';
    }

    /** @return list<PredbFeedEntry> */
    public function fetch(int $page = 1): array
    {
        if ($page > 1) {
            return [];
        }

        try {
            $html = $this->downloadBody();
        } catch (RequestException $exception) {
            // Preserve the source's crawl delay: the command must not retry a 429 either.
            throw new RuntimeException('Gabber returned HTTP status '.$exception->response->status().'.', previous: $exception);
        }

        return $this->parseHtml($html);
    }

    protected function http(): PendingRequest
    {
        return parent::http()->withoutRedirecting();
    }

    protected function requestAttempts(): int
    {
        return 1;
    }

    /** @return list<PredbFeedEntry> */
    public function parseHtml(string $html): array
    {
        if (strlen($html) > $this->maximumBodyBytes) {
            throw new RuntimeException('Gabber HTML exceeds the body limit.');
        }
        if ($html === '' || preg_match('//u', $html) !== 1 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $html) === 1) {
            throw new RuntimeException('Gabber returned invalid HTML.');
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
            throw new RuntimeException('Gabber returned invalid HTML.');
        }

        $xpath = new DOMXPath($document);
        $view = $this->singleElement($xpath, '//div['.$this->classToken('view-id-0day_releases').']', $document);
        $content = $this->singleElement($xpath, './div['.$this->classToken('view-content').']', $view);
        $header = $this->singleElement($xpath, './h3[1]', $content);
        for ($before = $content->firstChild; $before !== null && $before !== $header; $before = $before->nextSibling) {
            if ($before instanceof DOMElement || ($before instanceof DOMText && trim($before->textContent) !== '')) {
                throw new RuntimeException('Gabber returned content before the latest day.');
            }
        }
        $link = $this->singleElement($xpath, './a', $header);
        $date = $this->singleElement($xpath, './span['.$this->classToken('date-display-single').']', $link);
        $this->onlyChildren($header, [$link]);
        $this->onlyChildren($link, [$date]);
        if ($date->getElementsByTagName('*')->length !== 0 || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date->textContent, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new RuntimeException('Gabber returned an invalid printed day.');
        }

        $titles = [];
        $seen = [];
        $genres = [];
        for ($sibling = $header->nextSibling; $sibling !== null; $sibling = $sibling->nextSibling) {
            if (! $sibling instanceof DOMElement) {
                if ($sibling instanceof DOMText && trim($sibling->textContent) !== '') {
                    throw new RuntimeException('Gabber returned unrecognized latest-day content.');
                }

                continue;
            }
            if ($sibling->tagName === 'h3') {
                break;
            }
            if ($sibling->tagName !== 'div' || ! $this->hasClass($sibling, 'views-row')) {
                throw new RuntimeException('Gabber returned an unrecognized latest-day row.');
            }

            [$genre, $names] = $this->rowNames($xpath, $sibling);
            if (isset($genres[$genre])) {
                throw new RuntimeException('Gabber returned duplicate latest-day genre rows.');
            }
            $genres[$genre] = true;
            foreach ($names as $name) {
                $identity = mb_strtolower($name, 'UTF-8');
                if (! isset($seen[$identity])) {
                    $seen[$identity] = true;
                    $titles[] = $name;
                }
            }
        }
        if ($titles === []) {
            throw new RuntimeException('Gabber returned no latest-day directory names.');
        }

        return array_map(
            static fn (string $title): PredbFeedEntry => new PredbFeedEntry(title: $title, source: 'gabber.od.ua'),
            array_slice($titles, 0, $this->pageSize),
        );
    }

    /** @return array{string, list<string>} */
    private function rowNames(DOMXPath $xpath, DOMElement $row): array
    {
        $genreField = $this->singleElement($xpath, './div['.$this->classToken('views-field-field-genre').']', $row);
        $bodyField = $this->singleElement($xpath, './div['.$this->classToken('views-field-body').']', $row);
        $this->onlyChildren($row, [$genreField, $bodyField]);
        $genreContent = $this->singleElement($xpath, './div['.$this->classToken('field-content').']', $genreField);
        $this->onlyChildren($genreField, [$genreContent]);
        $genre = $genreContent->textContent;
        if ($genreContent->getElementsByTagName('*')->length !== 0 || ! isset(self::GENRE_LISTS[$genre])) {
            throw new RuntimeException('Gabber returned an unrecognized directory genre.');
        }
        $body = $this->singleElement($xpath, './div['.$this->classToken('field-content').']', $bodyField);
        $this->onlyChildren($bodyField, [$body]);
        $list = $this->singleElement($xpath, './ul['.$this->classToken(self::GENRE_LISTS[$genre]).']', $body);
        $this->onlyChildren($body, [$list]);
        $items = $xpath->query('./li', $list);
        if ($items === false || $items->length === 0) {
            throw new RuntimeException('Gabber returned an empty directory list.');
        }
        $this->onlyChildren($list, iterator_to_array($items));

        $names = [];
        foreach ($items as $item) {
            if (! $item instanceof DOMElement) {
                throw new RuntimeException('Gabber returned an invalid directory item.');
            }
            $literal = $this->singleElement($xpath, './a', $item);
            $this->onlyChildren($item, [$literal]);
            $name = $literal->textContent;
            if ($literal->getElementsByTagName('*')->length !== 0 || $name === '' || mb_strlen($name, 'UTF-8') > 255 || preg_match('/[\s\p{Z}\p{C}]/u', $name) !== 0) {
                throw new RuntimeException('Gabber returned a nonliteral directory name.');
            }
            $names[] = $name;
        }

        return [$genre, $names];
    }

    /** @param array<DOMNode> $expected */
    private function onlyChildren(DOMElement $parent, array $expected): void
    {
        $actual = [];
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $actual[] = $child;
            } elseif ($child instanceof DOMText && trim($child->textContent) !== '') {
                throw new RuntimeException('Gabber returned mixed listing content.');
            }
        }
        if ($actual !== array_values($expected)) {
            throw new RuntimeException('Gabber returned unexpected listing children.');
        }
    }

    private function singleElement(DOMXPath $xpath, string $expression, DOMNode $context): DOMElement
    {
        $nodes = $xpath->query($expression, $context);
        if ($nodes === false || $nodes->length !== 1 || ! $nodes->item(0) instanceof DOMElement) {
            throw new RuntimeException('Gabber returned a missing or ambiguous listing field.');
        }

        return $nodes->item(0);
    }

    private function classToken(string $class): string
    {
        return 'contains(concat(" ", normalize-space(@class), " "), " '.$class.' ")';
    }

    private function hasClass(DOMElement $element, string $class): bool
    {
        return in_array($class, preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [], true);
    }

    /** Reject references libxml could discard, which would fabricate a different literal identity. */
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
                throw new RuntimeException('Gabber returned an invalid character reference.');
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
                throw new RuntimeException('Gabber returned an invalid character reference.');
            }
            $codepoint = intval($digits, $base);
            if (($codepoint < 0x20 && ! in_array($codepoint, [9, 10, 13], true)) || $codepoint > 0x10FFFF || ($codepoint >= 0xD800 && $codepoint <= 0xDFFF)) {
                throw new RuntimeException('Gabber returned an invalid character reference.');
            }
            $offset = $reference[0][1] + strlen($reference[0][0]);
        }
    }
}
