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
}
