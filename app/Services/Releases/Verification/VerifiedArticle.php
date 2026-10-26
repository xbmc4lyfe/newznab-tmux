<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Services\Yenc\ArticleFrame;
use App\Services\YencService;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final readonly class VerifiedArticle
{
    private function __construct(
        public string $name,
        public int $size,
        public int $offset,
        public int $part,
        public ?int $totalParts,
        public string $bytes,
        public ?string $fileChecksum,
    ) {}

    public static function decode(string $raw, ?YencService $decoder = null): self
    {
        return self::decodePhp($raw, $decoder);
    }

    public static function setUseRustFfi(bool $enabled): void
    {
        self::useRustFfi($enabled);
    }

    private static function useRustFfi(?bool $enabled = null): bool
    {
        static $useRustFfi = true;
        if ($enabled !== null) {
            $useRustFfi = $enabled;
        }

        return $useRustFfi;
    }

    public static function decodeNative(string $raw, ?YencService $decoder = null): ?self
    {
        if (! self::useRustFfi() || PreparedManifest::nativeLibrary() === null) {
            return null;
        }

        return self::decodeFrame($raw, $decoder, true);
    }

    public static function decodePhp(string $raw, ?YencService $decoder = null): self
    {
        return self::decodeFrame($raw, $decoder, false);
    }

    private static function decodeFrame(string $raw, ?YencService $decoder, bool $native): self
    {
        $frame = ArticleFrame::parse($raw);
        if ($frame === null) {
            throw new RuntimeException('Unsupported article encoding.');
        }
        $name = $frame->header['name'];
        $reason = $native ? self::framingRejectionNative($raw) : self::framingRejectionPhp($frame);
        if ($reason !== null) {
            throw new RuntimeException($reason);
        }
        $totalParts = isset($frame->header['total']) ? (int) $frame->header['total'] : null;
        try {
            $decoded = ($decoder ?? new YencService)->decode($raw);
        } catch (RuntimeException $error) {
            if ($error->getMessage() === 'CRC32 checksums do not match. The file is probably corrupt.') {
                throw new ArticleIntegrityFailure($error->getMessage(), previous: $error);
            }
            throw $error;
        }
        if ($decoded === false) {
            throw new RuntimeException('Article decoding failed.');
        }

        return new self($name, (int) $frame->header['size'], (int) ($frame->part['begin'] ?? 1) - 1,
            (int) ($frame->header['part'] ?? 1), $totalParts, $decoded, isset($frame->trailer['crc32']) ? strtolower(str_pad($frame->trailer['crc32'], 8, '0', STR_PAD_LEFT)) : null);
    }

    public static function framingRejectionPhp(ArticleFrame $frame): ?string
    {
        $name = $frame->header['name'];
        if ($name === '' || ! mb_check_encoding($name, 'UTF-8') || strlen($name) > 200 || $name[0] === '.' || $name[0] === '-'
            || preg_match('/[\x00-\x1f\x7f\/\\\\]/', $name)) {
            return 'Unsafe article filename.';
        }
        $numbers = [$frame->header['size'], $frame->header['part'] ?? '1', $frame->part['begin'] ?? '1'];
        if (isset($frame->header['total'])) {
            $numbers[] = $frame->header['total'];
        }
        foreach ($numbers as $number) {
            if (! ctype_digit($number) || strlen($number) > 18 || (int) $number < 1) {
                return 'Invalid article dimensions.';
            }
        }
        $totalParts = isset($frame->header['total']) ? (int) $frame->header['total'] : null;
        if ($totalParts !== null && (int) ($frame->header['part'] ?? 1) > $totalParts) {
            return 'Article part number exceeds its declared total.';
        }
        if (! isset($frame->trailer[$frame->part === null ? 'crc32' : 'pcrc32'])) {
            return 'Article checksum is required.';
        }

        return null;
    }

    public static function framingRejectionNative(string $raw): ?string
    {
        $ffi = PreparedManifest::nativeLibrary();
        if (! self::useRustFfi() || $ffi === null) {
            return self::framingRejectionPhp(ArticleFrame::parse($raw) ?? throw new RuntimeException('Unsupported article encoding.'));
        }
        // @phpstan-ignore method.notFound
        $status = $ffi->nntmux_verification_article_framing($raw, strlen($raw));

        return match ($status) {
            0 => null,
            1 => 'Unsafe article filename.',
            2 => 'Invalid article dimensions.',
            3 => 'Article part number exceeds its declared total.',
            4 => 'Article checksum is required.',
            default => throw new RuntimeException('Unsupported article encoding.'),
        };
    }

    // ========================================================================
    // WAVE 59.4 BATCH HELPER
    // ========================================================================

    /** Disabled: uncommon batches reached only 1.28x mean and 1.36x p99 in the minimum of four paired runs (common mean 1.93x, p99 1.70x), since rejected rows fail fast in PHP while validation, packing and decoding stay fixed; see RustVerificationLockWorkerLocksVerifiedArticleNzbWriterPublisherW59ParityTest. */
    public const bool W59_RUST_FFI_DEFAULT = false;

    public const string W59_FFI_CDEF = <<<'C'
        ssize_t nntmux_w594_verified_article_row_batch(const char *blob, size_t blob_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** int64 words per articleRowBatch() record. */
    public const int W59_ROW_WORDS = 6;

    /** int64 summary words after the articleRowBatch() records (none for an empty batch). */
    public const int W59_TRAILER_WORDS = 8;

    /** Largest article dimension: at most 18 decimal digits, as framingRejectionPhp() allows. */
    private const int W59_DIM_MAX = 999_999_999_999_999_999;

    private const string W59_NAME_BAD = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x7f/\\";

    private const string W59_ID_BAD = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f \x7f<>";

    private const string W59_HEX = '0123456789abcdefABCDEF';

    /**
     * Verified article rows for [name, message-id, crc32, part, total parts (0 when absent),
     * begin, end, size] items, in posting order. Status 0 accepted, then the first rejection of
     * framingRejectionPhp(): 1 unsafe filename, 2 invalid dimensions (part, begin, size and a
     * present total in 1..10^18-1, begin <= end <= size), 3 part beyond its declared total, 4
     * missing or malformed checksum (1-8 hex digits, any case), and 5 malformed message-id (after
     * trim($id, '<>'): 3-250 bytes, an '@' neither first nor last, no space, control byte or
     * angle bracket).
     *
     * Rows [status, offset (begin - 1, -1 for invalid dimensions), part bytes, crc32 value (-1
     * when malformed), message-id span (offset << 32 | length), flags (1 consecutive, 2
     * duplicate part, 4 out of order, 8 declares a total, 16 last part, 32 whole file)], then the
     * trailer [accepted, rejected, accepted bytes (saturating), distinct parts, highest part,
     * declared total (the first accepted one), complete (every part 1..total once with one
     * declared total), FNV-1a digest of `crc32 << 16 ^ flags << 3 ^ status`]. Ordering flags
     * apply to accepted rows only.
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: int, 4: int, 5: int, 6: int, 7: int}>  $items
     * @return list<int>
     */
    public static function articleRowBatch(array $items): array
    {
        return self::usesW59RustFfi() ? self::articleRowBatchNative($items) : self::articleRowBatchPhp($items);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: int, 4: int, 5: int, 6: int, 7: int}>  $items
     * @return list<int>
     */
    public static function articleRowBatchPhp(array $items): array
    {
        self::w59Items($items);
        if ($items === []) {
            return [];
        }
        $out = [];
        $summary = [0, 0, 0, 0, 0, 0, 0, 0];
        $digest = 2_166_136_261;
        $seen = [];
        $previous = null;
        $mismatch = false;
        foreach ($items as [$name, $id, $crc, $part, $total, $begin, $end, $size]) {
            $dims = $part >= 1 && $part <= self::W59_DIM_MAX && $begin >= 1 && $begin <= self::W59_DIM_MAX && $size >= 1 && $size <= self::W59_DIM_MAX
                && ($total === 0 || ($total >= 1 && $total <= self::W59_DIM_MAX)) && $end >= $begin && $end <= $size;
            $crcLength = \strlen($crc);
            $crcValue = $crcLength >= 1 && $crcLength <= 8 && strspn($crc, self::W59_HEX) === $crcLength ? (int) hexdec($crc) : -1;
            $start = strspn($id, '<>');
            $trimmed = trim($id, '<>');
            $length = \strlen($trimmed);
            $nameLength = \strlen($name);
            $status = match (true) {
                $nameLength === 0 || $nameLength > 200 || $name[0] === '.' || $name[0] === '-' || strcspn($name, self::W59_NAME_BAD) !== $nameLength
                    || ! mb_check_encoding($name, 'UTF-8') => 1,
                ! $dims => 2,
                $total > 0 && $part > $total => 3,
                $crcValue < 0 => 4,
                $length < 3 || $length > 250 || ($at = strpos($trimmed, '@')) === false || $at === 0 || $at === $length - 1
                    || strcspn($trimmed, self::W59_ID_BAD) !== $length => 5,
                default => 0,
            };
            $partBytes = $dims ? $end - $begin + 1 : 0;
            $flags = ($total > 0 ? 8 : 0) | ($total > 0 && $part === $total ? 16 : 0) | ($dims && $begin === 1 && $end === $size ? 32 : 0);
            if ($status === 0) {
                $duplicate = isset($seen[$part]);
                $seen[$part] = true;
                $flags |= (($previous === null ? $part === 1 : $part === $previous + 1) ? 1 : 0) | ($duplicate ? 2 : 0)
                    | ($previous !== null && $part <= $previous ? 4 : 0);
                $previous = $part;
                $summary[0]++;
                $summary[2] = $partBytes > PHP_INT_MAX - $summary[2] ? PHP_INT_MAX : $summary[2] + $partBytes;
                $summary[3] += $duplicate ? 0 : 1;
                $summary[4] = max($summary[4], $part);
                if ($total > 0) {
                    if ($summary[5] === 0) {
                        $summary[5] = $total;
                    } elseif ($total !== $summary[5]) {
                        $mismatch = true;
                    }
                }
            } else {
                $summary[1]++;
            }
            array_push($out, $status, $dims ? $begin - 1 : -1, $partBytes, $crcValue, ($start << 32) | $length, $flags);
            $value = ($crcValue << 16) ^ ($flags << 3) ^ $status;
            $digest = (($digest ^ (($value ^ ($value >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
        }
        $summary[6] = $summary[5] > 0 && $summary[3] === $summary[5] && $summary[4] <= $summary[5] && ! $mismatch ? 1 : 0;
        $summary[7] = $digest;

        return [...$out, ...$summary];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: int, 4: int, 5: int, 6: int, 7: int}>  $items
     * @return list<int>
     */
    public static function articleRowBatchNative(array $items): array
    {
        self::w59Items($items);
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        [$blob, $twin] = self::w59Join([array_column($items, 0), array_column($items, 1), array_column($items, 2)], $count);
        $ints = pack('P*', ...array_column($items, 3), ...array_column($items, 4), ...array_column($items, 5), ...array_column($items, 6), ...array_column($items, 7));

        return self::w59Call($blob, $twin, $ints, $count, $count * self::W59_ROW_WORDS + self::W59_TRAILER_WORDS)
            ?? self::articleRowBatchPhp($items);
    }

    public static function usesW59RustFfi(): bool
    {
        $enabled = self::w59RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W59_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W59 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW59RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w59RustFfiState('enabled');
        self::w59RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w59RustFfiState('enabled', $previous);
        }
    }

    /** @param array<array-key, mixed> $items */
    private static function w59Items(array $items): void
    {
        if (! array_is_list($items)) {
            throw new InvalidArgumentException('W59 verified article batch items must be a list.');
        }
        foreach ($items as $item) {
            if (! \is_array($item) || \count($item) !== 8 || ! array_is_list($item) || ! \is_string($item[0]) || ! \is_string($item[1])
                || ! \is_string($item[2]) || ! \is_int($item[3]) || ! \is_int($item[4]) || ! \is_int($item[5]) || ! \is_int($item[6])
                || ! \is_int($item[7])) {
                throw new InvalidArgumentException('W59 verified article batch items must be [string name, string message-id, string crc32, int part, int total parts, int begin, int end, int size] lists.');
            }
        }
    }

    /**
     * String columns NUL-joined field-major and, when a value holds a NUL, the same columns
     * joined by 0x01 so Rust finds the real separators.
     *
     * @param  list<list<string>>  $columns
     * @return array{0: string, 1: string}
     */
    private static function w59Join(array $columns, int $count): array
    {
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = implode("\0", $column);
        }
        $joined = implode("\0", $parts);
        if (substr_count($joined, "\0") === \count($columns) * $count - 1) {
            return [$joined, ''];
        }
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = implode("\x01", $column);
        }

        return [$joined, implode("\x01", $parts)];
    }

    /**
     * One FFI crossing for the batch: $need int64 words back. Null when the library is missing
     * or declines a malformed frame, so the caller falls back to PHP; a declined call with a
     * resolved library is counted in the 'misses' state.
     *
     * @return list<int>|null
     */
    private static function w59Call(string $blob, string $twin, string $ints, int $count, int $need): ?array
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        $state = self::w59RustFfiState();
        $ffi = $state['ffi'];
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata_w59', self::W59_FFI_CDEF) ?? false;
            self::w59RustFfiState('ffi', $ffi);
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
                self::w59RustFfiState('out', $out);
                self::w59RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W59_FFI_CDEF.)
            $written = $ffi->nntmux_w594_verified_article_row_batch($blob, \strlen($blob), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $cap);
            $values = $written === $need ? unpack('P*', FFI::string($out, $need * 8)) : false;
            if ($values !== false) {
                return array_values($values);
            }
        } catch (Throwable) {
        }
        self::w59RustFfiState('misses', $state['misses'] + 1);

        return null;
    }

    /**
     * W59 routing override, cached binding, reusable output buffer and declined-call count, kept
     * in a function static: no arguments returns the state, one argument reads a key ('reset'
     * clears everything) and two arguments write a key.
     */
    private static function w59RustFfiState(?string $op = null, mixed $val = null): mixed
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
