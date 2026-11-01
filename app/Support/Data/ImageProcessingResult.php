<?php

declare(strict_types=1);

namespace App\Support\Data;

final readonly class ImageProcessingResult
{
    private function __construct(
        public bool $success,
        public ?string $path,
        public ?int $width,
        public ?int $height,
        public ?string $mimeType,
        public ?string $failureReason,
    ) {}

    public static function success(string $path, int $width, int $height, string $mimeType): self
    {
        return new self(true, $path, $width, $height, $mimeType, null);
    }

    public static function failure(string $reason): self
    {
        return new self(false, null, null, null, null, $reason);
    }
}
