<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ForkingService;
use Illuminate\Console\Command;

class ProcessPostProcess extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'multiprocessing:postprocess
                            {type : Type: ama, add, ani, mov, nfo, tv, boo, mus, con, gam}
                            {renamed=false : For mov/tv: only post-process renamed releases (true/false)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Post-process releases using multiprocessing';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $type = $this->argument('type');
        $renamed = $this->argument('renamed');

        if (! \in_array($type, ['ama', 'add', 'ani', 'mov', 'nfo', 'tv', 'boo', 'mus', 'con', 'gam'], true)) {
            $this->error('Type must be one of: ama, add, ani, mov, nfo, tv, boo, mus, con, gam');
            $this->line('');
            $this->line('ama => Do amazon (books+music+console+games) processing in parallel');
            $this->line('boo => Do books processing');
            $this->line('mus => Do music processing');
            $this->line('con => Do console processing');
            $this->line('gam => Do games processing');
            $this->line('add => Do additional (rar|zip) processing');
            $this->line('ani => Do anime processing');
            $this->line('mov => Do movie processing');
            $this->line('nfo => Do NFO processing');
            $this->line('tv  => Do TV processing');

            return self::FAILURE;
        }

        try {
            $renamedOnly = $renamed === 'true' || $renamed === true;
            $service = new ForkingService;

            match ($type) {
                'ama' => $service->processAmazon(),
                'boo' => $service->processBooks(),
                'add' => $service->processAdditional(),
                'ani' => $service->processAnime(),
                'mov' => $service->processMovies($renamedOnly),
                'nfo' => $service->processNfo(),
                'tv' => $service->processTv($renamedOnly),
                'mus' => $service->processMusic(),
                'con' => $service->processConsoles(),
                'gam' => $service->processGames(),
            };

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
