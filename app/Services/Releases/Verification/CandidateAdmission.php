<?php

namespace App\Services\Releases\Verification;

final readonly class CandidateAdmission
{
    public function __construct(public int $candidateId, public string $identity, public int $version, public string $manifestHash) {}

    /** @return array{candidate_id: int, identity: string, version: int, manifest_hash: string} */
    public function toArray(): array
    {
        return ['candidate_id' => $this->candidateId, 'identity' => $this->identity, 'version' => $this->version, 'manifest_hash' => $this->manifestHash];
    }
}
