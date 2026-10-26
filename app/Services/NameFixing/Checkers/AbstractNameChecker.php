<?php

declare(strict_types=1);

namespace App\Services\NameFixing\Checkers;

use App\Services\NameFixing\Contracts\NameCheckerInterface;
use App\Services\NameFixing\Data\NameFixResult;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;

/**
 * Abstract base class for name checkers.
 *
 * Provides common functionality for pattern-based name checking.
 */
abstract class AbstractNameChecker implements NameCheckerInterface
{
    private const string FFI_CDEF = <<<'C'
        ssize_t nntmux_name_fixing_query_pass(uint32_t mode, const char *input, size_t len, int64_t first, int64_t second, char *out, size_t cap);
        C;

    // Retained PHP: the paired caller benchmark must pass all four 1.5x gates.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    /** Negative ABI results retain the exact PHP implementation. */
    private static function queryPassNative(int $mode, ?string $input, int $first = 0, int $second = 0, int $needed = 4096): ?string
    {
        if ($input === null || strlen($input) > intdiv(PHP_INT_MAX - 4096, 8)) {
            return null;
        }
        try {
            $ffi = RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF);
            if ($ffi === null) {
                return null;
            }
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, max($needed, strlen($input) * 8 + 4096), $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_name_fixing_query_pass($mode, $input, strlen($input), $first, $second, $buffer, $capacity);

            return is_int($written) && $written >= 0 && $written <= $capacity
                ? ($written === 0 ? '' : FFI::string($buffer, $written)) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param list<string> $fields */
    private static function queryPassFields(array $fields): ?string
    {
        $input = '';
        foreach ($fields as $field) {
            if (strlen($field) > 0xFFFFFFFF) {
                return null;
            }
            $input .= pack('V', strlen($field)).$field;
        }

        return $input;
    }

    protected int $priority = 50;

    protected string $name = 'Abstract';

    /**
     * Get the patterns to check against.
     *
     * @return array<string, string> Pattern name => regex
     */
    abstract protected function getPatterns(): array;

    /**
     * Format the method name for a matched pattern.
     */
    abstract protected function formatMethod(string $patternName): string;

    /**
     * {@inheritdoc}
     */
    public function check(object $release, string $textstring): ?NameFixResult
    {
        foreach ($this->getPatterns() as $patternName => $pattern) {
            $match = $this->matchPattern($pattern, $textstring);
            if ($match['matched']) {
                return NameFixResult::fromMatch(
                    newName: $match['text'],
                    method: $this->formatMethod($patternName),
                    checkerName: $this->getName(),
                );
            }
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return $this->name;
    }

    /** @return array{matched: bool, text: string} */
    private function matchPattern(string $pattern, string $subject): array
    {
        return (self::$useRustFfi ? $this->matchPatternNative($pattern, $subject) : null) ?? $this->matchPatternPhp($pattern, $subject);
    }

    /** @return array{matched: bool, text: string}|null */
    private function matchPatternNative(string $pattern, string $subject): ?array
    {
        $raw = self::queryPassNative(17, self::queryPassFields([$pattern, $subject]));
        $fields = $raw === null ? null : self::queryPassDecode($raw);

        return $fields !== null && count($fields) === 2 ? ['matched' => $fields[0] === '1', 'text' => $fields[1]] : null;
    }

    /** @return array{matched: bool, text: string} */
    private function matchPatternPhp(string $pattern, string $subject): array
    {
        $matched = (bool) preg_match($pattern, $subject, $matches);

        return ['matched' => $matched, 'text' => $matched ? $matches[0] : ''];
    }

    /**
     * Convert a pattern name constant to a readable method description.
     */
    protected function patternNameToMethod(string $patternName): string
    {
        return (self::$useRustFfi ? $this->patternNameToMethodNative($patternName) : null) ?? $this->patternNameToMethodPhp($patternName);
    }

    private function patternNameToMethodNative(string $patternName): ?string
    {
        return self::queryPassNative(4, $patternName);
    }

    protected function patternNameToMethodPhp(string $patternName): string
    {
        return str_replace('_', ' ', strtolower($patternName));
    }

    /** @return list<string>|null */
    private static function queryPassDecode(string $raw): ?array
    {
        $fields = [];
        $offset = 0;
        $length = strlen($raw);
        while ($offset < $length) {
            if ($length - $offset < 4) {
                return null;
            }
            $size = unpack('Vsize', substr($raw, $offset, 4))['size'];
            $offset += 4;
            if ($size > $length - $offset) {
                return null;
            }
            $fields[] = substr($raw, $offset, $size);
            $offset += $size;
        }

        return $fields;
    }

    /** Wave 57.3 paired benchmarks decide this default; see RustNameFixingCheckersAbstractAppGameMovieTvW57ParityTest. */
    public const bool W57_RUST_FFI_DEFAULT = true;

    public const string W57_ABSTRACT_CHECKER_FFI_CDEF = <<<'C'
        ssize_t nntmux_w57_name_checker_candidate_eligibility_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-name words of w57CandidateEligibilityBatch(). */
    public const int W57_CANDIDATE_ELIGIBILITY_TRAILER = 8;

    public static function usesW57RustFfi(): bool
    {
        $override = self::w57RustFfiState();

        return \is_bool($override) ? $override : self::W57_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the Wave 57 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW57RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w57RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w57RustFfiState('set', $previous);
        }
    }

    /** Base-class toggle name that subclasses' own usesW57RustFfi() never shadows. */
    public static function usesAbstractNameCheckerW57RustFfi(): bool
    {
        return self::usesW57RustFfi();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withAbstractNameCheckerW57RustFfi(bool $enabled, callable $callback): mixed
    {
        return self::withW57RustFfi($enabled, $callback);
    }

    /**
     * Wave 57.3 candidate eligibility. Per name, after PHP trim(), with tokens the maximal ASCII
     * alphanumeric runs: `fnv1a32(lowercase tokens joined by '.') | min(tokens, 63) << 32 | flags << 38
     * | score << 45 | min(length, 1023) << 53`. Flags: 1 dot, 2 underscore, 4 space, 8 group suffix
     * (a last '-' after the first byte followed by 2-15 alphanumerics), 16 non-ASCII byte, 32 control
     * byte and 64 eligible (at least 8 bytes and 2 tokens, no control byte, a dot, underscore or space,
     * and a letter). Score is `min(255, 4 * min(tokens, 20) + 20 group + 10 dot + 5 underscore +
     * min(length, 200) / 10)`. Then the eligible, group, non-ASCII, control and empty counts, the max
     * score, the total tokens and the distinct hashes.
     *
     * @param  array<array-key, string>  $names
     * @return list<int>
     */
    public static function w57CandidateEligibilityBatch(array $names): array
    {
        return self::usesW57RustFfi() ? self::w57CandidateEligibilityBatchNative($names) : self::w57CandidateEligibilityBatchPhp($names);
    }

    /**
     * @param  array<array-key, string>  $names
     * @return list<int>
     */
    public static function w57CandidateEligibilityBatchNative(array $names): array
    {
        return self::w57Invoke($names, \count($names) + self::W57_CANDIDATE_ELIGIBILITY_TRAILER) ?? self::w57CandidateEligibilityBatchPhp($names);
    }

    /**
     * @param  array<array-key, string>  $names
     * @return list<int>
     */
    public static function w57CandidateEligibilityBatchPhp(array $names): array
    {
        $out = [];
        $totals = [0, 0, 0, 0, 0, 0, 0];
        $keys = [];
        foreach ($names as $name) {
            $trimmed = trim((string) $name);
            $length = \strlen($trimmed);
            preg_match_all('/[a-z0-9]+/', strtolower($trimmed), $matches);
            $tokens = \count($matches[0]);
            $hash = (int) hexdec(hash('fnv1a32', implode('.', $matches[0])));
            $dot = str_contains($trimmed, '.');
            $underscore = str_contains($trimmed, '_');
            $space = str_contains($trimmed, ' ');
            $dash = strrpos($trimmed, '-');
            $group = $dash !== false && $dash > 0 && preg_match('/^[A-Za-z0-9]{2,15}$/D', substr($trimmed, $dash + 1)) === 1;
            $nonAscii = preg_match('/[\x80-\xff]/', $trimmed) === 1;
            $control = preg_match('/[\x00-\x1f\x7f]/', $trimmed) === 1;
            $letter = preg_match('/[A-Za-z]/', $trimmed) === 1;
            $eligible = $length >= 8 && $tokens >= 2 && ! $control && ($dot || $underscore || $space) && $letter;
            $flags = ($dot ? 1 : 0) | ($underscore ? 2 : 0) | ($space ? 4 : 0) | ($group ? 8 : 0)
                | ($nonAscii ? 16 : 0) | ($control ? 32 : 0) | ($eligible ? 64 : 0);
            $score = min(255, 4 * min($tokens, 20) + ($group ? 20 : 0) + ($dot ? 10 : 0) + ($underscore ? 5 : 0) + intdiv(min($length, 200), 10));
            $totals[0] += $eligible ? 1 : 0;
            $totals[1] += $group ? 1 : 0;
            $totals[2] += $nonAscii ? 1 : 0;
            $totals[3] += $control ? 1 : 0;
            $totals[4] += $length === 0 ? 1 : 0;
            $totals[5] = max($totals[5], $score);
            $totals[6] += $tokens;
            $keys[$hash] = true;
            $out[] = $hash | min($tokens, 63) << 32 | $flags << 38 | $score << 45 | min($length, 1023) << 53;
        }
        array_push($out, ...$totals);
        $out[] = \count($keys);

        return $out;
    }

    /**
     * Wave 57 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'ffi' resolves the library (null when unavailable) and 'out' and
     * 'lens' return `int64_t` output and length-column buffers of at least $val words.
     */
    private static function w57RustFfiState(?string $op = null, mixed $val = null): mixed
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
            $ffi ??= RustPipelineLibrary::resolveDomain('name_checkers_w57', self::W57_ABSTRACT_CHECKER_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w57RustFfiState('ffi');
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
                $handle = self::w57RustFfiState('ffi');
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
     * Calls the Wave 57.3 export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w57Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w57RustFfiState('ffi');
            $out = self::w57RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w57RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W57_ABSTRACT_CHECKER_FFI_CDEF.)
            $written = $ffi->nntmux_w57_name_checker_candidate_eligibility_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (\Throwable) {
            return null;
        }
    }
}
