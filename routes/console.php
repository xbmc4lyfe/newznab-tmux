<?php

use App\Jobs\PurgeDeletedAccounts;
use App\Jobs\RemoveInactiveAccounts;
use App\Models\UserActivityStat;
use App\Models\UserDownload;
use App\Models\UserRequest;
use App\Services\Releases\Verification\PublicationOutbox;
use App\Services\Releases\Verification\VerificationWorkerLocks;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Verification workers and the preparer hold one candidate's whole manifest in memory (about 1 KiB per
// article at the metadata budget's limit), so their limit follows the budget rather than PHP's default.
$verificationMemory = static function (): string {
    $limit = (string) config('release_verification.worker_memory', '512M');

    return escapeshellarg('memory_limit='.(preg_match('/^[1-9][0-9]*[KMG]?$/i', $limit) === 1 ? $limit : '512M'));
};

// Each host sharing the database runs its own disjoint range of verification slots.
$scheduleVerificationWorkers = static function () use ($verificationMemory): void {
    for ($slot = VerificationWorkerLocks::firstSlot(); $slot <= VerificationWorkerLocks::lastSlot(); $slot++) {
        $verificationCommand = escapeshellarg(PHP_BINARY).' -d ffi.enable=true -d '.$verificationMemory().' '
            .escapeshellarg(base_path('artisan')).' releases:verify-candidates --limit='.max(1, (int) config('release_verification.worker_limit', 1000))
            .' --max-seconds='.max(1, (int) config('release_verification.worker_seconds', 300)).' --slot='.$slot.' --no-summary --diagnostics';
        Schedule::exec($verificationCommand)->everyMinute()->runInBackground()
            ->when(static fn (): bool => (bool) config('release_verification.scheduled'));
    }
};

// With asynchronous publication a remote worker hands verified results to a per-host outbox, and
// these forwarders (numbered from 1) send them to the primary in batches. Every run lasts at most
// the configured period, staggered by whole minutes inside it, so a container stop never waits long
// and the forwarders do not all restart at once.
$scheduleVerificationForwarders = static function (): void {
    if (! PublicationOutbox::enabled()) {
        return;
    }
    for ($forwarder = 1; $forwarder <= PublicationOutbox::forwarders(); $forwarder++) {
        $forwarderCommand = escapeshellarg(PHP_BINARY).' -d memory_limit=256M '.escapeshellarg(base_path('artisan'))
            .' releases:forward-verified-publications --slot='.$forwarder
            .' --max-seconds='.PublicationOutbox::forwarderLifetime($forwarder).' --diagnostics';
        Schedule::exec($forwarderCommand)->everyMinute()->runInBackground()
            ->when(static fn (): bool => (bool) config('release_verification.scheduled'));
    }
};

// A remote worker host only verifies candidates; the primary host runs everything else.
if ((bool) config('release_verification.worker_only')) {
    $scheduleVerificationWorkers();
    $scheduleVerificationForwarders();

    return;
}

Schedule::command('disposable:update')->weekly();
Schedule::command('clean:directories')->hourly()->withoutOverlapping();
Schedule::command('nntmux:delete-unverified-users')->twiceDaily(1, 13);
Schedule::command('nntmux:update-expired-roles')->daily();
// Automatically disable promotions that have passed their end_date
Schedule::command('nntmux:disable-expired-promotions')->daily();
// Automatically mark completed registration periods as done shortly after they end
Schedule::command('nntmux:disable-expired-registration-periods')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('nntmux:remove-bad')->hourly()->withoutOverlapping();
Schedule::command('cloudflare:reload')->daily()->withoutOverlapping();
Schedule::command('cache:prune-stale-tags')->hourly();
Schedule::command('gdpr:purge-expired-exports')->dailyAt('03:30')->withoutOverlapping();
Schedule::command('nntmux:collect-stats')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('nntmux:populate-steam-apps')->monthly();
// Collect system metrics every 5 minutes
Schedule::command('metrics:collect')->everyFiveMinutes()->withoutOverlapping();
// Cleanup old system metrics daily (keep last 60 days)
Schedule::command('metrics:collect --cleanup')->dailyAt('03:00');
// Push NNTmux application metrics to the Prometheus Pushgateway (see config/monitoring.php)
// Stop slow exports before the five-minute overlap lock can expire.
Schedule::command('monitoring:export-metrics --scheduled')->everyMinute()->withoutOverlapping(5)->when(static fn (): bool => (bool) config('monitoring.enabled'));
// Bound Horizon's runtime average to recent completions instead of accumulating it across days.
Schedule::command('horizon:snapshot')->everyFiveMinutes()->withoutOverlapping(5)->when(static fn (): bool => config('queue.default') === 'redis');
// Cleanup old user activity stats weekly (keep last 90 days)
Schedule::call(function () {
    UserActivityStat::cleanupOldStats(90);
})->weeklyOn(1, '04:00');
// Cleanup old hourly stats daily (keep last 30 days)
Schedule::call(function () {
    UserActivityStat::cleanupOldHourlyStats(30);
})->dailyAt('04:30');
if (config('nntmux.purge_inactive_users') === true) {
    Schedule::job(new RemoveInactiveAccounts)->daily();
    Schedule::job(new PurgeDeletedAccounts)->daily();
}
// Cleanup old API requests and download logs (older than 1 day) - deferred from inline API calls
Schedule::call(function () {
    UserRequest::clearApiRequests(false);
    UserDownload::where('timestamp', '<', now()->subDay())->delete();
})->name('cleanup-api-request-logs')->hourly()->withoutOverlapping();
// Check tmux health and auto-restart if monitor pane is dead
Schedule::command('tmux:health-check --auto-restart')->everyThirtyMinutes()->withoutOverlapping()->when(static fn (): bool => (bool) config('tmux.scheduled_health_check'));
Schedule::command('nntmux:check-service-health')->everyMinute()->withoutOverlapping();
Schedule::command('nntmux:search-repair --limit=100')->everyMinute()->withoutOverlapping();
// Fingerprint metadata a partial's in-place upgrade marked stale, whose collection could not be queued or saved.
Schedule::command('releases:fingerprint-backfill --stale --limit=200')->everyFifteenMinutes()->withoutOverlapping();
// TV: retry episode lookups for shows whose episode wasn't available yet, and normalize
// any release still carrying a legacy TV processor status.

Schedule::command('tv:reprocess-missing-episodes --scheduled --limit=500')->everyThirtyMinutes()->withoutOverlapping()->runInBackground();
Schedule::command('tv:repair-links --legacy --stuck --orphans --limit=50000')->dailyAt('05:15')->withoutOverlapping();
// Tail storage/logs into the Manticore log index used by the admin log viewer search
Schedule::command('nntmux:index-logs')->everyMinute()->withoutOverlapping()->runInBackground()->when(static fn (): bool => (bool) config('nntmux.log_viewer.index.enabled'));
// Keep the admin dashboard snapshot (Cache::flexible) hot so admins never pay
// the cold-cache cost when opening /admin/index.
Schedule::command('admin:warm-dashboard')->everyFifteenMinutes()->withoutOverlapping();
// Poll public PreDB JSON/RSS feeds (config/predb_feeds.php); the IRC scraper remains the realtime source
Schedule::command('predb:import-feed --quiet')->everyFiveMinutes()->withoutOverlapping()->runInBackground()->when(static fn (): bool => (bool) config('predb_feeds.enabled'));
// Link releases to PreDB titles that arrived after the release was created (feeds, IRC, history imports).
// Only recent releases are scanned; the old ones are covered by running `predb:check` by hand.
Schedule::command('predb:check 3 --quiet')->everyTenMinutes()->withoutOverlapping()->runInBackground();
// Match each new PreDB title against the raw subjects of existing releases (full-text). A run takes up to
// `fix_names_per_run` titles per worker (a few seconds). The tmux fix-names chain also ends with this pass,
// but its slow NNTP passes can run for an hour or more, so on its own it reached only about 100 titles a day;
// the tmux cycle skips it while this is on (TMUX_FIX_NAMES_PREDBFT_SCHEDULED), so there is one launcher.
// TMUX_FIX_NAMES_PREDBFT=false still turns the pass off entirely.
Schedule::command('multiprocessing:fixrelnames predbft')->everyFiveMinutes()->withoutOverlapping()->runInBackground()
    ->when(static fn (): bool => (bool) config('tmux.fix_names.predbft') && (bool) config('tmux.fix_names.predbft_scheduled'));

// Run slow NNTP PAR2 sweeps (levels 7 & 8) independently from the scheduler when TMUX_FIX_NAMES_PAR2_SCHEDULED
// is enabled. This decouples slow network downloads from fast database passes in Pane 1.0.
$par2Category = in_array(config('tmux.fix_names.category'), ['all', 'predb_id'], true) ? (string) config('tmux.fix_names.category') : 'other';
$par2Limit = max(1, (int) config('tmux.fix_names.batch_limit', 1000));
Schedule::command("releases:fix-names 7 --update --category={$par2Category} --set-status --limit={$par2Limit}")
    ->everyFiveMinutes()->withoutOverlapping()->runInBackground()
    ->when(static fn (): bool => (bool) config('tmux.fix_names.par2_scheduled'));
Schedule::command("releases:fix-names 8 --update --category={$par2Category} --set-status --limit={$par2Limit}")
    ->everyMinute()->withoutOverlapping()->runInBackground()
    ->when(static fn (): bool => (bool) config('tmux.fix_names.par2_scheduled'));

// One metadata preparer parks uploads without consuming a spot-verification slot.
$preparationCommand = escapeshellarg(PHP_BINARY).' -d ffi.enable=true -d '.$verificationMemory().' '
    .escapeshellarg(base_path('artisan')).' releases:prepare-candidates --limit=5000 --max-seconds=30 --diagnostics';
Schedule::exec($preparationCommand)->everyMinute()->runInBackground()
    ->when(static fn (): bool => (bool) config('release_verification.scheduled'));

// Stable worker slots cap concurrency without a stale scheduler overlap mutex.
$scheduleVerificationWorkers();
