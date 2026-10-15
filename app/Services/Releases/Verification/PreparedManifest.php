<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\NzbPasswordMetadata;
use RuntimeException;

/** Immutable bytes prepared without publishing a file or reserving shared capacity. */
final readonly class PreparedManifest
{
    public string $name;

    public string $compressed;

    public int $jsonBytes;

    /** @param array<string, mixed> $manifest */
    public function __construct(array $manifest)
    {
        if (array_key_exists('passwords', $manifest) && ! NzbPasswordMetadata::isValid($manifest['passwords'])) {
            throw new RuntimeException('Invalid candidate password metadata.');
        }
        $body = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        $compressed = gzencode($body);
        if ($compressed === false) {
            throw new RuntimeException('Cannot compress candidate manifest.');
        }
        $this->jsonBytes = strlen($body);
        $this->name = hash('sha256', $body).'.json.gz';
        $this->compressed = $compressed;
    }
}
