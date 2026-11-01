<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Release;
use App\Models\ReleaseFile;
use App\Models\ReleaseNfo;
use App\Models\UsenetGroup;
use App\Services\Configuration\ConfigurationProvider;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbContentsService;
use App\Services\Nzb\NzbParserService;
use App\Services\Nzb\NzbService;
use dariusiii\rarinfo\Par2Info;
use dariusiii\rarinfo\SfvInfo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Class NfoService - Handles NFO file processing, validation, and metadata extraction.
 *
 * NFO files are text files commonly used in the warez scene to provide information
 * about releases. This class handles detection, validation, parsing and storage of NFO content.
 */
class NfoService
{
    /**
     * Regex to detect common non-NFO file headers/signatures.
     * Matches XML, NZB, RIFF (media), PAR/RAR archives, and other binary formats.
     */
    protected string $_nonNfoHeaderRegex = '/\A(\s*<\?xml|=newz\[NZB\]=|RIFF|\s*[RP]AR|.{0,10}(JFIF|matroska|ftyp|ID3)|PK\x03\x04|\x1f\x8b\x08|MZ|%PDF|GIF8[79]a|\x89PNG)|;\s*Generated\s*by.*SF\w/i';

    /**
     * Regex to identify text encoding from the 'file' command output.
     */
    protected string $_textFileRegex = '/(ASCII|ISO-8859|UTF-(8|16|32).*?|Non-ISO extended-ASCII)\s*text/i';

    /**
     * Regex to identify common binary file types from the 'file' command output.
     */
    protected string $_binaryFileRegex = '/^(JPE?G|Parity|PNG|RAR|XML|(7-)?[Zz]ip|PDF|GIF|executable|archive|compressed|data|binary)/i';

    /**
     * Regex to detect binary characters within the content.
     * Excludes common control characters that may appear in NFOs (tab, newline, carriage return).
     */
    protected string $_binaryCharsRegex = '/[\x00-\x08\x0B\x0C\x0E-\x1F]/';

    /**
     * Common NFO keywords that help identify legitimate NFO files.
     *
     * @var array<string, mixed>
     */
    protected array $_nfoKeywords = [ // @phpstan-ignore property.defaultValue
        // Release information
        'release', 'group', 'date', 'size', 'format', 'source', 'genre', 'codec',
        'bitrate', 'resolution', 'language', 'subtitle', 'ripped', 'cracked',
        'keygen', 'serial', 'patch', 'trainer', 'install', 'notes', 'greets',
        'nfo', 'ascii', 'artwork', 'presents', 'proudly', 'brings', 'another',
        // Scene terminology
        'scene', 'rls', 'nuked', 'proper', 'repack', 'internal', 'retail',
        'webdl', 'webrip', 'bluray', 'bdrip', 'dvdrip', 'hdtv', 'pdtv',
        // Media info
        'video', 'audio', 'duration', 'runtime', 'aspect', 'fps', 'channels',
        'sample', 'encoder', 'x264', 'x265', 'hevc', 'avc', 'xvid', 'divx',
        'aac', 'ac3', 'dts', 'truehd', 'atmos', 'flac', 'mp3',
        // Content info
        'movie', 'film', 'episode', 'season', 'series', 'title', 'year',
        'director', 'cast', 'actors', 'plot', 'synopsis', 'imdb', 'rating',
        // Software
        'crack', 'readme', 'setup', 'installer', 'license', 'registration',
        'protection', 'requirements', 'platform', 'operating', 'system',
        // Contact/Group info
        'contact', 'irc', 'www', 'http', 'ftp', 'email', 'apply', 'join',
    ];

    /**
     * Maximum NFO file size in bytes (64KB).
     */
    protected const MAX_NFO_SIZE = 65535;

    /**
     * Minimum NFO file size in bytes.
     */
    protected const MIN_NFO_SIZE = 12;

    /**
     * Cache TTL for settings in seconds.
     */
    protected const SETTINGS_CACHE_TTL = 300;

    /**
     * Lazily loaded from settings + cache when NFO processing runs (see getters).
     */
    private ?int $nzbs = null;

    /**
     * Lazily loaded from settings + cache when NFO processing runs.
     */
    protected ?int $maxSize = null;

    /**
     * Lazily loaded from settings + cache when NFO processing runs.
     */
    private ?int $maxRetries = null;

    /**
     * Lazily loaded from settings + cache when NFO processing runs.
     */
    protected ?int $minSize = null;

    /**
     * @var string Temporary path for processing files.
     */
    private string $tmpPath;

    /**
     * @var bool Whether to echo output to CLI.
     */
    protected bool $echo;

    private string|false $unrarPath;

    private string|false $timeoutPath;

    private ?int $timeoutSeconds = null;

    public const NFO_FAILED = -9; // We failed to get a NFO after admin set max retries.

    public const NFO_FAILED_ARCHIVE = -10; // Archive-based NFO extraction also failed; no more retries.

    public const NFO_UNPROC = -1; // Release has not been processed yet.

    public const NFO_NONFO = 0; // Release has no NFO.

    public const NFO_FOUND = 1; // Release has an NFO.

    /**
     * Default constructor.
     *
     * Heavy settings (batch limits, sizes, retries) load on first NFO processing use via getters,
     * so constructing this service does not hit the cache or database.
     */
    public function __construct()
    {
        $this->echo = (bool) config('nntmux.echocli');

        $this->tmpPath = rtrim((string) config('nntmux.tmp_unrar_path'), '/\\').'/';
        $this->unrarPath = config('nntmux_settings.unrar_path') ?: false;
        $this->timeoutPath = config('nntmux_settings.timeout_path') ?: false;
    }

    private function getTimeoutSeconds(): int
    {
        return $this->timeoutSeconds ??= (int) (app(ConfigurationProvider::class)->postProcessing()->timeoutSeconds ?: 60);
    }

    private function getNzbs(): int
    {
        if ($this->nzbs === null) {
            $this->nzbs = (int) $this->rememberNfoSetting('nfo_maxnfoprocessed', function () {
                $value = app(ConfigurationProvider::class)->postProcessing()->maxNfoProcessed;

                return $value !== '' ? (int) $value : 100;
            });
        }

        return $this->nzbs;
    }

    private function getMaxRetries(): int
    {
        if ($this->maxRetries === null) {
            $maxRetries = (int) $this->rememberNfoSetting('nfo_maxnforetries', function () {
                return (int) app(ConfigurationProvider::class)->postProcessing()->maxNfoRetries;
            });
            $computed = $maxRetries >= 0 ? -($maxRetries + 1) : self::NFO_UNPROC;
            $this->maxRetries = max($computed, -8);
        }

        return $this->maxRetries;
    }

    private function getMaxSize(): int
    {
        if ($this->maxSize === null) {
            $this->maxSize = (int) $this->rememberNfoSetting('nfo_maxsizetoprocessnfo', function () {
                return (int) app(ConfigurationProvider::class)->postProcessing()->maxSizeToProcessNfo;
            });
        }

        return $this->maxSize;
    }

    private function getMinSize(): int
    {
        if ($this->minSize === null) {
            $this->minSize = (int) $this->rememberNfoSetting('nfo_minsizetoprocessnfo', function () {
                return (int) app(ConfigurationProvider::class)->postProcessing()->minSizeToProcessNfo;
            });
        }

        return $this->minSize;
    }

    /**
     * Read a setting through the cache when available; fall back to the callback if the
     * cache store is unreachable (e.g. Redis down while CACHE_STORE=redis).
     *
     * @param  callable(): mixed  $callback
     */
    private function rememberNfoSetting(string $key, callable $callback): mixed
    {
        try {
            return Cache::remember($key, self::SETTINGS_CACHE_TTL, $callback);
        } catch (Throwable $e) {
            if (config('app.debug')) {
                Log::debug('NfoService cache bypassed: '.$e->getMessage());
            }

            return $callback();
        }
    }

    /**
     * Confirm this is an NFO file.
     *
     * Uses multiple validation strategies:
     * 1. Size validation (too large/small = not NFO)
     * 2. Binary header detection (known file signatures)
     * 3. File type detection via 'file' command
     * 4. PAR2/SFV structure detection
     * 5. Binary character content analysis
     * 6. NFO keyword/content heuristics
     *
     * @param  bool|string  $possibleNFO  The nfo content.
     * @param  string  $guid  The guid of the release.
     * @return bool True if it's likely an NFO, False otherwise.
     */
    public function isNFO(bool|string &$possibleNFO, string $guid): bool
    {
        if ($possibleNFO === false || $possibleNFO === '') {
            return false;
        }

        $size = \strlen($possibleNFO);

        // Basic size and signature checks using constants
        if ($size >= self::MAX_NFO_SIZE || $size < self::MIN_NFO_SIZE) {
            return false;
        }

        // Quick check for known non-NFO file signatures
        if (preg_match($this->_nonNfoHeaderRegex, $possibleNFO)) {
            return false;
        }

        // Additional binary format checks
        if ($this->detectBinaryFormat($possibleNFO)) {
            return false;
        }

        $tmpPath = $this->tmpPath.$guid.'.nfo';
        $isNfo = false;

        try {
            // File/GetId3 work with files, so save to disk.
            File::put($tmpPath, $possibleNFO);

            // Use 'file' command via fileInfo if available
            $result = fileInfo($tmpPath);
            if (! empty($result)) {
                if (preg_match($this->_textFileRegex, $result)) {
                    $isNfo = true;
                } elseif (preg_match($this->_binaryFileRegex, $result) || preg_match($this->_binaryCharsRegex, $possibleNFO)) {
                    $isNfo = false;
                }

                // If fileInfo gave a result, apply additional heuristics before returning
                if ($isNfo) {
                    // Additional content validation for text files
                    $isNfo = $this->validateNfoContent($possibleNFO);
                }

                return $isNfo;
            }

            // Fallback checks if 'file' command is unavailable or inconclusive
            // Check if it's a PAR2 file
            $par2info = new Par2Info;
            $par2info->setData($possibleNFO);
            if (! $par2info->error) {
                return false;
            }

            // Check if it's an SFV file
            $sfv = new SfvInfo;
            $sfv->setData($possibleNFO);
            if (! $sfv->error) {
                return false;
            }

            // Check for binary characters
            if (preg_match($this->_binaryCharsRegex, $possibleNFO)) {
                return false;
            }

            // Final content-based validation
            $isNfo = $this->validateNfoContent($possibleNFO);

        } catch (Throwable $e) {
            Log::error("Error processing potential NFO for GUID {$guid}: ".$e->getMessage());
            $isNfo = false;
        } finally {
            // Ensure temporary file is always deleted
            if (File::exists($tmpPath)) {
                try {
                    File::delete($tmpPath);
                } catch (Throwable $e) {
                    Log::error("Error deleting temporary NFO file {$tmpPath}: ".$e->getMessage());
                }
            }
        }

        return $isNfo;
    }

    /**
     * Detect binary file formats by magic bytes.
     *
     * @param  string  $data  The file content to check.
     * @return bool True if binary format detected.
     */
    protected function detectBinaryFormat(string $data): bool
    {
        if (strlen($data) < 4) {
            return false;
        }

        // Magic bytes for common binary formats
        $magicBytes = [
            "\x50\x4B\x03\x04" => 'ZIP',         // ZIP/DOCX/XLSX etc.
            "\x50\x4B\x05\x06" => 'ZIP_EMPTY',   // Empty ZIP
            "\x52\x61\x72\x21" => 'RAR',         // RAR
            "\x37\x7A\xBC\xAF" => '7Z',          // 7-Zip
            "\x1F\x8B\x08" => 'GZIP',            // GZip
            "\x42\x5A\x68" => 'BZIP2',           // BZip2
            "\xFD\x37\x7A\x58" => 'XZ',          // XZ
            "\x89\x50\x4E\x47" => 'PNG',         // PNG
            "\xFF\xD8\xFF" => 'JPEG',            // JPEG
            "\x47\x49\x46\x38" => 'GIF',         // GIF
            "\x25\x50\x44\x46" => 'PDF',         // PDF
            "\x49\x44\x33" => 'MP3_ID3',         // MP3 with ID3
            "\xFF\xFB" => 'MP3',                 // MP3
            "\x4F\x67\x67\x53" => 'OGG',         // OGG
            "\x66\x4C\x61\x43" => 'FLAC',        // FLAC
            "\x52\x49\x46\x46" => 'RIFF',        // WAV/AVI
            "\x00\x00\x01\xBA" => 'MPEG',        // MPEG video
            "\x00\x00\x01\xB3" => 'MPEG',        // MPEG video
            "\x1A\x45\xDF\xA3" => 'MKV',         // Matroska/WebM
            "\x4D\x5A" => 'EXE',                 // Windows EXE
            "\x7F\x45\x4C\x46" => 'ELF',         // Linux executable
            "\xCA\xFE\xBA\xBE" => 'JAVA',        // Java class
            "\xD0\xCF\x11\xE0" => 'OLE',         // MS Office old format
        ];

        foreach ($magicBytes as $magic => $type) {
            if (str_starts_with($data, $magic)) {
                return true;
            }
        }

        // Check for UTF-16 BOM (could be text, but unlikely NFO)
        if (str_starts_with($data, "\xFF\xFE") || str_starts_with($data, "\xFE\xFF")) {
            // UTF-16 - could be valid, let other checks handle it
            return false;
        }

        return false;
    }

    /**
     * Validate NFO content using heuristics.
     *
     * @param  string  $content  The content to validate.
     * @return bool True if content appears to be a valid NFO.
     */
    protected function validateNfoContent(string $content): bool
    {
        $length = strlen($content);

        // Too short to be meaningful
        if ($length < 50) {
            return false;
        }

        // Count printable ASCII characters
        $printableCount = preg_match_all('/[\x20-\x7E]/', $content);
        $printableRatio = $printableCount / $length;

        // NFOs should be mostly printable characters
        if ($printableRatio < 0.7) {
            return false;
        }

        // Check for minimum text content (words, not just symbols)
        $wordCount = preg_match_all('/[A-Za-z]{2,}/', $content);
        if ($wordCount < 5) {
            return false;
        }

        // Check for NFO-like content patterns
        $nfoIndicators = 0;

        // Look for common NFO keywords
        foreach ($this->_nfoKeywords as $keyword) {
            if (stripos($content, $keyword) !== false) {
                $nfoIndicators++;
                if ($nfoIndicators >= 3) {
                    return true; // High confidence if multiple keywords found
                }
            }
        }

        // Check for scene-style formatting
        if (preg_match('/[-=*]{5,}/', $content)) {
            $nfoIndicators++;
        }

        // Check for URL presence (common in NFOs)
        if (preg_match('/https?:\/\/|www\./i', $content)) {
            $nfoIndicators++;
        }

        // Check for media IDs
        if (preg_match('/imdb\.com|thetvdb\.com|themoviedb\.org|anidb\.net/i', $content)) {
            $nfoIndicators += 2;
        }

        // Check for field:value patterns
        if (preg_match_all('/^[A-Za-z\s]{2,20}\s*[:\.]\s*.+$/m', $content, $matches)) {
            $nfoIndicators += min(count($matches[0]) / 3, 2);
        }

        return $nfoIndicators >= 2;
    }

    /**
     * Add an NFO from alternate sources. ex.: PreDB, rar, zip, etc...
     *
     * @param  bool|string  $nfo  The nfo.
     * @param  NNTPService  $nntp  Instance of class NNTPService.
     * @return bool True on success, False on failure.
     *
     * @throws \Exception
     */
    public function addAlternateNfo(bool|string &$nfo, mixed $release, NNTPService $nntp): bool
    {
        if ($release->id > 0 && $this->isNFO($nfo, $release->guid)) {
            $check = ReleaseNfo::whereReleasesId($release->id)->first(['releases_id']);

            if ($check === null) {
                ReleaseNfo::query()->insert(['releases_id' => $release->id, 'nfo' => "\x1f\x8b\x08\x00".gzcompress($nfo)]);
            }

            Release::whereId($release->id)->update(['nfostatus' => self::NFO_FOUND]);

            if (! isset($release->completion)) {
                $release->completion = 0;
            }

            if ($release->completion === 0) {
                $nzbContentsService = app(NzbContentsService::class);
                $nzbContentsService->setNntp($nntp);
                $nzbContentsService->setNfo($this);
                $nzbContentsService->setEchoOutput($this->echo);
                $nzbContentsService->parseNzb($release->guid, $release->id, $release->groups_id ?? 0);
            }

            return true;
        }

        return false;
    }

    /**
     * Fallback: attempt to extract an NFO from a compressed archive in the NZB
     * when the direct NZB-subject-based download has failed.
     *
     * Uses the release_files table to know which NFO filename to look for,
     * then downloads a RAR/ZIP from the NZB and extracts it with unrar/unzip.
     *
     * When NFO entries exist in release_files but extraction yields no valid
     * content (e.g. 0-byte file), sets nfostatus to NFO_NONFO to stop retries.
     *
     * @return string|false The NFO content, or false on failure
     */
    public function attemptNfoFromArchive(string $guid, int $releaseId, NNTPService $nntp): string|false
    {
        if ($this->unrarPath === false) {
            return false;
        }

        $nfoFiles = ReleaseFile::where('releases_id', $releaseId)
            ->nfoFiles()
            ->limit(3)
            ->pluck('name')
            ->all();

        if (empty($nfoFiles)) {
            return false;
        }

        $hasContentfulNfo = ReleaseFile::where('releases_id', $releaseId)
            ->nfoFilesWithContent()
            ->exists();

        if (! $hasContentfulNfo) {
            Release::whereId($releaseId)->update(['nfostatus' => self::NFO_NONFO]);

            return false;
        }

        $nzbService = app(NzbService::class);
        $nzbContents = $nzbService->readNzbContents($guid);
        if ($nzbContents === false) {
            return false;
        }

        $parserService = app(NzbParserService::class);
        $fileList = $parserService->parseNzbFileList($nzbContents);
        if (empty($fileList)) {
            return false;
        }

        $alternateNntp = (bool) config('nntmux_nntp.alternate_article_fallback');
        $maxSegments = 40;
        $triedArchive = false;

        foreach ($fileList as $nzbFile) {
            $title = $nzbFile['title'] ?? '';
            if (! preg_match(
                '/(\\.(part0*1|rar|zip))(\\s*\\.rar)*($|[ ")]|-])|"[a-f0-9]{32}\\.[1-9]\\d{1,2}".*\\(\\d+\\/\\d{2,}\\)$/i',
                $title
            )) {
                continue;
            }

            $segments = $nzbFile['segments'] ?? [];
            if (empty($segments)) {
                continue;
            }

            $messageIDs = array_slice($segments, 0, $maxSegments);
            $compressedData = $nntp->getMessagesByMessageID($messageIDs, $alternateNntp);

            if (! is_string($compressedData) || $compressedData === '') {
                continue;
            }

            $triedArchive = true;
            $extracted = $this->extractNfoViaUnrar($compressedData, $nfoFiles, $guid);
            if ($extracted !== false) {
                return $extracted;
            }

            break;
        }

        if ($triedArchive) {
            Release::whereId($releaseId)->update(['nfostatus' => self::NFO_NONFO]);
        }

        return false;
    }

    /**
     * Extract an NFO file from compressed archive data using the unrar binary.
     *
     * @param  string  $compressedData  Raw archive bytes
     * @param  array<string>  $nfoFilenames  NFO filenames to try extracting
     * @param  string  $guid  Release GUID for NFO validation
     * @return string|false Extracted NFO content, or false on failure
     */
    private function extractNfoViaUnrar(string $compressedData, array $nfoFilenames, string $guid): string|false
    {
        if (! is_string($this->unrarPath)) {
            return false;
        }

        $uniqueId = uniqid('nfo_', true);
        $archiveFile = $this->tmpPath.'archive_'.$uniqueId.'.rar';
        $extractDir = $this->tmpPath.'extract_'.$uniqueId.'/';

        try {
            if (! File::isDirectory($extractDir)) {
                File::makeDirectory($extractDir, 0777, true, true);
            }

            File::put($archiveFile, $compressedData);

            foreach ($nfoFilenames as $nfoFilename) {
                $command = [
                    $this->unrarPath,
                    'e',
                    '-y',
                    '-c-',
                    '-inul',
                    '-p-',
                    '-@',
                    '--',
                    $archiveFile,
                    $nfoFilename,
                    $extractDir,
                ];

                $timeoutSeconds = $this->getTimeoutSeconds();
                if ($this->timeoutPath && $timeoutSeconds > 0) {
                    $command = [
                        $this->timeoutPath,
                        '--foreground',
                        '--signal=KILL',
                        (string) $timeoutSeconds,
                        ...$command,
                    ];
                }

                Process::timeout(1800)->run($command);

                $extractedPath = $extractDir.basename($nfoFilename);
                if (! File::isFile($extractedPath)) {
                    $allFiles = File::allFiles($extractDir);
                    foreach ($allFiles as $file) {
                        if (strcasecmp($file->getFilename(), basename($nfoFilename)) === 0) {
                            $extractedPath = $file->getPathname();
                            break;
                        }
                    }
                }

                if (File::isFile($extractedPath) && File::size($extractedPath) > 0) {
                    $content = File::get($extractedPath);
                    if ($this->isNFO($content, $guid)) {
                        return $content;
                    }
                }
            }
        } catch (Throwable $e) {
            Log::debug('NFO unrar extraction failed: '.$e->getMessage());
        } finally {
            File::delete($archiveFile);
            if (File::isDirectory($extractDir)) {
                File::deleteDirectory($extractDir);
            }
        }

        return false;
    }

    /**
     * Attempt to find NFO files inside the NZB's of releases.
     *
     * @param  NNTPService  $nntp  The NNTP connection object
     * @param  string  $groupID  (optional) Group ID to filter releases by
     * @param  string  $guidChar  (optional) First character of the GUID for parallel processing
     * @param  bool  $processImdb  (optional) Process IMDB IDs (currently unused)
     * @param  bool  $processTv  (optional) Process TV IDs (currently unused)
     * @return int Count of successfully processed NFO files
     *
     * @throws \Exception If NNTP operations fail
     */
    public function processNfoFiles(NNTPService $nntp, string $groupID = '', string $guidChar = '', bool $processImdb = true, bool $processTv = true): int
    {
        $processedCount = 0;

        // Build base query with all filters
        $baseQuery = $this->buildNfoProcessingQuery($groupID, $guidChar);

        // Fetch releases to process
        $releases = $baseQuery->clone()
            ->orderBy('nfostatus')
            ->orderByDesc('postdate')
            ->limit($this->getNzbs())
            ->get(['id', 'guid', 'groups_id', 'name']);

        $nfoCount = $releases->count();

        if ($nfoCount > 0) {
            // Display processing information
            $this->displayProcessingHeader($guidChar, $groupID, $nfoCount);

            // Show detailed stats if echo is enabled
            if ($this->echo) {
                $this->displayNfoStatusStats($baseQuery);
            }

            // Process each release
            $nzbContentsService = app(NzbContentsService::class);
            $nzbContentsService->setNntp($nntp);
            $nzbContentsService->setNfo($this);

            foreach ($releases as $release) {
                try {
                    $groupName = UsenetGroup::getNameByID($release['groups_id']);
                    $fetchedBinary = $nzbContentsService->getNfoFromNzb($release['guid'], $release['id'], $release['groups_id'], $groupName);

                    // Fallback: try extracting NFO from a RAR/ZIP in the NZB
                    if ($fetchedBinary === false) {
                        $fetchedBinary = $this->attemptNfoFromArchive($release['guid'], $release['id'], $nntp);
                        if ($fetchedBinary !== false && $this->echo) {
                            echo 'A';
                        }
                    }

                    if ($fetchedBinary !== false) {
                        DB::beginTransaction();
                        try {
                            $exists = ReleaseNfo::whereReleasesId($release['id'])->exists();
                            if (! $exists) {
                                ReleaseNfo::query()->insert([
                                    'releases_id' => $release['id'],
                                    'nfo' => "\x1f\x8b\x08\x00".gzcompress($fetchedBinary),
                                ]);
                            }

                            Release::whereId($release['id'])->update(['nfostatus' => self::NFO_FOUND]);
                            DB::commit();
                            $processedCount++;
                        } catch (\Exception $e) {
                            DB::rollBack();
                            if ($this->echo) {
                                cli()->error("Error saving NFO for release {$release['id']}: {$e->getMessage()}");
                            }
                        }
                    }
                } catch (\Exception $e) {
                    if ($this->echo) {
                        cli()->error("Error processing release {$release['id']}: {$e->getMessage()}");
                    }
                }
            }
        }

        // Second pass: attempt archive-based extraction for NFO_FAILED (-9) releases
        // that have NFO files listed in release_files with non-zero size.
        $archiveProcessed = $this->processFailedReleasesViaArchive($nntp, $groupID, $guidChar);
        $processedCount += $archiveProcessed;

        // Output results
        if ($this->echo) {
            if ($nfoCount > 0) {
                echo PHP_EOL;
            }
            if ($processedCount > 0) {
                cli()->primary($processedCount.' NFO file(s) found/processed.');
            }
        }

        return $processedCount;
    }

    /**
     * Build base query for NFO processing with all common filters
     *
     * @return Builder<mixed>
     */
    private function buildNfoProcessingQuery(string $groupID, string $guidChar): \Illuminate\Database\Eloquent\Builder // @phpstan-ignore class.notFound, missingType.generics, return.phpDocType
    {
        $query = Release::query()
            ->whereBetween('nfostatus', [$this->getMaxRetries(), self::NFO_UNPROC]);
        // NFOs are read from the NZB; a partial awaiting late articles may still have it replaced.
        Release::whereNzbSettled($query);

        if ($guidChar !== '') {
            $query->where('leftguid', $guidChar);
        }

        if ($groupID !== '') {
            $query->where('groups_id', $groupID);
        }

        if ($this->getMaxSize() > 0) {
            $query->where('size', '<', $this->getMaxSize());
        }

        if ($this->getMinSize() > 0) {
            $query->where('size', '>', $this->getMinSize());
        }

        return $query;
    }

    /**
     * Display header information about the NFO processing
     */
    private function displayProcessingHeader(string $guidChar, string $groupID, int $nfoCount): void
    {
        cli()->primary(
            PHP_EOL.
            ($guidChar === '' ? '' : '['.$guidChar.'] ').
            ($groupID === '' ? '' : '['.$groupID.'] ').
            'Processing '.$nfoCount.
            ' NFO(s), starting at '.$this->getNzbs().
            ' * = hidden NFO, + = NFO, - = no NFO, f = download failed.'
        );
    }

    /**
     * Display statistics about NFO status counts
     */
    private function displayNfoStatusStats(\Illuminate\Database\Eloquent\Builder $baseQuery): void // @phpstan-ignore missingType.generics
    {
        $nfoStats = $baseQuery->clone()
            ->select(['nfostatus as status', DB::raw('COUNT(id) as count')])
            ->groupBy(['nfostatus'])
            ->orderBy('nfostatus')
            ->get();

        if ($nfoStats instanceof \Traversable && $nfoStats->count() > 0) {
            $outString = PHP_EOL.'Available to process';
            foreach ($nfoStats as $row) {
                $outString .= ', '.$row['status'].' = '.number_format($row['count']);
            }
            cli()->header($outString.'.');
        }
    }

    /**
     * Process releases at NFO_FAILED (-9) that have NFO files in release_files
     * with non-zero size. Attempts archive-based extraction via unrar.
     * On failure, sets nfostatus to NFO_FAILED_ARCHIVE (-10) to prevent further retries.
     */
    private function processFailedReleasesViaArchive(NNTPService $nntp, string $groupID, string $guidChar): int
    {
        $query = Release::query()
            ->where('nfostatus', self::NFO_FAILED)
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('release_files')
                    ->whereColumn('release_files.releases_id', 'releases.id')
                    ->where('size', '>', 0)
                    ->where(function ($q) {
                        $q->where('name', 'like', '%.nfo')
                            ->orWhere('name', 'like', '%.diz')
                            ->orWhere('name', 'like', '%.inf')
                            ->orWhere('name', 'like', '%file\_id.diz')
                            ->orWhere('name', 'like', '%info.txt');
                    });
            });
        // NFOs are read from the NZB; a partial awaiting late articles may still have it replaced.
        Release::whereNzbSettled($query);

        if ($guidChar !== '') {
            $query->where('leftguid', $guidChar);
        }

        if ($groupID !== '') {
            $query->where('groups_id', $groupID);
        }

        $releases = $query->limit($this->getNzbs())
            ->get(['id', 'guid', 'groups_id', 'name']);

        if ($releases->isEmpty()) {
            return 0;
        }

        if ($this->echo) {
            cli()->primary(PHP_EOL.'Attempting archive-based NFO extraction for '.$releases->count().' NFO_FAILED release(s).');
        }

        $processed = 0;

        foreach ($releases as $release) {
            try {
                $fetchedBinary = $this->attemptNfoFromArchive($release['guid'], $release['id'], $nntp);

                if ($fetchedBinary !== false) {
                    DB::beginTransaction();
                    try {
                        if (! ReleaseNfo::whereReleasesId($release['id'])->exists()) {
                            ReleaseNfo::query()->insert([
                                'releases_id' => $release['id'],
                                'nfo' => "\x1f\x8b\x08\x00".gzcompress($fetchedBinary),
                            ]);
                        }
                        Release::whereId($release['id'])->update(['nfostatus' => self::NFO_FOUND]);
                        DB::commit();
                        $processed++;

                        if ($this->echo) {
                            echo 'A';
                        }
                    } catch (\Exception $e) {
                        DB::rollBack();
                        if ($this->echo) {
                            cli()->error("Error saving archive NFO for release {$release['id']}: {$e->getMessage()}");
                        }
                    }
                } else {
                    Release::whereId($release['id'])->update(['nfostatus' => self::NFO_FAILED_ARCHIVE]);

                    if ($this->echo) {
                        echo '-';
                    }
                }
            } catch (\Exception $e) {
                Release::whereId($release['id'])->update(['nfostatus' => self::NFO_FAILED_ARCHIVE]);

                if ($this->echo) {
                    cli()->error("Error in archive NFO extraction for release {$release['id']}: {$e->getMessage()}");
                }
            }
        }

        return $processed;
    }

    /**
     * Get a string like this:
     * "AND r.nfostatus BETWEEN -8 AND -1 AND r.size < 1073741824 AND r.size > 1048576"
     * To use in a query.
     *
     *
     * @throws \Exception
     *
     * @static
     */
    public static function NfoQueryString(): string
    {
        $maxSize = (int) app(ConfigurationProvider::class)->postProcessing()->maxSizeToProcessNfo;
        $minSize = (int) app(ConfigurationProvider::class)->postProcessing()->minSizeToProcessNfo;
        $dummy = (int) app(ConfigurationProvider::class)->postProcessing()->maxNfoRetries;
        $maxRetries = ($dummy >= 0 ? -($dummy + 1) : self::NFO_UNPROC);

        return sprintf(
            'AND r.nfostatus BETWEEN %d AND %d %s %s AND %s',
            ($maxRetries < -8 ? -8 : $maxRetries),
            self::NFO_UNPROC,
            ($maxSize > 0 ? ('AND r.size < '.$maxSize) : ''),
            ($minSize > 0 ? ('AND r.size > '.$minSize) : ''),
            Release::nzbSettledSql('r')
        );
    }

    /**
     * Extract language information from NFO content.
     *
     * @param  string  $nfoContent  The NFO content to parse.
     * @return array<string, mixed> Array of detected languages.
     */
    public function extractLanguage(string $nfoContent): array
    {
        $languages = [];

        // Common language patterns in NFOs
        $patterns = [
            '/(?:language|audio|spoken?|dialogue)\s*[:\-]?\s*([A-Za-z]+(?:\s*[,\/&]\s*[A-Za-z]+)*)/i',
            '/(?:subs?|subtitles?)\s*[:\-]?\s*([A-Za-z]+(?:\s*[,\/&]\s*[A-Za-z]+)*)/i',
        ];

        // Known language names
        $knownLanguages = [
            'english', 'german', 'french', 'spanish', 'italian', 'dutch', 'portuguese',
            'russian', 'japanese', 'korean', 'chinese', 'mandarin', 'cantonese',
            'swedish', 'norwegian', 'danish', 'finnish', 'polish', 'czech', 'hungarian',
            'turkish', 'arabic', 'hindi', 'thai', 'vietnamese', 'indonesian', 'malay',
            'multi', 'dual', 'english/german', 'eng', 'ger', 'fre', 'spa', 'ita',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $nfoContent, $matches)) {
                $langs = preg_split('/[\s,\/&]+/', strtolower($matches[1]));
                foreach ($langs as $lang) {
                    $lang = trim($lang);
                    if (in_array($lang, $knownLanguages, true) && ! in_array($lang, $languages, true)) {
                        $languages[] = ucfirst($lang);
                    }
                }
            }
        }

        return $languages;
    }

    /** Private proposal: this final writer supplies the cleanup callback only after its actual SQL writes. */
    final public function storeNfoContentWithCommit(int $releaseId, string $nfoContent, \Closure $commit): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \RuntimeException('NFO commit witness requires an active transaction');
        }
        $data = "\x1f\x8b\x08\x00".gzcompress($nfoContent);
        ReleaseNfo::updateOrCreate(['releases_id' => $releaseId], ['nfo' => $data]);
        Release::whereId($releaseId)->update(['nfostatus' => self::NFO_FOUND]);
        $commit();
    }
}
