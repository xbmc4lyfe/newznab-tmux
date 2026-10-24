<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TvProcessing\TvProcessingPipeline;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class PostProcessTvPipeline extends Command
{
    private const FFI_CDEF = <<<'CDEF'
    int32_t nntmux_postprocess_tv_pipeline_is_valid_hex_char(const char *value_ptr, size_t value_len);
    ssize_t nntmux_postprocess_tv_pipeline_normalize_renamed(
        uint32_t has_renamed,
        const char *renamed_ptr,
        size_t renamed_len,
        char *out_buf,
        size_t out_cap
    );
    int32_t nntmux_postprocess_tv_pipeline_should_warn_mode(uint32_t mode_is_null);
    CDEF;

    /**
     * Rust stays opt-in: the FFI round trip is slower than these PHP helpers (< 1.5x); see
     * tests/Unit/RustPostProcessTvAniDbSteamCommandsParityTest.php.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private static bool $useRustFfi = self::RUST_FFI_DEFAULT;

    private static FFI|false|null $ffi = null;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'postprocess:tv-pipeline
                            {guid : First character of release guid (a-f, 0-9)}
                            {renamed? : Process renamed only (optional)}
                            {--mode= : Deprecated and ignored; every run uses the provider pipeline}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Post process TV releases by GUID character through the TV provider pipeline';

    public static function useRustFfi(bool $enabled = true): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = self::RUST_FFI_DEFAULT;
        self::$ffi = null;
    }

    public static function setFfiForTesting(FFI|false|null $ffi = null): void
    {
        self::$ffi = $ffi;
    }

    public static function isValidHexChar(string $str): bool
    {
        return self::$useRustFfi ? self::isValidHexCharNative($str) : self::isValidHexCharPhp($str);
    }

    /**
     * Check if the character contains a-f or 0-9.
     */
    public static function isValidHexCharPhp(string $str): bool
    {
        return \in_array(
            $str,
            ['a', 'b', 'c', 'd', 'e', 'f', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            true
        );
    }

    public static function isValidHexCharNative(string $str): bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::isValidHexCharPhp($str);
        }

        try {
            $rc = (int) $ffi->nntmux_postprocess_tv_pipeline_is_valid_hex_char($str, strlen($str)); // @phpstan-ignore method.notFound

            return $rc < 0 ? self::isValidHexCharPhp($str) : $rc === 1;
        } catch (Throwable) {
            return self::isValidHexCharPhp($str);
        }
    }

    public static function normalizeRenamedArg(?string $renamed): string
    {
        return self::$useRustFfi ? self::normalizeRenamedArgNative($renamed) : self::normalizeRenamedArgPhp($renamed);
    }

    public static function normalizeRenamedArgPhp(?string $renamed): string
    {
        return $renamed ?? '';
    }

    public static function normalizeRenamedArgNative(?string $renamed): string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::normalizeRenamedArgPhp($renamed);
        }

        try {
            return self::callBufferFfi($ffi, strlen($renamed ?? ''), 'nntmux_postprocess_tv_pipeline_normalize_renamed', $renamed === null ? 0 : 1, $renamed ?? '', strlen($renamed ?? ''))
                ?? self::normalizeRenamedArgPhp($renamed);
        } catch (Throwable) {
            return self::normalizeRenamedArgPhp($renamed);
        }
    }

    public static function shouldWarnDeprecatedMode(mixed $modeOption): bool
    {
        return self::$useRustFfi ? self::shouldWarnDeprecatedModeNative($modeOption) : self::shouldWarnDeprecatedModePhp($modeOption);
    }

    public static function shouldWarnDeprecatedModePhp(mixed $modeOption): bool
    {
        return $modeOption !== null;
    }

    public static function shouldWarnDeprecatedModeNative(mixed $modeOption): bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::shouldWarnDeprecatedModePhp($modeOption);
        }

        try {
            $rc = (int) $ffi->nntmux_postprocess_tv_pipeline_should_warn_mode($modeOption === null ? 1 : 0); // @phpstan-ignore method.notFound

            return $rc < 0 ? self::shouldWarnDeprecatedModePhp($modeOption) : $rc === 1;
        } catch (Throwable) {
            return self::shouldWarnDeprecatedModePhp($modeOption);
        }
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $guid = $this->argument('guid');
        $renamed = self::normalizeRenamedArg($this->argument('renamed'));

        if (! self::isValidHexChar($guid)) {
            $this->error('GUID character must be a-f or 0-9.');

            return self::FAILURE;
        }

        if (self::shouldWarnDeprecatedMode($this->option('mode'))) {
            $this->warn('The --mode option is deprecated and ignored; every run uses the provider pipeline.');
        }

        try {
            app(TvProcessingPipeline::class)->process('', $guid, $renamed);

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error($e->getTraceAsString());
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Runs a buffer-writing export, appending the scratch buffer and its capacity; `null` when it declines.
     */
    private static function callBufferFfi(FFI $ffi, int $minBytes, string $symbol, mixed ...$args): ?string
    {
        $cap = 0;
        $buf = RustPipelineLibrary::obtainScratch($ffi, max(128, $minBytes), $cap);
        if ($buf === null) {
            return null;
        }

        $rc = (int) $ffi->$symbol(...[...$args, $buf, $cap]);
        if ($rc < 0) {
            return null;
        }

        return $rc > 0 ? FFI::string($buf, $rc) : '';
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $envLib = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $ffi = RustPipelineLibrary::resolveDomain(
            'metadata',
            self::FFI_CDEF,
            is_string($envLib) && $envLib !== '' ? $envLib : null,
        );
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
