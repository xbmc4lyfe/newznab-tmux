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
}
