<?php

declare(strict_types=1);

namespace App\Services\Categorization\Pipes;

use App\Models\Category;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\Categorizers\XxxCategorizer;
use App\Services\Categorization\ReleaseContext;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Pipe for XXX/Adult content categorization.
 */
class XxxPipe extends AbstractCategorizationPipe
{
    /**
     * Wave 43.3 production default is Rust: the paired benchmark in
     * tests/Unit/RustCategorizersMusicPcTvXxxPipeW43ParityTest.php measures at least 3.1x
     * (mean and p99, common and uncommon batches). PHP stays the fallback when the library is
     * unavailable.
     * Named W433 so it stays distinct from other Rust FFI toggles.
     */
    public const bool W433_RUST_FFI_DEFAULT = true;

    public const string W433_FFI_CDEF = <<<'C'
        ssize_t nntmux_w433_xxx_pipe_adult_signal_batch(
            const char *blob_ptr,
            size_t blob_len,
            const char *lens_ptr,
            size_t lens_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Adult signal marker substrings, in match order. The first class with a substring of the
     * lowercased release name wins.
     *
     * @var array<string, list<string>>
     */
    public const array W433_ADULT_SIGNAL_MARKERS = [
        'studio' => ['brazzers', 'realitykings', 'naughtyamerica', 'bangbros', 'vixen', 'blacked', 'tushy', 'evilangel', 'teamskeet', 'mofos'],
        'jav' => ['1pondo', 'caribbeancom', 'heyzo', 'tokyohot', '10musume', 'fc2-ppv'],
        'keyword' => ['porn', 'milf', 'hardcore', 'lesbian', 'creampie', 'blowjob', 'threesome'],
    ];

    /** @var list<string> */
    public const array W433_XXX_TAG_MARKERS = ['xxx'];

    /** Exactly 6 0x1F-separated decimals of 1-19 digits. */
    private const string W433_FIELDS_PATTERN = '/\A(?:\d{1,19}\x1F){5}\d{1,19}\z/';

    private static ?bool $w433RustFfiOverride = null;

    private static FFI|false|null $w433RustFfiHandle = null;

    public const string PIPE_FFI_CDEF = <<<'C'
        ssize_t nntmux_cat_pipe_cmd_pipe_name(uint8_t kind, char *out_buf, size_t out_cap);
        int64_t nntmux_cat_pipe_cmd_pipe_priority(uint8_t kind);
        int32_t nntmux_cat_pipe_cmd_evaluate_result(uint8_t kind, int64_t category_id, double confidence, const char *matched_by_ptr, size_t matched_by_len);
        C;

    /** The result passes `CategorizationResult::isSuccessful()`. */
    public const int RESULT_SUCCESSFUL = 1;

    /** The category id lies in the XXX category family. */
    public const int RESULT_IN_DOMAIN = 2;

    /** The confidence reaches the `CategorizationPassable` stop threshold. */
    public const int RESULT_TERMINAL = 4;

    /** The category is `XXX > OnlyFans`. */
    public const int RESULT_ONLYFANS = 8;

    private const int PIPE_KIND = 1;

    private const string PIPE_NAME = 'XXX';

    private const int PIPE_PRIORITY = 10;

    // Public because the parent declares it public; a private redeclaration is a fatal error.
    public static bool $useRustFfi = false;

    private static FFI|false|null $pipeFfi = null;

    protected int $priority = self::PIPE_PRIORITY;

    private XxxCategorizer $categorizer;

    public function __construct()
    {
        $this->categorizer = new XxxCategorizer;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function setPipeFfiForTesting(FFI|false|null $ffi): void
    {
        self::$pipeFfi = $ffi;
    }

    public function getName(): string
    {
        return self::pipeName();
    }

    public static function pipeName(): string
    {
        return self::$useRustFfi ? self::pipeNameNative() : self::pipeNamePhp();
    }

    public static function pipeNamePhp(): string
    {
        return self::PIPE_NAME;
    }

    public static function pipeNameNative(): string
    {
        $ffi = self::pipeFfi();
        if ($ffi === null) {
            return self::pipeNamePhp();
        }

        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, 16, $cap);
            if ($scratch === null) {
                return self::pipeNamePhp();
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_cat_pipe_cmd_pipe_name(self::PIPE_KIND, $scratch, $cap);
            if (! \is_int($written) || $written <= 0 || $written > $cap) {
                return self::pipeNamePhp();
            }

            return FFI::string($scratch, $written);
        } catch (Throwable) {
            return self::pipeNamePhp();
        }
    }

    public static function pipePriority(): int
    {
        return self::$useRustFfi ? self::pipePriorityNative() : self::pipePriorityPhp();
    }

    public static function pipePriorityPhp(): int
    {
        return self::PIPE_PRIORITY;
    }

    public static function pipePriorityNative(): int
    {
        $ffi = self::pipeFfi();
        if ($ffi === null) {
            return self::pipePriorityPhp();
        }

        try {
            // @phpstan-ignore method.notFound
            $priority = $ffi->nntmux_cat_pipe_cmd_pipe_priority(self::PIPE_KIND);
        } catch (Throwable) {
            return self::pipePriorityPhp();
        }

        return \is_int($priority) && $priority >= 0 ? $priority : self::pipePriorityPhp();
    }

    /**
     * Evaluate a categorization result against this pipe as a `RESULT_*` bit mask.
     */
    public static function evaluateResult(int $categoryId, float $confidence, string $matchedBy): int
    {
        return self::$useRustFfi
            ? self::evaluateResultNative($categoryId, $confidence, $matchedBy)
            : self::evaluateResultPhp($categoryId, $confidence, $matchedBy);
    }

    public static function evaluateResultPhp(int $categoryId, float $confidence, string $matchedBy): int
    {
        $mask = 0;
        if (! ($confidence <= 0) && ! ($categoryId === Category::OTHER_MISC && $matchedBy === 'no_match')) {
            $mask |= self::RESULT_SUCCESSFUL;
        }
        if ($categoryId >= Category::XXX_ROOT && $categoryId <= Category::XXX_ROOT + 999) {
            $mask |= self::RESULT_IN_DOMAIN;
        }
        if ($confidence >= 0.95) {
            $mask |= self::RESULT_TERMINAL;
        }
        if ($categoryId === Category::XXX_ONLYFANS) {
            $mask |= self::RESULT_ONLYFANS;
        }

        return $mask;
    }

    public static function evaluateResultNative(int $categoryId, float $confidence, string $matchedBy): int
    {
        $ffi = self::pipeFfi();
        if ($ffi === null) {
            return self::evaluateResultPhp($categoryId, $confidence, $matchedBy);
        }

        try {
            // @phpstan-ignore method.notFound
            $mask = $ffi->nntmux_cat_pipe_cmd_evaluate_result(self::PIPE_KIND, $categoryId, $confidence, $matchedBy, \strlen($matchedBy));
        } catch (Throwable) {
            return self::evaluateResultPhp($categoryId, $confidence, $matchedBy);
        }

        return \is_int($mask) && $mask >= 0 ? $mask : self::evaluateResultPhp($categoryId, $confidence, $matchedBy);
    }

    protected function categorize(ReleaseContext $context): CategorizationResult
    {
        return $this->categorizer->categorize($context);
    }

    private static function pipeFfi(): ?FFI
    {
        if (self::$pipeFfi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            try {
                self::$pipeFfi = RustPipelineLibrary::resolveDomain(
                    'w29-categorization-pipes-commands',
                    self::PIPE_FFI_CDEF,
                    \is_string($path) && $path !== '' ? $path : null
                ) ?? false;
            } catch (Throwable) {
                self::$pipeFfi = false;
            }
        }

        return self::$pipeFfi === false ? null : self::$pipeFfi;
    }

    /**
     * Enables (true) or disables (false) the Wave 43.3 Rust helper; null restores
     * W433_RUST_FFI_DEFAULT.
     */
    public static function useW433RustFfi(?bool $enable = true): void
    {
        self::$w433RustFfiOverride = $enable;
    }

    public static function usesW433RustFfi(): bool
    {
        return self::$w433RustFfiOverride ?? self::W433_RUST_FFI_DEFAULT;
    }

    public static function resetW433RustFfi(): void
    {
        self::$w433RustFfiOverride = null;
        self::$w433RustFfiHandle = null;
    }

    /**
     * Adult signal summary for a batch of release names. Each lowercased name adds to its first
     * W433_ADULT_SIGNAL_MARKERS class, else to unknown, and separately to xxx when it holds any
     * W433_XXX_TAG_MARKERS entry. Non-strings count as empty names.
     *
     * @param  array<array-key, mixed>  $releaseNames
     * @return array{total: int, studio: int, jav: int, keyword: int, unknown: int, xxx: int}
     */
    public static function summarizeAdultSignalBatch(array $releaseNames): array
    {
        return (self::usesW433RustFfi() ? self::summarizeAdultSignalBatchNative($releaseNames) : null)
            ?? self::summarizeAdultSignalBatchPhp($releaseNames);
    }

    /**
     * @param  array<array-key, mixed>  $releaseNames
     * @return array{total: int, studio: int, jav: int, keyword: int, unknown: int, xxx: int}
     */
    public static function summarizeAdultSignalBatchPhp(array $releaseNames): array
    {
        $studio = 0;
        $jav = 0;
        $keyword = 0;
        $unknown = 0;
        $xxxCount = 0;
        foreach ($releaseNames as $releaseName) {
            $name = strtolower(\is_string($releaseName) ? $releaseName : '');
            foreach (self::W433_XXX_TAG_MARKERS as $needle) {
                if (str_contains($name, $needle)) {
                    $xxxCount++;

                    break;
                }
            }
            foreach (self::W433_ADULT_SIGNAL_MARKERS['studio'] as $needle) {
                if (str_contains($name, $needle)) {
                    $studio++;

                    continue 2;
                }
            }
            foreach (self::W433_ADULT_SIGNAL_MARKERS['jav'] as $needle) {
                if (str_contains($name, $needle)) {
                    $jav++;

                    continue 2;
                }
            }
            foreach (self::W433_ADULT_SIGNAL_MARKERS['keyword'] as $needle) {
                if (str_contains($name, $needle)) {
                    $keyword++;

                    continue 2;
                }
            }
            $unknown++;
        }

        return [
            'total' => \count($releaseNames),
            'studio' => $studio,
            'jav' => $jav,
            'keyword' => $keyword,
            'unknown' => $unknown,
            'xxx' => $xxxCount,
        ];
    }

    /**
     * Declines (null) for non-string items so PHP counts them.
     *
     * @param  array<array-key, mixed>  $releaseNames
     * @return array{total: int, studio: int, jav: int, keyword: int, unknown: int, xxx: int}|null
     */
    public static function summarizeAdultSignalBatchNative(array $releaseNames): ?array
    {
        $lengths = [];
        foreach ($releaseNames as $item) {
            if (! \is_string($item)) {
                return null;
            }
            $lengths[] = \strlen($item);
        }
        $blob = implode('', $releaseNames);
        $lens = pack('V*', ...$lengths);
        $fields = self::callW433Native($blob, $lens);
        if ($fields === null) {
            return null;
        }

        return [
            'total' => (int) $fields[0],
            'studio' => (int) $fields[1],
            'jav' => (int) $fields[2],
            'keyword' => (int) $fields[3],
            'unknown' => (int) $fields[4],
            'xxx' => (int) $fields[5],
        ];
    }

    /**
     * Calls this class's Wave 43.3 export with the shared scratch buffer, retrying once at the size
     * it asks for. Returns its fields only when they match W433_FIELDS_PATTERN: exactly the expected
     * count of 1-19 digit decimals.
     *
     * @return list<string>|null
     */
    private static function callW433Native(string $blob, string $lens): ?array
    {
        $ffi = self::w433RustFfi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 128, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w433_xxx_pipe_adult_signal_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w433_xxx_pipe_adult_signal_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }
            $raw = FFI::string($buffer, $written);

            return preg_match(self::W433_FIELDS_PATTERN, $raw) === 1 ? explode("\x1F", $raw) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function w433RustFfi(): ?FFI
    {
        if (self::$w433RustFfiHandle === null) {
            self::$w433RustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w433', self::W433_FFI_CDEF) ?? false;
        }

        return self::$w433RustFfiHandle === false ? null : self::$w433RustFfiHandle;
    }

    // ---------------------------------------------------------------------
    // Wave 54.4 batch helper
    // ---------------------------------------------------------------------

    /** Enabled: minimum of three paired runs cleared every 1.5x gate (common mean 4.05x, p99 2.24x; uncommon mean 4.02x, p99 2.89x); see RustXxxPipeIgdbClientCompanyGamePlatformW54ParityTest. */
    private const bool W54_RUST_FFI_DEFAULT = true;

    public const string W54_FFI_CDEF = <<<'C'
        ssize_t nntmux_w544_xxx_adult_signal_row_batch(const char *text, size_t text_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** W433_ADULT_SIGNAL_MARKERS classes by code. */
    private const array W54_ADULT_CLASSES = [1 => 'studio', 2 => 'jav', 3 => 'keyword'];

    /**
     * summarizeAdultSignalBatchPhp() per release name instead of as totals. Each name yields four
     * words: the class code (unknown 0, studio 1, jav 2, keyword 3) | 4 when it holds a
     * W433_XXX_TAG_MARKERS entry, the index of the first matching marker within its class (-1
     * for unknown), that marker's byte offset in the name (-1) and the byte offset of the first
     * xxx tag (-1). Matching is ASCII case-insensitive, as strtolower() is.
     *
     * @param  list<string>  $releaseNames
     * @return list<int>
     */
    public static function adultSignalRowBatch(array $releaseNames): array
    {
        return self::usesW54RustFfi()
            ? self::adultSignalRowBatchNative($releaseNames)
            : self::adultSignalRowBatchPhp($releaseNames);
    }

    /**
     * @param  list<string>  $releaseNames
     * @return list<int>
     */
    public static function adultSignalRowBatchNative(array $releaseNames): array
    {
        self::w54AssertList($releaseNames);
        $count = \count($releaseNames);
        if ($count === 0) {
            return [];
        }

        return self::w54Call(implode("\0", $releaseNames), '', $count, 4)
            ?? self::adultSignalRowBatchPhp($releaseNames);
    }

    /**
     * @param  list<string>  $releaseNames
     * @return list<int>
     */
    public static function adultSignalRowBatchPhp(array $releaseNames): array
    {
        self::w54AssertList($releaseNames);
        $tag = self::W433_XXX_TAG_MARKERS[0];
        $out = [];
        foreach ($releaseNames as $releaseName) {
            $name = strtolower($releaseName);
            $xxx = strpos($name, $tag);
            $class = 0;
            $index = -1;
            $at = -1;
            foreach (self::W54_ADULT_CLASSES as $code => $key) {
                foreach (self::W433_ADULT_SIGNAL_MARKERS[$key] as $k => $needle) {
                    $found = strpos($name, $needle);
                    if ($found !== false) {
                        $class = $code;
                        $index = $k;
                        $at = $found;

                        break 2;
                    }
                }
            }
            array_push($out, $xxx === false ? $class : $class | 4, $index, $at, $xxx === false ? -1 : $xxx);
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private static function w54AssertList(array $values): void
    {
        if (! array_is_list($values)) {
            throw new \InvalidArgumentException('W54 batch input must be a list.');
        }
    }

    public static function usesW54RustFfi(): bool
    {
        $enabled = self::w54RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W54_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W54 batch dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW54RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w54RustFfiState('enabled');
        self::w54RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w54RustFfiState('enabled', $previous);
        }
    }

    /**
     * One FFI crossing for the batch: NUL-joined text columns and packed int64 columns in,
     * `$words` int64 words back per record. Null when the library is missing or declines
     * (a value holding a NUL byte adds a field, which the export rejects).
     *
     * @return list<int>|null
     */
    private static function w54Call(string $text, string $ints, int $count, int $words): ?array
    {
        try {
            $state = self::w54RustFfiState();
            $ffi = $state['ffi'] ?? null;
            if ($ffi === null) {
                $ffi = RustPipelineLibrary::resolveDomain('metadata_w54', self::W54_FFI_CDEF) ?? false;
                self::w54RustFfiState('ffi', $ffi);
            }
            if (! $ffi instanceof FFI) {
                return null;
            }
            $need = $count * $words;
            $out = $state['out'] ?? null;
            $cap = $state['cap'] ?? 0;
            if (! $out instanceof CData || ! \is_int($cap) || $cap < $need) {
                $cap = max(1024, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w54RustFfiState('out', $out);
                self::w54RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W54_FFI_CDEF.)
            $written = $ffi->nntmux_w544_xxx_adult_signal_row_batch($text, \strlen($text), $ints, \strlen($ints), $count, $out, $cap);
            if ($written !== $need) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $need * 8));

            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * W54 routing override and cached binding plus reusable output buffer, kept in a function
     * static: no arguments returns the state, one argument reads a key ('reset' clears
     * everything) and two arguments write a key.
     */
    private static function w54RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;
        }

        return $state[$op] ?? null;
    }

    // ========================================================================
    // WAVE 67.1 BATCH HELPER
    // ========================================================================

    /** Benchmark-gated default; see RustXxxPipeCollectionCleanupCleaningConfigProviderRepoW67ParityTest. */
    public const bool W67_RUST_FFI_DEFAULT = true;

    public const string W67_XXX_PIPE_FFI_CDEF = <<<'C'
        ssize_t nntmux_w671_xxx_pipe_plan_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of w67XxxPipePlanBatch(). */
    public const int W67_XXX_PIPE_TRAILER = 28;

    public static function usesW67RustFfi(): bool
    {
        $override = self::w67RustFfiState();

        return \is_bool($override) ? $override : self::W67_RUST_FFI_DEFAULT;
    }

    /** Forces the XxxPipe Wave 67 Rust path on until reset. */
    public static function enableW67RustFfi(): void
    {
        self::w67RustFfiState('set', true);
    }

    /** Forces the XxxPipe Wave 67 PHP path until reset. */
    public static function disableW67RustFfi(): void
    {
        self::w67RustFfiState('set', false);
    }

    /** Restores the benchmark default and drops the cached library handle and scratch buffers; earlier toggles are untouched. */
    public static function resetW67RustFfi(): void
    {
        self::w67RustFfiState('reset');
    }

    /**
     * Runs $callback with the XxxPipe Wave 67 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW67RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w67RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w67RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 67.1 XXX candidate plans, one per `title|group|poster` row (trimmed; title, group and poster are ASCII-lowercased).
     * Signals: 1 `xxx` tag, 2 studio, 4 JAV and 8 keyword marker (W433_ADULT_SIGNAL_MARKERS), 16 adult group (xxx/erotica/porn),
     * 32 non-XXX veto (hdtv, documentary, ebook, epub, flac, mp3, linux, season or `sNNeNN`), 64 known clip poster; then
     * 128 title repeated, 256 non-ASCII, 512 control byte. Score = 40/30/25/20/15/10 for the xxx, studio, JAV, keyword, group
     * and poster signals. Subcategory, first match: 1 UHD, 2 imageset, 3 pack, 4 DVD, 5 WMV, 6 XviD, 7 x264, 8 clip, 0 other.
     * Decision: 0 empty title, 1 vetoed without an xxx tag, 2 no signal, 3 weak (score < 40), 4 XXX. Per item
     * `decision | sub << 3 | flags << 7 | score << 17 | categoryId << 25 | min(titleLength, 65535) << 38` (the category id of
     * the subcategory for decisions 3 and 4, else 0); then 5 decision, 9 subcategory and 10 flag counts, the score sum and
     * maximum, and the distinct titles and groups.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w67XxxPipePlanBatch(array $items): array
    {
        return self::usesW67RustFfi() ? self::w67XxxPipePlanBatchNative($items) : self::w67XxxPipePlanBatchPhp($items);
    }

    /**
     * Rust plan; falls back to the PHP reference when the library is missing or the call declines.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w67XxxPipePlanBatchNative(array $items): array
    {
        return self::w67Invoke($items, \count($items) + self::W67_XXX_PIPE_TRAILER) ?? self::w67XxxPipePlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w67XxxPipePlanBatchPhp(array $items): array
    {
        $out = [];
        $tail = array_fill(0, self::W67_XXX_PIPE_TRAILER, 0);
        $titles = [];
        $groups = [];
        foreach ($items as $item) {
            $item = (string) $item;
            [$rawTitle, $rawGroup, $rawPoster] = explode('|', $item, 3) + ['', '', ''];
            $title = trim($rawTitle);
            $lower = strtolower($title);
            $group = strtolower(trim($rawGroup));
            $poster = strtolower(trim($rawPoster));
            $veto = self::w67ContainsAny($lower, self::W67_XXX_VETO) || preg_match('/s[0-9]{2}e[0-9]{2}/', $lower) === 1;
            $flags = (str_contains($lower, 'xxx') ? 1 : 0) | (self::w67ContainsAny($lower, self::W67_XXX_STUDIOS) ? 2 : 0)
                | (self::w67ContainsAny($lower, self::W67_XXX_JAV) ? 4 : 0) | (self::w67ContainsAny($lower, self::W67_XXX_KEYWORDS) ? 8 : 0)
                | (self::w67ContainsAny($group, self::W67_XXX_GROUPS) ? 16 : 0) | ($veto ? 32 : 0)
                | (self::w67ContainsAny($poster, self::W67_XXX_POSTERS) ? 64 : 0);
            $score = 40 * ($flags & 1) + 30 * ($flags >> 1 & 1) + 25 * ($flags >> 2 & 1) + 20 * ($flags >> 3 & 1) + 15 * ($flags >> 4 & 1)
                + 10 * ($flags >> 6 & 1);
            $flags |= (preg_match('/[\x80-\xff]/', $item) === 1 ? 256 : 0) | (strcspn($item, self::W67_CONTROL_BYTES) !== \strlen($item) ? 512 : 0);
            $sub = 0;
            foreach (self::W67_XXX_SUBCATEGORIES as $code => $needles) {
                if ($code === 4 ? preg_match('/dvdr[^i]|dvd[59]/', $lower) === 1 : self::w67ContainsAny($lower, $needles)) {
                    $sub = $code;

                    break;
                }
            }
            $decision = match (true) {
                $title === '' => 0,
                $veto && ($flags & 1) === 0 => 1,
                $score === 0 => 2,
                $score < 40 => 3,
                default => 4,
            };
            if ($title !== '') {
                if (isset($titles[$lower])) {
                    $flags |= 128;
                } else {
                    $titles[$lower] = true;
                }
            }
            if ($group !== '') {
                $groups[$group] = true;
            }
            $category = $decision >= 3 ? self::W67_XXX_CATEGORIES[$sub] : 0;
            $tail[$decision]++;
            $tail[5 + $sub]++;
            for ($b = 0; $b < 10; $b++) {
                $tail[14 + $b] += $flags >> $b & 1;
            }
            $tail[24] += $score;
            $tail[25] = max($tail[25], $score);
            $out[] = $decision | $sub << 3 | $flags << 7 | $score << 17 | $category << 25 | min(\strlen($title), 65535) << 38;
        }
        $tail[26] = \count($titles);
        $tail[27] = \count($groups);
        array_push($out, ...$tail);

        return $out;
    }

    private const string W67_CONTROL_BYTES = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F";

    /** @var list<string> */
    private const array W67_XXX_STUDIOS = ['brazzers', 'realitykings', 'naughtyamerica', 'bangbros', 'vixen', 'blacked', 'tushy', 'evilangel', 'teamskeet', 'mofos'];

    /** @var list<string> */
    private const array W67_XXX_JAV = ['1pondo', 'caribbeancom', 'heyzo', 'tokyohot', '10musume', 'fc2-ppv'];

    /** @var list<string> */
    private const array W67_XXX_KEYWORDS = ['porn', 'milf', 'hardcore', 'lesbian', 'creampie', 'blowjob', 'threesome'];

    /** @var list<string> */
    private const array W67_XXX_GROUPS = ['xxx', 'erotica', 'porn'];

    /** @var list<string> */
    private const array W67_XXX_VETO = ['hdtv', 'documentary', 'ebook', 'epub', 'flac', 'mp3', 'linux', 'season'];

    /** @var list<string> */
    private const array W67_XXX_POSTERS = ['anon@y.com', '@md-hobbys.com', 'oz@lot.com'];

    /** Category ids by subcategory code. */
    private const array W67_XXX_CATEGORIES = [Category::XXX_OTHER, Category::XXX_UHD, Category::XXX_IMAGESET, Category::XXX_PACK, Category::XXX_DVD,
        Category::XXX_WMV, Category::XXX_XVID, Category::XXX_X264, Category::XXX_CLIPHD];

    /** Subcategory code => lowercase markers, in match order; DVD (4) matches `dvdr[^i]|dvd[59]` instead. */
    private const array W67_XXX_SUBCATEGORIES = [1 => ['2160p', 'uhd', '4k'], 2 => ['imageset', 'pictureset', 'imgset'], 3 => ['.pack.', ' pack ', '_pack_', '-pack-'],
        4 => [], 5 => ['wmv'], 6 => ['xvid', 'divx', 'dvdrip', 'bdrip', 'brrip'], 7 => ['x264', 'h264', 'h.264', 'x265', 'hevc', '1080p', '720p'],
        8 => ['clip', 'siterip', 'mp4']];

    /**
     * Whether $haystack holds any of $needles.
     *
     * @param  list<string>  $needles
     */
    private static function w67ContainsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Wave 67 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'reset' clears the override, library handle and buffers, 'ffi'
     * resolves the library (null when unavailable) and 'out' and 'lens' return `int64_t` output and
     * length-column buffers of at least $val words.
     */
    private static function w67RustFfiState(?string $op = null, mixed $val = null): mixed
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
        if ($op === 'reset') {
            $override = $ffi = $out = $lens = null;
            $outCap = $lensCap = 0;

            return null;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('xxx_pipe_w671', self::W67_XXX_PIPE_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w67RustFfiState('ffi');
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
                $handle = self::w67RustFfiState('ffi');
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
     * Calls the Wave 67.1 XxxPipe export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w67Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w67RustFfiState('ffi');
            $out = self::w67RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w67RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W67_XXX_PIPE_FFI_CDEF.)
            $written = $ffi->nntmux_w671_xxx_pipe_plan_batch($blob, \strlen($blob), $lens, $count, $out, $words);
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
