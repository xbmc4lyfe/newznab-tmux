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
        foreach ($errors as $error) {
            // These malformed-markup recoveries discard bytes or values; fatal errors truncate input.
            // Codes 45 and 76 cover unterminated comments and unexpected closing tags respectively.
            $discardedName = $error->code === 68 && in_array(trim($error->message), [
                'htmlParseStartTag: invalid element name',
                'error parsing attribute name',
            ], true);
            if ($error->level === LIBXML_ERR_FATAL || in_array($error->code, [42, 45, 61, 73, 800], true) || $discardedName) {
                throw new RuntimeException('NIMA4K returned invalid HTML.');
            }
            if ($error->code === 76 && ($unexpectedClosingTag ??= $this->hasUnexpectedClosingTag($html))) {
                throw new RuntimeException('NIMA4K returned invalid HTML.');
            }
            if ($error->code === 9) {
                throw new RuntimeException('NIMA4K returned an invalid numeric character reference.');
            }
        }

        $this->assertUtf8Encoding($document);
        $xpath = new DOMXPath($document);
        // DOM values are already decoded once. Comments and script/style text are inert,
        // but their attributes and every decoded value beyond the record limit still matter.
        $decodedValues = $xpath->query('//text()[not(parent::script or parent::style)] | //@*');
        if ($decodedValues === false) {
            throw new RuntimeException('NIMA4K returned invalid HTML.');
        }
        foreach ($decodedValues as $value) {
            if (preg_match(self::FORBIDDEN_CHARACTERS, $value->nodeValue ?? '') !== 0) {
                throw new RuntimeException('NIMA4K returned invalid HTML.');
            }
        }
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
            if (! $subtitle instanceof DOMElement || ! $cover instanceof DOMElement) {
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
            if (count($entries) < $this->pageSize) {
                $entries[] = new PredbFeedEntry(title: $title, source: 'nima4k.org');
            }
        }

        return $entries;
    }

    private function hasUnexpectedClosingTag(string $html): bool
    {
        // libxml also reports code 76 for apparent closing tags inside inert script/style text.
        // Recheck only this diagnostic with that text omitted; literal labels use the original DOM.
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
            if (preg_match('/\A<(script|style|title|textarea)(?=[\x20\t\r\n\/>])/i', substr($html, $start, $offset - $start), $opening) !== 1) {
                continue;
            }
            if (preg_match('~</'.$opening[1].'(?=[\x20\t\r\n/>])~i', $html, $closing, PREG_OFFSET_CAPTURE, $offset) !== 1) {
                throw new RuntimeException('NIMA4K returned invalid HTML.');
            }
            if (in_array(strtolower($opening[1]), ['script', 'style'], true)) {
                $parts[] = substr($html, $copied, $offset - $copied);
                $copied = $closing[0][1];
            }
            $offset = $closing[0][1];
        }
        if ($parts === []) {
            return true;
        }
        $parts[] = substr($html, $copied);
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $loaded = (new DOMDocument)->loadHTML('<?xml encoding="UTF-8">'.implode('', $parts), LIBXML_NONET);
            $errors = libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $loaded) {
            return true;
        }
        foreach ($errors as $error) {
            if ($error->code === 76 || $error->level === LIBXML_ERR_FATAL) {
                return true;
            }
        }

        return false;
    }

    private function assertUtf8Encoding(DOMDocument $document): void
    {
        // A meta declaration can override the prepended XML encoding and corrupt both labels alike.
        // libxml can retain the content-type charset's quote delimiters in this property.
        $encodings = $document->encoding === null ? [] : [trim($document->encoding, "\"'")];
        foreach ($document->getElementsByTagName('meta') as $meta) {
            if ($meta->hasAttribute('charset')) {
                $encodings[] = $meta->getAttribute('charset');
            }
            if (strcasecmp(trim($meta->getAttribute('http-equiv')), 'content-type') === 0) {
                preg_match_all('/(?:^|;)\s*charset\s*=\s*([^;\s]+)/i', $meta->getAttribute('content'), $matches);
                foreach ($matches[1] as $encoding) {
                    $encodings[] = trim($encoding, "\"'");
                }
            }
        }
        foreach ($encodings as $encoding) {
            if (! in_array(strtoupper(trim($encoding)), ['UTF-8', 'UTF8'], true)) {
                throw new RuntimeException('NIMA4K returned a conflicting HTML encoding.');
            }
        }
    }

    private function classToken(string $class): string
    {
        return 'contains(concat(" ", normalize-space(@class), " "), " '.$class.' ")';
    }
}
