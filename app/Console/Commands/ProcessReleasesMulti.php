<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ForkingService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Throwable;

class ProcessReleasesMulti extends Command
{
    public const string FFI_CDEF = <<<'CDEF'
        int32_t nntmux_cat_mac_release_plan(int64_t configured_threads, int64_t *out_max_processes);
        CDEF;

    /** Rust stays opt-in: the FFI round trip is slower than this PHP clamp (< 1.5x). */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'multiprocessing:releases';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create releases, delete unwanted releases, and categorize using multiprocessing';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            (new ForkingService)->releases();

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Overrides the PHP default for this command's helpers; `null` restores the default.
     */
    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    /**
     * The worker cap the release runners apply to the configured release thread count
     * (`max(1, $threads)`), and whether the configured count was raised to that floor.
     *
     * @return array{configuredThreads: int, maxProcesses: int, clamped: bool}
     */
    public static function buildMultiReleaseExecutionPlan(int $configuredThreads): array
    {
        return self::usesRustFfi()
            ? self::buildMultiReleaseExecutionPlanNative($configuredThreads)
            : self::buildMultiReleaseExecutionPlanPhp($configuredThreads);
    }

    /**
     * @return array{configuredThreads: int, maxProcesses: int, clamped: bool}
     */
    public static function buildMultiReleaseExecutionPlanPhp(int $configuredThreads): array
    {
        return [
            'configuredThreads' => $configuredThreads,
            'maxProcesses' => max(1, $configuredThreads),
            'clamped' => $configuredThreads < 1,
        ];
    }

    /**
     * @return array{configuredThreads: int, maxProcesses: int, clamped: bool}
     */
    public static function buildMultiReleaseExecutionPlanNative(int $configuredThreads): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::buildMultiReleaseExecutionPlanPhp($configuredThreads);
        }

        try {
            $out = $ffi->new('int64_t');
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_cat_mac_release_plan($configuredThreads, FFI::addr($out));
            if ($rc !== 0 && $rc !== 1) {
                return self::buildMultiReleaseExecutionPlanPhp($configuredThreads);
            }

            return [
                'configuredThreads' => $configuredThreads,
                // @phpstan-ignore property.notFound
                'maxProcesses' => (int) $out->cdata,
                'clamped' => $rc === 1,
            ];
        } catch (Throwable) {
            return self::buildMultiReleaseExecutionPlanPhp($configuredThreads);
        }
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        try {
            $ffi = RustPipelineLibrary::resolveDomain('categorize', self::FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        } catch (Throwable) {
            $ffi = null;
        }
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
