<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SystemMetricsService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'metrics:collect')]
class CollectSystemMetrics extends Command
{
    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_collect_system_metrics_format_deleted_message(int64_t deleted, uint8_t *out_buf, size_t out_cap);
ssize_t nntmux_collect_system_metrics_format_error_message(
    const char *message_ptr,
    size_t message_len,
    uint8_t *out_buf,
    size_t out_cap
);
CDEF;

    /** Rust stays opt-in: formatting two status lines is too small for FFI to beat PHP by 1.5x. */
    private const RUST_FFI_DEFAULT = false;

    private static bool $useRustFfi = self::RUST_FFI_DEFAULT;

    private static FFI|false|null $ffi = null;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'metrics:collect {--cleanup : Clean up old metrics}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Collect and store current system metrics (CPU and RAM usage)';

    protected SystemMetricsService $metricsService;

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

    public static function formatDeletedMetricsMessage(int $deleted): string
    {
        return self::$useRustFfi ? self::formatDeletedMetricsMessageNative($deleted) : self::formatDeletedMetricsMessagePhp($deleted);
    }

    public static function formatDeletedMetricsMessagePhp(int $deleted): string
    {
        return "Deleted {$deleted} old metric records.";
    }

    public static function formatDeletedMetricsMessageNative(int $deleted): string
    {
        $cap = strlen((string) $deleted) + 32;
        $result = self::callNative(static function (FFI $ffi) use ($deleted, $cap): ?string {
            $out = $ffi->new("uint8_t[{$cap}]");
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_collect_system_metrics_format_deleted_message($deleted, $out, $cap);

            return is_int($written) && $written >= 0 && $written <= $cap ? FFI::string($out, $written) : null;
        });

        return is_string($result) ? $result : self::formatDeletedMetricsMessagePhp($deleted);
    }

    public static function formatErrorMessage(string $message): string
    {
        return self::$useRustFfi ? self::formatErrorMessageNative($message) : self::formatErrorMessagePhp($message);
    }

    public static function formatErrorMessagePhp(string $message): string
    {
        return "Failed to collect system metrics: {$message}";
    }

    public static function formatErrorMessageNative(string $message): string
    {
        $cap = strlen($message) + 40;
        $result = self::callNative(static function (FFI $ffi) use ($message, $cap): ?string {
            $out = $ffi->new("uint8_t[{$cap}]");
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_collect_system_metrics_format_error_message($message, strlen($message), $out, $cap);

            return is_int($written) && $written >= 0 && $written <= $cap ? FFI::string($out, $written) : null;
        });

        return is_string($result) ? $result : self::formatErrorMessagePhp($message);
    }

    /**
     * Create a new command instance.
     */
    public function __construct(SystemMetricsService $metricsService)
    {
        parent::__construct();
        $this->metricsService = $metricsService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            if ($this->option('cleanup')) {
                $this->info('Cleaning up old metrics...');
                $deleted = $this->metricsService->cleanupOldMetrics();
                $this->info(self::formatDeletedMetricsMessage($deleted));
            }

            $this->info('Collecting system metrics...');
            $this->metricsService->collectMetrics();
            $this->info('System metrics collected successfully.');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error(self::formatErrorMessage($e->getMessage()));

            return Command::FAILURE;
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
