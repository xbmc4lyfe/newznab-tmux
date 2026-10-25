<?php

declare(strict_types=1);

namespace App\Services\Search\DTO;

use App\Support\RustPipelineLibrary;
use FFI;
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

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            self::$ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
        }

        return self::$ffi instanceof FFI ? self::$ffi : null;
    }
}
