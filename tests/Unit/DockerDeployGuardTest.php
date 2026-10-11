<?php

declare(strict_types=1);

namespace Tests\Unit;

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

        if ((new Process(['git', '--version']))->run() !== 0) {
            $this->markTestSkipped('git is not available.');
        }

        $this->dir = sys_get_temp_dir().'/deploy-guard-'.bin2hex(random_bytes(4));
        $this->repo = $this->dir.'/repo';
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
        $this->assertDirectoryDoesNotExist($this->dir.'/lock', 'the lock is released afterwards');
    }

    #[Test]
    public function a_second_deploy_is_refused_while_the_first_holds_the_lock(): void
    {
        $first = $this->guardProcess(['run', 'build', '--', 'sleep', '5'], ['DEPLOY_BY' => 'session-a']);
        $first->start();
        $this->waitFor(fn (): bool => is_dir($this->dir.'/lock/'));

        $second = $this->guard(['run', 'up', '--', 'sh', '-c', 'touch '.$this->dir.'/second-ran'], ['DEPLOY_BY' => 'session-b']);

        $this->assertSame(75, $second->getExitCode());
        $this->assertStringContainsString('by=session-a', $second->getErrorOutput());
        $this->assertFileDoesNotExist($this->dir.'/second-ran');

        $first->stop(0);
    }

    #[Test]
    public function a_stale_lock_is_replaced(): void
    {
        mkdir($this->dir.'/lock');
        file_put_contents($this->dir.'/lock/owner', "pid=999999\nby=gone\n");

        $process = $this->guard(['run', 'up', '--', 'true']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('stale lock', $process->getErrorOutput());
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
    private function guardProcess(array $arguments, array $env = []): Process
    {
        return new Process(
            ['sh', dirname(__DIR__, 2).'/docker/bin/deploy-guard', ...$arguments],
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
