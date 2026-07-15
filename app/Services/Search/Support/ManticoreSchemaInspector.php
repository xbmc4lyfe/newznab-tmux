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
        $encoded = strtolower((string) json_encode($actual));

        return array_values(array_filter(array_keys($expected), static function (string $setting) use ($encoded, $expected): bool {
            return ! str_contains($encoded, strtolower($setting))
                || ! str_contains($encoded, strtolower((string) $expected[$setting]));
        }));
    }

    /**
     * @param  array<int, array<string, mixed>>  $actual
     * @param  array<string, array<string, mixed>>  $expected
     * @return array{missing: list<string>, incompatible: array<string, array{expected: string, actual: string}>}
     */
    public static function compareColumns(array $actual, array $expected): array
    {
        $types = [];
        foreach ($actual as $column) {
            $name = (string) ($column['Field'] ?? $column['field'] ?? '');
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
            } elseif (! str_contains($types[$name], $expectedType)) {
                $incompatible[$name] = ['expected' => $expectedType, 'actual' => $types[$name]];
            }
        }

        return ['missing' => $missing, 'incompatible' => $incompatible];
    }
}
