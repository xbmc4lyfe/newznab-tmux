<?php

declare(strict_types=1);

namespace App\Services\Api\V1;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

final readonly class XmlResponseContext
{
    /** The Rust helper is opt-in; it did not beat PHP by 1.5x in the paired benchmark. */
    private const string FFI_CDEF = 'int32_t nntmux_api_xml_context_mask(uint32_t tags);';

    private const array SLOTS = ['Parameters', 'Data', 'Server', 'Offset', 'Type'];

    /**
     * The Wave 46.5 legacy option shape summary keeps PHP: computing the slot tags in PHP is most of the
     * work, so Rust stayed below 1.5x (min 1.25/1.20/1.45/1.36x); see
     * tests/Unit/RustApiV1V2PresenterPayloadRowsXmlContextW46ParityTest.php.
     */
    public const bool W465_RUST_FFI_DEFAULT = false;

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $server
     */
    private function __construct(
        public array $parameters,
        public mixed $data,
        public array $server,
        public mixed $offset,
        public mixed $type,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public static function fromLegacyOptions(array $options, bool $useRustFfi = false): self
    {
        return ($useRustFfi ? self::fromLegacyOptionsNative($options) : null) ?? self::fromLegacyOptionsPhp($options);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public static function fromLegacyOptionsPhp(array $options): self
    {
        $defaults = [
            'Parameters' => [],
            'Data' => null,
            'Server' => [],
            'Offset' => null,
            'Type' => null,
        ];
        $options += $defaults;

        return new self(
            parameters: \is_array($options['Parameters']) ? $options['Parameters'] : [],
            data: $options['Data'],
            server: \is_array($options['Server']) ? $options['Server'] : [],
            offset: $options['Offset'],
            type: $options['Type'],
        );
    }

    /**
     * Rust slot selection from per-option type tags; null when FFI is unavailable.
     *
     * @param  array<string, mixed>  $options
     */
    public static function fromLegacyOptionsNative(array $options): ?self
    {
        static $ffi;
        $ffi ??= RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false;
        if ($ffi === false) {
            return null;
        }
        $tags = 0;
        foreach (self::SLOTS as $slot => $key) {
            $tag = match (true) {
                ! \array_key_exists($key, $options) => 0,
                $options[$key] === null => 1,
                \is_array($options[$key]) => 2,
                default => 3,
            };
            $tags |= $tag << (2 * $slot);
        }
        try {
            $mask = $ffi->nntmux_api_xml_context_mask($tags);
        } catch (Throwable) {
            return null;
        }
        if (! \is_int($mask) || $mask < 0) {
            return null;
        }

        return new self(
            parameters: ($mask & 1) !== 0 ? $options['Parameters'] : [],
            data: ($mask & 2) !== 0 ? $options['Data'] : null,
            server: ($mask & 4) !== 0 ? $options['Server'] : [],
            offset: ($mask & 8) !== 0 ? $options['Offset'] : null,
            type: ($mask & 16) !== 0 ? $options['Type'] : null,
        );
    }

    /** @return array{enabled: ?bool} */
    private static function &w465State(): array
    {
        // Readonly classes cannot declare static properties; a static local keeps the toggle.
        static $state = ['enabled' => null];

        return $state;
    }

    /** Pass null to restore {@see W465_RUST_FFI_DEFAULT}. */
    public static function useW465RustFfi(?bool $enable = true): void
    {
        $state = &self::w465State();
        $state['enabled'] = $enable;
    }

    public static function usesW465RustFfi(): bool
    {
        return self::w465State()['enabled'] ?? self::W465_RUST_FFI_DEFAULT;
    }

    public static function resetW465RustFfi(): void
    {
        $state = &self::w465State();
        $state['enabled'] = null;
    }

    /**
     * Summarizes the slots {@see fromLegacyOptionsPhp()} sees per option array: `provided` slots split
     * into `nulls`, `arrays` and `others`, `coerced` Parameters/Server values that are not arrays and
     * become [], `complete` sets with all five slots and `distinct_shapes`. Non-array sets are invalid.
     *
     * @param  array<array-key, mixed>  $optionSets
     * @return array{sets: int, invalid: int, provided: int, nulls: int, arrays: int, others: int, coerced: int, complete: int, distinct_shapes: int}
     */
    public static function summarizeLegacyOptionsBatch(array $optionSets): array
    {
        return self::usesW465RustFfi() ? self::summarizeLegacyOptionsBatchNative($optionSets) : self::summarizeLegacyOptionsBatchPhp($optionSets);
    }

    /**
     * @param  array<array-key, mixed>  $optionSets
     * @return array{sets: int, invalid: int, provided: int, nulls: int, arrays: int, others: int, coerced: int, complete: int, distinct_shapes: int}
     */
    public static function summarizeLegacyOptionsBatchPhp(array $optionSets): array
    {
        $summary = ['sets' => \count($optionSets), 'invalid' => 0, 'provided' => 0, 'nulls' => 0, 'arrays' => 0, 'others' => 0, 'coerced' => 0, 'complete' => 0, 'distinct_shapes' => 0];
        $shapes = [];
        foreach ($optionSets as $options) {
            if (! \is_array($options)) {
                $summary['invalid']++;

                continue;
            }
            $shape = 0;
            $present = 0;
            foreach (self::SLOTS as $slot => $key) {
                if (! \array_key_exists($key, $options)) {
                    continue;
                }
                $present++;
                $value = $options[$key];
                if (\is_array($value)) {
                    $summary['arrays']++;
                    $shape |= 2 << (2 * $slot);

                    continue;
                }
                $summary[$value === null ? 'nulls' : 'others']++;
                $summary['coerced'] += (int) ($slot === 0 || $slot === 2);
                $shape |= ($value === null ? 1 : 3) << (2 * $slot);
            }
            $summary['provided'] += $present;
            $summary['complete'] += (int) ($present === 5);
            $shapes[$shape] = true;
        }
        $summary['distinct_shapes'] = \count($shapes);

        return $summary;
    }

    /**
     * @param  array<array-key, mixed>  $optionSets
     * @return array{sets: int, invalid: int, provided: int, nulls: int, arrays: int, others: int, coerced: int, complete: int, distinct_shapes: int}
     */
    public static function summarizeLegacyOptionsBatchNative(array $optionSets): array
    {
        $shapes = [];
        foreach ($optionSets as $options) {
            if (! \is_array($options)) {
                continue;
            }
            $shape = 0;
            foreach (self::SLOTS as $slot => $key) {
                if (\array_key_exists($key, $options)) {
                    $value = $options[$key];
                    $shape |= ($value === null ? 1 : (\is_array($value) ? 2 : 3)) << (2 * $slot);
                }
            }
            $shapes[] = $shape;
        }
        $fields = ApiV1Presenter::evaluateW465Counters(4, $shapes === [] ? '' : pack('S*', ...$shapes), 7);
        if ($fields === null) {
            return self::summarizeLegacyOptionsBatchPhp($optionSets);
        }

        return ['sets' => \count($optionSets), 'invalid' => \count($optionSets) - \count($shapes)]
            + array_combine(['provided', 'nulls', 'arrays', 'others', 'coerced', 'complete', 'distinct_shapes'], $fields);
    }

    public const string W53_FFI_CDEF = 'int64_t nntmux_w535_xml_escape_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W53.5 parity test's QUALIFICATION). */
    private const bool W53_RUST_FFI_DEFAULT = true;

    /**
     * Wave 53.5 routing and FFI cache, kept in function-local statics so readonly classes can use them too.
     * Without $op it reports the routing; 'set' swaps the override for $val and returns the previous one;
     * 'reset' replaces the cached handle with $val (null re-resolves, false disables); 'buffer' returns
     * `[FFI, int64_t[], capacity]` with room for at least $val words, or null without the library.
     */
    private static function w53RustFfiState(?string $op = null, mixed $val = null): mixed
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
                    $ffi = RustPipelineLibrary::resolveDomain('w53_5', self::W53_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
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
                return $enabled ?? self::W53_RUST_FFI_DEFAULT;
        }
    }

    public static function usesW53RustFfi(): bool
    {
        return self::w53RustFfiState() === true;
    }

    /**
     * Runs $callback with xmlEscapePlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW53RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w53RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w53RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 53.5 Rust helper, or null to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w53Words(int $count, string $data, string $ints, int $words): ?array
    {
        try {
            $handle = self::w53RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w535_xml_escape_plan($count, 0, $data, \strlen($data), $ints, \strlen($ints), $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('P*', FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Batch sizing of XML attribute/text values. Three words per value: `[htmlspecialchars()` length with
     * ENT_XML1 | ENT_QUOTES (0 for invalid UTF-8), escaped `& < > " '` characters, XML 1.0 disallowed
     * control bytes]`.
     *
     * @param  list<string>  $values
     * @return list<int>
     */
    public static function xmlEscapePlanBatch(array $values): array
    {
        return self::usesW53RustFfi() ? self::xmlEscapePlanBatchNative($values) : self::xmlEscapePlanBatchPhp($values);
    }

    /**
     * @param  list<string>  $values
     * @return list<int>
     */
    public static function xmlEscapePlanBatchPhp(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $out[] = \strlen(htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
            $out[] = (int) preg_match_all('/[&<>"\']/', $value);
            $out[] = (int) preg_match_all('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value);
        }

        return $out;
    }

    /**
     * Rust batch over the NUL-joined values; falls back to PHP without the library, for invalid UTF-8
     * or when a value holds a NUL.
     *
     * @param  list<string>  $values
     * @return list<int>
     */
    public static function xmlEscapePlanBatchNative(array $values): array
    {
        $count = \count($values);
        if ($count === 0) {
            return [];
        }

        return self::w53Words($count, implode("\0", $values), '', 3 * $count) ?? self::xmlEscapePlanBatchPhp($values);
    }

    // Rust is the default only while the paired W65.6 benchmark clears 1.5x on all four metrics.
    public const bool W65_RUST_FFI_DEFAULT = true;

    private const string W65_XML_FFI_CDEF = <<<'C'
        int32_t nntmux_w656_xml_context_batch(const char *strings, size_t strings_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    public const int W65_XML_HEADER = 21;

    /** Integer columns in the order Rust reads them. */
    public const array W65_XML_COLUMNS = ['offset', 'total', 'items'];

    private const int W65_MAX_ITEMS = 1_048_576;

    /** Offset (2^31 - 1), total (2^40) and item (2^20 - 1) clamps. */
    private const int W65_XML_OFFSET_CAP = 2_147_483_647;

    private const int W65_XML_TOTAL_CAP = 1_099_511_627_776;

    private const int W65_XML_ITEMS_CAP = 1_048_575;

    /** `strtolower(trim($type))` => 0 caps, 1 search, 2 tvsearch, 3 movie, 4 music, 5 book, 6 details, 7 register. */
    private const array W65_XML_TYPES = [
        'caps' => 0, 'c' => 0, 'search' => 1, 's' => 1, 'tvsearch' => 2, 'tv' => 2, 'movie' => 3, 'm' => 3,
        'music' => 4, 'book' => 5, 'details' => 6, 'd' => 6, 'register' => 7, 'r' => 7,
    ];

    private const string W65_XML_HOST_BYTES = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789.-:[]';

    /**
     * Plan Newznab/Torznab XML response envelopes: response types (unknown 8), server URLs and the `offset`,
     * `total` and `items` columns. Mode 0 envelope only (caps, register), 1 paginated feed (search to book), 2
     * single item (details), 3 error (unknown). Scheme 2 https, 1 http, 0 other (case-insensitive prefix); the
     * host runs to the first `/` after it and allows `[A-Za-z0-9.:[]-]`. Offsets clamp to 0..2^31-1, totals to
     * 0..2^40 and items to 0..2^20-1. A feed is consistent when offset + items fit the total, a single item holds
     * at most one item and an envelope none. Flags: 1 valid host, 2 trailing slash, 4 non-ASCII URL, 8
     * consistent, 16 offset past a non-zero total, 32 valid (scheme, host and consistent).
     *
     * Layout: `[0]` count, `[1..10]` per type, `[10..14]` per mode, `[14]` valid, `[15]` https, `[16]` total
     * items, `[17]` largest total, `[18]` inconsistent, `[19]` first invalid index (-1), `[20]` FNV-1a digest;
     * then per context `type | mode << 4 | scheme << 6 | flags << 8 | items << 14 | min(offset, 2^24-1) << 34 |
     * min(URL bytes, 31) << 58`.
     *
     * @param  array<array-key, int|string>  $types
     * @param  array<array-key, int|string>  $servers
     * @param  array<string, array<array-key, int>>  $metrics
     * @return list<int>
     */
    public static function w65XmlResponseContextPlanBatch(array $types, array $servers, array $metrics): array
    {
        return (self::usesW65RustFfi() ? self::w65XmlResponseContextPlanBatchNative($types, $servers, $metrics) : null)
            ?? self::w65XmlResponseContextPlanBatchPhp($types, $servers, $metrics);
    }

    /**
     * @param  array<array-key, int|string>  $types
     * @param  array<array-key, int|string>  $servers
     * @param  array<string, array<array-key, int>>  $metrics
     * @return list<int>
     */
    public static function w65XmlResponseContextPlanBatchPhp(array $types, array $servers, array $metrics): array
    {
        $types = array_values($types);
        $count = \count($types);
        $servers = self::w65Column($servers, $count, '');
        [$offsets, $totals, $items] = self::w65Metrics($metrics, $count);
        $out = array_fill(0, self::W65_XML_HEADER, 0);
        $out[0] = $count;
        $out[19] = -1;
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($types as $at => $type) {
            $kind = self::W65_XML_TYPES[strtolower(trim((string) $type))] ?? 8;
            $mode = match ($kind) {
                0, 7 => 0,
                6 => 2,
                8 => 3,
                default => 1,
            };
            $url = (string) $servers[$at];
            $scheme = strncasecmp($url, 'https://', 8) === 0 ? 2 : (strncasecmp($url, 'http://', 7) === 0 ? 1 : 0);
            $host = '';
            if ($scheme > 0) {
                $rest = substr($url, 6 + $scheme);
                $slash = strpos($rest, '/');
                $host = $slash === false ? $rest : substr($rest, 0, $slash);
            }
            $hostValid = $host !== '' && strspn($host, self::W65_XML_HOST_BYTES) === \strlen($host);
            $offset = max(0, min($offsets[$at], self::W65_XML_OFFSET_CAP));
            $total = max(0, min($totals[$at], self::W65_XML_TOTAL_CAP));
            $item = max(0, min($items[$at], self::W65_XML_ITEMS_CAP));
            $consistent = match ($mode) {
                0 => $item === 0,
                1 => $offset <= $total && $item <= $total - $offset,
                2 => $item <= 1,
                default => false,
            };
            $valid = $scheme > 0 && $hostValid && $consistent;
            $flags = ($hostValid ? 1 : 0) | ($url !== '' && $url[-1] === '/' ? 2 : 0) | (preg_match('/[\x80-\xff]/', $url) === 1 ? 4 : 0)
                | ($consistent ? 8 : 0) | ($mode === 1 && $total > 0 && $offset >= $total ? 16 : 0) | ($valid ? 32 : 0);
            $out[1 + $kind]++;
            $out[10 + $mode]++;
            $out[14] += (int) $valid;
            $out[15] += (int) ($scheme === 2);
            $out[16] += $item;
            $out[17] = max($out[17], $total);
            $out[18] += (int) ! $consistent;
            if (! $valid && $out[19] < 0) {
                $out[19] = $at;
            }
            $row = $kind | $mode << 4 | $scheme << 6 | $flags << 8 | $item << 14 | min($offset, 0xFFFFFF) << 34 | min(\strlen($url), 31) << 58;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[20] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust plan, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, int|string>  $types
     * @param  array<array-key, int|string>  $servers
     * @param  array<string, array<array-key, int>>  $metrics
     * @return list<int>|null
     */
    public static function w65XmlResponseContextPlanBatchNative(array $types, array $servers, array $metrics): ?array
    {
        $count = \count($types);
        if ($count > self::W65_MAX_ITEMS) {
            return null;
        }
        [$joined, $twin] = self::w65Strings([...array_values($types), ...self::w65Column($servers, $count, '')]);
        $ints = self::w65Pack($metrics, $count);
        $words = self::W65_XML_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w65Call($words, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w656_xml_context_batch($joined, \strlen($joined), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $words));
    }

    /**
     * Route the Wave 65.6 batch plan through Rust for the rest of the process.
     */
    public static function enableW65RustFfi(): void
    {
        self::w65RustFfiState('set', true);
    }

    /**
     * Route the Wave 65.6 batch plan through PHP for the rest of the process.
     */
    public static function disableW65RustFfi(): void
    {
        self::w65RustFfiState('set', false);
    }

    /**
     * Restore the default Wave 65.6 routing and drop the cached binding and scratch buffer.
     */
    public static function resetW65RustFfi(): void
    {
        self::w65RustFfiState('reset');
    }

    public static function usesW65RustFfi(): bool
    {
        $forced = self::w65RustFfiState();

        return \is_bool($forced) ? $forced : self::W65_RUST_FFI_DEFAULT;
    }

    /**
     * Run `$callback` with the Wave 65.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW65RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w65RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w65RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 65.6 routing and binding in function statics, so the class gains no properties. No `$op` returns
     * the forced routing (null for the default); `'set'` replaces it with `$val` and returns the previous
     * value; `'reset'` clears the routing, binding and buffer; `'binding'` returns the cached binding and an
     * output buffer of at least `$val` values, or null.
     */
    private static function w65RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $enabled */
        static $enabled = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $buffer */
        static $buffer = null;
        static $capacity = 0;
        if ($op === 'set') {
            $previous = $enabled;
            $enabled = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'reset') {
            $enabled = $ffi = $buffer = null;
            $capacity = 0;

            return null;
        }
        if ($op !== 'binding') {
            return $enabled;
        }
        $fields = \is_int($val) ? $val : 0;
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('api_w656', self::W65_XML_FFI_CDEF) ?? false;
            if ($ffi === false) {
                return null;
            }
            if ($buffer === null || $capacity < $fields) {
                $capacity = max(256, $fields);
                $buffer = $ffi->new('int64_t['.$capacity.']');
            }
        } catch (Throwable) {
            return null;
        }

        return $buffer === null ? null : [$ffi, $buffer];
    }

    /**
     * The batch's `$fields` output words from one Wave 65.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w65Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w65RustFfiState('binding', $fields);
        if ($binding === null) {
            return null;
        }
        try {
            if ($call($binding[0], $binding[1]) !== 1) {
                return null;
            }
            $values = unpack('P*', FFI::string($binding[1], $fields * 8));
        } catch (Throwable) {
            return null;
        }

        return $values === false ? null : array_values($values);
    }

    /**
     * Values NUL-joined and, when one holds a NUL, the same values joined with 0x01 so Rust finds the
     * separators where the two differ.
     *
     * @param  list<int|string|null>  $items
     * @return array{0: string, 1: string}
     */
    private static function w65Strings(array $items): array
    {
        $joined = implode("\0", $items);

        return $items !== [] && substr_count($joined, "\0") !== \count($items) - 1 ? [$joined, implode("\x01", $items)] : [$joined, ''];
    }

    /**
     * Values by position, cut or padded with `$pad` to `$count`.
     *
     * @template T of int|string|null
     *
     * @param  array<array-key, T>  $values
     * @param  T  $pad
     * @return list<T>
     */
    private static function w65Column(array $values, int $count, int|string|null $pad = 0): array
    {
        $values = array_values($values);

        return \count($values) === $count ? $values : array_pad(\array_slice($values, 0, $count), $count, $pad);
    }

    /**
     * The named integer columns of {@see W65_XML_COLUMNS}, each cut or padded with 0 to `$count`.
     *
     * @param  array<string, array<array-key, int>>  $metrics
     * @return list<list<int>>
     */
    private static function w65Metrics(array $metrics, int $count): array
    {
        $columns = [];
        foreach (self::W65_XML_COLUMNS as $key) {
            $columns[] = self::w65Column($metrics[$key] ?? [], $count);
        }

        return $columns;
    }

    /**
     * The named integer columns packed one after another as machine-order `int64_t`.
     *
     * @param  array<string, array<array-key, int>>  $metrics
     */
    private static function w65Pack(array $metrics, int $count): string
    {
        $packed = '';
        foreach (self::w65Metrics($metrics, $count) as $column) {
            $packed .= pack('q*', ...$column);
        }

        return $packed;
    }
}
