<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Support\RustPipelineLibrary;
use DOMDocument;
use DOMElement;
use DOMXPath;
use FFI;
use RuntimeException;
use Throwable;

/**
 * Opt-in, latest-only crowdNFO canonical catalogue headings, including contributed names.
 * File trust scores do not verify names; creation timestamps are not PRE times.
 * No aliases, payload metadata, or original spelling are inferred from the listing.
 */
final class CrowdnfoSource extends BoundedHttpFeedSource
{
    /**
     * Wave 44.3 production default stays PHP: the paired benchmark in
     * tests/Unit/RustReleaseContextPredbFeedsApibayXmlCrowdnfoDatsW44ParityTest.php measures
     * 1.99-2.09x mean but 1.01-2.03x p99 on the common 32-heading batch, so p99 does not clear
     * the 1.5x gate reliably, although uncommon batches reach 4.1-7.7x.
     * useW443RustFfi(true) keeps the Rust helper available for parity and benchmarks.
     * Named W443 so it stays distinct from the inherited feed toggles.
     */
    public const bool W443_RUST_FFI_DEFAULT = false;

    public const string W443_FFI_CDEF = <<<'C'
        ssize_t nntmux_w443_crowdnfo_heading_batch(
            const char *blob_ptr,
            size_t blob_len,
            const char *lens_ptr,
            size_t lens_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /** Scene-shaped heading: dot-separated segments, then a dash and an ASCII word group. */
    public const string W443_SCENE_HEADING_PATTERN = '/^[^.]+\.[^.]+(?:\.[^.]+)*-[A-Za-z0-9_]+$/D';

    /** Exactly 6 0x1F-separated decimals of 1-19 digits. */
    private const string W443_FIELDS_PATTERN = '/\A(?:\d{1,19}\x1F){5}\d{1,19}\z/';

    private static ?bool $w443RustFfiOverride = null;

    private static FFI|false|null $w443RustFfiHandle = null;

    private const NATIVE_CDEF = <<<'C'
        int32_t nntmux_predb_html_validate_numeric_refs(const char *html, size_t len, uint32_t mode);
        int32_t nntmux_predb_html_validate_listing_scope(const char *html, size_t len, uint32_t mode);
        C;

    private static FFI|false|null $native = null;

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
        // Reference-free pages do not amortize an FFI call. Keep this common
        // case in PHP's native string search rather than regressing its latency.
        if (! str_contains($html, '&#')) {
            return;
        }
        $ffi = self::$native ??= RustPipelineLibrary::resolveDomain('predb_sql_html', self::NATIVE_CDEF) ?? false;
        if ($ffi !== false) {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_predb_html_validate_numeric_refs($html, strlen($html), 1);
            if ($status === 1) {
                return;
            }
            if ($status === 0) {
                throw new RuntimeException('CROWDNFO returned an invalid character reference.');
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
        $ffi = self::$native ??= RustPipelineLibrary::resolveDomain('predb_sql_html', self::NATIVE_CDEF) ?? false;
        if ($ffi !== false) {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_predb_html_validate_listing_scope($html, strlen($html), 0);
            if ($status === 1) {
                return;
            }
            if ($status === 0) {
                throw new RuntimeException('CROWDNFO returned an incomplete listing document.');
            }
            if ($status === -2) {
                throw new RuntimeException('CROWDNFO returned an incomplete listing scope.');
            }
        }

        $this->validateClosedListingStructurePhp($html);
    }

    private function validateClosedListingStructurePhp(string $html): void
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

    /**
     * Enables (true) or disables (false) the Wave 44.3 Rust helper; null restores
     * W443_RUST_FFI_DEFAULT.
     */
    public static function useW443RustFfi(?bool $enable = true): void
    {
        self::$w443RustFfiOverride = $enable;
    }

    public static function usesW443RustFfi(): bool
    {
        return self::$w443RustFfiOverride ?? self::W443_RUST_FFI_DEFAULT;
    }

    public static function resetW443RustFfi(): void
    {
        self::$w443RustFfiOverride = null;
        self::$w443RustFfiHandle = null;
    }

    /**
     * Heading summary for a batch of crowdNFO card titles. Each trim()med title is empty, else
     * nonliteral when it holds an ASCII space or control byte, else truncated when it holds "..."
     * or an ellipsis, else scene when it matches W443_SCENE_HEADING_PATTERN, else other.
     * Non-strings count as empty titles.
     *
     * @param  array<array-key, mixed>  $titles
     * @return array{total: int, empty: int, nonliteral: int, truncated: int, scene: int, other: int}
     */
    public static function summarizeCrowdnfoHeadingBatch(array $titles): array
    {
        return (self::usesW443RustFfi() ? self::summarizeCrowdnfoHeadingBatchNative($titles) : null)
            ?? self::summarizeCrowdnfoHeadingBatchPhp($titles);
    }

    /**
     * @param  array<array-key, mixed>  $titles
     * @return array{total: int, empty: int, nonliteral: int, truncated: int, scene: int, other: int}
     */
    public static function summarizeCrowdnfoHeadingBatchPhp(array $titles): array
    {
        $empty = 0;
        $nonliteral = 0;
        $truncated = 0;
        $scene = 0;
        $other = 0;
        foreach ($titles as $item) {
            $title = trim(\is_string($item) ? $item : '');
            if ($title === '') {
                $empty++;
            } elseif (preg_match('/[\x00-\x20\x7F]/', $title) === 1) {
                $nonliteral++;
            } elseif (str_contains($title, '...') || str_contains($title, '…')) {
                $truncated++;
            } elseif (preg_match(self::W443_SCENE_HEADING_PATTERN, $title) === 1) {
                $scene++;
            } else {
                $other++;
            }
        }

        return [
            'total' => \count($titles),
            'empty' => $empty,
            'nonliteral' => $nonliteral,
            'truncated' => $truncated,
            'scene' => $scene,
            'other' => $other,
        ];
    }

    /**
     * Declines (null) for non-string items so PHP counts them.
     *
     * @param  array<array-key, mixed>  $titles
     * @return array{total: int, empty: int, nonliteral: int, truncated: int, scene: int, other: int}|null
     */
    public static function summarizeCrowdnfoHeadingBatchNative(array $titles): ?array
    {
        $lengths = [];
        foreach ($titles as $item) {
            if (! \is_string($item)) {
                return null;
            }
            $lengths[] = \strlen($item);
        }
        $fields = self::callW443Native(implode('', $titles), pack('V*', ...$lengths));
        if ($fields === null) {
            return null;
        }

        return [
            'total' => (int) $fields[0],
            'empty' => (int) $fields[1],
            'nonliteral' => (int) $fields[2],
            'truncated' => (int) $fields[3],
            'scene' => (int) $fields[4],
            'other' => (int) $fields[5],
        ];
    }

    /**
     * Calls this class's Wave 44.3 export with the shared scratch buffer, retrying once at the size
     * it asks for. Returns its fields only when they match W443_FIELDS_PATTERN: exactly the expected
     * count of 1-19 digit decimals.
     *
     * @return list<string>|null
     */
    private static function callW443Native(string $blob, string $lens): ?array
    {
        $ffi = self::w443RustFfi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 128, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w443_crowdnfo_heading_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w443_crowdnfo_heading_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }
            $raw = FFI::string($buffer, $written);

            return preg_match(self::W443_FIELDS_PATTERN, $raw) === 1 ? explode("\x1F", $raw) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function w443RustFfi(): ?FFI
    {
        if (self::$w443RustFfiHandle === null) {
            self::$w443RustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w443', self::W443_FFI_CDEF) ?? false;
        }

        return self::$w443RustFfiHandle === false ? null : self::$w443RustFfiHandle;
    }
}
