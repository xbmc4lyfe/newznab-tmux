<?php

declare(strict_types=1);

namespace App\Services\Configuration;

use App\Enums\ConfigurationDomain;
use App\Models\IngestionConfiguration;
use App\Models\MetadataConfiguration;
use App\Models\PostProcessingConfiguration;
use App\Models\RegistrationConfiguration;
use App\Models\SiteConfiguration;
use App\Models\TmuxCleanupRule;
use App\Models\TmuxColorExclusion;
use App\Models\TmuxConfiguration;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final readonly class DomainConfigurationRepository
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_cfgd_text(const char *ptr, size_t len, int32_t field, char *out, size_t cap);
        ssize_t nntmux_dcr_unique_strings(const char *buf, size_t buf_len, const int64_t *lens, size_t count, int64_t *out, size_t out_count);
        ssize_t nntmux_dcr_unique_ints(const int64_t *values, size_t count, int64_t *out, size_t out_count);
        C;

    public function __construct(private ConfigurationProvider $provider) {}

    // PHP remains the production default until benchmarks qualify Rust.
    public static function setUseRustFfi(?bool $use = true): void
    {
        self::toggleState($use, true);
        if ($use === null) {
            self::ffi(true);
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::toggleState() ?? false;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::ffi(true, $override);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>|null  $cleanupRules
     * @param  list<int>|null  $colorExclusions
     */
    public function update(ConfigurationDomain $domain, array $attributes, ?array $cleanupRules = null, ?array $colorExclusions = null): void
    {
        $modelClass = $this->modelClass($domain);
        $model = new $modelClass;

        if (! Schema::hasTable($model->getTable())) {
            throw new RuntimeException("The {$domain->label()} configuration table is not available.");
        }

        DB::transaction(function () use ($domain, $modelClass, $attributes, $cleanupRules, $colorExclusions): void {
            /** @var Model $configuration */
            $configuration = $modelClass::query()->lockForUpdate()->findOrFail(1);
            $configuration->fill($attributes);
            $configuration->save();

            if ($domain === ConfigurationDomain::Tmux && $cleanupRules !== null) {
                TmuxCleanupRule::query()->where('tmux_configuration_id', 1)->delete();
                TmuxCleanupRule::query()->insert(self::cleanupRuleRows($cleanupRules));
            }

            if ($domain === ConfigurationDomain::Tmux && $colorExclusions !== null) {
                TmuxColorExclusion::query()->where('tmux_configuration_id', 1)->delete();
                TmuxColorExclusion::query()->insert(self::colorExclusionRows($colorExclusions));
            }
        });

        $this->provider->forget($domain);
    }

    /**
     * Deduplicated `tmux_cleanup_rules` rows, keeping the first of equal rules.
     *
     * @param  list<string>  $cleanupRules
     * @return list<array{tmux_configuration_id: int, rule: string}>
     */
    public static function cleanupRuleRows(array $cleanupRules): array
    {
        return self::usesRustFfi() ? self::cleanupRuleRowsNative($cleanupRules) : self::cleanupRuleRowsPhp($cleanupRules);
    }

    /**
     * @param  list<string>  $cleanupRules
     * @return list<array{tmux_configuration_id: int, rule: string}>
     */
    public static function cleanupRuleRowsPhp(array $cleanupRules): array
    {
        return array_map(
            static fn (string $rule): array => ['tmux_configuration_id' => 1, 'rule' => $rule],
            array_values(array_unique($cleanupRules)),
        );
    }

    /**
     * @param  list<string>  $cleanupRules
     * @return list<array{tmux_configuration_id: int, rule: string}>
     */
    public static function cleanupRuleRowsNative(array $cleanupRules): array
    {
        $rules = array_values($cleanupRules);
        $count = \count($rules);
        if ($count === 0 || ($ffi = self::ffi()) === null) {
            return self::cleanupRuleRowsPhp($cleanupRules);
        }
        $lengths = [];
        foreach ($rules as $rule) {
            if (! \is_string($rule)) {
                return self::cleanupRuleRowsPhp($cleanupRules);
            }
            $lengths[] = \strlen($rule);
        }
        $positions = self::uniquePositions($ffi, 'nntmux_dcr_unique_strings', $count, $lengths, implode('', $rules));
        if ($positions === null) {
            return self::cleanupRuleRowsPhp($cleanupRules);
        }
        $rows = [];
        foreach ($positions as $position) {
            if (! isset($rules[$position])) {
                return self::cleanupRuleRowsPhp($cleanupRules);
            }
            $rows[] = ['tmux_configuration_id' => 1, 'rule' => $rules[$position]];
        }

        return $rows;
    }

    /**
     * Deduplicated `tmux_color_exclusions` rows, keeping the first of equal colors.
     *
     * @param  list<int>  $colorExclusions
     * @return list<array{tmux_configuration_id: int, color: int}>
     */
    public static function colorExclusionRows(array $colorExclusions): array
    {
        return self::usesRustFfi() ? self::colorExclusionRowsNative($colorExclusions) : self::colorExclusionRowsPhp($colorExclusions);
    }

    /**
     * @param  list<int>  $colorExclusions
     * @return list<array{tmux_configuration_id: int, color: int}>
     */
    public static function colorExclusionRowsPhp(array $colorExclusions): array
    {
        return array_map(
            static fn (int $color): array => ['tmux_configuration_id' => 1, 'color' => $color],
            array_values(array_unique($colorExclusions)),
        );
    }

    /**
     * @param  list<int>  $colorExclusions
     * @return list<array{tmux_configuration_id: int, color: int}>
     */
    public static function colorExclusionRowsNative(array $colorExclusions): array
    {
        $colors = array_values($colorExclusions);
        $count = \count($colors);
        if ($count === 0 || ($ffi = self::ffi()) === null) {
            return self::colorExclusionRowsPhp($colorExclusions);
        }
        foreach ($colors as $color) {
            if (! \is_int($color)) {
                return self::colorExclusionRowsPhp($colorExclusions);
            }
        }
        $unique = self::uniquePositions($ffi, 'nntmux_dcr_unique_ints', $count, $colors);
        if ($unique === null) {
            return self::colorExclusionRowsPhp($colorExclusions);
        }

        return array_map(static fn (int $color): array => ['tmux_configuration_id' => 1, 'color' => $color], $unique);
    }

    /**
     * Model class persisting the configuration for `$domain`.
     *
     * @return class-string<Model>
     */
    public static function modelClassFor(ConfigurationDomain $domain): string
    {
        return self::usesRustFfi() ? self::modelClassForNative($domain) : self::modelClassForPhp($domain);
    }

    /** @return class-string<Model> */
    public static function modelClassForNative(ConfigurationDomain $domain): string
    {
        if (($ffi = self::ffi()) === null) {
            return self::modelClassForPhp($domain);
        }
        try {
            $buffer = $ffi->new('char[64]');
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_cfgd_text($domain->value, \strlen($domain->value), ConfigurationDomain::TEXT_MODEL_CLASS, $buffer, 64);
            if (! \is_int($written) || $written <= 0) {
                return self::modelClassForPhp($domain);
            }
            $class = FFI::string($buffer, $written);
        } catch (Throwable) {
            return self::modelClassForPhp($domain);
        }

        return is_a($class, Model::class, true) ? $class : self::modelClassForPhp($domain);
    }

    /** @return class-string<Model> */
    public static function modelClassForPhp(ConfigurationDomain $domain): string
    {
        return match ($domain) {
            ConfigurationDomain::Site => SiteConfiguration::class,
            ConfigurationDomain::Registration => RegistrationConfiguration::class,
            ConfigurationDomain::Ingestion => IngestionConfiguration::class,
            ConfigurationDomain::PostProcessing => PostProcessingConfiguration::class,
            ConfigurationDomain::Metadata => MetadataConfiguration::class,
            ConfigurationDomain::Tmux => TmuxConfiguration::class,
        };
    }

    /** @return class-string<Model> */
    private function modelClass(ConfigurationDomain $domain): string
    {
        return self::modelClassFor($domain);
    }

    /**
     * Run a Rust dedupe export over `$count` entries; `$values` holds string lengths (with
     * `$packed` bytes) or the integers themselves. Returns the export's int64 output, or null.
     *
     * @param  list<int>  $values
     * @return list<int>|null
     */
    private static function uniquePositions(FFI $ffi, string $symbol, int $count, array $values, ?string $packed = null): ?array
    {
        try {
            $input = $ffi->new("int64_t[{$count}]");
            $output = $ffi->new("int64_t[{$count}]");
            FFI::memcpy($input, pack('q*', ...$values), $count * 8);
            $written = $packed === null
                ? $ffi->{$symbol}($input, $count, $output, $count)
                : $ffi->{$symbol}($packed, \strlen($packed), $input, $count, $output, $count);
            if (! \is_int($written) || $written < 1 || $written > $count) {
                return null;
            }
            $unpacked = unpack('q'.$written, FFI::string($output, $written * 8));
        } catch (Throwable) {
            return null;
        }

        return \is_array($unpacked) && \count($unpacked) === $written ? array_values(array_map(intval(...), $unpacked)) : null;
    }

    private static function toggleState(?bool $next = null, bool $write = false): ?bool
    {
        // Readonly classes cannot declare static properties.
        static $useRustFfi = null;
        if ($write) {
            $useRustFfi = $next;
        }

        return $useRustFfi;
    }

    private static function ffi(bool $reset = false, FFI|false|null $override = null): ?FFI
    {
        static $ffi = null;
        if ($reset) {
            $ffi = $override;
            if ($override !== null) {
                return $ffi === false ? null : $ffi;
            }
        }
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return $ffi === false ? null : $ffi;
    }

    /**
     * Enabled: the paired benchmark in the W57 parity test measures 2.991x common mean, 1.735x
     * common p99, 2.656x uncommon mean and 2.428x uncommon p99 (per-metric minimum of four runs),
     * above the 1.5x rule.
     */
    private const bool W57_RUST_FFI_DEFAULT = true;

    private const string W57_FFI_CDEF = 'ssize_t nntmux_w571_domain_setting_diff_batch(const char *rows, size_t rows_len, size_t pairs, int64_t *out, size_t out_cap);';

    public const int W57_DIFF_WORDS = 2;

    public const int W57_DIFF_CHANGED = 1;

    public const int W57_DIFF_TRIM_EQUAL = 2;

    public const int W57_DIFF_CASE_EQUAL = 4;

    public const int W57_DIFF_NUMERIC_EQUAL = 8;

    public const int W57_DIFF_CLEARED = 16;

    public const int W57_DIFF_ADDED = 32;

    public const int W57_DIFF_JSON = 64;

    public const int W57_DIFF_MULTILINE = 128;

    public const int W57_DIFF_LENGTH_SHIFT = 8;

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
     * Two words per positional stored/submitted setting pair: W57_DIFF_CHANGED, TRIM_EQUAL,
     * CASE_EQUAL (ASCII case-insensitive), NUMERIC_EQUAL (both trimmed values 18-digit integers
     * of equal value), CLEARED, ADDED, JSON (trimmed submitted `{...}`/`[...]`) and MULTILINE
     * flags plus the submitted length `<< W57_DIFF_LENGTH_SHIFT`; then `crc32()` of the
     * submitted value as its fingerprint.
     *
     * @param  array<array-key, string>  $stored
     * @param  array<array-key, string>  $submitted
     * @return list<int>
     *
     * @throws InvalidArgumentException when the two lists differ in length
     */
    public static function settingDiffBatch(array $stored, array $submitted): array
    {
        return self::usesW57RustFfi() ? self::settingDiffBatchNative($stored, $submitted) : self::settingDiffBatchPhp($stored, $submitted);
    }

    /**
     * @param  array<array-key, string>  $stored
     * @param  array<array-key, string>  $submitted
     * @return list<int>
     */
    public static function settingDiffBatchPhp(array $stored, array $submitted): array
    {
        if (\count($stored) !== \count($submitted)) {
            throw new InvalidArgumentException('Stored and submitted settings must pair up.');
        }
        $submitted = array_values($submitted);
        $words = [];
        foreach (array_values($stored) as $i => $before) {
            $after = $submitted[$i];
            $trimmedBefore = trim($before);
            $trimmedAfter = trim($after);
            $words[] = ($before !== $after ? self::W57_DIFF_CHANGED : 0)
                | ($trimmedBefore === $trimmedAfter ? self::W57_DIFF_TRIM_EQUAL : 0)
                | (strtolower($before) === strtolower($after) ? self::W57_DIFF_CASE_EQUAL : 0)
                | (preg_match('/\A[+-]?[0-9]{1,18}\z/', $trimmedBefore) === 1 && preg_match('/\A[+-]?[0-9]{1,18}\z/', $trimmedAfter) === 1 && (int) $trimmedBefore === (int) $trimmedAfter ? self::W57_DIFF_NUMERIC_EQUAL : 0)
                | ($before !== '' && $after === '' ? self::W57_DIFF_CLEARED : 0)
                | ($before === '' && $after !== '' ? self::W57_DIFF_ADDED : 0)
                | ($trimmedAfter !== '' && (($trimmedAfter[0] === '{' && $trimmedAfter[-1] === '}') || ($trimmedAfter[0] === '[' && $trimmedAfter[-1] === ']')) ? self::W57_DIFF_JSON : 0)
                | (str_contains($after, "\n") ? self::W57_DIFF_MULTILINE : 0)
                | \strlen($after) << self::W57_DIFF_LENGTH_SHIFT;
            $words[] = crc32($after);
        }

        return $words;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, the lists differ in length or
     * Rust declines the batch (a value holding a NUL byte).
     *
     * @param  array<array-key, string>  $stored
     * @param  array<array-key, string>  $submitted
     * @return list<int>
     */
    public static function settingDiffBatchNative(array $stored, array $submitted): array
    {
        $count = \count($stored);
        $words = $count * self::W57_DIFF_WORDS;
        /** @var array{0: FFI, 1: CData, 2: int}|null $buffer */
        $buffer = $count === 0 || $count !== \count($submitted) ? null : self::w57RustFfiState('out', $words);
        if ($buffer === null) {
            return self::settingDiffBatchPhp($stored, $submitted);
        }
        [$ffi, $out, $cap] = $buffer;
        $joined = implode("\0", $stored)."\0".implode("\0", $submitted);
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w571_domain_setting_diff_batch($joined, \strlen($joined), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        $codes = $written === $count ? unpack('P*', FFI::string($out, $words * 8)) : false;
        if (! \is_array($codes)) {
            return self::settingDiffBatchPhp($stored, $submitted);
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
                $ffi ??= RustPipelineLibrary::resolveDomain('domain_configuration_repository_w57', self::W57_FFI_CDEF) ?? false;
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

    public const string W67_DOMAIN_CONFIG_REPO_FFI_CDEF = <<<'C'
        ssize_t nntmux_w671_domain_config_repo_plan_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of w67DomainConfigRepoPlanBatch(). */
    public const int W67_DOMAIN_CONFIG_REPO_TRAILER = 31;

    public static function usesW67RustFfi(): bool
    {
        $override = self::w67RustFfiState();

        return \is_bool($override) ? $override : self::W67_RUST_FFI_DEFAULT;
    }

    /** Forces the DomainConfigurationRepository Wave 67 Rust path on until reset. */
    public static function enableW67RustFfi(): void
    {
        self::w67RustFfiState('set', true);
    }

    /** Forces the DomainConfigurationRepository Wave 67 PHP path until reset. */
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
     * Runs $callback with the DomainConfigurationRepository Wave 67 Rust path forced on or off, then restores the previous setting.
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
     * Wave 67.1 domain setting plans, one per `section|setting|stored|override|ttl` row (trimmed). The section is lowercased
     * with `_` and spaces read as `-` (`postprocessing` is post-processing): 0 empty, 1-6 the ConfigurationDomain values,
     * 7 unknown. The setting key is lowercased with `-`, spaces and dots read as `_` and must match `[a-z][a-z0-9_]*`. The
     * TTL is 1-15 digits (empty 300) clamped to 0-86400. Source: 2 a non-empty override, 1 a non-empty stored value, 0 the
     * default. Decision: 0 empty row, 1 unknown section, 2 invalid key, 3 malformed TTL, 4 override changes the stored
     * value, 5 override equals it, 6 stored, 7 default. Flags: 1 key normalized, 2 section normalized, 4 TTL clamped, 8 no
     * cache (TTL 0), 16 multi-line value, 32 JSON-looking effective value, 64 non-ASCII, 128 control byte, 256 repeated
     * section key. Per item `decision | section << 3 | source << 6 | flags << 8 | ttl << 17 |
     * (fnv1a32('configuration:' . domain . '.' . key) & 0xFFFFF) << 34 | min(effectiveLength, 511) << 54` (no hash below
     * decision 4); then 8 decision, 8 section, 3 source and 9 flag counts, the TTL sum and maximum and the distinct keys.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w67DomainConfigRepoPlanBatch(array $items): array
    {
        return self::usesW67RustFfi() ? self::w67DomainConfigRepoPlanBatchNative($items) : self::w67DomainConfigRepoPlanBatchPhp($items);
    }

    /**
     * Rust plan; falls back to the PHP reference when the library is missing or the call declines.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w67DomainConfigRepoPlanBatchNative(array $items): array
    {
        return self::w67Invoke($items, \count($items) + self::W67_DOMAIN_CONFIG_REPO_TRAILER) ?? self::w67DomainConfigRepoPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w67DomainConfigRepoPlanBatchPhp(array $items): array
    {
        $out = [];
        $tail = array_fill(0, self::W67_DOMAIN_CONFIG_REPO_TRAILER, 0);
        $keys = [];
        foreach ($items as $item) {
            $item = (string) $item;
            [$rawSection, $rawSetting, $rawStored, $rawOverride, $rawTtl] = explode('|', $item, 5) + ['', '', '', '', ''];
            $sectionLower = strtolower(trim($rawSection));
            $sectionName = strtr($sectionLower, '_ ', '--');
            $section = match (true) {
                $sectionName === '' => 0,
                $sectionName === 'postprocessing' => 4,
                default => self::W67_DOMAINS[$sectionName] ?? 7,
            };
            $setting = trim($rawSetting);
            $key = strtr(strtolower($setting), '- .', '___');
            $keyValid = preg_match('/\A[a-z][a-z0-9_]*\z/', $key) === 1;
            $stored = trim($rawStored);
            $override = trim($rawOverride);
            $ttl = self::w67Uint($rawTtl, 300);
            [$ttlValue, $clamped] = self::w67Clamp($ttl, 0, 86_400);
            $source = $override !== '' ? 2 : ($stored !== '' ? 1 : 0);
            $effective = match ($source) {
                2 => $override,
                1 => $stored,
                default => '',
            };
            $json = $effective !== '' && (($effective[0] === '{' && $effective[-1] === '}') || ($effective[0] === '[' && $effective[-1] === ']'));
            $flags = ($key !== $setting ? 1 : 0) | ($sectionName !== $sectionLower ? 2 : 0) | ($clamped ? 4 : 0) | ($ttl === 0 ? 8 : 0)
                | (str_contains($rawStored, "\n") || str_contains($rawOverride, "\n") ? 16 : 0) | ($json ? 32 : 0)
                | (preg_match('/[\x80-\xff]/', $item) === 1 ? 64 : 0) | (strcspn($item, self::W67_CONTROL_BYTES) !== \strlen($item) ? 128 : 0);
            $decision = match (true) {
                trim($item) === '' => 0,
                $section === 0 || $section === 7 => 1,
                ! $keyValid => 2,
                $ttl < 0 => 3,
                $source === 2 && $override !== $stored => 4,
                $source === 2 => 5,
                $source === 1 => 6,
                default => 7,
            };
            $hash = 0;
            if ($decision >= 4) {
                $hash = (int) hexdec(hash('fnv1a32', 'configuration:'.self::W67_DOMAIN_NAMES[$section].'.'.$key)) & 0xFFFFF;
                $seen = $section.':'.$key;
                if (isset($keys[$seen])) {
                    $flags |= 256;
                } else {
                    $keys[$seen] = true;
                }
            }
            $tail[$decision]++;
            $tail[8 + $section]++;
            $tail[16 + $source]++;
            for ($b = 0; $b < 9; $b++) {
                $tail[19 + $b] += $flags >> $b & 1;
            }
            $tail[28] += $ttlValue;
            $tail[29] = max($tail[29], $ttlValue);
            $out[] = $decision | $section << 3 | $source << 6 | $flags << 8 | $ttlValue << 17 | $hash << 34 | min(\strlen($effective), 511) << 54;
        }
        $tail[30] = \count($keys);
        array_push($out, ...$tail);

        return $out;
    }

    private const string W67_CONTROL_BYTES = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F";

    /** ConfigurationDomain value => case position + 1. */
    private const array W67_DOMAINS = ['site' => 1, 'registration' => 2, 'ingestion' => 3, 'post-processing' => 4, 'metadata' => 5, 'tmux' => 6];

    /** ConfigurationDomain values by W67_DOMAINS code. */
    private const array W67_DOMAIN_NAMES = [1 => 'site', 2 => 'registration', 3 => 'ingestion', 4 => 'post-processing', 5 => 'metadata', 6 => 'tmux'];

    private const string W67_DIGITS = '0123456789';

    /** A trimmed field of 1-15 digits; empty is $empty, anything else -1. */
    private static function w67Uint(string $raw, int $empty): int
    {
        $value = trim($raw);
        if ($value === '') {
            return $empty;
        }
        $length = \strlen($value);

        return $length <= 15 && strspn($value, self::W67_DIGITS) === $length ? (int) $value : -1;
    }

    /**
     * $value clamped to $min..$max and whether clamping changed it; malformed (-1) is 0, unchanged.
     *
     * @return array{0: int, 1: bool}
     */
    private static function w67Clamp(int $value, int $min, int $max): array
    {
        if ($value < 0) {
            return [0, false];
        }
        $clamped = max($min, min($max, $value));

        return [$clamped, $clamped !== $value];
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
            $ffi ??= RustPipelineLibrary::resolveDomain('domain_config_repo_w671', self::W67_DOMAIN_CONFIG_REPO_FFI_CDEF) ?? false;

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
     * Calls the Wave 67.1 DomainConfigurationRepository export with the NUL-joined $items, or with the items concatenated plus an
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
            // @phpstan-ignore method.notFound (Declared by W67_DOMAIN_CONFIG_REPO_FFI_CDEF.)
            $written = $ffi->nntmux_w671_domain_config_repo_plan_batch($blob, \strlen($blob), $lens, $count, $out, $words);
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
