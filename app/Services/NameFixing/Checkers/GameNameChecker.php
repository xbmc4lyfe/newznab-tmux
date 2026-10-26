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

    /** Wave 57.3 paired benchmarks decide this default; see RustNameFixingCheckersAbstractAppGameMovieTvW57ParityTest. */
    public const bool W57_RUST_FFI_DEFAULT = true;

    public const string W57_GAME_CHECKER_FFI_CDEF = <<<'C'
        ssize_t nntmux_w57_game_name_checker_pattern_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Console platform tokens of w57GamePatternBatch() with their word index. */
    public const array W57_GAME_PLATFORMS = [
        'ps3' => 1, 'ps4' => 2, 'ps5' => 3, 'xbox' => 4, 'xbox360' => 5, 'xboxone' => 6, 'x360' => 7, 'nsw' => 8,
        'switch' => 9, 'wii' => 10, 'wiiu' => 11, '3ds' => 12, 'nds' => 13, 'psp' => 14, 'psvita' => 15, 'vita' => 16,
    ];

    /** Scene group suffixes of w57GamePatternBatch() with their word index. */
    public const array W57_GAME_GROUPS = [
        'codex' => 1, 'plaza' => 2, 'skidrow' => 3, 'reloaded' => 4, 'cpy' => 5, 'empress' => 6, 'flt' => 7, 'tenoke' => 8,
        'rune' => 9, 'razor1911' => 10, 'hoodlum' => 11, 'prophet' => 12, 'darksiders' => 13, 'dodi' => 14, 'fitgirl' => 15,
        'elamigos' => 16, 'outlaws' => 17, 'alias' => 18, 'venom' => 19, 'bigblue' => 20, 'ali213' => 21, 'goldberg' => 22,
        'chronos' => 23, 'doge' => 24,
    ];

    /** Token flags of w57GamePatternBatch(). */
    public const array W57_GAME_TOKENS = [
        'pc' => 2, 'gog' => 2, 'steam' => 2, 'dlc' => 4, 'update' => 8, 'repack' => 16,
        'pal' => 64, 'ntsc' => 64, 'usa' => 64, 'eur' => 64, 'jpn' => 64, 'ntscu' => 64, 'ntscj' => 64,
        'proper' => 128, 'crackfix' => 128, 'hotfix' => 128, 'fix' => 128, 'build' => 512,
    ];

    /** Trailer words after the per-name words of w57GamePatternBatch(). */
    public const int W57_GAME_PATTERN_TRAILER = 9;

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
     * Wave 57.3 Game/Console name patterns. Per name, with tokens the lowercase maximal ASCII
     * alphanumeric runs: `flags | class << 12 | group << 16 | platform << 24 | min(tokens, 255) << 32`.
     * Flags: 1 a W57_GAME_PLATFORMS token (platform its value for the first one), the W57_GAME_TOKENS
     * bits of other tokens (2 PC, 4 DLC, 8 update, 16 repack, 64 region, 128 fix, 512 build), 256 a
     * `v\d+` token and 32 a W57_GAME_GROUPS last token right after '-' (group its value). Class: 1
     * console scene, 2 console, 3 PC scene, 4 DLC/update, 5 repack, else 0. Then the six class counts,
     * the DLC and update counts and the distinct groups.
     *
     * @param  array<array-key, string>  $names
     * @return list<int>
     */
    public static function w57GamePatternBatch(array $names): array
    {
        return self::usesW57RustFfi() ? self::w57GamePatternBatchNative($names) : self::w57GamePatternBatchPhp($names);
    }

    /**
     * @param  array<array-key, string>  $names
     * @return list<int>
     */
    public static function w57GamePatternBatchNative(array $names): array
    {
        return self::w57Invoke($names, \count($names) + self::W57_GAME_PATTERN_TRAILER) ?? self::w57GamePatternBatchPhp($names);
    }

    /**
     * @param  array<array-key, string>  $names
     * @return list<int>
     */
    public static function w57GamePatternBatchPhp(array $names): array
    {
        $out = [];
        $classes = [0, 0, 0, 0, 0, 0];
        $dlc = 0;
        $update = 0;
        $groups = [];
        foreach ($names as $name) {
            $lower = strtolower((string) $name);
            preg_match_all('/[a-z0-9]+/', $lower, $matches);
            $flags = 0;
            $platform = 0;
            foreach ($matches[0] as $token) {
                $index = self::W57_GAME_PLATFORMS[$token] ?? 0;
                if ($index > 0) {
                    $flags |= 1;
                    $platform = $platform === 0 ? $index : $platform;
                } else {
                    $flags |= self::W57_GAME_TOKENS[$token] ?? 0;
                }
                if ($token[0] === 'v' && \strlen($token) > 1 && ctype_digit(substr($token, 1))) {
                    $flags |= 256;
                }
            }
            $group = 0;
            if (preg_match('/-([a-z0-9]+)[^a-z0-9]*$/D', $lower, $suffix) === 1) {
                $group = self::W57_GAME_GROUPS[$suffix[1]] ?? 0;
                if ($group > 0) {
                    $flags |= 32;
                    $groups[$group] = true;
                }
            }
            $console = ($flags & 1) !== 0;
            $scene = ($flags & 32) !== 0;
            $class = match (true) {
                $console && $scene => 1,
                $console => 2,
                $scene => 3,
                ($flags & (4 | 8)) !== 0 => 4,
                ($flags & 16) !== 0 => 5,
                default => 0,
            };
            $classes[$class]++;
            $dlc += ($flags & 4) !== 0 ? 1 : 0;
            $update += ($flags & 8) !== 0 ? 1 : 0;
            $out[] = $flags | $class << 12 | $group << 16 | $platform << 24 | min(\count($matches[0]), 255) << 32;
        }
        array_push($out, ...$classes);
        array_push($out, $dlc, $update, \count($groups));

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
            $ffi ??= RustPipelineLibrary::resolveDomain('name_checkers_w57', self::W57_GAME_CHECKER_FFI_CDEF) ?? false;

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
            // @phpstan-ignore method.notFound (Declared by W57_GAME_CHECKER_FFI_CDEF.)
            $written = $ffi->nntmux_w57_game_name_checker_pattern_batch($blob, \strlen($blob), $lens, $count, $out, $words);
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
