<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\ReleaseNameFixed;
use App\Listeners\RecategorizeReleaseAfterNameFix;
use App\Models\AnidbInfo;
use App\Models\AnidbTitle;
use App\Models\BookInfo;
use App\Models\Category;
use App\Models\ConsoleInfo;
use App\Models\GamesInfo;
use App\Models\MovieInfo;
use App\Models\MusicInfo;
use App\Models\Release;
use App\Models\RolePromotion;
use App\Models\RootCategory;
use App\Models\SteamApp;
use App\Models\UsenetGroup;
use App\Models\User;
use App\Models\Video;
use App\Observers\AnidbInfoObserver;
use App\Observers\AnidbTitleObserver;
use App\Observers\BookInfoObserver;
use App\Observers\CategoryObserver;
use App\Observers\ConsoleInfoObserver;
use App\Observers\GamesInfoObserver;
use App\Observers\MovieInfoObserver;
use App\Observers\MusicInfoObserver;
use App\Observers\ReleaseObserver;
use App\Observers\RolePromotionObserver;
use App\Observers\RootCategoryObserver;
use App\Observers\SteamAppObserver;
use App\Observers\UsenetGroupObserver;
use App\Observers\VideoObserver;
use App\Services\LogViewer\Index\LogIndex;
use App\Services\Monitoring\Collectors\ApplicationCollector;
use App\Services\Monitoring\Collectors\DatabaseFingerprintCollector;
use App\Services\Monitoring\Collectors\FailedJobsCollector;
use App\Services\Monitoring\Collectors\HorizonCollector;
use App\Services\Monitoring\Collectors\MetricsCollector;
use App\Services\Monitoring\Collectors\ReleaseVerificationCollector;
use App\Services\Monitoring\Collectors\ServiceStatusCollector;
use App\Services\Monitoring\Collectors\TmuxSnapshotCollector;
use App\Services\Monitoring\Prometheus\TextFormatter;
use App\Services\Monitoring\PrometheusMetricsExporter;
use App\Services\Search\Support\ManticoreClientFactory;
use App\Services\Tmux\CollectionDowntimeCompensator;
use App\Services\Tmux\CollectionReconciliationBarrier;
use App\Services\Yenc\DecoderFactory;
use App\Services\YencService;
use App\Support\RustPipelineLibrary;
use App\View\Composers\AdminDataComposer;
use App\View\Composers\GlobalDataComposer;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Application;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use PragmaRX\Google2FALaravel\Listeners\LoginViaRemember;
use Symfony\Component\Console\Input\ArgvInput;

class AppServiceProvider extends ServiceProvider
{
    public const bool RUST_FFI_DEFAULT = false;

    public const string W376_FFI_CDEF = <<<'C'
        ssize_t nntmux_w376_app_rss_config_auth(uint8_t operation, const char *input, size_t input_len, char *out_buf, size_t out_cap);
        C;

    /** @var array<string, bool> Readonly-field and trait companion state. */
    private static array $w376Toggles = [];

    private static \FFI|false|null $w376Ffi = null;

    /** @internal State is keyed separately from existing auth FFI controls. */
    public static function w376Use(string $owner, ?bool $enabled): void
    {
        if ($enabled === null) {
            unset(self::$w376Toggles[$owner]);
            self::$w376Ffi = null;
        } else {
            self::$w376Toggles[$owner] = $enabled;
        }
    }

    /** @internal */
    public static function w376Enabled(string $owner): bool
    {
        return self::$w376Toggles[$owner] ?? self::RUST_FFI_DEFAULT;
    }

    public static function useRustFfi(?bool $enabled): void
    {
        self::w376Use(self::class, $enabled);
    }

    public static function usesRustFfi(): bool
    {
        return self::w376Enabled(self::class);
    }

    public static function resetRustFfi(): void
    {
        self::useRustFfi(null);
    }

    /** @internal Bounded byte strings, with u32 little-endian lengths. */
    public static function w376Frame(string ...$values): ?string
    {
        $size = 0;
        foreach ($values as $value) {
            $size += 4 + strlen($value);
            if ($size > 16_777_152) {
                return null;
            }
        }
        $frame = '';
        foreach ($values as $value) {
            $frame .= pack('V', strlen($value)).$value;
        }

        return $frame;
    }

    /** @internal Decode exactly one terminal length-prefixed byte string. */
    public static function w376ReadString(string $output, int $offset = 0): ?string
    {
        if ($offset < 0 || strlen($output) < $offset + 4) {
            return null;
        }
        $length = unpack('Vlength', substr($output, $offset, 4));
        if ($length === false || $length['length'] !== strlen($output) - $offset - 4) {
            return null;
        }

        return substr($output, $offset + 4);
    }

    /** @internal Shared bridge; every native failure leaves PHP available. */
    public static function w376Call(int $operation, string $input): ?string
    {
        if (strlen($input) > 16_777_216) {
            return null;
        }
        try {
            $ffi = self::$w376Ffi ??= RustPipelineLibrary::resolveDomain('additional_w376', self::W376_FFI_CDEF) ?? false;
            if ($ffi === false) {
                return null;
            }
            $capacity = 0;
            $output = RustPipelineLibrary::obtainScratch($ffi, 128, $capacity);
            if ($output === null) {
                return null;
            }
            $capacity = min($capacity, 16_777_216);
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w376_app_rss_config_auth($operation, $input, strlen($input), $output, $capacity);
            if (is_int($written) && $written < -1 && $written >= -16_777_216) {
                $required = -$written;
                $output = RustPipelineLibrary::obtainScratch($ffi, $required, $capacity);
                if ($output === null) {
                    return null;
                }
                $capacity = min($capacity, 16_777_216);
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w376_app_rss_config_auth($operation, $input, strlen($input), $output, $capacity);
            }
            if (! is_int($written) || $written < 1 || $written > $capacity) {
                return null;
            }

            return \FFI::string($output, $written);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{disable_pulse: bool} */
    public static function summarizePulseCommand(bool $console, bool $processingEnabled, ?string $command): array
    {
        return (self::usesRustFfi() ? self::summarizePulseCommandNative($console, $processingEnabled, $command) : null)
            ?? self::summarizePulseCommandPhp($console, $processingEnabled, $command);
    }

    /** @return array{disable_pulse: bool} */
    private static function summarizePulseCommandPhp(bool $console, bool $processingEnabled, ?string $command): array
    {
        return ['disable_pulse' => $console && ! $processingEnabled && $command !== null
            && preg_match('/^(?:tmux|multiprocessing|postprocess|update|releases|binaries|articles|backfill|group|predb):/', $command) === 1];
    }

    /** @return array{disable_pulse: bool}|null */
    private static function summarizePulseCommandNative(bool $console, bool $processingEnabled, ?string $command): ?array
    {
        $frame = self::w376Frame($command ?? '');
        if ($frame === null) {
            return null;
        }
        $output = self::w376Call(1, chr((int) $console).chr((int) $processingEnabled).$frame);

        return $output === "\0" || $output === "\1" ? ['disable_pulse' => $output === "\1"] : null;
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useTailwind();

        // Share public-site global data with layouts that need menu/category data.
        // search.* needs direct registration because @section blocks are evaluated
        // before the layout composer runs.
        view()->composer([
            'layouts.main',
            'layouts.guest',
            'layouts.app',
            'search.*',
        ], GlobalDataComposer::class);

        // Admin pages use a leaner composer and do not need public-site menu data.
        view()->composer([
            'layouts.admin',
            'admin.*',
        ], AdminDataComposer::class);

        Gate::define('viewPulse', function (User $user) {
            return $user->hasRole('Admin');
        });
        Event::listen(Login::class, LoginViaRemember::class);
        Event::listen(ReleaseNameFixed::class, RecategorizeReleaseAfterNameFix::class);

        // Register observers
        RolePromotion::observe(RolePromotionObserver::class);
        MovieInfo::observe(MovieInfoObserver::class);
        Video::observe(VideoObserver::class);
        Release::observe(ReleaseObserver::class);
        Category::observe(CategoryObserver::class);
        RootCategory::observe(RootCategoryObserver::class);
        UsenetGroup::observe(UsenetGroupObserver::class);
        MusicInfo::observe(MusicInfoObserver::class);
        BookInfo::observe(BookInfoObserver::class);
        GamesInfo::observe(GamesInfoObserver::class);
        ConsoleInfo::observe(ConsoleInfoObserver::class);
        SteamApp::observe(SteamAppObserver::class);
        AnidbTitle::observe(AnidbTitleObserver::class);
        AnidbInfo::observe(AnidbInfoObserver::class);
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        if ($this->app->runningInConsole() && ! $this->app['config']->get('pulse.processing_enabled', false)) {
            $arguments = $_SERVER['argv'] ?? [];
            foreach ($arguments as $index => $argument) {
                if ($argument === '--env' && isset($arguments[$index + 1]) && ! str_starts_with($arguments[$index + 1], '-')) {
                    $arguments[$index] = '--env='.$arguments[$index + 1];
                    unset($arguments[$index + 1]);
                }
            }
            $command = (new ArgvInput(array_values($arguments)))->getFirstArgument();
            if ($command !== null && preg_match('/^(?:tmux|multiprocessing|postprocess|update|releases|binaries|articles|backfill|group|predb):/', $command) === 1) {
                $this->app['config']->set('pulse.enabled', false);
            }
        }

        $this->app->alias(CollectionDowntimeCompensator::class, CollectionReconciliationBarrier::class);

        $this->app->singleton(YencService::class, function (Application $app): YencService {
            $decoder = (new DecoderFactory($app['log']))->make(
                (string) $app['config']->get('yenc.decoder', 'auto'),
                (string) $app['config']->get('yenc.native_library', ''),
            );

            return new YencService($decoder);
        });

        $this->app->tag([
            ApplicationCollector::class,
            TmuxSnapshotCollector::class,
            HorizonCollector::class,
            DatabaseFingerprintCollector::class,
            FailedJobsCollector::class,
            ReleaseVerificationCollector::class,
            ServiceStatusCollector::class,
        ], MetricsCollector::TAG);

        $this->app->bind(PrometheusMetricsExporter::class, static fn (Application $app): PrometheusMetricsExporter => new PrometheusMetricsExporter(
            $app->tagged(MetricsCollector::TAG),
            $app->make(TextFormatter::class),
        ));

        $this->app->singleton(LogIndex::class, static fn (Application $app): LogIndex => new LogIndex(
            ManticoreClientFactory::make((array) $app['config']->get('search.drivers.manticore', [])),
            (string) $app['config']->get('nntmux.log_viewer.index.table', 'app_logs_rt'),
            (bool) $app['config']->get('nntmux.log_viewer.index.enabled', true),
        ));
    }
}
