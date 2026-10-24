<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

final class SpotCheckPlanner
{
    private const string FFI_CDEF = <<<'C'
        ssize_t nntmux_spot_check_plan_packed(const char *data, size_t length, const char *identity, size_t identity_length, int64_t maximum_articles, int64_t maximum_header_bytes, int64_t article_limit, char *out_buf, size_t out_cap);
        C;

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

    private static bool $useRustFfi = true;

    private bool $explicitNativePlanner;

    public function __construct(private ?NativeSpotCheckPlanner $native = null)
    {
        $this->explicitNativePlanner = $native !== null;
    }

    public static function setUseRustFfi(bool $use): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * Select a stable, bounded sample and spread first across different files.
     * A stable candidate identity keeps retries from repeatedly sampling a new set.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<array{file_key:string,totalparts:int,totalparts_inferred:bool,number:int,messageid:string,bytes:int}>
     */
    public function select(array $manifest, int $maximumArticles, int $maximumHeaderBytes, ?int $maximumArticleBytes = null): array
    {
        if ($maximumArticles < 1 || $maximumHeaderBytes < 1 || ! is_array($manifest['files'] ?? null)) {
            return [];
        }

        if ($maximumArticles <= 16 && NativeSpotCheckPlanner::supportsInventory($manifest)) {
            $sample = ($this->native ??= new NativeSpotCheckPlanner)->select($manifest, $maximumArticles, $maximumHeaderBytes, $maximumArticleBytes);
            if ($sample !== null) {
                return $sample;
            }
        }

        if (! $this->explicitNativePlanner && self::$useRustFfi) {
            $sample = self::selectPackedNative($manifest, $maximumArticles, $maximumHeaderBytes, $maximumArticleBytes);
            if ($sample !== null) {
                return $sample;
            }
        }

        return self::selectPhp($manifest, $maximumArticles, $maximumHeaderBytes, $maximumArticleBytes);
    }

    /**
     * Rust plan over `serialize($manifest['files'])`; null delegates to the PHP planner. Only a
     * string (or absent) identity is passed, so PHP keeps its lazy identity cast.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<array{file_key:string,totalparts:int,totalparts_inferred:bool,number:int,messageid:string,bytes:int}>|null
     */
    public static function selectPackedNative(array $manifest, int $maximumArticles, int $maximumHeaderBytes, ?int $maximumArticleBytes = null): ?array
    {
        if ($maximumArticles < 1 || $maximumHeaderBytes < 1 || ! is_array($manifest['files'] ?? null)) {
            return [];
        }
        $identity = $manifest['identity'] ?? '';
        if (! is_string($identity)) {
            return null;
        }
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            $input = serialize($manifest['files']);
            $length = \strlen($input);
            $limit = min($maximumHeaderBytes, $maximumArticleBytes ?? $maximumHeaderBytes);
            $capacity = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, 64 * min($maximumArticles, 4096), $capacity);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_spot_check_plan_packed($input, $length, $identity, \strlen($identity),
                $maximumArticles, $maximumHeaderBytes, $limit, $scratch, $capacity);
            if (\is_int($written) && $written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_spot_check_plan_packed($input, $length, $identity, \strlen($identity),
                    $maximumArticles, $maximumHeaderBytes, $limit, $scratch, $capacity);
            }
            if (! \is_int($written) || $written < 0 || $written % 64 !== 0) {
                return null;
            }
            if ($written === 0) {
                return [];
            }
            $cells = unpack('q*', FFI::string($scratch, $written));
            if ($cells === false) {
                return null;
            }
            $selected = [];
            for ($cell = 1, $end = \count($cells); $cell < $end; $cell += 8) {
                $selected[] = ['file_key' => substr($input, $cells[$cell], $cells[$cell + 1]), 'totalparts' => $cells[$cell + 2],
                    'totalparts_inferred' => $cells[$cell + 3] === 1, 'number' => $cells[$cell + 4],
                    'messageid' => substr($input, $cells[$cell + 5], $cells[$cell + 6]), 'bytes' => $cells[$cell + 7]];
            }

            return $selected;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<array{file_key:string,totalparts:int,totalparts_inferred:bool,number:int,messageid:string,bytes:int}>
     */
    public static function selectPhp(array $manifest, int $maximumArticles, int $maximumHeaderBytes, ?int $maximumArticleBytes = null): array
    {
        if ($maximumArticles < 1 || $maximumHeaderBytes < 1 || ! is_array($manifest['files'] ?? null)) {
            return [];
        }

        $identity = null;
        $files = [];
        foreach ($manifest['files'] as $file) {
            if (! is_array($file) || ! is_string($file['key'] ?? null) || ! is_array($file['segments'] ?? null)) {
                continue;
            }
            $segments = [];
            $ranks = [];
            foreach ($file['segments'] as $segment) {
                if (! is_array($segment) || ! is_string($segment['messageid'] ?? null)
                    || $segment['messageid'] === '' || ! is_numeric($segment['number'] ?? null)
                    || ! is_numeric($segment['bytes'] ?? null)) {
                    continue;
                }
                $bytes = (int) $segment['bytes'];
                $number = (int) $segment['number'];
                if ($number < 1 || $bytes < 1 || $bytes > min($maximumHeaderBytes, $maximumArticleBytes ?? $maximumHeaderBytes)) {
                    continue;
                }
                $identity ??= (string) ($manifest['identity'] ?? '');
                $ranks[$segment['messageid']] ??= hash('sha256', $identity."\0".$segment['messageid']);
                $segments[] = ['file_key' => $file['key'], 'totalparts' => max(0, (int) ($file['totalparts'] ?? 0)),
                    'totalparts_inferred' => ($file['totalparts_inferred'] ?? false) === true,
                    'number' => $number, 'messageid' => $segment['messageid'], 'bytes' => $bytes];
            }
            if ($segments !== []) {
                usort($segments, static function (array $a, array $b) use ($ranks): int {
                    if ($a['totalparts_inferred']) {
                        $terminal = (int) ($b['number'] === $b['totalparts']) <=> (int) ($a['number'] === $a['totalparts']);
                        if ($terminal !== 0) {
                            return $terminal;
                        }
                    }

                    return $ranks[$a['messageid']] <=> $ranks[$b['messageid']];
                });
                $files[$file['key']] = $segments;
            }
        }

        if ($files === []) {
            return [];
        }

        $keys = array_keys($files);
        $fileRanks = [];
        foreach ($keys as $key) {
            $fileRanks[$key] = hash('sha256', $identity."\0".$key);
        }
        usort($keys, static function (string $a, string $b) use ($fileRanks, $files): int {
            $inferred = (int) $files[$b][0]['totalparts_inferred'] <=> (int) $files[$a][0]['totalparts_inferred'];

            return $inferred !== 0 ? $inferred : $fileRanks[$a] <=> $fileRanks[$b];
        });

        $selected = [];
        $seen = [];
        $bytes = 0;
        for ($round = 0; count($selected) < $maximumArticles; $round++) {
            $added = false;
            foreach ($keys as $key) {
                if (! isset($files[$key][$round])) {
                    continue;
                }
                $segment = $files[$key][$round];
                if (isset($seen[$segment['messageid']]) || $bytes + $segment['bytes'] > $maximumHeaderBytes) {
                    continue;
                }
                $selected[] = $segment;
                $seen[$segment['messageid']] = true;
                $bytes += $segment['bytes'];
                $added = true;
                if (count($selected) >= $maximumArticles) {
                    break;
                }
            }
            if (! $added) {
                break;
            }
        }

        return $selected;
    }

    private static function ffi(): ?FFI
    {
        if (! self::$ffiResolved) {
            self::$ffi = RustPipelineLibrary::resolveDomain('spot_check_planner', self::FFI_CDEF);
            self::$ffiResolved = true;
        }

        return self::$ffi;
    }
}
