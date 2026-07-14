<?php

declare(strict_types=1);

namespace App\Services\Search\DTO;

final readonly class SearchCursor
{
    /** @param list<int|float|string> $sortValues */
    public function __construct(
        public array $sortValues,
        public int $total,
        public string $queryHash,
        public string $driver,
        public string $indexGeneration,
        public int $expiresAt,
    ) {}
}
