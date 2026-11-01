<?php

declare(strict_types=1);

namespace App\Services\Monitoring\Collectors;

use App\Services\Monitoring\BoundedMetricQuery;
use App\Services\Monitoring\Prometheus\MetricFamily;
use Illuminate\Support\Facades\DB;

/** Rust clients report through SQL; their work is independent of Horizon. */
final class DatabaseFingerprintCollector implements MetricsCollector
{
    public function __construct(private readonly BoundedMetricQuery $queries) {}

    public function collect(): iterable
    {
        if (config('nntmux.fingerprint_worker', 'rust') !== 'rust') {
            return;
        }
        $jobs = MetricFamily::gauge('nntmux_fingerprint_jobs', 'Database fingerprint jobs by state.');
        $counts = array_fill_keys(['pending', 'processing', 'complete', 'failed'], 0);
        foreach ($this->queries->get(DB::table('release_fingerprint_jobs')->selectRaw('status, COUNT(*) AS total')->groupBy('status')) as $row) {
            $counts[$row->status] = (int) $row->total;
        }
        foreach ($counts as $status => $count) {
            $jobs->add($count, ['status' => $status]);
        }
        yield $jobs;
        $expired = DB::table('release_fingerprint_jobs')->where('status', 'processing')->where('lease_until', '<=', now('UTC'))->count();
        $live = DB::table('release_fingerprint_jobs')->where('status', 'processing')->where('lease_until', '>', now('UTC'))->count();
        yield MetricFamily::gauge('nntmux_fingerprint_live_claims', 'Rust fingerprint jobs with unexpired leases.')->add($live);
        $recent = $this->queries->get(DB::table('release_fingerprint_jobs')->where('status', 'complete')
            ->where('updated_at', '>=', now('UTC')->subMinutes(5))->selectRaw('COUNT(*) AS total'));
        yield MetricFamily::gauge('nntmux_fingerprint_completions_last_five_minutes', 'Releases whose Rust fingerprint job completed in the last five minutes.')
            ->add((int) $recent[0]->total);
        yield MetricFamily::gauge('nntmux_fingerprint_expired_claims', 'Rust fingerprint claims awaiting takeover.')->add($expired);
        $slots = MetricFamily::gauge('nntmux_fingerprint_connection_slots', 'Shared NNTP connection budget for Rust clients.');
        $used = MetricFamily::gauge('nntmux_fingerprint_connections_active', 'Currently leased Rust NNTP connection slots.');
        foreach (['primary', 'alternate'] as $provider) {
            $query = DB::table('release_fingerprint_connections')->where('provider', $provider);
            $slots->add((clone $query)->count(), ['provider' => $provider]);
            $used->add($query->where('lease_until', '>', now('UTC'))->count(), ['provider' => $provider]);
        }
        yield $slots;
        yield $used;
    }
}
