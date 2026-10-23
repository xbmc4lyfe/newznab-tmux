<?php

declare(strict_types=1);

namespace App\Services\NameFixing\Checkers;

use App\Services\NameFixing\Data\NameFixResult;
use App\Services\NameFixing\Patterns\GamePatterns;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;

/**
 * Name checker for Game releases.
 *
 * Checks release names against game-specific patterns including modern consoles,
 * PC games with scene groups, DLC/updates, and repacks.
 */
class GameNameChecker extends AbstractNameChecker
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

    protected int $priority = 30;

    protected string $name = 'Game';

    /**
     * {@inheritdoc}
     */
    protected function getPatterns(): array
    {
        return GamePatterns::getAllPatterns();
    }

    /**
     * {@inheritdoc}
     */
    protected function formatMethod(string $patternName): string
    {
        $methodMap = [
            'MODERN_CONSOLE' => 'Modern console release',
            'REGION_CONSOLE' => 'Videogames with region',
            'CONSOLE_SCENE_GROUP' => 'Console with scene group',
            'PC_SCENE_GROUP' => 'PC game with scene group',
            'DLC_UPDATE' => 'DLC/Update',
            'OUTLAWS' => 'PC Games -OUTLAWS',
            'ALIAS' => 'PC Games -ALiAS',
            'GOG' => 'GOG release',
            'REPACK' => 'Game REPACK',
        ];

        return $methodMap[$patternName] ?? $this->patternNameToMethod($patternName);
    }

    /**
     * {@inheritdoc}
     *
     * Override to handle special cases like OUTLAWS group naming.
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
                    $written = $ffi->nntmux_name_check_game($textstring, $len, $buf, $cap);
                    if ($written === 0) {
                        return null;
                    }
                    if ($written > 0) {
                        [$patternName, $matched] = explode("\0", FFI::string($buf, $written), 2);
                        if ($patternName === 'OUTLAWS') {
                            $matched = str_replace('OUTLAWS', 'PC GAME OUTLAWS', $matched);
                        } elseif ($patternName === 'ALIAS') {
                            $matched = str_replace('-ALiAS', ' PC GAME ALiAS', $matched);
                        }

                        return NameFixResult::fromMatch(
                            newName: $matched,
                            method: $this->formatMethod($patternName),
                            checkerName: $this->getName(),
                            confidence: in_array($patternName, ['MODERN_CONSOLE', 'REGION_CONSOLE', 'CONSOLE_SCENE_GROUP'], true) ? 0.90 : 0.85,
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
        // Try console patterns first
        foreach (GamePatterns::getConsolePatterns() as $patternName => $pattern) {
            if (preg_match($pattern, $textstring, $matches)) {
                return NameFixResult::fromMatch(
                    newName: $matches[0],
                    method: $this->formatMethod($patternName),
                    checkerName: $this->getName(),
                    confidence: 0.90,
                );
            }
        }

        // Then try PC patterns with special handling
        foreach (GamePatterns::getPCPatterns() as $patternName => $pattern) {
            if (preg_match($pattern, $textstring, $matches)) {
                $newName = $matches[0];

                // Handle OUTLAWS group (add PC GAME tag)
                if ($patternName === 'OUTLAWS') {
                    $newName = str_replace('OUTLAWS', 'PC GAME OUTLAWS', $newName);
                }

                // Handle ALiAS group (add PC GAME tag)
                if ($patternName === 'ALIAS') {
                    $newName = str_replace('-ALiAS', ' PC GAME ALiAS', $newName);
                }

                return NameFixResult::fromMatch(
                    newName: $newName,
                    method: $this->formatMethod($patternName),
                    checkerName: $this->getName(),
                    confidence: 0.85,
                );
            }
        }

        return null;
    }
}
