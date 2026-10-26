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

    // Rust is the default only while the paired W60.6 benchmark clears 1.5x on all four metrics.
    public const bool W60_RUST_FFI_DEFAULT = true;

    private const string W60_ENTRY_FFI_CDEF = <<<'C'
        int32_t nntmux_w606_archived_entry_batch(const char *strings, size_t strings_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    public const int W60_ENTRY_HEADER = 29;

    private const int W60_MAX_ITEMS = 1_048_576;

    private const int W60_VALUE_CAP = (1 << 40) - 1;

    private const int W60_TOTAL_CAP = 1 << 62;

    /** 1-8 video containers, 9 audio, 10 image, 11 text, 12 archive; others are 0. */
    private const array W60_ENTRY_CLASSES = [
        'mkv' => 1, 'mp4' => 2, 'm4v' => 2, 'avi' => 3, 'ts' => 4, 'm2ts' => 4, 'mts' => 4, 'wmv' => 5, 'mov' => 6,
        'mpg' => 7, 'mpeg' => 7, 'vob' => 7, 'webm' => 8, 'mp3' => 9, 'flac' => 9, 'm4a' => 9, 'ogg' => 9, 'wav' => 9,
        'jpg' => 10, 'jpeg' => 10, 'png' => 10, 'webp' => 10, 'nfo' => 11, 'txt' => 11, 'srt' => 11, 'sub' => 11,
        'rar' => 12, 'zip' => 12, '7z' => 12,
    ];

    /**
     * Classify archived entries by base name (after the last `/` or `\`), sizes (clamped to 0..2^40-1),
     * compression method (non-zero is compressed) and encryption flag, and pick the largest playable
     * (safe, stored, unencrypted, non-empty video) main entry and sample, first on ties. An entry is unsafe
     * with an empty base name, a leading separator, a NUL byte or a `..` segment.
     *
     * Layout: `[0]` count, `[1]` video, `[2]` playable, `[3]` samples, `[4]` compressed, `[5]` encrypted,
     * `[6]` unsafe, `[7]` complete, `[8]` size and `[9]` held totals (capped at 2^62), `[10]` main video
     * index (-1) and `[11]` its size, `[12]` best sample index (-1) and `[13]` its size, `[14..26]` per
     * class, `[27]` mean held percent, `[28]` FNV-1a digest; then per entry `class | sample << 4 |
     * compressed << 5 | encrypted << 6 | unsafe << 7 | complete << 8 | playable << 9 | percent << 10 |
     * min(name bytes, 65535) << 17 | min(ext bytes, 255) << 33 | codec << 41 | hidden << 44`.
     *
     * @param  array<array-key, string>  $names
     * @param  array<array-key, int>  $realSizes
     * @param  array<array-key, int>  $heldBytes
     * @param  array<array-key, int>  $methods
     * @param  array<array-key, int>  $encrypted
     * @return list<int>
     */
    public static function summarizeArchivedEntryBatch(array $names, array $realSizes, array $heldBytes, array $methods, array $encrypted): array
    {
        return (self::usesW60RustFfi() ? self::summarizeArchivedEntryBatchNative($names, $realSizes, $heldBytes, $methods, $encrypted) : null)
            ?? self::summarizeArchivedEntryBatchPhp($names, $realSizes, $heldBytes, $methods, $encrypted);
    }

    /**
     * @param  array<array-key, string>  $names
     * @param  array<array-key, int>  $realSizes
     * @param  array<array-key, int>  $heldBytes
     * @param  array<array-key, int>  $methods
     * @param  array<array-key, int>  $encrypted
     * @return list<int>
     */
    public static function summarizeArchivedEntryBatchPhp(array $names, array $realSizes, array $heldBytes, array $methods, array $encrypted): array
    {
        $names = array_values($names);
        $realSizes = array_values($realSizes);
        $heldBytes = array_values($heldBytes);
        $methods = array_values($methods);
        $encrypted = array_values($encrypted);
        $count = \count($names);
        $cap = self::W60_VALUE_CAP;
        $totalCap = self::W60_TOTAL_CAP;
        $out = array_fill(0, self::W60_ENTRY_HEADER, 0);
        $out[0] = $count;
        $out[10] = $out[12] = -1;
        $digest = 2_166_136_261;
        $percentSum = 0;
        $rows = [];
        foreach ($names as $at => $name) {
            $name = (string) $name;
            $size = min(max((int) ($realSizes[$at] ?? 0), 0), $cap);
            $held = min(max((int) ($heldBytes[$at] ?? 0), 0), $cap);
            $compressed = (int) ($methods[$at] ?? 0) !== 0 ? 1 : 0;
            $locked = (int) ($encrypted[$at] ?? 0) !== 0 ? 1 : 0;
            $normalized = strtr($name, '\\', '/');
            $slash = strrpos($normalized, '/');
            $base = $slash === false ? $name : substr($name, $slash + 1);
            $lower = strtolower($base);
            $dot = strrpos($lower, '.');
            $ext = $dot === false ? '' : substr($lower, $dot + 1);
            $class = self::W60_ENTRY_CLASSES[$ext] ?? 0;
            $sample = str_contains($lower, 'sample') ? 1 : 0;
            $unsafe = $base === '' || $normalized[0] === '/' || str_contains($name, "\0") || str_contains('/'.$normalized.'/', '/../') ? 1 : 0;
            $complete = $held >= $size ? 1 : 0;
            $percent = $size > 0 ? min(100, intdiv($held * 100, $size)) : 100;
            $video = $class >= 1 && $class <= 8;
            $playable = $video && $compressed === 0 && $locked === 0 && $unsafe === 0 && $size > 0 ? 1 : 0;
            $out[1] += (int) $video;
            $out[2] += $playable;
            $out[3] += $sample;
            $out[4] += $compressed;
            $out[5] += $locked;
            $out[6] += $unsafe;
            $out[7] += $complete;
            $out[8] = min($out[8] + $size, $totalCap);
            $out[9] = min($out[9] + $held, $totalCap);
            if ($playable === 1) {
                $slot = $sample === 0 ? 10 : 12;
                if ($out[$slot] < 0 || $size > $out[$slot + 1]) {
                    $out[$slot] = $at;
                    $out[$slot + 1] = $size;
                }
            }
            $out[14 + $class]++;
            $percentSum += $percent;
            $row = $class | $sample << 4 | $compressed << 5 | $locked << 6 | $unsafe << 7 | $complete << 8 | $playable << 9 | $percent << 10
                | min(\strlen($name), 65_535) << 17 | min(\strlen($ext), 255) << 33 | self::w47CodecHint($lower) << 41
                | (int) ($base !== '' && $base[0] === '.') << 44;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        if ($count > 0) {
            $out[27] = intdiv($percentSum, $count);
        }
        $out[28] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust summary, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, string>  $names
     * @param  array<array-key, int>  $realSizes
     * @param  array<array-key, int>  $heldBytes
     * @param  array<array-key, int>  $methods
     * @param  array<array-key, int>  $encrypted
     * @return list<int>|null
     */
    public static function summarizeArchivedEntryBatchNative(array $names, array $realSizes, array $heldBytes, array $methods, array $encrypted): ?array
    {
        $count = \count($names);
        if ($count > self::W60_MAX_ITEMS) {
            return null;
        }
        [$joined, $twin] = self::w60Strings(array_values($names));
        $ints = pack('q*', ...self::w60Column($realSizes, $count), ...self::w60Column($heldBytes, $count),
            ...self::w60Column($methods, $count), ...self::w60Column($encrypted, $count));
        $fields = self::W60_ENTRY_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w60Call($fields, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w606_archived_entry_batch($joined, \strlen($joined), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $fields));
    }

    /**
     * Run `$callback` with the Wave 60.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW60RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w60RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w60RustFfiState('set', $previous);
        }
    }

    public static function usesW60RustFfi(): bool
    {
        $forced = self::w60RustFfiState();

        return \is_bool($forced) ? $forced : self::W60_RUST_FFI_DEFAULT;
    }

    /**
     * Wave 60.6 routing and binding in function statics (readonly classes cannot declare static properties).
     * No `$op` returns the forced routing (null for the default); `'set'` replaces it with `$val` and returns
     * the previous value; `'binding'` returns the cached binding and an output buffer of at least `$val`
     * values, or null.
     */
    private static function w60RustFfiState(?string $op = null, mixed $val = null): mixed
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
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w606', self::W60_ENTRY_FFI_CDEF) ?? false;
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
     * The batch's `$fields` output words from one Wave 60.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w60Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w60RustFfiState('binding', $fields);
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
     * Values NUL-joined and, when one holds a NUL, the same values joined with 0x01 so Rust finds the
     * separators where the two differ.
     *
     * @param  list<string>  $items
     * @return array{0: string, 1: string}
     */
    private static function w60Strings(array $items): array
    {
        $joined = implode("\0", $items);

        return $items !== [] && substr_count($joined, "\0") !== \count($items) - 1 ? [$joined, implode("\x01", $items)] : [$joined, ''];
    }

    /**
     * Values by position, cut or padded with `$pad` to `$count`.
     *
     * @template T of int|string
     *
     * @param  array<array-key, T>  $values
     * @param  T  $pad
     * @return list<T>
     */
    private static function w60Column(array $values, int $count, int|string $pad = 0): array
    {
        $values = array_values($values);

        return \count($values) === $count ? $values : array_pad(\array_slice($values, 0, $count), $count, $pad);
    }
}
