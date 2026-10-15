<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Services\Predb\Feeds\PredbFeedEntry;
use Dom\Element as HtmlElement;
use Dom\HTMLDocument;
use Dom\Node as HtmlNode;
use Dom\XPath as HtmlXPath;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;

/**
 * Opt-in, latest-only NIMA4K catalogue names. The 1 MiB default bounds HTML download and DOM parsing.
 * Listing dates have no timezone and delivery metadata is not PRE metadata, so both stay unknown.
 */
final class Nima4kSource extends BoundedHttpFeedSource
{
    private const HTML_NAMESPACE = 'http://www.w3.org/1999/xhtml';

    private const FORBIDDEN_CHARACTERS = '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}\x{FDD0}-\x{FDEF}\x{FFFE}-\x{FFFF}\x{1FFFE}-\x{1FFFF}\x{2FFFE}-\x{2FFFF}\x{3FFFE}-\x{3FFFF}\x{4FFFE}-\x{4FFFF}\x{5FFFE}-\x{5FFFF}\x{6FFFE}-\x{6FFFF}\x{7FFFE}-\x{7FFFF}\x{8FFFE}-\x{8FFFF}\x{9FFFE}-\x{9FFFF}\x{AFFFE}-\x{AFFFF}\x{BFFFE}-\x{BFFFF}\x{CFFFE}-\x{CFFFF}\x{DFFFE}-\x{DFFFF}\x{EFFFE}-\x{EFFFF}\x{FFFFE}-\x{FFFFF}\x{10FFFE}-\x{10FFFF}]/u';

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
        // libxml can discard malformed characters before the literal labels are compared.
        if (preg_match(self::FORBIDDEN_CHARACTERS, $html) !== 0) {
            throw new RuntimeException('NIMA4K returned invalid HTML.');
        }
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
            $errors = libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $loaded) {
            throw new RuntimeException('NIMA4K returned invalid HTML.');
        }

        // This parser reports discarded controls, surrogates and overflowing references as code 9.
        $unexpectedClosingTag = null;
        $invalidNumericReference = null;
        foreach ($errors as $error) {
            // These malformed-markup recoveries discard bytes or values; fatal errors truncate input.
            // Codes 45/47 truncate comments/PIs; 802 discards bogus declarations on newer libxml.
            $discardedName = $error->code === 68 && in_array(trim($error->message), [
                'htmlParseStartTag: invalid element name',
                'error parsing attribute name',
            ], true);
            if ($error->level === LIBXML_ERR_FATAL || in_array($error->code, [42, 45, 47, 61, 73, 800, 802], true) || $discardedName) {
                throw new RuntimeException('NIMA4K returned invalid HTML.');
            }
            if ($error->code === 76 && ($unexpectedClosingTag ??= $this->hasMaskedDiagnostic($html, $document, 76))) {
                throw new RuntimeException('NIMA4K returned invalid HTML.');
            }
            if ($error->code === 9 && ($invalidNumericReference ??= $this->hasMaskedDiagnostic($html, $document, 9))) {
                throw new RuntimeException('NIMA4K returned an invalid numeric character reference.');
            }
        }

        $cards = $this->validatedReleaseCards($document);
        // The legacy parser can expose inert cards or leave HTML5 entities undecoded without errors.
        // Compare every observed card before limiting output; labels still come from the original DOM.
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $reference = HTMLDocument::createFromString($html, 0, 'UTF-8');
            $referenceErrors = libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $invalidControlReference = null;
        foreach ($referenceErrors as $error) {
            // HTML5 can replace invalid references before decoded-value validation, including in SVG.
            if (preg_match('/\Atokenizer error (?:null-character-reference|character-reference-outside-unicode-range|surrogate-character-reference|noncharacter-character-reference)\b/', $error->message) === 1) {
                throw new RuntimeException('NIMA4K returned an invalid numeric character reference.');
            }
            if (str_starts_with($error->message, 'tokenizer error control-character-reference ')
                && ($invalidControlReference ??= $this->hasDisallowedControlReference($html))) {
                throw new RuntimeException('NIMA4K returned an invalid numeric character reference.');
            }
        }
        if ($cards !== $this->validatedReleaseCards($reference)) {
            throw new RuntimeException('NIMA4K returned inconsistent HTML release semantics.');
        }
        $entries = [];
        foreach (array_slice($cards, 0, $this->pageSize) as $card) {
            $entries[] = new PredbFeedEntry(title: $card['title'], source: 'nima4k.org');
        }

        return $entries;
    }

    private function hasDisallowedControlReference(string $html): bool
    {
        // HTML5 warns on allowed CR as well as forbidden controls. Keep reference syntax and let
        // the native tokenizer recheck contexts after changing only CR to LF in this diagnostic copy.
        $diagnosticHtml = preg_replace('/&#(?:0*13(?![0-9])|[xX]0*[dD](?![0-9a-fA-F]));?/', '&#10;', $html);
        if ($diagnosticHtml === null || $diagnosticHtml === $html) {
            return true;
        }
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            HTMLDocument::createFromString($diagnosticHtml, 0, 'UTF-8');
            $errors = libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        foreach ($errors as $error) {
            if (str_starts_with($error->message, 'tokenizer error control-character-reference ')) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{article: string, subtitle: string, cover: string, title: string}> */
    private function validatedReleaseCards(DOMDocument|HTMLDocument $document): array
    {
        if ($document instanceof HTMLDocument) {
            $xpath = new HtmlXPath($document);
            $xpath->registerNamespace('h', self::HTML_NAMESPACE);
            $prefix = 'h:';
        } else {
            $this->assertUtf8Encoding($document);
            $xpath = new DOMXPath($document);
            $prefix = '';
        }
        // Only actual HTML raw text is inert. Foreign text, RCDATA and all attributes stay decoded.
        $rawParents = implode(' or ', array_map(static fn (string $tag): string => 'parent::'.$prefix.$tag, ['script', 'style', 'iframe', 'xmp', 'noembed', 'noframes']));
        $decodedValues = $xpath->query('//text()[not('.$rawParents.')] | //@*');
        if ($decodedValues === false) {
            throw new RuntimeException('NIMA4K returned invalid HTML.');
        }
        foreach ($decodedValues as $value) {
            if (preg_match(self::FORBIDDEN_CHARACTERS, $value->nodeValue ?? '') !== 0) {
                throw new RuntimeException('NIMA4K returned invalid HTML.');
            }
        }
        $templateFilter = $document instanceof HTMLDocument ? '[not(ancestor::h:template)]' : '';
        $articles = $xpath->query('//'.$prefix.'div['.$this->classToken('article').']'.$templateFilter);
        if ($articles === false || $articles->length === 0) {
            throw new RuntimeException('NIMA4K returned no recognized release cards.');
        }
        $cards = [];
        foreach ($articles as $article) {
            $subtitles = $xpath->query('.//'.$prefix.'span['.$this->classToken('subtitle').']', $article);
            $titles = $xpath->query('./'.$prefix.'div['.$this->classToken('article-right').']/'.$prefix.'h2/'.$prefix.'span['.$this->classToken('subtitle').']', $article);
            $covers = $xpath->query('.//'.$prefix.'div['.$this->classToken('cover-box').']//'.$prefix.'img[@alt]', $article);
            if ($subtitles === false || $titles === false || $covers === false || $subtitles->length !== 1 || $titles->length !== 1 || $covers->length !== 1) {
                throw new RuntimeException('NIMA4K returned an ambiguous release card.');
            }
            $subtitle = $titles->item(0);
            $cover = $covers->item(0);
            if ((! $subtitle instanceof DOMElement && ! $subtitle instanceof HtmlElement) || (! $cover instanceof DOMElement && ! $cover instanceof HtmlElement)) {
                throw new RuntimeException('NIMA4K returned an invalid release subtitle.');
            }
            foreach ($subtitle->childNodes as $child) {
                if ($child->nodeType !== XML_TEXT_NODE) {
                    throw new RuntimeException('NIMA4K returned an invalid release subtitle.');
                }
            }
            $title = trim($subtitle->textContent);
            if ($title === '' || preg_match('/[\s\p{Z}\p{C}]/u', $title) !== 0 || $cover->getAttribute('alt') !== $title) {
                throw new RuntimeException('NIMA4K returned a nonliteral or inconsistent release name.');
            }
            $articlePath = $this->elementPath($article);
            $subtitlePath = $this->elementPath($subtitle);
            $coverPath = $this->elementPath($cover);
            $cards[] = ['article' => $articlePath, 'subtitle' => $subtitlePath, 'cover' => $coverPath, 'title' => $title];
        }

        return $cards;
    }

    private function elementPath(DOMNode|HtmlNode $node): string
    {
        // Modern getNodePath uses wildcard positions; use namespace/name sibling positions for both APIs.
        $parts = [];
        while ($node instanceof DOMElement || $node instanceof HtmlElement) {
            $namespace = $node->namespaceURI ?? self::HTML_NAMESPACE;
            $name = $node->localName;
            $position = 1;
            for ($sibling = $node->previousSibling; $sibling !== null; $sibling = $sibling->previousSibling) {
                if (($sibling instanceof DOMElement || $sibling instanceof HtmlElement)
                    && ($sibling->namespaceURI ?? self::HTML_NAMESPACE) === $namespace
                    && $sibling->localName === $name) {
                    $position++;
                }
            }
            $parts[] = '{'.$namespace.'}'.$name.'['.$position.']';
            $node = $node->parentNode;
        }

        return implode('/', array_reverse($parts));
    }

    private function maskTextContents(string $html, bool $includeRcdata): string
    {
        $parts = [];
        $copied = 0;
        $offset = 0;
        $length = strlen($html);
        while (($start = strpos($html, '<', $offset)) !== false) {
            if (substr($html, $start, 4) === '<!--') {
                $end = strpos($html, '-->', $start + 4);
                if ($end === false) {
                    throw new RuntimeException('NIMA4K returned invalid HTML.');
                }
                $offset = $end + 3;

                continue;
            }
            if (substr($html, $start, 2) === '<?') {
                // HTML processing instructions end at the first >, irrespective of quotes.
                $end = strpos($html, '>', $start + 2);
                if ($end === false) {
                    throw new RuntimeException('NIMA4K returned invalid HTML.');
                }
                $offset = $end + 1;

                continue;
            }
            if (preg_match('/\G<(?:[a-zA-Z!]|\/[a-zA-Z])/', $html, $unused, 0, $start) !== 1) {
                // A literal < must not consume a subsequent actual tag opener.
                $offset = $start + 1;

                continue;
            }
            $quote = null;
            for ($end = $start + 1; $end < $length; $end++) {
                $character = $html[$end];
                if ($quote !== null) {
                    if ($character === $quote) {
                        $quote = null;
                    }
                } elseif ($character === '"' || $character === "'") {
                    $quote = $character;
                } elseif ($character === '>') {
                    break;
                }
            }
            if ($end === $length) {
                break;
            }
            $offset = $end + 1;
            if (preg_match('/\A<(script|style|title|textarea|iframe|xmp|noembed|noframes)(?=[\x20\t\r\n\/>])/i', substr($html, $start, $offset - $start), $opening) !== 1) {
                continue;
            }
            if (preg_match('~</'.$opening[1].'(?=[\x20\t\r\n/>])~i', $html, $closing, PREG_OFFSET_CAPTURE, $offset) !== 1) {
                throw new RuntimeException('NIMA4K returned invalid HTML.');
            }
            // RCDATA must be skipped lexically in both modes, but numeric references there still decode.
            if ($includeRcdata || ! in_array(strtolower($opening[1]), ['title', 'textarea'], true)) {
                $parts[] = substr($html, $copied, $offset - $copied);
                $copied = $closing[0][1];
            }
            $offset = $closing[0][1];
        }
        $parts[] = substr($html, $copied);

        return implode('', $parts);
    }

    private function hasMaskedDiagnostic(string $html, DOMDocument $original, int $code): bool
    {
        // Recheck code 76 with raw text/RCDATA omitted, and code 9 with only raw text omitted.
        $masked = $this->maskTextContents($html, $code === 76);
        if ($masked === $html) {
            return true;
        }
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $document = new DOMDocument;
            $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$masked, LIBXML_NONET);
            $errors = libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $loaded) {
            return true;
        }
        foreach ($errors as $error) {
            if ($error->code === $code || $error->level === LIBXML_ERR_FATAL) {
                return true;
            }
        }

        // Masking must not erase or move a card, even when that card is beyond the output limit.
        return $this->validatedReleaseCards($original) !== $this->validatedReleaseCards($document);
    }

    private function assertUtf8Encoding(DOMDocument $document): void
    {
        // A meta declaration can override the prepended XML encoding and corrupt both labels alike.
        // libxml can retain the content-type charset's quote delimiters in this property.
        $encodings = $document->encoding === null ? [] : [$this->unquoteEncoding($document->encoding)];
        foreach ($document->getElementsByTagName('meta') as $meta) {
            if ($meta->hasAttribute('charset')) {
                $encodings[] = $meta->getAttribute('charset');
            }
            if (strcasecmp(trim($meta->getAttribute('http-equiv')), 'content-type') === 0) {
                preg_match_all('/(?:^|;)[\x20\t\r\n\f]*charset[\x20\t\r\n\f]*=[\x20\t\r\n\f]*("[^"]*"|\'[^\']*\'|[^;]*)(?=[\x20\t\r\n\f]*(?:;|$))/i', $meta->getAttribute('content'), $matches);
                foreach ($matches[1] as $encoding) {
                    $encodings[] = $this->unquoteEncoding($encoding);
                }
            }
        }
        foreach ($encodings as $encoding) {
            // Exact UTF-8 labels from the WHATWG Encoding Standard; prefixes are not aliases.
            if (! in_array(strtolower(trim($encoding, " \t\r\n\f")), [
                'unicode-1-1-utf-8', 'unicode11utf8', 'unicode20utf8', 'utf-8', 'utf8', 'x-unicode20utf8',
            ], true)) {
                throw new RuntimeException('NIMA4K returned a conflicting HTML encoding.');
            }
        }
    }

    private function unquoteEncoding(string $encoding): string
    {
        $encoding = trim($encoding, " \t\r\n\f");
        if ($encoding !== '' && ($encoding[0] === '"' || $encoding[0] === "'")) {
            if (strlen($encoding) < 2 || $encoding[-1] !== $encoding[0]) {
                throw new RuntimeException('NIMA4K returned a conflicting HTML encoding.');
            }
            $encoding = substr($encoding, 1, -1);
        }

        return trim($encoding, " \t\r\n\f");
    }

    private function classToken(string $class): string
    {
        return 'contains(concat(" ", normalize-space(@class), " "), " '.$class.' ")';
    }
}
