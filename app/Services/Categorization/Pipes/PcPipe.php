<?php

declare(strict_types=1);

namespace App\Services\Categorization\Pipes;

use App\Models\Category;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\Categorizers\PcCategorizer;
use App\Services\Categorization\ReleaseContext;
use App\Support\RustPipelineLibrary;
use FFI;
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
}
