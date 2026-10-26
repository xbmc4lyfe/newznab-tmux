<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

use App\Services\AdditionalProcessing\Enums\MappingConfidence;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * An NZB file whose real name the PAR2 index revealed.
 */
final class MappedNzbFile
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w356_dto_provider(uint8_t operation, const char *input, size_t input_len, char *output, size_t output_capacity);
        C;

    // PHP remains the default until the complete caller clears the 1.5x gate.
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(?bool $enabled): void
    {
        self::$useRustFfi = $enabled;
        if ($enabled === null) {
            self::$ffi = null;
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    private static function rust(string $input, int $capacity): ?string
    {
        if (strlen($input) > 16_777_216 || $capacity < 1 || $capacity > 16_777_216) {
            return null;
        }
        try {
            self::$ffi ??= RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
            if (self::$ffi === false) {
                return null;
            }
            $output = self::$ffi->new("char[{$capacity}]");
            // @phpstan-ignore method.notFound
            $written = self::$ffi->nntmux_w356_dto_provider(3, $input, strlen($input), $output, $capacity);
            if (! is_int($written) || $written < 1 || $written > $capacity) {
                return null;
            }

            return FFI::string($output, $written);
        } catch (Throwable) {
            return null;
        }
    }

    public function __construct(
        public readonly int $sourceIndex,
        public readonly Par2FileEntry $file,
        public readonly MappingConfidence $confidence,
    ) {}

    /**
     * Normalize known backing values without constructing an enum; unknown values rank zero.
     *
     * @return array{sourceIndex: int, filename: string, fileSize: int, confidence: string, confidenceRank: int}
     */
    public static function summarizeMapping(int $sourceIndex, string $filename, int $fileSize, string $confidenceValue): array
    {
        return (self::usesRustFfi() ? self::summarizeMappingNative($sourceIndex, $filename, $fileSize, $confidenceValue) : null)
            ?? self::summarizeMappingPhp($sourceIndex, $filename, $fileSize, $confidenceValue);
    }

    /** @return array{sourceIndex: int, filename: string, fileSize: int, confidence: string, confidenceRank: int} */
    public static function summarizeMappingPhp(int $sourceIndex, string $filename, int $fileSize, string $confidenceValue): array
    {
        $confidence = str_replace('_', '-', strtolower(trim($confidenceValue)));
        $rank = match ($confidence) {
            'verified' => 2,
            'unique-size' => 1,
            default => 0,
        };

        return ['sourceIndex' => max(0, $sourceIndex), 'filename' => trim($filename), 'fileSize' => max(0, $fileSize), 'confidence' => $rank > 0 ? $confidence : 'unknown', 'confidenceRank' => $rank];
    }

    /** @return array{sourceIndex: int, filename: string, fileSize: int, confidence: string, confidenceRank: int}|null */
    public static function summarizeMappingNative(int $sourceIndex, string $filename, int $fileSize, string $confidenceValue): ?array
    {
        $input = pack('q2V', $sourceIndex, $fileSize, strlen($filename)).$filename.pack('V', strlen($confidenceValue)).$confidenceValue;
        $bytes = self::rust($input, strlen($input) + 32);
        if ($bytes === null || strlen($bytes) < 32) {
            return null;
        }
        /** @var array{sourceIndex: int, fileSize: int, confidenceRank: int, filenameLength: int} $header */
        $header = unpack('qsourceIndex/qfileSize/qconfidenceRank/VfilenameLength', $bytes);
        $offset = 28 + $header['filenameLength'];
        if ($offset > strlen($bytes) - 4) {
            return null;
        }
        /** @var array{length: int} $length */
        $length = unpack('Vlength', $bytes, $offset);
        if ($length['length'] !== strlen($bytes) - $offset - 4) {
            return null;
        }

        return ['sourceIndex' => $header['sourceIndex'], 'filename' => substr($bytes, 28, $header['filenameLength']), 'fileSize' => $header['fileSize'], 'confidence' => substr($bytes, $offset + 4), 'confidenceRank' => $header['confidenceRank']];
    }

    // Rust is the default: the paired W48.6 benchmark cleared 1.5x on all four metrics (3.22x/2.44x common, 2.53x/1.95x uncommon mean/p99).
    public const bool W48_FILE_MAP_RUST_FFI_DEFAULT = true;

    public const string W48_FFI_CDEF = <<<'C'
        int32_t nntmux_w48_nzb_file_map(const char *subjects, size_t subjects_len, const char *subject_lens, size_t subject_lens_len, const char *ints, size_t ints_len, size_t count, const char *names, size_t names_len, const char *name_lens, size_t name_lens_len, const char *par2_sizes, size_t par2_sizes_len, size_t par2_count, int64_t *out, size_t out_count);
        C;

    public const int W48_MAP_HEADER = 12;

    public const int W48_MAP_WIDTH = 6;

    private const int W48_VALUE_CAP = 1_099_511_627_775;

    private const int W48_TOTAL_CAP = 4_611_686_018_427_387_904;

    private const int W48_MAX_ITEMS = 1_048_576;

    public static function useW48FileMapRustFfi(?bool $enabled): void
    {
        self::w48Toggle(true, $enabled);
    }

    public static function usesW48FileMapRustFfi(): bool
    {
        return self::w48Toggle() ?? self::W48_FILE_MAP_RUST_FFI_DEFAULT;
    }

    public static function resetW48FileMapRustFfi(): void
    {
        self::w48Toggle(true);
        self::w48Binding(0, true);
    }

    /**
     * Map a batch of NZB files to PAR2 entries and order them for byte offsets. A subject's file
     * name is its first quoted text (else the trimmed subject), its file counter the first
     * `[n/m]` and its segment total the last `(x/y)` unless `$segmentsTotal` gives one. Kinds:
     * 3 exact name, 2 case-insensitive name, 1 unique size (one NZB file and one PAR2 entry of
     * that size), 0 none; each PAR2 entry maps once, names first in input order. Files are
     * ordered by file counter (none last), name bytes, then index; offsets accumulate the mapped
     * PAR2 size or else the NZB size. Sizes and counts are clamped to [0, 2^40).
     *
     * Layout: `[0]` count, `[1..3]` exact/case-insensitive/unique-size matches, `[4]` unmapped,
     * `[5..7]` complete/incomplete/unknown segment totals, `[8]` NZB bytes, `[9]` mapped PAR2
     * bytes, `[10]` ordered bytes, `[11]` FNV-1a digest; then per file the PAR2 index or -1,
     * `kind | confidence rank << 4`, `file << 32 | files`, segment ppm, byte offset and NZB minus
     * PAR2 size; then the file order.
     *
     * @param  array<array-key, string>  $subjects
     * @param  array<array-key, int>  $sizes
     * @param  array<array-key, int>  $segmentsPresent
     * @param  array<array-key, int>  $segmentsTotal
     * @param  array<array-key, string>  $par2Names
     * @param  array<array-key, int>  $par2Sizes
     * @return list<int>
     */
    public static function mapFileBatch(array $subjects, array $sizes, array $segmentsPresent, array $segmentsTotal, array $par2Names, array $par2Sizes): array
    {
        return (self::usesW48FileMapRustFfi() ? self::mapFileBatchNative($subjects, $sizes, $segmentsPresent, $segmentsTotal, $par2Names, $par2Sizes) : null)
            ?? self::mapFileBatchPhp($subjects, $sizes, $segmentsPresent, $segmentsTotal, $par2Names, $par2Sizes);
    }

    /**
     * @param  array<array-key, string>  $subjects
     * @param  array<array-key, int>  $sizes
     * @param  array<array-key, int>  $segmentsPresent
     * @param  array<array-key, int>  $segmentsTotal
     * @param  array<array-key, string>  $par2Names
     * @param  array<array-key, int>  $par2Sizes
     * @return list<int>
     */
    public static function mapFileBatchPhp(array $subjects, array $sizes, array $segmentsPresent, array $segmentsTotal, array $par2Names, array $par2Sizes): array
    {
        $subjects = array_values($subjects);
        $sizes = array_values($sizes);
        $segmentsPresent = array_values($segmentsPresent);
        $segmentsTotal = array_values($segmentsTotal);
        $par2Names = array_values($par2Names);
        $par2Sizes = array_values($par2Sizes);
        $count = \count($subjects);
        $exact = $folded = $bySize = $real = [];
        foreach ($par2Names as $entry => $name) {
            $exact[$name] ??= $entry;
            $folded[strtolower($name)] ??= $entry;
            $size = min(max($par2Sizes[$entry] ?? 0, 0), self::W48_VALUE_CAP);
            $real[] = $size;
            if (isset($bySize[$size])) {
                $bySize[$size][0]++;
            } else {
                $bySize[$size] = [1, $entry];
            }
        }
        $nzb = $nzbBySize = $names = [];
        for ($i = 0; $i < $count; $i++) {
            $size = min(max($sizes[$i] ?? 0, 0), self::W48_VALUE_CAP);
            $nzb[] = $size;
            $nzbBySize[$size] = ($nzbBySize[$size] ?? 0) + 1;
            $names[] = self::w48SubjectName($subjects[$i]);
        }
        $claimed = [];
        $entries = array_fill(0, $count, -1);
        $kinds = array_fill(0, $count, 0);
        for ($i = 0; $i < $count; $i++) {
            $entry = $exact[$names[$i]] ?? null;
            $kind = 3;
            if ($entry === null || isset($claimed[$entry])) {
                $entry = $folded[strtolower($names[$i])] ?? null;
                $kind = 2;
            }
            if ($entry !== null && ! isset($claimed[$entry])) {
                $claimed[$entry] = true;
                $entries[$i] = $entry;
                $kinds[$i] = $kind;
            }
        }
        for ($i = 0; $i < $count; $i++) {
            $size = $nzb[$i];
            if ($kinds[$i] !== 0 || $size <= 0 || $nzbBySize[$size] !== 1) {
                continue;
            }
            $bucket = $bySize[$size] ?? null;
            if ($bucket !== null && $bucket[0] === 1 && ! isset($claimed[$bucket[1]])) {
                $claimed[$bucket[1]] = true;
                $entries[$i] = $bucket[1];
                $kinds[$i] = 1;
            }
        }
        $out = array_fill(0, self::W48_MAP_HEADER, 0);
        $out[0] = $count;
        $keys = [];
        $digest = 2_166_136_261;
        $nzbTotal = $mappedTotal = 0;
        for ($i = 0; $i < $count; $i++) {
            $subject = $subjects[$i];
            [$file, $files] = self::w48CounterPair($subject, strpos($subject, '['), ']');
            [, $subjectTotal] = self::w48CounterPair($subject, strrpos($subject, '('), ')');
            $total = ($segmentsTotal[$i] ?? 0) > 0 ? min($segmentsTotal[$i], self::W48_VALUE_CAP) : $subjectTotal;
            $have = min(max($segmentsPresent[$i] ?? 0, 0), $total);
            $out[$total === 0 ? 7 : ($have === $total ? 5 : 6)]++;
            $kind = $kinds[$i];
            $out[match ($kind) {
                3 => 1,
                2 => 2,
                1 => 3,
                default => 4,
            }]++;
            $nzbTotal = min($nzbTotal + $nzb[$i], self::W48_TOTAL_CAP);
            $entry = $entries[$i];
            if ($entry >= 0) {
                $mappedTotal = min($mappedTotal + $real[$entry], self::W48_TOTAL_CAP);
            }
            $out[] = $entry;
            $out[] = $kind | min($kind, 2) << 4;
            $out[] = $file << 32 | $files;
            $out[] = $total > 0 ? intdiv($have * 1_000_000, $total) : 0;
            $out[] = 0;
            $out[] = $entry >= 0 ? $nzb[$i] - $real[$entry] : 0;
            $digest = (($digest ^ ($kind | min($file, 65_535) << 4)) * 16_777_619) & 0xFFFFFFFF;
            $keys[] = $file > 0 ? $file : 1_000_000;
        }
        $order = $count > 0 ? range(0, $count - 1) : [];
        $sortNames = $names;
        array_multisort($keys, SORT_ASC, SORT_NUMERIC, $sortNames, SORT_ASC, SORT_STRING, $order, SORT_ASC, SORT_NUMERIC);
        $offset = 0;
        foreach ($order as $index) {
            $out[self::W48_MAP_HEADER + self::W48_MAP_WIDTH * $index + 4] = $offset;
            $offset = min($offset + ($entries[$index] >= 0 ? $real[$entries[$index]] : $nzb[$index]), self::W48_TOTAL_CAP);
        }
        $out[8] = $nzbTotal;
        $out[9] = $mappedTotal;
        $out[10] = $offset;
        $out[11] = $digest;

        return [...$out, ...$order];
    }

    /**
     * Rust mapping, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, string>  $subjects
     * @param  array<array-key, int>  $sizes
     * @param  array<array-key, int>  $segmentsPresent
     * @param  array<array-key, int>  $segmentsTotal
     * @param  array<array-key, string>  $par2Names
     * @param  array<array-key, int>  $par2Sizes
     * @return list<int>|null
     */
    public static function mapFileBatchNative(array $subjects, array $sizes, array $segmentsPresent, array $segmentsTotal, array $par2Names, array $par2Sizes): ?array
    {
        $subjects = array_values($subjects);
        $par2Names = array_values($par2Names);
        $count = \count($subjects);
        $par2Count = \count($par2Names);
        $fields = self::W48_MAP_HEADER + (self::W48_MAP_WIDTH + 1) * $count;
        $binding = $count <= self::W48_MAX_ITEMS && $par2Count <= self::W48_MAX_ITEMS ? self::w48Binding($fields) : null;
        if ($binding === null) {
            return null;
        }
        [$ffi, $out] = $binding;
        [$joined, $lens] = self::w48Join($subjects);
        [$names, $nameLens] = self::w48Join($par2Names);
        $ints = pack('q*', ...self::w48Fit($sizes, $count), ...self::w48Fit($segmentsPresent, $count), ...self::w48Fit($segmentsTotal, $count));
        $real = pack('q*', ...self::w48Fit($par2Sizes, $par2Count));
        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_w48_nzb_file_map($joined, \strlen($joined), $lens, \strlen($lens), $ints, \strlen($ints), $count, $names, \strlen($names), $nameLens, \strlen($nameLens), $real, \strlen($real), $par2Count, $out, $fields);
            if ($status !== 1) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $fields * 8));
        } catch (Throwable) {
            return null;
        }

        return $values === false ? null : array_values($values);
    }

    /** The quoted yEnc file name, else the trimmed subject. */
    private static function w48SubjectName(string $subject): string
    {
        $first = strpos($subject, '"');
        if ($first !== false) {
            $second = strpos($subject, '"', $first + 1);
            if ($second !== false && $second > $first + 1) {
                return substr($subject, $first + 1, $second - $first - 1);
            }
        }

        return trim($subject);
    }

    /**
     * 1-6 digit `a/b` between `$open` and the first `$close` after it, else zeros.
     *
     * @return array{0: int, 1: int}
     */
    private static function w48CounterPair(string $subject, int|false $open, string $close): array
    {
        if ($open === false) {
            return [0, 0];
        }
        $slash = strpos($subject, '/', $open);
        $end = strpos($subject, $close, $open);
        if ($slash === false || $end === false || $slash > $end) {
            return [0, 0];
        }
        $part = substr($subject, $open + 1, $slash - $open - 1);
        $total = substr($subject, $slash + 1, $end - $slash - 1);

        return ctype_digit($part) && ctype_digit($total) && \strlen($part) <= 6 && \strlen($total) <= 6 ? [(int) $part, (int) $total] : [0, 0];
    }

    /**
     * NUL-joined strings, or concatenated strings with `pack('V*')` lengths when one holds a NUL.
     *
     * @param  list<string>  $values
     * @return array{0: string, 1: string}
     */
    private static function w48Join(array $values): array
    {
        $joined = implode("\0", $values);
        if ($values === [] || substr_count($joined, "\0") === \count($values) - 1) {
            return [$joined, ''];
        }

        return [implode('', $values), pack('V*', ...array_map(strlen(...), $values))];
    }

    /**
     * @param  array<array-key, int>  $values
     * @return list<int>
     */
    private static function w48Fit(array $values, int $count): array
    {
        return \count($values) === $count && array_is_list($values) ? $values : array_pad(\array_slice(array_values($values), 0, $count), $count, 0);
    }

    private static function w48Toggle(bool $set = false, ?bool $enabled = null): ?bool
    {
        /** @var bool|null $state */
        static $state = null;
        if ($set) {
            $state = $enabled;
        }

        return $state;
    }

    /**
     * Cached `additional_w48` binding and an output buffer of at least `$fields` values; `$reset` drops both.
     *
     * @return array{0: FFI, 1: CData}|null
     */
    private static function w48Binding(int $fields, bool $reset = false): ?array
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
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w48', self::W48_FFI_CDEF) ?? false;
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

    // Rust is the default only while the paired W53.6 benchmark clears 1.5x on all four metrics.
    private const bool W53_RUST_FFI_DEFAULT = true;

    public const string W53_FFI_CDEF = <<<'C'
        int32_t nntmux_w536_mapping_summary_batch(const char *names, size_t names_len, const char *name_twin, size_t name_twin_len, const char *confidences, size_t confidences_len, const char *confidence_twin, size_t confidence_twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_count);
        C;

    public const int W53_MAPPING_HEADER = 9;

    /** Saturation of a row's 20-bit trimmed name length. */
    private const int W53_LENGTH_CAP = 1_048_575;

    /**
     * `summarizeMappingPhp()` over mapped files, grouped by trimmed name.
     *
     * Source indexes and sizes are clamped to [0, 2^40); missing ones are 0 and a missing confidence
     * is `''`. Layout: `[0]` count, `[1]` verified, `[2]` unique-size, `[3]` unknown, `[4]` total
     * size of ranked files (capped at 2^62), `[5]` distinct trimmed names, `[6]` empty names, `[7]`
     * distinct source indexes, `[8]` FNV-1a digest; then per file `rank | nameLength << 2 | leader <<
     * 22`, where `nameLength` is the trimmed length (saturating at 2^20-1) and `leader` the first file
     * with the same trimmed name.
     *
     * @param  array<array-key, int>  $sourceIndexes
     * @param  array<array-key, string>  $filenames
     * @param  array<array-key, int>  $fileSizes
     * @param  array<array-key, string>  $confidenceValues
     * @return list<int>
     */
    public static function summarizeMappingBatch(array $sourceIndexes, array $filenames, array $fileSizes, array $confidenceValues): array
    {
        return (self::usesW53RustFfi() ? self::summarizeMappingBatchNative($sourceIndexes, $filenames, $fileSizes, $confidenceValues) : null)
            ?? self::summarizeMappingBatchPhp($sourceIndexes, $filenames, $fileSizes, $confidenceValues);
    }

    /**
     * @param  array<array-key, int>  $sourceIndexes
     * @param  array<array-key, string>  $filenames
     * @param  array<array-key, int>  $fileSizes
     * @param  array<array-key, string>  $confidenceValues
     * @return list<int>
     */
    public static function summarizeMappingBatchPhp(array $sourceIndexes, array $filenames, array $fileSizes, array $confidenceValues): array
    {
        $filenames = array_values($filenames);
        $sourceIndexes = array_values($sourceIndexes);
        $fileSizes = array_values($fileSizes);
        $confidenceValues = array_values($confidenceValues);
        $count = \count($filenames);
        $cap = self::W48_VALUE_CAP;
        $out = array_fill(0, self::W53_MAPPING_HEADER, 0);
        $out[0] = $count;
        $digest = 2_166_136_261;
        $leaders = $sources = $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rank = match (str_replace('_', '-', strtolower(trim($confidenceValues[$i] ?? '')))) {
                'verified' => 2,
                'unique-size' => 1,
                default => 0,
            };
            $out[3 - $rank]++;
            if ($rank > 0) {
                $out[4] = min($out[4] + min(max($fileSizes[$i] ?? 0, 0), $cap), self::W48_TOTAL_CAP);
            }
            $name = trim($filenames[$i]);
            if ($name === '') {
                $out[6]++;
            }
            $leader = $leaders[$name] ??= $i;
            if ($leader === $i) {
                $out[5]++;
            }
            $sources[min(max($sourceIndexes[$i] ?? 0, 0), $cap)] = true;
            $row = $rank | min(\strlen($name), self::W53_LENGTH_CAP) << 2 | $leader << 22;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[7] = \count($sources);
        $out[8] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust summaries, or null when PHP must compute them (unavailable library or rejected input).
     *
     * @param  array<array-key, int>  $sourceIndexes
     * @param  array<array-key, string>  $filenames
     * @param  array<array-key, int>  $fileSizes
     * @param  array<array-key, string>  $confidenceValues
     * @return list<int>|null
     */
    public static function summarizeMappingBatchNative(array $sourceIndexes, array $filenames, array $fileSizes, array $confidenceValues): ?array
    {
        $count = \count($filenames);
        if ($count > self::W48_MAX_ITEMS) {
            return null;
        }
        if (\count($confidenceValues) !== $count) {
            $confidenceValues = array_pad(\array_slice(array_values($confidenceValues), 0, $count), $count, '');
        }
        $names = implode("\0", $filenames);
        $nameTwin = $count > 0 && substr_count($names, "\0") !== $count - 1 ? implode("\x01", $filenames) : '';
        $confidences = implode("\0", $confidenceValues);
        $confidenceTwin = $count > 0 && substr_count($confidences, "\0") !== $count - 1 ? implode("\x01", $confidenceValues) : '';
        $ints = pack('q*', ...self::w48Fit($sourceIndexes, $count), ...self::w48Fit($fileSizes, $count));
        $fields = self::W53_MAPPING_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w53Call($fields, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w536_mapping_summary_batch(
            $names, \strlen($names), $nameTwin, \strlen($nameTwin), $confidences, \strlen($confidences), $confidenceTwin, \strlen($confidenceTwin),
            $ints, \strlen($ints), $count, $out, $fields,
        ));
    }

    /**
     * Run `$callback` with the Wave 53.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW53RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w53RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w53RustFfiState('set', $previous);
        }
    }

    public static function usesW53RustFfi(): bool
    {
        $forced = self::w53RustFfiState();

        return \is_bool($forced) ? $forced : self::W53_RUST_FFI_DEFAULT;
    }

    /**
     * Wave 53.6 routing and binding, kept in function statics. No `$op` returns the forced routing (null
     * for the default); `'set'` replaces it with `$val` and returns the previous value; `'binding'`
     * returns the cached `additional_w53` binding and an output buffer of at least `$val` values, or null.
     */
    private static function w53RustFfiState(?string $op = null, mixed $val = null): mixed
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
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w53', self::W53_FFI_CDEF) ?? false;
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
     * The batch's `$fields` output words from one Wave 53.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w53Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w53RustFfiState('binding', $fields);
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

    // ========================================================================
    // WAVE 64.3 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 4.41x, p99 3.48x; uncommon mean 4.65x, p99 4.17x); see RustAdditionalDownloadMetricsArchiveImageMappedNzbSourceHintsW64ParityTest. */
    public const bool W64_RUST_FFI_DEFAULT = true;

    public const string W64_MAPPED_NZB_FILE_FFI_CDEF = <<<'C'
        ssize_t nntmux_w643_mapped_nzb_file_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of the Wave 64.3 batch. */
    public const int W64_MAPPED_NZB_FILE_TRAILER = 18;

    /** Cap for the Wave 64.3 running totals. */
    private const int W64_TOTAL_CAP = 4_611_686_018_427_387_904;

    private const array W64_MEDIA_EXTENSIONS = ['mkv' => true, 'mp4' => true, 'avi' => true, 'm4v' => true, 'ts' => true, 'wmv' => true, 'mov' => true, 'mpg' => true, 'mpeg' => true];

    private const array W64_ARCHIVE_EXTENSIONS = ['rar' => true, 'zip' => true, '7z' => true];

    private const array W64_SIDECAR_EXTENSIONS = ['nfo' => true, 'sfv' => true, 'txt' => true, 'srr' => true, 'jpg' => true, 'png' => true];

    /** Lower-cased MappingConfidence value => code (empty 0, anything else 3). */
    private const array W64_CONFIDENCES = ['verified' => 1, 'unique-size' => 2];

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
     * Runs $callback with the MappedNzbFile Wave 64 Rust path forced on or off, then restores the previous setting.
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
     * Wave 64.3 PAR2-to-NZB mapped file confidence, segment completeness and role plans. Each item is `par2Name|subject|
     * par2Size|nzbSize|segmentsPresent|segmentsTotal|confidence`. The subject name is its first `"`-quoted part, else the
     * subject, trimmed; the name match against the trimmed PAR2 name is 0 when either is empty, 1 equal, 2 equal ignoring
     * case, 3 equal non-empty stems (text before the last `.`) ignoring case, else 4. Sizes (up to 12 digits) and segment
     * counts (up to 6) are 0 empty, 1 trimmed digits, 2 other; the size match is 0 unless both sizes are valid, then 1
     * equal, 2 an NZB size above the PAR2 size by at most 10% (yEnc overhead), else 3. Completeness is present segments per
     * mille of the total (capped at 1000, 0 without a total), missing segments the shortfall. The trimmed confidence is 0
     * empty, 1 `verified`, 2 `unique-size` (any case), else 3. The role of the PAR2 name is 2 a `.par2` index or 3 one
     * holding `.vol`, 1 media (mkv/mp4/avi/m4v/ts/wmv/mov/mpg/mpeg) or 6 media with a `sample` token, 4 an archive (rar/zip/
     * 7z/rNN/NNN), 5 a sidecar (nfo/sfv/txt/srr/jpg/png), else 0. The score is 60/40 for confidence 1/2 + 30/25/15 for
     * name matches 1/2/3 + 10/5/-20 for size matches 1/2/3 - 20 for a name mismatch, clamped to 0-100; the status is 0
     * ready (score 60+, complete with a total), 1 repairable (score 60+, some segments), 2 weak (score 30+), else 3 reject.
     * Per item `role | nameMatch << 3 | par2SizeState << 6 | nzbSizeState << 8 | sizeMatch << 10 | presentState << 12 |
     * totalState << 14 | confidence << 16 | status << 18 | score << 20 | completeness << 27 | min(missing, 65535) << 37 |
     * min(subjectNameLength, 255) << 53`. Then the seven role counts, the four confidence counts, the four status counts,
     * the missing segments, the ready PAR2 bytes (capped at 2^62) and the distinct PAR2 names (case-insensitive).
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w64MappedNzbFilePlanBatch(array $items): array
    {
        return self::usesW64RustFfi() ? self::w64MappedNzbFilePlanBatchNative($items) : self::w64MappedNzbFilePlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w64MappedNzbFilePlanBatchNative(array $items): array
    {
        return self::w64Invoke($items, \count($items) + self::W64_MAPPED_NZB_FILE_TRAILER) ?? self::w64MappedNzbFilePlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w64MappedNzbFilePlanBatchPhp(array $items): array
    {
        $out = [];
        $roles = [0, 0, 0, 0, 0, 0, 0];
        $confidences = [0, 0, 0, 0];
        $statuses = [0, 0, 0, 0];
        $missingSum = $readyBytes = 0;
        $names = [];
        foreach ($items as $item) {
            [$rawPar2, $rawSubject, $rawPar2Size, $rawNzbSize, $rawPresent, $rawTotal, $rawConfidence]
                = explode('|', (string) $item, 7) + ['', '', '', '', '', '', ''];
            $par2 = trim($rawPar2);
            $lower = strtolower($par2);
            $subject = self::w64SubjectName($rawSubject);
            if ($par2 === '' || $subject === '') {
                $nameMatch = 0;
            } elseif ($par2 === $subject) {
                $nameMatch = 1;
            } elseif ($lower === strtolower($subject)) {
                $nameMatch = 2;
            } else {
                $stem = self::w64Stem($lower);
                $nameMatch = $stem !== '' && $stem === strtolower(self::w64Stem($subject)) ? 3 : 4;
            }
            [$par2SizeState, $par2Size] = self::w64Count($rawPar2Size, 12);
            [$nzbSizeState, $nzbSize] = self::w64Count($rawNzbSize, 12);
            if ($par2SizeState !== 1 || $nzbSizeState !== 1) {
                $sizeMatch = 0;
            } elseif ($par2Size === $nzbSize) {
                $sizeMatch = 1;
            } else {
                $sizeMatch = $nzbSize > $par2Size && $nzbSize * 10 <= $par2Size * 11 ? 2 : 3;
            }
            [$presentState, $present] = self::w64Count($rawPresent, 6);
            [$totalState, $total] = self::w64Count($rawTotal, 6);
            $completeness = $total > 0 ? min(intdiv($present * 1000, $total), 1000) : 0;
            $missing = max($total - $present, 0);
            $confidenceText = strtolower(trim($rawConfidence));
            $confidence = $confidenceText === '' ? 0 : (self::W64_CONFIDENCES[$confidenceText] ?? 3);
            $role = self::w64Role($lower);
            $score = max(0, min(100, [0, 60, 40, 0][$confidence] + [0, 30, 25, 15, 0][$nameMatch] + [0, 10, 5, -20][$sizeMatch]
                - ($nameMatch === 4 ? 20 : 0)));
            if ($score >= 60 && $completeness === 1000 && $total > 0) {
                $status = 0;
            } elseif ($score >= 60 && $completeness > 0) {
                $status = 1;
            } else {
                $status = $score >= 30 ? 2 : 3;
            }
            $roles[$role]++;
            $confidences[$confidence]++;
            $statuses[$status]++;
            $missingSum += $missing;
            if ($status === 0) {
                $readyBytes = min($readyBytes + $par2Size, self::W64_TOTAL_CAP);
            }
            if ($par2 !== '') {
                $names[$lower] = true;
            }
            $out[] = $role | $nameMatch << 3 | $par2SizeState << 6 | $nzbSizeState << 8 | $sizeMatch << 10 | $presentState << 12
                | $totalState << 14 | $confidence << 16 | $status << 18 | $score << 20 | $completeness << 27 | min($missing, 65_535) << 37
                | min(\strlen($subject), 255) << 53;
        }
        array_push($out, ...$roles, ...$confidences, ...$statuses);
        array_push($out, $missingSum, $readyBytes, \count($names));

        return $out;
    }

    /** The first `"`-quoted part of a subject, else the subject; trimmed. */
    private static function w64SubjectName(string $subject): string
    {
        $open = strpos($subject, '"');
        if ($open !== false && ($close = strpos($subject, '"', $open + 1)) !== false) {
            return trim(substr($subject, $open + 1, $close - $open - 1));
        }

        return trim($subject);
    }

    /** The text before the last `.` (all of it without one). */
    private static function w64Stem(string $name): string
    {
        $dot = strrpos($name, '.');

        return $dot === false ? $name : substr($name, 0, $dot);
    }

    /** Role of a lower-cased PAR2 name, as w64MappedNzbFilePlanBatch() documents. */
    private static function w64Role(string $lower): int
    {
        $dot = strrpos($lower, '.');
        $extension = $dot === false ? '' : substr($lower, $dot + 1);
        if ($extension === 'par2') {
            return str_contains($lower, '.vol') ? 3 : 2;
        }
        if (isset(self::W64_MEDIA_EXTENSIONS[$extension])) {
            return preg_match('/(?:^|[^a-z0-9])sample(?:[^a-z0-9]|$)/', $lower) === 1 ? 6 : 1;
        }
        if (isset(self::W64_ARCHIVE_EXTENSIONS[$extension]) || (\strlen($extension) === 3 && $extension[0] === 'r' && self::w64Digits(substr($extension, 1), 2, 2) !== null)
            || self::w64Digits($extension, 3, 3) !== null) {
            return 4;
        }

        return isset(self::W64_SIDECAR_EXTENSIONS[$extension]) ? 5 : 0;
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
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w643', self::W64_MAPPED_NZB_FILE_FFI_CDEF) ?? false;

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
     * Calls the Wave 64.3 MappedNzbFile export with the NUL-joined $items, or with the items concatenated plus an
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
            // @phpstan-ignore method.notFound (Declared by W64_MAPPED_NZB_FILE_FFI_CDEF.)
            $written = $ffi->nntmux_w643_mapped_nzb_file_batch($blob, \strlen($blob), $lens, $count, $out, $words);
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
