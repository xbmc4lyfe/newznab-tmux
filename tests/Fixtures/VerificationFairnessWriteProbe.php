<?php

declare(strict_types=1);

namespace Tests\Fixtures {
    final class VerificationFairnessWriteProbe
    {
        public static ?string $failPath = null;
    }
}

namespace App\Services\Releases\Verification {
    use Tests\Fixtures\VerificationFairnessWriteProbe;

    /** @param resource $handle */
    function fwrite($handle, string $data): int|false
    {
        if (VerificationFairnessWriteProbe::$failPath === (stream_get_meta_data($handle)['uri'] ?? null)) {
            return false;
        }

        return \fwrite($handle, $data);
    }
}
