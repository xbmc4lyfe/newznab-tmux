<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Support\RustPipelineLibrary;
use Carbon\CarbonImmutable;
use FFI;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

/**
 * Generic RSS 2.0 PreDB feed (e.g. predb.me). Only the item title (and pubDate when present) is used.
 *
 * Opt-in feeds that publish human-readable titles ("Show S01E01 720p WEB h264-GROUP", scnsrc.me, EZTV) set
 * $spacedTitles: whitespace becomes dots and a trailing " EZTV" tag is dropped. That is lossy (the original
 * dots, underscores and casing are unrecoverable), so those sources must stay opt-in. $mapCategory copies the
 * first RSS <category> element into the entry category. Catalogues can set $publicationDatesOnly
 * so listing timestamps are never treated as PRE times.
 */
final class RssFeedSource extends BoundedHttpFeedSource
{
    private const MAXIMUM_ITEMS = 5000;

    private const NATIVE_CDEF = <<<'C'
        ssize_t nntmux_predb_xml_rss(const char *xml, size_t len, uint32_t flags, size_t page_size, char *out_buf, size_t out_cap);
        C;

    /**
     * Wave 45.2 production default for summarizeItemTitleBatch() is PHP: the paired benchmark in
     * tests/Unit/RustPredbFeedsNzedbPredatabaseRssSceneArchiveSasW45ParityTest.php measures 0.94-0.96x mean and
     * 0.84-1.02x p99 for common default-flag pages, though spaced, size-stripped uncommon batches reach 3.94-4.23x
     * mean and 2.94-5.05x p99.
     */
    public const bool W452_RUST_FFI_DEFAULT = false;

    private static ?bool $w452RustFfiOverride = null;

    private static FFI|false|null $w452RustFfiHandle = null;

    private static FFI|false|null $native = null;

    public function __construct(
        private readonly string $sourceKey,
        private readonly string $sourceLabel,
        string $endpoint,
        int $pageSize = 100,
        int $timeout = 15,
        string $userAgent = 'NNTmux-PreDB-Importer/1.0',
        private readonly bool $spacedTitles = false,
        private readonly bool $mapCategory = false,
        private readonly bool $publicationDatesOnly = false,
        private readonly bool $stripDisplaySizeSuffix = false,
        ?int $maximumBodyBytes = null,
    ) {
        parent::__construct(
            $endpoint,
            $pageSize,
            $timeout,
            $userAgent,
            $maximumBodyBytes ?? 1048576,
        );
    }

    public function key(): string
    {
        return $this->sourceKey;
    }

    /**
     * RSS feeds expose only their latest items, so every page past the first is empty.
     */
    public function fetch(int $page = 1): array
    {
        if ($page > 1) {
            return [];
        }

        return $this->parseXml($this->downloadBody());
    }

    /**
     * @return list<PredbFeedEntry>
     *
     * @throws RuntimeException When the body is not an RSS 2.0 document (e.g. an HTML error page).
     */
    public function parseXml(string $xml): array
    {
        if (strlen($xml) > $this->maximumBodyBytes) {
            throw $this->bodyLimitException();
        }
        $output = $this->nativeItems($xml);
        if ($output !== null) {
            return $this->entriesFromNative($output);
        }
        if (preg_match('/<!(?:DOCTYPE|ENTITY)\b/i', $xml) === 1) {
            throw new RuntimeException($this->sourceLabel.' returned an invalid RSS document.');
        }
        $xml = preg_replace('/\A[ \t\r\n]+(?=<\?xml[ \t\r\n])/', '', $xml) ?? $xml;
        $previous = libxml_use_internal_errors(true);

        try {
            $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($document === false || strtolower($document->getName()) !== 'rss' || ! isset($document->channel)) {
            throw new RuntimeException($this->sourceLabel.' returned an invalid RSS document.');
        }

        if (! isset($document->channel->item)) {
            return [];
        }

        if (count($document->channel->item) > self::MAXIMUM_ITEMS) {
            throw new RuntimeException($this->sourceLabel.' RSS document exceeds the item limit.');
        }

        $entries = [];

        foreach ($document->channel->item as $item) {
            $title = trim((string) $item->title);

            if ($this->stripDisplaySizeSuffix) {
                $title = preg_replace('/ \x{2013} [0-9]+(?:\.[0-9]+)? (?:MB|GB)$/u', '', $title) ?? $title;
            }

            if ($this->spacedTitles) {
                $title = $this->dotted($title);
            }

            if ($title === '' || str_contains($title, ' ')) {
                continue;
            }

            $category = $this->mapCategory ? trim((string) $item->category) : '';
            $publishedAt = $this->parseDate((string) $item->pubDate);

            $entries[] = new PredbFeedEntry(
                title: $title,
                source: $this->sourceLabel,
                category: $category !== '' ? $category : null,
                predate: $this->publicationDatesOnly ? null : $publishedAt,
                listedAt: $this->publicationDatesOnly ? $publishedAt : null,
            );

            if (count($entries) >= $this->pageSize) {
                break;
            }
        }

        return $entries;
    }

    /**
     * Rust item output, or null when PHP must parse the document (library missing, or any
     * document outside the reproduced XML subset, including every invalid one).
     */
    private function nativeItems(string $xml): ?string
    {
        $ffi = self::$native ??= RustPipelineLibrary::resolveDomain('predb_xml_catalogues', self::NATIVE_CDEF) ?? false;
        if ($ffi === false) {
            return null;
        }
        $flags = ($this->spacedTitles ? 1 : 0) | ($this->stripDisplaySizeSuffix ? 2 : 0) | ($this->mapCategory ? 4 : 0);
        $length = strlen($xml);
        $capacity = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, $length + 64, $capacity);
        if ($scratch === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_predb_xml_rss($xml, $length, $flags, $this->pageSize, $scratch, $capacity);
        if (is_int($written) && $written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_predb_xml_rss($xml, $length, $flags, $this->pageSize, $scratch, $capacity);
        }

        return is_int($written) && $written > 0 ? FFI::string($scratch, $written) : null;
    }

    /**
     * @return list<PredbFeedEntry>
     */
    private function entriesFromNative(string $output): array
    {
        $parts = explode("\0", substr($output, 1));
        $entries = [];
        for ($index = 0, $last = count($parts) - 1; $index < $last; $index += 3) {
            $category = $parts[$index + 1];
            $date = $parts[$index + 2];
            // "\x01" marks an exact RFC 2822 date that Rust already converted to UTC wall time.
            $publishedAt = $date !== '' && $date[0] === "\x01"
                ? new CarbonImmutable(substr($date, 1), 'UTC')
                : $this->parseDate($date);
            $entries[] = new PredbFeedEntry(
                title: $parts[$index],
                source: $this->sourceLabel,
                category: $category !== '' ? $category : null,
                predate: $this->publicationDatesOnly ? null : $publishedAt,
                listedAt: $this->publicationDatesOnly ? $publishedAt : null,
            );
        }

        return $entries;
    }

    private function dotted(string $title): string
    {
        $title = (string) preg_replace('/\s+EZTV$/i', '', trim($title));

        return (string) preg_replace('/\s+/', '.', $title);
    }

    private function parseDate(string $value): ?CarbonImmutable
    {
        if (trim($value) === '') {
            return null;
        }

        if ($this->publicationDatesOnly && ! isset(date_parse($value)['zone_type'])) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /** Force the Wave 45.2 helper onto Rust (true) or PHP (false); null restores W452_RUST_FFI_DEFAULT. */
    public static function useW452RustFfi(?bool $enable = true): void
    {
        self::$w452RustFfiOverride = $enable;
    }

    public static function usesW452RustFfi(): bool
    {
        return self::$w452RustFfiOverride ?? self::W452_RUST_FFI_DEFAULT;
    }

    public static function resetW452RustFfi(): void
    {
        self::$w452RustFfiOverride = null;
        self::$w452RustFfiHandle = null;
    }

    /**
     * Summarize item titles as parseXml() normalizes them: non-strings are invalid; trim(), then the display-size
     * suffix strip and dotted() when enabled (stripped and eztv count the titles each step changed); empty and
     * spaced titles are skipped, and accepted titles that differ from the input count as changed.
     *
     * @param  array<array-key, mixed>  $titles
     * @return array{total: int, invalid: int, stripped: int, eztv: int, empty: int, spaced: int, accepted: int, changed: int}
     */
    public static function summarizeItemTitleBatch(array $titles, bool $spacedTitles = false, bool $stripDisplaySizeSuffix = false): array
    {
        return (self::usesW452RustFfi() ? self::summarizeItemTitleBatchNative($titles, $spacedTitles, $stripDisplaySizeSuffix) : null)
            ?? self::summarizeItemTitleBatchPhp($titles, $spacedTitles, $stripDisplaySizeSuffix);
    }

    /**
     * @param  array<array-key, mixed>  $titles
     * @return array{total: int, invalid: int, stripped: int, eztv: int, empty: int, spaced: int, accepted: int, changed: int}
     */
    public static function summarizeItemTitleBatchPhp(array $titles, bool $spacedTitles = false, bool $stripDisplaySizeSuffix = false): array
    {
        $summary = ['total' => \count($titles), 'invalid' => 0, 'stripped' => 0, 'eztv' => 0, 'empty' => 0, 'spaced' => 0, 'accepted' => 0, 'changed' => 0];
        foreach ($titles as $text) {
            if (! \is_string($text)) {
                $summary['invalid']++;

                continue;
            }
            $title = trim($text);
            if ($stripDisplaySizeSuffix) {
                $stripped = preg_replace('/ \x{2013} [0-9]+(?:\.[0-9]+)? (?:MB|GB)$/u', '', $title) ?? $title;
                if ($stripped !== $title) {
                    $summary['stripped']++;
                    $title = $stripped;
                }
            }
            if ($spacedTitles) {
                // dotted(), split to count the dropped EZTV tag.
                $base = trim($title);
                $untagged = (string) preg_replace('/\s+EZTV$/i', '', $base);
                if ($untagged !== $base) {
                    $summary['eztv']++;
                }
                $title = (string) preg_replace('/\s+/', '.', $untagged);
            }
            if ($title === '') {
                $summary['empty']++;
            } elseif (str_contains($title, ' ')) {
                $summary['spaced']++;
            } else {
                $summary['accepted']++;
                if ($title !== $text) {
                    $summary['changed']++;
                }
            }
        }

        return $summary;
    }

    /**
     * @param  array<array-key, mixed>  $titles
     * @return array{total: int, invalid: int, stripped: int, eztv: int, empty: int, spaced: int, accepted: int, changed: int}|null
     */
    public static function summarizeItemTitleBatchNative(array $titles, bool $spacedTitles = false, bool $stripDisplaySizeSuffix = false): ?array
    {
        $packed = PredatabaseSource::packW452Items($titles);
        $flags = ($spacedTitles ? 1 : 0) | ($stripDisplaySizeSuffix ? 2 : 0);
        $values = $packed === null ? null : PredatabaseSource::callW452Native(self::w452RustFfi(), 2 | $packed[2], $flags, $packed[0], $packed[1], 8);
        if ($values === null) {
            return null;
        }
        [$total, $invalid, $stripped, $eztv, $empty, $spaced, $accepted, $changed] = $values;
        if ($total !== \count($titles) || min($values) < 0 || $invalid + $empty + $spaced + $accepted !== $total
            || $stripped + $invalid > $total || $eztv + $invalid > $total || $changed > $accepted
            || (! $stripDisplaySizeSuffix && $stripped > 0) || (! $spacedTitles && $eztv > 0) || ($spacedTitles && $spaced > 0)) {
            return null;
        }

        return ['total' => $total, 'invalid' => $invalid, 'stripped' => $stripped, 'eztv' => $eztv, 'empty' => $empty, 'spaced' => $spaced,
            'accepted' => $accepted, 'changed' => $changed];
    }

    private static function w452RustFfi(): ?FFI
    {
        self::$w452RustFfiHandle ??= RustPipelineLibrary::resolveDomain(PredatabaseSource::W452_FFI_DOMAIN, PredatabaseSource::W452_FFI_CDEF) ?? false;

        return self::$w452RustFfiHandle === false ? null : self::$w452RustFfiHandle;
    }
}
