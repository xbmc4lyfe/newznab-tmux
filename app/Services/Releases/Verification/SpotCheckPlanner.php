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

    public const string W58_FFI_CDEF = 'int64_t nntmux_w585_spot_check_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /** Most samples one Wave 58.5 spot-check plan selects. */
    private const int W58_MAX_SAMPLES = 64;

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W58.5 parity test's QUALIFICATION). */
    private const bool W58_RUST_FFI_DEFAULT = true;

    /**
     * Wave 58.5 routing and FFI cache, kept in function-local statics (readonly verification classes cannot declare
     * static properties, so every W58 class follows the same shape). Without $op it reports the routing; 'set' swaps
     * the override for $val and returns the previous one; 'reset' replaces the cached handle with $val (null
     * re-resolves, false disables); 'buffer' returns `[FFI, int64_t[], capacity]` with room for at least $val words,
     * or null without the library.
     */
    private static function w58RustFfiState(?string $op = null, mixed $val = null): mixed
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
            case 'reset':
                $ffi = $val;
                $out = null;
                $outCap = 0;

                return null;
            case 'buffer':
                if ($ffi === null) {
                    $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
                    $ffi = RustPipelineLibrary::resolveDomain('w58_5', self::W58_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
                }
                if (! $ffi instanceof FFI || ! \is_int($val)) {
                    return null;
                }
                if ($out === null || $outCap < $val) {
                    $outCap = max(1024, $val);
                    $out = $ffi->new('int64_t['.$outCap.']');
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled ?? self::W58_RUST_FFI_DEFAULT;
        }
    }

    public static function usesW58RustFfi(): bool
    {
        return self::w58RustFfiState() === true;
    }

    /**
     * Runs $callback with spotCheckPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW58RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w58RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w58RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 58.5 Rust helper over $count NUL-joined records, or null
     * to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w58Words(int $count, string $data, int $words): ?array
    {
        try {
            $handle = self::w58RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w585_spot_check_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('q*', FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Batch spot-check plan over comma-separated `parts, samples` (trimmed, each 1-12 digits). Eight words per record:
     * `[selected, stride, first, middle, last, index_sum, coverage, flags]`. Selected is `min(samples, parts)` with
     * samples capped at 64; the strided 1-based indices are `1 + i * stride` with stride `(parts - 1) / (selected -
     * 1)` (0 for one sample). First is 1, middle `(parts + 1) / 2` and last the final selected index; coverage is
     * `last * 1000 / parts` (permille). Without a selection every word but flags is 0. Flags: 1 parts invalid, 2
     * samples invalid (each read as 0), 4 samples capped, 8 not exactly two fields.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function spotCheckPlanBatch(array $records): array
    {
        return self::usesW58RustFfi() ? self::spotCheckPlanBatchNative($records) : self::spotCheckPlanBatchPhp($records);
    }

    /**
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function spotCheckPlanBatchPhp(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $fields = explode(',', $record);
            $flags = \count($fields) === 2 ? 0 : 8;
            $parts = trim($fields[0]);
            if (\strlen($parts) <= 12 && ctype_digit($parts)) {
                $parts = (int) $parts;
            } else {
                $flags |= 1;
                $parts = 0;
            }
            $samples = trim($fields[1] ?? '');
            if (\strlen($samples) <= 12 && ctype_digit($samples)) {
                $samples = (int) $samples;
            } else {
                $flags |= 2;
                $samples = 0;
            }
            if ($samples > self::W58_MAX_SAMPLES) {
                $samples = self::W58_MAX_SAMPLES;
                $flags |= 4;
            }
            $selected = min($samples, $parts);
            if ($selected === 0) {
                array_push($out, 0, 0, 0, 0, 0, 0, 0, $flags);

                continue;
            }
            $stride = $selected > 1 ? intdiv($parts - 1, $selected - 1) : 0;
            $indices = [];
            for ($i = 0; $i < $selected; $i++) {
                $indices[] = 1 + $i * $stride;
            }
            $last = $indices[$selected - 1];
            array_push($out, $selected, $stride, 1, intdiv($parts + 1, 2), $last, array_sum($indices), intdiv($last * 1000, $parts), $flags);
        }

        return $out;
    }

    /**
     * Rust batch over the NUL-joined records; falls back to PHP without the library or when a record holds
     * a NUL.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function spotCheckPlanBatchNative(array $records): array
    {
        $count = \count($records);
        if ($count === 0) {
            return [];
        }

        return self::w58Words($count, implode("\0", $records), 8 * $count) ?? self::spotCheckPlanBatchPhp($records);
    }
}
