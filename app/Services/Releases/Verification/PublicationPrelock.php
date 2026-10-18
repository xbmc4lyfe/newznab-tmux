<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

/**
 * Work a publication derives from its manifest and unlocked reads before it takes any lock.
 *
 * Every field is optional: a step that was skipped (an outer transaction, a stale claim) or
 * threw leaves its field unset, and the locked section then computes it as it always did.
 * Values are immutable, so each retried transaction attempt reuses them unchanged.
 */
final readonly class PublicationPrelock
{
    /**
     * @param  array<string, mixed>|null  $archived  The candidate's archived manifest, read from $archivedPath.
     * @param  bool  $policyKnown  Whether $policyRejection holds an evaluated collection-policy verdict.
     * @param  string|null  $body  The gzipped verified NZB for this exact manifest.
     * @param  array<string, true>|null  $coverageIds  Article IDs the incoming NZB requires an earlier release to cover.
     * @param  bool  $coverageKnown  Whether coverageIds() ran; null with this set means the document has no usable
     *                               inventory (oversized or malformed), which no earlier release can cover.
     */
    public function __construct(
        public ?int $size = null,
        public ?string $archivedPath = null,
        public ?array $archived = null,
        public bool $policyKnown = false,
        public ?string $policyRejection = null,
        public ?string $body = null,
        public ?array $coverageIds = null,
        public bool $coverageKnown = false,
    ) {}
}
