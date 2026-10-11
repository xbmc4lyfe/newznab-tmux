<?php

declare(strict_types=1);

namespace App\Services\Monitoring\Collectors;

use App\Services\Monitoring\Prometheus\MetricFamily;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ReleaseVerificationCollector implements MetricsCollector
{
    public function collect(): iterable
    {
        if (! Schema::hasTable('release_verification_candidates')) {
            return;
        }
        $family = MetricFamily::gauge('nntmux_release_verification_candidates', 'Candidates by verification outcome.');
        foreach (DB::table('release_verification_candidates')->selectRaw('status, COUNT(*) AS total')->groupBy('status')->get() as $row) {
            $family->add((int) $row->total, ['status' => $row->status]);
        }
        yield $family;
        yield MetricFamily::gauge('nntmux_release_verification_pending_bytes', 'Compressed pending manifest bytes.')
            ->add((int) DB::table('release_verification_state')->where('id', 1)->value('pending_bytes'));
        $snapshotPath = (string) config('release_verification.storage_path').'/status.json';
        $snapshot = is_file($snapshotPath) ? json_decode((string) file_get_contents($snapshotPath), true) : [];
        foreach (['scratch_bytes', 'verified_bytes_per_second'] as $metric) {
            yield MetricFamily::gauge('nntmux_release_verification_'.$metric, 'Latest bounded verifier measurement.')
                ->add(is_array($snapshot) && is_numeric($snapshot[$metric] ?? null) ? (float) $snapshot[$metric] : 0);
        }
        $oldest = DB::table('release_verification_candidates')->whereNull('release_id')->whereNotIn('status', ['expired'])->min('last_unique_at');
        yield MetricFamily::gauge('nntmux_release_verification_backlog_age_seconds', 'Age of the oldest unverified candidate.')
            ->add($oldest === null ? 0 : max(0, time() - strtotime($oldest)));
    }
}
