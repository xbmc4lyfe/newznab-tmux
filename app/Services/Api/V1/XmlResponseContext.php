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
}
