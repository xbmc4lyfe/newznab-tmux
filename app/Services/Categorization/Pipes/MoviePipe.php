<?php

declare(strict_types=1);

namespace App\Services\Categorization\Pipes;

use App\Models\Category;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\Categorizers\MovieCategorizer;
use App\Services\Categorization\ReleaseContext;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Pipe for Movie content categorization.
 */
class MoviePipe extends AbstractCategorizationPipe
{
    /**
     * Wave 40.3 production default is PHP: the paired benchmark in
     * tests/Unit/RustCategorizationServiceGroupNameFixerW40ParityTest.php measures only
     * 0.42-0.43x mean / 0.37-0.50x p99 on the common batch, below the 1.5x adoption threshold, so Rust
     * stays behind useW403RustFfi(true). Named W403 so it stays distinct from other Rust FFI toggles.
     */
    public const bool W403_RUST_FFI_DEFAULT = false;

    public const string W403_FFI_CDEF = <<<'C'
        ssize_t nntmux_w403_candidate_score_window(
            const char *values_ptr,
            size_t values_len,
            int64_t threshold_permille,
            char *out_buf,
            size_t out_cap
        );
        C;

    public const int W403_MAX_PERMILLE = 1000;

    /** A winner above the threshold is unambiguous alone or this far ahead of the runner-up. */
    public const int W403_MIN_WINNER_MARGIN_PERMILLE = 50;

    private static ?bool $w403RustFfiOverride = null;

    private static FFI|false|null $w403RustFfiHandle = null;

    public const string PIPE_SPEC_FFI_CDEF = <<<'C'
        ssize_t nntmux_categorize_pipes_spec_name(uint8_t kind, char *out_buf, size_t out_cap);
        int64_t nntmux_categorize_pipes_spec_priority(uint8_t kind);
        int32_t nntmux_categorize_pipes_spec_evaluate_result(uint8_t kind, int64_t category_id, double confidence, const char *matched_by_ptr, size_t matched_by_len);
        C;

    /** The result passes `CategorizationResult::isSuccessful()`. */
    public const int RESULT_SUCCESSFUL = 1;

    /** The category id lies in this pipe's category family. */
    public const int RESULT_IN_DOMAIN = 2;

    /** The confidence reaches the `CategorizationPassable` stop threshold. */
    public const int RESULT_TERMINAL = 4;

    private const int PIPE_SPEC_KIND = 2;

    private const string PIPE_NAME = 'Movie';

    private const int PIPE_PRIORITY = 25;

    // Public because the parent declares it public; a private redeclaration is a fatal error.
    public static bool $useRustFfi = false;

    private static FFI|false|null $pipeSpecFfi = null;

    protected int $priority = self::PIPE_PRIORITY;

    private MovieCategorizer $categorizer;

    public function __construct()
    {
        $this->categorizer = new MovieCategorizer;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function setPipeSpecFfiForTesting(FFI|false|null $ffi): void
    {
        self::$pipeSpecFfi = $ffi;
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
        $ffi = self::pipeSpecFfi();
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
            $written = $ffi->nntmux_categorize_pipes_spec_name(self::PIPE_SPEC_KIND, $scratch, $cap);
            if (! \is_int($written) || $written <= 0) {
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
        $ffi = self::pipeSpecFfi();
        if ($ffi === null) {
            return self::pipePriorityPhp();
        }

        try {
            // @phpstan-ignore method.notFound
            $priority = $ffi->nntmux_categorize_pipes_spec_priority(self::PIPE_SPEC_KIND);
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
        if ($categoryId >= Category::MOVIE_ROOT && $categoryId <= Category::MOVIE_ROOT + 999) {
            $mask |= self::RESULT_IN_DOMAIN;
        }
        if ($confidence >= 0.95) {
            $mask |= self::RESULT_TERMINAL;
        }

        return $mask;
    }

    public static function evaluateResultNative(int $categoryId, float $confidence, string $matchedBy): int
    {
        $ffi = self::pipeSpecFfi();
        if ($ffi === null) {
            return self::evaluateResultPhp($categoryId, $confidence, $matchedBy);
        }

        try {
            // @phpstan-ignore method.notFound
            $mask = $ffi->nntmux_categorize_pipes_spec_evaluate_result(
                self::PIPE_SPEC_KIND,
                $categoryId,
                $confidence,
                $matchedBy,
                \strlen($matchedBy)
            );
        } catch (Throwable) {
            return self::evaluateResultPhp($categoryId, $confidence, $matchedBy);
        }

        return \is_int($mask) && $mask >= 0 ? $mask : self::evaluateResultPhp($categoryId, $confidence, $matchedBy);
    }

    protected function shouldSkip(ReleaseContext $context): bool
    {
        return $this->categorizer->shouldSkip($context);
    }

    protected function categorize(ReleaseContext $context): CategorizationResult
    {
        return $this->categorizer->categorize($context);
    }

    private static function pipeSpecFfi(): ?FFI
    {
        if (self::$pipeSpecFfi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            try {
                self::$pipeSpecFfi = RustPipelineLibrary::resolveDomain(
                    'categorize',
                    self::PIPE_SPEC_FFI_CDEF,
                    \is_string($path) && $path !== '' ? $path : null
                ) ?? false;
            } catch (Throwable) {
                self::$pipeSpecFfi = false;
            }
        }

        return self::$pipeSpecFfi === false ? null : self::$pipeSpecFfi;
    }

    public static function useW403RustFfi(?bool $enabled): void
    {
        self::$w403RustFfiOverride = $enabled;
    }

    public static function usesW403RustFfi(): bool
    {
        return self::$w403RustFfiOverride ?? self::W403_RUST_FFI_DEFAULT;
    }

    public static function resetW403RustFfi(): void
    {
        self::$w403RustFfiOverride = null;
        self::$w403RustFfiHandle = null;
    }

    /**
     * Summary of a window of candidate similarity scores in permille. Integer scores and the
     * threshold are clamped to 0..W403_MAX_PERMILLE; non-integer scores are invalid and skipped. The
     * best candidate is an unambiguous winner when it reaches the threshold and is either the only
     * candidate or at least W403_MIN_WINNER_MARGIN_PERMILLE ahead of the runner-up.
     *
     * @param  array<array-key, mixed>  $scoresPermille
     * @return array{candidates: int, invalid: int, above_threshold: int, best: int, runner_up: int, margin: int, unambiguous: bool, threshold: int}
     */
    public static function summarizeCandidateScoreWindow(array $scoresPermille, int $thresholdPermille = 850): array
    {
        return (self::usesW403RustFfi() ? self::summarizeCandidateScoreWindowNative($scoresPermille, $thresholdPermille) : null)
            ?? self::summarizeCandidateScoreWindowPhp($scoresPermille, $thresholdPermille);
    }

    /**
     * @param  array<array-key, mixed>  $scoresPermille
     * @return array{candidates: int, invalid: int, above_threshold: int, best: int, runner_up: int, margin: int, unambiguous: bool, threshold: int}
     */
    public static function summarizeCandidateScoreWindowPhp(array $scoresPermille, int $thresholdPermille = 850): array
    {
        $threshold = max(0, min(self::W403_MAX_PERMILLE, $thresholdPermille));
        $invalid = 0;
        $above = 0;
        $best = 0;
        $runnerUp = 0;
        foreach ($scoresPermille as $raw) {
            if (! \is_int($raw)) {
                $invalid++;

                continue;
            }
            $score = max(0, min(self::W403_MAX_PERMILLE, $raw));
            if ($score >= $threshold) {
                $above++;
            }
            if ($score > $best) {
                $runnerUp = $best;
                $best = $score;
            } elseif ($score > $runnerUp) {
                $runnerUp = $score;
            }
        }
        $candidates = \count($scoresPermille) - $invalid;
        $margin = $best - $runnerUp;

        return [
            'candidates' => $candidates,
            'invalid' => $invalid,
            'above_threshold' => $above,
            'best' => $best,
            'runner_up' => $runnerUp,
            'margin' => $margin,
            'unambiguous' => $above >= 1 && ($candidates === 1 || $margin >= self::W403_MIN_WINNER_MARGIN_PERMILLE),
            'threshold' => $threshold,
        ];
    }

    /**
     * Declines (null) for non-integer scores so PHP counts them as invalid.
     *
     * @param  array<array-key, mixed>  $scoresPermille
     * @return array{candidates: int, invalid: int, above_threshold: int, best: int, runner_up: int, margin: int, unambiguous: bool, threshold: int}|null
     */
    public static function summarizeCandidateScoreWindowNative(array $scoresPermille, int $thresholdPermille = 850): ?array
    {
        foreach ($scoresPermille as $item) {
            if (! \is_int($item)) {
                return null;
            }
        }
        $packed = pack('P*', ...array_values($scoresPermille));
        $fields = self::callW403Native('nntmux_w403_candidate_score_window', [$packed, \strlen($packed), $thresholdPermille], 96, 7);
        if ($fields === null || ($fields[5] !== '0' && $fields[5] !== '1')) {
            return null;
        }

        return [
            'candidates' => (int) $fields[0],
            'invalid' => 0,
            'above_threshold' => (int) $fields[1],
            'best' => (int) $fields[2],
            'runner_up' => (int) $fields[3],
            'margin' => (int) $fields[4],
            'unambiguous' => $fields[5] === '1',
            'threshold' => (int) $fields[6],
        ];
    }

    /**
     * Calls a Wave 40.3 export with the shared scratch buffer, retrying once at the size it asks for.
     * Returns its fields only when there are exactly $fieldCount, all of them decimal.
     *
     * @param  list<int|string>  $args  arguments preceding the caller-owned output buffer
     * @return list<string>|null
     */
    private static function callW403Native(string $symbol, array $args, int $minBytes, int $fieldCount): ?array
    {
        $ffi = self::w403RustFfi();
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
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }
            $fields = explode("\x1F", FFI::string($buffer, $written));
            if (\count($fields) !== $fieldCount) {
                return null;
            }
            foreach ($fields as $field) {
                if (\strlen($field) > 19 || ! ctype_digit($field)) {
                    return null;
                }
            }

            return $fields;
        } catch (Throwable) {
            return null;
        }
    }

    private static function w403RustFfi(): ?FFI
    {
        if (self::$w403RustFfiHandle === null) {
            self::$w403RustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w403', self::W403_FFI_CDEF) ?? false;
        }

        return self::$w403RustFfiHandle === false ? null : self::$w403RustFfiHandle;
    }

    /** Wave 53.2 paired benchmarks decide this default; see RustCategorizationPipesAbstractBookPassableMiscMovieW53ParityTest. */
    private const bool W53_RUST_FFI_DEFAULT = true;

    private const string W53_FFI_CDEF = <<<'C'
        intptr_t nntmux_w532_movie_result_plan_batch(const char *labels, size_t labels_len, const char *categories, size_t categories_len, const char *confidences, size_t confidences_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /**
     * Wave 53 routing, binding and output buffer, kept in function-local statics. Operations:
     * null reads the routing override, 'set' replaces it (returning the previous one), 'ffi'
     * resolves the binding and 'out' returns `[binding, buffer, capacity]` with room for at
     * least $val words (null without the library).
     */
    private static function w53RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w53', self::W53_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w53RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    public static function usesW53RustFfi(): bool
    {
        $enabled = self::w53RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W53_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with planMovieResultBatch() routed to Rust ($enabled) or PHP, then restores the
     * previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW53RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w53RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w53RustFfiState('set', $previous);
        }
    }

    /** Wave 53.2 sub-category histogram slots (0 out of domain, 11 other in-domain ids). */
    private const array W53_SUBCATEGORIES = [
        Category::MOVIE_FOREIGN => 1,
        Category::MOVIE_OTHER => 2,
        Category::MOVIE_SD => 3,
        Category::MOVIE_HD => 4,
        Category::MOVIE_UHD => 5,
        Category::MOVIE_3D => 6,
        Category::MOVIE_BLURAY => 7,
        Category::MOVIE_DVD => 8,
        Category::MOVIE_WEBDL => 9,
        Category::MOVIE_X265 => 10,
    ];

    /** Wave 53.2 matched-by families in match order: prefix => [index, category the family assigns]. */
    private const array W53_FAMILIES = [
        'foreign_' => [1, Category::MOVIE_FOREIGN],
        'uhd' => [2, Category::MOVIE_UHD],
        'bluray' => [3, Category::MOVIE_BLURAY],
        'dvd' => [4, Category::MOVIE_DVD],
        'webdl' => [5, Category::MOVIE_WEBDL],
        'hd' => [6, Category::MOVIE_HD],
        'sd' => [7, Category::MOVIE_SD],
        'cam' => [8, Category::MOVIE_OTHER],
    ];

    /**
     * Batch result plan for Movie results, as evaluateResultPhp() grades them. One word per
     * result: `mask | sub << 8 | family << 16 | crc32(matchedBy) << 32`. Mask: RESULT_SUCCESSFUL,
     * RESULT_IN_DOMAIN and RESULT_TERMINAL, plus 8 when the matched-by family's category equals
     * the result's. `sub` is the W53_SUBCATEGORIES slot (11 other in-domain ids, 0 out of
     * domain); `family` the first W53_FAMILIES prefix of matchedBy (0 none). Successful,
     * in-domain, terminal and agreeing counts, the first terminal row (-1 none), the 12-slot
     * sub-category histogram and the XXH64 digest of all earlier words follow.
     *
     * @param  list<int>  $categoryIds
     * @param  list<float>  $confidences
     * @param  list<string>  $matchedBy
     * @return list<int>
     */
    public static function planMovieResultBatch(array $categoryIds, array $confidences, array $matchedBy): array
    {
        return self::usesW53RustFfi()
            ? self::planMovieResultBatchNative($categoryIds, $confidences, $matchedBy)
            : self::planMovieResultBatchPhp($categoryIds, $confidences, $matchedBy);
    }

    /**
     * @param  list<int>  $categoryIds
     * @param  list<float>  $confidences
     * @param  list<string>  $matchedBy
     * @return list<int>
     */
    public static function planMovieResultBatchPhp(array $categoryIds, array $confidences, array $matchedBy): array
    {
        [$categoryIds, $confidences] = [array_values($categoryIds), array_values($confidences)];
        $out = [];
        $ok = $domain = $terminal = $agree = 0;
        $firstTerminal = -1;
        $histogram = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
        foreach (array_values($matchedBy) as $i => $label) {
            $category = $categoryIds[$i] ?? 0;
            $confidence = (float) ($confidences[$i] ?? 0.0);
            $success = ! ($confidence <= 0) && ! ($category === Category::OTHER_MISC && $label === 'no_match');
            $inDomain = $category >= Category::MOVIE_ROOT && $category <= Category::MOVIE_ROOT + 999;
            $stop = $confidence >= 0.95;
            $sub = self::W53_SUBCATEGORIES[$category] ?? ($inDomain ? 11 : 0);
            $family = 0;
            $matches = false;
            foreach (self::W53_FAMILIES as $prefix => [$index, $familyCategory]) {
                if (str_starts_with($label, $prefix)) {
                    $family = $index;
                    $matches = $familyCategory === $category;
                    break;
                }
            }
            $ok += (int) $success;
            $domain += (int) $inDomain;
            $terminal += (int) $stop;
            $agree += (int) $matches;
            if ($stop && $firstTerminal < 0) {
                $firstTerminal = $i;
            }
            $histogram[$sub]++;
            $out[] = ($success ? self::RESULT_SUCCESSFUL : 0) | ($inDomain ? self::RESULT_IN_DOMAIN : 0) | ($stop ? self::RESULT_TERMINAL : 0) | ($matches ? 8 : 0)
                | $sub << 8 | $family << 16 | crc32($label) << 32;
        }
        array_push($out, $ok, $domain, $terminal, $agree, $firstTerminal, ...$histogram);
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));
        $out[] = $digest[1];

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, the lists differ in length or
     * are not lists, or a label holds a NUL byte.
     *
     * @param  list<int>  $categoryIds
     * @param  list<float>  $confidences
     * @param  list<string>  $matchedBy
     * @return list<int>
     */
    public static function planMovieResultBatchNative(array $categoryIds, array $confidences, array $matchedBy): array
    {
        $count = \count($matchedBy);
        $words = $count + 18;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w53RustFfiState('out', $words);
        if ($state === null || \count($categoryIds) !== $count || \count($confidences) !== $count
            || ! array_is_list($matchedBy) || ! array_is_list($categoryIds) || ! array_is_list($confidences)) {
            return self::planMovieResultBatchPhp($categoryIds, $confidences, $matchedBy);
        }
        $labels = implode("\0", $matchedBy);
        $categories = pack('P*', ...$categoryIds);
        $packed = pack('e*', ...$confidences);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W53_FFI_CDEF.)
            $written = $ffi->nntmux_w532_movie_result_plan_batch($labels, \strlen($labels), $categories, \strlen($categories), $packed, \strlen($packed), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : self::planMovieResultBatchPhp($categoryIds, $confidences, $matchedBy);
    }

    /** C signature of the Wave 66.6 {@see self::w66MoviePipePlanBatch()} helper. */
    private const string W66_MOVIE_PIPE_PLAN_FFI_CDEF = 'int64_t nntmux_w666_movie_pipe_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /**
     * The Wave 66.6 plan batch defaults to Rust: every common and uncommon mean and p99 ratio cleared 1.5x
     * (min 7.37/6.01/5.98/3.75x); see tests/Unit/RustCategorizationMiscSafetyMovieMusicPcTvPipeW66ParityTest.php.
     */
    public const bool W66_RUST_FFI_DEFAULT = true;

    /** `S\d{1,3}(?:E\d{1,4})?` over a whole uppercased token. */
    private const string W66_SEASON_PATTERN = '/\AS(\d{1,3})(?:E(\d{1,4}))?\z/';

    /** Wave 66.6 flags per uppercased token: 1 SD, 2 HD, 4 UHD, 8 x265, 16 3D, 32 BluRay disc, 64 DVD, 128 WEB, 256 foreign, 2048 movie source. */
    private const array W66_MOVIE_TOKENS = [
        '480P' => 1, '576P' => 1, 'DVDRIP' => 1, 'XVID' => 1, 'DIVX' => 1,
        '720P' => 2, '1080P' => 2, '1080I' => 2,
        '2160P' => 4, '4320P' => 4, '4K' => 4, 'UHD' => 4,
        'X265' => 8, 'H265' => 8, 'HEVC' => 8,
        '3D' => 16, 'SBS' => 16, 'HSBS' => 16, 'HOU' => 16, 'HALFSBS' => 16,
        'BDMV' => 32, 'BD25' => 32, 'BD50' => 32, 'BD66' => 32, 'BD100' => 32,
        'DVDR' => 64, 'DVD5' => 64, 'DVD9' => 64,
        'WEB' => 128, 'WEBDL' => 128, 'WEBRIP' => 128,
        'GERMAN' => 256, 'FRENCH' => 256, 'SPANISH' => 256, 'ITALIAN' => 256, 'DUTCH' => 256, 'SWEDISH' => 256, 'NORDIC' => 256, 'DANISH' => 256, 'FINNISH' => 256, 'NORWEGIAN' => 256, 'POLISH' => 256, 'RUSSIAN' => 256, 'HINDI' => 256, 'TURKISH' => 256, 'PORTUGUESE' => 256,
        'BLURAY' => 2048, 'BDRIP' => 2048, 'BRRIP' => 2048, 'REMUX' => 2048, 'HDRIP' => 2048, 'CAM' => 2048, 'HDCAM' => 2048, 'TELESYNC' => 2048, 'DVDSCR' => 2048, 'SCREENER' => 2048,
    ];

    /** Wave 66.6 proposals in priority order: flag bits => category. */
    private const array W66_MOVIE_PROPOSALS = [
        256 => Category::MOVIE_FOREIGN,
        16 => Category::MOVIE_3D,
        32 => Category::MOVIE_BLURAY,
        4 => Category::MOVIE_UHD,
        8 => Category::MOVIE_X265,
        64 => Category::MOVIE_DVD,
        128 => Category::MOVIE_WEBDL,
        2 => Category::MOVIE_HD,
        1 => Category::MOVIE_SD,
        2048 => Category::MOVIE_OTHER,
    ];

    /**
     * Wave 66.6 routing and FFI cache, kept in function-local statics (no properties). Without $op it
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
                    $ffi = RustPipelineLibrary::resolveDomain('w66_6', self::W66_MOVIE_PIPE_PLAN_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
                }
                if (! $ffi instanceof FFI || ! \is_int($val)) {
                    return null;
                }
                if (! $out instanceof CData || $outCap < $val) {
                    $outCap = max(1024, $val);
                    $out = $ffi->new('int64_t['.$outCap.']');
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled ?? self::W66_RUST_FFI_DEFAULT;
        }
    }

    /** Route the Wave 66.6 plan batch to Rust until disabled or reset. */
    public static function enableW66RustFfi(): void
    {
        self::w66RustFfiState('set', true);
    }

    /** Route the Wave 66.6 plan batch to PHP until enabled or reset. */
    public static function disableW66RustFfi(): void
    {
        self::w66RustFfiState('set', false);
    }

    /** Restore W66_RUST_FFI_DEFAULT routing and drop the cached Wave 66.6 handle and buffer. */
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
     * Runs $callback with w66MoviePipePlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
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
     * Exactly $words output words from the Wave 66.6 Rust helper over $count NUL-joined records, or null
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
            $written = $ffi->nntmux_w666_movie_pipe_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
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
     * Batch Movie pipe plan over `subject\tcategoryId\tconfidence\tmatchedBy\tcurrentCategory`, confidences in
     * permille. Eight words per record: `[tokens, flags, proposed, mask, decision, target, extra, errors]`. Tokens are
     * maximal ASCII alphanumeric runs of the subject compared case-insensitively; flags: W66_MOVIE_TOKENS bits, 512 a TV season token
     * (`S\d{1,3}(?:E\d{1,4})?`), 1024 a 1900-2099 year. Proposed is the
     * first W66_MOVIE_PROPOSALS category whose flag bits are set (0 none). Mask: 1 successful (like evaluateResultPhp()),
     * 2 in domain, 4 terminal (>= 950), 8 proposed equals the result category. Decision/target: 3 keeps an
     * OTHER_HASHED current category, 1 takes a successful in-domain result, 4 keeps the current category when a TV season token
     * vetoes the movie pipe,
     * 2 moves an empty, OTHER_MISC or MOVIE_OTHER current category to the proposal, 0 keeps the current category
     * (default OTHER_MISC). Extra: the first year token (0 none). Numbers are trimmed 1-15 ASCII digits; errors: 1 invalid number, 2 not
     * exactly five fields.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w66MoviePipePlanBatch(array $records): array
    {
        return self::usesW66RustFfi() ? self::w66MoviePipePlanBatchNative($records) : self::w66MoviePipePlanBatchPhp($records);
    }

    /**
     * Rust batch over the NUL-joined records; falls back to PHP without the library or when a record holds
     * a NUL.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w66MoviePipePlanBatchNative(array $records): array
    {
        $count = \count($records);
        if ($count === 0) {
            return [];
        }

        return self::w66Words($count, implode("\0", $records), 8 * $count) ?? self::w66MoviePipePlanBatchPhp($records);
    }

    /**
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w66MoviePipePlanBatchPhp(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $fields = explode("\t", $record);
            $errors = \count($fields) === 5 ? 0 : 2;
            $tokens = $flags = $year = 0;
            foreach (preg_split('/[^A-Z0-9]+/', strtoupper($fields[0]), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                $tokens++;
                $flags |= self::W66_MOVIE_TOKENS[$token] ?? 0;
                if ($token[0] === 'S' && preg_match(self::W66_SEASON_PATTERN, $token) === 1) {
                    $flags |= 512;
                }
                if (\strlen($token) === 4 && ctype_digit($token) && $token >= '1900' && $token <= '2099') {
                    $flags |= 1024;
                    $year = $year === 0 ? (int) $token : $year;
                }
            }
            $proposed = 0;
            foreach (self::W66_MOVIE_PROPOSALS as $bits => $candidate) {
                if (($flags & $bits) !== 0) {
                    $proposed = $candidate;
                    break;
                }
            }
            $category = self::w66Number($fields[1] ?? '', 0, $errors);
            $confidence = self::w66Number($fields[2] ?? '', 0, $errors);
            $label = $fields[3] ?? '';
            $current = self::w66Number($fields[4] ?? '', Category::OTHER_MISC, $errors);
            $success = $confidence > 0 && ! ($category === Category::OTHER_MISC && $label === 'no_match');
            $inDomain = $category >= Category::MOVIE_ROOT && $category <= Category::MOVIE_ROOT + 999;
            [$decision, $target] = match (true) {
                $current === Category::OTHER_HASHED => [3, $current],
                $success && $inDomain => [1, $category],
                ($flags & 512) !== 0 => [4, $current],
                $proposed !== 0 && ($current === 0 || $current === Category::OTHER_MISC || $current === Category::MOVIE_OTHER) => [2, $proposed],
                default => [0, $current],
            };
            $mask = ($success ? 1 : 0) | ($inDomain ? 2 : 0) | ($confidence >= 950 ? 4 : 0) | ($proposed !== 0 && $proposed === $category ? 8 : 0);
            array_push($out, $tokens, $flags, $proposed, $mask, $decision, $target, $year, $errors);
        }

        return $out;
    }
}
