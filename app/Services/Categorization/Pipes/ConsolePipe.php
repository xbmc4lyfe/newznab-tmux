<?php

declare(strict_types=1);

namespace App\Services\Categorization\Pipes;

use App\Models\Category;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\Categorizers\ConsoleCategorizer;
use App\Services\Categorization\ReleaseContext;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Pipe for Console game categorization.
 */
class ConsolePipe extends AbstractCategorizationPipe
{
    /**
     * Wave 40.3 production default is Rust: the paired benchmark in
     * tests/Unit/RustCategorizationServiceGroupNameFixerW40ParityTest.php measures at least 2.0x
     * (mean and p99, common and uncommon batches). PHP stays the fallback when the library is
     * unavailable. Named W403 so it stays distinct from other Rust FFI toggles.
     */
    public const bool W403_RUST_FFI_DEFAULT = true;

    public const string W403_FFI_CDEF = <<<'C'
        ssize_t nntmux_w403_cleaned_filename_batch(
            const char *blob_ptr,
            size_t blob_len,
            const char *lens_ptr,
            size_t lens_len,
            int64_t min_plausible_length,
            char *out_buf,
            size_t out_cap
        );
        C;

    /** Trailing extensions of 1 to this many ASCII letters and digits are stripped from the stem. */
    public const int W403_MAX_EXTENSION_LENGTH = 4;

    private const string W403_ASCII_ALNUM = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    /** Lowercased stems that carry no release identity. */
    private const array W403_GENERIC_STEMS = [
        'sample' => true, 'proof' => true, 'cover' => true, 'covers' => true, 'thumbs' => true,
        'folder' => true, 'index' => true, 'readme' => true, 'screens' => true, 'screenshot' => true,
        'subs' => true, 'setup' => true, 'install' => true, 'info' => true, 'nfo' => true,
        'sfv' => true, 'par2' => true, 'extras' => true, 'default' => true, 'untitled' => true,
    ];

    private static ?bool $w403RustFfiOverride = null;

    private static FFI|false|null $w403RustFfiHandle = null;

    public const string PIPE_SPEC_FFI_CDEF = <<<'C'
        ssize_t nntmux_categorize_pipes_spec_name(uint8_t kind, char *out_buf, size_t out_cap);
        int64_t nntmux_categorize_pipes_spec_priority(uint8_t kind);
        int32_t nntmux_categorize_pipes_spec_evaluate_result(uint8_t kind, int64_t category_id, double confidence, const char *matched_by_ptr, size_t matched_by_len);
        C;

    /** The result passes `CategorizationResult::isSuccessful()`. */
    public const int RESULT_SUCCESSFUL = 1;

    /** The category id lies in this pipe's category family. */
    public const int RESULT_IN_DOMAIN = 2;

    /** The confidence reaches the `CategorizationPassable` stop threshold. */
    public const int RESULT_TERMINAL = 4;

    private const int PIPE_SPEC_KIND = 1;

    private const string PIPE_NAME = 'Console';

    private const int PIPE_PRIORITY = 35;

    // Public because the parent declares it public; a private redeclaration is a fatal error.
    public static bool $useRustFfi = false;

    private static FFI|false|null $pipeSpecFfi = null;

    protected int $priority = self::PIPE_PRIORITY;

    private ConsoleCategorizer $categorizer;

    public function __construct()
    {
        $this->categorizer = new ConsoleCategorizer;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function setPipeSpecFfiForTesting(FFI|false|null $ffi): void
    {
        self::$pipeSpecFfi = $ffi;
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
        $ffi = self::pipeSpecFfi();
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
            $written = $ffi->nntmux_categorize_pipes_spec_name(self::PIPE_SPEC_KIND, $scratch, $cap);
            if (! \is_int($written) || $written <= 0) {
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
        $ffi = self::pipeSpecFfi();
        if ($ffi === null) {
            return self::pipePriorityPhp();
        }

        try {
            // @phpstan-ignore method.notFound
            $priority = $ffi->nntmux_categorize_pipes_spec_priority(self::PIPE_SPEC_KIND);
        } catch (Throwable) {
            return self::pipePriorityPhp();
        }

        return \is_int($priority) && $priority >= 0 ? $priority : self::pipePriorityPhp();
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
        if ($categoryId >= Category::GAME_ROOT && $categoryId <= Category::GAME_ROOT + 999) {
            $mask |= self::RESULT_IN_DOMAIN;
        }
        if ($confidence >= 0.95) {
            $mask |= self::RESULT_TERMINAL;
        }

        return $mask;
    }

    public static function evaluateResultNative(int $categoryId, float $confidence, string $matchedBy): int
    {
        $ffi = self::pipeSpecFfi();
        if ($ffi === null) {
            return self::evaluateResultPhp($categoryId, $confidence, $matchedBy);
        }

        try {
            // @phpstan-ignore method.notFound
            $mask = $ffi->nntmux_categorize_pipes_spec_evaluate_result(
                self::PIPE_SPEC_KIND,
                $categoryId,
                $confidence,
                $matchedBy,
                \strlen($matchedBy)
            );
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

    private static function pipeSpecFfi(): ?FFI
    {
        if (self::$pipeSpecFfi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            try {
                self::$pipeSpecFfi = RustPipelineLibrary::resolveDomain(
                    'categorize',
                    self::PIPE_SPEC_FFI_CDEF,
                    \is_string($path) && $path !== '' ? $path : null
                ) ?? false;
            } catch (Throwable) {
                self::$pipeSpecFfi = false;
            }
        }

        return self::$pipeSpecFfi === false ? null : self::$pipeSpecFfi;
    }

    public static function useW403RustFfi(?bool $enabled): void
    {
        self::$w403RustFfiOverride = $enabled;
    }

    public static function usesW403RustFfi(): bool
    {
        return self::$w403RustFfiOverride ?? self::W403_RUST_FFI_DEFAULT;
    }

    public static function resetW403RustFfi(): void
    {
        self::$w403RustFfiOverride = null;
        self::$w403RustFfiHandle = null;
    }

    /**
     * Cleaning summary for a batch of filenames. Each name is trimmed (PHP's default set), cut after
     * its last "/" or "\\", loses a trailing extension of 1 to W403_MAX_EXTENSION_LENGTH ASCII
     * alphanumerics (when the dot is not the first byte), then is trimmed of whitespace, ".", "-" and
     * "_". Stems shorter than $minPlausibleLength bytes are too short; all-digit and generic stems are
     * junk; the rest are plausible candidates, deduplicated case-insensitively. Non-strings count as
     * empty.
     *
     * @param  array<array-key, mixed>  $filenames
     * @return array{total: int, plausible: int, too_short: int, generic_junk: int, unique_candidates: int}
     */
    public static function summarizeCleanedFilenameBatch(array $filenames, int $minPlausibleLength = 5): array
    {
        return (self::usesW403RustFfi() ? self::summarizeCleanedFilenameBatchNative($filenames, $minPlausibleLength) : null)
            ?? self::summarizeCleanedFilenameBatchPhp($filenames, $minPlausibleLength);
    }

    /**
     * @param  array<array-key, mixed>  $filenames
     * @return array{total: int, plausible: int, too_short: int, generic_junk: int, unique_candidates: int}
     */
    public static function summarizeCleanedFilenameBatchPhp(array $filenames, int $minPlausibleLength = 5): array
    {
        $plausible = 0;
        $tooShort = 0;
        $junk = 0;
        $seen = [];
        foreach ($filenames as $filename) {
            $name = trim(\is_string($filename) ? $filename : '');
            $slash = strrpos($name, '/');
            $backslash = strrpos($name, '\\');
            $cut = max($slash === false ? -1 : $slash, $backslash === false ? -1 : $backslash);
            $stem = $cut >= 0 ? substr($name, $cut + 1) : $name;
            $dot = strrpos($stem, '.');
            if ($dot !== false && $dot > 0) {
                $extensionLength = \strlen($stem) - $dot - 1;
                if ($extensionLength >= 1 && $extensionLength <= self::W403_MAX_EXTENSION_LENGTH
                    && strspn($stem, self::W403_ASCII_ALNUM, $dot + 1) === $extensionLength) {
                    $stem = substr($stem, 0, $dot);
                }
            }
            $stem = trim($stem, " \t\n\r\0\x0B.-_");
            if (\strlen($stem) < $minPlausibleLength) {
                $tooShort++;

                continue;
            }
            $lower = strtolower($stem);
            if (strspn($lower, '0123456789') === \strlen($lower) || isset(self::W403_GENERIC_STEMS[$lower])) {
                $junk++;

                continue;
            }
            $plausible++;
            $seen[$lower] = true;
        }

        return [
            'total' => \count($filenames),
            'plausible' => $plausible,
            'too_short' => $tooShort,
            'generic_junk' => $junk,
            'unique_candidates' => \count($seen),
        ];
    }

    /**
     * Declines (null) for non-string filenames so PHP counts them.
     *
     * @param  array<array-key, mixed>  $filenames
     * @return array{total: int, plausible: int, too_short: int, generic_junk: int, unique_candidates: int}|null
     */
    public static function summarizeCleanedFilenameBatchNative(array $filenames, int $minPlausibleLength = 5): ?array
    {
        $lengths = [];
        foreach ($filenames as $item) {
            if (! \is_string($item)) {
                return null;
            }
            $lengths[] = \strlen($item);
        }
        $blob = implode('', $filenames);
        $lens = pack('V*', ...$lengths);
        $fields = self::callW403Native('nntmux_w403_cleaned_filename_batch', [$blob, \strlen($blob), $lens, \strlen($lens), $minPlausibleLength], 96, 5);
        if ($fields === null) {
            return null;
        }

        return [
            'total' => (int) $fields[0],
            'plausible' => (int) $fields[1],
            'too_short' => (int) $fields[2],
            'generic_junk' => (int) $fields[3],
            'unique_candidates' => (int) $fields[4],
        ];
    }

    /**
     * Calls a Wave 40.3 export with the shared scratch buffer, retrying once at the size it asks for.
     * Returns its fields only when there are exactly $fieldCount, all of them decimal.
     *
     * @param  list<int|string>  $args  arguments preceding the caller-owned output buffer
     * @return list<string>|null
     */
    private static function callW403Native(string $symbol, array $args, int $minBytes, int $fieldCount): ?array
    {
        $ffi = self::w403RustFfi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $minBytes, $capacity);
            if ($buffer === null) {
                return null;
            }
            $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }
            $fields = explode("\x1F", FFI::string($buffer, $written));
            if (\count($fields) !== $fieldCount) {
                return null;
            }
            foreach ($fields as $field) {
                if (\strlen($field) > 19 || ! ctype_digit($field)) {
                    return null;
                }
            }

            return $fields;
        } catch (Throwable) {
            return null;
        }
    }

    private static function w403RustFfi(): ?FFI
    {
        if (self::$w403RustFfiHandle === null) {
            self::$w403RustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w403', self::W403_FFI_CDEF) ?? false;
        }

        return self::$w403RustFfiHandle === false ? null : self::$w403RustFfiHandle;
    }

    /** Wave 54.3 paired benchmarks decide this default; see RustCategorizationPipesConsoleGroupMiscMusicPcW54ParityTest. */
    private const bool W54_RUST_FFI_DEFAULT = true;

    public const string W54_FFI_CDEF = <<<'C'
        ssize_t nntmux_w543_console_platform_flag_batch(const char *blob, size_t blob_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Per uppercased token: 2 | category << 16 for a platform, 4 | GAME_OTHER << 16 for a retro platform, 8 region and 32 PC store or OS. */
    private const array W54_CONSOLE_TOKENS = [
        'PS4' => 2 | Category::GAME_PS4 << 16, 'PS3' => 2 | Category::GAME_PS3 << 16,
        'PSVITA' => 2 | Category::GAME_PSVITA << 16, 'VITA' => 2 | Category::GAME_PSVITA << 16,
        'PSV' => 2 | Category::GAME_PSVITA << 16, 'PSP' => 2 | Category::GAME_PSP << 16,
        'XBOXONE' => 2 | Category::GAME_XBOXONE << 16, 'XONE' => 2 | Category::GAME_XBOXONE << 16,
        'XBONE' => 2 | Category::GAME_XBOXONE << 16, 'X360' => 2 | Category::GAME_XBOX360 << 16,
        'XBOX360' => 2 | Category::GAME_XBOX360 << 16, 'XBOX' => 2 | Category::GAME_XBOX << 16,
        'WIIU' => 2 | Category::GAME_WIIU << 16, 'WII' => 2 | Category::GAME_WII << 16,
        '3DS' => 2 | Category::GAME_3DS << 16, 'NDS' => 2 | Category::GAME_NDS << 16,
        'SWITCH' => 2 | Category::GAME_OTHER << 16, 'NSW' => 2 | Category::GAME_OTHER << 16,
        'PS5' => 2 | Category::GAME_OTHER << 16, 'SNES' => 4 | Category::GAME_OTHER << 16,
        'NES' => 4 | Category::GAME_OTHER << 16, 'N64' => 4 | Category::GAME_OTHER << 16,
        'GBA' => 4 | Category::GAME_OTHER << 16, 'GBC' => 4 | Category::GAME_OTHER << 16,
        'NGC' => 4 | Category::GAME_OTHER << 16, 'GAMECUBE' => 4 | Category::GAME_OTHER << 16,
        'PSX' => 4 | Category::GAME_OTHER << 16, 'PS1' => 4 | Category::GAME_OTHER << 16,
        'PS2' => 4 | Category::GAME_OTHER << 16, 'GENESIS' => 4 | Category::GAME_OTHER << 16,
        'MEGADRIVE' => 4 | Category::GAME_OTHER << 16,
        'DREAMCAST' => 4 | Category::GAME_OTHER << 16, 'SATURN' => 4 | Category::GAME_OTHER << 16,
        'USA' => 8, 'EUR' => 8, 'PAL' => 8, 'NTSC' => 8, 'JPN' => 8, 'JAP' => 8, 'ASIA' => 8,
        'KOR' => 8, 'REGIONFREE' => 8, 'GOG' => 32, 'STEAM' => 32, 'WIN' => 32, 'WIN32' => 32,
        'WIN64' => 32, 'WINDOWS' => 32, 'X64' => 32, 'X86' => 32, 'PC' => 32,
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
     * Wave 54.3 console platform flags. Per name `flags | category << 16`, with flags 1 empty (after
     * trim()), 2 platform, 4 retro platform, 8 region, 16 TV season/episode token (`S\d{1,3}` with an
     * optional `E\d{1,4}`) and 32 PC store or OS token, and `category` the first platform token's
     * Category id (0 without one); then the six flag counts and the number of distinct categories.
     * Tokens are maximal ASCII alphanumeric runs compared case-insensitively.
     *
     * @param  array<array-key, string>  $names
     * @return list<int>
     */
    public static function w54ConsolePlatformFlagBatch(array $names): array
    {
        return self::usesW54RustFfi() ? self::w54ConsolePlatformFlagBatchNative($names) : self::w54ConsolePlatformFlagBatchPhp($names);
    }

    /**
     * @param  array<array-key, string>  $names
     * @return list<int>
     */
    public static function w54ConsolePlatformFlagBatchNative(array $names): array
    {
        return self::w54Invoke($names, \count($names) + 7) ?? self::w54ConsolePlatformFlagBatchPhp($names);
    }

    /**
     * @param  array<array-key, string>  $names
     * @return list<int>
     */
    public static function w54ConsolePlatformFlagBatchPhp(array $names): array
    {
        $out = [];
        $totals = [0, 0, 0, 0, 0, 0];
        $seen = [];
        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                $word = 1;
            } else {
                $flags = 0;
                $category = 0;
                foreach (preg_split('/[^A-Za-z0-9]+/', strtoupper($name), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                    $found = self::W54_CONSOLE_TOKENS[$token] ?? 0;
                    if ($token[0] === 'S' && preg_match('/\AS\d{1,3}(?:E\d{1,4})?\z/', $token) === 1) {
                        $flags |= 16;
                    }
                    $flags |= $found & 0xFFFF;
                    if ($category === 0) {
                        $category = $found >> 16;
                    }
                }
                if ($category !== 0) {
                    $seen[$category] = true;
                }
                $word = $flags | ($category << 16);
            }
            $totals[0] += $word & 1;
            $totals[1] += ($word >> 1) & 1;
            $totals[2] += ($word >> 2) & 1;
            $totals[3] += ($word >> 3) & 1;
            $totals[4] += ($word >> 4) & 1;
            $totals[5] += ($word >> 5) & 1;
            $out[] = $word;
        }
        array_push($out, ...$totals);
        $out[] = \count($seen);

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
            $written = $ffi->nntmux_w543_console_platform_flag_batch($blob, \strlen($blob), \count($items), $out, $words);
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
}
