<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Monitoring\PrometheusMetricsExporter;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'monitoring:export-metrics')]
class ExportMonitoringMetrics extends Command
{
    private const FFI_CDEF = <<<'CDEF'
int32_t nntmux_export_monitoring_metrics_resolve_export_mode(
    uint8_t print_only,
    uint8_t enabled,
    const char *url_ptr,
    size_t url_len,
    uint8_t has_pcntl
);
ssize_t nntmux_export_monitoring_metrics_format_failure_message(
    const char *message_ptr,
    size_t message_len,
    uint8_t *out_buf,
    size_t out_cap
);
CDEF;

    /** Rust stays opt-in: a mode switch and one message are too small for FFI to beat PHP by 1.5x. */
    private const RUST_FFI_DEFAULT = false;

    private static bool $useRustFfi = self::RUST_FFI_DEFAULT;

    private static FFI|false|null $ffi = null;

    /** Rust export mode codes, in `resolveExportMode()` order. */
    private const array EXPORT_MODES = ['print', 'disabled', 'push', 'push_with_alarm'];

    /**
     * The exporter reports a blank Pushgateway URL as a push failure, so the command keeps that
     * path instead of letting the configured URL select the disabled mode.
     */
    private const string PUSHGATEWAY_URL_CHECKED_BY_EXPORTER = 'exporter';

    /**
     * @var string
     */
    protected $signature = 'monitoring:export-metrics
                            {--print : Print the metrics instead of pushing them to the Pushgateway}
                            {--scheduled : Limit the scheduled export runtime to four minutes}';

    /**
     * @var string
     */
    protected $description = 'Push NNTmux application metrics to the Prometheus Pushgateway';

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

    /**
     * @return 'print'|'disabled'|'push'|'push_with_alarm'
     */
    public static function resolveExportMode(bool $printOnly, bool $enabled, string $pushgatewayUrl, bool $hasPcntl): string
    {
        return self::$useRustFfi
            ? self::resolveExportModeNative($printOnly, $enabled, $pushgatewayUrl, $hasPcntl)
            : self::resolveExportModePhp($printOnly, $enabled, $pushgatewayUrl, $hasPcntl);
    }

    /**
     * @return 'print'|'disabled'|'push'|'push_with_alarm'
     */
    public static function resolveExportModePhp(bool $printOnly, bool $enabled, string $pushgatewayUrl, bool $hasPcntl): string
    {
        if ($printOnly) {
            return 'print';
        }

        if (! $enabled || trim($pushgatewayUrl) === '') {
            return 'disabled';
        }

        return $hasPcntl ? 'push_with_alarm' : 'push';
    }

    /**
     * @return 'print'|'disabled'|'push'|'push_with_alarm'
     */
    public static function resolveExportModeNative(bool $printOnly, bool $enabled, string $pushgatewayUrl, bool $hasPcntl): string
    {
        // @phpstan-ignore method.notFound
        $code = self::callNative(static fn (FFI $ffi): mixed => $ffi->nntmux_export_monitoring_metrics_resolve_export_mode(
            $printOnly ? 1 : 0,
            $enabled ? 1 : 0,
            $pushgatewayUrl,
            strlen($pushgatewayUrl),
            $hasPcntl ? 1 : 0
        ));

        return is_int($code) && isset(self::EXPORT_MODES[$code])
            ? self::EXPORT_MODES[$code]
            : self::resolveExportModePhp($printOnly, $enabled, $pushgatewayUrl, $hasPcntl);
    }

    public static function formatExportFailureMessage(string $message): string
    {
        return self::$useRustFfi ? self::formatExportFailureMessageNative($message) : self::formatExportFailureMessagePhp($message);
    }

    public static function formatExportFailureMessagePhp(string $message): string
    {
        return "Monitoring metrics export failed: {$message}";
    }

    public static function formatExportFailureMessageNative(string $message): string
    {
        $cap = strlen($message) + 40;
        $result = self::callNative(static function (FFI $ffi) use ($message, $cap): ?string {
            $out = $ffi->new("uint8_t[{$cap}]");
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_export_monitoring_metrics_format_failure_message($message, strlen($message), $out, $cap);

            return is_int($written) && $written >= 0 && $written <= $cap ? FFI::string($out, $written) : null;
        });

        return is_string($result) ? $result : self::formatExportFailureMessagePhp($message);
    }

    public function handle(PrometheusMetricsExporter $exporter): int
    {
        $scheduled = (bool) $this->option('scheduled');
        $mode = self::resolveExportMode(
            (bool) $this->option('print'),
            (bool) config('monitoring.enabled'),
            self::PUSHGATEWAY_URL_CHECKED_BY_EXPORTER,
            $scheduled && function_exists('pcntl_alarm')
        );

        if ($mode === 'print') {
            $this->output->write($exporter->render());

            return self::SUCCESS;
        }

        if ($mode === 'disabled') {
            $this->components->info('Monitoring is disabled (MONITORING_ENABLED=false); nothing to export.');

            return self::SUCCESS;
        }

        $withAlarm = $mode === 'push_with_alarm';
        if ($scheduled && ! $withAlarm) {
            $this->components->error('Scheduled monitoring exports require the PCNTL extension for their runtime limit.');

            return self::FAILURE;
        }

        $previousHandler = null;
        if ($withAlarm) {
            $previousHandler = pcntl_signal_get_handler(SIGALRM);
            // The OS default terminates even when PHP is blocked inside database or Redis I/O.
            pcntl_signal(SIGALRM, SIG_DFL);
            pcntl_alarm(240);
        }

        try {
            if (! $exporter->push()) {
                $this->components->error('Could not push metrics to '.config('monitoring.pushgateway.url').'. See the application log for details.');

                return self::FAILURE;
            }

            return self::SUCCESS;
        } finally {
            if ($withAlarm) {
                pcntl_alarm(0);
                pcntl_signal(SIGALRM, $previousHandler);
            }
        }
    }

    /**
     * @param  callable(FFI): mixed  $call
     */
    private static function callNative(callable $call): mixed
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return null;
        }

        try {
            return $call($ffi);
        } catch (Throwable) {
            return null;
        }
    }

    private static function additionalFfi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
