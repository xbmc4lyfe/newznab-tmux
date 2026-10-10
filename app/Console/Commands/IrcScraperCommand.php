<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\IRCScraper;
use App\Services\Predb\Irc\IrcNetworks;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class IrcScraperCommand extends Command
{
    /**
     * Seconds to wait before restarting a network whose scraper exited.
     */
    private const RESTART_DELAY = 30;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'irc:scrape
                            {--network= : Scrape one network from irc_settings.networks (default: every enabled network)}
                            {--debug : Turn on debug (shows sent/received messages from the socket)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scrape IRC for PRE information';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (config('irc_settings.scrape_irc_username') === '') {
            $this->error('ERROR! You must put a username in config/irc_settings.php');

            return self::FAILURE;
        }

        $network = $this->option('network');
        if (is_string($network) && $network !== '') {
            return $this->scrape($network);
        }

        $networks = IrcNetworks::enabled();
        if ($networks === []) {
            $this->error('No IRC networks are enabled in irc_settings.networks.');

            return self::FAILURE;
        }

        return count($networks) === 1 ? $this->scrape($networks[0]) : $this->supervise($networks);
    }

    private function scrape(string $network): int
    {
        // Use Laravel's built-in quiet mode for silent operation
        $silent = (bool) $this->option('quiet');
        $debug = (bool) $this->option('debug');

        if (! $silent) {
            $this->info("Starting IRC Scraper ({$network})...");
            if ($debug) {
                $this->warn('Debug mode enabled');
            }
        }

        try {
            new IRCScraper($silent, $debug, $network);

            return self::SUCCESS;
        } catch (\Exception $e) {
            if (! $silent) {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }
    }

    /**
     * Run one child scraper per network and restart any that exit.
     *
     * @param  list<string>  $networks
     */
    private function supervise(array $networks): int
    {
        if (! $this->option('quiet')) {
            $this->info('Starting IRC scrapers for: '.implode(', ', $networks));
        }

        /** @var array<string, Process> $running */
        $running = [];
        /** @var array<string, int> $restartAt */
        $restartAt = array_fill_keys($networks, 0);

        while (true) { // @phpstan-ignore while.alwaysTrue
            foreach ($networks as $network) {
                $process = $running[$network] ?? null;

                if ($process !== null && ! $process->isRunning()) {
                    $this->warn("[{$network}] scraper exited with code ".($process->getExitCode() ?? -1).'; restarting in '.self::RESTART_DELAY.'s.');
                    unset($running[$network]);
                    $restartAt[$network] = time() + self::RESTART_DELAY;
                }

                if (! isset($running[$network]) && time() >= $restartAt[$network]) {
                    $running[$network] = $this->startChild($network);
                }
            }

            sleep(1);
        }
    }

    private function startChild(string $network): Process
    {
        $command = [PHP_BINARY, base_path('artisan'), 'irc:scrape', '--network='.$network];
        if ($this->option('quiet')) {
            $command[] = '--quiet';
        }
        if ($this->option('debug')) {
            $command[] = '--debug';
        }

        $process = new Process($command, base_path(), null, null, null);
        $process->start(function (string $type, string $buffer) use ($network): void {
            foreach (preg_split('/\R/', rtrim($buffer)) ?: [] as $line) {
                if ($line !== '') {
                    $this->line("[{$network}] {$line}");
                }
            }
        });

        return $process;
    }
}
