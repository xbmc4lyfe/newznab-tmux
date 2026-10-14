<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Enums\Par2RenameOutcome;

final readonly class Par2CandidateRecoveryResult
{
    public function __construct(public Par2RenameOutcome $outcome, public int $probes, public int $articles) {}
}
