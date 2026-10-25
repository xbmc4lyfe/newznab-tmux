<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\DTO\DownloadMetrics;
use App\Services\AdditionalProcessing\DTO\Par2FileEntry;
use App\Services\AdditionalProcessing\Enums\DownloadKind;
use App\Services\NNTP\NNTPService;
use App\Services\Yenc\YencPart;
use App\Support\RustPipelineLibrary;
use Exception;
use FFI;
use Illuminate\Support\Facades\Log;

/**
 * Service for downloading content from Usenet via NNTP.
 * Handles message ID downloading with error handling and group availability detection.
 */
class UsenetDownloadService
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_rfm_is_group_unavailable(const char *ptr, size_t len);
        int32_t nntmux_rfm_mapped_article_kind(const char *ptr, size_t len);
        C;

    private const string DEFAULT_VIDEO_FILE_REGEX = '\.(AVI|F4V|IFO|M1V|M2V|M4V|MKV|MOV|MP4|MPEG|MPG|MPGV|MPV|OGV|QT|RM|RMVB|TS|VOB|WMV)';

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

    private static bool $useRustFfi = true;

    /** Wave 40.6 paired benchmarks decide this default; see RustArchiveExtractionMediaUsenetDownloadReleaseProcW40ParityTest. */
    public const bool W406_RUST_FFI_DEFAULT = false;

    private static ?bool $w406UseRustFfi = null;

    private const int MAX_CACHE_ENTRIES = 4;

    private const int MAX_CACHE_ENTRY_BYTES = 8_388_608;

    private const int MAX_CACHE_BYTES = 16_777_216;

    private NNTPService $nntp;

    private bool $releaseScopeActive = false;

    /**
     * @var array<string, array{success: bool, data: string|null, groupUnavailable: bool, error: string|null}>
     */
    private array $releaseCache = [];

    /** @var array<string, array{data: string, part: YencPart}> */
    private array $mappedArchiveArticles = [];

    /** @var array<string, array{data: string, part: YencPart}> */
    private array $mappedVideoArticles = [];

    /** @var array{key: string, data: string, part: YencPart}|null */
    private ?array $pendingMappingArticle = null;

    private bool $capturingMappingArticle = false;

    private int $cachedBytes = 0;

    private int $logicalRequests = 0;

    private int $networkRequests = 0;

    private int $cacheHits = 0;

    private int $bytesDownloaded = 0;

    private int $bytesReused = 0;

    private int $mediaBytesDownloaded = 0;

    public function __construct(
        private readonly ProcessingConfiguration $config,
        ?NNTPService $nntp = null
    ) {
        $this->nntp = $nntp ?? new NNTPService;
    }

    public function beginReleaseScope(): void
    {
        $this->clearReleaseScope();
        $this->releaseScopeActive = true;
    }

    public function finishReleaseScope(): DownloadMetrics
    {
        $metrics = new DownloadMetrics(
            logicalRequests: $this->logicalRequests,
            networkRequests: $this->networkRequests,
            cacheHits: $this->cacheHits,
            bytesDownloaded: $this->bytesDownloaded,
            bytesReused: $this->bytesReused,
            mediaBytesDownloaded: $this->mediaBytesDownloaded,
        );

        $this->clearReleaseScope();

        return $metrics;
    }

    /**
     * Download binary content from usenet using message IDs.
     *
     * @param  array<int|string, mixed>|string  $messageIDs  Single or array of message IDs
     * @param  string  $groupName  Group name for logging
     * @param  int|null  $releaseId  Release ID for logging
     * @return array{success: bool, data: string|null, groupUnavailable: bool, error: string|null}
     *
     * @throws Exception
     */
    public function downloadByMessageIDs(
        array|string $messageIDs,
        string $groupName = '',
        ?int $releaseId = null
    ): array {
        $result = [
            'success' => false,
            'data' => null,
            'groupUnavailable' => false,
            'error' => null,
        ];

        if (empty($messageIDs)) {
            $result['error'] = 'No message IDs provided';

            return $result;
        }

        // Ensure array format
        if (is_string($messageIDs)) {
            $messageIDs = [$messageIDs];
        }

        if ($this->config->debugMode) {
            Log::debug('Attempting NNTP fetch', [
                'release_id' => $releaseId,
                'message_id_count' => count($messageIDs),
                'request_fingerprint' => $this->downloadFingerprint($messageIDs, $groupName),
                'group' => $groupName,
            ]);
        }

        $binary = $this->nntp->getMessagesByMessageID($messageIDs, $this->config->alternateNNTP);

        return $this->resultFromBinary($binary, $messageIDs, $groupName, $releaseId);
    }

    /**
     * @param  array<int|string, mixed>  $messageIDs
     * @return array{success: bool, data: string|null, groupUnavailable: bool, error: string|null}
     */
    private function resultFromBinary(mixed $binary, array $messageIDs, string $groupName, ?int $releaseId): array
    {
        $result = ['success' => false, 'data' => null, 'groupUnavailable' => false, 'error' => null];

        // Handle non-string or empty response as failure
        if (! is_string($binary) || $binary === '') {
            $errorMessage = null;

            if (is_object($binary) && method_exists($binary, 'getMessage')) {
                $errorMessage = $binary->getMessage();

                if ($this->isGroupUnavailableMessage($errorMessage)) {
                    $result['groupUnavailable'] = true;
                    $result['error'] = 'Group unavailable: '.$errorMessage;

                    return $result;
                }
            }

            if ($this->config->debugMode) {
                Log::debug('NNTP fetch failed', [
                    'release_id' => $releaseId,
                    'message_id_count' => count($messageIDs),
                    'request_fingerprint' => $this->downloadFingerprint($messageIDs, $groupName),
                    'group' => $groupName,
                    'error_object' => is_object($binary) ? get_class($binary) : null,
                    'error_message' => $errorMessage,
                    'raw_type' => gettype($binary),
                    'length' => is_string($binary) ? strlen($binary) : 0,
                ]);
            }

            $result['error'] = $errorMessage ?? 'Download failed';

            return $result;
        }

        $result['success'] = true;
        $result['data'] = $binary;

        return $result;
    }

    /**
     * Download content for a specific processing step.
     *
     * @param  array<int|string, mixed>|string  $messageIDs
     * @param  bool  $cache  false keeps the result out of the release cache
     * @return array{success: bool, data: string|null, groupUnavailable: bool, error: string|null}
     */
    public function download(
        DownloadKind $kind,
        array|string $messageIDs,
        string $groupName = '',
        ?int $releaseId = null,
        ?string $fileTitle = null,
        bool $cache = true,
    ): array {
        $messageIdList = is_array($messageIDs) ? array_values($messageIDs) : [$messageIDs];
        $cacheKey = $this->downloadFingerprint($messageIdList, $groupName);

        if ($this->releaseScopeActive) {
            $this->logicalRequests++;

            if (isset($this->releaseCache[$cacheKey])) {
                $cached = $this->releaseCache[$cacheKey];
                $this->cacheHits++;
                $this->bytesReused += is_string($cached['data']) ? strlen($cached['data']) : 0;

                return $cached;
            }
        }

        if ($this->config->debugMode) {
            Log::debug('Attempting download', [
                'kind' => $kind->value,
                'release_id' => $releaseId,
                'file_title' => $fileTitle,
                'message_id_count' => count($messageIdList),
                'request_fingerprint' => $cacheKey,
                'group' => $groupName,
            ]);
        }

        $reusedBytes = 0;
        if ($this->releaseScopeActive && $kind === DownloadKind::Compressed && ! empty($messageIDs) && $this->mappedArchiveArticles !== []) {
            $networkRequired = $messageIdList !== [] && $messageIdList !== ['']
                && array_any($messageIdList, fn (mixed $id): bool => ! isset($this->mappedArchiveArticles[$this->downloadFingerprint([(string) $id], $groupName)]));
            $binary = $this->nntp->getMessagesByMessageIDWithArticleReuse(
                $messageIDs,
                $this->config->alternateNNTP,
                fn (mixed $id): ?string => $this->mappedArchiveArticles[$this->downloadFingerprint([(string) $id], $groupName)]['data'] ?? null,
                function (int $bytes) use (&$reusedBytes): void {
                    $reusedBytes += $bytes;
                },
            );
            if ($networkRequired) {
                $this->networkRequests++;
            }
            if ($reusedBytes > 0) {
                $this->cacheHits++;
                $this->bytesReused += $reusedBytes;
            }
            $result = $this->resultFromBinary($binary, $messageIdList, $groupName, $releaseId);
        } else {
            if ($this->releaseScopeActive && $messageIdList !== [] && $messageIdList !== ['']) {
                $this->networkRequests++;
            }
            $result = $this->downloadByMessageIDs($messageIDs, $groupName, $releaseId);
        }

        if ($this->releaseScopeActive && $result['success'] && is_string($result['data'])) {
            $byteSize = strlen($result['data']);
            $this->bytesDownloaded += $byteSize - $reusedBytes;
            if ($kind->isPreviewSource()) {
                $this->mediaBytesDownloaded += $byteSize;
            }
            if ($cache) {
                $this->rememberSuccessfulDownload($cacheKey, $result, $byteSize);
            }
        }

        if (! $result['success'] && $this->config->debugMode) {
            Log::debug('Download failed', [
                'kind' => $kind->value,
                'release_id' => $releaseId,
                'file_title' => $fileTitle,
                'message_id_count' => count($messageIdList),
                'request_fingerprint' => $cacheKey,
                'group' => $groupName,
                'error' => $result['error'],
            ]);
        }

        return $result;
    }

    /**
     * Read a complete preview file within a hard BODY-byte allowance, including failed attempts.
     * Partial files are never returned as successful images and bounded requests bypass the cache.
     *
     * @param  list<string>  $messageIds
     * @return array{success: bool, data: string|null, groupUnavailable: bool, error: string|null, bytesDownloaded: int}
     */
    public function downloadBounded(DownloadKind $kind, array $messageIds, int $maximumBytes, string $groupName = '', ?int $releaseId = null): array
    {
        $result = ['success' => false, 'data' => null, 'groupUnavailable' => false, 'error' => null, 'bytesDownloaded' => 0];
        if ($maximumBytes <= 0 || $messageIds === []) {
            return [...$result, 'error' => 'No preview download allowance or message IDs.'];
        }
        if ($this->releaseScopeActive) {
            $this->logicalRequests++;
        }
        $data = '';
        foreach ($messageIds as $messageId) {
            $remaining = $maximumBytes - $result['bytesDownloaded'];
            if ($remaining <= 0) {
                return [...$result, 'error' => 'Preview download byte budget exhausted.'];
            }
            if ($this->releaseScopeActive) {
                $this->networkRequests++;
            }
            $article = $this->nntp->getBoundedArticleByMessageID($messageId, $remaining, $this->config->alternateNNTP);
            $result['bytesDownloaded'] += $article['bytesDownloaded'];
            if ($this->releaseScopeActive) {
                $this->bytesDownloaded += $article['bytesDownloaded'];
                if ($kind->isPreviewSource()) {
                    $this->mediaBytesDownloaded += $article['bytesDownloaded'];
                }
            }
            if ($article['data'] === null) {
                return [...$result, 'error' => $article['error'],
                    'groupUnavailable' => $article['error'] !== null && $this->isGroupUnavailableMessage($article['error'])];
            }
            $data .= $article['data'];
        }

        return [...$result, 'success' => $data !== '', 'data' => $data];
    }

    /**
     * Download one article together with what its yEnc header says about its file.
     *
     * A cached copy is reused, but the article is never added to the release cache,
     * so the cache keeps room for the archives the fallbacks download again.
     *
     * @return array{success: bool, data: string|null, part: YencPart|null, groupUnavailable: bool, error: string|null}
     */
    public function downloadArticle(
        DownloadKind $kind,
        string $messageId,
        string $groupName = '',
        ?int $releaseId = null,
    ): array {
        $result = ['success' => false, 'data' => null, 'part' => null, 'groupUnavailable' => false, 'error' => null];
        if ($messageId === '') {
            $result['error'] = 'No message IDs provided';

            return $result;
        }

        $cacheKey = $this->downloadFingerprint([$messageId], $groupName);
        if ($this->releaseScopeActive) {
            $this->logicalRequests++;

            if (isset($this->releaseCache[$cacheKey])) {
                $cached = $this->releaseCache[$cacheKey];
                $this->cacheHits++;
                $this->bytesReused += is_string($cached['data']) ? strlen($cached['data']) : 0;

                return [...$cached, 'part' => null];
            }

            $this->networkRequests++;
        }

        $reusedBytes = 0;
        if ($this->releaseScopeActive && $kind === DownloadKind::MediaSource && ! $this->capturingMappingArticle
            && isset($this->mappedVideoArticles[$cacheKey])) {
            $article = $this->nntp->getArticleByMessageIDWithReuse($messageId, $this->config->alternateNNTP,
                fn (): ?array => $this->mappedVideoArticles[$cacheKey] ?? null,
                function (int $bytes) use (&$reusedBytes): void {
                    $reusedBytes = $bytes;
                    $this->networkRequests--;
                    $this->cacheHits++;
                    $this->bytesReused += $bytes;
                });
        } elseif ($this->capturingMappingArticle && $this->releaseScopeActive) {
            $article = $this->nntp->getArticleForArchiveMapping($messageId, $this->config->alternateNNTP,
                function (string $data, YencPart $part) use ($cacheKey): void {
                    $bytes = strlen($data);
                    if ($this->pendingMappingArticle === null && $bytes > 0 && $bytes <= self::MAX_CACHE_ENTRY_BYTES
                        && $this->makeRoomForMappingArticle($bytes)) {
                        $this->pendingMappingArticle = ['key' => $cacheKey, 'data' => $data, 'part' => $part];
                        $this->cachedBytes += $bytes;
                    }
                });
        } else {
            $article = $this->nntp->getArticleByMessageID($messageId, $this->config->alternateNNTP);
        }
        if (! is_array($article) || $article['data'] === '') {
            $errorMessage = is_array($article) ? null : $article->getMessage();
            $result['groupUnavailable'] = $errorMessage !== null && $this->isGroupUnavailableMessage($errorMessage);
            $result['error'] = $errorMessage ?? 'Download failed';

            if ($this->config->debugMode) {
                Log::debug('Article download failed', [
                    'kind' => $kind->value,
                    'release_id' => $releaseId,
                    'request_fingerprint' => $cacheKey,
                    'group' => $groupName,
                    'error' => $result['error'],
                ]);
            }

            return $result;
        }

        if ($this->releaseScopeActive) {
            $byteSize = strlen($article['data']);
            $this->bytesDownloaded += $byteSize - $reusedBytes;
            if ($kind->isPreviewSource()) {
                $this->mediaBytesDownloaded += $byteSize - $reusedBytes;
            }
        }

        return [...$result, 'success' => true, 'data' => $article['data'], 'part' => $article['part']];
    }

    /**
     * Capture a fresh integrity-checked primary article for possible PAR2 source mapping.
     *
     * @return array{success: bool, data: string|null, part: YencPart|null, groupUnavailable: bool, error: string|null}
     */
    public function downloadMappingArticle(string $messageId, string $groupName = '', ?int $releaseId = null): array
    {
        $this->discardPendingMappingArticle();
        $this->capturingMappingArticle = true;
        try {
            return $this->downloadArticle(DownloadKind::MediaSource, $messageId, $groupName, $releaseId);
        } catch (\Throwable $exception) {
            $this->clearReleaseScope();
            throw $exception;
        } finally {
            $this->capturingMappingArticle = false;
        }
    }

    /** Keep the legacy archive-only admission contract. */
    public function retainMappedArchiveArticle(Par2FileEntry $file): void
    {
        $this->promoteMappedArticle($file, allowVideo: false);
    }

    /** Promote a fresh receipt after the resolver matched its real PAR2 file. */
    public function retainMappedSourceArticle(Par2FileEntry $file): void
    {
        $this->promoteMappedArticle($file, allowVideo: true);
    }

    private function promoteMappedArticle(Par2FileEntry $file, bool $allowVideo): void
    {
        $article = $this->pendingMappingArticle;
        $this->discardPendingMappingArticle();
        if (! $this->releaseScopeActive || $article === null
            || (! $file->matchesStartOf($article['data'])
                && ($article['part']->name !== $file->name || $article['part']->size !== $file->size))) {
            return;
        }
        $archive = self::isMappedArchiveName($file->name);
        $video = $allowVideo && ! $archive && $this->config->processThumbnails && $this->config->mediaMainVideoFallback
            && $file->size >= $this->config->mediaMainVideoMinBytes
            && self::isMappedVideoName($file->name, $this->config->videoFileRegex)
            && $article['part']->size === $file->size && $article['part']->offset() === 0
            && ($article['part']->part === null || $article['part']->part === 1);
        $key = $article['key'];
        $size = strlen($article['data']);
        if ((! $archive && ! $video) || isset($this->mappedArchiveArticles[$key]) || isset($this->mappedVideoArticles[$key])
            || $this->cacheEntryCount() >= self::MAX_CACHE_ENTRIES
            || $this->cachedBytes + $size > self::MAX_CACHE_BYTES) {
            return;
        }
        if ($archive) {
            $this->mappedArchiveArticles[$key] = ['data' => $article['data'], 'part' => $article['part']];
        } else {
            $this->mappedVideoArticles[$key] = ['data' => $article['data'], 'part' => $article['part']];
        }
        $this->cachedBytes += $size;
    }

    /** Mapping receipts may replace videos while preserving archives and aggregate downloads. */
    private function makeRoomForMappingArticle(int $bytes): bool
    {
        while ($this->mappedVideoArticles !== []
            && ($this->cacheEntryCount() >= self::MAX_CACHE_ENTRIES || $this->cachedBytes + $bytes > self::MAX_CACHE_BYTES)) {
            $this->evictMappedVideoArticle();
        }

        return $this->cacheEntryCount() < self::MAX_CACHE_ENTRIES && $this->cachedBytes + $bytes <= self::MAX_CACHE_BYTES;
    }

    private function evictMappedVideoArticle(): void
    {
        $key = array_key_first($this->mappedVideoArticles);
        $this->cachedBytes -= strlen($this->mappedVideoArticles[$key]['data']);
        unset($this->mappedVideoArticles[$key]);
    }

    /** Release an unmatched receipt at the end of a mapping attempt. */
    public function discardPendingMappingArticle(): void
    {
        if ($this->pendingMappingArticle !== null) {
            $this->cachedBytes -= strlen($this->pendingMappingArticle['data']);
            $this->pendingMappingArticle = null;
        }
    }

    private function cacheEntryCount(): int
    {
        return count($this->releaseCache) + count($this->mappedArchiveArticles) + count($this->mappedVideoArticles) + ($this->pendingMappingArticle === null ? 0 : 1);
    }

    /**
     * Retains the native PHP path in production (~0.44x-0.72x mean in paired benchmarks);
     * see isMappedArchiveNameNative().
     */
    public static function isMappedArchiveName(string $name): bool
    {
        return self::isMappedArchiveNamePhp($name);
    }

    public static function isMappedArchiveNamePhp(string $name): bool
    {
        return preg_match('/\.(?:rar|7z(?:\.\d+)?|zip|\d{3})$/i', $name) === 1;
    }

    public static function isMappedArchiveNameNative(string $name, bool &$supported = false, ?string $libraryPath = null): bool
    {
        $kind = self::mappedArticleKind($name, $libraryPath);
        $supported = $kind !== null;

        return $kind === null ? self::isMappedArchiveNamePhp($name) : ($kind & 1) === 1;
    }

    /**
     * A main-video name: not a sample and ending in a configured video extension.
     *
     * Retains the native PHP path in production (~0.76x common, ~1.14x-1.32x uncommon mean
     * in paired benchmarks); see isMappedVideoNameNative().
     */
    public static function isMappedVideoName(string $name, string $videoFileRegex): bool
    {
        return self::isMappedVideoNamePhp($name, $videoFileRegex);
    }

    public static function isMappedVideoNamePhp(string $name, string $videoFileRegex): bool
    {
        return stripos($name, 'sample') === false
            && preg_match('/'.$videoFileRegex.'$/i', $name) === 1;
    }

    public static function isMappedVideoNameNative(string $name, string $videoFileRegex, bool &$supported = false, ?string $libraryPath = null): bool
    {
        $kind = $videoFileRegex === self::DEFAULT_VIDEO_FILE_REGEX ? self::mappedArticleKind($name, $libraryPath) : null;
        $supported = $kind !== null;

        return $kind === null ? self::isMappedVideoNamePhp($name, $videoFileRegex) : ($kind & 2) === 2;
    }

    /**
     * Retains the native PHP path in production (~0.37x-0.52x mean in paired benchmarks);
     * see isGroupUnavailableMessageNative().
     */
    private function isGroupUnavailableMessage(string $errorMessage): bool
    {
        return self::isGroupUnavailableMessagePhp($errorMessage);
    }

    public static function isGroupUnavailableMessagePhp(string $errorMessage): bool
    {
        return stripos($errorMessage, 'No such news group') !== false
            || stripos($errorMessage, 'Group not found') !== false;
    }

    public static function isGroupUnavailableMessageNative(string $errorMessage, bool &$supported = false, ?string $libraryPath = null): bool
    {
        $supported = false;
        $ffi = self::rustFfi($libraryPath);
        if ($ffi === null) {
            return self::isGroupUnavailableMessagePhp($errorMessage);
        }

        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_rfm_is_group_unavailable($errorMessage, strlen($errorMessage));
        } catch (\Throwable) {
            return self::isGroupUnavailableMessagePhp($errorMessage);
        }

        if (! is_int($status) || $status < 0) {
            return self::isGroupUnavailableMessagePhp($errorMessage);
        }
        $supported = true;

        return $status === 1;
    }

    /**
     * Toggle the Rust helpers; disabled, every *Native method falls back to PHP.
     */
    public static function useRustFfi(bool $use): void
    {
        self::$useRustFfi = $use;
    }

    private static function mappedArticleKind(string $name, ?string $libraryPath): ?int
    {
        $ffi = self::rustFfi($libraryPath);
        if ($ffi === null) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $kind = $ffi->nntmux_rfm_mapped_article_kind($name, strlen($name));
        } catch (\Throwable) {
            return null;
        }

        return is_int($kind) && $kind >= 0 ? $kind : null;
    }

    private static function rustFfi(?string $libraryPath = null): ?FFI
    {
        if (! self::$useRustFfi) {
            return null;
        }
        if ($libraryPath !== null) {
            return RustPipelineLibrary::resolveDomain('usenet_download', self::FFI_CDEF, $libraryPath);
        }
        if (! self::$ffiResolved) {
            self::$ffiResolved = true;
            self::$ffi = RustPipelineLibrary::resolveDomain('usenet_download', self::FFI_CDEF);
        }

        return self::$ffi;
    }

    /**
     * @param  list<mixed>  $messageIds
     */
    private function downloadFingerprint(array $messageIds, string $groupName): string
    {
        return hash('sha256', serialize([
            'message_ids' => array_map(static fn (mixed $messageId): string => (string) $messageId, $messageIds),
            'group' => $groupName,
            'alternate' => $this->config->alternateNNTP,
        ]));
    }

    /**
     * @param  array{success: bool, data: string|null, groupUnavailable: bool, error: string|null}  $result
     */
    private function rememberSuccessfulDownload(string $cacheKey, array $result, int $byteSize): void
    {
        if ($byteSize > self::MAX_CACHE_ENTRY_BYTES || count($this->releaseCache) >= self::MAX_CACHE_ENTRIES) {
            return;
        }
        while (($this->pendingMappingArticle !== null || $this->mappedVideoArticles !== [] || $this->mappedArchiveArticles !== [])
            && ($this->cacheEntryCount() >= self::MAX_CACHE_ENTRIES
                || $this->cachedBytes + $byteSize > self::MAX_CACHE_BYTES)) {
            if ($this->pendingMappingArticle !== null) {
                $this->discardPendingMappingArticle();

                continue;
            }
            if ($this->mappedVideoArticles !== []) {
                $this->evictMappedVideoArticle();

                continue;
            }
            $key = array_key_first($this->mappedArchiveArticles);
            $this->cachedBytes -= strlen($this->mappedArchiveArticles[$key]['data']);
            unset($this->mappedArchiveArticles[$key]);
        }
        if ($this->cachedBytes + $byteSize > self::MAX_CACHE_BYTES) {
            return;
        }

        $this->releaseCache[$cacheKey] = $result;
        $this->cachedBytes += $byteSize;
    }

    private function clearReleaseScope(): void
    {
        $this->releaseScopeActive = false;
        $this->releaseCache = [];
        $this->mappedArchiveArticles = [];
        $this->mappedVideoArticles = [];
        $this->pendingMappingArticle = null;
        $this->capturingMappingArticle = false;
        $this->cachedBytes = 0;
        $this->logicalRequests = 0;
        $this->networkRequests = 0;
        $this->cacheHits = 0;
        $this->bytesDownloaded = 0;
        $this->bytesReused = 0;
        $this->mediaBytesDownloaded = 0;
    }

    /**
     * Get the NNTP client instance.
     */
    public function getNNTP(): NNTPService
    {
        return $this->nntp;
    }

    /**
     * Check if the minimum content size requirement is met.
     */
    public function meetsMinimumSize(string $data, int $minimumBytes = 40): bool
    {
        return strlen($data) > $minimumBytes;
    }

    public static function useW406RustFfi(?bool $enabled): void
    {
        self::$w406UseRustFfi = $enabled;
    }

    public static function usesW406RustFfi(): bool
    {
        return self::$w406UseRustFfi ?? self::W406_RUST_FFI_DEFAULT;
    }

    public static function resetW406RustFfi(): void
    {
        self::$w406UseRustFfi = null;
    }

    /**
     * Summarize NNTP segment outcomes. Each outcome is an array whose string 'status' is ok/downloaded,
     * missing/not_found or timeout/error/retry (compared exactly); other values only count toward the total.
     * Downloaded segments add max(0, an integer 'bytes'), saturating at PHP_INT_MAX. The window aborts once
     * more than max(0, $maxMissingSegments) articles are missing.
     *
     * @param  array<array-key, mixed>  $segmentOutcomes
     * @return array{total_segments: int, downloaded: int, missing_articles: int, transient_errors: int, downloaded_bytes: int, abort_threshold_exceeded: bool}
     */
    public static function summarizeSegmentDownloadWindow(array $segmentOutcomes, int $maxMissingSegments = 2): array
    {
        return (self::usesW406RustFfi() ? self::summarizeSegmentDownloadWindowNative($segmentOutcomes, $maxMissingSegments) : null)
            ?? self::summarizeSegmentDownloadWindowPhp($segmentOutcomes, $maxMissingSegments);
    }

    /**
     * @param  array<array-key, mixed>  $segmentOutcomes
     * @return array{total_segments: int, downloaded: int, missing_articles: int, transient_errors: int, downloaded_bytes: int, abort_threshold_exceeded: bool}
     */
    public static function summarizeSegmentDownloadWindowPhp(array $segmentOutcomes, int $maxMissingSegments = 2): array
    {
        $downloaded = 0;
        $missing = 0;
        $transient = 0;
        $bytes = 0;
        foreach ($segmentOutcomes as $outcome) {
            [$status, $size] = self::w406Segment($outcome);
            switch ($status) {
                case 'ok':
                case 'downloaded':
                    $downloaded++;
                    if ($size > 0) {
                        $bytes = $bytes > PHP_INT_MAX - $size ? PHP_INT_MAX : $bytes + $size;
                    }
                    break;
                case 'missing':
                case 'not_found':
                    $missing++;
                    break;
                case 'timeout':
                case 'error':
                case 'retry':
                    $transient++;
                    break;
            }
        }

        return [
            'total_segments' => \count($segmentOutcomes),
            'downloaded' => $downloaded,
            'missing_articles' => $missing,
            'transient_errors' => $transient,
            'downloaded_bytes' => $bytes,
            'abort_threshold_exceeded' => $missing > max(0, $maxMissingSegments),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $segmentOutcomes
     * @return array{total_segments: int, downloaded: int, missing_articles: int, transient_errors: int, downloaded_bytes: int, abort_threshold_exceeded: bool}|null
     */
    public static function summarizeSegmentDownloadWindowNative(array $segmentOutcomes, int $maxMissingSegments = 2): ?array
    {
        $total = \count($segmentOutcomes);
        $frame = pack('qV', $maxMissingSegments, $total);
        foreach ($segmentOutcomes as $outcome) {
            [$status, $size] = self::w406Segment($outcome);
            $frame .= ArchiveExtractionService::w406OptionalString($status).pack('q', $size);
        }
        $slots = ArchiveExtractionService::w406Slots(3, $frame, 6);
        if ($slots === null || $slots[0] !== $total || $slots[1] < 0 || $slots[2] < 0 || $slots[3] < 0
            || $total < $slots[1] + $slots[2] + $slots[3] || $slots[4] < 0 || ($slots[5] & ~1) !== 0) {
            return null;
        }

        return [
            'total_segments' => $total,
            'downloaded' => $slots[1],
            'missing_articles' => $slots[2],
            'transient_errors' => $slots[3],
            'downloaded_bytes' => $slots[4],
            'abort_threshold_exceeded' => $slots[5] === 1,
        ];
    }

    /** @return array{0: string|null, 1: int} The outcome's string status and integer byte count, if any. */
    private static function w406Segment(mixed $outcome): array
    {
        if (! \is_array($outcome)) {
            return [null, 0];
        }
        $status = $outcome['status'] ?? null;
        $size = $outcome['bytes'] ?? null;

        return [\is_string($status) ? $status : null, \is_int($size) ? $size : 0];
    }
}
