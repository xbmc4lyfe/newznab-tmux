<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\IRCScraper;
use App\Services\Predb\Feeds\PredbFeedImporter;
use App\Services\Predb\Irc\IrcNetworks;
use App\Services\Predb\Stream\PredbStreamListener;
use App\Services\Predb\Stream\WebSocketClient;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class IrcScraperCommand extends Command
{
    /**
     * First restart delay in seconds; it doubles after each failed run up to MAX_RESTART_DELAY.
     */
    public const RESTART_DELAY = 30;

    public const MAX_RESTART_DELAY = 3600;

    /**
     * A child that ran at least this long counts as healthy, which resets its backoff.
     */
    public const HEALTHY_RUN_SECONDS = 600;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'irc:scrape
                            {--network= : Scrape one network or stream from irc_settings.networks (default: every enabled one)}
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
        $network = $this->option('network');
        $networks = is_string($network) && $network !== '' ? [$network] : IrcNetworks::enabled();

        if ($networks === []) {
            $this->error('No IRC networks are enabled in irc_settings.networks.');

            return self::FAILURE;
        }

        // Only IRC connections need a nickname; WebSocket streams run without one.
        if (config('irc_settings.scrape_irc_username') === '' && $this->needsIrcIdentity($networks)) {
            $this->error('ERROR! You must put a username in config/irc_settings.php');

            return self::FAILURE;
        }

        return count($networks) === 1 ? $this->scrape($networks[0]) : $this->supervise($networks);
    }

    /**
     * @param  list<string>  $networks
     */
    private function needsIrcIdentity(array $networks): bool
    {
        foreach ($networks as $network) {
            try {
                if (IrcNetworks::resolve($network)['type'] !== 'websocket') {
                    return true;
                }
            } catch (\InvalidArgumentException) {
                return true; // Unknown names fail later with a clearer error.
            }
        }

        return false;
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
            $profile = IrcNetworks::resolve($network);

            if ($profile['type'] === 'websocket') {
                (new PredbStreamListener(
                    new WebSocketClient((string) $profile['url'], max(1, (int) config('predb_feeds.timeout', 15)), (string) config('predb_feeds.user_agent', 'NNTmux-PreDB-Importer/1.0')),
                    $profile['format'],
                    app(PredbFeedImporter::class),
                    $silent,
                ))->run();
            }

            new IRCScraper($silent, $debug, $network);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            // Always reported: the supervisor relays it, and a quiet failure loop is hard to diagnose.
            $this->error("[{$network}] ".$e->getMessage());

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
        /** @var array<string, int> $startedAt */
        $startedAt = [];
        /** @var array<string, int> $failures */
        $failures = array_fill_keys($networks, 0);

        while (true) { // @phpstan-ignore while.alwaysTrue
            foreach ($networks as $network) {
                $process = $running[$network] ?? null;

                if ($process !== null && ! $process->isRunning()) {
                    $healthy = time() - ($startedAt[$network] ?? time()) >= self::HEALTHY_RUN_SECONDS;
                    $failures[$network] = $healthy ? 1 : $failures[$network] + 1;
                    $delay = self::restartDelay($failures[$network]);
                    $this->warn("[{$network}] scraper exited with code ".($process->getExitCode() ?? -1)."; restarting in {$delay}s.");
                    unset($running[$network]);
                    $restartAt[$network] = time() + $delay;
                }

                if (! isset($running[$network]) && time() >= $restartAt[$network]) {
                    $running[$network] = $this->startChild($network);
                    $startedAt[$network] = time();
                }
            }

            sleep(1);
        }
    }

    /**
     * Seconds to wait before the next start after $failures consecutive short runs (1 = first failure).
     */
    public static function restartDelay(int $failures): int
    {
        return (int) min(self::MAX_RESTART_DELAY, self::RESTART_DELAY * (2 ** max(0, min(16, $failures - 1))));
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
