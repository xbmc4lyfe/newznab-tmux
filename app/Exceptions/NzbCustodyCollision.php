<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class NzbCustodyCollision extends RuntimeException
{
    public function __construct(public readonly string $attempt, public readonly ?string $recoveredFilename, public readonly string $privateDirectory)
    {
        parent::__construct('A replacement input entered private custody; retained for explicit restaging.');
    }
}
