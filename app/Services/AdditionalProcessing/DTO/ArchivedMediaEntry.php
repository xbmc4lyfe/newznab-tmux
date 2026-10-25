<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

use App\Services\AdditionalProcessing\Enums\VideoContainer;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * A video stored uncompressed in a downloaded archive volume, and the bytes of it we hold.
 */
final readonly class ArchivedMediaEntry
{
    public const string FFI_CDEF = <<<'C'
        int64_t nntmux_media_continuation_bytes(int64_t range_start, int64_t head_length, int64_t volume_bytes_downloaded, int64_t in_volume_end, int64_t real_size, int64_t target);
        C;

    // Rust qualified (seed 476, 2,000 pairs): 3.45x/2.68x common, 4.01x/3.35x uncommon mean/p99.
    public const bool W47_ENTRY_RANK_RUST_FFI_DEFAULT = true;

    public const string W47_FFI_CDEF = <<<'C'
        int32_t nntmux_w47_media_entry_rank(const char *paths, size_t paths_len, const char *path_lens, size_t path_lens_len, const char *sizes, size_t sizes_len, size_t count, int64_t *out, size_t out_count);
        C;

    public const int W47_ENTRY_HEADER = 5;

    public const int W47_ENTRY_WIDTH = 5;

    public const int W47_FLAG_TRAVERSAL = 1;

    public const int W47_FLAG_ABSOLUTE = 2;

    public const int W47_FLAG_CONTROL = 4;

    public const int W47_FLAG_SAMPLE = 8;

    public const int W47_FLAG_HIDDEN = 16;

    public const int W47_FLAG_EMPTY = 32;

    private const int W47_UNSAFE_FLAGS = self::W47_FLAG_TRAVERSAL | self::W47_FLAG_ABSOLUTE | self::W47_FLAG_CONTROL | self::W47_FLAG_EMPTY;

    private const int W47_MAX_ITEMS = 1_048_576;

    private const string W47_CONTROL_BYTES = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F";

    /** 1-8 video containers, 9 audio, 10 image, 11 subtitle; others are 0. */
    private const array W47_MEDIA_CLASSES = [
        'mkv' => 1, 'mp4' => 2, 'm4v' => 2, 'avi' => 3, 'ts' => 4, 'm2ts' => 4, 'mts' => 4, 'wmv' => 5, 'mov' => 6,
        'mpg' => 7, 'mpeg' => 7, 'vob' => 7, 'webm' => 8, 'mp3' => 9, 'flac' => 9, 'm4a' => 9, 'ogg' => 9, 'wav' => 9,
        'jpg' => 10, 'jpeg' => 10, 'png' => 10, 'webp' => 10, 'srt' => 11, 'sub' => 11, 'idx' => 11, 'ass' => 11,
    ];

    /**
     * @param  int  $sourceIndex  NZB file of the volume
     * @param  string  $headBytes  start of the video, as far as it was downloaded
     * @param  int  $rangeStart  offset of the video's first byte in the volume
     * @param  int  $inVolumeEnd  offset of the video's last byte in the volume
     * @param  int  $volumeBytesDownloaded  leading bytes of the volume that were downloaded
     * @param  int  $volumeSegmentsDownloaded  leading NZB segments those bytes came from
     */
    public function __construct(
        public string $name,
        public int $realSize,
        public int $sourceIndex,
        public string $headBytes,
        public int $rangeStart,
        public int $inVolumeEnd,
        public int $volumeBytesDownloaded,
        public int $volumeSegmentsDownloaded,
        public bool $isSample,
        public ?VideoContainer $container,
    ) {}

    public function isComplete(): bool
    {
        return strlen($this->headBytes) >= $this->realSize;
    }

    /**
     * Bytes that can still be appended from later segments of the same volume, up to $target in all.
     */
    public function continuationBytes(int $target): int
    {
        // Retains the native PHP path: five integer operations cost less than one FFI crossing
        // (0.47x mean / 0.50x p99 common, 0.41x / 0.50x uncommon).
        return $this->continuationBytesPhp($target);
    }

    public function continuationBytesPhp(int $target): int
    {
        // Only a head that runs to the end of the downloaded data can be continued.
        if ($this->rangeStart + strlen($this->headBytes) !== $this->volumeBytesDownloaded) {
            return 0;
        }

        $wanted = min($target, $this->realSize) - strlen($this->headBytes);
        $leftInVolume = $this->inVolumeEnd + 1 - $this->volumeBytesDownloaded;

        return max(0, min($wanted, $leftInVolume));
    }

    public function continuationBytesNative(int $target, bool &$supported = false): int
    {
        $supported = false;
        $ffi = self::rustFfi();
        if ($ffi === null) {
            return $this->continuationBytesPhp($target);
        }
        try {
            // @phpstan-ignore method.notFound
            $bytes = $ffi->nntmux_media_continuation_bytes(
                $this->rangeStart,
                strlen($this->headBytes),
                $this->volumeBytesDownloaded,
                $this->inVolumeEnd,
                $this->realSize,
                $target,
            );
        } catch (Throwable) {
            return $this->continuationBytesPhp($target);
        }
        if (! is_int($bytes) || $bytes < 0) {
            return $this->continuationBytesPhp($target);
        }
        $supported = true;

        return $bytes;
    }

    public static function useW47EntryRankRustFfi(?bool $enabled): void
    {
        self::w47Toggle(true, $enabled);
    }

    public static function usesW47EntryRankRustFfi(): bool
    {
        return self::w47Toggle() ?? self::W47_ENTRY_RANK_RUST_FFI_DEFAULT;
    }

    public static function resetW47EntryRankRustFfi(): void
    {
        self::w47Toggle(true);
        self::w47Binding(0, true);
    }

    /**
     * Sanitize, classify and rank a batch of archive inner paths. Sanitizing drops control bytes,
     * turns backslashes into slashes, strips a leading drive letter and drops empty, `.` and `..`
     * segments. Ranking orders safe non-sample video, safe samples, other safe entries and unsafe
     * entries, larger first, then by input position.
     *
     * Layout: `[0]` count, `[1]` playable video entries, `[2]` samples, `[3]` unsafe paths, `[4]`
     * best video entry or -1, then W47_ENTRY_WIDTH values per entry: W47_FLAG_* flags,
     * `class | codec << 8` (codec 1 AVC, 2 HEVC, 3 AV1, 4 MPEG-4 ASP), sanitized length, CRC32 of
     * the sanitized path and rank. Missing sizes are 0.
     *
     * @param  array<array-key, string>  $paths
     * @param  array<array-key, int>  $sizes
     * @return list<int>
     */
    public static function rankEntryBatch(array $paths, array $sizes): array
    {
        return (self::usesW47EntryRankRustFfi() ? self::rankEntryBatchNative($paths, $sizes) : null)
            ?? self::rankEntryBatchPhp($paths, $sizes);
    }

    /**
     * @param  array<array-key, string>  $paths
     * @param  array<array-key, int>  $sizes
     * @return list<int>
     */
    public static function rankEntryBatchPhp(array $paths, array $sizes): array
    {
        $paths = array_values($paths);
        $sizes = array_values($sizes);
        $count = \count($paths);
        $out = [$count, 0, 0, 0, -1];
        $tiers = $keys = [];
        for ($i = 0; $i < $count; $i++) {
            $path = $paths[$i];
            $flags = 0;
            if (strcspn($path, self::W47_CONTROL_BYTES) !== \strlen($path)) {
                $flags |= self::W47_FLAG_CONTROL;
                $path = (string) preg_replace('/[\x00-\x1F\x7F]+/', '', $path);
            }
            $path = str_replace('\\', '/', $path);
            if ($path !== '' && $path[0] === '/') {
                $flags |= self::W47_FLAG_ABSOLUTE;
            } elseif (\strlen($path) >= 2 && $path[1] === ':' && (\ord($path[0]) | 0x20) >= 97 && (\ord($path[0]) | 0x20) <= 122) {
                $flags |= self::W47_FLAG_ABSOLUTE;
                $path = substr($path, 2);
            }
            $segments = [];
            foreach (explode('/', $path) as $segment) {
                if ($segment === '' || $segment === '.') {
                    continue;
                }
                if ($segment === '..') {
                    $flags |= self::W47_FLAG_TRAVERSAL;

                    continue;
                }
                $segments[] = $segment;
            }
            $clean = implode('/', $segments);
            $base = $segments === [] ? '' : $segments[\count($segments) - 1];
            if ($clean === '') {
                $flags |= self::W47_FLAG_EMPTY;
            }
            if ($base !== '' && $base[0] === '.') {
                $flags |= self::W47_FLAG_HIDDEN;
            }
            $lower = strtolower($base);
            if (str_contains($lower, 'sample')) {
                $flags |= self::W47_FLAG_SAMPLE;
            }
            $dot = strrpos($lower, '.');
            $class = self::W47_MEDIA_CLASSES[$dot === false ? '' : substr($lower, $dot + 1)] ?? 0;
            $unsafe = ($flags & self::W47_UNSAFE_FLAGS) !== 0;
            $tier = $unsafe ? 3 : ($class >= 1 && $class <= 8 ? (($flags & self::W47_FLAG_SAMPLE) !== 0 ? 1 : 0) : 2);
            if ($tier <= 1) {
                $out[1]++;
            }
            if (($flags & self::W47_FLAG_SAMPLE) !== 0) {
                $out[2]++;
            }
            if ($unsafe) {
                $out[3]++;
            }
            array_push($out, $flags, $class | self::w47CodecHint($lower) << 8, \strlen($clean), crc32($clean), 0);
            $tiers[] = $tier;
            $keys[] = max($sizes[$i] ?? 0, 0);
        }
        if ($count > 0) {
            $order = range(0, $count - 1);
            array_multisort($tiers, SORT_ASC, SORT_NUMERIC, $keys, SORT_DESC, SORT_NUMERIC, $order);
            foreach ($order as $rank => $index) {
                $out[self::W47_ENTRY_HEADER + self::W47_ENTRY_WIDTH * $index + 4] = $rank;
            }
            if ($tiers[0] <= 1) {
                $out[4] = $order[0];
            }
        }

        return $out;
    }

    /**
     * Rust ranking, or null when PHP must rank (unavailable library or rejected input).
     *
     * @param  array<array-key, string>  $paths
     * @param  array<array-key, int>  $sizes
     * @return list<int>|null
     */
    public static function rankEntryBatchNative(array $paths, array $sizes): ?array
    {
        $paths = array_values($paths);
        $count = \count($paths);
        $fields = self::W47_ENTRY_HEADER + self::W47_ENTRY_WIDTH * $count;
        $binding = $count <= self::W47_MAX_ITEMS ? self::w47Binding($fields) : null;
        if ($binding === null) {
            return null;
        }
        [$ffi, $out] = $binding;
        $joined = implode("\0", $paths);
        $lens = '';
        if ($count > 0 && substr_count($joined, "\0") !== $count - 1) {
            $joined = implode('', $paths);
            $lens = pack('V*', ...array_map(strlen(...), $paths));
        }
        $sizes = pack('q*', ...(\count($sizes) === $count && array_is_list($sizes) ? $sizes : array_pad(\array_slice(array_values($sizes), 0, $count), $count, 0)));
        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_w47_media_entry_rank($joined, \strlen($joined), $lens, \strlen($lens), $sizes, \strlen($sizes), $count, $out, $fields);
            if ($status !== 1) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $fields * 8));
        } catch (Throwable) {
            return null;
        }

        return $values === false ? null : array_values($values);
    }

    /** 2 HEVC, 1 AVC, 3 AV1, 4 MPEG-4 ASP, 0 unknown. */
    private static function w47CodecHint(string $lower): int
    {
        if (str_contains($lower, 'x265') || str_contains($lower, 'h265') || str_contains($lower, 'hevc')) {
            return 2;
        }
        if (str_contains($lower, 'x264') || str_contains($lower, 'h264') || str_contains($lower, 'avc')) {
            return 1;
        }
        if (str_contains($lower, 'av1')) {
            return 3;
        }

        return str_contains($lower, 'xvid') || str_contains($lower, 'divx') ? 4 : 0;
    }

    /** Readonly classes keep the nullable toggle in a function static. */
    private static function w47Toggle(bool $set = false, ?bool $enabled = null): ?bool
    {
        /** @var bool|null $state */
        static $state = null;
        if ($set) {
            $state = $enabled;
        }

        return $state;
    }

    /**
     * Cached binding and an output buffer of at least `$fields` values; `$reset` drops both.
     *
     * @return array{0: FFI, 1: CData}|null
     */
    private static function w47Binding(int $fields, bool $reset = false): ?array
    {
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $buffer */
        static $buffer = null;
        static $capacity = 0;
        if ($reset) {
            $ffi = $buffer = null;
            $capacity = 0;

            return null;
        }
        $ffi ??= RustPipelineLibrary::resolveDomain('additional_w47', self::W47_FFI_CDEF) ?? false;
        if ($ffi === false) {
            return null;
        }
        if ($buffer === null || $capacity < $fields) {
            $capacity = max(256, $fields);
            $buffer = $ffi->new('int64_t['.$capacity.']');
        }

        return $buffer === null ? null : [$ffi, $buffer];
    }

    /**
     * Toggle the Rust helper; readonly classes keep the state in a function static.
     */
    public static function useRustFfi(bool $use): void
    {
        self::rustFfi($use);
    }

    private static function rustFfi(?bool $use = null): ?FFI
    {
        static $enabled = true;
        static $resolved = false;
        static $ffi = null;
        if ($use !== null) {
            $enabled = $use;

            return null;
        }
        if (! $enabled) {
            return null;
        }
        if (! $resolved) {
            $resolved = true;
            $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF);
        }

        return $ffi;
    }
}
