<?php

namespace App\Services\Nzb;

final class NativeManifestIoProbe
{
    public static ?\Closure $beforeRename = null;

    public static bool $failWrite = false;

    public static int $failRecoverySyncs = 0;

    public static int $recoverySyncs = 0;
}
function rename(string $source, string $target): bool
{
    if (NativeManifestIoProbe::$beforeRename !== null) {
        (NativeManifestIoProbe::$beforeRename)($source, $target);
    }

    return \rename($source, $target);
}
function fwrite($stream, string $bytes): int|false
{
    $path = stream_get_meta_data($stream)['uri'] ?? '';
    if (NativeManifestIoProbe::$failWrite && str_contains($path, NzbUploadManifestService::FILENAME.'.tmp.')) {
        return false;
    }

    return \fwrite($stream, $bytes);
}

function fsync($stream): bool
{
    $path = stream_get_meta_data($stream)['uri'] ?? '';
    if (str_contains($path, '.recovered-')) {
        NativeManifestIoProbe::$recoverySyncs++;
        if (NativeManifestIoProbe::$failRecoverySyncs > 0) {
            NativeManifestIoProbe::$failRecoverySyncs--;

            return false;
        }
    }

    return \fsync($stream);
}
