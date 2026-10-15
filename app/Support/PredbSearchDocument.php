<?php

declare(strict_types=1);

namespace App\Support;

final class PredbSearchDocument
{
    public static function exactValue(string $value): string
    {
        return mb_strtolower($value, 'UTF-8');
    }

    /** @return array{id: int, title: string, filename: string, source: string}|null */
    public static function filenameCandidate(mixed $row): ?array
    {
        if (! is_array($row) || ! is_array($row['_source'] ?? null)) {
            return null;
        }
        $id = $row['_id'] ?? null;
        if (is_string($id) && preg_match('/^[0-9]+$/D', $id) === 1) {
            $digits = ltrim($id, '0');
            $maximum = (string) PHP_INT_MAX;
            if ($digits === '' || strlen($digits) > strlen($maximum)
                || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
                return null;
            }
            $id = (int) $digits;
        }
        if (! is_int($id) || $id <= 0) {
            return null;
        }
        $source = $row['_source'];
        if (! is_string($source['title'] ?? null) || $source['title'] === '') {
            return null;
        }
        foreach (['filename', 'source'] as $field) {
            if (isset($source[$field]) && ! is_string($source[$field])) {
                return null;
            }
        }

        return self::normalize($source, $id);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{id: int, title: string, filename: string, source: string}
     */
    public static function normalize(array $row, int $id): array
    {
        return [
            'id' => $id,
            'title' => (string) ($row['title'] ?? ''),
            'filename' => (string) ($row['filename'] ?? ''),
            'source' => (string) ($row['source'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{id: int, title: string, filename: string, source: string, title_exact: string, filename_exact: string}
     */
    public static function forElasticsearch(array $row): array
    {
        $document = self::normalize($row, (int) ($row['id'] ?? 0));

        return $document + [
            'title_exact' => self::exactValue($document['title']),
            'filename_exact' => self::exactValue($document['filename']),
        ];
    }

    /** @return array<string, array{type: string}> */
    public static function elasticsearchExactMappings(): array
    {
        return [
            'title_exact' => ['type' => 'keyword'],
            'filename_exact' => ['type' => 'keyword'],
        ];
    }
}
