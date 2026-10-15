<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use FFI;
use JsonException;
use Throwable;

class NativeSpotCheckPlanner
{
    private const int MINIMUM_ARTICLES = 1024;

    private const int MAXIMUM_INPUT_BYTES = 67_108_864;

    /** @var array<string, FFI|null> */
    private static array $libraries = [];

    public function __construct(private readonly ?string $libraryPath = null) {}

    /** @param array<string, mixed> $manifest */
    public static function supportsInventory(array $manifest): bool
    {
        if (! is_array($manifest['files'] ?? null)) {
            return false;
        }
        $articles = 0;
        foreach ($manifest['files'] as $file) {
            if (! is_array($file) || ! is_array($file['segments'] ?? null)) {
                return false;
            }
            $articles += count($file['segments']);
            if ($articles >= self::MINIMUM_ARTICLES) {
                return true;
            }
        }

        return false;
    }

    /**
     * Null delegates to the unchanged PHP planner. Project scalar fields before
     * JSON encoding so serialization cannot erase PHP types or invoke hooks on
     * unused manifest metadata.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<array{file_key:string,totalparts:int,totalparts_inferred:bool,number:int,messageid:string,bytes:int}>|null
     */
    public function select(array $manifest, int $maximumArticles, int $maximumHeaderBytes, ?int $maximumArticleBytes = null): ?array
    {
        if ($maximumArticles < 1 || $maximumHeaderBytes < 1) {
            return [];
        }
        if ($maximumArticles > 16 || ! self::supportsInventory($manifest)) {
            return null;
        }
        $library = $this->library();
        if ($library === null || ! array_is_list($manifest['files'])) {
            return null;
        }
        $identity = $manifest['identity'] ?? '';
        if (! is_string($identity)) {
            return null;
        }
        $projected = ['identity' => $identity, 'files' => []];
        foreach ($manifest['files'] as $file) {
            if (! is_array($file) || ! is_string($file['key'] ?? null)
                || ! is_array($file['segments'] ?? null) || ! array_is_list($file['segments'])) {
                return null;
            }
            $total = $file['totalparts'] ?? 0;
            $inferred = $file['totalparts_inferred'] ?? false;
            if (! is_int($total) || ! is_bool($inferred)) {
                return null;
            }
            $parts = [];
            foreach ($file['segments'] as $segment) {
                if (! is_array($segment) || ! is_int($segment['number'] ?? null)
                    || ! is_string($segment['messageid'] ?? null) || ! is_int($segment['bytes'] ?? null)) {
                    return null;
                }
                $parts[] = ['number' => $segment['number'], 'messageid' => $segment['messageid'], 'bytes' => $segment['bytes']];
            }
            $projected['files'][] = ['key' => $file['key'], 'totalparts' => $total,
                'totalparts_inferred' => $inferred, 'segments' => $parts];
        }
        try {
            $input = json_encode($projected, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        $result = $this->call($library, $input, $maximumArticles, $maximumHeaderBytes, $maximumArticleBytes);

        return $this->validResult($result, $maximumArticles, $maximumHeaderBytes, $maximumArticleBytes) ? $result : null;
    }

    private function library(): ?FFI
    {
        if (PHP_OS_FAMILY !== 'Linux' || PHP_SAPI !== 'cli' || ! extension_loaded('FFI')) {
            return null;
        }
        try {
            $path = $this->libraryPath ?? (string) config('yenc.native_library', '');
        } catch (Throwable) {
            return null;
        }
        $resolved = realpath($path);
        if ($resolved === false || ! is_file($resolved) || ! is_readable($resolved)) {
            return null;
        }
        $key = getmypid().':'.$resolved;
        if (array_key_exists($key, self::$libraries)) {
            return self::$libraries[$key];
        }
        self::$libraries[$key] = null;
        try {
            $library = FFI::cdef(<<<'C'
                char *nntmux_spot_check_sample_v1_json(const char *data, size_t length, int64_t maximum_articles, int64_t maximum_header_bytes, int64_t maximum_article_bytes, int has_maximum_article_bytes);
                void nntmux_metadata_string_free(char *value);
                C, $resolved);
            $knownInput = '{"files":[{"key":"probe","totalparts":2,"totalparts_inferred":true,"segments":[{"number":1,"messageid":"normal","bytes":2},{"number":2,"messageid":"terminal","bytes":2}]}]}';
            $expected = [['file_key' => 'probe', 'totalparts' => 2, 'totalparts_inferred' => true,
                'number' => 2, 'messageid' => 'terminal', 'bytes' => 2]];
            if ($this->call($library, $knownInput, 1, 2, null) !== $expected) {
                return null;
            }
            self::$libraries[$key] = $library;

            return $library;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<mixed>|null */
    private function call(FFI $library, string $input, int $maximumArticles, int $maximumHeaderBytes, ?int $maximumArticleBytes): ?array
    {
        if (strlen($input) > self::MAXIMUM_INPUT_BYTES) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $pointer = $library->nntmux_spot_check_sample_v1_json($input, strlen($input), $maximumArticles, $maximumHeaderBytes,
                $maximumArticleBytes ?? 0, (int) ($maximumArticleBytes !== null));
            if ($pointer === null || FFI::isNull($pointer)) {
                return null;
            }
            try {
                $encoded = FFI::string($pointer);
            } finally {
                $library->nntmux_metadata_string_free($pointer); // @phpstan-ignore method.notFound
            }
            $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<mixed>|null $result */
    private function validResult(?array $result, int $maximumArticles, int $maximumHeaderBytes, ?int $maximumArticleBytes): bool
    {
        if ($result === null || ! array_is_list($result) || count($result) > $maximumArticles) {
            return false;
        }
        $used = 0;
        $seen = [];
        foreach ($result as $row) {
            if (! is_array($row) || array_keys($row) !== ['file_key', 'totalparts', 'totalparts_inferred', 'number', 'messageid', 'bytes']
                || ! is_string($row['file_key']) || ! is_int($row['totalparts']) || $row['totalparts'] < 0
                || ! is_bool($row['totalparts_inferred']) || ! is_int($row['number']) || $row['number'] < 1
                || ! is_string($row['messageid']) || $row['messageid'] === '' || isset($seen[$row['messageid']])
                || ! is_int($row['bytes']) || $row['bytes'] < 1
                || $row['bytes'] > min($maximumHeaderBytes, $maximumArticleBytes ?? $maximumHeaderBytes)
                || $row['bytes'] > $maximumHeaderBytes - $used) {
                return false;
            }
            $used += $row['bytes'];
            $seen[$row['messageid']] = true;
        }

        return true;
    }
}
