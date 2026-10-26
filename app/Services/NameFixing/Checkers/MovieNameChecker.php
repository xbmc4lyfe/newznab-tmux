<?php

declare(strict_types=1);

namespace App\Services\NameFixing\Checkers;

use App\Services\NameFixing\Data\NameFixResult;
use App\Services\NameFixing\Patterns\MoviePatterns;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;

/**
 * Name checker for Movie releases.
 *
 * Checks release names against movie-specific patterns including 4K/UHD,
 * streaming services, and standard HD releases.
 */
class MovieNameChecker extends AbstractNameChecker
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

    protected int $priority = 20;

    protected string $name = 'Movie';

    /**
     * {@inheritdoc}
     */
    protected function getPatterns(): array
    {
        return MoviePatterns::getAllPatterns();
    }

    /**
     * {@inheritdoc}
     */
    protected function formatMethod(string $patternName): string
    {
        $methodMap = [
            'UHD_HDR' => '4K/UHD with HDR',
            'UHD_REMUX' => '4K REMUX',
            'STREAMING_4K' => '4K Streaming service',
            'STREAMING_HD' => 'HD Streaming service',
            'YEAR_RES_VCODEC' => 'Title.year.Text.res.vcod.group',
            'YEAR_SOURCE_VCODEC_RES' => 'Title.year.source.vcodec.res.group',
            'YEAR_SOURCE_VCODEC_ACODEC' => 'Title.year.source.vcodec.acodec.group',
            'RES_SOURCE_ACODEC_VCODEC' => 'Title.year.resolution.source.acodec.vcodec.group',
            'RES_ACODEC_SOURCE_YEAR' => 'Title.resolution.acodec.eptitle.source.year.group',
            'MULTI_LANGUAGE' => 'Title.language.year.acodec.src',
            'GENERIC' => 'Title.year.res.source.group',
        ];

        return $methodMap[$patternName] ?? $this->patternNameToMethod($patternName);
    }

    /**
     * {@inheritdoc}
     *
     * Override to provide more specific movie checking with pattern priority.
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
                    $written = $ffi->nntmux_name_check_movie($textstring, $len, $buf, $cap);
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
            confidence: in_array($patternName, ['UHD_HDR', 'UHD_REMUX', 'STREAMING_4K'], true) ? 0.95 : ($patternName === 'GENERIC' ? 0.70 : 0.85),
        );
    }

    /**
     * Original PCRE implementation, also used when Rust declines an input.
     */
    public function checkPhp(object $release, string $textstring): ?NameFixResult
    {
        // Try 4K/UHD patterns first (most specific and modern)
        foreach (MoviePatterns::get4KPatterns() as $patternName => $pattern) {
            if (preg_match($pattern, $textstring, $matches)) {
                return NameFixResult::fromMatch(
                    newName: $matches[0],
                    method: $this->formatMethod($patternName),
                    checkerName: $this->getName(),
                    confidence: 0.95,
                );
            }
        }

        // Then try standard patterns in order of specificity
        $standardPatterns = [
            'STREAMING_HD' => MoviePatterns::STREAMING_HD,
            'YEAR_RES_VCODEC' => MoviePatterns::YEAR_RES_VCODEC,
            'YEAR_SOURCE_VCODEC_RES' => MoviePatterns::YEAR_SOURCE_VCODEC_RES,
            'YEAR_SOURCE_VCODEC_ACODEC' => MoviePatterns::YEAR_SOURCE_VCODEC_ACODEC,
            'RES_SOURCE_ACODEC_VCODEC' => MoviePatterns::RES_SOURCE_ACODEC_VCODEC,
            'RES_ACODEC_SOURCE_YEAR' => MoviePatterns::RES_ACODEC_SOURCE_YEAR,
            'MULTI_LANGUAGE' => MoviePatterns::MULTI_LANGUAGE,
            'GENERIC' => MoviePatterns::GENERIC,
        ];

        foreach ($standardPatterns as $patternName => $pattern) {
            if (preg_match($pattern, $textstring, $matches)) {
                $confidence = $patternName === 'GENERIC' ? 0.70 : 0.85;

                return NameFixResult::fromMatch(
                    newName: $matches[0],
                    method: $this->formatMethod($patternName),
                    checkerName: $this->getName(),
                    confidence: $confidence,
                );
            }
        }

        return null;
    }

    /** Wave 57.3 paired benchmarks decide this default; see RustNameFixingCheckersAbstractAppGameMovieTvW57ParityTest. */
    public const bool W57_RUST_FFI_DEFAULT = true;

    public const string W57_MOVIE_CHECKER_FFI_CDEF = <<<'C'
        ssize_t nntmux_w57_movie_name_checker_pattern_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Resolution tokens of w57MoviePatternBatch(). */
    public const array W57_MOVIE_RESOLUTIONS = ['480p' => 1, '576p' => 2, '720p' => 3, '1080p' => 4, '1080i' => 5, '2160p' => 6, '4k' => 7, 'uhd' => 7];

    /** Source tokens of w57MoviePatternBatch(); `web` depends on the next token. */
    public const array W57_MOVIE_SOURCES = [
        'bluray' => 1, 'bdrip' => 1, 'brrip' => 1, 'bdremux' => 1, 'webdl' => 2, 'webrip' => 3, 'hdtv' => 5, 'dvdrip' => 6, 'dvd' => 6, 'dvdr' => 6,
    ];

    /** Codec tokens of w57MoviePatternBatch(); `h` depends on the next token. */
    public const array W57_MOVIE_CODECS = ['x264' => 1, 'h264' => 2, 'avc' => 2, 'x265' => 3, 'h265' => 4, 'hevc' => 4, 'xvid' => 5, 'divx' => 5, 'av1' => 6, 'vp9' => 7];

    /** Token flags of w57MoviePatternBatch(). */
    public const array W57_MOVIE_FLAGS = [
        'dts' => 1, 'ac3' => 1, 'aac' => 1, 'ddp' => 1, 'ddp5' => 1, 'dd5' => 1, 'atmos' => 1, 'truehd' => 1, 'flac' => 1,
        'hdr' => 2, 'hdr10' => 2, 'dv' => 2, 'dolbyvision' => 2, 'hdr10plus' => 2, 'remux' => 4, 'bdremux' => 4,
        'proper' => 8, 'repack' => 8, 'multi' => 16, 'dual' => 16, 'multisubs' => 16, '3d' => 32, 'sbs' => 32, 'hsbs' => 32,
    ];

    /** Trailer words after the per-name words of w57MoviePatternBatch(). */
    public const int W57_MOVIE_PATTERN_TRAILER = 8;

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
     * Wave 57.3 Movie name patterns. Per name, with tokens the lowercase maximal ASCII alphanumeric runs:
     * `year | resolution << 12 | source << 16 | codec << 20 | flags << 24 | score << 30 | matched << 33 |
     * min(tokens, 255) << 34`. Year is the first `(?:19|20)\d\d` token; resolution, source and codec are
     * the first W57_MOVIE_RESOLUTIONS, W57_MOVIE_SOURCES and W57_MOVIE_CODECS values (`web` then `dl` is
     * WEB-DL 2, then `rip` WEBRip 3, else WEB 4; `h` then `264` is 2 and then `265` is 4); flags are the
     * W57_MOVIE_FLAGS bits (1 audio, 2 HDR, 4 remux, 8 proper, 16 multi, 32 3D). Score counts the found
     * year, resolution, source and codec; a name matches with a score of 3 or a year and resolution.
     * Then the matched and year counts, the min and max year, the 720p, 1080 and UHD counts and the HDR count.
     *
     * @param  array<array-key, string>  $names
     * @return list<int>
     */
    public static function w57MoviePatternBatch(array $names): array
    {
        return self::usesW57RustFfi() ? self::w57MoviePatternBatchNative($names) : self::w57MoviePatternBatchPhp($names);
    }

    /**
     * @param  array<array-key, string>  $names
     * @return list<int>
     */
    public static function w57MoviePatternBatchNative(array $names): array
    {
        return self::w57Invoke($names, \count($names) + self::W57_MOVIE_PATTERN_TRAILER) ?? self::w57MoviePatternBatchPhp($names);
    }

    /**
     * @param  array<array-key, string>  $names
     * @return list<int>
     */
    public static function w57MoviePatternBatchPhp(array $names): array
    {
        $out = [];
        $totals = [0, 0, 0, 0, 0, 0, 0, 0];
        foreach ($names as $name) {
            preg_match_all('/[a-z0-9]+/', strtolower((string) $name), $matches);
            $tokens = $matches[0];
            $year = 0;
            $resolution = 0;
            $source = 0;
            $codec = 0;
            $flags = 0;
            foreach ($tokens as $k => $token) {
                if ($year === 0 && preg_match('/^(?:19|20)\d\d$/D', $token) === 1) {
                    $year = (int) $token;
                }
                $next = $tokens[$k + 1] ?? '';
                if ($resolution === 0) {
                    $resolution = self::W57_MOVIE_RESOLUTIONS[$token] ?? 0;
                }
                if ($source === 0) {
                    $source = $token === 'web' ? match ($next) {
                        'dl' => 2,
                        'rip' => 3,
                        default => 4,
                    } : (self::W57_MOVIE_SOURCES[$token] ?? 0);
                }
                if ($codec === 0) {
                    $codec = $token === 'h' ? match ($next) {
                        '264' => 2,
                        '265' => 4,
                        default => 0,
                    } : (self::W57_MOVIE_CODECS[$token] ?? 0);
                }
                $flags |= self::W57_MOVIE_FLAGS[$token] ?? 0;
            }
            $score = ($year > 0 ? 1 : 0) + ($resolution > 0 ? 1 : 0) + ($source > 0 ? 1 : 0) + ($codec > 0 ? 1 : 0);
            $matched = $score >= 3 || ($year > 0 && $resolution > 0);
            $totals[0] += $matched ? 1 : 0;
            if ($year > 0) {
                $totals[1]++;
                $totals[2] = $totals[2] === 0 ? $year : min($totals[2], $year);
                $totals[3] = max($totals[3], $year);
            }
            $totals[4] += $resolution === 3 ? 1 : 0;
            $totals[5] += $resolution === 4 || $resolution === 5 ? 1 : 0;
            $totals[6] += $resolution >= 6 ? 1 : 0;
            $totals[7] += ($flags & 2) !== 0 ? 1 : 0;
            $out[] = $year | $resolution << 12 | $source << 16 | $codec << 20 | $flags << 24 | $score << 30 | ($matched ? 1 : 0) << 33
                | min(\count($tokens), 255) << 34;
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
            $ffi ??= RustPipelineLibrary::resolveDomain('name_checkers_w57', self::W57_MOVIE_CHECKER_FFI_CDEF) ?? false;

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
            // @phpstan-ignore method.notFound (Declared by W57_MOVIE_CHECKER_FFI_CDEF.)
            $written = $ffi->nntmux_w57_movie_name_checker_pattern_batch($blob, \strlen($blob), $lens, $count, $out, $words);
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
