<?php

declare(strict_types=1);

namespace Tests\Fixtures {
    final class ManifestCompressionProbe
    {
        public static ?\Closure $onCompress = null;
    }
}

namespace App\Services\Releases\Verification {
    use Tests\Fixtures\ManifestCompressionProbe;

    /** Observe the real compression boundary without replacing its bytes or side effects. */
    function gzencode(string $data, int $level = -1, int $encoding = ZLIB_ENCODING_GZIP): string|false
    {
        ManifestCompressionProbe::$onCompress?->__invoke();

        return \gzencode($data, $level, $encoding);
    }
}
