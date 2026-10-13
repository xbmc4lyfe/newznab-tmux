<?php

declare(strict_types=1);

namespace App\Support;

final readonly class Par2CandidateCheckpoint
{
    /**
     * @param  list<int>  $retrySegments
     * @param  array{version: 1, packets: list<string>, tail: string, skip_remaining: int}|null  $accumulatorState
     * @param  array{name_base64: string, size: int, part: ?int}|null  $articleIdentity
     */
    public function __construct(
        public int $nextSegment,
        public array $retrySegments,
        public ?array $accumulatorState,
        public int $storedBytes,
        public ?string $blockedReason,
        public int $decodedOffset = 0,
        public ?array $articleIdentity = null,
    ) {}
}
