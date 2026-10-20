<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\Enums\VideoContainer;
use App\Services\Nzb\NzbBlobStore;
use App\Services\Nzb\NzbParserService;
use App\Services\Nzb\NzbService;
use App\Services\Nzb\NzbSourceGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Service for parsing NZB file contents and extracting file metadata.
 * Handles NZB repair, file listing, and message ID extraction.
 */
class NzbContentParser
{
    public function __construct(
        private readonly NzbService $nzb,
        private readonly NzbParserService $nzbParser,
        private readonly bool $debugMode = false,
        private readonly bool $echoCLI = false
    ) {}

    /**
     * Parse an NZB file and return its contents as an array of files.
     *
     * @param  string  $guid  The release GUID to find the NZB for
     * @return array{contents: array<string, mixed>, error: string|null, sourceDigest?: ?string, sourceChanged?: bool, directVideoFiles?: list<array{name: string, firstMessageId: string}>|null, sourceFiles?: list<array{name: string, firstMessageId: string}>|null}
     */
    public function parseNzb(string $guid): array
    {
        $source = (new NzbSourceGuard($this->nzb))->snapshot($guid, beginNamingInspection: true);
        if ($source === null) {
            return ['contents' => [], 'error' => 'NZB is missing or broken for GUID: '.$guid, 'sourceDigest' => null, 'sourceChanged' => true];
        }
        $nzbPath = $source['path'];
        $nzbContents = $source['contents'];

        // Get a list of files in the NZB
        $fileList = $this->nzbParser->parseNzbFileList($nzbContents, ['no-file-key' => false, 'strip-count' => true]);
        if (count($fileList) === 0) {
            // Attempt repair if initial parse yielded no files
            $repaired = $this->repairNzb($nzbContents, $nzbPath, $guid);
            $retained = (new NzbSourceGuard($this->nzb))->snapshot($guid, beginNamingInspection: true);
            if ($retained === null || ($repaired === null ? $retained['digest'] !== $source['digest'] : $retained['contents'] !== $repaired)) {
                return ['contents' => [], 'error' => 'NZB source changed during repair for GUID: '.$guid, 'sourceDigest' => null, 'sourceChanged' => true];
            }
            $source = $retained;
            if ($repaired !== null) {
                $fileList = $this->nzbParser->parseNzbFileList($repaired, ['no-file-key' => false, 'strip-count' => true]);
            }
            if (count($fileList) === 0) {
                return ['contents' => [], 'error' => 'NZB is potentially broken for GUID: '.$guid, 'sourceDigest' => $source['digest'], 'sourceChanged' => true];
            }
        }

        // Sort keys naturally
        ksort($fileList, SORT_NATURAL);

        $sourceFiles = $this->sourceFileInventory($source['contents'], $fileList);
        $directFiles = $sourceFiles;
        foreach ($sourceFiles ?? [] as $file) {
            if (VideoContainer::fromFileName('video.'.pathinfo($file['name'], PATHINFO_EXTENSION)) === null) {
                $directFiles = null;
                break;
            }
        }

        return ['contents' => $fileList, 'error' => null, 'sourceDigest' => $source['digest'],
            'sourceFiles' => $sourceFiles, 'directVideoFiles' => $directFiles];
    }

    /**
     * Nominate the full unmerged source inventory. Subjects alone never attest
     * whether a file is an archive, direct media, or harmless metadata.
     *
     * @param  array<string, mixed>  $parsedFiles
     * @return list<array{name: string, firstMessageId: string}>|null
     */
    private function sourceFileInventory(string $decoded, array $parsedFiles): ?array
    {
        if (stripos($decoded, '<!ENTITY') !== false) {
            return null;
        }
        $xml = simplexml_load_string($decoded, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        if ($xml === false || count($xml->file) === 0 || count($xml->file) > 256 || count($xml->file) !== count($parsedFiles)) {
            return null;
        }
        foreach ($parsedFiles as $file) {
            if (! is_array($file) || (int) ($file['filecount'] ?? 0) !== 1) {
                return null;
            }
        }
        foreach ($xml->head->meta ?? [] as $meta) {
            if (in_array((string) $meta['type'], ['nntmux:declared-file-count', 'nntmux:verified-file-count'], true)
                && (! ctype_digit((string) $meta) || (int) (string) $meta > count($xml->file))) {
                return null;
            }
        }
        $inventory = [];
        $messageIds = [];
        $nameBytes = 0;
        foreach ($xml->file as $file) {
            $subject = (string) $file['subject'];
            if (preg_match('/^[^"\r\n]*"([^"\r\n]+)"(?:\s+yEnc)?(?:\s+\(\d+\/(\d+)\))?\s*$/iD', $subject, $match) !== 1
                && preg_match('/^([^"\r\n]+?)(?:\s+yEnc)?(?:\s+\(\d+\/(\d+)\))?\s*$/iD', $subject, $match) !== 1) {
                return null;
            }
            $name = trim($match[1]);
            $nameBytes += strlen($name);
            if ($name === '' || strlen($name) > 4096 || $nameBytes > 32768 || ! mb_check_encoding($name, 'UTF-8')
                || preg_match('/[\x00-\x1f\x7f]/', $name) === 1 || in_array($name[0], ['.', '-'], true)
                || basename($name) !== $name || str_contains($name, '\\')) {
                return null;
            }
            $segments = [];
            foreach ($file->segments->segment ?? [] as $segment) {
                $number = (string) $segment['number'];
                if (! ctype_digit($number) || (int) $number < 1 || isset($segments[(int) $number]) || trim((string) $segment) === '') {
                    return null;
                }
                $segments[(int) $number] = (string) $segment;
            }
            ksort($segments);
            if ($segments === [] || array_keys($segments) !== range(1, count($segments))
                || (isset($match[2]) && (int) $match[2] !== count($segments)) || isset($messageIds[$segments[1]])) {
                return null;
            }
            $messageIds[$segments[1]] = true;
            $inventory[] = ['name' => $name, 'firstMessageId' => $segments[1]];
        }

        return $inventory;
    }

    /**
     * Attempt to repair a potentially broken NZB XML string.
     */
    public function repairNzb(string $raw, string $originalPath, string $guid): ?string
    {
        // Remove common binary / control chars except tab, newline, carriage return
        $fixed = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $raw);

        // If missing opening <nzb ...> tag, wrap content
        if (! str_contains(strtolower($fixed), '<nzb')) {
            $fixed = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<nzb xmlns=\"http://www.newzbin.com/DTD/2003/nzb\">\n".$fixed."\n</nzb>";
        } else {
            // Ensure closing tag
            if (! preg_match('/<\/nzb>\s*$/i', $fixed)) {
                $fixed .= "\n</nzb>";
            }
        }

        // Try to parse using libxml recovery
        $opts = LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT | LIBXML_NONET | LIBXML_NOCDATA | LIBXML_PARSEHUGE;
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($fixed, 'SimpleXMLElement', $opts);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if ($xml === false || empty($xml->file)) {
            if ($this->debugMode && $this->echoCLI) {
                $guidInfo = $guid ? ' for GUID: '.$guid : '';
                echo 'NZB repair failed'.$guidInfo.' ('.count($errors).' XML errors)'.PHP_EOL;
            }

            return null;
        }

        if ($fixed === $raw) {
            return $raw;
        }
        if (NzbBlobStore::usesDatabase()) {
            return $this->storeRepairedRow($raw, $fixed, $guid) ? $fixed : null;
        }
        $temporary = NzbService::temporaryNzbPathFor($originalPath);
        try {
            if ($guid === '') {
                return null;
            }
            $body = str_ends_with(strtolower($originalPath), '.gz') ? gzencode($fixed) : $fixed;
            if ($body === false || File::put($temporary, $body) !== strlen($body)) {
                return null;
            }
            DB::transaction(function () use ($guid, $temporary, $originalPath, $raw): void {
                $release = DB::table('releases')->where('guid', $guid)->lockForUpdate()->first(['id', 'guid']);
                if ($release === null) {
                    throw new \RuntimeException('NZB release disappeared during repair.');
                }
                $receipt = (new NzbSourceGuard($this->nzb))->install($release, $temporary, $originalPath, NzbSourceGuard::digest($raw));
                DB::afterRollBack(static fn () => $receipt->rollback());
                DB::afterCommit(static fn () => $receipt->close());
            });
        } catch (\Throwable $e) {
            if ($this->debugMode) {
                Log::debug('Failed to persist repaired NZB: '.$e->getMessage());
            }

            // Proposed bytes are never evidence when the replacement failed.
            return null;
        } finally {
            if (File::isFile($temporary)) {
                File::delete($temporary);
            }
        }

        return $fixed;
    }

    /** Replace the release's row with the repaired document, only while it still holds $raw. */
    private function storeRepairedRow(string $raw, string $fixed, string $guid): bool
    {
        $body = $guid === '' ? false : gzencode($fixed);
        if ($body === false) {
            return false;
        }
        try {
            DB::transaction(function () use ($guid, $body, $raw): void {
                $release = DB::table('releases')->where('guid', $guid)->lockForUpdate()->first(['id', 'guid']);
                if ($release === null) {
                    throw new \RuntimeException('NZB release disappeared during repair.');
                }
                $receipt = (new NzbSourceGuard($this->nzb))->installStored($release, $body, NzbSourceGuard::digest($raw));
                DB::afterRollBack(static fn () => $receipt->rollback());
                DB::afterCommit(static fn () => $receipt->close());
            });
        } catch (\Throwable $e) {
            if ($this->debugMode) {
                Log::debug('Failed to persist repaired NZB: '.$e->getMessage());
            }

            // Proposed bytes are never evidence when the replacement failed.
            return false;
        }

        return true;
    }

    /**
     * Process NZB contents to extract message IDs for different file types.
     *
     * @param  list<array<string, mixed>>  $nzbContents
     * @return array{
     *     hasCompressedFile: bool,
     *     sampleMessageIDs: list<string>,
     *     jpgMessageIDs: list<string>,
     *     mediaInfoMessageID: string,
     *     audioInfoMessageID: string,
     *     audioInfoExtension: string,
     *     bookFileCount: int
     * }
     */
    public function extractMessageIDs(
        array $nzbContents,
        string $groupName,
        ProcessingConfiguration $config
    ): array {
        $result = [
            'hasCompressedFile' => false,
            'sampleMessageIDs' => [],
            'jpgMessageIDs' => [],
            'mediaInfoMessageID' => '',
            'audioInfoMessageID' => '',
            'audioInfoExtension' => '',
            'bookFileCount' => 0,
        ];

        foreach ($nzbContents as $file) {
            try {
                $title = $file['title'] ?? '';
                $segments = $file['segments'] ?? [];

                // Skip support/nfo files
                if (preg_match('/(?:'.$config->supportFileRegex.'|nfo\\b|inf\\b|ofn\\b)($|[ ")]|-])(?!.{20,})/i', $title)) {
                    continue;
                }

                // Compressed file detection
                if (! $result['hasCompressedFile'] && preg_match(
                    '/(\\.(part\\d+|[rz]\\d+|rar|0+|0*10?|zipr\\d{2,3}|zipx?)("|\\s*\\.rar)*($|[ ")]|-])|"[a-f0-9]{32}\\.[1-9]\\d{1,2}".*\\(\\d+\\/\\d{2,}\\)$)/i',
                    $title
                )) {
                    $result['hasCompressedFile'] = true;
                }

                // Look for a video sample (not an image)
                if ($config->processThumbnails && empty($result['sampleMessageIDs']) && ! empty($segments)
                    && stripos($title, 'sample') !== false
                    && ! preg_match('/\.(?:jpe?g|png|webp)$/i', $title)
                ) {
                    $result['sampleMessageIDs'] = $this->extractSegments($segments, $config->segmentsToDownload);
                }

                // Look for a JPG picture (not a CD cover)
                if ($config->processJPGSample && empty($result['jpgMessageIDs']) && ! empty($segments)
                    && ! preg_match('/flac|lossless|mp3|music|inner-sanctum|sound/i', $groupName)
                    && preg_match('/\.(?:jpe?g|png|webp)[. ")\]]/i', $title)
                ) {
                    $result['jpgMessageIDs'] = $this->extractSegments($segments, $config->segmentsToDownload);
                }

                // Look for a video file for MediaInfo (sample video)
                if ($config->processMediaInfo && empty($result['mediaInfoMessageID']) && ! empty($segments[0])
                    && stripos($title, 'sample') !== false
                    && preg_match('/'.$config->videoFileRegex.'[. ")\]]/i', $title)
                ) {
                    $result['mediaInfoMessageID'] = (string) $segments[0];
                }

                // Look for an audio file
                if ($config->processAudioInfo && empty($result['audioInfoMessageID']) && ! empty($segments)
                    && preg_match('/'.$config->audioFileRegex.'[. ")\]]/i', $title, $type)
                ) {
                    $result['audioInfoExtension'] = $type[1];
                    $result['audioInfoMessageID'] = (string) $segments[0];
                }

                // Count book files
                if (preg_match($config->ignoreBookRegex, $title)) {
                    $result['bookFileCount']++;
                }
            } catch (\ErrorException $e) {
                Log::debug($e->getTraceAsString());
            }
        }

        return $result;
    }

    /**
     * Extract segment message IDs up to a limit.
     *
     * @param  array<int|string, mixed>  $segments
     * @return list<string>
     */
    private function extractSegments(array $segments, int $limit): array
    {
        $ids = [];
        foreach (array_slice(array_values($segments), 0, max(0, $limit)) as $segment) {
            $ids[] = (string) $segment;
        }

        return $ids;
    }

    /**
     * Get the NZB path for a GUID.
     */
    public function getNzbPath(string $guid): string|false
    {
        return $this->nzb->nzbPath($guid);
    }
}
