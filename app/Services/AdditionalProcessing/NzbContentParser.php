<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\Enums\VideoContainer;
use App\Services\Nzb\DatabaseNzbStore;
use App\Services\Nzb\NzbParserService;
use App\Services\Nzb\NzbService;
use App\Services\Nzb\NzbSourceGuard;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Service for parsing NZB file contents and extracting file metadata.
 * Handles NZB repair, file listing, and message ID extraction.
 */
class NzbContentParser
{
    private const string DEFAULT_AUDIO_FILE_REGEX = '\.(AAC|AIFF|APE|AC3|ASF|DTS|FLAC|MKA|MKS|MP2|MP3|RA|OGG|OGM|W64|WAV|WMA)';

    private const string DEFAULT_IGNORE_BOOK_REGEX = '/\b(epub|lit|mobi|pdf|sipdf|html)\b.*\.rar(?!.{20,})/i';

    private const string DEFAULT_SUPPORT_FILE_REGEX = '\.(?:vol\d{1,3}\+\d{1,3}|par2|srs|sfv|nzb)';

    private const string DEFAULT_VIDEO_FILE_REGEX = '\.(AVI|F4V|IFO|M1V|M2V|M4V|MKV|MOV|MP4|MPEG|MPG|MPGV|MPV|OGV|QT|RM|RMVB|TS|VOB|WMV)';

    private const string FFI_CDEF = <<<'C'
        ssize_t nntmux_par2_map_files(const char *sources, size_t sources_len, const char *files, size_t files_len, const char *verified, size_t verified_len, int64_t article_part_size, char *out_buf, size_t out_cap);
        ssize_t nntmux_par2_candidate_indexes(const char *sources, size_t sources_len, int64_t file_size, int64_t article_part_size, char *out_buf, size_t out_cap);
        ssize_t nntmux_nzb_extract_message_ids_nul(const char *group, size_t group_len, const char *titles, size_t titles_len, const char *seg_flags, size_t seg_flags_len, size_t count, uint32_t config_flags, char *out_buf, size_t out_cap);
        ssize_t nntmux_nzb_source_file_inventory(const char *xml, size_t xml_len, size_t expected_file_count, char *out_buf, size_t out_cap);
        C;

    public function __construct(
        private readonly NzbService $nzb,
        private readonly NzbParserService $nzbParser,
        private readonly bool $debugMode = false,
        private readonly bool $echoCLI = false,
        private readonly ?string $libraryPath = null,
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
    public function sourceFileInventory(string $decoded, array $parsedFiles): ?array
    {
        $expectedCount = count($parsedFiles);
        if ($expectedCount === 0 || $expectedCount > 256) {
            return null;
        }
        foreach ($parsedFiles as $file) {
            if (! is_array($file) || (int) ($file['filecount'] ?? 0) !== 1) {
                return null;
            }
        }

        $ffi = RustPipelineLibrary::resolveDomain('additional_nzb_par2', self::FFI_CDEF, $this->libraryPath);
        if ($ffi !== null) {
            $supported = false;
            $rustInventory = $this->sourceFileInventoryRust($ffi, $decoded, $expectedCount, $supported);
            if ($supported) {
                return $rustInventory;
            }
        }

        return $this->sourceFileInventoryPhp($decoded, $parsedFiles);
    }

    /**
     * @param  array<string, mixed>  $parsedFiles
     * @return list<array{name: string, firstMessageId: string}>|null
     */
    public function sourceFileInventoryPhp(string $decoded, array $parsedFiles): ?array
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
     * @param-out bool $supported
     *
     * @return list<array{name: string, firstMessageId: string}>|null
     */
    private function sourceFileInventoryRust(FFI $ffi, string $decoded, int $expectedCount, bool &$supported = false): ?array
    {
        $supported = false;
        $xmlLen = strlen($decoded);
        if ($xmlLen === 0) {
            $supported = true;

            return null;
        }

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(1024, $expectedCount * 128), $cap);
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_nzb_source_file_inventory($decoded, $xmlLen, $expectedCount, $scratch, $cap);
        if (! is_int($written) || $written === -1) {
            return null;
        }
        if ($written === -2) {
            $supported = true;

            return null;
        }
        if ($written < -2) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_nzb_source_file_inventory($decoded, $xmlLen, $expectedCount, $scratch, $cap);
            if (! is_int($written) || $written === -1) {
                return null;
            }
            if ($written === -2) {
                $supported = true;

                return null;
            }
            if ($written <= 0) {
                return null;
            }
        }
        if ($written <= 0) {
            return null;
        }

        $tokens = explode("\0", FFI::string($scratch, $written));
        if (count($tokens) !== $expectedCount * 2) {
            return null;
        }

        $inventory = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i += 2) {
            $inventory[] = ['name' => $tokens[$i], 'firstMessageId' => $tokens[$i + 1]];
        }
        $supported = true;

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
        if (DatabaseNzbStore::enabled()) {
            try {
                DB::transaction(function () use ($guid, $fixed, $raw): void {
                    $release = DB::table('releases')->where('guid', $guid)->lockForUpdate()->first(['id', 'guid']);
                    $body = gzencode($fixed);
                    if ($release === null || $body === false) {
                        throw new \RuntimeException('NZB source is unavailable during repair.');
                    }
                    (new NzbSourceGuard($this->nzb))->installCompressed($release, $body, NzbSourceGuard::digest($raw));
                });

                return $fixed;
            } catch (\Throwable) {
                return null;
            }
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
        if ($this->canUseRustClassifier($config)) {
            $ffi = RustPipelineLibrary::resolveDomain('additional_nzb_par2', self::FFI_CDEF, $this->libraryPath);
            if ($ffi !== null) {
                $rustResult = $this->extractMessageIDsRust($ffi, $nzbContents, $groupName, $config);
                if ($rustResult !== null) {
                    return $rustResult;
                }
            }
        }

        return $this->extractMessageIDsPhp($nzbContents, $groupName, $config);
    }

    /**
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
    public function extractMessageIDsPhp(
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

    private function canUseRustClassifier(ProcessingConfiguration $config): bool
    {
        return $config->audioFileRegex === self::DEFAULT_AUDIO_FILE_REGEX
            && $config->ignoreBookRegex === self::DEFAULT_IGNORE_BOOK_REGEX
            && $config->supportFileRegex === self::DEFAULT_SUPPORT_FILE_REGEX
            && $config->videoFileRegex === self::DEFAULT_VIDEO_FILE_REGEX;
    }

    /**
     * @param  list<array<string, mixed>>  $nzbContents
     * @return array{
     *     hasCompressedFile: bool,
     *     sampleMessageIDs: list<string>,
     *     jpgMessageIDs: list<string>,
     *     mediaInfoMessageID: string,
     *     audioInfoMessageID: string,
     *     audioInfoExtension: string,
     *     bookFileCount: int
     * }|null
     */
    private function extractMessageIDsRust(
        FFI $ffi,
        array $nzbContents,
        string $groupName,
        ProcessingConfiguration $config
    ): ?array {
        if ($config->segmentsToDownload <= 0 || str_contains($groupName, "\0")) {
            return null;
        }
        if ($nzbContents === []) {
            return [
                'hasCompressedFile' => false,
                'sampleMessageIDs' => [],
                'jpgMessageIDs' => [],
                'mediaInfoMessageID' => '',
                'audioInfoMessageID' => '',
                'audioInfoExtension' => '',
                'bookFileCount' => 0,
            ];
        }

        $fileCount = count($nzbContents);
        if (! array_is_list($nzbContents)) {
            return null;
        }
        $titles = array_column($nzbContents, 'title');
        if (count($titles) !== $fileCount) {
            return null;
        }

        $configFlags = ($config->processThumbnails ? 1 : 0)
            | ($config->processJPGSample ? 2 : 0)
            | ($config->processMediaInfo ? 4 : 0)
            | ($config->processAudioInfo ? 8 : 0);

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 64, $cap);
        if ($scratch === null) {
            return null;
        }

        $joinedTitles = implode("\0", $titles);
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_nzb_extract_message_ids_nul(
            $groupName,
            strlen($groupName),
            $joinedTitles,
            strlen($joinedTitles),
            null,
            0,
            $fileCount,
            $configFlags,
            $scratch,
            $cap,
        );
        if (! is_int($written) || $written < 28) {
            return null;
        }

        $raw = FFI::string($scratch, $written);
        /** @var array{has_comp: int, book_cnt: int, sample_idx: int, jpg_idx: int, mi_idx: int, audio_idx: int, ext_len: int}|false $hdr */
        $hdr = unpack('Lhas_comp/Lbook_cnt/lsample_idx/ljpg_idx/lmi_idx/laudio_idx/Lext_len', $raw);
        if ($hdr === false) {
            return null;
        }

        $sampleIdx = $hdr['sample_idx'];
        $jpgIdx = $hdr['jpg_idx'];
        $miIdx = $hdr['mi_idx'];
        $audioIdx = $hdr['audio_idx'];
        $extLen = $hdr['ext_len'];

        foreach ([$sampleIdx, $jpgIdx, $miIdx, $audioIdx] as $idx) {
            if ($idx >= 0) {
                $segs = $nzbContents[$idx]['segments'] ?? null;
                if (! is_array($segs) || $segs === [] || ! isset($segs[0]) || ! is_scalar($segs[0]) || (string) $segs[0] === '') {
                    return null;
                }
            }
        }

        return [
            'hasCompressedFile' => $hdr['has_comp'] !== 0,
            'sampleMessageIDs' => $sampleIdx >= 0
                ? $this->extractSegments($nzbContents[$sampleIdx]['segments'], $config->segmentsToDownload)
                : [],
            'jpgMessageIDs' => $jpgIdx >= 0
                ? $this->extractSegments($nzbContents[$jpgIdx]['segments'], $config->segmentsToDownload)
                : [],
            'mediaInfoMessageID' => $miIdx >= 0
                ? (string) $nzbContents[$miIdx]['segments'][0]
                : '',
            'audioInfoMessageID' => $audioIdx >= 0
                ? (string) $nzbContents[$audioIdx]['segments'][0]
                : '',
            'audioInfoExtension' => $extLen > 0 ? substr($raw, 28, $extLen) : '',
            'bookFileCount' => $hdr['book_cnt'],
        ];
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

    public const bool W416_RUST_FFI_DEFAULT = false;

    private const string W416_FFI_CDEF = 'int32_t nntmux_w416_additional_candidate_diag_orch_console_nzb(uint8_t operation, const char *input, size_t input_len, char *out_buf, size_t out_cap);';

    private static ?bool $w416UseRustFfi = null;

    private static FFI|false|null $w416Ffi = null;

    public static function useW416RustFfi(bool $enable = true): void
    {
        self::$w416UseRustFfi = $enable;
    }

    public static function usesW416RustFfi(): bool
    {
        return self::$w416UseRustFfi ?? self::W416_RUST_FFI_DEFAULT;
    }

    public static function resetW416RustFfi(): void
    {
        self::$w416UseRustFfi = null;
        self::$w416Ffi = null;
    }

    /** @return list<int>|null */
    private static function w416Slots(int $operation, string $input, int $count): ?array
    {
        if (\strlen($input) > 16_777_216) {
            return null;
        }
        try {
            self::$w416Ffi ??= RustPipelineLibrary::resolveDomain('additional', self::W416_FFI_CDEF) ?? false;
            if (self::$w416Ffi === false) {
                return null;
            }
            $bytes = $count * 8;
            $buffer = self::$w416Ffi->new('char['.$bytes.']');
            // @phpstan-ignore method.notFound
            $written = self::$w416Ffi->nntmux_w416_additional_candidate_diag_orch_console_nzb($operation, $input, \strlen($input), $buffer, $bytes);
            if ($written !== $bytes) {
                return null;
            }
            $values = unpack('q*', FFI::string($buffer, $bytes));
            if ($values === false || \count($values) !== $count) {
                return null;
            }
            foreach ($values as $value) {
                if (! \is_int($value) || $value < 0) {
                    return null;
                }
            }

            return array_values($values);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function w416Add(int $left, int $right): int
    {
        return $left > PHP_INT_MAX - $right ? PHP_INT_MAX : $left + $right;
    }

    /**
     * @param  array<array-key, mixed>  $files
     * @return array{total_files: int, empty_files: int, total_segments: int, total_bytes: int, invalid_files: int, exceeds_segment_limit: bool}
     */
    public static function summarizeNzbSegmentBatch(array $files, int $maxSegments = 100000): array
    {
        return self::usesW416RustFfi()
            ? self::summarizeNzbSegmentBatchNative($files, $maxSegments)
            : self::summarizeNzbSegmentBatchPhp($files, $maxSegments);
    }

    /**
     * @param  array<array-key, mixed>  $files
     * @return array{total_files: int, empty_files: int, total_segments: int, total_bytes: int, invalid_files: int, exceeds_segment_limit: bool}
     */
    public static function summarizeNzbSegmentBatchPhp(array $files, int $maxSegments = 100000): array
    {
        $result = ['total_files' => \count($files), 'empty_files' => 0, 'total_segments' => 0, 'total_bytes' => 0, 'invalid_files' => 0, 'exceeds_segment_limit' => false];
        foreach ($files as $row) {
            if (! \is_array($row) || ! \is_int($row['segments'] ?? null) || ! \is_int($row['bytes'] ?? null) || $row['segments'] < 0 || $row['bytes'] < 0) {
                $result['invalid_files']++;

                continue;
            }
            $result['empty_files'] += (int) ($row['segments'] === 0);
            $result['total_segments'] = self::w416Add($result['total_segments'], $row['segments']);
            $result['total_bytes'] = self::w416Add($result['total_bytes'], $row['bytes']);
        }
        $result['exceeds_segment_limit'] = $result['total_segments'] > max(0, $maxSegments);

        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $files
     * @return array{total_files: int, empty_files: int, total_segments: int, total_bytes: int, invalid_files: int, exceeds_segment_limit: bool}
     */
    public static function summarizeNzbSegmentBatchNative(array $files, int $maxSegments = 100000): array
    {
        $total = \count($files);
        if ($total > 100_000) {
            return self::summarizeNzbSegmentBatchPhp($files, $maxSegments);
        }
        $frame = pack('qqV', $maxSegments, 0, $total);
        foreach ($files as $row) {
            if (! \is_array($row) || ! \is_int($row['segments'] ?? null) || ! \is_int($row['bytes'] ?? null) || $row['segments'] < 0 || $row['bytes'] < 0) {
                return self::summarizeNzbSegmentBatchPhp($files, $maxSegments);
            }
            $frame .= pack('qq', $row['segments'], $row['bytes']);
        }
        $slots = self::w416Slots(5, $frame, 6);
        if ($slots === null || $slots[0] !== $total || $slots[4] !== 0 || $slots[5] > 1 || $slots[5] !== (int) ($slots[2] > max(0, $maxSegments)) || $slots[1] > $total) {
            return self::summarizeNzbSegmentBatchPhp($files, $maxSegments);
        }

        return [
            'total_files' => $slots[0],
            'empty_files' => $slots[1],
            'total_segments' => $slots[2],
            'total_bytes' => $slots[3],
            'invalid_files' => $slots[4],
            'exceeds_segment_limit' => $slots[5] === 1,
        ];
    }

    public const string W52_FFI_CDEF = 'int64_t nntmux_w525_nzb_subject_names(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W52.5 parity test's QUALIFICATION). */
    private const bool W52_RUST_FFI_DEFAULT = true;

    /**
     * Wave 52.5 routing and FFI cache, kept in function-local statics so readonly classes can use them too.
     * Without $op it reports the routing; 'set' swaps the override for $val and returns the previous one;
     * 'reset' replaces the cached handle with $val (null re-resolves, false disables); 'buffer' returns
     * `[FFI, int64_t[], capacity]` with room for at least $val words, or null without the library.
     */
    private static function w52RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'reset':
                $ffi = $val;
                $out = null;
                $outCap = 0;

                return null;
            case 'buffer':
                if ($ffi === null) {
                    $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
                    $ffi = RustPipelineLibrary::resolveDomain('w52_5', self::W52_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
                }
                if (! $ffi instanceof FFI || ! \is_int($val)) {
                    return null;
                }
                if ($out === null || $outCap < $val) {
                    $outCap = max(1024, $val);
                    $out = $ffi->new('int64_t['.$outCap.']');
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled ?? self::W52_RUST_FFI_DEFAULT;
        }
    }

    public static function usesW52RustFfi(): bool
    {
        return self::w52RustFfiState() === true;
    }

    /**
     * Runs $callback with subjectNameBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW52RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w52RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w52RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 52.5 Rust helper, or null to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w52Words(int $count, string $data, string $ints, int $words): ?array
    {
        try {
            $handle = self::w52RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w525_nzb_subject_names($count, 0, $data, \strlen($data), $ints, \strlen($ints), $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('P*', FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Batch subject parse for sourceFileInventoryPhp(). Four words per subject: `[status, name offset, name
     * length, declared part total]`. Status 0 means neither subject pattern matched (the other words are
     * 0, 0, -1); 1 a matched name the inventory rejects; 2 an accepted name. The name is the trimmed first
     * capture, as a byte span of the subject; the declared total is `(int)` of the `(n/total)` capture, or -1.
     *
     * @param  list<string>  $subjects
     * @return list<int>
     */
    public static function subjectNameBatch(array $subjects): array
    {
        return self::usesW52RustFfi() ? self::subjectNameBatchNative($subjects) : self::subjectNameBatchPhp($subjects);
    }

    /**
     * @param  list<string>  $subjects
     * @return list<int>
     */
    public static function subjectNameBatchPhp(array $subjects): array
    {
        $out = [];
        foreach ($subjects as $subject) {
            array_push($out, ...self::w52SubjectName($subject));
        }

        return $out;
    }

    /**
     * Rust batch over NUL-delimited subjects. Falls back to PHP when the library is missing or a subject
     * holds a NUL byte, and computes declared totals of more than 18 significant digits with PHP.
     *
     * @param  list<string>  $subjects
     * @return list<int>
     */
    public static function subjectNameBatchNative(array $subjects): array
    {
        $count = \count($subjects);
        if ($count === 0) {
            return [];
        }
        $data = implode("\0", $subjects);
        $words = substr_count($data, "\0") === $count - 1 ? self::w52Words($count, $data, '', 4 * $count) : null;
        if ($words === null) {
            return self::subjectNameBatchPhp($subjects);
        }
        if (\in_array(-2, $words, true)) {
            $list = array_values($subjects);
            foreach (array_keys($words, -2, true) as $key) {
                $words[$key] = self::w52SubjectName($list[intdiv($key, 4)])[3];
            }
        }

        return $words;
    }

    /** @return array{int, int, int, int} */
    private static function w52SubjectName(string $subject): array
    {
        if (preg_match('/^[^"\r\n]*"([^"\r\n]+)"(?:\s+yEnc)?(?:\s+\(\d+\/(\d+)\))?\s*$/iD', $subject, $match, PREG_OFFSET_CAPTURE) !== 1
            && preg_match('/^([^"\r\n]+?)(?:\s+yEnc)?(?:\s+\(\d+\/(\d+)\))?\s*$/iD', $subject, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return [0, 0, 0, -1];
        }
        [$raw, $offset] = $match[1];
        $name = trim($raw);
        $valid = $name !== '' && \strlen($name) <= 4096 && mb_check_encoding($name, 'UTF-8')
            && preg_match('/[\x00-\x1f\x7f]/', $name) !== 1 && ! \in_array($name[0], ['.', '-'], true)
            && basename($name) === $name && ! str_contains($name, '\\');

        return [$valid ? 2 : 1, $offset + strspn($raw, " \t\n\r\0\x0B"), \strlen($name), isset($match[2]) ? (int) $match[2][0] : -1];
    }
}
