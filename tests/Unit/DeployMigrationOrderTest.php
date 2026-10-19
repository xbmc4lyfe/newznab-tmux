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
        file_put_contents($this->directory.'/.gitignore', "/docker/.build.stamp\n/docker/.deploy.stamp\n/docker/.deploy.time\n/docker/.deploy.log\n/docker/.deploy.lock*\n/events\n/recreated*\n/replaced\n/barrier.log\n/barrier.running\n");
        // Docker ignores .gitignore: the fixture's own state must also stay out of the build context.
        file_put_contents($this->directory.'/.dockerignore', "events\nrecreated*\nreplaced\nbarrier.log\nbarrier.running\n");
        file_put_contents($this->directory.'/app.txt', "one\n");
        file_put_contents($this->directory.'/bin/docker', <<<'SH'
#!/bin/sh
printf 'COMPOSE_FILE=%s %s\n' "$COMPOSE_FILE" "$*" >> "$NNTMUX_DEPLOY_EVENTS"
for last in "$@"; do :; done
# Missing app containers stay missing until this run recreates them.
missing=0
if [ "${NNTMUX_SERVICE_MISSING:-0}" = 1 ] && ! grep -q -- ' up -d --no-deps --force-recreate' "$NNTMUX_DEPLOY_EVENTS"; then missing=1; fi
default_config='{"services":{"web":{},"horizon":{},"scheduler":{},"indexer":{},"mariadb":{},"redis":{},"manticore":{},"proxy":{},"mailpit":{},"prometheus":{},"pushgateway":{},"grafana":{},"node-exporter":{},"mysqld-exporter":{},"redis-exporter":{}}}'
case "$*" in
    *'releases:verification-barrier --release'*) echo forced-release >> "$NNTMUX_DEPLOY_EVENTS"; [ "${NNTMUX_FORCED_RELEASE_FAIL:-0}" != 1 ] || exit 52 ;;
    *'releases:verification-barrier'*)
        if [ "${NNTMUX_BARRIER_FAIL:-0}" = 1 ] || { [ -n "${NNTMUX_BARRIER_FAIL_AFTER:-}" ] && grep -q -- "$NNTMUX_BARRIER_FAIL_AFTER" "$NNTMUX_DEPLOY_EVENTS"; }; then echo 'still running' > "$NNTMUX_REPO/barrier.log"; else echo 'barrier held' > "$NNTMUX_REPO/barrier.log"; : > "$NNTMUX_REPO/barrier.running"; fi
        if [ "${NNTMUX_BARRIER_LOCK_MARKER:-0}" = 1 ]; then chmod 444 "$NNTMUX_REPO/docker/.deploy.stamp.barrier-owed"; echo two > "$NNTMUX_REPO/app.txt"; fi
        echo barrier-container ;;
    'inspect --format {{.State.Running}} '*'-verification-barrier')
        if [ -n "${NNTMUX_BARRIER_EXIT_ON:-}" ] && grep -q -- "$NNTMUX_BARRIER_EXIT_ON" "$NNTMUX_DEPLOY_EVENTS"; then rm -f "$NNTMUX_REPO/barrier.running"; echo 'barrier lost' >> "$NNTMUX_REPO/barrier.log"; fi
        [ -f "$NNTMUX_REPO/barrier.running" ] && echo 'true nntmux' || echo 'false nntmux' ;;
    'logs '*'-verification-barrier')
        cat "$NNTMUX_REPO/barrier.log" 2>/dev/null
        if [ "${NNTMUX_BARRIER_LOST:-}" != '' ] && grep -q "${NNTMUX_BARRIER_LOST}" "$NNTMUX_DEPLOY_EVENTS"; then echo 'barrier lost'; fi ;;
    'stop --timeout 30 '*'-verification-barrier')
        rm -f "$NNTMUX_REPO/barrier.running"; echo released >> "$NNTMUX_DEPLOY_EVENTS"
        [ "${NNTMUX_BARRIER_UNCLEAN:-0}" = 1 ] || echo 'barrier released' >> "$NNTMUX_REPO/barrier.log" ;;
    'rm -f '*'-verification-barrier') rm -f "$NNTMUX_REPO/barrier.running" ;;
    *' config --hash '*) echo "$last ${NNTMUX_CONFIG_HASH:-fixture-hash}" ;;
    *'com.docker.compose.config-hash'*) echo "${NNTMUX_CONTAINER_CONFIG_HASH:-${NNTMUX_CONFIG_HASH:-fixture-hash}}" ;;
    *'com.docker.compose.service'*)
        shift 3
        for container in "$@"; do
            svc=${container##*fixture-container-}
            if [ "$svc" = "${NNTMUX_STALE_SERVICE_IMAGE:-}" ] && [ ! -f "$NNTMUX_REPO/recreated-$svc" ]; then id=sha256:stale; else id=${NNTMUX_IMAGE_ID:-sha256:fixture}; fi
            echo "$svc $id fixture/$svc:tag"
        done ;;
    *' config --format json'*) printf '%s\n' "${NNTMUX_CONFIG_ID:-$default_config}" | sed 's/^{/{"name":"nntmux",/' ;;
    *'image inspect --format'*)
        # After the rollout, while the guard writes the stamp.
        if [ "${NNTMUX_INSPECT_FAIL_AFTER_UP:-0}" = 1 ] && [ -f "$NNTMUX_REPO/recreated" ] && ls "$NNTMUX_REPO"/docker/.deploy.stamp.tmp.* >/dev/null 2>&1; then echo "Error response from daemon: timeout" >&2; exit 1; fi
        [ "${NNTMUX_IMAGE_INSPECT_FAIL:-0}" != 1 ] || { echo "Error response from daemon: request timed out" >&2; exit 1; }
        if [ -f "$NNTMUX_REPO/replaced" ]; then echo sha256:replaced
        elif [ "${NNTMUX_REPLACE_AT_STAMP:-0}" = 1 ] && ls "$NNTMUX_REPO"/docker/.deploy.stamp.tmp.* >/dev/null 2>&1; then echo sha256:replaced
        elif [ "$last" = nntmux/base:local ] && [ -n "${NNTMUX_BASE_IMAGE_ID:-}" ]; then echo "$NNTMUX_BASE_IMAGE_ID"
        else echo "${NNTMUX_IMAGE_ID:-sha256:fixture}"; fi ;;
    *'image inspect'*) exit 0 ;;
    *'inspect --format {{.Image}}'*)
        if [ -f "$NNTMUX_REPO/recreated" ]; then
            echo "${NNTMUX_IMAGE_ID:-sha256:fixture}"
        else
            echo "${NNTMUX_CONTAINER_IMAGE_ID:-${NNTMUX_IMAGE_ID:-sha256:fixture}}"
        fi ;;
    *'inspect --format {{.State.StartedAt}}'*) echo "${NNTMUX_STARTED_AT:-2999-01-01T00:00:00.123456789Z}" ;;
    *'inspect --format {{.State.Status}}'*)
        # A tag replaced in place once planning starts inspecting containers (after the build).
        [ "${NNTMUX_REPLACE_TAG_ON_STATUS:-0}" != 1 ] || : > "$NNTMUX_REPO/replaced"
        late=none
        if [ -n "${NNTMUX_UNHEALTHY_AFTER:-}" ] && grep -q -- "$NNTMUX_UNHEALTHY_AFTER" "$NNTMUX_DEPLOY_EVENTS"; then late=${NNTMUX_UNHEALTHY_LATE:-none}; fi
        case "$*" in *"fixture-container-${NNTMUX_UNHEALTHY:-none}"|*"fixture-container-$late") echo "running unhealthy" ;; *) echo "running ${NNTMUX_HEALTH_STATUS:-healthy}" ;; esac ;;
    *' ps -a -q '*)
        if [ -n "${NNTMUX_PS_A_FAIL_AFTER:-}" ] && grep -q -- "$NNTMUX_PS_A_FAIL_AFTER" "$NNTMUX_DEPLOY_EVENTS"; then echo "Cannot connect to the Docker daemon" >&2; exit 1; fi
        [ "${NNTMUX_NO_EXISTING_APP:-0}" = 1 ] && exit 0
        if [ -n "${NNTMUX_NO_CONTAINER:-}" ] && [ "$last" = "$NNTMUX_NO_CONTAINER" ] && ! grep -q -- " up -d .* $last\( \|\$\)" "$NNTMUX_DEPLOY_EVENTS"; then exit 0; fi
        [ ! -f "$NNTMUX_REPO/recreated-$last" ] || printf 'r%s-' "$(wc -c < "$NNTMUX_REPO/recreated-$last" | tr -d ' ')"
        echo "fixture-container-$last" ;;
    *' ps -q')
        [ "${NNTMUX_PS_FAIL:-0}" != 1 ] || { echo "Cannot connect to the Docker daemon" >&2; exit 1; }
        [ "$missing" = 1 ] || printf '%s\n' "${NNTMUX_CONFIG_ID:-$default_config}" \
            | python3 -c 'import json, sys; print("\n".join("fixture-container-" + s for s in json.load(sys.stdin)["services"]))' 2>/dev/null
        [ -z "${NNTMUX_ORPHAN:-}" ] || echo "fixture-container-$NNTMUX_ORPHAN" ;;
    *' ps -q horizon'*)
        [ "${NNTMUX_HORIZON_PS_FAIL:-0}" != 1 ] || exit 50
        [ "$missing" = 1 ] || { [ ! -f "$NNTMUX_REPO/recreated-horizon" ] || printf 'r%s-' "$(wc -c < "$NNTMUX_REPO/recreated-horizon" | tr -d ' ')"; echo "fixture-container-horizon"; } ;;
    *' ps -q '*)
        [ "${NNTMUX_PS_FAIL:-0}" != 1 ] || { echo "Cannot connect to the Docker daemon" >&2; exit 1; }
        # A recreated container gets a new ID, as Docker gives it.
        # NNTMUX_NO_CONTAINER: missing until this run (re)creates it.
        if [ -n "${NNTMUX_NO_CONTAINER:-}" ] && [ "$last" = "$NNTMUX_NO_CONTAINER" ] && ! grep -q -- " up -d .* $last\( \|\$\)" "$NNTMUX_DEPLOY_EVENTS"; then exit 0; fi
        [ "$missing" = 1 ] || { [ ! -f "$NNTMUX_REPO/recreated-$last" ] || printf 'r%s-' "$(wc -c < "$NNTMUX_REPO/recreated-$last" | tr -d ' ')"; echo "fixture-container-$last"; } ;;
    *'scheduler python3 -c '*) [ "${NNTMUX_SCHEDULER_CAPABILITY_FAIL:-0}" != 1 ] || exit 48; echo "${NNTMUX_SCHEDULER_CAPABILITY:-native}" ;;
    *'scheduler.py drain --timeout='*)
        [ "${NNTMUX_SCHEDULER_DRAIN_FAIL:-0}" != 1 ] || exit 47
        if [ "${NNTMUX_STALE_SCHEDULER_DRAIN:-0}" = 1 ]; then echo two > "$NNTMUX_REPO/app.txt"; fi
        if [ "${NNTMUX_REPLACE_DURING_DRAIN:-0}" = 1 ]; then : > "$NNTMUX_REPO/replaced"; fi ;;
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
        case "$*" in *'--wait-timeout 240 web'*)
            if [ "${NNTMUX_APP_UP_FAIL:-0}" = 1 ]; then
                # Compose replaced the containers before the health wait failed, unless it failed first.
                if [ "${NNTMUX_APP_UP_NO_REPLACE:-0}" != 1 ]; then
                    for svc in "$@"; do case "$svc" in web|horizon|scheduler|indexer) printf x >> "$NNTMUX_REPO/recreated-$svc" ;; esac; done
                fi
                exit 52
            fi ;; esac
        case "$*" in *'--force-recreate'*' redis'*) [ "${NNTMUX_REDIS_FAIL:-0}" != 1 ] || exit 53 ;; esac
        [ -z "${NNTMUX_UP_FAIL_SERVICE:-}" ] || [ "$last" != "$NNTMUX_UP_FAIL_SERVICE" ] || exit 54
        if [ "${NNTMUX_STALE_UP:-0}" = 1 ]; then echo changed > "$NNTMUX_REPO/app.txt"; fi
        if [ -n "${NNTMUX_STALE_AFTER_UP_OF:-}" ] && [ "$last" = "$NNTMUX_STALE_AFTER_UP_OF" ]; then echo changed > "$NNTMUX_REPO/app.txt"; fi
        case "$*" in *' --force-recreate '*)
            : > "$NNTMUX_REPO/recreated"
            # Every service named after the options gets a new container, not only the last one.
            for svc in "$@"; do case "$svc" in -*|*/*|compose|up|[0-9]*) ;; *) printf x >> "$NNTMUX_REPO/recreated-$svc" ;; esac; done ;; esac ;;
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

    public function test_a_no_op_barrier_recovery_verifies_the_stack_before_releasing(): void
    {
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        file_put_contents($this->directory.'/docker/.deploy.stamp.barrier-owed', '');
        file_put_contents($this->directory.'/docker/.env', "DB_ROOTPASSWORD=rotated\n");
        file_put_contents($this->directory.'/events', '');

        // Horizon turns unhealthy while the barrier drains remote workers.
        $process = $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_UNHEALTHY_AFTER' => 'releases:verification-barrier --timeout', 'NNTMUX_UNHEALTHY_LATE' => 'horizon']);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('keeping remote verification workers held', $process->getErrorOutput());
        $this->assertStringNotContainsString('released', $this->events());
        $this->assertFileExists($this->directory.'/docker/.deploy.stamp.barrier-owed');
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

    public function test_a_recreated_database_is_fenced_by_a_fresh_barrier_at_once(): void
    {
        $services = json_decode($this->composeJson(), true);
        $services['services']['mariadb'] = ['image' => 'mariadb:one'];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_CONFIG_ID' => (string) json_encode($services, JSON_UNESCAPED_SLASHES)])->mustRun();
        file_put_contents($this->directory.'/events', '');

        $services['services']['mariadb'] = ['image' => 'mariadb:two'];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_CONFIG_ID' => (string) json_encode($services, JSON_UNESCAPED_SLASHES)])->mustRun();
        $events = $this->events();
        $recreate = strpos($events, ' up -d --no-deps --force-recreate --wait --wait-timeout 240 mariadb');
        $this->assertNotFalse($recreate);
        $this->assertSame(2, substr_count($events, 'releases:verification-barrier --timeout=900'));
        $this->assertGreaterThan($recreate, strrpos($events, 'releases:verification-barrier --timeout=900'));
        $this->assertGreaterThan(strrpos($events, 'releases:verification-barrier --timeout=900'), strpos($events, 'artisan migrate'));
        $this->assertStringContainsString('released', $events);
    }

    public function test_a_failure_after_replacing_the_database_keeps_remote_workers_out(): void
    {
        mkdir($this->directory.'/docker/config/redis', 0777, true);
        file_put_contents($this->directory.'/docker/config/redis/redis.conf', "maxmemory 1gb\n");
        $services = json_decode($this->composeJson(), true);
        $services['services']['mariadb'] = ['image' => 'mariadb:one'];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_CONFIG_ID' => (string) json_encode($services, JSON_UNESCAPED_SLASHES)])->mustRun();
        file_put_contents($this->directory.'/events', '');

        // The database is replaced, then a later data service fails before the migration.
        $services['services']['mariadb'] = ['image' => 'mariadb:two'];
        file_put_contents($this->directory.'/docker/config/redis/redis.conf', "maxmemory 2gb\n");
        $process = $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_CONFIG_ID' => (string) json_encode($services, JSON_UNESCAPED_SLASHES), 'NNTMUX_UP_FAIL_SERVICE' => 'redis']);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $events = $this->events();
        $this->assertStringNotContainsString('artisan migrate', $events);
        $this->assertStringNotContainsString('released', $events);
        $this->assertStringNotContainsString('forced-release', $events);
        $this->assertFileExists($this->directory.'/docker/.deploy.stamp.barrier-owed');
    }

    public function test_reconciling_an_unrecorded_database_migrates(): void
    {
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        $stamp = (string) file_get_contents($this->directory.'/docker/.deploy.stamp');
        // A lost record (and a stamp left unfinished) makes the next run reconcile it.
        file_put_contents($this->directory.'/docker/.deploy.stamp', preg_replace(['/^[^\n]*\n/', '/^service:mariadb=.*\n/m'], ["stale\n", ''], $stamp, 1));
        file_put_contents($this->directory.'/events', '');

        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        $events = $this->events();
        $this->assertStringContainsString('--wait-timeout 240 mariadb', $events);
        $this->assertGreaterThan(strpos($events, '--wait-timeout 240 mariadb'), strpos($events, 'artisan migrate --force'));
    }

    public function test_a_check_failing_right_after_the_database_is_replaced_keeps_remote_workers_out(): void
    {
        $services = json_decode($this->composeJson(), true);
        $services['services']['mariadb'] = ['image' => 'mariadb:one'];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_CONFIG_ID' => (string) json_encode($services, JSON_UNESCAPED_SLASHES)])->mustRun();
        file_put_contents($this->directory.'/events', '');

        $services['services']['mariadb'] = ['image' => 'mariadb:two'];
        $process = $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_CONFIG_ID' => (string) json_encode($services, JSON_UNESCAPED_SLASHES), 'NNTMUX_STALE_AFTER_UP_OF' => 'mariadb']);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $events = $this->events();
        $this->assertStringNotContainsString('artisan migrate', $events);
        $this->assertStringNotContainsString('released', $events);
        $this->assertStringNotContainsString('forced-release', $events);
    }

    public function test_another_data_service_keeps_the_existing_barrier(): void
    {
        mkdir($this->directory.'/docker/config/redis', 0777, true);
        file_put_contents($this->directory.'/docker/config/redis/redis.conf', "maxmemory 1gb\n");
        $config = ['NNTMUX_CONFIG_ID' => $this->composeJson()];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();
        file_put_contents($this->directory.'/events', '');

        file_put_contents($this->directory.'/docker/config/redis/redis.conf', "maxmemory 2gb\n");
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();
        $this->assertSame(1, substr_count($this->events(), 'releases:verification-barrier --timeout=900'));
    }

    public function test_a_failed_migration_never_releases_the_pause_of_a_barrier_that_exited(): void
    {
        $process = $this->deploy(['NNTMUX_MIGRATION_FAIL' => '1', 'NNTMUX_BARRIER_EXIT_ON' => 'artisan migrate', 'NNTMUX_BARRIER_UNCLEAN' => '1']);
        $process->run();

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('stay held', $process->getErrorOutput());
        $this->assertStringNotContainsString('forced-release', $this->events());
        $this->assertStringNotContainsString('released', $this->events());
        $this->assertFileExists($this->directory.'/docker/.deploy.stamp.barrier-owed');
    }

    public function test_a_failed_barrier_retake_after_a_data_service_restart_still_owes_a_release(): void
    {
        mkdir($this->directory.'/docker/config/redis', 0777, true);
        file_put_contents($this->directory.'/docker/config/redis/redis.conf', "maxmemory 1gb\n");
        $config = ['NNTMUX_CONFIG_ID' => $this->composeJson()];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();

        file_put_contents($this->directory.'/docker/config/redis/redis.conf', "maxmemory 2gb\n");
        $process = $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config + ['NNTMUX_BARRIER_FAIL_AFTER' => '--wait-timeout 240 redis']);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertFileExists($this->directory.'/docker/.deploy.stamp.barrier-owed');

        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();
        $events = $this->events();
        $this->assertStringContainsString('releases:verification-barrier --timeout=900', $events);
        $this->assertStringContainsString('released', $events);
        $this->assertFileDoesNotExist($this->directory.'/docker/.deploy.stamp.barrier-owed');
    }

    public function test_a_failed_retry_never_clears_the_pause_left_by_an_earlier_failed_migration(): void
    {
        $this->deploy(['NNTMUX_MIGRATION_FAIL' => '1'])->run();
        // That barrier then lost its session and exited; only its durable pause holds remote workers.
        unlink($this->directory.'/barrier.running');
        file_put_contents($this->directory.'/barrier.log', "barrier held\nbarrier lost\n");
        file_put_contents($this->directory.'/events', '');

        $process = $this->deploy(['NNTMUX_STALE_STOP' => '1']);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('releases:verification-barrier --timeout=900', $this->events());
        $this->assertStringNotContainsString('released', $this->events());
        $this->assertStringNotContainsString('forced-release', $this->events());

        file_put_contents($this->directory.'/events', '');
        $this->deploy()->mustRun();
        $this->assertStringContainsString('artisan migrate --force', $this->events());
        $this->assertStringContainsString('released', $this->events());
        $this->assertFileDoesNotExist($this->directory.'/docker/.deploy.stamp.barrier-owed');
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

    public function test_a_failed_app_start_that_replaced_no_container_records_none_of_them(): void
    {
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        file_put_contents($this->directory.'/app.txt', "two\n");
        $failed = $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_APP_UP_FAIL' => '1', 'NNTMUX_APP_UP_NO_REPLACE' => '1']);
        $failed->run();
        $this->assertSame(52, $failed->getExitCode());

        // The old containers stayed healthy on the same image and Compose hash; none was replaced, so
        // the retry must recreate all of them instead of stamping a no-op.
        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        $this->assertStringContainsString(' up -d --no-deps --force-recreate --wait --wait-timeout 240 web horizon scheduler indexer', $this->events());
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

    public function test_a_removed_infrastructure_container_is_recreated_but_a_stopped_one_is_left(): void
    {
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        file_put_contents($this->directory.'/events', '');

        // The proxy container was removed: the guard must not skip, and the plan recreates it.
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_NO_CONTAINER' => 'proxy'])->mustRun();
        $this->assertStringContainsString(' up -d --no-deps --wait --wait-timeout 240 proxy', $this->events());
    }

    public function test_infrastructure_needing_a_changed_data_service_and_a_stopped_app_follows_the_apps(): void
    {
        mkdir($this->directory.'/docker/config/redis', 0777, true);
        file_put_contents($this->directory.'/docker/config/redis/redis.conf', "maxmemory 1gb\n");
        $json = json_decode($this->composeJson(), true);
        $json['services']['sidecar'] = ['labels' => ['nntmux.deploy.stateless' => 'true'], 'depends_on' => ['web' => ['condition' => 'service_healthy'], 'redis' => ['condition' => 'service_healthy']], 'environment' => ['V' => '1']];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_CONFIG_ID' => (string) json_encode($json, JSON_UNESCAPED_SLASHES)])->mustRun();
        file_put_contents($this->directory.'/events', '');

        // Changed redis stops every writer (full mode); the sidecar needs web, so it waits for it.
        file_put_contents($this->directory.'/docker/config/redis/redis.conf', "maxmemory 2gb\n");
        $json['services']['sidecar']['environment'] = ['V' => '2'];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_CONFIG_ID' => (string) json_encode($json, JSON_UNESCAPED_SLASHES)])->mustRun();
        $events = $this->events();
        $this->assertGreaterThan(strpos($events, 'web horizon scheduler indexer'), strpos($events, '--wait-timeout 240 sidecar'));
    }

    public function test_an_owed_barrier_release_is_never_batched(): void
    {
        $this->deploy()->mustRun();
        file_put_contents($this->directory.'/docker/.deploy.stamp.barrier-owed', '');
        file_put_contents($this->directory.'/docker/.env', "DB_ROOTPASSWORD=rotated\n");
        file_put_contents($this->directory.'/events', '');

        $this->deploy()->mustRun();
        $this->assertStringContainsString('releases:verification-barrier --timeout=900', $this->events());
        $this->assertFileDoesNotExist($this->directory.'/docker/.deploy.stamp.barrier-owed');
    }

    public function test_the_final_stamp_records_the_verified_images_not_a_tag_replaced_afterwards(): void
    {
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_REPLACE_AT_STAMP' => '1'])->mustRun();
        $stamp = (string) file_get_contents($this->directory.'/docker/.deploy.stamp');
        $this->assertStringContainsString('image:web=nntmux/web:local@sha256:fixture', $stamp);
        $this->assertStringNotContainsString('sha256:replaced', $stamp);
    }

    public function test_a_daemon_chosen_inside_dc_fails_closed(): void
    {
        foreach (['docker --context remote', 'docker -H ssh://host', 'docker -Hssh://host', 'docker -cremote', 'docker --config /tmp/other', '/usr/local/bin/remote-compose'] as $flags) {
            $plan = $this->make(['deploy-plan', 'DC='.$flags.' compose --project-directory '.$this->directory.'/docker']);
            $plan->run();
            $this->assertNotSame(0, $plan->getExitCode(), $flags);
            $this->assertStringContainsString('can select another', $plan->getErrorOutput(), $flags);
        }
    }

    public function test_effective_compose_configuration_changes_trigger_a_rollout(): void
    {
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_CONFIG_ID' => substr('{"services":{"web":{},"horizon":{},"scheduler":{},"indexer":{},"mariadb":{},"redis":{},"manticore":{},"proxy":{},"mailpit":{},"prometheus":{},"pushgateway":{},"grafana":{},"node-exporter":{},"mysqld-exporter":{},"redis-exporter":{}}}', 0, -1).',"version":"changed"}'])->mustRun();
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

        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0', 'DC=docker compose --project-directory '.$this->directory.'/docker --ansi never'], $config)->mustRun();
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
        file_put_contents($this->directory.'/.dockerignore', "vendor\n", FILE_APPEND);
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
        $this->assertDoesNotMatchRegularExpression('/ up -d[^\n]* proxy\b/', $this->events());

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
        $dc = 'DC=docker compose --project-directory '.$this->directory.'/docker --ansi never';
        file_put_contents($this->directory.'/events', '');
        $failed = $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0', $dc], ['NNTMUX_APP_UP_FAIL' => '1']);
        $failed->run();
        $this->assertSame(52, $failed->getExitCode());
        $this->assertStringContainsString('artisan migrate', $this->events());

        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0', $dc])->mustRun();
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
        // Mounted files present: a missing one would be a change, not a reconcile.
        $this->composeFiles();
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

    public function test_pointing_the_app_at_another_database_runs_the_migration(): void
    {
        mkdir($this->directory.'/docker/config', 0777, true);
        file_put_contents($this->directory.'/docker/config/app.env', "DB_HOST=192.0.2.10\nDB_DATABASE=nntmux\nAPP_DEBUG=false\n");
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();

        // An unrelated app.env key restarts the apps but does not migrate.
        file_put_contents($this->directory.'/docker/config/app.env', "DB_HOST=192.0.2.10\nDB_DATABASE=nntmux\nAPP_DEBUG=true\n");
        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        $this->assertStringNotContainsString('artisan migrate', $this->events());

        // Another database (e.g. a restored copy) migrates.
        file_put_contents($this->directory.'/docker/config/app.env', "DB_HOST=192.0.2.20\nDB_DATABASE=nntmux\nAPP_DEBUG=true\n");
        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        $this->assertStringContainsString('artisan migrate --force', $this->events());

        // DB_URL replaces the host and database settings.
        file_put_contents($this->directory.'/docker/config/app.env', "DB_URL=mysql://u:p@192.0.2.30:3306/nntmux\nDB_HOST=192.0.2.20\nDB_DATABASE=nntmux\nAPP_DEBUG=true\n");
        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        $this->assertStringContainsString('artisan migrate --force', $this->events());
        $this->assertStringNotContainsString('u:p@', (string) file_get_contents($this->directory.'/docker/.deploy.stamp'));

        // Rotating only the URL's password is no database switch.
        file_put_contents($this->directory.'/docker/config/app.env', "DB_URL=mysql://u:rotated@192.0.2.30:3306/nntmux\nDB_HOST=192.0.2.20\nDB_DATABASE=nntmux\nAPP_DEBUG=true\n");
        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        $this->assertStringNotContainsString('artisan migrate', $this->events());

        // Nor is one whose new password holds a literal `$`, nor a later unrelated edit.
        file_put_contents($this->directory.'/docker/config/app.env', "DB_URL=mysql://u:n\$ew@192.0.2.30:3306/nntmux\nDB_HOST=192.0.2.20\nDB_DATABASE=nntmux\nAPP_DEBUG=true\n");
        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        $this->assertStringNotContainsString('artisan migrate', $this->events());
        file_put_contents($this->directory.'/docker/config/app.env', "DB_URL=mysql://u:n\$ew@192.0.2.30:3306/nntmux\nDB_HOST=192.0.2.20\nDB_DATABASE=nntmux\nAPP_DEBUG=false\n");
        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        $this->assertStringNotContainsString('artisan migrate', $this->events());

        // So can a reference in DB_URL's port.
        file_put_contents($this->directory.'/docker/config/app.env', "CUTOVER_PORT=3306\nDB_URL=mysql://u:p@192.0.2.50:\${CUTOVER_PORT}/nntmux\n");
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        file_put_contents($this->directory.'/docker/config/app.env', "CUTOVER_PORT=3307\nDB_URL=mysql://u:p@192.0.2.50:\${CUTOVER_PORT}/nntmux\n");
        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        $this->assertStringContainsString('artisan migrate --force', $this->events());

        // A nested reference can switch databases without changing DB_HOST's text.
        file_put_contents($this->directory.'/docker/config/app.env', "DB_TARGET=192.0.2.40\nDB_HOST=\${DB_TARGET}\nDB_DATABASE=nntmux\n");
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        file_put_contents($this->directory.'/docker/config/app.env', "DB_TARGET=192.0.2.41\nDB_HOST=\${DB_TARGET}\nDB_DATABASE=nntmux\n");
        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        $this->assertStringContainsString('artisan migrate --force', $this->events());
    }

    public function test_infrastructure_a_new_data_service_needs_starts_first(): void
    {
        $base = json_decode($this->composeJson(), true);
        unset($base['services']['redis']);
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_CONFIG_ID' => (string) json_encode($base, JSON_UNESCAPED_SLASHES)])->mustRun();
        file_put_contents($this->directory.'/events', '');

        // A new redis (no container) depends on a newly added stateless sidecar.
        $with = $base;
        $with['services']['redis'] = ['depends_on' => ['tunnel' => ['condition' => 'service_started']]];
        $with['services']['tunnel'] = ['labels' => ['nntmux.deploy.stateless' => 'true']];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_CONFIG_ID' => (string) json_encode($with, JSON_UNESCAPED_SLASHES), 'NNTMUX_NO_CONTAINER' => 'redis'])->mustRun();
        $events = $this->events();
        $tunnel = strpos($events, '--wait-timeout 240 tunnel');
        $redis = strpos($events, '--wait-timeout 240 redis');
        $this->assertNotFalse($tunnel);
        $this->assertNotFalse($redis);
        $this->assertLessThan($redis, $tunnel);
    }

    public function test_retargeting_a_symlinked_data_directory_recreates_the_service(): void
    {
        mkdir($this->directory.'/storage-a', 0777, true);
        mkdir($this->directory.'/storage-b', 0777, true);
        symlink($this->directory.'/storage-a', $this->directory.'/current');
        file_put_contents($this->directory.'/.gitignore', "/storage-a\n/storage-b\n/current\n", FILE_APPEND);
        file_put_contents($this->directory.'/.dockerignore', "storage-a\nstorage-b\ncurrent\n", FILE_APPEND);
        (new Process(['git', 'commit', '-q', '-am', 'storage'], $this->directory))->mustRun();
        $json = json_decode($this->composeJson(), true);
        $json['services']['redis'] = ['volumes' => [['type' => 'bind', 'source' => $this->directory.'/current', 'target' => '/data']]];
        $config = ['NNTMUX_CONFIG_ID' => (string) json_encode($json, JSON_UNESCAPED_SLASHES)];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();

        unlink($this->directory.'/current');
        symlink($this->directory.'/storage-b', $this->directory.'/current');
        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();
        $this->assertStringContainsString(' up -d --no-deps --force-recreate --wait --wait-timeout 240 redis', $this->events());
    }

    public function test_data_services_after_the_drain_follow_depends_on_across_both_lists(): void
    {
        // Legacy stamp: redis has no record (reconcile) while a changed sidecar database depends on it.
        $json = json_decode($this->composeJson(), true);
        $json['services']['cache2'] = ['depends_on' => ['redis' => ['condition' => 'service_healthy']]];
        $config = ['NNTMUX_CONFIG_ID' => (string) json_encode($json, JSON_UNESCAPED_SLASHES)];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();
        $stamp = (string) file_get_contents($this->directory.'/docker/.deploy.stamp');
        file_put_contents($this->directory.'/docker/.deploy.stamp', preg_replace('/^service:redis=.*\n/m', '', $stamp));
        $json['services']['cache2']['environment'] = ['CHANGED' => '1'];
        $config = ['NNTMUX_CONFIG_ID' => (string) json_encode($json, JSON_UNESCAPED_SLASHES)];
        file_put_contents($this->directory.'/events', '');

        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();
        $events = $this->events();
        $redis = strpos($events, '--wait-timeout 240 redis');
        $cache = strpos($events, '--wait-timeout 240 cache2');
        $this->assertNotFalse($redis);
        $this->assertNotFalse($cache);
        $this->assertLessThan($cache, $redis);
    }

    public function test_retargeting_a_symlinked_config_file_to_identical_bytes_recreates_the_service(): void
    {
        foreach (['a', 'b'] as $name) {
            file_put_contents($this->directory.'/conf-'.$name, "same\n");
        }
        symlink($this->directory.'/conf-a', $this->directory.'/conf');
        file_put_contents($this->directory.'/.gitignore', "/conf-a\n/conf-b\n/conf\n", FILE_APPEND);
        file_put_contents($this->directory.'/.dockerignore', "conf-a\nconf-b\nconf\n", FILE_APPEND);
        (new Process(['git', 'commit', '-q', '-am', 'conf'], $this->directory))->mustRun();
        $json = json_decode($this->composeJson(), true);
        $json['services']['proxy'] = ['volumes' => [['type' => 'bind', 'source' => $this->directory.'/conf', 'target' => '/etc/conf', 'read_only' => true]]];
        $config = ['NNTMUX_CONFIG_ID' => (string) json_encode($json, JSON_UNESCAPED_SLASHES)];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();

        unlink($this->directory.'/conf');
        symlink($this->directory.'/conf-b', $this->directory.'/conf');
        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();
        $this->assertStringContainsString(' up -d --no-deps --force-recreate --wait --wait-timeout 240 proxy', $this->events());
    }

    public function test_infrastructure_whose_app_provider_has_no_container_starts_after_the_apps(): void
    {
        $json = json_decode($this->composeJson(), true);
        $json['services']['sidecar'] = ['labels' => ['nntmux.deploy.stateless' => 'true'], 'depends_on' => ['web' => ['condition' => 'service_healthy']]];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_CONFIG_ID' => (string) json_encode($json, JSON_UNESCAPED_SLASHES)])->mustRun();
        file_put_contents($this->directory.'/events', '');

        $json['services']['sidecar']['environment'] = ['CHANGED' => '1'];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0', 'FORCE=1'], ['NNTMUX_CONFIG_ID' => (string) json_encode($json, JSON_UNESCAPED_SLASHES), 'NNTMUX_NO_CONTAINER' => 'web'])->mustRun();
        $events = $this->events();
        $this->assertGreaterThan(strpos($events, 'web horizon scheduler indexer'), strpos($events, '--wait-timeout 240 sidecar'));

        // A data service cannot wait for an app provider.
        $json['services']['cache2'] = ['depends_on' => ['web' => ['condition' => 'service_healthy']]];
        $plan = $this->make(['deploy-plan'], ['NNTMUX_CONFIG_ID' => (string) json_encode($json, JSON_UNESCAPED_SLASHES), 'NNTMUX_NO_CONTAINER' => 'cache2']);
        $plan->run();
        $this->assertNotSame(0, $plan->getExitCode());
        $this->assertStringContainsString('data service cache2 depends on app service web', $plan->getErrorOutput());
    }

    public function test_docker_context_overrides_docker_host_in_the_daemon_identity(): void
    {
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['DOCKER_HOST' => 'unix:///same.sock', 'DOCKER_CONTEXT' => 'one'])->mustRun();
        $process = $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['DOCKER_HOST' => 'unix:///same.sock', 'DOCKER_CONTEXT' => 'two']);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('Docker daemon changed from context:one to context:two', $process->getErrorOutput());
    }

    public function test_a_docker_daemon_change_aborts_before_any_change(): void
    {
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['DOCKER_CONTEXT' => 'one'])->mustRun();
        $this->assertStringContainsString("\ndaemon=context:one\n", (string) file_get_contents($this->directory.'/docker/.deploy.stamp'));
        file_put_contents($this->directory.'/events', '');

        $process = $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['DOCKER_CONTEXT' => 'two']);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('Docker daemon changed from context:one to context:two', $process->getErrorOutput());
        $this->assertStringNotContainsString(' up -d', $this->events());
    }

    public function test_infrastructure_that_needs_a_changed_data_service_follows_it_after_the_drain(): void
    {
        mkdir($this->directory.'/docker/config/redis', 0777, true);
        file_put_contents($this->directory.'/docker/config/redis/redis.conf', "maxmemory 1gb\n");
        $json = json_decode($this->composeJson(), true);
        $json['services']['redis-exporter'] = ['depends_on' => ['redis'], 'environment' => ['V' => '1']];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_CONFIG_ID' => (string) json_encode($json, JSON_UNESCAPED_SLASHES)])->mustRun();
        file_put_contents($this->directory.'/events', '');

        file_put_contents($this->directory.'/docker/config/redis/redis.conf', "maxmemory 2gb\n");
        $json['services']['redis-exporter']['environment'] = ['V' => '2'];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_CONFIG_ID' => (string) json_encode($json, JSON_UNESCAPED_SLASHES)])->mustRun();
        $events = $this->events();
        $this->assertLessThan(strpos($events, '--wait-timeout 240 redis-exporter'), strpos($events, '--wait-timeout 240 redis'."\n"));
    }

    public function test_infrastructure_whose_app_provider_is_stopped_or_unhealthy_follows_the_apps(): void
    {
        $json = json_decode($this->composeJson(), true);
        $json['services']['sidecar'] = ['labels' => ['nntmux.deploy.stateless' => 'true'], 'depends_on' => ['web' => ['condition' => 'service_healthy']]];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_CONFIG_ID' => (string) json_encode($json, JSON_UNESCAPED_SLASHES)])->mustRun();
        file_put_contents($this->directory.'/events', '');

        $json['services']['sidecar']['environment'] = ['CHANGED' => '1'];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0', 'FORCE=1'], ['NNTMUX_CONFIG_ID' => (string) json_encode($json, JSON_UNESCAPED_SLASHES), 'NNTMUX_UNHEALTHY' => 'web'])->run();
        $events = $this->events();
        $this->assertGreaterThan(strpos($events, 'web horizon scheduler indexer'), strpos($events, '--wait-timeout 240 sidecar'));
    }

    public function test_retargeting_a_symlinked_read_only_directory_recreates_the_service(): void
    {
        foreach (['a', 'b'] as $name) {
            mkdir($this->directory.'/tree-'.$name);
            file_put_contents($this->directory.'/tree-'.$name.'/x.conf', "same\n");
        }
        symlink($this->directory.'/tree-a', $this->directory.'/tree');
        file_put_contents($this->directory.'/.gitignore', "/tree-a\n/tree-b\n/tree\n", FILE_APPEND);
        file_put_contents($this->directory.'/.dockerignore', "tree-a\ntree-b\ntree\n", FILE_APPEND);
        (new Process(['git', 'commit', '-q', '-am', 'tree'], $this->directory))->mustRun();
        $json = json_decode($this->composeJson(), true);
        $json['services']['proxy'] = ['volumes' => [['type' => 'bind', 'source' => $this->directory.'/tree', 'target' => '/etc/tree', 'read_only' => true]]];
        $config = ['NNTMUX_CONFIG_ID' => (string) json_encode($json, JSON_UNESCAPED_SLASHES)];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();

        unlink($this->directory.'/tree');
        symlink($this->directory.'/tree-b', $this->directory.'/tree');
        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();
        $this->assertStringContainsString(' up -d --no-deps --force-recreate --wait --wait-timeout 240 proxy', $this->events());
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

        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_CONFIG_ID' => '{"services":{'.$services.',"sidecar":{}}}', 'NNTMUX_NO_CONTAINER' => 'sidecar'])->mustRun();
        $events = $this->events();
        // A service added to Compose (with no container yet) is created by a non-forced up, without a drain.
        $this->assertStringContainsString(' up -d --no-deps --wait --wait-timeout 240 sidecar', $events);
        $this->assertStringNotContainsString('drain', $events);
        $this->assertStringNotContainsString(' stop ', $events);
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
        // Read-only image queries may name it; nothing recreates or reconciles it.
        $this->assertDoesNotMatchRegularExpression('/ up -d[^\n]* proxy\b/', $this->events());
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
        $this->composeFiles();
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
        // In production the runtime is outside the candidate checkout, so outside its build context too.
        file_put_contents($this->directory.'/.dockerignore', "runtime\n", FILE_APPEND);
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
        file_put_contents($this->directory.'/.dockerignore', "outside\n", FILE_APPEND);
        (new Process(['git', 'commit', '-q', '-am', 'outside mounts'], $this->directory))->mustRun();
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();
        file_put_contents($this->directory.'/events', '');

        // Finder metadata is no configuration change.
        file_put_contents($this->directory.'/outside/certs/.DS_Store', "finder\n");
        file_put_contents($this->directory.'/outside/certs/._ca.pem', "appledouble\n");
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();
        $this->assertStringNotContainsString(' up -d', $this->events());

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
        // Redis's mounted file predates both container start times.
        mkdir($this->directory.'/docker/config/redis', 0777, true);
        file_put_contents($this->directory.'/docker/config/redis/redis.conf', "maxmemory 1gb\n");
        touch($this->directory.'/docker/config/redis/redis.conf', 900000000);
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
        $this->composeFiles();
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
            'scripts/install-monitoring.sh' => '-', '.env.example' => '-', 'docker/docker-compose.yml' => '-', 'docker/config/caddy/Caddyfile' => '-', 'app/.DS_Store' => '-', 'public/.DS_Store' => '-',
        ];
        $root = dirname(__DIR__, 2);
        $process = new Process([$root.'/docker/bin/deploy-plan', '--classify', ...array_keys($paths)], env: ['DEPLOY_REPO' => $root, 'PYTHONDONTWRITEBYTECODE' => '1']);
        $process->mustRun();
        $expected = array_map(fn (string $path, string $owner): string => $path.' '.$owner, array_keys($paths), $paths);
        $this->assertSame($expected, explode("\n", trim($process->getOutput())));
    }

    public function test_success_requires_every_app_service_healthy_after_a_selective_rollout(): void
    {
        mkdir($this->directory.'/resources/js', 0777, true);
        file_put_contents($this->directory.'/resources/js/app.js', "1\n");
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        file_put_contents($this->directory.'/docker/.deploy.time', '1000');
        file_put_contents($this->directory.'/resources/js/app.js', "2\n");
        file_put_contents($this->directory.'/events', '');

        // Horizon, untouched by this web-only rollout, becomes unhealthy while web is recreated.
        $process = $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], [
            'NNTMUX_UNHEALTHY_AFTER' => ' up -d --no-deps --force-recreate --wait --wait-timeout 240 web', 'NNTMUX_UNHEALTHY_LATE' => 'horizon',
        ]);
        $process->run();
        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('not every app service is running healthy', $process->getErrorOutput());
        $this->assertStringNotContainsString('web horizon', $this->events());
        $this->assertNotStamped();
        $this->assertSame('1000', trim((string) file_get_contents($this->directory.'/docker/.deploy.time')));
        $this->assertAttemptsClosed();
    }

    public function test_a_failed_final_check_keeps_remote_workers_paused(): void
    {
        $process = $this->deploy(['NNTMUX_UNHEALTHY_AFTER' => ' up -d --no-deps --force-recreate --wait --wait-timeout 240 web horizon scheduler indexer', 'NNTMUX_UNHEALTHY_LATE' => 'horizon']);
        $process->run();

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('not every app service is running healthy', $process->getErrorOutput());
        // The stack was never verified, so the barrier is neither released nor cleared.
        $this->assertStringNotContainsString('released', $this->events());
        $this->assertFileExists($this->directory.'/docker/.deploy.stamp.barrier-owed');
    }

    public function test_an_unrecordable_owed_release_never_pauses_remote_workers(): void
    {
        mkdir($this->directory.'/docker/.deploy.stamp.barrier-owed');
        $process = $this->deploy();
        $process->run();

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('Cannot record the owed barrier release', $process->getErrorOutput());
        $this->assertStringNotContainsString('releases:verification-barrier', $this->events());
        $this->assertStringNotContainsString(' stop web scheduler indexer', $this->events());
    }

    public function test_an_orphaned_data_container_with_a_replaced_image_never_blocks_rollouts(): void
    {
        $orphan = ['NNTMUX_ORPHAN' => 'oldcache', 'NNTMUX_STALE_SERVICE_IMAGE' => 'oldcache'];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $orphan)->mustRun();
        $this->assertNotSame('stale', strtok((string) file_get_contents($this->directory.'/docker/.deploy.stamp'), "\n"));

        // The unrendered orphan is not stale for the guard's unchanged check either.
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $orphan)->mustRun();
        $this->assertStringContainsString('result=skipped (nothing changed', (string) file_get_contents($this->directory.'/docker/.deploy.log'));
    }

    public function test_a_new_data_service_starts_before_the_infrastructure_that_now_needs_it(): void
    {
        mkdir($this->directory.'/docker/config/caddy', 0777, true);
        file_put_contents($this->directory.'/docker/config/caddy/Caddyfile', "one\n");
        $without = json_decode($this->composeJson(), true);
        unset($without['services']['redis']);
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_CONFIG_ID' => (string) json_encode($without, JSON_UNESCAPED_SLASHES)])->mustRun();
        file_put_contents($this->directory.'/events', '');

        // The proxy changes and now depends on a newly rendered redis that has no container yet.
        $with = json_decode($this->composeJson(), true);
        $with['services']['proxy']['depends_on'] = ['redis' => ['condition' => 'service_healthy']];
        file_put_contents($this->directory.'/docker/config/caddy/Caddyfile', "two\n");
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_CONFIG_ID' => (string) json_encode($with, JSON_UNESCAPED_SLASHES), 'NNTMUX_NO_CONTAINER' => 'redis'])->mustRun();
        $events = $this->events();
        $redis = strpos($events, '--wait-timeout 240 redis');
        $proxy = strpos($events, '--wait-timeout 240 proxy');
        $this->assertNotFalse($redis);
        $this->assertNotFalse($proxy);
        $this->assertLessThan($proxy, $redis);
    }

    public function test_a_forced_rollout_starts_a_missing_data_service_before_taking_the_barrier(): void
    {
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        file_put_contents($this->directory.'/events', '');

        foreach ([['FORCE=1'], []] as $arguments) {
            // Forced, or a cutover that changed the missing database: writers stop, then it starts,
            // then the barrier is taken on it.
            if ($arguments === []) {
                file_put_contents($this->directory.'/docker/.env', "MARIADB_IMAGE=mariadb:restored\n");
            }
            file_put_contents($this->directory.'/events', '');
            $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0', ...$arguments], ['NNTMUX_NO_CONTAINER' => 'mariadb', 'NNTMUX_CONFIG_ID' => $arguments === [] ? '{"services":{"web":{},"horizon":{},"scheduler":{},"indexer":{},"mariadb":{"image":"mariadb:restored"},"redis":{},"manticore":{},"proxy":{}}}' : ''])->mustRun();
            $events = $this->events();
            $start = strpos($events, 'up -d --no-deps --wait --wait-timeout 240 mariadb');
            $this->assertNotFalse($start, implode(' ', $arguments));
            $this->assertLessThan($start, strpos($events, ' stop web scheduler indexer'));
            $this->assertLessThan(strpos($events, 'releases:verification-barrier --timeout'), $start);
        }
    }

    public function test_a_pending_base_rebuild_is_not_skipped_as_unchanged(): void
    {
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        file_put_contents($this->directory.'/events', '');

        // The base image was refreshed in place: same source and tags, a new local base image ID.
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_BASE_IMAGE_ID' => 'sha256:refreshed'])->mustRun();
        $this->assertStringContainsString('build --target selfhosted-web', $this->events());
    }

    public function test_an_unwritable_marker_during_recovery_still_restores_the_writers(): void
    {
        $process = $this->deploy(['NNTMUX_BARRIER_LOCK_MARKER' => '1']);
        $process->run();
        @chmod($this->directory.'/docker/.deploy.stamp.barrier-owed', 0644);

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('Could not record the owed barrier release', $process->getErrorOutput());
        $events = $this->events();
        $this->assertStringContainsString(' start horizon', $events);
        $this->assertStringContainsString('scheduler.py restore --token=', $events);
        $this->assertStringContainsString('tmux:drain --resume', $events);
    }

    public function test_a_failed_run_after_starting_a_missing_database_forces_its_recreate_on_retry(): void
    {
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        file_put_contents($this->directory.'/events', '');
        $process = $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0', 'FORCE=1'], ['NNTMUX_NO_CONTAINER' => 'mariadb', 'NNTMUX_BARRIER_FAIL' => '1']);
        $process->run();

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('up -d --no-deps --wait --wait-timeout 240 mariadb', $this->events());
        $this->assertStringContainsString('service:mariadb=pending', (string) file_get_contents($this->directory.'/docker/.deploy.stamp'));
    }

    public function test_a_failed_database_lookup_before_the_barrier_aborts_instead_of_starting_it(): void
    {
        // First deploy: mariadb has no record and is reconciled, so its container is looked up.
        $process = $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_PS_A_FAIL_AFTER' => 'tmux:drain --timeout']);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('tmux:drain --timeout', $this->events());
        $this->assertStringContainsString('ps -a -q mariadb', $this->events());
        $this->assertStringNotContainsString('up -d --no-deps --wait --wait-timeout 240 mariadb', $this->events());
        $this->assertStringNotContainsString(' stop web scheduler indexer', $this->events());
    }

    public function test_an_image_tag_replaced_during_the_drain_is_never_rolled_out(): void
    {
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        file_put_contents($this->directory.'/app.txt', "two\n");
        (new Process(['git', 'commit', '-q', '-am', 'change'], $this->directory))->mustRun();
        file_put_contents($this->directory.'/events', '');

        $process = $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_REPLACE_DURING_DRAIN' => '1']);
        $process->run();
        @unlink($this->directory.'/replaced');
        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('not rolling out a replaced image', $process->getErrorOutput());
        $this->assertStringNotContainsString(' up -d --no-deps --force-recreate --wait --wait-timeout 240 web', $this->events());
        $this->assertNotStamped();
    }

    public function test_extension_blocks_never_force_a_global_rollout(): void
    {
        $config = fn (string $value): array => ['NNTMUX_CONFIG_ID' => substr($this->composeJson(), 0, -1).',"x-note":{"v":"'.$value.'"}}'];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config('one'))->mustRun();
        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config('two'))->mustRun();
        $this->assertStringNotContainsString('artisan migrate', $this->events());
        $this->assertStringNotContainsString(' up -d', $this->events());
    }

    public function test_an_alternate_compose_env_file_fails_closed(): void
    {
        $plan = $this->make(['deploy-plan', 'DC=docker compose --env-file /tmp/other.env']);
        $plan->run();
        $this->assertNotSame(0, $plan->getExitCode());
        $this->assertStringContainsString('DC passes --env-file', $plan->getErrorOutput());

        $plan = $this->make(['deploy-plan'], ['COMPOSE_ENV_FILES' => '/tmp/other.env']);
        $plan->run();
        $this->assertNotSame(0, $plan->getExitCode());
        $this->assertStringContainsString('COMPOSE_ENV_FILES is set', $plan->getErrorOutput());
    }

    public function test_image_tag_replaced_after_the_build_aborts_before_any_change(): void
    {
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        $stamp = (string) file_get_contents($this->directory.'/docker/.deploy.stamp');
        file_put_contents($this->directory.'/app.txt', "two\n");
        file_put_contents($this->directory.'/events', '');

        $process = $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_REPLACE_TAG_ON_STATUS' => '1']);
        $process->run();
        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('image tags changed after the build', $process->getErrorOutput());
        $events = $this->events();
        $this->assertStringContainsString('build --target selfhosted-web', $events);
        $this->assertStringNotContainsString(' up -d', $events);
        $this->assertStringNotContainsString('drain', $events);
        $this->assertStringNotContainsString(' stop ', $events);
        $this->assertSame($stamp, (string) file_get_contents($this->directory.'/docker/.deploy.stamp'));
    }

    public function test_forced_rollout_with_freshly_built_images_is_not_mistaken_for_a_replaced_tag(): void
    {
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'FORCE=1'])->mustRun();
        $this->assertStringContainsString('build --target', $this->events());
        $this->assertStringContainsString(' up -d --no-deps --force-recreate --wait --wait-timeout 240 web horizon scheduler indexer', $this->events());
    }

    public function test_unknown_service_with_a_container_but_no_record_changes_only_with_writers_quiesced(): void
    {
        $services = '"web":{},"horizon":{},"scheduler":{},"indexer":{},"redis":{},"manticore":{},"proxy":{}';
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_CONFIG_ID' => '{"services":{'.$services.'}}'])->mustRun();
        $config = ['NNTMUX_CONFIG_ID' => '{"services":{'.$services.',"replacement-db":{}}}'];

        $plan = $this->make(['deploy-plan'], $config);
        $plan->mustRun();
        $this->assertMatchesRegularExpression('/replacement-db\s+data\s+reconcile/', $plan->getOutput());

        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();
        $events = $this->events();
        $up = ' up -d --no-deps --wait --wait-timeout 240 replacement-db';
        $this->assertStringContainsString($up, $events);
        $this->assertLessThan(strpos($events, $up), strpos($events, ' stop web scheduler indexer'));
    }

    public function test_unknown_service_labelled_stateless_is_reconciled_as_infrastructure_before_any_drain(): void
    {
        $services = '"web":{},"horizon":{},"scheduler":{},"indexer":{},"redis":{},"manticore":{},"proxy":{}';
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_CONFIG_ID' => '{"services":{'.$services.'}}'])->mustRun();
        file_put_contents($this->directory.'/events', '');

        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_CONFIG_ID' => '{"services":{'.$services.',"sidecar":{"labels":{"nntmux.deploy.stateless":"true"}}}}'])->mustRun();
        $events = $this->events();
        $this->assertStringContainsString(' up -d --no-deps --wait --wait-timeout 240 sidecar', $events);
        $this->assertStringNotContainsString('drain', $events);
        $this->assertStringNotContainsString(' stop ', $events);
    }

    public function test_added_services_are_applied_in_dependency_order(): void
    {
        $base = ['web' => [], 'horizon' => [], 'scheduler' => [], 'indexer' => [], 'redis' => [], 'manticore' => [], 'proxy' => []];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_CONFIG_ID' => (string) json_encode(['services' => $base])])->mustRun();
        file_put_contents($this->directory.'/events', '');

        $stateless = ['nntmux.deploy.stateless' => 'true'];
        $services = $base + [
            'aaa-consumer' => ['labels' => $stateless, 'depends_on' => ['zzz-provider' => ['condition' => 'service_healthy']]],
            'zzz-provider' => ['labels' => $stateless],
        ];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_CONFIG_ID' => (string) json_encode(['services' => $services])])->mustRun();
        $events = $this->events();
        $provider = strpos($events, ' up -d --no-deps --wait --wait-timeout 240 zzz-provider');
        $this->assertNotFalse($provider);
        $this->assertGreaterThan($provider, strpos($events, ' up -d --no-deps --wait --wait-timeout 240 aaa-consumer'));
    }

    public function test_legacy_stamp_recreates_a_service_whose_mounted_file_was_deleted(): void
    {
        // The proxy's Caddyfile no longer exists while its container keeps running.
        mkdir($this->directory.'/docker/config/redis', 0777, true);
        file_put_contents($this->directory.'/docker/config/redis/redis.conf', "maxmemory 1gb\n");
        $head = trim((new Process(['git', 'rev-parse', 'HEAD'], $this->directory))->mustRun()->getOutput());
        file_put_contents($this->directory.'/docker/.deploy.stamp', $head." e3b0 e3b0 c16c bb93\n");

        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_CONFIG_ID' => $this->composeJson()])->mustRun();
        $events = $this->events();
        $this->assertStringContainsString(' up -d --no-deps --force-recreate --wait --wait-timeout 240 proxy', $events);
        $this->assertStringContainsString(' up -d --no-deps --wait --wait-timeout 240 redis', $events);
        $this->assertStringNotContainsString('--force-recreate --wait --wait-timeout 240 redis', $events);
    }

    public function test_retargeted_directory_symlink_in_a_read_only_mount_recreates_the_service(): void
    {
        $tree = $this->directory.'/docker/config/proxy-conf';
        foreach (['v1', 'v2'] as $version) {
            mkdir($tree.'/'.$version, 0777, true);
            file_put_contents($tree.'/'.$version.'/site.conf', "same\n");
        }
        symlink('v1', $tree.'/current');
        $proxy = ['volumes' => [['type' => 'bind', 'source' => $tree, 'target' => '/etc/caddy/conf', 'read_only' => true]]];
        $config = ['NNTMUX_CONFIG_ID' => (string) json_encode(['services' => ['web' => [], 'horizon' => [], 'scheduler' => [], 'indexer' => [], 'proxy' => $proxy]], JSON_UNESCAPED_SLASHES)];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();
        file_put_contents($this->directory.'/events', '');

        unlink($tree.'/current');
        symlink('v2', $tree.'/current');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();
        $this->assertStringContainsString(' up -d --no-deps --force-recreate --wait --wait-timeout 240 proxy', $this->events());
    }

    public function test_replacing_the_database_storage_runs_the_migration(): void
    {
        $bind = fn (string $source): array => ['volumes' => [['type' => 'bind', 'source' => $this->directory.'/'.$source, 'target' => '/var/lib/mysql']]];
        $services = ['web' => [], 'horizon' => [], 'scheduler' => [], 'indexer' => [], 'redis' => [], 'manticore' => [], 'proxy' => []];
        $config = fn (string $source): array => ['NNTMUX_CONFIG_ID' => (string) json_encode(['services' => $services + ['mariadb' => $bind($source)]], JSON_UNESCAPED_SLASHES)];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config('data/live'))->mustRun();
        file_put_contents($this->directory.'/events', '');

        // A cutover points MariaDB at a restored copy; no migration file or app definition changed.
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config('data/restored'))->mustRun();
        $events = $this->events();
        $this->assertStringContainsString(' up -d --no-deps --force-recreate --wait --wait-timeout 240 mariadb', $events);
        $this->assertGreaterThan(strpos($events, '240 mariadb'), strpos($events, 'artisan migrate --force'));
    }

    public function test_missing_data_service_whose_record_changed_is_recreated_with_writers_quiesced(): void
    {
        $this->composeFiles();
        $config = ['NNTMUX_CONFIG_ID' => $this->composeJson()];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();
        file_put_contents($this->directory.'/docker/config/redis/redis.conf', "maxmemory 2gb\n");
        file_put_contents($this->directory.'/events', '');

        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config + ['NNTMUX_NO_CONTAINER' => 'redis'])->mustRun();
        $events = $this->events();
        $recreate = ' up -d --no-deps --force-recreate --wait --wait-timeout 240 redis';
        $this->assertStringContainsString($recreate, $events);
        $this->assertLessThan(strpos($events, $recreate), strpos($events, ' stop web scheduler indexer'));
        $this->assertStringNotContainsString(' up -d --no-deps --wait --wait-timeout 240 redis', $events);
    }

    public function test_compose_project_name_change_aborts_before_any_change(): void
    {
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        $stamp = (string) file_get_contents($this->directory.'/docker/.deploy.stamp');
        $this->assertStringContainsString("\nproject=nntmux\n", $stamp);
        $renamed = ['NNTMUX_CONFIG_ID' => '{"name":"other","services":{"web":{},"horizon":{},"scheduler":{},"indexer":{},"redis":{},"manticore":{},"proxy":{}}}'];

        $plan = $this->make(['deploy-plan'], $renamed);
        $plan->run();
        $this->assertNotSame(0, $plan->getExitCode());
        $this->assertStringContainsString('effective Compose project changed from nntmux to other', $plan->getErrorOutput());

        file_put_contents($this->directory.'/events', '');
        $process = $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $renamed);
        $process->run();
        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringNotContainsString(' up -d', $this->events());
        $this->assertStringNotContainsString('drain', $this->events());
        $this->assertSame($stamp, (string) file_get_contents($this->directory.'/docker/.deploy.stamp'));

        // A stamp written before the project was recorded is still accepted.
        file_put_contents($this->directory.'/docker/.deploy.stamp', str_replace("project=nntmux\n", '', $stamp));
        $this->make(['deploy-plan'], $renamed)->mustRun();
    }

    public function test_failed_recreate_that_left_the_old_container_healthy_is_not_recorded(): void
    {
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        $config = ['NNTMUX_CONFIG_ID' => '{"services":{"web":{"environment":{"FEATURE":"on"}},"horizon":{},"scheduler":{},"indexer":{},"redis":{},"manticore":{},"proxy":{}}}', 'NNTMUX_CONFIG_HASH' => 'new-config'];

        // compose up fails before replacing web: the old container stays healthy on the same image.
        $failed = $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config + ['NNTMUX_APP_UP_FAIL' => '1', 'NNTMUX_CONTAINER_CONFIG_HASH' => 'old-config']);
        $failed->run();
        $this->assertSame(52, $failed->getExitCode());
        $this->assertStringNotContainsString('service:web=', (string) file_get_contents($this->directory.'/docker/.deploy.stamp'));

        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();
        $this->assertStringContainsString(' up -d --no-deps --force-recreate --wait --wait-timeout 240 web', $this->events());
    }

    public function test_data_and_infra_containers_running_a_replaced_image_tag_are_recreated(): void
    {
        $this->composeFiles();
        $json = json_decode($this->composeJson(), true);
        $json['services']['redis']['image'] = 'redis:7.4.2-alpine';
        $json['services']['proxy']['image'] = 'caddy:2.10.2-alpine';
        $config = ['NNTMUX_CONFIG_ID' => (string) json_encode($json, JSON_UNESCAPED_SLASHES)];
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();
        $stamp = (string) file_get_contents($this->directory.'/docker/.deploy.stamp');

        // `docker pull redis:7.4.2-alpine` replaced the tag; the rendered configuration is unchanged.
        file_put_contents($this->directory.'/events', '');
        $plan = $this->make(['deploy-plan'], $config + ['NNTMUX_STALE_SERVICE_IMAGE' => 'redis']);
        $plan->mustRun();
        $this->assertMatchesRegularExpression('/redis\s+data\s+drain\+recreate\s+running image differs from its tag/', $plan->getOutput());
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config + ['NNTMUX_STALE_SERVICE_IMAGE' => 'redis'])->mustRun();
        $events = $this->events();
        $recreate = ' up -d --no-deps --force-recreate --wait --wait-timeout 240 redis';
        $this->assertStringContainsString($recreate, $events);
        $this->assertLessThan(strpos($events, $recreate), strpos($events, ' stop web scheduler indexer'));
        // Image IDs never enter the fingerprint.
        $this->assertSame(strtok($stamp, "\n"), strtok((string) file_get_contents($this->directory.'/docker/.deploy.stamp'), "\n"));

        // Recreated on the new image: the next poll skips.
        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config + ['NNTMUX_STALE_SERVICE_IMAGE' => 'redis'])->mustRun();
        $this->assertStringNotContainsString(' up -d', $this->events());

        // A stale stateless service is recreated without draining any writer.
        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config + ['NNTMUX_STALE_SERVICE_IMAGE' => 'proxy'])->mustRun();
        $this->assertStringContainsString(' up -d --no-deps --force-recreate --wait --wait-timeout 240 proxy', $this->events());
        $this->assertStringNotContainsString('drain', $this->events());
    }

    public function test_rebuilt_base_image_rebuilds_every_role_image(): void
    {
        $this->make(['build'], ['NNTMUX_BASE_IMAGE_ID' => 'sha256:base-1'])->mustRun();
        file_put_contents($this->directory.'/events', '');

        $this->make(['build'], ['NNTMUX_BASE_IMAGE_ID' => 'sha256:base-2'])->mustRun();
        $events = $this->events();
        $this->assertStringContainsString('build --target base ', $events);
        foreach (['web', 'horizon', 'scheduler', 'indexer'] as $role) {
            $this->assertStringContainsString('build --target selfhosted-'.$role, $events);
        }
    }

    public function test_git_ignored_files_the_build_context_copies_are_fingerprinted(): void
    {
        file_put_contents($this->directory.'/.gitignore', "error_log\nvendor/\n", FILE_APPEND);
        file_put_contents($this->directory.'/.dockerignore', "vendor/\n", FILE_APPEND);
        (new Process(['git', 'commit', '-q', '-am', 'ignore logs and dependencies'], $this->directory))->mustRun();
        mkdir($this->directory.'/public', 0777, true);
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();

        // Ignored by both Git and Docker: never an image input.
        file_put_contents($this->directory.'/events', '');
        mkdir($this->directory.'/vendor/pkg', 0777, true);
        file_put_contents($this->directory.'/vendor/pkg/x.php', "<?php\n");
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        $this->assertStringNotContainsString('build --target', $this->events());
        $this->assertStringNotContainsString(' up -d', $this->events());

        // Ignored by Git only: `COPY public` still copies it.
        file_put_contents($this->directory.'/events', '');
        file_put_contents($this->directory.'/public/error_log', "x\n");
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'])->mustRun();
        foreach (['web', 'horizon', 'scheduler', 'indexer'] as $role) {
            $this->assertStringContainsString('build --target selfhosted-'.$role, $this->events());
        }
    }

    public function test_config_and_secret_sources_are_fingerprinted_without_logging_values(): void
    {
        mkdir($this->directory.'/docker/config', 0777, true);
        file_put_contents($this->directory.'/docker/config/tls.pem', "one\n");
        $json = ['services' => ['web' => [], 'horizon' => [], 'scheduler' => [], 'indexer' => [], 'redis' => [], 'manticore' => [],
            'proxy' => ['secrets' => [['source' => 'tls', 'target' => 'tls']], 'configs' => [['source' => 'site', 'target' => '/etc/site']]]],
            'secrets' => ['tls' => ['file' => $this->directory.'/docker/config/tls.pem']],
            'configs' => ['site' => ['environment' => 'NNTMUX_SITE_CONFIG']]];
        $config = ['NNTMUX_CONFIG_ID' => (string) json_encode($json, JSON_UNESCAPED_SLASHES)];
        $recreate = ' up -d --no-deps --force-recreate --wait --wait-timeout 240 proxy';
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config + ['NNTMUX_SITE_CONFIG' => 'site-value-one'])->mustRun();

        // The secret's file content changes; its rendered definition does not.
        file_put_contents($this->directory.'/events', '');
        file_put_contents($this->directory.'/docker/config/tls.pem', "two\n");
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config + ['NNTMUX_SITE_CONFIG' => 'site-value-one'])->mustRun();
        $this->assertStringContainsString($recreate, $this->events());
        $this->assertStringNotContainsString('drain', $this->events());

        // The config's environment value changes.
        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config + ['NNTMUX_SITE_CONFIG' => 'site-value-two'])->mustRun();
        $this->assertStringContainsString($recreate, $this->events());
        foreach (['/docker/.deploy.stamp', '/docker/.deploy.log', '/events'] as $file) {
            $this->assertStringNotContainsString('site-value', (string) file_get_contents($this->directory.$file));
        }

        // docker/.env supplies an unset variable; an unresolvable one fails closed.
        file_put_contents($this->directory.'/events', '');
        file_put_contents($this->directory.'/docker/.env', "NNTMUX_SITE_CONFIG=site-value-two\n");
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();
        $this->assertStringNotContainsString(' up -d', $this->events());
        unlink($this->directory.'/docker/.env');
        $plan = $this->make(['deploy-plan'], $config);
        $plan->run();
        $this->assertNotSame(0, $plan->getExitCode());
        $this->assertStringContainsString('cannot resolve environment variable NNTMUX_SITE_CONFIG behind config site', $plan->getErrorOutput());

        // A .env value Compose would interpolate cannot be hashed literally; a single-quoted one can.
        file_put_contents($this->directory.'/docker/.env', "NNTMUX_SITE_CONFIG=\"\${ROTATED}\"\n");
        $plan = $this->make(['deploy-plan'], $config);
        $plan->run();
        $this->assertNotSame(0, $plan->getExitCode());
        $this->assertStringContainsString('NNTMUX_SITE_CONFIG behind config site uses interpolation', $plan->getErrorOutput());
        file_put_contents($this->directory.'/docker/.env', "NNTMUX_SITE_CONFIG='\${literal}'\n");
        $this->make(['deploy-plan'], $config)->mustRun();

        // Double quotes decode escapes, single quotes do not: the same text is two values.
        file_put_contents($this->directory.'/docker/.env', "NNTMUX_SITE_CONFIG='a\\nb'\n");
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();
        file_put_contents($this->directory.'/docker/.env', "NNTMUX_SITE_CONFIG=\"a\\nb\"\n");
        file_put_contents($this->directory.'/events', '');
        $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], $config)->mustRun();
        $this->assertStringContainsString($recreate, $this->events());

        // A multi-line value cannot be read whole by the line parser.
        file_put_contents($this->directory.'/docker/.env', "NNTMUX_SITE_CONFIG='first line\nsecond line'\n");
        $plan = $this->make(['deploy-plan'], $config);
        $plan->run();
        $this->assertNotSame(0, $plan->getExitCode());
        $this->assertStringContainsString('multi-line', $plan->getErrorOutput());
    }

    public function test_service_definitions_no_fingerprint_can_represent_fail_closed(): void
    {
        $services = ['web' => [], 'horizon' => [], 'scheduler' => [], 'indexer' => [], 'proxy' => []];
        $definitions = [
            'build' => ['build' => ['context' => $this->directory.'/helper']],
            'network_mode' => ['network_mode' => 'service:proxy'],
            'ipc' => ['ipc' => 'container:abc123'],
            'pid' => ['pid' => 'service:proxy'],
            'volumes_from' => ['volumes_from' => ['proxy']],
        ];
        foreach ($definitions as $label => $definition) {
            $plan = $this->make(['deploy-plan'], ['NNTMUX_CONFIG_ID' => (string) json_encode(['services' => $services + ['helper' => $definition]], JSON_UNESCAPED_SLASHES)]);
            $plan->run();
            $this->assertNotSame(0, $plan->getExitCode(), $label);
            $this->assertStringContainsString('service helper', $plan->getErrorOutput(), $label);
            $this->assertStringContainsString('selective deployment cannot', $plan->getErrorOutput(), $label);
        }

        $process = $this->make(['deploy', 'DEPLOY_MIN_INTERVAL=0'], ['NNTMUX_CONFIG_ID' => (string) json_encode(['services' => $services + ['helper' => $definitions['build']]], JSON_UNESCAPED_SLASHES)]);
        $process->run();
        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringNotContainsString(' up -d', $this->events());

        // Compose would restart a dependent after its provider's update; deploy-app cannot.
        $plan = $this->make(['deploy-plan'], ['NNTMUX_CONFIG_ID' => (string) json_encode(['services' => ['web' => ['depends_on' => ['proxy' => ['condition' => 'service_started', 'restart' => true]]]] + $services], JSON_UNESCAPED_SLASHES)]);
        $plan->run();
        $this->assertNotSame(0, $plan->getExitCode());
        $this->assertStringContainsString('depends_on.proxy.restart', $plan->getErrorOutput());

        // Health and recreation checks inspect one container per app service.
        foreach ([['deploy' => ['replicas' => 2]], ['scale' => 3]] as $scaled) {
            $plan = $this->make(['deploy-plan'], ['NNTMUX_CONFIG_ID' => (string) json_encode(['services' => ['web' => $scaled] + $services], JSON_UNESCAPED_SLASHES)]);
            $plan->run();
            $this->assertNotSame(0, $plan->getExitCode());
            $this->assertStringContainsString('app service web runs more than one replica', $plan->getErrorOutput());
        }

        // node-exporter's `pid: host` names no other container.
        $this->make(['deploy-plan'], ['NNTMUX_CONFIG_ID' => (string) json_encode(['services' => $services + ['node-exporter' => ['pid' => 'host']]])])->mustRun();
    }

    /** Create the files composeJson() bind-mounts, older than every fixture container. */
    private function composeFiles(): void
    {
        foreach (['caddy/Caddyfile' => ":8080\n", 'redis/redis.conf' => "maxmemory 1gb\n"] as $file => $content) {
            @mkdir(dirname($this->directory.'/docker/config/'.$file), 0777, true);
            file_put_contents($this->directory.'/docker/config/'.$file, $content);
        }
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
