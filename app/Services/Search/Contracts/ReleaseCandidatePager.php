<?php

namespace App\Services\Search\Contracts;

interface ReleaseCandidatePager
{
    /**
     * Coarse candidates matching the complete title query in either release name field, without fuzzy fallback.
     * IDs are numeric ascending and strictly greater than afterId; one lookahead determines has_more.
     * Unsupported or failed searches must not claim an available, complete empty page.
     *
     * @return array{ids: list<int>, available: bool, has_more: bool}
     */
    public function searchReleaseCandidatesPage(string $title, int $limit = 21, int $afterId = 0): array;
}
