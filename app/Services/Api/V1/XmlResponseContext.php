<?php

declare(strict_types=1);

namespace App\Services\Api\V1;

use App\Support\RustPipelineLibrary;
use FFI;
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
}
