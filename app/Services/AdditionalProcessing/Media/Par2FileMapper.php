<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\Media;

use App\Services\AdditionalProcessing\DTO\MappedNzbFile;
use App\Services\AdditionalProcessing\DTO\Par2FileEntry;
use App\Services\AdditionalProcessing\Enums\MappingConfidence;
use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

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
    public const bool W44_RUST_FFI_DEFAULT = false;

    /** @return array{enabled: ?bool, libraries: array<string, FFI|null>} */
    private static function &w44State(): array
    {
        static $state = ['enabled' => null, 'libraries' => []];

        return $state;
    }

    public static function useRustFfi(?bool $enabled): void
    {
        self::w44State()['enabled'] = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::w44State()['enabled'] ?? self::W44_RUST_FFI_DEFAULT;
    }

    private function w44Ffi(): ?FFI
    {
        $state = &self::w44State();
        $key = $this->libraryPath === null ? 'default' : 'path:'.$this->libraryPath;
        if (! array_key_exists($key, $state['libraries'])) {
            $state['libraries'][$key] = RustPipelineLibrary::resolveDomain('w44_par2_mapper', self::FFI_CDEF, $this->libraryPath);
        }

        return $state['libraries'][$key];
    }

    /** Smallest real size, relative to the posted size, still taken as the same file. */
    private const float MIN_SIZE_RATIO = 0.90;

    /** Posted sizes are sometimes the decoded size; allow a little rounding above it. */
    private const float MAX_SIZE_RATIO = 1.001;

    private const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w44_par2_map_files(const char *sources, size_t sources_len, const char *files, size_t files_len, const char *verified, size_t verified_len, int64_t article_part_size, char *out_buf, size_t out_cap);
        ssize_t nntmux_w44_par2_candidate_indexes(const char *sources, size_t sources_len, int64_t file_size, int64_t article_part_size, char *out_buf, size_t out_cap);
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
        return self::usesRustFfi()
            ? $this->mapNative($nzbContents, $files, $articlePartSize, $verified)
            : $this->mapPhp($nzbContents, $files, $articlePartSize, $verified);
    }

    /**
     * @param  array<int|string, mixed>  $nzbContents
     * @param  list<Par2FileEntry>  $files
     * @param  array<int, Par2FileEntry>  $verified
     * @return array<int, MappedNzbFile>
     */
    public function mapNative(array $nzbContents, array $files, ?int $articlePartSize = null, array $verified = [], bool &$supported = false): array
    {
        $supported = false;
        $ffi = $this->w44Ffi();
        if ($ffi !== null) {
            try {
                $mapped = $this->mapRust($ffi, $nzbContents, $files, $articlePartSize, $verified);
                if ($mapped !== null) {
                    $supported = true;

                    return $mapped;
                }
            } catch (Throwable) {
                // An unavailable or incompatible accelerator retains PHP behavior.
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
        return self::usesRustFfi()
            ? $this->candidateIndexesNative($nzbContents, $file, $articlePartSize)
            : $this->candidateIndexesPhp($nzbContents, $file, $articlePartSize);
    }

    /**
     * @param  array<int|string, mixed>  $nzbContents
     * @return list<int>
     */
    public function candidateIndexesNative(array $nzbContents, Par2FileEntry $file, ?int $articlePartSize = null, bool &$supported = false): array
    {
        $supported = false;
        $ffi = $this->w44Ffi();
        if ($ffi !== null) {
            try {
                $indexes = $this->candidateIndexesRust($ffi, $nzbContents, $file, $articlePartSize);
                if ($indexes !== null) {
                    $supported = true;

                    return $indexes;
                }
            } catch (Throwable) {
                // An unavailable or incompatible accelerator retains PHP behavior.
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
                $written = $ffi->nntmux_w44_par2_map_files(
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
                    $written = $ffi->nntmux_w44_par2_map_files(
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
        $written = $ffi->nntmux_w44_par2_candidate_indexes(
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
            $written = $ffi->nntmux_w44_par2_candidate_indexes(
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

    public const string W52_FFI_CDEF = 'int64_t nntmux_w525_par2_candidate_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W52.5 parity test's QUALIFICATION). */
    private const bool W52_RUST_FFI_DEFAULT = true;

    /**
     * Wave 52.5 routing and FFI cache, kept in function-local statics so readonly classes can use them too.
     * Without $op it reports the routing; 'set' swaps the override for $val and returns the previous one;
     * 'reset' replaces the cached handle with $val (null re-resolves, false disables); 'buffer' returns
     * `[FFI, int64_t[], capacity]` with room for at least $val words, or null without the library.
     */
    private static function w52RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'reset':
                $ffi = $val;
                $out = null;
                $outCap = 0;

                return null;
            case 'buffer':
                if ($ffi === null) {
                    $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
                    $ffi = RustPipelineLibrary::resolveDomain('w52_5', self::W52_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
                }
                if (! $ffi instanceof FFI || ! \is_int($val)) {
                    return null;
                }
                if ($out === null || $outCap < $val) {
                    $outCap = max(1024, $val);
                    $out = $ffi->new('int64_t['.$outCap.']');
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled ?? self::W52_RUST_FFI_DEFAULT;
        }
    }

    public static function usesW52RustFfi(): bool
    {
        return self::w52RustFfiState() === true;
    }

    /**
     * Runs $callback with candidatePlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW52RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w52RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w52RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 52.5 Rust helper, or null to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w52Words(int $count, string $data, string $ints, int $words): ?array
    {
        try {
            $handle = self::w52RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w525_par2_candidate_plan($count, 0, $data, \strlen($data), $ints, \strlen($ints), $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('P*', FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Batch candidateIndexesPhp() summary for every PAR2 entry at once. Three words per entry:
     * `[candidate count, first candidate source index, last candidate source index]`, the indexes -1
     * without a candidate.
     *
     * @param  array<int|string, mixed>  $nzbContents
     * @param  list<Par2FileEntry>  $files
     * @return list<int>
     */
    public static function candidatePlanBatch(array $nzbContents, array $files, ?int $articlePartSize = null): array
    {
        return self::usesW52RustFfi()
            ? self::candidatePlanBatchNative($nzbContents, $files, $articlePartSize)
            : self::candidatePlanBatchPhp($nzbContents, $files, $articlePartSize);
    }

    /**
     * @param  array<int|string, mixed>  $nzbContents
     * @param  list<Par2FileEntry>  $files
     * @return list<int>
     */
    public static function candidatePlanBatchPhp(array $nzbContents, array $files, ?int $articlePartSize = null): array
    {
        $sources = self::w52Sources($nzbContents);
        $checkSegments = $articlePartSize !== null && $articlePartSize > 0;
        $out = [];
        foreach ($files as $file) {
            $found = 0;
            $first = $last = -1;
            for ($index = 0, $end = \count($sources); $index < $end; $index += 3) {
                $ratio = $file->size / $sources[$index + 1];
                if ($ratio < self::MIN_SIZE_RATIO || $ratio > self::MAX_SIZE_RATIO) {
                    continue;
                }
                if ($checkSegments && (int) ceil($file->size / $articlePartSize) !== $sources[$index + 2]) {
                    continue;
                }
                $found++;
                if ($first < 0) {
                    $first = $sources[$index];
                }
                $last = $sources[$index];
            }
            array_push($out, $found, $first, $last);
        }

        return $out;
    }

    /**
     * Rust batch over `[part size, source count, (index, posted size, segments)…, file size…]`; falls back to
     * PHP without the library or when a size exceeds 2^53 (where PHP's float division is not exact).
     *
     * @param  array<int|string, mixed>  $nzbContents
     * @param  list<Par2FileEntry>  $files
     * @return list<int>
     */
    public static function candidatePlanBatchNative(array $nzbContents, array $files, ?int $articlePartSize = null): array
    {
        $count = \count($files);
        if ($count === 0) {
            return [];
        }
        $sources = self::w52Sources($nzbContents);
        $ints = pack('P*', $articlePartSize ?? 0, intdiv(\count($sources), 3), ...$sources, ...array_column($files, 'size'));

        return self::w52Words($count, '', $ints, 3 * $count)
            ?? self::candidatePlanBatchPhp($nzbContents, $files, $articlePartSize);
    }

    /**
     * Source index, posted size and segment count of every single-file NZB entry, flattened.
     *
     * @param  array<int|string, mixed>  $nzbContents
     * @return list<int>
     */
    private static function w52Sources(array $nzbContents): array
    {
        $sources = [];
        foreach (array_values($nzbContents) as $sourceIndex => $entry) {
            if (\is_array($entry) && (int) ($entry['filecount'] ?? 1) === 1 && (int) ($entry['size'] ?? 0) > 0
                && \is_array($entry['segments'] ?? null) && $entry['segments'] !== []) {
                array_push($sources, $sourceIndex, (int) $entry['size'], \count($entry['segments']));
            }
        }

        return $sources;
    }
}
