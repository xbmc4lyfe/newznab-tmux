<?php

declare(strict_types=1);

namespace App\Services\Categorization\Categorizers;

use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\Contracts\CategorizerInterface;
use App\Services\Categorization\ReleaseContext;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

abstract class AbstractCategorizer implements CategorizerInterface
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_abstract_categorizer_priority_and_skip(int64_t priority, const char *release_name, size_t release_name_len, int64_t *out_priority);
        ssize_t nntmux_abstract_categorizer_format_result_meta(uint32_t is_no_match, int64_t category_id, double confidence, const char *matched_by_ptr, size_t matched_by_len, int64_t *out_category_id, double *out_confidence, char *out_buf, size_t out_cap);
        C;

    /**
     * Wave 46.3 production default is Rust: the paired benchmark in
     * tests/Unit/RustAbstractCategorizerPipeSafetyNetDisplayNamesW46ParityTest.php measures
     * 1.97-2.07x mean / 1.81-1.97x p99 on the common 32-name batch and 3.84-4.12x mean /
     * 3.06-3.58x p99 on the uncommon 2000-name batch. PHP stays the fallback when the library is
     * unavailable or a name is not a string.
     * Named W463 so it stays distinct from the class's existing useRustFfi paths.
     */
    public const bool W463_RUST_FFI_DEFAULT = true;

    public const string W463_FFI_CDEF = <<<'C'
        ssize_t nntmux_w463_categorizer_release_name_shape_batch(
            const char *blob_ptr,
            size_t blob_len,
            const char *lens_ptr,
            size_t lens_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /** Release group suffix: a hyphen and 2-15 trailing ASCII alphanumerics. */
    public const string W463_GROUP_SUFFIX_PATTERN = '/-[A-Za-z0-9]{2,15}\z/';

    /** A 19xx/20xx year not embedded in a longer digit run. */
    public const string W463_YEAR_PATTERN = '/(?<![0-9])(?:19|20)[0-9]{2}(?![0-9])/';

    /** Exactly 8 0x1F-separated decimals of 1-19 digits. */
    private const string W463_FIELDS_PATTERN = '/\A(?:\d{1,19}\x1F){7}\d{1,19}\z/';

    private static ?bool $w463RustFfiOverride = null;

    private static FFI|false|null $w463RustFfiHandle = null;

    public static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    protected int $priority = 50;

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function mediaFfi(): ?FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi === false ? null : self::$ffi;
        }

        self::$ffi = RustPipelineLibrary::resolveDomain('media', self::FFI_CDEF) ?? false;

        return self::$ffi === false ? null : self::$ffi;
    }

    public function getPriority(): int
    {
        return self::$useRustFfi
            ? $this->getPriorityNative()
            : $this->getPriorityPhp();
    }

    public function getPriorityPhp(): int
    {
        return $this->priority;
    }

    public function getPriorityNative(): int
    {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return $this->getPriorityPhp();
        }

        $outPriority = $ffi->new('int64_t[1]');
        // @phpstan-ignore method.notFound
        $res = $ffi->nntmux_abstract_categorizer_priority_and_skip(
            $this->priority,
            '',
            0,
            $outPriority,
        );
        if (! \is_int($res) || $res < 0) {
            return $this->getPriorityPhp();
        }

        /** @var int $resolved */
        $resolved = $outPriority[0];

        return $resolved;
    }

    public function shouldSkip(ReleaseContext $context): bool
    {
        return self::$useRustFfi
            ? $this->shouldSkipNative($context)
            : $this->shouldSkipPhp($context);
    }

    public function shouldSkipPhp(ReleaseContext $context): bool
    {
        return false;
    }

    public function shouldSkipNative(ReleaseContext $context): bool
    {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return $this->shouldSkipPhp($context);
        }

        $outPriority = $ffi->new('int64_t[1]');
        // @phpstan-ignore method.notFound
        $res = $ffi->nntmux_abstract_categorizer_priority_and_skip(
            $this->priority,
            $context->releaseName,
            \strlen($context->releaseName),
            $outPriority,
        );
        if (! \is_int($res) || $res < 0) {
            return $this->shouldSkipPhp($context);
        }

        return $res === 1;
    }

    /**
     * @param  array<string, mixed>  $debug
     */
    protected function matched(int $categoryId, float $confidence, string $matchedBy, array $debug = []): CategorizationResult
    {
        return self::$useRustFfi
            ? $this->matchedNative($categoryId, $confidence, $matchedBy, $debug)
            : $this->matchedPhp($categoryId, $confidence, $matchedBy, $debug);
    }

    /**
     * @param  array<string, mixed>  $debug
     */
    public function matchedPhp(int $categoryId, float $confidence, string $matchedBy, array $debug = []): CategorizationResult
    {
        return new CategorizationResult($categoryId, $confidence, $matchedBy, $debug);
    }

    /**
     * @param  array<string, mixed>  $debug
     */
    public function matchedNative(int $categoryId, float $confidence, string $matchedBy, array $debug = []): CategorizationResult
    {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return $this->matchedPhp($categoryId, $confidence, $matchedBy, $debug);
        }

        $len = \strlen($matchedBy);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(64, $len + 16), $cap);
        if ($scratch === null) {
            return $this->matchedPhp($categoryId, $confidence, $matchedBy, $debug);
        }
        $outCat = $ffi->new('int64_t[1]');
        $outConf = $ffi->new('double[1]');

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_abstract_categorizer_format_result_meta(
            0,
            $categoryId,
            $confidence,
            $matchedBy,
            $len,
            $outCat,
            $outConf,
            $scratch,
            $cap,
        );
        if (\is_int($written) && $written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return $this->matchedPhp($categoryId, $confidence, $matchedBy, $debug);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_abstract_categorizer_format_result_meta(
                0,
                $categoryId,
                $confidence,
                $matchedBy,
                $len,
                $outCat,
                $outConf,
                $scratch,
                $cap,
            );
        }
        if (! \is_int($written) || $written < 0) {
            return $this->matchedPhp($categoryId, $confidence, $matchedBy, $debug);
        }

        /** @var int $resolvedCat */
        $resolvedCat = $outCat[0];
        /** @var float $resolvedConf */
        $resolvedConf = $outConf[0];
        $resolvedMatchedBy = $written === 0 ? '' : FFI::string($scratch, $written);

        return new CategorizationResult($resolvedCat, $resolvedConf, $resolvedMatchedBy, $debug);
    }

    protected function noMatch(): CategorizationResult
    {
        return self::$useRustFfi
            ? $this->noMatchNative()
            : $this->noMatchPhp();
    }

    public function noMatchPhp(): CategorizationResult
    {
        return CategorizationResult::noMatch();
    }

    public function noMatchNative(): CategorizationResult
    {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return $this->noMatchPhp();
        }

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 32, $cap);
        if ($scratch === null) {
            return $this->noMatchPhp();
        }
        $outCat = $ffi->new('int64_t[1]');
        $outConf = $ffi->new('double[1]');

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_abstract_categorizer_format_result_meta(
            1,
            0,
            0.0,
            '',
            0,
            $outCat,
            $outConf,
            $scratch,
            $cap,
        );
        if (! \is_int($written) || $written <= 0) {
            return $this->noMatchPhp();
        }

        /** @var int $resolvedCat */
        $resolvedCat = $outCat[0];
        /** @var float $resolvedConf */
        $resolvedConf = $outConf[0];

        return new CategorizationResult($resolvedCat, $resolvedConf, FFI::string($scratch, $written));
    }

    /**
     * Enables (true) or disables (false) the Wave 46.3 Rust helper; null restores
     * W463_RUST_FFI_DEFAULT.
     */
    public static function useW463RustFfi(?bool $enable = true): void
    {
        self::$w463RustFfiOverride = $enable;
    }

    public static function usesW463RustFfi(): bool
    {
        return self::$w463RustFfiOverride ?? self::W463_RUST_FFI_DEFAULT;
    }

    public static function resetW463RustFfi(): void
    {
        self::$w463RustFfiOverride = null;
        self::$w463RustFfiHandle = null;
    }

    /**
     * Release name shape summary for categorizer inputs: empty names (only trim() characters), names
     * holding a dot, space, underscore or opening bracket/parenthesis, names ending in a
     * W463_GROUP_SUFFIX_PATTERN release group and names holding a W463_YEAR_PATTERN year. Matching
     * is bytewise; non-strings count as empty names.
     *
     * @param  array<array-key, mixed>  $names
     * @return array{total: int, empty: int, dotted: int, spaced: int, underscored: int, bracketed: int, group_suffix: int, year: int}
     */
    public static function summarizeReleaseNameShapeBatch(array $names): array
    {
        return (self::usesW463RustFfi() ? self::summarizeReleaseNameShapeBatchNative($names) : null)
            ?? self::summarizeReleaseNameShapeBatchPhp($names);
    }

    /**
     * @param  array<array-key, mixed>  $names
     * @return array{total: int, empty: int, dotted: int, spaced: int, underscored: int, bracketed: int, group_suffix: int, year: int}
     */
    public static function summarizeReleaseNameShapeBatchPhp(array $names): array
    {
        $empty = $dotted = $spaced = $underscored = $bracketed = $groupSuffix = $year = 0;
        foreach ($names as $name) {
            $name = \is_string($name) ? $name : '';
            $empty += (int) (trim($name) === '');
            $dotted += (int) str_contains($name, '.');
            $spaced += (int) str_contains($name, ' ');
            $underscored += (int) str_contains($name, '_');
            $bracketed += (int) (strpbrk($name, '[(') !== false);
            $groupSuffix += (int) (preg_match(self::W463_GROUP_SUFFIX_PATTERN, $name) === 1);
            $year += (int) (preg_match(self::W463_YEAR_PATTERN, $name) === 1);
        }

        return [
            'total' => \count($names),
            'empty' => $empty,
            'dotted' => $dotted,
            'spaced' => $spaced,
            'underscored' => $underscored,
            'bracketed' => $bracketed,
            'group_suffix' => $groupSuffix,
            'year' => $year,
        ];
    }

    /**
     * Declines (null) for non-string names and when the library is unavailable, so PHP counts them.
     *
     * @param  array<array-key, mixed>  $names
     * @return array{total: int, empty: int, dotted: int, spaced: int, underscored: int, bracketed: int, group_suffix: int, year: int}|null
     */
    public static function summarizeReleaseNameShapeBatchNative(array $names): ?array
    {
        $lengths = [];
        foreach ($names as $name) {
            if (! \is_string($name)) {
                return null;
            }
            $lengths[] = \strlen($name);
        }
        $fields = self::callW463Native(implode('', $names), pack('V*', ...$lengths));
        if ($fields === null) {
            return null;
        }

        return [
            'total' => (int) $fields[0],
            'empty' => (int) $fields[1],
            'dotted' => (int) $fields[2],
            'spaced' => (int) $fields[3],
            'underscored' => (int) $fields[4],
            'bracketed' => (int) $fields[5],
            'group_suffix' => (int) $fields[6],
            'year' => (int) $fields[7],
        ];
    }

    /**
     * Calls the Wave 46.3 export with the shared scratch buffer, retrying once at the size it asks
     * for. Returns its fields only when they match W463_FIELDS_PATTERN.
     *
     * @return list<string>|null
     */
    private static function callW463Native(string $blob, string $lens): ?array
    {
        $ffi = self::w463RustFfi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 192, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w463_categorizer_release_name_shape_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w463_categorizer_release_name_shape_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }
            $raw = FFI::string($buffer, $written);

            return preg_match(self::W463_FIELDS_PATTERN, $raw) === 1 ? explode("\x1F", $raw) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function w463RustFfi(): ?FFI
    {
        if (self::$w463RustFfiHandle === null) {
            self::$w463RustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w463', self::W463_FFI_CDEF) ?? false;
        }

        return self::$w463RustFfiHandle === false ? null : self::$w463RustFfiHandle;
    }

    // ========================================================================
    // WAVE 60.3 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 5.48x, p99 4.96x; uncommon mean 4.37x, p99 3.88x); see RustCategorizersAbstractBookConsoleGroupNameReleaseContextW60ParityTest. */
    public const bool W60_RUST_FFI_DEFAULT = true;

    public const string W60_ABSTRACT_CATEGORIZER_FFI_CDEF = <<<'C'
        ssize_t nntmux_w603_abstract_categorizer_gate_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of w60CategorizerGateBatch(). */
    public const int W60_ABSTRACT_CATEGORIZER_TRAILER = 34;

    /** The AbstractCategorizer Wave 60 toggle; subclasses with their own Wave 60 helper keep separate toggles. */
    public static function usesAbstractCategorizerW60RustFfi(): bool
    {
        $override = self::w60RustFfiState();

        return \is_bool($override) ? $override : self::W60_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the AbstractCategorizer Wave 60 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withAbstractCategorizerW60RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w60RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w60RustFfiState('set', $previous);
        }
    }

    /**
     * Categorizers without their own Wave 60 helper report the AbstractCategorizer toggle; those with one
     * override this (and withW60RustFfi()) with the same signature.
     */
    public static function usesW60RustFfi(): bool
    {
        return self::usesAbstractCategorizerW60RustFfi();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW60RustFfi(bool $enabled, callable $callback): mixed
    {
        return self::withAbstractCategorizerW60RustFfi($enabled, $callback);
    }

    /**
     * Wave 60.3 categorizer gate and dispatch plans. Each item is `releaseName|groupName|priority|confidence|categoryId`
     * (missing fields are empty, every field is trimmed). Per item `priorityState | band << 2 | priority << 4 |
     * confidenceState << 11 | tier << 13 | confidenceMilli << 15 | categoryState << 35 | root << 37 | dotted << 41 |
     * spaced << 42 | underscored << 43 | bracketed << 44 | groupSuffix << 45 | year << 46 | reason << 47 | decision
     * << 50 | min(nameLength, 255) << 52`: priority state is 0 empty, 1 up to three digits worth at most 100 and 2
     * invalid (both others use the default 50); the band is 0 up to 25, 1 up to 50, 2 up to 75, else 3; confidence
     * is w60Confidence() with tier 1 from 0.5, 2 from 0.7 and 3 from 0.85 (valid only, else 0); category is
     * w60Category() with root id / 1000 for valid ids; the shape flags follow summarizeReleaseNameShapeBatchPhp()
     * on the trimmed name; reason is 0 categorizable, 1 empty name, 2 name under three bytes, 3 malformed confidence
     * or 4 malformed category; decision is 0 skip, 1 no match and 2 matched (categorizable with a tier and a valid
     * category). Then three decision, five reason, four band, four tier, nine root (0 without a valid category) and
     * six shape counts, the largest effective priority, the matched confidence sum in thousandths and the distinct
     * (case-insensitive) group names of categorizable items.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w60CategorizerGateBatch(array $items): array
    {
        return self::usesAbstractCategorizerW60RustFfi() ? self::w60CategorizerGateBatchNative($items) : self::w60CategorizerGateBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w60CategorizerGateBatchNative(array $items): array
    {
        return self::w60Invoke($items, \count($items) + self::W60_ABSTRACT_CATEGORIZER_TRAILER) ?? self::w60CategorizerGateBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w60CategorizerGateBatchPhp(array $items): array
    {
        $out = [];
        $decisions = [0, 0, 0];
        $reasons = [0, 0, 0, 0, 0];
        $bands = [0, 0, 0, 0];
        $tiers = [0, 0, 0, 0];
        $roots = array_fill(0, 9, 0);
        $shapes = [0, 0, 0, 0, 0, 0];
        $maxPriority = $milliSum = 0;
        $groups = [];
        foreach ($items as $item) {
            $f = explode('|', (string) $item, 5) + ['', '', '', '', ''];
            $name = trim($f[0]);
            $group = trim($f[1]);
            $priorityRaw = trim($f[2]);
            $length = \strlen($priorityRaw);
            if ($priorityRaw === '') {
                [$priorityState, $priority] = [0, 50];
            } elseif ($length <= 3 && strspn($priorityRaw, '0123456789') === $length && (int) $priorityRaw <= 100) {
                [$priorityState, $priority] = [1, (int) $priorityRaw];
            } else {
                [$priorityState, $priority] = [2, 50];
            }
            $band = $priority <= 25 ? 0 : ($priority <= 50 ? 1 : ($priority <= 75 ? 2 : 3));
            [$confidenceState, $milli] = self::w60Confidence(trim($f[3]));
            $tier = $confidenceState !== 1 || $milli < 500 ? 0 : ($milli < 700 ? 1 : ($milli < 850 ? 2 : 3));
            [$categoryState, $categoryId] = self::w60Category(trim($f[4]));
            $root = $categoryState === 1 ? intdiv($categoryId, 1000) : 0;
            $shape = [str_contains($name, '.'), str_contains($name, ' '), str_contains($name, '_'), strpbrk($name, '[(') !== false,
                preg_match(self::W463_GROUP_SUFFIX_PATTERN, $name) === 1, preg_match(self::W463_YEAR_PATTERN, $name) === 1];
            $nameLength = \strlen($name);
            $reason = $name === '' ? 1 : ($nameLength < 3 ? 2 : ($confidenceState === 3 ? 3 : ($categoryState === 3 ? 4 : 0)));
            $decision = $reason !== 0 ? 0 : ($tier >= 1 && $categoryState === 1 ? 2 : 1);
            $decisions[$decision]++;
            $reasons[$reason]++;
            $bands[$band]++;
            $tiers[$tier]++;
            $roots[$root]++;
            $word = $priorityState | $band << 2 | $priority << 4 | $confidenceState << 11 | $tier << 13 | $milli << 15 | $categoryState << 35
                | $root << 37 | $reason << 47 | $decision << 50 | min($nameLength, 255) << 52;
            foreach ($shape as $i => $on) {
                if ($on) {
                    $shapes[$i]++;
                    $word |= 1 << (41 + $i);
                }
            }
            $maxPriority = max($maxPriority, $priority);
            if ($decision === 2) {
                $milliSum += $milli;
            }
            if ($reason === 0 && $group !== '') {
                $groups[strtolower($group)] = true;
            }
            $out[] = $word;
        }
        array_push($out, ...$decisions, ...$reasons, ...$bands, ...$tiers, ...$roots, ...$shapes);
        array_push($out, $maxPriority, $milliSum, \count($groups));

        return $out;
    }

    /**
     * Confidence state 0 empty, 1 a 1-3 digit integer part with an optional `.` and 1-6 fraction digits worth at
     * most 1.000, 2 well-formed but larger and 3 malformed; then the value in thousandths (fraction truncated to
     * three digits, 0 when malformed).
     *
     * @return array{int, int}
     */
    private static function w60Confidence(string $raw): array
    {
        if ($raw === '') {
            return [0, 0];
        }
        $dot = strpos($raw, '.');
        $int = $dot === false ? $raw : substr($raw, 0, $dot);
        $frac = $dot === false ? '' : substr($raw, $dot + 1);
        $intLength = \strlen($int);
        $fracLength = \strlen($frac);
        if ($intLength < 1 || $intLength > 3 || strspn($int, '0123456789') !== $intLength
            || ($dot !== false && ($fracLength < 1 || $fracLength > 6 || strspn($frac, '0123456789') !== $fracLength))) {
            return [3, 0];
        }
        $milli = (int) $int * 1000 + (int) str_pad(substr($frac, 0, 3), 3, '0');

        return [$milli <= 1000 ? 1 : 2, $milli];
    }

    /**
     * Category state 0 empty, 1 an id in 1000-8999, 2 another number of up to nine digits and 3 malformed; then the
     * id (0 unless numeric).
     *
     * @return array{int, int}
     */
    private static function w60Category(string $raw): array
    {
        if ($raw === '') {
            return [0, 0];
        }
        $length = \strlen($raw);
        if ($length > 9 || strspn($raw, '0123456789') !== $length) {
            return [3, 0];
        }
        $id = (int) $raw;

        return [$id >= 1000 && $id <= 8999 ? 1 : 2, $id];
    }

    /**
     * Wave 60 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'ffi' resolves the library (null when unavailable) and 'out' and
     * 'lens' return `int64_t` output and length-column buffers of at least $val words.
     */
    private static function w60RustFfiState(?string $op = null, mixed $val = null): mixed
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
            $ffi ??= RustPipelineLibrary::resolveDomain('categorize_w603', self::W60_ABSTRACT_CATEGORIZER_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w60RustFfiState('ffi');
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
                $handle = self::w60RustFfiState('ffi');
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
     * Calls the Wave 60.3 AbstractCategorizer export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w60Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w60RustFfiState('ffi');
            $out = self::w60RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w60RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W60_ABSTRACT_CATEGORIZER_FFI_CDEF.)
            $written = $ffi->nntmux_w603_abstract_categorizer_gate_batch($blob, \strlen($blob), $lens, $count, $out, $words);
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
