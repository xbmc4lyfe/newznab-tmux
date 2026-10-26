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
}
