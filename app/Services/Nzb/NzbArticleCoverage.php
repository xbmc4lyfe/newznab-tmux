<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Models\Release;
use App\Services\Releases\ReleaseDuplicateFinder;
use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

/** Proves single-release article coverage without metadata-only suppression. */
final class NzbArticleCoverage
{
    public function __construct(private readonly NzbService $nzb, private readonly ReleaseDuplicateFinder $releaseDuplicateFinder) {}

    private const int COVERAGE_CANDIDATES = 128;

    private const int COVERAGE_COMPRESSED_BYTES = 4_194_304;

    private const int COVERAGE_DECODED_BYTES = 16_777_216;

    private const int COVERAGE_TOTAL_BYTES = 33_554_432;

    private const int COVERAGE_IDS = 100_000;

    private const string FFI_CDEF = 'ssize_t nntmux_nzb_coverage_ids(const char *xml, size_t xml_len, char *out_buf, size_t out_cap);';

    /**
     * Stored fingerprints and metadata only nominate uploads. Every suppression
     * requires a complete readable NZB covering the incoming set by exact ID bytes.
     * Bounds limit comparison work, never whether an incoming NZB may be imported.
     *
     * @param  (callable(Release): void)|null  $onCandidate
     * @param  (callable(Release, \SimpleXMLElement): bool)|null  $acceptCovered
     * @param  array<string, true>|null  $requiredIds  coverageIds($incoming), when the caller already computed it
     */
    public function findCoveredUpload(int $totalSize, ?string $fingerprint, ?\SimpleXMLElement $incoming = null, string $name = '', string $searchName = '', int $predbId = 0, bool $publishedOnly = false, ?callable $onCandidate = null, ?callable $acceptCovered = null, ?array $requiredIds = null): ?Release
    {
        if ($incoming === null || ($required = $requiredIds ?? $this->coverageIds($incoming)) === null) {
            return null;
        }
        $seen = [];
        $budget = self::COVERAGE_TOTAL_BYTES;
        $verify = function (Release $candidate) use (&$seen, &$budget, $required, $publishedOnly, $onCandidate, $acceptCovered): bool {
            if ((string) $candidate->guid === '' || isset($seen[$candidate->id]) || count($seen) >= self::COVERAGE_CANDIDATES || $budget <= 0) {
                return false;
            }
            $seen[$candidate->id] = true;
            if ($onCandidate !== null) {
                $onCandidate($candidate);
            }
            if (! $this->isCurrentRelease($candidate, $publishedOnly)) {
                return false;
            }

            $snapshot = $this->coveredIdsSnapshot($required, (string) $candidate->guid, $budget);

            return $snapshot !== null && $this->isCurrentRelease($candidate, $publishedOnly)
                && ($acceptCovered === null || $acceptCovered($candidate, $snapshot));
        };
        if ($fingerprint !== null) {
            foreach (ReleaseArticleFingerprintStore::candidates($fingerprint)->take(self::COVERAGE_CANDIDATES) as $candidate) {
                if ($verify($candidate)) {
                    return $candidate;
                }
            }
        }
        if ($this->proofBoundsExhausted(count($seen), $budget)) {
            return null;
        }
        foreach ($this->releaseDuplicateFinder->candidates($name, $searchName, $predbId, self::COVERAGE_CANDIDATES, $publishedOnly) as $candidate) {
            if ($verify($candidate)) {
                return $candidate;
            }
        }
        if ($totalSize > 0 && count($seen) < self::COVERAGE_CANDIDATES && $budget > 0) {
            $candidates = Release::query()->where('size', $totalSize)
                ->when($publishedOnly, static fn ($query) => $query->where('nzbstatus', NzbService::NZB_ADDED))->orderBy('id')
                ->limit(self::COVERAGE_CANDIDATES)->get(['id', 'guid', 'name', 'searchname', 'fromname', 'size']);
            foreach ($candidates as $candidate) {
                if ($verify($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function proofBoundsExhausted(int $candidates, int $decodedBytesRemaining): bool
    {
        return $candidates >= self::COVERAGE_CANDIDATES || $decodedBytesRemaining <= 0;
    }

    public function parseXml(string $contents): ?\SimpleXMLElement
    {
        if (strlen($contents) > self::COVERAGE_DECODED_BYTES || preg_match('/<!ENTITY\s/i', $contents)) {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($contents, \SimpleXMLElement::class, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $xml === false ? null : $xml;
    }

    public function coversXml(\SimpleXMLElement $incoming, string $guid): bool
    {
        $required = $this->coverageIds($incoming);
        $budget = self::COVERAGE_TOTAL_BYTES;

        return $required !== null && $this->coversIds($required, $guid, $budget);
    }

    /** Use one bounded retained generation for article and companion metadata evidence. */
    public function coveredSnapshot(\SimpleXMLElement $incoming, string $guid): ?\SimpleXMLElement
    {
        $required = $this->coverageIds($incoming);
        if ($required === null) {
            return null;
        }
        $used = 0;
        $contents = $this->nzb->readNzbContentsBounded($guid, self::COVERAGE_COMPRESSED_BYTES, self::COVERAGE_DECODED_BYTES, $used);
        if ($contents === false || ($xml = $this->parseXml($contents)) === null) {
            return null;
        }
        $available = $this->coverageIds($xml);

        return $available !== null && array_diff_key($required, $available) === [] ? $xml : null;
    }

    /** @param array<string, true> $required */
    private function coversIds(array $required, string $guid, int &$budget): bool
    {
        return $this->coveredIdsSnapshot($required, $guid, $budget) !== null;
    }

    /** @param array<string, true> $required */
    private function coveredIdsSnapshot(array $required, string $guid, int &$budget): ?\SimpleXMLElement
    {
        if ($budget <= 0) {
            return null;
        }
        $used = 0;
        $contents = $this->nzb->readNzbContentsBounded($guid, self::COVERAGE_COMPRESSED_BYTES, min($budget, self::COVERAGE_DECODED_BYTES), $used);
        $budget -= max(0, $used);
        if ($contents === false || ($xml = $this->parseXml($contents)) === null) {
            return null;
        }
        $available = $this->coverageIds($xml);

        return $available !== null && array_diff_key($required, $available) === [] ? $xml : null;
    }

    /** @phpstan-impure */
    private function isCurrentRelease(Release $candidate, bool $publishedOnly): bool
    {
        return Release::query()->where('id', $candidate->id)
            ->when($publishedOnly, static fn ($query) => $query->where('nzbstatus', NzbService::NZB_ADDED))
            ->value('guid') === (string) $candidate->guid;
    }

    /**
     * The exact article IDs a document requires, or null when it is not a well-formed NZB inventory.
     *
     * @return array<string, true>|null
     */
    public function coverageIds(\SimpleXMLElement $xml): ?array
    {
        $serialized = $xml->asXML();
        if ($serialized === false || strlen($serialized) > self::COVERAGE_DECODED_BYTES) {
            return null;
        }
        // The PHP XPath checks are document-absolute, even for attached subtrees.
        if (dom_import_simplexml($xml)->parentNode instanceof \DOMDocument) {
            try {
                $ffi = RustPipelineLibrary::resolveDomain('nzb_article_coverage', self::FFI_CDEF);
                if ($ffi !== null) {
                    $capacity = 0;
                    $scratch = RustPipelineLibrary::obtainScratch($ffi, strlen($serialized), $capacity);
                    if ($scratch !== null) {
                        // @phpstan-ignore method.notFound
                        $written = $ffi->nntmux_nzb_coverage_ids($serialized, strlen($serialized), $scratch, $capacity);
                        if (is_int($written) && $written < -2) {
                            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                            if ($scratch !== null) {
                                // @phpstan-ignore method.notFound
                                $written = $ffi->nntmux_nzb_coverage_ids($serialized, strlen($serialized), $scratch, $capacity);
                            }
                        }
                        if ($written === -2 || $written === 0) {
                            return null;
                        }
                        if (is_int($written) && $written > 0 && $written <= $capacity && $scratch !== null) {
                            $raw = FFI::string($scratch, $written);
                            $ids = [];
                            foreach (explode("\n", $raw) as $id) {
                                $ids["\0".$id] = true;
                            }

                            return $ids;
                        }
                    }
                }
            } catch (Throwable) {
                // Missing exports, disabled FFI and allocation failures use PHP.
            }
        }

        return $this->coverageIdsPhp($xml);
    }

    /** @return array<string, true>|null */
    public function coverageIdsPhp(\SimpleXMLElement $xml): ?array
    {
        $serialized = $xml->asXML();
        if ($serialized === false || strlen($serialized) > self::COVERAGE_DECODED_BYTES) {
            return null;
        }
        try {
            $elements = new NzbXmlElements($xml);
        } catch (\RuntimeException) {
            return null;
        }
        $files = [];
        foreach ($elements->files() as $file) {
            $files[] = $file;
        }
        if ($files === [] || count($xml->xpath('/*/*[local-name()="file"]') ?: []) !== count($files)) {
            return null;
        }
        $ids = [];
        $segments = 0;
        foreach ($files as $file) {
            $fileSegments = [];
            foreach ($elements->segments($file) as $segment) {
                $fileSegments[] = $segment;
            }
            if (count($file->xpath('./*[local-name()="segments"]') ?: []) !== 1 || $fileSegments === []
                || count($file->xpath('./*[local-name()="segments"]/*') ?: []) !== count($fileSegments)) {
                return null;
            }
            foreach ($fileSegments as $segment) {
                $attributes = $segment->attributes();
                $number = (string) $attributes['number'];
                $bytes = (string) $attributes['bytes'];
                if (! preg_match('/^[1-9][0-9]*$/D', $number) || ! ctype_digit($bytes) || dom_import_simplexml($segment)->childElementCount !== 0) {
                    return null;
                }
                $id = NzbArticleFingerprint::validatedMessageId((string) $segment);
                if ($id === null || ++$segments > self::COVERAGE_IDS) {
                    return null;
                }
                $ids["\0".$id] = true;
            }
        }

        return $ids;
    }
}
