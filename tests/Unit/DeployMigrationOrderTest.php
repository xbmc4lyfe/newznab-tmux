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
        foreach (['deploy-guard', 'deploy-app', 'deploy-current', 'up-app', 'compose', 'legacy-drain.php'] as $script) {
            copy(__DIR__.'/../../docker/bin/'.$script, $this->directory.'/docker/bin/'.$script);
            chmod($this->directory.'/docker/bin/'.$script, 0755);
        }
        file_put_contents($this->directory.'/.gitignore', "/docker/.build.stamp\n/docker/.deploy.stamp\n/docker/.deploy.time\n/docker/.deploy.log\n/docker/.deploy.lock*\n/events\n");
        file_put_contents($this->directory.'/app.txt', "one\n");
        file_put_contents($this->directory.'/bin/docker', <<<'SH'
#!/bin/sh
printf 'COMPOSE_FILE=%s %s\n' "$COMPOSE_FILE" "$*" >> "$NNTMUX_DEPLOY_EVENTS"
case "$*" in
    *' config --format json'*) echo "${NNTMUX_CONFIG_ID:-fixture-config}" ;;
    *'image inspect --format'*) echo sha256:fixture ;;
    *'image inspect'*) exit 0 ;;
    *'inspect --format {{.Image}}'*) echo sha256:fixture ;;
    *'inspect --format {{.State.Status}}'*) echo "running ${NNTMUX_HEALTH_STATUS:-healthy}" ;;
    *' ps -a -q '*) [ "${NNTMUX_NO_EXISTING_APP:-0}" = 1 ] || echo fixture-container ;;
    *' ps -q '*) [ "${NNTMUX_SERVICE_MISSING:-0}" = 1 ] || echo fixture-container ;;
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
    *' up -d'*) [ "${NNTMUX_HEALTH_FAIL:-0}" != 1 ] || exit 44 ;;
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
        $steps = ['build --target selfhosted', 'artisan tmux:drain --timeout=90', ' stop --timeout 1920 horizon', ' stop web scheduler indexer', 'artisan migrate --force --no-interaction', ' up -d --no-deps --wait --wait-timeout 240 web horizon scheduler indexer'];
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
        $this->assertStringContainsString(' up -d --no-deps --wait', $this->events());
    }

    public function test_failed_drain_leaves_writers_running_and_never_migrates_or_stamps(): void
    {
        $process = $this->deploy(['NNTMUX_DRAIN_FAIL' => '1']);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringNotContainsString(' stop ', $this->events());
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
        $this->assertLessThan(strpos($events, ' stop --timeout'), strpos($events, 'php -- /app drain 1800'));
        $this->assertStringContainsString('artisan migrate', $events);
    }

    public function test_failed_legacy_transition_never_stops_or_migrates(): void
    {
        $process = $this->deploy(['NNTMUX_LEGACY_IMAGE' => '1', 'DEPLOY_LEGACY_DRAIN' => '1', 'NNTMUX_DRAIN_FAIL' => '1']);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringNotContainsString(' stop ', $this->events());
        $this->assertStringNotContainsString('artisan migrate', $this->events());
    }

    public function test_stale_legacy_transition_restores_using_the_old_runtime(): void
    {
        $process = $this->deploy(['NNTMUX_LEGACY_IMAGE' => '1', 'DEPLOY_LEGACY_DRAIN' => '1', 'NNTMUX_STALE_DRAIN' => '1']);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('php -- /app resume', $this->events());
        $this->assertStringNotContainsString(' stop ', $this->events());
    }

    public function test_stale_checkout_after_drain_resumes_the_old_indexer_without_stopping_writers(): void
    {
        $process = $this->deploy(['NNTMUX_STALE_DRAIN' => '1']);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('artisan tmux:drain --resume', $this->events());
        $this->assertStringNotContainsString(' stop ', $this->events());
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
        $this->assertStringContainsString('artisan tmux:drain --timeout=90', $this->events());
        $this->assertStringContainsString('artisan migrate --force --no-interaction', $this->events());
        $this->assertStringContainsString(' up -d --no-deps --wait', $this->events());
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
        $this->assertStringContainsString('artisan tmux:drain --timeout=90', $this->events());
        $this->assertStringContainsString(' up -d --no-deps --wait', $this->events());
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

    public function test_failed_horizon_shutdown_preserves_web_and_resumes_the_indexer_before_any_migration(): void
    {
        $process = $this->deploy(['NNTMUX_HORIZON_STOP_FAIL' => '1']);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString(' stop --timeout 1920 horizon', $this->events());
        $this->assertStringContainsString('artisan tmux:drain --resume', $this->events());
        $this->assertStringNotContainsString(' stop web scheduler indexer', $this->events());
        $this->assertStringNotContainsString('artisan migrate', $this->events());
        $this->assertFileDoesNotExist($this->directory.'/docker/.deploy.stamp');
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
