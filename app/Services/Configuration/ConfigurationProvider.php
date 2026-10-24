<?php

declare(strict_types=1);

namespace App\Services\Configuration;

use App\Enums\ConfigurationDomain;
use App\Models\IngestionConfiguration;
use App\Models\MetadataConfiguration;
use App\Models\PostProcessingConfiguration;
use App\Models\RegistrationConfiguration;
use App\Models\SiteConfiguration;
use App\Models\TmuxConfiguration;
use App\Support\Configuration\IngestionConfigurationData;
use App\Support\Configuration\MetadataConfigurationData;
use App\Support\Configuration\PostProcessingConfigurationData;
use App\Support\Configuration\RegistrationConfigurationData;
use App\Support\Configuration\SiteConfigurationData;
use App\Support\Configuration\TmuxConfigurationData;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ConfigurationProvider
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_cfgd_text(const char *ptr, size_t len, int32_t field, char *out, size_t cap);
        C;

    private const int CACHE_TTL_SECONDS = 300;

    // PHP remains the production default until benchmarks qualify Rust.
    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    private static ?CData $textBuffer = null;

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use;
        if ($use === null) {
            self::$ffi = null;
            self::$textBuffer = null;
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::$ffi = $override;
        self::$textBuffer = null;
    }

    /**
     * Cache key holding the resolved configuration for `$domain`.
     */
    public static function cacheKeyFor(ConfigurationDomain $domain): string
    {
        return self::usesRustFfi() ? self::cacheKeyForNative($domain) : self::cacheKeyForPhp($domain);
    }

    public static function cacheKeyForPhp(ConfigurationDomain $domain): string
    {
        return 'configuration:'.$domain->value;
    }

    public static function cacheKeyForNative(ConfigurationDomain $domain): string
    {
        return self::textNative($domain, ConfigurationDomain::TEXT_CACHE_KEY) ?? self::cacheKeyForPhp($domain);
    }

    /**
     * Table whose presence gates the persisted configuration for `$domain`.
     */
    public static function tableFor(ConfigurationDomain $domain): string
    {
        return self::usesRustFfi() ? self::tableForNative($domain) : self::tableForPhp($domain);
    }

    public static function tableForPhp(ConfigurationDomain $domain): string
    {
        return $domain->tablePhp();
    }

    public static function tableForNative(ConfigurationDomain $domain): string
    {
        return self::textNative($domain, ConfigurationDomain::TEXT_TABLE) ?? self::tableForPhp($domain);
    }

    public function site(bool $fresh = false): SiteConfigurationData
    {
        if (! $this->tableExists(self::tableFor(ConfigurationDomain::Site))) {
            return SiteConfigurationData::defaults();
        }

        return $this->resolve(ConfigurationDomain::Site, $fresh, SiteConfigurationData::class, static fn (): SiteConfigurationData => SiteConfigurationData::fromModel(SiteConfiguration::singleton()));
    }

    public function registration(bool $fresh = false): RegistrationConfigurationData
    {
        if (! $this->tableExists(self::tableFor(ConfigurationDomain::Registration))) {
            return RegistrationConfigurationData::defaults();
        }

        return $this->resolve(ConfigurationDomain::Registration, $fresh, RegistrationConfigurationData::class, static fn (): RegistrationConfigurationData => RegistrationConfigurationData::fromModel(RegistrationConfiguration::singleton()));
    }

    public function ingestion(bool $fresh = false): IngestionConfigurationData
    {
        if (! $this->tableExists(self::tableFor(ConfigurationDomain::Ingestion))) {
            return IngestionConfigurationData::defaults();
        }

        return $this->resolve(ConfigurationDomain::Ingestion, $fresh, IngestionConfigurationData::class, static fn (): IngestionConfigurationData => IngestionConfigurationData::fromModel(IngestionConfiguration::singleton()));
    }

    public function postProcessing(bool $fresh = false): PostProcessingConfigurationData
    {
        if (! $this->tableExists(self::tableFor(ConfigurationDomain::PostProcessing))) {
            return PostProcessingConfigurationData::defaults();
        }

        return $this->resolve(ConfigurationDomain::PostProcessing, $fresh, PostProcessingConfigurationData::class, static fn (): PostProcessingConfigurationData => PostProcessingConfigurationData::fromModel(PostProcessingConfiguration::singleton()));
    }

    public function metadata(bool $fresh = false): MetadataConfigurationData
    {
        if (! $this->tableExists(self::tableFor(ConfigurationDomain::Metadata))) {
            return MetadataConfigurationData::defaults();
        }

        return $this->resolve(ConfigurationDomain::Metadata, $fresh, MetadataConfigurationData::class, static fn (): MetadataConfigurationData => MetadataConfigurationData::fromModel(MetadataConfiguration::singleton()));
    }

    public function tmux(bool $fresh = false): TmuxConfigurationData
    {
        if (! $this->tableExists(self::tableFor(ConfigurationDomain::Tmux))) {
            return TmuxConfigurationData::defaults();
        }

        return $this->resolve(ConfigurationDomain::Tmux, $fresh, TmuxConfigurationData::class, static fn (): TmuxConfigurationData => TmuxConfigurationData::fromModel(TmuxConfiguration::query()->with(['cleanupRules', 'colorExclusions'])->findOrFail(TmuxConfiguration::SINGLETON_ID)));
    }

    public function forget(ConfigurationDomain $domain): void
    {
        try {
            Cache::forget($this->cacheKey($domain));
        } catch (Throwable $throwable) {
            if (config('app.debug')) {
                Log::debug('Configuration cache invalidation bypassed: '.$throwable->getMessage());
            }
        }
    }

    /**
     * @template TValue of object
     *
     * @param  class-string<TValue>  $type
     * @param  callable(): TValue  $resolver
     * @return TValue
     */
    private function resolve(ConfigurationDomain $domain, bool $fresh, string $type, callable $resolver): object
    {
        if ($fresh) {
            return $resolver();
        }

        try {
            $cached = Cache::get($this->cacheKey($domain));
            if ($cached instanceof $type) {
                return $cached;
            }

            $value = $resolver();
            Cache::put($this->cacheKey($domain), $value, self::CACHE_TTL_SECONDS);

            return $value;
        } catch (Throwable $throwable) {
            if (config('app.debug')) {
                Log::debug('Configuration cache bypassed: '.$throwable->getMessage());
            }

            return $resolver();
        }
    }

    private function cacheKey(ConfigurationDomain $domain): string
    {
        return self::cacheKeyFor($domain);
    }

    private function tableExists(string $table): bool
    {
        return Schema::hasTable($table);
    }

    private static function textNative(ConfigurationDomain $domain, int $field): ?string
    {
        if (($ffi = self::ffi()) === null) {
            return null;
        }
        try {
            self::$textBuffer ??= $ffi->new('char[64]');
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_cfgd_text($domain->value, \strlen($domain->value), $field, self::$textBuffer, 64);
            if (! \is_int($written) || $written <= 0) {
                return null;
            }

            return FFI::string(self::$textBuffer, $written);
        } catch (Throwable) {
            return null;
        }
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }
}
