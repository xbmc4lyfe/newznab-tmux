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
            confidence: match ($patternName) {
                'WITH_KEYGEN' => 0.90, 'ADOBE', 'MICROSOFT' => 0.85, 'WITH_VERSION' => 0.70, default => 0.80
            },
        );
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

    /** Wave 57.3 paired benchmarks decide this default; see RustNameFixingCheckersAbstractAppGameMovieTvW57ParityTest. */
    public const bool W57_RUST_FFI_DEFAULT = true;

    public const string W57_APP_CHECKER_FFI_CDEF = <<<'C'
        ssize_t nntmux_w57_app_name_checker_pattern_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Token flags of w57AppPatternBatch(). */
    public const array W57_APP_TOKENS = [
        'x64' => 2, 'amd64' => 2, '64bit' => 2, 'win64' => 2 | 64, 'x86' => 4, '32bit' => 4, 'i386' => 4, 'win32' => 4 | 64,
        'multilingual' => 8, 'multilang' => 8, 'multi' => 8,
        'keygen' => 16, 'crack' => 16, 'cracked' => 16, 'patch' => 16, 'serial' => 16, 'keymaker' => 16,
        'windows' => 64, 'win' => 64, 'win10' => 64, 'win11' => 64, 'winall' => 64,
        'macos' => 128, 'macosx' => 128, 'osx' => 128, 'mac' => 128,
        'linux' => 256, 'ubuntu' => 256, 'debian' => 256,
        'adobe' => 512, 'microsoft' => 512, 'autodesk' => 512, 'corel' => 512, 'jetbrains' => 512,
        'portable' => 1024, 'retail' => 1024,
    ];

    /** Trailer words after the per-name words of w57AppPatternBatch(). */
    public const int W57_APP_PATTERN_TRAILER = 10;

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
     * Wave 57.3 App/PC name patterns. Per name, with tokens the lowercase maximal ASCII alphanumeric
     * runs: `flags | class << 12 | parts << 16 | major << 20 | min(tokens, 255) << 34`. Flags: 1 a
     * `(?<![A-Za-z0-9])[vV]\d+(?:\.\d+)+` version (parts its dotted digit groups, at most 15, and major
     * its first group, 9999 past four digits), then the W57_APP_TOKENS bits (2 x64, 4 x86, 8 multilingual,
     * 16 keygen, 64 Windows, 128 macOS, 256 Linux, 512 vendor, 1024 portable/retail) and 32 for a keygen
     * token right after `incl`. Class: 1 keygen, 2 vendor, 3 Windows, 4 macOS, 5 Linux, 6 version, else 0.
     * Then the seven class counts, the version and x64/x86 counts and the max major version.
     *
     * @param  array<array-key, string>  $names
     * @return list<int>
     */
    public static function w57AppPatternBatch(array $names): array
    {
        return self::usesW57RustFfi() ? self::w57AppPatternBatchNative($names) : self::w57AppPatternBatchPhp($names);
    }

    /**
     * @param  array<array-key, string>  $names
     * @return list<int>
     */
    public static function w57AppPatternBatchNative(array $names): array
    {
        return self::w57Invoke($names, \count($names) + self::W57_APP_PATTERN_TRAILER) ?? self::w57AppPatternBatchPhp($names);
    }

    /**
     * @param  array<array-key, string>  $names
     * @return list<int>
     */
    public static function w57AppPatternBatchPhp(array $names): array
    {
        $out = [];
        $classes = [0, 0, 0, 0, 0, 0, 0];
        $versions = 0;
        $arch = 0;
        $maxMajor = 0;
        foreach ($names as $name) {
            $name = (string) $name;
            preg_match_all('/[a-z0-9]+/', strtolower($name), $matches);
            $flags = 0;
            $afterIncl = false;
            foreach ($matches[0] as $token) {
                $bits = self::W57_APP_TOKENS[$token] ?? 0;
                if ($afterIncl && ($bits & 16) !== 0) {
                    $flags |= 32;
                }
                $flags |= $bits;
                $afterIncl = $token === 'incl';
            }
            $parts = 0;
            $major = 0;
            if (preg_match('/(?<![A-Za-z0-9])[vV](\d+)((?:\.\d+)+)/', $name, $version) === 1) {
                $flags |= 1;
                $parts = min(15, 1 + substr_count($version[2], '.'));
                $major = \strlen($version[1]) > 4 ? 9999 : (int) $version[1];
                $versions++;
                $maxMajor = max($maxMajor, $major);
            }
            $class = match (true) {
                ($flags & (16 | 32)) !== 0 => 1,
                ($flags & 512) !== 0 => 2,
                ($flags & 64) !== 0 => 3,
                ($flags & 128) !== 0 => 4,
                ($flags & 256) !== 0 => 5,
                ($flags & 1) !== 0 => 6,
                default => 0,
            };
            $classes[$class]++;
            $arch += ($flags & 6) !== 0 ? 1 : 0;
            $out[] = $flags | $class << 12 | $parts << 16 | $major << 20 | min(\count($matches[0]), 255) << 34;
        }
        array_push($out, ...$classes);
        array_push($out, $versions, $arch, $maxMajor);

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
            $ffi ??= RustPipelineLibrary::resolveDomain('name_checkers_w57', self::W57_APP_CHECKER_FFI_CDEF) ?? false;

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
            // @phpstan-ignore method.notFound (Declared by W57_APP_CHECKER_FFI_CDEF.)
            $written = $ffi->nntmux_w57_app_name_checker_pattern_batch($blob, \strlen($blob), $lens, $count, $out, $words);
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
