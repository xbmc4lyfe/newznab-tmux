<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Support\RustPipelineLibrary;
use FFI;

/**
 * Result of a behavioural proxy-fetch analysis.
 *
 * @property array<string, int> $reasons Per-signal score contributions.
 */
final readonly class ProxyVerdict
{
    /**
     * Rust candidates for `allow()` and the `fromScore()` block decision. They stay
     * opt-in: neither beat PHP by 1.5x in the paired benchmark
     * (tests/Unit/RustSearchPageProxySortDropdownParityTest.php).
     */
    public const string FFI_CDEF = <<<'C'
        intptr_t nntmux_proxy_verdict_allow(int32_t *out_block, int64_t *out_score);
        int32_t nntmux_proxy_verdict_should_block(int64_t score, int64_t threshold);
        C;

    /**
     * @param  array<string, int>  $reasons
     */
    public function __construct(
        public bool $shouldBlock,
        public int $score,
        public array $reasons,
    ) {}

    private static function rustEnabled(?bool $enabled = null): bool
    {
        // Readonly classes cannot declare static properties.
        static $useRustFfi = false;
        if ($enabled !== null) {
            $useRustFfi = $enabled;
        }

        return $useRustFfi;
    }

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::rustEnabled($useRustFfi);
    }

    /**
     * Cached search-domain FFI handle; `false` records a missing library.
     */
    private static function ffiState(bool $assign = false, FFI|false|null $ffi = null): FFI|false|null
    {
        static $state = null;
        if ($assign) {
            $state = $ffi;
        }

        return $state;
    }

    public static function setSearchFfi(FFI|false|null $ffi): void
    {
        self::ffiState(true, $ffi);
    }

    public static function searchFfi(): ?FFI
    {
        if (self::ffiState() === null) {
            self::ffiState(true, RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false);
        }
        $ffi = self::ffiState();

        return $ffi instanceof FFI ? $ffi : null;
    }

    /**
     * A verdict that never blocks (used when detection is disabled).
     */
    public static function allow(): self
    {
        return self::rustEnabled() ? self::allowNative() : self::allowPhp();
    }

    public static function allowPhp(): self
    {
        return new self(false, 0, []);
    }

    public static function allowNative(): self
    {
        $ffi = self::searchFfi();
        if ($ffi === null) {
            return self::allowPhp();
        }

        $block = $ffi->new('int32_t[1]');
        $score = $ffi->new('int64_t[1]');
        // @phpstan-ignore method.notFound
        $reasons = $ffi->nntmux_proxy_verdict_allow($block, $score);

        return $reasons === 0 ? new self($block[0] === 1, (int) $score[0], []) : self::allowPhp();
    }

    /**
     * Normalize a scored analysis into a verdict: block once the score reaches the
     * threshold.
     *
     * @param  array<string, int>  $reasons
     */
    public static function fromScore(int $score, int $threshold, array $reasons): self
    {
        return self::rustEnabled()
            ? self::fromScoreNative($score, $threshold, $reasons)
            : self::fromScorePhp($score, $threshold, $reasons);
    }

    /**
     * @param  array<string, int>  $reasons
     */
    public static function fromScorePhp(int $score, int $threshold, array $reasons): self
    {
        return new self($score >= $threshold, $score, $reasons);
    }

    /**
     * @param  array<string, int>  $reasons
     */
    public static function fromScoreNative(int $score, int $threshold, array $reasons): self
    {
        $ffi = self::searchFfi();
        if ($ffi === null) {
            return self::fromScorePhp($score, $threshold, $reasons);
        }

        // @phpstan-ignore method.notFound
        $block = $ffi->nntmux_proxy_verdict_should_block($score, $threshold);

        return $block === 0 || $block === 1
            ? new self($block === 1, $score, $reasons)
            : self::fromScorePhp($score, $threshold, $reasons);
    }
}
