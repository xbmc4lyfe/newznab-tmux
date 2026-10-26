<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

final readonly class DownloadedArchive
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_dlarchive_summary(const char *flags, size_t count, const char *sizes, size_t sizes_len, int64_t *out, size_t out_len);
        C;

    // Rust qualified (seed 476, 2,000 pairs): 5.33x/3.77x common, 3.18x/2.42x uncommon mean/p99.
    public const bool W47_VOLUME_VERIFY_RUST_FFI_DEFAULT = true;

    public const string W47_FFI_CDEF = <<<'C'
        int32_t nntmux_w47_archive_volume_verify(const char *names, size_t names_len, const char *name_lens, size_t name_lens_len, const char *heads, size_t heads_len, const char *head_lens, size_t head_lens_len, size_t count, int64_t *out, size_t out_count);
        C;

    public const int W47_VOLUME_HEADER = 9;

    public const int W47_VOLUME_WIDTH = 4;

    private const int W47_HEAD_BYTES = 64;

    private const int W47_MAX_ITEMS = 1_048_576;

    private const int FLAG_IS_DIR = 1;

    private const int FLAG_PASSWORDED = 2;

    /**
     * @param  array<int, array<string, mixed>>  $files
     */
    public function __construct(
        public string $title,
        public string $data,
        public array $files,
        // PHP remains the production default: FFI marshaling stays below the 1.5x gate.
        public bool $useRustFfi = false,
    ) {}

    public function byteSize(): int
    {
        return strlen($this->data);
    }

    /**
     * Extracted (non-directory) file count and declared size, directory count and whether any
     * listed file is password protected.
     *
     * @return array{files: int, directories: int, bytes: int|float, passworded: bool}
     */
    public function fileSummary(): array
    {
        return ($this->useRustFfi ? $this->fileSummaryNative() : null) ?? $this->fileSummaryPhp();
    }

    /**
     * @return array{files: int, directories: int, bytes: int|float, passworded: bool}
     */
    public function fileSummaryPhp(): array
    {
        $files = 0;
        $directories = 0;
        $bytes = 0;
        $passworded = false;
        foreach ($this->files as $file) {
            if (! empty($file['is_dir'])) {
                $directories++;

                continue;
            }
            $files++;
            $bytes += (int) ($file['size'] ?? 0);
            if (! empty($file['pass'])) {
                $passworded = true;
            }
        }

        return ['files' => $files, 'directories' => $directories, 'bytes' => $bytes, 'passworded' => $passworded];
    }

    /**
     * @return array{files: int, directories: int, bytes: int, passworded: bool}|null Null when PHP must summarize.
     */
    public function fileSummaryNative(): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $flags = '';
        $sizes = [];
        foreach ($this->files as $file) {
            $flags .= \chr((! empty($file['is_dir']) ? self::FLAG_IS_DIR : 0) | (! empty($file['pass']) ? self::FLAG_PASSWORDED : 0));
            $sizes[] = (int) ($file['size'] ?? 0);
        }
        $packed = $sizes === [] ? '' : pack('q*', ...$sizes);
        try {
            $out = $ffi->new('int64_t[4]');
            // @phpstan-ignore method.notFound
            $code = $ffi->nntmux_dlarchive_summary($flags, \strlen($flags), $packed, \strlen($packed), $out, 4);
            if ($code !== 1) {
                return null;
            }

            return ['files' => (int) $out[0], 'directories' => (int) $out[1], 'bytes' => (int) $out[2], 'passworded' => (int) $out[3] === 1];
        } catch (Throwable) {
            return null;
        }
    }

    public static function useW47VolumeVerifyRustFfi(?bool $enabled): void
    {
        self::w47Toggle(true, $enabled);
    }

    public static function usesW47VolumeVerifyRustFfi(): bool
    {
        return self::w47Toggle() ?? self::W47_VOLUME_VERIFY_RUST_FFI_DEFAULT;
    }

    public static function resetW47VolumeVerifyRustFfi(): void
    {
        self::w47Toggle(true);
        self::w47Binding(0, true);
    }

    /**
     * Verify downloaded volume heads against their names, order multipart volumes and digest them.
     * Names give the expected container (-1 unknown extension, 0 generic `.NNN` split, 1 RAR,
     * 3 ZIP, 4 7z, 5 gzip, 6 PAR2), a volume ordinal and a set stem; heads give the magic-byte
     * signature (1 RAR4, 2 RAR5, 3 ZIP, 4 7z, 5 gzip, 6 PAR2, 0 unknown). Status: 0 matched,
     * 1 mismatched, 2 continuation without a signature to check, 3 unknown extension. Volumes are
     * ordered by set (first index), ordinal and input position; missing and duplicate ordinals are
     * counted per archive set, and the digest folds each head's CRC32 (first 64 bytes) and
     * ordinal in that order with FNV-1a.
     *
     * Layout: `[0]` count, `[1]` sets, `[2..5]` status counts, `[6]` missing volumes, `[7]`
     * duplicate volumes, `[8]` digest, then per item `status | signature << 4 | (expected + 1) << 8`,
     * ordinal, set id and volume-order position. Missing heads are empty.
     *
     * @param  array<array-key, string>  $names
     * @param  array<array-key, string>  $heads
     * @return list<int>
     */
    public static function verifyVolumeBatch(array $names, array $heads): array
    {
        return (self::usesW47VolumeVerifyRustFfi() ? self::verifyVolumeBatchNative($names, $heads) : null)
            ?? self::verifyVolumeBatchPhp($names, $heads);
    }

    /**
     * @param  array<array-key, string>  $names
     * @param  array<array-key, string>  $heads
     * @return list<int>
     */
    public static function verifyVolumeBatchPhp(array $names, array $heads): array
    {
        $names = array_values($names);
        $heads = array_values($heads);
        $count = \count($names);
        $out = [$count, 0, 0, 0, 0, 0, 0, 0, 2_166_136_261];
        $sets = $setIds = $ordinals = $expectations = [];
        for ($i = 0; $i < $count; $i++) {
            [$expected, $ordinal, $stem] = self::w47Volume(strtolower($names[$i]));
            $signature = self::w47Signature($heads[$i] ?? '');
            if ($expected < 0) {
                $status = 3;
            } elseif ($expected === 0 || (($expected === 3 || $expected === 4) && $ordinal > 0)) {
                $status = 2;
            } elseif ($expected === 1) {
                $status = $signature === 1 || $signature === 2 ? 0 : 1;
            } else {
                $status = $signature === $expected ? 0 : 1;
            }
            $out[2 + $status]++;
            $set = $sets[$expected.':'.$stem] ??= $i;
            array_push($out, $status | $signature << 4 | ($expected + 1) << 8, $ordinal, $set, 0);
            $setIds[] = $set;
            $ordinals[] = $ordinal;
            $expectations[] = $expected;
        }
        $out[1] = \count($sets);
        if ($count === 0) {
            return $out;
        }
        $order = range(0, $count - 1);
        $setKeys = $setIds;
        $ordinalKeys = $ordinals;
        array_multisort($setKeys, SORT_ASC, SORT_NUMERIC, $ordinalKeys, SORT_ASC, SORT_NUMERIC, $order);
        $digest = $out[8];
        $previousSet = -1;
        $previous = 0;
        foreach ($order as $position => $index) {
            $out[self::W47_VOLUME_HEADER + self::W47_VOLUME_WIDTH * $index + 3] = $position;
            $ordinal = $ordinals[$index];
            $digest = (($digest ^ crc32(substr($heads[$index] ?? '', 0, self::W47_HEAD_BYTES))) * 16_777_619) & 0xFFFFFFFF;
            $digest = (($digest ^ ($ordinal & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            if ($expectations[$index] < 0 || $expectations[$index] === 6) {
                continue;
            }
            if ($setIds[$index] !== $previousSet) {
                $out[6] += $ordinal;
                $previousSet = $setIds[$index];
            } elseif ($ordinal === $previous) {
                $out[7]++;
            } elseif ($ordinal > $previous + 1) {
                $out[6] += $ordinal - $previous - 1;
            }
            $previous = $ordinal;
        }
        $out[8] = $digest;

        return $out;
    }

    /**
     * Rust verification, or null when PHP must verify (unavailable library or rejected input).
     *
     * @param  array<array-key, string>  $names
     * @param  array<array-key, string>  $heads
     * @return list<int>|null
     */
    public static function verifyVolumeBatchNative(array $names, array $heads): ?array
    {
        $names = array_values($names);
        $count = \count($names);
        $fields = self::W47_VOLUME_HEADER + self::W47_VOLUME_WIDTH * $count;
        $binding = $count <= self::W47_MAX_ITEMS ? self::w47Binding($fields) : null;
        if ($binding === null) {
            return null;
        }
        [$ffi, $out] = $binding;
        [$nameBytes, $nameLens] = self::w47Strings($names);
        [$headBytes, $headLens] = self::w47Strings(\count($heads) === $count && array_is_list($heads) ? $heads : array_pad(\array_slice(array_values($heads), 0, $count), $count, ''));
        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_w47_archive_volume_verify(
                $nameBytes, \strlen($nameBytes), $nameLens, \strlen($nameLens),
                $headBytes, \strlen($headBytes), $headLens, \strlen($headLens), $count, $out, $fields,
            );
            if ($status !== 1) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $fields * 8));
        } catch (Throwable) {
            return null;
        }

        return $values === false ? null : array_values($values);
    }

    /** 2 RAR5, 1 RAR4, 3 ZIP, 4 7z, 5 gzip, 6 PAR2, 0 unknown. */
    private static function w47Signature(string $head): int
    {
        return match (true) {
            str_starts_with($head, "Rar!\x1A\x07\x01\x00") => 2,
            str_starts_with($head, "Rar!\x1A\x07\x00") => 1,
            str_starts_with($head, "PK\x03\x04"), str_starts_with($head, "PK\x07\x08") => 3,
            str_starts_with($head, "7z\xBC\xAF\x27\x1C") => 4,
            str_starts_with($head, "\x1F\x8B") => 5,
            str_starts_with($head, "PAR2\x00PKT") => 6,
            default => 0,
        };
    }

    /**
     * Expected container, volume ordinal and set stem of a lowercased name.
     *
     * @return array{0: int, 1: int, 2: string}
     */
    private static function w47Volume(string $lower): array
    {
        $dot = strrpos($lower, '.');
        if ($dot === false) {
            return [-1, -1, $lower];
        }
        $stem = substr($lower, 0, $dot);
        $extension = substr($lower, $dot + 1);
        if ($extension === 'rar') {
            $part = strrpos($stem, '.part');
            if ($part !== false) {
                $digits = substr($stem, $part + 5);
                if (\strlen($digits) <= 6 && ctype_digit($digits)) {
                    return [1, max((int) $digits - 1, 0), substr($stem, 0, $part)];
                }
            }

            return [1, 0, $stem];
        }
        if ($extension === 'zip') {
            return [3, 0, $stem];
        }
        if ($extension === '7z') {
            return [4, 0, $stem];
        }
        if ($extension === 'gz') {
            return [5, 0, $stem];
        }
        if ($extension === 'par2') {
            $volume = strrpos($stem, '.vol');

            return $volume === false ? [6, 0, $stem] : [6, 1, substr($stem, 0, $volume)];
        }
        if (\strlen($extension) === 3) {
            $tail = substr($extension, 1);
            if ($extension[0] === 'r' && ctype_digit($tail)) {
                return [1, (int) $tail + 1, $stem];
            }
            if ($extension[0] === 'z' && ctype_digit($tail)) {
                return [3, (int) $tail, $stem];
            }
            if (ctype_digit($extension)) {
                $ordinal = max((int) $extension - 1, 0);
                $inner = strrpos($stem, '.');

                return $inner !== false && substr($stem, $inner + 1) === '7z' ? [4, $ordinal, substr($stem, 0, $inner)] : [0, $ordinal, $stem];
            }
        }

        return [-1, -1, $lower];
    }

    /**
     * NUL-joined items, or their concatenation with `pack('V*')` lengths when an item holds a NUL.
     *
     * @param  list<string>  $items
     * @return array{0: string, 1: string}
     */
    private static function w47Strings(array $items): array
    {
        $joined = implode("\0", $items);
        if ($items === [] || substr_count($joined, "\0") === \count($items) - 1) {
            return [$joined, ''];
        }

        return [implode('', $items), pack('V*', ...array_map(strlen(...), $items))];
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

    private static function ffi(): ?FFI
    {
        static $ffi = null;
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return $ffi === false ? null : $ffi;
    }

    // Rust is the default only while the paired W54.6 benchmark clears 1.5x on all four metrics.
    private const bool W54_RUST_FFI_DEFAULT = true;

    public const string W54_FFI_CDEF = <<<'C'
        int32_t nntmux_w546_listing_summary_batch(const char *names, size_t names_len, const char *name_lens, size_t name_lens_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    public const int W54_LISTING_HEADER = 17;

    private const int W54_MAX_ITEMS = 1_048_576;

    private const int W54_VALUE_CAP = 1_099_511_627_775;

    private const int W54_TOTAL_CAP = 4_611_686_018_427_387_904;

    /** Lower-cased extension => listing class (0 video, 1 audio, 2 image, 3 NFO, 4 other text, 5 PAR2, 6 archive). */
    private const array W54_LISTING_CLASSES = [
        'mkv' => 0, 'mp4' => 0, 'avi' => 0, 'm4v' => 0, 'mov' => 0, 'wmv' => 0, 'ts' => 0, 'm2ts' => 0, 'mpg' => 0, 'mpeg' => 0, 'vob' => 0, 'webm' => 0,
        'mp3' => 1, 'flac' => 1, 'm4a' => 1, 'aac' => 1, 'ogg' => 1, 'wav' => 1, 'wma' => 1, 'ape' => 1,
        'jpg' => 2, 'jpeg' => 2, 'png' => 2, 'gif' => 2, 'webp' => 2, 'bmp' => 2,
        'nfo' => 3, 'txt' => 4, 'diz' => 4, 'sfv' => 4, 'par2' => 5, 'rar' => 6, 'zip' => 6, '7z' => 6,
    ];

    /**
     * `fileSummaryPhp()` plus name classes over one listing, from per-entry names, sizes and flags.
     *
     * Sizes clamp to [0, 2^40); missing sizes and flags are 0. Flag bit 0 marks a directory (`is_dir`) and
     * bit 1 a passworded entry (`pass`); higher bits are ignored. The class is that of the text after the
     * last dot, ASCII lower-cased (three-digit and `r` plus two-digit extensions are archive volumes; 7 is
     * any other file and 8 a directory). Layout: `[0]` count, `[1]` files, `[2]` directories, `[3]` file
     * bytes (capped at 2^62), `[4]` 1 when a file is passworded, `[5..12]` files per class 0..7, `[13]`
     * first largest file (-1 when none), `[14]` first NFO file (-1 when none), `[15]` deepest path (`/` or
     * `\` separators, capped at 255), `[16]` FNV-1a digest; then per entry `class | depth << 4 | flags << 12
     * | size << 14`.
     *
     * @param  array<array-key, string>  $names
     * @param  array<array-key, int>  $sizes
     * @param  array<array-key, int>  $flags
     * @return list<int>
     */
    public static function summarizeListingBatch(array $names, array $sizes, array $flags): array
    {
        return (self::usesW54RustFfi() ? self::summarizeListingBatchNative($names, $sizes, $flags) : null)
            ?? self::summarizeListingBatchPhp($names, $sizes, $flags);
    }

    /**
     * @param  array<array-key, string>  $names
     * @param  array<array-key, int>  $sizes
     * @param  array<array-key, int>  $flags
     * @return list<int>
     */
    public static function summarizeListingBatchPhp(array $names, array $sizes, array $flags): array
    {
        $names = array_values($names);
        $sizes = array_values($sizes);
        $flags = array_values($flags);
        $count = \count($names);
        $out = array_fill(0, self::W54_LISTING_HEADER, 0);
        $out[0] = $count;
        $out[14] = -1;
        $largestAt = -1;
        $largest = 0;
        $digest = 2_166_136_261;
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $name = $names[$i];
            $size = min(max($sizes[$i] ?? 0, 0), self::W54_VALUE_CAP);
            $flag = ($flags[$i] ?? 0) & 3;
            $depth = min(substr_count($name, '/') + substr_count($name, '\\'), 255);
            if ($depth > $out[15]) {
                $out[15] = $depth;
            }
            if (($flag & 1) !== 0) {
                $out[2]++;
                $class = 8;
            } else {
                $class = self::w54ListingClass($name);
                $out[1]++;
                $out[3] = min($out[3] + $size, self::W54_TOTAL_CAP);
                $out[4] |= $flag >> 1;
                $out[5 + $class]++;
                if ($largestAt < 0 || $size > $largest) {
                    $largestAt = $i;
                    $largest = $size;
                }
                if ($class === 3 && $out[14] < 0) {
                    $out[14] = $i;
                }
            }
            $row = $class | $depth << 4 | $flag << 12 | $size << 14;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[13] = $largestAt;
        $out[16] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust summary, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, string>  $names
     * @param  array<array-key, int>  $sizes
     * @param  array<array-key, int>  $flags
     * @return list<int>|null
     */
    public static function summarizeListingBatchNative(array $names, array $sizes, array $flags): ?array
    {
        $names = array_values($names);
        $count = \count($names);
        if ($count > self::W54_MAX_ITEMS) {
            return null;
        }
        [$joined, $lens] = self::w54Strings($names);
        $ints = pack('q*', ...self::w54Fit($sizes, $count), ...self::w54Fit($flags, $count));
        $fields = self::W54_LISTING_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w54Call($fields, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w546_listing_summary_batch($joined, \strlen($joined), $lens, \strlen($lens), $ints, \strlen($ints), $count, $out, $fields));
    }

    /** Listing class of a file name, as `summarizeListingBatch()` documents it. */
    private static function w54ListingClass(string $name): int
    {
        $dot = strrpos($name, '.');
        if ($dot === false) {
            return 7;
        }
        $ext = strtolower(substr($name, $dot + 1));
        $length = \strlen($ext);
        if ($length === 0 || $length > 4) {
            return 7;
        }
        $class = self::W54_LISTING_CLASSES[$ext] ?? null;
        if ($class !== null) {
            return $class;
        }

        return $length === 3 && ctype_digit($ext[0] === 'r' ? substr($ext, 1) : $ext) ? 6 : 7;
    }

    /**
     * Run `$callback` with the Wave 54.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW54RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w54RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w54RustFfiState('set', $previous);
        }
    }

    public static function usesW54RustFfi(): bool
    {
        $forced = self::w54RustFfiState();

        return \is_bool($forced) ? $forced : self::W54_RUST_FFI_DEFAULT;
    }

    /**
     * Wave 54.6 routing and binding, kept in function statics because readonly classes cannot declare
     * static properties. No `$op` returns the forced routing (null for the default); `'set'` replaces it
     * with `$val` and returns the previous value; `'binding'` returns the cached `additional_w54` binding
     * and an output buffer of at least `$val` values, or null.
     */
    private static function w54RustFfiState(?string $op = null, mixed $val = null): mixed
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
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w54', self::W54_FFI_CDEF) ?? false;
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
     * The batch's `$fields` output words from one Wave 54.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w54Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w54RustFfiState('binding', $fields);
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
     * Exactly `$count` values: the list itself when it already fits, otherwise padded with `$default`.
     *
     * @param  array<array-key, int>  $values
     * @return list<int>
     */
    private static function w54Fit(array $values, int $count, int $default = 0): array
    {
        return \count($values) === $count && array_is_list($values) ? $values : array_pad(\array_slice(array_values($values), 0, $count), $count, $default);
    }

    /**
     * NUL-joined items, or their concatenation with `pack('V*')` lengths when an item holds a NUL.
     *
     * @param  list<string>  $items
     * @return array{0: string, 1: string}
     */
    private static function w54Strings(array $items): array
    {
        $joined = implode("\0", $items);
        if ($items === [] || substr_count($joined, "\0") === \count($items) - 1) {
            return [$joined, ''];
        }

        return [implode('', $items), pack('V*', ...array_map(strlen(...), $items))];
    }

    // ========================================================================
    // WAVE 64.3 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 5.30x, p99 4.33x; uncommon mean 4.69x, p99 3.89x); see RustAdditionalDownloadMetricsArchiveImageMappedNzbSourceHintsW64ParityTest. */
    public const bool W64_RUST_FFI_DEFAULT = true;

    public const string W64_DOWNLOADED_ARCHIVE_FFI_CDEF = <<<'C'
        ssize_t nntmux_w643_downloaded_archive_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of the Wave 64.3 batch. */
    public const int W64_DOWNLOADED_ARCHIVE_TRAILER = 18;

    /** Cap for the Wave 64.3 running totals. */
    private const int W64_TOTAL_CAP = 4_611_686_018_427_387_904;

    /** Name class => family (0 unknown, 1 RAR, 2 ZIP, 3 7z, 4 split, 5 compressed stream). */
    private const array W64_CLASS_FAMILY = [0, 1, 1, 2, 3, 4, 5];

    /** Payload signature => family (signature 8, unknown, matches no class). */
    private const array W64_SIGNATURE_FAMILY = [0, 1, 1, 2, 3, 5, 5, 5, 9];

    public static function enableW64RustFfi(): void
    {
        self::w64RustFfiState('set', true);
    }

    public static function disableW64RustFfi(): void
    {
        self::w64RustFfiState('set', false);
    }

    /** Restores W64_RUST_FFI_DEFAULT and drops the cached library handle and scratch buffers. */
    public static function resetW64RustFfi(): void
    {
        self::w64RustFfiState('reset');
    }

    public static function usesW64RustFfi(): bool
    {
        $override = self::w64RustFfiState();

        return \is_bool($override) ? $override : self::W64_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the DownloadedArchive Wave 64 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW64RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w64RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w64RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 64.3 downloaded archive payload signature, volume index, compression and extraction readiness plans. Each item
     * is `name|size|flags|head`, where the head (the rest of the item, raw bytes) is the payload's first bytes. The trimmed
     * name's class and volume (case-insensitive) are 1 `.rar` (volume 1, or N of `.partN.rar` with 1-4 digits), 2 `.rNN`
     * (NN + 2), 3 `.zip` (1) or `.zNN` (NN + 1), 4 `.7z` (1) or `.7z.NNN` (NNN), 5 `.NNN` (NNN), 6 `.tar`/`.tgz`/`.gz`/
     * `.bz2`/`.xz` (1), else 0 (volume 0); the set base is the name before that suffix. The signature is 0 empty, 1 RAR4,
     * 2 RAR5, 3 ZIP, 4 7z, 5 gzip, 6 bzip2, 7 xz, 8 other; the ZIP method (ZIP heads of 10+ bytes) is 1 stored, 2 deflate,
     * 3 other, else 0. The match is 0 without a head, 1 when a known signature fits the class's family (any known signature
     * for classes 0 and 5), else 2. Size (up to 12 digits) and flags (up to 3) are 0 empty, 1 trimmed digits, 2 other;
     * flag bit 0 is encrypted and bit 2 a directory. Readiness is 2 encrypted, 3 invalid (mismatch, invalid size or flags,
     * a directory, or neither a class nor a head), 1 a continuation volume (volume > 1), else 0 ready. Per item `class |
     * signature << 3 | match << 7 | sizeState << 9 | flagState << 11 | (flags & 7) << 13 | zipMethod << 16 | readiness << 18
     * | min(volume, 65535) << 20 | min(nameLength, 255) << 36 | min(headLength, 255) << 44`. Then the four readiness counts,
     * the nine signature counts, the first volumes, the highest capped volume, the ready bytes (capped at 2^62), the
     * distinct set bases of named items (case-insensitive) and the 1-based index of the first ready first volume.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w64DownloadedArchivePlanBatch(array $items): array
    {
        return self::usesW64RustFfi() ? self::w64DownloadedArchivePlanBatchNative($items) : self::w64DownloadedArchivePlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w64DownloadedArchivePlanBatchNative(array $items): array
    {
        return self::w64Invoke($items, \count($items) + self::W64_DOWNLOADED_ARCHIVE_TRAILER) ?? self::w64DownloadedArchivePlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w64DownloadedArchivePlanBatchPhp(array $items): array
    {
        $out = [];
        $readinessCounts = [0, 0, 0, 0];
        $signatures = [0, 0, 0, 0, 0, 0, 0, 0, 0];
        $firstVolumes = $maxVolume = $readyBytes = $firstReady = $index = 0;
        $sets = [];
        foreach ($items as $item) {
            $index++;
            [$rawName, $rawSize, $rawFlags, $head] = explode('|', (string) $item, 4) + ['', '', '', ''];
            $name = trim($rawName);
            $lower = strtolower($name);
            [$class, $volume, $base] = self::w64ArchiveName($lower);
            $signature = self::w64ArchiveSignature($head);
            [$sizeState, $size] = self::w64Count($rawSize, 12);
            [$flagState, $flags] = self::w64Count($rawFlags, 3);
            $zip = 0;
            if ($signature === 3 && \strlen($head) >= 10) {
                $method = \ord($head[8]) | \ord($head[9]) << 8;
                $zip = $method === 0 ? 1 : ($method === 8 ? 2 : 3);
            }
            $match = $signature === 0 ? 0
                : ($signature !== 8 && ($class === 0 || $class === 5 || self::W64_CLASS_FAMILY[$class] === self::W64_SIGNATURE_FAMILY[$signature]) ? 1 : 2);
            if ($flagState === 1 && ($flags & 1) !== 0) {
                $readiness = 2;
            } elseif ($match === 2 || $sizeState === 2 || $flagState === 2 || ($flagState === 1 && ($flags & 4) !== 0) || ($class === 0 && $signature === 0)) {
                $readiness = 3;
            } elseif ($volume > 1) {
                $readiness = 1;
            } else {
                $readiness = 0;
            }
            $readinessCounts[$readiness]++;
            $signatures[$signature]++;
            if ($volume === 1) {
                $firstVolumes++;
            }
            $maxVolume = max($maxVolume, min($volume, 65_535));
            if ($readiness === 0) {
                $readyBytes = min($readyBytes + $size, self::W64_TOTAL_CAP);
                if ($volume === 1 && $firstReady === 0) {
                    $firstReady = $index;
                }
            }
            if ($name !== '') {
                $sets[substr($lower, 0, $base)] = true;
            }
            $out[] = $class | $signature << 3 | $match << 7 | $sizeState << 9 | $flagState << 11 | ($flags & 7) << 13 | $zip << 16
                | $readiness << 18 | min($volume, 65_535) << 20 | min(\strlen($name), 255) << 36 | min(\strlen($head), 255) << 44;
        }
        array_push($out, ...$readinessCounts, ...$signatures);
        array_push($out, $firstVolumes, $maxVolume, $readyBytes, \count($sets), $firstReady);

        return $out;
    }

    /**
     * (class, volume, base length) of a lower-cased trimmed archive name, as w64DownloadedArchivePlanBatch() documents.
     *
     * @return array{int, int, int}
     */
    private static function w64ArchiveName(string $name): array
    {
        $n = \strlen($name);
        if (str_ends_with($name, '.rar')) {
            $stem = $n - 4;
            $run = strspn(strrev(substr($name, 0, $stem)), '0123456789');
            if ($run >= 1 && $run <= 4 && $stem - $run >= 5 && substr($name, $stem - $run - 5, 5) === '.part') {
                return [1, (int) substr($name, $stem - $run, $run), $stem - $run - 5];
            }

            return [1, 1, $stem];
        }
        if ($n >= 4 && $name[$n - 4] === '.' && $name[$n - 3] === 'r' && ($value = self::w64Digits(substr($name, $n - 2), 2, 2)) !== null) {
            return [2, $value + 2, $n - 4];
        }
        if (str_ends_with($name, '.zip')) {
            return [3, 1, $n - 4];
        }
        if ($n >= 4 && $name[$n - 4] === '.' && $name[$n - 3] === 'z' && ($value = self::w64Digits(substr($name, $n - 2), 2, 2)) !== null) {
            return [3, $value + 1, $n - 4];
        }
        if (str_ends_with($name, '.7z')) {
            return [4, 1, $n - 3];
        }
        if ($n >= 7 && substr($name, $n - 7, 4) === '.7z.' && ($value = self::w64Digits(substr($name, $n - 3), 3, 3)) !== null) {
            return [4, $value, $n - 7];
        }
        if ($n >= 4 && $name[$n - 4] === '.' && ($value = self::w64Digits(substr($name, $n - 3), 3, 3)) !== null) {
            return [5, $value, $n - 4];
        }
        foreach (['.tar', '.tgz', '.gz', '.bz2', '.xz'] as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return [6, 1, $n - \strlen($suffix)];
            }
        }

        return [0, 0, $n];
    }

    /** 0 empty, 1 RAR4, 2 RAR5, 3 ZIP, 4 7z, 5 gzip, 6 bzip2, 7 xz, 8 other. */
    private static function w64ArchiveSignature(string $head): int
    {
        return match (true) {
            $head === '' => 0,
            str_starts_with($head, "Rar!\x1a\x07\x01\x00") => 2,
            str_starts_with($head, "Rar!\x1a\x07\x00") => 1,
            str_starts_with($head, "PK\x03\x04") => 3,
            str_starts_with($head, "7z\xbc\xaf\x27\x1c") => 4,
            str_starts_with($head, "\x1f\x8b") => 5,
            str_starts_with($head, 'BZh') => 6,
            str_starts_with($head, "\xfd7zXZ\x00") => 7,
            default => 8,
        };
    }

    /**
     * (0 empty, 1 one to $max ASCII digits, 2 other; the value) for the trimmed field.
     *
     * @return array{int, int}
     */
    private static function w64Count(string $text, int $max): array
    {
        $text = trim($text);
        if ($text === '') {
            return [0, 0];
        }
        $value = self::w64Digits($text, 1, $max);

        return $value === null ? [2, 0] : [1, $value];
    }

    /** $min to $max ASCII digits as their value, else null. */
    private static function w64Digits(string $text, int $min, int $max): ?int
    {
        $length = \strlen($text);

        return $length >= $min && $length <= $max && strspn($text, '0123456789') === $length ? (int) $text : null;
    }

    /**
     * Wave 64 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and returns the
     * previous override, 'reset' clears the override, library handle and buffers, 'ffi' resolves the library
     * (null when unavailable) and 'out' and 'lens' return `int64_t` output and length-column buffers of at least
     * $val words.
     */
    private static function w64RustFfiState(?string $op = null, mixed $val = null): mixed
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
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w643', self::W64_DOWNLOADED_ARCHIVE_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w64RustFfiState('ffi');
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
                $handle = self::w64RustFfiState('ffi');
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
     * Calls the Wave 64.3 DownloadedArchive export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call declines
     * or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w64Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w64RustFfiState('ffi');
            $out = self::w64RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w64RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W64_DOWNLOADED_ARCHIVE_FFI_CDEF.)
            $written = $ffi->nntmux_w643_downloaded_archive_batch($blob, \strlen($blob), $lens, $count, $out, $words);
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
