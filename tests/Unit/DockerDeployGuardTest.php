<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * docker/bin/deploy-guard: one deploy at a time, a deploy log, and no rebuild when nothing changed.
 */
final class DockerDeployGuardTest extends TestCase
{
    private string $dir;

    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/deploy-guard-'.bin2hex(random_bytes(4));
        $this->repo = $this->dir.'/repo';

        if ((new Process(['git', '--version']))->run() !== 0) {
            $this->markTestSkipped('git is not available.');
        }

        mkdir($this->repo, 0777, true);
        $this->git('init', '-q');
        $this->git('config', 'user.email', 'test@example.com');
        $this->git('config', 'user.name', 'Test');
        file_put_contents($this->repo.'/app.txt', "one\n");
        $this->git('add', '.');
        $this->git('commit', '-q', '-m', 'first');
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->dir]))->run();

        parent::tearDown();
    }

    /** @return iterable<string, array{int, int}> */
    public static function interruptions(): iterable
    {
        yield 'SIGTERM' => [15, 130];
        yield 'SIGHUP (dropped SSH session)' => [1, 129];
        yield 'SIGINT' => [2, 130];
    }

    #[Test]
    public function the_lock_is_held_until_every_payload_descendant_exits(): void
    {
        $ready = $this->dir.'/payload-ready';
        $done = $this->dir.'/grandchild-done';
        // The payload dies on TERM, but its grandchild (like a nested docker build) keeps running.
        $payload = 'sh -c "trap \"\" TERM; touch '.$ready.'; sleep 2; touch '.$done.'" & wait';
        $process = $this->guardProcess(['run', 'deploy', '--', 'sh', '-c', $payload]);
        $process->start();
        $this->waitFor(fn (): bool => file_exists($ready));
        $process->signal(15);
        $this->waitFor(fn (): bool => ! $process->isRunning());

        $this->assertFileExists($done, 'the guard returned before the grandchild finished');
        $this->assertFalse(is_link($this->dir.'/lock'));
    }

    #[Test]
    public function a_closed_stdin_still_runs_the_payload(): void
    {
        foreach (['sh', 'dash', 'bash'] as $shell) {
            if ((new Process(['sh', '-c', 'command -v '.$shell]))->run() !== 0) {
                continue;
            }
            $ran = $this->dir.'/ran-'.$shell;
            $process = new Process(['sh', '-c', 'exec "$0" "$@" <&-', $shell, dirname(__DIR__, 2).'/docker/bin/deploy-guard', 'run', 'resume-scheduler', '--', 'sh', '-c', 'touch '.escapeshellarg($ran)], null, [
                'DEPLOY_LOCK_DIR' => $this->dir.'/lock', 'DEPLOY_LOG' => $this->dir.'/deploy.log', 'DEPLOY_STAMP' => $this->dir.'/stamp', 'DEPLOY_REPO' => $this->repo,
            ]);
            $process->mustRun();
            $this->assertFileExists($ran, $shell);
        }
    }

    #[Test]
    public function the_payload_reads_the_guards_stdin_under_every_posix_shell(): void
    {
        foreach (['sh', 'dash', 'bash'] as $shell) {
            if ((new Process(['sh', '-c', 'command -v '.$shell]))->run() !== 0) {
                continue;
            }
            $out = $this->dir.'/stdin-'.$shell;
            $process = $this->guardProcess(['run', 'resume-scheduler', '--', 'sh', '-c', 'cat > '.escapeshellarg($out)], [], $shell);
            $process->setInput("recovery script\n");
            $process->mustRun();
            $this->assertSame("recovery script\n", (string) file_get_contents($out), $shell);
        }
    }

    #[Test]
    #[DataProvider('interruptions')]
    public function a_signal_to_the_guard_reaches_the_payload_and_waits_for_its_recovery(int $signal, int $code): void
    {
        $ready = $this->dir.'/command-started';
        $restored = $this->dir.'/payload-restored';
        $payload = 'trap "sleep 0.5; touch '.escapeshellarg($restored).'; exit 3" INT TERM HUP; touch '.escapeshellarg($ready).'; while :; do sleep 0.1; done';
        $process = $this->guardProcess(['run', 'deploy', '--', 'sh', '-c', $payload]);
        $process->start();
        $this->waitFor(fn (): bool => file_exists($ready));
        $process->signal($signal);
        $this->waitFor(fn (): bool => ! $process->isRunning());

        // The guard released ownership only after the payload finished its own recovery.
        $this->assertFileExists($restored);
        $this->assertSame($code, $process->getExitCode());
        $this->assertFalse(is_link($this->dir.'/lock'));
    }

    #[Test]
    #[DataProvider('interruptions')]
    public function an_interrupted_run_closes_its_attempt_and_releases_the_lock(int $signal, int $code): void
    {
        $ready = $this->dir.'/command-started';
        $process = $this->guardProcess(['run', 'deploy', '--', 'sh', '-c', 'touch '.escapeshellarg($ready).'; sleep 2']);
        $process->start();
        $this->waitFor(fn (): bool => file_exists($ready));
        $process->signal($signal);
        $this->waitFor(fn (): bool => ! $process->isRunning());

        $log = (string) file_get_contents($this->dir.'/deploy.log');
        $this->assertStringContainsString('result=started', $log);
        $this->assertStringContainsString("result=finished rc={$code} (interrupted)", $log);
        $this->assertSame(substr_count($log, 'result=started'), substr_count($log, 'result=finished'));
        $this->assertFalse(is_link($this->dir.'/lock'), 'the lock is released after an interruption');
    }

    #[Test]
    public function it_runs_the_command_and_logs_who_deployed(): void
    {
        $process = $this->guard(['run', 'up', '--', 'sh', '-c', 'echo ran > '.$this->dir.'/marker'], ['DEPLOY_BY' => 'session-a']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertFileExists($this->dir.'/marker');
        $log = (string) file_get_contents($this->dir.'/deploy.log');
        $this->assertStringContainsString('up by=session-a', $log);
        $this->assertStringContainsString('result=started', $log);
        $this->assertStringContainsString('result=finished rc=0', $log);
        $this->assertFalse(is_link($this->dir.'/lock'), 'the lock is released afterwards');
    }

    #[Test]
    public function a_missing_lock_parent_is_rejected_before_mutation(): void
    {
        $this->assertInvalidLockParentIsRejected($this->dir.'/missing');
    }

    #[Test]
    public function a_non_directory_lock_parent_is_rejected_before_mutation(): void
    {
        $parent = $this->dir.'/regular-file';
        file_put_contents($parent, 'not a directory');

        $this->assertInvalidLockParentIsRejected($parent);
    }

    #[Test]
    public function a_directory_at_the_lock_path_is_preserved_and_refuses_execution(): void
    {
        mkdir($this->dir.'/lock');
        file_put_contents($this->dir.'/lock/sentinel', 'preserve');

        $process = $this->guard(['run', 'up', '--', 'touch', $this->dir.'/payload-ran'], ['DEPLOY_BY' => 's']);

        $this->assertSame(75, $process->getExitCode(), (string) json_encode([
            'stderr' => $process->getErrorOutput(),
            'payload_ran' => file_exists($this->dir.'/payload-ran'),
            'lock_entries' => scandir($this->dir.'/lock'),
        ]));
        $this->assertFileDoesNotExist($this->dir.'/payload-ran');
        $this->assertSame(['.', '..', 'sentinel'], scandir($this->dir.'/lock'));
        $this->assertSame('preserve', file_get_contents($this->dir.'/lock/sentinel'));
    }

    private function assertInvalidLockParentIsRejected(string $parent): void
    {
        $bin = $this->dir.'/bin';
        mkdir($bin);
        $attempts = $this->dir.'/mutation-attempts';
        // Record the real guard's mutation attempts without writing an erroneous root-level lock.
        file_put_contents($bin.'/mkdir', "#!/bin/sh\nprintf '%s\\n' \"$@\" >> ".escapeshellarg($attempts)."\nexit 1\n");
        chmod($bin.'/mkdir', 0755);

        $process = $this->guard(['run', 'up', '--', 'touch', $this->dir.'/payload-ran'], [
            'DEPLOY_LOCK_DIR' => $parent.'/lock',
            'PATH' => $bin.':'.getenv('PATH'),
        ]);

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertFileDoesNotExist($attempts, 'invalid parent must be rejected before acquiring any other lock');
        $this->assertFileDoesNotExist($this->dir.'/payload-ran');
        $this->assertFileDoesNotExist($this->dir.'/deploy.log');
    }

    #[Test]
    public function a_second_deploy_is_refused_while_the_first_holds_the_lock(): void
    {
        $first = $this->guardProcess(['run', 'build', '--', 'sleep', '5'], ['DEPLOY_BY' => 'session-a']);
        $first->start();
        $this->waitFor(fn (): bool => is_link($this->dir.'/lock'));

        $second = $this->guard(['run', 'up', '--', 'sh', '-c', 'touch '.$this->dir.'/second-ran'], ['DEPLOY_BY' => 'session-b']);

        $this->assertSame(75, $second->getExitCode());
        $this->assertStringContainsString('by=session-a', $second->getErrorOutput());
        $this->assertFileDoesNotExist($this->dir.'/second-ran');

        $first->stop(0);
    }

    #[Test]
    public function a_stale_lock_is_replaced(): void
    {
        symlink('pid=999999 by=gone target=up since=then', $this->dir.'/lock');

        $process = $this->guard(['run', 'up', '--', 'true']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('stale lock', $process->getErrorOutput());
    }

    #[Test]
    public function sessions_racing_for_one_stale_lock_never_deploy_at_the_same_time(): void
    {
        symlink('pid=999999 by=gone target=up since=then', $this->dir.'/lock');

        // Each deploy enters a critical section; mkdir fails if another one is inside it.
        $critical = $this->dir.'/critical';
        $command = ['run', 'up', '--', 'sh', '-c', "mkdir $critical || touch {$this->dir}/overlap; sleep 1; rmdir $critical 2>/dev/null; true"];

        $processes = [];
        foreach (range(1, 6) as $i) {
            $processes[$i] = $this->guardProcess($command, ['DEPLOY_BY' => "session-$i"]);
            $processes[$i]->start();
        }
        $codes = [];
        foreach ($processes as $process) {
            $process->wait();
            $codes[] = $process->getExitCode();
        }

        $this->assertFileDoesNotExist($this->dir.'/overlap', 'two deploys ran at once');
        $this->assertContains(0, $codes, 'one session should have deployed');
        $this->assertEmpty(array_diff($codes, [0, 75]), 'every other session is refused, never crashes');
    }

    #[Test]
    public function a_guarded_command_can_run_another_under_the_same_lock(): void
    {
        $inner = 'sh '.dirname(__DIR__, 2).'/docker/bin/deploy-guard run inner -- sh -c "echo inner > '.$this->dir.'/inner; test -L '.$this->dir.'/lock"';

        $process = $this->guard(['run', 'deploy', '--', 'sh', '-c', $inner]);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertFileExists($this->dir.'/inner');
        $log = (string) file_get_contents($this->dir.'/deploy.log');
        $this->assertStringContainsString('inner by=', $log);
        $this->assertFalse(is_link($this->dir.'/lock'), 'the outer guard releases the lock once');
    }

    #[Test]
    public function a_recovery_hold_blocks_forced_runs_before_callbacks_and_stale_lock_reclamation(): void
    {
        file_put_contents($this->dir.'/lock.recovery-hold', "v1\nowner_sha256=".str_repeat('a', 64)."\noperation=recovery-a\n");
        symlink('pid=999999 by=gone target=up since=then', $this->dir.'/lock');
        $callback = $this->dir.'/config-check';
        file_put_contents($callback, "#!/bin/sh\ntouch ".escapeshellarg($this->dir.'/callback-ran')."\necho config\n");
        chmod($callback, 0755);

        $process = $this->guard(['run', 'up', '--', 'touch', $this->dir.'/payload-ran'], [
            'FORCE' => '1',
            'SKIP_IF_UNCHANGED' => '1',
            'DEPLOY_CONFIG_COMMAND' => $callback,
        ]);

        $this->assertSame(75, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('recovery hold', $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->dir.'/callback-ran');
        $this->assertFileDoesNotExist($this->dir.'/payload-ran');
        $this->assertSame('pid=999999 by=gone target=up since=then', readlink($this->dir.'/lock'));
        $this->assertFileExists($this->dir.'/lock.recovery-hold');
    }

    #[Test]
    public function malformed_directory_and_dangling_symlink_holds_also_block_new_runs(): void
    {
        $hold = $this->dir.'/lock.recovery-hold';
        foreach (['partial', 'unreadable', 'directory', 'symlink'] as $kind) {
            if ($kind === 'directory') {
                mkdir($hold);
            } elseif ($kind === 'symlink') {
                symlink($this->dir.'/missing', $hold);
            } else {
                file_put_contents($hold, 'v1');
                if ($kind === 'unreadable') {
                    chmod($hold, 0000);
                }
            }

            $process = $this->guard(['run', 'up', '--', 'touch', $this->dir.'/payload-ran']);
            $this->assertSame(75, $process->getExitCode(), $kind.': '.$process->getErrorOutput());
            $this->assertFileDoesNotExist($this->dir.'/payload-ran');
            $this->assertFalse(is_link($this->dir.'/lock'));
            if ($kind === 'directory') {
                rmdir($hold);
            } else {
                unlink($hold);
            }
        }
    }

    #[Test]
    public function an_exact_live_nested_owner_can_continue_without_removing_its_recovery_hold(): void
    {
        $writer = $this->recoveryHoldWriter();
        $inner = $this->guardShell('run inner -- touch '.escapeshellarg($this->dir.'/inner-ran'));
        $command = $writer.' && '.$this->guardShell('verify-owner').' && '.$inner.' && test -L '.escapeshellarg($this->dir.'/lock')
            .' && test -f '.escapeshellarg($this->dir.'/lock.recovery-hold');

        $process = $this->guard(['run', 'deploy', '--', 'sh', '-c', $command], ['DEPLOY_RECOVERY_OPERATION' => 'recovery-a']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertFileExists($this->dir.'/inner-ran');
        $this->assertFileExists($this->dir.'/lock.recovery-hold');
        $this->assertFalse(is_link($this->dir.'/lock'));
        $context = json_decode((string) file_get_contents($this->dir.'/owner-context.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertMatchesRegularExpression('/ generation=[a-f0-9]{32}$/', $context['lock']);
        $this->assertSame(hash('sha256', $context['lock']."\n"), $context['DEPLOY_GUARD_OWNER_SHA256']);
        $this->assertSame(75, $this->guard(['run', 'up', '--', 'true'])->getExitCode(), 'owner exit never clears the hold');
    }

    #[Test]
    public function nested_runs_reject_wrong_owner_operation_and_noncanonical_hold_bytes(): void
    {
        foreach (['owner', 'operation', 'lock-path', 'extra-byte', 'truncated', 'symlink'] as $kind) {
            $writer = $this->recoveryHoldWriter();
            $mutation = match ($kind) {
                'owner' => 'export DEPLOY_GUARD_OWNER_SHA256='.str_repeat('0', 64),
                'operation' => 'export DEPLOY_RECOVERY_OPERATION=another-operation',
                'lock-path' => 'export DEPLOY_LOCK_DIR='.escapeshellarg($this->dir.'/other-lock'),
                'extra-byte' => 'printf x >> '.escapeshellarg($this->dir.'/lock.recovery-hold'),
                'truncated' => 'printf v1 > '.escapeshellarg($this->dir.'/lock.recovery-hold'),
                'symlink' => 'mv '.escapeshellarg($this->dir.'/lock.recovery-hold').' '.escapeshellarg($this->dir.'/saved-hold')
                    .' && ln -s '.escapeshellarg($this->dir.'/saved-hold').' '.escapeshellarg($this->dir.'/lock.recovery-hold'),
            };
            $inner = $this->guardShell('run inner -- touch '.escapeshellarg($this->dir.'/inner-ran'));
            $process = $this->guard(['run', 'deploy', '--', 'sh', '-c', $writer.' && '.$mutation.' && '.$inner], [
                'DEPLOY_RECOVERY_OPERATION' => 'recovery-a',
            ]);

            $this->assertSame(75, $process->getExitCode(), $kind.': '.$process->getErrorOutput());
            $this->assertFileDoesNotExist($this->dir.'/inner-ran');
            $this->assertTrue(file_exists($this->dir.'/lock.recovery-hold') || is_link($this->dir.'/lock.recovery-hold'));
            unlink($this->dir.'/lock.recovery-hold');
        }
    }

    #[Test]
    public function copied_owner_environment_does_not_make_a_sibling_process_a_nested_guard(): void
    {
        $writer = $this->recoveryHoldWriter();
        $ready = $this->dir.'/ready';
        $finish = $this->dir.'/finish';
        $first = $this->guardProcess(['run', 'deploy', '--', 'sh', '-c',
            $writer.' && touch '.escapeshellarg($ready).' && while [ ! -f '.escapeshellarg($finish).' ]; do sleep 0.05; done',
        ], ['DEPLOY_RECOVERY_OPERATION' => 'recovery-a']);
        $first->start();
        try {
            $this->waitFor(fn (): bool => file_exists($ready));
            $context = json_decode((string) file_get_contents($this->dir.'/owner-context.json'), true, 512, JSON_THROW_ON_ERROR);
            unset($context['lock']);
            $second = $this->guard(['run', 'up', '--', 'touch', $this->dir.'/sibling-ran'], $context);
            $this->assertSame(75, $second->getExitCode(), $second->getErrorOutput());
            $this->assertFileDoesNotExist($this->dir.'/sibling-ran');
            $this->assertTrue(is_link($this->dir.'/lock'));
        } finally {
            touch($finish);
            $first->wait();
        }
    }

    #[Test]
    public function a_hold_published_during_acquisition_is_checked_before_the_command_runs(): void
    {
        $bin = $this->dir.'/bin';
        mkdir($bin);
        $ln = trim((new Process(['sh', '-c', 'command -v ln']))->mustRun()->getOutput());
        $hold = $this->dir.'/lock.recovery-hold';
        $wrapper = "#!/bin/sh\n".escapeshellarg($ln).' "$@" || exit $?'."\nprintf 'v1\\n' > ".escapeshellarg($hold)."\n";
        file_put_contents($bin.'/ln', $wrapper);
        chmod($bin.'/ln', 0755);

        $process = $this->guard(['run', 'up', '--', 'touch', $this->dir.'/payload-ran'], ['PATH' => $bin.':'.getenv('PATH')]);

        $this->assertSame(75, $process->getExitCode(), $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->dir.'/payload-ran');
        $this->assertFileExists($hold);
        $this->assertFalse(is_link($this->dir.'/lock'), 'the rejected invocation releases only its own acquired lock');
    }

    #[Test]
    public function a_hold_published_by_a_preflight_callback_blocks_the_payload(): void
    {
        $callback = $this->dir.'/config-check';
        file_put_contents($callback, "#!/bin/sh\nprintf 'v1\\n' > ".escapeshellarg($this->dir.'/lock.recovery-hold')."\necho config\n");
        chmod($callback, 0755);

        $process = $this->guard(['run', 'up', '--', 'touch', $this->dir.'/payload-ran'], ['DEPLOY_CONFIG_COMMAND' => $callback]);

        $this->assertSame(75, $process->getExitCode(), $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->dir.'/payload-ran');
        $this->assertFileExists($this->dir.'/lock.recovery-hold');
        $this->assertFalse(is_link($this->dir.'/lock'));
    }

    #[Test]
    public function verifying_ownership_without_an_inherited_owner_does_not_acquire_a_lock(): void
    {
        $process = $this->guard(['verify-owner']);

        $this->assertSame(75, $process->getExitCode());
        $this->assertFalse(is_link($this->dir.'/lock'));
        $this->assertFileDoesNotExist($this->dir.'/deploy.log');
    }

    #[Test]
    public function a_crashed_owner_leaves_a_hold_that_stale_lock_reclamation_cannot_bypass(): void
    {
        $ready = $this->dir.'/ready';
        $finish = $this->dir.'/finish';
        $first = $this->guardProcess(['run', 'deploy', '--', 'sh', '-c',
            $this->recoveryHoldWriter().' && touch '.escapeshellarg($ready)
                .' && while [ ! -f '.escapeshellarg($finish).' ]; do sleep 0.05; done',
        ], ['DEPLOY_RECOVERY_OPERATION' => 'recovery-a']);
        $first->start();
        try {
            $this->waitFor(fn (): bool => file_exists($ready));
            $owner = readlink($this->dir.'/lock');
            $first->signal(9);
        } finally {
            touch($finish);
            $first->wait();
        }

        $process = $this->guard(['run', 'up', '--', 'touch', $this->dir.'/payload-ran'], ['FORCE' => '1']);

        $this->assertSame(75, $process->getExitCode(), $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->dir.'/payload-ran');
        $this->assertSame($owner, readlink($this->dir.'/lock'));
        $this->assertFileExists($this->dir.'/lock.recovery-hold');
    }

    #[Test]
    public function persistent_recovery_metadata_does_not_change_the_guarded_build_fingerprint(): void
    {
        file_put_contents($this->repo.'/.gitignore', file_get_contents(dirname(__DIR__, 2).'/docker/.gitignore'));
        $this->git('add', '.gitignore');
        $this->git('commit', '-q', '-m', 'ignore deployment state');
        $hold = $this->repo.'/.deploy.lock.recovery-hold';
        $command = $this->recoveryHoldWriter().' && '.$this->guardShell('verify-expected')
            .' && '.$this->guardShell('run inner -- true').' && rm '.escapeshellarg($hold);

        $process = $this->guard(['run', 'build', '--', 'sh', '-c', $command], [
            'DEPLOY_LOCK_DIR' => $this->repo.'/.deploy.lock',
            'DEPLOY_RECOVERY_OPERATION' => 'recovery-a',
            'STAMP_ON_SUCCESS' => '1',
        ]);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertFileExists($this->dir.'/stamp');
        $this->assertStringContainsString("docker/.deploy.lock.recovery-hold\n", (string) file_get_contents(dirname(__DIR__, 2).'/.dockerignore'));
    }

    #[Test]
    public function an_exiting_owner_does_not_remove_a_replaced_lock_generation(): void
    {
        $replacement = 'pid=1 by=replacement target=up since=now generation='.str_repeat('f', 32);
        $command = 'rm '.escapeshellarg($this->dir.'/lock').' && ln -s '.escapeshellarg($replacement).' '.escapeshellarg($this->dir.'/lock');

        $process = $this->guard(['run', 'up', '--', 'sh', '-c', $command]);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame($replacement, readlink($this->dir.'/lock'), 'a late trap must not unlink a different owner');
    }

    #[Test]
    public function an_orphaned_release_child_cannot_unlink_a_new_owners_lock(): void
    {
        $bin = $this->dir.'/bin';
        mkdir($bin);
        $rm = trim((new Process(['sh', '-c', 'command -v rm']))->mustRun()->getOutput());
        $ready = $this->dir.'/removal-ready';
        $allow = $this->dir.'/allow-removal';
        $done = $this->dir.'/removal-done';
        $secondReady = $this->dir.'/second-ready';
        $secondFinish = $this->dir.'/second-finish';
        // Pause the actual removal child after the guard compared its ownership record.
        file_put_contents($bin.'/rm', "#!/bin/sh\ntouch ".escapeshellarg($ready)
            ."\nwhile [ ! -f ".escapeshellarg($allow)." ]; do sleep 0.05; done\n"
            .escapeshellarg($rm).' "$@"'."\ntouch ".escapeshellarg($done)."\n");
        chmod($bin.'/rm', 0755);
        $first = $this->guardProcess(['run', 'up', '--', 'true'], ['PATH' => $bin.':'.getenv('PATH')]);
        $second = $this->guardProcess(['run', 'up', '--', 'sh', '-c',
            'touch '.escapeshellarg($secondReady).' && while [ ! -f '.escapeshellarg($secondFinish).' ]; do sleep 0.05; done',
        ]);
        $first->start();
        try {
            $this->waitFor(fn (): bool => file_exists($ready));
            $first->signal(9);
            $this->waitFor(fn (): bool => ! $first->isRunning());
            $second->start();
            $this->waitFor(fn (): bool => file_exists($secondReady) || ! $second->isRunning());
            $replacement = is_link($this->dir.'/lock') ? readlink($this->dir.'/lock') : false;
            touch($allow);
            $this->waitFor(fn (): bool => file_exists($done));
            if (file_exists($secondReady)) {
                $this->assertSame($replacement, is_link($this->dir.'/lock') ? readlink($this->dir.'/lock') : false,
                    'the orphaned removal must not unlink a newly acquired generation');
            }
            $this->assertSame(75, $second->getExitCode(), $second->getErrorOutput());
            $this->assertFileDoesNotExist($secondReady);
            $this->assertDirectoryExists($this->dir.'/lock.claim', 'an interrupted mutation requires explicit recovery');
        } finally {
            touch($allow);
            touch($secondFinish);
            if ($second->isStarted()) {
                $second->wait();
            }
            $first->wait();
            $this->waitFor(fn (): bool => file_exists($done));
        }
    }

    #[Test]
    public function an_old_mutation_claim_is_not_reclaimed_based_on_age(): void
    {
        $owner = 'pid=999999 by=gone target=up since=then';
        symlink($owner, $this->dir.'/lock');
        mkdir($this->dir.'/lock.claim');
        touch($this->dir.'/lock.claim', time() - 7200);

        $process = $this->guard(['run', 'up', '--', 'touch', $this->dir.'/payload-ran']);

        $this->assertSame(75, $process->getExitCode(), $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->dir.'/payload-ran');
        $this->assertSame($owner, readlink($this->dir.'/lock'));
        $this->assertDirectoryExists($this->dir.'/lock.claim');
    }

    private function guardShell(string $arguments): string
    {
        return 'sh '.escapeshellarg(dirname(__DIR__, 2).'/docker/bin/deploy-guard').' '.$arguments;
    }

    private function recoveryHoldWriter(): string
    {
        $path = $this->dir.'/write-hold.php';
        file_put_contents($path, <<<'PHP'
<?php
$lock = getenv('DEPLOY_LOCK_DIR');
$context = ['lock' => readlink($lock)];
foreach (['DEPLOY_GUARD_HELD', 'DEPLOY_GUARD_OWNER_SHA256', 'DEPLOY_GUARD_LOCK', 'DEPLOY_RECOVERY_OPERATION'] as $key) {
    $context[$key] = getenv($key) ?: '';
}
file_put_contents($lock.'.recovery-hold', "v1\nowner_sha256=".$context['DEPLOY_GUARD_OWNER_SHA256']."\noperation=".$context['DEPLOY_RECOVERY_OPERATION']."\n");
file_put_contents($argv[1], json_encode($context, JSON_THROW_ON_ERROR));
PHP);

        return escapeshellarg(PHP_BINARY).' '.escapeshellarg($path).' '.escapeshellarg($this->dir.'/owner-context.json');
    }

    #[Test]
    public function moving_bytes_between_untracked_files_counts_as_a_change(): void
    {
        $build = ['run', 'build', '--', 'sh', '-c', 'echo x >> '.$this->dir.'/builds'];
        $flags = ['SKIP_IF_UNCHANGED' => '1', 'STAMP_ON_SUCCESS' => '1'];
        file_put_contents($this->repo.'/a.txt', 'a');
        file_put_contents($this->repo.'/b.txt', 'bc');

        $this->guard($build, $flags);

        file_put_contents($this->repo.'/a.txt', 'ab');
        file_put_contents($this->repo.'/b.txt', 'c');
        $this->guard($build, $flags);

        $this->assertCount(2, file($this->dir.'/builds'), 'the same bytes split differently is a different tree');
    }

    #[Test]
    public function an_untracked_file_whose_name_starts_with_a_dash_is_fingerprinted(): void
    {
        $build = ['run', 'build', '--', 'sh', '-c', 'echo x >> '.$this->dir.'/builds'];
        $flags = ['SKIP_IF_UNCHANGED' => '1', 'STAMP_ON_SUCCESS' => '1'];
        file_put_contents($this->repo.'/-notes', "a\n");

        $this->guard($build, $flags);
        file_put_contents($this->repo.'/-notes', "b\n");
        $this->guard($build, $flags);

        $this->assertCount(2, file($this->dir.'/builds'));
    }

    #[Test]
    public function retargeting_an_untracked_symlink_counts_as_a_change(): void
    {
        $build = ['run', 'build', '--', 'sh', '-c', 'echo x >> '.$this->dir.'/builds'];
        $flags = ['SKIP_IF_UNCHANGED' => '1', 'STAMP_ON_SUCCESS' => '1'];
        file_put_contents($this->repo.'/one.txt', "same\n");
        file_put_contents($this->repo.'/two.txt', "same\n");
        symlink('one.txt', $this->repo.'/current');

        $this->guard($build, $flags);
        unlink($this->repo.'/current');
        symlink('two.txt', $this->repo.'/current');
        $this->guard($build, $flags);

        $this->assertCount(2, file($this->dir.'/builds'), 'same bytes at the end, different link, so rebuilt');
    }

    #[Test]
    public function verify_refuses_when_the_tree_changed_since_the_last_build(): void
    {
        $flags = ['SKIP_IF_UNCHANGED' => '1', 'STAMP_ON_SUCCESS' => '1'];

        $this->assertSame(0, $this->guard(['verify'])->getExitCode(), 'nothing built yet, nothing to contradict');

        $this->guard(['run', 'build', '--', 'true'], $flags);
        $this->assertSame(0, $this->guard(['verify'])->getExitCode());

        file_put_contents($this->repo.'/app.txt', "changed after the build\n");
        $process = $this->guard(['verify']);

        $this->assertSame(76, $process->getExitCode());
        $this->assertStringContainsString('out of date', $process->getErrorOutput());
    }

    #[Test]
    public function a_lock_whose_pid_was_reused_by_another_process_is_stale(): void
    {
        // PID 1 exists, but it did not start when the lock owner did.
        symlink('pid=1 by=old target=up since=then start=Mon_Jan__1_00:00:00_2001', $this->dir.'/lock');

        $process = $this->guard(['run', 'up', '--', 'true']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('stale lock', $process->getErrorOutput());
    }

    #[Test]
    public function a_lock_holder_is_recognized_by_its_recorded_start_time(): void
    {
        $first = $this->guardProcess(['run', 'build', '--', 'sleep', '3']);
        $first->start();
        $this->waitFor(fn (): bool => is_link($this->dir.'/lock'));

        $this->assertMatchesRegularExpression('/ start=\S+/', (string) readlink($this->dir.'/lock'));
        $this->assertSame(75, $this->guard(['run', 'up', '--', 'true'])->getExitCode());

        $first->stop(0);
    }

    #[Test]
    public function changing_only_the_mode_of_an_untracked_file_counts_as_a_change(): void
    {
        $build = ['run', 'build', '--', 'sh', '-c', 'echo x >> '.$this->dir.'/builds'];
        $flags = ['SKIP_IF_UNCHANGED' => '1', 'STAMP_ON_SUCCESS' => '1'];
        file_put_contents($this->repo.'/tool.sh', "echo hi\n");
        chmod($this->repo.'/tool.sh', 0644);

        $this->guard($build, $flags);
        chmod($this->repo.'/tool.sh', 0755);
        $this->guard($build, $flags);

        $this->assertCount(2, file($this->dir.'/builds'), 'chmod +x changes the image, so it rebuilds');
    }

    #[Test]
    public function a_lock_owned_by_a_process_we_cannot_signal_counts_as_held(): void
    {
        // PID 1 is alive and, for a non-root user, owned by someone else.
        symlink('pid=1 by=init target=up since=boot', $this->dir.'/lock');

        $process = $this->guard(['run', 'up', '--', 'true']);

        $this->assertSame(75, $process->getExitCode());
        $this->assertStringContainsString('pid=1', $process->getErrorOutput());
    }

    #[Test]
    public function editing_an_untracked_file_without_renaming_it_counts_as_a_change(): void
    {
        $build = ['run', 'build', '--', 'sh', '-c', 'echo x >> '.$this->dir.'/builds'];
        $flags = ['SKIP_IF_UNCHANGED' => '1', 'STAMP_ON_SUCCESS' => '1'];
        file_put_contents($this->repo.'/notes.txt', "a\n");

        $this->guard($build, $flags);
        $this->guard($build, $flags);
        $this->assertCount(1, file($this->dir.'/builds'), 'unchanged, so skipped');

        file_put_contents($this->repo.'/notes.txt', "b\n");
        $this->guard($build, $flags);
        $this->assertCount(2, file($this->dir.'/builds'), 'same name, new contents, so rebuilt');
    }

    #[Test]
    public function the_command_status_is_passed_on_and_a_failed_run_is_not_stamped(): void
    {
        $process = $this->guard(['run', 'build', '--', 'sh', '-c', 'exit 3'], ['STAMP_ON_SUCCESS' => '1']);

        $this->assertSame(3, $process->getExitCode());
        $this->assertFileDoesNotExist($this->dir.'/stamp');
        $this->assertStringContainsString('rc=3', (string) file_get_contents($this->dir.'/deploy.log'));
    }

    #[Test]
    public function a_rebuild_is_skipped_until_the_tree_changes_or_it_is_forced(): void
    {
        $build = ['run', 'build', '--', 'sh', '-c', 'echo x >> '.$this->dir.'/builds'];
        $flags = ['SKIP_IF_UNCHANGED' => '1', 'STAMP_ON_SUCCESS' => '1'];
        $builds = fn (): int => is_file($this->dir.'/builds') ? count(file($this->dir.'/builds')) : 0;

        $this->guard($build, $flags);
        $this->assertSame(1, $builds());

        $skipped = $this->guard($build, $flags);
        $this->assertSame(0, $skipped->getExitCode());
        $this->assertSame(1, $builds(), 'nothing changed, so no rebuild');
        $this->assertStringContainsString('result=skipped', (string) file_get_contents($this->dir.'/deploy.log'));

        file_put_contents($this->repo.'/app.txt', "two\n");
        $this->guard($build, $flags);
        $this->assertSame(2, $builds(), 'a changed file rebuilds');

        $this->guard($build, $flags);
        $this->assertSame(2, $builds());

        $this->guard($build, $flags + ['FORCE' => '1']);
        $this->assertSame(3, $builds(), 'FORCE=1 always rebuilds');
    }

    #[Test]
    public function a_tree_that_changes_during_the_run_is_not_stamped(): void
    {
        $build = ['run', 'build', '--', 'sh', '-c', 'echo changed > '.$this->repo.'/app.txt'];
        $flags = ['SKIP_IF_UNCHANGED' => '1', 'STAMP_ON_SUCCESS' => '1'];

        $first = $this->guard($build, $flags);

        $this->assertSame(76, $first->getExitCode(), 'a stale result must not let the next step run');
        $this->assertFileDoesNotExist($this->dir.'/stamp');
        $this->assertStringContainsString('tree-changed-during-run', (string) file_get_contents($this->dir.'/deploy.log'));

        // With the tree now settled, the next build runs and is stamped.
        $second = $this->guard(['run', 'build', '--', 'true'], $flags);
        $this->assertSame(0, $second->getExitCode());
        $this->assertFileExists($this->dir.'/stamp');
    }

    #[Test]
    public function documentation_and_test_changes_do_not_invalidate_the_runtime_fingerprint(): void
    {
        $build = ['run', 'build', '--', 'sh', '-c', 'echo x >> '.$this->dir.'/builds'];
        $flags = ['SKIP_IF_UNCHANGED' => '1', 'STAMP_ON_SUCCESS' => '1'];
        $builds = fn (): int => is_file($this->dir.'/builds') ? count(file($this->dir.'/builds')) : 0;

        $this->guard($build, $flags);
        $this->assertSame(1, $builds());

        mkdir($this->repo.'/docs', 0777, true);
        mkdir($this->repo.'/.ai/rules', 0777, true);
        mkdir($this->repo.'/tests/Feature', 0777, true);
        file_put_contents($this->repo.'/README.md', "# Updated docs\n");
        file_put_contents($this->repo.'/AGENTS.md', "# Agent rules\n");
        file_put_contents($this->repo.'/docs/guide.md', "Guide\n");
        file_put_contents($this->repo.'/.ai/rules/index.md', "Rule\n");
        file_put_contents($this->repo.'/tests/Feature/ExampleTest.php', "<?php\n");
        file_put_contents($this->repo.'/phpstan.neon', "parameters:\n");

        // Uncommitted non-runtime changes must skip
        $this->guard($build, $flags);
        $this->assertSame(1, $builds());

        // Committed non-runtime changes (changing git HEAD) must also skip
        $this->git('add', '.');
        $this->git('commit', '-q', '-m', 'docs and tests only');
        $this->guard($build, $flags);
        $this->assertSame(1, $builds());
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $env
     */
    private function guard(array $arguments, array $env = []): Process
    {
        $process = $this->guardProcess($arguments, $env);
        $process->run();

        return $process;
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $env
     */
    private function guardProcess(array $arguments, array $env = [], string $shell = 'sh'): Process
    {
        return new Process(
            [$shell, dirname(__DIR__, 2).'/docker/bin/deploy-guard', ...$arguments],
            null,
            $env + [
                'DEPLOY_LOCK_DIR' => $this->dir.'/lock',
                'DEPLOY_LOG' => $this->dir.'/deploy.log',
                'DEPLOY_STAMP' => $this->dir.'/stamp',
                'DEPLOY_REPO' => $this->repo,
            ],
        );
    }

    private function git(string ...$arguments): void
    {
        $process = new Process(['git', ...$arguments], $this->repo);
        $process->mustRun();
    }

    private function waitFor(callable $condition): void
    {
        $deadline = microtime(true) + 5;
        while (! $condition() && microtime(true) < $deadline) {
            usleep(50_000);
        }
        $this->assertTrue($condition(), 'timed out waiting');
    }
}
