<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;

/**
 * A validated network body and its decoded bytes travel together without decoding twice. (Each property is
 * readonly; the class itself cannot be, because it holds the static Rust toggle.)
 */
final class FetchedArticle
{
    public const string FFI_CDEF = CandidateRepository::FFI_CDEF;

    /** Rust stays opt-in: the FFI round trip is slower than this PHP helper (< 1.5x). */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    public function __construct(public readonly string $raw, public readonly VerifiedArticle $article) {}

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    /**
     * How the fetched body relates to the decoded segment it carries.
     *
     * @return array{raw_bytes: int, decoded_bytes: int, overhead_bytes: int, end_offset: ?int, within_file: bool, final_part: ?bool}
     */
    public function summary(): array
    {
        return self::summarizeTransfer(\strlen($this->raw), $this->article->size, $this->article->offset, \strlen($this->article->bytes),
            $this->article->part, $this->article->totalParts);
    }

    /**
     * Summarize one fetched article: its wire and decoded sizes (negative counts read as zero), the encoding
     * overhead, where the decoded bytes end in the file (null for a negative or overflowing offset), whether
     * that end lies within the declared file size, and whether this is the final part (null when unknown).
     *
     * @return array{raw_bytes: int, decoded_bytes: int, overhead_bytes: int, end_offset: ?int, within_file: bool, final_part: ?bool}
     */
    public static function summarizeTransfer(int $rawBytes, int $size, int $offset, int $decodedBytes, int $part, ?int $totalParts): array
    {
        return self::usesRustFfi() ? self::summarizeTransferNative($rawBytes, $size, $offset, $decodedBytes, $part, $totalParts)
            : self::summarizeTransferPhp($rawBytes, $size, $offset, $decodedBytes, $part, $totalParts);
    }

    /** @return array{raw_bytes: int, decoded_bytes: int, overhead_bytes: int, end_offset: ?int, within_file: bool, final_part: ?bool} */
    public static function summarizeTransferPhp(int $rawBytes, int $size, int $offset, int $decodedBytes, int $part, ?int $totalParts): array
    {
        $raw = max(0, $rawBytes);
        $decoded = max(0, $decodedBytes);
        $end = $offset >= 0 && $offset <= PHP_INT_MAX - $decoded ? $offset + $decoded : null;

        return [
            'raw_bytes' => $raw,
            'decoded_bytes' => $decoded,
            'overhead_bytes' => $raw - $decoded,
            'end_offset' => $end,
            'within_file' => $end !== null && $end <= $size,
            'final_part' => $totalParts === null ? null : $part === $totalParts,
        ];
    }

    /** @return array{raw_bytes: int, decoded_bytes: int, overhead_bytes: int, end_offset: ?int, within_file: bool, final_part: ?bool} */
    public static function summarizeTransferNative(int $rawBytes, int $size, int $offset, int $decodedBytes, int $part, ?int $totalParts): array
    {
        $slots = CandidateRepository::nativeSlots(CandidateRepository::evaluateNative(5,
            pack('q7', $rawBytes, $size, $offset, $decodedBytes, $part, (int) ($totalParts !== null), $totalParts ?? 0)), 7);
        if ($slots !== null && $slots[0] >= 0 && $slots[1] >= 0 && \in_array($slots[3], [0, 1], true) && \in_array($slots[5], [0, 1], true)
            && \in_array($slots[6], [-1, 0, 1], true)) {
            return [
                'raw_bytes' => $slots[0],
                'decoded_bytes' => $slots[1],
                'overhead_bytes' => $slots[2],
                'end_offset' => $slots[3] === 1 ? $slots[4] : null,
                'within_file' => $slots[5] === 1,
                'final_part' => $slots[6] === -1 ? null : $slots[6] === 1,
            ];
        }

        return self::summarizeTransferPhp($rawBytes, $size, $offset, $decodedBytes, $part, $totalParts);
    }

    /** Wave 50.2 paired benchmarks decide this default; see RustVerificationDbLockDirtyFetchedFileLockHeartbeatW50ParityTest. */
    private const W50_RUST_FFI_DEFAULT = true;

    public const string W50_FFI_CDEF = <<<'C'
        intptr_t nntmux_w502_fetched_article_transfer_batch(const char *ints, size_t ints_len, size_t count, int64_t size, int64_t total_parts, int64_t has_total, int64_t *out, size_t out_cap);
        C;

    /**
     * Wave 50 routing, binding and output buffer, kept in function-local statics. Operations:
     * null reads the routing override, 'set' replaces it (returning the previous one), 'ffi'
     * resolves the binding and 'out' returns a buffer of at least $val words (its capacity
     * through 'cap').
     */
    private static function w50RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w50', self::W50_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w50RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return $out;
            case 'cap':
                return $capacity;
            default:
                return $enabled;
        }
    }

    public static function usesW50RustFfi(): bool
    {
        $enabled = self::w50RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W50_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with summarizeTransfers() routed to Rust ($enabled) or PHP, then
     * restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW50RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w50RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w50RustFfiState('set', $previous);
        }
    }

    /**
     * Batch transfer summaries, in summarizeTransfer() terms, for the fetched articles of one
     * yEnc file, which all declare its $size and $totalParts. One word per article: its end
     * offset (-1 when null). One flag byte per article follows, packed eight to a word (article
     * i at bits 8 * (i % 8) of word i / 8; flags: 1 end offset known, 2 within the declared file
     * size, 4 part total known, 8 final part, 16 negative raw size read as zero, 32 negative
     * decoded size read as zero). Saturated raw and decoded totals, within-file and final-part
     * counts and the XXH64 digest of all earlier words follow.
     *
     * @param  list<int>  $rawBytes
     * @param  list<int>  $offsets
     * @param  list<int>  $decodedBytes
     * @param  list<int>  $parts
     * @return list<int>
     */
    public static function summarizeTransfers(int $size, ?int $totalParts, array $rawBytes, array $offsets, array $decodedBytes, array $parts): array
    {
        return self::usesW50RustFfi()
            ? self::summarizeTransfersNative($size, $totalParts, $rawBytes, $offsets, $decodedBytes, $parts)
            : self::summarizeTransfersPhp($size, $totalParts, $rawBytes, $offsets, $decodedBytes, $parts);
    }

    /**
     * @param  list<int>  $rawBytes
     * @param  list<int>  $offsets
     * @param  list<int>  $decodedBytes
     * @param  list<int>  $parts
     * @return list<int>
     */
    public static function summarizeTransfersPhp(int $size, ?int $totalParts, array $rawBytes, array $offsets, array $decodedBytes, array $parts): array
    {
        $out = [];
        $masks = $rawBytes === [] ? [] : array_fill(0, (\count($rawBytes) + 7) >> 3, 0);
        $rawTotal = $decodedTotal = $within = $finals = $k = 0;
        foreach ($rawBytes as $i => $rawValue) {
            $decodedValue = $decodedBytes[$i] ?? 0;
            $offset = $offsets[$i] ?? 0;
            $raw = max(0, $rawValue);
            $decoded = max(0, $decodedValue);
            $flags = (int) ($rawValue < 0) << 4 | (int) ($decodedValue < 0) << 5;
            $end = -1;
            if ($offset >= 0 && $offset <= PHP_INT_MAX - $decoded) {
                $flags |= 1;
                $end = $offset + $decoded;
                if ($end <= $size) {
                    $flags |= 2;
                    $within++;
                }
            }
            if ($totalParts !== null) {
                $flags |= 4;
                if (($parts[$i] ?? 0) === $totalParts) {
                    $flags |= 8;
                    $finals++;
                }
            }
            $rawTotal = $raw > PHP_INT_MAX - $rawTotal ? PHP_INT_MAX : $rawTotal + $raw;
            $decodedTotal = $decoded > PHP_INT_MAX - $decodedTotal ? PHP_INT_MAX : $decodedTotal + $decoded;
            $out[] = $end;
            $masks[$k >> 3] |= $flags << (($k & 7) << 3);
            $k++;
        }
        foreach ($masks as $mask) {
            $out[] = $mask;
        }
        $out[] = $rawTotal;
        $out[] = $decodedTotal;
        $out[] = $within;
        $out[] = $finals;
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));
        $out[] = $digest[1];

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or the columns differ in length
     * or are not lists.
     *
     * @param  list<int>  $rawBytes
     * @param  list<int>  $offsets
     * @param  list<int>  $decodedBytes
     * @param  list<int>  $parts
     * @return list<int>
     */
    public static function summarizeTransfersNative(int $size, ?int $totalParts, array $rawBytes, array $offsets, array $decodedBytes, array $parts): array
    {
        $count = \count($rawBytes);
        $ffi = $count === 0 ? null : self::w50RustFfiState('ffi');
        if (! $ffi instanceof FFI || \count($offsets) !== $count || \count($decodedBytes) !== $count || \count($parts) !== $count
            || ! array_is_list($rawBytes) || ! array_is_list($offsets) || ! array_is_list($decodedBytes) || ! array_is_list($parts)) {
            return self::summarizeTransfersPhp($size, $totalParts, $rawBytes, $offsets, $decodedBytes, $parts);
        }
        $words = $count + (($count + 7) >> 3) + 5;
        $values = false;
        try {
            $out = self::w50RustFfiState('out', $words);
            if ($out instanceof CData) {
                $ints = pack('P*', ...$rawBytes, ...$offsets, ...$decodedBytes, ...$parts);
                // @phpstan-ignore method.notFound (Declared by W50_FFI_CDEF.)
                $written = $ffi->nntmux_w502_fetched_article_transfer_batch($ints, \strlen($ints), $count, $size, $totalParts ?? 0, (int) ($totalParts !== null), $out, self::w50RustFfiState('cap'));
                $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;
            }
        } catch (\Throwable) {
            $values = false;
        }

        /** @var list<int>|null $result */
        $result = \is_array($values) ? array_values($values) : null;

        return $result ?? self::summarizeTransfersPhp($size, $totalParts, $rawBytes, $offsets, $decodedBytes, $parts);
    }
}
