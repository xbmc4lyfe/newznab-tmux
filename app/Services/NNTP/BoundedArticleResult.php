<?php

declare(strict_types=1);

namespace App\Services\NNTP;

use App\Services\Yenc\YencPart;

/** A complete bounded article, or a failure with no usable body or metadata. */
final readonly class BoundedArticleResult
{
    private function __construct(
        public ?string $body,
        public ?YencPart $part,
        public ?string $error,
        public bool $oversized,
    ) {}

    public static function success(string $body, ?YencPart $part = null): self
    {
        return new self($body, $part, null, false);
    }

    public static function failure(string $error, bool $oversized = false): self
    {
        return new self(null, null, $error, $oversized);
    }
}
