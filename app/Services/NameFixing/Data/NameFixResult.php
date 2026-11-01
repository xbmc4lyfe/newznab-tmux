<?php

declare(strict_types=1);

namespace App\Services\NameFixing\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Data Transfer Object for name fix results.
 *
 * Encapsulates the result of a name fixing operation, including the new name,
 * method used, and optional metadata.
 */
#[TypeScript]
final class NameFixResult extends Data
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $newName,
        public string $method,
        public string $checkerName,
        public int $preDbId = 0,
        public float $confidence = 1.0,
        public array $metadata = [],
    ) {}

    /**
     * Create a result from a pattern match.
     */
    public static function fromMatch(
        string $newName,
        string $method,
        string $checkerName,
        int $preDbId = 0,
        float $confidence = 1.0
    ): self {
        return new self(
            newName: $newName,
            method: $method,
            checkerName: $checkerName,
            preDbId: $preDbId,
            confidence: $confidence,
        );
    }

    /**
     * Get a formatted method string for logging.
     */
    public function getFormattedMethod(): string
    {
        return "{$this->checkerName}: {$this->method}";
    }
}
