<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SteamService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Throwable;

class NntmuxPopulateSteamApps extends Command
{
    private const FFI_CDEF = <<<'CDEF'
    int32_t nntmux_populate_steam_apps_should_report_progress(int64_t processed);
    ssize_t nntmux_populate_steam_apps_format_progress(int64_t processed, int64_t total, char *out_buf, size_t out_cap);
    ssize_t nntmux_populate_steam_apps_format_summary(
        int64_t inserted,
        int64_t dupe,
        int64_t errors,
        char *out_buf,
        size_t out_cap
    );
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
    protected $signature = 'nntmux:populate-steam-apps';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Populate Steam apps table';

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

    public static function shouldReportProgress(int $processed): bool
    {
        return self::$useRustFfi ? self::shouldReportProgressNative($processed) : self::shouldReportProgressPhp($processed);
    }

    public static function shouldReportProgressPhp(int $processed): bool
    {
        return $processed > 0 && $processed % 1000 === 0;
    }

    public static function shouldReportProgressNative(int $processed): bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::shouldReportProgressPhp($processed);
        }

        try {
            return (int) $ffi->nntmux_populate_steam_apps_should_report_progress($processed) === 1; // @phpstan-ignore method.notFound
        } catch (Throwable) {
            return self::shouldReportProgressPhp($processed);
        }
    }

    public static function formatProgressLine(int $processed, int $total): string
    {
        return self::$useRustFfi ? self::formatProgressLineNative($processed, $total) : self::formatProgressLinePhp($processed, $total);
    }

    public static function formatProgressLinePhp(int $processed, int $total): string
    {
        return "Processed {$processed} of {$total} apps";
    }

    public static function formatProgressLineNative(int $processed, int $total): string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::formatProgressLinePhp($processed, $total);
        }

        try {
            return self::callBufferFfi($ffi, 128, 'nntmux_populate_steam_apps_format_progress', $processed, $total)
                ?? self::formatProgressLinePhp($processed, $total);
        } catch (Throwable) {
            return self::formatProgressLinePhp($processed, $total);
        }
    }

    public static function formatFinalSummaryLine(int $inserted, int $dupe, int $errors): string
    {
        return self::$useRustFfi ? self::formatFinalSummaryLineNative($inserted, $dupe, $errors) : self::formatFinalSummaryLinePhp($inserted, $dupe, $errors);
    }

    public static function formatFinalSummaryLinePhp(int $inserted, int $dupe, int $errors): string
    {
        return sprintf('Added %d new steam app(s), %d skipped, %d errors', $inserted, $dupe, $errors);
    }

    public static function formatFinalSummaryLineNative(int $inserted, int $dupe, int $errors): string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::formatFinalSummaryLinePhp($inserted, $dupe, $errors);
        }

        try {
            return self::callBufferFfi($ffi, 160, 'nntmux_populate_steam_apps_format_summary', $inserted, $dupe, $errors)
                ?? self::formatFinalSummaryLinePhp($inserted, $dupe, $errors);
        } catch (Throwable) {
            return self::formatFinalSummaryLinePhp($inserted, $dupe, $errors);
        }
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        try {
            $this->info('Populating steam apps table...');

            $service = new SteamService;
            $stats = $service->populateSteamAppsTable(function (int $processed, int $total) {
                if (self::shouldReportProgress($processed)) {
                    $this->info(self::formatProgressLine($processed, $total));
                }
            });

            $this->info(self::formatFinalSummaryLine($stats['inserted'], $stats['skipped'], $stats['errors']));
        } catch (\Exception $e) {
            $this->error($e->getMessage());
            $this->error('There was an error populating the steam_apps table');
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
