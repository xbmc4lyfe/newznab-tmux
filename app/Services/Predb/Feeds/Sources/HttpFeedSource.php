<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Services\Predb\Feeds\Contracts\PredbFeedSource;
use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

/**
 * Shared HTTP plumbing for remote PreDB feeds.
 */
abstract class HttpFeedSource implements PredbFeedSource
{
    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustPredbSourcesHttpNswdb3dsdbRateParityTest.php is below 1.5x.
     */
    public const bool HTTP_FEED_RUST_FFI_DEFAULT = false;

    /** Distinct from the bounded, srrDB and xREL subclass toggles and FFI_CDEF constants. */
    private const string HTTP_FEED_FFI_CDEF = <<<'C'
        ssize_t nntmux_w384_http_feed_request_plan(
            int64_t timeout,
            int64_t status,
            uint32_t connection_error,
            const char *user_agent,
            size_t user_agent_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    private const int HTTP_FEED_ATTEMPTS = 2;

    private const int HTTP_FEED_RETRY_SLEEP_MS = 500;

    private const int HTTP_FEED_CONNECT_TIMEOUT_CAP = 10;

    private const string HTTP_FEED_FIELD_SEPARATOR = "\x1F";

    /** Shared by every subclass: only this class's methods touch the private toggle. */
    private static ?bool $httpFeedUseRustFfi = null;

    private static FFI|false|null $httpFeedFfi = null;

    /**
     * Wave 48.2 production default for preflightRequestBatch() is Rust: the paired benchmark in
     * tests/Unit/RustPredbEntryFactoryBoundedHttpUpdateLockW48ParityTest.php measures 5.33-5.51x mean and 4.26-4.93x p99 on common batches and 4.49-4.90x mean and 3.53-4.99x p99 on uncommon batches.
     * PHP stays the fallback when the library is unavailable or a string holds a NUL byte.
     */
    public const bool W48_HTTP_RUST_FFI_DEFAULT = true;

    public const string W48_FFI_CDEF = <<<'C'
        ssize_t nntmux_w48_http_preflight_batch(const char *blob, size_t blob_len, const char *aux, size_t aux_len, int64_t maximum_payload_bytes, int64_t reserved, char *out_buf, size_t out_cap);
        C;

    /** Largest payload cap the Wave 48.2 preflight accepts, so payload sums stay exact. */
    private const int W48_HTTP_MAX_SPAN = 1 << 40;

    private const array W48_HTTP_KEYS = ['total', 'valid_url', 'invalid_url', 'unsupported_scheme', 'https', 'userinfo', 'numeric_host', 'ipv6_host',
        'custom_port', 'query', 'distinct_hosts', 'host_bytes', 'ok', 'not_modified', 'rate_limited', 'server_error', 'client_error', 'other_status',
        'strong_etag', 'weak_etag', 'invalid_etag', 'cached_etag', 'etag_match', 'revalidated', 'unexpected_not_modified', 'redundant_body',
        'empty_payload', 'oversized_payload', 'payload_bytes', 'digest'];

    private const string W48_HOST_BYTES = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789.-';

    private static ?bool $w48HttpRustFfiOverride = null;

    private static FFI|false|null $w48HttpRustFfiHandle = null;

    public function __construct(
        protected readonly string $endpoint,
        protected readonly int $pageSize = 100,
        protected readonly int $timeout = 15,
        protected readonly string $userAgent = 'NNTmux-PreDB-Importer/1.0',
    ) {}

    protected function http(): PendingRequest
    {
        return Http::timeout($this->timeout)
            ->connectTimeout(min($this->timeout, 10))
            ->withUserAgent($this->userAgent)
            // Transient failures only (connection errors, 5xx). 4xx are not retried here; a 429 goes
            // straight to the command's Retry-After backoff.
            ->retry(2, 500, static fn (Throwable $e): bool => $e instanceof ConnectionException
                || ($e instanceof RequestException && $e->response->serverError()), throw: false);
    }

    public static function useHttpFeedRustFfi(?bool $enabled = true): void
    {
        self::$httpFeedUseRustFfi = $enabled;
    }

    public static function usesHttpFeedRustFfi(): bool
    {
        return self::$httpFeedUseRustFfi ?? self::HTTP_FEED_RUST_FFI_DEFAULT;
    }

    public static function resetHttpFeedRustFfi(): void
    {
        self::$httpFeedUseRustFfi = null;
        self::$httpFeedFfi = null;
    }

    /**
     * Mirrors http(): the request and connect timeouts, the attempt count and retry sleep, and how
     * a response status (or connection error) is classified: retried (connection errors, 5xx),
     * rate limited (429, handed to the command's Retry-After backoff) or another client error.
     *
     * @return array{timeout: int, connect_timeout: int, attempts: int, retry_sleep_ms: int, retryable: bool, rate_limited: bool, client_error: bool, user_agent: string}
     */
    public static function planHttpFeedRequest(int $timeout, int $status, bool $connectionError, string $userAgent): array
    {
        return self::usesHttpFeedRustFfi()
            ? self::planHttpFeedRequestNative($timeout, $status, $connectionError, $userAgent)
            : self::planHttpFeedRequestPhp($timeout, $status, $connectionError, $userAgent);
    }

    /**
     * @return array{timeout: int, connect_timeout: int, attempts: int, retry_sleep_ms: int, retryable: bool, rate_limited: bool, client_error: bool, user_agent: string}
     */
    private static function planHttpFeedRequestNative(int $timeout, int $status, bool $connectionError, string $userAgent): array
    {
        $output = self::callHttpFeedNative([$timeout, $status, (int) $connectionError, $userAgent, strlen($userAgent)], strlen($userAgent) + 96);
        // The user agent is the last field and may itself contain the separator.
        $fields = $output === null ? [] : explode(self::HTTP_FEED_FIELD_SEPARATOR, $output, 8);
        if (count($fields) !== 8) {
            return self::planHttpFeedRequestPhp($timeout, $status, $connectionError, $userAgent);
        }

        return [
            'timeout' => (int) $fields[0],
            'connect_timeout' => (int) $fields[1],
            'attempts' => (int) $fields[2],
            'retry_sleep_ms' => (int) $fields[3],
            'retryable' => $fields[4] === '1',
            'rate_limited' => $fields[5] === '1',
            'client_error' => $fields[6] === '1',
            'user_agent' => $fields[7],
        ];
    }

    /**
     * @return array{timeout: int, connect_timeout: int, attempts: int, retry_sleep_ms: int, retryable: bool, rate_limited: bool, client_error: bool, user_agent: string}
     */
    private static function planHttpFeedRequestPhp(int $timeout, int $status, bool $connectionError, string $userAgent): array
    {
        return [
            'timeout' => $timeout,
            'connect_timeout' => min($timeout, self::HTTP_FEED_CONNECT_TIMEOUT_CAP),
            'attempts' => self::HTTP_FEED_ATTEMPTS,
            'retry_sleep_ms' => self::HTTP_FEED_RETRY_SLEEP_MS,
            'retryable' => $connectionError || ($status >= 500 && $status < 600),
            'rate_limited' => ! $connectionError && $status === 429,
            'client_error' => ! $connectionError && $status >= 400 && $status < 500,
            'user_agent' => trim($userAgent),
        ];
    }

    /** Force the Wave 48.2 request preflight onto Rust (true) or PHP (false); null restores W48_HTTP_RUST_FFI_DEFAULT. */
    public static function useW48HttpRustFfi(?bool $enable = true): void
    {
        self::$w48HttpRustFfiOverride = $enable;
    }

    public static function usesW48HttpRustFfi(): bool
    {
        return self::$w48HttpRustFfiOverride ?? self::W48_HTTP_RUST_FFI_DEFAULT;
    }

    public static function resetW48HttpRustFfi(): void
    {
        self::$w48HttpRustFfiOverride = null;
        self::$w48HttpRustFfiHandle = null;
    }

    /**
     * Preflight a batch of feed requests and their outcomes (request $i is $urls[$i] answered with $statuses[$i], the
     * response ETag $etags[$i], the cached ETag $cachedEtags[$i] and a $payloadBytes[$i] body): URL validity (http(s)
     * scheme, host name or bracketed IPv6 literal, port 1-65535) and traits, distinct lower-cased hosts, status classes
     * as http() treats them (0 is a connection error), strong/weak/invalid ETags and cache revalidation, payloads
     * against $maximumPayloadBytes (clamped to 1..2^40), and an FNV-1a digest of every request's classification in order.
     *
     * @param  list<string>  $urls
     * @param  list<string|null>  $etags
     * @param  list<string|null>  $cachedEtags
     * @param  list<int>  $statuses
     * @param  list<int>  $payloadBytes
     * @return array<string, int>
     *
     * @throws InvalidArgumentException when the lists differ in length
     */
    public static function preflightRequestBatch(array $urls, array $etags, array $cachedEtags, array $statuses, array $payloadBytes, int $maximumPayloadBytes = 1048576): array
    {
        return (self::usesW48HttpRustFfi() ? self::preflightRequestBatchNative($urls, $etags, $cachedEtags, $statuses, $payloadBytes, $maximumPayloadBytes) : null)
            ?? self::preflightRequestBatchPhp($urls, $etags, $cachedEtags, $statuses, $payloadBytes, $maximumPayloadBytes);
    }

    /**
     * @param  list<string>  $urls
     * @param  list<string|null>  $etags
     * @param  list<string|null>  $cachedEtags
     * @param  list<int>  $statuses
     * @param  list<int>  $payloadBytes
     * @return array<string, int>
     */
    public static function preflightRequestBatchPhp(array $urls, array $etags, array $cachedEtags, array $statuses, array $payloadBytes, int $maximumPayloadBytes = 1048576): array
    {
        self::w48AssertSameLength(\count($urls), $etags, $cachedEtags, $statuses, $payloadBytes);
        $etags = array_values($etags);
        $cachedEtags = array_values($cachedEtags);
        $statuses = array_values($statuses);
        $payloadBytes = array_values($payloadBytes);
        $maximum = max(1, min(self::W48_HTTP_MAX_SPAN, $maximumPayloadBytes));
        $c = array_fill(0, \count(self::W48_HTTP_KEYS), 0);
        $hosts = [];
        $stream = '';
        foreach (array_values($urls) as $i => $url) {
            $c[0]++;
            [$state, $flags, $host] = self::w48ParseUrl($url);
            if ($state !== 0) {
                $c[$state === 1 ? 2 : 3]++;
                $stream .= pack('Cx7', $state);

                continue;
            }
            $c[1]++;
            for ($bit = 0; $bit < 6; $bit++) {
                $c[4 + $bit] += $flags >> $bit & 1;
            }
            $c[11] += \strlen($host);
            $hosts[$host] = true;
            $status = $statuses[$i];
            $class = match (true) {
                $status >= 200 && $status < 300 => 1,
                $status === 304 => 2,
                $status === 429 => 3,
                $status === 0 || ($status >= 500 && $status < 600) => 4,
                $status >= 400 && $status < 500 => 5,
                default => 6,
            };
            $c[11 + $class]++;
            [$tag, $tagOpaque] = self::w48Etag((string) $etags[$i]);
            [$stored, $storedOpaque] = self::w48Etag((string) $cachedEtags[$i]);
            if ($tag !== 0) {
                $c[[1 => 20, 2 => 18, 3 => 19][$tag]]++;
            }
            if ($stored >= 2) {
                $c[21]++;
            }
            $hit = $tag >= 2 && $stored >= 2 && $tagOpaque === $storedOpaque;
            if ($hit) {
                $c[22]++;
            }
            if ($class === 1) {
                $payload = $payloadBytes[$i];
                if ($payload <= 0) {
                    $c[26]++;
                } elseif ($payload > $maximum) {
                    $c[27]++;
                } else {
                    $c[28] += $payload;
                    if ($hit) {
                        $c[25]++;
                    }
                }
            } elseif ($class === 2) {
                if ($stored >= 2 && ($tag === 0 || $hit)) {
                    $c[23]++;
                } else {
                    $c[24]++;
                }
            }
            $stream .= pack('CCCCV', 0, $flags, $class, $tag | $stored << 2 | ($hit ? 16 : 0), \strlen($host));
        }
        $c[10] = \count($hosts);
        $c[29] = PredbFeedEntry::w48Fnv($stream);

        return array_combine(self::W48_HTTP_KEYS, $c);
    }

    /**
     * Null when the library is missing, a string holds a NUL byte or the batch exceeds the native limits.
     *
     * @param  list<string>  $urls
     * @param  list<string|null>  $etags
     * @param  list<string|null>  $cachedEtags
     * @param  list<int>  $statuses
     * @param  list<int>  $payloadBytes
     * @return array<string, int>|null
     */
    public static function preflightRequestBatchNative(array $urls, array $etags, array $cachedEtags, array $statuses, array $payloadBytes, int $maximumPayloadBytes = 1048576): ?array
    {
        $count = \count($urls);
        self::w48AssertSameLength($count, $etags, $cachedEtags, $statuses, $payloadBytes);
        $ffi = self::w48HttpRustFfi();
        if ($ffi === null || $count > PredbFeedEntry::W48_MAX_ITEMS) {
            return null;
        }
        $blob = $count === 0 ? '' : implode("\0", [...array_values($urls), ...array_values($etags), ...array_values($cachedEtags)]);
        $aux = pack('q*', ...array_values($statuses), ...array_values($payloadBytes));
        $values = PredbFeedEntry::callW48Native($ffi, 'nntmux_w48_http_preflight_batch', $blob, $aux,
            $maximumPayloadBytes, 0, \count(self::W48_HTTP_KEYS));
        if ($values === null || $values[0] !== $count || $count !== $values[1] + $values[2] + $values[3]) {
            return null;
        }

        return array_combine(self::W48_HTTP_KEYS, $values);
    }

    /**
     * [0 valid / 1 invalid / 2 unsupported scheme, flags (1 https, 2 userinfo, 4 numeric host, 8 IPv6 literal, 16
     * non-default port, 32 query), lower-cased host].
     *
     * @return array{int, int, string}
     */
    private static function w48ParseUrl(string $url): array
    {
        $url = trim($url);
        $separator = strpos($url, '://');
        if ($separator === false) {
            return [1, 0, ''];
        }
        $scheme = substr($url, 0, $separator);
        $https = strcasecmp($scheme, 'https') === 0;
        if (! $https && strcasecmp($scheme, 'http') !== 0) {
            return [2, 0, ''];
        }
        $rest = substr($url, $separator + 3);
        $end = strcspn($rest, '/?#');
        $authority = substr($rest, 0, $end);
        $flags = $https ? 1 : 0;
        $at = strrpos($authority, '@');
        if ($at !== false) {
            $flags |= 2;
            $authority = substr($authority, $at + 1);
        }
        if (str_starts_with($authority, '[')) {
            $close = strpos($authority, ']');
            if ($close === false) {
                return [1, 0, ''];
            }
            $host = substr($authority, 0, $close + 1);
            $after = substr($authority, $close + 1);
            if ($after !== '' && $after[0] !== ':') {
                return [1, 0, ''];
            }
            $port = substr($after, 1);
            if (preg_match('/^[0-9A-Fa-f:.]+$/D', substr($host, 1, -1)) !== 1) {
                return [1, 0, ''];
            }
            $flags |= 8;
        } else {
            $colon = strrpos($authority, ':');
            $host = $colon === false ? $authority : substr($authority, 0, $colon);
            $port = $colon === false ? '' : substr($authority, $colon + 1);
            $length = \strlen($host);
            if ($length === 0 || $length > 253 || strspn($host, self::W48_HOST_BYTES) !== $length || strpbrk($host[0].$host[-1], '.-') !== false) {
                return [1, 0, ''];
            }
            if (strspn($host, '0123456789.') === $length) {
                $flags |= 4;
            }
        }
        if ($port !== '') {
            if (\strlen($port) > 5 || strspn($port, '0123456789') !== \strlen($port) || (int) $port < 1 || (int) $port > 65535) {
                return [1, 0, ''];
            }
            if ((int) $port !== ($https ? 443 : 80)) {
                $flags |= 16;
            }
        }
        if (str_contains(substr($rest, $end), '?')) {
            $flags |= 32;
        }

        return [0, $flags, strtolower($host)];
    }

    /**
     * [0 absent / 1 invalid / 2 strong / 3 weak, opaque tag with its quotes].
     *
     * @return array{int, string}
     */
    private static function w48Etag(string $raw): array
    {
        $tag = trim($raw, " \t");
        if ($tag === '') {
            return [0, ''];
        }
        $weak = str_starts_with($tag, 'W/');
        $opaque = $weak ? substr($tag, 2) : $tag;

        return preg_match('/^"[^\x00-\x20"\x7f]*"$/D', $opaque) === 1 ? [$weak ? 3 : 2, $opaque] : [1, ''];
    }

    /**
     * @param  array<array-key, mixed>  ...$lists
     *
     * @throws InvalidArgumentException
     */
    private static function w48AssertSameLength(int $count, array ...$lists): void
    {
        foreach ($lists as $list) {
            if (\count($list) !== $count) {
                throw new InvalidArgumentException('Wave 48.2 batch lists must have the same length.');
            }
        }
    }

    private static function w48HttpRustFfi(): ?FFI
    {
        self::$w48HttpRustFfiHandle ??= RustPipelineLibrary::resolveDomain(PredbFeedEntry::W48_FFI_DOMAIN, self::W48_FFI_CDEF) ?? false;

        return self::$w48HttpRustFfiHandle === false ? null : self::$w48HttpRustFfiHandle;
    }

    /**
     * @param  list<int|string>  $args
     */
    private static function callHttpFeedNative(array $args, int $hint): ?string
    {
        self::$httpFeedFfi ??= RustPipelineLibrary::resolveDomain('metadata_w384', self::HTTP_FEED_FFI_CDEF) ?? false;
        if (self::$httpFeedFfi === false) {
            return null;
        }
        $ffi = self::$httpFeedFfi;

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $hint, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w384_http_feed_request_plan(...[...$args, $buffer, $capacity]);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w384_http_feed_request_plan(...[...$args, $buffer, $capacity]);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return null;
        }
    }

    // ========================================================================
    // WAVE 59.3 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 5.58x, p99 2.57x; uncommon mean 5.38x, p99 4.33x); see RustSeriesControllerAdminLogRequestsHorizonProviderHttpFeedW59ParityTest. */
    public const bool W59_RUST_FFI_DEFAULT = true;

    /** Distinct from the subclasses' FFI declaration constants. */
    public const string W59_HTTP_FEED_SOURCE_FFI_CDEF = <<<'C'
        ssize_t nntmux_w593_http_feed_response_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Feed content types w59HttpFeedResponseBatch() classifies as XML. */
    public const array W59_HTTP_FEED_XML_TYPES = ['application/xml' => true, 'text/xml' => true, 'application/rss+xml' => true,
        'application/atom+xml' => true];

    /** Largest Content-Length w59HttpFeedResponseBatch() accepts. */
    public const int W59_HTTP_FEED_MAX_PAYLOAD = 1048576;

    /** Trailer words after the per-item words of w59HttpFeedResponseBatch(). */
    public const int W59_HTTP_FEED_TRAILER = 14;

    public static function usesW59RustFfi(): bool
    {
        $override = self::w59RustFfiState();

        return \is_bool($override) ? $override : self::W59_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the Wave 59 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW59RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w59RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w59RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 59.3 feed responses. Each item is `url|status|retryAfter|contentType|contentLength|contentEncoding`
     * (missing fields are empty, every field is trimmed). Per item `urlState | https << 3 | query << 4 |
     * customPort << 5 | statusClass << 6 | retryState << 9 | type << 11 | charset << 14 | lengthState << 17 |
     * encoding << 20 | verdict << 23 | delay << 27 | min(hostLength, 255) << 44`: url state is
     * w59HttpFeedUrl()'s; status class is 0 not a 100-599 three digit code, 1 2xx, 2 304, 3 429, 4 5xx, 5 other
     * 4xx and 6 other; Retry-After is 0 absent, 1 up to ten digits, 2 an IMF-fixdate and 3 invalid; type is 0
     * absent, 1 W59_HTTP_FEED_XML_TYPES, 2 JSON, 3 text/html, 4 text/plain, 5 octet-stream and 6 other, and
     * charset 0 absent, 1 UTF-8, 2 Latin-1, 3 empty and 4 other; length is 0 absent, 1 zero, 2 within
     * W59_HTTP_FEED_MAX_PAYLOAD, 3 larger and 4 invalid (over 15 digits or non-numeric); encoding is 0 identity, 1
     * gzip, 2 deflate, 3 br and 4 unsupported. The verdict is 6 invalid URL, 7 invalid status, 1 retry (429 or
     * 5xx), 2 not modified, 3 rejected status, 4 unsupported encoding, 5 HTML, 8 oversized, 9 empty, 10 invalid
     * length, else 0 accepted; a retry waits the Retry-After seconds (capped at a day), 60 seconds for a date or
     * a bare 429 and 5 seconds otherwise. Then eleven verdict counts, the accepted payload bytes, the longest
     * delay and the distinct lower-cased hosts of valid URLs.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w59HttpFeedResponseBatch(array $items): array
    {
        return self::usesW59RustFfi() ? self::w59HttpFeedResponseBatchNative($items) : self::w59HttpFeedResponseBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w59HttpFeedResponseBatchNative(array $items): array
    {
        return self::w59Invoke($items, \count($items) + self::W59_HTTP_FEED_TRAILER) ?? self::w59HttpFeedResponseBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w59HttpFeedResponseBatchPhp(array $items): array
    {
        $out = [];
        $verdicts = array_fill(0, 11, 0);
        $payloadSum = $maxDelay = 0;
        $hosts = [];
        foreach ($items as $item) {
            $f = explode('|', (string) $item, 6) + ['', '', '', '', '', ''];
            [$urlState, $https, $query, $customPort, $host] = self::w59HttpFeedUrl(trim($f[0]));
            $status = trim($f[1]);
            $code = \strlen($status) === 3 && strspn($status, '0123456789') === 3 ? (int) $status : 0;
            $statusClass = match (true) {
                $code < 100 || $code > 599 => 0,
                $code >= 200 && $code < 300 => 1,
                $code === 304 => 2,
                $code === 429 => 3,
                $code >= 500 => 4,
                $code >= 400 => 5,
                default => 6,
            };
            $retry = trim($f[2]);
            $retryState = match (true) {
                $retry === '' => 0,
                \strlen($retry) <= 10 && strspn($retry, '0123456789') === \strlen($retry) => 1,
                preg_match('/\A[A-Za-z]{3}, \d{2} [A-Za-z]{3} \d{4} \d{2}:\d{2}:\d{2} GMT\z/', $retry) === 1 => 2,
                default => 3,
            };
            $contentType = strtolower(trim($f[3]));
            $semi = strpos($contentType, ';');
            $mime = trim($semi === false ? $contentType : substr($contentType, 0, $semi));
            $type = match (true) {
                $mime === '' => 0,
                isset(self::W59_HTTP_FEED_XML_TYPES[$mime]) => 1,
                $mime === 'application/json' || str_ends_with($mime, '+json') => 2,
                $mime === 'text/html' => 3,
                $mime === 'text/plain' => 4,
                $mime === 'application/octet-stream' => 5,
                default => 6,
            };
            $at = strpos($contentType, 'charset=');
            if ($at === false) {
                $charset = 0;
            } else {
                $value = substr($contentType, $at + 8);
                $end = strpos($value, ';');
                $value = trim(trim($end === false ? $value : substr($value, 0, $end)), "\"'");
                $charset = match ($value) {
                    'utf-8', 'utf8' => 1,
                    'iso-8859-1', 'latin1' => 2,
                    '' => 3,
                    default => 4,
                };
            }
            $length = trim($f[4]);
            if ($length === '') {
                [$lengthState, $bytes] = [0, 0];
            } elseif (\strlen($length) <= 15 && strspn($length, '0123456789') === \strlen($length)) {
                $bytes = (int) $length;
                $lengthState = $bytes === 0 ? 1 : ($bytes > self::W59_HTTP_FEED_MAX_PAYLOAD ? 3 : 2);
            } else {
                [$lengthState, $bytes] = [4, 0];
            }
            $encoding = match (strtolower(trim($f[5]))) {
                '', 'identity' => 0,
                'gzip', 'x-gzip' => 1,
                'deflate' => 2,
                'br' => 3,
                default => 4,
            };
            $verdict = match (true) {
                $urlState !== 0 => 6,
                $statusClass === 0 => 7,
                $statusClass === 3 || $statusClass === 4 => 1,
                $statusClass === 2 => 2,
                $statusClass !== 1 => 3,
                $encoding === 4 => 4,
                $type === 3 => 5,
                $lengthState === 3 => 8,
                $lengthState === 1 => 9,
                $lengthState === 4 => 10,
                default => 0,
            };
            $delay = $verdict !== 1 ? 0 : match ($retryState) {
                1 => min((int) $retry, 86400),
                2 => 60,
                default => $statusClass === 3 ? 60 : 5,
            };
            $verdicts[$verdict]++;
            if ($verdict === 0 && $lengthState === 2) {
                $payloadSum += $bytes;
            }
            $maxDelay = max($maxDelay, $delay);
            if ($urlState === 0) {
                $hosts[$host] = true;
            }
            $out[] = $urlState | $https << 3 | $query << 4 | $customPort << 5 | $statusClass << 6 | $retryState << 9 | $type << 11
                | $charset << 14 | $lengthState << 17 | $encoding << 20 | $verdict << 23 | $delay << 27 | min(\strlen($host), 255) << 44;
        }
        array_push($out, ...$verdicts);
        array_push($out, $payloadSum, $maxDelay, \count($hosts));

        return $out;
    }

    /**
     * [state (0 valid, 1 empty, 2 no `://`, 3 a scheme other than http(s), 4 a host outside W48_HOST_BYTES, empty,
     * over 253 bytes or starting/ending with `.`/`-`, 5 a port other than 1-65535), https, query, non-default
     * port, lower-cased host (valid URLs only)]. Userinfo up to the last `@` is dropped and the port follows
     * the last `:`.
     *
     * @return array{int, int, int, int, string}
     */
    private static function w59HttpFeedUrl(string $url): array
    {
        if ($url === '') {
            return [1, 0, 0, 0, ''];
        }
        $separator = strpos($url, '://');
        if ($separator === false) {
            return [2, 0, 0, 0, ''];
        }
        $scheme = strtolower(substr($url, 0, $separator));
        $https = $scheme === 'https' ? 1 : 0;
        if ($https === 0 && $scheme !== 'http') {
            return [3, 0, 0, 0, ''];
        }
        $rest = substr($url, $separator + 3);
        $end = strcspn($rest, '/?#');
        $authority = substr($rest, 0, $end);
        $at = strrpos($authority, '@');
        if ($at !== false) {
            $authority = substr($authority, $at + 1);
        }
        $colon = strrpos($authority, ':');
        $host = $colon === false ? $authority : substr($authority, 0, $colon);
        $length = \strlen($host);
        if ($length === 0 || $length > 253 || strspn($host, self::W48_HOST_BYTES) !== $length || strpbrk($host[0].$host[-1], '.-') !== false) {
            return [4, $https, 0, 0, ''];
        }
        $custom = 0;
        if ($colon !== false) {
            $port = substr($authority, $colon + 1);
            if ($port === '' || \strlen($port) > 5 || strspn($port, '0123456789') !== \strlen($port) || (int) $port < 1 || (int) $port > 65535) {
                return [5, $https, 0, 0, ''];
            }
            $custom = (int) $port !== ($https === 1 ? 443 : 80) ? 1 : 0;
        }

        return [0, $https, str_contains(substr($rest, $end), '?') ? 1 : 0, $custom, strtolower($host)];
    }

    /**
     * Wave 59 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'ffi' resolves the library (null when unavailable) and 'out' and
     * 'lens' return `int64_t` output and length-column buffers of at least $val words.
     */
    private static function w59RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('categorize_w593', self::W59_HTTP_FEED_SOURCE_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w59RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w59RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 59.3 export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w59Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w59RustFfiState('ffi');
            $out = self::w59RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w59RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W59_HTTP_FEED_SOURCE_FFI_CDEF.)
            $written = $ffi->nntmux_w593_http_feed_response_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
