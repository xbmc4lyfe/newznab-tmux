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
use Throwable;

/**
 * Latest printed day of the public music directory catalogue, using anchor text only.
 * Its unzoned day groups listings and supplies neither publication nor PRE timestamps.
 */
final class GabberSource extends BoundedHttpFeedSource
{
    /**
     * Wave 45.3 production default is Rust: the paired benchmark in
     * tests/Unit/RustPredbFeedsG4uGabberKnabenNima4kPassableW45ParityTest.php measures at least
     * 2.3x (mean and p99, common and uncommon batches). PHP stays the fallback when the library is
     * unavailable or a name holds a character Rust leaves to PCRE2.
     * useW453RustFfi(true) keeps the Rust helper available for parity and benchmarks.
     * Named W453 so it stays distinct from the class's existing native paths.
     */
    public const bool W453_RUST_FFI_DEFAULT = true;

    public const string W453_FFI_CDEF = <<<'C'
        ssize_t nntmux_w453_gabber_directory_name_batch(
            const char *blob_ptr,
            size_t blob_len,
            const char *lens_ptr,
            size_t lens_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /** mb_strlen() character limit for a literal directory name. */
    public const int W453_MAX_TITLE_CHARS = 255;

    /** Exactly 6 0x1F-separated decimals of 1-19 digits. */
    private const string W453_FIELDS_PATTERN = '/\A(?:\d{1,19}\x1F){5}\d{1,19}\z/';

    private static ?bool $w453RustFfiOverride = null;

    private static FFI|false|null $w453RustFfiHandle = null;

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
     * Directory name summary, using rowNames()'s rules in a fixed order: an empty name is empty,
     * one holding `\s`, `\p{Z}` or `\p{C}` (or invalid UTF-8) is nonliteral, one over
     * W453_MAX_TITLE_CHARS characters is overlength, else literal. Literal names whose
     * mb_strtolower() identity was already seen also count as duplicate, as parseHtml() drops
     * them. Non-strings count as empty.
     *
     * @param  array<array-key, mixed>  $items
     * @return array{total: int, literal: int, empty: int, nonliteral: int, overlength: int, duplicate: int}
     */
    public static function summarizeGabberDirectoryNameBatch(array $items): array
    {
        return (self::usesW453RustFfi() ? self::summarizeGabberDirectoryNameBatchNative($items) : null)
            ?? self::summarizeGabberDirectoryNameBatchPhp($items);
    }

    /**
     * @param  array<array-key, mixed>  $items
     * @return array{total: int, literal: int, empty: int, nonliteral: int, overlength: int, duplicate: int}
     */
    public static function summarizeGabberDirectoryNameBatchPhp(array $items): array
    {
        $literal = 0;
        $empty = 0;
        $nonliteral = 0;
        $overlength = 0;
        $duplicate = 0;
        $seen = [];
        foreach ($items as $item) {
            $name = \is_string($item) ? $item : '';
            if ($name === '') {
                $empty++;
            } elseif (preg_match('/[\s\p{Z}\p{C}]/u', $name) !== 0) {
                $nonliteral++;
            } elseif (mb_strlen($name, 'UTF-8') > self::W453_MAX_TITLE_CHARS) {
                $overlength++;
            } else {
                $literal++;
                $identity = mb_strtolower($name, 'UTF-8');
                if (isset($seen[$identity])) {
                    $duplicate++;
                } else {
                    $seen[$identity] = true;
                }
            }
        }

        return [
            'total' => \count($items),
            'literal' => $literal,
            'empty' => $empty,
            'nonliteral' => $nonliteral,
            'overlength' => $overlength,
            'duplicate' => $duplicate,
        ];
    }

    /**
     * Declines (null) for non-string items, and whenever Rust leaves a character class to PCRE2,
     * so PHP counts them.
     *
     * @param  array<array-key, mixed>  $items
     * @return array{total: int, literal: int, empty: int, nonliteral: int, overlength: int, duplicate: int}|null
     */
    public static function summarizeGabberDirectoryNameBatchNative(array $items): ?array
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
            'duplicate' => (int) $fields[5],
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
            $written = $ffi->nntmux_w453_gabber_directory_name_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w453_gabber_directory_name_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
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
