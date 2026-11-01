<?php

declare(strict_types=1);

namespace App\Support;

final class PredbSearchDocument
{
    public const PREDB_IDENTITY_POLICY = '50f8235fba9c3de439431e0d83e45b393187d7f2997451d98c60b9af5ecd6445';

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

        // These are the persisted source-column domains, not truncation targets.
        foreach (['title' => 255, 'filename' => 255, 'source' => 50] as $field => $maximum) {
            if (isset($source[$field]) && (strlen($source[$field]) > $maximum * 4
                || ! mb_check_encoding($source[$field], 'UTF-8') || mb_strlen($source[$field], 'UTF-8') > $maximum)) {
                return null;
            }
        }

        foreach (['title', 'filename'] as $field) {
            if (isset($source[$field]) && (! mb_check_encoding($source[$field], 'UTF-8')
                || preg_match('/\p{C}/u', $source[$field]) !== 0)) {
                return null;
            }
        }

        if (strpbrk($source['title'], '/\\|') !== false) {
            return null;
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
}
