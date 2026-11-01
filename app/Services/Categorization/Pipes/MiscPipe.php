<?php

declare(strict_types=1);

namespace App\Services\Categorization\Pipes;

use App\Models\Category;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\CategorizationService;
use App\Services\Categorization\Categorizers\MiscCategorizer;
use App\Services\Categorization\ReleaseContext;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Pipe for miscellaneous content and hash detection.
 * This runs FIRST with high priority to detect hashes early and prevent
 * them from being incorrectly categorized by group-based or content-based rules.
 *
 * When a hashed/obfuscated/gibberish release is detected, the passable is
 * locked to misc categories so no downstream pipe can override the result.
 */
class MiscPipe extends AbstractCategorizationPipe
{
    public static bool $useRustFfi = false;

    /**
     * Wave 39.3 production default is Rust: the paired benchmark in
     * tests/Unit/RustVerificationBarrierForwardPubControllersW39ParityTest.php measures at least
     * 2.9x (mean and p99, common and uncommon batches). PHP stays the fallback when the library is
     * unavailable. Named W393 so it stays distinct from this class's existing Rust FFI toggle.
     */
    public const bool W393_RUST_FFI_DEFAULT = true;

    public const string W393_FFI_CDEF = <<<'C'
        ssize_t nntmux_w393_misc_pipe_decision_metrics(
            const char *blob_ptr,
            size_t blob_len,
            const char *lens_ptr,
            size_t lens_len,
            int64_t min_length,
            char *out_buf,
            size_t out_cap
        );
        C;

    /** Trimmed subjects of at least this many hex digits (and nothing else) are hash-like. */
    public const int HASH_LIKE_MIN_LENGTH = 16;

    private static ?bool $w393RustFfiOverride = null;

    private static FFI|false|null $w393RustFfiHandle = null;

    protected int $priority = 1; // Run first to catch hashes early

    private MiscCategorizer $categorizer;

    /**
     * matchedBy prefixes that indicate a hashed/obfuscated/gibberish result
     * and should trigger the misc lock.
     */
    private const LOCK_PREFIXES = [
        'hash_',
        'obfuscated_',
        'gibberish_',
    ];

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function categorizeFfi(): ?FFI
    {
        return CategorizationService::categorizeFfi();
    }

    public function __construct()
    {
        $this->categorizer = new MiscCategorizer;
    }

    public function getName(): string
    {
        return 'Misc';
    }

    /**
     * Override handle() to lock the passable when a hash/gibberish release is detected.
     */
    public function handle(CategorizationPassable $passable, Closure $next): CategorizationPassable
    {
        // MiscPipe always runs regardless of shouldStopProcessing — it IS the first pipe.
        [$passable->miscAnalysis, $result] = $this->categorizer->inspectAndCategorize($passable->context);

        // Record the result for debug
        $passable->updateBestResult($result, $this->getName());

        // Lock to misc if this is a hashed/obfuscated/gibberish detection
        if ($result->isSuccessful() && $this->shouldLock($result)) {
            $passable->bestResult = $result;
            $passable->lockToMisc();
        }

        return $next($passable);
    }

    protected function categorize(ReleaseContext $context): CategorizationResult
    {
        return $this->categorizer->categorize($context);
    }

    /**
     * Determine if a result should trigger the misc lock.
     */
    public function shouldLock(CategorizationResult $result): bool
    {
        return self::$useRustFfi
            ? $this->shouldLockNative($result)
            : $this->shouldLockPhp($result);
    }

    public function shouldLockPhp(CategorizationResult $result): bool
    {
        // Always lock OTHER_HASHED results
        if ($result->categoryId === Category::OTHER_HASHED) {
            return true;
        }

        // Lock OTHER_MISC results that were matched by hash/obfuscated/gibberish checks
        foreach (self::LOCK_PREFIXES as $prefix) {
            if (str_starts_with($result->matchedBy, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function shouldLockNative(CategorizationResult $result): bool
    {
        $ffi = self::categorizeFfi();
        if ($ffi === null) {
            return $this->shouldLockPhp($result);
        }

        // @phpstan-ignore method.notFound
        $res = $ffi->nntmux_categorize_misc_pipe_should_lock(
            $result->categoryId,
            $result->matchedBy,
            \strlen($result->matchedBy)
        );
        if (! \is_int($res) || $res < 0) {
            return $this->shouldLockPhp($result);
        }

        return $res === 1;
    }

    public static function useW393RustFfi(?bool $enabled): void
    {
        self::$w393RustFfiOverride = $enabled;
    }

    public static function usesW393RustFfi(): bool
    {
        return self::$w393RustFfiOverride ?? self::W393_RUST_FFI_DEFAULT;
    }

    public static function resetW393RustFfi(): void
    {
        self::$w393RustFfiOverride = null;
        self::$w393RustFfiHandle = null;
    }

    /**
     * Pre-screening metrics for a batch of subjects. Each subject is trimmed (PHP's default set);
     * shorter than $minLength bytes is too short, else HASH_LIKE_MIN_LENGTH+ hex digits only is
     * hash-like; every other subject is a categorizer candidate, uppercase-heavy when it has at least
     * four ASCII letters and 80% or more of them are uppercase. Non-string subjects count as empty.
     *
     * @param  array<array-key, mixed>  $subjects
     * @return array{total: int, too_short: int, hash_like: int, uppercase_heavy: int, candidates: int}
     */
    public static function summarizeMiscPipeDecisionMetrics(array $subjects, int $minLength = 8): array
    {
        return (self::usesW393RustFfi() ? self::summarizeMiscPipeDecisionMetricsNative($subjects, $minLength) : null)
            ?? self::summarizeMiscPipeDecisionMetricsPhp($subjects, $minLength);
    }

    /**
     * @param  array<array-key, mixed>  $subjects
     * @return array{total: int, too_short: int, hash_like: int, uppercase_heavy: int, candidates: int}
     */
    public static function summarizeMiscPipeDecisionMetricsPhp(array $subjects, int $minLength = 8): array
    {
        $tooShort = 0;
        $hashLike = 0;
        $upperHeavy = 0;
        foreach ($subjects as $subject) {
            $trimmed = trim(\is_string($subject) ? $subject : '');
            $length = \strlen($trimmed);
            if ($length < $minLength) {
                $tooShort++;

                continue;
            }
            if ($length >= self::HASH_LIKE_MIN_LENGTH && strspn($trimmed, '0123456789abcdefABCDEF') === $length) {
                $hashLike++;

                continue;
            }
            $letters = \strlen((string) preg_replace('/[^A-Za-z]+/', '', $trimmed));
            if ($letters >= 4 && \strlen((string) preg_replace('/[^A-Z]+/', '', $trimmed)) * 10 >= $letters * 8) {
                $upperHeavy++;
            }
        }
        $total = \count($subjects);

        return [
            'total' => $total,
            'too_short' => $tooShort,
            'hash_like' => $hashLike,
            'uppercase_heavy' => $upperHeavy,
            'candidates' => $total - $tooShort - $hashLike,
        ];
    }

    /**
     * Declines (null) for non-string subjects so PHP counts them.
     *
     * @param  array<array-key, mixed>  $subjects
     * @return array{total: int, too_short: int, hash_like: int, uppercase_heavy: int, candidates: int}|null
     */
    public static function summarizeMiscPipeDecisionMetricsNative(array $subjects, int $minLength = 8): ?array
    {
        $lengths = [];
        foreach ($subjects as $subject) {
            if (! \is_string($subject)) {
                return null;
            }
            $lengths[] = \strlen($subject);
        }
        $blob = implode('', $subjects);
        $lens = pack('V*', ...$lengths);
        $fields = self::callW393Native('nntmux_w393_misc_pipe_decision_metrics', [$blob, \strlen($blob), $lens, \strlen($lens), $minLength], 96, 5, 5);
        if ($fields === null) {
            return null;
        }

        return [
            'total' => (int) $fields[0],
            'too_short' => (int) $fields[1],
            'hash_like' => (int) $fields[2],
            'uppercase_heavy' => (int) $fields[3],
            'candidates' => (int) $fields[4],
        ];
    }

    /**
     * Calls a Wave 39.3 export with the shared scratch buffer, retrying once at the size it asks for.
     * Returns its fields only when there are exactly $fieldCount and the first $digitFields are decimal.
     *
     * @param  list<int|string>  $args  arguments preceding the caller-owned output buffer
     * @return list<string>|null
     */
    private static function callW393Native(string $symbol, array $args, int $minBytes, int $fieldCount, int $digitFields): ?array
    {
        $ffi = self::w393RustFfi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $minBytes, $capacity);
            if ($buffer === null) {
                return null;
            }
            $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }
            $fields = explode("\x1F", FFI::string($buffer, $written));
            if (count($fields) !== $fieldCount) {
                return null;
            }
            for ($i = 0; $i < $digitFields; $i++) {
                if (strlen($fields[$i]) > 19 || ! ctype_digit($fields[$i])) {
                    return null;
                }
            }

            return $fields;
        } catch (Throwable) {
            return null;
        }
    }

    private static function w393RustFfi(): ?FFI
    {
        if (self::$w393RustFfiHandle === null) {
            self::$w393RustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w393', self::W393_FFI_CDEF) ?? false;
        }

        return self::$w393RustFfiHandle === false ? null : self::$w393RustFfiHandle;
    }

    /** Wave 54.3 paired benchmarks decide this default; see RustCategorizationPipesConsoleGroupMiscMusicPcW54ParityTest. */
    private const bool W54_RUST_FFI_DEFAULT = true;

    public const string W54_FFI_CDEF = <<<'C'
        ssize_t nntmux_w543_misc_subject_shape_batch(const char *blob, size_t blob_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Tokens at least this long are checked for hash and gibberish shapes. */
    private const int W54_LONG_TOKEN_LENGTH = 16;

    /** Uppercased archive tokens; `R\d\d` and `PART\d{1,4}` also count. */
    private const array W54_ARCHIVE_TOKENS = ['RAR' => true, 'ZIP' => true, '7Z' => true, 'PAR2' => true, 'PAR' => true, 'SFV' => true, 'NZB' => true];

    public static function usesW54RustFfi(): bool
    {
        $override = self::w54RustFfiState();

        return \is_bool($override) ? $override : self::W54_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the Wave 54 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW54RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w54RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w54RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 54.3 subject shapes. Per subject `flags | min(longest token, 0xFFFFFF) << 8 | tokens << 32`,
     * with flags 1 empty (after trim()), 2 hex hash token (W54_LONG_TOKEN_LENGTH+ hex digits, not all
     * decimal), 4 long mixed token (W54_LONG_TOKEN_LENGTH+ bytes with letters and digits, not hex), 8
     * archive token, 16 every token decimal (at least one) and 32 yEnc; then the six flag counts.
     * Tokens are maximal ASCII alphanumeric runs compared case-insensitively.
     *
     * @param  array<array-key, string>  $subjects
     * @return list<int>
     */
    public static function w54MiscSubjectShapeBatch(array $subjects): array
    {
        return self::usesW54RustFfi() ? self::w54MiscSubjectShapeBatchNative($subjects) : self::w54MiscSubjectShapeBatchPhp($subjects);
    }

    /**
     * @param  array<array-key, string>  $subjects
     * @return list<int>
     */
    public static function w54MiscSubjectShapeBatchNative(array $subjects): array
    {
        return self::w54Invoke($subjects, \count($subjects) + 6) ?? self::w54MiscSubjectShapeBatchPhp($subjects);
    }

    /**
     * @param  array<array-key, string>  $subjects
     * @return list<int>
     */
    public static function w54MiscSubjectShapeBatchPhp(array $subjects): array
    {
        $out = [];
        $totals = [0, 0, 0, 0, 0, 0];
        foreach ($subjects as $subject) {
            $subject = trim((string) $subject);
            if ($subject === '') {
                $word = 1;
            } else {
                $tokens = preg_split('/[^A-Za-z0-9]+/', strtoupper($subject), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $flags = 0;
                $longest = 0;
                $numeric = true;
                foreach ($tokens as $token) {
                    $length = \strlen($token);
                    if ($length > $longest) {
                        $longest = $length;
                    }
                    $decimal = ctype_digit($token);
                    $numeric = $numeric && $decimal;
                    if ($length >= self::W54_LONG_TOKEN_LENGTH && ! $decimal) {
                        $flags |= ctype_xdigit($token) ? 2 : (ctype_alpha($token) ? 0 : 4);
                    }
                    if (isset(self::W54_ARCHIVE_TOKENS[$token])
                        || (($token[0] === 'R' || $token[0] === 'P') && preg_match('/\A(?:R\d\d|PART\d{1,4})\z/', $token) === 1)) {
                        $flags |= 8;
                    }
                    if ($token === 'YENC') {
                        $flags |= 32;
                    }
                }
                if ($tokens !== [] && $numeric) {
                    $flags |= 16;
                }
                $word = $flags | (min($longest, 0xFFFFFF) << 8) | (\count($tokens) << 32);
            }
            $totals[0] += $word & 1;
            $totals[1] += ($word >> 1) & 1;
            $totals[2] += ($word >> 2) & 1;
            $totals[3] += ($word >> 3) & 1;
            $totals[4] += ($word >> 4) & 1;
            $totals[5] += ($word >> 5) & 1;
            $out[] = $word;
        }
        array_push($out, ...$totals);

        return $out;
    }

    /**
     * Wave 54 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'ffi' resolves the library (null when unavailable) and 'out'
     * returns an `int64_t` output buffer of at least $val words.
     */
    private static function w54RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('categorize_w54', self::W54_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w54RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }

        return $override;
    }

    /**
     * Calls the Wave 54.3 export with the NUL-joined $items. Null when the library is missing, the
     * call declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>|null
     */
    private static function w54Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w54RustFfiState('ffi');
            $out = self::w54RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $blob = implode("\0", $items);
            if (substr_count($blob, "\0") !== max(0, \count($items) - 1)) {
                // An item holds a NUL byte: substitute a space, which classifies identically (trim() strips both bytes and both split tokens).
                $blob = implode("\0", str_replace("\0", ' ', $items));
            }
            // @phpstan-ignore method.notFound (Declared by W54_FFI_CDEF.)
            $written = $ffi->nntmux_w543_misc_subject_shape_batch($blob, \strlen($blob), \count($items), $out, $words);
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
