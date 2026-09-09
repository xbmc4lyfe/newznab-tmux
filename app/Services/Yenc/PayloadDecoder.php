<?php

declare(strict_types=1);

namespace App\Services\Yenc;

interface PayloadDecoder
{
    /** Decode a payload with framing, line endings and NNTP dot stuffing already removed. */
    public function decode(string $payload): string;
}
