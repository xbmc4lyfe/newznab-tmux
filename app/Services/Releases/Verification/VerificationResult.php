<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

final readonly class VerificationResult
{
    public const string FFI_CDEF = <<<'C'
        intptr_t nntmux_w18_verification_metadata_fingerprint(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);
        C;

    // Readonly classes cannot have static properties; preserve readonly value semantics.
    private static function rustEnabled(?bool $enabled = null): bool
    {
        static $useRustFfi = false;
        if ($enabled !== null) {
            $useRustFfi = $enabled;
        }

        return $useRustFfi;
    }

    public static function setUseRustFfi(bool $enabled): void
    {
        self::rustEnabled($enabled);
    }

    /** Decline objects/resources/cycles before serialize() could invoke user code. */
    private static function nativeValueSupported(mixed $value, int &$remaining, int $depth): bool
    {
        if (--$remaining < 0 || $depth > 24) {
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (! self::nativeValueSupported($item, $remaining, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || is_scalar($value);
    }

    /**
     * @param  list<mixed>  $arguments
     * @return array{value: mixed}|null An envelope distinguishes a native null from failure.
     */
    private static function computeNative(int $operation, array $arguments): ?array
    {
        if (! self::rustEnabled()) {
            return null;
        }
        try {
            $remaining = 100000;
            if (! self::nativeValueSupported($arguments, $remaining, 0)) {
                return null;
            }
            $ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF);
            if ($ffi === null) {
                return null;
            }
            $input = serialize($arguments);
            if (strlen($input) > 64 * 1024 * 1024) {
                return null;
            }
            $capacity = 0;
            $output = RustPipelineLibrary::obtainScratch($ffi, max(4096, strlen($input) + 1024), $capacity);
            if ($output === null) {
                return null;
            }
            // @phpstan-ignore method.notFound (Declared by FFI_CDEF.)
            $written = $ffi->nntmux_w18_verification_metadata_fingerprint($operation, $input, strlen($input), $output, $capacity);
            if (! is_int($written) || $written < 2 || $written > $capacity) {
                return null;
            }

            return ['value' => unserialize(FFI::string($output, $written), ['allowed_classes' => false])];
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $manifest */
    public function __construct(public string $verdict, public string $reason, public array $manifest = [], public bool $waitingForHeaders = false,
        public bool $headerRejected = false, public bool $deterministic = false) {}

    public function passed(): bool
    {
        return self::rustEnabled() ? ($this->passedNative() ?? $this->passedPhp()) : $this->passedPhp();
    }

    public function passedPhp(): bool
    {
        return in_array($this->verdict, ['complete', 'repaired', 'sampled'], true);
    }

    public function passedNative(): ?bool
    {
        $result = self::computeNative(5, [$this->verdict])['value'] ?? null;

        return is_bool($result) ? $result : null;
    }

    // Rust is the default only while the paired W58.6 benchmark clears 1.5x on all four metrics.
    private const bool W58_RUST_FFI_DEFAULT = true;

    public const string W58_FFI_CDEF = <<<'C'
        int32_t nntmux_w586_result_summary_batch(const char *strings, size_t strings_len, const char *string_twin, size_t string_twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    private const int W58_MAX_ITEMS = 1_048_576;

    public const int W58_RESULT_HEADER = 25;

    /** Verdict => index (5 names none). */
    private const array W58_VERDICT_INDEX = ['complete' => 0, 'repaired' => 1, 'sampled' => 2, 'unknown' => 3, 'unrepairable' => 4];

    /**
     * Summarize verification results given each verdict, reason, sampled article count (clamped to
     * 0..2^31-1) and flags (waiting for headers 1, header rejected 2, deterministic 4). Passed verdicts
     * are 0-2. Reason class (case-sensitive, first match): 0 empty, 1 budget, 2 identity or collision,
     * 3 byte range or overlap, 4 retry, 5 header, 6 other. Retry class 0 passed, 1 deterministic, 2
     * waiting, 3 rejected, 4 retryable (rejected, then waiting, then deterministic win). Article bucket
     * 0 none, 1 one, 2 up to 4, 3 up to 16, 4 more.
     *
     * Layout: `[0]` count, `[1..6]` per verdict, `[7]` passed, `[8..14]` per reason class, `[15..19]`
     * per retry class, `[20]` articles, `[21]` most articles, `[22]` first failing index (-1 when none),
     * `[23]` pass ppm, `[24]` FNV-1a digest; then per result `verdict | passed << 3 | reason << 4 |
     * retry << 7 | flags << 10 | bucket << 13 | min(reason bytes, 65535) << 16 | articles << 32`.
     *
     * @param  array<array-key, string>  $verdicts
     * @param  array<array-key, string>  $reasons
     * @param  array<array-key, int>  $articles
     * @param  array<array-key, int>  $flags
     * @return list<int>
     */
    public static function summarizeResultBatch(array $verdicts, array $reasons, array $articles, array $flags): array
    {
        return (self::usesW58RustFfi() ? self::summarizeResultBatchNative($verdicts, $reasons, $articles, $flags) : null)
            ?? self::summarizeResultBatchPhp($verdicts, $reasons, $articles, $flags);
    }

    /**
     * @param  array<array-key, string>  $verdicts
     * @param  array<array-key, string>  $reasons
     * @param  array<array-key, int>  $articles
     * @param  array<array-key, int>  $flags
     * @return list<int>
     */
    public static function summarizeResultBatchPhp(array $verdicts, array $reasons, array $articles, array $flags): array
    {
        $verdicts = array_values($verdicts);
        $reasons = array_values($reasons);
        $articles = array_values($articles);
        $flags = array_values($flags);
        $count = \count($verdicts);
        $out = array_fill(0, self::W58_RESULT_HEADER, 0);
        $out[0] = $count;
        $out[22] = -1;
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($verdicts as $at => $verdict) {
            $case = self::W58_VERDICT_INDEX[(string) $verdict] ?? 5;
            $reason = (string) ($reasons[$at] ?? '');
            $class = match (true) {
                $reason === '' => 0,
                str_contains($reason, 'budget') => 1,
                str_contains($reason, 'identity') || str_contains($reason, 'collide') => 2,
                str_contains($reason, 'byte range') || str_contains($reason, 'overlapping') => 3,
                str_contains($reason, 'retry') => 4,
                str_contains($reason, 'header') => 5,
                default => 6,
            };
            $sampled = max(0, min((int) ($articles[$at] ?? 0), 2_147_483_647));
            $flag = (int) ($flags[$at] ?? 0) & 7;
            $passed = $case <= 2;
            $retry = match (true) {
                $passed => 0,
                ($flag & 2) !== 0 => 3,
                ($flag & 1) !== 0 => 2,
                ($flag & 4) !== 0 => 1,
                default => 4,
            };
            $bucket = match (true) {
                $sampled === 0 => 0,
                $sampled === 1 => 1,
                $sampled <= 4 => 2,
                $sampled <= 16 => 3,
                default => 4,
            };
            $out[1 + $case]++;
            $out[7] += (int) $passed;
            $out[8 + $class]++;
            $out[15 + $retry]++;
            $out[20] += $sampled;
            if ($sampled > $out[21]) {
                $out[21] = $sampled;
            }
            if (! $passed && $out[22] < 0) {
                $out[22] = $at;
            }
            $row = $case | (int) $passed << 3 | $class << 4 | $retry << 7 | $flag << 10 | $bucket << 13 | min(\strlen($reason), 65_535) << 16
                | $sampled << 32;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        if ($count > 0) {
            $out[23] = intdiv($out[7] * 1_000_000, $count);
        }
        $out[24] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust summary, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, string>  $verdicts
     * @param  array<array-key, string>  $reasons
     * @param  array<array-key, int>  $articles
     * @param  array<array-key, int>  $flags
     * @return list<int>|null
     */
    public static function summarizeResultBatchNative(array $verdicts, array $reasons, array $articles, array $flags): ?array
    {
        $count = \count($verdicts);
        if ($count > self::W58_MAX_ITEMS || \count($reasons) !== $count || \count($articles) !== $count || \count($flags) !== $count) {
            return null;
        }
        [$joined, $twin] = self::w58Strings(array_values($verdicts), array_values($reasons));
        $ints = pack('q*', ...array_values($articles), ...array_values($flags));
        $fields = self::W58_RESULT_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w58Call($fields, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w586_result_summary_batch($joined, \strlen($joined), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $fields));
    }

    /**
     * Run `$callback` with the Wave 58.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW58RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w58RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w58RustFfiState('set', $previous);
        }
    }

    public static function usesW58RustFfi(): bool
    {
        $forced = self::w58RustFfiState();

        return \is_bool($forced) ? $forced : self::W58_RUST_FFI_DEFAULT;
    }

    /**
     * Wave 58.6 routing and binding in function statics (no static properties, so readonly classes can share it).
     * No `$op` returns the forced routing (null for the default); `'set'` replaces it with `$val`
     * and returns the previous value; `'binding'` returns the cached `additional_w58` binding and
     * an output buffer of at least `$val` values, or null.
     */
    private static function w58RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $enabled */
        static $enabled = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $buffer */
        static $buffer = null;
        static $capacity = 0;
        if ($op === 'set') {
            $previous = $enabled;
            $enabled = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op !== 'binding') {
            return $enabled;
        }
        $fields = \is_int($val) ? $val : 0;
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w58', self::W58_FFI_CDEF) ?? false;
            if ($ffi === false) {
                return null;
            }
            if ($buffer === null || $capacity < $fields) {
                $capacity = max(256, $fields);
                $buffer = $ffi->new('int64_t['.$capacity.']');
            }
        } catch (Throwable) {
            return null;
        }

        return $buffer === null ? null : [$ffi, $buffer];
    }

    /**
     * The batch's `$fields` output words from one Wave 58.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w58Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w58RustFfiState('binding', $fields);
        if ($binding === null) {
            return null;
        }
        try {
            if ($call($binding[0], $binding[1]) !== 1) {
                return null;
            }
            $values = unpack('P*', FFI::string($binding[1], $fields * 8));
        } catch (Throwable) {
            return null;
        }

        return $values === false ? null : array_values($values);
    }

    /**
     * Columns NUL-joined one after another and, when a value holds a NUL, the same columns joined
     * with 0x01 so Rust finds the separators where the two differ.
     *
     * @param  list<string>  ...$columns
     * @return array{0: string, 1: string}
     */
    private static function w58Strings(array ...$columns): array
    {
        $joined = '';
        $items = 0;
        foreach ($columns as $column) {
            if ($column !== []) {
                $joined .= ($items > 0 ? "\0" : '').implode("\0", $column);
                $items += \count($column);
            }
        }
        if ($items === 0 || substr_count($joined, "\0") === $items - 1) {
            return [$joined, ''];
        }
        $twin = '';
        $items = 0;
        foreach ($columns as $column) {
            if ($column !== []) {
                $twin .= ($items > 0 ? "\x01" : '').implode("\x01", $column);
                $items += \count($column);
            }
        }

        return [$joined, $twin];
    }
}
