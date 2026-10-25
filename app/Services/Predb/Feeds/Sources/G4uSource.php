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
use Throwable;

/**
 * Latest-only public game catalogue. Names come from literal card fields, never editorial titles.
 * The today date groups updates; it supplies neither a publication timestamp nor a PRE time.
 */
final class G4uSource extends BoundedHttpFeedSource
{
    /**
     * Wave 45.3 production default stays PHP: the paired benchmark in
     * tests/Unit/RustPredbFeedsG4uGabberKnabenNima4kPassableW45ParityTest.php measures 1.73-2.16x
     * mean but 0.94-2.35x p99 on the common 32-title batch (fixed FFI marshaling is close to the
     * whole PHP cost), below the 1.5x gate, although uncommon batches reach 3.7-5.4x.
     * useW453RustFfi(true) keeps the Rust helper available for parity and benchmarks.
     * Named W453 so it stays distinct from the class's existing native paths.
     */
    public const bool W453_RUST_FFI_DEFAULT = false;

    public const string W453_FFI_CDEF = <<<'C'
        ssize_t nntmux_w453_g4u_card_title_batch(
            const char *blob_ptr,
            size_t blob_len,
            const char *lens_ptr,
            size_t lens_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /** mb_strlen() character limit for a literal card title. */
    public const int W453_MAX_TITLE_CHARS = 255;

    /** Exactly 5 0x1F-separated decimals of 1-19 digits. */
    private const string W453_FIELDS_PATTERN = '/\A(?:\d{1,19}\x1F){4}\d{1,19}\z/';

    private static ?bool $w453RustFfiOverride = null;

    private static FFI|false|null $w453RustFfiHandle = null;

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

    /**
     * Enables (true) or disables (false) the Wave 45.3 Rust helper; null restores
     * W453_RUST_FFI_DEFAULT.
     */
    public static function useW453RustFfi(?bool $enable = true): void
    {
        self::$w453RustFfiOverride = $enable;
    }

    public static function usesW453RustFfi(): bool
    {
        return self::$w453RustFfiOverride ?? self::W453_RUST_FFI_DEFAULT;
    }

    public static function resetW453RustFfi(): void
    {
        self::$w453RustFfiOverride = null;
        self::$w453RustFfiHandle = null;
    }

    /**
     * Literal card title summary, using cardTitle()'s rules in a fixed order: an empty title is
     * empty, one holding `\s`, `\p{Z}` or `\p{C}` (or invalid UTF-8) is nonliteral, one over
     * W453_MAX_TITLE_CHARS characters is overlength, else literal. Non-strings count as empty.
     *
     * @param  array<array-key, mixed>  $items
     * @return array{total: int, literal: int, empty: int, nonliteral: int, overlength: int}
     */
    public static function summarizeG4uCardTitleBatch(array $items): array
    {
        return (self::usesW453RustFfi() ? self::summarizeG4uCardTitleBatchNative($items) : null)
            ?? self::summarizeG4uCardTitleBatchPhp($items);
    }

    /**
     * @param  array<array-key, mixed>  $items
     * @return array{total: int, literal: int, empty: int, nonliteral: int, overlength: int}
     */
    public static function summarizeG4uCardTitleBatchPhp(array $items): array
    {
        $literal = 0;
        $empty = 0;
        $nonliteral = 0;
        $overlength = 0;
        foreach ($items as $item) {
            $title = \is_string($item) ? $item : '';
            if ($title === '') {
                $empty++;
            } elseif (preg_match('/[\s\p{Z}\p{C}]/u', $title) !== 0) {
                $nonliteral++;
            } elseif (mb_strlen($title, 'UTF-8') > self::W453_MAX_TITLE_CHARS) {
                $overlength++;
            } else {
                $literal++;
            }
        }

        return [
            'total' => \count($items),
            'literal' => $literal,
            'empty' => $empty,
            'nonliteral' => $nonliteral,
            'overlength' => $overlength,
        ];
    }

    /**
     * Declines (null) for non-string items, and whenever Rust leaves a character class to PCRE2,
     * so PHP counts them.
     *
     * @param  array<array-key, mixed>  $items
     * @return array{total: int, literal: int, empty: int, nonliteral: int, overlength: int}|null
     */
    public static function summarizeG4uCardTitleBatchNative(array $items): ?array
    {
        $lengths = [];
        foreach ($items as $item) {
            if (! \is_string($item)) {
                return null;
            }
            $lengths[] = \strlen($item);
        }
        $fields = self::callW453Native(implode('', $items), pack('V*', ...$lengths));
        if ($fields === null) {
            return null;
        }

        return [
            'total' => (int) $fields[0],
            'literal' => (int) $fields[1],
            'empty' => (int) $fields[2],
            'nonliteral' => (int) $fields[3],
            'overlength' => (int) $fields[4],
        ];
    }

    /**
     * Calls this class's Wave 45.3 export with the shared scratch buffer, retrying once at the size
     * it asks for. Returns its fields only when they match W453_FIELDS_PATTERN: exactly the expected
     * count of 1-19 digit decimals.
     *
     * @return list<string>|null
     */
    private static function callW453Native(string $blob, string $lens): ?array
    {
        $ffi = self::w453RustFfi();
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
            $written = $ffi->nntmux_w453_g4u_card_title_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w453_g4u_card_title_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }
            $raw = FFI::string($buffer, $written);

            return preg_match(self::W453_FIELDS_PATTERN, $raw) === 1 ? explode("\x1F", $raw) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function w453RustFfi(): ?FFI
    {
        if (self::$w453RustFfiHandle === null) {
            self::$w453RustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w453', self::W453_FFI_CDEF) ?? false;
        }

        return self::$w453RustFfiHandle === false ? null : self::$w453RustFfiHandle;
    }
}
