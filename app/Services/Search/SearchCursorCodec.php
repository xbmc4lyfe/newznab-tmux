<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Services\Search\DTO\SearchCursor;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use InvalidArgumentException;
use Throwable;

final readonly class SearchCursorCodec
{
    private const string FFI_CDEF = <<<'C'
        ssize_t nntmux_search_cursor_encode_payload(const char *input, size_t len, char *out, size_t cap);
        ssize_t nntmux_search_cursor_decode_payload(const char *input, size_t len, int64_t now, char *out, size_t cap);
        C;

    /**
     * Rust stays opt-in for the Wave 41.5 summary: the FFI round trip misses the 1.5x gate; see
     * tests/Unit/RustSearchDtoQueryCursorPageMediaCodecW41ParityTest.php.
     */
    public const bool W415_RUST_FFI_DEFAULT = false;

    /**
     * The Rust payload codec stays opt-in: it is slower than json_encode/json_decode
     * in the paired benchmark (see RustSearchCursorAndLogIndexParityTest).
     */
    public function __construct(private Encrypter $encrypter, private bool $useRustFfi = false) {}

    public function encode(SearchCursor $cursor): string
    {
        return $this->encrypter->encrypt($this->encodePayload($cursor), false);
    }

    public function decode(string $token): SearchCursor
    {
        try {
            $json = $this->encrypter->decrypt($token, false);
        } catch (DecryptException $e) {
            throw new InvalidArgumentException('Invalid search cursor.', previous: $e);
        }

        return $this->decodePayload($json);
    }

    public function encodePayload(SearchCursor $cursor): string
    {
        return ($this->useRustFfi ? self::encodePayloadNative($cursor) : null) ?? self::encodePayloadPhp($cursor);
    }

    public function decodePayload(mixed $json): SearchCursor
    {
        return ($this->useRustFfi && is_string($json) ? self::decodePayloadNative($json, time()) : null)
            ?? self::decodePayloadPhp($json);
    }

    public static function encodePayloadPhp(SearchCursor $cursor): string
    {
        return json_encode([
            'sort' => $cursor->sortValues,
            'total' => $cursor->total,
            'query' => $cursor->queryHash,
            'driver' => $cursor->driver,
            'generation' => $cursor->indexGeneration,
            'expires' => $cursor->expiresAt,
        ], JSON_THROW_ON_ERROR);
    }

    public static function decodePayloadPhp(mixed $json): SearchCursor
    {
        try {
            $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('Invalid search cursor.', previous: $e);
        }

        if (! is_array($payload) || ! is_array($payload['sort'] ?? null) || (int) ($payload['expires'] ?? 0) < time()) {
            throw new InvalidArgumentException('Invalid or expired search cursor.');
        }

        return new SearchCursor(
            sortValues: array_values($payload['sort']),
            total: (int) ($payload['total'] ?? 0),
            queryHash: (string) ($payload['query'] ?? ''),
            driver: (string) ($payload['driver'] ?? ''),
            indexGeneration: (string) ($payload['generation'] ?? ''),
            expiresAt: (int) $payload['expires'],
        );
    }

    /**
     * Rust payload encoding for integer/string sort values; null defers to PHP (floats,
     * non-list sort values, invalid UTF-8 and an unavailable library).
     */
    public static function encodePayloadNative(SearchCursor $cursor): ?string
    {
        if (! array_is_list($cursor->sortValues)) {
            return null;
        }
        $packed = $cursor->total."\n".$cursor->expiresAt."\n"
            .strlen($cursor->queryHash)."\n".$cursor->queryHash
            .strlen($cursor->driver)."\n".$cursor->driver
            .strlen($cursor->indexGeneration)."\n".$cursor->indexGeneration;
        foreach ($cursor->sortValues as $value) {
            if (is_int($value)) {
                $packed .= 'i'.$value."\n";
            } elseif (is_string($value)) {
                $packed .= 's'.strlen($value)."\n".$value;
            } else {
                return null;
            }
        }

        return self::call('nntmux_search_cursor_encode_payload', $packed, null, strlen($packed) * 6 + 128);
    }

    /**
     * Rust payload validation/decoding; throws for invalid or expired payloads and
     * returns null when PHP must decide (malformed JSON, floats, bools, nested values).
     */
    public static function decodePayloadNative(string $json, int $now): ?SearchCursor
    {
        $record = self::call('nntmux_search_cursor_decode_payload', $json, $now, strlen($json) + 128);
        if ($record === null) {
            return null;
        }
        $p = 0;
        $total = (int) self::line($record, $p);
        $expires = (int) self::line($record, $p);
        $strings = [];
        for ($i = 0; $i < 3; $i++) {
            $length = (int) self::line($record, $p);
            $strings[] = substr($record, $p, $length);
            $p += $length;
        }
        $sort = [];
        $end = strlen($record);
        while ($p < $end) {
            $tag = $record[$p++];
            if ($tag === 'i') {
                $sort[] = (int) self::line($record, $p);
            } else {
                $length = (int) self::line($record, $p);
                $sort[] = substr($record, $p, $length);
                $p += $length;
            }
        }

        return new SearchCursor($sort, $total, $strings[0], $strings[1], $strings[2], $expires);
    }

    /**
     * Wave 41.5 toggle state; readonly classes cannot declare static properties.
     */
    private static function w415State(bool $assign = false, ?bool $enable = null): ?bool
    {
        static $useRustFfi = null;
        if ($assign) {
            $useRustFfi = $enable;
        }

        return $useRustFfi;
    }

    public static function useW415RustFfi(bool $enable = true): void
    {
        self::w415State(true, $enable);
    }

    public static function usesW415RustFfi(): bool
    {
        return self::w415State() ?? self::W415_RUST_FFI_DEFAULT;
    }

    public static function resetW415RustFfi(): void
    {
        self::w415State(true);
    }

    /**
     * Summarizes decoded cursor payloads with the acceptance rules of `decodePayloadPhp()`: a payload
     * needs an array `sort`, and `(int) expires` (0 when missing) must not be before $now. Sort and
     * driver statistics cover accepted payloads; a non-string driver counts as the empty driver.
     *
     * @param  array<array-key, mixed>  $payloads
     * @return array{payloads: int, invalid_shape: int, expired: int, accepted: int, sort_values: int, max_sort_width: int, distinct_drivers: int}
     */
    public static function summarizePayloadBatch(array $payloads, int $now): array
    {
        return self::usesW415RustFfi() ? self::summarizePayloadBatchNative($payloads, $now) : self::summarizePayloadBatchPhp($payloads, $now);
    }

    /**
     * @param  array<array-key, mixed>  $payloads
     * @return array{payloads: int, invalid_shape: int, expired: int, accepted: int, sort_values: int, max_sort_width: int, distinct_drivers: int}
     */
    public static function summarizePayloadBatchPhp(array $payloads, int $now): array
    {
        $invalid = 0;
        $expired = 0;
        $accepted = 0;
        $values = 0;
        $widest = 0;
        $drivers = [];
        foreach ($payloads as $payload) {
            if (! is_array($payload) || ! is_array($payload['sort'] ?? null)) {
                $invalid++;

                continue;
            }
            if ((int) ($payload['expires'] ?? 0) < $now) {
                $expired++;

                continue;
            }
            $accepted++;
            $width = count($payload['sort']);
            $values += $width;
            $widest = max($widest, $width);
            $driver = $payload['driver'] ?? '';
            $drivers[is_string($driver) ? $driver : ''] = true;
        }

        return [
            'payloads' => count($payloads),
            'invalid_shape' => $invalid,
            'expired' => $expired,
            'accepted' => $accepted,
            'sort_values' => $values,
            'max_sort_width' => $widest,
            'distinct_drivers' => count($drivers),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $payloads
     * @return array{payloads: int, invalid_shape: int, expired: int, accepted: int, sort_values: int, max_sort_width: int, distinct_drivers: int}
     */
    public static function summarizePayloadBatchNative(array $payloads, int $now): array
    {
        $parts = [(string) $now];
        foreach ($payloads as $payload) {
            if (! is_array($payload) || ! is_array($payload['sort'] ?? null)) {
                array_push($parts, '0', '', '', '');

                continue;
            }
            $driver = $payload['driver'] ?? '';
            array_push($parts, '1', (string) (int) ($payload['expires'] ?? 0), (string) count($payload['sort']), is_string($driver) ? $driver : '');
        }
        $fields = SearchCursor::evaluateW415Native(5, $parts);
        if ($fields === null || count($fields) !== 7) {
            return self::summarizePayloadBatchPhp($payloads, $now);
        }

        return [
            'payloads' => (int) $fields[0],
            'invalid_shape' => (int) $fields[1],
            'expired' => (int) $fields[2],
            'accepted' => (int) $fields[3],
            'sort_values' => (int) $fields[4],
            'max_sort_width' => (int) $fields[5],
            'distinct_drivers' => (int) $fields[6],
        ];
    }

    private static function line(string $record, int &$p): string
    {
        $at = (int) strpos($record, "\n", $p);
        $line = substr($record, $p, $at - $p);
        $p = $at + 1;

        return $line;
    }

    private static function call(string $function, string $input, ?int $now, int $minBytes): ?string
    {
        static $ffi;
        $ffi ??= RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false;
        if ($ffi === false) {
            return null;
        }
        try {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $cap = 0;
                $scratch = RustPipelineLibrary::obtainScratch($ffi, $minBytes, $cap);
                if ($scratch === null) {
                    return null;
                }
                $written = $now === null
                    ? $ffi->{$function}($input, strlen($input), $scratch, $cap)
                    : $ffi->{$function}($input, strlen($input), $now, $scratch, $cap);
                if (! is_int($written)) {
                    return null;
                }
                if ($written === -3) {
                    throw new InvalidArgumentException('Invalid or expired search cursor.');
                }
                if ($written >= 0 && $written <= $cap) {
                    return FFI::string($scratch, $written);
                }
                if ($written > -3) {
                    return null;
                }
                $minBytes = -$written - 3;
            }
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    public const string W50_FFI_CDEF = 'int64_t nntmux_w505_search_cursor_decode(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W50.5 parity test's QUALIFICATION). */
    private const W50_RUST_FFI_DEFAULT = true;

    /**
     * Wave 50.5 state; readonly classes cannot declare static properties. Ops: null reads the toggle, `set`
     * stores it and returns the previous value, `ffi` reads and `setFfi` replaces the cached library handle
     * (null re-resolves, false disables), and `words` runs the Rust helper for `[count, data, ints, words]`,
     * returning `[rows PHP must compute, exactly that many output words]` or null.
     */
    private static function w50RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $cap = 0;

        switch ($op) {
            case null:
                return $enabled;
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'ffi':
                return $ffi;
            case 'setFfi':
                $ffi = $val;
                $out = null;
                $cap = 0;

                return null;
        }

        /** @var array{int, string, string, int} $val */
        [$count, $data, $ints, $words] = $val;
        if ($ffi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            $ffi = RustPipelineLibrary::resolveDomain('search_w50', self::W50_FFI_CDEF, is_string($path) && $path !== '' ? $path : null) ?? false;
            $out = null;
            $cap = 0;
        }
        if (! $ffi instanceof FFI) {
            return null;
        }

        try {
            if (! $out instanceof CData || $words >= $cap) {
                $cap = max(1024, $words + 1);
                $out = $ffi->new('int64_t['.$cap.']');
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w505_search_cursor_decode($count, 0, $data, strlen($data), $ints, strlen($ints), $out, $cap);
            if ($written !== $words + 1) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('P*', FFI::string($out, $written * 8), 8);

            return is_array($values) ? [$out[0], array_values($values)] : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function usesW50RustFfi(): bool
    {
        $enabled = self::w50RustFfiState();

        return is_bool($enabled) ? $enabled : self::W50_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with decodePayloadBatch() routed to Rust ($enabled) or PHP, then restores the previous
     * routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW50RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w50RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w50RustFfiState('set', $previous);
        }
    }

    /**
     * Decodes many decrypted cursor payloads with the rules of decodePayloadPhp() at $now. Five words per
     * payload: `[status, total, expires, sort width, xxh64(record)]`; status 0 accepts the payload, 1 is
     * malformed JSON, 2 an invalid shape or an expired cursor and 3 an array query/driver/generation (which
     * PHP's string cast rejects); rejected payloads have four zero words. The record is `total\nexpires\n`,
     * then query, driver and generation as `length\nbytes`, then each sort value as `i<int>\n`,
     * `s<length>\n<bytes>` or `x<var_export()>\n`.
     *
     * @param  list<string>  $payloads
     * @return list<int>
     */
    public static function decodePayloadBatch(array $payloads, int $now): array
    {
        return self::usesW50RustFfi() ? self::decodePayloadBatchNative($payloads, $now) : self::decodePayloadBatchPhp($payloads, $now);
    }

    /**
     * @param  list<string>  $payloads
     * @return list<int>
     */
    public static function decodePayloadBatchPhp(array $payloads, int $now): array
    {
        $out = [];
        foreach ($payloads as $json) {
            try {
                $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                array_push($out, 1, 0, 0, 0, 0);

                continue;
            }
            if (! is_array($payload) || ! is_array($payload['sort'] ?? null) || ($expires = (int) ($payload['expires'] ?? 0)) < $now) {
                array_push($out, 2, 0, 0, 0, 0);

                continue;
            }
            $query = $payload['query'] ?? '';
            $driver = $payload['driver'] ?? '';
            $generation = $payload['generation'] ?? '';
            if (is_array($query) || is_array($driver) || is_array($generation)) {
                array_push($out, 3, 0, 0, 0, 0);

                continue;
            }
            $total = (int) ($payload['total'] ?? 0);
            $query = (string) $query;
            $driver = (string) $driver;
            $generation = (string) $generation;
            $record = $total."\n".$expires."\n".strlen($query)."\n".$query.strlen($driver)."\n".$driver.strlen($generation)."\n".$generation;
            foreach ($payload['sort'] as $value) {
                $record .= match (true) {
                    is_int($value) => 'i'.$value."\n",
                    is_string($value) => 's'.strlen($value)."\n".$value,
                    default => 'x'.var_export($value, true)."\n",
                };
            }
            /** @var array{1: int} $digest */
            $digest = unpack('J', hash('xxh64', $record, true));
            array_push($out, 0, $total, $expires, count($payload['sort']), $digest[1]);
        }

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or a payload holds a NUL byte, and decodes
     * payloads outside the Rust JSON subset (malformed JSON, floats, nested values, non-integer casts) with PHP.
     *
     * @param  list<string>  $payloads
     * @return list<int>
     */
    public static function decodePayloadBatchNative(array $payloads, int $now): array
    {
        $count = count($payloads);
        if ($count === 0 || ! array_is_list($payloads)) {
            return self::decodePayloadBatchPhp($payloads, $now);
        }
        /** @var array{int, list<int>}|null $result */
        $result = self::w50RustFfiState('words', [$count, implode("\0", $payloads), pack('P', $now), 5 * $count]);
        if ($result === null) {
            return self::decodePayloadBatchPhp($payloads, $now);
        }
        [$patches, $words] = $result;
        if ($patches > 0) {
            foreach (array_keys($words, -1, true) as $key) {
                if ($key % 5 === 0) {
                    [$words[$key], $words[$key + 1], $words[$key + 2], $words[$key + 3], $words[$key + 4]] = self::decodePayloadBatchPhp([$payloads[intdiv($key, 5)]], $now);
                }
            }
        }

        return $words;
    }
}
