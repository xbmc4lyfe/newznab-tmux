<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Releases\Verification\CandidateHeaderInventory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Candidates recorded before header_rejected existed keep retrying as unknown. Move those whose
 * current reason is a header defect out of the claim queue, in short primary-key ranges, skipping
 * any a worker holds.
 */
class ReclassifyHeaderRejections extends Command
{
    protected $signature = 'releases:reclassify-header-rejections {--chunk=5000 : Candidate ids per update} {--max-seconds=0 : Stop after this duration; zero runs to the end}';

    protected $description = 'Stop retrying unknown verification candidates whose headers cannot pass';

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $maxSeconds = max(0, (int) $this->option('max-seconds'));
        $started = microtime(true);
        $ceiling = (int) DB::table('release_verification_candidates')->max('id');
        $changed = 0;
        for ($after = 0; $after < $ceiling; $after += $chunk) {
            if ($maxSeconds > 0 && microtime(true) - $started >= $maxSeconds) {
                $this->warn('Stopped after '.$maxSeconds.' seconds at id '.$after.'; run again to continue.');
                break;
            }
            $changed += DB::table('release_verification_candidates')->where('id', '>', $after)->where('id', '<=', $after + $chunk)
                ->where('status', 'unknown')->whereNull('release_id')->whereNull('lease_token')
                ->whereIn('reason', CandidateHeaderInventory::REJECTION_REASONS)
                ->update(['status' => CandidateHeaderInventory::REJECTED]);
        }
        $this->info('Reclassified '.$changed.' candidates with header defects.');

        return self::SUCCESS;
    }
}
