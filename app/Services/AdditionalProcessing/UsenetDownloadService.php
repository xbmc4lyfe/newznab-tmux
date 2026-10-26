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
use FFI\CData;
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

    /** Enabled: three paired runs cleared every 1.5x gate (common mean 4.22-4.42x, p99 3.69-3.95x; uncommon mean 3.78-3.92x, p99 3.08-3.87x); see RustReleaseFileArchiveProcessorSyncUsenetW52ParityTest. */
    private const bool W52_RUST_FFI_DEFAULT = true;

    public const string W52_FFI_CDEF = <<<'C'
        ssize_t nntmux_w523_mapped_article_kind_batch(const char *blob, size_t blob_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    private static ?bool $useW52RustFfi = null;

    private static FFI|false|null $w52Ffi = null;

    private static ?CData $w52Out = null;

    private static int $w52OutCap = 0;

    public static function usesW52RustFfi(): bool
    {
        return self::$useW52RustFfi ?? self::W52_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 52 Rust path forced on or off, then restores the previous setting. */
    public static function withW52RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$useW52RustFfi;
        self::$useW52RustFfi = $enabled;
        try {
            return $callback();
        } finally {
            self::$useW52RustFfi = $previous;
        }
    }

    /**
     * Classifies PAR2-mapped article names: one word per name with 1 isMappedArchiveNamePhp() and
     * 2 isMappedVideoNamePhp($name, $videoFileRegex), then the archive and video counts. Rust
     * handles the default video regex; any other regex stays on PHP.
     *
     * @param  array<int, string>  $names
     * @return list<int>
     */
    public static function w52MappedArticleKindBatch(array $names, string $videoFileRegex = self::DEFAULT_VIDEO_FILE_REGEX): array
    {
        return self::usesW52RustFfi() ? self::w52MappedArticleKindBatchNative($names, $videoFileRegex) : self::w52MappedArticleKindBatchPhp($names, $videoFileRegex);
    }

    /**
     * @param  array<int, string>  $names
     * @return list<int>
     */
    public static function w52MappedArticleKindBatchNative(array $names, string $videoFileRegex = self::DEFAULT_VIDEO_FILE_REGEX): array
    {
        if ($videoFileRegex !== self::DEFAULT_VIDEO_FILE_REGEX) {
            return self::w52MappedArticleKindBatchPhp($names, $videoFileRegex);
        }
        $count = \count($names);

        return self::w52Invoke(implode("\0", $names), '', $count, $count + 2)
            ?? self::w52MappedArticleKindBatchPhp($names, $videoFileRegex);
    }

    /**
     * @param  array<int, string>  $names
     * @return list<int>
     */
    public static function w52MappedArticleKindBatchPhp(array $names, string $videoFileRegex = self::DEFAULT_VIDEO_FILE_REGEX): array
    {
        $out = [];
        $archives = 0;
        $videos = 0;
        foreach ($names as $name) {
            $name = (string) $name;
            $archive = self::isMappedArchiveNamePhp($name);
            $video = self::isMappedVideoNamePhp($name, $videoFileRegex);
            $archives += (int) $archive;
            $videos += (int) $video;
            $out[] = (int) $archive | ($video ? 2 : 0);
        }
        $out[] = $archives;
        $out[] = $videos;

        return $out;
    }

    /**
     * Calls the Wave 52 export with NUL-joined strings and packed `i64` integers. Null when the
     * library is missing, the call declines (for example a string holding a NUL byte) or it writes
     * more than $cap words, so the caller falls back to PHP.
     *
     * @return list<int>|null
     */
    private static function w52Invoke(string $blob, string $ints, int $count, int $cap): ?array
    {
        self::$w52Ffi ??= RustPipelineLibrary::resolveDomain('categorize_w52', self::W52_FFI_CDEF) ?? false;
        $ffi = self::$w52Ffi;
        if ($ffi === false) {
            return null;
        }
        try {
            if (self::$w52Out === null || self::$w52OutCap < $cap) {
                self::$w52OutCap = max(1024, $cap * 2);
                self::$w52Out = $ffi->new('int64_t['.self::$w52OutCap.']');
            }
            // @phpstan-ignore method.notFound (Declared by W52_FFI_CDEF.)
            $written = $ffi->nntmux_w523_mapped_article_kind_batch($blob, \strlen($blob), $ints, \strlen($ints), $count, self::$w52Out, self::$w52OutCap);
            if (! \is_int($written) || $written <= 0 || $written > $cap) {
                return null;
            }
            $values = unpack('P*', FFI::string(self::$w52Out, $written * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (\Throwable) {
            return null;
        }
    }

    // ========================================================================
    // WAVE 65.4 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 1000-round paired runs reached 2.53x common mean, 2.43x common p99, 2.25x uncommon mean and 2.25x uncommon p99; see RustUsenetDownloadAdminSnapshotAnidbArchiveBlacklistW65ParityTest. */
    public const bool W65_RUST_FFI_DEFAULT = true;

    private const string W65_FFI_CDEF = 'ssize_t nntmux_w654_usenet_download_plan_batch(const char *blob, size_t blob_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);';

    /** int64 words per w65UsenetDownloadPlanBatch() record. */
    public const int W65_ROW_WORDS = 5;

    /** int64 summary words after the w65UsenetDownloadPlanBatch() records (none for an empty batch). */
    public const int W65_TRAILER_WORDS = 13;

    private const string W65_ID_SEPARATORS = '/[ \t\r\n,]+/';

    private const string W65_MESSAGE_ID = '/\A[^@\x00-\x20\x7f]+@[^@\x00-\x20\x7f]+\z/';

    private const int W65_MESSAGE_ID_MAX = 250;

    private const int W65_BATCH_ARTICLES = 20;

    private const int W65_VALUE_CAP = (1 << 40) - 1;

    private const int W65_TOTAL_CAP = 1 << 62;

    private const int W65_DEFAULT_PART = 716_800;

    private const int W65_PART_CAP = 1 << 30;

    /**
     * Usenet download plan rows for [message IDs, group, last error, decoded bytes per article,
     * byte budget, attempts so far, alternate NNTP available] items.
     *
     * Message IDs split on runs of spaces, tabs, CR, LF and commas; each token is trimmed of `<>`
     * and is valid when it has at most 250 bytes and matches `local@domain` without control
     * bytes, spaces, DEL or a second `@`. Repeated valid IDs count once. A part size of 0 or less
     * is 716,800 bytes (capped at 2^30); budgets clamp to [0, 2^40) and 0 means unbounded. The
     * trimmed error picks the decision: 5 no valid IDs, 1 fetch (no error), 2 alternate NNTP (a
     * group-unavailable error with an alternate server), 3 retry (another error, fewer than 3
     * attempts), 4 give up. Fetch, alternate and retry plan `min(unique, budget / part)`
     * articles in BODY batches of 20; the yEnc assembly estimate adds 2 bytes per 128-byte line
     * and 96 header/trailer bytes per article.
     *
     * Rows [decision | flags << 4 (1 budget capped, 2 invalid IDs, 4 duplicate IDs, 8 group
     * unavailable, 16 alternate NNTP, 32 error) | IDs << 12 | unique IDs << 28 | batches << 44
     * (each clamped to 2^16 - 1), fnv1a32(strtolower(trim(group))) (0 when empty), articles to
     * fetch, decoded bytes, yEnc bytes], then the trailer [records, fetch, alternate, retry,
     * give up, no IDs, IDs, unique IDs, invalid IDs, duplicate IDs, decoded bytes, yEnc bytes
     * (both saturating at 2^62), FNV-1a digest].
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: int, 4: int, 5: int, 6: int}>  $items
     * @return list<int>
     */
    public static function w65UsenetDownloadPlanBatch(array $items): array
    {
        return self::usesW65RustFfi() ? self::w65UsenetDownloadPlanBatchNative($items) : self::w65UsenetDownloadPlanBatchPhp($items);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: int, 4: int, 5: int, 6: int}>  $items
     * @return list<int>
     */
    public static function w65UsenetDownloadPlanBatchPhp(array $items): array
    {
        self::w65Items($items);
        if ($items === []) {
            return [];
        }
        $out = [];
        $t = [\count($items), 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
        $digest = 2_166_136_261;
        foreach ($items as [$ids, $group, $error, $part, $budget, $attempts, $alternate]) {
            $part = $part > 0 ? min($part, self::W65_PART_CAP) : self::W65_DEFAULT_PART;
            $budget = max(0, min($budget, self::W65_VALUE_CAP));
            $error = trim($error);
            $seen = [];
            $total = $invalid = $duplicates = 0;
            foreach (preg_split(self::W65_ID_SEPARATORS, $ids, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                $total++;
                $id = trim($token, '<>');
                if (\strlen($id) > self::W65_MESSAGE_ID_MAX || preg_match(self::W65_MESSAGE_ID, $id) !== 1) {
                    $invalid++;
                } elseif (isset($seen[$id])) {
                    $duplicates++;
                } else {
                    $seen[$id] = true;
                }
            }
            $unique = \count($seen);
            $unavailable = self::isGroupUnavailableMessagePhp($error);
            $decision = match (true) {
                $unique === 0 => 5,
                $error === '' => 1,
                $unavailable => $alternate !== 0 ? 2 : 4,
                $attempts < 3 => 3,
                default => 4,
            };
            $fetch = $decision <= 3 ? ($budget > 0 ? min($unique, intdiv($budget, $part)) : $unique) : 0;
            $decoded = $fetch * $part;
            $encoded = $fetch === 0 ? 0 : $decoded + 2 * intdiv($decoded + 127, 128) + 96 * $fetch;
            $flags = ($decision <= 3 && $fetch < $unique ? 1 : 0) | ($invalid > 0 ? 2 : 0) | ($duplicates > 0 ? 4 : 0)
                | ($unavailable ? 8 : 0) | ($alternate !== 0 ? 16 : 0) | ($error !== '' ? 32 : 0);
            $hash = self::w65Fnv(strtolower(trim($group)));
            $batches = intdiv($fetch + self::W65_BATCH_ARTICLES - 1, self::W65_BATCH_ARTICLES);
            $word = $decision | $flags << 4 | min($total, 0xFFFF) << 12 | min($unique, 0xFFFF) << 28 | min($batches, 0xFFFF) << 44;
            array_push($out, $word, $hash, $fetch, $decoded, $encoded);
            $t[$decision]++;
            $t[6] += $total;
            $t[7] += $unique;
            $t[8] += $invalid;
            $t[9] += $duplicates;
            $t[10] = min($t[10] + $decoded, self::W65_TOTAL_CAP);
            $t[11] = min($t[11] + $encoded, self::W65_TOTAL_CAP);
            $value = $word ^ $hash << 16 ^ $decoded;
            $digest = (($digest ^ (($value ^ ($value >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
        }
        $t[12] = $digest;

        return [...$out, ...$t];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: int, 4: int, 5: int, 6: int}>  $items
     * @return list<int>
     */
    public static function w65UsenetDownloadPlanBatchNative(array $items): array
    {
        self::w65Items($items);
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        [$blob, $twin] = self::w65Join([array_column($items, 0), array_column($items, 1), array_column($items, 2)]);
        $ints = pack('P*', ...array_column($items, 3), ...array_column($items, 4), ...array_column($items, 5), ...array_column($items, 6));

        return self::w65Call($blob, $twin, $ints, $count, $count * self::W65_ROW_WORDS + self::W65_TRAILER_WORDS)
            ?? self::w65UsenetDownloadPlanBatchPhp($items);
    }

    /** @param array<array-key, mixed> $items */
    private static function w65Items(array $items): void
    {
        if (! array_is_list($items)) {
            throw new \InvalidArgumentException('W65 usenet download plan batch items must be a list.');
        }
        foreach ($items as $item) {
            if (! \is_array($item) || \count($item) !== 7 || ! array_is_list($item) || ! \is_string($item[0]) || ! \is_string($item[1]) || ! \is_string($item[2]) || ! \is_int($item[3]) || ! \is_int($item[4]) || ! \is_int($item[5]) || ! \is_int($item[6])) {
                throw new \InvalidArgumentException('W65 usenet download plan batch items must be [string message IDs, string group, string error, int part size, int byte budget, int attempts, int alternate NNTP] lists.');
            }
        }
    }

    public static function usesW65RustFfi(): bool
    {
        $enabled = self::w65RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W65_RUST_FFI_DEFAULT;
    }

    public static function enableW65RustFfi(): void
    {
        self::w65RustFfiState('enabled', true);
    }

    public static function disableW65RustFfi(): void
    {
        self::w65RustFfiState('enabled', false);
    }

    /** Drop the W65 override, binding, output buffer and declined-call count; routing returns to W65_RUST_FFI_DEFAULT. */
    public static function resetW65RustFfi(): void
    {
        self::w65RustFfiState('reset');
    }

    /**
     * Run $callback with the W65 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW65RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w65RustFfiState('enabled');
        self::w65RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w65RustFfiState('enabled', $previous);
        }
    }

    /**
     * String columns NUL-joined field-major and, when a value holds a NUL, the same columns
     * joined by 0x01 so Rust finds the real separators.
     *
     * @param  list<list<string>>  $columns
     * @return array{0: string, 1: string}
     */
    private static function w65Join(array $columns): array
    {
        $values = array_merge(...$columns);
        $joined = implode("\0", $values);

        return substr_count($joined, "\0") === \count($values) - 1 ? [$joined, ''] : [$joined, implode("\x01", $values)];
    }

    /**
     * One FFI crossing for the batch: $need int64 words back. Null when the library is missing
     * or declines a malformed frame, so the caller falls back to PHP; a declined call with a
     * resolved library is counted in the 'misses' state.
     *
     * @return list<int>|null
     */
    private static function w65Call(string $blob, string $twin, string $ints, int $count, int $need): ?array
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        $state = self::w65RustFfiState();
        $ffi = $state['ffi'];
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata_w65', self::W65_FFI_CDEF) ?? false;
            self::w65RustFfiState('ffi', $ffi);
        }
        if (! $ffi instanceof FFI) {
            return null;
        }
        try {
            $out = $state['out'];
            $cap = $state['cap'];
            if (! $out instanceof CData || $cap < $need) {
                $cap = max(4096, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w65RustFfiState('out', $out);
                self::w65RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W65_FFI_CDEF.)
            $written = $ffi->nntmux_w654_usenet_download_plan_batch($blob, \strlen($blob), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $cap);
            $values = $written === $need ? unpack('P*', FFI::string($out, $need * 8)) : false;
            if ($values !== false) {
                return array_values($values);
            }
        } catch (\Throwable) {
        }
        self::w65RustFfiState('misses', $state['misses'] + 1);

        return null;
    }

    /**
     * W65 routing override, cached binding, reusable output buffer and declined-call count, kept
     * in a function static so the class declares no new property: no arguments returns the state,
     * one argument reads a key ('reset' clears everything) and two arguments write a key.
     */
    private static function w65RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;

            return null;
        }

        return $state[$op] ?? null;
    }

    /** FNV-1a 32 of $bytes as an unsigned int, 0 for an empty string. */
    private static function w65Fnv(string $bytes): int
    {
        if ($bytes === '') {
            return 0;
        }
        /** @var array{1: int} $hash */
        $hash = unpack('N', hash('fnv1a32', $bytes, true));

        return $hash[1];
    }
}
