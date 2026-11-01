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

    /**
     * Enabled: the paired benchmark in the W57 parity test measures 4.629x common mean, 4.44x
     * common p99, 2.844x uncommon mean and 1.975x uncommon p99 (per-metric minimum of four runs),
     * above the 1.5x rule.
     */
    private const bool W57_RUST_FFI_DEFAULT = true;

    private const string W57_FFI_CDEF = 'ssize_t nntmux_w571_config_value_batch(const char *rows, size_t rows_len, size_t count, int64_t *out, size_t out_cap);';

    public const int W57_VALUE_WORDS = 2;

    public const int W57_VALUE_EMPTY = 0;

    public const int W57_VALUE_TRUE = 1;

    public const int W57_VALUE_FALSE = 2;

    public const int W57_VALUE_INT = 3;

    public const int W57_VALUE_FLOAT = 4;

    public const int W57_VALUE_JSON = 5;

    public const int W57_VALUE_STRING = 6;

    public const int W57_VALUE_TRIMMED = 16;

    public const int W57_VALUE_KEY = 32;

    public const int W57_VALUE_NON_ASCII = 64;

    public const int W57_VALUE_DOMAIN_SHIFT = 8;

    public const int W57_VALUE_LENGTH_SHIFT = 12;

    public static function usesW57RustFfi(): bool
    {
        $enabled = self::w57RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W57_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 57 batch routed to Rust or PHP, then restores the routing. */
    public static function withW57RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w57RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w57RustFfiState('set', $previous);
        }
    }

    /**
     * Two words per configuration value over `trim($value)`: its kind (W57_VALUE_EMPTY, an
     * 18-digit INT, a 15.15-digit FLOAT, TRUE/FALSE for true|yes|on / false|no|off in any case,
     * a JSON-shaped `{...}`/`[...]`, else STRING) plus W57_VALUE_TRIMMED, W57_VALUE_KEY (a dotted
     * lowercase key), W57_VALUE_NON_ASCII, its ConfigurationDomain index + 1 (0 none)
     * `<< W57_VALUE_DOMAIN_SHIFT` and its length `<< W57_VALUE_LENGTH_SHIFT`; then the coerced
     * integer (the INT value, the FLOAT integer part, 1/0 for TRUE/FALSE, else 0).
     *
     * @param  array<array-key, string>  $values
     * @return list<int>
     */
    public static function valueCodesBatch(array $values): array
    {
        return self::usesW57RustFfi() ? self::valueCodesBatchNative($values) : self::valueCodesBatchPhp($values);
    }

    /**
     * @param  array<array-key, string>  $values
     * @return list<int>
     */
    public static function valueCodesBatchPhp(array $values): array
    {
        static $domains = null;
        $domains ??= array_flip(array_map(static fn (ConfigurationDomain $domain): string => $domain->value, ConfigurationDomain::cases()));
        $words = [];
        foreach ($values as $value) {
            $trimmed = trim($value);
            $lower = strtolower($trimmed);
            $int = 0;
            if ($trimmed === '') {
                $kind = self::W57_VALUE_EMPTY;
            } elseif (preg_match('/\A[+-]?[0-9]{1,18}\z/', $trimmed) === 1) {
                $kind = self::W57_VALUE_INT;
                $int = (int) $trimmed;
            } elseif (preg_match('/\A[+-]?(?:[0-9]{1,15}\.[0-9]{0,15}|\.[0-9]{1,15})\z/', $trimmed) === 1) {
                $kind = self::W57_VALUE_FLOAT;
                $int = (int) substr($trimmed, 0, (int) strpos($trimmed, '.'));
            } else {
                $kind = match ($lower) {
                    'true', 'yes', 'on' => self::W57_VALUE_TRUE,
                    'false', 'no', 'off' => self::W57_VALUE_FALSE,
                    default => ($trimmed[0] === '{' && $trimmed[-1] === '}') || ($trimmed[0] === '[' && $trimmed[-1] === ']') ? self::W57_VALUE_JSON : self::W57_VALUE_STRING,
                };
                $int = $kind === self::W57_VALUE_TRUE ? 1 : 0;
            }
            $words[] = $kind
                | ($trimmed !== $value ? self::W57_VALUE_TRIMMED : 0)
                | (preg_match('/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*\z/', $trimmed) === 1 ? self::W57_VALUE_KEY : 0)
                | (preg_match('/[\x80-\xff]/', $trimmed) === 1 ? self::W57_VALUE_NON_ASCII : 0)
                | (($domains[$lower] ?? -1) + 1) << self::W57_VALUE_DOMAIN_SHIFT
                | \strlen($trimmed) << self::W57_VALUE_LENGTH_SHIFT;
            $words[] = $int;
        }

        return $words;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or declines the batch (a value
     * holding a NUL byte).
     *
     * @param  array<array-key, string>  $values
     * @return list<int>
     */
    public static function valueCodesBatchNative(array $values): array
    {
        $count = \count($values);
        $words = $count * self::W57_VALUE_WORDS;
        /** @var array{0: FFI, 1: CData, 2: int}|null $buffer */
        $buffer = $count === 0 ? null : self::w57RustFfiState('out', $words);
        if ($buffer === null) {
            return self::valueCodesBatchPhp($values);
        }
        [$ffi, $out, $cap] = $buffer;
        $joined = implode("\0", $values);
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w571_config_value_batch($joined, \strlen($joined), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        $codes = $written === $count ? unpack('P*', FFI::string($out, $words * 8)) : false;
        if (! \is_array($codes)) {
            return self::valueCodesBatchPhp($values);
        }

        /** @var list<int> */
        return array_values($codes);
    }

    /**
     * Wave 57 routing override (`set`) plus the lazily resolved binding and reusable `int64_t`
     * output buffer (`out`, sized for `$val` words), kept in function-local statics.
     */
    private static function w57RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'out':
                $ffi ??= RustPipelineLibrary::resolveDomain('configuration_provider_w57', self::W57_FFI_CDEF) ?? false;
                if (! $ffi instanceof FFI || ! \is_int($val) || $val < 1) {
                    return null;
                }
                if (! $out instanceof CData || $outCap < $val) {
                    $outCap = max(1024, $val * 2);
                    $out = $ffi->new("int64_t[{$outCap}]");
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled;
        }
    }
}
