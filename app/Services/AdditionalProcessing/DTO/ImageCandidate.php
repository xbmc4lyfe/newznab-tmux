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

    // PHP stays the default: the paired W53.6 benchmark keeps uncommon p99 below 1.5x (copying long ID
    // lists across FFI costs about as much as the deduplication PHP does natively).
    private const bool W53_RUST_FFI_DEFAULT = false;

    public const string W53_FFI_CDEF = <<<'C'
        int32_t nntmux_w536_message_id_plan_batch(const char *ids, size_t ids_len, const char *id_twin, size_t id_twin_len, const char *counts, size_t counts_len, size_t count, int64_t *out, size_t out_count);
        C;

    public const int W53_MESSAGE_HEADER = 8;

    /** Saturation of a 14-bit row count. */
    private const int W53_FIELD_CAP = 16_383;

    private const int W53_MAX_ITEMS = 1_048_576;

    /**
     * `normalizeCandidateDescriptorPhp()`'s message-ID cleanup over candidates, plus cross-candidate sharing.
     *
     * IDs are trimmed; empty ones and repeats within a candidate are dropped. Layout: `[0]`
     * candidates, `[1]` IDs, `[2]` kept IDs, `[3]` distinct IDs, `[4]` candidates without a kept ID,
     * `[5]` candidates sharing an ID with an earlier candidate, `[6]` most IDs kept by one candidate,
     * `[7]` FNV-1a digest; then per candidate `kept | dropped << 14 | shared << 28 | leader << 42`,
     * the counts saturating at 2^14-1 and `leader` the earliest candidate it shares an ID with
     * (itself when none). Rust handles up to 2^20 candidates and IDs; PHP computes larger batches.
     *
     * @param  array<array-key, array<array-key, string>>  $messageIdLists
     * @return list<int>
     */
    public static function messageIdPlanBatch(array $messageIdLists): array
    {
        return (self::usesW53RustFfi() ? self::messageIdPlanBatchNative($messageIdLists) : null)
            ?? self::messageIdPlanBatchPhp($messageIdLists);
    }

    /**
     * @param  array<array-key, array<array-key, string>>  $messageIdLists
     * @return list<int>
     */
    public static function messageIdPlanBatchPhp(array $messageIdLists): array
    {
        $cap = self::W53_FIELD_CAP;
        $out = array_fill(0, self::W53_MESSAGE_HEADER, 0);
        $digest = 2_166_136_261;
        $first = $last = $rows = [];
        $candidate = 0;
        foreach ($messageIdLists as $ids) {
            $kept = $dropped = $shared = 0;
            $leader = $candidate;
            foreach ($ids as $id) {
                $id = trim($id);
                if ($id === '') {
                    $dropped++;

                    continue;
                }
                $seen = $last[$id] ?? null;
                if ($seen === null) {
                    $first[$id] = $last[$id] = $candidate;
                    $kept++;
                    $out[3]++;
                } elseif ($seen === $candidate) {
                    $dropped++;
                } else {
                    $last[$id] = $candidate;
                    $kept++;
                    $shared++;
                    if ($first[$id] < $leader) {
                        $leader = $first[$id];
                    }
                }
            }
            $out[1] += \count($ids);
            $out[2] += $kept;
            if ($kept === 0) {
                $out[4]++;
            }
            if ($shared > 0) {
                $out[5]++;
            }
            if ($kept > $out[6]) {
                $out[6] = $kept;
            }
            $row = min($kept, $cap) | min($dropped, $cap) << 14 | min($shared, $cap) << 28 | $leader << 42;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
            $candidate++;
        }
        $out[0] = $candidate;
        $out[7] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust plans, or null when PHP must compute them (unavailable library or rejected input).
     *
     * @param  array<array-key, array<array-key, string>>  $messageIdLists
     * @return list<int>|null
     */
    public static function messageIdPlanBatchNative(array $messageIdLists): ?array
    {
        $lists = array_is_list($messageIdLists) ? $messageIdLists : array_values($messageIdLists);
        $count = \count($lists);
        $counts = [];
        foreach ($lists as $ids) {
            $counts[] = \count($ids);
        }
        $ids = $lists === [] ? [] : array_merge(...$lists);
        if (\count($ids) !== array_sum($counts)) {
            // array_merge() collapsed string keys repeated across candidates.
            $ids = [];
            foreach ($lists as $list) {
                foreach ($list as $id) {
                    $ids[] = $id;
                }
            }
        }
        if ($count > self::W53_MAX_ITEMS || \count($ids) > self::W53_MAX_ITEMS) {
            return null;
        }
        $packed = pack('q*', ...$counts);
        $fields = self::W53_MESSAGE_HEADER + $count;
        $plan = static fn (string $joined, string $twin): ?array => self::w53Call(
            $fields,
            // @phpstan-ignore method.notFound
            static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w536_message_id_plan_batch($joined, \strlen($joined), $twin, \strlen($twin), $packed, \strlen($packed), $count, $out, $fields),
        );
        // IDs are joined with "\0\x01"; Rust rejects the batch when an ID holds that pair (it then
        // splits into too many items), and it is resent NUL-joined with the same list joined with 0x01.
        $result = $plan(implode("\0\x01", $ids), '');
        if ($result !== null || $ids === [] || self::w53RustFfiState('binding', $fields) === null) {
            return $result;
        }

        return $plan(implode("\0", $ids), implode("\x01", $ids));
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

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 6.21x, p99 4.44x; uncommon mean 5.48x, p99 4.43x); see RustAdditionalDownloadMetricsArchiveImageMappedNzbSourceHintsW64ParityTest. */
    public const bool W64_RUST_FFI_DEFAULT = true;

    public const string W64_IMAGE_CANDIDATE_FFI_CDEF = <<<'C'
        ssize_t nntmux_w643_image_candidate_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of the Wave 64.3 batch. */
    public const int W64_IMAGE_CANDIDATE_TRAILER = 16;

    /** Cap for the Wave 64.3 running totals. */
    private const int W64_TOTAL_CAP = 4_611_686_018_427_387_904;

    /** Lower-cased extension => format (1 JPEG, 2 PNG, 3 GIF, 4 WebP, 5 BMP). */
    private const array W64_IMAGE_EXTENSIONS = ['jpg' => 1, 'jpeg' => 1, 'png' => 2, 'gif' => 3, 'webp' => 4, 'bmp' => 5];

    private const array W64_COVER_WORDS = ['cover' => true, 'front' => true, 'poster' => true, 'folder' => true];

    private const array W64_SAMPLE_WORDS = ['sample' => true, 'screen' => true, 'screens' => true, 'screenshot' => true, 'thumb' => true, 'proof' => true, 'back' => true];

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
     * Runs $callback with the ImageCandidate Wave 64 Rust path forced on or off, then restores the previous setting.
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
     * Wave 64.3 image candidate format, dimension, byte size, aspect ratio and cover/sample priority plans. Each item is
     * `name|width|height|bytes|head`, where the head (the rest of the item, raw bytes) is the image's first bytes. The
     * extension format of the trimmed name is 1 jpg/jpeg, 2 png, 3 gif, 4 webp, 5 bmp (any case), else 0; the head format
     * is 0 empty, 1 JPEG, 2 PNG, 3 GIF87a/89a, 4 RIFF....WEBP, 5 BM, 6 other. The format is the head's when known, the
     * extension's without a head, else 0; the match is 0 without a head, 2 for an unknown head or a different known
     * extension, else 1. Width and height (up to 6 digits) and bytes (up to 12) are 0 empty, 1 trimmed digits, 2 other;
     * dimensions are valid when both are positive, the aspect is width * 1000 / height (capped at 65535). The role is 1
     * when a lower-cased `[a-z0-9]+` name token is cover/front/poster/folder, else 2 for sample/screen/screens/screenshot/
     * thumb/proof/back, else 0; tiny is valid bytes under 10240 or valid dimensions whose larger side is under 100. The
     * score is 40 for a format + 30 cover + 20 when the smaller side is 300+ + 10 for an aspect of 600-800 + 10 for a match
     * - 20 sample - 15 tiny - 25 for a mismatch, clamped to 0-127. Per item `extensionFormat | headFormat << 3 | format <<
     * 6 | match << 9 | widthState << 11 | heightState << 13 | bytesState << 15 | role << 17 | tiny << 19 | score << 20 |
     * aspect << 27 | min(tokens, 255) << 43 | min(nameLength, 255) << 51`. Then the six format counts, the three role
     * counts, the tiny count, the 1-based index of the first strictly best positive cover score, of the first strictly
     * best positive score and that score, the byte total (capped at 2^62), the distinct names (case-insensitive) and the
     * items with valid dimensions.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w64ImageCandidatePlanBatch(array $items): array
    {
        return self::usesW64RustFfi() ? self::w64ImageCandidatePlanBatchNative($items) : self::w64ImageCandidatePlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w64ImageCandidatePlanBatchNative(array $items): array
    {
        return self::w64Invoke($items, \count($items) + self::W64_IMAGE_CANDIDATE_TRAILER) ?? self::w64ImageCandidatePlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w64ImageCandidatePlanBatchPhp(array $items): array
    {
        $out = [];
        $formats = [0, 0, 0, 0, 0, 0];
        $roles = [0, 0, 0];
        $tinyCount = $cover = $coverScore = $best = $bestScore = $bytesSum = $sized = $index = 0;
        $names = [];
        foreach ($items as $item) {
            $index++;
            [$rawName, $rawWidth, $rawHeight, $rawBytes, $head] = explode('|', (string) $item, 5) + ['', '', '', '', ''];
            $name = trim($rawName);
            $lower = strtolower($name);
            $dot = strrpos($lower, '.');
            $extensionFormat = $dot === false ? 0 : (self::W64_IMAGE_EXTENSIONS[substr($lower, $dot + 1)] ?? 0);
            $headFormat = self::w64ImageSignature($head);
            $format = $headFormat >= 1 && $headFormat <= 5 ? $headFormat : ($headFormat === 0 ? $extensionFormat : 0);
            $match = $headFormat === 0 ? 0 : ($headFormat !== 6 && ($extensionFormat === 0 || $extensionFormat === $headFormat) ? 1 : 2);
            [$widthState, $width] = self::w64Count($rawWidth, 6);
            [$heightState, $height] = self::w64Count($rawHeight, 6);
            [$bytesState, $bytes] = self::w64Count($rawBytes, 12);
            $dims = $widthState === 1 && $heightState === 1 && $width > 0 && $height > 0;
            $aspect = $dims ? min(intdiv($width * 1000, $height), 65_535) : 0;
            $tokens = preg_split('/[^a-z0-9]+/', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $isCover = $isSample = false;
            foreach ($tokens as $token) {
                $isCover = $isCover || isset(self::W64_COVER_WORDS[$token]);
                $isSample = $isSample || isset(self::W64_SAMPLE_WORDS[$token]);
            }
            $role = $isCover ? 1 : ($isSample ? 2 : 0);
            $tiny = ($bytesState === 1 && $bytes < 10_240) || ($dims && max($width, $height) < 100);
            $score = max(0, min(127, ($format !== 0 ? 40 : 0) + ($role === 1 ? 30 : 0) + ($dims && min($width, $height) >= 300 ? 20 : 0)
                + ($aspect >= 600 && $aspect <= 800 ? 10 : 0) + ($match === 1 ? 10 : 0) - ($role === 2 ? 20 : 0) - ($tiny ? 15 : 0)
                - ($match === 2 ? 25 : 0)));
            $formats[$format]++;
            $roles[$role]++;
            $tinyCount += $tiny ? 1 : 0;
            if ($role === 1 && $score > $coverScore) {
                $cover = $index;
                $coverScore = $score;
            }
            if ($score > $bestScore) {
                $best = $index;
                $bestScore = $score;
            }
            $bytesSum = min($bytesSum + $bytes, self::W64_TOTAL_CAP);
            $sized += $dims ? 1 : 0;
            if ($name !== '') {
                $names[$lower] = true;
            }
            $out[] = $extensionFormat | $headFormat << 3 | $format << 6 | $match << 9 | $widthState << 11 | $heightState << 13
                | $bytesState << 15 | $role << 17 | ($tiny ? 1 : 0) << 19 | $score << 20 | $aspect << 27
                | min(\count($tokens), 255) << 43 | min(\strlen($name), 255) << 51;
        }
        array_push($out, ...$formats, ...$roles);
        array_push($out, $tinyCount, $cover, $best, $bestScore, $bytesSum, \count($names), $sized);

        return $out;
    }

    /** 0 empty, 1 JPEG, 2 PNG, 3 GIF, 4 WebP, 5 BMP, 6 other. */
    private static function w64ImageSignature(string $head): int
    {
        return match (true) {
            $head === '' => 0,
            str_starts_with($head, "\xff\xd8\xff") => 1,
            str_starts_with($head, "\x89PNG\r\n\x1a\n") => 2,
            str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a') => 3,
            \strlen($head) >= 12 && str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' => 4,
            str_starts_with($head, 'BM') => 5,
            default => 6,
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
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w643', self::W64_IMAGE_CANDIDATE_FFI_CDEF) ?? false;

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
     * Calls the Wave 64.3 ImageCandidate export with the NUL-joined $items, or with the items concatenated plus an
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
            // @phpstan-ignore method.notFound (Declared by W64_IMAGE_CANDIDATE_FFI_CDEF.)
            $written = $ffi->nntmux_w643_image_candidate_batch($blob, \strlen($blob), $lens, $count, $out, $words);
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
