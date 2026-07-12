<?php

declare(strict_types=1);

namespace App\Services\Api\V1;

final readonly class XmlResponseContext
{
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
    public static function fromLegacyOptions(array $options): self
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
}
