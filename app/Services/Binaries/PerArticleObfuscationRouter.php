<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use App\Support\IrcChannelList;
use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

/**
 * Decides, from a sample of a header range, whether {@see NativePerArticleObfuscation} should mark
 * it or the PHP rule in {@see HeaderParser::markPerArticleObfuscated()} should. Loaded only for
 * ranges of HeaderParser::NATIVE_MIN_HEADERS or more that pass {@see PerArticleObfuscationGate}'s check.
 */
final class PerArticleObfuscationRouter
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w381_obfuscation_gate_router_admin_names(uint32_t operation, uint32_t flags, const char *input, size_t input_len, char *out_buf, size_t out_cap);
        C;

    /**
     * Rust is the default for summarizeSampleCosts(): the paired benchmark in
     * tests/Unit/RustObfuscationGateRouterAdminNamesParityTest.php measured 2.10-2.13x common mean,
     * 1.79-2.13x common p99 and 10.4-11.1x uncommon p99, above the 1.5x rule. The routing decision in
     * worthNativeMarking() stays on PHP, so a range routed to PHP still never loads the library.
     */
    public const bool RUST_FFI_DEFAULT = true;

    private const int RUST_MAX_INPUT = 16_777_216;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /** Headers sampled to decide whether a range is worth marshalling to Rust: one in 128, 32 to 128. */
    private const int SAMPLE_MIN = 32;

    private const int SAMPLE_MAX = 128;

    /**
     * Per-header cost by sampled class (no part count, a part count only, a candidate) as
     * [PHP base ns, PHP tenths of a ns per subject byte, Rust base ns, Rust tenths of a ns per byte].
     * Fitted to XOVER-shaped ranges of 60-480 byte subjects with
     * scripts/benchmark-per-article-obfuscation.php on Linux arm64; CI requires that benchmark to
     * qualify on both Linux x86_64 and arm64 (archive-sample-performance.yml).
     */
    private const array COSTS = [[210, 5, 265, 10], [80, 80, 110, 39], [1350, 60, 330, 37]];

    /**
     * A range goes to Rust when its predicted speedup reaches 2x. The prices run about a sixth
     * optimistic on mixed ranges, so this keeps the measured speedup above the 1.5x rule.
     */
    private const int PREDICTED_SPEEDUP_PERCENT = 200;

    /**
     * Building the wrapper, loading the library and running its probe took about 0.6 ms median and
     * 0.8-1.2 ms p99 per process; a range that would load it must be predicted to save 4 ms.
     */
    private const int FIRST_LOAD_SAVING_NS = 4_000_000;

    private const string DIGITS = '0123456789';

    private const string ALNUM = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    /** PCRE's non-UTF `\s`. */
    private const string WHITESPACE = " \t\n\v\f\r";

    /** Mirrors NativePerArticleObfuscation::MAX_SUBJECT_LENGTH: Rust declines longer subjects. */
    public const int MAX_SUBJECT_LENGTH = 512;

    /**
     * Whether the Rust port should take this range of HeaderParser::NATIVE_MIN_HEADERS or more.
     * These stay on PHP:
     * - ranges where a spread sample, priced with COSTS, predicts under 2x: the PHP rule
     *   rejects subjects without a part count faster than they can be handed to Rust;
     * - before the library is loaded, ranges whose predicted saving is under
     *   FIRST_LOAD_SAVING_NS (building the wrapper, loading the library and its probe);
     * - ranges with a subject over MAX_SUBJECT_LENGTH, which Rust would decline.
     * Separate from the wrapper, so a range routed to PHP never loads the wrapper class.
     *
     * @param  array<int, array<string, mixed>>  $headers
     */
    public static function worthNativeMarking(array $headers, bool $libraryLoaded): bool
    {
        $values = array_is_list($headers) ? $headers : array_values($headers);
        $count = \count($values);
        // One pseudo-random header from each of $samples equal slices, so no periodic ordering of the
        // range decides the prediction.
        $samples = min(self::SAMPLE_MAX, max(self::SAMPLE_MIN, intdiv($count, 128)));
        $random = PerArticleObfuscationGate::sampler($values);
        $php = $rust = 0;
        for ($slice = 0; $slice < $samples; $slice++) {
            $start = intdiv($slice * $count, $samples);
            $index = $random->getInt($start, max($start, intdiv(($slice + 1) * $count, $samples) - 1));
            $subject = $values[$index]['Subject'] ?? null;
            $bytes = is_string($subject) ? strlen($subject) : 0;
            [$phpBase, $phpPerByte, $rustBase, $rustPerByte] = self::COSTS[self::classifySample($subject)];
            $php += $phpBase + intdiv($phpPerByte * $bytes, 10);
            $rust += $rustBase + intdiv($rustPerByte * $bytes, 10);
        }
        if ($php * 100 < $rust * self::PREDICTED_SPEEDUP_PERCENT) {
            return false;
        }
        // Before this process has loaded the library, the predicted saving must also pay for
        // loading it and running its probe.
        if (! $libraryLoaded && intdiv(($php - $rust) * $count, $samples) < self::FIRST_LOAD_SAVING_NS) {
            return false;
        }
        // A subject Rust would decline sends the range to PHP before any packing.
        $subjects = array_filter(array_column($values, 'Subject'), is_string(...));

        return $subjects === [] || max(array_map(strlen(...), $subjects)) <= self::MAX_SUBJECT_LENGTH;
    }

    /**
     * 0: no part count; 1: a part count only; 2: a candidate as the PHP rule sees it, a first
     * whitespace-led `(n/total)` with total of at least 2 and a 16+ alphanumeric token before it.
     * String scans rather than regexes, so a fresh process does not pay to compile patterns, and
     * linear in the subject length.
     */
    private static function classifySample(mixed $subject): int
    {
        // The subject pattern's negative lookahead rejects these at once (only with no leading
        // whitespace, where it has no shorter start to fall back on).
        if (! is_string($subject) || str_starts_with($subject, 'Usenet Index Post')) {
            return 0;
        }
        for ($open = strpos($subject, '('); $open !== false; $open = strpos($subject, '(', $open + 1)) {
            if ($open === 0 || strspn($subject, self::WHITESPACE, $open - 1, 1) === 0) {
                continue;
            }
            $part = strspn($subject, self::DIGITS, $open + 1);
            $totalAt = $open + 2 + $part;
            if ($part === 0 || ($subject[$totalAt - 1] ?? '') !== '/') {
                continue;
            }
            $total = strspn($subject, self::DIGITS, $totalAt);
            if ($total === 0 || ($subject[$totalAt + $total] ?? '') !== ')') {
                continue;
            }

            return (int) substr($subject, $totalAt, $total) >= 2 && self::hasToken($subject, $open) ? 2 : 1;
        }

        return 0;
    }

    /** Whether `$subject` holds a run of 16+ ASCII alphanumerics before byte `$end`. */
    private static function hasToken(string $subject, int $end): bool
    {
        $at = strcspn($subject, self::ALNUM, 0, $end);
        while ($at < $end) {
            $run = strspn($subject, self::ALNUM, $at, $end - $at);
            if ($run >= 16) {
                return true;
            }
            $at += $run;
            $at += strcspn($subject, self::ALNUM, $at, $end - $at);
        }

        return false;
    }

    /**
     * Prices sampled subjects the way {@see worthNativeMarking()} does: per-class counts, the
     * summed PHP and Rust cost predictions from COSTS, the longest subject, whether the prediction
     * reaches PREDICTED_SPEEDUP_PERCENT and whether every subject is within MAX_SUBJECT_LENGTH.
     *
     * @param  array<array-key, mixed>  $subjects
     * @return array{no_part_count: int, part_count: int, candidates: int, php_ns: int, rust_ns: int, max_length: int, predicts_speedup: bool, within_subject_limit: bool}
     */
    public static function summarizeSampleCosts(array $subjects): array
    {
        return (self::usesRustFfi() ? self::summarizeSampleCostsNative($subjects) : null) ?? self::summarizeSampleCostsPhp($subjects);
    }

    /**
     * @param  array<array-key, mixed>  $subjects
     * @return array{no_part_count: int, part_count: int, candidates: int, php_ns: int, rust_ns: int, max_length: int, predicts_speedup: bool, within_subject_limit: bool}
     */
    private static function summarizeSampleCostsPhp(array $subjects): array
    {
        $classes = [0, 0, 0];
        $php = $rust = $maxLength = 0;
        foreach ($subjects as $subject) {
            $class = self::classifySample($subject);
            $bytes = is_string($subject) ? strlen($subject) : 0;
            [$phpBase, $phpPerByte, $rustBase, $rustPerByte] = self::COSTS[$class];
            $classes[$class]++;
            $php += $phpBase + intdiv($phpPerByte * $bytes, 10);
            $rust += $rustBase + intdiv($rustPerByte * $bytes, 10);
            $maxLength = max($maxLength, $bytes);
        }

        return ['no_part_count' => $classes[0], 'part_count' => $classes[1], 'candidates' => $classes[2], 'php_ns' => $php, 'rust_ns' => $rust,
            'max_length' => $maxLength, 'predicts_speedup' => $php * 100 >= $rust * self::PREDICTED_SPEEDUP_PERCENT,
            'within_subject_limit' => $maxLength <= self::MAX_SUBJECT_LENGTH];
    }

    /**
     * @param  array<array-key, mixed>  $subjects
     * @return array{no_part_count: int, part_count: int, candidates: int, php_ns: int, rust_ns: int, max_length: int, predicts_speedup: bool, within_subject_limit: bool}|null
     */
    private static function summarizeSampleCostsNative(array $subjects): ?array
    {
        $input = pack('q', count($subjects));
        foreach ($subjects as $subject) {
            $input .= is_string($subject) ? self::packRustString($subject) : pack('q', -1);
        }
        $fields = self::unpackRustInts(self::callRustFfi(2, 0, $input), 8);
        if ($fields === null || ($fields[6] | $fields[7]) & ~1) {
            return null;
        }

        return ['no_part_count' => $fields[0], 'part_count' => $fields[1], 'candidates' => $fields[2], 'php_ns' => $fields[3], 'rust_ns' => $fields[4],
            'max_length' => $fields[5], 'predicts_speedup' => $fields[6] === 1, 'within_subject_limit' => $fields[7] === 1];
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

    /** Enabled: paired common/uncommon mean and p99 gains reach 1.5x in all five runs (min 2.42x; see the W45 parity test). */
    public const bool W451_RUST_FFI_DEFAULT = true;

    public const string W451_FFI_CDEF = <<<'C'
        ssize_t nntmux_w451_obfuscation_router_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W451_SUMMARY_KEYS = ['total_subjects', 'empty_subject_count', 'no_part_count', 'part_count', 'candidate_count', 'over_limit_count'];

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
     * Summarizes subjects with {@see classifySample()}: subjects without a part count, with a part count
     * only, or holding a candidate, and subjects over MAX_SUBJECT_LENGTH.
     * Entries come from strings, integers, or the first non-null Subject, subject array field; other
     * values (including objects) count as empty entries.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeRouterSubjectBatch(array $rows): array
    {
        return self::usesW451RustFfi() ? self::summarizeRouterSubjectBatchNative($rows) : self::summarizeRouterSubjectBatchPhp($rows);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeRouterSubjectBatchNative(array $rows): array
    {
        $values = IrcChannelList::w451Invoke(self::$w451Ffi, self::W451_FFI_CDEF, 'nntmux_w451_obfuscation_router_batch', $rows, ['Subject', 'subject'], \count(self::W451_SUMMARY_KEYS));

        return $values === null ? self::summarizeRouterSubjectBatchPhp($rows) : array_combine(self::W451_SUMMARY_KEYS, $values);
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeRouterSubjectBatchPhp(array $rows): array
    {
        $summary = array_fill_keys(self::W451_SUMMARY_KEYS, 0);
        $classes = ['no_part_count', 'part_count', 'candidate_count'];
        foreach (IrcChannelList::w451Entries($rows, ['Subject', 'subject']) as $entry) {
            $summary['total_subjects']++;
            if ($entry === '') {
                $summary['empty_subject_count']++;

                continue;
            }
            $summary[$classes[self::classifySample($entry)]]++;
            $summary['over_limit_count'] += (int) (strlen($entry) > self::MAX_SUBJECT_LENGTH);
        }

        return $summary;
    }
}
