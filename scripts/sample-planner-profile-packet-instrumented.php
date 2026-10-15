<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use FFI;
use JsonException;
use Throwable;

class ProfiledNativeSpotCheckPlanner extends NativeSpotCheckPlanner
{
    // PRIVATE DIAGNOSTIC COPY ONLY. No native observer, reset, logging or I/O.
    private static int $attributionPhase = 0;

    private static int $attributionPublicOrdinal = 0;

    private static int $attributionInvocation = 0;

    private static bool $attributionOverflow = false;

    /** @var list<array{int,int,int,int,int,int,int,int}> */
    private static array $attributionTrace = [];

    public static function attributionPhase(int $phase, int $publicOrdinal): void
    {
        self::$attributionPhase = $phase;
        self::$attributionPublicOrdinal = $publicOrdinal;
    }

    /** @return array{capacity:int,invocation_capacity:int,overflow:bool,invocations:int,events:list<array{int,int,int,int,int,int,int,int}>} */
    public static function attributionRead(): array
    {
        return ['capacity' => 128, 'invocation_capacity' => 8, 'overflow' => self::$attributionOverflow,
            'invocations' => self::$attributionInvocation, 'events' => self::$attributionTrace];
    }

    private static function attributionBegin(int $stage, int $role = 0, int $invocation = 0): int
    {
        $slot = count(self::$attributionTrace);
        if ($slot >= 128) {
            self::$attributionOverflow = true;

            return -1;
        }
        self::$attributionTrace[$slot] = [self::$attributionPhase, self::$attributionPublicOrdinal,
            $invocation, $role, $stage, hrtime(true), 0, 0];

        return $slot;
    }

    private static function attributionEnd(int $slot): void
    {
        $ended = hrtime(true);
        if ($slot >= 0) {
            self::$attributionTrace[$slot][6] = $ended;
            self::$attributionTrace[$slot][7] = 1;
        }
    }

    private const int MINIMUM_ARTICLES = 1024;

    private const int MAXIMUM_INPUT_BYTES = 67_108_864;

    /** @var array<string, FFI|null> */
    private static array $libraries = [];

    public function __construct(private readonly ?string $libraryPath = null) {}

    /** @param array<string, mixed> $manifest */
    public static function supportsInventory(array $manifest): bool
    {
        $attributionMethod1 = self::attributionBegin(1);
        try {
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
        } finally {
            self::attributionEnd($attributionMethod1);
        }
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
        $attributionMethod3 = self::attributionBegin(3);
        try {
            $attribution2 = self::attributionBegin(2);
            try {
                if ($maximumArticles < 1 || $maximumHeaderBytes < 1) {
                    return [];
                }
                if ($maximumArticles > 16 || ! self::supportsInventory($manifest)) {
                    return null;
                }
            } finally {
                self::attributionEnd($attribution2);
            }
            $library = $this->library();
            $attribution4 = self::attributionBegin(4);
            try {
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
            } finally {
                self::attributionEnd($attribution4);
            }
            $attribution5 = self::attributionBegin(5);
            try {
                try {
                    $input = json_encode($projected, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    return null;
                }
            } finally {
                self::attributionEnd($attribution5);
            }
            $result = $this->call($library, $input, $maximumArticles, $maximumHeaderBytes, $maximumArticleBytes);

            return $this->validResult($result, $maximumArticles, $maximumHeaderBytes, $maximumArticleBytes) ? $result : null;
        } finally {
            self::attributionEnd($attributionMethod3);
        }
    }

    private function library(): ?FFI
    {
        $attributionMethod6 = self::attributionBegin(6);
        try {
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
                $attribution11 = self::attributionBegin(11);
                try {
                    $library = FFI::cdef(<<<'C'
                        char *nntmux_spot_check_sample_v1_json(const char *data, size_t length, int64_t maximum_articles, int64_t maximum_header_bytes, int64_t maximum_article_bytes, int has_maximum_article_bytes);
                        void nntmux_metadata_string_free(char *value);
                        C, $resolved);
                } finally {
                    self::attributionEnd($attribution11);
                }
                $knownInput = '{"files":[{"key":"probe","totalparts":2,"totalparts_inferred":true,"segments":[{"number":1,"messageid":"normal","bytes":2},{"number":2,"messageid":"terminal","bytes":2}]}]}';
                $expected = [['file_key' => 'probe', 'totalparts' => 2, 'totalparts_inferred' => true,
                    'number' => 2, 'messageid' => 'terminal', 'bytes' => 2]];
                if ($this->call($library, $knownInput, 1, 2, null, 1) !== $expected) {
                    return null;
                }
                self::$libraries[$key] = $library;

                return $library;
            } catch (Throwable) {
                return null;
            }
        } finally {
            self::attributionEnd($attributionMethod6);
        }
    }

    /** @return array<mixed>|null */
    private function call(FFI $library, string $input, int $maximumArticles, int $maximumHeaderBytes, ?int $maximumArticleBytes, int $attributionRole = 2): ?array
    {
        $attributionInvocation = ++self::$attributionInvocation;
        if ($attributionInvocation > 8) {
            self::$attributionOverflow = true;
        }
        $attributionMethod10 = self::attributionBegin(10, $attributionRole, $attributionInvocation);
        try {
            if (strlen($input) > self::MAXIMUM_INPUT_BYTES) {
                return null;
            }
            try {
                $attribution7 = self::attributionBegin(7, $attributionRole, $attributionInvocation);
                try {
                    // @phpstan-ignore method.notFound
                    $pointer = $library->nntmux_spot_check_sample_v1_json($input, strlen($input), $maximumArticles, $maximumHeaderBytes,
                        $maximumArticleBytes ?? 0, (int) ($maximumArticleBytes !== null));
                } finally {
                    self::attributionEnd($attribution7);
                }
                if ($pointer === null || FFI::isNull($pointer)) {
                    return null;
                }
                $attribution8 = self::attributionBegin(8, $attributionRole, $attributionInvocation);
                try {
                    try {
                        $encoded = FFI::string($pointer);
                    } finally {
                        $library->nntmux_metadata_string_free($pointer); // @phpstan-ignore method.notFound
                    }
                } finally {
                    self::attributionEnd($attribution8);
                }
                $attribution9 = self::attributionBegin(9, $attributionRole, $attributionInvocation);
                try {
                    $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
                } finally {
                    self::attributionEnd($attribution9);
                }

                return is_array($decoded) ? $decoded : null;
            } catch (Throwable) {
                return null;
            }
        } finally {
            self::attributionEnd($attributionMethod10);
        }
    }

    /** @param array<mixed>|null $result */
    private function validResult(?array $result, int $maximumArticles, int $maximumHeaderBytes, ?int $maximumArticleBytes): bool
    {
        $attributionMethod12 = self::attributionBegin(12);
        try {
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
        } finally {
            self::attributionEnd($attributionMethod12);
        }
    }
}
