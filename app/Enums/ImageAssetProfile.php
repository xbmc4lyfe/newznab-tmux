<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Image bounds used by the existing cover and sample producers.
 */
enum ImageAssetProfile
{
    case Original;
    case MetadataCover;
    case Backdrop;
    case Preview;
    case Sample;

    public function maxWidth(): ?int
    {
        return match ($this) {
            self::Original => null,
            self::MetadataCover => 250,
            self::Backdrop => 1920,
            self::Preview => 800,
            self::Sample => 650,
        };
    }

    public function maxHeight(): ?int
    {
        return match ($this) {
            self::Original => null,
            self::MetadataCover => 250,
            self::Backdrop => 1024,
            self::Preview => 600,
            self::Sample => 650,
        };
    }
}
