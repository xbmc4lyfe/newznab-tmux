<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ImageAssetProfile;
use App\Support\Data\ImageProcessingResult;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use FFI\CData;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Image\Image as LaravelImage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetch, normalize, store, locate, and delete release images.
 *
 * Destination directories are deliberately supplied by callers. This keeps
 * the existing storage_path/public_path/COVERS_PATH ownership unchanged.
 */
class ReleaseImageService
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_release_image_is_valid_basename(const char *input, size_t input_len);
        ssize_t nntmux_release_image_redact_url(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        int64_t nntmux_release_image_scale_dimensions(int64_t width, int64_t height, int64_t max_width, int64_t max_height, const char *format, size_t format_len, int32_t *out_format);
        int32_t nntmux_release_duplicate_resolve_reason(int64_t dup_predb_id, int64_t predb_id, const char *dup_search_name, size_t dup_search_name_len, const char *search_name, size_t search_name_len);
        int32_t nntmux_release_duplicate_size_bounds(int64_t filesize, double tolerance, int64_t *out_low, int64_t *out_high);
        ssize_t nntmux_release_management_export_date(const char *input, size_t input_len, uint32_t is_end_of_day, char *out_buf, size_t out_cap);
        int32_t nntmux_categorization_result_is_successful(int64_t category_id, double confidence, const char *matched_by, size_t matched_by_len);
        int32_t nntmux_categorization_result_is_protected_misc(int64_t category_id, const char *matched_by, size_t matched_by_len);
        int32_t nntmux_categorization_result_should_override(int64_t self_category_id, double self_confidence, const char *self_matched_by, size_t self_matched_by_len, int64_t other_category_id, double other_confidence, const char *other_matched_by, size_t other_matched_by_len);
        ssize_t nntmux_api_release_cache_normalize_json(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        C;

    public static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private static ?CData $formatOut = null;

    /** Path to save ogg audio samples. */
    public string $audSavePath;

    /** Path to save video preview pictures. */
    public string $imgSavePath;

    /** Path to save downloaded sample pictures. */
    public string $jpgSavePath;

    /** Path to save movie covers. */
    public string $movieImgSavePath;

    /** Path to save video samples. */
    public string $vidSavePath;

    /** @var Closure(string): list<string> */
    private Closure $hostResolver;

    /**
     * @param  (Closure(string): list<string>)|null  $hostResolver
     */
    public function __construct(?Closure $hostResolver = null)
    {
        $this->audSavePath = storage_path('covers/audiosample/');
        $this->imgSavePath = storage_path('covers/preview/');
        $this->jpgSavePath = storage_path('covers/sample/');
        $this->movieImgSavePath = storage_path('covers/movies/');
        $this->vidSavePath = storage_path('covers/video/');
        $this->hostResolver = $hostResolver ?? $this->defaultHostResolver(...);
    }

    public function saveRemoteImage(
        string $imgName,
        string $url,
        string $destinationDirectory,
        ImageAssetProfile $profile = ImageAssetProfile::Original,
    ): ImageProcessingResult {
        $fetched = $this->fetchRemoteBytes($url);

        if (! $fetched['success']) {
            return ImageProcessingResult::failure($fetched['reason']);
        }

        return $this->processBytes(
            $imgName,
            $fetched['contents'],
            $destinationDirectory,
            $profile->maxWidth(),
            $profile->maxHeight(),
        );
    }

    public function saveLocalImage(
        string $imgName,
        string $sourcePath,
        string $destinationDirectory,
        ImageAssetProfile $profile = ImageAssetProfile::Original,
    ): ImageProcessingResult {
        if (! File::isFile($sourcePath) || ! File::isReadable($sourcePath)) {
            return ImageProcessingResult::failure('Image source is not a readable local file.');
        }

        $maxBytes = $this->maxSourceBytes();
        $sourceSize = File::size($sourcePath);
        if ($sourceSize === false || $sourceSize <= 0 || $sourceSize > $maxBytes) {
            return ImageProcessingResult::failure('Image source size is invalid or exceeds the configured limit.');
        }

        try {
            return $this->processBytes(
                $imgName,
                File::get($sourcePath),
                $destinationDirectory,
                $profile->maxWidth(),
                $profile->maxHeight(),
            );
        } catch (Throwable $e) {
            Log::debug('Unable to read local image source.', [
                'path' => $sourcePath,
                'error' => $e->getMessage(),
            ]);

            return ImageProcessingResult::failure('Unable to read the local image source.');
        }
    }

    public function saveUploadedImage(
        string $imgName,
        UploadedFile $upload,
        string $destinationDirectory,
        ImageAssetProfile $profile = ImageAssetProfile::Original,
    ): ImageProcessingResult {
        if (! $upload->isValid()) {
            return ImageProcessingResult::failure('The uploaded image is invalid.');
        }

        $path = $upload->getRealPath();
        if ($path === false) {
            return ImageProcessingResult::failure('The uploaded image has no readable temporary path.');
        }

        return $this->saveLocalImage($imgName, $path, $destinationDirectory, $profile);
    }

    /**
     * Backwards-compatible adapter while callers move to explicit source APIs.
     *
     * @return int 1 on success, 0 on failure
     */
    public function saveImage(
        string $imgName,
        string $imgLoc,
        string $imgSavePath,
        int $imgMaxWidth = 0,
        int $imgMaxHeight = 0,
        bool $saveThumb = false,
    ): int {
        if ($imgLoc === '') {
            return 0;
        }

        $scheme = strtolower((string) parse_url($imgLoc, PHP_URL_SCHEME));
        $source = in_array($scheme, ['http', 'https'], true)
            ? $this->fetchRemoteBytes($imgLoc)
            : $this->readLocalBytes($imgLoc);

        if (! $source['success']) {
            return 0;
        }

        $result = $this->processBytes(
            $imgName,
            $source['contents'],
            $imgSavePath,
            $imgMaxWidth > 0 ? $imgMaxWidth : null,
            $imgMaxHeight > 0 ? $imgMaxHeight : null,
        );

        if ($result->success && $saveThumb && $result->path !== null) {
            $thumbPath = $imgSavePath.$imgName.'_thumb.'.$this->outputExtension();
            if (! File::copy($result->path, $thumbPath)) {
                return 0;
            }
        }

        return $result->success ? 1 : 0;
    }

    public function imagePath(string $directory, string $basename): ?string
    {
        foreach (array_unique([$this->outputExtension(), 'webp', 'jpg', 'jpeg']) as $extension) {
            $path = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$basename.'.'.$extension;
            if (File::isReadable($path)) {
                return $path;
            }
        }

        return null;
    }

    public function imageExists(string $directory, string $basename): bool
    {
        return $this->imagePath($directory, $basename) !== null;
    }

    public function outputExtension(): string
    {
        return $this->outputFormat() === 'webp' ? 'webp' : 'jpg';
    }

    /** Delete all generated assets for a release, including legacy images. */
    public function delete(string $guid): void
    {
        File::delete($this->releaseFiles($guid));
    }

    /** @return list<string> */
    public function releaseFiles(string $guid): array
    {
        $files = [
            $this->audSavePath.$guid.'.ogg',
            $this->vidSavePath.$guid.'.ogv',
        ];

        foreach (['webp', 'jpg', 'jpeg'] as $extension) {
            $files[] = $this->imgSavePath.$guid.'_thumb.'.$extension;
            $files[] = $this->jpgSavePath.$guid.'_thumb.'.$extension;
        }

        return $files;
    }

    /** @return array{success: bool, contents: string, reason: string} */
    private function readLocalBytes(string $path): array
    {
        if (! File::isFile($path) || ! File::isReadable($path)) {
            return ['success' => false, 'contents' => '', 'reason' => 'Image source is not a readable local file.'];
        }

        $size = File::size($path);
        if ($size === false || $size <= 0 || $size > $this->maxSourceBytes()) {
            return ['success' => false, 'contents' => '', 'reason' => 'Image source size is invalid or exceeds the configured limit.'];
        }

        try {
            return ['success' => true, 'contents' => File::get($path), 'reason' => ''];
        } catch (Throwable) {
            return ['success' => false, 'contents' => '', 'reason' => 'Unable to read the local image source.'];
        }
    }

    /** @return array{success: bool, contents: string, reason: string} */
    private function fetchRemoteBytes(string $url): array
    {
        $currentUrl = $url;
        $maxRedirects = max(0, (int) config('image.fetch_max_redirects', 5));

        try {
            for ($redirects = 0; $redirects <= $maxRedirects; $redirects++) {
                $this->assertSafeRemoteUrl($currentUrl);

                $response = Http::withHeaders([
                    'Accept' => 'image/avif,image/webp,image/png,image/jpeg,*/*;q=0.5',
                    'User-Agent' => 'NNTmux Image Fetcher',
                ])->withOptions([
                    'allow_redirects' => false,
                    'stream' => true,
                ])->connectTimeout(max(1, (int) config('image.fetch_connect_timeout', 5)))
                    ->timeout(max(1, (int) config('image.fetch_timeout', 30)))
                    ->get($currentUrl);

                if ($response->status() >= 300 && $response->status() < 400) {
                    $location = $response->header('Location');
                    if ($location === null || $location === '' || $redirects === $maxRedirects) {
                        return ['success' => false, 'contents' => '', 'reason' => 'Remote image redirect limit was exceeded.'];
                    }

                    $currentUrl = (string) UriResolver::resolve(new Uri($currentUrl), new Uri($location));

                    continue;
                }

                if (! $response->successful()) {
                    Log::debug('Remote image request failed.', [
                        'url' => $this->redactedUrl($currentUrl),
                        'status' => $response->status(),
                    ]);

                    return ['success' => false, 'contents' => '', 'reason' => 'Remote image request failed.'];
                }

                $contentLength = $response->header('Content-Length');
                if (is_numeric($contentLength) && (int) $contentLength > $this->maxSourceBytes()) {
                    return ['success' => false, 'contents' => '', 'reason' => 'Remote image exceeds the configured size limit.'];
                }

                $stream = $response->toPsrResponse()->getBody();
                $contents = '';
                while (! $stream->eof()) {
                    $remaining = $this->maxSourceBytes() - strlen($contents);
                    if ($remaining <= 0) {
                        return ['success' => false, 'contents' => '', 'reason' => 'Remote image exceeds the configured size limit.'];
                    }

                    $contents .= $stream->read(min(8192, $remaining + 1));
                    if (strlen($contents) > $this->maxSourceBytes()) {
                        return ['success' => false, 'contents' => '', 'reason' => 'Remote image exceeds the configured size limit.'];
                    }
                }

                if ($contents === '') {
                    return ['success' => false, 'contents' => '', 'reason' => 'Remote image response was empty.'];
                }

                return ['success' => true, 'contents' => $contents, 'reason' => ''];
            }
        } catch (Throwable $e) {
            Log::debug('Unable to fetch remote image.', [
                'url' => $this->redactedUrl($currentUrl),
                'error' => $e->getMessage(),
            ]);
        }

        return ['success' => false, 'contents' => '', 'reason' => 'Unable to fetch the remote image.'];
    }

    private function processBytes(
        string $imgName,
        string $contents,
        string $destinationDirectory,
        ?int $maxWidth,
        ?int $maxHeight,
    ): ImageProcessingResult {
        if (! $this->isValidBasename($imgName)) {
            return ImageProcessingResult::failure('Image basename is invalid.');
        }

        if ($contents === '' || strlen($contents) > $this->maxSourceBytes()) {
            return ImageProcessingResult::failure('Image source size is invalid or exceeds the configured limit.');
        }

        $temporaryPath = null;

        try {
            $image = Image::fromBytes($contents);
            $width = $image->width();
            $height = $image->height();

            if ($width <= 0 || $height <= 0 || ($width * $height) > $this->maxSourcePixels()) {
                return ImageProcessingResult::failure('Decoded image dimensions exceed the configured limit.');
            }

            $image = $image->orient();
            $image = $this->resizeDown($image, $width, $height, $maxWidth, $maxHeight);
            $encoded = $this->encode($image);

            File::ensureDirectoryExists($destinationDirectory, 0775, true);
            $directory = rtrim($destinationDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
            $extension = $this->outputExtension();
            $destinationPath = $directory.$imgName.'.'.$extension;
            $temporaryPath = $directory.'.'.$imgName.'.'.bin2hex(random_bytes(8)).'.tmp.'.$extension;

            if (File::put($temporaryPath, $encoded) === false) {
                return ImageProcessingResult::failure('Unable to write the processed image.');
            }

            $dimensions = getimagesize($temporaryPath);
            $mimeType = File::mimeType($temporaryPath);
            $expectedMime = $extension === 'webp' ? 'image/webp' : 'image/jpeg';

            if (! is_array($dimensions) || $mimeType !== $expectedMime || ! File::isReadable($temporaryPath)) {
                return ImageProcessingResult::failure('Processed image validation failed.');
            }

            if (! rename($temporaryPath, $destinationPath)) {
                return ImageProcessingResult::failure('Unable to atomically publish the processed image.');
            }
            $temporaryPath = null;
            File::chmod($destinationPath, 0644);

            return ImageProcessingResult::success(
                $destinationPath,
                (int) $dimensions[0],
                (int) $dimensions[1],
                $expectedMime,
            );
        } catch (Throwable $e) {
            Log::debug('Unable to process image.', [
                'destination' => $destinationDirectory,
                'name' => $imgName,
                'error' => $e->getMessage(),
            ]);

            return ImageProcessingResult::failure('Unable to decode or process the image.');
        } finally {
            if ($temporaryPath !== null) {
                File::delete($temporaryPath);
            }
        }
    }

    private function resizeDown(
        LaravelImage $image,
        int $width,
        int $height,
        ?int $maxWidth,
        ?int $maxHeight,
    ): LaravelImage {
        $dimensions = $this->scaleDimensions($width, $height, $maxWidth, $maxHeight);
        if ($dimensions !== null) {
            return $image->resize($dimensions[0], $dimensions[1]);
        }

        return $image;
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    public function scaleDimensions(int $width, int $height, ?int $maxWidth, ?int $maxHeight): ?array
    {
        return self::$useRustFfi
            ? $this->scaleDimensionsNative($width, $height, $maxWidth, $maxHeight)
            : $this->scaleDimensionsPhp($width, $height, $maxWidth, $maxHeight);
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    public function scaleDimensionsPhp(int $width, int $height, ?int $maxWidth, ?int $maxHeight): ?array
    {
        if ($maxWidth === null || $maxHeight === null || $width <= 0 || $height <= 0 || $maxWidth <= 0 || $maxHeight <= 0) {
            return null;
        }

        $ratio = min($maxHeight / $height, $maxWidth / $width, 1);
        $newWidth = (int) floor($ratio * $width);
        $newHeight = (int) floor($ratio * $height);

        if ($ratio < 1 && $newWidth > 10 && $newHeight > 10) {
            return [$newWidth, $newHeight];
        }

        return null;
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    public function scaleDimensionsNative(int $width, int $height, ?int $maxWidth, ?int $maxHeight): ?array
    {
        if ($maxWidth === null || $maxHeight === null || $width <= 0 || $height <= 0 || $maxWidth <= 0 || $maxHeight <= 0) {
            return null;
        }

        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return $this->scaleDimensionsPhp($width, $height, $maxWidth, $maxHeight);
        }

        // @phpstan-ignore method.notFound
        $packed = $ffi->nntmux_release_image_scale_dimensions($width, $height, $maxWidth, $maxHeight, null, 0, null);
        if (! \is_int($packed) || $packed < 0) {
            return $this->scaleDimensionsPhp($width, $height, $maxWidth, $maxHeight);
        }
        if ($packed === 0) {
            return null;
        }

        return [$packed >> 32, $packed & 0xFFFFFFFF];
    }

    private function encode(LaravelImage $image): string
    {
        $quality = max(1, min(100, (int) config('image.output_quality', 82)));

        return match ($this->outputFormat()) {
            'webp' => $image->toWebp()->quality($quality)->toBytes(),
            default => $image->toJpeg()->quality($quality)->toBytes(),
        };
    }

    private function outputFormat(): string
    {
        return $this->resolveOutputFormat((string) config('image.output_format', 'webp'));
    }

    public function resolveOutputFormat(string $format): string
    {
        return self::$useRustFfi
            ? $this->resolveOutputFormatNative($format)
            : $this->resolveOutputFormatPhp($format);
    }

    public function resolveOutputFormatPhp(string $format): string
    {
        $normalized = strtolower($format);

        return in_array($normalized, ['jpg', 'jpeg'], true) ? 'jpg' : 'webp';
    }

    public function resolveOutputFormatNative(string $format): string
    {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return $this->resolveOutputFormatPhp($format);
        }

        $outBuf = self::$formatOut ??= $ffi->new('int32_t');

        // @phpstan-ignore method.notFound
        $res = $ffi->nntmux_release_image_scale_dimensions(0, 0, 0, 0, $format, \strlen($format), FFI::addr($outBuf));
        if (! \is_int($res) || $res < 0) {
            return $this->resolveOutputFormatPhp($format);
        }

        // @phpstan-ignore property.notFound
        return $outBuf->cdata === 1 ? 'jpg' : 'webp';
    }

    private function maxSourceBytes(): int
    {
        return max(1, (int) config('image.max_source_bytes', 20 * 1024 * 1024));
    }

    private function maxSourcePixels(): int
    {
        return max(1, (int) config('image.max_source_pixels', 40_000_000));
    }

    public function isValidBasename(string $basename): bool
    {
        return self::$useRustFfi
            ? $this->isValidBasenameNative($basename)
            : $this->isValidBasenamePhp($basename);
    }

    public function isValidBasenamePhp(string $basename): bool
    {
        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]*\z/D', $basename) === 1;
    }

    public function isValidBasenameNative(string $basename): bool
    {
        if ($basename === '') {
            return false;
        }

        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return $this->isValidBasenamePhp($basename);
        }

        // @phpstan-ignore method.notFound
        $res = $ffi->nntmux_release_image_is_valid_basename($basename, \strlen($basename));
        if (! \is_int($res) || $res < 0) {
            return $this->isValidBasenamePhp($basename);
        }

        return $res === 1;
    }

    private function assertSafeRemoteUrl(string $url): void
    {
        $parts = parse_url($url);
        if (! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new \InvalidArgumentException('Remote image URL is invalid.');
        }

        $addresses = ($this->hostResolver)($parts['host']);
        if ($addresses === []) {
            throw new \InvalidArgumentException('Remote image host did not resolve.');
        }

        foreach ($addresses as $address) {
            if (filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) === false) {
                throw new \InvalidArgumentException('Remote image host resolves to a non-public address.');
            }
        }
    }

    /** @return list<string> */
    private function defaultHostResolver(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false) {
            return [];
        }

        $addresses = [];
        foreach ($records as $record) {
            if (isset($record['ip'])) {
                $addresses[] = $record['ip'];
            }
            if (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }

    public function redactedUrl(string $url): string
    {
        return self::$useRustFfi
            ? $this->redactedUrlNative($url)
            : $this->redactedUrlPhp($url);
    }

    public function redactedUrlPhp(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return '[invalid-url]';
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return strtolower($parts['scheme']).'://'.$parts['host'].$port.($parts['path'] ?? '/');
    }

    public function redactedUrlNative(string $url): string
    {
        if ($url === '') {
            return '[invalid-url]';
        }

        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return $this->redactedUrlPhp($url);
        }

        $len = \strlen($url);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, $len + 32), $cap);
        if ($scratch === null) {
            return $this->redactedUrlPhp($url);
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_release_image_redact_url($url, $len, $scratch, $cap);
        if (! \is_int($written) || $written === -1) {
            return $this->redactedUrlPhp($url);
        }
        if ($written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return $this->redactedUrlPhp($url);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_release_image_redact_url($url, $len, $scratch, $cap);
            if (! \is_int($written) || $written <= 0) {
                return $this->redactedUrlPhp($url);
            }
        }

        return $written === 0 ? '[invalid-url]' : FFI::string($scratch, $written);
    }

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function mediaFfi(): ?FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi === false ? null : self::$ffi;
        }

        self::$ffi = RustPipelineLibrary::resolveDomain('media', self::FFI_CDEF) ?? false;

        return self::$ffi === false ? null : self::$ffi;
    }

    /** Disabled: common p99 missed 1.5x in three of eight paired runs (1.41x, 1.49x, 1.49x; others 2.04x-2.88x; see the W43 parity test). */
    public const bool W431_RUST_FFI_DEFAULT = false;

    public const string W431_FFI_CDEF = <<<'C'
        ssize_t nntmux_w431_release_image_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W431_SUMMARY_KEYS = ['total_refs', 'empty_ref_count', 'valid_basename_count', 'http_url_count', 'credential_url_count', 'image_extension_count', 'query_url_count'];

    private static ?bool $w431UseRustFfi = null;

    private static FFI|false|null $w431Ffi = null;

    public static function useW431RustFfi(bool $enable = true): void
    {
        self::$w431UseRustFfi = $enable;
    }

    public static function usesW431RustFfi(): bool
    {
        return self::$w431UseRustFfi ?? self::W431_RUST_FFI_DEFAULT;
    }

    /** Restores the benchmark default and drops the cached library handle; earlier toggles are untouched. */
    public static function resetW431RustFfi(): void
    {
        self::$w431UseRustFfi = null;
        self::$w431Ffi = null;
    }

    /**
     * Summarizes trimmed image references: basenames isValidBasenamePhp() accepts, http(s) URLs, URLs with
     * an `@` in the authority, URLs with a query, and image extensions before a query, fragment or the end.
     * Entries come from strings, integers, or the first non-null url, basename, name array field; other values
     * (including objects) count as empty entries.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeImageReferenceBatch(array $rows): array
    {
        return self::usesW431RustFfi() ? self::summarizeImageReferenceBatchNative($rows) : self::summarizeImageReferenceBatchPhp($rows);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeImageReferenceBatchNative(array $rows): array
    {
        $values = CollectionsCleaningService::w431Invoke(self::$w431Ffi, self::W431_FFI_CDEF, 'nntmux_w431_release_image_batch', $rows, ['url', 'basename', 'name'], \count(self::W431_SUMMARY_KEYS));

        return $values === null ? self::summarizeImageReferenceBatchPhp($rows) : array_combine(self::W431_SUMMARY_KEYS, $values);
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeImageReferenceBatchPhp(array $rows): array
    {
        $summary = array_fill_keys(self::W431_SUMMARY_KEYS, 0);
        foreach (CollectionsCleaningService::w431Entries($rows, ['url', 'basename', 'name']) as $entry) {
            $summary['total_refs']++;
            $entry = trim($entry);
            if ($entry === '') {
                $summary['empty_ref_count']++;

                continue;
            }
            // Same pattern as isValidBasenamePhp().
            $summary['valid_basename_count'] += (int) (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]*\z/D', $entry) === 1);
            if (preg_match('#\Ahttps?://([^/?\#]*)#i', $entry, $match) === 1) {
                $summary['http_url_count']++;
                $summary['credential_url_count'] += (int) str_contains($match[1], '@');
                $summary['query_url_count'] += (int) str_contains($entry, '?');
            }
            $summary['image_extension_count'] += (int) (preg_match('/\.(?:jpe?g|png|gif|webp|bmp)(?:[?#]|\z)/i', $entry) === 1);
        }

        return $summary;
    }
}
