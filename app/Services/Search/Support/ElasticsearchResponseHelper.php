<?php

declare(strict_types=1);

namespace App\Services\Search\Support;

use App\Support\RustPipelineLibrary;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Response\Elasticsearch as ElasticsearchResponse;
use FFI;
use Throwable;

final class ElasticsearchResponseHelper
{
    /**
     * Rust candidates for `asArray()` routing and `isNotFound()`. They stay opt-in:
     * neither beat PHP by 1.5x in the paired benchmark
     * (tests/Unit/RustSearchPageProxySortDropdownParityTest.php).
     */
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_es_response_as_array_mode(int32_t is_array);
        int32_t nntmux_es_is_not_found(int32_t is_client_response, int64_t code);
        C;

    public static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public const string W425_FFI_CDEF = 'int32_t nntmux_w425_search_outbox_elastic_manticore_support(uint32_t mode, const char *input, size_t input_len, char *out, size_t cap);';

    /**
     * Rust stays opt-in for the Wave 42.5 summary: the FFI round trip misses the 1.5x gate; see
     * tests/Unit/RustSearchOutboxElasticManticoreSupportW42ParityTest.php.
     */
    public const bool W425_RUST_FFI_DEFAULT = false;

    private const int W425_NATIVE_MAX_PARTS = 1 << 20;

    private const int W425_NATIVE_MAX_BYTES = 16777216;

    private static ?bool $useW425RustFfi = null;

    private static FFI|false|null $w425Ffi = null;

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function searchFfi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * @param  array<string, mixed>|ElasticsearchResponse  $response
     * @return array<string, mixed>
     */
    public static function asArray(array|ElasticsearchResponse $response): array
    {
        return self::$useRustFfi ? self::asArrayNative($response) : self::asArrayPhp($response);
    }

    /**
     * @param  array<string, mixed>|ElasticsearchResponse  $response
     * @return array<string, mixed>
     */
    public static function asArrayPhp(array|ElasticsearchResponse $response): array
    {
        return is_array($response) ? $response : $response->asArray();
    }

    /**
     * @param  array<string, mixed>|ElasticsearchResponse  $response
     * @return array<string, mixed>
     */
    public static function asArrayNative(array|ElasticsearchResponse $response): array
    {
        $ffi = self::searchFfi();
        if ($ffi === null) {
            return self::asArrayPhp($response);
        }

        // @phpstan-ignore method.notFound
        $mode = $ffi->nntmux_es_response_as_array_mode((int) is_array($response));

        return match (true) {
            $mode === 0 && is_array($response) => $response,
            $mode === 1 && $response instanceof ElasticsearchResponse => $response->asArray(),
            default => self::asArrayPhp($response),
        };
    }

    /**
     * Execute a HEAD-style Elasticsearch call without throwing on 404.
     *
     * @param  callable(Client): ElasticsearchResponse  $callback
     */
    public static function boolResponse(Client $client, callable $callback): bool
    {
        $previousSetting = $client->getResponseException();
        $client->setResponseException(false);

        try {
            return $callback($client)->asBool();
        } finally {
            $client->setResponseException($previousSetting);
        }
    }

    public static function isNotFound(Throwable $throwable): bool
    {
        return self::$useRustFfi ? self::isNotFoundNative($throwable) : self::isNotFoundPhp($throwable);
    }

    public static function isNotFoundPhp(Throwable $throwable): bool
    {
        return $throwable instanceof ClientResponseException && $throwable->getCode() === 404;
    }

    public static function isNotFoundNative(Throwable $throwable): bool
    {
        $code = $throwable->getCode();
        $ffi = \is_int($code) ? self::searchFfi() : null;
        if ($ffi === null || ! \is_int($code)) {
            return self::isNotFoundPhp($throwable);
        }

        // @phpstan-ignore method.notFound
        $notFound = $ffi->nntmux_es_is_not_found((int) ($throwable instanceof ClientResponseException), $code);

        return \is_int($notFound) && $notFound >= 0 ? $notFound === 1 : self::isNotFoundPhp($throwable);
    }

    public static function useW425RustFfi(bool $enable = true): void
    {
        self::$useW425RustFfi = $enable;
    }

    public static function usesW425RustFfi(): bool
    {
        return self::$useW425RustFfi ?? self::W425_RUST_FFI_DEFAULT;
    }

    public static function resetW425RustFfi(): void
    {
        self::$useW425RustFfi = null;
    }

    /**
     * Summarizes decoded search responses (`asArray()` output). Non-array items are invalid. A response
     * timed out when `timed_out` is `true`; `_shards.failed` and `hits.total` (or `hits.total.value`)
     * count only as non-negative integers. `returned_hits` counts `hits.hits` entries and
     * `distinct_ids` the distinct string or integer `_id` values across all responses.
     *
     * @param  array<array-key, mixed>  $responses
     * @return array{responses: int, invalid: int, timed_out: int, with_shard_failures: int, failed_shards: int, total_hits: int, returned_hits: int, max_total: int, empty_responses: int, distinct_ids: int}
     */
    public static function summarizeSearchResponseBatch(array $responses): array
    {
        return self::usesW425RustFfi() ? self::summarizeSearchResponseBatchNative($responses) : self::summarizeSearchResponseBatchPhp($responses);
    }

    /**
     * @param  array<array-key, mixed>  $responses
     * @return array{responses: int, invalid: int, timed_out: int, with_shard_failures: int, failed_shards: int, total_hits: int, returned_hits: int, max_total: int, empty_responses: int, distinct_ids: int}
     */
    public static function summarizeSearchResponseBatchPhp(array $responses): array
    {
        $invalid = 0;
        $timedOut = 0;
        $failing = 0;
        $failedShards = 0;
        $totalHits = 0;
        $returnedHits = 0;
        $maxTotal = 0;
        $empty = 0;
        $ids = [];
        foreach ($responses as $response) {
            $fields = self::w425ResponseFields($response);
            if ($fields === null) {
                $invalid++;

                continue;
            }
            [$timed, $failed, $total, $returned, $hitIds] = $fields;
            $timedOut += (int) $timed;
            $failing += (int) ($failed > 0);
            $failedShards += $failed;
            $totalHits += $total;
            $returnedHits += $returned;
            $maxTotal = max($maxTotal, $total);
            $empty += (int) ($returned === 0);
            foreach ($hitIds as $id) {
                $ids[$id] = true;
            }
        }

        return [
            'responses' => \count($responses),
            'invalid' => $invalid,
            'timed_out' => $timedOut,
            'with_shard_failures' => $failing,
            'failed_shards' => $failedShards,
            'total_hits' => $totalHits,
            'returned_hits' => $returnedHits,
            'max_total' => $maxTotal,
            'empty_responses' => $empty,
            'distinct_ids' => \count($ids),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $responses
     * @return array{responses: int, invalid: int, timed_out: int, with_shard_failures: int, failed_shards: int, total_hits: int, returned_hits: int, max_total: int, empty_responses: int, distinct_ids: int}
     */
    public static function summarizeSearchResponseBatchNative(array $responses): array
    {
        $parts = [];
        foreach ($responses as $response) {
            $fields = self::w425ResponseFields($response);
            if ($fields === null) {
                $parts[] = '0';

                continue;
            }
            [$timed, $failed, $total, $returned, $hitIds] = $fields;
            array_push($parts, '1', $timed ? '1' : '0', (string) $failed, (string) $total, (string) $returned, (string) \count($hitIds), ...$hitIds);
        }
        $fields = self::evaluateW425Native(3, $parts);
        if ($fields === null || \count($fields) !== 10) {
            return self::summarizeSearchResponseBatchPhp($responses);
        }

        return [
            'responses' => (int) $fields[0],
            'invalid' => (int) $fields[1],
            'timed_out' => (int) $fields[2],
            'with_shard_failures' => (int) $fields[3],
            'failed_shards' => (int) $fields[4],
            'total_hits' => (int) $fields[5],
            'returned_hits' => (int) $fields[6],
            'max_total' => (int) $fields[7],
            'empty_responses' => (int) $fields[8],
            'distinct_ids' => (int) $fields[9],
        ];
    }

    /**
     * Shared Wave 42.5 bridge to `nntmux_w425_search_outbox_elastic_manticore_support`. Returns the
     * decoded result fields, or null when the library is missing, the input is too large or Rust
     * declines (callers then use their PHP implementation).
     *
     * @param  list<string>  $parts
     * @return list<string>|null
     */
    public static function evaluateW425Native(int $mode, array $parts): ?array
    {
        $ffi = self::w425Ffi();
        if ($ffi === null || $mode < 0 || \count($parts) > self::W425_NATIVE_MAX_PARTS) {
            return null;
        }

        try {
            $input = pack('V', \count($parts));
            foreach ($parts as $part) {
                $input .= pack('V', \strlen($part)).$part;
            }
            if (\strlen($input) > self::W425_NATIVE_MAX_BYTES) {
                return null;
            }

            $minBytes = 1024;
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $capacity = 0;
                $out = RustPipelineLibrary::obtainScratch($ffi, $minBytes, $capacity);
                if ($out === null) {
                    return null;
                }
                $capacity = min($capacity, self::W425_NATIVE_MAX_BYTES);
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w425_search_outbox_elastic_manticore_support($mode, $input, \strlen($input), $out, $capacity);
                if (! \is_int($written)) {
                    return null;
                }
                if ($written >= 4 && $written <= $capacity) {
                    return self::unpackW425(FFI::string($out, $written));
                }
                if ($written > -4 || -$written <= $capacity || -$written > self::W425_NATIVE_MAX_BYTES) {
                    return null;
                }
                $minBytes = -$written;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @return array{bool, int, int, int, list<string>}|null
     */
    private static function w425ResponseFields(mixed $response): ?array
    {
        if (! \is_array($response)) {
            return null;
        }
        $failed = $response['_shards']['failed'] ?? 0;
        $hits = $response['hits'] ?? null;
        $hits = \is_array($hits) ? $hits : [];
        $total = $hits['total'] ?? 0;
        if (\is_array($total)) {
            $total = $total['value'] ?? 0;
        }
        $hitList = $hits['hits'] ?? null;
        $ids = [];
        if (\is_array($hitList)) {
            foreach ($hitList as $hit) {
                $id = \is_array($hit) ? ($hit['_id'] ?? null) : null;
                if (\is_string($id) || \is_int($id)) {
                    $ids[] = (string) $id;
                }
            }
        }

        return [
            ($response['timed_out'] ?? false) === true,
            \is_int($failed) && $failed > 0 ? $failed : 0,
            \is_int($total) && $total > 0 ? $total : 0,
            \is_array($hitList) ? \count($hitList) : 0,
            $ids,
        ];
    }

    /** @return list<string>|null */
    private static function unpackW425(string $packet): ?array
    {
        $length = \strlen($packet);
        $header = unpack('V', $packet);
        $count = \is_array($header) ? $header[1] : null;
        if (! \is_int($count) || $count > intdiv($length - 4, 4)) {
            return null;
        }

        $offset = 4;
        $parts = [];
        for ($i = 0; $i < $count; $i++) {
            if ($offset + 4 > $length) {
                return null;
            }
            $size = unpack('V', $packet, $offset);
            $size = \is_array($size) ? $size[1] : null;
            $offset += 4;
            if (! \is_int($size) || $size > $length - $offset) {
                return null;
            }
            $parts[] = substr($packet, $offset, $size);
            $offset += $size;
        }

        return $offset === $length ? $parts : null;
    }

    private static function w425Ffi(): ?FFI
    {
        if (self::$w425Ffi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            self::$w425Ffi = RustPipelineLibrary::resolveDomain('search_w425', self::W425_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
        }

        return self::$w425Ffi instanceof FFI ? self::$w425Ffi : null;
    }

    public const string W56_FFI_CDEF = 'int64_t nntmux_w565_es_response_hit_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W56.5 parity test's QUALIFICATION). */
    private const bool W56_RUST_FFI_DEFAULT = true;

    /**
     * Wave 56.5 routing and FFI cache, kept in function-local statics. Without $op it reports
     * the routing; 'set' swaps the override for $val and returns the previous one; 'reset' replaces the
     * cached handle with $val (null re-resolves, false disables); 'buffer' returns `[FFI, int64_t[],
     * capacity]` with room for at least $val words, or null without the library.
     */
    private static function w56RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'reset':
                $ffi = $val;
                $out = null;
                $outCap = 0;

                return null;
            case 'buffer':
                if ($ffi === null) {
                    $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
                    $ffi = RustPipelineLibrary::resolveDomain('w56_5', self::W56_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
                }
                if (! $ffi instanceof FFI || ! \is_int($val)) {
                    return null;
                }
                if ($out === null || $outCap < $val) {
                    $outCap = max(1024, $val);
                    $out = $ffi->new('int64_t['.$outCap.']');
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled ?? self::W56_RUST_FFI_DEFAULT;
        }
    }

    public static function usesW56RustFfi(): bool
    {
        return self::w56RustFfiState() === true;
    }

    /**
     * Runs $callback with hitPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW56RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w56RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w56RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 56.5 Rust helper over $count NUL-joined records, or null
     * to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w56Words(int $count, string $data, int $words): ?array
    {
        try {
            $handle = self::w56RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w565_es_response_hit_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('P*', FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Batch scan of raw (compact JSON) response bodies. Four words per body: `[ids, total, errors, error
     * entries]`. Ids and error entries count `"_id":` and `"error":`. Total reads the digits after the
     * first `"total":`, or after `"value":` inside the `{...}` object it opens (-1 when missing, -2 beyond
     * 18 digits). Errors: 1 for `"errors": true`, 0 for `false`, -1 otherwise.
     *
     * @param  list<string>  $bodies
     * @return list<int>
     */
    public static function hitPlanBatch(array $bodies): array
    {
        return self::usesW56RustFfi() ? self::hitPlanBatchNative($bodies) : self::hitPlanBatchPhp($bodies);
    }

    /**
     * @param  list<string>  $bodies
     * @return list<int>
     */
    public static function hitPlanBatchPhp(array $bodies): array
    {
        $out = [];
        foreach ($bodies as $body) {
            $total = -1;
            $position = strpos($body, '"total":');
            if ($position !== false) {
                $at = $position + 8;
                $at += strspn($body, " \t\n\r", $at);
                if (($body[$at] ?? '') === '{') {
                    $close = strpos($body, '}', $at);
                    $segment = $close === false ? substr($body, $at) : substr($body, $at, $close - $at);
                    $value = strpos($segment, '"value":');
                    $total = $value === false ? -1 : self::w56Digits($segment, $value + 8);
                } else {
                    $total = self::w56Digits($body, $at);
                }
            }
            $errors = -1;
            $position = strpos($body, '"errors":');
            if ($position !== false) {
                $at = $position + 9;
                $at += strspn($body, " \t\n\r", $at);
                $errors = substr_compare($body, 'true', $at, 4) === 0 ? 1 : (substr_compare($body, 'false', $at, 5) === 0 ? 0 : -1);
            }
            array_push($out, substr_count($body, '"_id":'), $total, $errors, substr_count($body, '"error":'));
        }

        return $out;
    }

    /**
     * Rust batch over the NUL-joined values; falls back to PHP without the library or when a value holds
     * a NUL.
     *
     * @param  list<string>  $bodies
     * @return list<int>
     */
    public static function hitPlanBatchNative(array $bodies): array
    {
        $count = \count($bodies);
        if ($count === 0) {
            return [];
        }

        return self::w56Words($count, implode("\0", $bodies), 4 * $count) ?? self::hitPlanBatchPhp($bodies);
    }

    /** Digits after optional JSON whitespace at $at: -1 without digits, -2 beyond 18 digits. */
    private static function w56Digits(string $text, int $at): int
    {
        $at += strspn($text, " \t\n\r", $at);
        $run = strspn($text, '0123456789', $at);

        return match (true) {
            $run === 0 => -1,
            $run > 18 => -2,
            default => (int) substr($text, $at, $run),
        };
    }
}
