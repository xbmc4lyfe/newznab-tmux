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

    /**
     * Default for the Wave 41.2 toggle only; it stays false because the paired benchmark in
     * tests/Unit/RustNzbSyncNfoImportCoverageCandidateW41ParityTest.php is below 1.5x.
     */
    public const bool W412_RUST_FFI_DEFAULT = false;

    private static ?bool $useW412RustFfi = null;

    public static function useW412RustFfi(bool $enable = true): void
    {
        self::$useW412RustFfi = $enable;
    }

    public static function usesW412RustFfi(): bool
    {
        return self::$useW412RustFfi ?? self::W412_RUST_FFI_DEFAULT;
    }

    public static function resetW412RustFfi(): void
    {
        self::$useW412RustFfi = null;
    }

    /**
     * Summarize article coverage candidates (`required`, `covered`). A candidate is invalid unless `required` is a
     * positive integer; `covered` counts only as an integer, clamped to [0, required]. Valid candidates are full,
     * partial or none, and acceptable when at most max(0, $tolerance) articles are missing. The required and missing
     * totals saturate at PHP_INT_MAX.
     *
     * @param  array<array-key, mixed>  $candidates
     * @return array{candidates: int, full: int, partial: int, none: int, invalid: int, acceptable: int, total_required: int, total_missing: int, max_missing: int}
     */
    public static function summarizeCoverageCandidates(array $candidates, int $tolerance = 0): array
    {
        return (self::usesW412RustFfi() ? self::summarizeCoverageCandidatesNative($candidates, $tolerance) : null)
            ?? self::summarizeCoverageCandidatesPhp($candidates, $tolerance);
    }

    /**
     * @param  array<array-key, mixed>  $candidates
     * @return array{candidates: int, full: int, partial: int, none: int, invalid: int, acceptable: int, total_required: int, total_missing: int, max_missing: int}
     */
    public static function summarizeCoverageCandidatesPhp(array $candidates, int $tolerance = 0): array
    {
        $tolerance = max(0, $tolerance);
        $full = $partial = $none = $invalid = $acceptable = $required = $missing = $maxMissing = 0;
        foreach ($candidates as $candidate) {
            [$need, $covered] = self::coverageCandidateFields($candidate);
            if ($need <= 0) {
                $invalid++;

                continue;
            }
            $covered = min(max(0, $covered), $need);
            // 0 <= covered <= need, so the subtraction cannot overflow.
            $gap = $need - $covered;
            if ($gap === 0) {
                $full++;
            } elseif ($covered === 0) {
                $none++;
            } else {
                $partial++;
            }
            $acceptable += $gap <= $tolerance ? 1 : 0;
            $required = $required > PHP_INT_MAX - $need ? PHP_INT_MAX : $required + $need;
            $missing = $missing > PHP_INT_MAX - $gap ? PHP_INT_MAX : $missing + $gap;
            $maxMissing = max($maxMissing, $gap);
        }

        return ['candidates' => \count($candidates), 'full' => $full, 'partial' => $partial, 'none' => $none, 'invalid' => $invalid,
            'acceptable' => $acceptable, 'total_required' => $required, 'total_missing' => $missing, 'max_missing' => $maxMissing];
    }

    /**
     * @param  array<array-key, mixed>  $candidates
     * @return array{candidates: int, full: int, partial: int, none: int, invalid: int, acceptable: int, total_required: int, total_missing: int, max_missing: int}|null
     */
    public static function summarizeCoverageCandidatesNative(array $candidates, int $tolerance = 0): ?array
    {
        if (\count($candidates) * 2 + 1 > DirectorySyncBridge::W412_MAX_INPUT / 8) {
            return null;
        }
        $words = [$tolerance];
        foreach ($candidates as $candidate) {
            array_push($words, ...self::coverageCandidateFields($candidate));
        }
        $slots = DirectorySyncBridge::evaluateW412Native(2, pack('q*', ...$words), 9);
        if ($slots === null) {
            return null;
        }
        [$count, $full, $partial, $none, $invalid, $acceptable, $required, $missing, $maxMissing] = $slots;
        if ($count !== \count($candidates) || min($slots) < 0 || $full + $partial + $none + $invalid !== $count || $acceptable > $count - $invalid
            || $maxMissing > $missing) {
            return null;
        }

        return ['candidates' => $count, 'full' => $full, 'partial' => $partial, 'none' => $none, 'invalid' => $invalid,
            'acceptable' => $acceptable, 'total_required' => $required, 'total_missing' => $missing, 'max_missing' => $maxMissing];
    }

    /** @return array{int, int} the required and covered counts, each 0 unless an integer */
    private static function coverageCandidateFields(mixed $candidate): array
    {
        if (! \is_array($candidate)) {
            return [0, 0];
        }
        $required = $candidate['required'] ?? 0;
        $covered = $candidate['covered'] ?? 0;

        return [\is_int($required) ? $required : 0, \is_int($covered) ? $covered : 0];
    }
}
