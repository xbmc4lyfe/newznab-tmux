<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Services\Predb\Feeds\FeedRateLimitedException;
use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Support\RustPipelineLibrary;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
use FFI;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use ReflectionMethod;
use RuntimeException;
use Throwable;

/**
 * predataba.se search API (newest first).
 *
 * The API returns ten rows per request, ignores `page`, and pages only through the `next_page_id`
 * cursor. Anonymous clients may read five requests deep; an API key lifts that limit. One page of
 * this source chains up to `pageSize / 10` requests, and later pages continue the same cursor.
 *
 * Row shape: {rlsname, section, size (MB), files, ctime (unix), status, grp, has_nfo, traces}.
 * Only status 0 has been observed, so nuke status is left to the other feeds.
 */
final class PredatabaseSource extends HttpFeedSource
{
    public const ROWS_PER_REQUEST = 10;

    public const ANONYMOUS_REQUEST_LIMIT = 5;

    private const MAX_EMPTY_CONTINUATION_REQUESTS = 5;

    private const NATIVE_CDEF = <<<'C'
        ssize_t nntmux_predb_json_feed_parse(const char *json, size_t json_len, uint32_t source, uint64_t limit, char *out_buf, size_t out_cap);
        C;

    /** Latest supported Unix time (9999-12-31 23:59:59 UTC) for the cloned-epoch Carbon fast path. */
    private const NATIVE_MAX_TIMESTAMP = 253402300799;

    /**
     * Wave 45.2 production default for summarizeRlsnameBatch() is PHP: the paired benchmark in
     * tests/Unit/RustPredbFeedsNzedbPredatabaseRssSceneArchiveSasW45ParityTest.php measures only 1.01-1.06x mean
     * and 0.87-1.07x p99 on common pages (1.07-1.12x mean, 0.95-1.31x p99 uncommon): trim() and cached string
     * hashes leave PHP little work for the marshaled batch to save.
     */
    public const bool W452_RUST_FFI_DEFAULT = false;

    /** Wave 45.2 export shared by the nZEDb archive, predataba.se, RSS, scene SQL and SeriousAboutSound sources. */
    public const string W452_FFI_CDEF = <<<'C'
        ssize_t nntmux_w452_predb_feed_source_batch(
            uint32_t operation,
            uint32_t flags,
            const char *blob,
            size_t blob_len,
            const char *lens,
            size_t lens_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    public const string W452_FFI_DOMAIN = 'predb_feeds_w452';

    /** Native input cap for the blob and for the length table. */
    public const int W452_MAX_INPUT = 16777216;

    /** Length sentinel for a null or non-string item. */
    public const int W452_NULL = 0xFFFFFFFF;

    /** Length sentinel for both slots of an invalid scene SQL row. */
    public const int W452_INVALID = 0xFFFFFFFE;

    /** Operation bit for a NUL-separated blob of strings without a length table. */
    public const int W452_DELIMITED = 0x100;

    private static ?bool $w452RustFfiOverride = null;

    private static FFI|false|null $w452RustFfiHandle = null;

    private static FFI|false|null $native = null;

    /** @var (Closure(int): CarbonImmutable)|null */
    private static ?Closure $epoch = null;

    /** @var (Closure(int): CarbonImmutable)|null */
    private static ?Closure $namedEpoch = null;

    private ?string $cursor = null;

    private int $lastPage = 0;

    private int $requests = 0;

    private ?int $pendingPage = null;

    private int $pendingRequests = 0;

    /** @var array<string, true> */
    private array $visitedCursors = [];

    private ?int $oldestTimestamp = null;

    /** @var list<PredbFeedEntry> */
    private array $pendingEntries = [];

    /** @var list<PredbFeedEntry> */
    private array $completedEntries = [];

    public function __construct(
        string $endpoint,
        int $pageSize = 100,
        int $timeout = 15,
        string $userAgent = 'NNTmux-PreDB-Importer/1.0',
        private readonly string $apiKey = '',
    ) {
        parent::__construct($endpoint, $pageSize, $timeout, $userAgent);
    }

    public function key(): string
    {
        return 'predatabase';
    }

    public function fetch(int $page = 1): array
    {
        $page = max(1, $page);

        if ($this->pendingPage !== null) {
            if ($page !== $this->pendingPage) {
                throw new RuntimeException('predataba.se has an unfinished page; retry it before fetching the next page.');
            }
        } else {
            if ($page === $this->lastPage) {
                return $this->completedEntries;
            }
            if ($page !== $this->lastPage + 1) {
                throw new RuntimeException('predataba.se pages through a cursor; fetch pages in order starting at 1.');
            }
            if ($this->lastPage > 0 && $this->cursor === null) {
                return [];
            }
            $this->pendingPage = $page;
            $this->pendingRequests = 0;
            $this->pendingEntries = [];
        }

        $requestsPerPage = max(1, intdiv($this->pageSize + self::ROWS_PER_REQUEST - 1, self::ROWS_PER_REQUEST));

        while ($this->pendingRequests < $requestsPerPage || ($this->pendingEntries === [] && $this->cursor !== null)) {
            if ($this->pendingRequests >= $requestsPerPage + self::MAX_EMPTY_CONTINUATION_REQUESTS) {
                throw new RuntimeException('predataba.se returned too many nonterminal pages without eligible titles; history coverage is incomplete.');
            }
            if ($this->apiKey === '' && $this->requests >= self::ANONYMOUS_REQUEST_LIMIT) {
                $this->cursor = null;
                break;
            }

            [$rows, $nextCursor, $nativeEntries, $nativeTimes] = $this->request($this->cursor);
            $entries = $nativeEntries ?? $this->parseRows($rows);
            if ($nextCursor !== null && ($nextCursor === $this->cursor || isset($this->visitedCursors[':'.$nextCursor]))) {
                throw new RuntimeException('predataba.se returned a repeated history cursor.');
            }
            $oldestTimestamp = $nativeTimes !== null ? $this->nativeChronology(...$nativeTimes) : $this->validateChronology($rows);
            if ($this->cursor !== null) {
                $this->visitedCursors[':'.$this->cursor] = true;
            }
            array_push($this->pendingEntries, ...$entries);
            $this->oldestTimestamp = $oldestTimestamp;
            $this->cursor = $nextCursor;
            $this->pendingRequests++;

            if ($this->cursor === null) {
                $this->cursor = null;
                break;
            }
        }

        $this->lastPage = $page;
        $this->completedEntries = $this->pendingEntries;
        $this->pendingPage = null;
        $this->pendingEntries = [];

        return $this->completedEntries;
    }

    /**
     * The rows and the cursor for the next request; when Rust decoded the body, the entries and the
     * newest and oldest positive row times instead of the rows.
     *
     * @return array{0: array<int, mixed>, 1: ?string, 2: list<PredbFeedEntry>|null, 3: array{0: ?int, 1: ?int}|null}
     */
    private function request(?string $cursor): array
    {
        $attempts = $this->apiKey === '' ? min(2, self::ANONYMOUS_REQUEST_LIMIT - $this->requests) : 2;
        $request = $this->http()
            ->retry($attempts, 500, static fn (Throwable $exception): bool => $exception instanceof ConnectionException
                || ($exception instanceof RequestException && $exception->response->serverError()), throw: false)
            ->beforeSending(function (): void {
                if ($this->apiKey === '' && $this->requests >= self::ANONYMOUS_REQUEST_LIMIT) {
                    throw new RuntimeException('predataba.se anonymous wire request allowance is exhausted.');
                }
                $this->requests++;
            });
        if ($this->apiKey !== '') {
            $request = $request->withToken($this->apiKey);
        }

        $response = $request->get($this->endpoint, array_filter(['page_id' => $cursor]));

        // The API answers 403 when the anonymous rate or depth limit is exceeded.
        if ($response->status() === 403) {
            throw new FeedRateLimitedException('predataba.se rate limited: '.(string) $response->json('message', 'forbidden'));
        }

        $response->throw();

        $native = self::nativeJsonEntries($response->body(), 1, 'predataba.se');
        if ($native !== null) {
            [[$next, $newest, $oldest], $entries] = $native;

            return [[], $next !== '' ? $next : null, $entries, [$newest === '' ? null : (int) $newest, $oldest === '' ? null : (int) $oldest]];
        }

        $rows = $response->json('results');
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new RuntimeException('predataba.se returned an unexpected response (no results).');
        }

        $next = $response->json('next_page_id');
        if ($next !== null && ! is_string($next)) {
            throw new RuntimeException('predataba.se returned a malformed history cursor.');
        }

        return [$rows, $next !== '' ? $next : null, null, null];
    }

    /**
     * validateChronology() for rows Rust already checked: their positive times never increase.
     */
    private function nativeChronology(?int $newest, ?int $oldest): ?int
    {
        if ($newest === null) {
            return $this->oldestTimestamp;
        }
        if ($this->oldestTimestamp !== null && $newest > $this->oldestTimestamp) {
            throw new RuntimeException('predataba.se returned reversed chronological history.');
        }

        return $oldest;
    }

    /**
     * Validate received dates before committing chunk state or allowing a CLI history cutoff.
     * Unknown dates stay unknown; only an explicit reversal of known dates is rejected.
     *
     * @param  array<int, mixed>  $rows
     */
    private function validateChronology(array $rows): ?int
    {
        $oldest = $this->oldestTimestamp;
        foreach ($rows as $row) {
            $timestamp = is_array($row) && is_numeric($row['ctime'] ?? null) ? (int) $row['ctime'] : 0;
            if ($timestamp <= 0) {
                continue;
            }
            if ($oldest !== null && $timestamp > $oldest) {
                throw new RuntimeException('predataba.se returned reversed chronological history.');
            }
            $oldest = $timestamp;
        }

        return $oldest;
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return list<PredbFeedEntry>
     */
    public function parseRows(array $rows): array
    {
        $entries = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['rlsname'] ?? null) || trim($row['rlsname']) === '') {
                continue;
            }

            $entries[] = new PredbFeedEntry(
                title: trim($row['rlsname']),
                source: 'predataba.se',
                category: is_string($row['section'] ?? null) && $row['section'] !== '' ? $row['section'] : null,
                size: PredbFeedEntry::sizeFromMegabytes(is_numeric($row['size'] ?? null) ? (float) $row['size'] : null),
                files: PredbFeedEntry::filesFromCount(is_numeric($row['files'] ?? null) ? (int) $row['files'] : null),
                predate: is_numeric($row['ctime'] ?? null) && (int) $row['ctime'] > 0
                    ? CarbonImmutable::createFromTimestampUTC((int) $row['ctime'])
                    : null,
            );
        }

        return $entries;
    }

    /**
     * @internal Rust decode of a JSON feed body, shared by the JSON feed sources. Returns the offset of
     * the first entry field and the flat field list: the source's header fields, then seven fields per
     * entry (title, category, size, files, time, nuke status, reason). Null when PHP must decode the
     * body, which includes every invalid one.
     *
     * @return array{0: int, 1: list<string>}|null
     */
    public static function nativeJsonFields(string $json, int $source, int $limit = 1): ?array
    {
        $ffi = self::$native ??= RustPipelineLibrary::resolveDomain('predb_json_sources', self::NATIVE_CDEF) ?? false;
        if ($ffi === false || $json === '') {
            return null;
        }
        $length = strlen($json);
        $limit = max(1, $limit);
        $capacity = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, $length + 64, $capacity);
        if ($scratch === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_predb_json_feed_parse($json, $length, $source, $limit, $scratch, $capacity);
        if (is_int($written) && $written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_predb_json_feed_parse($json, $length, $source, $limit, $scratch, $capacity);
        }
        if (! is_int($written) || $written < 1) {
            return null;
        }

        $fields = explode("\x1F", substr(FFI::string($scratch, $written), 1));
        $offset = match ($source) {
            1 => 3,
            default => 1,
        };

        return (count($fields) - $offset) % 7 === 0 ? [$offset, $fields] : null;
    }

    /**
     * @internal nativeJsonFields() turned into the header fields and entries whose time is the PRE
     * time, or the listing time when $listed is set.
     *
     * @return array{0: list<string>, 1: list<PredbFeedEntry>}|null
     */
    public static function nativeJsonEntries(string $json, int $source, string $label, bool $listed = false, int $limit = 1): ?array
    {
        $native = self::nativeJsonFields($json, $source, $limit);
        if ($native === null) {
            return null;
        }

        [$offset, $fields] = $native;
        $fast = ! CarbonImmutable::hasTestNow();
        $entries = [];
        for ($i = $offset, $count = count($fields); $i < $count; $i += 7) {
            $time = $fields[$i + 4];
            $at = $time === '' ? null : self::nativeTime($time, $fast);
            $size = $fields[$i + 2];
            $entries[] = new PredbFeedEntry(
                $fields[$i],
                $label,
                $fields[$i + 1] === '' ? null : $fields[$i + 1],
                // "~" carries a value near a rounding tie for PHP's own formatting.
                $size === '' ? null : ($size[0] === '~' ? PredbFeedEntry::sizeFromMegabytes((float) substr($size, 1)) : $size),
                $fields[$i + 3] === '' ? null : $fields[$i + 3],
                $listed ? null : $at,
                (int) $fields[$i + 5],
                $fields[$i + 6] === '' ? null : $fields[$i + 6],
                $listed ? $at : null,
            );
        }

        return [array_slice($fields, 0, $offset), $entries];
    }

    /**
     * CarbonImmutable::createFromTimestampUTC() for a decimal Unix time, or its utc() form when $named.
     * Setting the time on a cloned epoch yields an identical object much faster, unless Carbon's test
     * clock is mocked.
     */
    public static function nativeTime(string $time, bool $fast = true, bool $named = false): CarbonImmutable
    {
        $timestamp = (int) $time;
        if ($fast && $timestamp >= 0 && $timestamp <= self::NATIVE_MAX_TIMESTAMP) {
            $set = $named
                ? self::$namedEpoch ??= self::epochSetter(CarbonImmutable::createFromTimestampUTC(0)->utc())
                : self::$epoch ??= self::epochSetter(CarbonImmutable::createFromTimestampUTC(0));

            return $set($timestamp);
        }

        $time = CarbonImmutable::createFromTimestampUTC($timestamp);

        return $named ? $time->utc() : $time;
    }

    /**
     * The native DateTimeImmutable::setTimestamp() bound to $epoch, bypassing Carbon's slower override;
     * each call returns a clone (through Carbon's __clone()) that keeps the epoch's timezone.
     *
     * @return Closure(int): CarbonImmutable
     */
    private static function epochSetter(CarbonImmutable $epoch): Closure
    {
        return (new ReflectionMethod(DateTimeImmutable::class, 'setTimestamp'))->getClosure($epoch);
    }

    /** Force the Wave 45.2 helper onto Rust (true) or PHP (false); null restores W452_RUST_FFI_DEFAULT. */
    public static function useW452RustFfi(?bool $enable = true): void
    {
        self::$w452RustFfiOverride = $enable;
    }

    public static function usesW452RustFfi(): bool
    {
        return self::$w452RustFfiOverride ?? self::W452_RUST_FFI_DEFAULT;
    }

    public static function resetW452RustFfi(): void
    {
        self::$w452RustFfiOverride = null;
        self::$w452RustFfiHandle = null;
    }

    /**
     * Summarize release names as parseRows() admits them: non-strings are invalid, names that trim() to '' are
     * empty, and the rest are accepted, counting those trim() changed and the distinct trimmed names.
     *
     * @param  array<array-key, mixed>  $names
     * @return array{total: int, invalid: int, empty: int, accepted: int, trimmed: int, distinct: int}
     */
    public static function summarizeRlsnameBatch(array $names): array
    {
        return (self::usesW452RustFfi() ? self::summarizeRlsnameBatchNative($names) : null)
            ?? self::summarizeRlsnameBatchPhp($names);
    }

    /**
     * @param  array<array-key, mixed>  $names
     * @return array{total: int, invalid: int, empty: int, accepted: int, trimmed: int, distinct: int}
     */
    public static function summarizeRlsnameBatchPhp(array $names): array
    {
        $summary = ['total' => \count($names), 'invalid' => 0, 'empty' => 0, 'accepted' => 0, 'trimmed' => 0, 'distinct' => 0];
        $titles = [];
        foreach ($names as $name) {
            if (! \is_string($name)) {
                $summary['invalid']++;

                continue;
            }
            $title = trim($name);
            if ($title === '') {
                $summary['empty']++;

                continue;
            }
            $summary['accepted']++;
            if ($title !== $name) {
                $summary['trimmed']++;
            }
            $titles[$title] = true;
        }
        $summary['distinct'] = \count($titles);

        return $summary;
    }

    /**
     * @param  array<array-key, mixed>  $names
     * @return array{total: int, invalid: int, empty: int, accepted: int, trimmed: int, distinct: int}|null
     */
    public static function summarizeRlsnameBatchNative(array $names): ?array
    {
        $packed = self::packW452Items($names);
        $values = $packed === null ? null : self::callW452Native(self::w452RustFfi(), 1 | $packed[2], 0, $packed[0], $packed[1], 6);
        if ($values === null) {
            return null;
        }
        [$total, $invalid, $empty, $accepted, $trimmed, $distinct] = $values;
        if ($total !== \count($names) || min($values) < 0 || $invalid + $empty + $accepted !== $total
            || $trimmed > $accepted || $distinct > $accepted || ($distinct > 0) !== ($accepted > 0)) {
            return null;
        }

        return ['total' => $total, 'invalid' => $invalid, 'empty' => $empty, 'accepted' => $accepted, 'trimmed' => $trimmed, 'distinct' => $distinct];
    }

    /**
     * @internal The Wave 45.2 batch layout and its operation bit. A non-empty batch of strings without NUL bytes
     * is joined by NUL (W452_DELIMITED, no length table); any other batch concatenates its strings with one
     * little-endian u32 length per item (W452_NULL for a non-string). Null when a part exceeds W452_MAX_INPUT.
     *
     * @param  array<array-key, mixed>  $items
     * @return array{0: string, 1: string, 2: int}|null
     */
    public static function packW452Items(array $items): ?array
    {
        $delimited = self::delimitW452Strings($items);
        if ($delimited !== null) {
            return \strlen($delimited) > self::W452_MAX_INPUT ? null : [$delimited, '', self::W452_DELIMITED];
        }
        $lengths = [];
        $strings = true;
        foreach ($items as $item) {
            if (\is_string($item)) {
                $lengths[] = \strlen($item);
            } else {
                $lengths[] = self::W452_NULL;
                $strings = false;
            }
        }
        $blob = implode('', $strings ? $items : array_filter($items, \is_string(...)));
        if (\strlen($blob) > self::W452_MAX_INPUT || \count($lengths) * 4 > self::W452_MAX_INPUT) {
            return null;
        }

        return [$blob, pack('V*', ...$lengths), 0];
    }

    /**
     * @internal The items joined by NUL when the list is non-empty, every item is a string and none holds a NUL.
     *
     * @param  array<array-key, mixed>  $items
     */
    public static function delimitW452Strings(array $items): ?string
    {
        if ($items === []) {
            return null;
        }
        foreach ($items as $item) {
            if (! \is_string($item)) {
                return null;
            }
        }
        $blob = implode("\0", $items);

        return substr_count($blob, "\0") === \count($items) - 1 ? $blob : null;
    }

    /**
     * @internal Call the Wave 45.2 export through the shared scratch buffer, retrying once at the size it asks for.
     * Returns exactly $slots counters, or null when the library is missing or the op declines the batch.
     *
     * @return list<int>|null
     */
    public static function callW452Native(?FFI $ffi, int $operation, int $flags, string $blob, string $lens, int $slots): ?array
    {
        if ($ffi === null || $slots < 1) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $slots * 8, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w452_predb_feed_source_batch($operation, $flags, $blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w452_predb_feed_source_batch($operation, $flags, $blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            }
            if (! \is_int($written) || $written !== $slots * 8 || $written > $capacity) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('q'.$slots, FFI::string($buffer, $written));

            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    private static function w452RustFfi(): ?FFI
    {
        self::$w452RustFfiHandle ??= RustPipelineLibrary::resolveDomain(self::W452_FFI_DOMAIN, self::W452_FFI_CDEF) ?? false;

        return self::$w452RustFfiHandle === false ? null : self::$w452RustFfiHandle;
    }
}
