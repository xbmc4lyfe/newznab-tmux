<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\GetNzbController;
use App\Models\Release;
use App\Services\Nzb\NzbParserService;
use App\Services\Nzb\NzbService;
use Illuminate\Http\JsonResponse;

class FileListApiController extends Controller
{
    // Keep PHP as the production default until the Rust performance gate passes.
    private static bool $useRustFfi = false;

    public const string FFI_CDEF = GetNzbController::FFI_CDEF;

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi ?? false;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    /** @param array<array-key, mixed> $files
     * @return array<string, mixed>
     */
    public static function fileListPayload(string $guid, string $searchname, array $files): array
    {
        return self::$useRustFfi ? self::fileListPayloadNative($guid, $searchname, $files) : self::fileListPayloadPhp($guid, $searchname, $files);
    }

    /** @param array<array-key, mixed> $files
     * @return array<string, mixed>
     */
    public static function fileListPayloadPhp(string $guid, string $searchname, array $files): array
    {
        return ['release' => ['guid' => $guid, 'searchname' => $searchname], 'files' => $files, 'total' => count($files)];
    }

    /** @param array<array-key, mixed> $files
     * @return array<string, mixed>
     */
    public static function fileListPayloadNative(string $guid, string $searchname, array $files): array
    {
        $result = GetNzbController::transformNative(8, [$guid, $searchname, $files]);

        return is_array($result) ? $result : self::fileListPayloadPhp($guid, $searchname, $files);
    }

    /**
     * Get file list for a release
     */
    public function getFileList(string $guid): JsonResponse
    {
        $nzb = app(NzbService::class);
        $nzbParser = app(NzbParserService::class);

        $rel = Release::getByGuid($guid);
        if (! $rel) {
            return response()->json(['error' => 'Release not found'], 404);
        }

        $nzbfile = $nzb->readNzbContents($guid);

        if (! is_string($nzbfile)) {
            return response()->json(['error' => 'NZB file not found'], 404);
        }

        $files = $nzbParser->parseNzbFileList($nzbfile);

        return response()->json(self::fileListPayload((string) $rel['guid'], (string) $rel['searchname'], $files));
    }
}
