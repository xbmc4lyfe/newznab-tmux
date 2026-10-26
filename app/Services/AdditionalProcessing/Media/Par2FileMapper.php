<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\Media;

use App\Services\AdditionalProcessing\DTO\MappedNzbFile;
use App\Services\AdditionalProcessing\DTO\Par2FileEntry;
use App\Services\AdditionalProcessing\Enums\MappingConfidence;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
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

    // ========================================================================
    // WAVE 65.1 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 3.99x, p99 3.64x; uncommon mean 4.49x, p99 3.84x); see RustAdditionalMediaStageToolPar2MapperThumbExtractionW65ParityTest. */
    public const bool W65_RUST_FFI_DEFAULT = true;

    public const string W65_PAR2_FILE_MAPPER_FFI_CDEF = <<<'C'
        ssize_t nntmux_w651_par2_file_mapper_plan_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of w65Par2FileMapperPlanBatch(). */
    public const int W65_PAR2_FILE_MAPPER_TRAILER = 37;

    public static function usesW65RustFfi(): bool
    {
        $override = self::w65RustFfiState();

        return \is_bool($override) ? $override : self::W65_RUST_FFI_DEFAULT;
    }

    /** Forces the Par2FileMapper Wave 65 Rust path on until reset. */
    public static function enableW65RustFfi(): void
    {
        self::w65RustFfiState('set', true);
    }

    /** Forces the Par2FileMapper Wave 65 PHP path until reset. */
    public static function disableW65RustFfi(): void
    {
        self::w65RustFfiState('set', false);
    }

    /** Restores the benchmark default and drops the cached library handle and scratch buffers; earlier toggles are untouched. */
    public static function resetW65RustFfi(): void
    {
        self::w65RustFfiState('reset');
    }

    /**
     * Runs $callback with the Par2FileMapper Wave 65 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW65RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w65RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w65RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 65.1 PAR2 mapping plans, one per PAR2 file description `hash16k|name|size|subject|bytes|segments` (trimmed fields;
     * numbers are 1-15 digits, empty 0). The NZB file name is the first non-empty `"..."` run of the subject, else the whole
     * subject. Confidence: 3 exact name, 2 case-insensitive name, 1 same name without extension, 0 none. Size state: 0
     * unknown, 1 encoded bytes within 5% plus 4 KiB above the PAR2 size, 2 smaller, 3 larger. Media role from the PAR2 name:
     * 0 none, 1 video, 2 audio, 3 image, 4 archive volume, 5 par2, 6 nfo, 7 subtitle, 8 other. Decision: 0 empty row, 1
     * malformed (bad number or empty name), 2 hash not 32 hex digits, 3 hash repeated in the batch, 4 unmatched, 5 size
     * mismatch, 6 weak mapping, 7 mapped. Flags: 1 quoted subject, 2 non-ASCII name, 4 control byte in the name, 8 path
     * separator, 16 uppercase hash, 32 sample, 64 name repeated (case-insensitive), 128 no segments. Per item `decision |
     * confidence << 3 | sizeState << 5 | role << 7 | flags << 11 | bitLength(size) << 19 | bitLength(bytes) << 25 |
     * min(segments, 4095) << 31 | (fnv1a32(lowercase name) & 0xFFF) << 43 | min(nameLength, 255) << 55`; then 8 decision, 4
     * confidence, 4 size state, 9 role and 8 flag counts, the mapped byte and segment sums, the distinct hashes and names.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w65Par2FileMapperPlanBatch(array $items): array
    {
        return self::usesW65RustFfi() ? self::w65Par2FileMapperPlanBatchNative($items) : self::w65Par2FileMapperPlanBatchPhp($items);
    }

    /**
     * Rust plan; falls back to the PHP reference when the library is missing or the call declines.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w65Par2FileMapperPlanBatchNative(array $items): array
    {
        return self::w65Invoke($items, \count($items) + self::W65_PAR2_FILE_MAPPER_TRAILER) ?? self::w65Par2FileMapperPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w65Par2FileMapperPlanBatchPhp(array $items): array
    {
        $out = [];
        $tail = array_fill(0, self::W65_PAR2_FILE_MAPPER_TRAILER, 0);
        $hashes = [];
        $names = [];
        foreach ($items as $item) {
            $item = (string) $item;
            [$rawHash, $rawName, $rawSize, $rawSubject, $rawBytes, $rawSegments] = explode('|', $item, 6) + ['', '', '', '', '', ''];
            $hashRaw = trim($rawHash);
            $hash = strtolower($hashRaw);
            $validHash = \strlen($hash) === 32 && strspn($hash, '0123456789abcdef') === 32;
            $name = trim($rawName);
            $nameLength = \strlen($name);
            $size = self::w65Uint($rawSize, 0);
            $subject = trim($rawSubject);
            $target = $subject;
            $quoted = false;
            $open = strpos($subject, '"');
            if ($open !== false) {
                $close = strpos($subject, '"', $open + 1);
                if ($close !== false && $close > $open + 1) {
                    $target = substr($subject, $open + 1, $close - $open - 1);
                    $quoted = true;
                }
            }
            $bytes = self::w65Uint($rawBytes, 0);
            $segments = self::w65Uint($rawSegments, 0);
            $lower = strtolower($name);
            $targetLower = strtolower($target);
            $confidence = match (true) {
                $name === '' || $target === '' => 0,
                $name === $target => 3,
                $lower === $targetLower => 2,
                self::w65SplitExtension($lower)[0] === self::w65SplitExtension($targetLower)[0] => 1,
                default => 0,
            };
            $sizeState = match (true) {
                $size <= 0 || $bytes <= 0 => 0,
                $bytes < $size => 2,
                $bytes <= $size + intdiv($size, 20) + 4096 => 1,
                default => 3,
            };
            $role = self::w65MediaRole($lower);
            if (trim($item) === '') {
                $decision = 0;
            } elseif ($size < 0 || $bytes < 0 || $segments < 0 || $name === '') {
                $decision = 1;
            } elseif (! $validHash) {
                $decision = 2;
            } elseif (isset($hashes[$hash])) {
                $decision = 3;
            } else {
                $hashes[$hash] = true;
                $decision = $confidence === 0 ? 4 : ($sizeState >= 2 ? 5 : ($confidence === 1 ? 6 : 7));
            }
            $flags = ($quoted ? 1 : 0) | (preg_match('/[\x80-\xff]/', $name) === 1 ? 2 : 0)
                | (strcspn($name, self::W65_CONTROL_BYTES) !== $nameLength ? 4 : 0) | (strpbrk($name, '/\\') !== false ? 8 : 0)
                | (strpbrk($hashRaw, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ') !== false ? 16 : 0) | (str_contains($lower, 'sample') ? 32 : 0)
                | ($segments === 0 ? 128 : 0);
            if ($lower !== '') {
                if (isset($names[$lower])) {
                    $flags |= 64;
                } else {
                    $names[$lower] = true;
                }
            }
            $tail[$decision]++;
            $tail[8 + $confidence]++;
            $tail[12 + $sizeState]++;
            $tail[16 + $role]++;
            for ($b = 0; $b < 8; $b++) {
                $tail[25 + $b] += $flags >> $b & 1;
            }
            if ($decision >= 6) {
                $tail[33] += max(0, min($size, 1 << 40));
                $tail[34] += max(0, min($segments, 1 << 40));
            }
            $out[] = $decision | $confidence << 3 | $sizeState << 5 | $role << 7 | $flags << 11 | self::w65BitLength($size) << 19
                | self::w65BitLength($bytes) << 25 | max(0, min($segments, 4095)) << 31 | ((int) hexdec(hash('fnv1a32', $lower)) & 0xFFF) << 43
                | min($nameLength, 255) << 55;
        }
        $tail[35] = \count($hashes);
        $tail[36] = \count($names);
        array_push($out, ...$tail);

        return $out;
    }

    /** @var array<string, true> */
    private const array W65_PAR2_VIDEO = ['avi' => true, 'm2ts' => true, 'm4v' => true, 'mkv' => true, 'mov' => true, 'mp4' => true, 'mpeg' => true,
        'mpg' => true, 'ts' => true, 'vob' => true, 'webm' => true, 'wmv' => true];

    /** @var array<string, true> */
    private const array W65_PAR2_AUDIO = ['aac' => true, 'ac3' => true, 'dts' => true, 'flac' => true, 'm4a' => true, 'mp3' => true, 'ogg' => true,
        'opus' => true, 'wav' => true, 'wma' => true];

    /** @var array<string, true> */
    private const array W65_PAR2_IMAGE = ['bmp' => true, 'gif' => true, 'jpeg' => true, 'jpg' => true, 'png' => true, 'webp' => true];

    /** @var array<string, true> */
    private const array W65_PAR2_SUBTITLE = ['ass' => true, 'idx' => true, 'srt' => true, 'ssa' => true, 'sub' => true];

    private const string W65_DIGITS = '0123456789';

    private const string W65_CONTROL_BYTES = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F";

    /** A trimmed field of 1-15 digits; empty is $empty, anything else -1. */
    private static function w65Uint(string $raw, int $empty): int
    {
        $value = trim($raw);
        if ($value === '') {
            return $empty;
        }
        $length = \strlen($value);

        return $length <= 15 && strspn($value, self::W65_DIGITS) === $length ? (int) $value : -1;
    }

    private static function w65BitLength(int $value): int
    {
        $bits = 0;
        for (; $value > 0; $value >>= 1) {
            $bits++;
        }

        return $bits;
    }

    /**
     * Splits a lowercased name at its last `.` when the extension is 1-5 `[a-z0-9]` bytes.
     *
     * @return array{0: string, 1: string}
     */
    private static function w65SplitExtension(string $name): array
    {
        $dot = strrpos($name, '.');
        if ($dot === false) {
            return [$name, ''];
        }
        $extension = substr($name, $dot + 1);
        $length = \strlen($extension);
        if ($length === 0 || $length > 5 || strspn($extension, 'abcdefghijklmnopqrstuvwxyz0123456789') !== $length) {
            return [$name, ''];
        }

        return [substr($name, 0, $dot), $extension];
    }

    /** The media role of a lowercased PAR2 file name. */
    private static function w65MediaRole(string $lower): int
    {
        if ($lower === '') {
            return 0;
        }
        [, $extension] = self::w65SplitExtension($lower);

        return match (true) {
            isset(self::W65_PAR2_VIDEO[$extension]) => 1,
            isset(self::W65_PAR2_AUDIO[$extension]) => 2,
            isset(self::W65_PAR2_IMAGE[$extension]) => 3,
            $extension === 'rar' || $extension === '7z' || $extension === 'zip'
                || (\strlen($extension) === 3 && ($extension[0] === 'r' ? strspn($extension, self::W65_DIGITS, 1) === 2 : strspn($extension, self::W65_DIGITS) === 3)) => 4,
            $extension === 'par2' => 5,
            $extension === 'nfo' || $extension === 'diz' => 6,
            isset(self::W65_PAR2_SUBTITLE[$extension]) => 7,
            default => 8,
        };
    }

    /**
     * Wave 65 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'reset' clears the override, library handle and buffers, 'ffi'
     * resolves the library (null when unavailable) and 'out' and 'lens' return `int64_t` output and
     * length-column buffers of at least $val words.
     */
    private static function w65RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'reset') {
            $override = $ffi = $out = $lens = null;
            $outCap = $lensCap = 0;

            return null;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('par2_file_mapper_w651', self::W65_PAR2_FILE_MAPPER_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w65RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w65RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 65.1 Par2FileMapper export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w65Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w65RustFfiState('ffi');
            $out = self::w65RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w65RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W65_PAR2_FILE_MAPPER_FFI_CDEF.)
            $written = $ffi->nntmux_w651_par2_file_mapper_plan_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
