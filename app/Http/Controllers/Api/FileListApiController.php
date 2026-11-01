<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Release;
use App\Services\Nzb\NzbParserService;
use App\Services\Nzb\NzbService;
use Illuminate\Http\JsonResponse;

class FileListApiController extends Controller
{
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

        return response()->json([
            'release' => [
                'guid' => $rel['guid'],
                'searchname' => $rel['searchname'],
            ],
            'files' => $files,
            'total' => count($files),
        ]);
    }
}
