<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\Media;

use App\Services\AdditionalProcessing\DTO\MappedNzbFile;
use App\Services\AdditionalProcessing\DTO\Par2FileEntry;
use App\Services\AdditionalProcessing\Enums\MappingConfidence;

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

    /**
     * @param  array<int|string, mixed>  $nzbContents
     * @param  list<Par2FileEntry>  $files
     * @param  array<int, Par2FileEntry>  $verified  files whose start was already matched, keyed by source index
     * @return array<int, MappedNzbFile> keyed by source index
     */
    public function map(array $nzbContents, array $files, ?int $articlePartSize = null, array $verified = []): array
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
