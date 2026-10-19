<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Releases\Verification\CandidatePreparationService;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\DirtyCandidateSettler;
use App\Services\Releases\Verification\VerificationWorkerLocks;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class PrepareReleaseCandidates extends Command
{
    protected $signature = 'releases:prepare-candidates {--limit=5000 : Maximum metadata records inspected} {--max-seconds=30 : Stop starting metadata reads after this duration} {--diagnostics : Log bounded preparation counters and timings} {--dirty-limit=200 : Maximum dirty candidates whose late headers are archived} {--dirty-seconds=20 : Stop starting dirty-candidate archives after this duration}';

    protected $description = 'Separate complete header inventories from uploads waiting for articles';

    public function handle(CandidatePreparationService $service, CandidateRepository $repository, DirtyCandidateSettler $settler): int
    {
        $locks = new VerificationWorkerLocks;
        if (! $locks->startPreparation()) {
            $this->info('Candidate preparation or exclusive maintenance is already running.');

            return self::SUCCESS;
        }
        $report = null;
        $observer = $this->option('diagnostics') ? static function (array $summary) use (&$report): void {
            $report = $summary;
        } : null;
        try {
            $changed = $service->prepare(max(1, (int) $this->option('limit')), max(1, (int) $this->option('max-seconds')),
                fn (bool $advance): bool => $locks->preferPending($advance), $observer);
            $this->info('Classified '.$changed.' candidate header inventories.');
            $promoted = $repository->promotePartial(max(1, (int) $this->option('limit')));
            $this->info('Queued '.$promoted.' stalled incomplete uploads for partial publication.');
            // Late headers make a candidate dirty until archive() merges them; release creation may not reach
            // their collection for a long time on a large group.
            $dirty = $settler->settle(max(1, (int) $this->option('dirty-limit')), max(1, (int) $this->option('dirty-seconds')));
            $this->info('Settled '.$dirty['settled'].' of '.$dirty['inspected'].' dirty candidates ('.$dirty['kept'].' late collections kept for a later pass).');
            if ($report !== null) {
                $report['dirty_settle'] = $dirty;
            }

            return self::SUCCESS;
        } finally {
            $locks->close();
            if ($report !== null) {
                try {
                    Log::info('Candidate preparation run completed.', $report);
                } catch (Throwable) {
                    // Logging cannot mask preparation failures or change successful command outcomes.
                }
            }
        }
    }
}
