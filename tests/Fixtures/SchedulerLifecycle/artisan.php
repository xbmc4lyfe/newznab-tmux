<?php

declare(strict_types=1);

use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\ScheduleWorkCommand;
use Illuminate\Console\Signals;
use Illuminate\Contracts\Events\Dispatcher as DispatcherContract;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Support\Carbon;
use Symfony\Component\Process\Process;

require getenv('SCHEDULER_AUTOLOAD') ?: '/app/vendor/autoload.php';

define('ARTISAN_BINARY', __FILE__);
Signals::resolveAvailabilityUsing(fn (): bool => extension_loaded('pcntl'));
$app = new Application(__DIR__);
$app->instance('env', 'testing');
$app->instance('config', new ConfigRepository(['app' => ['env' => 'testing'], 'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]]]));
$app->instance(DispatcherContract::class, new Dispatcher($app));

if (($argv[1] ?? '') === 'fixture:run') {
    $event = new Event(new CacheEventMutex(new CacheManager($app)), 'python3 '.escapeshellarg(__DIR__.'/fixture.py').' event-worker');
    $event->runInBackground();
    $event->run($app);
    exit(0);
}
if (($argv[1] ?? '') === 'schedule:finish') {
    file_put_contents(__DIR__.'/finished', 'finished');
    exit(0);
}

$console = new ConsoleApplication($app, $app->make(DispatcherContract::class), 'fixture');
$console->add(new class extends ScheduleWorkCommand
{
    protected function work($command): int
    {
        // Seed an immediate genuine Event execution; freeze at second 1 so the
        // inherited coordinator does not wait for the next wall-clock minute.
        Carbon::setTestNow(Carbon::now()->startOfMinute()->addSecond());
        file_put_contents(__DIR__.'/admissions', "started\n", FILE_APPEND);
        $execution = new Process([PHP_BINARY, __FILE__, 'fixture:run']);
        $execution->start();
        $this->executions[] = $execution;

        return parent::work($command);
    }
});
exit($console->run());
