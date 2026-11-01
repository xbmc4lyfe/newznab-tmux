<?php

declare(strict_types=1);

namespace App\Services\Search\Support;

final class ManticoreSchemaInspector
{
    /**
     * @param  array<string, mixed>  $expected
     * @return list<string>
     */
    public static function missingSettings(mixed $actual, array $expected): array
    {
        $settings = self::normalizeSettings($actual);

        return array_values(array_filter(array_keys($expected), static function (string $setting) use ($settings, $expected): bool {
            return ! array_key_exists(strtolower($setting), $settings)
                || $settings[strtolower($setting)] !== strtolower((string) $expected[$setting]);
        }));
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $actual
     * @param  array<string, array<string, mixed>>  $expected
     * @return array{missing: list<string>, incompatible: array<string, array{expected: string, actual: string}>}
     */
    public static function compareColumns(array $actual, array $expected): array
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
