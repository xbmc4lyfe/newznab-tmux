<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Models\Release;
use App\Services\Releases\ReleaseDuplicateFinder;

/** Proves single-release article coverage without metadata-only suppression. */
final class NzbArticleCoverage
{
    public function __construct(private readonly NzbService $nzb, private readonly ReleaseDuplicateFinder $releaseDuplicateFinder) {}

    private const int COVERAGE_CANDIDATES = 128;

    private const int COVERAGE_COMPRESSED_BYTES = 4_194_304;

    private const int COVERAGE_DECODED_BYTES = 16_777_216;

    private const int COVERAGE_TOTAL_BYTES = 33_554_432;

    private const int COVERAGE_IDS = 100_000;

    /**
     * Stored fingerprints and metadata only nominate uploads. Every suppression
     * requires a complete readable NZB covering the incoming set by exact ID bytes.
     * Bounds limit comparison work, never whether an incoming NZB may be imported.
     *
     * @param  (callable(): void)|null  $onCandidate
     */
    public function findCoveredUpload(int $totalSize, ?string $fingerprint, ?\SimpleXMLElement $incoming = null, string $name = '', string $searchName = '', int $predbId = 0, bool $publishedOnly = false, ?callable $onCandidate = null): ?Release
    {
        if ($incoming === null || ($required = $this->coverageIds($incoming)) === null) {
            return null;
        }
        $seen = [];
        $budget = self::COVERAGE_TOTAL_BYTES;
        $verify = function (Release $candidate) use (&$seen, &$budget, $required, $publishedOnly, $onCandidate): bool {
            if ((string) $candidate->guid === '' || isset($seen[$candidate->id]) || count($seen) >= self::COVERAGE_CANDIDATES || $budget <= 0) {
                return false;
            }
            $seen[$candidate->id] = true;
            if ($onCandidate !== null) {
                $onCandidate();
            }
            if (! $this->isCurrentRelease($candidate, $publishedOnly)) {
                return false;
            }

            return $this->coversIds($required, (string) $candidate->guid, $budget)
                && $this->isCurrentRelease($candidate, $publishedOnly);
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

    /** @param array<string, true> $required */
    private function coversIds(array $required, string $guid, int &$budget): bool
    {
        if ($budget <= 0) {
            return false;
        }
        $used = 0;
        $contents = $this->nzb->readNzbContentsBounded($guid, self::COVERAGE_COMPRESSED_BYTES, min($budget, self::COVERAGE_DECODED_BYTES), $used);
        $budget -= max(0, $used);
        if ($contents === false || ($xml = $this->parseXml($contents)) === null) {
            return false;
        }
        $available = $this->coverageIds($xml);

        return $available !== null && array_diff_key($required, $available) === [];
    }

    /** @phpstan-impure */
    private function isCurrentRelease(Release $candidate, bool $publishedOnly): bool
    {
        return Release::query()->where('id', $candidate->id)
            ->when($publishedOnly, static fn ($query) => $query->where('nzbstatus', NzbService::NZB_ADDED))
            ->value('guid') === (string) $candidate->guid;
    }

    /** @return array<string, true>|null */
    private function coverageIds(\SimpleXMLElement $xml): ?array
    {
        $serialized = $xml->asXML();
        $namespace = $xml->getDocNamespaces()[''] ?? '';
        if ($serialized === false || strlen($serialized) > self::COVERAGE_DECODED_BYTES
            || $xml->getName() !== 'nzb' || ! in_array($namespace, ['', 'http://www.newzbin.com/DTD/2003/nzb'], true)
            || count($xml->file) === 0 || count($xml->xpath('/*/*[local-name()="file"]') ?: []) !== count($xml->file)) {
            return null;
        }
        $ids = [];
        $segments = 0;
        foreach ($xml->file as $file) {
            if (count($file->segments) !== 1 || count($file->xpath('./*[local-name()="segments"]') ?: []) !== 1 || count($file->segments->segment) === 0
                || count($file->xpath('./*[local-name()="segments"]/*') ?: []) !== count($file->segments->segment)) {
                return null;
            }
            foreach ($file->segments->segment as $segment) {
                $number = (string) $segment['number'];
                $bytes = (string) $segment['bytes'];
                if (! preg_match('/^[1-9][0-9]*$/D', $number) || ! ctype_digit($bytes) || count($segment->xpath('./*') ?: []) !== 0) {
                    return null;
                }
                $id = NzbArticleFingerprint::validatedMessageId((string) $segment);
                if ($id === null || ++$segments > self::COVERAGE_IDS) {
                    return null;
                }
                $ids["\0".$id] = true;
            }
        }

        return $ids === [] ? null : $ids;
    }
}
