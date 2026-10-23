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

/**
 * Manual complete-export catalogue: IDs order catalogue entries, never scene PRE times.
 * Gamecard image sizes, CRCs and archive aliases are deliberately not release metadata.
 */
abstract class CompleteXmlCatalogueSource extends BoundedHttpFeedSource implements CompleteCatalogueSource
{
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
}
