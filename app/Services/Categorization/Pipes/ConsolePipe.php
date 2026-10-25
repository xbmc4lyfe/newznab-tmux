<?php

declare(strict_types=1);

namespace App\Services\Categorization\Pipes;

use App\Models\Category;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\Categorizers\ConsoleCategorizer;
use App\Services\Categorization\ReleaseContext;
use App\Support\RustPipelineLibrary;
use FFI;
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
}
