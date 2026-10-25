<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\TvProcessing\TvProcessingPipeline;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\ServiceProvider;
use Throwable;

class TvProcessingServiceProvider extends ServiceProvider
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w364_tv_provider_pipeline_order(
            size_t name_count,
            uint32_t include_fallback,
            const char *input_ptr,
            size_t input_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /** Default lookup order of the metadata providers in TvProcessingPipeline::createDefault(). */
    public const array PROVIDER_PIPELINE_ORDER = ['local', 'tvdb', 'tvmaze', 'tmdb', 'trakt'];

    private const array PROVIDER_ALIASES = ['localdb' => 'local', 'thetvdb' => 'tvdb', 'themoviedb' => 'tmdb'];

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustTvForumRoutePrivacyTermsParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private const int MAX_PROVIDER_NAMES = 4096;

    private const int MAX_INPUT = 16_777_216;

    private const string FIELD_SEPARATOR = "\x1F";

    private const string NULL_MARK = "\x1E";

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * Register services.
     */
    public function register(): void
    {
        // A fresh pipeline per resolve: pipes and providers hold per-run state.
        $this->app->bind(TvProcessingPipeline::class, static fn (): TvProcessingPipeline => TvProcessingPipeline::createDefault(
            (bool) config('nntmux.echocli')
        ));
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * Normalizes a configured provider order: names are lower-cased with every non `[a-z0-9]`
     * byte removed and aliases resolved; unknown names are reported in `ignored`, repeats are
     * counted and dropped, non-string entries are skipped and, with `$includeFallback`, missing
     * providers are appended in default order.
     *
     * @param  array<mixed>  $providerNames
     * @return array{providers: list<string>, ignored: list<string>, duplicates: int, fallback_added: int}
     */
    public static function normalizeProviderPipelineOrder(array $providerNames, bool $includeFallback = true): array
    {
        return self::usesRustFfi()
            ? self::normalizeProviderPipelineOrderNative($providerNames, $includeFallback)
            : self::normalizeProviderPipelineOrderPhp($providerNames, $includeFallback);
    }

    /**
     * @param  array<mixed>  $providerNames
     * @return array{providers: list<string>, ignored: list<string>, duplicates: int, fallback_added: int}
     */
    public static function normalizeProviderPipelineOrderNative(array $providerNames, bool $includeFallback = true): array
    {
        $names = self::stringProviderNames($providerNames);
        $input = implode(self::FIELD_SEPARATOR, $names);
        if (count($names) > self::MAX_PROVIDER_NAMES || strlen($input) > self::MAX_INPUT) {
            return self::normalizeProviderPipelineOrderPhp($providerNames, $includeFallback);
        }
        $fields = self::callNative([count($names), $includeFallback ? 1 : 0, $input, strlen($input)]);
        if ($fields === null || count($fields) < 3 || in_array(null, $fields, true)) {
            return self::normalizeProviderPipelineOrderPhp($providerNames, $includeFallback);
        }
        /** @var list<string> $fields */
        $providerCount = (int) $fields[2];
        if ($providerCount > count($fields) - 3) {
            return self::normalizeProviderPipelineOrderPhp($providerNames, $includeFallback);
        }

        return [
            'providers' => array_slice($fields, 3, $providerCount),
            'ignored' => array_slice($fields, 3 + $providerCount),
            'duplicates' => (int) $fields[0],
            'fallback_added' => (int) $fields[1],
        ];
    }

    /**
     * @param  array<mixed>  $providerNames
     * @return array{providers: list<string>, ignored: list<string>, duplicates: int, fallback_added: int}
     */
    public static function normalizeProviderPipelineOrderPhp(array $providerNames, bool $includeFallback = true): array
    {
        $providers = [];
        $ignored = [];
        $duplicates = 0;
        foreach (self::stringProviderNames($providerNames) as $name) {
            $key = preg_replace('/[^a-z0-9]+/', '', strtolower($name)) ?? '';
            if ($key === '') {
                continue;
            }
            $key = self::PROVIDER_ALIASES[$key] ?? $key;
            if (! in_array($key, self::PROVIDER_PIPELINE_ORDER, true)) {
                $ignored[] = $key;
            } elseif (in_array($key, $providers, true)) {
                $duplicates++;
            } else {
                $providers[] = $key;
            }
        }
        $added = 0;
        if ($includeFallback) {
            foreach (self::PROVIDER_PIPELINE_ORDER as $provider) {
                if (! in_array($provider, $providers, true)) {
                    $providers[] = $provider;
                    $added++;
                }
            }
        }

        return ['providers' => $providers, 'ignored' => $ignored, 'duplicates' => $duplicates, 'fallback_added' => $added];
    }

    /**
     * @param  array<mixed>  $providerNames
     * @return list<string>
     */
    private static function stringProviderNames(array $providerNames): array
    {
        return array_values(array_filter($providerNames, is_string(...)));
    }

    /**
     * @param  list<int|string>  $args
     * @return list<?string>|null
     */
    private static function callNative(array $args): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 1024, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w364_tv_provider_pipeline_order(...[...$args, $buffer, $capacity]);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w364_tv_provider_pipeline_order(...[...$args, $buffer, $capacity]);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return array_map(
                static fn (string $field): ?string => $field === self::NULL_MARK ? null : $field,
                explode(self::FIELD_SEPARATOR, FFI::string($buffer, $written)),
            );
        } catch (Throwable) {
            return null;
        }
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }
}
