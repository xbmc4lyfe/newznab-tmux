<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Predb\Feeds\Contracts\PredbFeedSource;
use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Services\Predb\Feeds\PredbFeedImporter;
use App\Services\Predb\Feeds\PredbFeedSourceFactory;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
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
                            {--since= : History mode: page back until entries are older than this (14d, 36h or a Y-m-d date)}
                            {--max-pages= : Cap on pages per source in history mode (default predb_feeds.max_pages)}
                            {--dry-run : Fetch and report without writing to the database}';

    /**
     * @var string
     */
    protected $description = 'Import PREs from public PreDB JSON/RSS feeds into the predb table';

    public function handle(PredbFeedSourceFactory $factory, PredbFeedImporter $importer): int
    {
        $since = $this->parseSince($this->option('since'));
        if ($since === false) {
            $this->error('Invalid --since value; use e.g. 14d, 36h or a date such as 2026-09-25.');

            return self::FAILURE;
        }

        $pages = $since === null
            ? max(1, (int) $this->option('pages'))
            : max(1, (int) ($this->option('max-pages') ?? config('predb_feeds.max_pages', 2000)));
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

            $reachedCutoff = false;

            try {
                for ($page = 1; $page <= $pages; $page++) {
                    if ($page > 1) {
                        usleep(max(0, (int) config('predb_feeds.request_delay_ms', 1000)) * 1000);
                    }

                    $entries = $this->fetchWithBackoff($source, $page);

                    if ($entries === []) {
                        break;
                    }

                    foreach ($importer->import($entries, $dryRun) as $key => $count) {
                        $totals[$key] += $count;
                    }

                    if ($since !== null && $this->reachedCutoff($entries, $since)) {
                        $reachedCutoff = true;
                        break;
                    }
                }

                $succeeded++;

                if ($since !== null && ! $reachedCutoff) {
                    // The source ran out of history (or --max-pages was hit) before the cutoff.
                    $status = 'incomplete: history ended before '.$since->toDateString();
                    Log::warning('PreDB history import incomplete', ['source' => $source->key(), 'since' => $since->toDateTimeString()]);
                }
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

    /**
     * Fetch one page, waiting and retrying when the source answers HTTP 429.
     *
     * @return list<PredbFeedEntry>
     */
    private function fetchWithBackoff(PredbFeedSource $source, int $page): array
    {
        $retries = max(0, (int) config('predb_feeds.rate_limit_retries', 5));

        for ($attempt = 0; ; $attempt++) {
            try {
                return $source->fetch($page);
            } catch (RequestException $e) {
                if ($e->response->status() !== 429 || $attempt >= $retries) {
                    throw $e;
                }

                $wait = $this->retryAfterSeconds($e->response->header('Retry-After'), (int) config('predb_feeds.rate_limit_wait_seconds', 60));
                Log::info('PreDB feed rate limited; backing off', ['source' => $source->key(), 'page' => $page, 'wait_seconds' => $wait]);
                sleep($wait);
            }
        }
    }

    /**
     * Seconds to wait for a Retry-After header (delta-seconds or HTTP-date); the server's delay is
     * never shortened. Falls back to $default when the header is missing or invalid.
     */
    private function retryAfterSeconds(?string $header, int $default): int
    {
        $header = trim((string) $header);

        if ($header !== '' && ctype_digit($header)) {
            return (int) $header;
        }

        if ($header !== '') {
            try {
                return max(0, (int) ceil(CarbonImmutable::now()->diffInSeconds(CarbonImmutable::parse($header), false)));
            } catch (Throwable) {
                // Not an HTTP-date either.
            }
        }

        return max(0, $default);
    }

    /**
     * @param  list<PredbFeedEntry>  $entries
     */
    private function reachedCutoff(array $entries, CarbonImmutable $since): bool
    {
        $dates = array_filter(array_map(static fn (PredbFeedEntry $entry): ?CarbonImmutable => $entry->predate, $entries));

        // Sources without dates (e.g. RSS) cannot page back; stop after the first page.
        return $dates === [] || min($dates)->lessThan($since);
    }

    /**
     * @return CarbonImmutable|false|null null when not given, false when invalid
     */
    private function parseSince(mixed $value): CarbonImmutable|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim((string) $value);
        if (preg_match('/^(\d+)\s*([dh])$/i', $value, $match) === 1) {
            return strtolower($match[2]) === 'd'
                ? CarbonImmutable::now()->subDays((int) $match[1])
                : CarbonImmutable::now()->subHours((int) $match[1]);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        // Strict: reject dates that would be normalised (e.g. 2026-02-31).
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');

        return $date !== null && $date->format('Y-m-d') === $value ? $date : false;
    }
}
