<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * An image posted in the release, with every article needed to download it whole.
 */
final class ImageCandidate
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
            $written = self::$ffi->nntmux_w356_dto_provider(2, $input, strlen($input), $output, $capacity);
            if (! is_int($written) || $written < 1 || $written > $capacity) {
                return null;
            }

            return FFI::string($output, $written);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $messageIds
     */
    public function __construct(
        public readonly string $title,
        public readonly array $messageIds,
        public readonly int $sourceIndex,
        public readonly int $postedSize = 0,
    ) {}

    /**
     * Trim byte strings, discard nonstring/empty IDs and deduplicate in first-seen order.
     * Constructor data remains untouched; callers opt into this descriptor explicitly.
     *
     * @param  array<array-key, mixed>  $messageIds
     * @return array{title: string, messageIds: list<string>, sourceIndex: int, postedSize: int}
     */
    public static function normalizeCandidateDescriptor(string $title, array $messageIds, int $sourceIndex, int $postedSize = 0): array
    {
        return (self::usesRustFfi() ? self::normalizeCandidateDescriptorNative($title, $messageIds, $sourceIndex, $postedSize) : null)
            ?? self::normalizeCandidateDescriptorPhp($title, $messageIds, $sourceIndex, $postedSize);
    }

    /**
     * @param  array<array-key, mixed>  $messageIds
     * @return array{title: string, messageIds: list<string>, sourceIndex: int, postedSize: int}
     */
    public static function normalizeCandidateDescriptorPhp(string $title, array $messageIds, int $sourceIndex, int $postedSize = 0): array
    {
        $ids = [];
        foreach ($messageIds as $id) {
            if (is_string($id) && ($id = trim($id)) !== '') {
                $ids[$id] = $id;
            }
        }

        return ['title' => trim($title), 'messageIds' => array_values($ids), 'sourceIndex' => max(0, $sourceIndex), 'postedSize' => max(0, $postedSize)];
    }

    /**
     * @param  array<array-key, mixed>  $messageIds
     * @return array{title: string, messageIds: list<string>, sourceIndex: int, postedSize: int}|null
     */
    public static function normalizeCandidateDescriptorNative(string $title, array $messageIds, int $sourceIndex, int $postedSize = 0): ?array
    {
        $ids = array_values(array_filter($messageIds, is_string(...)));
        $input = pack('q2V', $sourceIndex, $postedSize, strlen($title)).$title.pack('V', count($ids));
        foreach ($ids as $id) {
            $input .= pack('V', strlen($id)).$id;
        }
        $bytes = self::rust($input, strlen($input));
        if ($bytes === null || strlen($bytes) < 24) {
            return null;
        }
        /** @var array{sourceIndex: int, postedSize: int, titleLength: int} $header */
        $header = unpack('qsourceIndex/qpostedSize/VtitleLength', $bytes);
        $offset = 20 + $header['titleLength'];
        if ($offset > strlen($bytes) - 4) {
            return null;
        }
        $title = substr($bytes, 20, $header['titleLength']);
        /** @var array{count: int} $count */
        $count = unpack('Vcount', $bytes, $offset);
        $offset += 4;
        if ($count['count'] > intdiv(strlen($bytes) - $offset, 4)) {
            return null;
        }
        $ids = [];
        for ($i = 0; $i < $count['count']; $i++) {
            if ($offset > strlen($bytes) - 4) {
                return null;
            }
            /** @var array{length: int} $length */
            $length = unpack('Vlength', $bytes, $offset);
            $offset += 4;
            if ($length['length'] > strlen($bytes) - $offset) {
                return null;
            }
            $ids[] = substr($bytes, $offset, $length['length']);
            $offset += $length['length'];
        }

        return $offset === strlen($bytes)
            ? ['title' => $title, 'messageIds' => $ids, 'sourceIndex' => $header['sourceIndex'], 'postedSize' => $header['postedSize']]
            : null;
    }

    // Rust is the default: the paired W48.6 benchmark cleared 1.5x on all four metrics (6.25x/5.13x common, 3.34x/3.52x uncommon mean/p99).
    public const bool W48_IMAGE_SCORE_RUST_FFI_DEFAULT = true;

    public const string W48_FFI_CDEF = <<<'C'
        int32_t nntmux_w48_image_score(const char *names, size_t names_len, const char *name_lens, size_t name_lens_len, const char *heads, size_t heads_len, const char *head_lens, size_t head_lens_len, const char *sizes, size_t sizes_len, size_t count, int64_t *out, size_t out_count);
        C;

    public const int W48_IMAGE_HEADER = 12;

    public const int W48_IMAGE_WIDTH = 6;

    private const int W48_DIMENSION_CAP = 1_000_000;

    private const int W48_ENTROPY_WINDOW = 4096;

    private const int W48_MAX_ITEMS = 1_048_576;

    private const array W48_COVER_WORDS = ['cover', 'front', 'poster', 'folder'];

    private const array W48_SAMPLE_WORDS = ['sample', 'screen', 'thumb', 'proof', 'back'];

    private const array W48_EXTENSIONS = ['jpg' => 1, 'jpeg' => 1, 'png' => 2, 'gif' => 3, 'webp' => 4, 'bmp' => 5];

    public static function useW48ImageScoreRustFfi(?bool $enabled): void
    {
        self::w48Toggle(true, $enabled);
    }

    public static function usesW48ImageScoreRustFfi(): bool
    {
        return self::w48Toggle() ?? self::W48_IMAGE_SCORE_RUST_FFI_DEFAULT;
    }

    public static function resetW48ImageScoreRustFfi(): void
    {
        self::w48Toggle(true);
        self::w48Binding(0, true);
    }

    /**
     * Score and rank a batch of posted images from their names, downloaded heads and posted sizes.
     * Formats: 0 unknown, 1 JPEG, 2 PNG, 3 GIF, 4 WebP, 5 BMP (extensions use the same codes).
     * Kinds: 0 other, 1 sample/screenshot, 2 cover. Shapes: 0 unknown, 1 portrait, 2 square,
     * 3 landscape. Header dimensions are clamped to 1,000,000; the byte histogram covers 4 KiB.
     *
     * Layout: `[0]` count, `[1..6]` format counts, `[7]` with dimensions, `[8]` covers, `[9]`
     * samples, `[10]` best index or -1, `[11]` FNV-1a digest; then per image `format |
     * extension << 4 | kind << 8 | shape << 12 | mismatch << 16`, width, height, aspect permille,
     * `distinct bytes << 32 | collision ppm` and score; then the indices best first (ties keep
     * input order). Missing heads are empty and missing sizes 0.
     *
     * @param  array<array-key, string>  $names
     * @param  array<array-key, string>  $heads
     * @param  array<array-key, int>  $postedSizes
     * @return list<int>
     */
    public static function scoreImageBatch(array $names, array $heads, array $postedSizes): array
    {
        return (self::usesW48ImageScoreRustFfi() ? self::scoreImageBatchNative($names, $heads, $postedSizes) : null)
            ?? self::scoreImageBatchPhp($names, $heads, $postedSizes);
    }

    /**
     * @param  array<array-key, string>  $names
     * @param  array<array-key, string>  $heads
     * @param  array<array-key, int>  $postedSizes
     * @return list<int>
     */
    public static function scoreImageBatchPhp(array $names, array $heads, array $postedSizes): array
    {
        $names = array_values($names);
        $heads = array_values($heads);
        $postedSizes = array_values($postedSizes);
        $count = \count($names);
        $out = array_fill(0, self::W48_IMAGE_HEADER, 0);
        $out[0] = $count;
        $out[10] = -1;
        $scores = [];
        $digest = 2_166_136_261;
        for ($i = 0; $i < $count; $i++) {
            $head = $heads[$i] ?? '';
            $length = \strlen($head);
            $format = self::w48Format($head);
            [$width, $height] = self::w48Dimensions($format, $head, $length);
            $window = $length > self::W48_ENTROPY_WINDOW ? substr($head, 0, self::W48_ENTROPY_WINDOW) : $head;
            $distinct = $collision = 0;
            if ($window !== '') {
                $histogram = count_chars($window, 1);
                $squares = 0;
                foreach ($histogram as $frequency) {
                    $squares += $frequency * $frequency;
                }
                $distinct = \count($histogram);
                $n = \strlen($window);
                $collision = intdiv($squares * 1_000_000, $n * $n);
            }
            $lower = strtolower($names[$i]);
            $dot = strrpos($lower, '.');
            $extension = $dot === false ? 0 : (self::W48_EXTENSIONS[substr($lower, $dot + 1)] ?? 0);
            $mismatch = $extension !== 0 && $format !== 0 && $extension !== $format;
            $kind = self::w48ContainsAny($lower, self::W48_SAMPLE_WORDS) ? 1 : (self::w48ContainsAny($lower, self::W48_COVER_WORDS) ? 2 : 0);
            $dimensions = $width > 0 && $height > 0;
            $aspect = $dimensions ? intdiv($width * 1000, $height) : 0;
            $shape = ! $dimensions ? 0 : ($aspect < 900 ? 1 : ($aspect <= 1100 ? 2 : 3));
            $score = $format !== 0 ? 1000 : 0;
            if ($dimensions) {
                $score += intdiv(min($width * $height, 50_000_000), 10_000);
                if ($width < 100 || $height < 100) {
                    $score -= 1500;
                }
            }
            if ($kind === 2) {
                $score += $shape === 1 && $aspect >= 550 ? 3000 : 1500;
            } elseif ($kind === 1) {
                $score -= 2000;
            }
            if ($mismatch) {
                $score -= 500;
            }
            if ($length >= 64 && $distinct < 32) {
                $score -= 800;
            }
            if ($collision > 200_000) {
                $score -= 400;
            }
            $score += min(max($postedSizes[$i] ?? 0, 0) >> 10, 4096) >> 3;
            $out[1 + $format]++;
            $out[7] += (int) $dimensions;
            $out[8] += (int) ($kind === 2);
            $out[9] += (int) ($kind === 1);
            $digest = (($digest ^ ($format | $kind << 4 | $shape << 8)) * 16_777_619) & 0xFFFFFFFF;
            $out[] = $format | $extension << 4 | $kind << 8 | $shape << 12 | (int) $mismatch << 16;
            $out[] = $width;
            $out[] = $height;
            $out[] = $aspect;
            $out[] = $distinct << 32 | $collision;
            $out[] = $score;
            $scores[] = $score;
        }
        $order = self::w48Rank($scores);
        if ($order !== []) {
            $out[10] = $order[0];
        }
        $out[11] = $digest;

        return [...$out, ...$order];
    }

    /**
     * Rust scores, or null when PHP must compute them (unavailable library or rejected input).
     *
     * @param  array<array-key, string>  $names
     * @param  array<array-key, string>  $heads
     * @param  array<array-key, int>  $postedSizes
     * @return list<int>|null
     */
    public static function scoreImageBatchNative(array $names, array $heads, array $postedSizes): ?array
    {
        $names = array_values($names);
        $count = \count($names);
        $fields = self::W48_IMAGE_HEADER + (self::W48_IMAGE_WIDTH + 1) * $count;
        $binding = $count <= self::W48_MAX_ITEMS ? self::w48Binding($fields) : null;
        if ($binding === null) {
            return null;
        }
        [$ffi, $out] = $binding;
        $joined = implode("\0", $names);
        $lens = '';
        if ($count > 0 && substr_count($joined, "\0") !== $count - 1) {
            $joined = implode('', $names);
            $lens = pack('V*', ...array_map(strlen(...), $names));
        }
        $heads = \count($heads) === $count && array_is_list($heads) ? $heads : array_pad(\array_slice(array_values($heads), 0, $count), $count, '');
        $headBytes = implode('', $heads);
        $headLens = pack('V*', ...array_map(strlen(...), $heads));
        $sizes = pack('q*', ...(\count($postedSizes) === $count && array_is_list($postedSizes) ? $postedSizes : array_pad(\array_slice(array_values($postedSizes), 0, $count), $count, 0)));
        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_w48_image_score($joined, \strlen($joined), $lens, \strlen($lens), $headBytes, \strlen($headBytes), $headLens, \strlen($headLens), $sizes, \strlen($sizes), $count, $out, $fields);
            if ($status !== 1) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $fields * 8));
        } catch (Throwable) {
            return null;
        }

        return $values === false ? null : array_values($values);
    }

    private static function w48Format(string $head): int
    {
        return match (true) {
            str_starts_with($head, "\xFF\xD8\xFF") => 1,
            str_starts_with($head, "\x89PNG\r\n\x1A\n") => 2,
            str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a') => 3,
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' => 4,
            str_starts_with($head, 'BM') => 5,
            default => 0,
        };
    }

    /** @return array{0: int, 1: int} */
    private static function w48Dimensions(int $format, string $head, int $length): array
    {
        $width = $height = 0;
        if ($format === 1) {
            [$width, $height] = self::w48JpegDimensions($head, $length);
        } elseif ($format === 2 && $length >= 24 && substr($head, 12, 4) === 'IHDR') {
            /** @var array{w: int, h: int} $size */
            $size = unpack('Nw/Nh', $head, 16);
            [$width, $height] = [$size['w'], $size['h']];
        } elseif ($format === 3 && $length >= 10) {
            /** @var array{w: int, h: int} $size */
            $size = unpack('vw/vh', $head, 6);
            [$width, $height] = [$size['w'], $size['h']];
        } elseif ($format === 4 && $length >= 16) {
            $chunk = substr($head, 12, 4);
            if ($chunk === 'VP8 ' && $length >= 30) {
                /** @var array{w: int, h: int} $size */
                $size = unpack('vw/vh', $head, 26);
                [$width, $height] = [$size['w'] & 0x3FFF, $size['h'] & 0x3FFF];
            } elseif ($chunk === 'VP8L' && $length >= 25) {
                [$a, $b, $c, $d] = [\ord($head[21]), \ord($head[22]), \ord($head[23]), \ord($head[24])];
                [$width, $height] = [1 + ($a | ($b & 0x3F) << 8), 1 + ($b >> 6 | $c << 2 | ($d & 0x0F) << 10)];
            } elseif ($chunk === 'VP8X' && $length >= 30) {
                $width = 1 + (\ord($head[24]) | \ord($head[25]) << 8 | \ord($head[26]) << 16);
                $height = 1 + (\ord($head[27]) | \ord($head[28]) << 8 | \ord($head[29]) << 16);
            }
        } elseif ($format === 5 && $length >= 26) {
            /** @var array{w: int, h: int} $size */
            $size = unpack('Vw/Vh', $head, 18);
            $width = abs($size['w'] >= 0x80000000 ? $size['w'] - 0x100000000 : $size['w']);
            $height = abs($size['h'] >= 0x80000000 ? $size['h'] - 0x100000000 : $size['h']);
        }

        return [min($width, self::W48_DIMENSION_CAP), min($height, self::W48_DIMENSION_CAP)];
    }

    /** @return array{0: int, 1: int} */
    private static function w48JpegDimensions(string $head, int $length): array
    {
        $at = 2;
        while ($at + 4 <= $length) {
            if ($head[$at] !== "\xFF") {
                break;
            }
            $marker = \ord($head[$at + 1]);
            if ($marker === 0xFF) {
                $at++;

                continue;
            }
            if ($marker === 0xD8 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $at += 2;

                continue;
            }
            if ($marker === 0xD9 || $marker === 0xDA) {
                break;
            }
            $segment = \ord($head[$at + 2]) << 8 | \ord($head[$at + 3]);
            if ($segment < 2) {
                break;
            }
            if ($marker >= 0xC0 && $marker <= 0xCF && $marker !== 0xC4 && $marker !== 0xC8 && $marker !== 0xCC) {
                if ($at + 9 <= $length) {
                    return [\ord($head[$at + 7]) << 8 | \ord($head[$at + 8]), \ord($head[$at + 5]) << 8 | \ord($head[$at + 6])];
                }
                break;
            }
            $at += 2 + $segment;
        }

        return [0, 0];
    }

    /** @param  list<string>  $needles */
    private static function w48ContainsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Indices by descending score, then ascending index.
     *
     * @param  list<int>  $scores
     * @return list<int>
     */
    private static function w48Rank(array $scores): array
    {
        if ($scores === []) {
            return [];
        }
        $indexes = array_keys($scores);
        array_multisort($scores, SORT_DESC, SORT_NUMERIC, $indexes, SORT_ASC, SORT_NUMERIC);

        return $indexes;
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
}
