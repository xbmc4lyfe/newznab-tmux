<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Tmux\TmuxTaskRunner;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class TmuxFixNamesScheduleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Cache::flush();
    }

    #[Test]
    public function recent_passes_always_run_and_full_backlog_passes_at_most_once_per_interval(): void
    {
        config(['tmux.fix_names.full_backlog' => true, 'tmux.fix_names.full_backlog_interval_minutes' => 60, 'tmux.fix_names.predbft' => true]);

        $first = $this->commands();
        $second = $this->commands();

        foreach ([3, 5, 7, 9, 11, 13, 15, 17, 19] as $level) {
            $this->assertContainsLevel($level, $first);
            $this->assertContainsLevel($level, $second);
        }
        foreach ([4, 6, 8, 10, 12, 14, 16, 18, 20] as $level) {
            $this->assertContainsLevel($level, $first);
            $this->assertNotContainsLevel($level, $second);
        }
        $this->assertTrue($this->hasPredbft($first));
        $this->assertTrue($this->hasPredbft($second));
    }

    #[Test]
    public function the_extra_passes_can_be_switched_off(): void
    {
        config(['tmux.fix_names.full_backlog' => false, 'tmux.fix_names.predbft' => false]);

        $commands = $this->commands();

        $this->assertCount(9, $commands);
        $this->assertNotContainsLevel(4, $commands);
        $this->assertFalse($this->hasPredbft($commands));
    }

    /**
     * @return list<string>
     */
    private function commands(): array
    {
        return (new ReflectionMethod(TmuxTaskRunner::class, 'fixNamesCommands'))->invoke(new TmuxTaskRunner('fixnames-test'));
    }

    /**
     * @param  list<string>  $commands
     */
    private function assertContainsLevel(int $level, array $commands): void
    {
        $this->assertNotEmpty(array_filter($commands, static fn (string $c): bool => preg_match("/releases:fix-names'? {$level} /", $c) === 1), "level {$level} missing");
    }

    /**
     * @param  list<string>  $commands
     */
    private function assertNotContainsLevel(int $level, array $commands): void
    {
        $this->assertEmpty(array_filter($commands, static fn (string $c): bool => preg_match("/releases:fix-names'? {$level} /", $c) === 1), "level {$level} unexpected");
    }

    /**
     * @param  list<string>  $commands
     */
    private function hasPredbft(array $commands): bool
    {
        return array_filter($commands, static fn (string $c): bool => str_contains($c, 'multiprocessing:fixrelnames') && str_contains($c, 'predbft')) !== [];
    }
}
