<?php

declare(strict_types=1);

namespace App\Services\Api\V1;

use App\Support\RustPipelineLibrary;
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
}
