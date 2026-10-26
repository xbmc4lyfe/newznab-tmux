<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Services\Releases\ReleaseBrowseService;
use App\Support\RustPipelineLibrary;
use dariusiii\rarinfo\ArchiveInfo;
use dariusiii\rarinfo\Par2Info;
use dariusiii\rarinfo\RarInfo;
use dariusiii\rarinfo\SzipInfo;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use Symfony\Component\Process\Process as SymfonyProcess;
use Throwable;

/**
 * Service for extracting and processing archive files (RAR, ZIP, 7z).
 * Handles password detection, file listing, and content extraction.
 * 7z archives are listed only; there is no extractor for them.
 */
class ArchiveExtractionService
{
    /** Largest 7z volume the start header may describe before it is treated as corrupt. */
    public const SEVENZIP_MAX_ARCHIVE_BYTES = 1 << 40;

    /** Stop reading a `7z l` listing after this many bytes or file entries. */
    public const SEVENZIP_LISTING_MAX_BYTES = 4 << 20;

    public const SEVENZIP_LISTING_MAX_ENTRIES = 1000;

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_archive_7z_validate_end_header(const char *head, size_t head_len, const char *tail, size_t tail_len, char *out_buf, size_t out_cap);
        int32_t nntmux_archive_is_nfo_file(const char *filename, size_t filename_len);
        ssize_t nntmux_archive_rank_nfo_files_nul(const char *names, size_t names_len, size_t count, char *out_buf, size_t out_cap);
        int32_t nntmux_archive_detect_standalone_video(const char *data, size_t data_len);
        C;

    public const string W406_FFI_CDEF = 'ssize_t nntmux_w406_archive_media_download_processor_planner(uint8_t operation, const char *input, size_t input_len, char *out_buf, size_t out_cap);';

    /** Wave 40.6 paired benchmarks decide this default; see RustArchiveExtractionMediaUsenetDownloadReleaseProcW40ParityTest. */
    public const bool W406_RUST_FFI_DEFAULT = false;

    private const int W406_RUST_MAX_INPUT = 16_777_216;

    /** Extensions of directly executable programs and scripts, compared ASCII case-insensitively. */
    private const array W406_EXECUTABLE_EXTENSIONS = ['bat' => true, 'cmd' => true, 'com' => true, 'cpl' => true, 'exe' => true, 'jar' => true,
        'js' => true, 'msi' => true, 'ps1' => true, 'scr' => true, 'sh' => true, 'vbs' => true];

    private static ?bool $w406UseRustFfi = null;

    private static FFI|false|null $w406Ffi = null;

    private const array STANDALONE_VIDEO_CODES = [
        1 => 'avi',
        2 => 'mkv',
        3 => 'mpg',
        4 => 'ts',
        5 => 'mp4',
        6 => 'wmv',
        7 => 'flv',
        8 => 'ogv',
    ];

    private ArchiveInfo $archiveInfo;

    private Par2Info $par2Info;

    public function __construct(
        private readonly ProcessingConfiguration $config,
        ?ArchiveInfo $archiveInfo = null,
        ?Par2Info $par2Info = null,
        private readonly ?string $libraryPath = null,
    ) {
        $this->archiveInfo = $archiveInfo ?? new ArchiveInfo;
        $this->par2Info = $par2Info ?? new Par2Info;

        // Configure external clients for ArchiveInfo
        if ($this->config->unrarPath) {
            $this->archiveInfo->setExternalClients([ArchiveInfo::TYPE_RAR => $this->config->unrarPath]);
        }
    }

    /**
     * Process compressed data and extract file information.
     *
     * @return array<string, mixed>
     */
    public function processCompressedData(
        string $compressedData,
        ReleaseProcessingContext $context,
        string $tmpPath
    ): array {
        $result = $this->inspectCompressedData($compressedData, $context, $tmpPath);

        if (! $result['success'] && ! $result['hasPassword']) {
            if ($result['undecodable'] ?? false) {
                $context->archiveListingUndecodable = true;
            } else {
                $context->archiveRetryable = true;
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function inspectCompressedData(
        string $compressedData,
        ReleaseProcessingContext $context,
        string $tmpPath
    ): array {
        $result = [
            'success' => false,
            'files' => [],
            'hasPassword' => false,
            'passwordStatus' => ReleaseBrowseService::PASSWD_NONE,
            'listingOnly' => false,
        ];

        $context->compressedFilesChecked++;

        // Plain media is not an archive, even if ArchiveInfo finds a marker inside it.
        $videoType = self::hasArchiveSignature($compressedData) ? null : $this->detectStandaloneVideo($compressedData);
        if ($videoType !== null) {
            return [
                'success' => false,
                'files' => [],
                'hasPassword' => false,
                'passwordStatus' => ReleaseBrowseService::PASSWD_NONE,
                'standaloneVideoType' => $videoType,
                'standaloneVideoData' => $compressedData,
            ];
        }

        // Try ArchiveInfo for RAR/ZIP/7z
        if (! $this->archiveInfo->setData($compressedData, true)) {
            return $result;
        }

        if ($this->archiveInfo->error !== '') {
            if ($this->config->debugMode) {
                Log::debug('ArchiveInfo Error: '.$this->archiveInfo->error);
            }

            return $result;
        }

        $listingOnly = $this->archiveInfo->type === ArchiveInfo::TYPE_SZIP;
        $result['listingOnly'] = $listingOnly;

        try {
            $dataSummary = $this->archiveInfo->getSummary(! $listingOnly);
        } catch (\Exception $e) {
            if ($this->config->debugMode) {
                Log::warning($e->getTraceAsString());
            }

            return $result;
        }

        // Check for encryption
        if (! empty($this->archiveInfo->isEncrypted)
            || (isset($dataSummary['is_encrypted']) && (int) $dataSummary['is_encrypted'] !== 0)
        ) {
            if ($this->config->debugMode) {
                Log::debug('ArchiveInfo: Compressed file has a password.');
            }

            return [
                ...$result,
                'hasPassword' => true,
                'passwordStatus' => ReleaseBrowseService::PASSWD_RAR,
            ];
        }

        // Prepare extraction directories
        if (! $listingOnly) {
            $this->prepareExtractionDirectories($tmpPath);
        }

        // Process based on archive type
        $archiveMarker = $this->extractArchive($compressedData, $dataSummary, $tmpPath);

        // Get file list
        $files = $listingOnly
            ? $this->archiveInfo->getArchiveFileList(false)
            : $this->archiveInfo->getArchiveFileList();
        if (! is_array($files) || count($files) === 0) {
            // A compressed 7z header needs an LZMA decoder, which isn't available.
            if ($listingOnly && (int) ($dataSummary['enc_header'] ?? 0) === 1) {
                return [...$result, 'undecodable' => true];
            }

            return $result;
        }

        return [
            'success' => true,
            'files' => $files,
            'hasPassword' => false,
            'passwordStatus' => ReleaseBrowseService::PASSWD_NONE,
            'archiveMarker' => $archiveMarker,
            'dataSummary' => $dataSummary,
            'listingOnly' => $listingOnly,
            'rarListingComplete' => $this->archiveInfo->type === ArchiveInfo::TYPE_RAR
                ? $this->hasTerminalRarBlock($compressedData)
                : null,
        ];
    }

    private function hasTerminalRarBlock(string $data): bool
    {
        $reader = $this->archiveInfo->getReader();
        if (! $reader instanceof RarInfo) {
            return false;
        }
        $blocks = $reader->getBlocks(false);
        if (! is_array($blocks) || $blocks === []) {
            return false;
        }
        $last = end($blocks);

        return in_array($last['head_type'] ?? null, [RarInfo::BLOCK_ENDARC, RarInfo::R50_BLOCK_ENDARC], true)
            && ($last['more_volumes'] ?? true) === false
            && ($last['next_offset'] ?? null) === strlen($data);
    }

    /**
     * Check if a file is an NFO or info file.
     *
     * @param  string  $filename  The filename to check.
     * @return bool True if it's an NFO-like file.
     */
    public function isNfoFile(string $filename): bool
    {
        $basename = strtolower(basename($filename));

        // Standard NFO extensions
        if (preg_match('/\.(nfo|diz|inf)$/i', $basename)) {
            return true;
        }

        // Common NFO alternative names
        $nfoNames = [
            'file_id.diz', 'fileid.diz', 'file-id.diz',
            'readme.txt', 'readme.1st', 'read.me', 'readmenow.txt',
            'info.txt', 'information.txt', 'about.txt', 'notes.txt',
            'release.txt', 'release.nfo',
        ];

        if (in_array($basename, $nfoNames, true)) {
            return true;
        }

        // Scene-style NFO naming: 00-groupname.nfo, group-release.nfo
        if (preg_match('/^(?:00?-[a-z0-9_-]+|[a-z0-9]+-[a-z0-9._-]+)\.(?:nfo|txt)$/i', $basename)) {
            return true;
        }

        return false;
    }

    /**
     * `isNfoFile()` backed by the Rust pipeline library; falls back to `isNfoFile()`
     * (and leaves `$supported` false) when the library is unavailable.
     */
    public function isNfoFileNative(string $filename, bool &$supported = false): bool
    {
        $supported = false;
        $ffi = RustPipelineLibrary::resolveDomain('archive_extraction', self::FFI_CDEF, $this->libraryPath);
        if ($ffi === null) {
            return $this->isNfoFile($filename);
        }

        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_archive_is_nfo_file($filename, strlen($filename));
        } catch (Throwable) {
            return $this->isNfoFile($filename);
        }

        if (! is_int($status) || $status < 0) {
            return $this->isNfoFile($filename);
        }

        $supported = true;

        return $status === 1;
    }

    /**
     * Sort files to prioritize NFO files for processing.
     *
     * @param  list<array<string, mixed>>  $files  Array of file info arrays.
     * @return list<array<string, mixed>> Sorted array with NFO files first.
     */
    public function sortFilesWithNfoPriority(array $files): array
    {
        if (static::class !== self::class) {
            return $this->sortFilesWithNfoPriorityPhp($files);
        }

        return $this->sortFilesWithNfoPriorityNative($files);
    }

    /**
     * Sort archive file records with NFO priority in a single Rust FFI crossing, falling back to PHP.
     *
     * @param  list<array<string, mixed>>  $files
     * @return list<array<string, mixed>>
     */
    public function sortFilesWithNfoPriorityNative(array $files, bool &$supported = false): array
    {
        $supported = false;
        $count = count($files);
        $ffi = RustPipelineLibrary::resolveDomain('archive_extraction', self::FFI_CDEF, $this->libraryPath);
        if ($ffi === null) {
            return $this->sortFilesWithNfoPriorityPhp($files);
        }
        if ($count <= 1) {
            $supported = true;

            return array_values($files);
        }

        $values = array_values($files);
        $names = [];
        foreach ($values as $file) {
            $names[] = is_string($file['name'] ?? null) ? $file['name'] : '';
        }

        $joined = implode("\0", $names);
        if (substr_count($joined, "\0") !== $count - 1) {
            return $this->sortFilesWithNfoPriorityPhp($files);
        }

        $needed = $count * 4 + 16;
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, $needed, $cap);
        if ($scratch === null) {
            return $this->sortFilesWithNfoPriorityPhp($files);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_archive_rank_nfo_files_nul(
                $joined,
                strlen($joined),
                $count,
                $scratch,
                $cap,
            );
            if (is_int($written) && $written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return $this->sortFilesWithNfoPriorityPhp($files);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_archive_rank_nfo_files_nul(
                    $joined,
                    strlen($joined),
                    $count,
                    $scratch,
                    $cap,
                );
            }
        } catch (Throwable) {
            return $this->sortFilesWithNfoPriorityPhp($files);
        }

        if (! is_int($written) || $written < 0) {
            return $this->sortFilesWithNfoPriorityPhp($files);
        }
        if ($written === 0) {
            $supported = true;

            return $values;
        }
        if ($written !== $count * 4) {
            return $this->sortFilesWithNfoPriorityPhp($files);
        }

        /** @var array<int, int>|false $order */
        $order = unpack('V*', FFI::string($scratch, $written));
        if ($order === false) {
            return $this->sortFilesWithNfoPriorityPhp($files);
        }

        $supported = true;
        $sorted = [];
        foreach ($order as $idx) {
            $sorted[] = $values[$idx];
        }

        return $sorted;
    }

    /**
     * Pure PHP reference implementation of NFO-priority sorting.
     *
     * @param  list<array<string, mixed>>  $files
     * @return list<array<string, mixed>>
     */
    public function sortFilesWithNfoPriorityPhp(array $files): array
    {
        usort($files, function ($a, $b) {
            $aIsNfo = $this->isNfoFile($a['name'] ?? '');
            $bIsNfo = $this->isNfoFile($b['name'] ?? '');

            if ($aIsNfo && ! $bIsNfo) {
                return -1;
            }
            if (! $aIsNfo && $bIsNfo) {
                return 1;
            }

            return 0;
        });

        return $files;
    }

    /**
     * Prepare extraction directories.
     *
     * @return list<mixed>
     */
    private function prepareExtractionDirectories(string $tmpPath): void
    {
        if ($this->config->extractUsingRarInfo) {
            return;
        }

        try {
            if ($this->config->unrarPath) {
                $unrarDir = $tmpPath.'unrar/';
                if (! File::isDirectory($unrarDir)) {
                    File::makeDirectory($unrarDir, 0777, true, true);
                }
            }
            $unzipDir = $tmpPath.'unzip/';
            if (! File::isDirectory($unzipDir)) {
                File::makeDirectory($unzipDir, 0777, true, true);
            }
        } catch (Throwable $e) {
            if ($this->config->debugMode) {
                Log::warning('Failed ensuring extraction subdirectories: '.$e->getMessage());
            }
        }
    }

    /**
     * Extract archive based on type.
     *
     * @param  array<string, mixed>  $dataSummary
     */
    private function extractArchive(string $compressedData, array $dataSummary, string $tmpPath): string
    {
        switch ($dataSummary['main_type']) {
            case ArchiveInfo::TYPE_RAR:
                if (! $this->config->extractUsingRarInfo && $this->config->unrarPath) {
                    $fileName = $tmpPath.uniqid('', true).'.rar';
                    File::put($fileName, $compressedData);
                    try {
                        $this->runExternalCommand($this->config->unrarPath, [
                            'e', '-ai', '-ep', '-c-', '-id', '-inul', '-kb', '-or', '-p-', '-r', '-y', '-@', '--',
                            $fileName,
                            $tmpPath.'unrar/',
                        ]);
                    } finally {
                        File::delete($fileName);
                    }
                }

                return 'r';

            case ArchiveInfo::TYPE_ZIP:
                if (! $this->config->extractUsingRarInfo && $this->config->unzipPath) {
                    $fileName = $tmpPath.uniqid('', true).'.zip';
                    File::put($fileName, $compressedData);
                    try {
                        $this->runExternalCommand($this->config->unzipPath, [
                            '-o',
                            '-d',
                            $tmpPath.'unzip/',
                            '--',
                            $fileName,
                        ]);
                    } finally {
                        File::delete($fileName);
                    }
                }

                return 'z';

            case ArchiveInfo::TYPE_SZIP:
                return '7';
        }

        return '';
    }

    /**
     * True when the data starts with a RAR, ZIP or 7z signature.
     */
    public static function hasArchiveSignature(string $data): bool
    {
        foreach (["Rar!\x1A\x07\x00", "Rar!\x1A\x07\x01\x00", "PK\x03\x04", SzipInfo::MARKER_SIGNATURE] as $signature) {
            if (str_starts_with($data, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when the data starts a 7z archive whose end header (file list) lies beyond it.
     */
    public function needsSevenZipEndHeader(string $data): bool
    {
        $start = $this->sevenZipStartHeader($data);

        return $start !== null && 32 + $start['next_offset'] + $start['next_size'] > strlen($data);
    }

    /**
     * Rebuild a listable 7z from its start header and the archive's last bytes.
     *
     * The start header gives the size and CRC of the end header, which 7-Zip writes
     * last, so it can be cut from the tail and checked. Packed data is left out.
     */
    public function withSevenZipEndHeader(string $head, string $tail): ?string
    {
        return $this->withSevenZipEndHeaderNative($head, $tail);
    }

    /**
     * Rebuild a listable 7z via the Rust CRC32/header accelerator, falling back to PHP.
     */
    public function withSevenZipEndHeaderNative(string $head, string $tail, bool &$supported = false): ?string
    {
        $supported = false;
        $ffi = RustPipelineLibrary::resolveDomain('archive_extraction', self::FFI_CDEF, $this->libraryPath);
        if ($ffi === null) {
            return $this->withSevenZipEndHeaderPhp($head, $tail);
        }

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 256, $cap);
        if ($scratch === null) {
            return $this->withSevenZipEndHeaderPhp($head, $tail);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_archive_7z_validate_end_header(
                $head,
                strlen($head),
                $tail,
                strlen($tail),
                $scratch,
                $cap,
            );
            if (is_int($written) && $written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return $this->withSevenZipEndHeaderPhp($head, $tail);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_archive_7z_validate_end_header(
                    $head,
                    strlen($head),
                    $tail,
                    strlen($tail),
                    $scratch,
                    $cap,
                );
            }
        } catch (Throwable) {
            return $this->withSevenZipEndHeaderPhp($head, $tail);
        }

        if (! is_int($written) || $written < 0) {
            return $this->withSevenZipEndHeaderPhp($head, $tail);
        }

        $supported = true;

        return $written === 0 ? null : FFI::string($scratch, $written);
    }

    /**
     * Pure PHP reference implementation of 7z end-header validation and reconstruction.
     */
    public function withSevenZipEndHeaderPhp(string $head, string $tail): ?string
    {
        $start = $this->sevenZipStartHeader($head);
        if ($start === null || $start['next_size'] === 0 || $start['next_size'] > strlen($tail)) {
            return null;
        }

        $endHeader = substr($tail, -$start['next_size']);
        if (crc32($endHeader) !== $start['next_crc']) {
            return null;
        }

        $startHeader = pack('PPV', 0, $start['next_size'], $start['next_crc']);

        return substr($head, 0, 8).pack('V', crc32($startHeader)).$startHeader.$endHeader;
    }

    /**
     * List a 7z whose end header is compressed, which rarinfo can't decode, with the 7-Zip client.
     *
     * The client gets a sparse copy of the archive holding only the downloaded first and
     * last bytes at their real offsets; that is enough when the packed header stream lies
     * inside them, as 7-Zip writes it just before the end header. Without a tail the whole
     * archive, end header included, must be in the head.
     *
     * The listing is read as it streams and the client is stopped after
     * SEVENZIP_LISTING_MAX_ENTRIES entries or SEVENZIP_LISTING_MAX_BYTES of output; the
     * entries read until then are kept, as only a handful are stored per release anyway,
     * and the context is marked so the password status of the release stays unknown. The
     * same happens when a record can't be read unambiguously and is left out.
     *
     * @return array<string, mixed>|null result like processCompressedData(), or null to fall back
     */
    public function listEncodedSevenZip(
        string $head,
        ?string $tail,
        ReleaseProcessingContext $context,
        string $tmpPath,
    ): ?array {
        $client = $this->config->sevenZipPath;
        if (! is_string($client) || ! is_executable($client)) {
            return null;
        }

        $start = $this->sevenZipStartHeader($head);
        $length = $start === null ? null : $this->sevenZipArchiveLength($start, $context);
        if ($length === null) {
            return null;
        }
        if ($tail === null) {
            if ($length > strlen($head)) {
                return null;
            }
            $tail = substr($head, 0, $length);
        }

        $joined = $this->withSevenZipEndHeader($head, $tail);
        if ($joined === null) {
            return null;
        }

        $szip = new SzipInfo;
        $szip->setData($joined);
        $encoded = null;
        foreach ($szip->getHeaders() ?: [] as $header) {
            if ($header['type'] === SzipInfo::PROPERTY_ENCODED_HEADER) {
                $encoded = $header;
            }
        }
        if ($encoded === null || $szip->isEncrypted || ! isset($encoded['pack_offset'], $encoded['pack_sizes'])) {
            return null;
        }

        $tailStart = $length - strlen($tail);
        $packStart = 32 + (int) $encoded['pack_offset'];
        $packEnd = $packStart + (int) array_sum($encoded['pack_sizes']);
        if ($tailStart < 0 || $packStart < 32 || $packEnd > $length - $start['next_size']
            || ($packStart < $tailStart && $packEnd > strlen($head))
        ) {
            return null;
        }

        $file = $tmpPath.uniqid('7z_', true).'.7z';
        $process = null;
        try {
            $handle = @fopen($file, 'wb');
            if ($handle === false) {
                return null;
            }
            $written = fwrite($handle, $head) === strlen($head)
                && fseek($handle, $tailStart) === 0
                && fwrite($handle, $tail) === strlen($tail);
            fclose($handle);
            if (! $written) {
                return null;
            }

            // A dummy password makes an encrypted archive fail instead of prompting.
            $process = new SymfonyProcess([$client, 'l', '-slt', '-ba', '-sccUTF-8', '-pnntmux', '-t7z', '--', $file]);
            $process->setTimeout(60)->start();
            [$files, $complete] = $this->readSevenZipListing($process);
        } catch (Throwable) {
            return null;
        } finally {
            if ($process?->isRunning()) {
                $process->stop(0);
            }
            @unlink($file);
        }

        // Marked even when no record survived, as a rejected record may be the encrypted one.
        if (! $complete) {
            $context->archiveListingIncomplete = true;
        }
        if ($files === []) {
            return null;
        }

        $context->compressedFilesChecked++;

        return [
            'success' => true,
            'files' => $files,
            'hasPassword' => false,
            'passwordStatus' => ReleaseBrowseService::PASSWD_NONE,
            'archiveMarker' => '7',
            'dataSummary' => ['main_type' => ArchiveInfo::TYPE_SZIP],
            'listingOnly' => true,
        ];
    }

    /**
     * Turn `7z l -slt` output into rarinfo-style file records, leaving out directories.
     * Records that can't be read unambiguously are left out and clear $reliable; see
     * SevenZipListingParser.
     *
     * @return list<array{name: string, size: int, date: int, pass: int, compressed: int, crc32?: string}>
     */
    public static function parseSevenZipListing(string $output, ?bool &$reliable = null): array
    {
        $parser = new SevenZipListingParser;
        $parser->feed($output);
        $parser->finish();
        $reliable = $parser->reliable;

        return $parser->files;
    }

    /**
     * Parse a running `7z l -slt` listing as it streams, stopping the client at the caps.
     * Also says whether the listing was read to the end with every record understood.
     *
     * @return array{list<array{name: string, size: int, date: int, pass: int, compressed: int, crc32?: string}>, bool}
     */
    private function readSevenZipListing(SymfonyProcess $process): array
    {
        $parser = new SevenZipListingParser;
        $bytes = 0;
        foreach ($process->getIterator(SymfonyProcess::ITER_SKIP_ERR) as $chunk) {
            // Parse no more than the cap, however much output a single read returned.
            $cut = $bytes + strlen($chunk) > self::SEVENZIP_LISTING_MAX_BYTES;
            if ($cut) {
                $chunk = substr($chunk, 0, self::SEVENZIP_LISTING_MAX_BYTES - $bytes);
            }
            $bytes += strlen($chunk);
            $parser->feed($chunk);
            if (count($parser->files) >= self::SEVENZIP_LISTING_MAX_ENTRIES) {
                $process->stop(0);

                return [array_slice($parser->files, 0, self::SEVENZIP_LISTING_MAX_ENTRIES), false];
            }
            if ($cut) {
                // The record being read when the output was cut is dropped, never half-parsed.
                $process->stop(0);

                return [$parser->files, false];
            }
        }

        if ($process->wait() !== 0) {
            return [[], true];
        }
        $parser->finish();

        return [$parser->files, $parser->reliable];
    }

    /**
     * Length of the archive the start header describes, or null when it can't belong to this
     * release: larger than the release itself (when known) or SEVENZIP_MAX_ARCHIVE_BYTES.
     *
     * @param  array{next_offset: int, next_size: int, next_crc: int}  $start
     */
    private function sevenZipArchiveLength(array $start, ReleaseProcessingContext $context): ?int
    {
        $length = 32 + $start['next_offset'] + $start['next_size'];
        $releaseSize = (int) ($context->release->size ?? 0);

        return $releaseSize > 0 && $length > $releaseSize ? null : $length;
    }

    /**
     * @return array{next_offset: int, next_size: int, next_crc: int}|null
     */
    private function sevenZipStartHeader(string $data): ?array
    {
        if (strlen($data) < 32 || ! str_starts_with($data, SzipInfo::MARKER_SIGNATURE)) {
            return null;
        }

        /** @var array{next_offset: int, next_size: int, next_crc: int} $start */
        $start = unpack('Pnext_offset/Pnext_size/Vnext_crc', $data, 12);

        // Unsigned values past PHP_INT_MAX unpack as negative; anything beyond the cap is corrupt
        // and must not reach the offset arithmetic or the sparse copy.
        $max = self::SEVENZIP_MAX_ARCHIVE_BYTES - 32;

        return $start['next_offset'] >= 0 && $start['next_size'] >= 0
            && $start['next_offset'] <= $max && $start['next_size'] <= $max - $start['next_offset']
            ? $start : null;
    }

    /**
     * Detect a plain media container (not an archive) from its leading bytes.
     *
     * Stays on native PHP in production because the 4-byte signature checks cost ~100 ns,
     * so a single-buffer FFI crossing (~80 ns) measures below the 1.5x adoption threshold.
     */
    public function detectStandaloneVideo(string $data): ?string
    {
        if (strlen($data) < 16) {
            return null;
        }

        $sig4 = substr($data, 0, 4);

        return match (true) {
            $sig4 === 'RIFF' && substr($data, 8, 4) === 'AVI ' => 'avi',
            $sig4 === "\x1A\x45\xDF\xA3" => 'mkv',
            $sig4 === "\x00\x00\x01\xBA", $sig4 === "\x00\x00\x01\xB3" => 'mpg',
            $data[0] === "\x47" && ($data[188] ?? '') === "\x47" && ($data[376] ?? '') === "\x47" => 'ts',
            substr($data, 4, 4) === 'ftyp' && preg_match('/^[\x20-\x7E]{4}$/', substr($data, 8, 4)) === 1 => 'mp4',
            str_starts_with($data, "\x30\x26\xB2\x75\x8E\x66\xCF\x11") => 'wmv',
            str_starts_with($data, "FLV\x01") => 'flv',
            $sig4 === 'OggS' => 'ogv',
            default => null,
        };
    }

    /**
     * `detectStandaloneVideo()` backed by the Rust pipeline library; falls back to
     * `detectStandaloneVideo()` (and leaves `$supported` false) when unavailable.
     */
    public function detectStandaloneVideoNative(string $data, bool &$supported = false): ?string
    {
        $supported = false;
        $ffi = RustPipelineLibrary::resolveDomain('archive_extraction', self::FFI_CDEF, $this->libraryPath);
        if ($ffi === null) {
            return $this->detectStandaloneVideo($data);
        }

        try {
            // @phpstan-ignore method.notFound
            $code = $ffi->nntmux_archive_detect_standalone_video($data, strlen($data));
        } catch (Throwable) {
            return $this->detectStandaloneVideo($data);
        }

        if (! is_int($code) || $code < 0) {
            return $this->detectStandaloneVideo($data);
        }

        $supported = true;

        return self::STANDALONE_VIDEO_CODES[$code] ?? null;
    }

    /**
     * Get PAR2 info parser.
     */
    public function getPar2Info(): Par2Info
    {
        return $this->par2Info;
    }

    /**
     * Get archive info handler.
     */
    public function getArchiveInfo(): ArchiveInfo
    {
        return $this->archiveInfo;
    }

    /**
     * Extract a specific file from archive data by filename.
     *
     * @param  string  $compressedData  The raw archive data
     * @param  string  $filename  The filename to extract (exact match)
     * @param  string  $tmpPath  Temporary directory path
     * @return string|null The extracted file content, or null if extraction failed
     */
    public function extractSpecificFile(string $compressedData, string $filename, string $tmpPath): ?string
    {
        return $this->extractSpecificFiles($compressedData, [$filename], $tmpPath)[$filename] ?? null;
    }

    /**
     * Inspect one archive and extract several exact filenames from it.
     *
     * @param  list<string>  $filenames
     * @return array<string, string>
     */
    public function extractSpecificFiles(string $compressedData, array $filenames, string $tmpPath): array
    {
        if (str_starts_with($compressedData, SzipInfo::MARKER_SIGNATURE)) {
            return [];
        }

        $filenames = array_values(array_unique(array_filter(
            $filenames,
            static fn (string $filename): bool => $filename !== '',
        )));
        $extractedFiles = [];
        $remaining = $filenames;

        if ($this->archiveInfo->setData($compressedData, true)) {
            if ($this->archiveInfo->type === ArchiveInfo::TYPE_SZIP) {
                return [];
            }

            $entries = self::completeStoredEntries($this->archiveInfo->getArchiveFileList());

            foreach ($filenames as $filename) {
                if (! isset($entries[$filename])) {
                    continue;
                }

                try {
                    $extracted = $this->archiveInfo->getFileData($filename);
                    if (is_string($extracted) && strlen($extracted) === $entries[$filename]) {
                        $extractedFiles[$filename] = $extracted;
                        $remaining = array_values(array_diff($remaining, [$filename]));
                    }
                } catch (Throwable $e) {
                    if ($this->config->debugMode) {
                        Log::debug('ArchiveInfo getFileData failed: '.$e->getMessage());
                    }
                }
            }
        }

        foreach ($remaining as $filename) {
            $extracted = $this->extractSpecificFileWithExternalTools($compressedData, $filename, $tmpPath);
            if ($extracted !== null) {
                $extractedFiles[$filename] = $extracted;
            }
        }

        return $extractedFiles;
    }

    /**
     * Entries whose bytes can be copied straight out of the downloaded data:
     * stored, not encrypted, and wholly inside it.
     *
     * @param  array<int|string, mixed>  $files
     * @return array<string, int> name => size
     */
    public static function completeStoredEntries(array $files): array
    {
        $entries = [];
        foreach ($files as $file) {
            if (! is_array($file) || ! isset($file['name'], $file['size'], $file['range'])
                || ($file['source'] ?? 'main') !== 'main'
                || ! empty($file['pass'])
                || ! empty($file['is_dir'])
                || ($file['compressed'] ?? null) !== 0
            ) {
                continue;
            }

            $size = (int) $file['size'];
            if ($size <= 0) {
                continue;
            }

            $range = (string) $file['range'];
            $dash = strpos($range, '-');
            $start = $dash === 0 ? 0 : (int) $range;
            $end = $dash === false ? -1 : (int) substr($range, $dash + 1);
            if ($end - $start + 1 === $size) {
                $entries[(string) $file['name']] = $size;
            }
        }

        return $entries;
    }

    /**
     * Original array_map/explode implementation of `completeStoredEntries()` retained for parity checks.
     *
     * @param  array<int|string, mixed>  $files
     * @return array<string, int> name => size
     */
    public static function completeStoredEntriesLegacy(array $files): array
    {
        $entries = [];
        foreach ($files as $file) {
            if (! is_array($file) || ! isset($file['name'], $file['size'], $file['range'])
                || ($file['source'] ?? 'main') !== 'main'
                || ! empty($file['pass'])
                || ! empty($file['is_dir'])
                || ($file['compressed'] ?? null) !== 0
            ) {
                continue;
            }

            [$start, $end] = array_map(intval(...), explode('-', (string) $file['range'], 2) + [1 => -1]);
            if ((int) $file['size'] > 0 && $end - $start + 1 === (int) $file['size']) {
                $entries[(string) $file['name']] = (int) $file['size'];
            }
        }

        return $entries;
    }

    private function extractSpecificFileWithExternalTools(
        string $compressedData,
        string $filename,
        string $tmpPath,
    ): ?string {
        if ($this->config->unrarPath) {
            $extracted = $this->extractFileViaExternalTool(
                $compressedData,
                $filename,
                $tmpPath,
                'rar',
                fn (string $archiveFile, string $extractDir): array => [
                    'e', '-y', '-c-', '-inul', '-p-', '-@', '--',
                    $archiveFile,
                    $filename,
                    $extractDir,
                ],
            );
            if ($extracted !== null) {
                return $extracted;
            }
        }

        if ($this->config->unzipPath) {
            $extracted = $this->extractFileViaExternalTool(
                $compressedData,
                $filename,
                $tmpPath,
                'zip',
                fn (string $archiveFile, string $extractDir): array => [
                    '-j',
                    '-d',
                    $extractDir,
                    '--',
                    $archiveFile,
                    $filename,
                ],
            );
            if ($extracted !== null) {
                return $extracted;
            }
        }

        return null;
    }

    /**
     * Extract a specific file using unrar.
     *
     * @param  callable(string, string): list<string>  $commandBuilder
     */
    private function extractFileViaExternalTool(
        string $compressedData,
        string $filename,
        string $tmpPath,
        string $archiveExtension,
        callable $commandBuilder
    ): ?string {
        $extractDir = null;
        $archiveFile = null;

        try {
            $binary = $archiveExtension === 'rar' ? $this->config->unrarPath : $this->config->unzipPath;
            if (! is_string($binary)) {
                return null;
            }

            $extractDir = $tmpPath.'extract_'.uniqid('', true).'/';
            if (! File::isDirectory($extractDir)) {
                File::makeDirectory($extractDir, 0777, true, true);
            }

            $archiveFile = $tmpPath.'archive_'.uniqid('', true).'.'.$archiveExtension;
            File::put($archiveFile, $compressedData);

            $this->runExternalCommand($binary, $commandBuilder($archiveFile, $extractDir));

            // Look for extracted file
            $extractedPath = $extractDir.basename($filename);
            if (File::isFile($extractedPath)) {
                return File::get($extractedPath);
            }

            // Try to find it with glob
            $files = File::allFiles($extractDir);
            foreach ($files as $file) {
                if (strtolower($file->getFilename()) === strtolower(basename($filename))) {
                    return File::get($file->getPathname());
                }
            }
        } catch (Throwable $e) {
            if ($this->config->debugMode) {
                Log::debug('External extraction failed: '.$e->getMessage());
            }
        } finally {
            if (is_string($archiveFile)) {
                File::delete($archiveFile);
            }
            if (is_string($extractDir) && File::isDirectory($extractDir)) {
                File::deleteDirectory($extractDir);
            }
        }

        return null;
    }

    /**
     * Run an external extractor without invoking a command shell.
     *
     * @param  list<string>  $arguments
     */
    private function runExternalCommand(string $binary, array $arguments): void
    {
        $command = [$binary, ...$arguments];

        if ($this->config->timeoutPath && $this->config->timeoutSeconds > 0) {
            $command = [
                $this->config->timeoutPath,
                '--foreground',
                '--signal=KILL',
                (string) $this->config->timeoutSeconds,
                ...$command,
            ];
        }

        Process::timeout(1800)->run($command);
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
        self::$w406Ffi = null;
    }

    /**
     * Audit extracted archive member paths. A member is rejected when it is not a string, is empty, starts with
     * '/' or '\\', contains a NUL byte, or has a '..' segment ('/' and '\\' both separate segments); the rest are
     * safe relative paths. Every string member whose final extension is an executable or script type counts as
     * executable, rejected or not. The listing exceeds the limit when it holds more than max(0, $maxEntries) members.
     *
     * @param  array<array-key, mixed>  $relativePaths
     * @return array{total_entries: int, safe_relative: int, traversal_rejected: int, executable: int, exceeds_limit: bool}
     */
    public static function summarizeExtractedEntriesSafety(array $relativePaths, int $maxEntries = 500): array
    {
        return (self::usesW406RustFfi() ? self::summarizeExtractedEntriesSafetyNative($relativePaths, $maxEntries) : null)
            ?? self::summarizeExtractedEntriesSafetyPhp($relativePaths, $maxEntries);
    }

    /**
     * @param  array<array-key, mixed>  $relativePaths
     * @return array{total_entries: int, safe_relative: int, traversal_rejected: int, executable: int, exceeds_limit: bool}
     */
    public static function summarizeExtractedEntriesSafetyPhp(array $relativePaths, int $maxEntries = 500): array
    {
        $rejected = 0;
        $executable = 0;
        foreach ($relativePaths as $path) {
            if (! \is_string($path)) {
                $rejected++;

                continue;
            }
            $normalized = strtr($path, '\\', '/');
            if ($path === '' || $normalized[0] === '/' || str_contains($path, "\0") || \in_array('..', explode('/', $normalized), true)) {
                $rejected++;
            }
            $slash = strrpos($normalized, '/');
            $name = $slash === false ? $normalized : substr($normalized, $slash + 1);
            $dot = strrpos($name, '.');
            if ($dot !== false && isset(self::W406_EXECUTABLE_EXTENSIONS[strtolower(substr($name, $dot + 1))])) {
                $executable++;
            }
        }
        $total = \count($relativePaths);

        return [
            'total_entries' => $total,
            'safe_relative' => $total - $rejected,
            'traversal_rejected' => $rejected,
            'executable' => $executable,
            'exceeds_limit' => $total > max(0, $maxEntries),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $relativePaths
     * @return array{total_entries: int, safe_relative: int, traversal_rejected: int, executable: int, exceeds_limit: bool}|null
     */
    public static function summarizeExtractedEntriesSafetyNative(array $relativePaths, int $maxEntries = 500): ?array
    {
        $total = \count($relativePaths);
        $frame = pack('qV', $maxEntries, $total);
        foreach ($relativePaths as $path) {
            $frame .= self::w406OptionalString($path);
        }
        $slots = self::w406Slots(1, $frame, 5);
        if ($slots === null || $slots[0] !== $total || $slots[1] < 0 || $slots[2] < 0 || $total !== $slots[1] + $slots[2]
            || $slots[3] < 0 || $slots[3] > $total || ($slots[4] & ~1) !== 0) {
            return null;
        }

        return [
            'total_entries' => $total,
            'safe_relative' => $slots[1],
            'traversal_rejected' => $slots[2],
            'executable' => $slots[3],
            'exceeds_limit' => $slots[4] === 1,
        ];
    }

    /**
     * Frames a value as a u32-LE length-prefixed string, or as the 0xFFFFFFFF marker when it is not a string.
     *
     * @internal
     */
    public static function w406OptionalString(mixed $value): string
    {
        return \is_string($value) ? pack('V', \strlen($value)).$value : pack('V', 0xFFFFFFFF);
    }

    /**
     * Calls the Wave 40.6 export shared by the additional-processing helpers and decodes its fixed i64 slots.
     * Every output fits the initial scratch buffer, so any negative or unexpected size falls back to PHP.
     *
     * @internal
     *
     * @return list<int>|null
     */
    public static function w406Slots(int $operation, string $input, int $slots): ?array
    {
        if (\strlen($input) > self::W406_RUST_MAX_INPUT) {
            return null;
        }
        try {
            self::$w406Ffi ??= RustPipelineLibrary::resolveDomain('additional_w406', self::W406_FFI_CDEF) ?? false;
            if (self::$w406Ffi === false) {
                return null;
            }
            $ffi = self::$w406Ffi;
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $slots * 8, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w406_archive_media_download_processor_planner($operation, $input, \strlen($input), $buffer, min($capacity, self::W406_RUST_MAX_INPUT));
            if ($written !== $slots * 8) {
                return null;
            }
            $values = unpack('q*', FFI::string($buffer, $written));

            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    // ---------------------------------------------------------------------
    // Wave 52.4 batch helper
    // ---------------------------------------------------------------------

    /** Enabled: minimum of three paired runs cleared every 1.5x gate (common mean 3.48x, p99 2.58x; uncommon mean 4.22x, p99 2.91x); see RustWorkPlannerArchiveConfigConsoleMediaExtractW52ParityTest. */
    private const bool W52_RUST_FFI_DEFAULT = true;

    public const string W52_FFI_CDEF = <<<'C'
        ssize_t nntmux_w524_extracted_entry_batch(const char *text, size_t text_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** extractedEntryBatch() flags. */
    public const int W52_ENTRY_EMPTY = 1;

    public const int W52_ENTRY_ABSOLUTE = 2;

    public const int W52_ENTRY_TRAVERSAL = 4;

    public const int W52_ENTRY_NUL = 8;

    public const int W52_ENTRY_EXECUTABLE = 16;

    public const int W52_ENTRY_ARCHIVE = 32;

    public const int W52_ENTRY_NFO = 64;

    private const array W52_ARCHIVE_EXTENSIONS = ['rar' => true, 'zip' => true, '7z' => true];

    private const array W52_NFO_EXTENSIONS = ['nfo' => true, 'diz' => true, 'inf' => true];

    /**
     * Audit extracted archive member paths, two ints each: [flags, base name offset << 32 |
     * length, the text after the last '/' or '\\']. Flags: 1 empty, 2 absolute (leading '/' or
     * '\\'), 4 '..' segment, 8 NUL byte, 16 executable or script extension, 32 nested archive
     * (rar, zip, 7z, rNN or NNN), 64 nfo/diz/inf. Empty, absolute, traversal and NUL members
     * are the ones summarizeExtractedEntriesSafety() rejects.
     *
     * @param  list<string>  $paths
     * @return list<int>
     */
    public static function extractedEntryBatch(array $paths): array
    {
        return self::usesW52RustFfi()
            ? self::extractedEntryBatchNative($paths)
            : self::extractedEntryBatchPhp($paths);
    }

    /**
     * @param  list<string>  $paths
     * @return list<int>
     */
    public static function extractedEntryBatchNative(array $paths): array
    {
        self::w52AssertList($paths);
        if ($paths === []) {
            return [];
        }

        [$text, $twin] = self::w52Text($paths);

        return self::w52Call($text, $twin, \count($paths))
            ?? self::extractedEntryBatchPhp($paths);
    }

    /**
     * @param  list<string>  $paths
     * @return list<int>
     */
    public static function extractedEntryBatchPhp(array $paths): array
    {
        self::w52AssertList($paths);
        $out = [];
        foreach ($paths as $path) {
            if ($path === '') {
                $out[] = self::W52_ENTRY_EMPTY;
                $out[] = 0;

                continue;
            }
            $normalized = strtr($path, '\\', '/');
            $flags = $normalized[0] === '/' ? self::W52_ENTRY_ABSOLUTE : 0;
            if (\in_array('..', explode('/', $normalized), true)) {
                $flags |= self::W52_ENTRY_TRAVERSAL;
            }
            if (str_contains($path, "\0")) {
                $flags |= self::W52_ENTRY_NUL;
            }
            $slash = strrpos($normalized, '/');
            $at = $slash === false ? 0 : $slash + 1;
            $dot = strrpos($normalized, '.', $at);
            $extension = $dot === false ? '' : strtolower(substr($normalized, $dot + 1));
            if (isset(self::W406_EXECUTABLE_EXTENSIONS[$extension])) {
                $flags |= self::W52_ENTRY_EXECUTABLE;
            }
            if (isset(self::W52_ARCHIVE_EXTENSIONS[$extension]) || preg_match('/^(?:r\d\d|\d{3})\z/', $extension) === 1) {
                $flags |= self::W52_ENTRY_ARCHIVE;
            }
            if (isset(self::W52_NFO_EXTENSIONS[$extension])) {
                $flags |= self::W52_ENTRY_NFO;
            }
            $out[] = $flags;
            $out[] = ($at << 32) | (\strlen($path) - $at);
        }

        return $out;
    }

    public static function usesW52RustFfi(): bool
    {
        $enabled = self::w52RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W52_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W52 batch dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW52RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w52RustFfiState('enabled');
        self::w52RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w52RustFfiState('enabled', $previous);
        }
    }

    /**
     * One FFI crossing for the batch: encoded text and packed int64 columns in, 2 int64
     * words back per record. Null when the library is missing or declines.
     *
     * @return list<int>|null
     */
    private static function w52Call(string $text, string $ints, int $count): ?array
    {
        try {
            $state = self::w52RustFfiState();
            $ffi = $state['ffi'] ?? null;
            if ($ffi === null) {
                $ffi = RustPipelineLibrary::resolveDomain('metadata_w52', self::W52_FFI_CDEF) ?? false;
                self::w52RustFfiState('ffi', $ffi);
            }
            if (! $ffi instanceof FFI) {
                return null;
            }
            $need = $count * 2;
            $out = $state['out'] ?? null;
            $cap = $state['cap'] ?? 0;
            if (! $out instanceof CData || ! \is_int($cap) || $cap < $need) {
                $cap = max(1024, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w52RustFfiState('out', $out);
                self::w52RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W52_FFI_CDEF.)
            $written = $ffi->nntmux_w524_extracted_entry_batch($text, \strlen($text), $ints, \strlen($ints), $count, $out, $cap);
            if ($written !== $need) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $need * 8));

            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Text column encoding: NUL-joined values and, when a value holds a NUL byte, the same
     * values joined with 0x01 so Rust finds the separators where the two buffers differ.
     *
     * @param  list<string>  $values
     * @return array{0: string, 1: string}
     */
    private static function w52Text(array $values): array
    {
        $joined = implode("\0", $values);

        return [$joined, substr_count($joined, "\0") === \count($values) - 1 ? '' : implode("\x01", $values)];
    }

    /** @param array<array-key, mixed> $values */
    private static function w52AssertList(array $values): void
    {
        if (! array_is_list($values)) {
            throw new InvalidArgumentException('W52 batch input must be a list.');
        }
    }

    /**
     * W52 routing override and cached binding. Readonly classes cannot declare static
     * properties, so they live in a function static: no arguments returns the state, one
     * argument reads a key ('reset' clears everything) and two arguments write a key.
     */
    private static function w52RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;
        }

        return $state[$op] ?? null;
    }

    // ========================================================================
    // WAVE 64.1 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 6.50x, p99 6.39x; uncommon mean 6.46x, p99 5.39x); see RustAdditionalQueryDiagnosticsOrchestratorPlannerExtractionW64ParityTest. */
    public const bool W64_RUST_FFI_DEFAULT = true;

    public const string W64_ARCHIVE_EXTRACTION_FFI_CDEF = <<<'C'
        ssize_t nntmux_w641_archive_extraction_plan_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of w64ArchiveExtractionPlanBatch(). */
    public const int W64_ARCHIVE_EXTRACTION_TRAILER = 44;

    public static function usesW64RustFfi(): bool
    {
        $override = self::w64RustFfiState();

        return \is_bool($override) ? $override : self::W64_RUST_FFI_DEFAULT;
    }

    /** Forces the ArchiveExtractionService Wave 64 Rust path on until reset. */
    public static function enableW64RustFfi(): void
    {
        self::w64RustFfiState('set', true);
    }

    /** Forces the ArchiveExtractionService Wave 64 PHP path until reset. */
    public static function disableW64RustFfi(): void
    {
        self::w64RustFfiState('set', false);
    }

    /** Restores the benchmark default and drops the cached library handle and scratch buffers; earlier toggles are untouched. */
    public static function resetW64RustFfi(): void
    {
        self::w64RustFfiState('reset');
    }

    /**
     * Runs $callback with the ArchiveExtractionService Wave 64 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW64RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w64RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w64RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 64.1 extraction plans, one per archive listing entry `type|encrypted|path|size|packed|tools` (trimmed fields;
     * numbers are 1-15 digits, empty 0). Type (lowercased): 0 empty, 1 rar, 2 zip, 3 7z, 7 other. Encrypted: 0 none, 1
     * encrypted entry, 2 encoded header. Tools bitmask (0-15): 1 unrar, 2 unzip, 4 7z, 8 extract using rarinfo. Tool,
     * mirroring extractArchive(): 1 unrar, 2 unzip, 3 7z listing, 4 rarinfo, 5 internal 7z listing, 0 none. Password
     * status: 0 none, 1 passworded, 2 undecodable header, 3 header listed by 7z. Decision: 0 empty row, 1 malformed
     * (bad number, encrypted over 2, tools over 15 or an empty path), 11 unknown type, 2 passworded, 9 undecodable, 3
     * unsafe path (absolute, `..` segment or NUL byte), 4 directory, 10 over the 1 TiB archive cap, 5 executable, 6
     * nested archive, 7 NFO, 8 extract. Flags: 1 absolute, 2 traversal, 4 NUL, 8 non-ASCII, 16 other control byte, 32
     * over 100x the packed size, 64 path repeated in the batch (case-insensitive), 128 backslash separators, 256 stored,
     * 512 hidden basename. Per item `decision | type << 4 | encrypted << 7 | tool << 9 | password << 12 | flags << 14 |
     * min(depth, 63) << 24 | bitLength(size) << 30 | bitLength(packed) << 36 | min(pathLength, 1023) << 42 |
     * (fnv1a32(lowercase path) & 0x3FF) << 52`; then 12 decision, 8 type, 6 tool, 4 password and 10 flag counts, the
     * byte sum of extracted, nested and NFO entries, the packed sum, the deepest path and the distinct paths.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w64ArchiveExtractionPlanBatch(array $items): array
    {
        return self::usesW64RustFfi() ? self::w64ArchiveExtractionPlanBatchNative($items) : self::w64ArchiveExtractionPlanBatchPhp($items);
    }

    /**
     * Rust plan; falls back to the PHP reference when the library is missing or the call declines.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w64ArchiveExtractionPlanBatchNative(array $items): array
    {
        return self::w64Invoke($items, \count($items) + self::W64_ARCHIVE_EXTRACTION_TRAILER) ?? self::w64ArchiveExtractionPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w64ArchiveExtractionPlanBatchPhp(array $items): array
    {
        $out = [];
        $tail = array_fill(0, self::W64_ARCHIVE_EXTRACTION_TRAILER, 0);
        $paths = [];
        foreach ($items as $item) {
            $item = (string) $item;
            [$rawType, $rawEncrypted, $rawPath, $rawSize, $rawPacked, $rawTools] = explode('|', $item, 6) + ['', '', '', '', '', ''];
            $type = self::W64_ARCHIVE_TYPES[strtolower(trim($rawType))] ?? 7;
            $encrypted = self::w64Uint($rawEncrypted, 0);
            $path = trim($rawPath);
            $pathLength = \strlen($path);
            $size = self::w64Uint($rawSize, 0);
            $packed = self::w64Uint($rawPacked, 0);
            $tools = self::w64Uint($rawTools, 0);
            $malformed = $encrypted < 0 || $encrypted > 2 || $size < 0 || $packed < 0 || $tools < 0 || $tools > 15 || $path === '';
            $encryption = $encrypted >= 0 && $encrypted <= 2 ? $encrypted : 0;
            $available = $tools >= 0 && $tools <= 15 ? $tools : 0;
            $tool = match ($type) {
                1 => ($available & 8) === 0 && ($available & 1) !== 0 ? 1 : 4,
                2 => ($available & 8) === 0 && ($available & 2) !== 0 ? 2 : 4,
                3 => $encryption === 2 && ($available & 4) !== 0 ? 3 : 5,
                default => 0,
            };
            $password = match ($encryption) {
                1 => 1,
                2 => ($available & 4) !== 0 && $type === 3 ? 3 : 2,
                default => 0,
            };
            $normalized = str_replace('\\', '/', $path);
            $absolute = $path !== '' && ($path[0] === '/' || $path[0] === '\\' || ($pathLength >= 2 && $path[1] === ':' && strspn($path, self::W64_LETTERS, 0, 1) === 1));
            $depth = 0;
            $traversal = false;
            foreach (explode('/', $normalized) as $segment) {
                if ($segment !== '') {
                    $depth++;
                }
                if ($segment === '..') {
                    $traversal = true;
                }
            }
            $slash = strrpos($normalized, '/');
            $base = $slash === false ? $normalized : substr($normalized, $slash + 1);
            [, $extension] = self::w64SplitExtension(strtolower($base));
            $nul = str_contains($path, "\0");
            $decision = match (true) {
                trim($item) === '' => 0,
                $malformed => 1,
                $type === 7 => 11,
                $encryption === 1 => 2,
                $password === 2 => 9,
                $absolute || $traversal || $nul => 3,
                str_ends_with($normalized, '/') => 4,
                $size > 1 << 40 => 10,
                isset(self::W406_EXECUTABLE_EXTENSIONS[$extension]) => 5,
                $extension === 'rar' || $extension === 'zip' || $extension === '7z'
                    || (\strlen($extension) === 3 && $extension[0] === 'r' && strspn($extension, self::W64_DIGITS, 1) === 2) => 6,
                isset(self::W64_NFO_EXTENSIONS[$extension]) => 7,
                default => 8,
            };
            $flags = ($absolute ? 1 : 0) | ($traversal ? 2 : 0) | ($nul ? 4 : 0) | (preg_match('/[\x80-\xff]/', $path) === 1 ? 8 : 0)
                | (strcspn($path, self::W64_CONTROL_BYTES_NO_NUL) !== $pathLength ? 16 : 0) | ($packed > 0 && $size > $packed * 100 ? 32 : 0)
                | (str_contains($path, '\\') ? 128 : 0) | ($size > 0 && $size === $packed ? 256 : 0) | (str_starts_with($base, '.') ? 512 : 0);
            $lower = strtolower($path);
            if ($lower !== '') {
                if (isset($paths[$lower])) {
                    $flags |= 64;
                } else {
                    $paths[$lower] = true;
                }
            }
            $tail[$decision]++;
            $tail[12 + $type]++;
            $tail[20 + $tool]++;
            $tail[26 + $password]++;
            for ($b = 0; $b < 10; $b++) {
                $tail[30 + $b] += $flags >> $b & 1;
            }
            if ($decision >= 6 && $decision <= 8) {
                $tail[40] += max(0, min($size, 1 << 40));
            }
            $tail[41] += max(0, min($packed, 1 << 40));
            $tail[42] = max($tail[42], $depth);
            $out[] = $decision | $type << 4 | $encryption << 7 | $tool << 9 | $password << 12 | $flags << 14 | min($depth, 63) << 24
                | self::w64BitLength($size) << 30 | self::w64BitLength($packed) << 36 | min($pathLength, 1023) << 42
                | ((int) hexdec(hash('fnv1a32', $lower)) & 0x3FF) << 52;
        }
        $tail[43] = \count($paths);
        array_push($out, ...$tail);

        return $out;
    }

    /** @var array<string, int> */
    private const array W64_ARCHIVE_TYPES = ['' => 0, 'rar' => 1, 'zip' => 2, '7z' => 3];

    /** @var array<string, true> */
    private const array W64_NFO_EXTENSIONS = ['nfo' => true, 'diz' => true, 'inf' => true];

    private const string W64_CONTROL_BYTES_NO_NUL = "\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F";

    private const string W64_LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    private const string W64_DIGITS = '0123456789';

    /** A trimmed field of 1-15 digits; empty is $empty, anything else -1. */
    private static function w64Uint(string $raw, int $empty): int
    {
        $value = trim($raw);
        if ($value === '') {
            return $empty;
        }
        $length = \strlen($value);

        return $length <= 15 && strspn($value, self::W64_DIGITS) === $length ? (int) $value : -1;
    }

    private static function w64BitLength(int $value): int
    {
        $bits = 0;
        for (; $value > 0; $value >>= 1) {
            $bits++;
        }

        return $bits;
    }

    /**
     * Splits a lowercased name at its last `.` when the extension is 1-5 `[a-z0-9]` bytes.
     *
     * @return array{0: string, 1: string}
     */
    private static function w64SplitExtension(string $name): array
    {
        $dot = strrpos($name, '.');
        if ($dot === false) {
            return [$name, ''];
        }
        $extension = substr($name, $dot + 1);
        $length = \strlen($extension);
        if ($length === 0 || $length > 5 || strspn($extension, 'abcdefghijklmnopqrstuvwxyz0123456789') !== $length) {
            return [$name, ''];
        }

        return [substr($name, 0, $dot), $extension];
    }

    /**
     * Wave 64 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'reset' clears the override, library handle and buffers, 'ffi'
     * resolves the library (null when unavailable) and 'out' and 'lens' return `int64_t` output and
     * length-column buffers of at least $val words.
     */
    private static function w64RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'reset') {
            $override = $ffi = $out = $lens = null;
            $outCap = $lensCap = 0;

            return null;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('archive_extraction_w641', self::W64_ARCHIVE_EXTRACTION_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w64RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w64RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 64.1 ArchiveExtractionService export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w64Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w64RustFfiState('ffi');
            $out = self::w64RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w64RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W64_ARCHIVE_EXTRACTION_FFI_CDEF.)
            $written = $ffi->nntmux_w641_archive_extraction_plan_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
