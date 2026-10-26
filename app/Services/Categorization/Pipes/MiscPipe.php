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

    /** C signature of the Wave 66.5 {@see self::w66MiscPipePlanBatch()} helper. */
    private const string W66_MISC_PIPE_PLAN_FFI_CDEF = 'int64_t nntmux_w665_misc_pipe_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /**
     * The Wave 66.5 plan batch defaults to Rust: every common and uncommon mean and p99 ratio cleared 1.5x
     * (min 5.63/5.82/4.06/4.25x); see tests/Unit/RustCategorizationBookPassableConsoleGroupNameMiscPipeW66ParityTest.php.
     */
    public const bool W66_RUST_FFI_DEFAULT = true;

    /**
     * Wave 66.5 routing and FFI cache, kept in function-local statics (no properties). Without $op it
     * reports the routing; 'set' swaps the override for $val and returns the previous one; 'reset' replaces the
     * cached handle with $val (null re-resolves, false disables); 'buffer' returns `[FFI, int64_t[], capacity]`
     * with room for at least $val words, or null without the library.
     */
    private static function w66RustFfiState(?string $op = null, mixed $val = null): mixed
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
                    $ffi = RustPipelineLibrary::resolveDomain('w66_5', self::W66_MISC_PIPE_PLAN_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
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
                return $enabled ?? self::W66_RUST_FFI_DEFAULT;
        }
    }

    /** Route the Wave 66.5 plan batch to Rust until disabled or reset. */
    public static function enableW66RustFfi(): void
    {
        self::w66RustFfiState('set', true);
    }

    /** Route the Wave 66.5 plan batch to PHP until enabled or reset. */
    public static function disableW66RustFfi(): void
    {
        self::w66RustFfiState('set', false);
    }

    /** Restore W66_RUST_FFI_DEFAULT routing and drop the cached Wave 66.5 handle and buffer. */
    public static function resetW66RustFfi(): void
    {
        self::w66RustFfiState('set', null);
        self::w66RustFfiState('reset', null);
    }

    public static function usesW66RustFfi(): bool
    {
        return self::w66RustFfiState() === true;
    }

    /**
     * Runs $callback with w66MiscPipePlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW66RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w66RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w66RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 66.5 Rust helper over $count NUL-joined records, or null
     * to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w66Words(int $count, string $data, int $words): ?array
    {
        try {
            $handle = self::w66RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w665_misc_pipe_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('q*', FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Trimmed 1-15 ASCII digits as an integer; empty yields $default, anything else sets bit 1 and yields $default. */
    private static function w66Number(string $field, int $default, int &$flags): int
    {
        $text = trim($field);
        if ($text === '') {
            return $default;
        }
        if (\strlen($text) <= 15 && strspn($text, '0123456789') === \strlen($text)) {
            return (int) $text;
        }
        $flags |= 1;

        return $default;
    }

    /**
     * Batch Misc pipe plan over `subject\tcategoryId\tconfidence\tmatchedBy\tcurrentCategory`, confidences in
     * permille. Eight words per record: `[length, tokens, shape, longest, mask, decision, target, errors]` for the trimmed
     * subject. Shape: 16 empty, 1 hash-like (at least 16 hex digits and nothing else), plus per maximal ASCII
     * alphanumeric token 2 archive (W54_ARCHIVE_TOKENS, case-insensitive), 4 long (16+ bytes) and not all hex, 8 a
     * vowelless word of 6+ letters (no AEIOUY). Longest is the longest token's length. Mask: 1 successful, 2 locks like
     * shouldLockPhp(), 4 terminal (>= 950). Decision/target: 1 locks a successful locking result to its category, 2
     * moves a hash-like subject with an empty or misc current category to OTHER_HASHED, 3 takes a successful misc or
     * hashed result, 0 keeps the current category (default OTHER_MISC). Numbers are trimmed 1-15 ASCII digits; errors:
     * 1 invalid number, 2 not exactly five fields.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w66MiscPipePlanBatch(array $records): array
    {
        return self::usesW66RustFfi() ? self::w66MiscPipePlanBatchNative($records) : self::w66MiscPipePlanBatchPhp($records);
    }

    /**
     * Rust batch over the NUL-joined records; falls back to PHP without the library or when a record holds
     * a NUL.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w66MiscPipePlanBatchNative(array $records): array
    {
        $count = \count($records);
        if ($count === 0) {
            return [];
        }

        return self::w66Words($count, implode("\0", $records), 8 * $count) ?? self::w66MiscPipePlanBatchPhp($records);
    }

    /**
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w66MiscPipePlanBatchPhp(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $fields = explode("\t", $record);
            $errors = \count($fields) === 5 ? 0 : 2;
            $subject = trim($fields[0]);
            $length = \strlen($subject);
            $shape = $length === 0 ? 16 : ($length >= 16 && strspn($subject, '0123456789abcdefABCDEF') === $length ? 1 : 0);
            $tokens = $longest = 0;
            foreach (preg_split('/[^A-Z0-9]+/', strtoupper($subject), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                $tokens++;
                $size = \strlen($token);
                $longest = max($longest, $size);
                if ($size >= 16 && strspn($token, '0123456789ABCDEF') !== $size) {
                    $shape |= 4;
                }
                if ($size >= 6 && strspn($token, 'BCDFGHJKLMNPQRSTVWXZ') === $size) {
                    $shape |= 8;
                }
                if (isset(self::W54_ARCHIVE_TOKENS[$token])) {
                    $shape |= 2;
                }
            }
            $category = self::w66Number($fields[1] ?? '', 0, $errors);
            $confidence = self::w66Number($fields[2] ?? '', 0, $errors);
            $label = $fields[3] ?? '';
            $current = self::w66Number($fields[4] ?? '', Category::OTHER_MISC, $errors);
            $success = $confidence > 0 && ! ($category === Category::OTHER_MISC && $label === 'no_match');
            $lock = $category === Category::OTHER_HASHED;
            foreach (self::LOCK_PREFIXES as $prefix) {
                $lock = $lock || str_starts_with($label, $prefix);
            }
            [$decision, $target] = match (true) {
                $success && $lock => [1, $category],
                ($shape & 1) !== 0 && ($current === 0 || $current === Category::OTHER_MISC) => [2, Category::OTHER_HASHED],
                $success && ($category === Category::OTHER_MISC || $category === Category::OTHER_HASHED) => [3, $category],
                default => [0, $current],
            };
            array_push($out, $length, $tokens, $shape, $longest, ($success ? 1 : 0) | ($lock ? 2 : 0) | ($confidence >= 950 ? 4 : 0), $decision, $target, $errors);
        }

        return $out;
    }
}
