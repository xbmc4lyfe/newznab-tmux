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
}
