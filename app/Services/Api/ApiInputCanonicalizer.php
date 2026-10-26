<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Single canonical form for API input, shared by HTTP QUERY normalization,
 * search cursor binding, and the usage audit, so equivalent GET and QUERY
 * filters (e.g. `cat=2000,5030` and `{"cat":[2000,5030]}`) compare equal.
 */
final class ApiInputCanonicalizer
{
    /**
     * Parameters that accept either a list or a comma-separated string.
     */
    public const LIST_PARAMETERS = ['cat'];

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_api_canonicalize_list_csv(const char *input_ptr, size_t input_len, char *out_buf, size_t out_cap);
        C;

    public const string W455_FFI_CDEF = 'int64_t nntmux_w455_api_capabilities_canonicalizer_query_cache_usage(uint32_t mode, const char *input, size_t input_len, int64_t *out, size_t cap);';

    /**
     * The Wave 45.5 list CSV summary defaults to Rust: every common and uncommon mean and p99 ratio
     * cleared 1.5x (min 5.32/4.71/9.82/8.49x); see
     * tests/Unit/RustApiCapabilitiesCanonicalizerQueryCacheUsageW45ParityTest.php.
     */
    public const bool W455_RUST_FFI_DEFAULT = true;

    private const int W455_NATIVE_MAX_PARTS = 1 << 22;

    private const int W455_NATIVE_MAX_BYTES = 67108864;

    private const int W455_OUT_CAP = 32;

    private static FFI|false|null $ffi = null;

    private static ?CData $outBuf512 = null;

    private static bool $useRustFfi = true;

    private static ?bool $useW455RustFfi = null;

    private static FFI|false|null $w455Ffi = null;

    private static ?CData $w455Out = null;

    public function __construct(
        private readonly bool $useNative = true,
    ) {}

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    /**
     * @param  array<array-key, mixed>  $parameters
     * @return array<string, string|array<array-key, mixed>|null>
     */
    public function canonicalize(array $parameters): array
    {
        $canonical = [];
        foreach ($parameters as $key => $value) {
            $key = (string) $key;
            $canonical[$key] = in_array($key, self::LIST_PARAMETERS, true)
                ? $this->listValue($value)
                : $this->value($value);
        }

        ksort($canonical, SORT_STRING);

        return $canonical;
    }

    /**
     * @param  array<array-key, mixed>  $parameters
     * @return array<string, string|array<array-key, mixed>|null>
     */
    public function canonicalizePhp(array $parameters): array
    {
        $canonical = [];
        foreach ($parameters as $key => $value) {
            $key = (string) $key;
            $canonical[$key] = in_array($key, self::LIST_PARAMETERS, true)
                ? $this->listValuePhp($value)
                : $this->value($value);
        }

        ksort($canonical, SORT_STRING);

        return $canonical;
    }

    public static function isListParameter(string $key): bool
    {
        return in_array($key, self::LIST_PARAMETERS, true);
    }

    public function canonicalizeListCsv(string $csv): ?string
    {
        if ($this->useNative && self::$useRustFfi && \str_contains($csv, ',')) {
            $supported = false;
            $native = $this->canonicalizeListCsvNative($csv, $supported);
            if ($supported) {
                return $native;
            }
        }

        return $this->canonicalizeListCsvPhp($csv);
    }

    /**
     * @param-out bool $supported
     */
    public function canonicalizeListCsvNative(string $csv, bool &$supported = false): ?string
    {
        $supported = false;
        if (! $this->useNative || ! self::$useRustFfi) {
            return null;
        }
        $len = \strlen($csv);
        if ($len === 0) {
            $supported = true;

            return null;
        }
        $ffi = self::$ffi ?? self::resolveFfi();
        if ($ffi === null || $ffi === false) {
            return null;
        }
        $cap = 512;
        $scratch = $len <= 512 ? self::$outBuf512 : RustPipelineLibrary::obtainScratch($ffi, $len, $cap);
        if ($scratch === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_api_canonicalize_list_csv($csv, $len, $scratch, $cap);
            if (! \is_int($written)) {
                return null;
            }
            if ($written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_api_canonicalize_list_csv($csv, $len, $scratch, $cap);
                if (! \is_int($written)) {
                    return null;
                }
            }
            if ($written < 0) {
                return null;
            }
            $supported = true;

            return $written === 0 ? null : FFI::string($scratch, $written);
        } catch (Throwable) {
            $supported = false;

            return null;
        }
    }

    public function canonicalizeListCsvPhp(string $csv): ?string
    {
        return $this->listValuePhp($csv);
    }

    private function listValue(mixed $value): ?string
    {
        if (\is_scalar($value)) {
            return $this->canonicalizeListCsv((string) $value);
        }

        return $this->listValuePhp($value);
    }

    private function listValuePhp(mixed $value): ?string
    {
        if (is_array($value)) {
            $items = $value;
        } elseif (is_scalar($value)) {
            $items = explode(',', (string) $value);
        } else {
            return null;
        }

        $items = array_values(array_filter(
            array_map(static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '', $items),
            static fn (string $item): bool => $item !== ''
        ));

        return $items === [] ? null : implode(',', $items);
    }

    /**
     * @return string|array<array-key, mixed>|null
     */
    private function value(mixed $value): string|array|null
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value)) {
            $value = trim((string) $value);

            return $value === '' ? null : $value;
        }

        if (is_array($value)) {
            $nested = array_map(fn (mixed $item): string|array|null => $this->value($item), $value);
            ksort($nested, SORT_STRING);

            return $nested;
        }

        return null;
    }

    /** Pass null to restore {@see W455_RUST_FFI_DEFAULT}. */
    public static function useW455RustFfi(?bool $enable = true): void
    {
        self::$useW455RustFfi = $enable;
    }

    public static function usesW455RustFfi(): bool
    {
        return self::$useW455RustFfi ?? self::W455_RUST_FFI_DEFAULT;
    }

    public static function resetW455RustFfi(): void
    {
        self::$useW455RustFfi = null;
    }

    /**
     * Summarizes {@see canonicalizeListCsvPhp()} over scalar values (cast to string): `null_results`,
     * `changed` canonical lists, kept `items`, `dropped_items` (empty after trim), `canonical_bytes`,
     * `max_items` and `distinct_canonical`. Non-scalar values are invalid.
     *
     * @param  array<array-key, mixed>  $values
     * @return array{values: int, invalid: int, null_results: int, changed: int, items: int, dropped_items: int, canonical_bytes: int, max_items: int, distinct_canonical: int}
     */
    public function summarizeListCsvBatch(array $values): array
    {
        return self::usesW455RustFfi() ? $this->summarizeListCsvBatchNative($values) : $this->summarizeListCsvBatchPhp($values);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array{values: int, invalid: int, null_results: int, changed: int, items: int, dropped_items: int, canonical_bytes: int, max_items: int, distinct_canonical: int}
     */
    public function summarizeListCsvBatchPhp(array $values): array
    {
        $summary = ['values' => \count($values), 'invalid' => 0, 'null_results' => 0, 'changed' => 0, 'items' => 0, 'dropped_items' => 0, 'canonical_bytes' => 0, 'max_items' => 0, 'distinct_canonical' => 0];
        $distinct = [];
        foreach ($values as $value) {
            if (! \is_scalar($value)) {
                $summary['invalid']++;

                continue;
            }
            $source = (string) $value;
            $canonical = $this->listValuePhp($source);
            $parts = substr_count($source, ',') + 1;
            if ($canonical === null) {
                $summary['null_results']++;
                $summary['dropped_items'] += $parts;

                continue;
            }
            // Kept items are trimmed and non-empty, so they never contain a comma.
            $items = substr_count($canonical, ',') + 1;
            $summary['changed'] += (int) ($canonical !== $source);
            $summary['items'] += $items;
            $summary['dropped_items'] += $parts - $items;
            $summary['canonical_bytes'] += \strlen($canonical);
            $summary['max_items'] = max($summary['max_items'], $items);
            $distinct[$canonical] = true;
        }
        $summary['distinct_canonical'] = \count($distinct);

        return $summary;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array{values: int, invalid: int, null_results: int, changed: int, items: int, dropped_items: int, canonical_bytes: int, max_items: int, distinct_canonical: int}
     */
    public function summarizeListCsvBatchNative(array $values): array
    {
        $sources = self::scalarStrings($values);
        $fields = self::evaluateW455Native(2, $sources, 7);
        if ($fields === null) {
            return $this->summarizeListCsvBatchPhp($values);
        }

        return ['values' => \count($values), 'invalid' => \count($values) - \count($sources)]
            + array_combine(['null_results', 'changed', 'items', 'dropped_items', 'canonical_bytes', 'max_items', 'distinct_canonical'], $fields);
    }

    /**
     * Shared Wave 45.5 bridge for the API capabilities, canonicalizer, query parameter, release row
     * cache and usage summaries: returns exactly $fields counters, or null when the library is
     * unavailable or declined the batch so callers fall back to PHP.
     *
     * @param  list<string>  $parts
     * @return list<int>|null
     */
    public static function evaluateW455Native(int $mode, array $parts, int $fields): ?array
    {
        $ffi = self::w455Ffi();
        $count = \count($parts);
        if ($ffi === null || $mode < 1 || $fields < 1 || $fields > self::W455_OUT_CAP || $count > self::W455_NATIVE_MAX_PARTS) {
            return null;
        }

        try {
            $input = $count === 0 ? pack('V', 0) : pack('V*', $count, ...array_map('strlen', $parts)).implode('', $parts);
            $length = \strlen($input);
            if ($length > self::W455_NATIVE_MAX_BYTES) {
                return null;
            }
            $out = self::$w455Out ??= $ffi->new('int64_t['.self::W455_OUT_CAP.']');
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w455_api_capabilities_canonicalizer_query_cache_usage($mode, $input, $length, $out, self::W455_OUT_CAP);
            if ($written !== $fields) {
                return null;
            }
            $values = unpack('q*', FFI::string($out, $fields * 8));
        } catch (Throwable) {
            return null;
        }

        /** @var list<int>|null */
        return \is_array($values) ? array_values($values) : null;
    }

    /**
     * The scalar values cast to string, in order; others are left out.
     *
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    public static function scalarStrings(array $values): array
    {
        $strings = [];
        foreach ($values as $value) {
            if (\is_string($value)) {
                $strings[] = $value;
            } elseif (\is_scalar($value)) {
                $strings[] = (string) $value;
            }
        }

        return $strings;
    }

    private static function w455Ffi(): ?FFI
    {
        if (self::$w455Ffi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            self::$w455Ffi = RustPipelineLibrary::resolveDomain('search_w455', self::W455_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
            self::$w455Out = null;
        }

        return self::$w455Ffi instanceof FFI ? self::$w455Ffi : null;
    }

    private static function resolveFfi(): ?FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi === false ? null : self::$ffi;
        }
        $ffi = RustPipelineLibrary::resolveDomain('api_input_canonicalizer', self::FFI_CDEF);
        if ($ffi === null) {
            self::$ffi = false;

            return null;
        }
        try {
            self::$outBuf512 = $ffi->new('char[512]');
            self::$ffi = $ffi;

            return $ffi;
        } catch (Throwable) {
            self::$ffi = false;

            return null;
        }
    }

    /** C signature of the Wave 60.5 {@see self::inputPlanBatch()} helper. */
    private const string W60_INPUT_PLAN_FFI_CDEF = 'int64_t nntmux_w605_api_input_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /**
     * The Wave 60.5 plan batch defaults to Rust: every common and uncommon mean and p99 ratio cleared 1.5x
     * (min 5.93/4.50/6.99/4.94x); see
     * tests/Unit/RustApiCanonicalizerQueryParamsV1BuilderV2PresenterLogQueryW60ParityTest.php.
     */
    public const bool W60_RUST_FFI_DEFAULT = true;

    /** API functions (`t`, lowercased) and their {@see self::inputPlanBatch()} codes. */
    private const array W60_FUNCTIONS = ['search' => 1, 's' => 1, 'tvsearch' => 2, 'tv' => 2, 'movie' => 3, 'm' => 3, 'music' => 4, 'book' => 5, 'caps' => 6, 'c' => 6, 'details' => 7, 'd' => 7, 'get' => 8, 'g' => 8, 'info' => 9];

    /**
     * Wave 60.5 routing and FFI cache, kept in function-local statics (no static properties). Without $op it
     * reports the routing; 'set' swaps the override for $val and returns the previous one; 'reset' replaces the
     * cached handle with $val (null re-resolves, false disables); 'buffer' returns `[FFI, int64_t[], capacity]`
     * with room for at least $val words, or null without the library.
     */
    private static function w60RustFfiState(?string $op = null, mixed $val = null): mixed
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
                    $ffi = RustPipelineLibrary::resolveDomain('w60_5', self::W60_INPUT_PLAN_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
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
                return $enabled ?? self::W60_RUST_FFI_DEFAULT;
        }
    }

    public static function usesW60RustFfi(): bool
    {
        return self::w60RustFfiState() === true;
    }

    /**
     * Runs $callback with inputPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW60RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w60RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w60RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 60.5 Rust helper over $count NUL-joined records, or null
     * to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w60Words(int $count, string $data, int $words): ?array
    {
        try {
            $handle = self::w60RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w605_api_input_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
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
     * Batch Newznab input plan over `t\tcat\tid\trid\timdbid\ttmdbid\ttraktid\tseason\tep`. Eight words per record:
     * `[function, cat_count, cat_first, id_kind, id_mask, season, episode, flags]`. Function codes (trimmed, ASCII
     * case-insensitive): 1 search/s, 2 tvsearch/tv, 3 movie/m, 4 music, 5 book, 6 caps/c, 7 details/d, 8 get/g,
     * 9 info, else 0. Categories split on commas as {@see canonicalizeListCsvPhp()} does (trimmed, empties dropped);
     * the first kept item reads as 1-6 digits or -1 (0 with none). The trimmed id is 0 empty, 1 forty hex digits,
     * 2 other. The id mask sets 1 rid, 2 imdbid (optional `tt`, 1-8 digits), 4 tmdbid and 8 traktid (1-10 digits)
     * for positive values. Season (optional `S`) and episode (optional `E`) are 1-4 digits, -1 when empty or
     * invalid. Flags: 1 empty function, 2 unknown function, 4 non-numeric category, 8 dropped empty category,
     * 16 invalid external id, 32 invalid season, 64 invalid episode, 128 episode without season, 256 not exactly
     * nine fields.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function inputPlanBatch(array $records): array
    {
        return self::usesW60RustFfi() ? self::inputPlanBatchNative($records) : self::inputPlanBatchPhp($records);
    }

    /**
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function inputPlanBatchPhp(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $fields = explode("\t", $record);
            $flags = \count($fields) === 9 ? 0 : 256;
            $t = strtolower(trim($fields[0]));
            $function = self::W60_FUNCTIONS[$t] ?? 0;
            if ($t === '') {
                $flags |= 1;
            } elseif ($function === 0) {
                $flags |= 2;
            }
            $cat = $fields[1] ?? '';
            $parts = explode(',', $cat);
            $kept = $first = 0;
            foreach ($parts as $part) {
                $item = trim($part);
                if ($item === '') {
                    continue;
                }
                $value = self::w60Digits($item, 6);
                if ($value === null) {
                    $flags |= 4;
                }
                if ($kept === 0) {
                    $first = $value ?? -1;
                }
                $kept++;
            }
            if ($cat !== '' && $kept < \count($parts)) {
                $flags |= 8;
            }
            $id = trim($fields[2] ?? '');
            $idKind = $id === '' ? 0 : (\strlen($id) === 40 && ctype_xdigit($id) ? 1 : 2);
            $mask = 0;
            foreach ([3 => 1, 4 => 2, 5 => 4, 6 => 8] as $index => $bit) {
                $field = trim($fields[$index] ?? '');
                if ($field === '') {
                    continue;
                }
                if ($bit === 2 && strncasecmp($field, 'tt', 2) === 0) {
                    $field = substr($field, 2);
                }
                $value = self::w60Digits($field, $bit === 2 ? 8 : 10);
                if ($value !== null && $value > 0) {
                    $mask |= $bit;
                } else {
                    $flags |= 16;
                }
            }
            $season = self::w60Numbered(trim($fields[7] ?? ''), 's', 32, $flags);
            $episode = self::w60Numbered(trim($fields[8] ?? ''), 'e', 64, $flags);
            if ($episode >= 0 && $season < 0) {
                $flags |= 128;
            }
            array_push($out, $function, $kept, $first, $idKind, $mask, $season, $episode, $flags);
        }

        return $out;
    }

    /** Season/episode number: one optional ASCII case-insensitive $marker, then 1-4 digits; -1 when empty or invalid. */
    private static function w60Numbered(string $text, string $marker, int $flag, int &$flags): int
    {
        if ($text === '') {
            return -1;
        }
        if (strtolower($text[0]) === $marker) {
            $text = substr($text, 1);
        }
        $value = self::w60Digits($text, 4);
        if ($value === null) {
            $flags |= $flag;

            return -1;
        }

        return $value;
    }

    /** 1-$max ASCII digits as an integer, else null. */
    private static function w60Digits(string $text, int $max): ?int
    {
        return $text !== '' && \strlen($text) <= $max && ctype_digit($text) ? (int) $text : null;
    }

    /**
     * Rust batch over the NUL-joined records; falls back to PHP without the library or when a record holds
     * a NUL.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function inputPlanBatchNative(array $records): array
    {
        $count = \count($records);
        if ($count === 0) {
            return [];
        }

        return self::w60Words($count, implode("\0", $records), 8 * $count) ?? self::inputPlanBatchPhp($records);
    }
}
