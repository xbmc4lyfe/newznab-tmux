<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use App\Support\IrcChannelList;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
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

    /**
     * Disabled: one str_contains() per subject costs about what marshaling it does, so five paired runs
     * measured only 1.04-1.14x common mean, 0.87-1.03x common p99, 1.33-1.37x uncommon mean and
     * 1.19-1.36x uncommon p99 (see the W45 parity test), below the 1.5x rule.
     */
    public const bool W451_RUST_FFI_DEFAULT = false;

    public const string W451_FFI_CDEF = <<<'C'
        ssize_t nntmux_w451_obfuscation_gate_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W451_SUMMARY_KEYS = ['total_subjects', 'empty_subject_count', 'part_marker_count'];

    private static ?bool $w451UseRustFfi = null;

    private static FFI|false|null $w451Ffi = null;

    /** Null restores the benchmark default. */
    public static function useW451RustFfi(?bool $enable = true): void
    {
        self::$w451UseRustFfi = $enable;
    }

    public static function usesW451RustFfi(): bool
    {
        return self::$w451UseRustFfi ?? self::W451_RUST_FFI_DEFAULT;
    }

    /** Restores the benchmark default and drops the cached library handle; earlier toggles are untouched. */
    public static function resetW451RustFfi(): void
    {
        self::$w451UseRustFfi = null;
        self::$w451Ffi = null;
    }

    /**
     * Summarizes subjects the way {@see mayCarryPartCounts()} scores each sample: subjects holding
     * ` (`, which every yEnc part count needs.
     * Entries come from strings, integers, or the first non-null Subject, subject array field; other
     * values (including objects) count as empty entries.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeGateSubjectBatch(array $rows): array
    {
        return self::usesW451RustFfi() ? self::summarizeGateSubjectBatchNative($rows) : self::summarizeGateSubjectBatchPhp($rows);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeGateSubjectBatchNative(array $rows): array
    {
        $values = IrcChannelList::w451Invoke(self::$w451Ffi, self::W451_FFI_CDEF, 'nntmux_w451_obfuscation_gate_batch', $rows, ['Subject', 'subject'], \count(self::W451_SUMMARY_KEYS));

        return $values === null ? self::summarizeGateSubjectBatchPhp($rows) : array_combine(self::W451_SUMMARY_KEYS, $values);
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeGateSubjectBatchPhp(array $rows): array
    {
        $summary = array_fill_keys(self::W451_SUMMARY_KEYS, 0);
        foreach (IrcChannelList::w451Entries($rows, ['Subject', 'subject']) as $entry) {
            $summary['total_subjects']++;
            if ($entry === '') {
                $summary['empty_subject_count']++;

                continue;
            }
            $summary['part_marker_count'] += (int) str_contains($entry, ' (');
        }

        return $summary;
    }

    /**
     * Enabled: the paired benchmark in the W53 parity test measures 4.68x common mean, 3.90x common
     * p99, 3.91x uncommon mean and 2.51x uncommon p99 (per-metric minimum of three runs), above the
     * 1.5x rule.
     */
    private const bool W53_RUST_FFI_DEFAULT = true;

    private const string W53_FFI_CDEF = 'ssize_t nntmux_w531_obfuscation_part_count_summary_batch(const char *subjects, size_t subjects_len, size_t count, int64_t *out, size_t out_cap);';

    public static function usesW53RustFfi(): bool
    {
        $enabled = self::w53RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W53_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 53 batch routed to Rust or PHP, then restores the routing. */
    public static function withW53RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w53RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w53RustFfiState('set', $previous);
        }
    }

    /** {@see summarizePartCounts()} keys after `subjects`, in the order the Rust export writes them. */
    private const array W53_PART_SUMMARY_KEYS = ['part_markers', 'part_counts', 'first_parts', 'final_parts', 'invalid_parts', 'max_total'];

    /**
     * Summarizes the yEnc part counts of a subject batch: subjects holding ` (` (the
     * {@see mayCarryPartCounts()} marker), subjects with a `(part/total)` count (the rightmost one,
     * 1-9 digits each), first parts, final parts (`part === total`), invalid counts (`part` 0 or
     * above `total`, excluded from the rest) and the largest valid total.
     *
     * @param  array<array-key, string>  $subjects
     * @return array{subjects: int, part_markers: int, part_counts: int, first_parts: int, final_parts: int, invalid_parts: int, max_total: int}
     */
    public static function summarizePartCounts(array $subjects): array
    {
        return self::usesW53RustFfi() ? self::summarizePartCountsNative($subjects) : self::summarizePartCountsPhp($subjects);
    }

    /**
     * @param  array<array-key, string>  $subjects
     * @return array{subjects: int, part_markers: int, part_counts: int, first_parts: int, final_parts: int, invalid_parts: int, max_total: int}
     */
    public static function summarizePartCountsPhp(array $subjects): array
    {
        $markers = $counts = $first = $final = $invalid = $max = 0;
        foreach ($subjects as $subject) {
            if (str_contains($subject, ' (')) {
                $markers++;
            }
            if (preg_match('/.*\((\d{1,9})\/(\d{1,9})\)/s', $subject, $match) !== 1) {
                continue;
            }
            $counts++;
            $part = (int) $match[1];
            $total = (int) $match[2];
            if ($part === 0 || $part > $total) {
                $invalid++;

                continue;
            }
            if ($part === 1) {
                $first++;
            }
            if ($part === $total) {
                $final++;
            }
            if ($total > $max) {
                $max = $total;
            }
        }

        return ['subjects' => \count($subjects), 'part_markers' => $markers, 'part_counts' => $counts, 'first_parts' => $first, 'final_parts' => $final, 'invalid_parts' => $invalid, 'max_total' => $max];
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or declines the batch (a subject
     * holding a NUL byte).
     *
     * @param  array<array-key, string>  $subjects
     * @return array{subjects: int, part_markers: int, part_counts: int, first_parts: int, final_parts: int, invalid_parts: int, max_total: int}
     */
    public static function summarizePartCountsNative(array $subjects): array
    {
        $count = \count($subjects);
        $buffer = $count === 0 ? null : self::w53Buffer(6);
        if ($buffer === null) {
            return self::summarizePartCountsPhp($subjects);
        }
        [$ffi, $out, $cap] = $buffer;
        $joined = implode("\0", $subjects);
        try {
            // @phpstan-ignore method.notFound
            $words = self::w53Decode($out, $ffi->nntmux_w531_obfuscation_part_count_summary_batch($joined, \strlen($joined), $count, $out, $cap), 6);
        } catch (Throwable) {
            $words = null;
        }
        if ($words === null || \count($words) !== 6) {
            return self::summarizePartCountsPhp($subjects);
        }

        return ['subjects' => $count] + array_combine(self::W53_PART_SUMMARY_KEYS, $words);
    }

    /**
     * Lazily resolved binding plus the reusable output buffer sized for `$words` words.
     *
     * @return array{0: FFI, 1: CData, 2: int}|null
     */
    private static function w53Buffer(int $words): ?array
    {
        $buffer = self::w53RustFfiState('out', $words);

        return \is_array($buffer) ? $buffer : null;
    }

    /** @return list<int>|null The `$written` words the export reported, or null when it declined. */
    private static function w53Decode(CData $out, mixed $written, int $maxWords): ?array
    {
        if (! \is_int($written) || $written < 0 || $written > $maxWords) {
            return null;
        }
        if ($written === 0) {
            return [];
        }
        $decoded = unpack('P*', FFI::string($out, $written * 8));

        /** @var list<int>|null */
        return \is_array($decoded) ? array_values($decoded) : null;
    }

    /**
     * Wave 53 routing override (`set`) plus the lazily resolved binding and reusable `int64_t`
     * output buffer (`out`, sized for `$val` words), kept in function-local statics.
     */
    private static function w53RustFfiState(?string $op = null, mixed $val = null): mixed
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
            case 'out':
                $ffi ??= RustPipelineLibrary::resolveDomain('obfuscation_blacklist_cat_w53', self::W53_FFI_CDEF) ?? false;
                if (! $ffi instanceof FFI || ! \is_int($val) || $val < 1) {
                    return null;
                }
                if (! $out instanceof CData || $outCap < $val) {
                    $outCap = max(512, $val * 2);
                    $out = $ffi->new("int64_t[{$outCap}]");
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled;
        }
    }
}
