<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Services\Predb\Feeds\Contracts\CompleteCatalogueSource;
use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

/**
 * Manual complete-export catalogue: IDs order catalogue entries, never scene PRE times.
 * Gamecard image sizes, CRCs and archive aliases are deliberately not release metadata.
 */
abstract class CompleteXmlCatalogueSource extends BoundedHttpFeedSource implements CompleteCatalogueSource
{
    /**
     * Wave 44.3 production default stays PHP: the paired benchmark in
     * tests/Unit/RustReleaseContextPredbFeedsApibayXmlCrowdnfoDatsW44ParityTest.php measures
     * 1.38-1.48x mean and 0.93-1.71x p99 on the common 32-title batch (FFI marshaling dominates
     * the cheap PHP checks), below the 1.5x gate, although uncommon batches reach 2.6-4.6x.
     * useW443RustFfi(true) keeps the Rust helper available for parity and benchmarks.
     * Named W443 so it stays distinct from the inherited feed toggles.
     */
    public const bool W443_RUST_FFI_DEFAULT = false;

    public const string W443_FFI_CDEF = <<<'C'
        ssize_t nntmux_w443_complete_xml_catalogue_title_batch(
            const char *blob_ptr,
            size_t blob_len,
            const char *lens_ptr,
            size_t lens_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /** Byte limit for an admitted catalogue title. */
    public const int W443_MAX_TITLE_BYTES = 255;

    /** Exactly 6 0x1F-separated decimals of 1-19 digits. */
    private const string W443_FIELDS_PATTERN = '/\A(?:\d{1,19}\x1F){5}\d{1,19}\z/';

    private static ?bool $w443RustFfiOverride = null;

    private static FFI|false|null $w443RustFfiHandle = null;

    private const NATIVE_CDEF = <<<'C'
        ssize_t nntmux_predb_xml_complete_catalogue(const char *xml, size_t len, size_t max_records, char *out_buf, size_t out_cap);
        C;

    private static FFI|false|null $native = null;

    /** @var list<PredbFeedEntry>|null */
    private ?array $entries = null;

    /** @var array<string, int> */
    private array $rejections = [];

    private int $acceptedCount = 0;

    private int $boundaryNormalizedCount = 0;

    public function __construct(
        string $endpoint,
        int $pageSize = 100,
        int $timeout = 15,
        string $userAgent = 'NNTmux-PreDB-Importer/1.0',
        int $maximumBodyBytes = 8388608,
        private readonly int $maximumRecords = 5000,
    ) {
        if ($maximumBodyBytes < 1 || $maximumBodyBytes > 8388608
            || $maximumRecords < 1 || $maximumRecords > 5000 || $pageSize < 1
        ) {
            throw new RuntimeException(strtoupper($this->key()).' limits must be positive and within the complete-export bounds.');
        }

        parent::__construct($endpoint, $pageSize, $timeout, $userAgent, $maximumBodyBytes);
    }

    abstract protected function sourceLabel(): string;

    /** @return list<PredbFeedEntry> */
    public function fetch(int $page = 1): array
    {
        if ($page < 1) {
            throw new RuntimeException(strtoupper($this->key()).' local pages start at one.');
        }
        if ($this->entries === null) {
            $this->rejections = [];
            $this->acceptedCount = 0;
            $this->boundaryNormalizedCount = 0;
            $this->entries = $this->parseXml($this->downloadBody());
        }
        if ($page > (int) ceil(count($this->entries) / $this->pageSize)) {
            return [];
        }

        return array_slice($this->entries, ($page - 1) * $this->pageSize, $this->pageSize);
    }

    /** @return array<string, int> */
    public function rejectionCounts(): array
    {
        return $this->rejections;
    }

    public function acceptedCount(): int
    {
        return $this->acceptedCount;
    }

    public function boundaryNormalizedCount(): int
    {
        return $this->boundaryNormalizedCount;
    }

    /** @return list<PredbFeedEntry> */
    public function parseXml(string $xml): array
    {
        $this->rejections = [];
        $this->acceptedCount = 0;
        $this->boundaryNormalizedCount = 0;
        $this->entries = null;
        $output = strlen($xml) <= $this->maximumBodyBytes ? $this->nativeCatalogue($xml) : null;
        if ($output !== null) {
            return $this->entriesFromNative($output);
        }
        if (strlen($xml) > $this->maximumBodyBytes || str_contains($xml, "\0")
            || preg_match('//u', $xml) !== 1 || preg_match('/<!(?:DOCTYPE|ENTITY)\b/i', $xml)
        ) {
            throw new RuntimeException(strtoupper($this->key()).' export exceeds its bounds or declares external entities.');
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($document === false || $document->getName() !== 'releases'
            || $document->getDocNamespaces(true) !== [] || trim((string) $document) !== ''
        ) {
            throw new RuntimeException(strtoupper($this->key()).' returned an invalid complete XML export.');
        }

        $total = count($document->release);
        if ($total < 1 || $total > $this->maximumRecords) {
            throw new RuntimeException(strtoupper($this->key()).' export has no records or exceeds the record limit.');
        }

        $accepted = [];
        $ids = [];
        $rejections = [];
        $normalizedIds = [];
        foreach ($document->children() as $record) {
            if ($record->getName() !== 'release' || count($record->id) !== 1 || count($record->id->children()) !== 0) {
                throw new RuntimeException(strtoupper($this->key()).' export contains an unexpected record or missing catalogue ID.');
            }
            $id = (string) $record->id;
            if (! preg_match('/^[1-9][0-9]*$/D', $id)
                || filter_var($id, FILTER_VALIDATE_INT) === false || isset($ids[(int) $id])
            ) {
                throw new RuntimeException(strtoupper($this->key()).' export contains an invalid or duplicate catalogue ID.');
            }
            $ids[(int) $id] = true;

            $rawTitle = (string) $record->releasename;
            $title = trim($rawTitle);
            $reason = $this->rejectionReason($record, $title);
            if ($reason !== null) {
                $rejections[$reason] = ($rejections[$reason] ?? 0) + 1;

                continue;
            }
            $accepted[(int) $id] = new PredbFeedEntry(title: $title, source: $this->sourceLabel());
            $normalizedIds[(int) $id] = $rawTitle !== $title;
        }

        krsort($accepted, SORT_NUMERIC);
        $entries = [];
        $titles = [];
        $boundaryNormalized = 0;
        foreach ($accepted as $id => $entry) {
            $title = mb_strtolower($entry->title, 'UTF-8');
            if (isset($titles[$title])) {
                $rejections['duplicate_title'] = ($rejections['duplicate_title'] ?? 0) + 1;

                continue;
            }
            $titles[$title] = true;
            $entries[] = $entry;
            $boundaryNormalized += (int) $normalizedIds[$id];
        }

        return $this->publish($entries, $rejections, $total, $boundaryNormalized);
    }

    /**
     * Rust catalogue output, or null when PHP must parse the export (library missing, or any
     * export outside the reproduced XML subset, including every invalid one).
     */
    private function nativeCatalogue(string $xml): ?string
    {
        $ffi = self::$native ??= RustPipelineLibrary::resolveDomain('predb_xml_catalogues', self::NATIVE_CDEF) ?? false;
        if ($ffi === false) {
            return null;
        }
        $length = strlen($xml);
        $capacity = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, $length + 256, $capacity);
        if ($scratch === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_predb_xml_complete_catalogue($xml, $length, $this->maximumRecords, $scratch, $capacity);
        if (is_int($written) && $written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_predb_xml_complete_catalogue($xml, $length, $this->maximumRecords, $scratch, $capacity);
        }

        return is_int($written) && $written > 0 ? FFI::string($scratch, $written) : null;
    }

    /** @return list<PredbFeedEntry> */
    private function entriesFromNative(string $output): array
    {
        $parts = explode("\0", substr($output, 1));
        $rejections = [];
        $offset = 3;
        for ($reason = 0, $reasons = (int) $parts[2]; $reason < $reasons; $reason++, $offset += 2) {
            $rejections[$parts[$offset]] = (int) $parts[$offset + 1];
        }
        $entries = [];
        $label = $this->sourceLabel();
        for ($count = count($parts); $offset < $count; $offset++) {
            $entries[] = new PredbFeedEntry(title: $parts[$offset], source: $label);
        }

        return $this->publish($entries, $rejections, (int) $parts[0], (int) $parts[1]);
    }

    /**
     * @param  list<PredbFeedEntry>  $entries
     * @param  array<string, int>  $rejections
     * @return list<PredbFeedEntry>
     */
    private function publish(array $entries, array $rejections, int $total, int $boundaryNormalized): array
    {
        $this->rejections = $rejections;
        if ($rejections !== []) {
            Log::warning(strtoupper($this->key()).' export omitted ambiguous, nonliteral or duplicate catalogue records.', [
                'total_records' => $total,
                'rejected_records' => array_sum($rejections),
                'reasons' => $rejections,
            ]);
        }

        if ($boundaryNormalized > 0) {
            Log::notice(strtoupper($this->key()).' export applied importer-compatible outer title formatting trim.', [
                'total_records' => $total,
                'boundary_normalized_records' => $boundaryNormalized,
            ]);
        }

        $this->acceptedCount = count($entries);
        $this->boundaryNormalizedCount = $boundaryNormalized;

        return $entries;
    }

    private function rejectionReason(SimpleXMLElement $record, string $title): ?string
    {
        if (count($record->releasename) !== 1 || count($record->releasename->children()) !== 0
            || $title === ''
        ) {
            return 'missing_or_ambiguous_title';
        }
        if (preg_match('/[\s\p{Z}\p{C}]/u', $title) !== 0) {
            return 'nonliteral_title';
        }
        if (mb_strlen($title, 'UTF-8') > 255) {
            return 'overlength_title';
        }

        return null;
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
     * Title admission summary for a batch of raw catalogue titles. Each trim()med title is missing
     * when empty, nonliteral when it holds an ASCII space or control byte, overlength above
     * W443_MAX_TITLE_BYTES bytes, else accepted; accepted titles that trim() changed also count as
     * normalized. Non-strings count as empty titles.
     *
     * @param  array<array-key, mixed>  $titles
     * @return array{total: int, accepted: int, missing: int, nonliteral: int, overlength: int, normalized: int}
     */
    public static function summarizeCatalogueTitleBatch(array $titles): array
    {
        return (self::usesW443RustFfi() ? self::summarizeCatalogueTitleBatchNative($titles) : null)
            ?? self::summarizeCatalogueTitleBatchPhp($titles);
    }

    /**
     * @param  array<array-key, mixed>  $titles
     * @return array{total: int, accepted: int, missing: int, nonliteral: int, overlength: int, normalized: int}
     */
    public static function summarizeCatalogueTitleBatchPhp(array $titles): array
    {
        $accepted = 0;
        $missing = 0;
        $nonliteral = 0;
        $overlength = 0;
        $normalized = 0;
        foreach ($titles as $item) {
            $raw = \is_string($item) ? $item : '';
            $title = trim($raw);
            if ($title === '') {
                $missing++;
            } elseif (preg_match('/[\x00-\x20\x7F]/', $title) === 1) {
                $nonliteral++;
            } elseif (\strlen($title) > self::W443_MAX_TITLE_BYTES) {
                $overlength++;
            } else {
                $accepted++;
                $normalized += (int) ($title !== $raw);
            }
        }

        return [
            'total' => \count($titles),
            'accepted' => $accepted,
            'missing' => $missing,
            'nonliteral' => $nonliteral,
            'overlength' => $overlength,
            'normalized' => $normalized,
        ];
    }

    /**
     * Declines (null) for non-string items so PHP counts them.
     *
     * @param  array<array-key, mixed>  $titles
     * @return array{total: int, accepted: int, missing: int, nonliteral: int, overlength: int, normalized: int}|null
     */
    public static function summarizeCatalogueTitleBatchNative(array $titles): ?array
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
            'accepted' => (int) $fields[1],
            'missing' => (int) $fields[2],
            'nonliteral' => (int) $fields[3],
            'overlength' => (int) $fields[4],
            'normalized' => (int) $fields[5],
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
            $written = $ffi->nntmux_w443_complete_xml_catalogue_title_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w443_complete_xml_catalogue_title_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
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
