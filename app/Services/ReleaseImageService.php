<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Image\Image as LaravelImage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelImageOptimizer\Facades\ImageOptimizer;
use Throwable;

/**
 * Resize/save/delete images to disk.
 * Service for handling release images (covers, backdrops, previews).
 * Manages image storage and deletion for releases.
 */
class ReleaseImageService
{
    /**
     * Path to save ogg audio samples.
     */
    public string $audSavePath;

    /**
     * Path to save video preview jpg pictures.
     */
    public string $imgSavePath;

    /**
     * Path to save large jpg pictures(xxx).
     */
    public string $jpgSavePath;

    /**
     * Path to save movie jpg covers.
     */
    public string $movieImgSavePath;

    /**
     * Path to save video ogv files.
     */
    public string $vidSavePath;

    /**
     * ReleaseImageService constructor.
     */
    public function __construct()
    {
        $this->audSavePath = storage_path('covers/audiosample/');
        $this->imgSavePath = storage_path('covers/preview/');
        $this->jpgSavePath = storage_path('covers/sample/');
        $this->movieImgSavePath = storage_path('covers/movies/');
        $this->vidSavePath = storage_path('covers/video/');
    }

    protected function fetchImage(string $imgLoc): bool|LaravelImage
    {
        try {
            // Create context with timeout settings for file_get_contents
            $context = stream_context_create([
                'http' => [
                    'timeout' => 30,
                    'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'follow_location' => true,
                    'max_redirects' => 5,
                    'ignore_errors' => true,
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                ],
            ]);

            $imageData = @file_get_contents($imgLoc, false, $context);

            // Check HTTP response headers if available
            if (! empty($http_response_header)) {
                $statusLine = $http_response_header[0] ?? '';
                if (preg_match('/HTTP\/\d\.\d\s+(\d+)/', $statusLine, $matches)) {
                    $httpCode = (int) $matches[1];
                    if ($httpCode >= 400) {
                        Log::debug('HTTP error fetching image from '.$imgLoc.': '.$statusLine);
                        cli()->notice('HTTP error fetching image: '.$statusLine);

                        return false;
                    }
                }
            }

            if ($imageData === false) {
                $error = error_get_last();
                $errorMsg = $error !== null ? $error['message'] : 'Unknown error fetching image';
                Log::debug('Failed to fetch image from '.$imgLoc.': '.$errorMsg);
                cli()->notice('Failed to fetch image from '.$imgLoc.': '.$errorMsg);

                return false;
            }

            if (empty($imageData)) {
                Log::debug('Empty image data received from '.$imgLoc);
                cli()->notice('Empty image data received from '.$imgLoc);

                return false;
            }

            return Image::fromBytes($imageData);
        } catch (Throwable $e) {
            if ($e->getCode() === 404) {
                cli()->notice('Data not available on server');
            } elseif ($e->getCode() === 503) {
                cli()->notice('Service unavailable');
            } else {
                Log::debug('Exception fetching image from '.$imgLoc.': '.$e->getMessage());
                cli()->notice('Unable to fetch image: '.$e->getMessage());
            }
        }

        return false;
    }

    /**
     * Save an image to disk, optionally resizing it.
     *
     * @param  string  $imgName  What to name the new image.
     * @param  string  $imgLoc  URL or location on the disk the original image is in.
     * @param  string  $imgSavePath  Folder to save the new image in.
     * @param  int  $imgMaxWidth  Max width to resize image to.   (OPTIONAL)
     * @param  int  $imgMaxHeight  Max height to resize image to.  (OPTIONAL)
     * @param  bool  $saveThumb  Save a thumbnail of this image? (OPTIONAL)
     * @return int 1 on success, 0 on failure Used on site to check if there is an image.
     */
    public function saveImage(string $imgName, string $imgLoc, string $imgSavePath, int $imgMaxWidth = 0, int $imgMaxHeight = 0, bool $saveThumb = false): int
    {
        // Guard against empty image locations to avoid 'Path cannot be empty'
        if (empty($imgLoc)) {
            return 0;
        }

        $cover = $this->fetchImage($imgLoc);

        if ($cover === false) {
            return 0;
        }

        $coverPath = $imgSavePath.$imgName.'.jpg';

        try {
            $shouldSaveThumb = false;

            // Check if we need to resize it.
            if ($imgMaxWidth !== 0 && $imgMaxHeight !== 0) {
                $width = $cover->width();
                $height = $cover->height();
                if ($width !== 0 || $height !== 0) {
                    $ratio = min($imgMaxHeight / $height, $imgMaxWidth / $width);
                    // New dimensions
                    $new_width = (int) ($ratio * $width);
                    $new_height = (int) ($ratio * $height);
                    if ($new_width < $width && $new_width > 10 && $new_height > 10) {
                        $cover = $cover->resize($new_width, $new_height);
                        $shouldSaveThumb = $saveThumb;
                    }
                }
            }

            $jpeg = $cover->toJpeg()->quality(100)->toBytes();

            if ($shouldSaveThumb && ! $this->writeOptimizedImage($imgSavePath.$imgName.'_thumb.jpg', $jpeg)) {
                return 0;
            }

            if (! $this->writeOptimizedImage($coverPath, $jpeg)) {
                return 0;
            }
        } catch (Throwable $e) {
            Log::debug('Unable to process image '.$imgLoc.' for '.$coverPath.': '.$e->getMessage());

            return 0;
        }
        // Check if it's on the drive.
        if (! File::isReadable($coverPath)) {
            Log::debug('Image was not readable after save: '.$coverPath);

            return 0;
        }

        return 1;
    }

    private function writeOptimizedImage(string $path, string $contents): bool
    {
        if (File::put($path, $contents) === false) {
            Log::debug('Unable to write image to '.$path);

            return false;
        }

        ImageOptimizer::optimize($path);

        if (! File::isReadable($path)) {
            Log::debug('Image was not readable after save: '.$path);

            return false;
        }

        return true;
    }

    /**
     * Delete images for the release.
     *
     * @param  string  $guid  The GUID of the release.
     */
    public function delete(string $guid): void
    {
        $thumb = $guid.'_thumb.jpg';

        File::delete([$this->audSavePath.$guid.'.ogg', $this->imgSavePath.$thumb, $this->jpgSavePath.$thumb, $this->vidSavePath.$guid.'.ogv']);
    }
}
