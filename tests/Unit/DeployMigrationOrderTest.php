<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class DeployMigrationOrderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/deploy-order-'.bin2hex(random_bytes(8));
        mkdir($this->directory.'/docker/bin', 0700, true);
        mkdir($this->directory.'/bin', 0700);
        copy(__DIR__.'/../../docker/Justfile', $this->directory.'/docker/Justfile');
        foreach (['deploy-guard', 'deploy-app', 'deploy-current', 'deploy-plan', 'build-images', 'up-app', 'compose', 'legacy-drain.php', 'legacy-scheduler-drain.py'] as $script) {
            copy(__DIR__.'/../../docker/bin/'.$script, $this->directory.'/docker/bin/'.$script);
            chmod($this->directory.'/docker/bin/'.$script, 0755);
        }
        file_put_contents($this->directory.'/.gitignore', "/docker/.build.stamp\n/docker/.deploy.stamp\n/docker/.deploy.time\n/docker/.deploy.log\n/docker/.deploy.lock*\n/events\n/recreated\n/barrier.log\n/barrier.running\n");
        file_put_contents($this->directory.'/app.txt', "one\n");
        file_put_contents($this->directory.'/bin/docker', <<<'SH'
#!/bin/sh
printf 'COMPOSE_FILE=%s %s\n' "$COMPOSE_FILE" "$*" >> "$NNTMUX_DEPLOY_EVENTS"
for last in "$@"; do :; done
default_config='{"services":{"web":{},"horizon":{},"scheduler":{},"indexer":{},"mariadb":{},"redis":{},"manticore":{},"proxy":{},"mailpit":{},"prometheus":{},"pushgateway":{},"grafana":{},"node-exporter":{},"mysqld-exporter":{},"redis-exporter":{}}}'
case "$*" in
    *'releases:verification-barrier --release'*) echo forced-release >> "$NNTMUX_DEPLOY_EVENTS"; [ "${NNTMUX_FORCED_RELEASE_FAIL:-0}" != 1 ] || exit 52 ;;
    *'releases:verification-barrier'*)
        if [ "${NNTMUX_BARRIER_FAIL:-0}" = 1 ]; then echo 'still running' > "$NNTMUX_REPO/barrier.log"; else echo 'barrier held' > "$NNTMUX_REPO/barrier.log"; : > "$NNTMUX_REPO/barrier.running"; fi
        echo barrier-container ;;
    'inspect --format {{.State.Running}} '*'-verification-barrier') [ -f "$NNTMUX_REPO/barrier.running" ] && echo 'true nntmux' || echo 'false nntmux' ;;
    'logs '*'-verification-barrier')
        cat "$NNTMUX_REPO/barrier.log" 2>/dev/null
        if [ "${NNTMUX_BARRIER_LOST:-}" != '' ] && grep -q "${NNTMUX_BARRIER_LOST}" "$NNTMUX_DEPLOY_EVENTS"; then echo 'barrier lost'; fi ;;
    'stop --timeout 30 '*'-verification-barrier')
        rm -f "$NNTMUX_REPO/barrier.running"; echo released >> "$NNTMUX_DEPLOY_EVENTS"
        [ "${NNTMUX_BARRIER_UNCLEAN:-0}" = 1 ] || echo 'barrier released' >> "$NNTMUX_REPO/barrier.log" ;;
    'rm -f '*'-verification-barrier') rm -f "$NNTMUX_REPO/barrier.running" ;;
    *' config --format json'*) printf '%s\n' "${NNTMUX_CONFIG_ID:-$default_config}" | sed 's/^{/{"name":"nntmux",/' ;;
    *'image inspect --format'*)
        if [ "${NNTMUX_INSPECT_FAIL_AFTER_UP:-0}" = 1 ] && [ -f "$NNTMUX_REPO/recreated" ]; then echo "Error response from daemon: timeout" >&2; exit 1; fi
        [ "${NNTMUX_IMAGE_INSPECT_FAIL:-0}" != 1 ] || { echo "Error response from daemon: request timed out" >&2; exit 1; }
        echo "${NNTMUX_IMAGE_ID:-sha256:fixture}" ;;
    *'image inspect'*) exit 0 ;;
    *'inspect --format {{.Image}}'*)
        if [ -f "$NNTMUX_REPO/recreated" ]; then
            echo "${NNTMUX_IMAGE_ID:-sha256:fixture}"
        else
            echo "${NNTMUX_CONTAINER_IMAGE_ID:-${NNTMUX_IMAGE_ID:-sha256:fixture}}"
        fi ;;
    *'inspect --format {{.State.StartedAt}}'*) echo "${NNTMUX_STARTED_AT:-2999-01-01T00:00:00.123456789Z}" ;;
    *'inspect --format {{.State.Status}}'*)
        case "$*" in *"fixture-container-${NNTMUX_UNHEALTHY:-none}") echo "running unhealthy" ;; *) echo "running ${NNTMUX_HEALTH_STATUS:-healthy}" ;; esac ;;
    *' ps -a -q '*)
        [ "${NNTMUX_NO_EXISTING_APP:-0}" = 1 ] && exit 0
        [ -n "${NNTMUX_NO_CONTAINER:-}" ] && [ "$last" = "$NNTMUX_NO_CONTAINER" ] && exit 0
        echo fixture-container ;;
    *' ps -q horizon'*)
        [ "${NNTMUX_HORIZON_PS_FAIL:-0}" != 1 ] || exit 50
        [ "${NNTMUX_SERVICE_MISSING:-0}" = 1 ] || echo "fixture-container-horizon" ;;
    *' ps -q '*)
        [ "${NNTMUX_PS_FAIL:-0}" != 1 ] || { echo "Cannot connect to the Docker daemon" >&2; exit 1; }
        [ "${NNTMUX_SERVICE_MISSING:-0}" = 1 ] || echo "fixture-container-$last" ;;
    *'scheduler python3 -c '*) [ "${NNTMUX_SCHEDULER_CAPABILITY_FAIL:-0}" != 1 ] || exit 48; echo "${NNTMUX_SCHEDULER_CAPABILITY:-native}" ;;
    *'scheduler.py drain --timeout='*)
        [ "${NNTMUX_SCHEDULER_DRAIN_FAIL:-0}" != 1 ] || exit 47
        if [ "${NNTMUX_STALE_SCHEDULER_DRAIN:-0}" = 1 ]; then echo two > "$NNTMUX_REPO/app.txt"; fi ;;
    *'scheduler.py check'*) [ "${NNTMUX_SCHEDULER_CHECK_FAIL:-0}" != 1 ] || exit 49 ;;
    *'scheduler python3 - drain '*) [ "${NNTMUX_SCHEDULER_DRAIN_FAIL:-0}" != 1 ] || exit 47 ;;
    *'scheduler python3 - check '*) [ "${NNTMUX_SCHEDULER_CHECK_FAIL:-0}" != 1 ] || exit 49 ;;
    *'artisan list --raw'*)
        [ "${NNTMUX_CAPABILITY_FAIL:-0}" != 1 ] || exit 46
        [ "${NNTMUX_LEGACY_IMAGE:-0}" = 1 ] || echo 'tmux:drain Drain processing' ;;
    *'php -- /app drain '*)
        [ "${NNTMUX_DRAIN_FAIL:-0}" != 1 ] || exit 43
        if [ "${NNTMUX_STALE_DRAIN:-0}" = 1 ]; then echo two > "$NNTMUX_REPO/app.txt"; fi ;;
    *'artisan tmux:drain --timeout='*)
        [ "${NNTMUX_DRAIN_FAIL:-0}" != 1 ] || exit 43
        if [ "${NNTMUX_STALE_DRAIN:-0}" = 1 ]; then echo two > "$NNTMUX_REPO/app.txt"; fi ;;
    *' stop --timeout 1920 horizon'*) [ "${NNTMUX_HORIZON_STOP_FAIL:-0}" != 1 ] || exit 45 ;;
    *' stop web scheduler indexer'*)
        if [ "${NNTMUX_STALE_STOP:-0}" = 1 ]; then echo two > "$NNTMUX_REPO/app.txt"; fi ;;
    *'artisan migrate'*) [ "${NNTMUX_MIGRATION_FAIL:-0}" != 1 ] || exit 42 ;;
    *' up -d'*)
        [ "${NNTMUX_HEALTH_FAIL:-0}" != 1 ] || exit 44
        case "$*" in *' proxy'*)
            if [ -n "${NNTMUX_PROXY_EDIT:-}" ]; then echo C > "$NNTMUX_PROXY_EDIT"; fi
            [ "${NNTMUX_PROXY_FAIL:-0}" != 1 ] || exit 51 ;; esac
        case "$*" in *'--wait-timeout 240 web'*) [ "${NNTMUX_APP_UP_FAIL:-0}" != 1 ] || exit 52 ;; esac
        case "$*" in *'--force-recreate'*' redis'*) [ "${NNTMUX_REDIS_FAIL:-0}" != 1 ] || exit 53 ;; esac
        [ -z "${NNTMUX_UP_FAIL_SERVICE:-}" ] || [ "$last" != "$NNTMUX_UP_FAIL_SERVICE" ] || exit 54
        if [ "${NNTMUX_STALE_UP:-0}" = 1 ]; then echo changed > "$NNTMUX_REPO/app.txt"; fi
        case "$*" in *' --force-recreate '*) : > "$NNTMUX_REPO/recreated" ;; esac ;;
esac
exit 0
SH);
        chmod($this->directory.'/bin/docker', 0755);
        foreach ([['init', '-q'], ['config', 'user.email', 'test@example.com'], ['config', 'user.name', 'Test'], ['add', '.'], ['commit', '-q', '-m', 'fixture']] as $arguments) {
            (new Process(['git', ...$arguments], $this->directory))->mustRun();
        }
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function test_deploy_builds_live_then_drains_stops_migrates_and_waits_for_app_health(): void
    {
        $this->deploy()->mustRun();
        $events = $this->events();
        $steps = ['build --target selfhosted', 'artisan list --raw', ' stop --timeout 1920 horizon', 'scheduler.py drain --timeout=1800', 'artisan tmux:drain --timeout=900', 'releases:verification-barrier --timeout=900', ' stop web scheduler indexer', 'artisan migrate --force --no-interaction', ' up -d --no-deps --force-recreate --wait --wait-timeout 240 web horizon scheduler indexer', 'released'];
        $previous = -1;
        foreach ($steps as $step) {
            $position = strpos($events, $step);
            $this->assertNotFalse($position, $step);
            $this->assertGreaterThan($previous, $position, $step);
            $previous = $position;
        }
        $this->assertStringNotContainsString(' down', $events);
        $this->assertFileExists($this->directory.'/docker/.build.stamp');
        $this->assertFileExists($this->directory.'/docker/.deploy.stamp');
        $this->assertFileExists($this->directory.'/docker/.deploy.time');
    }

    public function test_remote_workers_that_do_not_drain_stop_the_rollout_before_writers_stop(): void
    {
        $process = $this->deploy(['NNTMUX_BARRIER_FAIL' => '1']);
        $process->run();

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('Remote verification workers did not drain', $process->getErrorOutput());
        $events = $this->events();
        $this->assertStringContainsString('releases:verification-barrier', $events);
        $this->assertStringNotContainsString(' stop web scheduler indexer', $events);
        $this->assertStringNotContainsString('artisan migrate', $events);
        $this->assertStringContainsString(' start horizon', $events);
        $this->assertStringContainsString('scheduler.py restore --token=', $events);
        $this->assertSame('stale', strtok((string) file_get_contents($this->directory.'/docker/.deploy.stamp'), "\n"));
    }

    public function test_a_failed_migration_keeps_remote_workers_held_for_the_repaired_deployment(): void
    {
        $process = $this->deploy(['NNTMUX_MIGRATION_FAIL' => '1']);
        $process->run();

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('stay held by container', $process->getErrorOutput());
        $this->assertStringNotContainsString('released', $this->events());
        $this->assertFileExists($this->directory.'/barrier.running');

        // The repaired deployment reuses the running barrier and releases it after health.
        file_put_contents($this->directory.'/events', '');
        $this->deploy()->mustRun();
        $events = $this->events();
        $this->assertSame(0, substr_count($events, 'releases:verification-barrier'));
        $this->assertGreaterThan(strpos($events, ' up -d --no-deps --force-recreate'), strpos($events, 'released'));
        $this->assertFileDoesNotExist($this->directory.'/barrier.running');
    }

    public function test_an_adopted_barrier_stays_held_when_the_repaired_deployment_fails_early(): void
    {
        $this->deploy(['NNTMUX_MIGRATION_FAIL' => '1'])->run();
        $this->assertFileExists($this->directory.'/barrier.running');
        file_put_contents($this->directory.'/events', '');

        $process = $this->deploy(['NNTMUX_BARRIER_LOST' => ' stop web scheduler indexer']);
        $process->run();

        $this->assertFalse($process->isSuccessful());
        $this->assertStringNotContainsString('released', $this->events());
        $this->assertStringContainsString('stay held by container', $process->getErrorOutput());
    }

    public function test_an_unclean_barrier_stop_clears_the_durable_pause_explicitly(): void
    {
        $this->deploy(['NNTMUX_BARRIER_UNCLEAN' => '1'])->mustRun();

        $this->assertGreaterThan(strpos($this->events(), 'released'), strpos($this->events(), 'forced-release'));
    }

    public function test_a_durable_pause_that_cannot_be_cleared_fails_the_rollout(): void
    {
        $process = $this->deploy(['NNTMUX_BARRIER_UNCLEAN' => '1', 'NNTMUX_FORCED_RELEASE_FAIL' => '1']);
        $process->run();

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('deployment pause could not be cleared', $process->getErrorOutput());
        $this->assertSame('stale', strtok((string) file_get_contents($this->directory.'/docker/.deploy.stamp'), "\n"));

        // The healthy stack must not make the retry skip: it owes a barrier, never the completed migration.
        file_put_contents($this->directory.'/events', '');
        $this->deploy()->mustRun();
        $this->assertStringContainsString('releases:verification-barrier --timeout=900', $this->events());
        $this->assertStringNotContainsString('artisan migrate', $this->events());
        $this->assertStringContainsString('released', $this->events());
        $this->assertSame([], glob($this->directory.'/docker/.deploy.stamp.barrier-owed') ?: []);
    }

    public function test_a_barrier_lost_before_migration_aborts_without_migrating(): void
    {
        $process = $this->deploy(['NNTMUX_BARRIER_LOST' => ' stop web scheduler indexer']);
        $process->run();

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('The verification barrier was lost', $process->getErrorOutput());
        $this->assertStringNotContainsString('artisan migrate', $this->events());
    }

    public function test_a_barrier_lost_during_migration_fails_the_rollout(): void
    {
        $process = $this->deploy(['NNTMUX_BARRIER_LOST' => 'artisan migrate']);
        $process->run();

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('The verification barrier was lost', $process->getErrorOutput());
        $this->assertStringNotContainsString(' up -d --no-deps --force-recreate', $this->events());
    }

    public function test_a_barrier_lost_after_every_service_is_recorded_still_owes_its_release(): void
    {
        $process = $this->deploy(['NNTMUX_BARRIER_LOST' => ' up -d --no-deps --force-recreate']);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        // A barrier that lost its session exits; only its durable pause is left behind.
        @unlink($this->directory.'/barrier.running');

        // Every service came up and was recorded, yet the lost barrier's durable pause remains: the
        // retry must not skip as a no-op but take a barrier again and release it after health.
        file_put_contents($this->directory.'/events', '');
        $this->deploy()->mustRun();
        $events = $this->events();
        $this->assertStringContainsString('releases:verification-barrier --timeout=900', $events);
        $this->assertStringNotContainsString('artisan migrate', $events);
        $this->assertStringContainsString('released', $events);
    }

    public function test_a_no_op_run_that_cannot_clear_a_leftover_pause_makes_the_next_poll_retake_the_barrier(): void
    {
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        file_put_contents($this->directory.'/barrier.log', "barrier held\n");
        touch($this->directory.'/barrier.running');
        file_put_contents($this->directory.'/docker/.env', "DB_ROOTPASSWORD=rotated\n");

        $process = $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_BARRIER_UNCLEAN' => '1', 'NNTMUX_FORCED_RELEASE_FAIL' => '1']);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('forced-release', $this->events());

        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        $events = $this->events();
        $this->assertStringContainsString('releases:verification-barrier --timeout=900', $events);
        $this->assertStringContainsString('released', $events);
    }

    public function test_a_recreated_data_service_is_followed_by_a_fresh_barrier_before_writers_start(): void
    {
        mkdir($this->directory.'/docker/config/redis', 0777, true);
        file_put_contents($this->directory.'/docker/config/redis/redis.conf', "maxmemory 1gb\n");
        $config = ['NNTMUX_CONFIG_ID' => $this->composeJson()];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();
        file_put_contents($this->directory.'/events', '');

        file_put_contents($this->directory.'/docker/config/redis/redis.conf', "maxmemory 2gb\n");
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();
        $events = $this->events();
        $recreate = strpos($events, ' up -d --no-deps --force-recreate --wait --wait-timeout 240 redis');
        $this->assertNotFalse($recreate);
        $this->assertSame(2, substr_count($events, 'releases:verification-barrier --timeout=900'));
        $this->assertGreaterThan($recreate, strrpos($events, 'releases:verification-barrier --timeout=900'));
        $this->assertGreaterThan(strrpos($events, 'releases:verification-barrier --timeout=900'), strpos($events, 'horizon scheduler indexer'));
        $this->assertStringContainsString('released', $events);
    }

    public function test_native_indexer_abort_uses_the_same_unique_operation_token_as_its_drain(): void
    {
        $process = $this->deploy(['NNTMUX_STALE_DRAIN' => '1']);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $events = $this->events();
        $this->assertSame(1, preg_match('/scheduler.py drain --timeout=1800 --token=([a-f0-9]{32})/', $events, $matches));
        $token = $matches[1];
        $this->assertStringContainsString('tmux:drain --timeout=900 --session=nntmux --token='.$token, $events);
        $this->assertStringContainsString('tmux:drain --resume --session=nntmux --token='.$token, $events);
    }

    public function test_legacy_indexer_abort_uses_the_same_unique_operation_token_as_its_drain(): void
    {
        $process = $this->deploy(['NNTMUX_LEGACY_IMAGE' => '1', 'DEPLOY_LEGACY_DRAIN' => '1', 'NNTMUX_DRAIN_FAIL' => '1']);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $events = $this->events();
        $this->assertSame(1, preg_match('/scheduler.py drain --timeout=1800 --token=([a-f0-9]{32})/', $events, $matches));
        $token = $matches[1];
        $this->assertStringContainsString('php -- /app drain 1800 '.$token, $events);
        $this->assertStringContainsString('php -- /app resume 1800 '.$token, $events);
    }

    public function test_unchanged_healthy_rollout_skips_build_drain_migration_and_restart(): void
    {
        $this->deploy()->mustRun();
        file_put_contents($this->directory.'/events', '');
        $this->deploy()->mustRun();
        $this->assertStringNotContainsString('build --target', $this->events());
        $this->assertStringNotContainsString('tmux:drain', $this->events());
        $this->assertStringNotContainsString(' stop ', $this->events());
        $this->assertStringNotContainsString('artisan migrate', $this->events());
        $this->assertStringNotContainsString(' up ', $this->events());
    }

    public function test_missing_service_prevents_an_unchanged_rollout_from_being_skipped(): void
    {
        $this->deploy()->mustRun();
        file_put_contents($this->directory.'/events', '');
        $this->deploy(['NNTMUX_SERVICE_MISSING' => '1'])->mustRun();
        // Missing services are recreated; the recorded migration state alone decides migrating.
        $this->assertStringNotContainsString('artisan migrate', $this->events());
        $this->assertStringContainsString(' up -d --no-deps --force-recreate --wait --wait-timeout 240 web horizon scheduler indexer', $this->events());
    }

    public function test_retry_after_an_unhealthy_web_only_rollout_recreates_only_web(): void
    {
        mkdir($this->directory.'/resources/js', 0777, true);
        file_put_contents($this->directory.'/resources/js/app.js', "1\n");
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        file_put_contents($this->directory.'/resources/js/app.js', "2\n");
        $failed = $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_APP_UP_FAIL' => '1']);
        $failed->run();
        $this->assertSame(52, $failed->getExitCode());

        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_UNHEALTHY' => 'web'])->run();
        $events = $this->events();
        $this->assertStringNotContainsString('drain', $events);
        $this->assertStringNotContainsString('artisan migrate', $events);
        $this->assertStringNotContainsString(' stop ', $events);
        $this->assertStringContainsString(' up -d --no-deps --force-recreate --wait --wait-timeout 240 web', $events);
        $this->assertStringNotContainsString('web horizon', $events);
    }

    public function test_retry_after_a_failed_app_start_does_not_repeat_a_completed_migration(): void
    {
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        mkdir($this->directory.'/database/migrations', 0777, true);
        file_put_contents($this->directory.'/database/migrations/2026_10_17_000001_x.php', "<?php\n");
        file_put_contents($this->directory.'/events', '');
        $failed = $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_APP_UP_FAIL' => '1', 'NNTMUX_UNHEALTHY' => 'web']);
        $failed->run();
        $this->assertSame(52, $failed->getExitCode());
        $this->assertSame(1, substr_count($this->events(), 'artisan migrate'));

        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_UNHEALTHY' => 'web'])->run();
        $this->assertStringNotContainsString('artisan migrate', $this->events());
        // Only the service that never came up healthy is redeployed.
        $this->assertStringContainsString(' up -d --no-deps --force-recreate --wait --wait-timeout 240 web', $this->events());
        $this->assertStringNotContainsString('web horizon', $this->events());
    }

    public function test_stale_container_image_id_forces_recreation_so_subsequent_unchanged_deploy_skips(): void
    {
        $env = ['NNTMUX_IMAGE_ID' => 'sha256:new-index', 'NNTMUX_CONTAINER_IMAGE_ID' => 'sha256:old-index'];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $env)->mustRun();
        $this->assertFileExists($this->directory.'/recreated');
        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $env)->mustRun();
        $this->assertStringNotContainsString(' stop ', $this->events());
        $this->assertStringNotContainsString(' up -d', $this->events());
    }

    public function test_failed_drain_leaves_writers_running_and_never_migrates_or_stamps(): void
    {
        $process = $this->deploy(['NNTMUX_DRAIN_FAIL' => '1']);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringNotContainsString(' stop web', $this->events());
        $this->assertStringContainsString(' start horizon', $this->events());
        $this->assertStringNotContainsString('artisan migrate', $this->events());
        $this->assertNotStamped();
    }

    public function test_legacy_image_requires_explicit_transition_opt_in(): void
    {
        $process = $this->deploy(['NNTMUX_LEGACY_IMAGE' => '1']);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringNotContainsString(' stop ', $this->events());
        $this->assertStringNotContainsString('php -- /app drain', $this->events());
    }

    public function test_failed_capability_probe_never_uses_the_legacy_bridge(): void
    {
        $process = $this->deploy(['NNTMUX_CAPABILITY_FAIL' => '1', 'DEPLOY_LEGACY_DRAIN' => '1']);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringNotContainsString('php -- /app drain', $this->events());
        $this->assertStringNotContainsString(' stop ', $this->events());
    }

    public function test_legacy_transition_drains_before_stopping_or_migrating(): void
    {
        $this->deploy(['NNTMUX_LEGACY_IMAGE' => '1', 'DEPLOY_LEGACY_DRAIN' => '1'])->mustRun();
        $events = $this->events();
        $this->assertLessThan(strpos($events, ' stop web scheduler indexer'), strpos($events, 'php -- /app drain 1800'));
        $this->assertStringContainsString('artisan migrate', $events);
    }

    public function test_failed_legacy_transition_never_stops_or_migrates(): void
    {
        $process = $this->deploy(['NNTMUX_LEGACY_IMAGE' => '1', 'DEPLOY_LEGACY_DRAIN' => '1', 'NNTMUX_DRAIN_FAIL' => '1']);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringNotContainsString(' stop web', $this->events());
        $this->assertStringContainsString(' start horizon', $this->events());
        $this->assertStringNotContainsString('artisan migrate', $this->events());
    }

    public function test_stale_legacy_transition_restores_using_the_old_runtime(): void
    {
        $process = $this->deploy(['NNTMUX_LEGACY_IMAGE' => '1', 'DEPLOY_LEGACY_DRAIN' => '1', 'NNTMUX_STALE_DRAIN' => '1']);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('php -- /app resume', $this->events());
        $this->assertStringNotContainsString(' stop web', $this->events());
        $this->assertStringContainsString(' start horizon', $this->events());
    }

    public function test_stale_checkout_after_drain_resumes_the_old_indexer_without_stopping_writers(): void
    {
        $process = $this->deploy(['NNTMUX_STALE_DRAIN' => '1']);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('artisan tmux:drain --resume', $this->events());
        $this->assertStringNotContainsString(' stop web', $this->events());
        $this->assertStringContainsString(' start horizon', $this->events());
        $this->assertStringNotContainsString('artisan migrate', $this->events());
    }

    public function test_failed_migration_never_starts_or_stamps_the_new_runtime(): void
    {
        $process = $this->deploy(['NNTMUX_MIGRATION_FAIL' => '1']);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        // Infra (before any drain) and data reconciliation may run; new app containers never start.
        $this->assertStringNotContainsString(' up -d --no-deps --force-recreate --wait --wait-timeout 240 web horizon scheduler indexer', $this->events());
        $this->assertNotStamped();
        $this->assertFileDoesNotExist($this->directory.'/docker/.deploy.time');
    }

    public function test_failed_health_check_never_marks_the_new_runtime_as_deployed(): void
    {
        $process = $this->deploy(['NNTMUX_HEALTH_FAIL' => '1']);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertFileExists($this->directory.'/docker/.build.stamp');
        $this->assertNotStamped();
        $this->assertFileDoesNotExist($this->directory.'/docker/.deploy.time');
    }

    public function test_successful_build_alone_does_not_skip_the_first_deployment(): void
    {
        $this->make(['build'])->mustRun();
        $this->assertFileDoesNotExist($this->directory.'/docker/.deploy.time');
        file_put_contents($this->directory.'/events', '');
        $this->deploy()->mustRun();
        $this->assertStringNotContainsString('build --target', $this->events());
        $this->assertStringContainsString('artisan migrate', $this->events());
    }

    public function test_batching_is_measured_from_last_success_and_force_bypasses_it(): void
    {
        $this->deploy()->mustRun();
        file_put_contents($this->directory.'/app.txt', "two\n");
        file_put_contents($this->directory.'/events', '');
        $this->deploy()->mustRun();
        $this->assertStringNotContainsString('build --target', $this->events());
        $this->assertStringNotContainsString(' stop ', $this->events());
        file_put_contents($this->directory.'/docker/.deploy.time', (string) (time() - 901));
        $this->deploy()->mustRun();
        $this->assertStringContainsString('build --target', $this->events());
        file_put_contents($this->directory.'/app.txt', "three\n");
        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'FORCE=1'])->mustRun();
        $this->assertStringContainsString('build --target', $this->events());
        $this->assertStringContainsString(' stop ', $this->events());
    }

    public function test_effective_compose_configuration_changes_trigger_a_rollout(): void
    {
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_CONFIG_ID' => substr('{"services":{"web":{},"horizon":{},"scheduler":{},"indexer":{},"mariadb":{},"redis":{},"manticore":{},"proxy":{},"mailpit":{},"prometheus":{},"pushgateway":{},"grafana":{},"node-exporter":{},"mysqld-exporter":{},"redis-exporter":{}}}', 0, -1).',"x-changed":1}'])->mustRun();
        $this->assertStringContainsString('artisan migrate', $this->events());
    }

    public function test_build_then_up_uses_the_same_drain_and_migration_rollout(): void
    {
        $this->make(['build'])->mustRun();
        $this->make(['up'])->mustRun();
        $this->assertStringContainsString('artisan tmux:drain --timeout=900', $this->events());
        $this->assertStringContainsString('artisan migrate --force --no-interaction', $this->events());
        $this->assertStringContainsString(' up -d --no-deps --force-recreate --wait', $this->events());
    }

    public function test_up_cannot_bypass_a_failed_migration_for_existing_stopped_containers(): void
    {
        $process = $this->make(['up'], ['NNTMUX_MIGRATION_FAIL' => '1', 'NNTMUX_SERVICE_MISSING' => '1']);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('artisan migrate', $this->events());
        $this->assertStringNotContainsString(' up -d --no-deps --force-recreate --wait --wait-timeout 240 web horizon scheduler indexer', $this->events());
    }

    public function test_up_preserves_first_bringup_when_no_app_containers_exist(): void
    {
        $this->make(['up'], ['NNTMUX_NO_EXISTING_APP' => '1'])->mustRun();
        $this->assertStringContainsString(' up -d', $this->events());
        $this->assertStringNotContainsString('artisan migrate', $this->events());
    }

    public function test_compose_override_files_are_used_for_config_fingerprint_and_every_runtime_call(): void
    {
        $files = $this->directory.'/docker/docker-compose.yml:'.$this->directory.'/cutover.yml';
        $this->make(['deploy'], ['COMPOSE_FILE' => $files])->mustRun();
        $composeCalls = array_filter(explode("\n", $this->events()), fn (string $line): bool => str_contains($line, ' compose '));
        $this->assertNotEmpty($composeCalls);
        foreach ($composeCalls as $call) {
            $this->assertStringStartsWith('COMPOSE_FILE='.$files.' compose ', $call);
        }
        $this->assertStringContainsString(' config --format json', $this->events());
        $this->assertStringContainsString('artisan tmux:drain --timeout=900', $this->events());
        $this->assertStringContainsString(' up -d --no-deps --force-recreate --wait', $this->events());
    }

    public function test_legacy_dc_overrides_are_used_for_every_compose_call_without_shell_evaluation(): void
    {
        $command = 'docker compose --project-directory "'.$this->directory.'/docker" -f "'.$this->directory.'/docker/docker-compose.yml" -f "'.$this->directory.'/cutover with spaces.yml"';
        $this->make(['deploy', 'DC='.$command])->mustRun();
        $composeCalls = array_filter(explode("\n", $this->events()), fn (string $line): bool => str_contains($line, ' compose '));
        $this->assertNotEmpty($composeCalls);
        foreach ($composeCalls as $call) {
            $this->assertStringContainsString('-f '.$this->directory.'/cutover with spaces.yml', $call);
        }
        $this->assertStringContainsString(' config --format json', $this->events());
        $this->assertStringContainsString('artisan migrate', $this->events());
    }

    public function test_checkout_changes_while_stopping_writers_abort_before_migration(): void
    {
        $process = $this->deploy(['NNTMUX_STALE_STOP' => '1']);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString(' stop web scheduler indexer', $this->events());
        $this->assertStringNotContainsString('artisan migrate', $this->events());
        $this->assertStringNotContainsString(' up -d --no-deps --force-recreate --wait --wait-timeout 240 web horizon scheduler indexer', $this->events());
        $this->assertLessThan(strpos($this->events(), 'scheduler.py drain'), strrpos($this->events(), ' up -d') ?: -1, 'nothing may start after the writers stop');
        $this->assertStringNotContainsString('tmux:drain --resume', $this->events());
        $this->assertNotStamped();
    }

    public function test_legacy_scheduler_requires_opt_in_before_any_service_stops(): void
    {
        $process = $this->deploy(['NNTMUX_SCHEDULER_CAPABILITY' => 'legacy']);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringNotContainsString(' stop ', $this->events());
        $this->assertStringNotContainsString('scheduler python3 - drain', $this->events());
    }

    public function test_scheduler_probe_failure_never_falls_back_to_legacy(): void
    {
        $process = $this->deploy(['NNTMUX_SCHEDULER_CAPABILITY_FAIL' => '1', 'DEPLOY_LEGACY_DRAIN' => '1']);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringNotContainsString('scheduler python3 - drain', $this->events());
        $this->assertStringNotContainsString(' stop ', $this->events());
    }

    public function test_legacy_scheduler_is_reverified_then_stopped_without_reopening_admission(): void
    {
        $this->deploy(['NNTMUX_SCHEDULER_CAPABILITY' => 'legacy', 'DEPLOY_LEGACY_DRAIN' => '1'])->mustRun();
        $steps = [' stop --timeout 1920 horizon', 'scheduler python3 - drain 1800', 'scheduler python3 - check', ' stop --timeout 1 scheduler', ' stop web scheduler indexer', 'artisan migrate'];
        $previous = -1;
        foreach ($steps as $step) {
            $position = strpos($this->events(), $step);
            $this->assertNotFalse($position, $step);
            $this->assertGreaterThan($previous, $position, $step);
            $previous = $position;
        }
        $this->assertStringNotContainsString('scheduler python3 - resume', $this->events());
    }

    public function test_lost_legacy_scheduler_barrier_prevents_stop_and_restores_admission(): void
    {
        $process = $this->deploy(['NNTMUX_SCHEDULER_CAPABILITY' => 'legacy', 'DEPLOY_LEGACY_DRAIN' => '1', 'NNTMUX_SCHEDULER_CHECK_FAIL' => '1']);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('scheduler python3 - resume', $this->events());
        $this->assertStringNotContainsString(' stop --timeout 1 scheduler', $this->events());
        $this->assertStringNotContainsString(' stop web', $this->events());
        $this->assertStringNotContainsString('artisan migrate', $this->events());
    }

    public function test_stale_checkout_after_scheduler_drain_restores_without_stopping_services(): void
    {
        $process = $this->deploy(['NNTMUX_STALE_SCHEDULER_DRAIN' => '1']);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('scheduler.py restore', $this->events());
        $this->assertStringNotContainsString(' stop web', $this->events());
        $this->assertStringNotContainsString('artisan migrate', $this->events());
    }

    public function test_native_scheduler_barrier_is_rechecked_before_stopping_writers(): void
    {
        $process = $this->deploy(['NNTMUX_SCHEDULER_CHECK_FAIL' => '1']);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('scheduler.py restore --token=', $this->events());
        $this->assertStringNotContainsString(' stop web', $this->events());
        $this->assertStringNotContainsString('artisan migrate', $this->events());
    }

    public function test_invalid_scheduler_timeout_does_not_touch_admission(): void
    {
        $process = $this->deploy(['DEPLOY_SCHEDULER_DRAIN_TIMEOUT' => 'invalid']);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringNotContainsString('scheduler.py drain', $this->events());
        $this->assertStringNotContainsString('scheduler.py restore', $this->events());
        $this->assertStringNotContainsString(' stop ', $this->events());
    }

    public function test_failed_horizon_shutdown_restarts_it_before_any_drain_or_migration(): void
    {
        $process = $this->deploy(['NNTMUX_HORIZON_STOP_FAIL' => '1']);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString(' stop --timeout 1920 horizon', $this->events());
        $this->assertStringContainsString(' start horizon', $this->events());
        $this->assertStringNotContainsString('scheduler.py drain', $this->events());
        $this->assertStringNotContainsString('tmux:drain', $this->events());
        $this->assertStringNotContainsString(' stop web scheduler indexer', $this->events());
        $this->assertStringNotContainsString('artisan migrate', $this->events());
        $this->assertNotStamped();
    }

    public function test_failed_horizon_lookup_aborts_before_any_drain_or_migration(): void
    {
        $process = $this->deploy(['NNTMUX_HORIZON_PS_FAIL' => '1']);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringNotContainsString('scheduler.py drain', $this->events());
        $this->assertStringNotContainsString(' stop ', $this->events());
        $this->assertStringNotContainsString('artisan migrate', $this->events());
    }

    public function test_writers_keep_running_while_horizon_drains(): void
    {
        $this->deploy()->mustRun();
        $events = $this->events();
        $horizon = strpos($events, ' stop --timeout 1920 horizon');
        $this->assertNotFalse($horizon);
        $this->assertGreaterThan($horizon, strpos($events, 'scheduler.py drain'));
        $this->assertGreaterThan($horizon, strpos($events, 'artisan tmux:drain --timeout='));
    }

    public function test_failed_scheduler_drain_restarts_horizon_keeps_writers_live_and_never_migrates(): void
    {
        $process = $this->deploy(['NNTMUX_SCHEDULER_DRAIN_FAIL' => '1']);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString(' start horizon', $this->events());
        $this->assertStringNotContainsString(' stop web', $this->events());
        $this->assertStringNotContainsString('artisan migrate', $this->events());
    }

    public function test_indexer_drain_failure_resumes_an_already_drained_scheduler_and_horizon(): void
    {
        $process = $this->deploy(['NNTMUX_DRAIN_FAIL' => '1']);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('scheduler.py restore', $this->events());
        $this->assertStringContainsString(' start horizon', $this->events());
        $this->assertStringNotContainsString(' stop web', $this->events());
    }

    public function test_legacy_indexer_without_opt_in_never_stops_horizon(): void
    {
        $process = $this->deploy(['NNTMUX_LEGACY_IMAGE' => '1']);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringNotContainsString(' stop ', $this->events());
        $this->assertStringNotContainsString('scheduler.py drain', $this->events());
    }

    public function test_documentation_and_test_changes_skip_all_builds_drains_and_restarts(): void
    {
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        file_put_contents($this->directory.'/events', '');

        mkdir($this->directory.'/docs', 0777, true);
        mkdir($this->directory.'/tests/Feature', 0777, true);
        file_put_contents($this->directory.'/README.md', "# Docs\n");
        file_put_contents($this->directory.'/docs/architecture.md', "# Arch\n");
        file_put_contents($this->directory.'/tests/Feature/SampleTest.php', "<?php\n");
        (new Process(['git', 'add', '.'], $this->directory))->mustRun();
        (new Process(['git', 'commit', '-q', '-m', 'docs and tests'], $this->directory))->mustRun();

        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        $this->assertStringNotContainsString('build --target', $this->events());
        $this->assertStringNotContainsString('scheduler.py drain', $this->events());
        $this->assertStringNotContainsString('tmux:drain', $this->events());
        $this->assertStringNotContainsString(' stop ', $this->events());
        $this->assertStringNotContainsString('artisan migrate', $this->events());
        $this->assertStringNotContainsString(' up -d', $this->events());
    }

    public function test_files_outside_the_image_context_never_trigger_a_rollout(): void
    {
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        file_put_contents($this->directory.'/events', '');

        mkdir($this->directory.'/storage/app', 0777, true);
        mkdir($this->directory.'/deploy', 0777, true);
        file_put_contents($this->directory.'/.env.testing', "APP_ENV=testing\n");
        file_put_contents($this->directory.'/storage/app/.gitignore', "*\n");
        file_put_contents($this->directory.'/deploy/select.sh', "#!/bin/sh\n");
        (new Process(['git', 'add', '.'], $this->directory))->mustRun();
        (new Process(['git', 'commit', '-q', '-m', 'test env and runtime placeholders'], $this->directory))->mustRun();

        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        $this->assertStringNotContainsString('build --target', $this->events());
        $this->assertStringNotContainsString(' up -d', $this->events());
    }

    public function test_fingerprinting_fails_closed_outside_a_git_checkout(): void
    {
        $outside = $this->directory.'/not-a-checkout';
        mkdir($outside);
        $process = new Process([$this->directory.'/docker/bin/deploy-plan', '--fingerprint'], env: ['DEPLOY_REPO' => $outside, 'GIT_CEILING_DIRECTORIES' => $this->directory]);
        $process->run();
        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('cannot fingerprint the checkout', $process->getErrorOutput());
    }

    public function test_web_only_changes_rebuild_and_recreate_only_web_without_draining_writers_or_migrating(): void
    {
        mkdir($this->directory.'/resources/js', 0777, true);
        file_put_contents($this->directory.'/resources/js/app.js', "console.log(1);\n");
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        file_put_contents($this->directory.'/events', '');

        file_put_contents($this->directory.'/resources/js/app.js', "console.log(2);\n");

        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        $events = $this->events();
        $this->assertStringContainsString('build --target selfhosted-web', $events);
        $this->assertStringNotContainsString('build --target selfhosted-horizon', $events);
        $this->assertStringNotContainsString('build --target selfhosted-scheduler', $events);
        $this->assertStringNotContainsString('build --target selfhosted-indexer', $events);
        $this->assertStringNotContainsString('scheduler.py drain', $events);
        $this->assertStringNotContainsString('tmux:drain', $events);
        $this->assertStringNotContainsString(' stop ', $events);
        $this->assertStringNotContainsString('artisan migrate', $events);
        $this->assertStringContainsString(' up -d --no-deps --force-recreate --wait --wait-timeout 240 web', $events);
        $this->assertStringNotContainsString('horizon scheduler indexer', $events);
        $this->assertStringNotContainsString('releases:verification-barrier', $events);
    }

    public function test_a_selective_rollout_adopts_and_releases_a_barrier_left_by_a_failed_attempt(): void
    {
        mkdir($this->directory.'/resources/js', 0777, true);
        file_put_contents($this->directory.'/resources/js/app.js', "console.log(1);\n");
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        file_put_contents($this->directory.'/barrier.log', "barrier held\n");
        touch($this->directory.'/barrier.running');
        file_put_contents($this->directory.'/events', '');

        file_put_contents($this->directory.'/resources/js/app.js', "console.log(2);\n");
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();

        $events = $this->events();
        $this->assertStringNotContainsString('releases:verification-barrier --timeout', $events);
        $this->assertGreaterThan(strpos($events, ' up -d --no-deps --force-recreate --wait --wait-timeout 240 web'), strpos($events, 'released'));
        $this->assertFileDoesNotExist($this->directory.'/barrier.running');
    }

    public function test_scheduler_only_changes_rebuild_drain_and_recreate_only_scheduler(): void
    {
        mkdir($this->directory.'/docker/app/bin', 0777, true);
        file_put_contents($this->directory.'/docker/app/bin/scheduler.py', "# one\n");
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        file_put_contents($this->directory.'/events', '');

        file_put_contents($this->directory.'/docker/app/bin/scheduler.py', "# two\n");

        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        $events = $this->events();
        $this->assertStringContainsString('build --target selfhosted-scheduler', $events);
        $this->assertStringNotContainsString('build --target selfhosted-web', $events);
        $this->assertStringNotContainsString('build --target selfhosted-horizon', $events);
        $this->assertStringNotContainsString('build --target selfhosted-indexer', $events);
        $this->assertStringContainsString('scheduler.py drain --timeout=1800', $events);
        $this->assertStringContainsString('scheduler.py check', $events);
        $this->assertStringContainsString(' stop scheduler', $events);
        $this->assertStringNotContainsString('tmux:drain', $events);
        $this->assertStringNotContainsString(' stop --timeout 1920 horizon', $events);
        $this->assertStringNotContainsString('artisan migrate', $events);
        $this->assertStringContainsString(' up -d --no-deps --force-recreate --wait --wait-timeout 240 scheduler', $events);
    }

    public function test_indexer_only_changes_rebuild_drain_and_recreate_only_indexer(): void
    {
        mkdir($this->directory.'/docker/app/bin', 0777, true);
        file_put_contents($this->directory.'/docker/app/bin/indexer.sh', "#!/bin/sh\n");
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        file_put_contents($this->directory.'/events', '');

        file_put_contents($this->directory.'/docker/app/bin/indexer.sh', "#!/bin/sh\nexit 0\n");

        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        $events = $this->events();
        $this->assertStringContainsString('build --target selfhosted-indexer', $events);
        $this->assertStringNotContainsString('build --target selfhosted-web', $events);
        $this->assertStringNotContainsString('build --target selfhosted-horizon', $events);
        $this->assertStringNotContainsString('build --target selfhosted-scheduler', $events);
        $this->assertStringContainsString('artisan tmux:drain --timeout=900', $events);
        $this->assertStringContainsString(' stop indexer', $events);
        $this->assertStringNotContainsString('scheduler.py drain', $events);
        $this->assertStringNotContainsString(' stop --timeout 1920 horizon', $events);
        $this->assertStringNotContainsString('artisan migrate', $events);
        $this->assertStringContainsString(' up -d --no-deps --force-recreate --wait --wait-timeout 240 indexer', $events);
    }

    public function test_application_code_reachable_from_several_roles_rebuilds_and_drains_every_app_service(): void
    {
        // Each of these is executed by more than one role (queued jobs, scheduled commands, web
        // controllers, service providers), so none may be treated as single-role.
        $paths = [
            'config/horizon.php', 'routes/web.php', 'routes/console.php', 'app/Services/Tmux/Tmux.php',
            'app/Services/Binaries/BinariesConfig.php', 'app/Http/Controllers/HomeController.php',
            'resources/views/home.blade.php', 'resources/forum/views/index.blade.php', 'public/robots.txt',
        ];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        foreach ($paths as $path) {
            file_put_contents($this->directory.'/events', '');
            @mkdir(dirname($this->directory.'/'.$path), 0777, true);
            file_put_contents($this->directory.'/'.$path, $path."\n");

            $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
            $events = $this->events();
            foreach (['web', 'horizon', 'scheduler', 'indexer'] as $role) {
                $this->assertStringContainsString('build --target selfhosted-'.$role, $events, $path);
            }
            $this->assertStringContainsString('scheduler.py drain --timeout=1800', $events, $path);
            $this->assertStringContainsString('artisan tmux:drain --timeout=900', $events, $path);
            $this->assertStringContainsString(' stop --timeout 1920 horizon', $events, $path);
            $this->assertStringContainsString(' up -d --no-deps --force-recreate --wait --wait-timeout 240 web horizon scheduler indexer', $events, $path);
        }
    }

    public function test_migration_changes_trigger_full_writer_quiesce_and_artisan_migrate(): void
    {
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        file_put_contents($this->directory.'/events', '');

        mkdir($this->directory.'/database/migrations', 0777, true);
        file_put_contents($this->directory.'/database/migrations/2026_10_16_000000_test.php', "<?php\n");

        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        $events = $this->events();
        $this->assertStringContainsString('scheduler.py drain --timeout=1800', $events);
        $this->assertStringContainsString('artisan tmux:drain --timeout=900', $events);
        $this->assertStringContainsString(' stop --timeout 1920 horizon', $events);
        $this->assertStringContainsString(' stop web scheduler indexer', $events);
        $this->assertStringContainsString('artisan migrate --force --no-interaction', $events);
        $this->assertStringContainsString(' up -d --no-deps --force-recreate --wait --wait-timeout 240 web horizon scheduler indexer', $events);
    }

    public function test_stateless_infra_config_changes_recreate_only_the_affected_infra_service_without_draining_writers(): void
    {
        mkdir($this->directory.'/docker/config/caddy', 0777, true);
        file_put_contents($this->directory.'/docker/config/caddy/Caddyfile', ":80\n");
        $config = ['NNTMUX_CONFIG_ID' => $this->composeJson()];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();
        file_put_contents($this->directory.'/events', '');

        // The mounted file's content changes; the rendered Compose text does not.
        file_put_contents($this->directory.'/docker/config/caddy/Caddyfile', ":8080\n");

        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();
        $events = $this->events();
        $this->assertStringNotContainsString('build --target', $events);
        $this->assertStringNotContainsString('scheduler.py drain', $events);
        $this->assertStringNotContainsString('tmux:drain', $events);
        $this->assertStringNotContainsString(' stop ', $events);
        $this->assertStringNotContainsString('artisan migrate', $events);
        $this->assertStringContainsString(' up -d --no-deps --force-recreate --wait --wait-timeout 240 proxy', $events);
        $this->assertDoesNotMatchRegularExpression('/ up -d[^\\n]* redis\\b/', $events);
    }

    public function test_compose_command_text_changes_never_recreate_data_services(): void
    {
        $config = ['NNTMUX_CONFIG_ID' => $this->composeJson()];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();
        file_put_contents($this->directory.'/events', '');

        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config + ['DOCKER_CONTEXT' => 'other-spelling'])->mustRun();
        $events = $this->events();
        $this->assertStringNotContainsString('--force-recreate --wait --wait-timeout 240 redis', $events);
        $this->assertStringNotContainsString('--force-recreate --wait --wait-timeout 240 proxy', $events);
    }

    public function test_dockerignore_and_file_permission_changes_invalidate_build_fingerprints(): void
    {
        mkdir($this->directory.'/docker/app', 0777, true);
        file_put_contents($this->directory.'/docker/app/Caddyfile', ":8080\n");
        chmod($this->directory.'/docker/app/Caddyfile', 0644);
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();

        file_put_contents($this->directory.'/events', '');
        chmod($this->directory.'/docker/app/Caddyfile', 0640);
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        $this->assertStringContainsString('build --target selfhosted-web', $this->events());

        file_put_contents($this->directory.'/events', '');
        file_put_contents($this->directory.'/.dockerignore', "vendor\n");
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        $events = $this->events();
        $this->assertStringContainsString('build --target selfhosted-web', $events);
        $this->assertStringContainsString('build --target selfhosted-horizon', $events);
        $this->assertStringContainsString('build --target selfhosted-scheduler', $events);
        $this->assertStringContainsString('build --target selfhosted-indexer', $events);
    }

    public function test_partial_shared_role_image_tags_build_selfhosted_for_shared_tag_and_specific_targets_for_unique_tags(): void
    {
        $this->make(['build'], [
            'WEB_IMAGE' => 'custom/web:local',
            'HORIZON_IMAGE' => 'custom/worker:local',
            'SCHEDULER_IMAGE' => 'custom/worker:local',
            'INDEXER_IMAGE' => 'custom/indexer:local',
        ])->mustRun();
        $events = $this->events();
        $this->assertStringContainsString('build --target selfhosted-web -t custom/web:local', $events);
        $this->assertStringContainsString('build --target selfhosted -t custom/worker:local', $events);
        $this->assertStringContainsString('build --target selfhosted-indexer -t custom/indexer:local', $events);
        $this->assertSame(1, substr_count($events, '-t custom/worker:local'));
    }

    public function test_stateful_data_service_config_changes_drain_writers_before_recreating_the_data_service(): void
    {
        mkdir($this->directory.'/docker/config/redis', 0777, true);
        file_put_contents($this->directory.'/docker/config/redis/redis.conf', "maxmemory 512mb\n");
        $config = ['NNTMUX_CONFIG_ID' => $this->composeJson()];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();
        file_put_contents($this->directory.'/events', '');

        file_put_contents($this->directory.'/docker/config/redis/redis.conf', "maxmemory 1gb\n");

        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();
        $events = $this->events();
        $recreate = ' up -d --no-deps --force-recreate --wait --wait-timeout 240 redis';
        $this->assertStringContainsString('scheduler.py drain --timeout=1800', $events);
        $this->assertStringContainsString('artisan tmux:drain --timeout=900', $events);
        $this->assertStringContainsString(' stop web scheduler indexer', $events);
        $this->assertStringContainsString($recreate, $events);
        $this->assertDoesNotMatchRegularExpression('/ up -d[^\\n]* manticore\\b/', $events);
        $this->assertLessThan(strpos($events, $recreate), strpos($events, ' stop web scheduler indexer'));
    }

    public function test_deploy_plan_outputs_human_readable_per_service_explanation(): void
    {
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        mkdir($this->directory.'/docker/app/bin', 0777, true);
        file_put_contents($this->directory.'/docker/app/bin/scheduler.py', "# updated\n");

        $process = $this->make(['deploy-plan']);
        $process->mustRun();
        $output = $process->getOutput();
        $this->assertStringContainsString('Deployment Mode : selective', $output);
        $this->assertStringContainsString('Build Targets   : scheduler', $output);
        $this->assertStringContainsString('Run Migrations  : no', $output);
        $this->assertMatchesRegularExpression('/scheduler\s+app\s+drain\+recreate\s+code\/config changed/', $output);
        $this->assertMatchesRegularExpression('/web\s+app\s+skip\s+unchanged/', $output);
    }

    public function test_first_deploy_without_stamp_reconciles_data_and_infra_without_force(): void
    {
        $this->deploy()->mustRun();
        $events = $this->events();
        $reconcile = ' up -d --no-deps --wait --wait-timeout 240 mariadb';
        $this->assertStringContainsString(' up -d --no-deps --wait --wait-timeout 240 redis', $events);
        $this->assertStringContainsString(' up -d --no-deps --wait --wait-timeout 240 manticore', $events);
        $this->assertStringContainsString($reconcile, $events);
        $this->assertStringNotContainsString('--force-recreate --wait mariadb', $events);
        $this->assertLessThan(strpos($events, 'artisan migrate'), strpos($events, $reconcile));
        $this->assertGreaterThan(strpos($events, ' stop web scheduler indexer'), strpos($events, $reconcile));
        $infra = ' up -d --no-deps --wait --wait-timeout 240 proxy';
        $this->assertStringContainsString(' up -d --no-deps --wait --wait-timeout 240 mailpit', $events);
        $this->assertStringContainsString($infra, $events);
        $this->assertStringNotContainsString('--force-recreate --wait --wait-timeout 240 proxy', $events);
        $this->assertLessThan(strpos($events, 'scheduler.py drain'), strpos($events, $infra), 'infra is applied before any drain');
    }

    public function test_first_deploy_recreates_infra_whose_mounted_config_is_newer_than_its_container(): void
    {
        mkdir($this->directory.'/docker/config/caddy', 0777, true);
        file_put_contents($this->directory.'/docker/config/caddy/Caddyfile', ":8080\n");
        $this->deploy(['NNTMUX_CONFIG_ID' => $this->composeJson(), 'NNTMUX_STARTED_AT' => '2000-01-01T00:00:00Z'])->mustRun();
        $this->assertStringContainsString(' up -d --no-deps --force-recreate --wait --wait-timeout 240 proxy', $this->events());
    }

    public function test_deleting_a_nested_file_from_a_read_only_mount_counts_as_newer_than_the_container(): void
    {
        $tree = $this->directory.'/docker/monitoring/provisioning';
        mkdir($tree.'/datasources', 0777, true);
        file_put_contents($tree.'/datasources/prometheus.yml', "a\n");
        file_put_contents($tree.'/datasources/legacy.yml', "b\n");
        foreach ([$tree.'/datasources/prometheus.yml', $tree.'/datasources', $tree] as $path) {
            touch($path, 946684800);
        }
        $grafana = ['volumes' => [['type' => 'bind', 'source' => $tree, 'target' => '/etc/grafana/provisioning', 'read_only' => true]]];
        $config = ['NNTMUX_CONFIG_ID' => (string) json_encode(['services' => ['web' => [], 'horizon' => [], 'scheduler' => [], 'indexer' => [], 'grafana' => $grafana]], JSON_UNESCAPED_SLASHES)];
        unlink($tree.'/datasources/legacy.yml');
        touch($tree, 946684800);
        $this->deploy($config + ['NNTMUX_STARTED_AT' => '2001-01-01T00:00:00Z'])->mustRun();
        $this->assertStringContainsString(' up -d --no-deps --force-recreate --wait --wait-timeout 240 grafana', $this->events());
    }

    public function test_infra_recreated_before_a_failed_drain_is_recorded_so_a_revert_or_retry_is_correct(): void
    {
        mkdir($this->directory.'/docker/config/caddy', 0777, true);
        file_put_contents($this->directory.'/docker/config/caddy/Caddyfile', "A\n");
        $config = ['NNTMUX_CONFIG_ID' => $this->composeJson()];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();

        // B is applied to proxy, then a code change's drain fails.
        file_put_contents($this->directory.'/docker/config/caddy/Caddyfile', "B\n");
        file_put_contents($this->directory.'/app.txt', "two\n");
        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config + ['NNTMUX_SCHEDULER_DRAIN_FAIL' => '1'])->run();
        $this->assertStringContainsString(' up -d --no-deps --force-recreate --wait --wait-timeout 240 proxy', $this->events());
        $this->assertSame('stale', strtok((string) file_get_contents($this->directory.'/docker/.deploy.stamp'), "\n"));

        // A retry does not recreate proxy again; it is already on B.
        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config + ['NNTMUX_SCHEDULER_DRAIN_FAIL' => '1'])->run();
        $this->assertStringNotContainsString(' proxy', $this->events());

        // Reverting both changes must put proxy back on A rather than skip.
        file_put_contents($this->directory.'/docker/config/caddy/Caddyfile', "A\n");
        file_put_contents($this->directory.'/app.txt', "one\n");
        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();
        $this->assertStringContainsString(' up -d --no-deps --force-recreate --wait --wait-timeout 240 proxy', $this->events());

        // And afterwards the stack is fully stamped again: the next poll skips.
        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();
        $this->assertStringNotContainsString(' up -d', $this->events());
    }

    public function test_mount_inside_an_unreadable_directory_fails_closed(): void
    {
        mkdir($this->directory.'/docker/config/redis', 0777, true);
        file_put_contents($this->directory.'/docker/config/redis/redis.conf', "maxmemory 1gb\n");
        chmod($this->directory.'/docker/config/redis', 0000);
        try {
            $process = $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_CONFIG_ID' => $this->composeJson()]);
            $process->run();
        } finally {
            chmod($this->directory.'/docker/config/redis', 0755);
        }
        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('cannot fingerprint mounted configuration', $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->directory.'/docker/.deploy.stamp');
    }

    public function test_app_rollout_failing_after_recreate_never_lets_a_revert_be_skipped(): void
    {
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        file_put_contents($this->directory.'/app.txt', "two\n");
        $failed = $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_APP_UP_FAIL' => '1']);
        $failed->run();
        $this->assertSame(52, $failed->getExitCode());

        file_put_contents($this->directory.'/app.txt', "one\n");
        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        $this->assertStringContainsString(' up -d --no-deps --force-recreate --wait --wait-timeout 240 web horizon scheduler indexer', $this->events());
    }

    public function test_infra_recreate_failing_never_lets_a_revert_be_skipped(): void
    {
        mkdir($this->directory.'/docker/config/caddy', 0777, true);
        file_put_contents($this->directory.'/docker/config/caddy/Caddyfile', "A\n");
        $config = ['NNTMUX_CONFIG_ID' => $this->composeJson()];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();

        file_put_contents($this->directory.'/docker/config/caddy/Caddyfile', "B\n");
        $failed = $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config + ['NNTMUX_PROXY_FAIL' => '1']);
        $failed->run();
        $this->assertSame(51, $failed->getExitCode());

        file_put_contents($this->directory.'/docker/config/caddy/Caddyfile', "A\n");
        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config + ['NNTMUX_STARTED_AT' => '2000-01-01T00:00:00Z'])->mustRun();
        $this->assertStringContainsString(' up -d --no-deps --force-recreate --wait --wait-timeout 240 proxy', $this->events());
    }

    public function test_mounted_file_changed_during_its_recreate_is_redeployed_on_the_next_poll(): void
    {
        mkdir($this->directory.'/docker/config/caddy', 0777, true);
        $caddyfile = $this->directory.'/docker/config/caddy/Caddyfile';
        file_put_contents($caddyfile, "A\n");
        $config = ['NNTMUX_CONFIG_ID' => $this->composeJson()];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();

        file_put_contents($caddyfile, "B\n");
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config + ['NNTMUX_PROXY_EDIT' => $caddyfile])->run();
        $this->assertSame("C\n", file_get_contents($caddyfile));

        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();
        $this->assertStringContainsString(' up -d --no-deps --force-recreate --wait --wait-timeout 240 proxy', $this->events());
    }

    public function test_recorded_mount_change_with_a_reverted_app_change_needs_no_app_rollout(): void
    {
        mkdir($this->directory.'/docker/config/caddy', 0777, true);
        file_put_contents($this->directory.'/docker/config/caddy/Caddyfile', "A\n");
        $config = ['NNTMUX_CONFIG_ID' => $this->composeJson()];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();

        file_put_contents($this->directory.'/docker/config/caddy/Caddyfile', "B\n");
        file_put_contents($this->directory.'/app.txt', "two\n");
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config + ['NNTMUX_SCHEDULER_DRAIN_FAIL' => '1'])->run();

        file_put_contents($this->directory.'/app.txt', "one\n");
        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();
        $events = $this->events();
        $this->assertStringNotContainsString('drain', $events);
        $this->assertStringNotContainsString('artisan migrate', $events);
        $this->assertStringNotContainsString(' up -d', $events);
    }

    public function test_interrupted_infra_only_rollout_is_not_retried_as_a_full_app_rollout(): void
    {
        $base = json_decode($this->composeJson(), true);
        $config = fn (string $image): array => ['NNTMUX_CONFIG_ID' => (string) json_encode(array_replace_recursive($base, ['services' => ['proxy' => ['image' => $image]]]), JSON_UNESCAPED_SLASHES)];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config('caddy:1'))->mustRun();

        // The proxy rollout completes, then the run is interrupted before the final stamp.
        $interrupted = $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config('caddy:2') + ['NNTMUX_PROXY_EDIT' => $this->directory.'/app.txt']);
        $interrupted->run();
        $this->assertSame(76, $interrupted->getExitCode());
        file_put_contents($this->directory.'/app.txt', "one\n");

        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config('caddy:2'))->mustRun();
        $events = $this->events();
        $this->assertStringNotContainsString('drain', $events);
        $this->assertStringNotContainsString('artisan migrate', $events);
        $this->assertStringNotContainsString('--wait-timeout 240 web', $events);
        $this->assertStringNotContainsString(' proxy', $events);
    }

    public function test_compose_change_requiring_migration_still_migrates_when_retried_after_a_failed_drain(): void
    {
        $base = json_decode($this->composeJson(), true);
        $config = function (string $host) use ($base): array {
            $services = $base;
            foreach (['web', 'horizon', 'scheduler', 'indexer'] as $role) {
                $services['services'][$role] = ['environment' => ['DB_HOST' => $host]];
            }

            return ['NNTMUX_CONFIG_ID' => (string) json_encode($services, JSON_UNESCAPED_SLASHES)];
        };
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config('old'))->mustRun();
        file_put_contents($this->directory.'/events', '');

        $failed = $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config('new') + ['NNTMUX_SCHEDULER_DRAIN_FAIL' => '1']);
        $failed->run();
        $this->assertSame(47, $failed->getExitCode());
        $this->assertStringNotContainsString('artisan migrate', $this->events());

        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config('new'))->mustRun();
        $this->assertStringContainsString('artisan migrate --force --no-interaction', $this->events());
        $this->assertStringContainsString(' stop web scheduler indexer', $this->events());
    }

    public function test_retry_after_a_fallback_rollout_failed_past_its_migration_does_not_migrate_again(): void
    {
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        $context = ['DOCKER_CONTEXT' => 'other-context'];
        file_put_contents($this->directory.'/events', '');
        $failed = $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $context + ['NNTMUX_APP_UP_FAIL' => '1']);
        $failed->run();
        $this->assertSame(52, $failed->getExitCode());
        $this->assertStringContainsString('artisan migrate', $this->events());

        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $context)->mustRun();
        $this->assertStringNotContainsString('artisan migrate', $this->events());
        // Every service came up healthy despite the failed wait: nothing is repeated.
        $this->assertStringNotContainsString('--wait-timeout 240 web', $this->events());
    }

    public function test_removing_a_service_from_compose_needs_no_app_rollout(): void
    {
        $services = '"web":{},"horizon":{},"scheduler":{},"indexer":{},"redis":{},"manticore":{},"proxy":{}';
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_CONFIG_ID' => '{"services":{'.$services.',"mailpit":{},"sidecar":{}}}'])->mustRun();
        file_put_contents($this->directory.'/events', '');

        $plan = $this->make(['deploy-plan'], ['NNTMUX_CONFIG_ID' => '{"services":{'.$services.'}}']);
        $plan->mustRun();
        $this->assertMatchesRegularExpression('/mailpit\s+infra\s+skip\s+removed from Compose/', $plan->getOutput());
        $this->assertMatchesRegularExpression('/sidecar\s+infra\s+skip\s+removed from Compose/', $plan->getOutput());

        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_CONFIG_ID' => '{"services":{'.$services.'}}'])->mustRun();
        $events = $this->events();
        $this->assertStringNotContainsString('drain', $events);
        $this->assertStringNotContainsString('artisan migrate', $events);
        $this->assertStringNotContainsString(' up -d', $events);
        $this->assertStringNotContainsString('service:sidecar=', (string) file_get_contents($this->directory.'/docker/.deploy.stamp'));
    }

    public function test_plan_explanation_reports_the_writer_quiesce_a_data_reconcile_needs(): void
    {
        $config = ['NNTMUX_CONFIG_ID' => $this->composeJson()];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();
        $stamp = $this->directory.'/docker/.deploy.stamp';
        file_put_contents($stamp, preg_replace('/^service:redis=.*\n/m', '', (string) file_get_contents($stamp)));

        $plan = $this->make(['deploy-plan'], $config);
        $plan->mustRun();
        $this->assertStringContainsString('Deployment Mode : full', $plan->getOutput());
        $this->assertMatchesRegularExpression('/redis\s+data\s+reconcile/', $plan->getOutput());
        $this->assertMatchesRegularExpression('/scheduler\s+app\s+quiesce\+restart\s+data service restart/', $plan->getOutput());
    }

    public function test_unreadable_env_file_fails_closed(): void
    {
        mkdir($this->directory.'/docker/config', 0777, true);
        file_put_contents($this->directory.'/docker/config/app.env', "APP_KEY=x\n");
        chmod($this->directory.'/docker/config', 0000);
        try {
            $process = new Process([$this->directory.'/docker/bin/deploy-plan', '--fingerprint'], env: ['DEPLOY_REPO' => $this->directory, 'PYTHONDONTWRITEBYTECODE' => '1']);
            $process->run();
        } finally {
            chmod($this->directory.'/docker/config', 0755);
        }
        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('cannot read', $process->getErrorOutput());
    }

    public function test_unreadable_container_start_time_aborts_instead_of_recreating_data_services(): void
    {
        mkdir($this->directory.'/docker/config/redis', 0777, true);
        file_put_contents($this->directory.'/docker/config/redis/redis.conf', "maxmemory 1gb\n");
        $head = trim((new Process(['git', 'rev-parse', 'HEAD'], $this->directory))->mustRun()->getOutput());
        $legacy = $head." e3b0 e3b0 c16c bb93\n";
        file_put_contents($this->directory.'/docker/.deploy.stamp', $legacy);
        $process = $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_CONFIG_ID' => $this->composeJson(), 'NNTMUX_STARTED_AT' => 'not-a-time']);
        $process->run();
        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('cannot determine when redis started', $process->getErrorOutput());
        $this->assertStringNotContainsString('--force-recreate', $this->events());
        $this->assertStringNotContainsString('drain', $this->events());
        $this->assertSame($legacy, file_get_contents($this->directory.'/docker/.deploy.stamp'));
    }

    public function test_unparseable_rendered_compose_aborts_the_plan(): void
    {
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        file_put_contents($this->directory.'/events', '');
        $process = $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_CONFIG_ID' => 'wrapper noise {"services":{}}']);
        $process->run();
        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('cannot parse the rendered Compose configuration', $process->getErrorOutput());
        $this->assertStringNotContainsString(' up -d', $this->events());
        $this->assertStringNotContainsString('drain', $this->events());
    }

    public function test_a_stale_mode_in_the_environment_cannot_stand_in_for_a_failed_plan(): void
    {
        $head = trim((new Process(['git', 'rev-parse', 'HEAD'], $this->directory))->mustRun()->getOutput());
        $legacy = $head." e3b0 e3b0 c16c bb93\n";
        file_put_contents($this->directory.'/docker/.deploy.stamp', $legacy);
        $process = $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['MODE' => 'noop', 'NNTMUX_CONFIG_ID' => $this->composeJson(), 'NNTMUX_STARTED_AT' => 'not-a-time']);
        $process->run();
        $this->assertNotSame(0, $process->getExitCode());
        $this->assertSame($legacy, file_get_contents($this->directory.'/docker/.deploy.stamp'));
        $this->assertAttemptsClosed();
    }

    public function test_newly_rendered_data_service_starts_without_quiescing_writers(): void
    {
        $without = '{"services":{"web":{},"horizon":{},"scheduler":{},"indexer":{},"redis":{},"manticore":{},"proxy":{}}}';
        $with = '{"services":{"web":{},"horizon":{},"scheduler":{},"indexer":{},"mariadb":{},"redis":{},"manticore":{},"proxy":{}}}';
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_CONFIG_ID' => $without])->mustRun();
        file_put_contents($this->directory.'/events', '');

        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_CONFIG_ID' => $with, 'NNTMUX_NO_CONTAINER' => 'mariadb'])->mustRun();
        $events = $this->events();
        $this->assertStringContainsString(' up -d --no-deps --wait --wait-timeout 240 mariadb', $events);
        $this->assertStringNotContainsString('drain', $events);
        $this->assertStringNotContainsString(' stop ', $events);
        $this->assertStringNotContainsString('--wait-timeout 240 web', $events);
        $this->assertAttemptsClosed();
    }

    public function test_re_rendered_data_service_with_a_running_orphan_is_quiesced(): void
    {
        mkdir($this->directory.'/docker/config/redis', 0777, true);
        file_put_contents($this->directory.'/docker/config/redis/redis.conf', "maxmemory 1gb\n");
        $with = json_decode($this->composeJson(), true);
        $without = $with;
        unset($without['services']['redis']);
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_CONFIG_ID' => (string) json_encode($without, JSON_UNESCAPED_SLASHES)])->mustRun();
        $this->assertStringContainsString('compose:redis=absent', (string) file_get_contents($this->directory.'/docker/.deploy.stamp'));

        // The orphaned redis kept running and was edited meanwhile: rendering it again must recreate
        // it with writers stopped, never start or stamp it under live writers.
        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_CONFIG_ID' => (string) json_encode($with, JSON_UNESCAPED_SLASHES), 'NNTMUX_STARTED_AT' => '2000-01-01T00:00:00Z'])->mustRun();
        $events = $this->events();
        $recreate = ' up -d --no-deps --force-recreate --wait --wait-timeout 240 redis';
        $this->assertStringContainsString($recreate, $events);
        $this->assertLessThan(strpos($events, $recreate), strpos($events, ' stop web scheduler indexer'));
        $this->assertStringNotContainsString(' up -d --no-deps --wait --wait-timeout 240 redis', $events);
    }

    public function test_interrupted_recreate_of_a_changed_data_service_is_still_forced_on_retry(): void
    {
        mkdir($this->directory.'/docker/config/redis', 0777, true);
        file_put_contents($this->directory.'/docker/config/redis/redis.conf', "maxmemory 1gb\n");
        $config = ['NNTMUX_CONFIG_ID' => $this->composeJson()];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();

        // An mtime-preserving copy: the file is not newer than the container, only its content changed.
        file_put_contents($this->directory.'/docker/config/redis/redis.conf', "maxmemory 2gb\n");
        touch($this->directory.'/docker/config/redis/redis.conf', 946684800);
        $failed = $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config + ['NNTMUX_REDIS_FAIL' => '1']);
        $failed->run();
        $this->assertSame(53, $failed->getExitCode());
        $this->assertStringContainsString('service:redis=pending', (string) file_get_contents($this->directory.'/docker/.deploy.stamp'));

        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();
        $this->assertStringContainsString(' up -d --no-deps --force-recreate --wait --wait-timeout 240 redis', $this->events());
    }

    public function test_partially_failed_app_start_keeps_the_services_that_came_up(): void
    {
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        file_put_contents($this->directory.'/app.txt', "two\n");
        $failed = $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_APP_UP_FAIL' => '1', 'NNTMUX_UNHEALTHY' => 'indexer']);
        $failed->run();
        $this->assertSame(52, $failed->getExitCode());

        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        $events = $this->events();
        $this->assertStringContainsString(' up -d --no-deps --force-recreate --wait --wait-timeout 240 indexer', $events);
        $this->assertStringNotContainsString('web horizon', $events);
        $this->assertStringNotContainsString('scheduler.py drain', $events);
        $this->assertStringNotContainsString(' stop --timeout 1920 horizon', $events);
    }

    public function test_failed_container_query_during_planning_aborts_without_touching_services(): void
    {
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        file_put_contents($this->directory.'/app.txt', "two\n");
        file_put_contents($this->directory.'/events', '');
        $process = $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_PS_FAIL' => '1']);
        $process->run();
        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringNotContainsString(' up -d', $this->events());
        $this->assertStringNotContainsString('drain', $this->events());
    }

    public function test_dc_from_docker_env_replaces_the_default_compose_command(): void
    {
        file_put_contents($this->directory.'/docker/.env', "DC=docker compose --project-directory /persistent/runtime\n");
        $this->make(['deploy-plan'])->mustRun();
        $this->assertStringContainsString('compose --project-directory /persistent/runtime config --format json', $this->events());
    }

    public function test_forced_rollout_starts_a_data_service_whose_container_is_gone(): void
    {
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'FORCE=1'], ['NNTMUX_NO_CONTAINER' => 'redis'])->mustRun();
        $events = $this->events();
        $this->assertMatchesRegularExpression('/ up -d --no-deps --wait --wait-timeout 240 [^\n]*redis/', $events);
        $this->assertLessThan(strpos($events, 'artisan migrate'), strpos($events, ' stop web scheduler indexer'));
    }

    public function test_reverting_a_data_service_change_after_a_failed_drain_needs_no_rollout(): void
    {
        $base = json_decode($this->composeJson(), true);
        $config = fn (string $image): array => ['NNTMUX_CONFIG_ID' => (string) json_encode(array_replace_recursive($base, ['services' => ['redis' => ['image' => $image]]]), JSON_UNESCAPED_SLASHES)];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config('redis:7.4.1'))->mustRun();

        $failed = $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config('redis:7.4.2') + ['NNTMUX_SCHEDULER_DRAIN_FAIL' => '1']);
        $failed->run();
        $this->assertSame(47, $failed->getExitCode());

        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config('redis:7.4.1'))->mustRun();
        $events = $this->events();
        $this->assertStringNotContainsString('drain', $events);
        $this->assertStringNotContainsString('artisan migrate', $events);
        $this->assertStringNotContainsString(' up -d', $events);
    }

    public function test_compose_driven_migration_survives_an_interruption_after_writers_stop(): void
    {
        $base = json_decode($this->composeJson(), true);
        $config = function (string $host) use ($base): array {
            foreach (['web', 'horizon', 'scheduler', 'indexer'] as $role) {
                $base['services'][$role] = ['environment' => ['DB_HOST' => $host]];
            }

            return ['NNTMUX_CONFIG_ID' => (string) json_encode($base, JSON_UNESCAPED_SLASHES)];
        };
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config('old'))->mustRun();
        file_put_contents($this->directory.'/events', '');
        $failed = $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config('new') + ['NNTMUX_HORIZON_STOP_FAIL' => '1']);
        $failed->run();
        $this->assertSame(45, $failed->getExitCode());
        $this->assertStringNotContainsString('artisan migrate', $this->events());

        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config('new'))->mustRun();
        $this->assertStringContainsString('artisan migrate --force --no-interaction', $this->events());
    }

    public function test_roles_sharing_a_rebuilt_image_tag_are_recreated_in_the_same_rollout(): void
    {
        $shared = ['WEB_IMAGE' => 'custom/app:1', 'HORIZON_IMAGE' => 'custom/app:1', 'SCHEDULER_IMAGE' => 'custom/app:1', 'INDEXER_IMAGE' => 'custom/indexer:1'];
        mkdir($this->directory.'/resources/js', 0777, true);
        file_put_contents($this->directory.'/resources/js/app.js', "1\n");
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $shared)->mustRun();
        file_put_contents($this->directory.'/resources/js/app.js', "2\n");

        $plan = $this->make(['deploy-plan'], $shared);
        $plan->mustRun();
        $this->assertMatchesRegularExpression('/horizon\s+app\s+drain\+recreate\s+shares a rebuilt image/', $plan->getOutput());
        $this->assertMatchesRegularExpression('/scheduler\s+app\s+drain\+recreate\s+shares a rebuilt image/', $plan->getOutput());
        $this->assertMatchesRegularExpression('/indexer\s+app\s+skip\s+unchanged/', $plan->getOutput());
    }

    public function test_failed_image_inspection_aborts_instead_of_reading_as_a_missing_image(): void
    {
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        file_put_contents($this->directory.'/events', '');
        $process = $this->make(['deploy-plan'], ['NNTMUX_IMAGE_INSPECT_FAIL' => '1']);
        $process->run();
        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('cannot inspect image', $process->getErrorOutput());
    }

    public function test_failing_build_planner_is_never_reported_as_nothing_to_build(): void
    {
        $this->make(['build'])->mustRun();
        $process = $this->make(['build'], ['NNTMUX_IMAGE_INSPECT_FAIL' => '1']);
        $process->run();
        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringNotContainsString('nothing changed', $process->getOutput());
    }

    public function test_symlinked_justfile_path_uses_the_physical_stack_directory(): void
    {
        $link = $this->directory.'-link';
        symlink($this->directory, $link);
        try {
            $process = new Process(['just', '--justfile', $link.'/docker/Justfile', '--working-directory', $link.'/docker', 'deploy-plan', 'DEPLOY_BY=fixture'], env: [
                'PATH' => $this->directory.'/bin:'.getenv('PATH'), 'NNTMUX_DEPLOY_EVENTS' => $this->directory.'/events', 'NNTMUX_REPO' => $this->directory,
            ]);
            $process->mustRun();
        } finally {
            unlink($link);
        }
        $this->assertStringContainsString('COMPOSE_FILE='.realpath($this->directory).'/docker/docker-compose.yml', $this->events());
        $this->assertStringNotContainsString($link, $this->events());
    }

    public function test_all_app_compose_change_after_a_partial_failure_still_migrates(): void
    {
        $base = json_decode($this->composeJson(), true);
        $config = function (string $host) use ($base): array {
            foreach (['web', 'horizon', 'scheduler', 'indexer'] as $role) {
                $base['services'][$role] = ['environment' => ['DB_HOST' => $host]];
            }

            return ['NNTMUX_CONFIG_ID' => (string) json_encode($base, JSON_UNESCAPED_SLASHES)];
        };
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config('a'))->mustRun();
        // A code change whose app start partly fails: web never comes up healthy.
        file_put_contents($this->directory.'/app.txt', "two\n");
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config('a') + ['NNTMUX_APP_UP_FAIL' => '1', 'NNTMUX_UNHEALTHY' => 'web'])->run();

        // Then a database cutover reaches all four app services.
        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config('b'))->mustRun();
        $this->assertStringContainsString('artisan migrate --force --no-interaction', $this->events());
    }

    public function test_one_failing_infra_service_never_makes_the_retry_repeat_the_others(): void
    {
        $json = json_decode($this->composeJson(), true);
        $json['services']['prometheus'] = ['volumes' => [['type' => 'bind', 'source' => $this->directory.'/docker/config/prometheus.yml', 'target' => '/etc/prometheus/prometheus.yml', 'read_only' => true]]];
        mkdir($this->directory.'/docker/config/caddy', 0777, true);
        file_put_contents($this->directory.'/docker/config/caddy/Caddyfile', "A\n");
        file_put_contents($this->directory.'/docker/config/prometheus.yml', "a\n");
        $config = ['NNTMUX_CONFIG_ID' => (string) json_encode($json, JSON_UNESCAPED_SLASHES)];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();

        file_put_contents($this->directory.'/docker/config/caddy/Caddyfile', "B\n");
        file_put_contents($this->directory.'/docker/config/prometheus.yml', "b\n");
        $failed = $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config + ['NNTMUX_UP_FAIL_SERVICE' => 'prometheus']);
        $failed->run();
        $this->assertSame(54, $failed->getExitCode());

        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();
        $events = $this->events();
        $this->assertStringContainsString(' up -d --no-deps --force-recreate --wait --wait-timeout 240 prometheus', $events);
        $this->assertDoesNotMatchRegularExpression('/ up -d[^\n]* proxy\b/', $events);
    }

    public function test_stamping_failure_after_a_successful_rollout_is_not_reported_as_a_checkout_change(): void
    {
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        file_put_contents($this->directory.'/app.txt', "two\n");
        file_put_contents($this->directory.'/docker/.deploy.time', '1000');
        // The image inspection fails only after the rollout recreates the app, while the guard
        // writes the stamp.
        unlink($this->directory.'/recreated');
        $process = $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_INSPECT_FAIL_AFTER_UP' => '1']);
        $process->run();
        $this->assertSame(1, $process->getExitCode());
        $log = (string) file_get_contents($this->directory.'/docker/.deploy.log');
        $this->assertStringContainsString('result=stamp-failed', $log);
        $this->assertStringNotContainsString('tree-changed-during-run', $log);
        $this->assertNotSame('1000', trim((string) file_get_contents($this->directory.'/docker/.deploy.time')));
        $this->assertSame('stale', strtok((string) file_get_contents($this->directory.'/docker/.deploy.stamp'), "\n"));
        $this->assertAttemptsClosed();
    }

    public function test_host_system_paths_match_on_path_boundaries_only(): void
    {
        $script = 'import importlib.machinery, importlib.util, sys; l = importlib.machinery.SourceFileLoader("dp", sys.argv[1]); '
            .'s = importlib.util.spec_from_loader("dp", l); m = importlib.util.module_from_spec(s); l.exec_module(m); '
            .'print(" ".join(str(int(m.is_host_system(p))) for p in sys.argv[2:]))';
        $process = new Process(['python3', '-c', $script, $this->directory.'/docker/bin/deploy-plan',
            '/', '/proc', '/proc/1', '/sys/fs', '/run', '/var/run/docker.sock', '/development/stack', '/runner/x', '/sysadmin', '/srv/docker',
        ], env: ['PYTHONDONTWRITEBYTECODE' => '1']);
        $process->mustRun();
        $this->assertSame('1 1 1 1 1 1 0 0 0 0', trim($process->getOutput()));
    }

    public function test_docker_env_last_assignment_wins_and_whitespace_is_trimmed(): void
    {
        file_put_contents($this->directory.'/docker/.env', "WEB_IMAGE=first/web:1\nWEB_IMAGE = second/web:2\nDEPLOY_MIN_INTERVAL=  0\nINDEXER_IMAGE=  'idx:3'  # pinned\n");
        $this->make(['build'])->mustRun();
        $events = $this->events();
        $this->assertStringContainsString('build --target selfhosted-web -t second/web:2', $events);
        $this->assertStringContainsString('build --target selfhosted-indexer -t idx:3', $events);
        $this->assertStringNotContainsString('first/web', $events);
    }

    public function test_nested_recipes_and_helpers_work_without_just_on_path(): void
    {
        $just = trim((new Process(['sh', '-c', 'command -v just']))->mustRun()->getOutput());
        $process = new Process([$just, '--justfile', $this->directory.'/docker/Justfile', '--working-directory', $this->directory.'/docker', 'deploy', 'DEPLOY_BY=fixture'], env: [
            'PATH' => $this->directory.'/bin:/usr/bin:/bin', 'NNTMUX_DEPLOY_EVENTS' => $this->directory.'/events', 'NNTMUX_REPO' => $this->directory,
        ]);
        $process->mustRun();
        $this->assertStringContainsString('build --target selfhosted-web', $this->events());
        $this->assertStringContainsString(' up -d --no-deps --force-recreate --wait --wait-timeout 240 web horizon scheduler indexer', $this->events());
    }

    public function test_service_added_to_effective_compose_is_started_and_unrendered_data_is_left_alone(): void
    {
        $services = '"web":{},"horizon":{},"scheduler":{},"indexer":{},"redis":{},"manticore":{},"proxy":{}';
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_CONFIG_ID' => '{"services":{'.$services.'}}'])->mustRun();
        $this->assertStringNotContainsString('mariadb', $this->events());
        file_put_contents($this->directory.'/events', '');

        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_CONFIG_ID' => '{"services":{'.$services.',"sidecar":{}}}'])->mustRun();
        $events = $this->events();
        // A service added to Compose is created by a non-forced up.
        $this->assertStringContainsString(' up -d --no-deps --wait --wait-timeout 240 sidecar', $events);
        $this->assertStringNotContainsString(' up -d --no-deps --force-recreate --wait --wait-timeout 240 proxy', $events);
        $this->assertStringNotContainsString('artisan migrate', $events);
        $this->assertFileExists($this->directory.'/docker/.deploy.stamp');
        $this->assertStringContainsString('service:sidecar=', (string) file_get_contents($this->directory.'/docker/.deploy.stamp'));
    }

    public function test_checkout_change_during_rollout_records_what_was_deployed_but_never_reports_unchanged(): void
    {
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        $previous = (string) file_get_contents($this->directory.'/docker/.deploy.stamp');
        file_put_contents($this->directory.'/app.txt', "two\n");

        $process = $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_STALE_UP' => '1']);
        $process->run();
        $this->assertSame(76, $process->getExitCode());
        $stamp = (string) file_get_contents($this->directory.'/docker/.deploy.stamp');
        $this->assertSame("stale\n", strstr($stamp, "\n", true)."\n");
        // Services this run rolled out are recorded from the plan it deployed, not the later tree.
        preg_match('/^service:web=(.*)$/m', $stamp, $recorded);
        preg_match('/^service:web=(.*)$/m', (string) file_get_contents($this->directory.'/docker/.deploy.stamp.plan'), $planned);
        preg_match('/^service:web=(.*)$/m', $previous, $before);
        $this->assertSame($planned[1], $recorded[1]);
        $this->assertNotSame($before[1], $recorded[1]);
        $this->assertSame([], glob($this->directory.'/docker/.deploy.stamp.tmp.*') ?: []);

        // Not a first installation: infra is left alone, and the app is redeployed.
        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        $this->assertStringNotContainsString('proxy', $this->events());
        $this->assertStringContainsString(' up -d --no-deps --force-recreate --wait --wait-timeout 240 web horizon scheduler indexer', $this->events());
    }

    public function test_running_image_that_differs_from_the_stamp_is_redeployed(): void
    {
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        file_put_contents($this->directory.'/events', '');
        unlink($this->directory.'/recreated');

        $process = $this->make(['deploy-plan'], ['NNTMUX_CONTAINER_IMAGE_ID' => 'sha256:rolled-out-earlier']);
        $process->mustRun();
        $this->assertMatchesRegularExpression('/web\s+app\s+recreate\s+running image differs from stamp/', $process->getOutput());
    }

    public function test_root_laravel_env_never_reaches_docker_recipes_when_docker_env_is_missing(): void
    {
        file_put_contents($this->directory.'/.env', "WEB_IMAGE=leaked/web:1\nAPP_IMAGE=leaked/app:1\nDEPLOY_MIN_INTERVAL=5\n");
        $this->make(['build'])->mustRun();
        $events = $this->events();
        $this->assertStringContainsString('build --target selfhosted-web -t nntmux/web:local', $events);
        $this->assertStringNotContainsString('leaked', $events);
    }

    public function test_docker_env_supplies_settings_below_arguments_and_environment(): void
    {
        file_put_contents($this->directory.'/docker/.env', "WEB_IMAGE=\"env/web:1\"\nHORIZON_IMAGE=env/horizon:1 # comment\nDC=docker compose ignored\n");
        $this->make(['build'], ['HORIZON_IMAGE' => 'caller/horizon:1'])->mustRun();
        $events = $this->events();
        $this->assertStringContainsString('build --target selfhosted-web -t env/web:1', $events);
        $this->assertStringContainsString('build --target selfhosted-horizon -t caller/horizon:1', $events);
        $this->assertStringNotContainsString('ignored', $events);
    }

    public function test_production_legacy_stamp_reconciles_data_and_infra_without_forcing(): void
    {
        $head = trim((new Process(['git', 'rev-parse', 'HEAD'], $this->directory))->mustRun()->getOutput());
        foreach ([$head, str_repeat('0', 40)] as $revision) {
            file_put_contents($this->directory.'/events', '');
            @unlink($this->directory.'/docker/.deploy.time');
            file_put_contents($this->directory.'/docker/.deploy.stamp', $revision.' e3b0 e3b0 c16c bb93'."\n");

            $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_CONFIG_ID' => $this->composeJson()])->mustRun();
            $events = $this->events();
            $this->assertStringContainsString(' up -d --no-deps --wait --wait-timeout 240 redis', $events, $revision);
            $this->assertStringContainsString(' up -d --no-deps --wait --wait-timeout 240 manticore', $events, $revision);
            $this->assertStringContainsString(' up -d --no-deps --wait --wait-timeout 240 proxy', $events, $revision);
            $this->assertStringNotContainsString('--force-recreate --wait --wait-timeout 240 redis', $events, $revision);
            $this->assertStringNotContainsString('--force-recreate --wait --wait-timeout 240 proxy', $events, $revision);
            $this->assertStringContainsString(' up -d --no-deps --force-recreate --wait --wait-timeout 240 web horizon scheduler indexer', $events, $revision);
            $this->assertStringContainsString('service:web=', (string) file_get_contents($this->directory.'/docker/.deploy.stamp'));
        }
    }

    public function test_root_module_invocation_uses_the_docker_directory_and_never_the_laravel_env(): void
    {
        file_put_contents($this->directory.'/Justfile', "mod docker 'docker/Justfile'\n");
        file_put_contents($this->directory.'/.env', "WEB_IMAGE=leaked/web:1\nDB_ROOTPASSWORD=leaked\n");
        $process = new Process(['just', 'docker', 'build', 'DEPLOY_BY=fixture'], $this->directory, [
            'PATH' => $this->directory.'/bin:'.getenv('PATH'), 'NNTMUX_DEPLOY_EVENTS' => $this->directory.'/events',
            'NNTMUX_REPO' => $this->directory,
        ]);
        $process->mustRun();
        $events = $this->events();
        $this->assertStringContainsString('build --target selfhosted-web -t nntmux/web:local', $events);
        $this->assertStringContainsString('COMPOSE_FILE='.realpath($this->directory).'/docker/docker-compose.yml', $events);
        $this->assertStringNotContainsString('leaked', $events);
        $this->assertFileExists($this->directory.'/docker/.build.stamp');
    }

    public function test_compose_file_from_docker_env_is_used_below_the_environment(): void
    {
        file_put_contents($this->directory.'/docker/.env', "COMPOSE_FILE=/stack/base.yml:/stack/override.yml\n");
        $this->make(['deploy-plan'])->mustRun();
        $this->assertStringContainsString('COMPOSE_FILE=/stack/base.yml:/stack/override.yml ', $this->events());

        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy-plan'], ['COMPOSE_FILE' => '/caller.yml'])->mustRun();
        $this->assertStringContainsString('COMPOSE_FILE=/caller.yml ', $this->events());
        $this->assertStringNotContainsString('override.yml', $this->events());
    }

    public function test_shared_app_extension_block_changes_never_recreate_data_or_infra(): void
    {
        $config = fn (string $value): array => ['NNTMUX_CONFIG_ID' => substr($this->composeJson(), 0, -1).',"x-app":{"environment":{"PULSE_ENABLED":"'.$value.'"}}}'];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config('false'))->mustRun();
        file_put_contents($this->directory.'/events', '');

        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config('true'))->mustRun();
        $events = $this->events();
        $this->assertDoesNotMatchRegularExpression('/ up -d[^\\n]* redis\\b/', $events);
        $this->assertDoesNotMatchRegularExpression('/ up -d[^\\n]* proxy\\b/', $events);
    }

    public function test_fingerprint_change_without_an_affected_service_is_not_logged_as_a_rollout(): void
    {
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        file_put_contents($this->directory.'/docker/.deploy.time', '1000');
        file_put_contents($this->directory.'/events', '');
        file_put_contents($this->directory.'/docker/.env', "DB_ROOTPASSWORD=rotated\n");

        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        $this->assertStringNotContainsString(' up -d', $this->events());
        $this->assertSame('1000', trim((string) file_get_contents($this->directory.'/docker/.deploy.time')));
        $log = (string) file_get_contents($this->directory.'/docker/.deploy.log');
        $this->assertStringContainsString('result=finished rc=0 (no service affected; stamped without a rollout)', $log);
        $this->assertSame([], glob($this->directory.'/docker/.deploy.stamp.noop.*') ?: []);

        // The new fingerprint is stamped, so the next poll skips outright.
        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        $this->assertStringNotContainsString('build --target', $this->events());
    }

    public function test_split_production_layout_hashes_runtime_mounts_and_keeps_history_in_the_runtime(): void
    {
        // m5stephen: a candidate checkout deploys a stack whose Compose project, .env, mounted
        // configuration and deployment history live in a separate persistent runtime directory.
        $runtime = $this->directory.'/runtime';
        file_put_contents($this->directory.'/.gitignore', "/runtime\n", FILE_APPEND);
        (new Process(['git', 'commit', '-q', '-am', 'runtime lives outside the candidate'], $this->directory))->mustRun();
        mkdir($runtime.'/config/redis', 0777, true);
        file_put_contents($runtime.'/.env', "APP_IMAGE=\nWEB_IMAGE=\nREDIS_IMAGE=\n");
        symlink($runtime.'/.env', $this->directory.'/docker/.env');
        file_put_contents($runtime.'/config/redis/redis.conf', "maxmemory 1gb\n");
        $head = trim((new Process(['git', 'rev-parse', 'HEAD'], $this->directory))->mustRun()->getOutput());
        file_put_contents($runtime.'/.deploy.stamp', $head." e3b0 e3b0 c16c bb93\n");
        $redis = ['volumes' => [['type' => 'bind', 'source' => $runtime.'/config/redis/redis.conf', 'target' => '/usr/local/etc/redis/redis.conf', 'read_only' => true]]];
        $json = (string) json_encode(['services' => ['web' => [], 'horizon' => [], 'scheduler' => [], 'indexer' => [], 'redis' => $redis, 'manticore' => [], 'proxy' => []], 'x-app' => ['tmpfs' => ['/tmp']]], JSON_UNESCAPED_SLASHES);
        $environment = [
            'NNTMUX_CONFIG_ID' => $json, 'DEPLOY_REPO' => $this->directory,
            'DEPLOY_LOCK_DIR' => $runtime.'/.deploy.lock', 'DEPLOY_LOG' => $runtime.'/.deploy.log',
            'DEPLOY_STAMP' => $runtime.'/.deploy.stamp', 'DEPLOY_SUCCESS_TIME' => $runtime.'/.deploy.time',
        ];
        $deploy = fn (): Process => $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0', 'DC=docker compose --project-directory '.$runtime], $environment)->mustRun();

        $deploy();
        $events = $this->events();
        $this->assertStringContainsString(' up -d --no-deps --force-recreate --wait --wait-timeout 240 web horizon scheduler indexer', $events);
        $this->assertStringContainsString(' up -d --no-deps --wait --wait-timeout 240 redis', $events);
        $this->assertStringContainsString(' up -d --no-deps --wait --wait-timeout 240 manticore', $events);
        $this->assertStringNotContainsString('--force-recreate --wait --wait-timeout 240 redis', $events);
        $this->assertStringNotContainsString('--force-recreate --wait --wait-timeout 240 proxy', $events);
        $this->assertStringContainsString('compose --project-directory '.$runtime, $events);
        $this->assertStringContainsString('service:redis=', (string) file_get_contents($runtime.'/.deploy.stamp'));
        $this->assertFileExists($runtime.'/.deploy.time');
        $this->assertFileDoesNotExist($this->directory.'/docker/.deploy.stamp');
        $this->assertFileDoesNotExist($this->directory.'/docker/.deploy.log');

        file_put_contents($this->directory.'/events', '');
        $deploy();
        $this->assertStringNotContainsString(' up -d', $this->events());
        $this->assertStringContainsString('result=skipped', (string) file_get_contents($runtime.'/.deploy.log'));

        // The candidate's copy is not what Compose mounts: editing it changes nothing.
        file_put_contents($this->directory.'/events', '');
        mkdir($this->directory.'/docker/config/redis', 0777, true);
        file_put_contents($this->directory.'/docker/config/redis/redis.conf', "maxmemory 2gb\n");
        $deploy();
        $this->assertStringNotContainsString(' up -d', $this->events());

        // The mounted runtime file is what matters.
        file_put_contents($this->directory.'/events', '');
        file_put_contents($runtime.'/config/redis/redis.conf', "maxmemory 2gb\n");
        $deploy();
        $events = $this->events();
        $recreate = ' up -d --no-deps --force-recreate --wait --wait-timeout 240 redis';
        $this->assertStringContainsString($recreate, $events);
        $this->assertLessThan(strpos($events, $recreate), strpos($events, ' stop web scheduler indexer'));
        $this->assertDoesNotMatchRegularExpression('/ up -d[^\\n]* manticore\\b/', $events);

        // A docs-only commit skips.
        file_put_contents($this->directory.'/events', '');
        file_put_contents($this->directory.'/README.md', "# docs\n");
        (new Process(['git', 'add', 'README.md'], $this->directory))->mustRun();
        (new Process(['git', 'commit', '-q', '-m', 'docs'], $this->directory))->mustRun();
        $deploy();
        $this->assertStringNotContainsString(' up -d', $this->events());
        $this->assertStringNotContainsString('build --target', $this->events());
    }

    public function test_unreadable_mounted_configuration_aborts_instead_of_stamping(): void
    {
        mkdir($this->directory.'/docker/config/redis', 0777, true);
        file_put_contents($this->directory.'/docker/config/redis/redis.conf', "maxmemory 1gb\n");
        $config = ['NNTMUX_CONFIG_ID' => $this->composeJson()];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();
        $stamp = (string) file_get_contents($this->directory.'/docker/.deploy.stamp');

        file_put_contents($this->directory.'/docker/config/redis/redis.conf', "maxmemory 2gb\n");
        chmod($this->directory.'/docker/config/redis/redis.conf', 0000);
        try {
            $process = $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config);
            $process->run();
        } finally {
            chmod($this->directory.'/docker/config/redis/redis.conf', 0644);
        }
        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('cannot fingerprint mounted configuration', $process->getErrorOutput());
        $this->assertSame($stamp, (string) file_get_contents($this->directory.'/docker/.deploy.stamp'));
        $this->assertAttemptsClosed();
        $this->assertStringContainsString('result=failed (cannot fingerprint the checkout', (string) file_get_contents($this->directory.'/docker/.deploy.log'));
    }

    public function test_dockerignore_and_copy_rules_match_docker_semantics(): void
    {
        file_put_contents($this->directory.'/.dockerignore', "*.md\nsecret/\napp/**/*.cache\napp/**x*\n!app/keep/x.cache\n/storage\n");
        file_put_contents($this->directory.'/Dockerfile', "FROM scratch\nCOPY app /app/app\nCOPY --from=build /x /y\nCOPY \\\n  docker/app/bin/scheduler.py /s.py\n");
        $process = new Process([$this->directory.'/docker/bin/deploy-plan', '--classify',
            'README.md', 'app/README.md', 'app/a/b.cache', 'app/b.cache', 'app/keep/x.cache', 'app/fooxbar.php', 'app/sub/x.php',
            'app/Http/Kernel.php', 'secret/app.php', 'storage/logs/x', 'routes/web.php', 'docker/app/bin/scheduler.py', 'Dockerfile',
        ], env: ['DEPLOY_REPO' => $this->directory]);
        $process->mustRun();
        $expected = [
            'README.md -', // root-level docs
            'app/README.md shared-app', // nested docs inside a copied tree are image inputs
            'app/a/b.cache -', 'app/b.cache -', // `**/` matches zero or more directories
            'app/keep/x.cache shared-app', // `!` re-includes, last match wins
            'app/fooxbar.php shared-app', 'app/sub/x.php -', // `**x*` is (.*/)?x[^/]*, as in moby
            'app/Http/Kernel.php shared-app', 'secret/app.php -', 'storage/logs/x -',
            'routes/web.php -', // in the context but never copied
            'docker/app/bin/scheduler.py scheduler', 'Dockerfile shared-app',
        ];
        $this->assertSame($expected, explode("\n", trim($process->getOutput())));
    }

    public function test_read_only_directory_mounted_from_outside_the_project_is_content_hashed(): void
    {
        mkdir($this->directory.'/outside/certs', 0777, true);
        file_put_contents($this->directory.'/outside/certs/ca.pem', "one\n");
        $proxy = ['volumes' => [['type' => 'bind', 'source' => $this->directory.'/outside/certs', 'target' => '/certs', 'read_only' => true]]];
        $config = ['NNTMUX_CONFIG_ID' => (string) json_encode(['services' => ['web' => [], 'horizon' => [], 'scheduler' => [], 'indexer' => [], 'proxy' => $proxy]], JSON_UNESCAPED_SLASHES)];
        file_put_contents($this->directory.'/.gitignore', "/outside\n", FILE_APPEND);
        (new Process(['git', 'commit', '-q', '-am', 'outside mounts'], $this->directory))->mustRun();
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();
        file_put_contents($this->directory.'/events', '');

        file_put_contents($this->directory.'/outside/certs/ca.pem', "two\n");
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();
        $this->assertStringContainsString(' up -d --no-deps --force-recreate --wait --wait-timeout 240 proxy', $this->events());
    }

    public function test_compose_wrapper_parses_docker_env_like_compose(): void
    {
        file_put_contents($this->directory.'/docker/.env', "APP_IMAGE=\"registry/app:1\" # pinned\nexport HORIZON_IMAGE='registry/horizon:2'\n");
        $process = new Process([$this->directory.'/docker/bin/compose'], env: ['DC' => 'env', 'PATH' => getenv('PATH')]);
        $process->mustRun();
        $this->assertStringContainsString("\nWEB_IMAGE=registry/app:1\n", "\n".$process->getOutput());
        // An explicit role tag is left for Compose to read from .env, never replaced by APP_IMAGE.
        $this->assertStringNotContainsString("\nHORIZON_IMAGE=", "\n".$process->getOutput());
    }

    public function test_external_image_replacement_or_tag_change_invalidates_build_stamp(): void
    {
        $this->make(['build'], ['NNTMUX_IMAGE_ID' => 'sha256:first-build'])->mustRun();
        file_put_contents($this->directory.'/events', '');

        $this->make(['build'], ['NNTMUX_IMAGE_ID' => 'sha256:replaced-externally'])->mustRun();
        $this->assertStringContainsString('build --target selfhosted-web', $this->events());
    }

    public function test_legacy_stamp_recreates_only_services_whose_mounted_config_is_newer_than_their_container(): void
    {
        $head = trim((new Process(['git', 'rev-parse', 'HEAD'], $this->directory))->mustRun()->getOutput());
        mkdir($this->directory.'/docker/config/caddy', 0777, true);
        file_put_contents($this->directory.'/docker/config/caddy/Caddyfile', ":8080\n");
        $config = ['NNTMUX_CONFIG_ID' => $this->composeJson()];

        // Containers started after the edit already run it: reconcile only.
        file_put_contents($this->directory.'/docker/.deploy.stamp', $head." e3b0 e3b0 c16c bb93\n");
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();
        $this->assertStringNotContainsString('--force-recreate --wait --wait-timeout 240 proxy', $this->events());
        $this->assertStringContainsString(' up -d --no-deps --wait --wait-timeout 240 proxy', $this->events());

        // A container that started before the edit never loaded it, so the stamp must not claim it.
        file_put_contents($this->directory.'/events', '');
        @unlink($this->directory.'/docker/.deploy.time');
        file_put_contents($this->directory.'/docker/.deploy.stamp', $head." e3b0 e3b0 c16c bb93\n");
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config + ['NNTMUX_STARTED_AT' => '2000-01-01T00:00:00Z'])->mustRun();
        $events = $this->events();
        $this->assertStringContainsString(' up -d --no-deps --force-recreate --wait --wait-timeout 240 proxy', $events);
        $this->assertStringNotContainsString('--force-recreate --wait --wait-timeout 240 redis', $events);
    }

    public function test_failing_infra_service_aborts_before_any_writer_is_drained(): void
    {
        $head = trim((new Process(['git', 'rev-parse', 'HEAD'], $this->directory))->mustRun()->getOutput());
        file_put_contents($this->directory.'/docker/.deploy.stamp', $head." e3b0 e3b0 c16c bb93\n");
        $process = $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_CONFIG_ID' => $this->composeJson(), 'NNTMUX_PROXY_FAIL' => '1']);
        $process->run();
        $this->assertSame(51, $process->getExitCode());
        $events = $this->events();
        $this->assertStringContainsString(' up -d --no-deps --wait --wait-timeout 240 proxy', $events);
        $this->assertStringNotContainsString('drain', $events);
        $this->assertStringNotContainsString(' stop ', $events);
        $this->assertStringNotContainsString('artisan migrate', $events);
        $this->assertStringNotContainsString('--force-recreate --wait --wait-timeout 240 web', $events);
    }

    public function test_real_repository_paths_reach_only_the_roles_whose_images_copy_them(): void
    {
        $paths = [
            'routes/web.php' => 'shared-app', 'app/Http/Kernel.php' => 'shared-app', 'resources/views/layouts/main.blade.php' => 'shared-app',
            'resources/js/app.js' => 'web', 'resources/css/app.css' => 'web', 'package.json' => 'web', 'docker/app/Caddyfile' => 'web',
            'docker/app/bin/scheduler.py' => 'scheduler', 'docker/app/bin/indexer.sh' => 'indexer', 'docker/app/php/yenc-ffi.ini' => 'indexer',
            'docker/8.5/php.ini' => 'shared-app', 'tests/Fixtures/nzb-article-fingerprint/NzbArticleFingerprint.php' => 'shared-app',
            'deploy/cloud/entrypoint.sh' => 'shared-app', 'database/migrations/x.php' => 'migrations,shared-app',
            'deploy/cloud/compose.yaml' => '-', 'docs/x.md' => '-', 'README.md' => '-', 'tests/Unit/XTest.php' => '-',
            'scripts/install-monitoring.sh' => '-', '.env.example' => '-', 'docker/docker-compose.yml' => '-', 'docker/config/caddy/Caddyfile' => '-',
        ];
        $root = dirname(__DIR__, 2);
        $process = new Process([$root.'/docker/bin/deploy-plan', '--classify', ...array_keys($paths)], env: ['DEPLOY_REPO' => $root, 'PYTHONDONTWRITEBYTECODE' => '1']);
        $process->mustRun();
        $expected = array_map(fn (string $path, string $owner): string => $path.' '.$owner, array_keys($paths), $paths);
        $this->assertSame($expected, explode("\n", trim($process->getOutput())));
    }

    /** Rendered Compose JSON whose proxy and redis bind-mount repository configuration files. */
    private function composeJson(): string
    {
        $bind = fn (string $source, string $target): array => ['type' => 'bind', 'source' => $this->directory.'/docker/'.$source, 'target' => $target, 'read_only' => true];
        $services = ['web' => [], 'horizon' => [], 'scheduler' => [], 'indexer' => [], 'manticore' => []];
        $services['proxy'] = ['volumes' => [$bind('config/caddy/Caddyfile', '/etc/caddy/Caddyfile')]];
        $services['redis'] = ['volumes' => [$bind('config/redis/redis.conf', '/usr/local/etc/redis/redis.conf')]];

        return (string) json_encode(['services' => $services], JSON_UNESCAPED_SLASHES);
    }

    /** Every `started` line in .deploy.log is closed by a `finished` line for the same target. */
    private function assertAttemptsClosed(): void
    {
        $open = [];
        foreach (file($this->directory.'/docker/.deploy.log', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^\S+ (\S+) by=.* result=(started|finished)/', $line, $match)) {
                $open[$match[1]] = ($open[$match[1]] ?? 0) + ($match[2] === 'started' ? 1 : -1);
            }
        }
        $this->assertSame([], array_filter($open), 'unmatched started/finished lines in .deploy.log');
    }

    /** A failed run may record services it recreated, but never a valid fingerprint. */
    private function assertNotStamped(): void
    {
        $stamp = $this->directory.'/docker/.deploy.stamp';
        if (file_exists($stamp)) {
            $this->assertSame('stale', strtok((string) file_get_contents($stamp), "\n"));
        } else {
            $this->addToAssertionCount(1);
        }
    }

    private function events(): string
    {
        return (string) file_get_contents($this->directory.'/events');
    }

    /** @param array<string, string> $environment */
    private function deploy(array $environment = []): Process
    {
        return $this->make(['deploy'], $environment);
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $environment
     */
    private function make(array $arguments, array $environment = []): Process
    {
        return new Process([
            'just', '--justfile', $this->directory.'/docker/Justfile',
            '--working-directory', $this->directory.'/docker',
            ...$arguments, 'DEPLOY_BY=fixture',
        ], env: $environment + [
            'PATH' => $this->directory.'/bin:'.getenv('PATH'), 'NNTMUX_DEPLOY_EVENTS' => $this->directory.'/events',
            'NNTMUX_REPO' => $this->directory,
        ]);
    }
}
