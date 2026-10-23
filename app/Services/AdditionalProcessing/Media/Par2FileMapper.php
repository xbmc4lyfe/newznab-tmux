<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\Media;

use App\Services\AdditionalProcessing\DTO\MappedNzbFile;
use App\Services\AdditionalProcessing\DTO\Par2FileEntry;
use App\Services\AdditionalProcessing\Enums\MappingConfidence;
use App\Support\RustPipelineLibrary;
use FFI;

/**
 * Matches the files of an NZB to the real names in its PAR2 index.
 *
 * An NZB only knows the posted size of each file: its yEnc-encoded articles,
 * a few percent larger than the file itself. A PAR2 entry is matched to an NZB
 * file when that file is the only one whose posted size fits it, and it is the
 * only entry that fits the file. Equal-sized archive volumes stay unmatched
 * unless their first 16 KiB was checked against the index.
 */
final readonly class Par2FileMapper
{
    /** Smallest real size, relative to the posted size, still taken as the same file. */
    private const float MIN_SIZE_RATIO = 0.90;

    /** Posted sizes are sometimes the decoded size; allow a little rounding above it. */
    private const float MAX_SIZE_RATIO = 1.001;

    private const string FFI_CDEF = <<<'C'
        ssize_t nntmux_par2_map_files(const char *sources, size_t sources_len, const char *files, size_t files_len, const char *verified, size_t verified_len, int64_t article_part_size, char *out_buf, size_t out_cap);
        ssize_t nntmux_par2_candidate_indexes(const char *sources, size_t sources_len, int64_t file_size, int64_t article_part_size, char *out_buf, size_t out_cap);
        ssize_t nntmux_nzb_extract_message_ids_nul(const char *group, size_t group_len, const char *titles, size_t titles_len, const char *seg_flags, size_t seg_flags_len, size_t count, uint32_t config_flags, char *out_buf, size_t out_cap);
        ssize_t nntmux_nzb_source_file_inventory(const char *xml, size_t xml_len, size_t expected_file_count, char *out_buf, size_t out_cap);
        C;

    public function __construct(
        private ?string $libraryPath = null,
    ) {}

    /**
     * @param  array<int|string, mixed>  $nzbContents
     * @param  list<Par2FileEntry>  $files
     * @param  array<int, Par2FileEntry>  $verified  files whose start was already matched, keyed by source index
     * @return array<int, MappedNzbFile> keyed by source index
     */
    public function map(array $nzbContents, array $files, ?int $articlePartSize = null, array $verified = []): array
    {
        $ffi = RustPipelineLibrary::resolveDomain('additional_nzb_par2', self::FFI_CDEF, $this->libraryPath);
        if ($ffi !== null) {
            $rustMapped = $this->mapRust($ffi, $nzbContents, $files, $articlePartSize, $verified);
            if ($rustMapped !== null) {
                return $rustMapped;
            }
        }

        return $this->mapPhp($nzbContents, $files, $articlePartSize, $verified);
    }

    /**
     * @param  array<int|string, mixed>  $nzbContents
     * @param  list<Par2FileEntry>  $files
     * @param  array<int, Par2FileEntry>  $verified
     * @return array<int, MappedNzbFile>
     */
    public function mapPhp(array $nzbContents, array $files, ?int $articlePartSize = null, array $verified = []): array
    {
        $mapped = [];
        foreach ($verified as $sourceIndex => $file) {
            $mapped[$sourceIndex] = new MappedNzbFile($sourceIndex, $file, MappingConfidence::Verified);
        }

        $remainingFiles = array_values(array_filter(
            $files,
            static fn (Par2FileEntry $file): bool => ! in_array($file, $verified, true),
        ));

        if ($remainingFiles !== []) {
            $postedSizes = [];
            $segmentCounts = [];
            foreach (array_values($nzbContents) as $sourceIndex => $entry) {
                if (! $this->isSingleFile($entry)) {
                    continue;
                }
                if (isset($mapped[$sourceIndex])) {
                    continue;
                }
                $postedSizes[$sourceIndex] = (int) $entry['size'];
                $segmentCounts[$sourceIndex] = count($entry['segments']);
            }

            if ($postedSizes !== []) {
                asort($postedSizes, SORT_NUMERIC);
                $sortedSources = array_keys($postedSizes);
                $sortedSizes = array_values($postedSizes);
                $entryCount = count($sortedSizes);

                $checkSegments = $articlePartSize !== null && $articlePartSize > 0;
                $fileFitCounts = [];
                $fileFirstSource = [];
                $entryFitCounts = [];

                foreach ($remainingFiles as $fileIndex => $file) {
                    $fileSize = $file->size;
                    if ($fileSize <= 0) {
                        continue;
                    }
                    $expectedSegments = $checkSegments ? (int) ceil($fileSize / $articlePartSize) : null;
                    $minPosted = $fileSize - ($fileSize >> 9);
                    $low = 0;
                    $high = $entryCount;
                    while ($low < $high) {
                        $mid = ($low + $high) >> 1;
                        if ($sortedSizes[$mid] < $minPosted) {
                            $low = $mid + 1;
                        } else {
                            $high = $mid;
                        }
                    }

                    for ($index = $low; $index < $entryCount; $index++) {
                        $postedSize = $sortedSizes[$index];
                        $ratio = $fileSize / $postedSize;
                        if ($ratio < self::MIN_SIZE_RATIO) {
                            break;
                        }
                        if ($ratio > self::MAX_SIZE_RATIO) {
                            continue;
                        }
                        $sourceIndex = $sortedSources[$index];
                        if ($expectedSegments !== null && $expectedSegments !== $segmentCounts[$sourceIndex]) {
                            continue;
                        }
                        $fitCount = ($fileFitCounts[$fileIndex] ?? 0) + 1;
                        $fileFitCounts[$fileIndex] = $fitCount;
                        if ($fitCount === 1) {
                            $fileFirstSource[$fileIndex] = $sourceIndex;
                        }
                        $entryFitCounts[$sourceIndex] = ($entryFitCounts[$sourceIndex] ?? 0) + 1;
                    }
                }

                foreach ($fileFitCounts as $fileIndex => $fitCount) {
                    if ($fitCount === 1) {
                        $sourceIndex = $fileFirstSource[$fileIndex];
                        if ($entryFitCounts[$sourceIndex] === 1) {
                            $mapped[$sourceIndex] = new MappedNzbFile($sourceIndex, $remainingFiles[$fileIndex], MappingConfidence::UniqueSize);
                        }
                    }
                }
            }
        }

        ksort($mapped);

        return $mapped;
    }

    /**
     * NZB files whose posted size, and segment count when the article size is known, fit a PAR2 entry.
     *
     * @param  array<int|string, mixed>  $nzbContents
     * @return list<int> source indexes
     */
    public function candidateIndexes(array $nzbContents, Par2FileEntry $file, ?int $articlePartSize = null): array
    {
        $ffi = RustPipelineLibrary::resolveDomain('additional_nzb_par2', self::FFI_CDEF, $this->libraryPath);
        if ($ffi !== null) {
            $rustIndexes = $this->candidateIndexesRust($ffi, $nzbContents, $file, $articlePartSize);
            if ($rustIndexes !== null) {
                return $rustIndexes;
            }
        }

        return $this->candidateIndexesPhp($nzbContents, $file, $articlePartSize);
    }

    /**
     * @param  array<int|string, mixed>  $nzbContents
     * @return list<int>
     */
    public function candidateIndexesPhp(array $nzbContents, Par2FileEntry $file, ?int $articlePartSize = null): array
    {
        $indexes = [];
        foreach (array_values($nzbContents) as $sourceIndex => $entry) {
            if (! $this->isSingleFile($entry)) {
                continue;
            }

            $postedSize = (int) $entry['size'];
            $ratio = $file->size / $postedSize;
            if ($ratio < self::MIN_SIZE_RATIO || $ratio > self::MAX_SIZE_RATIO) {
                continue;
            }

            if ($articlePartSize !== null && $articlePartSize > 0
                && (int) ceil($file->size / $articlePartSize) !== count($entry['segments'])
            ) {
                continue;
            }

            $indexes[] = $sourceIndex;
        }

        return $indexes;
    }

    /**
     * @param  array<int|string, mixed>  $nzbContents
     * @param  list<Par2FileEntry>  $files
     * @param  array<int, Par2FileEntry>  $verified
     * @return array<int, MappedNzbFile>|null
     */
    private function mapRust(FFI $ffi, array $nzbContents, array $files, ?int $articlePartSize, array $verified): ?array
    {
        $mapped = [];
        foreach ($verified as $sourceIndex => $file) {
            $mapped[$sourceIndex] = new MappedNzbFile($sourceIndex, $file, MappingConfidence::Verified);
        }

        $remainingFiles = $verified === []
            ? array_values($files)
            : array_values(array_filter(
                $files,
                static fn (Par2FileEntry $file): bool => ! in_array($file, $verified, true),
            ));

        if ($remainingFiles !== []) {
            $packedSources = $this->packSources($nzbContents);
            if ($packedSources !== '') {
                $fileSizes = [];
                foreach ($remainingFiles as $file) {
                    $fileSizes[] = $file->size;
                }
                $packedFiles = pack('q*', ...$fileSizes);
                $packedVerified = $verified === [] ? '' : pack('q*', ...array_keys($verified));

                $needed = count($remainingFiles) * 8 + 16;
                $cap = 0;
                $scratch = RustPipelineLibrary::obtainScratch($ffi, $needed, $cap);
                if ($scratch === null) {
                    return null;
                }

                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_par2_map_files(
                    $packedSources,
                    strlen($packedSources),
                    $packedFiles,
                    strlen($packedFiles),
                    $packedVerified,
                    strlen($packedVerified),
                    $articlePartSize ?? 0,
                    $scratch,
                    $cap,
                );
                if (! is_int($written) || $written === -1) {
                    return null;
                }
                if ($written < -1) {
                    $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                    if ($scratch === null) {
                        return null;
                    }
                    // @phpstan-ignore method.notFound
                    $written = $ffi->nntmux_par2_map_files(
                        $packedSources,
                        strlen($packedSources),
                        $packedFiles,
                        strlen($packedFiles),
                        $packedVerified,
                        strlen($packedVerified),
                        $articlePartSize ?? 0,
                        $scratch,
                        $cap,
                    );
                    if (! is_int($written) || $written < 0) {
                        return null;
                    }
                }

                if ($written > 0) {
                    /** @var array<int, int>|false $pairs */
                    $pairs = unpack('L*', FFI::string($scratch, $written));
                    if ($pairs === false) {
                        return null;
                    }
                    $pairLen = count($pairs);
                    for ($i = 1; $i <= $pairLen; $i += 2) {
                        $sourceIndex = $pairs[$i];
                        $fileIndex = $pairs[$i + 1];
                        $mapped[$sourceIndex] = new MappedNzbFile(
                            $sourceIndex,
                            $remainingFiles[$fileIndex],
                            MappingConfidence::UniqueSize,
                        );
                    }
                }
            }
        }

        if ($verified !== []) {
            ksort($mapped);
        }

        return $mapped;
    }

    /**
     * @param  array<int|string, mixed>  $nzbContents
     * @return list<int>|null
     */
    private function candidateIndexesRust(FFI $ffi, array $nzbContents, Par2FileEntry $file, ?int $articlePartSize): ?array
    {
        $packedSources = $this->packSources($nzbContents);
        if ($packedSources === '' || $file->size <= 0) {
            return [];
        }

        $needed = intdiv(strlen($packedSources), 24) * 4 + 16;
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, $needed, $cap);
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_par2_candidate_indexes(
            $packedSources,
            strlen($packedSources),
            $file->size,
            $articlePartSize ?? 0,
            $scratch,
            $cap,
        );
        if (! is_int($written) || $written === -1) {
            return null;
        }
        if ($written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_par2_candidate_indexes(
                $packedSources,
                strlen($packedSources),
                $file->size,
                $articlePartSize ?? 0,
                $scratch,
                $cap,
            );
            if (! is_int($written) || $written < 0) {
                return null;
            }
        }
        if ($written === 0) {
            return [];
        }

        /** @var array<int, int>|false $unpacked */
        $unpacked = unpack('L*', FFI::string($scratch, $written));

        return $unpacked === false ? null : array_values($unpacked);
    }

    /**
     * @param  array<int|string, mixed>  $nzbContents
     */
    private function packSources(array $nzbContents): string
    {
        /** @var list<array{0: array<int|string, mixed>, 1: string}> $cache */
        static $cache = [];
        foreach ($cache as [$cachedNzb, $cachedPacked]) {
            if ($cachedNzb === $nzbContents) {
                return $cachedPacked;
            }
        }

        $flat = [];
        foreach (array_values($nzbContents) as $sourceIndex => $entry) {
            if (! $this->isSingleFile($entry)) {
                continue;
            }
            $flat[] = $sourceIndex;
            $flat[] = (int) $entry['size'];
            $flat[] = count($entry['segments']);
        }

        $packed = $flat === [] ? '' : pack('q*', ...$flat);
        if (count($cache) >= 8) {
            array_shift($cache);
        }
        $cache[] = [$nzbContents, $packed];

        return $packed;
    }

    /**
     * Stripped subjects can merge several NZB files into one entry, whose size is then wrong.
     *
     * @phpstan-assert-if-true array{size: int|string, segments: non-empty-array<int|string, mixed>} $entry
     */
    private function isSingleFile(mixed $entry): bool
    {
        return is_array($entry)
            && (int) ($entry['filecount'] ?? 1) === 1
            && (int) ($entry['size'] ?? 0) > 0
            && is_array($entry['segments'] ?? null)
            && $entry['segments'] !== [];
    }
}
