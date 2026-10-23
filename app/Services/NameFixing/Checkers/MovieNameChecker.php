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

                        return NameFixResult::fromMatch(
                            newName: $matched,
                            method: $this->formatMethod($patternName),
                            checkerName: $this->getName(),
                            confidence: in_array($patternName, ['UHD_HDR', 'UHD_REMUX', 'STREAMING_4K'], true) ? 0.95 : ($patternName === 'GENERIC' ? 0.70 : 0.85),
                        );
                    }
                }
            } catch (\Throwable) {
                // Unavailable symbols or allocation errors retain the PHP path.
            }
        }

        return $this->checkPhp($release, $textstring);
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
}
