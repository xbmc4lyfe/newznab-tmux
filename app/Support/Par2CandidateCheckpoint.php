<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Releases\Verification\CandidateRepository;

/** Each property is readonly; the class itself cannot be, because it holds the static Rust toggle. */
final class Par2CandidateCheckpoint
{
    public const string FFI_CDEF = CandidateRepository::FFI_CDEF;

    /** Rust stays opt-in: the FFI round trip is slower than this PHP helper (< 1.5x). */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    /**
     * @param  list<int>  $retrySegments
     * @param  array{version: 1, packets: list<string>, tail: string, skip_remaining: int}|null  $accumulatorState
     * @param  array{name_base64: string, size: int, part: ?int}|null  $articleIdentity
     */
    public function __construct(
        public readonly int $nextSegment,
        public readonly array $retrySegments,
        public readonly ?array $accumulatorState,
        public readonly int $storedBytes,
        public readonly ?string $blockedReason,
        public readonly int $decodedOffset = 0,
        public readonly ?array $articleIdentity = null,
    ) {}

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    /**
     * This checkpoint's progress through a candidate of $segmentCount articles.
     *
     * @return array{blocked: bool, resumable: bool, remaining_segments: int, retry_count: int, complete: bool}
     */
    public function progress(int $segmentCount): array
    {
        return self::summarizeProgress($this->nextSegment, \count($this->retrySegments), $this->accumulatorState !== null,
            $this->blockedReason !== null, $segmentCount);
    }

    /**
     * Summarize a checkpoint cursor: a blocked candidate neither resumes nor completes; an unblocked one resumes
     * when it has read articles, owes retries or holds packet state, and completes once no article is left to
     * read or retry. Negative counts read as zero.
     *
     * @return array{blocked: bool, resumable: bool, remaining_segments: int, retry_count: int, complete: bool}
     */
    public static function summarizeProgress(int $nextSegment, int $retryCount, bool $hasState, bool $blocked, int $segmentCount): array
    {
        return self::usesRustFfi() ? self::summarizeProgressNative($nextSegment, $retryCount, $hasState, $blocked, $segmentCount)
            : self::summarizeProgressPhp($nextSegment, $retryCount, $hasState, $blocked, $segmentCount);
    }

    /** @return array{blocked: bool, resumable: bool, remaining_segments: int, retry_count: int, complete: bool} */
    public static function summarizeProgressPhp(int $nextSegment, int $retryCount, bool $hasState, bool $blocked, int $segmentCount): array
    {
        $next = max(0, $nextSegment);
        $count = max(0, $segmentCount);
        $retries = max(0, $retryCount);
        $remaining = $count > $next ? $count - $next : 0;

        return [
            'blocked' => $blocked,
            'resumable' => ! $blocked && ($next > 0 || $retries > 0 || $hasState),
            'remaining_segments' => $remaining,
            'retry_count' => $retries,
            'complete' => ! $blocked && $remaining === 0 && $retries === 0,
        ];
    }

    /** @return array{blocked: bool, resumable: bool, remaining_segments: int, retry_count: int, complete: bool} */
    public static function summarizeProgressNative(int $nextSegment, int $retryCount, bool $hasState, bool $blocked, int $segmentCount): array
    {
        $slots = CandidateRepository::nativeSlots(CandidateRepository::evaluateNative(4,
            pack('q5', $nextSegment, $retryCount, (int) $hasState, (int) $blocked, $segmentCount)), 5);
        if ($slots !== null && \in_array($slots[0], [0, 1], true) && \in_array($slots[1], [0, 1], true) && $slots[2] >= 0 && $slots[3] >= 0
            && \in_array($slots[4], [0, 1], true)) {
            return [
                'blocked' => $slots[0] === 1,
                'resumable' => $slots[1] === 1,
                'remaining_segments' => $slots[2],
                'retry_count' => $slots[3],
                'complete' => $slots[4] === 1,
            ];
        }

        return self::summarizeProgressPhp($nextSegment, $retryCount, $hasState, $blocked, $segmentCount);
    }
}
