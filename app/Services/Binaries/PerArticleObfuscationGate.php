<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use App\Support\RustPipelineLibrary;
use FFI;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use Throwable;

/**
 * The first routing step for {@see NativePerArticleObfuscation}, loaded only for ranges of
 * HeaderParser::NATIVE_MIN_HEADERS or more: a cheap check of 16 sampled subjects keeps mostly plain-text
 * ranges on PHP before the larger {@see PerArticleObfuscationRouter} loads.
 */
final class PerArticleObfuscationGate
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w381_obfuscation_gate_router_admin_names(uint32_t operation, uint32_t flags, const char *input, size_t input_len, char *out_buf, size_t out_cap);
        C;

    /**
     * PHP remains the default: the paired benchmark in
     * tests/Unit/RustObfuscationGateRouterAdminNamesParityTest.php is below 1.5x.
     */
    public const bool RUST_FFI_DEFAULT = false;

    private const int RUST_MAX_INPUT = 16_777_216;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * Whether the Rust port should take this range.
     *
     * @param  array<int, array<string, mixed>>  $headers
     */
    public static function worthNativeMarking(array $headers, bool $libraryLoaded): bool
    {
        return \count($headers) >= HeaderParser::NATIVE_MIN_HEADERS && self::mayCarryPartCounts($headers)
            && PerArticleObfuscationRouter::worthNativeMarking($headers, $libraryLoaded);
    }

    /**
     * Sample positions seeded from the range itself (its size and first and last article numbers):
     * pseudo-random, so a periodic ordering cannot line up with them, yet the same range always
     * gets the same routing.
     *
     * @param  list<array<string, mixed>>  $values
     */
    public static function sampler(array $values): Randomizer
    {
        $first = $values[0]['Number'] ?? '';
        $last = $values[\count($values) - 1]['Number'] ?? '';
        $key = \count($values).':'.(\is_scalar($first) ? $first : '').':'.(\is_scalar($last) ? $last : '');

        return new Randomizer(new Xoshiro256StarStar(hash('xxh3', $key, true).hash('xxh3', $key.'#', true).hash('xxh3', $key.'##', true).hash('xxh3', $key.'###', true)));
    }

    /**
     * Whether at least a quarter of 16 subjects, one pseudo-random pick from each sixteenth of the range, hold ` (`, which every yEnc part
     * count needs. Ranges of mostly plain text, which the PHP rule rejects fastest, stay on PHP
     * without loading the router. It can only keep a range on PHP, never send one to Rust.
     *
     * @param  array<int, array<string, mixed>>  $headers
     */
    private static function mayCarryPartCounts(array $headers): bool
    {
        $values = array_is_list($headers) ? $headers : array_values($headers);
        $count = \count($values);
        $random = self::sampler($values);
        $hits = 0;
        for ($probe = 0; $probe < 16; $probe++) {
            // One pseudo-random subject from each of 16 equal slices, so no periodic ordering decides.
            $subject = $values[$random->getInt(intdiv($probe * $count, 16), intdiv(($probe + 1) * $count, 16) - 1)]['Subject'] ?? null;
            if (\is_string($subject) && str_contains($subject, ' (')) {
                $hits++;
            }
        }

        return $hits >= 4;
    }

    /**
     * Summarizes sampled subjects the way {@see mayCarryPartCounts()} scores them: how many are
     * strings and how many hold ` (`. Four hits keep a range eligible for the router.
     *
     * @param  array<array-key, mixed>  $subjects
     * @return array{samples: int, strings: int, hits: int, may_carry_part_counts: bool}
     */
    public static function summarizeSubjectSample(array $subjects): array
    {
        return (self::usesRustFfi() ? self::summarizeSubjectSampleNative($subjects) : null) ?? self::summarizeSubjectSamplePhp($subjects);
    }

    /**
     * @param  array<array-key, mixed>  $subjects
     * @return array{samples: int, strings: int, hits: int, may_carry_part_counts: bool}
     */
    private static function summarizeSubjectSamplePhp(array $subjects): array
    {
        $strings = $hits = 0;
        foreach ($subjects as $subject) {
            if (\is_string($subject)) {
                $strings++;
                if (str_contains($subject, ' (')) {
                    $hits++;
                }
            }
        }

        return ['samples' => \count($subjects), 'strings' => $strings, 'hits' => $hits, 'may_carry_part_counts' => $hits >= 4];
    }

    /**
     * @param  array<array-key, mixed>  $subjects
     * @return array{samples: int, strings: int, hits: int, may_carry_part_counts: bool}|null
     */
    private static function summarizeSubjectSampleNative(array $subjects): ?array
    {
        $input = pack('q', \count($subjects));
        foreach ($subjects as $subject) {
            $input .= \is_string($subject) ? self::packRustString($subject) : pack('q', -1);
        }
        $fields = self::unpackRustInts(self::callRustFfi(1, 0, $input), 4);
        if ($fields === null || $fields[3] & ~1) {
            return null;
        }

        return ['samples' => $fields[0], 'strings' => $fields[1], 'hits' => $fields[2], 'may_carry_part_counts' => $fields[3] === 1];
    }

    public static function useRustFfi(?bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = null;
        self::$ffi = null;
    }

    private static function packRustString(string $value): string
    {
        return pack('q', strlen($value)).$value;
    }

    /**
     * @return list<int>|null
     */
    private static function unpackRustInts(?string $bytes, int $count): ?array
    {
        if ($bytes === null || strlen($bytes) !== $count * 8) {
            return null;
        }
        $values = unpack('q*', $bytes);

        return $values === false ? null : array_values($values);
    }

    /**
     * Calls the Wave 38.1 export, retrying once with a larger scratch buffer when it reports `-required`.
     */
    private static function callRustFfi(int $operation, int $flags, string $input): ?string
    {
        if (strlen($input) > self::RUST_MAX_INPUT) {
            return null;
        }
        try {
            self::$ffi ??= RustPipelineLibrary::resolveDomain('cleaning_w381', self::FFI_CDEF) ?? false;
            if (self::$ffi === false) {
                return null;
            }
            $ffi = self::$ffi;
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, strlen($input) + 256, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w381_obfuscation_gate_router_admin_names($operation, $flags, $input, strlen($input), $buffer, $capacity);
            if (is_int($written) && $written < -1) {
                if (-$written <= $capacity || -$written > self::RUST_MAX_INPUT) {
                    return null;
                }
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w381_obfuscation_gate_router_admin_names($operation, $flags, $input, strlen($input), $buffer, $capacity);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return null;
        }
    }
}
