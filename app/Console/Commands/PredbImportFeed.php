<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Predb\Feeds\PredbFeedImporter;
use App\Services\Predb\Feeds\PredbFeedSourceFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class PredbImportFeed extends Command
{
    /**
     * @var string
     */
    protected $signature = 'predb:import-feed
                            {--source=* : Feed source key(s) to poll (default: predb_feeds.sources)}
                            {--pages=1 : Number of pages to fetch per source (newest first)}
                            {--dry-run : Fetch and report without writing to the database}';

    /**
     * @var string
     */
    protected $description = 'Import PREs from public PreDB JSON/RSS feeds into the predb table';

    public function handle(PredbFeedSourceFactory $factory, PredbFeedImporter $importer): int
    {
        $pages = max(1, (int) $this->option('pages'));
        $dryRun = (bool) $this->option('dry-run');
        /** @var list<string> $requested */
        $requested = array_values(array_filter((array) $this->option('source')));

        try {
            $sources = $factory->make($requested === [] ? null : $requested);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($sources === []) {
            $this->warn('No PreDB feed sources configured (PREDB_FEED_SOURCES).');

            return self::FAILURE;
        }

        $succeeded = 0;
        $rows = [];

        foreach ($sources as $source) {
            $totals = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
            $status = 'ok';

            try {
                for ($page = 1; $page <= $pages; $page++) {
                    $entries = $source->fetch($page);

                    if ($entries === []) {
                        break;
                    }

                    foreach ($importer->import($entries, $dryRun) as $key => $count) {
                        $totals[$key] += $count;
                    }
                }

                $succeeded++;
            } catch (Throwable $e) {
                $status = 'failed: '.$e->getMessage();
                Log::warning('PreDB feed source failed', ['source' => $source->key(), 'error' => $e->getMessage()]);
            }

            $rows[] = [$source->key(), $totals['inserted'], $totals['updated'], $totals['skipped'], $status];
        }

        if (! $this->option('quiet')) {
            $this->table(['Source', 'Inserted', 'Updated', 'Skipped', 'Status'], $rows);

            if ($dryRun) {
                $this->comment('Dry run: nothing was written.');
            }
        }

        return $succeeded > 0 ? self::SUCCESS : self::FAILURE;
    }
}
