<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\Media;

use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\DTO\ArchiveCandidate;
use App\Services\AdditionalProcessing\DTO\ArchivedMediaEntry;
use App\Services\AdditionalProcessing\Enums\VideoContainer;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Support\RustPipelineLibrary;
use dariusiii\rarinfo\ArchiveInfo;
use FFI;
use FFI\CData;
use InvalidArgumentException;
use Throwable;

/**
 * Finds videos stored uncompressed in archive volumes, as scene releases store them,
 * and cuts their bytes straight out of the downloaded data. No unrar is needed, and
 * the file listing says exactly where the video starts and how much the volume holds.
 */
class ArchivedMediaSlicer
{
    // Production adoption requires >= 1.5x mean and p99 on both W43 workloads.
    public const bool W43_RUST_FFI_DEFAULT = false;

    private static ?bool $w43RustFfi = null;

    public static function useRustFfi(?bool $enabled = true): void
    {
        self::$w43RustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$w43RustFfi ?? self::W43_RUST_FFI_DEFAULT;
    }

    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_w436_archived_media_range(const char *range_ptr, size_t range_len, const char *name_ptr, size_t name_len, int64_t file_size, int64_t next_offset, uint32_t has_next_offset, int64_t data_len, int64_t max_sample_bytes, int64_t min_main_video_bytes, int64_t *out_bounds);
        C;

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

    private static ?CData $boundsBuf = null;

    private static ?CData $boundsPtr = null;

    public function __construct(
        private readonly ProcessingConfiguration $config,
        private readonly ?string $libraryPath = null,
    ) {}

    /**
     * Keep the start of each stored video in a volume's listing for the media-source stage.
     *
     * @param  array<int|string, mixed>  $files  ArchiveInfo::getArchiveFileList() of $data
     */
    public function register(ReleaseProcessingContext $context, string $data, array $files, ?ArchiveCandidate $source): void
    {
        if (! $this->config->mediaArchiveSlicing || ! $this->config->processThumbnails || $source === null) {
            return;
        }

        foreach ($this->storedVideos($files) as $file) {
            $entry = $this->entryFor($data, $file, $source->sourceIndex, count($source->messageIds));
            if ($entry !== null) {
                $context->rememberArchivedMedia($entry);
            }
        }
    }

    /**
     * Read the chunk of a stored video that a later volume holds, from that volume's first bytes.
     */
    public function chunkFromVolume(string $volumeData, string $entryName): ?string
    {
        $archive = new ArchiveInfo;
        if (! $archive->setData($volumeData, true) || $archive->error) {
            return null;
        }

        foreach ($this->storedVideos($archive->getArchiveFileList()) as $file) {
            if ($file['name'] !== $entryName && basename(str_replace('\\', '/', $file['name'])) !== basename(str_replace('\\', '/', $entryName))) {
                continue;
            }

            [$start, $end] = $this->range($file);
            if ($start < strlen($volumeData)) {
                return substr($volumeData, $start, min($end, strlen($volumeData) - 1) - $start + 1);
            }
        }

        return null;
    }

    /**
     * @param  array{name: string, size: int, range: string, next_offset: int|null}  $file
     */
    public function entryFor(string $data, array $file, int $sourceIndex, int $segmentsDownloaded): ?ArchivedMediaEntry
    {
        return self::usesRustFfi()
            ? $this->entryForNative($data, $file, $sourceIndex, $segmentsDownloaded)
            : $this->entryForPhp($data, $file, $sourceIndex, $segmentsDownloaded);
    }

    /**
     * @param  array{name: string, size: int, range: string, next_offset: int|null}  $file
     */
    public function entryForPhp(string $data, array $file, int $sourceIndex, int $segmentsDownloaded): ?ArchivedMediaEntry
    {
        [$start] = $this->rangePhp($file);
        if ($start >= strlen($data)) {
            return null;
        }

        $size = $file['size'];
        $inVolumeEnd = $file['next_offset'] !== null && $file['next_offset'] > $start
            ? $file['next_offset'] - 1
            : $start + $size - 1;
        $available = min($inVolumeEnd, strlen($data) - 1) - $start + 1;
        $head = substr($data, $start, min($available, $this->config->mediaSampleMaxBytes));
        $name = $file['name'];

        return new ArchivedMediaEntry(
            name: $name,
            realSize: $size,
            sourceIndex: $sourceIndex,
            headBytes: $head,
            rangeStart: $start,
            inVolumeEnd: $inVolumeEnd,
            volumeBytesDownloaded: strlen($data),
            volumeSegmentsDownloaded: $segmentsDownloaded,
            isSample: preg_match('/(?:^|[\\\\\/._ -])sample(?:[\\\\\/._ -]|$)/i', $name) === 1
                || $size < $this->config->mediaMainVideoMinBytes,
            container: VideoContainer::detectPhp($head) ?? VideoContainer::fromFileName($name),
        );
    }

    /**
     * @param  array{name: string, size: int, range: string, next_offset: int|null}  $file
     */
    public function entryForNative(string $data, array $file, int $sourceIndex, int $segmentsDownloaded, bool &$supported = false): ?ArchivedMediaEntry
    {
        $supported = false;
        [$ffi, $boundsBuf, $boundsPtr] = $this->resolveFfi();
        if ($ffi === null || $boundsBuf === null || $boundsPtr === null) {
            return $this->entryForPhp($data, $file, $sourceIndex, $segmentsDownloaded);
        }

        $range = (string) $file['range'];
        $name = (string) $file['name'];
        $size = (int) $file['size'];
        $nextOffset = $file['next_offset'];
        $dataLen = strlen($data);

        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_w436_archived_media_range(
                $range,
                strlen($range),
                $name,
                strlen($name),
                $size,
                $nextOffset ?? 0,
                $nextOffset !== null ? 1 : 0,
                $dataLen,
                $this->config->mediaSampleMaxBytes,
                $this->config->mediaMainVideoMinBytes,
                $boundsPtr,
            );
        } catch (Throwable) {
            return $this->entryForPhp($data, $file, $sourceIndex, $segmentsDownloaded);
        }

        if (! is_int($status) || $status < 0) {
            return $this->entryForPhp($data, $file, $sourceIndex, $segmentsDownloaded);
        }

        $supported = true;
        $start = (int) $boundsBuf[0];
        if ($start >= $dataLen) {
            return null;
        }

        $inVolumeEnd = (int) $boundsBuf[2];
        $headLen = (int) $boundsBuf[4];
        $isSample = ((int) $boundsBuf[5]) === 1;
        $head = substr($data, $start, $headLen);

        return new ArchivedMediaEntry(
            name: $name,
            realSize: $size,
            sourceIndex: $sourceIndex,
            headBytes: $head,
            rangeStart: $start,
            inVolumeEnd: $inVolumeEnd,
            volumeBytesDownloaded: $dataLen,
            volumeSegmentsDownloaded: $segmentsDownloaded,
            isSample: $isSample,
            container: VideoContainer::detectPhp($head) ?? VideoContainer::fromFileName($name),
        );
    }

    /**
     * Unencrypted, uncompressed video entries of the archive itself (not of nested archives).
     *
     * @param  array<int|string, mixed>  $files
     * @return list<array{name: string, size: int, range: string, next_offset: int|null}>
     */
    public function storedVideos(array $files): array
    {
        return $this->storedVideosPhp($files);
    }

    /**
     * @param  array<int|string, mixed>  $files
     * @return list<array{name: string, size: int, range: string, next_offset: int|null}>
     */
    public function storedVideosPhp(array $files): array
    {
        $videos = [];
        foreach ($files as $file) {
            if (is_array($file)
                && is_string($file['name'] ?? null)
                && isset($file['size'], $file['range'])
                && ($file['source'] ?? 'main') === 'main'
                && empty($file['pass'])
                && empty($file['is_dir'])
                && ($file['compressed'] ?? null) === 0
                && (int) $file['size'] > 0
                && VideoContainer::fromFileName($file['name']) !== null
            ) {
                $videos[] = [
                    'name' => $file['name'],
                    'size' => (int) $file['size'],
                    'range' => (string) $file['range'],
                    'next_offset' => isset($file['next_offset']) ? (int) $file['next_offset'] : null,
                ];
            }
        }

        return $videos;
    }

    /**
     * @param  array<int|string, mixed>  $files
     * @return list<array{name: string, size: int, range: string, next_offset: int|null}>
     */
    public function storedVideosNative(array $files, bool &$supported = false): array
    {
        $supported = false;
        [$ffi, $boundsBuf, $boundsPtr] = $this->resolveFfi();
        if ($ffi === null || $boundsBuf === null || $boundsPtr === null) {
            return $this->storedVideosPhp($files);
        }

        $videos = $this->storedVideosPhp($files);
        if ($videos === []) {
            $supported = true;

            return [];
        }

        foreach ($videos as $video) {
            $rangeSupported = false;
            $this->rangeNative($video, $rangeSupported);
            if (! $rangeSupported) {
                return $videos;
            }
        }

        $supported = true;

        return $videos;
    }

    /**
     * @param  array{range: string}  $file
     * @return array{0: int, 1: int}
     */
    public function range(array $file): array
    {
        return self::usesRustFfi() ? $this->rangeNative($file) : $this->rangePhp($file);
    }

    /**
     * @param  array{range: string}  $file
     * @return array{0: int, 1: int}
     */
    public function rangePhp(array $file): array
    {
        $bounds = explode('-', $file['range'], 2);

        return [(int) $bounds[0], (int) ($bounds[1] ?? -1)];
    }

    /**
     * @param  array{range: string}  $file
     * @return array{0: int, 1: int}
     */
    public function rangeNative(array $file, bool &$supported = false): array
    {
        $supported = false;
        [$ffi, $boundsBuf, $boundsPtr] = $this->resolveFfi();
        if ($ffi === null || $boundsBuf === null || $boundsPtr === null) {
            return $this->rangePhp($file);
        }

        $range = (string) $file['range'];
        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_w436_archived_media_range(
                $range,
                strlen($range),
                '',
                0,
                0,
                0,
                0,
                0,
                0,
                0,
                $boundsPtr,
            );
        } catch (Throwable) {
            return $this->rangePhp($file);
        }

        if (! is_int($status) || $status < 0) {
            return $this->rangePhp($file);
        }

        $supported = true;

        return [(int) $boundsBuf[0], (int) $boundsBuf[1]];
    }

    /**
     * @return array{0: FFI|null, 1: CData|null, 2: CData|null}
     */
    private function resolveFfi(): array
    {
        if ($this->libraryPath !== null) {
            $ffi = RustPipelineLibrary::resolveDomain('archived_media_slicer', self::FFI_CDEF, $this->libraryPath);
            if ($ffi === null) {
                return [null, null, null];
            }
            try {
                $buf = $ffi->new('int64_t[6]');

                return [$ffi, $buf, FFI::addr($buf[0])];
            } catch (Throwable) {
                return [null, null, null];
            }
        }

        if (! self::$ffiResolved) {
            self::$ffiResolved = true;
            $ffi = RustPipelineLibrary::resolveDomain('archived_media_slicer', self::FFI_CDEF);
            if ($ffi !== null) {
                try {
                    $buf = $ffi->new('int64_t[6]');
                    self::$boundsBuf = $buf;
                    self::$boundsPtr = FFI::addr($buf[0]);
                    self::$ffi = $ffi;
                } catch (Throwable) {
                    self::$ffi = null;
                }
            }
        }

        return [self::$ffi, self::$boundsBuf, self::$boundsPtr];
    }

    // Rust is the default only while the paired W50.6 benchmark clears 1.5x on all four metrics.
    private const bool W50_RUST_FFI_DEFAULT = false;

    public const string W50_FFI_CDEF = <<<'C'
        int32_t nntmux_w506_archived_media_slice_batch(const char *ranges, size_t ranges_len, const char *range_twin, size_t range_twin_len, const char *names, size_t names_len, const char *name_twin, size_t name_twin_len, const char *ints, size_t ints_len, size_t count, int64_t data_len, int64_t max_sample_bytes, int64_t min_main_video_bytes, int64_t *out, size_t out_count);
        C;

    public const int W50_SLICE_HEADER = 9;

    public const int W50_SLICE_ROW = 5;

    private const int W50_MAX_ITEMS = 1_048_576;

    private const int W50_TOTAL_CAP = 1 << 62;

    public static function usesW50RustFfi(): bool
    {
        return self::w50RustFfiState() === true;
    }

    /**
     * Run `$callback` with the Wave 50.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW50RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w50RustFfiState('swap', $enabled);
        try {
            return $callback();
        } finally {
            self::w50RustFfiState('swap', $previous);
        }
    }

    /**
     * Plan the cut of a batch of stored video entries of one downloaded volume as `rangePhp()`
     * and `entryForPhp()` do, without copying any bytes.
     *
     * Layout: `[0]` count, `[1]` entries starting in the data, `[2]` entries outside it, `[3]`
     * samples, `[4]` main videos in the data, `[5]` head bytes (saturating at 2^62), `[6]` index
     * and `[7]` size of the largest main video in the data (first on ties; -1 and 0 when none),
     * `[8]` FNV-1a digest; then five values per entry: range start, range end, in-volume end,
     * head length (0 outside the data) and `in data | sample << 1 | has next offset << 2`.
     *
     * @param  array<array-key, array{name: string, size: int, range: string, next_offset: int|null}>  $files
     * @return list<int|float>
     */
    public static function planSliceBatch(array $files, int $dataLen, int $maxSampleBytes, int $minMainVideoBytes): array
    {
        return (self::usesW50RustFfi() ? self::planSliceBatchNative($files, $dataLen, $maxSampleBytes, $minMainVideoBytes) : null)
            ?? self::planSliceBatchPhp($files, $dataLen, $maxSampleBytes, $minMainVideoBytes);
    }

    /**
     * @param  array<array-key, array{name: string, size: int, range: string, next_offset: int|null}>  $files
     * @return list<int|float>
     */
    public static function planSliceBatchPhp(array $files, int $dataLen, int $maxSampleBytes, int $minMainVideoBytes): array
    {
        $out = array_fill(0, self::W50_SLICE_HEADER, 0);
        $out[0] = \count($files);
        $out[6] = -1;
        $digest = 2_166_136_261;
        $rows = [];
        $index = 0;
        foreach ($files as $file) {
            $bounds = explode('-', (string) $file['range'], 2);
            $start = (int) $bounds[0];
            $end = (int) ($bounds[1] ?? -1);
            $size = (int) $file['size'];
            $next = $file['next_offset'];
            $inVolumeEnd = $next !== null && $next > $start ? $next - 1 : $start + $size - 1;
            $available = min($inVolumeEnd, $dataLen - 1) - $start + 1;
            $sample = preg_match('/(?:^|[\\\\\/._ -])sample(?:[\\\\\/._ -]|$)/i', (string) $file['name']) === 1
                || $size < $minMainVideoBytes;
            $head = 0;
            if ($start < $dataLen) {
                // strlen(substr($data, $start, $take)) for a volume of $dataLen bytes.
                $take = min($available, $maxSampleBytes);
                $rest = $dataLen - $start;
                $head = $take >= 0 ? min($take, $rest) : max(0, $rest + $take);
                $out[1]++;
                $out[5] = $head > self::W50_TOTAL_CAP - $out[5] ? self::W50_TOTAL_CAP : $out[5] + $head;
                if (! $sample) {
                    $out[4]++;
                    if ($out[6] < 0 || $size > $out[7]) {
                        $out[6] = $index;
                        $out[7] = $size;
                    }
                }
                $flags = 1;
            } else {
                $out[2]++;
                $flags = 0;
            }
            $out[3] += $sample ? 1 : 0;
            $flags |= ($sample ? 2 : 0) | ($next !== null ? 4 : 0);
            $digest = (($digest ^ $flags) * 16_777_619) & 0xFFFFFFFF;
            array_push($rows, $start, $end, $inVolumeEnd, $head, $flags);
            $index++;
        }
        $out[8] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust plan, or null when PHP must compute it (unavailable library, a range PHP converts
     * through a float, or an overflowing offset).
     *
     * @param  array<array-key, array{name: string, size: int, range: string, next_offset: int|null}>  $files
     * @return list<int>|null
     */
    public static function planSliceBatchNative(array $files, int $dataLen, int $maxSampleBytes, int $minMainVideoBytes): ?array
    {
        $count = \count($files);
        $fields = self::W50_SLICE_HEADER + self::W50_SLICE_ROW * $count;
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = $count <= self::W50_MAX_ITEMS ? self::w50RustFfiState('buffer', $fields) : null;
        if ($binding === null) {
            return null;
        }
        [$ffi, $out] = $binding;
        // Columns are gathered natively; a file without one of the four keys stays in PHP.
        $ranges = array_column($files, 'range');
        $names = array_column($files, 'name');
        $sizes = array_column($files, 'size');
        $nexts = array_column($files, 'next_offset');
        if (\count($ranges) !== $count || \count($names) !== $count || \count($sizes) !== $count || \count($nexts) !== $count) {
            return null;
        }
        $flags = $count === 0 ? [] : array_fill(0, $count, 1);
        foreach (array_keys($nexts, null, true) as $index) {
            $flags[$index] = 0;
        }
        [$rangeJoined, $rangeTwin] = self::w50Strings($ranges);
        [$nameJoined, $nameTwin] = self::w50Strings($names);
        // pack() reads a null next offset as 0; its flag is 0.
        $ints = pack('q*', ...$sizes, ...$nexts, ...$flags);
        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_w506_archived_media_slice_batch(
                $rangeJoined, \strlen($rangeJoined), $rangeTwin, \strlen($rangeTwin),
                $nameJoined, \strlen($nameJoined), $nameTwin, \strlen($nameTwin),
                $ints, \strlen($ints), $count, $dataLen, $maxSampleBytes, $minMainVideoBytes, $out, $fields,
            );
            if ($status !== 1) {
                return null;
            }
            $unpacked = unpack('P*', FFI::string($out, $fields * 8));
        } catch (Throwable) {
            return null;
        }

        return $unpacked === false ? null : array_values($unpacked);
    }

    /**
     * Wave 50.6 routing: null reads the toggle, `swap` replaces it (null restores the default)
     * and returns the previous value, `buffer` returns the cached binding and an output buffer
     * of at least `$val` values. Enums and readonly classes cannot declare static properties,
     * so the state lives in function-local statics.
     */
    private static function w50RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $enabled */
        static $enabled = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $buffer */
        static $buffer = null;
        static $capacity = 0;
        if ($op === null) {
            return $enabled ?? self::W50_RUST_FFI_DEFAULT;
        }
        if ($op === 'swap') {
            $previous = $enabled;
            $enabled = \is_bool($val) ? $val : null;

            return $previous;
        }
        $fields = \is_int($val) ? $val : 0;
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w50', self::W50_FFI_CDEF) ?? false;
            if ($ffi === false) {
                return null;
            }
            if ($buffer === null || $capacity < $fields) {
                $capacity = max(256, $fields);
                $buffer = $ffi->new('int64_t['.$capacity.']');
            }
        } catch (Throwable) {
            return null;
        }

        return $buffer === null ? null : [$ffi, $buffer];
    }

    /**
     * NUL-joined values and, when one holds a NUL, the same list joined with 0x01 so Rust
     * finds the separators where the two differ.
     *
     * @param  list<string>  $items
     * @return array{0: string, 1: string}
     */
    private static function w50Strings(array $items): array
    {
        $joined = implode("\0", $items);

        return $items !== [] && substr_count($joined, "\0") !== \count($items) - 1 ? [$joined, implode("\x01", $items)] : [$joined, ''];
    }

    // Rust is the default only while the paired W55.6 benchmark clears 1.5x on all four metrics.
    private const bool W55_RUST_FFI_DEFAULT = true;

    public const string W55_FFI_CDEF = <<<'C'
        int32_t nntmux_w556_stored_video_batch(const char *names, size_t names_len, const char *name_twin, size_t name_twin_len, const char *ints, size_t ints_len, size_t count, int64_t min_main_video_bytes, int64_t *out, size_t out_cap);
        C;

    public const int W55_STORED_HEADER = 16;

    private const int W55_MAX_ITEMS = 1_048_576;

    private const int W55_VALUE_CAP = 1_099_511_627_775;

    private const int W55_TOTAL_CAP = 4_611_686_018_427_387_904;

    /** Container backing value => code (case index + 1); 0 is none. */
    private const array W55_CONTAINER_CODES = ['matroska' => 1, 'avi' => 2, 'mpeg' => 3, 'mpegts' => 4, 'mov' => 5, 'asf' => 6, 'ogg' => 7];

    /**
     * Classify a batch of archive listing entries as storedVideosPhp() and entryForPhp() do: per entry a
     * name, a size and flags (bit 0 passworded, bit 1 directory, bit 2 compressed, bit 3 not the main
     * archive; higher bits ignored); a missing size or flag is 0. An entry is eligible when no flag is
     * set, its size is positive and VideoContainer::fromFileName() knows its extension; it is a sample
     * when its name says so or it is smaller than `$minMainVideoBytes`.
     *
     * Layout: `[0]` count, `[1]` eligible, `[2]` ineligible, `[3..9]` eligible per container code (case
     * index + 1), `[10]` eligible samples, `[11]` eligible main videos, `[12]` first largest eligible
     * main video (-1 when none) and `[13]` its size, `[14]` eligible bytes (capped at 2^62), `[15]` FNV-1a
     * digest; then per entry `code | eligible << 3 | sample << 4 | sample_name << 5 | reasons << 6 | size
     * << 12` (reasons: flag bits 0-3, bit 4 size 0 or less, bit 5 no container; size clamped to
     * 0..2^40-1).
     *
     * @param  array<array-key, string>  $names
     * @param  array<array-key, int>  $sizes
     * @param  array<array-key, int>  $flags
     * @return list<int>
     */
    public static function classifyStoredVideoBatch(array $names, array $sizes, array $flags, int $minMainVideoBytes): array
    {
        return (self::usesW55RustFfi() ? self::classifyStoredVideoBatchNative($names, $sizes, $flags, $minMainVideoBytes) : null)
            ?? self::classifyStoredVideoBatchPhp($names, $sizes, $flags, $minMainVideoBytes);
    }

    /**
     * @param  array<array-key, string>  $names
     * @param  array<array-key, int>  $sizes
     * @param  array<array-key, int>  $flags
     * @return list<int>
     */
    public static function classifyStoredVideoBatchPhp(array $names, array $sizes, array $flags, int $minMainVideoBytes): array
    {
        $names = array_values($names);
        $sizes = array_values($sizes);
        $flags = array_values($flags);
        $count = \count($names);
        $out = array_fill(0, self::W55_STORED_HEADER, 0);
        $out[0] = $count;
        $largestAt = -1;
        $largest = 0;
        $digest = 2_166_136_261;
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $name = $names[$i];
            $raw = $sizes[$i] ?? 0;
            $size = min(max($raw, 0), self::W55_VALUE_CAP);
            $container = VideoContainer::fromFileName($name);
            $code = $container === null ? 0 : self::W55_CONTAINER_CODES[$container->value];
            $sampleName = preg_match('/(?:^|[\\\\\/._ -])sample(?:[\\\\\/._ -]|$)/i', $name) === 1;
            $sample = $sampleName || $raw < $minMainVideoBytes;
            $reasons = (($flags[$i] ?? 0) & 0xF) | ($raw <= 0 ? 16 : 0) | ($code === 0 ? 32 : 0);
            if ($reasons === 0) {
                $out[1]++;
                $out[2 + $code]++;
                $out[14] = min($out[14] + $size, self::W55_TOTAL_CAP);
                if ($sample) {
                    $out[10]++;
                } else {
                    $out[11]++;
                    if ($largestAt < 0 || $size > $largest) {
                        $largestAt = $i;
                        $largest = $size;
                    }
                }
            } else {
                $out[2]++;
            }
            $row = $code | ($reasons === 0 ? 8 : 0) | ($sample ? 16 : 0) | ($sampleName ? 32 : 0) | $reasons << 6 | $size << 12;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[12] = $largestAt;
        $out[13] = $largest;
        $out[15] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust classification, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, string>  $names
     * @param  array<array-key, int>  $sizes
     * @param  array<array-key, int>  $flags
     * @return list<int>|null
     */
    public static function classifyStoredVideoBatchNative(array $names, array $sizes, array $flags, int $minMainVideoBytes): ?array
    {
        $count = \count($names);
        $strings = $count <= self::W55_MAX_ITEMS ? self::w55Strings(array_values($names)) : null;
        if ($strings === null) {
            return null;
        }
        [$joined, $twin] = $strings;
        $ints = pack('q*', ...self::w55Fit($sizes, $count), ...self::w55Fit($flags, $count));
        $fields = self::W55_STORED_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w55Call($fields, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w556_stored_video_batch($joined, \strlen($joined), $twin, \strlen($twin), $ints, \strlen($ints), $count, $minMainVideoBytes, $out, $fields));
    }

    /**
     * Run `$callback` with the Wave 55.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW55RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w55RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w55RustFfiState('set', $previous);
        }
    }

    public static function usesW55RustFfi(): bool
    {
        $forced = self::w55RustFfiState();

        return \is_bool($forced) ? $forced : self::W55_RUST_FFI_DEFAULT;
    }

    /**
     * Wave 55.6 routing and binding, kept in function statics so earlier waves' static state stays
     * untouched. No `$op` returns the forced routing (null for the default); `'set'` replaces it with
     * `$val` and returns the previous value; `'binding'` returns the cached `additional_w55` binding and
     * an output buffer of at least `$val` values, or null.
     */
    private static function w55RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $enabled */
        static $enabled = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $buffer */
        static $buffer = null;
        static $capacity = 0;
        if ($op === 'set') {
            $previous = $enabled;
            $enabled = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op !== 'binding') {
            return $enabled;
        }
        $fields = \is_int($val) ? $val : 0;
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w55', self::W55_FFI_CDEF) ?? false;
            if ($ffi === false) {
                return null;
            }
            if ($buffer === null || $capacity < $fields) {
                $capacity = max(256, $fields);
                $buffer = $ffi->new('int64_t['.$capacity.']');
            }
        } catch (Throwable) {
            return null;
        }

        return $buffer === null ? null : [$ffi, $buffer];
    }

    /**
     * The batch's `$fields` output words from one Wave 55.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w55Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w55RustFfiState('binding', $fields);
        if ($binding === null) {
            return null;
        }
        try {
            if ($call($binding[0], $binding[1]) !== 1) {
                return null;
            }
            $values = unpack('P*', FFI::string($binding[1], $fields * 8));
        } catch (Throwable) {
            return null;
        }

        return $values === false ? null : array_values($values);
    }

    /**
     * Exactly `$count` values: the list itself when it already fits, otherwise padded with 0.
     *
     * @param  array<array-key, int>  $values
     * @return list<int>
     */
    private static function w55Fit(array $values, int $count): array
    {
        return \count($values) === $count && array_is_list($values) ? $values : array_pad(\array_slice(array_values($values), 0, $count), $count, 0);
    }

    /**
     * NUL-joined strings and, when one holds a NUL, the same list joined with 0x01 so Rust finds
     * the separators where the two differ.
     *
     * @param  list<string>  $items
     * @return array{0: string, 1: string}
     */
    private static function w55Strings(array $items): array
    {
        $joined = implode("\0", $items);

        return $items !== [] && substr_count($joined, "\0") !== \count($items) - 1 ? [$joined, implode("\x01", $items)] : [$joined, ''];
    }

    // ========================================================================
    // WAVE 64.4 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 1000-round paired runs reached 2.63x common mean, 2.31x common p99, 2.08x uncommon mean and 1.73x uncommon p99; see RustAdditionalSourcePlanPar2EntryPersistenceSlicerContainerW64ParityTest. */
    public const bool W64_RUST_FFI_DEFAULT = true;

    private const string W64_FFI_CDEF = 'ssize_t nntmux_w644_archived_media_slicer_plan_batch(const char *blob, size_t blob_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);';

    /** int64 words per w64ArchivedMediaSlicerPlanBatch() record. */
    public const int W64_ROW_WORDS = 6;

    /** int64 summary words after the w64ArchivedMediaSlicerPlanBatch() records (none for an empty batch). */
    public const int W64_TRAILER_WORDS = 10;

    private const array W64_CONTAINER_CODES = ['matroska' => 1, 'avi' => 2, 'mpeg' => 3, 'mpegts' => 4, 'mov' => 5, 'asf' => 6, 'ogg' => 7];

    private const string W64_SAMPLE = '/(?:^|[\\\\\/._ -])sample(?:[\\\\\/._ -]|$)/i';

    private const int W64_VALUE_CAP = (1 << 40) - 1;

    private const int W64_DEFAULT_PART = 716_800;

    private const int W64_PART_CAP = 1 << 30;

    /**
     * Stored-archive member slicing plan rows for [entry name, "start-end" range, size, next
     * offset (negative when none), downloaded volume bytes, segment bytes] items.
     *
     * Range bounds are the text before and after the first `-` and count only as 1-18 ASCII
     * digits (-1 otherwise). Sizes, next offsets and volume bytes clamp to [0, 2^40); a segment
     * size of 0 or less is 716,800 bytes (capped at 2^30). The in-volume end is the next offset -
     * 1 when it lies past the start, else start + size - 1; available bytes are those of the
     * member inside the downloaded volume. Segments run from the start's segment to the in-volume
     * end's. Flags: 1 bad start, 2 bad end, 4 end before start, 8 sample name, 16 next offset, 32
     * starts inside the data, 64 no VideoContainer::fromFileName() container.
     *
     * Rows [flags | container << 8 | segments << 12 (clamped to 2^24 - 1), start, end, in-volume
     * end (-1 for a bad start), available bytes, first segment (-1 for a bad start)], then the
     * trailer [records, inside the data, outside, samples, main videos inside, available bytes,
     * first index and size of the largest main video inside (-1 and 0 when none), bad ranges,
     * FNV-1a digest].
     *
     * @param  list<array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int}>  $items
     * @return list<int>
     */
    public static function w64ArchivedMediaSlicerPlanBatch(array $items): array
    {
        return self::usesW64RustFfi() ? self::w64ArchivedMediaSlicerPlanBatchNative($items) : self::w64ArchivedMediaSlicerPlanBatchPhp($items);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int}>  $items
     * @return list<int>
     */
    public static function w64ArchivedMediaSlicerPlanBatchPhp(array $items): array
    {
        self::w64Items($items);
        if ($items === []) {
            return [];
        }
        $out = [];
        $t = [\count($items), 0, 0, 0, 0, 0, -1, 0, 0, 0];
        $digest = 2_166_136_261;
        foreach ($items as $i => [$name, $range, $size, $next, $dataLen, $part]) {
            $dash = strpos($range, '-');
            if ($dash === false) {
                $start = self::w64Bound($range);
                $end = -1;
            } else {
                $start = self::w64Bound(substr($range, 0, $dash));
                $end = self::w64Bound(substr($range, $dash + 1));
            }
            $size = max(0, min($size, self::W64_VALUE_CAP));
            $hasNext = $next >= 0;
            $next = min($next, self::W64_VALUE_CAP);
            $dataLen = max(0, min($dataLen, self::W64_VALUE_CAP));
            $part = $part > 0 ? min($part, self::W64_PART_CAP) : self::W64_DEFAULT_PART;
            $video = VideoContainer::fromFileName($name);
            $container = $video === null ? 0 : self::W64_CONTAINER_CODES[$video->value];
            $sample = preg_match(self::W64_SAMPLE, $name) === 1;
            $inData = $start >= 0 && $start < $dataLen;
            $flags = ($start < 0 ? 1 : 0) | ($end < 0 ? 2 : 0) | ($start >= 0 && $end >= 0 && $end < $start ? 4 : 0) | ($sample ? 8 : 0)
                | ($hasNext ? 16 : 0) | ($inData ? 32 : 0) | ($container === 0 ? 64 : 0);
            if ($start >= 0) {
                $inVolumeEnd = $hasNext && $next > $start ? $next - 1 : $start + $size - 1;
                $available = $inData ? max(0, min($inVolumeEnd, $dataLen - 1) - $start + 1) : 0;
                $segment = intdiv($start, $part);
                $segments = ($inVolumeEnd >= $start ? intdiv($inVolumeEnd, $part) : $segment) - $segment + 1;
            } else {
                $inVolumeEnd = $segment = -1;
                $available = $segments = 0;
            }
            $word = $flags | $container << 8 | min($segments, 0xFFFFFF) << 12;
            array_push($out, $word, $start, $end, $inVolumeEnd, $available, $segment);
            if ($inData) {
                $t[1]++;
                $t[5] += $available;
                if (! $sample && $container !== 0) {
                    $t[4]++;
                    if ($t[6] < 0 || $size > $t[7]) {
                        $t[6] = $i;
                        $t[7] = $size;
                    }
                }
            } else {
                $t[2]++;
            }
            if ($sample) {
                $t[3]++;
            }
            if (($flags & 7) !== 0) {
                $t[8]++;
            }
            $value = $word ^ $available << 12 ^ $start;
            $digest = (($digest ^ (($value ^ ($value >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
        }
        $t[9] = $digest;

        return [...$out, ...$t];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int}>  $items
     * @return list<int>
     */
    public static function w64ArchivedMediaSlicerPlanBatchNative(array $items): array
    {
        self::w64Items($items);
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        [$blob, $twin] = self::w64Join([array_column($items, 0), array_column($items, 1)]);
        $ints = pack('P*', ...array_column($items, 2), ...array_column($items, 3), ...array_column($items, 4), ...array_column($items, 5));

        return self::w64Call($blob, $twin, $ints, $count, $count * self::W64_ROW_WORDS + self::W64_TRAILER_WORDS)
            ?? self::w64ArchivedMediaSlicerPlanBatchPhp($items);
    }

    /** A range bound of 1-18 ASCII digits, else -1. */
    private static function w64Bound(string $bound): int
    {
        $length = \strlen($bound);

        return $length === 0 || $length > 18 || strspn($bound, '0123456789') !== $length ? -1 : (int) $bound;
    }

    /** @param array<array-key, mixed> $items */
    private static function w64Items(array $items): void
    {
        if (! array_is_list($items)) {
            throw new InvalidArgumentException('W64 archived media slicer plan batch items must be a list.');
        }
        foreach ($items as $item) {
            if (! \is_array($item) || \count($item) !== 6 || ! array_is_list($item) || ! \is_string($item[0]) || ! \is_string($item[1]) || ! \is_int($item[2]) || ! \is_int($item[3]) || ! \is_int($item[4]) || ! \is_int($item[5])) {
                throw new InvalidArgumentException('W64 archived media slicer plan batch items must be [string entry name, string range, int size, int next offset, int volume bytes, int segment bytes] lists.');
            }
        }
    }

    public static function usesW64RustFfi(): bool
    {
        $enabled = self::w64RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W64_RUST_FFI_DEFAULT;
    }

    public static function enableW64RustFfi(): void
    {
        self::w64RustFfiState('enabled', true);
    }

    public static function disableW64RustFfi(): void
    {
        self::w64RustFfiState('enabled', false);
    }

    /** Drop the W64 override, binding, output buffer and declined-call count; routing returns to W64_RUST_FFI_DEFAULT. */
    public static function resetW64RustFfi(): void
    {
        self::w64RustFfiState('reset');
    }

    /**
     * Run $callback with the W64 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW64RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w64RustFfiState('enabled');
        self::w64RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w64RustFfiState('enabled', $previous);
        }
    }

    /**
     * String columns NUL-joined field-major and, when a value holds a NUL, the same columns
     * joined by 0x01 so Rust finds the real separators.
     *
     * @param  list<list<string>>  $columns
     * @return array{0: string, 1: string}
     */
    private static function w64Join(array $columns): array
    {
        $values = array_merge(...$columns);
        $joined = implode("\0", $values);

        return substr_count($joined, "\0") === \count($values) - 1 ? [$joined, ''] : [$joined, implode("\x01", $values)];
    }

    /**
     * One FFI crossing for the batch: $need int64 words back. Null when the library is missing
     * or declines a malformed frame, so the caller falls back to PHP; a declined call with a
     * resolved library is counted in the 'misses' state.
     *
     * @return list<int>|null
     */
    private static function w64Call(string $blob, string $twin, string $ints, int $count, int $need): ?array
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        $state = self::w64RustFfiState();
        $ffi = $state['ffi'];
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata_w64', self::W64_FFI_CDEF) ?? false;
            self::w64RustFfiState('ffi', $ffi);
        }
        if (! $ffi instanceof FFI) {
            return null;
        }
        try {
            $out = $state['out'];
            $cap = $state['cap'];
            if (! $out instanceof CData || $cap < $need) {
                $cap = max(4096, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w64RustFfiState('out', $out);
                self::w64RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W64_FFI_CDEF.)
            $written = $ffi->nntmux_w644_archived_media_slicer_plan_batch($blob, \strlen($blob), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $cap);
            $values = $written === $need ? unpack('P*', FFI::string($out, $need * 8)) : false;
            if ($values !== false) {
                return array_values($values);
            }
        } catch (Throwable) {
        }
        self::w64RustFfiState('misses', $state['misses'] + 1);

        return null;
    }

    /**
     * W64 routing override, cached binding, reusable output buffer and declined-call count, kept
     * in a function static so the class declares no new property: no arguments returns the state,
     * one argument reads a key ('reset' clears everything) and two arguments write a key.
     */
    private static function w64RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;

            return null;
        }

        return $state[$op] ?? null;
    }
}
