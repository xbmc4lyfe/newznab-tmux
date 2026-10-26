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

    // ========================================================================
    // WAVE 67.1 BATCH HELPER
    // ========================================================================

    /** Benchmark-gated default; see RustXxxPipeCollectionCleanupCleaningConfigProviderRepoW67ParityTest. */
    public const bool W67_RUST_FFI_DEFAULT = true;

    public const string W67_CONFIGURATION_PROVIDER_FFI_CDEF = <<<'C'
        ssize_t nntmux_w671_configuration_provider_plan_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of w67ConfigurationProviderPlanBatch(). */
    public const int W67_CONFIGURATION_PROVIDER_TRAILER = 36;

    public static function usesW67RustFfi(): bool
    {
        $override = self::w67RustFfiState();

        return \is_bool($override) ? $override : self::W67_RUST_FFI_DEFAULT;
    }

    /** Forces the ConfigurationProvider Wave 67 Rust path on until reset. */
    public static function enableW67RustFfi(): void
    {
        self::w67RustFfiState('set', true);
    }

    /** Forces the ConfigurationProvider Wave 67 PHP path until reset. */
    public static function disableW67RustFfi(): void
    {
        self::w67RustFfiState('set', false);
    }

    /** Restores the benchmark default and drops the cached library handle and scratch buffers; earlier toggles are untouched. */
    public static function resetW67RustFfi(): void
    {
        self::w67RustFfiState('reset');
    }

    /**
     * Runs $callback with the ConfigurationProvider Wave 67 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW67RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w67RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w67RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 67.1 configuration lookup plans, one per `domain|key|type|value|default` row (trimmed). Domain: 0 empty, 1-6 the
     * ConfigurationDomain values (case-insensitive), 7 unknown. Keys match `[a-z][a-z0-9_]*` segments joined by dots. Type
     * (case-insensitive): 0 empty (auto: int, float, true/false/yes/no/on/off, else string), 1 int/integer (`[+-]?` 1-18
     * digits), 2 bool/boolean (also 1/0), 3 float/double (ints or `[+-]?` 1-15 digits with a dot), 4 string, 5 unknown.
     * A non-empty value that coerces wins, otherwise the default (an empty string coerces only as a string). Decision:
     * 0 empty row, 1 unknown domain, 2 invalid key, 3 unknown type, 4 value, 5 default, 6 unresolved. Kind: 0 none, 1 int,
     * 2 bool, 3 float, 4 string; magnitude is the absolute int, the bool, the absolute integer part or 0. Flags: 1 negative,
     * 2 value trimmed, 4 valid key, 8 default used, 16 value did not coerce, 32 non-ASCII, 64 control byte, 128 repeated
     * domain key. Per item `decision | domain << 3 | type << 6 | kind << 9 | flags << 12 | min(magnitude, 2^31 - 1) << 20 |
     * min(resolvedLength, 2047) << 51`; then 7 decision, 8 domain, 6 type, 5 kind and 8 flag counts, the magnitude sum and
     * the distinct domain keys.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w67ConfigurationProviderPlanBatch(array $items): array
    {
        return self::usesW67RustFfi() ? self::w67ConfigurationProviderPlanBatchNative($items) : self::w67ConfigurationProviderPlanBatchPhp($items);
    }

    /**
     * Rust plan; falls back to the PHP reference when the library is missing or the call declines.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w67ConfigurationProviderPlanBatchNative(array $items): array
    {
        return self::w67Invoke($items, \count($items) + self::W67_CONFIGURATION_PROVIDER_TRAILER) ?? self::w67ConfigurationProviderPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w67ConfigurationProviderPlanBatchPhp(array $items): array
    {
        $out = [];
        $tail = array_fill(0, self::W67_CONFIGURATION_PROVIDER_TRAILER, 0);
        $keys = [];
        foreach ($items as $item) {
            $item = (string) $item;
            [$rawDomain, $rawKey, $rawType, $rawValue, $rawDefault] = explode('|', $item, 5) + ['', '', '', '', ''];
            $domainName = strtolower(trim($rawDomain));
            $domain = $domainName === '' ? 0 : (self::W67_DOMAINS[$domainName] ?? 7);
            $key = trim($rawKey);
            $keyValid = preg_match('/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*\z/', $key) === 1;
            $type = match (strtolower(trim($rawType))) {
                '' => 0,
                'int', 'integer' => 1,
                'bool', 'boolean' => 2,
                'float', 'double' => 3,
                'string' => 4,
                default => 5,
            };
            $value = trim($rawValue);
            $fallback = trim($rawDefault);
            $flags = ($value !== $rawValue ? 2 : 0) | ($keyValid ? 4 : 0);
            [$kind, $negative, $magnitude, $length] = [0, false, 0, 0];
            if (trim($item) === '') {
                $decision = 0;
            } elseif ($domain === 7) {
                $decision = 1;
            } elseif (! $keyValid) {
                $decision = 2;
            } elseif ($type === 5) {
                $decision = 3;
            } else {
                $coerced = $value !== '' ? self::w67Coerce($value, $type) : null;
                if ($coerced !== null) {
                    [$kind, $negative, $magnitude] = $coerced;
                    $length = \strlen($value);
                    $decision = 4;
                } else {
                    $flags |= $value !== '' ? 16 : 0;
                    $coerced = self::w67Coerce($fallback, $type);
                    if ($coerced !== null) {
                        [$kind, $negative, $magnitude] = $coerced;
                        $length = \strlen($fallback);
                        $flags |= 8;
                        $decision = 5;
                    } else {
                        $decision = 6;
                    }
                }
            }
            $flags |= ($negative ? 1 : 0) | (preg_match('/[\x80-\xff]/', $item) === 1 ? 32 : 0) | (strcspn($item, self::W67_CONTROL_BYTES) !== \strlen($item) ? 64 : 0);
            if ($keyValid) {
                $seen = $domain.':'.$key;
                if (isset($keys[$seen])) {
                    $flags |= 128;
                } else {
                    $keys[$seen] = true;
                }
            }
            $tail[$decision]++;
            $tail[7 + $domain]++;
            $tail[15 + $type]++;
            $tail[21 + $kind]++;
            for ($b = 0; $b < 8; $b++) {
                $tail[26 + $b] += $flags >> $b & 1;
            }
            $tail[34] += min($magnitude, self::W67_SUM_CAP);
            $out[] = $decision | $domain << 3 | $type << 6 | $kind << 9 | $flags << 12 | min($magnitude, 0x7FFFFFFF) << 20 | min($length, 2047) << 51;
        }
        $tail[35] = \count($keys);
        array_push($out, ...$tail);

        return $out;
    }

    private const string W67_CONTROL_BYTES = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F";

    private const int W67_SUM_CAP = 1 << 40;

    /** ConfigurationDomain value => case position + 1. */
    private const array W67_DOMAINS = ['site' => 1, 'registration' => 2, 'ingestion' => 3, 'post-processing' => 4, 'metadata' => 5, 'tmux' => 6];

    /**
     * `[kind, negative, magnitude]` of trimmed $value coerced to $type (0 auto, 1 int, 2 bool, 3 float, 4 string), or null when
     * it does not coerce. Kinds: 1 int (absolute value), 2 bool (0/1), 3 float (absolute integer part), 4 string (0).
     *
     * @return array{0: int, 1: bool, 2: int}|null
     */
    private static function w67Coerce(string $value, int $type): ?array
    {
        $isInt = preg_match('/\A[+-]?[0-9]{1,18}\z/', $value) === 1;
        $isFloat = ! $isInt && preg_match('/\A[+-]?(?:[0-9]{1,15}\.[0-9]{0,15}|\.[0-9]{1,15})\z/', $value) === 1;
        $int = static function () use ($value): array {
            $number = (int) $value;

            return [1, $number < 0, abs($number)];
        };
        $float = static function () use ($value): array {
            $digits = $value[0] === '+' || $value[0] === '-' ? substr($value, 1) : $value;
            $dot = strpos($digits, '.');
            $whole = $dot === false ? $digits : substr($digits, 0, $dot);

            return [3, $value[0] === '-', $whole === '' ? 0 : (int) $whole];
        };
        $bool = static fn (bool $strict): ?array => match (strtolower($value)) {
            'true', 'yes', 'on' => [2, false, 1],
            'false', 'no', 'off' => [2, false, 0],
            '1' => $strict ? [2, false, 1] : null,
            '0' => $strict ? [2, false, 0] : null,
            default => null,
        };

        return match ($type) {
            0 => $value === '' ? null : ($isInt ? $int() : ($isFloat ? $float() : ($bool(false) ?? [4, false, 0]))),
            1 => $isInt ? $int() : null,
            2 => $bool(true),
            3 => $isInt || $isFloat ? $float() : null,
            4 => [4, false, 0],
            default => null,
        };
    }

    /**
     * Wave 67 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'reset' clears the override, library handle and buffers, 'ffi'
     * resolves the library (null when unavailable) and 'out' and 'lens' return `int64_t` output and
     * length-column buffers of at least $val words.
     */
    private static function w67RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'reset') {
            $override = $ffi = $out = $lens = null;
            $outCap = $lensCap = 0;

            return null;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('configuration_provider_w671', self::W67_CONFIGURATION_PROVIDER_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w67RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w67RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 67.1 ConfigurationProvider export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w67Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w67RustFfiState('ffi');
            $out = self::w67RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w67RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W67_CONFIGURATION_PROVIDER_FFI_CDEF.)
            $written = $ffi->nntmux_w671_configuration_provider_plan_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
