<?php

declare(strict_types=1);

namespace App\Services\NameFixing\Checkers;

use App\Services\NameFixing\Data\NameFixResult;
use App\Services\NameFixing\Patterns\AppPatterns;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;

/**
 * Name checker for Application/Software releases.
 *
 * Checks release names against software-specific patterns including
 * platform-specific releases, vendor software, and generic patterns.
 */
class AppNameChecker extends AbstractNameChecker
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

    protected int $priority = 40;

    protected string $name = 'App';

    /**
     * {@inheritdoc}
     */
    protected function getPatterns(): array
    {
        return AppPatterns::getAllPatterns();
    }

    /**
     * {@inheritdoc}
     */
    protected function formatMethod(string $patternName): string
    {
        $methodMap = [
            'WITH_KEYGEN' => 'Apps with keygen/patch',
            'WINDOWS' => 'Windows apps',
            'MACOS' => 'macOS apps',
            'LINUX' => 'Linux apps',
            'ADOBE' => 'Adobe apps',
            'MICROSOFT' => 'Microsoft apps',
            'WITH_VERSION' => 'Software with version',
        ];

        return $methodMap[$patternName] ?? $this->patternNameToMethod($patternName);
    }

    /**
     * {@inheritdoc}
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
                    $written = $ffi->nntmux_name_check_app($textstring, $len, $buf, $cap);
                    if ($written === 0) {
                        return null;
                    }
                    if ($written > 0) {
                        [$patternName, $matched] = explode("\0", FFI::string($buf, $written), 2);

                        return NameFixResult::fromMatch(
                            newName: $matched,
                            method: $this->formatMethod($patternName),
                            checkerName: $this->getName(),
                            confidence: match ($patternName) {
                                'WITH_KEYGEN' => 0.90, 'ADOBE', 'MICROSOFT' => 0.85, 'WITH_VERSION' => 0.70, default => 0.80
                            },
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
        // Try keygen/crack patterns first (most definitive for software)
        if (preg_match(AppPatterns::WITH_KEYGEN, $textstring, $matches)) {
            return NameFixResult::fromMatch(
                newName: $matches[0],
                method: $this->formatMethod('WITH_KEYGEN'),
                checkerName: $this->getName(),
                confidence: 0.90,
            );
        }

        // Try vendor-specific patterns
        foreach (AppPatterns::getVendorPatterns() as $patternName => $pattern) {
            if (preg_match($pattern, $textstring, $matches)) {
                return NameFixResult::fromMatch(
                    newName: $matches[0],
                    method: $this->formatMethod($patternName),
                    checkerName: $this->getName(),
                    confidence: 0.85,
                );
            }
        }

        // Try platform-specific patterns
        foreach (AppPatterns::getPlatformPatterns() as $patternName => $pattern) {
            if (preg_match($pattern, $textstring, $matches)) {
                return NameFixResult::fromMatch(
                    newName: $matches[0],
                    method: $this->formatMethod($patternName),
                    checkerName: $this->getName(),
                    confidence: 0.80,
                );
            }
        }

        // Finally try generic version pattern
        if (preg_match(AppPatterns::WITH_VERSION, $textstring, $matches)) {
            return NameFixResult::fromMatch(
                newName: $matches[0],
                method: $this->formatMethod('WITH_VERSION'),
                checkerName: $this->getName(),
                confidence: 0.70,
            );
        }

        return null;
    }
}
