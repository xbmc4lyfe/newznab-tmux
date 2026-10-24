<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Support\RustPipelineLibrary;
use Dom\Element as HtmlElement;
use Dom\HTMLDocument;
use Dom\Node as HtmlNode;
use Dom\XPath as HtmlXPath;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use FFI;
use RuntimeException;
use SplObjectStorage;

/**
 * Opt-in, latest-only NIMA4K catalogue names. The 1 MiB default bounds HTML download and DOM parsing.
 * Listing dates have no timezone and delivery metadata is not PRE metadata, so both stay unknown.
 */
final class Nima4kSource extends BoundedHttpFeedSource
{
    private const HTML_NAMESPACE = 'http://www.w3.org/1999/xhtml';

    private const FFI_CDEF = <<<'C'
        ssize_t nntmux_predb_nima4k_parse_html(const char *html, size_t html_len, size_t page_size, size_t max_body_bytes, char *out_buf, size_t out_cap);
        C;

    private static FFI|false|null $native = null;

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
     * Rust accepts only a strict, unambiguous HTML subset; everything else (including every
     * invalid document) is verified by the dual-DOM PHP parser with its exact diagnostics.
     *
     * @return list<PredbFeedEntry>
     */
    public function parseHtml(string $html): array
    {
        $titles = $this->nativeTitles($html);
        if ($titles === null) {
            return $this->parseHtmlPhp($html);
        }
        $entries = [];
        foreach ($titles as $title) {
            $entries[] = new PredbFeedEntry(title: $title, source: 'nima4k.org');
        }

        return $entries;
    }

    /** @return list<string>|null */
    private function nativeTitles(string $html): ?array
    {
        $ffi = self::$native ??= RustPipelineLibrary::resolveDomain('predb_nima4k', self::FFI_CDEF) ?? false;
        if ($ffi === false) {
            return null;
        }
        $length = strlen($html);
        $capacity = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, $length + 64, $capacity);
        if ($scratch === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_predb_nima4k_parse_html($html, $length, $this->pageSize, $this->maximumBodyBytes, $scratch, $capacity);
        if (is_int($written) && $written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_predb_nima4k_parse_html($html, $length, $this->pageSize, $this->maximumBodyBytes, $scratch, $capacity);
        }
        if (! is_int($written) || $written <= 0) {
            return null;
        }
        $titles = explode("\0", FFI::string($scratch, $written));
        array_pop($titles);

        return $titles;
    }

    /**
     * Require one literal subtitle and one agreeing cover label per observed release card.
     *
     * @return list<PredbFeedEntry>
     */
    public function parseHtmlPhp(string $html): array
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
        // Reject nonwaivable loss/depth errors before additional native parsing or tree equality.
        foreach ($errors as $error) {
            if ($error->level === LIBXML_ERR_FATAL || in_array($error->code, [42, 45, 47, 61, 73, 800], true)) {
                throw new RuntimeException('NIMA4K returned invalid HTML.');
            }
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $reference = HTMLDocument::createFromString($html, 0, 'UTF-8');
            $referenceErrors = libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $projections = $this->maskProvenInertContents($html, $reference);
        $provenanceHtml = $projections['markup'];
        $this->assertUtf8Encoding($reference);
        $outsideValues = $document;
        if ($provenanceHtml !== $html) {
            // Legacy parsers can expose phantom tags/encodings or decode inert references. Only
            // native-proven text spans may change this outside-value view; original labels stay intact.
            $outsideValues = new DOMDocument;
            $previous = libxml_use_internal_errors(true);
            libxml_clear_errors();
            try {
                $loaded = $outsideValues->loadHTML('<?xml encoding="UTF-8">'.$provenanceHtml, LIBXML_NONET);
                $outsideErrors = libxml_get_errors();
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
            if (! $loaded) {
                throw new RuntimeException('NIMA4K returned invalid HTML.');
            }
            foreach ($outsideErrors as $error) {
                $discardedName = $error->code === 68 && in_array(trim($error->message), [
                    'htmlParseStartTag: invalid element name',
                    'error parsing attribute name',
                ], true);
                if ($error->level === LIBXML_ERR_FATAL || in_array($error->code, [42, 45, 47, 61, 73, 800, 802], true) || $discardedName) {
                    throw new RuntimeException('NIMA4K returned invalid HTML.');
                }
            }
            $projectionPaths = [];
            if ($this->validatedReleaseCards($document, $projectionPaths) !== $this->validatedReleaseCards($outsideValues, $projectionPaths)) {
                throw new RuntimeException('NIMA4K returned inconsistent HTML release semantics.');
            }
        }
        $this->assertDecodedValues($outsideValues);

        // This parser reports discarded controls, surrogates and overflowing references as code 9.
        $unexpectedClosingTag = null;
        $invalidNumericReference = null;
        $discardedMarkup = [];
        foreach ($errors as $error) {
            // Only proven inert source can excuse these recoveries. Code 802 discards bogus
            // declarations on newer libxml; unrelated malformed markup must still be rejected.
            $discardedName = $error->code === 68 && in_array(trim($error->message), [
                'htmlParseStartTag: invalid element name',
                'error parsing attribute name',
            ], true);
            if (($discardedName || $error->code === 802)
                && ($discardedMarkup[$error->code] ??= $this->hasMaskedDiagnostic($html, $document, $error->code, $provenanceHtml))) {
                throw new RuntimeException('NIMA4K returned invalid HTML.');
            }
            if ($error->code === 76 && ($unexpectedClosingTag ??= $this->hasMaskedDiagnostic($html, $document, 76, $provenanceHtml))) {
                throw new RuntimeException('NIMA4K returned invalid HTML.');
            }
            if ($error->code === 9 && ($invalidNumericReference ??= $this->hasMaskedDiagnostic($html, $document, 9, $projections['raw']))) {
                throw new RuntimeException('NIMA4K returned an invalid numeric character reference.');
            }
        }

        $pathInterner = [];
        $cards = $this->validatedReleaseCards($document, $pathInterner);
        // The legacy parser can expose inert cards or leave HTML5 entities undecoded without errors.
        // Compare every observed card before limiting output; labels still come from the original DOM.
        $hasControlReference = false;
        foreach ($referenceErrors as $error) {
            // HTML5 can replace invalid references before decoded-value validation, including in SVG.
            if (preg_match('/\Atokenizer error (?:null-character-reference|character-reference-outside-unicode-range|surrogate-character-reference|noncharacter-character-reference)\b/', $error->message) === 1) {
                throw new RuntimeException('NIMA4K returned an invalid numeric character reference.');
            }
            if (str_starts_with($error->message, 'tokenizer error control-character-reference ')) {
                $hasControlReference = true;
            }
        }
        if ($this->hasDisallowedControlReference($html, $hasControlReference)) {
            throw new RuntimeException('NIMA4K returned an invalid numeric character reference.');
        }
        $this->assertDecodedValues($reference);
        if ($cards !== $this->validatedReleaseCards($reference, $pathInterner)) {
            throw new RuntimeException('NIMA4K returned inconsistent HTML release semantics.');
        }
        $entries = [];
        foreach (array_slice($cards, 0, $this->pageSize) as $card) {
            $entries[] = new PredbFeedEntry(title: $card['title'], source: 'nima4k.org');
        }

        return $entries;
    }

    private function hasDisallowedControlReference(string $html, bool $hasControlReference): bool
    {
        // HTML5 warns on allowed CR as well as forbidden controls. Keep reference syntax and let
        // the native tokenizer recheck contexts after changing CR to LF. FF can disappear as initial
        // whitespace without a warning, so mirror it to VT to expose that loss in the same parse.
        $diagnosticHtml = preg_replace([
            '/&#(?:0*13(?![0-9])|[xX]0*[dD](?![0-9a-fA-F]));?/',
            '/&#(?:0*12(?![0-9])|[xX]0*[cC](?![0-9a-fA-F]));?/',
        ], ['&#10;', '&#11;'], $html);
        if ($diagnosticHtml === null || $diagnosticHtml === $html) {
            return $hasControlReference;
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

    private function assertDecodedValues(DOMDocument|HTMLDocument $document): void
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
        $rawParents = implode(' or ', array_map(static fn (string $tag): string => 'parent::'.$prefix.$tag, ['script', 'style', 'iframe', 'xmp', 'noembed', 'noframes', 'plaintext']));
        $decodedValues = $xpath->query('//text()[not('.$rawParents.')] | //@*');
        if ($decodedValues === false) {
            throw new RuntimeException('NIMA4K returned invalid HTML.');
        }
        foreach ($decodedValues as $value) {
            if (preg_match(self::FORBIDDEN_CHARACTERS, $value->nodeValue ?? '') !== 0) {
                throw new RuntimeException('NIMA4K returned invalid HTML.');
            }
        }
    }

    /**
     * @param  array<string, int>  $pathInterner
     * @return list<array{article: int, subtitle: int, cover: int, title: string}>
     */
    private function validatedReleaseCards(DOMDocument|HTMLDocument $document, array &$pathInterner): array
    {
        if ($document instanceof HTMLDocument) {
            $xpath = new HtmlXPath($document);
            $xpath->registerNamespace('h', self::HTML_NAMESPACE);
            $prefix = 'h:';
        } else {
            $xpath = new DOMXPath($document);
            $prefix = '';
        }
        $templateFilter = $document instanceof HTMLDocument ? '[not(ancestor::h:template)]' : '';
        $articles = $xpath->query('//'.$prefix.'div['.$this->classToken('article').']'.$templateFilter);
        if ($articles === false || $articles->length === 0) {
            throw new RuntimeException('NIMA4K returned no recognized release cards.');
        }
        $cards = [];
        /** @var SplObjectStorage<DOMNode|HtmlNode, int> $positions */
        $positions = new SplObjectStorage;
        /** @var SplObjectStorage<DOMNode|HtmlNode, bool> $indexedParents */
        $indexedParents = new SplObjectStorage;
        /** @var SplObjectStorage<DOMNode|HtmlNode, int> $pathIds */
        $pathIds = new SplObjectStorage;
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
            $articlePath = $this->elementPath($article, $positions, $indexedParents, $pathIds, $pathInterner);
            $subtitlePath = $this->elementPath($subtitle, $positions, $indexedParents, $pathIds, $pathInterner);
            $coverPath = $this->elementPath($cover, $positions, $indexedParents, $pathIds, $pathInterner);
            $cards[] = ['article' => $articlePath, 'subtitle' => $subtitlePath, 'cover' => $coverPath, 'title' => $title];
        }

        return $cards;
    }

    /**
     * @param  SplObjectStorage<DOMNode|HtmlNode, int>  $positions
     * @param  SplObjectStorage<DOMNode|HtmlNode, bool>  $indexedParents
     * @param  SplObjectStorage<DOMNode|HtmlNode, int>  $pathIds
     * @param  array<string, int>  $pathInterner
     */
    private function elementPath(DOMNode|HtmlNode $node, SplObjectStorage $positions, SplObjectStorage $indexedParents, SplObjectStorage $pathIds, array &$pathInterner): int
    {
        // Cache ancestor IDs, not full path strings. Strong node keys retain wrapper identity.
        $ancestors = [];
        while (($node instanceof DOMElement || $node instanceof HtmlElement) && ! $pathIds->offsetExists($node)) {
            $ancestors[] = $node;
            $node = $node->parentNode;
        }
        $parentId = $node !== null && $pathIds->offsetExists($node) ? $pathIds[$node] : 0;
        for ($index = count($ancestors) - 1; $index >= 0; $index--) {
            $element = $ancestors[$index];
            $parent = $element->parentNode;
            if ($parent === null) {
                throw new RuntimeException('NIMA4K returned an invalid release card.');
            }
            if (! $indexedParents->offsetExists($parent)) {
                $counts = [];
                foreach ($parent->childNodes as $sibling) {
                    if ($sibling instanceof DOMElement || $sibling instanceof HtmlElement) {
                        $name = serialize([$sibling->namespaceURI ?? self::HTML_NAMESPACE, $sibling->localName]);
                        $counts[$name] = ($counts[$name] ?? 0) + 1;
                        $positions[$sibling] = $counts[$name];
                    }
                }
                $indexedParents[$parent] = true;
            }
            if (! $positions->offsetExists($element)) {
                throw new RuntimeException('NIMA4K returned an invalid release card.');
            }
            // Exact tuples share IDs only within this comparison; no hashes or path-prefix copies.
            $tuple = serialize([$parentId, $element->namespaceURI ?? self::HTML_NAMESPACE, $element->localName, $positions[$element]]);
            $parentId = $pathInterner[$tuple] ??= count($pathInterner) + 1;
            $pathIds[$element] = $parentId;
        }

        return $parentId;
    }

    /** @return array{raw: string, markup: string} */
    private function maskProvenInertContents(string $html, HTMLDocument $reference): array
    {
        $unchanged = ['raw' => $html, 'markup' => $html];
        preg_match_all('/<!\[CDATA\[|<\/?(?i:plaintext|script|style|title|textarea|iframe|xmp|noembed|noframes)(?=[\x20\t\r\n\f\/>])/', $html, $openings, PREG_OFFSET_CAPTURE);
        if ($openings[0] === []) {
            return $unchanged;
        }

        // Inventory collisions once, including entity-decoded text that could otherwise spoof a
        // CDATA marker. The smallest unused integer requires at most one more step than this set.
        $used = [];
        $collect = static function (string $value) use (&$used): void {
            preg_match_all('/nima-inert-([0-9]+)-z/i', $value, $matches);
            foreach ($matches[1] as $number) {
                $used['n'.$number] = true;
            }
        };
        $collect($html);
        $xpath = new HtmlXPath($reference);
        $values = $xpath->query('//text() | //@*');
        foreach ($values as $value) {
            $collect($value->nodeValue ?? '');
        }
        $number = 0;
        while (isset($used['n'.$number])) {
            $number++;
        }
        $prefix = 'nima-inert-'.$number.'-z';
        $attribute = 'data-'.$prefix.'p';
        $parts = [];
        $candidates = [];
        $copied = 0;
        $length = strlen($html);
        // Discovery has no guessed HTML context: fake comments or quotes in raw text cannot hide
        // later candidates. Only the marked native parse decides which candidates are inert.
        foreach ($openings[0] as [$opening, $start]) {
            $id = count($candidates);
            $end = $start + strlen($opening);
            $cdata = $opening === '<![CDATA[';
            $closing = ! $cdata && $opening[1] === '/';
            $tag = $cdata ? '' : strtolower(substr($opening, $closing ? 2 : 1));
            $candidates[] = ['kind' => $cdata ? 'cdata' : ($closing ? 'close' : 'open'), 'tag' => $tag, 'start' => $start, 'end' => $end];
            // No quotes or tag delimiters are inserted into possible attribute lookalikes. Spaces
            // delimit the numeric value even before />; the original name/value prefix is retained.
            $at = $closing ? $start : $end;
            $marker = $cdata ? $prefix.'c'.$id.'z' : ($closing ? $prefix.'e'.$id.'z' : ' '.$attribute.'='.$id.' ');
            $parts[] = substr($html, $copied, $at - $copied).$marker;
            $copied = $at;
        }
        $parts[] = substr($html, $copied);
        // Candidates consume at least four disjoint source bytes. One ASCII marker per candidate,
        // with input-bounded integer IDs, keeps the default 1 MiB annotation below 16x source size.
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $marked = HTMLDocument::createFromString(implode('', $parts), 0, 'UTF-8');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $markedXpath = new HtmlXPath($marked);
        $markedXpath->registerNamespace('h', self::HTML_NAMESPACE);
        $plaintext = $markedXpath->query('//h:plaintext[@'.$attribute.']');
        $elements = $markedXpath->query('//*[@'.$attribute.']');
        $values = $markedXpath->query('//text() | //comment() | //processing-instruction() | //@*');
        // A retained HTML plaintext opener consumes the remainder of the input. Reject ambiguity
        // before scanning an original opener, so fake candidates never cause repeated suffix scans.
        if ($plaintext->length > 1) {
            throw new RuntimeException('NIMA4K returned ambiguous inert HTML.');
        }
        $proven = [];
        foreach ($elements as $node) {
            if (! $node instanceof HtmlElement) {
                throw new RuntimeException('NIMA4K returned invalid HTML.');
            }
            $id = $node->getAttribute($attribute);
            if ($node->namespaceURI === self::HTML_NAMESPACE && ctype_digit($id)
                && isset($candidates[(int) $id]) && $candidates[(int) $id]['kind'] === 'open'
                && strtolower($node->localName) === $candidates[(int) $id]['tag']) {
                if (isset($proven[(int) $id])) {
                    throw new RuntimeException('NIMA4K returned ambiguous inert HTML.');
                }
                $proven[(int) $id] = true;
            }
        }
        $counts = [];
        $ends = [];
        foreach ($values as $value) {
            $text = $value->nodeValue ?? '';
            preg_match_all('/'.$prefix.'([ce])(0|[1-9][0-9]*)z/', $text, $matches, PREG_SET_ORDER);
            foreach ($matches as [$marker, $kind, $id]) {
                if (! isset($candidates[(int) $id]) || $candidates[(int) $id]['kind'] !== ($kind === 'c' ? 'cdata' : 'close')) {
                    throw new RuntimeException('NIMA4K returned ambiguous inert HTML.');
                }
                $counts[(int) $id] = ($counts[(int) $id] ?? 0) + 1;
                $parent = $value->parentNode;
                if (in_array($value->nodeType, [XML_TEXT_NODE, XML_CDATA_SECTION_NODE], true)
                    && $parent instanceof HtmlElement) {
                    if ($kind === 'c' && $parent->namespaceURI !== null && $parent->namespaceURI !== self::HTML_NAMESPACE) {
                        $proven[(int) $id] = true;
                    } elseif ($kind === 'e' && $parent->namespaceURI === self::HTML_NAMESPACE
                        && $value->nextSibling === null && str_ends_with($text, $marker)
                        && strtolower($parent->localName) === $candidates[(int) $id]['tag']) {
                        $opener = $parent->getAttribute($attribute);
                        if (ctype_digit($opener) && isset($proven[(int) $opener])) {
                            if (isset($ends[(int) $opener])) {
                                throw new RuntimeException('NIMA4K returned ambiguous inert HTML.');
                            }
                            // An actual close ends native text at its prefix marker. A false script
                            // close stays literal after that marker, including double-escaped states.
                            $ends[(int) $opener] = (int) $id;
                        }
                    }
                }
            }
        }
        // Even unquoted markers can split an existing unquoted value and expose a semantic
        // attribute (for example SVG font color). Undo only fresh annotations and require the
        // entire native tree to agree before trusting any recorded namespace provenance.
        foreach ($values as $value) {
            $restored = preg_replace('/ '.$attribute.'=(?:0|[1-9][0-9]*) |'.$prefix.'[ce](?:0|[1-9][0-9]*)z/', '', $value->nodeValue ?? '');
            if ($restored === null) {
                throw new RuntimeException('NIMA4K returned invalid HTML.');
            }
            $value->nodeValue = $restored;
        }
        $markerAttributes = $markedXpath->query('//*[@'.$attribute.']');
        foreach ($markerAttributes as $node) {
            if ($node instanceof HtmlElement) {
                $node->removeAttribute($attribute);
            }
        }
        // Normalize only adjacent/empty text bookkeeping, without changing values or elements.
        $reference->normalize();
        $marked->normalize();
        if (! $reference->isEqualNode($marked)) {
            return $unchanged;
        }
        $spans = [];
        $copied = 0;
        foreach ($candidates as $id => $candidate) {
            if ($candidate['start'] < $copied || ! isset($proven[$id])) {
                continue;
            }
            if ($candidate['kind'] === 'open') {
                $plaintext = $candidate['tag'] === 'plaintext';
                if (! $plaintext && ! isset($ends[$id])) {
                    continue;
                }
                $close = $plaintext ? $length : $candidates[$ends[$id]]['start'];
                if (! $plaintext && ($counts[$ends[$id]] ?? 0) !== 1) {
                    throw new RuntimeException('NIMA4K returned ambiguous inert HTML.');
                }
                $end = $this->provenTagEnd($html, $candidate['end'], $close);
                if (! $plaintext) {
                    // A truncated end tag can be discarded at EOF. A terminal text marker alone
                    // must not authorize that loss; retain and require its complete source token.
                    $this->provenTagEnd($html, $candidates[$ends[$id]]['end'], $length);
                }
                $spans[] = ['start' => $end + 1, 'end' => $close, 'replacement' => '', 'rcdata' => in_array($candidate['tag'], ['title', 'textarea'], true)];
                $copied = $close;

                continue;
            }
            if (($counts[$id] ?? 0) !== 1) {
                throw new RuntimeException('NIMA4K returned ambiguous inert HTML.');
            }
            $end = strpos($html, ']]>', $candidate['end']);
            if ($end === false) {
                throw new RuntimeException('NIMA4K returned invalid HTML.');
            }
            // A comment preserves token separation on both sides of an authorized whole span.
            $spans[] = ['start' => $candidate['start'], 'end' => $end + 3, 'replacement' => '<!-- -->', 'rcdata' => false];
            $copied = $end + 3;
        }
        $projections = [];
        foreach (['raw', 'markup'] as $mode) {
            $parts = [];
            $copied = 0;
            foreach ($spans as $span) {
                if ($mode === 'raw' && $span['rcdata']) {
                    continue;
                }
                $parts[] = substr($html, $copied, $span['start'] - $copied).$span['replacement'];
                $copied = $span['end'];
            }
            $parts[] = substr($html, $copied);
            $projections[$mode] = implode('', $parts);
        }

        return ['raw' => $projections['raw'], 'markup' => $projections['markup']];
    }

    private function provenTagEnd(string $html, int $offset, int $limit): int
    {
        // Called only for native-proven, disjoint element boundaries. A quote starts a quoted
        // value only after =; quotes inside names or unquoted values cannot hide the true >.
        $state = 'before-name';
        for ($end = $offset; $end < $limit; $end++) {
            $character = $html[$end];
            if ($state === 'double-quoted' || $state === 'single-quoted') {
                if ($character === ($state === 'double-quoted' ? '"' : "'")) {
                    $state = 'after-value';
                }

                continue;
            }
            if ($character === '>') {
                return $end;
            }
            $space = str_contains(" \t\r\n\f", $character);
            switch ($state) {
                case 'before-name':
                case 'after-value':
                    $state = $space || $character === '/' ? 'before-name' : 'name';
                    break;
                case 'name':
                    if ($space) {
                        $state = 'after-name';
                    } elseif ($character === '/') {
                        $state = 'before-name';
                    } elseif ($character === '=') {
                        $state = 'before-value';
                    }
                    break;
                case 'after-name':
                    if ($character === '=') {
                        $state = 'before-value';
                    } elseif ($character === '/') {
                        $state = 'before-name';
                    } elseif (! $space) {
                        $state = 'name';
                    }
                    break;
                case 'before-value':
                    if (! $space) {
                        $state = match ($character) {
                            '"' => 'double-quoted',
                            "'" => 'single-quoted',
                            default => 'unquoted',
                        };
                    }
                    break;
                case 'unquoted':
                    if ($space) {
                        $state = 'before-name';
                    }
                    break;
            }
        }

        throw new RuntimeException('NIMA4K returned invalid HTML.');
    }

    private function hasMaskedDiagnostic(string $html, DOMDocument $original, int $code, string $provenanceHtml): bool
    {
        // The caller chooses native-proven raw text for numeric diagnostics, also RCDATA for markup.
        $masked = $provenanceHtml;
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
            $sameDiagnostic = $error->code === $code && ($code !== 68 || in_array(trim($error->message), [
                'htmlParseStartTag: invalid element name',
                'error parsing attribute name',
            ], true));
            if ($sameDiagnostic || $error->level === LIBXML_ERR_FATAL) {
                return true;
            }
        }

        // Masking must not erase or move a card, even when that card is beyond the output limit.
        $pathInterner = [];

        return $this->validatedReleaseCards($original, $pathInterner) !== $this->validatedReleaseCards($document, $pathInterner);
    }

    private function assertUtf8Encoding(DOMDocument|HTMLDocument $document): void
    {
        // A meta declaration can override the prepended XML encoding and corrupt both labels alike.
        // libxml can retain the content-type charset's quote delimiters in this property.
        $encodings = $document instanceof DOMDocument && $document->encoding !== null ? [$this->unquoteEncoding($document->encoding)] : [];
        $metadata = $document instanceof HTMLDocument
            ? $document->getElementsByTagNameNS(self::HTML_NAMESPACE, 'meta')
            : $document->getElementsByTagName('meta');
        foreach ($metadata as $meta) {
            if ($meta->hasAttribute('charset')) {
                $encodings[] = $meta->getAttribute('charset');
            }
            if (strcasecmp(trim($meta->getAttribute('http-equiv') ?? ''), 'content-type') === 0) {
                preg_match_all('/(?:^|;)[\x20\t\r\n\f]*charset[\x20\t\r\n\f]*=[\x20\t\r\n\f]*("[^"]*"|\'[^\']*\'|[^;]*)(?=[\x20\t\r\n\f]*(?:;|$))/i', $meta->getAttribute('content') ?? '', $matches);
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
