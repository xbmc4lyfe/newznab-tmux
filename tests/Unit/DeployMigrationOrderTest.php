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
        copy(__DIR__.'/../../docker/Makefile', $this->directory.'/docker/Makefile');
        foreach (['deploy-guard', 'deploy-app', 'deploy-current', 'up-app', 'compose', 'legacy-drain.php', 'legacy-scheduler-drain.py'] as $script) {
            copy(__DIR__.'/../../docker/bin/'.$script, $this->directory.'/docker/bin/'.$script);
            chmod($this->directory.'/docker/bin/'.$script, 0755);
        }
        file_put_contents($this->directory.'/.gitignore', "/docker/.build.stamp\n/docker/.deploy.stamp\n/docker/.deploy.time\n/docker/.deploy.log\n/docker/.deploy.lock*\n/events\n/recreated\n");
        file_put_contents($this->directory.'/app.txt', "one\n");
        file_put_contents($this->directory.'/bin/docker', <<<'SH'
#!/bin/sh
printf 'COMPOSE_FILE=%s %s\n' "$COMPOSE_FILE" "$*" >> "$NNTMUX_DEPLOY_EVENTS"
case "$*" in
    *'releases:verification-barrier'*)
        [ "${NNTMUX_BARRIER_FAIL:-0}" != 1 ] || { echo 'Verification workers are still running on a host.'; exit 51; }
        echo 'barrier held'
        cat > /dev/null
        echo released >> "$NNTMUX_DEPLOY_EVENTS" ;;
    *' config --format json'*) echo "${NNTMUX_CONFIG_ID:-fixture-config}" ;;
    *'image inspect --format'*) echo "${NNTMUX_IMAGE_ID:-sha256:fixture}" ;;
    *'image inspect'*) exit 0 ;;
    *'inspect --format {{.Image}}'*)
        if [ -f "$NNTMUX_REPO/recreated" ]; then
            echo "${NNTMUX_IMAGE_ID:-sha256:fixture}"
        else
            echo "${NNTMUX_CONTAINER_IMAGE_ID:-${NNTMUX_IMAGE_ID:-sha256:fixture}}"
        fi ;;
    *'inspect --format {{.State.Status}}'*) echo "running ${NNTMUX_HEALTH_STATUS:-healthy}" ;;
    *' ps -a -q '*) [ "${NNTMUX_NO_EXISTING_APP:-0}" = 1 ] || echo fixture-container ;;
    *' ps -q horizon'*) [ "${NNTMUX_HORIZON_PS_FAIL:-0}" != 1 ] || exit 50; [ "${NNTMUX_SERVICE_MISSING:-0}" = 1 ] || echo fixture-container ;;
    *' ps -q '*) [ "${NNTMUX_SERVICE_MISSING:-0}" = 1 ] || echo fixture-container ;;
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
        $this->assertFileDoesNotExist($this->directory.'/docker/.deploy.stamp');
    }

    public function test_a_failed_migration_releases_the_remote_worker_barrier(): void
    {
        $process = $this->deploy(['NNTMUX_MIGRATION_FAIL' => '1']);
        $process->run();

        $this->assertFalse($process->isSuccessful());
        $events = $this->events();
        $this->assertGreaterThan(strpos($events, 'artisan migrate'), strpos($events, "released\n"));
        $this->assertStringNotContainsString(' up -d', $events);
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
        $this->assertStringContainsString('artisan migrate', $this->events());
        $this->assertStringContainsString(' up -d --no-deps --force-recreate --wait', $this->events());
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
        $this->assertFileDoesNotExist($this->directory.'/docker/.deploy.stamp');
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
        $this->assertStringNotContainsString(' up -d', $this->events());
        $this->assertFileDoesNotExist($this->directory.'/docker/.deploy.stamp');
        $this->assertFileDoesNotExist($this->directory.'/docker/.deploy.time');
    }

    public function test_failed_health_check_never_marks_the_new_runtime_as_deployed(): void
    {
        $process = $this->deploy(['NNTMUX_HEALTH_FAIL' => '1']);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertFileExists($this->directory.'/docker/.build.stamp');
        $this->assertFileDoesNotExist($this->directory.'/docker/.deploy.stamp');
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
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_CONFIG_ID' => 'changed-config'])->mustRun();
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
        $this->assertStringNotContainsString(' up -d', $this->events());
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
        $this->assertFileDoesNotExist($this->directory.'/docker/.deploy.stamp');
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

    public function test_checkout_changes_while_stopping_writers_abort_before_migration(): void
    {
        $process = $this->deploy(['NNTMUX_STALE_STOP' => '1']);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString(' stop web scheduler indexer', $this->events());
        $this->assertStringNotContainsString('artisan migrate', $this->events());
        $this->assertStringNotContainsString(' up -d', $this->events());
        $this->assertStringNotContainsString('tmux:drain --resume', $this->events());
        $this->assertFileDoesNotExist($this->directory.'/docker/.deploy.stamp');
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
        return new Process(['make', '-s', '-C', $this->directory.'/docker', ...$arguments, 'DEPLOY_BY=fixture'], env: $environment + [
            'PATH' => $this->directory.'/bin:'.getenv('PATH'), 'NNTMUX_DEPLOY_EVENTS' => $this->directory.'/events',
            'NNTMUX_REPO' => $this->directory,
        ]);
    }
}
