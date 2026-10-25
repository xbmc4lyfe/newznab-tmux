<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

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
}
