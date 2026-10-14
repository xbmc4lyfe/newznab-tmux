<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\DTO\DownloadMetrics;
use App\Services\AdditionalProcessing\DTO\Par2FileEntry;
use App\Services\AdditionalProcessing\Enums\DownloadKind;
use App\Services\NNTP\NNTPService;
use App\Services\Yenc\YencPart;
use Exception;
use Illuminate\Support\Facades\Log;

/**
 * Service for downloading content from Usenet via NNTP.
 * Handles message ID downloading with error handling and group availability detection.
 */
class UsenetDownloadService
{
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

        $article = $this->capturingMappingArticle && $this->releaseScopeActive
            ? $this->nntp->getArticleForArchiveMapping($messageId, $this->config->alternateNNTP,
                function (string $data, YencPart $part) use ($cacheKey): void {
                    $bytes = strlen($data);
                    if ($this->pendingMappingArticle === null && $bytes > 0 && $bytes <= self::MAX_CACHE_ENTRY_BYTES
                        && $this->cacheEntryCount() < self::MAX_CACHE_ENTRIES
                        && $this->cachedBytes + $bytes <= self::MAX_CACHE_BYTES) {
                        $this->pendingMappingArticle = ['key' => $cacheKey, 'data' => $data, 'part' => $part];
                        $this->cachedBytes += $bytes;
                    }
                })
            : $this->nntp->getArticleByMessageID($messageId, $this->config->alternateNNTP);
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
            $this->bytesDownloaded += $byteSize;
            if ($kind->isPreviewSource()) {
                $this->mediaBytesDownloaded += $byteSize;
            }
        }

        return [...$result, 'success' => true, 'data' => $article['data'], 'part' => $article['part']];
    }

    /**
     * Capture a fresh integrity-checked primary article for possible archive mapping.
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

    /** Promote only the internally captured article that actually matched an archive entry. */
    public function retainMappedArchiveArticle(Par2FileEntry $file): void
    {
        $article = $this->pendingMappingArticle;
        $this->discardPendingMappingArticle();
        if (! $this->releaseScopeActive || $article === null
            || preg_match('/\.(?:rar|7z(?:\.\d+)?|zip|\d{3})$/i', $file->name) !== 1
            || (! $file->matchesStartOf($article['data'])
                && ($article['part']->name !== $file->name || $article['part']->size !== $file->size))) {
            return;
        }
        $key = $article['key'];
        $size = strlen($article['data']);
        if (isset($this->mappedArchiveArticles[$key])
            || count($this->releaseCache) + count($this->mappedArchiveArticles) >= self::MAX_CACHE_ENTRIES
            || $this->cachedBytes + $size > self::MAX_CACHE_BYTES) {
            return;
        }
        $this->mappedArchiveArticles[$key] = ['data' => $article['data'], 'part' => $article['part']];
        $this->cachedBytes += $size;
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
        return count($this->releaseCache) + count($this->mappedArchiveArticles) + ($this->pendingMappingArticle === null ? 0 : 1);
    }

    private function isGroupUnavailableMessage(string $errorMessage): bool
    {
        return stripos($errorMessage, 'No such news group') !== false
            || stripos($errorMessage, 'Group not found') !== false;
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
        while (($this->pendingMappingArticle !== null || $this->mappedArchiveArticles !== [])
            && ($this->cacheEntryCount() >= self::MAX_CACHE_ENTRIES
                || $this->cachedBytes + $byteSize > self::MAX_CACHE_BYTES)) {
            if ($this->pendingMappingArticle !== null) {
                $this->discardPendingMappingArticle();

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
}
