<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Search\Contracts\SearchDriverInterface;
use App\Services\Search\Contracts\SearchServiceInterface;
use App\Services\Search\Drivers\ElasticSearchDriver;
use App\Services\Search\Drivers\ManticoreSearchDriver;
use App\Services\Search\MediaSearchService;
use App\Services\Search\SearchService;
use App\Services\Search\Support\ElasticsearchClientFactory;
use App\Services\Search\Support\SearchFailureTracker;
use App\Support\RustPipelineLibrary;
use Elastic\Elasticsearch\Client;
use FFI;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Throwable;

class SearchServiceProvider extends ServiceProvider
{
    public const string FFI_CDEF = 'ptrdiff_t nntmux_w35_search_provider_facade_facets(uint32_t mode, const char *input, size_t input_len, char *out, size_t cap);';

    /** Drivers {@see SearchService} can create; any other configured name resolves to the fallback. */
    public const array SUPPORTED_DRIVERS = ['manticore', 'elasticsearch'];

    private const string FALLBACK_DRIVER = 'manticore';

    private const array ALIAS_KEYS = ['default', 'search', 'search.manticore', 'search.elasticsearch'];

    private const int NATIVE_MAX_BYTES = 16777216;

    private const int NATIVE_MAX_PARTS = 64;

    /**
     * Rust stays opt-in: the FFI round trip is slower than the PHP helper (< 1.5x); see
     * tests/Unit/RustSearchProviderFacadeFacetsParityTest.php.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Merge the search configuration
        $this->mergeConfigFrom(
            base_path('config/search.php'),
            'search'
        );

        // Register the SearchService as a singleton (Manager pattern)
        $this->app->singleton(SearchService::class, function (Application $app) {
            return new SearchService($app);
        });

        // Bind the interface to the SearchService
        $this->app->singleton(SearchServiceInterface::class, function (Application $app) {
            return $app->make(SearchService::class);
        });

        // Bind SearchDriverInterface to current driver
        $this->app->bind(SearchDriverInterface::class, function (Application $app) {
            return $app->make(SearchService::class)->driver();
        });

        // Per-request record of failed search queries (see ApiReleaseRowCache)
        $this->app->scoped(SearchFailureTracker::class);

        // Register the MediaSearchService for optimized movie/TV searches
        $this->app->singleton(MediaSearchService::class, function (Application $app) {
            return new MediaSearchService;
        });

        // Register individual drivers as singletons for direct access
        $this->app->singleton(ManticoreSearchDriver::class, function (Application $app) {
            $config = config('search.drivers.manticore', []);

            return new ManticoreSearchDriver($config);
        });

        $this->app->singleton(ElasticSearchDriver::class, function (Application $app) {
            $config = config('search.drivers.elasticsearch', []);

            return new ElasticSearchDriver($config);
        });

        $this->app->singleton('elasticsearch', function (): Client {
            $config = config('search.drivers.elasticsearch', []);

            return ElasticsearchClientFactory::make($config);
        });

        // Register aliases (using prefixed names to avoid conflicts with other packages)
        $this->app->alias(SearchService::class, 'search');
        $this->app->alias(ManticoreSearchDriver::class, 'search.manticore');
        $this->app->alias(ElasticSearchDriver::class, 'search.elasticsearch');
        $this->app->alias(MediaSearchService::class, 'search.media');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    /**
     * Which driver each container alias resolves to; the default is trimmed, lowercased and limited
     * to the supported drivers.
     *
     * @return array{default: string, search: string, 'search.manticore': string, 'search.elasticsearch': string}
     */
    public static function normalizeDriverAliasMap(string $defaultDriver): array
    {
        return self::usesRustFfi() ? self::normalizeDriverAliasMapNative($defaultDriver) : self::normalizeDriverAliasMapPhp($defaultDriver);
    }

    /**
     * @return array{default: string, search: string, 'search.manticore': string, 'search.elasticsearch': string}
     */
    public static function normalizeDriverAliasMapPhp(string $defaultDriver): array
    {
        $driver = strtolower(trim($defaultDriver));
        if (! \in_array($driver, self::SUPPORTED_DRIVERS, true)) {
            $driver = self::FALLBACK_DRIVER;
        }

        return [
            'default' => $driver,
            'search' => $driver,
            'search.manticore' => 'manticore',
            'search.elasticsearch' => 'elasticsearch',
        ];
    }

    /**
     * @return array{default: string, search: string, 'search.manticore': string, 'search.elasticsearch': string}
     */
    public static function normalizeDriverAliasMapNative(string $defaultDriver): array
    {
        $result = self::evaluateNative(1, [$defaultDriver]);
        if ($result !== null && \count($result) === 8
            && [$result[0], $result[2], $result[4], $result[6]] === self::ALIAS_KEYS
            && \in_array($result[1], self::SUPPORTED_DRIVERS, true) && $result[3] === $result[1]
            && $result[5] === 'manticore' && $result[7] === 'elasticsearch') {
            return [
                'default' => $result[1],
                'search' => $result[3],
                'search.manticore' => $result[5],
                'search.elasticsearch' => $result[7],
            ];
        }

        return self::normalizeDriverAliasMapPhp($defaultDriver);
    }

    /**
     * Shared Wave 35.5 bridge: count- and length-prefixed (little-endian u32) packets preserve PHP byte
     * strings. Returns the output parts, or null when Rust declined the input or is unavailable.
     *
     * @param  list<string>  $parts
     * @return list<string>|null
     */
    public static function evaluateNative(int $mode, array $parts): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null || $mode < 0 || \count($parts) > self::NATIVE_MAX_PARTS) {
            return null;
        }

        try {
            $input = pack('V', \count($parts));
            foreach ($parts as $part) {
                $input .= pack('V', \strlen($part)).$part;
            }
            if (\strlen($input) > self::NATIVE_MAX_BYTES) {
                return null;
            }

            $minBytes = \strlen($input) * 2 + 256;
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $capacity = 0;
                $out = RustPipelineLibrary::obtainScratch($ffi, $minBytes, $capacity);
                if ($out === null) {
                    return null;
                }
                $capacity = min($capacity, self::NATIVE_MAX_BYTES);
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w35_search_provider_facade_facets($mode, $input, \strlen($input), $out, $capacity);
                if (! \is_int($written)) {
                    return null;
                }
                if ($written >= 4 && $written <= $capacity) {
                    return self::unpackNative(FFI::string($out, $written));
                }
                if ($written > -4 || -$written <= $capacity || -$written > self::NATIVE_MAX_BYTES) {
                    return null;
                }
                $minBytes = -$written;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /** @return list<string>|null */
    private static function unpackNative(string $packet): ?array
    {
        $length = \strlen($packet);
        $header = unpack('V', $packet);
        $count = \is_array($header) ? $header[1] : null;
        if (! \is_int($count) || $count > self::NATIVE_MAX_PARTS || $count > intdiv($length - 4, 4)) {
            return null;
        }

        $offset = 4;
        $parts = [];
        for ($i = 0; $i < $count; $i++) {
            if ($offset + 4 > $length) {
                return null;
            }
            $size = unpack('V', $packet, $offset);
            $size = \is_array($size) ? $size[1] : null;
            $offset += 4;
            if (! \is_int($size) || $size > $length - $offset) {
                return null;
            }
            $parts[] = substr($packet, $offset, $size);
            $offset += $size;
        }

        return $offset === $length ? $parts : null;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            self::$ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
        }

        return self::$ffi instanceof FFI ? self::$ffi : null;
    }
}
