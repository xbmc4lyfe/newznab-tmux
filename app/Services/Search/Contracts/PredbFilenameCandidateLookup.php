<?php

declare(strict_types=1);

namespace App\Services\Search\Contracts;

interface PredbFilenameCandidateLookup
{
    /**
     * Fresh, complete filename nominations from one backend response.
     * Null means unsupported, failed, or completeness unproven; never an empty match set.
     *
     * @return list<array{id: int, title: string, filename: string, source: string}>|null
     */
    public function searchPredbFilenameCandidates(string $searchTerm): ?array;
}
