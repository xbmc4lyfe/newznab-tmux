<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Releases\Verification\CandidatePreparationService;
use App\Services\Releases\Verification\VerificationWorkerLocks;
use Illuminate\Console\Command;

class PrepareReleaseCandidates extends Command
{
    protected $signature = 'releases:prepare-candidates {--limit=5000 : Maximum metadata records inspected} {--max-seconds=30 : Stop starting metadata reads after this duration}';

    protected $description = 'Separate complete header inventories from uploads waiting for articles';

    public function handle(CandidatePreparationService $service): int
    {
        $locks = new VerificationWorkerLocks;
        if (! $locks->startPreparation()) {
            $this->info('Candidate preparation or exclusive maintenance is already running.');

            return self::SUCCESS;
        }
        try {
            $changed = $service->prepare(max(1, (int) $this->option('limit')), max(1, (int) $this->option('max-seconds')));
            $this->info('Classified '.$changed.' candidate header inventories.');

            return self::SUCCESS;
        } finally {
            $locks->close();
        }
    }
}
