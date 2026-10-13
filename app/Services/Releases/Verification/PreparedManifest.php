<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use RuntimeException;

/** Immutable bytes prepared without publishing a file or reserving shared capacity. */
final readonly class PreparedManifest
{
    public string $name;

    public string $compressed;

    /** @param array<string, mixed> $manifest */
    public function __construct(array $manifest)
    {
        $body = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        $compressed = gzencode($body);
        if ($compressed === false) {
            throw new RuntimeException('Cannot compress candidate manifest.');
        }
        $this->name = hash('sha256', $body).'.json.gz';
        $this->compressed = $compressed;
    }
}
