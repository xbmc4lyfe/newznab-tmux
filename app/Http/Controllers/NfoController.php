<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Release;
use App\Models\ReleaseNfo;
use Illuminate\Http\Request;

class NfoController extends BasePageController
{
    // Keep PHP as the production default until the Rust performance gate passes.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi ?? false;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    /** @return array{modal: bool, meta_title?: string, meta_keywords?: string, meta_description?: string} */
    public static function nfoContext(string $searchName, bool $modal): array
    {
        return self::$useRustFfi ? self::nfoContextNative($searchName, $modal) : self::nfoContextPhp($searchName, $modal);
    }

    /** @return array{modal: bool, meta_title?: string, meta_keywords?: string, meta_description?: string} */
    public static function nfoContextPhp(string $searchName, bool $modal): array
    {
        return $modal ? ['modal' => true] : [
            'modal' => false,
            'meta_title' => 'View NFO - '.$searchName,
            'meta_keywords' => 'view,nzb,nfo,description,details',
            'meta_description' => 'View NFO File for '.$searchName,
        ];
    }

    /** @return array{modal: bool, meta_title?: string, meta_keywords?: string, meta_description?: string} */
    public static function nfoContextNative(string $searchName, bool $modal): array
    {
        $result = BrowseController::transformNative(2, [$searchName, $modal]);
        if (is_array($result) && ($modal ? $result === ['modal' => true] : (array_keys($result) === ['modal', 'meta_title', 'meta_keywords', 'meta_description'] && $result['modal'] === false && is_string($result['meta_title']) && is_string($result['meta_keywords']) && is_string($result['meta_description'])))) {
            return $result;
        }

        return self::nfoContextPhp($searchName, $modal);
    }

    /**
     * @throws \Exception
     */
    public function showNfo(Request $request, string $id = ''): mixed
    {
        if ($id) {
            $rel = Release::getByGuid($id);

            if (! $rel) {
                abort(404, 'Release does not exist');
            }

            $nfo = ReleaseNfo::getReleaseNfo($rel['id']);

            if ($nfo !== null) {
                $nfo['nfoUTF'] = cp437toUTF($nfo['nfo']);

                $modal = $request->has('modal');

                return view('nfo.view', array_merge([
                    'rel' => $rel,
                    'nfo' => $nfo,
                ], self::nfoContext($modal ? '' : (string) $rel['searchname'], $modal)));
            } else {
                abort(404, 'NFO does not exist');
            }
        }

        abort(404, 'Invalid request');
    }
}
