<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\Media;

use App\Support\RustPipelineLibrary;
use FFI\CData;

/**
 * Orders the images of a release by how well they show what it contains.
 *
 * Screen captures and contact sheets show the content itself, covers come next,
 * and scans of disc labels, backs, proofs and banners are kept for last.
 */
final class ImageCandidateRanker
{
    // Production adoption requires >= 1.5x mean and p99 on both W43 workloads.
    public const bool W43_RUST_FFI_DEFAULT = false;

    private static ?bool $w43RustFfi = null;

    public static function useRustFfi(?bool $enabled = true): void
    {
        self::$w43RustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$w43RustFfi ?? self::W43_RUST_FFI_DEFAULT;
    }

    private const string CONTENT = '/(?:^|[^a-z])(?:screens?|screenshots?|screencaps?|caps|contact[ ._-]?sheets?|sheet|thumbs?|thumbnails?|previews?|samples?)(?:[^a-z]|$)/i';

    private const string COVER = '/(?:^|[^a-z])(?:cover|front|poster|folder|box|keyart)(?:[^a-z]|$)/i';

    private const string LEAST = '/(?:^|[^a-z])(?:back|rear|cd\d*|dvd\d*|disc\d*|disk\d*|label|inlay|insert|proof|logo|banner|avatar|sig|signature|spine)(?:[^a-z]|$)/i';

    /**
     * Lower is better.
     */
    public static function rank(string $name, ?string $libraryPath = null): int
    {
        return self::usesRustFfi() ? self::rankNative($name, $libraryPath) : self::rankPhp($name);
    }

    private const string FFI_CDEF = 'int32_t nntmux_w436_image_candidate_rank(const char *name, size_t len);';

    private static ?\FFI $ffi = null;

    private static bool $ffiResolved = false;

    public static function rankNative(string $name, ?string $libraryPath = null, bool &$supported = false): int
    {
        $supported = false;
        if ($libraryPath !== null) {
            $ffi = RustPipelineLibrary::resolveDomain('w436_image_candidate_ranker', self::FFI_CDEF, $libraryPath);
        } else {
            if (! self::$ffiResolved) {
                self::$ffiResolved = true;
                self::$ffi = RustPipelineLibrary::resolveDomain('w436_image_candidate_ranker', self::FFI_CDEF);
            }
            $ffi = self::$ffi;
        }
        if ($ffi !== null) {
            try {
                // @phpstan-ignore method.notFound (Dynamically declared C ABI symbol.)
                $rank = $ffi->nntmux_w436_image_candidate_rank($name, strlen($name));
                if (is_int($rank) && $rank >= 0 && $rank <= 3) {
                    $supported = true;

                    return $rank;
                }
            } catch (\Throwable) {
                // The PHP reference also handles names the native parser rejects.
            }
        }

        return self::rankPhp($name);
    }

    public static function rankPhp(string $name): int
    {
        $name = self::fileName($name);

        // "CD1.screens.jpg" is a contact sheet; "cover.back.jpg" is not a front cover.
        return match (true) {
            preg_match(self::CONTENT, $name) === 1 => 0,
            preg_match(self::LEAST, $name) === 1 => 3,
            preg_match(self::COVER, $name) === 1 => 1,
            default => 2,
        };
    }

    /**
     * Sort by rank, then larger (higher resolution) first.
     *
     * @template T
     *
     * @param  list<T>  $items
     * @param  callable(T): string  $nameOf
     * @param  callable(T): int  $sizeOf
     * @return list<T>
     */
    public static function sort(array $items, callable $nameOf, callable $sizeOf, ?string $libraryPath = null): array
    {
        $count = count($items);
        if ($count <= 1) {
            return array_values($items);
        }

        $ranks = [];
        $negSizes = [];
        $order = [];
        $list = array_values($items);
        foreach ($list as $index => $item) {
            $ranks[$index] = self::rank($nameOf($item), $libraryPath);
            $negSizes[$index] = -$sizeOf($item);
            $order[$index] = $index;
        }

        array_multisort($ranks, SORT_ASC, $negSizes, SORT_ASC, $order, SORT_ASC, $list);

        return $list;
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @param  callable(T): string  $nameOf
     * @param  callable(T): int  $sizeOf
     * @return list<T>
     */
    public static function sortPhp(array $items, callable $nameOf, callable $sizeOf): array
    {
        usort($items, static fn (mixed $a, mixed $b): int => [self::rankPhp($nameOf($a)), -$sizeOf($a)] <=> [self::rankPhp($nameOf($b)), -$sizeOf($b)]);

        return $items;
    }

    /**
     * The file name inside a yEnc subject, or the base name of a path.
     */
    public static function fileName(string $name): string
    {
        if (preg_match('/"([^"]+)"/', $name, $quoted) === 1) {
            $name = $quoted[1];
        }

        return basename(str_replace('\\', '/', trim($name)));
    }

    // Rust is the default only while the paired W51.6 benchmark clears 1.5x on all four metrics.
    private const bool W51_RUST_FFI_DEFAULT = true;

    public const string W51_FFI_CDEF = <<<'C'
        int32_t nntmux_w516_image_rank_batch(const char *names, size_t names_len, const char *name_twin, size_t name_twin_len, const char *sizes, size_t sizes_len, size_t count, int64_t *out, size_t out_count);
        C;

    public const int W51_RANK_HEADER = 7;

    private const int W51_VALUE_CAP = (1 << 40) - 1;

    private const int W51_MAX_ITEMS = 1_048_576;

    private static ?bool $w51RustFfi = null;

    private static \FFI|false|null $w51Ffi = null;

    private static ?CData $w51Out = null;

    private static int $w51Capacity = 0;

    /**
     * Rank a batch of image names as `rankPhp()` does and order them as `sort()` does.
     *
     * Sizes are clamped to 0..2^40-1 (missing sizes are 0). Layout: `[0]` count, `[1..4]` per
     * rank, `[5]` index of the best image (-1 when empty), `[6]` FNV-1a digest; then per name
     * `rank | size << 4`, then the indexes in order (rank, then larger size, then input order).
     *
     * @param  array<array-key, string>  $names
     * @param  array<array-key, int>  $sizes
     * @return list<int>
     */
    public static function rankCandidateBatch(array $names, array $sizes): array
    {
        return (self::usesW51RustFfi() ? self::rankCandidateBatchNative($names, $sizes) : null) ?? self::rankCandidateBatchPhp($names, $sizes);
    }

    /**
     * @param  array<array-key, string>  $names
     * @param  array<array-key, int>  $sizes
     * @return list<int>
     */
    public static function rankCandidateBatchPhp(array $names, array $sizes): array
    {
        $names = array_values($names);
        $sizes = array_values($sizes);
        $count = \count($names);
        $out = [$count, 0, 0, 0, 0, -1, 0];
        $digest = 2_166_136_261;
        $rows = $ranks = $negSizes = $order = [];
        foreach ($names as $index => $name) {
            $rank = self::rankPhp($name);
            $size = min(max($sizes[$index] ?? 0, 0), self::W51_VALUE_CAP);
            $out[1 + $rank]++;
            $row = $rank | $size << 4;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
            $ranks[] = $rank;
            $negSizes[] = -$size;
            $order[] = $index;
        }
        if ($count > 0) {
            array_multisort($ranks, SORT_ASC, SORT_NUMERIC, $negSizes, SORT_ASC, SORT_NUMERIC, $order, SORT_ASC, SORT_NUMERIC);
            foreach ($order as $index) {
                $digest = (($digest ^ $index) * 16_777_619) & 0xFFFFFFFF;
            }
            $out[5] = $order[0];
        }
        $out[6] = $digest;

        return [...$out, ...$rows, ...$order];
    }

    /**
     * Rust ranking, or null when PHP must compute it (unavailable library, non-ASCII names,
     * trailing separators or an empty quoted pair, whose PHP file-name semantics differ).
     *
     * @param  array<array-key, string>  $names
     * @param  array<array-key, int>  $sizes
     * @return list<int>|null
     */
    public static function rankCandidateBatchNative(array $names, array $sizes): ?array
    {
        $names = array_values($names);
        $count = \count($names);
        $fields = self::W51_RANK_HEADER + 2 * $count;
        $binding = $count <= self::W51_MAX_ITEMS ? self::w51Binding($fields) : null;
        if ($binding === null) {
            return null;
        }
        [$ffi, $out] = $binding;
        [$joined, $twin] = self::w51Strings($names);
        $sizes = array_values($sizes);
        $packed = pack('q*', ...(\count($sizes) === $count ? $sizes : array_pad(\array_slice($sizes, 0, $count), $count, 0)));
        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_w516_image_rank_batch($joined, \strlen($joined), $twin, \strlen($twin), $packed, \strlen($packed), $count, $out, $fields);

            return self::w51Decode(\is_int($status) ? $status : -1, $out, $fields);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Run `$callback` with the Wave 51.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW51RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$w51RustFfi;
        self::$w51RustFfi = $enabled;
        try {
            return $callback();
        } finally {
            self::$w51RustFfi = $previous;
        }
    }

    public static function usesW51RustFfi(): bool
    {
        return self::$w51RustFfi ?? self::W51_RUST_FFI_DEFAULT;
    }

    /**
     * The cached Wave 51.6 binding and an output buffer of at least `$fields` values, or null.
     *
     * @return array{0: \FFI, 1: CData}|null
     */
    private static function w51Binding(int $fields): ?array
    {
        try {
            self::$w51Ffi ??= RustPipelineLibrary::resolveDomain('additional_w51', self::W51_FFI_CDEF) ?? false;
            if (self::$w51Ffi === false) {
                return null;
            }
            $out = self::$w51Out;
            if ($out === null || self::$w51Capacity < $fields) {
                self::$w51Capacity = max(256, $fields);
                $out = self::$w51Out = self::$w51Ffi->new('int64_t['.self::$w51Capacity.']');
            }
        } catch (\Throwable) {
            return null;
        }

        return [self::$w51Ffi, $out];
    }

    /**
     * Decode `$fields` output values after a successful Wave 51.6 call.
     *
     * @return list<int>|null
     */
    private static function w51Decode(int $status, CData $out, int $fields): ?array
    {
        if ($status !== 1) {
            return null;
        }
        $values = unpack('P*', \FFI::string($out, $fields * 8));

        return $values === false ? null : array_values($values);
    }

    /**
     * NUL-joined values and, when one holds a NUL, the same list joined with 0x01 so Rust
     * finds the separators where the two differ.
     *
     * @param  array<array-key, string>  $items
     * @return array{0: string, 1: string}
     */
    private static function w51Strings(array $items): array
    {
        $joined = implode("\0", $items);

        return $items !== [] && substr_count($joined, "\0") !== \count($items) - 1 ? [$joined, implode("\x01", $items)] : [$joined, ''];
    }

    /** C signature of the Wave 64.5 {@see self::w64ImageRankerPlanBatch()} helper. */
    private const string W64_IMAGE_RANKER_PLAN_FFI_CDEF = 'int64_t nntmux_w645_image_ranker_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /**
     * The Wave 64.5 plan batch defaults to Rust: every common and uncommon mean and p99 ratio cleared 1.5x
     * (min 3.20/3.32/2.62/2.64x); see tests/Unit/RustAdditionalFfmpegFrameScorerRankerInspectorFetcherW64ParityTest.php.
     */
    public const bool W64_RUST_FFI_DEFAULT = true;

    /**
     * Wave 64.5 routing and FFI cache, kept in function-local statics (no properties). Without $op it
     * reports the routing; 'set' swaps the override for $val and returns the previous one; 'reset' replaces the
     * cached handle with $val (null re-resolves, false disables); 'buffer' returns `[FFI, int64_t[], capacity]`
     * with room for at least $val words, or null without the library.
     */
    private static function w64RustFfiState(?string $op = null, mixed $val = null): mixed
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
                    $ffi = RustPipelineLibrary::resolveDomain('w64_5', self::W64_IMAGE_RANKER_PLAN_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
                }
                if (! $ffi instanceof \FFI || ! \is_int($val)) {
                    return null;
                }
                if ($out === null || $outCap < $val) {
                    $outCap = max(1024, $val);
                    $out = $ffi->new('int64_t['.$outCap.']');
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled ?? self::W64_RUST_FFI_DEFAULT;
        }
    }

    /** Route the Wave 64.5 plan batch to Rust until disabled or reset. */
    public static function enableW64RustFfi(): void
    {
        self::w64RustFfiState('set', true);
    }

    /** Route the Wave 64.5 plan batch to PHP until enabled or reset. */
    public static function disableW64RustFfi(): void
    {
        self::w64RustFfiState('set', false);
    }

    /** Restore W64_RUST_FFI_DEFAULT routing and drop the cached Wave 64.5 handle and buffer. */
    public static function resetW64RustFfi(): void
    {
        self::w64RustFfiState('set', null);
        self::w64RustFfiState('reset', null);
    }

    public static function usesW64RustFfi(): bool
    {
        return self::w64RustFfiState() === true;
    }

    /**
     * Runs $callback with w64ImageRankerPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW64RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w64RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w64RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 64.5 Rust helper over $count NUL-joined records, or null
     * to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w64Words(int $count, string $data, int $words): ?array
    {
        try {
            $handle = self::w64RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w645_image_ranker_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('q*', \FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Trimmed 1-15 ASCII digits as an integer; empty yields $default, anything else sets bit 1 and yields $default. */
    private static function w64Number(string $field, int $default, int &$flags): int
    {
        $text = trim($field);
        if ($text === '') {
            return $default;
        }
        if (\strlen($text) <= 15 && strspn($text, '0123456789') === \strlen($text)) {
            return (int) $text;
        }
        $flags |= 1;

        return $default;
    }

    /**
     * Batch candidate ranking plan over `name\twidth\theight\tbytes\trole`. Ten words per record: `[rank,
     * explicit_role, extension, width, height, size_class, bytes, bytes_per_kilopixel, score, flags]`. The name is
     * the first non-empty `"..."` part of the field (else the whole field), trimmed, with a backslash read as `/`, trailing
     * slashes dropped and only the text after the last slash kept. A trimmed role of content, cover, other or
     * least (ASCII case-insensitive) sets rank 0-3 explicitly; otherwise the name's rankPhp() keyword rank
     * applies. The extension is the 1-based position of jpg, jpeg, png, gif, webp or bmp (case-insensitive) after
     * the last dot, else 0. Numbers are trimmed 1-15 ASCII digits (else 0); width and height are capped at 65535.
     * Size class: 0 unknown, 1 an edge below 150, 2 under 640x480 pixels, else 3. The density is `bytes * 1000 /
     * pixels` (0 without both), and score `(3 - rank) << 50 | size_class << 48 | pixels << 16 | min(bytes >> 10,
     * 65535)` orders candidates (higher is better). Flags: 1 invalid number, 2 not exactly five fields, 4 capped
     * dimension, 8 unknown role, 16 empty name, 32 quoted name.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w64ImageRankerPlanBatch(array $records): array
    {
        return self::usesW64RustFfi() ? self::w64ImageRankerPlanBatchNative($records) : self::w64ImageRankerPlanBatchPhp($records);
    }

    /**
     * Rust batch over the NUL-joined records; falls back to PHP without the library or when a record holds
     * a NUL.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w64ImageRankerPlanBatchNative(array $records): array
    {
        $count = \count($records);
        if ($count === 0) {
            return [];
        }

        return self::w64Words($count, implode("\0", $records), 10 * $count) ?? self::w64ImageRankerPlanBatchPhp($records);
    }

    private const array W64_ROLES = ['content', 'cover', 'other', 'least'];

    private const array W64_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    /**
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w64ImageRankerPlanBatchPhp(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $fields = explode("\t", $record);
            $flags = \count($fields) === 5 ? 0 : 2;
            $name = $fields[0];
            if (preg_match('/"([^"]+)"/', $name, $quoted) === 1) {
                $name = $quoted[1];
                $flags |= 32;
            }
            $name = rtrim(trim(strtr($name, '\\', '/')), '/');
            $slash = strrpos($name, '/');
            $base = $slash === false ? $name : substr($name, $slash + 1);
            if ($base === '') {
                $flags |= 16;
            }
            $role = strtolower(trim($fields[4] ?? ''));
            $rank = array_search($role, self::W64_ROLES, true);
            $explicit = 1;
            if ($rank === false) {
                if ($role !== '') {
                    $flags |= 8;
                }
                $explicit = 0;
                $rank = match (true) {
                    preg_match(self::CONTENT, $base) === 1 => 0,
                    preg_match(self::LEAST, $base) === 1 => 3,
                    preg_match(self::COVER, $base) === 1 => 1,
                    default => 2,
                };
            }
            $dot = strrpos($base, '.');
            $extension = $dot === false ? false : array_search(strtolower(substr($base, $dot + 1)), self::W64_EXTENSIONS, true);
            $width = self::w64Number($fields[1] ?? '', 0, $flags);
            $height = self::w64Number($fields[2] ?? '', 0, $flags);
            $bytes = self::w64Number($fields[3] ?? '', 0, $flags);
            if ($width > 65_535) {
                $width = 65_535;
                $flags |= 4;
            }
            if ($height > 65_535) {
                $height = 65_535;
                $flags |= 4;
            }
            $pixels = $width * $height;
            $sizeClass = match (true) {
                $width === 0 || $height === 0 => 0,
                min($width, $height) < 150 => 1,
                $pixels < 640 * 480 => 2,
                default => 3,
            };
            array_push(
                $out,
                $rank,
                $explicit,
                $extension === false ? 0 : $extension + 1,
                $width,
                $height,
                $sizeClass,
                $bytes,
                $pixels > 0 && $bytes > 0 ? intdiv($bytes * 1000, $pixels) : 0,
                (3 - $rank) << 50 | $sizeClass << 48 | $pixels << 16 | min($bytes >> 10, 65_535),
                $flags,
            );
        }

        return $out;
    }
}
