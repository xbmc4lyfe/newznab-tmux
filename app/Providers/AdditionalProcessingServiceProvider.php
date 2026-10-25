<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\AdditionalProcessing\AdditionalProcessingOrchestrator;
use App\Services\AdditionalProcessing\AdditionalWorkPlanner;
use App\Services\AdditionalProcessing\ArchiveExtractionService;
use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\ConsoleOutputService;
use App\Services\AdditionalProcessing\Media\MediaSourceResolver;
use App\Services\AdditionalProcessing\Media\MediaToolFactory;
use App\Services\AdditionalProcessing\MediaExtractionService;
use App\Services\AdditionalProcessing\NzbContentParser;
use App\Services\AdditionalProcessing\ReleaseFileManager;
use App\Services\AdditionalProcessing\ReleaseFilesArchiveFallback;
use App\Services\AdditionalProcessing\ReleaseProcessor;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\AdditionalProcessing\UsenetDownloadService;
use App\Services\Categorization\CategorizationService;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\NfoService;
use App\Services\Nzb\NzbParserService;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseExtraService;
use App\Services\ReleaseImageService;
use App\Services\TempWorkspaceService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Service provider for Additional Processing services.
 * Registers all the refactored processing services with proper dependency injection.
 */
class AdditionalProcessingServiceProvider extends ServiceProvider
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w356_dto_provider(uint8_t operation, const char *input, size_t input_len, char *output, size_t output_capacity);
        C;

    // PHP remains the default until the complete caller clears the 1.5x gate.
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(?bool $enabled): void
    {
        self::$useRustFfi = $enabled;
        if ($enabled === null) {
            self::$ffi = null;
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    private static function rust(string $input, int $capacity): ?string
    {
        if (strlen($input) > 16_777_216 || $capacity < 1 || $capacity > 16_777_216) {
            return null;
        }
        try {
            self::$ffi ??= RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
            if (self::$ffi === false) {
                return null;
            }
            $output = self::$ffi->new("char[{$capacity}]");
            // @phpstan-ignore method.notFound
            $written = self::$ffi->nntmux_w356_dto_provider(5, $input, strlen($input), $output, $capacity);
            if (! is_int($written) || $written < 1 || $written > $capacity) {
                return null;
            }

            return FFI::string($output, $written);
        } catch (Throwable) {
            return null;
        }
    }

    /** Missing, negative and nonfinite timings contribute zero milliseconds. */
    public static function normalizeQueryDurationMs(?float $timeMs): float
    {
        return (self::usesRustFfi() ? self::normalizeQueryDurationMsNative($timeMs) : null)
            ?? self::normalizeQueryDurationMsPhp($timeMs);
    }

    public static function normalizeQueryDurationMsPhp(?float $timeMs): float
    {
        return $timeMs !== null && is_finite($timeMs) && $timeMs > 0.0 ? $timeMs : 0.0;
    }

    public static function normalizeQueryDurationMsNative(?float $timeMs): ?float
    {
        $bytes = self::rust(pack('d', $timeMs ?? NAN), 8);
        if ($bytes === null || strlen($bytes) !== 8) {
            return null;
        }
        /** @var array{time: float} $result */
        $result = unpack('dtime', $bytes);

        return $result['time'];
    }

    /**
     * Register services.
     */
    public function register(): void
    {
        // Configuration is a singleton since it loads settings once
        $this->app->singleton(ProcessingConfiguration::class, function () {
            return new ProcessingConfiguration;
        });

        $this->app->singleton(PersistenceMetricsCollector::class);
        $this->app->singleton(ReleaseSearchSyncCoordinator::class, function ($app) {
            return new ReleaseSearchSyncCoordinator(
                $app->make(PersistenceMetricsCollector::class),
            );
        });
        $this->app->bind(ReleaseUpdateService::class, function ($app) {
            return new ReleaseUpdateService(
                searchSyncCoordinator: $app->make(ReleaseSearchSyncCoordinator::class),
            );
        });

        // Release extra service for video/audio/subtitle data
        $this->app->singleton(ReleaseExtraService::class, function () {
            return new ReleaseExtraService;
        });

        // Console output service
        $this->app->singleton(ConsoleOutputService::class, function ($app) {
            $config = $app->make(ProcessingConfiguration::class);

            return new ConsoleOutputService($config->echoCLI);
        });

        // NZB content parser
        $this->app->singleton(NzbContentParser::class, function ($app) {
            $config = $app->make(ProcessingConfiguration::class);

            return new NzbContentParser(
                $app->make(NzbService::class),
                $app->make(NzbParserService::class),
                $config->debugMode,
                $config->echoCLI
            );
        });

        $this->app->singleton(AdditionalWorkPlanner::class, function ($app) {
            return new AdditionalWorkPlanner($app->make(ProcessingConfiguration::class));
        });

        // Archive extraction service
        $this->app->singleton(ArchiveExtractionService::class, function ($app) {
            return new ArchiveExtractionService(
                $app->make(ProcessingConfiguration::class)
            );
        });

        // Usenet download service
        $this->app->singleton(UsenetDownloadService::class, function ($app) {
            return new UsenetDownloadService(
                $app->make(ProcessingConfiguration::class)
            );
        });

        // Release file manager
        $this->app->singleton(ReleaseFileManager::class, function ($app) {
            return new ReleaseFileManager(
                $app->make(ProcessingConfiguration::class),
                $app->make(ReleaseImageService::class),
                new NfoService,
                $app->make(NzbService::class),
                new NameFixingService($app->make(ReleaseUpdateService::class)),
                $app->make(ReleaseUpdateService::class),
                searchSyncCoordinator: $app->make(ReleaseSearchSyncCoordinator::class),
            );
        });

        // Media extraction service
        $this->app->singleton(MediaExtractionService::class, function ($app) {
            return new MediaExtractionService(
                $app->make(ProcessingConfiguration::class),
                $app->make(ReleaseImageService::class),
                $app->make(ReleaseExtraService::class),
                new CategorizationService,
                $app->make(ReleaseSearchSyncCoordinator::class),
                $app->make(MediaToolFactory::class),
            );
        });

        $this->app->singleton(ReleaseFilesArchiveFallback::class, function ($app) {
            return new ReleaseFilesArchiveFallback(
                $app->make(ArchiveExtractionService::class),
                $app->make(MediaExtractionService::class),
                $app->make(UsenetDownloadService::class),
                $app->make(ReleaseFileManager::class),
                $app->make(ConsoleOutputService::class),
            );
        });

        $this->app->singleton(ReleaseProcessor::class, function ($app) {
            return new ReleaseProcessor(
                $app->make(ProcessingConfiguration::class),
                $app->make(NzbContentParser::class),
                $app->make(AdditionalWorkPlanner::class),
                $app->make(ArchiveExtractionService::class),
                $app->make(MediaExtractionService::class),
                $app->make(UsenetDownloadService::class),
                $app->make(ReleaseFileManager::class),
                $app->make(ReleaseFilesArchiveFallback::class),
                $app->make(TempWorkspaceService::class),
                $app->make(ConsoleOutputService::class),
                $app->make(ReleaseSearchSyncCoordinator::class),
                $app->make(PersistenceMetricsCollector::class),
                $app->make(MediaSourceResolver::class),
            );
        });

        $this->app->singleton(MediaToolFactory::class, function ($app) {
            return new MediaToolFactory($app->make(ProcessingConfiguration::class));
        });

        $this->app->singleton(MediaSourceResolver::class, function ($app) {
            return new MediaSourceResolver(
                $app->make(ProcessingConfiguration::class),
                $app->make(UsenetDownloadService::class),
            );
        });

        // Temp workspace service (might already be registered elsewhere)
        $this->app->singleton(TempWorkspaceService::class, function () {
            return new TempWorkspaceService;
        });

        // Main orchestrator
        $this->app->singleton(AdditionalProcessingOrchestrator::class, function ($app) {
            return new AdditionalProcessingOrchestrator(
                $app->make(ProcessingConfiguration::class),
                $app->make(ReleaseProcessor::class),
                $app->make(TempWorkspaceService::class),
                $app->make(ConsoleOutputService::class)
            );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(PersistenceMetricsCollector $persistenceMetrics): void
    {
        DB::listen(static function (QueryExecuted $query) use ($persistenceMetrics): void {
            $persistenceMetrics->recordDatabaseStatement(self::normalizeQueryDurationMs($query->time));
        });

        $this->app->terminating(function (): void {
            if ($this->app->resolved(AdditionalProcessingOrchestrator::class)) {
                $this->app->make(AdditionalProcessingOrchestrator::class)->finish();
            }
        });
    }
}
