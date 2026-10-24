<?php

declare(strict_types=1);

namespace App\Support;

use FFI;
use Throwable;

final class PredbSearchDocument
{
    public const PREDB_IDENTITY_POLICY = '602ab87b80da1ca545fbb0cb363d26577d781442f51059251f07ef548be53a3d';

    private const string FFI_CDEF = <<<'C'
        int64_t nntmux_predb_validate_filename_candidate(const char *id_str, size_t id_len, int64_t id_int, uint32_t has_id_int, const char *title, size_t title_len, const char *filename, size_t filename_len, const char *source, size_t source_len);
        ssize_t nntmux_prometheus_format_text(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_release_plain_search_name(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        int64_t nntmux_datetime_to_unix_utc(const char *input, size_t input_len);
        C;

    private static FFI|false|null $ffi = null;

    private static bool $useNative = true;

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
        $rawId = $row['_id'] ?? null;
        if (! is_int($rawId) && ! is_string($rawId)) {
            return null;
        }
        $source = $row['_source'];
        $title = $source['title'] ?? null;
        if (! is_string($title) || $title === '') {
            return null;
        }
        $filename = $source['filename'] ?? '';
        $src = $source['source'] ?? '';
        if (! is_string($filename) || ! is_string($src)) {
            return null;
        }

        if (self::$useNative) {
            $ffi = self::$ffi ??= (RustPipelineLibrary::resolveDomain('search_doc_metrics', self::FFI_CDEF) ?? false);
            if ($ffi !== false) {
                try {
                    $hasInt = is_int($rawId) ? 1 : 0;
                    $idStr = $hasInt === 1 ? '' : $rawId;
                    // @phpstan-ignore method.notFound
                    $validId = $ffi->nntmux_predb_validate_filename_candidate(
                        $idStr,
                        strlen($idStr),
                        $hasInt === 1 ? $rawId : 0,
                        $hasInt,
                        $title,
                        strlen($title),
                        $filename,
                        strlen($filename),
                        $src,
                        strlen($src),
                    );
                    if (is_int($validId)) {
                        if ($validId > 0) {
                            return [
                                'id' => $validId,
                                'title' => $title,
                                'filename' => $filename,
                                'source' => $src,
                            ];
                        }
                        if ($validId === 0) {
                            return null;
                        }
                    }
                } catch (Throwable) {
                }
            }
        }

        return self::filenameCandidatePhp($row);
    }

    /** @return array{id: int, title: string, filename: string, source: string}|null */
    private static function filenameCandidatePhp(mixed $row): ?array
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
