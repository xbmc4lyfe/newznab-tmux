<?php

declare(strict_types=1);

namespace App\Services\Search\Support;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

final class ManticoreSchemaInspector
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_manticore_schema_compare_columns_nul(const char *actual_nul, size_t actual_len, size_t actual_count, const char *expected_nul, size_t expected_len, size_t expected_count, char *out_buf, size_t out_cap);
        ssize_t nntmux_manticore_schema_missing_settings_nul(const char *actual_nul, size_t actual_len, size_t actual_count, const char *expected_nul, size_t expected_len, size_t expected_count, char *out_buf, size_t out_cap);
        C;

    private static FFI|false|null $ffi = null;

    private static ?CData $outBuf1024 = null;

    private static bool $useRustFfi = true;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    /**
     * @param  array<string, mixed>  $expected
     * @return list<string>
     */
    public static function missingSettings(mixed $actual, array $expected): array
    {
        if (self::$useRustFfi) {
            if (\is_array($actual) && \count($actual) === 1 && isset($actual['settings']) && \is_string($actual['settings'])) {
                $expPacked = null;
                $expLen = 0;
                $expCount = 0;
                if ($expected === ['min_infix_len' => 2, 'index_field_lengths' => 1]) {
                    $expPacked = "min_infix_len\x002\x00index_field_lengths\x001";
                    $expLen = 37;
                    $expCount = 2;
                } elseif ($expected === ['min_infix_len' => 2, 'exact_words' => 1, 'index_field_lengths' => 1]) {
                    $expPacked = "min_infix_len\x002\x00exact_words\x001\x00index_field_lengths\x001";
                    $expLen = 51;
                    $expCount = 3;
                }
                if ($expPacked !== null) {
                    $ffi = self::$ffi ?? self::resolveFfi();
                    $buf = self::$outBuf1024;
                    if ($ffi !== null && $ffi !== false && $buf !== null) {
                        $blob = $actual['settings'];
                        // @phpstan-ignore method.notFound
                        $written = $ffi->nntmux_manticore_schema_missing_settings_nul(
                            $blob,
                            \strlen($blob),
                            0xFFFFFFFF,
                            $expPacked,
                            $expLen,
                            $expCount,
                            $buf,
                            1024,
                        );
                        if (\is_int($written) && $written >= 0) {
                            return $written === 0 ? [] : \explode("\n", FFI::string($buf, $written));
                        }
                    }
                }
            }
            $native = self::missingSettingsNative($actual, $expected);
            if ($native !== null) {
                return $native;
            }
        }

        return self::missingSettingsPhp($actual, $expected);
    }

    /**
     * @param  array<string, mixed>  $expected
     * @return list<string>|null
     */
    public static function missingSettingsNative(mixed $actual, array $expected): ?array
    {
        if (! self::$useRustFfi) {
            return null;
        }
        if ($expected === []) {
            return [];
        }

        $actualPacked = '';
        $actualCount = 0;
        if (\is_array($actual) && \count($actual) === 1 && isset($actual['settings']) && \is_string($actual['settings'])) {
            $actualPacked = $actual['settings'];
            $actualCount = 0xFFFFFFFF;
        } elseif (\is_array($actual)) {
            $actualFlat = [];
            foreach ($actual as $key => $value) {
                if (\is_string($key) && ! \is_array($value)) {
                    if ($key === "\x01") {
                        return null;
                    }
                    if (\strtolower($key) === 'settings' && \is_string($value)) {
                        $actualFlat[] = "\x01";
                        $actualFlat[] = $value;
                        $actualCount++;

                        continue;
                    }
                    if (! \is_scalar($value) && $value !== null && ! (\is_object($value) && \method_exists($value, '__toString'))) {
                        return null;
                    }
                    $actualFlat[] = $key;
                    $actualFlat[] = (string) $value;
                    $actualCount++;

                    continue;
                }

                if (! \is_array($value)) {
                    continue;
                }

                $name = $value['Setting_name'] ?? $value['setting_name'] ?? $value['Variable_name'] ?? $value['variable_name'] ?? $value['Name'] ?? $value['name'] ?? null;
                $settingValue = $value['Value'] ?? $value['value'] ?? null;
                if (\is_string($name) && $settingValue !== null) {
                    if ($name === "\x01" || (! \is_scalar($settingValue) && ! (\is_object($settingValue) && \method_exists($settingValue, '__toString')))) {
                        return null;
                    }
                    $actualFlat[] = $name;
                    $actualFlat[] = (string) $settingValue;
                    $actualCount++;

                    continue;
                }

                if (\is_string($key)) {
                    if ($key === "\x01") {
                        return null;
                    }
                    $nestedValue = $value['Value'] ?? $value['value'] ?? \reset($value);
                    if (\is_scalar($nestedValue)) {
                        $actualFlat[] = $key;
                        $actualFlat[] = (string) $nestedValue;
                        $actualCount++;
                    }
                }
            }

            $actualPacked = $actualCount === 0 ? '' : \implode("\0", $actualFlat);
            if ($actualCount > 0 && \substr_count($actualPacked, "\0") !== ($actualCount * 2 - 1)) {
                return null;
            }
        }

        if ($expected === ['min_infix_len' => 2, 'index_field_lengths' => 1]) {
            $expectedPacked = "min_infix_len\x002\x00index_field_lengths\x001";
            $expLen = 37;
            $expectedCount = 2;
        } elseif ($expected === ['min_infix_len' => 2, 'exact_words' => 1, 'index_field_lengths' => 1]) {
            $expectedPacked = "min_infix_len\x002\x00exact_words\x001\x00index_field_lengths\x001";
            $expLen = 51;
            $expectedCount = 3;
        } else {
            $expectedFlat = [];
            $expectedCount = 0;
            foreach ($expected as $setting => $expectedVal) {
                if (! \is_string($setting) || $setting === '' || (! \is_scalar($expectedVal) && $expectedVal !== null && ! (\is_object($expectedVal) && \method_exists($expectedVal, '__toString')))) {
                    return null;
                }
                $expectedFlat[] = $setting;
                $expectedFlat[] = (string) $expectedVal;
                $expectedCount++;
            }
            $expectedPacked = \implode("\0", $expectedFlat);
            if (\substr_count($expectedPacked, "\0") !== ($expectedCount * 2 - 1)) {
                return null;
            }
            $expLen = \strlen($expectedPacked);
        }

        $ffi = self::$ffi ?? self::resolveFfi();
        $buf = self::$outBuf1024;
        if ($ffi === null || $ffi === false || $buf === null) {
            return null;
        }

        $actLen = \strlen($actualPacked);

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_manticore_schema_missing_settings_nul(
                $actualPacked,
                $actLen,
                $actualCount,
                $expectedPacked,
                $expLen,
                $expectedCount,
                $buf,
                1024,
            );
            if (! \is_int($written) || $written === -1 || $written === -2) {
                return null;
            }
            if ($written < -2) {
                $cap = 0;
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_manticore_schema_missing_settings_nul(
                    $actualPacked,
                    $actLen,
                    $actualCount,
                    $expectedPacked,
                    $expLen,
                    $expectedCount,
                    $scratch,
                    $cap,
                );
                if (! \is_int($written) || $written < 0) {
                    return null;
                }

                return $written === 0 ? [] : \explode("\n", FFI::string($scratch, $written));
            }

            return $written === 0 ? [] : \explode("\n", FFI::string($buf, $written));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $expected
     * @return list<string>
     */
    public static function missingSettingsPhp(mixed $actual, array $expected): array
    {
        $settings = self::normalizeSettings($actual);

        return array_values(array_filter(array_keys($expected), static function (string $setting) use ($settings, $expected): bool {
            return ! array_key_exists(strtolower($setting), $settings)
                || $settings[strtolower($setting)] !== strtolower((string) $expected[$setting]);
        }));
    }

    /**
     * Retains native PHP associative lookup in production under the < 1.5x FFI threshold
     * (`0.70x` mean / `0.54x` p99 on 35-column `releases_rt` schema due to nested PHP array
     * flattening and NUL-packing overhead); see `compareColumnsNative`.
     *
     * @param  array<int|string, array<string, mixed>>  $actual
     * @param  array<string, array<string, mixed>>  $expected
     * @return array{missing: list<string>, incompatible: array<string, array{expected: string, actual: string}>}
     */
    public static function compareColumns(array $actual, array $expected): array
    {
        return self::compareColumnsPhp($actual, $expected);
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $actual
     * @param  array<string, array<string, mixed>>  $expected
     * @return array{missing: list<string>, incompatible: array<string, array{expected: string, actual: string}>}|null
     */
    public static function compareColumnsNative(array $actual, array $expected): ?array
    {
        if (! self::$useRustFfi) {
            return null;
        }
        if ($expected === []) {
            return ['missing' => [], 'incompatible' => []];
        }

        $actualFlat = [];
        $actualCount = 0;
        foreach ($actual as $key => $column) {
            $nameRaw = $column['Field'] ?? $column['field'] ?? (\is_string($key) ? $key : '');
            if (! \is_scalar($nameRaw)) {
                return null;
            }
            $name = (string) $nameRaw;
            if ($name !== '') {
                $typeRaw = $column['Type'] ?? $column['type'] ?? '';
                if (! \is_scalar($typeRaw)) {
                    return null;
                }
                $actualFlat[] = $name;
                $actualFlat[] = (string) $typeRaw;
                $actualCount++;
            }
        }
        $actualPacked = $actualCount === 0 ? '' : \implode("\0", $actualFlat);
        if ($actualCount > 0 && \substr_count($actualPacked, "\0") !== ($actualCount * 2 - 1)) {
            return null;
        }

        $expectedFlat = [];
        $expectedCount = 0;
        foreach ($expected as $name => $definition) {
            if (! \is_string($name) || $name === '') {
                return null;
            }
            $typeRaw = $definition['type'] ?? '';
            if (! \is_scalar($typeRaw)) {
                return null;
            }
            $expectedFlat[] = $name;
            $expectedFlat[] = (string) $typeRaw;
            $expectedCount++;
        }
        $expectedPacked = \implode("\0", $expectedFlat);
        if (\substr_count($expectedPacked, "\0") !== ($expectedCount * 2 - 1)) {
            return null;
        }

        $ffi = self::$ffi ?? self::resolveFfi();
        $buf = self::$outBuf1024;
        if ($ffi === null || $ffi === false || $buf === null) {
            return null;
        }

        $actLen = \strlen($actualPacked);
        $expLen = \strlen($expectedPacked);

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_manticore_schema_compare_columns_nul(
                $actualPacked,
                $actLen,
                $actualCount,
                $expectedPacked,
                $expLen,
                $expectedCount,
                $buf,
                1024,
            );
            if (! \is_int($written) || $written === -1 || $written === -2) {
                return null;
            }
            $rawOut = '';
            if ($written < -2) {
                $cap = 0;
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_manticore_schema_compare_columns_nul(
                    $actualPacked,
                    $actLen,
                    $actualCount,
                    $expectedPacked,
                    $expLen,
                    $expectedCount,
                    $scratch,
                    $cap,
                );
                if (! \is_int($written) || $written < 0) {
                    return null;
                }
                if ($written > 0) {
                    $rawOut = FFI::string($scratch, $written);
                }
            } elseif ($written > 0) {
                $rawOut = FFI::string($buf, $written);
            }

            if ($rawOut === '') {
                return ['missing' => [], 'incompatible' => []];
            }

            $missing = [];
            $incompatible = [];
            foreach (\explode("\n", $rawOut) as $line) {
                $parts = \explode("\t", $line);
                if ($parts[0] === 'M' && isset($parts[1])) {
                    $missing[] = $parts[1];
                } elseif ($parts[0] === 'I' && isset($parts[1], $parts[2], $parts[3])) {
                    $incompatible[$parts[1]] = ['expected' => $parts[2], 'actual' => $parts[3]];
                }
            }

            return ['missing' => $missing, 'incompatible' => $incompatible];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $actual
     * @param  array<string, array<string, mixed>>  $expected
     * @return array{missing: list<string>, incompatible: array<string, array{expected: string, actual: string}>}
     */
    public static function compareColumnsPhp(array $actual, array $expected): array
    {
        $types = [];
        foreach ($actual as $key => $column) {
            $name = (string) ($column['Field'] ?? $column['field'] ?? (is_string($key) ? $key : ''));
            $type = strtolower((string) ($column['Type'] ?? $column['type'] ?? ''));
            if ($name !== '') {
                $types[$name] = $type;
            }
        }

        $missing = [];
        $incompatible = [];
        foreach ($expected as $name => $definition) {
            $expectedType = strtolower((string) $definition['type']);
            if (! isset($types[$name])) {
                $missing[] = $name;
            } elseif (! self::typesAreCompatible($types[$name], $expectedType)) {
                $incompatible[$name] = ['expected' => $expectedType, 'actual' => $types[$name]];
            }
        }

        return ['missing' => $missing, 'incompatible' => $incompatible];
    }

    private static function resolveFfi(): ?FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi === false ? null : self::$ffi;
        }
        $ffi = RustPipelineLibrary::resolveDomain('manticore_schema_inspector', self::FFI_CDEF);
        if ($ffi === null) {
            self::$ffi = false;

            return null;
        }
        try {
            self::$outBuf1024 = $ffi->new('char[1024]');
            self::$ffi = $ffi;

            return $ffi;
        } catch (Throwable) {
            self::$ffi = false;

            return null;
        }
    }

    private static function typesAreCompatible(string $actual, string $expected): bool
    {
        if ($expected === 'integer') {
            return str_contains($actual, 'integer') || str_contains($actual, 'uint');
        }

        return str_contains($actual, $expected);
    }

    /** @return array<string, string> */
    private static function normalizeSettings(mixed $actual): array
    {
        if (! is_array($actual)) {
            return [];
        }

        $settings = [];
        foreach ($actual as $key => $value) {
            if (is_string($key) && ! is_array($value)) {
                if (strtolower($key) === 'settings' && is_string($value)) {
                    foreach (preg_split('/\R/', $value) ?: [] as $line) {
                        if (preg_match('/^\s*([a-z0-9_]+)\s*=\s*[\'\"]?([^\'\"]+?)[\'\"]?\s*$/i', $line, $match) === 1) {
                            $settings[strtolower($match[1])] = strtolower(trim($match[2]));
                        }
                    }

                    continue;
                }
                $settings[strtolower($key)] = strtolower((string) $value);

                continue;
            }

            if (! is_array($value)) {
                continue;
            }

            $name = $value['Setting_name'] ?? $value['setting_name'] ?? $value['Variable_name'] ?? $value['variable_name'] ?? $value['Name'] ?? $value['name'] ?? null;
            $settingValue = $value['Value'] ?? $value['value'] ?? null;
            if (is_string($name) && $settingValue !== null) {
                $settings[strtolower($name)] = strtolower((string) $settingValue);

                continue;
            }

            if (is_string($key)) {
                $nestedValue = $value['Value'] ?? $value['value'] ?? reset($value);
                if (is_scalar($nestedValue)) {
                    $settings[strtolower($key)] = strtolower((string) $nestedValue);
                }
            }
        }

        return $settings;
    }
}
