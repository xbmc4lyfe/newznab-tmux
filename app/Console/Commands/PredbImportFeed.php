<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Predb\Feeds\Contracts\CompleteCatalogueSource;
use App\Services\Predb\Feeds\Contracts\PredbFeedSource;
use App\Services\Predb\Feeds\FeedRateLimitedException;
use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Services\Predb\Feeds\PredbFeedImporter;
use App\Services\Predb\Feeds\PredbFeedSourceFactory;
use App\Support\RustPipelineLibrary;
use Carbon\CarbonImmutable;
use FFI;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class PredbImportFeed extends Command
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_predb_import_parse_since(const char *value, size_t value_len, int64_t *amount);
        int64_t nntmux_predb_import_rate_limit_wait(const char *retry_after, size_t retry_after_len, const char *reset_at, size_t reset_at_len, int64_t default_seconds, int64_t now, uint8_t has_now);
        C;

    /**
     * Both option helpers stay on PHP: Carbon construction dominates parseSince() and the
     * single-call FFI overhead outweighs rateLimitWaitSeconds() (see
     * RustPredbFeedsAndStreamParityTest for the paired benchmark evidence).
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private static ?FFI\CData $sinceAmount = null;

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
    protected $description = 'Import release-title metadata from public PreDB feeds and catalogues';

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
        $keys = $requested === [] ? (array) config('predb_feeds.sources', []) : $requested;
        if ($requested === []) {
            $manualOnly = (array) config('predb_feeds.manual_only_sources', []);
            foreach ($keys as $key) {
                if (in_array($key, $manualOnly, true)) {
                    $this->warn('Skipping manual-only catalogue '.$key.'; select it explicitly with --source.');
                    Log::warning('Manual-only catalogue excluded from automatic polling', ['source' => $key]);
                }
            }
            $keys = array_values(array_diff($keys, $manualOnly));
        }

        try {
            $sources = $factory->make($keys);
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
            $visited = 0;
            $singlePage = in_array($source->key(), (array) config('predb_feeds.single_page_sources', []), true);
            $sourcePages = $singlePage ? 1 : $pages;

            try {
                for ($page = 1; $page <= $sourcePages; $page++) {
                    if ($page > 1) {
                        usleep(max(0, (int) config('predb_feeds.request_delay_ms', 1000)) * 1000);
                    }

                    $entries = $this->fetchWithBackoff($source, $page);

                    if ($entries === []) {
                        break;
                    }
                    $visited += count($entries);

                    foreach ($importer->import($entries, $dryRun) as $key => $count) {
                        $totals[$key] += $count;
                    }

                    if ($since !== null) {
                        $oldest = $this->oldestPredate($entries);
                        if ($oldest === null) {
                            // Undated entries (e.g. RSS without pubDate) cannot page back; stop, unverified.
                            break;
                        }
                        if ($oldest->lessThan($since)) {
                            $reachedCutoff = true;
                            break;
                        }
                    }
                }

                $succeeded++;

                if ($source instanceof CompleteCatalogueSource && $visited < $source->acceptedCount()) {
                    $status = 'incomplete: processed '.$visited.' of '.$source->acceptedCount().' catalogue entries; increase --pages';
                }

                if ($since !== null && ($singlePage || ! $reachedCutoff)) {
                    // Latest-only listings cannot verify historical coverage, even when a listing date crosses the cutoff.
                    // Other sources may have run out of history or hit --max-pages.
                    $status = $source instanceof CompleteCatalogueSource
                        ? 'incomplete: catalogue dates are unknown; --since cannot verify coverage'
                        : ($singlePage
                            ? 'incomplete: history paging is disabled for this source'
                            : 'incomplete: history ended before '.$since->toDateString());
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
     * Fetch one page, waiting and retrying when the source is throttled (HTTP 429 or a rate-limit envelope).
     *
     * @return list<PredbFeedEntry>
     */
    private function fetchWithBackoff(PredbFeedSource $source, int $page): array
    {
        $retries = max(0, (int) config('predb_feeds.rate_limit_retries', 5));

        for ($attempt = 0; ; $attempt++) {
            try {
                return $source->fetch($page);
            } catch (RequestException|FeedRateLimitedException $e) {
                $throttled = $e instanceof FeedRateLimitedException || $e->response->status() === 429;
                if (! $throttled || $attempt >= $retries) {
                    throw $e;
                }

                $response = $e instanceof RequestException ? $e->response : null;
                $wait = $this->rateLimitWaitSeconds(
                    $response?->header('Retry-After'),
                    $response?->header('X-RateLimit-Reset'),
                    (int) config('predb_feeds.rate_limit_wait_seconds', 60),
                );

                // Never retry before the server's reset; if that is too far away, give up on this run.
                $maxWait = max(0, (int) config('predb_feeds.rate_limit_max_wait_seconds', 900));
                if ($wait > $maxWait) {
                    throw new RuntimeException("rate limited for {$wait}s (more than the {$maxWait}s maximum wait)", 0, $e);
                }

                Log::info('PreDB feed rate limited; backing off', ['source' => $source->key(), 'page' => $page, 'wait_seconds' => $wait]);
                sleep($wait);
            }
        }
    }

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    private static function ffi(): ?FFI
    {
        self::$ffi ??= RustPipelineLibrary::resolveDomain('predb', self::FFI_CDEF) ?? false;

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * Seconds to wait before retrying a throttled request: Retry-After (delta-seconds or HTTP-date),
     * else an epoch X-RateLimit-Reset (xREL), else $default. The server's delay is never shortened.
     */
    private function rateLimitWaitSeconds(?string $retryAfter, ?string $resetAt, int $default): int
    {
        if (self::$useRustFfi) {
            $supported = false;
            $native = self::rateLimitWaitSecondsNative($retryAfter, $resetAt, $default, $supported);
            if ($supported && $native !== null) {
                return $native;
            }
        }

        return self::rateLimitWaitSecondsPhp($retryAfter, $resetAt, $default);
    }

    /**
     * Rust rateLimitWaitSeconds(); $supported is false for HTTP-dates other than a
     * weekday-consistent IMF-fixdate and for overlong numbers.
     */
    public static function rateLimitWaitSecondsNative(?string $retryAfter, ?string $resetAt, int $default, bool &$supported = false): ?int
    {
        $supported = false;
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $retryAfter = (string) $retryAfter;
        $resetAt = (string) $resetAt;
        try {
            // @phpstan-ignore method.notFound
            $wait = $ffi->nntmux_predb_import_rate_limit_wait($retryAfter, strlen($retryAfter), $resetAt, strlen($resetAt), $default, 0, 0);
            if ($wait === -2) {
                // @phpstan-ignore method.notFound
                $wait = $ffi->nntmux_predb_import_rate_limit_wait($retryAfter, strlen($retryAfter), $resetAt, strlen($resetAt), $default, CarbonImmutable::now()->getTimestamp(), 1);
            }
        } catch (Throwable) {
            return null;
        }
        if (! is_int($wait) || $wait < 0) {
            return null;
        }
        $supported = true;

        return $wait;
    }

    public static function rateLimitWaitSecondsPhp(?string $retryAfter, ?string $resetAt, int $default): int
    {
        $retryAfter = trim((string) $retryAfter);

        if ($retryAfter !== '' && ctype_digit($retryAfter)) {
            return (int) $retryAfter;
        }

        if ($retryAfter !== '') {
            try {
                return max(0, (int) ceil(CarbonImmutable::now()->diffInSeconds(CarbonImmutable::parse($retryAfter), false)));
            } catch (Throwable) {
                // Not an HTTP-date either.
            }
        }

        $resetAt = trim((string) $resetAt);
        if ($resetAt !== '' && ctype_digit($resetAt)) {
            return max(0, (int) $resetAt - CarbonImmutable::now()->getTimestamp());
        }

        return max(0, $default);
    }

    /**
     * @param  list<PredbFeedEntry>  $entries
     */
    private function oldestPredate(array $entries): ?CarbonImmutable
    {
        $dates = array_filter(array_map(static fn (PredbFeedEntry $entry): ?CarbonImmutable => $entry->pagingTime(), $entries));

        return $dates === [] ? null : min($dates);
    }

    /**
     * @return CarbonImmutable|false|null null when not given, false when invalid
     */
    private function parseSince(mixed $value): CarbonImmutable|false|null
    {
        if (self::$useRustFfi) {
            $supported = false;
            $native = self::parseSinceNative($value, $supported);
            if ($supported) {
                return $native;
            }
        }

        return self::parseSincePhp($value);
    }

    /**
     * Rust parseSince(); $supported is false for non-string values, non-ASCII text and overlong
     * offsets.
     */
    public static function parseSinceNative(mixed $value, bool &$supported = false): CarbonImmutable|false|null
    {
        $supported = false;
        if ($value === null) {
            $supported = true;

            return null;
        }
        $ffi = self::ffi();
        if ($ffi === null || ! is_string($value)) {
            return null;
        }
        try {
            self::$sinceAmount ??= $ffi->new('int64_t');
            // @phpstan-ignore method.notFound
            $kind = $ffi->nntmux_predb_import_parse_since($value, strlen($value), FFI::addr(self::$sinceAmount));
            // @phpstan-ignore property.notFound
            $amount = self::$sinceAmount->cdata;
        } catch (Throwable) {
            return null;
        }
        if (! is_int($amount)) {
            return null;
        }
        $result = match ($kind) {
            0 => null,
            1 => false,
            2 => CarbonImmutable::now()->subDays($amount),
            3 => CarbonImmutable::now()->subHours($amount),
            4 => CarbonImmutable::createFromTimestamp($amount, 'UTC'),
            default => -1,
        };
        if ($result === -1) {
            return null;
        }
        $supported = true;

        return $result;
    }

    /**
     * @return CarbonImmutable|false|null null when not given, false when invalid
     */
    public static function parseSincePhp(mixed $value): CarbonImmutable|false|null
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
