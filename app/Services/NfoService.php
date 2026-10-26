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
use App\Support\RustPipelineLibrary;
use dariusiii\rarinfo\Par2Info;
use dariusiii\rarinfo\SfvInfo;
use FFI\CData;
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
    public const METADATA_FFI_CDEF = <<<'CDEF'
        ssize_t nntmux_nfo_extract_urls(const char *text, size_t text_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_nfo_extract_group_name(const char *text, size_t text_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_nfo_extract_release_date(const char *text, size_t text_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_nfo_extract_codec_info(const char *text, size_t text_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_nfo_extract_file_size(const char *text, size_t text_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_nfo_parse_metadata(const char *text, size_t text_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_nfo_extract_language(const char *text, size_t text_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_nfo_extract_runtime(const char *text, size_t text_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_nfo_extract_genre(const char *text, size_t text_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_nfo_extract_software_info(const char *text, size_t text_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_nfo_extract_release_title(const char *text, size_t text_len, char *out_buf, size_t out_cap);
        int32_t nntmux_nfo_calculate_quality(const char *text, size_t text_len);
        int32_t nntmux_nfo_calculate_quality_fused(const char *text, size_t text_len);
        int32_t nntmux_nfo_has_ascii_art(const char *text, size_t text_len);
        int32_t nntmux_nfo_analyze_structure(const char *text, size_t text_len);
        ssize_t nntmux_nfo_extract_all_media_ids(const char *text, size_t text_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_nfo_parse_show_id(const char *text, size_t text_len, char *out_buf, size_t out_cap);
    CDEF;

    private static bool $metadataFfiResolved = false;

    private static ?\FFI $metadataFfi = null;

    private function resolveMetadataFfi(): ?\FFI
    {
        if ($this->libraryPath !== null) {
            return RustPipelineLibrary::resolveDomain('nfo_metadata_quality', self::METADATA_FFI_CDEF, $this->libraryPath);
        }
        if (! self::$metadataFfiResolved) {
            self::$metadataFfi = RustPipelineLibrary::resolveDomain('nfo_metadata_quality', self::METADATA_FFI_CDEF);
            self::$metadataFfiResolved = true;
        }

        return self::$metadataFfi;
    }

    /**
     * Byte-preserving serialized values keep sparse URL keys and binary NFO text.
     * A missing/older library, unavailable engine or failed allocation falls back.
     */
    private function metadataRust(string $symbol, string $text, bool &$handled, bool $scalar = false): mixed
    {
        $handled = false;
        if (ini_get('pcre.backtrack_limit') !== '1000000' || ini_get('pcre.recursion_limit') !== '100000'
            || ini_get('pcre.jit') !== '1' || ! in_array(setlocale(LC_CTYPE, '0'), ['C', 'POSIX', 'C.UTF-8', 'C.utf8'], true)) {
            return null;
        }
        try {
            $ffi = $this->resolveMetadataFfi();
            if ($ffi === null) {
                return null;
            }
            if ($scalar) {
                $result = $ffi->$symbol($text, strlen($text));
                $handled = is_int($result) && $result >= 0;

                return $result;
            }
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, max(512, strlen($text) + 512), $capacity);
            if ($buffer === null) {
                return null;
            }
            $written = $ffi->$symbol($text, strlen($text), $buffer, $capacity);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol($text, strlen($text), $buffer, $capacity);
            }
            if (! is_int($written) || $written <= 0 || $written > $capacity) {
                return null;
            }
            $result = unserialize(\FFI::string($buffer, $written), ['allowed_classes' => false]);
            $handled = true;

            return $result;
        } catch (Throwable) {
            return null;
        }
    }

    private const DEFAULT_NON_NFO_HEADER_REGEX = '/\A(\s*<\?xml|=newz\[NZB\]=|RIFF|\s*[RP]AR|.{0,10}(JFIF|matroska|ftyp|ID3)|PK\x03\x04|\x1f\x8b\x08|MZ|%PDF|GIF8[79]a|\x89PNG)|;\s*Generated\s*by.*SF\w/i';

    private const DEFAULT_BINARY_CHARS_REGEX = '/[\x00-\x08\x0B\x0C\x0E-\x1F]/';

    /** @var list<string> */
    private const DEFAULT_NFO_KEYWORDS = [
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
     * Regex to detect common non-NFO file headers/signatures.
     * Matches XML, NZB, RIFF (media), PAR/RAR archives, and other binary formats.
     */
    protected string $_nonNfoHeaderRegex = self::DEFAULT_NON_NFO_HEADER_REGEX;

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
    protected array $_nfoKeywords = self::DEFAULT_NFO_KEYWORDS; // @phpstan-ignore property.defaultValue

    /**
     * Scene group patterns for improved detection.
     *
     * @var array<string, mixed>
     */
    protected array $_sceneGroupPatterns = self::DEFAULT_SCENE_GROUP_PATTERNS; // @phpstan-ignore property.defaultValue

    private const DEFAULT_SCENE_GROUP_PATTERNS = [
        '/(?:^|\n)\s*[-=*]{3,}.*?([A-Z0-9]{2,15})\s*[-=*]{3,}/i',
        '/(?:presents?|brought\s+(?:to\s+)?(?:you\s+)?by|from)\s*[:\-]?\s*([A-Z][A-Z0-9]{1,14})/i',
        '/(?:greets?\s+(?:go(?:es)?\s+)?(?:out\s+)?to|respect\s+to)\s*[:\-]?\s*([\w,\s&]+)/i',
        '/(?:^|\n)\s*([A-Z][A-Z0-9]{1,14})\s+(?:nfo|info|release)\s*(?:$|\n)/i',
        '/(?:released\s+by|rls\s+by)\s*[:\-]?\s*([A-Z][A-Z0-9]{1,14})/i',
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

    private ?string $libraryPath = null;

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
    public function __construct(?string $libraryPath = null)
    {
        $this->libraryPath = $libraryPath;
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
     * Look for a TV Show ID or Movie ID in a string.
     *
     * Supports: TVMaze, IMDB, TVDB (legacy & modern), TMDB, AniDB
     *
     * @param  string  $str  The string with a Show ID.
     * @return array{showid: string, site: string}|false Return array with show ID and site source or false on failure.
     */
    public function parseShowId(string $str): array|false
    {
        return $this->parseShowIdNative($str);
    }

    /** @return array<string, mixed>|false */
    public function parseShowIdRust(string $str): array|false
    {
        if (get_class($this) !== self::class) {
            return $this->parseShowIdNative($str);
        }
        $handled = false;
        $value = $this->metadataRust('nntmux_nfo_parse_show_id', $str, $handled);

        return $handled && (is_array($value) || $value === false) ? $value : $this->parseShowIdNative($str);
    }

    /** @return array{showid: string, site: string}|false */
    public function parseShowIdNative(string $str): array|false
    {
        // TVMaze
        if (preg_match('/tvmaze\.com\/shows\/(\d{1,6})/i', $str, $hits)) {
            return ['showid' => trim($hits[1]), 'site' => 'tvmaze'];
        }

        // IMDB (movies and TV shows)
        if (preg_match('/imdb\.com\/title\/(tt\d{7,})/i', $str, $hits)) {
            return ['showid' => trim($hits[1]), 'site' => 'imdb'];
        }

        // TVDB - Legacy URL format
        if (preg_match('/thetvdb\.com\/\?tab=series&id=(\d{1,8})/i', $str, $hits)) {
            return ['showid' => trim($hits[1]), 'site' => 'thetvdb'];
        }

        // TVDB - Modern URL format (series/slug or series/id)
        if (preg_match('/thetvdb\.com\/series\/(\d{1,8}|[\w-]+)/i', $str, $hits)) {
            return ['showid' => trim($hits[1]), 'site' => 'thetvdb'];
        }

        // TMDB - Movie
        if (preg_match('/themoviedb\.org\/movie\/(\d{1,8})/i', $str, $hits)) {
            return ['showid' => trim($hits[1]), 'site' => 'tmdb_movie'];
        }

        // TMDB - TV Show
        if (preg_match('/themoviedb\.org\/tv\/(\d{1,8})/i', $str, $hits)) {
            return ['showid' => trim($hits[1]), 'site' => 'tmdb_tv'];
        }

        // AniDB
        if (preg_match('/anidb\.net\/(?:perl-bin\/animedb\.pl\?show=anime&aid=|anime\/)(\d{1,6})/i', $str, $hits)) {
            return ['showid' => trim($hits[1]), 'site' => 'anidb'];
        }

        // Trakt.tv
        if (preg_match('/trakt\.tv\/(?:shows|movies)\/([\w-]+)/i', $str, $hits)) {
            return ['showid' => trim($hits[1]), 'site' => 'trakt'];
        }

        return false;
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

        $preconditionFlags = null;
        if ($this->_nonNfoHeaderRegex === self::DEFAULT_NON_NFO_HEADER_REGEX) {
            $preconditionFlags = RustPipelineLibrary::nfoCheckPreconditions($possibleNFO, $this->libraryPath);
        }

        if ($preconditionFlags !== null) {
            if (($preconditionFlags & 3) !== 0) {
                return false;
            }
        } else {
            // Quick check for known non-NFO file signatures
            if (preg_match($this->_nonNfoHeaderRegex, $possibleNFO)) {
                return false;
            }

            // Additional binary format checks
            if ($this->detectBinaryFormat($possibleNFO)) {
                return false;
            }
        }

        $tmpPath = $this->tmpPath.$guid.'.nfo';
        $isNfo = false;

        try {
            // File/GetId3 work with files, so save to disk.
            File::put($tmpPath, $possibleNFO);

            // Use 'file' command via fileInfo if available
            $result = fileInfo($tmpPath);
            if (! empty($result)) {
                $hasBinaryChars = ($preconditionFlags !== null && $this->_binaryCharsRegex === self::DEFAULT_BINARY_CHARS_REGEX)
                    ? (($preconditionFlags & 4) !== 0)
                    : (bool) preg_match($this->_binaryCharsRegex, $possibleNFO);

                if (preg_match($this->_textFileRegex, $result)) {
                    $isNfo = true;
                } elseif (preg_match($this->_binaryFileRegex, $result) || $hasBinaryChars) {
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
            $hasBinaryChars = ($preconditionFlags !== null && $this->_binaryCharsRegex === self::DEFAULT_BINARY_CHARS_REGEX)
                ? (($preconditionFlags & 4) !== 0)
                : (bool) preg_match($this->_binaryCharsRegex, $possibleNFO);
            if ($hasBinaryChars) {
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
        if (\strlen($data) < 4) {
            return false;
        }

        $flags = RustPipelineLibrary::nfoCheckPreconditions($data, $this->libraryPath);
        if ($flags !== null) {
            return ($flags & 2) !== 0;
        }

        return $this->detectBinaryFormatPhp($data);
    }

    protected function detectBinaryFormatPhp(string $data): bool
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
        if (\strlen($content) < 50) {
            return false;
        }

        if ($this->_nfoKeywords === self::DEFAULT_NFO_KEYWORDS) {
            $native = RustPipelineLibrary::nfoValidateContent($content, $this->libraryPath);
            if ($native !== null) {
                return $native;
            }
        }

        return $this->validateNfoContentPhp($content);
    }

    protected function validateNfoContentPhp(string $content): bool
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
     * Extract URLs from NFO content.
     *
     * @param  string  $nfoContent  The NFO content to parse.
     * @return array<string, mixed> Array of found URLs.
     */
    public function extractUrls(string $nfoContent): array
    {
        return $this->extractUrlsNative($nfoContent);
    }

    /** @return array<array-key, mixed> */
    public function extractUrlsRust(string $nfoContent): array
    {
        if (get_class($this) !== self::class) {
            return $this->extractUrlsNative($nfoContent);
        }
        $handled = false;
        $value = $this->metadataRust('nntmux_nfo_extract_urls', $nfoContent, $handled);

        return $handled && is_array($value) ? $value : $this->extractUrlsNative($nfoContent);
    }

    /** @return array<array-key, mixed> */
    public function extractUrlsNative(string $nfoContent): array
    {
        $urls = [];

        // Match HTTP/HTTPS URLs
        if (preg_match_all('/https?:\/\/[^\s<>"\']+/i', $nfoContent, $matches)) {
            $urls = array_merge($urls, $matches[0]);
        }

        // Match www URLs without protocol
        if (preg_match_all('/(?<![\/\.])\bwww\.[a-z0-9][-a-z0-9]*\.[^\s<>"\']+/i', $nfoContent, $matches)) {
            foreach ($matches[0] as $url) {
                $urls[] = 'http://'.$url;
            }
        }

        return array_unique(array_filter($urls));
    }

    /**
     * Extract release group name from NFO content.
     *
     * Uses multiple detection strategies including:
     * - Common presentation phrases
     * - Scene-style headers with ASCII borders
     * - Greetings sections
     * - Footer signatures
     *
     * @param  string  $nfoContent  The NFO content to parse.
     * @return array<int<0, max>, string>
     */
    public function extractGroupName(string $nfoContent): ?string
    {
        return $this->extractGroupNameNative($nfoContent);
    }

    public function extractGroupNameRust(string $nfoContent): ?string
    {
        if (get_class($this) !== self::class || $this->_sceneGroupPatterns !== self::DEFAULT_SCENE_GROUP_PATTERNS) {
            return $this->extractGroupNameNative($nfoContent);
        }
        $handled = false;
        $value = $this->metadataRust('nntmux_nfo_extract_group_name', $nfoContent, $handled);

        return $handled && ($value === null || is_string($value)) ? $value : $this->extractGroupNameNative($nfoContent);
    }

    public function extractGroupNameNative(string $nfoContent): ?string
    {
        // False positives to filter out
        $falsePositives = [
            'THE', 'AND', 'FOR', 'NFO', 'INFO', 'DVD', 'BLU', 'RAY', 'WEB', 'HDTV',
            'RELEASE', 'GROUP', 'DATE', 'SIZE', 'CODEC', 'VIDEO', 'AUDIO', 'FORMAT',
            'NOTES', 'INSTALL', 'GREETS', 'PRESENTS', 'TEAM', 'SCENE', 'FILE', 'FILES',
        ];

        // Use configured scene group patterns
        foreach ($this->_sceneGroupPatterns as $pattern) {
            if (preg_match($pattern, $nfoContent, $matches)) {
                $groupName = trim($matches[1]);
                if (! in_array(strtoupper($groupName), $falsePositives, true) && strlen($groupName) >= 2 && strlen($groupName) <= 20) {
                    return $groupName;
                }
            }
        }

        // Additional patterns for group name detection
        $additionalPatterns = [
            // "GROUP presents" or "GROUP brings you"
            '/\b([A-Z][A-Z0-9]{1,14})\s+(?:presents?|brings?\s+you)/i',
            // Common footer format: "--- GROUP ---"
            '/[-=]{2,}\s*([A-Z][A-Z0-9]{1,14})\s*[-=]{2,}$/mi',
            // Contact section: "irc.server.net #GROUP"
            '/irc\.[a-z0-9.-]+\s+#([A-Z][A-Z0-9]{1,14})/i',
            // Website: "www.GROUP.com/org/net"
            '/www\.([a-z][a-z0-9]{1,14})\.(?:com|org|net|info)/i',
            // ASCII art name extraction (common pattern at start)
            '/^\s*[^a-zA-Z0-9]*([A-Z][A-Z0-9]{2,14})[^a-zA-Z0-9]*\s*$/mi',
        ];

        foreach ($additionalPatterns as $pattern) {
            if (preg_match($pattern, $nfoContent, $matches)) {
                $groupName = trim($matches[1]);
                if (! in_array(strtoupper($groupName), $falsePositives, true) && strlen($groupName) >= 2 && strlen($groupName) <= 20) {
                    return strtoupper($groupName);
                }
            }
        }

        return null;
    }

    /**
     * Extract release date from NFO content.
     *
     * @param  string  $nfoContent  The NFO content to parse.
     * @return string|null ISO date string if found, null otherwise.
     */
    public function extractReleaseDate(string $nfoContent): ?string
    {
        return $this->extractReleaseDateNative($nfoContent);
    }

    public function extractReleaseDateRust(string $nfoContent): ?string
    {
        if (get_class($this) !== self::class) {
            return $this->extractReleaseDateNative($nfoContent);
        }
        $handled = false;
        $value = $this->metadataRust('nntmux_nfo_extract_release_date', $nfoContent, $handled);

        return $handled && ($value === null || is_string($value)) ? $value : $this->extractReleaseDateNative($nfoContent);
    }

    public function extractReleaseDateNative(string $nfoContent): ?string
    {
        $patterns = [
            // DD/MM/YYYY or MM/DD/YYYY
            '/(?:date|released?|rls)\s*[:\-]?\s*(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})/i',
            // YYYY-MM-DD
            '/(?:date|released?|rls)\s*[:\-]?\s*(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})/i',
            // Month DD, YYYY
            '/(?:date|released?|rls)\s*[:\-]?\s*(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?\s+(\d{1,2}),?\s+(\d{4})/i',
        ];

        foreach ($patterns as $index => $pattern) {
            if (preg_match($pattern, $nfoContent, $matches)) {
                try {
                    if ($index === 0) {
                        // Try both DD/MM and MM/DD formats
                        $year = strlen($matches[3]) === 2 ? '20'.$matches[3] : $matches[3];

                        // Assume DD/MM/YYYY format (more common internationally)
                        return sprintf('%04d-%02d-%02d', (int) $year, (int) $matches[2], (int) $matches[1]);
                    } elseif ($index === 1) {
                        // YYYY-MM-DD
                        return sprintf('%04d-%02d-%02d', (int) $matches[1], (int) $matches[2], (int) $matches[3]);
                    } else {
                        // Month name format
                        $months = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
                        $month = $months[strtolower(substr($matches[1], 0, 3))] ?? 1;

                        return sprintf('%04d-%02d-%02d', (int) $matches[3], $month, (int) $matches[2]);
                    }
                } catch (Throwable) {
                    continue;
                }
            }
        }

        return null;
    }

    /**
     * Extract video/audio codec information from NFO content.
     *
     * @param  string  $nfoContent  The NFO content to parse.
     * @return array{video?: string, audio?: string, resolution?: string} Array with codec info.
     */
    public function extractCodecInfo(string $nfoContent): array
    {
        return $this->extractCodecInfoNative($nfoContent);
    }

    /** @return array<array-key, mixed> */
    public function extractCodecInfoRust(string $nfoContent): array
    {
        if (get_class($this) !== self::class) {
            return $this->extractCodecInfoNative($nfoContent);
        }
        $handled = false;
        $value = $this->metadataRust('nntmux_nfo_extract_codec_info', $nfoContent, $handled);

        return $handled && is_array($value) ? $value : $this->extractCodecInfoNative($nfoContent);
    }

    /** @return array<array-key, mixed> */
    public function extractCodecInfoNative(string $nfoContent): array
    {
        $result = [];

        // Video codecs
        $videoPatterns = [
            '/(?:video|codec)\s*[:\-]?\s*(x264|x265|hevc|h\.?264|h\.?265|xvid|divx|av1|vp9|mpeg[24]?)/i',
            '/\b(x264|x265|HEVC|H\.?264|H\.?265|XviD|DivX|AV1|VP9)\b/i',
        ];
        foreach ($videoPatterns as $pattern) {
            if (preg_match($pattern, $nfoContent, $matches)) {
                $result['video'] = strtoupper(str_replace('.', '', $matches[1]));
                break;
            }
        }

        // Audio codecs
        $audioPatterns = [
            '/(?:audio|sound)\s*[:\-]?\s*(aac|ac3|dts(?:-(?:hd|ma|x))?|truehd|atmos|flac|mp3|eac3|dd[+p]?|dolby)/i',
            '/\b(AAC|AC3|DTS(?:-(?:HD|MA|X))?|TrueHD|Atmos|FLAC|EAC3|DD[+P]?)\b/i',
        ];
        foreach ($audioPatterns as $pattern) {
            if (preg_match($pattern, $nfoContent, $matches)) {
                $result['audio'] = strtoupper($matches[1]);
                break;
            }
        }

        // Resolution
        $resolutionPatterns = [
            '/(?:resolution|quality)\s*[:\-]?\s*(\d{3,4}[xX×]\d{3,4}|\d{3,4}p|[48]K|UHD|FHD|HD)/i',
            '/\b(2160p|1080p|720p|480p|4K|UHD|FHD|HD)\b/i',
            '/\b(\d{3,4})\s*[xX×]\s*(\d{3,4})\b/',
        ];
        foreach ($resolutionPatterns as $index => $pattern) {
            if (preg_match($pattern, $nfoContent, $matches)) {
                if ($index === 2) {
                    $result['resolution'] = $matches[1].'x'.$matches[2];
                } else {
                    $result['resolution'] = strtoupper($matches[1]);
                }
                break;
            }
        }

        return $result;
    }

    /**
     * Extract file size information from NFO content.
     *
     * @param  string  $nfoContent  The NFO content to parse.
     * @return int|null File size in bytes if found, null otherwise.
     */
    public function extractFileSize(string $nfoContent): ?int
    {
        return $this->extractFileSizeNative($nfoContent);
    }

    public function extractFileSizeRust(string $nfoContent): ?int
    {
        if (get_class($this) !== self::class) {
            return $this->extractFileSizeNative($nfoContent);
        }
        $handled = false;
        $value = $this->metadataRust('nntmux_nfo_extract_file_size', $nfoContent, $handled);

        return $handled && ($value === null || is_int($value)) ? $value : $this->extractFileSizeNative($nfoContent);
    }

    public function extractFileSizeNative(string $nfoContent): ?int
    {
        $patterns = [
            '/(?:size|file\s*size)\s*[:\-]?\s*(\d+(?:[.,]\d+)?)\s*(bytes?|[KMGTP]B|[KMGTP]iB)/i',
            '/\b(\d+(?:[.,]\d+)?)\s*(GB|GiB|MB|MiB|TB|TiB)\b/i',
        ];

        $multipliers = [
            'B' => 1, 'BYTE' => 1, 'BYTES' => 1,
            'KB' => 1024, 'KIB' => 1024,
            'MB' => 1024 * 1024, 'MIB' => 1024 * 1024,
            'GB' => 1024 * 1024 * 1024, 'GIB' => 1024 * 1024 * 1024,
            'TB' => 1024 * 1024 * 1024 * 1024, 'TIB' => 1024 * 1024 * 1024 * 1024,
            'PB' => 1024 * 1024 * 1024 * 1024 * 1024, 'PIB' => 1024 * 1024 * 1024 * 1024 * 1024,
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $nfoContent, $matches)) {
                $value = (float) str_replace(',', '.', $matches[1]);
                $unit = strtoupper($matches[2]);

                if (isset($multipliers[$unit])) {
                    return (int) ($value * $multipliers[$unit]);
                }
            }
        }

        return null;
    }

    /**
     * Extract all media IDs (IMDB, TVDB, TMDB, etc.) from NFO content.
     *
     * @param  string  $nfoContent  The NFO content to parse.
     * @return array<string, mixed> Array of media IDs with their sources.
     */
    public function extractAllMediaIds(string $nfoContent): array
    {
        return $this->extractAllMediaIdsNative($nfoContent);
    }

    /** @return list<array{id: string, source: string}> */
    public function extractAllMediaIdsRust(string $nfoContent): array
    {
        if (get_class($this) !== self::class) {
            return $this->extractAllMediaIdsNative($nfoContent);
        }
        $handled = false;
        $value = $this->metadataRust('nntmux_nfo_extract_all_media_ids', $nfoContent, $handled);

        return $handled && is_array($value) ? $value : $this->extractAllMediaIdsNative($nfoContent);
    }

    /** @return list<array{id: string, source: string}> */
    public function extractAllMediaIdsNative(string $nfoContent): array
    {
        $ids = [];

        // IMDB
        if (preg_match_all('/imdb\.com\/title\/(tt\d{7,})/i', $nfoContent, $matches)) {
            foreach ($matches[1] as $id) {
                $ids[] = ['id' => $id, 'source' => 'imdb'];
            }
        }

        // TVDB
        if (preg_match_all('/thetvdb\.com\/(?:\?tab=series&id=|series\/)(\d{1,8})/i', $nfoContent, $matches)) {
            foreach ($matches[1] as $id) {
                $ids[] = ['id' => $id, 'source' => 'thetvdb'];
            }
        }

        // TMDB Movie
        if (preg_match_all('/themoviedb\.org\/movie\/(\d{1,8})/i', $nfoContent, $matches)) {
            foreach ($matches[1] as $id) {
                $ids[] = ['id' => $id, 'source' => 'tmdb_movie'];
            }
        }

        // TMDB TV
        if (preg_match_all('/themoviedb\.org\/tv\/(\d{1,8})/i', $nfoContent, $matches)) {
            foreach ($matches[1] as $id) {
                $ids[] = ['id' => $id, 'source' => 'tmdb_tv'];
            }
        }

        // TVMaze
        if (preg_match_all('/tvmaze\.com\/shows\/(\d{1,6})/i', $nfoContent, $matches)) {
            foreach ($matches[1] as $id) {
                $ids[] = ['id' => $id, 'source' => 'tvmaze'];
            }
        }

        // AniDB
        if (preg_match_all('/anidb\.net\/(?:perl-bin\/animedb\.pl\?show=anime&aid=|anime\/)(\d{1,6})/i', $nfoContent, $matches)) {
            foreach ($matches[1] as $id) {
                $ids[] = ['id' => $id, 'source' => 'anidb'];
            }
        }

        // MyAnimeList (MAL)
        if (preg_match_all('/myanimelist\.net\/anime\/(\d{1,6})/i', $nfoContent, $matches)) {
            foreach ($matches[1] as $id) {
                $ids[] = ['id' => $id, 'source' => 'mal'];
            }
        }

        return $ids;
    }

    /**
     * Parse and extract comprehensive metadata from NFO content.
     *
     * @param  string  $nfoContent  The NFO content to parse.
     * @return list<array<string, string>>
     */
    public function parseNfoMetadata(string $nfoContent): array
    {
        return $this->parseNfoMetadataNative($nfoContent);
    }

    /** @return array<array-key, mixed> */
    public function parseNfoMetadataRust(string $nfoContent): array
    {
        if (get_class($this) !== self::class || $this->_sceneGroupPatterns !== self::DEFAULT_SCENE_GROUP_PATTERNS) {
            return $this->parseNfoMetadataNative($nfoContent);
        }
        $handled = false;
        $value = $this->metadataRust('nntmux_nfo_parse_metadata', $nfoContent, $handled);

        return $handled && is_array($value) ? $value : $this->parseNfoMetadataNative($nfoContent);
    }

    /** @return array<array-key, mixed> */
    public function parseNfoMetadataNative(string $nfoContent): array
    {
        return [
            'urls' => $this->extractUrls($nfoContent),
            'group' => $this->extractGroupName($nfoContent),
            'release_date' => $this->extractReleaseDate($nfoContent),
            'codec_info' => $this->extractCodecInfo($nfoContent),
            'file_size' => $this->extractFileSize($nfoContent),
            'media_ids' => $this->extractAllMediaIds($nfoContent),
            'show_id' => $this->parseShowId($nfoContent),
            'language' => $this->extractLanguage($nfoContent),
            'runtime' => $this->extractRuntime($nfoContent),
            'genre' => $this->extractGenre($nfoContent),
            'software_info' => $this->extractSoftwareInfo($nfoContent),
            'release_title' => $this->extractReleaseTitle($nfoContent),
        ];
    }

    /**
     * Extract language information from NFO content.
     *
     * @param  string  $nfoContent  The NFO content to parse.
     * @return array<string, mixed> Array of detected languages.
     */
    public function extractLanguage(string $nfoContent): array
    {
        return $this->extractLanguageNative($nfoContent);
    }

    /** @return array<array-key, mixed> */
    public function extractLanguageRust(string $nfoContent): array
    {
        if (get_class($this) !== self::class) {
            return $this->extractLanguageNative($nfoContent);
        }
        $handled = false;
        $value = $this->metadataRust('nntmux_nfo_extract_language', $nfoContent, $handled);

        return $handled && is_array($value) ? $value : $this->extractLanguageNative($nfoContent);
    }

    /** @return array<array-key, mixed> */
    public function extractLanguageNative(string $nfoContent): array
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

    /**
     * Extract runtime/duration from NFO content.
     *
     * @param  string  $nfoContent  The NFO content to parse.
     * @return list<string>
     */
    public function extractRuntime(string $nfoContent): ?int
    {
        return $this->extractRuntimeNative($nfoContent);
    }

    public function extractRuntimeRust(string $nfoContent): ?int
    {
        if (get_class($this) !== self::class) {
            return $this->extractRuntimeNative($nfoContent);
        }
        $handled = false;
        $value = $this->metadataRust('nntmux_nfo_extract_runtime', $nfoContent, $handled);

        return $handled && ($value === null || is_int($value)) ? $value : $this->extractRuntimeNative($nfoContent);
    }

    public function extractRuntimeNative(string $nfoContent): ?int
    {
        $patterns = [
            // "Runtime: 1h 30m" or "Duration: 90min"
            '/(?:runtime|duration|length|playtime)\s*[:\-]?\s*(?:(\d{1,2})\s*h(?:ours?)?\s*)?(\d{1,3})\s*m(?:in(?:utes?)?)?/i',
            // "Runtime: 01:30:00" or "1:30:00"
            '/(?:runtime|duration|length|playtime)\s*[:\-]?\s*(\d{1,2}):(\d{2})(?::(\d{2}))?/i',
            // "90 minutes" standalone
            '/\b(\d{2,3})\s*(?:min(?:utes?)?|mins)\b/i',
        ];

        foreach ($patterns as $index => $pattern) {
            if (preg_match($pattern, $nfoContent, $matches)) {
                if ($index === 0) {
                    $hours = ! empty($matches[1]) ? (int) $matches[1] : 0;
                    $minutes = (int) $matches[2];

                    return ($hours * 60) + $minutes;
                } elseif ($index === 1) {
                    $hours = (int) $matches[1];
                    $minutes = (int) $matches[2];

                    return ($hours * 60) + $minutes;
                } else {
                    return (int) $matches[1];
                }
            }
        }

        return null;
    }

    /**
     * Extract genre information from NFO content.
     *
     * @param  string  $nfoContent  The NFO content to parse.
     * @return array<string, mixed> Array of detected genres.
     */
    public function extractGenre(string $nfoContent): array
    {
        return $this->extractGenreNative($nfoContent);
    }

    /** @return array<array-key, mixed> */
    public function extractGenreRust(string $nfoContent): array
    {
        if (get_class($this) !== self::class) {
            return $this->extractGenreNative($nfoContent);
        }
        $handled = false;
        $value = $this->metadataRust('nntmux_nfo_extract_genre', $nfoContent, $handled);

        return $handled && is_array($value) ? $value : $this->extractGenreNative($nfoContent);
    }

    /** @return array<array-key, mixed> */
    public function extractGenreNative(string $nfoContent): array
    {
        $genres = [];

        if (preg_match('/(?:genre|category|type)\s*[:\-]?\s*([^\n\r]+)/i', $nfoContent, $matches)) {
            $genreString = $matches[1];
            // Split on common separators
            $parts = preg_split('/[\s,\/&|]+/', $genreString);

            // Known valid genres
            $validGenres = [
                'action', 'adventure', 'animation', 'biography', 'comedy', 'crime',
                'documentary', 'drama', 'family', 'fantasy', 'history', 'horror',
                'music', 'musical', 'mystery', 'romance', 'sci-fi', 'scifi', 'sport',
                'thriller', 'war', 'western', 'adult', 'xxx', 'erotic', 'anime',
                'rpg', 'fps', 'strategy', 'simulation', 'puzzle', 'racing', 'sports',
                'rock', 'pop', 'electronic', 'hip-hop', 'rap', 'classical', 'jazz',
            ];

            foreach ($parts as $part) {
                $part = strtolower(trim($part));
                if (in_array($part, $validGenres, true) && ! in_array(ucfirst($part), $genres, true)) {
                    $genres[] = ucfirst($part);
                }
            }
        }

        return $genres;
    }

    /**
     * Extract software-specific information from NFO content.
     *
     * @param  string  $nfoContent  The NFO content to parse.
     * @return list<string>
     */
    public function extractSoftwareInfo(string $nfoContent): array
    {
        return $this->extractSoftwareInfoNative($nfoContent);
    }

    /** @return array<array-key, mixed> */
    public function extractSoftwareInfoRust(string $nfoContent): array
    {
        if (get_class($this) !== self::class) {
            return $this->extractSoftwareInfoNative($nfoContent);
        }
        $handled = false;
        $value = $this->metadataRust('nntmux_nfo_extract_software_info', $nfoContent, $handled);

        return $handled && is_array($value) ? $value : $this->extractSoftwareInfoNative($nfoContent);
    }

    /** @return array<array-key, mixed> */
    public function extractSoftwareInfoNative(string $nfoContent): array
    {
        $info = [];

        // Platform/OS detection
        $platformPatterns = [
            '/(?:platform|os|system|requires?)\s*[:\-]?\s*(windows?|linux|mac(?:os)?|unix|android|ios)/i',
        ];
        foreach ($platformPatterns as $pattern) {
            if (preg_match($pattern, $nfoContent, $matches)) {
                $info['platform'] = ucfirst(strtolower($matches[1]));
                break;
            }
        }

        // Version detection
        if (preg_match('/(?:version|ver|v)\s*[:\-]?\s*(\d+(?:\.\d+)*(?:\s*(?:build|b)\s*\d+)?)/i', $nfoContent, $matches)) {
            $info['version'] = trim($matches[1]);
        }

        // Protection type
        $protectionPatterns = [
            '/(?:protection|drm|copy[ -]?protection)\s*[:\-]?\s*([^\n\r]+)/i',
        ];
        foreach ($protectionPatterns as $pattern) {
            if (preg_match($pattern, $nfoContent, $matches)) {
                $protection = trim($matches[1]);
                if (strlen($protection) > 2 && strlen($protection) < 50) {
                    $info['protection'] = $protection;
                }
                break;
            }
        }

        // Crack/Keygen/Serial info
        if (preg_match('/\b(cracked|keygen|serial|patch|loader|activator)\b/i', $nfoContent)) {
            $info['has_crack'] = true;
        }

        return $info;
    }

    /**
     * Extract release title from NFO content.
     *
     * @param  string  $nfoContent  The NFO content to parse.
     * @return string|null The release title if found.
     */
    public function extractReleaseTitle(string $nfoContent): ?string
    {
        return $this->extractReleaseTitleNative($nfoContent);
    }

    public function extractReleaseTitleRust(string $nfoContent): ?string
    {
        if (get_class($this) !== self::class) {
            return $this->extractReleaseTitleNative($nfoContent);
        }
        $handled = false;
        $value = $this->metadataRust('nntmux_nfo_extract_release_title', $nfoContent, $handled);

        return $handled && ($value === null || is_string($value)) ? $value : $this->extractReleaseTitleNative($nfoContent);
    }

    public function extractReleaseTitleNative(string $nfoContent): ?string
    {
        $patterns = [
            // "Title: Movie Name" or "Release: Title.Goes.Here"
            '/(?:title|release|name)\s*[:\-]?\s*([^\n\r]{5,100})/i',
            // Scene-style title in header
            '/(?:^|\n)\s*(?:[\-=*~]{3,}\s*)?([A-Za-z0-9][\w.\-\s]{10,80}?)(?:\s*[\-=*~]{3,})?\s*(?:\n|$)/m',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $nfoContent, $matches)) {
                $title = trim($matches[1]);
                // Filter out common non-title content
                if (! preg_match('/^(?:date|size|codec|format|video|audio|language|runtime|genre)\s*:/i', $title)
                    && strlen($title) >= 5 && strlen($title) <= 100) {
                    return $title;
                }
            }
        }

        return null;
    }

    /**
     * Clean and normalize NFO content.
     *
     * @param  string  $nfoContent  Raw NFO content.
     * @return string Cleaned NFO content.
     */
    public function cleanNfoContent(string $nfoContent): string
    {
        if ($nfoContent === '') {
            return '';
        }

        $native = RustPipelineLibrary::nfoCleanContent($nfoContent, $this->libraryPath);
        if ($native !== null) {
            return $native;
        }

        return $this->cleanNfoContentPhp($nfoContent);
    }

    public function cleanNfoContentPhp(string $nfoContent): string
    {
        // Convert to UTF-8 if needed (CP437 is common for NFOs)
        $content = (string) iconv('CP437', 'UTF-8//IGNORE//TRANSLIT', $nfoContent);

        // Normalize line endings
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        // Remove excessive whitespace while preserving NFO art
        $lines = explode("\n", $content);
        $cleanedLines = [];
        $emptyLineCount = 0;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                $emptyLineCount++;
                // Allow max 2 consecutive empty lines
                if ($emptyLineCount <= 2) {
                    $cleanedLines[] = '';
                }
            } else {
                $emptyLineCount = 0;
                $cleanedLines[] = rtrim($line);
            }
        }

        return implode("\n", $cleanedLines);
    }

    /**
     * Calculate an NFO quality score based on content analysis.
     *
     * Scoring factors:
     * - Content length (too short or too long penalized)
     * - Keyword presence (scene terminology, media info)
     * - Media ID presence (IMDB, TVDB, etc.)
     * - URL presence
     * - Codec information
     * - ASCII art detection (scene NFOs often have artistic headers)
     * - Structural elements (proper formatting)
     *
     * @param  string  $nfoContent  The NFO content to analyze.
     * @return int Quality score from 0-100.
     */
    public function calculateNfoQuality(string $nfoContent): int
    {
        return $this->calculateNfoQualityNative($nfoContent);
    }

    public function calculateNfoQualityRust(string $nfoContent): int
    {
        if (get_class($this) !== self::class || $this->_sceneGroupPatterns !== self::DEFAULT_SCENE_GROUP_PATTERNS || $this->_nfoKeywords !== self::DEFAULT_NFO_KEYWORDS) {
            return $this->calculateNfoQualityNative($nfoContent);
        }
        $handled = false;
        $value = $this->metadataRust('nntmux_nfo_calculate_quality', $nfoContent, $handled, true);

        return $handled && is_int($value) ? $value : $this->calculateNfoQualityNative($nfoContent);
    }

    /** Comparative candidate: fused extraction with independent ordered regex scans. */
    public function calculateNfoQualityFusedRust(string $nfoContent): int
    {
        if (get_class($this) !== self::class || $this->_sceneGroupPatterns !== self::DEFAULT_SCENE_GROUP_PATTERNS || $this->_nfoKeywords !== self::DEFAULT_NFO_KEYWORDS) {
            return $this->calculateNfoQualityNative($nfoContent);
        }
        $handled = false;
        $value = $this->metadataRust('nntmux_nfo_calculate_quality_fused', $nfoContent, $handled, true);

        return $handled && is_int($value) ? $value : $this->calculateNfoQualityNative($nfoContent);
    }

    public function calculateNfoQualityNative(string $nfoContent): int
    {
        $score = 50; // Base score

        $length = strlen($nfoContent);

        // Length bonus/penalty
        if ($length < 100) {
            $score -= 20;
        } elseif ($length > 500 && $length < 20000) {
            $score += 15;
        } elseif ($length >= 20000) {
            $score += 5; // Longer NFOs might have too much filler
        }

        // Keyword matching
        $keywordMatches = 0;
        foreach ($this->_nfoKeywords as $keyword) {
            if (stripos($nfoContent, $keyword) !== false) {
                $keywordMatches++;
            }
        }
        $score += min($keywordMatches * 2, 20);

        // Media ID presence bonus
        $mediaIds = $this->extractAllMediaIds($nfoContent);
        if (! empty($mediaIds)) {
            $score += min(count($mediaIds) * 5, 15);
        }

        // URL presence
        $urls = $this->extractUrls($nfoContent);
        if (! empty($urls)) {
            $score += min(count($urls) * 2, 10);
        }

        // Codec info presence
        $codecInfo = $this->extractCodecInfo($nfoContent);
        $score += count(array_filter($codecInfo)) * 3;

        // ASCII art detection (scene NFOs often have decorative borders)
        if ($this->hasAsciiArt($nfoContent)) {
            $score += 10;
        }

        // Structural elements bonus
        $structuralScore = $this->analyzeStructure($nfoContent);
        $score += $structuralScore;

        // Group name detection bonus
        if ($this->extractGroupName($nfoContent) !== null) {
            $score += 8;
        }

        // Release date detection bonus
        if ($this->extractReleaseDate($nfoContent) !== null) {
            $score += 5;
        }

        // Language info bonus
        $languages = $this->extractLanguage($nfoContent);
        if (! empty($languages)) {
            $score += min(count($languages) * 2, 6);
        }

        // Runtime detection bonus
        if ($this->extractRuntime($nfoContent) !== null) {
            $score += 4;
        }

        // Penalty for binary content remnants
        if (preg_match_all('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $nfoContent, $binaryMatches)) {
            $score -= min(count($binaryMatches[0]) * 5, 20);
        }

        return max(0, min(100, $score));
    }

    /**
     * Detect ASCII art in NFO content.
     *
     * @param  string  $nfoContent  The NFO content to analyze.
     * @return bool True if ASCII art is detected.
     */
    protected function hasAsciiArt(string $nfoContent): bool
    {
        return $this->hasAsciiArtNative($nfoContent);
    }

    public function hasAsciiArtRust(string $nfoContent): bool
    {
        if (get_class($this) !== self::class) {
            return $this->hasAsciiArtNative($nfoContent);
        }
        $handled = false;
        $value = $this->metadataRust('nntmux_nfo_has_ascii_art', $nfoContent, $handled, true);

        return $handled && is_int($value) ? (bool) $value : $this->hasAsciiArtNative($nfoContent);
    }

    public function hasAsciiArtNative(string $nfoContent): bool
    {
        // Check for common ASCII art characters in repeated sequences
        $asciiArtPatterns = [
            // Decorative borders
            '/[-=*~#@]{5,}/',
            // Box drawing characters
            '/[┌┐└┘├┤┬┴┼│─╔╗╚╝║═]{3,}/',
            // Extended ASCII art characters
            '/[░▒▓█▄▀■□▪▫]{3,}/',
            // Common ASCII art patterns
            '/[\/\\|_]{3,}.*[\/\\|_]{3,}/',
            // Repeated special chars in artistic patterns
            '/(\S)\1{4,}/',
        ];

        foreach ($asciiArtPatterns as $pattern) {
            if (preg_match($pattern, $nfoContent)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Analyze structural elements of NFO content.
     *
     * @param  string  $nfoContent  The NFO content to analyze.
     * @return int Score based on structural quality (0-15).
     */
    protected function analyzeStructure(string $nfoContent): int
    {
        return $this->analyzeStructureNative($nfoContent);
    }

    public function analyzeStructureRust(string $nfoContent): int
    {
        if (get_class($this) !== self::class) {
            return $this->analyzeStructureNative($nfoContent);
        }
        $handled = false;
        $value = $this->metadataRust('nntmux_nfo_analyze_structure', $nfoContent, $handled, true);

        return $handled && is_int($value) ? $value : $this->analyzeStructureNative($nfoContent);
    }

    public function analyzeStructureNative(string $nfoContent): int
    {
        $score = 0;

        // Check for section headers
        $sectionPatterns = [
            '/^[ \t]*[-=*]{2,}.*[-=*]{2,}[ \t]*$/m', // Decorative section dividers
            '/^[ \t]*\[.*\][ \t]*$/m',                 // [Section Name]
            '/^[ \t]*<.*>[ \t]*$/m',                   // <Section Name>
        ];

        foreach ($sectionPatterns as $pattern) {
            if (preg_match_all($pattern, $nfoContent, $matches)) {
                $score += min(count($matches[0]), 3);
            }
        }

        // Check for labeled fields (Field: Value format)
        if (preg_match_all('/^[ \t]*[A-Za-z][A-Za-z\s]{2,20}\s*[:\.].*$/m', $nfoContent, $matches)) {
            $score += min(count($matches[0]) / 2, 5);
        }

        // Check for consistent line endings and formatting
        $lines = explode("\n", $nfoContent);
        $nonEmptyLines = array_filter($lines, fn ($line) => trim($line) !== '');

        if (count($nonEmptyLines) >= 10) {
            $score += 2;
        }

        return min(15, (int) $score);
    }

    /**
     * Decompress and retrieve NFO content from a release.
     *
     * @param  int  $releaseId  The release ID.
     * @return string|null The NFO content or null if not found.
     */
    public function getNfoContent(int $releaseId): ?string
    {
        $nfoRecord = ReleaseNfo::getReleaseNfo($releaseId);

        if ($nfoRecord === null || empty($nfoRecord->nfo)) {
            return null;
        }

        return $nfoRecord->nfo;
    }

    /**
     * Store NFO content for a release.
     *
     * @param  int  $releaseId  The release ID.
     * @param  string  $nfoContent  The NFO content to store.
     * @param  bool  $compress  Whether to compress the content.
     * @return bool True on success, false on failure.
     */
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

    public function storeNfoContent(int $releaseId, string $nfoContent, bool $compress = true): bool
    {
        try {
            $data = $compress ? "\x1f\x8b\x08\x00".gzcompress($nfoContent) : $nfoContent;

            ReleaseNfo::updateOrCreate(
                ['releases_id' => $releaseId],
                ['nfo' => $data]
            );

            Release::whereId($releaseId)->update(['nfostatus' => self::NFO_FOUND]);

            return true;
        } catch (Throwable $e) {
            Log::error("Failed to store NFO for release {$releaseId}: ".$e->getMessage());

            return false;
        }
    }

    /**
     * Clear the settings cache.
     *
     * Useful when settings have been updated and need to be reloaded.
     */
    public function clearSettingsCache(): void
    {
        Cache::forget('nfo_maxnfoprocessed');
        Cache::forget('nfo_maxnforetries');
        Cache::forget('nfo_maxsizetoprocessnfo');
        Cache::forget('nfo_minsizetoprocessnfo');

        $this->nzbs = null;
        $this->maxRetries = null;
        $this->maxSize = null;
        $this->minSize = null;
    }

    /** Disabled: common p99 missed 1.5x in three of five paired runs (1.51x, 1.38x, 1.39x, 1.39x, 1.89x; see the W42 parity test). */
    public const bool W424_RUST_FFI_DEFAULT = false;

    public const string W424_FFI_CDEF = <<<'C'
        ssize_t nntmux_w424_nfo_content_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W424_SUMMARY_KEYS = ['total_nfos', 'empty_nfo_count', 'imdb_link_count', 'tv_link_count', 'url_count', 'high_ascii_count', 'multi_line_count'];

    private static ?bool $w424UseRustFfi = null;

    private static \FFI|false|null $w424Ffi = null;

    public static function useW424RustFfi(bool $enable = true): void
    {
        self::$w424UseRustFfi = $enable;
    }

    public static function usesW424RustFfi(): bool
    {
        return self::$w424UseRustFfi ?? self::W424_RUST_FFI_DEFAULT;
    }

    public static function resetW424RustFfi(): void
    {
        self::$w424UseRustFfi = null;
        self::$w424Ffi = null;
    }

    /**
     * Summarize NFO text: imdb.com/title/tt links with at least seven
     * digits, TheTVDB/TVmaze hosts, http(s) URLs (all ASCII case-insensitive),
     * bytes above 0x7F (CP437 art) and at least ten newlines after trimming.
     * Entries come from strings, integers, or the nfo or content array
     * field; other values (including objects) count as empty entries.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeNfoContentBatch(array $entries): array
    {
        return self::usesW424RustFfi()
            ? self::summarizeNfoContentBatchNative($entries)
            : self::summarizeNfoContentBatchPhp($entries);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeNfoContentBatchNative(array $entries): array
    {
        return self::w424Call(self::w424Entries($entries)) ?? self::summarizeNfoContentBatchPhp($entries);
    }

    /**
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeNfoContentBatchPhp(array $entries): array
    {
        $summary = array_fill_keys(self::W424_SUMMARY_KEYS, 0);
        foreach (self::w424Entries($entries) as $entry) {
            $summary['total_nfos']++;
            $entry = trim($entry);
            if ($entry === '') {
                $summary['empty_nfo_count']++;

                continue;
            }
            $summary['imdb_link_count'] += (int) (preg_match('~imdb\.com/title/tt\d{7}~i', $entry) === 1);
            $summary['tv_link_count'] += (int) (stripos($entry, 'thetvdb.com') !== false || stripos($entry, 'tvmaze.com') !== false);
            $summary['url_count'] += (int) (stripos($entry, 'http://') !== false || stripos($entry, 'https://') !== false);
            $summary['high_ascii_count'] += (int) (preg_match('/[\x80-\xFF]/', $entry) === 1);
            $summary['multi_line_count'] += (int) (substr_count($entry, "\n") >= 10);
        }

        return $summary;
    }

    /** Only integer and string entries participate; objects are never cast or serialized.
     *
     * @param  array<array-key, mixed>  $input
     * @return list<string>
     */
    private static function w424Entries(array $input): array
    {
        $entries = [];
        foreach ($input as $item) {
            if (is_array($item)) {
                $item = $item['nfo'] ?? $item['content'] ?? null;
            }
            $entries[] = is_string($item) || is_int($item) ? (string) $item : '';
        }

        return $entries;
    }

    /** @param list<string> $entries
     * @return array<string, int>|null
     */
    private static function w424Call(array $entries): ?array
    {
        try {
            $input = serialize([$entries]);
            if (strlen($input) > 16777216) {
                return null;
            }
            self::$w424Ffi ??= RustPipelineLibrary::resolveDomain('metadata_w424', self::W424_FFI_CDEF) ?? false;
            $ffi = self::$w424Ffi;
            if ($ffi === false) {
                return null;
            }
            $capacity = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, 2048, $capacity);
            if ($out === null) {
                return null;
            }
            // @phpstan-ignore method.notFound (Declared by W424_FFI_CDEF.)
            $written = $ffi->nntmux_w424_nfo_content_batch($input, strlen($input), $out, $capacity);
            if (is_int($written) && $written < -1 && $written >= -16777216) {
                $out = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($out === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound (Declared by W424_FFI_CDEF.)
                $written = $ffi->nntmux_w424_nfo_content_batch($input, strlen($input), $out, $capacity);
            }
            if (! is_int($written) || $written <= 0 || $written > $capacity || $written > 16777216) {
                return null;
            }
            $result = @unserialize(\FFI::string($out, $written), ['allowed_classes' => false]);
            if (! is_array($result) || array_keys($result) !== self::W424_SUMMARY_KEYS || $result['total_nfos'] !== count($entries)) {
                return null;
            }
            $summary = [];
            foreach (self::W424_SUMMARY_KEYS as $key) {
                $value = $result[$key];
                if (! is_int($value) || $value < 0 || $value > count($entries)) {
                    return null;
                }
                $summary[$key] = $value;
            }

            return $summary;
        } catch (Throwable) {
            return null;
        }
    }

    /** Wave 55.2 paired benchmarks decide this default; see RustNfoServiceDbNzbStoreNfoImportNzbContentsParserW55ParityTest. */
    private const bool W55_RUST_FFI_DEFAULT = true;

    private const string W55_FFI_CDEF = <<<'C'
        intptr_t nntmux_w552_nfo_show_id_plan_batch(const char *texts, size_t texts_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /**
     * Wave 55 routing, binding and output buffer, kept in function-local statics. Operations:
     * null reads the routing override, 'set' replaces it (returning the previous one), 'ffi'
     * resolves the binding and 'out' returns `[binding, buffer, capacity]` with room for at
     * least $val words (null without the library).
     */
    private static function w55RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w55', self::W55_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w55RustFfiState('ffi');
                    $buffer = $resolved instanceof \FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    public static function usesW55RustFfi(): bool
    {
        $enabled = self::w55RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W55_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with planShowIdBatch() routed to Rust ($enabled) or PHP, then restores the previous
     * routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW55RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w55RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w55RustFfiState('set', $previous);
        }
    }

    /** parseShowIdNative()'s patterns in priority order (TVDB has two). */
    private const array W55_SHOW_ID_PATTERNS = [
        '/tvmaze\.com\/shows\/(\d{1,6})/i',
        '/imdb\.com\/title\/(tt\d{7,})/i',
        '/thetvdb\.com\/\?tab=series&id=(\d{1,8})/i',
        '/thetvdb\.com\/series\/(\d{1,8}|[\w-]+)/i',
        '/themoviedb\.org\/movie\/(\d{1,8})/i',
        '/themoviedb\.org\/tv\/(\d{1,8})/i',
        '/anidb\.net\/(?:perl-bin\/animedb\.pl\?show=anime&aid=|anime\/)(\d{1,6})/i',
        '/trakt\.tv\/(?:shows|movies)\/([\w-]+)/i',
    ];

    /**
     * Batch show-id plan, as parseShowIdNative() reads each NFO. One word per text:
     * `pattern | min(id length, 0xFFFFFF) << 8 | crc32(id) << 32`, where pattern is the first
     * matching W55_SHOW_ID_PATTERNS entry (1-8, both TVDB forms are site `thetvdb`) and the word
     * is 0 without a match. The matched texts, the per-pattern counts (1-8) and the XXH64 digest
     * of all earlier words follow.
     *
     * @param  list<string>  $texts
     * @return list<int>
     */
    public static function planShowIdBatch(array $texts): array
    {
        return self::usesW55RustFfi() ? self::planShowIdBatchNative($texts) : self::planShowIdBatchPhp($texts);
    }

    /**
     * @param  list<string>  $texts
     * @return list<int>
     */
    public static function planShowIdBatchPhp(array $texts): array
    {
        $out = [];
        $counts = array_fill(0, 9, 0);
        foreach ($texts as $text) {
            $pattern = 0;
            $id = '';
            foreach (self::W55_SHOW_ID_PATTERNS as $i => $regex) {
                if (preg_match($regex, $text, $hits)) {
                    $pattern = $i + 1;
                    $id = trim($hits[1]);
                    break;
                }
            }
            $counts[$pattern]++;
            $out[] = $pattern === 0 ? 0 : $pattern | min(\strlen($id), 0xFFFFFF) << 8 | crc32($id) << 32;
        }
        $out[] = \count($texts) - $counts[0];
        array_push($out, ...\array_slice($counts, 1));
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));
        $out[] = $digest[1];

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, PCRE's character tables are not the
     * default (C) ones, or the texts are not a list.
     *
     * @param  list<string>  $texts
     * @return list<int>
     */
    public static function planShowIdBatchNative(array $texts): array
    {
        $count = \count($texts);
        $words = $count + 10;
        /** @var array{\FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w55RustFfiState('out', $words);
        if ($state === null || ! array_is_list($texts) || ! NzbParserService::pcreUsesDefaultTables()) {
            return self::planShowIdBatchPhp($texts);
        }
        $blob = implode('', $texts);
        $ints = pack('P*', ...array_map(strlen(...), $texts));
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W55_FFI_CDEF.)
            $written = $ffi->nntmux_w552_nfo_show_id_plan_batch($blob, \strlen($blob), $ints, \strlen($ints), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', \FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : self::planShowIdBatchPhp($texts);
    }
}
