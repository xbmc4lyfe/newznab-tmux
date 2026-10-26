<?php

declare(strict_types=1);

namespace App\Services\Categorization\Pipes;

use App\Models\Category;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\Categorizers\PcCategorizer;
use App\Services\Categorization\ReleaseContext;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Pipe for PC content categorization (Games, Software, etc.).
 */
class PcPipe extends AbstractCategorizationPipe
{
    /**
     * Wave 41.3 production default is Rust: the paired benchmark in
     * tests/Unit/RustCategorizationPipelineResultMusicPcTvW41ParityTest.php measures at least 6.0x
     * (mean and p99, common and uncommon batches). PHP stays the fallback when the library is
     * unavailable.
     * Named W413 so it stays distinct from other Rust FFI toggles.
     */
    public const bool W413_RUST_FFI_DEFAULT = true;

    public const string W413_FFI_CDEF = <<<'C'
        ssize_t nntmux_w413_pc_platform_batch(
            const char *blob_ptr,
            size_t blob_len,
            const char *lens_ptr,
            size_t lens_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Platform marker substrings, in match order (Mac before Windows because Mac builds often say
     * x64). The first platform with a substring of the lowercased release name wins.
     *
     * @var array<string, list<string>>
     */
    public const array W413_PLATFORM_MARKERS = [
        'mac' => ['macos', 'osx', 'mac.os', '.dmg'],
        'linux' => ['linux', '.deb', '.rpm', 'appimage'],
        'mobile' => ['android', '.apk', 'ios.', '.ipa'],
        'windows' => ['win64', 'win32', 'windows', 'x64', 'x86'],
    ];

    /** @var list<string> */
    public const array W413_DISC_IMAGE_MARKERS = ['.iso', '.img', '.bin', '.cue'];

    /** @var list<string> */
    public const array W413_CRACK_MARKERS = ['crack', 'keygen', 'patch', 'serial'];

    /** Exactly 8 0x1F-separated decimals of 1-19 digits. */
    private const string W413_FIELDS_PATTERN = '/\A(?:\d{1,19}\x1F){7}\d{1,19}\z/';

    private static ?bool $w413RustFfiOverride = null;

    private static FFI|false|null $w413RustFfiHandle = null;

    public const string PIPE_FFI_CDEF = <<<'C'
        ssize_t nntmux_cat_pipe_cmd_pipe_name(uint8_t kind, char *out_buf, size_t out_cap);
        int64_t nntmux_cat_pipe_cmd_pipe_priority(uint8_t kind);
        int32_t nntmux_cat_pipe_cmd_evaluate_result(uint8_t kind, int64_t category_id, double confidence, const char *matched_by_ptr, size_t matched_by_len);
        int32_t nntmux_cat_pipe_cmd_decision_state(uint8_t stop_processing, uint8_t skip);
        C;

    /** The result passes `CategorizationResult::isSuccessful()`. */
    public const int RESULT_SUCCESSFUL = 1;

    /** The category id lies in the PC category family. */
    public const int RESULT_IN_DOMAIN = 2;

    /** The confidence reaches the `CategorizationPassable` stop threshold. */
    public const int RESULT_TERMINAL = 4;

    /** `handle()` passes the release on because a terminal result already exists. */
    public const int DECISION_PASS_THROUGH = 0;

    /** `handle()` passes the release on because the categorizer skips it. */
    public const int DECISION_SKIP = 1;

    /** `handle()` categorizes the release. */
    public const int DECISION_CATEGORIZE = 2;

    private const int PIPE_KIND = 2;

    private const string PIPE_NAME = 'PC';

    private const int PIPE_PRIORITY = 30;

    // Public because the parent declares it public; a private redeclaration is a fatal error.
    public static bool $useRustFfi = false;

    private static FFI|false|null $pipeFfi = null;

    protected int $priority = self::PIPE_PRIORITY;

    private PcCategorizer $categorizer;

    public function __construct()
    {
        $this->categorizer = new PcCategorizer;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function setPipeFfiForTesting(FFI|false|null $ffi): void
    {
        self::$pipeFfi = $ffi;
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
        $ffi = self::pipeFfi();
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
            $written = $ffi->nntmux_cat_pipe_cmd_pipe_name(self::PIPE_KIND, $scratch, $cap);
            if (! \is_int($written) || $written <= 0 || $written > $cap) {
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
        $ffi = self::pipeFfi();
        if ($ffi === null) {
            return self::pipePriorityPhp();
        }

        try {
            // @phpstan-ignore method.notFound
            $priority = $ffi->nntmux_cat_pipe_cmd_pipe_priority(self::PIPE_KIND);
        } catch (Throwable) {
            return self::pipePriorityPhp();
        }

        return \is_int($priority) && $priority >= 0 ? $priority : self::pipePriorityPhp();
    }

    /**
     * The `DECISION_*` state `handle()` reaches for a stop-processing flag and skip outcome.
     */
    public static function decisionState(bool $stopProcessing, bool $skip): int
    {
        return self::$useRustFfi
            ? self::decisionStateNative($stopProcessing, $skip)
            : self::decisionStatePhp($stopProcessing, $skip);
    }

    public static function decisionStatePhp(bool $stopProcessing, bool $skip): int
    {
        if ($stopProcessing) {
            return self::DECISION_PASS_THROUGH;
        }

        return $skip ? self::DECISION_SKIP : self::DECISION_CATEGORIZE;
    }

    public static function decisionStateNative(bool $stopProcessing, bool $skip): int
    {
        $ffi = self::pipeFfi();
        if ($ffi === null) {
            return self::decisionStatePhp($stopProcessing, $skip);
        }

        try {
            // @phpstan-ignore method.notFound
            $state = $ffi->nntmux_cat_pipe_cmd_decision_state($stopProcessing ? 1 : 0, $skip ? 1 : 0);
        } catch (Throwable) {
            return self::decisionStatePhp($stopProcessing, $skip);
        }

        return \is_int($state) && $state >= self::DECISION_PASS_THROUGH && $state <= self::DECISION_CATEGORIZE
            ? $state
            : self::decisionStatePhp($stopProcessing, $skip);
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
        if ($categoryId >= Category::PC_ROOT && $categoryId <= Category::PC_ROOT + 999) {
            $mask |= self::RESULT_IN_DOMAIN;
        }
        if ($confidence >= 0.95) {
            $mask |= self::RESULT_TERMINAL;
        }

        return $mask;
    }

    public static function evaluateResultNative(int $categoryId, float $confidence, string $matchedBy): int
    {
        $ffi = self::pipeFfi();
        if ($ffi === null) {
            return self::evaluateResultPhp($categoryId, $confidence, $matchedBy);
        }

        try {
            // @phpstan-ignore method.notFound
            $mask = $ffi->nntmux_cat_pipe_cmd_evaluate_result(self::PIPE_KIND, $categoryId, $confidence, $matchedBy, \strlen($matchedBy));
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

    private static function pipeFfi(): ?FFI
    {
        if (self::$pipeFfi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            try {
                self::$pipeFfi = RustPipelineLibrary::resolveDomain(
                    'w29-categorization-pipes-commands',
                    self::PIPE_FFI_CDEF,
                    \is_string($path) && $path !== '' ? $path : null
                ) ?? false;
            } catch (Throwable) {
                self::$pipeFfi = false;
            }
        }

        return self::$pipeFfi === false ? null : self::$pipeFfi;
    }

    public static function useW413RustFfi(bool $enable = true): void
    {
        self::$w413RustFfiOverride = $enable;
    }

    public static function usesW413RustFfi(): bool
    {
        return self::$w413RustFfiOverride ?? self::W413_RUST_FFI_DEFAULT;
    }

    public static function resetW413RustFfi(): void
    {
        self::$w413RustFfiOverride = null;
        self::$w413RustFfiHandle = null;
    }

    /**
     * Platform summary for a batch of PC release names. Each lowercased name adds to its first
     * W413_PLATFORM_MARKERS platform, else to unknown, and separately to disc_image and cracked when
     * it holds any W413_DISC_IMAGE_MARKERS or W413_CRACK_MARKERS substring. Non-strings count as
     * empty names.
     *
     * @param  array<array-key, mixed>  $releaseNames
     * @return array{total: int, windows: int, mac: int, linux: int, mobile: int, unknown: int, disc_image: int, cracked: int}
     */
    public static function summarizePcPlatformBatch(array $releaseNames): array
    {
        return (self::usesW413RustFfi() ? self::summarizePcPlatformBatchNative($releaseNames) : null)
            ?? self::summarizePcPlatformBatchPhp($releaseNames);
    }

    /**
     * @param  array<array-key, mixed>  $releaseNames
     * @return array{total: int, windows: int, mac: int, linux: int, mobile: int, unknown: int, disc_image: int, cracked: int}
     */
    public static function summarizePcPlatformBatchPhp(array $releaseNames): array
    {
        $platforms = ['mac' => 0, 'linux' => 0, 'mobile' => 0, 'windows' => 0];
        $unknown = 0;
        $discImage = 0;
        $cracked = 0;
        foreach ($releaseNames as $releaseName) {
            $name = strtolower(\is_string($releaseName) ? $releaseName : '');
            foreach (self::W413_DISC_IMAGE_MARKERS as $needle) {
                if (str_contains($name, $needle)) {
                    $discImage++;

                    break;
                }
            }
            foreach (self::W413_CRACK_MARKERS as $needle) {
                if (str_contains($name, $needle)) {
                    $cracked++;

                    break;
                }
            }
            foreach (self::W413_PLATFORM_MARKERS as $platform => $needles) {
                foreach ($needles as $needle) {
                    if (str_contains($name, $needle)) {
                        $platforms[$platform]++;

                        continue 3;
                    }
                }
            }
            $unknown++;
        }

        return [
            'total' => \count($releaseNames),
            'windows' => $platforms['windows'],
            'mac' => $platforms['mac'],
            'linux' => $platforms['linux'],
            'mobile' => $platforms['mobile'],
            'unknown' => $unknown,
            'disc_image' => $discImage,
            'cracked' => $cracked,
        ];
    }

    /**
     * Declines (null) for non-string items so PHP counts them.
     *
     * @param  array<array-key, mixed>  $releaseNames
     * @return array{total: int, windows: int, mac: int, linux: int, mobile: int, unknown: int, disc_image: int, cracked: int}|null
     */
    public static function summarizePcPlatformBatchNative(array $releaseNames): ?array
    {
        $lengths = [];
        foreach ($releaseNames as $item) {
            if (! \is_string($item)) {
                return null;
            }
            $lengths[] = \strlen($item);
        }
        $blob = implode('', $releaseNames);
        $lens = pack('V*', ...$lengths);
        $fields = self::callW413Native($blob, $lens);
        if ($fields === null) {
            return null;
        }

        return [
            'total' => (int) $fields[0],
            'windows' => (int) $fields[1],
            'mac' => (int) $fields[2],
            'linux' => (int) $fields[3],
            'mobile' => (int) $fields[4],
            'unknown' => (int) $fields[5],
            'disc_image' => (int) $fields[6],
            'cracked' => (int) $fields[7],
        ];
    }

    /**
     * Calls this class's Wave 41.3 export with the shared scratch buffer, retrying once at the size
     * it asks for. Returns its fields only when they match W413_FIELDS_PATTERN: exactly the expected
     * count of 1-19 digit decimals.
     *
     * @return list<string>|null
     */
    private static function callW413Native(string $blob, string $lens): ?array
    {
        $ffi = self::w413RustFfi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 128, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w413_pc_platform_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w413_pc_platform_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }
            $raw = FFI::string($buffer, $written);

            return preg_match(self::W413_FIELDS_PATTERN, $raw) === 1 ? explode("\x1F", $raw) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function w413RustFfi(): ?FFI
    {
        if (self::$w413RustFfiHandle === null) {
            self::$w413RustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w413', self::W413_FFI_CDEF) ?? false;
        }

        return self::$w413RustFfiHandle === false ? null : self::$w413RustFfiHandle;
    }

    /** Wave 54.3 paired benchmarks decide this default; see RustCategorizationPipesConsoleGroupMiscMusicPcW54ParityTest. */
    private const bool W54_RUST_FFI_DEFAULT = true;

    public const string W54_FFI_CDEF = <<<'C'
        ssize_t nntmux_w543_pc_platform_flag_batch(const char *blob, size_t blob_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Per uppercased token: `flags | platform << 16` (platform 1 Windows, 2 macOS, 3 Linux, 4 mobile); 32 crack, 64 installer or disc image, 128 BUILD. */
    private const array W54_PC_TOKENS = [
        'WIN' => 2 | 1 << 16, 'WINDOWS' => 2 | 1 << 16, 'WIN32' => 2 | 1 << 16,
        'WIN64' => 2 | 1 << 16, 'WINALL' => 2 | 1 << 16, 'X64' => 2 | 1 << 16, 'X86' => 2 | 1 << 16,
        'MAC' => 4 | 2 << 16, 'MACOS' => 4 | 2 << 16, 'MACOSX' => 4 | 2 << 16, 'OSX' => 4 | 2 << 16,
        'DMG' => 4 | 2 << 16, 'LINUX' => 8 | 3 << 16, 'UBUNTU' => 8 | 3 << 16, 'DEB' => 8 | 3 << 16,
        'RPM' => 8 | 3 << 16, 'ANDROID' => 16 | 4 << 16, 'APK' => 16 | 4 << 16,
        'IOS' => 16 | 4 << 16, 'IPA' => 16 | 4 << 16, 'IPHONE' => 16 | 4 << 16,
        'IPAD' => 16 | 4 << 16, 'CRACK' => 32, 'CRACKED' => 32, 'KEYGEN' => 32, 'PATCH' => 32,
        'SERIAL' => 32, 'KEYMAKER' => 32, 'ACTIVATOR' => 32, 'LOADER' => 32, 'REGGED' => 32,
        'KEYFILE' => 32, 'ISO' => 64, 'IMG' => 64, 'BIN' => 64, 'CUE' => 64, 'SETUP' => 64,
        'INSTALLER' => 64, 'MSI' => 64, 'EXE' => 64, 'PORTABLE' => 64, 'BUILD' => 128,
    ];

    public static function usesW54RustFfi(): bool
    {
        $override = self::w54RustFfiState();

        return \is_bool($override) ? $override : self::W54_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the Wave 54 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW54RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w54RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w54RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 54.3 PC platform flags. Per name `flags | platform << 16`, with flags 1 empty (after
     * trim()), 2 Windows, 4 macOS, 8 Linux, 16 mobile, 32 crack, 64 installer or disc image and 128
     * `V\d+`/BUILD version, and `platform` the first platform token's family (1 Windows, 2 macOS, 3
     * Linux, 4 mobile; 0 without one); then the eight flag counts. Tokens are maximal ASCII
     * alphanumeric runs compared case-insensitively.
     *
     * @param  array<array-key, string>  $names
     * @return list<int>
     */
    public static function w54PcPlatformFlagBatch(array $names): array
    {
        return self::usesW54RustFfi() ? self::w54PcPlatformFlagBatchNative($names) : self::w54PcPlatformFlagBatchPhp($names);
    }

    /**
     * @param  array<array-key, string>  $names
     * @return list<int>
     */
    public static function w54PcPlatformFlagBatchNative(array $names): array
    {
        return self::w54Invoke($names, \count($names) + 8) ?? self::w54PcPlatformFlagBatchPhp($names);
    }

    /**
     * @param  array<array-key, string>  $names
     * @return list<int>
     */
    public static function w54PcPlatformFlagBatchPhp(array $names): array
    {
        $out = [];
        $totals = [0, 0, 0, 0, 0, 0, 0, 0];
        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                $word = 1;
            } else {
                $flags = 0;
                $platform = 0;
                foreach (preg_split('/[^A-Za-z0-9]+/', strtoupper($name), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                    $found = self::W54_PC_TOKENS[$token] ?? 0;
                    if ($token[0] === 'V' && \strlen($token) > 1 && ctype_digit(substr($token, 1))) {
                        $flags |= 128;
                    }
                    $flags |= $found & 0xFFFF;
                    if ($platform === 0) {
                        $platform = $found >> 16;
                    }
                }
                $word = $flags | ($platform << 16);
            }
            $totals[0] += $word & 1;
            $totals[1] += ($word >> 1) & 1;
            $totals[2] += ($word >> 2) & 1;
            $totals[3] += ($word >> 3) & 1;
            $totals[4] += ($word >> 4) & 1;
            $totals[5] += ($word >> 5) & 1;
            $totals[6] += ($word >> 6) & 1;
            $totals[7] += ($word >> 7) & 1;
            $out[] = $word;
        }
        array_push($out, ...$totals);

        return $out;
    }

    /**
     * Wave 54 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'ffi' resolves the library (null when unavailable) and 'out'
     * returns an `int64_t` output buffer of at least $val words.
     */
    private static function w54RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('categorize_w54', self::W54_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w54RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }

        return $override;
    }

    /**
     * Calls the Wave 54.3 export with the NUL-joined $items. Null when the library is missing, the
     * call declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>|null
     */
    private static function w54Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w54RustFfiState('ffi');
            $out = self::w54RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $blob = implode("\0", $items);
            if (substr_count($blob, "\0") !== max(0, \count($items) - 1)) {
                // An item holds a NUL byte: substitute a space, which classifies identically (trim() strips both bytes and both split tokens).
                $blob = implode("\0", str_replace("\0", ' ', $items));
            }
            // @phpstan-ignore method.notFound (Declared by W54_FFI_CDEF.)
            $written = $ffi->nntmux_w543_pc_platform_flag_batch($blob, \strlen($blob), \count($items), $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    /** C signature of the Wave 66.6 {@see self::w66PcPipePlanBatch()} helper. */
    private const string W66_PC_PIPE_PLAN_FFI_CDEF = 'int64_t nntmux_w666_pc_pipe_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /**
     * The Wave 66.6 plan batch defaults to Rust: every common and uncommon mean and p99 ratio cleared 1.5x
     * (min 7.21/4.77/5.93/3.86x); see tests/Unit/RustCategorizationMiscSafetyMovieMusicPcTvPipeW66ParityTest.php.
     */
    public const bool W66_RUST_FFI_DEFAULT = true;

    /** `S\d{1,3}(?:E\d{1,4})?` over a whole uppercased token. */
    private const string W66_SEASON_PATTERN = '/\AS(\d{1,3})(?:E(\d{1,4}))?\z/';

    /** Wave 66.6 flags per uppercased token: 1 Windows, 2 macOS, 4 Linux, 8 iOS, 16 Android, 32 disc image, 64 game, 128 software, 256 build, 512 console, 1024 media. */
    private const array W66_PC_TOKENS = [
        'WIN' => 1, 'WINDOWS' => 1, 'WIN32' => 1, 'WIN64' => 1, 'WINALL' => 1, 'X64' => 1, 'X86' => 1,
        'MAC' => 2, 'MACOS' => 2, 'MACOSX' => 2, 'OSX' => 2, 'DMG' => 2,
        'LINUX' => 4, 'UBUNTU' => 4, 'DEB' => 4, 'RPM' => 4,
        'IOS' => 8, 'IPA' => 8, 'IPHONE' => 8, 'IPAD' => 8,
        'ANDROID' => 16, 'APK' => 16,
        'ISO' => 32, 'IMG' => 32, 'BIN' => 32, 'CUE' => 32,
        'GOG' => 64, 'STEAM' => 64, 'CODEX' => 64, 'PLAZA' => 64, 'SKIDROW' => 64, 'RELOADED' => 64, 'FLT' => 64, 'DARKSIDERS' => 64, 'EMPRESS' => 64, 'TENOKE' => 64, 'RUNE' => 64, 'GAME' => 64,
        'CRACK' => 128, 'CRACKED' => 128, 'KEYGEN' => 128, 'PATCH' => 128, 'SERIAL' => 128, 'PORTABLE' => 128, 'SETUP' => 128, 'INSTALLER' => 128, 'MSI' => 128, 'EXE' => 128, 'MULTILINGUAL' => 128, 'RETAIL' => 128,
        'BUILD' => 256,
        'PS4' => 512, 'PS5' => 512, 'XBOX' => 512, 'XBOX360' => 512, 'X360' => 512, 'NSW' => 512, 'SWITCH' => 512, 'WII' => 512, 'WIIU' => 512, '3DS' => 512, 'NDS' => 512,
        '720P' => 1024, '1080P' => 1024, '2160P' => 1024, 'X264' => 1024, 'X265' => 1024, 'HEVC' => 1024, 'MP3' => 1024, 'FLAC' => 1024, 'EPUB' => 1024,
    ];

    /** Wave 66.6 proposals in priority order: flag bits => category. */
    private const array W66_PC_PROPOSALS = [
        64 => Category::PC_GAMES,
        16 => Category::PC_PHONE_ANDROID,
        8 => Category::PC_PHONE_IOS,
        2 => Category::PC_MAC,
        32 => Category::PC_ISO,
        1 | 4 | 128 | 256 => Category::PC_0DAY,
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
                    $ffi = RustPipelineLibrary::resolveDomain('w66_6', self::W66_PC_PIPE_PLAN_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
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
     * Runs $callback with w66PcPipePlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
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
            $written = $ffi->nntmux_w666_pc_pipe_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
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
     * Batch PC pipe plan over `subject\tcategoryId\tconfidence\tmatchedBy\tcurrentCategory`, confidences in
     * permille. Eight words per record: `[tokens, flags, proposed, mask, decision, target, extra, errors]`. Tokens are
     * maximal ASCII alphanumeric runs of the subject compared case-insensitively; flags: W66_PC_TOKENS bits (256 also `V\d+`, 1024 also a
     * TV season token). Proposed is the
     * first W66_PC_PROPOSALS category whose flag bits are set (0 none). Mask: 1 successful (like evaluateResultPhp()),
     * 2 in domain, 4 terminal (>= 950), 8 proposed equals the result category. Decision/target: 3 keeps an
     * OTHER_HASHED current category, 1 takes a successful in-domain result, 4 keeps the current category when console or media
     * markers appear without any PC platform,
     * 2 moves an empty, OTHER_MISC or PC_0DAY current category to the proposal, 0 keeps the current category
     * (default OTHER_MISC). Extra: the number of distinct platforms (flag bits 1-16). Numbers are trimmed 1-15 ASCII digits; errors: 1 invalid number, 2 not
     * exactly five fields.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w66PcPipePlanBatch(array $records): array
    {
        return self::usesW66RustFfi() ? self::w66PcPipePlanBatchNative($records) : self::w66PcPipePlanBatchPhp($records);
    }

    /**
     * Rust batch over the NUL-joined records; falls back to PHP without the library or when a record holds
     * a NUL.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w66PcPipePlanBatchNative(array $records): array
    {
        $count = \count($records);
        if ($count === 0) {
            return [];
        }

        return self::w66Words($count, implode("\0", $records), 8 * $count) ?? self::w66PcPipePlanBatchPhp($records);
    }

    /**
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w66PcPipePlanBatchPhp(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $fields = explode("\t", $record);
            $errors = \count($fields) === 5 ? 0 : 2;
            $tokens = $flags = 0;
            foreach (preg_split('/[^A-Z0-9]+/', strtoupper($fields[0]), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                $tokens++;
                $flags |= self::W66_PC_TOKENS[$token] ?? 0;
                if ($token[0] === 'V' && \strlen($token) > 1 && ctype_digit(substr($token, 1))) {
                    $flags |= 256;
                }
                if ($token[0] === 'S' && preg_match(self::W66_SEASON_PATTERN, $token) === 1) {
                    $flags |= 1024;
                }
            }
            $proposed = 0;
            foreach (self::W66_PC_PROPOSALS as $bits => $candidate) {
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
            $inDomain = $category >= Category::PC_ROOT && $category <= Category::PC_ROOT + 999;
            [$decision, $target] = match (true) {
                $current === Category::OTHER_HASHED => [3, $current],
                $success && $inDomain => [1, $category],
                ($flags & (512 | 1024)) !== 0 && ($flags & 31) === 0 => [4, $current],
                $proposed !== 0 && ($current === 0 || $current === Category::OTHER_MISC || $current === Category::PC_0DAY) => [2, $proposed],
                default => [0, $current],
            };
            $mask = ($success ? 1 : 0) | ($inDomain ? 2 : 0) | ($confidence >= 950 ? 4 : 0) | ($proposed !== 0 && $proposed === $category ? 8 : 0);
            array_push($out, $tokens, $flags, $proposed, $mask, $decision, $target, substr_count(decbin($flags & 31), '1'), $errors);
        }

        return $out;
    }
}
