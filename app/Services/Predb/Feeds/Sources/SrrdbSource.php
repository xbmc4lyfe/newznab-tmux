<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Services\Predb\Feeds\PredbFeedImporter;
use App\Support\RustPipelineLibrary;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use FFI;
use RuntimeException;
use Throwable;

/**
 * srrDB search API, newest first (`/v1/search/order:date-desc`), 45 results per page paged with `/skip:N`.
 *
 * Row shape: {release, date (SRR upload time, "Y-m-d H:i:s" in srrDB's local time), size (bytes), hasNFO, hasSRS, isForeign}
 */
final class SrrdbSource extends HttpFeedSource
{
    /** srrDB returns a fixed 45 results per search page. */
    public const int PAGE_SIZE = 45;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustReleaseModelDetailsFeedSourceParityTest.php is below 1.5x.
     */
    public const bool RUST_FFI_DEFAULT = false;

    /**
     * Wave 46.2 production default for summarizeDateBatch() is Rust: the paired benchmark in
     * tests/Unit/RustPredbFeedsSrrdb3dsdbXrelNswdbImporterW46ParityTest.php measures 5.23-5.63x mean and 3.78-5.13x
     * p99 on common pages and 5.57-5.78x mean and 4.14-5.45x p99 on uncommon batches. PHP stays the fallback when the
     * library is unavailable.
     */
    public const bool W462_RUST_FFI_DEFAULT = true;

    private const string SRRDB_PAGE_FFI_CDEF = <<<'C'
        ssize_t nntmux_w374_srrdb_page_plan(
            int64_t page,
            const char *endpoint,
            size_t endpoint_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /** intdiv(PHP_INT_MAX, PAGE_SIZE) + 1: the last page whose skip offset fits an int. */
    private const int SRRDB_MAX_PLANNED_PAGE = 204963823041217241;

    private const string SRRDB_FIELD_SEPARATOR = "\x1F";

    private static ?bool $srrdbUseRustFfi = null;

    private static FFI|false|null $srrdbFfi = null;

    private static ?bool $w462RustFfiOverride = null;

    private static FFI|false|null $w462RustFfiHandle = null;

    /** @var array<string, DateTimeZone|false> Zones whose local dates may skip Carbon's parser. */
    private static array $zones = [];

    public function __construct(
        string $endpoint,
        int $pageSize = 100,
        int $timeout = 15,
        string $userAgent = 'NNTmux-PreDB-Importer/1.0',
        private readonly string $timezone = 'Europe/Brussels',
    ) {
        parent::__construct($endpoint, $pageSize, $timeout, $userAgent);
    }

    public function key(): string
    {
        return 'srrdb';
    }

    public static function pageUrl(string $endpoint, int $page): string
    {
        $endpoint = rtrim($endpoint, '/');

        return $page > 1 ? $endpoint.'/skip:'.(($page - 1) * self::PAGE_SIZE) : $endpoint;
    }

    public function fetch(int $page = 1): array
    {
        $response = $this->http()->get(self::pageUrl($this->endpoint, max(1, $page)))->throw();
        $native = PredatabaseSource::nativeJsonFields($response->body(), 4);
        if ($native !== null) {
            return $this->nativeEntries($native[1]);
        }

        $rows = $response->json('results');
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new RuntimeException('srrDB returned an unexpected response (no results list).');
        }

        return $this->parseRows($rows);
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return list<PredbFeedEntry>
     */
    public function parseRows(array $rows): array
    {
        $entries = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['release'] ?? null) || trim($row['release']) === '') {
                continue;
            }

            // srrDB's `date` is when the SRR was added (possibly long after the PRE) and `size` can
            // describe only an NFO, so neither is stored as PRE data; the date only drives paging.
            $entries[] = new PredbFeedEntry(
                title: trim($row['release']),
                source: 'srrdb',
                listedAt: $this->parseDate($row['date'] ?? null),
            );
        }

        return $entries;
    }

    /**
     * @param  list<string>  $fields  PredatabaseSource::nativeJsonFields() output (one empty header field).
     * @return list<PredbFeedEntry>
     */
    private function nativeEntries(array $fields): array
    {
        $zone = ! CarbonImmutable::hasTestNow() ? (self::$zones[$this->timezone] ??= in_array($this->timezone, DateTimeZone::listIdentifiers(), true) ? new DateTimeZone($this->timezone) : false) : false;
        $entries = [];
        for ($i = 1, $count = count($fields); $i < $count; $i += 7) {
            $date = $fields[$i + 4];
            $entries[] = new PredbFeedEntry($fields[$i], 'srrdb', listedAt: $date === '' ? null : $this->nativeDate($date, $zone));
        }

        return $entries;
    }

    /**
     * parseDate() for a trimmed date; "!" marks the canonical "Y-m-d H:i:s" shape, which the native
     * parser reads exactly as Carbon's createFromFormat() does.
     */
    private function nativeDate(string $date, DateTimeZone|false $zone): ?CarbonImmutable
    {
        if ($date[0] === '!' && $zone !== false) {
            $local = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', substr($date, 1), $zone);
            $timestamp = $local === false ? -1 : $local->getTimestamp();
            if ($timestamp >= 0 && $timestamp <= 253402300799) {
                return PredatabaseSource::nativeTime((string) $timestamp, named: true);
            }
        }

        return $this->parseDate(substr($date, 1));
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d H:i:s', trim($value), $this->timezone)?->utc();
        } catch (Throwable) {
            return null;
        }
    }

    public static function useRustFfi(?bool $enabled = true): void
    {
        self::$srrdbUseRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$srrdbUseRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function resetRustFfi(): void
    {
        self::$srrdbUseRustFfi = null;
        self::$srrdbFfi = null;
    }

    /**
     * Plans the request for a page: the page clamped to 1 and to the last page whose skip offset
     * fits an int, that offset and the pageUrl() it produces.
     *
     * @return array{url: string, page: int, skip: int, clamped: bool}
     */
    public static function planSrrdbPage(string $endpoint, int $page): array
    {
        return self::usesRustFfi() ? self::planSrrdbPageNative($endpoint, $page) : self::planSrrdbPagePhp($endpoint, $page);
    }

    /**
     * @return array{url: string, page: int, skip: int, clamped: bool}
     */
    private static function planSrrdbPageNative(string $endpoint, int $page): array
    {
        $output = self::callSrrdbNative([$page, $endpoint, strlen($endpoint)], strlen($endpoint) + 64);
        // The URL is the last field and may itself contain the separator.
        $fields = $output === null ? [] : explode(self::SRRDB_FIELD_SEPARATOR, $output, 4);
        if (count($fields) !== 4) {
            return self::planSrrdbPagePhp($endpoint, $page);
        }

        return ['url' => $fields[3], 'page' => (int) $fields[0], 'skip' => (int) $fields[1], 'clamped' => $fields[2] === '1'];
    }

    /**
     * @return array{url: string, page: int, skip: int, clamped: bool}
     */
    private static function planSrrdbPagePhp(string $endpoint, int $page): array
    {
        $planned = max(1, min(self::SRRDB_MAX_PLANNED_PAGE, $page));

        return [
            'url' => self::pageUrl($endpoint, $planned),
            'page' => $planned,
            'skip' => ($planned - 1) * self::PAGE_SIZE,
            'clamped' => $planned !== $page,
        ];
    }

    /**
     * @param  list<int|string>  $args
     */
    private static function callSrrdbNative(array $args, int $hint): ?string
    {
        self::$srrdbFfi ??= RustPipelineLibrary::resolveDomain('metadata_w374', self::SRRDB_PAGE_FFI_CDEF) ?? false;
        if (self::$srrdbFfi === false) {
            return null;
        }
        $ffi = self::$srrdbFfi;

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $hint, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w374_srrdb_page_plan(...[...$args, $buffer, $capacity]);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w374_srrdb_page_plan(...[...$args, $buffer, $capacity]);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return null;
        }
    }

    /** Force the Wave 46.2 helper onto Rust (true) or PHP (false); null restores W462_RUST_FFI_DEFAULT. */
    public static function useW462RustFfi(?bool $enable = true): void
    {
        self::$w462RustFfiOverride = $enable;
    }

    public static function usesW462RustFfi(): bool
    {
        return self::$w462RustFfiOverride ?? self::W462_RUST_FFI_DEFAULT;
    }

    public static function resetW462RustFfi(): void
    {
        self::$w462RustFfiOverride = null;
        self::$w462RustFfiHandle = null;
    }

    /**
     * Summarize row dates as parseDate() reads them: non-strings and dates that trim() to '' are undated; the rest
     * are canonical (a real "Y-m-d H:i:s" calendar date and time) or malformed, counting those trim() changed and
     * the distinct canonical days.
     *
     * @param  array<array-key, mixed>  $dates
     * @return array{total: int, undated: int, canonical: int, malformed: int, padded: int, distinct_days: int}
     */
    public static function summarizeDateBatch(array $dates): array
    {
        return (self::usesW462RustFfi() ? self::summarizeDateBatchNative($dates) : null)
            ?? self::summarizeDateBatchPhp($dates);
    }

    /**
     * @param  array<array-key, mixed>  $dates
     * @return array{total: int, undated: int, canonical: int, malformed: int, padded: int, distinct_days: int}
     */
    public static function summarizeDateBatchPhp(array $dates): array
    {
        $summary = ['total' => \count($dates), 'undated' => 0, 'canonical' => 0, 'malformed' => 0, 'padded' => 0, 'distinct_days' => 0];
        $days = [];
        foreach ($dates as $date) {
            $value = \is_string($date) ? trim($date) : '';
            if ($value === '') {
                $summary['undated']++;

                continue;
            }
            if ($value !== $date) {
                $summary['padded']++;
            }
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/D', $value, $parts) === 1
                && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
                && (int) $parts[4] < 24 && (int) $parts[5] < 60 && (int) $parts[6] < 60) {
                $summary['canonical']++;
                $days[substr($value, 0, 10)] = true;
            } else {
                $summary['malformed']++;
            }
        }
        $summary['distinct_days'] = \count($days);

        return $summary;
    }

    /**
     * @param  array<array-key, mixed>  $dates
     * @return array{total: int, undated: int, canonical: int, malformed: int, padded: int, distinct_days: int}|null
     */
    public static function summarizeDateBatchNative(array $dates): ?array
    {
        $values = PredbFeedImporter::w462Summary(self::w462RustFfi(), 0, $dates, 6);
        if ($values === null) {
            return null;
        }
        [$total, $undated, $canonical, $malformed, $padded, $days] = $values;
        if ($total !== \count($dates) || min($values) < 0 || $undated + $canonical + $malformed !== $total
            || $padded > $canonical + $malformed || $days > $canonical || ($days > 0) !== ($canonical > 0)) {
            return null;
        }

        return ['total' => $total, 'undated' => $undated, 'canonical' => $canonical, 'malformed' => $malformed, 'padded' => $padded,
            'distinct_days' => $days];
    }

    private static function w462RustFfi(): ?FFI
    {
        self::$w462RustFfiHandle ??= RustPipelineLibrary::resolveDomain(PredbFeedImporter::W462_FFI_DOMAIN, PredbFeedImporter::W462_FFI_CDEF) ?? false;

        return self::$w462RustFfiHandle === false ? null : self::$w462RustFfiHandle;
    }
}
