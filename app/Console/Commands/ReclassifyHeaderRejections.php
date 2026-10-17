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
    protected $signature = 'releases:reclassify-header-rejections {--chunk=5000 : Candidate ids per update} {--after=0 : Resume after this candidate id} {--max-seconds=0 : Stop after this duration; zero runs to the end}';

    protected $description = 'Stop retrying unknown verification candidates whose headers cannot pass';

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $maxSeconds = max(0, (int) $this->option('max-seconds'));
        $started = microtime(true);
        $ceiling = (int) DB::table('release_verification_candidates')->max('id');
        $changed = 0;
        for ($after = max(0, (int) $this->option('after')); $after < $ceiling; $after += $chunk) {
            if ($maxSeconds > 0 && microtime(true) - $started >= $maxSeconds) {
                $this->warn('Stopped after '.$maxSeconds.' seconds; continue with --after='.$after.'.');
                break;
            }
            $changed += $this->reclassify($after, $after + $chunk);
        }
        $this->info('Reclassified '.$changed.' candidates with header defects.');

        return self::SUCCESS;
    }

    /** Ingestion and workers may hold candidates in another order; take only available rows. */
    private function reclassify(int $after, int $through): int
    {
        $query = DB::table('release_verification_candidates')->where('id', '>', $after)->where('id', '<=', $through)
            ->where('status', 'unknown')->whereNull('release_id')->whereNull('lease_token')
            ->whereIn('reason', CandidateHeaderInventory::REJECTION_REASONS);
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return $query->update(['status' => CandidateHeaderInventory::REJECTED]);
        }

        return DB::transaction(static function () use ($query): int {
            $ids = (clone $query)->lock('FOR UPDATE SKIP LOCKED')->pluck('id')->all();

            return $ids === [] ? 0 : $query->whereIn('id', $ids)->update(['status' => CandidateHeaderInventory::REJECTED]);
        }, 3);
    }
}
