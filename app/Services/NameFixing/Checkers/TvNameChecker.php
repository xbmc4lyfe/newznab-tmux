<?php

declare(strict_types=1);

namespace App\Services\NameFixing\Checkers;

use App\Services\NameFixing\Data\NameFixResult;
use App\Services\NameFixing\Patterns\TvPatterns;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;

/**
 * Name checker for TV show releases.
 *
 * Checks release names against TV-specific patterns including streaming services,
 * daily shows, sports, complete seasons, and anime.
 */
class TvNameChecker extends AbstractNameChecker
{
    private const string FFI_CDEF = <<<'C'
        ssize_t nntmux_name_check_tv(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_name_check_movie(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_name_check_game(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_name_check_app(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        C;

    private static bool $ffiResolved = false;

    private static ?FFI $defaultFfi = null;

    private static ?CData $defaultBuf = null;

    private static int $defaultCap = 0;

    /**
     * Rust remains opt-in: paired caller benchmarks did not meet the 1.5x
     * common-path and uncommon p99 gate. PHP remains the production default.
     */
    public function __construct(
        private readonly ?string $libraryPath = null,
        private readonly bool $useRust = false,
    ) {}

    protected int $priority = 10;

    protected string $name = 'TV';

    /**
     * {@inheritdoc}
     */
    protected function getPatterns(): array
    {
        return TvPatterns::getAllPatterns();
    }

    /**
     * {@inheritdoc}
     */
    protected function formatMethod(string $patternName): string
    {
        $methodMap = [
            'STREAMING_4K_HDR' => 'Title.SxxExx.4K.streaming.source.hdr.vcodec',
            'STREAMING_HD' => 'Title.SxxExx.res.streaming.source',
            'TV_SOURCE_GROUP' => 'Title.SxxExx.Text.source.group',
            'TV_WITH_YEAR' => 'Title.SxxExx.Text.year.group',
            'TV_RES_SOURCE_VCODEC' => 'Title.SxxExx.Text.resolution.source.vcodec.group',
            'TV_SOURCE_VCODEC' => 'Title.SxxExx.source.vcodec.group',
            'TV_AUDIO_SOURCE_RES_VCODEC' => 'Title.SxxExx.acodec.source.res.vcodec.group',
            'TV_YEAR_SEASON' => 'Title.year.###(season/episode).source.group',
            'DAILY_SHOW' => 'Daily show with date',
            'SPORTS' => 'Sports',
            'COMPLETE_SEASON' => 'Complete season',
            'ANIME' => 'Anime episode',
        ];

        return $methodMap[$patternName] ?? $this->patternNameToMethod($patternName);
    }

    /**
     * {@inheritdoc}
     *
     * Override to provide more specific TV checking with pattern priority.
     */
    public function check(object $release, string $textstring): ?NameFixResult
    {
        // Subclasses may customize pattern order or result formatting.
        if (! $this->useRust || get_class($this) !== self::class) {
            return $this->checkPhp($release, $textstring);
        }

        if ($this->libraryPath !== null) {
            $ffi = RustPipelineLibrary::resolveDomain('name_checkers', self::FFI_CDEF, $this->libraryPath);
        } else {
            if (! self::$ffiResolved) {
                self::$defaultFfi = RustPipelineLibrary::resolveDomain('name_checkers', self::FFI_CDEF);
                if (self::$defaultFfi !== null) {
                    self::$defaultBuf = RustPipelineLibrary::obtainScratch(self::$defaultFfi, 4096, self::$defaultCap);
                }
                self::$ffiResolved = true;
            }
            $ffi = self::$defaultFfi;
        }

        if ($ffi !== null) {
            try {
                $len = strlen($textstring);
                if ($this->libraryPath === null && self::$defaultBuf !== null && $len + 64 <= self::$defaultCap) {
                    $buf = self::$defaultBuf;
                    $cap = self::$defaultCap;
                } else {
                    $cap = 0;
                    $buf = RustPipelineLibrary::obtainScratch($ffi, $len + 64, $cap);
                }
                if ($buf !== null) {
                    // @phpstan-ignore method.notFound
                    $written = $ffi->nntmux_name_check_tv($textstring, $len, $buf, $cap);
                    if ($written === 0) {
                        return null;
                    }
                    if ($written > 0) {
                        [$patternName, $matched] = explode("\0", FFI::string($buf, $written), 2);

                        return $this->fromNativeMatch($patternName, $matched);
                    }
                }
            } catch (\Throwable) {
                // Unavailable symbols or allocation errors retain the PHP path.
            }
        }

        return $this->checkPhp($release, $textstring);
    }

    /** Build the same result for individual and fused native matches. */
    public function fromNativeMatch(string $patternName, string $matched): NameFixResult
    {
        return NameFixResult::fromMatch(
            newName: $matched,
            method: $this->formatMethod($patternName),
            checkerName: $this->getName(),
            confidence: in_array($patternName, ['STREAMING_4K_HDR', 'STREAMING_HD'], true) ? 0.95 : (in_array($patternName, ['DAILY_SHOW', 'SPORTS', 'COMPLETE_SEASON', 'ANIME'], true) ? 0.80 : 0.85),
        );
    }

    /**
     * Original PCRE implementation, also used when Rust declines an input.
     */
    public function checkPhp(object $release, string $textstring): ?NameFixResult
    {
        // Try streaming patterns first (most specific)
        foreach (TvPatterns::getStreamingPatterns() as $patternName => $pattern) {
            if (preg_match($pattern, $textstring, $matches)) {
                return NameFixResult::fromMatch(
                    newName: $matches[0],
                    method: $this->formatMethod($patternName),
                    checkerName: $this->getName(),
                    confidence: 0.95,
                );
            }
        }

        // Then try standard patterns
        foreach (TvPatterns::getStandardPatterns() as $patternName => $pattern) {
            if (preg_match($pattern, $textstring, $matches)) {
                return NameFixResult::fromMatch(
                    newName: $matches[0],
                    method: $this->formatMethod($patternName),
                    checkerName: $this->getName(),
                    confidence: 0.85,
                );
            }
        }

        // Finally try special format patterns
        $specialPatterns = [
            'DAILY_SHOW' => TvPatterns::DAILY_SHOW,
            'SPORTS' => TvPatterns::SPORTS,
            'COMPLETE_SEASON' => TvPatterns::COMPLETE_SEASON,
            'ANIME' => TvPatterns::ANIME,
        ];

        foreach ($specialPatterns as $patternName => $pattern) {
            if (preg_match($pattern, $textstring, $matches)) {
                return NameFixResult::fromMatch(
                    newName: $matches[0],
                    method: $this->formatMethod($patternName),
                    checkerName: $this->getName(),
                    confidence: 0.80,
                );
            }
        }

        return null;
    }

    /** Wave 57.3 paired benchmarks decide this default; see RustNameFixingCheckersAbstractAppGameMovieTvW57ParityTest. */
    public const bool W57_RUST_FFI_DEFAULT = true;

    public const string W57_TV_CHECKER_FFI_CDEF = <<<'C'
        ssize_t nntmux_w57_tv_name_checker_pattern_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Token flags of w57TvPatternBatch(). */
    public const array W57_TV_FLAGS = [
        'hdtv' => 1, 'web' => 2, 'webrip' => 2, 'webdl' => 2, 'bluray' => 4, 'bdrip' => 4, 'brrip' => 4,
        '480p' => 8, '720p' => 8, '1080p' => 8, '2160p' => 8, 'proper' => 16, 'repack' => 16, 'complete' => 32,
        'amzn' => 64, 'nf' => 64, 'dsnp' => 64, 'hmax' => 64, 'atvp' => 64, 'hulu' => 64, 'pcok' => 64, 'max' => 64,
    ];

    /** Trailer words after the per-name words of w57TvPatternBatch(). */
    public const int W57_TV_PATTERN_TRAILER = 8;

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

    /**
     * Wave 57.3 TV name patterns. Per name, with tokens the lowercase maximal ASCII alphanumeric runs:
     * `kind | season << 3 | episode << 13 | flags << 23 | airdate << 30 | min(tokens, 255) << 55`. Kind is
     * that of the first episode token: 1 `s\d{1,3}e\d{1,3}`, 2 `\d{1,2}x\d{2}` or 3 a `(?:19|20)\d\d`
     * token followed by a 01-12 and a 01-31 two-digit token (airdate YYYYMMDD); without one, 4 for the
     * first `s\d{1,3}` season pack token before it, else 0. Flags are the W57_TV_FLAGS bits (1 HDTV, 2 WEB,
     * 4 BluRay, 8 resolution, 16 proper, 32 complete, 64 streaming service). Then the five kind counts,
     * the max season and episode and the streaming-service count.
     *
     * @param  array<array-key, string>  $names
     * @return list<int>
     */
    public static function w57TvPatternBatch(array $names): array
    {
        return self::usesW57RustFfi() ? self::w57TvPatternBatchNative($names) : self::w57TvPatternBatchPhp($names);
    }

    /**
     * @param  array<array-key, string>  $names
     * @return list<int>
     */
    public static function w57TvPatternBatchNative(array $names): array
    {
        return self::w57Invoke($names, \count($names) + self::W57_TV_PATTERN_TRAILER) ?? self::w57TvPatternBatchPhp($names);
    }

    /**
     * @param  array<array-key, string>  $names
     * @return list<int>
     */
    public static function w57TvPatternBatchPhp(array $names): array
    {
        $out = [];
        $totals = [0, 0, 0, 0, 0, 0, 0, 0];
        foreach ($names as $name) {
            preg_match_all('/[a-z0-9]+/', strtolower((string) $name), $matches);
            $tokens = $matches[0];
            $kind = 0;
            $season = 0;
            $episode = 0;
            $airdate = 0;
            $flags = 0;
            $pack = null;
            foreach ($tokens as $k => $token) {
                if ($kind === 0) {
                    if (preg_match('/^s(\d{1,3})e(\d{1,3})$/D', $token, $m) === 1) {
                        [$kind, $season, $episode] = [1, (int) $m[1], (int) $m[2]];
                    } elseif (preg_match('/^(\d{1,2})x(\d\d)$/D', $token, $m) === 1) {
                        [$kind, $season, $episode] = [2, (int) $m[1], (int) $m[2]];
                    } elseif (isset($tokens[$k + 2]) && preg_match('/^(?:19|20)\d\d$/D', $token) === 1) {
                        $month = $tokens[$k + 1];
                        $day = $tokens[$k + 2];
                        if (\strlen($month) === 2 && ctype_digit($month) && (int) $month >= 1 && (int) $month <= 12
                            && \strlen($day) === 2 && ctype_digit($day) && (int) $day >= 1 && (int) $day <= 31) {
                            [$kind, $airdate] = [3, (int) $token * 10000 + (int) $month * 100 + (int) $day];
                        }
                    }
                    if ($kind === 0 && $pack === null && preg_match('/^s(\d{1,3})$/D', $token, $m) === 1) {
                        $pack = (int) $m[1];
                    }
                }
                $flags |= self::W57_TV_FLAGS[$token] ?? 0;
            }
            if ($kind === 0 && $pack !== null) {
                [$kind, $season] = [4, $pack];
            }
            $totals[$kind]++;
            $totals[5] = max($totals[5], $season);
            $totals[6] = max($totals[6], $episode);
            $totals[7] += ($flags & 64) !== 0 ? 1 : 0;
            $out[] = $kind | $season << 3 | $episode << 13 | $flags << 23 | $airdate << 30 | min(\count($tokens), 255) << 55;
        }
        array_push($out, ...$totals);

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
            $ffi ??= RustPipelineLibrary::resolveDomain('name_checkers_w57', self::W57_TV_CHECKER_FFI_CDEF) ?? false;

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
            // @phpstan-ignore method.notFound (Declared by W57_TV_CHECKER_FFI_CDEF.)
            $written = $ffi->nntmux_w57_tv_name_checker_pattern_batch($blob, \strlen($blob), $lens, $count, $out, $words);
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
