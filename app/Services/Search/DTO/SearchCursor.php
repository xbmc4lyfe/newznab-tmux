<?php

declare(strict_types=1);

namespace App\Services\Search\DTO;

use App\Services\Search\SearchCursorCodec;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use JsonException;
use Throwable;

/**
 * Each property is readonly; the class itself cannot be, because it holds the static Rust toggle.
 */
final class SearchCursor
{
    public const string FFI_CDEF = 'ptrdiff_t nntmux_w34_search_cursor_config_stats(uint32_t mode, const char *input, size_t input_len, char *out, size_t cap);';

    public const array MATCH_STATES = ['valid', 'expired', 'query_mismatch', 'driver_mismatch', 'generation_mismatch'];

    private const int NATIVE_MAX_BYTES = 16777216;

    private const int NATIVE_MAX_PARTS = 64;

    /**
     * Rust stays opt-in: the FFI round trip is slower than the PHP helpers (< 1.5x); see
     * tests/Unit/RustSearchCursorConfigStatsParityTest.php.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public const string W415_FFI_CDEF = 'int32_t nntmux_w415_search_dto_query_cursor_page_media_codec(uint32_t mode, const char *input, size_t input_len, char *out, size_t cap);';

    /**
     * Rust stays opt-in for the Wave 41.5 summary: the FFI round trip misses the 1.5x gate; see
     * tests/Unit/RustSearchDtoQueryCursorPageMediaCodecW41ParityTest.php.
     */
    public const bool W415_RUST_FFI_DEFAULT = false;

    private const int W415_NATIVE_MAX_PARTS = 1 << 20;

    private static ?bool $useW415RustFfi = null;

    private static FFI|false|null $w415Ffi = null;

    /** @param list<int|float|string> $sortValues */
    public function __construct(
        public readonly array $sortValues,
        public readonly int $total,
        public readonly string $queryHash,
        public readonly string $driver,
        public readonly string $indexGeneration,
        public readonly int $expiresAt,
    ) {}

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    /** Whether this cursor may resume the given query on the given driver and index generation at $now. */
    public function matchState(string $queryHash, string $driver, string $indexGeneration, int $now): string
    {
        return self::classifyCursorMatch($this->queryHash, $this->driver, $this->indexGeneration, $this->expiresAt,
            $queryHash, $driver, $indexGeneration, $now);
    }

    /** The offset this cursor resumes from: a single sort value is an offset, anything else restarts at 0. */
    public function resumeOffset(): int
    {
        return self::resumeOffsetFromSortValues($this->sortValues);
    }

    /** Expiry is checked first, then the query, driver and index generation bindings, in that order. */
    public static function classifyCursorMatch(string $queryHash, string $driver, string $indexGeneration, int $expiresAt,
        string $expectedQueryHash, string $expectedDriver, string $expectedIndexGeneration, int $now): string
    {
        return self::usesRustFfi()
            ? self::classifyCursorMatchNative($queryHash, $driver, $indexGeneration, $expiresAt, $expectedQueryHash, $expectedDriver, $expectedIndexGeneration, $now)
            : self::classifyCursorMatchPhp($queryHash, $driver, $indexGeneration, $expiresAt, $expectedQueryHash, $expectedDriver, $expectedIndexGeneration, $now);
    }

    public static function classifyCursorMatchPhp(string $queryHash, string $driver, string $indexGeneration, int $expiresAt,
        string $expectedQueryHash, string $expectedDriver, string $expectedIndexGeneration, int $now): string
    {
        return match (true) {
            $expiresAt < $now => 'expired',
            $queryHash !== $expectedQueryHash => 'query_mismatch',
            $driver !== $expectedDriver => 'driver_mismatch',
            $indexGeneration !== $expectedIndexGeneration => 'generation_mismatch',
            default => 'valid',
        };
    }

    public static function classifyCursorMatchNative(string $queryHash, string $driver, string $indexGeneration, int $expiresAt,
        string $expectedQueryHash, string $expectedDriver, string $expectedIndexGeneration, int $now): string
    {
        $result = self::evaluateNative(1, [$queryHash, $driver, $indexGeneration, (string) $expiresAt,
            $expectedQueryHash, $expectedDriver, $expectedIndexGeneration, (string) $now]);

        return $result !== null && \count($result) === 1 && \in_array($result[0], self::MATCH_STATES, true)
            ? $result[0]
            : self::classifyCursorMatchPhp($queryHash, $driver, $indexGeneration, $expiresAt, $expectedQueryHash, $expectedDriver, $expectedIndexGeneration, $now);
    }

    /** @param array<array-key, int|float|string> $sortValues */
    public static function resumeOffsetFromSortValues(array $sortValues): int
    {
        return self::usesRustFfi() ? self::resumeOffsetFromSortValuesNative($sortValues) : self::resumeOffsetFromSortValuesPhp($sortValues);
    }

    /** @param array<array-key, int|float|string> $sortValues */
    public static function resumeOffsetFromSortValuesPhp(array $sortValues): int
    {
        return \count($sortValues) === 1 ? max(0, (int) array_values($sortValues)[0]) : 0;
    }

    /**
     * Rust handles integers and canonical decimal strings; floats and other strings (PHP's
     * numeric-prefix cast) stay in PHP.
     *
     * @param  array<array-key, int|float|string>  $sortValues
     */
    public static function resumeOffsetFromSortValuesNative(array $sortValues): int
    {
        $parts = [(string) \count($sortValues)];
        if (\count($sortValues) === 1) {
            $value = array_values($sortValues)[0];
            if (\is_float($value)) {
                return self::resumeOffsetFromSortValuesPhp($sortValues);
            }
            $parts[] = (string) $value;
        }
        $result = self::evaluateNative(2, $parts);
        if ($result !== null && \count($result) === 1 && preg_match('/^(0|[1-9][0-9]*)$/', $result[0]) === 1) {
            return (int) $result[0];
        }

        return self::resumeOffsetFromSortValuesPhp($sortValues);
    }

    /**
     * Shared Wave 34.5 bridge: count- and length-prefixed (little-endian u32) packets preserve PHP byte
     * strings. Returns the output parts, or null when Rust declined the input or is unavailable.
     *
     * @param  list<string>  $parts
     * @return list<string>|null
     */
    public static function evaluateNative(int $mode, array $parts): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null || $mode < 0 || \count($parts) > self::NATIVE_MAX_PARTS) {
            return null;
        }

        try {
            $input = pack('V', \count($parts));
            foreach ($parts as $part) {
                $input .= pack('V', \strlen($part)).$part;
            }
            if (\strlen($input) > self::NATIVE_MAX_BYTES) {
                return null;
            }

            $minBytes = \strlen($input) * 2 + 256;
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $capacity = 0;
                $out = RustPipelineLibrary::obtainScratch($ffi, $minBytes, $capacity);
                if ($out === null) {
                    return null;
                }
                $capacity = min($capacity, self::NATIVE_MAX_BYTES);
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w34_search_cursor_config_stats($mode, $input, \strlen($input), $out, $capacity);
                if (! \is_int($written)) {
                    return null;
                }
                if ($written >= 4 && $written <= $capacity) {
                    return self::unpackNative(FFI::string($out, $written));
                }
                if ($written > -4 || -$written <= $capacity || -$written > self::NATIVE_MAX_BYTES) {
                    return null;
                }
                $minBytes = -$written;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /** @return list<string>|null */
    private static function unpackNative(string $packet): ?array
    {
        $length = \strlen($packet);
        $header = unpack('V', $packet);
        $count = \is_array($header) ? $header[1] : null;
        if (! \is_int($count) || $count > self::NATIVE_MAX_PARTS || $count > intdiv($length - 4, 4)) {
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

    public static function useW415RustFfi(bool $enable = true): void
    {
        self::$useW415RustFfi = $enable;
    }

    public static function usesW415RustFfi(): bool
    {
        return self::$useW415RustFfi ?? self::W415_RUST_FFI_DEFAULT;
    }

    public static function resetW415RustFfi(): void
    {
        self::$useW415RustFfi = null;
    }

    /**
     * Classifies a batch of cursors against one query, driver and index generation at $now, with the
     * precedence of `classifyCursorMatchPhp()`. Non-cursor items are invalid. `max_valid_total` is the
     * largest total of a valid cursor (0 when none); `earliest_expires_at` is the smallest expiry of
     * any cursor (null when none).
     *
     * @param  array<array-key, mixed>  $cursors
     * @return array{cursors: int, invalid: int, valid: int, expired: int, query_mismatch: int, driver_mismatch: int, generation_mismatch: int, max_valid_total: int, earliest_expires_at: int|null}
     */
    public static function summarizeCursorMatchBatch(array $cursors, string $queryHash, string $driver, string $indexGeneration, int $now): array
    {
        return self::usesW415RustFfi()
            ? self::summarizeCursorMatchBatchNative($cursors, $queryHash, $driver, $indexGeneration, $now)
            : self::summarizeCursorMatchBatchPhp($cursors, $queryHash, $driver, $indexGeneration, $now);
    }

    /**
     * @param  array<array-key, mixed>  $cursors
     * @return array{cursors: int, invalid: int, valid: int, expired: int, query_mismatch: int, driver_mismatch: int, generation_mismatch: int, max_valid_total: int, earliest_expires_at: int|null}
     */
    public static function summarizeCursorMatchBatchPhp(array $cursors, string $queryHash, string $driver, string $indexGeneration, int $now): array
    {
        $states = array_fill_keys(self::MATCH_STATES, 0);
        $invalid = 0;
        $maxTotal = 0;
        $earliest = null;
        foreach ($cursors as $cursor) {
            if (! $cursor instanceof self) {
                $invalid++;

                continue;
            }
            $earliest = $earliest === null ? $cursor->expiresAt : min($earliest, $cursor->expiresAt);
            $state = self::classifyCursorMatchPhp($cursor->queryHash, $cursor->driver, $cursor->indexGeneration, $cursor->expiresAt, $queryHash, $driver, $indexGeneration, $now);
            $states[$state]++;
            if ($state === 'valid') {
                $maxTotal = max($maxTotal, $cursor->total);
            }
        }

        return [
            'cursors' => \count($cursors),
            'invalid' => $invalid,
            'valid' => $states['valid'],
            'expired' => $states['expired'],
            'query_mismatch' => $states['query_mismatch'],
            'driver_mismatch' => $states['driver_mismatch'],
            'generation_mismatch' => $states['generation_mismatch'],
            'max_valid_total' => $maxTotal,
            'earliest_expires_at' => $earliest,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $cursors
     * @return array{cursors: int, invalid: int, valid: int, expired: int, query_mismatch: int, driver_mismatch: int, generation_mismatch: int, max_valid_total: int, earliest_expires_at: int|null}
     */
    public static function summarizeCursorMatchBatchNative(array $cursors, string $queryHash, string $driver, string $indexGeneration, int $now): array
    {
        $parts = [$queryHash, $driver, $indexGeneration, (string) $now];
        foreach ($cursors as $cursor) {
            if ($cursor instanceof self) {
                array_push($parts, '1', $cursor->queryHash, $cursor->driver, $cursor->indexGeneration, (string) $cursor->expiresAt, (string) $cursor->total);
            } else {
                array_push($parts, '0', '', '', '', '0', '0');
            }
        }
        $fields = self::evaluateW415Native(2, $parts);
        if ($fields === null || \count($fields) !== 9) {
            return self::summarizeCursorMatchBatchPhp($cursors, $queryHash, $driver, $indexGeneration, $now);
        }

        return [
            'cursors' => (int) $fields[0],
            'invalid' => (int) $fields[1],
            'valid' => (int) $fields[2],
            'expired' => (int) $fields[3],
            'query_mismatch' => (int) $fields[4],
            'driver_mismatch' => (int) $fields[5],
            'generation_mismatch' => (int) $fields[6],
            'max_valid_total' => (int) $fields[7],
            'earliest_expires_at' => $fields[8] === '' ? null : (int) $fields[8],
        ];
    }

    /**
     * Shared Wave 41.5 bridge to `nntmux_w415_search_dto_query_cursor_page_media_codec`. Returns the
     * decoded result fields, or null when the library is missing, the input is too large or Rust
     * declines (callers then use their PHP implementation).
     *
     * @param  list<string>  $parts
     * @return list<string>|null
     */
    public static function evaluateW415Native(int $mode, array $parts): ?array
    {
        $ffi = self::w415Ffi();
        if ($ffi === null || $mode < 0 || \count($parts) > self::W415_NATIVE_MAX_PARTS) {
            return null;
        }

        try {
            $input = pack('V', \count($parts));
            foreach ($parts as $part) {
                $input .= pack('V', \strlen($part)).$part;
            }
            if (\strlen($input) > self::NATIVE_MAX_BYTES) {
                return null;
            }

            $minBytes = 1024;
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $capacity = 0;
                $out = RustPipelineLibrary::obtainScratch($ffi, $minBytes, $capacity);
                if ($out === null) {
                    return null;
                }
                $capacity = min($capacity, self::NATIVE_MAX_BYTES);
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w415_search_dto_query_cursor_page_media_codec($mode, $input, \strlen($input), $out, $capacity);
                if (! \is_int($written)) {
                    return null;
                }
                if ($written >= 4 && $written <= $capacity) {
                    return self::unpackW415(FFI::string($out, $written));
                }
                if ($written > -4 || -$written <= $capacity || -$written > self::NATIVE_MAX_BYTES) {
                    return null;
                }
                $minBytes = -$written;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /** @return list<string>|null */
    private static function unpackW415(string $packet): ?array
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

    private static function w415Ffi(): ?FFI
    {
        if (self::$w415Ffi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            self::$w415Ffi = RustPipelineLibrary::resolveDomain('search_w415', self::W415_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
        }

        return self::$w415Ffi instanceof FFI ? self::$w415Ffi : null;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            self::$ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
        }

        return self::$ffi instanceof FFI ? self::$ffi : null;
    }

    public const string W51_FFI_CDEF = 'int64_t nntmux_w515_search_cursor_resume_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W51.5 parity test's QUALIFICATION). */
    private const bool W51_RUST_FFI_DEFAULT = true;

    private static ?bool $w51RustFfi = null;

    private static FFI|false|null $w51Ffi = null;

    private static ?CData $w51Out = null;

    private static int $w51OutCap = 0;

    public static function usesW51RustFfi(): bool
    {
        return self::$w51RustFfi ?? self::W51_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with resumePlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW51RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$w51RustFfi;
        self::$w51RustFfi = $enabled;

        try {
            return $callback();
        } finally {
            self::$w51RustFfi = $previous;
        }
    }

    /**
     * Exactly $words output words from the Wave 51.5 Rust helper, or null to fall back to PHP. The helper
     * leads with the number of rows PHP must compute, returned in $patches.
     *
     * @return list<int>|null
     */
    private static function w51Words(int $count, string $data, string $ints, int $words, ?int &$patches = null): ?array
    {
        if (self::$w51Ffi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            self::$w51Ffi = RustPipelineLibrary::resolveDomain('search_w51', self::W51_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
            self::$w51Out = null;
            self::$w51OutCap = 0;
        }
        $ffi = self::$w51Ffi;
        if (! $ffi instanceof FFI) {
            return null;
        }

        try {
            if (self::$w51Out === null || $words >= self::$w51OutCap) {
                $cap = max(1024, $words + 1);
                self::$w51Out = $ffi->new('int64_t['.$cap.']');
                self::$w51OutCap = $cap;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w515_search_cursor_resume_plan($count, 0, $data, \strlen($data), $ints, \strlen($ints), self::$w51Out, self::$w51OutCap);
            if ($written !== $words + 1) {
                return null;
            }
            $patches = self::$w51Out[0];
            /** @var array<int, int>|false $values */
            $values = unpack('P*', FFI::string(self::$w51Out, $written * 8), 8);

            return \is_array($values) ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** PHP `hash('xxh64')` of $value read as a signed big-endian word, as the Rust helper reports it. */
    private static function w51Digest(string $value): int
    {
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('J', hash('xxh64', $value, true));

        return $unpacked[1];
    }

    /** MATCH_STATES codes reported by resumePlanBatch(). */
    private const array W51_STATE_CODES = ['valid' => 0, 'expired' => 1, 'query_mismatch' => 2, 'driver_mismatch' => 3, 'generation_mismatch' => 4];

    /**
     * Batch resume plan for cursors against one query, driver and index generation at $now. Six words per
     * cursor: `[classifyCursorMatchPhp() state (MATCH_STATES index) | sort width << 3,
     * resumeOffsetFromSortValuesPhp(), payload length, xxh64(payload), renewed payload length,
     * xxh64(renewed payload)]`. The payload is SearchCursorCodec::encodePayloadPhp() of the cursor and the
     * renewed payload that of the cursor rebound to the given query, driver and generation and expiring at
     * `$now + $ttlSeconds` (the next-page cursor); a payload that cannot be encoded has length -1 and
     * digest 0.
     *
     * @param  list<self>  $cursors
     * @return list<int>
     */
    public static function resumePlanBatch(array $cursors, string $queryHash, string $driver, string $indexGeneration, int $now, int $ttlSeconds = 900): array
    {
        return self::usesW51RustFfi()
            ? self::resumePlanBatchNative($cursors, $queryHash, $driver, $indexGeneration, $now, $ttlSeconds)
            : self::resumePlanBatchPhp($cursors, $queryHash, $driver, $indexGeneration, $now, $ttlSeconds);
    }

    /**
     * @param  list<self>  $cursors
     * @return list<int>
     */
    public static function resumePlanBatchPhp(array $cursors, string $queryHash, string $driver, string $indexGeneration, int $now, int $ttlSeconds = 900): array
    {
        $out = [];
        foreach ($cursors as $cursor) {
            $state = self::W51_STATE_CODES[self::classifyCursorMatchPhp($cursor->queryHash, $cursor->driver, $cursor->indexGeneration, $cursor->expiresAt, $queryHash, $driver, $indexGeneration, $now)];
            array_push($out, $state | \count($cursor->sortValues) << 3, self::resumeOffsetFromSortValuesPhp($cursor->sortValues));
            foreach ([$cursor, new self($cursor->sortValues, $cursor->total, $queryHash, $driver, $indexGeneration, $now + $ttlSeconds)] as $encoded) {
                try {
                    $payload = SearchCursorCodec::encodePayloadPhp($encoded);
                    array_push($out, \strlen($payload), self::w51Digest($payload));
                } catch (JsonException) {
                    array_push($out, -1, 0);
                }
            }
        }

        return $out;
    }

    /**
     * Rust batch: one `json_encode()` of every cursor's sort values plus NUL-delimited strings. Falls back to
     * PHP when the library is missing, a string holds a NUL byte, a sort value cannot be encoded or the
     * renewed expiry overflows, and computes cursors with float, nested or non-list sort values (or a single
     * string sort value PHP must cast) with PHP.
     *
     * @param  list<self>  $cursors
     * @return list<int>
     */
    public static function resumePlanBatchNative(array $cursors, string $queryHash, string $driver, string $indexGeneration, int $now, int $ttlSeconds = 900): array
    {
        $count = \count($cursors);
        $overflows = $ttlSeconds > 0 ? $now > PHP_INT_MAX - $ttlSeconds : $now < PHP_INT_MIN - $ttlSeconds;
        $sorts = $count === 0 || $overflows || ! array_is_list($cursors) ? false : json_encode(array_column($cursors, 'sortValues'));
        if ($sorts === false) {
            return self::resumePlanBatchPhp($cursors, $queryHash, $driver, $indexGeneration, $now, $ttlSeconds);
        }
        $data = $sorts."\0".$queryHash."\0".$driver."\0".$indexGeneration
            ."\0".implode("\0", array_column($cursors, 'queryHash'))
            ."\0".implode("\0", array_column($cursors, 'driver'))
            ."\0".implode("\0", array_column($cursors, 'indexGeneration'));
        $words = self::w51Words($count, $data, pack('P*', $now, $now + $ttlSeconds, ...array_column($cursors, 'expiresAt'), ...array_column($cursors, 'total')), 6 * $count, $patches);
        if ($words === null) {
            return self::resumePlanBatchPhp($cursors, $queryHash, $driver, $indexGeneration, $now, $ttlSeconds);
        }
        if ($patches > 0) {
            $keys = $patchCursors = [];
            foreach (array_keys($words, -1, true) as $key) {
                if ($key % 6 === 0) {
                    $keys[] = $key;
                    $patchCursors[] = $cursors[intdiv($key, 6)];
                }
            }
            $fixed = self::resumePlanBatchPhp($patchCursors, $queryHash, $driver, $indexGeneration, $now, $ttlSeconds);
            foreach ($keys as $n => $key) {
                for ($word = 0; $word < 6; $word++) {
                    $words[$key + $word] = $fixed[6 * $n + $word];
                }
            }
        }

        return $words;
    }
}
